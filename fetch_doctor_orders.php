<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['doctor','general_doctor'])) {
    http_response_code(403);
    exit('Unauthorized');
}

if (!isset($_GET['admission_id'])) {
    http_response_code(400);
    exit('Missing admission_id');
}
$admission_id = intval($_GET['admission_id']);

$database = new Database();
$db = $database->getConnection();

$query = "SELECT do.*, u.name as ordered_by_name, n.name as claimed_by_name 
          FROM doctors_orders do
          JOIN users u ON do.ordered_by = u.id
          LEFT JOIN users n ON do.claimed_by = n.id
          WHERE do.admission_id = :admission_id
          AND do.status IN ('active','in_progress')
          ORDER BY do.ordered_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(":admission_id", $admission_id);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($orders as $order): ?>
    <div class="card order-card order-type-<?php echo $order['order_type']; ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="card-title">
                    <?php echo ucfirst(str_replace('_', ' ', $order['order_type'])); ?>
                    <span class="badge bg-secondary ms-2">
                        <?php echo $order['frequency'] ? $order['frequency'] : 'Once'; ?>
                    </span>
                    <?php if ($order['status'] === 'in_progress'): ?>
                        <span class="badge bg-warning text-dark ms-2">In Progress</span>
                        <?php if (!empty($order['claimed_by_name'])): ?>
                            <small class="text-muted ms-2">By: <?php echo htmlspecialchars($order['claimed_by_name']); ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </h6>
                <button class="btn btn-sm btn-outline-danger" onclick="prepareDiscontinue(<?php echo $order['id']; ?>)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="card-text"><?php echo nl2br(htmlspecialchars($order['order_details'])); ?></p>
            <?php if ($order['special_instructions']): ?>
                <p class="card-text"><small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo htmlspecialchars($order['special_instructions']); ?>
                </small></p>
            <?php endif; ?>
            <div class="text-muted">
                <small>
                    <i class="fas fa-user-md me-1"></i>
                    <?php echo htmlspecialchars($order['ordered_by_name']); ?> |
                    <i class="fas fa-clock me-1"></i>
                    <?php echo date('M d, Y h:i A', strtotime($order['ordered_at'])); ?>
                </small>
            </div>
        </div>
    </div>
<?php endforeach; 
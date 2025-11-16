<?php
session_start();
require_once "config/database.php";

// Only nurses can fetch
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    http_response_code(403);
    exit('Unauthorized');
}

$database = new Database();
$db = $database->getConnection();

function isDischargeOrderType($orderType) {
    $value = strtolower(trim((string)$orderType));
    return $value === 'discharge' || $value === 'discharge_order' || $value === '';
}

function getOrderTypeLabel(array $order): string {
    if (isDischargeOrderType($order['order_type'] ?? null)) {
        return 'Discharge';
    }
    $raw = trim((string)($order['order_type'] ?? ''));
    return $raw !== '' ? ucfirst(str_replace('_',' ', $raw)) : 'Unspecified';
}

$admission_id = isset($_GET['admission_id']) ? intval($_GET['admission_id']) : 0;

// Fetch active and in-progress orders
$query = "SELECT do.*, p.name as patient_name, p.age, p.gender, p.address as patient_address, p.id as patient_id,
                 r.room_number, b.bed_number,
                 u.name as claimed_by_name
          FROM doctors_orders do
          JOIN patient_admissions pa ON do.admission_id = pa.id
          JOIN patients p ON pa.patient_id = p.id
          LEFT JOIN rooms r ON pa.room_id = r.id
          LEFT JOIN beds b ON pa.bed_id = b.id
          LEFT JOIN users u ON do.claimed_by = u.id
          WHERE pa.admission_status = 'admitted'
            AND do.status IN ('active','in_progress')" .
          ($admission_id ? " AND do.admission_id = :admission_id" : "") .
          " ORDER BY do.status = 'in_progress' DESC, do.ordered_at DESC";
$stmt = $db->prepare($query);
if ($admission_id) {
    $stmt->bindParam(":admission_id", $admission_id);
}
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($orders as $order): ?>
    <tr>
        <td><?php echo date('M d, Y h:i A', strtotime($order['ordered_at'])); ?></td>
        <td><?php echo htmlspecialchars($order['patient_name']); ?></td>
        <td><?php echo htmlspecialchars($order['room_number'] . ' / ' . $order['bed_number']); ?></td>
        <td>
            <?php if ($order['special_instructions'] === 'ROOM_TRANSFER'): ?>
                <span class="badge bg-warning text-dark">Room Transfer</span>
            <?php elseif ($order['special_instructions'] === 'NEWBORN_INFO_REQUEST'): ?>
                <span class="badge bg-pink text-dark" style="background:#f8d7da;color:#842029;">Newborn Info</span>
            <?php elseif (isDischargeOrderType($order['order_type'])): ?>
                <span class="badge bg-danger">Discharge</span>
            <?php else: ?>
                <span class="badge bg-secondary"><?php echo htmlspecialchars(getOrderTypeLabel($order)); ?></span>
            <?php endif; ?>
        </td>
        <td><?php echo nl2br(htmlspecialchars($order['order_details'])); ?></td>
        <td>
            <?php if ($order['status'] === 'in_progress'): ?>
                <span class="badge bg-warning text-dark">In Progress</span>
                <?php if (!empty($order['claimed_by_name'])): ?>
                    <small class="text-muted d-block">By: <?php echo htmlspecialchars($order['claimed_by_name']); ?></small>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge bg-info">Active</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="d-flex gap-2">
                <?php if ($order['special_instructions'] === 'NEWBORN_INFO_REQUEST'): ?>
                    <?php if ($order['status'] === 'active' || ($order['status'] === 'in_progress' && (int)$order['claimed_by'] === (int)$_SESSION['user_id'])): ?>
                        <a href="nurse_newborn_request.php?order_id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-baby me-1"></i>Process Request
                        </a>
                    <?php else: ?>
                        <button class="btn btn-sm btn-outline-secondary" disabled>
                            <i class="fas fa-lock me-1"></i>Unavailable
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($order['status'] === 'active'): ?>
                        <?php if (isDischargeOrderType($order['order_type'])): ?>
                            <form method="POST" action="nurse_orders.php" class="d-inline">
                                <input type="hidden" name="action" value="claim_order">
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <input type="hidden" name="redirect_to_clearance" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-hand-paper me-1"></i>Claim &amp; Open Clearance
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="nurse_orders.php" class="d-inline">
                                <input type="hidden" name="action" value="claim_order">
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-hand-paper me-1"></i>Claim
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($order['status'] === 'in_progress' && $order['claimed_by'] == $_SESSION['user_id']): ?>
                        <?php if (isDischargeOrderType($order['order_type'])): ?>
                            <a
                                href="nurse_clearance_form.php?order_id=<?php echo $order['id']; ?>"
                                class="btn btn-sm btn-outline-primary"
                            >
                                <i class="fas fa-file-alt me-1"></i>Open Clearance
                            </a>
                        <?php endif; ?>
                        <form method="POST" action="nurse_orders.php" class="d-inline">
                            <input type="hidden" name="action" value="release_order">
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-undo me-1"></i>Release
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($order['status'] === 'in_progress' && $order['claimed_by'] == $_SESSION['user_id']): ?>
                        <button class="btn btn-sm btn-success" onclick="openComplete(<?php echo $order['id']; ?>)">
                            <i class="fas fa-check me-1"></i>Done
                        </button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-success" disabled title="Claim this order to complete">
                            <i class="fas fa-check me-1"></i>Done
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php endforeach; 
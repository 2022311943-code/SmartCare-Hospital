<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a pharmacist
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'pharmacist') {
    http_response_code(403);
    exit('Unauthorized');
}

$database = new Database();
$db = $database->getConnection();

// Get recent orders
$recent_orders_query = "SELECT mo.*, 
                              u.name as doctor_name, 
                              p.name as patient_name
                       FROM medicine_orders mo
                       JOIN users u ON mo.doctor_id = u.id
                       JOIN patients p ON mo.patient_id = p.id
                       ORDER BY mo.created_at DESC 
                       LIMIT 10";
$recent_orders_stmt = $db->prepare($recent_orders_query);
$recent_orders_stmt->execute();
$recent_orders = $recent_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Return only the table rows HTML
foreach($recent_orders as $recent_order): ?>
    <tr>
        <td><?php echo htmlspecialchars($recent_order['order_number']); ?></td>
        <td><?php echo htmlspecialchars($recent_order['patient_name']); ?></td>
        <td>Dr. <?php echo htmlspecialchars($recent_order['doctor_name']); ?></td>
        <td><?php echo date('M d, Y', strtotime($recent_order['created_at'])); ?></td>
        <td>
            <span class="status-badge status-<?php echo strtolower($recent_order['status']); ?>">
                <?php echo ucfirst($recent_order['status']); ?>
            </span>
        </td>
        <td>
            <a href="?order_number=<?php echo $recent_order['order_number']; ?>" class="btn btn-danger btn-sm">
                <i class="fas fa-eye"></i>
            </a>
        </td>
    </tr>
<?php endforeach; ?> 
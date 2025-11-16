<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a pharmacist
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'pharmacist') {
    exit(json_encode(['error' => 'Unauthorized']));
}

$database = new Database();
$db = $database->getConnection();

$search = isset($_GET['order_number']) ? $_GET['order_number'] : '';

// Get order details if order number is provided
$order = null;
$order_items = [];
if (!empty($search)) {
    try {
        // Get order details
        $order_query = "SELECT mo.*, 
                              u.name as doctor_name, 
                              p.name as patient_name,
                              p.phone as patient_phone
                       FROM medicine_orders mo
                       JOIN users u ON mo.doctor_id = u.id
                       JOIN patients p ON mo.patient_id = p.id
                       WHERE mo.order_number = :order_number";
        $order_stmt = $db->prepare($order_query);
        $order_stmt->bindParam(":order_number", $search);
        $order_stmt->execute();
        $order = $order_stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Get order items
            $items_query = "SELECT moi.*, m.name as medicine_name, m.unit
                          FROM medicine_order_items moi
                          JOIN medicines m ON moi.medicine_id = m.id
                          WHERE moi.order_id = :order_id";
            $items_stmt = $db->prepare($items_query);
            $items_stmt->bindParam(":order_id", $order['id']);
            $items_stmt->execute();
            $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(PDOException $e) {
        exit(json_encode(['error' => 'Database error']));
    }
}

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

// Generate HTML for order details if found
$html = '';
if ($order) {
    $html .= '<div class="card mb-4">';
    $html .= '<div class="card-header py-3">';
    $html .= '<h5 class="mb-0"><i class="fas fa-file-prescription me-2"></i>Order #' . htmlspecialchars($order['order_number']) . '</h5>';
    $html .= '</div>';
    $html .= '<div class="card-body">';
    $html .= '<div class="row mb-4">';
    $html .= '<div class="col-md-6">';
    $html .= '<h6 class="text-muted">Order Information</h6>';
    $html .= '<p><strong>Status:</strong> <span class="status-badge status-' . strtolower($order['status']) . '">' . ucfirst($order['status']) . '</span></p>';
    $html .= '<p><strong>Date:</strong> ' . date('M d, Y H:i', strtotime($order['created_at'])) . '</p>';
    $html .= '<p><strong>Notes:</strong> ' . htmlspecialchars($order['notes']) . '</p>';
    $html .= '</div>';
    $html .= '<div class="col-md-6">';
    $html .= '<h6 class="text-muted">Patient & Doctor Information</h6>';
    $html .= '<p><strong>Patient:</strong> ' . htmlspecialchars($order['patient_name']) . '</p>';
    $html .= '<p><strong>Phone:</strong> ' . htmlspecialchars($order['patient_phone']) . '</p>';
    $html .= '<p><strong>Doctor:</strong> Dr. ' . htmlspecialchars($order['doctor_name']) . '</p>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<h6 class="text-muted mb-3">Requested Medicines</h6>';
    $html .= '<div class="table-responsive">';
    $html .= '<table class="table">';
    $html .= '<thead><tr><th>Medicine</th><th>Quantity</th><th>Instructions</th></tr></thead>';
    $html .= '<tbody>';
    foreach($order_items as $item) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($item['medicine_name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($item['quantity']) . ' ' . htmlspecialchars($item['unit']) . '</td>';
        $html .= '<td>' . htmlspecialchars($item['instructions']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    if($order['status'] !== 'completed' && $order['status'] !== 'cancelled') {
        $html .= '<form method="POST" action="" class="mt-3">';
        $html .= '<input type="hidden" name="action" value="update_status">';
        $html .= '<input type="hidden" name="order_id" value="' . $order['id'] . '">';
        $html .= '<div class="row g-3 align-items-center">';
        $html .= '<div class="col-auto">';
        $html .= '<select class="form-select" name="status" required>';
        $html .= '<option value="">Update Status</option>';
        $html .= '<option value="processing">Mark as Processing</option>';
        $html .= '<option value="completed">Mark as Completed</option>';
        $html .= '<option value="cancelled">Cancel Order</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<div class="col-auto">';
        $html .= '<button type="submit" class="btn btn-danger">Update Status</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</form>';
    }
    $html .= '</div></div>';
} elseif (!empty($search)) {
    $html .= '<div class="alert alert-warning">No order found with the specified order number.</div>';
}

// Generate HTML for recent orders
$html .= '<div class="card">';
$html .= '<div class="card-header py-3">';
$html .= '<h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Orders</h5>';
$html .= '</div>';
$html .= '<div class="card-body">';
$html .= '<div class="table-responsive">';
$html .= '<table class="table">';
$html .= '<thead><tr><th>Order #</th><th>Patient</th><th>Doctor</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>';
$html .= '<tbody>';
foreach($recent_orders as $recent_order) {
    $html .= '<tr>';
    $html .= '<td>' . htmlspecialchars($recent_order['order_number']) . '</td>';
    $html .= '<td>' . htmlspecialchars($recent_order['patient_name']) . '</td>';
    $html .= '<td>Dr. ' . htmlspecialchars($recent_order['doctor_name']) . '</td>';
    $html .= '<td>' . date('M d, Y', strtotime($recent_order['created_at'])) . '</td>';
    $html .= '<td><span class="status-badge status-' . strtolower($recent_order['status']) . '">' . ucfirst($recent_order['status']) . '</span></td>';
    $html .= '<td>';
    $html .= '<button class="btn btn-danger btn-sm" onclick="searchOrder(' . $recent_order['order_number'] . ')">';
    $html .= '<i class="fas fa-eye"></i>';
    $html .= '</button>';
    $html .= '</td>';
    $html .= '</tr>';
}
$html .= '</tbody></table></div></div></div>';

echo $html; 
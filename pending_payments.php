<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['medical_technician', 'radiologist'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get test type based on employee role
$test_type = ($_SESSION['employee_type'] === 'medical_technician') ? 'laboratory' : 'radiology';

// Get pending payments
$query = "SELECT 
    lr.id,
    lr.visit_id,
    lr.requested_at,
    lr.priority,
    lr.payment_status,
    lr.status,
    lt.test_name,
    lt.cost,
    CONCAT(u.name, ' (', u.employee_type, ')') as requested_by,
    ov.patient_name,
    ov.contact_number
FROM lab_requests lr
JOIN lab_tests lt ON lr.test_id = lt.id
JOIN users u ON lr.doctor_id = u.id
JOIN opd_visits ov ON lr.visit_id = ov.id
WHERE lt.test_type = :test_type 
AND lr.status = 'pending_payment'
ORDER BY 
    CASE lr.priority
        WHEN 'emergency' THEN 1
        WHEN 'urgent' THEN 2
        ELSE 3
    END,
    lr.requested_at ASC";

$stmt = $db->prepare($query);
$stmt->bindParam(":test_type", $test_type);
$stmt->execute();
$pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Decrypt sensitive fields for display
foreach ($pending_requests as &$pr) {
    $pr['patient_name'] = decrypt_safe($pr['patient_name'] ?? '');
    $pr['contact_number'] = decrypt_safe($pr['contact_number'] ?? '');
}
unset($pr);

// Handle payment request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_payment'])) {
    $request_id = $_POST['request_id'];
    $amount = $_POST['amount'];
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Create payment record
        $payment_query = "INSERT INTO lab_payments (lab_request_id, amount, payment_status) 
                         VALUES (:request_id, :amount, 'pending')";
        $payment_stmt = $db->prepare($payment_query);
        $payment_stmt->bindParam(":request_id", $request_id);
        $payment_stmt->bindParam(":amount", $amount);
        $payment_stmt->execute();
        
        $payment_id = $db->lastInsertId();
        
        // Create notification
        $notify_query = "INSERT INTO payment_notifications (lab_payment_id, notification_type) 
                        VALUES (:payment_id, 'payment_request')";
        $notify_stmt = $db->prepare($notify_query);
        $notify_stmt->bindParam(":payment_id", $payment_id);
        $notify_stmt->execute();
        
        // Update lab request status
        $update_query = "UPDATE lab_requests SET status = 'pending' WHERE id = :request_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(":request_id", $request_id);
        $update_stmt->execute();
        
        $db->commit();
        
        $_SESSION['success_message'] = "Payment request sent successfully.";
        header("Location: pending_payments.php");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_message'] = "Error sending payment request. Please try again.";
    }
}

// Set page title
$page_title = 'Pending Payments';
if (!defined('INCLUDED_IN_PAGE')) { define('INCLUDED_IN_PAGE', true); }
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Payments - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .priority-emergency { background-color: #ffe6e6; }
        .priority-urgent { background-color: #fff3e6; }
        .payment-status-unpaid { color: #dc3545; }
        .payment-status-pending { color: #ffc107; }
        .payment-status-paid { color: #28a745; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-end mb-3">
            <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
        </div>
        <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-dollar-sign me-2"></i>
                    Pending <?php echo ucfirst($test_type); ?> Payments
                </h5>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Test</th>
                                <th>Requested By</th>
                                <th>Priority</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Requested Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending_requests as $request): ?>
                                <tr class="<?php echo 'priority-' . $request['priority']; ?>">
                                    <td>
                                        <?php echo htmlspecialchars($request['patient_name']); ?>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($request['contact_number']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($request['test_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['requested_by']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $request['priority'] === 'emergency' ? 'danger' : 
                                                ($request['priority'] === 'urgent' ? 'warning' : 'info'); 
                                        ?>">
                                            <?php echo ucfirst($request['priority']); ?>
                                        </span>
                                    </td>
                                    <td>₱<?php echo number_format($request['cost'], 2); ?></td>
                                    <td>
                                        <span class="payment-status-<?php echo $request['payment_status']; ?>">
                                            <?php echo ucfirst($request['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($request['requested_at'])); ?></td>
                                    <td>
                                        <?php if ($request['status'] === 'pending_payment'): ?>
                                            <button type="button" 
                                                    class="btn btn-primary btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#requestPaymentModal<?php echo $request['id']; ?>">
                                                <i class="fas fa-paper-plane me-1"></i>
                                                Request Payment
                                            </button>
                                            
                                            <!-- Payment Request Modal -->
                                            <div class="modal fade" id="requestPaymentModal<?php echo $request['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Request Payment</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form action="pending_payments.php" method="POST">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                                <input type="hidden" name="amount" value="<?php echo $request['cost']; ?>">
                                                                
                                                                <p>Are you sure you want to send a payment request for:</p>
                                                                <ul class="list-unstyled">
                                                                    <li><strong>Patient:</strong> <?php echo htmlspecialchars($request['patient_name']); ?></li>
                                                                    <li><strong>Test:</strong> <?php echo htmlspecialchars($request['test_name']); ?></li>
                                                                    <li><strong>Amount:</strong> ₱<?php echo number_format($request['cost'], 2); ?></li>
                                                                </ul>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="request_payment" class="btn btn-primary">
                                                                    <i class="fas fa-paper-plane me-1"></i>
                                                                    Send Request
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">
                                                <i class="fas fa-check me-1"></i>
                                                Request Sent
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pending_requests)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-check-circle text-success me-2"></i>
                                        No pending payments at the moment.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
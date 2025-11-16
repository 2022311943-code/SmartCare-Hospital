<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a doctor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['doctor', 'general_doctor'])) {
    header("Location: index.php");
    exit();
}

$specialist_departments = ['OB-GYN','Pediatrics'];
$is_specialist_doctor = (
    $_SESSION['employee_type'] === 'doctor'
    && isset($_SESSION['department'])
    && in_array($_SESSION['department'], $specialist_departments, true)
);

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Get admission ID from URL
$admission_id = isset($_GET['admission_id']) ? intval($_GET['admission_id']) : 0;

// Verify admission exists and doctor has access
$admission_query = "SELECT pa.*, p.name as patient_name, p.age, p.gender,
                          p.doctor_id AS assigned_doctor_id,
                          r.room_number, b.bed_number,
                          u1.name as admitting_doctor_name
                   FROM patient_admissions pa
                   JOIN patients p ON pa.patient_id = p.id
                   LEFT JOIN rooms r ON pa.room_id = r.id
                   LEFT JOIN beds b ON pa.bed_id = b.id
                   LEFT JOIN users u1 ON pa.admitted_by = u1.id
                   WHERE pa.id = :admission_id
                   AND pa.admission_status = 'admitted'";

$admission_stmt = $db->prepare($admission_query);
$admission_stmt->bindParam(":admission_id", $admission_id);
$admission_stmt->execute();
$admission = $admission_stmt->fetch(PDO::FETCH_ASSOC);

if (!$admission) {
    header("Location: index.php");
    exit();
}

// Determine if current user can create orders (only assigned doctor)
$can_order = (isset($admission['assigned_doctor_id']) && intval($admission['assigned_doctor_id']) === intval($_SESSION['user_id']));

// Handle form submission for new order
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Enforce permission server-side: only assigned doctor can order
        if (!$can_order) {
            throw new Exception('You are not the assigned doctor for this patient. Ordering is restricted.');
        }
        $db->beginTransaction();

        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'add_order') {
                $is_newborn_request = isset($_POST['is_newborn_request']) && $_POST['is_newborn_request'] === '1';
                $is_discharge_order = isset($_POST['is_discharge_order']) && $_POST['is_discharge_order'] === '1';
                $order_type_value = trim($_POST['order_type'] ?? '');
                if ($is_discharge_order) {
                    $order_type_value = 'discharge';
                }
                $order_details_value = $_POST['order_details'] ?? '';
                $frequency_value = $_POST['frequency'] ?? null;
                $duration_value = $_POST['duration'] ?? null;
                $special_instructions_value = $_POST['special_instructions'] ?? null;

                if (!$is_discharge_order && !$is_newborn_request && $order_type_value === '') {
                    throw new Exception('Order type is required.');
                }

                if ($is_newborn_request) {
                    if (!$is_specialist_doctor) {
                        throw new Exception('Newborn information requests are limited to OB-GYN and Pediatrics.');
                    }
                    $order_type_value = 'other';
                    $order_details_value = 'Request newborn information for birth certificate';
                    $newborn_notes = trim($_POST['newborn_request_notes'] ?? '');
                    if ($newborn_notes !== '') {
                        $order_details_value .= "\nNotes: " . $newborn_notes;
                    }
                    $frequency_value = null;
                    $duration_value = null;
                    $special_instructions_value = 'NEWBORN_INFO_REQUEST';
                }

                // Insert new order
                $order_query = "INSERT INTO doctors_orders 
                              (admission_id, order_type, order_details, frequency, duration,
                               special_instructions, ordered_by)
                              VALUES 
                              (:admission_id, :order_type, :order_details, :frequency, :duration,
                               :special_instructions, :ordered_by)";
                
                $order_stmt = $db->prepare($order_query);
                $order_stmt->bindParam(":admission_id", $admission_id);
                $order_stmt->bindParam(":order_type", $order_type_value);
                $order_stmt->bindParam(":order_details", $order_details_value);
                $order_stmt->bindParam(":frequency", $frequency_value);
                $order_stmt->bindParam(":duration", $duration_value);
                $order_stmt->bindParam(":special_instructions", $special_instructions_value);
                $order_stmt->bindParam(":ordered_by", $_SESSION['user_id']);
                $order_stmt->execute();

                // If this is a discharge order, ensure billing row exists (will only show after nurse completes)
                if ($order_type_value === 'discharge') {
                    $ensure_bill = $db->prepare("INSERT INTO admission_billing (admission_id, subtotal, discount_amount, discount_label, total_due, payment_status, created_by)
                                                 SELECT :aid, 0, 0, NULL, 0, 'unpaid', :uid
                                                 WHERE NOT EXISTS (SELECT 1 FROM admission_billing WHERE admission_id = :aid)");
                    $ensure_bill->execute(['aid' => $admission_id, 'uid' => $_SESSION['user_id']]);
                }

                $success_message = "Order added successfully";
            }
            elseif ($_POST['action'] === 'discontinue_order') {
                // Discontinue existing order
                $update_query = "UPDATE doctors_orders 
                               SET status = 'discontinued',
                                   discontinued_at = NOW(),
                                   discontinued_by = :discontinued_by,
                                   discontinue_reason = :discontinue_reason
                               WHERE id = :order_id 
                               AND admission_id = :admission_id
                               AND status = 'active'";
                
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(":discontinued_by", $_SESSION['user_id']);
                $update_stmt->bindParam(":discontinue_reason", $_POST['discontinue_reason']);
                $update_stmt->bindParam(":order_id", $_POST['order_id']);
                $update_stmt->bindParam(":admission_id", $admission_id);
                $update_stmt->execute();

                $success_message = "Order discontinued successfully";
            }
            elseif ($_POST['action'] === 'request_lab') {
                // Inpatient lab request: no payment prerequisite
                $lab_query = "INSERT INTO lab_requests (
                                  visit_id, admission_id, doctor_id, test_id, priority, status, notes
                              ) VALUES (
                                  NULL, :admission_id, :doctor_id, :test_id, :priority, 'pending', :notes
                              )";
                $lab_stmt = $db->prepare($lab_query);
                $lab_stmt->bindParam(":admission_id", $admission_id);
                $lab_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
                $lab_stmt->bindParam(":test_id", $_POST['lab_test_id']);
                $lab_stmt->bindParam(":priority", $_POST['lab_priority']);
                $lab_stmt->bindParam(":notes", $_POST['lab_notes']);
                $lab_stmt->execute();

                // Also create a nurse-visible order to collect/prepare for the lab test
                $test_info_stmt = $db->prepare("SELECT test_name, test_type FROM lab_tests WHERE id = :id");
                $test_info_stmt->bindParam(":id", $_POST['lab_test_id']);
                $test_info_stmt->execute();
                $test_info = $test_info_stmt->fetch(PDO::FETCH_ASSOC);
                $lab_order_details = 'Lab: ' . ($test_info ? ($test_info['test_name']) : 'Test #' . intval($_POST['lab_test_id']));
                if (!empty($_POST['lab_notes'])) {
                    $lab_order_details .= "\nNotes: " . $_POST['lab_notes'];
                }
                $nurse_order_query = "INSERT INTO doctors_orders (
                                        admission_id, order_type, order_details, frequency, duration, special_instructions, ordered_by
                                      ) VALUES (
                                        :admission_id, 'lab_test', :details, NULL, NULL, NULL, :ordered_by
                                      )";
                $nurse_order_stmt = $db->prepare($nurse_order_query);
                $nurse_order_stmt->bindParam(":admission_id", $admission_id);
                $nurse_order_stmt->bindParam(":details", $lab_order_details);
                $nurse_order_stmt->bindParam(":ordered_by", $_SESSION['user_id']);
                $nurse_order_stmt->execute();

                $success_message = "Lab test requested successfully";
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get active orders
$active_orders_query = "SELECT do.*, u.name as ordered_by_name, n.name as claimed_by_name 
                       FROM doctors_orders do
                       JOIN users u ON do.ordered_by = u.id
                       LEFT JOIN users n ON do.claimed_by = n.id
                       WHERE do.admission_id = :admission_id
                       AND do.status IN ('active','in_progress')
                       ORDER BY do.ordered_at DESC";
$active_orders_stmt = $db->prepare($active_orders_query);
$active_orders_stmt->bindParam(":admission_id", $admission_id);
$active_orders_stmt->execute();
$active_orders = $active_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get discontinued orders
$discontinued_orders_query = "SELECT do.*, 
                                   u1.name as ordered_by_name,
                                   u2.name as discontinued_by_name
                            FROM doctors_orders do
                            JOIN users u1 ON do.ordered_by = u1.id
                            JOIN users u2 ON do.discontinued_by = u2.id
                            WHERE do.admission_id = :admission_id
                            AND do.status = 'discontinued'
                            ORDER BY do.discontinued_at DESC";
$discontinued_orders_stmt = $db->prepare($discontinued_orders_query);
$discontinued_orders_stmt->bindParam(":admission_id", $admission_id);
$discontinued_orders_stmt->execute();
$discontinued_orders = $discontinued_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Doctor\'s Orders';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor's Orders - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .order-card {
            border-left: 4px solid #dc3545;
            margin-bottom: 15px;
        }
        .order-type-medication { border-left-color: #0d6efd; }
        .order-type-lab_test { border-left-color: #198754; }
        .order-type-diagnostic { border-left-color: #6f42c1; }
        .order-type-diet { border-left-color: #fd7e14; }
        .order-type-activity { border-left-color: #20c997; }
        .order-type-monitoring { border-left-color: #0dcaf0; }
        .discontinued { opacity: 0.7; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col d-flex justify-content-between align-items-center">
                <h2 class="mb-0"><i class="fas fa-file-medical me-2"></i>Doctor's Orders</h2>
                <a href="doctor_inpatients.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>
        </div>

        <!-- Patient Information Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="card-title"><?php echo htmlspecialchars($admission['patient_name']); ?></h5>
                        <p class="card-text">
                            <?php echo $admission['age']; ?> years, <?php echo ucfirst($admission['gender']); ?><br>
                            Room <?php echo $admission['room_number']; ?>, Bed <?php echo $admission['bed_number']; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="card-text">
                            <strong>Admitting Doctor:</strong> <?php echo htmlspecialchars($admission['admitting_doctor_name']); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Active Orders -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Active Orders</h5>
                        <div class="d-flex gap-2">
                            <?php if ($can_order): ?>
                            <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#newOrderModal">
                                <i class="fas fa-plus me-2"></i>New Order
                            </button>
                            <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#labRequestModal">
                                <i class="fas fa-flask me-2"></i>Request Lab Test
                            </button>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#dischargeModal">
                                <i class="fas fa-door-open me-2"></i>Discharge Order
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body" id="activeOrdersBody">
                        <?php if (empty($active_orders)): ?>
                            <p class="text-muted text-center py-3">No active orders</p>
                        <?php else: ?>
                            <?php foreach ($active_orders as $order): ?>
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
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="prepareDiscontinue(<?php echo $order['id']; ?>)">
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
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Discontinued Orders -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Discontinued Orders</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($discontinued_orders)): ?>
                            <p class="text-muted text-center py-3">No discontinued orders</p>
                        <?php else: ?>
                            <?php foreach ($discontinued_orders as $order): ?>
                                <div class="card order-card discontinued mb-3">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <?php echo ucfirst(str_replace('_', ' ', $order['order_type'])); ?>
                                        </h6>
                                        <p class="card-text"><?php echo nl2br(htmlspecialchars($order['order_details'])); ?></p>
                                        <p class="card-text"><small class="text-danger">
                                            <i class="fas fa-ban me-1"></i>
                                            Discontinued by <?php echo htmlspecialchars($order['discontinued_by_name']); ?>
                                            on <?php echo date('M d, Y h:i A', strtotime($order['discontinued_at'])); ?>
                                        </small></p>
                                        <?php if ($order['discontinue_reason']): ?>
                                            <p class="card-text"><small class="text-muted">
                                                Reason: <?php echo htmlspecialchars($order['discontinue_reason']); ?>
                                            </small></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- New Order Modal -->
    <div class="modal fade" id="newOrderModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_order">
                    <input type="hidden" name="is_newborn_request" id="is_newborn_request_input" value="0">
                    <div class="modal-header">
                        <h5 class="modal-title">New Order</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3" id="standardOrderFields">
                            <div class="col-md-6">
                                <label class="form-label">Order Type</label>
                                <select name="order_type" class="form-select" required id="order_type_select">
                                    <option value="">Select type...</option>
                                    <option value="medication">Medication</option>
                                    <option value="diagnostic">Diagnostic Test</option>
                                    <option value="diet">Diet</option>
                                    <option value="activity">Activity</option>
                                    <option value="monitoring">Monitoring</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Frequency</label>
                                <input type="text" name="frequency" class="form-control" 
                                       placeholder="e.g., Once daily, Every 4 hours, PRN">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Order Details</label>
                                <textarea name="order_details" class="form-control" rows="3" required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Duration</label>
                                <input type="text" name="duration" class="form-control" 
                                       placeholder="e.g., 5 days, Until discontinued">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Special Instructions</label>
                                <textarea name="special_instructions" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        <?php if ($is_specialist_doctor): ?>
                        <div id="newbornToggleContainer" class="mt-3 d-none">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="newbornRequestToggle">
                                <label class="form-check-label fw-semibold" for="newbornRequestToggle">
                                    Request newborn information (sends task to Nurses)
                                </label>
                            </div>
                            <small class="text-muted">Use this when you need the nursing team to provide newborn details for birth certificates.</small>
                        </div>
                        <div id="newbornRequestPanel" class="border rounded p-3 mt-3 d-none">
                            <h6 class="fw-semibold mb-2"><i class="fas fa-baby me-2"></i>Newborn Information Request</h6>
                            <p class="text-muted mb-3">A task will be sent to nurse orders. Nurses will fill out the newborn details and forward them to Medical Records for review.</p>
                            <div class="mb-3">
                                <label class="form-label">Additional Notes (optional)</label>
                                <textarea class="form-control" name="newborn_request_notes" id="newborn_request_notes" rows="3" placeholder="Add any instructions for the nurse..."></textarea>
                            </div>
                            <div class="alert alert-light border d-flex align-items-center mb-0">
                                <i class="fas fa-info-circle text-danger me-2"></i>
                                <div>Only the confirmation button below will appear while this mode is enabled.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="standardOrderSubmit">Add Order</button>
                        <?php if ($is_specialist_doctor): ?>
                        <button type="submit" class="btn btn-danger d-none" id="newbornOrderSubmit">
                            <i class="fas fa-paper-plane me-1"></i>Confirm Newborn Request
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Discontinue Order Modal -->
    <div class="modal fade" id="discontinueModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="discontinue_order">
                    <input type="hidden" name="order_id" id="discontinue_order_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Discontinue Order</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Reason for Discontinuing</label>
                            <textarea name="discontinue_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Discontinue Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Discharge Order Modal -->
    <div class="modal fade" id="dischargeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_order">
                    <div class="modal-header">
                        <h5 class="modal-title">Discharge Order</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="order_type" value="discharge">
                        <input type="hidden" name="is_discharge_order" value="1">
                        <div class="mb-3">
                            <label class="form-label">Discharge Instructions</label>
                            <textarea class="form-control" name="order_details" rows="3" placeholder="Discharge instructions, follow-up, medications, etc." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Discharge Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Lab Request Modal -->
    <div class="modal fade" id="labRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="request_lab">
                    <div class="modal-header">
                        <h5 class="modal-title">Request Lab Test (Inpatient)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Test</label>
                            <div class="position-relative">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" id="labTestSearch" placeholder="Search tests...">
                                    <select class="form-select" id="labTestType" style="max-width: 160px;">
                                        <option value="">All Types</option>
                                        <option value="laboratory">Laboratory</option>
                                        <option value="radiology">Radiology</option>
                                    </select>
                                </div>
                                <div id="labTestResults" class="list-group position-absolute w-100" style="top: calc(100% + 4px); z-index: 2055; display: none; max-height: 260px; overflow-y: auto;"></div>
                            </div>
                            <select class="form-select mt-2" name="lab_test_id" id="labTestSelect" required>
                                <option value="">Select test...</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="lab_priority">
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                                <option value="emergency">Emergency</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="lab_notes" rows="3" placeholder="Clinical notes or instructions"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function prepareDiscontinue(orderId) {
            document.getElementById('discontinue_order_id').value = orderId;
            new bootstrap.Modal(document.getElementById('discontinueModal')).show();
        }
        // Poll for active orders every 10s
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.col-md-8 .card.mb-4 .card-body');
            function refreshOrders() {
                fetch('fetch_doctor_orders.php?admission_id=<?php echo $admission_id; ?>')
                    .then(r => r.text())
                    .then(html => {
                        if (container) {
                            if (html.trim() === '') {
                                container.innerHTML = '<p class="text-muted text-center py-3">No active orders</p>';
                            } else {
                                container.innerHTML = html;
                            }
                        }
                    })
                    .catch(() => {});
            }
            setInterval(refreshOrders, 10000);

            // Lab test search in modal
            const testSearch = document.getElementById('labTestSearch');
            const testType = document.getElementById('labTestType');
            const testSelect = document.getElementById('labTestSelect');
            const testResults = document.getElementById('labTestResults');
            function loadTests() {
                const q = encodeURIComponent(testSearch.value || '');
                const t = encodeURIComponent(testType.value || '');
                fetch('search_lab_tests.php?q=' + q + '&type=' + t)
                  .then(r => r.json())
                  .then(data => {
                    if (!data.success) return;
                    testSelect.innerHTML = '<option value="">Select test...</option>';
                    testResults.innerHTML = '';
                    let hadItems = false;
                    (data.tests || []).forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item.id;
                        opt.textContent = (item.test_type.charAt(0).toUpperCase() + item.test_type.slice(1)) + ' - ' + item.test_name + ' (₱' + Number(item.cost).toFixed(2) + ')';
                        testSelect.appendChild(opt);

                        // Suggestion row
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                        btn.innerHTML = `<span>${item.test_name} <small class=\"text-muted\">(${item.test_type})</small></span><span class=\"badge bg-light text-dark\">₱${Number(item.cost).toFixed(2)}</span>`;
                        btn.addEventListener('click', function(){
                            testSelect.value = String(item.id);
                            testResults.style.display = 'none';
                        });
                        testResults.appendChild(btn);
                        hadItems = true;
                    });
                    testResults.style.display = hadItems && (testSearch.value || '').length ? 'block' : 'none';
                  })
                  .catch(() => {});
            }
            if (testSearch && testType && testSelect) {
                testSearch.addEventListener('input', function(){ setTimeout(loadTests, 200); });
                testType.addEventListener('change', loadTests);
                // preload on modal show
                document.getElementById('labRequestModal').addEventListener('show.bs.modal', loadTests);
                document.addEventListener('click', function(e){
                    if (testResults && !testResults.contains(e.target) && e.target !== testSearch) {
                        testResults.style.display = 'none';
                    }
                });
            }

            // Newborn request toggle logic
            (function(){
                const orderTypeSelect = document.getElementById('order_type_select');
                const toggleContainer = document.getElementById('newbornToggleContainer');
                const toggleInput = document.getElementById('newbornRequestToggle');
                const hiddenInput = document.getElementById('is_newborn_request_input');
                const panel = document.getElementById('newbornRequestPanel');
                const standardFields = document.getElementById('standardOrderFields');
                const standardSubmit = document.getElementById('standardOrderSubmit');
                const newbornSubmit = document.getElementById('newbornOrderSubmit');
                const modalEl = document.getElementById('newOrderModal');
                const notesField = document.getElementById('newborn_request_notes');
                const orderDetailsField = document.querySelector('textarea[name="order_details"]');
                if (!orderTypeSelect || !hiddenInput) return;

                function setNewbornState(enabled) {
                    hiddenInput.value = enabled ? '1' : '0';
                    if (toggleInput) toggleInput.checked = enabled;
                    if (standardFields) standardFields.style.display = enabled ? 'none' : '';
                    if (panel) panel.classList.toggle('d-none', !enabled);
                    if (standardSubmit) standardSubmit.classList.toggle('d-none', enabled);
                    if (newbornSubmit) newbornSubmit.classList.toggle('d-none', !enabled);
                    if (orderDetailsField) {
                        if (enabled) {
                            orderDetailsField.dataset.wasRequired = orderDetailsField.hasAttribute('required') ? '1' : '0';
                            orderDetailsField.removeAttribute('required');
                            orderDetailsField.value = '';
                        } else if (orderDetailsField.dataset.wasRequired !== '0') {
                            orderDetailsField.setAttribute('required', 'required');
                        }
                    }
                    if (!enabled && notesField) {
                        notesField.value = '';
                    }
                }

                function updateToggleVisibility() {
                    if (!toggleContainer) return;
                    const showToggle = orderTypeSelect.value === 'other';
                    toggleContainer.classList.toggle('d-none', !showToggle);
                    if (!showToggle) {
                        setNewbornState(false);
                    }
                }

                orderTypeSelect.addEventListener('change', updateToggleVisibility);
                if (toggleInput) {
                    toggleInput.addEventListener('change', function(){
                        setNewbornState(toggleInput.checked);
                    });
                }
                if (modalEl) {
                    modalEl.addEventListener('hidden.bs.modal', function(){
                        setNewbornState(false);
                        if (toggleContainer) toggleContainer.classList.add('d-none');
                        orderTypeSelect.value = '';
                    });
                }
            })();
        });
    </script>
</body>
</html> 
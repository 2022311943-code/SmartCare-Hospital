<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Get admission ID from URL
$admission_id = isset($_GET['admission_id']) ? intval($_GET['admission_id']) : 0;

// Verify admission exists
$admission_query = "SELECT pa.*, p.name as patient_name, p.age, p.gender,
                          r.room_number, b.bed_number,
                          u1.name as admitting_doctor_name,
                          u2.name as attending_doctor_name
                   FROM patient_admissions pa
                   JOIN patients p ON pa.patient_id = p.id
                   JOIN rooms r ON pa.room_id = r.id
                   JOIN beds b ON pa.bed_id = b.id
                   LEFT JOIN users u1 ON pa.admitting_doctor_id = u1.id
                   LEFT JOIN users u2 ON pa.attending_doctor_id = u2.id
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        // Record medication administration
        $mar_query = "INSERT INTO medication_administration_records 
                     (order_id, administered_by, administered_at, dose_given, route, status, notes)
                     VALUES 
                     (:order_id, :administered_by, :administered_at, :dose_given, :route, :status, :notes)";
        
        $mar_stmt = $db->prepare($mar_query);
        $mar_stmt->bindParam(":order_id", $_POST['order_id']);
        $mar_stmt->bindParam(":administered_by", $_SESSION['user_id']);
        $mar_stmt->bindParam(":administered_at", $_POST['administered_at']);
        $mar_stmt->bindParam(":dose_given", $_POST['dose_given']);
        $mar_stmt->bindParam(":route", $_POST['route']);
        $mar_stmt->bindParam(":status", $_POST['status']);
        $mar_stmt->bindParam(":notes", $_POST['notes']);
        $mar_stmt->execute();

        $db->commit();
        $success_message = "Medication administration recorded successfully";
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get active medication orders
$orders_query = "SELECT do.*, u.name as ordered_by_name 
                FROM doctors_orders do
                JOIN users u ON do.ordered_by = u.id
                WHERE do.admission_id = :admission_id 
                AND do.status = 'active'
                AND do.order_type = 'medication'
                ORDER BY do.ordered_at DESC";
$orders_stmt = $db->prepare($orders_query);
$orders_stmt->bindParam(":admission_id", $admission_id);
$orders_stmt->execute();
$medication_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get medication administration history
$mar_query = "SELECT mar.*, do.order_details, u.name as nurse_name 
             FROM medication_administration_records mar
             JOIN doctors_orders do ON mar.order_id = do.id
             JOIN users u ON mar.administered_by = u.id
             WHERE do.admission_id = :admission_id
             ORDER BY mar.administered_at DESC";
$mar_stmt = $db->prepare($mar_query);
$mar_stmt->bindParam(":admission_id", $admission_id);
$mar_stmt->execute();
$mar_history = $mar_stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Medication Administration';
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medication Administration - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .mar-card {
            border-left: 4px solid #0d6efd;
            margin-bottom: 15px;
        }
        .status-given { color: #198754; }
        .status-held { color: #ffc107; }
        .status-refused { color: #dc3545; }
        .status-missed { color: #6c757d; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <h2><i class="fas fa-pills me-2"></i>Medication Administration Record</h2>
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
                            <strong>Admitting Doctor:</strong> <?php echo htmlspecialchars($admission['admitting_doctor_name']); ?><br>
                            <strong>Attending Doctor:</strong> <?php echo htmlspecialchars($admission['attending_doctor_name']); ?>
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
            <!-- Active Medication Orders -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Active Medication Orders</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($medication_orders)): ?>
                            <p class="text-muted text-center">No active medication orders</p>
                        <?php else: ?>
                            <?php foreach ($medication_orders as $order): ?>
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <p class="card-text"><?php echo nl2br(htmlspecialchars($order['order_details'])); ?></p>
                                        <?php if ($order['frequency']): ?>
                                            <p class="card-text"><small class="text-muted">
                                                <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($order['frequency']); ?>
                                            </small></p>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-danger" 
                                                onclick="prepareAdministration(<?php echo $order['id']; ?>, '<?php echo addslashes($order['order_details']); ?>')">
                                            Record Administration
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- MAR History -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Administration History</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($mar_history)): ?>
                            <p class="text-muted text-center py-3">No medication administration records yet</p>
                        <?php else: ?>
                            <?php foreach ($mar_history as $mar): ?>
                                <div class="mar-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="card-title">
                                                <?php echo htmlspecialchars($mar['order_details']); ?>
                                            </h6>
                                            <span class="badge bg-<?php 
                                                echo $mar['status'] === 'given' ? 'success' : 
                                                    ($mar['status'] === 'held' ? 'warning' : 
                                                    ($mar['status'] === 'refused' ? 'danger' : 'secondary')); 
                                            ?>">
                                                <?php echo ucfirst($mar['status']); ?>
                                            </span>
                                        </div>
                                        <p class="card-text">
                                            <strong>Dose Given:</strong> <?php echo htmlspecialchars($mar['dose_given']); ?><br>
                                            <strong>Route:</strong> <?php echo htmlspecialchars($mar['route']); ?>
                                        </p>
                                        <?php if ($mar['notes']): ?>
                                            <p class="card-text"><small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars($mar['notes']); ?>
                                            </small></p>
                                        <?php endif; ?>
                                        <div class="text-muted">
                                            <small>
                                                <i class="fas fa-user-nurse me-1"></i>
                                                <?php echo htmlspecialchars($mar['nurse_name']); ?> |
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M d, Y h:i A', strtotime($mar['administered_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Administration Modal -->
    <div class="modal fade" id="administrationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" class="needs-validation" novalidate>
                    <input type="hidden" name="order_id" id="order_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Record Medication Administration</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Medication Order</label>
                            <p class="form-control-plaintext" id="order_details"></p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Administration Date & Time</label>
                            <input type="datetime-local" name="administered_at" class="form-control" 
                                   value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Dose Given</label>
                            <input type="text" name="dose_given" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Route</label>
                            <select name="route" class="form-select" required>
                                <option value="">Select route...</option>
                                <option value="PO">PO (Oral)</option>
                                <option value="IV">IV (Intravenous)</option>
                                <option value="IM">IM (Intramuscular)</option>
                                <option value="SC">SC (Subcutaneous)</option>
                                <option value="TD">TD (Transdermal)</option>
                                <option value="PR">PR (Per Rectum)</option>
                                <option value="SL">SL (Sublingual)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="given">Given</option>
                                <option value="held">Held</option>
                                <option value="refused">Refused</option>
                                <option value="missed">Missed</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Record Administration</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function prepareAdministration(orderId, orderDetails) {
            document.getElementById('order_id').value = orderId;
            document.getElementById('order_details').textContent = orderDetails;
            new bootstrap.Modal(document.getElementById('administrationModal')).show();
        }

        // Form validation
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
    </script>
</body>
</html> 
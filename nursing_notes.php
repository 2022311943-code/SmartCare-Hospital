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

        // Prepare vital signs JSON
        $vital_signs = [
            'temperature' => $_POST['temperature'],
            'blood_pressure' => $_POST['blood_pressure'],
            'pulse_rate' => $_POST['pulse_rate'],
            'respiratory_rate' => $_POST['respiratory_rate'],
            'oxygen_saturation' => $_POST['oxygen_saturation'],
            'pain_score' => $_POST['pain_score']
        ];

        // Prepare intake/output JSON if provided
        $intake_output = null;
        if (!empty($_POST['intake']) || !empty($_POST['output'])) {
            $intake_output = [
                'intake' => [
                    'oral' => $_POST['intake_oral'],
                    'iv' => $_POST['intake_iv'],
                    'other' => $_POST['intake_other']
                ],
                'output' => [
                    'urine' => $_POST['output_urine'],
                    'stool' => $_POST['output_stool'],
                    'other' => $_POST['output_other']
                ]
            ];
        }

        // Insert nursing note
        $note_query = "INSERT INTO nursing_notes 
                      (admission_id, note_type, note_text, vital_signs, intake_output, created_by)
                      VALUES 
                      (:admission_id, :note_type, :note_text, :vital_signs, :intake_output, :created_by)";
        
        $note_stmt = $db->prepare($note_query);
        $note_stmt->bindParam(":admission_id", $admission_id);
        $note_stmt->bindParam(":note_type", $_POST['note_type']);
        $note_stmt->bindParam(":note_text", $_POST['note_text']);
        $note_stmt->bindParam(":vital_signs", json_encode($vital_signs));
        $note_stmt->bindParam(":intake_output", $intake_output ? json_encode($intake_output) : null);
        $note_stmt->bindParam(":created_by", $_SESSION['user_id']);
        $note_stmt->execute();

        $db->commit();
        $success_message = "Nursing note added successfully";
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get nursing notes for this admission
$notes_query = "SELECT nn.*, u.name as nurse_name 
                FROM nursing_notes nn
                JOIN users u ON nn.created_by = u.id
                WHERE nn.admission_id = :admission_id
                ORDER BY nn.created_at DESC";
$notes_stmt = $db->prepare($notes_query);
$notes_stmt->bindParam(":admission_id", $admission_id);
$notes_stmt->execute();
$nursing_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active doctor's orders
$orders_query = "SELECT * FROM doctors_orders 
                WHERE admission_id = :admission_id 
                AND status = 'active'
                ORDER BY ordered_at DESC";
$orders_stmt = $db->prepare($orders_query);
$orders_stmt->bindParam(":admission_id", $admission_id);
$orders_stmt->execute();
$active_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Nursing Notes';
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nursing Notes - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .note-card {
            border-left: 4px solid #dc3545;
            margin-bottom: 15px;
        }
        .note-type-assessment { border-left-color: #0d6efd; }
        .note-type-medication { border-left-color: #198754; }
        .note-type-intervention { border-left-color: #6f42c1; }
        .note-type-monitoring { border-left-color: #fd7e14; }
        .note-type-handover { border-left-color: #20c997; }
        .vital-signs {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <h2><i class="fas fa-notes-medical me-2"></i>Nursing Notes</h2>
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
            <!-- New Note Form -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Add New Note</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">Note Type</label>
                                <select name="note_type" class="form-select" required>
                                    <option value="">Select type...</option>
                                    <option value="assessment">Assessment</option>
                                    <option value="medication">Medication</option>
                                    <option value="intervention">Intervention</option>
                                    <option value="monitoring">Monitoring</option>
                                    <option value="handover">Handover</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Vital Signs</label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="text" name="temperature" class="form-control" 
                                               placeholder="Temperature" required>
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="blood_pressure" class="form-control" 
                                               placeholder="Blood Pressure" required>
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="pulse_rate" class="form-control" 
                                               placeholder="Pulse Rate" required>
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="respiratory_rate" class="form-control" 
                                               placeholder="Respiratory Rate" required>
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="oxygen_saturation" class="form-control" 
                                               placeholder="O2 Saturation">
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="pain_score" class="form-control" 
                                               placeholder="Pain Score">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Intake & Output</label>
                                <div class="row g-2">
                                    <div class="col-12"><strong>Intake (mL)</strong></div>
                                    <div class="col-4">
                                        <input type="number" name="intake_oral" class="form-control" 
                                               placeholder="Oral">
                                    </div>
                                    <div class="col-4">
                                        <input type="number" name="intake_iv" class="form-control" 
                                               placeholder="IV">
                                    </div>
                                    <div class="col-4">
                                        <input type="number" name="intake_other" class="form-control" 
                                               placeholder="Other">
                                    </div>
                                    <div class="col-12"><strong>Output (mL)</strong></div>
                                    <div class="col-4">
                                        <input type="number" name="output_urine" class="form-control" 
                                               placeholder="Urine">
                                    </div>
                                    <div class="col-4">
                                        <input type="number" name="output_stool" class="form-control" 
                                               placeholder="Stool">
                                    </div>
                                    <div class="col-4">
                                        <input type="number" name="output_other" class="form-control" 
                                               placeholder="Other">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Note</label>
                                <textarea name="note_text" class="form-control" rows="5" required></textarea>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-save me-2"></i>Save Note
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Active Orders Reference -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Active Orders</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($active_orders)): ?>
                            <p class="text-muted text-center">No active orders</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($active_orders as $order): ?>
                                    <li class="list-group-item">
                                        <strong><?php echo ucfirst($order['order_type']); ?></strong><br>
                                        <?php echo nl2br(htmlspecialchars($order['order_details'])); ?>
                                        <?php if ($order['frequency']): ?>
                                            <br><small class="text-muted">
                                                <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($order['frequency']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Notes History -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Notes History</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($nursing_notes)): ?>
                            <p class="text-muted text-center py-3">No notes recorded yet</p>
                        <?php else: ?>
                            <?php foreach ($nursing_notes as $note): ?>
                                <?php 
                                    $vital_signs = json_decode($note['vital_signs'], true);
                                    $intake_output = $note['intake_output'] ? json_decode($note['intake_output'], true) : null;
                                ?>
                                <div class="note-card note-type-<?php echo $note['note_type']; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="card-title">
                                                <?php echo ucfirst($note['note_type']); ?> Note
                                            </h6>
                                            <small class="text-muted">
                                                <?php echo date('M d, Y h:i A', strtotime($note['created_at'])); ?>
                                            </small>
                                        </div>

                                        <!-- Vital Signs -->
                                        <div class="vital-signs mb-3">
                                            <div class="row">
                                                <div class="col-md-2">
                                                    <small class="text-muted">Temp</small><br>
                                                    <?php echo $vital_signs['temperature']; ?>
                                                </div>
                                                <div class="col-md-2">
                                                    <small class="text-muted">BP</small><br>
                                                    <?php echo $vital_signs['blood_pressure']; ?>
                                                </div>
                                                <div class="col-md-2">
                                                    <small class="text-muted">Pulse</small><br>
                                                    <?php echo $vital_signs['pulse_rate']; ?>
                                                </div>
                                                <div class="col-md-2">
                                                    <small class="text-muted">RR</small><br>
                                                    <?php echo $vital_signs['respiratory_rate']; ?>
                                                </div>
                                                <div class="col-md-2">
                                                    <small class="text-muted">O2 Sat</small><br>
                                                    <?php echo $vital_signs['oxygen_saturation']; ?>
                                                </div>
                                                <div class="col-md-2">
                                                    <small class="text-muted">Pain</small><br>
                                                    <?php echo $vital_signs['pain_score']; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Intake/Output if available -->
                                        <?php if ($intake_output): ?>
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <small class="text-muted">Intake (mL):</small><br>
                                                    Oral: <?php echo $intake_output['intake']['oral']; ?>,
                                                    IV: <?php echo $intake_output['intake']['iv']; ?>,
                                                    Other: <?php echo $intake_output['intake']['other']; ?>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted">Output (mL):</small><br>
                                                    Urine: <?php echo $intake_output['output']['urine']; ?>,
                                                    Stool: <?php echo $intake_output['output']['stool']; ?>,
                                                    Other: <?php echo $intake_output['output']['other']; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <p class="card-text"><?php echo nl2br(htmlspecialchars($note['note_text'])); ?></p>
                                        
                                        <div class="text-muted">
                                            <small>
                                                <i class="fas fa-user-nurse me-1"></i>
                                                <?php echo htmlspecialchars($note['nurse_name']); ?>
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

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
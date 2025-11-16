<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || 
    !in_array($_SESSION['employee_type'], ['receptionist', 'medical_staff', 'admin_staff', 'medical_records', 'doctor', 'nurse'])) {
    header("Location: index.php");
    exit();
}

// Check if patient ID is provided
if (!isset($_GET['id'])) {
    header("Location: search_patients.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$error_message = '';

try {
    // Get patient basic information
    $patient_id = intval($_GET['id']); // Convert to integer
    
    $patient_query = "SELECT 
        p.id,
        p.name,
        p.age,
        p.gender,
        p.blood_group,
        p.phone,
        p.address,
        p.doctor_id,
        p.created_at,
        u.name as doctor_name,
        u.department as doctor_department
        FROM patients p
        LEFT JOIN users u ON p.doctor_id = u.id
        WHERE p.id = ?";
    
    $patient_stmt = $db->prepare($patient_query);
    $patient_stmt->execute([$patient_id]);
    $patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        throw new Exception("Patient not found");
    }

    // Get all OPD visits
    $visits_query = "SELECT 
        ov.*,
        u.name as doctor_name,
        u.department as doctor_department
    FROM opd_visits ov
    LEFT JOIN users u ON ov.doctor_id = u.id
        WHERE ov.patient_name = ? 
        AND ov.contact_number = ?
    ORDER BY ov.created_at DESC";
    
    $visits_stmt = $db->prepare($visits_query);
    $visits_stmt->execute([$patient['name'], $patient['phone']]);
    $visits = $visits_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all progress notes
    $progress_query = "SELECT 
        pp.*,
        u.name as doctor_name,
        u.department as doctor_department
        FROM patient_progress pp
        LEFT JOIN users u ON pp.doctor_id = u.id
        WHERE pp.patient_id = ?
        ORDER BY pp.progress_date DESC";
    
    $progress_stmt = $db->prepare($progress_query);
    $progress_stmt->execute([$patient_id]);
    $progress_notes = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all lab/radiology results
    $lab_query = "SELECT 
        lr.*,
        lt.test_name,
        lt.test_type,
        u.name as doctor_name,
        u.department as doctor_department
    FROM lab_requests lr
    JOIN lab_tests lt ON lr.test_id = lt.id
    JOIN opd_visits ov ON lr.visit_id = ov.id
        LEFT JOIN users u ON lr.doctor_id = u.id
        WHERE ov.patient_name = ? 
        AND ov.contact_number = ?
    ORDER BY lr.requested_at DESC";
    
    $lab_stmt = $db->prepare($lab_query);
    $lab_stmt->execute([$patient['name'], $patient['phone']]);
    $lab_results = $lab_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Error in view_patient.php: " . $e->getMessage());
}

// Set page title and include header
$page_title = $patient ? 'Patient Details: ' . $patient['name'] : 'Patient Not Found';
define('INCLUDED_IN_PAGE', true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            border-radius: 15px 15px 0 0 !important;
            border-bottom: 1px solid #eee;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            padding: 1rem 1.5rem;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #dc3545;
            border-bottom: 2px solid #dc3545;
            background: none;
        }
        .tab-content {
            padding: 20px 0;
        }
    </style>
</head>
<body>
    <?php if ($error_message): ?>
    <div class="container py-4">
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <div class="mt-3">
                    <a href="search_patients.php" class="btn btn-danger">
                <i class="fas fa-arrow-left me-2"></i>Back to Search
            </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php require_once 'includes/header.php'; ?>
        
        <div class="container py-4">
            <!-- Back button and patient name header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>
                    <i class="fas fa-user-circle me-2"></i>
                    <?php echo htmlspecialchars($patient['name']); ?>
                </h2>
                <a href="search_patients.php" class="btn btn-outline-danger">
                    <i class="fas fa-arrow-left me-2"></i>Back to Search
                </a>
            </div>

            <!-- Patient Basic Information Card -->
        <div class="card mb-4">
                <div class="card-header bg-danger text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="150">Age:</th>
                                    <td><?php echo htmlspecialchars($patient['age']); ?> years</td>
                                </tr>
                                <tr>
                                    <th>Gender:</th>
                                    <td><?php echo ucfirst(htmlspecialchars($patient['gender'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Blood Group:</th>
                                    <td><?php echo htmlspecialchars($patient['blood_group'] ?: 'Not specified'); ?></td>
                                </tr>
                            </table>
                    </div>
                    <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="150">Phone:</th>
                                    <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                </tr>
                                <tr>
                                    <th>Address:</th>
                                    <td><?php echo htmlspecialchars($patient['address']); ?></td>
                                </tr>
                                <tr>
                                    <th>Assigned Doctor:</th>
                                    <td>
                                        Dr. <?php echo htmlspecialchars($patient['doctor_name']); ?>
                                        <small class="text-muted d-block">
                                            <?php echo htmlspecialchars($patient['doctor_department']); ?>
                                        </small>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs for different sections -->
            <ul class="nav nav-tabs mb-4" id="patientTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="visits-tab" data-bs-toggle="tab" href="#visits" role="tab">
                        <i class="fas fa-calendar-check me-2"></i>OPD Visits
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="progress-tab" data-bs-toggle="tab" href="#progress" role="tab">
                        <i class="fas fa-notes-medical me-2"></i>Progress Notes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="lab-tab" data-bs-toggle="tab" href="#lab" role="tab">
                        <i class="fas fa-flask me-2"></i>Lab Results
                    </a>
                </li>
            </ul>

            <!-- Tab Contents -->
            <div class="tab-content" id="patientTabContent">
                <!-- OPD Visits Tab -->
                <div class="tab-pane fade show active" id="visits" role="tabpanel">
                    <?php if (empty($visits)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No OPD visits recorded yet.
            </div>
                <?php else: ?>
                        <?php foreach($visits as $visit): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                            <h6 class="mb-1">
                                                Visit Date: <?php echo date('M d, Y h:i A', strtotime($visit['created_at'])); ?>
                                            </h6>
                                        <span class="badge bg-<?php 
                                            echo match($visit['visit_status']) {
                                                'waiting' => 'warning',
                                                'in_progress' => 'info',
                                                'completed' => 'success',
                                                'cancelled' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst($visit['visit_status']); ?>
                                        </span>
                                            <span class="badge bg-primary ms-2">
                                                <?php echo ucfirst($visit['visit_type']); ?>
                                            </span>
                                        </div>
                                        <div class="text-muted small">
                                            Dr. <?php echo htmlspecialchars($visit['doctor_name']); ?>
                                            <small class="d-block"><?php echo htmlspecialchars($visit['doctor_department']); ?></small>
                                        </div>
                                    </div>

                                    <?php if ($visit['visit_status'] === 'completed'): ?>
                                        <div class="row g-3">
                                            <?php if ($visit['diagnosis']): ?>
                                                <div class="col-md-6">
                                                    <h6 class="text-danger">Diagnosis</h6>
                                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($visit['diagnosis'])); ?></p>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($visit['treatment_plan']): ?>
                                                <div class="col-md-6">
                                                    <h6 class="text-danger">Treatment Plan</h6>
                                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($visit['treatment_plan'])); ?></p>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($visit['prescription']): ?>
                                                <div class="col-12">
                                                    <h6 class="text-danger">Prescription</h6>
                                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($visit['prescription'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($visit['follow_up_date']): ?>
                                            <div class="mt-3 pt-3 border-top">
                                                <i class="fas fa-calendar-alt text-danger me-2"></i>
                                                Follow-up Date: <?php echo date('M d, Y', strtotime($visit['follow_up_date'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <h6 class="text-danger">Symptoms</h6>
                                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($visit['symptoms'])); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="text-danger">Vital Signs</h6>
                                                <ul class="list-unstyled mb-0">
                                                    <li>Temperature: <?php echo htmlspecialchars($visit['temperature']); ?>°C</li>
                                                    <li>Blood Pressure: <?php echo htmlspecialchars($visit['blood_pressure']); ?></li>
                                                    <li>Pulse Rate: <?php echo htmlspecialchars($visit['pulse_rate']); ?> bpm</li>
                                                    <li>Respiratory Rate: <?php echo htmlspecialchars($visit['respiratory_rate']); ?></li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Progress Notes Tab -->
                <div class="tab-pane fade" id="progress" role="tabpanel">
                    <?php if (empty($progress_notes)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No progress notes recorded yet.
                        </div>
                    <?php else: ?>
                        <?php foreach($progress_notes as $note): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h6 class="mb-1">
                                            <?php echo date('M d, Y h:i A', strtotime($note['progress_date'])); ?>
                                        </h6>
                                        <div class="text-muted small">
                                            Dr. <?php echo htmlspecialchars($note['doctor_name']); ?>
                                            <small class="d-block"><?php echo htmlspecialchars($note['doctor_department']); ?></small>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <h6 class="text-danger">Progress Note</h6>
                                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($note['progress_note'])); ?></p>
                                        </div>

                                        <?php if ($note['vital_signs']): ?>
                                            <div class="col-md-6">
                                                <h6 class="text-danger">Vital Signs</h6>
                                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($note['vital_signs'])); ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($note['treatment_response']): ?>
                                            <div class="col-md-6">
                                                <h6 class="text-danger">Treatment Response</h6>
                                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($note['treatment_response'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($note['next_appointment']): ?>
                                        <div class="mt-3 pt-3 border-top">
                                            <i class="fas fa-calendar-alt text-danger me-2"></i>
                                            Next Appointment: <?php echo date('M d, Y', strtotime($note['next_appointment'])); ?>
                    </div>
                <?php endif; ?>
            </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
        </div>

                <!-- Lab Results Tab -->
                <div class="tab-pane fade" id="lab" role="tabpanel">
                    <?php if (empty($lab_results)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No laboratory or radiology results found.
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-md-3">
                                <!-- Test type filter -->
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="mb-3">Filter by Type</h6>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="testType" id="allTests" value="all" checked>
                                            <label class="form-check-label" for="allTests">All Tests</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="testType" id="labTests" value="laboratory">
                                            <label class="form-check-label" for="labTests">Laboratory</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="testType" id="radioTests" value="radiology">
                                            <label class="form-check-label" for="radioTests">Radiology</label>
                                        </div>
                                    </div>
                                </div>
            </div>

                            <div class="col-md-9">
                                <!-- Test results -->
                                <?php foreach($lab_results as $result): ?>
                                    <div class="card mb-3 test-result" data-type="<?php echo $result['test_type']; ?>">
            <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($result['test_name']); ?></h6>
                                <span class="badge bg-<?php 
                                                        echo match($result['status']) {
                                                            'pending_payment' => 'warning',
                                                            'pending' => 'info',
                                                            'in_progress' => 'primary',
                                        'completed' => 'success',
                                        'cancelled' => 'danger',
                                        default => 'secondary'
                                    };
                                ?>">
                                                        <?php echo ucwords(str_replace('_', ' ', $result['status'])); ?>
                                                    </span>
                                                    <span class="badge bg-<?php echo $result['test_type'] === 'laboratory' ? 'info' : 'danger'; ?> ms-2">
                                                        <?php echo ucfirst($result['test_type']); ?>
                                </span>
                            </div>
                                                <div class="text-muted small">
                                                    Requested: <?php echo date('M d, Y', strtotime($result['requested_at'])); ?>
                                                    <small class="d-block">
                                                        Dr. <?php echo htmlspecialchars($result['doctor_name']); ?>
                                                    </small>
                                                </div>
                        </div>

                                            <?php if ($result['status'] === 'completed'): ?>
                                                <div class="mt-3">
                                                    <?php if ($result['result']): ?>
                                                        <h6 class="text-danger">Results</h6>
                                                        <p class="mb-3"><?php echo nl2br(htmlspecialchars($result['result'])); ?></p>
                        <?php endif; ?>

                                                    <?php if ($result['result_file_path']): ?>
                                                        <a href="<?php echo htmlspecialchars($result['result_file_path']); ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                       target="_blank">
                                                            <i class="fas fa-file-medical me-2"></i>View Result File
                                    </a>
                                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Test type filter functionality
        const testTypeRadios = document.querySelectorAll('input[name="testType"]');
        const testResults = document.querySelectorAll('.test-result');

        testTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const selectedType = this.value;
                testResults.forEach(result => {
                    if (selectedType === 'all' || result.dataset.type === selectedType) {
                        result.style.display = 'block';
                    } else {
                        result.style.display = 'none';
                    }
                });
            });
        });
    });
    </script>
</body>
</html> 
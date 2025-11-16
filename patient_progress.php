<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a doctor
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'doctor') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = "";
$error_message = "";

// Handle form submission for adding progress note
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_progress') {
        try {
            $query = "INSERT INTO patient_progress (patient_id, doctor_id, progress_note, vital_signs, treatment_response, next_appointment) 
                     VALUES (:patient_id, :doctor_id, :progress_note, :vital_signs, :treatment_response, :next_appointment)";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(":patient_id", $_POST['patient_id']);
            $stmt->bindParam(":doctor_id", $_SESSION['user_id']);
            $stmt->bindParam(":progress_note", $_POST['progress_note']);
            $stmt->bindParam(":vital_signs", $_POST['vital_signs']);
            $stmt->bindParam(":treatment_response", $_POST['treatment_response']);
            $stmt->bindParam(":next_appointment", $_POST['next_appointment']);
            
            if($stmt->execute()) {
                $success_message = "Progress note added successfully!";
            } else {
                $error_message = "Error adding progress note.";
            }
        } catch(PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Get doctor's patients
$patients_query = "SELECT * FROM patients WHERE doctor_id = :doctor_id ORDER BY name ASC";
$patients_stmt = $db->prepare($patients_query);
$patients_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
$patients_stmt->execute();
$patients = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get patient progress if patient is selected
$selected_patient = null;
$progress_notes = [];
if (isset($_GET['patient_id'])) {
    // Get patient details
    $patient_query = "SELECT * FROM patients WHERE id = :patient_id AND doctor_id = :doctor_id";
    $patient_stmt = $db->prepare($patient_query);
    $patient_stmt->bindParam(":patient_id", $_GET['patient_id']);
    $patient_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
    $patient_stmt->execute();
    $selected_patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_patient) {
        // Get progress notes
        $progress_query = "SELECT * FROM patient_progress 
                          WHERE patient_id = :patient_id 
                          ORDER BY progress_date DESC";
        $progress_stmt = $db->prepare($progress_query);
        $progress_stmt->bindParam(":patient_id", $_GET['patient_id']);
        $progress_stmt->execute();
        $progress_notes = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Progress - SmartCare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            padding: 1rem;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-radius: 15px 15px 0 0 !important;
            border-bottom: 1px solid #eee;
        }
        .progress-note {
            border-left: 4px solid #dc3545;
            padding-left: 1rem;
            margin-bottom: 1.5rem;
        }
        .progress-date {
            color: #6c757d;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-hospital me-2"></i>
                Hospital Management System
            </a>
            <div class="d-flex align-items-center">
                <a href="dashboard.php" class="btn btn-outline-light me-2">
                    <i class="fas fa-home me-2"></i>Dashboard
                </a>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Patient Selection -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Select Patient</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <select class="form-select" name="patient_id" onchange="this.form.submit()">
                            <option value="">Select a patient...</option>
                            <?php foreach($patients as $patient): ?>
                                <option value="<?php echo $patient['id']; ?>" <?php echo (isset($_GET['patient_id']) && $_GET['patient_id'] == $patient['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($patient['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selected_patient): ?>
            <!-- Add Progress Note -->
            <div class="card mb-4">
                <div class="card-header py-3">
                    <h5 class="mb-0"><i class="fas fa-notes-medical me-2"></i>Add Progress Note</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_progress">
                        <input type="hidden" name="patient_id" value="<?php echo $selected_patient['id']; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Progress Note</label>
                            <textarea class="form-control" name="progress_note" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Vital Signs</label>
                            <textarea class="form-control" name="vital_signs" rows="2"></textarea>
                            <div class="form-text">Blood pressure, temperature, pulse rate, etc.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Treatment Response</label>
                            <textarea class="form-control" name="treatment_response" rows="2"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Next Appointment</label>
                            <input type="date" class="form-control" name="next_appointment">
                        </div>

                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-plus me-2"></i>Add Progress Note
                        </button>
                    </form>
                </div>
            </div>

            <!-- Progress History -->
            <div class="card">
                <div class="card-header py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>
                        Progress History for <?php echo htmlspecialchars($selected_patient['name']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($progress_notes)): ?>
                        <p class="text-muted">No progress notes recorded yet.</p>
                    <?php else: ?>
                        <?php foreach($progress_notes as $note): ?>
                            <div class="progress-note">
                                <div class="progress-date">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    <?php echo date('M d, Y h:i A', strtotime($note['progress_date'])); ?>
                                </div>
                                <h6 class="mt-2 mb-2">Progress Note:</h6>
                                <p><?php echo nl2br(htmlspecialchars($note['progress_note'])); ?></p>
                                
                                <?php if ($note['vital_signs']): ?>
                                    <h6 class="mb-2">Vital Signs:</h6>
                                    <p><?php echo nl2br(htmlspecialchars($note['vital_signs'])); ?></p>
                                <?php endif; ?>

                                <?php if ($note['treatment_response']): ?>
                                    <h6 class="mb-2">Treatment Response:</h6>
                                    <p><?php echo nl2br(htmlspecialchars($note['treatment_response'])); ?></p>
                                <?php endif; ?>

                                <?php if ($note['next_appointment']): ?>
                                    <h6 class="mb-2">Next Appointment:</h6>
                                    <p><?php echo date('M d, Y', strtotime($note['next_appointment'])); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
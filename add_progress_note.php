<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and is a general doctor
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'general_doctor') {
    header("Location: index.php");
    exit();
}

// Check if visit_id is provided
if (!isset($_GET['visit_id'])) {
    header("Location: opd_queue.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get visit details
$visit_query = "SELECT v.*, pr.id as patient_record_id 
                FROM opd_visits v 
                LEFT JOIN patient_records pr ON v.patient_record_id = pr.id 
                WHERE v.id = :visit_id AND v.doctor_id = :doctor_id AND v.visit_status = 'in_progress'";
$visit_stmt = $db->prepare($visit_query);
$visit_stmt->bindParam(":visit_id", $_GET['visit_id']);
$visit_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
$visit_stmt->execute();
$visit = $visit_stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    header("Location: opd_queue.php");
    exit();
}

// If patient_record_id is null, create a new patient record
if (!$visit['patient_record_id']) {
    try {
        $db->beginTransaction();
        
        // Insert into patient_records
        $insert_record_query = "INSERT INTO patient_records (
            patient_name, age, gender, contact_number, address
        ) VALUES (
            :patient_name, :age, :gender, :contact_number, :address
        )";
        
        $insert_record_stmt = $db->prepare($insert_record_query);
        // Values in opd_visits are stored encrypted for sensitive fields already
        $insert_record_stmt->bindParam(":patient_name", $visit['patient_name']);
        $insert_record_stmt->bindParam(":age", $visit['age']);
        $insert_record_stmt->bindParam(":gender", $visit['gender']);
        $insert_record_stmt->bindParam(":contact_number", $visit['contact_number']);
        $insert_record_stmt->bindParam(":address", $visit['address']);
        
        if ($insert_record_stmt->execute()) {
            $patient_record_id = $db->lastInsertId();
            
            // Update opd_visit with patient_record_id
            $update_visit_query = "UPDATE opd_visits SET patient_record_id = :patient_record_id WHERE id = :visit_id";
            $update_visit_stmt = $db->prepare($update_visit_query);
            $update_visit_stmt->bindParam(":patient_record_id", $patient_record_id);
            $update_visit_stmt->bindParam(":visit_id", $visit['id']);
            
            if ($update_visit_stmt->execute()) {
                $db->commit();
                $visit['patient_record_id'] = $patient_record_id;
            } else {
                throw new Exception("Failed to update visit with patient record");
            }
        } else {
            throw new Exception("Failed to create patient record");
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// Get all previous progress notes for this patient
$notes_query = "SELECT pn.*, u.name as doctor_name, v.visit_type, v.created_at as visit_date
                FROM patient_progress_notes pn
                JOIN users u ON pn.doctor_id = u.id
                JOIN opd_visits v ON pn.visit_id = v.id
                WHERE pn.patient_record_id = :patient_record_id
                ORDER BY pn.created_at DESC";
$notes_stmt = $db->prepare($notes_query);
$notes_stmt->bindParam(":patient_record_id", $visit['patient_record_id']);
$notes_stmt->execute();
$previous_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($previous_notes as &$pn) {
    if (isset($pn['note_text'])) { $pn['note_text'] = decrypt_safe((string)$pn['note_text']); }
}
unset($pn);

$success_message = "";
$error_message = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $db->beginTransaction();
        
        // Insert into patient_progress_notes
        $insert_note_query = "INSERT INTO patient_progress_notes (
            patient_record_id, visit_id, note_text, doctor_id
        ) VALUES (
            :patient_record_id, :visit_id, :note_text, :doctor_id
        )";
        
        $insert_note_stmt = $db->prepare($insert_note_query);
        $insert_note_stmt->bindParam(":patient_record_id", $visit['patient_record_id']);
        $insert_note_stmt->bindParam(":visit_id", $visit['id']);
        $enc_note = encrypt_strict((string)$_POST['progress_note']);
        $insert_note_stmt->bindParam(":note_text", $enc_note);
        $insert_note_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
        
        if ($insert_note_stmt->execute()) {
            $db->commit();
            $success_message = "Progress note added successfully!";
            
            // Refresh notes list
            $notes_stmt->execute();
            $previous_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($previous_notes as &$pn) {
                if (isset($pn['note_text'])) { $pn['note_text'] = decrypt_safe((string)$pn['note_text']); }
            }
            unset($pn);
        } else {
            throw new Exception("Failed to add progress note");
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Progress Note - SmartCare</title>
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
        .navbar-brand {
            color: white !important;
            font-weight: 600;
        }
        .nav-link {
            color: rgba(255,255,255,0.9) !important;
        }
        .nav-link:hover {
            color: white !important;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .card-header {
            background: white;
            border-radius: 15px 15px 0 0 !important;
            border-bottom: 1px solid #eee;
        }
        .previous-notes {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        .note-timestamp {
            color: #6c757d;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-hospital me-2"></i>
                Hospital Management System
            </a>
            <div class="d-flex align-items-center">
                <a href="opd_queue.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-list me-2"></i>Back to Queue
                </a>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
                <a href="logout.php" class="btn btn-light btn-sm">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-notes-medical me-2"></i>
                                Add Progress Note
                            </h5>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Patient Info -->
                        <div class="alert alert-info">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Patient:</strong> <?php echo htmlspecialchars($visit['patient_name']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Age:</strong> <?php echo htmlspecialchars($visit['age']); ?> years
                                </div>
                                <div class="col-md-3">
                                    <strong>Gender:</strong> <?php echo ucfirst(htmlspecialchars($visit['gender'])); ?>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <strong>Visit Type:</strong> 
                                    <span class="badge bg-<?php echo $visit['visit_type'] === 'follow_up' ? 'info' : 'primary'; ?>">
                                        <?php echo $visit['visit_type'] === 'follow_up' ? 'Follow-up Visit' : 'New Visit'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($success_message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Previous Notes -->
                        <?php if (!empty($previous_notes)): ?>
                            <h6 class="mb-3">Patient History</h6>
                            <div class="previous-notes mb-4">
                                <?php foreach($previous_notes as $note): ?>
                                    <div class="note-entry mb-3 p-3 border-start border-danger border-3 rounded bg-light">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <span class="badge bg-<?php echo $note['visit_type'] === 'follow_up' ? 'info' : 'primary'; ?> me-2">
                                                    <?php echo $note['visit_type'] === 'follow_up' ? 'Follow-up Visit' : 'New Visit'; ?>
                                                </span>
                                                <span class="text-muted small">
                                                    <i class="fas fa-user-md me-1"></i>
                                                    Dr. <?php echo htmlspecialchars($note['doctor_name']); ?>
                                                </span>
                                            </div>
                                            <span class="text-muted small">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                <?php echo date('M d, Y h:i A', strtotime($note['created_at'])); ?>
                                            </span>
                                        </div>
                                        <div class="note-content">
                                            <?php echo nl2br(htmlspecialchars($note['note_text'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Add Note Form -->
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Progress Note</label>
                                <textarea class="form-control" name="progress_note" rows="5" required></textarea>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="opd_queue.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Queue
                                </a>
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-save me-2"></i>Save Progress Note
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
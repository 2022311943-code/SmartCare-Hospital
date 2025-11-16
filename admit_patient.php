<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        // Find available bed
        $bed_query = "SELECT b.id as bed_id, b.bed_number, r.id as room_id, r.room_number, r.room_type, r.floor_number 
                     FROM beds b 
                     JOIN rooms r ON b.room_id = r.id 
                     WHERE b.status = 'available' 
                     AND r.status = 'active'
                     ORDER BY r.room_type = :preferred_room_type DESC, 
                             r.floor_number = :preferred_floor DESC,
                             r.room_number, b.bed_number
                     LIMIT 1";
        
        $bed_stmt = $db->prepare($bed_query);
        $bed_stmt->bindParam(":preferred_room_type", $_POST['preferred_room_type']);
        $bed_stmt->bindParam(":preferred_floor", $_POST['preferred_floor']);
        $bed_stmt->execute();
        
        $bed = $bed_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$bed) {
            throw new Exception("No available beds found.");
        }

        // Create admission record
        $admission_query = "INSERT INTO patient_admissions 
                          (patient_id, room_id, bed_id, admission_date, expected_discharge_date, 
                           admission_notes, admitted_by) 
                          VALUES 
                          (:patient_id, :room_id, :bed_id, :admission_date, :expected_discharge_date,
                           :admission_notes, :admitted_by)";
        
        $admission_stmt = $db->prepare($admission_query);
        $admission_stmt->bindParam(":patient_id", $_POST['patient_id']);
        $admission_stmt->bindParam(":room_id", $bed['room_id']);
        $admission_stmt->bindParam(":bed_id", $bed['bed_id']);
        $admission_stmt->bindParam(":admission_date", $_POST['admission_date']);
        $admission_stmt->bindParam(":expected_discharge_date", $_POST['expected_discharge_date']);
        $enc_notes = isset($_POST['admission_notes']) ? encrypt_strict((string)$_POST['admission_notes']) : encrypt_strict('');
        $admission_stmt->bindParam(":admission_notes", $enc_notes);
        $admission_stmt->bindParam(":admitted_by", $_SESSION['user_id']);
        $admission_stmt->execute();
        
        $admission_id = $db->lastInsertId();

        // Update bed status to occupied
        $update_bed_query = "UPDATE beds SET status = 'occupied' WHERE id = :bed_id";
        $update_bed_stmt = $db->prepare($update_bed_query);
        $update_bed_stmt->bindParam(":bed_id", $bed['bed_id']);
        $update_bed_stmt->execute();

        // Insert diagnoses
        if (!empty($_POST['diagnoses'])) {
            $diagnoses = array_filter(array_map('trim', explode("\n", $_POST['diagnoses'])));
            foreach ($diagnoses as $diagnosis) {
                $diagnosis_query = "INSERT INTO admission_diagnoses (admission_id, diagnosis) VALUES (:admission_id, :diagnosis)";
                $diagnosis_stmt = $db->prepare($diagnosis_query);
                $diagnosis_stmt->bindParam(":admission_id", $admission_id);
                $enc_diag = encrypt_strict($diagnosis);
                $diagnosis_stmt->bindParam(":diagnosis", $enc_diag);
                $diagnosis_stmt->execute();
            }
        }

        $db->commit();
        $success_message = "Patient successfully admitted to Room {$bed['room_number']}, Bed {$bed['bed_number']}";
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error admitting patient: " . $e->getMessage();
    }
}

// Get list of patients not currently admitted
$patients_query = "SELECT p.* FROM patients p 
                  LEFT JOIN patient_admissions pa ON p.id = pa.patient_id AND pa.admission_status = 'admitted'
                  WHERE pa.id IS NULL
                  ORDER BY p.name";
$patients_stmt = $db->prepare($patients_query);
$patients_stmt->execute();
$patients = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get room types and floor numbers for filtering
$room_types_query = "SELECT DISTINCT room_type FROM rooms WHERE status = 'active'";
$room_types_stmt = $db->prepare($room_types_query);
$room_types_stmt->execute();
$room_types = $room_types_stmt->fetchAll(PDO::FETCH_COLUMN);

$floors_query = "SELECT DISTINCT floor_number FROM rooms WHERE status = 'active' ORDER BY floor_number";
$floors_stmt = $db->prepare($floors_query);
$floors_stmt->execute();
$floors = $floors_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admit Patient - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
            padding: 20px;
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
        .form-control:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #ff4d4d 0%, #dc3545 100%);
        }
        .back-link {
            color: #6c757d;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-procedures me-2"></i>Admit Patient</h2>
            <a href="dashboard.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
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

        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0">Patient Admission Form</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Select Patient</label>
                        <select name="patient_id" class="form-select" required>
                            <option value="">Choose patient...</option>
                            <?php foreach($patients as $patient): ?>
                                <option value="<?php echo $patient['id']; ?>">
                                    <?php echo htmlspecialchars($patient['name']); ?> 
                                    (<?php echo $patient['gender']; ?>, <?php echo $patient['age']; ?> years)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Preferred Room Type</label>
                        <select name="preferred_room_type" class="form-select" required>
                            <?php foreach($room_types as $type): ?>
                                <option value="<?php echo $type; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Preferred Floor</label>
                        <select name="preferred_floor" class="form-select" required>
                            <?php foreach($floors as $floor): ?>
                                <option value="<?php echo $floor; ?>">
                                    Floor <?php echo $floor; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Admission Date & Time</label>
                        <input type="datetime-local" name="admission_date" class="form-control" 
                               value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Expected Discharge Date</label>
                        <input type="date" name="expected_discharge_date" class="form-control" 
                               min="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Diagnoses (one per line)</label>
                        <textarea name="diagnoses" class="form-control" rows="3" 
                                  placeholder="Enter diagnoses (one per line)" required></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Admission Notes</label>
                        <textarea name="admission_notes" class="form-control" rows="3" 
                                  placeholder="Enter any additional notes about the admission"></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-bed me-2"></i>Admit Patient
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
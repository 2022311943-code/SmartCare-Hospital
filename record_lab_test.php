<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['medical_technician','radiologist'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error_message = '';
$success_message = '';

// Load request
$request = null;
if (isset($_GET['request_id'])) {
    $rq = $db->prepare("SELECT lr.*, lt.test_name, lt.test_type,
                               COALESCE(v.patient_name, p.name) as patient_name,
                               COALESCE(v.age, p.age) as age,
                               COALESCE(v.gender, p.gender) as gender,
                               u.name as doctor_name
                        FROM lab_requests lr
                        JOIN lab_tests lt ON lr.test_id = lt.id
                        LEFT JOIN opd_visits v ON lr.visit_id = v.id
                        LEFT JOIN patient_admissions pa ON lr.admission_id = pa.id
                        LEFT JOIN patients p ON pa.patient_id = p.id
                        JOIN users u ON lr.doctor_id = u.id
                        WHERE lr.id = :id");
    $rq->execute([':id' => $_GET['request_id']]);
    $request = $rq->fetch(PDO::FETCH_ASSOC);
    if ($request) {
        $request['patient_name'] = decrypt_safe($request['patient_name'] ?? '');
        $request['notes'] = decrypt_safe($request['notes'] ?? '');
        $request['result'] = decrypt_safe($request['result'] ?? '');
        $request['result_file_path'] = decrypt_safe($request['result_file_path'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    try {
        $db->beginTransaction();
        $rid = (int)$_POST['request_id'];

        // Handle "start" (save draft) and "complete"
        $action = $_POST['action'] ?? 'save';

        // Optional file upload
        $result_file_path = null;
        if (isset($_FILES['result_file']) && $_FILES['result_file']['error'] === UPLOAD_ERR_OK) {
            $file_info = pathinfo($_FILES['result_file']['name']);
            $extension = strtolower($file_info['extension'] ?? '');
            $allowed_types = ['pdf','jpg','jpeg','png','doc','docx'];
            if (!in_array($extension, $allowed_types, true)) {
                throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowed_types));
            }
            $upload_dir = 'uploads/lab_results/';
            if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0777, true); }
            $filename = 'result_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            if (!move_uploaded_file($_FILES['result_file']['tmp_name'], $upload_dir . $filename)) {
                throw new Exception("Failed to upload file");
            }
            $result_file_path = $upload_dir . $filename;
        }

        // Start/save draft
        if ($action === 'start') {
            $upd = $db->prepare("UPDATE lab_requests
                                 SET status = 'in_progress',
                                     result = :result
                                 WHERE id = :id AND status IN ('pending','in_progress')");
            $upd->execute([
                ':result' => $_POST['result'] ?? '',
                ':id' => $rid
            ]);
        } else {
            // Fetch details for progress note (OPD only)
            $get = $db->prepare("SELECT lr.*, lt.test_name, lt.test_type, v.patient_record_id 
                                 FROM lab_requests lr
                                 JOIN lab_tests lt ON lr.test_id = lt.id
                                 LEFT JOIN opd_visits v ON lr.visit_id = v.id
                                 WHERE lr.id = :id");
            $get->execute([':id' => $rid]);
            $details = $get->fetch(PDO::FETCH_ASSOC);

            // Build progress note text
            $progress_note = null;
            if ($details) {
                $test_type = ucfirst($details['test_type']);
                $progress_note = "[" . $test_type . " Test Result] " . $details['test_name'] . "\n\n";
                $progress_note .= ($_POST['result'] ?? '') . "\n\n";
                if ($result_file_path) { $progress_note .= "Result File: " . $result_file_path; }
            }

            if ($details && !empty($details['visit_id']) && !empty($details['patient_record_id'])) {
                $pn = $db->prepare("INSERT INTO patient_progress_notes (patient_record_id, visit_id, note_text, doctor_id, created_at)
                                    VALUES (:pr, :vid, :txt, :doc, NOW())");
                $pn->execute([
                    ':pr' => $details['patient_record_id'],
                    ':vid' => $details['visit_id'],
                    ':txt' => encrypt_strict($progress_note),
                    ':doc' => $_SESSION['user_id']
                ]);
            }

            // Complete lab request
            if ($result_file_path !== null) {
                $upd = $db->prepare("UPDATE lab_requests
                                     SET status = 'completed',
                                         result = :result,
                                         result_file_path = :file,
                                         completed_at = NOW()
                                     WHERE id = :id");
                $upd->execute([
                    ':result' => $_POST['result'] ?? '',
                    ':file' => $result_file_path,
                    ':id' => $rid
                ]);
            } else {
                $upd = $db->prepare("UPDATE lab_requests
                                     SET status = 'completed',
                                         result = :result,
                                         completed_at = NOW()
                                     WHERE id = :id");
                $upd->execute([
                    ':result' => $_POST['result'] ?? '',
                    ':id' => $rid
                ]);
            }
        }

        $db->commit();
        header("Location: lab_dashboard.php");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        if (isset($result_file_path) && $result_file_path && file_exists($result_file_path)) {
            @unlink($result_file_path);
        }
        $error_message = "Failed to save: " . $e->getMessage();
    }
}

$page_title = 'Record Lab Test';
$additional_css = '<style>
.card { border: none; border-radius: 12px; box-shadow: 0 0 20px rgba(0,0,0,0.08); }
.card-header { background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%); color: #fff; border-radius: 12px 12px 0 0 !important; }
.meta { color: #6c757d; }
.info-label { font-size: .9rem; color: #6c757d; }
.info-value { font-weight: 600; color: #2c3e50; }
</style>';
include 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Lab Test</title>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="lab_dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
            <h5 class="mb-0">Record Lab Test</h5>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if (!$request): ?>
            <div class="alert alert-warning">Lab request not found.</div>
        <?php else: ?>
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-vial me-2"></i>Request Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="info-label">Patient</div>
                        <div class="info-value"><?php echo htmlspecialchars($request['patient_name']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-label">Age / Gender</div>
                        <div class="info-value"><?php echo (int)$request['age']; ?> / <?php echo ucfirst($request['gender']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-label">Test</div>
                        <div class="info-value"><?php echo htmlspecialchars($request['test_name']); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-label">Priority</div>
                        <div class="info-value"><?php echo ucfirst($request['priority']); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="info-label">Requesting Doctor</div>
                        <div class="info-value"><?php echo htmlspecialchars($request['doctor_name']); ?></div>
                    </div>
                    <?php if (!empty($request['notes'])): ?>
                    <div class="col-12">
                        <div class="info-label">Notes</div>
                        <div class="info-value" style="white-space: pre-wrap;"><?php echo htmlspecialchars($request['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="card">
            <div class="card-header"><i class="fas fa-file-medical me-2"></i>Record Result</div>
            <div class="card-body">
                <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                <div class="mb-3">
                    <label class="form-label">Result Findings</label>
                    <textarea name="result" class="form-control" rows="6" required><?php echo htmlspecialchars($request['result'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Attach Result File (optional)</label>
                    <input type="file" name="result_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" name="action" value="start" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>Save as Draft / In Progress
                    </button>
                    <button type="submit" name="action" value="complete" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Mark as Completed
                    </button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


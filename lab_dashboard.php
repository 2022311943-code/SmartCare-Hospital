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

// Handle request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'start':
                    // Start processing the request
                    $update_query = "UPDATE lab_requests 
                                   SET status = 'in_progress' 
                                   WHERE id = :request_id 
                                   AND status = 'pending'";
                    $stmt = $db->prepare($update_query);
                    $stmt->execute(['request_id' => $_POST['request_id']]);
                    break;

                case 'complete':
                    // Handle file upload
                    $result_file_path = null;
                    if (isset($_FILES['result_file']) && $_FILES['result_file']['error'] === UPLOAD_ERR_OK) {
                        $file_info = pathinfo($_FILES['result_file']['name']);
                        $extension = strtolower($file_info['extension']);
                        
                        // Check file type
                        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                        if (!in_array($extension, $allowed_types)) {
                            throw new Exception("Invalid file type. Allowed types: " . implode(', ', $allowed_types));
                        }
                        
                        // Generate unique filename
                        $filename = 'result_' . uniqid() . '.' . $extension;
                        $upload_dir = 'uploads/lab_results/';
                        
                        // Create directory if it doesn't exist
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        // Move uploaded file
                        if (move_uploaded_file($_FILES['result_file']['tmp_name'], $upload_dir . $filename)) {
                            $result_file_path = $upload_dir . $filename;
                        } else {
                            throw new Exception("Failed to upload file");
                        }
                    }

                    // Get lab request details for progress note (OPD only)
                    $request_query = "SELECT lr.*, lt.test_name, lt.test_type, v.patient_record_id 
                                    FROM lab_requests lr
                                    JOIN lab_tests lt ON lr.test_id = lt.id
                                    LEFT JOIN opd_visits v ON lr.visit_id = v.id
                                    WHERE lr.id = :request_id";
                    $request_stmt = $db->prepare($request_query);
                    $request_stmt->execute(['request_id' => $_POST['request_id']]);
                    $request_details = $request_stmt->fetch(PDO::FETCH_ASSOC);

                    // Create progress note content
                    $progress_note = null;
                    if ($request_details) {
                        $test_type = ucfirst($request_details['test_type']);
                        $progress_note = "[" . $test_type . " Test Result] " . $request_details['test_name'] . "\n\n";
                        $progress_note .= $_POST['result'] . "\n\n";
                        if ($result_file_path) {
                            $progress_note .= "Result File: " . $result_file_path;
                        }
                    }

                    // Add to patient progress notes ONLY for OPD requests with a valid visit_id
                    if ($request_details && !empty($request_details['visit_id']) && !empty($request_details['patient_record_id'])) {
                    $progress_query = "INSERT INTO patient_progress_notes (
                    patient_record_id, 
                    visit_id, 
                    note_text, 
                    doctor_id, 
                    created_at
                ) VALUES (
                    :patient_record_id,
                    :visit_id,
                    :note_text,
                    :doctor_id,
                    NOW()
                )";
                    $progress_stmt = $db->prepare($progress_query);
                    require_once 'includes/crypto.php';
                    $enc_note = encrypt_strict($progress_note);
                    $progress_stmt->execute([
                        'patient_record_id' => $request_details['patient_record_id'],
                        'visit_id' => $request_details['visit_id'],
                        'note_text' => $enc_note,
                        'doctor_id' => $_SESSION['user_id']
                    ]);
                    }

                    // Complete the request with results
                    if ($result_file_path !== null) {
                        $update_query = "UPDATE lab_requests 
                                       SET status = 'completed',
                                           result = :result,
                                           result_file_path = :result_file_path,
                                           completed_at = CURRENT_TIMESTAMP 
                                       WHERE id = :request_id 
                                       AND status = 'in_progress'";
                        $params = [
                            'result' => $_POST['result'],
                            'result_file_path' => $result_file_path,
                            'request_id' => $_POST['request_id']
                        ];
                    } else {
                        $update_query = "UPDATE lab_requests 
                                       SET status = 'completed',
                                           result = :result,
                                           completed_at = CURRENT_TIMESTAMP 
                                       WHERE id = :request_id 
                                       AND status = 'in_progress'";
                        $params = [
                            'result' => $_POST['result'],
                            'request_id' => $_POST['request_id']
                        ];
                    }
                    
                    $stmt = $db->prepare($update_query);
                    $stmt->execute($params);
                    break;
            }
        }

        $db->commit();
        $success_message = "Request updated successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        // Delete uploaded file if there was an error
        if (isset($result_file_path) && file_exists($result_file_path)) {
            unlink($result_file_path);
        }
        $error_message = "Error updating request: " . $e->getMessage();
    }
}

// Get test type based on employee role
$test_type = $_SESSION['employee_type'] === 'radiologist' ? 'radiology' : 'laboratory';

// Get pending requests
$pending_query = "SELECT lr.*, 
                        lt.test_name,
                        lt.test_type,
                        lt.cost,
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
                 WHERE lt.test_type = :test_type
                 AND lr.status IN ('pending', 'pending_payment')
                 ORDER BY 
                    CASE lr.priority
                        WHEN 'emergency' THEN 1
                        WHEN 'urgent' THEN 2
                        ELSE 3
                    END,
                    lr.requested_at ASC";

$pending_stmt = $db->prepare($pending_query);
$pending_stmt->execute(['test_type' => $test_type]);
$pending_requests = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($pending_requests as &$pr) {
    $pr['patient_name'] = decrypt_safe($pr['patient_name'] ?? '');
    // Notes may be encrypted elsewhere; safe to attempt
    $pr['notes'] = decrypt_safe($pr['notes'] ?? '');
}
unset($pr);

// Get in-progress requests
$progress_query = "SELECT lr.*, 
                         lt.test_name,
                         lt.test_type,
                         lt.cost,
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
                  WHERE lt.test_type = :test_type
                  AND lr.status = 'in_progress'
                 ORDER BY 
                    CASE lr.priority
                        WHEN 'emergency' THEN 1
                        WHEN 'urgent' THEN 2
                        ELSE 3
                    END,
                    lr.requested_at ASC";

$progress_stmt = $db->prepare($progress_query);
$progress_stmt->execute(['test_type' => $test_type]);
$progress_requests = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($progress_requests as &$pg) {
    $pg['patient_name'] = decrypt_safe($pg['patient_name'] ?? '');
    $pg['notes'] = decrypt_safe($pg['notes'] ?? '');
}
unset($pg);

// Get completed requests
$completed_query = "SELECT lr.*, 
                          lt.test_name,
                          lt.test_type,
                          lt.cost,
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
                   WHERE lt.test_type = :test_type
                   AND lr.status = 'completed'
                   ORDER BY lr.completed_at DESC
                   LIMIT 10";

$completed_stmt = $db->prepare($completed_query);
$completed_stmt->execute(['test_type' => $test_type]);
$completed_requests = $completed_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($completed_requests as &$cr) {
    $cr['patient_name'] = decrypt_safe($cr['patient_name'] ?? '');
    $cr['result'] = decrypt_safe($cr['result'] ?? '');
    $cr['result_file_path'] = decrypt_safe($cr['result_file_path'] ?? '');
}
unset($cr);

$page_title = ($_SESSION['employee_type'] === 'radiologist' ? "Radiology" : "Laboratory") . " Dashboard";
$additional_css = '
<style>
    .card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    .card-header {
        background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
        color: white;
        border-radius: 10px 10px 0 0 !important;
    }
    .request-card {
        transition: all 0.3s;
    }
    .request-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .priority-normal { background-color: #28a745; color: white; }
    .priority-urgent { background-color: #ffc107; color: black; }
    .priority-emergency { background-color: #dc3545; color: white; }
</style>';
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-end mb-3">
        <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $success_message; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="btn-group" id="priorityFilter" role="group" aria-label="Filter by priority">
            <button type="button" class="btn btn-outline-danger active" data-priority="all">
                <i class="fas fa-filter me-1"></i>All
            </button>
            <button type="button" class="btn btn-outline-danger" data-priority="emergency">
                <i class="fas fa-exclamation-triangle me-1"></i>Emergency
            </button>
            <button type="button" class="btn btn-outline-danger" data-priority="urgent">
                <i class="fas fa-bolt me-1"></i>Urgent
            </button>
            <button type="button" class="btn btn-outline-danger" data-priority="normal">
                <i class="fas fa-check me-1"></i>Normal
            </button>
        </div>
        <small class="text-muted">Filter affects Pending and In Progress lists</small>
    </div>

    <div class="row">
        <!-- Pending Requests -->
        <div class="col-md-4" id="pendingColumn">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-clock me-2"></i>
                        Pending Requests
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_requests)): ?>
                        <p class="text-muted text-center">No pending requests</p>
                    <?php else: ?>
                        <?php foreach ($pending_requests as $request): ?>
                            <div class="card request-card mb-3" data-priority="<?php echo htmlspecialchars($request['priority']); ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">
                                            <?php echo htmlspecialchars($request['patient_name']); ?>
                                            <small class="text-muted">
                                                (<?php echo $request['age']; ?> years, 
                                                <?php echo ucfirst($request['gender']); ?>)
                                            </small>
                                        </h6>
                                        <div>
                                            <span class="badge priority-<?php echo $request['priority']; ?>">
                                                <?php echo ucfirst($request['priority']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <p class="mb-1">
                                        <strong>Test:</strong> <?php echo htmlspecialchars($request['test_name']); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Doctor:</strong> <?php echo htmlspecialchars($request['doctor_name']); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Cost:</strong> ₱<?php echo number_format($request['cost'], 2); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Payment Status:</strong> 
                                        <span class="badge bg-<?php echo $request['payment_status'] === 'paid' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($request['payment_status']); ?>
                                        </span>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Requested:</strong> 
                                        <?php echo date('M d, Y g:i A', strtotime($request['requested_at'])); ?>
                                    </p>
                                    <?php if (!empty($request['notes'])): ?>
                                        <p class="mb-2">
                                            <strong>Notes:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($request['notes'])); ?>
                                        </p>
                                    <?php endif; ?>
                                    <form method="POST" class="mt-2">
                                        <input type="hidden" name="action" value="start">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <?php if ($request['status'] === 'pending'): ?>
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-play me-1"></i>
                                            Start Processing
                                        </button>
                                        <a href="record_lab_test.php?request_id=<?php echo $request['id']; ?>" class="btn btn-outline-secondary btn-sm ms-1">
                                            <i class="fas fa-file-medical me-1"></i>Record Result
                                        </a>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-secondary btn-sm" disabled>
                                            <i class="fas fa-lock me-1"></i>
                                            Awaiting Payment
                                        </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- In Progress Requests -->
        <div class="col-md-4" id="progressColumn">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-spinner me-2"></i>
                        In Progress
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($progress_requests)): ?>
                        <p class="text-muted text-center">No requests in progress</p>
                    <?php else: ?>
                        <?php foreach ($progress_requests as $request): ?>
                            <div class="card request-card mb-3" data-priority="<?php echo htmlspecialchars($request['priority']); ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">
                                            <?php echo htmlspecialchars($request['patient_name']); ?>
                                            <small class="text-muted">
                                                (<?php echo $request['age']; ?> years, 
                                                <?php echo ucfirst($request['gender']); ?>)
                                            </small>
                                        </h6>
                                        <span class="badge priority-<?php echo $request['priority']; ?>">
                                            <?php echo ucfirst($request['priority']); ?>
                                        </span>
                                    </div>
                                    <p class="mb-1">
                                        <strong>Test:</strong> <?php echo htmlspecialchars($request['test_name']); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Doctor:</strong> <?php echo htmlspecialchars($request['doctor_name']); ?>
                                    </p>
                                    <?php if (!empty($request['notes'])): ?>
                                        <p class="mb-2">
                                            <strong>Notes:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($request['notes'])); ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <a href="record_lab_test.php?request_id=<?php echo $request['id']; ?>" class="btn btn-success btn-sm">
                                            <i class="fas fa-file-medical me-1"></i>Record / Complete Result
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Completed Requests -->
        <div class="col-md-4" id="completedColumn">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-check-circle me-2"></i>
                        Recently Completed
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($completed_requests)): ?>
                        <p class="text-muted text-center">No completed requests</p>
                    <?php else: ?>
                        <?php foreach ($completed_requests as $request): ?>
                            <div class="card request-card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">
                                            <?php echo htmlspecialchars($request['patient_name']); ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y g:i A', strtotime($request['completed_at'])); ?>
                                        </small>
                                    </div>
                                    <p class="mb-1">
                                        <strong>Test:</strong> <?php echo htmlspecialchars($request['test_name']); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Doctor:</strong> <?php echo htmlspecialchars($request['doctor_name']); ?>
                                    </p>
                                    <div class="mt-2">
                                        <strong>Results:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($request['result'])); ?>
                                        <?php if (!empty($request['result_file_path'])): ?>
                                            <div class="mt-2">
                                                <a href="<?php echo htmlspecialchars($request['result_file_path']); ?>" 
                                                   class="btn btn-sm btn-primary" 
                                                   target="_blank">
                                                    <i class="fas fa-file-download me-1"></i>
                                                    View Result File
                                                </a>
                                            </div>
                                        <?php endif; ?>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var current = 'all';
    var buttons = document.querySelectorAll('#priorityFilter [data-priority]');
    function applyFilter() {
        var cards = document.querySelectorAll('#pendingColumn .request-card, #progressColumn .request-card');
        cards.forEach(function(card){
            var p = (card.getAttribute('data-priority') || '').toLowerCase();
            card.style.display = (current === 'all' || p === current) ? '' : 'none';
        });
    }
    buttons.forEach(function(btn){
        btn.addEventListener('click', function(){
            buttons.forEach(function(b){ b.classList.remove('active'); });
            this.classList.add('active');
            current = this.getAttribute('data-priority');
            applyFilter();
        });
    });
});
</script>
</body>
</html> 
<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['general_doctor', 'receptionist', 'medical_staff', 'doctor', 'nurse', 'admin_staff', 'medical_records'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Load any saved draft for the current user
$draft_data = null;
try {
    $d = $db->prepare("SELECT data_json, updated_at FROM opd_registration_drafts WHERE user_id = :uid LIMIT 1");
    $d->bindValue(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
    $d->execute();
    $draft_row = $d->fetch(PDO::FETCH_ASSOC);
    if ($draft_row) {
        $draft_data = json_decode($draft_row['data_json'], true);
        $draft_updated = $draft_row['updated_at'];
    }
} catch (Exception $e) { $draft_data = null; }

// Get today's queue
$queue_query = "SELECT 
    ov.*,
    TIMESTAMPDIFF(MINUTE, ov.arrival_time, COALESCE(ov.consultation_start, NOW())) as waiting_time,
    u.name as registered_by_name,
    d.name as doctor_name
FROM opd_visits ov
LEFT JOIN users u ON ov.registered_by = u.id
LEFT JOIN users d ON ov.doctor_id = d.id
WHERE DATE(ov.arrival_time) = CURDATE()
AND ov.visit_status <> 'cancelled'";

// Add doctor filter based on role
if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor') {
    $queue_query .= " AND (ov.doctor_id = :doctor_id OR (ov.visit_status = 'waiting' AND ov.doctor_id IS NULL))";
}

$queue_query .= " ORDER BY 
    CASE ov.visit_status
        WHEN 'waiting' THEN 1
        WHEN 'in_progress' THEN 2
        WHEN 'completed' THEN 3
        WHEN 'cancelled' THEN 4
    END,
    ov.arrival_time ASC";

$queue_stmt = $db->prepare($queue_query);

// Bind doctor_id if needed
if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor') {
    $queue_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
}

$queue_stmt->execute();
$queue = $queue_stmt->fetchAll(PDO::FETCH_ASSOC);
// Decrypt patient names for display
foreach ($queue as &$row) {
    if (isset($row['patient_name'])) {
        $row['patient_name'] = decrypt_safe($row['patient_name']);
    }
}
unset($row);

// Get previous days queue (waiting or in_progress before today)
$past_query = "SELECT 
    ov.*,
    TIMESTAMPDIFF(DAY, DATE(ov.arrival_time), CURDATE()) as days_ago,
    u.name as registered_by_name,
    d.name as doctor_name
FROM opd_visits ov
LEFT JOIN users u ON ov.registered_by = u.id
LEFT JOIN users d ON ov.doctor_id = d.id
WHERE DATE(ov.arrival_time) < CURDATE()
AND ov.visit_status IN ('waiting','in_progress')";

// Doctor filter for past queue
if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor') {
    $past_query .= " AND (ov.doctor_id = :doctor_id_past OR (ov.visit_status = 'waiting' AND ov.doctor_id IS NULL))";
}

$past_query .= " ORDER BY ov.arrival_time DESC";

$past_stmt = $db->prepare($past_query);
if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor') {
    $past_stmt->bindParam(':doctor_id_past', $_SESSION['user_id']);
}
$past_stmt->execute();
$past_queue = $past_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($past_queue as &$prow) {
    if (isset($prow['patient_name'])) {
        $prow['patient_name'] = decrypt_safe($prow['patient_name']);
    }
}
unset($prow);

// Get statistics for the filtered queue
$stats_query = "SELECT
    COUNT(*) as total_visits,
    SUM(CASE WHEN visit_status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
    SUM(CASE WHEN visit_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
    SUM(CASE WHEN visit_status = 'completed' THEN 1 ELSE 0 END) as completed_count,
    AVG(TIMESTAMPDIFF(MINUTE, arrival_time, COALESCE(consultation_start, NOW()))) as avg_waiting_time
FROM opd_visits
WHERE DATE(arrival_time) = CURDATE()
AND visit_status <> 'cancelled'";

// Add doctor filter to stats query
if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor') {
    $stats_query .= " AND (doctor_id = :doctor_id OR (visit_status = 'waiting' AND doctor_id IS NULL))";
}

$stats_stmt = $db->prepare($stats_query);

// Bind doctor_id for stats if needed
if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor') {
    $stats_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
}

$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Function to get patient details
function getPatientDetails($db, $visit_id) {
    // Get visit and patient record details
    $query = "SELECT v.*, pr.id as patient_record_id 
              FROM opd_visits v 
              LEFT JOIN patient_records pr ON v.patient_record_id = pr.id 
              WHERE v.id = :visit_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":visit_id", $visit_id);
    $stmt->execute();
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($patient) {
        // Decrypt core visit fields
        foreach (['patient_name','contact_number','address','symptoms','diagnosis','treatment_plan','prescription','notes','medical_history','medications'] as $f) {
            if (array_key_exists($f, $patient)) { $patient[$f] = decrypt_safe($patient[$f]); }
        }
    }
    
    if ($patient && $patient['patient_record_id']) {
        // Get all lab results for this patient across all visits
        $lab_query = "SELECT lr.*, lt.test_name, lt.test_type,
                             DATE_FORMAT(lr.completed_at, '%M %d, %Y %h:%i %p') as completed_date,
                             DATE_FORMAT(lr.requested_at, '%M %d, %Y %h:%i %p') as requested_date,
                             ov.visit_type
                      FROM lab_requests lr
                      JOIN lab_tests lt ON lr.test_id = lt.id
                      JOIN opd_visits ov ON lr.visit_id = ov.id
                      WHERE ov.patient_record_id = :patient_record_id
                      AND lr.status = 'completed'
                      ORDER BY lr.completed_at DESC";
        $lab_stmt = $db->prepare($lab_query);
        $lab_stmt->bindParam(":patient_record_id", $patient['patient_record_id']);
        $lab_stmt->execute();
        $patient['lab_results'] = $lab_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get progress notes history - excluding lab results
        $notes_query = "SELECT pn.*, u.name as doctor_name, u.employee_type,
                       v.visit_type, v.created_at as visit_date
                FROM patient_progress_notes pn
                JOIN users u ON pn.doctor_id = u.id
                JOIN opd_visits v ON pn.visit_id = v.id
                WHERE pn.patient_record_id = :patient_record_id
                AND pn.note_text NOT LIKE '[Laboratory Test Result]%'
                AND pn.note_text NOT LIKE '[Radiology Test Result]%'
                ORDER BY pn.created_at DESC";
        $notes_stmt = $db->prepare($notes_query);
        $notes_stmt->bindParam(":patient_record_id", $patient['patient_record_id']);
        $notes_stmt->execute();
        $patient['progress_notes'] = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
        // Decrypt progress note text
        foreach ($patient['progress_notes'] as &$n) {
            if (isset($n['note_text'])) { $n['note_text'] = decrypt_safe($n['note_text']); }
        }
        unset($n);
    }
    
    return $patient;
}

// Handle AJAX request for patient details
if (isset($_GET['action']) && $_GET['action'] === 'get_patient_details' && isset($_GET['visit_id'])) {
    $patient = getPatientDetails($db, $_GET['visit_id']);
    if ($patient) {
        echo json_encode([
            'success' => true,
            'data' => $patient
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Patient not found'
        ]);
    }
    exit();
}

// Process user actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && isset($_POST['visit_id'])) {
    try {
        $db->beginTransaction();
        
        $visit_id = $_POST['visit_id'];
        $action = $_POST['action'];
        
        switch ($action) {
            case 'start':
                $update_query = "UPDATE opd_visits SET 
                    visit_status = 'in_progress',
                    consultation_start = NOW(),
                    doctor_id = :doctor_id
                WHERE id = :visit_id AND visit_status = 'waiting'";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
                break;
                
            case 'complete':
                $update_query = "UPDATE opd_visits SET 
                    visit_status = 'completed',
                    consultation_end = NOW()
                WHERE id = :visit_id AND doctor_id = :doctor_id AND visit_status = 'in_progress'";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
                break;
                
            case 'cancel':
                if ($_SESSION['employee_type'] !== 'receptionist') {
                    throw new Exception("Unauthorized action");
                }
                $update_query = "UPDATE opd_visits SET 
                    visit_status = 'cancelled'
                WHERE id = :visit_id AND visit_status = 'waiting'";
                $update_stmt = $db->prepare($update_query);
                break;
                
            default:
                throw new Exception("Invalid action");
        }
        
        $update_stmt->bindParam(':visit_id', $visit_id);
        
        if ($update_stmt->execute()) {
            $db->commit();
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
            exit();
        } else {
            throw new Exception("Failed to update visit status");
        }
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: " . $_SERVER['PHP_SELF'] . "?error=" . urlencode($e->getMessage()));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-HT585MPVQ4"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);} 
      gtag('js', new Date());
      gtag('config', 'G-HT585MPVQ4');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPD Queue - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Global Theme CSS -->
    <link href="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>/assets/css/theme.css?v=20251005" rel="stylesheet">
    <!-- Update the styles -->
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
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            transition: none;
        }
        .stats-card:hover {
            transform: none;
        }
        .stats-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .stats-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        .queue-item {
            transition: none;
        }
        .queue-item:hover {
            background-color: inherit;
        }
        .queue-item.waiting {
            border-left: 4px solid #ffc107;
        }
        .queue-item.in-progress {
            border-left: 4px solid #0dcaf0;
        }
        .queue-item.completed {
            border-left: 4px solid #198754;
        }
        .queue-item.cancelled {
            border-left: 4px solid #dc3545;
        }
        .progress-notes {
            max-height: 300px;
            overflow-y: auto;
        }
        .note-entry {
            transition: none;
        }
        .note-entry:hover {
            background-color: inherit;
        }
        /* Special formatting only for consultation notes */
        .consultation-note {
            white-space: pre-wrap;
            font-family: monospace;
            line-height: 1.5;
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
        }
        /* Simple formatting for regular progress notes */
        .progress-note {
            white-space: pre-line;
            line-height: 1.5;
            margin-top: 10px;
        }
        .vital-sign-item {
            padding: 10px;
            border-radius: 8px;
            background: white;
            margin-bottom: 10px;
            border-left: 4px solid #dc3545;
        }
        .vital-sign-card {
            border-radius: 10px;
            border-left: 4px solid #dc3545;
            background: white;
            transition: none;
            padding: 1rem;
        }
        .vital-sign-card:hover {
            transform: none;
            box-shadow: none;
        }
        .vital-sign-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
        }
        .vital-sign-label {
            color: #6c757d;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }
        .vital-sign-label i {
            width: 20px;
            margin-right: 8px;
            text-align: center;
        }
        .info-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .info-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        .info-value {
            color: #2c3e50;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        .info-value i {
            width: 20px;
            margin-right: 8px;
            text-align: center;
        }
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 500;
        }
        .status-badge i {
            width: 20px;
            text-align: center;
        }
        .consultation-details {
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            padding: 1.5rem;
        }
        .progress-note-card {
            border-radius: 10px;
            border-left: 4px solid #0d6efd;
            background: white;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: none;
        }
        .progress-note-card:hover {
            transform: none;
            box-shadow: none;
        }
        .symptoms-section {
            background: white;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            padding: 1.5rem;
        }
        .symptoms-section .symptoms-content {
            white-space: pre-wrap;
            line-height: 1.5;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        .section-title i {
            width: 24px;
            margin-right: 8px;
            text-align: center;
            color: #dc3545;
        }
        .patient-info i {
            width: 20px;
            text-align: center;
            margin-right: 4px;
        }
        /* Add these styles for the patient header */
        .patient-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
        .patient-avatar {
            width: 60px;
            height: 60px;
            background: #dc3545;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            flex-shrink: 0;
        }
        .patient-info-container {
            flex-grow: 1;
        }
        .patient-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #2c3e50;
        }
        .patient-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #6c757d;
        }
        .patient-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .visit-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-container {
            text-align: right;
        }

        .consultation-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }

        .consultation-header {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .consultation-item {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            background: white;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .consultation-item i {
            color: #dc3545;
        }

        .consultation-footer {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #dee2e6;
            color: #6c757d;
            font-size: 0.85rem;
        }
        .consultation-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .consultation-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #495057;
            font-size: 0.9rem;
        }

        .consultation-item i {
            color: #dc3545;
            width: 16px;
            text-align: center;
        }
        .consultation-items {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 15px;
            padding: 0 10px;
        }

        .consultation-section {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .consultation-label {
            color: #dc3545;  /* Bootstrap's danger red */
            font-size: 0.9rem;
            font-weight: 500;
        }

        .consultation-content {
            color: #495057;
            font-size: 0.95rem;
            padding-left: 20px;
            white-space: pre-wrap;
        }

        .follow-up-date {
            color: #dc3545;  /* Changed to match other labels */
            font-size: 0.9rem;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-hospital me-2"></i>
                SmartCare
            </a>
            <button class="navbar-toggler" type="button" aria-controls="mainNav" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center ms-lg-auto gap-2 mt-3 mt-lg-0">
                    <a href="opd_registration.php" class="btn btn-outline-light btn-sm me-lg-3">
                    <i class="fas fa-plus me-2"></i>New Registration
                </a>
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm me-lg-3">
                    <i class="fas fa-tachometer-alt me-2"></i>Back to Dashboard
                </a>
                    <span class="text-white me-lg-3">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <?php if ($draft_data): ?>
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-file-alt me-2"></i>
                You have a saved OPD registration draft
                <?php
                    $nm = '';
                    if (!empty($draft_data['last_name']) || !empty($draft_data['first_name'])) {
                        $nm = trim(($draft_data['last_name'] ?? '') . ', ' . ($draft_data['first_name'] ?? ''));
                        if (!empty($draft_data['middle_name'])) { $nm .= ' ' . $draft_data['middle_name']; }
                        if (!empty($draft_data['suffix'])) { $nm .= ' ' . $draft_data['suffix']; }
                        echo '<strong> (' . htmlspecialchars($nm) . ')</strong>';
                    }
                ?>
                <span class="ms-2 small text-muted">Last saved: <?php echo htmlspecialchars($draft_updated ?? ''); ?></span>
            </div>
            <div class="d-flex gap-2">
                <a href="opd_registration.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-edit me-1"></i>Resume Draft
                </a>
                <a href="discard_opd_draft.php" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Discard this draft?');">
                    <i class="fas fa-trash me-1"></i>Discard
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-primary">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['total_visits']; ?></div>
                    <div class="text-muted">Total Visits Today</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['waiting_count']; ?></div>
                    <div class="text-muted">Waiting</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-info">
                        <i class="fas fa-stethoscope"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['in_progress_count']; ?></div>
                    <div class="text-muted">In Consultation</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stats-number"><?php echo $stats['completed_count']; ?></div>
                    <div class="text-muted">Completed</div>
                </div>
            </div>
        </div>

        <!-- Queue List -->
        <div class="card">
            <div class="card-header py-3">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Today's OPD Queue
                        </h5>
                    </div>
                    <div class="col">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search by name, token, age...">
                            <button class="btn btn-outline-secondary" type="button" id="clearSearch" style="display: none;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-danger active" data-filter="all">
                                All
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-filter="waiting">
                                Waiting
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-filter="in_progress">
                                In Progress
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-filter="completed">
                                Completed
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Patient Name</th>
                                <th>Age/Gender</th>
                                <th>Arrival Time</th>
                                <th>Waiting Time</th>
                                <th>Status</th>
                                <th>Doctor</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="queueTableBody">
                            <?php foreach($queue as $visit): ?>
                                <tr class="queue-item <?php echo $visit['visit_status']; ?>" data-status="<?php echo $visit['visit_status']; ?>">
                                    <td>
                                        <span class="badge bg-danger">
                                            <?php echo str_pad($visit['id'], 3, '0', STR_PAD_LEFT); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($visit['patient_name']); ?>
                                        <?php if ($visit['visit_type'] === 'follow_up'): ?>
                                            <span class="badge bg-info ms-1">Follow-up</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $visit['age']; ?>/<?php echo ucfirst($visit['gender']); ?>
                                    </td>
                                    <td>
                                        <?php echo date('h:i A', strtotime($visit['arrival_time'])); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($visit['visit_status'] === 'waiting' || $visit['visit_status'] === 'in_progress') {
                                            echo $visit['waiting_time'] . ' mins';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = [
                                            'waiting' => 'warning',
                                            'in_progress' => 'info',
                                            'completed' => 'success',
                                            'cancelled' => 'danger'
                                        ][$visit['visit_status']];
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $visit['visit_status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $visit['doctor_name'] ? htmlspecialchars($visit['doctor_name']) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor'): ?>
                                            <?php if ($visit['visit_status'] === 'waiting'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                                    <input type="hidden" name="action" value="start">
                                                    <button type="submit" class="btn btn-info btn-sm">
                                                        <i class="fas fa-play me-1"></i>Start
                                                    </button>
                                                </form>
                                            <?php elseif ($visit['visit_status'] === 'in_progress' && $visit['doctor_id'] == $_SESSION['user_id']): ?>
                                                <a href="patient_consultation.php?visit_id=<?php echo $visit['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-stethoscope me-1"></i>Continue Consultation
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array($_SESSION['employee_type'], ['receptionist','admin_staff']) && $visit['visit_status'] === 'waiting'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to cancel this visit?');">
                                                    <i class="fas fa-times me-1"></i>Cancel
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-secondary btn-sm view-details" data-id="<?php echo $visit['id']; ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Previous OPD Queue (Waiting / In Progress) -->
        <div class="card mt-4">
            <div class="card-header py-3">
                <div class="d-flex align-items-center justify-content-between">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>Previous OPD Queue
                        <span class="text-muted small ms-2">(Waiting/In Progress from previous days)</span>
                    </h5>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($past_queue)): ?>
                    <div class="text-muted text-center">No previous pending queue found.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Patient Name</th>
                                <th>Age/Gender</th>
                                <th>Arrival</th>
                                <th>Days Ago</th>
                                <th>Status</th>
                                <th>Doctor</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($past_queue as $visit): ?>
                            <tr class="queue-item <?php echo $visit['visit_status']; ?>">
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo str_pad($visit['id'], 3, '0', STR_PAD_LEFT); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($visit['patient_name']); ?>
                                    <?php if ($visit['visit_type'] === 'follow_up'): ?>
                                        <span class="badge bg-info ms-1">Follow-up</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $visit['age']; ?>/<?php echo ucfirst($visit['gender']); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($visit['arrival_time'])); ?></td>
                                <td><?php echo (int)$visit['days_ago']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $visit['visit_status']==='waiting' ? 'warning' : 'info'; ?>">
                                        <?php echo ucfirst(str_replace('_',' ', $visit['visit_status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo $visit['doctor_name'] ? htmlspecialchars($visit['doctor_name']) : '-'; ?></td>
                                <td>
                                    <?php if ($_SESSION['employee_type'] === 'general_doctor' || $_SESSION['employee_type'] === 'doctor'): ?>
                                        <?php if ($visit['visit_status'] === 'waiting'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="visit_id" value="<?php echo $visit['id']; ?>">
                                                <input type="hidden" name="action" value="start">
                                                <button type="submit" class="btn btn-info btn-sm">
                                                    <i class="fas fa-play me-1"></i>Start
                                                </button>
                                            </form>
                                        <?php elseif ($visit['visit_status'] === 'in_progress' && $visit['doctor_id'] == $_SESSION['user_id']): ?>
                                            <a href="patient_consultation.php?visit_id=<?php echo $visit['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-stethoscope me-1"></i>Continue
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button class="btn btn-secondary btn-sm view-details" data-id="<?php echo $visit['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Patient Details Modal -->
    <div class="modal fade" id="patientDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>
                        Patient Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" id="modalContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Navbar toggler manual control to improve reliability
        (function(){
            var nav = document.getElementById('mainNav');
            var toggler = document.querySelector('.navbar-toggler');
            if (nav && toggler && window.bootstrap && bootstrap.Collapse) {
                toggler.addEventListener('click', function(){
                    var instance = bootstrap.Collapse.getOrCreateInstance(nav);
                    instance.toggle();
                });
                nav.querySelectorAll('a').forEach(function(a){
                    a.addEventListener('click', function(){
                        if (window.innerWidth < 992) {
                            var inst = bootstrap.Collapse.getOrCreateInstance(nav);
                            inst.hide();
                        }
                    });
                });
            }
        })();
        // Initialize search functionality
        const searchInput = document.getElementById('searchInput');
        const clearSearch = document.getElementById('clearSearch');
        const queueTableBody = document.getElementById('queueTableBody');
        const rows = queueTableBody.getElementsByTagName('tr');
        let currentFilter = 'all';

        function filterTable() {
            const searchTerm = searchInput.value.toLowerCase();
            clearSearch.style.display = searchTerm ? 'block' : 'none';
            let visibleCount = 0;

            Array.from(rows).forEach(row => {
                const status = row.getAttribute('data-status');
                const text = row.textContent.toLowerCase();
                const matchesSearch = !searchTerm || text.includes(searchTerm);
                const matchesFilter = currentFilter === 'all' || status === currentFilter;

                if (matchesSearch && matchesFilter) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show or hide "no results" message
            let noResultsMsg = document.getElementById('noResultsMessage');
            if (visibleCount === 0) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('tr');
                    noResultsMsg.id = 'noResultsMessage';
                    noResultsMsg.innerHTML = `
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fas fa-search me-2"></i>No patients found matching your search
                        </td>
                    `;
                    queueTableBody.appendChild(noResultsMsg);
                }
            } else if (noResultsMsg) {
                noResultsMsg.remove();
            }

            // Update stats
            updateFilterStats(visibleCount);
        }

        function updateFilterStats(count) {
            const statsElement = document.querySelector('.card-header .text-muted');
            if (statsElement) {
                statsElement.textContent = `Showing ${count} patient${count !== 1 ? 's' : ''}`;
            }
        }

        // Search input event listener
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(filterTable, 300);
            });
        }

        // Clear search button
        if (clearSearch) {
            clearSearch.addEventListener('click', function() {
                searchInput.value = '';
                filterTable();
            });
        }

        // Filter buttons
        document.querySelectorAll('[data-filter]').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('[data-filter]').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                currentFilter = this.getAttribute('data-filter');
                filterTable();
            });
        });

        // Initialize Bootstrap components
        const patientDetailsModal = new bootstrap.Modal(document.getElementById('patientDetailsModal'));
        
        // Handle form submissions for both pending and status forms
        function handleFormSubmission(form) {
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            const originalButtonHtml = submitButton.innerHTML;
            
            // Remove any existing alerts
            document.querySelectorAll('.alert').forEach(alert => alert.remove());
            
            // Disable the submit button and show loading state
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    throw new Error(data.error || 'Failed to update status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Restore button state
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonHtml;
                
                // Show error message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-2';
                alertDiv.innerHTML = `
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${error.message || 'An error occurred. Please try again.'}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                form.insertAdjacentElement('beforebegin', alertDiv);
            });
        }

        // Handle all form submissions
        document.querySelectorAll('.pending-form, .status-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleFormSubmission(this);
            });
        });

        // View details functionality
        document.querySelectorAll('.view-details').forEach(button => {
            button.addEventListener('click', function() {
                const visitId = this.getAttribute('data-id');
                const modalContent = document.getElementById('modalContent');
                
                // Show loading state
                modalContent.innerHTML = `
                    <div class="text-center py-5">
                        <div class="spinner-border text-danger" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="mt-3">Loading patient details...</div>
                    </div>
                `;
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('patientDetailsModal'));
                modal.show();
                
                // Fetch patient details
                fetch(`${window.location.pathname}?action=get_patient_details&visit_id=${visitId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            const patient = data.data;
                            const symptomsHtml = (patient.symptoms || '').toString().trim()
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/\n/g, '<br>');
                            
                            // Create the content HTML
                            const content = `
                                <div class="p-4">
                                    <!-- Patient Header -->
                                    <div class="patient-header">
                                        <div class="patient-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="patient-info-container">
                                            <div class="patient-name">${patient.patient_name}</div>
                                            <div class="patient-meta">
                                                <div class="patient-meta-item">
                                                    <i class="fas fa-user-clock"></i>
                                                    <span>${patient.age} years</span>
                                                </div>
                                                <div class="patient-meta-item">
                                                    <i class="fas fa-venus-mars"></i>
                                                    <span>${patient.gender.charAt(0).toUpperCase() + patient.gender.slice(1)}</span>
                                                </div>
                                                <div class="visit-badge bg-${patient.visit_type === 'follow_up' ? 'info' : 'primary'}">
                                                    ${patient.visit_type === 'follow_up' ? 'Follow-up Visit' : 'New Visit'}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="status-container">
                                            <div class="badge bg-${
                                                patient.visit_status === 'waiting' ? 'warning' :
                                                patient.visit_status === 'in_progress' ? 'info' :
                                                patient.visit_status === 'completed' ? 'success' : 'danger'
                                            } status-badge">
                                                <i class="fas fa-${
                                                    patient.visit_status === 'waiting' ? 'clock' :
                                                    patient.visit_status === 'in_progress' ? 'stethoscope' :
                                                    patient.visit_status === 'completed' ? 'check-circle' : 'times-circle'
                                                }"></i>
                                                ${patient.visit_status.charAt(0).toUpperCase() + patient.visit_status.slice(1)}
                                            </div>
                                            <div class="text-muted small mt-2">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                ${new Date(patient.arrival_time).toLocaleString()}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Contact Information -->
                                    <div class="section-title">
                                        <i class="fas fa-address-card"></i>Contact Information
                                    </div>
                                    <div class="info-section mb-4">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="info-label">Phone Number</div>
                                                <div class="info-value">
                                                    <i class="fas fa-phone me-2"></i>${patient.contact_number}
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-label">Address</div>
                                                <div class="info-value">
                                                    <i class="fas fa-map-marker-alt me-2"></i>${patient.address}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Vital Signs -->
                                    <div class="section-title">
                                        <i class="fas fa-heartbeat"></i>Vital Signs
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label">
                                                    <i class="fas fa-thermometer-half"></i>Temperature
                                                </div>
                                                <div class="vital-sign-value">${patient.temperature}°C</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label">
                                                    <i class="fas fa-heart"></i>Blood Pressure
                                                </div>
                                                <div class="vital-sign-value">${patient.blood_pressure}</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label">
                                                    <i class="fas fa-wave-square"></i>Pulse Rate
                                                </div>
                                                <div class="vital-sign-value">${patient.pulse_rate} bpm</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label">
                                                    <i class="fas fa-lungs"></i>Respiratory Rate
                                                </div>
                                                <div class="vital-sign-value">${patient.respiratory_rate} /min</div>
                                            </div>
                                        </div>
                                        ${patient.oxygen_saturation != null ? `
                                        <div class="col-md-3 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label">
                                                    <i class="fas fa-percentage"></i>Oxygen Saturation
                                                </div>
                                                <div class="vital-sign-value">${patient.oxygen_saturation}%</div>
                                            </div>
                                        </div>` : ''}
                                        ${patient.height_cm != null ? `
                                        <div class="col-md-3 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label">
                                                    <i class="fas fa-arrows-alt-v"></i>Height
                                                </div>
                                                <div class="vital-sign-value">${patient.height_cm} cm</div>
                                            </div>
                                        </div>` : ''}
                                        ${patient.weight_kg != null ? `
                                        <div class="col-md-3 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label">
                                                    <i class="fas fa-weight"></i>Weight
                                                </div>
                                                <div class="vital-sign-value">${patient.weight_kg} kg</div>
                                            </div>
                                        </div>` : ''}
                                    </div>

                                    <!-- Incident/Onset Details -->
                                    ${(patient.noi || patient.poi || patient.doi || patient.toi) ? `
                                    <div class="section-title">
                                        <i class="fas fa-exclamation-circle"></i>Incident/Onset Details
                                    </div>
                                    <div class="row g-3 mb-4">
                                        ${patient.noi ? `
                                        <div class="col-md-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label"><i class="fas fa-info-circle"></i>NOI</div>
                                                <div class="vital-sign-value">${patient.noi}</div>
                                            </div>
                                        </div>` : ''}
                                        ${patient.poi ? `
                                        <div class="col-md-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label"><i class="fas fa-map-marker-alt"></i>POI</div>
                                                <div class="vital-sign-value">${patient.poi}</div>
                                            </div>
                                        </div>` : ''}
                                        ${patient.doi ? `
                                        <div class="col-md-6 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label"><i class="fas fa-calendar-day"></i>DOI</div>
                                                <div class="vital-sign-value">${new Date(patient.doi).toLocaleDateString()}</div>
                                            </div>
                                        </div>` : ''}
                                        ${patient.toi ? `
                                        <div class="col-md-6 col-6">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label"><i class="fas fa-clock"></i>TOI</div>
                                                <div class="vital-sign-value">${patient.toi}</div>
                                            </div>
                                        </div>` : ''}
                                    </div>` : ''}

                                    <!-- LMP / Medical History / Medications -->
                                    ${(patient.lmp || patient.medical_history || patient.medications) ? `
                                    <div class="row g-3 mb-4">
                                        ${patient.lmp ? `
                                        <div class="col-md-4">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label"><i class="fas fa-calendar-alt"></i>Last Menstrual Period</div>
                                                <div class="vital-sign-value">${new Date(patient.lmp).toLocaleDateString()}</div>
                                            </div>
                                        </div>` : ''}
                                        ${patient.medical_history ? `
                                        <div class="col-md-4">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label"><i class="fas fa-notes-medical"></i>Medical History</div>
                                                <div class="vital-sign-value" style="white-space: pre-wrap;">${patient.medical_history}</div>
                                            </div>
                                        </div>` : ''}
                                        ${patient.medications ? `
                                        <div class="col-md-4">
                                            <div class="vital-sign-card">
                                                <div class="vital-sign-label"><i class="fas fa-pills"></i>Medications Taken</div>
                                                <div class="vital-sign-value" style="white-space: pre-wrap;">${patient.medications}</div>
                                            </div>
                                        </div>` : ''}
                                    </div>` : ''}

                                    <!-- Symptoms -->
                                    <div class="section-title">
                                        <i class="fas fa-notes-medical"></i>Symptoms/Chief Complaint
                                    </div>
                                    <div class="symptoms-section mb-4">
                                        <div class="symptoms-content">${symptomsHtml || '<span class="text-muted">None recorded</span>'}</div>
                                    </div>

                                    ${patient.visit_status === 'completed' ? `
                                        <!-- Consultation Details -->
                                        <div class="section-title">
                                            <i class="fas fa-file-medical"></i>Consultation Details
                                        </div>
                                        <div class="consultation-details mb-4">
                                            <div class="row g-4">
                                                <div class="col-md-6">
                                                    <div class="info-label">Diagnosis</div>
                                                    <div class="info-value">${patient.diagnosis || '-'}</div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="info-label">Follow-up Date</div>
                                                    <div class="info-value">
                                                        ${patient.follow_up_date ? new Date(patient.follow_up_date).toLocaleDateString() : '-'}
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="info-label">Treatment Plan</div>
                                                    <div class="info-value">${patient.treatment_plan || '-'}</div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="info-label">Prescription</div>
                                                    <div class="info-value">${patient.prescription || '-'}</div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="info-label">Additional Notes</div>
                                                    <div class="info-value">${patient.notes || '-'}</div>
                                                </div>
                                            </div>
                                        </div>
                                    ` : ''}

                                    <!-- Progress Notes History -->
                                    <div class="section-title mb-3" style="color: #dc3545;">
                                        <i class="fas fa-history"></i>Progress Notes History
                                    </div>
                                    <div class="progress-notes">
                                        ${patient.progress_notes && patient.progress_notes.map(note => {
                                            // Check if this is a consultation summary
                                            const isConsultation = note.note_text.includes('DIAGNOSIS:') && 
                                                                 note.note_text.includes('TREATMENT PLAN:') && 
                                                                 note.note_text.includes('PRESCRIPTION:');
                                            
                                            // Parse consultation details
                                            let consultationDetails = null;
                                            if (isConsultation) {
                                                const text = note.note_text;
                                                consultationDetails = {
                                                    diagnosis: text.match(/DIAGNOSIS:\s*([\s\S]*?)(?=TREATMENT PLAN:|$)/i)?.[1]?.trim() || '',
                                                    treatment: text.match(/TREATMENT PLAN:\s*([\s\S]*?)(?=PRESCRIPTION:|$)/i)?.[1]?.trim() || '',
                                                    prescription: text.match(/PRESCRIPTION:\s*([\s\S]*?)(?=ADDITIONAL NOTES:|$)/i)?.[1]?.trim() || '',
                                                    notes: text.match(/ADDITIONAL NOTES:\s*([\s\S]*?)(?=FOLLOW-UP DATE:|$)/i)?.[1]?.trim() || '',
                                                    followUp: text.match(/FOLLOW-UP DATE:\s*([^-\n]+)/i)?.[1]?.trim() || ''
                                                };
                                            }

                                            return `
                                                <div class="vital-sign-item mb-3">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <span class="badge bg-${note.visit_type === 'follow_up' ? 'info' : 'primary'} me-2">
                                                                ${note.visit_type === 'follow_up' ? 'Follow-up Visit' : 'New Visit'}
                                                            </span>
                                                            <span class="text-muted">
                                                                ${note.doctor_name}
                                                            </span>
                                                        </div>
                                                        <span class="text-muted small">
                                                            ${new Date(note.created_at).toLocaleString()}
                                                        </span>
                                                    </div>
                                                    ${isConsultation ? `
                                                        <div class="consultation-items">
                                                            ${consultationDetails.diagnosis ? `
                                                                <div class="consultation-section">
                                                                    <div class="consultation-label">Diagnosis</div>
                                                                    <div class="consultation-content">${consultationDetails.diagnosis}</div>
                                                                </div>
                                                            ` : ''}
                                                            ${consultationDetails.treatment ? `
                                                                <div class="consultation-section">
                                                                    <div class="consultation-label">Treatment Plan</div>
                                                                    <div class="consultation-content">${consultationDetails.treatment}</div>
                                                                </div>
                                                            ` : ''}
                                                            ${consultationDetails.prescription ? `
                                                                <div class="consultation-section">
                                                                    <div class="consultation-label">Prescription</div>
                                                                    <div class="consultation-content">${consultationDetails.prescription}</div>
                                                                </div>
                                                            ` : ''}
                                                            ${consultationDetails.notes ? `
                                                                <div class="consultation-section">
                                                                    <div class="consultation-label">Additional Notes</div>
                                                                    <div class="consultation-content">${consultationDetails.notes}</div>
                                                                </div>
                                                            ` : ''}
                                                            ${consultationDetails.followUp ? `
                                                                <div class="follow-up-date">
                                                                    Follow-up Date: ${consultationDetails.followUp}
                                                                </div>
                                                            ` : ''}
                                                        </div>
                                                    ` : `
                                                        <div class="progress-note">
                                                            ${note.note_text}
                                                        </div>
                                                    `}
                                                </div>
                                            `;
                                        }).join('') || `
                                            <div class="text-muted text-center py-3">
                                                No progress notes available
                                            </div>
                                        `}
                                    </div>

                                    <!-- Laboratory Results -->
                                    <div class="section-title mb-3">
                                        <i class="fas fa-flask"></i>Laboratory Results
                                    </div>
                                    <div class="lab-results">
                                        ${patient.lab_results && patient.lab_results.map(result => `
                                            <div class="vital-sign-item mb-3">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <h6 class="mb-0">
                                                        ${result.test_type === 'laboratory' ? 
                                                            '<i class="fas fa-flask text-primary me-2"></i>' : 
                                                            '<i class="fas fa-x-ray text-info me-2"></i>'
                                                        }
                                                        ${result.test_name}
                                                        <small class="text-muted ms-2">
                                                            (${result.visit_type === 'follow_up' ? 'Follow-up Visit' : 'New Visit'})
                                                        </small>
                                                    </h6>
                                                    <span class="badge bg-success">Completed</span>
                                                </div>
                                                <div class="text-muted small mb-2">
                                                    <div><strong>Requested:</strong> ${result.requested_date}</div>
                                                    <div><strong>Completed:</strong> ${result.completed_date}</div>
                                                </div>
                                                <div class="mb-2">
                                                    ${result.result}
                                                </div>
                                                ${result.result_file_path ? `
                                                    <div>
                                                        <a href="${result.result_file_path}" 
                                                           class="btn btn-sm btn-primary" 
                                                           target="_blank">
                                                            <i class="fas fa-file-download me-1"></i>
                                                            View Result File
                                                        </a>
                                                    </div>
                                                ` : ''}
                                            </div>
                                        `).join('') || `
                                            <div class="text-muted text-center py-3">
                                                <i class="fas fa-info-circle me-2"></i>No laboratory results available
                                            </div>
                                        `}
                                    </div>
                                </div>
                            `;
                            
                            // Update modal content
                            modalContent.innerHTML = content;
                        } else {
                            throw new Error(data.error || 'Failed to load patient details');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        modalContent.innerHTML = `
                            <div class="alert alert-danger m-3">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                ${error.message || 'An error occurred while loading patient details. Please try again.'}
                            </div>
                        `;
                    });
            });
        });

        // Function to handle the refresh
        function handleRefresh() {
            // Check if any modal is open
            const openModal = document.querySelector('.modal.show');
            if (!openModal) {
                // Only reload if no modal is open
                window.location.reload();
            }
        }

        // Set refresh interval to 5 minutes (300000 milliseconds)
        setInterval(handleRefresh, 300000);
    });
    </script>
</body>
</html> 
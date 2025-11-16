<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['receptionist', 'medical_staff', 'medical_records', 'admin_staff', 'general_doctor', 'doctor', 'nurse']) && $_SESSION['role'] !== 'supra_admin') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$deathDeclarationsAvailable = false;
$deathTimeSubquery = "NULL AS death_time";
try {
    $chk = $db->query("SHOW TABLES LIKE 'death_declarations'");
    if ($chk && $chk->fetchColumn()) {
        $deathDeclarationsAvailable = true;
        $deathTimeSubquery = "(SELECT MAX(dd.time_of_death)
             FROM death_declarations dd
             JOIN patient_admissions pa3 ON dd.admission_id = pa3.id
             JOIN opd_visits ov3 ON ov3.id = pa3.source_visit_id
             WHERE ov3.patient_record_id = pr.id) AS death_time";
    }
} catch (Exception $e) {
    // leave as unavailable
}

$search_results = [];
$search_performed = false;
$error_message = '';

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_performed = true;
    $search = $_GET['search'];
    
    try {
        // Because name/contact/address are encrypted, fetch a recent window and filter after decryption
        $query = "SELECT 
            pr.*,
            COUNT(DISTINCT ov.id) as total_visits,
            MAX(ov.created_at) as last_visit,
            GROUP_CONCAT(DISTINCT u.name) as doctors,
            (SELECT visit_status 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             ORDER BY created_at DESC LIMIT 1) as current_status,
            (SELECT arrival_time 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             AND DATE(arrival_time) = CURDATE() 
             ORDER BY arrival_time DESC LIMIT 1) as today_visit_time,
            (SELECT visit_type 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             ORDER BY created_at DESC LIMIT 1) as last_visit_type,
            (SELECT COUNT(*) 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             AND DATE(arrival_time) = CURDATE()) as visits_today,
            (SELECT pa.admission_status
             FROM patient_admissions pa
             JOIN opd_visits ov2 ON ov2.id = pa.source_visit_id
             WHERE ov2.patient_record_id = pr.id
               AND pa.admission_status IN ('admitted','pending')
             ORDER BY FIELD(pa.admission_status,'admitted','pending'), pa.created_at DESC
             LIMIT 1) AS inpatient_status
            ,
            {$deathTimeSubquery}
        FROM patient_records pr
        LEFT JOIN opd_visits ov ON pr.id = ov.patient_record_id
        LEFT JOIN users u ON ov.doctor_id = u.id
        GROUP BY pr.id
        ORDER BY pr.created_at DESC
        LIMIT 1000"; // window for performance

        $stmt = $db->prepare($query);
        $stmt->execute();
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $needle = mb_strtolower(trim($search), 'UTF-8');
        foreach ($all as $r) {
            $name = decrypt_safe(decrypt_safe((string)$r['patient_name']));
            $contact = decrypt_safe(decrypt_safe((string)$r['contact_number']));
            $address = decrypt_safe(decrypt_safe((string)$r['address']));
            // Build haystack
            $hay = mb_strtolower($name.' '.$contact.' '.$address, 'UTF-8');
            $match = mb_strpos($hay, $needle, 0, 'UTF-8') !== false;
            // Also match by numeric id if numbers entered
            if (!$match && ctype_digit($needle)) {
                $match = ((string)$r['id'] === $needle);
            }
            if ($match) {
                $r['patient_name'] = $name;
                $r['contact_number'] = $contact;
                $r['address'] = $address;
                $search_results[] = $r;
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}
else {
    // No search provided: show all registered/existing patients
    $search_performed = true;
    try {
        $query = "SELECT 
            pr.*,
            COUNT(DISTINCT ov.id) as total_visits,
            MAX(ov.created_at) as last_visit,
            GROUP_CONCAT(DISTINCT u.name) as doctors,
            (SELECT visit_status 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             ORDER BY created_at DESC LIMIT 1) as current_status,
            (SELECT arrival_time 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             AND DATE(arrival_time) = CURDATE() 
             ORDER BY arrival_time DESC LIMIT 1) as today_visit_time,
            (SELECT visit_type 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             ORDER BY created_at DESC LIMIT 1) as last_visit_type,
            (SELECT COUNT(*) 
             FROM opd_visits 
             WHERE patient_record_id = pr.id 
             AND DATE(arrival_time) = CURDATE()) as visits_today,
            (SELECT pa.admission_status
             FROM patient_admissions pa
             JOIN opd_visits ov2 ON ov2.id = pa.source_visit_id
             WHERE ov2.patient_record_id = pr.id
               AND pa.admission_status IN ('admitted','pending')
             ORDER BY FIELD(pa.admission_status,'admitted','pending'), pa.created_at DESC
             LIMIT 1) AS inpatient_status
            ,
            {$deathTimeSubquery}
        FROM patient_records pr
        LEFT JOIN opd_visits ov ON pr.id = ov.patient_record_id
        LEFT JOIN users u ON ov.doctor_id = u.id
        GROUP BY pr.id
        ORDER BY pr.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($search_results as &$r) {
            foreach (['patient_name','contact_number','address'] as $f) {
                if (array_key_exists($f, $r)) {
                    $once = decrypt_safe((string)$r[$f]);
                    $r[$f] = decrypt_safe($once);
                }
            }
        }
        unset($r);
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Define page title and additional CSS
$page_title = 'Search Patient Records';
$additional_css = '<style>
    .patient-card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    .patient-card:hover {
        transform: translateY(-5px);
    }
    .status-badge {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
    }
    .quick-action-btn {
        padding: 5px 10px;
        border-radius: 5px;
        font-size: 0.9rem;
        margin-right: 5px;
    }
</style>';

// Phone formatter for display
function format_phone_display($value) {
    $s = (string)$value;
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') return $s;
    if (strpos($digits, '63') === 0 && strlen($digits) === 12) $digits = substr($digits, 2);
    if (strlen($digits) === 11 && $digits[0] === '0') $digits = substr($digits, 1);
    if (strlen($digits) === 10) return '+63' . $digits;
    return $s;
}

// Include header
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Patient Records - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php echo $additional_css; ?>
</head>
<body>
    <div class="container py-4">
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-search me-2"></i>Search Patient Records
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <a href="dashboard.php" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                    </div>
                </div>
                <form method="GET" action="" class="mb-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-search"></i>
                                </span>
                                <input type="text" name="search" class="form-control" placeholder="Search by name, contact number, or address..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-search me-2"></i>Search
                            </button>
                            <a href="opd_registration.php" class="btn btn-outline-danger">
                                <i class="fas fa-user-plus me-2"></i>New Registration
                            </a>
                        </div>
                    </div>
                </form>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($search_performed): ?>
                    <?php if (empty($search_results)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No patients found matching your search criteria.
                        </div>
                    <?php else: ?>
                        <div class="search-results">
                            <?php foreach($search_results as $patient): ?>
                                <div class="card patient-card mb-3">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-4">
                                                <h5 class="mb-1"><?php echo htmlspecialchars(decrypt_safe(decrypt_safe($patient['patient_name']))); ?></h5>
                                                <div class="text-muted small">
                                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars(format_phone_display(decrypt_safe(decrypt_safe($patient['contact_number'])))); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-1">
                                                    <span class="badge bg-info">
                                                        <i class="fas fa-user me-1"></i>Age: <?php echo $patient['age']; ?>
                                                    </span>
                                                    <span class="badge bg-secondary">
                                                        <i class="fas fa-venus-mars me-1"></i><?php echo ucfirst($patient['gender']); ?>
                                                    </span>
                                                </div>
                                                <div class="text-muted small">
                                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars(decrypt_safe(decrypt_safe($patient['address']))); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <?php if ($patient['current_status']): ?>
                                                    <span class="status-badge bg-<?php 
                                                        echo $patient['current_status'] === 'waiting' ? 'warning' : 
                                                            ($patient['current_status'] === 'in_progress' ? 'info' : 
                                                            ($patient['current_status'] === 'completed' ? 'success' : 'secondary')); 
                                                    ?>">
                                                        <i class="fas fa-circle me-1"></i>
                                                        <?php echo ucfirst($patient['current_status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($patient['inpatient_status'])): ?>
                                                    <span class="status-badge bg-<?php echo $patient['inpatient_status'] === 'admitted' ? 'success' : 'warning'; ?>">
                                                        <i class="fas fa-bed me-1"></i>
                                                        <?php echo $patient['inpatient_status'] === 'admitted' ? 'Admitted' : 'Admission Pending'; ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($patient['death_time'])): ?>
                                                    <span class="status-badge bg-dark text-white mt-1">
                                                        <i class="fas fa-skull-crossbones me-1"></i>
                                                        Deceased <?php echo date('M d, Y', strtotime($patient['death_time'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <div class="mt-2">
                                                    <?php if (!$patient['visits_today']): ?>
                                                    <a href="opd_registration.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-primary quick-action-btn">
                                                        <i class="fas fa-plus-circle me-1"></i>New Visit
                                                    </a>
                                                    <?php else: ?>
                                                    <span class="badge bg-info">
                                                        <i class="fas fa-info-circle me-1"></i>Already visited today
                                                    </span>
                                                    <?php endif; ?>
                                                    <a href="patient_history.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-outline-info quick-action-btn">
                                                        <i class="fas fa-history me-1"></i>History
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3 pt-3 border-top">
                                            <div class="row text-muted small">
                                                <div class="col-md-3">
                                                    <i class="fas fa-calendar-check me-1"></i>
                                                    Total Visits: <?php echo $patient['total_visits']; ?>
                                                </div>
                                                <?php if ($patient['last_visit']): ?>
                                                    <div class="col-md-4">
                                                        <i class="fas fa-clock me-1"></i>
                                                        Last Visit: <?php echo date('M d, Y', strtotime($patient['last_visit'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($patient['today_visit_time']): ?>
                                                    <div class="col-md-5">
                                                        <i class="fas fa-hospital me-1"></i>
                                                        Today's Visit: <?php echo date('h:i A', strtotime($patient['today_visit_time'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
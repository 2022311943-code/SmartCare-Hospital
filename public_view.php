<?php
session_start();
require_once "config/database.php";

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log access for debugging
error_log("Public view accessed with token: " . (isset($_GET['token']) ? $_GET['token'] : 'no token'));

// Check if token is provided
if (!isset($_GET['token'])) {
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <title>Invalid Link - SmartCare</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { 
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f5f7fa;
            }
            .error-card {
                max-width: 500px;
                text-align: center;
                padding: 2rem;
            }
        </style>
    </head>
    <body>
        <div class="card error-card">
            <div class="card-body">
                <h3 class="text-danger mb-4">Invalid Link</h3>
                <p class="text-muted">No token provided. Please make sure you have the correct link.</p>
                <a href="index.php" class="btn btn-danger mt-3">Go to Homepage</a>
            </div>
        </div>
    </body>
    </html>
    ');
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Get patient information and share token details
    $query = "SELECT p.*, 
              CONCAT(u.name, ' (', u.employee_type, ')') as added_by_name,
              d.name as doctor_name,
              d.id as doctor_id,
              st.access_level
              FROM patient_share_tokens st
              JOIN patients p ON st.patient_id = p.id 
              LEFT JOIN users u ON p.added_by = u.id
              LEFT JOIN users d ON p.doctor_id = d.id
              WHERE st.token = :token";

    $stmt = $db->prepare($query);
    $stmt->bindParam(":token", $_GET['token']);
    $stmt->execute();
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    // If token is invalid
    if (!$patient) {
        die('
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invalid Link - SmartCare</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { 
                    height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #f5f7fa;
                }
                .error-card {
                    max-width: 500px;
                    text-align: center;
                    padding: 2rem;
                }
            </style>
        </head>
        <body>
            <div class="card error-card">
                <div class="card-body">
                    <h3 class="text-danger mb-4">Invalid Link</h3>
                    <p class="text-muted">This link is invalid. Please request a new link from your healthcare provider.</p>
                    <a href="index.php" class="btn btn-danger mt-3">Go to Homepage</a>
                </div>
            </div>
        </body>
        </html>
        ');
        exit();
    }

    // Check access level
    $has_full_access = false;
    $is_staff = false;

    if (isset($_SESSION['user_id'])) {
        // Check if the user is a staff member
        if (isset($_SESSION['employee_type']) && in_array($_SESSION['employee_type'], ['doctor', 'nurse', 'staff'])) {
            $is_staff = true;
            // Only the patient's assigned doctor gets full access
            if ($_SESSION['employee_type'] === 'doctor' && $_SESSION['user_id'] == $patient['doctor_id']) {
                $has_full_access = true;
            }
        }
    }

    // If not logged in or not a staff member, show unauthorized message
    if (!$is_staff) {
        die('
        <!DOCTYPE html>
        <html>
        <head>
            <title>Unauthorized - SmartCare</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { 
                    height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #f5f7fa;
                }
                .error-card {
                    max-width: 500px;
                    text-align: center;
                    padding: 2rem;
                }
            </style>
        </head>
        <body>
            <div class="card error-card">
                <div class="card-body">
                    <h3 class="text-warning mb-4">Unauthorized Access</h3>
                    <p class="text-muted">Only authorized hospital staff can view patient information. Please log in with your staff account.</p>
                    <a href="index.php" class="btn btn-danger mt-3">Login</a>
                </div>
            </div>
        </body>
        </html>
        ');
        exit();
    }

    // Get patient's progress notes if full access is granted
    $progress_notes = [];
    if ($has_full_access) {
        $progress_query = "SELECT * FROM patient_progress 
                          WHERE patient_id = :patient_id 
                          ORDER BY progress_date DESC";
        $progress_stmt = $db->prepare($progress_query);
        $progress_stmt->bindParam(":patient_id", $patient['id']);
        $progress_stmt->execute();
        $progress_notes = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    // Log the error
    error_log("Error in public_view.php: " . $e->getMessage());
    
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error - SmartCare</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { 
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f5f7fa;
            }
            .error-card {
                max-width: 500px;
                text-align: center;
                padding: 2rem;
            }
        </style>
    </head>
    <body>
        <div class="card error-card">
            <div class="card-body">
                <h3 class="text-danger mb-4">System Error</h3>
                <p class="text-muted">An error occurred while processing your request. Please try again later.</p>
                <a href="index.php" class="btn btn-danger mt-3">Go to Homepage</a>
            </div>
        </div>
    </body>
    </html>
    ');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Information - SmartCare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
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
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        .patient-info-label {
            font-weight: 600;
            color: #6c757d;
        }
        .patient-info-value {
            font-size: 1.1rem;
        }
        .access-level-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
        }
        .access-level-full {
            background-color: #198754;
            color: white;
        }
        .access-level-basic {
            background-color: #ffc107;
            color: #000;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <span class="navbar-brand">
                <i class="fas fa-hospital me-2"></i>
                SmartCare
            </span>
            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="d-flex align-items-center">
                    <span class="text-white me-3">
                        <i class="fas fa-user me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                    </span>
                    <a href="dashboard.php" class="btn btn-outline-light me-2">Dashboard</a>
                    <a href="logout.php" class="btn btn-outline-light">Logout</a>
                </div>
            <?php else: ?>
                <a href="index.php" class="btn btn-outline-light">Login</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Patient Information -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0">
                    <i class="fas fa-user-injured me-2"></i>Patient Information
                </h5>
                <span class="access-level-badge <?php echo $has_full_access ? 'access-level-full' : 'access-level-basic'; ?>">
                    <i class="fas <?php echo $has_full_access ? 'fa-lock-open' : 'fa-lock'; ?> me-2"></i>
                    <?php echo $has_full_access ? 'Full Access' : 'Basic Access'; ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <p class="patient-info-label mb-1">Name</p>
                        <p class="patient-info-value"><?php echo htmlspecialchars($patient['name']); ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="patient-info-label mb-1">Age</p>
                        <p class="patient-info-value"><?php echo htmlspecialchars($patient['age']); ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="patient-info-label mb-1">Gender</p>
                        <p class="patient-info-value"><?php echo ucfirst(htmlspecialchars($patient['gender'])); ?></p>
                    </div>
                    <?php if ($has_full_access): ?>
                        <div class="col-md-6">
                            <p class="patient-info-label mb-1">Contact Number</p>
                            <p class="patient-info-value"><?php echo htmlspecialchars($patient['phone']); ?></p>
                        </div>
                        <div class="col-12">
                            <p class="patient-info-label mb-1">Address</p>
                            <p class="patient-info-value"><?php echo htmlspecialchars($patient['address']); ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-6">
                        <p class="patient-info-label mb-1">Doctor</p>
                        <p class="patient-info-value"><?php echo htmlspecialchars($patient['doctor_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="patient-info-label mb-1">Registration Date</p>
                        <p class="patient-info-value"><?php echo date('F d, Y', strtotime($patient['created_at'])); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($has_full_access): ?>
        <!-- Progress Notes -->
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0">
                    <i class="fas fa-notes-medical me-2"></i>Progress Notes
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($progress_notes)): ?>
                    <p class="text-muted text-center mb-0">No progress notes recorded yet.</p>
                <?php else: ?>
                    <?php foreach($progress_notes as $note): ?>
                        <div class="progress-note">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Progress Note</h6>
                                <small class="text-muted">
                                    <?php echo date('M d, Y h:i A', strtotime($note['progress_date'])); ?>
                                </small>
                            </div>
                            <p class="mb-2"><?php echo nl2br(htmlspecialchars($note['progress_note'])); ?></p>
                            <?php if (!empty($note['vital_signs'])): ?>
                                <p class="mb-1">
                                    <strong><i class="fas fa-heartbeat me-1"></i>Vital Signs:</strong>
                                    <?php echo htmlspecialchars($note['vital_signs']); ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($note['treatment_response'])): ?>
                                <p class="mb-1">
                                    <strong><i class="fas fa-comment-medical me-1"></i>Treatment Response:</strong>
                                    <?php echo htmlspecialchars($note['treatment_response']); ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($note['next_appointment'])): ?>
                                <p class="mb-0">
                                    <strong><i class="fas fa-calendar-check me-1"></i>Next Appointment:</strong>
                                    <?php echo date('M d, Y', strtotime($note['next_appointment'])); ?>
                                </p>
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
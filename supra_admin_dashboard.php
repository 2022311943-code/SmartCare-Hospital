<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is supra admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supra_admin') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Ensure audit_logs table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        target_type VARCHAR(50) DEFAULT NULL,
        target_id INT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_action (action),
        KEY idx_target (target_type, target_id),
        KEY idx_actor (actor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ignore */ }

// Helper to present username (stored in users.email for compatibility) with masking
function mask_username_for_display($raw) {
    $val = (string)($raw ?? '');
    // If an email-like string was stored previously, use the local part as username
    $atPos = strpos($val, '@');
    if ($atPos !== false) {
        $val = substr($val, 0, $atPos);
    }
    $len = strlen($val);
    if ($len <= 2) {
        return str_repeat('*', $len);
    }
    $prefix = substr($val, 0, 2);
    $suffix = substr($val, -2);
    $maskedLen = max(0, $len - 4);
    return $prefix . str_repeat('*', $maskedLen) . $suffix;
}

// Get user's full information
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(":user_id", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get total users count
$users_query = "SELECT COUNT(*) as count FROM users WHERE role != 'supra_admin'";
$users_stmt = $db->prepare($users_query);
$users_stmt->execute();
$total_users = $users_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get pending users count
$pending_query = "SELECT COUNT(*) as count FROM users WHERE status = 'pending'";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->execute();
$pending_users = $pending_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get active users count
$active_count_query = "SELECT COUNT(*) as count FROM users WHERE status = 'active' AND role != 'supra_admin'";
$active_count_stmt = $db->prepare($active_count_query);
$active_count_stmt->execute();
$active_users_count = $active_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get list of all users (except supra admin), including both active and inactive
$active_users_query = "SELECT * FROM users WHERE role != 'supra_admin' ORDER BY name ASC";
$active_users_stmt = $db->prepare($active_users_query);
$active_users_stmt->execute();
$active_users = $active_users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get inactive users count
$inactive_query = "SELECT COUNT(*) as count FROM users WHERE status = 'inactive'";
$inactive_stmt = $db->prepare($inactive_query);
$inactive_stmt->execute();
$inactive_users = $inactive_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get count of active nurses
$nurse_count_query = "SELECT COUNT(*) as count FROM users WHERE employee_type = 'nurse' AND status = 'active'";
$nurse_count_stmt = $db->prepare($nurse_count_query);
$nurse_count_stmt->execute();
$nurse_count = $nurse_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get count of active doctors
$doctor_count_query = "SELECT COUNT(*) as count FROM users WHERE employee_type = 'doctor' AND status = 'active'";
$doctor_count_stmt = $db->prepare($doctor_count_query);
$doctor_count_stmt->execute();
$doctor_count = $doctor_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get total patients count
$patients_query = "SELECT COUNT(*) as count FROM patients";
$patients_stmt = $db->prepare($patients_query);
$patients_stmt->execute();
$total_patients = $patients_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get total medicines count
$medicines_query = "SELECT COUNT(*) as count FROM medicines";
$medicines_stmt = $db->prepare($medicines_query);
$medicines_stmt->execute();
$total_medicines = $medicines_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get pending users list
$pending_users_query = "SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5";
$pending_users_stmt = $db->prepare($pending_users_query);
$pending_users_stmt->execute();
$pending_users_list = $pending_users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent patients
$recent_patients_query = "SELECT p.*, u.name as doctor_name 
                         FROM patients p 
                         JOIN users u ON p.doctor_id = u.id 
                         ORDER BY p.created_at DESC LIMIT 5";
$recent_patients_stmt = $db->prepare($recent_patients_query);
$recent_patients_stmt->execute();
$recent_patients = $recent_patients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get low stock medicines
$low_stock_query = "SELECT * FROM medicines 
                    WHERE quantity <= low_stock_threshold 
                    ORDER BY quantity ASC LIMIT 5";
$low_stock_stmt = $db->prepare($low_stock_query);
$low_stock_stmt->execute();
$low_stock_medicines = $low_stock_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get near expiry medicines
$near_expiry_query = "SELECT *, 
                      DATEDIFF(expiration_date, CURDATE()) as days_until_expiry 
                      FROM medicines 
                      WHERE expiration_date IS NOT NULL 
                      AND expiration_date > CURDATE() 
                      AND DATEDIFF(expiration_date, CURDATE()) <= near_expiry_days 
                      ORDER BY expiration_date ASC LIMIT 5";
$near_expiry_stmt = $db->prepare($near_expiry_query);
$near_expiry_stmt->execute();
$near_expiry_medicines = $near_expiry_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get room statistics
$room_stats_query = "SELECT 
                     COUNT(*) as total_rooms,
                     SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_rooms,
                     SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_rooms,
                     SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_rooms
                     FROM rooms";
$room_stats_stmt = $db->prepare($room_stats_query);
$room_stats_stmt->execute();
$room_stats = $room_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get bed statistics
$bed_stats_query = "SELECT 
                    COUNT(*) as total_beds,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_beds,
                    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_beds,
                    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_beds
                    FROM beds";
$bed_stats_stmt = $db->prepare($bed_stats_query);
$bed_stats_stmt->execute();
$bed_stats = $bed_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Process user actions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && isset($_POST['user_id'])) {
        $user_id = intval($_POST['user_id']);
        $action = $_POST['action'];
        
        try {
            // First verify if user exists
            $check_user_query = "SELECT id, status FROM users WHERE id = :user_id";
            $check_user_stmt = $db->prepare($check_user_query);
            $check_user_stmt->bindParam(":user_id", $user_id);
            $check_user_stmt->execute();
            $user_exists = $check_user_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user_exists) {
                throw new Exception("User ID not found in the database.");
            }

            if ($action === 'approve' || $action === 'deactivate' || $action === 'activate') {
                // For approval, always set status to active
                $new_status = ($action === 'approve') ? 'active' : (($action === 'activate') ? 'active' : 'inactive');
                $old_status = (string)($user_exists['status'] ?? '');
                
                // Start transaction
                $db->beginTransaction();
                
                // Update user status
                $update_query = "UPDATE users SET status = :status WHERE id = :user_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(":status", $new_status);
                $update_stmt->bindParam(":user_id", $user_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update user status. Database error.");
                }
                
                // Get updated user data
                $user_query = "SELECT id, name, email, employee_type, department, status FROM users WHERE id = :user_id";
                $user_stmt = $db->prepare($user_query);
                $user_stmt->bindParam(":user_id", $user_id);
                
                if (!$user_stmt->execute()) {
                    throw new Exception("Failed to retrieve updated user data.");
                }
                
                $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user_data) {
                    throw new Exception("Failed to retrieve user data after update.");
                }

                // Commit the transaction
                $db->commit();

                // Write audit trail
                try {
                    $details = "status_change: " . $old_status . " -> " . $new_status;
                    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
                    $log = $db->prepare("INSERT INTO audit_logs (actor_id, action, target_type, target_id, details, ip_address) VALUES (:actor_id, :action, 'user', :target_id, :details, :ip)");
                    $log->bindValue(':actor_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
                    $log->bindValue(':action', $action === 'approve' ? 'user_approve' : ($action === 'activate' ? 'user_activate' : 'user_deactivate'));
                    $log->bindValue(':target_id', (int)$user_id, PDO::PARAM_INT);
                    $log->bindValue(':details', $details);
                    $log->bindValue(':ip', $ip);
                    $log->execute();
                } catch (Exception $ignore) { /* non-fatal */ }
                
                // If it's an AJAX request, return JSON response
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'User status updated successfully',
                        'user' => $user_data,
                        'action' => $action
                    ]);
                    exit();
                }
                
                // For non-AJAX requests, redirect without success parameter
                header("Location: ".$_SERVER['PHP_SELF']);
                exit();
            } else {
                throw new Exception("Invalid action specified.");
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'error' => $e->getMessage()
                ]);
                exit();
            }
            
            // For non-AJAX requests, redirect with error
            header("Location: ".$_SERVER['PHP_SELF']."?error=".urlencode($e->getMessage()));
            exit();
        }
    } else {
        // Handle missing parameters
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Missing required parameters (user_id or action)'
            ]);
            exit();
        }
        
        header("Location: ".$_SERVER['PHP_SELF']."?error=missing_parameters");
        exit();
    }
}

// Fetch recent audit trails
try {
    $audit_stmt = $db->prepare("
        SELECT al.*, 
               ua.name AS actor_name,
               tu.name AS target_name
        FROM audit_logs al
        LEFT JOIN users ua ON ua.id = al.actor_id
        LEFT JOIN users tu ON tu.id = al.target_id AND al.target_type = 'user'
        ORDER BY al.created_at DESC
        LIMIT 50
    ");
    $audit_stmt->execute();
    $audit_logs = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $audit_logs = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supra Admin Dashboard - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Global Theme CSS -->
    <link href="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>/assets/css/theme.css?v=20251005" rel="stylesheet">
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
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-radius: 15px 15px 0 0 !important;
            border-bottom: 1px solid #eee;
        }
        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            letter-spacing: 0.3px;
        }
        .table {
            margin-bottom: 0;
        }
        .table th {
            border-top: none;
            font-weight: 600;
            color: #2c3e50;
        }
        .badge {
            padding: 8px 12px;
            border-radius: 6px;
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
        .logout-btn {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white !important;
            padding: 8px 20px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .logout-btn:hover {
            background: rgba(255,255,255,0.2);
        }
        /* Quick Action Cards Styling */
        .quick-action-card {
            transition: none;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            overflow: hidden;
        }
        .quick-action-card:hover {
            transform: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }
        .quick-action-card .display-4 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        .quick-action-card .card-body {
            padding: 1.5rem;
        }
        .quick-action-card .card-title {
            color: #2c3e50;
            font-weight: 600;
        }
        .quick-action-card .card-text {
            color: #6c757d;
        }
        /* Quick Action Card Hover Effects */
        .quick-action-link {
            text-decoration: none;
            display: block;
        }
        .quick-action-link .card {
            transition: none;
            border: 2px solid transparent;
            background: white;
        }
        .quick-action-link:hover .card {
            transform: none;
            border-color: transparent;
            box-shadow: none;
        }
        .quick-action-link:hover .display-4 {
            transform: none;
        }
        .quick-action-link .display-4 {
            transition: none;
        }
        .quick-action-link:hover .card-title {
            color: inherit;
        }
        .quick-action-btn {
            border: none;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: none;
            background: white;
            color: #2c3e50;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .quick-action-btn:hover {
            transform: none;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            color: inherit;
        }
        .quick-action-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: block;
        }
        .quick-action-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .quick-action-desc {
            font-size: 0.875rem;
            color: #6c757d;
            margin: 0;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-hospital me-2"></i>
                Hospital Management System
            </a>
            <button class="navbar-toggler" type="button" aria-controls="mainNav" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center ms-lg-auto gap-2 mt-3 mt-lg-0">
                    <span class="text-white me-lg-3">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="logout.php" class="btn btn-outline-light btn-sm text-decoration-none">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- User Info Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="avatar-circle">
                            <?php if (isset($user['profile_picture']) && !empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fas fa-user-shield"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col">
                        <h5 class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name']); ?></h5>
                        <div class="text-muted">
                            <span class="badge bg-danger me-2">
                                Supra Admin
                            </span>
                            <span class="badge bg-secondary">
                                Administration
                            </span>
                        </div>
                    </div>
                    <div class="col-auto">
                        <a href="edit_profile.php" class="btn btn-light btn-sm me-2">
                            <i class="fas fa-edit me-1"></i>Edit Profile
                        </a>
                        <button class="btn btn-light btn-sm" id="shareProfileBtn">
                            <i class="fas fa-share-alt me-1"></i>Share Profile
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Share Profile Modal -->
        <div class="modal fade" id="shareProfileModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Share Profile</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Generate a link to share your profile with others.
                        </p>
                        <div class="input-group mb-3" id="linkGroup" style="display: none;">
                            <input type="text" class="form-control" id="shareLink" readonly>
                            <button class="btn btn-danger" id="copyButton">
                                <i class="fas fa-copy me-2"></i>Copy
                            </button>
                        </div>
                        <div id="copyMessage" class="text-success" style="display: none;">
                            <i class="fas fa-check me-2"></i>Link copied to clipboard!
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-danger" id="generateLink">Generate Link</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-danger">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <div class="stats-number" id="active-users-count"><?php echo $active_users_count; ?></div>
                    <div class="text-muted">Active Users</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-warning">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stats-number" id="pending-count"><?php echo $pending_users; ?></div>
                    <div class="text-muted">Pending Approvals</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-success">
                        <i class="fas fa-user-nurse"></i>
                    </div>
                    <div class="stats-number" id="active-nurses-count"><?php echo $nurse_count; ?></div>
                    <div class="text-muted">Active Nurses</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon text-info">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="stats-number" id="active-doctors-count"><?php echo $doctor_count; ?></div>
                    <div class="text-muted">Active Doctors</div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <a href="manage_rooms_beds.php" class="quick-action-btn">
                            <i class="fas fa-door-open quick-action-icon"></i>
                            <span>Manage Rooms & Beds</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Registrations -->
        <div class="card mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-user-clock me-2"></i>Pending Registrations
                    <small class="text-muted ms-2" id="lastPendingUpdateTime"></small>
                    <button class="btn btn-sm btn-outline-primary ms-2" id="refreshPendingRegistrations">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </h5>
                <div>
                    <span class="badge bg-warning me-2" id="pending-users-badge" style="display: none;">
                        <i class="fas fa-bell me-1"></i><span id="pending-count-badge">0</span> New
                    </span>
                    <audio id="notificationSound" style="display: none;">
                        <source src="assets/notification.mp3" type="audio/mpeg">
                    </audio>
                </div>
            </div>
            <div class="card-body" id="pendingUsersContainer">
                <?php if (empty($pending_users_list)): ?>
                    <p class="text-muted text-center mb-0">No pending registrations</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Employee Type</th>
                                    <th>Department</th>
                            <th>Registration Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pending_users_list as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars(mask_username_for_display($user['email'])); ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucfirst(str_replace('_', ' ', $user['employee_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($user['department']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <button type="button" class="btn btn-success btn-action btn-sm approve-user" data-user-id="<?php echo $user['id']; ?>">
                                        <i class="fas fa-check me-1"></i>Approve
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

<!-- Active Users -->
<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-users me-2"></i>Active Users
            </h5>
            <div class="input-group ms-3" style="width: 300px;">
                <span class="input-group-text">
                    <i class="fas fa-search"></i>
                </span>
                <input type="text" class="form-control" id="activeUserSearch" placeholder="Search users...">
                <button class="btn btn-outline-secondary" type="button" id="clearActiveUserSearch" style="display: none;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <small class="text-muted ms-3" id="lastActiveUpdateTime"></small>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary btn-sm active" data-filter="all">
                All Users
            </button>
            <button type="button" class="btn btn-outline-success btn-sm" data-filter="active">
                Active Only
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" data-filter="inactive">
                Inactive Only
            </button>
        </div>
    </div>
    <div class="card-body" id="activeUsersContainer">
        <?php if (empty($active_users)): ?>
            <p class="text-muted text-center mb-0">No active users</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Employee Type</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($active_users as $user): ?>
                            <tr data-status="<?php echo $user['status']; ?>">
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars(mask_username_for_display($user['email'])); ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucfirst(str_replace('_', ' ', $user['employee_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($user['department']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge status-badge <?php echo $user['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo ucfirst($user['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" class="status-form">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <?php if ($user['status'] === 'active'): ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <button type="submit" class="btn btn-danger btn-action btn-sm">
                                                <i class="fas fa-ban me-1"></i>Deactivate
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="activate">
                                            <button type="submit" class="btn btn-success btn-action btn-sm">
                                                <i class="fas fa-check-circle me-1"></i>Reactivate
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Audit Trails -->
<div class="card mt-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-clipboard-list me-2"></i>Audit Trails
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($audit_logs)): ?>
            <p class="text-muted text-center mb-0">No audit entries yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_logs as $log): ?>
                        <tr>
                            <td><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($log['actor_name'] ?? ('#'.$log['actor_id'])); ?></td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo htmlspecialchars(str_replace('_',' ', $log['action'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if (($log['target_type'] ?? '') === 'user') {
                                    echo '<i class="fas fa-user me-1 text-muted"></i>' . htmlspecialchars($log['target_name'] ?? ('#'.$log['target_id']));
                                } else {
                                    echo htmlspecialchars(($log['target_type'] ?? '-')) . ' #' . intval($log['target_id'] ?? 0);
                                }
                                ?>
                            </td>
                            <td class="text-muted small">
                                <?php echo nl2br(htmlspecialchars($log['details'] ?? '')); ?>
                            </td>
                            <td class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer text-muted small">
        Showing latest 50 actions
    </div>
</div>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Navbar toggler manual control for reliability
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
                // Show success message
                const successDiv = document.createElement('div');
                successDiv.className = 'alert alert-success alert-dismissible fade show mt-2';
                successDiv.innerHTML = `
                    <i class="fas fa-check-circle me-2"></i>
                    ${data.message || 'User status updated successfully!'}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Insert message after the user info card
                const userInfoCard = document.querySelector('.card.mb-4');
                if (userInfoCard) {
                    userInfoCard.insertAdjacentElement('afterend', successDiv);
                }

                // Reload the page after delay
                setTimeout(() => {
                    window.location.href = window.location.pathname;
                }, 1500);
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
            
            // Insert error message after the user info card
            const userInfoCard = document.querySelector('.card.mb-4');
            if (userInfoCard) {
                userInfoCard.insertAdjacentElement('afterend', alertDiv);
            }

            // Remove error message after 3 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 3000);
        });
    }

    // Handle all form submissions
    document.querySelectorAll('.pending-form, .status-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleFormSubmission(this);
        });
    });

    // Add search functionality for active users
    const activeUserSearch = document.getElementById('activeUserSearch');
    const clearActiveUserSearch = document.getElementById('clearActiveUserSearch');
    let currentFilter = 'all';

    function filterUsers() {
        const searchTerm = activeUserSearch.value.toLowerCase().trim();
        clearActiveUserSearch.style.display = searchTerm ? 'block' : 'none';
        
        const rows = document.querySelectorAll('#activeUsersContainer tbody tr');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            const text = row.textContent.toLowerCase();
            const matchesSearch = searchTerm === '' || text.includes(searchTerm);
            const matchesFilter = currentFilter === 'all' || status === currentFilter;
            
            if (matchesSearch && matchesFilter) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Show or hide "no results" message
        let noResultsMsg = activeUsersContainer.querySelector('.no-results-message');
        if (visibleCount === 0) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('p');
                noResultsMsg.className = 'text-muted text-center py-3 no-results-message';
                noResultsMsg.innerHTML = '<i class="fas fa-search me-2"></i>No users found matching your search.';
                const table = activeUsersContainer.querySelector('.table-responsive');
                if (table) {
                    table.insertAdjacentElement('beforebegin', noResultsMsg);
                }
            }
        } else if (noResultsMsg) {
            noResultsMsg.remove();
        }
    }

    // Search input event listener
    if (activeUserSearch) {
        activeUserSearch.addEventListener('input', filterUsers);
    }

    // Clear search button event listener
    if (clearActiveUserSearch) {
        clearActiveUserSearch.addEventListener('click', () => {
            activeUserSearch.value = '';
            filterUsers();
        });
    }

    // Filter buttons functionality
    document.querySelectorAll('.btn-group button').forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons
            document.querySelectorAll('.btn-group button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Add active class to clicked button
            this.classList.add('active');
            
            // Update current filter and apply filtering
            currentFilter = this.getAttribute('data-filter');
            filterUsers();
        });
    });

    // Handle approve buttons
    document.querySelectorAll('.approve-user').forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            const originalButtonHtml = this.innerHTML;
            const button = this;
            const row = button.closest('tr');

            // Remove any existing alerts
            document.querySelectorAll('.alert').forEach(alert => alert.remove());

            // Show loading state
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';

            // Create form data
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('action', 'approve');

            // Send approval request
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
                    // Show success message
                    const successDiv = document.createElement('div');
                    successDiv.className = 'alert alert-success alert-dismissible fade show mt-2';
                    successDiv.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>
                        ${data.message || 'User approved successfully!'}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `;
                    
                    // Insert message after the user info card
                    const userInfoCard = document.querySelector('.card.mb-4');
                    if (userInfoCard) {
                        userInfoCard.insertAdjacentElement('afterend', successDiv);
                    }

                    // Update button to show deactivate option
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-ban me-1"></i>Deactivate';
                    button.classList.remove('btn-success', 'approve-user');
                    button.classList.add('btn-danger');
                    
                    // Update button functionality to handle deactivation
                    button.removeEventListener('click', arguments.callee);
                    button.addEventListener('click', function() {
                        const deactivateFormData = new FormData();
                        deactivateFormData.append('user_id', userId);
                        deactivateFormData.append('action', 'deactivate');
                        
                        // Show loading state
                        button.disabled = true;
                        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';
                        
                        fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: deactivateFormData
                        })
                        .then(response => response.json())
                        .then(deactivateData => {
                            if (deactivateData.success) {
                                // Show deactivation success message
                                const deactivateSuccessDiv = document.createElement('div');
                                deactivateSuccessDiv.className = 'alert alert-success alert-dismissible fade show mt-2';
                                deactivateSuccessDiv.innerHTML = `
                                    <i class="fas fa-check-circle me-2"></i>
                                    User deactivated successfully!
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                `;
                                userInfoCard.insertAdjacentElement('afterend', deactivateSuccessDiv);
                                
                                // Update button to show activate option
                                button.disabled = false;
                                button.innerHTML = '<i class="fas fa-check-circle me-1"></i>Activate';
                                button.classList.remove('btn-danger');
                                button.classList.add('btn-success');
                                
                                // Remove success message after 3 seconds
                                setTimeout(() => {
                                    if (deactivateSuccessDiv.parentNode) {
                                        deactivateSuccessDiv.remove();
                                    }
                                }, 3000);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            button.disabled = false;
                            button.innerHTML = '<i class="fas fa-ban me-1"></i>Deactivate';
                        });
                    });

                    // Update the pending count in the stats
                    const pendingCountElement = document.getElementById('pending-count');
                    if (pendingCountElement) {
                        const currentCount = parseInt(pendingCountElement.textContent);
                        if (!isNaN(currentCount) && currentCount > 0) {
                            pendingCountElement.textContent = currentCount - 1;
                        }
                    }

                    // Remove success message after 3 seconds
                    setTimeout(() => {
                        if (successDiv.parentNode) {
                            successDiv.remove();
                        }
                    }, 3000);
                } else {
                    throw new Error(data.error || 'Failed to approve user');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Restore button state
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
                
                // Show error message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-2';
                alertDiv.innerHTML = `
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${error.message || 'An error occurred. Please try again.'}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Insert error message after the user info card
                const userInfoCard = document.querySelector('.card.mb-4');
                if (userInfoCard) {
                    userInfoCard.insertAdjacentElement('afterend', alertDiv);
                }

                // Remove error message after 3 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 3000);
            });
        });
    });
});
</script>
</body>
</html> 
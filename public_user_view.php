<?php
require_once "config/database.php";

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if token is provided
if (!isset($_GET['token'])) {
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <title>Invalid Link - Hospital Management System</title>
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

    // Get user information using the token
    $query = "SELECT u.*, 
              CASE 
                WHEN u.employee_type = 'doctor' THEN 'Dr.'
                ELSE ''
              END as title
              FROM user_share_tokens st
              JOIN users u ON st.user_id = u.id 
              WHERE st.token = :token";

    $stmt = $db->prepare($query);
    $stmt->bindParam(":token", $_GET['token']);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // If token is invalid
    if (!$user) {
        die('
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invalid Link - Hospital Management System</title>
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
                    <p class="text-muted">This link is invalid or has expired. Please request a new link.</p>
                    <a href="index.php" class="btn btn-danger mt-3">Go to Homepage</a>
                </div>
            </div>
        </body>
        </html>
        ');
        exit();
    }
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Profile - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
        }
        .profile-header {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.2);
        }
        .profile-picture {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid rgba(255,255,255,0.3);
            margin-bottom: 1rem;
        }
        .profile-name {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .profile-role {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }
        .department-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 0.9rem;
            margin-top: 10px;
            display: inline-block;
        }
        .contact-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            padding: 8px 20px;
            text-decoration: none;
            transition: all 0.3s;
            margin-top: 1rem;
            display: inline-block;
        }
        .contact-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
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
        a {
            color: #dc3545;
            text-decoration: none;
        }
        a:hover {
            color: #bb2d3b;
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
        .form-control:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-img">
                            <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture">
                            <?php else: ?>
                                <?php
                                $icon_class = 'fa-user-circle';
                                switch($user['employee_type']) {
                                    case 'doctor':
                                        $icon_class = 'fa-user-md';
                                        break;
                                    case 'nurse':
                                        $icon_class = 'fa-user-nurse';
                                        break;
                                    case 'pharmacist':
                                        $icon_class = 'fa-pills';
                                        break;
                                    case 'lab_technician':
                                        $icon_class = 'fa-flask';
                                        break;
                                }
                                ?>
                                <i class="fas <?php echo $icon_class; ?>"></i>
                            <?php endif; ?>
                        </div>
                        <h3 class="mb-0"><?php echo $user['title'] . ' ' . htmlspecialchars($user['name']); ?></h3>
                        <p class="mb-2"><?php echo ucfirst(str_replace('_', ' ', $user['employee_type'])); ?></p>
                        <span class="department-badge">
                            <i class="fas fa-hospital-user me-1"></i>
                            <?php echo htmlspecialchars($user['department']); ?>
                        </span>
                    </div>
                    <div class="profile-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="info-label">Email</p>
                                <p class="info-value">
                                    <i class="fas fa-envelope me-2 text-danger"></i>
                                    <?php echo htmlspecialchars($user['email']); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="info-label">Role</p>
                                <p class="info-value">
                                    <i class="fas fa-id-badge me-2 text-danger"></i>
                                    <?php echo ucfirst($user['role']); ?>
                                </p>
                            </div>
                            <div class="col-12">
                                <p class="info-label">Department</p>
                                <p class="info-value">
                                    <i class="fas fa-hospital me-2 text-danger"></i>
                                    <?php echo htmlspecialchars($user['department']); ?>
                                </p>
                            </div>
                            <?php if($user['employee_type'] === 'doctor'): ?>
                            <div class="col-12">
                                <p class="info-label">Specialization</p>
                                <p class="info-value">
                                    <i class="fas fa-stethoscope me-2 text-danger"></i>
                                    <?php echo htmlspecialchars($user['department']); ?> Specialist
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
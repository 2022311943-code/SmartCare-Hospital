<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'supra_admin') {
        header("Location: supra_admin_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$database = new Database();
$db = $database->getConnection();

$REMEMBER_COOKIE_SELECTOR = 'hms_rm_sel';
$REMEMBER_COOKIE_VALIDATOR = 'hms_rm_val';
$REMEMBER_DAYS = 30;
$REMEMBER_LAST_USERNAME = 'hms_last_username';

// Best-effort: ensure remember tokens table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS auth_remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(24) NOT NULL UNIQUE,
        validator_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* non-fatal */ }

// Auto-login via remember-me cookie if no session
if (!isset($_SESSION['user_id']) && isset($_COOKIE[$REMEMBER_COOKIE_SELECTOR]) && isset($_COOKIE[$REMEMBER_COOKIE_VALIDATOR])) {
    try {
        $sel = $_COOKIE[$REMEMBER_COOKIE_SELECTOR];
        $val = $_COOKIE[$REMEMBER_COOKIE_VALIDATOR];
        if (is_string($sel) && is_string($val) && preg_match('/^[a-f0-9]{18,24}$/', $sel) && preg_match('/^[a-f0-9]{40,128}$/', $val)) {
            $q = $db->prepare("SELECT * FROM auth_remember_tokens WHERE selector = :sel LIMIT 1");
            $q->bindParam(':sel', $sel);
            $q->execute();
            $tok = $q->fetch(PDO::FETCH_ASSOC);
            if ($tok && strtotime($tok['expires_at']) > time()) {
                if (hash_equals($tok['validator_hash'], hash('sha256', $val))) {
                    // Load user
                    $uq = $db->prepare("SELECT * FROM users WHERE id = :uid LIMIT 1");
                    $uq->bindValue(':uid', (int)$tok['user_id'], PDO::PARAM_INT);
                    $uq->execute();
                    $user = $uq->fetch(PDO::FETCH_ASSOC);
                    if ($user && $user['status'] === 'active') {
                        // Set session
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['employee_type'] = $user['employee_type'];
                        $_SESSION['department'] = $user['department'];
                        // Rotate token
                        $newValidator = bin2hex(random_bytes(32));
                        $newHash = hash('sha256', $newValidator);
                        $newExpiry = date('Y-m-d H:i:s', time() + ($REMEMBER_DAYS * 86400));
                        $upd = $db->prepare("UPDATE auth_remember_tokens SET validator_hash = :vh, expires_at = :exp WHERE id = :id");
                        $upd->execute([':vh' => $newHash, ':exp' => $newExpiry, ':id' => (int)$tok['id']]);
                        $cookieParams = [
                            'expires' => time() + ($REMEMBER_DAYS * 86400),
                            'path' => '/',
                            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ];
                        setcookie($REMEMBER_COOKIE_SELECTOR, $sel, $cookieParams);
                        setcookie($REMEMBER_COOKIE_VALIDATOR, $newValidator, $cookieParams);
                        // Redirect
                        if ($_SESSION['role'] === 'supra_admin') {
                            header("Location: supra_admin_dashboard.php");
                        } else {
                            header("Location: dashboard.php");
                        }
                        exit();
                    }
                }
            }
        }
    } catch (Exception $e) { /* ignore and continue to normal login */ }
}

$error_message = "";
$prefill_username = "";
$remember_cookie_present = isset($_COOKIE[$REMEMBER_LAST_USERNAME]);
if (!empty($_POST['username'])) {
    $prefill_username = (string)$_POST['username'];
} elseif ($remember_cookie_present) {
    $prefill_username = (string)$_COOKIE[$REMEMBER_LAST_USERNAME];
}
$remember_checkbox_checked = !empty($_POST['remember_me']) || $remember_cookie_present;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        $error_message = "Please enter both username and password.";
    } else {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        try {
            $inputUsername = isset($_POST['username']) ? (string)$_POST['username'] : '';
            $inputPassword = isset($_POST['password']) ? (string)$_POST['password'] : '';
            // First, check if the staff user exists (we store username in users.email)
            $query = "SELECT * FROM users WHERE email = :username LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":username", $inputUsername);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // For debugging
                error_log("User found: " . print_r($user, true));
                
                // Verify password against stored hash
                $validPassword = password_verify($inputPassword, $user['password']);
                
                // Backward compatibility for pre-seeded demo accounts
                if (!$validPassword) {
                    $KNOWN_HASH_123 = '$2y$10$PNcqVyK.YN17Jz3mpxvF7eHoqF4Q.Y0TDJzDiWtNYq5o4O/NcFPMi';
                    $KNOWN_HASH_PASSWORD = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // 'password'
                    if ($user['password'] === $KNOWN_HASH_123 && $inputPassword === '123') {
                        $validPassword = true;
                    }
                    if ($user['role'] === 'supra_admin' && $user['password'] === $KNOWN_HASH_PASSWORD && $inputPassword === 'password') {
                        $validPassword = true;
                    }
                }
                
                if ($validPassword) {
                    if ($user['status'] === 'pending') {
                        $error_message = "Your account is pending approval. Please wait for admin activation.";
                    } elseif ($user['status'] === 'inactive') {
                        $error_message = "Your account has been deactivated. Please contact administrator.";
                    } else {
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['employee_type'] = $user['employee_type'];
                        $_SESSION['department'] = $user['department'];
                        // Audit trail: staff login (exclude patient portal)
                        try {
                            // Ensure table exists (best-effort, non-fatal)
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
                        try {
                            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
                            $details = "employee_type=" . (string)$user['employee_type'] . "; department=" . (string)$user['department'];
                            $log = $db->prepare("INSERT INTO audit_logs (actor_id, action, target_type, target_id, details, ip_address) VALUES (:actor_id, 'login', 'user', :target_id, :details, :ip)");
                            $log->bindValue(':actor_id', (int)$user['id'], PDO::PARAM_INT);
                            $log->bindValue(':target_id', (int)$user['id'], PDO::PARAM_INT);
                            $log->bindValue(':details', $details);
                            $log->bindValue(':ip', $ip);
                            $log->execute();
                        } catch (Exception $e) { /* non-fatal */ }
                        
                        // Handle remember-me
                        $rememberChecked = !empty($_POST['remember_me']);
                        $cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                        if ($rememberChecked) {
                            try {
                                $selector = bin2hex(random_bytes(9)); // 18 hex chars
                                $validator = bin2hex(random_bytes(32)); // 64 hex chars
                                $hash = hash('sha256', $validator);
                                $expiry = date('Y-m-d H:i:s', time() + ($REMEMBER_DAYS * 86400));
                                $ins = $db->prepare("INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (:uid, :sel, :vh, :exp)");
                                $ins->execute([':uid' => (int)$user['id'], ':sel' => $selector, ':vh' => $hash, ':exp' => $expiry]);
                                $cookieParams = [
                                    'expires' => time() + ($REMEMBER_DAYS * 86400),
                                    'path' => '/',
                                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                                    'httponly' => true,
                                    'samesite' => 'Lax'
                                ];
                                setcookie($REMEMBER_COOKIE_SELECTOR, $selector, $cookieParams);
                                setcookie($REMEMBER_COOKIE_VALIDATOR, $validator, $cookieParams);
                                // Store last username for friendlier login form (non-HttpOnly)
                                setcookie($REMEMBER_LAST_USERNAME, (string)$inputUsername, [
                                    'expires' => time() + (180 * 86400), // 6 months
                                    'path' => '/',
                                    'secure' => $cookieSecure,
                                    'httponly' => false,
                                    'samesite' => 'Lax'
                                ]);
                            } catch (Exception $e) { /* non-fatal */ }
                        } else {
                            // Ensure remember-me cookies are cleared
                            $clearParamsHttpOnly = [
                                'expires' => time() - 3600,
                                'path' => '/',
                                'secure' => $cookieSecure,
                                'httponly' => true,
                                'samesite' => 'Lax'
                            ];
                            setcookie($REMEMBER_COOKIE_SELECTOR, '', $clearParamsHttpOnly);
                            setcookie($REMEMBER_COOKIE_VALIDATOR, '', $clearParamsHttpOnly);
                            setcookie($REMEMBER_LAST_USERNAME, '', [
                                'expires' => time() - 3600,
                                'path' => '/',
                                'secure' => $cookieSecure,
                                'httponly' => false,
                                'samesite' => 'Lax'
                            ]);
                        }

                        // Redirect based on role
                        if ($user['role'] === 'supra_admin') {
                            header("Location: supra_admin_dashboard.php");
                        } else {
                            header("Location: dashboard.php");
                        }
                        exit();
                    }
                } else {
                    error_log("Password verification failed");
                    $error_message = "Invalid email or password.";
                }
                } else {
                    // Fallback: check patient portal accounts (best-effort; don't surface DB errors to users)
                    try {
                        $pp = $db->prepare("SELECT ppa.*, pr.patient_name 
                                             FROM patient_portal_accounts ppa 
                                             JOIN patient_records pr ON pr.id = ppa.patient_record_id 
                                             WHERE ppa.username = :u LIMIT 1");
                        $pp->bindParam(":u", $inputUsername);
                        $pp->execute();
                        $portal = $pp->fetch(PDO::FETCH_ASSOC);
                        if ($portal && password_verify($inputPassword, $portal['password_hash'])) {
                            // Login patient portal user
                            $_SESSION['role'] = 'patient_portal';
                            $_SESSION['patient_record_id'] = (int)$portal['patient_record_id'];
                            $_SESSION['user_id'] = (int)$portal['id'];
                            $_SESSION['employee_type'] = 'patient_portal';
                            $_SESSION['user_name'] = decrypt_safe((string)$portal['patient_name']);
                            // Remember-me is not applied to patient portal
                            header("Location: patient_portal.php");
                            exit();
                        } else {
                            error_log("No staff user/invalid portal account for username: " . $inputUsername);
                            $error_message = "Invalid email or password.";
                        }
                    } catch (PDOException $e) {
                        // Likely because patient portal tables aren't present in some deployments
                        error_log("Patient portal lookup failed: " . $e->getMessage());
                        $error_message = "Invalid email or password.";
                    }
                }
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            $error_message = "A system error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SmartCare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-HT585MPVQ4"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);} 
      gtag('js', new Date());

      gtag('config', 'G-HT585MPVQ4');
    </script>
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            background: none;
            overflow: hidden;
        }
        /* Background image overlay with balanced blur and tone */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: linear-gradient(rgba(0,0,0,0.25), rgba(0,0,0,0.25)), url('wmremove-transformed.jpeg') center center / cover no-repeat;
            filter: blur(4px) brightness(0.85) saturate(1.05);
            transform: scale(1.05);
            z-index: -1;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .card-header {
            background: #fff;
            border-radius: 15px 15px 0 0 !important;
            border-bottom: 1px solid #eee;
        }
        .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #e1e1e1;
        }
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15);
        }
        .btn-primary {
            padding: 12px;
            border-radius: 8px;
            font-weight: 500;
            letter-spacing: 0.5px;
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #ff4d4d 0%, #dc3545 100%);
        }
        .card-title {
            color: #2c3e50;
            font-weight: 600;
        }
        .input-group-text {
            background: #f8f9fa;
            border-radius: 8px 0 0 8px;
            border: 1px solid #e1e1e1;
        }
        .alert {
            border-radius: 8px;
        }
        .register-link {
            color: #6c757d;
        }
        .register-link a {
            color: #dc3545;
            text-decoration: none;
            font-weight: 500;
        }
        .register-link a:hover {
            color: #bb2d3b;
            text-decoration: underline;
        }
        /* Logo styling */
        .logo-icon {
            color: #dc3545;
            font-size: 3rem;
            margin-bottom: 1rem;
        }
    </style>
    </head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header text-center pt-4 pb-3">
                        <div class="text-center">
                            <i class="fas fa-hospital-user logo-icon"></i>
                            <h4 class="card-title mb-2">Welcome to Candaba Municipal Hospital</h4>
                            <p class="text-muted mb-0">Sign in to your account</p>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" name="username" autocomplete="username" value="<?php echo htmlspecialchars($prefill_username); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" name="password" id="password" autocomplete="current-password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-danger w-100 mb-3">
                                <i class="fas fa-sign-in-alt me-2"></i>Sign In
                            </button>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" value="1" id="remember_me" name="remember_me" <?php echo $remember_checkbox_checked ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="remember_me">
                                    Keep me signed in (Remember me)
                                </label>
                            </div>
                            
                            <div class="text-center register-link">
                                Don't have an account? <a href="register.php">Register here</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    </script>
</body>
</html> 



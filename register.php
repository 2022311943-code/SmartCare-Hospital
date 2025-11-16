<?php
session_start();
require_once "config/database.php";

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error_message = "";
$success_message = "";

// Define departments for each employee type
$departments = [
    'general_doctor' => [
        'General Practice',
        'OPD',
        'Emergency'
    ],
    'doctor' => [
        'Cardiology',
        'Neurology',
        'Pediatrics',
        'Orthopedics',
        'Dermatology',
        'Ophthalmology',
        'ENT',
        'General Medicine',
        'Surgery',
        'Gynecology',
        'OB-GYN',
        'General Physician'
    ],
    'nurse' => [
        'Emergency',
        'ICU',
        'Pediatrics',
        'Surgery',
        'General Ward',
        'Maternity',
        'Outpatient'
    ],
    'medical_technician' => [
        'Laboratory',
        'Diagnostic',
        'Clinical',
        'Research'
    ],
    'receptionist' => [
        'Front Desk',
        'Patient Services',
        'Information',
        'Appointments'
    ],
    'medical_staff' => [
        'Operating Room',
        'Emergency Room',
        'Intensive Care',
        'General Practice'
    ],
    'finance' => [
        'Billing',
        'Insurance',
        'Accounts',
        'Revenue'
    ],
    'radiologist' => [
        'X-Ray',
        'CT Scan',
        'MRI',
        'Ultrasound',
        'Nuclear Medicine'
    ],
    'pharmacist' => [
        'Main Pharmacy',
        'Emergency Pharmacy',
        'OPD Pharmacy',
        'IPD Pharmacy'
    ],
    'admin_staff' => [
        'Administration',
        'Human Resources',
        'Operations',
        'Facilities'
    ],
    'it' => [
        'Systems Administration',
        'Network Support',
        'Technical Support',
        'Software Development'
    ]
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $required_fields = ['first_name', 'last_name', 'username', 'password', 'confirm_password', 'employee_type', 'department'];
    $missing_fields = false;
    
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            $missing_fields = true;
            break;
        }
    }
    
    if ($missing_fields) {
        $error_message = "All fields are required.";
    } elseif (
        !preg_match('/^[\\p{L}\s\.\'-]+$/u', $_POST['first_name']) ||
        !preg_match('/^[\\p{L}\s\.\'-]+$/u', $_POST['last_name']) ||
        (!empty(trim($_POST['middle_name'])) && !preg_match('/^[\\p{L}\s\.\'-]+$/u', $_POST['middle_name']))
    ) {
        $error_message = "Name fields may contain letters, spaces, hyphens, apostrophes, and periods only.";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{12,}$/', $_POST['password'])) {
        $error_message = "Password must be at least 12 characters and include uppercase, lowercase, number, and symbol.";
    } elseif ($_POST['password'] !== $_POST['confirm_password']) {
        $error_message = "Passwords do not match.";
    } else {
        // Check if username already exists (stored in users.email column)
        $check_query = "SELECT id FROM users WHERE email = :username";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(":username", $_POST['username']);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            $error_message = "Username already taken.";
        } else {
            // Insert new user
            $insert_query = "INSERT INTO users (name, email, password, employee_type, department, role, status) 
                           VALUES (:name, :email, :password, :employee_type, :department, 'staff', 'pending')";
            
            try {
                $stmt = $db->prepare($insert_query);
                
                $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                // Build full name from separate fields
                $first_name = trim($_POST['first_name']);
                $middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
                $last_name = trim($_POST['last_name']);
                $suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : '';
                $full_name = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([$first_name, $middle_name, $last_name, $suffix]))));

                $stmt->bindParam(":name", $full_name);
                // store username in users.email column for compatibility
                $stmt->bindParam(":email", $_POST['username']);
                $stmt->bindParam(":password", $password_hash);
                $stmt->bindParam(":employee_type", $_POST['employee_type']);
                $stmt->bindParam(":department", $_POST['department']);
                
                if ($stmt->execute()) {
                    $success_message = "Registration successful! Please wait for admin approval to login.";
                    // Clear form values after successful registration
                    $_POST = array();
                } else {
                    $error_message = "Registration failed. Please try again.";
                }
            } catch (PDOException $e) {
                $error_message = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            background: none;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
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
        .form-control, .form-select {
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #e1e1e1;
        }
        .form-control:focus, .form-select:focus {
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
        /* Logo styling */
        .logo-icon {
            color: #dc3545;
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .login-link {
            color: #6c757d;
        }
        .login-link a {
            color: #dc3545;
            text-decoration: none;
            font-weight: 500;
        }
        .login-link a:hover {
            color: #bb2d3b;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header text-center pt-4 pb-3">
                        <div class="text-center">
                            <i class="fas fa-user-plus logo-icon"></i>
                            <h4 class="card-title mb-2">Create Account</h4>
                            <p class="text-muted mb-0">Join our healthcare team</p>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success_message): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <div class="row g-2">
                                    <div class="col-12 col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="first_name" placeholder="First Name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" inputmode="text" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ\s.'-]+$" title="Only letters, spaces, hyphens, apostrophes, and periods are allowed" required>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="middle_name" placeholder="Middle Name (optional)" value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>" inputmode="text" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ\s.'-]+$" title="Only letters, spaces, hyphens, apostrophes, and periods are allowed">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-8">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="last_name" placeholder="Last Name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" inputmode="text" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ\s.'-]+$" title="Only letters, spaces, hyphens, apostrophes, and periods are allowed" required>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user-tag"></i></span>
                                            <input type="text" class="form-control" name="suffix" placeholder="Suffix (e.g., Jr., III)" value="<?php echo isset($_POST['suffix']) ? htmlspecialchars($_POST['suffix']) : ''; ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" name="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Employee Type</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                                    <select class="form-select" name="employee_type" id="employee_type" required>
                                        <option value="">Select Employee Type</option>
                                        <option value="general_doctor" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'general_doctor') ? 'selected' : ''; ?>>General Doctor</option>
                                        <option value="doctor" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'doctor') ? 'selected' : ''; ?>>Specialist Doctor</option>
                                        <!-- OB-GYN and General Physician are departments under Specialist Doctor -->
                                        <option value="nurse" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'nurse') ? 'selected' : ''; ?>>Nurse</option>
                                        <option value="medical_technician" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'medical_technician') ? 'selected' : ''; ?>>Medical Technician</option>
                                        <option value="receptionist" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'receptionist') ? 'selected' : ''; ?>>Front Desk/Receptionist</option>
                                        <option value="medical_staff" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'medical_staff') ? 'selected' : ''; ?>>Medical Staff</option>
                                        <option value="finance" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'finance') ? 'selected' : ''; ?>>Finance/Billing</option>
                                        <option value="radiologist" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'radiologist') ? 'selected' : ''; ?>>Radiologist</option>
                                        <option value="pharmacist" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'pharmacist') ? 'selected' : ''; ?>>Pharmacist</option>
                                        <option value="admin_staff" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'admin_staff') ? 'selected' : ''; ?>>Admin Staff</option>
                                        <option value="it" <?php echo (isset($_POST['employee_type']) && $_POST['employee_type'] === 'it') ? 'selected' : ''; ?>>IT</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Department</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hospital"></i></span>
                                    <select class="form-select" name="department" id="department" required>
                                        <option value="">Select Department</option>
                                        <?php
                                        if (isset($_POST['employee_type']) && isset($departments[$_POST['employee_type']])) {
                                            foreach ($departments[$_POST['employee_type']] as $dept) {
                                                $selected = (isset($_POST['department']) && $_POST['department'] === $dept) ? 'selected' : '';
                                                echo "<option value=\"" . htmlspecialchars($dept) . "\" $selected>" . htmlspecialchars($dept) . "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control <?php echo ($error_message && strpos($error_message,'Password')===0) ? 'is-invalid' : ''; ?>" name="password" id="password" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}" title="Minimum 12 characters with uppercase, lowercase, number, and symbol" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($error_message && strpos($error_message,'Password')===0): ?>
                                    <div class="invalid-feedback">
                                        <?php echo htmlspecialchars($error_message); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Confirm Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control <?php echo ($error_message && (strpos($error_message,'Passwords do not match')===0 || strpos($error_message,'Password must')===0)) ? 'is-invalid' : ''; ?>" name="confirm_password" id="confirmPassword" minlength="12" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($error_message === 'Passwords do not match.'): ?>
                                    <div class="invalid-feedback">
                                        Passwords do not match
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-danger w-100 mb-3">
                                <i class="fas fa-user-plus me-2"></i>Register
                            </button>
                            
                            <div class="text-center login-link">
                                Already have an account? <a href="index.php">Log in here</a>
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
        // Function to toggle password visibility for both fields
        function togglePasswordVisibility(clickedButtonId) {
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const passwordButton = document.getElementById('togglePassword');
            const confirmPasswordButton = document.getElementById('toggleConfirmPassword');
            
            // Get both icons
            const passwordIcon = passwordButton.querySelector('i');
            const confirmPasswordIcon = confirmPasswordButton.querySelector('i');
            
            // Toggle both password fields
            if (passwordInput.type === 'password') {
                // Show passwords
                passwordInput.type = 'text';
                confirmPasswordInput.type = 'text';
                // Change both icons
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
                confirmPasswordIcon.classList.remove('fa-eye');
                confirmPasswordIcon.classList.add('fa-eye-slash');
            } else {
                // Hide passwords
                passwordInput.type = 'password';
                confirmPasswordInput.type = 'password';
                // Change both icons
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
                confirmPasswordIcon.classList.remove('fa-eye-slash');
                confirmPasswordIcon.classList.add('fa-eye');
            }
        }

        // Add event listeners for both password toggle buttons
        document.getElementById('togglePassword').addEventListener('click', function() {
            togglePasswordVisibility('togglePassword');
        });

        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            togglePasswordVisibility('toggleConfirmPassword');
        });

        // Add real-time password matching validation
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');

        function validatePasswords() {
            const strongPattern = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}$/;
            const weak = passwordInput.value.length > 0 && !strongPattern.test(passwordInput.value);
            const mismatch = confirmPasswordInput.value !== '' && passwordInput.value !== confirmPasswordInput.value;

            if (weak || mismatch) {
                passwordInput.classList.add('is-invalid');
                confirmPasswordInput.classList.add('is-invalid');
            } else {
                passwordInput.classList.remove('is-invalid');
                confirmPasswordInput.classList.remove('is-invalid');
            }
        }

        passwordInput.addEventListener('input', validatePasswords);
        confirmPasswordInput.addEventListener('input', validatePasswords);

        // Check if registration was successful and clear form
        <?php if ($success_message): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Clear all form inputs
            document.querySelectorAll('input').forEach(input => {
                input.value = '';
            });
            
            // Reset employee type and department dropdowns
            document.getElementById('employee_type').value = '';
            document.getElementById('department').innerHTML = '<option value="">Select Department</option>';
            
            // Remove any validation classes
            document.querySelectorAll('.is-invalid').forEach(el => {
                el.classList.remove('is-invalid');
            });
        });
        <?php endif; ?>
    </script>
    
    <script>
    // Department selection based on employee type
    const departments = <?php echo json_encode($departments); ?>;
    const employeeTypeSelect = document.getElementById('employee_type');
    const departmentSelect = document.getElementById('department');
    
    employeeTypeSelect.addEventListener('change', function() {
        const selectedType = this.value;
        departmentSelect.innerHTML = '<option value="">Select Department</option>';
        
        if (selectedType && departments[selectedType]) {
            departments[selectedType].forEach(function(department) {
                const option = document.createElement('option');
                option.value = department;
                option.textContent = department;
                departmentSelect.appendChild(option);
            });
        }
    });
    </script>
</body>
</html> 
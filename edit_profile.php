<?php
session_start();
require_once "config/database.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get user's current information
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(":user_id", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$success_message = "";
$error_message = "";

// Best-effort split of existing full name into components for prefilling
$first_name_val = '';
$middle_name_val = '';
$last_name_val = '';
$suffix_val = '';
if ($user && isset($user['name'])) {
    $full = trim((string)$user['name']);
    // Common suffixes
    $suffixes = ['jr','jr.','sr','sr.','iii','iv','v','vi'];
    $parts = preg_split('/\s+/', $full);
    if ($parts && count($parts) > 0) {
        $first_name_val = array_shift($parts);
        if (count($parts) > 0) {
            $last = strtolower(end($parts));
            if (in_array($last, $suffixes, true)) {
                $suffix_val = array_pop($parts);
            }
            if (count($parts) > 0) {
                $last_name_val = array_pop($parts);
                if (count($parts) > 0) { $middle_name_val = implode(' ', $parts); }
            } else {
                $last_name_val = '';
            }
        }
    }
}

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads/profile_pictures/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Define allowed file types and max file size
$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$max_file_size = 5 * 1024 * 1024; // 5MB

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle remove profile picture
    if (isset($_POST['action']) && $_POST['action'] === 'remove_picture') {
        try {
            $db->beginTransaction();
            
            // Delete the file if it exists
            if ($user['profile_picture'] && file_exists($user['profile_picture'])) {
                unlink($user['profile_picture']);
            }
            
            // Update database to remove profile picture
            $update_query = "UPDATE users SET profile_picture = NULL WHERE id = :user_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(":user_id", $_SESSION['user_id']);
            
            if ($update_stmt->execute()) {
                $db->commit();
                $success_message = "Profile picture removed successfully!";
                
                // Refresh user data
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                throw new Exception("Failed to remove profile picture");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "An error occurred while removing your profile picture. Please try again.";
        }
    } else {
        // Build full name from separate fields
        $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
        $middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
        $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
        $suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : '';
        $name = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([$first_name, $middle_name, $last_name, $suffix]))));
        // Preserve form values on validation error
        $first_name_val = $first_name;
        $middle_name_val = $middle_name;
        $last_name_val = $last_name;
        $suffix_val = $suffix;

        $email = trim($_POST['email']);
        $current_password = trim($_POST['current_password']);
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        // Validate inputs
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $error_message = "First name, last name and username are required.";
        } elseif (!preg_match('/^[\p{L}\s\.\'-]+$/u', $first_name) || (!empty($middle_name) && !preg_match('/^[\p{L}\s\.\'-]+$/u', $middle_name)) || !preg_match('/^[\p{L}\s\.\'-]+$/u', $last_name)) {
            $error_message = "Name fields may contain letters, spaces, hyphens, apostrophes, and periods only.";
        } elseif ($email !== $user['email']) {
            // Check if new email already exists
            $check_query = "SELECT id FROM users WHERE email = :email AND id != :user_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(":email", $email);
            $check_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error_message = "Username already taken.";
            }
        }
        
        // Handle profile picture upload
        $profile_picture = $user['profile_picture']; // Keep existing picture by default
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            
            // Validate file type
            if (!in_array($file['type'], $allowed_types)) {
                $error_message = "Invalid file type. Only JPG, PNG and GIF files are allowed.";
            }
            // Validate file size
            elseif ($file['size'] > $max_file_size) {
                $error_message = "File is too large. Maximum size is 5MB.";
            }
            else {
                // Generate unique filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid('profile_') . '.' . $ext;
                $filepath = $upload_dir . $filename;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Delete old profile picture if exists
                    if ($user['profile_picture'] && file_exists($user['profile_picture'])) {
                        unlink($user['profile_picture']);
                    }
                    $profile_picture = $filepath;
                } else {
                    $error_message = "Failed to upload profile picture.";
                }
            }
        }
        
        // If changing password, verify current password and validate new password
        if (!empty($current_password)) {
            if (!password_verify($current_password, $user['password'])) {
                $error_message = "Current password is incorrect.";
            } elseif (empty($new_password) || empty($confirm_password)) {
                $error_message = "Please enter and confirm your new password.";
            } elseif ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match.";
            }
        }
        
        // If no errors, update the user information
        if (empty($error_message)) {
            try {
                $db->beginTransaction();
                
                // Update basic information
                $update_query = "UPDATE users SET name = :name, email = :email, profile_picture = :profile_picture";
                $params = [
                    ":name" => $name,
                    ":email" => $email,
                    ":profile_picture" => $profile_picture,
                    ":user_id" => $_SESSION['user_id']
                ];
                
                // If password is being changed, add it to the update query
                if (!empty($new_password)) {
                    $update_query .= ", password = :password";
                    $params[":password"] = password_hash($new_password, PASSWORD_DEFAULT);
                }
                
                $update_query .= " WHERE id = :user_id";
                
                $update_stmt = $db->prepare($update_query);
                
                if ($update_stmt->execute($params)) {
                    $db->commit();
                    $_SESSION['user_name'] = $name; // Update session name
                    $success_message = "Profile updated successfully!";
                    
                    // Refresh user data
                    $stmt->execute();
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    throw new Exception("Failed to update profile");
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "An error occurred while updating your profile. Please try again.";
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
    <title>Edit Profile - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
            padding-top: 20px;
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
        .back-link {
            color: #6c757d;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: #dc3545;
        }
        .profile-picture {
            width: 180px;
            height: 180px;
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin: 0 auto;
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        .profile-picture:hover .profile-picture-overlay {
            opacity: 1;
        }
        .profile-picture-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 50%;
        }
        .profile-picture-overlay i {
            font-size: 24px;
            margin-bottom: 8px;
        }
        .profile-picture-overlay span {
            font-size: 14px;
            text-align: center;
            padding: 0 10px;
        }
        .profile-picture img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-picture-icon {
            font-size: 80px;
            color: white;
        }
        .profile-picture-icon.cardiology {
            color: white;
        }
        .profile-picture-icon.neurology {
            color: white;
        }
        .profile-picture-icon.pediatrics {
            color: white;
        }
        .profile-picture-icon.orthopedics {
            color: white;
        }
        .profile-picture-icon.dermatology {
            color: white;
        }
        .profile-picture-icon.ophthalmology {
            color: white;
        }
        .profile-picture-icon.ent {
            color: white;
        }
        .profile-picture-icon.emergency {
            color: white;
        }
        .profile-picture-icon.icu {
            color: white;
        }
        .profile-picture-icon.surgery {
            color: white;
        }
        .profile-picture-icon.laboratory {
            color: white;
        }
        .profile-picture-icon.pharmacy {
            color: white;
        }
        .profile-picture-icon.staff {
            color: white;
        }
        .upload-hint {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 1.5rem;
            text-align: center;
        }
        .upload-hint i {
            margin-right: 5px;
            color: #6c757d;
        }
        .profile-picture-container {
            position: relative;
            margin-bottom: 2rem;
            padding-top: 2rem;
        }
        .format-hint {
            font-size: 0.75rem;
            color: #999;
            margin-top: 0.5rem;
            text-align: center;
        }
        .remove-picture-btn {
            color: #dc3545;
            background: none;
            border: none;
            padding: 0;
            font: inherit;
            cursor: pointer;
            margin-top: 1rem;
            text-decoration: underline;
            font-size: 0.875rem;
        }
        .remove-picture-btn:hover {
            color: #bb2d3b;
        }
        #profile_picture_preview {
            cursor: pointer;
            transition: opacity 0.3s;
        }
        #profile_picture_preview:hover {
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left me-2"></i>
            Back to Dashboard
        </a>
        
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header text-center py-4">
                        <div class="profile-picture-container">
                            <label for="profile_picture" class="mb-0" style="cursor: pointer;">
                                <div class="profile-picture">
                                    <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" id="profile_picture_preview">
                                    <?php else: ?>
                                        <?php
                                        $icon_class = 'fa-user-circle';
                                        $dept_class = '';
                                        
                                        // Determine icon based on employee type and department
                                        switch($user['employee_type']) {
                                            case 'doctor':
                                                switch(strtolower($user['department'])) {
                                                    case 'cardiology':
                                                        $icon_class = 'fa-heart';
                                                        $dept_class = 'cardiology';
                                                        break;
                                                    case 'neurology':
                                                        $icon_class = 'fa-brain';
                                                        $dept_class = 'neurology';
                                                        break;
                                                    case 'pediatrics':
                                                        $icon_class = 'fa-child';
                                                        $dept_class = 'pediatrics';
                                                        break;
                                                    case 'orthopedics':
                                                        $icon_class = 'fa-bone';
                                                        $dept_class = 'orthopedics';
                                                        break;
                                                    case 'dermatology':
                                                        $icon_class = 'fa-allergies';
                                                        $dept_class = 'dermatology';
                                                        break;
                                                    case 'ophthalmology':
                                                        $icon_class = 'fa-eye';
                                                        $dept_class = 'ophthalmology';
                                                        break;
                                                    case 'ent':
                                                        $icon_class = 'fa-ear-deaf';
                                                        $dept_class = 'ent';
                                                        break;
                                                    default:
                                                        $icon_class = 'fa-user-md';
                                                        break;
                                                }
                                                break;
                                            
                                            case 'nurse':
                                                switch(strtolower($user['department'])) {
                                                    case 'emergency':
                                                        $icon_class = 'fa-truck-medical';
                                                        $dept_class = 'emergency';
                                                        break;
                                                    case 'icu':
                                                        $icon_class = 'fa-heart-pulse';
                                                        $dept_class = 'icu';
                                                        break;
                                                    case 'surgery':
                                                        $icon_class = 'fa-bed-pulse';
                                                        $dept_class = 'surgery';
                                                        break;
                                                    default:
                                                        $icon_class = 'fa-user-nurse';
                                                        break;
                                                }
                                                break;
                                            
                                            case 'lab_technician':
                                                $icon_class = 'fa-flask-vial';
                                                $dept_class = 'laboratory';
                                                break;
                                            
                                            case 'pharmacist':
                                                $icon_class = 'fa-pills';
                                                $dept_class = 'pharmacy';
                                                break;
                                            
                                            case 'staff':
                                                switch(strtolower($user['department'])) {
                                                    case 'administration':
                                                        $icon_class = 'fa-user-tie';
                                                        break;
                                                    case 'reception':
                                                        $icon_class = 'fa-desktop';
                                                        break;
                                                    case 'medical records':
                                                        $icon_class = 'fa-folder-open';
                                                        break;
                                                    case 'billing':
                                                        $icon_class = 'fa-calculator';
                                                        break;
                                                    case 'maintenance':
                                                        $icon_class = 'fa-screwdriver-wrench';
                                                        break;
                                                    case 'security':
                                                        $icon_class = 'fa-shield-halved';
                                                        break;
                                                    default:
                                                        $icon_class = 'fa-user';
                                                        break;
                                                }
                                                $dept_class = 'staff';
                                                break;
                                        }
                                        ?>
                                        <i class="fas <?php echo $icon_class; ?> profile-picture-icon <?php echo $dept_class; ?>"></i>
                                    <?php endif; ?>
                                    <div class="profile-picture-overlay">
                                        <i class="fas fa-camera"></i>
                                        <span>Change Profile Picture</span>
                                    </div>
                                </div>
                            </label>
                            <div class="upload-hint">
                                <i class="fas fa-info-circle"></i>
                                Click on the profile picture to change it
                            </div>
                            <div class="format-hint">
                                Supported: JPG, PNG, GIF | Max size: 5MB
                            </div>
                            <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="remove_picture">
                                    <button type="submit" class="remove-picture-btn">
                                        <i class="fas fa-trash-alt me-1"></i>Remove Profile Picture
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <h4 class="card-title mb-0 mt-4">Edit Profile</h4>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($success_message): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                            <div class="mb-4 text-center">
                                <input type="file" id="profile_picture" name="profile_picture" class="d-none" accept="image/jpeg,image/png,image/gif">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <div class="row g-2">
                                    <div class="col-12 col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="first_name" placeholder="First Name" value="<?php echo htmlspecialchars($first_name_val); ?>" inputmode="text" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ\s.'-]+$" title="Only letters, spaces, hyphens, apostrophes, and periods are allowed" required>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="middle_name" placeholder="Middle Name (optional)" value="<?php echo htmlspecialchars($middle_name_val); ?>" inputmode="text" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ\s.'-]+$" title="Only letters, spaces, hyphens, apostrophes, and periods are allowed">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-8">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="last_name" placeholder="Last Name" value="<?php echo htmlspecialchars($last_name_val); ?>" inputmode="text" pattern="^[A-Za-zÀ-ÖØ-öø-ÿ\s.'-]+$" title="Only letters, spaces, hyphens, apostrophes, and periods are allowed" required>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user-tag"></i></span>
                                            <input type="text" class="form-control" name="suffix" placeholder="Suffix (e.g., Jr., III)" value="<?php echo htmlspecialchars($suffix_val); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                                <div class="form-text">This is your login username.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Employee Type</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                                    <input type="text" class="form-control" value="<?php echo ucfirst(str_replace('_', ' ', $user['employee_type'])); ?>" disabled>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Department</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-hospital"></i></span>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['department']); ?>" disabled>
                                </div>
                            </div>

                            <hr class="my-4">

                            <h5 class="mb-3">Change Password</h5>
                            <div class="mb-3">
                                <label class="form-label">Current Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" name="current_password">
                                </div>
                                <div class="form-text">Leave blank if you don't want to change your password</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    <input type="password" class="form-control" name="new_password">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    <input type="password" class="form-control" name="confirm_password">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Profile Picture Preview Script -->
    <script>
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('profile_picture_preview');
                    const container = document.querySelector('.profile-picture');
                    
                    // Remove icon if it exists
                    const icon = container.querySelector('.profile-picture-icon');
                    if (icon) {
                        icon.remove();
                    }
                    
                    // Create or update image
                    if (!preview) {
                        const img = document.createElement('img');
                        img.id = 'profile_picture_preview';
                        img.alt = 'Profile Picture';
                        img.src = e.target.result;
                        container.appendChild(img);
                    } else {
                        preview.src = e.target.result;
                    }
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });
    </script>
</body>
</html> 
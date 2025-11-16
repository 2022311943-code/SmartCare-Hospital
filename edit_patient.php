<?php
session_start();
require_once "config/database.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || ($_SESSION['employee_type'] !== 'nurse' && $_SESSION['employee_type'] !== 'doctor')) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error_message = "";
$success_message = "";
$patient = null;

// Get patient ID from URL
if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$patient_id = $_GET['id'];

// Get list of active doctors if user is a nurse
$doctors = [];
if($_SESSION['employee_type'] === 'nurse') {
    $doctor_query = "SELECT id, name FROM users WHERE employee_type = 'doctor' AND status = 'active'";
    $doctor_stmt = $db->prepare($doctor_query);
    $doctor_stmt->execute();
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch patient information
try {
    $query = "SELECT * FROM patients WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":id", $patient_id);
    $stmt->execute();
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        header("Location: dashboard.php");
        exit();
    }

    // Check if the user has permission to edit this patient
    if ($_SESSION['employee_type'] === 'doctor' && $patient['doctor_id'] != $_SESSION['user_id']) {
        header("Location: dashboard.php");
        exit();
    }
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $query = "UPDATE patients SET 
                 name = :name,
                 age = :age,
                 gender = :gender,
                 blood_group = :blood_group,
                 phone = :phone,
                 address = :address";
        
        // Only update doctor_id if the user is a nurse
        if($_SESSION['employee_type'] === 'nurse') {
            $query .= ", doctor_id = :doctor_id";
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(":name", $_POST['name']);
        $stmt->bindParam(":age", $_POST['age']);
        $stmt->bindParam(":gender", $_POST['gender']);
        $stmt->bindParam(":blood_group", $_POST['blood_group']);
        $stmt->bindParam(":phone", $_POST['phone']);
        $stmt->bindParam(":address", $_POST['address']);
        $stmt->bindParam(":id", $patient_id);
        
        if($_SESSION['employee_type'] === 'nurse') {
            $stmt->bindParam(":doctor_id", $_POST['doctor_id']);
        }
        
        if($stmt->execute()) {
            $success_message = "Patient information updated successfully!";
            // Refresh patient data
            $stmt = $db->prepare("SELECT * FROM patients WHERE id = :id");
            $stmt->bindParam(":id", $patient_id);
            $stmt->execute();
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Failed to update patient information. Please try again.";
        }
    } catch(PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Patient - Hospital Management System</title>
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
            padding: 20px;
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
        .form-control:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
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
        .back-link {
            color: #6c757d;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <h2>Edit Patient Information</h2>
        
        <?php if(isset($error_message)): ?>
            <div class="error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if(isset($success_message)): ?>
            <div class="success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if($patient): ?>
        <form method="POST" action="">
            <div class="form-group">
                <label for="name">Patient Name:</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($patient['name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="age">Age:</label>
                <input type="number" id="age" name="age" value="<?php echo htmlspecialchars($patient['age']); ?>" required min="0" max="150">
            </div>
            
            <div class="form-group">
                <label for="gender">Gender:</label>
                <select id="gender" name="gender" required>
                    <option value="">Select Gender</option>
                    <option value="male" <?php echo $patient['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?php echo $patient['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                    <option value="other" <?php echo $patient['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="blood_group">Blood Group:</label>
                <select id="blood_group" name="blood_group">
                    <option value="">Select Blood Group</option>
                    <?php
                    $blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                    foreach($blood_groups as $group) {
                        $selected = ($patient['blood_group'] === $group) ? 'selected' : '';
                        echo "<option value=\"$group\" $selected>$group</option>";
                    }
                    ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number:</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($patient['phone']); ?>">
            </div>
            
            <div class="form-group">
                <label for="address">Address:</label>
                <textarea id="address" name="address"><?php echo htmlspecialchars($patient['address']); ?></textarea>
            </div>
            
            <?php if($_SESSION['employee_type'] === 'nurse'): ?>
            <div class="form-group">
                <label for="doctor_id">Assign Doctor:</label>
                <select id="doctor_id" name="doctor_id" required>
                    <option value="">Select Doctor</option>
                    <?php foreach($doctors as $doctor): ?>
                        <option value="<?php echo $doctor['id']; ?>" <?php echo $patient['doctor_id'] == $doctor['id'] ? 'selected' : ''; ?>>
                            Dr. <?php echo htmlspecialchars($doctor['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <button type="submit" class="btn-submit">Update Patient Information</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html> 
<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || 
    !in_array($_SESSION['employee_type'], ['receptionist', 'medical_staff', 'admin_staff', 'medical_records', 'nurse', 'doctor'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get list of doctors for the dropdown
$doctors_query = "SELECT id, name, department FROM users WHERE employee_type IN ('doctor', 'general_doctor') AND status = 'active'";
$doctors_stmt = $db->prepare($doctors_query);
$doctors_stmt->execute();
$doctors = $doctors_stmt->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['name', 'age', 'gender', 'phone', 'address', 'doctor_id'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("All required fields must be filled out.");
            }
        }

        // Insert new patient
        $query = "INSERT INTO patients (name, age, gender, blood_group, phone, address, doctor_id, added_by, created_at) 
                 VALUES (:name, :age, :gender, :blood_group, :phone, :address, :doctor_id, :added_by, NOW())";
        
        $stmt = $db->prepare($query);
        $enc_name = encrypt_strict($_POST['name']);
        $enc_phone = encrypt_strict($_POST['phone']);
        $enc_address = encrypt_strict($_POST['address']);
        
        $stmt->bindParam(":name", $enc_name);
        $stmt->bindParam(":age", $_POST['age']);
        $stmt->bindParam(":gender", $_POST['gender']);
        $stmt->bindParam(":blood_group", $_POST['blood_group']);
        $stmt->bindParam(":phone", $enc_phone);
        $stmt->bindParam(":address", $enc_address);
        $stmt->bindParam(":doctor_id", $_POST['doctor_id']);
        $stmt->bindParam(":added_by", $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $success_message = "Patient added successfully!";
            // Clear form data after successful submission
            $_POST = array();
        } else {
            throw new Exception("Error adding patient.");
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Set page title and include header
$page_title = 'Add New Patient';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white py-3">
                    <h4 class="mb-0"><i class="fas fa-user-plus me-2"></i>Add New Patient</h4>
                </div>
                <div class="card-body p-4">
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="needs-validation" novalidate>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" 
                                       class="form-control" 
                                       id="name" 
                                       name="name" 
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                                       required>
                                <div class="invalid-feedback">Please enter patient's full name.</div>
                            </div>

                            <div class="col-md-3">
                                <label for="age" class="form-label">Age <span class="text-danger">*</span></label>
                                <input type="number" 
                                       class="form-control" 
                                       id="age" 
                                       name="age" 
                                       min="0" 
                                       max="150"
                                       value="<?php echo isset($_POST['age']) ? htmlspecialchars($_POST['age']) : ''; ?>"
                                       required>
                                <div class="invalid-feedback">Please enter a valid age.</div>
                            </div>

                            <div class="col-md-3">
                                <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                <select class="form-select" id="gender" name="gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <div class="invalid-feedback">Please select gender.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" 
                                       class="form-control" 
                                       id="phone" 
                                       name="phone"
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                       required>
                                <div class="invalid-feedback">Please enter a valid phone number.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="blood_group" class="form-label">Blood Group</label>
                                <select class="form-select" id="blood_group" name="blood_group">
                                    <option value="">Select Blood Group</option>
                                    <?php
                                    $blood_groups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
                                    foreach ($blood_groups as $bg) {
                                        $selected = (isset($_POST['blood_group']) && $_POST['blood_group'] === $bg) ? 'selected' : '';
                                        echo "<option value=\"$bg\" $selected>$bg</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" 
                                          id="address" 
                                          name="address" 
                                          rows="3" 
                                          required><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                                <div class="invalid-feedback">Please enter the address.</div>
                            </div>

                            <div class="col-12">
                                <label for="doctor_id" class="form-label">Assign Doctor <span class="text-danger">*</span></label>
                                <select class="form-select" id="doctor_id" name="doctor_id" required>
                                    <option value="">Select Doctor</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <option value="<?php echo $doctor['id']; ?>" 
                                                <?php echo (isset($_POST['doctor_id']) && $_POST['doctor_id'] == $doctor['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($doctor['name']); ?> 
                                            (<?php echo htmlspecialchars($doctor['department']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a doctor.</div>
                            </div>
                        </div>

                        <div class="mt-4 d-flex justify-content-between">
                            <a href="search_patients.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Search
                            </a>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-save me-2"></i>Save Patient
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation script
(function () {
    'use strict'

    // Fetch all the forms we want to apply custom Bootstrap validation styles to
    var forms = document.querySelectorAll('.needs-validation')

    // Loop over them and prevent submission
    Array.prototype.slice.call(forms)
        .forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault()
                    event.stopPropagation()
                }

                form.classList.add('was-validated')
            }, false)
        })
})()
</script> 
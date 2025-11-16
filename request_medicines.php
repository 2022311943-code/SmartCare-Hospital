<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a doctor
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'doctor') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get all available medicines
$medicines_query = "SELECT id, name, unit, quantity FROM medicines WHERE quantity > 0 ORDER BY name ASC";
$medicines_stmt = $db->prepare($medicines_query);
$medicines_stmt->execute();
$medicines = $medicines_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get doctor's patients
$patients_query = "SELECT * FROM patients WHERE doctor_id = :doctor_id ORDER BY name ASC";
$patients_stmt = $db->prepare($patients_query);
$patients_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
$patients_stmt->execute();
$patients = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $db->beginTransaction();

        // Get the next order number
        $order_number_query = "SELECT COALESCE(MAX(order_number), 0) + 1 as next_order_number FROM medicine_orders";
        $order_number_stmt = $db->prepare($order_number_query);
        $order_number_stmt->execute();
        $order_number = $order_number_stmt->fetch(PDO::FETCH_ASSOC)['next_order_number'];

        // Create the order
        $order_query = "INSERT INTO medicine_orders (order_number, doctor_id, patient_id, notes) VALUES (:order_number, :doctor_id, :patient_id, :notes)";
        $order_stmt = $db->prepare($order_query);
        $order_stmt->bindParam(":order_number", $order_number);
        $order_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
        $order_stmt->bindParam(":patient_id", $_POST['patient_id']);
        $order_stmt->bindParam(":notes", $_POST['notes']);
        $order_stmt->execute();

        $order_id = $db->lastInsertId();

        // Add order items
        foreach ($_POST['medicines'] as $medicine) {
            if (!empty($medicine['medicine_id']) && !empty($medicine['quantity'])) {
                $item_query = "INSERT INTO medicine_order_items (order_id, medicine_id, quantity, instructions) 
                              VALUES (:order_id, :medicine_id, :quantity, :instructions)";
                $item_stmt = $db->prepare($item_query);
                $item_stmt->bindParam(":order_id", $order_id);
                $item_stmt->bindParam(":medicine_id", $medicine['medicine_id']);
                $item_stmt->bindParam(":quantity", $medicine['quantity']);
                $item_stmt->bindParam(":instructions", $medicine['instructions']);
                $item_stmt->execute();
            }
        }

        $db->commit();
        
        // Store the order number in session to show the modal
        $_SESSION['last_order_number'] = $order_number;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } catch(PDOException $e) {
        $db->rollBack();
        header("Location: dashboard.php");
        exit();
    }
}

// Check if we need to show the success modal
$show_success_modal = false;
$last_order_number = null;
if (isset($_SESSION['last_order_number'])) {
    $show_success_modal = true;
    $last_order_number = $_SESSION['last_order_number'];
    unset($_SESSION['last_order_number']); // Clear it so modal won't show on refresh
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Medicines - SmartCare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
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
        .medicine-item {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .medicine-item:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .medicine-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .medicine-details {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .quantity-input {
            max-width: 100px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-hospital me-2"></i>
                Hospital Management System
            </a>
            <div class="d-flex align-items-center">
                <a href="dashboard.php" class="btn btn-outline-light me-2">
                    <i class="fas fa-home me-2"></i>Dashboard
                </a>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-prescription-bottle me-2"></i>Request Medicines</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="medicineRequestForm">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Select Patient</label>
                            <select class="form-select" name="patient_id" required>
                                <option value="">Choose Patient</option>
                                <?php foreach($patients as $patient): ?>
                                    <option value="<?php echo $patient['id']; ?>">
                                        <?php echo htmlspecialchars($patient['name']); ?> 
                                        (<?php echo htmlspecialchars($patient['phone']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="medicinesList">
                        <div class="medicine-item">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label">Medicine</label>
                                    <select class="form-select medicine-select" name="medicines[0][medicine_id]" required onchange="checkStock(this)">
                                        <option value="">Select Medicine</option>
                                        <?php foreach($medicines as $medicine): ?>
                                            <option value="<?php echo $medicine['id']; ?>" data-stock="<?php echo $medicine['quantity']; ?>">
                                                <?php echo htmlspecialchars($medicine['name']); ?> 
                                                (<?php echo htmlspecialchars($medicine['unit']); ?>) - 
                                                Stock: <?php echo $medicine['quantity']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" class="form-control quantity-input" name="medicines[0][quantity]" required min="1" onchange="checkStock(this.parentElement.previousElementSibling.querySelector('select'))">
                                    <div class="invalid-feedback">
                                        Requested quantity exceeds available stock!
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Instructions</label>
                                    <input type="text" class="form-control" name="medicines[0][instructions]" placeholder="e.g., Take twice daily after meals">
                                </div>
                                <div class="col-md-1 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger" onclick="removeMedicine(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <button type="button" class="btn btn-secondary" onclick="addMedicine()">
                            <i class="fas fa-plus me-2"></i>Add Another Medicine
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Additional Notes</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="Any additional instructions or notes"></textarea>
                    </div>

                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-paper-plane me-2"></i>Submit Request
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>
                        Order Submitted Successfully!
                    </h5>
                </div>
                <div class="modal-body text-center py-4">
                    <h3 class="mb-4">Your Order Number is:</h3>
                    <div class="display-4 fw-bold text-success mb-4"><?php echo $last_order_number; ?></div>
                    <p class="text-muted mb-0">Please save this number for future reference.</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <a href="dashboard.php" class="btn btn-danger">
                        <i class="fas fa-home me-2"></i>Return to Dashboard
                    </a>
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                        <i class="fas fa-plus me-2"></i>Make Another Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/session-handler.js"></script>
    
    <script>
        let medicineCount = 1;

        function checkStock(selectElement) {
            const quantityInput = selectElement.closest('.row').querySelector('.quantity-input');
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            
            if (selectedOption.value) {
                const availableStock = parseInt(selectedOption.dataset.stock);
                const requestedQuantity = parseInt(quantityInput.value) || 0;
                
                if (requestedQuantity > availableStock) {
                    quantityInput.classList.add('is-invalid');
                    quantityInput.value = availableStock;
                } else {
                    quantityInput.classList.remove('is-invalid');
                }
                
                // Update max attribute
                quantityInput.setAttribute('max', availableStock);
            }
        }

        function addMedicine() {
            const medicinesList = document.getElementById('medicinesList');
            const medicineItem = document.querySelector('.medicine-item').cloneNode(true);
            
            // Update input names
            const inputs = medicineItem.querySelectorAll('select, input');
            inputs.forEach(input => {
                input.name = input.name.replace('[0]', `[${medicineCount}]`);
                if (input.type !== 'button') {
                    input.value = '';
                }
                if (input.classList.contains('medicine-select')) {
                    input.onchange = function() { checkStock(this); };
                }
                if (input.classList.contains('quantity-input')) {
                    input.onchange = function() { checkStock(this.parentElement.previousElementSibling.querySelector('select')); };
                }
            });

            medicinesList.appendChild(medicineItem);
            medicineCount++;
        }

        function removeMedicine(button) {
            const medicinesList = document.getElementById('medicinesList');
            if (medicinesList.children.length > 1) {
                button.closest('.medicine-item').remove();
            }
        }

        // Show success modal if needed
        <?php if($show_success_modal): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new bootstrap.Modal(document.getElementById('successModal')).show();
        });
        <?php endif; ?>
    </script>
</body>
</html> 
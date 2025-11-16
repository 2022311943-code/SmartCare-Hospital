<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a pharmacist
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'pharmacist') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Handle form submission for adding/updating medicine
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'add') {
                $category = $_POST['category'] === 'Others' ? trim($_POST['other_category']) : $_POST['category'];
            // Add new medicine
                $query = "INSERT INTO medicines (name, generic_name, category, description, unit, quantity, 
                         unit_price, reorder_level, low_stock_threshold, near_expiry_days, expiration_date, 
                         manufacturer, added_by) 
                         VALUES (:name, :generic_name, :category, :description, :unit, :quantity, 
                         :unit_price, :reorder_level, :low_stock_threshold, :near_expiry_days, :expiration_date, 
                         :manufacturer, :added_by)";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(":name", $_POST['name']);
            $stmt->bindParam(":generic_name", $_POST['generic_name']);
                $stmt->bindParam(":category", $category);
            $stmt->bindParam(":description", $_POST['description']);
            $stmt->bindParam(":unit", $_POST['unit']);
            $stmt->bindParam(":quantity", $_POST['quantity']);
            $stmt->bindParam(":unit_price", $_POST['unit_price']);
            $stmt->bindParam(":reorder_level", $_POST['reorder_level']);
                $stmt->bindParam(":low_stock_threshold", $_POST['low_stock_threshold']);
                $stmt->bindParam(":near_expiry_days", $_POST['near_expiry_days']);
                $stmt->bindParam(":expiration_date", $_POST['expiration_date']);
            $stmt->bindParam(":manufacturer", $_POST['manufacturer']);
            $stmt->bindParam(":added_by", $_SESSION['user_id']);
            
            if($stmt->execute()) {
                header("Location: manage_medicines.php");
                exit();
            }
            } else if ($_POST['action'] === 'delete' && isset($_POST['medicine_id'])) {
                // Delete medicine
                $query = "DELETE FROM medicines WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":id", $_POST['medicine_id']);
            
            if($stmt->execute()) {
                    $_SESSION['success_message'] = "Medicine deleted successfully";
            } else {
                    $_SESSION['error_message'] = "Failed to delete medicine";
                }
                header("Location: manage_medicines.php");
                exit();
            } else if ($_POST['action'] === 'update' && isset($_POST['medicine_id'])) {
                // Update medicine
                $category = $_POST['category'] === 'Others' ? trim($_POST['other_category']) : $_POST['category'];
                $update = "UPDATE medicines SET 
                            name = :name,
                            generic_name = :generic_name,
                            category = :category,
                            description = :description,
                            unit = :unit,
                            quantity = :quantity,
                            unit_price = :unit_price,
                            reorder_level = :reorder_level,
                            low_stock_threshold = :low_stock_threshold,
                            near_expiry_days = :near_expiry_days,
                            expiration_date = :expiration_date,
                            manufacturer = :manufacturer
                          WHERE id = :id";
                $stmt = $db->prepare($update);
                $stmt->bindParam(':name', $_POST['name']);
                $stmt->bindParam(':generic_name', $_POST['generic_name']);
                $stmt->bindParam(':category', $category);
                $stmt->bindParam(':description', $_POST['description']);
                $stmt->bindParam(':unit', $_POST['unit']);
                $stmt->bindParam(':quantity', $_POST['quantity']);
                $stmt->bindParam(':unit_price', $_POST['unit_price']);
                $stmt->bindParam(':reorder_level', $_POST['reorder_level']);
                $stmt->bindParam(':low_stock_threshold', $_POST['low_stock_threshold']);
                $stmt->bindParam(':near_expiry_days', $_POST['near_expiry_days']);
                $stmt->bindParam(':expiration_date', $_POST['expiration_date']);
                $stmt->bindParam(':manufacturer', $_POST['manufacturer']);
                $stmt->bindParam(':id', $_POST['medicine_id']);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = 'Medicine updated successfully';
                } else {
                    $_SESSION['error_message'] = 'Failed to update medicine';
                }
                header('Location: manage_medicines.php');
                exit();
            }
        }
    } catch(PDOException $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
        header("Location: manage_medicines.php");
        exit();
    }
}

// AJAX: fetch medicine details by ID for modals
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['action']) && $_GET['action'] === 'get_medicine' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $stmt = $db->prepare("SELECT * FROM medicines WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => (bool)$row, 'data' => $row]);
    exit();
}

// Get all medicines with status flags
$query = "SELECT *, 
          CASE 
              WHEN quantity <= low_stock_threshold THEN 'low_stock'
              WHEN expiration_date <= CURDATE() THEN 'expired'
              WHEN expiration_date <= DATE_ADD(CURDATE(), INTERVAL near_expiry_days DAY) THEN 'near_expiry'
              ELSE 'normal'
          END as stock_status
          FROM medicines";

// Add search functionality
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $query .= " WHERE name LIKE :search 
                OR generic_name LIKE :search 
                OR category LIKE :search";
}

$query .= " ORDER BY name ASC";
$stmt = $db->prepare($query);

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $stmt->bindParam(":search", $search);
}

$stmt->execute();
$medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get low stock items
$low_stock_query = "SELECT * FROM medicines WHERE quantity <= low_stock_threshold ORDER BY quantity ASC";
$low_stock_stmt = $db->prepare($low_stock_query);
$low_stock_stmt->execute();
$low_stock_items = $low_stock_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get expired items
$expired_query = "SELECT * FROM medicines WHERE expiration_date <= CURDATE() ORDER BY expiration_date ASC";
$expired_stmt = $db->prepare($expired_query);
$expired_stmt->execute();
$expired_items = $expired_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get near expiry items
$near_expiry_query = "SELECT * FROM medicines 
                     WHERE expiration_date > CURDATE() 
                     AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL near_expiry_days DAY)
                     ORDER BY expiration_date ASC";
$near_expiry_stmt = $db->prepare($near_expiry_query);
$near_expiry_stmt->execute();
$near_expiry_items = $near_expiry_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total inventory value
$total_value_query = "SELECT SUM(quantity * unit_price) as total_value FROM medicines";
$total_value_stmt = $db->prepare($total_value_query);
$total_value_stmt->execute();
$total_value = $total_value_stmt->fetch(PDO::FETCH_ASSOC)['total_value'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Medicines - Hospital Management System</title>
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
        .table th {
            font-weight: 600;
            color: #2c3e50;
        }
        .btn-add {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            border: none;
            color: white;
            padding: 8px 20px;
            border-radius: 8px;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        .status-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        /* Status colors mapping:
           expired -> red, low_stock -> yellow, near_expiry -> orange, normal -> green */
        .status-expired,
        .status-expired { /* alias for safety */
            background-color: #f8d7da; /* red tint */
            color: #842029;
            border: 1px solid #f5c2c7;
        }
        /* Low stock (orange). Support both dash and underscore variants */
        .status-low-stock,
        .status-low_stock {
            background-color: #ffd8a8; /* stronger orange for visibility */
            color: #7a3e06;
            border: 1px solid #ffc078;
        }
        /* Near expiry (orange family). Support both dash and underscore variants */
        .status-near-expiry,
        .status-near_expiry {
            background-color: #ffe5d0; /* orange tint */
            color: #7a3e06;
            border: 1px solid #ffd3b0;
        }
        .status-normal {
            background-color: #d1e7dd; /* green tint */
            color: #0f5132;
            border: 1px solid #badbcc;
        }
        /* Add tooltip styles */
        .status-badge[data-bs-toggle="tooltip"] {
            cursor: help;
        }
        .search-form {
            min-width: 300px;
        }
        .search-form .form-control {
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 8px 15px;
        }
        .search-form .form-control:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        .gap-2 {
            gap: 0.5rem !important;
        }
        .search-form .btn {
            padding: 8px 15px;
            border-radius: 8px;
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
        <!-- Stats Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Total Medicines</h6>
                        <h3><?php echo count($medicines); ?></h3>
                        <i class="fas fa-pills text-danger"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Low Stock Items</h6>
                        <h3 class="text-warning"><?php echo count($low_stock_items); ?></h3>
                        <i class="fas fa-exclamation-triangle text-warning"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Near Expiry</h6>
                        <h3 class="text-warning"><?php echo count($near_expiry_items); ?></h3>
                        <i class="fas fa-clock text-warning"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted">Expired Items</h6>
                        <h3 class="text-danger"><?php echo count($expired_items); ?></h3>
                        <i class="fas fa-times-circle text-danger"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Medicine Button and Search -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <button type="button" class="btn btn-add" data-bs-toggle="modal" data-bs-target="#addMedicineModal">
                <i class="fas fa-plus me-2"></i>Add New Medicine
            </button>
        </div>

        <!-- Medicines Table -->
        <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-pills me-2"></i>Medicine Inventory
                    </h5>
                    <div class="input-group ms-3" style="width: 300px;">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" class="form-control" id="medicineSearch" placeholder="Search medicines...">
                        <button class="btn btn-outline-secondary" type="button" id="clearMedicineSearch" style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Generic Name</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Expiration Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($medicines as $medicine): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($medicine['name']); ?></td>
                                    <td><?php echo htmlspecialchars($medicine['generic_name']); ?></td>
                                    <td><?php echo htmlspecialchars($medicine['category']); ?></td>
                                    <td><?php 
                                        echo htmlspecialchars($medicine['quantity']);
                                        $unit = trim($medicine['unit']);
                                        if (!empty($unit)) {
                                            echo ' ' . htmlspecialchars($unit);
                                        }
                                    ?></td>
                                    <td>₱<?php echo number_format($medicine['unit_price'], 2); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($medicine['expiration_date'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $medicine['stock_status']; ?>" 
                                              data-bs-toggle="tooltip" 
                                              data-bs-placement="top"
                                              title="<?php
                                                switch($medicine['stock_status']) {
                                                    case 'low_stock':
                                                        echo 'Stock is below the low stock threshold';
                                                        break;
                                                    case 'expired':
                                                        echo 'Medicine has expired';
                                                        break;
                                                    case 'near_expiry':
                                                        echo 'Medicine is approaching expiration date';
                                                        break;
                                                    default:
                                                        echo 'Stock level is normal';
                                                }
                                              ?>">
                                        <?php 
                                            switch($medicine['stock_status']) {
                                                case 'low_stock':
                                                    echo '<i class="fas fa-exclamation-triangle me-1"></i>Low Stock';
                                                    break;
                                                case 'expired':
                                                    echo '<i class="fas fa-times-circle me-1"></i>Expired';
                                                    break;
                                                case 'near_expiry':
                                                    echo '<i class="fas fa-clock me-1"></i>Near Expiry';
                                                    break;
                                                default:
                                                    echo '<i class="fas fa-check-circle me-1"></i>Normal';
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="editMedicine(<?php echo $medicine['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $medicine['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $medicine['id']; ?>, '<?php echo htmlspecialchars($medicine['name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Medicine Modal -->
    <div class="modal fade" id="addMedicineModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Medicine</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Medicine Name</label>
                                <input type="text" name="name" class="form-control" required 
                                       placeholder="Enter commercial/brand name (e.g., Biogesic)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Generic Name</label>
                                <input type="text" name="generic_name" class="form-control" 
                                       placeholder="Enter generic/scientific name (e.g., Paracetamol)">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Category</label>
                                <select name="category" id="categorySelect" class="form-control" required onchange="toggleOtherCategory()">
                                    <option value="" disabled selected>Select medicine category</option>
                                    <option value="Tablet">Tablet</option>
                                    <option value="Capsule">Capsule</option>
                                    <option value="Syrup">Syrup</option>
                                    <option value="Ointment">Ointment</option>
                                    <option value="Injection">Injection</option>
                                    <option value="Others">Others</option>
                                </select>
                                <input type="text" name="other_category" id="otherCategoryInput" class="form-control mt-2" 
                                       placeholder="Specify custom category" style="display:none;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Manufacturer</label>
                                <input type="text" name="manufacturer" class="form-control" 
                                       placeholder="Enter manufacturer/company name">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Unit</label>
                                <input type="text" name="unit" class="form-control" required 
                                       placeholder="e.g., tablets, ml, capsules, vials">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity" class="form-control" required min="0" 
                                       placeholder="Enter initial stock quantity">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Unit Price</label>
                                <input type="number" name="unit_price" class="form-control" required min="0" step="0.01" 
                                       placeholder="Enter price per unit (₱)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Expiration Date</label>
                                <input type="date" name="expiration_date" class="form-control" required 
                                       placeholder="Select expiration date">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" class="form-control" required min="0" 
                                       placeholder="Minimum stock to trigger reorder">
                                <small class="text-muted">Stock level to place new order</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Low Stock Threshold</label>
                                <input type="number" name="low_stock_threshold" class="form-control" required min="0" 
                                       placeholder="Warning level for low stock">
                                <small class="text-muted">Trigger warning when stock is low</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Near Expiry Days</label>
                                <input type="number" name="near_expiry_days" class="form-control" required min="0" 
                                       placeholder="Days before expiry warning">
                                <small class="text-muted">Days before expiry to show warning</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" 
                                    placeholder="Enter additional details about the medicine (e.g., usage, dosage, precautions)"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Add Medicine</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewDetailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Medicine Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="detailsBody" class="small">
                        <div class="text-muted">Loading...</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Medicine Modal (reuses Add form layout) -->
    <div class="modal fade" id="editMedicineModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Medicine</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="" id="editMedicineForm">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="medicine_id" id="edit_medicine_id">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Medicine Name</label>
                                <input type="text" name="name" id="edit_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Generic Name</label>
                                <input type="text" name="generic_name" id="edit_generic_name" class="form-control">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Category</label>
                                <input type="text" name="category" id="edit_category" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Manufacturer</label>
                                <input type="text" name="manufacturer" id="edit_manufacturer" class="form-control">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Unit</label>
                                <input type="text" name="unit" id="edit_unit" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity" id="edit_quantity" class="form-control" required min="0">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Unit Price</label>
                                <input type="number" name="unit_price" id="edit_unit_price" class="form-control" required min="0" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Expiration Date</label>
                                <input type="date" name="expiration_date" id="edit_expiration_date" class="form-control" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" id="edit_reorder_level" class="form-control" required min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Low Stock Threshold</label>
                                <input type="number" name="low_stock_threshold" id="edit_low_stock_threshold" class="form-control" required min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Near Expiry Days</label>
                                <input type="number" name="near_expiry_days" id="edit_near_expiry_days" class="form-control" required min="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <span id="medicineName" class="fw-bold"></span>?</p>
                    <p class="text-danger">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="medicine_id" id="deleteMedicineId">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Alert Messages -->
    <?php if(isset($_SESSION['success_message'])): ?>
        <div class="position-fixed top-0 end-0 p-3" style="z-index: 1050">
            <div class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header bg-success text-white">
                    <strong class="me-auto">Success</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">
                    <?php 
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                    ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['error_message'])): ?>
        <div class="position-fixed top-0 end-0 p-3" style="z-index: 1050">
            <div class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header bg-danger text-white">
                    <strong class="me-auto">Error</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">
                    <?php 
                    echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']);
                    ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editMedicine(id) {
            fetch('manage_medicines.php?ajax=1&action=get_medicine&id=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.data) { alert('Failed to load medicine'); return; }
                    const m = data.data;
                    document.getElementById('edit_medicine_id').value = m.id;
                    document.getElementById('edit_name').value = m.name || '';
                    document.getElementById('edit_generic_name').value = m.generic_name || '';
                    document.getElementById('edit_category').value = m.category || '';
                    document.getElementById('edit_manufacturer').value = m.manufacturer || '';
                    document.getElementById('edit_unit').value = m.unit || '';
                    document.getElementById('edit_quantity').value = m.quantity || 0;
                    document.getElementById('edit_unit_price').value = m.unit_price || 0;
                    document.getElementById('edit_expiration_date').value = (m.expiration_date || '').substring(0,10);
                    document.getElementById('edit_reorder_level').value = m.reorder_level || 0;
                    document.getElementById('edit_low_stock_threshold').value = m.low_stock_threshold || 0;
                    document.getElementById('edit_near_expiry_days').value = m.near_expiry_days || 0;
                    document.getElementById('edit_description').value = m.description || '';
                    new bootstrap.Modal(document.getElementById('editMedicineModal')).show();
                })
                .catch(() => alert('Network error'));
        }

        function viewDetails(id) {
            const body = document.getElementById('detailsBody');
            body.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-danger"></div></div>';
            fetch('manage_medicines.php?ajax=1&action=get_medicine&id=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.data) { body.innerHTML = '<div class="text-danger">Failed to load details</div>'; return; }
                    const m = data.data;
                    body.innerHTML = `
                        <div class="row mb-2">
                            <div class="col-6"><strong>Name</strong></div><div class="col-6">${escapeHtml(m.name)}</div>
                            <div class="col-6"><strong>Generic</strong></div><div class="col-6">${escapeHtml(m.generic_name||'-')}</div>
                            <div class="col-6"><strong>Category</strong></div><div class="col-6">${escapeHtml(m.category||'-')}</div>
                            <div class="col-6"><strong>Manufacturer</strong></div><div class="col-6">${escapeHtml(m.manufacturer||'-')}</div>
                            <div class="col-6"><strong>Unit</strong></div><div class="col-6">${escapeHtml(m.unit)}</div>
                            <div class="col-6"><strong>Quantity</strong></div><div class="col-6">${Number(m.quantity)} ${escapeHtml(m.unit||'')}</div>
                            <div class="col-6"><strong>Unit Price</strong></div><div class="col-6">₱${Number(m.unit_price).toFixed(2)}</div>
                            <div class="col-6"><strong>Expiration</strong></div><div class="col-6">${(m.expiration_date||'').substring(0,10)}</div>
                            <div class="col-6"><strong>Reorder Level</strong></div><div class="col-6">${Number(m.reorder_level)}</div>
                            <div class="col-6"><strong>Low Stock Threshold</strong></div><div class="col-6">${Number(m.low_stock_threshold)}</div>
                            <div class="col-6"><strong>Near Expiry Days</strong></div><div class="col-6">${Number(m.near_expiry_days)}</div>
                        </div>
                        <div><strong>Description</strong><div class="mt-1">${escapeHtml(m.description||'-')}</div></div>
                    `;
                })
                .catch(() => { body.innerHTML = '<div class="text-danger">Network error</div>'; });
            new bootstrap.Modal(document.getElementById('viewDetailsModal')).show();
        }

        function escapeHtml(str){
            return (str||'').toString()
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(/\"/g,'&quot;').replace(/'/g,'&#039;');
        }

        function confirmDelete(id, name) {
            document.getElementById('medicineName').textContent = name;
            document.getElementById('deleteMedicineId').value = id;
            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        }

        function toggleOtherCategory() {
            var select = document.getElementById('categorySelect');
            var otherInput = document.getElementById('otherCategoryInput');
            if (select.value === 'Others') {
                otherInput.style.display = 'block';
                otherInput.required = true;
            } else {
                otherInput.style.display = 'none';
                otherInput.required = false;
            }
        }

        // Auto-hide toasts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var toasts = document.querySelectorAll('.toast');
                toasts.forEach(function(toast) {
                    var bsToast = new bootstrap.Toast(toast);
                    bsToast.hide();
                });
            }, 5000);
        });

        document.addEventListener('DOMContentLoaded', function() {
            const medicineSearch = document.getElementById('medicineSearch');
            const clearMedicineSearch = document.getElementById('clearMedicineSearch');
            const medicinesTableBody = document.querySelector('.table tbody');
            let originalRows = null;

            // Store original table content
            if (medicinesTableBody) {
                originalRows = medicinesTableBody.innerHTML;
            }

            function performSearch() {
                const searchTerm = medicineSearch.value.toLowerCase().trim();
                clearMedicineSearch.style.display = searchTerm ? 'block' : 'none';

                if (!searchTerm) {
                    // Restore original content if search is empty
                    medicinesTableBody.innerHTML = originalRows;
                    return;
                }

                const rows = medicinesTableBody.querySelectorAll('tr');
                let visibleCount = 0;

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Show or hide "no results" message
                let noResultsMsg = document.querySelector('.no-results-message');
                if (visibleCount === 0) {
                    if (!noResultsMsg) {
                        noResultsMsg = document.createElement('tr');
                        noResultsMsg.className = 'no-results-message';
                        noResultsMsg.innerHTML = `
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="fas fa-search me-2"></i>No medicines found matching your search.
                            </td>
                        `;
                        medicinesTableBody.appendChild(noResultsMsg);
                    }
                } else if (noResultsMsg) {
                    noResultsMsg.remove();
                }
            }

            // Search input event listener
            if (medicineSearch) {
                medicineSearch.addEventListener('input', performSearch);
            }

            // Clear search button event listener
            if (clearMedicineSearch) {
                clearMedicineSearch.addEventListener('click', () => {
                    medicineSearch.value = '';
                    performSearch();
                });
            }
        });
    </script>
</body>
</html> 
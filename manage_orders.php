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

$success_message = "";
$error_message = "";

// Handle order status updates
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    try {
        $db->beginTransaction();

		// If status is being changed to completed, reduce medicine stock
		if ($_POST['status'] === 'completed') {
            // Get order items with prices
            $items_query = "SELECT moi.medicine_id, moi.quantity, m.quantity as current_stock, m.unit_price 
                          FROM medicine_order_items moi
                          JOIN medicines m ON moi.medicine_id = m.id
                          WHERE moi.order_id = :order_id";
            $items_stmt = $db->prepare($items_query);
            $items_stmt->bindParam(":order_id", $_POST['order_id']);
            $items_stmt->execute();
            $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

			// Calculate total amount and update stock
			$total_amount = 0;
            foreach ($order_items as $item) {
                if ($item['current_stock'] >= $item['quantity']) {
                    // Calculate item total
                    $total_amount += $item['quantity'] * $item['unit_price'];
                    
                    // Update stock
                    $update_stock_query = "UPDATE medicines 
                                         SET quantity = quantity - :quantity 
                                         WHERE id = :medicine_id";
                    $update_stock_stmt = $db->prepare($update_stock_query);
                    $update_stock_stmt->bindParam(":quantity", $item['quantity']);
                    $update_stock_stmt->bindParam(":medicine_id", $item['medicine_id']);
                    $update_stock_stmt->execute();
                } else {
                    throw new Exception("Insufficient stock for one or more medicines");
                }
            }

			// Override with final total from O.R. modal if provided (allows discounts/adjustments)
			if (isset($_POST['final_total']) && is_numeric($_POST['final_total'])) {
				$final_total = (float)$_POST['final_total'];
				if ($final_total >= 0) { $total_amount = $final_total; }
			}

			// Update order with total amount
			$update_total_query = "UPDATE medicine_orders 
							 SET total_amount = :total_amount 
							 WHERE id = :order_id";
			$update_total_stmt = $db->prepare($update_total_query);
			$update_total_stmt->bindParam(":total_amount", $total_amount);
			$update_total_stmt->bindParam(":order_id", $_POST['order_id']);
			$update_total_stmt->execute();
        }

        // Update order status
        $update_query = "UPDATE medicine_orders SET status = :status WHERE id = :order_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(":status", $_POST['status']);
        $update_stmt->bindParam(":order_id", $_POST['order_id']);
        $update_stmt->execute();

        $db->commit();
        header("Location: manage_orders.php?order_number=" . $_GET['order_number']);
        exit();
    } catch(Exception $e) {
        $db->rollBack();
        header("Location: manage_orders.php?order_number=" . $_GET['order_number']);
        exit();
    }
}

// Get order details if order number is provided
$order = null;
$order_items = [];
if (isset($_GET['order_number'])) {
    try {
        // Get order details
        $order_query = "SELECT mo.*, 
                              u.name as doctor_name, 
                              p.name as patient_name,
                              p.phone as patient_phone
                       FROM medicine_orders mo
                       JOIN users u ON mo.doctor_id = u.id
                       JOIN patients p ON mo.patient_id = p.id
                       WHERE mo.order_number = :order_number";
        $order_stmt = $db->prepare($order_query);
        $order_stmt->bindParam(":order_number", $_GET['order_number']);
        $order_stmt->execute();
        $order = $order_stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Get order items with prices
            $items_query = "SELECT moi.*, m.name as medicine_name, m.unit, m.unit_price
                          FROM medicine_order_items moi
                          JOIN medicines m ON moi.medicine_id = m.id
                          WHERE moi.order_id = :order_id";
            $items_stmt = $db->prepare($items_query);
            $items_stmt->bindParam(":order_id", $order['id']);
            $items_stmt->execute();
            $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(PDOException $e) {
        $error_message = "Error fetching order details: " . $e->getMessage();
    }
}

// Get recent orders
$recent_orders_query = "SELECT mo.*, 
                              u.name as doctor_name, 
                              p.name as patient_name
                       FROM medicine_orders mo
                       JOIN users u ON mo.doctor_id = u.id
                       JOIN patients p ON mo.patient_id = p.id
                       ORDER BY mo.created_at DESC 
                       LIMIT 10";
$recent_orders_stmt = $db->prepare($recent_orders_query);
$recent_orders_stmt->execute();
$recent_orders = $recent_orders_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - SmartCare</title>
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
        /* Search Input Group Styling */
        .search-input-group {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .search-input-group .form-control {
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
            border: 1px solid #dee2e6;
            border-right: none;
        }
        .search-input-group .form-control:focus {
            box-shadow: none;
            border-color: #dc3545;
        }
        .search-input-group .btn-primary {
            border-radius: 0;
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }
        .search-input-group .btn-secondary {
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        .search-input-group .btn-danger {
            border-radius: 0;
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }
        .status-badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-processing { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .or-af51{width:600px;background:#fff;border:1px solid #999;padding:12px;font-family:Arial,Helvetica,sans-serif;color:#000}
        .or-af51 .af51-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:12px}
        .or-af51 .af51-topboxes{display:flex;gap:8px;margin-bottom:6px}
        .or-af51 .af51-topboxes .box{border:1px solid #999;height:38px}
        .or-af51 .af51-topboxes .large{flex:1}
        .or-af51 .af51-topboxes .barcode{width:220px}
        .or-af51 .af51-row{display:flex;gap:8px;margin-bottom:6px}
        .or-af51 .af51-field{border:1px solid #999;padding:6px;flex:1;font-size:13px;min-height:34px}
        .or-af51 table{width:100%;border-collapse:collapse;font-size:13px}
        .or-af51 th,.or-af51 td{border:1px solid #999;padding:4px 6px}
        .or-af51 tfoot td{border-top:2px solid #000}
        .or-af51 .checkline{display:flex;gap:16px;align-items:center;margin-top:8px;font-size:13px}
        .or-af51 .cb{display:inline-block;width:13px;height:13px;border:1px solid #000;text-align:center;line-height:13px;font-size:12px}
        .or-af51 .af51-ack{display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:12px}
        .or-af51 .af51-note{font-size:11px;color:#555;margin-top:6px}
        .or-af-service-table{width:100%;border-collapse:collapse;font-size:13px}
        .or-af-service-table th,.or-af-service-table td{border:1px solid #000;padding:4px 6px}
        .or-af-service-table tfoot td{border-top:2px solid #000}
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-hospital me-2"></i>
                SmartCare
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
        <!-- Search Form -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-search me-2"></i>Search Order</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <div class="input-group search-input-group">
                                <input type="number" class="form-control" id="orderSearchInput" placeholder="Enter Order Number" value="<?php echo isset($_GET['order_number']) ? htmlspecialchars($_GET['order_number']) : ''; ?>">
                                <button class="btn btn-danger" type="button" id="searchButton">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button type="button" class="btn btn-secondary" id="clearOrderSearch" style="display: none;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Details -->
        <?php if ($order): ?>
        <div class="card mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-file-invoice me-2"></i>
                    Order #<?php echo htmlspecialchars($order['order_number']); ?>
                </h5>
                <span class="status-badge status-<?php echo strtolower($order['status']); ?>">
                    <?php echo ucfirst($order['status']); ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3">Order Information</h6>
                        <p><strong>Doctor:</strong> <?php echo htmlspecialchars($order['doctor_name']); ?></p>
                        <p><strong>Patient:</strong> <?php echo htmlspecialchars($order['patient_name']); ?></p>
                        <p><strong>Patient Phone:</strong> <?php echo htmlspecialchars($order['patient_phone']); ?></p>
                        <p><strong>Order Date:</strong> <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></p>
                        <?php if ($order['status'] === 'completed'): ?>
                            <p><strong>Total Amount:</strong> ₱<?php echo number_format($order['total_amount'], 2); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3">Order Status</h6>
                        <?php if ($order['status'] !== 'completed' && $order['status'] !== 'cancelled'): ?>
                            <div class="mb-3">
                                <?php if ($order['status'] !== 'pending'): ?>
                                    <form method="POST" action="" class="d-inline-block me-2">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <input type="hidden" name="status" value="pending">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-clock me-1"></i>Mark as Pending
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] !== 'processing'): ?>
                                    <form method="POST" action="" class="d-inline-block me-2">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <input type="hidden" name="status" value="processing">
                                        <button type="submit" class="btn btn-info">
                                            <i class="fas fa-spinner me-1"></i>Mark as Processing
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <button type="button" class="btn btn-success d-inline-block me-2" data-bs-toggle="modal" data-bs-target="#pharmOrModal<?php echo $order['id']; ?>">
                                    <i class="fas fa-check me-1"></i>Complete Order
                                </button>
                                
                                <form method="POST" action="" class="d-inline-block">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-times me-1"></i>Cancel Order
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($order['notes'])): ?>
                            <div class="mt-3">
                                <h6 class="text-muted">Notes:</h6>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <h6 class="text-muted mb-3">Ordered Medicines</h6>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Quantity</th>
                                <th>Unit</th>
                                <th>Instructions</th>
                                <?php if ($order['status'] === 'completed' || $order['status'] === 'processing'): ?>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grand_total = 0;
                            foreach($order_items as $item): 
                                $item_total = $item['quantity'] * $item['unit_price'];
                                $grand_total += $item_total;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['medicine_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                    <td><?php echo nl2br(htmlspecialchars($item['instructions'])); ?></td>
                                    <?php if ($order['status'] === 'completed' || $order['status'] === 'processing'): ?>
                                        <td>₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                        <td>₱<?php echo number_format($item_total, 2); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($order['status'] === 'completed' || $order['status'] === 'processing'): ?>
                                <tr>
                                    <td colspan="4"></td>
                                    <td><strong>Total Amount:</strong></td>
                                    <td><strong>₱<?php echo number_format($grand_total, 2); ?></strong></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Pharmacy OR Modal -->
        <div class="modal fade" id="pharmOrModal<?php echo $order['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-md">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Official Receipt (O.R.)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" class="pharm-or-form" data-order-id="<?php echo $order['id']; ?>">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <input type="hidden" name="status" value="completed">
                            <input type="hidden" name="final_total" class="pharm-or-total-hidden" value="0.00">
                            <div class="mb-3">
                                <label class="form-label">O.R. Number</label>
                                <input type="text" class="form-control" value="<?php echo intval($order['id']); ?>" readonly required>
                            </div>
                            <div class="or-capture border rounded p-3">
                                <div class="d-flex justify-content-between small text-muted mb-2">
                                    <span>Date: <?php echo date('M d, Y h:i A'); ?></span>
                                    <span>Cashier: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                                </div>
                                <div class="mb-1"><strong>Payor:</strong> <?php echo htmlspecialchars($order['patient_name']); ?></div>
                                <div class="mb-1"><strong>Order:</strong> Pharmacy Medicines</div>

                                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                                    <h6 class="mb-0">Items</h6>
                                    <button type="button" class="btn btn-sm btn-outline-secondary pharm-or-add-item"><i class="fas fa-plus me-1"></i>Add Item</button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-2">
                                        <thead>
                                            <tr>
                                                <th style="width:70%">Description</th>
                                                <th class="text-end" style="width:25%">Amount (₱)</th>
                                                <th style="width:5%"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="pharm-or-items">
                                            <?php foreach($order_items as $it): $it_total = $it['quantity'] * $it['unit_price']; ?>
                                            <tr>
                                                <td><input type="text" class="form-control form-control-sm pharm-or-desc" value="<?php echo htmlspecialchars($it['medicine_name'] . ' x' . $it['quantity']); ?>" /></td>
                                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end pharm-or-amt" value="<?php echo number_format((float)$it_total,2,'.',''); ?>" /></td>
                                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger pharm-or-remove">&times;</button></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mb-2">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label small mb-0">Discounts</label>
                                        <button type="button" class="btn btn-sm btn-outline-secondary pharm-or-add-discount"><i class="fas fa-plus"></i></button>
                                    </div>
                                    <table class="table table-sm mb-1">
                                        <thead>
                                            <tr><th style="width:70%">Label</th><th style="width:25%" class="text-end">%</th><th style="width:5%"></th></tr>
                                        </thead>
                                        <tbody class="pharm-or-discounts"></tbody>
                                    </table>
                                </div>

                                <table class="table table-sm mb-2">
                                    <tbody>
                                        <tr>
                                            <td style="width:70%">Subtotal</td>
                                            <td class="text-end pe-0" style="width:25%"><input type="text" class="form-control form-control-sm text-end pharm-or-subtotal" value="0.00" disabled></td>
                                            <td style="width:5%"></td>
                                        </tr>
                                        <tr>
                                            <td>Discount Amt</td>
                                            <td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end pharm-or-disc-amt" value="0.00" disabled></td>
                                            <td></td>
                                        </tr>
                                        <tr class="table-active">
                                            <td class="fw-bold">TOTAL</td>
                                            <td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end pharm-or-total fw-bold" value="0.00" disabled></td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div class="row g-2 align-items-end">
                                    <div class="col-6">
                                        <label class="form-label">Cash Tendered</label>
                                        <input type="number" step="0.01" min="0" class="form-control pharm-or-cash" placeholder="0.00">
                                    </div>
                                    <div class="col-6 text-end">
                                        <div class="small text-muted">Change</div>
                                        <div class="fs-6 fw-semibold">₱<span class="pharm-or-change">0.00</span></div>
                                    </div>
                                </div>
                            </div>
                            <hr class="my-3">
                            <div class="or-capture-print border rounded p-3 bg-white">
                                <div class="d-flex justify-content-between small text-muted mb-2">
                                    <span>Date: <?php echo date('M d, Y h:i A'); ?></span>
                                    <span>Cashier: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                                </div>
                                <div class="mb-1"><strong>Receipt No.:</strong> <?php echo intval($order['id']); ?> <span class="text-muted">(System-generated)</span></div>
                                <div class="mb-1"><strong>Payor:</strong> <?php echo htmlspecialchars($order['patient_name']); ?></div>
                                <div class="table-responsive mt-2">
                                    <table class="or-af-service-table">
                                        <thead>
                                            <tr>
                                                <th style="width:60%">Nature of Collection</th>
                                                <th style="width:20%">Account Code</th>
                                                <th style="width:20%" class="text-end">Amount (₱)</th>
                                            </tr>
                                        </thead>
                                        <tbody class="pharm-or-print-items"></tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="2" class="fw-bold">TOTAL</td>
                                                <td class="text-end fw-bold">₱<span class="pharm-or-print-total">0.00</span></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <div class="mb-1"><strong>Payment Method:</strong> <span class="pharm-or-print-method">Cash</span></div>
                                <div class="mb-1"><strong>Account Code:</strong> <span class="pharm-or-print-account">-</span></div>
                                <div class="mb-1"><strong>Notes:</strong> <span class="pharm-or-print-notes">-</span></div>
                            </div>
                            <div class="row g-3 mt-2">
                                <div class="col-12">
                                    <label class="form-label">Payment Method</label>
                                    <div class="d-flex gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input pharm-or-method" type="radio" name="pharm_or_method_<?php echo $order['id']; ?>" id="pharmOrMethodCash<?php echo $order['id']; ?>" value="Cash" checked>
                                            <label class="form-check-label" for="pharmOrMethodCash<?php echo $order['id']; ?>">Cash</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input pharm-or-method" type="radio" name="pharm_or_method_<?php echo $order['id']; ?>" id="pharmOrMethodCheck<?php echo $order['id']; ?>" value="Check">
                                            <label class="form-check-label" for="pharmOrMethodCheck<?php echo $order['id']; ?>">Check</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Internal Account Code</label>
                                    <input type="text" class="form-control pharm-or-account-input" placeholder="e.g. RX-001">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Notes</label>
                                    <input type="text" class="form-control pharm-or-notes-input" placeholder="Optional notes">
                                </div>
                            </div>
                            <div class="form-text mt-2">O.R. preview mirrors OPD style.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i>Confirm Payment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php elseif(isset($_GET['order_number'])): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No order found with the specified order number.
        </div>
        <?php endif; ?>

        <!-- Recent Orders -->
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>Recent Orders
                    <small class="text-muted ms-2" id="lastUpdateTime"></small>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Patient</th>
                                <th>Doctor</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="recentOrdersTableBody">
                            <?php foreach($recent_orders as $recent_order): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($recent_order['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($recent_order['patient_name']); ?></td>
                                    <td>Dr. <?php echo htmlspecialchars($recent_order['doctor_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($recent_order['created_at'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($recent_order['status']); ?>">
                                            <?php echo ucfirst($recent_order['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?order_number=<?php echo $recent_order['order_number']; ?>" class="btn btn-danger btn-sm">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // OR modal logic (similar to OPD)
            document.querySelectorAll('.pharm-or-form').forEach(function(form){
                const root = form.closest('.modal-content');
                const cash = root.querySelector('.pharm-or-cash');
                const changeEl = root.querySelector('.pharm-or-change');
                const itemsBody = root.querySelector('.pharm-or-items');
                const addItemBtn = root.querySelector('.pharm-or-add-item');
                const addDiscBtn = root.querySelector('.pharm-or-add-discount');
                const discTable = root.querySelector('.pharm-or-discounts');
                const subtotalEl = root.querySelector('.pharm-or-subtotal');
                const discAmtEl = root.querySelector('.pharm-or-disc-amt');
                const totalEl = root.querySelector('.pharm-or-total');
                const totalHidden = root.querySelector('.pharm-or-total-hidden');
                // Print preview bindings
                const printItems = root.querySelector('.pharm-or-print-items');
                const printTotal = root.querySelector('.pharm-or-print-total');
                const printMethod = root.querySelector('.pharm-or-print-method');
                const printAccount = root.querySelector('.pharm-or-print-account');
                const printNotes = root.querySelector('.pharm-or-print-notes');
                const methodInputs = Array.from(root.querySelectorAll('.pharm-or-method'));
                const accountInput = root.querySelector('.pharm-or-account-input');
                const notesInput = root.querySelector('.pharm-or-notes-input');
                function toNum(v){ return parseFloat(v || '0') || 0; }
                function bindRow(row){
                    if (row.dataset.bound === '1') return;
                    row.dataset.bound = '1';
                    row.querySelectorAll('.pharm-or-amt, .pharm-or-desc').forEach(inp => {
                        if (!inp.dataset.bound) { inp.addEventListener('input', recalc); inp.dataset.bound = '1'; }
                    });
                    const del = row.querySelector('.pharm-or-remove');
                    if (del && !del.dataset.bound) { del.addEventListener('click', function(){ row.remove(); recalc(); }); del.dataset.bound = '1'; }
                }
                function recalc(){
                    let sub = 0; itemsBody && itemsBody.querySelectorAll('.pharm-or-amt').forEach(i => { sub += toNum(i.value); });
                    if (subtotalEl) subtotalEl.value = sub.toFixed(2);
                    let pct = 0; discTable && discTable.querySelectorAll('.pharm-or-disc-pct').forEach(i => { pct += toNum(i.value); });
                    const disc = (pct / 100) * sub; if (discAmtEl) discAmtEl.value = disc.toFixed(2);
                    const total = Math.max(0, sub - disc); if (totalEl) totalEl.value = total.toFixed(2); if (totalHidden) totalHidden.value = total.toFixed(2);
                    if (cash && changeEl) { const tendered = toNum(cash.value); changeEl.textContent = Math.max(0, tendered - total).toFixed(2); }
                    // Build print preview
                    if (printItems) {
                        printItems.innerHTML = '';
                        itemsBody && itemsBody.querySelectorAll('tr').forEach(function(tr){
                            const d = (tr.querySelector('.pharm-or-desc')?.value || '').trim();
                            const a = toNum(tr.querySelector('.pharm-or-amt')?.value || '0');
                            const acc = (accountInput && accountInput.value.trim()) ? accountInput.value.trim() : '';
                            if (d !== '') {
                                const row = document.createElement('tr');
                                row.innerHTML = '<td>'+d.replace(/</g,'&lt;')+'</td><td>'+(acc||'')+'</td><td class="text-end">₱'+a.toFixed(2)+'</td>';
                                printItems.appendChild(row);
                            }
                        });
                    }
                    if (printTotal) { printTotal.textContent = (totalEl ? totalEl.value : total.toFixed(2)); }
                    if (printMethod) {
                        const sel = methodInputs.find(i => i.checked);
                        printMethod.textContent = sel ? sel.value : 'Cash';
                    }
                    if (printAccount) { printAccount.textContent = (accountInput && accountInput.value.trim()) ? accountInput.value.trim() : '-'; }
                    if (printNotes) { printNotes.textContent = (notesInput && notesInput.value.trim()) ? notesInput.value.trim() : '-'; }
                }
                if (itemsBody) itemsBody.querySelectorAll('tr').forEach(bindRow);
                if (addItemBtn && !addItemBtn.dataset.bound) { addItemBtn.addEventListener('click', function(){ const tr = document.createElement('tr'); tr.innerHTML = '<td><input type="text" class="form-control form-control-sm pharm-or-desc" placeholder="Description"></td>'+'<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end pharm-or-amt" value="0.00"></td>'+'<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger pharm-or-remove">&times;</button></td>'; itemsBody.appendChild(tr); bindRow(tr); recalc(); }); addItemBtn.dataset.bound = '1'; }
                if (addDiscBtn && !addDiscBtn.dataset.bound) { addDiscBtn.addEventListener('click', function(){ const tr = document.createElement('tr'); tr.innerHTML = '<td><input type="text" class="form-control form-control-sm pharm-or-disc-label" placeholder="Label"></td>'+'<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end pharm-or-disc-pct" value="0"></td>'+'<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger pharm-or-del-discount">&times;</button></td>'; discTable.appendChild(tr); tr.querySelector('.pharm-or-disc-label').addEventListener('input', recalc); tr.querySelector('.pharm-or-disc-pct').addEventListener('input', recalc); tr.querySelector('.pharm-or-del-discount').addEventListener('click', function(){ tr.remove(); recalc(); }); }); addDiscBtn.dataset.bound = '1'; }
                if (cash && !cash.dataset.bound) { cash.addEventListener('input', recalc); cash.dataset.bound = '1'; }
                methodInputs.forEach(function(mi){ if (!mi.dataset.bound){ mi.addEventListener('change', recalc); mi.dataset.bound='1'; } });
                if (accountInput && !accountInput.dataset.bound) { accountInput.addEventListener('input', recalc); accountInput.dataset.bound='1'; }
                if (notesInput && !notesInput.dataset.bound) { notesInput.addEventListener('input', recalc); notesInput.dataset.bound='1'; }
                recalc();

                // Capture and submit
                form.addEventListener('submit', function(ev){
                    const captureEl = form.querySelector('.or-capture-print');
                    const totEl = root.querySelector('.pharm-or-total');
                    const totHidden = root.querySelector('.pharm-or-total-hidden');
                    const cashEl = root.querySelector('.pharm-or-cash');
                    const total = parseFloat((totEl && totEl.value) || '0') || 0;
                    const tendered = parseFloat((cashEl && cashEl.value) || '0') || 0;
                    if (tendered < total) { ev.preventDefault(); alert('Insufficient cash tendered.'); cashEl && cashEl.focus(); return; }
                    if (totHidden) totHidden.value = total.toFixed(2);
                    if (!captureEl) return;
                    try {
                        html2canvas(captureEl, {scale:2}).then(function(canvas){
                            canvas.toBlob(function(blob){
                                const a = document.createElement('a');
                                const url = URL.createObjectURL(blob);
                                a.href = url; a.download = 'Pharmacy_OR_'+ (form.getAttribute('data-order-id')||'') +'.png';
                                document.body.appendChild(a); a.click();
                                setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); form.submit(); }, 150);
                            }, 'image/png');
                        });
                        ev.preventDefault();
                    } catch(e) { /* fallback submit */ }
                });
            });
            const orderSearchInput = document.getElementById('orderSearchInput');
            const clearOrderSearch = document.getElementById('clearOrderSearch');
            const searchButton = document.getElementById('searchButton');

            // Show/hide clear button based on initial input value
            clearOrderSearch.style.display = orderSearchInput.value ? 'block' : 'none';

            // Function to handle search
            function handleSearch() {
                const orderNumber = orderSearchInput.value.trim();
                if (orderNumber) {
                    window.location.href = `manage_orders.php?order_number=${encodeURIComponent(orderNumber)}`;
                }
            }

            // Show/hide clear button on input
            orderSearchInput.addEventListener('input', function() {
                clearOrderSearch.style.display = this.value ? 'block' : 'none';
            });

            // Search when Enter is pressed
            orderSearchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    handleSearch();
                }
            });

            // Search when Search button is clicked
            searchButton.addEventListener('click', function() {
                handleSearch();
            });

            // Clear search
            clearOrderSearch.addEventListener('click', function() {
                orderSearchInput.value = '';
                clearOrderSearch.style.display = 'none';
                window.location.href = 'manage_orders.php';
            });

            // Real-time updates for recent orders
            function updateRecentOrders() {
                fetch('fetch_recent_orders.php')
                    .then(response => response.text())
                    .then(html => {
                        const tableBody = document.getElementById('recentOrdersTableBody');
                        if (tableBody.innerHTML.trim() !== html.trim()) {
                            // Only update if there are changes
                            tableBody.innerHTML = html;
                            
                            // Update last update time
                            const now = new Date();
                            const timeString = now.toLocaleTimeString();
                            document.getElementById('lastUpdateTime').textContent = `Last updated: ${timeString}`;

                            // Optional: Play a notification sound if there are new orders
                            const audio = new Audio('notification.mp3');
                            audio.play().catch(e => console.log('Audio play failed or was interrupted'));
                        }
                    })
                    .catch(error => console.error('Error fetching recent orders:', error));
            }

            // Update every 5 seconds
            setInterval(updateRecentOrders, 5000);

            // Initial update
            updateRecentOrders();
        });
    </script>
</body>
</html> 
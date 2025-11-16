<?php
session_start();
if (!defined('INCLUDED_IN_PAGE')) { define('INCLUDED_IN_PAGE', true); }
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'finance') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $payment_id = $_POST['payment_id'];
    $request_id = $_POST['request_id'];
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Update payment status
        $payment_query = "UPDATE lab_payments 
                         SET payment_status = 'completed', 
                             payment_date = CURRENT_TIMESTAMP,
                             processed_by = :user_id
                         WHERE id = :payment_id";
        $payment_stmt = $db->prepare($payment_query);
        $payment_stmt->bindParam(":user_id", $_SESSION['user_id']);
        $payment_stmt->bindParam(":payment_id", $payment_id);
        $payment_stmt->execute();
        
        // Update lab request status
        $request_query = "UPDATE lab_requests 
                         SET payment_status = 'paid',
                             status = 'pending'
                         WHERE id = :request_id";
        $request_stmt = $db->prepare($request_query);
        $request_stmt->bindParam(":request_id", $request_id);
        $request_stmt->execute();
        
        // Create notification
        $notify_query = "INSERT INTO payment_notifications (lab_payment_id, notification_type) 
                        VALUES (:payment_id, 'payment_completed')";
        $notify_stmt = $db->prepare($notify_query);
        $notify_stmt->bindParam(":payment_id", $payment_id);
        $notify_stmt->execute();
        
        $db->commit();
        
        $_SESSION['success_message'] = "Payment processed successfully.";
        header("Location: pending_lab_payments.php");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_message'] = "Error processing payment. Please try again.";
    }
}

// Get pending payment requests
$query = "SELECT 
    lp.id as payment_id,
    lp.amount,
    lp.created_at as requested_at,
    lr.id as request_id,
    lr.priority,
    lt.test_name,
    lt.test_type,
    CONCAT(u.name, ' (', u.employee_type, ')') as requested_by,
    ov.patient_name,
    ov.contact_number
FROM lab_payments lp
JOIN lab_requests lr ON lp.lab_request_id = lr.id
JOIN lab_tests lt ON lr.test_id = lt.id
JOIN users u ON lr.doctor_id = u.id
JOIN opd_visits ov ON lr.visit_id = ov.id
WHERE lp.payment_status = 'pending' 
  AND lr.payment_status = 'unpaid'
  AND NOT EXISTS (
      SELECT 1 FROM lab_payments lp2 
      WHERE lp2.lab_request_id = lr.id AND lp2.payment_status = 'completed'
  )
ORDER BY 
    CASE lr.priority
        WHEN 'emergency' THEN 1
        WHEN 'urgent' THEN 2
        ELSE 3
    END,
    lp.created_at ASC";

$stmt = $db->prepare($query);
$stmt->execute();
$pending_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Decrypt sensitive fields for display
foreach ($pending_payments as &$pp) {
    $pp['patient_name'] = decrypt_safe($pp['patient_name'] ?? '');
    $pp['contact_number'] = decrypt_safe($pp['contact_number'] ?? '');
}
unset($pp);

// Set page title
$page_title = 'Pending Lab Payments';
if (!defined('INCLUDED_IN_PAGE')) { define('INCLUDED_IN_PAGE', true); }
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Lab Payments - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .priority-emergency { background-color: #ffe6e6; }
        .priority-urgent { background-color: #fff3e6; }
        .test-type-laboratory { color: #0d6efd; }
        .test-type-radiology { color: #6f42c1; }
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
    <div class="container py-4">
        <div class="d-flex justify-content-end mb-3">
            <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
        </div>
        <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-clock me-2"></i>
                    Pending Lab & Radiology Payments
                </h5>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Test</th>
                                <th>Requested By</th>
                                <th>Priority</th>
                                <th>Amount</th>
                                <th>Requested Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending_payments as $payment): ?>
                                <tr class="<?php echo 'priority-' . $payment['priority']; ?>">
                                    <td>
                                        <?php echo htmlspecialchars($payment['patient_name']); ?>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($payment['contact_number']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="test-type-<?php echo $payment['test_type']; ?>">
                                            <i class="fas <?php echo $payment['test_type'] === 'laboratory' ? 'fa-flask' : 'fa-x-ray'; ?> me-1"></i>
                                            <?php echo htmlspecialchars($payment['test_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['requested_by']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $payment['priority'] === 'emergency' ? 'danger' : 
                                                ($payment['priority'] === 'urgent' ? 'warning' : 'info'); 
                                        ?>">
                                            <?php echo ucfirst($payment['priority']); ?>
                                        </span>
                                    </td>
                                    <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($payment['requested_at'])); ?></td>
                                    <td>
                                        <button type="button" 
                                                class="btn btn-success btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#processPaymentModal<?php echo $payment['payment_id']; ?>">
                                            <i class="fas fa-check me-1"></i>
                                            Process Payment
                                        </button>
                                        
                                        <!-- Process Payment Modal (O.R. Preview) -->
                                        <div class="modal fade" id="processPaymentModal<?php echo $payment['payment_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-md">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Official Receipt (O.R.)</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form action="pending_lab_payments.php" method="POST" class="or-form" data-payment-id="<?php echo $payment['payment_id']; ?>">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                                                            <input type="hidden" name="request_id" value="<?php echo $payment['request_id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">O.R. Number</label>
                                                                <input type="text" name="or_number" class="form-control" value="<?php echo intval($payment['payment_id']); ?>" readonly required>
                                                            </div>
                                                            <div class="or-capture border rounded p-3" id="or-capture-<?php echo $payment['payment_id']; ?>">
                                                                <div class="d-flex justify-content-between small text-muted mb-2">
                                                                    <span>Date: <?php echo date('M d, Y h:i A'); ?></span>
                                                                    <span>Cashier: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                                                                </div>
                                                                <div class="mb-1"><strong>Patient:</strong> <?php echo htmlspecialchars($payment['patient_name']); ?></div>
                                                                <div class="mb-1"><strong>Test:</strong> <?php echo htmlspecialchars($payment['test_name']); ?> (<?php echo ucfirst($payment['priority']); ?>)</div>

                                                                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                                                                    <h6 class="mb-0">Order / Treatment</h6>
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary or-add-item"><i class="fas fa-plus me-1"></i>Add Item</button>
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
                                                                        <tbody class="or-items">
                                                                            <tr>
                                                                                <td><input type="text" class="form-control form-control-sm or-desc" value="<?php echo htmlspecialchars($payment['test_name']); ?>" /></td>
                                                                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end or-amt" value="<?php echo number_format($payment['amount'], 2, '.', ''); ?>" /></td>
                                                                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger or-remove">&times;</button></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>

                                                                <div class="mb-2">
                                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                                        <label class="form-label small mb-0">Discounts</label>
                                                                        <div class="d-flex gap-2">
                                                                            <select class="form-select form-select-sm or-discount-select" style="min-width: 200px;">
                                                                                <option value="">Select discount…</option>
                                                                                <option value="senior">Senior Citizen - 20%</option>
                                                                                <option value="pwd">PWD - 20%</option>
                                                                                <option value="philhealth">Philhealth - Custom %</option>
                                                                            </select>
                                                                            <button type="button" class="btn btn-sm btn-outline-secondary or-add-discount"><i class="fas fa-plus"></i></button>
                                                                        </div>
                                                                    </div>
                                                                    <table class="table table-sm mb-1">
                                                                        <thead>
                                                                            <tr><th style="width:70%">Label</th><th style="width:25%" class="text-end">%</th><th style="width:5%"></th></tr>
                                                                        </thead>
                                                                        <tbody class="or-discounts"></tbody>
                                                                    </table>
                                                                </div>

                                                                <table class="table table-sm mb-2">
                                                                    <tbody>
                                                                        <tr>
                                                                            <td style="width:70%">Subtotal</td>
                                                                            <td class="text-end pe-0" style="width:25%"><input type="text" class="form-control form-control-sm text-end or-subtotal" value="0.00" disabled></td>
                                                                            <td style="width:5%"></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <td>Discount Amt</td>
                                                                            <td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end or-disc-amt" value="0.00" disabled></td>
                                                                            <td></td>
                                                                        </tr>
                                                                        <tr class="table-active">
                                                                            <td class="fw-bold">TOTAL</td>
                                                                            <td class="text-end pe-0"><input type="text" class="form-control form-control-sm text-end or-total fw-bold" value="0.00" disabled></td>
                                                                            <td></td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>

                                                                <hr class="my-3">
                                                                <div class="or-capture-print border rounded p-3 bg-white">
                                                                    <div class="d-flex justify-content-between small text-muted mb-2">
                                                                        <span>Date: <?php echo date('M d, Y h:i A'); ?></span>
                                                                        <span>Cashier: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                                                                    </div>
                                                                    <div class="mb-1"><strong>Receipt No.:</strong> <?php echo intval($payment['payment_id']); ?> <span class="text-muted">(System-generated)</span></div>
                                                                    <div class="mb-1"><strong>Payor:</strong> <?php echo htmlspecialchars($payment['patient_name']); ?></div>
                                                                    <div class="table-responsive mt-2">
                                                                        <table class="or-af-service-table">
                                                                            <thead>
                                                                                <tr>
                                                                                    <th style="width:60%">Nature of Collection</th>
                                                                                    <th style="width:20%">Account Code</th>
                                                                                    <th style="width:20%" class="text-end">Amount (₱)</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody class="or-print-items"></tbody>
                                                                            <tfoot>
                                                                                <tr>
                                                                                    <td colspan="2" class="fw-bold">TOTAL</td>
                                                                                    <td class="text-end fw-bold">₱<span class="or-print-total">0.00</span></td>
                                                                                </tr>
                                                                            </tfoot>
                                                                        </table>
                                                                    </div>
                                                                    <div class="mb-1"><strong>Payment Method:</strong> <span class="or-print-method">Cash</span></div>
                                                                    <div class="mb-1"><strong>Account Code:</strong> <span class="or-print-account">-</span></div>
                                                                    <div class="mb-1"><strong>Notes:</strong> <span class="or-print-notes">-</span></div>
                                                                </div>

                                                                <div class="row g-2 align-items-end">
                                                                    <div class="col-6">
                                                                        <label class="form-label">Cash Tendered</label>
                                                                        <input type="number" step="0.01" min="0" class="form-control or-cash" placeholder="0.00">
                                                                    </div>
                                                                    <div class="col-6 text-end">
                                                                        <div class="small text-muted">Change</div>
                                                                        <div class="fs-6 fw-semibold">₱<span class="or-change">0.00</span></div>
                                                                    </div>
                                                                </div>
                                                                <div class="row g-3 mt-2">
                                                                    <div class="col-12">
                                                                        <label class="form-label">Payment Method</label>
                                                                        <div class="d-flex gap-3">
                                                                            <div class="form-check">
                                                                                <input class="form-check-input or-method" type="radio" name="lab_or_method_<?php echo $payment['payment_id']; ?>" id="labOrMethodCash<?php echo $payment['payment_id']; ?>" value="Cash" checked>
                                                                                <label class="form-check-label" for="labOrMethodCash<?php echo $payment['payment_id']; ?>">Cash</label>
                                                                            </div>
                                                                            <div class="form-check">
                                                                                <input class="form-check-input or-method" type="radio" name="lab_or_method_<?php echo $payment['payment_id']; ?>" id="labOrMethodCheck<?php echo $payment['payment_id']; ?>" value="Check">
                                                                                <label class="form-check-label" for="labOrMethodCheck<?php echo $payment['payment_id']; ?>">Check</label>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-6">
                                                                        <label class="form-label">Internal Account Code</label>
                                                                        <input type="text" class="form-control or-account-input" placeholder="e.g. LAB-001">
                                                                    </div>
                                                                    <div class="col-6">
                                                                        <label class="form-label">Notes</label>
                                                                        <input type="text" class="form-control or-notes-input" placeholder="Optional notes">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="form-text mt-2">O.R. preview (temporary for lab payments) mirrors Discharge Billing.</div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="process_payment" class="btn btn-success">
                                                                <i class="fas fa-check me-1"></i>
                                                                Confirm Payment
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pending_payments)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-check-circle text-success me-2"></i>
                                        No pending payments at the moment.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.modal').forEach(function(modal){
            modal.addEventListener('shown.bs.modal', function(ev){
                const root = ev.target;
                const cash = root.querySelector('.or-cash');
                const changeEl = root.querySelector('.or-change');
                const itemsBody = root.querySelector('.or-items');
                const addItemBtn = root.querySelector('.or-add-item');
                const addDiscBtn = root.querySelector('.or-add-discount');
                const discTable = root.querySelector('.or-discounts');
                const subtotalEl = root.querySelector('.or-subtotal');
                const discAmtEl = root.querySelector('.or-disc-amt');
                const totalEl = root.querySelector('.or-total');
                // Print preview bindings
                const printItems = root.querySelector('.or-print-items');
                const printTotal = root.querySelector('.or-print-total');
                const printMethod = root.querySelector('.or-print-method');
                const printAccount = root.querySelector('.or-print-account');
                const printNotes = root.querySelector('.or-print-notes');
                const methodInputs = Array.from(root.querySelectorAll('.or-method'));
                const accountInput = root.querySelector('.or-account-input');
                const notesInput = root.querySelector('.or-notes-input');
                function toNum(v){ return parseFloat(v || '0') || 0; }
                function bindRow(row){
                    if (row.dataset.bound === '1') return; // prevent duplicate bindings
                    row.dataset.bound = '1';
                    row.querySelectorAll('.or-amt, .or-desc').forEach(inp => {
                        if (!inp.dataset.bound) {
                            inp.addEventListener('input', recalc);
                            inp.dataset.bound = '1';
                        }
                    });
                    const del = row.querySelector('.or-remove');
                    if (del && !del.dataset.bound) {
                        del.addEventListener('click', function(){ row.remove(); recalc(); });
                        del.dataset.bound = '1';
                    }
                }
                function recalc(){
                    let sub = 0;
                    itemsBody && itemsBody.querySelectorAll('.or-amt').forEach(i => { sub += toNum(i.value); });
                    if (subtotalEl) subtotalEl.value = sub.toFixed(2);
                    let pct = 0;
                    discTable && discTable.querySelectorAll('.or-disc-pct').forEach(i => { pct += toNum(i.value); });
                    const disc = (pct / 100) * sub;
                    if (discAmtEl) discAmtEl.value = disc.toFixed(2);
                    const total = Math.max(0, sub - disc);
                    if (totalEl) totalEl.value = total.toFixed(2);
                    if (cash && changeEl) {
                        const tendered = toNum(cash.value);
                        changeEl.textContent = Math.max(0, tendered - total).toFixed(2);
                    }
                    // Build print preview (3 columns)
                    if (printItems) {
                        printItems.innerHTML = '';
                        itemsBody && itemsBody.querySelectorAll('tr').forEach(function(tr){
                            const d = (tr.querySelector('.or-desc')?.value || '').trim();
                            const a = toNum(tr.querySelector('.or-amt')?.value || '0');
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
                if (addItemBtn && !addItemBtn.dataset.bound) {
                    addItemBtn.addEventListener('click', function(){
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="text" class="form-control form-control-sm or-desc" placeholder="Description"></td>'+
                                   '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end or-amt" value="0.00"></td>'+
                                   '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger or-remove">&times;</button></td>';
                    itemsBody.appendChild(tr); bindRow(tr); recalc();
                    });
                    addItemBtn.dataset.bound = '1';
                }
                if (addDiscBtn && !addDiscBtn.dataset.bound) {
                    addDiscBtn.addEventListener('click', function(){
                        const select = root.querySelector('.or-discount-select');
                        if (!select) return;
                        const val = select.value;
                        if (!val) return;
                        let label = '';
                        let pct = 0;
                        let locked = false;
                        if (val === 'senior') { label = 'Senior Citizen'; pct = 20; locked = true; }
                        else if (val === 'pwd') { label = 'PWD'; pct = 20; locked = true; }
                        else if (val === 'philhealth') { label = 'Philhealth'; pct = 0; locked = false; }
                        const tr = document.createElement('tr');
                        tr.setAttribute('data-discount', val);
                        tr.innerHTML = '<td><input type="text" class="form-control form-control-sm or-disc-label" value="'+label.replace(/"/g,'&quot;')+'" readonly></td>'+
                                       '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end or-disc-pct" value="'+pct.toFixed(2)+'" '+(locked?'disabled':'')+'></td>'+
                                       '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger or-del-discount">&times;</button></td>';
                        discTable.appendChild(tr);
                        const pctInp = tr.querySelector('.or-disc-pct');
                        pctInp && pctInp.addEventListener('input', recalc);
                        const delBtn = tr.querySelector('.or-del-discount');
                        if (delBtn) {
                            delBtn.addEventListener('click', function(){
                                tr.remove();
                                // re-enable option
                                const opt = select.querySelector('option[value="'+val+'"]');
                                if (opt) opt.disabled = false;
                                recalc();
                            });
                        }
                        // disable chosen option
                        const opt = select.querySelector('option[value="'+val+'"]');
                        if (opt) opt.disabled = true;
                        select.value = '';
                        recalc();
                    });
                    addDiscBtn.dataset.bound = '1';
                }
                if (cash && !cash.dataset.bound) { cash.addEventListener('input', recalc); cash.dataset.bound = '1'; }
                methodInputs.forEach(function(mi){ if (!mi.dataset.bound){ mi.addEventListener('change', recalc); mi.dataset.bound='1'; } });
                if (accountInput && !accountInput.dataset.bound) { accountInput.addEventListener('input', recalc); accountInput.dataset.bound='1'; }
                if (notesInput && !notesInput.dataset.bound) { notesInput.addEventListener('input', recalc); notesInput.dataset.bound='1'; }
                recalc();
                cash && cash.focus();
            });
        });

        // Validate tendered cash and capture O.R. as image on Confirm Payment click
        document.querySelectorAll('.or-form').forEach(function(form){
            form.addEventListener('submit', function(ev){
                const captureEl = form.querySelector('.or-capture-print');
                const orNum = (form.querySelector('input[name="or_number"]').value || '').replace(/[^\w-]/g,'');
                // Validate cash tendered >= total
                const totalEl = form.querySelector('.or-total');
                const cashEl = form.querySelector('.or-cash');
                const total = parseFloat((totalEl && totalEl.value) || '0') || 0;
                const tendered = parseFloat((cashEl && cashEl.value) || '0') || 0;
                if (tendered < total) {
                    ev.preventDefault();
                    alert('Insufficient cash tendered. Please enter an amount equal to or greater than the total.');
                    cashEl && cashEl.focus();
                    return;
                }
                if (!captureEl) return; // fallback submits normally
                try {
                    html2canvas(captureEl, {scale: 2}).then(function(canvas){
                        canvas.toBlob(function(blob){
                            const a = document.createElement('a');
                            const url = URL.createObjectURL(blob);
                            a.href = url;
                            const filename = 'OR_' + (orNum || ('PAY'+ (form.getAttribute('data-payment-id')||''))) + '.png';
                            a.download = filename;
                            document.body.appendChild(a);
                            a.click();
                            setTimeout(function(){ 
                                URL.revokeObjectURL(url); 
                                a.remove(); 
                                // Ensure PHP sees the intent
                                let flag = form.querySelector('input[name="process_payment"]');
                                if (!flag) {
                                    flag = document.createElement('input');
                                    flag.type = 'hidden';
                                    flag.name = 'process_payment';
                                    flag.value = '1';
                                    form.appendChild(flag);
                                }
                                form.submit(); 
                            }, 150);
                        }, 'image/png');
                    });
                    ev.preventDefault();
                } catch(e) {
                    // If capture fails, proceed with normal submit
                    let flag = form.querySelector('input[name="process_payment"]');
                    if (!flag) {
                        flag = document.createElement('input');
                        flag.type = 'hidden';
                        flag.name = 'process_payment';
                        flag.value = '1';
                        form.appendChild(flag);
                    }
                }
            });
        });
    });
    </script>
</body>
</html> 
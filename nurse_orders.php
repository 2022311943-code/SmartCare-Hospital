<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

function isDischargeOrderType($orderType) {
    $value = strtolower(trim((string)$orderType));
    return $value === 'discharge' || $value === 'discharge_order' || $value === '';
}

function getOrderTypeLabel(array $order): string {
    if (isDischargeOrderType($order['order_type'] ?? null)) {
        return 'Discharge';
    }
    $raw = trim((string)($order['order_type'] ?? ''));
    return $raw !== '' ? ucfirst(str_replace('_',' ', $raw)) : 'Unspecified';
}

$success_message = $error_message = '';

if (isset($_SESSION['nurse_order_success'])) {
    $success_message = trim($success_message . ' ' . $_SESSION['nurse_order_success']);
    unset($_SESSION['nurse_order_success']);
}

// Optional filter by admission
$admission_id = isset($_GET['admission_id']) ? intval($_GET['admission_id']) : 0;

// Handle actions: claim, release, complete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();

        if ($_POST['action'] === 'claim_order') {
            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            if ($order_id <= 0) {
                throw new Exception('Invalid order reference.');
            }

            $order_details_stmt = $db->prepare("SELECT 
                    do.order_type,
                    p.name AS patient_name,
                    p.age,
                    p.gender,
                    p.address AS patient_address,
                    p.id AS patient_id,
                    r.room_number,
                    b.bed_number
                FROM doctors_orders do
                JOIN patient_admissions pa ON do.admission_id = pa.id
                JOIN patients p ON pa.patient_id = p.id
                LEFT JOIN rooms r ON pa.room_id = r.id
                LEFT JOIN beds b ON pa.bed_id = b.id
                WHERE do.id = :order_id");
            $order_details_stmt->bindParam(":order_id", $order_id);
            $order_details_stmt->execute();
            $order_details = $order_details_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order_details) {
                throw new Exception('Order not found.');
            }

            // Claim only if active and unclaimed
            $claim_query = "UPDATE doctors_orders
                            SET status = 'in_progress',
                                claimed_by = :nurse_id,
                                claimed_at = NOW()
                            WHERE id = :order_id
                              AND status = 'active'
                              AND (claimed_by IS NULL)";
            $claim_stmt = $db->prepare($claim_query);
            $claim_stmt->bindParam(":nurse_id", $_SESSION['user_id']);
            $claim_stmt->bindParam(":order_id", $order_id);
            $claim_stmt->execute();
            if ($claim_stmt->rowCount() === 0) {
                throw new Exception('Order cannot be claimed (already in progress or done).');
            }

            if (isset($_POST['redirect_to_clearance']) && $_POST['redirect_to_clearance'] === '1' && isDischargeOrderType($order_details['order_type'])) {
                $db->commit();
                header("Location: nurse_clearance_form.php?order_id=" . intval($order_id));
                exit();
            }

            $_SESSION['nurse_order_success'] = "Order claimed";
        } elseif ($_POST['action'] === 'release_order') {
            // Release only if claimed by me and still in_progress
            $release_query = "UPDATE doctors_orders
                              SET status = 'active',
                                  claimed_by = NULL,
                                  claimed_at = NULL
                              WHERE id = :order_id
                                AND status = 'in_progress'
                                AND claimed_by = :nurse_id";
            $release_stmt = $db->prepare($release_query);
            $release_stmt->bindParam(":order_id", $_POST['order_id']);
            $release_stmt->bindParam(":nurse_id", $_SESSION['user_id']);
            $release_stmt->execute();
            if ($release_stmt->rowCount() === 0) {
                throw new Exception('Order cannot be released.');
            }
            $_SESSION['nurse_order_success'] = "Order released";
        } elseif ($_POST['action'] === 'complete_order') {
            $update_query = "UPDATE doctors_orders
                             SET status = 'completed',
                                 completed_by = :nurse_id,
                                 completed_at = NOW(),
                                 completion_note = :completion_note
                             WHERE id = :order_id 
                               AND status = 'in_progress'
                               AND claimed_by = :nurse_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(":nurse_id", $_SESSION['user_id']);
            $update_stmt->bindParam(":completion_note", $_POST['completion_note']);
            $update_stmt->bindParam(":order_id", $_POST['order_id']);
            $update_stmt->execute();
            if ($update_stmt->rowCount() === 0) {
                throw new Exception('Only the nurse who claimed this order can complete it.');
            }

            // If this is a discharge order, open a billing case for finance
            $order_info = $db->prepare("SELECT admission_id, order_type FROM doctors_orders WHERE id = :id");
            $order_info->bindParam(":id", $_POST['order_id']);
            $order_info->execute();
            $oi = $order_info->fetch(PDO::FETCH_ASSOC);
            if ($oi && isDischargeOrderType($oi['order_type']) && !empty($oi['admission_id'])) {
                // Create billing row if not exists
                $exists_stmt = $db->prepare("SELECT id FROM admission_billing WHERE admission_id = :aid LIMIT 1");
                $exists_stmt->bindParam(":aid", $oi['admission_id']);
                $exists_stmt->execute();
                if (!$exists_stmt->fetch()) {
                    $create_bill = $db->prepare("INSERT INTO admission_billing (admission_id, subtotal, discount_amount, discount_label, total_due, payment_status, created_by) VALUES (:aid, 0, 0, NULL, 0, 'unpaid', :uid)");
                    $create_bill->bindParam(":aid", $oi['admission_id']);
                    $create_bill->bindParam(":uid", $_SESSION['user_id']);
                    $create_bill->execute();
                }
            }

            $_SESSION['nurse_order_success'] = "Order marked as completed";
        }

        $db->commit();
        $redirect = 'nurse_orders.php' . ($admission_id ? ('?admission_id=' . intval($admission_id)) : '');
        header("Location: " . $redirect);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Fetch active and in-progress orders, optionally filtered by admission
$orders_query = "SELECT do.*, p.name as patient_name, p.age, p.gender, p.address as patient_address, p.id as patient_id,
                        r.room_number, b.bed_number,
                        u.name as claimed_by_name
                 FROM doctors_orders do
                 JOIN patient_admissions pa ON do.admission_id = pa.id
                 JOIN patients p ON pa.patient_id = p.id
                 LEFT JOIN rooms r ON pa.room_id = r.id
                 LEFT JOIN beds b ON pa.bed_id = b.id
                 LEFT JOIN users u ON do.claimed_by = u.id
                 WHERE pa.admission_status = 'admitted' 
                   AND do.status IN ('active','in_progress')" .
                 ($admission_id ? " AND do.admission_id = :admission_id" : "") .
                 " ORDER BY do.status = 'in_progress' DESC, do.ordered_at DESC";
$orders_stmt = $db->prepare($orders_query);
if ($admission_id) {
    $orders_stmt->bindParam(":admission_id", $admission_id);
}
$orders_stmt->execute();
$active_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);


$page_title = 'Nurse - Doctor Orders';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Orders - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Doctor Orders</h2>
        <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($active_orders)): ?>
                <p class="text-muted text-center mb-0">No active orders.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Ordered At</th>
                                <th>Patient</th>
                                <th>Location</th>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody">
                        <?php foreach ($active_orders as $order): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($order['ordered_at'])); ?></td>
                                <td><?php echo htmlspecialchars($order['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['room_number'] . ' / ' . $order['bed_number']); ?></td>
                                <td>
                                    <?php if ($order['special_instructions'] === 'ROOM_TRANSFER'): ?>
                                        <span class="badge bg-warning text-dark">Room Transfer</span>
                                    <?php elseif ($order['special_instructions'] === 'NEWBORN_INFO_REQUEST'): ?>
                                        <span class="badge bg-pink text-dark" style="background:#f8d7da;color:#842029;">Newborn Info</span>
                                    <?php elseif (isDischargeOrderType($order['order_type'])): ?>
                                        <span class="badge bg-danger">Discharge</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(getOrderTypeLabel($order)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo nl2br(htmlspecialchars($order['order_details'])); ?></td>
                                <td>
                                    <?php if ($order['status'] === 'in_progress'): ?>
                                        <span class="badge bg-warning text-dark">In Progress</span>
                                        <?php if (!empty($order['claimed_by_name'])): ?>
                                            <small class="text-muted d-block">By: <?php echo htmlspecialchars($order['claimed_by_name']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-info">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <?php if ($order['special_instructions'] === 'NEWBORN_INFO_REQUEST'): ?>
                                            <?php if ($order['status'] === 'active' || ($order['status'] === 'in_progress' && (int)$order['claimed_by'] === (int)$_SESSION['user_id'])): ?>
                                                <a
                                                    href="nurse_newborn_request.php?order_id=<?php echo $order['id']; ?>"
                                                    class="btn btn-sm btn-outline-primary"
                                                >
                                                    <i class="fas fa-baby me-1"></i>Process Request
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled>
                                                    <i class="fas fa-lock me-1"></i>Unavailable
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($order['status'] === 'active'): ?>
                                                <?php if (isDischargeOrderType($order['order_type'])): ?>
                                                    <form method="POST" action="nurse_orders.php" class="d-inline">
                                                        <input type="hidden" name="action" value="claim_order">
                                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                        <input type="hidden" name="redirect_to_clearance" value="1">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-hand-paper me-1"></i>Claim &amp; Open Clearance
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="nurse_orders.php" class="d-inline">
                                                        <input type="hidden" name="action" value="claim_order">
                                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-hand-paper me-1"></i>Claim
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php elseif ($order['status'] === 'in_progress' && $order['claimed_by'] == $_SESSION['user_id']): ?>
                                                <?php if (isDischargeOrderType($order['order_type'])): ?>
                                                    <a 
                                                        href="nurse_clearance_form.php?order_id=<?php echo $order['id']; ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                    >
                                                        <i class="fas fa-file-alt me-1"></i>Open Clearance
                                                    </a>
                                                <?php endif; ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="release_order">
                                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                        <i class="fas fa-undo me-1"></i>Release
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'in_progress' && $order['claimed_by'] == $_SESSION['user_id']): ?>
                                                <button class="btn btn-sm btn-success" onclick="openComplete(<?php echo $order['id']; ?>)">
                                                    <i class="fas fa-check me-1"></i>Done
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-success" disabled title="Claim this order to complete">
                                                    <i class="fas fa-check me-1"></i>Done
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Complete Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="complete_order">
                <input type="hidden" name="order_id" id="order_id">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Order as Completed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Completion Note (optional)</label>
                        <textarea class="form-control" name="completion_note" rows="3" placeholder="Add any notes about completion..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark Done</button>
                </div>
            </form>
        </div>
    </div>
</div>



<style>
.clearance-sheet {
    width: 640px;
    border: 2px solid #000;
    padding: 15px;
    font-family: 'Times New Roman', serif;
    color: #000;
    font-size: 14px;
}
.underline-field {
    display: inline-block;
    min-width: 150px;
    border-bottom: 1px solid #000;
    padding: 0 4px;
}
.clearance-table td, .clearance-table th {
    font-size: 14px;
    vertical-align: middle;
}
.signature-line {
    border-top: 1px solid #000;
    font-size: 12px;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
let completeModal;
let clearanceModal;

function showClearanceModal(data, disableClaim) {
    if (!clearanceModal || !data) return;
    const wardEl = document.getElementById('clearanceWard');
    const patientNoEl = document.getElementById('clearancePatientNo');
    const patientNameEl = document.getElementById('clearancePatientName');
    const patientAddressEl = document.getElementById('clearanceAddress');
    const patientAgeEl = document.getElementById('clearanceAge');
    const patientGenderEl = document.getElementById('clearanceGender');
    const dateInput = document.getElementById('clearanceDate');
    const timeInput = document.getElementById('clearanceTime');
    const confirmBtn = document.getElementById('confirmClaimBtn');

    if (wardEl) {
        const wardText = (data.room || '') + (data.bed ? ' / ' + data.bed : '');
        wardEl.textContent = wardText.trim() !== '' ? wardText : '-';
    }
    if (patientNoEl) patientNoEl.textContent = data.patientNo || '-';
    if (patientNameEl) patientNameEl.textContent = data.patientName || '-';
    if (patientAddressEl) patientAddressEl.textContent = data.patientAddress || '-';
    if (patientAgeEl) patientAgeEl.textContent = data.patientAge || '-';
    if (patientGenderEl) patientGenderEl.textContent = data.patientGender || '-';

    const now = new Date();
    if (dateInput) dateInput.value = now.toISOString().split('T')[0];
    if (timeInput) timeInput.value = now.toTimeString().slice(0,5);

    if (confirmBtn) {
        if (disableClaim) {
            confirmBtn.style.display = 'none';
        } else {
            confirmBtn.style.display = '';
            confirmBtn.dataset.orderId = data.orderId || '';
        }
    }

    clearanceModal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    completeModal = new bootstrap.Modal(document.getElementById('completeModal'));
    clearanceModal = new bootstrap.Modal(document.getElementById('clearanceModal'));

    const downloadBtn = document.getElementById('downloadClearance');
    const confirmClaimBtn = document.getElementById('confirmClaimBtn');

    function bindClearanceButtons(scope) {
        scope.querySelectorAll('.btn-open-clearance').forEach(btn => {
            btn.addEventListener('click', () => {
                showClearanceModal({
                    orderId: btn.dataset.orderId || '',
                    patientNo: btn.dataset.patientNo || '-',
                    patientName: btn.dataset.patientName || '-',
                    patientAddress: btn.dataset.patientAddress || '-',
                    patientAge: btn.dataset.patientAge || '-',
                    patientGender: btn.dataset.patientGender || '-',
                    room: btn.dataset.room || '',
                    bed: btn.dataset.bed || ''
                }, btn.dataset.disableClaim === '1');
            });
        });
    }

    bindClearanceButtons(document);

    if (downloadBtn) {
        downloadBtn.addEventListener('click', () => {
            html2canvas(document.getElementById('clearanceWrapper'), {scale:2}).then(canvas => {
                canvas.toBlob(blob => {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'clearance_form.png';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                });
            });
        });
    }

    if (confirmClaimBtn) {
        confirmClaimBtn.addEventListener('click', function() {
            const orderId = this.dataset.orderId;
            if (!orderId) return;
            if (clearanceModal) {
                clearanceModal.hide();
            }
            document.getElementById('claimOrderIdHidden').value = orderId;
            document.getElementById('claimForm').submit();
        });
    }

    function refreshOrders() {
        fetch('fetch_nurse_orders.php<?php echo $admission_id ? ('?admission_id=' . intval($admission_id)) : '';?>')
            .then(r => r.text())
            .then(html => {
                const tbody = document.getElementById('ordersTableBody');
                if (!tbody) return;
                tbody.innerHTML = html;
                bindClearanceButtons(tbody);
            })
            .catch(() => {});
    }

    setInterval(refreshOrders, 10000);

    if (window.postClaimClearanceData) {
        showClearanceModal(window.postClaimClearanceData, true);
        window.postClaimClearanceData = null;
    }
});

function openComplete(orderId) {
    document.getElementById('order_id').value = orderId;
    if (completeModal) {
        completeModal.show();
    }
}
</script>
<?php if (!empty($auto_clearance_data)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const data = <?php echo json_encode($auto_clearance_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    if (data) {
        showClearanceModal(data, true);
    }
});
</script>
<?php endif; ?>
</body>
</html>
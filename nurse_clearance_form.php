<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    header("Location: index.php");
    exit();
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($order_id <= 0) {
    header("Location: nurse_orders.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

function isDischargeOrderType($orderType) {
    $value = strtolower(trim((string)$orderType));
    return $value === 'discharge' || $value === 'discharge_order' || $value === '';
}

function fetch_discharge_order(PDO $db, int $order_id): ?array {
    $sql = "SELECT do.*,
                   p.name AS patient_name,
                   p.age AS patient_age,
                   p.gender AS patient_gender,
                   p.address AS patient_address,
                   p.id AS patient_id,
                   r.room_number,
                   b.bed_number,
                   u.name AS claimed_by_name
            FROM doctors_orders do
            JOIN patient_admissions pa ON do.admission_id = pa.id
            JOIN patients p ON pa.patient_id = p.id
            LEFT JOIN rooms r ON pa.room_id = r.id
            LEFT JOIN beds b ON pa.bed_id = b.id
            LEFT JOIN users u ON do.claimed_by = u.id
            WHERE do.id = :id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':id', $order_id);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$order = fetch_discharge_order($db, $order_id);
if (!$order || !isDischargeOrderType($order['order_type'])) {
    header("Location: nurse_orders.php");
    exit();
}

$error_message = '';

try {
    if ($order['status'] === 'active') {
        $claim_stmt = $db->prepare("UPDATE doctors_orders
                                    SET status = 'in_progress',
                                        claimed_by = :nurse_id,
                                        claimed_at = NOW()
                                    WHERE id = :order_id AND status = 'active' AND (claimed_by IS NULL)");
        $claim_stmt->execute([
            ':nurse_id' => $_SESSION['user_id'],
            ':order_id' => $order_id
        ]);
        $order = fetch_discharge_order($db, $order_id);
    }

if ($order['status'] === 'in_progress' && $order['claimed_by'] && intval($order['claimed_by']) !== intval($_SESSION['user_id'])) {
        $error_message = 'This discharge order is already being handled by ' . htmlspecialchars($order['claimed_by_name'] ?? 'another nurse') . '.';
    }
} catch (Exception $e) {
    $error_message = 'Error claiming order: ' . $e->getMessage();
}

$page_title = 'Discharge Clearance Form';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discharge Clearance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .clearance-sheet {
            width: 720px;
            border: 2px solid #000;
            padding: 20px;
            font-family: 'Times New Roman', serif;
            color: #000;
            margin: 0 auto;
            background: #fff;
        }
        .underline-field {
            display: inline-block;
            min-width: 160px;
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
        body {
            background: #f4f6f8;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
        <h2 class="mb-0"><i class="fas fa-file-alt me-2"></i>Discharge Clearance</h2>
        <div class="d-flex gap-2">
            <a href="nurse_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Orders
            </a>
            <?php if ($order['status'] === 'in_progress' && (int)$order['claimed_by'] === (int)$_SESSION['user_id'] && empty($error_message)): ?>
                <form method="POST" action="nurse_orders.php" class="d-inline">
                    <input type="hidden" name="action" value="complete_order">
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <input type="hidden" name="completion_note" value="Clearance form completed">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Mark Done
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php elseif ($order['status'] !== 'in_progress'): ?>
        <div class="alert alert-warning">This order is currently marked as "<?php echo htmlspecialchars($order['status']); ?>". Clearance can only be filled while it is in progress.</div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between flex-wrap gap-3">
            <div>
                <div class="text-muted small">Patient</div>
                <div class="fw-semibold"><?php echo htmlspecialchars($order['patient_name']); ?></div>
                <div class="small text-muted"><?php echo htmlspecialchars($order['patient_age']); ?> yrs • <?php echo htmlspecialchars(ucfirst($order['patient_gender'])); ?></div>
            </div>
            <div>
                <div class="text-muted small">Room / Bed</div>
                <div class="fw-semibold"><?php echo htmlspecialchars(($order['room_number'] ?? '-') . ' / ' . ($order['bed_number'] ?? '-')); ?></div>
            </div>
            <div>
                <div class="text-muted small">Order Status</div>
                <span class="badge bg-<?php echo $order['status'] === 'in_progress' ? 'warning text-dark' : 'secondary'; ?>">
                    <?php echo htmlspecialchars(ucfirst(str_replace('_',' ', $order['status']))); ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body text-center">
            <div id="clearanceWrapper" class="clearance-sheet text-start">
                <div class="text-center mb-2">
                    <div class="fw-bold text-uppercase">CANDABA MUNICIPAL INFIRMARY</div>
                    <div style="font-size:0.9rem;">Pansul, Pasig, Candaba, Pampanga</div>
                    <div class="fw-bold mt-1 text-uppercase">CLEARANCE FORM</div>
                </div>
                <div class="mb-2">
                    <div class="d-flex justify-content-between">
                        <div>Ward: <span class="underline-field"><?php echo htmlspecialchars(($order['room_number'] ?? '') . ($order['bed_number'] ? ' / '.$order['bed_number'] : '')); ?></span></div>
                        <div>Patient No.: <span class="underline-field"><?php echo htmlspecialchars($order['patient_id']); ?></span></div>
                    </div>
                    <div>Patient's Name: <span class="underline-field"><?php echo htmlspecialchars($order['patient_name']); ?></span></div>
                    <div>Address: <span class="underline-field"><?php echo htmlspecialchars($order['patient_address']); ?></span></div>
                    <div class="d-flex justify-content-between">
                        <div>Age: <span class="underline-field"><?php echo htmlspecialchars($order['patient_age']); ?></span></div>
                        <div>Gender: <span class="underline-field"><?php echo htmlspecialchars(ucfirst($order['patient_gender'])); ?></span></div>
                    </div>
                </div>
                <table class="table table-bordered clearance-table mb-2">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50%;">DEPARTMENT</th>
                            <th>SIGNATURE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $departments = ['PHARMACY','CENTRAL SUPPLY','LABORATORY','RADIOLOGY','PHILHEALTH','BILLING','CASHIER','MEDICAL RECORDS'];
                            foreach ($departments as $dept): ?>
                            <tr>
                                <td><?php echo $dept; ?></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mb-2">
                    <div>Date of Discharged:
                        <input type="date" class="form-control form-control-sm d-inline-block w-auto" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mt-1">Time of Discharged:
                        <input type="time" class="form-control form-control-sm d-inline-block w-auto" value="<?php echo date('H:i'); ?>">
                    </div>
                </div>
                <table class="table table-bordered text-center mb-0">
                    <tr>
                        <td style="height:90px;">
                            <div class="fw-semibold">NURSE ON DUTY</div>
                            <div class="signature-line mt-4">Signature Over Printed Name</div>
                        </td>
                        <td style="height:90px;">
                            <div class="fw-semibold">GUARD ON DUTY</div>
                            <div class="signature-line mt-4">Signature Over Printed Name</div>
                        </td>
                    </tr>
                </table>
                <div class="text-end text-muted small mt-1">CF020</div>
            </div>

            <div class="mt-4 d-flex justify-content-center gap-2">
                <button id="downloadClearance" class="btn btn-outline-primary">
                    <i class="fas fa-download me-1"></i>Save as Image
                </button>
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const downloadBtn = document.getElementById('downloadClearance');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', function() {
            downloadBtn.disabled = true;
            downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
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
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = '<i class="fas fa-download me-1"></i>Save as Image';
                });
            }).catch(() => {
                downloadBtn.disabled = false;
                downloadBtn.innerHTML = '<i class="fas fa-download me-1"></i>Save as Image';
            });
        });
    }
});
</script>
</body>
</html>


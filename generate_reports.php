<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'finance') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Filters
$start = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : date('Y-m-01');
$end = isset($_GET['end']) && $_GET['end'] !== '' ? $_GET['end'] : date('Y-m-d');

// LAB totals
$labStmt = $db->prepare("SELECT 
    COALESCE(SUM(CASE WHEN payment_status='completed' AND DATE(payment_date) BETWEEN :s AND :e THEN amount END),0) as collected,
    COUNT(CASE WHEN payment_status='completed' AND DATE(payment_date) BETWEEN :s AND :e THEN 1 END) as tx
FROM lab_payments");
$labStmt->execute([':s'=>$start, ':e'=>$end]);
$lab = $labStmt->fetch(PDO::FETCH_ASSOC);

// OPD totals
try {
    $opdStmt = $db->prepare("SELECT 
        COALESCE(SUM(CASE WHEN payment_status='paid' AND DATE(payment_date) BETWEEN :s AND :e THEN amount_due END),0) as collected,
        COUNT(CASE WHEN payment_status='paid' AND DATE(payment_date) BETWEEN :s AND :e THEN 1 END) as tx
    FROM opd_payments");
    $opdStmt->execute([':s'=>$start, ':e'=>$end]);
    $opd = $opdStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $opd = ['collected'=>0,'tx'=>0];
}

// DISCHARGE billings (from inpatient admission_billing paid by updated_at)
$abStmt = $db->prepare("SELECT 
    COALESCE(SUM(CASE WHEN payment_status='paid' AND DATE(updated_at) BETWEEN :s AND :e THEN total_due END),0) as collected,
    COUNT(CASE WHEN payment_status='paid' AND DATE(updated_at) BETWEEN :s AND :e THEN 1 END) as tx
FROM admission_billing");
$abStmt->execute([':s'=>$start, ':e'=>$end]);
$ab = $abStmt->fetch(PDO::FETCH_ASSOC);

$grandCollected = (float)$lab['collected'] + (float)$opd['collected'] + (float)$ab['collected'];
$grandTx = (int)$lab['tx'] + (int)$opd['tx'] + (int)$ab['tx'];

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="financial_report_'.$start.'_to_'.$end.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section','Transactions','Collected']);
    fputcsv($out, ['Lab', $lab['tx'], number_format($lab['collected'],2)]);
    fputcsv($out, ['OPD', $opd['tx'], number_format($opd['collected'],2)]);
    fputcsv($out, ['Discharge', $ab['tx'], number_format($ab['collected'],2)]);
    fputcsv($out, ['TOTAL', $grandTx, number_format($grandCollected,2)]);
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Financial Reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-chart-line me-2"></i>Financial Reports</h3>
        <a href="dashboard.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <form method="GET" class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <label class="form-label">Start</label>
            <input type="date" name="start" class="form-control" value="<?php echo htmlspecialchars($start); ?>">
        </div>
        <div class="col-auto">
            <label class="form-label">End</label>
            <input type="date" name="end" class="form-control" value="<?php echo htmlspecialchars($end); ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-danger" type="submit"><i class="fas fa-search me-1"></i>Apply</button>
            <a class="btn btn-success" href="?start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&export=csv"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Lab Payments</div>
                <div class="fs-5">Transactions: <?php echo (int)$lab['tx']; ?></div>
                <div class="fs-5">Collected: ₱<?php echo number_format($lab['collected'],2); ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">OPD Payments</div>
                <div class="fs-5">Transactions: <?php echo (int)$opd['tx']; ?></div>
                <div class="fs-5">Collected: ₱<?php echo number_format($opd['collected'],2); ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Total</div>
                <div class="fs-5">Transactions: <?php echo (int)$grandTx; ?></div>
                <div class="fs-5">Collected: ₱<?php echo number_format($grandCollected,2); ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Discharge Billings</div>
                <div class="fs-5">Transactions: <?php echo (int)$ab['tx']; ?></div>
                <div class="fs-5">Collected: ₱<?php echo number_format($ab['collected'],2); ?></div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Breakdown</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Section</th>
                            <th>Transactions</th>
                            <th>Collected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Lab</td>
                            <td><?php echo (int)$lab['tx']; ?></td>
                            <td>₱<?php echo number_format($lab['collected'],2); ?></td>
                        </tr>
                        <tr>
                            <td>OPD</td>
                            <td><?php echo (int)$opd['tx']; ?></td>
                            <td>₱<?php echo number_format($opd['collected'],2); ?></td>
                        </tr>
                        <tr>
                            <td>Discharge</td>
                            <td><?php echo (int)$ab['tx']; ?></td>
                            <td>₱<?php echo number_format($ab['collected'],2); ?></td>
                        </tr>
                        <tr class="table-active">
                            <td class="fw-bold">TOTAL</td>
                            <td class="fw-bold"><?php echo (int)$grandTx; ?></td>
                            <td class="fw-bold">₱<?php echo number_format($grandCollected,2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

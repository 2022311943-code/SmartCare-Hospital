<?php
session_start();
require_once "config/database.php";

// Allow admin_staff or supra_admin (and finance to view sales) – relax as needed
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['admin_staff','finance']) ) {
    if (($_SESSION['role'] ?? '') !== 'supra_admin') {
        header('Location: index.php');
        exit();
    }
}

$database = new Database();
$db = $database->getConnection();

// Filters
$start = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : date('Y-m-01');
$end = isset($_GET['end']) && $_GET['end'] !== '' ? $_GET['end'] : date('Y-m-d');
$type = isset($_GET['type']) ? $_GET['type'] : 'all'; // opd,inpatients,patients,sales_all,sales_lab,sales_pharmacy,sales_discharge

// Compute metrics
$opdStmt = $db->prepare("SELECT COUNT(*) AS c FROM opd_visits WHERE DATE(arrival_time) BETWEEN :s AND :e");
$opdStmt->execute([':s'=>$start, ':e'=>$end]);
$opdCount = (int)$opdStmt->fetchColumn();

// Use admission_date when available, else created_at
$inpatientsStmt = $db->prepare("SELECT COUNT(*) AS c 
    FROM patient_admissions 
    WHERE admission_status IN ('pending','admitted')
      AND DATE(COALESCE(admission_date, created_at)) BETWEEN :s AND :e");
$inpatientsStmt->execute([':s'=>$start, ':e'=>$end]);
$inpatientsCount = (int)$inpatientsStmt->fetchColumn();

$patientsStmt = $db->prepare("SELECT COUNT(*) AS c FROM patient_records WHERE DATE(created_at) BETWEEN :s AND :e");
$patientsStmt->execute([':s'=>$start, ':e'=>$end]);
$totalPatients = (int)$patientsStmt->fetchColumn();

// Sales
$labSalesStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM lab_payments WHERE payment_status='completed' AND DATE(payment_date) BETWEEN :s AND :e");
$labSalesStmt->execute([':s'=>$start, ':e'=>$end]);
$labSales = (float)$labSalesStmt->fetchColumn();

// Pharmacy sales placeholder (no pharmacy sales table in current schema) => 0
$pharmacySales = 0.0;

// OPD sales
try {
    $opdSalesStmt = $db->prepare("SELECT COALESCE(SUM(amount_due),0) FROM opd_payments WHERE payment_status='paid' AND DATE(payment_date) BETWEEN :s AND :e");
    $opdSalesStmt->execute([':s'=>$start, ':e'=>$end]);
    $opdSales = (float)$opdSalesStmt->fetchColumn();
} catch (Exception $e) {
    $opdSales = 0.0;
}

// Discharge billings sales (from admission_billing)
$abSalesStmt = $db->prepare("SELECT COALESCE(SUM(total_due),0) FROM admission_billing WHERE payment_status='paid' AND DATE(updated_at) BETWEEN :s AND :e");
$abSalesStmt->execute([':s'=>$start, ':e'=>$end]);
$dischargeSales = (float)$abSalesStmt->fetchColumn();

$totalSales = $labSales + $opdSales + $dischargeSales + $pharmacySales;

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="hospital_stats_'.$start.'_to_'.$end.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric','Value']);
    if ($type === 'opd' || $type === 'all') fputcsv($out, ['Total OPD Patients', $opdCount]);
    if ($type === 'inpatients' || $type === 'all') fputcsv($out, ['Total Inpatients', $inpatientsCount]);
    if ($type === 'patients' || $type === 'all') fputcsv($out, ['Total Patients', $totalPatients]);
    if ($type === 'sales_lab' || $type === 'all') fputcsv($out, ['Total Lab Sales', number_format($labSales,2)]);
    if ($type === 'sales_discharge' || $type === 'all' || $type === 'sales_all') fputcsv($out, ['Total Discharge Sales', number_format($dischargeSales,2)]);
    if ($type === 'sales_pharmacy' || $type === 'all') fputcsv($out, ['Total Pharmacy Sales', number_format($pharmacySales,2)]);
    if ($type === 'sales_all' || $type === 'all') fputcsv($out, ['Total Sales (All Finance)', number_format($totalSales,2)]);
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hospital Statistics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Hospital Statistics</h3>
        <a href="dashboard.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
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
            <label class="form-label">Metric</label>
            <select name="type" class="form-select">
                <option value="all" <?php echo $type==='all'?'selected':''; ?>>All</option>
                <option value="opd" <?php echo $type==='opd'?'selected':''; ?>>Total OPD Patients</option>
                <option value="inpatients" <?php echo $type==='inpatients'?'selected':''; ?>>Total Inpatients</option>
                <option value="patients" <?php echo $type==='patients'?'selected':''; ?>>Total Patients</option>
                <option value="sales_all" <?php echo $type==='sales_all'?'selected':''; ?>>Total Sales (All Finance)</option>
                <option value="sales_lab" <?php echo $type==='sales_lab'?'selected':''; ?>>Total Lab Sales</option>
                <option value="sales_discharge" <?php echo $type==='sales_discharge'?'selected':''; ?>>Total Discharge Sales</option>
                <option value="sales_pharmacy" <?php echo $type==='sales_pharmacy'?'selected':''; ?>>Total Pharmacy Sales</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Apply</button>
            <a class="btn btn-success" href="?start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&type=<?php echo urlencode($type); ?>&export=csv"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <?php if ($type==='all' || $type==='opd'): ?>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">OPD Patients</div>
                <div class="fs-5">Total: <?php echo $opdCount; ?></div>
            </div></div>
        </div>
        <?php endif; ?>
        <?php if ($type==='all' || $type==='inpatients'): ?>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Inpatients</div>
                <div class="fs-5">Total: <?php echo $inpatientsCount; ?></div>
            </div></div>
        </div>
        <?php endif; ?>
        <?php if ($type==='all' || $type==='patients'): ?>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Patients</div>
                <div class="fs-5">Total: <?php echo $totalPatients; ?></div>
            </div></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php if ($type==='all' || $type==='sales_lab' || $type==='sales_all'): ?>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Lab Sales</div>
                <div class="fs-5">₱<?php echo number_format($labSales,2); ?></div>
            </div></div>
        </div>
        <?php endif; ?>
        <?php if ($type==='all' || $type==='sales_discharge' || $type==='sales_all'): ?>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Discharge Sales</div>
                <div class="fs-5">₱<?php echo number_format($dischargeSales,2); ?></div>
            </div></div>
        </div>
        <?php endif; ?>
        <?php if ($type==='all' || $type==='sales_pharmacy' || $type==='sales_all'): ?>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Pharmacy Sales</div>
                <div class="fs-5">₱<?php echo number_format($pharmacySales,2); ?></div>
            </div></div>
        </div>
        <?php endif; ?>
        <?php if ($type==='all' || $type==='sales_all'): ?>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted">Total Sales (All)</div>
                <div class="fs-5">₱<?php echo number_format($totalSales,2); ?></div>
            </div></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">Breakdown</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Metric</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($type==='all' || $type==='opd'): ?><tr><td>Total OPD Patients</td><td><?php echo $opdCount; ?></td></tr><?php endif; ?>
                        <?php if ($type==='all' || $type==='inpatients'): ?><tr><td>Total Inpatients</td><td><?php echo $inpatientsCount; ?></td></tr><?php endif; ?>
                        <?php if ($type==='all' || $type==='patients'): ?><tr><td>Total Patients</td><td><?php echo $totalPatients; ?></td></tr><?php endif; ?>
                        <?php if ($type==='all' || $type==='sales_lab' || $type==='sales_all'): ?><tr><td>Total Lab Sales</td><td>₱<?php echo number_format($labSales,2); ?></td></tr><?php endif; ?>
                        <?php if ($type==='all' || $type==='sales_discharge' || $type==='sales_all'): ?><tr><td>Total Discharge Sales</td><td>₱<?php echo number_format($dischargeSales,2); ?></td></tr><?php endif; ?>
                        <?php if ($type==='all' || $type==='sales_pharmacy' || $type==='sales_all'): ?><tr><td>Total Pharmacy Sales</td><td>₱<?php echo number_format($pharmacySales,2); ?></td></tr><?php endif; ?>
                        <?php if ($type==='all' || $type==='sales_all'): ?><tr class="table-active"><td class="fw-bold">Total Sales (All)</td><td class="fw-bold">₱<?php echo number_format($totalSales,2); ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
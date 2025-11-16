<?php
session_start();
require_once "config/database.php";
// Show errors to diagnose blank screen
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Lightweight encrypt fallback for this page (avoid heavy crypto dependency here)
if (!function_exists('bedview_encrypt')) {
	function bedview_encrypt($v) { return (string)$v; }
}

// Require logged-in staff
if (!isset($_SESSION['user_id'])) {
	header("Location: index.php");
	exit();
}

// Restrict certain roles from accessing bed view by token
$blocked_roles = ['finance','it','medical_staff','medical_technician','pharmacist','radiologist','receptionist'];
if (isset($_SESSION['employee_type']) && in_array($_SESSION['employee_type'], $blocked_roles)) {
	echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="2;url=dashboard.php">'
		. '<title>Access Restricted</title>'
		. '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">'
		. '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">'
		. '</head><body class="bg-light">'
		. '<div class="container py-5"><div class="alert alert-warning">'
		. '<i class="fas fa-exclamation-triangle me-2"></i>Access to bed view by token is restricted for your role. Redirecting to dashboard...'
		. '</div></div>'
		. '<script>setTimeout(function(){ window.location.href = "dashboard.php"; }, 2000);</script>'
		. '</body></html>';
	exit();
}

if (!isset($_GET['token'])) {
	die('Invalid link');
}

try {
	$database = new Database();
	$db = $database->getConnection();

	// Resolve token to bed
	$sql = "SELECT b.id as bed_id, b.bed_number, b.status as bed_status, r.room_number, r.room_type, r.floor_number
			FROM bed_profile_tokens t
			JOIN beds b ON t.bed_id = b.id
			JOIN rooms r ON b.room_id = r.id
			WHERE t.token = :token";
	$stmt = $db->prepare($sql);
	$stmt->bindParam(":token", $_GET['token']);
	$stmt->execute();
	$bed = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$bed) {
		die('Invalid or expired link');
	}

    // Find current occupant (if any). Resolve patient_record_id via the OPD visit that sourced the admission.
    $occ = $db->prepare("SELECT 
            pa.id AS admission_id,
            p.id AS patient_id,
            ov.patient_record_id AS patient_record_id,
            p.name AS patient_name,
            p.age,
            p.gender,
            pa.admission_date,
            p.doctor_id,
            p.phone,
            p.address
        FROM patient_admissions pa
        JOIN patients p ON pa.patient_id = p.id
        LEFT JOIN opd_visits ov ON ov.id = pa.source_visit_id
        WHERE pa.bed_id = :bid AND pa.admission_status = 'admitted'
        ORDER BY pa.created_at DESC LIMIT 1");
	$occ->bindParam(":bid", $bed['bed_id']);
	$occ->execute();
	$occupant = $occ->fetch(PDO::FETCH_ASSOC);

	// All doctors can view; ordering is enforced elsewhere (doctor_orders.php)
} catch (Exception $e) {
	error_log("bed_view error: " . $e->getMessage());
	echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
		. '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">'
		. '</head><body class="bg-light"><div class="container py-5">'
		. '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Server error loading bed view.'
		. '<div class="small text-muted mt-2">' . htmlspecialchars($e->getMessage()) . '</div>'
		. '</div></div></body></html>';
	exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Room <?php echo htmlspecialchars($bed['room_number']); ?> • Bed <?php echo htmlspecialchars($bed['bed_number']); ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<style>
		body { background: #f5f7fa; }
		.header-card { border: none; border-radius: 16px; box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
		.header-hero { background: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%); color: #fff; border-radius: 16px 16px 0 0; padding: 20px; }
		.badge-chip { padding: 8px 12px; border-radius: 20px; font-size: 0.85rem; }
		.status-badge { text-transform: capitalize; }
		.section-card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
		.empty-state { display: flex; align-items: center; gap: 12px; }
		.empty-icon { width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background: #f1f3f5; color: #6c757d; }
	</style>
</head>
<body>
	<div class="container py-4">
		<div class="card header-card">
			<div class="header-hero d-flex justify-content-between align-items-center">
				<div>
					<h4 class="mb-1"><i class="fas fa-bed me-2"></i>Room <?php echo htmlspecialchars($bed['room_number']); ?> • Bed <?php echo htmlspecialchars($bed['bed_number']); ?></h4>
					<div class="d-flex flex-wrap align-items-center gap-2">
						<span class="badge bg-light text-dark badge-chip"><i class="fas fa-layer-group me-1"></i>Floor <?php echo (int)$bed['floor_number']; ?></span>
						<span class="badge bg-light text-dark badge-chip"><i class="fas fa-door-open me-1"></i><?php echo ucwords(str_replace('_',' ', $bed['room_type'])); ?></span>
						<span class="badge <?php echo $bed['bed_status']==='available' ? 'bg-success' : ($bed['bed_status']==='occupied' ? 'bg-primary' : 'bg-secondary'); ?> badge-chip status-badge">
							<i class="fas fa-circle me-1"></i><?php echo ucfirst($bed['bed_status']); ?>
						</span>
					</div>
				</div>
				<div class="d-flex gap-2">
					<a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
					<?php if (isset($_SESSION['employee_type']) && in_array($_SESSION['employee_type'], ['admin_staff'])): ?>
					<button id="copyLink" class="btn btn-outline-light btn-sm" title="Copy Link"><i class="fas fa-link me-1"></i>Copy Link</button>
					<?php endif; ?>
				</div>
			</div>
			<div class="card-body">
                <?php if ($occupant): ?>
					<div class="row g-3">
						<div class="col-lg-6">
							<div class="card section-card h-100">
								<div class="card-body">
									<h5 class="mb-2"><i class="fas fa-user me-2 text-danger"></i><?php echo htmlspecialchars($occupant['patient_name']); ?></h5>
									<div class="text-muted mb-3">
										<span class="me-3"><i class="fas fa-birthday-cake me-1"></i><?php echo (int)$occupant['age']; ?>y</span>
										<span><i class="fas fa-venus-mars me-1"></i><?php echo ucfirst($occupant['gender']); ?></span>
									</div>
									<div class="small text-muted"><i class="fas fa-calendar-alt me-1"></i>Admitted: <?php echo date('M d, Y h:i A', strtotime($occupant['admission_date'])); ?></div>
                                    <?php if (isset($_SESSION['employee_type']) && in_array($_SESSION['employee_type'], ['doctor','general_doctor']) && intval($occupant['doctor_id']) !== intval($_SESSION['user_id'])): ?>
                                        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>You are not the assigned doctor for this patient. Orders are restricted to the assigned doctor.</div>
                                    <?php endif; ?>
                                    <div class="mt-3">
                                        <a href="admission_profile.php?admission_id=<?php echo intval($occupant['admission_id']); ?>" class="btn btn-danger btn-sm">
											<i class="fas fa-eye me-1"></i>View Admission Profile
										</a>
						<a href="<?php echo $occupant['patient_record_id'] ? ('patient_history.php?id=' . intval($occupant['patient_record_id'])) : '#'; ?>" class="btn btn-outline-danger btn-sm ms-2" <?php echo $occupant['patient_record_id'] ? '' : 'disabled title="Patient record not linked"'; ?>>
							<i class="fas fa-clock-rotate-left me-1"></i>View Patient History
						</a>
									</div>
								</div>
							</div>
						</div>
						<div class="col-lg-6">
							<div class="card section-card h-100">
								<div class="card-body">
									<h6 class="text-muted mb-2"><i class="fas fa-info-circle me-1"></i>Bed Details</h6>
									<ul class="list-unstyled mb-0">
										<li class="mb-2"><i class="fas fa-hashtag text-danger me-2"></i>Bed Number: <strong><?php echo htmlspecialchars($bed['bed_number']); ?></strong></li>
										<li class="mb-2"><i class="fas fa-door-open text-danger me-2"></i>Room: <strong><?php echo htmlspecialchars($bed['room_number']); ?></strong></li>
										<li class="mb-2"><i class="fas fa-layer-group text-danger me-2"></i>Floor: <strong><?php echo (int)$bed['floor_number']; ?></strong></li>
										<li><i class="fas fa-circle text-danger me-2"></i>Status: <strong><?php echo ucfirst($bed['bed_status']); ?></strong></li>
									</ul>
								</div>
							</div>
						</div>
					</div>
				<?php else: ?>
					<div class="card section-card">
						<div class="card-body">
							<div class="empty-state">
								<span class="empty-icon"><i class="fas fa-bed"></i></span>
								<div>
									<h5 class="mb-1">This bed is currently not occupied</h5>
									<div class="text-muted">Share this link with staff to reference Room <?php echo htmlspecialchars($bed['room_number']); ?>, Bed <?php echo htmlspecialchars($bed['bed_number']); ?>.</div>
								</div>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const copyBtn = document.getElementById('copyLink');
		if (copyBtn) {
			copyBtn.addEventListener('click', function() {
				const original = copyBtn.innerHTML;
				copyBtn.disabled = true;
				copyBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Copying...';
				navigator.clipboard.writeText(window.location.href)
					.then(() => {
						copyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Copied';
						setTimeout(() => { copyBtn.innerHTML = original; copyBtn.disabled = false; }, 1200);
					})
					.catch(() => {
						alert('Failed to copy link');
						copyBtn.innerHTML = original;
						copyBtn.disabled = false;
					});
			});
		}
	});
	</script>
</body>
</html> 
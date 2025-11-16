<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
	header("Location: index.php");
	exit();
}

$isMedicalRecords = (($_SESSION['employee_type'] ?? '') === 'medical_records') || (($_SESSION['role'] ?? '') === 'supra_admin');

$database = new Database();
$db = $database->getConnection();

$error_message = '';
$success_message = '';

// Ensure table exists (idempotent)
try {
	$db->exec("CREATE TABLE IF NOT EXISTS death_declarations (
		id INT AUTO_INCREMENT PRIMARY KEY,
		admission_id INT NOT NULL,
		patient_id INT NOT NULL,
		declared_by INT NOT NULL,
		declared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		time_of_death DATETIME NOT NULL,
		cause_of_death TEXT NOT NULL,
		notes TEXT NULL,
		status ENUM('pending','reviewed') NOT NULL DEFAULT 'pending',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (admission_id) REFERENCES patient_admissions(id) ON DELETE CASCADE,
		FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
		FOREIGN KEY (declared_by) REFERENCES users(id) ON DELETE RESTRICT
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { $error_message = "Setup error: " . $e->getMessage(); }

// Handle deletes (unified patients)
if ($isMedicalRecords && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_patient_entry') {
	try {
		$pid = isset($_POST['patient_id']) && $_POST['patient_id'] !== '' ? (int)$_POST['patient_id'] : null;
		$prid = isset($_POST['patient_record_id']) && $_POST['patient_record_id'] !== '' ? (int)$_POST['patient_record_id'] : null;
		if ($pid === null && $prid === null) {
			throw new Exception('Invalid patient reference.');
		}
		if ($prid !== null) {
			$del = $db->prepare("DELETE FROM patient_records WHERE id = :id");
			$del->execute([':id' => $prid]);
			$success_message = "OPD patient record deleted.";
		} else {
			$del = $db->prepare("DELETE FROM patients WHERE id = :id");
			$del->execute([':id' => $pid]);
			$success_message = "Inpatient record deleted.";
		}
	} catch (Exception $e) {
		$error_message = "Delete error: " . $e->getMessage();
	}
}

// Handle "mark reviewed" for death declarations
if ($isMedicalRecords && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_reviewed') {
	try {
		$rid = (int)($_POST['id'] ?? 0);
		if ($rid <= 0) { throw new Exception('Invalid record id.'); }
		$upd = $db->prepare("UPDATE death_declarations SET status = 'reviewed' WHERE id = :id");
		$upd->execute([':id' => $rid]);
		$success_message = "Death record marked as reviewed.";
	} catch (Exception $e) {
		$error_message = "Update error: " . $e->getMessage();
	}
}

// Load records
$rows = [];
try {
	$stmt = $db->prepare("
		SELECT dd.*,
			   p.name AS patient_name, p.age, p.gender,
			   u.name AS declared_by_name,
			   r.room_number, b.bed_number
		FROM death_declarations dd
		JOIN patients p ON dd.patient_id = p.id
		LEFT JOIN patient_admissions pa ON dd.admission_id = pa.id
		LEFT JOIN rooms r ON pa.room_id = r.id
		LEFT JOIN beds b ON pa.bed_id = b.id
		LEFT JOIN users u ON dd.declared_by = u.id
		ORDER BY FIELD(dd.status, 'pending','reviewed'), dd.created_at DESC
	");
	$stmt->execute();
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
	if ($error_message === '') $error_message = "Load error: " . $e->getMessage();
}

// Build unified patient registry (OPD + Inpatient), deduplicated by name+phone
function mr_normalize_phone($value) {
	$s = (string)$value;
	$digits = preg_replace('/\D+/', '', $s);
	if ($digits === '') return '';
	if (strpos($digits, '63') === 0 && strlen($digits) === 12) $digits = substr($digits, 2);
	if (strlen($digits) === 11 && $digits[0] === '0') $digits = substr($digits, 1);
	if (strlen($digits) === 10) return $digits;
	return $digits;
}
function mr_format_phone($value) {
	$d = mr_normalize_phone($value);
	if ($d === '') return '';
	return '+63' . $d;
}

$uniquePatients = []; // key => aggregated record
try {
	// OPD patient records (encrypted)
	$pr = $db->prepare("SELECT id, patient_name, age, gender, contact_number, address, created_at FROM patient_records ORDER BY created_at DESC");
	$pr->execute();
	while ($r = $pr->fetch(PDO::FETCH_ASSOC)) {
		$nameDec = decrypt_safe(decrypt_safe((string)$r['patient_name']));
		$phoneDec = decrypt_safe(decrypt_safe((string)$r['contact_number']));
		$addrDec = decrypt_safe(decrypt_safe((string)$r['address']));
		$key = mb_strtolower(trim($nameDec), 'UTF-8') . '|' . mr_normalize_phone($phoneDec);
		if (!isset($uniquePatients[$key])) {
			$uniquePatients[$key] = [
				'display_name' => $nameDec,
				'gender' => $r['gender'],
				'age' => (int)$r['age'],
				'phone' => mr_format_phone($phoneDec),
				'address' => $addrDec,
				'has_opd' => true,
				'has_inpatient' => false,
				'patient_record_id' => (int)$r['id'],
				'patient_id' => null,
				'created_at' => $r['created_at'],
				'has_death' => false,
			];
		} else {
			// Preserve earliest created_at if needed
			if (strtotime($r['created_at']) < strtotime($uniquePatients[$key]['created_at'])) {
				$uniquePatients[$key]['created_at'] = $r['created_at'];
			}
			$uniquePatients[$key]['has_opd'] = true;
			$uniquePatients[$key]['patient_record_id'] = $uniquePatients[$key]['patient_record_id'] ?: (int)$r['id'];
		}
	}
} catch (Exception $e) {
	if ($error_message === '') $error_message = "OPD load error: " . $e->getMessage();
}
try {
	// Inpatient registry
	$pt = $db->prepare("SELECT id, name, phone, address, age, gender, created_at FROM patients ORDER BY created_at DESC");
	$pt->execute();
	while ($p = $pt->fetch(PDO::FETCH_ASSOC)) {
		$key = mb_strtolower(trim((string)$p['name']), 'UTF-8') . '|' . mr_normalize_phone((string)$p['phone']);
		if (!isset($uniquePatients[$key])) {
			$uniquePatients[$key] = [
				'display_name' => (string)$p['name'],
				'gender' => (string)$p['gender'],
				'age' => (int)$p['age'],
				'phone' => mr_format_phone((string)$p['phone']),
				'address' => (string)$p['address'],
				'has_opd' => false,
				'has_inpatient' => true,
				'patient_record_id' => null,
				'patient_id' => (int)$p['id'],
				'created_at' => (string)$p['created_at'],
				'has_death' => false,
			];
		} else {
			$uniquePatients[$key]['has_inpatient'] = true;
			$uniquePatients[$key]['patient_id'] = $uniquePatients[$key]['patient_id'] ?: (int)$p['id'];
		}
	}
} catch (Exception $e) {
	if ($error_message === '') $error_message = "Inpatient load error: " . $e->getMessage();
}
// Mark entries that have death declarations (via patients table ids)
try {
	$dq = $db->query("SELECT DISTINCT patient_id FROM death_declarations");
	$deadIds = [];
	while ($row = $dq->fetch(PDO::FETCH_ASSOC)) {
		if (isset($row['patient_id'])) $deadIds[(int)$row['patient_id']] = true;
	}
	foreach ($uniquePatients as &$u) {
		if (!empty($u['patient_id']) && isset($deadIds[$u['patient_id']])) {
			$u['has_death'] = true;
		}
	}
	unset($u);
} catch (Exception $e) { /* best-effort */ }

// Mark newborn patient_records (from birth certificates)
try {
	$nb_stmt = $db->query("SELECT newborn_patient_record_id FROM birth_certificates WHERE newborn_patient_record_id IS NOT NULL");
	$newbornMap = [];
	while ($row = $nb_stmt->fetch(PDO::FETCH_ASSOC)) {
		if (!empty($row['newborn_patient_record_id'])) {
			$newbornMap[(int)$row['newborn_patient_record_id']] = true;
		}
	}
	foreach ($uniquePatients as &$u) {
		$u['is_newborn'] = (!empty($u['patient_record_id']) && isset($newbornMap[(int)$u['patient_record_id']]));
	}
	unset($u);
} catch (Exception $e) {
	// ignore
}

// No per-patient review state needed now
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Manage Records - SmartCare</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<style>
		body { background: #f5f7fa; }
		.card-lite { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
		.badge-pill { border-radius: 20px; padding: 4px 10px; font-size: .8rem; }
	</style>
	</head>
<body>
	<nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);">
		<div class="container">
			<a class="navbar-brand" href="dashboard.php">
				<i class="fas fa-hospital me-2"></i>SmartCare
			</a>
			<div class="ms-auto">
				<a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
			</div>
		</div>
	</nav>

	<div class="container py-4">
		<!-- Page Header -->
		<div class="d-flex justify-content-between align-items-center mb-3">
			<h3 class="mb-0"><i class="fas fa-file-medical me-2"></i>Manage Records</h3>
			<div class="d-flex align-items-center gap-3">
				<span class="text-muted small"><?php echo count($uniquePatients); ?> record<?php echo count($uniquePatients) === 1 ? '' : 's'; ?></span>
				<?php if (!$isMedicalRecords): ?>
					<span class="badge bg-secondary">Read-only</span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Unified Patient Registry -->
		<div class="card card-lite mb-4">
			<div class="card-body">
				<div class="row g-2 mb-3">
					<div class="col-md-6">
						<div class="input-group">
							<span class="input-group-text"><i class="fas fa-search"></i></span>
							<input type="text" id="patientSearch" class="form-control" placeholder="Search patients by name, phone, address...">
							<button class="btn btn-outline-secondary" type="button" id="clearPatientSearch" title="Clear" style="display:none;">
								<i class="fas fa-times"></i>
							</button>
						</div>
					</div>
				</div>
				<?php if (empty($uniquePatients)): ?>
					<div class="text-center text-muted">No patients found.</div>
				<?php else: ?>
					<div class="list-group" id="patientsList">
						<?php foreach ($uniquePatients as $u): ?>
							<div class="list-group-item d-flex justify-content-between align-items-start">
								<div>
									<div class="fw-semibold"><?php echo htmlspecialchars($u['display_name']); ?></div>
									<div class="text-muted small">
										<?php if (!empty($u['age'])): ?><span class="me-2"><?php echo (int)$u['age']; ?>y</span><?php endif; ?>
										<?php if (!empty($u['gender'])): ?><span class="me-2"><?php echo ucfirst($u['gender']); ?></span><?php endif; ?>
										<?php if (!empty($u['phone'])): ?><span class="me-2"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($u['phone']); ?></span><?php endif; ?>
										<?php if (!empty($u['address'])): ?><span class="me-2"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($u['address']); ?></span><?php endif; ?>
									</div>
									<div class="mt-1">
										<?php if ($u['has_inpatient']): ?>
											<span class="badge bg-info text-dark me-1">Inpatient</span>
										<?php endif; ?>
										<?php if (!empty($u['is_newborn'])): ?>
											<span class="badge bg-pink text-dark me-1" style="background:#f8d7da;color:#842029;">Newborn</span>
										<?php endif; ?>
										<?php if ($u['has_death']): ?>
											<span class="badge bg-dark">Declared Deceased</span>
										<?php endif; ?>
									</div>
								</div>
								<div class="text-end">
									<?php if (!empty($u['patient_record_id'])): ?>
										<a href="opd_registration.php?patient_id=<?php echo (int)$u['patient_record_id']; ?>" class="btn btn-outline-primary btn-sm me-1">
											<i class="fas fa-plus me-1"></i>Add Visit
										</a>
										<a href="patient_history.php?id=<?php echo (int)$u['patient_record_id']; ?>" class="btn btn-outline-secondary btn-sm me-1">
											<i class="fas fa-history me-1"></i>Manage
										</a>
									<?php endif; ?>
									<?php if (!empty($u['patient_id'])): ?>
										<a href="admit_patient.php" class="btn btn-outline-primary btn-sm me-1">
											<i class="fas fa-bed me-1"></i>Admit
										</a>
									<?php endif; ?>
									<?php if ($isMedicalRecords): ?>
										<form method="POST" class="d-inline" onsubmit="return confirm('Delete this record? This action cannot be undone.');">
											<input type="hidden" name="action" value="delete_patient_entry">
											<input type="hidden" name="patient_id" value="<?php echo htmlspecialchars((string)($u['patient_id'] ?? '')); ?>">
											<input type="hidden" name="patient_record_id" value="<?php echo htmlspecialchars((string)($u['patient_record_id'] ?? '')); ?>">
											<button type="submit" class="btn btn-sm btn-danger ms-1">
												<i class="fas fa-trash me-1"></i>Delete
											</button>
										</form>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Death Declarations -->
		<div class="row g-2 mb-3">
			<div class="col-md-6">
				<div class="input-group">
					<span class="input-group-text"><i class="fas fa-search"></i></span>
					<input type="text" id="recordsSearch" class="form-control" placeholder="Search declared deaths by patient, doctor, room, notes...">
					<button class="btn btn-outline-secondary" type="button" id="clearRecordsSearch" title="Clear" style="display:none;">
						<i class="fas fa-times"></i>
					</button>
				</div>
			</div>
		</div>

		<?php if (!empty($_GET['success'])): ?>
			<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Record received from ward.</div>
		<?php endif; ?>
		<?php if ($success_message): ?>
			<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?></div>
		<?php endif; ?>
		<?php if ($error_message): ?>
			<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
		<?php endif; ?>

		<?php if (!$isMedicalRecords): ?>
			<div class="alert alert-warning">
				<i class="fas fa-exclamation-triangle me-2"></i>
				This page is intended for Medical Records staff. You are viewing in read-only mode.
			</div>
		<?php endif; ?>

		<?php if (empty($rows)): ?>
			<div class="card card-lite">
				<div class="card-body text-center text-muted py-5">
					<i class="fas fa-folder-open fa-2x mb-3"></i>
					<div>No records to display.</div>
					<div class="small mt-2">When a doctor declares a death in Admission Profile, it will appear here.</div>
				</div>
			</div>
		<?php else: ?>
			<div class="accordion" id="recordsAccordion">
				<?php foreach ($rows as $i => $r): ?>
					<div class="card card-lite mb-3">
						<div class="card-body">
							<div class="d-flex justify-content-between align-items-start">
								<div>
									<h5 class="mb-1"><i class="fas fa-user me-2"></i><?php echo htmlspecialchars($r['patient_name']); ?></h5>
									<div class="text-muted small mb-2">
										<span class="me-3"><i class="fas fa-venus-mars me-1"></i><?php echo ucfirst($r['gender']); ?></span>
										<span class="me-3"><i class="fas fa-birthday-cake me-1"></i><?php echo (int)$r['age']; ?>y</span>
										<?php if (!empty($r['room_number']) || !empty($r['bed_number'])): ?>
											<span><i class="fas fa-bed me-1"></i>Room <?php echo htmlspecialchars($r['room_number'] ?? '-'); ?>, Bed <?php echo htmlspecialchars($r['bed_number'] ?? '-'); ?></span>
										<?php endif; ?>
									</div>
									<div class="text-muted small">
										<span class="me-3"><i class="fas fa-clock me-1"></i>Declared: <?php echo date('M d, Y h:i A', strtotime($r['declared_at'])); ?></span>
										<span class="me-3"><i class="fas fa-hourglass-end me-1"></i>Time of Death: <?php echo date('M d, Y h:i A', strtotime($r['time_of_death'])); ?></span>
										<span class="me-3"><i class="fas fa-user-md me-1"></i>By: <?php echo htmlspecialchars($r['declared_by_name'] ?: 'Doctor'); ?></span>
									</div>
								</div>
								<div class="text-end">
									<span class="badge badge-pill bg-<?php echo $r['status']==='pending' ? 'warning' : 'secondary'; ?>">
										<?php echo ucfirst($r['status']); ?>
									</span>
									<?php if ($isMedicalRecords && $r['status'] === 'pending'): ?>
									<form method="POST" class="mt-2">
										<input type="hidden" name="action" value="mark_reviewed">
										<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
										<button type="submit" class="btn btn-sm btn-success">
											<i class="fas fa-check me-1"></i>Mark Reviewed
										</button>
									</form>
									<?php endif; ?>
								</div>
							</div>

							<div class="mt-3">
								<button class="accordion-button collapsed px-0" type="button" data-bs-toggle="collapse" data-bs-target="#rec<?php echo $i; ?>">
									<div>
										<strong><i class="fas fa-info-circle me-2"></i>Details</strong>
										<div class="text-muted small">Click to expand</div>
									</div>
								</button>
								<div id="rec<?php echo $i; ?>" class="accordion-collapse collapse" data-bs-parent="#recordsAccordion">
									<div class="pt-3">
										<div class="row g-3">
											<div class="col-md-6">
												<div class="card">
													<div class="card-header bg-light"><strong>Cause of Death</strong></div>
													<div class="card-body"><?php echo nl2br(htmlspecialchars($r['cause_of_death'])); ?></div>
												</div>
											</div>
											<div class="col-md-6">
												<div class="card">
													<div class="card-header bg-light"><strong>Notes</strong></div>
													<div class="card-body"><?php echo $r['notes'] ? nl2br(htmlspecialchars($r['notes'])) : '<span class="text-muted">None</span>'; ?></div>
												</div>
											</div>
										</div>
										<div class="mt-3">
											<a href="admission_profile.php?admission_id=<?php echo (int)$r['admission_id']; ?>" class="btn btn-outline-primary btn-sm">
												<i class="fas fa-external-link-alt me-1"></i>Open Admission Profile
											</a>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	(function(){
		function setupFilter(inputId, clearId, containerSelector, itemSelector) {
			var input = document.getElementById(inputId);
			var clearBtn = document.getElementById(clearId);
			var container = document.querySelector(containerSelector);
			if (!input || !container) return;
			function apply() {
				var q = (input.value || '').toLowerCase().trim();
				if (clearBtn) clearBtn.style.display = q ? '' : 'none';
				var items = container.querySelectorAll(itemSelector);
				var anyVisible = false;
				items.forEach(function(el){
					var text = (el.textContent || '').toLowerCase();
					var vis = !q || text.indexOf(q) !== -1;
					el.style.display = vis ? '' : 'none';
					if (vis) anyVisible = true;
				});
			}
			input.addEventListener('input', function(){ apply(); });
			if (clearBtn) {
				clearBtn.addEventListener('click', function(){
					input.value = '';
					apply();
					input.focus();
				});
			}
		}
		// Patients list
		setupFilter('patientSearch','clearPatientSearch','#patientsList','.list-group-item');
		// Death declarations list (cards under accordion)
		setupFilter('recordsSearch','clearRecordsSearch','#recordsAccordion','>.card');
	})();
	</script>
</body>
</html>


<?php
session_start();
require_once "config/database.php";
if (!defined('INCLUDED_IN_PAGE')) {
	define('INCLUDED_IN_PAGE', true);
}
require_once "includes/crypto.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'medical_records') {
	header("Location: index.php");
	exit();
}

$database = new Database();
$db = $database->getConnection();

try {
	$colCheck = $db->prepare("SHOW COLUMNS FROM birth_certificates LIKE 'newborn_patient_record_id'");
	$colCheck->execute();
	if (!$colCheck->fetch()) {
		$db->exec("ALTER TABLE birth_certificates ADD COLUMN newborn_patient_record_id INT NULL");
	}
} catch (Exception $e) {
	// best effort
}

$success_message = $error_message = '';

function ensure_newborn_patient_record(PDO $db, int $certificateId): int {
	$bc_stmt = $db->prepare("SELECT 
			bc.*, 
			p.phone AS mother_phone,
			p.address AS mother_address
		FROM birth_certificates bc
		LEFT JOIN patients p ON bc.patient_id = p.id
		WHERE bc.id = :id");
	$bc_stmt->bindParam(':id', $certificateId);
	$bc_stmt->execute();
	$bc = $bc_stmt->fetch(PDO::FETCH_ASSOC);
	if (!$bc) {
		throw new Exception('Birth certificate not found.');
	}

	if (!empty($bc['newborn_patient_record_id'])) {
		return (int)$bc['newborn_patient_record_id'];
	}

	$babyName = trim((string)$bc['newborn_name']);
	if ($babyName === '') {
		$babyName = 'Baby of ' . trim((string)$bc['mother_name']);
		if (!empty($bc['sex'])) {
			$babyName .= ' (' . ucfirst($bc['sex']) . ')';
		}
	}

	$nameHash = hash('sha256', $babyName);
	$existing = $db->prepare("SELECT id FROM patient_records WHERE name_exact_hash = :hash LIMIT 1");
	$existing->bindParam(':hash', $nameHash);
	$existing->execute();
	$newbornId = $existing->fetchColumn();

	if (!$newbornId) {
		$contact = trim((string)($bc['mother_phone'] ?? ''));
		if ($contact === '') { $contact = 'N/A'; }
		$address = trim((string)($bc['mother_address'] ?? ''));
		if ($address === '') { $address = 'N/A'; }
		$encName = encrypt_strict($babyName);
		$encContact = encrypt_strict($contact);
		$encAddress = encrypt_strict($address);
		$gender = strtolower((string)$bc['sex']);
		if (!in_array($gender, ['male','female','other'], true)) {
			$gender = 'other';
		}
		$age = 0;
		if (!empty($bc['date_of_birth'])) {
			try {
				$dob = new DateTime($bc['date_of_birth']);
				$now = new DateTime();
				$age = max(0, (int)$dob->diff($now)->y);
			} catch (Exception $e) {
				$age = 0;
			}
		}

		$insert = $db->prepare("INSERT INTO patient_records (patient_name, age, gender, contact_number, address, created_at, name_exact_hash)
			VALUES (:name, :age, :gender, :contact, :address, NOW(), :hash)");
		$insert->execute([
			':name' => $encName,
			':age' => $age,
			':gender' => $gender,
			':contact' => $encContact,
			':address' => $encAddress,
			':hash' => $nameHash
		]);
		$newbornId = (int)$db->lastInsertId();
	} else {
		$newbornId = (int)$newbornId;
	}

	$upd = $db->prepare("UPDATE birth_certificates SET newborn_patient_record_id = :pid WHERE id = :id");
	$upd->execute([
		':pid' => $newbornId,
		':id' => $certificateId
	]);

	return $newbornId;
}

// Current filter
$filter = isset($_GET['status']) ? $_GET['status'] : 'submitted';
$valid_filters = ['submitted','approved','rejected','all'];
if (!in_array($filter, $valid_filters, true)) { $filter = 'submitted'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
	try {
		$action = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
		$stmt = $db->prepare("UPDATE birth_certificates SET status = :status, reviewed_by = :uid, reviewed_at = NOW(), review_note = :note WHERE id = :id AND status = 'submitted'");
		$stmt->bindParam(':status', $action);
		$stmt->bindParam(':uid', $_SESSION['user_id']);
		$stmt->bindParam(':note', $_POST['review_note']);
		$stmt->bindParam(':id', $_POST['id']);
		$stmt->execute();
		if ($stmt->rowCount() === 0) {
			throw new Exception('Record not in submitted state or not found.');
		}
		if ($action === 'approved') {
			ensure_newborn_patient_record($db, (int)$_POST['id']);
		}
		// Redirect to preserve filter and refresh list
		header('Location: medical_records_birth_certificates.php?status=' . urlencode($filter));
		exit();
	} catch (Exception $e) {
		$error_message = 'Error: ' . $e->getMessage();
	}
}

// Counts per status for tabs
$count_stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM birth_certificates GROUP BY status");
$count_stmt->execute();
$counts = ['submitted'=>0,'approved'=>0,'rejected'=>0];
foreach ($counts as $k=>$_) { $counts[$k] = 0; }
while ($row = $count_stmt->fetch(PDO::FETCH_ASSOC)) {
	$counts[$row['status']] = (int)$row['cnt'];
}
$total_all = array_sum($counts);

// Load birth certificates with optional filter
$where = '';
$params = [];
if ($filter !== 'all') { $where = "WHERE bc.status = :f"; $params[':f'] = $filter; }

$list_sql = "SELECT bc.*, p.name AS patient_name, u.name AS submitted_by_name,
	pa.id AS pa_id, r.room_number, b.bed_number
	FROM birth_certificates bc
	JOIN patients p ON bc.patient_id = p.id
	JOIN users u ON bc.submitted_by = u.id
	LEFT JOIN patient_admissions pa ON bc.admission_id = pa.id
	LEFT JOIN rooms r ON pa.room_id = r.id
	LEFT JOIN beds b ON pa.bed_id = b.id
	" . $where . "
	ORDER BY FIELD(bc.status,'submitted','approved','rejected'), bc.submitted_at DESC";
$list_stmt = $db->prepare($list_sql);
foreach ($params as $k=>$v) { $list_stmt->bindValue($k, $v); }
$list_stmt->execute();
$records = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Medical Records - Birth Certificates';
if (!defined('INCLUDED_IN_PAGE')) {
	define('INCLUDED_IN_PAGE', true);
}
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Birth Certificates</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h2><i class="fas fa-file-signature me-2"></i>Birth Certificates</h2>
	</div>
	<?php if ($success_message): ?>
		<div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
	<?php endif; ?>
	<?php if ($error_message): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
	<?php endif; ?>

	<ul class="nav nav-tabs mb-3">
		<li class="nav-item">
			<a class="nav-link <?php echo $filter==='submitted'?'active':''; ?>" href="?status=submitted">
				Submitted <span class="badge bg-secondary"><?php echo (int)$counts['submitted']; ?></span>
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link <?php echo $filter==='approved'?'active':''; ?>" href="?status=approved">
				Approved <span class="badge bg-secondary"><?php echo (int)$counts['approved']; ?></span>
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link <?php echo $filter==='rejected'?'active':''; ?>" href="?status=rejected">
				Rejected <span class="badge bg-secondary"><?php echo (int)$counts['rejected']; ?></span>
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link <?php echo $filter==='all'?'active':''; ?>" href="?status=all">
				All <span class="badge bg-secondary"><?php echo (int)$total_all; ?></span>
			</a>
		</li>
	</ul>

	<div class="card">
		<div class="card-body">
			<div class="table-responsive">
				<table class="table table-hover align-middle">
					<thead>
						<tr>
							<th>Submitted At</th>
							<th>Patient</th>
							<th>Mother</th>
							<th>Newborn</th>
							<th>Sex</th>
							<th>DoB / ToB</th>
							<th>Status</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($records as $r): ?>
						<tr>
							<td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($r['submitted_at']))); ?></td>
							<td><?php echo htmlspecialchars($r['patient_name']); ?></td>
							<td><?php echo htmlspecialchars($r['mother_name']); ?></td>
							<td><?php echo htmlspecialchars($r['newborn_name']); ?></td>
							<td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($r['sex'])); ?></span></td>
							<td><?php echo htmlspecialchars($r['date_of_birth'] . ' ' . $r['time_of_birth']); ?></td>
							<td>
								<?php if ($r['status'] === 'submitted'): ?>
									<span class="badge bg-warning text-dark">Submitted</span>
								<?php elseif ($r['status'] === 'approved'): ?>
									<span class="badge bg-success">Approved</span>
								<?php else: ?>
									<span class="badge bg-danger">Rejected</span>
								<?php endif; ?>
							</td>
							<td>
								<div class="d-flex gap-2">
									<button class="btn btn-sm btn-info" onclick="openView(this)"
										data-patient="<?php echo htmlspecialchars($r['patient_name']); ?>"
										data-mother="<?php echo htmlspecialchars($r['mother_name']); ?>"
										data-father="<?php echo htmlspecialchars($r['father_name']); ?>"
										data-newborn="<?php echo htmlspecialchars($r['newborn_name']); ?>"
										data-sex="<?php echo htmlspecialchars($r['sex']); ?>"
										data-dob="<?php echo htmlspecialchars($r['date_of_birth']); ?>"
										data-tob="<?php echo htmlspecialchars($r['time_of_birth']); ?>"
										data-place="<?php echo htmlspecialchars($r['place_of_birth']); ?>"
										data-weight="<?php echo htmlspecialchars($r['birth_weight_kg']); ?>"
										data-length="<?php echo htmlspecialchars($r['birth_length_cm']); ?>"
										data-type="<?php echo htmlspecialchars($r['type_of_birth']); ?>"
										data-attendant="<?php echo htmlspecialchars($r['attendant_at_birth']); ?>"
										data-remarks="<?php echo htmlspecialchars($r['remarks']); ?>"
										data-room="<?php echo htmlspecialchars($r['room_number']); ?>"
										data-bed="<?php echo htmlspecialchars($r['bed_number']); ?>"
										data-submitter="<?php echo htmlspecialchars($r['submitted_by_name']); ?>"
										data-submittedat="<?php echo htmlspecialchars($r['submitted_at']); ?>"
										data-status="<?php echo htmlspecialchars($r['status']); ?>">
										<i class="fas fa-eye me-1"></i>View
									</button>
									<?php if ($r['status'] === 'submitted'): ?>
										<button class="btn btn-sm btn-success" onclick="openReview(<?php echo $r['id']; ?>,'approve')"><i class="fas fa-check me-1"></i>Approve</button>
										<button class="btn btn-sm btn-danger" onclick="openReview(<?php echo $r['id']; ?>,'reject')"><i class="fas fa-times me-1"></i>Reject</button>
									<?php else: ?>
										<button class="btn btn-sm btn-outline-secondary" disabled>Reviewed</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="reviewModal" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<form method="POST">
				<input type="hidden" name="id" id="review_id">
				<input type="hidden" name="action" id="review_action">
				<div class="modal-header">
					<h5 class="modal-title">Review Birth Certificate</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<div class="mb-3">
						<label class="form-label">Review Note (optional)</label>
						<textarea class="form-control" name="review_note" rows="3"></textarea>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Submit</button>
				</div>
			</form>
		</div>
	</div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Birth Certificate Details</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<div class="row g-3">
					<div class="col-md-6"><strong>Patient:</strong> <span id="vm_patient"></span></div>
					<div class="col-md-6"><strong>Status:</strong> <span id="vm_status" class="badge"></span></div>
					<div class="col-md-6"><strong>Mother:</strong> <span id="vm_mother"></span></div>
					<div class="col-md-6"><strong>Father:</strong> <span id="vm_father"></span></div>
					<div class="col-md-4"><strong>Newborn:</strong> <span id="vm_newborn"></span></div>
					<div class="col-md-4"><strong>Sex:</strong> <span id="vm_sex"></span></div>
					<div class="col-md-4"><strong>Type of Birth:</strong> <span id="vm_type"></span></div>
					<div class="col-md-4"><strong>Date of Birth:</strong> <span id="vm_dob"></span></div>
					<div class="col-md-4"><strong>Time of Birth:</strong> <span id="vm_tob"></span></div>
					<div class="col-md-4"><strong>Place of Birth:</strong> <span id="vm_place"></span></div>
					<div class="col-md-4"><strong>Weight (kg):</strong> <span id="vm_weight"></span></div>
					<div class="col-md-4"><strong>Length (cm):</strong> <span id="vm_length"></span></div>
					<div class="col-md-4"><strong>Attendant:</strong> <span id="vm_attendant"></span></div>
					<div class="col-md-6"><strong>Room / Bed:</strong> <span id="vm_location"></span></div>
					<div class="col-md-6"><strong>Submitted By:</strong> <span id="vm_submitter"></span></div>
					<div class="col-md-12"><strong>Submitted At:</strong> <span id="vm_submittedat"></span></div>
					<div class="col-12">
						<strong>Remarks:</strong>
						<div id="vm_remarks" class="border rounded p-2 bg-light"></div>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let reviewModal;
let viewModal;
document.addEventListener('DOMContentLoaded', function(){
	reviewModal = new bootstrap.Modal(document.getElementById('reviewModal'));
	viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
});
function openReview(id, action){
	document.getElementById('review_id').value = id;
	document.getElementById('review_action').value = action === 'approve' ? 'approve' : 'reject';
	reviewModal.show();
}
function openView(el){
	const d = el.dataset;
	const set = (id, val) => { document.getElementById(id).textContent = val && val.trim() !== '' ? val : '-'; };
	set('vm_patient', d.patient || '-');
	set('vm_mother', d.mother || '-');
	set('vm_father', d.father || '-');
	set('vm_newborn', d.newborn || '-');
	set('vm_sex', d.sex ? (d.sex.charAt(0).toUpperCase()+d.sex.slice(1)) : '-');
	set('vm_type', d.type ? (d.type.charAt(0).toUpperCase()+d.type.slice(1)) : '-');
	set('vm_dob', d.dob || '-');
	set('vm_tob', d.tob || '-');
	set('vm_place', d.place || '-');
	set('vm_weight', d.weight || '-');
	set('vm_length', d.length || '-');
	set('vm_attendant', d.attendant || '-');
	set('vm_location', (d.room||'-') + ' / ' + (d.bed||'-'));
	set('vm_submitter', d.submitter || '-');
	set('vm_submittedat', d.submittedat || '-');
	const statusEl = document.getElementById('vm_status');
	statusEl.textContent = d.status || '-';
	statusEl.className = 'badge ' + (d.status === 'approved' ? 'bg-success' : (d.status === 'rejected' ? 'bg-danger' : 'bg-warning text-dark'));
	document.getElementById('vm_remarks').textContent = d.remarks && d.remarks.trim() !== '' ? d.remarks : '—';
	viewModal.show();
}
</script>
</body>
</html> 
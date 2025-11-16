<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
	header("Location: index.php");
	exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Load admitted mothers for selection (pregnancy/labor rooms)
$patients_stmt = $db->prepare("SELECT pa.id as admission_id, p.id as patient_id, p.name as patient_name, r.room_number, b.bed_number
	FROM patient_admissions pa
	JOIN patients p ON pa.patient_id = p.id
	LEFT JOIN rooms r ON pa.room_id = r.id
	LEFT JOIN beds b ON pa.bed_id = b.id
	WHERE pa.admission_status = 'admitted'
	ORDER BY p.name ASC");
$patients_stmt->execute();
$admissions = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		$required = ['patient_id','admission_id','mother_name','sex','date_of_birth','time_of_birth','place_of_birth'];
		foreach ($required as $field) {
			if (empty($_POST[$field])) {
				throw new Exception('Missing required field: ' . $field);
			}
		}

		$sql = "INSERT INTO birth_certificates (
			patient_id, admission_id, mother_name, father_name, newborn_name, sex,
			date_of_birth, time_of_birth, place_of_birth, birth_weight_kg, birth_length_cm,
			type_of_birth, attendant_at_birth, remarks, submitted_by
		) VALUES (
			:patient_id, :admission_id, :mother_name, :father_name, :newborn_name, :sex,
			:date_of_birth, :time_of_birth, :place_of_birth, :birth_weight_kg, :birth_length_cm,
			:type_of_birth, :attendant_at_birth, :remarks, :submitted_by
		)";
		$stmt = $db->prepare($sql);
		$stmt->bindParam(':patient_id', $_POST['patient_id']);
		$stmt->bindParam(':admission_id', $_POST['admission_id']);
		$stmt->bindParam(':mother_name', $_POST['mother_name']);
		$stmt->bindParam(':father_name', $_POST['father_name']);
		$stmt->bindParam(':newborn_name', $_POST['newborn_name']);
		$stmt->bindParam(':sex', $_POST['sex']);
		$stmt->bindParam(':date_of_birth', $_POST['date_of_birth']);
		$stmt->bindParam(':time_of_birth', $_POST['time_of_birth']);
		$stmt->bindParam(':place_of_birth', $_POST['place_of_birth']);
		$stmt->bindParam(':birth_weight_kg', $_POST['birth_weight_kg']);
		$stmt->bindParam(':birth_length_cm', $_POST['birth_length_cm']);
		$stmt->bindParam(':type_of_birth', $_POST['type_of_birth']);
		$stmt->bindParam(':attendant_at_birth', $_POST['attendant_at_birth']);
		$stmt->bindParam(':remarks', $_POST['remarks']);
		$uid = $_SESSION['user_id'];
		$stmt->bindParam(':submitted_by', $uid);
		$stmt->execute();

		$success_message = 'Birth certificate submitted successfully.';
	} catch (Exception $e) {
		$error_message = 'Error: ' . $e->getMessage();
	}
}

$page_title = 'Nurse - Birth Certificate';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Birth Certificate</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h2 class="mb-0"><i class="fas fa-baby me-2"></i>Birth Certificate</h2>
		<a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
	</div>
	<?php if ($success_message): ?>
		<div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
	<?php endif; ?>
	<?php if ($error_message): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
	<?php endif; ?>

	<div class="card">
		<div class="card-body">
			<form method="POST">
				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label">Mother (Patient) - Admission</label>
						<select class="form-select" name="admission_id" required onchange="syncPatientId(this)">
							<option value="">Select admitted patient</option>
							<?php foreach ($admissions as $a): ?>
								<option value="<?php echo $a['admission_id']; ?>" data-pid="<?php echo $a['patient_id']; ?>">
									<?php echo htmlspecialchars($a['patient_name'] . ' - Room ' . $a['room_number'] . ' / Bed ' . $a['bed_number']); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<input type="hidden" name="patient_id" id="patient_id">
					</div>
					<div class="col-md-6">
						<label class="form-label">Mother's Full Name</label>
						<input type="text" class="form-control" name="mother_name" placeholder="Last, First Middle" required>
					</div>
					<div class="col-md-6">
						<label class="form-label">Father's Full Name</label>
						<input type="text" class="form-control" name="father_name" placeholder="Last, First Middle">
					</div>
					<div class="col-md-6">
						<label class="form-label">Newborn Name</label>
						<input type="text" class="form-control" name="newborn_name" placeholder="Optional">
					</div>
					<div class="col-md-3">
						<label class="form-label">Sex</label>
						<select class="form-select" name="sex" required>
							<option value="">Select</option>
							<option value="male">Male</option>
							<option value="female">Female</option>
						</select>
					</div>
					<div class="col-md-3">
						<label class="form-label">Date of Birth</label>
						<input type="date" class="form-control" name="date_of_birth" required>
					</div>
					<div class="col-md-3">
						<label class="form-label">Time of Birth</label>
						<input type="time" class="form-control" name="time_of_birth" required>
					</div>
					<div class="col-md-3">
						<label class="form-label">Place of Birth</label>
						<input type="text" class="form-control" name="place_of_birth" placeholder="Facility / Address" required>
					</div>
					<div class="col-md-3">
						<label class="form-label">Birth Weight (kg)</label>
						<input type="number" step="0.01" class="form-control" name="birth_weight_kg">
					</div>
					<div class="col-md-3">
						<label class="form-label">Birth Length (cm)</label>
						<input type="number" step="0.01" class="form-control" name="birth_length_cm">
					</div>
					<div class="col-md-3">
						<label class="form-label">Type of Birth</label>
						<select class="form-select" name="type_of_birth">
							<option value="single">Single</option>
							<option value="twin">Twin</option>
							<option value="multiple">Multiple</option>
						</select>
					</div>
					<div class="col-md-6">
						<label class="form-label">Attendant at Birth</label>
						<input type="text" class="form-control" name="attendant_at_birth" placeholder="Physician / Midwife / Nurse">
					</div>
					<div class="col-12">
						<label class="form-label">Remarks</label>
						<textarea class="form-control" name="remarks" rows="3" placeholder="Notes"></textarea>
					</div>
				</div>
				<div class="mt-3 d-flex justify-content-end">
					<button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Submit</button>
				</div>
			</form>
		</div>
	</div>
</div>
<script>
function syncPatientId(sel){
	var opt = sel.options[sel.selectedIndex];
	document.getElementById('patient_id').value = opt ? (opt.getAttribute('data-pid')||'') : '';
}
</script>
</body>
</html> 
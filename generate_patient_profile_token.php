<?php
session_start();
require_once "config/database.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
	http_response_code(401);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit();
}

// Only supra_admin or admin_staff can generate
$is_admin = ($_SESSION['role'] === 'supra_admin') || ($_SESSION['employee_type'] === 'admin_staff');
if (!$is_admin) {
	http_response_code(403);
	echo json_encode(['success' => false, 'message' => 'Forbidden']);
	exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$patient_record_id = isset($input['patient_record_id']) ? intval($input['patient_record_id']) : 0;
if ($patient_record_id <= 0) {
	http_response_code(400);
	echo json_encode(['success' => false, 'message' => 'Invalid patient_record_id']);
	exit();
}

try {
	$database = new Database();
	$db = $database->getConnection();

	// Ensure patient_record exists
	$check = $db->prepare("SELECT id FROM patient_records WHERE id = :id");
	$check->bindParam(":id", $patient_record_id);
	$check->execute();
	if ($check->rowCount() === 0) {
		http_response_code(404);
		echo json_encode(['success' => false, 'message' => 'Patient record not found']);
		exit();
	}

	// Check if a token already exists
	$sel = $db->prepare("SELECT token FROM patient_profile_tokens WHERE patient_record_id = :pid");
	$sel->bindParam(":pid", $patient_record_id);
	$sel->execute();
	$existing = $sel->fetch(PDO::FETCH_ASSOC);
	if ($existing && !empty($existing['token'])) {
		echo json_encode(['success' => true, 'token' => $existing['token']]);
		exit();
	}

	// Generate new token
	$token = bin2hex(random_bytes(32));
	$ins = $db->prepare("INSERT INTO patient_profile_tokens (patient_record_id, created_by, token) VALUES (:pid, :uid, :token)");
	$ins->bindParam(":pid", $patient_record_id);
	$ins->bindParam(":uid", $_SESSION['user_id']);
	$ins->bindParam(":token", $token);
	$ins->execute();

	echo json_encode(['success' => true, 'token' => $token]);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} 
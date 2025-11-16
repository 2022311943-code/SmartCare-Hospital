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
$bed_id = isset($input['bed_id']) ? intval($input['bed_id']) : 0;
if ($bed_id <= 0) {
	http_response_code(400);
	echo json_encode(['success' => false, 'message' => 'Invalid bed_id']);
	exit();
}

try {
	$database = new Database();
	$db = $database->getConnection();

	// Ensure bed exists
	$check = $db->prepare("SELECT id FROM beds WHERE id = :id");
	$check->bindParam(":id", $bed_id);
	$check->execute();
	if ($check->rowCount() === 0) {
		http_response_code(404);
		echo json_encode(['success' => false, 'message' => 'Bed not found']);
		exit();
	}

	// Check existing token
	$sel = $db->prepare("SELECT token FROM bed_profile_tokens WHERE bed_id = :bid");
	$sel->bindParam(":bid", $bed_id);
	$sel->execute();
	$existing = $sel->fetch(PDO::FETCH_ASSOC);
	if ($existing && !empty($existing['token'])) {
		echo json_encode(['success' => true, 'token' => $existing['token']]);
		exit();
	}

	// Create token
	$token = bin2hex(random_bytes(32));
	$ins = $db->prepare("INSERT INTO bed_profile_tokens (bed_id, created_by, token) VALUES (:bid, :uid, :token)");
	$ins->bindParam(":bid", $bed_id);
	$ins->bindParam(":uid", $_SESSION['user_id']);
	$ins->bindParam(":token", $token);
	$ins->execute();

	echo json_encode(['success' => true, 'token' => $token]);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} 
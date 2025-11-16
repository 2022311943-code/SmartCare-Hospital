<?php
session_start();
require_once "config/database.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
	http_response_code(403);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$order_id = isset($input['order_id']) ? intval($input['order_id']) : 0;
$note = isset($input['completion_note']) ? trim($input['completion_note']) : '';

if (!$order_id) {
	http_response_code(400);
	echo json_encode(['success' => false, 'message' => 'Invalid request']);
	exit();
}

try {
	$database = new Database();
	$db = $database->getConnection();
	$db->beginTransaction();

	$sql = "UPDATE doctors_orders
			SET status = 'completed', completed_by = :nid, completed_at = NOW(), completion_note = :note
			WHERE id = :oid AND status = 'in_progress' AND claimed_by = :nid";
	$stmt = $db->prepare($sql);
	$stmt->execute(['nid' => $_SESSION['user_id'], 'oid' => $order_id, 'note' => $note]);
	if ($stmt->rowCount() === 0) {
		throw new Exception('Only the claiming nurse can complete this order.');
	}

	$db->commit();
	echo json_encode(['success' => true]);
} catch (Exception $e) {
	if ($db && $db->inTransaction()) $db->rollBack();
	http_response_code(400);
	echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 
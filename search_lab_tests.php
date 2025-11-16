<?php
session_start();
require_once "config/database.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['doctor','general_doctor'])) {
	http_response_code(403);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit();
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';

try {
	$database = new Database();
	$db = $database->getConnection();

	$sql = "SELECT id, test_name, test_type, cost FROM lab_tests WHERE 1=1";
	$params = [];
	if ($q !== '') {
		$sql .= " AND (test_name LIKE :q OR description LIKE :q)";
		$params[':q'] = '%' . $q . '%';
	}
	if ($type !== '' && ($type === 'laboratory' || $type === 'radiology')) {
		$sql .= " AND test_type = :type";
		$params[':type'] = $type;
	}
	$sql .= " ORDER BY test_type, test_name LIMIT 30";

	$stmt = $db->prepare($sql);
	foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
	$stmt->execute();
	$tests = $stmt->fetchAll(PDO::FETCH_ASSOC);
	echo json_encode(['success' => true, 'tests' => $tests]);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'message' => 'Server error']);
}

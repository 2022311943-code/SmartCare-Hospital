<?php
session_start();
require_once "config/database.php";

header('Content-Type: application/json');

// Allow doctors/general_doctor to search
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['doctor','general_doctor'])) {
	http_response_code(403);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit();
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

try {
	$database = new Database();
	$db = $database->getConnection();

    $sql = "SELECT id, name, generic_name, unit, quantity, expiration_date
            FROM medicines
            WHERE quantity > 0
              AND (expiration_date IS NULL OR expiration_date > CURDATE())";
	$params = [];
	if ($q !== '') {
		$sql .= " AND (name LIKE :q OR generic_name LIKE :q)";
		$params[':q'] = '%' . $q . '%';
	}
	$sql .= " ORDER BY name ASC LIMIT 15";

	$stmt = $db->prepare($sql);
	foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
	$stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	echo json_encode(['success' => true, 'items' => $rows]);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'message' => 'Server error']);
}

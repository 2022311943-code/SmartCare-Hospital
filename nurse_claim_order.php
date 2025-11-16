<?php
session_start();
header('Content-Type: application/json');
require_once "config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = file_get_contents('php://input');
parse_str($input, $postData);
if (empty($postData)) {
    $postData = $_POST;
}

$order_id = isset($postData['order_id']) ? intval($postData['order_id']) : 0;
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    $db->beginTransaction();

    $order_stmt = $db->prepare("SELECT do.*, pa.admission_status
                                 FROM doctors_orders do
                                 JOIN patient_admissions pa ON do.admission_id = pa.id
                                 WHERE do.id = :id AND (do.order_type = 'discharge' OR do.order_type = '')");
    $order_stmt->execute([':id' => $order_id]);
    $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Discharge order not found.');
    }

    if ($order['status'] !== 'active') {
        throw new Exception('Order already claimed or completed.');
    }

    $claim_stmt = $db->prepare("UPDATE doctors_orders
                                SET status = 'in_progress',
                                    claimed_by = :nurse_id,
                                    claimed_at = NOW()
                                WHERE id = :order_id AND status = 'active' AND claimed_by IS NULL");
    $claim_stmt->execute([
        ':nurse_id' => $_SESSION['user_id'],
        ':order_id' => $order_id
    ]);
    if ($claim_stmt->rowCount() === 0) {
        throw new Exception('Order already claimed.');
    }

    $db->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
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
$action = isset($input['action']) ? $input['action'] : '';
$order_id = isset($input['order_id']) ? intval($input['order_id']) : 0;

if (!$order_id || !in_array($action, ['claim','release'])) {
	http_response_code(400);
	echo json_encode(['success' => false, 'message' => 'Invalid request']);
	exit();
}

try {
	$database = new Database();
	$db = $database->getConnection();
	$db->beginTransaction();

	if ($action === 'claim') {
		$sql = "UPDATE doctors_orders
				SET status = 'in_progress', claimed_by = :nid, claimed_at = NOW()
				WHERE id = :oid AND status = 'active' AND (claimed_by IS NULL)";
		$stmt = $db->prepare($sql);
		$stmt->execute(['nid' => $_SESSION['user_id'], 'oid' => $order_id]);
		if ($stmt->rowCount() === 0) {
			throw new Exception('Order cannot be claimed.');
		}
	} else {
		$sql = "UPDATE doctors_orders
				SET status = 'active', claimed_by = NULL, claimed_at = NULL
				WHERE id = :oid AND status = 'in_progress' AND claimed_by = :nid";
		$stmt = $db->prepare($sql);
		$stmt->execute(['nid' => $_SESSION['user_id'], 'oid' => $order_id]);
		if ($stmt->rowCount() === 0) {
			throw new Exception('Order cannot be released.');
		}
	}

	$db->commit();
	echo json_encode(['success' => true]);
} catch (Exception $e) {
	if ($db && $db->inTransaction()) $db->rollBack();
	http_response_code(400);
	echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 
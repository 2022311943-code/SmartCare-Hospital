<?php
session_start();
require_once "config/database.php";

// Only nurses can execute transfers
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$admission_id = isset($_POST['admission_id']) ? intval($_POST['admission_id']) : 0;
$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
$room_id = isset($_POST['room_id']) ? intval($_POST['room_id']) : 0;

if (!$admission_id || !$order_id || !$room_id) {
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

try {
    $db->beginTransaction();

    // Ensure order is valid and active for this admission
    $order_stmt = $db->prepare("SELECT id FROM doctors_orders WHERE id = :oid AND admission_id = :aid AND status IN ('active','in_progress') AND special_instructions = 'ROOM_TRANSFER' FOR UPDATE");
    $order_stmt->execute(['oid' => $order_id, 'aid' => $admission_id]);
    if (!$order_stmt->fetch()) {
        throw new Exception('Invalid or completed transfer order');
    }

    // Lock admission row and get current bed/room
    $adm_stmt = $db->prepare("SELECT room_id, bed_id FROM patient_admissions WHERE id = :aid FOR UPDATE");
    $adm_stmt->execute(['aid' => $admission_id]);
    $admission = $adm_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admission) {
        throw new Exception('Admission not found');
    }

    // Pick first available bed in chosen room
    $bed_stmt = $db->prepare("SELECT b.id as bed_id, b.bed_number, r.room_number FROM beds b JOIN rooms r ON r.id = b.room_id WHERE b.room_id = :rid AND b.status = 'available' ORDER BY b.bed_number LIMIT 1 FOR UPDATE");
    $bed_stmt->execute(['rid' => $room_id]);
    $dest = $bed_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dest) {
        throw new Exception('No available bed in selected room');
    }

    // Update admission to new room/bed
    $update_adm = $db->prepare("UPDATE patient_admissions SET room_id = :rid, bed_id = :bid, updated_at = CURRENT_TIMESTAMP WHERE id = :aid");
    $update_adm->execute(['rid' => $room_id, 'bid' => $dest['bed_id'], 'aid' => $admission_id]);

    // Free old bed if there was one
    if (!empty($admission['bed_id'])) {
        $free_stmt = $db->prepare("UPDATE beds SET status = 'available' WHERE id = :old_bed");
        $free_stmt->execute(['old_bed' => $admission['bed_id']]);
    }

    // Occupy new bed
    $occ_stmt = $db->prepare("UPDATE beds SET status = 'occupied' WHERE id = :bid");
    $occ_stmt->execute(['bid' => $dest['bed_id']]);

    // Mark order completed by this nurse (claim if necessary)
    $claim_stmt = $db->prepare("UPDATE doctors_orders SET status = 'in_progress', claimed_by = COALESCE(claimed_by, :nid), claimed_at = COALESCE(claimed_at, NOW()) WHERE id = :oid AND status = 'active'");
    $claim_stmt->execute(['nid' => $_SESSION['user_id'], 'oid' => $order_id]);

    $done_stmt = $db->prepare("UPDATE doctors_orders SET status = 'completed', completed_by = :nid, completed_at = NOW(), completion_note = CONCAT('Transferred to room ', :rnum, ' bed ', :bnum) WHERE id = :oid AND status IN ('active','in_progress')");
    $done_stmt->execute(['nid' => $_SESSION['user_id'], 'oid' => $order_id, 'rnum' => $dest['room_number'], 'bnum' => $dest['bed_number']]);

    $db->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
} 
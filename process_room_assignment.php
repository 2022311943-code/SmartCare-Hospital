<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a nurse
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

try {
    $db->beginTransaction();

    // If a specific room_id is provided, pick the first available bed in that room.
    if (!empty($_POST['room_id'])) {
        $room_query = "SELECT :room_id AS room_id, b.id as bed_id, r.room_number, b.bed_number
                       FROM rooms r
                       JOIN beds b ON r.id = b.room_id
                       WHERE r.id = :room_id
                       AND r.status = 'active'
                       AND b.status = 'available'
                       ORDER BY b.bed_number
                       LIMIT 1";
        $room_stmt = $db->prepare($room_query);
        $room_stmt->bindParam(":room_id", $_POST['room_id'], PDO::PARAM_INT);
    } else {
        // Fallback: first available room by selected type
        $room_query = "SELECT r.id as room_id, b.id as bed_id, r.room_number, b.bed_number
                       FROM rooms r
                       JOIN beds b ON r.id = b.room_id
                       WHERE r.room_type = :room_type
                       AND r.status = 'active'
                       AND b.status = 'available'
                       ORDER BY r.floor_number, r.room_number, b.bed_number
                       LIMIT 1";
        $room_stmt = $db->prepare($room_query);
        $room_stmt->bindParam(":room_type", $_POST['room_type']);
    }
    $room_stmt->execute();
    $available = $room_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$available) {
        throw new Exception("No available rooms of the selected type");
    }

    // Update admission with room and bed assignment, set admission_date if not set
    $update_query = "UPDATE patient_admissions 
                    SET room_id = :room_id,
                        bed_id = :bed_id,
                        admission_status = 'admitted',
                        admission_date = IFNULL(admission_date, CURRENT_TIMESTAMP),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :admission_id";

    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(":room_id", $available['room_id']);
    $update_stmt->bindParam(":bed_id", $available['bed_id']);
    $update_stmt->bindParam(":admission_id", $_POST['admission_id']);

    if ($update_stmt->execute()) {
        // Update bed status to occupied
        $bed_query = "UPDATE beds 
                     SET status = 'occupied' 
                     WHERE id = :bed_id";
        
        $bed_stmt = $db->prepare($bed_query);
        $bed_stmt->bindParam(":bed_id", $available['bed_id']);
        $bed_stmt->execute();

        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => "Room {$available['room_number']} Bed {$available['bed_number']} assigned successfully"
        ]);
    } else {
        throw new Exception("Failed to update admission");
    }
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
} 
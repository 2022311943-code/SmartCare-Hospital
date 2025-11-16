<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['room_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Room ID is required']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    $query = "SELECT id, bed_number 
              FROM beds 
              WHERE room_id = :room_id 
              AND status = 'available' 
              ORDER BY bed_number";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":room_id", $_GET['room_id']);
    $stmt->execute();
    
    $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($beds);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} 
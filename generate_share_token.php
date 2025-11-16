<?php
session_start();
require_once "config/database.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['patient_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    // Verify the patient exists
    $verify_query = "SELECT id FROM patients WHERE id = :patient_id";
    $verify_stmt = $db->prepare($verify_query);
    $verify_stmt->bindParam(":patient_id", $data['patient_id']);
    $verify_stmt->execute();
    $patient = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit();
    }

    // Generate a random token
    $token = bin2hex(random_bytes(32));
    
    // Insert the token (always basic access)
    $query = "INSERT INTO patient_share_tokens (patient_id, shared_by, token, access_level) 
              VALUES (:patient_id, :shared_by, :token, 'basic')";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":patient_id", $data['patient_id']);
    $stmt->bindParam(":shared_by", $_SESSION['user_id']);
    $stmt->bindParam(":token", $token);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'token' => $token]);
    } else {
        throw new Exception('Failed to generate share token');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?> 
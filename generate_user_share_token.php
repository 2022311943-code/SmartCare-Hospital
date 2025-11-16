<?php
session_start();
require_once "config/database.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized access']));
}

// Get the user ID from the session
$user_id = $_SESSION['user_id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Generate a unique token
    $token = bin2hex(random_bytes(32));
    
    // First, check if a token already exists for this user
    $check_query = "SELECT id FROM user_share_tokens WHERE user_id = :user_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(":user_id", $user_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        // Update existing token
        $update_query = "UPDATE user_share_tokens SET token = :token WHERE user_id = :user_id";
        $stmt = $db->prepare($update_query);
    } else {
        // Insert new token
        $update_query = "INSERT INTO user_share_tokens (user_id, token) VALUES (:user_id, :token)";
        $stmt = $db->prepare($update_query);
    }
    
    $stmt->bindParam(":user_id", $user_id);
    $stmt->bindParam(":token", $token);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'token' => $token]);
    } else {
        echo json_encode(['error' => 'Failed to generate token']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'An error occurred: ' . $e->getMessage()]);
}
?> 
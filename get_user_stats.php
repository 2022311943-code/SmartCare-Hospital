<?php
require_once "config/database.php";

$database = new Database();
$db = $database->getConnection();

// Get active users count
$active_count_query = "SELECT COUNT(*) as count FROM users WHERE status = 'active' AND role != 'supra_admin'";
$active_count_stmt = $db->prepare($active_count_query);
$active_count_stmt->execute();
$active_count = $active_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get active nurses count
$nurse_count_query = "SELECT COUNT(*) as count FROM users WHERE employee_type = 'nurse' AND status = 'active'";
$nurse_count_stmt = $db->prepare($nurse_count_query);
$nurse_count_stmt->execute();
$nurse_count = $nurse_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get active doctors count
$doctor_count_query = "SELECT COUNT(*) as count FROM users WHERE employee_type = 'doctor' AND status = 'active'";
$doctor_count_stmt = $db->prepare($doctor_count_query);
$doctor_count_stmt->execute();
$doctor_count = $doctor_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get pending users count
$pending_count_query = "SELECT COUNT(*) as count FROM users WHERE status = 'pending'";
$pending_count_stmt = $db->prepare($pending_count_query);
$pending_count_stmt->execute();
$pending_count = $pending_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'active_users' => $active_count,
    'active_nurses' => $nurse_count,
    'active_doctors' => $doctor_count,
    'pending_users' => $pending_count
]); 
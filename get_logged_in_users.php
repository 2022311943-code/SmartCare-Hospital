<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is a supra admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'supra_admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    // Get online users (users who have logged in within the last 5 minutes)
    $query = "SELECT * FROM users 
              WHERE status = 'active' 
              AND last_activity >= NOW() - INTERVAL 5 MINUTE 
              AND role != 'supra_admin'
              ORDER BY name ASC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $online_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    if (empty($online_users)) {
        $html = '<tr><td colspan="4" class="text-center text-muted">No users currently online</td></tr>';
    } else {
        foreach ($online_users as $user) {
            $html .= '<tr>';
            $html .= '<td><i class="fas fa-circle text-success me-2"></i>' . htmlspecialchars($user['name']) . '</td>';
            $html .= '<td><span class="badge bg-info">' . ucfirst(str_replace('_', ' ', $user['employee_type'])) . '</span></td>';
            $html .= '<td><span class="badge bg-secondary">' . htmlspecialchars($user['department']) . '</span></td>';
            $html .= '<td><span class="badge bg-success">Online</span></td>';
            $html .= '</tr>';
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $html,
        'count' => count($online_users)
    ]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Error fetching online users: ' . $e->getMessage()
    ]);
} 
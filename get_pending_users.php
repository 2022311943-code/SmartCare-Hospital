<?php
session_start();
require_once "config/database.php";

// Only Supra Admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supra_admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get pending registrations
$pending_query = "SELECT * FROM users WHERE status = 'pending' ORDER BY id DESC";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->execute();
$pending_users = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for statistics
$pending_count = count($pending_users);

// Prepare HTML for pending users
$html = '';
if (empty($pending_users)) {
    $html = '<p class="text-muted text-center mb-0">No pending registrations</p>';
} else {
    $html .= '<div class="table-responsive"><table class="table table-hover">';
    $html .= '<thead><tr><th>Name</th><th>Email</th><th>Employee Type</th><th>Department</th><th>Registration Date</th><th>Actions</th></tr></thead><tbody>';
    
    foreach ($pending_users as $user) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($user['name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($user['email']) . '</td>';
        $html .= '<td><span class="badge bg-info">' . ucfirst(str_replace('_', ' ', $user['employee_type'])) . '</span></td>';
        $html .= '<td><span class="badge bg-secondary">' . htmlspecialchars($user['department']) . '</span></td>';
        $html .= '<td>' . date('M d, Y', strtotime($user['created_at'])) . '</td>';
        $html .= '<td>';
        $html .= '<form method="POST" style="display: inline;">';
        $html .= '<input type="hidden" name="user_id" value="' . $user['id'] . '">';
        $html .= '<input type="hidden" name="action" value="approve">';
        $html .= '<button type="submit" class="btn btn-success btn-action btn-sm">';
        $html .= '<i class="fas fa-check me-1"></i>Approve</button>';
        $html .= '</form>';
        $html .= '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table></div>';
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'html' => $html,
    'count' => $pending_count,
    'has_new' => $pending_count > 0
]); 
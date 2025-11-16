<?php
// Force error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

echo "<h2>Session Information:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Testing Database Connection:</h2>";
try {
    require_once "config/database.php";
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Database connection successful!<br>";
    
    // Test lab_tests table
    $test_query = "SELECT COUNT(*) FROM lab_tests";
    $result = $db->query($test_query);
    $count = $result->fetchColumn();
    echo "Number of lab tests in database: " . $count . "<br>";
    
    // Test if user is general doctor
    if (isset($_SESSION['user_id'])) {
        $user_query = "SELECT * FROM users WHERE id = ?";
        $stmt = $db->prepare($user_query);
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<h3>User Information:</h3>";
        echo "Name: " . ($user['name'] ?? 'Not found') . "<br>";
        echo "Employee Type: " . ($user['employee_type'] ?? 'Not found') . "<br>";
        echo "Department: " . ($user['department'] ?? 'Not found') . "<br>";
    } else {
        echo "No user is logged in<br>";
    }
    
    // Test active OPD visits
    if (isset($_SESSION['user_id'])) {
        $opd_query = "SELECT COUNT(*) FROM opd_visits 
                      WHERE doctor_id = ? 
                      AND visit_status = 'in_progress'";
        $stmt = $db->prepare($opd_query);
        $stmt->execute([$_SESSION['user_id']]);
        $opd_count = $stmt->fetchColumn();
        
        echo "<h3>Active OPD Visits:</h3>";
        echo "Number of active consultations: " . $opd_count . "<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red'>";
    echo "Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    echo "</div>";
}
?> 
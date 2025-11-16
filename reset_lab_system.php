<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "config/database.php";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Disable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Drop existing tables
    echo "Dropping existing tables...<br>";
    $db->exec("DROP TABLE IF EXISTS lab_requests");
    $db->exec("DROP TABLE IF EXISTS lab_tests");
    
    // Create lab_tests table
    echo "Creating lab_tests table...<br>";
    $sql1 = "CREATE TABLE lab_tests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        test_name VARCHAR(100) NOT NULL,
        test_type ENUM('laboratory', 'radiology') NOT NULL,
        cost DECIMAL(10,2) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql1);
    
    // Create lab_requests table
    echo "Creating lab_requests table...<br>";
    $sql2 = "CREATE TABLE lab_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_id INT NOT NULL,
        doctor_id INT NOT NULL,
        test_id INT NOT NULL,
        priority ENUM('normal', 'urgent', 'emergency') NOT NULL DEFAULT 'normal',
        status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
        notes TEXT,
        result TEXT NULL,
        result_file_path VARCHAR(255) NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        FOREIGN KEY (visit_id) REFERENCES opd_visits(id) ON DELETE CASCADE,
        FOREIGN KEY (doctor_id) REFERENCES users(id),
        FOREIGN KEY (test_id) REFERENCES lab_tests(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql2);
    
    // Insert sample test types
    echo "Inserting sample test types...<br>";
    $tests = [
        ['Complete Blood Count (CBC)', 'laboratory', 500.00, 'Basic blood test that checks RBC, WBC, platelets, etc.'],
        ['Blood Sugar Test', 'laboratory', 300.00, 'Measures blood glucose levels'],
        ['Urinalysis', 'laboratory', 200.00, 'Examines physical and chemical properties of urine'],
        ['X-Ray - Chest', 'radiology', 800.00, 'Chest X-ray imaging'],
        ['X-Ray - Extremities', 'radiology', 600.00, 'X-ray for arms, legs, hands, or feet'],
        ['Ultrasound', 'radiology', 1200.00, 'Ultrasound imaging scan'],
        ['ECG', 'laboratory', 500.00, 'Electrocardiogram heart activity test'],
        ['Lipid Profile', 'laboratory', 800.00, 'Cholesterol and triglycerides test'],
        ['Kidney Function Test', 'laboratory', 700.00, 'Measures kidney function markers'],
        ['Liver Function Test', 'laboratory', 700.00, 'Measures liver enzymes and proteins']
    ];
    
    $insert_query = "INSERT INTO lab_tests (test_name, test_type, cost, description) VALUES (?, ?, ?, ?)";
    $stmt = $db->prepare($insert_query);
    foreach ($tests as $test) {
        $stmt->execute($test);
    }
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Verify table structures
    echo "<br>Verifying table structures:<br>";
    
    echo "<br>lab_tests table:<br>";
    $result = $db->query("SHOW CREATE TABLE lab_tests");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . $row['Create Table'] . "</pre>";
    
    echo "<br>lab_requests table:<br>";
    $result = $db->query("SHOW CREATE TABLE lab_requests");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . $row['Create Table'] . "</pre>";
    
    // Count test types
    $count = $db->query("SELECT COUNT(*) FROM lab_tests")->fetchColumn();
    echo "<br>Number of test types: " . $count . "<br>";
    
    echo "<br><strong>Lab system reset completed successfully!</strong>";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?> 
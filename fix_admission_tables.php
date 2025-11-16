<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    $db->beginTransaction();

    // Create table if it doesn't exist with the correct structure
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS patient_admissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            room_id INT NULL,
            bed_id INT NULL,
            admission_source VARCHAR(32) DEFAULT 'opd',
            admission_date DATETIME NULL,
            expected_discharge_date DATE NULL,
            actual_discharge_date DATETIME NULL,
            admission_status ENUM('pending','admitted','discharged') DEFAULT 'pending',
            admission_notes TEXT,
            admitted_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (room_id) REFERENCES rooms(id),
            FOREIGN KEY (bed_id) REFERENCES beds(id),
            FOREIGN KEY (admitted_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $db->exec($createTableSql);

    // Ensure columns exist/are compatible across prior schemas
    $db->exec("ALTER TABLE patient_admissions MODIFY COLUMN room_id INT NULL");
    $db->exec("ALTER TABLE patient_admissions MODIFY COLUMN bed_id INT NULL");
    $db->exec("ALTER TABLE patient_admissions ADD COLUMN IF NOT EXISTS admission_source VARCHAR(32) DEFAULT 'opd'");
    $db->exec("ALTER TABLE patient_admissions ADD COLUMN IF NOT EXISTS admission_date DATETIME NULL");
    $db->exec("ALTER TABLE patient_admissions ADD COLUMN IF NOT EXISTS expected_discharge_date DATE NULL");
    $db->exec("ALTER TABLE patient_admissions ADD COLUMN IF NOT EXISTS actual_discharge_date DATETIME NULL");
    $db->exec("ALTER TABLE patient_admissions ADD COLUMN IF NOT EXISTS admission_status ENUM('pending','admitted','discharged') DEFAULT 'pending'");

    // In case admission_status exists but lacks 'pending', recreate ENUM safely
    // Fetch current enum definition
    $enumStmt = $db->query("SHOW COLUMNS FROM patient_admissions LIKE 'admission_status'");
    $enumRow = $enumStmt->fetch(PDO::FETCH_ASSOC);
    if ($enumRow && strpos($enumRow['Type'], "pending") === false) {
        // Replace enum to include pending
        $db->exec("ALTER TABLE patient_admissions MODIFY COLUMN admission_status ENUM('pending','admitted','discharged') NOT NULL DEFAULT 'pending'");
    }

    // Add helpful indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_patient_admissions_status ON patient_admissions(admission_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_patient_admissions_dates ON patient_admissions(admission_date, actual_discharge_date)");

    $db->commit();
    echo "Patient admissions table is up to date.";
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo "Error updating admissions schema: " . $e->getMessage();
} 
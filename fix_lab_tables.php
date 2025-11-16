<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "config/database.php";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Disable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    echo "Updating lab_requests schema...<br>";
    // Allow visit_id to be NULL
    $db->exec("ALTER TABLE lab_requests MODIFY COLUMN visit_id INT NULL");
    echo "visit_id set to NULLABLE<br>";
    
    // Add admission_id if not exists
    $db->exec("ALTER TABLE lab_requests ADD COLUMN IF NOT EXISTS admission_id INT NULL AFTER visit_id");
    echo "admission_id column added (if missing)<br>";
    
    // Add foreign key if not exists (best effort)
    // MySQL doesn't support IF NOT EXISTS for FK; wrap in try
    try {
        $db->exec("ALTER TABLE lab_requests ADD CONSTRAINT fk_labreq_admission FOREIGN KEY (admission_id) REFERENCES patient_admissions(id) ON DELETE SET NULL");
        echo "Foreign key added for admission_id<br>";
    } catch (Exception $e) {
        echo "Foreign key may already exist: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
    
    // Add result_file_path column if it doesn't exist
    $db->exec("ALTER TABLE lab_requests ADD COLUMN IF NOT EXISTS result_file_path VARCHAR(255) NULL AFTER result");
    echo "result_file_path ensured<br>";
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    echo "<strong>Lab tables updated successfully!</strong>";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
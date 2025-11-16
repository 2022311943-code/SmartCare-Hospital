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

    // Create doctors_orders table if it does not exist
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS doctors_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admission_id INT NOT NULL,
  order_type ENUM('medication','lab_test','diagnostic','diet','activity','monitoring','other','discharge') NOT NULL,
  order_details TEXT NOT NULL,
  frequency VARCHAR(100) NULL,
  duration VARCHAR(100) NULL,
  special_instructions TEXT NULL,
  status ENUM('active','in_progress','completed','discontinued') DEFAULT 'active',
  ordered_by INT NOT NULL,
  ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  claimed_by INT NULL,
  claimed_at DATETIME NULL,
  completed_by INT NULL,
  completed_at DATETIME NULL,
  completion_note TEXT NULL,
  discontinued_by INT NULL,
  discontinued_at DATETIME NULL,
  discontinue_reason TEXT NULL,
  FOREIGN KEY (admission_id) REFERENCES patient_admissions(id) ON DELETE CASCADE,
  FOREIGN KEY (ordered_by) REFERENCES users(id),
  FOREIGN KEY (claimed_by) REFERENCES users(id),
  FOREIGN KEY (completed_by) REFERENCES users(id),
  FOREIGN KEY (discontinued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

    // Add missing columns defensively
    $db->exec("ALTER TABLE doctors_orders MODIFY COLUMN status ENUM('active','in_progress','completed','discontinued') DEFAULT 'active'");
    $db->exec("ALTER TABLE doctors_orders MODIFY COLUMN order_type ENUM('medication','lab_test','diagnostic','diet','activity','monitoring','other','discharge') NOT NULL");
    $db->exec("UPDATE doctors_orders SET order_type = 'discharge' WHERE order_type = '' OR order_type IS NULL");
    $db->exec("ALTER TABLE doctors_orders ADD COLUMN IF NOT EXISTS claimed_by INT NULL");
    $db->exec("ALTER TABLE doctors_orders ADD COLUMN IF NOT EXISTS claimed_at DATETIME NULL");

    if ($db->inTransaction()) {
        $db->commit();
    }
    echo "Doctor orders table is ready.";
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo "Error updating orders schema: " . $e->getMessage();
} 
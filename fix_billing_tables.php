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

	// existing billing table
	$db->exec("CREATE TABLE IF NOT EXISTS admission_billing (
	  id INT AUTO_INCREMENT PRIMARY KEY,
	  admission_id INT NOT NULL,
	  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
	  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
	  discount_label VARCHAR(100) NULL,
	  total_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
	  payment_status ENUM('unpaid','paid') DEFAULT 'unpaid',
	  notes TEXT NULL,
	  created_by INT NULL,
	  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	  FOREIGN KEY (admission_id) REFERENCES patient_admissions(id) ON DELETE CASCADE,
	  FOREIGN KEY (created_by) REFERENCES users(id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

	// items table
	$db->exec("CREATE TABLE IF NOT EXISTS admission_billing_items (
	  id INT AUTO_INCREMENT PRIMARY KEY,
	  billing_id INT NOT NULL,
	  description VARCHAR(255) NOT NULL,
	  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
	  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	  FOREIGN KEY (billing_id) REFERENCES admission_billing(id) ON DELETE CASCADE,
	  KEY billing_id_idx (billing_id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

	// new: suppressed sources (e.g., lab requests hidden by finance)
	$db->exec("CREATE TABLE IF NOT EXISTS admission_billing_suppressed (
	  id INT AUTO_INCREMENT PRIMARY KEY,
	  billing_id INT NOT NULL,
	  type ENUM('lab') NOT NULL,
	  ref_id INT NOT NULL,
	  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	  UNIQUE KEY uniq_suppress (billing_id, type, ref_id),
	  FOREIGN KEY (billing_id) REFERENCES admission_billing(id) ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

	// backfill billing rows
	$stmt = $db->prepare(
		"INSERT INTO admission_billing (admission_id, subtotal, discount_amount, discount_label, total_due, payment_status, created_by)
		 SELECT DISTINCT do.admission_id, 0, 0, NULL, 0, 'unpaid', :uid
		 FROM doctors_orders do
		 LEFT JOIN admission_billing ab ON ab.admission_id = do.admission_id
		 WHERE (do.order_type = 'discharge' OR do.order_type = '') AND do.status = 'completed' AND ab.id IS NULL AND do.admission_id IS NOT NULL"
	);
	$stmt->execute(['uid' => $_SESSION['user_id']]);

	$db->commit();
	echo "Billing tables ensured and backfilled.";
} catch (Exception $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500);
	echo "Error: " . $e->getMessage();
} 
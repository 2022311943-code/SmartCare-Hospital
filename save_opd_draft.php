<?php
session_start();
require_once "config/database.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['receptionist','medical_staff','nurse','admin_staff','medical_records'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
        throw new Exception('Invalid payload');
    }

    // Whitelist form fields to store
    $allowed = [
        'last_name','first_name','middle_name','suffix','age','gender','birthdate','civil_status',
        'address_house','address_street','address_barangay','address_city','address_postal','address_province',
        'contact_digits','occupation','head_of_family','religion','philhealth','pregnancy_status','doctor_id',
        'symptoms','blood_pressure','pulse_rate','respiratory_rate','temperature','oxygen_saturation',
        'height_cm','weight_kg','noi','poi','doi','toi','lmp','medical_history','medications'
    ];
    $data = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $payload['data'])) {
            $v = $payload['data'][$f];
            if (is_string($v)) { $v = substr($v, 0, 10000); } // basic clamp
            $data[$f] = $v;
        }
    }

    $database = new Database();
    $db = $database->getConnection();
    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS opd_registration_drafts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        data_json JSON NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upsert
    $stmt = $db->prepare("INSERT INTO opd_registration_drafts (user_id, data_json) 
                          VALUES (:uid, :js) 
                          ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([
        ':uid' => $_SESSION['user_id'],
        ':js' => json_encode($data, JSON_UNESCAPED_UNICODE)
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?> 


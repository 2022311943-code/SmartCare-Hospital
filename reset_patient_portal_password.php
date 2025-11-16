<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once 'config/database.php';
require_once 'includes/crypto.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['employee_type'] ?? '') !== 'admin_staff') {
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

function json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

try {
    $db = (new Database())->getConnection();
    // Ensure table and columns
    $db->exec("CREATE TABLE IF NOT EXISTS patient_portal_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_record_id INT NOT NULL UNIQUE,
        username VARCHAR(150) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        password_plain_enc TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $db->exec("ALTER TABLE patient_portal_accounts ADD COLUMN password_reset_at TIMESTAMP NULL DEFAULT NULL"); } catch (Exception $e) { /* ignore */ }
    try { $db->exec("ALTER TABLE patient_portal_accounts ADD COLUMN password_plain_is_reset TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) { /* ignore */ }

    $in = json_input();
    $patient_record_id = isset($in['patient_record_id']) ? intval($in['patient_record_id']) : 0;
    if ($patient_record_id <= 0) {
        echo json_encode(['success'=>false,'error'=>'Invalid patient_record_id']);
        exit;
    }

    // Fetch or create account
    $acc = $db->prepare('SELECT * FROM patient_portal_accounts WHERE patient_record_id = :pid');
    $acc->execute(['pid'=>$patient_record_id]);
    $row = $acc->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Build username from patient records
        $st = $db->prepare('SELECT patient_name, contact_number FROM patient_records WHERE id = :id');
        $st->execute(['id'=>$patient_record_id]);
        $pr = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pr) { echo json_encode(['success'=>false,'error'=>'Patient not found']); exit; }
        $name = decrypt_safe((string)($pr['patient_name'] ?? ''));
        $phone = decrypt_safe((string)($pr['contact_number'] ?? ''));
        $base = preg_replace('/[^a-z]/', '', strtolower($name));
        if ($base === '') { $base = 'patient'; }
        $last4 = substr(preg_replace('/\D+/', '', $phone), -4);
        if ($last4 === '') { $last4 = substr((string)$patient_record_id, -4); }
        $candidate = $base.$last4;
        $chk = $db->prepare('SELECT 1 FROM patient_portal_accounts WHERE username = :u LIMIT 1');
        $try = 0;
        while (true) {
            $chk->execute(['u'=>$candidate]);
            if (!$chk->fetch()) break;
            $try++; $candidate = $base.$last4.$try; if ($try > 50) { $candidate = $base.time(); break; }
        }
        $username = $candidate;
    } else {
        $username = $row['username'];
    }

    // Generate strong password
    function strong_password($length = 14) {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%^&*()-_=+[]{}<>?';
        $all = $upper.$lower.$digits.$symbols;
        $pw = $upper[random_int(0,strlen($upper)-1)]
            . $lower[random_int(0,strlen($lower)-1)]
            . $digits[random_int(0,strlen($digits)-1)]
            . $symbols[random_int(0,strlen($symbols)-1)];
        for ($i = 4; $i < $length; $i++) { $pw .= $all[random_int(0, strlen($all)-1)]; }
        return str_shuffle($pw);
    }

    $plain = strong_password(14);
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $enc_plain = encrypt_strict($plain);

    if ($row) {
        $up = $db->prepare('UPDATE patient_portal_accounts SET password_hash = :h, password_plain_enc = :p, password_plain_is_reset = 1, password_reset_at = NOW() WHERE patient_record_id = :pid');
        $up->execute(['h'=>$hash,'p'=>$enc_plain,'pid'=>$patient_record_id]);
    } else {
        $ins = $db->prepare('INSERT INTO patient_portal_accounts (patient_record_id, username, password_hash, password_plain_enc, password_plain_is_reset, password_reset_at) VALUES (:pid,:u,:h,:p,1,NOW())');
        $ins->execute(['pid'=>$patient_record_id,'u'=>$username,'h'=>$hash,'p'=>$enc_plain]);
    }

    echo json_encode(['success'=>true,'patient_record_id'=>$patient_record_id,'username'=>$username,'password'=>$plain]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}

?>



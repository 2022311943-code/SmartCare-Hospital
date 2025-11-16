<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once 'config/database.php';
require_once 'includes/crypto.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'] ?? '', ['finance','admin_staff','receptionist','medical_records','admin_staff']) ) {
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

    // Ensure accounts table exists
    $db->exec("CREATE TABLE IF NOT EXISTS patient_portal_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_record_id INT NOT NULL UNIQUE,
        username VARCHAR(150) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        password_plain_enc TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add reset-tracking columns if missing
    try { $db->exec("ALTER TABLE patient_portal_accounts ADD COLUMN password_reset_at TIMESTAMP NULL DEFAULT NULL"); } catch (Exception $e) { /* ignore */ }
    try { $db->exec("ALTER TABLE patient_portal_accounts ADD COLUMN password_plain_is_reset TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) { /* ignore */ }

    $in = json_input();
    $visit_id = isset($in['visit_id']) ? intval($in['visit_id']) : 0;
    $admission_id = isset($in['admission_id']) ? intval($in['admission_id']) : 0;
    $patient_record_id = isset($in['patient_record_id']) ? intval($in['patient_record_id']) : 0;

    // Resolve patient_record_id
    if ($patient_record_id <= 0) {
        if ($visit_id > 0) {
            $st = $db->prepare("SELECT patient_record_id FROM opd_visits WHERE id = :id");
            $st->execute(['id'=>$visit_id]);
            $patient_record_id = intval($st->fetchColumn());
        } elseif ($admission_id > 0) {
            // Resolve via admission → patients (plaintext) → patient_records (encrypted, compare in PHP)
            $pstmt = $db->prepare("SELECT p.name AS pname, p.phone AS pphone FROM patient_admissions pa JOIN patients p ON pa.patient_id = p.id WHERE pa.id = :aid LIMIT 1");
            $pstmt->execute(['aid'=>$admission_id]);
            $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
            if ($prow && isset($prow['pname'], $prow['pphone'])) {
                $plainName = (string)$prow['pname'];
                $plainPhone = (string)$prow['pphone'];
                // Scan recent patient_records and match after decryption
                $scan = $db->prepare("SELECT id, patient_name, contact_number FROM patient_records ORDER BY id DESC LIMIT 1000");
                $scan->execute();
                while ($r = $scan->fetch(PDO::FETCH_ASSOC)) {
                    $decName = decrypt_safe((string)$r['patient_name']);
                    $decPhone = decrypt_safe((string)$r['contact_number']);
                    if ($decName === $plainName && $decPhone === $plainPhone) {
                        $patient_record_id = (int)$r['id'];
                        break;
                    }
                }
            }
        }
    }

    if ($patient_record_id <= 0) {
        echo json_encode(['success'=>false,'error'=>'Unable to resolve patient']);
        exit;
    }

    // Check existing account
    $acc = $db->prepare("SELECT * FROM patient_portal_accounts WHERE patient_record_id = :pid");
    $acc->execute(['pid'=>$patient_record_id]);
    $row = $acc->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $username = $row['username'];
        $plain = '';
        // Reveal the stored plain password ONLY if it has NOT been reset yet
        if (!empty($row['password_plain_enc']) && (empty($row['password_plain_is_reset']) || intval($row['password_plain_is_reset']) === 0)) {
            $plain = decrypt_safe($row['password_plain_enc']);
        }
        echo json_encode(['success'=>true, 'patient_record_id'=>$patient_record_id, 'username'=>$username, 'password'=>$plain]);
        exit;
    }

    // Need patient name and phone to build username
    $st = $db->prepare("SELECT patient_name, contact_number FROM patient_records WHERE id = :id");
    $st->execute(['id'=>$patient_record_id]);
    $pr = $st->fetch(PDO::FETCH_ASSOC);
    $name = $pr ? decrypt_safe((string)$pr['patient_name']) : '';
    $phone = $pr ? decrypt_safe((string)$pr['contact_number']) : '';

    $base = preg_replace('/[^a-z]/', '', strtolower($name));
    if ($base === '') { $base = 'patient'; }
    $last4 = substr(preg_replace('/\D+/', '', $phone), -4);
    if ($last4 === '') { $last4 = substr((string)$patient_record_id, -4); }

    // Generate unique username
    $candidate = $base . $last4;
    $try = 0;
    $chk = $db->prepare("SELECT 1 FROM patient_portal_accounts WHERE username = :u LIMIT 1");
    while (true) {
        $chk->execute(['u'=>$candidate]);
        if (!$chk->fetch()) break;
        $try++;
        $candidate = $base . $last4 . $try;
        if ($try > 50) { $candidate = $base . time(); break; }
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

    $ins = $db->prepare("INSERT INTO patient_portal_accounts (patient_record_id, username, password_hash, password_plain_enc, password_plain_is_reset) VALUES (:pid,:u,:h,:p, 0)");
    $ins->execute(['pid'=>$patient_record_id,'u'=>$candidate,'h'=>$hash,'p'=>$enc_plain]);

    echo json_encode(['success'=>true,'patient_record_id'=>$patient_record_id,'username'=>$candidate,'password'=>$plain]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>



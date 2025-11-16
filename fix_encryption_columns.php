<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";

header('Content-Type: text/plain');

try {
    $db = (new Database())->getConnection();

    $stmts = [
        // Allow room for AES-GCM base64 strings
        "ALTER TABLE patient_records 
            MODIFY patient_name VARCHAR(255) NOT NULL,
            MODIFY contact_number VARCHAR(128) NOT NULL,
            MODIFY address TEXT NOT NULL",

        "ALTER TABLE opd_visits 
            MODIFY patient_name VARCHAR(255) NOT NULL,
            MODIFY contact_number VARCHAR(128) NOT NULL,
            MODIFY address TEXT NOT NULL"
    ];

    foreach ($stmts as $sql) {
        try {
            $db->exec($sql);
            echo "OK: $sql\n";
        } catch (Exception $e) {
            echo "NOTE: $sql -> " . $e->getMessage() . "\n";
        }
    }

    echo "\nDone. If you still see encrypted text for existing rows, those were already truncated and cannot be decrypted. New/updated entries will save correctly now.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
?>



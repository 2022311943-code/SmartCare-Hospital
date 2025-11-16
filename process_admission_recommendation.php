<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and is a doctor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['general_doctor', 'doctor'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    $db->beginTransaction();

    // First, check if patient exists in patients table
    $check_patient_query = "SELECT id FROM patients WHERE name = :name LIMIT 1";
    $check_patient_stmt = $db->prepare($check_patient_query);
    $check_patient_stmt->bindParam(":name", $_POST['patient_name']);
    $check_patient_stmt->execute();
    $patient = $check_patient_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        // Create new patient record
        $insert_patient = "INSERT INTO patients (
            name, age, gender, phone, address, doctor_id, added_by
        ) VALUES (
            :name, :age, :gender, :phone, :address, :doctor_id, :added_by
        )";
        
        $patient_stmt = $db->prepare($insert_patient);
        $enc_name = encrypt_strict($_POST['patient_name']);
        $enc_phone = encrypt_strict($_POST['contact_number']);
        $enc_addr = encrypt_strict($_POST['address']);
        $patient_stmt->bindParam(":name", $enc_name);
        $patient_stmt->bindParam(":age", $_POST['age']);
        $patient_stmt->bindParam(":gender", $_POST['gender']);
        $patient_stmt->bindParam(":phone", $enc_phone);
        $patient_stmt->bindParam(":address", $enc_addr);
        $patient_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
        $patient_stmt->bindParam(":added_by", $_SESSION['user_id']);
        $patient_stmt->execute();
        
        $patient_id = $db->lastInsertId();
    } else {
        $patient_id = $patient['id'];
    }

    // Create admission record
    $admission_query = "INSERT INTO patient_admissions (
        patient_id,
        admission_source,
        admission_notes,
        admitted_by
    ) VALUES (
        :patient_id,
        'opd',
        :admission_notes,
        :admitted_by
    )";

    $admission_stmt = $db->prepare($admission_query);
    $admission_stmt->bindParam(":patient_id", $patient_id);
    $enc_notes = encrypt_strict((string)$_POST['admission_notes']);
    $admission_stmt->bindParam(":admission_notes", $enc_notes);
    $admission_stmt->bindParam(":admitted_by", $_SESSION['user_id']);
    
    if ($admission_stmt->execute()) {
        $db->commit();
        echo json_encode(['success' => true]);
    } else {
        throw new Exception("Failed to create admission record");
    }
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
} 
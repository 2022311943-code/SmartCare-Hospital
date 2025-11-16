<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['receptionist', 'medical_staff', 'nurse', 'admin_staff', 'medical_records'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error_message = '';
$success_message = '';
$patient_data = null;
$previous_doctor = null;
$is_new_patient = true;

// Ensure drafts table exists for autosave
try {
	$db->exec("CREATE TABLE IF NOT EXISTS opd_registration_drafts (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT NOT NULL UNIQUE,
		data_json JSON NOT NULL,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* non-fatal */ }

// Ensure helper column for exact-name duplicate checks (for fast future lookups)
try {
	$db->exec("ALTER TABLE patient_records ADD COLUMN IF NOT EXISTS name_exact_hash CHAR(64) NULL");
} catch (Exception $e) { /* non-fatal */ }

// Helper: parse name into parts for prefill (best-effort)
function opd_parse_name_parts($full_name) {
    $parts = [
        'last_name' => '',
        'first_name' => '',
        'middle_name' => '',
        'suffix' => ''
    ];
    $full_name = trim((string)$full_name);
    if ($full_name === '') return $parts;
    $suffixes = ['Jr', 'Jr.', 'Sr', 'Sr.', 'II', 'III', 'IV', 'V'];
    if (strpos($full_name, ',') !== false) {
        list($last, $rest) = array_map('trim', explode(',', $full_name, 2));
        $parts['last_name'] = $last;
        $nameTokens = preg_split('/\s+/', $rest);
        if ($nameTokens) {
            $parts['first_name'] = array_shift($nameTokens);
            if ($nameTokens) {
                $maybeSuffix = end($nameTokens);
                if (in_array($maybeSuffix, $suffixes, true)) {
                    $parts['suffix'] = $maybeSuffix;
                    array_pop($nameTokens);
                }
                $parts['middle_name'] = implode(' ', $nameTokens);
            }
        }
    } else {
        $tokens = preg_split('/\s+/', $full_name);
        if (count($tokens) >= 2) {
            // Assume last token is last name
            $parts['last_name'] = array_pop($tokens);
            $maybeSuffix = end($tokens);
            if ($maybeSuffix && in_array($maybeSuffix, $suffixes, true)) {
                $parts['suffix'] = $maybeSuffix;
                array_pop($tokens);
            }
            $parts['first_name'] = array_shift($tokens);
            $parts['middle_name'] = implode(' ', $tokens);
        } else {
            $parts['first_name'] = $full_name;
        }
    }
    return $parts;
}

// Helper: extract 10-digit local number from a stored phone
function opd_extract_contact_digits($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (strpos($digits, '63') === 0 && strlen($digits) === 12) {
        return substr($digits, 2);
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
        return substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return $digits;
    }
    return '';
}

// Helper: parse address into components for prefill (best-effort)
function opd_parse_address_parts($address) {
    $result = [
        'house' => '',
        'street' => '',
        'barangay' => '',
        'city' => '',
        'postal' => '',
        'province' => ''
    ];
    $address = trim((string)$address);
    if ($address === '') return $result;
    $tokens = array_map('trim', array_filter(explode(',', $address)));
    if (!$tokens) return $result;
    $result['province'] = count($tokens) ? array_pop($tokens) : '';
    if ($tokens) {
        $maybePostalOrCity = array_pop($tokens);
        if (preg_match('/^\d{4}$/', $maybePostalOrCity)) {
            $result['postal'] = $maybePostalOrCity;
            $result['city'] = $tokens ? array_pop($tokens) : '';
        } else {
            $result['city'] = $maybePostalOrCity;
        }
    }
    $result['barangay'] = $tokens ? array_pop($tokens) : '';
    $result['street'] = $tokens ? array_pop($tokens) : '';
    $result['house'] = $tokens ? implode(', ', $tokens) : '';
    return $result;
}

// If patient_id is provided, get their details
if (isset($_GET['patient_id'])) {
    try {
        // Get patient details
        $patient_query = "SELECT * FROM patient_records WHERE id = :patient_id";
        $patient_stmt = $db->prepare($patient_query);
        $patient_stmt->bindParam(":patient_id", $_GET['patient_id']);
        $patient_stmt->execute();
        $patient_data = $patient_stmt->fetch(PDO::FETCH_ASSOC);
        // Decrypt sensitive fields for prefill when coming from follow-up link
        if ($patient_data) {
            if (isset($patient_data['patient_name'])) { $patient_data['patient_name'] = decrypt_safe((string)$patient_data['patient_name']); }
            if (isset($patient_data['contact_number'])) { $patient_data['contact_number'] = decrypt_safe((string)$patient_data['contact_number']); }
            if (isset($patient_data['address'])) { $patient_data['address'] = decrypt_safe((string)$patient_data['address']); }
        }

        if ($patient_data) {
            $is_new_patient = false;

            // Get the patient's last visit and doctor
            $last_visit_query = "SELECT 
                ov.*,
                u.id as doctor_id,
                u.name as doctor_name,
                u.employee_type,
                u.department
            FROM opd_visits ov
            JOIN users u ON ov.doctor_id = u.id
            WHERE ov.patient_record_id = :patient_id
            ORDER BY ov.created_at DESC
            LIMIT 1";
            
            $last_visit_stmt = $db->prepare($last_visit_query);
            $last_visit_stmt->bindParam(":patient_id", $_GET['patient_id']);
            $last_visit_stmt->execute();
            $previous_doctor = $last_visit_stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Get list of available doctors
$doctors_query = "SELECT id, name, employee_type, department 
                 FROM users 
                 WHERE (employee_type = 'general_doctor' OR employee_type = 'doctor')
                 AND status = 'active'
                 ORDER BY name";
$doctors_stmt = $db->prepare($doctors_query);
$doctors_stmt->execute();
$available_doctors = $doctors_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $patient_id = null;
			
			// For display/reference if needed
			$todayDate = date('Y-m-d');
			$nowTime = date('H:i');
        
        // Build full name from parts
        $post_last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
        $post_first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
        $post_middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
        $post_suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : '';
        // Server-side name validations (letters and spaces only)
        if ($post_last_name !== '' && !preg_match('/^[A-Za-z\s]+$/', $post_last_name)) {
            throw new Exception('Last name must contain letters and spaces only.');
        }
        if ($post_first_name !== '' && !preg_match('/^[A-Za-z\s]+$/', $post_first_name)) {
            throw new Exception('First name must contain letters and spaces only.');
        }
        if ($post_middle_name !== '' && !preg_match('/^[A-Za-z\s]+$/', $post_middle_name)) {
            throw new Exception('Middle name must contain letters and spaces only.');
        }
        $patient_full_name = '';
        if ($post_last_name !== '' || $post_first_name !== '' || $post_middle_name !== '' || $post_suffix !== '') {
            if ($post_last_name === '' || $post_first_name === '') {
                throw new Exception('Please provide at least last name and first name.');
            }
            $patient_full_name = $post_last_name . ', ' . $post_first_name;
            if ($post_middle_name !== '') { $patient_full_name .= ' ' . $post_middle_name; }
            if ($post_suffix !== '') { $patient_full_name .= ' ' . $post_suffix; }
        } else {
            // Fallback if old single-field is somehow posted
            $patient_full_name = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : '';
        }

        // Compose contact number: +63 + 10 digits
        $contact_digits = isset($_POST['contact_digits']) ? preg_replace('/\D+/', '', $_POST['contact_digits']) : '';
        if (strlen($contact_digits) !== 10) {
            throw new Exception('Contact number must be exactly 10 digits after +63.');
        }
        $full_contact = '+63' . $contact_digits;

        // Compose formal address
        $addr_house = isset($_POST['address_house']) ? trim($_POST['address_house']) : '';
        $addr_street = isset($_POST['address_street']) ? trim($_POST['address_street']) : '';
        $addr_barangay = isset($_POST['address_barangay']) ? trim($_POST['address_barangay']) : '';
        $addr_city = isset($_POST['address_city']) ? trim($_POST['address_city']) : '';
        $addr_postal = isset($_POST['address_postal']) ? trim($_POST['address_postal']) : '';
        $addr_province = isset($_POST['address_province']) ? trim($_POST['address_province']) : '';
        if ($addr_province === '' || !preg_match('/^[A-Za-z\s]+$/', $addr_province)) {
            throw new Exception('Province must contain letters and spaces only.');
        }
        if ($addr_street === '' || $addr_barangay === '' || $addr_city === '' || $addr_postal === '' || $addr_province === '') {
            throw new Exception('Please complete the address fields.');
        }
        // Postal code server-side enforcement: exactly 4 digits
        if (!preg_match('/^\d{4}$/', $addr_postal)) {
            throw new Exception('Postal code must be exactly 4 digits.');
        }
        $address_components = [];
        if ($addr_house !== '') { $address_components[] = $addr_house; }
        $address_components[] = $addr_street;
        $address_components[] = $addr_barangay;
        $address_components[] = $addr_city;
        $address_components[] = $addr_postal;
        $address_components[] = $addr_province;
        $full_address = implode(', ', $address_components);
			
			// Additional demographics from paper OPD form
			$birthdate = isset($_POST['birthdate']) && $_POST['birthdate'] !== '' ? $_POST['birthdate'] : null;
			if ($birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
				throw new Exception('Invalid birthdate format.');
			}
			$civil_status = isset($_POST['civil_status']) ? $_POST['civil_status'] : null;
			if ($civil_status && !in_array($civil_status, ['single','married','widowed','separated','unknown'], true)) {
				throw new Exception('Invalid civil status.');
			}
			$occupation = isset($_POST['occupation']) ? trim($_POST['occupation']) : null;
			$head_of_family = isset($_POST['head_of_family']) ? trim($_POST['head_of_family']) : null;
			$religion = isset($_POST['religion']) ? trim($_POST['religion']) : null;
			$philhealth = isset($_POST['philhealth']) ? $_POST['philhealth'] : null;
			if ($philhealth && !in_array($philhealth, ['yes','no'], true)) {
				throw new Exception('Invalid PhilHealth selection.');
			}
        
        // If it's a new patient, check for exact-name duplicates then create record in patient_records
        if ($is_new_patient) {
            // Exact-match duplicate check on full name (case-sensitive by spec: "exactly same")
            $name_hash = hash('sha256', $patient_full_name);
            try {
                // Fast path: rows with same hash; also scan legacy rows with NULL hash
                $dup = $db->prepare("SELECT id, patient_name, name_exact_hash FROM patient_records WHERE name_exact_hash = :h OR name_exact_hash IS NULL");
                $dup->bindParam(':h', $name_hash);
                $dup->execute();
                while ($row = $dup->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($row['name_exact_hash']) && $row['name_exact_hash'] === $name_hash) {
                        throw new Exception('This patient already exists in records.');
                    }
                    if (!isset($row['name_exact_hash']) || $row['name_exact_hash'] === null) {
                        $dec = decrypt_safe((string)$row['patient_name']);
                        if ($dec === $patient_full_name) {
                            // Backfill hash for future fast lookups
                            try {
                                $bf = $db->prepare("UPDATE patient_records SET name_exact_hash = :h WHERE id = :id");
                                $bf->execute([':h' => $name_hash, ':id' => (int)$row['id']]);
                            } catch (Exception $e) { /* ignore */ }
                            throw new Exception('This patient already exists in records.');
                        }
                    }
                }
            } catch (Exception $dupEx) {
                // Bubble up to the main error handler
                throw $dupEx;
            }

            $insert_patient = "INSERT INTO patient_records (
                patient_name, age, gender, contact_number, address, created_at, name_exact_hash
            ) VALUES (
                :name, :age, :gender, :contact, :address, NOW(), :neh
            )";
            
            $patient_stmt = $db->prepare($insert_patient);
            $enc_name = encrypt_strict($patient_full_name);
            $enc_contact = encrypt_strict($full_contact);
            $enc_address = encrypt_strict($full_address);
            $patient_stmt->bindParam(':name', $enc_name);
            $patient_stmt->bindParam(':age', $_POST['age']);
            $patient_stmt->bindParam(':gender', $_POST['gender']);
            $patient_stmt->bindParam(':contact', $enc_contact);
            $patient_stmt->bindParam(':address', $enc_address);
            $patient_stmt->bindParam(':neh', $name_hash);
            $patient_stmt->execute();
            
            $patient_id = $db->lastInsertId();
        } else {
            $patient_id = $_GET['patient_id'];
        }

        // Doctor selection for follow-up: honor the user's selection (no forced override).
        // Validate that the selected doctor is active and of correct type.
        $doctor_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
        $validate_doctor = $db->prepare("SELECT id FROM users 
                                         WHERE id = :doctor_id 
                                           AND status = 'active' 
                                           AND (employee_type = 'general_doctor' OR employee_type = 'doctor')
                                         LIMIT 1");
        $validate_doctor->bindParam(':doctor_id', $doctor_id, PDO::PARAM_INT);
        $validate_doctor->execute();
        if (!$validate_doctor->fetch()) {
            throw new Exception('Please select a valid active doctor.');
        }

        // Create new OPD visit
        $insert_visit = "INSERT INTO opd_visits (
				patient_record_id, patient_name, age, gender, contact_number, address,
				birthdate, civil_status, occupation, head_of_family, religion, philhealth,
				arrival_time, symptoms, temperature, blood_pressure, pulse_rate,
				respiratory_rate, oxygen_saturation, height_cm, weight_kg,
				visit_type, pregnancy_status, visit_status, registered_by, doctor_id,
				lmp, medical_history, medications, noi, poi, doi, toi
        ) VALUES (
				:patient_id, :name, :age, :gender, :contact, :address,
				:birthdate, :civil_status, :occupation, :head_of_family, :religion, :philhealth,
				NOW(), :symptoms, :temperature, :blood_pressure, :pulse_rate,
				:respiratory_rate, :oxygen_saturation, :height_cm, :weight_kg,
				:visit_type, :pregnancy_status, 'waiting', :registered_by, :doctor_id,
				:lmp, :medical_history, :medications, :noi, :poi, :doi, :toi
        )";
        
        // Store values in variables before binding
        $visit_patient_id = $patient_id;
        $visit_name = $patient_full_name;
        $visit_age = $_POST['age'];
        if (!ctype_digit((string)$visit_age) || (int)$visit_age < 1) {
            throw new Exception('Age must be a positive integer.');
        }
        $visit_gender = $_POST['gender'];
        $visit_contact = $full_contact;
        $visit_address = $full_address;
        $visit_symptoms = $_POST['symptoms'];
        $visit_temperature = $_POST['temperature'];
        if (!is_numeric($visit_temperature) || (float)$visit_temperature <= 0) {
            throw new Exception('Temperature must be a positive number.');
        }
        $visit_blood_pressure = $_POST['blood_pressure'];
        if (!preg_match('/^\d{2,3}\/\d{2,3}$/', $visit_blood_pressure)) {
            throw new Exception('Blood pressure must be in format 120/80 using numbers.');
        }
        $visit_pulse_rate = $_POST['pulse_rate'];
        $visit_respiratory_rate = $_POST['respiratory_rate'];
			$visit_oxygen_saturation = isset($_POST['oxygen_saturation']) && $_POST['oxygen_saturation'] !== '' ? (int)$_POST['oxygen_saturation'] : null;
			if ($visit_oxygen_saturation !== null && ($visit_oxygen_saturation < 0 || $visit_oxygen_saturation > 100)) {
				throw new Exception('Oxygen saturation must be between 0 and 100.');
			}
			$visit_height_cm = isset($_POST['height_cm']) && $_POST['height_cm'] !== '' ? (float)$_POST['height_cm'] : null;
			$visit_weight_kg = isset($_POST['weight_kg']) && $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
        $visit_type = $is_new_patient ? 'new' : 'follow_up';
        $visit_registered_by = $_SESSION['user_id'];
        $pregnancy_status = isset($_POST['pregnancy_status']) ? $_POST['pregnancy_status'] : 'none';
			$lmp_date = isset($_POST['lmp']) && $_POST['lmp'] !== '' ? $_POST['lmp'] : null;
			if ($lmp_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lmp_date)) {
				throw new Exception('Invalid LMP date.');
			}
			$medical_history = isset($_POST['medical_history']) ? trim($_POST['medical_history']) : '';
			$medications = isset($_POST['medications']) ? trim($_POST['medications']) : '';
			$noi = isset($_POST['noi']) ? trim($_POST['noi']) : null;
			$poi = isset($_POST['poi']) ? trim($_POST['poi']) : null;
			$doi = isset($_POST['doi']) && $_POST['doi'] !== '' ? $_POST['doi'] : null;
			if ($doi && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $doi)) { throw new Exception('Invalid DOI date.'); }
			$toi = isset($_POST['toi']) && $_POST['toi'] !== '' ? $_POST['toi'] : null;
			if ($toi && !preg_match('/^\d{2}:\d{2}$/', $toi)) { throw new Exception('Invalid TOI time.'); }
        
        $visit_stmt = $db->prepare($insert_visit);
        $enc_visit_name = encrypt_strict($visit_name);
        $enc_visit_contact = encrypt_strict($visit_contact);
        $enc_visit_address = encrypt_strict($visit_address);
        $enc_symptoms = encrypt_strict($visit_symptoms);
			$enc_med_history = encrypt_strict($medical_history);
			$enc_medications = encrypt_strict($medications);
        $visit_stmt->bindParam(':patient_id', $visit_patient_id);
        $visit_stmt->bindParam(':name', $enc_visit_name);
        $visit_stmt->bindParam(':age', $visit_age);
        $visit_stmt->bindParam(':gender', $visit_gender);
        $visit_stmt->bindParam(':contact', $enc_visit_contact);
        $visit_stmt->bindParam(':address', $enc_visit_address);
			$visit_stmt->bindParam(':birthdate', $birthdate);
			$visit_stmt->bindParam(':civil_status', $civil_status);
			$visit_stmt->bindParam(':occupation', $occupation);
			$visit_stmt->bindParam(':head_of_family', $head_of_family);
			$visit_stmt->bindParam(':religion', $religion);
			$visit_stmt->bindParam(':philhealth', $philhealth);
        $visit_stmt->bindParam(':symptoms', $enc_symptoms);
        $visit_stmt->bindParam(':temperature', $visit_temperature);
        $visit_stmt->bindParam(':blood_pressure', $visit_blood_pressure);
        $visit_stmt->bindParam(':pulse_rate', $visit_pulse_rate);
        $visit_stmt->bindParam(':respiratory_rate', $visit_respiratory_rate);
			$visit_stmt->bindParam(':oxygen_saturation', $visit_oxygen_saturation);
			$visit_stmt->bindParam(':height_cm', $visit_height_cm);
			$visit_stmt->bindParam(':weight_kg', $visit_weight_kg);
        $visit_stmt->bindParam(':visit_type', $visit_type);
        $visit_stmt->bindParam(':pregnancy_status', $pregnancy_status);
        $visit_stmt->bindParam(':registered_by', $visit_registered_by);
        $visit_stmt->bindParam(':doctor_id', $doctor_id);
			$visit_stmt->bindParam(':lmp', $lmp_date);
			$visit_stmt->bindParam(':medical_history', $enc_med_history);
			$visit_stmt->bindParam(':medications', $enc_medications);
			$visit_stmt->bindParam(':noi', $noi);
			$visit_stmt->bindParam(':poi', $poi);
			$visit_stmt->bindParam(':doi', $doi);
			$visit_stmt->bindParam(':toi', $toi);
        $visit_stmt->execute();
        $new_visit_id = $db->lastInsertId();

        // Handle external lab upload (optional)
        if (isset($_POST['ext_lab_toggle']) && $_POST['ext_lab_toggle'] === '1' && isset($_FILES['ext_lab_files'])) {
            // Ensure upload dir
            $targetDir = __DIR__ . '/uploads/lab_results';
            if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
            // Ensure a lab test exists for external uploads
            $extTestId = null;
            try {
                $selExt = $db->prepare("SELECT id FROM lab_tests WHERE test_name = 'External Upload' LIMIT 1");
                $selExt->execute();
                $rowExt = $selExt->fetch(PDO::FETCH_ASSOC);
                if ($rowExt && isset($rowExt['id'])) {
                    $extTestId = (int)$rowExt['id'];
                } else {
                    $insExt = $db->prepare("INSERT INTO lab_tests (test_name, test_type, cost, description, created_at) VALUES ('External Upload','laboratory',0.00,'External lab file upload', NOW())");
                    $insExt->execute();
                    $extTestId = (int)$db->lastInsertId();
                }
            } catch (Exception $e) { $extTestId = null; }
            $files = $_FILES['ext_lab_files'];
            $num = is_array($files['name']) ? count($files['name']) : 0;
            for ($i=0; $i<$num; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { continue; }
                $orig = basename($files['name'][$i]);
                $ext = pathinfo($orig, PATHINFO_EXTENSION);
                $safeName = 'extlab_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? ('.' . $ext) : '');
                $tmpPath = $files['tmp_name'][$i];
                $destPath = $targetDir . '/' . $safeName;
                if (@move_uploaded_file($tmpPath, $destPath)) {
                    if ($extTestId) {
                        // Store a lab_requests row with status completed and an indicator
                        $insert_extlab = "INSERT INTO lab_requests (visit_id, doctor_id, test_id, priority, status, payment_status, notes, result, result_file_path, requested_at, completed_at) 
                                          VALUES (:visit_id, :doctor_id, :test_id, 'normal', 'completed', 'paid', :notes, :result, :file, NOW(), NOW())";
                        $stmt_ext = $db->prepare($insert_extlab);
                        $notes = encrypt_strict('[EXTERNAL] Uploaded lab result');
                        $result = encrypt_strict('External lab document attached.');
                        $fileUrl = 'uploads/lab_results/' . $safeName;
                        $enc_file = encrypt_strict($fileUrl);
                        $stmt_ext->bindParam(':visit_id', $new_visit_id);
                        $stmt_ext->bindParam(':doctor_id', $doctor_id);
                        $stmt_ext->bindParam(':test_id', $extTestId);
                        $stmt_ext->bindParam(':notes', $notes);
                        $stmt_ext->bindParam(':result', $result);
                        $stmt_ext->bindParam(':file', $enc_file);
                        try { $stmt_ext->execute(); } catch (Exception $ignore) {}
                    }
                }
            }
        }

        $db->commit();

        // Clear draft for this user upon successful registration
        try {
            $delDraft = $db->prepare("DELETE FROM opd_registration_drafts WHERE user_id = :uid");
            $delDraft->bindValue(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
            $delDraft->execute();
        } catch (Exception $e) { /* ignore */ }
        $success_message = "Patient registered successfully!";
        
        // Redirect to OPD queue after successful registration
        header("Location: opd_queue.php");
        exit();

    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Registration failed: " . $e->getMessage();
    }
}

// Set page title
$page_title = $is_new_patient ? 'New OPD Registration' : 'Follow-up Registration';
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - MediCore</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
        }
        .navbar {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            padding: 1rem;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .card-header {
            background: white;
            border-radius: 15px 15px 0 0 !important;
            border-bottom: 1px solid #eee;
        }
        /* Paper OPD form styling */
        .paper-form {
            background: #ffffff;
            border: 1px solid #000;
            padding: 18px 18px 10px 18px;
            max-width: 100%;
            overflow-x: hidden;
        }
        .paper-title {
            font-weight: 700;
            letter-spacing: .3px;
        }
        .underline-input,
        .underline-select {
            border: none !important;
            border-bottom: 1px solid #000 !important;
            border-radius: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            background: transparent !important;
        }
        .underline-input:focus,
        .underline-select:focus,
        .underline-textarea:focus {
            box-shadow: none !important;
        }
        .underline-textarea {
            width: 100%;
            border: 1px solid #000;
            min-height: 140px;
            resize: vertical;
        }
        .paper-small {
            font-size: .9rem;
        }
        .paper-label {
            font-weight: 600;
            color: #111;
            margin-bottom: .25rem;
        }
        .v-divider {
            border-left: 1px solid #000;
            height: 100%;
        }
        .vitals-row .form-control.underline-input {
            text-align: right;
        }
        .unit {
            min-width: 42px;
            display: inline-block;
            text-align: left;
            font-weight: 600;
            color: #222;
        }
        .paper-topline {
            border-top: 1px solid #000;
            padding-top: 10px;
        }
        .paper-section-title {
            font-weight: 700;
            font-size: .95rem;
            margin-bottom: .35rem;
        }
        /* number inputs – hide spinners for a paper look */
        input[type=number].underline-input::-webkit-outer-spin-button,
        input[type=number].underline-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number].underline-input {
            -moz-appearance: textfield;
        }
        .form-label {
            font-weight: 600;
            color: #2c3e50;
        }
        .previous-doctor-alert {
            background-color: #e8f4f8;
            border-left: 4px solid #17a2b8;
        }
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            color: #fff;
        }
        .loading-overlay .loading-box {
            text-align: center;
        }
        /* Validation highlights for paper-style controls */
        .was-validated .underline-input:invalid,
        .underline-input.is-invalid,
        .was-validated .underline-select:invalid,
        .underline-select.is-invalid {
            border-bottom: 2px solid #dc3545 !important;
        }
        .was-validated .underline-textarea:invalid,
        .underline-textarea.is-invalid {
            border-color: #dc3545 !important;
        }
        .page-header-bar {
            gap: 1rem;
        }
        .page-header-actions {
            flex-wrap: wrap;
        }
        .page-header-actions .btn {
            white-space: nowrap;
        }
        .assign-panel {
            min-width: 320px;
        }
        @media (max-width: 768px) {
            .page-header-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            .page-header-actions {
                width: 100%;
                gap: 0.5rem;
            }
            .page-header-actions .btn {
                width: 100%;
            }
            .assign-panel {
                min-width: 0;
                width: 100%;
                text-align: left !important;
            }
            .card {
                border-radius: 12px;
            }
            .paper-form {
                padding: 12px;
            }
            .paper-form .row {
                --bs-gutter-x: 0.75rem;
                --bs-gutter-y: 0.75rem;
            }
            .paper-form .underline-input,
            .paper-form .underline-select {
                font-size: 0.95rem;
            }
            .paper-form .input-group-text {
                font-size: 0.9rem;
            }
        }
        @media (max-width: 576px) {
            .paper-form {
                padding: 10px;
            }
            .paper-form .row > [class*="col-"] {
                flex: 0 0 100%;
                max-width: 100%;
            }
            .paper-section-title {
                font-size: 0.9rem;
            }
            .paper-form .text-end {
                text-align: center !important;
            }
            .assign-panel {
                text-align: center !important;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header py-3">
                        <div class="d-flex justify-content-between align-items-center page-header-bar">
                            <h5 class="mb-0">
                                <i class="fas fa-user-plus me-2"></i><?php echo $page_title; ?>
                            </h5>
                            <div class="d-flex align-items-center gap-2 page-header-actions">
                                <a href="javascript:history.back()" class="btn btn-light btn-sm">
                                    <i class="fas fa-arrow-left me-1"></i>Back
                                </a>
                                <a href="opd_queue.php" class="btn btn-outline-danger btn-sm">
                                    <i class="fas fa-list me-2"></i>View OPD Queue
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success_message): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                            </div>
                        <?php endif; ?>
                        <div id="clientValidationAlert" class="alert alert-danger d-none">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Please complete the required fields highlighted in red.
                        </div>

                        <?php if ($previous_doctor): ?>
                            <div class="alert previous-doctor-alert">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Previous Visit Information:</strong><br>
                                Last seen by: Dr. <?php echo htmlspecialchars($previous_doctor['doctor_name']); ?><br>
                                Department: <?php echo htmlspecialchars($previous_doctor['department']); ?><br>
                                Visit Date: <?php echo date('M d, Y', strtotime($previous_doctor['created_at'])); ?>
                                <div class="mt-2 small text-muted">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Patient will be automatically assigned to their previous doctor if available.
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="needs-validation" novalidate enctype="multipart/form-data">
							<?php
							$pref_last = '';
							$pref_first = '';
							$pref_middle = '';
							$pref_suffix = '';
							if ($patient_data) {
								$np = opd_parse_name_parts($patient_data['patient_name'] ?? '');
								$pref_last = $np['last_name'];
								$pref_first = $np['first_name'];
								$pref_middle = $np['middle_name'];
								$pref_suffix = $np['suffix'];
							}
							$addr_house = '';
							$addr_street = '';
							$addr_barangay = '';
							$addr_city = '';
							$addr_postal = '';
							$addr_province = '';
							if ($patient_data) {
								$ap = opd_parse_address_parts($patient_data['address'] ?? '');
								$addr_house = $ap['house'];
								$addr_street = $ap['street'];
								$addr_barangay = $ap['barangay'];
								$addr_city = $ap['city'];
								$addr_postal = $ap['postal'];
								$addr_province = $ap['province'];
							}
							$pref_digits = $patient_data ? opd_extract_contact_digits($patient_data['contact_number'] ?? '') : '';
							?>
							<?php
							// Additional demographics prefill from last OPD visit when available (for follow-ups)
							$pref_birthdate = '';
							$pref_civil_status = '';
							$pref_occupation = '';
							$pref_head_of_family = '';
							$pref_religion = '';
							$pref_philhealth = '';
							if ($previous_doctor) {
								$pref_birthdate = isset($previous_doctor['birthdate']) ? (string)$previous_doctor['birthdate'] : '';
								$pref_civil_status = isset($previous_doctor['civil_status']) ? (string)$previous_doctor['civil_status'] : '';
								$pref_occupation = isset($previous_doctor['occupation']) ? (string)$previous_doctor['occupation'] : '';
								$pref_head_of_family = isset($previous_doctor['head_of_family']) ? (string)$previous_doctor['head_of_family'] : '';
								$pref_religion = isset($previous_doctor['religion']) ? (string)$previous_doctor['religion'] : '';
								$pref_philhealth = isset($previous_doctor['philhealth']) ? (string)$previous_doctor['philhealth'] : '';
							}
							?>
							<div class="paper-form">
								<div class="d-flex justify-content-between align-items-end">
									<div>
										<div class="paper-title">Outpatient Department (OPD) Consultation Form</div>
										<div class="paper-small text-muted">Please accomplish legibly. Fields with lines are required unless marked optional.</div>
									</div>
									<div class="text-end assign-panel">
										<label class="paper-label mb-1">Assign Doctor</label>
										<select class="form-select underline-select" name="doctor_id" required>
											<option value="">Select Doctor</option>
											<?php 
											$pregSel = isset($_POST['pregnancy_status']) ? $_POST['pregnancy_status'] : 'none';
											if ($pregSel === 'pregnant' || $pregSel === 'labor') {
												$docStmt = $db->prepare("SELECT id, name, employee_type, department FROM users WHERE employee_type = 'doctor' AND department IN ('OB-GYN','General Physician') AND status='active' ORDER BY name");
											} else {
												$docStmt = $db->prepare("SELECT id, name, employee_type, department FROM users WHERE (employee_type IN ('general_doctor','doctor')) AND status='active' ORDER BY name");
											}
											$docStmt->execute();
											$docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
											foreach($docs as $doctor): ?>
												<option value="<?php echo $doctor['id']; ?>" 
													data-department="<?php echo htmlspecialchars($doctor['department']); ?>"
													<?php echo ($previous_doctor && $previous_doctor['doctor_id'] === $doctor['id']) ? 'selected' : ''; ?>>
													Dr. <?php echo htmlspecialchars($doctor['name']); ?> (<?php echo htmlspecialchars($doctor['department']); ?>)
												</option>
											<?php endforeach; ?>
										</select>
										<div class="row gx-2 mt-2">
											<div class="col">
												<label class="paper-label">Date</label>
												<input type="date" class="form-control underline-input" value="<?php echo date('Y-m-d'); ?>" readonly>
											</div>
											<div class="col">
												<label class="paper-label">Time</label>
												<input type="time" class="form-control underline-input" value="<?php echo date('H:i'); ?>" readonly>
											</div>
										</div>
									</div>
								</div>

								<div class="paper-topline mt-3">
									<div class="row gx-3 gy-2 align-items-end">
										<div class="col-md-3">
											<label class="paper-label">Apelyido (Last Name)</label>
											<input type="text" class="form-control underline-input" name="last_name"
												value="<?php echo htmlspecialchars($pref_last); ?>"
												pattern="[A-Za-z\s]+" title="Letters and spaces only"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
										<div class="col-md-3">
											<label class="paper-label">Pangalan (First Name)</label>
											<input type="text" class="form-control underline-input" name="first_name"
												value="<?php echo htmlspecialchars($pref_first); ?>"
												pattern="[A-Za-z\s]+" title="Letters and spaces only"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
										<div class="col-md-3">
											<label class="paper-label">Gitnang Apelyido (Middle)</label>
											<input type="text" class="form-control underline-input" name="middle_name"
												value="<?php echo htmlspecialchars($pref_middle); ?>"
												pattern="[A-Za-z\s]+" title="Letters and spaces only"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?>>
										</div>
										<div class="col-md-3">
											<label class="paper-label">Suffix</label>
											<input type="text" class="form-control underline-input" name="suffix"
												value="<?php echo htmlspecialchars($pref_suffix); ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?>>
										</div>
									</div>
									<div class="row gx-3 gy-2 align-items-end mt-1">
										<div class="col-md-2">
											<label class="paper-label">Edad (Age)</label>
											<input type="number" class="form-control underline-input" name="age" inputmode="numeric"
												value="<?php echo $patient_data ? $patient_data['age'] : ''; ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
										<div class="col-md-3">
											<label class="paper-label">Sex</label>
											<select class="form-select underline-select" name="gender" <?php echo !$is_new_patient ? 'disabled' : ''; ?> required>
												<option value="">Select</option>
												<option value="male" <?php echo ($patient_data && $patient_data['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
												<option value="female" <?php echo ($patient_data && $patient_data['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
											</select>
											<?php if (!$is_new_patient): ?>
												<input type="hidden" name="gender" value="<?php echo $patient_data['gender']; ?>">
											<?php endif; ?>
										</div>
										<div class="col-md-3">
											<label class="paper-label">Civil Status</label>
											<?php $csVal = isset($_POST['civil_status']) ? $_POST['civil_status'] : $pref_civil_status; ?>
											<select class="form-select underline-select" name="civil_status">
												<option value="">Select</option>
												<option value="single" <?php echo ($csVal==='single' ? 'selected' : ''); ?>>Single</option>
												<option value="married" <?php echo ($csVal==='married' ? 'selected' : ''); ?>>Married</option>
												<option value="widowed" <?php echo ($csVal==='widowed' ? 'selected' : ''); ?>>Widowed</option>
												<option value="separated" <?php echo ($csVal==='separated' ? 'selected' : ''); ?>>Separated</option>
												<option value="unknown" <?php echo ($csVal==='unknown' ? 'selected' : ''); ?>>Unknown</option>
											</select>
										</div>
										<div class="col-md-4">
											<label class="paper-label">Birthdate</label>
											<input type="date" class="form-control underline-input" name="birthdate" value="<?php echo htmlspecialchars(isset($_POST['birthdate']) ? $_POST['birthdate'] : $pref_birthdate); ?>">
										</div>
									</div>

									<div class="row gx-3 gy-2 align-items-end mt-1">
										<div class="col-md-5">
											<label class="paper-label">Address - House/Unit/Building (optional)</label>
											<input type="text" class="form-control underline-input" name="address_house"
												value="<?php echo htmlspecialchars($addr_house); ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?>>
										</div>
										<div class="col-md-7">
											<label class="paper-label">Building No. and Street</label>
											<input type="text" class="form-control underline-input" name="address_street"
												value="<?php echo htmlspecialchars($addr_street); ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
									</div>
									<div class="row gx-3 gy-2 align-items-end mt-1">
										<div class="col-md-4">
											<label class="paper-label">Barangay</label>
											<input type="text" class="form-control underline-input" name="address_barangay"
												value="<?php echo htmlspecialchars($addr_barangay); ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
										<div class="col-md-3">
											<label class="paper-label">City/Municipality</label>
											<input type="text" class="form-control underline-input" name="address_city"
												value="<?php echo htmlspecialchars($addr_city); ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
										<div class="col-md-2">
											<label class="paper-label">Postal</label>
											<input type="text" class="form-control underline-input" name="address_postal"
												pattern="\d{4}" inputmode="numeric" maxlength="4"
												value="<?php echo htmlspecialchars($addr_postal); ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
										<div class="col-md-3">
											<label class="paper-label">Province</label>
											<input type="text" class="form-control underline-input" name="address_province"
												pattern="[A-Za-z\s]+" title="Letters and spaces only"
												value="<?php echo htmlspecialchars($addr_province); ?>"
												<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
										</div>
									</div>

									<div class="row gx-3 gy-2 align-items-end mt-1">
										<div class="col-md-4">
											<label class="paper-label">Contact No.</label>
											<div class="input-group">
												<span class="input-group-text">+63</span>
												<input type="text" class="form-control underline-input" name="contact_digits"
													inputmode="numeric" pattern="\d{10}" maxlength="10"
													value="<?php echo htmlspecialchars($pref_digits); ?>"
													<?php echo !$is_new_patient ? 'readonly' : ''; ?> required>
											</div>
											<div class="form-text">10 digits only after +63.</div>
										</div>
										<div class="col-md-4">
											<label class="paper-label">Occupation</label>
											<input type="text" class="form-control underline-input" name="occupation" value="<?php echo htmlspecialchars(isset($_POST['occupation']) ? $_POST['occupation'] : $pref_occupation); ?>">
										</div>
										<div class="col-md-4">
											<label class="paper-label">Head of the Family</label>
											<input type="text" class="form-control underline-input" name="head_of_family" value="<?php echo htmlspecialchars(isset($_POST['head_of_family']) ? $_POST['head_of_family'] : $pref_head_of_family); ?>">
										</div>
									</div>

									<div class="row gx-3 gy-2 align-items-end mt-1">
										<div class="col-md-4">
											<label class="paper-label">Religion</label>
											<input type="text" class="form-control underline-input" name="religion" pattern="[A-Za-z\s]+" title="Letters and spaces only" value="<?php echo htmlspecialchars(isset($_POST['religion']) ? $_POST['religion'] : $pref_religion); ?>">
										</div>
										<div class="col-md-4">
											<label class="paper-label d-block">PhilHealth</label>
											<div class="d-flex gap-3 align-items-center">
												<div class="form-check">
													<input class="form-check-input" type="radio" name="philhealth" id="phYes" value="yes" <?php
														$phVal = isset($_POST['philhealth']) ? $_POST['philhealth'] : $pref_philhealth;
														echo ($phVal==='yes' ? 'checked' : '');
													?>>
													<label class="form-check-label" for="phYes">Yes</label>
												</div>
												<div class="form-check">
													<input class="form-check-input" type="radio" name="philhealth" id="phNo" value="no" <?php
														$phVal = isset($_POST['philhealth']) ? $_POST['philhealth'] : $pref_philhealth;
														echo ($phVal==='no' ? 'checked' : '');
													?>>
													<label class="form-check-label" for="phNo">No</label>
												</div>
											</div>
										</div>
										<div class="col-md-4" id="pregnancyField">
											<label class="paper-label">Pregnancy Status</label>
											<select class="form-select underline-select" name="pregnancy_status">
												<option value="none" <?php echo (isset($_POST['pregnancy_status']) && $_POST['pregnancy_status']==='none') ? 'selected' : ''; ?>>Not Pregnant</option>
												<option value="pregnant" <?php echo (isset($_POST['pregnancy_status']) && $_POST['pregnancy_status']==='pregnant') ? 'selected' : ''; ?>>Pregnant</option>
												<option value="labor" <?php echo (isset($_POST['pregnancy_status']) && $_POST['pregnancy_status']==='labor') ? 'selected' : ''; ?>>In Labor / Giving Birth</option>
											</select>
										</div>
									</div>
								</div>

								<div class="row g-0 mt-3">
									<div class="col-md-7 pe-md-3">
										<div class="paper-section-title">Chief Complaint (as verbalized)</div>
										<textarea class="underline-textarea" name="symptoms" required></textarea>
									</div>
									<div class="col-md-5 ps-md-3 mt-3 mt-md-0">
										<div class="paper-section-title d-flex justify-content-between">
											<span>Vital Signs</span>
										</div>
										<div class="row vitals-row gy-1 align-items-end">
											<div class="col-7 paper-small">BP</div>
											<div class="col-5">
												<input type="text" class="form-control underline-input" name="blood_pressure" placeholder="120/80" inputmode="numeric" pattern="\d{2,3}/\d{2,3}" title="Use numbers only, format 120/80" required>
											</div>
											<div class="col-7 paper-small">PR <span class="unit">bpm</span></div>
											<div class="col-5">
												<input type="number" class="form-control underline-input" name="pulse_rate" inputmode="numeric" step="1" required>
											</div>
											<div class="col-7 paper-small">RR <span class="unit">cpm</span></div>
											<div class="col-5">
												<input type="number" class="form-control underline-input" name="respiratory_rate" inputmode="numeric" step="1" required>
											</div>
											<div class="col-7 paper-small">Temp <span class="unit">°C</span></div>
											<div class="col-5">
												<input type="number" step="0.1" class="form-control underline-input" name="temperature" inputmode="decimal" required>
											</div>
											<div class="col-7 paper-small">SpO₂ <span class="unit">%</span></div>
											<div class="col-5">
												<input type="number" class="form-control underline-input" name="oxygen_saturation" inputmode="numeric" min="0" max="100" step="1" placeholder="98">
											</div>
											<div class="col-7 paper-small">Height <span class="unit">cm</span></div>
											<div class="col-5">
												<input type="number" step="0.1" class="form-control underline-input" name="height_cm" inputmode="decimal" placeholder="165.0">
											</div>
											<div class="col-7 paper-small">Weight <span class="unit">kg</span></div>
											<div class="col-5">
												<input type="number" step="0.1" class="form-control underline-input" name="weight_kg" inputmode="decimal" placeholder="60.0">
											</div>
										</div>
										<div class="mt-3">
											<div class="paper-section-title">Incident/Onset Details</div>
											<div class="mb-2">
												<label class="paper-label">NOI</label>
												<input type="text" class="form-control underline-input" name="noi" placeholder="Nature of Injury / Onset">
											</div>
											<div class="mb-2">
												<label class="paper-label">POI</label>
												<input type="text" class="form-control underline-input" name="poi" placeholder="Place of Injury / Onset">
											</div>
											<div class="row gx-2">
												<div class="col-6">
													<label class="paper-label">DOI</label>
													<input type="date" class="form-control underline-input" name="doi">
													<!-- Year length is enforced via JS -->
												</div>
												<div class="col-6">
													<label class="paper-label">TOI</label>
													<input type="time" class="form-control underline-input" name="toi">
												</div>
											</div>
										</div>
										<div class="row gx-3 mt-3">
											<div class="col-12" id="lmpField">
												<label class="paper-label">Last Menstrual Period (LMP)</label>
												<input type="date" class="form-control underline-input" name="lmp">
											</div>
										</div>
										<div class="mt-2">
											<label class="paper-label">Medical History</label>
											<textarea class="underline-textarea" name="medical_history" placeholder="e.g., DM, HTN, PTB, allergies, surgeries, etc."></textarea>
										</div>
										<div class="mt-2">
											<label class="paper-label">Medications Taken</label>
											<textarea class="underline-textarea" name="medications" placeholder="List current medications"></textarea>
										</div>
									</div>
								</div>

								<div class="mt-3">
									<div class="paper-section-title mb-1">External Laboratory</div>
									<div class="form-check form-switch">
										<input class="form-check-input" type="checkbox" id="extLabToggle" name="ext_lab_toggle" value="1">
										<label class="form-check-label" for="extLabToggle">Lab test done outside?</label>
									</div>
									<div id="extLabUpload" class="mt-2" style="display:none;">
										<label class="paper-label">Attach Result Files</label>
										<input type="file" class="form-control" name="ext_lab_files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.doc,.docx">
										<div class="form-text">Accepted: PDF, PNG, JPG, DOC, DOCX</div>
									</div>
								</div>
							</div>

							<div class="text-end mt-3">
								<button type="submit" class="btn btn-primary">
									<i class="fas fa-save me-2"></i>Register Patient
								</button>
							</div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-box">
            <div class="spinner-border text-light" role="status"></div>
            <div class="mt-3 fw-semibold">Registering patient... Please wait</div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Prevent autosave from re-saving drafts during submit (race guard)
        let __opdAutosaveDisabled = false;
        let __opdAutosaveTimer = null;
        // Prefill from server-side draft if exists
        <?php
        $draftPayload = null;
        try {
            $selDraft = $db->prepare("SELECT data_json FROM opd_registration_drafts WHERE user_id = :uid LIMIT 1");
            $selDraft->bindValue(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
            $selDraft->execute();
            $rowDraft = $selDraft->fetch(PDO::FETCH_ASSOC);
            if ($rowDraft) { $draftPayload = $rowDraft['data_json']; }
        } catch (Exception $e) { $draftPayload = null; }
        ?>
        const serverDraft = <?php echo $draftPayload ? $draftPayload : 'null'; ?>;
        if (serverDraft) {
            const form = document.querySelector('.needs-validation');
            Object.entries(serverDraft).forEach(([name, value]) => {
                const el = form.querySelector(`[name=\"${name}\"]`);
                if (!el) return;
                if (el.type === 'radio') {
                    const radio = form.querySelector(`input[name=\"${name}\"][value=\"${value}\"]`);
                    if (radio) radio.checked = true;
                } else if (el.tagName === 'SELECT') {
                    el.value = value;
                } else {
                    el.value = value;
                }
            });
        }

        // Input filters
        function on(el, evt, handler) { if (el) el.addEventListener(evt, handler); }
        function allowOnlyLetters(el) {
            ['input','paste'].forEach(ev => on(el, ev, () => {
                const start = el.selectionStart;
                const end = el.selectionEnd;
                el.value = (el.value || '').replace(/[^A-Za-z\s]/g, '');
                try { el.setSelectionRange(start, end); } catch(e) {}
            }));
            on(el, 'keydown', (e) => {
                const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End','Shift','Control'];
                if (allowed.includes(e.key)) return;
                if (!/^[A-Za-z\s]$/.test(e.key)) e.preventDefault();
            });
        }
        function allowOnlyDigits(el) {
            ['input','paste'].forEach(ev => on(el, ev, () => {
                const start = el.selectionStart;
                const end = el.selectionEnd;
                el.value = (el.value || '').replace(/\D+/g, '');
                try { el.setSelectionRange(start, end); } catch(e) {}
            }));
            on(el, 'keydown', (e) => {
                const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End','Shift','Control'];
                if (allowed.includes(e.key)) return;
                if (!/^\d$/.test(e.key)) e.preventDefault();
            });
        }
        function allowDigitsAndDot(el) {
            ['input','paste'].forEach(ev => on(el, ev, () => {
                let v = (el.value || '').replace(/[^\d.]/g, '');
                const firstDot = v.indexOf('.');
                if (firstDot !== -1) {
                    v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
                }
                el.value = v;
            }));
            on(el, 'keydown', (e) => {
                const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End','Shift','Control'];
                if (allowed.includes(e.key)) return;
                if (!/^\d$/.test(e.key) && e.key !== '.') e.preventDefault();
                if (e.key === '.' && (el.value || '').includes('.')) e.preventDefault();
            });
        }
        function allowDigitsAndSlash(el) {
            ['input','paste'].forEach(ev => on(el, ev, () => {
                let v = (el.value || '').replace(/[^\d/]/g, '');
                const firstSlash = v.indexOf('/');
                if (firstSlash !== -1) {
                    v = v.slice(0, firstSlash + 1) + v.slice(firstSlash + 1).replace(/\//g, '');
                }
                el.value = v;
            }));
            on(el, 'keydown', (e) => {
                const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End','Shift','Control'];
                if (allowed.includes(e.key)) return;
                if (!/^\d$/.test(e.key) && e.key !== '/') e.preventDefault();
                if (e.key === '/' && (el.value || '').includes('/')) e.preventDefault();
            });
        }
        function clampDateYearLength(el) {
            on(el, 'input', () => {
                const v = el.value || '';
                const parts = v.split('-');
                if (parts[0] && parts[0].length > 4) {
                    parts[0] = parts[0].slice(0, 4);
                    el.value = parts.join('-');
                }
            });
        }

        // Autosave draft (debounced)
        (function setupAutosave(){
            const form = document.querySelector('.needs-validation');
            if (!form) return;
            const fields = [
                'last_name','first_name','middle_name','suffix','age','gender','birthdate','civil_status',
                'address_house','address_street','address_barangay','address_city','address_postal','address_province',
                'contact_digits','occupation','head_of_family','religion','philhealth','pregnancy_status','doctor_id',
                'symptoms','blood_pressure','pulse_rate','respiratory_rate','temperature','oxygen_saturation',
                'height_cm','weight_kg','noi','poi','doi','toi','lmp','medical_history','medications'
            ];
            function collect() {
                const data = {};
                fields.forEach((name) => {
                    const el = form.querySelector(`[name=\"${name}\"]`);
                    if (!el) return;
                    if (el.type === 'radio') {
                        const checked = form.querySelector(`input[name=\"${name}\"]:checked`);
                        data[name] = checked ? checked.value : '';
                    } else {
                        data[name] = el.value || '';
                    }
                });
                return data;
            }
            function triggerSave() {
                if (__opdAutosaveDisabled) return;
                clearTimeout(__opdAutosaveTimer);
                __opdAutosaveTimer = setTimeout(() => {
                    fetch('save_opd_draft.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ data: collect() })
                    }).then(() => { /* silent */ }).catch(() => {});
                }, 500);
            }
            form.addEventListener('input', triggerSave, true);
            form.addEventListener('change', triggerSave, true);
        })();

        // Form validation
        const form = document.querySelector('.needs-validation');
        form.addEventListener('submit', function(event) {
            const clientAlert = document.getElementById('clientValidationAlert');
            // Extra client-side checks for phone digits
            const phoneDigits = form.querySelector('input[name="contact_digits"]');
            if (phoneDigits && !/^\d{10}$/.test(phoneDigits.value)) {
                phoneDigits.setCustomValidity('Enter exactly 10 digits after +63');
            } else if (phoneDigits) {
                phoneDigits.setCustomValidity('');
            }
            // Postal code
            const postal = form.querySelector('input[name="address_postal"]');
            if (postal && !/^\d{4}$/.test(postal.value)) {
                postal.setCustomValidity('Postal code must be 4 digits');
            } else if (postal) {
                postal.setCustomValidity('');
            }
            const isValid = form.checkValidity();
            if (!isValid) {
                event.preventDefault();
                event.stopPropagation();
                // Highlight invalid fields and notify
                const invalidFields = Array.from(form.querySelectorAll('input, select, textarea'))
                    .filter(el => !el.checkValidity());
                invalidFields.forEach(el => el.classList.add('is-invalid'));
                if (clientAlert) clientAlert.classList.remove('d-none');
                if (invalidFields.length) {
                    const first = invalidFields[0];
                    try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
                    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            form.classList.add('was-validated');
            if (isValid) {
                // Disable autosave and clear any pending save before navigation
                __opdAutosaveDisabled = true;
                try { clearTimeout(__opdAutosaveTimer); } catch (e) {}
                __opdAutosaveTimer = null;
                // Best-effort server discard to avoid race
                try {
                    if (navigator.sendBeacon) {
                        const payload = new Blob([], { type: 'application/x-www-form-urlencoded' });
                        navigator.sendBeacon('discard_opd_draft.php', payload);
                    }
                } catch (e) {}
                if (clientAlert) clientAlert.classList.add('d-none');
                const overlay = document.getElementById('loadingOverlay');
                if (overlay) overlay.style.display = 'flex';
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Registering...';
                }
            }
        });
        // Remove highlight once field is valid
        ['input','change','blur'].forEach(evt => {
            form.addEventListener(evt, function(e) {
                const t = e.target;
                if (!t || !('checkValidity' in t)) return;
                if (t.checkValidity()) {
                    t.classList.remove('is-invalid');
                }
            }, true);
        });

        // Apply input filters per field
        allowOnlyLetters(document.querySelector('input[name="last_name"]'));
        allowOnlyLetters(document.querySelector('input[name="first_name"]'));
        allowOnlyLetters(document.querySelector('input[name="middle_name"]'));
        allowOnlyLetters(document.querySelector('input[name="address_province"]'));
        allowOnlyLetters(document.querySelector('input[name="religion"]'));

        allowOnlyDigits(document.querySelector('input[name="age"]'));
        allowOnlyDigits(document.querySelector('input[name="contact_digits"]'));
        allowOnlyDigits(document.querySelector('input[name="address_postal"]'));
        allowOnlyDigits(document.querySelector('input[name="pulse_rate"]'));
        allowOnlyDigits(document.querySelector('input[name="respiratory_rate"]'));
        allowOnlyDigits(document.querySelector('input[name="oxygen_saturation"]'));

        allowDigitsAndDot(document.querySelector('input[name="temperature"]'));
        allowDigitsAndDot(document.querySelector('input[name="height_cm"]'));
        allowDigitsAndDot(document.querySelector('input[name="weight_kg"]'));

        allowDigitsAndSlash(document.querySelector('input[name="blood_pressure"]'));

        clampDateYearLength(document.querySelector('input[name="birthdate"]'));
        clampDateYearLength(document.querySelector('input[name="doi"]'));
        clampDateYearLength(document.querySelector('input[name="lmp"]'));

        // Blood pressure live validation and highlight
        const bpInput = document.querySelector('input[name="blood_pressure"]');
        function validateBp() {
            if (!bpInput) return;
            const value = bpInput.value || '';
            const pattern = /^\d{2,3}\/\d{2,3}$/;
            const ok = pattern.test(value);
            bpInput.setCustomValidity(ok ? '' : 'Please enter blood pressure in format: 120/80');
            if (ok) {
                bpInput.classList.remove('is-invalid');
            } else {
                bpInput.classList.add('is-invalid');
            }
        }
        if (bpInput) {
            bpInput.addEventListener('input', validateBp);
            bpInput.addEventListener('blur', validateBp);
        }

        // Hide pregnancy field when gender is male
        const genderSelect = document.querySelector('select[name="gender"]');
        const pregnancyField = document.getElementById('pregnancyField');
        const pregnancySelect = document.querySelector('select[name="pregnancy_status"]');
		const lmpField = document.getElementById('lmpField');
		const lmpInput = document.querySelector('input[name="lmp"]');
        function updatePregnancyVisibility() {
            if (!genderSelect || !pregnancyField) return;
            const val = (genderSelect.value || '').toLowerCase();
            if (val === 'male') {
                pregnancyField.style.display = 'none';
                if (pregnancySelect) pregnancySelect.value = 'none';
				if (lmpField) lmpField.style.display = 'none';
				if (lmpInput) lmpInput.value = '';
            } else {
                pregnancyField.style.display = '';
				if (lmpField) lmpField.style.display = '';
            }
        }
        updatePregnancyVisibility();
        if (genderSelect) {
            genderSelect.addEventListener('change', updatePregnancyVisibility);
        }

        // Hide OB-GYN and Pediatrics from doctor list when gender is male
        const doctorSelect = document.querySelector('select[name="doctor_id"]');
        function normalizeDept(name) {
            return (name || '').toString().toLowerCase().replace(/[^a-z]/g, '');
        }
        function updateDoctorVisibilityByGender() {
            if (!doctorSelect) return;
            const genderVal = (genderSelect ? genderSelect.value : (document.querySelector('input[name="gender"]') ? document.querySelector('input[name="gender"]').value : '')).toLowerCase();
            const hideForMale = new Set(['obgyn','obstetricsgynecology','obstetricsandgynecology','pediatrics','pediatric','peds']);
            let mustClearSelection = false;
            Array.from(doctorSelect.options).forEach((opt, idx) => {
                if (!opt.value) return; // skip placeholder
                const deptNorm = normalizeDept(opt.dataset.department || '');
                const shouldHide = (genderVal === 'male') && hideForMale.has(deptNorm);
                opt.hidden = !!shouldHide;
                if (shouldHide && opt.selected) {
                    mustClearSelection = true;
                }
            });
            if (mustClearSelection) {
                doctorSelect.value = '';
            }
        }
        updateDoctorVisibilityByGender();
        if (genderSelect) {
            genderSelect.addEventListener('change', updateDoctorVisibilityByGender);
        }
		
		// Oxygen saturation quick clamp
		const spo2 = document.querySelector('input[name="oxygen_saturation"]');
		if (spo2) {
			spo2.addEventListener('blur', function() {
				let v = parseInt(this.value, 10);
				if (isNaN(v)) return;
				if (v < 0) v = 0;
				if (v > 100) v = 100;
				this.value = v;
			});
		}
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('extLabToggle');
        const upload = document.getElementById('extLabUpload');
        if (toggle) {
            const update = () => { upload.style.display = toggle.checked ? '' : 'none'; };
            toggle.addEventListener('change', update);
            update();
        }
    });
    </script>
</body>
</html> 
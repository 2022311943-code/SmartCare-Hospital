<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Helper for decrypting rows (handles historical double-encryption)
if (!function_exists('dec_row')) {
    function dec_row(array $row, array $fields) {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $once = decrypt_safe((string)$row[$f]);
                $row[$f] = decrypt_safe($once);
            }
        }
        return $row;
    }
}

// Check if user is logged in and is a general doctor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['general_doctor', 'doctor'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Ensure linkage column exists outside of transactions to avoid implicit commits during DDL
try { $db->exec("ALTER TABLE patient_admissions ADD COLUMN source_visit_id INT NULL"); } catch (Exception $e) { /* ignore */ }
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_pa_source_visit ON patient_admissions (source_visit_id)"); } catch (Exception $e) { /* ignore */ }

$error_message = "";
$success_message = "";

// Get current consultation if any
if (isset($_GET['visit_id'])) {
    $current_query = "SELECT ov.*, 
        DATE_FORMAT(ov.arrival_time, '%h:%i %p') as arrival_time_formatted,
        TIMESTAMPDIFF(MINUTE, ov.arrival_time, NOW()) as waiting_time
    FROM opd_visits ov
    WHERE ov.id = :visit_id 
    AND ov.doctor_id = :doctor_id 
    LIMIT 1";

    $current_stmt = $db->prepare($current_query);
    $current_stmt->bindParam(":visit_id", $_GET['visit_id']);
    $current_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
} else {
    $current_query = "SELECT ov.*, 
        DATE_FORMAT(ov.arrival_time, '%h:%i %p') as arrival_time_formatted,
        TIMESTAMPDIFF(MINUTE, ov.arrival_time, NOW()) as waiting_time
    FROM opd_visits ov
    WHERE ov.doctor_id = :doctor_id 
    AND ov.visit_status = 'in_progress'
    AND DATE(ov.arrival_time) = CURDATE()
    LIMIT 1";

    $current_stmt = $db->prepare($current_query);
    $current_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
}
$current_stmt->execute();
$current_patient = $current_stmt->fetch(PDO::FETCH_ASSOC);
// Decrypt sensitive visit fields for display
if ($current_patient) {
    foreach (['patient_name','contact_number','address','symptoms','diagnosis','treatment_plan','prescription','notes','medical_history','medications'] as $f) {
        if (array_key_exists($f, $current_patient)) {
            $current_patient[$f] = decrypt_safe((string)$current_patient[$f]);
        }
    }
}

// Get lab results for this patient
if ($current_patient) {
    // First get the patient_record_id
    $patient_query = "SELECT patient_record_id FROM opd_visits WHERE id = :visit_id";
    $patient_stmt = $db->prepare($patient_query);
    $patient_stmt->bindParam(":visit_id", $current_patient['id']);
    $patient_stmt->execute();
    $patient_data = $patient_stmt->fetch(PDO::FETCH_ASSOC);

	// Prefill: get most recent completed visit's diagnosis and treatment plan for this patient (if any)
	$prefill_diagnosis = '';
	$prefill_treatment_plan = '';
	try {
		if (!empty($patient_data['patient_record_id'])) {
			$prefill_q = "SELECT diagnosis, treatment_plan
				FROM opd_visits
				WHERE patient_record_id = :prid
				  AND id <> :vid
				  AND visit_status = 'completed'
				ORDER BY created_at DESC
				LIMIT 1";
			$prefill_stmt = $db->prepare($prefill_q);
			$prefill_stmt->bindParam(':prid', $patient_data['patient_record_id']);
			$prefill_stmt->bindParam(':vid', $current_patient['id']);
			$prefill_stmt->execute();
			$prev = $prefill_stmt->fetch(PDO::FETCH_ASSOC);
			if ($prev) {
                $prev = dec_row($prev, ['diagnosis','treatment_plan']);
				if (isset($prev['diagnosis'])) { $prefill_diagnosis = (string)$prev['diagnosis']; }
				if (isset($prev['treatment_plan'])) { $prefill_treatment_plan = (string)$prev['treatment_plan']; }
			}
		}
	} catch (Exception $e) {
		// ignore prefill errors
	}

    // Get all lab results for this patient across all visits (include external uploads)
    $lab_query = "SELECT lr.*, lt.test_name, lt.test_type,
                         DATE_FORMAT(lr.completed_at, '%M %d, %Y %h:%i %p') as completed_date,
                         DATE_FORMAT(lr.requested_at, '%M %d, %Y %h:%i %p') as requested_date,
                         ov.visit_type
                  FROM lab_requests lr
                  LEFT JOIN lab_tests lt ON lr.test_id = lt.id
                  JOIN opd_visits ov ON lr.visit_id = ov.id
                  WHERE ov.patient_record_id = (
                      SELECT patient_record_id 
                      FROM opd_visits 
                      WHERE id = :visit_id
                  )
                  AND lr.status = 'completed'
                  ORDER BY lr.completed_at DESC";
    $lab_stmt = $db->prepare($lab_query);
    $lab_stmt->bindParam(":visit_id", $current_patient['id']);
    $lab_stmt->execute();
    $lab_results = $lab_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lab_results as &$lr) {
        if (isset($lr['result'])) { $lr['result'] = decrypt_safe((string)$lr['result']); }
        if (isset($lr['result_file_path'])) { $lr['result_file_path'] = decrypt_safe((string)$lr['result_file_path']); }
        if (empty($lr['test_name'])) { $lr['test_name'] = '[External Upload]'; }
        if (empty($lr['test_type'])) { $lr['test_type'] = 'laboratory'; }
    }
    unset($lr);

    // Debug information
    error_log("Patient Record ID: " . $patient_data['patient_record_id']);
    error_log("Number of lab results found: " . count($lab_results));
}

// Get available medicines (exclude expired)
$medicines_query = "SELECT id, name, generic_name, unit, quantity 
                    FROM medicines 
                    WHERE quantity > 0 
                      AND (expiration_date IS NULL OR expiration_date > CURDATE())
                    ORDER BY name ASC";
$medicines_stmt = $db->prepare($medicines_query);
$medicines_stmt->execute();
$medicines = $medicines_stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine if patient portal account exists (best-effort)
$portal_status = 'unknown';
try {
    if (!empty($patient_data['patient_record_id'])) {
        $pp = $db->prepare("SELECT COUNT(*) AS c FROM patient_portal_accounts WHERE patient_record_id = :prid");
        $pp->bindParam(':prid', $patient_data['patient_record_id']);
        $pp->execute();
        $row = $pp->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['c'] > 0) {
            $portal_status = 'active';
        } else {
            $portal_status = 'none';
        }
    }
} catch (Exception $e) { /* ignore */ }

// Handle form submission for consultation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    try {
        $db->beginTransaction();
        
        if ($_POST['action'] === 'complete_consultation') {
            // Validate required fields
            if (empty($_POST['diagnosis']) || empty($_POST['treatment_plan'])) {
                throw new Exception("Diagnosis and Treatment Plan are required fields");
            }

            // Prepare follow-up date (can be NULL)
            $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
            
            // Create medicine order if medicines were prescribed
            if (isset($_POST['medicines']) && !empty($_POST['medicines'])) {
                // First check if patient exists in patients table
                $check_patient_query = "SELECT id FROM patients WHERE doctor_id = :doctor_id AND name = :name LIMIT 1";
                $check_patient_stmt = $db->prepare($check_patient_query);
                $check_patient_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
                $check_patient_stmt->bindParam(":name", $current_patient['patient_name']);
                $check_patient_stmt->execute();
                $existing_patient = $check_patient_stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_patient) {
                    $patient_id = $existing_patient['id'];
                } else {
                    // Create new patient record
                    $insert_patient_query = "INSERT INTO patients (name, age, gender, phone, address, doctor_id, added_by) 
                                          VALUES (:name, :age, :gender, :phone, :address, :doctor_id, :added_by)";
                    $insert_patient_stmt = $db->prepare($insert_patient_query);
                    $insert_patient_stmt->bindParam(":name", $current_patient['patient_name']);
                    $insert_patient_stmt->bindParam(":age", $current_patient['age']);
                    $insert_patient_stmt->bindParam(":gender", $current_patient['gender']);
                    $insert_patient_stmt->bindParam(":phone", $current_patient['contact_number']);
                    $insert_patient_stmt->bindParam(":address", $current_patient['address']);
                    $insert_patient_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
                    $insert_patient_stmt->bindParam(":added_by", $_SESSION['user_id']);
                    $insert_patient_stmt->execute();
                    $patient_id = $db->lastInsertId();
                }

                // Get the next order number
                $order_number_query = "SELECT COALESCE(MAX(order_number), 0) + 1 as next_order_number FROM medicine_orders";
                $order_number_stmt = $db->prepare($order_number_query);
                $order_number_stmt->execute();
                $order_number = $order_number_stmt->fetch(PDO::FETCH_ASSOC)['next_order_number'];

                // Create the order with the correct patient_id
                $order_query = "INSERT INTO medicine_orders (order_number, doctor_id, patient_id, notes) 
                               VALUES (:order_number, :doctor_id, :patient_id, :notes)";
                $order_stmt = $db->prepare($order_query);
                $order_stmt->bindParam(":order_number", $order_number);
                $order_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
                $order_stmt->bindParam(":patient_id", $patient_id);
                $order_stmt->bindParam(":notes", $_POST['prescription']);
                $order_stmt->execute();

                $order_id = $db->lastInsertId();

                // Add order items
                foreach ($_POST['medicines'] as $medicine) {
                    // Validate that medicine is not expired at order time
                    $expiry_check_query = "SELECT expiration_date FROM medicines WHERE id = :id";
                    $expiry_check_stmt = $db->prepare($expiry_check_query);
                    $expiry_check_stmt->bindParam(":id", $medicine['id']);
                    $expiry_check_stmt->execute();
                    $expiry = $expiry_check_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($expiry && !empty($expiry['expiration_date']) && strtotime($expiry['expiration_date']) <= strtotime(date('Y-m-d'))) {
                        throw new Exception("Attempted to prescribe an expired medicine. Please refresh your list.");
                    }

                    $item_query = "INSERT INTO medicine_order_items (order_id, medicine_id, quantity, instructions) 
                                  VALUES (:order_id, :medicine_id, :quantity, :instructions)";
                    $item_stmt = $db->prepare($item_query);
                    $item_stmt->bindParam(":order_id", $order_id);
                    $item_stmt->bindParam(":medicine_id", $medicine['id']);
                    $item_stmt->bindParam(":quantity", $medicine['quantity']);
                    $item_stmt->bindParam(":instructions", $medicine['instructions']);
                    $item_stmt->execute();
                }

                // Create notification for pharmacist
                $notification_query = "INSERT INTO medicine_order_notifications (order_id, notification_type) 
                                     VALUES (:order_id, 'new_order')";
                $notification_stmt = $db->prepare($notification_query);
                $notification_stmt->bindParam(":order_id", $order_id);
                $notification_stmt->execute();

                // Format prescription text to include medicine details
                $prescription_text = "PRESCRIBED MEDICINES:\n\n";
                foreach ($_POST['medicines'] as $medicine) {
                    // Get medicine details
                    $med_query = "SELECT name, unit FROM medicines WHERE id = :id";
                    $med_stmt = $db->prepare($med_query);
                    $med_stmt->bindParam(":id", $medicine['id']);
                    $med_stmt->execute();
                    $med_details = $med_stmt->fetch(PDO::FETCH_ASSOC);

                    $prescription_text .= "- " . $med_details['name'] . "\n";
                    $prescription_text .= "  Quantity: " . $medicine['quantity'] . " " . $med_details['unit'] . "\n";
                    if (!empty($medicine['instructions'])) {
                        $prescription_text .= "  Instructions: " . $medicine['instructions'] . "\n";
                    }
                    $prescription_text .= "\n";
                }

                if (!empty($_POST['prescription'])) {
                    $prescription_text .= "ADDITIONAL NOTES:\n" . $_POST['prescription'];
                }
            } else {
                $prescription_text = $_POST['prescription'];
            }

			// Append any custom (non-stock) medicines to the textual prescription (no pharmacy order)
			if (isset($_POST['custom_medicines']) && is_array($_POST['custom_medicines']) && !empty($_POST['custom_medicines'])) {
				$hasAny = false;
				$custom_text = "NON-STOCK/EXTERNAL MEDICINES:\n\n";
				foreach ($_POST['custom_medicines'] as $cm) {
					$name = isset($cm['name']) ? trim((string)$cm['name']) : '';
					if ($name === '') { continue; }
					$qty = isset($cm['quantity']) ? trim((string)$cm['quantity']) : '';
					$unit = isset($cm['unit']) ? trim((string)$cm['unit']) : '';
					$instr = isset($cm['instructions']) ? trim((string)$cm['instructions']) : '';
					$custom_text .= "- " . $name . "\n";
					if ($qty !== '' || $unit !== '') {
						$custom_text .= "  Quantity: " . $qty . ($unit !== '' ? " " . $unit : "") . "\n";
					}
					if ($instr !== '') {
						$custom_text .= "  Instructions: " . $instr . "\n";
					}
					$custom_text .= "\n";
					$hasAny = true;
				}
				if ($hasAny) {
					if (!empty($prescription_text)) { $prescription_text .= "\n\n"; }
					$prescription_text .= $custom_text;
				}
            }
            
            // Update opd_visits
            $update_query = "UPDATE opd_visits SET 
                visit_status = 'completed',
                consultation_end = NOW(),
                diagnosis = :diagnosis,
                treatment_plan = :treatment_plan,
                prescription = :prescription,
                follow_up_date = :follow_up_date,
                notes = :notes
            WHERE id = :visit_id AND doctor_id = :doctor_id";
            
            $update_stmt = $db->prepare($update_query);

            // Encrypt sensitive text fields
            $enc_diag = encrypt_strict((string)$_POST['diagnosis']);
            $enc_treat = encrypt_strict((string)$_POST['treatment_plan']);
            $enc_presc = encrypt_strict((string)$prescription_text);
            $enc_notes = encrypt_strict((string)$_POST['notes']);

            // Bind parameters with proper type handling
            $update_stmt->bindParam(":diagnosis", $enc_diag, PDO::PARAM_STR);
            $update_stmt->bindParam(":treatment_plan", $enc_treat, PDO::PARAM_STR);
            $update_stmt->bindParam(":prescription", $enc_presc, PDO::PARAM_STR);
            $update_stmt->bindParam(":follow_up_date", $follow_up_date, $follow_up_date === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $update_stmt->bindParam(":notes", $enc_notes, PDO::PARAM_STR);
            $update_stmt->bindParam(":visit_id", $_POST['visit_id'], PDO::PARAM_INT);
            $update_stmt->bindParam(":doctor_id", $_SESSION['user_id'], PDO::PARAM_INT);
            
                            if ($update_stmt->execute()) {
                    // Check if admission was recommended
                    if (isset($_POST['recommend_admission']) && $_POST['recommend_admission'] === '1') {
                        // First check if patient exists in patients table
                        $check_patient_query = "SELECT id FROM patients WHERE name = :name LIMIT 1";
                        $check_patient_stmt = $db->prepare($check_patient_query);
                        $check_patient_stmt->bindParam(":name", $current_patient['patient_name']);
                        $check_patient_stmt->execute();
                        $existing_patient = $check_patient_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($existing_patient) {
                            $patient_id = $existing_patient['id'];
                        } else {
                            // Create new patient record
                            $insert_patient = "INSERT INTO patients (
                                name, age, gender, phone, address, doctor_id, added_by
                            ) VALUES (
                                :name, :age, :gender, :phone, :address, :doctor_id, :added_by
                            )";
                            
                            $patient_stmt = $db->prepare($insert_patient);
                            $patient_stmt->bindParam(":name", $current_patient['patient_name']);
                            $patient_stmt->bindParam(":age", $current_patient['age']);
                            $patient_stmt->bindParam(":gender", $current_patient['gender']);
                            $patient_stmt->bindParam(":phone", $current_patient['contact_number']);
                            $patient_stmt->bindParam(":address", $current_patient['address']);
                            $patient_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
                            $patient_stmt->bindParam(":added_by", $_SESSION['user_id']);
                            $patient_stmt->execute();
                            
                            $patient_id = $db->lastInsertId();
                        }

                        // Create admission record
                        $admission_query = "INSERT INTO patient_admissions (
                            patient_id,
                            admission_source,
                            admission_notes,
                            admission_status,
                            admitted_by
                        ) VALUES (
                            :patient_id,
                            'opd',
                            :admission_notes,
                            'pending',
                            :admitted_by
                        )";

                        $combined_admission_notes = '';
                        if (!empty($_POST['admission_reason'])) {
                            $combined_admission_notes .= "Reason: " . $_POST['admission_reason'] . "\n";
                        }
                        if (!empty($_POST['admission_notes'])) {
                            $combined_admission_notes .= (empty($combined_admission_notes) ? '' : "\n") . "Initial Notes: " . $_POST['admission_notes'];
                        }
                        if ($combined_admission_notes === '') {
                            $combined_admission_notes = null;
                        }

                        $admission_stmt = $db->prepare($admission_query);
                        $admission_stmt->bindParam(":patient_id", $patient_id);
                        $admission_stmt->bindParam(":admission_notes", $combined_admission_notes);
                        $admission_stmt->bindParam(":admitted_by", $_SESSION['user_id']);
                        $admission_stmt->execute();
                        // Link admission to this OPD visit id for reliable downstream filtering
                        try {
                            $newAdmissionId = $db->lastInsertId();
                            $linkStmt = $db->prepare("UPDATE patient_admissions SET source_visit_id = :vid WHERE id = :aid");
                            $linkStmt->execute([':vid' => $_POST['visit_id'], ':aid' => $newAdmissionId]);
                        } catch (Exception $e) { /* ignore */ }
                    }

                    // Get patient_record_id
                    $patient_query = "SELECT patient_record_id, patient_name, age, gender, contact_number, address FROM opd_visits WHERE id = :visit_id";
                    $patient_stmt = $db->prepare($patient_query);
                    $patient_stmt->bindParam(":visit_id", $_POST['visit_id']);
                    $patient_stmt->execute();
                    $patient_data = $patient_stmt->fetch(PDO::FETCH_ASSOC);

                // If patient_record_id is null, create a new patient record
                if (!$patient_data['patient_record_id']) {
                    // Insert into patient_records
                    $insert_record_query = "INSERT INTO patient_records (
                        patient_name, age, gender, contact_number, address
                    ) VALUES (
                        :patient_name, :age, :gender, :contact_number, :address
                    )";
                    
                    $insert_record_stmt = $db->prepare($insert_record_query);
                    // Values from opd_visits are already encrypted for sensitive fields
                    $insert_record_stmt->bindParam(":patient_name", $patient_data['patient_name']);
                    $insert_record_stmt->bindParam(":age", $patient_data['age']);
                    $insert_record_stmt->bindParam(":gender", $patient_data['gender']);
                    $insert_record_stmt->bindParam(":contact_number", $patient_data['contact_number']);
                    $insert_record_stmt->bindParam(":address", $patient_data['address']);
                    $insert_record_stmt->execute();
                    
                    $patient_record_id = $db->lastInsertId();
                    
                    // Update the opd_visit with the new patient_record_id
                    $update_visit_query = "UPDATE opd_visits SET patient_record_id = :patient_record_id WHERE id = :visit_id";
                    $update_visit_stmt = $db->prepare($update_visit_query);
                    $update_visit_stmt->bindParam(":patient_record_id", $patient_record_id);
                    $update_visit_stmt->bindParam(":visit_id", $_POST['visit_id']);
                    $update_visit_stmt->execute();
                } else {
                    $patient_record_id = $patient_data['patient_record_id'];
                }

                // Check if this is a consultation completion or regular progress note
                if (isset($_POST['action']) && $_POST['action'] === 'complete_consultation') {
                    // Create consultation completion summary with special formatting
                    $note_text = "CONSULTATION SUMMARY\n\n";
                
                    $note_text .= "📋 DIAGNOSIS:\n";
                    $note_text .= $_POST['diagnosis'] . "\n\n\n";
                    
                    $note_text .= "💊 TREATMENT PLAN:\n";
                    $note_text .= $_POST['treatment_plan'] . "\n\n\n";
                    
                    if (!empty($_POST['prescription'])) {
                        $note_text .= "🏥 PRESCRIPTION:\n";
                        $note_text .= $_POST['prescription'] . "\n\n\n";
                    }
                    
                    if (!empty($_POST['notes'])) {
                        $note_text .= "📝 ADDITIONAL NOTES:\n";
                        $note_text .= $_POST['notes'] . "\n\n\n";
                    }
                    
                    if (!empty($_POST['follow_up_date'])) {
                        $note_text .= "📅 FOLLOW-UP DATE:\n";
                        $note_text .= date('F j, Y', strtotime($_POST['follow_up_date'])) . "\n\n\n";
                    }
                    
                    $note_text .= "----------------------------------------\n";
                    $note_text .= "Consultation completed by Dr. " . $_SESSION['user_name'] . "\n";
                    $note_text .= date('F j, Y h:i A');
                } else {
                    // Regular progress note - simple format
                    $note_text = $_POST['progress_note'];
                }

                // For completed OPD consultation without admission recommendation, create a finance pending payment record
                if (!isset($_POST['recommend_admission']) || $_POST['recommend_admission'] !== '1') {
                    // Ensure visit has a patient_record_id
                    $prid_for_payment = $patient_record_id;
                    // Use posted consultation_fee if provided
                    $amount_due = 0;
                    if (isset($_POST['consultation_fee'])) {
                        $amount_due = floatval($_POST['consultation_fee']);
                        if (!is_finite($amount_due) || $amount_due < 0) { $amount_due = 0; }
                    }
                    // Create or update an OPD payment row as pending
                    $create_payment = $db->prepare("INSERT INTO opd_payments (visit_id, patient_record_id, amount_due, payment_status, created_at) 
                                                    VALUES (:vid, :prid, :amt, 'pending', NOW())
                                                    ON DUPLICATE KEY UPDATE payment_status = 'pending', amount_due = :amt");
                    $create_payment->bindParam(':vid', $_POST['visit_id']);
                    $create_payment->bindParam(':prid', $prid_for_payment);
                    $create_payment->bindParam(':amt', $amount_due);
                    try { $create_payment->execute(); } catch (Exception $ignore) { /* table may not exist yet */ }
                }
                else {
                    // If recommending admission, ensure any existing OPD pending record for this visit is removed
                    try {
                        $del_opd = $db->prepare("DELETE FROM opd_payments WHERE visit_id = :vid");
                        $del_opd->bindParam(':vid', $_POST['visit_id']);
                        $del_opd->execute();
                    } catch (Exception $ignore) { /* table may not exist yet */ }
                }

                // Insert into patient_progress_notes
                $progress_query = "INSERT INTO patient_progress_notes (
                    patient_record_id, 
                    visit_id, 
                    note_text, 
                    doctor_id, 
                    created_at
                ) VALUES (
                    :patient_record_id,
                    :visit_id,
                    :note_text,
                    :doctor_id,
                    NOW()
                )";

                $progress_stmt = $db->prepare($progress_query);
                $progress_stmt->bindParam(":patient_record_id", $patient_record_id);
                $progress_stmt->bindParam(":visit_id", $_POST['visit_id']);
                $progress_stmt->bindParam(":note_text", $note_text);
                $progress_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
                $progress_stmt->execute();

                $db->commit();
                $_SESSION['success_message'] = "Consultation completed successfully!";
                header("Location: opd_queue.php");
                exit();
            } else {
                throw new Exception("Failed to update consultation");
            }
        }
    } catch (Exception $e) {
        if ($db && method_exists($db, 'inTransaction') && $db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Consultation - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            padding: 1rem;
        }
        .navbar-brand {
            color: white !important;
            font-weight: 600;
        }
        .nav-link {
            color: rgba(255,255,255,0.9) !important;
        }
        .nav-link:hover {
            color: white !important;
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
        .vital-signs {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .vital-sign-item {
            padding: 10px;
            border-radius: 8px;
            background: white;
            margin-bottom: 10px;
            border-left: 4px solid #dc3545;
        }
        
        /* Add styles for error message */
        .alert {
            margin-bottom: 20px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-hospital me-2"></i>
                SmartCare
            </a>
            <div class="d-flex align-items-center">
                <a href="opd_queue.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-list me-2"></i>OPD Queue
                </a>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
                <a href="logout.php" class="btn btn-light btn-sm">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($current_patient): ?>
            <div class="row">
                <div class="col-md-4">
                    <!-- Patient Info Card -->
                    <div class="card mb-4">
                        <div class="card-header py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-user me-2"></i>
                                Patient Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="text-muted mb-1">Patient Name</label>
                                <div class="h5"><?php echo htmlspecialchars($current_patient['patient_name']); ?></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col">
                                    <label class="text-muted mb-1">Age</label>
                                    <div class="h6"><?php echo htmlspecialchars($current_patient['age']); ?> years</div>
                                </div>
                                <div class="col">
                                    <label class="text-muted mb-1">Gender</label>
                                    <div class="h6"><?php echo ucfirst(htmlspecialchars($current_patient['gender'])); ?></div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted mb-1">Contact</label>
                                <div class="h6"><?php echo htmlspecialchars($current_patient['contact_number']); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted mb-1">Arrival Time</label>
                                <div class="h6">
                                    <?php echo $current_patient['arrival_time_formatted']; ?>
                                    <span class="text-muted">
                                        (<?php echo $current_patient['waiting_time']; ?> mins ago)
                                    </span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted mb-1">Visit Type</label>
                                <div class="h6">
                                    <span class="badge bg-<?php echo $current_patient['visit_type'] === 'follow_up' ? 'info' : 'primary'; ?>">
                                        <?php echo $current_patient['visit_type'] === 'follow_up' ? 'Follow-up Visit' : 'New Visit'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vital Signs Card -->
                    <div class="card mb-4">
                        <div class="card-header py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-heartbeat me-2"></i>
                                Vital Signs
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="vital-sign-item">
                                <label class="text-muted">Temperature</label>
                                <div class="h5 mb-0"><?php echo htmlspecialchars($current_patient['temperature']); ?> °C</div>
                            </div>
                            <div class="vital-sign-item">
                                <label class="text-muted">Blood Pressure</label>
                                <div class="h5 mb-0"><?php echo htmlspecialchars($current_patient['blood_pressure']); ?> mmHg</div>
                            </div>
                            <div class="vital-sign-item">
                                <label class="text-muted">Pulse Rate</label>
                                <div class="h5 mb-0"><?php echo htmlspecialchars($current_patient['pulse_rate']); ?> bpm</div>
                            </div>
                            <div class="vital-sign-item">
                                <label class="text-muted">Respiratory Rate</label>
                                <div class="h5 mb-0"><?php echo htmlspecialchars($current_patient['respiratory_rate']); ?> bpm</div>
                            </div>
                            <?php if (!is_null($current_patient['oxygen_saturation'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">Oxygen Saturation</label>
                                <div class="h5 mb-0"><?php echo htmlspecialchars($current_patient['oxygen_saturation']); ?> %</div>
                            </div>
                            <?php endif; ?>
                            <?php if (!is_null($current_patient['height_cm'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">Height</label>
                                <div class="h5 mb-0"><?php echo htmlspecialchars($current_patient['height_cm']); ?> cm</div>
                            </div>
                            <?php endif; ?>
                            <?php if (!is_null($current_patient['weight_kg'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">Weight</label>
                                <div class="h5 mb-0"><?php echo htmlspecialchars($current_patient['weight_kg']); ?> kg</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Incident/Onset Details -->
                    <?php if (!empty($current_patient['noi']) || !empty($current_patient['poi']) || !empty($current_patient['doi']) || !empty($current_patient['toi'])): ?>
                    <div class="card mb-4">
                        <div class="card-header py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Incident/Onset Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($current_patient['noi'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">NOI</label>
                                <div class="h6 mb-0"><?php echo htmlspecialchars($current_patient['noi']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($current_patient['poi'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">POI</label>
                                <div class="h6 mb-0"><?php echo htmlspecialchars($current_patient['poi']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($current_patient['doi'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">DOI</label>
                                <div class="h6 mb-0"><?php echo date('F j, Y', strtotime($current_patient['doi'])); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($current_patient['toi'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">TOI</label>
                                <div class="h6 mb-0"><?php echo htmlspecialchars($current_patient['toi']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- LMP / Medical History / Medications -->
                    <?php if (!empty($current_patient['lmp']) || !empty($current_patient['medical_history']) || !empty($current_patient['medications'])): ?>
                    <div class="card mb-4">
                        <div class="card-header py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-notes-medical me-2"></i>
                                Additional Health Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($current_patient['lmp'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">Last Menstrual Period</label>
                                <div class="h6 mb-0"><?php echo date('F j, Y', strtotime($current_patient['lmp'])); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($current_patient['medical_history'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">Medical History</label>
                                <div class="mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars($current_patient['medical_history']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($current_patient['medications'])): ?>
                            <div class="vital-sign-item">
                                <label class="text-muted">Medications Taken</label>
                                <div class="mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars($current_patient['medications']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Lab Results Card -->
                    <div class="card mb-4">
                        <div class="card-header py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-flask me-2"></i>
                                Laboratory Results
                                <?php if (isset($lab_results)): ?>
                                    <span class="badge bg-info ms-2"><?php echo count($lab_results); ?> results</span>
                                <?php endif; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!isset($lab_results) || empty($lab_results)): ?>
                                <p class="text-muted text-center mb-0">
                                    No lab results available yet.
                                    <?php if (isset($current_patient)): ?>
                                        <!-- Debug info -->
                                        <small class="d-block mt-2 text-danger">
                                            Visit ID: <?php echo $current_patient['id']; ?>
                                        </small>
                                    <?php endif; ?>
                                </p>
                            <?php else: ?>
                                <?php foreach ($lab_results as $result): ?>
                                    <div class="vital-sign-item">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0">
                                                <?php if ($result['test_type'] === 'laboratory'): ?>
                                                    <i class="fas fa-flask text-primary me-2"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-x-ray text-info me-2"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($result['test_name']); ?>
                                                <small class="text-muted ms-2">
                                                    (<?php echo $result['visit_type'] === 'follow_up' ? 'Follow-up Visit' : 'New Visit'; ?>)
                                                </small>
                                            </h6>
                                            <span class="badge bg-success">Completed</span>
                                        </div>
                                        <div class="text-muted small mb-2">
                                            <div><strong>Requested:</strong> <?php echo $result['requested_date']; ?></div>
                                            <div><strong>Completed:</strong> <?php echo $result['completed_date']; ?></div>
                                        </div>
                                        <div class="mb-2">
                                            <?php echo nl2br(htmlspecialchars($result['result'])); ?>
                                        </div>
                                        <?php if ($result['result_file_path']): ?>
                                            <div>
                                                <a href="<?php echo htmlspecialchars($result['result_file_path']); ?>" 
                                                   class="btn btn-sm btn-primary" 
                                                   target="_blank">
                                                    <i class="fas fa-file-download me-1"></i>
                                                    View Result File
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <a href="request_laboratory.php?visit_id=<?php echo $current_patient['id']; ?>" 
                                   class="btn btn-danger btn-sm">
                                    <i class="fas fa-plus me-1"></i>
                                    Request New Test
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <!-- Consultation Form -->
                    <div class="card">
                        <div class="card-header py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-stethoscope me-2"></i>
                                Consultation Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="consultationForm">
                                <input type="hidden" name="action" value="complete_consultation">
                                <input type="hidden" name="visit_id" value="<?php echo $current_patient['id']; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label">Chief Complaint/Symptoms</label>
                                    <div class="vital-sign-item">
                                        <?php echo nl2br(htmlspecialchars($current_patient['symptoms'])); ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Diagnosis <span class="text-danger">*</span></label>
									<textarea class="form-control" name="diagnosis" rows="3" required><?php echo htmlspecialchars($prefill_diagnosis ?? ''); ?></textarea>
                                    <div class="form-text">Required field</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Treatment Plan <span class="text-danger">*</span></label>
									<textarea class="form-control" name="treatment_plan" rows="3" required><?php echo htmlspecialchars($prefill_treatment_plan ?? ''); ?></textarea>
                                    <div class="form-text">Required field</div>
                                </div>

                                <div class="form-group mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label mb-0">Recommend Admission?</label>
                                        <button type="button" class="btn btn-warning btn-sm" id="recommendAdmission">
                                            <i class="fas fa-procedures me-1"></i>Recommend Admission
                                        </button>
                                    </div>
                                    <div id="admissionRecommendation" style="display: none;">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Reason for Admission</label>
                                                    <textarea class="form-control" name="admission_reason" rows="2"></textarea>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Initial Orders/Notes</label>
                                                    <textarea class="form-control" name="admission_notes" rows="2"></textarea>
                                                </div>
                                                <input type="hidden" name="recommend_admission" value="0" id="recommendAdmissionInput">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Prescription</label>
                                    <div class="card mb-2">
                                        <div class="card-body p-3">
                                            <div id="selectedMedicines" class="mb-3">
                                                <!-- Selected medicines will appear here -->
                                            </div>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="medicineSearch" placeholder="Search medicines...">
                                                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#addMedicineModal">
                                                    <i class="fas fa-plus"></i> Add Medicine
                                                </button>
                                            </div>
											<hr class="my-3">
											<div class="d-flex justify-content-between align-items-center mb-2">
												<label class="form-label mb-0">Non-stock / External Medicines</label>
												<button type="button" class="btn btn-sm btn-outline-danger" id="addCustomMedicineBtn">
													<i class="fas fa-plus"></i> Add Non-stock
												</button>
											</div>
											<div class="text-muted small mb-2">
												These entries are not in pharmacy inventory and will be added to the textual prescription only. No pharmacy order will be created for them.
											</div>
											<div id="customMedicines" class="mb-2"></div>
                                        </div>
                                    </div>
                                    <textarea class="form-control" name="prescription" rows="3" placeholder="Additional prescription notes..."></textarea>
                                </div>

                                <!-- Add Medicine Modal -->
                                <div class="modal fade" id="addMedicineModal" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Add Medicine to Prescription</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                        <input type="text" class="form-control" id="modalMedicineSearch" placeholder="Search medicines...">
                                                    </div>
                                                </div>
                                                <div class="table-responsive">
                                                    <table class="table table-hover" id="medicinesTable">
                                                        <thead>
                                                            <tr>
                                                                <th>Name</th>
                                                                <th>Generic Name</th>
                                                                <th>Unit</th>
                                                                <th>Available</th>
                                                                <th>Expiry</th>
                                                                <th>Action</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="medicinesBody">
                                                            <?php foreach($medicines as $medicine): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($medicine['name']); ?></td>
                                                                <td><?php echo htmlspecialchars($medicine['generic_name']); ?></td>
                                                                <td><?php echo htmlspecialchars($medicine['unit']); ?></td>
                                                                <td><?php echo htmlspecialchars($medicine['quantity']); ?></td>
                                                                <td class="text-muted">Safe</td>
                                                                <td>
                                                                    <button type="button" class="btn btn-sm btn-danger select-medicine" 
                                                                            data-id="<?php echo $medicine['id']; ?>"
                                                                            data-name="<?php echo htmlspecialchars($medicine['name']); ?>"
                                                                            data-unit="<?php echo htmlspecialchars($medicine['unit']); ?>">
                                                                        <i class="fas fa-plus"></i> Select
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Follow-up Date (if needed)</label>
                                    <input type="date" class="form-control" name="follow_up_date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Additional Notes</label>
                                    <textarea class="form-control" name="notes" rows="2"></textarea>
                                </div>

                                <div class="d-flex gap-2">
                                    <a href="add_progress_note.php?visit_id=<?php echo $current_patient['id']; ?>" class="btn btn-warning">
                                        <i class="fas fa-notes-medical me-2"></i>Add Progress Notes
                                    </a>
                                    <button type="submit" class="btn btn-danger flex-grow-1" id="submitBtn">
                                        <i class="fas fa-check-circle me-2"></i>Complete Consultation
                                    </button>
                                </div>
								<input type="hidden" name="consultation_fee" id="consultationFeeHidden" value="">
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-user-clock text-muted mb-3" style="font-size: 3rem;"></i>
                    <h4 class="text-muted">No Active Consultation</h4>
                    <p class="mb-4">There are no patients currently in consultation.</p>
                    <a href="opd_queue.php" class="btn btn-danger">
                        <i class="fas fa-list me-2"></i>View OPD Queue
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- html2canvas for image export -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
		// Draft autosave/restore
		const visitIdInput = document.querySelector('input[name="visit_id"]');
		const visitId = visitIdInput ? String(visitIdInput.value) : '';
		const doctorId = String(<?php echo isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0; ?>);
		const draftKey = `consultation_draft_${visitId}_doctor_${doctorId}`;

        // Initialize selected medicines array
        let selectedMedicines = [];
		let customMedicineCounter = 0;

        // Medicine search functionality
        const medicineSearch = document.getElementById('medicineSearch');
        const medicinesTable = document.getElementById('medicinesTable');
        let suggestBox;
        function ensureSuggestBox() {
            if (!suggestBox) {
                // Anchor to the nearest input-group container
                const container = medicineSearch.closest('.input-group') || medicineSearch.parentElement;
                if (container && getComputedStyle(container).position === 'static') {
                    container.style.position = 'relative';
                }
                suggestBox = document.createElement('div');
                suggestBox.className = 'list-group shadow';
                suggestBox.style.position = 'absolute';
                suggestBox.style.left = '0';
                suggestBox.style.right = '0';
                suggestBox.style.top = 'calc(100% + 4px)';
                suggestBox.style.zIndex = 1055;
                suggestBox.style.maxHeight = '260px';
                suggestBox.style.overflowY = 'auto';
                (container || medicineSearch.parentElement).appendChild(suggestBox);
            }
            return suggestBox;
        }
        
        // Live search suggestions from backend
        let searchTimer;
        medicineSearch.addEventListener('input', function() {
            clearTimeout(searchTimer);
            const q = this.value.trim();
            const box = ensureSuggestBox();
            if (q.length === 0) { box.innerHTML = ''; box.style.display = 'none'; return; }
            searchTimer = setTimeout(() => {
                fetch('search_medicines_doctor.php?q=' + encodeURIComponent(q))
                  .then(r => r.json())
                  .then(data => {
                    if (!data.success) throw new Error('Search failed');
                    box.innerHTML = '';
                    if (!data.items || data.items.length === 0) { box.style.display = 'none'; return; }
                    data.items.forEach(item => {
                        const a = document.createElement('button');
                        a.type = 'button';
                        a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                        a.innerHTML = `<span>${item.name} <small class=\"text-muted\">(${item.generic_name || ''})</small></span><span class=\"badge bg-light text-dark\">${item.quantity} ${item.unit}</span>`;
                        a.addEventListener('click', () => {
                            addMedicineToSelection(item.id, item.name, item.unit);
                            box.innerHTML = '';
                            box.style.display = 'none';
                            medicineSearch.value = '';
                        });
                        box.appendChild(a);
                    });
                    box.style.display = 'block';
                  })
                  .catch(() => { box.innerHTML = ''; box.style.display = 'none'; });
            }, 250);
        });
        document.addEventListener('click', (e) => {
            if (suggestBox && !suggestBox.contains(e.target) && e.target !== medicineSearch) {
                suggestBox.style.display = 'none';
            }
        });

        // Handle medicine selection
        function bindSelectButtons(scope) {
            (scope || document).querySelectorAll('.select-medicine').forEach(button => {
            button.addEventListener('click', function() {
                const medicineId = this.dataset.id;
                const medicineName = this.dataset.name;
                const medicineUnit = this.dataset.unit;
                    addMedicineToSelection(medicineId, medicineName, medicineUnit);
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addMedicineModal'));
                    modal && modal.hide();
                });
            });
        }
        bindSelectButtons(document);

        // Modal search
        const modalSearch = document.getElementById('modalMedicineSearch');
        const medicinesBody = document.getElementById('medicinesBody');
        function renderMedicines(items) {
            medicinesBody.innerHTML = items.map(item => `
                <tr>
                    <td>${escapeHtml(item.name)}</td>
                    <td>${escapeHtml(item.generic_name || '')}</td>
                    <td>${escapeHtml(item.unit || '')}</td>
                    <td>${Number(item.quantity) || 0}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-danger select-medicine" data-id="${item.id}" data-name="${escapeHtml(item.name)}" data-unit="${escapeHtml(item.unit || '')}">
                            <i class="fas fa-plus"></i> Select
                        </button>
                    </td>
                </tr>
            `).join('');
            bindSelectButtons(medicinesBody);
        }
        function escapeHtml(s) {
            return (s || '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        }
        let modalTimer;
        if (modalSearch) {
            modalSearch.addEventListener('input', function() {
                clearTimeout(modalTimer);
                const q = this.value.trim();
                modalTimer = setTimeout(() => {
                    fetch('search_medicines_doctor.php?q=' + encodeURIComponent(q))
                        .then(r => r.json())
                        .then(data => { if (data.success) renderMedicines(data.items || []); })
                        .catch(() => {});
                }, 250);
            });
        }

        function addMedicineToSelection(medicineId, medicineName, medicineUnit) {
            if (document.getElementById('medicine-' + medicineId)) return;
                const medicineHtml = `
                    <div class="input-group mb-2" id="medicine-${medicineId}">
                        <input type="hidden" name="medicines[${medicineId}][id]" value="${medicineId}">
                    <span class="input-group-text bg-white text-dark" style="max-width: 40%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${medicineName}</span>
                        <input type="text" class="form-control" name="medicines[${medicineId}][instructions]" 
                           placeholder="Instructions">
                        <input type="number" class="form-control" name="medicines[${medicineId}][quantity]" 
                               placeholder="Qty" style="max-width: 100px" min="1" required>
                        <span class="input-group-text">${medicineUnit}</span>
                        <button type="button" class="btn btn-outline-danger" onclick="removeMedicine(${medicineId})">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                document.getElementById('selectedMedicines').insertAdjacentHTML('beforeend', medicineHtml);
				scheduleDraftSave();
        }

		// Custom (non-stock) medicines
		const customContainer = document.getElementById('customMedicines');
		const addCustomBtn = document.getElementById('addCustomMedicineBtn');
		function addCustomMedicineRow(data) {
			const idx = ++customMedicineCounter;
			const name = data && data.name ? data.name : '';
			const unit = data && data.unit ? data.unit : '';
			const qty = data && data.quantity ? data.quantity : '';
			const instr = data && data.instructions ? data.instructions : '';
			const html = `
				<div class="input-group mb-2" id="custom-med-${idx}">
					<span class="input-group-text">Name</span>
					<input type="text" class="form-control" name="custom_medicines[${idx}][name]" placeholder="Medicine name" value="${escapeHtml(name)}" required>
					<span class="input-group-text">Qty</span>
					<input type="number" class="form-control" name="custom_medicines[${idx}][quantity]" placeholder="Qty" style="max-width: 100px" min="1" value="${escapeHtml(qty)}">
					<span class="input-group-text">Unit</span>
					<input type="text" class="form-control" name="custom_medicines[${idx}][unit]" placeholder="e.g., tablet, mL" style="max-width: 140px" value="${escapeHtml(unit)}">
					<input type="text" class="form-control" name="custom_medicines[${idx}][instructions]" placeholder="Instructions" value="${escapeHtml(instr)}">
					<button type="button" class="btn btn-outline-danger" onclick="removeCustomMedicine(${idx})">
						<i class="fas fa-times"></i>
					</button>
				</div>
			`;
			customContainer.insertAdjacentHTML('beforeend', html);
			scheduleDraftSave();
		}
		if (addCustomBtn) {
			addCustomBtn.addEventListener('click', () => addCustomMedicineRow());
		}
		window.removeCustomMedicine = function(idx) {
			const el = document.getElementById('custom-med-' + idx);
			if (el) {
				el.remove();
				scheduleDraftSave();
			}
		};

		// Draft: save current form state
		function collectFormDraft() {
			const diag = document.querySelector('textarea[name="diagnosis"]')?.value || '';
			const plan = document.querySelector('textarea[name="treatment_plan"]')?.value || '';
			const pres = document.querySelector('textarea[name="prescription"]')?.value || '';
			const notes = document.querySelector('textarea[name="notes"]')?.value || '';
			const follow = document.querySelector('input[name="follow_up_date"]')?.value || '';
			const recAdm = document.getElementById('recommendAdmissionInput')?.value || '0';
			const admReason = document.querySelector('textarea[name="admission_reason"]')?.value || '';
			const admNotes = document.querySelector('textarea[name="admission_notes"]')?.value || '';
			const admVisible = document.getElementById('admissionRecommendation')?.style.display !== 'none';
			// Collect medicines
			const meds = [];
			document.querySelectorAll('#selectedMedicines .input-group').forEach(group => {
				const idEl = group.querySelector('input[type="hidden"][name^="medicines"][name$="[id]"]');
				if (!idEl) return;
				const id = idEl.value;
				const name = group.querySelector('.input-group-text.bg-white')?.textContent?.trim() || '';
				const qty = group.querySelector('input[name$="[quantity]"]')?.value || '';
				const instr = group.querySelector('input[name$="[instructions]"]')?.value || '';
				const unit = group.querySelector('.input-group-text:last-child')?.textContent?.trim() || '';
				meds.push({ id, name, quantity: qty, instructions: instr, unit });
			});
			// Collect custom medicines
			const customMeds = [];
			document.querySelectorAll('#customMedicines .input-group').forEach(group => {
				const name = group.querySelector('input[name$="[name]"]')?.value || '';
				const qty = group.querySelector('input[name$="[quantity]"]')?.value || '';
				const unit = group.querySelector('input[name$="[unit]"]')?.value || '';
				const instr = group.querySelector('input[name$="[instructions]"]')?.value || '';
				customMeds.push({ name, quantity: qty, unit, instructions: instr });
			});
			return { diag, plan, pres, notes, follow, meds, customMeds, recAdm, admReason, admNotes, admVisible };
		}
		function saveDraftNow() {
			try {
				if (!visitId) return;
				localStorage.setItem(draftKey, JSON.stringify(collectFormDraft()));
			} catch (e) {}
		}
		let draftTimer;
		function scheduleDraftSave() {
			clearTimeout(draftTimer);
			draftTimer = setTimeout(saveDraftNow, 300);
		}
		function loadDraftIfAny() {
			try {
				if (!visitId) return;
				const raw = localStorage.getItem(draftKey);
				if (!raw) return;
				const d = JSON.parse(raw);
				// Restore simple fields
				const diagEl = document.querySelector('textarea[name="diagnosis"]');
				const planEl = document.querySelector('textarea[name="treatment_plan"]');
				const presEl = document.querySelector('textarea[name="prescription"]');
				const notesEl = document.querySelector('textarea[name="notes"]');
				const followEl = document.querySelector('input[name="follow_up_date"]');
				if (diagEl && d.diag != null) diagEl.value = d.diag;
				if (planEl && d.plan != null) planEl.value = d.plan;
				if (presEl && d.pres != null) presEl.value = d.pres;
				if (notesEl && d.notes != null) notesEl.value = d.notes;
				if (followEl && d.follow != null) followEl.value = d.follow;
				// Restore admission block
				const admSection = document.getElementById('admissionRecommendation');
				const admInput = document.getElementById('recommendAdmissionInput');
				if (d.admVisible && admSection && admInput) {
					admSection.style.display = 'block';
					admInput.value = '1';
					const btn = document.getElementById('recommendAdmission');
					if (btn) {
						btn.classList.remove('btn-warning');
						btn.classList.add('btn-danger');
						btn.innerHTML = '<i class="fas fa-times me-1"></i>Cancel Admission';
					}
				}
				const admReasonEl = document.querySelector('textarea[name="admission_reason"]');
				const admNotesEl = document.querySelector('textarea[name="admission_notes"]');
				if (admReasonEl && d.admReason != null) admReasonEl.value = d.admReason;
				if (admNotesEl && d.admNotes != null) admNotesEl.value = d.admNotes;
				// Restore medicines
				if (Array.isArray(d.meds)) {
					d.meds.forEach(m => {
						if (!m || !m.id) return;
						if (!document.getElementById('medicine-' + m.id)) {
							addMedicineToSelection(m.id, m.name || '', m.unit || '');
						}
						const grp = document.getElementById('medicine-' + m.id);
						if (grp) {
							const qtyEl = grp.querySelector('input[name$="[quantity]"]');
							const insEl = grp.querySelector('input[name$="[instructions]"]');
							if (qtyEl && m.quantity != null) qtyEl.value = m.quantity;
							if (insEl && m.instructions != null) insEl.value = m.instructions;
						}
					});
				}
				// Restore custom medicines
				if (Array.isArray(d.customMeds)) {
					d.customMeds.forEach(cm => addCustomMedicineRow(cm));
				}
			} catch (e) {}
		}
		// Hook inputs for autosave
		['textarea[name="diagnosis"]','textarea[name="treatment_plan"]','textarea[name="prescription"]','textarea[name="notes"]','input[name="follow_up_date"]','textarea[name="admission_reason"]','textarea[name="admission_notes"]'].forEach(sel => {
			const el = document.querySelector(sel);
			if (el) el.addEventListener('input', scheduleDraftSave);
		});
		// Medicines change hooks (delegate)
		document.getElementById('selectedMedicines')?.addEventListener('input', function(e){
			if (e.target && (e.target.name?.endsWith('[quantity]') || e.target.name?.endsWith('[instructions]'))) {
				scheduleDraftSave();
			}
		});
		// Custom medicines change hooks
		document.getElementById('customMedicines')?.addEventListener('input', function(e){
			if (e.target && (/\[name\]$|\[quantity\]$|\[unit\]$|\[instructions\]$/.test(e.target.name || ''))) {
				scheduleDraftSave();
			}
		});

        // Function to remove medicine
        window.removeMedicine = function(medicineId) {
            const element = document.getElementById(`medicine-${medicineId}`);
            if (element) {
                element.remove();
				scheduleDraftSave();
            }
        };

        // Form validation + fee prompt + summary flow
        const consultationForm = document.getElementById('consultationForm');
        let readyToSubmit = false;

        function buildSummaryHtml() {
            const diag = (document.querySelector('textarea[name="diagnosis"]')?.value || '').trim();
            const plan = (document.querySelector('textarea[name="treatment_plan"]')?.value || '').trim();
            const pres = (document.querySelector('textarea[name="prescription"]')?.value || '').trim();
            const notes = (document.querySelector('textarea[name="notes"]')?.value || '').trim();
            const follow = (document.querySelector('input[name="follow_up_date"]')?.value || '').trim();
            const visitType = <?php echo json_encode($current_patient['visit_type'] ?? 'new'); ?>;
            const portalStatus = <?php echo json_encode($portal_status); ?>;
            const portalText = portalStatus === 'active' ? 'Active' : (portalStatus === 'none' ? 'Not Created' : 'Unknown');
            const feeVal = (document.getElementById('consultationFeeHidden')?.value || '').trim();
            const patientName = <?php echo json_encode($current_patient['patient_name'] ?? ''); ?>;
            const nowStr = new Date().toLocaleString();
            return `
                <div class="p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="h5 mb-0"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Doctor'); ?></div>
                            <div class="text-muted small">Consultation Summary</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-semibold">${patientName}</div>
                            <div class="text-muted small">${nowStr}</div>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <div class="fw-semibold text-danger mb-1">Diagnosis</div>
                        <div style="white-space: pre-wrap;">${diag || '-'}</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-danger mb-1">Treatment Plan</div>
                        <div style="white-space: pre-wrap;">${plan || '-'}</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-danger mb-1">Prescription / Notes</div>
                        <div style="white-space: pre-wrap;">${pres || '-'}</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-danger mb-1">Follow-up Date</div>
                        <div>${follow ? new Date(follow).toLocaleDateString() : '-'}</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-danger mb-1">Additional Notes</div>
                        <div style="white-space: pre-wrap;">${notes || '-'}</div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                        <div class="small">
                            <span class="badge bg-${visitType === 'follow_up' ? 'info' : 'primary'}">
                                ${visitType === 'follow_up' ? 'Follow-up Patient' : 'New Patient'}
                            </span>
                            <span class="badge bg-${portalStatus === 'active' ? 'success' : (portalStatus === 'none' ? 'secondary' : 'warning')} ms-2">
                                Patient Portal: ${portalText}
                            </span>
                        </div>
                        <div class="small text-muted">Consultation Fee: ₱ ${feeVal || '0.00'}</div>
                    </div>
                </div>
            `;
        }

        function openSummaryModal() {
            const container = document.getElementById('summaryContent');
            if (container) {
                container.innerHTML = buildSummaryHtml();
            }
            if (window.bootstrap && bootstrap.Modal) {
                const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('summaryModal'));
                modal.show();
            }
        }

        consultationForm.addEventListener('submit', function(e) {
            if (readyToSubmit) { return true; }
            const diagnosis = document.querySelector('textarea[name="diagnosis"]').value.trim();
            const treatment = document.querySelector('textarea[name="treatment_plan"]').value.trim();
            const recommendAdmission = document.getElementById('recommendAdmissionInput') ? document.getElementById('recommendAdmissionInput').value === '1' : false;
            const feeHidden = document.getElementById('consultationFeeHidden');
            const feeValue = feeHidden ? feeHidden.value.trim() : '';
            
            if (!diagnosis || !treatment) {
                e.preventDefault();
                alert('Please fill in both Diagnosis and Treatment Plan fields.');
                return false;
            }

            // If not recommending admission, require consultation fee before final submit
            if (!recommendAdmission && (feeValue === '' || isNaN(Number(feeValue)) || Number(feeValue) < 0)) {
                e.preventDefault();
                // Open fee modal
                const feeModalEl = document.getElementById('feeModal');
                if (feeModalEl && window.bootstrap && bootstrap.Modal) {
                    const modal = bootstrap.Modal.getOrCreateInstance(feeModalEl);
                    // Pre-fill last used fee from localStorage draft if any
                    try {
                        const raw = localStorage.getItem(draftKey);
                        if (raw) {
                            const d = JSON.parse(raw);
                            if (d && d.consultationFee != null) {
                                const input = document.getElementById('consultationFeeInput');
                                if (input) input.value = d.consultationFee;
                            }
                        }
                    } catch(_) {}
                    modal.show();
                } else {
                    const v = prompt('Enter consultation fee (₱):', '');
                    if (v === null) { return false; }
                    const num = Number(v);
                    if (!isFinite(num) || num < 0) { alert('Please enter a valid amount.'); return false; }
                    feeHidden.value = String(num.toFixed(2));
                    // proceed to summary
                    openSummaryModal();
                }
                return false;
            }

            // Fee is ready; if admission is NOT recommended, show summary. Otherwise submit immediately.
            if (!recommendAdmission) {
                e.preventDefault();
                openSummaryModal();
                return false;
            } else {
                // Proceed submit immediately for admission path
                // Clear draft on successful submit path
                try { localStorage.removeItem(draftKey); } catch (e) {}
                // Disable submit button to prevent double submission
                const sb = document.getElementById('submitBtn');
                if (sb) {
                    sb.disabled = true;
                    sb.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Completing...';
                }
                readyToSubmit = true;
                return true;
            }
        });

        document.getElementById('recommendAdmission').addEventListener('click', function() {
            const admissionSection = document.getElementById('admissionRecommendation');
            const admissionInput = document.getElementById('recommendAdmissionInput');
            if (admissionSection.style.display === 'none') {
                admissionSection.style.display = 'block';
                admissionInput.value = '1';
                this.classList.remove('btn-warning');
                this.classList.add('btn-danger');
                this.innerHTML = '<i class="fas fa-times me-1"></i>Cancel Admission';
            } else {
                admissionSection.style.display = 'none';
                admissionInput.value = '0';
                this.classList.remove('btn-danger');
                this.classList.add('btn-warning');
                this.innerHTML = '<i class="fas fa-procedures me-1"></i>Recommend Admission';
            }
			scheduleDraftSave();
        });

		// Restore any existing draft after components are ready
		loadDraftIfAny();
    });
    </script>
    <!-- Fee Modal -->
    <div class="modal fade" id="feeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-coins me-2"></i>Consultation Fee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2 text-muted small">
                        This fee will be added to OPD pending payments. If you plan to admit the patient, leave admission recommended ON and no OPD payment will be created.
                    </div>
                    <label class="form-label">Amount (₱)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="consultationFeeInput" placeholder="0.00">
                    <div class="form-text">Enter 0 if no fee.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmFeeBtn">
                        <i class="fas fa-check me-1"></i>Confirm Fee
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const feeInput = document.getElementById('consultationFeeInput');
        const feeHidden = document.getElementById('consultationFeeHidden');
        const confirmBtn = document.getElementById('confirmFeeBtn');
        const consultationForm = document.getElementById('consultationForm');
        const visitIdInput = document.querySelector('input[name="visit_id"]');
        const visitId = visitIdInput ? String(visitIdInput.value) : '';
        const doctorId = String(<?php echo isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0; ?>);
        const draftKey = `consultation_draft_${visitId}_doctor_${doctorId}`;
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                const val = (feeInput ? feeInput.value.trim() : '');
                const num = Number(val);
                if (val === '' || !isFinite(num) || num < 0) {
                    alert('Please enter a valid non-negative amount.');
                    return;
                }
                if (feeHidden) feeHidden.value = String(num.toFixed(2));
                // Save to draft for convenience
                try {
                    const raw = localStorage.getItem(draftKey);
                    const d = raw ? JSON.parse(raw) : {};
                    d.consultationFee = String(num.toFixed(2));
                    localStorage.setItem(draftKey, JSON.stringify(d));
                } catch(_) {}
                // Close modal and open summary
                if (window.bootstrap && bootstrap.Modal) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('feeModal'));
                    modal && modal.hide();
                }
                openSummaryModal();
            });
        }
    });
    </script>
    <!-- Summary Modal -->
    <div class="modal fade" id="summaryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-medical me-2"></i>Consultation Summary</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="summaryContent" class="bg-white rounded border" style="overflow:hidden;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" id="saveSummaryBtn">
                        <i class="fas fa-download me-1"></i>Save Summary
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmCompleteBtn">
                        <i class="fas fa-check me-1"></i>Confirm & Complete
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const saveBtn = document.getElementById('saveSummaryBtn');
        const confirmBtn = document.getElementById('confirmCompleteBtn');
        const container = document.getElementById('summaryContent');
        const form = document.getElementById('consultationForm');
        // Download helper
        function downloadBlob(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => {
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }, 0);
        }
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                if (!window.html2canvas || !container) return;
                html2canvas(container, { scale: 2, backgroundColor: '#ffffff' }).then(canvas => {
                    canvas.toBlob(blob => {
                        const ts = new Date();
                        const fn = `consultation_summary_${ts.getFullYear()}${String(ts.getMonth()+1).padStart(2,'0')}${String(ts.getDate()).padStart(2,'0')}_${String(ts.getHours()).padStart(2,'0')}${String(ts.getMinutes()).padStart(2,'0')}.png`;
                        downloadBlob(blob, fn);
                    });
                });
            });
        }
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                // Proceed to actual submit
                if (window.bootstrap && bootstrap.Modal) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('summaryModal'));
                    modal && modal.hide();
                }
                // Clear draft and submit
                try { 
                    const visitId = document.querySelector('input[name="visit_id"]')?.value || '';
                    const doctorId = String(<?php echo isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0; ?>);
                    const draftKey = `consultation_draft_${visitId}_doctor_${doctorId}`;
                    localStorage.removeItem(draftKey);
                } catch (e) {}
                // Disable button to prevent double-click
                const sb = document.getElementById('submitBtn');
                if (sb) {
                    sb.disabled = true;
                    sb.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Completing...';
                }
                // Allow submit through
                window.readyToSubmit = true;
                try { readyToSubmit = true; } catch(e) {}
                form.submit();
            });
        }
    });
    </script>
</body>
</html> 
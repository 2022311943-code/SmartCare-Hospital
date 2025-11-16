<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Decryption helpers
function dec_row(array $row, array $fields) {
	foreach ($fields as $f) {
		if (array_key_exists($f, $row) && $row[$f] !== null) {
			$once = decrypt_safe((string)$row[$f]);
			// Handle historical rows that were accidentally double-encrypted
			$row[$f] = decrypt_safe($once);
		}
	}
	return $row;
}

function format_phone_display($value) {
    $s = (string)$value;
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') {
        return $s; // fallback
    }
    if (strpos($digits, '63') === 0 && strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return '+63' . $digits;
    }
    return $s;
}

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || (!in_array($_SESSION['employee_type'], ['general_doctor', 'receptionist', 'medical_staff', 'doctor', 'nurse', 'medical_records', 'admin_staff', 'patient_portal']) && !in_array($_SESSION['role'], ['supra_admin','patient_portal']))) {
	header("Location: index.php");
	exit();
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

$newborn_patient_records = [];
try {
	$nb_stmt = $db->query("SELECT newborn_patient_record_id FROM birth_certificates WHERE newborn_patient_record_id IS NOT NULL");
	while ($row = $nb_stmt->fetch(PDO::FETCH_ASSOC)) {
		if (!empty($row['newborn_patient_record_id'])) {
			$newborn_patient_records[(int)$row['newborn_patient_record_id']] = true;
		}
	}
} catch (Exception $e) {
	// optional
}

// Resolve token to patient_record_id if provided
try {
	if (isset($_GET['token']) && !isset($_GET['id'])) {
		$tok_stmt = $db->prepare("SELECT patient_record_id FROM patient_profile_tokens WHERE token = :token");
		$tok_stmt->bindParam(":token", $_GET['token']);
		$tok_stmt->execute();
		$tok_row = $tok_stmt->fetch(PDO::FETCH_ASSOC);
		if ($tok_row && isset($tok_row['patient_record_id'])) {
			$_GET['id'] = (string)intval($tok_row['patient_record_id']);
		} else {
			$error_message = "Invalid or unknown share token.";
		}
	}
} catch (Exception $e) {
	$error_message = "Token resolution error: " . $e->getMessage();
}

// Check if patient ID is provided
if (!isset($_GET['id'])) {
    try {
        // Optional list-level date filters (by OPD visit date)
        $list_start = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : null;
        $list_end = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : null;
        if ($list_start && $list_end && strtotime($list_end) < strtotime($list_start)) { $tmp=$list_start; $list_start=$list_end; $list_end=$tmp; }

        // Build query: constrain the LEFT JOIN by date so counts reflect the range; if filtered, show only patients with activity in range
        $join = "LEFT JOIN opd_visits ov ON pr.id = ov.patient_record_id AND ov.visit_status <> 'cancelled'";
        if ($list_start) { $join .= " AND DATE(ov.created_at) >= :ls"; }
        if ($list_end) { $join .= " AND DATE(ov.created_at) <= :le"; }

        $patients_query = "SELECT 
            pr.*,
            COUNT(DISTINCT ov.id) as total_visits,
            MAX(ov.created_at) as last_visit,
            (SELECT MAX(dd.time_of_death)
             FROM death_declarations dd
             JOIN patient_admissions pa3 ON dd.admission_id = pa3.id
             JOIN opd_visits ov3 ON pa3.source_visit_id = ov3.id
             WHERE ov3.patient_record_id = pr.id) AS death_time
        FROM patient_records pr
        $join
        GROUP BY pr.id";
        if ($list_start || $list_end) { $patients_query .= " HAVING last_visit IS NOT NULL"; }
        $patients_query .= " ORDER BY last_visit DESC, pr.patient_name ASC";
        
        $patients_stmt = $db->prepare($patients_query);
        if ($list_start) { $patients_stmt->bindParam(':ls', $list_start); }
        if ($list_end) { $patients_stmt->bindParam(':le', $list_end); }
        $patients_stmt->execute();
        $patients = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($patients as &$p) {
            $p = dec_row($p, ['patient_name','contact_number','address']);
            $p['is_newborn'] = isset($newborn_patient_records[(int)$p['id']]);
        }
        unset($p);
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

 $patient_is_newborn = false;
try {
	// Get patient details
	if (isset($_GET['id'])) {
		// Optional date filters
		$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : null;
		$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : null;
		// Normalize if end < start
		if ($start_date && $end_date && strtotime($end_date) < strtotime($start_date)) {
			$tmp = $start_date; $start_date = $end_date; $end_date = $tmp;
		}
		$patient_query = "SELECT * FROM patient_records WHERE id = :id";
		$patient_stmt = $db->prepare($patient_query);
		$patient_stmt->bindParam(":id", $_GET['id']);
        $patient_stmt->execute();
        $patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);
        if ($patient) {
            $patient = dec_row($patient, ['patient_name','contact_number','address']);
        }

		$patient_is_newborn = $patient ? isset($newborn_patient_records[(int)$patient['id']]) : false;

		if (!$patient) {
			throw new Exception("Patient not found");
		}

        // Fetch latest death declaration (if any)
        $death_record = null;
        try {
            $death_stmt = $db->prepare("SELECT dd.time_of_death, dd.cause_of_death
                FROM death_declarations dd
                JOIN patient_admissions pa ON dd.admission_id = pa.id
                JOIN opd_visits ov ON pa.source_visit_id = ov.id
                WHERE ov.patient_record_id = :pid
                ORDER BY dd.time_of_death DESC
                LIMIT 1");
            $death_stmt->bindParam(":pid", $_GET['id']);
            $death_stmt->execute();
            $death_record = $death_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* optional */ }

		// Fetch current admission details (if any) matched by name + contact
		$admission_info = null;
		$adm_query = "SELECT pa.id, pa.admission_status, r.room_number, b.bed_number
			FROM patient_admissions pa
			LEFT JOIN rooms r ON pa.room_id = r.id
			LEFT JOIN beds b ON pa.bed_id = b.id
			JOIN opd_visits ov ON ov.id = pa.source_visit_id
			WHERE ov.patient_record_id = :pid AND pa.admission_status = 'admitted'
			ORDER BY pa.created_at DESC LIMIT 1";
		$adm_stmt = $db->prepare($adm_query);
		$adm_stmt->bindParam(":pid", $_GET['id']);
		$adm_stmt->execute();
        $admission_info = $adm_stmt->fetch(PDO::FETCH_ASSOC);

		// Fetch pending admission (for nurse assignment)
		$pending_admission = null;
		$available_rooms = [];
		if (isset($_SESSION['employee_type']) && $_SESSION['employee_type'] === 'nurse') {
			$pend_query = "SELECT pa.id, pa.admission_notes, u.name AS doctor_name
				FROM patient_admissions pa
				LEFT JOIN users u ON pa.admitted_by = u.id
				JOIN opd_visits ov ON ov.id = pa.source_visit_id
				WHERE ov.patient_record_id = :pid AND pa.admission_status = 'pending'
				ORDER BY pa.created_at DESC LIMIT 1";
			$pend_stmt = $db->prepare($pend_query);
			$pend_stmt->bindParam(":pid", $_GET['id']);
			$pend_stmt->execute();
        $pending_admission = $pend_stmt->fetch(PDO::FETCH_ASSOC);
        if ($pending_admission) {
            $pending_admission = dec_row($pending_admission, ['admission_notes','doctor_name']);
        }

			// Available rooms list like assign_room.php
			$rooms_list_query = "SELECT r.id, r.room_number, r.room_type, r.floor_number,
								SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END) AS available_beds
					 		FROM rooms r
					 		LEFT JOIN beds b ON r.id = b.room_id
					 		WHERE r.status = 'active'
					 		GROUP BY r.id
					 		HAVING available_beds > 0
					 		ORDER BY FIELD(r.room_type,'private','semi_private','ward','labor_room','delivery_room','surgery_room'), r.floor_number, r.room_number";
			$rooms_list_stmt = $db->prepare($rooms_list_query);
			$rooms_list_stmt->execute();
			$available_rooms = $rooms_list_stmt->fetchAll(PDO::FETCH_ASSOC);
		}

		// Nurse-visible doctor orders for current admission
		$nurse_doctor_orders = [];
		if (isset($_SESSION['employee_type']) && $_SESSION['employee_type'] === 'nurse' && !empty($admission_info['id'])) {
			$orders_query_full = "SELECT do.*, u.name as ordered_by_name, n.name as claimed_by_name
				FROM doctors_orders do
				JOIN users u ON do.ordered_by = u.id
				LEFT JOIN users n ON do.claimed_by = n.id
				WHERE do.admission_id = :admission_id
				ORDER BY do.ordered_at DESC";
			$orders_stmt = $db->prepare($orders_query_full);
			$orders_stmt->bindParam(":admission_id", $admission_info['id']);
            $orders_stmt->execute();
            $nurse_doctor_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($nurse_doctor_orders as &$ordr) {
                $ordr = dec_row($ordr, ['order_details','special_instructions','completion_note','ordered_by_name','claimed_by_name']);
            }
            unset($ordr);
		}

		// Get all visits with doctor information
        $visits_query = "SELECT 
			ov.*,
			u.name as doctor_name,
			u.employee_type,
			u.department
		FROM opd_visits ov
		LEFT JOIN users u ON ov.doctor_id = u.id
		WHERE ov.patient_record_id = :patient_id AND ov.visit_status <> 'cancelled'";
		if ($start_date && $end_date) {
			$visits_query .= " AND DATE(ov.created_at) BETWEEN :s AND :e";
		} elseif ($start_date) {
			$visits_query .= " AND DATE(ov.created_at) >= :s";
		} elseif ($end_date) {
			$visits_query .= " AND DATE(ov.created_at) <= :e";
		}
		$visits_query .= "
		ORDER BY ov.created_at DESC";
		
		$visits_stmt = $db->prepare($visits_query);
		$visits_stmt->bindParam(":patient_id", $_GET['id']);
		if ($start_date) { $visits_stmt->bindParam(":s", $start_date); }
		if ($end_date) { $visits_stmt->bindParam(":e", $end_date); }
		$visits_stmt->execute();
        $visits = $visits_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($visits as &$v) {
            $v = dec_row($v, ['symptoms','diagnosis','treatment_plan','prescription','doctor_name','department']);
        }
        unset($v);

		// Get lab results (including external uploads)
		$lab_query = "SELECT 
			lr.*,
			lt.test_name,
			lt.test_type,
			u.name as doctor_name,
			u.department
		FROM lab_requests lr
		LEFT JOIN lab_tests lt ON lr.test_id = lt.id
		JOIN users u ON lr.doctor_id = u.id
		JOIN opd_visits ov ON lr.visit_id = ov.id
		WHERE ov.patient_record_id = :pid1";
		if ($start_date && $end_date) {
			$lab_query .= " AND DATE(lr.requested_at) BETWEEN :s AND :e";
		} elseif ($start_date) {
			$lab_query .= " AND DATE(lr.requested_at) >= :s";
		} elseif ($end_date) {
			$lab_query .= " AND DATE(lr.requested_at) <= :e";
		}
		$lab_query .= "
		UNION ALL
		SELECT 
			lr.*,
			lt.test_name,
			lt.test_type,
			u.name as doctor_name,
			u.department
		FROM lab_requests lr
		LEFT JOIN lab_tests lt ON lr.test_id = lt.id
		JOIN users u ON lr.doctor_id = u.id
		JOIN patient_admissions pa ON lr.admission_id = pa.id
		JOIN opd_visits ov2 ON pa.source_visit_id = ov2.id
		WHERE ov2.patient_record_id = :pid2 AND lr.status = 'completed'";
		if ($start_date && $end_date) {
			$lab_query .= " AND DATE(lr.completed_at) BETWEEN :s AND :e";
		} elseif ($start_date) {
			$lab_query .= " AND DATE(lr.completed_at) >= :s";
		} elseif ($end_date) {
			$lab_query .= " AND DATE(lr.completed_at) <= :e";
		}
		$lab_query .= "
		ORDER BY requested_at DESC";
		
		$lab_stmt = $db->prepare($lab_query);
		$lab_stmt->bindParam(":pid1", $_GET['id']);
		$lab_stmt->bindParam(":pid2", $_GET['id']);
		if ($start_date) { $lab_stmt->bindParam(":s", $start_date); }
		if ($end_date) { $lab_stmt->bindParam(":e", $end_date); }
		$lab_stmt->execute();
        $lab_results = $lab_stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($lab_results as &$lr) {
			$lr = dec_row($lr, ['doctor_name','department','result','result_file_path']);
			if (empty($lr['test_name'])) { $lr['test_name'] = '[External Upload]'; }
			if (empty($lr['test_type'])) { $lr['test_type'] = 'laboratory'; }
		}
        unset($lr);

		// Get progress notes
        $notes_query = "
            (
                SELECT 
                    pn.id,
                    pn.patient_record_id,
                    pn.visit_id,
                    pn.note_text,
                    pn.doctor_id,
                    pn.created_at,
                    u.name AS doctor_name,
                    u.department,
                    ov.visit_type,
                    'note' AS entry_kind,
                    NULL AS order_type_label,
                    NULL AS order_details,
                    NULL AS completion_note
                FROM patient_progress_notes pn
                JOIN users u ON pn.doctor_id = u.id
                JOIN opd_visits ov ON pn.visit_id = ov.id
                WHERE pn.patient_record_id = :pid1
            )
            UNION ALL
            (
                SELECT 
                    NULL AS id,
                    ov2.patient_record_id AS patient_record_id,
                    0 AS visit_id,
                    CONCAT('Order Completed: ', UPPER(REPLACE(do.order_type,'_',' '))) AS note_text,
                    do.ordered_by AS doctor_id,
                    do.completed_at AS created_at,
                    u2.name AS doctor_name,
                    u2.department,
                    'inpatient' AS visit_type,
                    'order' AS entry_kind,
                    UPPER(REPLACE(do.order_type,'_',' ')) AS order_type_label,
                    do.order_details AS order_details,
                    do.completion_note AS completion_note
                FROM doctors_orders do
                JOIN patient_admissions pa ON do.admission_id = pa.id
                JOIN opd_visits ov2 ON ov2.id = pa.source_visit_id
                LEFT JOIN users u2 ON do.ordered_by = u2.id
                WHERE ov2.patient_record_id = :pid2 AND do.status = 'completed'
            )
            UNION ALL
            (
                SELECT 
                    NULL AS id,
                    ov3.patient_record_id AS patient_record_id,
                    0 AS visit_id,
                    CONCAT('Lab Result: ', lt.test_name,
                           CASE WHEN lr.result IS NOT NULL AND lr.result <> '' THEN CONCAT('\n', lr.result) ELSE '' END) AS note_text,
                    lr.doctor_id AS doctor_id,
                    lr.completed_at AS created_at,
                    u3.name AS doctor_name,
                    u3.department,
                    'inpatient' AS visit_type,
                    'lab' AS entry_kind,
                    NULL AS order_type_label,
                    NULL AS order_details,
                    NULL AS completion_note
                FROM lab_requests lr
                JOIN lab_tests lt ON lr.test_id = lt.id
                JOIN patient_admissions pa2 ON lr.admission_id = pa2.id
                JOIN opd_visits ov3 ON pa2.source_visit_id = ov3.id
                LEFT JOIN users u3 ON lr.doctor_id = u3.id
                WHERE ov3.patient_record_id = :pid3 AND lr.status = 'completed'
            )
            ORDER BY created_at DESC
        ";
        
		// Apply date filters to progress notes union by constraining each subquery
		if ($start_date || $end_date) {
			$notes_query = str_replace(
				"WHERE pn.patient_record_id = :pid1",
				"WHERE pn.patient_record_id = :pid1" . (
					$start_date && $end_date ? " AND DATE(pn.created_at) BETWEEN :s AND :e" : (
					$start_date ? " AND DATE(pn.created_at) >= :s" : " AND DATE(pn.created_at) <= :e"
				)
				),
				$notes_query
			);
            $notes_query = str_replace(
				"WHERE ov2.patient_record_id = :pid2 AND do.status = 'completed'",
				"WHERE ov2.patient_record_id = :pid2 AND do.status = 'completed'" . (
					$start_date && $end_date ? " AND DATE(do.completed_at) BETWEEN :s AND :e" : (
					$start_date ? " AND DATE(do.completed_at) >= :s" : " AND DATE(do.completed_at) <= :e"
				)
				),
				$notes_query
			);
			$notes_query = str_replace(
				"WHERE ov3.patient_record_id = :pid3 AND lr.status = 'completed'",
				"WHERE ov3.patient_record_id = :pid3 AND lr.status = 'completed'" . (
					$start_date && $end_date ? " AND DATE(lr.completed_at) BETWEEN :s AND :e" : (
					$start_date ? " AND DATE(lr.completed_at) >= :s" : " AND DATE(lr.completed_at) <= :e"
				)
				),
				$notes_query
			);
		}
		$notes_stmt = $db->prepare($notes_query);
        $notes_stmt->bindParam(":pid1", $_GET['id']);
        $notes_stmt->bindParam(":pid2", $_GET['id']);
        $notes_stmt->bindParam(":pid3", $_GET['id']);
		if ($start_date) { $notes_stmt->bindParam(":s", $start_date); }
		if ($end_date) { $notes_stmt->bindParam(":e", $end_date); }
        $notes_stmt->execute();
        $progress_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($progress_notes as &$nt) {
            $nt = dec_row($nt, ['note_text','doctor_name','department','order_details','completion_note']);
        }
        unset($nt);

		// Get medicine orders
		$orders_query = "SELECT 
			mo.*,
			u.name as doctor_name,
			u.department
		FROM medicine_orders mo
		JOIN users u ON mo.doctor_id = u.id
		WHERE mo.patient_id = :patient_id";
		if ($start_date && $end_date) {
			$orders_query .= " AND DATE(mo.created_at) BETWEEN :s AND :e";
		} elseif ($start_date) {
			$orders_query .= " AND DATE(mo.created_at) >= :s";
		} elseif ($end_date) {
			$orders_query .= " AND DATE(mo.created_at) <= :e";
		}
		$orders_query .= "
		ORDER BY mo.created_at DESC";
		
		$orders_stmt = $db->prepare($orders_query);
		$orders_stmt->bindParam(":patient_id", $_GET['id']);
		if ($start_date) { $orders_stmt->bindParam(":s", $start_date); }
		if ($end_date) { $orders_stmt->bindParam(":e", $end_date); }
		$orders_stmt->execute();
        $medicine_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($medicine_orders as &$mo) {
            $mo = dec_row($mo, ['doctor_name','department','notes']);
        }
        unset($mo);

		// Get medicine order items
		$order_items = [];
		if (!empty($medicine_orders)) {
			$items_query = "SELECT 
				moi.*,
				m.name as medicine_name,
				m.unit
			FROM medicine_order_items moi
			JOIN medicines m ON moi.medicine_id = m.id
			WHERE moi.order_id = :order_id";
			
			$items_stmt = $db->prepare($items_query);
			
			foreach ($medicine_orders as $order) {
				$items_stmt->bindParam(":order_id", $order['id']);
				$items_stmt->execute();
                $order_items[$order['id']] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($order_items[$order['id']] as &$it) {
                    $it = dec_row($it, ['instructions']);
                }
                unset($it);
			}
		}
	}
} catch (Exception $e) {
	$error_message = $e->getMessage();
}

// Set page title and additional CSS
$page_title = isset($_GET['id']) ? 'Patient History' : 'Select Patient';
$additional_css = '<style>
	.history-card {
		border: none;
		border-radius: 15px;
		box-shadow: 0 0 20px rgba(0,0,0,0.1);
		margin-bottom: 20px;
	}
	.history-timeline {
		position: relative;
		padding-left: 30px;
	}
	.history-timeline::before {
		content: "";
		position: absolute;
		left: 0;
		top: 0;
		bottom: 0;
		width: 2px;
		background: #dc3545;
		opacity: 0.2;
	}
	.timeline-item {
		position: relative;
		padding-bottom: 20px;
	}
	.timeline-item::before {
		content: "";
		position: absolute;
		left: -34px;
		top: 0;
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background: #dc3545;
	}
	.timeline-date {
		font-size: 0.9rem;
		color: #6c757d;
		margin-bottom: 5px;
	}
	.status-badge {
		padding: 5px 10px;
		border-radius: 20px;
		font-size: 0.8rem;
	}
	.lab-result-file {
		padding: 10px;
		border-radius: 10px;
		background: #f8f9fa;
		margin-top: 10px;
	}
	/* Room selection cards */
	.room-type-card {
		border: 1px solid #dee2e6;
		border-radius: 8px;
		padding: 12px;
		margin-bottom: 10px;
		cursor: pointer;
		transition: all 0.2s ease;
	}
	.room-type-card:hover { background-color: #f8f9fa; transform: translateY(-1px); }
	.room-type-card.selected { border-color: #0d6efd; background-color: #e7f1ff; }
</style>';

// Include header
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient History - SmartCare</title>
	<!-- Bootstrap CSS -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<!-- Font Awesome -->
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<?php echo $additional_css; ?>
</head>
<body>
	<div class="container py-4">
		<?php if (isset($error_message)): ?>
			<div class="alert alert-danger">
				<i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
			</div>
		<?php endif; ?>

		<div class="d-flex justify-content-end mb-3">
			<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'patient_portal'): ?>
				<a href="patient_portal.php" class="btn btn-light btn-sm">
					<i class="fas fa-arrow-left me-1"></i>Back to Patient Dashboard
				</a>
			<?php else: ?>
				<a href="dashboard.php" class="btn btn-light btn-sm">
					<i class="fas fa-arrow-left me-1"></i>Back
				</a>
			<?php endif; ?>
		</div>

		<?php if (!isset($_GET['id'])): ?>
					<!-- Patient List -->
					<div class="card history-card">
					<div class="card-header bg-white py-3">
						<div class="row g-2 align-items-center">
							<div class="col-12 col-xl-4 d-flex align-items-center">
								<h5 class="mb-0">
									<i class="fas fa-users me-2"></i>Select Patient to View History
								</h5>
							</div>
							<div class="col-12 col-xl-8">
								<div class="row g-2 justify-content-end">
									<div class="col-12 col-sm-6 col-md-5">
										<div class="input-group">
											<span class="input-group-text">
												<i class="fas fa-search"></i>
											</span>
											<input type="text" class="form-control form-control-sm" id="patientSearch" placeholder="Search patient name...">
											<button class="btn btn-outline-secondary" type="button" id="clearSearch" style="display: none;">
												<i class="fas fa-times"></i>
											</button>
										</div>
									</div>
									<div class="col-12 col-sm-6 col-md-7">
										<form method="get" class="row g-2 align-items-end">
											<div class="col-12 col-md-4">
												<label class="form-label mb-0 small">Start date</label>
												<input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>">
											</div>
											<div class="col-12 col-md-4">
												<label class="form-label mb-0 small">End date</label>
												<input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>">
											</div>
											<div class="col-12 col-md-4 d-flex gap-2">
												<button type="submit" class="btn btn-sm btn-danger flex-fill"><i class="fas fa-filter me-1"></i>Apply</button>
												<a href="patient_history.php" class="btn btn-sm btn-outline-secondary flex-fill"><i class="fas fa-times me-1"></i>Clear</a>
											</div>
										</form>
									</div>
								</div>
							</div>
						</div>
						<div class="card-body">
							<?php if (empty($patients)): ?>
								<div class="alert alert-info">
									<i class="fas fa-info-circle me-2"></i>No patients found.
								</div>
							<?php else: ?>
								<div class="table-responsive">
									<table class="table table-hover">
										<thead>
											<tr>
												<th>Name</th>
												<th>Age/Gender</th>
												<th>Contact</th>
												<th>Total Visits</th>
												<th>Last Visit</th>
												<th>Action</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach($patients as $p): ?>
											<tr>
												<td>
                                                    <?php echo htmlspecialchars($p['patient_name']); ?>
													<?php if (!empty($p['is_newborn'])): ?>
														<span class="badge bg-pink text-dark ms-1" style="background:#f8d7da;color:#842029;">Newborn</span>
													<?php endif; ?>
                                                    <?php if (!empty($p['death_time'])): ?>
                                                        <span class="badge bg-dark ms-1">Deceased <?php echo date('M d, Y', strtotime($p['death_time'])); ?></span>
                                                    <?php endif; ?>
                                                </td>
													<td>
														<?php echo $p['age']; ?> / 
														<?php echo ucfirst($p['gender']); ?>
													</td>
													<td>
					<i class="fas fa-phone me-1"></i>
					<?php echo htmlspecialchars(format_phone_display($p['contact_number'])); ?>
													</td>
													<td>
														<span class="badge bg-info">
															<?php echo $p['total_visits']; ?> visits
														</span>
													</td>
													<td>
														<?php if ($p['last_visit']): ?>
															<?php echo date('M d, Y', strtotime($p['last_visit'])); ?>
														<?php else: ?>
															-
														<?php endif; ?>
													</td>
													<td>
											<a href="?id=<?php echo $p['id']; ?><?php echo isset($_GET['start_date']) && $_GET['start_date'] !== '' ? '&start_date='.urlencode($_GET['start_date']) : ''; ?><?php echo isset($_GET['end_date']) && $_GET['end_date'] !== '' ? '&end_date='.urlencode($_GET['end_date']) : ''; ?>" class="btn btn-sm btn-outline-secondary me-1">
															<i class="fas fa-history me-1"></i>View History
														</a>
														<?php if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'patient_portal'): ?>
														<a href="opd_registration.php?patient_id=<?php echo intval($p['id']); ?>" class="btn btn-sm btn-danger">
															<i class="fas fa-plus-circle me-1"></i>Follow Up
														</a>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php else: ?>
				<!-- Patient Info Card -->
			<div class="card history-card mb-4">
				<div class="card-body">
					<div class="row align-items-center">
						<div class="col-auto">
							<div class="avatar-circle bg-danger text-white" style="width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
								<i class="fas fa-user-circle fa-2x"></i>
							</div>
						</div>
						<div class="col">
							<h4 class="mb-1">
								<?php echo htmlspecialchars($patient['patient_name']); ?>
								<?php if (!empty($patient_is_newborn)): ?>
									<span class="badge bg-pink text-dark ms-2" style="background:#f8d7da;color:#842029;">Newborn</span>
								<?php endif; ?>
							</h4>
                            <?php if (!empty($death_record['time_of_death'])): ?>
                                <div class="mt-1">
                                    <span class="badge bg-dark">
                                        <i class="fas fa-skull-crossbones me-1"></i>
                                        Deceased <?php echo date('M d, Y h:i A', strtotime($death_record['time_of_death'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
							<div class="text-muted">
								<span class="me-3">
									<i class="fas fa-user me-1"></i><?php echo $patient['age']; ?> years
								</span>
								<span class="me-3">
									<i class="fas fa-venus-mars me-1"></i><?php echo ucfirst($patient['gender']); ?>
								</span>
								<span class="me-3">
					<i class="fas fa-phone me-1"></i><?php echo htmlspecialchars(format_phone_display($patient['contact_number'])); ?>
								</span>
							</div>
							<div class="text-muted small mt-1">
								<i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($patient['address']); ?>
							</div>
							<?php if (!empty($admission_info)): ?>
								<div class="mt-2">
									<span class="badge bg-success">
										<i class="fas fa-procedures me-1"></i>Admitted: Room <?php echo htmlspecialchars($admission_info['room_number'] ?? '-'); ?>, Bed <?php echo htmlspecialchars($admission_info['bed_number'] ?? '-'); ?>
									</span>
									<?php if (!empty($admission_info['id'])): ?>
										<a href="admission_profile.php?admission_id=<?php echo intval($admission_info['id']); ?>" class="btn btn-sm btn-outline-success ms-2">View Admission</a>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="col-auto">
							<?php if (($_SESSION['employee_type'] !== 'general_doctor') && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'patient_portal')): ?>
								<a href="opd_registration.php?patient_id=<?php echo intval($patient['id']); ?>" class="btn btn-danger">
									<i class="fas fa-plus-circle me-2"></i>Follow Up
								</a>
							<?php endif; ?>
							<?php if ($_SESSION['role'] === 'supra_admin' || $_SESSION['employee_type'] === 'admin_staff'): ?>
								<button id="copyProfileLink" type="button" class="btn btn-outline-secondary ms-2" data-patient-record-id="<?php echo intval($patient['id']); ?>">
									<i class="fas fa-link me-2"></i>Copy Profile Link
								</button>
							<?php endif; ?>
							<?php if (isset($_SESSION['employee_type']) && $_SESSION['employee_type'] === 'nurse' && !empty($pending_admission['id'])): ?>
								<button id="assignRoomBtn" type="button" class="btn btn-primary ms-2" data-admission-id="<?php echo intval($pending_admission['id']); ?>" data-doctor-name="<?php echo htmlspecialchars($pending_admission['doctor_name'] ?? '', ENT_QUOTES); ?>" data-initial-orders="<?php echo htmlspecialchars($pending_admission['admission_notes'] ?? '', ENT_QUOTES); ?>">
									<i class="fas fa-bed me-2"></i>Assign Room
								</button>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Date Filters -->
			<div class="card history-card mb-4">
				<div class="card-header bg-white py-3">
					<h5 class="mb-0"><i class="fas fa-filter me-2"></i>History Filters</h5>
				</div>
				<div class="card-body">
					<form method="get" class="row g-2 align-items-end">
						<input type="hidden" name="id" value="<?php echo intval($_GET['id']); ?>">
						<div class="col-sm-4 col-md-3">
							<label class="form-label">Start date</label>
							<input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>">
						</div>
						<div class="col-sm-4 col-md-3">
							<label class="form-label">End date</label>
							<input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>">
						</div>
						<div class="col-sm-4 col-md-3">
							<button type="submit" class="btn btn-danger w-100"><i class="fas fa-search me-1"></i>Apply</button>
						</div>
						<div class="col-sm-12 col-md-3">
							<a href="?id=<?php echo intval($_GET['id']); ?>" class="btn btn-outline-secondary w-100"><i class="fas fa-times me-1"></i>Clear</a>
						</div>
					</form>
				</div>
			</div>

			<!-- History Timeline -->
			<div class="row">
				<?php if (!isset($_SESSION['employee_type']) || $_SESSION['employee_type'] !== 'nurse'): ?>
				<div class="col-md-8">
					<!-- Visits Timeline -->
					<div class="card history-card mb-4">
						<div class="card-header bg-white py-3">
							<h5 class="mb-0">
								<i class="fas fa-calendar-check me-2"></i>Visit History
							</h5>
						</div>
						<div class="card-body">
							<div class="history-timeline">
								<?php if (empty($visits)): ?>
									<div class="text-muted text-center py-3">No visit history available</div>
								<?php else: ?>
									<div id="visitsList">
										<?php foreach($visits as $visit): ?>
											<div class="timeline-item">
												<div class="timeline-date">
													<i class="fas fa-calendar me-1"></i>
													<?php echo date('M d, Y h:i A', strtotime($visit['created_at'])); ?>
												</div>
												<div class="mb-2">
													<span class="badge bg-<?php 
														echo $visit['visit_type'] === 'follow_up' ? 'info' : 'primary'; 
													?> me-2">
														<?php echo $visit['visit_type'] === 'follow_up' ? 'Follow-up Visit' : 'New Visit'; ?>
													</span>
													<span class="badge bg-<?php 
														echo $visit['visit_status'] === 'waiting' ? 'warning' : 
															($visit['visit_status'] === 'in_progress' ? 'info' : 
															($visit['visit_status'] === 'completed' ? 'success' : 'secondary')); 
													?>">
														<?php echo ucfirst($visit['visit_status']); ?>
													</span>
												</div>
												<div class="card">
													<div class="card-body">
														<div class="row mb-2">
															<div class="col-md-6">
																<small class="text-muted">Doctor:</small>
																<span class="ms-1"><?php echo htmlspecialchars($visit['doctor_name'] ?? 'Not assigned'); ?></span>
															</div>
															<div class="col-md-6">
																<small class="text-muted">Department:</small>
																<span class="ms-1"><?php echo htmlspecialchars($visit['department'] ?? '-'); ?></span>
															</div>
														</div>
														<?php if ($visit['symptoms']): ?>
															<div class="mb-2">
																<small class="text-muted">Symptoms:</small>
																<div><?php echo nl2br(htmlspecialchars($visit['symptoms'])); ?></div>
															</div>
														<?php endif; ?>
														<?php if ($visit['diagnosis']): ?>
															<div class="mb-2">
																<small class="text-muted">Diagnosis:</small>
																<div><?php echo nl2br(htmlspecialchars($visit['diagnosis'])); ?></div>
															</div>
														<?php endif; ?>
														<?php if ($visit['treatment_plan']): ?>
															<div class="mb-2">
																<small class="text-muted">Treatment Plan:</small>
																<div><?php echo nl2br(htmlspecialchars($visit['treatment_plan'])); ?></div>
															</div>
														<?php endif; ?>
														<?php if ($visit['prescription']): ?>
															<div>
																<small class="text-muted">Prescription:</small>
																<div><?php echo nl2br(htmlspecialchars($visit['prescription'])); ?></div>
															</div>
														<?php endif; ?>
													</div>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
									<?php if (count($visits) > 5): ?>
										<div class="text-center mt-2">
											<button type="button" class="btn btn-sm btn-outline-secondary toggle-more" data-target="#visitsList" data-item-selector=".timeline-item" data-limit="5">
												<i class="fas fa-plus me-1"></i>Show more
											</button>
										</div>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<div class="col-md-4">
					<?php if (isset($_SESSION['employee_type']) && $_SESSION['employee_type'] === 'nurse' && !empty($admission_info['id'])): ?>
					<!-- Doctor Orders (Nurse View) -->
					<div class="card history-card mb-4">
						<div class="card-header bg-white py-3">
							<h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Doctor Orders</h5>
						</div>
						<div class="card-body">
							<?php if (empty($nurse_doctor_orders)): ?>
								<div class="text-muted text-center py-3">No orders available</div>
							<?php else: ?>
								<?php foreach ($nurse_doctor_orders as $ord): ?>
									<div class="mb-3 pb-3 border-bottom">
										<div class="d-flex justify-content-between align-items-center mb-1">
											<span class="badge bg-<?php echo $ord['status']==='completed' ? 'success' : ($ord['status']==='in_progress' ? 'info' : ($ord['status']==='discontinued' ? 'secondary' : 'warning')); ?>">
												<?php echo ucfirst($ord['status']); ?>
											</span>
											<small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($ord['ordered_at'])); ?></small>
										</div>
										<div class="mb-1"><strong><?php echo ucfirst(str_replace('_',' ', $ord['order_type'])); ?></strong></div>
										<div class="mb-1"><?php echo nl2br(htmlspecialchars($ord['order_details'])); ?></div>
										<?php if (!empty($ord['frequency']) || !empty($ord['duration'])): ?>
											<div class="small text-muted">Freq: <?php echo htmlspecialchars($ord['frequency'] ?? '-'); ?> | Dur: <?php echo htmlspecialchars($ord['duration'] ?? '-'); ?></div>
										<?php endif; ?>
										<?php if (!empty($ord['special_instructions'])): ?>
											<div class="small text-muted">Notes: <?php echo nl2br(htmlspecialchars($ord['special_instructions'])); ?></div>
										<?php endif; ?>
										<div class="small text-muted mt-1">Ordered by: <?php echo htmlspecialchars($ord['ordered_by_name']); ?></div>
										<div class="d-flex gap-2 mt-2">
											<?php if ($ord['status'] === 'active'): ?>
												<button class="btn btn-sm btn-outline-primary btn-claim-order" data-order-id="<?php echo intval($ord['id']); ?>">
													<i class="fas fa-hand-paper me-1"></i>Claim
												</button>
											<?php elseif ($ord['status'] === 'in_progress' && intval($ord['claimed_by']) === intval($_SESSION['user_id'])): ?>
												<button class="btn btn-sm btn-outline-secondary btn-release-order" data-order-id="<?php echo intval($ord['id']); ?>">
													<i class="fas fa-undo me-1"></i>Release
												</button>
											<?php endif; ?>
											<?php if ($ord['status'] === 'in_progress' && intval($ord['claimed_by']) === intval($_SESSION['user_id'])): ?>
												<button class="btn btn-sm btn-success btn-complete-order" data-order-id="<?php echo intval($ord['id']); ?>">
													<i class="fas fa-check me-1"></i>Done
												</button>
											<?php else: ?>
												<button class="btn btn-sm btn-success" disabled title="Claim this order to complete">
													<i class="fas fa-check me-1"></i>Done
												</button>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
			<?php endif; ?>
					<?php if (!isset($_SESSION['employee_type']) || $_SESSION['employee_type'] !== 'nurse'): ?>
					<!-- Lab Results -->
					<div class="card history-card mb-4">
						<div class="card-header bg-white py-3">
							<h5 class="mb-0">
								<i class="fas fa-flask me-2"></i>Laboratory Results
							</h5>
						</div>
						<div class="card-body">
							<?php if (empty($lab_results)): ?>
								<div class="text-muted text-center py-3">No laboratory results available</div>
							<?php else: ?>
								<div id="labResultsList">
									<?php foreach($lab_results as $result): ?>
									<div class="mb-3 pb-3 border-bottom lab-item">
										<div class="d-flex justify-content-between alignments-start mb-2">
											<h6 class="mb-0">
												<?php if ($result['test_type'] === 'laboratory'): ?>
													<i class="fas fa-flask text-primary me-2"></i>
												<?php else: ?>
													<i class="fas fa-x-ray text-info me-2"></i>
												<?php endif; ?>
												<?php echo htmlspecialchars($result['test_name']); ?>
											</h6>
											<span class="badge bg-<?php 
												echo $result['status'] === 'completed' ? 'success' : 
													($result['status'] === 'in_progress' ? 'info' : 'warning'); 
											?>">
												<?php echo ucfirst($result['status']); ?>
											</span>
										</div>
										<div class="text-muted small">
											<div>
												<strong>Requested:</strong> 
												<?php echo date('M d, Y h:i A', strtotime($result['requested_at'])); ?>
											</div>
											<?php if ($result['completed_at']): ?>
												<div>
													<strong>Completed:</strong>
													<?php echo date('M d, Y h:i A', strtotime($result['completed_at'])); ?>
												</div>
											<?php endif; ?>
										</div>
										<?php if ($result['result']): ?>
											<div class="mt-2">
												<?php echo nl2br(htmlspecialchars($result['result'])); ?>
											</div>
										<?php endif; ?>
										<?php if ($result['result_file_path']): ?>
											<div class="lab-result-file">
												<a href="<?php echo htmlspecialchars($result['result_file_path']); ?>" 
												   class="btn btn-sm btn-outline-primary" 
												   target="_blank">
													<i class="fas fa-file-download me-1"></i>
													View Result File
												</a>
											</div>
										<?php endif; ?>
									</div>
									<?php endforeach; ?>
								</div>
								<?php if (count($lab_results) > 5): ?>
									<div class="text-center mt-2">
										<button type="button" class="btn btn-sm btn-outline-secondary toggle-more" data-target="#labResultsList" data-item-selector=".lab-item" data-limit="5">
											<i class="fas fa-plus me-1"></i>Show more
										</button>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>

					<!-- Progress Notes -->
					<div class="card history-card mb-4">
						<div class="card-header bg-white py-3">
							<h5 class="mb-0">
								<i class="fas fa-notes-medical me-2"></i>Progress Notes
							</h5>
						</div>
						<div class="card-body">
							<?php if (empty($progress_notes)): ?>
								<div class="text-muted text-center py-3">No progress notes available</div>
							<?php else: ?>
							<div id="progressNotesList">
								<?php foreach($progress_notes as $note): ?>
								<div class="mb-3 pb-3 border-bottom progress-note-item">
								<div class="d-flex justify-content-between align-items-start mb-2">
									<div>
										<span class="text-danger">Dr. <?php echo htmlspecialchars($note['doctor_name']); ?></span>
										<small class="text-muted">(<?php echo htmlspecialchars($note['department']); ?>)</small>
										<?php if (($note['entry_kind'] ?? '') === 'order'): ?>
											<span class="badge bg-primary ms-2"><?php echo htmlspecialchars($note['order_type_label']); ?></span>
										<?php endif; ?>
									</div>
									<small class="text-muted">
										<?php echo date('M d, Y h:i A', strtotime($note['created_at'])); ?>
									</small>
								</div>
								<div class="mb-1"><?php echo nl2br(htmlspecialchars($note['note_text'])); ?></div>
								<?php if (($note['entry_kind'] ?? '') === 'order'): ?>
									<?php if (!empty($note['order_details'])): ?>
										<div class="small text-muted"><strong>Details:</strong> <?php echo nl2br(htmlspecialchars($note['order_details'])); ?></div>
									<?php endif; ?>
									<?php if (!empty($note['completion_note'])): ?>
										<div class="small text-muted"><strong>Note:</strong> <?php echo nl2br(htmlspecialchars($note['completion_note'])); ?></div>
									<?php endif; ?>
								<?php endif; ?>
							</div>
								<?php endforeach; ?>
							</div>
							<?php if (count($progress_notes) > 5): ?>
								<div class="text-center mt-2">
									<button type="button" class="btn btn-sm btn-outline-secondary toggle-more" data-target="#progressNotesList" data-item-selector=".progress-note-item" data-limit="5">
										<i class="fas fa-plus me-1"></i>Show more
									</button>
								</div>
							<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>

					<!-- Medicine Orders -->
					<div class="card history-card">
						<div class="card-header bg-white py-3">
							<h5 class="mb-0">
								<i class="fas fa-prescription-bottle-alt me-2"></i>Medicine Orders
							</h5>
						</div>
						<div class="card-body">
							<?php if (empty($medicine_orders)): ?>
								<div class="text-muted text-center py-3">No medicine orders available</div>
							<?php else: ?>
								<div id="medicineOrdersList">
									<?php foreach($medicine_orders as $order): ?>
									<div class="mb-4 pb-3 border-bottom medicine-order-item">
										<div class="d-flex justify-content-between align-items-start mb-2">
											<div>
												<span class="badge bg-<?php 
													echo $order['status'] === 'completed' ? 'success' : 
														($order['status'] === 'processing' ? 'info' : 
														($order['status'] === 'cancelled' ? 'danger' : 'warning')); 
												?> me-2">
													<?php echo ucfirst($order['status']); ?>
												</span>
												<span class="text-danger">Order #<?php echo $order['order_number']; ?></span>
											</div>
											<small class="text-muted">
												<?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?>
											</small>
										</div>
										<div class="mb-2">
											<small class="text-muted">Prescribed by:</small>
											<div>
												Dr. <?php echo htmlspecialchars($order['doctor_name']); ?>
												<small class="text-muted">(<?php echo htmlspecialchars($order['department']); ?>)</small>
											</div>
										</div>
										<?php if (isset($order_items[$order['id']])): ?>
											<div class="mb-2">
												<small class="text-muted">Medicines:</small>
												<div class="table-responsive">
													<table class="table table-sm">
														<thead>
															<tr>
																<th>Medicine</th>
																<th>Quantity</th>
																<th>Instructions</th>
															</tr>
														</thead>
														<tbody>
															<?php foreach($order_items[$order['id']] as $item): ?>
																<tr>
																	<td><?php echo htmlspecialchars($item['medicine_name']); ?></td>
																	<td><?php echo $item['quantity'] . ' ' . htmlspecialchars($item['unit']); ?></td>
																	<td><?php echo htmlspecialchars($item['instructions']); ?></td>
																</tr>
															<?php endforeach; ?>
														</tbody>
													</table>
												</div>
											</div>
										<?php endif; ?>
										<?php if ($order['notes']): ?>
											<div>
												<small class="text-muted">Notes:</small>
												<div><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
											</div>
										<?php endif; ?>
									</div>
									<?php endforeach; ?>
								</div>
								<?php if (count($medicine_orders) > 5): ?>
									<div class="text-center mt-2">
										<button type="button" class="btn btn-sm btn-outline-secondary toggle-more" data-target="#medicineOrdersList" data-item-selector=".medicine-order-item" data-limit="5">
											<i class="fas fa-plus me-1"></i>Show more
										</button>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<!-- Assign Room Modal -->
	<div class="modal fade" id="assignRoomModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="fas fa-bed me-2"></i>Assign Room</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<input type="hidden" id="assignAdmissionId" value="">
					<input type="hidden" id="selectedRoomType" value="">
					<input type="hidden" id="selectedRoomId" value="">
					<div class="mb-3 p-3 border rounded bg-light">
						<div class="d-flex justify-content-between align-items-center">
							<strong>Patient</strong>
							<span class="fw-bold text-dark" id="assignPatientName"><?php echo htmlspecialchars(($patient['patient_name'] ?? '-') . (isset($patient['age']) ? ' ('.$patient['age'].'y / '.ucfirst($patient['gender']).')' : '')); ?></span>
						</div>
						<div class="d-flex justify-content-between">
							<strong>Admitting Doctor</strong>
							<span id="assignDoctorName" class="text-muted">-</span>
						</div>
						<div class="mt-2">
							<strong>Initial Instructions / Orders</strong>
							<div id="assignInitialOrders" class="small text-muted"><em>No initial instructions provided.</em></div>
						</div>
					</div>
					<div class="mb-3">
						<label class="form-label">Select Room</label>
						<?php 
							$typeLabels = [
								'private' => 'Private',
								'semi_private' => 'Semi private',
								'ward' => 'Ward',
								'labor_room' => 'Labor',
								'delivery_room' => 'Delivery',
								'surgery_room' => 'Surgery'
							];
							foreach ($available_rooms as $room): 
								$typeLabel = $typeLabels[$room['room_type']] ?? ucwords(str_replace('_',' ', $room['room_type']));
						?>
							<div class="room-type-card" onclick="selectAssignRoom('<?php echo $room['id']; ?>','<?php echo $room['room_type']; ?>', this)">
								<div class="d-flex justify-content-between align-items-center">
									<div>
										<h5 class="mb-1"><?php echo htmlspecialchars($room['room_number']); ?></h5>
										<p class="mb-0 text-muted"><?php echo $typeLabel; ?> • Floor <?php echo (int)$room['floor_number']; ?> • <?php echo (int)$room['available_beds']; ?> bed(s) available</p>
									</div>
									<div class="text-success"><i class="fas fa-circle"></i></div>
								</div>
							</div>
						<?php endforeach; ?>
						<?php if (empty($available_rooms)): ?>
							<div class="alert alert-info mb-0">No available beds in any room.</div>
						<?php endif; ?>
					</div>
					<div class="alert alert-info d-flex align-items-center" role="alert">
						<i class="fas fa-info-circle me-2"></i>
						<b>Tip:</b>&nbsp;If you don't select a room, the system will auto-pick the first available bed for the chosen type.
					</div>
					<div class="mb-3">
						<label class="form-label">Fallback Room Type</label>
						<select id="assignRoomType" class="form-select">
							<option value="private">Private</option>
							<option value="semi_private">Semi Private</option>
							<option value="ward">Ward</option>
							<option value="labor_room">Labor Room</option>
							<option value="delivery_room">Delivery Room</option>
							<option value="surgery_room">Surgery Room</option>
						</select>
					</div>
					<div id="assignRoomFeedback" class="mt-2"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
					<button type="button" id="confirmAssignRoom" class="btn btn-primary">Assign</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Complete Order Modal -->
	<div class="modal fade" id="completeOrderModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="fas fa-check me-2"></i>Mark Order as Done</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<input type="hidden" id="completeOrderId" value="">
					<div class="mb-3">
						<label class="form-label">Completion Note (optional)</label>
						<textarea id="completionNote" class="form-control" rows="3" placeholder="Notes, actions taken, administered meds, etc."></textarea>
					</div>
					<div id="completeOrderFeedback" class="mt-2"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="button" id="confirmCompleteOrder" class="btn btn-success">Mark Done</button>
				</div>
			</div>
		</div>
	</div>
 
	<!-- Bootstrap Bundle with Popper -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const searchInput = document.getElementById('patientSearch');
		const clearButton = document.getElementById('clearSearch');
		const tableRows = document.querySelectorAll('tbody tr');

		function filterPatients(searchTerm) {
			searchTerm = searchTerm.toLowerCase();
			tableRows.forEach(row => {
				const name = row.querySelector('td:first-child').textContent.toLowerCase();
				const contact = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
				if (name.includes(searchTerm) || contact.includes(searchTerm)) {
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			});
		}

		if (searchInput) {
			searchInput.addEventListener('input', function() {
				const searchTerm = this.value.trim();
				clearButton.style.display = searchTerm ? 'block' : 'none';
				filterPatients(searchTerm);
			});
		}

		if (clearButton) {
			clearButton.addEventListener('click', function() {
				searchInput.value = '';
				clearButton.style.display = 'none';
				filterPatients('');
			});
		}

		const copyBtn = document.getElementById('copyProfileLink');
		if (copyBtn) {
			copyBtn.addEventListener('click', function() {
				const recordId = copyBtn.getAttribute('data-patient-record-id');
				copyBtn.disabled = true;
				const original = copyBtn.innerHTML;
				copyBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
				fetch('generate_patient_profile_token.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ patient_record_id: Number(recordId) })
				})
				.then(r => r.json())
				.then(data => {
					if (!data.success) throw new Error(data.message || 'Failed');
					const url = window.location.origin + window.location.pathname + '?token=' + data.token;
					navigator.clipboard.writeText(url).then(() => {
						copyBtn.innerHTML = '<i class="fas fa-check me-2"></i>Copied';
						setTimeout(() => { copyBtn.innerHTML = original; copyBtn.disabled = false; }, 1500);
					}).catch(() => {
						alert('Failed to copy link.');
						copyBtn.innerHTML = original;
						copyBtn.disabled = false;
					});
				})
				.catch(err => {
					console.error(err);
					alert('Unable to generate link.');
					copyBtn.innerHTML = original;
					copyBtn.disabled = false;
				});
			});
		}

		// Assign room flow for nurses
		const assignBtn = document.getElementById('assignRoomBtn');
		const assignModalEl = document.getElementById('assignRoomModal');
		const assignModal = assignModalEl ? new bootstrap.Modal(assignModalEl) : null;
		const assignAdmissionId = document.getElementById('assignAdmissionId');
		const assignRoomType = document.getElementById('assignRoomType');
		const confirmAssignRoom = document.getElementById('confirmAssignRoom');
		const assignRoomFeedback = document.getElementById('assignRoomFeedback');
		const selectedRoomType = document.getElementById('selectedRoomType');
		const selectedRoomId = document.getElementById('selectedRoomId');
		const assignDoctorName = document.getElementById('assignDoctorName');
		const assignInitialOrders = document.getElementById('assignInitialOrders');

		if (assignBtn && assignModal) {
			assignBtn.addEventListener('click', function() {
				assignAdmissionId.value = assignBtn.getAttribute('data-admission-id');
				assignRoomFeedback.innerHTML = '';
				selectedRoomId.value = '';
				selectedRoomType.value = '';
				assignDoctorName.textContent = assignBtn.getAttribute('data-doctor-name') || '-';
				const init = assignBtn.getAttribute('data-initial-orders') || '';
				assignInitialOrders.innerHTML = init ? init.replace(/\n/g, '<br>') : '<em>No initial instructions provided.</em>';
				assignModal.show();
			});

			confirmAssignRoom.addEventListener('click', function() {
				const payload = new FormData();
				payload.append('admission_id', assignAdmissionId.value);
				if (selectedRoomId.value) {
					payload.append('room_id', selectedRoomId.value);
				} else {
					payload.append('room_type', selectedRoomType.value || assignRoomType.value);
				}
				confirmAssignRoom.disabled = true;
				confirmAssignRoom.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Assigning...';
				fetch('process_room_assignment.php', { method: 'POST', body: payload })
					.then(r => r.json())
					.then(data => {
						if (data.success) {
							assignRoomFeedback.className = 'alert alert-success';
							assignRoomFeedback.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + data.message;
							setTimeout(() => { window.location.reload(); }, 1200);
						} else {
							assignRoomFeedback.className = 'alert alert-danger';
							assignRoomFeedback.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + (data.error || 'Failed to assign room');
						}
					})
					.catch(() => {
						assignRoomFeedback.className = 'alert alert-danger';
						assignRoomFeedback.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Server error';
					})
					.finally(() => {
						confirmAssignRoom.disabled = false;
						confirmAssignRoom.innerHTML = 'Assign';
					});
			});
		}

		// Selection of a specific room card
		window.selectAssignRoom = function(roomId, roomType, card) {
			// toggle selected class
			document.querySelectorAll('#assignRoomModal .room-type-card').forEach(c => c.classList.remove('selected'));
			card.classList.add('selected');
			selectedRoomId.value = roomId;
			selectedRoomType.value = roomType;
		};

		// Nurse order actions: claim/release/done
		function postJSON(url, payload) {
			return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(r => r.json());
		}

		// Claim
		document.querySelectorAll('.btn-claim-order').forEach(btn => {
			btn.addEventListener('click', () => {
				btn.disabled = true;
				btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Claiming...';
				postJSON('nurse_claim_order.php', { action: 'claim', order_id: Number(btn.getAttribute('data-order-id')) })
					.then(data => {
						if (!data.success) throw new Error(data.message || 'Failed');
						window.location.reload();
					})
					.catch(e => { alert(e.message || 'Unable to claim'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-hand-paper me-1"></i>Claim'; });
			});
		});

		// Release
		document.querySelectorAll('.btn-release-order').forEach(btn => {
			btn.addEventListener('click', () => {
				btn.disabled = true;
				btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Releasing...';
				postJSON('nurse_claim_order.php', { action: 'release', order_id: Number(btn.getAttribute('data-order-id')) })
					.then(data => {
						if (!data.success) throw new Error(data.message || 'Failed');
						window.location.reload();
					})
					.catch(e => { alert(e.message || 'Unable to release'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo me-1"></i>Release'; });
			});
		});

		// Complete
		const completeModalEl = document.getElementById('completeOrderModal');
		const completeModal = completeModalEl ? new bootstrap.Modal(completeModalEl) : null;
		const completeOrderId = document.getElementById('completeOrderId');
		const completionNote = document.getElementById('completionNote');
		const confirmCompleteOrder = document.getElementById('confirmCompleteOrder');
		const completeOrderFeedback = document.getElementById('completeOrderFeedback');

		document.querySelectorAll('.btn-complete-order').forEach(btn => {
			btn.addEventListener('click', () => {
				completeOrderId.value = btn.getAttribute('data-order-id');
				completionNote.value = '';
				completeOrderFeedback.innerHTML = '';
				completeModal && completeModal.show();
			});
		});

			if (confirmCompleteOrder && completeModal) {
			confirmCompleteOrder.addEventListener('click', () => {
				confirmCompleteOrder.disabled = true;
				confirmCompleteOrder.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
				postJSON('nurse_complete_order.php', { order_id: Number(completeOrderId.value), completion_note: completionNote.value })
					.then(data => {
						if (!data.success) throw new Error(data.message || 'Failed');
						window.location.reload();
					})
					.catch(e => {
						completeOrderFeedback.className = 'alert alert-danger';
						completeOrderFeedback.textContent = e.message || 'Unable to complete order';
					})
					.finally(() => {
						confirmCompleteOrder.disabled = false;
						confirmCompleteOrder.innerHTML = 'Mark Done';
					});
			});
		}

			// Collapsible sections: show first N, toggle with + / -
			function setupCollapsible(button) {
				const targetSel = button.getAttribute('data-target');
				const itemSel = button.getAttribute('data-item-selector') || ':scope > *';
				const limit = parseInt(button.getAttribute('data-limit') || '5', 10);
				if (!targetSel) return;
				const container = document.querySelector(targetSel);
				if (!container) return;
				const items = Array.from(container.querySelectorAll(itemSel));
				if (items.length <= limit) {
					button.style.display = 'none';
					return;
				}
				let expanded = false;
				function applyState() {
					items.forEach((el, idx) => {
						if (!expanded && idx >= limit) {
							el.style.display = 'none';
						} else {
							el.style.display = '';
						}
					});
					button.innerHTML = expanded
						? '<i class="fas fa-minus me-1"></i>Show less'
						: '<i class="fas fa-plus me-1"></i>Show more';
				}
				applyState();
				button.addEventListener('click', function() {
					expanded = !expanded;
					applyState();
				});
			}

			// Initialize collapsible buttons present on the page
			document.querySelectorAll('.toggle-more').forEach(setupCollapsible);
	});
	</script>
</body>
</html> 
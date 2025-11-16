<?php
session_start();
require_once "config/database.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

function ensureNewbornPatientColumn(PDO $db): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    try {
        $check = $db->prepare("SHOW COLUMNS FROM birth_certificates LIKE 'newborn_patient_record_id'");
        $check->execute();
        if (!$check->fetch()) {
            $db->exec("ALTER TABLE birth_certificates ADD COLUMN newborn_patient_record_id INT NULL");
        }
    } catch (Exception $e) {
        // best effort; dashboard will fall back gracefully
    }
}

ensureNewbornPatientColumn($db);

// Get user's full information
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(":user_id", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$specialist_departments = ['OB-GYN', 'Pediatrics'];
$is_specialist_doctor = (
    $_SESSION['employee_type'] === 'doctor'
    && !empty($user['department'])
    && in_array($user['department'], $specialist_departments)
);
$effective_employee_type = $is_specialist_doctor ? 'general_doctor' : $_SESSION['employee_type'];
$show_general_doctor_dashboard = ($effective_employee_type === 'general_doctor');
$show_standard_doctor_dashboard = ($_SESSION['employee_type'] === 'doctor' && !$is_specialist_doctor);

// OPD waiting patients today (for notifier)
$opd_waiting_count_today = 0;
try {
    if (in_array($_SESSION['employee_type'], ['general_doctor','doctor'])) {
        $wq = "SELECT COUNT(*) FROM opd_visits WHERE DATE(arrival_time) = CURDATE() AND visit_status = 'waiting' AND doctor_id = :uid";
        $wstmt = $db->prepare($wq);
        $wstmt->bindParam(':uid', $_SESSION['user_id']);
    } else {
        $wq = "SELECT COUNT(*) FROM opd_visits WHERE DATE(arrival_time) = CURDATE() AND visit_status = 'waiting'";
        $wstmt = $db->prepare($wq);
    }
    $wstmt->execute();
    $opd_waiting_count_today = (int)$wstmt->fetchColumn();
} catch (Exception $e) {
    $opd_waiting_count_today = 0;
}

// Function to get role-specific stats
function getRoleStats($db, $employee_type, $user_id) {
    $stats = [];
    
    switch($employee_type) {
        case 'general_doctor':
            // Get count of today's OPD consultations
            $opd_query = "SELECT 
                COUNT(*) as total_patients,
                SUM(CASE WHEN visit_status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
                SUM(CASE WHEN visit_status = 'in_progress' THEN 1 ELSE 0 END) as ongoing_count,
                SUM(CASE WHEN visit_status = 'completed' THEN 1 ELSE 0 END) as completed_count
            FROM opd_visits 
            WHERE DATE(arrival_time) = CURDATE() AND doctor_id = :uid";
            $opd_stmt = $db->prepare($opd_query);
            $opd_stmt->bindParam(":uid", $user_id);
            $opd_stmt->execute();
            $opd_result = $opd_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Inpatient admissions breakdown for this general doctor
            $gd_admissions_query = "SELECT 
                    SUM(CASE WHEN pa.admission_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN pa.admission_status = 'admitted' THEN 1 ELSE 0 END) AS admitted_count
                FROM patient_admissions pa
                JOIN patients p ON pa.patient_id = p.id
                WHERE p.doctor_id = :uid OR pa.admitted_by = :uid";
            $gd_admissions_stmt = $db->prepare($gd_admissions_query);
            $gd_admissions_stmt->bindParam(":uid", $user_id);
            $gd_admissions_stmt->execute();
            $gd_admissions = $gd_admissions_stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats[] = [
                'title' => 'Waiting Patients',
                'value' => $opd_result['waiting_count'],
                'icon' => 'fa-user-clock',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Current Consultation',
                'value' => $opd_result['ongoing_count'],
                'icon' => 'fa-stethoscope',
                'color' => 'info'
            ];
            $stats[] = [
                'title' => 'Completed Today',
                'value' => $opd_result['completed_count'],
                'icon' => 'fa-check-circle',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Total Patients Today',
                'value' => $opd_result['total_patients'],
                'icon' => 'fa-users',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'Inpatients Pending',
                'value' => isset($gd_admissions['pending_count']) ? $gd_admissions['pending_count'] : 0,
                'icon' => 'fa-hourglass-half',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Inpatients Admitted',
                'value' => isset($gd_admissions['admitted_count']) ? $gd_admissions['admitted_count'] : 0,
                'icon' => 'fa-bed',
                'color' => 'success'
            ];
            break;

        case 'doctor':
            // Get count of assigned patients
            $patient_query = "SELECT COUNT(*) as count FROM patients WHERE doctor_id = :doctor_id";
            $patient_stmt = $db->prepare($patient_query);
            $patient_stmt->bindParam(":doctor_id", $user_id);
            $patient_stmt->execute();
            $patient_result = $patient_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Inpatient admissions breakdown for this doctor (pending vs admitted)
            $admissions_query = "SELECT 
                    SUM(CASE WHEN pa.admission_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN pa.admission_status = 'admitted' THEN 1 ELSE 0 END) AS admitted_count
                FROM patient_admissions pa
                JOIN patients p ON pa.patient_id = p.id
                WHERE p.doctor_id = :doctor_id";
            $admissions_stmt = $db->prepare($admissions_query);
            $admissions_stmt->bindParam(":doctor_id", $user_id);
            $admissions_stmt->execute();
            $admissions_result = $admissions_stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats[] = [
                'title' => 'My Patients',
                'value' => $patient_result['count'],
                'icon' => 'fa-user-injured',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'Inpatients Pending',
                'value' => isset($admissions_result['pending_count']) ? $admissions_result['pending_count'] : 0,
                'icon' => 'fa-hourglass-half',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Inpatients Admitted',
                'value' => isset($admissions_result['admitted_count']) ? $admissions_result['admitted_count'] : 0,
                'icon' => 'fa-bed',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Pending Reports',
                'value' => '0',
                'icon' => 'fa-file-medical',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Appointments Today',
                'value' => '0',
                'icon' => 'fa-calendar-check',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Total Consultations',
                'value' => '0',
                'icon' => 'fa-stethoscope',
                'color' => 'info'
            ];
            break;
            
        case 'nurse':
            // Get count of patients added by this nurse
            $patient_query = "SELECT COUNT(*) as count FROM patients WHERE added_by = :nurse_id";
            $patient_stmt = $db->prepare($patient_query);
            $patient_stmt->bindParam(":nurse_id", $user_id);
            $patient_stmt->execute();
            $patient_result = $patient_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get count of today's OPD visits
            $visits_query = "SELECT 
                COUNT(*) as total_visits,
                SUM(CASE WHEN visit_status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
                SUM(CASE WHEN visit_status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN visit_type = 'follow_up' THEN 1 ELSE 0 END) as follow_up_count
            FROM opd_visits 
            WHERE DATE(arrival_time) = CURDATE()";
            $visits_stmt = $db->prepare($visits_query);
            $visits_stmt->execute();
            $visits_result = $visits_stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats[] = [
                'title' => 'OPD Waiting',
                'value' => $visits_result['waiting_count'],
                'icon' => 'fa-user-clock',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'OPD Completed',
                'value' => $visits_result['completed_count'],
                'icon' => 'fa-check-circle',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Active Patients',
                'value' => $patient_result['count'],
                'icon' => 'fa-procedures',
                'color' => 'info'
            ];
            $stats[] = [
                'title' => 'Follow-up Visits',
                'value' => $visits_result['follow_up_count'],
                'icon' => 'fa-calendar-check',
                'color' => 'primary'
            ];
            break;
            
        case 'lab_technician':
            $stats[] = [
                'title' => 'Pending Tests',
                'value' => '0',
                'icon' => 'fa-flask',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'Completed Today',
                'value' => '0',
                'icon' => 'fa-vial',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Total Samples',
                'value' => '0',
                'icon' => 'fa-microscope',
                'color' => 'info'
            ];
            $stats[] = [
                'title' => 'Critical Results',
                'value' => '0',
                'icon' => 'fa-exclamation-triangle',
                'color' => 'danger'
            ];
            break;
            
        case 'pharmacist':
            // Get daily sales
            $daily_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                FROM medicine_orders 
                                WHERE status = 'completed' 
                                AND DATE(created_at) = CURDATE()";
            $daily_sales_stmt = $db->prepare($daily_sales_query);
            $daily_sales_stmt->execute();
            $daily_sales = $daily_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get weekly sales
            $weekly_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                 FROM medicine_orders 
                                 WHERE status = 'completed' 
                                 AND YEARWEEK(created_at) = YEARWEEK(CURDATE())";
            $weekly_sales_stmt = $db->prepare($weekly_sales_query);
            $weekly_sales_stmt->execute();
            $weekly_sales = $weekly_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get monthly sales
            $monthly_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                  FROM medicine_orders 
                                  WHERE status = 'completed' 
                                  AND YEAR(created_at) = YEAR(CURDATE()) 
                                  AND MONTH(created_at) = MONTH(CURDATE())";
            $monthly_sales_stmt = $db->prepare($monthly_sales_query);
            $monthly_sales_stmt->execute();
            $monthly_sales = $monthly_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get yearly sales
            $yearly_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                 FROM medicine_orders 
                                 WHERE status = 'completed' 
                                 AND YEAR(created_at) = YEAR(CURDATE())";
            $yearly_sales_stmt = $db->prepare($yearly_sales_query);
            $yearly_sales_stmt->execute();
            $yearly_sales = $yearly_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stats[] = [
                'title' => 'Daily Sales',
                'value' => '₱' . number_format($daily_sales, 2),
                'icon' => 'fa-coins',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Weekly Sales',
                'value' => '₱' . number_format($weekly_sales, 2),
                'icon' => 'fa-chart-line',
                'color' => 'info'
            ];
            $stats[] = [
                'title' => 'Monthly Sales',
                'value' => '₱' . number_format($monthly_sales, 2),
                'icon' => 'fa-calendar-check',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'Yearly Sales',
                'value' => '₱' . number_format($yearly_sales, 2),
                'icon' => 'fa-chart-bar',
                'color' => 'warning'
            ];
            break;
            
        case 'supra_admin':
            // Get daily hospital sales
            $daily_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                FROM medicine_orders 
                                WHERE status = 'completed' 
                                AND DATE(created_at) = CURDATE()";
            $daily_sales_stmt = $db->prepare($daily_sales_query);
            $daily_sales_stmt->execute();
            $daily_sales = $daily_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get weekly hospital sales
            $weekly_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                 FROM medicine_orders 
                                 WHERE status = 'completed' 
                                 AND YEARWEEK(created_at) = YEARWEEK(CURDATE())";
            $weekly_sales_stmt = $db->prepare($weekly_sales_query);
            $weekly_sales_stmt->execute();
            $weekly_sales = $weekly_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get monthly hospital sales
            $monthly_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                  FROM medicine_orders 
                                  WHERE status = 'completed' 
                                  AND YEAR(created_at) = YEAR(CURDATE()) 
                                  AND MONTH(created_at) = MONTH(CURDATE())";
            $monthly_sales_stmt = $db->prepare($monthly_sales_query);
            $monthly_sales_stmt->execute();
            $monthly_sales = $monthly_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get yearly hospital sales
            $yearly_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                                 FROM medicine_orders 
                                 WHERE status = 'completed' 
                                 AND YEAR(created_at) = YEAR(CURDATE())";
            $yearly_sales_stmt = $db->prepare($yearly_sales_query);
            $yearly_sales_stmt->execute();
            $yearly_sales = $yearly_sales_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stats[] = [
                'title' => 'Daily Hospital Sales',
                'value' => '₱' . number_format($daily_sales, 2),
                'icon' => 'fa-coins',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Weekly Hospital Sales',
                'value' => '₱' . number_format($weekly_sales, 2),
                'icon' => 'fa-chart-line',
                'color' => 'info'
            ];
            $stats[] = [
                'title' => 'Monthly Hospital Sales',
                'value' => '₱' . number_format($monthly_sales, 2),
                'icon' => 'fa-calendar-check',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'Yearly Hospital Sales',
                'value' => '₱' . number_format($yearly_sales, 2),
                'icon' => 'fa-chart-bar',
                'color' => 'warning'
            ];
            break;
            
        case 'receptionist':
        case 'medical_staff':
            // Get count of today's OPD visits
            $visits_query = "SELECT 
                COUNT(*) as total_visits,
                SUM(CASE WHEN visit_status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
                SUM(CASE WHEN visit_status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN visit_type = 'follow_up' THEN 1 ELSE 0 END) as follow_up_count
            FROM opd_visits 
            WHERE DATE(arrival_time) = CURDATE()";
            $visits_stmt = $db->prepare($visits_query);
            $visits_stmt->execute();
            $visits_result = $visits_stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats[] = [
                'title' => 'Total OPD Visits Today',
                'value' => $visits_result['total_visits'],
                'icon' => 'fa-users',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'Waiting Patients',
                'value' => $visits_result['waiting_count'],
                'icon' => 'fa-clock',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Completed Visits',
                'value' => $visits_result['completed_count'],
                'icon' => 'fa-check-circle',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Follow-up Visits',
                'value' => $visits_result['follow_up_count'],
                'icon' => 'fa-calendar-check',
                'color' => 'info'
            ];
            break;
            
        case 'medical_technician':
            // Lab request KPIs (laboratory only)
            $pending_query = "SELECT 
                SUM(CASE WHEN status IN ('pending','in_progress') THEN 1 ELSE 0 END) as total_pending,
                SUM(CASE WHEN status = 'pending' AND payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
                SUM(CASE WHEN status = 'pending' AND payment_status = 'paid' THEN 1 ELSE 0 END) as ready_to_process,
                SUM(CASE WHEN status = 'completed' AND DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END) as completed_today
            FROM lab_requests 
            WHERE test_id IN (SELECT id FROM lab_tests WHERE test_type = 'laboratory')";
            $pending_stmt = $db->prepare($pending_query);
            $pending_stmt->execute();
            $pending_result = $pending_stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats[] = [
                'title' => 'Pending Tests',
                'value' => $pending_result['total_pending'],
                'icon' => 'fa-flask',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Awaiting Payment',
                'value' => $pending_result['unpaid_count'],
                'icon' => 'fa-dollar-sign',
                'color' => 'danger'
            ];
            $stats[] = [
                'title' => 'Ready to Process',
                'value' => $pending_result['ready_to_process'],
                'icon' => 'fa-vial',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Completed Today',
                'value' => $pending_result['completed_today'],
                'icon' => 'fa-check-circle',
                'color' => 'info'
            ];
            break;
            
        case 'radiologist':
            // Radiology KPIs
            $pending_query = "SELECT 
                SUM(CASE WHEN status IN ('pending','in_progress') THEN 1 ELSE 0 END) as total_pending,
                SUM(CASE WHEN status = 'pending' AND payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
                SUM(CASE WHEN status = 'pending' AND payment_status = 'paid' THEN 1 ELSE 0 END) as ready_to_process,
                SUM(CASE WHEN status = 'completed' AND DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END) as completed_today
            FROM lab_requests 
            WHERE test_id IN (SELECT id FROM lab_tests WHERE test_type = 'radiology')";
            $pending_stmt = $db->prepare($pending_query);
            $pending_stmt->execute();
            $pending_result = $pending_stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats[] = [
                'title' => 'Pending Scans',
                'value' => $pending_result['total_pending'],
                'icon' => 'fa-x-ray',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Awaiting Payment',
                'value' => $pending_result['unpaid_count'],
                'icon' => 'fa-dollar-sign',
                'color' => 'danger'
            ];
            $stats[] = [
                'title' => 'Ready to Process',
                'value' => $pending_result['ready_to_process'],
                'icon' => 'fa-radiation',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Completed Today',
                'value' => $pending_result['completed_today'],
                'icon' => 'fa-check-circle',
                'color' => 'info'
            ];
            break;

        case 'finance':
            // Combine LAB, OPD and DISCHARGE (inpatient) billings into dashboard metrics
            // LAB payments
            $lab_query = "SELECT 
                COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as lab_pending,
                COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN amount ELSE 0 END),0) as lab_pending_amount,
                COALESCE(SUM(CASE WHEN payment_status = 'completed' AND DATE(payment_date) = CURDATE() THEN amount ELSE 0 END),0) as lab_collected_today,
                COUNT(CASE WHEN payment_status = 'completed' AND DATE(payment_date) = CURDATE() THEN 1 END) as lab_tx_today
            FROM lab_payments";
            $lab_stmt = $db->prepare($lab_query);
            $lab_stmt->execute();
            $lab = $lab_stmt->fetch(PDO::FETCH_ASSOC);

            // OPD payments (table may not exist on some installs)
            $opd_query = "SELECT 
                COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as opd_pending,
                COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN amount_due ELSE 0 END),0) as opd_pending_amount,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' AND DATE(payment_date) = CURDATE() THEN amount_due ELSE 0 END),0) as opd_collected_today,
                COUNT(CASE WHEN payment_status = 'paid' AND DATE(payment_date) = CURDATE() THEN 1 END) as opd_tx_today
            FROM opd_payments";
            $opd_stmt = $db->prepare($opd_query);
            try { $opd_stmt->execute(); $opd = $opd_stmt->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) { $opd = ['opd_pending'=>0,'opd_pending_amount'=>0,'opd_collected_today'=>0,'opd_tx_today'=>0]; }

            // DISCHARGE billings from admission_billing
            // pending = payment_status = 'unpaid'
            // collected today = total_due where marked paid today (by updated_at) or created today if created and paid at once
            $discharge_pending_query = "SELECT 
                COUNT(*) as ab_pending,
                COALESCE(SUM(total_due),0) as ab_pending_amount
            FROM admission_billing WHERE payment_status = 'unpaid'";
            $ab_pending_stmt = $db->prepare($discharge_pending_query);
            try { $ab_pending_stmt->execute(); $ab_pending = $ab_pending_stmt->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) { $ab_pending = ['ab_pending'=>0,'ab_pending_amount'=>0]; }

            $discharge_today_query = "SELECT 
                COALESCE(SUM(total_due),0) as ab_collected_today,
                COUNT(*) as ab_tx_today
            FROM admission_billing 
            WHERE payment_status = 'paid' AND DATE(updated_at) = CURDATE()";
            $ab_today_stmt = $db->prepare($discharge_today_query);
            try { $ab_today_stmt->execute(); $ab_today = $ab_today_stmt->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) { $ab_today = ['ab_collected_today'=>0,'ab_tx_today'=>0]; }

            $payments_result = [
                'total_pending' => (int)$lab['lab_pending'] + (int)$opd['opd_pending'] + (int)$ab_pending['ab_pending'],
                'pending_amount' => (float)$lab['lab_pending_amount'] + (float)$opd['opd_pending_amount'] + (float)$ab_pending['ab_pending_amount'],
                'today_collected' => (float)$lab['lab_collected_today'] + (float)$opd['opd_collected_today'] + (float)$ab_today['ab_collected_today'],
                'transactions_today' => (int)$lab['lab_tx_today'] + (int)$opd['opd_tx_today'] + (int)$ab_today['ab_tx_today']
            ];
            
            $stats[] = [
                'title' => 'Pending Payments',
                'value' => $payments_result['total_pending'],
                'icon' => 'fa-clock',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Amount Pending',
                'value' => '₱' . number_format($payments_result['pending_amount'], 2),
                'icon' => 'fa-money-bill-wave',
                'color' => 'danger'
            ];
            $stats[] = [
                'title' => 'Collected Today',
                'value' => '₱' . number_format($payments_result['today_collected'], 2),
                'icon' => 'fa-cash-register',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Transactions Today',
                'value' => $payments_result['transactions_today'],
                'icon' => 'fa-receipt',
                'color' => 'info'
            ];
            break;
            
        case 'admin_staff':
        case 'medical_records':
            // Get total patients count for current year and today from OPD visits
            $patients_query = "SELECT 
                COUNT(DISTINCT pr.id) as total_patients,
                COUNT(DISTINCT CASE WHEN YEAR(ov.created_at) = YEAR(CURDATE()) THEN pr.id END) as patients_this_year,
                COUNT(DISTINCT CASE WHEN DATE(ov.created_at) = CURDATE() THEN pr.id END) as patients_today
            FROM patient_records pr
            LEFT JOIN opd_visits ov ON pr.id = ov.patient_record_id
            WHERE ov.visit_status = 'completed'";
            $patients_stmt = $db->prepare($patients_query);
            $patients_stmt->execute();
            $patients_result = $patients_stmt->fetch(PDO::FETCH_ASSOC);

            // Get total hospital sales (match logic in hospital_statistics.php)
            // Components: Lab payments (completed by payment_date), OPD payments (paid by payment_date), Discharge billings (paid by updated_at)
            // Pharmacy sales are not included (no canonical source) to keep parity with hospital_statistics.php
            $lab_sales_stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM lab_payments WHERE payment_status='completed' AND YEAR(payment_date) = YEAR(CURDATE())");
            $lab_sales_stmt->execute();
            $lab_sales = (float)$lab_sales_stmt->fetchColumn();

            // OPD payments table may not exist in some installs
            try {
                $opd_sales_stmt = $db->prepare("SELECT COALESCE(SUM(amount_due),0) FROM opd_payments WHERE payment_status='paid' AND YEAR(payment_date) = YEAR(CURDATE())");
                $opd_sales_stmt->execute();
                $opd_sales = (float)$opd_sales_stmt->fetchColumn();
            } catch (Exception $e) {
                $opd_sales = 0.0;
            }

            $ab_sales_stmt = $db->prepare("SELECT COALESCE(SUM(total_due),0) FROM admission_billing WHERE payment_status='paid' AND YEAR(updated_at) = YEAR(CURDATE())");
            $ab_sales_stmt->execute();
            $discharge_sales = (float)$ab_sales_stmt->fetchColumn();

            $total_sales_all = $lab_sales + $opd_sales + $discharge_sales;

            // Get room occupancy
            $rooms_query = "SELECT 
                COUNT(*) as total_rooms,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_rooms,
                (SELECT COUNT(*) FROM beds WHERE status = 'occupied') as occupied_beds
            FROM rooms";
            $rooms_stmt = $db->prepare($rooms_query);
            $rooms_stmt->execute();
            $rooms_result = $rooms_stmt->fetch(PDO::FETCH_ASSOC);

            try {
                $newborn_stmt = $db->prepare("SELECT COUNT(*) FROM birth_certificates WHERE newborn_patient_record_id IS NOT NULL");
                $newborn_stmt->execute();
                $newborn_count = (int)$newborn_stmt->fetchColumn();
            } catch (Exception $e) {
                $newborn_count = 0;
            }

            try {
                $deceased_stmt = $db->prepare("SELECT COUNT(DISTINCT patient_id) FROM death_declarations");
                $deceased_stmt->execute();
                $deceased_count = (int)$deceased_stmt->fetchColumn();
            } catch (Exception $e) {
                $deceased_count = 0;
            }

            $stats[] = [
                'title' => 'Total Patients (This Year)',
                'value' => $patients_result['patients_this_year'],
                'icon' => 'fa-users',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'New Patients Today',
                'value' => $patients_result['patients_today'],
                'icon' => 'fa-user-plus',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Occupied Beds',
                'value' => $rooms_result['occupied_beds'],
                'icon' => 'fa-bed',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Total Sales (This Year)',
                'value' => '₱' . number_format($total_sales_all, 2),
                'icon' => 'fa-chart-line',
                'color' => 'info'
            ];
            $stats[] = [
                'title' => 'Newborn Patients',
                'value' => $newborn_count,
                'icon' => 'fa-baby',
                'color' => 'danger'
            ];
            $stats[] = [
                'title' => 'Deceased Patients',
                'value' => $deceased_count,
                'icon' => 'fa-skull-crossbones',
                'color' => 'dark'
            ];
            break;
            
        default:
            $stats[] = [
                'title' => 'Tasks Today',
                'value' => '0',
                'icon' => 'fa-tasks',
                'color' => 'primary'
            ];
            $stats[] = [
                'title' => 'Notifications',
                'value' => '0',
                'icon' => 'fa-bell',
                'color' => 'warning'
            ];
            $stats[] = [
                'title' => 'Messages',
                'value' => '0',
                'icon' => 'fa-envelope',
                'color' => 'success'
            ];
            $stats[] = [
                'title' => 'Reports',
                'value' => '0',
                'icon' => 'fa-chart-bar',
                'color' => 'info'
            ];
    }
    
    return $stats;
}

$user_stats = getRoleStats($db, $effective_employee_type, $_SESSION['user_id']);

// Get assigned patients for doctors and recent progress notes
$assigned_patients = [];
$recent_progress_notes = [];
if($_SESSION['employee_type'] === 'doctor') {
    $patient_query = "SELECT * FROM patients WHERE doctor_id = :doctor_id ORDER BY created_at DESC LIMIT 5";
    $patient_stmt = $db->prepare($patient_query);
    $patient_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
    $patient_stmt->execute();
    $assigned_patients = $patient_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent progress notes with patient names
    $progress_query = "SELECT pp.*, p.name as patient_name 
                      FROM patient_progress pp
                      JOIN patients p ON pp.patient_id = p.id
                      WHERE pp.doctor_id = :doctor_id
                      ORDER BY pp.progress_date DESC
                      LIMIT 3";
    $progress_stmt = $db->prepare($progress_query);
    $progress_stmt->bindParam(":doctor_id", $_SESSION['user_id']);
    $progress_stmt->execute();
    $recent_progress_notes = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set page title and any additional CSS/JS
$page_title = 'Dashboard';
$additional_css = '<style>
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
        margin-bottom: 20px;
    }
    .card-header {
        background: white;
        border-radius: 15px 15px 0 0 !important;
        border-bottom: 1px solid #eee;
    }
    .stats-card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
        transition: none;
    }
    .stats-card:hover {
        transform: none;
    }
    .stats-icon {
        font-size: 2rem;
        margin-bottom: 10px;
    }
    .stats-number {
        font-size: 1.5rem;
        font-weight: 600;
        color: #2c3e50;
    }
    .quick-action-btn {
        border: none;
        border-radius: 10px;
        padding: 15px;
        text-align: center;
        transition: none;
        background: white;
        color: #2c3e50;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        position: relative;
    }
    .quick-action-btn:hover {
        transform: none;
        box-shadow: none;
        color: inherit;
    }
    .quick-action-icon {
        font-size: 1.5rem;
        margin-right: 10px;
    }
    .notif-dot {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 10px;
        height: 10px;
        background: #dc3545;
        border-radius: 50%;
    }
    .table {
        margin-bottom: 0;
    }
    .table th {
        border-top: none;
        font-weight: 600;
        color: #2c3e50;
    }
    .badge {
        padding: 8px 12px;
        border-radius: 6px;
    }
    .logout-btn {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        color: white !important;
        padding: 8px 20px;
        border-radius: 8px;
        transition: all 0.3s;
        text-decoration: none;
    }
    .logout-btn:hover {
        background: rgba(255,255,255,0.2);
    }
    .avatar-circle {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
    }
    /* Static sidebar (desktop) */
    .sidebar-static {
        background: #fff;
        border: 1px solid #eee;
        border-radius: 12px;
        padding: 8px 0;
        position: sticky;
        top: 16px;
        max-height: calc(100vh - 32px);
        overflow-y: auto;
    }
    .sidebar-static .list-group-item {
        border: 0;
        border-radius: 0;
        padding: 10px 16px;
    }
    .sidebar-static .list-group-item i { width: 20px; }
</style>';

// Include the header
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php echo $additional_css; ?>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-hospital me-2"></i>
                SmartCare
            </a>
            <button class="navbar-toggler d-none" type="button" aria-controls="mainNav" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <button class="btn btn-outline-light ms-2 d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarNav">
                <i class="fas fa-bars"></i>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center ms-lg-auto gap-2 mt-3 mt-lg-0">
                    <div class="text-white me-lg-3">
                        <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        <div class="small">
                            <i class="fas fa-id-badge me-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $_SESSION['employee_type'])); ?> |
                            <i class="fas fa-hospital me-1"></i>
                            <?php echo htmlspecialchars($user['department']); ?>
                        </div>
                    </div>
                    <?php if (in_array($_SESSION['employee_type'], ['receptionist','medical_staff','medical_records','admin_staff']) || $_SESSION['role'] === 'supra_admin'): ?>
                        <a href="opd_registration.php" class="btn btn-outline-light btn-sm me-lg-2">
                            <i class="fas fa-plus me-1"></i>New Registration
                        </a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar Offcanvas (mobile) -->
    <div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="sidebarNav" aria-labelledby="sidebarNavLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="sidebarNavLabel"><i class="fas fa-bars me-2"></i>Navigation</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-0">
            <div class="list-group list-group-flush">
                <a href="dashboard.php" class="list-group-item list-group-item-action"><i class="fas fa-home me-2"></i>Dashboard</a>
                <?php if (in_array($_SESSION['employee_type'], ['receptionist','medical_staff','medical_records','admin_staff']) || $_SESSION['role'] === 'supra_admin'): ?>
                    <a href="opd_registration.php" class="list-group-item list-group-item-action"><i class="fas fa-user-plus me-2"></i>OPD Registration</a>
                    <a href="opd_queue.php" class="list-group-item list-group-item-action"><i class="fas fa-list me-2"></i>OPD Queue</a>
                    <a href="search_patients.php" class="list-group-item list-group-item-action"><i class="fas fa-search me-2"></i>Search Patients</a>
                <?php endif; ?>
                <?php if (in_array($_SESSION['employee_type'], ['general_doctor','doctor'])): ?>
                    <a href="patient_consultation.php" class="list-group-item list-group-item-action"><i class="fas fa-stethoscope me-2"></i>Consultation</a>
                    <a href="patient_history.php" class="list-group-item list-group-item-action"><i class="fas fa-history me-2"></i>Patient History</a>
                    <a href="doctor_inpatients.php" class="list-group-item list-group-item-action"><i class="fas fa-procedures me-2"></i>Inpatients / Orders</a>
                    <a href="request_laboratory.php" class="list-group-item list-group-item-action"><i class="fas fa-flask me-2"></i>Request Laboratory</a>
                <?php endif; ?>
                <?php if ($_SESSION['employee_type'] === 'nurse'): ?>
                    <a href="nurse_orders.php" class="list-group-item list-group-item-action"><i class="fas fa-clipboard-list me-2"></i>Doctor Orders</a>
                    <a href="room_dashboard.php" class="list-group-item list-group-item-action"><i class="fas fa-door-open me-2"></i>Room Dashboard</a>
                    <a href="assign_room.php" class="list-group-item list-group-item-action"><i class="fas fa-bed me-2"></i>Assign Room</a>
                <?php endif; ?>
                <?php if (in_array($_SESSION['employee_type'], ['medical_technician','radiologist'])): ?>
                    <a href="lab_dashboard.php" class="list-group-item list-group-item-action"><i class="fas fa-flask me-2"></i>Lab / Scan Requests</a>
                    <a href="pending_payments.php" class="list-group-item list-group-item-action"><i class="fas fa-dollar-sign me-2"></i>Pending Payments</a>
                <?php endif; ?>
                <?php if ($_SESSION['employee_type'] === 'pharmacist'): ?>
                    <a href="manage_medicines.php" class="list-group-item list-group-item-action"><i class="fas fa-pills me-2"></i>Manage Medicines</a>
                    <a href="manage_orders.php" class="list-group-item list-group-item-action"><i class="fas fa-clipboard-list me-2"></i>Medicine Orders</a>
                    <a href="search_medicines.php" class="list-group-item list-group-item-action"><i class="fas fa-search me-2"></i>Search Medicines</a>
                <?php endif; ?>
                <?php if ($_SESSION['employee_type'] === 'finance'): ?>
                    <a href="pending_lab_payments.php" class="list-group-item list-group-item-action"><i class="fas fa-clock me-2"></i>Lab Payments</a>
                    <a href="finance_discharges.php" class="list-group-item list-group-item-action"><i class="fas fa-file-invoice-dollar me-2"></i>Discharge Billing</a>
                    <a href="opd_pending_payments.php" class="list-group-item list-group-item-action"><i class="fas fa-receipt me-2"></i>OPD Payments</a>
                    <a href="generate_reports.php" class="list-group-item list-group-item-action"><i class="fas fa-chart-bar me-2"></i>Reports</a>
                <?php endif; ?>
                <?php if ($_SESSION['employee_type'] === 'admin_staff' || $_SESSION['role'] === 'supra_admin'): ?>
                    <a href="manage_rooms_beds.php" class="list-group-item list-group-item-action"><i class="fas fa-door-open me-2"></i>Manage Rooms & Beds</a>
                    <a href="hospital_statistics.php" class="list-group-item list-group-item-action"><i class="fas fa-chart-bar me-2"></i>Hospital Statistics</a>
                <?php endif; ?>
                <a href="logout.php" class="list-group-item list-group-item-action"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container-fluid py-4">
        <div class="row">
            <!-- Static sidebar for >= lg screens -->
            <div class="col-lg-3 col-xl-2 d-none d-lg-block">
                <div class="sidebar-static">
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action"><i class="fas fa-home me-2"></i>Dashboard</a>
                        <?php if (in_array($_SESSION['employee_type'], ['receptionist','medical_staff','medical_records','admin_staff']) || $_SESSION['role'] === 'supra_admin'): ?>
                            <a href="opd_registration.php" class="list-group-item list-group-item-action"><i class="fas fa-user-plus me-2"></i>OPD Registration</a>
                            <a href="opd_queue.php" class="list-group-item list-group-item-action"><i class="fas fa-list me-2"></i>OPD Queue</a>
                            <a href="search_patients.php" class="list-group-item list-group-item-action"><i class="fas fa-search me-2"></i>Search Patients</a>
                        <?php endif; ?>
                        <?php if (in_array($_SESSION['employee_type'], ['general_doctor','doctor'])): ?>
                            <a href="patient_consultation.php" class="list-group-item list-group-item-action"><i class="fas fa-stethoscope me-2"></i>Consultation</a>
                            <a href="patient_history.php" class="list-group-item list-group-item-action"><i class="fas fa-history me-2"></i>Patient History</a>
                            <a href="doctor_inpatients.php" class="list-group-item list-group-item-action"><i class="fas fa-procedures me-2"></i>Inpatients / Orders</a>
                            <a href="request_laboratory.php" class="list-group-item list-group-item-action"><i class="fas fa-flask me-2"></i>Request Laboratory</a>
                        <?php endif; ?>
                        <?php if ($_SESSION['employee_type'] === 'nurse'): ?>
                            <a href="nurse_orders.php" class="list-group-item list-group-item-action"><i class="fas fa-clipboard-list me-2"></i>Doctor Orders</a>
                            <a href="room_dashboard.php" class="list-group-item list-group-item-action"><i class="fas fa-door-open me-2"></i>Room Dashboard</a>
                            <a href="assign_room.php" class="list-group-item list-group-item-action"><i class="fas fa-bed me-2"></i>Assign Room</a>
                        <?php endif; ?>
                        <?php if (in_array($_SESSION['employee_type'], ['medical_technician','radiologist'])): ?>
                            <a href="lab_dashboard.php" class="list-group-item list-group-item-action"><i class="fas fa-flask me-2"></i>Lab / Scan Requests</a>
                            <a href="pending_payments.php" class="list-group-item list-group-item-action"><i class="fas fa-dollar-sign me-2"></i>Pending Payments</a>
                        <?php endif; ?>
                        <?php if ($_SESSION['employee_type'] === 'pharmacist'): ?>
                            <a href="manage_medicines.php" class="list-group-item list-group-item-action"><i class="fas fa-pills me-2"></i>Manage Medicines</a>
                            <a href="manage_orders.php" class="list-group-item list-group-item-action"><i class="fas fa-clipboard-list me-2"></i>Medicine Orders</a>
                            <a href="search_medicines.php" class="list-group-item list-group-item-action"><i class="fas fa-search me-2"></i>Search Medicines</a>
                        <?php endif; ?>
                        <?php if ($_SESSION['employee_type'] === 'finance'): ?>
                            <a href="pending_lab_payments.php" class="list-group-item list-group-item-action"><i class="fas fa-clock me-2"></i>Lab Payments</a>
                            <a href="finance_discharges.php" class="list-group-item list-group-item-action"><i class="fas fa-file-invoice-dollar me-2"></i>Discharge Billing</a>
                            <a href="opd_pending_payments.php" class="list-group-item list-group-item-action"><i class="fas fa-receipt me-2"></i>OPD Payments</a>
                            <a href="generate_reports.php" class="list-group-item list-group-item-action"><i class="fas fa-chart-bar me-2"></i>Reports</a>
                        <?php endif; ?>
                        <?php if ($_SESSION['employee_type'] === 'admin_staff' || $_SESSION['role'] === 'supra_admin'): ?>
                            <a href="manage_rooms_beds.php" class="list-group-item list-group-item-action"><i class="fas fa-door-open me-2"></i>Manage Rooms & Beds</a>
                            <a href="hospital_statistics.php" class="list-group-item list-group-item-action"><i class="fas fa-chart-bar me-2"></i>Hospital Statistics</a>
                        <?php endif; ?>
                        <a href="logout.php" class="list-group-item list-group-item-action"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-9 col-xl-10">
                <div class="container-fluid px-3 px-lg-4">
        <!-- User Info Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="avatar-circle">
                            <?php if (isset($user['profile_picture']) && !empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php
                                // Show different icons based on employee type
                                $icon_class = 'fa-user-circle';
                                switch($user['employee_type']) {
                                    case 'doctor':
                                        $icon_class = 'fa-user-md';
                                        break;
                                    case 'nurse':
                                        $icon_class = 'fa-user-nurse';
                                        break;
                                    case 'pharmacist':
                                        $icon_class = 'fa-pills';
                                        break;
                                    case 'lab_technician':
                                        $icon_class = 'fa-flask';
                                        break;
                                    default:
                                        $icon_class = 'fa-user-circle';
                                }
                                ?>
                                <i class="fas <?php echo $icon_class; ?>"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col">
                        <h5 class="mb-1"><?php echo htmlspecialchars($user['name']); ?></h5>
                        <div class="text-muted">
                            <span class="badge bg-info me-2">
                                <?php echo ucfirst(str_replace('_', ' ', $user['employee_type'])); ?>
                            </span>
                            <span class="badge bg-secondary">
                                <?php echo htmlspecialchars($user['department']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-auto">
                        <a href="edit_profile.php" class="btn btn-light btn-sm me-2">
                            <i class="fas fa-edit me-1"></i>Edit Profile
                        </a>
                        <button class="btn btn-light btn-sm" id="shareProfileBtn">
                            <i class="fas fa-share-alt me-1"></i>Share Profile
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Share Profile Modal -->
        <div class="modal fade" id="shareProfileModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Share Profile</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Generate a link to share your profile with others.
                        </p>
                        <div class="input-group mb-3" id="linkGroup" style="display: none;">
                            <input type="text" class="form-control" id="shareLink" readonly>
                            <button class="btn btn-danger" id="copyButton">
                                <i class="fas fa-copy me-2"></i>Copy
                            </button>
                        </div>
                        <div id="copyMessage" class="text-success" style="display: none;">
                            <i class="fas fa-check me-2"></i>Link copied to clipboard!
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-danger" id="generateLink">Generate Link</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Logout Confirm Modal -->
        <div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="logoutConfirmLabel">Confirm Logout</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <i class="fas fa-sign-out-alt me-2"></i>Are you sure you want to log out?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <a href="logout.php" class="btn btn-danger" id="confirmLogoutBtn"><i class="fas fa-sign-out-alt me-2"></i>Log out</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="row">
            <?php foreach($user_stats as $stat): ?>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon text-<?php echo $stat['color']; ?>">
                            <i class="fas <?php echo $stat['icon']; ?>"></i>
                        </div>
                        <div class="stats-number"><?php echo $stat['value']; ?></div>
                        <div class="text-muted"><?php echo $stat['title']; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Actions</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php if($show_general_doctor_dashboard): ?>
                        <div class="col-md-3">
                            <a href="opd_queue.php" class="quick-action-btn">
                                <i class="fas fa-list quick-action-icon"></i>
                                <?php if (!empty($opd_waiting_count_today)): ?><span class="notif-dot" title="<?php echo $opd_waiting_count_today; ?> waiting"></span><?php endif; ?>
                                OPD Queue
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="patient_consultation.php" class="quick-action-btn">
                                <i class="fas fa-stethoscope quick-action-icon"></i>
                                Current Consultation
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="patient_history.php" class="quick-action-btn">
                                <i class="fas fa-history quick-action-icon"></i>
                                Patient History
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="request_laboratory.php" class="quick-action-btn">
                                <i class="fas fa-flask quick-action-icon"></i>
                                Request Laboratory
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="doctor_inpatients.php" class="quick-action-btn">
                                <i class="fas fa-procedures quick-action-icon"></i>
                                Inpatients / Orders
                            </a>
                        </div>
                    <?php elseif($show_standard_doctor_dashboard): ?>
                        <?php if (!$is_specialist_doctor): ?>
                        <div class="col-md-3">
                            <a href="add_patient.php" class="quick-action-btn">
                                <i class="fas fa-user-plus quick-action-icon"></i>
                                Add Patient
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3">
                            <a href="opd_queue.php" class="quick-action-btn">
                                <i class="fas fa-list quick-action-icon"></i>
                                <?php if (!empty($opd_waiting_count_today)): ?><span class="notif-dot" title="<?php echo $opd_waiting_count_today; ?> waiting"></span><?php endif; ?>
                                OPD Queue
                            </a>
                        </div>
                        <?php if (!$is_specialist_doctor): ?>
                        <div class="col-md-3">
                            <a href="request_medicines.php" class="quick-action-btn">
                                <i class="fas fa-prescription-bottle quick-action-icon"></i>
                                Request Medicines
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="#" class="quick-action-btn">
                                <i class="fas fa-calendar-alt quick-action-icon"></i>
                                View Appointments
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3">
                            <a href="#" class="quick-action-btn">
                                <i class="fas fa-file-medical quick-action-icon"></i>
                                Medical Reports
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="doctor_inpatients.php" class="quick-action-btn">
                                <i class="fas fa-procedures quick-action-icon"></i>
                                Inpatients / Orders
                            </a>
                        </div>
                    <?php elseif($_SESSION['employee_type'] === 'nurse'): ?>
                        <div class="col-md-3">
                            <a href="nurse_orders.php" class="quick-action-btn">
                                <i class="fas fa-notes-medical quick-action-icon"></i>
                                View Doctor Orders
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="assign_room.php" class="quick-action-btn">
                                <i class="fas fa-bed quick-action-icon"></i>
                                Assign Room
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="room_dashboard.php" class="quick-action-btn">
                                <i class="fas fa-door-open quick-action-icon"></i>
                                Room Dashboard
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="nurse_birth_certificate.php" class="quick-action-btn">
                                <i class="fas fa-baby quick-action-icon"></i>
                                Birth Certificate
                            </a>
                        </div>
                    <?php elseif($_SESSION['employee_type'] === 'medical_technician'): ?>
                        <div class="col-md-3">
                            <a href="lab_dashboard.php" class="quick-action-btn">
                                <i class="fas fa-flask quick-action-icon"></i>
                                View Test Requests
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="pending_payments.php" class="quick-action-btn">
                                <i class="fas fa-dollar-sign quick-action-icon"></i>
                                Payment Status
                            </a>
                        </div>
                        
                    <?php elseif($_SESSION['employee_type'] === 'radiologist'): ?>
                        <div class="col-md-3">
                            <a href="lab_dashboard.php" class="quick-action-btn">
                                <i class="fas fa-x-ray quick-action-icon"></i>
                                View Scan Requests
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="pending_payments.php" class="quick-action-btn">
                                <i class="fas fa-dollar-sign quick-action-icon"></i>
                                Payment Status
                            </a>
                        </div>
                        
                    <?php elseif($_SESSION['employee_type'] === 'pharmacist'): ?>
                        <div class="col-md-3">
                            <a href="manage_medicines.php" class="quick-action-btn">
                                <i class="fas fa-pills quick-action-icon"></i>
                                Manage Medicines
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="manage_orders.php" class="quick-action-btn">
                                <i class="fas fa-clipboard-list quick-action-icon"></i>
                                Manage Medicine Orders
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="search_medicines.php" class="quick-action-btn">
                                <i class="fas fa-search quick-action-icon"></i>
                                Search Medicines
                            </a>
                        </div>
                    <?php elseif($_SESSION['employee_type'] === 'finance'): ?>
                        <div class="col-md-3">
                            <a href="pending_lab_payments.php" class="quick-action-btn">
                                <i class="fas fa-clock quick-action-icon"></i>
                                Pending Lab Payments
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="generate_reports.php" class="quick-action-btn">
                                <i class="fas fa-chart-bar quick-action-icon"></i>
                                Financial Reports
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="finance_discharges.php" class="quick-action-btn">
                                <i class="fas fa-file-invoice-dollar quick-action-icon"></i>
                                Discharge Billing
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="opd_pending_payments.php" class="quick-action-btn">
                                <i class="fas fa-receipt quick-action-icon"></i>
                                OPD Pending Payments
                            </a>
                        </div>
                    <?php elseif($_SESSION['employee_type'] === 'receptionist' || $_SESSION['employee_type'] === 'medical_staff' || $_SESSION['employee_type'] === 'admin_staff' || $_SESSION['role'] === 'supra_admin'): ?>
                        <div class="col-md-3">
                            <a href="opd_registration.php" class="quick-action-btn">
                                <i class="fas fa-user-plus quick-action-icon"></i>
                                New OPD Registration
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="opd_queue.php" class="quick-action-btn">
                                <i class="fas fa-list quick-action-icon"></i>
                                <?php if (!empty($opd_waiting_count_today)): ?><span class="notif-dot" title="<?php echo $opd_waiting_count_today; ?> waiting"></span><?php endif; ?>
                                View OPD Queue
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="search_patients.php" class="quick-action-btn">
                                <i class="fas fa-search quick-action-icon"></i>
                                Search Patient Records
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="room_dashboard.php" class="quick-action-btn">
                                <i class="fas fa-door-open quick-action-icon"></i>
                                Room Status
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if($_SESSION['role'] === 'supra_admin'): ?>
                        <div class="col-md-3">
                            <a href="manage_rooms_beds.php" class="quick-action-btn">
                                <i class="fas fa-door-open quick-action-icon"></i>
                                Manage Rooms & Beds
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if($_SESSION['employee_type'] === 'admin_staff'): ?>
                        <div class="col-md-3">
                            <a href="manage_rooms_beds.php" class="quick-action-btn">
                                <i class="fas fa-door-open quick-action-icon"></i>
                                Manage Rooms & Beds
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="hospital_statistics.php" class="quick-action-btn">
                                <i class="fas fa-chart-bar quick-action-icon"></i>
                                Hospital Statistics
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="portal_account_reset.php" class="quick-action-btn">
                                <i class="fas fa-user-shield quick-action-icon"></i>
                                Patient Portal Account Reset
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if($_SESSION['employee_type'] === 'medical_records'): ?>
                        <div class="col-md-3">
                            <a href="opd_registration.php" class="quick-action-btn">
                                <i class="fas fa-user-plus quick-action-icon"></i>
                                New OPD Registration
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="opd_queue.php" class="quick-action-btn">
                                <i class="fas fa-list quick-action-icon"></i>
                                <?php if (!empty($opd_waiting_count_today)): ?><span class="notif-dot" title="<?php echo $opd_waiting_count_today; ?> waiting"></span><?php endif; ?>
                                View OPD Queue
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="search_patients.php" class="quick-action-btn">
                                <i class="fas fa-search quick-action-icon"></i>
                                Search Patient Records
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="patient_history.php" class="quick-action-btn">
                                <i class="fas fa-history quick-action-icon"></i>
                                Patient History
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="manage_records.php" class="quick-action-btn">
                                <i class="fas fa-folder-open quick-action-icon"></i>
                                Manage Records
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="medical_records_birth_certificates.php" class="quick-action-btn">
                                <i class="fas fa-file-signature quick-action-icon"></i>
                                Review Birth Certificates
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if($show_standard_doctor_dashboard): ?>
            <!-- Recent Patients -->
            <div class="card">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-user-injured me-2"></i>My Patients</h5>
                    <a href="add_patient.php" class="btn btn-danger">
                        <i class="fas fa-plus me-1"></i>Add New Patient
                    </a>
                </div>
                <div class="card-body">
                    <!-- Search Input -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-search"></i>
                                </span>
                                <input type="text" class="form-control" id="patientSearch" placeholder="Search patients...">
                                <button class="btn btn-outline-secondary" type="button" id="clearPatientSearch" style="display: none;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Age</th>
                                    <th>Gender</th>
                                    <th>Blood Group</th>
                                    <th>Phone</th>
                                    <th>Added Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="patientsTableBody">
                                <?php foreach($assigned_patients as $patient): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($patient['name']); ?></td>
                                        <td><?php echo htmlspecialchars($patient['age']); ?></td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo ucfirst(htmlspecialchars($patient['gender'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($patient['blood_group']); ?></td>
                                        <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($patient['created_at'])); ?></td>
                                        <td>
                                            <a href="view_patient.php?id=<?php echo $patient['id']; ?>" class="btn btn-danger btn-sm" title="View Patient Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_patient.php?id=<?php echo $patient['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Add this JavaScript code before the closing body tag -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Navbar toggler manual control to improve reliability on some hosts
        (function(){
            var nav = document.getElementById('mainNav');
            var toggler = document.querySelector('.navbar-toggler');
            if (nav && toggler && window.bootstrap && bootstrap.Collapse) {
                toggler.addEventListener('click', function(){
                    var instance = bootstrap.Collapse.getOrCreateInstance(nav);
                    instance.toggle();
                });
                // Hide after clicking any link in collapse on small screens
                nav.querySelectorAll('a').forEach(function(a){
                    a.addEventListener('click', function(){
                        if (window.innerWidth < 992) {
                            var inst = bootstrap.Collapse.getOrCreateInstance(nav);
                            inst.hide();
                        }
                    });
                });
            }
        })();
        // Initialize Bootstrap components
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Logout confirmation
        (function() {
            var modalEl = document.getElementById('logoutConfirmModal');
            var modal = (modalEl && window.bootstrap) ? new bootstrap.Modal(modalEl) : null;
            var confirmBtn = document.getElementById('confirmLogoutBtn');
            // Intercept all logout links EXCEPT the confirm button inside the modal
            var logoutLinks = Array.prototype.slice.call(document.querySelectorAll('a[href=\"logout.php\"]'))
                .filter(function(link){ return !(link && link.id === 'confirmLogoutBtn'); });
            logoutLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (modal) {
                        modal.show();
                    } else {
                        if (window.confirm('Are you sure you want to log out?')) {
                            window.location.href = 'logout.php';
                        }
                    }
                });
            });
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function(e){
                    // In case any global handlers try to block anchors, force navigation here
                    e.preventDefault();
                    window.location.href = 'logout.php';
                });
            }
        })();

        // Share Profile Modal functionality
        const shareProfileModal = document.getElementById('shareProfileModal');
        const shareButton = document.getElementById('shareProfileBtn');
        const generateLink = document.getElementById('generateLink');
        const linkGroup = document.getElementById('linkGroup');
        const shareLink = document.getElementById('shareLink');
        const copyButton = document.getElementById('copyButton');
        const copyMessage = document.getElementById('copyMessage');
        
        // Initialize the modal
        const shareModal = new bootstrap.Modal(shareProfileModal);

        // Share button click handler
        if (shareButton) {
            shareButton.addEventListener('click', function(e) {
                e.preventDefault();
                shareModal.show();
            });
        }

        // Generate link button click handler
        if (generateLink) {
            generateLink.addEventListener('click', function() {
                // Show loading state
                generateLink.disabled = true;
                generateLink.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
                
                // Generate share token
                fetch('generate_user_share_token.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        shareLink.value = window.location.href.substring(0, window.location.href.lastIndexOf('/')) + '/public_user_view.php?token=' + data.token;
                        linkGroup.style.display = 'flex';
                        generateLink.style.display = 'none';
                    } else {
                        throw new Error(data.error || 'Failed to generate link');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to generate share link. Please try again.');
                    // Reset button state
                    generateLink.disabled = false;
                    generateLink.innerHTML = 'Generate Link';
                });
            });
        }

        // Copy button click handler
        if (copyButton) {
            copyButton.addEventListener('click', function() {
                shareLink.select();
                document.execCommand('copy');
                copyMessage.style.display = 'block';
                
                // Show success state on button
                copyButton.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
                copyButton.classList.remove('btn-outline-secondary');
                copyButton.classList.add('btn-success');
                
                setTimeout(() => {
                    copyMessage.style.display = 'none';
                    copyButton.innerHTML = '<i class="fas fa-copy me-1"></i>Copy';
                    copyButton.classList.remove('btn-success');
                    copyButton.classList.add('btn-outline-secondary');
                }, 2000);
            });
        }

        // Reset modal when closed
        if (shareProfileModal) {
            shareProfileModal.addEventListener('hidden.bs.modal', function () {
                generateLink.style.display = 'block';
                generateLink.disabled = false;
                generateLink.innerHTML = 'Generate Link';
                linkGroup.style.display = 'none';
                copyMessage.style.display = 'none';
                copyButton.innerHTML = '<i class="fas fa-copy me-1"></i>Copy';
                copyButton.classList.remove('btn-success');
                copyButton.classList.add('btn-outline-secondary');
            });
        }

        // Temporary notice for radiology buttons
        const btnProcessScans = document.getElementById('btnProcessScans');
        const btnUploadResults = document.getElementById('btnUploadResults');
        function showTempNotice(e) {
            e.preventDefault();
            alert('Dipa po sya gawa sorry, naka kabit po sya sa "View Scan Requests" Temporary');
            window.location.href = 'lab_dashboard.php';
        }
        if (btnProcessScans) btnProcessScans.addEventListener('click', showTempNotice);
        if (btnUploadResults) btnUploadResults.addEventListener('click', showTempNotice);

        // Patient search functionality
        const patientSearch = document.getElementById('patientSearch');
        const clearPatientSearch = document.getElementById('clearPatientSearch');
        const patientsTableBody = document.getElementById('patientsTableBody');
        
        if (patientsTableBody) {
            const originalTableContent = patientsTableBody.innerHTML;
            let searchTimeout;

            // Function to perform the search
            function performSearch() {
                const searchTerm = patientSearch.value.trim();
                clearPatientSearch.style.display = searchTerm ? 'block' : 'none';

                // Clear the previous timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }

                // Set a new timeout
                searchTimeout = setTimeout(() => {
                    if (searchTerm === '') {
                        // If search is empty, restore the original content
                        patientsTableBody.innerHTML = originalTableContent;
                    } else {
                        // Perform the search
                        fetch(`search_patients.php?search=${encodeURIComponent(searchTerm)}`)
                            .then(response => response.text())
                            .then(html => {
                                patientsTableBody.innerHTML = html;
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                // In case of error, restore original content
                                patientsTableBody.innerHTML = originalTableContent;
                            });
                    }
                }, 300); // 300ms delay
            }

            // Search input event listener
            if (patientSearch) {
                patientSearch.addEventListener('input', performSearch);
            }

            // Clear search button event listener
            if (clearPatientSearch) {
                clearPatientSearch.addEventListener('click', () => {
                    patientSearch.value = '';
                    performSearch();
                    clearPatientSearch.style.display = 'none';
                });
            }
        }
    });
    </script>
</body>
</html> 
<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['admission_id'])) {
    header("Location: room_dashboard.php");
    exit();
}
$admission_id = intval($_GET['admission_id']);

$database = new Database();
$db = $database->getConnection();

// Fetch admission, patient, room, bed, doctor, and elapsed seconds from DB time
$admission_query = "SELECT pa.*, p.name as patient_name, p.age, p.gender,
                           p.doctor_id AS patient_doctor_id,
                           r.room_number, b.bed_number,
                           u.name as admitting_doctor_name,
                           COALESCE(pa.admission_date, pa.updated_at, pa.created_at) as admitted_at,
                           TIMESTAMPDIFF(SECOND, COALESCE(pa.admission_date, pa.updated_at, pa.created_at), NOW()) as seconds_elapsed
                    FROM patient_admissions pa
                    JOIN patients p ON pa.patient_id = p.id
                    LEFT JOIN rooms r ON pa.room_id = r.id
                    LEFT JOIN beds b ON pa.bed_id = b.id
                    LEFT JOIN users u ON pa.admitted_by = u.id
                    WHERE pa.id = :admission_id";
$adm_stmt = $db->prepare($admission_query);
$adm_stmt->bindParam(":admission_id", $admission_id);
$adm_stmt->execute();
$admission = $adm_stmt->fetch(PDO::FETCH_ASSOC);

if (!$admission) {
    header("Location: room_dashboard.php");
    exit();
}

// Doctor ownership notice: if logged-in user is a doctor, ensure the patient is theirs
$ownership_notice = null;
if (isset($_SESSION['employee_type']) && $_SESSION['employee_type'] === 'doctor') {
    $own_q = "SELECT 1 FROM patients WHERE id = :pid AND doctor_id = :doc LIMIT 1";
    $own_stmt = $db->prepare($own_q);
    $own_stmt->bindParam(":pid", $admission['patient_id']);
    $own_stmt->bindParam(":doc", $_SESSION['user_id']);
    $own_stmt->execute();
    if (!$own_stmt->fetch(PDO::FETCH_ASSOC)) {
        $ownership_notice = "This is not your patient. Access is read-only.";
    }
}

// Handle death declaration by assigned doctor
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'declare_death') {
    try {
        $txStarted = false;
        // Only assigned doctor or general_doctor with ownership can declare
        $isDoctor = isset($_SESSION['employee_type']) && in_array($_SESSION['employee_type'], ['doctor','general_doctor'], true);
        if (!$isDoctor || intval($admission['patient_doctor_id']) !== intval($_SESSION['user_id'])) {
            throw new Exception('Unauthorized action.');
        }
        // Admission must be active
        if (!isset($admission['admission_status']) || $admission['admission_status'] !== 'admitted') {
            throw new Exception('Only admitted patients can be declared deceased.');
        }
        // Validate inputs
        $time_of_death = isset($_POST['time_of_death']) && $_POST['time_of_death'] !== '' ? $_POST['time_of_death'] : date('Y-m-d H:i:s');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $time_of_death)) {
            throw new Exception('Invalid time of death format. Use YYYY-MM-DD HH:MM[:SS].');
        }
        $cause_of_death = isset($_POST['cause_of_death']) ? trim($_POST['cause_of_death']) : '';
        if ($cause_of_death === '') {
            throw new Exception('Cause of death is required.');
        }
        $notes = isset($_POST['death_notes']) ? trim($_POST['death_notes']) : '';

        // Ensure table exists (DDL may auto-commit; do this BEFORE starting a transaction)
        $db->exec("CREATE TABLE IF NOT EXISTS death_declarations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admission_id INT NOT NULL,
            patient_id INT NOT NULL,
            declared_by INT NOT NULL,
            declared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            time_of_death DATETIME NOT NULL,
            cause_of_death TEXT NOT NULL,
            notes TEXT NULL,
            status ENUM('pending','reviewed') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_dd_adm FOREIGN KEY (admission_id) REFERENCES patient_admissions(id) ON DELETE CASCADE,
            CONSTRAINT fk_dd_pat FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            CONSTRAINT fk_dd_user FOREIGN KEY (declared_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Now start a transaction for the data changes
        $db->beginTransaction();
        $txStarted = true;
        // Insert declaration
        $ins = $db->prepare("INSERT INTO death_declarations (admission_id, patient_id, declared_by, time_of_death, cause_of_death, notes)
                             VALUES (:admission_id, :patient_id, :declared_by, :tod, :cod, :notes)");
        $ins->execute([
            ':admission_id' => $admission_id,
            ':patient_id' => $admission['patient_id'],
            ':declared_by' => $_SESSION['user_id'],
            ':tod' => $time_of_death,
            ':cod' => $cause_of_death,
            ':notes' => $notes
        ]);
        // Mark admission as discharged at time of death (if not already)
        $upd = $db->prepare("UPDATE patient_admissions 
                             SET admission_status = 'discharged', actual_discharge_date = :tod 
                             WHERE id = :aid AND admission_status <> 'discharged'");
        $upd->execute([':tod' => $time_of_death, ':aid' => $admission_id]);

        // Free up the bed if assigned: set bed -> available and detach from admission
        $bedStmt = $db->prepare("SELECT bed_id FROM patient_admissions WHERE id = :aid LIMIT 1 FOR UPDATE");
        $bedStmt->execute([':aid' => $admission_id]);
        $bedId = (int)$bedStmt->fetchColumn();
        if ($bedId > 0) {
            // Mark bed as available
            $free = $db->prepare("UPDATE beds SET status = 'available' WHERE id = :bid");
            $free->execute([':bid' => $bedId]);
            // Detach bed and room from this (now discharged) admission
            $detach = $db->prepare("UPDATE patient_admissions SET bed_id = NULL, room_id = NULL WHERE id = :aid");
            $detach->execute([':aid' => $admission_id]);
        }
        $db->commit();
        // Redirect to manage records for medical_records
        header("Location: manage_records.php?success=1");
        exit();
    } catch (Exception $e) {
        if (isset($txStarted) && $txStarted && $db->inTransaction()) { $db->rollBack(); }
        $error_message = $e->getMessage();
    }
}

// Human-friendly length of stay
function humanizeDuration($seconds) {
    if ($seconds < 0) $seconds = 0;
    if ($seconds < 60) return 'Just now';
    $minutes = floor($seconds / 60);
    if ($minutes < 60) return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
    $hours = floor($minutes / 60);
    $remMin = $minutes % 60;
    if ($hours < 24) {
        $parts = $hours . ' hour' . ($hours === 1 ? '' : 's');
        if ($remMin > 0) $parts .= ' ' . $remMin . ' min' . ($remMin === 1 ? '' : 's');
        return $parts . ' ago';
    }
    $days = floor($hours / 24);
    $remHr = $hours % 24;
    $parts = $days . ' day' . ($days === 1 ? '' : 's');
    if ($remHr > 0) $parts .= ' ' . $remHr . ' hr' . ($remHr === 1 ? '' : 's');
    return $parts . ' ago';
}
$length_of_stay_human = humanizeDuration(intval($admission['seconds_elapsed']));

// Completed orders (ascending)
$orders_query = "SELECT do.*, u.name as completed_by_name
                 FROM doctors_orders do
                 LEFT JOIN users u ON do.completed_by = u.id
                 WHERE do.admission_id = :admission_id AND do.status = 'completed'
                 ORDER BY do.completed_at ASC";
$orders_stmt = $db->prepare($orders_query);
$orders_stmt->bindParam(":admission_id", $admission_id);
$orders_stmt->execute();
$completed_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Nursing notes (ascending by created_at)
$notes_query = "SELECT nn.*, u.name as nurse_name
                FROM nursing_notes nn
                JOIN users u ON nn.created_by = u.id
                WHERE nn.admission_id = :admission_id
                ORDER BY nn.created_at ASC";
$notes_stmt = $db->prepare($notes_query);
$notes_stmt->bindParam(":admission_id", $admission_id);
$notes_stmt->execute();
$nursing_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Admission Profile';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admission Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .section-card { border: none; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .section-header { background: #fff; border-bottom: 1px solid #eee; }
        .timeline { border-left: 3px solid #e9ecef; padding-left: 15px; }
        .timeline-item { position: relative; margin-bottom: 16px; }
        .timeline-item::before { content: ''; position: absolute; left: -10px; top: 6px; width: 8px; height: 8px; background: #dc3545; border-radius: 50%; }
        .stat { background:#f8f9fa; border-radius: 8px; padding:12px; }
        .badge-soft { background:#eef2f7; color:#495057; }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-user-injured me-2"></i>Admission Profile</h3>
        <div class="d-flex gap-2">
            <?php if (isset($_SESSION['employee_type']) && in_array($_SESSION['employee_type'], ['doctor','general_doctor']) && intval($admission['patient_doctor_id']) === intval($_SESSION['user_id'])): ?>
                <a href="doctor_orders.php?admission_id=<?php echo $admission_id; ?>" class="btn btn-danger btn-sm">
                    <i class="fas fa-plus me-1"></i>New Order
                </a>
                <?php if (isset($admission['admission_status']) && $admission['admission_status'] === 'admitted'): ?>
                <button type="button" class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#declareDeathModal">
                    <i class="fas fa-skull-crossbones me-1"></i>Declare Death
                </button>
                <?php endif; ?>
            <?php endif; ?>
            <a href="room_dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if ($ownership_notice): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($ownership_notice); ?></div>
        <a href="dashboard.php" class="btn btn-light btn-sm mb-3"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
    <?php else: ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card section-card">
                <div class="card-header section-header"><h5 class="mb-0">Patient</h5></div>
                <div class="card-body">
                    <div class="mb-2"><strong><?php echo htmlspecialchars($admission['patient_name']); ?></strong></div>
                    <div class="text-muted small mb-2"><?php echo intval($admission['age']); ?>y / <?php echo ucfirst($admission['gender']); ?></div>
                    <div class="mb-2"><i class="fas fa-door-open me-2"></i>Room <?php echo htmlspecialchars($admission['room_number']); ?>, Bed <?php echo htmlspecialchars($admission['bed_number']); ?></div>
                    <div class="mb-2"><i class="fas fa-user-md me-2"></i>Admitting Doctor: <?php echo htmlspecialchars($admission['admitting_doctor_name']); ?></div>
                    <?php if (isset($_SESSION['employee_type']) && in_array($_SESSION['employee_type'], ['doctor','general_doctor'])): ?>
                        <div class="small text-muted">Assigned Doctor ID: <?php echo (int)$admission['patient_doctor_id']; ?> • You: <?php echo (int)$_SESSION['user_id']; ?></div>
                    <?php endif; ?>
                    <div class="row g-2 mt-3">
                        <div class="col-6">
                            <div class="stat">
                                <div class="text-muted small">Admitted</div>
                                <div><?php echo date('M d, Y h:i A', strtotime($admission['admitted_at'])); ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat">
                                <div class="text-muted small">Length of Stay</div>
                                <div><?php echo htmlspecialchars($length_of_stay_human); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card section-card mb-3">
                <div class="card-header section-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Completed Doctor Orders</h5>
                    <span class="badge badge-soft">Ascending</span>
                </div>
                <div class="card-body">
                    <?php if (empty($completed_orders)): ?>
                        <p class="text-muted mb-0">No completed orders yet.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($completed_orders as $o): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <div class="mb-1">
                                                <span class="badge bg-secondary me-2"><?php echo ucfirst(str_replace('_',' ',$o['order_type'])); ?></span>
                                                <strong><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$o['order_type']))); ?></strong>
                                            </div>
                                            <div class="text-muted small">Frequency</div>
                                            <div class="mb-1"><?php echo htmlspecialchars($o['frequency'] ?: '-'); ?></div>
                                            <div class="text-muted small">Order Details</div>
                                            <div class="mb-1"><?php echo nl2br(htmlspecialchars($o['order_details'] ?: '-')); ?></div>
                                            <div class="text-muted small">Duration</div>
                                            <div class="mb-1"><?php echo htmlspecialchars($o['duration'] ?: '-'); ?></div>
                                            <div class="text-muted small">Special Instructions</div>
                                            <div class="mb-1"><?php echo nl2br(htmlspecialchars($o['special_instructions'] ?: '-')); ?></div>
                                            <?php if (!empty($o['completion_note'])): ?>
                                                <div class="text-muted small mt-1">Completion Note: <?php echo htmlspecialchars($o['completion_note']); ?></div>
                                            <?php endif; ?>
                                            <div class="text-muted small mt-1">By: <?php echo htmlspecialchars($o['completed_by_name']); ?></div>
                                        </div>
                                        <div class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($o['completed_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card section-card">
                <div class="card-header section-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-notes-medical me-2"></i>Nursing Notes</h5>
                    <span class="badge badge-soft">Ascending</span>
                </div>
                <div class="card-body">
                    <?php if (empty($nursing_notes)): ?>
                        <p class="text-muted mb-0">No nursing notes recorded.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($nursing_notes as $n): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong class="me-2"><?php echo ucfirst($n['note_type']); ?></strong>
                                            <?php echo nl2br(htmlspecialchars($n['note_text'])); ?>
                                        </div>
                                        <div class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($n['created_at'])); ?></div>
                                    </div>
                                    <?php if ($n['vital_signs']): ?>
                                        <?php $vs = json_decode($n['vital_signs'], true); ?>
                                        <div class="text-muted small mt-1">
                                            Temp: <?php echo htmlspecialchars($vs['temperature']); ?>,
                                            BP: <?php echo htmlspecialchars($vs['blood_pressure']); ?>,
                                            Pulse: <?php echo htmlspecialchars($vs['pulse_rate']); ?>,
                                            RR: <?php echo htmlspecialchars($vs['respiratory_rate']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-muted small">By: <?php echo htmlspecialchars($n['nurse_name']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Declare Death Modal -->
<div class="modal fade" id="declareDeathModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fas fa-skull-crossbones me-2"></i>Declare Patient Death</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="declare_death">
            <div class="mb-3">
                <label class="form-label">Time of Death</label>
                <input type="text" name="time_of_death" class="form-control" placeholder="YYYY-MM-DD HH:MM:SS" value="<?php echo date('Y-m-d H:i:s'); ?>">
                <div class="form-text">Leave as-is to use current time.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Cause of Death</label>
                <textarea name="cause_of_death" class="form-control" rows="2" required></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Notes (optional)</label>
                <textarea name="death_notes" class="form-control" rows="3" placeholder="Additional context for medical records..."></textarea>
            </div>
            <div class="alert alert-warning small">
                <i class="fas fa-exclamation-triangle me-2"></i>
                This will discharge the patient and notify Medical Records for archiving.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-dark"><i class="fas fa-check me-1"></i>Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html> 
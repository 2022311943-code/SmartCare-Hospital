<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once 'config/database.php';
require_once 'includes/crypto.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'patient_portal') {
    header('Location: index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$patient_record_id = (int)($_SESSION['patient_record_id'] ?? 0);
$patient = null;
if ($patient_record_id > 0) {
    $st = $db->prepare('SELECT * FROM patient_records WHERE id = :id');
    $st->execute([':id'=>$patient_record_id]);
    $patient = $st->fetch(PDO::FETCH_ASSOC);
    if ($patient) {
        foreach (['patient_name','contact_number','address'] as $f) {
            if (isset($patient[$f])) { $patient[$f] = decrypt_safe((string)$patient[$f]); }
        }
    }
}

// Compute follow-up date highlight (upcoming or overdue)
$follow_up_banner = null;
if ($patient_record_id > 0) {
    try {
        // Nearest upcoming follow-up (today or future)
        $nextStmt = $db->prepare("SELECT follow_up_date 
                                  FROM opd_visits 
                                  WHERE patient_record_id = :pid 
                                    AND follow_up_date IS NOT NULL 
                                    AND follow_up_date >= CURDATE()
                                  ORDER BY follow_up_date ASC 
                                  LIMIT 1");
        $nextStmt->execute([':pid' => $patient_record_id]);
        $next = $nextStmt->fetch(PDO::FETCH_ASSOC);

        if ($next && !empty($next['follow_up_date'])) {
            $date = $next['follow_up_date'];
            $days = (int)((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
            $follow_up_banner = [
                'kind' => 'upcoming',
                'date' => $date,
                'days' => $days,
                'variant' => $days <= 0 ? 'success' : ($days <= 7 ? 'warning' : 'info'),
                'icon' => $days <= 0 ? 'fa-circle-check' : 'fa-bell'
            ];
        } else {
            // No upcoming; check most recent missed follow-up (overdue)
            $prevStmt = $db->prepare("SELECT follow_up_date 
                                      FROM opd_visits 
                                      WHERE patient_record_id = :pid 
                                        AND follow_up_date IS NOT NULL 
                                        AND follow_up_date < CURDATE()
                                      ORDER BY follow_up_date DESC 
                                      LIMIT 1");
            $prevStmt->execute([':pid' => $patient_record_id]);
            $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
            if ($prev && !empty($prev['follow_up_date'])) {
                $date = $prev['follow_up_date'];
                $daysOver = (int)((strtotime(date('Y-m-d')) - strtotime($date)) / 86400);
                $follow_up_banner = [
                    'kind' => 'overdue',
                    'date' => $date,
                    'days' => $daysOver,
                    'variant' => 'danger',
                    'icon' => 'fa-triangle-exclamation'
                ];
            }
        }
    } catch (Exception $e) { /* ignore banner on error */ }
}
// Password change handling for patient portal users
$pwd_error = '';
$pwd_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    try {
        $portalId = (int)($_SESSION['user_id'] ?? 0);
        if ($portalId <= 0) { throw new Exception('Invalid session. Please login again.'); }

        $cur = trim($_POST['current_password'] ?? '');
        $new = trim($_POST['new_password'] ?? '');
        $conf = trim($_POST['confirm_password'] ?? '');

        if ($cur === '' || $new === '' || $conf === '') {
            throw new Exception('All password fields are required.');
        }
        if ($new !== $conf) {
            throw new Exception('New passwords do not match.');
        }
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{12,}$/', $new)) {
            throw new Exception('Password must be at least 12 characters and include uppercase, lowercase, number, and symbol.');
        }

        // Fetch existing hash
        $stAcc = $db->prepare('SELECT id, password_hash FROM patient_portal_accounts WHERE id = :id LIMIT 1');
        $stAcc->execute([':id' => $portalId]);
        $acc = $stAcc->fetch(PDO::FETCH_ASSOC);
        if (!$acc) { throw new Exception('Account not found.'); }
        if (!password_verify($cur, $acc['password_hash'])) { throw new Exception('Current password is incorrect.'); }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $up = $db->prepare('UPDATE patient_portal_accounts SET password_hash = :ph WHERE id = :id');
        $up->execute([':ph' => $newHash, ':id' => $portalId]);
        // Best-effort: clear any stored plain/reset flags after user changes password
        try {
            $clr = $db->prepare('UPDATE patient_portal_accounts SET password_plain_enc = NULL, password_plain_is_reset = 1, password_reset_at = CURRENT_TIMESTAMP WHERE id = :id');
            $clr->execute([':id' => $portalId]);
        } catch (Exception $e) { /* ignore if columns not present */ }
        $pwd_success = 'Your password has been updated successfully.';
    } catch (Exception $e) {
        $pwd_error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-user me-2"></i>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#settingsModal">
                <i class="fas fa-cog me-1"></i>Settings
            </button>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
    </div>
    </div>

    <?php if ($follow_up_banner): ?>
        <div class="alert alert-<?php echo htmlspecialchars($follow_up_banner['variant']); ?> d-flex align-items-center" role="alert">
            <i class="fas <?php echo htmlspecialchars($follow_up_banner['icon']); ?> me-2"></i>
            <?php if ($follow_up_banner['kind'] === 'upcoming'): ?>
                <div>
                    <strong>Follow-up <?php echo $follow_up_banner['days'] === 0 ? 'Today' : 'Scheduled'; ?>:</strong>
                    <?php echo date('M d, Y', strtotime($follow_up_banner['date'])); ?>
                    <?php if ($follow_up_banner['days'] > 0): ?>
                        <span class="ms-2 badge bg-dark-subtle text-dark">in <?php echo (int)$follow_up_banner['days']; ?> day<?php echo $follow_up_banner['days'] === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div>
                    <strong>Missed Follow-up:</strong>
                    <?php echo date('M d, Y', strtotime($follow_up_banner['date'])); ?>
                    <span class="ms-2 badge bg-light text-dark"><?php echo (int)$follow_up_banner['days']; ?> day<?php echo $follow_up_banner['days'] === 1 ? '' : 's'; ?> overdue</span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-cog me-2"></i>Account Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="change_password">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" name="current_password" id="currentPwd" required>
                                <button class="btn btn-outline-secondary" type="button" id="toggleCurrent"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                <input type="password" class="form-control" name="new_password" id="newPwd" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}" title="Minimum 12 characters with uppercase, lowercase, number, and symbol" required>
                                <button class="btn btn-outline-secondary" type="button" id="toggleNew"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="form-text">Minimum 12 chars with uppercase, lowercase, number, and symbol.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                <input type="password" class="form-control" name="confirm_password" id="confirmPwd" minlength="12" required>
                                <button class="btn btn-outline-secondary" type="button" id="toggleConfirm"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($pwd_error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($pwd_error); ?></div>
    <?php endif; ?>
    <?php if ($pwd_success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($pwd_success); ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-2"><strong>Name:</strong> <?php echo htmlspecialchars($patient['patient_name'] ?? '-'); ?></div>
                    <div class="mb-2"><strong>Contact:</strong> <?php echo htmlspecialchars($patient['contact_number'] ?? '-'); ?></div>
                    <div class="mb-2"><strong>Address:</strong> <?php echo htmlspecialchars($patient['address'] ?? '-'); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="mb-2"><strong>Patient Record ID:</strong> <?php echo $patient_record_id; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white"><strong><i class="fas fa-file-medical me-2"></i>Recent Visits</strong></div>
        <div class="card-body">
            <?php
            try {
                $vis = $db->prepare("SELECT created_at, visit_type, visit_status FROM opd_visits WHERE patient_record_id = :pid ORDER BY created_at DESC LIMIT 10");
                $vis->execute([':pid'=>$patient_record_id]);
                $visits = $vis->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $visits = []; }
            if (empty($visits)) {
                echo '<div class="text-muted">No recent visits.</div>';
            } else {
                echo '<ul class="list-group">';
                foreach ($visits as $v) {
                    echo '<li class="list-group-item d-flex justify-content-between"><span>'.date('M d, Y h:i A', strtotime($v['created_at'])).'</span><span class="badge bg-secondary">'.htmlspecialchars($v['visit_type']).' / '.htmlspecialchars($v['visit_status']).'</span></li>';
                }
                echo '</ul>';
            }
            ?>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong><i class="fas fa-notes-medical me-2"></i>Patient History</strong>
            <a href="patient_history.php?id=<?php echo (int)$patient_record_id; ?>" class="btn btn-sm btn-danger">
                <i class="fas fa-history me-1"></i>View Full History
            </a>
        </div>
        <div class="card-body">
            <?php
            try {
                $hist = $db->prepare("SELECT ov.created_at, ov.visit_type, ov.visit_status, u.name as doctor_name,
                                              ov.symptoms, ov.diagnosis, ov.treatment_plan, ov.prescription
                                       FROM opd_visits ov
                                       LEFT JOIN users u ON ov.doctor_id = u.id
                                       WHERE ov.patient_record_id = :pid
                                       ORDER BY ov.created_at DESC
                                       LIMIT 10");
                $hist->execute([':pid'=>$patient_record_id]);
                $history = $hist->fetchAll(PDO::FETCH_ASSOC);
                foreach ($history as &$h) {
                    foreach (['symptoms','diagnosis','treatment_plan','prescription','doctor_name'] as $f) {
                        if (isset($h[$f])) { $h[$f] = decrypt_safe((string)$h[$f]); }
                    }
                }
                unset($h);
            } catch (Exception $e) { $history = []; }

            if (empty($history)) {
                echo '<div class="text-muted">No history yet.</div>';
            } else {
                echo '<div class="list-group">';
                foreach ($history as $h) {
                    echo '<div class="list-group-item">';
                    echo '<div class="d-flex justify-content-between"><div><strong>'.date('M d, Y h:i A', strtotime($h['created_at'])).'</strong> • '.htmlspecialchars($h['visit_type']).'</div>';
                    echo '<span class="badge bg-secondary">'.htmlspecialchars($h['visit_status']).'</span></div>';
                    echo '<div class="small text-muted">Doctor: '.htmlspecialchars($h['doctor_name'] ?? '—').'</div>';
                    if (!empty($h['symptoms'])) echo '<div class="mt-1"><small class="text-muted">Symptoms:</small> '.nl2br(htmlspecialchars($h['symptoms'])).'</div>';
                    if (!empty($h['diagnosis'])) echo '<div class="mt-1"><small class="text-muted">Diagnosis:</small> '.nl2br(htmlspecialchars($h['diagnosis'])).'</div>';
                    if (!empty($h['treatment_plan'])) echo '<div class="mt-1"><small class="text-muted">Treatment:</small> '.nl2br(htmlspecialchars($h['treatment_plan'])).'</div>';
                    if (!empty($h['prescription'])) echo '<div class="mt-1"><small class="text-muted">Prescription:</small> '.nl2br(htmlspecialchars($h['prescription'])).'</div>';
                    echo '</div>';
                }
                echo '</div>';
            }
            ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    function wireToggle(inputId, btnId) {
        var input = document.getElementById(inputId);
        var btn = document.getElementById(btnId);
        if (!input || !btn) return;
        btn.addEventListener('click', function(){
            var icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
            } else {
                input.type = 'password';
                if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
            }
        });
    }
    wireToggle('currentPwd', 'toggleCurrent');
    wireToggle('newPwd', 'toggleNew');
    wireToggle('confirmPwd', 'toggleConfirm');
});
</script>
<?php if ($pwd_error): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var modalEl = document.getElementById('settingsModal');
    if (modalEl && window.bootstrap) {
        var m = new bootstrap.Modal(modalEl);
        m.show();
    }
});
</script>
<?php endif; ?>
</body>
</html>



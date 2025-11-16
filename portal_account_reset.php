<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once 'config/database.php';
require_once 'includes/crypto.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['employee_type'] ?? '') !== 'admin_staff') {
    header('Location: index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];
if ($q !== '') {
    try {
        // Collect results keyed by patient_record_id to avoid duplicates
        $byId = [];

        // 1) If numeric, try direct ID match (no decryption needed)
        if (preg_match('/^\d+$/', $q)) {
            $st = $db->prepare("SELECT id, patient_name, contact_number, address FROM patient_records WHERE id = :id LIMIT 1");
            $st->execute([':id'=>(int)$q]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $row['patient_name'] = decrypt_safe((string)$row['patient_name']);
                $row['contact_number'] = decrypt_safe((string)$row['contact_number']);
                $row['address'] = decrypt_safe((string)$row['address']);
                $byId[(int)$row['id']] = $row;
            }
        }

        // 2) Try by portal username (not encrypted)
        $stU = $db->prepare("SELECT pr.id, pr.patient_name, pr.contact_number, pr.address
                              FROM patient_portal_accounts ppa
                              JOIN patient_records pr ON pr.id = ppa.patient_record_id
                              WHERE ppa.username LIKE :u
                              ORDER BY pr.id DESC
                              LIMIT 50");
        $stU->execute([':u' => '%'.$q.'%']);
        foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['patient_name'] = decrypt_safe((string)$r['patient_name']);
            $r['contact_number'] = decrypt_safe((string)$r['contact_number']);
            $r['address'] = decrypt_safe((string)$r['address']);
            $byId[(int)$r['id']] = $r;
        }

        // 3) Decrypt and filter recent records by name/contact/address
        $stR = $db->prepare("SELECT id, patient_name, contact_number, address FROM patient_records ORDER BY id DESC LIMIT 500");
        $stR->execute();
        $needle = mb_strtolower($q, 'UTF-8');
        foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = decrypt_safe((string)$r['patient_name']);
            $contact = decrypt_safe((string)$r['contact_number']);
            $addr = decrypt_safe((string)$r['address']);
            $hay = mb_strtolower($name.' '.$contact.' '.$addr, 'UTF-8');
            if (mb_strpos($hay, $needle, 0, 'UTF-8') !== false) {
                $r['patient_name'] = $name;
                $r['contact_number'] = $contact;
                $r['address'] = $addr;
                $byId[(int)$r['id']] = $r;
            }
        }

        // Finalize results preserving insertion order by id desc
        if (!empty($byId)) {
            // Sort by id desc similar to original query
            krsort($byId, SORT_NUMERIC);
            $results = array_values($byId);
        }
    } catch (Exception $e) { /* ignore */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal Account Reset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="fas fa-user-shield me-2"></i>Patient Portal Account Reset</h3>
        <a href="dashboard.php" class="btn btn-outline-danger">Back</a>
    </div>

    <form method="get" class="input-group mb-3" style="max-width: 520px;">
        <span class="input-group-text"><i class="fas fa-search"></i></span>
        <input type="text" class="form-control" name="q" placeholder="Search patient name..." value="<?php echo htmlspecialchars($q); ?>">
        <button class="btn btn-danger" type="submit">Search</button>
    </form>

    <?php if ($q !== '' && empty($results)): ?>
        <div class="alert alert-info">No patients found for "<?php echo htmlspecialchars($q); ?>".</div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
    <div class="card">
        <div class="card-header bg-white"><strong>Results</strong></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th style="width: 1%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['id']; ?></td>
                                <td><?php echo htmlspecialchars($r['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['contact_number']); ?></td>
                                <td><?php echo htmlspecialchars($r['address']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-danger btn-reset" data-pid="<?php echo (int)$r['id']; ?>">
                                        <i class="fas fa-rotate me-1"></i>Reset Password
                                    </button>
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

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-key me-2"></i>New Portal Credentials</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>Please copy these credentials now. The password will not be shown again after you leave this page.</div>
        <div class="mb-2"><strong>Username:</strong> <span id="credUsername">-</span></div>
        <div class="mb-2"><strong>Password:</strong> <span id="credPassword" class="text-danger">-</span></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    const uEl = document.getElementById('credUsername');
    const pEl = document.getElementById('credPassword');
    document.querySelectorAll('.btn-reset').forEach(function(btn){
        btn.addEventListener('click', function(){
            const pid = Number(btn.getAttribute('data-pid')) || 0;
            if (!pid) return;
            btn.disabled = true;
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Resetting...';
            fetch('reset_patient_portal_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ patient_record_id: pid })
            }).then(r=>r.json()).then(data=>{
                if (!data || !data.success) throw new Error(data && data.error ? data.error : 'Failed to reset');
                uEl.textContent = data.username || '-';
                pEl.textContent = data.password || '-';
                modal.show();
            }).catch(err=>{
                alert(err.message || 'Unable to reset password');
            }).finally(()=>{
                btn.disabled = false; btn.innerHTML = original;
            });
        });
    });
});
</script>
</body>
</html>



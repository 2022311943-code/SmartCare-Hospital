<?php
session_start();
require_once "config/database.php";

// Check if user is logged in and is doctor/general_doctor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['employee_type'], ['doctor','general_doctor'])) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Only show inpatients for the logged-in doctor (privacy)
$is_general = false;

// Handle transfer request from OB-GYN or Pediatrics doctors
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_transfer') {
    if ($_SESSION['employee_type'] === 'doctor' && in_array($_SESSION['department'], ['OB-GYN','Pediatrics'])) {
        $admission_id = intval($_POST['admission_id']);
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

        try {
            $db->beginTransaction();
            $details = "Transfer Room Order\n";
            if ($notes !== '') {
                $details .= $notes;
            }
            $stmt = $db->prepare("INSERT INTO doctors_orders (admission_id, order_type, order_details, frequency, duration, special_instructions, ordered_by) VALUES (:aid, 'other', :details, NULL, NULL, 'ROOM_TRANSFER', :uid)");
            $stmt->bindParam(":aid", $admission_id);
            $stmt->bindParam(":details", $details);
            $stmt->bindParam(":uid", $_SESSION['user_id']);
            $stmt->execute();
            $db->commit();
            header("Location: doctor_inpatients.php?success=transfer_requested");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: doctor_inpatients.php?error=" . urlencode($e->getMessage()));
            exit();
        }
    } else {
        header("Location: doctor_inpatients.php?error=unauthorized");
        exit();
    }
}

$query = "SELECT pa.id as admission_id, p.name as patient_name, p.age, p.gender,
                 r.room_number, b.bed_number, pa.created_at
          FROM patient_admissions pa
          JOIN patients p ON pa.patient_id = p.id
          LEFT JOIN rooms r ON pa.room_id = r.id
          LEFT JOIN beds b ON pa.bed_id = b.id
          WHERE pa.admission_status = 'admitted' AND p.doctor_id = :doctor_id
          ORDER BY pa.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(":doctor_id", $_SESSION['user_id']);
$stmt->execute();
$admissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Inpatients - Place Orders';
define('INCLUDED_IN_PAGE', true);
define('GA_ALREADY_IN_PAGE', true);
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-HT585MPVQ4"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);} 
      gtag('js', new Date());
      gtag('config', 'G-HT585MPVQ4');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inpatients - Doctor Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-procedures me-2"></i>Admitted Patients</h2>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success']==='transfer_requested'): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Room transfer request submitted for nursing.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="inpatientSearch" placeholder="Search patient, room, bed...">
                        <button class="btn btn-outline-secondary" type="button" id="clearInpatientSearch" style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
    <?php if (empty($admissions)): ?>
        <p class="text-muted text-center mb-0">No admitted patients found.</p>
        <?php if (!$is_general): ?>
            <div class="alert alert-info mt-3 text-center"><i class="fas fa-info-circle me-2"></i>Only your assigned inpatients are listed for privacy.</div>
        <?php endif; ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Admitted</th>
                                <th>Patient</th>
                                <th>Age/Gender</th>
                                <th>Room / Bed</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="inpatientsTableBody">
                        <?php foreach ($admissions as $a): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['age']); ?> / <?php echo ucfirst($a['gender']); ?></td>
                                <td><?php echo htmlspecialchars(($a['room_number'] ?: '-') . ' / ' . ($a['bed_number'] ?: '-')); ?></td>
                                <td>
                                    <a href="doctor_orders.php?admission_id=<?php echo $a['admission_id']; ?>" class="btn btn-danger btn-sm">
                                        <i class="fas fa-clipboard-list me-1"></i>Manage Orders
                                    </a>
                                    <?php if ($_SESSION['employee_type'] === 'doctor' && in_array($_SESSION['department'], ['OB-GYN','Pediatrics'])): ?>
                                        <button class="btn btn-outline-primary btn-sm" onclick="openTransferModal(<?php echo $a['admission_id']; ?>)">
                                            <i class="fas fa-exchange-alt me-1"></i>Request Room Transfer
                                        </button>
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
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="request_transfer">
                <input type="hidden" name="admission_id" id="transfer_admission_id">
                <div class="modal-header">
                    <h5 class="modal-title">Transfer Room Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Transfer Order Details</label>
                        <textarea class="form-control" name="notes" rows="5" placeholder="Enter transfer instructions (clinical reason, isolation, unit preference, special handling, etc.)"></textarea>
                        <div class="form-text">Nurses will receive this order and select an appropriate room during assignment.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let transferModal;
document.addEventListener('DOMContentLoaded', function() {
    transferModal = new bootstrap.Modal(document.getElementById('transferModal'));

    const searchInput = document.getElementById('inpatientSearch');
    const clearBtn = document.getElementById('clearInpatientSearch');
    const tableBody = document.getElementById('inpatientsTableBody');

    function filterRows(term) {
        const q = (term || '').toLowerCase();
        clearBtn.style.display = q ? 'block' : 'none';
        Array.from(tableBody.querySelectorAll('tr')).forEach(row => {
            const admitted = row.children[0]?.textContent.toLowerCase() || '';
            const patient = row.children[1]?.textContent.toLowerCase() || '';
            const ageGen = row.children[2]?.textContent.toLowerCase() || '';
            const roomBed = row.children[3]?.textContent.toLowerCase() || '';
            const match = admitted.includes(q) || patient.includes(q) || ageGen.includes(q) || roomBed.includes(q);
            row.style.display = match ? '' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', e => filterRows(e.target.value));
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', () => { searchInput.value = ''; filterRows(''); });
    }
});
function openTransferModal(admissionId) {
    document.getElementById('transfer_admission_id').value = admissionId;
    transferModal.show();
}
</script>
</body>
</html> 
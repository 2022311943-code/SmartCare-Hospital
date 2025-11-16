<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    header("Location: index.php");
    exit();
}

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($order_id <= 0) {
    header("Location: nurse_orders.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

function loadNewbornOrder($db, $order_id) {
    $sql = "SELECT do.*, 
                   pa.id AS admission_id,
                   p.id AS patient_id,
                   p.name AS patient_name,
                   p.age,
                   p.gender,
                   p.address,
                   r.room_number,
                   b.bed_number
            FROM doctors_orders do
            JOIN patient_admissions pa ON do.admission_id = pa.id
            JOIN patients p ON pa.patient_id = p.id
            LEFT JOIN rooms r ON pa.room_id = r.id
            LEFT JOIN beds b ON pa.bed_id = b.id
            WHERE do.id = :order_id
              AND do.special_instructions = 'NEWBORN_INFO_REQUEST'";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':order_id', $order_id);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$order_info = loadNewbornOrder($db, $order_id);
if (!$order_info || !in_array($order_info['status'], ['active','in_progress'], true)) {
    $_SESSION['nurse_order_success'] = 'Newborn request is no longer available.';
    header("Location: nurse_orders.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $required = ['mother_name','sex','date_of_birth','time_of_birth','place_of_birth'];
    try {
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception('Please complete all required fields.');
            }
        }

        $db->beginTransaction();

        $order_info = loadNewbornOrder($db, $order_id);
        if (!$order_info || !in_array($order_info['status'], ['active','in_progress'], true)) {
            throw new Exception('Newborn request is no longer available.');
        }
        if ($order_info['status'] === 'in_progress' && $order_info['claimed_by'] && intval($order_info['claimed_by']) !== intval($_SESSION['user_id'])) {
            throw new Exception('Another nurse is already processing this request.');
        }

        if ($order_info['status'] === 'active') {
            $claim_stmt = $db->prepare("UPDATE doctors_orders
                                        SET status = 'in_progress',
                                            claimed_by = :nurse_id,
                                            claimed_at = NOW()
                                        WHERE id = :order_id AND status = 'active'");
            $claim_stmt->execute([
                ':nurse_id' => $_SESSION['user_id'],
                ':order_id' => $order_id
            ]);
        }

        $bc_stmt = $db->prepare("INSERT INTO birth_certificates (
                patient_id, admission_id, mother_name, father_name, newborn_name, sex,
                date_of_birth, time_of_birth, place_of_birth, birth_weight_kg, birth_length_cm,
                type_of_birth, attendant_at_birth, remarks, submitted_by
            ) VALUES (
                :patient_id, :admission_id, :mother_name, :father_name, :newborn_name, :sex,
                :date_of_birth, :time_of_birth, :place_of_birth, :birth_weight_kg, :birth_length_cm,
                :type_of_birth, :attendant_at_birth, :remarks, :submitted_by
            )");
        $bc_stmt->bindValue(':patient_id', $order_info['patient_id']);
        $bc_stmt->bindValue(':admission_id', $order_info['admission_id']);
        $bc_stmt->bindValue(':mother_name', $_POST['mother_name']);
        $bc_stmt->bindValue(':father_name', $_POST['father_name'] ?? null);
        $bc_stmt->bindValue(':newborn_name', $_POST['newborn_name'] ?? null);
        $bc_stmt->bindValue(':sex', $_POST['sex']);
        $bc_stmt->bindValue(':date_of_birth', $_POST['date_of_birth']);
        $bc_stmt->bindValue(':time_of_birth', $_POST['time_of_birth']);
        $bc_stmt->bindValue(':place_of_birth', $_POST['place_of_birth']);
        $bc_stmt->bindValue(':birth_weight_kg', $_POST['birth_weight_kg'] ?? null);
        $bc_stmt->bindValue(':birth_length_cm', $_POST['birth_length_cm'] ?? null);
        $bc_stmt->bindValue(':type_of_birth', $_POST['type_of_birth'] ?? 'single');
        $bc_stmt->bindValue(':attendant_at_birth', $_POST['attendant_at_birth'] ?? ($_SESSION['user_name'] ?? ''));
        $bc_stmt->bindValue(':remarks', $_POST['remarks'] ?? null);
        $bc_stmt->bindValue(':submitted_by', $_SESSION['user_id']);
        $bc_stmt->execute();

        $complete_newborn = $db->prepare("UPDATE doctors_orders
                                          SET status = 'completed',
                                              completed_by = :nurse_id,
                                              completed_at = NOW(),
                                              completion_note = 'Newborn information submitted to Medical Records'
                                          WHERE id = :order_id");
        $complete_newborn->bindValue(':nurse_id', $_SESSION['user_id']);
        $complete_newborn->bindValue(':order_id', $order_id);
        $complete_newborn->execute();

        $db->commit();

        $_SESSION['nurse_order_success'] = 'Newborn information submitted successfully.';
        header("Location: nurse_orders.php");
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Error: ' . $e->getMessage();
    }
}

$page_title = 'Newborn Request';
define('INCLUDED_IN_PAGE', true);
require_once 'includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Newborn Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-baby me-2"></i>Newborn Information Request</h2>
        <a href="nurse_orders.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label text-muted mb-0">Patient</label>
                    <div class="fw-semibold"><?php echo htmlspecialchars($order_info['patient_name']); ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-muted mb-0">Room / Bed</label>
                    <div class="fw-semibold"><?php echo htmlspecialchars(($order_info['room_number'] ?? '-') . ' / ' . ($order_info['bed_number'] ?? '-')); ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-muted mb-0">Age / Gender</label>
                    <div class="fw-semibold">
                        <?php echo htmlspecialchars(($order_info['age'] ?? '-') . ' yrs / ' . ucfirst($order_info['gender'] ?? '-')); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Mother's Full Name</label>
                        <input type="text" class="form-control" name="mother_name" value="<?php echo htmlspecialchars($_POST['mother_name'] ?? $order_info['patient_name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Father's Full Name</label>
                        <input type="text" class="form-control" name="father_name" value="<?php echo htmlspecialchars($_POST['father_name'] ?? ''); ?>" placeholder="Optional">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Newborn Full Name</label>
                        <input type="text" class="form-control" name="newborn_name" value="<?php echo htmlspecialchars($_POST['newborn_name'] ?? ''); ?>" placeholder="Optional">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sex</label>
                        <select class="form-select" name="sex" required>
                            <option value="">Select</option>
                            <option value="male" <?php echo (($_POST['sex'] ?? '') === 'male') ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo (($_POST['sex'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type of Birth</label>
                        <select class="form-select" name="type_of_birth">
                            <?php
                            $type = $_POST['type_of_birth'] ?? 'single';
                            $types = ['single' => 'Single','twin' => 'Twin','multiple' => 'Multiple'];
                            foreach ($types as $k => $label):
                            ?>
                            <option value="<?php echo $k; ?>" <?php echo $type === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" class="form-control" name="date_of_birth" value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Time of Birth</label>
                        <input type="time" class="form-control" name="time_of_birth" value="<?php echo htmlspecialchars($_POST['time_of_birth'] ?? date('H:i')); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Place of Birth</label>
                        <input type="text" class="form-control" name="place_of_birth" value="<?php echo htmlspecialchars($_POST['place_of_birth'] ?? 'Candaba Municipal Infirmary'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Birth Weight (kg)</label>
                        <input type="number" step="0.01" class="form-control" name="birth_weight_kg" value="<?php echo htmlspecialchars($_POST['birth_weight_kg'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Birth Length (cm)</label>
                        <input type="number" step="0.01" class="form-control" name="birth_length_cm" value="<?php echo htmlspecialchars($_POST['birth_length_cm'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Attendant at Birth</label>
                        <input type="text" class="form-control" name="attendant_at_birth" value="<?php echo htmlspecialchars($_POST['attendant_at_birth'] ?? ($_SESSION['user_name'] ?? '')); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Remarks / Notes</label>
                        <textarea class="form-control" name="remarks" rows="3" placeholder="Optional notes"><?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="nurse_orders.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i>Submit Details
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


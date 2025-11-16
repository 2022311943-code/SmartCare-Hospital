<?php
session_start();
define('INCLUDED_IN_PAGE', true);
require_once "config/database.php";
require_once "includes/crypto.php";

// Check if user is logged in and is a general doctor
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'general_doctor') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['manage_action'])) {
    try {
        $db->beginTransaction();

        // Verify that the OPD visit exists and is in progress
        $verify_query = "SELECT id FROM opd_visits 
                        WHERE id = :visit_id 
                        AND doctor_id = :doctor_id 
                        AND visit_status = 'in_progress'";
        $verify_stmt = $db->prepare($verify_query);
        $verify_stmt->bindParam(':visit_id', $_POST['visit_id']);
        $verify_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
        $verify_stmt->execute();

        if (!$verify_stmt->fetch()) {
            throw new Exception("Invalid or inactive OPD visit selected.");
        }

        // Collect selected tests (support multiple)
        $selectedTests = [];
        if (isset($_POST['test_ids'])) {
            $selectedTests = is_array($_POST['test_ids']) ? $_POST['test_ids'] : [$_POST['test_ids']];
        }
        $selectedTests = array_values(array_unique(array_map(fn($v) => (int)$v, $selectedTests)));
        if (count($selectedTests) === 0) {
            throw new Exception('Please select at least one test.');
        }

        $visitId = $_POST['visit_id'];
        $priority = $_POST['priority'];
        $notes = $_POST['notes'];

        // Prepare statements
        $check_duplicate_sql = "SELECT id, status FROM lab_requests 
                          WHERE visit_id = :visit_id 
                          AND test_id = :test_id 
                          AND status NOT IN ('completed', 'cancelled')";
        $check_stmt = $db->prepare($check_duplicate_sql);

        $insert_sql = "INSERT INTO lab_requests 
            (visit_id, doctor_id, test_id, priority, notes, status) 
            VALUES (:visit_id, :doctor_id, :test_id, :priority, :notes, 'pending_payment')";
        $insert_stmt = $db->prepare($insert_sql);

        $numInserted = 0;
        foreach ($selectedTests as $testId) {
            $check_stmt->bindParam(':visit_id', $visitId);
            $check_stmt->bindParam(':test_id', $testId);
            $check_stmt->execute();
            if ($existing = $check_stmt->fetch()) {
                $status = match($existing['status']) {
                    'pending_payment' => 'waiting for payment',
                    'pending' => 'pending processing',
                    'in_progress' => 'currently being processed',
                    default => 'in process'
                };
                throw new Exception('A selected test is already requested and ' . $status . ' for this patient.');
            }

            $insert_stmt->bindParam(':visit_id', $visitId);
            $insert_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
            $insert_stmt->bindParam(':test_id', $testId);
            $insert_stmt->bindParam(':priority', $priority);
            $insert_stmt->bindParam(':notes', $notes);
            $insert_stmt->execute();
            $numInserted++;
        }

        $db->commit();
        $success_message = $numInserted > 1 
            ? (string)$numInserted . " laboratory requests created successfully!" 
            : "Laboratory request created successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error creating laboratory request: " . $e->getMessage();
    }
}

// Manage actions: update/cancel existing request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_action'])) {
    try {
        $db->beginTransaction();
        $reqId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        if ($reqId <= 0) { throw new Exception('Invalid request id.'); }
        // Ensure ownership and fetch row
        $rowStmt = $db->prepare("SELECT * FROM lab_requests WHERE id = :id AND doctor_id = :doc LIMIT 1");
        $rowStmt->bindParam(':id', $reqId);
        $rowStmt->bindParam(':doc', $_SESSION['user_id']);
        $rowStmt->execute();
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Exception('Request not found.'); }
        if (in_array($row['status'], ['completed','cancelled'], true)) { throw new Exception('Cannot modify a completed or cancelled request.'); }

        if ($_POST['manage_action'] === 'cancel') {
            $upd = $db->prepare("UPDATE lab_requests SET status = 'cancelled' WHERE id = :id");
            $upd->bindParam(':id', $reqId);
            $upd->execute();
            $db->commit();
            $success_message = 'Request cancelled.';
        } elseif ($_POST['manage_action'] === 'update') {
            // Only allow updates while pending_payment or pending
            if (!in_array($row['status'], ['pending_payment','pending'], true)) {
                throw new Exception('Only pending requests can be modified.');
            }
            $newVisit = isset($_POST['edit_visit_id']) ? (int)$_POST['edit_visit_id'] : (int)$row['visit_id'];
            $newTest = isset($_POST['edit_test_id']) ? (int)$_POST['edit_test_id'] : (int)$row['test_id'];
            $newPriority = isset($_POST['edit_priority']) ? (string)$_POST['edit_priority'] : (string)$row['priority'];
            $newNotes = isset($_POST['edit_notes']) ? (string)$_POST['edit_notes'] : '';

            // Validate visit belongs to an active consultation or is the same
            $vStmt = $db->prepare("SELECT id FROM opd_visits WHERE id = :vid AND doctor_id = :doc LIMIT 1");
            $vStmt->bindParam(':vid', $newVisit);
            $vStmt->bindParam(':doc', $_SESSION['user_id']);
            $vStmt->execute();
            if (!$vStmt->fetch() && $newVisit !== (int)$row['visit_id']) {
                throw new Exception('Selected patient visit is not available.');
            }

            // Duplicate check for changed pairs
            if ($newVisit !== (int)$row['visit_id'] || $newTest !== (int)$row['test_id']) {
                $dup = $db->prepare("SELECT id FROM lab_requests WHERE visit_id = :vid AND test_id = :tid AND id <> :id AND status NOT IN ('completed','cancelled') LIMIT 1");
                $dup->bindParam(':vid', $newVisit, PDO::PARAM_INT);
                $dup->bindParam(':tid', $newTest, PDO::PARAM_INT);
                $dup->bindParam(':id', $reqId, PDO::PARAM_INT);
                $dup->execute();
                if ($dup->fetch()) { throw new Exception('A similar request already exists for that patient.'); }
            }

            $upd = $db->prepare("UPDATE lab_requests SET visit_id = :vid, test_id = :tid, priority = :prio, notes = :notes WHERE id = :id");
            $upd->bindParam(':vid', $newVisit);
            $upd->bindParam(':tid', $newTest);
            $upd->bindParam(':prio', $newPriority);
            $upd->bindParam(':notes', $newNotes);
            $upd->bindParam(':id', $reqId);
            $upd->execute();
            $db->commit();
            $success_message = 'Request updated.';
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = 'Manage action failed: ' . $e->getMessage();
    }
}

// Get available lab tests
$test_query = "SELECT * FROM lab_tests ORDER BY test_type, test_name";
$test_stmt = $db->prepare($test_query);
$test_stmt->execute();
$lab_tests = $test_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active OPD visits for this doctor
$visit_query = "SELECT 
                    v.id as visit_id,
                    v.patient_name,
                    v.age,
                    v.gender,
                    v.contact_number,
                    v.arrival_time,
                    v.symptoms
                FROM opd_visits v
                WHERE v.doctor_id = :doctor_id 
                AND v.visit_status = 'in_progress'
                ORDER BY v.arrival_time DESC";
$visit_stmt = $db->prepare($visit_query);
$visit_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
$visit_stmt->execute();
$active_visits = $visit_stmt->fetchAll(PDO::FETCH_ASSOC);
// Decrypt fields for display
foreach ($active_visits as &$v) {
    $v['patient_name'] = decrypt_safe($v['patient_name'] ?? '');
    $v['contact_number'] = decrypt_safe($v['contact_number'] ?? '');
    $v['symptoms'] = decrypt_safe($v['symptoms'] ?? '');
}
unset($v);

// If arriving from patient_consultation with a visit_id, use it to preselect the patient
$preselect_visit_id = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;

// Get recent requests
                $recent_query = "SELECT 
                    lr.*,
                    lt.test_name,
                    lt.test_type,
                    v.patient_name,
                    CASE 
                        WHEN lr.status = 'completed' THEN 'Completed'
                        WHEN lr.status = 'in_progress' THEN 
                            CASE lt.test_type
                                WHEN 'laboratory' THEN 'Processing in Lab'
                                WHEN 'radiology' THEN 'Processing in Radiology'
                            END
                        WHEN lr.status = 'pending' THEN 'Pending'
                        WHEN lr.status = 'pending_payment' THEN 'Waiting for Payment'
                        ELSE lr.status
                    END as status_display
                FROM lab_requests lr
                JOIN lab_tests lt ON lr.test_id = lt.id
                JOIN opd_visits v ON lr.visit_id = v.id
                WHERE lr.doctor_id = :doctor_id
                ORDER BY lr.requested_at DESC
                LIMIT 10";
$recent_stmt = $db->prepare($recent_query);
$recent_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
$recent_stmt->execute();
$recent_requests = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent_requests as &$rr) {
    $rr['patient_name'] = decrypt_safe($rr['patient_name'] ?? '');
}
unset($rr);

// Fetch my requests for management (latest 25)
$manage_query = "SELECT lr.*, lt.test_name, lt.test_type, v.patient_name, v.arrival_time
                 FROM lab_requests lr
                 JOIN lab_tests lt ON lr.test_id = lt.id
                 JOIN opd_visits v ON lr.visit_id = v.id
                 WHERE lr.doctor_id = :doctor_id
                 ORDER BY lr.requested_at DESC
                 LIMIT 25";
$manage_stmt = $db->prepare($manage_query);
$manage_stmt->bindParam(':doctor_id', $_SESSION['user_id']);
$manage_stmt->execute();
$my_requests = $manage_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($my_requests as &$mr) {
    $mr['patient_name'] = decrypt_safe($mr['patient_name'] ?? '');
}
unset($mr);

// If editing a specific request, fetch it
$editing_request = null;
if (isset($_GET['edit_request_id'])) {
    $erid = (int)$_GET['edit_request_id'];
    if ($erid > 0) {
        $erStmt = $db->prepare("SELECT lr.*, lt.test_name, v.patient_name FROM lab_requests lr JOIN lab_tests lt ON lr.test_id = lt.id JOIN opd_visits v ON lr.visit_id = v.id WHERE lr.id = :id AND lr.doctor_id = :doc LIMIT 1");
        $erStmt->bindParam(':id', $erid);
        $erStmt->bindParam(':doc', $_SESSION['user_id']);
        $erStmt->execute();
        $editing_request = $erStmt->fetch(PDO::FETCH_ASSOC);
        if ($editing_request) {
            $editing_request['patient_name'] = decrypt_safe($editing_request['patient_name'] ?? '');
        }
    }
}

$page_title = "Request Laboratory Test";
$additional_css = '
<style>
    .card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    .card-header {
        background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
        color: white;
        border-radius: 10px 10px 0 0 !important;
    }
    .test-card {
        cursor: pointer;
        transition: all 0.3s;
    }
    .test-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .test-card.selected {
        border: 2px solid #dc3545;
        background-color: #fff5f5;
    }
    .priority-normal { background-color: #28a745; color: white; }
    .priority-urgent { background-color: #ffc107; color: black; }
    .priority-emergency { background-color: #dc3545; color: white; }
</style>';
?>

<?php include 'includes/header.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-end mb-3">
        <a href="dashboard.php" class="btn btn-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Back
        </a>
    </div>
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-flask me-2"></i>
                        Request Laboratory Test
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($active_visits) && !$editing_request): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            No active consultations found. Please start an OPD consultation first.
                        </div>
                    <?php else: ?>
                        <form method="POST" id="labRequestForm">
                            <?php if ($editing_request): ?>
                                <input type="hidden" name="manage_action" value="update">
                                <input type="hidden" name="request_id" value="<?php echo (int)$editing_request['id']; ?>">
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="visit_id" class="form-label"><?php echo $editing_request ? 'Patient for this Test' : 'Select Patient (Active Consultations)'; ?></label>
                                <select class="form-select" name="<?php echo $editing_request ? 'edit_visit_id' : 'visit_id'; ?>" id="visit_id" required>
                                    <option value="">Select a patient</option>
                                    <?php 
                                    $hasCurrent = false;
                                    foreach ($active_visits as $visit): 
										$sel = $editing_request 
											? ((int)$editing_request['visit_id'] === (int)$visit['visit_id']) 
											: ($preselect_visit_id > 0 && (int)$preselect_visit_id === (int)$visit['visit_id']);
                                        if ($sel) { $hasCurrent = true; }
                                    ?>
                                        <option value="<?php echo $visit['visit_id']; ?>" <?php echo $sel ? 'selected' : '';?>>
                                            <?php echo htmlspecialchars($visit['patient_name']); ?> 
                                            (<?php echo $visit['age']; ?> years, 
                                            <?php echo ucfirst($visit['gender']); ?>)
                                            - Arrived: <?php echo date('h:i A', strtotime($visit['arrival_time'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if ($editing_request && !$hasCurrent): ?>
                                        <option value="<?php echo (int)$editing_request['visit_id']; ?>" selected>
                                            <?php echo htmlspecialchars($editing_request['patient_name']); ?> (previous visit)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><?php echo $editing_request ? 'Select Test' : 'Select Test'; ?></label>
                                <?php if ($editing_request): ?>
                                    <select class="form-select" name="edit_test_id" required>
                                        <option value="">Select test</option>
                                        <?php foreach ($lab_tests as $test): ?>
                                            <option value="<?php echo $test['id']; ?>" <?php echo ((int)$editing_request['test_id'] === (int)$test['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($test['test_name']); ?> (<?php echo ucfirst($test['test_type']); ?>) - ₱<?php echo number_format($test['cost'], 2); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
								<div class="accordion" id="testsAccordion">
									<div class="accordion-item" data-type="laboratory">
										<h2 class="accordion-header" id="headingLab">
											<button id="btnLab" class="accordion-button collapsed" type="button" aria-expanded="false" aria-controls="collapseLab">
												Laboratory Tests
											</button>
										</h2>
										<div id="collapseLab" class="accordion-collapse collapse">
											<div class="accordion-body">
												<div class="row g-3">
													<?php foreach ($lab_tests as $test): if ($test['test_type'] !== 'laboratory') continue; ?>
													<div class="col-md-6 test-item" data-name="<?php echo htmlspecialchars($test['test_name']); ?>" data-desc="<?php echo htmlspecialchars($test['description']); ?>" data-type="<?php echo htmlspecialchars($test['test_type']); ?>">
														<div class="card test-card h-100">
															<div class="card-body">
																<div class="form-check">
																	<input class="form-check-input" type="checkbox" 
																		name="test_ids[]" 
																		value="<?php echo $test['id']; ?>" 
																		id="test_<?php echo $test['id']; ?>">
																	<label class="form-check-label" for="test_<?php echo $test['id']; ?>">
																		<strong><?php echo htmlspecialchars($test['test_name']); ?></strong>
																		<span class="badge bg-primary float-end">
																			₱<?php echo number_format($test['cost'], 2); ?>
																		</span>
																	</label>
																</div>
																<small class="text-muted d-block mt-2">
																	<?php echo htmlspecialchars($test['description']); ?>
																</small>
															</div>
														</div>
													</div>
													<?php endforeach; ?>
												</div>
											</div>
										</div>
									</div>
									<div class="accordion-item" data-type="radiology">
										<h2 class="accordion-header" id="headingRad">
											<button id="btnRad" class="accordion-button collapsed" type="button" aria-expanded="false" aria-controls="collapseRad">
												Radiology Tests
											</button>
										</h2>
										<div id="collapseRad" class="accordion-collapse collapse">
											<div class="accordion-body">
												<div class="row g-3">
													<?php foreach ($lab_tests as $test): if ($test['test_type'] !== 'radiology') continue; ?>
													<div class="col-md-6 test-item" data-name="<?php echo htmlspecialchars($test['test_name']); ?>" data-desc="<?php echo htmlspecialchars($test['description']); ?>" data-type="<?php echo htmlspecialchars($test['test_type']); ?>">
														<div class="card test-card h-100">
															<div class="card-body">
																<div class="form-check">
																	<input class="form-check-input" type="checkbox" 
																		name="test_ids[]" 
																		value="<?php echo $test['id']; ?>" 
																		id="test_<?php echo $test['id']; ?>">
																	<label class="form-check-label" for="test_<?php echo $test['id']; ?>">
																		<strong><?php echo htmlspecialchars($test['test_name']); ?></strong>
																		<span class="badge bg-primary float-end">
																			₱<?php echo number_format($test['cost'], 2); ?>
																		</span>
																	</label>
																</div>
																<small class="text-muted d-block mt-2">
																	<?php echo htmlspecialchars($test['description']); ?>
																</small>
															</div>
														</div>
													</div>
													<?php endforeach; ?>
												</div>
											</div>
										</div>
									</div>
								</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Priority</label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="<?php echo $editing_request ? 'edit_priority' : 'priority'; ?>" id="normal" value="normal" <?php echo ($editing_request ? ($editing_request['priority']==='normal') : true) ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-success" for="normal">Normal</label>

                                    <input type="radio" class="btn-check" name="<?php echo $editing_request ? 'edit_priority' : 'priority'; ?>" id="urgent" value="urgent" <?php echo ($editing_request && $editing_request['priority']==='urgent') ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-warning" for="urgent">Urgent</label>

                                    <input type="radio" class="btn-check" name="<?php echo $editing_request ? 'edit_priority' : 'priority'; ?>" id="emergency" value="emergency" <?php echo ($editing_request && $editing_request['priority']==='emergency') ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-danger" for="emergency">Emergency</label>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Clinical Notes</label>
                                <textarea class="form-control" name="<?php echo $editing_request ? 'edit_notes' : 'notes'; ?>" id="notes" rows="3" 
                                          placeholder="Enter any relevant clinical notes or specific instructions"><?php echo $editing_request ? htmlspecialchars(decrypt_safe($editing_request['notes'] ?? '')) : ''; ?></textarea>
                            </div>

                            <div class="text-end">
                                <?php if ($editing_request): ?>
                                    <a href="request_laboratory.php" class="btn btn-light me-2"><i class="fas fa-times me-1"></i>Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-paper-plane me-2"></i>
                                        Submit Request
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>
                        Recent Requests
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_requests)): ?>
                        <p class="text-muted text-center mb-0">No recent requests found.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($recent_requests as $request): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($request['patient_name']); ?></h6>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y', strtotime($request['requested_at'])); ?>
                                        </small>
                                    </div>
                                    <p class="mb-1 small">
                                        <strong>Test:</strong> <?php echo htmlspecialchars($request['test_name']); ?>
                                    </p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge priority-<?php echo $request['priority']; ?>">
                                            <?php echo ucfirst($request['priority']); ?>
                                        </span>
                                        <span class="badge bg-<?php 
                                            echo match($request['status']) {
                                                'pending' => 'warning',
                                                'pending_payment' => 'secondary',
                                                'in_progress' => 'primary',
                                                'completed' => 'success',
                                                'cancelled' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo $request['status_display']; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>Manage Existing Requests</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($my_requests)): ?>
                        <p class="text-muted text-center mb-0">No requests found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Requested</th>
                                        <th>Patient</th>
                                        <th>Test</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($my_requests as $r): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y h:i A', strtotime($r['requested_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($r['patient_name']); ?></td>
                                        <td><?php echo htmlspecialchars($r['test_name']); ?> <small class="text-muted">(<?php echo ucfirst($r['test_type']); ?>)</small></td>
                                        <td><span class="badge priority-<?php echo $r['priority']; ?>"><?php echo ucfirst($r['priority']); ?></span></td>
                                        <td><span class="badge bg-<?php 
                                            echo match($r['status']) {
                                                'pending' => 'warning',
                                                'pending_payment' => 'secondary',
                                                'in_progress' => 'primary',
                                                'completed' => 'success',
                                                'cancelled' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>"><?php echo ucwords(str_replace('_',' ', $r['status'])); ?></span></td>
                                        <td class="text-end">
                                            <a href="request_laboratory.php?edit_request_id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary <?php echo in_array($r['status'], ['pending','pending_payment']) ? '' : 'disabled'; ?>">
                                                <i class="fas fa-edit me-1"></i>Edit
                                            </a>
                                            <form method="POST" style="display:inline-block" onsubmit="return confirm('Cancel this request?');">
                                                <input type="hidden" name="manage_action" value="cancel">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger <?php echo ($r['status']==='completed' || $r['status']==='cancelled') ? 'disabled' : ''; ?>">
                                                    <i class="fas fa-times me-1"></i>Cancel
                                                </button>
                                            </form>
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
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Ensure headers independently toggle open/close
    function setupManualCollapse(btnId, targetId) {
        const btn = document.getElementById(btnId);
        const target = document.getElementById(targetId);
        if (!btn || !target || !window.bootstrap || !bootstrap.Collapse) return;
        btn.addEventListener('click', function(ev) {
			// Manual toggle to avoid double-handling by data API
            const instance = bootstrap.Collapse.getOrCreateInstance(target, { toggle: false });
			const isOpen = target.classList.contains('show');
			if (isOpen) {
                instance.hide();
				btn.classList.add('collapsed');
				btn.setAttribute('aria-expanded', 'false');
            } else {
                instance.show();
				btn.classList.remove('collapsed');
				btn.setAttribute('aria-expanded', 'true');
            }
        });
    }
    setupManualCollapse('btnLab', 'collapseLab');
    setupManualCollapse('btnRad', 'collapseRad');

    // Handle test card toggle selection for checkboxes
    $('.test-card').click(function(e) {
        if (!$(e.target).is('input')) {
            const cb = $(this).find('input[type="checkbox"]');
            cb.prop('checked', !cb.prop('checked'));
        }
        const cb2 = $(this).find('input[type="checkbox"]');
        $(this).toggleClass('selected', cb2.prop('checked'));
    });

    // No filters: all tests are shown within their sections by default

    // Form validation
    $('#labRequestForm').submit(function(e) {
        const isEdit = $('input[name="manage_action"]').val() === 'update';
        if (!isEdit) {
            if (!$('input[name="test_ids[]"]:checked').length) {
                e.preventDefault();
                alert('Please select at least one test.');
                return false;
            }
        }
        return true;
    });
});
</script> 
<?php
session_start();
require_once "config/database.php";

// Only Supra Admin and Admin Staff can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'supra_admin' && $_SESSION['employee_type'] !== 'admin_staff')) {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Handle Add Room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_room') {
    try {
        $query = "INSERT INTO rooms (room_number, room_type, floor_number, status) 
                  VALUES (:room_number, :room_type, :floor_number, :status)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":room_number", $_POST['room_number']);
        $stmt->bindParam(":room_type", $_POST['room_type']);
        $stmt->bindParam(":floor_number", $_POST['floor_number']);
        $stmt->bindParam(":status", $_POST['status']);
        $stmt->execute();
        $success_message = "Room added successfully.";
    } catch(Exception $e) {
        $error_message = "Error adding room: " . $e->getMessage();
    }
}

// Handle Add Beds
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_beds') {
    try {
        $room_id = $_POST['room_id'];
        $bed_numbers = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $_POST['bed_numbers'])));
        
        $db->beginTransaction();
        $added = 0;
        
        foreach ($bed_numbers as $bed_number) {
            $query = "INSERT INTO beds (room_id, bed_number, status) VALUES (:room_id, :bed_number, 'available')";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":room_id", $room_id);
            $stmt->bindParam(":bed_number", $bed_number);
            $stmt->execute();
            $added++;
        }
        
        $db->commit();
        $success_message = "$added bed(s) added successfully.";
    } catch(Exception $e) {
        $db->rollBack();
        $error_message = "Error adding beds: " . $e->getMessage();
    }
}

// Handle Remove Beds
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_beds') {
    try {
        $room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
        $bed_ids = isset($_POST['bed_ids']) ? array_map('intval', (array)$_POST['bed_ids']) : [];
        if ($room_id <= 0 || empty($bed_ids)) {
            throw new Exception('Select at least one available bed to remove.');
        }

        // Validate selected beds belong to the room and are available
        $placeholders = implode(',', array_fill(0, count($bed_ids), '?'));
        $validate_sql = "SELECT id FROM beds WHERE id IN ($placeholders) AND room_id = ? AND status = 'available'";
        $validate_stmt = $db->prepare($validate_sql);
        $params = $bed_ids;
        $params[] = $room_id;
        $validate_stmt->execute($params);
        $valid_ids = $validate_stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($valid_ids) !== count($bed_ids)) {
            throw new Exception('One or more selected beds are not removable (not in this room or not available).');
        }

        // Detach historical (discharged) admissions that referenced these beds so we can safely delete
        $db->beginTransaction();
        $detach_sql = "UPDATE patient_admissions SET bed_id = NULL WHERE bed_id IN ($placeholders) AND admission_status = 'discharged'";
        $detach_stmt = $db->prepare($detach_sql);
        $detach_stmt->execute($bed_ids);

        // Guard: if any active admissions still reference these beds, stop
        $active_check_sql = "SELECT COUNT(*) FROM patient_admissions WHERE bed_id IN ($placeholders) AND admission_status <> 'discharged'";
        $active_check_stmt = $db->prepare($active_check_sql);
        $active_check_stmt->execute($bed_ids);
        $active_refs = (int)$active_check_stmt->fetchColumn();
        if ($active_refs > 0) {
            $db->rollBack();
            throw new Exception('One or more selected beds are still linked to active admissions.');
        }

        $delete_sql = "DELETE FROM beds WHERE id IN ($placeholders)";
        $delete_stmt = $db->prepare($delete_sql);
        $delete_stmt->execute($bed_ids);
        $db->commit();

        $success_message = 'Selected bed(s) removed successfully.';
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Error removing beds: ' . $e->getMessage();
    }
}

// Handle Delete Room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_room') {
    try {
        $room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
        if ($room_id <= 0) {
            throw new Exception('Invalid room.');
        }

        // Ensure no beds remain in this room
        $beds_count_stmt = $db->prepare('SELECT COUNT(*) FROM beds WHERE room_id = :room_id');
        $beds_count_stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
        $beds_count_stmt->execute();
        $beds_count = (int)$beds_count_stmt->fetchColumn();
        if ($beds_count > 0) {
            throw new Exception('Remove all beds in this room before deleting it.');
        }


		// Run inside transaction: detach discharged admissions, verify no active refs, then delete
		$db->beginTransaction();
		$detach_stmt = $db->prepare("UPDATE patient_admissions SET room_id = NULL WHERE room_id = :room_id AND admission_status = 'discharged'");
		$detach_stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
		$detach_stmt->execute();

		$active_stmt = $db->prepare("SELECT COUNT(*) FROM patient_admissions WHERE room_id = :room_id AND admission_status <> 'discharged'");
		$active_stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
		$active_stmt->execute();
		$active_count = (int)$active_stmt->fetchColumn();
		if ($active_count > 0) {
			$db->rollBack();
			throw new Exception('This room is still linked to active admissions. Discharge/transfer them first.');
		}

		$del_stmt = $db->prepare('DELETE FROM rooms WHERE id = :room_id');
		$del_stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
		$del_stmt->execute();
		$db->commit();

        $success_message = 'Room deleted successfully.';
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'Error deleting room: ' . $e->getMessage();
    }
}

// Fetch all rooms with bed counts
$rooms = [];
$room_query = "SELECT r.*, 
               COUNT(b.id) as total_beds,
               SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END) as available_beds,
               SUM(CASE WHEN b.status = 'occupied' THEN 1 ELSE 0 END) as occupied_beds
               FROM rooms r
               LEFT JOIN beds b ON r.id = b.room_id
               GROUP BY r.id
               ORDER BY r.floor_number, r.room_number";
$room_stmt = $db->prepare($room_query);
$room_stmt->execute();
$rooms = $room_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms & Beds - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            padding: 1rem;
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
        .btn-add {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            border: none;
            color: white;
            padding: 8px 20px;
            border-radius: 8px;
        }
        .btn-add:hover {
            background: linear-gradient(135deg, #ff4d4d 0%, #dc3545 100%);
        }
        .btn-primary {
            background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%);
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #ff4d4d 0%, #dc3545 100%);
        }
        .room-card {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .room-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .room-number {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
        }
        .room-type {
            font-size: 0.9rem;
            padding: 4px 8px;
            border-radius: 4px;
            background: #e9ecef;
            color: #495057;
        }
        .bed-status {
            display: flex;
            align-items: center;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        .bed-status i {
            margin-right: 5px;
        }
        .progress {
            height: 8px;
            margin-top: 5px;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-available {
            background: #d4edda;
            color: #155724;
        }
        .status-occupied {
            background: #f8d7da;
            color: #721c24;
        }
        .status-maintenance {
            background: #fff3cd;
            color: #856404;
        }
        a {
            color: #dc3545;
            text-decoration: none;
        }
        a:hover {
            color: #bb2d3b;
        }
        .back-link {
            color: #6c757d;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: #dc3545;
        }
        .form-control:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="supra_admin_dashboard.php" class="btn btn-secondary mb-3">
            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
        </a>

        <!-- Add Room Form -->
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-door-open me-2"></i>Add New Room</h5>
            </div>
            <div class="card-body">
                <?php if($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                <?php if($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="add_room">
                    <div class="col-md-3">
                        <label class="form-label">Room Name</label>
                        <input type="text" name="room_number" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Room Type</label>
                        <select name="room_type" class="form-select" required>
                            <option value="private">Private</option>
                            <option value="semi_private">Semi-Private</option>
                            <option value="ward">Ward</option>
                            <option value="labor_room">Labor room</option>
                            <option value="delivery_room">Delivery room</option>
                            <option value="surgery_room">Surgery room</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Floor Number</label>
                        <input type="number" name="floor_number" class="form-control" required min="1">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-plus me-2"></i>Add Room
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Rooms List -->
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Manage Rooms & Beds</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach($rooms as $room): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card room-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="card-title mb-0"><?php echo htmlspecialchars($room['room_number']); ?></h5>
                                        <span class="status-badge status-<?php echo $room['status']; ?>">
                                            <?php echo ucfirst($room['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="text-muted mb-1">
                                            <i class="fas fa-building me-1"></i>Floor <?php echo $room['floor_number']; ?>
                                        </div>
                                        <div class="text-muted">
                                            <i class="fas fa-door-closed me-1"></i><?php echo ucfirst(str_replace('_', ' ', $room['room_type'])); ?>
                                        </div>
                                    </div>

                                    <div class="bed-count mb-2">
                                        <div>Total Beds: <?php echo $room['total_beds'] ?: 0; ?></div>
                                        <div class="text-success">Available: <?php echo $room['available_beds'] ?: 0; ?></div>
                                        <div class="text-danger">Occupied: <?php echo $room['occupied_beds'] ?: 0; ?></div>
                                    </div>

                                    <?php if($room['total_beds'] > 0): ?>
                                        <div class="progress">
                                            <?php 
                                            $occupancy_rate = ($room['total_beds'] > 0) ? 
                                                ($room['occupied_beds'] / $room['total_beds']) * 100 : 0;
                                            ?>
                                            <div class="progress-bar bg-danger" role="progressbar" 
                                                 style="width: <?php echo $occupancy_rate; ?>%"
                                                 aria-valuenow="<?php echo $occupancy_rate; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-3">
                                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" 
                                                data-bs-target="#addBedsModal<?php echo $room['id']; ?>">
                                            <i class="fas fa-plus me-1"></i>Add Beds
                                        </button>
                                        <?php if($room['available_beds'] > 0): ?>
                                        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal"
                                                data-bs-target="#removeBedsModal<?php echo $room['id']; ?>">
                                            <i class="fas fa-minus me-1"></i>Remove Beds
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal"
                                                data-bs-target="#deleteRoomModal<?php echo $room['id']; ?>">
                                            <i class="fas fa-trash me-1"></i>Delete Room
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Add Beds Modal -->
                            <div class="modal fade" id="addBedsModal<?php echo $room['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Add Beds to Room <?php echo htmlspecialchars($room['room_number']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="add_beds">
                                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Bed Numbers</label>
                                                    <div class="form-text mb-2">Enter bed numbers separated by commas or new lines</div>
                                                    <textarea name="bed_numbers" class="form-control" rows="3" 
                                                              placeholder="Example:&#10;101&#10;102&#10;103" required></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fas fa-plus me-1"></i>Add Beds
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Remove Beds Modal -->
                            <div class="modal fade" id="removeBedsModal<?php echo $room['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Remove Beds in <?php echo htmlspecialchars($room['room_number']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="remove_beds">
                                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                                <p class="text-muted">Only available beds can be removed.</p>
                                                <div class="mb-2" style="max-height:240px; overflow-y:auto;">
                                                    <?php
                                                    // fetch available beds for this room
                                                    $beds_stmt = $db->prepare('SELECT id, bed_number FROM beds WHERE room_id = :room_id AND status = "available" ORDER BY bed_number');
                                                    $beds_stmt->bindParam(':room_id', $room['id'], PDO::PARAM_INT);
                                                    $beds_stmt->execute();
                                                    $available_beds = $beds_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                    ?>
                                                    <?php if(empty($available_beds)): ?>
                                                        <div class="alert alert-info mb-0">No available beds to remove.</div>
                                                    <?php else: ?>
                                                        <?php foreach($available_beds as $b): ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="bed_ids[]" value="<?php echo $b['id']; ?>" id="bed_<?php echo $b['id']; ?>">
                                                                <label class="form-check-label" for="bed_<?php echo $b['id']; ?>">
                                                                    Bed <?php echo htmlspecialchars($b['bed_number']); ?>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-outline-danger">
                                                    <i class="fas fa-minus me-1"></i>Remove Selected
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Delete Room Modal -->
                            <div class="modal fade" id="deleteRoomModal<?php echo $room['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Delete Room</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="delete_room">
                                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                                <div class="alert alert-warning">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                                    This will permanently delete the room if it has no beds and is not referenced by admissions.
                                                </div>
                                                <p class="mb-0">Are you sure you want to delete <strong><?php echo htmlspecialchars($room['room_number']); ?></strong>?</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-outline-secondary">
                                                    <i class="fas fa-trash me-1"></i>Delete Room
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if(empty($rooms)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>No rooms have been added yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
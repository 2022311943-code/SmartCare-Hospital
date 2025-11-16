<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "config/database.php";

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || $_SESSION['employee_type'] !== 'nurse') {
    header("Location: index.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success_message = $error_message = '';

// Get pending admissions
$pending_query = "SELECT pa.*, p.name as patient_name, p.age, p.gender, u.name AS doctor_name 
                 FROM patient_admissions pa
                 JOIN patients p ON pa.patient_id = p.id
                 LEFT JOIN users u ON pa.admitted_by = u.id
                 WHERE pa.admission_status = 'pending'
                 ORDER BY pa.created_at DESC";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->execute();
$pending_admissions = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get room availability summary by type
$rooms_query = "SELECT 
                r.room_type,
                COUNT(DISTINCT r.id) as total_rooms,
                SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END) as available_beds
                FROM rooms r
                LEFT JOIN beds b ON r.id = b.room_id
                WHERE r.status = 'active'
                GROUP BY r.room_type
                ORDER BY FIELD(r.room_type, 'private','semi_private','ward','labor_room','delivery_room','surgery_room')";
$rooms_stmt = $db->prepare($rooms_query);
$rooms_stmt->execute();
$room_availability = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch individual rooms with available beds
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

// Fetch active transfer requests (ROOM_TRANSFER flagged orders)
$transfer_query = "SELECT do.id as order_id, do.admission_id, p.name as patient_name, p.age, p.gender,
                          r.room_number, b.bed_number, do.order_details, do.ordered_at
                   FROM doctors_orders do
                   JOIN patient_admissions pa ON do.admission_id = pa.id
                   JOIN patients p ON pa.patient_id = p.id
                   LEFT JOIN rooms r ON pa.room_id = r.id
                   LEFT JOIN beds b ON pa.bed_id = b.id
                   WHERE do.status IN ('active','in_progress')
                     AND do.special_instructions = 'ROOM_TRANSFER'";
$transfer_stmt = $db->prepare($transfer_query);
$transfer_stmt->execute();
$transfer_orders = $transfer_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Room - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .room-type-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .room-type-card:hover {
            background-color: #f8f9fa;
            transform: translateY(-2px);
        }
        .room-type-card.selected {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .room-type-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col d-flex justify-content-between align-items-center">
                <h2 class="mb-0"><i class="fas fa-bed me-2"></i>Assign Room</h2>
                <a href="dashboard.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>
        </div>

        <?php if (!empty($transfer_orders)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title"><i class="fas fa-exchange-alt me-2"></i>Pending Room Transfer Requests</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Requested</th>
                                <th>Patient</th>
                                <th>Current Room/Bed</th>
                                <th>Details</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($transfer_orders as $t): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($t['ordered_at'])); ?></td>
                                <td><?php echo htmlspecialchars($t['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars(($t['room_number'] ?: '-') . ' / ' . ($t['bed_number'] ?: '-')); ?></td>
                                <td><?php echo nl2br(htmlspecialchars($t['order_details'])); ?></td>
                                <td>
                                    <button class="btn btn-outline-primary btn-sm" onclick='openTransferExec(<?php echo json_encode($t, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>)'>
                                        <i class="fas fa-random me-1"></i>Execute Transfer
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

        <?php if (empty($pending_admissions)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No pending admissions found.
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Pending Admissions</h5>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Patient Name</th>
                                    <th>Age/Gender</th>
                                    <th>Admission Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_admissions as $admission): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($admission['patient_name']); ?></td>
                                        <td>
                                            <?php echo $admission['age'] . 'y / ' . ucfirst($admission['gender']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y H:i', strtotime($admission['created_at'])); ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-primary btn-sm" 
                                                    onclick="showAssignRoomModal(<?php echo htmlspecialchars(json_encode($admission)); ?>)">
                                                <i class="fas fa-bed me-1"></i>Assign Room
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

    <!-- Assign Room Modal -->
    <div class="modal fade" id="assignRoomModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="assignRoomForm" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Room</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="admission_id" id="admissionId">
                        <input type="hidden" name="room_type" id="selectedRoomType">
                        <input type="hidden" name="room_id" id="selectedRoomId">
                        
                        <div class="mb-3 p-3 border rounded bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong>Patient</strong>
                                <span id="patientName" class="fw-bold text-dark"></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <strong>Admitting Doctor</strong>
                                <span id="doctorName" class="text-muted"></span>
                            </div>
                            <div class="mt-2">
                                <strong>Initial Instructions / Orders</strong>
                                <div id="initialOrders" class="small text-muted">
                                    <em>No initial instructions provided.</em>
                                </div>
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
                                <div class="room-type-card" onclick="selectRoom('<?php echo $room['id']; ?>','<?php echo $room['room_type']; ?>', this)">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="mb-1"><?php echo htmlspecialchars($room['room_number']); ?></h5>
                                            <p class="mb-0 text-muted">
                                                <?php echo $typeLabel; ?> • Floor <?php echo (int)$room['floor_number']; ?> • <?php echo (int)$room['available_beds']; ?> bed(s) available
                                            </p>
                                        </div>
                                        <div class="text-success">
                                            <i class="fas fa-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($available_rooms)): ?>
                                <div class="alert alert-info mb-0">No available beds in any room.</div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Execute Transfer Modal -->
    <div class="modal fade" id="transferExecModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="transferExecForm" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Execute Room Transfer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="admission_id" id="tx_admission_id">
                        <input type="hidden" name="order_id" id="tx_order_id">
                        <input type="hidden" name="room_id" id="tx_room_id">
                        <div class="mb-3 p-3 border rounded bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong>Patient</strong>
                                <span id="tx_patient_name" class="fw-bold text-dark"></span>
                            </div>
                            <div class="small text-muted" id="tx_details"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select New Room</label>
                            <?php foreach ($available_rooms as $room): ?>
                                <div class="room-type-card" onclick="selectTxRoom('<?php echo $room['id']; ?>', this)">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="mb-1"><?php echo htmlspecialchars($room['room_number']); ?></h5>
                                            <p class="mb-0 text-muted">
                                                <?php echo ucwords(str_replace('_',' ', $room['room_type'])); ?> • Floor <?php echo (int)$room['floor_number']; ?> • <?php echo (int)$room['available_beds']; ?> bed(s) available
                                            </p>
                                        </div>
                                        <div class="text-success">
                                            <i class="fas fa-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($available_rooms)): ?>
                                <div class="alert alert-info mb-0">No available beds in any room.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Transfer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let assignRoomModal;
        let selectedCard = null;
        let transferExecModal;
        let txSelectedCard = null;

        document.addEventListener('DOMContentLoaded', function() {
            assignRoomModal = new bootstrap.Modal(document.getElementById('assignRoomModal'));
            transferExecModal = new bootstrap.Modal(document.getElementById('transferExecModal'));

            document.getElementById('assignRoomForm').addEventListener('submit', function(e) {
                e.preventDefault();
                if (!document.getElementById('selectedRoomId').value) {
                    alert('Please select a room');
                    return;
                }
                const formData = new FormData(this);
                fetch('process_room_assignment.php', { method: 'POST', body: formData })
                  .then(response => response.json())
                  .then(data => { if (data.success) { location.reload(); } else { alert(data.error || 'Error assigning room'); }})
                  .catch(() => alert('Error processing request'));
            });

            document.getElementById('transferExecForm').addEventListener('submit', function(e) {
                e.preventDefault();
                if (!document.getElementById('tx_room_id').value) {
                    alert('Please select a destination room');
                    return;
                }
                const formData = new FormData(this);
                fetch('process_room_transfer.php', { method: 'POST', body: formData })
                  .then(r => r.json())
                  .then(data => { if (data.success) { location.reload(); } else { alert(data.error || 'Error executing transfer'); } })
                  .catch(() => alert('Network error'));
            });
        });

        function showAssignRoomModal(admission) {
            document.getElementById('admissionId').value = admission.id;
            document.getElementById('selectedRoomType').value = '';
            document.getElementById('selectedRoomId').value = '';
            if (selectedCard) {
                selectedCard.classList.remove('selected');
            }
            selectedCard = null;
            // Populate doctor and initial orders
            const gender = admission.gender ? (admission.gender.charAt(0).toUpperCase() + admission.gender.slice(1)) : '-';
            document.getElementById('patientName').textContent = (admission.patient_name || '-') + (admission.age ? ` (${admission.age}y / ${gender})` : '');
            document.getElementById('doctorName').textContent = admission.doctor_name || '-';
            const initial = admission.admission_notes ? admission.admission_notes.trim() : '';
            document.getElementById('initialOrders').innerHTML = initial ? initial.replace(/\n/g, '<br>') : '<em>No initial instructions provided.</em>';
            assignRoomModal.show();
        }

        function selectRoom(roomId, roomType, card) {
            if (selectedCard) {
                selectedCard.classList.remove('selected');
            }
            card.classList.add('selected');
            selectedCard = card;
            document.getElementById('selectedRoomType').value = roomType;
            document.getElementById('selectedRoomId').value = roomId;
        }

        function openTransferExec(t) {
            document.getElementById('tx_admission_id').value = t.admission_id;
            document.getElementById('tx_order_id').value = t.order_id;
            document.getElementById('tx_room_id').value = '';
            document.getElementById('tx_patient_name').textContent = t.patient_name;
            document.getElementById('tx_details').textContent = (t.order_details || '').replace(/\n/g,' ');
            if (txSelectedCard) { txSelectedCard.classList.remove('selected'); }
            txSelectedCard = null;
            transferExecModal.show();
        }
        function selectTxRoom(roomId, card) {
            if (txSelectedCard) { txSelectedCard.classList.remove('selected'); }
            txSelectedCard = card; card.classList.add('selected');
            document.getElementById('tx_room_id').value = roomId;
        }
    </script>
</body>
</html> 
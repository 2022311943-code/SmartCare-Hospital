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

// Get all rooms with their beds and patient information
$query = "SELECT 
            r.*,
            b.id as bed_id,
            b.bed_number,
            b.status as bed_status,
            pa.id as admission_id,
            p.name as patient_name,
            p.age,
            p.gender,
            COALESCE(pa.admission_date, pa.updated_at, pa.created_at) as admission_datetime,
            u.name as doctor_name
          FROM rooms r
          LEFT JOIN beds b ON r.id = b.room_id
          LEFT JOIN patient_admissions pa ON (b.id = pa.bed_id AND pa.admission_status = 'admitted')
          LEFT JOIN patients p ON pa.patient_id = p.id
          LEFT JOIN users u ON p.doctor_id = u.id
          ORDER BY r.floor_number, r.room_number, b.bed_number";

$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize results by room
$rooms = [];
foreach ($results as $row) {
    if (!isset($rooms[$row['id']])) {
        $rooms[$row['id']] = [
            'id' => $row['id'],
            'room_number' => $row['room_number'],
            'room_type' => $row['room_type'],
            'floor_number' => $row['floor_number'],
            'status' => $row['status'],
            'beds' => []
        ];
    }
    $rooms[$row['id']]['beds'][] = [
        'bed_id' => $row['bed_id'],
        'bed_number' => $row['bed_number'],
        'bed_status' => $row['bed_status'],
        'patient_name' => $row['patient_name'],
        'patient_age' => $row['age'],
        'patient_gender' => $row['gender'],
        'admission_id' => $row['admission_id'],
        'admission_datetime' => $row['admission_datetime'],
        'doctor_name' => $row['doctor_name']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Dashboard - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .page-header {
            background: white;
            padding: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .floor-section {
            margin-bottom: 40px;
        }
        .floor-title {
            color: #2c3e50;
            font-size: 1.5rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dc3545;
        }
        .room-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .room-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .room-type-badge {
            font-size: 0.8rem;
            padding: 3px 8px;
            border-radius: 12px;
            background: #e9ecef;
            color: #495057;
        }
        .bed-container {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        .bed-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #dee2e6;
            transition: transform 0.2s;
        }
        .bed-card:hover {
            transform: translateY(-2px);
        }
        .bed-card.available {
            border-left: 4px solid #28a745;
        }
        .bed-card.occupied {
            border-left: 4px solid #dc3545;
        }
        .bed-card.maintenance {
            border-left: 4px solid #ffc107;
        }
        .bed-number {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .bed-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .bed-status.available {
            background: #d4edda;
            color: #155724;
        }
        .bed-status.occupied {
            background: #f8d7da;
            color: #721c24;
        }
        .bed-status.maintenance {
            background: #fff3cd;
            color: #856404;
        }
        .patient-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            font-size: 0.9rem;
        }
        .patient-info label {
            color: #6c757d;
            font-size: 0.8rem;
            margin-bottom: 2px;
        }
        .patient-info p {
            margin-bottom: 8px;
            color: #2c3e50;
        }
        .back-button {
            padding: 8px 16px;
            border-radius: 20px;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="mb-0">
                    <i class="fas fa-door-open me-2"></i>Room Dashboard
                </h2>
                <a href="dashboard.php" class="btn btn-light btn-sm back-button">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <?php
        // Group rooms by floor
        $floors = [];
        foreach ($rooms as $room) {
            $floors[$room['floor_number']][] = $room;
        }
        ksort($floors);

        foreach ($floors as $floor_number => $floor_rooms):
        ?>
            <div class="floor-section">
                <h3 class="floor-title">
                    <i class="fas fa-building me-2"></i>Floor <?php echo $floor_number; ?>
                </h3>
                
                <div class="row">
                    <?php foreach ($floor_rooms as $room): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="room-card">
                                <div class="room-header">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="mb-0">Room <?php echo htmlspecialchars($room['room_number']); ?></h5>
                                            <span class="room-type-badge">
                                                <?php echo ucfirst(str_replace('_', ' ', $room['room_type'])); ?>
                                            </span>
                                        </div>
                                        <span class="badge bg-<?php echo $room['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($room['status']); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="bed-container">
                                    <?php foreach ($room['beds'] as $bed): ?>
                                        <div class="bed-card <?php echo $bed['bed_status']; ?>">
                                            <div class="bed-number">
                                                Bed <?php echo htmlspecialchars($bed['bed_number']); ?>
                                            </div>
                                            <span class="bed-status <?php echo $bed['bed_status']; ?>">
                                                <?php echo ucfirst($bed['bed_status']); ?>
                                            </span>
                                            <?php if (isset($_SESSION['employee_type']) && $_SESSION['employee_type'] === 'admin_staff' && !empty($bed['bed_id'])): ?>
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-copy-bed-link" data-bed-id="<?php echo intval($bed['bed_id']); ?>" title="Copy Bed Link">
                                                    <i class="fas fa-link"></i>
                                                </button>
                                            </div>
                                            <?php endif; ?>

                                            <?php if ($bed['bed_status'] === 'occupied' && $bed['patient_name']): ?>
                                                <div class="patient-info">
                                                    
                                                    <p class="mb-2 d-flex justify-content-between align-items-center">
                                                        <span>
                                                        <?php echo htmlspecialchars($bed['patient_name']); ?>
                                                        <small class="d-block text-muted">
                                                            <?php echo $bed['patient_age']; ?>y/<?php echo ucfirst($bed['patient_gender']); ?>
                                                        </small>
                                                        </span>
                                                        <?php if (!empty($bed['admission_id'])): ?>
                                                        <a href="admission_profile.php?admission_id=<?php echo intval($bed['admission_id']); ?>" class="btn btn-sm btn-outline-primary" title="View Admission Profile">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </p>

                                                    <label>Doctor</label>
                                                    <p class="mb-2"><?php echo htmlspecialchars($bed['doctor_name']); ?></p>

                                                    <label>Admitted</label>
                                                    <p class="mb-0">
                                                        <?php echo date('M d, Y h:i A', strtotime($bed['admission_datetime'])); ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function postJSON(url, payload) {
                return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(r => r.json());
            }
            document.querySelectorAll('.btn-copy-bed-link').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bedId = Number(btn.getAttribute('data-bed-id'));
                    const original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    postJSON('generate_bed_profile_token.php', { bed_id: bedId })
                      .then(data => {
                         if (!data.success) throw new Error(data.message || 'Failed');
                         const base = window.location.href.substring(0, window.location.href.lastIndexOf('/'));
                         const url = base + '/bed_view.php?token=' + data.token;
                         return navigator.clipboard.writeText(url).then(() => url);
                      })
                      .then(() => {
                         btn.innerHTML = '<i class="fas fa-check"></i>';
                         setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 1200);
                      })
                      .catch(err => {
                         alert(err.message || 'Unable to copy link');
                         btn.innerHTML = original;
                         btn.disabled = false;
                      });
                });
            });
        });
        </script>
    </body>
</html> 
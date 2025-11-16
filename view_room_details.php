<?php
session_start();
require_once "config/database.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Check if room ID is provided
if (!isset($_GET['id'])) {
    header("Location: room_dashboard.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get room details
$room_query = "SELECT r.*, 
               COUNT(b.id) as total_beds,
               SUM(CASE WHEN b.status = 'occupied' THEN 1 ELSE 0 END) as occupied_beds
               FROM rooms r
               LEFT JOIN beds b ON r.id = b.room_id
               WHERE r.id = :room_id
               GROUP BY r.id";
$room_stmt = $db->prepare($room_query);
$room_stmt->bindParam(":room_id", $_GET['id']);
$room_stmt->execute();
$room = $room_stmt->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    header("Location: room_dashboard.php");
    exit();
}

// Get bed details with patient information
$bed_query = "SELECT b.*, 
              pa.id as admission_id,
              p.name as patient_name,
              p.age as patient_age,
              p.gender as patient_gender,
              u.name as admitted_by_name,
              pa.admission_date,
              pa.admission_notes,
              doc.name as doctor_name,
              doc.employee_type as doctor_type,
              doc.department as doctor_department
              FROM beds b
              LEFT JOIN patient_admissions pa ON b.id = pa.bed_id AND pa.admission_status = 'admitted'
              LEFT JOIN patients p ON pa.patient_id = p.id
              LEFT JOIN users u ON pa.admitted_by = u.id
              LEFT JOIN users doc ON p.doctor_id = doc.id
              WHERE b.room_id = :room_id
              ORDER BY b.bed_number";
$bed_stmt = $db->prepare($bed_query);
$bed_stmt->bindParam(":room_id", $_GET['id']);
$bed_stmt->execute();
$beds = $bed_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Details - Hospital Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
            padding: 20px;
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
        .back-link {
            color: #6c757d;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: #dc3545;
        }
        .room-info {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .room-info h3 {
            color: #2c3e50;
            margin-bottom: 15px;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .info-value {
            color: #2c3e50;
            font-weight: 500;
        }
        .bed-card {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .bed-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .bed-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .bed-number {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
        }
        .bed-status {
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
        .patient-info {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        .patient-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .patient-details {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .progress {
            height: 8px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="room_dashboard.php" class="back-link">
            <i class="fas fa-arrow-left me-2"></i>Back to Room Dashboard
        </a>

        <!-- Room Information -->
        <div class="room-info">
            <div class="row">
                <div class="col-md-8">
                    <h3>
                        <i class="fas fa-door-open me-2"></i>
                        Room <?php echo htmlspecialchars($room['room_number']); ?>
                    </h3>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="info-item">
                                <div class="info-label">Room Type</div>
                                <div class="info-value">
                                    <?php echo ucfirst(str_replace('_', ' ', $room['room_type'])); ?> Room
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-item">
                                <div class="info-label">Floor</div>
                                <div class="info-value">Floor <?php echo $room['floor_number']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-item">
                                <div class="info-label">Status</div>
                                <div class="info-value">
                                    <span class="bed-status status-<?php echo $room['status']; ?>">
                                        <?php echo ucfirst($room['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-item">
                        <div class="info-label">Bed Occupancy</div>
                        <div class="info-value">
                            <?php echo $room['occupied_beds']; ?> / <?php echo $room['total_beds']; ?> Beds Occupied
                        </div>
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
                    </div>
                </div>
            </div>
        </div>

        <!-- Bed List -->
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-bed me-2"></i>Bed List</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach($beds as $bed): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="bed-card">
                                <div class="bed-header">
                                    <div class="bed-number">
                                        Bed <?php echo htmlspecialchars($bed['bed_number']); ?>
                                    </div>
                                    <span class="bed-status status-<?php echo $bed['status']; ?>">
                                        <?php echo ucfirst($bed['status']); ?>
                                    </span>
                                </div>
                                
                                <?php if($bed['status'] === 'occupied' && $bed['patient_name']): ?>
                                    <div class="patient-info">
                                        <div class="patient-name">
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($bed['patient_name']); ?>
                                        </div>
                                        <div class="patient-details">
                                            <div>
                                                <i class="fas fa-birthday-cake me-1"></i>
                                                Age: <?php echo htmlspecialchars($bed['patient_age']); ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-venus-mars me-1"></i>
                                                Gender: <?php echo ucfirst(htmlspecialchars($bed['patient_gender'])); ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                Admitted: <?php echo date('M d, Y', strtotime($bed['admission_date'])); ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-user-nurse me-1"></i>
                                                By: <?php echo htmlspecialchars($bed['admitted_by_name']); ?>
                                            </div>
                                            <?php if($bed['admission_notes']): ?>
                                                <div class="mt-2">
                                                    <i class="fas fa-sticky-note me-1"></i>
                                                    Notes: <?php echo htmlspecialchars($bed['admission_notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if($bed['doctor_name']): ?>
                                                <div class="mt-2">
                                                    <i class="fas fa-user-md me-1"></i>
                                                    Doctor: <?php echo htmlspecialchars($bed['doctor_name']); ?>
                                                    <div class="ms-4 text-muted small">
                                                        <div><?php echo ucfirst(htmlspecialchars($bed['doctor_type'])); ?></div>
                                                        <div>Department: <?php echo htmlspecialchars($bed['doctor_department']); ?></div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
<?php
session_start();
require_once "config/database.php";

try {
    $database = new Database();
    $db = $database->getConnection();

    // Check current ENUM definition
    $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'rooms' 
              AND COLUMN_NAME = 'room_type'";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $desiredEnum = "enum('private','semi_private','ward','labor_room','delivery_room','surgery_room')";

    $needsAlter = false;
    if ($row && isset($row['COLUMN_TYPE'])) {
        $current = strtolower($row['COLUMN_TYPE']);
        $needsAlter = (strpos($current, "labor_room") === false) || (strpos($current, "delivery_room") === false) || (strpos($current, "surgery_room") === false);
    } else {
        // Column missing or unknown; attempt to alter anyway
        $needsAlter = true;
    }

    if ($needsAlter) {
        $alter = "ALTER TABLE rooms MODIFY COLUMN room_type $desiredEnum NOT NULL";
        $db->exec($alter);
        echo "Updated rooms.room_type enum successfully.";
    } else {
        echo "rooms.room_type enum already up to date.";
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . htmlspecialchars($e->getMessage());
} 
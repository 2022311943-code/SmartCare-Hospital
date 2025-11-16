<?php
session_start();
require_once "config/database.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized access']));
}

$database = new Database();
$db = $database->getConnection();

if (isset($_GET['term'])) {
    $search = $_GET['term'];
    
    // Search in opd_visits table for unique patients
    $query = "SELECT DISTINCT patient_name, age, gender, contact_number, address 
              FROM opd_visits 
              WHERE patient_name LIKE :search 
              ORDER BY patient_name 
              LIMIT 10";
              
    $stmt = $db->prepare($query);
    $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
    $stmt->execute();
    
    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $results[] = [
            'id' => uniqid(), // Unique identifier for the result
            'patient_name' => $row['patient_name'],
            'age' => $row['age'],
            'gender' => $row['gender'],
            'contact_number' => $row['contact_number'],
            'address' => $row['address']
        ];
    }
    
    echo json_encode($results);
} 
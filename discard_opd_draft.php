<?php
session_start();
require_once "config/database.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $stmt = $db->prepare("DELETE FROM opd_registration_drafts WHERE user_id = :uid");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
} catch (Exception $e) { /* ignore */ }

header("Location: opd_queue.php");
exit();
?> 


<?php
session_start();
require_once "config/database.php";

// Clear all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Clear remember-me cookie and token
$REMEMBER_COOKIE_SELECTOR = 'hms_rm_sel';
$REMEMBER_COOKIE_VALIDATOR = 'hms_rm_val';

try {
    if (isset($_COOKIE[$REMEMBER_COOKIE_SELECTOR])) {
        $selector = $_COOKIE[$REMEMBER_COOKIE_SELECTOR];
        $database = new Database();
        $db = $database->getConnection();
        $stmt = $db->prepare("DELETE FROM auth_remember_tokens WHERE selector = :sel");
        $stmt->execute([':sel' => $selector]);
    }
} catch (Exception $e) { /* ignore */ }

// Clear cookies
if (isset($_COOKIE[$REMEMBER_COOKIE_SELECTOR])) {
    setcookie($REMEMBER_COOKIE_SELECTOR, '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true, 'samesite' => 'Lax'
    ]);
}
if (isset($_COOKIE[$REMEMBER_COOKIE_VALIDATOR])) {
    setcookie($REMEMBER_COOKIE_VALIDATOR, '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true, 'samesite' => 'Lax'
    ]);
}

// Redirect to login page
header("Location: index.php");
exit();
?> 
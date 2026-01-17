<?php
// Disable error reporting to prevent warnings in JSON output
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';

// Start session and destroy it
session_start();
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

echo json_encode(array("success" => true, "message" => "Logged out successfully"));
?>

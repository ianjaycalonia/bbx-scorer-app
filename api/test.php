<?php
// Disable error reporting to prevent warnings in JSON output
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/cors.php';

// Test endpoint - always returns valid JSON
echo json_encode([
    "success" => true, 
    "message" => "API test endpoint working correctly",
    "timestamp" => date('Y-m-d H:i:s')
]);
?>

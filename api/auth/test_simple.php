<?php
// Simple test to see if PHP is working
header('Content-Type: application/json');
echo json_encode(array(
    'success' => true,
    'message' => 'API is working!',
    'file' => __FILE__,
    'method' => $_SERVER['REQUEST_METHOD']
));
?>

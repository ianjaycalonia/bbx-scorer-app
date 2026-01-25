<?php
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    // Get all users
    $sql = "SELECT id, email, blader_name, display_name, avatar_url, created_at FROM users ORDER BY display_name, blader_name, email";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $db->error);
    }
    
    $stmt->execute();
    
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    $response = [
        'success' => true,
        'users' => $users,
        'count' => count($users)
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ];
    
    echo json_encode($response);
}
?>

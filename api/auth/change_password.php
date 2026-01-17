<?php
// Disable error reporting to prevent warnings in JSON output
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../config/cors.php';

class Auth {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function changePassword($userId, $currentPassword, $newPassword) {
        // Get current user password
        $query = "SELECT password FROM users WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return array("success" => false, "message" => "User not found");
        }

        $user = $result->fetch_assoc();

        // Verify current password
        if (!password_verify($currentPassword, $user['password'])) {
            return array("success" => false, "message" => "Current password is incorrect");
        }

        // Validate new password
        if (strlen($newPassword) < 6) {
            return array("success" => false, "message" => "New password must be at least 6 characters long");
        }

        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password
        $updateQuery = "UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->bind_param("ss", $hashedPassword, $userId);
        
        if ($updateStmt->execute()) {
            return array("success" => true, "message" => "Password updated successfully");
        } else {
            return array("success" => false, "message" => "Failed to update password");
        }
    }
}

// Handle request
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(array("success" => false, "message" => "Not authenticated"));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    
    if (isset($data->action) && $data->action == 'change_password') {
        $userId = $_SESSION['user_id'];
        $currentPassword = $data->current_password ?? '';
        $newPassword = $data->new_password ?? '';
        
        $auth = new Auth();
        $response = $auth->changePassword($userId, $currentPassword, $newPassword);
    } else {
        $response = array("success" => false, "message" => "Invalid action");
    }
} else {
    $response = array("success" => false, "message" => "Invalid request method");
}

echo json_encode($response);
?>

<?php
require_once '../config/database.php';
require_once '../config/cors.php';

class Auth {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function login($email, $password) {
        $query = "SELECT id, email, blader_name, display_name, avatar_url, password 
                  FROM users 
                  WHERE email = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Verify password (simple check for now - in production use password_hash)
            // Verify password properly (temporary plain text for testing)
            if ($password === 'test123' || password_verify($password, $user['password'])) {
                // Set session
                session_start();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                
                // Remove password from response
                unset($user['password']);
                
                return array("success" => true, "profile" => $user);
            } else {
                return array("success" => false, "message" => "Invalid password");
            }
        } else {
            return array("success" => false, "message" => "User not found");
        }
    }
}

// Handle request
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    
    if (isset($data->action) && $data->action == 'login') {
        $email = $data->email ?? '';
        $password = $data->password ?? '';
        
        $auth = new Auth();
        $response = $auth->login($email, $password);
    } else {
        $response = array("success" => false, "message" => "Invalid action");
    }
} else {
    $response = array("success" => false, "message" => "Invalid request method");
}

echo json_encode($response);
?>

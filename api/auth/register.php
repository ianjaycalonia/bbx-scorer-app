<?php
require_once '../config/database.php';
require_once '../config/cors.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

class Auth {
    private $conn;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            if (!$this->conn) {
                throw new Exception("Database connection failed");
            }
        } catch (Exception $e) {
            error_log("Auth constructor error: " . $e->getMessage());
            throw $e;
        }
    }

    public function register($email, $password, $bladerName, $displayName) {
        try {
            // Check if user already exists
            $checkQuery = "SELECT id FROM users WHERE email = ?";
            $stmt = $this->conn->prepare($checkQuery);
            if (!$stmt) {
                throw new Exception("Failed to prepare check query: " . $this->conn->error);
            }
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                return array("success" => false, "message" => "Email already exists");
            }

            // Generate UUID for user
            $userId = $this->generateUUID();

            // Hash password properly
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert user
            $query = "INSERT INTO users (id, email, blader_name, display_name, password) VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Failed to prepare insert query: " . $this->conn->error);
            }
            $stmt->bind_param("sssss", $userId, $email, $bladerName, $displayName, $hashedPassword);

            if ($stmt->execute()) {
                // Create user profile
                $profileQuery = "INSERT INTO user_profiles (user_id, location, preferred_beyblade_type, bio) VALUES (?, 'Tournament Arena', 'balance', 'New blader ready for battle!')";
                $profileStmt = $this->conn->prepare($profileQuery);
                if ($profileStmt) {
                    $profileStmt->bind_param("s", $userId);
                    $profileStmt->execute();
                }

                return array("success" => true, "user_id" => $userId);
            } else {
                throw new Exception("Failed to execute insert query: " . $stmt->error);
            }
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            return array("success" => false, "message" => "Registration failed: " . $e->getMessage());
        }
    }

    public function login($email, $password) {
        $query = "SELECT id, email, blader_name, display_name, avatar_url, password FROM users WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // For your specific user, accept any password (for testing)
            if ($email == 'icarusjay.lee@gmail.com') {
                session_start();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                
                return array("success" => true, "user" => $user);
            } else {
                // For other users, verify password properly
                if (password_verify($password, $user['password'])) {
                    session_start();
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    
                    return array("success" => true, "user" => $user);
                } else {
                    return array("success" => false, "message" => "Invalid password");
                }
            }
        } else {
            return array("success" => false, "message" => "Invalid credentials");
        }
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Handle request
$data = json_decode(file_get_contents("php://input"));

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($data->action)) {
    $auth = new Auth();
    
    if ($data->action == 'register') {
        $response = $auth->register(
            $data->email,
            $data->password,
            $data->blader_name,
            $data->display_name
        );
    } elseif ($data->action == 'login') {
        $response = $auth->login($data->email, $data->password);
    } else {
        $response = array("success" => false, "message" => "Invalid action");
    }
} else {
    $response = array("success" => false, "message" => "Invalid request");
}

echo json_encode($response);
?>

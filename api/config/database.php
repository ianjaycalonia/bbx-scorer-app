<?php
// Database Configuration
class Database {
    private $host;
    private $username;
    private $password;
    private $database;
    private $conn;

    public function __construct() {
        // Use environment variables from .htaccess for security
        $this->host = $_ENV['DB_HOST'] ?? 'localhost';
        $this->username = $_ENV['DB_USER'] ?? 'root';
        $this->password = $_ENV['DB_PASS'] ?? '';
        $this->database = $_ENV['DB_NAME'] ?? 'blader_db';
    }

    public function getConnection() {
        // Disable error reporting to prevent warnings in JSON output
        error_reporting(0);
        ini_set('display_errors', 0);
        
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->conn = null;

        try {
            $this->conn = new mysqli(
                $this->host, 
                $this->username, 
                $this->password, 
                $this->database
            );
            
            $this->conn->set_charset("utf8mb4");
            
            return $this->conn;
        } catch (mysqli_sql_exception $e) {
            // Return a proper error response instead of throwing exception
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit();
        }
    }
}
?>

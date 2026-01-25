<?php
// Database Configuration
class Database
{
    private $host;
    private $username;
    private $password;
    private $database;
    private $conn;

    public function __construct()
    {
        // Auto-detect environment and use appropriate database
        $this->detectEnvironment();
    }

    private function detectEnvironment()
    {
        // Check if we're in production environment
        $isProduction = $this->isProductionEnvironment();

        if ($isProduction) {
            // Production settings
            $this->host = 'mysql8003.site4now.net';
            $this->username = 'ac41df_xscore';
            $this->password = 'NewXsc0re!';
            $this->database = 'db_ac41df_xscore';
            error_log("Database Connection: PRODUCTION -> " . $this->database);
        } else {
            // Local development settings
            $this->host = $_ENV['DB_HOST'] ?? 'localhost';
            $this->username = $_ENV['DB_USER'] ?? 'root';
            $this->password = $_ENV['DB_PASS'] ?? '';
            $this->database = $_ENV['DB_NAME'] ?? 'XScore';
            error_log("Database Connection: LOCAL -> " . $this->database);
        }
    }

    private function isProductionEnvironment()
    {
        // Check various indicators of production environment

        // First check: Is this localhost?
        $isLocalhost = (
            (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', 'localhost:80'])) ||
            (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'])) ||
            (isset($_SERVER['SERVER_ADDR']) && in_array($_SERVER['SERVER_ADDR'], ['127.0.0.1', '::1']))
        );

        // If clearly localhost, return false (not production)
        if ($isLocalhost) {
            error_log("Environment Detection: LOCALHOST detected");
            return false;
        }

        // Check for production domain indicators
        $isProductionDomain = isset($_SERVER['HTTP_HOST']) && (
            strpos($_SERVER['HTTP_HOST'], 'site4now.net') !== false ||
            strpos($_SERVER['HTTP_HOST'], 'stempurl.com') !== false ||
            strpos($_SERVER['HTTP_HOST'], 'xscore.com') !== false
        );

        if ($isProductionDomain) {
            error_log("Environment Detection: PRODUCTION domain detected");
            return true;
        }

        // Default to local for safety
        error_log("Environment Detection: Defaulting to LOCAL");
        return false;
    }

    public function getConnection()
    {
        // Suppress native PHP error display to prevent breaking JSON output
        error_reporting(E_ALL);
        ini_set('display_errors', 0);

        $this->conn = null;

        // Check if mysqli is even available
        if (!extension_loaded('mysqli')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'PHP mysqli extension is not loaded on this server',
                'debug_info' => [
                    'php_version' => PHP_VERSION,
                    'is_production' => $this->isProductionEnvironment() ? 'YES' : 'NO',
                    'host_header' => $_SERVER['HTTP_HOST'] ?? 'NOT_SET'
                ]
            ]);
            exit();
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
            // Log detailed error for debugging
            error_log("Database Connection Error: " . $e->getMessage());

            // Return a VERY DETAILED error response for debugging
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed',
                'error' => $e->getMessage(),
                'debug_info' => [
                    'environment' => $this->isProductionEnvironment() ? 'PRODUCTION' : 'LOCAL',
                    'attempted_host' => $this->host,
                    'attempted_user' => $this->username,
                    'attempted_db' => $this->database,
                    'php_version' => PHP_VERSION,
                    'server_host' => $_SERVER['HTTP_HOST'] ?? 'N/A',
                    'server_name' => $_SERVER['SERVER_NAME'] ?? 'N/A',
                    'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'N/A'
                ]
            ]);
            exit();
        }
    }
}
?>
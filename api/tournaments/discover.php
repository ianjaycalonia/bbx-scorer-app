<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once dirname(__DIR__) . '/config/database.php';
    session_start();

    class TournamentDiscovery
    {
        private $conn;

        public function __construct($database)
        {
            $this->conn = $database->getConnection();
            if (!$this->conn) {
                throw new Exception('Database connection failed');
            }
        }

        /**
         * Find tournament by code
         */
        public function findByCode($code)
        {
            try {
                // Validate code format (8 alphanumeric characters)
                if (!preg_match('/^[A-Z0-9]{8}$/', $code)) {
                    return ['success' => false, 'message' => 'Invalid tournament code format'];
                }

                $sql = "SELECT t.*, u.display_name as creator_name 
                        FROM tournaments t 
                        LEFT JOIN users u ON t.created_by = u.id 
                        WHERE t.tournament_code = ?";

                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $code);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament) {
                    return ['success' => false, 'message' => 'Tournament not found'];
                }

                // Get participant count
                $sql = "SELECT COUNT(*) as count FROM tournament_roles 
                        WHERE tournament_id = ? AND FIND_IN_SET('player', role) > 0";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournament['id']);
                $stmt->execute();
                $participantCount = $stmt->get_result()->fetch_assoc()['count'];

                $tournament['participant_count'] = $participantCount;

                return [
                    'success' => true,
                    'tournament' => $tournament
                ];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        /**
         * Find tournament by slug
         */
        public function findBySlug($slug)
        {
            try {
                // Validate slug format (lowercase alphanumeric with hyphens)
                if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                    return ['success' => false, 'message' => 'Invalid tournament slug format'];
                }

                $sql = "SELECT t.*, u.display_name as creator_name 
                        FROM tournaments t 
                        LEFT JOIN users u ON t.created_by = u.id 
                        WHERE t.tournament_slug = ?";

                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $slug);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament) {
                    return ['success' => false, 'message' => 'Tournament not found'];
                }

                // Get participant count
                $sql = "SELECT COUNT(*) as count FROM tournament_roles 
                        WHERE tournament_id = ? AND FIND_IN_SET('player', role) > 0";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournament['id']);
                $stmt->execute();
                $participantCount = $stmt->get_result()->fetch_assoc()['count'];

                $tournament['participant_count'] = $participantCount;

                return [
                    'success' => true,
                    'tournament' => $tournament
                ];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
    }

    // Handle API requests
    $database = new Database();
    $discovery = new TournamentDiscovery($database);

    $method = $_SERVER['REQUEST_METHOD'];
    $response = ['success' => false, 'message' => 'Unknown error occurred'];

    if ($method === 'GET') {
        $code = $_GET['code'] ?? null;
        $slug = $_GET['slug'] ?? null;

        if ($code) {
            $response = $discovery->findByCode($code);
        } elseif ($slug) {
            $response = $discovery->findBySlug($slug);
        } else {
            $response = ['success' => false, 'message' => 'Either code or slug parameter is required'];
        }
    } else {
        http_response_code(405);
        $response = ['success' => false, 'message' => 'Method not allowed'];
    }

} catch (Throwable $e) {
    error_log('Unhandled exception in discover.php: ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ];
}

ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($response);
?>
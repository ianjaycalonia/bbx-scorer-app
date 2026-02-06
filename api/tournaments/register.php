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

    class PlayerRegistration
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
         * Register player for tournament
         */
        public function registerPlayer($tournamentId, $userId)
        {
            try {
                // Verify tournament exists and is open for registration
                $sql = "SELECT id, status, max_participants FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament) {
                    return ['success' => false, 'message' => 'Tournament not found'];
                }

                if ($tournament['status'] !== 'upcoming') {
                    return ['success' => false, 'message' => 'Tournament registration is closed'];
                }

                // Check capacity
                $sql = "SELECT COUNT(*) as count FROM tournament_roles 
                        WHERE tournament_id = ? AND FIND_IN_SET('player', role) > 0";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $participantCount = $stmt->get_result()->fetch_assoc()['count'];

                if ($tournament['max_participants'] > 0 && $participantCount >= $tournament['max_participants']) {
                    return ['success' => false, 'message' => 'Tournament is full'];
                }

                // Check if already registered
                $sql = "SELECT id, role, registration_status FROM tournament_roles 
                        WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $userId);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();

                if ($existing) {
                    // Check if already registered as player
                    if (strpos($existing['role'], 'player') !== false) {
                        if ($existing['registration_status'] === 'REGISTERED' || $existing['registration_status'] === 'CHECKED_IN') {
                            return ['success' => false, 'message' => 'Already registered for this tournament'];
                        }
                    }

                    // Update existing role to include player
                    $roles = explode(',', $existing['role']);
                    if (!in_array('player', $roles)) {
                        $roles[] = 'player';
                    }
                    $roleString = implode(',', array_unique($roles));

                    // Get next seed
                    $sql = "SELECT COALESCE(MAX(seed), 0) as max_seed FROM tournament_roles WHERE tournament_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("i", $tournamentId);
                    $stmt->execute();
                    $nextSeed = $stmt->get_result()->fetch_assoc()['max_seed'] + 1;

                    $sql = "UPDATE tournament_roles 
                            SET role = ?, registration_status = 'REGISTERED', seed = ? 
                            WHERE tournament_id = ? AND user_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("siis", $roleString, $nextSeed, $tournamentId, $userId);
                    $stmt->execute();
                } else {
                    // Get next seed
                    $sql = "SELECT COALESCE(MAX(seed), 0) as max_seed FROM tournament_roles WHERE tournament_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("i", $tournamentId);
                    $stmt->execute();
                    $nextSeed = $stmt->get_result()->fetch_assoc()['max_seed'] + 1;

                    // Insert new registration
                    $sql = "INSERT INTO tournament_roles (tournament_id, user_id, role, registration_status, seed) 
                            VALUES (?, ?, 'player', 'REGISTERED', ?)";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("isi", $tournamentId, $userId, $nextSeed);
                    $stmt->execute();
                }

                return ['success' => true, 'message' => 'Successfully registered for tournament'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        /**
         * Withdraw player from tournament
         */
        public function withdrawPlayer($tournamentId, $userId)
        {
            try {
                // Verify tournament is still upcoming
                $sql = "SELECT id, status FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament) {
                    return ['success' => false, 'message' => 'Tournament not found'];
                }

                if ($tournament['status'] !== 'upcoming') {
                    return ['success' => false, 'message' => 'Cannot withdraw after tournament has started'];
                }

                // Check if registered
                $sql = "SELECT id, role, registration_status FROM tournament_roles 
                        WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $userId);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();

                if (!$existing || strpos($existing['role'], 'player') === false) {
                    return ['success' => false, 'message' => 'Not registered for this tournament'];
                }

                // Remove player role or delete entry if only player
                $roles = explode(',', $existing['role']);
                $roles = array_filter($roles, function ($r) {
                    return $r !== 'player'; });

                if (empty($roles)) {
                    // Delete entry if no other roles
                    $sql = "DELETE FROM tournament_roles WHERE tournament_id = ? AND user_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("is", $tournamentId, $userId);
                    $stmt->execute();
                } else {
                    // Update to remove player role
                    $roleString = implode(',', $roles);
                    $sql = "UPDATE tournament_roles 
                            SET role = ?, registration_status = 'NONE', seed = 0 
                            WHERE tournament_id = ? AND user_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("sis", $roleString, $tournamentId, $userId);
                    $stmt->execute();
                }

                return ['success' => true, 'message' => 'Successfully withdrawn from tournament'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        /**
         * Get registration status for player
         */
        public function getRegistrationStatus($tournamentId, $userId)
        {
            try {
                $sql = "SELECT role, registration_status, seed FROM tournament_roles 
                        WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $userId);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();

                if (!$result || strpos($result['role'], 'player') === false) {
                    return [
                        'success' => true,
                        'registered' => false,
                        'status' => 'NONE'
                    ];
                }

                return [
                    'success' => true,
                    'registered' => true,
                    'status' => $result['registration_status'],
                    'seed' => $result['seed']
                ];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
    }

    // Handle API requests
    $database = new Database();
    $registration = new PlayerRegistration($database);

    $method = $_SERVER['REQUEST_METHOD'];
    $userId = $_SESSION['user_id'] ?? null;

    $response = ['success' => false, 'message' => 'Unknown error occurred'];

    if (!$userId) {
        http_response_code(401);
        $response = ['success' => false, 'message' => 'Authentication required'];
    } else {
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $tournamentId = $_POST['tournament_id'] ?? $_GET['tournament_id'] ?? null;

        if (!$tournamentId) {
            $response = ['success' => false, 'message' => 'tournament_id is required'];
        } else {
            switch ($action) {
                case 'register':
                    if ($method === 'POST') {
                        $response = $registration->registerPlayer($tournamentId, $userId);
                    } else {
                        http_response_code(405);
                        $response = ['success' => false, 'message' => 'Method not allowed'];
                    }
                    break;

                case 'withdraw':
                    if ($method === 'POST') {
                        $response = $registration->withdrawPlayer($tournamentId, $userId);
                    } else {
                        http_response_code(405);
                        $response = ['success' => false, 'message' => 'Method not allowed'];
                    }
                    break;

                case 'getStatus':
                    if ($method === 'GET') {
                        $response = $registration->getRegistrationStatus($tournamentId, $userId);
                    } else {
                        http_response_code(405);
                        $response = ['success' => false, 'message' => 'Method not allowed'];
                    }
                    break;

                default:
                    $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
                    break;
            }
        }
    }

} catch (Throwable $e) {
    error_log('Unhandled exception in register.php: ' . $e->getMessage());
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
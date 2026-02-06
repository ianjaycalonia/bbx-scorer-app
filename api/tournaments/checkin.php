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

    class CheckInManager
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
         * Verify organizer permissions
         */
        private function verifyOrganizerPermission($tournamentId, $userId)
        {
            $sql = "SELECT created_by FROM tournaments WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $tournamentId);
            $stmt->execute();
            $tournament = $stmt->get_result()->fetch_assoc();

            if (!$tournament) {
                return ['authorized' => false, 'message' => 'Tournament not found'];
            }

            if ($tournament['created_by'] !== $userId) {
                return ['authorized' => false, 'message' => 'Only tournament organizer can perform check-ins'];
            }

            return ['authorized' => true];
        }

        /**
         * Check in a single player
         */
        public function checkInPlayer($tournamentId, $playerId, $organizerId)
        {
            try {
                // Verify organizer permission
                $authCheck = $this->verifyOrganizerPermission($tournamentId, $organizerId);
                if (!$authCheck['authorized']) {
                    return ['success' => false, 'message' => $authCheck['message']];
                }

                // Verify tournament is still upcoming
                $sql = "SELECT id, status FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if ($tournament['status'] !== 'upcoming') {
                    return ['success' => false, 'message' => 'Cannot check in players after tournament has started'];
                }

                // Check if player is registered
                $sql = "SELECT id, role, registration_status FROM tournament_roles 
                        WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $playerId);
                $stmt->execute();
                $player = $stmt->get_result()->fetch_assoc();

                if (!$player || strpos($player['role'], 'player') === false) {
                    return ['success' => false, 'message' => 'Player not registered for this tournament'];
                }

                if ($player['registration_status'] === 'CHECKED_IN') {
                    return ['success' => false, 'message' => 'Player already checked in'];
                }

                // Update to checked in
                $sql = "UPDATE tournament_roles 
                        SET registration_status = 'CHECKED_IN' 
                        WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $playerId);
                $stmt->execute();

                return ['success' => true, 'message' => 'Player checked in successfully'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        /**
         * Bulk check in multiple players
         */
        public function bulkCheckIn($tournamentId, $playerIds, $organizerId)
        {
            try {
                // Verify organizer permission
                $authCheck = $this->verifyOrganizerPermission($tournamentId, $organizerId);
                if (!$authCheck['authorized']) {
                    return ['success' => false, 'message' => $authCheck['message']];
                }

                // Verify tournament is still upcoming
                $sql = "SELECT id, status FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if ($tournament['status'] !== 'upcoming') {
                    return ['success' => false, 'message' => 'Cannot check in players after tournament has started'];
                }

                $this->conn->begin_transaction();

                $successes = [];
                $failures = [];

                foreach ($playerIds as $playerId) {
                    // Check if player is registered
                    $sql = "SELECT id, role, registration_status FROM tournament_roles 
                            WHERE tournament_id = ? AND user_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("is", $tournamentId, $playerId);
                    $stmt->execute();
                    $player = $stmt->get_result()->fetch_assoc();

                    if (!$player || strpos($player['role'], 'player') === false) {
                        $failures[] = $playerId;
                        continue;
                    }

                    if ($player['registration_status'] === 'CHECKED_IN') {
                        $successes[] = $playerId; // Already checked in, count as success
                        continue;
                    }

                    // Update to checked in
                    $sql = "UPDATE tournament_roles 
                            SET registration_status = 'CHECKED_IN' 
                            WHERE tournament_id = ? AND user_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("is", $tournamentId, $playerId);

                    if ($stmt->execute()) {
                        $successes[] = $playerId;
                    } else {
                        $failures[] = $playerId;
                    }
                }

                $this->conn->commit();

                return [
                    'success' => true,
                    'message' => 'Bulk check-in completed',
                    'checked_in' => count($successes),
                    'failed' => count($failures),
                    'successes' => $successes,
                    'failures' => $failures
                ];
            } catch (Exception $e) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        /**
         * Get list of checked-in players
         */
        public function getCheckedInPlayers($tournamentId)
        {
            try {
                $sql = "SELECT tr.user_id, tr.seed, tr.registration_status, 
                               u.display_name, u.blader_name, u.email 
                        FROM tournament_roles tr 
                        JOIN users u ON tr.user_id = u.id 
                        WHERE tr.tournament_id = ? 
                        AND FIND_IN_SET('player', tr.role) > 0 
                        AND tr.registration_status = 'CHECKED_IN' 
                        ORDER BY tr.seed ASC";

                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $players = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                return [
                    'success' => true,
                    'players' => $players,
                    'count' => count($players)
                ];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        /**
         * Get list of registered but not checked-in players
         */
        public function getRegisteredPlayers($tournamentId)
        {
            try {
                $sql = "SELECT tr.user_id, tr.seed, tr.registration_status, 
                               u.display_name, u.blader_name, u.email 
                        FROM tournament_roles tr 
                        JOIN users u ON tr.user_id = u.id 
                        WHERE tr.tournament_id = ? 
                        AND FIND_IN_SET('player', tr.role) > 0 
                        AND tr.registration_status = 'REGISTERED' 
                        ORDER BY tr.assigned_at ASC";

                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $players = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                return [
                    'success' => true,
                    'players' => $players,
                    'count' => count($players)
                ];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
    }

    // Handle API requests
    $database = new Database();
    $checkInManager = new CheckInManager($database);

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
                case 'checkIn':
                    if ($method === 'POST' && isset($_POST['player_id'])) {
                        $response = $checkInManager->checkInPlayer($tournamentId, $_POST['player_id'], $userId);
                    } else {
                        http_response_code(400);
                        $response = ['success' => false, 'message' => 'player_id is required'];
                    }
                    break;

                case 'bulkCheckIn':
                    if ($method === 'POST' && isset($_POST['player_ids'])) {
                        $playerIds = json_decode($_POST['player_ids'], true);
                        if (is_array($playerIds)) {
                            $response = $checkInManager->bulkCheckIn($tournamentId, $playerIds, $userId);
                        } else {
                            $response = ['success' => false, 'message' => 'player_ids must be a JSON array'];
                        }
                    } else {
                        http_response_code(400);
                        $response = ['success' => false, 'message' => 'player_ids is required'];
                    }
                    break;

                case 'getCheckedIn':
                    if ($method === 'GET') {
                        $response = $checkInManager->getCheckedInPlayers($tournamentId);
                    } else {
                        http_response_code(405);
                        $response = ['success' => false, 'message' => 'Method not allowed'];
                    }
                    break;

                case 'getRegistered':
                    if ($method === 'GET') {
                        $response = $checkInManager->getRegisteredPlayers($tournamentId);
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
    error_log('Unhandled exception in checkin.php: ' . $e->getMessage());
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
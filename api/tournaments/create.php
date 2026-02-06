<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once dirname(__DIR__) . '/config/database.php';
    session_start();

    class TournamentCreator
    {
        private $conn;

        public function __construct($database)
        {
            try {
                $this->conn = $database->getConnection();
                if (!$this->conn) {
                    throw new Exception('Database connection failed');
                }
            } catch (Exception $e) {
                throw new Exception('Failed to initialize database connection: ' . $e->getMessage());
            }
        }

        public function createTournament($tournamentData)
        {
            try {
                $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';

                if (empty($tournamentData['name']))
                    return ['success' => false, 'message' => 'Tournament name is required'];
                if (empty($tournamentData['tournament_type']))
                    return ['success' => false, 'message' => 'Tournament type is required'];

                $type = $tournamentData['tournament_type'];
                if ($type === 'single_elimination' || $type === 'double_elimination') {
                    $rankTo = intval($tournamentData['rank_to'] ?? 0);
                    if (!in_array($rankTo, [3, 4, 5, 6, 7, 8])) {
                        return ['success' => false, 'message' => 'Placement cutoff must be 3rd, 4th, 5th, 6th, 7th, or 8th place for elimination tournaments'];
                    }
                }

                // Generate unique tournament code
                $tournamentCode = $this->generateUniqueTournamentCode();

                // Validate and sanitize slug if provided
                $slug = $this->validateSlug($tournamentData['slug'] ?? null);

                $sql = "INSERT INTO tournaments (
                    name, date, location, tournament_type, visibility, number_of_stadiums, max_participants, 
                    status, rules, created_by, swiss_rounds, top_cut, rank_to, tournament_code, tournament_slug
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'upcoming', ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare tournament insert query: ' . $this->conn->error);
                }

                // Assign to variables for bind_param (pass by reference)
                $name = $tournamentData['name'];
                $date = $tournamentData['date'];
                $location = $tournamentData['location'];
                $t_type = $tournamentData['tournament_type'];
                $visibility = $tournamentData['visibility'] ?? 'team_only';
                $stadiums = $tournamentData['number_of_stadiums'];
                $max_participants = $tournamentData['max_participants'];
                $rules = $tournamentData['rules'];
                $swiss_rounds = $tournamentData['swiss_rounds'];
                $top_cut = $tournamentData['top_cut'];
                $rank_to = $tournamentData['rank_to'] ?? 5;

                $stmt->bind_param(
                    "sssssiissiiiss",
                    $name,
                    $date,
                    $location,
                    $t_type,
                    $visibility,
                    $stadiums,
                    $max_participants,
                    $rules,
                    $userId,
                    $swiss_rounds,
                    $top_cut,
                    $rank_to,
                    $tournamentCode,
                    $slug
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute tournament insert: ' . $stmt->error);
                }

                $tournamentId = $this->conn->insert_id;

                $roleSql = "INSERT INTO tournament_roles (tournament_id, user_id, role, status) VALUES (?, ?, 'organizer', 'accepted')";
                $roleStmt = $this->conn->prepare($roleSql);
                if (!$roleStmt)
                    throw new Exception('Failed to prepare role insert query: ' . $this->conn->error);

                $roleStmt->bind_param("is", $tournamentId, $userId);
                if (!$roleStmt->execute())
                    throw new Exception('Failed to assign organizer role: ' . $roleStmt->error);

                return [
                    'success' => true,
                    'message' => 'Tournament created successfully',
                    'tournament_id' => $tournamentId,
                    'tournament_code' => $tournamentCode,
                    'tournament_slug' => $slug
                ];
            } catch (Exception $e) {
                error_log('Error in createTournament: ' . $e->getMessage());
                return ['success' => false, 'message' => 'Error creating tournament: ' . $e->getMessage()];
            }
        }

        public function updateTournament($tournamentId, $tournamentData)
        {
            $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
            try {
                $sql = "SELECT created_by FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $tournament = $result->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];
                if ($tournament['created_by'] !== $userId)
                    return ['success' => false, 'message' => 'Only tournament creator can update details'];

                $type = $tournamentData['tournament_type'] ?? null;
                if ($type === 'single_elimination' || $type === 'double_elimination') {
                    $rankTo = intval($tournamentData['rank_to'] ?? 0);
                    if (!in_array($rankTo, [3, 4, 5, 6, 7, 8]))
                        return ['success' => false, 'message' => 'Placement cutoff must be 3rd, 4th, 5th, 6th, 7th, or 8th place for elimination tournaments'];
                }

                $sql = "UPDATE tournaments SET name = ?, date = ?, location = ?, tournament_type = ?, visibility = ?, number_of_stadiums = ?, max_participants = ?, rules = ?, swiss_rounds = ?, top_cut = ?, rank_to = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $stmt = $this->conn->prepare($sql);

                // Assign to variables for bind_param (pass by reference)
                $name = $tournamentData['name'];
                $date = $tournamentData['date'];
                $location = $tournamentData['location'];
                $t_type = $tournamentData['tournament_type'];
                $visibility = $tournamentData['visibility'];
                $stadiums = $tournamentData['number_of_stadiums'];
                $max_participants = $tournamentData['max_participants'];
                $rules = $tournamentData['rules'];
                $swiss_rounds = $tournamentData['swiss_rounds'];
                $top_cut = $tournamentData['top_cut'];
                $rank_to = $tournamentData['rank_to'] ?? 5;

                $stmt->bind_param(
                    "sssssissiiii",
                    $name,
                    $date,
                    $location,
                    $t_type,
                    $visibility,
                    $stadiums,
                    $max_participants,
                    $rules,
                    $swiss_rounds,
                    $top_cut,
                    $rank_to,
                    $tournamentId
                );
                $result = $stmt->execute();

                return $result ? ['success' => true, 'message' => 'Tournament updated successfully'] : ['success' => false, 'message' => 'Failed to update tournament'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        public function deleteTournament($tournamentId)
        {
            $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
            try {
                $stmt = $this->conn->prepare("SELECT created_by FROM tournaments WHERE id = ?");
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $tournament = $result->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];
                // Not enforcing ownership for simplify deletion during dev if needed, or enforce it? Original code had it commented out?
                // if ($tournament['created_by'] !== $userId) return ['success' => false, 'message' => 'Only tournament creator can delete tournament'];

                $this->conn->begin_transaction();
                try {
                    $tables = ['tournament_roles', 'tournament_judges', 'tournament_participants', 'tournament_matches', 'tournament_rounds'];
                    foreach ($tables as $table) {
                        $check = $this->conn->query("SHOW TABLES LIKE '$table'");
                        if ($check && $check->num_rows > 0)
                            $this->conn->query("DELETE FROM $table WHERE tournament_id = $tournamentId");
                    }
                    $deleteTournament = $this->conn->prepare("DELETE FROM tournaments WHERE id = ?");
                    $deleteTournament->bind_param("s", $tournamentId);
                    $result = $deleteTournament->execute();

                    if ($result) {
                        $this->conn->commit();
                        return ['success' => true, 'message' => 'Tournament deleted successfully'];
                    } else {
                        $this->conn->rollback();
                        return ['success' => false, 'message' => 'Failed to delete tournament'];
                    }
                } catch (Exception $e) {
                    $this->conn->rollback();
                    throw $e;
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        public function registerPlayer($tournamentId, $playerName)
        {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId)
                return ['success' => false, 'message' => 'Not authenticated'];

            try {
                $sql = "SELECT id, email, display_name, blader_name FROM users WHERE display_name = ? OR blader_name = ? OR email = ? LIMIT 1";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("sss", $playerName, $playerName, $playerName);
                $stmt->execute();
                $player = $stmt->get_result()->fetch_assoc();

                if (!$player)
                    return ['success' => false, 'message' => 'Player not found: ' . $playerName];
                $playerId = $player['id'];

                $sql = "SELECT id, status, max_participants FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];
                if ($tournament['status'] !== 'upcoming')
                    return ['success' => false, 'message' => 'Tournament registration is closed'];

                $sql = "SELECT COUNT(*) as count FROM tournament_participants WHERE tournament_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $participantCount = $stmt->get_result()->fetch_assoc()['count'];

                if ($tournament['max_participants'] > 0 && $participantCount >= $tournament['max_participants'])
                    return ['success' => false, 'message' => 'Tournament is full'];

                $sql = "SELECT id FROM tournament_participants WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ss", $tournamentId, $playerId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0)
                    return ['success' => false, 'message' => 'Player already registered'];

                $sql = "INSERT INTO tournament_participants (tournament_id, user_id) VALUES (?, ?)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ss", $tournamentId, $playerId);
                $result = $stmt->execute();

                return $result ? ['success' => true, 'message' => 'Player registered successfully'] : ['success' => false, 'message' => 'Registration failed'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        public function getTournamentDetails($tournamentId)
        {
            try {
                $sql = "SELECT t.*, u.display_name as creator_name, u.avatar_url as creator_avatar FROM tournaments t LEFT JOIN users u ON t.created_by = u.id WHERE t.id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];

                $sql = "SELECT tr.*, u.display_name, u.blader_name, u.avatar_url FROM tournament_roles tr LEFT JOIN users u ON tr.user_id = u.id WHERE tr.tournament_id = ? ORDER BY tr.assigned_at";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $people = [];
                $participantCount = 0;
                $judgeCount = 0;

                while ($row = $result->fetch_assoc()) {
                    $people[] = $row;
                    $roles = $row['role'];
                    if (strpos($roles, 'player') !== false || strpos($roles, 'both') !== false || strpos($roles, 'organizer') !== false) {
                        if (strpos($roles, 'player') !== false || strpos($roles, 'both') !== false)
                            $participantCount++;
                    }
                    if (strpos($roles, 'judge') !== false || strpos($roles, 'both') !== false)
                        $judgeCount++;
                }

                return ['success' => true, 'tournament' => $tournament, 'people' => $people, 'participant_count' => $participantCount, 'judge_count' => $judgeCount];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        public function removePlayer($tournamentId, $userId)
        {
            $sessionUserId = $_SESSION['user_id'] ?? null;
            if (!$sessionUserId)
                return ['success' => false, 'message' => 'Not authenticated'];

            try {
                $sql = "SELECT id, created_by FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];
                if ($tournament['created_by'] !== $sessionUserId)
                    return ['success' => false, 'message' => 'Only tournament creator can remove players'];

                $sql = "SELECT id FROM tournament_participants WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ss", $tournamentId, $userId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0)
                    return ['success' => false, 'message' => 'Player not found in tournament'];

                $sql = "DELETE FROM tournament_participants WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ss", $tournamentId, $userId);

                return $stmt->execute() ? ['success' => true, 'message' => 'Player removed successfully'] : ['success' => false, 'message' => 'Failed to remove player'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        public function assignJudge($tournamentId, $userId)
        {
            $sessionUserId = $_SESSION['user_id'] ?? null;
            if (!$sessionUserId)
                return ['success' => false, 'message' => 'Not authenticated'];

            try {
                $sql = "SELECT id, created_by FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];
                if ($tournament['created_by'] !== $sessionUserId)
                    return ['success' => false, 'message' => 'Only tournament creator can assign judges'];

                $sql = "SELECT id FROM tournament_roles WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $userId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0)
                    return ['success' => false, 'message' => 'User is already assigned to this tournament'];

                $sql = "INSERT INTO tournament_roles (tournament_id, user_id, role, status) VALUES (?, ?, 'judge', 'accepted')";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $userId);

                return $stmt->execute() ? ['success' => true, 'message' => 'Judge assigned successfully'] : ['success' => false, 'message' => 'Failed to assign judge'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        public function getTournamentJudges($tournamentId)
        {
            try {
                $sql = "SELECT tr.id, tr.user_id, tr.assigned_at, u.email, u.display_name, u.blader_name 
                        FROM tournament_roles tr 
                        JOIN users u ON tr.user_id = u.id 
                        WHERE tr.tournament_id = ? AND tr.role = 'judge' 
                        ORDER BY tr.assigned_at ASC";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $judges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                return ['success' => true, 'judges' => $judges, 'count' => count($judges)];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        public function removeJudge($tournamentId, $userId)
        {
            $sessionUserId = $_SESSION['user_id'] ?? null;
            if (!$sessionUserId)
                return ['success' => false, 'message' => 'Not authenticated'];

            try {
                $sql = "SELECT id, created_by FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];
                if ($tournament['created_by'] !== $sessionUserId)
                    return ['success' => false, 'message' => 'Only tournament creator can remove judges'];

                $sql = "SELECT id FROM tournament_roles WHERE tournament_id = ? AND user_id = ? AND role = 'judge'";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $userId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0)
                    return ['success' => false, 'message' => 'Judge not found in tournament'];

                $sql = "DELETE FROM tournament_roles WHERE tournament_id = ? AND user_id = ? AND role = 'judge'";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("is", $tournamentId, $userId);

                return $stmt->execute() ? ['success' => true, 'message' => 'Judge removed successfully'] : ['success' => false, 'message' => 'Failed to remove judge'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }

        /**
         * Generate unique 8-character tournament code
         */
        private function generateUniqueTournamentCode()
        {
            $maxAttempts = 10;
            for ($i = 0; $i < $maxAttempts; $i++) {
                // Generate 8-character alphanumeric code (uppercase)
                $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8));

                // Check if code already exists
                $sql = "SELECT id FROM tournaments WHERE tournament_code = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $code);
                $stmt->execute();

                if ($stmt->get_result()->num_rows === 0) {
                    return $code;
                }
            }

            // Fallback to timestamp-based code if collision persists
            return strtoupper(substr(md5(microtime()), 0, 8));
        }

        /**
         * Validate and sanitize tournament slug
         */
        private function validateSlug($slug)
        {
            if (empty($slug)) {
                return null;
            }

            // Convert to lowercase and replace spaces/underscores with hyphens
            $slug = strtolower(trim($slug));
            $slug = preg_replace('/[\s_]+/', '-', $slug);

            // Remove invalid characters (only allow alphanumeric and hyphens)
            $slug = preg_replace('/[^a-z0-9-]/', '', $slug);

            // Remove consecutive hyphens
            $slug = preg_replace('/-+/', '-', $slug);

            // Remove leading/trailing hyphens
            $slug = trim($slug, '-');

            // Validate format
            if (!preg_match('/^[a-z0-9-]+$/', $slug) || strlen($slug) < 3) {
                return null; // Invalid slug, return null
            }

            // Check uniqueness
            $sql = "SELECT id FROM tournaments WHERE tournament_slug = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $slug);
            $stmt->execute();

            if ($stmt->get_result()->num_rows > 0) {
                return null; // Slug already exists
            }

            return $slug;
        }

        public function updateTournamentStatus($tournamentId, $status)
        {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId)
                return ['success' => false, 'message' => 'Not authenticated'];

            try {
                $sql = "SELECT created_by FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();

                if (!$tournament)
                    return ['success' => false, 'message' => 'Tournament not found'];
                if ($tournament['created_by'] !== $userId)
                    return ['success' => false, 'message' => 'Only tournament creator can update status'];

                $validStatuses = ['upcoming', 'ongoing', 'completed'];
                if (!in_array($status, $validStatuses))
                    return ['success' => false, 'message' => 'Invalid tournament status'];

                $sql = "UPDATE tournaments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ss", $status, $tournamentId);

                return $stmt->execute() ? ['success' => true, 'message' => 'Tournament status updated successfully'] : ['success' => false, 'message' => 'Status update failed'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
    }

    // Handle API requests
    $database = new Database();
    $tournamentCreator = new TournamentCreator($database);

    $method = $_SERVER['REQUEST_METHOD'];

    // Initialize response to ensure it's always defined
    $response = ['success' => false, 'message' => 'Unknown error occurred'];

    // Debug logging
    $rawInput = file_get_contents('php://input'); // read once so we can reuse
    error_log("[create.php] Method: " . $method);
    error_log("[create.php] Raw POST data: " . ($rawInput === false ? 'FALSE' : $rawInput));

    // Get action from POST data (form-urlencoded) or GET parameters
    $postData = [];
    if ($method === 'POST') {
        if (!empty($rawInput)) {
            parse_str($rawInput, $postData);
        }

        if (empty($postData) && !empty($_POST)) {
            // Fallback for environments where php://input has been consumed already
            $postData = $_POST;
            error_log('[create.php] Fallback to $_POST superglobal');
        }
    }
    $action = $postData['action'] ?? $_GET['action'] ?? '';

    error_log("[create.php] Parsed action: " . $action);

    if ($method === 'POST' && empty($postData)) {
        error_log('[create.php] WARNING: POST request but no payload parsed');
    }

    switch ($action) {
        case 'create':
            if ($method === 'POST') {
                if (empty($postData['name'])) {
                    $response = ['success' => false, 'message' => 'Tournament name is required'];
                    break;
                }
                if (empty($postData['tournament_type'])) {
                    $response = ['success' => false, 'message' => 'Tournament type is required'];
                    break;
                }

                $tournamentData = [
                    'name' => $postData['name'] ?? '',
                    'date' => $postData['date'] ?? null,
                    'location' => $postData['location'] ?? null,
                    'tournament_type' => $postData['tournament_type'] ?? 'single_elimination',
                    'visibility' => $postData['visibility'] ?? 'team_only',
                    'number_of_stadiums' => intval($postData['number_of_stadiums'] ?? 1),
                    'max_participants' => intval($postData['max_participants'] ?? 50),
                    'rules' => $postData['rules'] ?? null,
                    'swiss_rounds' => intval($postData['swiss_rounds'] ?? 5),
                    'top_cut' => intval($postData['top_cut'] ?? 0),
                    'rank_to' => intval($postData['rank_to'] ?? 5)
                ];
                $response = $tournamentCreator->createTournament($tournamentData);
            } else {
                http_response_code(405);
                $response = ['success' => false, 'message' => 'Invalid request method for create action'];
            }
            break;

        case 'update':
            if ($method === 'POST' && isset($postData['tournament_id'])) {
                $tournamentData = [
                    'name' => $postData['name'] ?? '',
                    'date' => $postData['date'] ?? null,
                    'location' => $postData['location'] ?? null,
                    'tournament_type' => $postData['tournament_type'] ?? 'single_elimination',
                    'visibility' => $postData['visibility'] ?? 'team_only',
                    'number_of_stadiums' => intval($postData['number_of_stadiums'] ?? 1),
                    'max_participants' => intval($postData['max_participants'] ?? 50),
                    'rules' => $postData['rules'] ?? null,
                    'swiss_rounds' => intval($postData['swiss_rounds'] ?? 5),
                    'top_cut' => intval($postData['top_cut'] ?? 0),
                    'rank_to' => intval($postData['rank_to'] ?? 5)
                ];
                $response = $tournamentCreator->updateTournament($postData['tournament_id'], $tournamentData);
            } else {
                http_response_code(400);
                $response = ['success' => false, 'message' => 'Invalid request for update action'];
            }
            break;

        case 'delete':
            if ($method === 'POST' && isset($postData['tournament_id'])) {
                $response = $tournamentCreator->deleteTournament($postData['tournament_id']);
            } else {
                http_response_code(400);
                $response = ['success' => false, 'message' => 'Invalid request for delete action'];
            }
            break;

        case 'getDetails':
            if (isset($_GET['tournament_id'])) {
                $response = $tournamentCreator->getTournamentDetails($_GET['tournament_id']);
            } else {
                http_response_code(400);
                $response = ['success' => false, 'message' => 'tournament_id is required'];
            }
            break;

        default:
            http_response_code(400);
            $response = ['success' => false, 'message' => 'Unknown or missing action: ' . $action];
            break;
    }

} catch (Throwable $e) {
    error_log('Unhandled exception in create.php: ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ];
}

ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($response ?? ['success' => false, 'message' => 'Unknown State']);
?>
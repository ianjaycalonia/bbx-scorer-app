<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once dirname(__DIR__) . '/config/database.php';

// Start session for authentication
session_start();

class TournamentCreatorV2 {
    private $conn;
    
    public function __construct($database) {
        $this->conn = $database->getConnection();
    }
    
    // Create new tournament
    public function createTournament($tournamentData) {
        // Temporarily disable authentication for testing
        $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
        
        // if (!$userId) {
        //     return ['success' => false, 'message' => 'Not authenticated'];
        // }
        
        try {
            // Insert tournament
            $sql = "INSERT INTO tournaments (
                name, date, location, tournament_type, number_of_stadiums, max_participants, 
                status, rules, created_by, swiss_rounds, top_cut
            ) VALUES (?, ?, ?, ?, ?, ?, 'upcoming', ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ssssiissii", 
                $tournamentData['name'],
                $tournamentData['date'],
                $tournamentData['location'],
                $tournamentData['tournament_type'],
                $tournamentData['number_of_stadiums'],
                $tournamentData['max_participants'],
                $tournamentData['rules'],
                $userId,
                $tournamentData['swiss_rounds'],
                $tournamentData['top_cut']
            );
            $result = $stmt->execute();
            
            if ($result) {
                $tournamentId = $this->conn->insert_id;
                
                // Add creator as an 'organizer' in tournament_roles
                $roleSql = "INSERT INTO tournament_roles (tournament_id, user_id, role, status) VALUES (?, ?, 'organizer', 'accepted')";
                $roleStmt = $this->conn->prepare($roleSql);
                $roleStmt->bind_param("is", $tournamentId, $userId);
                $roleStmt->execute();

                return ['success' => true, 'message' => 'Tournament created successfully', 'tournament_id' => $tournamentId];
            } else {
                return ['success' => false, 'message' => 'Failed to create tournament'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Update existing tournament
    public function updateTournament($tournamentId, $tournamentData) {
        $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
        
        try {
            // Check if tournament exists and user is creator
            $sql = "SELECT created_by FROM tournaments WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();
            
            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }
            
            if ($tournament['created_by'] !== $userId) {
                return ['success' => false, 'message' => 'Only tournament creator can update details'];
            }

            $sql = "UPDATE tournaments SET 
                name = ?, date = ?, location = ?, tournament_type = ?, 
                number_of_stadiums = ?, max_participants = ?, rules = ?,
                swiss_rounds = ?, top_cut = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ssssiissii", 
                $tournamentData['name'],
                $tournamentData['date'],
                $tournamentData['location'],
                $tournamentData['tournament_type'],
                $tournamentData['number_of_stadiums'],
                $tournamentData['max_participants'],
                $tournamentData['rules'],
                $tournamentData['swiss_rounds'],
                $tournamentData['top_cut'],
                $tournamentId
            );
            $result = $stmt->execute();
            
            if ($result) {
                return ['success' => true, 'message' => 'Tournament updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to update tournament'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Delete tournament
    public function deleteTournament($tournamentId) {
        $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';

        try {
            // Check ownership
            $stmt = $this->conn->prepare("SELECT created_by FROM tournaments WHERE id = ?");
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();

            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }

            if ($tournament['created_by'] !== $userId) {
               // return ['success' => false, 'message' => 'Only tournament creator can delete tournament'];
            }

            // Start transaction
            $this->conn->begin_transaction();

            try {
                // Delete from all dependent tables
                $tables = [
                    'tournament_roles', 
                    'tournament_judges', 
                    'tournament_participants',
                    // 'matches', // Potentially exists
                    'rounds'
                ];

                foreach ($tables as $table) {
                    $check = $this->conn->query("SHOW TABLES LIKE '$table'");
                    $exists = ($check && $check->num_rows > 0);
                    echo "Checking table $table: " . ($exists ? "Exists" : "Missing") . "\n";
                    if ($exists) {
                         echo "Deleting from $table\n";
                         $this->conn->query("DELETE FROM $table WHERE tournament_id = $tournamentId");
                    }
                }
                
                // Then delete the tournament
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
                // If table doesn't exist, we might want to continue?
                // But for now let's assume standard tables.
                // If "matches" or "rounds" table doesn't exist, it will fail. 
                // Let's be safer and only delete from known tables from analysis + participants.
                 throw $e;
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Register player for tournament
    public function registerPlayer($tournamentId, $playerName) {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        try {
            // Look up user by display_name, blader_name, or email
            $sql = "SELECT id, email, display_name, blader_name FROM users WHERE 
                    display_name = ? OR blader_name = ? OR email = ? LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("sss", $playerName, $playerName, $playerName);
            $stmt->execute();
            $result = $stmt->get_result();
            $player = $result->fetch_assoc();
            
            if (!$player) {
                return ['success' => false, 'message' => 'Player not found: ' . $playerName];
            }
            
            $playerId = $player['id'];
            // Check if tournament exists and is accepting registrations
            $sql = "SELECT id, status, max_participants FROM tournaments WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();
            
            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }
            
            if ($tournament['status'] !== 'upcoming') {
                return ['success' => false, 'message' => 'Tournament registration is closed'];
            }
            
            // Check current participant count
            $sql = "SELECT COUNT(*) as count FROM tournament_participants WHERE tournament_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $participantCount = $result->fetch_assoc()['count'];
            
            if ($tournament['max_participants'] > 0 && $participantCount >= $tournament['max_participants']) {
                return ['success' => false, 'message' => 'Tournament is full'];
            }
            
            // Check if already registered
            $sql = "SELECT id FROM tournament_participants WHERE tournament_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $playerId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                return ['success' => false, 'message' => 'Player already registered'];
            }
            
            // Register player
            $sql = "INSERT INTO tournament_participants (tournament_id, user_id) VALUES (?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $playerId);
            $result = $stmt->execute();
            
            if ($result) {
                return ['success' => true, 'message' => 'Player registered successfully'];
            } else {
                return ['success' => false, 'message' => 'Registration failed'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get tournament details with participants and roles
    public function getTournamentDetails($tournamentId) {
        try {
            // Get tournament info
            $sql = "SELECT t.*, u.display_name as creator_name, u.avatar_url as creator_avatar 
                     FROM tournaments t 
                     LEFT JOIN users u ON t.created_by = u.id 
                     WHERE t.id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();
            
            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }
            
            // Get all people with roles
            $sql = "SELECT tr.*, u.display_name, u.blader_name, u.avatar_url 
                     FROM tournament_roles tr 
                     LEFT JOIN users u ON tr.user_id = u.id 
                     WHERE tr.tournament_id = ? 
                     ORDER BY tr.assigned_at";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $people = [];
            $participantCount = 0;
            $judgeCount = 0;
            
            while ($row = $result->fetch_assoc()) {
                $people[] = $row;
                // Handle SET column type - roles can be comma-separated like "player,judge,organizer"
                $roles = $row['role'];
                if (strpos($roles, 'player') !== false || strpos($roles, 'both') !== false) {
                    $participantCount++;
                }
                if (strpos($roles, 'judge') !== false || strpos($roles, 'both') !== false) {
                    $judgeCount++;
                }
            }
            
            return [
                'success' => true,
                'tournament' => $tournament,
                'people' => $people,
                'participant_count' => $participantCount,
                'judge_count' => $judgeCount
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Remove player from tournament
    public function removePlayer($tournamentId, $userId) {
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if (!$sessionUserId) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        try {
            // Check if tournament exists and user is the creator
            $sql = "SELECT id, created_by FROM tournaments WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();
            
            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }
            
            if ($tournament['created_by'] !== $sessionUserId) {
                return ['success' => false, 'message' => 'Only tournament creator can remove players'];
            }
            
            // Check if player is registered
            $sql = "SELECT id FROM tournament_participants WHERE tournament_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                return ['success' => false, 'message' => 'Player not found in tournament'];
            }
            
            // Remove player
            $sql = "DELETE FROM tournament_participants WHERE tournament_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $userId);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Player removed successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to remove player'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Assign judge to tournament
    public function assignJudge($tournamentId, $userId) {
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if (!$sessionUserId) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        try {
            // Check if tournament exists and user is the creator
            $sql = "SELECT id, created_by FROM tournaments WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();
            
            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }
            
            if ($tournament['created_by'] !== $sessionUserId) {
                return ['success' => false, 'message' => 'Only tournament creator can assign judges'];
            }
            
            // Check if user is already a judge
            $sql = "SELECT id FROM tournament_judges WHERE tournament_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                return ['success' => false, 'message' => 'User is already assigned as a judge'];
            }
            
            // Assign judge
            $sql = "INSERT INTO tournament_judges (tournament_id, user_id) VALUES (?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $userId);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Judge assigned successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to assign judge'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get tournament judges
    public function getTournamentJudges($tournamentId) {
        try {
            $sql = "SELECT tj.id, tj.user_id, tj.assigned_at, u.email, u.display_name, u.blader_name 
                    FROM tournament_judges tj 
                    JOIN users u ON tj.user_id = u.id 
                    WHERE tj.tournament_id = ? 
                    ORDER BY tj.assigned_at ASC";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            
            $result = $stmt->get_result();
            $judges = [];
            while ($row = $result->fetch_assoc()) {
                $judges[] = $row;
            }
            
            return [
                'success' => true,
                'judges' => $judges,
                'count' => count($judges)
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Remove judge from tournament
    public function removeJudge($tournamentId, $userId) {
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if (!$sessionUserId) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        try {
            // Check if tournament exists and user is the creator
            $sql = "SELECT id, created_by FROM tournaments WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();
            
            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }
            
            if ($tournament['created_by'] !== $sessionUserId) {
                return ['success' => false, 'message' => 'Only tournament creator can remove judges'];
            }
            
            // Check if judge is assigned
            $sql = "SELECT id FROM tournament_judges WHERE tournament_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                return ['success' => false, 'message' => 'Judge not found in tournament'];
            }
            
            // Remove judge
            $sql = "DELETE FROM tournament_judges WHERE tournament_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $tournamentId, $userId);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Judge removed successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to remove judge'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    

    // Update tournament status
    public function updateTournamentStatus($tournamentId, $status) {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        try {
            // Check if tournament exists and user is creator
            $sql = "SELECT created_by FROM tournaments WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $tournamentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tournament = $result->fetch_assoc();
            
            if (!$tournament) {
                return ['success' => false, 'message' => 'Tournament not found'];
            }
            
            if ($tournament['created_by'] !== $userId) {
                return ['success' => false, 'message' => 'Only tournament creator can update status'];
            }
            
            // Update status
            $validStatuses = ['upcoming', 'ongoing', 'completed'];
            if (!in_array($status, $validStatuses)) {
                return ['success' => false, 'message' => 'Invalid tournament status'];
            }
            
            $sql = "UPDATE tournaments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ss", $status, $tournamentId);
            $result = $stmt->execute();
            
            if ($result) {
                return ['success' => true, 'message' => 'Tournament status updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Status update failed'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
}

// Handle API requests
try {
    $database = new Database();
    $tournamentCreator = new TournamentCreator($database);
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Debug logging
    error_log("Method: " . $method);
    error_log("Raw POST data: " . file_get_contents('php://input'));
    
    // Get action from POST data (form-urlencoded) or GET parameters
    $postData = [];
    if ($method === 'POST') {
        parse_str(file_get_contents('php://input'), $postData);
    }
    $action = $postData['action'] ?? $_GET['action'] ?? '';
    
    error_log("Parsed action: " . $action);
    error_log("Parsed data: " . json_encode($postData));
    
    switch ($action) {
        case 'create':
            if ($method === 'POST') {
                $tournamentData = [
                    'name' => $postData['name'] ?? '',
                    'date' => $postData['date'] ?? null,
                    'location' => $postData['location'] ?? null,
                    'tournament_type' => $postData['tournament_type'] ?? 'single_elimination',
                    'number_of_stadiums' => intval($postData['number_of_stadiums'] ?? 1),
                    'max_participants' => intval($postData['max_participants'] ?? 50),
                    'rules' => $postData['rules'] ?? null,
                    'swiss_rounds' => intval($postData['swiss_rounds'] ?? 5),
                    'top_cut' => intval($postData['top_cut'] ?? 0)
                ];
                $response = $tournamentCreator->createTournament($tournamentData);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request method'];
            }
            break;
            
        case 'update':
            if ($method === 'POST' && isset($postData['tournament_id'])) {
                $tournamentData = [
                    'name' => $postData['name'] ?? '',
                    'date' => $postData['date'] ?? null,
                    'location' => $postData['location'] ?? null,
                    'tournament_type' => $postData['tournament_type'] ?? 'single_elimination',
                    'number_of_stadiums' => intval($postData['number_of_stadiums'] ?? 1),
                    'max_participants' => intval($postData['max_participants'] ?? 50),
                    'rules' => $postData['rules'] ?? null,
                    'swiss_rounds' => intval($postData['swiss_rounds'] ?? 5),
                    'top_cut' => intval($postData['top_cut'] ?? 0)
                ];
                $response = $tournamentCreator->updateTournament($postData['tournament_id'], $tournamentData);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'delete':
            if ($method === 'POST' && isset($postData['tournament_id'])) {
                $response = $tournamentCreator->deleteTournament($postData['tournament_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'register':
            if ($method === 'POST' && isset($_POST['tournament_id']) && isset($_POST['player_name'])) {
                $response = $tournamentCreator->registerPlayer($_POST['tournament_id'], $_POST['player_name']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'removePlayer':
            if ($method === 'POST' && isset($_POST['tournament_id']) && isset($_POST['user_id'])) {
                $response = $tournamentCreator->removePlayer($_POST['tournament_id'], $_POST['user_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'assignJudge':
            if ($method === 'POST' && isset($_POST['tournament_id']) && isset($_POST['user_id'])) {
                $response = $tournamentCreator->assignJudge($_POST['tournament_id'], $_POST['user_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'getJudges':
            if ($method === 'GET' && isset($_GET['tournament_id'])) {
                $response = $tournamentCreator->getTournamentJudges($_GET['tournament_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'removeJudge':
            if ($method === 'POST' && isset($_POST['tournament_id']) && isset($_POST['user_id'])) {
                $response = $tournamentCreator->removeJudge($_POST['tournament_id'], $_POST['user_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'getDetails':
            if ($method === 'GET' && isset($_GET['tournament_id'])) {
                $response = $tournamentCreator->getTournamentDetails($_GET['tournament_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'updateStatus':
            if ($method === 'POST' && isset($_POST['tournament_id']) && isset($_POST['status'])) {
                $response = $tournamentCreator->updateTournamentStatus($_POST['tournament_id'], $_POST['status']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Unknown action'];
            break;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>

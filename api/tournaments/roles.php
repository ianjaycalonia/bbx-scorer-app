<?php
// Disable error reporting to prevent warnings in JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Set headers first
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once dirname(__DIR__) . '/config/database.php';

try {
    session_start();

    class TournamentRoles {
        private $conn;
        
        public function __construct() {
            $database = new Database();
            $this->conn = $database->getConnection();
        }
        
        // Add people with roles to tournament
        public function addPeopleWithRoles($tournamentId, $people) {
            $sessionUserId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
            
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
                    return ['success' => false, 'message' => 'Only tournament creator can add people'];
                }
                
                $this->conn->begin_transaction();
                
                $successes = [];
                $failures = [];
                
                foreach ($people as $person) {
                    $userId = $person['user_id'];
                    $newRole = $person['role']; // 'player', 'judge', or 'both'
                    
                    // Check if person already has a role in this tournament
                    $sql = "SELECT id, role FROM tournament_roles WHERE tournament_id = ? AND user_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("ss", $tournamentId, $userId);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    
                    if ($existing) {
                        $rolesList = explode(',', $existing['role']);
                        
                        // Logic: If they are already in the database, we update their participation roles.
                        // If they are an organizer (creator), we KEEP organizer in the set.
                        
                        $newRolesRaw = $newRole === 'both' ? ['player', 'judge'] : [$newRole];
                        $finalRoles = $newRolesRaw;
                        
                        if (in_array('organizer', $rolesList)) {
                            $finalRoles[] = 'organizer';
                        }
                        
                        $roleString = implode(',', array_unique($finalRoles));
                        
                        $sql = "UPDATE tournament_roles SET role = ? WHERE tournament_id = ? AND user_id = ?";
                        $stmt = $this->conn->prepare($sql);
                        $stmt->bind_param("sss", $roleString, $tournamentId, $userId);
                        $updateSuccess = $stmt->execute();
                        
                        $pName = $person['display_name'] ?? $userId;
                        if ($updateSuccess) {
                            $successes[] = $pName . ' (role updated)';
                        } else {
                            $failures[] = $pName . ' (failed to update role)';
                        }
                    } else {
                        // Insert new role
                        $roleString = $newRole === 'both' ? 'player,judge' : $newRole;
                        $sql = "INSERT INTO tournament_roles (tournament_id, user_id, role) VALUES (?, ?, ?)";
                        $stmt = $this->conn->prepare($sql);
                        $stmt->bind_param("sss", $tournamentId, $userId, $roleString);
                        $insertSuccess = $stmt->execute();
                        
                        $pName = $person['display_name'] ?? $userId;
                        if ($insertSuccess) {
                            $successes[] = $pName . ' (added as ' . $newRole . ')';
                        } else {
                            $failures[] = $pName . ' (failed to add)';
                        }
                    }
                }
                
                $this->conn->commit();
                
                $overallSuccess = count($successes) > 0;
                return [
                    'success' => $overallSuccess,
                    'message' => $overallSuccess ? 'People processed successfully' : 'Failed to process any people',
                    'successes' => $successes,
                    'failures' => $failures
                ];
                
            } catch (Exception $e) {
                $this->conn->rollback();
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        
        // Get all people with their roles
        public function getTournamentPeople($tournamentId) {
            try {
                $sql = "SELECT tr.id, tr.user_id, tr.role, tr.seed, tr.assigned_at, u.email, u.display_name, u.blader_name 
                        FROM tournament_roles tr 
                        JOIN users u ON tr.user_id = u.id 
                        WHERE tr.tournament_id = ? 
                        ORDER BY (tr.seed = 0) ASC, tr.seed ASC, tr.assigned_at ASC";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                
                $result = $stmt->get_result();
                $people = [];
                while ($row = $result->fetch_assoc()) {
                    $people[] = $row;
                }
                
                return [
                    'success' => true,
                    'people' => $people,
                    'count' => count($people)
                ];
                
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        
        // Remove person from tournament
        public function removePerson($tournamentId, $userId) {
            $sessionUserId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
            
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
                    return ['success' => false, 'message' => 'Only tournament creator can remove people'];
                }
                
                // Remove person
                $sql = "DELETE FROM tournament_roles WHERE tournament_id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ss", $tournamentId, $userId);
                
                if ($stmt->execute()) {
                    return ['success' => true, 'message' => 'Person removed successfully'];
                } else {
                    return ['success' => false, 'message' => 'Failed to remove person'];
                }
                
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        
        // Get available users (not already in tournament)
        public function getAvailableUsers($tournamentId) {
            try {
                $sql = "SELECT u.id, u.email, u.display_name, u.blader_name 
                        FROM users u 
                        WHERE u.id NOT IN (
                            SELECT user_id FROM tournament_roles 
                            WHERE tournament_id = ? AND (FIND_IN_SET('player', role) > 0 OR FIND_IN_SET('judge', role) > 0)
                        )
                        ORDER BY u.display_name, u.blader_name, u.email";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                
                $result = $stmt->get_result();
                $users = [];
                while ($row = $result->fetch_assoc()) {
                    $users[] = $row;
                }
                
                return [
                    'success' => true,
                    'users' => $users,
                    'count' => count($users)
                ];
                
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        
        // Shuffles seeds for all participants in a tournament
        public function shuffleSeeds($tournamentId) {
            $sessionUserId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
            
            try {
                // Check if tournament exists and user is the organizer/creator
                $sql = "SELECT id, created_by FROM tournaments WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $tournament = $stmt->get_result()->fetch_assoc();
                
                if (!$tournament) {
                    return ['success' => false, 'message' => 'Tournament not found'];
                }
                
                if ($tournament['created_by'] !== $sessionUserId) {
                    return ['success' => false, 'message' => 'Only tournament creator can shuffle seeds'];
                }
                
                // Get all player roles
                $sql = "SELECT user_id FROM tournament_roles WHERE tournament_id = ? AND (FIND_IN_SET('player', role) > 0 OR FIND_IN_SET('both', role) > 0)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("s", $tournamentId);
                $stmt->execute();
                $playerIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
                
                if (empty($playerIds)) {
                    return ['success' => true, 'message' => 'No players to shuffle'];
                }
                
                // Shuffle and update
                shuffle($playerIds);
                
                $this->conn->begin_transaction();
                foreach ($playerIds as $index => $uid) {
                    $seed = $index + 1;
                    $sql = "UPDATE tournament_roles SET seed = ? WHERE tournament_id = ? AND user_id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("iis", $seed, $tournamentId, $uid);
                    $stmt->execute();
                }
                $this->conn->commit();
                
                return ['success' => true, 'message' => 'Seeds shuffled successfully'];
                
            } catch (Exception $e) {
                if ($this->conn && $this->conn->in_transaction) $this->conn->rollback();
                return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
    }

    // Handle API requests
    $requestMethod = $_SERVER['REQUEST_METHOD'];
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    $roles = new TournamentRoles();
    $response = ['success' => false, 'message' => 'Unknown API error'];
    
    switch ($action) {
        case 'addPeople':
            if ($requestMethod === 'POST' && isset($_POST['tournament_id']) && isset($_POST['people'])) {
                $response = $roles->addPeopleWithRoles($_POST['tournament_id'], json_decode($_POST['people'], true));
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'getPeople':
            if ($requestMethod === 'GET' && isset($_GET['tournament_id'])) {
                $response = $roles->getTournamentPeople($_GET['tournament_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'removePerson':
            if ($requestMethod === 'POST' && isset($_POST['tournament_id']) && isset($_POST['user_id'])) {
                $response = $roles->removePerson($_POST['tournament_id'], $_POST['user_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'getAvailableUsers':
             if ($requestMethod === 'GET' && isset($_GET['tournament_id'])) {
                $response = $roles->getAvailableUsers($_GET['tournament_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        case 'shuffleSeeds':
            if ($requestMethod === 'POST' && isset($_POST['tournament_id'])) {
                $response = $roles->shuffleSeeds($_POST['tournament_id']);
            } else {
                $response = ['success' => false, 'message' => 'Invalid request'];
            }
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
}

ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($response);
?>

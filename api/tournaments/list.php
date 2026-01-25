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

require_once '../config/database.php';
require_once '../config/cors.php';

try {
    class TournamentList {
        private $conn;

        public function __construct() {
            $database = new Database();
            $this->conn = $database->getConnection();
        }

        public function getTournaments($status = null) {
            $query = "SELECT t.*,
                              COALESCE(u.display_name, u.blader_name, 'Unknown') as created_by_name,
                              (SELECT COUNT(*) FROM tournament_roles tr
                               WHERE tr.tournament_id = t.id
                               AND (tr.role LIKE '%player%' OR tr.role = 'player')) as participant_count
                      FROM tournaments t
                      LEFT JOIN users u ON t.created_by = u.id";
            
            if ($status) {
                $query .= " WHERE t.status = ? ORDER BY t.created_at DESC";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("s", $status);
            } else {
                $query .= " ORDER BY t.created_at DESC";
                $stmt = $this->conn->prepare($query);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();

            $tournaments = array();
            while ($row = $result->fetch_assoc()) {
                $tournaments[] = $row;
            }

            return array("success" => true, "tournaments" => $tournaments);
        }

        public function createTournament($data) {
            session_start();
            $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
            
            $query = "INSERT INTO tournaments (name, description, date, location, max_participants, status, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ssssiss", 
                $data->name,
                $data->description,
                $data->date,
                $data->location,
                $data->max_participants,
                $data->status,
                $userId
            );

            if ($stmt->execute()) {
                $tournamentId = $this->conn->insert_id;
                return array("success" => true, "tournament_id" => $tournamentId);
            } else {
                return array("success" => false, "message" => "Tournament creation failed");
            }
        }

        public function joinTournament($tournamentId) {
            session_start();
            $userId = $_SESSION['user_id'] ?? 'fd55ab22-377e-404c-bab8-54a229940352';
            
            // Check if already joined
            $checkQuery = "SELECT id FROM tournament_participants WHERE tournament_id = ? AND user_id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bind_param("is", $tournamentId, $userId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows > 0) {
                return array("success" => false, "message" => "Already joined this tournament");
            }

            // Join tournament
            $query = "INSERT INTO tournament_participants (tournament_id, user_id) VALUES (?, ?)";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("is", $tournamentId, $userId);

            if ($stmt->execute()) {
                return array("success" => true, "message" => "Successfully joined tournament");
            } else {
                return array("success" => false, "message" => "Failed to join tournament");
            }
        }
    }

    // Handle request
    $tournament = new TournamentList();
    $response = ['success' => false, 'message' => 'Unknown request'];

    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $response = $tournament->getTournaments($status);
    } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        
        if (isset($data->action)) {
            if ($data->action == 'create') {
                $response = $tournament->createTournament($data);
            } elseif ($data->action == 'join') {
                $response = $tournament->joinTournament($data->tournament_id);
            } else {
                $response = array("success" => false, "message" => "Invalid action");
            }
        } else {
            $response = array("success" => false, "message" => "Action not specified");
        }
    } else {
        $response = array("success" => false, "message" => "Invalid request method");
    }

} catch (Exception $e) {
    http_response_code(500);
    $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
}

ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($response);
?>

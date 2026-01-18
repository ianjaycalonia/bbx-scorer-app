<?php
// Disable error reporting to prevent warnings in JSON output
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../config/cors.php';

class Teams {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // Get teams with autocomplete search
    public function searchTeams($query) {
        $searchTerm = '%' . strtolower($query) . '%';
        $sql = "SELECT id, name FROM teams WHERE LOWER(name) LIKE ? ORDER BY name ASC LIMIT 10";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $teams = array();
        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
        
        return array("success" => true, "teams" => $teams);
    }

    // Create new team
    public function createTeam($teamName) {
        // Check if team already exists (case-insensitive)
        $checkSql = "SELECT id FROM teams WHERE LOWER(name) = LOWER(?)";
        $checkStmt = $this->conn->prepare($checkSql);
        $checkStmt->bind_param("s", $teamName);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            $existingTeam = $checkResult->fetch_assoc();
            return array("success" => true, "team" => $existingTeam, "message" => "Team already exists");
        }

        // Create new team
        $insertSql = "INSERT INTO teams (name) VALUES (?)";
        $insertStmt = $this->conn->prepare($insertSql);
        $insertStmt->bind_param("s", $teamName);
        
        if ($insertStmt->execute()) {
            $teamId = $this->conn->insert_id;
            return array("success" => true, "team" => array("id" => $teamId, "name" => $teamName), "message" => "Team created successfully");
        } else {
            return array("success" => false, "message" => "Failed to create team");
        }
    }

    // Get team by ID
    public function getTeamById($teamId) {
        $sql = "SELECT id, name FROM teams WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $teamId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return array("success" => true, "team" => $result->fetch_assoc());
        } else {
            return array("success" => false, "message" => "Team not found");
        }
    }
}

// Handle request
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(array("success" => false, "message" => "Not authenticated"));
    exit();
}

$teams = new Teams();

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // Search teams for autocomplete
    if (isset($_GET['action']) && $_GET['action'] == 'search' && isset($_GET['q'])) {
        $query = $_GET['q'];
        $response = $teams->searchTeams($query);
    } else {
        $response = array("success" => false, "message" => "Invalid action");
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    
    if (isset($data->action) && $data->action == 'create' && isset($data->team_name)) {
        $response = $teams->createTeam($data->team_name);
    } else {
        $response = array("success" => false, "message" => "Invalid action");
    }
} else {
    $response = array("success" => false, "message" => "Invalid request method");
}

echo json_encode($response);
?>

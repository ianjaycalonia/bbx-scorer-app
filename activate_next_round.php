<?php
require_once 'api/config/database.php';

// Get tournament ID from URL or set it manually
$tournamentId = $_GET['tournament_id'] ?? 1; // Change this to your tournament ID

if (empty($tournamentId)) {
    die("Please provide tournament_id parameter");
}

$database = new Database();
$conn = $database->getConnection();

try {
    // Get current tournament details
    $sql = "SELECT tournament_type, current_stage FROM tournaments WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tournamentId);
    $stmt->execute();
    $tournament = $stmt->get_result()->fetch_assoc();

    if (!$tournament) {
        die("Tournament not found");
    }

    echo "Tournament Type: " . $tournament['tournament_type'] . "<br>";
    echo "Current Stage: " . $tournament['current_stage'] . "<br>";

    // Get the current round number
    $sql = "SELECT MAX(round_number) as max_rn FROM tournament_rounds WHERE tournament_id = ? AND status = 'completed'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tournamentId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    $currentRoundNum = $result['max_rn'] ?? 0;
    $nextRoundNum = $currentRoundNum + 1;

    echo "Current Round: $currentRoundNum<br>";
    echo "Next Round: $nextRoundNum<br>";

    // Activate the next round
    if ($tournament['tournament_type'] === 'single_elimination') {
        $stage = 1;
        $sql = "UPDATE tournament_rounds SET status = 'active' 
                WHERE tournament_id = ? AND round_number = ? AND stage = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $tournamentId, $nextRoundNum, $stage);
        $result = $stmt->execute();
        
        if ($result) {
            echo "Successfully activated Round $nextRoundNum for Single Elimination<br>";
            
            // Run match engine to assign judges
            require_once 'api/tournaments/match_engine.php';
            $engine = new MatchEngine($database, $tournamentId);
            $assignmentResult = $engine->runAssignment();
            
            echo "Match engine result: " . json_encode($assignmentResult) . "<br>";
        } else {
            echo "Failed to activate round<br>";
        }
    } else {
        echo "This script is for single elimination tournaments only<br>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>

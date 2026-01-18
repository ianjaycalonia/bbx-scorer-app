<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../config/cors.php';

header('Content-Type: application/json');

class StatsService {
    private $conn;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }
    }

    private function getCurrentUserId() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get lifetime stats for current user
     * Returns champion counts, win rate, average rank, best finish
     */
    public function getLifetimeStats() {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }

        $stats = [
            'championCount' => 0,
            'firstRunnerUpCount' => 0,
            'secondRunnerUpCount' => 0,
            'winRate' => 0,
            'avgPlacement' => null,
            'bestFinish' => null
        ];

        // Get all completed tournaments the user participated in
        $query = "SELECT t.id
                  FROM tournaments t
                  INNER JOIN tournament_roles tr ON t.id = tr.tournament_id
                  WHERE tr.user_id = ?
                  AND tr.role LIKE '%player%'
                  AND t.status = 'completed'
                  ORDER BY t.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $placements = [];
        $bestFinish = null;

        while ($tournament = $result->fetch_assoc()) {
            $tournamentId = $tournament['id'];
            $placement = $this->getTournamentPlacement($tournamentId, $userId);
            
            if ($placement) {
                $placements[] = $placement;
                
                // Count podium finishes
                if ($placement == 1) {
                    $stats['championCount']++;
                } elseif ($placement == 2) {
                    $stats['firstRunnerUpCount']++;
                } elseif ($placement == 3) {
                    $stats['secondRunnerUpCount']++;
                }

                // Track best finish
                if ($bestFinish === null || $placement < $bestFinish) {
                    $bestFinish = $placement;
                }
            }
        }

        // Get total wins and matches across all completed tournaments (Swiss rounds only)
        $query = "SELECT
                    SUM(CASE WHEN tm.winner_id = ? THEN 1 ELSE 0 END) as wins,
                    COUNT(*) as total_matches
                  FROM tournament_matches tm
                  JOIN tournament_rounds tr ON tm.round_id = tr.id
                  JOIN tournament_roles tr2 ON tm.tournament_id = tr2.tournament_id
                  WHERE tr2.user_id = ?
                  AND tr2.role LIKE '%player%'
                  AND tr2.tournament_id IN (
                    SELECT id FROM tournaments WHERE status = 'completed'
                  )
                  AND tr.stage = 1
                  AND tm.status = 'completed'
                  AND (tm.player1_id = ? OR tm.player2_id = ?)";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ssss", $userId, $userId, $userId, $userId);
        $stmt->execute();
        $matchResult = $stmt->get_result()->fetch_assoc();

        $totalWins = $matchResult['wins'] ?? 0;
        $totalMatches = $matchResult['total_matches'] ?? 0;

        // Calculate win rate
        if ($totalMatches > 0) {
            $stats['winRate'] = round(($totalWins / $totalMatches) * 100, 1);
        }

        // Calculate average placement
        if (!empty($placements)) {
            $stats['avgPlacement'] = round(array_sum($placements) / count($placements), 1);
        }

        // Format best finish
        if ($bestFinish) {
            $suffixes = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
            $suffix = $suffixes[$bestFinish % 10] ?? 'th';
            $stats['bestFinish'] = $bestFinish . $suffix;
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Get user's placement in a tournament based on final rankings
     */
    private function getTournamentPlacement($tournamentId, $userId) {
        // 1. Check Finals (1st & 2nd)
        $sql = "SELECT winner_id, player1_id, player2_id 
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tm.tournament_id = ? AND tr.stage = 2 AND tm.next_match_id IS NULL AND tm.match_number = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $tournamentId);
        $stmt->execute();
        $finals = $stmt->get_result()->fetch_assoc();

        if ($finals && $finals['winner_id']) {
            if ($finals['winner_id'] == $userId) {
                return 1;
            }
            $runnerUp = ($finals['winner_id'] == $finals['player1_id']) ? $finals['player2_id'] : $finals['player1_id'];
            if ($runnerUp == $userId) {
                return 2;
            }
        }

        // 2. Check Consolation Matches (3rd-10th)
        $specialMatches = [
            99 => [3, 4], // Match 99: 3rd/4th
            98 => [5, 6], // Match 98: 5th/6th
            97 => [7, 8], // Match 97: 7th/8th
            96 => [9, 10] // Match 96: 9th/10th
        ];

        foreach ($specialMatches as $mNum => $places) {
            $sql = "SELECT winner_id, player1_id, player2_id FROM tournament_matches 
                    WHERE tournament_id = ? AND match_number = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $tournamentId, $mNum);
            $stmt->execute();
            $match = $stmt->get_result()->fetch_assoc();

            if ($match && $match['winner_id']) {
                if ($match['winner_id'] == $userId) {
                    return $places[0];
                }
                $loser = ($match['winner_id'] == $match['player1_id']) ? $match['player2_id'] : $match['player1_id'];
                if ($loser == $userId) {
                    return $places[1];
                }
            }
        }

        return null;
    }

    /**
     * Get stats for the most recent tournament
     */
    public function getRecentTournamentStats() {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }

        $stats = [
            'placement' => null,
            'winRate' => 0,
            'avgScore' => 0,
            'bestFinish' => null
        ];

        // Get most recent tournament the user participated in
        $query = "SELECT t.id, t.name, t.status, t.tournament_type
                  FROM tournaments t
                  INNER JOIN tournament_roles tr ON t.id = tr.tournament_id
                  WHERE tr.user_id = ?
                  AND tr.role LIKE '%player%'
                  ORDER BY t.created_at DESC
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $tournament = $result->fetch_assoc();

        if (!$tournament) {
            return ['success' => true, 'stats' => $stats];
        }

        $tournamentId = $tournament['id'];

        // Get placement using the same logic as podium display
        $placement = $this->getTournamentPlacement($tournamentId, $userId);
        if ($placement) {
            $suffixes = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
            $suffix = $suffixes[$placement % 10] ?? 'th';
            $stats['placement'] = $placement . $suffix;
        }

        // Get win rate (Swiss rounds only - stage = 1) and avg score from tournament_matches
        $matchStatsQuery = "SELECT
                              SUM(CASE WHEN tm.winner_id = ? THEN 1 ELSE 0 END) as wins,
                              COUNT(*) as total_matches,
                              AVG(CASE
                                  WHEN tm.player1_id = ? THEN tm.player1_score
                                  WHEN tm.player2_id = ? THEN tm.player2_score
                                  ELSE 0
                              END) as avg_score
                            FROM tournament_matches tm
                            JOIN tournament_rounds tr ON tm.round_id = tr.id
                            WHERE tm.tournament_id = ?
                            AND tr.stage = 1
                            AND tm.status = 'completed'
                            AND (tm.player1_id = ? OR tm.player2_id = ?)";

        $stmt = $this->conn->prepare($matchStatsQuery);
        $stmt->bind_param("ssssss", $userId, $userId, $userId, $tournamentId, $userId, $userId);
        $stmt->execute();
        $matchStats = $stmt->get_result()->fetch_assoc();

        if ($matchStats['total_matches'] > 0) {
            $stats['winRate'] = round(($matchStats['wins'] / $matchStats['total_matches']) * 100, 1);
            $stats['avgScore'] = round($matchStats['avg_score'] ?? 0, 1);
        }

        // Get best finish type (KO, SOS, etc.) from match_finishes
        $bestFinishQuery = "SELECT mf.finish_type
                           FROM match_finishes mf
                           JOIN tournament_matches tm ON mf.match_id = tm.id
                           WHERE tm.tournament_id = ?
                           AND mf.player_id = ?
                           LIMIT 1";

        $stmt = $this->conn->prepare($bestFinishQuery);
        $stmt->bind_param("is", $tournamentId, $userId);
        $stmt->execute();
        $bestFinishResult = $stmt->get_result()->fetch_assoc();

        if ($bestFinishResult && $bestFinishResult['finish_type']) {
            $stats['bestFinish'] = $bestFinishResult['finish_type'];
        }

        return ['success' => true, 'stats' => $stats];
    }
}

// Handle request
try {
    $statsService = new StatsService();

    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'lifetime':
            $response = $statsService->getLifetimeStats();
            break;
        case 'recent':
            $response = $statsService->getRecentTournamentStats();
            break;
        default:
            $response = ['success' => false, 'message' => 'Invalid action'];
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>

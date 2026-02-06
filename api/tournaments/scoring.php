<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/match_engine.php';

class ScoringService
{
    private $conn;
    private $tournamentId;

    public function __construct($database, $tournamentId)
    {
        $this->conn = $database->getConnection();
        $this->tournamentId = $tournamentId;

        // Initialize bye tracking session array if not exists
        if (!isset($_SESSION['bye_tracking'])) {
            $_SESSION['bye_tracking'] = [];
        }
        if (!isset($_SESSION['bye_tracking'][$this->tournamentId])) {
            $_SESSION['bye_tracking'][$this->tournamentId] = [
                'byes_awarded' => [],
                'rounds_completed' => []
            ];
        }
    }

    public function recordResult($matchId, $p1score = 0, $p2score = 0, $finishes = [], $p1Id = null, $p2Id = null)
    {
        $p1score = (int) $p1score;
        $p2score = (int) $p2score;

        // Constraint: Match must be played to at least 4 points
        if ($p1score < 4 && $p2score < 4) {
            throw new Exception("Invalid Score: Matches must be played to 4 points.");
        }

        $callerId = $_SESSION['user_id'] ?? null;
        if (!$callerId)
            throw new Exception("Authentication required.");

        // Fetch match, tournament owner, and current judge
        $sql = "SELECT tm.player1_id, tm.player2_id, tm.tournament_id, tm.round_id, t.created_by, ma.judge_id 
                FROM tournament_matches tm
                JOIN tournaments t ON tm.tournament_id = t.id
                LEFT JOIN match_assignments ma ON tm.id = ma.match_id
                WHERE tm.id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $matchId);
        $stmt->execute();
        $matchInfo = $stmt->get_result()->fetch_assoc();

        if (!$matchInfo)
            throw new Exception("Match not found.");

        // Build robust mapping from submitted Slot/ID to Database Column and PlayerID
        // This handles cases where p1Id/p2Id are provided (swapped or not)
        $idToColumn = [
            $matchInfo['player1_id'] => 'player1_score',
            $matchInfo['player2_id'] => 'player2_score'
        ];

        // Final scores to update in DB
        $finalP1Score = 0;
        $finalP2Score = 0;

        if ($p1Id !== null && $p2Id !== null) {
            // Map submitted p1score to the column associated with p1Id
            if (isset($idToColumn[$p1Id])) {
                if ($idToColumn[$p1Id] === 'player1_score') {
                    $finalP1Score = $p1score;
                    $finalP2Score = $p2score;
                } else {
                    $finalP1Score = $p2score;
                    $finalP2Score = $p1score;
                }
            }
        } else {
            // Fallback for calls that don't pass IDs (though we should encourage IDs)
            $finalP1Score = $p1score;
            $finalP2Score = $p2score;
        }

        // Authorization: judge, creator, or organizer
        $isJudge = ($callerId == $matchInfo['judge_id']);
        $isCreator = ($callerId == $matchInfo['created_by']);
        $isOrganizer = false;

        if (!$isCreator && !$isJudge) {
            $sql = "SELECT role FROM tournament_roles WHERE tournament_id = ? AND user_id = ? LIMIT 1";
            $roleStmt = $this->conn->prepare($sql);
            $roleStmt->bind_param("ii", $matchInfo['tournament_id'], $callerId);
            $roleStmt->execute();
            $roleRow = $roleStmt->get_result()->fetch_assoc();
            if ($roleRow && isset($roleRow['role'])) {
                $roles = explode(',', $roleRow['role']);
                foreach ($roles as $role) {
                    if (trim($role) === 'organizer') {
                        $isOrganizer = true;
                        break;
                    }
                }
            }
        }

        if (!$isJudge && ($isCreator || $isOrganizer)) {
            if (!empty($matchInfo['judge_id'])) {
                throw new Exception("Cannot override an assigned judge. Please unassign the judge first.");
            }
        }

        // Auto-determine winner
        $winnerId = null;
        $loserId = null;
        if ($finalP1Score > $finalP2Score) {
            $winnerId = $matchInfo['player1_id'];
            $loserId = $matchInfo['player2_id'];
        } else if ($finalP2Score > $finalP1Score) {
            $winnerId = $matchInfo['player2_id'];
            $loserId = $matchInfo['player1_id'];
        }

        $this->conn->begin_transaction();
        try {
            // Re-check authorization inside transaction to ensure consistent data view
            // Fetch judge_id from matches table (permanent) and match_assignments (active)
            $sql = "SELECT tm.judge_id as permanent_judge_id, ma.judge_id as active_judge_id, t.created_by 
                    FROM tournament_matches tm
                    JOIN tournaments t ON tm.tournament_id = t.id
                    LEFT JOIN match_assignments ma ON tm.id = ma.match_id
                    WHERE tm.id = ? FOR UPDATE";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $matchId);
            $stmt->execute();
            $currentMatchInfo = $stmt->get_result()->fetch_assoc();

            // Re-verify authorization with fresh data from within transaction
            $isJudge = ($callerId == $currentMatchInfo['permanent_judge_id'] || $callerId == $currentMatchInfo['active_judge_id']);
            $isCreator = ($callerId == $currentMatchInfo['created_by']);
            $isOrganizer = false;

            if (!$isCreator && !$isJudge) {
                $sql = "SELECT role FROM tournament_roles WHERE tournament_id = ? AND user_id = ? LIMIT 1";
                $roleStmt = $this->conn->prepare($sql);
                $roleStmt->bind_param("ii", $matchInfo['tournament_id'], $callerId);
                $roleStmt->execute();
                $roleRow = $roleStmt->get_result()->fetch_assoc();
                if ($roleRow && isset($roleRow['role'])) {
                    $roles = explode(',', $roleRow['role']);
                    foreach ($roles as $role) {
                        if (trim($role) === 'organizer') {
                            $isOrganizer = true;
                            break;
                        }
                    }
                }
            }

            if (!$isJudge && ($isCreator || $isOrganizer)) {
                if (!empty($currentMatchInfo['judge_id'])) {
                    throw new Exception("Cannot override an assigned judge. Please unassign the judge first.");
                }
            }

            if (!$isJudge && !$isCreator && !$isOrganizer) {
                $judgeId = $currentMatchInfo['judge_id'] ?? 'NULL';
                $creatorId = $currentMatchInfo['created_by'] ?? 'NULL';
                throw new Exception("Unauthorized. You are not the judge or organizer. (Caller: $callerId, Judge: $judgeId, Creator: $creatorId)");
            }
            $roundId = $matchInfo['round_id'];

            // 1. Update match
            $sql = "UPDATE tournament_matches SET winner_id = ?, player1_score = ?, player2_score = ?, status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("siii", $winnerId, $finalP1Score, $finalP2Score, $matchId);
            $stmt->execute();

            // 2. Replace detailed finishes for this match
            $deleteFinishesSql = "DELETE FROM match_finishes WHERE match_id = ?";
            $deleteFinishesStmt = $this->conn->prepare($deleteFinishesSql);
            $deleteFinishesStmt->bind_param("i", $matchId);
            $deleteFinishesStmt->execute();

            if (!empty($finishes)) {
                $sqlFinish = "INSERT INTO match_finishes (match_id, player_id, finish_type, points) VALUES (?, ?, ?, ?)";
                $stmtFinish = $this->conn->prepare($sqlFinish);
                foreach ($finishes as $f) {
                    $submittedPlayer = $f['player'] ?? null;
                    if (!$submittedPlayer)
                        continue;

                    // Improved PlayerId Resolution:
                    // 1. If it's literally 'p1' or 'p2', map to corresponding ID (using context IDs if provided)
                    // 2. Otherwise treatment it as the actual Player ID if it matches either player in the match

                    $playerId = null;
                    $submittedPlayerStr = (string) $submittedPlayer;

                    if ($submittedPlayerStr === 'p1') {
                        $playerId = $p1Id ?? $matchInfo['player1_id'];
                    } elseif ($submittedPlayerStr === 'p2') {
                        $playerId = $p2Id ?? $matchInfo['player2_id'];
                    } elseif ($submittedPlayerStr !== 'null' && !empty($submittedPlayerStr)) {
                        // Trust the submitted ID if it belongs to this match
                        if ($submittedPlayerStr === $matchInfo['player1_id'] || $submittedPlayerStr === $matchInfo['player2_id']) {
                            $playerId = $submittedPlayerStr;
                        } else {
                            // Last resort fallback for safety
                            $playerId = ($submittedPlayerStr === 'p1') ? $matchInfo['player1_id'] : $matchInfo['player2_id'];
                        }
                    }

                    // Strict check: if no valid player ID or it's still 'null', SKIP this finish to avoid FK error
                    if (!$playerId || $playerId === 'null')
                        continue;

                    $finishType = $f['type'];
                    $pts = (int) $f['points'];
                    $stmtFinish->bind_param("issi", $matchId, $playerId, $finishType, $pts);
                    $stmtFinish->execute();
                }
            }

            // 1.5 Remove from match_assignments to free up resources (stadium/judge)
            $this->conn->query("DELETE FROM match_assignments WHERE match_id = $matchId");

            // 2. Check if all matches in this round are completed
            $this->checkAndAwardByePoints($roundId);

            // 3. Trigger engine for this round
            // We pass the current connection to ensure it sees the uncommitted state
            $engine = new MatchEngine($this->conn, $this->tournamentId);
            $engine->runAssignment();

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Result recorded',
                'round_id' => $roundId,
                'winner_id' => $winnerId,
                'player1_id' => $matchInfo['player1_id'],
                'player2_id' => $matchInfo['player2_id'],
                'loser_id' => $loserId
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Check if all matches in a round are completed and award bye points if so
     */
    private function checkAndAwardByePoints($roundId)
    {
        // Get round number
        $sql = "SELECT round_number FROM tournament_rounds WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $roundId);
        $stmt->execute();
        $roundData = $stmt->get_result()->fetch_assoc();
        if (!$roundData)
            return;
        $roundNumber = (int) $roundData['round_number'];

        // Check if we've already awarded bye points for this round
        if (isset($_SESSION['bye_tracking'][$this->tournamentId]['rounds_completed'][$roundNumber])) {
            return; // Already processed
        }

        // Check if all "real" matches (non-bye) in this round are completed
        $sql = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed 
                FROM tournament_matches 
                WHERE round_id = ? AND player2_id IS NOT NULL";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $roundId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $totalMatches = (int) $result['total'];
        $completedMatches = (int) $result['completed'];

        // Only award bye points if all regular matches are completed
        // If there are no regular matches (e.g. only 1 player?), assume complete
        if ($totalMatches == $completedMatches) {
            $this->awardByePoints($roundNumber, $roundId);
            $_SESSION['bye_tracking'][$this->tournamentId]['rounds_completed'][$roundNumber] = true;
        }
    }

    /**
     * Award bye points to players who received byes in this round and finalize their matches
     */
    private function awardByePoints($roundNumber, $roundId)
    {
        if (!isset($_SESSION['bye_tracking'][$this->tournamentId]['byes_awarded'])) {
            return;
        }

        $byePoints = 0;
        foreach ($_SESSION['bye_tracking'][$this->tournamentId]['byes_awarded'] as $playerId => $byeRound) {
            if ($byeRound == $roundNumber) {
                // Find the bye match for this player in this round
                $sqlMatch = "SELECT id FROM tournament_matches WHERE round_id = ? AND player1_id = ? AND player2_id IS NULL";
                $stmtMatch = $this->conn->prepare($sqlMatch);
                $stmtMatch->bind_param("is", $roundId, $playerId);
                $stmtMatch->execute();
                $matchRow = $stmtMatch->get_result()->fetch_assoc();

                if (!$matchRow)
                    continue;
                $byeMatchId = $matchRow['id'];

                // Finalize the bye match: Set status to 'completed', set winner, set scores (4-0)
                $sqlComplete = "UPDATE tournament_matches SET status = 'completed', winner_id = ?, player1_score = 4, player2_score = 0 WHERE id = ?";
                $stmtComplete = $this->conn->prepare($sqlComplete);
                $stmtComplete->bind_param("si", $playerId, $byeMatchId);
                $stmtComplete->execute();

                // Award +2 BP as two Fault finishes (1 point each)
                for ($i = 0; $i < 2; $i++) {
                    $sql = "INSERT INTO match_finishes (match_id, player_id, finish_type, points) VALUES (?, ?, 'Fault', 1)";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("is", $byeMatchId, $playerId);
                    $stmt->execute();
                }

                $byePoints += 2;
            }
        }
    }

}

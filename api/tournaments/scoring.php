<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/match_engine.php';

class ScoringService {
    private $conn;
    private $tournamentId;

    public function __construct($database, $tournamentId) {
        $this->conn = $database->getConnection();
        $this->tournamentId = $tournamentId;
    }

    public function recordResult($matchId, $p1score = 0, $p2score = 0, $finishes = []) {
        $p1score = (int)$p1score;
        $p2score = (int)$p2score;

        // Constraint: Match must be played to at least 4 points
        if ($p1score < 4 && $p2score < 4) {
            throw new Exception("Invalid Score: Matches must be played to 4 points.");
        }
        
        $callerId = $_SESSION['user_id'] ?? null;
        if (!$callerId) throw new Exception("Authentication required.");

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

        if (!$matchInfo) throw new Exception("Match not found.");

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

        if (!$isJudge && !$isCreator && !$isOrganizer) {
            $judgeId = $matchInfo['judge_id'] ?? 'NULL';
            $creatorId = $matchInfo['created_by'] ?? 'NULL';
            throw new Exception("Unauthorized. You are not the judge or organizer. (Caller: $callerId, Judge: $judgeId, Creator: $creatorId)");
        }

        // Auto-determine winner
        $winnerId = null;
        if ($p1score > $p2score) $winnerId = $matchInfo['player1_id'];
        else if ($p2score > $p1score) $winnerId = $matchInfo['player2_id'];
        
        $this->conn->begin_transaction();
        try {
            $roundId = $matchInfo['round_id'];

            // 1. Update match
            $sql = "UPDATE tournament_matches SET winner_id = ?, player1_score = ?, player2_score = ?, status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("siii", $winnerId, $p1score, $p2score, $matchId);
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
                    $playerId = ($f['player'] === 'p1') ? $matchInfo['player1_id'] : $matchInfo['player2_id'];
                    $finishType = $f['type'];
                    $pts = (int)$f['points'];
                    $stmtFinish->bind_param("issi", $matchId, $playerId, $finishType, $pts);
                    $stmtFinish->execute();
                }
            }

            // 1.5 Remove from match_assignments to free up resources (stadium/judge)
            $this->conn->query("DELETE FROM match_assignments WHERE match_id = $matchId");

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

}

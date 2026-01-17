<?php
require_once dirname(__DIR__) . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class MatchEngine {
    private $conn;
    private $tournamentId;
    private $stageMaxRounds = [];

    public function __construct($database, $tournamentId) {
        if ($database instanceof mysqli) {
            $this->conn = $database;
        } else {
            $this->conn = $database->getConnection();
        }
        $this->tournamentId = $tournamentId;
    }

    /**
     * Main Assignment Loop (Rolling)
     * Rule 7: Stop if No stadiums, No judges, or No playable matches.
     */
    public function runAssignment() {
        $this->conn->begin_transaction();
        try {
            $assignments = [];
            $usedMatches = [];
            
            // Rule 2.1: Get all available stadiums first
            $stadiums = $this->getAllAvailableStadiums();

            foreach ($stadiums as $stadium) {
                // Rule 3: Match Selection (FIFO, active round)
                // Filter out matches already assigned in this run
                $matches = $this->getPlayableMatches($usedMatches);
                $matches = $this->prioritizeConsolationsFirst($matches);
                
                if (empty($matches)) {
                    // No more matches to play at all
                    break;
                }

                $foundForThisStadium = false;
                foreach ($matches as $match) {
                    if (!$this->isMatchReadyForAssignment($match)) {
                        continue;
                    }

                    $matchDetails = $this->getMatchDetails($match['id']);
                    if (!$matchDetails) {
                        continue;
                    }
                    $matchDetails['stage'] = isset($match['stage']) ? (int)$match['stage'] : null;
                    $matchDetails['round_number'] = isset($match['round_number']) ? (int)$match['round_number'] : null;
                    $matchDetails['match_number'] = isset($match['match_number']) ? (int)$match['match_number'] : null;

                    $designatedJudgeId = $this->getDesignatedJudgeId($matchDetails);
                    $judge = null;

                    if ($designatedJudgeId) {
                        if ($this->isJudgeEligible($designatedJudgeId, $matchDetails)) {
                            $judge = ['id' => $designatedJudgeId];
                        } else {
                            // Wait for designated semifinal/final judge to be available
                            continue;
                        }
                    } else {
                        $judge = $this->getBestJudgeForMatch($match['id']);
                    }
                    
                    if ($judge) {
                        // 1.7 Assignment must be atomic
                        $this->assignMatch($match['id'], $judge['id'], $stadium['id']);
                        
                        $assignments[] = [
                            'match_id' => $match['id'],
                            'judge_id' => $judge['id'],
                            'stadium_id' => $stadium['id']
                        ];
                        $usedMatches[] = $match['id'];
                        $foundForThisStadium = true;
                        break; // Success for this stadium, move to next stadium
                    } else {
                        /**
                         * Rule 6.1: Block if no judge available.
                         * We update the reason, but we'll try the next match in FIFO for this stadium.
                         */
                        $this->blockMatch($match['id'], "No eligible judge available (likely player-role overlap or fatigue).");
                    }
                }
                // If not found for this stadium, we just continue to the next stadium loop
            }

            $this->conn->commit();
            return ['success' => true, 'assignments' => $assignments];
        } catch (Exception $e) {
            if ($this->conn) {
                try { $this->conn->rollback(); } catch (Exception $re) {}
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Rule 2.1 & 2.3: Get ALL available stadiums ordered by ID
     */
    private function getAllAvailableStadiums() {
        $sql = "SELECT s.id, s.name 
                FROM tournament_stadiums s
                WHERE s.tournament_id = ? 
                AND NOT EXISTS (SELECT 1 FROM match_assignments ma WHERE ma.stadium_id = s.id)
                ORDER BY s.id ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Rule 3.1 & 3.2: Get all playable matches in FIFO order
     */
    private function getPlayableMatches($excludeIds = []) {
        $excludeClause = "";
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = " AND tm.id NOT IN ($placeholders)";
        }

        $sql = "SELECT tm.id, tm.match_number, tm.player1_id, tm.player2_id, tm.round_id, tr.round_number, tr.stage 
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                JOIN tournaments t ON tm.tournament_id = t.id
                WHERE tm.tournament_id = ? 
                AND tm.status IN ('scheduled', 'blocked')
                AND (tr.status = 'active' OR (t.current_stage = 2 AND tr.stage = 2 AND tr.round_number = (
                    SELECT MIN(tr2.round_number) 
                    FROM tournament_matches tm2 
                    JOIN tournament_rounds tr2 ON tm2.round_id = tr2.id 
                    WHERE tm2.tournament_id = tm.tournament_id 
                    AND tm2.status IN ('scheduled', 'blocked')
                    AND tr2.stage = 2
                )))
                AND tm.player1_id IS NOT NULL AND tm.player2_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM match_assignments ma WHERE ma.match_id = tm.id)
                -- 1.5 Players cannot be in two matches at once
                AND NOT EXISTS (
                    SELECT 1 FROM tournament_matches active
                    WHERE active.tournament_id = ?
                    AND active.status IN ('assigned', 'in_progress')
                    AND (active.player1_id IN (tm.player1_id, tm.player2_id) 
                         OR active.player2_id IN (tm.player1_id, tm.player2_id))
                )
                -- 1.6 Players cannot play while judging another match
                AND NOT EXISTS (
                    SELECT 1 FROM match_assignments ma
                    JOIN tournament_matches active_match ON ma.match_id = active_match.id
                    WHERE active_match.status IN ('assigned', 'in_progress')
                    AND ma.judge_id IN (tm.player1_id, tm.player2_id)
                )
                $excludeClause
                ORDER BY tr.round_number ASC, tm.created_at ASC, tm.match_number ASC";
        
        $stmt = $this->conn->prepare($sql);
        
        $params = array_merge([$this->tournamentId, $this->tournamentId], $excludeIds);
        $types = "ii" . str_repeat("i", count($excludeIds));
        $stmt->bind_param($types, ...$params);
        
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Rule 4 & 5: Judge selection with fatigue score
     */
    private function getBestJudgeForMatch($matchId) {
        $match = $this->getMatchDetails($matchId);
        if (!$match) return null;

        // Fetch all potential judges for this tournament who have accepted
        $sql = "SELECT tr.user_id as id, u.display_name
                FROM tournament_roles tr
                JOIN users u ON tr.user_id = u.id
                WHERE tr.tournament_id = ? AND (FIND_IN_SET('judge', tr.role) > 0) AND tr.status = 'accepted'";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $judges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $candidates = [];
        foreach ($judges as $judge) {
            if ($this->isJudgeEligible($judge['id'], $match)) {
                $score = $this->calculateJudgeScore($judge['id'], $match['round_id'], $match);
                $candidates[] = array_merge($judge, ['score' => $score]);
            }
        }

        if (empty($candidates)) return null;

        /**
         * Rule 5.3: Choose highest score
         * Tie-breaker: Lowest judge_id
         */
        usort($candidates, function($a, $b) {
            if ($b['score'] != $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            // Rule 5.3: Tie-breaker is Lowest judge_id
            return strcmp($a['id'], $b['id']);
        });

        return $candidates[0];
    }

    /**
     * Rule 4: Judge Eligibility Filter - Optimized to prioritize non-playing judges
     */
    private function isJudgeEligible($judgeId, $match) {
        // 1.1 Match-active assignment: One active assignment per judge
        $sql = "SELECT status FROM tournament_matches tm
                JOIN match_assignments ma ON tm.id = ma.match_id
                WHERE ma.judge_id = ? AND tm.status IN ('assigned', 'in_progress')";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $judgeId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) return false;

        // 1.3 No self-judging
        if ($match['player1_id'] === $judgeId || $match['player2_id'] === $judgeId) return false;

        // Rule 4: Has not already judged this exact match
        $sql = "SELECT 1 FROM tournament_matches 
                WHERE judge_id = ? AND player1_id = ? AND player2_id = ? AND status = 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $judgeId, $match['player1_id'], $match['player2_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) return false;

        return true;
    }

    /**
     * Optimized judge scoring that prioritizes non-playing judges
     */
    private function calculateJudgeScore($judgeId, $roundId, $match = null) {
        // Base score starts at 100
        $score = 100;

        // Check if judge is also a player in this tournament
        $isPlayer = $this->isJudgeAlsoPlayer($judgeId);
        if ($isPlayer) {
            // Penalty for being a player (prefer non-playing judges)
            $score -= 30;
        }

        // Check if judge is currently playing in another match
        $isCurrentlyPlaying = $this->isJudgeCurrentlyPlaying($judgeId);
        if ($isCurrentlyPlaying) {
            // Heavy penalty for currently playing
            $score -= 50;
        }

        // Fatigue calculation (original logic)
        $sql = "SELECT COUNT(*) FROM tournament_matches
                WHERE judge_id = ? AND round_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $judgeId, $roundId);
        $stmt->execute();
        $Mj = $stmt->get_result()->fetch_row()[0];

        $sql = "SELECT COUNT(*) FROM tournament_matches WHERE round_id = ? AND status = 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $roundId);
        $stmt->execute();
        $Mr = max(1, $stmt->get_result()->fetch_row()[0]);

        $fatigueWeight = 20; // Reduced fatigue weight
        $fatiguePenalty = ($Mj / $Mr) * $fatigueWeight;
        $score -= $fatiguePenalty;

        return max(0, $score);
    }

    /**
     * Check if judge is also a player in this tournament
     */
    private function isJudgeAlsoPlayer($judgeId) {
        $sql = "SELECT 1 FROM tournament_roles 
                WHERE tournament_id = ? AND user_id = ? AND (FIND_IN_SET('player', role) > 0)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $this->tournamentId, $judgeId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /**
     * Check if judge is currently playing in another active match
     */
    private function isJudgeCurrentlyPlaying($judgeId) {
        $sql = "SELECT 1 FROM tournament_matches
                WHERE (player1_id = ? OR player2_id = ?) AND status IN ('assigned', 'in_progress')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $judgeId, $judgeId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    private function isMatchReadyForAssignment($match) {
        if (!isset($match['stage']) || !isset($match['round_number'])) {
            return true;
        }

        if ((int)$match['stage'] !== 2) {
            return true;
        }

        $roundNumber = (int)$match['round_number'];
        $maxRound = $this->getMaxRoundNumber(2);

        if ($maxRound < 3 || $roundNumber !== ($maxRound - 1)) {
            return true;
        }

        // Delay semifinals until all quarterfinal matches are completed
        return $this->areRoundMatchesComplete(2, $roundNumber - 1);
    }

    private function getDesignatedJudgeId($match) {
        if (!isset($match['stage'], $match['round_number'], $match['match_number'])) {
            return null;
        }

        if ((int)$match['stage'] !== 2) {
            return null;
        }

        if ((int)$match['match_number'] >= 90) {
            // Consolation / placement matches can use normal assignment
            return null;
        }

        $maxRound = $this->getMaxRoundNumber(2);
        if ($maxRound < 2) {
            return null;
        }

        $roundNumber = (int)$match['round_number'];
        $sourceRound = null;

        if ($roundNumber === $maxRound) {
            // Finals reuse the semifinal judge
            $sourceRound = $maxRound - 1;
        } elseif ($roundNumber === $maxRound - 1) {
            // Semifinals share the first assigned semifinal judge
            $sourceRound = $roundNumber;
        } else {
            return null;
        }

        if ($sourceRound < 1) {
            return null;
        }

        $sql = "SELECT tm.judge_id 
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tr.tournament_id = ?
                  AND tr.stage = 2
                  AND tr.round_number = ?
                  AND tm.judge_id IS NOT NULL
                  AND tm.match_number < 90
                ORDER BY tm.match_number ASC
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tournamentId, $sourceRound);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result['judge_id'] ?? null;
    }

    private function getMaxRoundNumber($stage) {
        if (!isset($this->stageMaxRounds[$stage])) {
            $sql = "SELECT MAX(round_number) AS max_round
                    FROM tournament_rounds
                    WHERE tournament_id = ? AND stage = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $this->tournamentId, $stage);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $this->stageMaxRounds[$stage] = (int)($row['max_round'] ?? 0);
        }

        return $this->stageMaxRounds[$stage];
    }

    private function areRoundMatchesComplete($stage, $roundNumber) {
        if ($roundNumber < 1) {
            return true;
        }

        $sql = "SELECT COUNT(*) AS pending
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tr.tournament_id = ?
                  AND tr.stage = ?
                  AND tr.round_number = ?
                  AND tm.match_number < 90
                  AND tm.status <> 'completed'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->tournamentId, $stage, $roundNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return ((int)$row['pending']) === 0;
    }

    private function assignMatch($matchId, $judgeId, $stadiumId) {
        $sql = "INSERT INTO match_assignments (match_id, judge_id, stadium_id) VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isi", $matchId, $judgeId, $stadiumId);
        $stmt->execute();

        $sql = "UPDATE tournament_matches SET status = 'assigned', judge_id = ?, stadium_id = ?, blocked_reason = NULL, started_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sii", $judgeId, $stadiumId, $matchId);
        $stmt->execute();
    }

    private function prioritizeConsolationsFirst(array $matches) {
        if (empty($matches)) {
            return $matches;
        }

        $finalRound = $this->getMaxRoundNumber(2);
        if ($finalRound === 0) {
            return $matches;
        }

        $consolations = [];
        $others = [];

        foreach ($matches as $match) {
            $stage = isset($match['stage']) ? (int)$match['stage'] : null;
            $roundNumber = isset($match['round_number']) ? (int)$match['round_number'] : null;
            $matchNumber = isset($match['match_number']) ? (int)$match['match_number'] : null;

            if ($stage === 2 && $roundNumber === $finalRound && $matchNumber !== null && $matchNumber >= 90) {
                $consolations[] = $match;
            } else if ($stage === 2 && $roundNumber === $finalRound && $matchNumber !== null && $matchNumber < 90) {
                $others[] = $match;
            } else {
                $others[] = $match;
            }
        }

        return array_merge($consolations, $others);
    }

    private function blockMatch($matchId, $reason) {
        $sql = "UPDATE tournament_matches SET status = 'blocked', blocked_reason = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $reason, $matchId);
        $stmt->execute();
    }

    private function getMatchDetails($matchId) {
        $sql = "SELECT * FROM tournament_matches WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $matchId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

// Handler (only run if called directly)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $database = new Database();
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $tournamentId = $_GET['tournament_id'] ?? $_POST['tournament_id'] ?? null;

    if (!$tournamentId) {
        echo json_encode(['success' => false, 'message' => 'Tournament ID required']);
        exit;
    }

    $engine = new MatchEngine($database, $tournamentId);

    switch ($action) {
        case 'run':
            echo json_encode($engine->runAssignment());
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
}

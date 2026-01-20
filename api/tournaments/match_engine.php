<?php
require_once dirname(__DIR__) . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class MatchEngine {
    private $conn;
    private $tournamentId;
    private $stageMaxRounds = [];
    private $tournamentSeed;
    private $judgeOrderCache;
    private $judgeOrderPositions = [];
    private $judgeBindings = [];
    private $judgeBindingsInitialized = false;
    private $floatingJudges = [];
    private $homeStadiumByJudge = [];
    private $roundByeCache = [];

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

            $stadiums = $this->getAllAvailableStadiums();
            $this->initializeJudgeBindings($stadiums);

            if (empty($stadiums)) {
                $this->conn->commit();
                return ['success' => true, 'assignments' => []];
            }

            $matches = $this->prioritizeConsolationsFirst($this->getPlayableMatches());
            if (empty($matches)) {
                $this->conn->commit();
                return ['success' => true, 'assignments' => []];
            }

            $matches = array_values($matches);
            usort($stadiums, function($a, $b) {
                return ((int)$a['id']) <=> ((int)$b['id']);
            });

            $judgesAssigned = [];

            foreach ($stadiums as $stadium) {
                $result = $this->assignNextMatchForStadium($stadium, $matches, $judgesAssigned);
                if (!$result) {
                    continue;
                }

                $matchId = $result['match']['id'];
                $judgeId = $result['judge_id'];
                $stadiumId = (int)$stadium['id'];

                $this->assignMatch($matchId, $judgeId, $stadiumId);

                $assignments[] = [
                    'match_id' => $matchId,
                    'judge_id' => $judgeId,
                    'stadium_id' => $stadiumId
                ];

                $judgesAssigned[$judgeId] = true;

                unset($matches[$result['match_index']]);
                $matches = array_values($matches);

                if (empty($matches)) {
                    break;
                }
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

    private function assignNextMatchForStadium(array $stadium, array $matches, array $judgesAssigned) {
        $stadiumId = (int)$stadium['id'];
        $homeJudgeId = $this->judgeBindings[$stadiumId] ?? null;

        foreach ($matches as $index => $match) {
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

            if ($designatedJudgeId) {
                $designatedHome = $this->homeStadiumByJudge[$designatedJudgeId] ?? null;
                if ($designatedHome !== null && $designatedHome !== $stadiumId) {
                    // This match should be handled on the designated judge's home stadium.
                    continue;
                }
                if (!empty($judgesAssigned[$designatedJudgeId])) {
                    continue;
                }
                if ($this->isJudgeEligible($designatedJudgeId, $matchDetails)) {
                    return [
                        'match' => $match,
                        'match_index' => $index,
                        'judge_id' => $designatedJudgeId
                    ];
                }
                continue;
            }

            if ($homeJudgeId && empty($judgesAssigned[$homeJudgeId]) && $this->isJudgeEligible($homeJudgeId, $matchDetails)) {
                return [
                    'match' => $match,
                    'match_index' => $index,
                    'judge_id' => $homeJudgeId
                ];
            }

            $availableFloats = $this->getAvailableFloatingJudges($judgesAssigned);
            if (!empty($availableFloats)) {
                $floatCandidate = $this->getBestJudgeForMatch($match['id'], [], $stadiumId, $availableFloats);
                if ($floatCandidate && empty($judgesAssigned[$floatCandidate['id']])) {
                    return [
                        'match' => $match,
                        'match_index' => $index,
                        'judge_id' => $floatCandidate['id']
                    ];
                }
            }

            $escapeCandidate = $this->getEscapeJudgeForMatch($match['id'], $stadiumId, $homeJudgeId, $matchDetails, $judgesAssigned);
            if ($escapeCandidate) {
                return [
                    'match' => $match,
                    'match_index' => $index,
                    'judge_id' => $escapeCandidate['id']
                ];
            }
        }

        return null;
    }

    private function getAvailableFloatingJudges(array $judgesAssigned): array {
        if (empty($this->floatingJudges)) {
            return [];
        }

        $available = [];
        foreach ($this->floatingJudges as $judgeId) {
            if (empty($judgesAssigned[$judgeId])) {
                $available[] = $judgeId;
            }
        }

        return $available;
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
    private function getBestJudgeForMatch($matchId, array $preferredJudgeIds = [], $stadiumId = null, ?array $restrictToJudges = null) {
        $match = $this->getMatchDetails($matchId);
        if (!$match) return null;

        $judges = $this->getOrderedJudges();

        if ($restrictToJudges !== null) {
            if (empty($restrictToJudges)) {
                return null;
            }
            $allowList = array_fill_keys($restrictToJudges, true);
            $judges = array_values(array_filter($judges, function ($judge) use ($allowList) {
                return isset($allowList[$judge['id']]);
            }));
        }

        $preferredJudgeIds = array_values(array_unique($preferredJudgeIds));
        $primaryPreferred = $preferredJudgeIds[0] ?? null;
        $candidates = [];
        foreach ($judges as $judge) {
            if ($this->isJudgeEligible($judge['id'], $match)) {
                $score = $this->calculateJudgeScore($judge['id'], $match['round_id'], $match);

                if ($stadiumId !== null) {
                    $home = $this->homeStadiumByJudge[$judge['id']] ?? null;
                    if ($home !== null) {
                        if ($home === $stadiumId) {
                            $score += 40;
                        } else {
                            // Heavy penalty: bound to a different stadium.
                            $score -= 200;
                        }
                    }
                }

                if (!empty($preferredJudgeIds)) {
                    $pos = array_search($judge['id'], $preferredJudgeIds, true);
                    if ($pos !== false) {
                        $score += max(0, 40 - ($pos * 5));
                    }
                }

                $candidates[] = array_merge($judge, [
                    'score' => $score,
                    'order' => $this->judgeOrderPositions[$judge['id']] ?? PHP_INT_MAX
                ]);
            }
        }

        if (empty($candidates)) return null;

        if ($primaryPreferred) {
            foreach ($candidates as $candidate) {
                if ($candidate['id'] === $primaryPreferred) {
                    return $candidate;
                }
            }
        }

        /**
         * Rule 5.3: Choose highest score
         * Tie-breaker: Lowest judge_id
         */
        usort($candidates, function($a, $b) {
            if ($b['score'] != $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            if (($a['order'] ?? 0) !== ($b['order'] ?? 0)) {
                return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
            }
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

        $onBye = $roundId ? $this->judgeHasBye($judgeId, $roundId) : false;
        if ($onBye) {
            $score += 35;
            if ($isPlayer) {
                // Offset player penalty when they're already resting this round
                $score += 20;
            }
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
        $finals = [];

        foreach ($matches as $match) {
            $stage = isset($match['stage']) ? (int)$match['stage'] : null;
            $roundNumber = isset($match['round_number']) ? (int)$match['round_number'] : null;
            $matchNumber = isset($match['match_number']) ? (int)$match['match_number'] : null;

            if ($stage === 2 && $roundNumber === $finalRound) {
                // In final round, prioritize by importance (finals first, then consolation in order)
                if ($matchNumber !== null && $matchNumber < 90) {
                    // Finals (1, 2, etc.) - play first
                    $finals[] = $match;
                } else {
                    // Consolation matches - play after finals, sorted by descending importance
                    $consolations[] = $match;
                }
            } else {
                $others[] = $match;
            }
        }

        // Sort finals by match number (ascending)
        usort($finals, function($a, $b) {
            return ($a['match_number'] ?? 0) <=> ($b['match_number'] ?? 0);
        });

        // Sort consolation by descending importance (lower match number = higher importance)
        usort($consolations, function($a, $b) {
            return ($b['match_number'] ?? 0) <=> ($a['match_number'] ?? 0);
        });

        // Return in correct order: finals first, then consolation, then other matches
        return array_merge($finals, $consolations, $others);
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

    private function getTournamentSeed() {
        if ($this->tournamentSeed !== null) {
            return $this->tournamentSeed;
        }

        $sql = "SELECT created_at FROM tournaments WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $createdAt = $row['created_at'] ?? microtime(true);
        $this->tournamentSeed = hash('sha256', $this->tournamentId . '|' . $createdAt);
        return $this->tournamentSeed;
    }

    private function getOrderedJudges(): array {
        if ($this->judgeOrderCache !== null) {
            return $this->judgeOrderCache;
        }

        $sql = "SELECT tr.user_id as id, u.display_name
                FROM tournament_roles tr
                JOIN users u ON tr.user_id = u.id
                WHERE tr.tournament_id = ? AND (FIND_IN_SET('judge', tr.role) > 0) AND tr.status = 'accepted'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $judges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $seed = $this->getTournamentSeed();
        $orderMap = [];
        foreach ($judges as $judge) {
            $orderKey = hexdec(substr(hash('sha256', $seed . '|' . $judge['id']), 0, 8));
            $orderMap[$judge['id']] = $orderKey;
        }

        usort($judges, function($a, $b) use ($orderMap) {
            if ($orderMap[$a['id']] === $orderMap[$b['id']]) {
                return strcmp($a['id'], $b['id']);
            }
            return $orderMap[$a['id']] <=> $orderMap[$b['id']];
        });

        $this->judgeOrderCache = $judges;
        foreach ($judges as $index => $judge) {
            $this->judgeOrderPositions[$judge['id']] = $index;
        }

        return $this->judgeOrderCache;
    }

    private function initializeJudgeBindings(array $stadiums): void {
        if ($this->judgeBindingsInitialized) {
            return;
        }

        $this->judgeBindingsInitialized = true;
        if (empty($stadiums)) {
            return;
        }

        $judges = $this->getOrderedJudges();
        if (empty($judges) || empty($stadiums)) {
            return;
        }

        $sortedStadiums = $stadiums;
        usort($sortedStadiums, function($a, $b) {
            return $a['id'] <=> $b['id'];
        });

        $stadiumCount = count($sortedStadiums);
        $judgeCount = count($judges);

        $bindingCount = min($judgeCount, $stadiumCount);

        for ($i = 0; $i < $bindingCount; $i++) {
            $stadiumId = (int)$sortedStadiums[$i]['id'];
            $judgeId = $judges[$i]['id'];
            $this->judgeBindings[$stadiumId] = $judgeId;
            $this->homeStadiumByJudge[$judgeId] = $stadiumId;
        }

        if ($judgeCount > $stadiumCount) {
            $extraJudges = array_slice($judges, $stadiumCount);
            $this->floatingJudges = array_map(function ($judge) {
                return $judge['id'];
            }, $extraJudges);

            foreach ($extraJudges as $judge) {
                if (!array_key_exists($judge['id'], $this->homeStadiumByJudge)) {
                    $this->homeStadiumByJudge[$judge['id']] = null;
                }
            }
        } else {
            $this->floatingJudges = [];
        }
    }

    private function judgeHasBye($judgeId, $roundId): bool {
        if (!$roundId) {
            return false;
        }

        if (!isset($this->roundByeCache[$roundId])) {
            $sql = "SELECT bye_players FROM tournament_rounds WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $roundId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            $list = [];
            if ($row && !empty($row['bye_players'])) {
                $entries = array_filter(array_map('trim', explode(',', $row['bye_players'])));
                foreach ($entries as $entry) {
                    if ($entry !== '') {
                        $list[] = $entry;
                    }
                }
            }

            $this->roundByeCache[$roundId] = $list;
        }

        return in_array($judgeId, $this->roundByeCache[$roundId], true);
    }

    private function getEscapeJudgeForMatch($matchId, int $stadiumId, ?string $homeJudgeId, array $matchDetails, array $judgesAssigned) {
        $availableFloats = $this->getAvailableFloatingJudges($judgesAssigned);
        if (!empty($availableFloats)) {
            $candidate = $this->getBestJudgeForMatch($matchId, [], $stadiumId, $availableFloats);
            if ($candidate && empty($judgesAssigned[$candidate['id']])) {
                return $candidate;
            }
        }

        if ($homeJudgeId !== null && empty($judgesAssigned[$homeJudgeId]) && $this->isJudgeEligible($homeJudgeId, $matchDetails)) {
            return ['id' => $homeJudgeId];
        }

        $boundJudges = array_values(array_unique(array_values($this->judgeBindings)));
        $candidates = [];
        foreach ($boundJudges as $judgeId) {
            if ($homeJudgeId !== null && $judgeId === $homeJudgeId) {
                continue;
            }
            if (!empty($judgesAssigned[$judgeId])) {
                continue;
            }
            $candidates[] = $judgeId;
        }

        if (empty($candidates)) {
            return null;
        }

        // Emergency escape: borrow a bound judge from another stadium to keep matches moving.
        return $this->getBestJudgeForMatch($matchId, [], $stadiumId, $candidates);
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

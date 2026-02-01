<?php
require_once dirname(__DIR__) . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class MatchEngine
{
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
    private $judgeIdsCache;
    private $judgeIdLookup = [];
    private $feasibleJudgeIds = [];
    private $feasibleJudgeLookup = [];
    private $judgesLockedForJudging = false;
    private $judgeSurplusCapacity = 0;
    private $judgeSurplusRemaining = 0;
    private $judgeDemandState = [];
    private $matchDetailsCache = [];
    private $judgeAvailabilityCache = [];
    private $judgesReservedForPlaying = [];

    public function __construct($database, $tournamentId)
    {
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
    public function runAssignment()
    {
        if ($this->isSwissRoundInProgress()) {
            return ['success' => true, 'assignments' => [], 'message' => 'Swiss round in progress - judge assignments locked'];
        }

        // Removed first round lock - judges can now be assigned immediately

        $this->resetCycleState();

        $this->conn->begin_transaction();
        try {
            $assignments = [];

            $allStadiums = $this->getAllStadiums();
            $this->initializeJudgeBindings($allStadiums);

            // AUTO-HEAL: Clear assignments for Bye matches (they should not have stadiums)
            $sql = "DELETE ma FROM match_assignments ma 
                    JOIN tournament_matches tm ON ma.match_id = tm.id 
                    WHERE tm.player2_id IS NULL";
            $this->conn->query($sql);

            $availableStadiums = $this->getAllAvailableStadiums();
            if (empty($availableStadiums)) {
                $this->conn->commit();
                return ['success' => true, 'assignments' => []];
            }

            $matches = $this->prioritizeConsolationsFirst($this->getPlayableMatches());
            if (empty($matches)) {
                $this->conn->commit();
                return ['success' => true, 'assignments' => []];
            }

            $matches = array_values($matches);

            $this->judgeDemandState = $this->computeJudgeDemandState($availableStadiums, $matches);
            $cycleMatches = $this->prepareCycleMatches($matches);

            $judgesAssigned = [];
            $playersAssigned = [];
            $assignmentCount = 0;

            foreach ($availableStadiums as $stadium) {
                $stadiumId = (int) $stadium['id'];

                $matchSelection = $this->selectNextMatchForStadium($stadiumId, $cycleMatches, $judgesAssigned, $playersAssigned);
                if (!$matchSelection) {
                    error_log("DEBUG: No match selected for stadium $stadiumId");
                    continue;
                }

                $matchEntry = $matchSelection['entry'];
                $tierKey = $matchSelection['tier'];
                $matchOffset = $matchSelection['offset'];

                $feasibleJudges = $this->getFeasibleJudgesForMatch($stadiumId, $matchEntry, $judgesAssigned, $playersAssigned);
                if (empty($feasibleJudges)) {
                    error_log("DEBUG: No feasible judges for stadium $stadiumId, match {$matchEntry['match']['id']}");
                    error_log("DEBUG: Total feasible judges in system: " . count($this->feasibleJudgeIds ?? []));
                    error_log("DEBUG: Feasible judge IDs: " . json_encode($this->feasibleJudgeIds ?? []));
                    continue;
                }

                $judgeCandidate = $this->scoreJudges($feasibleJudges, $stadiumId, $matchEntry);
                if (!$judgeCandidate) {
                    continue;
                }

                $this->assignMatch($matchEntry['match']['id'], $judgeCandidate['id'], $stadiumId);


                $assignments[] = [
                    'match_id' => $matchEntry['match']['id'],
                    'judge_id' => $judgeCandidate['id'],
                    'stadium_id' => $stadiumId
                ];

                $judgesAssigned[$judgeCandidate['id']] = true;

                $p1Id = $matchEntry['details']['player1_id'] ?? null;
                $p2Id = $matchEntry['details']['player2_id'] ?? null;
                if ($p1Id) {
                    $playersAssigned[$p1Id] = true;
                }
                if ($p2Id) {
                    $playersAssigned[$p2Id] = true;
                }

                $assignmentCount++;

                $this->consumeJudgeSurplusForPlayers($matchEntry);

                $cycleMatches = $this->consumeCycleMatch($cycleMatches, $tierKey, $matchOffset);

                if ($this->isMatchPoolEmpty($cycleMatches)) {
                    break;
                }
            }


            $this->conn->commit();
            return ['success' => true, 'assignments' => $assignments];
        } catch (Exception $e) {
            if ($this->conn) {
                try {
                    $this->conn->rollback();
                } catch (Exception $re) {
                }
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function resetCycleState(): void
    {
        $this->judgeIdsCache = null;
        $this->judgeIdLookup = [];
        $this->feasibleJudgeIds = [];
        $this->feasibleJudgeLookup = [];
        $this->judgesLockedForJudging = false;
        $this->judgeSurplusCapacity = 0;
        $this->judgeSurplusRemaining = 0;
        $this->judgeDemandState = [];
        $this->matchDetailsCache = [];
        $this->judgeAvailabilityCache = [];
        $this->judgesReservedForPlaying = [];
    }

    private function prepareCycleMatches(array $matches): array
    {
        return $this->buildTieredMatchLists($matches);
    }

    private function computeJudgeDemandState(array $availableStadiums, array $matches): array
    {
        $required = count($availableStadiums);

        $orderedJudges = $this->getOrderedJudges();
        $judgeIds = array_map(function ($judge) {
            return $judge['id'];
        }, $orderedJudges);

        $this->judgeIdsCache = $orderedJudges;
        $this->judgeIdLookup = array_fill_keys($judgeIds, true);

        $feasibleJudges = $this->evaluateFeasibleJudgeSet($matches, $judgeIds);
        $feasibleCount = count($feasibleJudges);

        $lock = $feasibleCount < $required;

        $this->judgesLockedForJudging = $lock;
        $this->feasibleJudgeIds = $feasibleJudges;
        $this->feasibleJudgeLookup = array_fill_keys($feasibleJudges, true);
        $this->judgeSurplusCapacity = max(0, $feasibleCount - $required);
        $this->judgeSurplusRemaining = $this->judgeSurplusCapacity;

        $state = [
            'required_judges' => $required,
            'feasible_judges' => $feasibleCount,
            'judge_lock' => $lock,
            'locked_judge_ids' => $lock ? $feasibleJudges : [],
        ];

        $this->judgeDemandState = $state;

        return $state;
    }

    private function evaluateFeasibleJudgeSet(array $matches, array $judgeIds): array
    {
        $feasible = [];


        foreach ($judgeIds as $judgeId) {
            $isFeasible = $this->isJudgeGloballyFeasible($judgeId, $matches);
            if ($isFeasible) {
                $feasible[] = $judgeId;
            }
        }


        return $feasible;
    }

    private function getCachedMatchDetails(int $matchId, ?array $prime = null): ?array
    {
        if (isset($this->matchDetailsCache[$matchId])) {
            return $this->matchDetailsCache[$matchId];
        }

        $details = $this->getMatchDetails($matchId);
        if (!$details) {
            return null;
        }

        if ($prime) {
            foreach ($prime as $key => $value) {
                if (!array_key_exists($key, $details) || $details[$key] === null) {
                    $details[$key] = $value;
                }
            }
        }

        $this->matchDetailsCache[$matchId] = $details;
        return $details;
    }

    private function countJudgePlayers(array $matchDetails): int
    {
        $count = 0;
        $players = [
            $matchDetails['player1_id'] ?? null,
            $matchDetails['player2_id'] ?? null,
        ];

        foreach ($players as $playerId) {
            if ($playerId !== null && isset($this->judgeIdLookup[$playerId])) {
                $count++;
            }
        }

        return $count;
    }

    private function isFinalsMatch(array $match): bool
    {
        if (!isset($match['stage']) || (int) $match['stage'] !== 2) {
            return false;
        }

        if (!isset($match['match_number'])) {
            return false;
        }

        return ((int) $match['match_number']) < 90;
    }

    private function buildTieredMatchLists(array $matches): array
    {
        $tiered = [
            'tier1' => [],
            'tier2' => [],
            'tier3' => [],
        ];

        foreach ($matches as $match) {
            if (!$this->isMatchReadyForAssignment($match)) {
                continue;
            }

            $details = $this->getCachedMatchDetails((int) $match['id'], $match);
            if (!$details) {
                continue;
            }

            $judgeCount = $this->countJudgePlayers($details);

            $entry = [
                'match' => $match,
                'details' => $details,
                'judge_player_count' => $judgeCount,
            ];

            if ($judgeCount === 0) {
                $tiered['tier1'][] = $entry;
            } elseif ($judgeCount === 1) {
                $tiered['tier2'][] = $entry;
            } else {
                $tiered['tier3'][] = $entry;
            }
        }

        $sortFn = function ($a, $b) {
            $matchA = $a['match'];
            $matchB = $b['match'];

            $stageA = $matchA['stage'] ?? null;
            $stageB = $matchB['stage'] ?? null;
            if ($stageA !== $stageB) {
                return ($stageA ?? 0) <=> ($stageB ?? 0);
            }

            $roundA = $matchA['round_number'] ?? 0;
            $roundB = $matchB['round_number'] ?? 0;
            if ($roundA !== $roundB) {
                return $roundA <=> $roundB;
            }

            $finalA = $this->isFinalsMatch($matchA);
            $finalB = $this->isFinalsMatch($matchB);
            if ($finalA !== $finalB) {
                return $finalA ? -1 : 1;
            }

            $matchNumA = $matchA['match_number'] ?? 0;
            $matchNumB = $matchB['match_number'] ?? 0;
            if ($matchNumA !== $matchNumB) {
                return $matchNumA <=> $matchNumB;
            }

            return ($matchA['id'] ?? 0) <=> ($matchB['id'] ?? 0);
        };

        foreach (['tier1', 'tier2', 'tier3'] as $tierKey) {
            usort($tiered[$tierKey], $sortFn);
        }

        return $tiered;
    }

    private function isMatchStartable(array $matchEntry, array $judgesAssigned): bool
    {
        if (!$this->isMatchReadyForAssignment($matchEntry['match'])) {
            return false;
        }

        $p1 = $matchEntry['details']['player1_id'] ?? null;
        $p2 = $matchEntry['details']['player2_id'] ?? null;

        // Check DB occupancy
        if (($p1 && $this->isJudgeCurrentlyPlaying($p1)) || ($p2 && $this->isJudgeCurrentlyPlaying($p2))) {
            return false;
        }

        // Check intra-cycle judge occupancy
        if (($p1 && isset($judgesAssigned[$p1])) || ($p2 && isset($judgesAssigned[$p2]))) {
            return false;
        }

        return true;
    }

    private function canMatchProceedWithJudgePlayers(array $matchEntry, string $tierKey, array $tieredMatches, array $judgesAssigned): bool
    {
        $matchId = $matchEntry['match']['id'];
        $judgePlayers = $matchEntry['judge_player_count'] ?? 0;
        if ($judgePlayers === 0) {
            return true;
        }

        // Check higher priority Phase 1 matches: can any of them start right now?
        if (($tierKey === 'tier2' || $tierKey === 'tier3') && !empty($tieredMatches['tier1'])) {
            foreach ($tieredMatches['tier1'] as $t1Entry) {
                if ($t1Entry !== null && $this->isMatchStartable($t1Entry, $judgesAssigned)) {
                    return false;
                }
            }
        }

        // Check Phase 2 matches: can any of them start right now?
        if ($tierKey === 'tier3' && !empty($tieredMatches['tier2'])) {
            foreach ($tieredMatches['tier2'] as $t2Entry) {
                if ($t2Entry !== null && $this->isMatchStartable($t2Entry, $judgesAssigned)) {
                    return false;
                }
            }
        }


        return true;
    }

    private function selectNextMatchForStadium(int $stadiumId, array $tieredMatches, array $judgesAssigned, array $playersAssigned)
    {
        foreach (['tier1', 'tier2', 'tier3'] as $tierKey) {
            if (empty($tieredMatches[$tierKey])) {
                continue;
            }

            foreach ($tieredMatches[$tierKey] as $offset => $entry) {
                if ($entry === null) {
                    continue;
                }

                $match = $entry['match'];
                if (!$this->isMatchReadyForAssignment($match)) {
                    continue;
                }

                // Intra-cycle occupancy check: Players in this match cannot be already assigned as judges
                $p1 = $entry['details']['player1_id'] ?? null;
                $p2 = $entry['details']['player2_id'] ?? null;
                if (($p1 && isset($judgesAssigned[$p1])) || ($p2 && isset($judgesAssigned[$p2]))) {
                    continue;
                }

                if (!$this->canMatchProceedWithJudgePlayers($entry, $tierKey, $tieredMatches, $judgesAssigned)) {
                    continue;
                }

                $designatedJudgeId = $this->getDesignatedJudgeId($entry['details']);
                if ($designatedJudgeId) {
                    $designatedHome = $this->homeStadiumByJudge[$designatedJudgeId] ?? null;
                    if ($designatedHome !== null && $designatedHome !== $stadiumId) {
                        continue;
                    }
                }

                return [
                    'entry' => $entry,
                    'tier' => $tierKey,
                    'offset' => $offset,
                ];
            }
        }

        return null;
    }

    private function getFeasibleJudgesForMatch(int $stadiumId, array $matchEntry, array $judgesAssigned, array $playersAssigned): array
    {
        $matchDetails = $matchEntry['details'];
        if (!$matchDetails) {
            return [];
        }

        $players = [
            $matchDetails['player1_id'] ?? null,
            $matchDetails['player2_id'] ?? null,
        ];
        $playerLookup = array_fill_keys(array_filter($players), true);

        $designatedJudgeId = $this->getDesignatedJudgeId($matchDetails);
        if ($designatedJudgeId) {
            if (!isset($this->feasibleJudgeLookup[$designatedJudgeId])) {
                return [];
            }
            if (!empty($judgesAssigned[$designatedJudgeId]) || !empty($playersAssigned[$designatedJudgeId])) {
                return [];
            }

            return $this->isJudgeFeasibleForMatch($designatedJudgeId, $matchDetails, $stadiumId)
                ? [$designatedJudgeId]
                : [];
        }

        $stickyJudgeId = $this->getStickyJudgeForStadium($matchDetails['round_id'], $stadiumId);
        if ($stickyJudgeId) {
            if (!isset($this->feasibleJudgeLookup[$stickyJudgeId])) {
                return [];
            }
            if (!empty($judgesAssigned[$stickyJudgeId]) || !empty($playersAssigned[$stickyJudgeId])) {
                return [];
            }

            return $this->isJudgeFeasibleForMatch($stickyJudgeId, $matchDetails, $stadiumId)
                ? [$stickyJudgeId]
                : [];
        }

        $candidateIds = [];

        $homeJudgeId = $this->judgeBindings[$stadiumId] ?? null;
        if ($homeJudgeId) {
            $candidateIds[] = $homeJudgeId;
        }

        foreach ($this->getAvailableFloatingJudges($judgesAssigned) as $floatId) {
            $candidateIds[] = $floatId;
        }

        if (empty($candidateIds)) {
            $candidateIds = $this->feasibleJudgeIds;
        }

        $candidateIds = array_values(array_unique($candidateIds));

        $feasible = [];
        foreach ($candidateIds as $judgeId) {
            if (!isset($this->feasibleJudgeLookup[$judgeId])) {
                continue;
            }

            if (!empty($judgesAssigned[$judgeId]) || !empty($playersAssigned[$judgeId])) {
                continue;
            }

            if (isset($playerLookup[$judgeId])) {
                continue;
            }

            if (!$this->isJudgeFeasibleForMatch($judgeId, $matchDetails, $stadiumId)) {
                continue;
            }

            $feasible[] = $judgeId;
        }

        return $feasible;
    }

    private function scoreJudges(array $judgeIds, int $stadiumId, array $matchEntry)
    {
        if (empty($judgeIds)) {
            return null;
        }

        $matchDetails = $matchEntry['details'];
        if (!$matchDetails) {
            return null;
        }

        $scored = [];
        foreach ($judgeIds as $judgeId) {
            $score = $this->calculateJudgeScore($judgeId, $matchDetails['round_id'], $matchDetails);

            $home = $this->homeStadiumByJudge[$judgeId] ?? null;
            if ($home !== null) {
                if ($home === $stadiumId) {
                    $score += 40;
                } elseif (!in_array($judgeId, $this->floatingJudges, true)) {
                    $score -= 500;
                }
            }

            $scored[] = [
                'id' => $judgeId,
                'score' => $score,
                'order' => $this->judgeOrderPositions[$judgeId] ?? PHP_INT_MAX,
            ];
        }

        usort($scored, function ($a, $b) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['order'] !== $b['order']) {
                return $a['order'] <=> $b['order'];
            }
            return strcmp($a['id'], $b['id']);
        });

        return $scored[0] ?? null;
    }

    private function consumeJudgeSurplusForPlayers(array $matchEntry)
    {
        $details = $matchEntry['details'] ?? [];
        $judgePlayers = 0;
        foreach (['player1_id', 'player2_id'] as $slot) {
            $playerId = $details[$slot] ?? null;
            if ($playerId !== null && isset($this->judgeIdLookup[$playerId])) {
                $judgePlayers++;
            }
        }

        $this->judgeSurplusRemaining = max(0, $this->judgeSurplusRemaining - $judgePlayers);
    }

    private function isJudgeGloballyFeasible(string $judgeId, array $matches): bool
    {
        $available = $this->isJudgeAvailableForAssignment($judgeId);
        if (!$available) {
            return false;
        }

        foreach ($matches as $match) {
            $player1 = $match['player1_id'] ?? null;
            $player2 = $match['player2_id'] ?? null;

            if ($player1 !== $judgeId && $player2 !== $judgeId) {
                return true;
            }
        }

        return false;
    }

    private function isJudgeFeasibleForMatch(string $judgeId, array $matchDetails, int $stadiumId): bool
    {
        if (!$this->isJudgeAvailableForAssignment($judgeId)) {
            return false;
        }

        if (($matchDetails['player1_id'] ?? null) === $judgeId || ($matchDetails['player2_id'] ?? null) === $judgeId) {
            return false;
        }

        $homeStadium = $this->homeStadiumByJudge[$judgeId] ?? null;
        $isFloating = in_array($judgeId, $this->floatingJudges, true);

        if ($homeStadium !== null && $homeStadium !== $stadiumId && !$isFloating) {
            return false;
        }

        return true;
    }

    private function isJudgeAvailableForAssignment(string $judgeId): bool
    {
        if (array_key_exists($judgeId, $this->judgeAvailabilityCache)) {
            return $this->judgeAvailabilityCache[$judgeId];
        }

        if ($this->isJudgeCurrentlyPlaying($judgeId)) {
            $this->judgeAvailabilityCache[$judgeId] = false;
            return false;
        }

        $available = !$this->hasActiveJudgeAssignment($judgeId);
        $this->judgeAvailabilityCache[$judgeId] = $available;

        return $available;
    }

    private function hasActiveJudgeAssignment(string $judgeId): bool
    {
        $sql = "SELECT 1
                FROM match_assignments ma
                JOIN tournament_matches tm ON ma.match_id = tm.id
                WHERE ma.judge_id = ?
                  AND tm.status IN ('assigned', 'in_progress')
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $judgeId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    private function consumeCycleMatch(array $tieredMatches, string $tierKey, int $offset): array
    {
        if (isset($tieredMatches[$tierKey][$offset])) {
            $tieredMatches[$tierKey][$offset] = null;
        }

        return $tieredMatches;
    }

    private function isMatchPoolEmpty(array $tieredMatches): bool
    {
        foreach (['tier1', 'tier2', 'tier3'] as $tierKey) {
            if (empty($tieredMatches[$tierKey])) {
                continue;
            }

            foreach ($tieredMatches[$tierKey] as $entry) {
                if ($entry !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isSwissRoundInProgress(): bool
    {
        $sql = "SELECT 1
                FROM tournament_rounds tr
                JOIN tournaments t ON tr.tournament_id = t.id
                WHERE tr.tournament_id = ?
                  AND t.tournament_type = 'swiss'
                  AND tr.stage = 1
                  AND tr.status = 'in_progress'
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    private function isRoundInProgress(): bool
    {
        $sql = "SELECT 1
                FROM tournament_rounds
                WHERE tournament_id = ?
                  AND status = 'in_progress'
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    private function isFirstRound(): bool
    {
        $sql = "SELECT 1
                FROM tournament_rounds tr
                WHERE tr.tournament_id = ?
                  AND tr.stage = 1
                  AND tr.round_number = 1
                  AND tr.status IN ('active', 'in_progress')
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    private function getStickyJudgeForStadium($roundId, $stadiumId)
    {
        $sql = "SELECT tm.judge_id 
                FROM tournament_matches tm
                JOIN match_assignments ma ON tm.id = ma.match_id
                WHERE tm.round_id = ? AND ma.stadium_id = ?
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $roundId, $stadiumId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        return $res['judge_id'] ?? null;
    }

    private function getAvailableFloatingJudges(array $judgesAssigned): array
    {
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

    private function getAllStadiums()
    {
        $sql = "SELECT id, name FROM tournament_stadiums WHERE tournament_id = ? ORDER BY id ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Rule 2.1 & 2.3: Get ALL available stadiums ordered by ID
     */
    private function getAllAvailableStadiums()
    {
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
    private function getPlayableMatches($excludeIds = [])
    {
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
                AND tm.player2_id IS NOT NULL -- Exclude Byes
                AND tr.status = 'active'
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
    private function getBestJudgeForMatch($matchId, array $preferredJudgeIds = [], $stadiumId = null, ?array $restrictToJudges = null)
    {
        $match = $this->getMatchDetails($matchId);
        if (!$match)
            return null;

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
                            // Prohibitive penalty: bound to a different stadium.
                            // This ensures judges don't "hop" stadiums if there's a match elsewhere.
                            $score -= 500;
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

        if (empty($candidates))
            return null;

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
        usort($candidates, function ($a, $b) {
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
    private function isJudgeEligible($judgeId, $match)
    {
        // 1.0 Cannot judge while playing in another active match
        if ($this->isJudgeCurrentlyPlaying($judgeId)) {
            return false;
        }

        // 1.1 Match-active assignment: One active assignment per judge
        $sql = "SELECT status FROM tournament_matches tm
                JOIN match_assignments ma ON tm.id = ma.match_id
                WHERE ma.judge_id = ? AND tm.status IN ('assigned', 'in_progress')";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $judgeId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0)
            return false;

        // 1.3 No self-judging
        if ($match['player1_id'] === $judgeId || $match['player2_id'] === $judgeId)
            return false;

        // Rule 4: Has not already judged this exact match
        $sql = "SELECT 1 FROM tournament_matches 
                WHERE judge_id = ? AND player1_id = ? AND player2_id = ? AND status = 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $judgeId, $match['player1_id'], $match['player2_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0)
            return false;

        return true;
    }

    /**
     * Optimized judge scoring that prioritizes non-playing judges
     */
    private function calculateJudgeScore($judgeId, $roundId, $match = null)
    {
        // Base score starts at 100
        $score = 100;

        // Check if judge is also a player in this tournament
        $isPlayer = $this->isJudgeAlsoPlayer($judgeId);
        if ($isPlayer) {
            // Penalty for being a player (prefer non-playing judges)
            $score -= 30;
        }



        $onBye = $roundId ? $this->judgeHasBye($judgeId, $roundId) : false;
        if ($onBye) {
            $score += 35;
            if ($isPlayer) {
                // Offset player penalty when they're already resting this round
                $score += 20;
            }
        }

        // Fatigue calculation (only apply at round boundaries)
        if (!$this->isRoundInProgress()) {
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

            $fatigueWeight = 50;
            $fatiguePenalty = ($Mj / $Mr) * $fatigueWeight;
            $score -= $fatiguePenalty;
        }

        return max(0, $score);
    }

    /**
     * Check if judge is also a player in this tournament
     */
    private function isJudgeAlsoPlayer($judgeId)
    {
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
    private function isJudgeCurrentlyPlaying($judgeId)
    {
        $sql = "SELECT 1 FROM tournament_matches
                WHERE (player1_id = ? OR player2_id = ?) AND status IN ('assigned', 'in_progress')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $judgeId, $judgeId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    private function isMatchReadyForAssignment($match)
    {
        if (!isset($match['stage']) || !isset($match['round_number'])) {
            return true;
        }

        if ((int) $match['stage'] !== 2) {
            return true;
        }

        $roundNumber = (int) $match['round_number'];
        $maxRound = $this->getMaxRoundNumber(2);

        if ($maxRound < 3 || $roundNumber !== ($maxRound - 1)) {
            return true;
        }

        // Delay semifinals until all quarterfinal matches are completed
        return $this->areRoundMatchesComplete(2, $roundNumber - 1);
    }

    private function getDesignatedJudgeId($match)
    {
        if (!isset($match['stage'], $match['round_number'], $match['match_number'])) {
            return null;
        }

        if ((int) $match['stage'] !== 2) {
            return null;
        }

        if ((int) $match['match_number'] >= 90) {
            // Consolation / placement matches can use normal assignment
            return null;
        }

        $maxRound = $this->getMaxRoundNumber(2);
        if ($maxRound < 2) {
            return null;
        }

        $roundNumber = (int) $match['round_number'];
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

    private function getMaxRoundNumber($stage)
    {
        if (!isset($this->stageMaxRounds[$stage])) {
            $sql = "SELECT MAX(round_number) AS max_round
                    FROM tournament_rounds
                    WHERE tournament_id = ? AND stage = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $this->tournamentId, $stage);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $this->stageMaxRounds[$stage] = (int) ($row['max_round'] ?? 0);
        }

        return $this->stageMaxRounds[$stage];
    }

    private function areRoundMatchesComplete($stage, $roundNumber)
    {
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

        return ((int) $row['pending']) === 0;
    }

    private function assignMatch($matchId, $judgeId, $stadiumId)
    {
        $sql = "INSERT INTO match_assignments (match_id, judge_id, stadium_id) VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isi", $matchId, $judgeId, $stadiumId);
        $stmt->execute();

        $sql = "UPDATE tournament_matches SET status = 'assigned', judge_id = ?, stadium_id = ?, blocked_reason = NULL, started_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sii", $judgeId, $stadiumId, $matchId);
        $stmt->execute();
    }

    private function prioritizeConsolationsFirst(array $matches)
    {
        if (empty($matches)) {
            return $matches;
        }

        $finalRoundByStage = [];
        $consolations = [];
        $finals = [];
        $others = [];

        foreach ($matches as $match) {
            $stage = isset($match['stage']) ? (int) $match['stage'] : null;
            $roundNumber = isset($match['round_number']) ? (int) $match['round_number'] : null;
            $matchNumber = isset($match['match_number']) ? (int) $match['match_number'] : null;

            if ($stage === null || $roundNumber === null || $roundNumber <= 0) {
                $others[] = $match;
                continue;
            }

            if (!array_key_exists($stage, $finalRoundByStage)) {
                $finalRoundByStage[$stage] = $this->getMaxRoundNumber($stage);
            }

            $finalRound = $finalRoundByStage[$stage] ?? 0;
            if ($finalRound > 0 && $roundNumber === $finalRound) {
                if ($matchNumber !== null && $matchNumber >= 90) {
                    $consolations[] = $match;
                } else {
                    $finals[] = $match;
                }
            } else {
                $others[] = $match;
            }
        }

        // Consolation matches (>= 90) should be played before everything else in the final round.
        usort($consolations, function ($a, $b) {
            return ($b['match_number'] ?? 0) <=> ($a['match_number'] ?? 0);
        });

        // Finals (< 90) only become eligible once every consolation match is finished.
        usort($finals, function ($a, $b) {
            return ($a['match_number'] ?? 0) <=> ($b['match_number'] ?? 0);
        });

        if (!empty($consolations)) {
            // As long as a consolation is pending, hide finals from the playable pool entirely.
            return array_merge($consolations, $others);
        }

        return array_merge($finals, $others);
    }

    private function blockMatch($matchId, $reason)
    {
        $sql = "UPDATE tournament_matches SET status = 'blocked', blocked_reason = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $reason, $matchId);
        $stmt->execute();
    }

    private function getMatchDetails($matchId)
    {
        $sql = "SELECT * FROM tournament_matches WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $matchId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    private function getTournamentSeed()
    {
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

    private function getOrderedJudges(): array
    {
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

        usort($judges, function ($a, $b) use ($orderMap) {
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

    private function initializeJudgeBindings(array $stadiums): void
    {
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
        usort($sortedStadiums, function ($a, $b) {
            return $a['id'] <=> $b['id'];
        });

        $stadiumCount = count($sortedStadiums);
        $judgeCount = count($judges);

        $bindingCount = min($judgeCount, $stadiumCount);

        for ($i = 0; $i < $bindingCount; $i++) {
            $stadiumId = (int) $sortedStadiums[$i]['id'];
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

    private function judgeHasBye($judgeId, $roundId): bool
    {
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

    private function getEscapeJudgeForMatch($matchId, int $stadiumId, ?string $homeJudgeId, array $matchDetails, array $judgesAssigned)
    {
        // Only ever allow the home judge or a floating judge for this stadium.
        // We no longer "borrow" judges from other stadiums (Escape) to prevent stadium hopping.

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

        return null;
    }

    public function getPublicBindings(): array
    {
        $allStadiums = $this->getAllStadiums();
        $this->initializeJudgeBindings($allStadiums);

        $out = [];
        foreach ($this->judgeBindings as $sid => $jid) {
            $out[] = ['stadium_id' => $sid, 'judge_id' => $jid];
        }
        return $out;
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

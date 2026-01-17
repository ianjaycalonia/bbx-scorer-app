<?php
ob_start();
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/match_engine.php';
require_once __DIR__ . '/scoring.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global Error Handler to ensure JSON response
set_exception_handler(function($e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    exit;
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

class TournamentManager {
    private $conn;
    private $tournamentId;
    private $rankToCache = null;
    private $placementMatchCache = [];

    public function __construct($database, $tournamentId) {
        $this->conn = $database->getConnection();
        $this->tournamentId = $tournamentId;
    }

    /**
     * Start the tournament: Generate all rounds/pairings based on type
     */
    public function startTournament() {
        $this->conn->begin_transaction();
        try {
            $tournament = $this->getTournamentDetails();
            if (!$tournament) {
                throw new Exception("Tournament with ID {$this->tournamentId} not found.");
            }
            $type = $tournament['tournament_type'];
            $players = $this->getPlayers();

            if (count($players) < 2) {
                throw new Exception("Need at least 2 players to start. Current count: " . count($players));
            }

            // Cleanup any existing rounds/matches
            $this->cleanupTournament();

            switch ($type) {
                case 'single_elimination':
                    // Single Elimination starts directly in Stage 1
                    $this->generateSingleElimination($players, true, 1, $rankTo ?? 5);
                    // Set current_stage to 1 for single elimination
                    $sql = "UPDATE tournaments SET current_stage = 1 WHERE id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("i", $this->tournamentId);
                    $stmt->execute();
                    break;
                case 'swiss':
                    $this->startSwissStage($players, $tournament);
                    break;
                default:
                    throw new Exception("Format $type not yet supported for auto-generation.");
            }

            // Update tournament status, top_cut, and rank_to
            $topCut = isset($_POST['top_cut']) ? (int)$_POST['top_cut'] : 0;
            $rankTo = isset($_POST['rank_to']) ? (int)$_POST['rank_to'] : 5; // Default to 5th place to include 5th/6th matches
            $sql = "UPDATE tournaments SET status = 'ongoing', top_cut = ?, rank_to = ? WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iii", $topCut, $rankTo, $this->tournamentId);
            $stmt->execute();

            $this->conn->commit();

            // Initialize stadiums if first round
            $this->initializeStadiums($tournament['number_of_stadiums']);

            // Run engine
            $engine = new MatchEngine(new Database(), $this->tournamentId);
            $result = $engine->runAssignment();
            
            if (!$result['success']) {
                // Not a fatal error, but good to know
                error_log("Initial assignment warning: " . ($result['message'] ?? 'Unknown error'));
            }

            return ['success' => true, 'message' => "Tournament started. All rounds populated."];
        } catch (Exception $e) {
            if ($this->conn) {
                try { $this->conn->rollback(); } catch (Exception $re) {}
            }
            return [
                'success' => false, 
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        }
    }

    /**
     * Single Elimination Bracket Generation
     * @param array $players Array of players
     * @param bool $shuffle Whether to shuffle players (true for random seeding, false for preserving order)
     * @param int $stage Tournament stage (1 = initial, 2 = topcut)
     * @param int $rankTo Up to what place to rank (1, 3, 4, 6, 8)
     */
    private function generateSingleElimination($players, $shuffle = true, $stage = 1, $rankTo = 3) {
        if ($shuffle) {
            shuffle($players);
        }
        $numPlayers = count($players);
        $numRounds = ceil(log($numPlayers, 2));
        $bracketSize = pow(2, $numRounds); // Next power of 2

        $rounds = [];
        for ($r = 1; $r <= $numRounds; $r++) {
            $sql = "INSERT INTO tournament_rounds (tournament_id, round_number, stage, status) VALUES (?, ?, ?, 'scheduled')";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iii", $this->tournamentId, $r, $stage);
            $stmt->execute();
            $rounds[$r] = $stmt->insert_id;
        }

        // Set Round 1 as active
        $sql = "UPDATE tournament_rounds SET status = 'active' WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $rounds[1]);
        $stmt->execute();

        // 2. Build the tree from Final back to Round 1
        $matchesByRound = [];
        
        // Create placeholders for future rounds
        for ($r = $numRounds; $r >= 1; $r--) {
            $matchesInRound = pow(2, $numRounds - $r);
            for ($m = 1; $m <= $matchesInRound; $m++) {
                $nextMatchId = null;
                $nextSlot = '1';

                if ($r < $numRounds) {
                    $parentMatchIndex = ceil($m / 2);
                    $nextMatchId = $matchesByRound[$r + 1][$parentMatchIndex - 1];
                    $nextSlot = ($m % 2 != 0) ? '1' : '2';
                }

                $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status, next_match_id, next_match_slot) 
                        VALUES (?, ?, ?, 'scheduled', ?, ?)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("iiiii", $this->tournamentId, $rounds[$r], $m, $nextMatchId, $nextSlot);
                $stmt->execute();
                $matchesByRound[$r][] = $stmt->insert_id;
            }
        }

        // --- Consolation / Ranking Match Generation ---
        // Generate ranking matches for both Stage 1 (standalone) and Stage 2 (top cut)
        if ($rankTo >= 3 && $numRounds >= 2) {
            $finalsRoundId = $rounds[$numRounds];

            // 1. 3rd Place Match (Match 99)
            $semiRound = $numRounds - 1;
            $semiMatches = $matchesByRound[$semiRound];
            
            $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, 99, 'scheduled')";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $this->tournamentId, $finalsRoundId);
            $stmt->execute();
            $thirdMatchId = $stmt->insert_id;

            for ($i = 0; $i < 2; $i++) {
                $slot = (string)($i + 1);
                $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("isi", $thirdMatchId, $slot, $semiMatches[$i]);
                $stmt->execute();
            }

            // 2. 5th-6th Ranking (Match 98) - Direct quarterfinal losers to 5th/6th match
            if ($rankTo >= 5 && $numRounds >= 3) {
                $quarterRound = $numRounds - 2;
                $qfMatches = $matchesByRound[$quarterRound];

                // 5th/6th Playoff (Match 98)
                $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, 98, 'scheduled')";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ii", $this->tournamentId, $finalsRoundId);
                $stmt->execute();
                $fifthMatchId = $stmt->insert_id;

                // Direct route first two QF losers to 5th/6th match
                for ($i = 0; $i < 2; $i++) {
                    $slot = (string)($i + 1);
                    $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("isi", $fifthMatchId, $slot, $qfMatches[$i]);
                    $stmt->execute();
                }

                // Consolation Semis and 7th/8th Playoff - Only if RankTo >= 7
                if ($rankTo >= 7) {
                    // Consolation Semis for remaining QF losers
                    $consSemiIds = [];
                    for ($i = 0; $i < 2; $i++) {
                        $mNum = 95 - $i; // 95, 94
                        $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, ?, 'scheduled')";
                        $stmt = $this->conn->prepare($sql);
                        $stmt->bind_param("iii", $this->tournamentId, $finalsRoundId, $mNum);
                        $stmt->execute();
                        $consSemiIds[] = $stmt->insert_id;

                        // Link remaining QF losers (2 per consolation semi)
                        for ($j = 0; $j < 2; $j++) {
                            $qfIdx = ($i * 2) + $j + 2; // +2 to skip first two already routed
                            $slot = (string)($j + 1);
                            $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
                            $stmt = $this->conn->prepare($sql);
                            $stmt->bind_param("isi", $consSemiIds[$i], $slot, $qfMatches[$qfIdx]);
                            $stmt->execute();
                        }
                    }

                    // 7th/8th Playoff (Match 97)
                    $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, 97, 'scheduled')";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("ii", $this->tournamentId, $finalsRoundId);
                    $stmt->execute();
                    $seventhMatchId = $stmt->insert_id;

                    for ($i = 0; $i < 2; $i++) {
                        $slot = (string)($i + 1);
                        $sql = "UPDATE tournament_matches SET next_match_id = ?, next_match_slot = ? WHERE id = ?";
                        $stmt = $this->conn->prepare($sql);
                        $stmt->bind_param("isi", $seventhMatchId, $slot, $consSemiIds[$i]);
                        $stmt->execute();
                    }
                }
            }

            // 3. 9th/10th Ranking (Match 96)
            if ($rankTo >= 9 && $numRounds >= 4) {
                $r1Round = 1;
                $r1Matches = $matchesByRound[$r1Round];

                $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, 96, 'scheduled')";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ii", $this->tournamentId, $finalsRoundId);
                $stmt->execute();
                $ninthMatchId = $stmt->insert_id;

                // For simplicity, we take losers of the first two R1 matches
                // In a perfect system, we might take the ones with best Swiss ranking,
                // but this follows the "next match" logic pattern.
                for ($i = 0; $i < 2; $i++) {
                    $slot = (string)($i + 1);
                    $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("isi", $ninthMatchId, $slot, $r1Matches[$i]);
                    $stmt->execute();
                }
            }
        }

        // 3. Fill Round 1 with players
        $round1Matches = $matchesByRound[1];
        for ($i = 0; $i < $bracketSize; $i += 2) {
            $matchId = $round1Matches[$i / 2];
            $p1 = $players[$i]['id'] ?? null;
            $p2 = $players[$i + 1]['id'] ?? null;

            if ($p1 && $p2) {
                $sql = "UPDATE tournament_matches SET player1_id = ?, player2_id = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ssi", $p1, $p2, $matchId);
                $stmt->execute();
            } else if ($p1) {
                // P1 Bye
                $sql = "UPDATE tournament_matches SET player1_id = ?, status = 'completed', winner_id = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ssi", $p1, $p1, $matchId);
                $stmt->execute();
                $this->propagateWinner($matchId, $p1);
            } else if ($p2) {
                // P2 Bye
                $sql = "UPDATE tournament_matches SET player2_id = ?, status = 'completed', winner_id = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ssi", $p2, $p2, $matchId);
                $stmt->execute();
                $this->propagateWinner($matchId, $p2);
            } else {
                // Double NULL - empty bracket branch
                $sql = "UPDATE tournament_matches SET status = 'completed', winner_id = NULL WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $matchId);
                $stmt->execute();
                $this->propagateWinner($matchId, null);
            }
        }
    }

    /**
     * Swiss Initial Generation (Populate placeholders for 3 rounds by default)
     */
    private function startSwissStage(array $players, array $tournament) {
        // Randomize seeds for players lacking a seed and ensure consistent ordering.
        $this->initializeSwissSeeds($players);

        // Create the initial Swiss round as active and populate only that round.
        $firstRoundId = $this->createSwissRound(1, 'active');
        $pairings = $this->buildSwissPairings($players, 1, false);
        $this->persistSwissPairings($firstRoundId, $pairings);

        // Ensure tournament reflects Swiss stage.
        $sql = "UPDATE tournaments SET current_stage = 1 WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
    }

    private function initializeSwissSeeds(array $players): void {
        if (empty($players)) {
            return;
        }

        $seedMap = [];
        $sql = "SELECT user_id, seed FROM tournament_roles WHERE tournament_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['seed'])) {
                $seedMap[$row['user_id']] = (int)$row['seed'];
            }
        }

        if (count($seedMap) === count($players)) {
            return; // All players already seeded.
        }

        $unseededPlayers = array_filter($players, function ($player) use ($seedMap) {
            return empty($seedMap[$player['id']] ?? null);
        });

        if (empty($seedMap)) {
            // No existing seeds, randomize everyone and assign sequentially.
            $pool = $players;
            shuffle($pool);
            foreach ($pool as $index => $player) {
                $this->assignSeed($player['id'], $index + 1);
            }
            return;
        }

        if (!empty($unseededPlayers)) {
            $usedSeeds = array_values($seedMap);
            sort($usedSeeds);
            $nextSeed = 1;

            foreach ($unseededPlayers as $player) {
                while (in_array($nextSeed, $usedSeeds, true)) {
                    $nextSeed++;
                }
                $this->assignSeed($player['id'], $nextSeed);
                $usedSeeds[] = $nextSeed;
                $nextSeed++;
            }
        }
    }

    private function assignSeed($userId, int $seed): void {
        $sql = "UPDATE tournament_roles SET seed = ? WHERE tournament_id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iis", $seed, $this->tournamentId, $userId);
        $stmt->execute();
    }

    private function createSwissRound(int $roundNumber, string $status = 'scheduled'): int {
        // Ensure we do not create duplicate round records.
        $sql = "SELECT id FROM tournament_rounds WHERE tournament_id = ? AND round_number = ? AND stage = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tournamentId, $roundNumber);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if ($existing) {
            $roundId = (int)$existing['id'];
            $sql = "UPDATE tournament_rounds SET status = ? WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("si", $status, $roundId);
            $stmt->execute();
            return $roundId;
        }

        $sql = "INSERT INTO tournament_rounds (tournament_id, round_number, stage, status) VALUES (?, ?, 1, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iis", $this->tournamentId, $roundNumber, $status);
        $stmt->execute();
        return (int)$stmt->insert_id;
    }

    private function buildSwissPairings(array $players, int $roundNumber, bool $includeScheduled = false): array {
        $swiss = new SwissPairing($this->conn, $this->tournamentId);
        return $swiss->calculatePairings($players, $includeScheduled, $roundNumber);
    }

    private function persistSwissPairings(int $roundId, array $pairings): void {
        // Clean up any existing scheduled matches/byes for this round to avoid duplicates.
        $sql = "DELETE FROM tournament_matches WHERE round_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $roundId);
        $stmt->execute();

        $sql = "DELETE FROM tournament_byes WHERE round_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $roundId);
        $stmt->execute();

        $matchNumber = 1;
        foreach ($pairings as $pair) {
            if (!is_array($pair) || !array_key_exists('p1', $pair) || !array_key_exists('p2', $pair)) {
                continue;
            }

            $playerOne = $pair['p1'];
            $playerTwo = $pair['p2'];

            if (!empty($pair['is_bye'])) {
                if (!$playerOne) {
                    $matchNumber++;
                    continue;
                }

                $sql = "INSERT INTO tournament_byes (tournament_id, round_id, user_id) VALUES (?, ?, ?)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("iis", $this->tournamentId, $roundId, $playerOne);
                $stmt->execute();

                $nullPlayer = null;
                $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, player1_id, player2_id, status, winner_id) 
                        VALUES (?, ?, ?, ?, ?, 'completed', ?)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("iiisss", $this->tournamentId, $roundId, $matchNumber, $playerOne, $nullPlayer, $playerOne);
                $stmt->execute();
                $matchNumber++;
                continue;
            }

            if (!$playerOne || !$playerTwo) {
                $matchNumber++;
                continue;
            }

            $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, player1_id, player2_id, status) 
                    VALUES (?, ?, ?, ?, ?, 'scheduled')";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iiiss", $this->tournamentId, $roundId, $matchNumber, $playerOne, $playerTwo);
            $stmt->execute();
            $matchNumber++;
        }
    }

    private function calculateTotalSwissRounds(array $players, array $tournament): int {
        if (!empty($tournament['swiss_rounds']) && (int)$tournament['swiss_rounds'] > 0) {
            return (int)$tournament['swiss_rounds'];
        }

        $playerCount = max(1, count($players));
        return (int)ceil(log($playerCount, 2));
    }

    private function getRoundIdByNumber(int $roundNumber): ?int {
        $sql = "SELECT id FROM tournament_rounds WHERE tournament_id = ? AND round_number = ? AND stage = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tournamentId, $roundNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }

    private function getActiveSwissRound(): ?array {
        $sql = "SELECT id, round_number FROM tournament_rounds WHERE tournament_id = ? AND stage = 1 AND status = 'active' ORDER BY round_number DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    private function getLatestCompletedSwissRoundNumber(): int {
        $sql = "SELECT round_number FROM tournament_rounds WHERE tournament_id = ? AND stage = 1 AND status = 'completed' ORDER BY round_number DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['round_number'] : 0;
    }

    private function markRoundStatus(int $roundId, string $status): void {
        $allowed = ['scheduled', 'active', 'completed'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException("Invalid round status: {$status}");
        }

        $sql = "UPDATE tournament_rounds SET status = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $status, $roundId);
        $stmt->execute();
    }

    private function snapshotSwissStandings(int $roundNumber): void {
        if ($roundNumber <= 0) {
            return;
        }

        $this->conn->query(
            "CREATE TABLE IF NOT EXISTS tournament_swiss_history (
                tournament_id INT NOT NULL,
                round_number INT NOT NULL,
                player_id VARCHAR(64) NOT NULL,
                wins INT NOT NULL,
                losses INT NOT NULL,
                points FLOAT NOT NULL,
                bey_points INT DEFAULT 0,
                strength_metric DECIMAL(8,2) DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (tournament_id, round_number, player_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $calculator = new StandingsCalculator($this->conn, $this->tournamentId);
        $standings = $calculator->calculate();
        if (empty($standings)) {
            return;
        }

        $sql = "REPLACE INTO tournament_swiss_history (tournament_id, round_number, player_id, wins, losses, points, bey_points, strength_metric)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        foreach ($standings as $row) {
            $stmt->bind_param(
                "iisiiidd",
                $this->tournamentId,
                $roundNumber,
                $row['id'],
                $row['wins'],
                $row['losses'],
                $row['points'],
                $row['bey_points'],
                $row['strength_metric'] ?? 0
            );
            $stmt->execute();
        }
    }

    /**
     * Generate Top Cut Bracket using fold seeding (1v8, 2v7, 3v6, 4v5, etc.)
     * This is called after Swiss rounds complete
     */
    public function generateTopCutBracket() {
        $this->conn->begin_transaction();
        try {
            $tournament = $this->getTournamentDetails();
            
            // Validate tournament is Swiss and has top_cut set
            if ($tournament['tournament_type'] !== 'swiss') {
                throw new Exception("Top cut is only for Swiss tournaments.");
            }
            
            $topCut = (int)$tournament['top_cut'];
            if ($topCut <= 0) {
                throw new Exception("Top cut not configured for this tournament.");
            }
            
            // Get standings
            $calc = new StandingsCalculator($this->conn, $this->tournamentId);
            $standings = $calc->calculate();
            
            // Validate we have enough players
            if (count($standings) < $topCut) {
                throw new Exception("Not enough players for top {$topCut}. Only " . count($standings) . " players available.");
            }
            
            // Get top N players
            $topPlayers = array_slice($standings, 0, $topCut);
            
            // Apply fold seeding (Challonge style)
            // For 8 players: 1v8, 2v7, 3v6, 4v5
            // For 16 players: 1v16, 2v15, 3v14, 4v13, 5v12, 6v11, 7v10, 8v9
            $seededPlayers = [];
            $half = $topCut / 2;
            for ($i = 0; $i < $half; $i++) {
                $seededPlayers[] = ['id' => $topPlayers[$i]['id']];
                $seededPlayers[] = ['id' => $topPlayers[$topCut - 1 - $i]['id']];
            }
            
            // Generate single elimination bracket with seeded players (no shuffle, stage 2)
            $this->generateSingleElimination($seededPlayers, false, 2, (int)$tournament['rank_to']);
            
            // Update tournament to stage 2
            $sql = "UPDATE tournaments SET current_stage = 2 WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $this->tournamentId);
            $stmt->execute();
            
            $this->conn->commit();
            
            // Run match engine to assign judges/stadiums
            $engine = new MatchEngine(new Database(), $this->tournamentId);
            $engine->runAssignment();
            
            return ['success' => true, 'message' => "Top $topCut bracket generated with fold seeding."];
        } catch (Exception $e) {
            if ($this->conn) {
                try { $this->conn->rollback(); } catch (Exception $re) {}
            }
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        }
    }

    private function propagateWinner($matchId, $winnerId, $loserId = null) {
        // Get next match info (Winner paths AND Loser paths)
        $sql = "SELECT next_match_id, next_match_slot, loser_next_match_id, loser_next_match_slot 
                FROM tournament_matches WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $matchId);
        $stmt->execute();
        $match = $stmt->get_result()->fetch_assoc();
        
        if (!$match) return;

        // 1. Propagate Winner
        if ($match['next_match_id']) {
            $nextMatchId = $match['next_match_id'];
            $slot = $match['next_match_slot'];
            
            if ($slot == '1') {
                $sql = "UPDATE tournament_matches SET player1_id = ? WHERE id = ?";
            } else {
                $sql = "UPDATE tournament_matches SET player2_id = ? WHERE id = ?";
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("si", $winnerId, $nextMatchId);
            $stmt->execute();
            
            // Re-check for Bye in next match
            $this->checkAndHandleBye($nextMatchId, $slot, $matchId);
        }

        // 2. Existing loser wiring (loser_next_* columns) takes priority
        $loserHandled = false;
        if ($loserId && $match['loser_next_match_id']) {
            $loserHandled = $this->routeLoserDirectly($match, $loserId, $matchId);
        }

        // 3. Placement routing controlled by TO (rank_to) fills any remaining tiers
        if ($loserId && !$loserHandled) {
            $this->routeLoserToPlacement($matchId, $loserId);
        }
    }

    private function routeLoserDirectly(array $match, $loserId, $sourceMatchId) {
        $lnmId = $match['loser_next_match_id'];
        $slot = $match['loser_next_match_slot'];
        if (!$lnmId || !$slot) return false;

        $column = ($slot == '1') ? 'player1_id' : 'player2_id';
        $sql = "UPDATE tournament_matches SET $column = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $loserId, $lnmId);
        $stmt->execute();

        $this->checkAndHandleBye($lnmId, $slot, $sourceMatchId);
        return true;
    }

    private function routeLoserToPlacement($sourceMatchId, $loserId) {
        $rankTo = $this->getTournamentRankTo();
        if ($rankTo < 3) {
            return; // TO did not request placement matches
        }

        $meta = $this->getMatchMeta($sourceMatchId);
        if (!$meta || (int)$meta['stage'] !== 2) {
            return; // Only Stage 2 (Top Cut) uses placement routing
        }

        $maxRound = $this->getMaxRoundNumber(2);
        $roundNumber = (int)$meta['round_number'];
        $matchNumber = (int)$meta['match_number'];

        // Semifinal losers -> 3rd place match (Match 99)
        if ($rankTo >= 3 && $roundNumber === $maxRound - 1) {
            $thirdMatchId = $this->getPlacementMatchId(99);
            if ($thirdMatchId) {
                $slot = ($matchNumber % 2 === 1) ? '1' : '2';
                $this->assignPlacementSlot($thirdMatchId, $slot, $loserId, $sourceMatchId);
            }
            return;
        }

        // Quarterfinal losers -> 5th/6th match (98) or Consolation Semis (95 & 94) when enabled
        if ($rankTo >= 5 && $roundNumber === $maxRound - 2) {
            // If rankTo is 5-6, route directly to 5th/6th match
            if ($rankTo < 7) {
                $fifthMatch = $this->getPlacementMatchId(98);
                if ($fifthMatch) {
                    if ($matchNumber === 1 || $matchNumber === 2) {
                        $slot = ($matchNumber === 1) ? '1' : '2';
                        $this->assignPlacementSlot($fifthMatch, $slot, $loserId, $sourceMatchId);
                        return;
                    }
                }
            } else {
                // If rankTo >= 7, use consolation semis for remaining QF losers
                $consSemiA = $this->getPlacementMatchId(95);
                $consSemiB = $this->getPlacementMatchId(94);
                if ($consSemiA && $consSemiB) {
                    if ($matchNumber === 3 || $matchNumber === 4) {
                        $slot = ($matchNumber === 3) ? '1' : '2';
                        $this->assignPlacementSlot($consSemiA, $slot, $loserId, $sourceMatchId);
                        return;
                    }
                    if ($matchNumber === 5 || $matchNumber === 6) {
                        $slot = ($matchNumber === 5) ? '1' : '2';
                        $this->assignPlacementSlot($consSemiB, $slot, $loserId, $sourceMatchId);
                        return;
                    }
                }
            }
        }

        // Losers from consolation finals (95/94) -> 7th place match (97) if enabled
        if ($rankTo >= 7 && $roundNumber === $maxRound && ($matchNumber === 95 || $matchNumber === 94)) {
            $seventhMatch = $this->getPlacementMatchId(97);
            if ($seventhMatch) {
                $slot = ($matchNumber === 95) ? '1' : '2';
                $this->assignPlacementSlot($seventhMatch, $slot, $loserId, $sourceMatchId);
            }
        }
    }

    private function assignPlacementSlot($matchId, $slot, $playerId, $sourceMatchId) {
        if (!$matchId || !$slot) return;
        $column = ($slot === '1') ? 'player1_id' : 'player2_id';
        $sql = "UPDATE tournament_matches SET $column = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $playerId, $matchId);
        $stmt->execute();

        $this->checkAndHandleBye($matchId, $slot, $sourceMatchId);
    }

    private function getMatchMeta($matchId) {
        $sql = "SELECT tm.match_number, tr.round_number, tr.stage
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tm.id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $matchId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    private function getMaxRoundNumber($stage) {
        $sql = "SELECT MAX(round_number) AS max_round FROM tournament_rounds WHERE tournament_id = ? AND stage = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tournamentId, $stage);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['max_round'] ?? 0);
    }

    private function getPlacementMatchId($matchNumber) {
        if (array_key_exists($matchNumber, $this->placementMatchCache)) {
            return $this->placementMatchCache[$matchNumber];
        }

        $sql = "SELECT id FROM tournament_matches WHERE tournament_id = ? AND match_number = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tournamentId, $matchNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $this->placementMatchCache[$matchNumber] = $row['id'] ?? null;
        return $this->placementMatchCache[$matchNumber];
    }

    private function getTournamentRankTo() {
        if ($this->rankToCache !== null) {
            return $this->rankToCache;
        }

        $sql = "SELECT rank_to FROM tournaments WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $this->rankToCache = isset($row['rank_to']) ? (int)$row['rank_to'] : 0;
        return $this->rankToCache;
    }

    /**
     * Logic split from propagateWinner for better reuse
     */
    private function checkAndHandleBye($nextMatchId, $slot, $parentMatchId) {
        // Find the "other" parent match for this nextMatchId
        $otherSlot = ($slot == '1') ? '2' : '1';
        
        // This is tricky for Loser paths because they don't always have a strict "parent match next_match_id" relationship
        // Actually, they DO because we set loser_next_match_id on the parent.
        
        $sql = "SELECT id, status, winner_id FROM tournament_matches 
                WHERE (next_match_id = ? OR loser_next_match_id = ?) 
                AND ((next_match_id = ? AND next_match_slot = ?) OR (loser_next_match_id = ? AND loser_next_match_slot = ?))
                AND id != ?";
        $stmt = $this->conn->prepare($sql);
        // We need to be careful with IDs here.
        // Let's simplify: check if the other slot in nextMatchId is already completed/empty.
        
        $sql = "SELECT player1_id, player2_id FROM tournament_matches WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $nextMatchId);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();

        // Check if the OTHER player slot is destined to be empty (BYE)
        // This usually happens in the first round of topcut.
        // For higher rounds, we look at the other parent's status.
        
        $sql = "SELECT id, status, winner_id FROM tournament_matches 
                WHERE (next_match_id = ? AND next_match_slot = ?)
                OR (loser_next_match_id = ? AND loser_next_match_slot = ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isis", $nextMatchId, $otherSlot, $nextMatchId, $otherSlot);
        $stmt->execute();
        $otherParent = $stmt->get_result()->fetch_assoc();

        if ($otherParent && $otherParent['status'] === 'completed' && !$otherParent['winner_id']) {
            // The other side is permanently empty. This is a BYE.
            $sql = "SELECT player1_id, player2_id FROM tournament_matches WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $nextMatchId);
            $stmt->execute();
            $m = $stmt->get_result()->fetch_assoc();
            
            $currentAdvancer = ($slot == '1') ? $m['player1_id'] : $m['player2_id'];
            
            if ($currentAdvancer) {
                $sql = "UPDATE tournament_matches SET status = 'completed', winner_id = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("si", $currentAdvancer, $nextMatchId);
                $stmt->execute();
                
                // Get the loser of this "match" (which is null)
                $this->propagateWinner($nextMatchId, $currentAdvancer, null);
            }
        }
    }

    public function generateNextSwissRound() {
        $tournament = $this->getTournamentDetails();
        if ($tournament['tournament_type'] !== 'swiss' || (int)$tournament['current_stage'] !== 1) {
            throw new Exception("This action is only for Swiss Stage 1.");
        }

        $players = $this->getPlayers();
        if (count($players) < 2) {
            throw new Exception("Not enough players to generate next Swiss round.");
        }

        $totalRounds = $this->calculateTotalSwissRounds($players, $tournament);

        $activeRound = $this->getActiveSwissRound();
        $currentRoundNumber = 0;

        if ($activeRound) {
            $pendingCount = $this->countUnfinishedMatches($activeRound['id']);
            if ($pendingCount > 0) {
                throw new Exception("Cannot generate Round " . ($activeRound['round_number'] + 1) . ": {$pendingCount} match" . ($pendingCount === 1 ? '' : 'es') . " in Round {$activeRound['round_number']} are still unfinished.");
            }
            $currentRoundNumber = (int)$activeRound['round_number'];
        } else {
            $currentRoundNumber = $this->getLatestCompletedSwissRoundNumber();
        }

        if ($currentRoundNumber >= $totalRounds) {
            if ($activeRound) {
                $this->markRoundStatus($activeRound['id'], 'completed');
                $this->snapshotSwissStandings($currentRoundNumber);
            }
            return ['success' => true, 'message' => 'All Swiss rounds already generated.'];
        }

        if ($currentRoundNumber > 0) {
            $this->snapshotSwissStandings($currentRoundNumber);
        }

        $nextRoundNumber = $currentRoundNumber + 1;
        if ($nextRoundNumber > $totalRounds) {
            return ['success' => true, 'message' => 'Swiss schedule complete.'];
        }

        $pairings = $this->buildSwissPairings($players, $nextRoundNumber, false);

        $this->conn->begin_transaction();
        try {
            if ($activeRound) {
                $this->markRoundStatus($activeRound['id'], 'completed');
            }

            $roundId = $this->createSwissRound($nextRoundNumber, 'active');
            $this->persistSwissPairings($roundId, $pairings);

            $this->conn->commit();
        } catch (Exception $e) {
            if ($this->conn) {
                try { $this->conn->rollback(); } catch (Exception $re) {}
            }
            throw $e;
        }

        $engine = new MatchEngine(new Database(), $this->tournamentId);
        $engine->runAssignment();

        return ['success' => true, 'message' => "Round {$nextRoundNumber} paired."];
    }

    private function getCurrentRoundNumber() {
        $sql = "SELECT MAX(round_number) as max_rn FROM tournament_rounds WHERE tournament_id = ? AND status = 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        
        // If no rounds completed, maybe all matches in current round are completed?
        $sql = "SELECT r.round_number FROM tournament_rounds r 
                LEFT JOIN tournament_matches m ON r.id = m.round_id 
                WHERE r.tournament_id = ? AND r.status = 'active' 
                GROUP BY r.id HAVING COUNT(m.id) > 0 AND SUM(m.status='completed') = COUNT(m.id)
                ORDER BY r.round_number DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $res2 = $stmt->get_result()->fetch_assoc();
        
        return $res2['round_number'] ?? $res['max_rn'] ?? 0;
    }

    private function isRoundComplete($roundId) {
        $sql = "SELECT COUNT(*) FROM tournament_matches WHERE round_id = ? AND status != 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $roundId);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_row()[0];
        return $count == 0;
    }

    public function recordResult($matchId, $p1score = 0, $p2score = 0) {
        $scoringService = new ScoringService(new Database(), $this->tournamentId);
        $finishes = isset($_POST['finishes']) ? json_decode($_POST['finishes'], true) : [];
        
        $result = $scoringService->recordResult($matchId, $p1score, $p2score, $finishes);
        
        if ($result['success'] && isset($result['round_id'])) {
            $roundId = $result['round_id'];

            // Advance winner/loser in single elimination
            if (!empty($result['winner_id'])) {
                $winnerId = $result['winner_id'];
                $player1Id = $result['player1_id'] ?? null;
                $player2Id = $result['player2_id'] ?? null;
                $loserId = $result['loser_id'] ?? null;

                if (!$player1Id || !$player2Id || !$loserId) {
                    $sql = "SELECT player1_id, player2_id FROM tournament_matches WHERE id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("i", $matchId);
                    $stmt->execute();
                    $matchData = $stmt->get_result()->fetch_assoc();
                    if ($matchData) {
                        $player1Id = $player1Id ?: $matchData['player1_id'];
                        $player2Id = $player2Id ?: $matchData['player2_id'];
                    }

                    if (!$loserId && $player1Id && $player2Id) {
                        $loserId = ($winnerId == $player1Id) ? $player2Id : $player1Id;
                    }
                }

                $this->propagateWinner($matchId, $winnerId, $loserId);
            }
            
            // Check if current round is complete
            if ($this->isRoundComplete($roundId)) {
                error_log("Round $roundId is complete, marking as completed");
                $this->conn->query("UPDATE tournament_rounds SET status = 'completed' WHERE id = $roundId");
                
                // Auto-pair next Swiss round if applicable (only in Swiss Stage 1)
                $tournament = $this->getTournamentDetails();
                error_log("Tournament type: " . $tournament['tournament_type'] . ", Stage: " . $tournament['current_stage']);
                
                if ($tournament['tournament_type'] === 'swiss' && (int)$tournament['current_stage'] === 1) {
                    try {
                        error_log("Generating next Swiss round");
                        $this->generateNextSwissRound();
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), "Next round not found") === false) {
                            throw $e;
                        }
                    }
                } else if ($tournament['tournament_type'] === 'swiss' && (int)$tournament['current_stage'] === 2) {
                    // In Swiss Stage 2 (Top Cut), check if we should activate the next round
                    error_log("Activating next Swiss Stage 2 round");
                    $currentRoundNum = $this->getCurrentRoundNumber();
                    $nextRoundNum = $currentRoundNum + 1;
                    error_log("Current round: $currentRoundNum, Next round: $nextRoundNum");
                    $sql = "UPDATE tournament_rounds SET status = 'active' 
                            WHERE tournament_id = ? AND round_number = ? AND stage = 2";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("ii", $this->tournamentId, $nextRoundNum);
                    $stmt->execute();
                } else if ($tournament['tournament_type'] === 'single_elimination' && (int)$tournament['current_stage'] === 1) {
                    // In Single Elimination Stage 1, check if we should activate the next round
                    error_log("Activating next Single Elimination round");
                    $currentRoundNum = $this->getCurrentRoundNumber();
                    $nextRoundNum = $currentRoundNum + 1;
                    error_log("Current round: $currentRoundNum, Next round: $nextRoundNum");
                    $sql = "UPDATE tournament_rounds SET status = 'active' 
                            WHERE tournament_id = ? AND round_number = ? AND stage = 1";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("ii", $this->tournamentId, $nextRoundNum);
                    $result = $stmt->execute();
                    error_log("Update result: " . ($result ? "success" : "failed"));
                }
            }
            
            // Auto-assign next matches now that a judge/stadium is free
            try {
                $engine = new MatchEngine($this->conn, $this->tournamentId);
                $engine->runAssignment();
            } catch (Exception $e) {
                error_log("Auto-assignment warning: " . $e->getMessage());
            }
        }
        
        return $result;
    }

    private function initializeStadiums($num) {
        $num = $num ?: 1;
        $this->conn->query("DELETE FROM tournament_stadiums WHERE tournament_id = $this->tournamentId");
        for ($i = 1; $i <= $num; $i++) {
            $name = "Stadium $i";
            $sql = "INSERT INTO tournament_stadiums (tournament_id, name) VALUES (?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("is", $this->tournamentId, $name);
            $stmt->execute();
        }
    }

    private function cleanupTournament() {
        $this->conn->query("DELETE FROM tournament_byes WHERE tournament_id = $this->tournamentId");
        $this->conn->query("DELETE FROM tournament_rounds WHERE tournament_id = $this->tournamentId");
    }

    private function getTournamentDetails() {
        $sql = "SELECT * FROM tournaments WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    private function getPlayers() {
        // First, check for orphans that would cause FK failures later
        $sql = "SELECT tr.user_id FROM tournament_roles tr 
                LEFT JOIN users u ON tr.user_id = u.id 
                WHERE tr.tournament_id = ? AND (FIND_IN_SET('player', tr.role) > 0) AND u.id IS NULL";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $orphans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (!empty($orphans)) {
            $ids = implode(', ', array_column($orphans, 'user_id'));
            throw new Exception("Found tournament roles with invalid/missing User IDs: [$ids]. Please re-add these participants.");
        }

        $sql = "SELECT user_id as id FROM tournament_roles WHERE tournament_id = ? AND (FIND_IN_SET('player', role) > 0)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $players = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Final sanity check: filter out any empty IDs that might have slipped through
        return array_values(array_filter($players, function($p) {
            return !empty($p['id']);
        }));
    }

    public function endTournament() {
        $this->conn->query("UPDATE tournaments SET status = 'completed' WHERE id = " . $this->tournamentId);
        return ['success' => true, 'message' => 'Tournament completed successfully!'];
    }

    public function getPodium() {
        $rankings = $this->getFinalRankings();

        $swissKing = null;
        try {
            $calc = new StandingsCalculator($this->conn, $this->tournamentId);
            $standings = $calc->calculate();
            if (!empty($standings)) {
                $leader = $standings[0];
                $swissKing = [
                    'id' => $leader['id'],
                    'name' => $leader['name'],
                    'points' => $leader['points'],
                    'wins' => $leader['wins'],
                    'losses' => $leader['losses'],
                    'draws' => $leader['draws'],
                    'bey_points' => $leader['bey_points'],
                    'fqi' => $leader['fqi']
                ];
            }
        } catch (Exception $e) {
            // If standings cannot be calculated, we simply omit Swiss King from the response
        }

        return ['success' => true, 'podium' => $rankings, 'swissKing' => $swissKing];
    }

    private function getFinalRankings() {
        $rankings = [];

        // 1. Finals (1st & 2nd)
        $sql = "SELECT winner_id, player1_id, player2_id 
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tm.tournament_id = ? AND tr.stage = 2 AND tm.next_match_id IS NULL AND tm.match_number = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $finals = $stmt->get_result()->fetch_assoc();

        if ($finals && $finals['winner_id']) {
            $rankings[1] = $finals['winner_id'];
            $rankings[2] = ($finals['winner_id'] == $finals['player1_id']) ? $finals['player2_id'] : $finals['player1_id'];
        }

        // 2. Consolation Matches
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
            $stmt->bind_param("ii", $this->tournamentId, $mNum);
            $stmt->execute();
            $match = $stmt->get_result()->fetch_assoc();

            if ($match && $match['winner_id']) {
                $winIdx = $places[0];
                $loseIdx = $places[1];
                $rankings[$winIdx] = $match['winner_id'];
                $rankings[$loseIdx] = ($match['winner_id'] == $match['player1_id']) ? $match['player2_id'] : $match['player1_id'];
            }
        }

        if (empty($rankings)) return [];

        // Fetch display names
        $uniqueIds = array_unique(array_filter(array_values($rankings)));
        if (empty($uniqueIds)) return [];

        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        $sql = "SELECT id, display_name FROM users WHERE id IN ($placeholders)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($uniqueIds)), ...$uniqueIds);
        $stmt->execute();
        $users = [];
        $res = $stmt->get_result();
        while($u = $res->fetch_assoc()) $users[$u['id']] = $u;

        $result = [];
        foreach ($rankings as $place => $userId) {
            if ($userId) {
                $result[$place] = [
                    'id' => $userId,
                    'name' => $users[$userId]['display_name'] ?? 'Unknown'
                ];
            }
        }

        return $result;
    }
}

class SwissPairing {
    private $conn;
    private $tournamentId;

    public function __construct($conn, $tournamentId) {
        $this->conn = $conn;
        $this->tournamentId = $tournamentId;
    }

    public function calculatePairings($players, $includeScheduled = false, $roundNumber = 1) {
        $meta = $this->buildPlayerMeta($players, $includeScheduled);

        if (count($meta) === 0) {
            return [];
        }

        if (count($meta) === 1) {
            // Only one player remains — auto bye.
            return [[
                'p1' => $meta[0]['id'],
                'p2' => null,
                'is_bye' => true,
            ]];
        }

        if ($roundNumber === 1) {
            return $this->pairFirstRound($meta);
        }

        return $this->pairByScoreGroups($meta);
    }

    private function buildPlayerMeta(array $players, bool $includeScheduled): array {
        $seeds = [];
        $sql = "SELECT user_id, seed FROM tournament_roles WHERE tournament_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $seeds[$row['user_id']] = (int)($row['seed'] ?? 999);
        }

        $hasBye = [];
        $sql = "SELECT user_id FROM tournament_byes WHERE tournament_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $byeRes = $stmt->get_result();
        while ($row = $byeRes->fetch_assoc()) {
            $hasBye[$row['user_id']] = true;
        }

        $meta = [];
        foreach ($players as $player) {
            $id = $player['id'];
            $meta[] = [
                'id' => $id,
                'score' => $this->calculatePlayerScore($id),
                'seed' => $seeds[$id] ?? 999,
                'opponents' => $this->getPreviousOpponents($id, $includeScheduled),
                'had_bye' => isset($hasBye[$id]),
            ];
        }

        usort($meta, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $a['seed'] <=> $b['seed'];
        });

        return $meta;
    }

    private function pairFirstRound(array $meta): array {
        // Challonge fold pairing for Round 1 (1vN/2+1, 2vN/2+2, ...)
        $pairings = [];
        $count = count($meta);
        $half = intdiv($count, 2);

        for ($i = 0; $i < $half; $i++) {
            $pairings[] = [
                'p1' => $meta[$i]['id'],
                'p2' => $meta[$i + $half]['id'] ?? null,
                'is_bye' => false,
            ];
        }

        if ($count % 2 === 1) {
            // Assign bye to the lowest-ranked player who has not yet received one.
            for ($i = $count - 1; $i >= 0; $i--) {
                if (!$meta[$i]['had_bye']) {
                    $pairings[] = [
                        'p1' => $meta[$i]['id'],
                        'p2' => null,
                        'is_bye' => true,
                    ];
                    break;
                }
            }

            if (($pairings[count($pairings) - 1]['is_bye'] ?? false) === false) {
                // Every player has already received a bye; assign to the last player.
                $pairings[] = [
                    'p1' => $meta[$count - 1]['id'],
                    'p2' => null,
                    'is_bye' => true,
                ];
            }
        }

        return $this->sortPairingsByJudgeLoad($pairings);
    }

    private function pairByScoreGroups(array $meta): array {
        $groups = [];
        foreach ($meta as $player) {
            $groups[$player['score']][] = $player;
        }

        $scores = array_keys($groups);
        rsort($scores, SORT_NUMERIC);

        $pairings = [];
        $floater = null;

        foreach ($scores as $index => $score) {
            $group = $groups[$score];

            if ($floater !== null) {
                array_unshift($group, $floater);
                $floater = null;
            }

            $isLastGroup = ($index === count($scores) - 1);

            if (count($group) % 2 === 1) {
                if ($isLastGroup) {
                    // No lower group exists – award a bye within this group.
                    $byeIndex = $this->selectByeCandidateIndex($group);
                    $byePlayer = $group[$byeIndex];
                    unset($group[$byeIndex]);
                    $group = array_values($group);

                    $pairings[] = [
                        'p1' => $byePlayer['id'],
                        'p2' => null,
                        'is_bye' => true,
                    ];
                } else {
                    // Float the lowest-ranked player to the next score group.
                    $floater = array_pop($group);
                    // Documented floater to clarify pairing behaviour.
                }
            }

            if (!empty($group)) {
                $pairings = array_merge($pairings, $this->pairGroupPlayers($group));
            }
        }

        if ($floater !== null) {
            // All groups processed yet a floater remains – give them a bye.
            $pairings[] = [
                'p1' => $floater['id'],
                'p2' => null,
                'is_bye' => true,
            ];
        }

        return $this->sortPairingsByJudgeLoad($pairings);
    }

    private function pairGroupPlayers(array $group): array {
        $available = array_values($group);
        $pairings = [];

        while (count($available) > 1) {
            $p1 = array_shift($available);
            $idx = $this->findOpponentIndex($p1, $available);
            $opponent = $available[$idx];
            array_splice($available, $idx, 1);

            $pairings[] = [
                'p1' => $p1['id'],
                'p2' => $opponent['id'],
                'is_bye' => false,
            ];
        }

        // Any leftover player will be floated/handled by caller.
        return $pairings;
    }

    private function findOpponentIndex(array $player, array $candidates): int {
        $played = $player['opponents'];

        foreach ($candidates as $index => $candidate) {
            if (!in_array($candidate['id'], $played, true)) {
                return $index;
            }
        }

        return 0; // Rematch unavoidable.
    }

    private function selectByeCandidateIndex(array $group): int {
        // Prefer the lowest-ranked player who has not already received a bye.
        for ($i = count($group) - 1; $i >= 0; $i--) {
            if (!$group[$i]['had_bye']) {
                return $i;
            }
        }

        return count($group) - 1;
    }

    private function sortPairingsByJudgeLoad(array $pairings): array {
        $roles = [];
        $sql = "SELECT user_id, role FROM tournament_roles WHERE tournament_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $roles[$row['user_id']] = $row['role'];
        }

        usort($pairings, function ($a, $b) use ($roles) {
            $weightA = $this->judgeWeight($a, $roles);
            $weightB = $this->judgeWeight($b, $roles);

            if ($weightA === $weightB) {
                return 0;
            }
            return $weightA <=> $weightB;
        });

        return $pairings;
    }

    private function judgeWeight(array $pair, array $roles): int {
        if ($pair['is_bye']) {
            return 3; // Push byes to the end.
        }

        $weight = 0;

        if ($pair['p1'] && isset($roles[$pair['p1']]) && strpos($roles[$pair['p1']], 'judge') !== false) {
            $weight++;
        }

        if ($pair['p2'] && isset($roles[$pair['p2']]) && strpos($roles[$pair['p2']], 'judge') !== false) {
            $weight++;
        }

        return $weight;
    }

    private function calculatePlayerScore($userId) {
        $sql = "SELECT player1_id, player2_id, winner_id FROM tournament_matches 
                WHERE (player1_id = ? OR player2_id = ?) AND status = 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $userId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $points = 0;
        while ($row = $res->fetch_assoc()) {
            if ($row['winner_id'] === $userId) $points += 1;
        }

        // Add Bye points (1 per bye)
        $sql = "SELECT COUNT(*) as bye_count FROM tournament_byes WHERE user_id = ? AND tournament_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $userId, $this->tournamentId);
        $stmt->execute();
        $bc = $stmt->get_result()->fetch_assoc()['bye_count'];
        $points += $bc;

        return $points;
    }

    private function getPreviousOpponents($userId, $includeScheduled = false) {
        $statusClause = $includeScheduled ? "status IN ('completed', 'scheduled', 'assigned', 'in_progress')" : "status = 'completed'";
        $sql = "SELECT IF(player1_id = ?, player2_id, player1_id) as opponent 
                FROM tournament_matches WHERE (player1_id = ? OR player2_id = ?) AND $statusClause";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $userId, $userId, $userId);
        $stmt->execute();
        return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'opponent');
    }
}

// Handler (only run if called directly)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    // Determine input method
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($input)) {
        // Setup $_POST and $_GET from JSON body if applicable
        $_POST = array_merge($_POST, $input);
        $_REQUEST = array_merge($_REQUEST, $input);
    }

    $database = new Database();
    $tournamentId = $_REQUEST['tournament_id'] ?? null;
    $action = $_REQUEST['action'] ?? '';

    if (!$tournamentId) {
        if (ob_get_length()) ob_clean();
        die(json_encode(['success' => false, 'message' => 'Tournament ID required']));
    }

    $mgr = new TournamentManager($database, $tournamentId);

    $response = null;

    switch ($action) {
        case 'start':
            $response = $mgr->startTournament();
            break;
        case 'generate': // For Swiss Round 2+ 
            $response = $mgr->generateNextSwissRound();
            break;
        case 'recordResult':
             $p1s = $_POST['p1_score'] ?? 0;
             $p2s = $_POST['p2_score'] ?? 0;
             // Also handle JSON body case if not in $_POST? handled above.
            $response = $mgr->recordResult($_POST['match_id'], $p1s, $p2s);
            break;
        case 'getState':
            // Reuse state fetching logic from previous implementation
            try {
                $sql = "SELECT tr.id as round_id, tr.round_number, tr.status as round_status, tr.stage,
                               tm.id as match_id, tm.match_number, tm.player1_id, tm.player2_id, 
                               tm.winner_id, tm.status as match_status, tm.blocked_reason,
                               tm.player1_score, tm.player2_score,
                               tm.next_match_id, tm.next_match_slot,
                               tm.loser_next_match_id, tm.loser_next_match_slot,
                                u1.display_name as p1_name, u2.display_name as p2_name,
                               ma.judge_id, ju.display_name as judge_name,
                               ma.stadium_id, ts.name as stadium_name
                        FROM tournament_rounds tr
                        JOIN tournaments t ON tr.tournament_id = t.id
                        LEFT JOIN tournament_matches tm ON tr.id = tm.round_id
                        LEFT JOIN users u1 ON tm.player1_id = u1.id
                        LEFT JOIN users u2 ON tm.player2_id = u2.id
                        LEFT JOIN match_assignments ma ON tm.id = ma.match_id
                        LEFT JOIN users ju ON ma.judge_id = ju.id
                        LEFT JOIN tournament_stadiums ts ON ma.stadium_id = ts.id
                        WHERE tr.tournament_id = ?
                          AND (tr.stage != 1 OR tr.status IN ('completed', 'active'))
                        ORDER BY tr.stage ASC, tr.round_number ASC, tm.match_number ASC";
                
                $stmt = $database->getConnection()->prepare($sql);
                $stmt->bind_param("i", $tournamentId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                $roundsData = [];
                $matchIndexMap = [];
                foreach ($rows as $row) {
                    $stage = (int)$row['stage'];
                    $rn = $row['round_number'];
                    $key = "s{$stage}_r{$rn}";
                    if (!isset($roundsData[$key])) {
                        $roundsData[$key] = [
                            'id' => $row['round_id'],
                            'round_number' => $rn,
                            'status' => $row['round_status'],
                            'stage' => $stage,
                            'matches' => []
                        ];
                    }
                    if ($row['match_id']) {
                        // For Single Elim (Stage 2), we want to show TBD vs TBD clearly
                        $isStage2 = (int)$row['stage'] === 2;
                        $isByeMatch = $row['player2_id'] === null;
                        $p1Name = $row['p1_name'];
                        $p2Name = $row['p2_name'];
                        
                        if (!$p1Name) {
                            $p1Name = 'TBD';
                        }
                        
                        if ($isByeMatch) {
                            $p2Name = 'BYE';
                        } elseif (!$p2Name) {
                            if ($rn == 1 && $isStage2 && $row['player1_id']) {
                                $p2Name = 'BYE';
                            } else {
                                $p2Name = 'TBD';
                            }
                        }

                        $matchEntry = [
                            'id' => $row['match_id'],
                            'match_number' => $row['match_number'],
                            'status' => $row['match_status'],
                            'blocked_reason' => $row['blocked_reason'],
                            'player1' => ['id' => $row['player1_id'], 'name' => $p1Name, 'score' => $row['player1_score']],
                            'player2' => ['id' => $row['player2_id'], 'name' => $p2Name, 'score' => $row['player2_score']],
                            'winner_id' => $row['winner_id'],
                            'next_match_id' => $row['next_match_id'] ? (int)$row['next_match_id'] : null,
                            'next_match_slot' => $row['next_match_slot'],
                            'loser_next_match_id' => $row['loser_next_match_id'] ? (int)$row['loser_next_match_id'] : null,
                            'loser_next_match_slot' => $row['loser_next_match_slot'],
                            'judge' => $row['judge_id'] ? ['id' => $row['judge_id'], 'name' => $row['judge_name']] : null,
                            'stadium' => $row['stadium_id'] ? ['id' => $row['stadium_id'], 'name' => $row['stadium_name']] : null,
                            'finishes' => [
                                'player1' => [],
                                'player2' => []
                            ],
                            'is_bye' => $isByeMatch
                        ];

                        $roundsData[$key]['matches'][] = $matchEntry;
                        $matchIndexMap[(int)$row['match_id']] = ['key' => $key, 'index' => count($roundsData[$key]['matches']) - 1];
                    }
                }

                if (!empty($matchIndexMap)) {
                    $matchIds = array_map('intval', array_keys($matchIndexMap));
                    $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
                    $types = str_repeat('i', count($matchIds));

                    $finishSql = "SELECT mf.match_id, mf.player_id, mf.finish_type, mf.points 
                                   FROM match_finishes mf 
                                   WHERE mf.match_id IN ($placeholders)
                                   ORDER BY mf.id ASC";

                    $conn = $database->getConnection();
                    $finishStmt = $conn->prepare($finishSql);

                    if ($finishStmt) {
                        $finishStmt->bind_param($types, ...$matchIds);
                        $finishStmt->execute();
                        $finishResult = $finishStmt->get_result();

                        while ($finishRow = $finishResult->fetch_assoc()) {
                            $matchId = (int)$finishRow['match_id'];
                            if (!isset($matchIndexMap[$matchId])) continue;

                            $mapInfo = $matchIndexMap[$matchId];
                            $matchRef =& $roundsData[$mapInfo['key']]['matches'][$mapInfo['index']];

                            $playerSlot = null;
                            $playerId = $finishRow['player_id'];

                            if (!empty($matchRef['player1']['id']) && (string)$matchRef['player1']['id'] === (string)$playerId) {
                                $playerSlot = 'player1';
                            } elseif (!empty($matchRef['player2']['id']) && (string)$matchRef['player2']['id'] === (string)$playerId) {
                                $playerSlot = 'player2';
                            }

                            if ($playerSlot !== null) {
                                $matchRef['finishes'][$playerSlot][] = [
                                    'type' => $finishRow['finish_type'],
                                    'points' => (int)$finishRow['points']
                                ];
                            }
                        }
                        $finishStmt->close();
                    }
                }
                $roundEntries = array_values($roundsData);
                usort($roundEntries, function ($a, $b) {
                    if ($a['stage'] === $b['stage']) {
                        return (int)$a['round_number'] <=> (int)$b['round_number'];
                    }
                    return (int)$a['stage'] <=> (int)$b['stage'];
                });

                $response = ['success' => true, 'rounds' => $roundEntries];
            } catch (Exception $e) {
                 $response = ['success' => false, 'message' => 'State Fetch Error: ' . $e->getMessage()];
            }
            break;
            
        case 'getStandings':
            $calc = new StandingsCalculator($database->getConnection(), $tournamentId);
            $response = ['success' => true, 'standings' => $calc->calculate()];
            break;
            
        case 'advanceToTopCut':
            $response = $mgr->generateTopCutBracket();
            break;
            
        case 'saveTopCut':
            $topCut = (int)($_POST['top_cut'] ?? 0);
            $sql = "UPDATE tournaments SET top_cut = ? WHERE id = ?";
            $stmt = $database->getConnection()->prepare($sql);
            $stmt->bind_param("ii", $topCut, $tournamentId);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Top cut updated.'];
            } else {
                 $response = ['success' => false, 'message' => 'Failed to update top cut.'];
            }
            break;
            
        case 'endTournament':
            $response = $mgr->endTournament();
            break;
            
        case 'getPodium':
            $response = $mgr->getPodium();
            break;
            
        default:
             $response = ['success' => false, 'message' => 'Unknown Action: ' . $action];
             break;
    }

    // FINAL OUTPUT
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response);
    exit;
}

class StandingsCalculator {
    private $conn;
    private $tournamentId;

    public function __construct($conn, $tournamentId) {
        $this->conn = $conn;
        $this->tournamentId = $tournamentId;
    }

    public function calculate() {
        // Fetch players and seeds
        $sql = "SELECT tr.user_id as id, u.display_name, u.blader_name, tr.seed 
                FROM tournament_roles tr 
                JOIN users u ON tr.user_id = u.id 
                WHERE tr.tournament_id = ? AND (FIND_IN_SET('player', tr.role) > 0)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $players = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Fetch all completed matches (ONLY Stage 1 for Swiss standings)
        $sql = "SELECT tm.id, tm.player1_id, tm.player2_id, tm.winner_id, tm.player1_score, tm.player2_score 
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tm.tournament_id = ? AND tm.status = 'completed' AND tr.stage = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Fetch all finish details for Bey Points & FQI (Stage 1 only)
        // We use LIKE to match 'Burst Finish', 'Over Finish', etc.
        $sql = "SELECT mf.player_id, 
                       SUM(mf.points) as total_bey_points,
                       COUNT(*) as total_finishes,
                       SUM(CASE WHEN LOWER(mf.finish_type) LIKE '%burst%' 
                                  OR LOWER(mf.finish_type) LIKE '%over%' 
                                  OR LOWER(mf.finish_type) LIKE '%xtreme%' 
                                THEN 1 ELSE 0 END) as quality_finishes
                FROM match_finishes mf
                JOIN tournament_matches tm ON mf.match_id = tm.id
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tm.tournament_id = ? AND tr.stage = 1
                GROUP BY mf.player_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $finishStatsRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $finishStats = [];
        foreach ($finishStatsRaw as $row) {
            $finishStats[$row['player_id']] = $row;
        }

        $stats = [];
        $opponents = []; // List of opponent IDs for OMPA
        foreach ($players as $p) {
            $pid = $p['id'];
            $fStat = $finishStats[$pid] ?? ['total_bey_points' => 0, 'total_finishes' => 0, 'quality_finishes' => 0];
            
            // Calculate FQI
            $totalF = (int)$fStat['total_finishes'];
            $qualityF = (int)$fStat['quality_finishes'];
            $fqi = ($totalF > 0) ? round($qualityF / $totalF, 4) : 0;

            $stats[$pid] = [
                'id' => $pid,
                'name' => $p['display_name'] ?: $p['blader_name'],
                'seed' => $p['seed'],
                'points' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'pf' => 0,
                'pa' => 0,
                'bey_points' => (int)$fStat['total_bey_points'],
                'fqi' => $fqi,
                'ompa' => 0,
                'buchholz' => 0,
                'byes' => 0
            ];
            $opponents[$pid] = [];
        }

        // Processing Matches
        foreach ($matches as $m) {
            $p1 = $m['player1_id'];
            $p2 = $m['player2_id'];
            $winner = $m['winner_id'];
            $p1Score = (int)$m['player1_score'];
            $p2Score = (int)$m['player2_score'];

            if ($p1 && isset($stats[$p1])) {
                if ($p2) $opponents[$p1][] = (string)$p2;
                $stats[$p1]['pf'] += $p1Score;
                $stats[$p1]['pa'] += $p2Score;
                if ($winner === $p1) {
                    $stats[$p1]['points'] += 1;
                    $stats[$p1]['wins']++;
                } else if ($winner !== null) {
                    $stats[$p1]['losses']++;
                }
            }

            if ($p2 && isset($stats[$p2])) {
                if ($p1) $opponents[$p2][] = (string)$p1;
                $stats[$p2]['pf'] += $p2Score;
                $stats[$p2]['pa'] += $p1Score;
                if ($winner === $p2) {
                    $stats[$p2]['points'] += 1;
                    $stats[$p2]['wins']++;
                } else if ($winner !== null) {
                    $stats[$p2]['losses']++;
                }
            }
        }

        // Processing Byes (Stage 1 only)
        $sql = "SELECT tb.user_id, tr.status FROM tournament_byes tb
                JOIN tournament_rounds tr ON tb.round_id = tr.id 
                WHERE tb.tournament_id = ? AND tr.stage = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $byes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($byes as $b) {
            if (isset($stats[$b['user_id']])) {
                if ($b['status'] === 'completed') {
                    $stats[$b['user_id']]['points'] += 1;
                    $stats[$b['user_id']]['wins']++;
                    $stats[$b['user_id']]['byes']++;
                    $stats[$b['user_id']]['pf'] += 4;
                }
            }
        }

        // Calculate OMPA and Opponent Win Rate
        foreach ($stats as $id => &$s) {
            $oppBeySum = 0;
            $oppCount = 0;
            $oppWins = 0;
            $oppTotalMatches = 0;
            
            foreach ($opponents[$id] as $oppId) {
                if (isset($stats[$oppId])) {
                    $oppBeySum += (int)$stats[$oppId]['bey_points'];
                    $oppCount++;
                    $s['buchholz'] += $stats[$oppId]['points'];
                    
                    // Calculate opponent win rate
                    $oppWins += (int)$stats[$oppId]['points'];
                    $oppTotalMatches += (int)$stats[$oppId]['wins'] + (int)$stats[$oppId]['losses'];
                }
            }
            
            $s['ompa'] = ($oppCount > 0) ? round($oppBeySum / $oppCount, 2) : 0;
            
            // Calculate combined strength metric: (OMPA × 2) + (Opponent Win Rate × 5)
            $oppWinRate = ($oppTotalMatches > 0) ? $oppWins / $oppTotalMatches : 0;
            $s['strength_metric'] = round(($s['ompa'] * 2) + ($oppWinRate * 5), 2);
        }

        // New Sorting Logic: 
        // 1. Points (Wins)
        // 2. Bey Points (Skill)
        // 3. Strength Metric (OMPA × 2 + Opponent Win Rate × 5)
        // 4. FQI (Aggressiveness)
        // 5. Head-to-Head (Not fully implemented here, complex for multi-way ties)
        // 6. Seed
        $standings = array_values($stats);
        usort($standings, function($a, $b) {
            // 1. Match Points
            if ($b['points'] != $a['points']) return $b['points'] <=> $a['points'];
            
            // 2. Bey Points
            if ($b['bey_points'] != $a['bey_points']) return $b['bey_points'] <=> $a['bey_points'];
            
            // 3. Strength Metric (OMPA × 2 + Opponent Win Rate × 5)
            if ($b['strength_metric'] != $a['strength_metric']) return $b['strength_metric'] <=> $a['strength_metric'];
            
            // 4. FQI
            if ($b['fqi'] != $a['fqi']) return $b['fqi'] <=> $a['fqi'];
            
            // 5. Fallback: Seed (Ascending)
            return ($a['seed'] ?? 999) <=> ($b['seed'] ?? 999);
        });

        return $standings;
    }
}

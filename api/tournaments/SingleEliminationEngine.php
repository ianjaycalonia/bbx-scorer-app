<?php
require_once dirname(__DIR__) . '/config/database.php';

class SingleEliminationEngine
{
    private $conn;
    private $tournamentId;
    private $rankToCache = null;
    private $placementMatchCache = [];

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
     * Generate Single Elimination Bracket
     */
    public function generate(array $players, $shuffle = true, $stage = 1, $rankTo = 3)
    {
        if ($shuffle) {
            shuffle($players);
        }
        $numPlayers = count($players);
        $numRounds = ceil(log($numPlayers, 2));
        $bracketSize = pow(2, $numRounds);

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

        // Build the tree from Final back to Round 1
        $matchesByRound = [];

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
                $slot = (string) ($i + 1);
                $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("isi", $thirdMatchId, $slot, $semiMatches[$i]);
                $stmt->execute();
            }

            // 2. 5th-8th Ranking (Matches 95, 94, 98, 97)
            if ($rankTo >= 5 && $numRounds >= 3) {
                $quarterRound = $numRounds - 2;
                $qfMatches = $matchesByRound[$quarterRound];

                // Create Consolation Semifinals (95 and 94)
                $consSemiIds = [];
                for ($i = 0; $i < 2; $i++) {
                    $mNum = 95 - $i;
                    $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, ?, 'scheduled')";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("iii", $this->tournamentId, $finalsRoundId, $mNum);
                    $stmt->execute();
                    $consSemiIds[$i] = $stmt->insert_id;

                    // Wire losers from QF to these semis
                    // Semi 95: Losers of QF 1 & 2
                    // Semi 94: Losers of QF 3 & 4
                    for ($j = 0; $j < 2; $j++) {
                        $qfIdx = ($i * 2) + $j;
                        if (isset($qfMatches[$qfIdx])) {
                            $slot = (string) ($j + 1);
                            $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
                            $stmt = $this->conn->prepare($sql);
                            $stmt->bind_param("isi", $consSemiIds[$i], $slot, $qfMatches[$qfIdx]);
                            $stmt->execute();
                        }
                    }
                }

                // Create 5th Place Match (98)
                $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, 98, 'scheduled')";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ii", $this->tournamentId, $finalsRoundId);
                $stmt->execute();
                $fifthMatchId = $stmt->insert_id;

                // Wire winners from Consolation Semis to 5th place match
                for ($i = 0; $i < 2; $i++) {
                    $slot = (string) ($i + 1);
                    $sql = "UPDATE tournament_matches SET next_match_id = ?, next_match_slot = ? WHERE id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("isi", $fifthMatchId, $slot, $consSemiIds[$i]);
                    $stmt->execute();
                }

                // Create 7th Place Match (97) if requested
                if ($rankTo >= 7) {
                    $sql = "INSERT INTO tournament_matches (tournament_id, round_id, match_number, status) VALUES (?, ?, 97, 'scheduled')";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("ii", $this->tournamentId, $finalsRoundId);
                    $stmt->execute();
                    $seventhMatchId = $stmt->insert_id;

                    // Wire losers from Consolation Semis to 7th place match
                    for ($i = 0; $i < 2; $i++) {
                        $slot = (string) ($i + 1);
                        $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
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

                for ($i = 0; $i < 2; $i++) {
                    $slot = (string) ($i + 1);
                    $sql = "UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_match_slot = ? WHERE id = ?";
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param("isi", $ninthMatchId, $slot, $r1Matches[$i]);
                    $stmt->execute();
                }
            }
        }

        // Fill Round 1 with players
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
                $sql = "UPDATE tournament_matches SET player1_id = ?, status = 'completed', winner_id = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ssi", $p1, $p1, $matchId);
                $stmt->execute();
                $this->propagateWinner($matchId, $p1);
            } else if ($p2) {
                $sql = "UPDATE tournament_matches SET player2_id = ?, status = 'completed', winner_id = ? WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("ssi", $p2, $p2, $matchId);
                $stmt->execute();
                $this->propagateWinner($matchId, $p2);
            } else {
                $sql = "UPDATE tournament_matches SET status = 'completed' WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $matchId);
                $stmt->execute();
                $this->propagateWinner($matchId, null);
            }
        }
    }

    public function propagateWinner($matchId, $winnerId, $loserId = null)
    {
        $sql = "SELECT next_match_id, next_match_slot, loser_next_match_id, loser_next_match_slot 
                FROM tournament_matches WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $matchId);
        $stmt->execute();
        $match = $stmt->get_result()->fetch_assoc();

        if (!$match)
            return;

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

            $this->checkAndHandleBye($nextMatchId, $slot, $matchId);
        }

        // 2. Existing loser wiring
        $loserHandled = false;
        if ($loserId && $match['loser_next_match_id']) {
            $loserHandled = $this->routeLoserDirectly($match, $loserId, $matchId);
        }

        // 3. Placement routing
        if ($loserId && !$loserHandled) {
            $this->routeLoserToPlacement($matchId, $loserId);
        }
    }

    private function routeLoserDirectly(array $match, $loserId, $sourceMatchId)
    {
        $lnmId = $match['loser_next_match_id'];
        $slot = $match['loser_next_match_slot'];
        if (!$lnmId || !$slot)
            return false;

        $column = ($slot == '1') ? 'player1_id' : 'player2_id';
        $sql = "UPDATE tournament_matches SET $column = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $loserId, $lnmId);
        $stmt->execute();

        $this->checkAndHandleBye($lnmId, $slot, $sourceMatchId);
        return true;
    }

    private function routeLoserToPlacement($sourceMatchId, $loserId)
    {
        $rankTo = $this->getTournamentRankTo();
        if ($rankTo < 3)
            return;

        $meta = $this->getMatchMeta($sourceMatchId);
        // Stage check might need adjustment if using this engine for non-stage-2 (though it's mostly for Single Elim which sets stage=1)
        // Actually this logic explicitly checks for stage 2 in the original code. 
        // For Single Elim Tournament, everything is Stage 1. 
        // BUT strict single elim usually doesn't have complex placement matches unless requested.
        // The original code had: `if (!$meta || (int)$meta['stage'] !== 2) return;`
        // This implies placement matches ONLY happen in Stage 2 (Top Cut).
        // If Single Elim tournament logic needs placement matches, we should allow Stage 1.

        // Let's modify this to be generic for Single Elimination Engine.
        // If it's a Single Elim tournament, round_number is what matters relative to max_round.

        $stage = (int) $meta['stage'];
        $maxRound = $this->getMaxRoundNumber($stage);
        $roundNumber = (int) $meta['round_number'];
        $matchNumber = (int) $meta['match_number'];

        // Semifinal losers -> 3rd place match (Match 99)
        if ($rankTo >= 3 && $roundNumber === $maxRound - 1) {
            $thirdMatchId = $this->getPlacementMatchId(99);
            if ($thirdMatchId) {
                $slot = ($matchNumber % 2 === 1) ? '1' : '2';
                $this->assignPlacementSlot($thirdMatchId, $slot, $loserId, $sourceMatchId);
            }
            return;
        }

        // Quarterfinal losers -> Consolation Semis (95 & 94)
        if ($rankTo >= 5 && $roundNumber === $maxRound - 2) {
            $consSemiA = $this->getPlacementMatchId(95);
            $consSemiB = $this->getPlacementMatchId(94);
            if ($consSemiA && $consSemiB) {
                if ($matchNumber === 1 || $matchNumber === 2) {
                    $slot = ($matchNumber === 1) ? '1' : '2';
                    $this->assignPlacementSlot($consSemiA, $slot, $loserId, $sourceMatchId);
                    return;
                }
                if ($matchNumber === 3 || $matchNumber === 4) {
                    $slot = ($matchNumber === 3) ? '1' : '2';
                    $this->assignPlacementSlot($consSemiB, $slot, $loserId, $sourceMatchId);
                    return;
                }
            }
        }

        // Losers from consolation finals (95/94) -> 7th place match (97)
        if ($rankTo >= 7 && $roundNumber === $maxRound && ($matchNumber === 95 || $matchNumber === 94)) {
            $seventhMatch = $this->getPlacementMatchId(97);
            if ($seventhMatch) {
                $slot = ($matchNumber === 95) ? '1' : '2';
                $this->assignPlacementSlot($seventhMatch, $slot, $loserId, $sourceMatchId);
            }
        }
    }

    private function assignPlacementSlot($matchId, $slot, $playerId, $sourceMatchId)
    {
        if (!$matchId || !$slot)
            return;
        $column = ($slot === '1') ? 'player1_id' : 'player2_id';
        $sql = "UPDATE tournament_matches SET $column = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $playerId, $matchId);
        $stmt->execute();

        $this->checkAndHandleBye($matchId, $slot, $sourceMatchId);
    }

    private function getMatchMeta($matchId)
    {
        $sql = "SELECT tm.match_number, tr.round_number, tr.stage
                FROM tournament_matches tm
                JOIN tournament_rounds tr ON tm.round_id = tr.id
                WHERE tm.id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $matchId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    private function getMaxRoundNumber($stage)
    {
        $sql = "SELECT MAX(round_number) AS max_round FROM tournament_rounds WHERE tournament_id = ? AND stage = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->tournamentId, $stage);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['max_round'] ?? 0);
    }

    private function getPlacementMatchId($matchNumber)
    {
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

    private function getTournamentRankTo()
    {
        if ($this->rankToCache !== null) {
            return $this->rankToCache;
        }

        $sql = "SELECT rank_to FROM tournaments WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tournamentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $this->rankToCache = isset($row['rank_to']) ? (int) $row['rank_to'] : 0;
        return $this->rankToCache;
    }

    private function checkAndHandleBye($nextMatchId, $slot, $parentMatchId)
    {
        $otherSlot = ($slot == '1') ? '2' : '1';

        $sql = "SELECT player1_id, player2_id FROM tournament_matches WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $nextMatchId);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();

        $sql = "SELECT id, status, winner_id FROM tournament_matches 
                WHERE (next_match_id = ? AND next_match_slot = ?)
                OR (loser_next_match_id = ? AND loser_next_match_slot = ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isis", $nextMatchId, $otherSlot, $nextMatchId, $otherSlot);
        $stmt->execute();
        $otherParent = $stmt->get_result()->fetch_assoc();

        if ($otherParent && $otherParent['status'] === 'completed' && !$otherParent['winner_id']) {
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

                $this->propagateWinner($nextMatchId, $currentAdvancer, null);
            }
        }
    }
}

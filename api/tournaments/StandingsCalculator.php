<?php
require_once dirname(__DIR__) . '/config/database.php';

class StandingsCalculator
{
    private $conn;
    private $tournamentId;

    public function __construct($conn, $tournamentId)
    {
        $this->conn = $conn;
        $this->tournamentId = $tournamentId;
    }

    public function calculate()
    {
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
                       SUM(CASE 
                            WHEN LOWER(mf.finish_type) LIKE '%xtreme%' THEN 3
                            WHEN LOWER(mf.finish_type) LIKE '%burst%' THEN 2
                            WHEN LOWER(mf.finish_type) LIKE '%over%' THEN 2
                            ELSE 0 
                       END) as weighted_fqi
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
            $fStat = $finishStats[$pid] ?? ['total_bey_points' => 0, 'total_finishes' => 0, 'weighted_fqi' => 0];

            // Calculate FQI (Now a raw weighted sum)
            $fqi = (int) $fStat['weighted_fqi'];

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
                'bey_points' => (int) $fStat['total_bey_points'],
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
            $p1Score = (int) $m['player1_score'];
            $p2Score = (int) $m['player2_score'];

            if ($p1 && isset($stats[$p1])) {
                if ($p2)
                    $opponents[$p1][] = (string) $p2;
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
                if ($p1)
                    $opponents[$p2][] = (string) $p1;
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

        // Calculate OMPA and Opponent Win Rate
        foreach ($stats as $id => &$s) {
            $oppBeySum = 0;
            $oppCount = 0;
            $oppWins = 0;
            $oppTotalMatches = 0;

            foreach ($opponents[$id] as $oppId) {
                if (isset($stats[$oppId])) {
                    $oppBeySum += (int) $stats[$oppId]['bey_points'];
                    $oppCount++;
                    $s['buchholz'] += $stats[$oppId]['points'];

                    // Calculate opponent win rate
                    $oppWins += (int) $stats[$oppId]['points'];
                    $oppTotalMatches += (int) $stats[$oppId]['wins'] + (int) $stats[$oppId]['losses'];
                }
            }

            $s['ompa'] = ($oppCount > 0) ? round($oppBeySum / $oppCount, 2) : 0;

            // Calculate combined strength metric: (OMPA × 2) + (Opponent Win Rate × 5)
            $oppWinRate = ($oppTotalMatches > 0) ? $oppWins / $oppTotalMatches : 0;
            $s['strength_metric'] = round(($s['ompa'] * 2) + ($oppWinRate * 5), 2);
            $s['point_diff'] = (int) $s['pf'] - (int) $s['pa'];
        }

        // New Sorting Logic: 
        // 1. Points (Wins)
        // 2. Bey Points (Skill)
        // 3. FQI (Aggressiveness)
        // 4. Points Differential (PF - PA)
        // 5. Buchholz (strength of schedule - hidden)
        // 6. Seed (fallback)
        $standings = array_values($stats);
        usort($standings, function ($a, $b) {
            // 1. Match Points
            if ($b['points'] != $a['points'])
                return $b['points'] <=> $a['points'];

            // 2. Bey Points
            if ($b['bey_points'] != $a['bey_points'])
                return $b['bey_points'] <=> $a['bey_points'];

            // 3. FQI
            if ($b['fqi'] != $a['fqi'])
                return $b['fqi'] <=> $a['fqi'];

            // 4. Points Differential
            $aDiff = $a['point_diff'] ?? 0;
            $bDiff = $b['point_diff'] ?? 0;
            if ($bDiff != $aDiff)
                return $bDiff <=> $aDiff;

            // 5. Buchholz (higher is better)
            if (($b['buchholz'] ?? 0) != ($a['buchholz'] ?? 0))
                return ($b['buchholz'] ?? 0) <=> ($a['buchholz'] ?? 0);

            // 6. Fallback: Seed (Ascending)
            return ($a['seed'] ?? 999) <=> ($b['seed'] ?? 999);
        });

        return $standings;
    }
}

<?php
require_once 'api/config/database.php';
require_once 'api/tournaments/roles.php';

$database = new Database();
$conn = $database->getConnection();

// 1. Find a tournament and its creator
$res = $conn->query("SELECT id, created_by FROM tournaments LIMIT 1");
$tournament = $res->fetch_assoc();
$tid = $tournament['id'];
$creatorId = $tournament['created_by'];

echo "Testing role refinement for tournament $tid (Creator: $creatorId)\n";

// 2. FORCE organizer role for creator to start clean
$conn->query("DELETE FROM tournament_roles WHERE tournament_id = $tid AND user_id = '$creatorId'");
$conn->query("INSERT INTO tournament_roles (tournament_id, user_id, role) VALUES ($tid, '$creatorId', 'organizer')");

session_start();
$_SESSION['user_id'] = $creatorId;

$roles = new TournamentRoles();

// 3. Mock adding creator as both
$people = [
    [
        'user_id' => $creatorId,
        'role' => 'both',
        'display_name' => 'Creator'
    ]
];

$result = $roles->addPeopleWithRoles($tid, $people);
echo "Add result: " . json_encode($result) . "\n";

// 4. Verify updated role
$res = $conn->query("SELECT role FROM tournament_roles WHERE tournament_id = $tid AND user_id = '$creatorId'");
$updatedRole = $res->fetch_assoc()['role'];
echo "Updated role for creator: $updatedRole\n";

if (strpos($updatedRole, 'organizer') !== false && strpos($updatedRole, 'player') !== false && strpos($updatedRole, 'judge') !== false) {
    echo "SUCCESS: Creator has organizer, player, and judge roles.\n";
} else {
    echo "FAILURE: Role merge failed. Roles: $updatedRole\n";
}
?>

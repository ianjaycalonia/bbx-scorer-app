<?php
require_once 'api/config/database.php';
require_once 'api/tournaments/roles.php';

$database = new Database();
$conn = $database->getConnection();

// 1. Find a tournament
$res = $conn->query("SELECT id, created_by FROM tournaments LIMIT 1");
$tournament = $res->fetch_assoc();
$tid = $tournament['id'];
$creatorId = $tournament['created_by'];

session_start();
$_SESSION['user_id'] = $creatorId;

echo "Testing role refinement for tournament $tid (Creator: $creatorId)\n";

$roles = new TournamentRoles();

// 2. Check if creator is in available users
$available = $roles->getAvailableUsers($tid);
$found = false;
foreach ($available['users'] as $user) {
    if ($user['id'] === $creatorId) {
        $found = true;
        break;
    }
}

if ($found) {
    echo "SUCCESS: Creator is available to be added as participant.\n";
} else {
    echo "FAILURE: Creator NOT found in available users.\n";
    // Check their current role
    $res = $conn->query("SELECT role FROM tournament_roles WHERE tournament_id = $tid AND user_id = '$creatorId'");
    $role = $res->fetch_assoc()['role'];
    echo "Current role for creator: $role\n";
}

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

if ($updatedRole === 'both') {
    echo "SUCCESS: Creator's participation role successfully updated.\n";
} else {
    echo "FAILURE: Creator's role did not update to 'both'.\n";
}
?>

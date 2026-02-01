<?php
// Disable error reporting to prevent warnings in JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any stray output
ob_start();

try {
    require_once '../config/database.php';
    require_once '../config/cors.php';

    class UserProfile
    {
        private $conn;

        public function __construct()
        {
            try {
                $database = new Database();
                $this->conn = $database->getConnection();
                if (!$this->conn) {
                    throw new Exception('Database connection failed');
                }
            } catch (Exception $e) {
                throw new Exception('Failed to initialize database connection: ' . $e->getMessage());
            }
        }

        public function getProfile($userId)
        {
            try {
                if (empty($userId)) {
                    throw new Exception('User ID is empty');
                }

                $query = "SELECT u.id, u.email, u.blader_name, u.display_name, u.avatar_url, u.team_id,
                                  t.name as team_name,
                                  up.location, up.preferred_beyblade_type, up.bio, 
                                  up.email_notifications, up.public_profile, up.show_tournament_results
                          FROM users u 
                          LEFT JOIN teams t ON u.team_id = t.id
                          LEFT JOIN user_profiles up ON u.id = up.user_id 
                          WHERE u.id = ?";

                $stmt = $this->conn->prepare($query);
                if (!$stmt) {
                    throw new Exception('Failed to prepare query: ' . $this->conn->error);
                }

                $stmt->bind_param("s", $userId);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute query: ' . $stmt->error);
                }

                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    return array("success" => true, "profile" => $result->fetch_assoc());
                } else {
                    return array("success" => false, "message" => "Profile not found for user ID: " . $userId);
                }
            } catch (Exception $e) {
                error_log('Error in getProfile: ' . $e->getMessage());
                return array("success" => false, "message" => "Error retrieving profile: " . $e->getMessage());
            }
        }

        public function updateProfile($userId, $data)
        {
            try {
                error_log("DEBUG: updateProfile called for user: " . $userId);
                error_log("DEBUG: Received data: " . json_encode($data));

                // Handle team assignment
                $teamId = null;
                if (isset($data->team_name) && !empty($data->team_name)) {
                    // Check if team exists
                    $checkTeamSql = "SELECT id FROM teams WHERE LOWER(name) = LOWER(?)";
                    $checkTeamStmt = $this->conn->prepare($checkTeamSql);
                    if ($checkTeamStmt) {
                        $checkTeamStmt->bind_param("s", $data->team_name);
                        $checkTeamStmt->execute();
                        $teamResult = $checkTeamStmt->get_result();

                        if ($teamResult->num_rows > 0) {
                            // Team exists, use existing team_id
                            $teamRow = $teamResult->fetch_assoc();
                            $teamId = $teamRow['id'];
                        } else {
                            // Create new team
                            $createTeamSql = "INSERT INTO teams (name) VALUES (?)";
                            $createTeamStmt = $this->conn->prepare($createTeamSql);
                            if ($createTeamStmt) {
                                $createTeamStmt->bind_param("s", $data->team_name);
                                if ($createTeamStmt->execute()) {
                                    $teamId = $this->conn->insert_id;
                                }
                            }
                        }
                    }
                }

                // Update users table
                $userQuery = "UPDATE users SET blader_name = ?, display_name = ?, avatar_url = ?, team_id = ? WHERE id = ?";
                $userStmt = $this->conn->prepare($userQuery);
                if (!$userStmt) {
                    throw new Exception('Failed to prepare user update query');
                }

                $userStmt->bind_param(
                    "sssis",
                    $data->blader_name,
                    $data->display_name,
                    $data->avatar_url,
                    $teamId,
                    $userId
                );

                $userResult = $userStmt->execute();
                error_log("DEBUG: Users table update result: " . ($userResult ? "SUCCESS" : "FAILED"));
                error_log("DEBUG: Users table affected rows: " . $this->conn->affected_rows);
                error_log("DEBUG: Team ID assigned: " . ($teamId ? $teamId : "NULL"));

                // Update user_profiles table
                $profileQuery = "UPDATE user_profiles SET location = ?, preferred_beyblade_type = ?, bio = ?, 
                                 email_notifications = ?, public_profile = ?, show_tournament_results = ? 
                                 WHERE user_id = ?";

                $profileStmt = $this->conn->prepare($profileQuery);
                if (!$profileStmt) {
                    throw new Exception('Failed to prepare profile update query');
                }

                $profileStmt->bind_param(
                    "sssiiis",
                    $data->location,
                    $data->preferred_beyblade_type,
                    $data->bio,
                    $data->email_notifications,
                    $data->public_profile,
                    $data->show_tournament_results,
                    $userId
                );

                $profileResult = $profileStmt->execute();
                error_log("DEBUG: User_profiles table update result: " . ($profileResult ? "SUCCESS" : "FAILED"));
                error_log("DEBUG: User_profiles table affected rows: " . $this->conn->affected_rows);

                // Check if profile row exists for this user
                $checkQuery = "SELECT COUNT(*) as count FROM user_profiles WHERE user_id = ?";
                $checkStmt = $this->conn->prepare($checkQuery);
                if ($checkStmt) {
                    $checkStmt->bind_param("s", $userId);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $countRow = $checkResult->fetch_assoc();
                    error_log("DEBUG: User profile exists for user: " . $userId . " - Count: " . $countRow['count']);
                }

                if ($userResult && $profileResult) {
                    return array("success" => true, "message" => "Profile updated successfully", "team_id" => $teamId);
                } else {
                    return array("success" => false, "message" => "Failed to update profile");
                }
            } catch (Exception $e) {
                error_log('Error in updateProfile: ' . $e->getMessage());
                return array("success" => false, "message" => "Error updating profile");
            }
        }

        public function createProfile($userId, $data)
        {
            try {
                $query = "INSERT INTO user_profiles (user_id, location, preferred_beyblade_type, bio, 
                                                 email_notifications, public_profile, show_tournament_results) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";

                $stmt = $this->conn->prepare($query);
                if (!$stmt) {
                    throw new Exception('Failed to prepare profile creation query');
                }

                $stmt->bind_param(
                    "ssssiii",
                    $userId,
                    $data->location,
                    $data->preferred_beyblade_type,
                    $data->bio,
                    $data->email_notifications,
                    $data->public_profile,
                    $data->show_tournament_results
                );

                if ($stmt->execute()) {
                    return array("success" => true, "message" => "Profile created successfully");
                } else {
                    return array("success" => false, "message" => "Profile creation failed");
                }
            } catch (Exception $e) {
                error_log('Error in createProfile: ' . $e->getMessage());
                return array("success" => false, "message" => "Error creating profile");
            }
        }
    }

    // Handle request
    session_start();

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(array("success" => false, "message" => "Not authenticated"));
        exit();
    }

    $userId = $_SESSION['user_id'];

    try {
        $userProfile = new UserProfile();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array("success" => false, "message" => "Failed to initialize profile service"));
        exit();
    }

    $response = null;

    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
        $response = $userProfile->getProfile($userId);
    } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        if (!$data) {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Invalid request data"));
            exit();
        }
        $response = $userProfile->updateProfile($userId, $data);
    } elseif ($_SERVER['REQUEST_METHOD'] == 'PUT') {
        $data = json_decode(file_get_contents("php://input"));
        if (!$data) {
            http_response_code(400);
            echo json_encode(array("success" => false, "message" => "Invalid request data"));
            exit();
        }
        $response = $userProfile->createProfile($userId, $data);
    } else {
        http_response_code(405);
        $response = array("success" => false, "message" => "Invalid request method");
    }

    // Clear any output buffer to ensure clean JSON
    ob_end_clean();

    // Set proper content type
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode($response);

} catch (Exception $e) {
    // Catch any unhandled exceptions
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array("success" => false, "message" => "Server error: " . $e->getMessage()));
}
?>
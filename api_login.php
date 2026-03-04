<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include database authentication functions
require_once 'db_auth.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    // Validate input
    if (empty($username) || empty($password)) {
        echo json_encode([
            "status" => "error",
            "message" => "Username and password are required."
        ]);
        exit;
    }

    try {
        // Check if user exists and password is correct
        $stmt = $conn->prepare("SELECT UID, Username, PASSWORD FROM tblsysuser WHERE Username = :username AND isdeleted = 0");
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['PASSWORD'])) {
            // Generate token
            $token = generateSessionToken();
            $expiresAt = date('Y-m-d H:i:s', time() + ACCESS_TOKEN_EXPIRY);

            // Store token directly in tblsysuser table
            try {
                $updateStmt = $conn->prepare("
                    UPDATE tblsysuser
                    SET token = ?, token_expires_at = ?, last_login = NOW()
                    WHERE UID = ?
                ");
                $updateStmt->execute([$token, $expiresAt, $user['UID']]);

                $userData = [
                    'uid' => $user['UID'],
                    'username' => $user['Username']
                ];

                echo json_encode([
                    "status" => "success",
                    "message" => "Login successful",
                    "token" => $token,
                    "expires_in" => ACCESS_TOKEN_EXPIRY,
                    "user" => $userData
                ]);
            } catch (Exception $e) {
                error_log("Error storing token in user table: " . $e->getMessage());
                echo json_encode([
                    "status" => "error",
                    "message" => "Error storing authentication token."
                ]);
            }
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Invalid username or password."
            ]);
        }
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => "Database error occurred."
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method."
    ]);
}
?>
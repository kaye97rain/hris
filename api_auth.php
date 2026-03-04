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
    $rawInput = file_get_contents("php://input");
    error_log("API AUTH: Raw input received: '" . $rawInput . "'");

    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("API AUTH: JSON decode error: " . json_last_error_msg());
        echo json_encode([
            "status" => "error",
            "message" => "Invalid JSON format."
        ]);         
        exit;
    }

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    error_log("API AUTH: Parsed username: '" . $username . "', password provided: " . (!empty($password) ? 'YES' : 'NO'));

    // Validate input
    if (empty($username) || empty($password)) {
        error_log("API AUTH: Missing username or password. Username: '" . $username . "', Password provided: " . (!empty($password) ? 'YES' : 'NO'));
        echo json_encode([
            "status" => "error",
            "message" => "Username and password are required."
        ]);
        exit;
    }

    // Database authentication required
    if (!$conn) {
        echo json_encode([
            "status" => "error",
            "message" => "Database connection not available."
        ]);
        exit;
    }

    try {
        // Get user from database
        error_log("=== API AUTH DEBUG ===");
        error_log("Attempting login for username: " . $username);
        error_log("Password provided: " . (empty($password) ? 'EMPTY' : 'PROVIDED'));

        $stmt = $conn->prepare("SELECT UID as ID, Username, Password FROM tblsysuser WHERE Username = :username");
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        error_log("Database query executed");
        error_log("User found: " . ($user ? 'YES' : 'NO'));
        if ($user) {
            error_log("User ID: " . $user['ID']);
            error_log("Username: " . $user['Username']);
            error_log("Password hash length: " . strlen($user['Password']));
        }

        $passwordValid = false;
        if ($user) {
            // Debug: Log password verification
            error_log("User found: " . $user['Username']);
            error_log("Stored hash length: " . strlen($user['Password']));
            error_log("Stored hash starts with: " . substr($user['Password'], 0, 4));
            error_log("Input password: " . $password);
            error_log("Password verify result: " . (password_verify($password, $user['Password']) ? 'true' : 'false'));

            // Try password_verify first (for bcrypt hashed passwords)
            $passwordValid = password_verify($password, $user['Password']);

            // If password_verify fails, try MD5 comparison (for existing MD5 passwords)
            if (!$passwordValid && md5($password) === $user['Password']) {
                $passwordValid = true;
                error_log("Using MD5 password comparison for user: " . $user['Username']);
            }

            // If MD5 fails, try plain text comparison (for existing plain text passwords)
            if (!$passwordValid && $user['Password'] === $password) {
                $passwordValid = true;
                error_log("Using plain text password comparison for user: " . $user['Username']);
            }
        }
    } catch (Exception $e) {
        error_log("Database login error: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => "Database error occurred."
        ]);
        exit;
    }

    if ($passwordValid && $user) {
        error_log("=== AUTH SUCCESS ===");
        error_log("Password verification successful for user: " . $user['Username']);

        // Use the new secure token rotation system
        $tokens = storeUserSession($user['ID'], $user['Username']);

        if (!$tokens) {
            error_log("Error storing tokens in secure database");
            echo json_encode([
                "status" => "error",
                "message" => "Error creating secure authentication session."
            ]);
            exit;
        }

        // Prepare user data for response
        $userData = [
            'id' => $user['ID'],
            'username' => $user['Username'],
            'login_time' => time()
        ];

        error_log("Secure login successful for user: " . $userData['username'] . ", token: " . substr($tokens['access_token'], 0, 10) . "...");
        error_log("Tokens stored in secure database for user ID: " . $user['ID']);

        echo json_encode([
            "status" => "success",
            "message" => "Login successful.",
            "token" => $tokens['access_token'],
            "refresh_token" => $tokens['refresh_token'],
            "expires_in" => $tokens['expires_in'],
            "user" => $userData
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid username or password."
        ]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if user is authenticated via token
    $user = validateToken();

    if ($user) {
        echo json_encode([
            "status" => "success",
            "message" => "User authenticated.",
            "user" => $user
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Not authenticated."
        ]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'refresh') {
    // Handle token refresh
    $data = json_decode(file_get_contents("php://input"), true);
    $refreshToken = $data['refresh_token'] ?? '';

    if (empty($refreshToken)) {
        echo json_encode([
            "status" => "error",
            "message" => "Refresh token is required."
        ]);
        exit;
    }

    $newTokens = refreshAccessToken($refreshToken);

    if ($newTokens) {
        echo json_encode([
            "status" => "success",
            "message" => "Token refreshed successfully.",
            "token" => $newTokens['access_token'],
            "expires_in" => $newTokens['expires_in']
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid or expired refresh token."
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method."
    ]);
}
?>
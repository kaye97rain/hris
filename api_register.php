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

require_once "./config.php";

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

    if (strlen($username) < 3) {
        echo json_encode([
            "status" => "error",
            "message" => "Username must be at least 3 characters long."
        ]);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode([
            "status" => "error",
            "message" => "Password must be at least 6 characters long."
        ]);
        exit;
    }

    try {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT UID FROM tblsysuser WHERE Username = :username");
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                "status" => "error",
                "message" => "Username already exists. Please choose a different username."
            ]);
            exit;
        }

        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user
        $stmt = $conn->prepare("INSERT INTO tblsysuser (Username, PASSWORD) VALUES (:username, :password)");
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":password", $hashedPassword);

        if ($stmt->execute()) {
            echo json_encode([
                "status" => "success",
                "message" => "Registration successful! You can now log in."
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Registration failed. Please try again."
            ]);
        }
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
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

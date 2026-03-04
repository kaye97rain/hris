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

    $token = $data['token'] ?? '';

    if (empty($token)) {
        // Try to get token from Authorization header
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                $token = $matches[1];
            }
        }
    }

    if (empty($token)) {
        echo json_encode([
            "status" => "error",
            "message" => "Token is required."
        ]);
        exit;
    }

    try {
        $userData = validateToken();

        if ($userData) {
            echo json_encode([
                "status" => "success",
                "message" => "Token is valid",
                "user" => $userData
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Invalid or expired token."
            ]);
        }
    } catch (Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => "Token validation failed."
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method."
    ]);
}
?>
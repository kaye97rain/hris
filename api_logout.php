<?php
// API Logout Endpoint
// Handles token revocation via HTTP request

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Include database authentication functions
require_once 'db_auth.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? null;

if (!$token) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Token required']);
    exit;
}

try {
    // Delete user session (revoke token)
    $result = deleteUserSession($token);

    if ($result) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
    }
} catch (Exception $e) {
    error_log("Logout API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
}
?>
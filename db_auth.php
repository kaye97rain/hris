<?php
// Secure Database-Based Token Authentication System with Hashed Tokens
// Implements atomic token rotation with proper database tables

// Load environment variables from API/.env (function already defined in config.php)
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            throw new Exception("Environment file not found: $path");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

loadEnv(__DIR__ . '/.env');

// Database connection using API/.env (no fallbacks - must be in .env)
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASS');

if (!$host || !$dbname || !$username) {
    die("Database configuration missing from .env file");
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Force UTC timezone for consistent token expiration handling
    $conn->exec("SET time_zone = '+00:00'");
    error_log("Database connection successful - timezone set to UTC");
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $conn = null; // Set to null instead of dying
}

// Token configuration
define('ACCESS_TOKEN_EXPIRY', 3600); // 1 hour
define('REFRESH_TOKEN_EXPIRY', 2592000); // 30 days
define('TOKEN_LENGTH', 64);
define('HASH_ALGO', PASSWORD_DEFAULT); // Secure hashing algorithm (bcrypt)

/**
 * Generate a cryptographically secure random token
 */
function generateSecureToken($length = TOKEN_LENGTH) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Hash a token for secure storage
 */
function hashToken($token) {
    return password_hash($token, HASH_ALGO);
}

/**
 * Verify a token against its hash
 */
function verifyTokenHash($token, $hash) {
    return password_verify($token, $hash);
}

/**
 * Generate session token (access token)
 */
function generateSessionToken() {
    return generateSecureToken();
}

/**
 * Generate refresh token
 */
function generateRefreshToken() {
    return generateSecureToken();
}

/**
 * Atomic token rotation: Store new access token and invalidate old ones
 * Uses database transactions for atomicity
 */
function storeUserSession($userId, $username) {
    global $conn;

    try {
        $conn->beginTransaction();

        // Generate new tokens
        $accessToken = generateSessionToken();
        $refreshToken = generateRefreshToken();
        $accessTokenHash = hashToken($accessToken);
        $refreshTokenHash = hashToken($refreshToken);

        $accessExpiresAt = date('Y-m-d H:i:s', time() + ACCESS_TOKEN_EXPIRY);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + REFRESH_TOKEN_EXPIRY);

        // Get client info
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Revoke all existing active tokens for this user (token rotation)
        $revokeStmt = $conn->prepare("
            UPDATE access_tokens
            SET is_active = FALSE
            WHERE user_id = ? AND is_active = TRUE
        ");
        $revokeStmt->execute([$userId]);

        // Mark existing refresh tokens as revoked
        $revokeRefreshStmt = $conn->prepare("
            UPDATE refresh_tokens
            SET is_revoked = TRUE
            WHERE user_id = ? AND is_revoked = FALSE
        ");
        $revokeRefreshStmt->execute([$userId]);

        // Insert new access token
        $accessStmt = $conn->prepare("
            INSERT INTO access_tokens
            (token, user_id, username, expires_at, ip_address, user_agent, is_active)
            VALUES (?, ?, ?, ?, ?, ?, TRUE)
        ");
        $accessStmt->execute([
            $accessTokenHash,
            $userId,
            $username,
            $accessExpiresAt,
            $ipAddress,
            $userAgent
        ]);

        $accessTokenId = $conn->lastInsertId();

        // Insert new refresh token
        $refreshStmt = $conn->prepare("
            INSERT INTO refresh_tokens
            (refresh_token, user_id, username, access_token_id, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $refreshStmt->execute([
            $refreshTokenHash,
            $userId,
            $username,
            $accessTokenId,
            $refreshExpiresAt,
            $ipAddress,
            $userAgent
        ]);

        // Log token creation
        logTokenAction($accessTokenId, 'access', $userId, 'created', $ipAddress, $userAgent);

        $conn->commit();

        error_log("Secure token rotation completed for user: $username");

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => ACCESS_TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error in atomic token rotation: " . $e->getMessage());
        return false;
    }
}

/**
 * Validate access token using hashed comparison
 */
function validateToken() {
    global $conn;

    error_log("=== VALIDATE TOKEN DEBUG ===");
    error_log("Request URI: " . $_SERVER['REQUEST_URI']);
    error_log("Request method: " . $_SERVER['REQUEST_METHOD']);

    // Get token from multiple sources
    $token = null;

    // First priority: Authorization header
    $headers = getallheaders();
    error_log("Headers: " . json_encode($headers));
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $token = $matches[1];
            error_log("validateToken: Found token in Authorization header");
        }
    }

    // Second priority: Cookie
    if (!$token) {
        $token = $_COOKIE['auth_token'] ?? null;
        if ($token) {
            error_log("validateToken: Found token in cookie");
        } else {
            error_log("validateToken: No token in cookie");
        }
    }

    // Third priority: POST data (for AJAX requests)
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['auth_token'] ?? null;
        if ($token) {
            error_log("validateToken: Found token in POST data");
        }
    }

    // Fourth priority: GET data (for direct links)
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = $_GET['auth_token'] ?? null;
        if ($token) {
            error_log("validateToken: Found token in GET data: " . substr($token, 0, 10) . "...");
        } else {
            error_log("validateToken: No token in GET data");
        }
    }

    if (!$token) {
        error_log("validateToken: No token found in any source");
        return false;
    }

    error_log("validateToken: Using token: " . substr($token, 0, 10) . "...");

    try {
        // Find active access token by hash verification
        $stmt = $conn->prepare("
            SELECT id, user_id, username, token, expires_at, last_accessed
            FROM access_tokens
            WHERE expires_at > NOW() AND is_active = TRUE
        ");
        $stmt->execute();
        $tokens = $stmt->fetchAll();

        foreach ($tokens as $tokenData) {
            if (verifyTokenHash($token, $tokenData['token'])) {
                // Token verified, update last accessed
                $updateStmt = $conn->prepare("
                    UPDATE access_tokens
                    SET last_accessed = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$tokenData['id']]);

                // Log validation
                logTokenAction($tokenData['id'], 'access', $tokenData['user_id'], 'validated');

                return [
                    'id' => $tokenData['user_id'],
                    'username' => $tokenData['username'],
                    'token' => $token,
                    'expires_at' => $tokenData['expires_at']
                ];
            }
        }

        error_log("validateToken: Token verification failed");
        return false;

    } catch (Exception $e) {
        error_log("Error validating token: " . $e->getMessage());
        return false;
    }
}

/**
 * Refresh access token using refresh token (atomic operation)
 */
function refreshAccessToken($refreshToken) {
    global $conn;

    try {
        $conn->beginTransaction();

        // Find valid refresh token
        $stmt = $conn->prepare("
            SELECT rt.id, rt.user_id, rt.username, rt.access_token_id, at.token as access_token_hash
            FROM refresh_tokens rt
            LEFT JOIN access_tokens at ON rt.access_token_id = at.id
            WHERE rt.expires_at > NOW() AND rt.is_used = FALSE AND rt.is_revoked = FALSE
        ");
        $stmt->execute();
        $refreshTokens = $stmt->fetchAll();

        foreach ($refreshTokens as $rt) {
            if (verifyTokenHash($refreshToken, $rt['refresh_token'])) {
                // Mark refresh token as used
                $updateRefreshStmt = $conn->prepare("
                    UPDATE refresh_tokens
                    SET is_used = TRUE
                    WHERE id = ?
                ");
                $updateRefreshStmt->execute([$rt['id']]);

                // Mark old access token as inactive
                $updateAccessStmt = $conn->prepare("
                    UPDATE access_tokens
                    SET is_active = FALSE
                    WHERE id = ?
                ");
                $updateAccessStmt->execute([$rt['access_token_id']]);

                // Generate new token pair
                $newTokens = storeUserSession($rt['user_id'], $rt['username']);

                if ($newTokens) {
                    $conn->commit();
                    logTokenAction($rt['access_token_id'], 'refresh', $rt['user_id'], 'refreshed');
                    return $newTokens;
                } else {
                    $conn->rollBack();
                    return false;
                }
            }
        }

        $conn->rollBack();
        error_log("Invalid or expired refresh token");
        return false;

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error refreshing token: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete user session (logout) - revoke all tokens atomically
 */
function deleteUserSession($token) {
    global $conn;

    try {
        $conn->beginTransaction();

        // Find the token to revoke
        $stmt = $conn->prepare("
            SELECT id, user_id FROM access_tokens
            WHERE expires_at > NOW() AND is_active = TRUE
        ");
        $stmt->execute();
        $tokens = $stmt->fetchAll();

        foreach ($tokens as $tokenData) {
            if (verifyTokenHash($token, $tokenData['token'])) {
                // Revoke access token
                $revokeAccessStmt = $conn->prepare("
                    UPDATE access_tokens
                    SET is_active = FALSE
                    WHERE id = ?
                ");
                $revokeAccessStmt->execute([$tokenData['id']]);

                // Revoke associated refresh tokens
                $revokeRefreshStmt = $conn->prepare("
                    UPDATE refresh_tokens
                    SET is_revoked = TRUE
                    WHERE access_token_id = ?
                ");
                $revokeRefreshStmt->execute([$tokenData['id']]);

                // Log revocation
                logTokenAction($tokenData['id'], 'access', $tokenData['user_id'], 'revoked');

                $conn->commit();
                error_log("User session revoked for user ID: " . $tokenData['user_id']);
                return true;
            }
        }

        $conn->rollBack();
        error_log("Token not found for revocation");
        return false;

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error deleting user session: " . $e->getMessage());
        return false;
    }
}

/**
 * Log token actions for auditing
 */
function logTokenAction($tokenId, $tokenType, $userId, $action, $ipAddress = null, $userAgent = null) {
    global $conn;

    try {
        if (!$ipAddress) $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$userAgent) $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO token_logs
            (token_id, token_type, user_id, action, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tokenId, $tokenType, $userId, $action, $ipAddress, $userAgent]);
    } catch (Exception $e) {
        error_log("Error logging token action: " . $e->getMessage());
    }
}

/**
 * Require authentication - returns user data or exits with error
 */
function requireAuth() {
    $user = validateToken();

    if (!$user) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        exit;
    }

    return $user;
}

/**
 * Clean up expired tokens (should be called by cron job)
 */
function cleanupExpiredTokens() {
    global $conn;

    try {
        $conn->beginTransaction();

        // Mark expired access tokens as inactive
        $conn->exec("
            UPDATE access_tokens
            SET is_active = FALSE
            WHERE expires_at < NOW() AND is_active = TRUE
        ");

        // Mark expired refresh tokens as revoked
        $conn->exec("
            UPDATE refresh_tokens
            SET is_revoked = TRUE
            WHERE expires_at < NOW() AND is_revoked = FALSE
        ");

        // Move expired tokens to revoked table for audit trail
        $conn->exec("
            INSERT INTO revoked_tokens (token, token_type, user_id, reason)
            SELECT token, 'access', user_id, 'expired'
            FROM access_tokens
            WHERE expires_at < NOW() AND is_active = FALSE
        ");

        $conn->exec("
            INSERT INTO revoked_tokens (token, token_type, user_id, reason)
            SELECT refresh_token, 'refresh', user_id, 'expired'
            FROM refresh_tokens
            WHERE expires_at < NOW() AND is_revoked = FALSE
        ");

        // Delete old revoked tokens (keep last 30 days)
        $conn->exec("
            DELETE FROM revoked_tokens
            WHERE revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        $conn->commit();
        error_log("Cleaned up expired tokens");
        return true;

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error cleaning up expired tokens: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user tokens for management (admin function)
 */
function getUserTokens($userId) {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT
                'access' as type,
                id,
                username,
                created_at,
                expires_at,
                last_accessed,
                is_active,
                ip_address,
                user_agent
            FROM access_tokens
            WHERE user_id = ?

            UNION ALL

            SELECT
                'refresh' as type,
                id,
                username,
                created_at,
                expires_at,
                NULL as last_accessed,
                CASE WHEN is_revoked = FALSE AND is_used = FALSE THEN 1 ELSE 0 END as is_active,
                ip_address,
                user_agent
            FROM refresh_tokens
            WHERE user_id = ?

            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();

    } catch (Exception $e) {
        error_log("Error getting user tokens: " . $e->getMessage());
        return [];
    }
}
?>
<?php
declare(strict_types=1);

if (!function_exists('hrmis_apply_api_security')) {
    function hrmis_apply_api_security(): void
    {
        // db_auth.php is also included by server-rendered pages in /system.
        // API-only headers (especially default-src 'none') must not leak into
        // those HTML responses or the browser will block their CSS and images.
        $scriptFile = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $apiDirectory = realpath(__DIR__);
        if ($scriptFile === false || $apiDirectory === false) {
            return;
        }

        $apiPrefix = rtrim(str_replace('\\', '/', $apiDirectory), '/') . '/';
        $normalizedScript = str_replace('\\', '/', $scriptFile);
        if (strncmp($normalizedScript, $apiPrefix, strlen($apiPrefix)) !== 0) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $unsafe = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
            $hasSessionCookie = isset($_COOKIE['auth_token']) || isset($_COOKIE['pds_token']);
            if ($unsafe && $hasSessionCookie
                && in_array($fetchSite, ['cross-site', 'same-site'], true)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'message' => 'Cross-site request denied']);
                exit;
            }
            return;
        }

        $configured = trim((string)(getenv('CORS_ALLOWED_ORIGINS') ?: ''));
        $allowed = $configured === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $configured))));
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        $sameOrigin = $host === '' ? '' : $scheme . '://' . $host;

        if ($origin === $sameOrigin || in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-HRMIS-Background');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Vary: Origin');
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Cross-origin request denied']);
        exit;
    }
}

hrmis_apply_api_security();

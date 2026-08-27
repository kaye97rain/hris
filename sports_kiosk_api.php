<?php
// Mobile sports-event scanner API. Deliberately does NOT use validateToken()
// (HRMIS login) — scanning staff log in with a separate lightweight "CSC
// user" account (see tbl_sports_csc_users / the CSC Users tab in User
// Management), scoped only to this scanner. Every write is re-validated
// server-side: the login token is re-checked against the account's is_active
// flag on every call, and the event's is_active flag is re-checked in
// sports_scan_record() on every scan, so a revoked account or an event an
// admin has since flagged done both fail cleanly even mid-session.
// Deliberately does NOT require db_auth.php — nothing here calls into it
// directly, and this file is meant to work standalone on a separate public
// domain from the rest of HRIS. (dtr_search_employees(), used by
// manual_search below, still lazily pulls in db_auth.php/security.php on
// its own for one helper function; that's fine — API/.env is already loaded
// by then via sports_db.php's own require chain.)
require_once __DIR__ . '/sports_db.php';

sports_send_cors_headers();
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // No Content-Type here on purpose: a 204 has no body, and Safari/WebKit
    // (iOS — including "Chrome" on iOS, which is WebKit under the hood) is
    // known to fail CORS preflight when a body-implying header like
    // Content-Type is present on a contentless response — desktop Chrome
    // tolerates it, WebKit doesn't.
    http_response_code(204);
    exit;
}
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '');

// The frontend deliberately sends JSON bodies WITHOUT a Content-Type header
// (so the browser defaults to "text/plain", a CORS "simple" content-type)
// rather than 'application/json' — the latter forces a CORS preflight
// (OPTIONS) round-trip that iOS WebKit (Safari, and Chrome-on-iOS which is
// WebKit under the hood) has shown a real quirk with, while Android/desktop
// Chrome tolerate it fine. Skipping the preflight sidesteps that entirely.
// So: parse the raw body as JSON regardless of what Content-Type says.
$body = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $raw = file_get_contents('php://input');
    $decoded = $raw ? json_decode($raw, true) : null;
    $body = is_array($decoded) ? $decoded : $_POST;
}
function bp(string $key, $default = '') {
    global $body;
    return $body[$key] ?? $default;
}

function ok($data = []): never {
    echo json_encode(['status' => 'success'] + $data);
    exit;
}
function fail($msg, $code = 400): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

function csc_user_or_fail(PDO $conn): array {
    $token = trim((string)(bp('token', '') ?: ($_GET['token'] ?? '')));
    if ($token === '') fail('Please log in again.', 401);
    $scanner = sports_csc_user_from_token($conn, $token);
    if ($scanner === null) fail('You have been logged out — maybe your session expired, this account signed in on another device, or it was disabled. Please log in again.', 401);
    return $scanner;
}

function active_event_or_fail(PDO $conn, int $eventId): array {
    if ($eventId <= 0) fail('Event is required');
    $event = sports_event_by_id($conn, $eventId);
    if (!$event || !$event['is_active']) fail('This event has been marked done and is no longer accepting scans.', 403);
    return $event;
}

switch ($action) {
    case 'login':
        if ($method !== 'POST') fail('POST required');
        $username = trim((string)bp('username', ''));
        $password = (string)bp('password', '');
        if ($username === '' || $password === '') fail('Enter your username and password');
        $scanner = sports_csc_user_authenticate($leave_conn, $username, $password);
        if (!$scanner) fail('Invalid username or password, or this account has been disabled.', 401);
        ok(['token' => sports_csc_token($scanner['id'], $scanner['session_version']), 'user' => $scanner]);

    case 'me':
        ok(['user' => csc_user_or_fail($leave_conn)]);

    case 'events_list':
        csc_user_or_fail($leave_conn);
        ok(['data' => sports_event_list_active($leave_conn)]);

    case 'scan':
        if ($method !== 'POST') fail('POST required');
        $scanner = csc_user_or_fail($leave_conn);
        $event = active_event_or_fail($leave_conn, (int)bp('event_id', 0));
        $rawPayload = (string)bp('raw_payload', '');
        $manualPiid = trim((string)bp('piid', ''));
        try {
            $result = sports_scan_record($leave_conn, (int)$event['id'], $rawPayload, $manualPiid !== '' ? $manualPiid : null, $scanner);
            // Nested under 'scan': ok() merges this into ['status' => 'success', ...]
            // via array union, which keeps the LEFT ('success') on any key
            // collision — a top-level 'status' from $result would silently
            // discard the scan outcome (recorded/already_recorded/not_found).
            ok(['scan' => $result]);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }

    case 'manual_search':
        csc_user_or_fail($leave_conn);
        active_event_or_fail($leave_conn, (int)($_GET['event_id'] ?? 0));
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') fail('Enter a name or ID number to search');
        $result = dtr_search_employees($leave_conn, [
            'surname' => $q,
            'piid' => '',
            'page' => 1,
            'page_size' => 20,
        ]);
        $rows = array_map(static fn($row) => [
            'piid' => $row['PIID'],
            'id_num' => $row['ID_NUM'] ?? '',
            'name' => trim(($row['Firstname'] ?? '') . ' ' . ($row['MiddleName'] ?? '') . ' ' . ($row['Surname'] ?? '')),
        ], $result['rows']);
        ok(['data' => $rows]);

    default:
        fail('Unknown action', 404);
}

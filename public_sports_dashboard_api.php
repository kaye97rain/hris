<?php
// Read-only public sports-event attendance feed. Intentionally has no
// authentication or write actions — same pattern as public_dtr_api.php.
require_once __DIR__ . '/db_auth.php';
require_once __DIR__ . '/sports_db.php';

sports_send_cors_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$action = trim((string)($_GET['action'] ?? ''));

if ($action === 'events_list') {
    $stmt = $leave_conn->query(
        "SELECT id, sport_name FROM tbl_sports_events WHERE is_active = 1 ORDER BY created_at DESC, id DESC"
    );
    echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'feed') {
    $eventId = (int)($_GET['event_id'] ?? 0);
    $sinceId = (int)($_GET['since_id'] ?? 0);
    $rows = sports_dashboard_feed($leave_conn, $eventId > 0 ? $eventId : null, $sinceId);
    foreach ($rows as &$row) {
        $row['session_label'] = sports_session_label((string)$row['session']);
    }
    unset($row);
    echo json_encode(['status' => 'success', 'data' => $rows]);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);

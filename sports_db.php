<?php
/**
 * sports_db.php — CSC Sports Event mobile attendance.
 *
 * Attendance scanned at a sports event is written to the DTR through the
 * existing manual-override mechanism (dtr_override_save() in dtr_db.php),
 * not a parallel table. tbl_sports_events and tbl_sports_scan_log are only
 * the event registry and the scan audit trail; tbl_sports_scan_log.oid
 * links back to the tbldtr_override row a scan produced.
 *
 * Uses the same $leave_conn PDO connection as dtr_db.php/leave_db.php (the
 * `hrmis` database).
 */

require_once __DIR__ . '/dtr_db.php'; // pulls in leave_db.php, which loads API/.env itself

/**
 * sports_scan.php / public_sports_dashboard.php are meant to be deployable on
 * a separate public domain from this API, so their fetch() calls are
 * cross-origin. Handled self-contained here rather than via the app-wide
 * hrmis_apply_api_security() in security.php/db_auth.php — that mechanism
 * has an unrelated require-order bug (security.php can run its CORS check
 * before .env is loaded, depending on what already required env_loader.php
 * first) and touching db_auth.php has app-wide blast radius for a
 * sports-only need. Neither sports_kiosk_api.php nor
 * public_sports_dashboard_api.php requires db_auth.php at all.
 * Reuses the CORS_ALLOWED_ORIGINS env var (same one security.php reads) so
 * only one setting is needed regardless of which mechanism ends up handling
 * a given request.
 */
function sports_send_cors_headers(): void {
    $allowed = array_values(array_filter(array_map('trim', explode(',', (string)(getenv('CORS_ALLOWED_ORIGINS') ?: '')))));
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '' || !in_array($origin, $allowed, true)) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
}

function sports_ensure_schema(PDO $conn): void {
    static $ready = false;
    if ($ready) return;
    // event_date and kiosk_pin are legacy: attendance no longer follows a
    // fixed event date (it follows the real wall-clock date of each scan),
    // and there is no PIN gate any more (the scanner is a plain event
    // picker). Both columns are kept nullable for older installs but are
    // unused by the write path below.
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_sports_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sport_name VARCHAR(150) NOT NULL,
            event_date DATE NULL,
            kiosk_pin VARCHAR(12) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(50) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_sports_events_active (is_active, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $eventColumns = $conn->query('SHOW COLUMNS FROM tbl_sports_events')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($eventColumns as $column) {
        if ($column['Field'] === 'event_date' && strtoupper((string)$column['Null']) === 'NO') {
            $conn->exec('ALTER TABLE tbl_sports_events MODIFY COLUMN event_date DATE NULL');
        }
        if ($column['Field'] === 'kiosk_pin' && strtoupper((string)$column['Null']) === 'NO') {
            $conn->exec('ALTER TABLE tbl_sports_events MODIFY COLUMN kiosk_pin VARCHAR(12) NULL');
        }
    }

    // scan_date + session together track same-day coverage per employee: a
    // second scan on the opposite half of the SAME calendar day upgrades the
    // existing row (and its override) to 'whole' rather than adding a row.
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_sports_scan_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            piid VARCHAR(50) NOT NULL,
            employee_name VARCHAR(255) NOT NULL DEFAULT '',
            scan_date DATE NOT NULL,
            session ENUM('am','pm','whole') NOT NULL,
            oid BIGINT UNSIGNED NULL,
            raw_qr_payload VARCHAR(255) NULL,
            scanned_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sports_scan_dedup (event_id, piid, scan_date),
            KEY idx_sports_scan_event_time (event_id, scanned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $scanColumns = array_column($conn->query('SHOW COLUMNS FROM tbl_sports_scan_log')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('scan_date', $scanColumns, true)) {
        $conn->exec("ALTER TABLE tbl_sports_scan_log ADD COLUMN scan_date DATE NULL AFTER employee_name");
        $conn->exec('UPDATE tbl_sports_scan_log SET scan_date = DATE(scanned_at) WHERE scan_date IS NULL');
        $conn->exec('ALTER TABLE tbl_sports_scan_log MODIFY COLUMN scan_date DATE NOT NULL');
        $indexes = $conn->query("SHOW INDEX FROM tbl_sports_scan_log WHERE Key_name = 'uq_sports_scan_dedup'")->fetchAll(PDO::FETCH_ASSOC);
        if ($indexes) {
            $conn->exec('ALTER TABLE tbl_sports_scan_log DROP INDEX uq_sports_scan_dedup');
        }
        // Pre-redesign test data may hold separate AM/PM rows for the same
        // employee/event/day (allowed under the old per-session dedup key),
        // which the new one-row-per-day key would reject. Collapse those down
        // to the newest row per group before adding the constraint.
        $conn->exec(
            'DELETE s1 FROM tbl_sports_scan_log s1
             INNER JOIN tbl_sports_scan_log s2
                ON s1.event_id = s2.event_id AND s1.piid = s2.piid AND s1.scan_date = s2.scan_date
                AND s1.id < s2.id'
        );
        $conn->exec('ALTER TABLE tbl_sports_scan_log ADD UNIQUE KEY uq_sports_scan_dedup (event_id, piid, scan_date)');
    }
    if (!in_array('scanned_by_user_id', $scanColumns, true)) {
        $conn->exec('ALTER TABLE tbl_sports_scan_log ADD COLUMN scanned_by_user_id INT UNSIGNED NULL AFTER raw_qr_payload');
        $conn->exec('ALTER TABLE tbl_sports_scan_log ADD COLUMN scanned_by_name VARCHAR(150) NULL AFTER scanned_by_user_id');
    }

    // A lightweight account type just for the scanner — separate from full
    // HRMIS system accounts (tblsysuser) and employee portal accounts, so a
    // CSC user can only ever log into sports_scan.php, never anything else.
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_sports_csc_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            session_version INT UNSIGNED NOT NULL DEFAULT 1,
            created_by VARCHAR(50) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sports_csc_username (username),
            KEY idx_sports_csc_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    // One active session at a time: logging in on a new device bumps this,
    // which instantly invalidates every token minted before the bump (see
    // sports_csc_token()/sports_csc_user_from_token() below).
    $cscColumns = array_column($conn->query('SHOW COLUMNS FROM tbl_sports_csc_users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('session_version', $cscColumns, true)) {
        $conn->exec('ALTER TABLE tbl_sports_csc_users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active');
    }
    $ready = true;
}

/** No manual session picker: the half is inferred from the actual scan time. */
function sports_auto_session(): string {
    return (int)date('H') < 12 ? 'am' : 'pm';
}

/**
 * Employee ID badges here are pre-printed, not HRMIS-issued, and the QR
 * payload sometimes has the employee's name appended right after the ID
 * number with no separator (e.g. "2020-01234JUAN DELA CRUZ"). ID numbers use
 * a dash between digit groups, so take the leading run of digits/dashes
 * (starting with a digit) and trim any trailing dash.
 */
function sports_parse_id_number(string $raw): string {
    $raw = trim($raw);
    if (preg_match('/^[0-9][0-9-]*/', $raw, $m)) {
        return rtrim($m[0], '-');
    }
    return '';
}

/** Maps an operator-chosen session to the DTR override time window. */
function sports_session_window(string $session): ?array {
    return match ($session) {
        'am' => ['08:00:00', '12:00:00'],
        'pm' => ['13:00:00', '17:00:00'],
        'whole' => ['08:00:00', '17:00:00'],
        default => null,
    };
}

function sports_session_label(string $session): string {
    return match ($session) {
        'am' => 'Morning',
        'pm' => 'Afternoon',
        'whole' => 'Whole Day',
        default => $session,
    };
}

function sports_event_list(PDO $conn): array {
    sports_ensure_schema($conn);
    $stmt = $conn->query(
        "SELECT e.id, e.sport_name, e.is_active, e.created_by, e.created_at,
                (SELECT COUNT(*) FROM tbl_sports_scan_log s WHERE s.event_id = e.id) AS scan_count
           FROM tbl_sports_events e
          ORDER BY e.is_active DESC, e.created_at DESC, e.id DESC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * No login/PIN gate on the scanner: anyone with the link picks a still-open
 * event and scans directly. Only events an admin has NOT yet flagged done
 * (is_active) are offered here, and sports_scan_record() re-checks is_active
 * on every write, so scanning against a since-closed event still fails.
 */
function sports_event_list_active(PDO $conn): array {
    sports_ensure_schema($conn);
    $stmt = $conn->query(
        "SELECT id, sport_name FROM tbl_sports_events WHERE is_active = 1 ORDER BY created_at DESC, id DESC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Events are just a name (a real event can span several calendar days, e.g.
 * a multi-day basketball bracket). Attendance is recorded against the real
 * date/time of each scan (see sports_scan_record()), never a fixed event
 * date. An admin flags the event done via sports_event_set_active(false)
 * once it has actually finished; scans against a done event are rejected.
 */
function sports_event_create(PDO $conn, array $data, string $actor): int {
    sports_ensure_schema($conn);
    $sportName = trim((string)($data['sport_name'] ?? ''));
    if ($sportName === '') {
        throw new InvalidArgumentException('Sport name is required');
    }
    $stmt = $conn->prepare(
        'INSERT INTO tbl_sports_events (sport_name, is_active, created_by, created_at)
         VALUES (?, 1, ?, NOW())'
    );
    $stmt->execute([$sportName, substr($actor, 0, 50)]);
    return (int)$conn->lastInsertId();
}

function sports_event_set_active(PDO $conn, int $id, bool $active): void {
    sports_ensure_schema($conn);
    $stmt = $conn->prepare('UPDATE tbl_sports_events SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
}

function sports_event_by_id(PDO $conn, int $id): ?array {
    sports_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT id, sport_name, is_active FROM tbl_sports_events WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sports_event_scans(PDO $conn, int $eventId): array {
    sports_ensure_schema($conn);
    $stmt = $conn->prepare(
        'SELECT id, piid, employee_name, session, oid, scanned_by_name, scanned_at
           FROM tbl_sports_scan_log
          WHERE event_id = ?
          ORDER BY scanned_at DESC, id DESC'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- CSC scanner accounts: lightweight login, scoped only to the scanner ---- */

function sports_csc_user_list(PDO $conn): array {
    sports_ensure_schema($conn);
    $stmt = $conn->query(
        'SELECT id, username, full_name, is_active, created_by, created_at FROM tbl_sports_csc_users ORDER BY full_name'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sports_csc_user_create(PDO $conn, array $data, string $actor): int {
    sports_ensure_schema($conn);
    $username = trim((string)($data['username'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
        throw new InvalidArgumentException('Username must be 3-50 characters (letters, numbers, dot, dash, underscore)');
    }
    if ($fullName === '') {
        throw new InvalidArgumentException('Full name is required');
    }
    if (strlen($password) < 6) {
        throw new InvalidArgumentException('Password must be at least 6 characters');
    }
    $dupStmt = $conn->prepare('SELECT id FROM tbl_sports_csc_users WHERE username = ? LIMIT 1');
    $dupStmt->execute([$username]);
    if ($dupStmt->fetch()) {
        throw new InvalidArgumentException('That username is already taken');
    }
    $stmt = $conn->prepare(
        'INSERT INTO tbl_sports_csc_users (username, password_hash, full_name, is_active, created_by, created_at)
         VALUES (?, ?, ?, 1, ?, NOW())'
    );
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, substr($actor, 0, 50)]);
    return (int)$conn->lastInsertId();
}

function sports_csc_user_set_active(PDO $conn, int $id, bool $active): void {
    sports_ensure_schema($conn);
    $conn->prepare('UPDATE tbl_sports_csc_users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
}

function sports_csc_user_reset_password(PDO $conn, int $id, string $newPassword): void {
    sports_ensure_schema($conn);
    if (strlen($newPassword) < 6) {
        throw new InvalidArgumentException('Password must be at least 6 characters');
    }
    $conn->prepare('UPDATE tbl_sports_csc_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
}

/**
 * One active session per account: a successful login bumps session_version,
 * which instantly invalidates any token issued to a previous device — the
 * simplest recovery for an event where a phone gets lost, swapped, or left
 * logged in (no need to reach the old device to log it out first).
 */
function sports_csc_user_authenticate(PDO $conn, string $username, string $password): ?array {
    sports_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT id, username, password_hash, full_name, session_version FROM tbl_sports_csc_users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([trim($username)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        return null;
    }
    $newVersion = (int)$row['session_version'] + 1;
    $conn->prepare('UPDATE tbl_sports_csc_users SET session_version = ? WHERE id = ?')->execute([$newVersion, $row['id']]);
    return ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'full_name' => (string)$row['full_name'], 'session_version' => $newVersion];
}

function sports_csc_user_by_id(PDO $conn, int $id): ?array {
    sports_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT id, username, full_name, session_version FROM tbl_sports_csc_users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'full_name' => (string)$row['full_name'], 'session_version' => (int)$row['session_version']];
}

/* ---- CSC login token: signed, scoped to one scanner account ---- */

function sports_csc_token_secret(): string {
    $configured = trim((string)(getenv('SPORTS_CSC_TOKEN_SECRET') ?: ''));
    if (strlen($configured) >= 32) {
        return $configured;
    }
    return hash('sha256', 'hrmis-sports-csc-v1|' . (string)getenv('DB_PASS'));
}

function sports_csc_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function sports_csc_base64url_decode(string $value): string|false {
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($value, true);
}

function sports_csc_token(int $userId, int $sessionVersion): string {
    $payload = json_encode([
        'v' => 1,
        'uid' => $userId,
        'sv' => $sessionVersion,
        'e' => time() + (12 * 3600),
    ], JSON_UNESCAPED_SLASHES);
    $key = hash('sha256', sports_csc_token_secret(), true);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Unable to create login token');
    }
    return sports_csc_base64url_encode($nonce . $tag . $ciphertext);
}

/**
 * Re-checks the account is still active AND that this token's session_version
 * still matches the account's current one, on every single call — not just
 * at login. A newer login elsewhere bumps the DB value, so this rejects the
 * old device's token immediately rather than waiting for its 12h expiry.
 */
function sports_csc_user_from_token(PDO $conn, string $token): ?array {
    $encrypted = sports_csc_base64url_decode($token);
    if ($encrypted === false || strlen($encrypted) < 29) {
        return null;
    }
    $nonce = substr($encrypted, 0, 12);
    $tag = substr($encrypted, 12, 16);
    $ciphertext = substr($encrypted, 28);
    $payload = openssl_decrypt($ciphertext, 'aes-256-gcm', hash('sha256', sports_csc_token_secret(), true), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($payload === false) {
        return null;
    }
    $data = json_decode($payload, true);
    if (!is_array($data) || ($data['v'] ?? null) !== 1 || (int)($data['e'] ?? 0) < time()) {
        return null;
    }
    $userId = (int)($data['uid'] ?? 0);
    if ($userId <= 0) {
        return null;
    }
    $user = sports_csc_user_by_id($conn, $userId);
    if ($user === null || $user['session_version'] !== (int)($data['sv'] ?? -1)) {
        return null;
    }
    return $user;
}

/**
 * Deletes one scan and the DTR override it produced. Safe because each scan
 * creates its own dedicated override record (dtr_override_save() with oid=0
 * always inserts a new row) covering exactly that one employee/date, so
 * there is never another scan's data sharing the same OID to protect.
 */
function sports_scan_delete(PDO $conn, int $scanLogId): void {
    sports_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT id, oid FROM tbl_sports_scan_log WHERE id = ? LIMIT 1');
    $stmt->execute([$scanLogId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Scan record not found');
    }
    if (!empty($row['oid'])) {
        try {
            dtr_override_delete($conn, (int)$row['oid']);
        } catch (InvalidArgumentException $e) {
            // Override already gone (e.g. removed directly from DTR Overrides) —
            // still clean up the now-orphaned scan log row below.
        }
    }
    $conn->prepare('DELETE FROM tbl_sports_scan_log WHERE id = ?')->execute([$scanLogId]);
}

/**
 * Records one scan: parses the QR payload, resolves the employee from the
 * PDS master (never the payroll/leave roster), and writes a DTR override for
 * TODAY's real date via dtr_override_save() — never a fixed event date. The
 * half (AM/PM) is inferred from the actual time of the scan, not chosen by
 * the operator. A second scan of the same person for the same event on the
 * SAME calendar day, on the opposite half, upgrades the existing override in
 * place to a whole-day window rather than creating a second override. A
 * repeat scan of the same half (or a day already upgraded to whole) is
 * reported back as 'already_recorded'. $scannedBy (['id' => ..., 'name' => ...])
 * identifies the logged-in CSC scanner account performing the scan; it is
 * both stamped onto the scan log and used as the DTR override's actor, so
 * the override's Created_By reflects the real person who scanned, not a
 * generic system label.
 */
function sports_scan_record(PDO $conn, int $eventId, string $rawPayload, ?string $manualPiid, array $scannedBy): array {
    sports_ensure_schema($conn);
    $event = sports_event_by_id($conn, $eventId);
    if (!$event || !$event['is_active']) {
        throw new InvalidArgumentException('This event is no longer active');
    }

    $employee = null;
    if ($manualPiid !== null && $manualPiid !== '') {
        $stmt = $conn->prepare('SELECT PIID, ID_NUM, SurName, FirstName, MiddleName FROM tblpersonalinformation WHERE PIID = ? LIMIT 1');
        $stmt->execute([$manualPiid]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $idNumber = sports_parse_id_number($rawPayload);
        if ($idNumber === '') {
            return ['status' => 'not_found', 'message' => 'QR code could not be read. Use manual search.'];
        }
        $stmt = $conn->prepare('SELECT PIID, ID_NUM, SurName, FirstName, MiddleName FROM tblpersonalinformation WHERE ID_NUM = ? LIMIT 1');
        $stmt->execute([$idNumber]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$employee) {
        return ['status' => 'not_found', 'message' => 'No employee matches that ID. Use manual search.'];
    }
    $piid = (string)$employee['PIID'];
    $name = trim(implode(' ', array_filter([$employee['FirstName'] ?? '', $employee['MiddleName'] ?? '', $employee['SurName'] ?? '']))) ?: (string)$employee['ID_NUM'];
    $scannedByName = trim((string)($scannedBy['full_name'] ?? '')) ?: 'Unknown';
    $scannedByUserId = (int)($scannedBy['id'] ?? 0) ?: null;

    $today = date('Y-m-d');
    $scannedSession = sports_auto_session();

    $existingStmt = $conn->prepare('SELECT id, oid, session FROM tbl_sports_scan_log WHERE event_id = ? AND piid = ? AND scan_date = ? LIMIT 1');
    $existingStmt->execute([$eventId, $piid, $today]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $existingSession = (string)$existing['session'];
        if ($existingSession === 'whole' || $existingSession === $scannedSession) {
            return ['status' => 'already_recorded', 'employee_name' => $name, 'piid' => $piid];
        }

        // Opposite half already on file for today — upgrade that same
        // override (and log row) to a whole-day window in place.
        [$start, $end] = sports_session_window('whole');
        $oid = dtr_override_save($conn, [
            'oid' => (int)$existing['oid'],
            'name' => $event['sport_name'],
            'override_type' => 'Sports Event',
            'remarks' => 'Recorded via mobile sports attendance scanner by ' . $scannedByName,
            'piids' => [$piid],
            'dates' => [[
                'date' => $today,
                'time_start' => $start,
                'time_end' => $end,
            ]],
        ], $scannedByName);
        $conn->prepare('UPDATE tbl_sports_scan_log SET session = ?, oid = ?, employee_name = ?, scanned_by_user_id = ?, scanned_by_name = ? WHERE id = ?')
            ->execute(['whole', $oid, $name, $scannedByUserId, $scannedByName, $existing['id']]);

        return ['status' => 'recorded', 'employee_name' => $name, 'piid' => $piid, 'session' => 'whole'];
    }

    [$start, $end] = sports_session_window($scannedSession);
    $oid = dtr_override_save($conn, [
        'name' => $event['sport_name'],
        'override_type' => 'Sports Event',
        'remarks' => 'Recorded via mobile sports attendance scanner by ' . $scannedByName,
        'piids' => [$piid],
        'dates' => [[
            'date' => $today,
            'time_start' => $start,
            'time_end' => $end,
        ]],
    ], $scannedByName);

    try {
        $insert = $conn->prepare(
            'INSERT INTO tbl_sports_scan_log (event_id, piid, employee_name, scan_date, session, oid, raw_qr_payload, scanned_by_user_id, scanned_by_name, scanned_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $insert->execute([$eventId, $piid, $name, $today, $scannedSession, $oid, substr($rawPayload, 0, 255) ?: null, $scannedByUserId, $scannedByName]);
    } catch (PDOException $e) {
        // Unique key (event_id, piid, scan_date) caught a race between the
        // lookup above and this insert (two near-simultaneous scans of the
        // same badge). The override above was still written safely
        // (dtr_override_save is its own transaction); treat as already recorded.
        if ((int)$e->errorInfo[1] === 1062) {
            return ['status' => 'already_recorded', 'employee_name' => $name, 'piid' => $piid];
        }
        throw $e;
    }

    return ['status' => 'recorded', 'employee_name' => $name, 'piid' => $piid, 'session' => $scannedSession];
}

function sports_dashboard_feed(PDO $conn, ?int $eventId, int $sinceId = 0): array {
    sports_ensure_schema($conn);
    $where = ['1=1'];
    $params = [];
    if ($eventId) {
        $where[] = 's.event_id = ?';
        $params[] = $eventId;
    }
    if ($sinceId > 0) {
        $where[] = 's.id > ?';
        $params[] = $sinceId;
    }
    $stmt = $conn->prepare(
        "SELECT s.id, s.employee_name, s.session, s.scanned_at, e.sport_name, e.id AS event_id
           FROM tbl_sports_scan_log s
           INNER JOIN tbl_sports_events e ON e.id = s.event_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY s.id DESC
          LIMIT 200"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

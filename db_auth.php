<?php
// Secure Database-Based Token Authentication System with Hashed Tokens
// Implements atomic token rotation with proper database tables

require_once __DIR__ . '/security.php';

require_once __DIR__ . '/env_loader.php';
hrmis_load_env_file(__DIR__ . '/.env');

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
//
// Fixed session duration from sign-in. Activity and background polling do not
// shorten or extend this deadline, so browser and server consistently allow
// one 24-hour session. Override per deployment with SESSION_DURATION_SECONDS.
define('ACCESS_TOKEN_EXPIRY', max(300, (int)(getenv('SESSION_DURATION_SECONDS') ?: 86400))); // 24 hours
// Unused: refreshAccessToken() has no HTTP endpoint, so nothing can trade a
// refresh token for a fresh session and bypass the idle limit above.
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

function tokenLookup($token) {
    return hash('sha256', (string)$token);
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

function getBearerTokenFromRequest(): ?string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $key => $value) {
        if (strcasecmp((string)$key, 'Authorization') === 0
            && preg_match('/Bearer\s+(.+)$/i', (string)$value, $matches)) {
            return trim((string)$matches[1]);
        }
    }

    $serverCandidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['Authorization'] ?? null,
    ];
    foreach ($serverCandidates as $candidate) {
        if (is_string($candidate) && preg_match('/Bearer\s+(.+)$/i', $candidate, $matches)) {
            return trim((string)$matches[1]);
        }
    }

    return null;
}

function hrmis_ensure_employee_accounts_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_employee_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid VARCHAR(20) NOT NULL,
            id_number VARCHAR(20) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(150) NULL,
            email_source VARCHAR(40) NULL,
            email_confirmed_at DATETIME NULL,
            email_updated_at DATETIME NULL,
            must_change_password TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_employee_accounts_piid (piid),
            UNIQUE KEY uq_employee_accounts_id_number (id_number),
            KEY idx_employee_accounts_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

function hrmis_ensure_department_approvers_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_leave_department_approvers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            department VARCHAR(150) NOT NULL,
            approver_role VARCHAR(40) NOT NULL DEFAULT 'department_head',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_leave_dept_approver_user_dept (user_id, department),
            KEY idx_leave_dept_approver_username (username),
            KEY idx_leave_dept_approver_department (department),
            KEY idx_leave_dept_approver_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $columns = $conn->query("SHOW COLUMNS FROM tbl_employee_accounts")->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_flip($columns);
    $alter = [];
    if (!isset($existing['email'])) {
        $alter[] = "ADD COLUMN email VARCHAR(150) NULL AFTER password_hash";
    }
    if (!isset($existing['email_source'])) {
        $alter[] = "ADD COLUMN email_source VARCHAR(40) NULL AFTER email";
    }
    if (!isset($existing['email_confirmed_at'])) {
        $alter[] = "ADD COLUMN email_confirmed_at DATETIME NULL AFTER email_source";
    }
    if (!isset($existing['email_updated_at'])) {
        $alter[] = "ADD COLUMN email_updated_at DATETIME NULL AFTER email_confirmed_at";
    }
    if ($alter) {
        $conn->exec("ALTER TABLE tbl_employee_accounts " . implode(', ', $alter));
    }

    $ensured = true;
}

function hrmis_ensure_user_module_permissions_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_user_module_permissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            module VARCHAR(40) NOT NULL,
            access_level VARCHAR(20) NOT NULL DEFAULT 'admin',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            granted_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_module_permissions_user_module (user_id, module),
            KEY idx_user_module_permissions_username (username),
            KEY idx_user_module_permissions_module (module),
            KEY idx_user_module_permissions_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

/**
 * Live-server module switches are deliberately separate from per-user grants.
 * A user needs both: the module must be enabled for the deployment and the
 * user's account must have access.
 */
function hrmis_ensure_module_settings_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_system_module_settings (
            module VARCHAR(40) NOT NULL,
            enabled_on_live TINYINT(1) NOT NULL DEFAULT 1,
            updated_by VARCHAR(100) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (module),
            KEY idx_system_module_settings_enabled (enabled_on_live)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

function hrmis_runtime_environment(): string {
    $configured = strtolower(trim((string)(
        $_ENV['APP_ENV']
        ?? $_SERVER['APP_ENV']
        ?? getenv('APP_ENV')
        ?: ''
    )));
    if (in_array($configured, ['production', 'prod', 'live'], true)) {
        return 'production';
    }
    if (in_array($configured, ['development', 'dev', 'local', 'testing', 'test'], true)) {
        return 'development';
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        ? 'development'
        : 'production';
}

function hrmis_is_live_environment(): bool {
    return hrmis_runtime_environment() === 'production';
}

function hrmis_module_live_defaults(): array {
    return [
        'pds' => true,
        'leave' => true,
        'leave_undertime' => true,
        'leave_applications' => true,
        'dtr' => true,
        'dtr_flex' => true,
        'reports' => true,
        // Payroll is still under rollout and must be explicitly enabled live.
        'payroll' => false,
        // Job Order payroll is deployed separately from Regular/Casual payroll.
        'payroll_jo' => false,
        'sports_event' => true,
    ];
}

function hrmis_get_module_live_visibility(PDO $conn): array {
    static $visibility = null;
    if (is_array($visibility)) {
        return $visibility;
    }

    $visibility = hrmis_module_live_defaults();
    hrmis_ensure_module_settings_table($conn);
    $rows = $conn->query(
        "SELECT module, enabled_on_live
         FROM tbl_system_module_settings"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $module = (string)($row['module'] ?? '');
        if (array_key_exists($module, $visibility)) {
            $visibility[$module] = (bool)($row['enabled_on_live'] ?? false);
        }
    }

    // Flex schedules are a DTR capability and cannot outlive the parent module.
    if (empty($visibility['dtr'])) {
        $visibility['dtr_flex'] = false;
    }
    if (empty($visibility['leave'])) {
        $visibility['leave_undertime'] = false;
        $visibility['leave_applications'] = false;
    }
    return $visibility;
}

function hrmis_module_is_available(?PDO $conn, string $module): bool {
    // Child permissions use their parent's deployment visibility. They are
    // account-level capabilities, not separate live-rollout modules.
    $availabilityModule = [
        'payroll_settings' => 'payroll',
        'pds_lookup' => 'pds',
    ][$module] ?? $module;

    if (!array_key_exists($availabilityModule, hrmis_module_live_defaults())) {
        return false;
    }
    if (!hrmis_is_live_environment()) {
        return true;
    }
    if (!$conn) {
        // Fail closed on live if deployment visibility cannot be read.
        return false;
    }
    $visibility = hrmis_get_module_live_visibility($conn);
    return !empty($visibility[$availabilityModule]);
}

/**
 * One-time-per-module bootstrap: preserve 'rain's pre-existing de facto
 * access to modules that used to be gated only by hardcoded username checks
 * (PDS, then Leave), so behavior doesn't regress on rollout. Uses INSERT
 * IGNORE keyed on (user_id, module) so it only ever fills a genuinely missing
 * row — if rain later revokes their own access via User Management, this
 * will NOT silently re-grant it. Deliberately NOT called from
 * hrmis_get_user_module_permissions() — that function runs on every
 * authenticated request app-wide, so extra queries here would add a
 * remote-DB round trip to every page load. Only called from the User
 * Management screen (low-frequency, self-healing if a row is ever missing).
 */
function hrmis_seed_default_module_permissions(PDO $conn): void {
    hrmis_ensure_user_module_permissions_table($conn);
    $rainStmt = $conn->prepare("SELECT UID, Username FROM tblsysuser WHERE Username = 'rain' LIMIT 1");
    $rainStmt->execute();
    $rain = $rainStmt->fetch();
    if (!$rain) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    foreach (['pds', 'leave', 'dtr_flex', 'payroll_settings'] as $module) {
        $conn->prepare(
            "INSERT IGNORE INTO tbl_user_module_permissions
                (user_id, username, module, access_level, is_active, granted_by, created_at, updated_at)
             VALUES (?, ?, ?, 'admin', 1, 'system-bootstrap', ?, ?)"
        )->execute([(int)$rain['UID'], (string)$rain['Username'], $module, $now, $now]);
    }
}

function hrmis_get_user_module_permissions(PDO $conn, int $userId): array {
    hrmis_ensure_user_module_permissions_table($conn);
    if ($userId <= 0) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT module, access_level
         FROM tbl_user_module_permissions
         WHERE user_id = :user_id AND is_active = 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $permissions = [];
    foreach ($stmt->fetchAll() as $row) {
        $permissions[(string)$row['module']] = (string)$row['access_level'];
    }
    return $permissions;
}

function hrmis_actor_has_module_access(?array $user, string $module): bool {
    global $conn;
    if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
        return false;
    }
    if (!hrmis_module_is_available($conn instanceof PDO ? $conn : null, $module)) {
        return false;
    }
    $permissions = $user['permissions'] ?? [];
    return is_array($permissions) && !empty($permissions[$module] ?? null);
}

function hrmis_is_admin_role(?array $user): bool {
    return is_array($user) && ($user['role'] ?? '') === 'admin';
}

/** A restricted system account that can only use the PIID lookup page. */
function hrmis_is_pds_lookup_only_user(?array $user): bool {
    if (!hrmis_is_admin_role($user)) return false;
    $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
    if (empty($permissions['pds_lookup'])) return false;
    foreach (['pds', 'leave', 'dtr', 'dtr_flex', 'payroll', 'payroll_settings'] as $module) {
        if (!empty($permissions[$module])) return false;
    }
    return true;
}

/**
 * The Super Administrator account.
 *
 * This has always been a hardcoded username check scattered across the auth,
 * user-management, leave, and page-level guards. It stays hardcoded on purpose:
 * making it a grantable flag would let anyone holding User Management promote
 * themselves, which defeats the point of having a tier above administrator.
 * Naming it here gives the concept one definition and one place to change.
 */
const HRMIS_SUPER_ADMIN_USERNAME = 'rain';

function hrmis_is_super_admin(?array $user): bool {
    return is_array($user)
        && strtolower(trim((string)($user['username'] ?? ''))) === HRMIS_SUPER_ADMIN_USERNAME;
}

/** @deprecated Kept for existing call sites; prefer hrmis_is_super_admin(). */
function hrmis_is_rain_user(?array $user): bool {
    return hrmis_is_super_admin($user);
}

/** True when $username names the Super Administrator (for listing/labelling accounts). */
function hrmis_username_is_super_admin(?string $username): bool {
    return strtolower(trim((string)$username)) === HRMIS_SUPER_ADMIN_USERNAME;
}

function hrmis_ensure_access_audit_log_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_access_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NULL,
            username VARCHAR(100) NOT NULL,
            role VARCHAR(20) NULL,
            piid VARCHAR(20) NULL,
            event_type VARCHAR(20) NOT NULL,
            reason VARCHAR(255) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_access_audit_username (username),
            KEY idx_access_audit_event (event_type),
            KEY idx_access_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

// PHP's process-wide default timezone on this server is misconfigured
// (Europe/Berlin, confirmed via date_default_timezone_get()) rather than the
// Philippines. That's a server-level php.ini issue outside this feature's
// scope to fix globally (it would ripple into every other date() call in the
// app), so timestamps for this log are computed explicitly in the app's real
// operating timezone instead of relying on the process default. This matches
// the +08:00 the leave module's own DB connection already uses
// (API/leave_db.php, "SET time_zone = '+08:00'").
function hrmis_now_local(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
}

// Prefers X-Forwarded-For (first hop) for accuracy behind a future reverse
// proxy/load balancer; falls back to REMOTE_ADDR for direct connections
// (e.g. "::1" when the browser and server are the same machine — expected
// and correct for localhost testing, not a bug).
function hrmis_client_ip(): ?string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

// Dedicated login/logout/login_failed trail for the web system — deliberately
// separate from the existing token_logs table, which logs a 'validated' row
// on nearly every authenticated request (too noisy for an access audit view)
// and conflates a real user-initiated logout with a token just being rotated
// out on a fresh login elsewhere. This only ever gets 3 event types, written
// at exactly the points that mean what they say.
function hrmis_log_access_event(
    PDO $conn,
    string $eventType,
    string $username,
    ?int $userId = null,
    ?string $role = null,
    ?string $piid = null,
    ?string $reason = null
): void {
    try {
        hrmis_ensure_access_audit_log_table($conn);
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $conn->prepare(
            "INSERT INTO tbl_access_audit_log
                (user_id, username, role, piid, event_type, reason, ip_address, user_agent, created_at)
             VALUES
                (:user_id, :username, :role, :piid, :event_type, :reason, :ip_address, :user_agent, :created_at)"
        )->execute([
            ':user_id' => $userId,
            ':username' => $username,
            ':role' => $role,
            ':piid' => $piid,
            ':event_type' => $eventType,
            ':reason' => $reason,
            ':ip_address' => hrmis_client_ip(),
            ':user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            ':created_at' => hrmis_now_local(),
        ]);
    } catch (Throwable $e) {
        error_log('hrmis_log_access_event: ' . $e->getMessage());
    }
}

function hrmis_find_department_approver(PDO $conn, int $userId, string $username): ?array {
    hrmis_ensure_department_approvers_table($conn);
    if ($userId <= 0 && trim($username) === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT department, approver_role
         FROM tbl_leave_department_approvers
         WHERE is_active = 1
           AND (user_id = :user_id OR username = :username)
         ORDER BY CASE WHEN user_id = :user_id_order THEN 1 ELSE 2 END, id
         LIMIT 1"
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':username' => $username,
        ':user_id_order' => $userId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function hrmis_ensure_employee_department_approvers_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_employee_department_approvers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid VARCHAR(20) NOT NULL,
            department VARCHAR(150) NOT NULL,
            approver_role VARCHAR(40) NOT NULL DEFAULT 'department_head',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            granted_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_dept_approver_piid_dept (piid, department),
            KEY idx_emp_dept_approver_department (department),
            KEY idx_emp_dept_approver_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

// Deliberately separate from tbl_leave_department_approvers (the legacy
// tblsysuser-keyed table) — this table keys approver capability to an
// employee's own PIID so it can attach to their existing self-service
// account instead of requiring a second tblsysuser login. The legacy table
// and its consumers are left untouched/dormant.
function hrmis_find_employee_department_approver_departments(PDO $conn, string $piid): array {
    hrmis_ensure_employee_department_approvers_table($conn);
    $piid = trim($piid);
    if ($piid === '') {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT department, approver_role
         FROM tbl_employee_department_approvers
         WHERE is_active = 1 AND piid = :piid
         ORDER BY department"
    );
    $stmt->execute([':piid' => $piid]);
    return $stmt->fetchAll() ?: [];
}

// City Administrator approver capability -- citywide (no department scope), unlike
// tbl_employee_department_approvers. Attaches to an employee's own PIID/self-service
// login, same modern mechanism as the department-approver table above.
function hrmis_ensure_employee_city_admin_approvers_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_employee_city_admin_approvers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid VARCHAR(20) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            granted_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_city_admin_approver_piid (piid),
            KEY idx_emp_city_admin_approver_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

function hrmis_is_employee_city_admin_approver(PDO $conn, string $piid): bool {
    hrmis_ensure_employee_city_admin_approvers_table($conn);
    $piid = trim($piid);
    if ($piid === '') {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1 FROM tbl_employee_city_admin_approvers WHERE is_active = 1 AND piid = :piid LIMIT 1"
    );
    $stmt->execute([':piid' => $piid]);
    return (bool)$stmt->fetchColumn();
}

/** A module grant may be operational (`admin`) or report-only (`readonly`). */
function hrmis_actor_is_module_read_only(?array $user, string $module): bool {
    if (!is_array($user) || ($user['role'] ?? '') !== 'admin') return false;
    $permissions = $user['permissions'] ?? [];
    return is_array($permissions)
        && strtolower(trim((string)($permissions[$module] ?? ''))) === 'readonly';
}

function hrmis_ensure_employee_leave_official_roles_table(PDO $conn): void {
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_employee_leave_official_roles (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, piid VARCHAR(20) NOT NULL,
        official_role VARCHAR(30) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        granted_by VARCHAR(100) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (id), UNIQUE KEY uq_leave_official_role (piid), KEY idx_leave_official_role (official_role, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}
function hrmis_employee_leave_official_role(PDO $conn, string $piid): string {
    hrmis_ensure_employee_leave_official_roles_table($conn);
    $stmt = $conn->prepare("SELECT official_role FROM tbl_employee_leave_official_roles WHERE piid = :piid AND is_active = 1 LIMIT 1");
    $stmt->execute([':piid' => trim($piid)]);
    return (string)($stmt->fetchColumn() ?: '');
}
function hrmis_save_employee_leave_official_role(PDO $conn, string $piid, string $role, string $grantedBy): void {
    hrmis_ensure_employee_leave_official_roles_table($conn);
    $now = date('Y-m-d H:i:s');
    $conn->prepare("INSERT INTO tbl_employee_leave_official_roles (piid, official_role, is_active, granted_by, created_at, updated_at)
        VALUES (:piid, :role, 1, :by, :now, :now) ON DUPLICATE KEY UPDATE official_role=VALUES(official_role), is_active=1, granted_by=VALUES(granted_by), updated_at=VALUES(updated_at)")
        ->execute([':piid' => $piid, ':role' => $role, ':by' => $grantedBy, ':now' => $now]);
}

function hrmis_revoke_employee_leave_official_role(PDO $conn, int $id): void {
    hrmis_ensure_employee_leave_official_roles_table($conn);
    $conn->prepare(
        "UPDATE tbl_employee_leave_official_roles
         SET is_active = 0, updated_at = :updated_at
         WHERE id = :id"
    )->execute([
        ':updated_at' => date('Y-m-d H:i:s'),
        ':id' => $id,
    ]);
}

function hrmis_save_employee_city_admin_approver(PDO $conn, string $piid, string $grantedBy): void {
    hrmis_ensure_employee_city_admin_approvers_table($conn);
    $now = date('Y-m-d H:i:s');
    $conn->prepare(
        "INSERT INTO tbl_employee_city_admin_approvers
            (piid, is_active, granted_by, created_at, updated_at)
         VALUES (:piid, 1, :granted_by, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            is_active = 1, granted_by = VALUES(granted_by), updated_at = VALUES(updated_at)"
    )->execute([
        ':piid' => $piid,
        ':granted_by' => $grantedBy,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function hrmis_ensure_global_references_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) return;

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_global_reference_values (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference_type VARCHAR(40) NOT NULL,
            reference_value VARCHAR(190) NOT NULL,
            description VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(100) NULL,
            updated_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_global_reference_type_value (reference_type, reference_value),
            KEY idx_global_reference_type_active (reference_type, is_active),
            KEY idx_global_reference_sort (reference_type, sort_order, reference_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $ensured = true;
}

/**
 * Seeds the first global reference category from values already recognized by
 * the web and legacy account records. This runs only while the department
 * category has no rows; once seeded, Global References is authoritative.
 */
function hrmis_seed_department_references(PDO $conn): void {
    hrmis_ensure_global_references_table($conn);
    $count = $conn->prepare(
        "SELECT COUNT(*) FROM tbl_global_reference_values WHERE reference_type = 'department'"
    );
    $count->execute();
    if ((int)$count->fetchColumn() > 0) return;

    hrmis_ensure_employee_department_approvers_table($conn);
    $departments = $conn->query(
        "SELECT Department
         FROM (
            SELECT DISTINCT TRIM(Department) AS Department
            FROM tbl_syl_leave_employee_roster
            WHERE COALESCE(TRIM(Department), '') <> ''
            UNION
            SELECT DISTINCT TRIM(department) AS Department
            FROM tbl_employee_department_approvers
            WHERE COALESCE(TRIM(department), '') <> ''
            UNION
            SELECT DISTINCT TRIM(Office) AS Department
            FROM tblsysuser
            WHERE COALESCE(TRIM(Office), '') <> ''
         ) available_departments
         ORDER BY Department"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$departments) return;
    $now = hrmis_now_local();
    $insert = $conn->prepare(
        "INSERT IGNORE INTO tbl_global_reference_values
            (reference_type, reference_value, description, sort_order, is_active,
             created_by, updated_by, created_at, updated_at)
         VALUES ('department', :reference_value, NULL, 0, 1,
                 'system-seed', 'system-seed', :created_at, :updated_at)"
    );
    foreach ($departments as $department) {
        $department = trim((string)$department);
        if ($department === '') continue;
        $insert->execute([
            ':reference_value' => mb_substr($department, 0, 150),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function hrmis_get_global_reference_values(
    PDO $conn,
    string $referenceType,
    bool $activeOnly = true
): array {
    hrmis_ensure_global_references_table($conn);
    if ($referenceType === 'department') hrmis_seed_department_references($conn);

    $sql = "SELECT id, reference_type, reference_value, description, sort_order,
                   is_active, created_by, updated_by, created_at, updated_at
            FROM tbl_global_reference_values
            WHERE reference_type = :reference_type";
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY sort_order, reference_value';
    $stmt = $conn->prepare($sql);
    $stmt->execute([':reference_type' => $referenceType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fold in any office from the canonical Regular/Casual/JO office directory
 * (rpt_office_directory()) that isn't already a department reference. The
 * original seed only pulled from the Regular/Casual roster's Department
 * column, so an office with no regular-payroll employee — a per-councilor
 * Sangguniang Panlungsod JO template, PESO, BFP, City Court, etc. — never
 * made it into this list even though it's a real, HR-confirmed office.
 * INSERT IGNORE against the (reference_type, reference_value) unique key
 * means this never touches an existing row, so an admin's edit/deactivation
 * of a reference value is never overwritten by this sync.
 */
function hrmis_sync_department_references_with_offices(PDO $conn): void {
    hrmis_ensure_global_references_table($conn);
    require_once __DIR__ . '/reports_db.php';
    $offices = array_unique(array_map(
        static fn($row) => trim((string)$row['office']),
        rpt_office_directory($conn)
    ));
    if (!$offices) return;
    $now = hrmis_now_local();
    $insert = $conn->prepare(
        "INSERT IGNORE INTO tbl_global_reference_values
            (reference_type, reference_value, description, sort_order, is_active,
             created_by, updated_by, created_at, updated_at)
         VALUES ('department', :reference_value, NULL, 0, 1,
                 'system-office-sync', 'system-office-sync', :created_at, :updated_at)"
    );
    foreach ($offices as $office) {
        if ($office === '') continue;
        $insert->execute([
            ':reference_value' => mb_substr($office, 0, 150),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

/**
 * Authoritative global web reference for active departments.
 */
function hrmis_get_reference_departments(PDO $conn): array {
    hrmis_sync_department_references_with_offices($conn);
    return array_map(
        fn($row) => (string)$row['reference_value'],
        hrmis_get_global_reference_values($conn, 'department', true)
    );
}

function hrmis_save_employee_department_approver(PDO $conn, string $piid, string $department, string $approverRole, string $grantedBy): void {
    hrmis_ensure_employee_department_approvers_table($conn);
    $now = date('Y-m-d H:i:s');
    $conn->prepare(
        "INSERT INTO tbl_employee_department_approvers
            (piid, department, approver_role, is_active, granted_by, created_at, updated_at)
         VALUES (:piid, :department, :approver_role, 1, :granted_by, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            approver_role = VALUES(approver_role), is_active = 1,
            granted_by = VALUES(granted_by), updated_at = VALUES(updated_at)"
    )->execute([
        ':piid' => $piid,
        ':department' => $department,
        ':approver_role' => $approverRole,
        ':granted_by' => $grantedBy,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function hrmis_find_admin_profile(PDO $conn, array $tokenData): ?array {
    $userId = (int)($tokenData['user_id'] ?? 0);
    $username = trim((string)($tokenData['username'] ?? ''));
    if ($userId <= 0 && $username === '') {
        return null;
    }

    if ($username !== '') {
        $stmt = $conn->prepare(
            "SELECT UID, Username, Userlevel
             FROM tblsysuser
             WHERE Username = :username
             LIMIT 1"
        );
        $stmt->execute([':username' => $username]);
    } else {
        $stmt = $conn->prepare(
            "SELECT UID, Username, Userlevel
             FROM tblsysuser
             WHERE UID = :uid
             LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
    }
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $departmentApprover = hrmis_find_department_approver($conn, (int)$row['UID'], (string)$row['Username']);
    $permissions = hrmis_get_user_module_permissions($conn, (int)$row['UID']);

    return [
        'id' => (int)$row['UID'],
        'username' => (string)$row['Username'],
        'userlevel' => (int)($row['Userlevel'] ?? 1),
        'role' => $departmentApprover ? 'dept_approver' : 'admin',
        'department' => $departmentApprover ? (string)$departmentApprover['department'] : null,
        'dept_approver_role' => $departmentApprover ? (string)$departmentApprover['approver_role'] : null,
        'piid' => null,
        'id_number' => null,
        'must_change_password' => false,
        'employee_account_id' => null,
        'permissions' => $permissions,
    ];
}

function hrmis_find_employee_profile(PDO $conn, array $tokenData): ?array {
    hrmis_ensure_employee_accounts_table($conn);

    $userId = (int)($tokenData['user_id'] ?? 0);
    $username = trim((string)($tokenData['username'] ?? ''));
    if ($userId <= 0 && $username === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, piid, id_number, must_change_password, is_active
         FROM tbl_employee_accounts
         WHERE id = :id OR id_number = :id_number
         ORDER BY CASE
            WHEN id = :id_order THEN 1
            WHEN id_number = :id_number_order THEN 2
            ELSE 3
         END
         LIMIT 1"
    );
    $stmt->execute([
        ':id' => $userId,
        ':id_number' => $username,
        ':id_order' => $userId,
        ':id_number_order' => $username,
    ]);
    $row = $stmt->fetch();
    if (!$row || (int)($row['is_active'] ?? 0) !== 1) {
        return null;
    }

    $approverRows = hrmis_find_employee_department_approver_departments($conn, (string)$row['piid']);
    $isCityAdminApprover = hrmis_is_employee_city_admin_approver($conn, (string)$row['piid']);
    $officialRole = hrmis_employee_leave_official_role($conn, (string)$row['piid']);

    return [
        'id' => (int)$row['id'],
        'username' => (string)$row['id_number'],
        'userlevel' => 3,
        'role' => 'employee',
        'piid' => (string)$row['piid'],
        'id_number' => (string)$row['id_number'],
        'must_change_password' => (bool)$row['must_change_password'],
        'employee_account_id' => (int)$row['id'],
        'permissions' => [],
        'is_dept_approver' => !empty($approverRows),
        'department_scopes' => array_column($approverRows, 'department'),
        'dept_approver_role' => $approverRows[0]['approver_role'] ?? null,
        'is_city_admin_approver' => $isCityAdminApprover,
        'official_role' => $officialRole,
        'is_city_mayor_approver' => $officialRole === 'city_mayor',
    ];
}

function hrmis_resolve_token_profile(PDO $conn, array $tokenData): array {
    $admin = hrmis_find_admin_profile($conn, $tokenData);
    if ($admin) {
        return $admin;
    }

    $employee = hrmis_find_employee_profile($conn, $tokenData);
    if ($employee) {
        return $employee;
    }

    return [
        'id' => (int)($tokenData['user_id'] ?? 0),
        'username' => (string)($tokenData['username'] ?? ''),
        'userlevel' => 1,
        'role' => 'unknown',
        'piid' => null,
        'id_number' => null,
        'must_change_password' => false,
        'employee_account_id' => null,
        'permissions' => [],
    ];
}

/**
 * Resolve the PIID of whoever is acting, from a $userData/$user array built by
 * hrmis_resolve_token_profile(). This is the single canonical implementation —
 * do not re-derive it elsewhere.
 *
 * HRMIS has three overlapping numeric id spaces:
 *   - employee portal: $userData['id'] is a tbl_employee_accounts row id, and
 *     $userData['piid'] is the authoritative PDS key.
 *   - system account:  $userData['id'] IS the tblsysuser UID, and the PDS key
 *     lives in tblsysuser.fkPDS.
 * Matching an employee account's id against tblsysuser.UID — or against
 * tblpersonalinformation.PIID — silently resolves to a stranger, because those
 * id spaces overlap. That is how approvals came to be stamped with the wrong
 * person's name. Resolve the PIID first, and only ever match on that.
 */
function hrmis_resolve_actor_piid(PDO $conn, array $userData): string {
    $piid = trim((string)($userData['piid'] ?? ''));
    if ($piid !== '') {
        return $piid;
    }
    // System accounts only: $userData['id'] is a genuine tblsysuser UID here.
    $uid = trim((string)($userData['id'] ?? ''));
    if ($uid === '') {
        return '';
    }
    $sysUserStmt = $conn->prepare("SELECT fkPDS FROM tblsysuser WHERE UID = :uid LIMIT 1");
    $sysUserStmt->execute([':uid' => $uid]);
    $sysUserRow = $sysUserStmt->fetch(PDO::FETCH_ASSOC);
    return $sysUserRow && !empty($sysUserRow['fkPDS']) ? trim((string)$sysUserRow['fkPDS']) : '';
}

/**
 * The core "does this employee match this free-text term" predicate shared by
 * every employee-picker across payroll/DTR/JO/user-management: surname, first
 * name, or ID number, each matched as a %term% substring. Returns a ready-to-OR
 * SQL fragment and its bound params — callers compose it into their own WHERE
 * clause and may add further site-specific clauses (dept/status scope, extra
 * name-order variants) alongside it. Deliberately does NOT decide table scope,
 * status filtering, or decoration — those differ correctly per module.
 */
function hrmis_pds_name_term_predicate(string $term, string $alias = '', string $paramSuffix = ''): array {
    $col = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $like = "%$term%";
    $pSurname   = ":term_surname{$paramSuffix}";
    $pFirstname = ":term_firstname{$paramSuffix}";
    $pIdnum     = ":term_idnum{$paramSuffix}";
    return [
        "({$col}SurName LIKE {$pSurname} OR {$col}FirstName LIKE {$pFirstname} OR {$col}ID_NUM LIKE {$pIdnum})",
        [$pSurname => $like, $pFirstname => $like, $pIdnum => $like],
    ];
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
        $accessTokenLookup = tokenLookup($accessToken);
        $refreshTokenLookup = tokenLookup($refreshToken);

        $accessExpiresAt = date('Y-m-d H:i:s', time() + ACCESS_TOKEN_EXPIRY);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + REFRESH_TOKEN_EXPIRY);

        // Get client info
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Keep other browsers/devices signed in. Each session has its own fixed
        // 24-hour deadline, and explicit logout revokes only the presented token.

        // Insert new access token
        $accessStmt = $conn->prepare(
            'INSERT INTO access_tokens (token, token_lookup, user_id, username, expires_at, ip_address, user_agent, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)'
        );
        $accessStmt->execute([$accessTokenHash, $accessTokenLookup, $userId, $username, $accessExpiresAt, $ipAddress, $userAgent]);

        $accessTokenId = $conn->lastInsertId();

        // Insert new refresh token
        $refreshStmt = $conn->prepare(
            'INSERT INTO refresh_tokens (refresh_token, token_lookup, user_id, username, access_token_id, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $refreshStmt->execute([$refreshTokenHash, $refreshTokenLookup, $userId, $username, $accessTokenId, $refreshExpiresAt, $ipAddress, $userAgent]);

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
function hrmisValidateToken() {
    global $conn;

    // Get token from multiple sources
    $token = null;

    // First priority: Authorization header
    $headerToken = getBearerTokenFromRequest();
    if ($headerToken) {
        $token = $headerToken;
    }

    // Second priority: Cookie
    if (!$token) {
        $token = $_COOKIE['auth_token'] ?? null;
    }

    // Third priority: POST data (for AJAX requests)
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['auth_token'] ?? null;
    }

    // Fourth priority: GET data (for direct links)
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = $_GET['auth_token'] ?? null;
    }

    if (!$token) {
        return false;
    }

    try {
        // New sessions use an indexed deterministic digest. Older sessions fall
        // back to the bcrypt scan once and are upgraded in place.
        $sql = 'SELECT id, user_id, username, token, expires_at, last_accessed FROM access_tokens WHERE expires_at > NOW() AND is_active = TRUE AND (token_lookup = ? OR token_lookup IS NULL) ORDER BY token_lookup IS NULL ASC';
        $stmt = $conn->prepare($sql);
        $stmt->execute([tokenLookup($token)]);
        $tokens = $stmt->fetchAll();

        foreach ($tokens as $tokenData) {
            if (verifyTokenHash($token, $tokenData['token'])) {
                $conn->prepare('UPDATE access_tokens SET token_lookup = ? WHERE id = ? AND token_lookup IS NULL')
                    ->execute([tokenLookup($token), $tokenData['id']]);
                // Background requests authenticate normally but do not create
                // audit noise. Foreground use updates last_accessed for auditing
                // only; expires_at remains the fixed 24-hour deadline set at login.
                $isBackground = trim((string)($_SERVER['HTTP_X_HRMIS_BACKGROUND'] ?? '')) === '1';
                $newExpiresAt = (string)$tokenData['expires_at'];

                if (!$isBackground) {
                    // Throttle routine session writes and audit rows to once per five minutes.
                    $updateStmt = $conn->prepare("
                        UPDATE access_tokens
                        SET last_accessed = NOW()
                        WHERE id = ?
                          AND (last_accessed IS NULL OR last_accessed < NOW() - INTERVAL 5 MINUTE)
                    ");
                    $updateStmt->execute([$tokenData['id']]);

                    if ($updateStmt->rowCount() > 0) {
                        logTokenAction($tokenData['id'], 'access', $tokenData['user_id'], 'validated');
                    }
                }

                $resolved = hrmis_resolve_token_profile($conn, $tokenData);
                $resolved['token'] = $token;
                $resolved['expires_at'] = $newExpiresAt;
                return $resolved;
            }
        }

        error_log("validateToken: Token verification failed");
        return false;

    } catch (Exception $e) {
        error_log("Error validating token: " . $e->getMessage());
        return false;
    }
}

if (!function_exists('validateToken')) {
    function validateToken() {
        $startedAt = microtime(true);
        $result = hrmisValidateToken();
        if (!headers_sent()) {
            header('Server-Timing: auth;dur=' . number_format((microtime(true) - $startedAt) * 1000, 1, '.', ''), false);
        }
        return $result;
    }
}

/**
 * Refresh access token using refresh token (atomic operation)
 */
function refreshAccessToken($refreshToken) {
    global $conn;

    try {
        $conn->beginTransaction();

        // Find the refresh token by indexed digest when the migration is present.
        $sql = "
            SELECT rt.id, rt.user_id, rt.username, rt.access_token_id,
                   rt.refresh_token, at.token as access_token_hash
            FROM refresh_tokens rt
            LEFT JOIN access_tokens at ON rt.access_token_id = at.id
            WHERE rt.expires_at > NOW() AND rt.is_used = FALSE AND rt.is_revoked = FALSE
        ";
        $sql .= ' AND (rt.token_lookup = ? OR rt.token_lookup IS NULL) ORDER BY rt.token_lookup IS NULL ASC';
        $stmt = $conn->prepare($sql);
        $stmt->execute([tokenLookup($refreshToken)]);
        $refreshTokens = $stmt->fetchAll();

        foreach ($refreshTokens as $rt) {
            if (verifyTokenHash($refreshToken, $rt['refresh_token'])) {
                $conn->prepare('UPDATE refresh_tokens SET token_lookup = ? WHERE id = ? AND token_lookup IS NULL')
                    ->execute([tokenLookup($refreshToken), $rt['id']]);
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

    if (!$conn) {
        error_log("Cannot revoke session: database connection unavailable");
        return false;
    }

    try {
        $conn->beginTransaction();

        // Find the token to revoke
        $stmt = $conn->prepare("
            SELECT id, user_id, token FROM access_tokens
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

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Token not found for revocation");
        return false;

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
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

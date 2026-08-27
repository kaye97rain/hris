<?php
/**
 * leave_db.php — PDO connection + all DB query functions for the Leave Credits module.
 *
 * Uses the same cgmhris database as pds_db.php (PDS_DB_* env vars).
 * Exposes $leave_conn (PDO) and a set of functions used by leave_api.php.
 *
 * Tables used:
 *   tbl_syl_leave_form               — individual leave ledger entries
 *   tbl_syl_leave_available_balance  — per-employee per-year forwarded balances
 *   tbl_syl_leave_credits_earned     — lookup: days present + LWOP → credits earned
 *   tbl_syl_leave_conversion_of_working_day — lookup: time value + type → day fraction
 *   tbl_syl_employee_masterlist      — employee list (PIID, name, dept, etc.)
 */

require_once __DIR__ . '/env_loader.php';
hrmis_load_env_file(__DIR__ . '/.env');

// JO office fallback for lv_get_employee(). Safe to require here: this file and
// jo_office_map.php below it have no top-level requires of their own, so there is
// no cycle back into leave_db.php.
require_once __DIR__ . '/payroll_jo_directory.php';

function lv_env_flag(string $key, bool $default = false): bool {
    $raw = getenv($key);
    if ($raw === false && array_key_exists($key, $_ENV)) {
        $raw = $_ENV[$key];
    }
    $value = strtolower(trim((string)($raw === false ? ($default ? '1' : '0') : $raw)));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

$_lv_host = getenv('DB_HOST') ?: 'localhost';
$_lv_db   = getenv('DB_NAME') ?: 'hrmis';
$_lv_user = getenv('DB_USER') ?: 'root';
$_lv_pass = getenv('DB_PASS') ?: '';

// This connection is shared by Leave, DTR, reports, and JO Payroll. MySQL can
// briefly refuse a new connection while it is starting or recovering; retrying
// here prevents every caller from needing users to refresh repeatedly.
$leave_conn = null;
$leaveConnError = null;
// Most authenticated API/page requests have already opened the HRMIS account
// connection. Reuse it instead of opening a second MySQL socket for the same
// database. This is especially important for JO preparer checks during login.
if (isset($conn) && $conn instanceof PDO) {
    $leave_conn = $conn;
} elseif (function_exists('getAuthDbConnection')) {
    $sharedAuthConn = getAuthDbConnection();
    if ($sharedAuthConn instanceof PDO) {
        $leave_conn = $sharedAuthConn;
    }
}

for ($leaveAttempt = 1; !($leave_conn instanceof PDO) && $leaveAttempt <= 4; $leaveAttempt++) {
    try {
        $leave_conn = new PDO(
            "mysql:host=$_lv_host;dbname=$_lv_db;charset=utf8",
            $_lv_user,
            $_lv_pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );
        $leave_conn->exec("SET time_zone = '+08:00'");
        break;
    } catch (PDOException $e) {
        $leave_conn = null;
        $leaveConnError = $e;
        if ($leaveAttempt < 4) {
            usleep(250000 * $leaveAttempt);
        }
    }
}

if (!$leave_conn instanceof PDO) {
    error_log('leave_db.php: connection failed after 4 attempts: ' . ($leaveConnError?->getMessage() ?? 'unknown error'));
    header('Content-Type: application/json');
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Leave database unavailable']);
    exit;
}

// ── Payroll-based employee source (regular + casual consolidated) ─────────────
// Regular: tbl_syl_payroll_parent → tbl_template_payroll
// Casual:  tbl_syl_payroll_parent_casual → tbl_template_payroll_casual → bridged
//          to tbl_template_payroll by matching Name, so both show under the same
//          consolidated department label.
//
// OPTIMIZED: the "latest PPID per TID" lookup is pre-computed once per unique
// template (≈33 rows) via a derived table, instead of running a correlated
// subquery for every row in tbl_syl_payroll_parent (potentially thousands).

define('_LV_EMP_BASE_WHERE', '1');
// The tie-break order MUST be End_num (the numeric month) before End_Date
// (the month NAME). End_Date DESC sorts "May" ahead of "August" because 'M' >
// 'A' alphabetically — completely wrong chronologically (May is month 5,
// August is month 8). Confirmed on live data 2026-08-26: PIID 100 has April/
// May/August 2026 rows, August is genuinely latest (Date_Updated 2026-07-14,
// vs May's 2026-05-05), but the old ordering picked "May" as this person's
// snapshot. End_num decides first; End_Date only breaks a tie within the
// same numeric month (e.g. distinguishing two half-month cutoffs).
define('_LV_EMP_DEPT_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.Department ORDER BY emp_src.PayYear DESC, emp_src.End_num DESC, emp_src.End_Date DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_POS_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.Position ORDER BY emp_src.PayYear DESC, emp_src.End_num DESC, emp_src.End_Date DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_END_DATE_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.End_Date ORDER BY emp_src.PayYear DESC, emp_src.End_num DESC, emp_src.End_Date DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_PAYYEAR_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.PayYear ORDER BY emp_src.PayYear DESC, emp_src.End_num DESC, emp_src.End_Date DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_STATUS_EXPR',
    "(SELECT sr.Status
      FROM tbl_service_record sr
      WHERE sr.PIID = pi.PIID
        AND sr.Status IS NOT NULL
        AND sr.Status <> ''
      ORDER BY COALESCE(sr.Inc_Date_To, sr.Inc_Date_from, sr.Date) DESC, sr.Inc_Date_from DESC
      LIMIT 1)"
);
define('_LV_EMP_ROSTER_TABLE', 'tbl_syl_leave_employee_roster');
define('_LV_EMP_ROSTER_LOCK', 'lv_emp_roster_refresh');
define('_LV_EMP_ROSTER_TTL_SECONDS', 21600);
define('_LV_EMP_ROSTER_SOURCE_VERSION', '6');
define('_LV_MONTHLY_LEAVE_EARNED_LOCK', 'lv_monthly_leave_earned');
define('_LV_MONTHLY_LEAVE_EARNED_EVENT', 'ev_monthly_leave_earned');
define('_LV_MONTHLY_LEAVE_EARNED_PROC', 'sp_insert_monthly_leave_earned');
define('_LV_MONTHLY_LEAVE_EARNED_TRACKER', 'tbl_syl_leave_monthly_jobs');
define('_LV_LEAVE_AUDIT_LOG_TABLE', 'tbl_syl_leave_audit_log');
define('_LV_ELIGIBILITY_TABLE', 'tbl_syl_leave_eligibility');
  define('_LV_RECALC_STATUS_TABLE', 'tbl_syl_leave_recalc_status');
  define('_LV_DEFAULT_MANDATORY_FORCED_LEAVE', 5.0);
  define('_LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE', 3.0);

function lv_ensure_available_balance_schema(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $stmt = $conn->query("SHOW COLUMNS FROM tbl_syl_leave_available_balance LIKE 'NoAvailSLManual'");
    if (!$stmt->fetch()) {
        $conn->exec(
            "ALTER TABLE tbl_syl_leave_available_balance
             ADD COLUMN NoAvailSLManual TINYINT(1) NOT NULL DEFAULT 0 AFTER NoAvailSL"
        );
    }

    $stmt = $conn->query("SHOW COLUMNS FROM tbl_syl_leave_available_balance LIKE 'NoAvailSLManualSetAt'");
    if (!$stmt->fetch()) {
        $conn->exec(
            "ALTER TABLE tbl_syl_leave_available_balance
             ADD COLUMN NoAvailSLManualSetAt DATETIME NULL AFTER NoAvailSLManual"
        );
    }

    $ensured = true;
}

function lv_special_leave_manual_override(array $balance): bool {
    return (int)($balance['NoAvailSLManual'] ?? 0) === 1
        && trim((string)($balance['NoAvailSLManualSetAt'] ?? '')) !== '';
}

function lv_employee_ref_secret(): string {
    $secret = (string)(getenv('APP_KEY') ?: getenv('JWT_SECRET') ?: getenv('DB_PASS') ?: '');
    return $secret !== '' ? $secret : 'hrmis-leave-employee-ref';
}

function lv_employee_ref(string $piid): string {
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $piid, lv_employee_ref_secret(), true)), '+/', '-_'), '=');
}

function lv_resolve_employee_ref(PDO $conn, string $ref): ?string {
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }

    lv_ensure_employee_roster($conn);
    $stmt = $conn->query('SELECT PIID FROM ' . _LV_EMP_ROSTER_TABLE);
    while (($piid = $stmt->fetchColumn()) !== false) {
        $piid = (string)$piid;
        if (hash_equals(lv_employee_ref($piid), $ref)) {
            return $piid;
        }
    }

    return null;
}

function lv_normalize_mandatory_forced_leave($value): float {
    $num = (float)$value;
    return $num > 0 ? $num : _LV_DEFAULT_MANDATORY_FORCED_LEAVE;
}

function lv_normalize_employee_status(?string $service_status, bool $is_casual): string {
    $status = strtoupper(trim((string)$service_status));

    if ($status === 'REGULAR' || $status === 'PERMANENT') {
        return 'Permanent';
    }
    if ($status === 'CASUAL') {
        return 'Casual';
    }

    if ($status !== '') {
        return ucwords(strtolower($status));
    }

    return $is_casual ? 'Casual' : 'Not Classified';
}

// ── HR-managed Employment & Leave Eligibility ──────────────────────────────
// tbl_syl_leave_eligibility holds only rows HR has explicitly saved (Source,
// UpdatedBy, UpdatedAt always reflect an actual HR action). Employees without
// a row here are never auto-seeded — their effective state is computed live
// by lv_eligibility_suggestion() every time, so "no row" always means
// "unverified", distinguishable from "HR verified and eligible".

function lv_ensure_eligibility_schema(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `" . _LV_ELIGIBILITY_TABLE . "` (
            `PIID` varchar(20) NOT NULL,
            `EmploymentType` varchar(50) NOT NULL DEFAULT '',
            `LeaveEligible` tinyint(1) NOT NULL DEFAULT 0,
            `EffectiveDate` date NOT NULL,
            `EndDate` date DEFAULT NULL,
            `Source` varchar(100) NOT NULL DEFAULT '',
            `UpdatedBy` varchar(100) NOT NULL DEFAULT '',
            `UpdatedAt` datetime NOT NULL,
            PRIMARY KEY (`PIID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

/**
 * Auto-suggested employment type + leave eligibility for an employee with no
 * (or no currently-effective) HR eligibility record. Mirrors
 * lv_normalize_employee_status()'s precedence, plus a grandfather rule: an
 * otherwise-unclassified employee who already has leave ledger/application
 * history is treated as eligible (their history is itself strong evidence),
 * while one with neither a status nor history is held back as ineligible
 * until HR reviews them.
 */
function lv_eligibility_suggestion(?string $raw_service_status, bool $is_casual, bool $has_history): array {
    $status = strtoupper(trim((string)$raw_service_status));

    if ($status === 'REGULAR' || $status === 'PERMANENT') {
        return ['employment_type' => 'Permanent', 'leave_eligible' => true, 'basis' => 'Service record status'];
    }
    if ($is_casual) {
        return ['employment_type' => 'Casual', 'leave_eligible' => true, 'basis' => 'Casual payroll'];
    }
    if ($status !== '') {
        return ['employment_type' => ucwords(strtolower($status)), 'leave_eligible' => true, 'basis' => 'Service record status'];
    }
    if ($has_history) {
        return ['employment_type' => 'Not yet classified', 'leave_eligible' => true, 'basis' => 'Existing leave ledger/application history'];
    }
    return ['employment_type' => 'Not yet classified', 'leave_eligible' => false, 'basis' => 'No service record status or leave history on file'];
}

function lv_employee_has_leave_history(PDO $conn, string $piid): bool {
    $stmt = $conn->prepare(
        "SELECT
            EXISTS(SELECT 1 FROM tbl_syl_leave_form WHERE PIID = :p1 AND isDeleted = 0)
            OR EXISTS(SELECT 1 FROM tbl_leave_applications WHERE piid = :p2) AS has_history"
    );
    $stmt->execute([':p1' => $piid, ':p2' => $piid]);
    return (bool)$stmt->fetchColumn();
}

/** Batch version of lv_employee_has_leave_history() for a page of PIIDs. Returns [PIID => bool]. */
function lv_batch_leave_history(PDO $conn, array $piids): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), fn($p) => $p !== '')));
    $result = array_fill_keys($piids, false);
    if (!$piids) {
        return $result;
    }

    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    foreach (['tbl_syl_leave_form' => 'PIID', 'tbl_leave_applications' => 'piid'] as $table => $col) {
        $extra = $table === 'tbl_syl_leave_form' ? ' AND isDeleted = 0' : '';
        $stmt = $conn->prepare("SELECT DISTINCT $col FROM $table WHERE $col IN ($placeholders)$extra");
        $stmt->execute($piids);
        while (($piid = $stmt->fetchColumn()) !== false) {
            $result[(string)$piid] = true;
        }
    }

    return $result;
}

function lv_get_eligibility_record(PDO $conn, string $piid): ?array {
    lv_ensure_eligibility_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM ' . _LV_ELIGIBILITY_TABLE . ' WHERE PIID = :piid LIMIT 1');
    $stmt->execute([':piid' => $piid]);
    return $stmt->fetch() ?: null;
}

/** Batch version of lv_get_eligibility_record() for a page of PIIDs. Returns [PIID => row]. */
function lv_batch_eligibility_records(PDO $conn, array $piids): array {
    lv_ensure_eligibility_schema($conn);
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), fn($p) => $p !== '')));
    if (!$piids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare('SELECT * FROM ' . _LV_ELIGIBILITY_TABLE . ' WHERE PIID IN (' . $placeholders . ')');
    $stmt->execute($piids);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string)$row['PIID']] = $row;
    }
    return $out;
}

function lv_eligibility_record_in_effect(?array $hr_record): bool {
    if (!$hr_record) {
        return false;
    }
    $today = date('Y-m-d');
    $effective = trim((string)($hr_record['EffectiveDate'] ?? ''));
    if ($effective === '' || $today < $effective) {
        return false;
    }
    $end = trim((string)($hr_record['EndDate'] ?? ''));
    if ($end !== '' && $today > $end) {
        return false;
    }
    return true;
}

/**
 * Resolves the effective employment type + leave eligibility for one employee,
 * combining any in-window HR record with the live auto-suggestion.
 */
function lv_resolve_eligibility(PDO $conn, string $piid, ?string $raw_service_status, bool $is_casual, ?array $hr_record = null): array {
    if ($hr_record === null) {
        $hr_record = lv_get_eligibility_record($conn, $piid);
    }
    $has_history = lv_employee_has_leave_history($conn, $piid);
    $suggested = lv_eligibility_suggestion($raw_service_status, $is_casual, $has_history);
    $verified = lv_eligibility_record_in_effect($hr_record);

    return [
        'employment_type' => $verified ? (string)$hr_record['EmploymentType'] : $suggested['employment_type'],
        'leave_eligible'  => $verified ? (bool)$hr_record['LeaveEligible'] : $suggested['leave_eligible'],
        'is_hr_verified'  => $verified,
        'hr_record'       => $hr_record,
        'suggested'       => $suggested,
    ];
}

/**
 * Batch version of lv_resolve_eligibility() for a page of roster rows.
 * $rows must contain PIID, Status (raw), IsCasual for each employee.
 */
function lv_batch_resolve_eligibility(PDO $conn, array $rows): array {
    $piids = array_map(fn($r) => (string)($r['PIID'] ?? ''), $rows);
    $hrRecords = lv_batch_eligibility_records($conn, $piids);
    $historyByPiid = lv_batch_leave_history($conn, $piids);

    $out = [];
    foreach ($rows as $row) {
        $piid = (string)($row['PIID'] ?? '');
        if ($piid === '') {
            continue;
        }
        $hrRecord = $hrRecords[$piid] ?? null;
        $suggested = lv_eligibility_suggestion($row['Status'] ?? null, !empty($row['IsCasual']), $historyByPiid[$piid] ?? false);
        $verified = lv_eligibility_record_in_effect($hrRecord);
        $out[$piid] = [
            'employment_type' => $verified ? (string)$hrRecord['EmploymentType'] : $suggested['employment_type'],
            'leave_eligible'  => $verified ? (bool)$hrRecord['LeaveEligible'] : $suggested['leave_eligible'],
            'is_hr_verified'  => $verified,
            'hr_record'       => $hrRecord,
            'suggested'       => $suggested,
        ];
    }
    return $out;
}

/**
 * Upserts the HR-managed eligibility record for one employee.
 */
function lv_save_eligibility(PDO $conn, string $piid, string $employment_type, bool $leave_eligible, string $effective_date, ?string $end_date, string $source, string $actor): void {
    lv_ensure_eligibility_schema($conn);
    $stmt = $conn->prepare(
        'INSERT INTO ' . _LV_ELIGIBILITY_TABLE . '
            (PIID, EmploymentType, LeaveEligible, EffectiveDate, EndDate, Source, UpdatedBy, UpdatedAt)
         VALUES
            (:piid, :employment_type, :leave_eligible, :effective_date, :end_date, :source, :updated_by, NOW())
         ON DUPLICATE KEY UPDATE
            EmploymentType = VALUES(EmploymentType),
            LeaveEligible = VALUES(LeaveEligible),
            EffectiveDate = VALUES(EffectiveDate),
            EndDate = VALUES(EndDate),
            Source = VALUES(Source),
            UpdatedBy = VALUES(UpdatedBy),
            UpdatedAt = VALUES(UpdatedAt)'
    );
    $stmt->execute([
        ':piid' => $piid,
        ':employment_type' => $employment_type,
        ':leave_eligible' => $leave_eligible ? 1 : 0,
        ':effective_date' => $effective_date,
        ':end_date' => ($end_date !== null && trim($end_date) !== '') ? $end_date : null,
        ':source' => $source,
        ':updated_by' => substr(trim($actor) !== '' ? trim($actor) : 'HRMIS', 0, 100),
    ]);
}

function lv_department_match_key_sql(string $expr): string {
    $normalized = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE($expr, ''))), '&', 'AND'), '.', ''), ',', ''), '-', ''), '/', ''), '(', ''), ')', ''), ' ', ''), '''', '')";
    return "REPLACE($normalized, 'CITYACCOUNTANTSOFFICE', 'CITYACCOUNTING')";
}

function lv_department_canonical_name_sql(string $expr): string {
    $deptKey = lv_department_match_key_sql($expr);
    return "CASE
        WHEN $deptKey IN ('CITYACCOUNTING', 'CITYACCOUNTINGOFFICE') THEN 'CITY ACCOUNTANT''S OFFICE'
        ELSE UPPER(TRIM(COALESCE($expr, '')))
    END";
}

function lv_regular_department_match_join_sql(string $source_expr, string $alias = 'tp_match'): string {
    $regular_key = lv_department_match_key_sql('tp_norm.Name');
    $source_key = lv_department_match_key_sql($source_expr);

    return "LEFT JOIN (
                SELECT DeptKey, MIN(Name) AS Name
                FROM (
                    SELECT tp_norm.Name, $regular_key AS DeptKey
                    FROM tbl_template_payroll tp_norm
                    WHERE tp_norm.isDeleted = 0
                ) regular_map
                WHERE DeptKey <> ''
                GROUP BY DeptKey
            ) $alias ON $alias.DeptKey = $source_key";
}

function lv_employee_roster_create_sql(string $table): string {
    return "CREATE TABLE IF NOT EXISTS `$table` (
        `PIID` varchar(20) NOT NULL,
        `ID_NUM` varchar(20) DEFAULT NULL,
        `Surname` varchar(80) DEFAULT NULL,
        `Firstname` varchar(80) DEFAULT NULL,
        `MiddleName` varchar(80) DEFAULT NULL,
        `Department` varchar(255) DEFAULT NULL,
        `Position` varchar(255) DEFAULT NULL,
        `IsCasual` tinyint(1) NOT NULL DEFAULT 0,
        `End_Date` varchar(255) DEFAULT NULL,
        `PayYear` varchar(20) DEFAULT NULL,
        `Status` varchar(255) DEFAULT NULL,
        `FirstGovServiceDate` date DEFAULT NULL,
        `SourceFingerprint` varchar(64) DEFAULT NULL,
        `SnapshotYear` int(11) NOT NULL,
        `SnapshotMonth` int(11) NOT NULL,
        `RefreshedAt` datetime NOT NULL,
        PRIMARY KEY (`PIID`),
        KEY `idx_lv_roster_name` (`Surname`,`Firstname`),
        KEY `idx_lv_roster_firstname` (`Firstname`,`Surname`),
        KEY `idx_lv_roster_department` (`Department`),
        KEY `idx_lv_roster_snapshot` (`SnapshotYear`,`SnapshotMonth`,`RefreshedAt`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
}

function lv_employee_roster_ensure_schema(PDO $conn, ?string $table = null): void {
    $table = $table ?: _LV_EMP_ROSTER_TABLE;
    $conn->exec(lv_employee_roster_create_sql($table));
    $col_stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $has_column = function (string $column) use ($col_stmt, $table): bool {
        $col_stmt->execute([$table, $column]);
        return (int)$col_stmt->fetchColumn() > 0;
    };
    $add_column = function (string $column, string $definition) use ($conn, $table, $has_column): void {
        if (!$has_column($column)) {
            $conn->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $definition);
        }
    };

    $add_column('ID_NUM', '`ID_NUM` varchar(20) DEFAULT NULL AFTER `PIID`');
    $add_column('Surname', '`Surname` varchar(80) DEFAULT NULL AFTER `ID_NUM`');
    $add_column('Firstname', '`Firstname` varchar(80) DEFAULT NULL AFTER `Surname`');
    $add_column('MiddleName', '`MiddleName` varchar(80) DEFAULT NULL AFTER `Firstname`');
    $add_column('Department', '`Department` varchar(255) DEFAULT NULL AFTER `MiddleName`');
    $add_column('Position', '`Position` varchar(255) DEFAULT NULL AFTER `Department`');
    $add_column('IsCasual', '`IsCasual` tinyint(1) NOT NULL DEFAULT 0 AFTER `Position`');
    $add_column('End_Date', '`End_Date` varchar(255) DEFAULT NULL AFTER `IsCasual`');
    $add_column('PayYear', '`PayYear` varchar(20) DEFAULT NULL AFTER `End_Date`');
    $add_column('Status', '`Status` varchar(255) DEFAULT NULL AFTER `PayYear`');
    $add_column('FirstGovServiceDate', '`FirstGovServiceDate` date DEFAULT NULL AFTER `Status`');
    $add_column('SourceFingerprint', '`SourceFingerprint` varchar(64) DEFAULT NULL AFTER `FirstGovServiceDate`');
    $add_column('SnapshotYear', '`SnapshotYear` int(11) NOT NULL DEFAULT 0 AFTER `SourceFingerprint`');
    $add_column('SnapshotMonth', '`SnapshotMonth` int(11) NOT NULL DEFAULT 0 AFTER `SnapshotYear`');
    $add_column('RefreshedAt', '`RefreshedAt` datetime NULL DEFAULT NULL AFTER `SnapshotMonth`');
}

function lv_monthly_leave_earned_tracker_ensure_schema(PDO $conn): void {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `" . _LV_MONTHLY_LEAVE_EARNED_TRACKER . "` (
            `JobKey` varchar(80) NOT NULL,
            `JobPeriod` char(7) NOT NULL,
            `RanAt` datetime NOT NULL,
            `RunSource` varchar(40) NOT NULL,
            PRIMARY KEY (`JobKey`, `JobPeriod`),
            KEY `idx_lv_monthly_jobs_ran_at` (`RanAt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
}

function lv_ensure_leave_audit_log_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `" . _LV_LEAVE_AUDIT_LOG_TABLE . "` (
            `AuditID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `LID` int(11) NOT NULL,
            `PIID` varchar(50) NOT NULL DEFAULT '',
            `Action` varchar(40) NOT NULL,
            `Actor` varchar(100) NOT NULL DEFAULT '',
            `BeforeData` mediumtext NULL,
            `AfterData` mediumtext NULL,
            `CreatedAt` datetime NOT NULL,
            PRIMARY KEY (`AuditID`),
            KEY `idx_lv_audit_lid` (`LID`),
            KEY `idx_lv_audit_piid` (`PIID`),
            KEY `idx_lv_audit_action` (`Action`),
            KEY `idx_lv_audit_created_at` (`CreatedAt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $ensured = true;
}

function lv_audit_record_action(PDO $conn, string $action, int $lid, string $piid, ?array $before, ?array $after, string $actor): void {
    lv_ensure_leave_audit_log_table($conn);

    $encode = static function (?array $data): ?string {
        if ($data === null) {
            return null;
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };

    $stmt = $conn->prepare(
        "INSERT INTO `" . _LV_LEAVE_AUDIT_LOG_TABLE . "`
            (`LID`, `PIID`, `Action`, `Actor`, `BeforeData`, `AfterData`, `CreatedAt`)
         VALUES
            (:lid, :piid, :action, :actor, :before_data, :after_data, NOW())"
    );
    $stmt->execute([
        ':lid' => $lid,
        ':piid' => $piid,
        ':action' => $action,
        ':actor' => substr(trim($actor) !== '' ? trim($actor) : 'HRMIS', 0, 100),
        ':before_data' => $encode($before),
        ':after_data' => $encode($after),
    ]);
}

function lv_monthly_leave_earned_procedure_sql(): string {
    return <<<SQL
CREATE PROCEDURE sp_insert_monthly_leave_earned()
BEGIN
    DECLARE v_done INT DEFAULT FALSE;
    DECLARE v_piid VARCHAR(50);
    DECLARE v_vac_bal DOUBLE DEFAULT 0;
    DECLARE v_sick_bal DOUBLE DEFAULT 0;
    DECLARE v_earn_rate DOUBLE DEFAULT 1.25;
    DECLARE v_last_day INT;
    DECLARE v_month_pad VARCHAR(2);
    DECLARE v_from_date DATE;
    DECLARE v_to_date DATE;
    DECLARE v_date_action VARCHAR(50);
    DECLARE v_cur_month INT;
    DECLARE v_max_month INT;
    DECLARE v_cur_year INT;
    DECLARE v_exists INT;
    DECLARE v_deleted_exists INT;
    DECLARE v_sync_vac DOUBLE;
    DECLARE v_sync_sick DOUBLE;
    DECLARE v_is_casual TINYINT;
    DECLARE v_service_status VARCHAR(100);
    DECLARE v_has_history TINYINT;
    DECLARE v_hr_eligible TINYINT;
    DECLARE v_hr_effective DATE;
    DECLARE v_hr_end DATE;
    DECLARE v_eligible TINYINT;

    DECLARE emp_cur CURSOR FOR
        SELECT DISTINCT eligible.PIID,
               COALESCE(roster.IsCasual, 0),
               UPPER(TRIM(COALESCE(roster.Status, ''))),
               IF(hist_l.PIID IS NOT NULL OR hist_a.piid IS NOT NULL, 1, 0)
        FROM (
            SELECT p.PIID
            FROM tbl_syl_payroll_parent pp
            INNER JOIN tbl_template_payroll tp
                    ON pp.TID = tp.TID
                   AND tp.isDeleted = 0
            INNER JOIN tblpersonalinformation p ON pp.PIID = p.PIID
            WHERE pp.isDeleted = 0
              AND pp.Quencina = 'whole'
              AND pp.year <= YEAR(NOW())
              AND tp.NAME NOT LIKE '%JOB ORDER%'
              AND tp.NAME NOT LIKE '%JO%'
              AND tp.NAME NOT LIKE '%J.O%'

            UNION

            SELECT p.PIID
            FROM tbl_syl_payroll_parent_casual pc
            INNER JOIN tbl_template_payroll_casual tpc
                    ON pc.TID = tpc.TID
                   AND tpc.isDeleted = 0
            INNER JOIN tblpersonalinformation p ON pc.PIID = p.PIID
            WHERE pc.isDeleted = 0
              AND pc.Quencina = 'whole'
              AND pc.year <= YEAR(NOW())
              AND tpc.NAME NOT LIKE '%JOB ORDER%'
              AND tpc.NAME NOT LIKE '%JO%'
              AND tpc.NAME NOT LIKE '%J.O%'
        ) eligible
        LEFT JOIN tbl_syl_leave_employee_roster roster ON roster.PIID = eligible.PIID
        LEFT JOIN (SELECT DISTINCT PIID FROM tbl_syl_leave_form WHERE isDeleted = 0) hist_l ON hist_l.PIID = eligible.PIID
        LEFT JOIN (SELECT DISTINCT piid FROM tbl_leave_applications) hist_a ON hist_a.piid = eligible.PIID
        WHERE eligible.PIID IS NOT NULL;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;

    SELECT COALESCE(MAX(Leave_Credits_Earned), 1.25)
    INTO v_earn_rate
    FROM tbl_syl_leave_credits_earned;

    SET v_max_month = MONTH(NOW());
    SET v_cur_year = YEAR(NOW());

    OPEN emp_cur;

    emp_loop: LOOP
        FETCH emp_cur INTO v_piid, v_is_casual, v_service_status, v_has_history;
        IF v_done THEN
            LEAVE emp_loop;
        END IF;

        -- Effective leave eligibility: an in-window HR override (tbl_syl_leave_eligibility)
        -- wins; otherwise fall back to the same auto-suggestion precedence as
        -- lv_eligibility_suggestion() in leave_db.php (service status, then casual
        -- payroll, then existing leave history as a grandfather signal).
        SET v_hr_eligible = NULL;
        SET v_hr_effective = NULL;
        SET v_hr_end = NULL;
        SELECT MAX(LeaveEligible), MAX(EffectiveDate), MAX(EndDate)
        INTO v_hr_eligible, v_hr_effective, v_hr_end
        FROM tbl_syl_leave_eligibility
        WHERE PIID = v_piid;

        IF v_hr_eligible IS NOT NULL AND v_hr_effective IS NOT NULL
           AND CURDATE() >= v_hr_effective AND (v_hr_end IS NULL OR CURDATE() <= v_hr_end) THEN
            SET v_eligible = v_hr_eligible;
        ELSEIF v_service_status = 'REGULAR' OR v_service_status = 'PERMANENT' THEN
            SET v_eligible = 1;
        ELSEIF v_is_casual = 1 THEN
            SET v_eligible = 1;
        ELSEIF v_has_history = 1 THEN
            SET v_eligible = 1;
        ELSE
            SET v_eligible = 0;
        END IF;

        IF v_eligible = 0 THEN
            ITERATE emp_loop;
        END IF;

        SELECT COALESCE(MAX(cBackVaca), 0)
        INTO v_vac_bal
        FROM tbl_syl_leave_available_balance
        WHERE piid = v_piid AND year = v_cur_year;

        SELECT COALESCE(MAX(cBalSick), 0)
        INTO v_sick_bal
        FROM tbl_syl_leave_available_balance
        WHERE piid = v_piid AND year = v_cur_year;

        SET v_cur_month = 1;

        month_loop: WHILE v_cur_month <= v_max_month DO
            SET v_month_pad = LPAD(v_cur_month, 2, '0');
            SET v_from_date = STR_TO_DATE(CONCAT(v_cur_year, '-', v_month_pad, '-01'), '%Y-%m-%d');
            SET v_to_date = LAST_DAY(v_from_date);
            SET v_last_day = DAY(v_to_date);
            SET v_date_action = CONCAT(
                ELT(v_cur_month, 'JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'),
                '.01-', v_last_day, ', ', v_cur_year
            );

            SELECT COUNT(1) INTO v_exists
            FROM tbl_syl_leave_form
            WHERE Type_of_Records LIKE '%LEAVE EARNED%'
              AND Particulars LIKE 'LEAVE EARNED%'
              AND isDeleted = 0
              AND PIID = v_piid
              AND Date_of_Filing BETWEEN v_from_date AND v_to_date;

            SELECT COUNT(1) INTO v_deleted_exists
            FROM tbl_syl_leave_form
            WHERE Type_of_Records LIKE '%LEAVE EARNED%'
              AND Particulars LIKE 'LEAVE EARNED%'
              AND isDeleted <> 0
              AND PIID = v_piid
              AND Date_of_Filing BETWEEN v_from_date AND v_to_date;

            IF v_exists > 0 THEN
                SELECT VacBal, SickBal
                INTO v_sync_vac, v_sync_sick
                FROM tbl_syl_leave_form
                WHERE Type_of_Records LIKE '%LEAVE EARNED%'
                  AND Particulars LIKE 'LEAVE EARNED%'
                  AND isDeleted = 0
                  AND PIID = v_piid
                  AND Date_of_Filing BETWEEN v_from_date AND v_to_date
                ORDER BY Date_of_Filing DESC, DateProcessed DESC
                LIMIT 1;

                SET v_vac_bal = v_sync_vac;
                SET v_sick_bal = v_sync_sick;
            ELSEIF v_deleted_exists > 0 THEN
                SET v_vac_bal = v_vac_bal;
                SET v_sick_bal = v_sick_bal;
            ELSE
                SET v_vac_bal = v_vac_bal + v_earn_rate;
                SET v_sick_bal = v_sick_bal + v_earn_rate;

                INSERT INTO tbl_syl_leave_form (
                    PIID, Type_of_Records,
                    Period_From, Period_To, Date_of_Filing,
                    VacEarn, VacBal,
                    SickEarn, SickBal,
                    Particulars, DateAction,
                    no_avail_VL, no_avail_SL, no_avail_SP, no_avail_P,
                    DateProcessed, RecordedBy
                ) VALUES (
                    v_piid, 'Leave Earned|-N/A-',
                    v_from_date, v_to_date, v_from_date,
                    v_earn_rate, v_vac_bal,
                    v_earn_rate, v_sick_bal,
                    CONCAT('LEAVE EARNED ', v_cur_year), v_date_action,
                    0, 0, 0, 0,
                    v_from_date, 'Added by System'
                );
            END IF;

            SET v_cur_month = v_cur_month + 1;
        END WHILE;
    END LOOP;

    CLOSE emp_cur;
END
SQL;
}

function lv_monthly_leave_earned_event_starts(): string {
    $starts = new DateTimeImmutable('first day of next month 00:01:00');
    return $starts->format('Y-m-d H:i:s');
}

function lv_monthly_leave_earned_ensure_procedure(PDO $conn): void {
    $conn->exec('DROP PROCEDURE IF EXISTS ' . _LV_MONTHLY_LEAVE_EARNED_PROC);
    $conn->exec(lv_monthly_leave_earned_procedure_sql());
}

function lv_monthly_leave_earned_ensure_event(PDO $conn): void {
    $conn->exec('DROP EVENT IF EXISTS ' . _LV_MONTHLY_LEAVE_EARNED_EVENT);
    $conn->exec(
        "CREATE EVENT " . _LV_MONTHLY_LEAVE_EARNED_EVENT . "
            ON SCHEDULE EVERY 1 MONTH
            STARTS '" . lv_monthly_leave_earned_event_starts() . "'
            ON COMPLETION PRESERVE
            ENABLE
            COMMENT 'Auto-inserts Leave Earned for all regular employees; backfills missing months'
            DO CALL " . _LV_MONTHLY_LEAVE_EARNED_PROC . "()"
    );
}

function lv_monthly_leave_earned_has_run(PDO $conn, string $job_period): bool {
    lv_monthly_leave_earned_tracker_ensure_schema($conn);
    $stmt = $conn->prepare(
        'SELECT 1
         FROM ' . _LV_MONTHLY_LEAVE_EARNED_TRACKER . '
         WHERE JobKey = ?
           AND JobPeriod = ?
         LIMIT 1'
    );
    $stmt->execute([_LV_MONTHLY_LEAVE_EARNED_PROC, $job_period]);
    return (bool)$stmt->fetchColumn();
}

function lv_monthly_leave_earned_mark_run(PDO $conn, string $job_period, string $source): void {
    $stmt = $conn->prepare(
        'INSERT INTO ' . _LV_MONTHLY_LEAVE_EARNED_TRACKER . '
            (JobKey, JobPeriod, RanAt, RunSource)
         VALUES
            (:job_key, :job_period, NOW(), :run_source)
         ON DUPLICATE KEY UPDATE
            RanAt = VALUES(RanAt),
            RunSource = VALUES(RunSource)'
    );
    $stmt->execute([
        ':job_key' => _LV_MONTHLY_LEAVE_EARNED_PROC,
        ':job_period' => $job_period,
        ':run_source' => $source,
    ]);
}

function lv_monthly_leave_earned_run_if_due(PDO $conn): void {
    $job_period = date('Y-m');
    if (lv_monthly_leave_earned_has_run($conn, $job_period)) {
        return;
    }

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(?, 5)');
    $lock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        return;
    }

    try {
        if (lv_monthly_leave_earned_has_run($conn, $job_period)) {
            return;
        }
        $conn->exec('CALL ' . _LV_MONTHLY_LEAVE_EARNED_PROC . '()');
        lv_monthly_leave_earned_mark_run($conn, $job_period, 'php-fallback');
    } finally {
        $unlock_stmt = $conn->prepare('DO RELEASE_LOCK(?)');
        $unlock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    }
}

function lv_monthly_leave_earned_run_now(PDO $conn, string $source = 'manual'): void {
    lv_monthly_leave_earned_tracker_ensure_schema($conn);
    lv_monthly_leave_earned_ensure_procedure($conn);

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(?, 30)');
    $lock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Monthly leave earned job is already running');
    }

    try {
        $conn->exec('CALL ' . _LV_MONTHLY_LEAVE_EARNED_PROC . '()');
        lv_monthly_leave_earned_mark_run($conn, date('Y-m'), $source);
    } finally {
        $unlock_stmt = $conn->prepare('DO RELEASE_LOCK(?)');
        $unlock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    }
}

function lv_backfill_employee_leave_earned(PDO $conn, string $piid, int $year): int {
    $piid = trim($piid);
    if ($piid === '') {
        throw new InvalidArgumentException('PIID required');
    }

    $earn_rate = (float)$conn->query(
        'SELECT COALESCE(MAX(Leave_Credits_Earned), 1.25)
         FROM tbl_syl_leave_credits_earned'
    )->fetchColumn();
    if ($earn_rate <= 0) {
        $earn_rate = 1.25;
    }

    $max_month = $year === (int)date('Y') ? (int)date('n') : 12;
    $exists_stmt = $conn->prepare(
        "SELECT COUNT(1)
          FROM tbl_syl_leave_form
         WHERE Type_of_Records LIKE '%LEAVE EARNED%'
           AND Particulars LIKE 'LEAVE EARNED%'
            AND PIID = :piid
            AND Date_of_Filing BETWEEN :from_date AND :to_date"
    );
    $insert_stmt = $conn->prepare(
        "INSERT INTO tbl_syl_leave_form (
            PIID, Type_of_Records,
            Period_From, Period_To, Date_of_Filing,
            VacEarn, VacBal,
            SickEarn, SickBal,
            Particulars, DateAction,
            no_avail_VL, no_avail_SL, no_avail_SP, no_avail_P,
            DateProcessed, RecordedBy
        ) VALUES (
            :piid, 'Leave Earned|-N/A-',
            :period_from, :period_to, :date_filing,
            :vac_earn, 0,
            :sick_earn, 0,
            :particulars, :date_action,
            0, 0, 0, 0,
            :date_processed, 'Added by System'
        )"
    );

    $inserted = 0;
    for ($month = 1; $month <= $max_month; $month++) {
        $from_date = sprintf('%04d-%02d-01', $year, $month);
        $to_date = date('Y-m-t', strtotime($from_date));
        $exists_stmt->execute([
            ':piid' => $piid,
            ':from_date' => $from_date,
            ':to_date' => $to_date,
        ]);
        if ((int)$exists_stmt->fetchColumn() > 0) {
            continue;
        }

        $insert_stmt->execute([
            ':piid' => $piid,
            ':period_from' => $from_date,
            ':period_to' => $to_date,
            ':date_filing' => $from_date,
            ':vac_earn' => $earn_rate,
            ':sick_earn' => $earn_rate,
            ':particulars' => 'LEAVE EARNED ' . $year,
            ':date_action' => strtoupper(date('M', strtotime($from_date))) . '.01-' . date('j', strtotime($to_date)) . ', ' . $year,
            ':date_processed' => $from_date,
        ]);
        $inserted++;
    }

    if ($inserted > 0) {
        lv_recalculate($conn, $piid, $year, 'leave-earned-backfill');
    }

    return $inserted;
}

function lv_leave_earned_month_row(PDO $conn, string $piid, int $year, int $month, ?bool $deleted): ?array {
    $from_date = sprintf('%04d-%02d-01', $year, $month);
    $to_date = date('Y-m-t', strtotime($from_date));
    $whereDeleted = '';
    if ($deleted === true) {
        $whereDeleted = 'AND isDeleted <> 0';
    } elseif ($deleted === false) {
        $whereDeleted = 'AND isDeleted = 0';
    }

    $stmt = $conn->prepare(
        "SELECT *
           FROM tbl_syl_leave_form
          WHERE Type_of_Records LIKE '%LEAVE EARNED%'
            AND Particulars LIKE 'LEAVE EARNED%'
            AND PIID = :piid
            AND Date_of_Filing BETWEEN :from_date AND :to_date
            $whereDeleted
          ORDER BY LID DESC
          LIMIT 1"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':from_date' => $from_date,
        ':to_date' => $to_date,
    ]);
    return $stmt->fetch() ?: null;
}

function lv_add_employee_leave_earned_months(PDO $conn, string $piid, int $year, array $months, string $actor): array {
    $piid = trim($piid);
    if ($piid === '') {
        throw new InvalidArgumentException('PIID required');
    }

    $months = array_values(array_unique(array_filter(array_map('intval', $months), static fn(int $month): bool => $month >= 1 && $month <= 12)));
    sort($months);
    if ($months === []) {
        throw new InvalidArgumentException('Select at least one month');
    }

    $earn_rate = lv_auto_leave_earned_cap($conn);
    $insert_stmt = $conn->prepare(
        "INSERT INTO tbl_syl_leave_form (
            PIID, Type_of_Records,
            Period_From, Period_To, Date_of_Filing,
            VacEarn, VacBal,
            SickEarn, SickBal,
            Particulars, DateAction,
            no_avail_VL, no_avail_SL, no_avail_SP, no_avail_P,
            DateProcessed, RecordedBy,
            isDeleted
        ) VALUES (
            :piid, 'Leave Earned|-N/A-',
            :period_from, :period_to, :date_filing,
            :vac_earn, 0,
            :sick_earn, 0,
            :particulars, :date_action,
            0, 0, 0, 0,
            :date_processed, :recorded_by,
            0
        )"
    );
    $restore_stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_form
            SET isDeleted = 0,
                RecordedBy = :recorded_by
          WHERE LID = :lid'
    );

    $result = [
        'inserted' => 0,
        'restored' => 0,
        'skipped' => 0,
        'months' => [],
    ];

    lv_ensure_leave_audit_log_table($conn);
    $startedTx = !$conn->inTransaction();
    if ($startedTx) {
        $conn->beginTransaction();
    }

    try {
        foreach ($months as $month) {
            $from_date = sprintf('%04d-%02d-01', $year, $month);
            $to_date = date('Y-m-t', strtotime($from_date));

            $active = lv_leave_earned_month_row($conn, $piid, $year, $month, false);
            if ($active) {
                $result['skipped']++;
                $result['months'][] = ['month' => $month, 'status' => 'skipped', 'lid' => (int)$active['LID']];
                continue;
            }

            $deleted = lv_leave_earned_month_row($conn, $piid, $year, $month, true);
            if ($deleted) {
                $restore_stmt->execute([
                    ':lid' => (int)$deleted['LID'],
                    ':recorded_by' => substr(trim($actor) !== '' ? trim($actor) : 'HRMIS', 0, 100),
                ]);
                $after = lv_get_record($conn, (int)$deleted['LID']);
                lv_audit_record_action($conn, 'leave_earned_restore', (int)$deleted['LID'], $piid, $deleted, $after, $actor);
                $result['restored']++;
                $result['months'][] = ['month' => $month, 'status' => 'restored', 'lid' => (int)$deleted['LID']];
                continue;
            }

            $insert_stmt->execute([
                ':piid' => $piid,
                ':period_from' => $from_date,
                ':period_to' => $to_date,
                ':date_filing' => $from_date,
                ':vac_earn' => $earn_rate,
                ':sick_earn' => $earn_rate,
                ':particulars' => 'LEAVE EARNED ' . $year,
                ':date_action' => strtoupper(date('M', strtotime($from_date))) . '.01-' . date('j', strtotime($to_date)) . ', ' . $year,
                ':date_processed' => $from_date,
                ':recorded_by' => substr(trim($actor) !== '' ? trim($actor) : 'HRMIS', 0, 100),
            ]);
            $lid = (int)$conn->lastInsertId();
            $after = lv_get_record($conn, $lid);
            lv_audit_record_action($conn, 'leave_earned_insert', $lid, $piid, null, $after, $actor);
            $result['inserted']++;
            $result['months'][] = ['month' => $month, 'status' => 'inserted', 'lid' => $lid];
        }

        if ($startedTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    if ($result['inserted'] > 0 || $result['restored'] > 0) {
        lv_recalculate($conn, $piid, $year, 'leave-earned-selected-months');
    }

    return $result;
}

function lv_ensure_monthly_leave_earned_automation(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    try {
        lv_monthly_leave_earned_tracker_ensure_schema($conn);
        // The recreated procedure references these tables directly (not via PHP
        // interpolation, since heredocs can't embed bare constants) — they must
        // exist before CREATE PROCEDURE's later CALL, even though MySQL won't
        // validate their existence at creation time.
        lv_ensure_eligibility_schema($conn);
        lv_ensure_employee_roster($conn);
        lv_ensure_leave_applications_table($conn);
        lv_monthly_leave_earned_ensure_procedure($conn);
        try {
            lv_monthly_leave_earned_ensure_event($conn);
        } catch (Throwable $event_error) {
            error_log('lv_monthly_leave_earned_ensure_event: ' . $event_error->getMessage());
        }
        lv_monthly_leave_earned_run_if_due($conn);
    } catch (Throwable $e) {
        error_log('lv_ensure_monthly_leave_earned_automation: ' . $e->getMessage());
    }
}

function lv_employee_roster_source_fingerprint(PDO $conn): string {
    $row = $conn->query(
        "SELECT MD5(CONCAT_WS('|',
            '" . _LV_EMP_ROSTER_SOURCE_VERSION . "',
            (SELECT COUNT(*) FROM tblpersonalinformation),
            (SELECT COALESCE(MAX(PIID), 0) FROM tblpersonalinformation),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', PIID, ID_NUM, SurName, FirstName, MiddleName))), 0) FROM tblpersonalinformation),
            (SELECT COUNT(*) FROM tbl_syl_payroll_parent WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COALESCE(MAX(CONCAT_WS('|', Date_Updated, PPID, Year, End_Num, TID)), '') FROM tbl_syl_payroll_parent WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COUNT(*) FROM tbl_syl_payroll_parent_casual WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COALESCE(MAX(CONCAT_WS('|', Date_Updated, PPID, Year, End_Num, TID)), '') FROM tbl_syl_payroll_parent_casual WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COUNT(*) FROM tbl_template_payroll),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', TID, Name, isDeleted))), 0) FROM tbl_template_payroll),
            (SELECT COUNT(*) FROM tbl_template_payroll_casual),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', TID, Name, isDeleted))), 0) FROM tbl_template_payroll_casual),
            (SELECT COUNT(*) FROM tbl_service_record),
            (SELECT COALESCE(MAX(CONCAT_WS('|', COALESCE(Last_Updated, ''), COALESCE(Inc_Date_To, ''), COALESCE(Inc_Date_from, ''), COALESCE(Date, ''), COALESCE(PIID, ''), COALESCE(Status, ''))), '') FROM tbl_service_record)
        )) AS fingerprint"
    )->fetch() ?: [];

    return (string)($row['fingerprint'] ?? '');
}

function lv_employee_roster_meta(PDO $conn): array {
    lv_employee_roster_ensure_schema($conn);
    $row = $conn->query(
        'SELECT COUNT(*) AS row_count,
                MAX(SnapshotYear) AS SnapshotYear,
                MAX(SnapshotMonth) AS SnapshotMonth,
                MAX(RefreshedAt) AS RefreshedAt,
                MAX(SourceFingerprint) AS SourceFingerprint
         FROM ' . _LV_EMP_ROSTER_TABLE
    )->fetch() ?: [];

    return [
        'row_count' => (int)($row['row_count'] ?? 0),
        'SnapshotYear' => (int)($row['SnapshotYear'] ?? 0),
        'SnapshotMonth' => (int)($row['SnapshotMonth'] ?? 0),
        'RefreshedAt' => (string)($row['RefreshedAt'] ?? ''),
        'SourceFingerprint' => (string)($row['SourceFingerprint'] ?? ''),
    ];
}

function lv_employee_roster_is_fresh(array $meta, int $target_year, int $target_month, string $source_fingerprint = ''): bool {
    if (($meta['row_count'] ?? 0) <= 0) {
        return false;
    }
    if ((int)($meta['SnapshotYear'] ?? 0) !== $target_year) {
        return false;
    }
    if ((int)($meta['SnapshotMonth'] ?? 0) !== $target_month) {
        return false;
    }

    $refreshed_at = trim((string)($meta['RefreshedAt'] ?? ''));
    if ($refreshed_at === '') {
        return false;
    }

    $ts = strtotime($refreshed_at);
    if ($ts === false) {
        return false;
    }

    if ($source_fingerprint !== '' && ($meta['SourceFingerprint'] ?? '') !== '') {
        if (!hash_equals((string)$meta['SourceFingerprint'], $source_fingerprint)) {
            return false;
        }
    }

    return (time() - $ts) <= _LV_EMP_ROSTER_TTL_SECONDS;
}

function lv_employee_roster_refresh(PDO $conn, int $target_year, int $target_month, ?string $source_fingerprint = null): void {
    lv_employee_roster_ensure_schema($conn);

    $shadow = _LV_EMP_ROSTER_TABLE . '_shadow';
    $swap   = _LV_EMP_ROSTER_TABLE . '_old';
    $source_fingerprint = $source_fingerprint ?? lv_employee_roster_source_fingerprint($conn);

    $conn->exec('DROP TABLE IF EXISTS `' . $shadow . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $swap . '`');
    $conn->exec('CREATE TABLE `' . $shadow . '` LIKE `' . _LV_EMP_ROSTER_TABLE . '`');

    $join = lv_emp_join_sql($target_year, $target_month);
    $sql = 'INSERT INTO `' . $shadow . '`
                (PIID, ID_NUM, Surname, Firstname, MiddleName, Department, Position,
                 IsCasual, End_Date, PayYear, Status, FirstGovServiceDate, SourceFingerprint,
                 SnapshotYear, SnapshotMonth, RefreshedAt)
            SELECT CAST(pi.PIID AS CHAR(20)) AS PIID,
                   MIN(pi.ID_NUM) AS ID_NUM,
                   MIN(pi.SurName) AS Surname,
                   MIN(pi.FirstName) AS Firstname,
                   MIN(pi.MiddleName) AS MiddleName,
                   ' . _LV_EMP_DEPT_EXPR . ' AS Department,
                   ' . _LV_EMP_POS_EXPR . ' AS Position,
                   MAX(emp_src.IsCasual) AS IsCasual,
                   ' . _LV_EMP_END_DATE_EXPR . ' AS End_Date,
                   ' . _LV_EMP_PAYYEAR_EXPR . ' AS PayYear,
                   ' . _LV_EMP_STATUS_EXPR . ' AS Status,
                   (SELECT MIN(COALESCE(sr.Inc_Date_from, sr.Date))
                    FROM tbl_service_record sr
                    WHERE sr.PIID = pi.PIID
                      AND COALESCE(sr.Inc_Date_from, sr.Date) IS NOT NULL) AS FirstGovServiceDate,
                   :source_fingerprint AS SourceFingerprint,
                   :snapshot_year AS SnapshotYear,
                   :snapshot_month AS SnapshotMonth,
                   NOW() AS RefreshedAt
            FROM ' . $join['sql'] . '
            WHERE ' . _LV_EMP_BASE_WHERE . '
            GROUP BY pi.PIID';
    $stmt = $conn->prepare($sql);
    $stmt->execute($join['params'] + [
        ':source_fingerprint' => $source_fingerprint,
        ':snapshot_year' => $target_year,
        ':snapshot_month' => $target_month,
    ]);

    $conn->exec(
        'RENAME TABLE `' . _LV_EMP_ROSTER_TABLE . '` TO `' . $swap . '`, `' .
        $shadow . '` TO `' . _LV_EMP_ROSTER_TABLE . '`'
    );
    $conn->exec('DROP TABLE IF EXISTS `' . $swap . '`');
}

function lv_ensure_employee_roster(PDO $conn, ?int $target_year = null, ?int $target_month = null): void {
    $target_year = $target_year ?: (int)date('Y');
    $target_month = $target_month ?: (int)date('n');

    $meta = lv_employee_roster_meta($conn);
    // A snapshot inside its configured TTL is safe to serve immediately.
    // Defer the expensive multi-table source fingerprint until the TTL expires.
    if (lv_employee_roster_is_fresh($meta, $target_year, $target_month)) {
        return;
    }
    $source_fingerprint = lv_employee_roster_source_fingerprint($conn);
    if (lv_employee_roster_is_fresh($meta, $target_year, $target_month, $source_fingerprint)) {
        return;
    }

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(:lock_name, 30)');
    $lock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        return;
    }

    try {
        $meta = lv_employee_roster_meta($conn);
        if (!lv_employee_roster_is_fresh($meta, $target_year, $target_month, $source_fingerprint)) {
            lv_employee_roster_refresh($conn, $target_year, $target_month, $source_fingerprint);
        }
    } finally {
        $unlock_stmt = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $unlock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    }
}

function lv_force_employee_roster_refresh(PDO $conn, ?int $target_year = null, ?int $target_month = null): void {
    $target_year = $target_year ?: (int)date('Y');
    $target_month = $target_month ?: (int)date('n');
    $source_fingerprint = lv_employee_roster_source_fingerprint($conn);

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(:lock_name, 30)');
    $lock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        return;
    }

    try {
        lv_employee_roster_refresh($conn, $target_year, $target_month, $source_fingerprint);
    } finally {
        $unlock_stmt = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $unlock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    }
}

function lv_payroll_source_sql(int $target_year, int $cutoff_end_num): array {
    $dept_match_join = lv_regular_department_match_join_sql('tpc.Name', 'tp_match');
    $regular_department = lv_department_canonical_name_sql('tp.Name');
    $casual_department = lv_department_canonical_name_sql('COALESCE(tp_exact.Name, tp_match.Name, tpc.Name)');
    $sql = "(SELECT p.PIID, $regular_department AS Department, p.Position, p.End_Date, p.End_num, p.Date_Updated, p.year AS PayYear, p.TID,
                    0 AS IsCasual
             FROM tbl_syl_payroll_parent p
             INNER JOIN (
                 SELECT TID, MAX(year) AS LatestYear
                 FROM tbl_syl_payroll_parent
                 WHERE isDeleted = 0
                   AND Quencina = 'whole'
                   AND year <= :target_year_regular_latest
                 GROUP BY TID
             ) pr_latest ON pr_latest.TID = p.TID AND pr_latest.LatestYear = p.year
             INNER JOIN tbl_template_payroll tp ON p.TID = tp.TID AND tp.isDeleted = 0
             WHERE p.isDeleted = 0
               AND p.Quencina = 'whole'

             UNION

             SELECT pc.PIID, $casual_department AS Department, pc.Position, pc.End_Date, pc.End_num, pc.Date_Updated, pc.year AS PayYear, pc.TID,
                    1 AS IsCasual
             FROM tbl_syl_payroll_parent_casual pc
             INNER JOIN (
                 SELECT TID, MAX(year) AS LatestYear
                 FROM tbl_syl_payroll_parent_casual
                 WHERE isDeleted = 0
                   AND Quencina = 'whole'
                   AND year <= :target_year_casual_latest
                 GROUP BY TID
             ) pc_latest ON pc_latest.TID = pc.TID AND pc_latest.LatestYear = pc.year
             INNER JOIN tbl_template_payroll_casual tpc ON pc.TID = tpc.TID AND tpc.isDeleted = 0
             LEFT JOIN tbl_template_payroll tp_exact ON tp_exact.Name = tpc.Name AND tp_exact.isDeleted = 0
             $dept_match_join
             WHERE pc.isDeleted = 0
               AND pc.Quencina = 'whole'
            ) emp_src";

    return [
        'sql' => $sql,
        'params' => [
            ':target_year_regular_latest' => $target_year,
            ':target_year_casual_latest' => $target_year,
        ],
    ];
}

function lv_emp_join_sql(int $target_year, int $cutoff_end_num): array {
    $src = lv_payroll_source_sql($target_year, $cutoff_end_num);
    return [
        'sql' => $src['sql'] . ' INNER JOIN tblpersonalinformation pi ON pi.PIID = emp_src.PIID',
        'params' => $src['params'],
    ];
}

/**
 * Read-only Regular/Casual roster "as of" a past year — each template's
 * latest snapshot at or before that year, same rule lv_employee_roster_refresh()
 * uses for "now". Deliberately does NOT touch `_LV_EMP_ROSTER_TABLE`: that
 * table is a single live cache shared with leave eligibility, DTR, and the
 * payroll roster pickers, so overwriting it with a historical year would
 * corrupt "today" for the rest of the system. This is HR Insights' year
 * selector only — a plain read against the same underlying source tables.
 */
function lv_historical_employee_rows(PDO $conn, int $target_year): array {
    $join = lv_emp_join_sql($target_year, 12);
    $sql = 'SELECT CAST(pi.PIID AS CHAR(20)) AS PIID,
                   MIN(pi.SurName) AS Surname,
                   MIN(pi.FirstName) AS Firstname,
                   MIN(pi.MiddleName) AS MiddleName,
                   ' . _LV_EMP_DEPT_EXPR . ' AS Department,
                   ' . _LV_EMP_POS_EXPR . ' AS Position,
                   MAX(emp_src.IsCasual) AS IsCasual
            FROM ' . $join['sql'] . '
            WHERE ' . _LV_EMP_BASE_WHERE . '
            GROUP BY pi.PIID';
    $stmt = $conn->prepare($sql);
    $stmt->execute($join['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Employee queries ──────────────────────────────────────────────────────────

/**
 * Batch-fetch the latest Status for a list of PIIDs from tbl_service_record.
 * Returns [PIID => Status] map. Single query replaces N correlated subqueries.
 */
function lv_batch_statuses(PDO $conn, array $piids): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT PIID, Status
         FROM tbl_service_record
         WHERE PIID IN ($placeholders)
           AND Status IS NOT NULL AND Status <> ''
         ORDER BY PIID,
                  COALESCE(Inc_Date_To, Inc_Date_from, Date) DESC,
                  Inc_Date_from DESC"
    );
    $stmt->execute(array_values($piids));
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!array_key_exists($row['PIID'], $result)) {
            $result[$row['PIID']] = $row['Status'];
        }
    }
    return $result;
}

/**
 * Search employees eligible for leave credits from the active DTR template roster.
 *
 * Returns ['rows'=>[], 'total'=>int, 'page'=>int, 'page_size'=>int, 'total_pages'=>int].
 *
 * - dept uses exact match (value always comes from dropdown).
 * - surname/firstname/piid use prefix match (faster index scan than %like%).
 * - Status is fetched in one batch query after the main result set (no N+1).
 */
function lv_search_employees(
    PDO    $conn,
    string $surname   = '',
    string $firstname = '',
    string $dept      = '',
    string $piid      = '',
    int    $page      = 1,
    int    $page_size = 50,
    array  $deptIn     = []
): array {
    lv_ensure_employee_roster($conn);
    $where  = ['1'];
    $params = [];

    if ($surname !== '') {
        $where[] = 'Surname LIKE :surname';
        $params[':surname'] = "$surname%";
    }
    if ($firstname !== '') {
        $where[] = 'Firstname LIKE :firstname';
        $params[':firstname'] = "$firstname%";
    }
    if ($deptIn) {
        $placeholders = [];
        foreach (array_values($deptIn) as $i => $d) {
            $key = ":dept_in_$i";
            $placeholders[] = $key;
            $params[$key] = $d;
        }
        $where[] = 'Department IN (' . implode(',', $placeholders) . ')';
    } elseif ($dept !== '') {
        $where[] = 'Department = :dept';
        $params[':dept'] = $dept;
    }
    if ($piid !== '') {
        $where[] = 'PIID LIKE :piid';
        $params[':piid'] = "$piid%";
    }

    $where_sql = implode(' AND ', $where);

    $count_stmt = $conn->prepare(
        'SELECT COUNT(*) FROM ' . _LV_EMP_ROSTER_TABLE . ' WHERE ' . $where_sql
    );
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $page      = max(1, $page);
    $page_size = max(1, $page_size);
    $total_pages = $total > 0 ? (int)ceil($total / $page_size) : 1;
    $offset    = ($page - 1) * $page_size;

    // Main data query — Status excluded; fetched separately below
    $sql = 'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName,
                   Department, Position, IsCasual, End_Date, PayYear, Status
            FROM ' . _LV_EMP_ROSTER_TABLE . '
            WHERE ' . $where_sql . '
            ORDER BY Department, Surname, Firstname
            LIMIT ' . $page_size . ' OFFSET ' . $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Single batch query for all statuses — replaces one correlated subquery per row
    $eligibilityByPiid = lv_batch_resolve_eligibility($conn, $rows);
    foreach ($rows as &$row) {
        $row['employee_ref'] = lv_employee_ref((string)($row['PIID'] ?? ''));
        $elig = $eligibilityByPiid[(string)($row['PIID'] ?? '')] ?? null;
        $row['Status'] = lv_normalize_employee_status(
            $row['Status'] ?? null,
            !empty($row['IsCasual'])
        );
        $row['Eligibility'] = $elig;
        $row['EmploymentType'] = $elig['employment_type'] ?? 'Not yet classified';
        $row['LeaveEligible'] = $elig['leave_eligible'] ?? false;
    }
    unset($row);

    return [
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'page_size'   => $page_size,
        'total_pages' => $total_pages,
    ];
}

/**
 * Get an employee who is currently included in the Leave module roster.
 */
function lv_get_leave_roster_employee(PDO $conn, string $piid): ?array {
    static $employeeCache = [];
    $cacheKey = spl_object_id($conn) . ':' . $piid;
    if (array_key_exists($cacheKey, $employeeCache)) {
        return $employeeCache[$cacheKey];
    }

    lv_ensure_employee_roster($conn);
    $stmt = $conn->prepare(
        'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName,
                COALESCE((
                    SELECT NULLIF(TRIM(pi.NameExt), "")
                    FROM tblpersonalinformation pi
                    WHERE pi.PIID = ' . _LV_EMP_ROSTER_TABLE . '.PIID
                    LIMIT 1
                ), "") AS NameExt,
                Department, Position,
                IsCasual, End_Date, PayYear, FirstGovServiceDate, Status
         FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE PIID = :piid
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        $rawStatus = $row['Status'] ?? null;
        $row['Eligibility'] = lv_resolve_eligibility($conn, $piid, $rawStatus, !empty($row['IsCasual']));
        $row['Status'] = lv_normalize_employee_status($rawStatus, !empty($row['IsCasual']));
    }
    $employeeCache[$cacheKey] = $row;
    return $employeeCache[$cacheKey];
}

/**
 * Get a single employee row by PIID. Employees outside the Leave module roster
 * still receive their basic personnel identity for PDS and DTR self-service.
 */
function lv_monthly_salary_context(PDO $conn, string $piid, ?string $asOfDate = null): array {
    $empty = ['amount' => 0.0, 'year' => null, 'month' => null, 'label' => ''];
    if (trim($piid) === '') return $empty;
    try {
        $effectiveTimestamp = strtotime(trim((string)$asOfDate));
        if ($effectiveTimestamp === false) {
            $effectiveTimestamp = time();
        }
        $selectedYear = (int)date('Y', $effectiveTimestamp);
        $selectedMonth = (int)date('n', $effectiveTimestamp);

        // Casual employees are stored as a daily Rate, not a monthly salary -- convert
        // to a monthly-equivalent figure the same way payroll does (Rate x 22 working days).
        $stmt = $conn->prepare(
            "SELECT amount, ref_year, ref_month FROM (
                SELECT Salary AS amount, CAST(Year AS UNSIGNED) AS ref_year,
                       End_Num AS ref_month, Date_Updated AS updated_at, PPID AS row_id
                  FROM tbl_syl_payroll_parent
                 WHERE isDeleted = 0
                   AND Quencina = 'whole'
                   AND PIID = :r
                   AND (
                       CAST(Year AS UNSIGNED) < :r_year
                       OR (CAST(Year AS UNSIGNED) = :r_same_year AND End_Num <= :r_month)
                   )
                UNION ALL
                SELECT Rate * 22 AS amount, CAST(Year AS UNSIGNED) AS ref_year,
                       End_Num AS ref_month, Date_Updated AS updated_at, PPID AS row_id
                  FROM tbl_syl_payroll_parent_casual
                 WHERE isDeleted = 0
                   AND Quencina = 'whole'
                   AND PIID = :c
                   AND (
                       CAST(Year AS UNSIGNED) < :c_year
                       OR (CAST(Year AS UNSIGNED) = :c_same_year AND End_Num <= :c_month)
                   )
             ) s
             WHERE amount IS NOT NULL AND amount > 0
             ORDER BY ref_year DESC, ref_month DESC, updated_at DESC, row_id DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':r' => $piid,
            ':r_year' => $selectedYear,
            ':r_same_year' => $selectedYear,
            ':r_month' => $selectedMonth,
            ':c' => $piid,
            ':c_year' => $selectedYear,
            ':c_same_year' => $selectedYear,
            ':c_month' => $selectedMonth,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $empty;
        $templateYear = (int)$row['ref_year'];
        $templateMonth = (int)$row['ref_month'];
        return [
            'amount' => (float)$row['amount'],
            'year' => $templateYear,
            'month' => $templateMonth,
            'label' => date('F Y', mktime(0, 0, 0, $templateMonth, 1, $templateYear)),
        ];
    } catch (Throwable $e) {
        error_log('lv_monthly_salary_context: ' . $e->getMessage());
        return $empty;
    }
}

function lv_latest_monthly_salary(PDO $conn, string $piid, ?string $asOfDate = null): float {
    return (float)lv_monthly_salary_context($conn, $piid, $asOfDate)['amount'];
}

// Monetization formula: Monthly Salary x Monetized Days x 0.0481927.
function lv_monetization_amount(float $monthlySalary, float $days): float {
    // Preserve two decimal places by truncating, never rounding up.
    return floor(($monthlySalary * $days * 0.0481927 * 100) + 0.00000001) / 100;
}

function lv_get_employee(PDO $conn, string $piid): ?array {
    $row = lv_get_leave_roster_employee($conn, $piid);
    if ($row) {
        return $row;
    }

    $stmt = $conn->prepare(
        "SELECT
            PIID,
            ID_NUM,
            SurName AS Surname,
            FirstName AS Firstname,
            MiddleName,
            NameExt,
            '' AS Department,
            '' AS Position,
            0 AS IsCasual,
            NULL AS End_Date,
            NULL AS PayYear,
            NULL AS FirstGovServiceDate,
            NULL AS Status
         FROM tblpersonalinformation
         WHERE PIID = :piid
         LIMIT 1"
    );
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        $rawStatus = $row['Status'] ?? null;
        $row['Eligibility'] = lv_resolve_eligibility($conn, $piid, $rawStatus, !empty($row['IsCasual']));
        $row['Status'] = lv_normalize_employee_status($rawStatus, !empty($row['IsCasual']));

        // Job Order staff are deliberately NOT in the leave roster — roster
        // membership is what gates leave evaluation, and JO staff are not
        // leave-entitled (see payroll_jo_directory.php). They still belong to an
        // office, so fill Department from the JO directory rather than leaving the
        // portal showing "Office not set". Eligibility above is already resolved
        // and is not touched by this.
        if (trim((string)($row['Department'] ?? '')) === '') {
            $row['Department'] = jo_directory_office_for($conn, $piid);
        }
    }
    return $row;
}

/**
 * Get all distinct departments from payroll template tables.
 *
 * Regular template names are the canonical display values. Casual template
 * names are consolidated into matching regular template names, so the
 * dropdown only shows the regular department labels.
 */
function lv_get_departments(PDO $conn): array {
    $dept_match_join = lv_regular_department_match_join_sql('tpc.Name', 'tp_match');
    $regular_department = lv_department_canonical_name_sql('tp.Name');
    $casual_department = lv_department_canonical_name_sql('COALESCE(tp_exact.Name, tp_match.Name, tpc.Name)');
    $stmt = $conn->query(
        "SELECT DISTINCT Department
         FROM (
             SELECT $regular_department AS Department
             FROM tbl_template_payroll tp
             WHERE tp.isDeleted = 0

             UNION

             SELECT $casual_department AS Department
             FROM tbl_template_payroll_casual tpc
             LEFT JOIN tbl_template_payroll tp_exact ON tp_exact.Name = tpc.Name AND tp_exact.isDeleted = 0
             $dept_match_join
             WHERE tpc.isDeleted = 0
         ) dept_src
         WHERE Department IS NOT NULL AND Department <> ''
         ORDER BY Department"
    );
    return array_column($stmt->fetchAll(), 'Department');
}

/** Payroll templates available to one undertime group, with their roster department value. */
function lv_get_undertime_templates(PDO $conn, bool $isCasual): array {
    if (!$isCasual) {
        $department = lv_department_canonical_name_sql('tp.Name');
        $rows = $conn->query("SELECT tp.Name AS label, $department AS value FROM tbl_template_payroll tp WHERE tp.isDeleted = 0 ORDER BY tp.Name")->fetchAll();
    } else {
        $match = lv_regular_department_match_join_sql('tpc.Name', 'tp_match');
        $department = lv_department_canonical_name_sql('COALESCE(tp_exact.Name, tp_match.Name, tpc.Name)');
        $rows = $conn->query("SELECT tpc.Name AS label, $department AS value FROM tbl_template_payroll_casual tpc LEFT JOIN tbl_template_payroll tp_exact ON tp_exact.Name = tpc.Name AND tp_exact.isDeleted = 0 $match WHERE tpc.isDeleted = 0 ORDER BY tpc.Name")->fetchAll();
    }
    return array_values(array_filter($rows, static fn(array $row): bool => trim((string)($row['value'] ?? '')) !== ''));
}

// ── Leave ledger queries ──────────────────────────────────────────────────────

/**
 * Get all leave records for an employee in a given year, ordered chronologically.
 */
function lv_get_records(PDO $conn, string $piid, int $year): array {
    $stmt = $conn->prepare(
        'SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacEarn, VacWP, VacBal, VacWOP,
                SickEarn, SickWP, SickBal, SickWOP,
                Particulars, DateAction, DateProcessed, RecordedBy, Remarks,
                no_avail_VL, no_avail_SL, no_avail_mone_VL, no_avail_mone_SL,
                no_avail_SP, no_avail_P, no_avail_mone
         FROM tbl_syl_leave_form
         WHERE PIID = :piid
           AND Date_of_Filing BETWEEN :date_from AND :date_to
           AND isDeleted = 0
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC'
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => sprintf('%04d-01-01', $year),
        ':date_to' => sprintf('%04d-12-31', $year),
    ]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [];
    }

    $overrideMeta = lv_get_dtr_override_meta_for_lids(
        $conn,
        array_map(static fn(array $row): int => (int)($row['LID'] ?? 0), $rows)
    );
    foreach ($rows as &$row) {
        $lid = (int)($row['LID'] ?? 0);
        $meta = $overrideMeta[$lid] ?? ['DTRHalfDay' => 0, 'DTRHalfDayPeriod' => ''];
        $row['DTRHalfDay'] = (int)($meta['DTRHalfDay'] ?? 0);
        $row['DTRHalfDayPeriod'] = (string)($meta['DTRHalfDayPeriod'] ?? '');
    }
    unset($row);

    return $rows;
}

function lv_get_ledger_years(PDO $conn, string $piid): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT ledger_year
         FROM (
             SELECT YEAR(Date_of_Filing) AS ledger_year
             FROM tbl_syl_leave_form
             WHERE PIID = :records_piid AND isDeleted = 0 AND Date_of_Filing IS NOT NULL
             UNION
             SELECT year AS ledger_year
             FROM tbl_syl_leave_available_balance
             WHERE piid = :balance_piid
         ) years
         WHERE ledger_year IS NOT NULL AND ledger_year > 0
         ORDER BY ledger_year DESC"
    );
    $stmt->execute([':records_piid' => $piid, ':balance_piid' => $piid]);
    return array_map('intval', array_column($stmt->fetchAll(), 'ledger_year'));
}

function lv_get_records_for_recalculate(PDO $conn, string $piid, int $year): array {
    $stmt = $conn->prepare(
        'SELECT LID,
                VacEarn, VacWP, VacWOP,
                SickEarn, SickWP, SickWOP,
                no_avail_mone_VL, no_avail_mone_SL
         FROM tbl_syl_leave_form
         WHERE PIID = :piid
           AND Date_of_Filing BETWEEN :date_from AND :date_to
           AND isDeleted = 0
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC'
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => sprintf('%04d-01-01', $year),
        ':date_to' => sprintf('%04d-12-31', $year),
    ]);
    return $stmt->fetchAll();
}

function lv_update_latest_special_leave_balance(PDO $conn, string $piid, int $year, float $value): void {
    $records = lv_get_records_for_recalculate($conn, $piid, $year);
    if (empty($records)) {
        return;
    }

    $last = $records[count($records) - 1];
    $lid = (int)($last['LID'] ?? 0);
    if ($lid <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_form
         SET no_avail_SL = :value
         WHERE LID = :lid
           AND isDeleted = 0'
    );
    $stmt->execute([
        ':value' => $value,
        ':lid' => $lid,
    ]);
}

function lv_get_dtr_suggested_undertime(PDO $conn, string $piid, string $from, string $to = ''): array {
    $piid = trim($piid);
    $from = trim($from);
    $to = trim($to) !== '' ? trim($to) : $from;
    if ($piid === '' || $from === '') {
        return ['minutes' => 0, 'hours' => 0, 'display_minutes' => 0, 'from' => $from, 'to' => $to];
    }

    $start = strtotime($from);
    $end = strtotime($to);
    if ($start === false || $end === false) {
        return ['minutes' => 0, 'hours' => 0, 'display_minutes' => 0, 'from' => $from, 'to' => $to];
    }
    if ($end < $start) {
        [$from, $to] = [$to, $from];
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(TIME_TO_SEC(Undertime)), 0) AS undertime_seconds
         FROM tbldtr
         WHERE PIID = :piid
           AND DTR_Date BETWEEN :date_from AND :date_to
           AND Undertime IS NOT NULL
           AND Undertime <> '00:00:00'"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => $from,
        ':date_to' => $to,
    ]);

    $seconds = (int)($stmt->fetchColumn() ?: 0);
    $totalMinutes = (int)floor($seconds / 60);

    return [
        'minutes' => $totalMinutes,
        'hours' => (int)floor($totalMinutes / 60),
        'display_minutes' => $totalMinutes % 60,
        'from' => $from,
        'to' => $to,
    ];
}

/** Suggested undertime for one office. 480 minutes equals one VL day. */
function lv_ensure_monthly_undertime_drafts(PDO $conn): void {
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_leave_monthly_undertime_draft (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        department VARCHAR(120) NOT NULL,
        payroll_month DATE NOT NULL,
        PIID VARCHAR(40) NOT NULL,
        undertime_minutes INT NOT NULL DEFAULT 0,
        suggested_vl_days DECIMAL(10,4) NOT NULL DEFAULT 0,
        saved_by VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_leave_monthly_undertime_draft (department, payroll_month, PIID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function lv_get_dtr_undertime_suggestions(PDO $conn, string $department, string $from, string $to): array {
    lv_ensure_employee_roster($conn);
    lv_ensure_monthly_undertime_drafts($conn);
    $department = trim($department);
    if ($department === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        throw new InvalidArgumentException('Department and a valid date range are required.');
    }
    if ($to < $from) [$from, $to] = [$to, $from];
    $stmt = $conn->prepare(
        'SELECT r.PIID, r.ID_NUM, r.Surname, r.Firstname, r.MiddleName, r.Department, r.IsCasual,
                FLOOR(COALESCE(SUM(TIME_TO_SEC(d.Undertime)), 0) / 60) AS undertime_minutes
         FROM ' . _LV_EMP_ROSTER_TABLE . ' r
         INNER JOIN tbldtr d ON d.PIID = r.PIID
              AND d.DTR_Date BETWEEN :date_from AND :date_to
              AND d.Undertime IS NOT NULL AND d.Undertime <> "00:00:00"
         WHERE r.Department = :department
         GROUP BY r.PIID, r.ID_NUM, r.Surname, r.Firstname, r.MiddleName, r.Department, r.IsCasual
         HAVING undertime_minutes > 0
         ORDER BY r.Surname, r.Firstname'
    );
    $stmt->execute([':department' => $department, ':date_from' => $from, ':date_to' => $to]);
    $rows = $stmt->fetchAll();
    $draftStmt = $conn->prepare('SELECT PIID, undertime_minutes, suggested_vl_days FROM tbl_leave_monthly_undertime_draft WHERE department = ? AND payroll_month = ?');
    $draftStmt->execute([$department, $from]);
    $drafts = [];
    foreach ($draftStmt->fetchAll() as $draft) $drafts[(string)$draft['PIID']] = $draft;
    $totalMinutes = 0;
    foreach ($rows as &$row) {
        $draft = $drafts[(string)$row['PIID']] ?? null;
        $minutes = $draft ? (int)$draft['undertime_minutes'] : (int)$row['undertime_minutes'];
        $row['is_draft'] = (bool)$draft;
        // The DTR query provides the original suggestion; a saved review must replace it on reload.
        $row['undertime_minutes'] = $minutes;
        $totalMinutes += $minutes;
        $row['employee_name'] = trim((string)$row['Surname'] . ', ' . (string)$row['Firstname'] . ' ' . (string)$row['MiddleName']);
        $row['employment_group'] = !empty($row['IsCasual']) ? 'Casual' : 'Regular';
        $row['suggested_vl_days'] = round($minutes / 480, 4);
    }
    unset($row);
    return ['rows' => $rows, 'total_minutes' => $totalMinutes, 'total_vl_days' => round($totalMinutes / 480, 4), 'from' => $from, 'to' => $to];
}

/** Save reviewed monthly undertime without posting it to the leave ledger. */
function lv_save_monthly_undertime_draft(PDO $conn, string $department, string $month, array $items, string $actor): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new InvalidArgumentException('Select a valid payroll month.');
    lv_ensure_monthly_undertime_drafts($conn);
    $from = $month . '-01';
    $employees = lv_search_employees($conn, '', '', $department, '', 1, 500)['rows'];
    $allowed = array_fill_keys(array_map(static fn(array $r): string => (string)$r['PIID'], $employees), true);
    $save = $conn->prepare("INSERT INTO tbl_leave_monthly_undertime_draft (department, payroll_month, PIID, undertime_minutes, suggested_vl_days, saved_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE undertime_minutes=VALUES(undertime_minutes), suggested_vl_days=VALUES(suggested_vl_days), saved_by=VALUES(saved_by), updated_at=NOW()");
    $count = 0;
    $conn->beginTransaction();
    try {
        foreach ($items as $item) {
            $piid = trim((string)($item['piid'] ?? ''));
            $minutes = (int)($item['undertime_minutes'] ?? -1);
            if ($piid === '' || !isset($allowed[$piid]) || $minutes < 0) throw new InvalidArgumentException('Invalid employee or undertime value.');
            $save->execute([$department, $from, $piid, $minutes, round($minutes / 480, 4), $actor]);
            $count++;
        }
        $conn->commit();
    } catch (Throwable $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
    return ['saved' => $count, 'period_from' => $from];
}

/** Remove a saved review so the original DTR-derived undertime is shown again. */
function lv_reset_monthly_undertime_draft(PDO $conn, string $department, string $month): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new InvalidArgumentException('Select a valid payroll month.');
    lv_ensure_monthly_undertime_drafts($conn);
    $remove = $conn->prepare('DELETE FROM tbl_leave_monthly_undertime_draft WHERE department = ? AND payroll_month = ?');
    $remove->execute([trim($department), $month . '-01']);
    return ['removed' => $remove->rowCount(), 'period_from' => $month . '-01'];
}

/** Post reviewed monthly undertime suggestions as VL-charged leave ledger records. */
function lv_post_monthly_undertime(PDO $conn, string $department, string $month, array $items, string $actor): array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m) || !checkdate((int)$m[2], 1, (int)$m[1])) {
        throw new InvalidArgumentException('Select a valid payroll month.');
    }
    $from = $month . '-01'; $to = date('Y-m-t', strtotime($from)); $year = (int)$m[1];
    $employees = lv_search_employees($conn, '', '', $department, '', 1, 500)['rows'];
    $allowed = array_fill_keys(array_map(static fn(array $row): string => (string)$row['PIID'], $employees), true);
    $valid = [];
    foreach ($items as $item) {
        $piid = trim((string)($item['piid'] ?? '')); $days = round((float)($item['suggested_vl_days'] ?? 0), 4);
        if ($piid === '' || !isset($allowed[$piid]) || $days < 0) throw new InvalidArgumentException('Invalid employee or suggested VL amount.');
        if ($days > 0) $valid[$piid] = $days;
    }
    if (!$valid) throw new InvalidArgumentException('Enter a positive Suggested VL for at least one employee.');
    $ph = implode(',', array_fill(0, count($valid), '?'));
    $exists = $conn->prepare("SELECT PIID FROM tbl_syl_leave_form WHERE isDeleted = 0 AND Period_From = ? AND Type_of_Records IN ('UNDERTIME', 'UNDERTIME (SUBJECT TO VACATION LEAVE)') AND PIID IN ($ph)");
    $exists->execute(array_merge([$from], array_keys($valid)));
    $duplicates = $exists->fetchAll(PDO::FETCH_COLUMN);
    if ($duplicates) throw new RuntimeException('Undertime is already posted for ' . count($duplicates) . ' employee(s) for this month.');
    $conn->beginTransaction();
    try {
        foreach ($valid as $piid => $days) {
            lv_insert_record($conn, ['PIID'=>$piid, 'Type_of_Records'=>'UNDERTIME (SUBJECT TO VACATION LEAVE)', 'Date_of_Filing'=>date('Y-m-d'), 'Period_From'=>$from, 'Period_To'=>$to, 'VacWP'=>$days, 'Particulars'=>'UNDERTIME FOR ' . strtoupper(date('F Y', strtotime($from))), 'DateAction'=>'UNDERTIME FOR ' . strtoupper(date('F Y', strtotime($from))), 'Remarks'=>'Monthly DTR undertime review'], $actor);
            lv_recalculate($conn, $piid, $year, 'monthly-undertime');
        }
        $conn->commit();
    } catch (Throwable $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
    return ['posted'=>count($valid), 'period_from'=>$from, 'period_to'=>$to];
}

/**
 * Get a single leave record by LID.
 */
function lv_get_record(PDO $conn, int $lid): ?array {
    $stmt = $conn->prepare(
        'SELECT * FROM tbl_syl_leave_form WHERE LID = :lid AND isDeleted = 0 LIMIT 1'
    );
    $stmt->execute([':lid' => $lid]);
    return $stmt->fetch() ?: null;
}

function lv_get_dtr_override_meta_for_lids(PDO $conn, array $lids): array {
    $lids = array_values(array_unique(array_filter(array_map('intval', $lids), static fn(int $lid): bool => $lid > 0)));
    if (!$lids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($lids), '?'));
    $stmt = $conn->prepare(
        "SELECT o.LID, MIN(d.time_start) AS min_start, MAX(d.time_end) AS max_end
           FROM tbldtr_override o
           JOIN tbldtr_override_details d ON d.OID = o.OID
          WHERE o.LID IN ($placeholders)
          GROUP BY o.LID"
    );
    $stmt->execute($lids);

    $meta = [];
    foreach ($stmt->fetchAll() as $row) {
        $period = '';
        $minStart = trim((string)($row['min_start'] ?? ''));
        $maxEnd = trim((string)($row['max_end'] ?? ''));
        if ($minStart === '08:00' && $maxEnd === '12:00') {
            $period = 'AM';
        } elseif ($minStart === '12:45' && $maxEnd === '17:00') {
            $period = 'PM';
        }

        $lid = (int)($row['LID'] ?? 0);
        if ($lid > 0) {
            $meta[$lid] = [
                'DTRHalfDay' => $period !== '' ? 1 : 0,
                'DTRHalfDayPeriod' => $period,
            ];
        }
    }

    return $meta;
}

function lv_main_type(string $type): string {
    $parts = explode('|', $type, 2);
    return trim((string)($parts[0] ?? $type));
}

function lv_is_leave_earned_type(string $type): bool {
    return strtolower(lv_main_type($type)) === 'leave earned';
}

function lv_is_leave_earned_record(array $record): bool {
    $type = strtolower(lv_main_type((string)($record['Type_of_Records'] ?? '')));
    $particulars = strtolower((string)($record['Particulars'] ?? ''));
    return $type === 'leave earned' && str_contains($particulars, 'leave earned');
}

function lv_auto_leave_earned_cap(PDO $conn): float {
    $stmt = $conn->query(
        'SELECT COALESCE(MAX(Leave_Credits_Earned), 1.25)
         FROM tbl_syl_leave_credits_earned'
    );
    $cap = (float)($stmt->fetchColumn() ?: 1.25);
    return $cap > 0 ? round($cap, 4) : 1.25;
}

function lv_validate_leave_earned_cap(PDO $conn, array $d): void {
    if (!lv_is_leave_earned_type((string)($d['Type_of_Records'] ?? ''))) {
        return;
    }

    $cap = lv_auto_leave_earned_cap($conn);
    $vacEarn = round((float)($d['VacEarn'] ?? 0), 4);
    $sickEarn = round((float)($d['SickEarn'] ?? 0), 4);

    if ($vacEarn > $cap || $sickEarn > $cap) {
        throw new RuntimeException('Leave Earned cannot be more than the automated earned value of ' . number_format($cap, 4) . '.');
    }
}

function lv_is_wellness_type(string $type): bool {
    return strtolower(lv_main_type($type)) === 'wellness leave';
}

function lv_is_compensatory_type(string $type): bool {
    return strtolower(lv_main_type($type)) === 'compensatory time-off (cto)';
}

function lv_type_includes_weekends(string $type): bool {
    $normalized = strtolower(trim(lv_main_type($type)));
    return in_array($normalized, ['maternity leave', 'study leave'], true);
}

function lv_ph_easter_date(int $year): DateTimeImmutable {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

function lv_ph_last_monday(int $year, int $month): string {
    $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $date = $date->modify('last day of this month');
    while ($date->format('N') !== '1') {
        $date = $date->modify('-1 day');
    }
    return $date->format('Y-m-d');
}

function lv_ph_holiday_dates(int $year): array {
    $easter = lv_ph_easter_date($year);
    $dates = [
        sprintf('%04d-01-01', $year),
        sprintf('%04d-04-09', $year),
        sprintf('%04d-05-01', $year),
        sprintf('%04d-06-12', $year),
        sprintf('%04d-08-21', $year),
        sprintf('%04d-11-01', $year),
        sprintf('%04d-11-30', $year),
        sprintf('%04d-12-08', $year),
        sprintf('%04d-12-25', $year),
        sprintf('%04d-12-30', $year),
        $easter->modify('-3 days')->format('Y-m-d'),
        $easter->modify('-2 days')->format('Y-m-d'),
        lv_ph_last_monday($year, 8),
    ];
    if ($year === 2026) {
        $dates = array_merge($dates, [
            '2026-02-17',
            '2026-03-20',
            '2026-04-04',
            '2026-10-31',
            '2026-12-24',
            '2026-12-31',
        ]);
    }
    return array_fill_keys($dates, true);
}

function lv_ph_is_working_day_timestamp(int $ts): bool {
    $day = (int)date('w', $ts);
    if ($day === 0 || $day === 6) return false;
    $holidays = lv_ph_holiday_dates((int)date('Y', $ts));
    return !isset($holidays[date('Y-m-d', $ts)]);
}

function lv_count_leave_days_between(string $from, string $to, bool $include_weekends = false): float {
    if ($from === '' || $to === '') return 0.0;

    $start = strtotime($from . ' 00:00:00');
    $end = strtotime($to . ' 00:00:00');
    if ($start === false || $end === false || $end < $start) return 0.0;

    $count = 0.0;
    for ($ts = $start; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
        if ($include_weekends || lv_ph_is_working_day_timestamp($ts)) {
            $count += 1.0;
        }
    }

    return $count;
}

function lv_record_leave_days(array $record): float {
    $type = lv_main_type((string)($record['Type_of_Records'] ?? ''));
    return lv_count_leave_days_between(
        (string)($record['Period_From'] ?? ''),
        (string)($record['Period_To'] ?? ''),
        lv_type_includes_weekends($type)
    );
}

function lv_wellness_days_for_record(array $record): float {
    if (!lv_is_wellness_type((string)($record['Type_of_Records'] ?? ''))) {
        return 0.0;
    }

    return lv_record_leave_days($record);
}

function lv_wellness_days_used(PDO $conn, string $piid, int $year, int $exclude_lid = 0): float {
    $used = 0.0;
    foreach (lv_get_records($conn, $piid, $year) as $row) {
        if ($exclude_lid > 0 && (int)($row['LID'] ?? 0) === $exclude_lid) {
            continue;
        }
        $used += lv_wellness_days_for_record($row);
    }
    return round($used, 4);
}

/**
 * Insert a new leave record. Returns the new LID.
 */
function lv_insert_record(PDO $conn, array $d, string $recorded_by): int {
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_form
            (PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
             VacEarn, VacWP, VacBal, VacWOP,
            SickEarn, SickWP, SickBal, SickWOP,
            Particulars, DateAction, DateProcessed, RecordedBy,
            no_avail_VL, no_avail_SL, no_avail_mone_VL, no_avail_mone_SL,
            no_avail_SP, no_avail_P, no_avail_mone, Remarks, isDeleted)
         VALUES
            (:piid, :type, :filing, :pfrom, :pto,
             :ve, :vwp, :vbal, :vwop,
             :se, :swp, :sbal, :swop,
             :particulars, :date_action, :date_processed, :recorded_by,
             :nvl, :nsl, :nmvl, :nmsl,
             :nsp, :np, :nmone, :remarks, 0)'
    );
    $stmt->execute([
        ':piid'       => $d['PIID'],
        ':type'       => $d['Type_of_Records'],
        ':filing'     => $d['Date_of_Filing'],
        ':pfrom'      => $d['Period_From'],
        ':pto'        => $d['Period_To'],
        ':ve'         => $d['VacEarn']  ?? 0,
        ':vwp'        => $d['VacWP']   ?? 0,
        ':vbal'       => $d['VacBal']  ?? 0,
        ':vwop'       => $d['VacWOP']  ?? 0,
        ':se'         => $d['SickEarn'] ?? 0,
        ':swp'        => $d['SickWP']  ?? 0,
        ':sbal'       => $d['SickBal'] ?? 0,
        ':swop'       => $d['SickWOP'] ?? 0,
        ':particulars'=> $d['Particulars'] ?? '',
        ':date_action'=> $d['DateAction'] ?? '',
        ':date_processed'=> !empty($d['DateProcessed']) ? $d['DateProcessed'] : date('Y-m-d H:i:s'),
        ':recorded_by'=> !empty($d['RecordedBy']) ? $d['RecordedBy'] : $recorded_by,
        ':nvl'        => $d['no_avail_VL']      ?? 0,
        ':nsl'        => $d['no_avail_SL']      ?? 0,
        ':nmvl'       => $d['no_avail_mone_VL'] ?? 0,
        ':nmsl'       => $d['no_avail_mone_SL'] ?? 0,
        ':nsp'        => $d['no_avail_SP']      ?? 0,
        ':np'         => $d['no_avail_P']       ?? 0,
        ':nmone'      => $d['no_avail_mone']    ?? 0,
        ':remarks'    => $d['Remarks']          ?? '',
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Update an existing leave record by LID.
 */
function lv_update_record(PDO $conn, int $lid, array $d, string $recorded_by): void {
    $stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_form SET
            Type_of_Records = :type,
            Date_of_Filing  = :filing,
            Period_From     = :pfrom,
            Period_To       = :pto,
            VacEarn  = :ve,  VacWP  = :vwp,  VacBal  = :vbal,  VacWOP  = :vwop,
            SickEarn = :se,  SickWP = :swp,  SickBal = :sbal,  SickWOP = :swop,
            Particulars     = :particulars,
            DateAction      = :date_action,
            DateProcessed   = :date_processed,
            RecordedBy      = :recorded_by,
            no_avail_VL     = :nvl,
            no_avail_SL     = :nsl,
            no_avail_mone_VL= :nmvl,
            no_avail_mone_SL= :nmsl,
            no_avail_SP     = :nsp,
            no_avail_P      = :np,
            no_avail_mone   = :nmone,
            Remarks         = :remarks
         WHERE LID = :lid AND isDeleted = 0'
    );
    $stmt->execute([
        ':lid'        => $lid,
        ':type'       => $d['Type_of_Records'],
        ':filing'     => $d['Date_of_Filing'],
        ':pfrom'      => $d['Period_From'],
        ':pto'        => $d['Period_To'],
        ':ve'         => $d['VacEarn']  ?? 0,
        ':vwp'        => $d['VacWP']   ?? 0,
        ':vbal'       => $d['VacBal']  ?? 0,
        ':vwop'       => $d['VacWOP']  ?? 0,
        ':se'         => $d['SickEarn'] ?? 0,
        ':swp'        => $d['SickWP']  ?? 0,
        ':sbal'       => $d['SickBal'] ?? 0,
        ':swop'       => $d['SickWOP'] ?? 0,
        ':particulars'=> $d['Particulars'] ?? '',
        ':date_action'=> $d['DateAction'] ?? '',
        ':date_processed'=> !empty($d['DateProcessed']) ? $d['DateProcessed'] : date('Y-m-d H:i:s'),
        ':recorded_by'=> !empty($d['RecordedBy']) ? $d['RecordedBy'] : $recorded_by,
        ':nvl'        => $d['no_avail_VL']      ?? 0,
        ':nsl'        => $d['no_avail_SL']      ?? 0,
        ':nmvl'       => $d['no_avail_mone_VL'] ?? 0,
        ':nmsl'       => $d['no_avail_mone_SL'] ?? 0,
        ':nsp'        => $d['no_avail_SP']      ?? 0,
        ':np'         => $d['no_avail_P']       ?? 0,
        ':nmone'      => $d['no_avail_mone']    ?? 0,
        ':remarks'    => $d['Remarks']          ?? '',
    ]);
}

/**
 * Soft-delete a leave record.
 */
function lv_delete_record(PDO $conn, int $lid): void {
    $stmt = $conn->prepare('UPDATE tbl_syl_leave_form SET isDeleted = 1 WHERE LID = :lid');
    $stmt->execute([':lid' => $lid]);
}

// ── DTR override helpers ──────────────────────────────────────────────────────

/**
 * Returns true if this leave type should generate DTR override rows.
 * Mirrors VB frmChoiceFormLeave.add_to_override() eligibility condition.
 */
function lv_dtr_eligible(string $type): bool {
    $main = strtolower(trim(lv_main_type($type)));
    return in_array($main, [
        'vacation leave', 'sick leave', 'paternity leave', 'maternity leave',
        'terminal leave', 'solo parent leave', 'compensatory time-off (cto)',
        'rehabilitation leave', 'rehabilitation privilege',
        'vacation leave w/o pay', 'sick leave w/o pay',
        'mandatory/forced leave', 'others, (specify)', 'others (specify)',
        'special leave benefits for women', 'wellness leave',
    ], true);
}

function lv_dtr_audit_user(string $value): string {
    $value = trim($value);
    return $value !== '' ? substr($value, 0, 20) : 'HRMIS';
}

/**
 * Maps a leave type (may include "|subtype" suffix) to the Name stored in tbldtr_override.
 * Mirrors VB frmChoiceFormLeave.add_to_override() Name-mapping logic.
 */
function lv_dtr_name_for_type(string $type): string {
    $parts = explode('|', $type, 2);
    $main  = trim($parts[0] ?? $type);
    $sub   = strtolower(trim($parts[1] ?? ''));
    $normalizedMain = strtolower($main);
    $canonical = [
        'vacation leave' => 'Vacation Leave',
        'sick leave' => 'Sick Leave',
        'paternity leave' => 'Paternity Leave',
        'maternity leave' => 'Maternity Leave',
        'terminal leave' => 'Terminal Leave',
        'solo parent leave' => 'Solo Parent Leave',
        'compensatory time-off (cto)' => 'Compensatory Time-Off (CTO)',
        'rehabilitation leave' => 'Rehabilitation Leave',
        'rehabilitation privilege' => 'Rehabilitation Privilege',
        'vacation leave w/o pay' => 'Vacation Leave w/o pay',
        'sick leave w/o pay' => 'Sick Leave w/o pay',
        'mandatory/forced leave' => 'Mandatory/Forced Leave',
        'special leave benefits for women' => 'Special Leave Benefits for Women',
        'wellness leave' => 'Wellness Leave',
    ];
    if (isset($canonical[$normalizedMain])) {
        return $canonical[$normalizedMain];
    }
    if ($sub !== '' && $sub !== '-n/a-' && $normalizedMain === 'others, (specify)') {
        $map = [
            'personal milestone'    => 'SPECIAL LEAVE/PM',
            'filial obligation'     => 'SPECIAL LEAVE/FL',
            'domestic emergency'    => 'SPECIAL LEAVE/DE',
            'personal transaction'  => 'SPECIAL LEAVE/PT',
            'calamity, accident hospitalization leave' => 'Calamity,Accident',
            'mourning leave'        => 'SPECIAL LEAVE/ML',
            'magna carta for women' => 'SPECIAL LEAVE/MC',
        ];
        return $map[$sub] ?? ('SPECIAL LEAVE/' . strtoupper(trim($parts[1] ?? '')));
    }
    return $main;
}

/**
 * Given a cancelled or restoration record, return the LID of its pair or null.
 * Matches the pair using stable fields (PIID + period range) instead of parsing
 * human-readable Particulars text.
 */
function lv_find_cancel_companion(PDO $conn, array $rec): ?int {
    $pars  = strtoupper(trim((string)($rec['Particulars'] ?? '')));
    $piid  = (string)($rec['PIID'] ?? '');
    $lid   = (int)($rec['LID'] ?? 0);
    $pfrom = trim((string)($rec['Period_From'] ?? ''));
    $pto   = trim((string)($rec['Period_To'] ?? ''));

    if ($piid === '' || $lid <= 0 || $pfrom === '') {
        return null;
    }

    $pto = $pto !== '' ? $pto : $pfrom;

    if (str_starts_with($pars, 'CANCELLED-')) {
        $stmt = $conn->prepare(
            "SELECT LID FROM tbl_syl_leave_form
             WHERE PIID = :piid
               AND isDeleted = 0
               AND LID <> :lid
               AND DATE(Period_From) = :pfrom
               AND DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) = :pto
               AND UPPER(Particulars) LIKE 'RESTORATION%'
             ORDER BY LID DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':piid'  => $piid,
            ':lid'   => $lid,
            ':pfrom' => $pfrom,
            ':pto'   => $pto,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['LID'] : null;
    }

    if (str_starts_with($pars, 'RESTORATION')) {
        $stmt = $conn->prepare(
            "SELECT LID FROM tbl_syl_leave_form
             WHERE PIID = :piid
               AND isDeleted = 0
               AND LID <> :lid
               AND DATE(Period_From) = :pfrom
               AND DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) = :pto
               AND UPPER(Particulars) LIKE 'CANCELLED-%'
             ORDER BY LID DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':piid'  => $piid,
            ':lid'   => $lid,
            ':pfrom' => $pfrom,
            ':pto'   => $pto,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['LID'] : null;
    }

    return null;
}

/**
 * Delete DTR override rows by OID.
 * Removes details and person rows first (no FK cascade assumed), then the header.
 */
function lv_delete_dtr_override_oids(PDO $conn, array $oids): void {
    $oids = array_values(array_unique(array_filter(array_map('intval', $oids), static fn($oid) => $oid > 0)));
    if ($oids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($oids), '?'));
    $conn->prepare("DELETE FROM tbldtr_override_details WHERE OID IN ($placeholders)")->execute($oids);
    $conn->prepare("DELETE FROM tbldtr_override_person  WHERE OID IN ($placeholders)")->execute($oids);
    $conn->prepare("DELETE FROM tbldtr_override         WHERE OID IN ($placeholders)")->execute($oids);
}

/**
 * Delete all DTR override rows tied to a leave record LID.
 */
function lv_delete_dtr_override(PDO $conn, int $lid): void {
    $stmt = $conn->prepare('SELECT OID FROM tbldtr_override WHERE LID = :lid');
    $stmt->execute([':lid' => $lid]);
    $oids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    lv_delete_dtr_override_oids($conn, $oids);
}

/**
 * Find existing override rows for the same employee and overlapping date span.
 * The legacy client reads overrides by PIID + DTR_Date, so any overlapping row
 * can still surface even if the header/LID differs.
 */
function lv_find_dtr_override_oids_for_period(
    PDO $conn,
    string $piid,
    string $period_from,
    string $period_to,
    int $exclude_lid = 0,
    int $exclude_oid = 0
): array {
    if ($piid === '' || $period_from === '' || $period_to === '') {
        return [];
    }

    $sql = "
        SELECT DISTINCT o.OID
          FROM tbldtr_override o
          JOIN tbldtr_override_person p
            ON p.OID = o.OID
         JOIN tbldtr_override_details d
            ON d.OID = o.OID
         WHERE p.PIID = :piid
           AND DATE(d.dtr_date) BETWEEN :period_from AND :period_to
           AND (:exclude_lid_flag <= 0 OR COALESCE(o.LID, 0) <> :exclude_lid_value)
           AND (:exclude_oid_flag <= 0 OR o.OID <> :exclude_oid_value)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':piid' => $piid,
        ':exclude_lid_flag' => $exclude_lid,
        ':exclude_lid_value' => $exclude_lid,
        ':exclude_oid_flag' => $exclude_oid,
        ':exclude_oid_value' => $exclude_oid,
        ':period_from' => $period_from,
        ':period_to' => $period_to,
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Manual DTR overrides (LID is empty) that intersect a requested leave window.
 * Returned rows are safe to show to the affected employee/reviewer.
 */
function lv_find_manual_dtr_override_conflicts(
    PDO $conn,
    string $piid,
    string $periodFrom,
    string $periodTo,
    string $halfDayMode = 'full'
): array {
    if ($piid === '' || $periodFrom === '' || $periodTo === '') {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT o.OID, o.Name, o.Override_Type, d.DTR_Date, d.Time_Start, d.Time_End
           FROM tbldtr_override o
           JOIN tbldtr_override_person p ON p.OID = o.OID
           JOIN tbldtr_override_details d ON d.OID = o.OID
          WHERE p.PIID = :piid
            AND COALESCE(o.LID, 0) = 0
            AND DATE(d.DTR_Date) BETWEEN :period_from AND :period_to
          ORDER BY d.DTR_Date, o.OID"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':period_from' => $periodFrom,
        ':period_to' => $periodTo,
    ]);

    $mode = strtolower(trim($halfDayMode));
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $leaveStart = '08:00:00';
        $leaveEnd = '17:00:00';
        if ((string)$row['DTR_Date'] === $periodTo) {
            if ($mode === 'am') {
                $leaveEnd = '12:00:00';
            } elseif ($mode === 'pm') {
                $leaveStart = '12:45:00';
            }
        }
        $overrideStart = (string)($row['Time_Start'] ?: '08:00:00');
        $overrideEnd = (string)($row['Time_End'] ?: '17:00:00');
        if ($overrideStart < $leaveEnd && $overrideEnd > $leaveStart) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function lv_manual_override_conflict_message(array $conflicts): string {
    $items = [];
    foreach (array_slice($conflicts, 0, 5) as $row) {
        $items[] = sprintf(
            '%s: %s - %s',
            (string)($row['DTR_Date'] ?? ''),
            (string)($row['Override_Type'] ?? 'Override'),
            (string)($row['Name'] ?? '')
        );
    }
    $message = implode('; ', $items);
    if (count($conflicts) > 5) {
        $message .= '; +' . (count($conflicts) - 5) . ' more';
    }
    return $message;
}

/**
 * Other active leave applications that intersect a proposed application.
 */
function lv_find_active_leave_application_conflicts(
    PDO $conn,
    string $piid,
    string $periodFrom,
    string $periodTo,
    int $excludeId = 0
): array {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "SELECT id, leave_type, period_from, period_to, status, dept_status
           FROM tbl_leave_applications
          WHERE piid = :piid
            AND id <> :exclude_id
            AND status IN ('Pending', 'Approved')
            AND period_from <= :period_to
            AND period_to >= :period_from
          ORDER BY period_from, id"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':exclude_id' => $excludeId,
        ':period_from' => $periodFrom,
        ':period_to' => $periodTo,
    ]);
    $conflicts = $stmt->fetchAll() ?: [];

    // Cover legacy/directly posted ledger leave that has no online application.
    $posted = $conn->prepare(
        "SELECT LID AS id, Type_of_Records AS leave_type,
                DATE(Period_From) AS period_from,
                DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) AS period_to,
                'Approved' AS status, 'Approved' AS dept_status
           FROM tbl_syl_leave_form
          WHERE PIID = :piid
            AND isDeleted = 0
            AND UPPER(COALESCE(Particulars, '')) NOT LIKE 'CANCELLED-%'
            AND UPPER(COALESCE(Particulars, '')) NOT LIKE 'RESTORATION%'
            AND UPPER(COALESCE(Particulars, '')) NOT LIKE '%-RESCHEDULED TO %'
            AND DATE(Period_From) <= :period_to
            AND DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) >= :period_from
          ORDER BY Period_From, LID"
    );
    $posted->execute([
        ':piid' => $piid,
        ':period_from' => $periodFrom,
        ':period_to' => $periodTo,
    ]);
    foreach ($posted->fetchAll() ?: [] as $row) {
        if (!lv_dtr_eligible((string)$row['leave_type'])) {
            continue;
        }
        $coveredByApplication = false;
        foreach ($conflicts as $existing) {
            if (
                (string)$existing['status'] === 'Approved'
                && (string)$existing['period_from'] === (string)$row['period_from']
                && (string)$existing['period_to'] === (string)$row['period_to']
            ) {
                $coveredByApplication = true;
                break;
            }
        }
        if (!$coveredByApplication) {
            $conflicts[] = $row;
        }
    }
    return $conflicts;
}

/**
 * Insert/replace DTR override rows for a leave record.
 *
 * Mirrors VB frmChoiceFormLeave.add_to_override():
 *   - One tbldtr_override header row  (Override_Type = "Memo")
 *   - One tbldtr_override_details row per calendar day (weekends included, 08:00–17:00)
 *   - One tbldtr_override_person row
 *
 * No-ops if the leave type is not DTR-eligible or dates are missing.
 */
function lv_upsert_dtr_override(
    PDO $conn, int $lid, string $type,
    string $period_from, string $period_to,
    string $particulars, string $piid, string $created_by,
    bool $is_half_day = false, string $half_day_period = ''
): void {
    $mainType = lv_main_type($type);
    $eligible = lv_dtr_eligible($type);
    if (!$eligible || $period_from === '' || $period_to === '') {
        lv_delete_dtr_override($conn, $lid);
        return;
    }

    // Only rebuild the projection owned by this leave record. Unrelated manual
    // overrides are resolved explicitly by the workflow instead of being
    // silently deleted.
    lv_delete_dtr_override($conn, $lid);

    $now = date('Y-m-d H:i:s');
    $overrideName = lv_dtr_name_for_type($type);
    $auditUser = lv_dtr_audit_user($created_by);
    $conn->prepare(
        'INSERT INTO tbldtr_override
            (Name, ODate, Override_Type, Remarks, LID, Created_By, Created_Date, Updated_By, Updated_Date)
         VALUES (:name, :odate, :otype, :remarks, :lid, :cby, :cdate, :uby, :udate)'
    )->execute([
        ':name'    => $overrideName,
        ':odate'   => date('Y-m-d'),
        ':otype'   => 'Memo',
        ':remarks' => $particulars,
        ':lid'     => $lid,
        ':cby'     => $auditUser,
        ':cdate'   => $now,
        ':uby'     => $auditUser,
        ':udate'   => $now,
    ]);
    $oid = (int)$conn->lastInsertId();

    $halfDayStart = '08:00';
    $halfDayEnd = '17:00';
    if ($is_half_day) {
        $period = strtoupper(trim($half_day_period));
        if ($period === 'AM') {
            $halfDayStart = '08:00';
            $halfDayEnd = '12:00';
        } elseif ($period === 'PM') {
            $halfDayStart = '12:45';
            $halfDayEnd = '17:00';
        }
    }

    $det   = $conn->prepare(
        'INSERT INTO tbldtr_override_details (OID, dtr_date, time_start, time_end)
         VALUES (:oid, :dt, :time_start, :time_end)'
    );
    $start = strtotime($period_from);
    $end   = strtotime($period_to);
    if ($start !== false && $end !== false && $end >= $start) {
        for ($ts = $start; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
            $isFinalHalfDay = $is_half_day && date('Y-m-d', $ts) === date('Y-m-d', $end);
            $det->execute([
                ':oid' => $oid,
                ':dt' => date('Y-m-d', $ts),
                ':time_start' => $isFinalHalfDay ? $halfDayStart : '08:00',
                ':time_end' => $isFinalHalfDay ? $halfDayEnd : '17:00',
            ]);
        }
    }

    $conn->prepare('DELETE FROM tbldtr_override_person WHERE OID = :oid')->execute([':oid' => $oid]);
    $conn->prepare('INSERT INTO tbldtr_override_person (OID, piid) VALUES (:oid, :piid)')
         ->execute([':oid' => $oid, ':piid' => $piid]);

}

/**
 * Cancel a leave record (transaction-wrapped).
 *
 * - Prefixes Particulars with "CANCELLED-" on the original row (VacWP/SickWP kept intact)
 * - Inserts a restoration row: VacEarn = original.VacWP, SickEarn = original.SickWP
 * - Deletes the DTR override for the original LID
 * - Calls lv_recalculate() for the affected year
 *
 * Returns the new restoration row's LID.
 * Throws RuntimeException on invalid state (already cancelled, not found).
 */
function lv_cancel_record(PDO $conn, int $lid, string $filing_date, string $actor): int {
    $rec = lv_get_record($conn, $lid);
    if (!$rec) throw new RuntimeException('Record not found');
    if (str_starts_with((string)($rec['Particulars'] ?? ''), 'CANCELLED-')) {
        throw new RuntimeException('Record is already cancelled');
    }

    if (!$filing_date) $filing_date = date('Y-m-d');
    $pfrom = (string)($rec['Period_From'] ?? '');
    $pto   = (string)($rec['Period_To']   ?? '');
    $type  = (string)($rec['Type_of_Records'] ?? '');
    $pars  = (string)($rec['Particulars'] ?? '');

    $restPars = 'RESTORATION of ' . lv_main_type($type) . ' DATED '
              . date('M j, Y', strtotime($pfrom ?: 'now'));
    if ($pto && $pto !== $pfrom) {
        $restPars .= ' - ' . date('M j, Y', strtotime($pto));
    }

    $conn->beginTransaction();
    try {
        $conn->prepare('UPDATE tbl_syl_leave_form SET Particulars = :p WHERE LID = :lid')
             ->execute([':p' => 'CANCELLED-' . $pars, ':lid' => $lid]);

        $restore = [
            'PIID'             => $rec['PIID'],
            'Type_of_Records'  => $type,
            'Date_of_Filing'   => $filing_date,
            'Period_From'      => $pfrom,
            'Period_To'        => $pto,
            'VacEarn'          => (float)($rec['VacWP']  ?? 0),
            'VacWP'            => 0,
            'VacBal'           => 0,
            'VacWOP'           => 0,
            'SickEarn'         => (float)($rec['SickWP'] ?? 0),
            'SickWP'           => 0,
            'SickBal'          => 0,
            'SickWOP'          => 0,
            'Particulars'      => $restPars,
            'DateAction'       => (string)($rec['DateAction'] ?? ''),
            'DateProcessed'    => $filing_date,
            'RecordedBy'       => $actor,
            'no_avail_VL'      => (float)($rec['no_avail_VL']      ?? 0),
            'no_avail_SL'      => (float)($rec['no_avail_SL']      ?? 0),
            'no_avail_mone_VL' => (float)($rec['no_avail_mone_VL'] ?? 0),
            'no_avail_mone_SL' => (float)($rec['no_avail_mone_SL'] ?? 0),
            'no_avail_SP'      => (float)($rec['no_avail_SP']      ?? 0),
            'no_avail_P'       => (float)($rec['no_avail_P']       ?? 0),
            'no_avail_mone'    => 0,
            'Remarks'          => (string)($rec['Remarks'] ?? ''),
        ];
        $new_lid = lv_insert_record($conn, $restore, $actor);
        lv_delete_dtr_override($conn, $lid);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    lv_recalculate($conn, (string)$rec['PIID'], (int)date('Y', strtotime($pfrom ?: date('Y-m-d'))));
    return $new_lid;
}

/**
 * Reschedule a leave record (transaction-wrapped).
 *
 * - Clones the row with new Period_From / Period_To (keeping all deduction amounts)
 * - Appends "-RESCHEDULED TO [mm/dd/yyyy - mm/dd/yyyy]" to original Particulars
 * - Zeros original VacWP and SickWP
 * - Deletes the original DTR override; creates a new DTR override for the cloned row
 * - Calls lv_recalculate() for both affected years
 *
 * Returns the new cloned row's LID.
 * Throws RuntimeException on invalid state.
 */
function lv_reschedule_record(
    PDO $conn, int $lid, string $new_from, string $new_to, string $filing_date, string $actor
): int {
    $rec = lv_get_record($conn, $lid);
    if (!$rec) throw new RuntimeException('Record not found');
    if (str_contains((string)($rec['Particulars'] ?? ''), '-RESCHEDULED TO ')) {
        throw new RuntimeException('Record has already been rescheduled');
    }
    if (!$new_from || !$new_to) throw new RuntimeException('New Period_From and Period_To are required');

    $from_ts = strtotime($new_from);
    $to_ts   = strtotime($new_to);
    if ($from_ts === false || $to_ts === false || $to_ts < $from_ts) {
        throw new RuntimeException('Invalid new date range');
    }

    if (!$filing_date) $filing_date = date('Y-m-d');
    $pars   = (string)($rec['Particulars'] ?? '');
    $conDay = date('m/d/Y', $from_ts) . ' - ' . date('m/d/Y', $to_ts);

    $conn->beginTransaction();
    try {
        $clone = [
            'PIID'             => $rec['PIID'],
            'Type_of_Records'  => $rec['Type_of_Records'],
            'Date_of_Filing'   => $filing_date,
            'Period_From'      => $new_from,
            'Period_To'        => $new_to,
            'VacEarn'          => (float)($rec['VacEarn']  ?? 0),
            'VacWP'            => (float)($rec['VacWP']    ?? 0),
            'VacBal'           => 0,
            'VacWOP'           => (float)($rec['VacWOP']   ?? 0),
            'SickEarn'         => (float)($rec['SickEarn'] ?? 0),
            'SickWP'           => (float)($rec['SickWP']   ?? 0),
            'SickBal'          => 0,
            'SickWOP'          => (float)($rec['SickWOP']  ?? 0),
            'Particulars'      => $pars,
            'DateAction'       => (string)($rec['DateAction'] ?? ''),
            'DateProcessed'    => $filing_date,
            'RecordedBy'       => $actor,
            'no_avail_VL'      => (float)($rec['no_avail_VL']      ?? 0),
            'no_avail_SL'      => (float)($rec['no_avail_SL']      ?? 0),
            'no_avail_mone_VL' => (float)($rec['no_avail_mone_VL'] ?? 0),
            'no_avail_mone_SL' => (float)($rec['no_avail_mone_SL'] ?? 0),
            'no_avail_SP'      => (float)($rec['no_avail_SP']      ?? 0),
            'no_avail_P'       => (float)($rec['no_avail_P']       ?? 0),
            'no_avail_mone'    => (float)($rec['no_avail_mone']    ?? 0),
            'Remarks'          => (string)($rec['Remarks'] ?? ''),
        ];
        $new_lid = lv_insert_record($conn, $clone, $actor);

        $conn->prepare(
            'UPDATE tbl_syl_leave_form SET Particulars = :p, VacWP = 0, SickWP = 0 WHERE LID = :lid'
        )->execute([':p' => $pars . '-RESCHEDULED TO ' . $conDay, ':lid' => $lid]);

        lv_delete_dtr_override($conn, $lid);
        lv_upsert_dtr_override(
            $conn, $new_lid, (string)$rec['Type_of_Records'],
            $new_from, $new_to, $pars, (string)$rec['PIID'], $actor
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    $piid      = (string)$rec['PIID'];
    $orig_year = (int)date('Y', strtotime((string)($rec['Period_From'] ?? date('Y-m-d'))));
    $new_year  = (int)date('Y', $from_ts);
    lv_recalculate($conn, $piid, $orig_year);
    if ($new_year !== $orig_year) lv_recalculate($conn, $piid, $new_year);
    return $new_lid;
}

// ── Balance forwarded ─────────────────────────────────────────────────────────

/**
 * Get the forwarded balance row for an employee for a given year.
 * Returns null if not found.
 */
function lv_get_saved_balance(PDO $conn, string $piid, int $year): ?array {
    lv_ensure_available_balance_schema($conn);
    $stmt = $conn->prepare(
        'SELECT piid, cBackVaca, cBalSick, NoAvailVL, NoAvailSL,
                NoAvailSLManual, NoAvailSLManualSetAt, MonetaryVL, MonetarySL, year
         FROM tbl_syl_leave_available_balance
         WHERE piid = :piid AND year = :year
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid, ':year' => $year]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        $row['NoAvailVL'] = lv_normalize_mandatory_forced_leave($row['NoAvailVL'] ?? null);
    }
    return $row;
}

function lv_auto_special_leave_forward(PDO $conn, string $piid, int $year): float {
    return _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE;
}

/**
 * Build an automatic forwarded balance from the previous year's closing row.
 * Annual leave counters such as Special Leave reset to their yearly defaults.
 * Saved balances still take precedence over this derived fallback.
 */
function lv_get_auto_forwarded_balance(PDO $conn, string $piid, int $year): ?array {
    if ($year <= 0) return null;
    $source_year = $year - 1;
    if ($source_year <= 0) return null;

    $prev_records = lv_get_records($conn, $piid, $source_year);
    if (!empty($prev_records)) {
        $last = $prev_records[count($prev_records) - 1];
        return [
            'piid'        => $piid,
            'year'        => $year,
            'cBackVaca'   => (float)($last['VacBal'] ?? 0),
            'cBalSick'    => (float)($last['SickBal'] ?? 0),
            'NoAvailVL'   => _LV_DEFAULT_MANDATORY_FORCED_LEAVE,
            'NoAvailSL'   => lv_auto_special_leave_forward($conn, $piid, $year),
            'NoAvailSLManual' => 0,
            'MonetaryVL'  => (float)($last['no_avail_mone_VL'] ?? 0),
            'MonetarySL'  => (float)($last['no_avail_mone_SL'] ?? 0),
            'sourceYear'  => $source_year,
            'isAutoDerived' => 1,
        ];
    }

    $prev_saved = lv_get_saved_balance($conn, $piid, $source_year);
    if ($prev_saved) {
        return [
            'piid'        => $piid,
            'year'        => $year,
            'cBackVaca'   => (float)($prev_saved['cBackVaca'] ?? 0),
            'cBalSick'    => (float)($prev_saved['cBalSick'] ?? 0),
            'NoAvailVL'   => _LV_DEFAULT_MANDATORY_FORCED_LEAVE,
            'NoAvailSL'   => lv_auto_special_leave_forward($conn, $piid, $year),
            'NoAvailSLManual' => 0,
            'MonetaryVL'  => (float)($prev_saved['MonetaryVL'] ?? 0),
            'MonetarySL'  => (float)($prev_saved['MonetarySL'] ?? 0),
            'sourceYear'  => $source_year,
            'isAutoDerived' => 1,
        ];
    }

    return null;
}

function lv_get_balance(PDO $conn, string $piid, int $year): ?array {
    $saved = lv_get_saved_balance($conn, $piid, $year);
    if ($saved) {
        $saved['sourceYear'] = $year - 1;
        $saved['isAutoDerived'] = 0;
        if (!lv_special_leave_manual_override($saved)) {
            $saved['NoAvailSL'] = lv_auto_special_leave_forward($conn, $piid, $year);
        }
        return $saved;
    }

    return lv_get_auto_forwarded_balance($conn, $piid, $year);
}

/**
 * Upsert the forwarded balance row for an employee/year.
 */
function lv_upsert_balance(PDO $conn, string $piid, int $year, array $d): void {
    lv_ensure_available_balance_schema($conn);
    $params = [
        ':piid' => $piid,
        ':year' => $year,
        ':vac'  => $d['cBackVaca']  ?? 0,
        ':sick' => $d['cBalSick']   ?? 0,
        ':nvl'  => $d['NoAvailVL']  ?? 0,
        ':nsl'  => $d['NoAvailSL']  ?? 0,
        ':nsl_manual' => !empty($d['NoAvailSLManual']) ? 1 : 0,
        ':nsl_manual_at' => !empty($d['NoAvailSLManual']) ? ($d['NoAvailSLManualSetAt'] ?? date('Y-m-d H:i:s')) : null,
        ':mvl'  => $d['MonetaryVL'] ?? 0,
        ':msl'  => $d['MonetarySL'] ?? 0,
    ];

    $stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_available_balance
         SET cBackVaca = :vac,
             cBalSick = :sick,
             NoAvailVL = :nvl,
             NoAvailSL = :nsl,
             NoAvailSLManual = :nsl_manual,
             NoAvailSLManualSetAt = :nsl_manual_at,
             MonetaryVL = :mvl,
             MonetarySL = :msl
         WHERE piid = :piid AND year = :year'
    );
    $stmt->execute($params);
    $exists = $conn->prepare(
        'SELECT 1
         FROM tbl_syl_leave_available_balance
         WHERE piid = :piid AND year = :year
         LIMIT 1'
    );
    $exists->execute([':piid' => $piid, ':year' => $year]);
    if ($exists->fetchColumn()) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_available_balance
            (piid, year, cBackVaca, cBalSick, NoAvailVL, NoAvailSL, NoAvailSLManual, NoAvailSLManualSetAt, MonetaryVL, MonetarySL)
         VALUES (:piid, :year, :vac, :sick, :nvl, :nsl, :nsl_manual, :nsl_manual_at, :mvl, :msl)'
    );
    $stmt->execute($params);
}

// ── Recalculation ─────────────────────────────────────────────────────────────

function lv_recalc_status_ensure_schema(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `" . _LV_RECALC_STATUS_TABLE . "` (
            `PIID` varchar(20) NOT NULL,
            `LeaveYear` int(11) NOT NULL,
            `LedgerFingerprint` varchar(80) NOT NULL,
            `LastRecalculatedAt` datetime NOT NULL,
            `Source` varchar(40) NOT NULL,
            PRIMARY KEY (`PIID`, `LeaveYear`),
            KEY `idx_lv_recalc_year_time` (`LeaveYear`, `LastRecalculatedAt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $ensured = true;
}

function lv_recalc_fingerprint_expr(): string {
    return "CONCAT(
        COUNT(*), ':',
        COALESCE(MAX(LID), 0), ':',
        COALESCE(SUM(CRC32(CONCAT_WS('|',
            LID,
            COALESCE(Type_of_Records, ''),
            COALESCE(Date_of_Filing, ''),
            COALESCE(Period_From, ''),
            COALESCE(Period_To, ''),
            COALESCE(VacEarn, 0),
            COALESCE(VacWP, 0),
            COALESCE(VacWOP, 0),
            COALESCE(SickEarn, 0),
            COALESCE(SickWP, 0),
            COALESCE(SickWOP, 0),
            COALESCE(no_avail_mone_VL, 0),
            COALESCE(no_avail_mone_SL, 0),
            COALESCE(no_avail_SL, 0),
            COALESCE(no_avail_VL, 0),
            COALESCE(no_avail_SP, 0),
            COALESCE(no_avail_P, 0)
        ))), 0)
    )";
}

function lv_recalc_fingerprints(PDO $conn, array $piids, int $year): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn(string $piid): bool => trim($piid) !== '')));
    if (!$piids) return [];

    $fingerprints = array_fill_keys($piids, '0:0:0');
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT PIID, " . lv_recalc_fingerprint_expr() . " AS LedgerFingerprint
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Date_of_Filing BETWEEN ? AND ?
         GROUP BY PIID"
    );
    $params = $piids;
    $params[] = sprintf('%04d-01-01', $year);
    $params[] = sprintf('%04d-12-31', $year);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $fingerprints[(string)$row['PIID']] = (string)($row['LedgerFingerprint'] ?? '0:0:0');
    }
    return $fingerprints;
}

function lv_recalc_statuses(PDO $conn, array $piids, int $year): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn(string $piid): bool => trim($piid) !== '')));
    if (!$piids) return [];

    lv_recalc_status_ensure_schema($conn);
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT PIID, LedgerFingerprint, LastRecalculatedAt
         FROM " . _LV_RECALC_STATUS_TABLE . "
         WHERE LeaveYear = ?
           AND PIID IN ($placeholders)"
    );
    $params = [$year];
    array_push($params, ...$piids);
    $stmt->execute($params);

    $statuses = [];
    foreach ($stmt->fetchAll() as $row) {
        $statuses[(string)$row['PIID']] = $row;
    }
    return $statuses;
}

function lv_recalc_mark_clean(PDO $conn, string $piid, int $year, string $fingerprint = '', string $source = 'manual'): void {
    lv_recalc_status_ensure_schema($conn);
    if ($fingerprint === '') {
        $fingerprints = lv_recalc_fingerprints($conn, [$piid], $year);
        $fingerprint = $fingerprints[$piid] ?? '0:0:0';
    }

    $stmt = $conn->prepare(
        "INSERT INTO " . _LV_RECALC_STATUS_TABLE . "
            (PIID, LeaveYear, LedgerFingerprint, LastRecalculatedAt, Source)
         VALUES
            (:piid, :year, :fingerprint, NOW(), :source)
         ON DUPLICATE KEY UPDATE
            LedgerFingerprint = VALUES(LedgerFingerprint),
            LastRecalculatedAt = VALUES(LastRecalculatedAt),
            Source = VALUES(Source)"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':year' => $year,
        ':fingerprint' => $fingerprint,
        ':source' => $source,
    ]);
}

function lv_recalculate_stale_for_employees(PDO $conn, array $piids, int $year, string $source = 'report'): int {
    $fingerprints = lv_recalc_fingerprints($conn, $piids, $year);
    $statuses = lv_recalc_statuses($conn, $piids, $year);
    $count = 0;

    foreach ($piids as $piid) {
        $piid = (string)$piid;
        $fingerprint = $fingerprints[$piid] ?? '0:0:0';
        $status = $statuses[$piid] ?? null;
        if ($status && (string)($status['LedgerFingerprint'] ?? '') === $fingerprint) {
            continue;
        }
        lv_recalculate($conn, $piid, $year, $source, $fingerprint);
        $count++;
    }

    return $count;
}

function lv_recalculate_for_employees(PDO $conn, array $piids, int $year, string $source = 'report'): int {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn($piid) => trim($piid) !== '')));
    if ($piids === []) {
        return 0;
    }

    $fingerprints = lv_recalc_fingerprints($conn, $piids, $year);
    $count = 0;
    foreach ($piids as $piid) {
        lv_recalculate($conn, $piid, $year, $source, $fingerprints[$piid] ?? '0:0:0');
        $count++;
    }

    return $count;
}

function lv_recalculate_stale_batch(PDO $conn, int $year, string $dept = '', int $limit = 50): array {
    $limit = max(1, min(300, $limit));
    $employees = lv_report_employee_base($conn, $year, (int)date('n'), $dept);
    if (!$employees) {
        return ['checked' => 0, 'recalculated' => 0, 'remaining_stale' => 0];
    }

    $piids = array_map(static fn(array $row): string => (string)$row['PIID'], $employees);
    $fingerprints = lv_recalc_fingerprints($conn, $piids, $year);
    $statuses = lv_recalc_statuses($conn, $piids, $year);
    $stale = [];

    foreach ($piids as $piid) {
        $fingerprint = $fingerprints[$piid] ?? '0:0:0';
        $status = $statuses[$piid] ?? null;
        if (!$status || (string)($status['LedgerFingerprint'] ?? '') !== $fingerprint) {
            $stale[] = $piid;
        }
    }

    $batch = array_slice($stale, 0, $limit);
    foreach ($batch as $piid) {
        lv_recalculate($conn, $piid, $year, 'warmup', $fingerprints[$piid] ?? '0:0:0');
    }

    return [
        'checked' => count($piids),
        'recalculated' => count($batch),
        'remaining_stale' => max(0, count($stale) - count($batch)),
    ];
}

function lv_recalculate_report_batch(PDO $conn, int $year, int $cutoff_month, string $dept = '', int $offset = 0, int $limit = 25): array {
    $cutoff_month = max(1, min(12, $cutoff_month));
    $offset = max(0, $offset);
    $limit = max(1, min(100, $limit));

    $employees = lv_report_employee_base($conn, $year, $cutoff_month, $dept);
    if (!$employees) {
        return ['total' => 0, 'offset' => $offset, 'processed' => 0, 'remaining' => 0, 'done' => true];
    }

    $total = count($employees);
    $batch = array_slice($employees, $offset, $limit);
    $piids = array_map(static fn(array $row): string => (string)$row['PIID'], $batch);
    $processed = lv_recalculate_for_employees($conn, $piids, $year, 'report-batch');
    $next_offset = $offset + count($batch);

    return [
        'total' => $total,
        'offset' => $offset,
        'next_offset' => $next_offset,
        'processed' => $processed,
        'remaining' => max(0, $total - $next_offset),
        'done' => $next_offset >= $total,
    ];
}

/**
 * Recalculate running VL and SL balances for all records in a year.
 *
 * Mirrors VB frmRevisedLeaveMngmnt.recalculateRecord():
 *  - Starts from forwarded balance (cBackVaca / cBalSick)
 *  - Each record: balance = prev_balance + earned - with_pay - without_pay - monetized
 *  - Updates VacBal / SickBal in-place.
 */
function lv_recalculate(PDO $conn, string $piid, int $year, string $source = 'manual', string $known_fingerprint = ''): void {
    $balance = lv_get_balance($conn, $piid, $year);
    $vBal  = (float)($balance['cBackVaca'] ?? 0);
    $sBal  = (float)($balance['cBalSick']  ?? 0);

    $records = lv_get_records($conn, $piid, $year);
    if (empty($records)) {
        lv_recalc_mark_clean($conn, $piid, $year, $known_fingerprint !== '' ? $known_fingerprint : '0:0:0', $source);
        return;
    }

    $savedSpecialBal = (float)($balance['NoAvailSL'] ?? 0);
    $specialBal = $savedSpecialBal > 0 ? $savedSpecialBal : _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE;
    $soloParentBal = 7.0;
    $paternityBal = 7.0;

    $upd = $conn->prepare(
        'UPDATE tbl_syl_leave_form
         SET VacBal = :vbal,
             SickBal = :sbal,
             no_avail_SL = :special_bal,
             no_avail_SP = :solo_parent_bal,
             no_avail_P = :paternity_bal
         WHERE LID = :lid'
    );

    lv_recalc_status_ensure_schema($conn);
    $startedTx = !$conn->inTransaction();
    if ($startedTx) {
        $conn->beginTransaction();
    }

    try {
        foreach ($records as $row) {
            $vBal = $vBal
                + (float)$row['VacEarn']
                - (float)$row['VacWP']
                - (float)$row['VacWOP']
                - (float)$row['no_avail_mone_VL'];

            $sBal = $sBal
                + (float)$row['SickEarn']
                - (float)$row['SickWP']
                - (float)$row['SickWOP']
                - (float)$row['no_avail_mone_SL'];

            $mainType = strtolower(lv_main_type((string)($row['Type_of_Records'] ?? '')));
            $days = lv_record_leave_days($row);
            if (in_array($mainType, ['others, (specify)', 'others (specify)', 'special privilege leave'], true)) {
                $specialBal -= $days;
            } elseif ($mainType === 'solo parent leave') {
                $soloParentBal -= $days;
            } elseif ($mainType === 'paternity leave') {
                $paternityBal -= $days;
            }

            $upd->execute([
                ':vbal' => round($vBal, 4),
                ':sbal' => round($sBal, 4),
                ':special_bal' => round(max(0, $specialBal), 4),
                ':solo_parent_bal' => round(max(0, $soloParentBal), 4),
                ':paternity_bal' => round(max(0, $paternityBal), 4),
                ':lid' => $row['LID'],
            ]);
        }

        lv_recalc_mark_clean($conn, $piid, $year, $known_fingerprint, $source);

        if ($startedTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

// ── Credits earned lookup table ───────────────────────────────────────────────

/**
 * Get all rows from the credits-earned lookup table.
 */
function lv_get_credits_earned(PDO $conn): array {
    $stmt = $conn->query(
        'SELECT LCEID, No_Of_Days_Present, On_Leave_Without_Pay, Leave_Credits_Earned
         FROM tbl_syl_leave_credits_earned
         ORDER BY No_Of_Days_Present ASC, On_Leave_Without_Pay ASC'
    );
    return $stmt->fetchAll();
}

/**
 * Replace the entire credits-earned lookup table with new rows.
 * Accepts array of ['days_present', 'lwop', 'credits'] maps.
 */
function lv_save_credits_earned(PDO $conn, array $rows): void {
    $conn->exec('TRUNCATE TABLE tbl_syl_leave_credits_earned');
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_credits_earned
            (No_Of_Days_Present, On_Leave_Without_Pay, Leave_Credits_Earned)
         VALUES (:dp, :lwop, :ce)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            ':dp'   => $r['days_present'],
            ':lwop' => $r['lwop'],
            ':ce'   => $r['credits'],
        ]);
    }
}

// ── Working-day conversion table ─────────────────────────────────────────────

/**
 * Get all rows from the working-day conversion table.
 */
function lv_get_conversion(PDO $conn): array {
    $stmt = $conn->query(
        "SELECT CWHMID, Time, Type, Equivalent_Day
         FROM tbl_syl_leave_conversion_of_working_day
         ORDER BY Type ASC, Time ASC"
    );
    return $stmt->fetchAll();
}

/**
 * Replace the entire conversion table with new rows.
 * Accepts array of ['time', 'type', 'equivalent_day'] maps.
 */
function lv_save_conversion(PDO $conn, array $rows): void {
    $conn->exec('TRUNCATE TABLE tbl_syl_leave_conversion_of_working_day');
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_conversion_of_working_day (Time, Type, Equivalent_Day)
         VALUES (:time, :type, :equiv)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            ':time'  => $r['time'],
            ':type'  => $r['type'],   // 'Hr' or 'Min'
            ':equiv' => $r['equivalent_day'],
        ]);
    }
}

// ── Dashboard stats ───────────────────────────────────────────────────────────

/**
 * Return aggregate counts for the dashboard stats strip.
 */
function lv_dashboard_salary_roster_sql(int $year): string {
    // One current snapshot per payroll template: latest year, then latest month
    // in that year. This mirrors payroll_get_roster()'s effective-dated roster
    // choice, but does it for all templates in two set-based queries.
    $year = max(2000, $year);
    $currentSnapshot = static function (string $table) use ($year): string {
        return "SELECT y.TID, y.PayYear, MAX(CAST(p.End_Num AS UNSIGNED)) AS End_Num
                  FROM (SELECT TID, MAX(CAST(Year AS UNSIGNED)) AS PayYear
                          FROM `$table`
                         WHERE isDeleted = 0
                           AND Quencina = 'whole'
                           AND CAST(Year AS UNSIGNED) = $year
                         GROUP BY TID) y
                  INNER JOIN `$table` p
                          ON p.TID = y.TID
                         AND CAST(p.Year AS UNSIGNED) = y.PayYear
                         AND p.isDeleted = 0
                         AND p.Quencina = 'whole'
                 GROUP BY y.TID, y.PayYear";
    };
    $regularSnapshot = $currentSnapshot('tbl_syl_payroll_parent');
    $casualSnapshot = $currentSnapshot('tbl_syl_payroll_parent_casual');
    return "SELECT p.PIID,
                   TRIM(CONCAT_WS(' ', NULLIF(TRIM(pi.SurName), ''), NULLIF(TRIM(pi.FirstName), ''), NULLIF(TRIM(pi.MiddleName), ''))) AS employee_name,
                   NULLIF(TRIM(tp.Name), '') AS office,
                   'Regular' AS payroll_source
              FROM tbl_syl_payroll_parent p
              INNER JOIN ($regularSnapshot) current ON current.TID = p.TID
                         AND CAST(p.Year AS UNSIGNED) = current.PayYear
                         AND CAST(p.End_Num AS UNSIGNED) = current.End_Num
              INNER JOIN tbl_template_payroll tp ON tp.TID = p.TID AND tp.isDeleted = 0
              LEFT JOIN tblpersonalinformation pi ON pi.PIID = p.PIID
             WHERE p.isDeleted = 0 AND p.Quencina = 'whole'
             UNION ALL
            SELECT p.PIID,
                   TRIM(CONCAT_WS(' ', NULLIF(TRIM(pi.SurName), ''), NULLIF(TRIM(pi.FirstName), ''), NULLIF(TRIM(pi.MiddleName), ''))) AS employee_name,
                   NULLIF(TRIM(tp.Name), '') AS office,
                   'Casual' AS payroll_source
              FROM tbl_syl_payroll_parent_casual p
              INNER JOIN ($casualSnapshot) current ON current.TID = p.TID
                         AND CAST(p.Year AS UNSIGNED) = current.PayYear
                         AND CAST(p.End_Num AS UNSIGNED) = current.End_Num
              INNER JOIN tbl_template_payroll_casual tp ON tp.TID = p.TID AND tp.isDeleted = 0
              LEFT JOIN tblpersonalinformation pi ON pi.PIID = p.PIID
             WHERE p.isDeleted = 0 AND p.Quencina = 'whole'";
}

function lv_dashboard_stats(PDO $conn): array {
    $year = (int)date('Y');
    $month = (int)date('n');
    // Do not refresh the roster here. Its source-fingerprint check scans legacy
    // payroll history and can stall the dashboard; payroll/leave maintenance
    // already refreshes this current snapshot through its normal workflow.

    $empStmt = $conn->prepare(
        'SELECT COUNT(*) FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month'
    );
    $empStmt->execute([
        ':snapshot_year' => $year,
        ':snapshot_month' => $month,
    ]);
    // The roster cache is refreshed from active Regular and Casual payroll
    // templates before this request. Querying it avoids scanning the large
    // legacy parent tables during every dashboard load.
    $salaryEmployees = (int)$empStmt->fetchColumn();
    $typeCountStmt = $conn->prepare(
        'SELECT IsCasual, COUNT(DISTINCT PIID) AS total FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year AND SnapshotMonth = :snapshot_month
         GROUP BY IsCasual'
    );
    $typeCountStmt->execute([':snapshot_year' => $year, ':snapshot_month' => $month]);
    $payrollTypeCounts = [0 => 0, 1 => 0];
    foreach ($typeCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payrollTypeCounts[(int)$row['IsCasual']] = (int)$row['total'];
    }
    $regularEmployees = $payrollTypeCounts[0];
    $casualEmployees = $payrollTypeCounts[1];

    // The leave roster is sourced from the Salary Payroll regular + casual
    // templates. JO staff deliberately do not enter that roster (it determines
    // leave eligibility), so add the separate JO payroll directory here only
    // for dashboard reporting. UNION prevents a PIID that appears in both
    // payroll streams from being counted twice.
    //
    // Prefer the directory cache: one row per person, their single most recent
    // JO assignment regardless of year — a JO office can have several
    // templates run by different preparers on different schedules, so a
    // hardcoded "this calendar year" filter here previously dropped anyone
    // whose only active template hadn't been repayrolled yet this period (the
    // same bug already fixed in lv_dashboard_payroll_employees()). The
    // template-roster fallback needs no such filter either post-fix: it
    // already holds each TID's own latest processed year, not "this year"
    // (see jo_template_roster_refresh()).
    $joDirectoryActive = jo_directory_is_active($conn);
    $joTemplateRosterActive = jo_template_roster_is_active($conn);
    $joSourceTable = $joDirectoryActive ? _JO_DIRECTORY_TABLE : _JO_TEMPLATE_ROSTER_TABLE;
    $joSourceActive = $joDirectoryActive || $joTemplateRosterActive;
    $payrollPopulationSql = 'SELECT PIID FROM ' . _LV_EMP_ROSTER_TABLE . '
                             WHERE SnapshotYear = :snapshot_year
                               AND SnapshotMonth = :snapshot_month';
    if ($joSourceActive) {
        $payrollPopulationSql .= ' UNION SELECT j.PIID FROM `' . $joSourceTable . '` j
                                  INNER JOIN `' . _JO_OFFICE_MAP_TABLE . '` m ON m.TID = j.TID AND m.is_active_template = 1';
    }
    $populationStmt = $conn->prepare('SELECT COUNT(*) FROM (' . $payrollPopulationSql . ') payroll_people');
    $populationStmt->execute([':snapshot_year' => $year, ':snapshot_month' => $month]);
    $employees = (int)$populationStmt->fetchColumn();

    $joEmployees = 0;
    if ($joSourceActive) {
        $joEmployees = (int)$conn->query('SELECT COUNT(DISTINCT j.PIID) FROM `' . $joSourceTable . '` j
                                          INNER JOIN `' . _JO_OFFICE_MAP_TABLE . '` m ON m.TID = j.TID AND m.is_active_template = 1
                                          WHERE NOT EXISTS (SELECT 1 FROM ' . _LV_EMP_ROSTER_TABLE . ' r
                                                            WHERE r.PIID = j.PIID
                                                              AND r.SnapshotYear = ' . $year . '
                                                              AND r.SnapshotMonth = ' . $month . ')')->fetchColumn();
    }

    $pds = 0; // pds_personal_info is in the cgmhris DB, not queried here

    $leaveStmt = $conn->prepare(
        "SELECT COUNT(*) FROM tbl_syl_leave_form WHERE YEAR(Period_From) = :yr AND isDeleted = 0"
    );
    $leaveStmt->execute([':yr' => $year]);
    $leave = (int)$leaveStmt->fetchColumn();

    $deptStmt = $conn->prepare(
        'SELECT COUNT(DISTINCT Department) FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month'
    );
    $deptStmt->execute([
        ':snapshot_year' => $year,
        ':snapshot_month' => $month,
    ]);
    $depts = (int)$deptStmt->fetchColumn();

    lv_ensure_leave_applications_table($conn);
    $pendingStmt = $conn->prepare(
        "SELECT COUNT(*) FROM tbl_leave_applications WHERE status = 'Pending'"
    );
    $pendingStmt->execute();
    $pendingApplications = (int)$pendingStmt->fetchColumn();

    return [
        'employees'   => $employees,
        'salary_employees' => $salaryEmployees,
        'regular_employees' => $regularEmployees,
        'casual_employees' => $casualEmployees,
        'jo_employees' => $joEmployees,
        'pds'         => $pds,
        'leave'       => $leave,
        'departments' => $depts,
        'pending_applications' => $pendingApplications,
    ];
}

/**
 * Combined, de-duplicated payroll population for the dashboard directory.
 * Salary Payroll contains regular and casual employees; JO comes from the
 * separately maintained JO payroll directory so it never affects leave rules.
 */
function lv_dashboard_payroll_employees(PDO $conn, int $limit = 100, int $offset = 0, string $source = '', string $search = '', string $department = '', string $status = ''): array {
    $year = (int)date('Y');
    $month = (int)date('n');
    // Dashboard reads are intentionally cache-only; see lv_dashboard_stats().
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $source = in_array($source, ['Regular', 'Casual', 'Job Order'], true) ? $source : '';
    $search = trim($search);
    $department = trim($department);
    $status = trim($status);

    // A JO office can own multiple templates, each run by its own preparer on
    // its own schedule. Prefer the directory cache (one row per person, their
    // most recent assignment regardless of year) so a template that hasn't
    // been repayrolled this period still contributes its people; fall back to
    // reading each template's newest saved roster directly only if the
    // directory cache isn't built yet.
    if ($source === 'Job Order' && $department !== '') {
        $roster = jo_directory_is_active($conn)
            ? jo_directory_roster_for_offices($conn, [$department])
            : jo_latest_roster_for_offices($conn, [$department]);
        // A JO roster row can outlive an employee's promotion to Regular/Casual
        // if their old JO template entry was never retired. Anyone already on
        // this period's salary roster is not still a Job Order employee.
        $currentStmt = $conn->prepare('SELECT PIID FROM ' . _LV_EMP_ROSTER_TABLE . ' WHERE SnapshotYear = :y AND SnapshotMonth = :m');
        $currentStmt->execute([':y' => $year, ':m' => $month]);
        $currentPiids = array_fill_keys(array_map('strval', $currentStmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $roster = array_values(array_filter($roster, static fn($m) => !isset($currentPiids[(string)$m['PIID']])));
        $piids = array_column($roster, 'PIID');
        $names = [];
        if ($piids) {
            $marks = implode(',', array_fill(0, count($piids), '?'));
            $peopleStmt = $conn->prepare(
                "SELECT PIID, TRIM(CONCAT_WS(' ', NULLIF(TRIM(SurName), ''), NULLIF(TRIM(FirstName), ''), NULLIF(TRIM(MiddleName), ''))) AS employee_name
                   FROM tblpersonalinformation WHERE PIID IN ($marks)"
            );
            $peopleStmt->execute($piids);
            foreach ($peopleStmt->fetchAll(PDO::FETCH_ASSOC) as $person) $names[(string)$person['PIID']] = (string)$person['employee_name'];
        }
        $rows = [];
        foreach ($roster as $member) {
            if ($status !== '' && strcasecmp($status, 'Job Order') !== 0) continue;
            $name = trim($names[$member['PIID']] ?? '');
            if ($name === '') $name = 'Employee ' . $member['PIID'];
            if ($search !== '' && stripos($name, $search) === false && stripos((string)$member['PIID'], $search) === false) continue;
            $rows[] = ['PIID' => $member['PIID'], 'employee_name' => $name, 'office' => $member['office'], 'employee_status' => 'Job Order', 'payroll_source' => 'Job Order'];
        }
        usort($rows, static fn($a, $b) => [$a['employee_name'], $a['PIID']] <=> [$b['employee_name'], $b['PIID']]);
        $total = count($rows);
        return ['total' => $total, 'rows' => array_slice($rows, $offset, $limit), 'departments' => [$department], 'statuses' => ['Job Order']];
    }

    $salarySql = "SELECT r.PIID,
                          TRIM(CONCAT_WS(' ', NULLIF(TRIM(r.Surname), ''), NULLIF(TRIM(r.Firstname), ''), NULLIF(TRIM(r.MiddleName), ''))) AS employee_name,
                          NULLIF(TRIM(r.Department), '') AS office,
                          NULLIF(TRIM(r.Status), '') AS employee_status,
                          CASE WHEN r.IsCasual = 1 THEN 2 ELSE 3 END AS source_priority,
                          CASE WHEN r.IsCasual = 1 THEN 'Casual' ELSE 'Regular' END AS payroll_source
                     FROM " . _LV_EMP_ROSTER_TABLE . " r
                    WHERE r.SnapshotYear = :snapshot_year
                      AND r.SnapshotMonth = :snapshot_month";
    $params = [':snapshot_year' => $year, ':snapshot_month' => $month];
    $sources = [$salarySql];

    // The directory cache carries exactly one row per person (their most
    // recent JO assignment, any year), so it needs no year filter and, unlike
    // the raw template roster, isn't blind to a template that hasn't been
    // repayrolled this calendar year. Only fall back to the template roster
    // (current-year rows, per-PIID latest year picked via a plain join — not
    // a hardcoded "this year" which drops anyone whose template went dormant)
    // when the directory cache hasn't been built yet.
    if (jo_directory_is_active($conn)) {
        $sources[] = "SELECT j.PIID,
                             TRIM(CONCAT_WS(' ', NULLIF(TRIM(pi.SurName), ''), NULLIF(TRIM(pi.FirstName), ''), NULLIF(TRIM(pi.MiddleName), ''))) AS employee_name,
                             NULLIF(TRIM(j.Office), '') AS office,
                             'Job Order' AS employee_status,
                             1 AS source_priority,
                             'Job Order' AS payroll_source
                        FROM `" . _JO_DIRECTORY_TABLE . "` j
                   INNER JOIN `" . _JO_OFFICE_MAP_TABLE . "` m ON m.TID = j.TID AND m.is_active_template = 1
                   LEFT JOIN tblpersonalinformation pi ON pi.PIID = j.PIID";
    } elseif (jo_template_roster_is_active($conn)) {
        $sources[] = "SELECT j.PIID,
                             TRIM(CONCAT_WS(' ', NULLIF(TRIM(pi.SurName), ''), NULLIF(TRIM(pi.FirstName), ''), NULLIF(TRIM(pi.MiddleName), ''))) AS employee_name,
                             NULLIF(TRIM(j.Office), '') AS office,
                             'Job Order' AS employee_status,
                             1 AS source_priority,
                             'Job Order' AS payroll_source
                        FROM `" . _JO_TEMPLATE_ROSTER_TABLE . "` j
                   INNER JOIN (SELECT PIID, MAX(CAST(PayYear AS UNSIGNED)) AS latest_year
                                 FROM `" . _JO_TEMPLATE_ROSTER_TABLE . "` GROUP BY PIID) ly
                           ON ly.PIID = j.PIID AND ly.latest_year = CAST(j.PayYear AS UNSIGNED)
                   INNER JOIN `" . _JO_OFFICE_MAP_TABLE . "` m ON m.TID = j.TID AND m.is_active_template = 1
                   LEFT JOIN tblpersonalinformation pi ON pi.PIID = j.PIID";
    }

    $unionSql = implode(' UNION ALL ', $sources);
    $groupedSql = "SELECT PIID,
                          MAX(employee_name) AS employee_name,
                          MAX(office) AS office,
                          MAX(employee_status) AS employee_status,
                          SUBSTRING_INDEX(GROUP_CONCAT(payroll_source ORDER BY source_priority DESC SEPARATOR ','), ',', 1) AS payroll_source
                     FROM ($unionSql) payroll_source_rows
                    GROUP BY PIID";
    $filters = ['1'];
    if ($source !== '') { $filters[] = 'payroll_source LIKE :source'; $params[':source'] = '%' . $source . '%'; }
    if ($search !== '') { $filters[] = '(employee_name LIKE :search OR PIID LIKE :search)'; $params[':search'] = '%' . $search . '%'; }
    if ($department !== '') { $filters[] = 'office = :department'; $params[':department'] = $department; }
    if ($status !== '') { $filters[] = 'employee_status = :status'; $params[':status'] = $status; }
    $filteredSql = 'SELECT * FROM (' . $groupedSql . ') payroll_people WHERE ' . implode(' AND ', $filters);
    $countStmt = $conn->prepare('SELECT COUNT(*) FROM (' . $filteredSql . ') filtered_payroll_people');
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listStmt = $conn->prepare($filteredSql . ' ORDER BY employee_name ASC, PIID ASC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $listStmt->execute($params);
    $filterStmt = $conn->prepare('SELECT DISTINCT office, employee_status FROM (' . $groupedSql . ') payroll_people ORDER BY office, employee_status');
    $filterStmt->execute(array_intersect_key($params, [':snapshot_year' => true, ':snapshot_month' => true]));
    $departments = []; $statuses = [];
    foreach ($filterStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['office'] ?? '') !== '') $departments[] = $row['office'];
        if (($row['employee_status'] ?? '') !== '') $statuses[] = $row['employee_status'];
    }
    return ['total' => $total, 'rows' => $listStmt->fetchAll(PDO::FETCH_ASSOC), 'departments' => array_values(array_unique($departments)), 'statuses' => array_values(array_unique($statuses))];
}

// ── Monthly / quarterly report helpers ───────────────────────────────────────

function lv_report_employee_base(PDO $conn, int $year, int $cutoff_month, string $dept = ''): array {
    lv_ensure_employee_roster($conn, $year, $cutoff_month);
    $params = [];
    $where = ['1'];

    if ($dept !== '') {
        $where[] = 'Department = :dept';
        $params[':dept'] = $dept;
    }

    $stmt = $conn->prepare(
        'SELECT PIID,
                Surname,
                Firstname,
                MiddleName,
                Department,
                Position
         FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month
           AND ' . implode(' AND ', $where) . '
         ORDER BY Department, Surname, Firstname'
    );
    $params[':snapshot_year'] = $year;
    $params[':snapshot_month'] = $cutoff_month;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_rows_up_to_cutoff(PDO $conn, array $piids, int $year, string $cutoff_date): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacBal, SickBal, no_avail_VL, no_avail_SL,
                no_avail_mone_VL, no_avail_mone_SL,
                no_avail_SP, no_avail_P
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Date_of_Filing BETWEEN ? AND ?
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC"
    );
    $params = array_values($piids);
    $params[] = sprintf('%04d-01-01', $year);
    $params[] = $cutoff_date;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_rows_in_period(PDO $conn, array $piids, string $period_from, string $period_to): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacWP, VacWOP, SickWP, SickWOP,
                no_avail_mone_VL, no_avail_mone_SL, no_avail_SP, no_avail_P
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Period_From BETWEEN ? AND ?
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC"
    );
    $params = array_values($piids);
    $params[] = $period_from;
    $params[] = $period_to;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_forwarded_balances(PDO $conn, array $piids, int $year): array {
    if (empty($piids)) return [];
    lv_ensure_available_balance_schema($conn);
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT piid, cBackVaca, cBalSick, NoAvailVL, NoAvailSL, NoAvailSLManual, NoAvailSLManualSetAt
         FROM tbl_syl_leave_available_balance
         WHERE year = ?
           AND piid IN ($placeholders)"
    );
    $params = [$year];
    foreach ($piids as $piid) {
        $params[] = $piid;
    }
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['NoAvailVL'] = lv_normalize_mandatory_forced_leave($row['NoAvailVL'] ?? null);
        if (!lv_special_leave_manual_override($row)) {
            $row['NoAvailSL'] = lv_auto_special_leave_forward($conn, (string)$row['piid'], $year);
        }
        $rows[$row['piid']] = $row;
    }
    return $rows;
}

function lv_special_leave_days_used(PDO $conn, string $piid, int $year): float {
    $days = 0.0;
    foreach (lv_get_records($conn, $piid, $year) as $row) {
        $mainType = strtolower(lv_main_type((string)($row['Type_of_Records'] ?? '')));
        if (in_array($mainType, ['others, (specify)', 'others (specify)', 'special privilege leave'], true)) {
            $days += lv_record_leave_days($row);
        }
    }
    return round($days, 4);
}

function lv_report_type_matches(string $type, array $needles): bool {
    $type = strtolower(trim($type));
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($type, strtolower($needle))) {
            return true;
        }
    }
    return false;
}

function lv_report_main_type(string $type): string {
    $parts = explode('|', $type, 2);
    return trim($parts[0] ?? $type);
}

function lv_report_period_display(array $row): string {
    $from = trim((string)($row['Period_From'] ?? ''));
    $to = trim((string)($row['Period_To'] ?? ''));
    $source = $from !== '' ? $from : trim((string)($row['Date_of_Filing'] ?? ''));
    if ($source === '') return '';

    $dtFrom = strtotime($source);
    if ($dtFrom === false) return $source;

    $toSource = $to !== '' ? $to : $source;
    $dtTo = strtotime($toSource);
    if ($dtTo === false) {
        return date('M j, Y', $dtFrom);
    }

    if (date('Y-m-d', $dtFrom) === date('Y-m-d', $dtTo)) {
        return date('M j, Y', $dtFrom);
    }

    if (date('Y-m', $dtFrom) === date('Y-m', $dtTo)) {
        return date('M j', $dtFrom) . '-' . date('j, Y', $dtTo);
    }

    if (date('Y', $dtFrom) === date('Y', $dtTo)) {
        return date('M j', $dtFrom) . '-' . date('M j, Y', $dtTo);
    }

    return date('M j, Y', $dtFrom) . ' - ' . date('M j, Y', $dtTo);
}

function lv_report_has_vacation_application(array $row, string $type): bool {
    $mainType = strtolower(lv_report_main_type($type));
    return in_array($mainType, [
        'vacation leave',
        'vacation leave w/o pay',
    ], true);
}

function lv_report_has_sick_application(array $row, string $type): bool {
    $mainType = strtolower(lv_report_main_type($type));
    return in_array($mainType, [
        'sick leave',
        'sick leave w/o pay',
    ], true);
}

function lv_report_is_monetization(string $type): bool {
    return strtolower(lv_report_main_type($type)) === 'monetization of leave credits';
}

/**
 * Get leave balance report per employee for a given year and period.
 * Balances are derived from the latest ledger row on or before the selected
 * period cutoff date. Activity fields use the selected month/quarter window.
 */
function lv_report_summary(PDO $conn, int $year, ?int $month_from = null, ?int $month_to = null, string $dept = '', bool $recalculate = true): array {
    $month_from = $month_from !== null ? max(1, min(12, $month_from)) : 1;
    $month_to   = $month_to   !== null ? max(1, min(12, $month_to))   : 12;
    if ($month_from > $month_to) {
        [$month_from, $month_to] = [$month_to, $month_from];
    }

    $period_from = sprintf('%04d-%02d-01', $year, $month_from);
    $cutoff_date = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month_to)));

    $employees = lv_report_employee_base($conn, $year, $month_to, $dept);
    if (empty($employees)) return [];

    $piids = array_column($employees, 'PIID');

    if ($recalculate) {
        // The report is an official balance surface, so rebuild running balances
        // before reading rows instead of trusting cached recalc status.
        lv_recalculate_for_employees($conn, $piids, $year, 'report');
    }

    $up_to_cutoff = lv_report_rows_up_to_cutoff($conn, $piids, $year, $cutoff_date);
    $in_period = lv_report_rows_in_period($conn, $piids, $period_from, $cutoff_date);
    $forwarded = lv_report_forwarded_balances($conn, $piids, $year);

    $latest_by_piid = [];
    foreach ($up_to_cutoff as $row) {
        $latest_by_piid[$row['PIID']] = $row;
    }

    $wellness_used = [];
    foreach ($up_to_cutoff as $row) {
        $piid = $row['PIID'];
        if (!isset($wellness_used[$piid])) {
            $wellness_used[$piid] = 0.0;
        }
        $wellness_used[$piid] += lv_wellness_days_for_record($row);
    }

    $activity = [];
    foreach ($in_period as $row) {
        $piid = $row['PIID'];
        if (!isset($activity[$piid])) {
            $activity[$piid] = [
                'vac_applied_display' => '',
                'sick_applied_display' => '',
                'vac_applied_dates' => [],
                'sick_applied_dates' => [],
                'monetized_vl' => 0.0,
                'monetized_sl' => 0.0,
            ];
        }

        $type = (string)($row['Type_of_Records'] ?? '');
        $display = lv_report_period_display($row);

        if ($display !== '' && lv_report_has_vacation_application($row, $type)) {
            $activity[$piid]['vac_applied_dates'][$display] = true;
        }

        if ($display !== '' && lv_report_has_sick_application($row, $type)) {
            $activity[$piid]['sick_applied_dates'][$display] = true;
        }

        if (lv_report_is_monetization($type)) {
            $vlMonetized = (float)($row['no_avail_mone_VL'] ?? 0);
            $slMonetized = (float)($row['no_avail_mone_SL'] ?? 0);

            // Existing ledger rows often store monetization in the same
            // visible vacation/sick deduction fields shown on leave_form.
            if ($vlMonetized == 0.0) {
                $vlMonetized = (float)($row['VacWP'] ?? 0);
            }
            if ($slMonetized == 0.0) {
                $slMonetized = (float)($row['SickWP'] ?? 0);
            }

            $activity[$piid]['monetized_vl'] += $vlMonetized;
            $activity[$piid]['monetized_sl'] += $slMonetized;
        } else {
            $activity[$piid]['monetized_vl'] += (float)($row['no_avail_mone_VL'] ?? 0);
            $activity[$piid]['monetized_sl'] += (float)($row['no_avail_mone_SL'] ?? 0);
        }
    }

    $report = [];
    foreach ($employees as $emp) {
        $piid = $emp['PIID'];
        $latest = $latest_by_piid[$piid] ?? null;
        $fwd = $forwarded[$piid] ?? null;
        if ($fwd === null) {
            $fwd = lv_get_balance($conn, (string)$piid, $year) ?? [
                'cBackVaca' => 0,
                'cBalSick' => 0,
                'NoAvailVL' => _LV_DEFAULT_MANDATORY_FORCED_LEAVE,
                'NoAvailSL' => _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE,
            ];
        }
        $act = $activity[$piid] ?? [
            'vac_applied_display' => '',
            'sick_applied_display' => '',
            'vac_applied_dates' => [],
            'sick_applied_dates' => [],
            'monetized_vl' => 0.0,
            'monetized_sl' => 0.0,
        ];

        $emp['vac_balance'] = $latest !== null
            ? (float)($latest['VacBal'] ?? 0)
            : (float)($fwd['cBackVaca'] ?? 0);
        $emp['sick_balance'] = $latest !== null
            ? (float)($latest['SickBal'] ?? 0)
            : (float)($fwd['cBalSick'] ?? 0);
        $emp['vac_applied_display'] = !empty($act['vac_applied_dates'])
            ? implode('; ', array_keys($act['vac_applied_dates']))
            : '';
        $emp['sick_applied_display'] = !empty($act['sick_applied_dates'])
            ? implode('; ', array_keys($act['sick_applied_dates']))
            : '';
        $emp['monetized_vl'] = round($act['monetized_vl'], 4);
        $emp['monetized_sl'] = round($act['monetized_sl'], 4);
        $emp['available_mandatory_forced_leave'] = (float)($fwd['NoAvailVL'] ?? _LV_DEFAULT_MANDATORY_FORCED_LEAVE);
        $emp['available_special_leave'] = $latest !== null
            ? (float)($latest['no_avail_SL'] ?? 0)
            : (float)($fwd['NoAvailSL'] ?? 0);
        $emp['remaining_wellness_leave'] = round(max(0, 5 - (float)($wellness_used[$piid] ?? 0)), 4);
        $emp['cutoff_date'] = $cutoff_date;
        $emp['employee_ref'] = lv_employee_ref((string)$piid);

        if (
            $emp['vac_balance'] == 0.0 &&
            $emp['sick_balance'] == 0.0 &&
            $emp['vac_applied_display'] === '' &&
            $emp['sick_applied_display'] === '' &&
            $emp['monetized_vl'] == 0.0 &&
            $emp['monetized_sl'] == 0.0 &&
            $emp['available_mandatory_forced_leave'] == 0.0 &&
            $emp['available_special_leave'] == 0.0
        ) {
            continue;
        }

        $report[] = $emp;
    }

    return $report;
}

function lv_ensure_leave_applications_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_leave_applications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid VARCHAR(20) NOT NULL,
            id_number VARCHAR(20) DEFAULT NULL,
            employee_name VARCHAR(150) DEFAULT NULL,
            department VARCHAR(150) DEFAULT NULL,
            leave_type VARCHAR(100) NOT NULL,
            type_other VARCHAR(100) DEFAULT NULL,
            date_filed DATE NOT NULL,
            date_inputted DATE DEFAULT NULL,
            period_from DATE NOT NULL,
            period_to DATE NOT NULL,
            half_day_mode VARCHAR(10) DEFAULT NULL,
            half_day_window VARCHAR(40) DEFAULT NULL,
            days_requested DECIMAL(8,3) NOT NULL DEFAULT 0.000,
            vacation_location VARCHAR(30) DEFAULT NULL,
            vacation_abroad_details VARCHAR(100) DEFAULT NULL,
            sick_leave_detail VARCHAR(30) DEFAULT NULL,
            sick_illness VARCHAR(150) DEFAULT NULL,
            study_leave_detail VARCHAR(40) DEFAULT NULL,
            study_purpose VARCHAR(150) DEFAULT NULL,
            monetization_purpose VARCHAR(150) DEFAULT NULL,
            monetization_purpose_category VARCHAR(30) DEFAULT NULL,
            monetization_vl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000,
            monetization_sl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000,
            monetization_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            monetization_track VARCHAR(20) DEFAULT NULL,
            approval_route VARCHAR(50) NOT NULL DEFAULT 'standard',
            particulars VARCHAR(255) DEFAULT NULL,
            reason TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            dept_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            dept_remarks TEXT,
            dept_approved_by VARCHAR(100) DEFAULT NULL,
            dept_approved_at DATETIME DEFAULT NULL,
            dept_rejected_by VARCHAR(100) DEFAULT NULL,
            dept_rejected_at DATETIME DEFAULT NULL,
            city_admin_status VARCHAR(20) DEFAULT NULL,
            city_mayor_status VARCHAR(20) DEFAULT NULL,
            city_admin_remarks TEXT,
            city_admin_approved_by VARCHAR(100) DEFAULT NULL,
            city_admin_approved_at DATETIME DEFAULT NULL,
            city_admin_rejected_by VARCHAR(100) DEFAULT NULL,
            city_admin_rejected_at DATETIME DEFAULT NULL,
            admin_remarks TEXT,
            cancellation_reason TEXT,
            approved_by VARCHAR(100) DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            rejected_by VARCHAR(100) DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            cancelled_by_employee_at DATETIME DEFAULT NULL,
            posted_lid INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_leave_app_piid (piid),
            KEY idx_leave_app_status (status),
            KEY idx_leave_app_date_filed (date_filed),
            KEY idx_leave_app_status_department (status, department),
            KEY idx_leave_app_dept_status (dept_status, department)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $columns = [];
    $stmt = $conn->query("SHOW COLUMNS FROM tbl_leave_applications");
    foreach ($stmt->fetchAll() as $row) {
        $columns[strtolower((string)$row['Field'])] = true;
    }
    $alter = [];
    if (!isset($columns['type_other'])) {
        $alter[] = "ADD COLUMN type_other VARCHAR(100) DEFAULT NULL AFTER leave_type";
    }
    if (!isset($columns['date_inputted'])) {
        $alter[] = "ADD COLUMN date_inputted DATE DEFAULT NULL AFTER date_filed";
    }
    if (!isset($columns['half_day_mode'])) {
        $alter[] = "ADD COLUMN half_day_mode VARCHAR(10) DEFAULT NULL AFTER period_to";
    }
    if (!isset($columns['half_day_window'])) {
        $alter[] = "ADD COLUMN half_day_window VARCHAR(40) DEFAULT NULL AFTER half_day_mode";
    }
    if (!isset($columns['vacation_location'])) {
        $alter[] = "ADD COLUMN vacation_location VARCHAR(30) DEFAULT NULL AFTER days_requested";
    }
    if (!isset($columns['vacation_abroad_details'])) {
        $alter[] = "ADD COLUMN vacation_abroad_details VARCHAR(100) DEFAULT NULL AFTER vacation_location";
    }
    if (!isset($columns['sick_leave_detail'])) {
        $alter[] = "ADD COLUMN sick_leave_detail VARCHAR(30) DEFAULT NULL AFTER vacation_abroad_details";
    }
    if (!isset($columns['sick_illness'])) {
        $alter[] = "ADD COLUMN sick_illness VARCHAR(150) DEFAULT NULL AFTER sick_leave_detail";
    }
    if (!isset($columns['study_leave_detail'])) {
        $alter[] = "ADD COLUMN study_leave_detail VARCHAR(40) DEFAULT NULL AFTER sick_illness";
    }
    if (!isset($columns['study_purpose'])) {
        $alter[] = "ADD COLUMN study_purpose VARCHAR(150) DEFAULT NULL AFTER study_leave_detail";
    }
    if (!isset($columns['monetization_purpose'])) {
        $alter[] = "ADD COLUMN monetization_purpose VARCHAR(150) DEFAULT NULL AFTER study_purpose";
    }
    if (!isset($columns['monetization_purpose_category'])) {
        $alter[] = "ADD COLUMN monetization_purpose_category VARCHAR(30) DEFAULT NULL AFTER monetization_purpose";
    }
    if (!isset($columns['monetization_vl_days'])) {
        $alter[] = "ADD COLUMN monetization_vl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000 AFTER monetization_purpose_category";
    }
    if (!isset($columns['monetization_sl_days'])) {
        $alter[] = "ADD COLUMN monetization_sl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000 AFTER monetization_vl_days";
    }
    if (!isset($columns['monetization_amount'])) {
        $alter[] = "ADD COLUMN monetization_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER monetization_sl_days";
    }
    if (!isset($columns['monetization_track'])) {
        $alter[] = "ADD COLUMN monetization_track VARCHAR(20) DEFAULT NULL AFTER monetization_amount";
    }
    if (!isset($columns['approval_route'])) {
        $alter[] = "ADD COLUMN approval_route VARCHAR(50) NOT NULL DEFAULT 'standard' AFTER monetization_track";
    }
    if (!isset($columns['dept_status'])) {
        $alter[] = "ADD COLUMN dept_status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER status";
    }
    if (!isset($columns['dept_remarks'])) {
        $alter[] = "ADD COLUMN dept_remarks TEXT AFTER dept_status";
    }
    if (!isset($columns['dept_approved_by'])) {
        $alter[] = "ADD COLUMN dept_approved_by VARCHAR(100) DEFAULT NULL AFTER dept_remarks";
    }
    if (!isset($columns['dept_approved_at'])) {
        $alter[] = "ADD COLUMN dept_approved_at DATETIME DEFAULT NULL AFTER dept_approved_by";
    }
    if (!isset($columns['dept_rejected_by'])) {
        $alter[] = "ADD COLUMN dept_rejected_by VARCHAR(100) DEFAULT NULL AFTER dept_approved_at";
    }
    if (!isset($columns['dept_rejected_at'])) {
        $alter[] = "ADD COLUMN dept_rejected_at DATETIME DEFAULT NULL AFTER dept_rejected_by";
    }
    if (!isset($columns['city_admin_status'])) {
        $alter[] = "ADD COLUMN city_admin_status VARCHAR(20) DEFAULT NULL AFTER dept_rejected_at";
    }
    if (!isset($columns['city_mayor_status'])) {
        $alter[] = "ADD COLUMN city_mayor_status VARCHAR(20) DEFAULT NULL AFTER city_admin_status";
    }
    if (!isset($columns['city_admin_remarks'])) {
        $alter[] = "ADD COLUMN city_admin_remarks TEXT AFTER city_admin_status";
    }
    if (!isset($columns['city_admin_approved_by'])) {
        $alter[] = "ADD COLUMN city_admin_approved_by VARCHAR(100) DEFAULT NULL AFTER city_admin_remarks";
    }
    if (!isset($columns['city_admin_approved_at'])) {
        $alter[] = "ADD COLUMN city_admin_approved_at DATETIME DEFAULT NULL AFTER city_admin_approved_by";
    }
    if (!isset($columns['city_admin_rejected_by'])) {
        $alter[] = "ADD COLUMN city_admin_rejected_by VARCHAR(100) DEFAULT NULL AFTER city_admin_approved_at";
    }
    if (!isset($columns['city_admin_rejected_at'])) {
        $alter[] = "ADD COLUMN city_admin_rejected_at DATETIME DEFAULT NULL AFTER city_admin_rejected_by";
    }
    if (!isset($columns['cancellation_reason'])) {
        $alter[] = "ADD COLUMN cancellation_reason TEXT AFTER admin_remarks";
    }
    foreach ($alter as $statement) {
        $conn->exec("ALTER TABLE tbl_leave_applications " . $statement);
    }

    $ensured = true;
}

function lv_application_now(): string {
    return date('Y-m-d H:i:s');
}

function lv_application_employee_name(array $employee): string {
    $nameExt = trim((string)($employee['NameExt'] ?? ''));
    if (in_array(strtoupper($nameExt), ['', 'N/A', 'NA', 'NONE', '-'], true)) {
        $nameExt = '';
    }
    $parts = [
        trim((string)($employee['Firstname'] ?? '')),
        trim((string)($employee['MiddleName'] ?? '')) !== ''
            ? strtoupper(substr(trim((string)$employee['MiddleName']), 0, 1)) . '.'
            : '',
        trim((string)($employee['Surname'] ?? '')),
        $nameExt,
    ];
    return trim(implode(' ', array_filter($parts, static fn($v) => $v !== '')));
}

function lv_application_period_label(string $periodFrom, string $periodTo): string {
    if ($periodFrom === '') {
        return '';
    }

    try {
        $from = new DateTime($periodFrom);
        $to = $periodTo !== '' ? new DateTime($periodTo) : $from;
    } catch (Exception $e) {
        return strtoupper($periodFrom . ($periodTo !== '' && $periodTo !== $periodFrom ? '-' . $periodTo : ''));
    }

    $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    $fromMonth = $months[(int)$from->format('n') - 1];
    $toMonth = $months[(int)$to->format('n') - 1];
    $fromDay = (int)$from->format('j');
    $toDay = (int)$to->format('j');
    $fromYear = $from->format('Y');

    if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
        $days = (string)$fromDay;
    } elseif ($from->format('n') !== $to->format('n')) {
        $days = $fromDay . '-' . $toMonth . ' ' . $toDay;
    } else {
        $days = $fromDay . '-' . $toDay;
    }

    return $fromMonth . ' ' . $days . ' ,' . $fromYear;
}

function lv_calculate_days_requested(string $type, string $periodFrom, string $periodTo): float {
    return lv_count_leave_days_between($periodFrom, $periodTo, lv_type_includes_weekends($type));
}

function lv_application_half_day_mode(array $row): string {
    $mode = strtolower(trim((string)($row['half_day_mode'] ?? '')));
    return in_array($mode, ['am', 'pm'], true) ? $mode : 'full';
}

function lv_normalized_application_days(array $row): float {
    $stored = (float)($row['days_requested'] ?? 0);
    $mode = lv_application_half_day_mode($row);
    if ($mode === 'full') {
        return $stored;
    }

    $type = (string)($row['leave_type'] ?? '');
    $from = (string)($row['period_from'] ?? '');
    $to = (string)($row['period_to'] ?? '');
    if ($type === '' || $from === '' || $to === '') {
        return $stored;
    }

    $fullDays = lv_calculate_days_requested($type, $from, $to);
    if ($fullDays <= 1.0 && !lv_is_compensatory_type($type)) {
        return $stored;
    }

    return round($fullDays - 0.5, 3);
}

function lv_decorate_leave_application_row(array $row): array {
    $stored = (float)($row['days_requested'] ?? 0);
    $normalized = lv_normalized_application_days($row);
    $row['stored_days_requested'] = $stored;
    $row['days_requested'] = $normalized;
    $row['normalized_days_requested'] = $normalized;
    return $row;
}

function lv_get_employee_leave_applications(PDO $conn, string $piid, string $status = ''): array {
    lv_ensure_leave_applications_table($conn);
    $sql = "SELECT *
            FROM tbl_leave_applications
            WHERE piid = :piid";
    $params = [':piid' => $piid];
    if ($status !== '') {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }
    $sql .= " ORDER BY created_at DESC, id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return array_map('lv_decorate_leave_application_row', $stmt->fetchAll());
}

function lv_get_employee_leave_applications_page(PDO $conn, string $piid, string $status = '', int $page = 1, int $per_page = 10): array {
    lv_ensure_leave_applications_table($conn);
    $page = max(1, $page);
    $per_page = max(5, min(50, $per_page));
    $where = ' WHERE piid = :piid';
    $params = [':piid' => $piid];
    if ($status !== '') {
        $where .= ' AND status = :status';
        $params[':status'] = $status;
    }
    $count = $conn->prepare('SELECT COUNT(*) FROM tbl_leave_applications' . $where);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    $stmt = $conn->prepare('SELECT * FROM tbl_leave_applications' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . $per_page . ' OFFSET ' . $offset);
    $stmt->execute($params);
    return [
        'data' => array_map('lv_decorate_leave_application_row', $stmt->fetchAll()),
        'pagination' => ['page'=>$page, 'per_page'=>$per_page, 'total'=>$total, 'total_pages'=>$total_pages],
    ];
}

function lv_poll_employee_leave_applications(PDO $conn, string $piid, int $limit = 10): array {
    $limit = max(10, min(100, $limit));
    $count = $conn->prepare('SELECT COUNT(*) FROM tbl_leave_applications WHERE piid = :piid');
    $count->execute([':piid' => $piid]);
    $total = (int)$count->fetchColumn();
    $stmt = $conn->prepare(
        'SELECT *
           FROM tbl_leave_applications
          WHERE piid = :piid
          ORDER BY created_at DESC, id DESC
          LIMIT ' . $limit
    );
    $stmt->execute([':piid' => $piid]);
    return [
        'data' => array_map('lv_decorate_leave_application_row', $stmt->fetchAll()),
        'total' => $total,
    ];
}

function lv_get_leave_application(PDO $conn, int $id): ?array {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare("SELECT * FROM tbl_leave_applications WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch() ?: null;
    return $row ? lv_decorate_leave_application_row($row) : null;
}

function lv_employee_is_department_head(PDO $conn, string $piid): bool {
    $piid = trim($piid);
    if ($piid === '') return false;
    try {
        $stmt = $conn->prepare(
            "SELECT 1
             FROM tbl_employee_department_approvers
             WHERE is_active = 1
               AND piid = :piid
               AND approver_role = 'department_head'
             LIMIT 1"
        );
        $stmt->execute([':piid' => $piid]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('department head route lookup: ' . $e->getMessage());
        return false;
    }
}
function lv_employee_official_role(PDO $conn, string $piid): string {
    try {
        $stmt = $conn->prepare("SELECT official_role FROM tbl_employee_leave_official_roles WHERE piid = :piid AND is_active = 1 LIMIT 1");
        $stmt->execute([':piid' => trim($piid)]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

function lv_create_leave_application(PDO $conn, string $piid, array $payload): int {
    lv_ensure_leave_applications_table($conn);
    $employee = lv_get_employee($conn, $piid);
    if (!$employee) {
        throw new RuntimeException('Employee not found');
    }

    $type = trim((string)($payload['leave_type'] ?? ''));
    $typeOther = trim((string)($payload['type_other'] ?? ''));
    $dateFiled = trim((string)($payload['date_filed'] ?? date('Y-m-d')));
    $dateInputted = trim((string)($payload['date_inputted'] ?? ''));
    $periodFrom = trim((string)($payload['period_from'] ?? ''));
    $periodTo = trim((string)($payload['period_to'] ?? ''));
    $halfDayMode = trim((string)($payload['half_day_mode'] ?? ''));
    $halfDayWindow = trim((string)($payload['half_day_window'] ?? ''));
    $vacationLocation = trim((string)($payload['vacation_location'] ?? ''));
    $vacationAbroadDetails = trim((string)($payload['vacation_abroad_details'] ?? ''));
    $sickLeaveDetail = trim((string)($payload['sick_leave_detail'] ?? ''));
    $sickIllness = trim((string)($payload['sick_illness'] ?? ''));
    $studyLeaveDetail = trim((string)($payload['study_leave_detail'] ?? ''));
    $studyPurpose = trim((string)($payload['study_purpose'] ?? ''));
    $monetizationPurpose = trim((string)($payload['monetization_purpose'] ?? ''));
    $monetizationPurposeCategory = trim((string)($payload['monetization_purpose_category'] ?? ''));
    $monetizationVlDays = (float)($payload['monetization_vl_days'] ?? 0);
    $monetizationSlDays = (float)($payload['monetization_sl_days'] ?? 0);
    $monetizationAmount = (float)($payload['monetization_amount'] ?? 0);
    $monetizationTrack = trim((string)($payload['monetization_track'] ?? ''));
    $department = (string)($employee['Department'] ?? '');
    $officialRole = lv_employee_official_role($conn, $piid);
    $isDepartmentHead = lv_employee_is_department_head($conn, $piid);
    $needsSpecialMonetizationApproval = $monetizationTrack === 'special';
    $approvalRoute = in_array($officialRole, ['city_mayor', 'vice_mayor'], true)
        ? $officialRole . '_city_admin'
        : ($officialRole === 'councilor' ? 'councilor_city_mayor' : ($isDepartmentHead
        ? ($needsSpecialMonetizationApproval ? 'department_head_special_monetization' : 'department_head')
        : ($needsSpecialMonetizationApproval ? 'special_monetization' : 'standard')));
    $needsCityAdminApproval = $isDepartmentHead || $needsSpecialMonetizationApproval || in_array($officialRole, ['city_mayor', 'vice_mayor'], true);
    $needsCityMayorApproval = $officialRole === 'councilor';
    $cityAdminStatus = $needsCityAdminApproval ? 'Pending' : null;
    $bypassDepartment = $isDepartmentHead || $officialRole !== '';
    $deptStatus = $bypassDepartment ? 'Approved' : 'Pending';
    $deptRemarks = $bypassDepartment ? 'Official applicant routed directly to the designated approving authority.' : null;
    $deptApprovedBy = $bypassDepartment ? 'SYSTEM ROUTING' : null;
    $deptApprovedAt = $bypassDepartment ? lv_application_now() : null;
    $daysRequested = (float)($payload['days_requested'] ?? 0);
    if ($daysRequested <= 0 && $periodFrom !== '' && $periodTo !== '') {
        $daysRequested = lv_calculate_days_requested($type, $periodFrom, $periodTo);
    }

    $stmt = $conn->prepare(
        "INSERT INTO tbl_leave_applications
            (piid, id_number, employee_name, department, leave_type, type_other, date_filed, date_inputted,
             period_from, period_to, half_day_mode, half_day_window, days_requested,
             vacation_location, vacation_abroad_details, sick_leave_detail, sick_illness,
             study_leave_detail, study_purpose, monetization_purpose, monetization_purpose_category, monetization_vl_days, monetization_sl_days, monetization_amount, monetization_track, approval_route, particulars, reason,
             status, dept_status, dept_remarks, dept_approved_by, dept_approved_at, city_admin_status, city_mayor_status, created_at, updated_at)
         VALUES
            (:piid, :id_number, :employee_name, :department, :leave_type, :type_other, :date_filed, :date_inputted,
             :period_from, :period_to, :half_day_mode, :half_day_window, :days_requested,
             :vacation_location, :vacation_abroad_details, :sick_leave_detail, :sick_illness,
             :study_leave_detail, :study_purpose, :monetization_purpose, :monetization_purpose_category, :monetization_vl_days, :monetization_sl_days, :monetization_amount, :monetization_track, :approval_route, :particulars, :reason,
             'Pending', :dept_status, :dept_remarks, :dept_approved_by, :dept_approved_at, :city_admin_status, :city_mayor_status, :created_at, :updated_at)"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':piid' => $piid,
        ':id_number' => (string)($employee['ID_NUM'] ?? ''),
        ':employee_name' => lv_application_employee_name($employee),
        ':department' => $department,
        ':leave_type' => $type,
        ':type_other' => $typeOther !== '' ? $typeOther : null,
        ':date_filed' => $dateFiled,
        ':date_inputted' => $dateInputted !== '' ? $dateInputted : null,
        ':period_from' => $periodFrom,
        ':period_to' => $periodTo,
        ':half_day_mode' => $halfDayMode !== '' ? $halfDayMode : null,
        ':half_day_window' => $halfDayWindow !== '' ? $halfDayWindow : null,
        ':days_requested' => $daysRequested,
        ':vacation_location' => $vacationLocation !== '' ? $vacationLocation : null,
        ':vacation_abroad_details' => $vacationAbroadDetails !== '' ? $vacationAbroadDetails : null,
        ':sick_leave_detail' => $sickLeaveDetail !== '' ? $sickLeaveDetail : null,
        ':sick_illness' => $sickIllness !== '' ? $sickIllness : null,
        ':study_leave_detail' => $studyLeaveDetail !== '' ? $studyLeaveDetail : null,
        ':study_purpose' => $studyPurpose !== '' ? $studyPurpose : null,
        ':monetization_purpose' => $monetizationPurpose !== '' ? $monetizationPurpose : null,
        ':monetization_purpose_category' => $monetizationPurposeCategory !== '' ? $monetizationPurposeCategory : null,
        ':monetization_vl_days' => $monetizationVlDays,
        ':monetization_sl_days' => $monetizationSlDays,
        ':monetization_amount' => $monetizationAmount,
        ':monetization_track' => $monetizationTrack !== '' ? $monetizationTrack : null,
        ':approval_route' => $approvalRoute,
        ':dept_status' => $deptStatus,
        ':dept_remarks' => $deptRemarks,
        ':dept_approved_by' => $deptApprovedBy,
        ':dept_approved_at' => $deptApprovedAt,
        ':city_admin_status' => $cityAdminStatus,
        ':city_mayor_status' => $needsCityMayorApproval ? 'Pending' : null,
        ':particulars' => trim((string)($payload['particulars'] ?? '')),
        ':reason' => trim((string)($payload['reason'] ?? '')),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$conn->lastInsertId();
}

function lv_cancel_leave_application(PDO $conn, int $id, string $piid, string $reason): void {
    lv_ensure_leave_applications_table($conn);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Cancellation reason is required');
    }
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET status = 'Cancelled',
             cancellation_reason = :cancellation_reason,
             cancelled_by_employee_at = :cancelled_at,
             updated_at = :updated_at
         WHERE id = :id
           AND piid = :piid
           AND status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':cancellation_reason' => $reason,
        ':cancelled_at' => $now,
        ':updated_at' => $now,
        ':id' => $id,
        ':piid' => $piid,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending leave application not found');
    }
}

function lv_search_leave_applications(PDO $conn, array $filters = []): array {
    lv_ensure_leave_applications_table($conn);
    [$where, $params] = lv_leave_application_search_parts($filters);

    $sql = "SELECT * FROM tbl_leave_applications";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return array_map('lv_decorate_leave_application_row', $stmt->fetchAll());
}

function lv_leave_application_search_parts(array $filters): array {
    $where = [];
    $params = [];

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    $deptStatus = trim((string)($filters['dept_status'] ?? ''));
    if ($deptStatus !== '') {
        $where[] = 'dept_status = :dept_status';
        $params[':dept_status'] = $deptStatus;
    }

    $cityAdminStatus = trim((string)($filters['city_admin_status'] ?? ''));
    if ($cityAdminStatus !== '') {
        $where[] = 'city_admin_status = :city_admin_status';
        $params[':city_admin_status'] = $cityAdminStatus;
    }

    $monetizationTrack = trim((string)($filters['monetization_track'] ?? ''));
    if ($monetizationTrack !== '') {
        $where[] = 'monetization_track = :monetization_track';
        $params[':monetization_track'] = $monetizationTrack;
    }

    $department = $filters['department'] ?? '';
    if (is_array($department)) {
        $department = array_values(array_filter(array_map('trim', $department), fn($d) => $d !== ''));
        if ($department) {
            $placeholders = [];
            foreach ($department as $i => $dept) {
                $key = ":department_{$i}";
                $placeholders[] = $key;
                $params[$key] = $dept;
            }
            $where[] = 'department IN (' . implode(',', $placeholders) . ')';
        }
    } else {
        $department = trim((string)$department);
        if ($department !== '') {
            $where[] = 'department = :department';
            $params[':department'] = $department;
        }
    }

    $periodFrom = trim((string)($filters['period_from'] ?? ''));
    $periodTo = trim((string)($filters['period_to'] ?? ''));
    if ($periodFrom !== '' && $periodTo !== '') {
        $where[] = '(period_from <= :period_to AND period_to >= :period_from)';
        $params[':period_from'] = $periodFrom;
        $params[':period_to'] = $periodTo;
    }

    $query = trim((string)($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = '(employee_name LIKE :q OR id_number LIKE :q OR leave_type LIKE :q)';
        $params[':q'] = '%' . $query . '%';
    }

    return [$where, $params];
}

function lv_search_leave_applications_page(PDO $conn, array $filters = [], int $page = 1, int $per_page = 25): array {
    lv_ensure_leave_applications_table($conn);
    [$where, $params] = lv_leave_application_search_parts($filters);
    $page = max(1, $page);
    $per_page = max(10, min(100, $per_page));
    $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $count = $conn->prepare('SELECT COUNT(*) FROM tbl_leave_applications' . $where_sql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $sql = "SELECT * FROM tbl_leave_applications";
    $sql .= $where_sql . ' ORDER BY created_at DESC, id DESC';
    $sql .= ' LIMIT ' . $per_page . ' OFFSET ' . $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return [
        'data' => array_map('lv_decorate_leave_application_row', $stmt->fetchAll()),
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
        ],
    ];
}

function lv_department_pending_count(PDO $conn, array $departments): int {
    lv_ensure_leave_applications_table($conn);
    $departments = array_values(array_filter(array_map('trim', $departments), fn($d) => $d !== ''));
    if (!$departments) {
        return 0;
    }
    $placeholders = [];
    $params = [];
    foreach ($departments as $i => $dept) {
        $key = ":department_{$i}";
        $placeholders[] = $key;
        $params[$key] = $dept;
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM tbl_leave_applications
         WHERE department IN (" . implode(',', $placeholders) . ")
           AND status = 'Pending'
           AND dept_status = 'Pending'"
    );
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function lv_department_approve_leave_application(PDO $conn, int $id, string $department, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET dept_status = 'Approved',
             dept_remarks = :remarks,
             dept_approved_by = :approved_by,
             dept_approved_at = :approved_at,
             dept_rejected_by = NULL,
             dept_rejected_at = NULL,
             updated_at = :updated_at
         WHERE id = :id
           AND department = :department
           AND status = 'Pending'
           AND dept_status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':approved_by' => $actor,
        ':approved_at' => $now,
        ':updated_at' => $now,
        ':id' => $id,
        ':department' => $department,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending department leave application not found');
    }
}

function lv_department_reject_leave_application(PDO $conn, int $id, string $department, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET status = 'Rejected',
             dept_status = 'Rejected',
             dept_remarks = :remarks,
             dept_rejected_by = :rejected_by,
             dept_rejected_at = :rejected_at,
             admin_remarks = :remarks_admin,
             rejected_by = :rejected_by_admin,
             rejected_at = :rejected_at_admin,
             updated_at = :updated_at
         WHERE id = :id
           AND department = :department
           AND status = 'Pending'
           AND dept_status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':rejected_by' => $actor,
        ':rejected_at' => $now,
        ':remarks_admin' => $remarks,
        ':rejected_by_admin' => $actor,
        ':rejected_at_admin' => $now,
        ':updated_at' => $now,
        ':id' => $id,
        ':department' => $department,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending department leave application not found');
    }
}

// City Administrator tier. Used for Special Monetization and for every leave
// application filed by a tagged Department Head. Runs before HR approval.
function lv_city_admin_approve_leave_application(PDO $conn, int $id, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET city_admin_status = 'Approved',
             city_admin_remarks = :remarks,
             city_admin_approved_by = :approved_by,
             city_admin_approved_at = :approved_at,
             city_admin_rejected_by = NULL,
             city_admin_rejected_at = NULL,
             updated_at = :updated_at
         WHERE id = :id
           AND status = 'Pending'
           AND dept_status = 'Approved'
           AND city_admin_status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':approved_by' => $actor,
        ':approved_at' => $now,
        ':updated_at' => $now,
        ':id' => $id,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending City Administrator approval not found for this application');
    }
}

function lv_city_admin_reject_leave_application(PDO $conn, int $id, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET status = 'Rejected',
             city_admin_status = 'Rejected',
             city_admin_remarks = :remarks,
             city_admin_rejected_by = :rejected_by,
             city_admin_rejected_at = :rejected_at,
             admin_remarks = :remarks_admin,
             rejected_by = :rejected_by_admin,
             rejected_at = :rejected_at_admin,
             updated_at = :updated_at
         WHERE id = :id
           AND status = 'Pending'
           AND dept_status = 'Approved'
           AND city_admin_status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':rejected_by' => $actor,
        ':rejected_at' => $now,
        ':remarks_admin' => $remarks,
        ':rejected_by_admin' => $actor,
        ':rejected_at_admin' => $now,
        ':updated_at' => $now,
        ':id' => $id,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending City Administrator approval not found for this application');
    }
}

function lv_city_admin_pending_count(PDO $conn): int {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->query(
        "SELECT COUNT(*) FROM tbl_leave_applications
         WHERE status = 'Pending'
           AND dept_status = 'Approved'
           AND city_admin_status = 'Pending'"
    );
    return (int)$stmt->fetchColumn();
}
function lv_city_mayor_approve_leave_application(PDO $conn, int $id, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare("UPDATE tbl_leave_applications SET city_mayor_status='Approved', updated_at=:now WHERE id=:id AND status='Pending' AND dept_status='Approved' AND city_mayor_status='Pending'");
    $stmt->execute([':now' => lv_application_now(), ':id' => $id]);
    if ($stmt->rowCount() < 1) throw new RuntimeException('Pending City Mayor approval not found for this application');
}
function lv_city_mayor_reject_leave_application(PDO $conn, int $id, string $actor, string $remarks): void {
    lv_ensure_leave_applications_table($conn);
    $now = lv_application_now();
    $stmt = $conn->prepare("UPDATE tbl_leave_applications SET status='Rejected', city_mayor_status='Rejected', admin_remarks=:remarks, rejected_by=:actor, rejected_at=:now, updated_at=:now WHERE id=:id AND status='Pending' AND dept_status='Approved' AND city_mayor_status='Pending'");
    $stmt->execute([':remarks' => $remarks, ':actor' => $actor, ':now' => $now, ':id' => $id]);
    if ($stmt->rowCount() < 1) throw new RuntimeException('Pending City Mayor approval not found for this application');
}
function lv_city_mayor_pending_count(PDO $conn): int {
    lv_ensure_leave_applications_table($conn);
    return (int)$conn->query("SELECT COUNT(*) FROM tbl_leave_applications WHERE status='Pending' AND dept_status='Approved' AND city_mayor_status='Pending'")->fetchColumn();
}

function lv_application_to_record(array $app, string $actor): array {
    $type = strtoupper(trim((string)($app['leave_type'] ?? '')));
    $days = (float)($app['days_requested'] ?? 0);
    $mainType = strtoupper(lv_main_type($type));

    $record = [
        'PIID' => (string)$app['piid'],
        'Type_of_Records' => $type,
        'Date_of_Filing' => (string)$app['date_filed'],
        'Period_From' => (string)$app['period_from'],
        'Period_To' => (string)$app['period_to'],
        'VacEarn' => 0,
        'VacWP' => 0,
        'VacBal' => 0,
        'VacWOP' => 0,
        'SickEarn' => 0,
        'SickWP' => 0,
        'SickBal' => 0,
        'SickWOP' => 0,
        'Particulars' => strtoupper(trim((string)($app['particulars'] ?? ''))),
        'DateAction' => lv_application_period_label((string)$app['period_from'], (string)$app['period_to']),
        'DateProcessed' => trim((string)($app['date_inputted'] ?? '')) !== ''
            ? (string)$app['date_inputted']
            : date('Y-m-d'),
        'RecordedBy' => strtoupper($actor),
        'no_avail_VL' => 0,
        'no_avail_SL' => 0,
        'no_avail_mone_VL' => 0,
        'no_avail_mone_SL' => 0,
        'no_avail_SP' => 0,
        'no_avail_P' => 0,
        'no_avail_mone' => 0,
        'Remarks' => strtoupper(trim((string)($app['reason'] ?? ''))),
    ];

    if (in_array($mainType, ['VACATION LEAVE', 'UNDERTIME (SUBJECT TO VACATION LEAVE)', 'UNDERTIME', 'MANDATORY/FORCED LEAVE', 'SPECIAL LEAVE VACATION LEAVE', 'UNUSED FORCE VACATION LEAVE'], true)) {
        $record['VacWP'] = $days;
    } elseif ($mainType === 'VACATION LEAVE W/O PAY') {
        $record['VacWOP'] = $days;
    } elseif ($mainType === 'SICK LEAVE') {
        $record['SickWP'] = $days;
    } elseif ($mainType === 'SICK LEAVE W/O PAY') {
        $record['SickWOP'] = $days;
    } elseif ($mainType === 'MONETIZATION OF LEAVE CREDITS') {
        $monetizationVlDays = (float)($app['monetization_vl_days'] ?? 0);
        $monetizationSlDays = (float)($app['monetization_sl_days'] ?? 0);
        if ($monetizationVlDays <= 0 && $monetizationSlDays <= 0) {
            $monetizationVlDays = $days;
        }
        $record['no_avail_mone'] = $days;
        $record['no_avail_mone_VL'] = $monetizationVlDays;
        $record['no_avail_mone_SL'] = $monetizationSlDays;
    } elseif ($mainType === 'SOLO PARENT LEAVE') {
        $record['no_avail_SP'] = $days;
    } elseif ($mainType === 'PATERNITY LEAVE') {
        $record['no_avail_P'] = $days;
    }

    return $record;
}

function lv_approve_leave_application(PDO $conn, int $id, string $actor, string $remarks = ''): array {
    lv_ensure_leave_applications_table($conn);
    $app = lv_get_leave_application($conn, $id);
    if (!$app) {
        throw new RuntimeException('Leave application not found');
    }
    if ((string)$app['status'] !== 'Pending') {
        throw new RuntimeException('Leave application has already been processed');
    }
    if ((string)($app['dept_status'] ?? 'Pending') !== 'Approved') {
        throw new RuntimeException('Department approval is required before HR approval');
    }
    if (
        ($app['city_admin_status'] ?? null) !== null
        && (string)$app['city_admin_status'] !== 'Approved'
    ) {
        throw new RuntimeException('City Administrator approval is required before HR approval for this request');
    }
    if (($app['city_mayor_status'] ?? null) !== null && (string)$app['city_mayor_status'] !== 'Approved') {
        throw new RuntimeException('City Mayor approval is required before HR approval for this request');
    }
    $manualConflicts = lv_find_manual_dtr_override_conflicts(
        $conn,
        (string)$app['piid'],
        (string)$app['period_from'],
        (string)$app['period_to'],
        (string)($app['half_day_mode'] ?? 'full')
    );
    if ($manualConflicts) {
        throw new RuntimeException(
            'Cannot approve until the overlapping DTR override is removed or adjusted: '
            . lv_manual_override_conflict_message($manualConflicts)
        );
    }

    $record = lv_application_to_record($app, $actor);
    lv_recalc_status_ensure_schema($conn);
    $startedTx = !$conn->inTransaction();
    if ($startedTx) {
        $conn->beginTransaction();
    }

    try {
        if (lv_is_wellness_type($record['Type_of_Records'])) {
            $year = (int)date('Y', strtotime($record['Period_From']));
            $requested = lv_wellness_days_for_record($record);
            $used = lv_wellness_days_used($conn, (string)$app['piid'], $year);
            if ($requested > max(0, 5 - $used)) {
                throw new RuntimeException('Wellness Leave exceeds the remaining annual balance');
            }
        }
        if (
            lv_application_half_day_mode($app) !== 'full'
            && lv_calculate_days_requested($record['Type_of_Records'], $record['Period_From'], $record['Period_To']) <= 1.0
            && !lv_is_compensatory_type($record['Type_of_Records'])
        ) {
            throw new RuntimeException('Half-day leave must be part of a leave request longer than 1 day, such as 1.5 or 2.5 days.');
        }

        $lid = lv_insert_record($conn, $record, $actor);
        lv_upsert_dtr_override(
            $conn,
            $lid,
            $record['Type_of_Records'],
            $record['Period_From'],
            $record['Period_To'],
            $record['Particulars'],
            $record['PIID'],
            $actor,
            strtolower((string)($app['half_day_mode'] ?? '')) !== 'full' && trim((string)($app['half_day_mode'] ?? '')) !== '',
            strtoupper((string)($app['half_day_mode'] ?? ''))
        );
        lv_recalculate($conn, (string)$app['piid'], (int)date('Y', strtotime((string)$app['period_from'])));

        $stmt = $conn->prepare(
            "UPDATE tbl_leave_applications
             SET status = 'Approved',
                 admin_remarks = :remarks,
                 approved_by = :approved_by,
                 approved_at = :approved_at,
                 posted_lid = :posted_lid,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $now = lv_application_now();
        $stmt->execute([
            ':remarks' => $remarks,
            ':approved_by' => $actor,
            ':approved_at' => $now,
            ':posted_lid' => $lid,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        if ($startedTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    return ['lid' => $lid, 'application' => lv_get_leave_application($conn, $id)];
}

function lv_reject_leave_application(PDO $conn, int $id, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET status = 'Rejected',
             admin_remarks = :remarks,
             rejected_by = :rejected_by,
             rejected_at = :rejected_at,
             updated_at = :updated_at
         WHERE id = :id
           AND status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':rejected_by' => $actor,
        ':rejected_at' => $now,
        ':updated_at' => $now,
        ':id' => $id,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending leave application not found');
    }
}

if (lv_env_flag('LEAVE_MONTHLY_LEAVE_EARNED_ON_REQUEST', false)) {
    lv_ensure_monthly_leave_earned_automation($leave_conn);
}

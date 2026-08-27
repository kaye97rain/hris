<?php
/**
 * jo_office_map.php — Job Order payroll template → Office/Department mapping.
 *
 * WHY THIS EXISTS
 * Regular and Casual payroll templates are named after offices, so
 * tbl_template_payroll.Name doubles as the department label. Job Order templates
 * are named after *payroll batches* instead — 159 active templates covering only
 * ~25 real offices (CITY MAYOR'S OFFICE-MIXED 1..15, -LATE, -VERY LATE,
 * -CONTRACTUAL, CITY HEALTH OFFICE-1..6, ...). Without a mapping, ~4,700 JO
 * employees resolve no Office/Department at all in the employee portal, and
 * department heads cannot see JO staff in DTR.
 *
 * DESIGN
 * The mapping is stored as DATA, not derived by rules at read time, so future
 * template changes are an HR edit rather than a code change. Each row carries
 * both a rule-derived `proposed_office` and an HR-set `confirmed_office`;
 * resolution is COALESCE(confirmed, proposed). That means the map is useful
 * immediately (proposals) and hardens as HR works through it, and a brand-new
 * template shows up as `pending` instead of silently resolving to nothing.
 *
 * STANDALONE ON PURPOSE: takes a PDO, requires nothing. leave_db.php,
 * dtr_db.php AND the JO payroll module all consume this; since payroll_db.php
 * already requires leave_db.php, putting it in a payroll file would create a
 * circular require.
 *
 * ⚠ THIS IS NOT THE JO PAYROLL ACCESS SCOPE. Do not reuse it as one.
 * JO payroll is prepared by assigned personnel PER OFFICE, and the legacy app's
 * scope key (tblsysuser.Office) is a *template name*, at finer granularity than
 * the office rollup here. Concretely: CITY HEALTH OFFICE-NURSE and -POPDEV have
 * their own preparers and are explicitly excluded from the CITY HEALTH OFFICE
 * scope (see ucPayroll.vb load_template()), yet all three roll up to CITY HEALTH
 * OFFICE for department display. Likewise "-2" continuation batches merge here
 * but are often assigned to a *different* preparer (SP-MARABE vs SP-MARABE-2).
 * Two groupings, two purposes:
 *   - this map          -> Office/Department shown in the portal + DTR scoping
 *   - preparer scope    -> who may prepare which JO payroll (separate table)
 *
 * NOTE ON NORMALIZATION: jo_office_match_key() below is the PHP twin of
 * lv_department_match_key_sql() in leave_db.php, and jo_office_canonical_name()
 * of lv_department_canonical_name_sql(). They must stay in sync. They are
 * duplicated rather than shared to keep this file dependency-free — the SQL
 * versions remain the source of truth for the leave roster's own queries.
 */

define('_JO_OFFICE_MAP_TABLE', 'tbl_payroll_jo_office_map');
define('_JO_TEMPLATE_TABLE', 'tbl_templatepayroll_jo');

// ── Normalization (PHP twin of leave_db.php's SQL helpers) ──────────────────

/**
 * Collapse an office name to a comparison key: case, punctuation, spacing and
 * "&"/"AND" differences all fold away. Mirrors lv_department_match_key_sql().
 */
function jo_office_match_key(?string $name): string {
    $key = strtoupper(trim((string)$name));
    $key = str_replace('&', 'AND', $key);
    $key = str_replace(['.', ',', '-', '/', '(', ')', ' ', "'"], '', $key);
    // Legacy alias: the Accounting office appears under both spellings.
    return str_replace('CITYACCOUNTANTSOFFICE', 'CITYACCOUNTING', $key);
}

/** Mirrors lv_department_canonical_name_sql(). */
function jo_office_canonical_name(?string $name): string {
    $key = jo_office_match_key($name);
    if ($key === 'CITYACCOUNTING' || $key === 'CITYACCOUNTINGOFFICE') {
        return "CITY ACCOUNTANT'S OFFICE";
    }
    return strtoupper(trim((string)$name));
}

/**
 * Strip a JO template's batch decoration down to the office it belongs to.
 *
 * Two rules, because the two cases are genuinely different:
 *
 *  - Sangguniang Panlungsod staff are assigned PER COUNCILOR (HR ruling,
 *    2026-07-29), confirmed by tbl_templatepayroll_jo.InCharge naming the
 *    councilor on each row. So SP keeps its councilor token and only sheds a
 *    trailing numeric continuation batch:
 *      "SANGGUNIANG PANLUNGSOD-ALDEGUER-2"      -> "SANGGUNIANG PANLUNGSOD-ALDEGUER"
 *      "SANGGUNIANG PANLUNGSOD-DINLAYAN ZOLTAN" -> unchanged (distinct councilor)
 *
 *  - Everything else: the segment before the first hyphen is the office, and the
 *    remainder is a batch/crew label.
 *      "CITY MAYOR'S OFFICE-MIXED 7"            -> "CITY MAYOR'S OFFICE"
 *      "CITY HEALTH OFFICE-UPPER-2"             -> "CITY HEALTH OFFICE"
 *
 * This produces a PROPOSAL only. HR confirms or corrects each row, and several
 * named sub-units (-BAC, -OSCA, -TMC, -MOTORPOOL, ...) may legitimately be kept
 * as their own office — see HRMIS/JO-office-mapping-questions.md.
 */
function jo_office_propose_from_template(?string $templateName): string {
    $name = trim((string)$templateName);
    if ($name === '') {
        return '';
    }

    if (strpos(jo_office_match_key($name), 'SANGGUNIANGPANLUNGSOD') === 0) {
        // Shed only a trailing "-2" / "- 3" continuation batch.
        return trim(preg_replace('/-\s*\d+\s*$/', '', $name));
    }

    $firstHyphen = strpos($name, '-');
    return $firstHyphen === false ? $name : trim(substr($name, 0, $firstHyphen));
}

// ── Schema ──────────────────────────────────────────────────────────────────

function jo_office_map_ensure_schema(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        'CREATE TABLE IF NOT EXISTS `' . _JO_OFFICE_MAP_TABLE . '` (
            `TID` int(5) NOT NULL,
            `template_name` varchar(255) NOT NULL DEFAULT \'\',
            `in_charge` varchar(255) NOT NULL DEFAULT \'\',
            `proposed_office` varchar(255) NOT NULL DEFAULT \'\',
            `confirmed_office` varchar(255) DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT \'pending\',
            `notes` varchar(500) NOT NULL DEFAULT \'\',
            `is_active_template` tinyint(1) NOT NULL DEFAULT 1,
            `updated_by` varchar(100) NOT NULL DEFAULT \'\',
            `updated_at` datetime DEFAULT NULL,
            `synced_at` datetime DEFAULT NULL,
            PRIMARY KEY (`TID`),
            KEY `idx_jo_office_status` (`status`),
            KEY `idx_jo_office_resolved` (`confirmed_office`, `proposed_office`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    $ensured = true;
}

// ── Sync proposals from the live template table ─────────────────────────────

/**
 * Refresh the map against tbl_templatepayroll_jo.
 *
 * - New templates are inserted as `pending` with a rule-derived proposal.
 * - Existing rows have their template_name/in_charge/proposal refreshed, but a
 *   `confirmed_office` set by HR is NEVER overwritten.
 * - Templates that disappear (isDeleted) are flagged is_active_template = 0
 *   rather than deleted, because their payroll history still references the TID.
 *
 * NOT for page load. Only ~181 template rows, but it issues one upsert per row and
 * the live DB is remote — measured at ~3.5s. Call it from the migration script or
 * an explicit admin "resync" action. Read paths need only
 * jo_office_map_ensure_schema() (statically guarded) plus a select.
 */
function jo_office_map_sync(PDO $conn): array {
    jo_office_map_ensure_schema($conn);

    // Canonical regular-payroll office names, keyed for fuzzy matching, so a JO
    // proposal lands on the exact string the rest of the system already uses
    // (apostrophes, "&" vs "AND", Accounting alias) instead of a near-miss that
    // would never match a department scope.
    $canonical = [];
    foreach ($conn->query('SELECT Name FROM tbl_template_payroll WHERE isDeleted = 0')->fetchAll() as $row) {
        $key = jo_office_match_key($row['Name'] ?? '');
        if ($key !== '' && !isset($canonical[$key])) {
            $canonical[$key] = jo_office_canonical_name($row['Name'] ?? '');
        }
    }

    $templates = $conn->query(
        'SELECT TID, Name, COALESCE(InCharge, \'\') AS InCharge, isDeleted
           FROM ' . _JO_TEMPLATE_TABLE
    )->fetchAll();

    $upsert = $conn->prepare(
        'INSERT INTO `' . _JO_OFFICE_MAP_TABLE . '`
            (TID, template_name, in_charge, proposed_office, status, is_active_template, synced_at)
         VALUES (:tid, :name, :in_charge, :proposed, \'pending\', :active, NOW())
         ON DUPLICATE KEY UPDATE
            template_name      = VALUES(template_name),
            in_charge          = VALUES(in_charge),
            proposed_office    = VALUES(proposed_office),
            is_active_template = VALUES(is_active_template),
            synced_at          = NOW()'
    );

    $inserted = 0;
    $updated = 0;
    foreach ($templates as $tpl) {
        $proposed = jo_office_propose_from_template($tpl['Name'] ?? '');
        $key = jo_office_match_key($proposed);
        // Prefer the canonical regular-payroll spelling when one matches.
        $proposed = $canonical[$key] ?? jo_office_canonical_name($proposed);

        $upsert->execute([
            ':tid'       => (int)$tpl['TID'],
            ':name'      => (string)($tpl['Name'] ?? ''),
            ':in_charge' => (string)($tpl['InCharge'] ?? ''),
            ':proposed'  => $proposed,
            ':active'    => empty($tpl['isDeleted']) ? 1 : 0,
        ]);
        // rowCount(): 1 = inserted, 2 = updated, 0 = unchanged.
        if ($upsert->rowCount() === 1) { $inserted++; } else { $updated++; }
    }

    return ['templates' => count($templates), 'inserted' => $inserted, 'updated' => $updated];
}

// ── Reads ───────────────────────────────────────────────────────────────────

/** SQL fragment for the effective office of a mapping row. */
function jo_office_resolved_sql(string $alias = 'm'): string {
    return "COALESCE(NULLIF($alias.confirmed_office, ''), $alias.proposed_office)";
}

/**
 * [TID => office] for the given TIDs (all active templates when $tids is empty).
 * This is the lookup the portal/DTR department resolution consumes.
 */
function jo_office_map_resolve(PDO $conn, array $tids = []): array {
    jo_office_map_ensure_schema($conn);

    $sql = 'SELECT TID, ' . jo_office_resolved_sql() . ' AS office FROM `' . _JO_OFFICE_MAP_TABLE . '` m';
    $params = [];
    if ($tids) {
        $placeholders = implode(',', array_fill(0, count($tids), '?'));
        $sql .= " WHERE m.TID IN ($placeholders)";
        $params = array_map('intval', array_values($tids));
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $office = trim((string)$row['office']);
        if ($office !== '') {
            $out[(string)$row['TID']] = $office;
        }
    }
    return $out;
}

/** Full map for the HR confirmation screen, newest-unconfirmed first. */
function jo_office_map_rows(PDO $conn, bool $activeOnly = true): array {
    jo_office_map_ensure_schema($conn);

    $sql = 'SELECT TID, template_name, in_charge, proposed_office, confirmed_office,
                   status, notes, is_active_template,
                   CASE WHEN is_active_template = 0 THEN 1 ELSE 0 END AS is_deleted,
                   updated_by, updated_at,
                   ' . jo_office_resolved_sql() . ' AS resolved_office
              FROM `' . _JO_OFFICE_MAP_TABLE . '` m';
    if ($activeOnly) {
        $sql .= ' WHERE m.is_active_template = 1';
    }
    $sql .= " ORDER BY (m.status = 'confirmed'), m.template_name";

    return $conn->query($sql)->fetchAll();
}

function jo_office_map_stats(PDO $conn): array {
    jo_office_map_ensure_schema($conn);
    $row = $conn->query(
        "SELECT COUNT(*) AS total,
                SUM(status = 'confirmed') AS confirmed,
                SUM(status <> 'confirmed') AS pending,
                COUNT(DISTINCT " . jo_office_resolved_sql() . ") AS distinct_offices
           FROM `" . _JO_OFFICE_MAP_TABLE . "` m
          WHERE m.is_active_template = 1"
    )->fetch() ?: [];

    return [
        'total'            => (int)($row['total'] ?? 0),
        'confirmed'        => (int)($row['confirmed'] ?? 0),
        'pending'          => (int)($row['pending'] ?? 0),
        'distinct_offices' => (int)($row['distinct_offices'] ?? 0),
    ];
}

/** Distinct offices the map currently resolves to — for the screen's dropdown. */
function jo_office_map_office_options(PDO $conn): array {
    jo_office_map_ensure_schema($conn);

    $offices = [];
    foreach ($conn->query('SELECT DISTINCT Name FROM tbl_template_payroll WHERE isDeleted = 0')->fetchAll() as $row) {
        $name = jo_office_canonical_name($row['Name'] ?? '');
        if ($name !== '') { $offices[$name] = true; }
    }
    // JO-only offices (Sangguniang Panlungsod per councilor, external agencies)
    // have no regular-payroll counterpart, so include what the map itself holds.
    $sql = 'SELECT DISTINCT ' . jo_office_resolved_sql() . ' AS office
              FROM `' . _JO_OFFICE_MAP_TABLE . '` m WHERE m.is_active_template = 1';
    foreach ($conn->query($sql)->fetchAll() as $row) {
        $name = trim((string)$row['office']);
        if ($name !== '') { $offices[$name] = true; }
    }

    $list = array_keys($offices);
    sort($list);
    return $list;
}

// ── Writes ──────────────────────────────────────────────────────────────────

/**
 * Record HR's decision for one template.
 *
 * $office === '' reverts the row to `pending` (drops the confirmation) rather
 * than storing an empty office, so "unsure" is expressible without producing an
 * employee with a blank department.
 */
function jo_office_map_confirm(PDO $conn, int $tid, string $office, string $actor, string $notes = ''): void {
    jo_office_map_ensure_schema($conn);

    $office = jo_office_canonical_name($office);
    $stmt = $conn->prepare(
        'UPDATE `' . _JO_OFFICE_MAP_TABLE . '`
            SET confirmed_office = :office,
                status           = :status,
                notes            = :notes,
                updated_by       = :actor,
                updated_at       = NOW()
          WHERE TID = :tid'
    );
    $stmt->execute([
        ':office' => $office === '' ? null : $office,
        ':status' => $office === '' ? 'pending' : 'confirmed',
        ':notes'  => substr(trim($notes), 0, 500),
        ':actor'  => substr(trim($actor) !== '' ? trim($actor) : 'HRMIS', 0, 100),
        ':tid'    => $tid,
    ]);
}

/**
 * Accept the rule-derived proposal as confirmed for every still-pending row.
 * Used by the screen's "confirm all remaining as proposed" action once HR has
 * reviewed the exceptions.
 */
function jo_office_map_confirm_all_proposed(PDO $conn, string $actor): int {
    jo_office_map_ensure_schema($conn);

    $stmt = $conn->prepare(
        "UPDATE `" . _JO_OFFICE_MAP_TABLE . "`
            SET confirmed_office = proposed_office,
                status           = 'confirmed',
                updated_by       = :actor,
                updated_at       = NOW()
          WHERE status <> 'confirmed'
            AND is_active_template = 1
            AND proposed_office <> ''"
    );
    $stmt->execute([':actor' => substr(trim($actor) !== '' ? trim($actor) : 'HRMIS', 0, 100)]);
    return $stmt->rowCount();
}

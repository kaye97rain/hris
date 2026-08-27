<?php
/**
 * payroll_jo_directory.php — JO employee → Office directory.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT THE LEAVE ROSTER
 *
 * ~4,700 Job Order employees resolve no Office at all: lv_get_employee() falls back
 * to tblpersonalinformation with `'' AS Department`, so the portal shows
 * "Office not set", and dtr_db.php scopes department heads off the same roster, so
 * JO staff with DTR punches are invisible to their own office.
 *
 * The obvious fix — UNION a JO branch into lv_payroll_source_sql() so JO employees
 * land in tbl_syl_leave_employee_roster — is WRONG, and the reason is subtle:
 *
 *   Roster membership is the gate that decides who gets EVALUATED for leave.
 *   The leave balance stored procedure cursors over roster members, and
 *   lv_eligibility_suggestion() returns leave_eligible = TRUE for *any* non-empty
 *   service-record status (leave_db.php:240). So putting JO employees in the roster
 *   would silently grant leave eligibility to every JO employee who has any service
 *   record status at all, and generate balances for them.
 *
 * So the roster keeps meaning "leave-entitled", and JO office resolution lives here
 * instead, in its own small table that the portal and DTR consult as a FALLBACK when
 * the roster has no Department. Offices resolve; leave eligibility does not change.
 *
 * ⚠ REFRESH IS EXPLICIT, NEVER INLINE. It is called by API/payroll_jo_migrate.php
 * and by the admin resync action — never from a page load. Reads below are cheap
 * lookups against ~4,700 rows. Do not add a TTL that refreshes on read.
 *
 * NO NEW INDEX REQUIRED. The refresh is driven per-template off the EXISTING
 * `TID (TID, Year, End_Date)` index rather than scanning the 8M-row table: one
 * indexed MAX(Year) probe per template, then one indexed range read for that
 * template's latest year. ~340 small queries, measured at well under a minute,
 * against 292 seconds for the equivalent whole-table GROUP BY.
 *
 * That is deliberate. The obvious implementation —
 * `SELECT MAX(PPID) ... WHERE isDeleted=0 AND quencina='whole' GROUP BY PIID` —
 * needs a composite index this server does not have, and adding one on MySQL 5.1
 * means a write-blocking table rebuild (see API/jo_apply_indexes.php). Going
 * template-first avoids the whole problem. Do not "simplify" this back into a
 * single GROUP BY.
 *
 * STANDALONE ON PURPOSE: requires only jo_office_map.php (which itself requires
 * nothing), so leave_db.php and dtr_db.php can both pull it in without a circular
 * require. Same constraint jo_office_map.php documents.
 */

require_once __DIR__ . '/jo_office_map.php';

const _JO_DIRECTORY_TABLE = 'tbl_payroll_jo_employee_office';
const _JO_TEMPLATE_ROSTER_TABLE = 'tbl_payroll_jo_template_roster';

function jo_directory_ensure_schema(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $conn->exec(
        'CREATE TABLE IF NOT EXISTS `' . _JO_DIRECTORY_TABLE . '` (
            `PIID` varchar(20) NOT NULL,
            `TID` int(5) NOT NULL,
            `Office` varchar(255) NOT NULL DEFAULT \'\',
            `PayYear` varchar(20) DEFAULT NULL,
            `End_Date` varchar(20) DEFAULT NULL,
            `RefreshedAt` datetime NOT NULL,
            PRIMARY KEY (`PIID`),
            KEY `idx_jo_dir_office` (`Office`),
            KEY `idx_jo_dir_tid` (`TID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    $conn->exec(
        'CREATE TABLE IF NOT EXISTS `' . _JO_TEMPLATE_ROSTER_TABLE . '` (
            `TID` int(5) NOT NULL,
            `PIID` varchar(20) NOT NULL,
            `Office` varchar(255) NOT NULL DEFAULT \'\',
            `PayYear` varchar(20) DEFAULT NULL,
            `End_Date` varchar(20) DEFAULT NULL,
            `RefreshedAt` datetime NOT NULL,
            PRIMARY KEY (`TID`,`PIID`),
            KEY `idx_jo_template_roster_office` (`Office`),
            KEY `idx_jo_template_roster_piid` (`PIID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    $ensured = true;
}

/**
 * Split a real Period_Date ("2026-07-31") into the [year, month-name] pair
 * the directory/roster cache tables store (PayYear, End_Date columns), so
 * every downstream reader of those two columns keeps working unchanged.
 */
function jo_period_to_year_month(string $periodDate): array {
    $ts = strtotime($periodDate);
    return [date('Y', $ts), date('F', $ts)];
}

/**
 * The "current" JO reference period: the previous calendar month, not the
 * real current month. Confirmed 2026-08-26 — JO payroll for the month in
 * progress is never done yet by the time anyone checks headcount, so
 * "current" for JO always means the last fully-closed month (August in
 * progress -> use July; September in progress -> use August). Correctly
 * rolls the year back too: in January, the baseline is December of the
 * PRIOR year, not January of this one.
 *
 * @return array{0:string,1:int} [last day of the baseline month, its year]
 */
function jo_current_baseline_period(): array {
    $end = date('Y-m-t', strtotime('first day of last month'));
    return [$end, (int)date('Y', strtotime($end))];
}

/**
 * Rebuild the directory from tbl_syl_payroll_for_print_jo, template by template.
 *
 * WHY THIS TABLE AND NOT tbl_syl_payroll_parent_jo: parent_jo is the ROSTER a
 * preparer saves for a template/period (who's assigned, at what rate) — being
 * in it means "assigned," not "actually paid." for_print_jo is the computed,
 * printed payroll output, written only once a preparer actually runs payroll
 * for that period. Two people can be on the same roster while only one of
 * them has ever had payroll processed; for "current JO headcount" HR wants
 * the latter (confirmed 2026-08-26, after finding TID 138 had 294 people on
 * its July roster but zero processed for_print_jo rows that month).
 * Period_Date is a real DATE column (one stamp per calendar month, shared by
 * that month's 1st-quincena/2nd-quincena/whole-month rows), so no more
 * Year/End_Num/End_Date string juggling — and no repeat of the "December
 * 2026" mislabeled-year incident, since these dates come from actual
 * disbursement runs, not a hand-typed Year field.
 *
 * An employee's office is taken from their most recent processed period.
 * Where someone appears under two templates in the same period the higher
 * TID wins, so the result is stable across runs rather than depending on row
 * order.
 *
 * Builds into a shadow table and renames, so readers never see a half-built directory.
 *
 * @param int $lookbackYears how many years back from each template's latest to accept.
 *                           0 = latest period only. Guards against a long-dead template
 *                           resurrecting someone who left years ago.
 */
function jo_directory_refresh(PDO $conn, int $lookbackYears = 1): array {
    jo_directory_ensure_schema($conn);

    // TID -> office, resolved as COALESCE(confirmed, proposed). 170 rows.
    $officeByTid = jo_office_map_resolve($conn);

    // Rides the existing TID index (small table — 220K rows, not 8M).
    $maxPeriodStmt = $conn->prepare(
        'SELECT MAX(Period_Date) FROM tbl_syl_payroll_for_print_jo WHERE TID = ? AND isDeleted = 0 AND Period_Date <= ?'
    );
    $rowsStmt = $conn->prepare(
        "SELECT DISTINCT PIID, Period_Date
           FROM tbl_syl_payroll_for_print_jo
          WHERE TID = ? AND Period_Date BETWEEN ? AND ? AND isDeleted = 0"
    );

    // Best row per PIID, compared on (Period_Date, TID).
    $best = [];
    $sourceRows = 0;
    $templatesScanned = 0;
    $templateRoster = [];

    [$baselineEnd, $baselineYear] = jo_current_baseline_period();
    $yearStart = "$baselineYear-01-01";
    foreach (array_keys($officeByTid) as $tidKey) {
        $tid = (int)$tidKey;
        $maxPeriodStmt->execute([(string)$tid, $baselineEnd]);
        $maxPeriod = $maxPeriodStmt->fetchColumn();
        if ($maxPeriod === false || $maxPeriod === null) {
            continue;   // template has no processed payroll at or before the baseline month
        }
        // Still gated to the baseline's calendar year: a template whose
        // nearest processed run at/before the baseline is from 2022 or 2024
        // is not currently active — its people don't belong in the live
        // headcount (confirmed 2026-08-26: a "latest ever, however old" rule
        // was pulling stale years into what's supposed to be "now").
        if ((int)date('Y', strtotime($maxPeriod)) !== $baselineYear) {
            continue;
        }
        // Clamped to the start of the baseline year: the lookback window is
        // for catching everyone within THIS year's roster (a person who
        // worked January but not July shouldn't vanish from the "whole pay
        // year" figure), not for admitting a stray prior-year row.
        $fromDate = max(
            date('Y-m-d', strtotime($maxPeriod . ' -' . max(0, $lookbackYears) . ' years')),
            $yearStart
        );
        $templatesScanned++;
        $office = trim((string)($officeByTid[(string)$tid] ?? ''));

        $rowsStmt->execute([(string)$tid, $fromDate, $baselineEnd]);
        // Every distinct PIID that appeared under this template across the whole
        // lookback window — not just the latest month's snapshot. A person who
        // worked several months on the same template must still count once, so
        // office-detail reporting ("roster entries for the whole pay year")
        // reflects real yearly headcount, not a one-month slice. Where a person
        // spans two periods under the same TID, keep their most recent period,
        // same "latest wins" rule used for $best above.
        $rosterRank = [];
        foreach ($rowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sourceRows++;
            $piid = trim((string)$row['PIID']);
            if ($piid === '') {
                continue;
            }
            $period = (string)$row['Period_Date'];
            $rank = [$period, $tid];
            if (!isset($best[$piid]) || $best[$piid]['rank'] < $rank) {
                $best[$piid] = ['rank' => $rank, 'tid' => $tid, 'period' => $period];
            }

            if ($office === '') {
                continue;
            }
            if (isset($rosterRank[$piid]) && $rosterRank[$piid] >= $period) {
                continue;
            }
            $rosterRank[$piid] = $period;
        }
        foreach ($rosterRank as $piid => $period) {
            $templateRoster[] = ['tid' => $tid, 'piid' => $piid, 'office' => $office, 'period' => $period];
        }
    }

    $shadow = _JO_DIRECTORY_TABLE . '_shadow';
    $old    = _JO_DIRECTORY_TABLE . '_old';
    $rosterShadow = _JO_TEMPLATE_ROSTER_TABLE . '_shadow';
    $rosterOld = _JO_TEMPLATE_ROSTER_TABLE . '_old';
    $conn->exec('DROP TABLE IF EXISTS `' . $shadow . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $old . '`');
    $conn->exec('CREATE TABLE `' . $shadow . '` LIKE `' . _JO_DIRECTORY_TABLE . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $rosterShadow . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $rosterOld . '`');
    $conn->exec('CREATE TABLE `' . $rosterShadow . '` LIKE `' . _JO_TEMPLATE_ROSTER_TABLE . '`');

    $insert = $conn->prepare(
        'INSERT INTO `' . $shadow . '` (PIID, TID, Office, PayYear, End_Date, RefreshedAt)
         VALUES (:piid, :tid, :office, :payyear, :enddate, NOW())'
    );
    $insertTemplateRoster = $conn->prepare(
        'INSERT INTO `' . $rosterShadow . '` (TID, PIID, Office, PayYear, End_Date, RefreshedAt)
         VALUES (:tid, :piid, :office, :payyear, :enddate, NOW())'
    );

    $matched = 0;
    $unmapped = 0;
    foreach ($best as $piid => $row) {
        // for_print_jo.TID is int while the office map keys on int too, but
        // normalise through int regardless so '007' and '7' resolve the same.
        $office = trim((string)($officeByTid[(string)$row['tid']] ?? ''));
        if ($office === '') {
            $unmapped++;
            continue;
        }
        [$year, $endDate] = jo_period_to_year_month($row['period']);
        $insert->execute([
            ':piid'    => $piid,
            ':tid'     => $row['tid'],
            ':office'  => $office,
            ':payyear' => $year,
            ':enddate' => $endDate,
        ]);
        $matched++;
    }
    foreach ($templateRoster as $row) {
        [$year, $endDate] = jo_period_to_year_month($row['period']);
        $insertTemplateRoster->execute([':tid' => $row['tid'], ':piid' => $row['piid'], ':office' => $row['office'], ':payyear' => $year, ':enddate' => $endDate]);
    }

    $conn->exec(
        'RENAME TABLE `' . _JO_DIRECTORY_TABLE . '` TO `' . $old . '`, '
        . '`' . $shadow . '` TO `' . _JO_DIRECTORY_TABLE . '`'
    );
    $conn->exec('DROP TABLE IF EXISTS `' . $old . '`');
    $conn->exec(
        'RENAME TABLE `' . _JO_TEMPLATE_ROSTER_TABLE . '` TO `' . $rosterOld . '`, '
        . '`' . $rosterShadow . '` TO `' . _JO_TEMPLATE_ROSTER_TABLE . '`'
    );
    $conn->exec('DROP TABLE IF EXISTS `' . $rosterOld . '`');

    return [
        'templates'   => $templatesScanned,
        'source_rows' => $sourceRows,
        'employees'   => count($best),
        'mapped'      => $matched,
        'unmapped'    => $unmapped,
        'template_roster_rows' => count($templateRoster),
    ];
}

/**
 * Read-only JO population "as of" a past year — each active template's own
 * latest snapshot WITHIN that year specifically, same per-template probe
 * pattern as jo_directory_refresh()/jo_template_roster_refresh() (one indexed
 * MAX(Period_Date) query per template, not a whole-table GROUP BY — see the
 * file header). "Within that year" and not "at or before": a template whose
 * only data is several years older than $targetYear was not active in
 * $targetYear and must not appear in that year's snapshot (confirmed
 * 2026-08-26 — an earlier "at or before" version was pulling 2022 data into
 * a 2026 view). This never writes a cache table; it's HR Insights' year
 * selector only, called directly on the read path.
 *
 * @return array<string,array{office:string,year:int}> PIID => latest office/year within $targetYear
 */
function jo_population_asof(PDO $conn, int $targetYear): array {
    $officeByTid = jo_office_map_resolve($conn);
    $from = $targetYear . '-01-01';
    $to = $targetYear . '-12-31';
    $maxPeriodStmt = $conn->prepare(
        'SELECT MAX(Period_Date) FROM tbl_syl_payroll_for_print_jo WHERE TID = ? AND isDeleted = 0 AND Period_Date BETWEEN ? AND ?'
    );
    $rosterStmt = $conn->prepare(
        "SELECT DISTINCT PIID FROM tbl_syl_payroll_for_print_jo WHERE TID = ? AND Period_Date = ? AND isDeleted = 0"
    );

    $best = [];
    foreach ($officeByTid as $tidKey => $office) {
        $tid = (int)$tidKey;
        $office = trim((string)$office);
        if ($tid <= 0 || $office === '') continue;

        $maxPeriodStmt->execute([(string)$tid, $from, $to]);
        $period = $maxPeriodStmt->fetchColumn();
        if ($period === false || $period === null) continue;

        $rosterStmt->execute([(string)$tid, $period]);
        foreach ($rosterStmt->fetchAll(PDO::FETCH_COLUMN) as $piid) {
            $piid = trim((string)$piid);
            if ($piid === '') continue;
            if (isset($best[$piid]) && $best[$piid]['period'] >= $period) continue;
            $best[$piid] = ['office' => $office, 'period' => $period, 'year' => (int)substr($period, 0, 4)];
        }
    }
    return $best;
}

/** Rebuild only the current, per-template JO roster cache used by the dashboard. */
function jo_template_roster_refresh(PDO $conn): array {
    jo_directory_ensure_schema($conn);
    $officeByTid = jo_office_map_resolve($conn);
    $rowsStmt = $conn->prepare(
        "SELECT DISTINCT PIID FROM tbl_syl_payroll_for_print_jo
          WHERE TID = ? AND Period_Date = ? AND isDeleted = 0"
    );
    $shadow = _JO_TEMPLATE_ROSTER_TABLE . '_shadow';
    $old = _JO_TEMPLATE_ROSTER_TABLE . '_old';
    $conn->exec('DROP TABLE IF EXISTS `' . $shadow . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $old . '`');
    $conn->exec('CREATE TABLE `' . $shadow . '` LIKE `' . _JO_TEMPLATE_ROSTER_TABLE . '`');
    $insert = $conn->prepare(
        'INSERT INTO `' . $shadow . '` (TID, PIID, Office, PayYear, End_Date, RefreshedAt)
         VALUES (:tid, :piid, :office, :year, :end_date, NOW())'
    );
    $validTemplates = [];
    foreach ($officeByTid as $tidKey => $office) {
        $tid = (int)$tidKey;
        if ($tid > 0 && trim((string)$office) !== '') $validTemplates[$tid] = (string)$office;
    }

    // Each template's own latest PROCESSED period at or before the baseline
    // (previous calendar month — see jo_current_baseline_period()), within
    // that baseline's calendar year — not merely its latest saved roster
    // (parent_jo, see jo_directory_refresh()'s docblock for why processed
    // payroll is the right basis), and not "latest ever, however old": a
    // template whose nearest run at/before the baseline was 2022 or 2024 is
    // not currently active and must not feed the live cache (confirmed
    // 2026-08-26).
    [$baselineEnd, $baselineYear] = jo_current_baseline_period();
    $templateIds = array_keys($validTemplates);
    $latestPeriodByTid = [];
    if ($templateIds) {
        $marks = implode(',', array_fill(0, count($templateIds), '?'));
        $latestPeriodStmt = $conn->prepare(
            "SELECT TID, MAX(Period_Date) AS latest_period
               FROM tbl_syl_payroll_for_print_jo
              WHERE TID IN ($marks) AND isDeleted = 0
                AND Period_Date BETWEEN ? AND ?
              GROUP BY TID"
        );
        $latestPeriodStmt->execute(array_merge(array_map('strval', $templateIds), ["$baselineYear-01-01", $baselineEnd]));
        foreach ($latestPeriodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['latest_period'] === null) continue;
            $latestPeriodByTid[(int)$row['TID']] = (string)$row['latest_period'];
        }
    }

    $templates = 0; $employees = 0;
    foreach ($latestPeriodByTid as $tid => $period) {
        $office = $validTemplates[$tid] ?? '';
        if ($office === '') continue;
        [$year, $endDate] = jo_period_to_year_month($period);
        $templates++;
        $rowsStmt->execute([(string)$tid, $period]);
        foreach ($rowsStmt->fetchAll(PDO::FETCH_COLUMN) as $piid) {
            $piid = trim((string)$piid);
            if ($piid === '') continue;
            $insert->execute([':tid' => $tid, ':piid' => $piid, ':office' => $office, ':year' => $year, ':end_date' => $endDate]);
            $employees++;
        }
    }
    $conn->exec('RENAME TABLE `' . _JO_TEMPLATE_ROSTER_TABLE . '` TO `' . $old . '`, `' . $shadow . '` TO `' . _JO_TEMPLATE_ROSTER_TABLE . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $old . '`');
    return ['templates' => $templates, 'rows' => $employees];
}

// ── Reads (cheap — safe on user-facing paths) ───────────────────────────────

/**
 * True when JO office fallback should apply: the module is live and the directory
 * has been built. Callers use this to stay completely inert until both are true.
 */
function jo_directory_is_active(PDO $conn): bool {
    static $active = null;
    if ($active !== null) {
        return $active;
    }
    if (function_exists('hrmis_module_is_available') && !hrmis_module_is_available($conn, 'payroll_jo')) {
        return $active = false;
    }
    // Deliberately NO jo_directory_ensure_schema() here. This runs on user-facing
    // paths (the portal, DTR), and CREATE TABLE forces an implicit COMMIT in MySQL,
    // which would silently end any transaction the caller had open. The table is
    // created by the refresh/migration path instead; a missing table simply reads
    // as inactive.
    try {
        $active = (int)$conn->query('SELECT COUNT(*) FROM `' . _JO_DIRECTORY_TABLE . '`')->fetchColumn() > 0;
    } catch (PDOException $e) {
        $active = false;
    }
    return $active;
}

/** True when the per-template current JO roster cache has been refreshed. */
function jo_template_roster_is_active(PDO $conn): bool {
    static $active = null;
    if ($active !== null) return $active;
    try {
        return $active = (int)$conn->query('SELECT COUNT(*) FROM `' . _JO_TEMPLATE_ROSTER_TABLE . '`')->fetchColumn() > 0;
    } catch (PDOException $e) {
        return $active = false;
    }
}

/**
 * Return the newest processed payroll roster from every active JO template in
 * the requested offices — each template contributes its OWN latest processed
 * period independently (a template that hasn't been repayrolled this cycle
 * still counts via its last real run, rather than being dropped because a
 * sibling template in the same office happens to be more current). This is
 * deliberately limited to selected offices (the dashboard modal) so it can
 * use the existing TID index without a full-table scan.
 */
function jo_latest_roster_for_offices(PDO $conn, array $offices): array {
    $wanted = array_fill_keys(array_map(static fn($v) => strtolower(trim((string)$v)), $offices), true);
    $templateOffices = jo_office_map_resolve($conn);
    $templates = [];
    foreach ($templateOffices as $tid => $office) {
        if (isset($wanted[strtolower(trim((string)$office))])) $templates[(int)$tid] = (string)$office;
    }
    if (!$templates) return [];

    // "Current" means at or before the baseline (previous calendar month —
    // see jo_current_baseline_period()), within that baseline's year.
    [$baselineEnd, $baselineYear] = jo_current_baseline_period();
    $latestStmt = $conn->prepare(
        'SELECT MAX(Period_Date) FROM tbl_syl_payroll_for_print_jo
          WHERE TID = ? AND isDeleted = 0 AND Period_Date BETWEEN ? AND ?'
    );
    $rosterStmt = $conn->prepare(
        "SELECT DISTINCT PIID FROM tbl_syl_payroll_for_print_jo WHERE TID = ? AND Period_Date = ? AND isDeleted = 0"
    );

    $best = [];
    foreach ($templates as $tid => $office) {
        $latestStmt->execute([(string)$tid, "$baselineYear-01-01", $baselineEnd]);
        $period = $latestStmt->fetchColumn();
        if (!$period) continue;
        $rosterStmt->execute([(string)$tid, $period]);
        $rank = $period . '-' . sprintf('%08d', $tid);
        foreach ($rosterStmt->fetchAll(PDO::FETCH_COLUMN) as $piid) {
            $piid = trim((string)$piid);
            if ($piid === '' || (isset($best[$piid]) && $best[$piid]['rank'] >= $rank)) continue;
            $best[$piid] = ['PIID' => $piid, 'office' => $office, 'rank' => $rank];
        }
    }
    return array_values($best);
}

/**
 * Every JO employee currently on an active template mapped to one of the
 * given offices, read from `_JO_DIRECTORY_TABLE` — one row per person (their
 * single most recent JO assignment, any year, see jo_directory_refresh()).
 * Unlike jo_latest_roster_for_offices(), this isn't limited to whichever
 * template in the office happens to have the newest snapshot, so a template
 * whose preparer hasn't run payroll this period still contributes its people.
 */
function jo_directory_roster_for_offices(PDO $conn, array $offices): array {
    $wanted = array_fill_keys(array_map(static fn($v) => strtolower(trim((string)$v)), $offices), true);
    if (!$wanted) return [];
    $sql = 'SELECT j.PIID, ' . jo_office_resolved_sql('m') . ' AS office
              FROM `' . _JO_DIRECTORY_TABLE . '` j
        INNER JOIN `' . _JO_OFFICE_MAP_TABLE . '` m ON m.TID = j.TID AND m.is_active_template = 1';
    $rows = [];
    foreach ($conn->query($sql) as $row) {
        $office = trim((string)($row['office'] ?? ''));
        if (!isset($wanted[strtolower($office)])) continue;
        $piid = trim((string)$row['PIID']);
        if ($piid === '') continue;
        $rows[$piid] = ['PIID' => $piid, 'office' => $office];
    }
    return array_values($rows);
}

/** [PIID => Office] for the given PIIDs. Empty array when inactive. */
function jo_directory_resolve(PDO $conn, array $piids): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), fn($p) => trim($p) !== '')));
    if (!$piids || !jo_directory_is_active($conn)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        'SELECT PIID, Office FROM `' . _JO_DIRECTORY_TABLE . "` WHERE PIID IN ($placeholders)"
    );
    $stmt->execute($piids);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $office = trim((string)$row['Office']);
        if ($office !== '') {
            $out[(string)$row['PIID']] = $office;
        }
    }
    return $out;
}

/** Office for a single PIID, or '' when unknown/inactive. */
function jo_directory_office_for(PDO $conn, string $piid): string {
    $map = jo_directory_resolve($conn, [$piid]);
    return $map[$piid] ?? '';
}

/** PIIDs belonging to any of the given offices. Empty array when inactive. */
function jo_directory_department_piids(PDO $conn, array $offices): array {
    $offices = array_values(array_unique(array_filter(array_map('strval', $offices), fn($o) => trim($o) !== '')));
    if (!$offices || !jo_directory_is_active($conn)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($offices), '?'));
    $stmt = $conn->prepare(
        'SELECT PIID FROM `' . _JO_DIRECTORY_TABLE . "` WHERE Office IN ($placeholders)"
    );
    $stmt->execute($offices);
    return array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function jo_directory_meta(PDO $conn): array {
    jo_directory_ensure_schema($conn);
    $row = $conn->query(
        'SELECT COUNT(*) AS row_count, MAX(RefreshedAt) AS RefreshedAt,
                COUNT(DISTINCT Office) AS office_count
           FROM `' . _JO_DIRECTORY_TABLE . '`'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'rows'         => (int)($row['row_count'] ?? 0),
        'offices'      => (int)($row['office_count'] ?? 0),
        'refreshed_at' => $row['RefreshedAt'] ?? null,
    ];
}

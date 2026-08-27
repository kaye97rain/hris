<?php
/**
 * dtr_db.php — DB access + business logic for the DTR (Daily Time Record) module.
 *
 * Uses the same $leave_conn PDO connection as leave_db.php (same `hrmis` database).
 *
 * Tables used:
 *   tbldtr                    — raw AM/PM punches (PIID, DTR_Date, AM_In, AM_Out, PM_In, PM_Out)
 *   ref_events                — company/holiday calendar overlay
 *   tbldtr_override           — excuse-slip records (Memo / Travel Order / Tracer Slip)
 *   tbldtr_override_person    — employees attached to an override record
 *   tbldtr_override_details   — date + time-range rows attached to an override record
 *   tbl_templatedtr           — saved DTR print groups (also carries each group's "In-Charge" name)
 *   tbl_templatedtr_piid      — employees belonging to a tbl_templatedtr group
 *
 * Ported from the VB.NET forms FORMS/frmDtr.vb and FORMS/frmAlldtr.vb. The two VB forms
 * For ordinary rows frmAlldtr.vb displays tbldtr.Undertime verbatim. It only
 * calls get_Undertime() when holiday/override coverage changes the four punch
 * values. Keep that distinction here so totals match the production report.
 */

require_once __DIR__ . '/leave_db.php';

define('DTR_AM_IN_CUTOFF', '08:00:00');   // VB get_Undertime schedule boundary
define('DTR_AM_OUT_CUTOFF', '12:00:00');  // punch before this = early
define('DTR_PM_IN_CUTOFF', '13:00:00');   // VB get_Undertime schedule boundary
define('DTR_PM_OUT_CUTOFF', '17:00:00');  // punch before this = early

// Nominal schedule boundaries used only to test whether a holiday/override window
// covers a given slot — separate from the grace-period cutoffs above.
define('DTR_AM_IN_SCHED', '08:00:00');
define('DTR_AM_OUT_SCHED', '12:00:00');
define('DTR_PM_IN_SCHED', '13:00:00');
define('DTR_PM_OUT_SCHED', '17:00:00');

define('DTR_ABSENT_HALF_DAY_MINUTES', 240); // no punches at all for AM or PM half
define('DTR_DAY_UNDERTIME_CAP_MINUTES', 480); // 8:00 — matches VB's get_Times() display cap

function dtr_period_range(int $year, int $month, string $period): array {
    $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    switch ($period) {
        case '1-15':
            return [1, min(15, $lastDay)];
        case '16-31':
            return [16, $lastDay];
        default:
            return [1, $lastDay];
    }
}

function dtr_minutes_between(string $from, string $to): int {
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false || $toTs === false) {
        return 0;
    }
    return (int)round(($toTs - $fromTs) / 60);
}

function dtr_format_minutes(int $minutes): string {
    $minutes = max(0, $minutes);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return sprintf('%d:%02d', $h, $m);
}

/** Convert the stored HH:mm[:ss] Undertime value used by frmAlldtr.vb. */
function dtr_stored_undertime_minutes($value): int {
    $value = trim((string)($value ?? ''));
    if ($value === '') return 0;
    $parts = explode(':', $value);
    if (count($parts) < 2) return 0;
    return min(DTR_DAY_UNDERTIME_CAP_MINUTES, max(0, ((int)$parts[0] * 60) + (int)$parts[1]));
}

/** Exact arithmetic from frmAlldtr.vb get_Undertime(). */
function dtr_vb_undertime_minutes(?string $amIn, ?string $amOut, ?string $pmIn, ?string $pmOut): int {
    $parts = static function (?string $value, string $slot): ?array {
        if ($value === null || trim($value) === '') return null;
        $time = explode(':', trim($value));
        if (count($time) < 2) return null;
        $hour = (int)$time[0];
        $minute = (int)$time[1];
        // VB receives 12-hour display values for PM slots and converts them back.
        if ($slot === 'pm_in' && $hour >= 1 && $hour <= 4) {
            $hour += 12;
        } elseif ($slot === 'pm_out' && $hour >= 5 && $hour <= 8) {
            $hour += 12;
        }
        return [$hour, $minute];
    };

    $amInParts = $parts($amIn, 'am_in');
    $amOutParts = $parts($amOut, 'am_out');
    $pmInParts = $parts($pmIn, 'pm_in');
    $pmOutParts = $parts($pmOut, 'pm_out');
    $minutes = 0;

    // A half is complete only when both punches exist. An incomplete half is
    // a flat four hours; the available punch does not add more minutes.
    if ($amInParts !== null && $amOutParts !== null && $amInParts[0] > 0 && $amOutParts[0] > 0) {
        if ($amOutParts[0] < 12) {
            $minutes += ((11 - $amOutParts[0]) * 60) + (60 - $amOutParts[1]);
        }
        if ($amInParts[0] >= 8) {
            $minutes += (($amInParts[0] - 8) * 60) + $amInParts[1];
        }
    } else {
        $minutes += DTR_ABSENT_HALF_DAY_MINUTES;
    }

    if ($pmInParts !== null && $pmOutParts !== null && $pmInParts[0] > 0 && $pmOutParts[0] > 0) {
        if ($pmOutParts[0] < 17) {
            $minutes += ((16 - $pmOutParts[0]) * 60) + (60 - $pmOutParts[1]);
        }
        if ($pmInParts[0] >= 13) {
            $minutes += (($pmInParts[0] - 13) * 60) + $pmInParts[1];
        }
    } else {
        $minutes += DTR_ABSENT_HALF_DAY_MINUTES;
    }

    return min(DTR_DAY_UNDERTIME_CAP_MINUTES, max(0, $minutes));
}

/**
 * Holiday/company-event windows covering a given date, from ref_events.
 * Returns a list of ['label'=>string, 'whole'=>bool, 'am'=>bool, 'pm'=>bool, 'start'=>?string, 'end'=>?string].
 */
function dtr_day_holiday_events(PDO $conn, string $date): array {
    $stmt = $conn->prepare(
        'SELECT Name, Event_Duration, Event_Start, Event_End
         FROM ref_events
         WHERE Event_Date = :date'
    );
    $stmt->execute([':date' => $date]);

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        $duration = (string)($row['Event_Duration'] ?? '');
        $events[] = [
            'label' => (string)($row['Name'] ?? ''),
            'whole' => $duration === 'Whole Day',
            'am' => $duration === 'Morning',
            'pm' => $duration === 'Afternoon',
            'start' => $row['Event_Start'] ?: null,
            'end' => $row['Event_End'] ?: null,
        ];
    }
    return $events;
}

/**
 * Excuse-slip override windows covering a given employee/date, from tbldtr_override*.
 * Same return shape as dtr_day_holiday_events() (whole/am/pm always false — overrides
 * are always "Specific" time-window records, evaluated via start/end like frmDtr.vb does).
 */
function dtr_day_override_events(PDO $conn, string $piid, string $date): array {
    $stmt = $conn->prepare(
        'SELECT o.Name, o.Override_Type, d.Time_Start, d.Time_End
         FROM tbldtr_override_details d
         INNER JOIN tbldtr_override o ON o.OID = d.OID
         INNER JOIN tbldtr_override_person p ON p.OID = o.OID
         WHERE p.PIID = :piid AND d.DTR_Date = :date'
    );
    $stmt->execute([':piid' => $piid, ':date' => $date]);

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        $type = (string)($row['Override_Type'] ?? '');
        $window = dtr_standard_override_window((string)($row['Time_Start'] ?? ''), (string)($row['Time_End'] ?? ''));
        $events[] = [
            'label' => dtr_override_dtr_label($type, (string)($row['Name'] ?? '')),
            'is_tracer_slip' => $type === 'Tracer Slip',
            'whole' => $window === 'whole',
            'am' => $window === 'am',
            'pm' => $window === 'pm',
            'start' => $row['Time_Start'] ?: null,
            'end' => $row['Time_End'] ?: null,
        ];
    }
    return $events;
}

/** Label shown in a DTR for non-tracer manual overrides. */
function dtr_override_dtr_label(string $type, string $name): string {
    $prefix = match ($type) {
        'Memo' => 'MEMO',
        'Travel Order' => 'OB',
        'Tracer Slip' => 'TRACER SLIP',
        'Absent' => 'ABSENT',
        'JO Leave', 'Leave' => 'LEAVE',
        // The sports-event name alone reads better on the DTR than a generic
        // "SPORTS EVENT - <name>" prefix (there is only ever one covering
        // event per date/slot, so the name is unambiguous on its own).
        'Sports Event' => '',
        default => trim($type),
    };
    $name = trim($name);
    return trim($prefix . ($name !== '' ? ' - ' . $name : ''), ' -') ?: 'Excused';
}

/**
 * Given the combined holiday+override events for a day, return the covering event's
 * label for a specific slot (identified by its nominal schedule time + am/pm half),
 * or null if nothing covers that slot.
 */
function dtr_slot_covering_label(array $events, string $slotSchedTime, bool $isAm): ?string {
    $event = dtr_slot_covering_event($events, $slotSchedTime, $isAm);
    return $event === null ? null : (string)($event['label'] ?? 'Excused');
}

/** Return the event which covers a DTR slot, including its override type. */
function dtr_slot_covering_event(array $events, string $slotSchedTime, bool $isAm): ?array {
    foreach ($events as $event) {
        if ($event['whole']) {
            return $event;
        }
        if ($isAm && $event['am']) {
            return $event;
        }
        if (!$isAm && $event['pm']) {
            return $event;
        }
        if ($event['start'] !== null && $event['end'] !== null) {
            if ($slotSchedTime >= $event['start'] && $slotSchedTime <= $event['end']) {
                return $event;
            }
        }
    }
    return null;
}

/**
 * True if this employee is assigned to a flex/staggered-schedule group
 * (tbl_flex_template_name_piid). The fixed 08:00-12:00/13:00-17:00 undertime
 * rules ported from frmDtr.vb/frmAlldtr.vb do not apply to these employees —
 * their actual expected time-in/out windows come from tbl_flex_template_name_schedule
 * instead (Phase 4). Roughly 8% of employees are flex-assigned live today, so this
 * must be checked rather than assumed away.
 */
function dtr_get_flex_group_name(PDO $conn, string $piid): ?string {
    $stmt = $conn->prepare(
        'SELECT g.Name
         FROM tbl_flex_template_name_piid tp
         INNER JOIN tbl_flex_template_group g ON g.FID = tp.FID
         WHERE tp.PIID = :piid
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch();
    return $row ? (string)$row['Name'] : null;
}

/**
 * The "In-Charge" (supervisor) name for whichever tbl_templatedtr group this
 * employee currently belongs to, if any.
 */
function dtr_get_in_charge(PDO $conn, string $piid): ?string {
    $stmt = $conn->prepare(
        'SELECT td.InCharge
         FROM tbl_templatedtr_piid tp
         INNER JOIN tbl_templatedtr td ON td.TID = tp.TID
         WHERE tp.PIID = :piid
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch();
    return $row ? (string)$row['InCharge'] : null;
}

/**
 * Build one employee's DTR grid for a given year/month/period.
 * Returns ['employee'=>..., 'in_charge'=>..., 'period'=>..., 'days'=>[...], 'total_undertime_minutes'=>int, 'total_undertime_label'=>string].
 */
function dtr_get_employee_month(
    PDO $conn,
    string $piid,
    int $year,
    int $month,
    string $period = 'full',
    ?array $bulkContext = null
): array {
    [$startDay, $endDay] = dtr_period_range($year, $month, $period);
    $today = date('Y-m-d');
    $flexGroupName = $bulkContext !== null
        ? ($bulkContext['flex_groups'][$piid] ?? null)
        : dtr_get_flex_group_name($conn, $piid);

    $startDate = sprintf('%04d-%02d-%02d', $year, $month, $startDay);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $endDay);

    if ($bulkContext !== null) {
        $punchesByDate = $bulkContext['punches'][$piid] ?? [];
    } else {
        $stmt = $conn->prepare(
            'SELECT DTR_Date, AM_In, AM_Out, PM_In, PM_Out, Undertime
             FROM tbldtr
             WHERE PIID = :piid AND DTR_Date BETWEEN :start_date AND :end_date'
        );
        $stmt->execute([':piid' => $piid, ':start_date' => $startDate, ':end_date' => $endDate]);
        $punchesByDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $punchesByDate[$row['DTR_Date']] = $row;
        }
    }

    // Weekend rows are normally printed as a merged SATURDAY/SUNDAY label.
    // Flexitime employees may, however, be assigned a weekend schedule. Keep
    // those rows as individual DTR cells so their scheduled time entries show.
    $flexSchedulesByDate = [];
    if ($flexGroupName !== null) {
        if ($bulkContext !== null) {
            $flexSchedulesByDate = $bulkContext['flex_dtr'][$piid] ?? [];
        } else {
            $flexStmt = $conn->prepare(
                'SELECT DTR_Date, `1_timeIn` AS t1in, `1_timeOut` AS t1out,
                        `2_timeIn` AS t2in, `2_timeOut` AS t2out,
                        `3_timeIn` AS t3in, `3_timeOut` AS t3out, Undertime
                 FROM tbl_flex_dtr
                 WHERE PIID = :piid AND DTR_Date BETWEEN :start_date AND :end_date'
            );
            $flexStmt->execute([':piid' => $piid, ':start_date' => $startDate, ':end_date' => $endDate]);
            foreach ($flexStmt->fetchAll() as $flexRow) {
                $flexSchedulesByDate[(string)$flexRow['DTR_Date']] = $flexRow;
            }
        }
    }

    $days = [];
    $totalUndertime = 0;

    // The selected period limits recorded entries, while the printed DTR keeps
    // the complete month so its official form is never cropped.
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $dow = (int)date('N', mktime(0, 0, 0, $month, $day, $year)); // 6=Sat, 7=Sun
        $isWeekend = $dow >= 6;
        $isFuture = $date > $today;

        $rowOut = [
            'date' => $date,
            'day' => $day,
            'dow_label' => date('D', mktime(0, 0, 0, $month, $day, $year)),
            'is_weekend' => $isWeekend,
            'is_future' => $isFuture,
            'am_in' => null, 'am_out' => null, 'pm_in' => null, 'pm_out' => null,
            'am_in_label' => null, 'am_out_label' => null, 'pm_in_label' => null, 'pm_out_label' => null,
            'undertime_minutes' => 0,
        ];

        if ($day < $startDay || $day > $endDay) {
            $days[] = $rowOut;
            continue;
        }

        $flexSchedule = $flexSchedulesByDate[$date] ?? null;
        $hasWeekendFlexSchedule = $flexSchedule !== null && (bool)array_filter([
            $flexSchedule['t1in'] ?? null, $flexSchedule['t1out'] ?? null,
            $flexSchedule['t2in'] ?? null, $flexSchedule['t2out'] ?? null,
            $flexSchedule['t3in'] ?? null, $flexSchedule['t3out'] ?? null,
        ], static fn($time) => $time !== null && $time !== '');

        if ($isWeekend && !$hasWeekendFlexSchedule) {
            $label = $dow === 6 ? 'SATURDAY' : 'SUNDAY';
            $rowOut['am_in_label'] = $rowOut['am_out_label'] = $rowOut['pm_in_label'] = $rowOut['pm_out_label'] = $label;
            $days[] = $rowOut;
            continue;
        }

        if ($hasWeekendFlexSchedule) {
            // The standard DTR form has AM/PM cells only, so show the first
            // two Flexitime arrival/departure pairs. The dedicated Flexitime
            // DTR continues to show all three pairs.
            $rowOut['am_in'] = $flexSchedule['t1in'] ?? null;
            $rowOut['am_out'] = $flexSchedule['t1out'] ?? null;
            $rowOut['pm_in'] = $flexSchedule['t2in'] ?? null;
            $rowOut['pm_out'] = $flexSchedule['t2out'] ?? null;
            $rowOut['undertime_minutes'] = dtr_stored_undertime_minutes($flexSchedule['Undertime'] ?? null);
            $totalUndertime += $rowOut['undertime_minutes'];
            $days[] = $rowOut;
            continue;
        }

        // Future days normally remain blank because they have no punches or
        // events. Do not exit early, however: a scheduled DTR override must
        // still appear on its covered future date (for example, an approved
        // Tracer Slip created before the travel date).

        // Crystal's row_Nulls() leaves undertime empty when there is no tbldtr
        // record for the date. A stored row whose AM or PM half is empty is
        // different: the legacy report treats that missing half as undertime.
        $hasPunchRow = array_key_exists($date, $punchesByDate);
        $punch = $punchesByDate[$date] ?? ['AM_In' => null, 'AM_Out' => null, 'PM_In' => null, 'PM_Out' => null];
        $amIn = $punch['AM_In'] ?: null;
        $amOut = $punch['AM_Out'] ?: null;
        $pmIn = $punch['PM_In'] ?: null;
        $pmOut = $punch['PM_Out'] ?: null;

        $rowOut['am_in'] = $amIn;
        $rowOut['am_out'] = $amOut;
        $rowOut['pm_in'] = $pmIn;
        $rowOut['pm_out'] = $pmOut;

        // The VB report replaces event-covered slots before recalculating them.
        $events = $bulkContext !== null
            ? array_merge(
                $bulkContext['holidays'][$date] ?? [],
                $bulkContext['overrides'][$piid][$date] ?? []
            )
            : array_merge(
                dtr_day_holiday_events($conn, $date),
                dtr_day_override_events($conn, $piid, $date)
            );

        $amInCover = dtr_slot_covering_event($events, DTR_AM_IN_SCHED, true);
        $amOutCover = dtr_slot_covering_event($events, DTR_AM_OUT_SCHED, true);
        $pmInCover = dtr_slot_covering_event($events, DTR_PM_IN_SCHED, false);
        $pmOutCover = dtr_slot_covering_event($events, DTR_PM_OUT_SCHED, false);

        $applyCover = static function (array &$day, string $slot, ?array $cover, string $scheduledTime): void {
            if ($cover === null) return;
            if (!empty($cover['is_tracer_slip'])) {
                // Tracer slips show the credited scheduled time in the actual
                // DTR cell, rather than an explanatory text label.
                $day[$slot] = $scheduledTime;
            } else {
                $day[$slot . '_label'] = (string)($cover['label'] ?? 'Excused');
            }
        };
        $applyCover($rowOut, 'am_in', $amInCover, DTR_AM_IN_SCHED);
        $applyCover($rowOut, 'am_out', $amOutCover, DTR_AM_OUT_SCHED);
        $applyCover($rowOut, 'pm_in', $pmInCover, DTR_PM_IN_SCHED);
        $applyCover($rowOut, 'pm_out', $pmOutCover, DTR_PM_OUT_SCHED);

        if (!$events) {
            // Normal Dtr_rows_ref branch: use the persisted value exactly as
            // the Crystal dataset did. Do not derive it from punches here.
            $dayUndertime = $hasPunchRow
                ? dtr_stored_undertime_minutes($punch['Undertime'] ?? null)
                : 0;
        } else {
            // Holiday/override branch: covered slots become nominal schedule
            // punches before calling the VB-equivalent get_Undertime logic.
            $dayUndertime = dtr_vb_undertime_minutes(
                $amInCover !== null ? DTR_AM_IN_SCHED : $amIn,
                $amOutCover !== null ? DTR_AM_OUT_SCHED : $amOut,
                $pmInCover !== null ? DTR_PM_IN_SCHED : $pmIn,
                $pmOutCover !== null ? DTR_PM_OUT_SCHED : $pmOut
            );
        }
        $rowOut['undertime_minutes'] = $dayUndertime;
        $totalUndertime += $dayUndertime;

        $days[] = $rowOut;
    }

    $employee = $bulkContext !== null
        ? ($bulkContext['employees'][$piid] ?? null)
        : lv_get_employee($conn, $piid);

    return [
        'employee' => $employee,
        'in_charge' => $bulkContext !== null
            ? ($bulkContext['in_charge'][$piid] ?? null)
            : dtr_get_in_charge($conn, $piid),
        'year' => $year,
        'month' => $month,
        'period' => $period,
        'days' => $days,
        'is_flex_schedule' => $flexGroupName !== null,
        'flex_group_name' => $flexGroupName,
        'total_undertime_minutes' => $totalUndertime,
        'total_undertime_label' => $flexGroupName !== null ? 'N/A (Flex Schedule)' : dtr_format_minutes($totalUndertime),
    ];
}

// ============================================================================
// Override (excuse-slip) admin CRUD — Memo / Travel Order / Tracer Slip
// One override record (tbldtr_override) can be attached to several employees
// (tbldtr_override_person) and covers several date+time windows
// (tbldtr_override_details), applied uniformly to every attached employee.
// ============================================================================

function dtr_ensure_verification_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) return;
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_dtr_verifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            piid VARCHAR(50) NOT NULL,
            dtr_year SMALLINT UNSIGNED NOT NULL,
            dtr_month TINYINT UNSIGNED NOT NULL,
            dtr_period VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            snapshot_json LONGTEXT CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
            employee_name VARCHAR(255) NOT NULL DEFAULT '',
            issued_by VARCHAR(150) NOT NULL DEFAULT '',
            issued_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            revoked_by VARCHAR(150) NULL,
            revoke_reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dtr_verification_token_hash (token_hash),
            KEY idx_dtr_verification_employee_period (piid, dtr_year, dtr_month, dtr_period),
            KEY idx_dtr_verification_issued_at (issued_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $ensured = true;
}

function dtr_verification_employee_name(array $employee): string {
    return strtoupper(trim(implode(' ', array_filter([
        trim((string)($employee['Firstname'] ?? $employee['FirstName'] ?? '')),
        trim((string)($employee['MiddleName'] ?? '')),
        trim((string)($employee['Surname'] ?? $employee['SurName'] ?? '')),
        trim((string)($employee['NameExt'] ?? '')),
    ], static fn($part) => $part !== ''))));
}

/**
 * Stable, privacy-limited representation saved for a printed DTR.
 */
function dtr_verification_snapshot(array $record): array {
    $employee = (array)($record['employee'] ?? []);
    $employeeSnapshot = [
        'PIID' => (string)($employee['PIID'] ?? ''),
        'ID_NUM' => (string)($employee['ID_NUM'] ?? ''),
        'Firstname' => (string)($employee['Firstname'] ?? $employee['FirstName'] ?? ''),
        'MiddleName' => (string)($employee['MiddleName'] ?? ''),
        'Surname' => (string)($employee['Surname'] ?? $employee['SurName'] ?? ''),
        'NameExt' => (string)($employee['NameExt'] ?? ''),
        'Department' => (string)($employee['Department'] ?? ''),
        'Position' => (string)($employee['Position'] ?? ''),
    ];

    $isFlexSchedule = !empty($record['is_flex_schedule']);
    $days = [];
    foreach ((array)($record['days'] ?? []) as $day) {
        $snapshotDay = [
            'date' => (string)($day['date'] ?? ''),
            'day' => (int)($day['day'] ?? 0),
            'dow_label' => (string)($day['dow_label'] ?? ''),
            'is_weekend' => !empty($day['is_weekend']),
            'am_in' => $day['am_in'] ?? null,
            'am_out' => $day['am_out'] ?? null,
            'pm_in' => $day['pm_in'] ?? null,
            'pm_out' => $day['pm_out'] ?? null,
            'am_in_label' => $day['am_in_label'] ?? null,
            'am_out_label' => $day['am_out_label'] ?? null,
            'pm_in_label' => $day['pm_in_label'] ?? null,
            'pm_out_label' => $day['pm_out_label'] ?? null,
            'undertime_minutes' => (int)($day['undertime_minutes'] ?? 0),
        ];
        if ($isFlexSchedule) {
            $snapshotDay['time_1_in'] = $day['t1in'] ?? null;
            $snapshotDay['time_1_out'] = $day['t1out'] ?? null;
            $snapshotDay['time_2_in'] = $day['t2in'] ?? null;
            $snapshotDay['time_2_out'] = $day['t2out'] ?? null;
            $snapshotDay['time_3_in'] = $day['t3in'] ?? null;
            $snapshotDay['time_3_out'] = $day['t3out'] ?? null;
        }
        $days[] = $snapshotDay;
    }

    return [
        'version' => 1,
        'employee' => $employeeSnapshot,
        'employee_name' => dtr_verification_employee_name($employeeSnapshot),
        'in_charge' => (string)($record['in_charge'] ?? ''),
        'year' => (int)($record['year'] ?? 0),
        'month' => (int)($record['month'] ?? 0),
        'period' => (string)($record['period'] ?? 'full'),
        'days' => $days,
        'is_flex_schedule' => $isFlexSchedule,
        'flex_group_name' => (string)($record['flex_group_name'] ?? ''),
        'total_undertime_minutes' => (int)($record['total_undertime_minutes'] ?? 0),
        'total_undertime_label' => (string)($record['total_undertime_label'] ?? ''),
    ];
}

function dtr_verification_json(array $snapshot): string {
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode the DTR verification snapshot');
    }
    return $json;
}

function dtr_verification_now(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->format('Y-m-d H:i:s');
}

/**
 * Fingerprint the attendance source separately from print-time labels such as
 * an administrator-supplied in-charge name.
 */
function dtr_verification_source_hash(array $snapshot): string {
    $source = [
        'version' => (int)($snapshot['version'] ?? 1),
        'piid' => (string)($snapshot['employee']['PIID'] ?? ''),
        'year' => (int)($snapshot['year'] ?? 0),
        'month' => (int)($snapshot['month'] ?? 0),
        'period' => (string)($snapshot['period'] ?? 'full'),
        'days' => (array)($snapshot['days'] ?? []),
        'is_flex_schedule' => !empty($snapshot['is_flex_schedule']),
        'flex_group_name' => (string)($snapshot['flex_group_name'] ?? ''),
        'total_undertime_minutes' => (int)($snapshot['total_undertime_minutes'] ?? 0),
    ];
    return hash('sha256', dtr_verification_json($source));
}

function dtr_issue_verification(PDO $conn, array $record, string $issuedBy): array {
    dtr_ensure_verification_table($conn);

    $snapshot = dtr_verification_snapshot($record);
    $piid = trim((string)($snapshot['employee']['PIID'] ?? ''));
    if ($piid === '' || empty($snapshot['year']) || empty($snapshot['month'])) {
        throw new InvalidArgumentException('The DTR record is missing its employee or reporting period');
    }

    $snapshotJson = dtr_verification_json($snapshot);
    $token = bin2hex(random_bytes(24));
    $issuedAt = dtr_verification_now();
    $stmt = $conn->prepare(
        "INSERT INTO tbl_dtr_verifications
            (token_hash, piid, dtr_year, dtr_month, dtr_period,
             snapshot_hash, source_hash, snapshot_json, employee_name,
             issued_by, issued_at)
         VALUES
            (:token_hash, :piid, :dtr_year, :dtr_month, :dtr_period,
             :snapshot_hash, :source_hash, :snapshot_json, :employee_name,
             :issued_by, :issued_at)"
    );
    $stmt->execute([
        ':token_hash' => hash('sha256', $token),
        ':piid' => $piid,
        ':dtr_year' => (int)$snapshot['year'],
        ':dtr_month' => (int)$snapshot['month'],
        ':dtr_period' => (string)$snapshot['period'],
        ':snapshot_hash' => hash('sha256', $snapshotJson),
        ':source_hash' => dtr_verification_source_hash($snapshot),
        ':snapshot_json' => $snapshotJson,
        ':employee_name' => (string)$snapshot['employee_name'],
        ':issued_by' => substr(trim($issuedBy), 0, 150),
        ':issued_at' => $issuedAt,
    ]);

    return [
        'id' => (int)$conn->lastInsertId(),
        'token' => $token,
        'issued_at' => $issuedAt,
        'snapshot' => $snapshot,
    ];
}

function dtr_find_verification(PDO $conn, string $token): ?array {
    $token = strtolower(trim($token));
    if (strlen($token) !== 48 || !ctype_xdigit($token)) {
        return null;
    }

    dtr_ensure_verification_table($conn);
    $stmt = $conn->prepare(
        "SELECT id, piid, dtr_year, dtr_month, dtr_period,
                snapshot_hash, source_hash, snapshot_json, employee_name,
                issued_by, issued_at, revoked_at, revoked_by, revoke_reason
           FROM tbl_dtr_verifications
          WHERE token_hash = :token_hash
          LIMIT 1"
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $snapshotJson = (string)$row['snapshot_json'];
    $snapshot = json_decode($snapshotJson, true);
    $integrityValid = is_array($snapshot)
        && hash_equals((string)$row['snapshot_hash'], hash('sha256', $snapshotJson));

    $sourceMatches = false;
    $comparisonAvailable = false;
    $currentSnapshot = [];
    if ($integrityValid) {
        try {
            $isFlexSchedule = !empty($snapshot['is_flex_schedule']);
            $current = $isFlexSchedule
                ? dtr_flex_get_employee_month($conn, (string)$row['piid'], (int)$row['dtr_year'], (int)$row['dtr_month'])
                : dtr_get_employee_month($conn, (string)$row['piid'], (int)$row['dtr_year'], (int)$row['dtr_month'], (string)$row['dtr_period']);
            if ($isFlexSchedule) {
                $current['year'] = (int)$row['dtr_year'];
                $current['month'] = (int)$row['dtr_month'];
                $current['period'] = (string)$row['dtr_period'];
                $current['is_flex_schedule'] = true;
            }
            $comparisonAvailable = true;
            $currentSnapshot = !empty($current['employee'])
                ? dtr_verification_snapshot($current)
                : [];
            $sourceMatches = !empty($current['employee'])
                && hash_equals(
                    (string)$row['source_hash'],
                    dtr_verification_source_hash($currentSnapshot)
                );
        } catch (Throwable $e) {
            error_log('dtr_find_verification current-record comparison: ' . $e->getMessage());
        }
    }

    $row['snapshot'] = is_array($snapshot) ? $snapshot : [];
    $row['current_snapshot'] = $currentSnapshot;
    $row['integrity_valid'] = $integrityValid;
    $row['source_matches'] = $sourceMatches;
    $row['comparison_available'] = $comparisonAvailable;
    $row['verified_at'] = dtr_verification_now();
    unset($row['snapshot_json']);
    return $row;
}

function dtr_revoke_verification(
    PDO $conn,
    int $verificationId,
    string $revokedBy,
    string $reason = ''
): bool {
    dtr_ensure_verification_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_dtr_verifications
            SET revoked_at = :revoked_at,
                revoked_by = :revoked_by,
                revoke_reason = :revoke_reason
          WHERE id = :id AND revoked_at IS NULL"
    );
    $stmt->execute([
        ':revoked_at' => dtr_verification_now(),
        ':revoked_by' => substr(trim($revokedBy), 0, 150),
        ':revoke_reason' => substr(trim($reason), 0, 255),
        ':id' => $verificationId,
    ]);
    return $stmt->rowCount() > 0;
}

function dtr_verification_list(PDO $conn, array $opts = []): array {
    dtr_ensure_verification_table($conn);
    $search = trim((string)($opts['search'] ?? ''));
    $year = (int)($opts['year'] ?? 0);
    $month = (int)($opts['month'] ?? 0);
    $status = trim((string)($opts['status'] ?? ''));
    $page = max(1, (int)($opts['page'] ?? 1));
    $pageSize = max(10, min(100, (int)($opts['page_size'] ?? 25)));

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(employee_name LIKE :search_name OR piid LIKE :search_piid OR snapshot_hash LIKE :search_ref)';
        $params[':search_name'] = '%' . $search . '%';
        $params[':search_piid'] = '%' . $search . '%';
        $params[':search_ref'] = strtolower($search) . '%';
    }
    if ($year > 0) {
        $where[] = 'dtr_year = :dtr_year';
        $params[':dtr_year'] = $year;
    }
    if ($month >= 1 && $month <= 12) {
        $where[] = 'dtr_month = :dtr_month';
        $params[':dtr_month'] = $month;
    }
    if ($status === 'active') {
        $where[] = 'revoked_at IS NULL';
    } elseif ($status === 'revoked') {
        $where[] = 'revoked_at IS NOT NULL';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $conn->prepare('SELECT COUNT(1) FROM tbl_dtr_verifications' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;

    $stmt = $conn->prepare(
        "SELECT id, piid, dtr_year, dtr_month, dtr_period,
                employee_name, issued_by, issued_at,
                revoked_at, revoked_by, revoke_reason,
                UPPER(SUBSTRING(snapshot_hash, 1, 12)) AS reference
           FROM tbl_dtr_verifications"
        . $whereSql .
        " ORDER BY issued_at DESC, id DESC
          LIMIT " . (int)$pageSize . " OFFSET " . (int)$offset
    );
    $stmt->execute($params);

    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
    ];
}

// Keep JO Leave accepted so existing records remain viewable/editable.
define('DTR_OVERRIDE_TYPES', ['Memo', 'Travel Order', 'Tracer Slip', 'Absent', 'Leave', 'JO Leave', 'Sports Event']);

/** Maps the three standard name-only override windows to their DTR half-day. */
function dtr_standard_override_window(string $start, string $end): ?string {
    $key = substr($start, 0, 5) . '|' . substr($end, 0, 5);
    return match ($key) {
        '08:00|12:00' => 'am',
        '13:00|17:00' => 'pm',
        '08:00|17:00' => 'whole',
        default => null,
    };
}

/**
 * Leave is reserved for staff outside the Regular/Casual Leave Credits
 * roster as of the override coverage.  The shared leave roster is a current
 * cache, so do not use it for a backdated override.
 */
function dtr_validate_jo_leave_employees(PDO $conn, array $piids, array $dates): void {
    if (!$piids) return;

    $coverageDates = array_values(array_filter(array_map(
        static fn($row) => is_array($row) ? trim((string)($row['date'] ?? $row['DTR_Date'] ?? '')) : '',
        $dates
    ), static fn($date) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1));
    $latestCoverage = $coverageDates ? max($coverageDates) : date('Y-m-d');
    $asOfKey = (int)str_replace('-', '', substr($latestCoverage, 0, 7)); // YYYYMM
    $marks = implode(',', array_fill(0, count($piids), '?'));

    // End_Num stores the payroll snapshot month.  Resolve the latest snapshot
    // per template up to the coverage month, then test only that roster.
    $sql = "SELECT PIID FROM (
                SELECT p.PIID
                  FROM tbl_syl_payroll_parent p
                  INNER JOIN (
                      SELECT TID, MAX(CAST(Year AS UNSIGNED) * 100 + COALESCE(CAST(End_Num AS UNSIGNED), 0)) AS snapshot_key
                        FROM tbl_syl_payroll_parent
                       WHERE isDeleted = 0 AND Quencina = 'whole'
                         AND (CAST(Year AS UNSIGNED) * 100 + COALESCE(CAST(End_Num AS UNSIGNED), 0)) <= ?
                       GROUP BY TID
                  ) latest ON latest.TID = p.TID
                         AND latest.snapshot_key = (CAST(p.Year AS UNSIGNED) * 100 + COALESCE(CAST(p.End_Num AS UNSIGNED), 0))
                 WHERE p.isDeleted = 0 AND p.Quencina = 'whole' AND p.PIID IN ($marks)
                UNION
                SELECT p.PIID
                  FROM tbl_syl_payroll_parent_casual p
                  INNER JOIN (
                      SELECT TID, MAX(CAST(Year AS UNSIGNED) * 100 + COALESCE(CAST(End_Num AS UNSIGNED), 0)) AS snapshot_key
                        FROM tbl_syl_payroll_parent_casual
                       WHERE isDeleted = 0 AND Quencina = 'whole'
                         AND (CAST(Year AS UNSIGNED) * 100 + COALESCE(CAST(End_Num AS UNSIGNED), 0)) <= ?
                       GROUP BY TID
                  ) latest ON latest.TID = p.TID
                         AND latest.snapshot_key = (CAST(p.Year AS UNSIGNED) * 100 + COALESCE(CAST(p.End_Num AS UNSIGNED), 0))
                 WHERE p.isDeleted = 0 AND p.Quencina = 'whole' AND p.PIID IN ($marks)
            ) leave_credit_roster LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$asOfKey], $piids, [$asOfKey], $piids));
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new InvalidArgumentException('Leave can only be assigned to Job Order employees; Regular or Casual Leave Credits employees are not allowed.');
    }
}

/** Additive schema for template-linked manual overrides and their source files. */
function dtr_ensure_override_extensions(PDO $conn): void {
    static $ready = false;
    if ($ready) return;
    $columns = $conn->query('SHOW COLUMNS FROM tbldtr_override')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('Template_TID', $columns, true)) {
        $conn->exec('ALTER TABLE tbldtr_override ADD COLUMN Template_TID BIGINT UNSIGNED NULL AFTER LID, ADD KEY idx_dtr_override_template (Template_TID)');
    }
    $conn->exec(
        'CREATE TABLE IF NOT EXISTS tbldtr_override_attachment (
            Attachment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            OID BIGINT UNSIGNED NOT NULL,
            Original_Filename VARCHAR(255) NOT NULL,
            Mime_Type VARCHAR(100) NOT NULL,
            File_Size_Bytes INT UNSIGNED NOT NULL,
            File_Data MEDIUMBLOB NOT NULL,
            Uploaded_By VARCHAR(50) NOT NULL,
            Uploaded_At DATETIME NOT NULL,
            PRIMARY KEY (Attachment_ID),
            KEY idx_dtr_override_attachment_oid (OID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    $indexes = $conn->query("SHOW INDEX FROM tbldtr_override_attachment WHERE Column_name = 'OID'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $index) {
        if ((int)($index['Non_unique'] ?? 1) === 0) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string)$index['Key_name']);
            if ($name !== '') $conn->exec("ALTER TABLE tbldtr_override_attachment DROP INDEX `$name`, ADD KEY idx_dtr_override_attachment_oid (OID)");
            break;
        }
    }
    $ready = true;
}

function dtr_override_attachments(PDO $conn, int $oid): array {
    $stmt = $conn->prepare('SELECT Attachment_ID, Original_Filename, Mime_Type, File_Size_Bytes, Uploaded_By, Uploaded_At FROM tbldtr_override_attachment WHERE OID = ? ORDER BY Attachment_ID');
    $stmt->execute([$oid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** The template members that an Admin Officer is explicitly assigned to manage. */
function dtr_override_assigned_template_piids(PDO $conn, array $deptScopes): array {
    $templateIds = dtr_override_assigned_template_ids($conn, $deptScopes);
    if (!$templateIds) return [];
    $marks = implode(',', array_fill(0, count($templateIds), '?'));
    $stmt = $conn->prepare("SELECT DISTINCT PIID FROM tbl_templatedtr_piid WHERE TID IN ($marks)");
    $stmt->execute($templateIds);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function dtr_override_assigned_template_ids(PDO $conn, array $deptScopes): array {
    dtr_ensure_template_department_column($conn);
    $scopes = array_values(array_unique(array_filter(array_map('strval', $deptScopes))));
    if (!$scopes) return [];
    $marks = implode(',', array_fill(0, count($scopes), '?'));
    $stmt = $conn->prepare("SELECT TID FROM tbl_templatedtr WHERE Department IN ($marks)");
    $stmt->execute($scopes);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function dtr_override_template_members(PDO $conn, int $tid): array {
    if ($tid <= 0) return [];
    $stmt = $conn->prepare('SELECT PIID FROM tbl_templatedtr_piid WHERE TID = ?');
    $stmt->execute([$tid]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Admin Officers may use employees from either their consolidated department
 * roster or an explicitly department-mapped DTR template. The latter preserves
 * legacy/JO template members that do not exist in the leave/payroll roster.
 */
function dtr_override_allowed_piids(PDO $conn, array $deptScopes): array {
    $deptScopes = array_values(array_unique(array_filter(array_map('strval', $deptScopes))));
    if (!$deptScopes) {
        return [];
    }
    lv_ensure_employee_roster($conn);
    dtr_ensure_template_department_column($conn);
    $placeholders = implode(',', array_fill(0, count($deptScopes), '?'));

    $stmt = $conn->prepare(
        'SELECT PIID FROM ' . _LV_EMP_ROSTER_TABLE . " WHERE Department IN ($placeholders)
         UNION
         SELECT tp.PIID
           FROM tbl_templatedtr_piid tp
           INNER JOIN tbl_templatedtr t ON t.TID = tp.TID
          WHERE t.Department IN ($placeholders)"
    );
    $stmt->execute(array_merge($deptScopes, $deptScopes));
    $piids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Job Order staff are not in the leave roster by design, so a department head
    // would otherwise never see them. Their office comes from the JO directory.
    $piids = array_merge($piids, jo_directory_department_piids($conn, $deptScopes));

    return array_values(array_unique($piids));
}

/**
 * Employee picker for every DTR screen (override, template groups, View DTR).
 *
 * Sourced from the PDS master, NOT from the leave/payroll roster. The roster is
 * built from tbl_syl_payroll_parent(_casual) only, so it holds ~990 of the ~9,400
 * people in tblpersonalinformation — Job Order staff, new hires whose first
 * payroll has not run yet, and anyone off the regular/casual payrolls were simply
 * unfindable even though the save path (dtr_override_save) accepts them fine.
 *
 * Department is an enrichment, not a filter: it is resolved per page of results
 * from the roster, then the JO directory, then the employee's DTR template group.
 * Department-scoped Admin Officers still see only dtr_override_allowed_piids(),
 * the same set the save path validates against, so search and save agree.
 */
function dtr_search_employees(PDO $conn, array $opts, ?array $deptScopes = null): array {
    $surname   = trim((string)($opts['surname'] ?? ''));
    $firstname = trim((string)($opts['firstname'] ?? ''));
    $dept      = trim((string)($opts['dept'] ?? ''));
    $piid      = trim((string)($opts['piid'] ?? ''));
    $templateTid = (int)($opts['template_tid'] ?? 0);
    $page      = max(1, (int)($opts['page'] ?? 1));
    $pageSize  = min(200, max(10, (int)($opts['page_size'] ?? 50)));

    // A few legacy PDS records have no surname but do have a valid employee
    // number. Keep them reachable in DTR instead of silently dropping them.
    $where  = ["(TRIM(COALESCE(pi.SurName, '')) <> '' OR TRIM(COALESCE(pi.ID_NUM, '')) <> '')"];
    $params = [];

    // The override/template pickers have a single search box, so one term has to
    // match a surname, a first name or an employee number. dtr.php's two-field
    // form still targets each column separately.
    if ($surname !== '' && $firstname === '') {
        if (!function_exists('hrmis_pds_name_term_predicate')) {
            require_once __DIR__ . '/db_auth.php';
        }
        [$termClause, $termParams] = hrmis_pds_name_term_predicate($surname, 'pi');
        $where[] = $termClause;
        $params += $termParams;
    } else {
        if ($surname !== '') {
            $where[] = 'pi.SurName LIKE :surname';
            $params[':surname'] = "%$surname%";
        }
        if ($firstname !== '') {
            $where[] = 'pi.FirstName LIKE :firstname';
            $params[':firstname'] = "%$firstname%";
        }
    }
    if ($piid !== '') {
        $where[] = '(pi.ID_NUM LIKE :employee_id OR CAST(pi.PIID AS CHAR) LIKE :internal_piid)';
        $params[':employee_id']   = "$piid%";
        $params[':internal_piid'] = "$piid%";
    }

    // Department is only known through the lookup tables, so a department filter
    // has to be resolved to a PIID set first. Same for approver scoping.
    $restrictTo = null;
    if ($deptScopes !== null) {
        $restrictTo = dtr_override_allowed_piids($conn, $deptScopes);
    } elseif ($dept !== '') {
        $restrictTo = dtr_override_allowed_piids($conn, [$dept]);
    }
    if ($templateTid > 0) {
        // The portal uses this for its template-first override picker. Never
        // trust the browser's TID: a scoped user may only search templates
        // belonging to one of their assigned departments.
        if ($deptScopes !== null && !in_array($templateTid, dtr_override_assigned_template_ids($conn, $deptScopes), true)) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => $pageSize, 'total_pages' => 1];
        }
        $templateMembers = dtr_override_template_members($conn, $templateTid);
        if (!$templateMembers) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => $pageSize, 'total_pages' => 1];
        }
        $restrictTo = $restrictTo === null
            ? $templateMembers
            : array_values(array_intersect($restrictTo, $templateMembers));
    }
    if ($restrictTo !== null) {
        if (!$restrictTo) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => $pageSize, 'total_pages' => 1];
        }
        $placeholders = [];
        foreach (array_values($restrictTo) as $i => $allowedPiid) {
            $key = ":scope_$i";
            $placeholders[] = $key;
            $params[$key] = $allowedPiid;
        }
        $where[] = 'pi.PIID IN (' . implode(',', $placeholders) . ')';
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM tblpersonalinformation pi WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;
    $offset = ($page - 1) * $pageSize;

    $stmt = $conn->prepare(
        "SELECT pi.PIID, pi.ID_NUM, pi.SurName AS Surname, pi.FirstName AS Firstname,
                pi.MiddleName, pi.NameExt
           FROM tblpersonalinformation pi
          WHERE $whereSql
          ORDER BY pi.SurName ASC, pi.FirstName ASC, pi.MiddleName ASC, pi.PIID ASC
          LIMIT $pageSize OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'rows'        => dtr_decorate_employee_rows($conn, $rows),
        'total'       => $total,
        'page'        => $page,
        'page_size'   => $pageSize,
        'total_pages' => $totalPages,
    ];
}

/**
 * Fill Department/Position/Status onto one page of PDS rows. Three batched
 * lookups, never a correlated subquery per row (see lv_batch_statuses).
 */
function dtr_decorate_employee_rows(PDO $conn, array $rows): array {
    if (!$rows) {
        return [];
    }
    $piids = array_values(array_unique(array_map(static fn($r) => (string)$r['PIID'], $rows)));
    $placeholders = implode(',', array_fill(0, count($piids), '?'));

    lv_ensure_employee_roster($conn);
    $rosterStmt = $conn->prepare(
        'SELECT PIID, Department, Position, IsCasual, Status
           FROM ' . _LV_EMP_ROSTER_TABLE . "
          WHERE PIID IN ($placeholders)"
    );
    $rosterStmt->execute($piids);
    $roster = [];
    foreach ($rosterStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roster[(string)$row['PIID']] = $row;
    }

    $joOffices = jo_directory_resolve($conn, $piids);

    // Legacy/JO groups that predate the payroll roster still carry a department
    // on the DTR template itself.
    dtr_ensure_template_department_column($conn);
    $templateStmt = $conn->prepare(
        "SELECT tp.PIID, t.Department
           FROM tbl_templatedtr_piid tp
           INNER JOIN tbl_templatedtr t ON t.TID = tp.TID
          WHERE tp.PIID IN ($placeholders)
            AND t.Department IS NOT NULL AND t.Department <> ''"
    );
    $templateStmt->execute($piids);
    $templateDepts = [];
    foreach ($templateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $templateDepts[(string)$row['PIID']] = (string)$row['Department'];
    }

    foreach ($rows as &$row) {
        $key = (string)$row['PIID'];
        $rosterRow = $roster[$key] ?? null;
        $department = trim((string)($rosterRow['Department'] ?? ''));
        if ($department === '') {
            $department = trim((string)($joOffices[$key] ?? ''));
        }
        if ($department === '') {
            $department = trim($templateDepts[$key] ?? '');
        }
        $row['Department']   = $department;
        $row['Position']     = trim((string)($rosterRow['Position'] ?? ''));
        $row['IsCasual']     = (int)($rosterRow['IsCasual'] ?? 0);
        $row['Status']       = $rosterRow['Status'] ?? null;
    }
    unset($row);

    return $rows;
}

function dtr_validate_override_piids_in_departments(PDO $conn, array $piids, array $deptScopes): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), fn($value) => $value !== '')));
    if (!$piids) {
        return [];
    }
    $allowed = array_flip(dtr_override_allowed_piids($conn, $deptScopes));
    return array_values(array_filter($piids, static fn($piid) => isset($allowed[$piid])));
}

function dtr_override_list_scoped(PDO $conn, array $opts, array $deptScopes): array {
    dtr_ensure_override_extensions($conn);
    $year = (int)($opts['year'] ?? 0);
    $month = (int)($opts['month'] ?? 0);
    $search = trim((string)($opts['search'] ?? ''));
    $page = max(1, (int)($opts['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($opts['page_size'] ?? 50)));
    // Visibility is template-member based, not just department based: a record
    // belongs in this officer's portal when at least one covered PIID is in one
    // of the officer's assigned DTR templates. This also exposes older records
    // that predate Template_TID.
    $allowedPiids = dtr_override_assigned_template_piids($conn, $deptScopes);
    if (!$allowedPiids) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => $pageSize, 'total_pages' => 1];
    }
    if ($year <= 0) {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $year = (int)$now->format('Y');
        if ($month < 0 || $month > 12) {
            $month = (int)$now->format('n');
        }
    } elseif ($month < 0 || $month > 12) {
        $month = 0;
    }

    // First page/filter the small set of override headers for the requested
    // month, then validate their employees in PHP. MySQL 5.1 spent minutes
    // materializing/grouping the full override-person table when department
    // validation lived inside the COUNT query.
    $joins =
        ' INNER JOIN (
            SELECT DISTINCT OID
              FROM tbldtr_override_details
             WHERE DTR_Date BETWEEN ? AND ?
          ) matched ON matched.OID = o.OID';
    $where = ['COALESCE(o.LID, 0) = 0'];
    $start = $month > 0
        ? sprintf('%04d-%02d-01', $year, $month)
        : sprintf('%04d-01-01', $year);
    $end = $month > 0 ? date('Y-m-t', strtotime($start)) : sprintf('%04d-12-31', $year);
    $params = [$start, $end];
    if ($search !== '') {
        // Build matching OIDs once. A correlated employee lookup made the
        // override list scan repeatedly and could leave the browser loading.
        $joins .= ' LEFT JOIN (
            SELECT DISTINCT op.OID
              FROM tbldtr_override_person op
              INNER JOIN tblpersonalinformation pi ON pi.PIID = op.PIID
              INNER JOIN (SELECT DISTINCT OID FROM tbldtr_override_details WHERE DTR_Date BETWEEN ? AND ?) employee_dates
                ON employee_dates.OID = op.OID
             WHERE pi.SurName LIKE ? OR pi.FirstName LIKE ? OR pi.MiddleName LIKE ? OR pi.ID_NUM LIKE ?
                OR CONCAT_WS(\' \', pi.FirstName, pi.MiddleName, pi.SurName) LIKE ?
                OR CONCAT_WS(\' \', pi.SurName, pi.FirstName, pi.MiddleName) LIKE ?
        ) employee_match ON employee_match.OID = o.OID';
        $params[] = $start;
        $params[] = $end;
        for ($i = 0; $i < 6; $i++) {
            $params[] = '%' . $search . '%';
        }
        $where[] = '(o.Name LIKE ? OR o.Remarks LIKE ? OR employee_match.OID IS NOT NULL)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $candidateStmt = $conn->prepare(
        "SELECT o.OID, o.Name, o.ODate, o.Override_Type, o.Remarks, o.Created_By, o.Created_Date, o.Template_TID
           FROM tbldtr_override o
           $joins
          WHERE " . implode(' AND ', $where) . "
          ORDER BY o.OID DESC"
    );
    $candidateStmt->execute($params);
    $candidateRows = $candidateStmt->fetchAll();
    $candidateByOid = [];
    foreach ($candidateRows as $row) {
        $candidateByOid[(int)$row['OID']] = $row;
    }

    $employeesByOid = [];
    foreach (array_chunk(array_keys($candidateByOid), 400) as $oidChunk) {
        if (!$oidChunk) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($oidChunk), '?'));
        $personStmt = $conn->prepare(
            "SELECT OID, PIID
               FROM tbldtr_override_person
              WHERE OID IN ($placeholders)"
        );
        $personStmt->execute($oidChunk);
        foreach ($personStmt->fetchAll() as $person) {
            $employeesByOid[(int)$person['OID']][] = (string)$person['PIID'];
        }
    }

    $allowedLookup = array_flip($allowedPiids);
    $allowedTemplates = dtr_override_assigned_template_ids($conn, $deptScopes);
    $scopedRows = [];
    foreach ($candidateByOid as $oid => $row) {
        $recordPiids = array_values(array_unique($employeesByOid[$oid] ?? []));
        $visiblePiids = array_values(array_filter($recordPiids, static fn($piid) => isset($allowedLookup[$piid])));
        if ($visiblePiids) {
            $row['employee_count'] = count($visiblePiids);
            $row['total_employee_count'] = count($recordPiids);
            $row['partial_scope'] = count($visiblePiids) !== count($recordPiids);
            $row['can_edit'] = !$row['partial_scope'] && in_array((int)($row['Template_TID'] ?? 0), $allowedTemplates, true);
            $scopedRows[] = $row;
        }
    }

    $total = count($scopedRows);
    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $totalPages);
    $rows = array_slice($scopedRows, ($page - 1) * $pageSize, $pageSize);
    if (!$rows) {
        return ['rows' => [], 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'total_pages' => $totalPages];
    }

    $pageOids = array_map('intval', array_column($rows, 'OID'));
    $placeholders = implode(',', array_fill(0, count($pageOids), '?'));
    $dateCounts = [];
    $coveredDates = [];
    $stmt = $conn->prepare(
        "SELECT OID, DTR_Date
           FROM tbldtr_override_details
          WHERE OID IN ($placeholders)
          ORDER BY OID, DTR_Date"
    );
    $stmt->execute($pageOids);
    foreach ($stmt->fetchAll() as $row) {
        $oid = (int)$row['OID'];
        $dateCounts[$oid] = ($dateCounts[$oid] ?? 0) + 1;
        $coveredDates[$oid][] = (string)$row['DTR_Date'];
    }
    foreach ($rows as &$row) {
        $row['date_count'] = $dateCounts[$row['OID']] ?? 0;
        $row['covered_dates'] = $coveredDates[$row['OID']] ?? [];
        $row['attachments'] = dtr_override_attachments($conn, (int)$row['OID']);
        $row['attachment'] = $row['attachments'][0] ?? null;
    }
    unset($row);

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
    ];
}

function dtr_override_list(PDO $conn, array $opts = []): array {
    dtr_ensure_override_extensions($conn);
    $year = (int)($opts['year'] ?? 0);
    $month = (int)($opts['month'] ?? 0);
    $search = trim((string)($opts['search'] ?? ''));
    $page = max(1, (int)($opts['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($opts['page_size'] ?? 50)));
    $deptScopes = array_key_exists('department_scopes', $opts) ? $opts['department_scopes'] : null;
    if ($deptScopes !== null) {
        return dtr_override_list_scoped($conn, $opts, $deptScopes);
    }

    // When filtering by month, join against a small pre-filtered derived table of
    // matching OIDs (built off the indexed DTR_Date column) rather than an
    // IN(SELECT DISTINCT ...) or correlated EXISTS against the 250k-row details
    // table — both of those forced MySQL into a slow per-row/temp-table scan
    // (~150s) against the full tbldtr_override table. Joining a small derived
    // table keeps this to a handful of milliseconds regardless of table size.
    $joins = [];
    $where = ['COALESCE(o.LID, 0) = 0'];
    $params = [];
    if ($year > 0) {
        $start = $month > 0
            ? sprintf('%04d-%02d-01', $year, $month)
            : sprintf('%04d-01-01', $year);
        $end = $month > 0 ? date('Y-m-t', strtotime($start)) : sprintf('%04d-12-31', $year);
        $joins[] = 'INNER JOIN (SELECT DISTINCT OID FROM tbldtr_override_details WHERE DTR_Date BETWEEN :start_date AND :end_date) matched ON matched.OID = o.OID';
        $params[':start_date'] = $start;
        $params[':end_date'] = $end;
    }
    if ($search !== '') {
        // Pre-filter employee matches once instead of a correlated lookup for
        // every override row; that keeps searches responsive on the DTR data.
        $employeeDateJoin = '';
        if ($year > 0) {
            $employeeDateJoin = 'INNER JOIN (SELECT DISTINCT OID FROM tbldtr_override_details WHERE DTR_Date BETWEEN :employee_search_start AND :employee_search_end) employee_dates ON employee_dates.OID = op.OID';
            $params[':employee_search_start'] = $start;
            $params[':employee_search_end'] = $end;
        }
        $joins[] = "LEFT JOIN (
            SELECT DISTINCT op.OID
              FROM tbldtr_override_person op
              INNER JOIN tblpersonalinformation pi ON pi.PIID = op.PIID
              $employeeDateJoin
             WHERE pi.SurName LIKE :search_surname OR pi.FirstName LIKE :search_firstname
                OR pi.MiddleName LIKE :search_middlename OR pi.ID_NUM LIKE :search_employee_no
                OR CONCAT_WS(' ', pi.FirstName, pi.MiddleName, pi.SurName) LIKE :search_first_last
                OR CONCAT_WS(' ', pi.SurName, pi.FirstName, pi.MiddleName) LIKE :search_last_first
        ) employee_match ON employee_match.OID = o.OID";
        $where[] = '(o.Name LIKE :search_name OR o.Remarks LIKE :search_remarks OR employee_match.OID IS NOT NULL)';
        $params[':search_name'] = "%$search%";
        $params[':search_remarks'] = "%$search%";
        $params[':search_surname'] = "%$search%";
        $params[':search_firstname'] = "%$search%";
        $params[':search_middlename'] = "%$search%";
        $params[':search_employee_no'] = "%$search%";
        $params[':search_first_last'] = "%$search%";
        $params[':search_last_first'] = "%$search%";
    }
    $whereSql = implode(' AND ', $where);
    $joinSql = implode(' ', $joins);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM tbldtr_override o $joinSql WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $page = max(1, $page);
    $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;
    $offset = ($page - 1) * $pageSize;

    // Page first (fast — proven in isolation), then batch-fetch employee/date counts
    // for just this page's OIDs as two separate GROUP BY queries and merge in PHP.
    // A correlated subquery in the SELECT list against this derived-table page (even
    // for only page_size rows) made MySQL hang indefinitely here — a known class of
    // optimizer trap with correlated subqueries over derived tables. Two flat batched
    // queries sidestep it entirely and match the batch-fetch pattern already used
    // elsewhere in this codebase (see lv_batch_statuses in leave_db.php).
    $stmt = $conn->prepare(
        "SELECT o.OID, o.Name, o.ODate, o.Override_Type, o.Remarks, o.Created_By, o.Created_Date, o.Template_TID
         FROM tbldtr_override o
         $joinSql
         WHERE $whereSql
         ORDER BY o.OID DESC
         LIMIT $pageSize OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $oids = array_column($rows, 'OID');
    $employeeCounts = [];
    $dateCounts = [];
    $coveredDates = [];
    if ($oids) {
        $placeholders = implode(',', array_fill(0, count($oids), '?'));
        $stmt = $conn->prepare("SELECT OID, COUNT(*) AS c FROM tbldtr_override_person WHERE OID IN ($placeholders) GROUP BY OID");
        $stmt->execute($oids);
        foreach ($stmt->fetchAll() as $row) {
            $employeeCounts[$row['OID']] = (int)$row['c'];
        }
        $stmt = $conn->prepare("SELECT OID, DTR_Date FROM tbldtr_override_details WHERE OID IN ($placeholders) ORDER BY OID, DTR_Date");
        $stmt->execute($oids);
        foreach ($stmt->fetchAll() as $row) {
            $oid = (int)$row['OID'];
            $dateCounts[$oid] = ($dateCounts[$oid] ?? 0) + 1;
            $coveredDates[$oid][] = (string)$row['DTR_Date'];
        }
    }
    foreach ($rows as &$row) {
        $row['employee_count'] = $employeeCounts[$row['OID']] ?? 0;
        $row['date_count'] = $dateCounts[$row['OID']] ?? 0;
        $row['covered_dates'] = $coveredDates[$row['OID']] ?? [];
        $row['can_edit'] = true;
        $row['attachments'] = dtr_override_attachments($conn, (int)$row['OID']);
        $row['attachment'] = $row['attachments'][0] ?? null;
    }
    unset($row);

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
    ];
}

function dtr_override_get(PDO $conn, int $oid, ?array $deptScopes = null): ?array {
    dtr_ensure_override_extensions($conn);
    $stmt = $conn->prepare('SELECT * FROM tbldtr_override WHERE OID = :oid');
    $stmt->execute([':oid' => $oid]);
    $header = $stmt->fetch();
    if (
        !$header
        || (int)($header['LID'] ?? 0) > 0
    ) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT p.PIID, pi.SurName, pi.FirstName, pi.MiddleName
         FROM tbldtr_override_person p
         LEFT JOIN tblpersonalinformation pi ON pi.PIID = p.PIID
         WHERE p.OID = :oid
         ORDER BY pi.SurName, pi.FirstName"
    );
    $stmt->execute([':oid' => $oid]);
    $header['employees'] = $stmt->fetchAll();
    if ($deptScopes !== null) {
        $recordPiids = array_values(array_unique(array_map('strval', array_column($header['employees'], 'PIID'))));
        $allowedPiids = dtr_override_assigned_template_piids($conn, $deptScopes);
        $visible = array_values(array_intersect($recordPiids, $allowedPiids));
        if (!$visible) {
            return null;
        }
        $header['partial_scope'] = count($visible) !== count($recordPiids);
        $assignedTemplates = dtr_override_assigned_template_ids($conn, $deptScopes);
        $header['can_edit'] = !$header['partial_scope']
            && in_array((int)($header['Template_TID'] ?? 0), $assignedTemplates, true);
        if ($header['partial_scope']) {
            $visibleLookup = array_flip($visible);
            $header['employees'] = array_values(array_filter($header['employees'], static fn($employee) => isset($visibleLookup[(string)$employee['PIID']])));
        }
    } else {
        $header['partial_scope'] = false;
        $header['can_edit'] = true;
    }

    $stmt = $conn->prepare(
        'SELECT DTR_Date, Time_Start, Time_End
         FROM tbldtr_override_details
         WHERE OID = :oid
         ORDER BY DTR_Date'
    );
    $stmt->execute([':oid' => $oid]);
    $header['dates'] = $stmt->fetchAll();

    $header['attachments'] = dtr_override_attachments($conn, $oid);
    $header['attachment'] = $header['attachments'][0] ?? null;

    return $header;
}

function dtr_normalize_time(string $time, string $fallback): string {
    $time = trim($time);
    if ($time === '') {
        return $fallback;
    }
    return strlen($time) === 5 ? $time . ':00' : $time;
}

/**
 * Create or update an override record (data['oid'] > 0 to update). Transactional
 * delete-then-reinsert of person/detail rows, matching the VB app's save semantics.
 * Returns the OID.
 */
function dtr_override_save(PDO $conn, array $data, string $actor, ?array $deptScopes = null, bool $isAdminOfficer = false, ?array $attachmentFiles = null): int {
    dtr_ensure_override_extensions($conn);
    $oid = (int)($data['oid'] ?? 0);
    $templateTid = (int)($data['template_tid'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $overrideType = trim((string)($data['override_type'] ?? ''));
    $remarks = trim((string)($data['remarks'] ?? ''));
    $piids = array_values(array_unique(array_filter(array_map('strval', $data['piids'] ?? []), fn($v) => $v !== '')));
    $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
    $acknowledgePending = filter_var($data['acknowledge_pending_leave_overlap'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($name === '' && $overrideType !== 'Absent') {
        throw new InvalidArgumentException('Name is required');
    }
    if (!in_array($overrideType, DTR_OVERRIDE_TYPES, true)) {
        throw new InvalidArgumentException('Invalid override type');
    }
    if ($name === '') {
        $name = 'Absent';
    }
    if (empty($piids)) {
        throw new InvalidArgumentException('At least one employee is required');
    }
    if (empty($dates)) {
        throw new InvalidArgumentException('At least one date is required');
    }
    if (lv_dtr_eligible($name)) {
        throw new InvalidArgumentException('Leave must be recorded through the Leave Application module');
    }
    if (in_array($overrideType, ['Leave', 'JO Leave'], true)) {
        dtr_validate_jo_leave_employees($conn, $piids, $dates);
    }
    if ($deptScopes !== null) {
        $validPiids = dtr_validate_override_piids_in_departments($conn, $piids, $deptScopes);
        if (count($validPiids) !== count($piids)) {
            throw new InvalidArgumentException('One or more employees are outside your assigned departments');
        }
    }
    if ($oid > 0) {
        $existing = dtr_override_get($conn, $oid, $deptScopes);
        if (!$existing) {
            throw new InvalidArgumentException('Manual override record not found');
        }
        if ($isAdminOfficer && empty($existing['can_edit'])) {
            throw new InvalidArgumentException('This override also covers employees outside your assigned templates and can only be viewed');
        }
    }
    if ($isAdminOfficer) {
        $allowedTemplates = dtr_override_assigned_template_ids($conn, $deptScopes ?? []);
        if ($templateTid <= 0 || !in_array($templateTid, $allowedTemplates, true)) {
            throw new InvalidArgumentException('Select one of your assigned DTR templates');
        }
        $templateMembers = array_flip(dtr_override_template_members($conn, $templateTid));
        if (array_diff_key(array_flip($piids), $templateMembers)) {
            throw new InvalidArgumentException('Every employee in the override must belong to the selected template');
        }
    }
    $uploads = [];
    if ($attachmentFiles && is_array($attachmentFiles['name'] ?? null)) {
        foreach ($attachmentFiles['name'] as $i => $name) $uploads[] = ['name' => $name, 'type' => $attachmentFiles['type'][$i] ?? '', 'tmp_name' => $attachmentFiles['tmp_name'][$i] ?? '', 'error' => $attachmentFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $attachmentFiles['size'][$i] ?? 0];
    } elseif ($attachmentFiles) {
        $uploads[] = $attachmentFiles;
    }
    $uploads = array_values(array_filter($uploads, static fn($file) => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    foreach ($uploads as &$file) {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('An attachment could not be uploaded');
        if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) throw new InvalidArgumentException('Each attachment must be 10 MB or smaller');
        $file['mime'] = mime_content_type((string)$file['tmp_name']);
        if (!in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true)) throw new InvalidArgumentException('Attachments must be PDF, JPG, or PNG');
    }
    unset($file);

    $leaveConflicts = dtr_find_leave_conflicts_for_override($conn, $piids, $dates);
    $approved = array_values(array_filter($leaveConflicts, static fn($row) => (string)$row['status'] === 'Approved'));
    if ($approved) {
        throw new InvalidArgumentException(
            'An approved leave already covers the selected employee/date: '
            . dtr_leave_conflict_message($approved)
            . '. Cancel or reschedule the leave before adding an override.'
        );
    }
    if ($leaveConflicts && !$acknowledgePending) {
        throw new InvalidArgumentException(
            'PENDING_LEAVE_WARNING: A pending leave request overlaps this override: '
            . dtr_leave_conflict_message($leaveConflicts)
        );
    }

    $conn->beginTransaction();
    try {
        if ($oid > 0) {
            $stmt = $conn->prepare(
                'UPDATE tbldtr_override
                 SET Name = :name, Override_Type = :type, Remarks = :remarks, Template_TID = :template_tid, Updated_By = :actor
                 WHERE OID = :oid'
            );
            $stmt->execute([
                ':name' => $name, ':type' => $overrideType, ':remarks' => $remarks,
                ':actor' => substr($actor, 0, 20), ':template_tid' => $templateTid ?: null, ':oid' => $oid,
            ]);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO tbldtr_override (Name, ODate, Override_Type, Remarks, Created_By, Created_Date, Updated_By, Template_TID)
                 VALUES (:name, CURDATE(), :type, :remarks, :created_by, NOW(), :updated_by, :template_tid)'
            );
            $stmt->execute([
                ':name' => $name, ':type' => $overrideType, ':remarks' => $remarks,
                ':created_by' => substr($actor, 0, 50), ':updated_by' => substr($actor, 0, 20), ':template_tid' => $templateTid ?: null,
            ]);
            $oid = (int)$conn->lastInsertId();
        }

        $conn->prepare('DELETE FROM tbldtr_override_person WHERE OID = :oid')->execute([':oid' => $oid]);
        if ($piids) {
            $personPlaceholders = implode(',', array_fill(0, count($piids), '(?, ?)'));
            $personParams = [];
            foreach ($piids as $piid) {
                $personParams[] = $oid;
                $personParams[] = $piid;
            }
            $conn->prepare("INSERT INTO tbldtr_override_person (OID, PIID) VALUES $personPlaceholders")
                 ->execute($personParams);
        }

        $conn->prepare('DELETE FROM tbldtr_override_details WHERE OID = :oid')->execute([':oid' => $oid]);
        // Validate every date before inserting any of them, so a bad entry never
        // leaves earlier rows in this batch half-written ahead of the throw.
        $detailRows = [];
        foreach ($dates as $d) {
            $date = trim((string)($d['date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $start = dtr_normalize_time((string)($d['time_start'] ?? ''), '08:00:00');
            $end = dtr_normalize_time((string)($d['time_end'] ?? ''), '17:00:00');
            if ($overrideType !== 'Tracer Slip' && dtr_standard_override_window($start, $end) === null) {
                throw new InvalidArgumentException('Memo, Travel Order, Absent, and Leave overrides must use Morning, Afternoon, or Whole Day coverage');
            }
            $detailRows[] = [$date, $start, $end];
        }
        if ($detailRows) {
            $detailPlaceholders = implode(',', array_fill(0, count($detailRows), '(?, ?, ?, ?)'));
            $detailParams = [];
            foreach ($detailRows as [$date, $start, $end]) {
                $detailParams[] = $oid;
                $detailParams[] = $date;
                $detailParams[] = $start;
                $detailParams[] = $end;
            }
            $conn->prepare("INSERT INTO tbldtr_override_details (OID, DTR_Date, Time_Start, Time_End) VALUES $detailPlaceholders")
                 ->execute($detailParams);
        }

        foreach ($uploads as $file) {
            $binary = file_get_contents((string)$file['tmp_name']);
            if ($binary === false) throw new InvalidArgumentException('Could not read an attachment');
            $store = $conn->prepare('INSERT INTO tbldtr_override_attachment (OID, Original_Filename, Mime_Type, File_Size_Bytes, File_Data, Uploaded_By, Uploaded_At) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $store->bindValue(1, $oid, PDO::PARAM_INT);
            $store->bindValue(2, substr(basename((string)$file['name']), 0, 255));
            $store->bindValue(3, $file['mime']);
            $store->bindValue(4, (int)$file['size'], PDO::PARAM_INT);
            $store->bindValue(5, $binary, PDO::PARAM_LOB);
            $store->bindValue(6, substr($actor, 0, 50));
            $store->execute();
        }

        $conn->commit();
        return $oid;
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

function dtr_find_leave_conflicts_for_override(PDO $conn, array $piids, array $dates): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids))));
    $dateWindows = [];
    foreach ($dates as $dateRow) {
        $date = trim((string)($dateRow['date'] ?? ''));
        if ($date === '') {
            continue;
        }
        $dateWindows[$date] = [
            'start' => dtr_normalize_time((string)($dateRow['time_start'] ?? ''), '08:00:00'),
            'end' => dtr_normalize_time((string)($dateRow['time_end'] ?? ''), '17:00:00'),
        ];
    }
    $dateValues = array_keys($dateWindows);
    if (!$piids || !$dateValues) {
        return [];
    }
    lv_ensure_leave_applications_table($conn);
    $piidPlaceholders = implode(',', array_fill(0, count($piids), '?'));
    $periodFrom = min($dateValues);
    $periodTo = max($dateValues);
    $stmt = $conn->prepare(
        "SELECT id, piid, employee_name, leave_type, period_from, period_to, half_day_mode, status
           FROM tbl_leave_applications
          WHERE piid IN ($piidPlaceholders)
            AND status IN ('Pending', 'Approved')
            AND period_from <= ?
            AND period_to >= ?
          ORDER BY status DESC, period_from, id"
    );
    $stmt->execute(array_merge($piids, [$periodTo, $periodFrom]));
    $conflicts = array_values(array_filter($stmt->fetchAll() ?: [], static function ($row) use ($dateWindows): bool {
        $mode = strtolower(trim((string)($row['half_day_mode'] ?? 'full')));
        foreach ($dateWindows as $date => $manualWindow) {
            if ($date >= (string)$row['period_from'] && $date <= (string)$row['period_to']) {
                $leaveStart = '08:00:00';
                $leaveEnd = '17:00:00';
                if ($date === (string)$row['period_to']) {
                    if ($mode === 'am') {
                        $leaveEnd = '12:00:00';
                    } elseif ($mode === 'pm') {
                        $leaveStart = '12:45:00';
                    }
                }
                if ($manualWindow['start'] < $leaveEnd && $manualWindow['end'] > $leaveStart) {
                    return true;
                }
            }
        }
        return false;
    }));

    // Legacy/posted ledger leave may predate online applications. Its system
    // projection is still authoritative and must also block a manual override.
    $datePlaceholders = implode(',', array_fill(0, count($dateValues), '?'));
    $projected = $conn->prepare(
        "SELECT DISTINCT o.LID AS id, p.PIID AS piid, '' AS employee_name,
                o.Name AS leave_type, d.DTR_Date AS period_from,
                d.DTR_Date AS period_to, '' AS half_day_mode,
                d.Time_Start, d.Time_End, 'Approved' AS status
           FROM tbldtr_override o
           JOIN tbldtr_override_person p ON p.OID = o.OID
           JOIN tbldtr_override_details d ON d.OID = o.OID
          WHERE COALESCE(o.LID, 0) > 0
            AND p.PIID IN ($piidPlaceholders)
            AND d.DTR_Date IN ($datePlaceholders)"
    );
    $projected->execute(array_merge($piids, $dateValues));
    foreach ($projected->fetchAll() ?: [] as $row) {
        $manualWindow = $dateWindows[(string)$row['period_from']] ?? null;
        if (
            !$manualWindow
            || $manualWindow['start'] >= (string)($row['Time_End'] ?: '17:00:00')
            || $manualWindow['end'] <= (string)($row['Time_Start'] ?: '08:00:00')
        ) {
            continue;
        }
        $key = (string)$row['piid'] . '|' . (string)$row['period_from'] . '|' . strtolower((string)$row['leave_type']);
        $exists = false;
        foreach ($conflicts as $existing) {
            if (
                (string)$existing['piid'] === (string)$row['piid']
                && (string)$row['period_from'] >= (string)$existing['period_from']
                && (string)$row['period_from'] <= (string)$existing['period_to']
            ) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $conflicts[$key] = $row;
        }
    }
    return array_values($conflicts);
}

function dtr_leave_conflict_message(array $conflicts): string {
    $items = [];
    foreach (array_slice($conflicts, 0, 5) as $row) {
        $items[] = sprintf(
            '%s — %s (%s to %s, %s)',
            (string)($row['employee_name'] ?: $row['piid']),
            lv_main_type((string)$row['leave_type']),
            (string)$row['period_from'],
            (string)$row['period_to'],
            (string)$row['status']
        );
    }
    return implode('; ', $items);
}

function dtr_override_delete(PDO $conn, int $oid, ?array $deptScopes = null): void {
    if (!dtr_override_get($conn, $oid, $deptScopes)) {
        throw new InvalidArgumentException('Manual override record not found');
    }
    $conn->beginTransaction();
    try {
        $conn->prepare('DELETE FROM tbldtr_override_attachment WHERE OID = :oid')->execute([':oid' => $oid]);
        $conn->prepare('DELETE FROM tbldtr_override_details WHERE OID = :oid')->execute([':oid' => $oid]);
        $conn->prepare('DELETE FROM tbldtr_override_person WHERE OID = :oid')->execute([':oid' => $oid]);
        $conn->prepare('DELETE FROM tbldtr_override WHERE OID = :oid')->execute([':oid' => $oid]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

function dtr_override_remove_employee(PDO $conn, int $oid, string $piid, ?array $deptScopes = null): void {
    if (!dtr_override_get($conn, $oid, $deptScopes)) {
        throw new InvalidArgumentException('Manual override record not found');
    }
    $stmt = $conn->prepare('DELETE FROM tbldtr_override_person WHERE OID = :oid AND PIID = :piid');
    $stmt->execute([':oid' => $oid, ':piid' => $piid]);
}

// ============================================================================
// Bulk-print template groups — saved employee rosters for printing many DTRs
// at once (tbl_templatedtr + tbl_templatedtr_piid). Mirrors frmAlldtr.vb's
// template tab.
//
// Department scoping: tbl_templatedtr.Department is NULL for groups created
// from the admin DTR screen (global, admin-only — unchanged legacy behavior).
// Portal dept-head/admin-officer users create groups stamped with their own
// department and may only see/edit/delete groups tagged to a department in
// their $deptScopes. Passing $deptScopes = null means "admin, unscoped" and
// preserves the original unfiltered behavior.
// ============================================================================

function dtr_ensure_template_department_column(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $columns = $conn->query('SHOW COLUMNS FROM tbl_templatedtr')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('Department', $columns, true)) {
        $conn->exec('ALTER TABLE tbl_templatedtr ADD COLUMN Department VARCHAR(150) NULL DEFAULT NULL AFTER type');
    }
    $ensured = true;
}

function dtr_templates_list(PDO $conn, ?array $deptScopes = null, bool $includeEmployees = false): array {
    dtr_ensure_template_department_column($conn);
    $sql = 'SELECT TID, Name, Charge, InCharge, type, Department, Updated_By, Last_Update FROM tbl_templatedtr';
    $params = [];
    if ($deptScopes !== null) {
        if (!$deptScopes) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($deptScopes), '?'));
        $sql .= " WHERE Department IN ($placeholders)";
        $params = array_values($deptScopes);
    }
    $sql .= ' ORDER BY Name';
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $tids = array_column($rows, 'TID');
    $counts = [];
    if ($tids) {
        $placeholders = implode(',', array_fill(0, count($tids), '?'));
        $countStmt = $conn->prepare(
            "SELECT TID, COUNT(*) AS c
             FROM tbl_templatedtr_piid
             WHERE TID IN ($placeholders)
             GROUP BY TID"
        );
        $countStmt->execute($tids);
        foreach ($countStmt->fetchAll() as $row) {
            $counts[$row['TID']] = (int)$row['c'];
        }
    }
    foreach ($rows as &$row) {
        $row['employee_count'] = $counts[$row['TID']] ?? 0;
    }
    unset($row);

    if ($includeEmployees && $tids) {
        $placeholders = implode(',', array_fill(0, count($tids), '?'));
        $employeeStmt = $conn->prepare(
            "SELECT tp.TID, tp.PIID, pi.SurName, pi.FirstName, pi.MiddleName
             FROM tbl_templatedtr_piid tp
             LEFT JOIN tblpersonalinformation pi ON pi.PIID = tp.PIID
             WHERE tp.TID IN ($placeholders)
             ORDER BY tp.TID, pi.SurName, pi.FirstName"
        );
        $employeeStmt->execute($tids);
        $employeesByTemplate = [];
        foreach ($employeeStmt->fetchAll() as $employee) {
            $employeesByTemplate[$employee['TID']][] = [
                'PIID' => $employee['PIID'],
                'SurName' => $employee['SurName'],
                'FirstName' => $employee['FirstName'],
                'MiddleName' => $employee['MiddleName'],
            ];
        }
        foreach ($rows as &$row) {
            $row['employees'] = $employeesByTemplate[$row['TID']] ?? [];
        }
        unset($row);
    }

    return $rows;
}

function dtr_template_get(PDO $conn, int $tid, ?array $deptScopes = null): ?array {
    dtr_ensure_template_department_column($conn);
    $stmt = $conn->prepare('SELECT * FROM tbl_templatedtr WHERE TID = :tid');
    $stmt->execute([':tid' => $tid]);
    $header = $stmt->fetch();
    if (!$header) {
        return null;
    }
    if ($deptScopes !== null && !in_array($header['Department'], $deptScopes, true)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT tp.PIID, pi.SurName, pi.FirstName, pi.MiddleName
         FROM tbl_templatedtr_piid tp
         LEFT JOIN tblpersonalinformation pi ON pi.PIID = tp.PIID
         WHERE tp.TID = :tid
         ORDER BY pi.SurName, pi.FirstName"
    );
    $stmt->execute([':tid' => $tid]);
    $header['employees'] = $stmt->fetchAll();

    return $header;
}

/**
 * Returns the subset of $piids whose roster Department is in $deptScopes.
 * Flat join, no correlated subquery (large-table subquery correlation has
 * hung this connection before — see dtr_get_bulk_month callers' history).
 */
function dtr_validate_piids_in_departments(PDO $conn, array $piids, array $deptScopes): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), fn($v) => $v !== '')));
    if (!$piids || !$deptScopes) {
        return [];
    }
    // Department is sourced from the consolidated leave/payroll roster. The
    // base personal-information table does not carry a Department column.
    lv_ensure_employee_roster($conn);
    $piidPlaceholders = implode(',', array_fill(0, count($piids), '?'));
    $deptPlaceholders = implode(',', array_fill(0, count($deptScopes), '?'));
    $stmt = $conn->prepare(
        'SELECT PIID FROM ' . _LV_EMP_ROSTER_TABLE
        . " WHERE PIID IN ($piidPlaceholders) AND Department IN ($deptPlaceholders)"
    );
    $stmt->execute(array_merge($piids, array_values($deptScopes)));
    $allowed = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // JO staff are absent from the roster by design; validate them against the JO
    // directory so a department head can act on their own office's JO employees.
    $joInScope = jo_directory_department_piids($conn, array_values($deptScopes));
    if ($joInScope) {
        $allowed = array_merge($allowed, array_values(array_intersect($piids, $joInScope)));
    }

    return array_values(array_unique($allowed));
}

function dtr_template_save(PDO $conn, array $data, string $actor, ?array $deptScopes = null, ?string $department = null): int {
    dtr_ensure_template_department_column($conn);
    $tid = (int)($data['tid'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $charge = trim((string)($data['charge'] ?? ''));
    $inCharge = trim((string)($data['in_charge'] ?? ''));
    $type = trim((string)($data['type'] ?? 'Regular'));
    $type = in_array($type, ['Regular', 'JO'], true) ? $type : 'Regular';
    $piids = array_values(array_unique(array_filter(array_map('strval', $data['piids'] ?? []), fn($v) => $v !== '')));

    if ($name === '') {
        throw new InvalidArgumentException('Name is required');
    }

    if ($deptScopes !== null) {
        // Scoped (portal dept-head/admin-officer) caller: confine the group to
        // one of their own departments, and silently drop any submitted PIID
        // that doesn't actually belong to that department.
        $department = $department !== null && in_array($department, $deptScopes, true) ? $department : ($deptScopes[0] ?? null);
        if ($department === null) {
            throw new InvalidArgumentException('No department scope available for this account');
        }
        $existingPiids = [];
        $existingDept = null;
        if ($tid > 0) {
            $existing = $conn->prepare('SELECT Department FROM tbl_templatedtr WHERE TID = :tid');
            $existing->execute([':tid' => $tid]);
            $existingDept = $existing->fetchColumn();
            if ($existingDept === false || !in_array($existingDept, $deptScopes, true)) {
                throw new InvalidArgumentException('Template group not found');
            }
            if ($department === $existingDept) {
                $existingMembers = $conn->prepare('SELECT PIID FROM tbl_templatedtr_piid WHERE TID = :tid');
                $existingMembers->execute([':tid' => $tid]);
                $existingPiids = array_map('strval', $existingMembers->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        // Preserve legacy/JO members already belonging to an explicitly
        // department-mapped group. New members still have to come from the
        // scoped consolidated roster.
        $validRosterPiids = dtr_validate_piids_in_departments($conn, $piids, [$department]);
        $allowedPiids = array_flip(array_merge($existingPiids, $validRosterPiids));
        $piids = array_values(array_filter($piids, static fn($piid) => isset($allowedPiids[$piid])));
    }

    $conn->beginTransaction();
    try {
        if ($tid > 0) {
            $stmt = $conn->prepare(
                'UPDATE tbl_templatedtr SET Name = :name, Charge = :charge, InCharge = :in_charge, type = :type'
                . ($deptScopes !== null ? ', Department = :department' : '')
                . ' , Updated_By = :actor WHERE TID = :tid'
            );
            $params = [
                ':name' => $name, ':charge' => $charge, ':in_charge' => $inCharge,
                ':type' => $type, ':actor' => substr($actor, 0, 20), ':tid' => $tid,
            ];
            if ($deptScopes !== null) {
                $params[':department'] = $department;
            }
            $stmt->execute($params);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO tbl_templatedtr (Name, Charge, InCharge, type, Department, Updated_By) VALUES (:name, :charge, :in_charge, :type, :department, :actor)'
            );
            $stmt->execute([
                ':name' => $name, ':charge' => $charge, ':in_charge' => $inCharge,
                ':type' => $type, ':department' => $deptScopes !== null ? $department : null,
                ':actor' => substr($actor, 0, 20),
            ]);
            $tid = (int)$conn->lastInsertId();
        }

        $conn->prepare('DELETE FROM tbl_templatedtr_piid WHERE TID = :tid')->execute([':tid' => $tid]);
        if ($piids) {
            $placeholders = implode(',', array_fill(0, count($piids), '(?, ?)'));
            $params = [];
            foreach ($piids as $piid) {
                $params[] = $tid;
                $params[] = $piid;
            }
            $conn->prepare("INSERT INTO tbl_templatedtr_piid (TID, PIID) VALUES $placeholders")->execute($params);
        }

        $conn->commit();
        return $tid;
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

function dtr_template_delete(PDO $conn, int $tid, ?array $deptScopes = null): void {
    dtr_ensure_template_department_column($conn);
    if ($deptScopes !== null) {
        $existing = $conn->prepare('SELECT Department FROM tbl_templatedtr WHERE TID = :tid');
        $existing->execute([':tid' => $tid]);
        $existingDept = $existing->fetchColumn();
        if ($existingDept === false || !in_array($existingDept, $deptScopes, true)) {
            throw new InvalidArgumentException('Template group not found');
        }
    }
    $conn->beginTransaction();
    try {
        $conn->prepare('DELETE FROM tbl_templatedtr_piid WHERE TID = :tid')->execute([':tid' => $tid]);
        $conn->prepare('DELETE FROM tbl_templatedtr WHERE TID = :tid')->execute([':tid' => $tid]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Build several employee DTR grids with shared source data loaded once.
 * This avoids the old employee x workday holiday/override query pattern.
 */
function dtr_get_bulk_month(PDO $conn, array $piids, int $year, int $month, string $period = 'full'): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn($value) => $value !== '')));
    if (!$piids) return [];

    [$startDay, $endDay] = dtr_period_range($year, $month, $period);
    $startDate = sprintf('%04d-%02d-%02d', $year, $month, $startDay);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $endDay);
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $context = [
        'punches' => [],
        'holidays' => [],
        'overrides' => [],
        'flex_groups' => [],
        'flex_dtr' => [],
        'in_charge' => [],
        'employees' => [],
    ];

    lv_ensure_employee_roster($conn);
    $stmt = $conn->prepare(
        'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName, Department, Position,
                IsCasual, End_Date, PayYear, FirstGovServiceDate, Status
           FROM ' . _LV_EMP_ROSTER_TABLE . "
          WHERE PIID IN ($placeholders)"
    );
    $stmt->execute($piids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context['employees'][(string)$row['PIID']] = $row;
    }
    $missingEmployeePiids = array_values(array_diff($piids, array_keys($context['employees'])));
    if ($missingEmployeePiids) {
        $missingPlaceholders = implode(',', array_fill(0, count($missingEmployeePiids), '?'));
        $stmt = $conn->prepare(
            "SELECT PIID, ID_NUM, SurName AS Surname, FirstName AS Firstname, MiddleName,
                    '' AS Department, '' AS Position, 0 AS IsCasual,
                    NULL AS End_Date, NULL AS PayYear, NULL AS FirstGovServiceDate, NULL AS Status
               FROM tblpersonalinformation
              WHERE PIID IN ($missingPlaceholders)"
        );
        $stmt->execute($missingEmployeePiids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $context['employees'][(string)$row['PIID']] = $row;
        }

        // Everyone who fell through to tblpersonalinformation has no Department.
        // JO staff are the bulk of that set and do have an office — fill it from
        // the JO directory in one batch lookup rather than per row.
        $joOffices = jo_directory_resolve($conn, $missingEmployeePiids);
        foreach ($joOffices as $joPiid => $joOffice) {
            if (isset($context['employees'][$joPiid])) {
                $context['employees'][$joPiid]['Department'] = $joOffice;
            }
        }
    }

    $stmt = $conn->prepare(
        "SELECT PIID, DTR_Date, AM_In, AM_Out, PM_In, PM_Out, Undertime
           FROM tbldtr
          WHERE PIID IN ($placeholders)
            AND DTR_Date BETWEEN ? AND ?"
    );
    $stmt->execute(array_merge($piids, [$startDate, $endDate]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context['punches'][(string)$row['PIID']][(string)$row['DTR_Date']] = $row;
    }

    $stmt = $conn->prepare(
        'SELECT Event_Date, Name, Event_Duration, Event_Start, Event_End
           FROM ref_events
          WHERE Event_Date BETWEEN ? AND ?'
    );
    $stmt->execute([$startDate, $endDate]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $duration = (string)($row['Event_Duration'] ?? '');
        $context['holidays'][(string)$row['Event_Date']][] = [
            'label' => (string)($row['Name'] ?? ''),
            'whole' => $duration === 'Whole Day',
            'am' => $duration === 'Morning',
            'pm' => $duration === 'Afternoon',
            'start' => $row['Event_Start'] ?: null,
            'end' => $row['Event_End'] ?: null,
        ];
    }

    $stmt = $conn->prepare(
        "SELECT p.PIID, d.DTR_Date, o.Name, o.Override_Type, d.Time_Start, d.Time_End
           FROM tbldtr_override_person p
           STRAIGHT_JOIN tbldtr_override_details d ON d.OID = p.OID
           STRAIGHT_JOIN tbldtr_override o ON o.OID = p.OID
          WHERE p.PIID IN ($placeholders)
            AND d.DTR_Date BETWEEN ? AND ?"
    );
    $stmt->execute(array_merge($piids, [$startDate, $endDate]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = (string)($row['Override_Type'] ?? '');
        $window = dtr_standard_override_window((string)($row['Time_Start'] ?? ''), (string)($row['Time_End'] ?? ''));
        $context['overrides'][(string)$row['PIID']][(string)$row['DTR_Date']][] = [
            'label' => dtr_override_dtr_label($type, (string)($row['Name'] ?? '')),
            'is_tracer_slip' => $type === 'Tracer Slip',
            'whole' => $window === 'whole',
            'am' => $window === 'am',
            'pm' => $window === 'pm',
            'start' => $row['Time_Start'] ?: null,
            'end' => $row['Time_End'] ?: null,
        ];
    }

    $stmt = $conn->prepare(
        "SELECT tp.PIID, g.Name
           FROM tbl_flex_template_name_piid tp
           INNER JOIN tbl_flex_template_group g ON g.FID = tp.FID
          WHERE tp.PIID IN ($placeholders)"
    );
    $stmt->execute($piids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)$row['PIID'];
        if (!array_key_exists($key, $context['flex_groups'])) {
            $context['flex_groups'][$key] = (string)$row['Name'];
        }
    }

    $flexPiids = array_keys($context['flex_groups']);
    if ($flexPiids) {
        $flexPlaceholders = implode(',', array_fill(0, count($flexPiids), '?'));
        $stmt = $conn->prepare(
            "SELECT PIID, DTR_Date, `1_timeIn` AS t1in, `1_timeOut` AS t1out,
                    `2_timeIn` AS t2in, `2_timeOut` AS t2out,
                    `3_timeIn` AS t3in, `3_timeOut` AS t3out, Undertime
               FROM tbl_flex_dtr
              WHERE PIID IN ($flexPlaceholders) AND DTR_Date BETWEEN ? AND ?"
        );
        $stmt->execute(array_merge($flexPiids, [$startDate, $endDate]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $context['flex_dtr'][(string)$row['PIID']][(string)$row['DTR_Date']] = $row;
        }
    }

    $stmt = $conn->prepare(
        "SELECT tp.PIID, td.InCharge
           FROM tbl_templatedtr_piid tp
           INNER JOIN tbl_templatedtr td ON td.TID = tp.TID
          WHERE tp.PIID IN ($placeholders)"
    );
    $stmt->execute($piids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)$row['PIID'];
        if (!array_key_exists($key, $context['in_charge'])) {
            $context['in_charge'][$key] = (string)$row['InCharge'];
        }
    }

    $results = [];
    foreach ($piids as $piid) {
        $results[] = dtr_get_employee_month($conn, $piid, $year, $month, $period, $context);
    }
    return $results;
}

// ============================================================================
// Flex/staggered-schedule groups, templates, and employee assignment.
// Consolidates the 3 duplicate VB tools (frmEmpSchdMngt, frmScheduleTime,
// frmImportExportData) into one canonical implementation. The legacy Assign
// workflow is an XML round-trip; "ReSync Data" controls the optional
// spFlexTime call after a successful import.
//
// None of tbl_flex_template_group.FID / tbl_flex_template_name.flexSchedID are
// DB auto-increment (confirmed via SHOW COLUMNS — no unique/primary key on
// either table at all, a pre-existing schema gap carried over from the VB app,
// not introduced here). IDs are computed client-side (MAX+1) under a MySQL
// named lock to reduce — not eliminate — the race window, matching the level
// of care already used elsewhere in this codebase (see the roster refresh
// GET_LOCK pattern in leave_db.php). Do not treat this as fully race-safe.
// ============================================================================

function dtr_flex_next_id(PDO $conn, string $table, string $column): int {
    $stmt = $conn->query("SELECT COALESCE(MAX(`$column`), 0) + 1 FROM `$table`");
    return (int)$stmt->fetchColumn();
}

function dtr_flex_with_lock(PDO $conn, string $lockName, callable $fn) {
    $lockStmt = $conn->prepare('SELECT GET_LOCK(:lock, 10)');
    $lockStmt->execute([':lock' => $lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire lock — another admin may be editing this right now. Try again.');
    }
    try {
        return $fn();
    } finally {
        $conn->prepare('SELECT RELEASE_LOCK(:lock)')->execute([':lock' => $lockName]);
    }
}

// ---- Groups ----

function dtr_flex_groups_list(PDO $conn): array {
    $rows = $conn->query('SELECT FID, Name, InCharge FROM tbl_flex_template_group ORDER BY Name')->fetchAll();
    $fids = array_column($rows, 'FID');
    $counts = [];
    if ($fids) {
        $placeholders = implode(',', array_fill(0, count($fids), '?'));
        $stmt = $conn->prepare("SELECT FID, COUNT(*) AS c FROM tbl_flex_template_name_piid WHERE FID IN ($placeholders) GROUP BY FID");
        $stmt->execute($fids);
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['FID']] = (int)$row['c'];
        }
    }
    foreach ($rows as &$row) {
        $row['employee_count'] = $counts[$row['FID']] ?? 0;
    }
    unset($row);
    return $rows;
}

function dtr_flex_group_save(PDO $conn, array $data): int {
    $fid = (int)($data['fid'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $inCharge = trim((string)($data['in_charge'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Name is required');
    }

    if ($fid > 0) {
        $stmt = $conn->prepare('UPDATE tbl_flex_template_group SET Name = :name, InCharge = :in_charge WHERE FID = :fid');
        $stmt->execute([':name' => $name, ':in_charge' => $inCharge, ':fid' => $fid]);
        return $fid;
    }

    return dtr_flex_with_lock($conn, 'dtr_flex_group_next_id', function () use ($conn, $name, $inCharge) {
        $fid = dtr_flex_next_id($conn, 'tbl_flex_template_group', 'FID');
        $stmt = $conn->prepare('INSERT INTO tbl_flex_template_group (FID, Name, InCharge) VALUES (:fid, :name, :in_charge)');
        $stmt->execute([':fid' => $fid, ':name' => $name, ':in_charge' => $inCharge]);
        return $fid;
    });
}

function dtr_flex_group_delete(PDO $conn, int $fid): void {
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare('SELECT flexSchedID FROM tbl_flex_template_name WHERE FID = :fid');
        $stmt->execute([':fid' => $fid]);
        $flexIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($flexIds) {
            $placeholders = implode(',', array_fill(0, count($flexIds), '?'));
            $conn->prepare("DELETE FROM tbl_flex_template_name_schedule WHERE flexSchedID IN ($placeholders)")->execute($flexIds);
        }
        $conn->prepare('DELETE FROM tbl_flex_template_name WHERE FID = :fid')->execute([':fid' => $fid]);
        $conn->prepare('DELETE FROM tbl_flex_template_name_piid WHERE FID = :fid')->execute([':fid' => $fid]);
        $conn->prepare('DELETE FROM tbl_flex_template_group WHERE FID = :fid')->execute([':fid' => $fid]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

// ---- Employees attached to a group ----

function dtr_flex_group_employees_get(PDO $conn, int $fid): array {
    $stmt = $conn->prepare(
        "SELECT tp.PIID, pi.SurName, pi.FirstName, pi.MiddleName
         FROM tbl_flex_template_name_piid tp
         LEFT JOIN tblpersonalinformation pi ON pi.PIID = tp.PIID
         WHERE tp.FID = :fid
         ORDER BY pi.SurName, pi.FirstName"
    );
    $stmt->execute([':fid' => $fid]);
    return $stmt->fetchAll();
}

/**
 * Rebuild the plotted Flexitime punches for a group.  The legacy stored
 * procedure remains the authority when it works.  Some production copies of
 * that procedure currently return successfully without writing any of the six
 * Flexi time columns, though, so verify its result and recover from the raw
 * biometric source in that specific case.
 */
function dtr_flex_resync(PDO $conn, string $startDate, string $endDate, int $fid): array {
    $procedureError = null;
    try {
        $stmt = $conn->prepare('CALL spFlexTime(:start_date, :end_date, :fid)');
        $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate, ':fid' => $fid]);
        do { $stmt->closeCursor(); } while ($stmt->nextRowset());
    } catch (Throwable $e) {
        $procedureError = $e;
        error_log('dtr_flex_resync: spFlexTime call failed: ' . $e->getMessage());
    }

    // Always reconcile from the biometric source after the legacy procedure.
    // The procedure can leave only part of a day plotted (for example, the PM
    // pair while valid morning punches remain unassigned).  A row with any
    // value is therefore not proof that the row is complete.
    $rows = $conn->prepare(
        'SELECT f.PIID, f.DTR_Date, f.flexSchedID
         FROM tbl_flex_dtr f
         INNER JOIN tbl_flex_template_name_piid p ON p.PIID = f.PIID AND p.FID = :fid
         WHERE f.DTR_Date BETWEEN :start_date AND :end_date
         ORDER BY f.PIID, f.DTR_Date'
    );
    $rows->execute([':fid' => $fid, ':start_date' => $startDate, ':end_date' => $endDate]);
    $flexRows = $rows->fetchAll();
    if (!$flexRows) return ['status' => $procedureError ? 'failed' : 'completed', 'plotted_rows' => 0, 'fallback_rows' => 0];

    $templates = $conn->prepare(
        'SELECT s.flexSchedID, s.TimeIn, s.TimeOut
         FROM tbl_flex_template_name_schedule s
         INNER JOIN tbl_flex_template_name n ON n.flexSchedID = s.flexSchedID
         WHERE n.FID = :fid
         ORDER BY s.flexSchedID, s.TimeIn, s.TimeOut'
    );
    $templates->execute([':fid' => $fid]);
    $schedule = [];
    foreach ($templates->fetchAll() as $row) $schedule[(int)$row['flexSchedID']][] = $row;

    $bio = $conn->prepare(
        'SELECT PIID, BioTime FROM tbl_rawbiometric
         WHERE PIID IN (SELECT PIID FROM tbl_flex_template_name_piid WHERE FID = :fid)
           AND BioTime >= :start_date AND BioTime < DATE_ADD(:end_date, INTERVAL 2 DAY)
         ORDER BY PIID, BioTime'
    );
    $bio->execute([':fid' => $fid, ':start_date' => $startDate, ':end_date' => $endDate]);
    $punches = [];
    foreach ($bio->fetchAll() as $row) $punches[(string)$row['PIID']][] = (string)$row['BioTime'];

    $update = $conn->prepare(
        'UPDATE tbl_flex_dtr SET `1_timeIn`=?, `1_timeOut`=?, `2_timeIn`=?, `2_timeOut`=?, `3_timeIn`=?, `3_timeOut`=?
         WHERE PIID=? AND DTR_Date=? AND flexSchedID=?'
    );
    $fallbackRows = 0;
    foreach ($flexRows as $flexRow) {
        $pairs = $schedule[(int)$flexRow['flexSchedID']] ?? [];
        $events = $punches[(string)$flexRow['PIID']] ?? [];
        if (!$pairs || !$events) continue;
        $slots = [];
        foreach (array_slice($pairs, 0, 3) as $pairIndex => $pair) {
            $in = strtotime($flexRow['DTR_Date'] . ' ' . $pair['TimeIn']);
            $outDate = $pair['TimeOut'] <= $pair['TimeIn'] ? date('Y-m-d', strtotime($flexRow['DTR_Date'] . ' +1 day')) : $flexRow['DTR_Date'];
            $slots[] = ['key' => $pairIndex * 2, 'time' => $in];
            $slots[] = ['key' => $pairIndex * 2 + 1, 'time' => strtotime($outDate . ' ' . $pair['TimeOut'])];
        }
        $used = []; $values = array_fill(0, 6, '');
        foreach ($slots as $slot) {
            $best = null; $distance = null;
            foreach ($events as $eventIndex => $event) {
                if (isset($used[$eventIndex])) continue;
                $candidate = strtotime($event);
                $gap = abs($candidate - $slot['time']);
                if ($distance === null || $gap < $distance) { $best = $eventIndex; $distance = $gap; }
            }
            // A punch more than 12 hours from a scheduled boundary belongs to
            // another duty day, not this Flexitime row.
            if ($best !== null && $distance <= 43200) {
                $values[$slot['key']] = date('H:i:s', strtotime($events[$best]));
                $used[$best] = true;
            }
        }
        if (array_filter($values, static fn($value) => $value !== '')) {
            $update->execute(array_merge($values, [(string)$flexRow['PIID'], $flexRow['DTR_Date'], (int)$flexRow['flexSchedID']]));
            $fallbackRows++;
        }
    }
    return ['status' => $fallbackRows ? 'fallback_completed' : ($procedureError ? 'failed' : 'completed'), 'plotted_rows' => $fallbackRows, 'fallback_rows' => $fallbackRows];
}

/** Build the legacy Flexitime DTR rows: three arrival/departure pairs per day. */
function dtr_flex_get_employee_months(PDO $conn, array $piids, int $year, int $month, string $period = 'full'): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn($piid): bool => $piid !== '')));
    if (!$piids) return [];
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $placeholders = implode(',', array_fill(0, count($piids), '?'));

    $people = [];
    $stmt = $conn->prepare("SELECT PIID, ID_NUM, SurName, FirstName, MiddleName FROM tblpersonalinformation WHERE PIID IN ($placeholders)");
    $stmt->execute($piids);
    foreach ($stmt->fetchAll() as $row) $people[(string)$row['PIID']] = $row;

    $groups = [];
    $stmt = $conn->prepare(
        "SELECT p.PIID, g.Name, g.InCharge
           FROM tbl_flex_template_name_piid p
           INNER JOIN tbl_flex_template_group g ON g.FID = p.FID
          WHERE p.PIID IN ($placeholders)"
    );
    $stmt->execute($piids);
    foreach ($stmt->fetchAll() as $row) $groups[(string)$row['PIID']] = $row;

    $attendance = [];
    $stmt = $conn->prepare(
        "SELECT PIID, DTR_Date, flexSchedID, `1_timeIn` AS t1in, `1_timeOut` AS t1out,
                `2_timeIn` AS t2in, `2_timeOut` AS t2out,
                `3_timeIn` AS t3in, `3_timeOut` AS t3out, Undertime
           FROM tbl_flex_dtr
          WHERE PIID IN ($placeholders) AND DTR_Date BETWEEN ? AND ?
          ORDER BY PIID, DTR_Date"
    );
    $stmt->execute(array_merge($piids, [$start, $end]));
    foreach ($stmt->fetchAll() as $row) $attendance[(string)$row['PIID']][(string)$row['DTR_Date']] = $row;

    // Generate the report from the authoritative biometric source whenever a
    // saved Flexitime row is incomplete. This keeps old/partly re-synced DTR
    // rows from hiding valid punches in the printed report.
    $scheduleIds = [];
    foreach ($attendance as $employeeDays) foreach ($employeeDays as $row) {
        $id = (int)($row['flexSchedID'] ?? 0);
        if ($id > 0) $scheduleIds[$id] = $id;
    }
    $schedules = [];
    if ($scheduleIds) {
        $schedulePlaceholders = implode(',', array_fill(0, count($scheduleIds), '?'));
        $stmt = $conn->prepare("SELECT flexSchedID, TimeIn, TimeOut FROM tbl_flex_template_name_schedule WHERE flexSchedID IN ($schedulePlaceholders) ORDER BY flexSchedID, TimeIn, TimeOut");
        $stmt->execute(array_values($scheduleIds));
        foreach ($stmt->fetchAll() as $row) $schedules[(int)$row['flexSchedID']][] = $row;
    }
    $punches = [];
    $stmt = $conn->prepare("SELECT PIID, BioTime FROM tbl_rawbiometric WHERE PIID IN ($placeholders) AND BioTime >= ? AND BioTime < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY PIID, BioTime");
    $stmt->execute(array_merge($piids, [$start, $end]));
    foreach ($stmt->fetchAll() as $row) {
        $punches[(string)$row['PIID']][substr((string)$row['BioTime'], 0, 10)][] = (string)$row['BioTime'];
    }
    $plotPunches = static function (string $date, array $pairs, array $events): array {
        $values = array_fill(0, 6, '');
        $slots = [];
        foreach (array_slice($pairs, 0, 3) as $pairIndex => $pair) {
            foreach (['TimeIn', 'TimeOut'] as $offset => $field) {
                $time = trim((string)($pair[$field] ?? ''));
                if ($time === '') continue;
                $slots[] = ['key' => $pairIndex * 2 + $offset, 'time' => strtotime($date . ' ' . $time)];
            }
        }
        // Match in chronological order. A greedy nearest-time match can cross
        // lunch (for example, assigning a 12:50 punch to the noon boundary
        // before considering the 11:02 departure). This dynamic match keeps
        // the sequence intact while still allowing missing punches.
        $slotCount = count($slots); $eventCount = count($events);
        $dp = array_fill(0, $slotCount + 1, array_fill(0, $eventCount + 1, null));
        $dp[0][0] = ['matched' => 0, 'cost' => 0, 'pairs' => []];
        $better = static function (?array $candidate, ?array $current): ?array {
            if ($candidate === null) return $current;
            if ($current === null || $candidate['matched'] > $current['matched'] || ($candidate['matched'] === $current['matched'] && $candidate['cost'] < $current['cost'])) return $candidate;
            return $current;
        };
        for ($slotIndex = 0; $slotIndex <= $slotCount; $slotIndex++) for ($eventIndex = 0; $eventIndex <= $eventCount; $eventIndex++) {
            $state = $dp[$slotIndex][$eventIndex];
            if ($state === null) continue;
            if ($slotIndex < $slotCount) $dp[$slotIndex + 1][$eventIndex] = $better($state, $dp[$slotIndex + 1][$eventIndex]);
            if ($eventIndex < $eventCount) $dp[$slotIndex][$eventIndex + 1] = $better($state, $dp[$slotIndex][$eventIndex + 1]);
            if ($slotIndex < $slotCount && $eventIndex < $eventCount) {
                $gap = abs(strtotime($events[$eventIndex]) - $slots[$slotIndex]['time']);
                if ($gap <= 43200) {
                    $candidate = $state;
                    $candidate['matched']++;
                    $candidate['cost'] += $gap;
                    $candidate['pairs'][] = [$slots[$slotIndex]['key'], $eventIndex];
                    $dp[$slotIndex + 1][$eventIndex + 1] = $better($candidate, $dp[$slotIndex + 1][$eventIndex + 1]);
                }
            }
        }
        foreach (($dp[$slotCount][$eventCount]['pairs'] ?? []) as [$slotKey, $eventIndex]) $values[$slotKey] = date('H:i:s', strtotime($events[$eventIndex]));
        return $values;
    };

    [$selectedStart, $selectedEnd] = dtr_period_range($year, $month, $period);
    $lastDay = (int)date('t', strtotime($start));
    $records = [];
    foreach ($piids as $piid) {
        $days = [];
        for ($day = 1; $day <= $lastDay; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $row = ($day >= $selectedStart && $day <= $selectedEnd) ? ($attendance[$piid][$date] ?? []) : [];
            $scheduleId = (int)($row['flexSchedID'] ?? 0);
            $derived = $plotPunches($date, $schedules[$scheduleId] ?? [], $punches[$piid][$date] ?? []);
            $storedCount = count(array_filter([$row['t1in'] ?? '', $row['t1out'] ?? '', $row['t2in'] ?? '', $row['t2out'] ?? '', $row['t3in'] ?? '', $row['t3out'] ?? ''], static fn($value): bool => $value !== ''));
            $derivedCount = count(array_filter($derived, static fn($value): bool => $value !== ''));
            if ($derivedCount > $storedCount) {
                foreach (['t1in', 't1out', 't2in', 't2out', 't3in', 't3out'] as $index => $field) $row[$field] = $derived[$index];
            }
            $days[] = ['day' => $day, 'date' => $date, 'dow' => date('D', strtotime($date))] + $row;
        }
        $group = $groups[$piid] ?? [];
        $records[] = [
            'employee' => $people[$piid] ?? [], 'group' => $group['Name'] ?? '',
            'in_charge' => $group['InCharge'] ?? '', 'days' => $days, 'period' => $period,
        ];
    }
    return $records;
}

function dtr_flex_get_employee_month(PDO $conn, string $piid, int $year, int $month, string $period = 'full'): array {
    $person = $conn->prepare('SELECT PIID, ID_NUM, SurName, FirstName, MiddleName FROM tblpersonalinformation WHERE PIID = ? LIMIT 1');
    $person->execute([$piid]);
    $employee = $person->fetch() ?: [];
    $group = $conn->prepare('SELECT g.Name, g.InCharge FROM tbl_flex_template_name_piid p INNER JOIN tbl_flex_template_group g ON g.FID=p.FID WHERE p.PIID=? LIMIT 1');
    $group->execute([$piid]);
    $groupRow = $group->fetch() ?: [];
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $conn->prepare('SELECT DTR_Date, `1_timeIn` AS t1in, `1_timeOut` AS t1out, `2_timeIn` AS t2in, `2_timeOut` AS t2out, `3_timeIn` AS t3in, `3_timeOut` AS t3out, Undertime FROM tbl_flex_dtr WHERE PIID=? AND DTR_Date BETWEEN ? AND ? ORDER BY DTR_Date');
    $stmt->execute([$piid, $start, $end]);
    $byDate = [];
    foreach ($stmt->fetchAll() as $row) $byDate[$row['DTR_Date']] = $row;
    $days = [];
    // Keep the statutory DTR form as a full monthly grid.  A selected half-month
    // controls which attendance entries are populated, not which calendar rows exist.
    [$selectedStart, $selectedEnd] = dtr_period_range($year, $month, $period);
    $lastDay = (int)date('t', strtotime($start));
    for ($day = 1; $day <= $lastDay; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $row = ($day >= $selectedStart && $day <= $selectedEnd) ? ($byDate[$date] ?? []) : [];
        $days[] = ['day' => $day, 'date' => $date, 'dow' => date('D', strtotime($date))] + $row;
    }
    return ['employee' => $employee, 'group' => $groupRow['Name'] ?? '', 'in_charge' => $groupRow['InCharge'] ?? '', 'days' => $days, 'period' => $period];
}

function dtr_flex_group_employees_save(PDO $conn, int $fid, array $piids): void {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), fn($v) => $v !== '')));
    $conn->beginTransaction();
    try {
        $conn->prepare('DELETE FROM tbl_flex_template_name_piid WHERE FID = :fid')->execute([':fid' => $fid]);
        if ($piids) {
            $placeholders = implode(',', array_fill(0, count($piids), '(?, ?)'));
            $params = [];
            foreach ($piids as $piid) {
                $params[] = $fid;
                $params[] = $piid;
            }
            $conn->prepare("INSERT INTO tbl_flex_template_name_piid (FID, PIID) VALUES $placeholders")->execute($params);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

// ---- Templates (a named schedule with one or more time-in/out pairs) ----

function dtr_flex_templates_list(PDO $conn, int $fid): array {
    $stmt = $conn->prepare('SELECT flexSchedID, FID, templateName FROM tbl_flex_template_name WHERE FID = :fid ORDER BY templateName');
    $stmt->execute([':fid' => $fid]);
    $rows = $stmt->fetchAll();

    $ids = array_column($rows, 'flexSchedID');
    $pairs = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT flexSchedID, TimeIn, TimeOut FROM tbl_flex_template_name_schedule WHERE flexSchedID IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $pairs[$row['flexSchedID']][] = ['time_in' => $row['TimeIn'], 'time_out' => $row['TimeOut']];
        }
    }
    foreach ($rows as &$row) {
        $row['schedule'] = $pairs[$row['flexSchedID']] ?? [];
    }
    unset($row);
    return $rows;
}

/** Legacy bin.xml-style configuration export for the offline Flexi Scheduler. */
function dtr_flex_template_export_xml(PDO $conn, int $fid): string {
    $group = $conn->prepare('SELECT FID, Name, InCharge FROM tbl_flex_template_group WHERE FID = ? LIMIT 1');
    $group->execute([$fid]); $group = $group->fetch();
    if (!$group) throw new InvalidArgumentException('Schedule group not found');
    $employees = dtr_flex_group_employees_get($conn, $fid);
    $templates = dtr_flex_templates_list($conn, $fid);
    $dom = new DOMDocument('1.0', 'UTF-8'); $dom->formatOutput = true;
    $root = $dom->appendChild($dom->createElement('Department'));
    $root->setAttribute('FID', (string)$group['FID']);
    $root->setAttribute('DepartmentName', (string)$group['Name']);
    $root->setAttribute('InCharge', (string)$group['InCharge']);
    $listEmployees = $root->appendChild($dom->createElement('ListEmployees'));
    foreach ($employees as $employee) {
        $node = $listEmployees->appendChild($dom->createElement('Employees'));
        $node->setAttribute('PIID', (string)$employee['PIID']);
        $node->setAttribute('Name', trim(($employee['FirstName'] ?? '') . ' ' . ($employee['MiddleName'] ?? '') . ' ' . ($employee['SurName'] ?? '')));
    }
    $listTemplates = $root->appendChild($dom->createElement('ListTemplates'));
    foreach ($templates as $template) {
        $node = $listTemplates->appendChild($dom->createElement('Template'));
        $node->setAttribute('flexSchedID', (string)$template['flexSchedID']);
        $node->setAttribute('Template', (string)$template['templateName']);
        $node->setAttribute('TimeIn', implode(',', array_column($template['schedule'] ?? [], 'time_in')));
        $node->setAttribute('TimeOut', implode(',', array_column($template['schedule'] ?? [], 'time_out')));
    }
    return $dom->saveXML();
}

/** Return an employee's template assignment for every day in one month. */
function dtr_flex_schedule_month(PDO $conn, int $fid, string $piid, int $year, int $month): array {
    if ($fid <= 0 || $piid === '' || $month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Invalid scheduler request');
    }
    $member = $conn->prepare('SELECT 1 FROM tbl_flex_template_name_piid WHERE FID = ? AND PIID = ? LIMIT 1');
    $member->execute([$fid, $piid]);
    if (!$member->fetchColumn()) throw new InvalidArgumentException('Employee is not a member of this flex group');
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $conn->prepare('SELECT DTR_Date, flexSchedID FROM tbl_flex_dtr WHERE PIID = ? AND DTR_Date BETWEEN ? AND ?');
    $stmt->execute([$piid, $start, $end]);
    $assignments = [];
    foreach ($stmt->fetchAll() as $row) $assignments[$row['DTR_Date']] = (int)$row['flexSchedID'];
    return ['assignments' => $assignments, 'templates' => dtr_flex_templates_list($conn, $fid)];
}

/** Replace one employee's assignments for a calendar month, then rebuild flex DTR times. */
function dtr_flex_schedule_month_save(PDO $conn, int $fid, string $piid, int $year, int $month, array $assignments, string $actor): int {
    $data = dtr_flex_schedule_month($conn, $fid, $piid, $year, $month);
    $allowed = array_flip(array_map(fn($template) => (int)$template['flexSchedID'], $data['templates']));
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $clean = [];
    foreach ($assignments as $date => $templateId) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) || $date < $start || $date > $end) continue;
        $templateId = (int)$templateId;
        if ($templateId && !isset($allowed[$templateId])) throw new InvalidArgumentException('A selected template does not belong to this group');
        if ($templateId) $clean[$date] = $templateId;
    }
    $conn->beginTransaction();
    try {
        $conn->prepare('DELETE FROM tbl_flex_dtr WHERE PIID = ? AND DTR_Date BETWEEN ? AND ?')->execute([$piid, $start, $end]);
        $insert = $conn->prepare('INSERT INTO tbl_flex_dtr (PIID, DTR_Date, flexSchedID) VALUES (?, ?, ?)');
        foreach ($clean as $date => $templateId) $insert->execute([$piid, $date, $templateId]);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollBack(); throw $e; }
    dtr_flex_resync($conn, $start, $end, $fid);
    try {
        $stmt = $conn->prepare('INSERT INTO tbl_flex_dtr_history (FID, DtTemplate, Dtuploaded, uploadedBy) VALUES (?, ?, NOW(), ?)');
        $stmt->execute([$fid, date('M, Y', strtotime($start)), substr($actor, 0, 25)]);
    } catch (Throwable $e) { error_log('dtr_flex_schedule_month_save history: ' . $e->getMessage()); }
    return count($clean);
}

function dtr_flex_template_save(PDO $conn, array $data): int {
    $flexSchedID = (int)($data['flex_sched_id'] ?? 0);
    $fid = (int)($data['fid'] ?? 0);
    $name = trim((string)($data['template_name'] ?? ''));
    $pairs = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];

    if ($fid <= 0) {
        throw new InvalidArgumentException('fid is required');
    }
    if ($name === '') {
        throw new InvalidArgumentException('Template name is required');
    }
    if (!$pairs) {
        throw new InvalidArgumentException('At least one time-in/time-out pair is required');
    }

    if ($flexSchedID > 0) {
        $stmt = $conn->prepare('UPDATE tbl_flex_template_name SET templateName = :name WHERE flexSchedID = :id');
        $stmt->execute([':name' => $name, ':id' => $flexSchedID]);
    } else {
        $flexSchedID = dtr_flex_with_lock($conn, 'dtr_flex_template_next_id', function () use ($conn, $fid, $name) {
            $id = dtr_flex_next_id($conn, 'tbl_flex_template_name', 'flexSchedID');
            $stmt = $conn->prepare('INSERT INTO tbl_flex_template_name (flexSchedID, FID, templateName) VALUES (:id, :fid, :name)');
            $stmt->execute([':id' => $id, ':fid' => $fid, ':name' => $name]);
            return $id;
        });
    }

    $conn->prepare('DELETE FROM tbl_flex_template_name_schedule WHERE flexSchedID = :id')->execute([':id' => $flexSchedID]);
    $insert = $conn->prepare('INSERT INTO tbl_flex_template_name_schedule (flexSchedID, TimeIn, TimeOut) VALUES (:id, :time_in, :time_out)');
    foreach ($pairs as $pair) {
        $timeIn = trim((string)($pair['time_in'] ?? ''));
        $timeOut = trim((string)($pair['time_out'] ?? ''));
        if ($timeIn === '' && $timeOut === '') {
            continue;
        }
        $insert->execute([':id' => $flexSchedID, ':time_in' => $timeIn, ':time_out' => $timeOut]);
    }

    return $flexSchedID;
}

function dtr_flex_template_delete(PDO $conn, int $flexSchedID): void {
    $conn->beginTransaction();
    try {
        $conn->prepare('DELETE FROM tbl_flex_template_name_schedule WHERE flexSchedID = :id')->execute([':id' => $flexSchedID]);
        $conn->prepare('DELETE FROM tbl_flex_template_name WHERE flexSchedID = :id')->execute([':id' => $flexSchedID]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

// ---- Day-by-day assignment (tbl_flex_dtr) + history ----

/**
 * Assign a template to one or more employees across a date range (inclusive).
 * Delete-before-insert per employee/range (frmImportExportData's pattern),
 * then CALL spFlexTime(start, end, FID) unconditionally, then log to
 * tbl_flex_dtr_history. spFlexTime's own body isn't inspectable with this
 * app's DB privileges (SHOW CREATE PROCEDURE returns empty) — it's called as
 * a black box, matching what the VB app already did. If it fails, the
 * assignment itself has still been committed; only the recompute step is
 * skipped and logged.
 */
function dtr_flex_assign_bulk(PDO $conn, int $fid, array $piids, int $flexSchedID, string $startDate, string $endDate, string $actor): int {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), fn($v) => $v !== '')));
    if (!$piids) {
        throw new InvalidArgumentException('At least one employee is required');
    }
    if ($flexSchedID <= 0) {
        throw new InvalidArgumentException('Template is required');
    }
    if (strtotime($startDate) === false || strtotime($endDate) === false || $startDate > $endDate) {
        throw new InvalidArgumentException('Invalid date range');
    }

    $conn->beginTransaction();
    try {
        $deleteStmt = $conn->prepare('DELETE FROM tbl_flex_dtr WHERE PIID = :piid AND DTR_Date BETWEEN :start_date AND :end_date');
        $insertStmt = $conn->prepare('INSERT INTO tbl_flex_dtr (PIID, DTR_Date, flexSchedID) VALUES (:piid, :date, :flex_sched_id)');
        $inserted = 0;
        foreach ($piids as $piid) {
            $deleteStmt->execute([':piid' => $piid, ':start_date' => $startDate, ':end_date' => $endDate]);
            $cursor = new DateTime($startDate);
            $end = (new DateTime($endDate))->modify('+1 day');
            while ($cursor < $end) {
                $insertStmt->execute([':piid' => $piid, ':date' => $cursor->format('Y-m-d'), ':flex_sched_id' => $flexSchedID]);
                $inserted++;
                $cursor->modify('+1 day');
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    dtr_flex_resync($conn, $startDate, $endDate, $fid);

    try {
        $stmt = $conn->prepare(
            'INSERT INTO tbl_flex_dtr_history (FID, DtTemplate, Dtuploaded, uploadedBy) VALUES (:fid, :template, NOW(), :actor)'
        );
        $stmt->execute([
            ':fid' => $fid,
            ':template' => date('M, Y', strtotime($startDate)),
            ':actor' => substr($actor, 0, 25),
        ]);
    } catch (Throwable $e) {
        // tbl_flex_dtr_history.FID is only tinyint(3) unsigned (max 255) — a
        // pre-existing schema limit. Don't fail the whole assignment over a
        // history-log row; just record it server-side for follow-up.
        error_log('dtr_flex_assign_bulk: history log insert failed: ' . $e->getMessage());
    }

    return $inserted;
}

/**
 * Import the XML produced by the legacy Flex Schedule desktop tool.
 *
 * Expected shape:
 * Department[FID] / ListEmployees / Employees[PIID] / DTR
 * with PIID, flexSchedID and Date attributes on every DTR node. A zero
 * flexSchedID means that date has no flex assignment. As in
 * frmImportExportData, the imported employee list replaces the group's
 * membership and the imported month replaces each included employee's rows.
 * Re-sync is deliberately optional, matching frmScheduleTime.chkSync.
 */
function dtr_flex_import_xml(PDO $conn, string $xmlText, bool $resync, string $actor): array {
    if (trim($xmlText) === '') {
        throw new InvalidArgumentException('The XML file is empty');
    }
    if (stripos($xmlText, '<!DOCTYPE') !== false || stripos($xmlText, '<!ENTITY') !== false) {
        throw new InvalidArgumentException('XML files with document types or external entities are not allowed');
    }

    $previousLibxml = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xmlText, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxml);
    if (!$loaded || !$document->documentElement || $document->documentElement->tagName !== 'Department') {
        $detail = $xmlErrors ? trim((string)$xmlErrors[0]->message) : '';
        throw new InvalidArgumentException('Invalid flex schedule XML file' . ($detail !== '' ? ': ' . $detail : ''));
    }

    $root = $document->documentElement;
    $fidRaw = trim($root->getAttribute('FID'));
    if ($fidRaw === '' || !ctype_digit($fidRaw) || (int)$fidRaw <= 0) {
        throw new InvalidArgumentException('The XML file does not contain a valid department FID');
    }
    $fid = (int)$fidRaw;

    $groupStmt = $conn->prepare('SELECT Name FROM tbl_flex_template_group WHERE FID = :fid LIMIT 1');
    $groupStmt->execute([':fid' => $fid]);
    $groupName = $groupStmt->fetchColumn();
    if ($groupName === false) {
        throw new InvalidArgumentException("The XML department (FID $fid) does not exist in Flex Schedules");
    }

    $xpath = new DOMXPath($document);
    $employeeNodes = $xpath->query('/Department/ListEmployees/Employees');
    if (!$employeeNodes || $employeeNodes->length === 0) {
        throw new InvalidArgumentException('The XML file has no employees to import');
    }
    if ($employeeNodes->length > 2000) {
        throw new InvalidArgumentException('The XML file contains too many employees');
    }

    $piids = [];
    $assignments = [];
    $seenDates = [];
    $periodKey = '';
    $periodStart = '';
    $periodEnd = '';

    foreach ($employeeNodes as $employeeNode) {
        if (!$employeeNode instanceof DOMElement) {
            continue;
        }
        $piid = trim($employeeNode->getAttribute('PIID'));
        if ($piid === '' || !ctype_digit($piid)) {
            throw new InvalidArgumentException('An employee in the XML file has an invalid PIID');
        }
        if (isset($piids[$piid])) {
            throw new InvalidArgumentException("Employee PIID $piid appears more than once in the XML file");
        }
        $piids[$piid] = true;

        $dtrCount = 0;
        foreach ($employeeNode->childNodes as $dtrNode) {
            if (!$dtrNode instanceof DOMElement || $dtrNode->tagName !== 'DTR') {
                continue;
            }
            $dtrCount++;
            $rowPiid = trim($dtrNode->getAttribute('PIID'));
            if ($rowPiid !== '' && $rowPiid !== $piid) {
                throw new InvalidArgumentException("A DTR row under PIID $piid belongs to another employee");
            }

            $dateRaw = trim($dtrNode->getAttribute('Date'));
            if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateRaw, $dateParts)) {
                throw new InvalidArgumentException("Invalid DTR date '$dateRaw' for PIID $piid");
            }
            $year = (int)$dateParts[1];
            $month = (int)$dateParts[2];
            $day = (int)$dateParts[3];
            if (!checkdate($month, $day, $year)) {
                // The desktop tool always generated days 1-31. Ignore only its
                // known overflow days (e.g. February 30), as the VB importer did.
                if ($day >= 29 && $day <= 31 && $month >= 1 && $month <= 12 && $year >= 2000 && $year <= 2100) {
                    continue;
                }
                throw new InvalidArgumentException("Invalid DTR date '$dateRaw' for PIID $piid");
            }
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $rowPeriodKey = sprintf('%04d-%02d', $year, $month);
            if ($periodKey === '') {
                $periodKey = $rowPeriodKey;
                $periodStart = $rowPeriodKey . '-01';
                $periodEnd = date('Y-m-t', strtotime($periodStart));
            } elseif ($periodKey !== $rowPeriodKey) {
                throw new InvalidArgumentException('All DTR rows in the XML file must belong to one month');
            }
            $dateKey = $piid . '|' . $date;
            if (isset($seenDates[$dateKey])) {
                throw new InvalidArgumentException("Duplicate DTR date $date for PIID $piid");
            }
            $seenDates[$dateKey] = true;

            $flexRaw = trim($dtrNode->getAttribute('flexSchedID'));
            if ($flexRaw === '') {
                $flexRaw = '0';
            }
            if (!ctype_digit($flexRaw)) {
                throw new InvalidArgumentException("Invalid flex schedule ID '$flexRaw' for PIID $piid");
            }
            $flexSchedID = (int)$flexRaw;
            if ($flexSchedID > 0) {
                $assignments[] = [
                    'piid' => $piid,
                    'date' => $date,
                    'flex_sched_id' => $flexSchedID,
                ];
            }
        }
        if ($dtrCount === 0) {
            throw new InvalidArgumentException("Employee PIID $piid has no DTR rows in the XML file");
        }
    }

    if ($periodKey === '') {
        throw new InvalidArgumentException('The XML file has no valid DTR dates');
    }

    $piidList = array_keys($piids);
    $placeholders = implode(',', array_fill(0, count($piidList), '?'));
    $employeeCheck = $conn->prepare("SELECT PIID FROM tblpersonalinformation WHERE PIID IN ($placeholders)");
    $employeeCheck->execute($piidList);
    $existingPiids = array_map('strval', $employeeCheck->fetchAll(PDO::FETCH_COLUMN));
    $missingPiids = array_values(array_diff($piidList, $existingPiids));
    if ($missingPiids) {
        $preview = implode(', ', array_slice($missingPiids, 0, 8));
        if (count($missingPiids) > 8) {
            $preview .= ', ...';
        }
        throw new InvalidArgumentException("Employee PIID(s) not found: $preview");
    }

    $usedTemplateIds = array_values(array_unique(array_column($assignments, 'flex_sched_id')));
    if ($usedTemplateIds) {
        $templatePlaceholders = implode(',', array_fill(0, count($usedTemplateIds), '?'));
        $templateCheck = $conn->prepare(
            "SELECT flexSchedID FROM tbl_flex_template_name WHERE FID = ? AND flexSchedID IN ($templatePlaceholders)"
        );
        $templateCheck->execute(array_merge([$fid], $usedTemplateIds));
        $validTemplateIds = array_map('intval', $templateCheck->fetchAll(PDO::FETCH_COLUMN));
        $missingTemplateIds = array_values(array_diff($usedTemplateIds, $validTemplateIds));
        if ($missingTemplateIds) {
            throw new InvalidArgumentException(
                'The XML uses schedule template ID(s) that do not belong to this group: ' . implode(', ', $missingTemplateIds)
            );
        }
    }

    $conn->beginTransaction();
    try {
        $conn->prepare('DELETE FROM tbl_flex_template_name_piid WHERE FID = :fid')->execute([':fid' => $fid]);
        $memberInsert = $conn->prepare('INSERT INTO tbl_flex_template_name_piid (FID, PIID) VALUES (:fid, :piid)');
        $monthDelete = $conn->prepare(
            'DELETE FROM tbl_flex_dtr WHERE PIID = :piid AND DTR_Date BETWEEN :period_start AND :period_end'
        );
        foreach ($piidList as $piid) {
            $memberInsert->execute([':fid' => $fid, ':piid' => $piid]);
            $monthDelete->execute([
                ':piid' => $piid,
                ':period_start' => $periodStart,
                ':period_end' => $periodEnd,
            ]);
        }

        $assignmentInsert = $conn->prepare(
            'INSERT INTO tbl_flex_dtr (PIID, DTR_Date, flexSchedID) VALUES (:piid, :date, :flex_sched_id)'
        );
        foreach ($assignments as $assignment) {
            $assignmentInsert->execute([
                ':piid' => $assignment['piid'],
                ':date' => $assignment['date'],
                ':flex_sched_id' => $assignment['flex_sched_id'],
            ]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    $resyncStatus = $resync ? 'completed' : 'not_requested';
    $warning = '';
    if ($resync) {
        $sync = dtr_flex_resync($conn, $periodStart, $periodEnd, $fid);
        $resyncStatus = $sync['status'];
        if ($resyncStatus === 'failed') {
            $warning = 'Assignments were imported, but DTR re-sync could not be completed.';
        } elseif ($resyncStatus === 'completed' && (int)$sync['plotted_rows'] === 0) {
            $warning = 'Assignments were imported, but no biometric punches were available to plot.';
        }
    }

    try {
        $historyStmt = $conn->prepare(
            'INSERT INTO tbl_flex_dtr_history (FID, DtTemplate, Dtuploaded, uploadedBy) VALUES (:fid, :template, NOW(), :actor)'
        );
        $historyStmt->execute([
            ':fid' => $fid,
            ':template' => date('F, Y', strtotime($periodStart)),
            ':actor' => substr($actor, 0, 25),
        ]);
    } catch (Throwable $e) {
        error_log('dtr_flex_import_xml: history log insert failed: ' . $e->getMessage());
    }

    return [
        'fid' => $fid,
        'group_name' => (string)$groupName,
        'period' => date('F Y', strtotime($periodStart)),
        'employees' => count($piidList),
        'assignments' => count($assignments),
        'resync_status' => $resyncStatus,
        'warning' => $warning,
    ];
}

function dtr_flex_history_list(PDO $conn, int $fid, int $year = 0): array {
    $where = ['h.FID = :fid'];
    $params = [':fid' => $fid];
    if ($year > 0) {
        $where[] = 'h.DtTemplate LIKE :year_like';
        $params[':year_like'] = "%$year";
    }
    $whereSql = implode(' AND ', $where);
    $stmt = $conn->prepare(
        "SELECT h.FID, h.DtTemplate, h.Dtuploaded, h.uploadedBy, g.Name AS group_name
         FROM tbl_flex_dtr_history h
         LEFT JOIN tbl_flex_template_group g ON g.FID = h.FID
         WHERE $whereSql
         ORDER BY h.Dtuploaded DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

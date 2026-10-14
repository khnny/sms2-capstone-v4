<?php
/**
 * Defense rescheduling and schedule history (To-Do P1.4, CRAD-owned).
 *
 * A reschedule changes the date, time, and venue of an existing defense schedule. It refuses:
 * - a venue that another active schedule already uses at that time,
 * - a research group that already has another active schedule at that time,
 * - an end time not after the start time, a start time in the past, or a missing reason.
 * Every reschedule writes one history row with the old and new values, the reason, and who changed it.
 * Schedules are never deleted here.
 */

const CRAD_DEFENSE_HISTORY_TABLE = 'crad_research_defense_schedule_history';

function cradEnsureDefenseScheduleHistory(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `" . CRAD_DEFENSE_HISTORY_TABLE . "` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            schedule_id INT UNSIGNED NOT NULL,
            research_group_id INT UNSIGNED DEFAULT NULL,
            group_number VARCHAR(60) DEFAULT NULL,
            defense_type VARCHAR(60) DEFAULT NULL,
            old_datetime DATETIME DEFAULT NULL,
            old_end_datetime DATETIME DEFAULT NULL,
            old_venue VARCHAR(160) DEFAULT NULL,
            new_datetime DATETIME NOT NULL,
            new_end_datetime DATETIME NOT NULL,
            new_venue VARCHAR(160) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            changed_by_user_id INT UNSIGNED DEFAULT NULL,
            changed_by_name VARCHAR(160) DEFAULT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_defense_history_schedule (schedule_id),
            KEY idx_defense_history_group (research_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Active schedules only: rejected and cancelled ones do not hold a time. */
function cradDefenseActiveStatusSql(string $alias = ''): string
{
    $col = $alias !== '' ? $alias . '.status' : 'status';
    return "LOWER(COALESCE({$col}, '')) NOT IN ('rejected', 'cancelled', 'canceled')";
}


/**
 * Panelists on a schedule who cannot attend the new slot (P1.4: check panelist availability).
 * Same rule as the Research Director scheduling: when a panelist has time windows saved, one window for that
 * weekday must cover the slot. Status "Unavailable" always blocks. A panelist with no availability record
 * is not blocked, because the system has nothing to check against.
 * @return list<string>
 */
function cradPanelsUnavailableForSlot(PDO $pdo, string $panelMembers, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $names = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $panelMembers) ?: [])));
    $day = $start->format('l');
    $startTime = $start->format('H:i');
    $endTime = $end->format('H:i');
    $blocked = [];
    foreach ($names as $name) {
        $stmt = $pdo->prepare(
            "SELECT pma.availability_status, pma.availability_windows_json
               FROM sms2_users u
               JOIN `crad_panel_member_availability` pma ON pma.panel_user_id = u.id
              WHERE u.full_name = ? LIMIT 1"
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if (!$row) {
            continue;
        }
        if (strtolower((string) $row['availability_status']) === 'unavailable') {
            $blocked[] = $name;
            continue;
        }
        $raw = trim((string) ($row['availability_windows_json'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $windows = json_decode($raw, true);
        $fits = false;
        if (is_array($windows) && isset($windows[$day]) && is_array($windows[$day])) {
            foreach ($windows[$day] as $window) {
                if (!is_array($window)) {
                    continue;
                }
                $ws = (string) ($window['start'] ?? '');
                $we = (string) ($window['end'] ?? '');
                if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $ws) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $we)
                    && $we > $ws && $ws <= $startTime && $we >= $endTime) {
                    $fits = true;
                    break;
                }
            }
        }
        if (!$fits) {
            $blocked[] = $name;
        }
    }
    return array_values(array_unique($blocked));
}

/** @return array{ok: bool, error?: string, message?: string} */
function cradRescheduleDefense(PDO $pdo, array $input, int $actorId, string $actorName): array
{
    cradEnsureDefenseScheduleHistory($pdo);
    $scheduleId = (int) ($input['schedule_id'] ?? 0);
    $date = trim((string) ($input['new_date'] ?? ''));
    $startText = trim((string) ($input['new_start'] ?? ''));
    $endText = trim((string) ($input['new_end'] ?? ''));
    $venue = trim((string) ($input['new_venue'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? ''));

    if ($scheduleId <= 0) {
        return ['ok' => false, 'error' => 'Choose the defense schedule to reschedule.'];
    }
    if ($reason === '' || mb_strlen($reason) < 5) {
        return ['ok' => false, 'error' => 'Write the reason for the reschedule (at least 5 characters).'];
    }
    if ($venue === '' || mb_strlen($venue) > 160) {
        return ['ok' => false, 'error' => 'Enter the venue (up to 160 characters).'];
    }
    $tz = new DateTimeZone('Asia/Manila');
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $startText, $tz);
    $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $endText, $tz);
    if (!$start || !$end || $start->format('Y-m-d H:i') !== $date . ' ' . $startText || $end->format('Y-m-d H:i') !== $date . ' ' . $endText) {
        return ['ok' => false, 'error' => 'Enter a valid date and start and end times.'];
    }
    if ($end <= $start) {
        return ['ok' => false, 'error' => 'The end time must be after the start time.'];
    }
    if ($start <= new DateTimeImmutable('now', $tz)) {
        return ['ok' => false, 'error' => 'The new defense time must be in the future.'];
    }
    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "SELECT id, research_group_id, group_number, defense_type, defense_datetime, defense_end_datetime, venue, status, panel_members
           FROM `crad_research_defense_schedules` WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$scheduleId]);
    $current = $stmt->fetch();
    if (!$current) {
        return ['ok' => false, 'error' => 'That defense schedule no longer exists.'];
    }
    if (in_array(strtolower((string) $current['status']), ['completed', 'rejected', 'cancelled', 'canceled'], true)) {
        return ['ok' => false, 'error' => 'A ' . strtolower((string) $current['status']) . ' defense cannot be rescheduled.'];
    }

    // Overlap: another active schedule's [start, end) crosses the new [start, end).
    $overlap = "(
            COALESCE(s.defense_datetime, '1970-01-01') < :new_end
            AND COALESCE(s.defense_end_datetime, DATE_ADD(s.defense_datetime, INTERVAL 2 HOUR)) > :new_start
        )";
    $venueConflict = $pdo->prepare(
        "SELECT s.group_number, s.defense_datetime FROM `crad_research_defense_schedules` s
          WHERE s.id <> :self AND " . cradDefenseActiveStatusSql('s') . "
            AND LOWER(TRIM(s.venue)) = LOWER(TRIM(:venue)) AND {$overlap}
          LIMIT 1"
    );
    $venueConflict->execute([':self' => $scheduleId, ':venue' => $venue, ':new_start' => $startSql, ':new_end' => $endSql]);
    if ($row = $venueConflict->fetch()) {
        return ['ok' => false, 'error' => 'That venue is already booked at this time by ' . ($row['group_number'] ?: 'another defense') . '.'];
    }

    $groupConflict = $pdo->prepare(
        "SELECT s.group_number FROM `crad_research_defense_schedules` s
          WHERE s.id <> :self AND " . cradDefenseActiveStatusSql('s') . "
            AND s.research_group_id = :group AND {$overlap}
          LIMIT 1"
    );
    $groupConflict->execute([':self' => $scheduleId, ':group' => (int) $current['research_group_id'], ':new_start' => $startSql, ':new_end' => $endSql]);
    if ($groupConflict->fetch()) {
        return ['ok' => false, 'error' => 'This research group already has another defense at that time.'];
    }

    $unavailable = cradPanelsUnavailableForSlot($pdo, (string) ($current['panel_members'] ?? ''), $start, $end);
    if ($unavailable !== []) {
        return ['ok' => false, 'error' => 'These panelists are not available at that time: ' . implode(', ', $unavailable) . '.'];
    }

    $oldEnd = $current['defense_end_datetime'] ?: null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE `crad_research_defense_schedules`
                SET defense_datetime = ?, defense_end_datetime = ?, venue = ?, updated_at = NOW()
              WHERE id = ?"
        )->execute([$startSql, $endSql, $venue, $scheduleId]);
        $pdo->prepare(
            "INSERT INTO `" . CRAD_DEFENSE_HISTORY_TABLE . "`
                (schedule_id, research_group_id, group_number, defense_type,
                 old_datetime, old_end_datetime, old_venue, new_datetime, new_end_datetime, new_venue,
                 reason, changed_by_user_id, changed_by_name, changed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $scheduleId,
            (int) $current['research_group_id'],
            (string) ($current['group_number'] ?? ''),
            (string) ($current['defense_type'] ?? ''),
            $current['defense_datetime'] ?: null,
            $oldEnd,
            (string) ($current['venue'] ?? ''),
            $startSql,
            $endSql,
            $venue,
            mb_substr($reason, 0, 500),
            $actorId > 0 ? $actorId : null,
            mb_substr($actorName, 0, 160),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Defense reschedule failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The reschedule could not be saved. Please try again.'];
    }

    return ['ok' => true, 'message' => 'Defense rescheduled. The change is recorded in the schedule history.'];
}

/** @return array<int, array<string, mixed>> */
function cradDefenseActiveSchedules(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, group_number, research_title, defense_type, defense_datetime, defense_end_datetime, venue, status
           FROM `crad_research_defense_schedules`
          WHERE " . cradDefenseActiveStatusSql() . "
          ORDER BY defense_datetime ASC, id ASC
          LIMIT 200"
    );
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/** @return array<int, array<string, mixed>> */
function cradDefenseHistoryRows(PDO $pdo, int $limit = 50): array
{
    cradEnsureDefenseScheduleHistory($pdo);
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->query(
        "SELECT schedule_id, group_number, defense_type, old_datetime, old_venue, new_datetime, new_venue,
                reason, changed_by_name, changed_at
           FROM `" . CRAD_DEFENSE_HISTORY_TABLE . "`
          ORDER BY changed_at DESC, id DESC
          LIMIT {$limit}"
    );
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

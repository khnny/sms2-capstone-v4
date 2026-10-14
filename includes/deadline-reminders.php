<?php
/**
 * Deadline reminders for CRAD research work (To-Do P2.6: deadline reminders).
 *
 * A reminder is created for each group member and adviser when one of these falls within the next 7 days:
 *   - a research plan target completion date (active plans)
 *   - a milestone target date (milestones not yet completed or approved)
 *   - a confirmed defense (scheduled or finalized, dated)
 *
 * Each reminder is stored once per user, deadline, and stage (7, 3, 1, or 0 days left). Running the job again
 * never creates duplicates, and a missed day is caught up on the next run. Email is sent when SMTP is configured.
 * The in-app reminder is always stored, and the email outcome is recorded in email_status.
 */
declare(strict_types=1);

require_once ROOT_PATH . '/config/database.php';
if (!function_exists('smsSetting')) {
    require_once ROOT_PATH . '/includes/security.php';
}
if (!function_exists('cradDb')) {
    require_once ROOT_PATH . '/modules/crad/config/config.php';
}

function smsReminderTimezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function smsReminderSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `sms2_deadline_reminders` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            source_key VARCHAR(80) NOT NULL,
            deadline_date DATE NOT NULL,
            remind_days TINYINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL DEFAULT '',
            group_label VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT NULL,
            email_status VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_deadline_reminder (user_id, source_key, deadline_date, remind_days),
            KEY idx_deadline_reminder_user (user_id, is_read, deadline_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

/**
 * Days left mapped to the reminder stage. 4-7 days -> 7, 2-3 days -> 3, otherwise the exact day (1 or 0).
 * Returns null when the deadline is more than 7 days away or already past.
 */
function smsReminderBucket(int $daysLeft): ?int
{
    if ($daysLeft < 0 || $daysLeft > 7) {
        return null;
    }
    if ($daysLeft >= 4) {
        return 7;
    }
    if ($daysLeft >= 2) {
        return 3;
    }
    return $daysLeft;
}

/** @return list<array<string, mixed>> */
function smsReminderSources(PDO $crad, string $from, string $to): array
{
    $queries = [
        'plan' => "SELECT rp.id AS source_id, rp.research_group_id AS group_id, rp.target_completion_date AS due,
                          'Research plan completion' AS label, rp.research_title, rp.group_number
                     FROM crad_research_plans rp
                    WHERE rp.status = 'Active' AND rp.research_group_id IS NOT NULL
                      AND rp.target_completion_date BETWEEN :from AND :to",
        'milestone' => "SELECT rm.id AS source_id, rp.research_group_id AS group_id, rm.target_date AS due,
                              rm.milestone_name AS label, rp.research_title, rp.group_number
                         FROM crad_research_milestones rm
                         JOIN crad_research_plans rp ON rp.id = rm.research_plan_id
                        WHERE rp.status = 'Active' AND rp.research_group_id IS NOT NULL
                          AND rm.status NOT IN ('Completed', 'Approved')
                          AND rm.target_date BETWEEN :from AND :to",
        'defense' => "SELECT rds.id AS source_id, rds.research_group_id AS group_id, DATE(rds.defense_datetime) AS due,
                             CONCAT(COALESCE(rds.defense_type, 'Research'), ' defense') AS label,
                             rds.research_title, rds.group_number
                        FROM crad_research_defense_schedules rds
                       WHERE LOWER(COALESCE(rds.status, '')) IN ('scheduled', 'finalized')
                         AND rds.research_group_id IS NOT NULL
                         AND rds.defense_datetime IS NOT NULL
                         AND DATE(rds.defense_datetime) BETWEEN :from AND :to",
    ];
    $rows = [];
    foreach ($queries as $kind => $sql) {
        $stmt = $crad->prepare($sql);
        $stmt->execute([':from' => $from, ':to' => $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['kind'] = $kind;
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Sms2 user ids for a research group: members matched by student id or email, and assigned advisers.
 * @return list<int>
 */
function smsReminderRecipients(PDO $crad, PDO $users, int $groupId): array
{
    $ids = [];
    $members = $crad->prepare('SELECT student_id, email FROM crad_research_group_members WHERE research_group_id = ?');
    $members->execute([$groupId]);
    $find = $users->prepare(
        "SELECT id FROM sms2_users
          WHERE status = 'active'
            AND ((COALESCE(student_id, '') <> '' AND student_id = :sid) OR (COALESCE(email, '') <> '' AND email = :email))
          LIMIT 1"
    );
    foreach ($members->fetchAll(PDO::FETCH_ASSOC) as $member) {
        $find->execute([
            ':sid' => trim((string) ($member['student_id'] ?? '')),
            ':email' => trim((string) ($member['email'] ?? '')),
        ]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $advisers = $crad->prepare(
        "SELECT DISTINCT adviser_user_id FROM crad_research_adviser_assignments
          WHERE research_group_id = ? AND adviser_user_id IS NOT NULL
            AND COALESCE(assignment_status, '') NOT IN ('Removed', 'Cancelled', 'Inactive')"
    );
    $advisers->execute([$groupId]);
    foreach ($advisers->fetchAll(PDO::FETCH_COLUMN) as $adviserId) {
        $ids[(int) $adviserId] = true;
    }
    return array_map('intval', array_keys($ids));
}

/** Sends the reminder email. Returns 'sent' or a short reason the email was not sent. */
function smsReminderSendEmail(array $user, string $label, string $groupLabel, string $message): string
{
    $to = trim((string) ($user['email'] ?? ''));
    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return 'no email address';
    }
    if (!function_exists('smsSendMail')) {
        require_once ROOT_PATH . '/includes/mail.php';
    }
    $calendarUrl = BASE_URL . '/communication/calendar.php';
    $subject = 'Deadline reminder: ' . $label;
    $text = 'Hello ' . trim((string) ($user['full_name'] ?? '')) . ",\n\n" . $message . "\n" . $groupLabel
        . "\n\nOpen the calendar: " . $calendarUrl . "\n";
    $html = '<p>Hello ' . e((string) ($user['full_name'] ?? '')) . ',</p>'
        . '<p>' . e($message) . '<br>' . e($groupLabel) . '</p>'
        . '<p><a href="' . e($calendarUrl) . '">Open the calendar</a></p>';
    $result = smsSendMail($to, $subject, $html, $text);
    if (!empty($result['ok'])) {
        return 'sent';
    }
    return 'not sent: ' . substr((string) ($result['error'] ?? 'unknown error'), 0, 200);
}

/**
 * Creates reminders for deadlines in the next 7 days and sends emails for new ones.
 * @return array{deadlines:int, created:int, emailed:int, email_not_sent:int, error?:string}
 */
function smsGenerateDeadlineReminders(?DateTimeImmutable $today = null): array
{
    $stats = ['deadlines' => 0, 'created' => 0, 'emailed' => 0, 'email_not_sent' => 0];
    $crad = cradDb();
    $users = db();
    if (!$crad instanceof PDO || !$users instanceof PDO) {
        return $stats + ['error' => 'database unavailable'];
    }
    smsReminderSchema($users);

    $tz = smsReminderTimezone();
    $today = $today ?? new DateTimeImmutable('today', $tz);
    $todayStart = new DateTimeImmutable($today->format('Y-m-d'), $tz);
    $from = $todayStart->format('Y-m-d');
    $to = $todayStart->modify('+7 days')->format('Y-m-d');

    $insert = $users->prepare(
        'INSERT IGNORE INTO sms2_deadline_reminders
            (user_id, source_key, deadline_date, remind_days, title, group_label, message, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $userStmt = $users->prepare("SELECT id, email, full_name FROM sms2_users WHERE id = ? AND status = 'active' LIMIT 1");
    $markEmail = $users->prepare('UPDATE sms2_deadline_reminders SET email_status = ? WHERE id = ?');

    foreach (smsReminderSources($crad, $from, $to) as $source) {
        $dueDate = new DateTimeImmutable((string) $source['due'], $tz);
        $daysLeft = (int) round(($dueDate->getTimestamp() - $todayStart->getTimestamp()) / 86400);
        $bucket = smsReminderBucket($daysLeft);
        if ($bucket === null) {
            continue;
        }
        $stats['deadlines']++;
        $sourceKey = $source['kind'] . ':' . (string) $source['source_id'];
        $label = trim((string) $source['label']);
        $groupLabel = 'Group ' . trim((string) $source['group_number']) . ' - ' . trim((string) $source['research_title']);
        $when = $bucket === 0 ? 'today' : 'in ' . $bucket . ' day' . ($bucket === 1 ? '' : 's');
        $message = $label . ' is due ' . $when . ' (' . $dueDate->format('M j, Y') . ').';

        foreach (smsReminderRecipients($crad, $users, (int) $source['group_id']) as $userId) {
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                continue;
            }
            $insert->execute([$userId, $sourceKey, $dueDate->format('Y-m-d'), $bucket, $label, $groupLabel, $message]);
            if ($insert->rowCount() !== 1) {
                continue; // already reminded at this stage
            }
            $stats['created']++;
            $reminderId = (int) $users->lastInsertId();
            $emailStatus = smsReminderSendEmail($user, $label, $groupLabel, $message);
            $markEmail->execute([$emailStatus, $reminderId]);
            if ($emailStatus === 'sent') {
                $stats['emailed']++;
            } else {
                $stats['email_not_sent']++;
            }
        }
    }
    return $stats;
}

/** Runs the generator once per day when someone opens a dashboard. Safe to call on every page load. */
function smsDeadlineRemindersEnsureDaily(): void
{
    try {
        $today = (new DateTimeImmutable('today', smsReminderTimezone()))->format('Y-m-d');
        if (smsSetting('deadline_reminders_last_run', '') === $today) {
            return;
        }
        smsSetSetting('deadline_reminders_last_run', $today); // mark first so one request runs it
        smsGenerateDeadlineReminders();
    } catch (Throwable $e) {
        error_log('Deadline reminders daily run failed: ' . $e->getMessage());
    }
}

/** Unread reminders for a user whose deadline has not passed yet. @return list<array<string, mixed>> */
function smsDeadlineReminderItems(int $userId, int $limit = 5): array
{
    $pdo = db();
    if (!$pdo instanceof PDO || $userId <= 0) {
        return [];
    }
    smsReminderSchema($pdo);
    $today = (new DateTimeImmutable('today', smsReminderTimezone()))->format('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT id, title, group_label, message, deadline_date, remind_days
           FROM sms2_deadline_reminders
          WHERE user_id = ? AND is_read = 0 AND deadline_date >= ?
          ORDER BY deadline_date ASC, remind_days ASC, id ASC
          LIMIT ' . max(1, min(20, $limit))
    );
    $stmt->execute([$userId, $today]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function smsDeadlineReminderMarkRead(int $userId, int $reminderId): bool
{
    $pdo = db();
    if (!$pdo instanceof PDO || $userId <= 0 || $reminderId <= 0) {
        return false;
    }
    smsReminderSchema($pdo);
    $stmt = $pdo->prepare('UPDATE sms2_deadline_reminders SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?');
    $stmt->execute([$reminderId, $userId]);
    return $stmt->rowCount() > 0;
}

/** Path the mark-as-read form can safely return to: inside this app only. */
function smsReminderReturnPath(): string
{
    $basePath = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?? '');
    $requested = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($requested !== '' && str_starts_with($requested, '/') && !str_starts_with($requested, '//') && str_starts_with($requested, $basePath)) {
        return $requested;
    }
    return BASE_URL . '/dashboard/index.php';
}

/**
 * Banner for unread, upcoming deadline reminders. Called from the shared layout, so every role sees it on its own pages.
 * Also runs the daily generator once per day.
 */
function smsRenderDeadlineReminderBanner(): void
{
    if (!function_exists('isAuthenticated') || !isAuthenticated()) {
        return;
    }
    smsDeadlineRemindersEnsureDaily();
    $items = smsDeadlineReminderItems((int) getCurrentUserId(), 3);
    if ($items === []) {
        return;
    }
    $returnTo = smsReminderReturnPath();
    $html = '<section class="alert alert-warning py-2 px-3 mb-3" role="region" aria-label="Deadline reminders">'
        . '<strong>Deadline reminders</strong><ul class="mb-0 mt-1 ps-3">';
    foreach ($items as $item) {
        $html .= '<li>' . e((string) $item['message'])
            . ' <span class="text-muted small">' . e((string) $item['group_label']) . '</span>'
            . '<form method="post" action="' . e(BASE_URL . '/communication/reminder-read.php') . '" class="d-inline ms-2">'
            . csrfField()
            . '<input type="hidden" name="reminder_id" value="' . (int) $item['id'] . '">'
            . '<input type="hidden" name="return_to" value="' . e($returnTo) . '">'
            . '<button type="submit" class="btn btn-sm btn-link p-0 align-baseline">Mark as read</button>'
            . '</form></li>';
    }
    $html .= '</ul></section>';
    echo $html;
}


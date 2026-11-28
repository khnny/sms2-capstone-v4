<?php
/**
 * SMS 2 - Outgoing webhooks for integrated apps.
 *
 * How events are produced (no CRAD page or workflow file was changed):
 *   A watcher compares the current state of a few CRAD tables with the last
 *   state it saw (sms2_webhook_entity_state). Each change becomes one event in
 *   the outbox (sms2_webhook_events) and one delivery row per subscribed
 *   client (sms2_webhook_deliveries). The first run only records the current
 *   state, so old records do not flood subscribers.
 *
 * How events are delivered:
 *   tools/send-webhooks.php (cron, every minute) or POST /api/v1/internal/webhooks/run
 *   sends each delivery as a signed JSON POST and retries failures
 *   (1, 5, 15, 60 minutes, then gives up and marks it failed).
 *
 * Signature: X-SMS2-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<body>">
 * using the client's webhook secret; X-SMS2-Timestamp carries the timestamp.
 */
declare(strict_types=1);

require_once __DIR__ . '/integration.php';
require_once __DIR__ . '/../modules/crad/config/config.php';

const SMS_WEBHOOK_RETRY_MINUTES = [1, 5, 15, 60];
const SMS_WEBHOOK_TIMEOUT_SECONDS = 8;

function smsWebhookEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    smsIntegrationEnsureSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sms2_webhook_entity_state` (
        `entity` varchar(60) NOT NULL,
        `entity_id` int(10) unsigned NOT NULL,
        `state_json` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`entity`, `entity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sms2_webhook_events` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `event_id` char(36) NOT NULL,
        `event_type` varchar(80) NOT NULL,
        `payload` mediumtext NOT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_webhook_event_id` (`event_id`),
        KEY `idx_webhook_event_type` (`event_type`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sms2_webhook_deliveries` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `event_pk` int(10) unsigned NOT NULL,
        `client_id` varchar(80) NOT NULL,
        `status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
        `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
        `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
        `last_http_status` smallint(5) unsigned DEFAULT NULL,
        `last_error` varchar(500) DEFAULT NULL,
        `delivered_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_webhook_delivery` (`event_pk`, `client_id`),
        KEY `idx_webhook_due` (`status`, `next_attempt_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function smsWebhookUuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function smsWebhookClientWants(array $client, string $eventType): bool
{
    $filters = array_filter(array_map('trim', explode(',', (string) ($client['webhook_events'] ?? '*'))));
    foreach ($filters as $f) {
        if ($f === '*' || $f === $eventType || (str_ends_with($f, '.*') && str_starts_with($eventType, substr($f, 0, -1)))) {
            return true;
        }
    }
    return false;
}

/** The event filter picks what a client wants; its scopes decide what it may see. */
function smsWebhookClientMayReceive(array $client, string $eventType, array $data): bool
{
    $scope = smsApiEventScope($eventType, $data);
    return $scope === null || smsApiClientHasScope($client, $scope);
}

/**
 * Queue one event for every active client that subscribes to its type.
 *
 * @param array<string, mixed> $data
 * @param array{student_ids?: list<string>, user_ids?: list<int>} $subjects
 */
function smsWebhookEmit(PDO $pdo, string $eventType, array $data, array $subjects = []): string
{
    smsWebhookEnsureSchema($pdo);
    $eventId = smsWebhookUuid();
    $payload = [
        'id' => $eventId,
        'type' => $eventType,
        'occurred_at' => date('c'),
        'data' => $data,
        'subjects' => [
            'student_ids' => array_values(array_unique(array_filter(array_map('strval', $subjects['student_ids'] ?? []), 'strlen'))),
            'user_ids' => array_values(array_unique(array_filter(array_map('intval', $subjects['user_ids'] ?? [])))),
        ],
    ];
    $pdo->prepare('INSERT INTO `sms2_webhook_events` (event_id, event_type, payload) VALUES (?, ?, ?)')
        ->execute([$eventId, $eventType, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    $eventPk = (int) $pdo->lastInsertId();

    $clients = $pdo->query("SELECT client_id, webhook_events, scopes FROM `sms2_api_clients`
                            WHERE status = 'active' AND webhook_url IS NOT NULL AND webhook_url <> ''")->fetchAll();
    $insert = $pdo->prepare('INSERT IGNORE INTO `sms2_webhook_deliveries` (event_pk, client_id) VALUES (?, ?)');
    foreach ($clients as $client) {
        if (smsWebhookClientWants($client, $eventType) && smsWebhookClientMayReceive($client, $eventType, $data)) {
            $insert->execute([$eventPk, (string) $client['client_id']]);
        }
    }
    return $eventId;
}

/** Student IDs and user IDs connected to a research group. */
function smsWebhookGroupSubjects(PDO $crad, ?int $groupId, ?string $groupNumber = null): array
{
    $studentIds = [];
    $userIds = [];
    if (!$groupId && $groupNumber) {
        $stmt = $crad->prepare('SELECT id FROM `crad_research_groups` WHERE group_number = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$groupNumber]);
        $groupId = (int) $stmt->fetchColumn() ?: null;
    }
    if ($groupId) {
        $stmt = $crad->prepare('SELECT student_id FROM `crad_research_group_members` WHERE research_group_id = ?');
        $stmt->execute([$groupId]);
        $studentIds = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt = $crad->prepare(
            'SELECT rg.leader_id, t.student_id, t.student_user_id FROM `crad_research_groups` rg
             LEFT JOIN `crad_title_approvals` t ON t.id = rg.title_approval_id WHERE rg.id = ?'
        );
        $stmt->execute([$groupId]);
        if ($row = $stmt->fetch()) {
            $studentIds[] = (string) $row['leader_id'];
            $studentIds[] = (string) $row['student_id'];
            $userIds[] = (int) $row['student_user_id'];
        }
    }
    return ['student_ids' => array_map('strtoupper', array_filter($studentIds, 'strlen')), 'user_ids' => array_filter($userIds)];
}

/**
 * Watched CRAD tables. Each watcher returns rows (id + fields) and turns a
 * change into [eventType, data, subjects] or null.
 *
 * @return array<string, array{sql: string, fields: list<string>, event: callable}>
 */
function smsWebhookWatchers(): array
{
    return [
        'title_approval' => [
            'sql' => 'SELECT id, student_id, student_user_id, proposed_title, proposal_number, status, coordinator_status, crad_status, sent_at FROM `crad_title_approvals`',
            'fields' => ['status', 'coordinator_status', 'crad_status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                if ($prev === null && trim((string) $row['status']) === '') {
                    return null;
                }
                $label = smsWebhookTitleLabel($row);
                $prevLabel = $prev ? smsWebhookTitleLabel($prev + $row) : null;
                if ($prevLabel === $label) {
                    return null;
                }
                $groupStmt = $crad->prepare('SELECT id FROM `crad_research_groups` WHERE title_approval_id = ? ORDER BY id DESC LIMIT 1');
                $groupStmt->execute([(int) $row['id']]);
                $subjects = smsWebhookGroupSubjects($crad, (int) $groupStmt->fetchColumn() ?: null);
                $subjects['student_ids'][] = strtoupper((string) $row['student_id']);
                $subjects['user_ids'][] = (int) $row['student_user_id'];
                return ['research.title_status_changed', [
                    'title_approval_id' => (int) $row['id'],
                    'proposal_number' => $row['proposal_number'],
                    'title' => $row['proposed_title'],
                    'status' => $label,
                    'previous_status' => $prevLabel,
                    'adviser_status' => $row['status'],
                    'coordinator_status' => $row['coordinator_status'],
                    'crad_status' => $row['crad_status'],
                ], $subjects];
            },
        ],
        'adviser_assignment' => [
            'sql' => 'SELECT id, research_group_id, group_number, student_id, adviser_name, assignment_status, confirmation_status FROM `crad_research_adviser_assignments`',
            'fields' => ['adviser_name', 'assignment_status', 'confirmation_status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                if (strcasecmp((string) $row['assignment_status'], 'Assigned') !== 0) {
                    return null;
                }
                $subjects = smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null, (string) $row['group_number']);
                $subjects['student_ids'][] = strtoupper((string) $row['student_id']);
                return ['research.adviser_assigned', [
                    'assignment_id' => (int) $row['id'],
                    'group_number' => $row['group_number'],
                    'adviser' => $row['adviser_name'],
                    'confirmation_status' => $row['confirmation_status'],
                ], $subjects];
            },
        ],
        'research_group' => [
            'sql' => 'SELECT id, group_number, leader_id, flow_status FROM `crad_research_groups`',
            'fields' => ['flow_status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                $status = strtolower((string) $row['flow_status']);
                if (!in_array($status, ['approved', 'rejected', 'ready_for_assignment'], true)) {
                    return null;
                }
                $subjects = smsWebhookGroupSubjects($crad, (int) $row['id']);
                return ['research.group_status_changed', [
                    'group_id' => (int) $row['id'],
                    'group_number' => $row['group_number'],
                    'status' => ucwords(str_replace('_', ' ', $status)),
                    'previous_status' => $prev['flow_status'] ?? null,
                ], $subjects];
            },
        ],
        'panel_application' => [
            'sql' => 'SELECT id, research_group_id, application_ref, status FROM `crad_defense_panel_requests`',
            'fields' => ['status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                if (in_array((string) $row['status'], ['Draft', 'Submitted'], true)) {
                    return null;
                }
                return ['research.panel_application_status_changed', [
                    'application_ref' => $row['application_ref'],
                    'status' => $row['status'],
                    'previous_status' => $prev['status'] ?? null,
                ], smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null)];
            },
        ],
        'chapter_submission' => [
            'sql' => 'SELECT id, research_group_id, chapter_number, version_number, status FROM `crad_chapter_submissions`',
            'fields' => ['status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                if (in_array(strtolower((string) $row['status']), ['submitted', 'pending', ''], true)) {
                    return null;
                }
                return ['submission.reviewed', [
                    'source' => 'chapter',
                    'submission_id' => (int) $row['id'],
                    'chapter' => (int) $row['chapter_number'],
                    'version' => (int) $row['version_number'],
                    'status' => $row['status'],
                    'previous_status' => $prev['status'] ?? null,
                ], smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null)];
            },
        ],
        'progress_feedback' => [
            'sql' => 'SELECT f.id, u.research_group_id, u.update_title, f.adviser_name, f.new_milestone_status
                      FROM `crad_research_progress_feedback` f JOIN `crad_research_progress_updates` u ON u.id = f.progress_update_id',
            'fields' => ['id'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                if ($prev !== null) {
                    return null;
                }
                return ['submission.reviewed', [
                    'source' => 'progress_update',
                    'feedback_id' => (int) $row['id'],
                    'update_title' => $row['update_title'],
                    'from' => $row['adviser_name'],
                    'status' => $row['new_milestone_status'],
                ], smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null)];
            },
        ],
        'defense_schedule' => [
            'sql' => 'SELECT ds.id, ds.research_group_id, ds.group_number, ds.defense_type, ds.status, ds.defense_datetime, ds.defense_end_datetime,
                             ds.venue, ds.venue_id, COALESCE(v.venue_name, ds.venue) AS venue_name
                      FROM `crad_research_defense_schedules` ds LEFT JOIN `crad_research_venues` v ON v.id = ds.venue_id',
            'fields' => ['status', 'defense_datetime', 'defense_end_datetime', 'venue', 'venue_id'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                $moved = $prev !== null && ($prev['defense_datetime'] !== $row['defense_datetime']
                    || $prev['defense_end_datetime'] !== $row['defense_end_datetime']
                    || $prev['venue'] !== $row['venue'] || $prev['venue_id'] !== $row['venue_id']);
                $type = $prev === null ? 'defense.scheduled' : ($moved ? 'defense.rescheduled' : 'defense.status_changed');
                return [$type, [
                    'defense_id' => (int) $row['id'],
                    'group_number' => $row['group_number'],
                    'defense_type' => $row['defense_type'],
                    'status' => $row['status'],
                    'starts_at' => smsWebhookIso($row['defense_datetime']),
                    'ends_at' => smsWebhookIso($row['defense_end_datetime']),
                    'venue' => $row['venue_name'],
                    'previous_starts_at' => $prev ? smsWebhookIso($prev['defense_datetime']) : null,
                ], smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null, (string) $row['group_number'])];
            },
        ],
        'clearance' => [
            'sql' => 'SELECT id, research_group_id, research_stage, status FROM `crad_research_services_clearances`',
            'fields' => ['status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                return ['clearance.status_changed', [
                    'kind' => 'clearance',
                    'id' => (int) $row['id'],
                    'stage' => $row['research_stage'],
                    'status' => $row['status'],
                    'previous_status' => $prev['status'] ?? null,
                ], smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null)];
            },
        ],
        'clearance_payment' => [
            'sql' => 'SELECT id, research_group_id, research_stage, status, or_number FROM `crad_research_clearance_payments`',
            'fields' => ['status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                return ['clearance.status_changed', [
                    'kind' => 'payment',
                    'id' => (int) $row['id'],
                    'stage' => $row['research_stage'],
                    'status' => $row['status'],
                    'or_number' => $row['or_number'],
                    'previous_status' => $prev['status'] ?? null,
                ], smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null)];
            },
        ],
        'final_manuscript' => [
            'sql' => 'SELECT id, research_group_id, status, approved_by_name, approved_at FROM `crad_final_manuscript_approvals`',
            'fields' => ['status'],
            'event' => static function (array $row, ?array $prev, PDO $crad): ?array {
                if (strcasecmp((string) $row['status'], 'Approved') !== 0) {
                    return null;
                }
                return ['manuscript.final_approved', [
                    'approval_id' => (int) $row['id'],
                    'research_group_id' => (int) $row['research_group_id'],
                    'approved_by' => $row['approved_by_name'],
                    'approved_at' => smsWebhookIso($row['approved_at']),
                ], smsWebhookGroupSubjects($crad, (int) $row['research_group_id'] ?: null)];
            },
        ],
    ];
}

function smsWebhookIso($value): ?string
{
    if ($value === null || $value === '' || str_starts_with((string) $value, '0000')) {
        return null;
    }
    $ts = strtotime((string) $value);
    return $ts ? date('c', $ts) : null;
}

function smsWebhookTitleLabel(array $t): string
{
    $title = (string) ($t['status'] ?? '');
    $coord = (string) ($t['coordinator_status'] ?? '');
    $crad = (string) ($t['crad_status'] ?? '');
    foreach (['Rejected', 'Returned'] as $bad) {
        if (strcasecmp($title, $bad) === 0 || strcasecmp($coord, $bad) === 0 || strcasecmp($crad, $bad) === 0) {
            return $bad;
        }
    }
    if (strcasecmp($crad, 'Approved') === 0) {
        return 'CRAD Approved';
    }
    if (strcasecmp($coord, 'Approved') === 0) {
        return 'Coordinator Approved';
    }
    if (strcasecmp($title, 'Approved') === 0) {
        return 'Adviser Approved';
    }
    return 'Document Packet Sent';
}

/** Compare CRAD tables with the last seen state and queue events. Returns the number of events queued. */
function smsWebhookDetectChanges(PDO $sms, PDO $crad): int
{
    smsWebhookEnsureSchema($sms);
    $queued = 0;
    $load = $sms->prepare('SELECT entity_id, state_json FROM `sms2_webhook_entity_state` WHERE entity = ?');
    $save = $sms->prepare('INSERT INTO `sms2_webhook_entity_state` (entity, entity_id, state_json) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)');
    foreach (smsWebhookWatchers() as $entity => $watcher) {
        try {
            $rows = $crad->query($watcher['sql'])->fetchAll();
        } catch (Throwable $e) {
            error_log('Webhook watcher ' . $entity . ' skipped: ' . $e->getMessage());
            continue;
        }
        $load->execute([$entity]);
        $known = [];
        foreach ($load->fetchAll() as $r) {
            $known[(int) $r['entity_id']] = json_decode((string) $r['state_json'], true) ?: [];
        }
        // Entity id 0 marks that this watcher has taken its first snapshot.
        $baselined = array_key_exists(0, $known);
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $state = [];
            foreach ($watcher['fields'] as $field) {
                $state[$field] = $row[$field] === null ? null : (string) $row[$field];
            }
            $prev = $known[$id] ?? null;
            if ($prev === $state) {
                continue;
            }
            if ($baselined) {
                $event = ($watcher['event'])($row, $prev, $crad);
                if ($event !== null) {
                    [$type, $data, $subjects] = $event;
                    smsWebhookEmit($sms, $type, $data, $subjects);
                    $queued++;
                }
            }
            $save->execute([$entity, $id, json_encode($state)]);
        }
        if (!$baselined) {
            $save->execute([$entity, 0, '{"baseline":true}']);
        }
    }
    return $queued;
}

function smsWebhookSign(string $secret, int $timestamp, string $body): string
{
    return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

/** Send due deliveries. @return array{sent: int, failed: int, retrying: int} */
function smsWebhookDeliverDue(PDO $pdo, int $limit = 50): array
{
    smsWebhookEnsureSchema($pdo);
    $stats = ['sent' => 0, 'failed' => 0, 'retrying' => 0];
    $due = $pdo->query(
        "SELECT d.id, d.attempts, d.client_id, e.event_id, e.event_type, e.payload, c.webhook_url, c.webhook_secret
         FROM `sms2_webhook_deliveries` d
         JOIN `sms2_webhook_events` e ON e.id = d.event_pk
         JOIN `sms2_api_clients` c ON c.client_id = d.client_id
         WHERE d.status = 'pending' AND d.next_attempt_at <= NOW() AND c.status = 'active'
         ORDER BY d.id LIMIT " . max(1, min(500, $limit))
    )->fetchAll();
    $done = $pdo->prepare("UPDATE `sms2_webhook_deliveries` SET status = 'delivered', attempts = attempts + 1, last_http_status = ?, last_error = NULL, delivered_at = NOW() WHERE id = ?");
    $retry = $pdo->prepare("UPDATE `sms2_webhook_deliveries` SET status = ?, attempts = ?, last_http_status = ?, last_error = ?, next_attempt_at = NOW() + INTERVAL ? MINUTE WHERE id = ?");

    foreach ($due as $d) {
        $secret = smsApiClientWebhookSecret($d);
        $body = (string) $d['payload'];
        $ts = time();
        [$status, $error] = smsWebhookPost((string) $d['webhook_url'], $body, [
            'Content-Type: application/json',
            'User-Agent: SMS2-Webhooks/1.0',
            'X-SMS2-Event: ' . $d['event_type'],
            'X-SMS2-Event-Id: ' . $d['event_id'],
            'X-SMS2-Timestamp: ' . $ts,
            'X-SMS2-Signature: ' . smsWebhookSign($secret, $ts, $body),
        ]);
        if ($status >= 200 && $status < 300) {
            $done->execute([$status, (int) $d['id']]);
            $stats['sent']++;
            continue;
        }
        $attempts = (int) $d['attempts'] + 1;
        $giveUp = $attempts > count(SMS_WEBHOOK_RETRY_MINUTES);
        $wait = SMS_WEBHOOK_RETRY_MINUTES[min($attempts - 1, count(SMS_WEBHOOK_RETRY_MINUTES) - 1)];
        $retry->execute([$giveUp ? 'failed' : 'pending', $attempts, $status ?: null, mb_substr($error, 0, 500), $wait, (int) $d['id']]);
        $stats[$giveUp ? 'failed' : 'retrying']++;
    }
    return $stats;
}

/** @return array{0: int, 1: string} HTTP status (0 on network error) and error text */
function smsWebhookPost(string $url, string $body, array $headers): array
{
    if (!function_exists('curl_init')) {
        return [0, 'PHP curl extension is not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => SMS_WEBHOOK_TIMEOUT_SECONDS,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $response === false ? curl_error($ch) : ($status >= 300 ? 'HTTP ' . $status . ': ' . mb_substr((string) $response, 0, 200) : '');
    curl_close($ch);
    return [$status, $error];
}

/** Detect changes and deliver, guarded so two runs never overlap. */
function smsWebhookRun(): array
{
    $sms = getDatabaseConnection();
    $crad = getCradDatabaseConnection();
    $got = (int) $sms->query("SELECT GET_LOCK('sms2_webhook_run', 0)")->fetchColumn();
    if ($got !== 1) {
        return ['skipped' => 'another run is in progress'];
    }
    try {
        $queued = smsWebhookDetectChanges($sms, $crad);
        $stats = smsWebhookDeliverDue($sms);
        return ['queued' => $queued] + $stats;
    } finally {
        $sms->query("SELECT RELEASE_LOCK('sms2_webhook_run')");
    }
}

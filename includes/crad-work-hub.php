<?php
/**
 * SMS 2 - CRAD Work Hub: one page for CRAD Officers and Research Coordinators.
 *
 * A banner with key numbers, charts (groups by stage, reviews per week,
 * upcoming defenses), every queue that is waiting on the user, heads-up alerts
 * and (for coordinators) their research groups. Review buttons open the
 * existing review page in a pop-up on the same page (assets/js/research-hub.js).
 */
declare(strict_types=1);

function cwhEsc($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Run a query; any error (missing table on older installs) gives an empty result. */
function cwhRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('CRAD work hub query: ' . $e->getMessage());
        return [];
    }
}

function cwhValue(PDO $pdo, string $sql, array $params = []): int
{
    $rows = cwhRows($pdo, $sql, $params);
    return $rows ? (int) array_values($rows[0])[0] : 0;
}

/** Whole days since a date (0 when missing). */
function cwhAgeDays(?string $when): int
{
    $ts = $when ? strtotime($when) : false;
    return $ts ? max(0, (int) floor((time() - $ts) / 86400)) : 0;
}

function cwhAgeLabel(int $days): string
{
    return $days <= 0 ? 'new' : $days . 'd';
}

function cwhInitials(string $name): string
{
    $name = preg_replace('/^(Dr\.|Engr\.|Mrs?\.|Ms\.|Prof\.)\s*/i', '', trim($name));
    $parts = preg_split('/[\s,\-]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '•';
}

/** First name in a members JSON list, or ''. */
function cwhLeaderFromJson(?string $json): string
{
    $list = json_decode((string) $json, true);
    if (!is_array($list)) {
        return '';
    }
    foreach ($list as $m) {
        if (is_array($m)) {
            $name = (string) ($m['name'] ?? $m['full_name'] ?? $m['student_name'] ?? '');
            if ($name !== '') {
                return $name;
            }
        }
    }
    return '';
}

/**
 * Stage of every research group, for the "groups by stage" chart.
 * Returns [stage label => count] in process order.
 */
function cwhStageCounts(PDO $pdo, ?array $groupIds = null): array
{
    $order = ['Group', 'Title', 'Registry', 'Ch 1–3', 'Pre-oral', 'Ch 4–5', 'Final defense', 'Manuscript', 'Clearance', 'Done'];
    $counts = array_fill_keys($order, 0);

    $titles = [];
    foreach (cwhRows($pdo, 'SELECT id, student_id, crad_status FROM `crad_title_approvals` ORDER BY id') as $t) {
        $titles[strtoupper(trim((string) $t['student_id']))] = $t;
    }
    $groups = cwhRows($pdo, "SELECT id, group_number, title_approval_id, leader_id, flow_status FROM `crad_research_groups`");
    $officialByTitle = [];
    foreach ($groups as $g) {
        if (!str_starts_with(strtoupper((string) $g['group_number']), 'STU-') && (int) $g['title_approval_id'] > 0) {
            $officialByTitle[(int) $g['title_approval_id']] = $g;
        }
    }
    $chapters = [];
    foreach (cwhRows($pdo, "SELECT research_group_id, COUNT(DISTINCT chapter_number) AS n FROM `crad_chapter_submissions` WHERE status = 'Accepted' AND chapter_number BETWEEN 1 AND 3 GROUP BY research_group_id") as $r) {
        $chapters[(int) $r['research_group_id']] = (int) $r['n'];
    }
    $defenses = [];
    foreach (cwhRows($pdo, "SELECT research_group_id, defense_type, status FROM `crad_research_defense_schedules`") as $r) {
        $type = stripos((string) $r['defense_type'], 'final') !== false ? 'final' : 'proposal';
        $done = in_array(strtolower((string) $r['status']), ['completed', 'passed', 'done'], true);
        $defenses[(int) $r['research_group_id']][$type] = max($defenses[(int) $r['research_group_id']][$type] ?? 0, $done ? 2 : 1);
    }
    $plans = [];
    foreach (cwhRows($pdo, 'SELECT research_group_id, final_defense_recommended FROM `crad_research_plans`') as $r) {
        $plans[(int) $r['research_group_id']] = (int) $r['final_defense_recommended'];
    }
    $manuscripts = [];
    foreach (cwhRows($pdo, "SELECT research_group_id, MAX(status = 'Approved') AS ok FROM `crad_manuscript_submissions` GROUP BY research_group_id") as $r) {
        $manuscripts[(int) $r['research_group_id']] = (int) $r['ok'];
    }
    $clearanceDone = [];
    foreach (cwhRows($pdo, "SELECT research_group_id FROM `crad_research_services_clearances` WHERE research_stage = 'research_2' AND status = 'clearance_done'") as $r) {
        $clearanceDone[(int) $r['research_group_id']] = true;
    }

    foreach ($groups as $g) {
        $gid = (int) $g['id'];
        $isWorkflowRow = str_starts_with(strtoupper((string) $g['group_number']), 'STU-');
        if ($isWorkflowRow) {
            $t = $titles[strtoupper(trim((string) $g['leader_id']))] ?? null;
            if ($t && isset($officialByTitle[(int) $t['id']])) {
                continue; // counted through its official RG row
            }
            if ($groupIds !== null && !in_array($gid, $groupIds, true)) {
                continue;
            }
            $stage = !$t ? 'Group' : ((string) $t['crad_status'] === 'Approved' ? 'Registry' : 'Title');
        } else {
            if ($groupIds !== null && !in_array($gid, $groupIds, true)) {
                continue;
            }
            if (($chapters[$gid] ?? 0) < 3) {
                $stage = 'Ch 1–3';
            } elseif (($defenses[$gid]['proposal'] ?? 0) < 2) {
                $stage = 'Pre-oral';
            } elseif (empty($plans[$gid])) {
                $stage = 'Ch 4–5';
            } elseif (($defenses[$gid]['final'] ?? 0) < 2) {
                $stage = 'Final defense';
            } elseif (empty($manuscripts[$gid])) {
                $stage = 'Manuscript';
            } elseif (empty($clearanceDone[$gid])) {
                $stage = 'Clearance';
            } else {
                $stage = 'Done';
            }
        }
        $counts[$stage]++;
    }
    return $counts;
}

/** Received vs. finished reviews for the last 6 weeks (Monday-based). */
function cwhWeekly(PDO $pdo, array $pairs): array
{
    $weeks = [];
    $start = (new DateTimeImmutable('monday this week'))->modify('-5 weeks');
    for ($i = 0; $i < 6; $i++) {
        $from = $start->modify('+' . $i . ' weeks');
        $weeks[] = ['label' => $from->format('M j'), 'from' => $from->format('Y-m-d 00:00:00'), 'to' => $from->modify('+7 days')->format('Y-m-d 00:00:00'), 'in' => 0, 'out' => 0];
    }
    foreach ($pairs as [$table, $inCol, $outCol, $where]) {
        foreach ($weeks as &$w) {
            $w['in'] += cwhValue($pdo, "SELECT COUNT(*) FROM `$table` WHERE $inCol >= ? AND $inCol < ?" . ($where ? " AND $where" : ''), [$w['from'], $w['to']]);
            $w['out'] += cwhValue($pdo, "SELECT COUNT(*) FROM `$table` WHERE $outCol >= ? AND $outCol < ?" . ($where ? " AND $where" : ''), [$w['from'], $w['to']]);
        }
        unset($w);
    }
    return $weeks;
}

function cwhUpcomingDefenses(PDO $pdo, ?array $groupIds = null, int $limit = 4): array
{
    $rows = cwhRows($pdo, "SELECT research_group_id, group_number, research_title, defense_type, defense_datetime, venue
        FROM `crad_research_defense_schedules`
        WHERE defense_datetime >= CURDATE() AND LOWER(status) NOT IN ('cancelled', 'completed')
        ORDER BY defense_datetime LIMIT 50");
    if ($groupIds !== null) {
        $rows = array_values(array_filter($rows, static fn($r) => in_array((int) $r['research_group_id'], $groupIds, true)));
    }
    return array_slice($rows, 0, $limit);
}

/** Everything the CRAD Officer hub shows. */
function cwhCradOfficerData(PDO $pdo): array
{
    $base = BASE_URL . '/modules/crad/pages/';
    $q = [];

    $rows = cwhRows($pdo, "SELECT id, proposed_title, department, members_json, student_name, coordinator_reviewed_at
        FROM `crad_title_approvals` WHERE status = 'Approved' AND coordinator_status = 'Approved' AND COALESCE(crad_status, 'Pending') IN ('Pending', '')
        ORDER BY coordinator_reviewed_at, id");
    $q[] = ['key' => 'register', 'title' => 'Proposals to register', 'icon' => 'ti-file-check', 'color' => '#ef4444', 'verb' => 'Register',
        'all' => $base . 'register-proposal.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => $r['proposed_title'], 'sub' => $r['student_name'] ?: $r['department'], 'who' => $r['student_name'], 'age' => cwhAgeDays($r['coordinator_reviewed_at']), 'url' => $base . 'register-proposal.php'], $rows)];

    $rows = cwhRows($pdo, "SELECT c.id, c.research_stage, c.leader_group_no, c.uploaded_at, c.members_json, g.group_number, g.leader_name
        FROM `crad_research_services_clearances` c LEFT JOIN `crad_research_groups` g ON g.id = c.research_group_id
        WHERE c.status = 'crad_received' AND c.uploaded_at IS NOT NULL ORDER BY c.uploaded_at, c.id");
    $q[] = ['key' => 'clearance', 'title' => 'Clearances to approve', 'icon' => 'ti-clipboard-check', 'color' => '#f97316', 'verb' => 'Review',
        'all' => $base . 'research-clearance.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => trim(($r['group_number'] ?: $r['leader_group_no']) . ' · ' . ($r['research_stage'] === 'research_2' ? 'Research 2' : 'Research 1'), ' ·'), 'sub' => $r['leader_name'] ?: cwhLeaderFromJson($r['members_json']), 'who' => $r['leader_name'] ?: cwhLeaderFromJson($r['members_json']), 'age' => cwhAgeDays($r['uploaded_at']), 'url' => $base . 'research-clearance.php?id=' . (int) $r['id']], $rows)];

    $rows = cwhRows($pdo, "SELECT id, application_ref, group_number, research_title, submitted_at, status FROM `crad_defense_panel_requests`
        WHERE status IN ('Submitted', 'Under Review') ORDER BY submitted_at, id");
    $q[] = ['key' => 'panel', 'title' => 'Panel applications', 'icon' => 'ti-users', 'color' => '#8b5cf6', 'verb' => 'Review',
        'all' => $base . 'panel-applications.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => $r['group_number'] . ' · ' . $r['research_title'], 'sub' => $r['application_ref'] . ' · ' . $r['status'], 'who' => (string) $r['group_number'], 'age' => cwhAgeDays($r['submitted_at']), 'url' => $base . 'panel-applications.php?id=' . (int) $r['id']], $rows)];

    $rows = cwhRows($pdo, "SELECT p.id, p.group_number, p.research_title, p.reviewed_at FROM `crad_defense_panel_requests` p
        WHERE p.status = 'Approved' AND NOT EXISTS (SELECT 1 FROM `crad_research_defense_schedules` s WHERE s.research_group_id = p.research_group_id AND LOWER(s.status) <> 'cancelled')
        ORDER BY p.reviewed_at, p.id");
    $q[] = ['key' => 'schedule', 'title' => 'Ready to schedule', 'icon' => 'ti-calendar-event', 'color' => '#3b82f6', 'verb' => 'Schedule',
        'all' => $base . 'panel-configuration.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => $r['group_number'] . ' · ' . $r['research_title'], 'sub' => 'Panel application approved', 'who' => (string) $r['group_number'], 'age' => cwhAgeDays($r['reviewed_at']), 'url' => $base . 'panel-configuration.php'], $rows)];

    $rows = cwhRows($pdo, "SELECT t.id, t.proposal_number, t.proposed_title, t.department, t.crad_reviewed_at FROM `crad_title_approvals` t
        WHERE t.status = 'Approved' AND t.coordinator_status = 'Approved' AND t.crad_status = 'Approved'
          AND NOT EXISTS (SELECT 1 FROM `crad_research_groups` g WHERE g.title_approval_id = t.id AND g.group_number NOT LIKE 'STU-%' AND COALESCE(g.group_number, '') <> '')
        ORDER BY t.crad_reviewed_at, t.id");
    $q[] = ['key' => 'rgno', 'title' => 'Group numbers to issue', 'icon' => 'ti-hash', 'color' => '#0ea5e9', 'verb' => 'Generate',
        'all' => $base . 'research-group-number.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => ($r['proposal_number'] ?: 'Proposal') . ' · ' . $r['proposed_title'], 'sub' => $r['department'], 'who' => (string) $r['department'], 'age' => cwhAgeDays($r['crad_reviewed_at']), 'url' => $base . 'research-group-number.php'], $rows)];

    $rows = cwhRows($pdo, "SELECT id, applicant_name, application_ref, submitted_at FROM `crad_panelist_applications`
        WHERE status IN ('Submitted', 'Under Review', 'Pending') ORDER BY submitted_at, id");
    $q[] = ['key' => 'panelist', 'title' => 'Panelist applications', 'icon' => 'ti-user-plus', 'color' => '#14b8a6', 'verb' => 'Review',
        'all' => $base . 'panelist-applications.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => $r['applicant_name'], 'sub' => $r['application_ref'], 'who' => $r['applicant_name'], 'age' => cwhAgeDays($r['submitted_at']), 'url' => $base . 'panelist-applications.php?id=' . (int) $r['id']], $rows)];

    $rows = cwhRows($pdo, "SELECT ms.id, ms.version_number, ms.submitted_at, ms.submitted_by_name, g.group_number FROM `crad_manuscript_submissions` ms
        LEFT JOIN `crad_research_groups` g ON g.id = ms.research_group_id
        WHERE ms.status IN ('Submitted', 'Under Review', 'Pending') ORDER BY ms.submitted_at, ms.id");
    $q[] = ['key' => 'manuscript', 'title' => 'Final manuscripts', 'icon' => 'ti-book', 'color' => '#f59e0b', 'verb' => 'Review',
        'all' => $base . 'final-manuscript-review.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => $r['group_number'] . ' · version ' . (int) $r['version_number'], 'sub' => $r['submitted_by_name'], 'who' => $r['submitted_by_name'], 'age' => cwhAgeDays($r['submitted_at']), 'url' => $base . 'final-manuscript-review.php?id=' . (int) $r['id']], $rows)];

    $waiting = array_sum(array_column($q, 'count'));
    $stages = cwhStageCounts($pdo);
    $active = array_sum($stages) - $stages['Done'];
    $defenses = cwhUpcomingDefenses($pdo);
    $weekDefenses = count(array_filter(cwhUpcomingDefenses($pdo, null, 50), static fn($d) => strtotime((string) $d['defense_datetime']) < strtotime('+7 days')));
    $doneWeek = cwhValue($pdo, "SELECT COUNT(*) FROM `crad_title_approvals` WHERE crad_reviewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
        + cwhValue($pdo, "SELECT COUNT(*) FROM `crad_defense_panel_requests` WHERE reviewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
        + cwhValue($pdo, "SELECT COUNT(*) FROM `crad_research_services_clearances` WHERE crad_signed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $oldest = 0;
    foreach ($q as $queue) {
        foreach ($queue['items'] as $it) {
            $oldest = max($oldest, $it['age']);
        }
    }
    $next = $defenses[0] ?? null;

    return [
        'role' => 'CRAD Officer',
        'greeting_name' => 'CRAD Office',
        'subline' => $waiting === 0 ? 'Nothing is waiting on CRAD right now. Nice work.' : $waiting . ' item' . ($waiting === 1 ? '' : 's') . ' need you today' . ($oldest >= 2 ? ', the oldest has waited ' . $oldest . ' days.' : '.'),
        'kpis' => [
            ['icon' => 'ti-bolt', 'value' => (string) $waiting, 'label' => 'Waiting on you', 'note' => $oldest > 0 ? 'Oldest: ' . $oldest . ' days' : 'All recent', 'color' => '#ef4444'],
            ['icon' => 'ti-users', 'value' => (string) $active, 'label' => 'Active research groups', 'note' => $stages['Done'] . ' finished', 'color' => '#60a5fa'],
            ['icon' => 'ti-presentation', 'value' => (string) $weekDefenses, 'label' => 'Defenses this week', 'note' => $next ? 'Next: ' . date('M j, g:i A', strtotime((string) $next['defense_datetime'])) : 'None scheduled', 'color' => '#a78bfa'],
            ['icon' => 'ti-checks', 'value' => (string) $doneWeek, 'label' => 'Reviews finished', 'note' => 'Last 7 days', 'color' => '#34d399'],
        ],
        'stages' => $stages,
        'stage_title' => 'Groups by stage',
        'weekly' => cwhWeekly($pdo, [
            ['crad_title_approvals', 'coordinator_reviewed_at', 'crad_reviewed_at', "coordinator_status = 'Approved'"],
            ['crad_defense_panel_requests', 'submitted_at', 'reviewed_at', ''],
            ['crad_research_services_clearances', 'uploaded_at', 'crad_signed_at', ''],
        ]),
        'defenses' => $defenses,
        'queues' => $q,
        'alerts' => cwhAlerts($pdo, null),
        'groups' => null,
    ];
}

/** Everything the Research Coordinator hub shows. */
function cwhCoordinatorData(PDO $pdo, int $userId, string $name): array
{
    $base = BASE_URL . '/modules/crad/pages/';
    $assign = cwhRows($pdo, "SELECT id, research_group_id, group_number, research_title, assigned_at, confirmation_status FROM `crad_research_coordinator_assignments`
        WHERE coordinator_user_id = ? ORDER BY assigned_at, id", [$userId]);
    $groupIds = array_values(array_unique(array_map(static fn($a) => (int) $a['research_group_id'], array_filter($assign, static fn($a) => strtolower((string) $a['confirmation_status']) === 'confirmed'))));
    $pending = array_values(array_filter($assign, static fn($a) => !in_array(strtolower((string) $a['confirmation_status']), ['confirmed', 'declined', 'rejected'], true)));

    $q = [];
    $rows = cwhRows($pdo, "SELECT id, proposed_title, student_name, reviewed_at FROM `crad_title_approvals`
        WHERE status = 'Approved' AND COALESCE(coordinator_status, 'Pending') IN ('Pending', '', 'Screening') AND (coordinator_name = ? OR coordinator_name IS NULL OR coordinator_name = '')
        ORDER BY reviewed_at, id", [$name]);
    $q[] = ['key' => 'screen', 'title' => 'Titles to screen', 'icon' => 'ti-signature', 'color' => '#ef4444', 'verb' => 'Screen',
        'all' => $base . 'approved-research.php', 'count' => count($rows),
        'items' => array_map(static fn($r) => ['title' => $r['proposed_title'], 'sub' => $r['student_name'] . ' · adviser approved', 'who' => $r['student_name'], 'age' => cwhAgeDays($r['reviewed_at']), 'url' => $base . 'approved-research.php'], $rows)];
    $q[] = ['key' => 'confirm', 'title' => 'Assignments to confirm', 'icon' => 'ti-user-plus', 'color' => '#f97316', 'verb' => 'Open',
        'all' => $base . 'assignment-confirmation.php', 'count' => count($pending),
        'items' => array_map(static fn($r) => ['title' => trim(($r['group_number'] ?: 'Group') . ' · ' . $r['research_title'], ' ·'), 'sub' => 'Assigned ' . ($r['assigned_at'] ? date('M j', strtotime((string) $r['assigned_at'])) : ''), 'who' => (string) $r['group_number'], 'age' => cwhAgeDays($r['assigned_at']), 'url' => $base . 'assignment-confirmation.php'], $pending)];

    $groups = [];
    $behind = 0;
    if ($groupIds) {
        $in = implode(',', array_map('intval', $groupIds));
        $plans = [];
        foreach (cwhRows($pdo, "SELECT id, research_group_id, overall_progress, current_stage FROM `crad_research_plans` WHERE research_group_id IN ($in)") as $p) {
            $plans[(int) $p['research_group_id']] = $p;
        }
        $late = [];
        foreach (cwhRows($pdo, "SELECT p.research_group_id, MIN(m.target_date) AS late_since FROM `crad_research_milestones` m JOIN `crad_research_plans` p ON p.id = m.research_plan_id
            WHERE p.research_group_id IN ($in) AND m.target_date < CURDATE() AND LOWER(COALESCE(m.status, '')) NOT IN ('completed', 'approved') GROUP BY p.research_group_id") as $r) {
            $late[(int) $r['research_group_id']] = cwhAgeDays($r['late_since']);
        }
        $stageOf = [];
        foreach ($groupIds as $gid) {
            $one = cwhStageCounts($pdo, [$gid]);
            foreach ($one as $label => $n) {
                if ($n > 0) {
                    $stageOf[$gid] = $label;
                }
            }
        }
        $members = [];
        foreach (cwhRows($pdo, "SELECT g.id AS gid, m.full_name FROM `crad_research_groups` g
            JOIN `crad_research_groups` w ON w.leader_id = g.leader_id AND w.group_number LIKE 'STU-%'
            JOIN `crad_research_group_members` m ON m.research_group_id = w.id
            WHERE g.id IN ($in) ORDER BY m.is_leader DESC, m.member_order, m.id") as $r) {
            $members[(int) $r['gid']][] = (string) $r['full_name'];
        }
        foreach (cwhRows($pdo, "SELECT id, group_number, research_title FROM `crad_research_groups` WHERE id IN ($in) ORDER BY group_number") as $g) {
            $gid = (int) $g['id'];
            $progress = (int) round((float) ($plans[$gid]['overall_progress'] ?? 0));
            $stage = $stageOf[$gid] ?? 'Ch 1–3';
            $isLate = isset($late[$gid]) && $late[$gid] > 0;
            $behind += $isLate ? 1 : 0;
            $groups[] = [
                'group_number' => $g['group_number'],
                'title' => $g['research_title'],
                'stage' => $stage,
                'progress' => $stage === 'Done' ? 100 : $progress,
                'flag' => $stage === 'Done' ? 'Done' : ($isLate ? $late[$gid] . ' days behind' : 'On track'),
                'tone' => $stage === 'Done' ? 'done' : ($isLate ? 'late' : 'ok'),
                'members' => array_slice($members[$gid] ?? [], 0, 5),
                'url' => $base . 'research-workspace.php',
            ];
        }
    }

    $waiting = array_sum(array_column($q, 'count'));
    $defenses = cwhUpcomingDefenses($pdo, $groupIds ?: [0]);
    $monthDefenses = count(array_filter(cwhUpcomingDefenses($pdo, $groupIds ?: [0], 50), static fn($d) => strtotime((string) $d['defense_datetime']) < strtotime('+30 days')));
    $first = preg_replace('/^(Dr\.|Engr\.|Prof\.)\s*/i', '$0', $name);

    return [
        'role' => 'Research Coordinator',
        'greeting_name' => $first,
        'subline' => ($waiting === 0 ? 'Nothing is waiting on you right now' : $waiting . ' item' . ($waiting === 1 ? '' : 's') . ' need you today')
            . ($behind > 0 ? ', and ' . $behind . ' of your groups ' . ($behind === 1 ? 'is' : 'are') . ' behind schedule.' : '.'),
        'kpis' => [
            ['icon' => 'ti-bolt', 'value' => (string) $waiting, 'label' => 'Waiting on you', 'note' => $waiting ? 'Open the cards below' : 'All clear', 'color' => '#ef4444'],
            ['icon' => 'ti-users', 'value' => (string) count($groups), 'label' => 'My research groups', 'note' => count($pending) ? count($pending) . ' to confirm' : 'All confirmed', 'color' => '#60a5fa'],
            ['icon' => 'ti-flag', 'value' => (string) $behind, 'label' => 'Behind schedule', 'note' => $behind ? 'Check the red cards' : 'All on track', 'color' => '#fbbf24'],
            ['icon' => 'ti-presentation', 'value' => (string) $monthDefenses, 'label' => 'Defenses in 30 days', 'note' => $defenses ? 'Next: ' . date('M j', strtotime((string) $defenses[0]['defense_datetime'])) : 'None scheduled', 'color' => '#a78bfa'],
        ],
        'stages' => cwhStageCounts($pdo, $groupIds ?: [0]),
        'stage_title' => 'My groups by stage',
        'weekly' => cwhWeekly($pdo, [
            ['crad_title_approvals', 'reviewed_at', 'coordinator_reviewed_at', 'coordinator_name = ' . $pdo->quote($name)],
        ]),
        'defenses' => $defenses,
        'queues' => $q,
        'alerts' => cwhAlerts($pdo, $groupIds ?: [0]),
        'groups' => $groups,
    ];
}

/** Short heads-up lines: groups with no progress update for 14+ days, defenses soon. */
function cwhAlerts(PDO $pdo, ?array $groupIds): array
{
    $alerts = [];
    $where = $groupIds !== null ? ' AND g.id IN (' . implode(',', array_map('intval', $groupIds)) . ')' : '';
    foreach (cwhRows($pdo, "SELECT g.group_number, MAX(u.submitted_at) AS last_at FROM `crad_research_groups` g
        LEFT JOIN `crad_research_progress_updates` u ON u.research_group_id = g.id
        WHERE g.group_number NOT LIKE 'STU-%' AND COALESCE(g.group_number, '') <> ''$where
        GROUP BY g.id HAVING last_at IS NULL OR last_at < DATE_SUB(NOW(), INTERVAL 14 DAY) LIMIT 3") as $r) {
        $alerts[] = ['tone' => 'bad', 'icon' => 'ti-alert-triangle', 'text' => $r['group_number'] . ($r['last_at'] ? ' has had no progress update for ' . cwhAgeDays($r['last_at']) . ' days.' : ' has not sent a progress update yet.')];
    }
    $soon = count(array_filter(cwhUpcomingDefenses($pdo, $groupIds, 50), static fn($d) => strtotime((string) $d['defense_datetime']) < strtotime('+3 days')));
    if ($soon > 0) {
        $alerts[] = ['tone' => 'warn', 'icon' => 'ti-clock', 'text' => $soon . ' defense' . ($soon === 1 ? ' is' : 's are') . ' in the next 3 days. Check that panels have confirmed.'];
    }
    if (!$alerts) {
        $alerts[] = ['tone' => 'good', 'icon' => 'ti-checks', 'text' => 'Nothing needs special attention right now.'];
    }
    return $alerts;
}

function cwhRender(array $d): string
{
    $stageColors = ['Group' => '#94a3b8', 'Title' => '#60a5fa', 'Registry' => '#3b82f6', 'Ch 1–3' => '#6366f1', 'Pre-oral' => '#8b5cf6',
        'Ch 4–5' => '#a855f7', 'Final defense' => '#ec4899', 'Manuscript' => '#f59e0b', 'Clearance' => '#14b8a6', 'Done' => '#10b981'];
    $total = array_sum($d['stages']);
    $acc = 0;
    $grad = [];
    foreach ($d['stages'] as $label => $n) {
        if ($n <= 0) {
            continue;
        }
        $a = $acc / $total * 360;
        $acc += $n;
        $grad[] = $stageColors[$label] . ' ' . round($a, 2) . 'deg ' . round($acc / $total * 360, 2) . 'deg';
    }
    $ring = $grad ? 'conic-gradient(' . implode(',', $grad) . ')' : '#e2e8f0';
    $maxWeek = max(1, ...array_map(static fn($w) => max($w['in'], $w['out']), $d['weekly']));
    $waitingTotal = array_sum(array_column($d['queues'], 'count'));
    $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('G');
    $greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $queues = $d['queues'];
    usort($queues, static fn($a, $b) => ($b['count'] > 0) <=> ($a['count'] > 0));
    ob_start();
    ?>
<div class="cwh" id="researchHub" data-rh-root>
    <section class="cwh-hero">
        <img class="cwh-wm" src="<?= cwhEsc(BASE_URL . '/images/bcp-crest.png') ?>" alt="">
        <div class="cwh-date"><?= cwhEsc((new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('l, M j, Y')) ?> · <?= cwhEsc($d['role']) ?></div>
        <h1><?= cwhEsc($greet . ', ' . $d['greeting_name']) ?></h1>
        <p><?= cwhEsc($d['subline']) ?></p>
        <div class="cwh-kpis">
            <?php foreach ($d['kpis'] as $k): ?>
                <div class="cwh-kpi" style="--c:<?= cwhEsc($k['color']) ?>">
                    <div class="cwh-kpi-ic"><i class="ti <?= cwhEsc($k['icon']) ?>" aria-hidden="true"></i></div>
                    <div><b><?= cwhEsc($k['value']) ?></b><span><?= cwhEsc($k['label']) ?></span><small><?= cwhEsc($k['note']) ?></small></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="cwh-row">
        <section class="cwh-card">
            <h2><i class="ti ti-chart-donut" aria-hidden="true"></i><?= cwhEsc($d['stage_title']) ?></h2>
            <div class="cwh-donut">
                <div class="cwh-ring" style="background:<?= cwhEsc($ring) ?>" role="img" aria-label="<?= (int) $total ?> groups by stage"><div><b><?= (int) $total ?></b><span>group<?= $total === 1 ? '' : 's' ?></span></div></div>
                <div class="cwh-legend">
                    <?php foreach ($d['stages'] as $label => $n): if ($n === 0 && in_array($label, ['Done'], true)) { continue; } ?>
                        <div class="<?= $n === 0 ? 'is-zero' : '' ?>"><i style="background:<?= cwhEsc($stageColors[$label]) ?>"></i><?= cwhEsc($label) ?><b><?= (int) $n ?></b></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="cwh-card">
            <h2><i class="ti ti-trending-up" aria-hidden="true"></i>Reviews per week<small>received vs. finished</small></h2>
            <div class="cwh-bars" role="img" aria-label="Reviews received and finished per week">
                <?php foreach ($d['weekly'] as $w): ?>
                    <div class="cwh-week" title="<?= cwhEsc($w['label'] . ': ' . $w['in'] . ' received, ' . $w['out'] . ' finished') ?>">
                        <span class="in" style="height:<?= round($w['in'] / $maxWeek * 100) ?>%"></span>
                        <span class="out" style="height:<?= round($w['out'] / $maxWeek * 100) ?>%"></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="cwh-xl"><?php foreach ($d['weekly'] as $w): ?><span><?= cwhEsc($w['label']) ?></span><?php endforeach; ?></div>
            <div class="cwh-lg2"><span><i class="in"></i>Received</span><span><i class="out"></i>Finished</span></div>
        </section>
        <section class="cwh-card">
            <h2><i class="ti ti-calendar-event" aria-hidden="true"></i>Upcoming defenses</h2>
            <?php if (!$d['defenses']): ?>
                <p class="cwh-empty">No defenses scheduled yet.</p>
            <?php endif; ?>
            <?php foreach ($d['defenses'] as $ev): $ts = strtotime((string) $ev['defense_datetime']); $final = stripos((string) $ev['defense_type'], 'final') !== false; ?>
                <div class="cwh-ev" style="--c:<?= $final ? '#ec4899' : '#8b5cf6' ?>">
                    <div class="cwh-cal"><span><?= cwhEsc(strtoupper(date('M', $ts))) ?></span><b><?= cwhEsc(date('j', $ts)) ?></b></div>
                    <div class="cwh-ev-t"><b><?= cwhEsc($ev['group_number']) ?></b><span><?= cwhEsc(date('g:i A', $ts) . ($ev['venue'] ? ' · ' . $ev['venue'] : '')) ?></span></div>
                    <span class="cwh-tag"><?= $final ? 'Final' : 'Proposal' ?></span>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <div class="cwh-sec"><h2>Waiting on you</h2><span class="cwh-pill<?= $waitingTotal ? '' : ' is-clear' ?>"><?= $waitingTotal ? $waitingTotal . ' item' . ($waitingTotal === 1 ? '' : 's') : 'All clear' ?></span></div>
    <div class="cwh-queues">
        <?php foreach ($queues as $q): ?>
            <section class="cwh-q<?= $q['count'] ? '' : ' is-empty' ?>" style="--c:<?= cwhEsc($q['color']) ?>">
                <div class="cwh-qh"><div class="cwh-q-ic"><i class="ti <?= cwhEsc($q['icon']) ?>" aria-hidden="true"></i></div><h3><?= cwhEsc($q['title']) ?></h3><span class="cwh-n"><?= (int) $q['count'] ?></span></div>
                <div class="cwh-qb">
                    <?php if (!$q['count']): ?>
                        <p class="cwh-empty"><i class="ti ti-checks" aria-hidden="true"></i> Nothing waiting</p>
                    <?php endif; ?>
                    <?php foreach (array_slice($q['items'], 0, 3) as $it): $age = (int) $it['age']; ?>
                        <div class="cwh-it">
                            <span class="cwh-av"><?= cwhEsc(cwhInitials((string) $it['who'])) ?></span>
                            <div class="cwh-it-t"><b title="<?= cwhEsc($it['title']) ?>"><?= cwhEsc($it['title']) ?></b><span><?= cwhEsc($it['sub']) ?></span></div>
                            <span class="cwh-age <?= $age <= 0 ? 'new' : ($age === 1 ? 'd1' : 'd2') ?>"><?= cwhEsc(cwhAgeLabel($age)) ?></span>
                            <button type="button" class="cwh-go" data-rh-open="frame" data-rh-src="<?= cwhEsc($it['url']) ?>" data-rh-title="<?= cwhEsc($q['title']) ?>"><?= cwhEsc($q['verb']) ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="cwh-qf">
                    <span><?= $q['count'] ? 'Oldest: ' . cwhEsc(cwhAgeLabel(max(array_column($q['items'], 'age')))) : '' ?></span>
                    <button type="button" class="cwh-all" data-rh-open="frame" data-rh-src="<?= cwhEsc($q['all']) ?>" data-rh-title="<?= cwhEsc($q['title']) ?>"><?= $q['count'] > 3 ? 'See all ' . (int) $q['count'] : 'Open page' ?> →</button>
                </div>
            </section>
        <?php endforeach; ?>
        <section class="cwh-card cwh-alerts">
            <h2><i class="ti ti-alert-triangle" aria-hidden="true"></i>Heads-up</h2>
            <?php foreach ($d['alerts'] as $a): ?>
                <div class="cwh-al cwh-al--<?= cwhEsc($a['tone']) ?>"><i class="ti <?= cwhEsc($a['icon']) ?>" aria-hidden="true"></i><?= cwhEsc($a['text']) ?></div>
            <?php endforeach; ?>
        </section>
    </div>

    <?php if (is_array($d['groups'])): ?>
        <div class="cwh-sec"><h2>My research groups</h2></div>
        <?php if (!$d['groups']): ?>
            <p class="cwh-empty cwh-card">No confirmed groups yet. Groups appear here after you accept an assignment.</p>
        <?php endif; ?>
        <div class="cwh-groups">
            <?php foreach ($d['groups'] as $g): $color = ['late' => '#ef4444', 'done' => '#10b981'][$g['tone']] ?? ($stageColors[$g['stage']] ?? '#3b82f6'); ?>
                <article class="cwh-g" style="--c:<?= cwhEsc($color) ?>;--p:<?= (int) $g['progress'] ?>">
                    <div class="cwh-pr"><span><?= (int) $g['progress'] ?>%</span></div>
                    <div class="cwh-g-t">
                        <b><?= cwhEsc($g['group_number'] . ' · ' . $g['stage']) ?></b>
                        <div><?= cwhEsc($g['title']) ?></div>
                        <span class="cwh-flag"><?= cwhEsc($g['flag']) ?></span>
                        <?php if ($g['members']): ?>
                            <div class="cwh-mem"><?php foreach ($g['members'] as $i => $m): ?><i title="<?= cwhEsc($m) ?>" style="background:<?= ['#3b82f6', '#8b5cf6', '#f59e0b', '#14b8a6', '#ec4899'][$i % 5] ?>"><?= cwhEsc(cwhInitials($m)) ?></i><?php endforeach; ?></div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="rh-modal" id="rhModal" hidden>
        <div class="rh-modal-backdrop" data-rh-close></div>
        <div class="rh-modal-box" role="dialog" aria-modal="true" aria-labelledby="rhModalTitle">
            <header class="rh-modal-head">
                <span class="rh-no" id="rhModalNo" hidden></span>
                <h2 id="rhModalTitle"></h2>
                <a class="rh-modal-pop" id="rhModalPop" href="#" target="_blank" rel="noopener" hidden>Open full page ↗</a>
                <button type="button" class="rh-modal-x" data-rh-close aria-label="Close">×</button>
            </header>
            <div class="rh-modal-body" id="rhModalBody"></div>
        </div>
    </div>
</div>
    <?php
    return (string) ob_get_clean();
}

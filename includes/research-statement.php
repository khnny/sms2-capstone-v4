<?php
/**
 * SMS 2 - Research statement: the whole CRAD process for one student on one page.
 *
 * Reads like a utility bill: a summary at the top, then every step of the
 * research lifecycle in order with its status, dates, who acted and remarks.
 * Used by the SMS 2 Research Workspace page and (through api/v1
 * me/research/statement) by the separate Student Portal, so both show the
 * same thing.
 *
 * Every step has: key, no, title, state (done | current | waiting | locked | attention),
 * status (short label), summary (one line), rows (label => value), list (optional
 * table: ['columns' => [...], 'rows' => [[...], ...]]), remarks (list of
 * ['from', 'text', 'at']), action (['label', 'path']) and updated_at.
 */
declare(strict_types=1);

require_once __DIR__ . '/integration-data.php';

/** Format a stored date for display (empty string when missing). */
function smsStmtDate($value, bool $withTime = false): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000')) {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date($withTime ? 'M j, Y g:i A' : 'M j, Y', $ts) : '';
}

function smsStmtIs(?string $value, string ...$words): bool
{
    $v = strtolower(trim((string) $value));
    foreach ($words as $w) {
        if ($v === strtolower($w)) {
            return true;
        }
    }
    return false;
}

function smsStmtBad(?string $value): bool
{
    return (bool) preg_match('/reject|return|revision|declin|cancel|fail/i', (string) $value);
}

function smsStmtFetch(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('Research statement query failed: ' . $e->getMessage());
        return null;
    }
}

function smsStmtFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Research statement query failed: ' . $e->getMessage());
        return [];
    }
}

/** The student's own submitted Research Group (the STU- workflow row), newest first. */
function smsStmtWorkflowGroup(PDO $crad, array $user): ?array
{
    $sid = strtoupper(trim((string) ($user['student_id'] ?? '')));
    $uid = (int) $user['id'];
    return smsStmtFetch(
        $crad,
        "SELECT g.* FROM `crad_research_groups` g
         WHERE (? <> '' AND UPPER(TRIM(g.leader_id)) = ?)
            OR (? > 0 AND g.submitted_by_user_id = ?)
            OR EXISTS (SELECT 1 FROM `crad_research_group_members` m
                       WHERE m.research_group_id = g.id AND ? <> '' AND UPPER(TRIM(m.student_id)) = ?)
         ORDER BY (g.group_number LIKE 'STU-%') DESC, (g.flow_status IS NOT NULL AND g.flow_status <> '') DESC, g.id DESC
         LIMIT 1",
        [$sid, $sid, $uid, $uid, $sid, $sid]
    );
}

/**
 * Build the full statement for one student.
 *
 * @return array{student: array, summary: array, steps: list<array>, generated_at: string}
 */
function smsResearchStatement(PDO $crad, array $user): array
{
    $profile = smsApiStudentProfile($user);
    $flowGroup = smsStmtWorkflowGroup($crad, $user);
    $registered = smsApiStudentGroup($crad, $user);
    $title = smsApiLatestTitleApproval($crad, $user);
    $titleFull = $title ? smsStmtFetch($crad, 'SELECT * FROM `crad_title_approvals` WHERE id = ?', [(int) $title['id']]) : null;
    $research = smsApiResearchSummary($crad, $user);
    $plan = $research['plan'] ?? null;

    // Group ids that belong to this student (workflow STU- row and the official registered row).
    $groupIds = array_values(array_unique(array_filter([
        (int) ($flowGroup['id'] ?? 0),
        (int) ($registered['id'] ?? 0),
    ])));
    $inIds = $groupIds ? implode(',', array_map('intval', $groupIds)) : '0';
    $officialGroup = $registered ?: null;
    $officialNumber = (string) ($officialGroup['group_number'] ?? '');
    $isOfficialNumber = $officialNumber !== '' && !str_starts_with(strtoupper($officialNumber), 'STU-');

    $steps = [];

    /* 1. Research group ------------------------------------------------- */
    $members = $flowGroup ? smsStmtFetchAll(
        $crad,
        'SELECT student_id, full_name, section, is_leader FROM `crad_research_group_members`
         WHERE research_group_id = ? ORDER BY is_leader DESC, member_order, id',
        [(int) $flowGroup['id']]
    ) : [];
    $flow = (string) ($flowGroup['flow_status'] ?? '');
    $dh = (string) ($flowGroup['dh_decision'] ?? '');
    if (!$flowGroup || $flow === 'draft' || $flow === '') {
        $s1 = ['state' => 'current', 'status' => $flowGroup ? 'Draft' : 'Not submitted'];
    } elseif (smsStmtBad($dh) || smsStmtBad($flow)) {
        $s1 = ['state' => 'attention', 'status' => 'Rejected by Department Head'];
    } elseif (str_contains($flow, 'pending')) {
        $s1 = ['state' => 'waiting', 'status' => 'Waiting for Department Head'];
    } else {
        $s1 = ['state' => 'done', 'status' => 'Approved'];
    }
    $steps[] = $s1 + [
        'key' => 'group',
        'title' => 'Research group',
        'summary' => $flowGroup
            ? count($members) . ' member(s)' . (!empty($flowGroup['incomplete_reason']) ? ' · below minimum, reason given' : '')
            : 'Submit your group members to start the research process.',
        'rows' => array_filter([
            'Group reference' => (string) ($flowGroup['group_number'] ?? ''),
            'Submitted' => smsStmtDate($flowGroup['submitted_at'] ?? null, true),
            'Department Head decision' => $dh !== '' ? ucfirst($dh) : '',
            'Decided by / on' => trim(((string) ($flowGroup['dh_decision_by'] ?? '') !== '' ? '' : '') . smsStmtDate($flowGroup['dh_decision_at'] ?? null, true)),
            'Reason for incomplete group' => (string) ($flowGroup['incomplete_reason'] ?? ''),
        ], static fn($v) => $v !== ''),
        'list' => $members ? [
            'columns' => ['#', 'Name', 'Student ID', 'Section'],
            'rows' => array_map(static fn(array $m, int $i): array => [
                (string) ($i + 1) . (!empty($m['is_leader']) ? ' (Leader)' : ''),
                (string) $m['full_name'], (string) $m['student_id'], (string) $m['section'],
            ], $members, array_keys($members)),
        ] : null,
        'remarks' => !empty($flowGroup['dh_remarks'])
            ? [['from' => 'Department Head', 'text' => (string) $flowGroup['dh_remarks'], 'at' => smsStmtDate($flowGroup['dh_decision_at'] ?? null)]]
            : [],
        'action' => ['label' => 'Open research group', 'path' => '/modules/student-portal/pages/research-group.php'],
        'updated_at' => (string) ($flowGroup['dh_decision_at'] ?? $flowGroup['submitted_at'] ?? ''),
    ];

    /* 2. Coordinator and adviser ------------------------------------------- */
    $cycle = $groupIds ? smsStmtFetch(
        $crad,
        "SELECT * FROM `crad_research_assignment_cycles` WHERE research_group_id IN ($inIds) ORDER BY id DESC LIMIT 1"
    ) : null;
    $coord = $cycle && !empty($cycle['coordinator_assignment_id'])
        ? smsStmtFetch($crad, 'SELECT coordinator_name, coordinator_email, confirmation_status, confirmed_at, assigned_at FROM `crad_research_coordinator_assignments` WHERE id = ?', [(int) $cycle['coordinator_assignment_id']])
        : null;
    $adv = $cycle && !empty($cycle['adviser_assignment_id'])
        ? smsStmtFetch($crad, 'SELECT adviser_name, adviser_email, confirmation_status, confirmed_at, assigned_at FROM `crad_research_adviser_assignments` WHERE id = ?', [(int) $cycle['adviser_assignment_id']])
        : null;
    $cycleStatus = (string) ($cycle['status'] ?? '');
    if ($s1['state'] !== 'done') {
        $s2 = ['state' => 'locked', 'status' => 'Locked'];
    } elseif (!$cycle) {
        $s2 = ['state' => 'waiting', 'status' => 'Waiting for assignment'];
    } elseif (smsStmtBad($cycleStatus) || str_contains($cycleStatus, 'reassign')) {
        $s2 = ['state' => 'attention', 'status' => 'Needs reassignment'];
    } elseif (smsStmtIs($cycleStatus, 'confirmed', 'fully_assigned')) {
        $s2 = ['state' => 'done', 'status' => 'Fully assigned'];
    } else {
        $s2 = ['state' => 'waiting', 'status' => 'Waiting for confirmation'];
    }
    $confirm = static fn(?string $at): string => $at ? 'Accepted ' . smsStmtDate($at, true) : 'Waiting to accept';
    $steps[] = $s2 + [
        'key' => 'assignment',
        'title' => 'Research coordinator and adviser',
        'summary' => $cycle
            ? 'Assigned by the Department Head. Both must accept before Title Approval.'
            : 'The Department Head assigns a coordinator and an adviser after your group is approved.',
        'rows' => array_filter([
            'Research coordinator' => (string) ($coord['coordinator_name'] ?? ''),
            'Coordinator response' => $cycle ? $confirm($cycle['coordinator_confirmed_at'] ?? null) : '',
            'Research adviser' => (string) ($adv['adviser_name'] ?? ''),
            'Adviser response' => $cycle && $adv ? $confirm($cycle['adviser_confirmed_at'] ?? null) : '',
            'Assigned on' => smsStmtDate($cycle['created_at'] ?? null, true),
        ], static fn($v) => $v !== ''),
        'list' => null,
        'remarks' => !empty($cycle['cancel_reason'])
            ? [['from' => ucfirst((string) ($cycle['cancel_role'] ?: 'Assignment')), 'text' => (string) $cycle['cancel_reason'], 'at' => smsStmtDate($cycle['cancelled_at'] ?? null)]]
            : [],
        'action' => null,
        'updated_at' => (string) ($cycle['updated_at'] ?? ''),
    ];

    /* 3. Title approval ------------------------------------------------- */
    $tLabel = smsApiTitleStatusLabel($title);
    if (!$titleFull) {
        $s3 = $s2['state'] === 'done' ? ['state' => 'current', 'status' => 'Not sent'] : ['state' => 'locked', 'status' => 'Locked'];
    } elseif (in_array($tLabel, ['Rejected', 'Returned'], true)) {
        $s3 = ['state' => 'attention', 'status' => $tLabel];
    } elseif ($tLabel === 'CRAD Approved') {
        $s3 = ['state' => 'done', 'status' => 'CRAD approved'];
    } else {
        $s3 = ['state' => 'waiting', 'status' => $tLabel];
    }
    $sig = static function (?string $status, $at): string {
        $status = trim((string) $status);
        if ($status === '' || smsStmtIs($status, 'Not Ready')) {
            return 'Not yet';
        }
        $d = smsStmtDate($at, true);
        return $status . ($d !== '' ? ' · ' . $d : '');
    };
    $titleRemarks = [];
    foreach ([['Adviser', 'adviser_remarks', 'reviewed_at'], ['Research coordinator', 'coordinator_remarks', 'coordinator_reviewed_at']] as [$who, $col, $at]) {
        if ($titleFull && trim((string) ($titleFull[$col] ?? '')) !== '') {
            $titleRemarks[] = ['from' => $who, 'text' => (string) $titleFull[$col], 'at' => smsStmtDate($titleFull[$at] ?? null)];
        }
    }
    $steps[] = $s3 + [
        'key' => 'title',
        'title' => 'Title approval (CRAD Form S2)',
        'summary' => $titleFull ? (string) $titleFull['proposed_title'] : 'Send your proposed title to your adviser.',
        'rows' => $titleFull ? array_filter([
            'Proposal no.' => (string) ($titleFull['proposal_number'] ?? ''),
            'Department' => (string) ($titleFull['department'] ?? ''),
            'Sent to adviser' => smsStmtDate($titleFull['sent_at'] ?? null, true),
            'Research Adviser' => ($titleFull['adviser_name'] ? $titleFull['adviser_name'] . ': ' : '') . $sig($titleFull['status'] ?? null, $titleFull['reviewed_at'] ?? null),
            'Research Coordinator' => ($titleFull['coordinator_name'] ? $titleFull['coordinator_name'] . ': ' : '') . $sig($titleFull['coordinator_status'] ?? null, $titleFull['coordinator_reviewed_at'] ?? null),
            'CRAD' => $sig($titleFull['crad_status'] ?? null, $titleFull['crad_reviewed_at'] ?? null),
            'Primary SDG' => (string) ($titleFull['primary_sdg'] ?? ''),
        ], static fn($v) => $v !== '') : [],
        'list' => null,
        'remarks' => $titleRemarks,
        'action' => ['label' => 'Open title approval form', 'path' => '/modules/student-portal/pages/research-proposal-submission.php'],
        'updated_at' => (string) ($titleFull['updated_at'] ?? ''),
    ];

    /* 4. Official group number and registry ---------------------------------- */
    if ($s3['state'] !== 'done') {
        $s4 = ['state' => 'locked', 'status' => 'Locked'];
    } elseif ($isOfficialNumber && !empty($registered['research_plan_id'])) {
        $s4 = ['state' => 'done', 'status' => 'Registered'];
    } else {
        $s4 = ['state' => 'waiting', 'status' => 'Waiting for CRAD'];
    }
    $steps[] = $s4 + [
        'key' => 'registry',
        'title' => 'Official group number and registry',
        'summary' => $isOfficialNumber
            ? 'Your group is listed in the Capstone Group / Student Registry.'
            : 'After all title signatures, CRAD generates your official RG number and lists your group in the registry.',
        'rows' => array_filter([
            'Official group no.' => $isOfficialNumber ? $officialNumber : '',
            'Research title' => (string) ($registered['research_title'] ?? ''),
            'Program / department' => (string) ($registered['college_dept'] ?? ''),
            'Academic year' => (string) ($registered['academic_year'] ?? ''),
            'Registered on' => smsStmtDate($registered['date_assigned'] ?? null),
        ], static fn($v) => $v !== ''),
        'list' => null,
        'remarks' => [],
        'action' => null,
        'updated_at' => (string) ($registered['date_assigned'] ?? ''),
    ];

    /* 5. Chapters 1-3 ---------------------------------------------------- */
    $subs = smsApiSubmissions($crad, $user);
    $latestByChapter = [];
    foreach ($subs['chapters'] as $c) {
        $latestByChapter[$c['chapter']] ??= $c; // list is newest version first
    }
    ksort($latestByChapter);
    $chApproved = count(array_filter($latestByChapter, static fn($c) => preg_match('/approved|accepted|passed/i', (string) $c['status'])));
    $chBad = count(array_filter($latestByChapter, static fn($c) => smsStmtBad($c['status'])));
    if ($s4['state'] !== 'done') {
        $s5 = ['state' => 'locked', 'status' => 'Locked'];
    } elseif ($chBad > 0) {
        $s5 = ['state' => 'attention', 'status' => 'Revision needed'];
    } elseif ($chApproved >= 3) {
        $s5 = ['state' => 'done', 'status' => 'Chapters 1-3 approved'];
    } elseif ($latestByChapter) {
        $s5 = ['state' => 'waiting', 'status' => $chApproved . ' of 3 approved'];
    } else {
        $s5 = ['state' => 'current', 'status' => 'Not submitted'];
    }
    $chapterFeedback = array_values(array_filter(smsApiFeedback($crad, $user), static fn($f) => $f['source'] === 'chapter_evaluation'));
    $steps[] = $s5 + [
        'key' => 'chapters',
        'title' => 'Chapters 1 to 3',
        'summary' => $latestByChapter ? count($latestByChapter) . ' chapter(s) submitted' : 'Submit Chapter 1, 2 and 3 for evaluation.',
        'rows' => [],
        'list' => $latestByChapter ? [
            'columns' => ['Chapter', 'Version', 'Status', 'Submitted', 'Reviewed'],
            'rows' => array_map(static fn($c) => [
                'Chapter ' . $c['chapter'], 'v' . $c['version'], (string) $c['status'],
                smsStmtDate($c['submitted_at']), smsStmtDate($c['reviewed_at']),
            ], array_values($latestByChapter)),
        ] : null,
        'remarks' => array_map(static fn($f) => [
            'from' => trim((string) $f['from'] . ' · ' . $f['about'], ' ·'),
            'text' => trim((string) $f['text']) !== '' ? (string) $f['text'] : (string) $f['result'],
            'at' => smsStmtDate($f['created_at']),
        ], array_slice($chapterFeedback, 0, 3)),
        'action' => ['label' => 'Submit chapters', 'path' => '/modules/student-portal/pages/submit-chapters.php'],
        'updated_at' => (string) (($subs['chapters'][0]['submitted_at'] ?? '') ?: ''),
    ];

    /* 6. Progress and milestones --------------------------------------------- */
    $milestones = smsApiTimeline($crad, $user);
    $progress = (float) ($plan['progress'] ?? 0);
    $progressFeedback = array_values(array_filter(smsApiFeedback($crad, $user), static fn($f) => $f['source'] === 'progress_update'));
    if (!$plan) {
        $s6 = $s4['state'] === 'done' ? ['state' => 'waiting', 'status' => 'No research plan yet'] : ['state' => 'locked', 'status' => 'Locked'];
    } elseif ($progress >= 100 || smsStmtIs($plan['status'] ?? '', 'Completed')) {
        $s6 = ['state' => 'done', 'status' => 'Completed'];
    } else {
        $s6 = ['state' => 'current', 'status' => round($progress) . '% complete'];
    }
    $steps[] = $s6 + [
        'key' => 'progress',
        'title' => 'Research progress and milestones',
        'summary' => $plan ? 'Current stage: ' . ((string) ($plan['current_stage'] ?? '') ?: 'Not set') : 'Your research plan opens after registration.',
        'rows' => $plan ? array_filter([
            'Overall progress' => round($progress) . '%',
            'Start date' => smsStmtDate($plan['start_date'] ?? null),
            'Target completion' => smsStmtDate($plan['target_completion_date'] ?? null),
            'Progress updates sent' => (string) count($subs['progress_updates']),
            'Last update' => smsStmtDate($subs['progress_updates'][0]['submitted_at'] ?? null, true),
        ], static fn($v) => $v !== '') : [],
        'list' => $milestones ? [
            'columns' => ['Milestone', 'Status', 'Target', 'Progress'],
            'rows' => array_map(static fn($m) => [
                (string) $m['name'], (string) $m['status'], smsStmtDate($m['target_date']), round((float) $m['progress']) . '%',
            ], $milestones),
        ] : null,
        'remarks' => array_map(static fn($f) => [
            'from' => trim((string) $f['from'] . ' · ' . $f['about'], ' ·'),
            'text' => (string) $f['text'], 'at' => smsStmtDate($f['created_at']),
        ], array_slice($progressFeedback, 0, 3)),
        'action' => ['label' => 'Send a progress update', 'path' => '/modules/student-portal/pages/progress-updates.php'],
        'updated_at' => (string) ($subs['progress_updates'][0]['submitted_at'] ?? ''),
    ];

    /* 7. Panel and defense -------------------------------------------------- */
    $panelReq = $groupIds ? smsStmtFetch(
        $crad,
        "SELECT application_ref, status, review_remarks, submitted_at, reviewed_at FROM `crad_defense_panel_requests`
         WHERE research_group_id IN ($inIds) ORDER BY id DESC LIMIT 1"
    ) : null;
    $defenses = smsApiDefenses($crad, $user);
    $defDone = count(array_filter($defenses, static fn($d) => preg_match('/complete|done|passed|finished/i', (string) $d['status'])));
    if ($s5['state'] === 'locked') {
        $s7 = ['state' => 'locked', 'status' => 'Locked'];
    } elseif ($panelReq && smsStmtBad($panelReq['status'])) {
        $s7 = ['state' => 'attention', 'status' => 'Panel application ' . strtolower((string) $panelReq['status'])];
    } elseif ($defenses && $defDone === count($defenses) && $defDone > 0) {
        $s7 = ['state' => 'done', 'status' => 'Defense completed'];
    } elseif ($defenses) {
        $s7 = ['state' => 'waiting', 'status' => 'Defense scheduled'];
    } elseif ($panelReq) {
        $s7 = ['state' => 'waiting', 'status' => 'Panel application ' . strtolower((string) $panelReq['status'])];
    } else {
        $s7 = ['state' => $s5['state'] === 'done' ? 'current' : 'locked', 'status' => $s5['state'] === 'done' ? 'Not applied' : 'Locked'];
    }
    $steps[] = $s7 + [
        'key' => 'defense',
        'title' => 'Panel application and defense',
        'summary' => $defenses ? count($defenses) . ' defense schedule(s)' : 'Apply for a panel once your chapters are approved.',
        'rows' => array_filter([
            'Panel application' => $panelReq ? ((string) $panelReq['application_ref'] . ' · ' . $panelReq['status']) : '',
            'Applied on' => smsStmtDate($panelReq['submitted_at'] ?? null, true),
            'Reviewed on' => smsStmtDate($panelReq['reviewed_at'] ?? null, true),
        ], static fn($v) => $v !== ''),
        'list' => $defenses ? [
            'columns' => ['Defense', 'Date and time', 'Venue', 'Panel', 'Status'],
            'rows' => array_map(static fn($d) => [
                (string) $d['type'], smsStmtDate($d['starts_at'], true), (string) ($d['venue'] ?: 'To be announced'),
                implode(', ', array_filter(array_merge([(string) $d['panel_chair']], array_map(static fn($p) => (string) $p['name'], $d['panel'])))),
                (string) $d['status'],
            ], $defenses),
        ] : null,
        'remarks' => !empty($panelReq['review_remarks'])
            ? [['from' => 'CRAD', 'text' => (string) $panelReq['review_remarks'], 'at' => smsStmtDate($panelReq['reviewed_at'] ?? null)]]
            : [],
        'action' => ['label' => 'Open panel application', 'path' => '/modules/student-portal/pages/panel-application.php'],
        'updated_at' => (string) ($panelReq['reviewed_at'] ?? $panelReq['submitted_at'] ?? ''),
    ];

    /* 8. Final manuscript ------------------------------------------------- */
    $recommend = $groupIds ? smsStmtFetch($crad, "SELECT status, adviser_name, remarks, recommended_at FROM `crad_final_defense_recommendations` WHERE research_group_id IN ($inIds) ORDER BY id DESC LIMIT 1") : null;
    $manuscript = $groupIds ? smsStmtFetch($crad, "SELECT version_number, status, original_name, submitted_at, reviewed_at FROM `crad_manuscript_submissions` WHERE research_group_id IN ($inIds) ORDER BY id DESC LIMIT 1") : null;
    $finalApproval = $groupIds ? smsStmtFetch($crad, "SELECT status, approved_by_name, remarks, approved_at FROM `crad_final_manuscript_approvals` WHERE research_group_id IN ($inIds) ORDER BY id DESC LIMIT 1") : null;
    if ($finalApproval && preg_match('/approved/i', (string) $finalApproval['status'])) {
        $s8 = ['state' => 'done', 'status' => 'Final manuscript approved'];
    } elseif ($manuscript && smsStmtBad($manuscript['status'])) {
        $s8 = ['state' => 'attention', 'status' => (string) $manuscript['status']];
    } elseif ($manuscript) {
        $s8 = ['state' => 'waiting', 'status' => 'Submitted · ' . $manuscript['status']];
    } elseif ($recommend && preg_match('/recommend|approved/i', (string) $recommend['status'])) {
        $s8 = ['state' => 'current', 'status' => 'Ready to submit'];
    } else {
        $s8 = ['state' => 'locked', 'status' => 'Needs final defense recommendation'];
    }
    $steps[] = $s8 + [
        'key' => 'manuscript',
        'title' => 'Final manuscript (Chapters 1-5)',
        'summary' => 'Submit the consolidated manuscript after your adviser recommends you for final defense.',
        'rows' => array_filter([
            'Final defense recommendation' => $recommend ? ((string) $recommend['status'] . ($recommend['recommended_at'] ? ' · ' . smsStmtDate($recommend['recommended_at']) : '')) : '',
            'Manuscript submitted' => $manuscript ? ('v' . (int) $manuscript['version_number'] . ' · ' . smsStmtDate($manuscript['submitted_at'], true)) : '',
            'Final approval' => $finalApproval ? ((string) $finalApproval['status'] . ($finalApproval['approved_by_name'] ? ' by ' . $finalApproval['approved_by_name'] : '') . ($finalApproval['approved_at'] ? ' · ' . smsStmtDate($finalApproval['approved_at']) : '')) : '',
        ], static fn($v) => $v !== ''),
        'list' => null,
        'remarks' => array_values(array_filter([
            !empty($recommend['remarks']) ? ['from' => (string) ($recommend['adviser_name'] ?: 'Adviser'), 'text' => (string) $recommend['remarks'], 'at' => smsStmtDate($recommend['recommended_at'] ?? null)] : null,
            !empty($finalApproval['remarks']) ? ['from' => (string) ($finalApproval['approved_by_name'] ?: 'CRAD'), 'text' => (string) $finalApproval['remarks'], 'at' => smsStmtDate($finalApproval['approved_at'] ?? null)] : null,
        ])),
        'action' => ['label' => 'Open final manuscript', 'path' => '/modules/student-portal/pages/final-manuscript.php'],
        'updated_at' => (string) ($finalApproval['approved_at'] ?? $manuscript['submitted_at'] ?? ''),
    ];

    /* 9. Payment and research clearance --------------------------------------- */
    $clear = smsApiClearance($crad, $user);
    $stages = [];
    foreach ($clear['payments'] as $p) {
        $stages[(string) $p['stage']]['payment'] ??= $p;
    }
    foreach ($clear['clearances'] as $c) {
        $stages[(string) $c['stage']]['clearance'] ??= $c;
    }
    ksort($stages);
    $allCleared = $stages && count(array_filter($stages, static fn($s) => isset($s['clearance']) && preg_match('/approved|cleared|done/i', (string) $s['clearance']['status']))) === count($stages);
    $anyBad = count(array_filter($stages, static fn($s) => smsStmtBad($s['payment']['status'] ?? '') || smsStmtBad($s['clearance']['status'] ?? '')));
    if ($anyBad) {
        $s9 = ['state' => 'attention', 'status' => 'Needs attention'];
    } elseif ($allCleared && isset($stages['research_2'])) {
        $s9 = ['state' => 'done', 'status' => 'Cleared'];
    } elseif ($allCleared) {
        // Research 1 (pre-oral) is cleared; Research 2 opens after the final manuscript.
        $s9 = ['state' => 'waiting', 'status' => 'Research 1 cleared'];
    } elseif ($stages) {
        $s9 = ['state' => 'waiting', 'status' => 'In progress'];
    } else {
        $s9 = ['state' => $s4['state'] === 'done' ? 'current' : 'locked', 'status' => 'Not started'];
    }
    $steps[] = $s9 + [
        'key' => 'clearance',
        'title' => 'College payment and research clearance',
        'summary' => 'Upload your payment, then print, sign and upload the clearance for CRAD approval.',
        'rows' => [],
        'list' => $stages ? [
            'columns' => ['Stage', 'Payment', 'O.R. no.', 'Clearance', 'Updated'],
            'rows' => array_map(static fn($stage, $s) => [
                ucwords(str_replace('_', ' ', (string) $stage)),
                (string) ($s['payment']['status'] ?? 'Not uploaded'),
                (string) ($s['payment']['or_number'] ?? $s['clearance']['or_number'] ?? ''),
                (string) ($s['clearance']['status'] ?? 'Not started'),
                smsStmtDate($s['clearance']['updated_at'] ?? $s['payment']['approved_at'] ?? $s['payment']['uploaded_at'] ?? null),
            ], array_keys($stages), array_values($stages)),
        ] : null,
        'remarks' => array_values(array_filter(array_map(static fn($s) => !empty($s['clearance']['crad_remarks'])
            ? ['from' => 'CRAD', 'text' => (string) $s['clearance']['crad_remarks'], 'at' => smsStmtDate($s['clearance']['updated_at'] ?? null)]
            : (!empty($s['payment']['remarks']) ? ['from' => 'Payment review', 'text' => (string) $s['payment']['remarks'], 'at' => smsStmtDate($s['payment']['approved_at'] ?? null)] : null), $stages))),
        'action' => ['label' => 'Open research clearance', 'path' => '/modules/student-portal/pages/research-clearance.php'],
        'updated_at' => '',
    ];

    foreach ($steps as $i => &$step) {
        $step['no'] = $i + 1;
    }
    unset($step);

    $done = count(array_filter($steps, static fn($s) => $s['state'] === 'done'));
    $next = null;
    foreach ($steps as $s) {
        if (in_array($s['state'], ['attention', 'current', 'waiting'], true)) {
            $next = $s;
            break;
        }
    }
    $nextText = 'All steps are complete.';
    if ($next) {
        $nextText = match ($next['state']) {
            'attention' => $next['title'] . ': ' . $next['status'] . '. Check the remarks and resubmit.',
            'current' => $next['title'] . ': this is your next step.',
            default => $next['title'] . ': ' . $next['status'] . '.',
        };
    }

    return [
        'student' => [
            'name' => (string) ($profile['name'] ?? $user['full_name']),
            'student_id' => (string) ($profile['student_id'] ?? $user['student_id'] ?? ''),
            'program' => (string) ($profile['program'] ?? ''),
            'section' => (string) ($profile['section'] ?? ''),
            'school_year' => (string) ($profile['school_year'] ?? ''),
        ],
        'summary' => [
            'research_title' => (string) ($research['title'] ?? ''),
            'group_number' => $isOfficialNumber ? $officialNumber : (string) ($flowGroup['group_number'] ?? ''),
            'adviser' => (string) ($adv['adviser_name'] ?? $registered['adviser_name'] ?? ''),
            'coordinator' => (string) ($coord['coordinator_name'] ?? $registered['coordinator_name'] ?? ''),
            'overall_status' => (string) ($research['status'] ?? ''),
            'progress' => round($progress),
            'steps_done' => $done,
            'steps_total' => count($steps),
            'next_step' => $next['key'] ?? null,
            'next_text' => $nextText,
        ],
        'steps' => $steps,
        'generated_at' => date('c'),
    ];
}

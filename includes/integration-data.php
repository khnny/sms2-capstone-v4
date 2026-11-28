<?php
/**
 * SMS 2 - Read models for the integration API (api/v1).
 *
 * Every function reads data for ONE student: the user named in the access
 * token. Group lookups reuse the same CRAD helper the existing Student Portal
 * pages use (chapterRegisteredStudentGroup), so both portals show the same
 * group. Only plain fields are returned: no signatures, file blobs or
 * internal tokens.
 */
declare(strict_types=1);

require_once __DIR__ . '/../modules/crad/config/config.php';
require_once __DIR__ . '/../modules/crad/includes/chapter-evaluation-workflow.php';
require_once __DIR__ . '/../modules/student-portal/includes/student-profile.php';
require_once __DIR__ . '/announcements.php';
require_once __DIR__ . '/notifications.php';

/** Fill the in-memory session so existing helpers resolve the token's user. */
function smsApiActAsUser(array $user): void
{
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['full_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_role_key'] = (string) $user['role_key'];
    $_SESSION['user_role'] = (string) ($user['role_label'] ?? $user['role_key']);
    $_SESSION['must_change_password'] = 0;
    if (!empty($user['student_id'])) {
        $_SESSION['student_id'] = (string) $user['student_id'];
    } else {
        unset($_SESSION['student_id']);
    }
}

function smsApiIsoDate(?string $value): ?string
{
    if ($value === null || trim($value) === '' || str_starts_with($value, '0000')) {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? date('c', $ts) : null;
}

function smsApiStudentProfile(array $user): array
{
    $profile = studentPortalLoadProfile(
        db(),
        (int) $user['id'],
        (string) ($user['student_id'] ?? ''),
        (string) $user['full_name'],
        (string) $user['email']
    );
    $keep = ['student_id', 'name', 'email', 'program', 'year_level', 'section', 'semester', 'school_year',
        'standing', 'mobile', 'address', 'guardian', 'guardian_contact'];
    $out = ['user_id' => (int) $user['id']];
    foreach ($keep as $key) {
        if (array_key_exists($key, $profile)) {
            $out[$key] = $profile[$key] === null ? null : (string) $profile[$key];
        }
    }
    $out['enrollment_status'] = isset($profile['status']) ? (string) $profile['status'] : null;
    $out['name'] = $out['name'] ?? (string) $user['full_name'];
    $out['email'] = $out['email'] ?? (string) $user['email'];
    return $out;
}

function smsApiLatestTitleApproval(PDO $crad, array $user): ?array
{
    $stmt = $crad->prepare(
        "SELECT id, proposed_title, proposal_number, department, status, adviser_name, coordinator_status, crad_status,
                adviser_remarks, coordinator_remarks, sent_at, reviewed_at, coordinator_reviewed_at, crad_reviewed_at, updated_at
         FROM `crad_title_approvals`
         WHERE (:sid <> '' AND student_id = :sid2) OR (:uid > 0 AND student_user_id = :uid2)
         ORDER BY id DESC LIMIT 1"
    );
    $sid = strtoupper(trim((string) ($user['student_id'] ?? '')));
    $uid = (int) $user['id'];
    $stmt->execute([':sid' => $sid, ':sid2' => $sid, ':uid' => $uid, ':uid2' => $uid]);
    return $stmt->fetch() ?: null;
}

/** Same status rules as modules/student-portal/student-portal-page.php. */
function smsApiTitleStatusLabel(?array $t): string
{
    if (!$t) {
        return 'Not Started';
    }
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
    if (strcasecmp($title, 'Pending') === 0 || !empty($t['sent_at'])) {
        return 'Document Packet Sent';
    }
    return 'Draft';
}

/** The student's research group, or null. */
function smsApiStudentGroup(PDO $crad, array $user): ?array
{
    static $cache = [];
    $key = (int) $user['id'];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $group = null;
    try {
        $group = chapterRegisteredStudentGroup($crad);
    } catch (Throwable $e) {
        error_log('API group lookup failed: ' . $e->getMessage());
    }
    if (!$group) {
        // Groups not yet fully registered (no coordinator/adviser assignment yet).
        $sid = strtoupper(trim((string) ($user['student_id'] ?? '')));
        if ($sid !== '') {
            $stmt = $crad->prepare(
                "SELECT rg.*, NULL AS research_plan_id, NULL AS adviser_name, NULL AS adviser_email, NULL AS coordinator_name
                 FROM `crad_research_groups` rg
                 WHERE rg.leader_id = ? OR EXISTS (
                     SELECT 1 FROM `crad_research_group_members` m WHERE m.research_group_id = rg.id AND m.student_id = ?
                 )
                 ORDER BY rg.id DESC LIMIT 1"
            );
            $stmt->execute([$sid, $sid]);
            $group = $stmt->fetch() ?: null;
        }
    }
    if ($group && empty($group['research_plan_id'])) {
        $plan = $crad->prepare('SELECT id FROM `crad_research_plans` WHERE research_group_id = ? ORDER BY id DESC LIMIT 1');
        $plan->execute([(int) $group['id']]);
        $group['research_plan_id'] = $plan->fetchColumn() ?: null;
    }
    return $cache[$key] = $group;
}

function smsApiResearchSummary(PDO $crad, array $user): array
{
    $title = smsApiLatestTitleApproval($crad, $user);
    $group = smsApiStudentGroup($crad, $user);
    $plan = null;
    if ($group && !empty($group['research_plan_id'])) {
        $stmt = $crad->prepare('SELECT id, current_stage, overall_progress, status, start_date, target_completion_date, final_defense_recommended FROM `crad_research_plans` WHERE id = ?');
        $stmt->execute([(int) $group['research_plan_id']]);
        $plan = $stmt->fetch() ?: null;
    }
    $updated = $title ? ($title['crad_reviewed_at'] ?: $title['coordinator_reviewed_at'] ?: $title['reviewed_at'] ?: $title['sent_at'] ?: $title['updated_at']) : null;

    return [
        'status' => smsApiTitleStatusLabel($title),
        'title' => $group['research_title'] ?? ($title['proposed_title'] ?? null),
        'updated_at' => smsApiIsoDate($updated),
        'title_approval' => $title ? [
            'id' => (int) $title['id'],
            'proposal_number' => $title['proposal_number'],
            'proposed_title' => $title['proposed_title'],
            'department' => $title['department'],
            'adviser_status' => $title['status'],
            'coordinator_status' => $title['coordinator_status'],
            'crad_status' => $title['crad_status'],
            'adviser_remarks' => $title['adviser_remarks'],
            'coordinator_remarks' => $title['coordinator_remarks'],
            'sent_at' => smsApiIsoDate($title['sent_at']),
        ] : null,
        'group' => $group ? [
            'id' => (int) $group['id'],
            'group_number' => $group['group_number'],
            'group_name' => $group['group_name'] ?? null,
            'research_title' => $group['research_title'],
            'college_dept' => $group['college_dept'],
            'academic_year' => $group['academic_year'],
            'status' => $group['status'],
            'leader_name' => $group['leader_name'],
            'adviser' => $group['adviser_name'] ?: ($group['adviser'] ?? null),
            'adviser_email' => $group['adviser_email'] ?? null,
            'coordinator' => $group['coordinator_name'] ?? null,
        ] : null,
        'plan' => $plan ? [
            'id' => (int) $plan['id'],
            'current_stage' => $plan['current_stage'],
            'progress' => (float) $plan['overall_progress'],
            'status' => $plan['status'],
            'start_date' => $plan['start_date'],
            'target_completion_date' => $plan['target_completion_date'],
            'final_defense_recommended' => (bool) $plan['final_defense_recommended'],
        ] : null,
    ];
}

function smsApiGroupMembers(PDO $crad, array $user): array
{
    $group = smsApiStudentGroup($crad, $user);
    if (!$group) {
        return [];
    }
    $stmt = $crad->prepare(
        'SELECT student_id, full_name, section, email, is_leader, research_stage
         FROM `crad_research_group_members` WHERE research_group_id = ? ORDER BY is_leader DESC, member_order, id'
    );
    $stmt->execute([(int) $group['id']]);
    return array_map(static fn(array $r): array => [
        'student_id' => $r['student_id'],
        'name' => $r['full_name'],
        'section' => $r['section'],
        'email' => $r['email'],
        'is_leader' => (bool) $r['is_leader'],
        'research_stage' => $r['research_stage'],
    ], $stmt->fetchAll());
}

function smsApiTimeline(PDO $crad, array $user): array
{
    $group = smsApiStudentGroup($crad, $user);
    if (!$group || empty($group['research_plan_id'])) {
        return [];
    }
    $stmt = $crad->prepare(
        'SELECT id, milestone_name, description, milestone_order, progress_percentage, weight, status, start_date, target_date, completed_at, adviser_remarks
         FROM `crad_research_milestones` WHERE research_plan_id = ? ORDER BY milestone_order, id'
    );
    $stmt->execute([(int) $group['research_plan_id']]);
    return array_map(static fn(array $r): array => [
        'id' => (int) $r['id'],
        'name' => $r['milestone_name'],
        'description' => $r['description'],
        'order' => (int) $r['milestone_order'],
        'progress' => (float) $r['progress_percentage'],
        'weight' => $r['weight'] !== null ? (float) $r['weight'] : null,
        'status' => $r['status'],
        'start_date' => $r['start_date'],
        'target_date' => $r['target_date'],
        'completed_at' => smsApiIsoDate($r['completed_at']),
        'adviser_remarks' => $r['adviser_remarks'],
    ], $stmt->fetchAll());
}

function smsApiSubmissions(PDO $crad, array $user): array
{
    $group = smsApiStudentGroup($crad, $user);
    if (!$group) {
        return ['chapters' => [], 'progress_updates' => []];
    }
    $chapters = $crad->prepare(
        'SELECT id, chapter_number, version_number, status, submitted_by_name, original_name, submitted_at, reviewed_at
         FROM `crad_chapter_submissions` WHERE research_group_id = ? ORDER BY chapter_number, version_number DESC, id DESC'
    );
    $chapters->execute([(int) $group['id']]);
    $updates = $crad->prepare(
        'SELECT id, update_title, milestone_status, previous_progress, new_progress, submitted_by_name, submitted_at
         FROM `crad_research_progress_updates` WHERE research_group_id = ? ORDER BY submitted_at DESC, id DESC LIMIT 100'
    );
    $updates->execute([(int) $group['id']]);
    return [
        'chapters' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'chapter' => (int) $r['chapter_number'],
            'version' => (int) $r['version_number'],
            'status' => $r['status'],
            'file_name' => $r['original_name'],
            'submitted_by' => $r['submitted_by_name'],
            'submitted_at' => smsApiIsoDate($r['submitted_at']),
            'reviewed_at' => smsApiIsoDate($r['reviewed_at']),
        ], $chapters->fetchAll()),
        'progress_updates' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'title' => $r['update_title'],
            'milestone_status' => $r['milestone_status'],
            'progress_from' => $r['previous_progress'] !== null ? (float) $r['previous_progress'] : null,
            'progress_to' => $r['new_progress'] !== null ? (float) $r['new_progress'] : null,
            'submitted_by' => $r['submitted_by_name'],
            'submitted_at' => smsApiIsoDate($r['submitted_at']),
        ], $updates->fetchAll()),
    ];
}

function smsApiFeedback(PDO $crad, array $user): array
{
    $group = smsApiStudentGroup($crad, $user);
    if (!$group) {
        return [];
    }
    $out = [];
    $progress = $crad->prepare(
        'SELECT f.id, f.adviser_name, f.feedback_text, f.new_milestone_status, f.feedback_type, f.created_at, u.update_title
         FROM `crad_research_progress_feedback` f
         JOIN `crad_research_progress_updates` u ON u.id = f.progress_update_id
         WHERE u.research_group_id = ? ORDER BY f.created_at DESC LIMIT 100'
    );
    $progress->execute([(int) $group['id']]);
    foreach ($progress->fetchAll() as $r) {
        $out[] = [
            'source' => 'progress_update',
            'id' => (int) $r['id'],
            'from' => $r['adviser_name'],
            'about' => $r['update_title'],
            'text' => $r['feedback_text'],
            'result' => $r['new_milestone_status'],
            'type' => $r['feedback_type'],
            'created_at' => smsApiIsoDate($r['created_at']),
        ];
    }
    $chapters = $crad->prepare(
        'SELECT e.id, e.evaluator_name, e.overall_feedback, e.result, e.overall_score, e.evaluated_at, e.created_at, s.chapter_number, s.version_number
         FROM `crad_chapter_evaluations` e
         JOIN `crad_chapter_submissions` s ON s.id = e.submission_id
         WHERE s.research_group_id = ? ORDER BY e.created_at DESC LIMIT 100'
    );
    $chapters->execute([(int) $group['id']]);
    foreach ($chapters->fetchAll() as $r) {
        $out[] = [
            'source' => 'chapter_evaluation',
            'id' => (int) $r['id'],
            'from' => $r['evaluator_name'],
            'about' => 'Chapter ' . (int) $r['chapter_number'] . ' (v' . (int) $r['version_number'] . ')',
            'text' => $r['overall_feedback'],
            'result' => $r['result'],
            'score' => $r['overall_score'] !== null ? (float) $r['overall_score'] : null,
            'created_at' => smsApiIsoDate($r['evaluated_at'] ?: $r['created_at']),
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
    return $out;
}

function smsApiDefenses(PDO $crad, array $user): array
{
    $group = smsApiStudentGroup($crad, $user);
    if (!$group) {
        return [];
    }
    $stmt = $crad->prepare(
        "SELECT ds.id, ds.defense_type, ds.status, ds.defense_datetime, ds.defense_end_datetime, ds.venue,
                COALESCE(v.venue_name, ds.venue) AS venue_name, ds.panel_chair, ds.panel_members, ds.adviser_name, ds.research_title
         FROM `crad_research_defense_schedules` ds
         LEFT JOIN `crad_research_venues` v ON v.id = ds.venue_id
         WHERE ds.research_group_id = ? OR (ds.research_group_id IS NULL AND ds.group_number = ?)
         ORDER BY ds.defense_datetime DESC, ds.id DESC"
    );
    $stmt->execute([(int) $group['id'], (string) $group['group_number']]);
    $panel = $crad->prepare(
        "SELECT panel_name, assignment_status, defense_phase FROM `crad_research_panel_assignments`
         WHERE defense_schedule_id = ? ORDER BY id"
    );
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $panel->execute([(int) $r['id']]);
        $members = array_map(static fn(array $p): array => [
            'name' => $p['panel_name'],
            'status' => $p['assignment_status'],
        ], $panel->fetchAll());
        if (!$members && trim((string) $r['panel_members']) !== '') {
            foreach (preg_split('/\s*[,;\n]\s*/', (string) $r['panel_members']) ?: [] as $name) {
                if ($name !== '') {
                    $members[] = ['name' => $name, 'status' => null];
                }
            }
        }
        $out[] = [
            'id' => (int) $r['id'],
            'type' => $r['defense_type'],
            'status' => $r['status'],
            'starts_at' => smsApiIsoDate($r['defense_datetime']),
            'ends_at' => smsApiIsoDate($r['defense_end_datetime']),
            'venue' => $r['venue_name'],
            'panel_chair' => $r['panel_chair'],
            'panel' => $members,
            'adviser' => $r['adviser_name'],
            'research_title' => $r['research_title'],
        ];
    }
    return $out;
}

function smsApiClearance(PDO $crad, array $user): array
{
    $group = smsApiStudentGroup($crad, $user);
    if (!$group) {
        return ['clearances' => [], 'payments' => []];
    }
    $clear = $crad->prepare(
        'SELECT id, research_stage, status, or_number, adviser_signed_at, crad_signed_at, crad_remarks, mis_verified, aa_verified, sent_at, updated_at
         FROM `crad_research_services_clearances` WHERE research_group_id = ? ORDER BY research_stage, id DESC'
    );
    $clear->execute([(int) $group['id']]);
    $pay = $crad->prepare(
        'SELECT id, research_stage, status, or_number, remarks, approved_by_name, approved_at, created_at
         FROM `crad_research_clearance_payments` WHERE research_group_id = ? ORDER BY research_stage, id DESC'
    );
    $pay->execute([(int) $group['id']]);
    return [
        'clearances' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'stage' => $r['research_stage'],
            'status' => $r['status'],
            'or_number' => $r['or_number'],
            'adviser_signed' => $r['adviser_signed_at'] !== null,
            'crad_signed' => $r['crad_signed_at'] !== null,
            'crad_remarks' => $r['crad_remarks'],
            'mis_verified' => (bool) $r['mis_verified'],
            'aa_verified' => (bool) $r['aa_verified'],
            'updated_at' => smsApiIsoDate($r['updated_at']),
        ], $clear->fetchAll()),
        'payments' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'stage' => $r['research_stage'],
            'status' => $r['status'],
            'or_number' => $r['or_number'],
            'remarks' => $r['remarks'],
            'approved_by' => $r['approved_by_name'],
            'approved_at' => smsApiIsoDate($r['approved_at']),
            'uploaded_at' => smsApiIsoDate($r['created_at']),
        ], $pay->fetchAll()),
    ];
}

function smsApiAnnouncements(array $user): array
{
    $rows = smsAnnouncementPublicRows(smsAnnouncementFetch(true, 30, (string) $user['role_key']));
    return array_map(static fn(array $r): array => [
        'id' => $r['id'],
        'title' => $r['title'],
        'body' => $r['body'],
        'category' => $r['category'],
        'posted_by' => $r['posted_by'],
        'posted_at' => $r['posted_at'],
        'expires_at' => $r['expires_at'] !== '' ? $r['expires_at'] : null,
    ], $rows);
}

function smsApiNotifications(): array
{
    $items = smsNotificationPayloadForCurrentUser();
    return array_map(static fn(array $n): array => [
        'id' => (int) ($n['id'] ?? 0),
        'batch_key' => (string) ($n['batch_key'] ?? ''),
        'title' => (string) ($n['label'] ?? 'Notification'),
        'body' => (string) ($n['body'] ?? ''),
        'is_unread' => !empty($n['is_unread']),
        'time' => (string) ($n['time'] ?? ''),
    ], array_values($items));
}

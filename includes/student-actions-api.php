<?php
/**
 * SMS 2 - Student actions for the Integration API (used by the separate Student Portal).
 *
 * Reuses the same functions as the SMS 2 student pages, so every rule (adviser
 * approval, chapter order, revision gate, final defense recommendation, file
 * checks, notifications) stays in one place. The API fills $_SESSION from the
 * access token first (smsApiActAsUser), which these helpers read.
 */
declare(strict_types=1);

require_once ROOT_PATH . '/includes/uploads.php';
require_once ROOT_PATH . '/modules/crad/includes/chapter-evaluation-workflow.php';
require_once ROOT_PATH . '/modules/crad/includes/research-progress-helpers.php';
require_once ROOT_PATH . '/modules/crad/includes/final-phase-helpers.php';

/** Chapter 1-3 submission state: which chapters can be sent now, and the latest version of each. */
function smsApiChapterState(): array
{
    $crad = chapterDb();
    $group = chapterRegisteredStudentGroup($crad);
    if (!$group) {
        return ['available' => false, 'message' => chapterSubmissionUnavailableMessage(), 'max_mb' => 10, 'chapters' => []];
    }
    $latest = [];
    foreach (chapterLatestSubmissionsForGroup($crad, (int) $group['id']) as $row) {
        $latest[(int) $row['chapter_number']] = $row;
    }
    $eligibility = chapterSubmissionEligibility($crad, (int) $group['id']);
    $chapters = [];
    foreach (chapterAllowedChapters() as $num => $label) {
        $current = $latest[$num] ?? null;
        $eligible = !empty($eligibility[$num]['eligible']);
        $canRevise = $current && (string) $current['status'] === 'Needs Revision';
        $canSubmit = $eligible && (!$current || $canRevise);
        $reason = '';
        if (!$eligible) {
            $reason = (string) ($eligibility[$num]['message'] ?? 'Adviser Approval Required');
        } elseif ($current && !$canRevise) {
            $reason = 'Version ' . (int) $current['version_number'] . ' is ' . strtolower((string) $current['status']) . '.';
        }
        $chapters[] = [
            'number' => (int) $num,
            'label' => (string) $label,
            'can_submit' => $canSubmit,
            'next_version' => $current ? (int) $current['version_number'] + 1 : 1,
            'reason' => $reason,
            'latest' => $current ? [
                'version' => (int) $current['version_number'],
                'status' => (string) $current['status'],
                'submitted_at' => smsApiIsoDate($current['submitted_at'] ?? null),
                'updated_at' => smsApiIsoDate($current['updated_at'] ?? null),
            ] : null,
        ];
    }
    return [
        'available' => true,
        'message' => '',
        'group_number' => (string) ($group['group_number'] ?? ''),
        'max_mb' => 10,
        'chapters' => $chapters,
    ];
}

/** Submit one chapter file. $token is a client-made 64-hex string that blocks double submits. */
function smsApiChapterSubmit(int $chapter, array $file, string $notes, string $token): array
{
    $crad = chapterDb();
    $group = chapterRegisteredStudentGroup($crad);
    if (!$group) {
        return ['ok' => false, 'status' => 409, 'error' => 'not_available', 'message' => chapterSubmissionUnavailableMessage()];
    }
    $result = chapterSubmitDocument($crad, $group, $chapter, $file, mb_substr($notes, 0, 2000), $token);
    if (empty($result['ok'])) {
        return ['ok' => false, 'status' => 422, 'error' => 'not_submitted', 'message' => (string) ($result['error'] ?? 'Unable to submit document.')];
    }
    return ['ok' => true, 'version' => (int) $result['version'], 'submission_id' => (int) $result['submission_id']];
}

function smsApiManuscriptGroupId(PDO $crad): int
{
    $group = rpGetRegisteredResearchGroup($crad, trim((string) ($_SESSION['student_id'] ?? '')), (int) ($_SESSION['user_id'] ?? 0));
    return (int) ($group['id'] ?? 0);
}

/** Final manuscript state: eligible or not, and every version sent. */
function smsApiManuscriptState(): array
{
    $crad = getCradDatabaseConnection();
    finalPhaseEnsureSchema($crad);
    $groupId = smsApiManuscriptGroupId($crad);
    if ($groupId <= 0) {
        return ['available' => false, 'eligible' => false, 'can_submit' => false, 'message' => 'Your research group is not officially registered yet.', 'max_mb' => 20, 'submissions' => []];
    }
    $eligible = fpIsRecommendedForFinalDefense($crad, $groupId);
    $latest = fpGetLatestManuscriptSubmission($crad, $groupId);
    $canSubmit = $eligible && smsApiManuscriptMaySubmit($latest);
    $stmt = $crad->prepare('SELECT version_number, status, original_name, submitted_at, reviewed_at FROM `crad_manuscript_submissions` WHERE research_group_id = ? ORDER BY version_number DESC');
    $stmt->execute([$groupId]);
    $rows = array_map(static fn(array $r): array => [
        'version' => (int) $r['version_number'],
        'status' => (string) $r['status'],
        'file_name' => (string) $r['original_name'],
        'submitted_at' => smsApiIsoDate($r['submitted_at'] ?? null),
        'reviewed_at' => smsApiIsoDate($r['reviewed_at'] ?? null),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    return [
        'available' => true,
        'eligible' => $eligible,
        'can_submit' => $canSubmit,
        'message' => !$eligible
            ? 'Not yet eligible. Your adviser must recommend your group for Final Defense first.'
            : ($canSubmit ? '' : 'Version ' . (int) $latest['version_number'] . ' is ' . strtolower((string) $latest['status']) . '. You can send a new version when it is returned for revision.'),
        'max_mb' => 20,
        'submissions' => $rows,
    ];
}

/** A new version is accepted only when there is none yet or the latest one was returned for revision. */
function smsApiManuscriptMaySubmit(?array $latest): bool
{
    return !$latest || (string) $latest['status'] === 'For Revision';
}

function smsApiManuscriptSubmit(array $file, string $notes): array
{
    $crad = getCradDatabaseConnection();
    finalPhaseEnsureSchema($crad);
    $groupId = smsApiManuscriptGroupId($crad);
    $latest = $groupId > 0 ? fpGetLatestManuscriptSubmission($crad, $groupId) : null;
    if (!smsApiManuscriptMaySubmit($latest)) {
        return ['ok' => false, 'status' => 409, 'error' => 'not_submitted', 'message' => 'Your latest manuscript (version ' . (int) $latest['version_number'] . ') is ' . strtolower((string) $latest['status']) . '. Send a new version only after it is returned for revision.'];
    }
    $result = fpSubmitManuscript($crad, $groupId, $file, mb_substr($notes, 0, 2000));
    if (empty($result['ok'])) {
        return ['ok' => false, 'status' => 422, 'error' => 'not_submitted', 'message' => (string) ($result['error'] ?? 'Upload failed.')];
    }
    return ['ok' => true, 'version' => (int) $result['version'], 'submission_id' => (int) $result['submission_id']];
}

/* ===================================================================== *
 * More CRAD student steps for the Student Portal. Each pair is:
 *   smsApiXxxState()   what the student sees (GET)
 *   smsApiXxxSubmit()  the student's action (POST), same rules as the SMS 2 page
 * Write results: ['ok' => true, ...] or ['ok' => false, 'status', 'error', 'message'].
 * ===================================================================== */

function smsApiFail(int $status, string $message, string $error = 'not_submitted'): array
{
    return ['ok' => false, 'status' => $status, 'error' => $error, 'message' => $message];
}

function smsApiStudentId(): string
{
    return trim((string) ($_SESSION['student_id'] ?? ''));
}

/* ------------------------------------------------------- research group */

function smsApiGroupState(): array
{
    require_once ROOT_PATH . '/modules/crad/config/config.php';
    require_once ROOT_PATH . '/modules/crad/includes/research-group-flow.php';
    $crad = cradDb();
    $sid = smsApiStudentId();
    $group = ($crad && $sid !== '') ? cradRgFlowGetStudentGroup($crad, $sid) : null;
    $members = $group ? cradRgFlowGetMembers($crad, (int) $group['id']) : [];
    $flow = strtolower((string) ($group['flow_status'] ?? 'draft'));
    $locked = in_array($flow, ['approved', 'ready_for_assignment', 'pending_dh_approval', 'pending_incomplete_approval'], true);
    return [
        'has_group' => (bool) $group,
        'flow_status' => $group ? $flow : '',
        'status_label' => $group ? cradRgFlowOverallStatusLabel($flow) : 'Not submitted',
        'can_edit' => !$locked || $flow === 'rejected' || $flow === 'draft',
        'min_members' => cradRgFlowMinMembers(),
        'max_members' => cradRgFlowMaxMembers(),
        'group_number' => (string) ($group['group_number'] ?? ''),
        'incomplete_reason' => (string) ($group['incomplete_reason'] ?? ''),
        'remarks' => (string) ($group['dh_remarks'] ?? $group['remarks'] ?? ''),
        'members' => array_map(static fn(array $m): array => [
            'full_name' => (string) ($m['full_name'] ?? ''),
            'student_id' => (string) ($m['student_id'] ?? ''),
            'section' => (string) ($m['section'] ?? ''),
            'is_leader' => !empty($m['is_leader']),
        ], $members),
    ];
}

/** @param list<array{full_name: string, student_id: string, section: string}> $members */
function smsApiGroupSubmit(array $members, string $reason, bool $submit): array
{
    require_once ROOT_PATH . '/modules/crad/config/config.php';
    require_once ROOT_PATH . '/modules/crad/includes/research-group-flow.php';
    $crad = cradDb();
    $sid = smsApiStudentId();
    if (!$crad || $sid === '') {
        return smsApiFail(409, 'Your student number is missing from your SMS 2 account.', 'not_available');
    }
    if (empty(smsApiGroupState()['can_edit'])) {
        return smsApiFail(409, 'Your research group was already submitted and is being processed, so it cannot be changed here.');
    }
    $payload = [];
    foreach (array_slice($members, 0, 20) as $m) {
        $payload[] = [
            'full_name' => mb_substr(trim((string) ($m['full_name'] ?? '')), 0, 160),
            'student_id' => mb_substr(trim((string) ($m['student_id'] ?? '')), 0, 40),
            'section' => mb_substr(trim((string) ($m['section'] ?? '')), 0, 60),
            'email' => '',
            'or_number' => '',
        ];
    }
    $result = cradRgFlowSaveSubmission(
        $crad, $sid, (string) ($_SESSION['user_name'] ?? ''), (string) ($_SESSION['user_email'] ?? ''),
        (string) ($_SESSION['department'] ?? ''), (int) ($_SESSION['user_id'] ?? 0),
        $payload, mb_substr(trim($reason), 0, 1000), $submit
    );
    if (empty($result['ok'])) {
        return smsApiFail(422, (string) ($result['message'] ?? 'Save failed.'));
    }
    if (function_exists('logActivity')) {
        logActivity('research_group_' . ($submit ? 'submit' : 'draft'), (string) $result['message'] . ' (Student Portal)');
    }
    return ['ok' => true, 'message' => (string) $result['message']];
}

/* ------------------------------------------------- title approval form */

function smsApiTitleState(): array
{
    require_once ROOT_PATH . '/modules/crad/config/config.php';
    require_once ROOT_PATH . '/modules/crad/includes/title-approval-submit.php';
    $crad = getCradDatabaseConnection();
    $sid = smsApiStudentId();
    $stmt = $crad->prepare('SELECT * FROM `crad_title_approvals` WHERE student_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $gate = cradRgFlowTitleApprovalGate($crad, $sid);
    $official = cradStudentOfficialAssignees($crad, $sid);
    $members = [];
    $rg = cradRgFlowGetStudentGroup($crad, $sid);
    if ($rg) {
        $members = cradRgFlowMembersAsTitleJson($crad, (int) $rg['id']);
    }
    if ($members === [] && !empty($gate['members'])) {
        $members = $gate['members'];
    }
    $returned = $row && ((string) $row['status'] === 'Returned' || (string) ($row['coordinator_status'] ?? '') === 'Returned');
    $assigned = trim((string) ($official['adviser_name'] ?? '')) !== '' && trim((string) ($official['coordinator_name'] ?? '')) !== '' && !empty($official['ready']);
    $canSubmit = !empty($gate['ok']) && $assigned && (!$row || $returned);
    return [
        'can_submit' => $canSubmit,
        'is_resubmit' => $returned,
        'message' => $canSubmit ? '' : (!$row || $returned
            ? (empty($gate['ok']) ? (string) $gate['message'] : 'Waiting for coordinator and adviser assignment or confirmation.')
            : 'Your title approval form was sent. You will be notified when it is reviewed.'),
        'adviser' => (string) ($official['adviser_name'] ?? ''),
        'coordinator' => (string) ($official['coordinator_name'] ?? ''),
        'members' => array_map(static fn($m): array => ['name' => (string) ($m[0] ?? $m['name'] ?? ''), 'section' => (string) ($m[1] ?? $m['section'] ?? '')], $members),
        'options' => cradTitleFormOptions(),
        'submission' => $row ? [
            'id' => (int) $row['id'],
            'title' => (string) $row['proposed_title'],
            'department' => (string) ($row['department'] ?? ''),
            'discipline' => (string) ($row['discipline_cluster'] ?? ''),
            'primary_sdg' => (string) ($row['primary_sdg'] ?? ''),
            'research_agenda' => (string) ($row['research_agenda'] ?? ''),
            'justification' => (string) ($row['sdg_justification'] ?? ''),
            'adviser_status' => (string) $row['status'],
            'coordinator_status' => (string) ($row['coordinator_status'] ?? ''),
            'crad_status' => (string) ($row['crad_status'] ?? ''),
            'adviser_remarks' => (string) ($row['adviser_remarks'] ?? ''),
            'coordinator_remarks' => (string) ($row['coordinator_remarks'] ?? ''),
            'sent_at' => smsApiIsoDate($row['sent_at'] ?? null),
            'reviewed_at' => smsApiIsoDate($row['reviewed_at'] ?? null),
        ] : null,
    ];
}

function smsApiTitleSubmit(array $input): array
{
    require_once ROOT_PATH . '/modules/crad/config/config.php';
    require_once ROOT_PATH . '/modules/crad/includes/title-approval-submit.php';
    $options = cradTitleFormOptions();
    $agenda = trim((string) ($input['research_agenda'] ?? ''));
    if (str_starts_with($agenda, 'Others')) {
        $agenda = trim((string) ($input['research_agenda_others'] ?? ''));
        if ($agenda === '') {
            return smsApiFail(422, 'Please specify the other research agenda.');
        }
    } elseif (!in_array($agenda, $options['research_agendas'], true)) {
        return smsApiFail(422, 'Choose a research agenda.');
    }
    if (!in_array((string) ($input['discipline'] ?? ''), $options['discipline_clusters'], true)) {
        return smsApiFail(422, 'Choose a discipline cluster.');
    }
    if (!in_array((string) ($input['department'] ?? ''), $options['departments'], true)) {
        return smsApiFail(422, 'Choose a department.');
    }
    $sdgOk = false;
    foreach ($options['sdgs'] as $s) {
        $sdgOk = $sdgOk || (string) ($input['primary_sdg'] ?? '') === 'SDG ' . $s['num'] . ' — ' . $s['title'];
    }
    if (!$sdgOk) {
        return smsApiFail(422, 'Choose one primary SDG.');
    }
    $state = smsApiTitleState();
    $result = cradTitleSendToAdviser(getCradDatabaseConnection(), [
        'student_id' => smsApiStudentId(),
        'student_user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'student_name' => (string) ($_SESSION['user_name'] ?? ''),
        'research_title' => mb_strtoupper(mb_substr(trim((string) ($input['research_title'] ?? '')), 0, 500)),
        'department' => (string) $input['department'],
        'submission_date' => date('Y-m-d'),
        'discipline' => (string) $input['discipline'],
        'primary_sdg' => (string) $input['primary_sdg'],
        'research_agenda' => mb_substr($agenda, 0, 255),
        'justification' => mb_substr(trim((string) ($input['justification'] ?? '')), 0, 5000),
        // CRAD uses the Research Group members as the source of truth.
        'members' => array_map(static fn(array $m): array => [$m['name'], $m['section'], ''], $state['members']),
        'submission_id' => (int) ($state['submission']['id'] ?? 0),
    ]);
    $body = $result['body'];
    if (empty($body['ok'])) {
        return smsApiFail($result['status'] === 200 ? 422 : $result['status'], (string) ($body['message'] ?? 'The form could not be sent.'));
    }
    if (!empty($body['already_sent'])) {
        return smsApiFail(409, 'Your title approval form was already sent. Wait for the adviser to review or return it.');
    }
    return ['ok' => true, 'message' => (string) $body['message'], 'submission_id' => (int) ($body['submission_id'] ?? 0)];
}

/* ---------------------------------------------------- progress updates */

function smsApiProgressGroup(PDO $crad): ?array
{
    return rpGetRegisteredResearchGroup($crad, smsApiStudentId(), (int) ($_SESSION['user_id'] ?? 0));
}

function smsApiProgressState(): array
{
    require_once ROOT_PATH . '/modules/crad/config/config.php';
    $crad = cradDb();
    $group = $crad ? smsApiProgressGroup($crad) : null;
    if (!$group) {
        return ['available' => false, 'message' => 'Research Development is not available. Your group must be registered in the Capstone Group/Student Registry first.', 'milestones' => [], 'history' => []];
    }
    $groupId = (int) $group['id'];
    $plan = rpGetOrCreateResearchPlan($crad, $groupId);
    $milestones = $plan ? rpGetMilestonesWithPendingFlags($crad, (int) $plan['id'], $groupId) : [];
    $stmt = $crad->prepare(
        'SELECT rpu.id, rpu.update_title, rpu.new_progress, rpu.milestone_status, rpu.submitted_at, rm.milestone_name
         FROM `crad_research_progress_updates` rpu LEFT JOIN `crad_research_milestones` rm ON rm.id = rpu.milestone_id
         WHERE rpu.research_group_id = ? ORDER BY rpu.submitted_at DESC LIMIT 20'
    );
    $stmt->execute([$groupId]);
    return [
        'available' => true,
        'message' => '',
        'max_mb' => 10,
        'statuses' => ['Not Started', 'Submitted for Review'],
        'milestones' => array_map(static fn(array $m): array => [
            'id' => (int) $m['id'],
            'name' => (string) $m['milestone_name'],
            'order' => (int) ($m['milestone_order'] ?? 0),
            'status' => (string) ($m['status'] ?? ''),
            'progress' => (float) ($m['progress_percentage'] ?? 0),
            'chapter' => rpMilestoneChapterNumber($m),
            'pending' => !empty($m['has_pending_submission']),
            'can_submit' => (string) ($m['status'] ?? '') !== 'Approved' && empty($m['has_pending_submission']),
        ], $milestones),
        'history' => array_map(static fn(array $r): array => [
            'title' => (string) ($r['update_title'] ?? ''),
            'milestone' => (string) ($r['milestone_name'] ?? ''),
            'progress' => (float) ($r['new_progress'] ?? 0),
            'status' => (string) ($r['milestone_status'] ?? ''),
            'submitted_at' => smsApiIsoDate($r['submitted_at'] ?? null),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
    ];
}

function smsApiProgressSubmit(array $input, array $file): array
{
    require_once ROOT_PATH . '/modules/crad/config/config.php';
    require_once ROOT_PATH . '/modules/crad/includes/research-progress-submit.php';
    $crad = cradDb();
    $group = $crad ? smsApiProgressGroup($crad) : null;
    if (!$group) {
        return smsApiFail(409, 'Your group must be registered in the Capstone Group/Student Registry first.', 'not_available');
    }
    $fields = [];
    foreach (['milestone_id', 'new_progress', 'milestone_status', 'update_title', 'accomplishments', 'problems_blockers', 'next_planned_activity', 'submission_token'] as $k) {
        if (array_key_exists($k, $input)) {
            $fields[$k] = mb_substr((string) $input[$k], 0, 4000);
        }
    }
    $result = rpStudentSubmitProgress($crad, (int) $group['id'], $group, (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['user_name'] ?? ''), $fields, $file);
    if ($result['status'] !== 200 || empty($result['body']['success'])) {
        return smsApiFail($result['status'] === 200 ? 422 : $result['status'], (string) ($result['body']['message'] ?? 'The progress update could not be sent.'));
    }
    return ['ok' => true, 'message' => 'Progress update submitted. Your adviser will review it.', 'update_id' => (int) $result['body']['update_id']];
}

/* --------------------------------------------------- panel application */

/** @return array{0: ?PDO, 1: ?array} the CRAD connection and the student's draft/latest application */
function smsApiPanelApp(): array
{
    require_once ROOT_PATH . '/modules/crad/config/config.php';
    require_once ROOT_PATH . '/modules/crad/includes/research-group-flow.php';
    require_once ROOT_PATH . '/modules/crad/includes/panel-applications.php';
    $pdo = cradDb();
    $sid = strtoupper(smsApiStudentId());
    if (!$pdo || $sid === '') {
        return [$pdo, null];
    }
    cradPanelAppEnsureSchema($pdo);
    $group = chapterRegisteredStudentGroup($pdo);
    if ($group && empty($group['adviser']) && !empty($group['adviser_name'])) {
        $group['adviser'] = $group['adviser_name'];
    }
    $group = $group ?: cradRgFlowGetStudentGroup($pdo, $sid);
    if (!$group) {
        return [$pdo, null];
    }
    return [$pdo, cradPanelAppDraftForGroup($pdo, $group, (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['user_name'] ?? ''))];
}

function smsApiPanelState(): array
{
    [$pdo, $app] = smsApiPanelApp();
    if (!$app) {
        return ['available' => false, 'message' => 'Your research group must be registered before you can apply for a panel.', 'application' => null];
    }
    $docs = [];
    foreach (cradPanelAppDocuments($pdo, (int) $app['id']) as $d) {
        $docs[(string) $d['doc_type']] = ['file_name' => (string) ($d['original_name'] ?? ''), 'uploaded_at' => smsApiIsoDate($d['uploaded_at'] ?? $d['created_at'] ?? null)];
    }
    $types = [];
    foreach (cradPanelAppDocTypes() as $type => $label) {
        $types[] = ['type' => $type, 'label' => $label, 'uploaded' => $docs[$type] ?? null];
    }
    return [
        'available' => true,
        'message' => '',
        'max_mb' => 20,
        'application' => [
            'ref' => (string) $app['application_ref'],
            'status' => (string) $app['status'],
            'status_label' => cradPanelAppStatusLabel((string) $app['status']),
            'editable' => in_array((string) $app['status'], cradPanelAppEditableStatuses(), true),
            'research_title' => (string) ($app['research_title'] ?? ''),
            'adviser_name' => (string) ($app['adviser_name'] ?? ''),
            'members_text' => (string) ($app['members_text'] ?? ''),
            'review_remarks' => (string) ($app['review_remarks'] ?? ''),
            'documents' => $types,
            'history' => array_map(static fn(array $h): array => [
                'status' => (string) $h['to_status'],
                'by' => (string) ($h['actor_name'] ?? ''),
                'remarks' => (string) ($h['remarks'] ?? ''),
                'at' => smsApiIsoDate($h['created_at'] ?? null),
            ], cradPanelAppHistory($pdo, (int) $app['id'])),
        ],
    ];
}

function smsApiPanelSubmit(string $action, array $input, array $file): array
{
    [$pdo, $app] = smsApiPanelApp();
    if (!$app) {
        return smsApiFail(409, 'Your research group must be registered before you can apply for a panel.', 'not_available');
    }
    $id = (int) $app['id'];
    $result = match ($action) {
        'save_details' => cradPanelAppSaveDetails($pdo, $id, [
            'research_title' => (string) ($input['research_title'] ?? ''),
            'adviser_name' => (string) ($input['adviser_name'] ?? ''),
            'members_text' => (string) ($input['members_text'] ?? ''),
        ]),
        'upload_document' => cradPanelAppStoreDocument($pdo, $id, (string) ($input['doc_type'] ?? ''), $file),
        'submit' => cradPanelAppSubmit($pdo, $id, (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['user_name'] ?? '')),
        default => ['ok' => false, 'error' => 'Unknown action.'],
    };
    if (empty($result['ok'])) {
        return smsApiFail(422, (string) ($result['error'] ?? 'Not saved.'));
    }
    return ['ok' => true, 'message' => (string) ($result['message'] ?? 'Saved.')];
}

/* ------------------------------------------------------ college payment */

function smsApiPaymentState(): array
{
    require_once ROOT_PATH . '/modules/crad/includes/research-services-clearance.php';
    $crad = rscDb();
    rscEnsureSchema($crad);
    rcpEnsureSchema($crad);
    $group = chapterRegisteredStudentGroup($crad);
    if (!$group) {
        return ['available' => false, 'message' => 'A registered research group is needed before you can upload a college payment.', 'stages' => []];
    }
    return [
        'available' => true,
        'message' => '',
        'max_mb' => 10,
        'stages' => array_map(static fn(array $r): array => [
            'stage' => (string) $r['research_stage'],
            'label' => (string) $r['stage_label'],
            'status' => (string) $r['status'],
            'status_label' => (string) ($r['status_label'] ?: 'Not uploaded'),
            'or_number' => (string) $r['or_number'],
            'remarks' => (string) $r['remarks'],
            'file_name' => (string) $r['uploaded_original'],
            'updated_at' => smsApiIsoDate($r['updated_at'] ?: null),
            'can_upload' => !empty($r['can_upload']) && $r['locked_reason'] === '',
            'locked_reason' => (string) $r['locked_reason'],
        ], rcpStudentInbox($crad, (int) $group['id'])),
    ];
}

function smsApiPaymentSubmit(string $stage, array $file, string $orNumber): array
{
    require_once ROOT_PATH . '/modules/crad/includes/research-services-clearance.php';
    $crad = rscDb();
    rscEnsureSchema($crad);
    rcpEnsureSchema($crad);
    $group = chapterRegisteredStudentGroup($crad);
    if (!$group) {
        return smsApiFail(409, 'No research group is registered yet.', 'not_available');
    }
    $result = rcpStudentUpload($crad, (int) $group['id'], $file, mb_substr(trim($orNumber), 0, 60), rcpNormalizeStage($stage));
    if (empty($result['ok'])) {
        return smsApiFail(422, (string) ($result['error'] ?? 'Upload failed.'));
    }
    return ['ok' => true, 'message' => 'College payment uploaded. Admin will review it.'];
}

/* -------------------------------------------- research services clearance */

function smsApiClearanceFormState(): array
{
    require_once ROOT_PATH . '/modules/crad/includes/research-services-clearance.php';
    $crad = rscDb();
    rscEnsureSchema($crad);
    $group = chapterRegisteredStudentGroup($crad);
    if (!$group) {
        return ['available' => false, 'message' => 'A registered research group is needed first.', 'stages' => []];
    }
    return [
        'available' => true,
        'message' => '',
        'max_mb' => 10,
        'stages' => array_map(static fn(array $r): array => [
            'id' => (int) ($r['id'] ?? 0),
            'stage' => (string) $r['research_stage'],
            'label' => (string) $r['stage_label'],
            'status' => (string) $r['status'],
            'status_label' => (string) $r['status_label'],
            'or_number' => (string) ($r['or_number'] ?? ''),
            'crad_remarks' => (string) ($r['crad_remarks'] ?? ''),
            'locked_reason' => (string) ($r['locked_reason'] ?? ''),
            'can_upload' => !empty($r['can_student_upload']),
            'file_name' => (string) ($r['uploaded_original'] ?? ''),
            // The printable form, as CRAD renders it. The portal shows it in a sandboxed frame.
            'form_html' => (string) ($r['form_html'] ?? ''),
        ], rscStudentInbox($crad, (int) $group['id'])),
    ];
}

function smsApiClearanceFormSubmit(int $id, array $file): array
{
    require_once ROOT_PATH . '/modules/crad/includes/research-services-clearance.php';
    $crad = rscDb();
    rscEnsureSchema($crad);
    $row = $id > 0 ? rscFindById($crad, $id) : null;
    if (!$row || !rscStudentCanAccess($crad, $row)) {
        return smsApiFail(404, 'That clearance form was not found.', 'not_found');
    }
    $result = rscStudentUploadSigned($crad, $row, $file);
    if (empty($result['ok'])) {
        return smsApiFail(422, (string) ($result['error'] ?? 'Upload failed.'));
    }
    return ['ok' => true, 'message' => 'Signed clearance uploaded. CRAD will review it.'];
}

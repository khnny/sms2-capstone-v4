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

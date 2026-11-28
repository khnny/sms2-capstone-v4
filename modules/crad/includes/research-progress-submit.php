<?php
declare(strict_types=1);

require_once __DIR__ . '/research-progress-helpers.php';
require_once ROOT_PATH . '/includes/uploads.php';

/**
 * Submit one student progress update with duplicate prevention.
 * Shared by the CRAD page endpoint (modules/crad/api/research-progress.php)
 * and the Integration API (POST me/research/progress), so the rules live here once.
 *
 * @return array{status: int, body: array} HTTP status and JSON body
 */
function rpStudentSubmitProgress(PDO $crad, int $groupId, array $researchGroup, int $userId, string $userName, array $input, array $document): array
{
    // Validate required fields
    if (!isset($input['milestone_id']) || !isset($input['new_progress'])) {
        return ['status' => 400, 'body' => ['success' => false, 'message' => 'Missing required fields']];
    }
    
    // Validate submission token (DUPLICATE PREVENTION)
    $submissionToken = $input['submission_token'] ?? '';
    if (empty($submissionToken)) {
        return ['status' => 400, 'body' => ['success' => false, 'message' => 'Submission token required']];
    }
    
    // Check for duplicate token usage
    if (rpIsTokenRecentlyUsed($crad, 'research_progress_updates', $submissionToken, 5)) {
        return ['status' => 409, 'body' => [
            'success' => false, 
            'message' => 'Duplicate submission detected. Please wait before submitting again.',
            'is_duplicate' => true
        ]];
    }
    
    // Get research plan
    $planStmt = $crad->prepare("SELECT * FROM `crad_research_plans` WHERE research_group_id = ? LIMIT 1");
    $planStmt->execute([$groupId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plan) {
        return ['status' => 404, 'body' => ['success' => false, 'message' => 'Research plan not found']];
    }
    
    // Get milestone to update
    $milestoneStmt = $crad->prepare("
        SELECT * FROM `crad_research_milestones` 
        WHERE id = ? AND research_plan_id = ?
        LIMIT 1
    ");
    $milestoneStmt->execute([$input['milestone_id'], $plan['id']]);
    $milestone = $milestoneStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$milestone) {
        return ['status' => 404, 'body' => ['success' => false, 'message' => 'Milestone not found']];
    }

    if ((string) ($milestone['status'] ?? '') === 'Approved') {
        return ['status' => 409, 'body' => ['success' => false, 'message' => 'This milestone is already approved and finalized at 100%.']];
    }

    // PENDING REVIEW GUARD — database-level check (not session-based).
    // Rejects a new submission if this milestone already has an active
    // "Submitted for Review" update that the adviser has not yet acted on.
    $pendingUpdate = rpHasPendingSubmission($crad, $groupId, (int) $input['milestone_id']);
    if ($pendingUpdate) {
        return ['status' => 409, 'body' => [
            'success'           => false,
            'is_pending_review' => true,
            'milestone_name'    => $milestone['milestone_name'],
            'submitted_at'      => $pendingUpdate['submitted_at'],
            'message'           => $milestone['milestone_name'] . ' already has a progress update awaiting Adviser review. Please wait for your adviser to respond before submitting another update.',
        ]];
    }

    $allowedStudentStatuses = ['Not Started', 'Submitted for Review'];
    $milestoneStatus = trim((string) ($input['milestone_status'] ?? 'Not Started'));
    if (!in_array($milestoneStatus, $allowedStudentStatuses, true)) {
        return ['status' => 400, 'body' => ['success' => false, 'message' => 'Invalid milestone status selected. Students may only choose Not Started or Submitted for Review.']];
    }

    $chapterNumber = rpMilestoneChapterNumber($milestone);
    $uploadedDocument = null;
    $uploadedPath = null;
    $requiresDocument = $chapterNumber !== null && $milestoneStatus === 'Submitted for Review';
    if ($requiresDocument || (isset($document['error']) && (int) $document['error'] !== UPLOAD_ERR_NO_FILE)) {
        $upload = smsSecureUpload($document, [
            'subdir' => 'research_progress/g' . $groupId . '/u' . max(0, $userId),
            'max_bytes' => 10 * 1024 * 1024,
            'allowed' => smsUploadAllowedDocuments(),
            'required' => $requiresDocument,
        ]);
        if (empty($upload['ok'])) {
            $chapterLabel = $chapterNumber ? ('Chapter ' . $chapterNumber) : 'milestone';
            $message = $requiresDocument
                ? 'Please upload the ' . $chapterLabel . ' document for Adviser review.'
                : ($upload['error'] ?: 'Upload failed.');
            return ['status' => 400, 'body' => ['success' => false, 'message' => $message]];
        }
        if (!empty($upload['path'])) {
            $uploadedDocument = $upload;
            $uploadedPath = (string) $upload['path'];
        }
    }

    // Prepare progress update data
    $progressData = [
        'research_plan_id' => $plan['id'],
        'research_group_id' => $groupId,
        'milestone_id' => $input['milestone_id'],
        'submitted_by_user_id' => $userId,
        'submitted_by_name' => $userName,
        'update_title' => $input['update_title'] ?? $milestone['milestone_name'] . ' Progress Update',
        'previous_progress' => (float) $milestone['progress_percentage'],
        'new_progress' => (float) $input['new_progress'],
        'milestone_status' => $milestoneStatus,
        'accomplishments' => $input['accomplishments'] ?? null,
        'problems_blockers' => $input['problems_blockers'] ?? null,
        'next_planned_activity' => $input['next_planned_activity'] ?? null,
        'attachment_path' => $uploadedDocument['path'] ?? null,
        'attachment_original_name' => $uploadedDocument['original_name'] ?? null,
        'uploaded_document' => $uploadedDocument,
        'submission_token' => $submissionToken
    ];
    
    // Submit progress update
    $result = rpSubmitProgressUpdate($crad, $progressData);
    
    if (!$result['success']) {
        if ($uploadedPath && is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
        return ['status' => 400, 'body' => $result];
    }
    
    // Create notification for adviser (with duplicate prevention)
    if (!empty($researchGroup['adviser_user_id'])) {
        rpCreateNotification($crad, [
            'recipient_user_id' => $researchGroup['adviser_user_id'],
            'recipient_email' => $researchGroup['adviser_email'] ?? '',
            'recipient_role' => 'adviser',
            'batch_key' => 'progress_update:' . $result['update_id'],
            'notification_type' => 'progress_update',
            'title' => 'New Progress Update',
            'body' => $researchGroup['group_number'] . ' submitted a progress update for ' . $milestone['milestone_name'],
            'related_entity_type' => 'progress_update',
            'related_entity_id' => $result['update_id'],
            'action_url' => BASE_URL . '/modules/faculty/pages/research-progress.php?group=' . $researchGroup['group_number']
        ]);
    }
    
    // Determine the next/current milestone using the actual milestone_order sequence.
    // Re-fetch fresh milestones (post-commit) so the order, statuses, and pending flags are current.
    $freshMilestones = rpGetMilestonesWithPendingFlags($crad, (int) $plan['id'], $groupId);

    $nextMilestoneId  = null;
    $submittedOrder   = (int) ($milestone['milestone_order'] ?? 0);

    // Walk the ordered list and find the first milestone whose order is greater
    // than the one just submitted. This respects the actual DB order without
    // hardcoding any chapter names or IDs.
    foreach ($freshMilestones as $fm) {
        if ((int) ($fm['milestone_order'] ?? 0) > $submittedOrder) {
            $nextMilestoneId = (int) $fm['id'];
            break;
        }
    }

    return ['status' => 200, 'body' => [
        'success'            => true,
        'message'            => 'Progress update submitted successfully',
        'update_id'          => $result['update_id'],
        'next_milestone_id'  => $nextMilestoneId,   // null when no further milestone exists
        'milestones'         => $freshMilestones,    // fresh list for client-side UI refresh
    ]];
}

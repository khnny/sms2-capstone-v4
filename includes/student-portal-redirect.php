<?php
/**
 * Students do every CRAD research step in the standalone Student Portal.
 * When STUDENT_PORTAL_URL is set, a student who opens one of the old research
 * pages in SMS 2 is sent to the same step in the portal. Officers, advisers,
 * panels and coordinators are not affected, nor are API calls, AJAX or POSTs.
 */
declare(strict_types=1);

/** Old SMS 2 student research page (file name) => portal page. */
function smsStudentPortalPageMap(): array
{
    $hub = 'crad/index.php';
    return [
        'research-workspace.php' => $hub,
        'my-research.php' => $hub,
        'research-plan.php' => $hub,
        'milestones.php' => $hub,
        'my-submissions.php' => $hub,
        'submission-history.php' => $hub,
        'submission-status.php' => $hub,
        'adviser-feedback.php' => $hub,
        'submit-documents.php' => $hub,
        'research-group.php' => 'crad/step.php?do=group',
        'research-proposal-submission.php' => 'crad/step.php?do=proposal',
        'submit-chapters.php' => 'crad/step.php?do=chapters',
        'progress-updates.php' => 'crad/step.php?do=progress',
        'panel-application.php' => 'crad/step.php?do=panel',
        'final-manuscript.php' => 'crad/step.php?do=manuscript',
        'college-payment.php' => 'crad/step.php?do=payment',
        'research-clearance.php' => 'crad/step.php?do=clearance',
    ];
}

/** Portal URL for the current request, or null when the page stays in SMS 2. */
function smsStudentPortalTarget(): ?string
{
    $portal = defined('STUDENT_PORTAL_URL') ? (string) STUDENT_PORTAL_URL : '';
    if ($portal === '' || getCurrentUserRoleKey() !== 'student') {
        return null;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        return null;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!str_contains($script, '/modules/student-portal/pages/')) {
        return null;
    }
    $target = smsStudentPortalPageMap()[basename($script)] ?? null;
    return $target === null ? null : $portal . '/' . $target;
}

function smsRedirectStudentToPortal(): void
{
    $target = smsStudentPortalTarget();
    if ($target !== null) {
        header('Location: ' . $target, true, 302);
        exit;
    }
}

<?php
/**
 * CRAD API — Send Title Approval Form to Adviser
 *
 * POST /modules/crad/api/send-to-adviser.php
 * 1. Validates the adviser exists in sms2_users
 * 2. Returns {ok:false, no_account:true} when adviser has no account
 * 3. Inserts into `crad_title_approvals` when all is well
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/title-approval-assignees.php';
require_once __DIR__ . '/../includes/research-group-flow.php';
require_once __DIR__ . '/../includes/title-approval-submit.php';

header('Content-Type: application/json; charset=utf-8');

function saJson(bool $ok, string $message, array $extra = []): never
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

/* ── auth ────────────────────────────────────────────────── */
if (!isAuthenticated()) {
    http_response_code(401);
    saJson(false, 'Not authenticated.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    saJson(false, 'Method not allowed.');
}

/* ── parse body ──────────────────────────────────────────── */
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    saJson(false, 'Invalid JSON body.');
}
if (!csrfVerify((string) ($body['csrf_token'] ?? ''))) {
    http_response_code(403);
    saJson(false, 'Security check failed. Refresh the page and try again.');
}

if (getCurrentUserRoleKey() === 'student') {
    // A student may only send their own form.
    $body['student_id'] = (string) ($_SESSION['student_id'] ?? '');
    $body['student_user_id'] = (int) ($_SESSION['user_id'] ?? 0);
}

try {
    $pdo = getCradDatabaseConnection();
} catch (Throwable $e) {
    http_response_code(503);
    saJson(false, 'Database unavailable: ' . $e->getMessage());
}

$result = cradTitleSendToAdviser($pdo, $body);
if ($result['status'] !== 200) {
    http_response_code($result['status']);
}
echo json_encode($result['body']);

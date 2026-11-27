<?php
/**
 * SMS 2 - Open an SMS 2 page from an integrated app without a second login.
 *
 * GET /login/sso-launch.php?ticket=...
 * The app gets the one-time ticket (60 seconds) from POST /api/v1/auth/ticket
 * using the student's access token. The page to open is fixed when the ticket
 * is created and must be a path inside SMS 2.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/integration.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$ticket = trim((string) ($_GET['ticket'] ?? ''));
try {
    $result = smsApiConsumeSsoTicket($ticket);
} catch (Throwable $e) {
    error_log('SSO launch failed: ' . $e->getMessage());
    $result = null;
}
if ($result === null) {
    header('Location: ' . BASE_URL . '/login/login.php');
    exit;
}

$user = $result['user'];
$current = isAuthenticated() ? (int) $_SESSION['user_id'] : 0;
if ($current !== (int) $user['id']) {
    // Same effect as a successful login for this user (the ticket came from a
    // token issued after a full SMS 2 login, including any second factor).
    $_SESSION = [];
    smsCompleteLoginSession($user);
}

header('Location: ' . BASE_URL . $result['next_path']);
exit;

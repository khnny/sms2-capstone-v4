<?php
/**
 * Marks one of the signed-in user's deadline reminders as read (POST, CSRF protected).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/deadline-reminders.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrfVerify()) {
    http_response_code(400);
    exit('Invalid request.');
}

smsDeadlineReminderMarkRead((int) getCurrentUserId(), (int) ($_POST['reminder_id'] ?? 0));

$returnTo = (string) ($_POST['return_to'] ?? '');
$basePath = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?? '');
$isInsideApp = str_starts_with($returnTo, BASE_URL)
    || (str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && ($basePath === '' || str_starts_with($returnTo, $basePath)));
$target = $isInsideApp ? $returnTo : BASE_URL . '/dashboard/index.php';
header('Location: ' . $target, true, 303);
exit;

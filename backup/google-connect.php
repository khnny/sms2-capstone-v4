<?php
/**
 * SMS 2 – Start the one-time Google sign-in for Drive backups (Super Admin only).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/gdrive-backup.php';

requireAuth();
requireSuperAdmin();

$back = BASE_URL . '/modules/user-management/pages/data-backup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfVerify()) {
    $_SESSION['flash_backup_error'] = 'Security check failed. Please try again.';
    header('Location: ' . $back);
    exit;
}
if (!smsGdriveIsConfigured()) {
    $_SESSION['flash_backup_error'] = 'Add the Google OAuth client ID and secret first (see the setup steps on this page).';
    header('Location: ' . $back);
    exit;
}

$state = bin2hex(random_bytes(24));
$_SESSION['gdrive_oauth_state'] = $state;
header('Location: ' . smsGdriveAuthUrl($state));
exit;

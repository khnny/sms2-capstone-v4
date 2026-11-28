<?php
/**
 * SMS 2 – Google OAuth redirect URI for Drive backups.
 *
 * Register this exact URL as an "Authorized redirect URI" of the OAuth client,
 * e.g. http://localhost/sms2-capstone-v3/backup/google-callback.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/gdrive-backup.php';

requireAuth();
requireSuperAdmin();

$back = BASE_URL . '/modules/user-management/pages/data-backup.php';
$expected = (string) ($_SESSION['gdrive_oauth_state'] ?? '');
unset($_SESSION['gdrive_oauth_state']);
$state = (string) ($_GET['state'] ?? '');

if ($expected === '' || !hash_equals($expected, $state)) {
    $_SESSION['flash_backup_error'] = 'Google sign-in expired or did not match. Click "Connect Google Drive" again.';
} elseif (!empty($_GET['error'])) {
    $_SESSION['flash_backup_error'] = 'Google sign-in was cancelled (' . substr((string) $_GET['error'], 0, 60) . ').';
} elseif (empty($_GET['code'])) {
    $_SESSION['flash_backup_error'] = 'Google did not send a sign-in code. Please try again.';
} else {
    $result = smsGdriveHandleCallback((string) $_GET['code']);
    if ($result['ok']) {
        try {
            smsGdriveFolderId();
            $_SESSION['flash_backup_success'] = 'Google Drive connected'
                . ($result['email'] !== '' ? ' as ' . $result['email'] : '')
                . '. Backups will go to the "' . smsBackupFolderName() . '" folder.';
        } catch (Throwable $e) {
            $_SESSION['flash_backup_error'] = 'Connected, but the backup folder could not be created: ' . $e->getMessage();
        }
        logActivity('update', 'Connected Google Drive for backups' . ($result['email'] !== '' ? ' (' . $result['email'] . ')' : ''), 'user-management');
    } else {
        $_SESSION['flash_backup_error'] = 'Could not connect Google Drive: ' . $result['error'];
    }
}

header('Location: ' . $back);
exit;

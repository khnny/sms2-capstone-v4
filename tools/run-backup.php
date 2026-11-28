<?php
/**
 * SMS 2 – Run a Google Drive backup from the command line.
 *
 *   C:\xampp\php\php.exe A:\xampp_new\htdocs\sms2-capstone-v3\tools\run-backup.php
 *   php tools/run-backup.php
 *
 * Can be used with Windows Task Scheduler instead of cron-job.org.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require_once dirname(__DIR__) . '/config/config.php';
require_once ROOT_PATH . '/includes/gdrive-backup.php';

$result = smsBackupRun('cli', 'System (CLI)');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($result['ok'] ? 0 : 1);

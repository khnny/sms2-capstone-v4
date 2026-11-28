<?php
/**
 * SMS 2 – Scheduled Google Drive backup (for cron-job.org or any web cron).
 *
 *   URL:    https://YOUR-DOMAIN/backup/run.php
 *   Header: X-Backup-Token: <SMS2_BACKUP_CRON_TOKEN>      (recommended)
 *   or      https://YOUR-DOMAIN/backup/run.php?token=<SMS2_BACKUP_CRON_TOKEN>
 *
 * The response is sent right away (cron-job.org stops waiting after 30 s)
 * and the backup keeps running on the server. Add ?wait=1 to wait for the
 * result instead (useful for testing).
 *
 * Returns 404 when the token is missing or wrong, so the URL is not guessable.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/gdrive-backup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$expected = smsBackupCronToken();
$given = (string) ($_SERVER['HTTP_X_BACKUP_TOKEN'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

if (!empty($_GET['wait'])) {
    $result = smsBackupRun('cron', 'System (cron)');
    http_response_code($result['ok'] ? 200 : 500);
    echo json_encode($result);
    exit;
}

// Answer now, then keep working after the cron service disconnects.
@ignore_user_abort(true);
@set_time_limit(0);
$body = (string) json_encode(['ok' => true, 'status' => 'started', 'time' => date('c')]);
http_response_code(202);
header('Connection: close');
header('Content-Length: ' . strlen($body));
echo $body;
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

smsBackupRun('cron', 'System (cron)');

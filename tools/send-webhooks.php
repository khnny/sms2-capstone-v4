<?php
/**
 * SMS 2 - Webhook cron job: detect CRAD changes, then deliver due webhooks.
 *
 * HostForge cron (every minute):
 *   php /path/to/sms2/tools/send-webhooks.php
 * XAMPP (manual run or Windows Task Scheduler):
 *   C:\xampp\php\php.exe tools\send-webhooks.php
 *
 * Options: --loop   keep running, once every 30 seconds (for local testing)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/webhooks.php';

$loop = in_array('--loop', $argv, true);
do {
    try {
        $result = smsWebhookRun();
        echo date('Y-m-d H:i:s') . ' ' . json_encode($result) . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, date('Y-m-d H:i:s') . ' webhook run failed: ' . $e->getMessage() . PHP_EOL);
        if (!$loop) {
            exit(1);
        }
    }
    if ($loop) {
        sleep(30);
    }
} while ($loop);

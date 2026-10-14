<?php
/**
 * Creates deadline reminders and sends reminder emails (To-Do P2.6).
 *
 * Run once a day. On Windows (XAMPP) use Task Scheduler with this command:
 *   C:\xampp\php\php.exe F:\xampp\htdocs\sms2_system\tools\send-deadline-reminders.php
 * The dashboard also runs it once per day, so the schedule is a backup for days nobody logs in.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require_once dirname(__DIR__) . '/config/config.php';
require_once ROOT_PATH . '/includes/deadline-reminders.php';

$stats = smsGenerateDeadlineReminders();
echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
exit(isset($stats['error']) ? 1 : 0);

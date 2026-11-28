<?php
/**
 * SMS 2 - Register or update an integrated app (API client).
 *
 * Student Portal example (XAMPP):
 *   php tools/register-api-client.php student-portal \
 *       --name="Student Portal" \
 *       --redirect=http://localhost/sms2-student-portal/callback.php \
 *       --webhook=http://localhost/sms2-student-portal/webhook.php \
 *       --roles=student
 *
 * Options:
 *   --name=TEXT          Display name
 *   --redirect=URL       Allowed login redirect URI (repeat or space-separate for several)
 *   --roles=a,b          Roles allowed to sign in through this app (default: student; * = any)
 *   --webhook=URL        Where to POST webhooks (omit for no webhooks)
 *   --events=LIST        Webhook event filter, e.g. "*" or "defense.*,research.title_status_changed"
 *   --rotate-secret      Issue a new client secret
 *   --rotate-webhook-secret  Issue a new webhook signing secret
 *   --disable            Disable the client
 *
 * Secrets are shown ONCE. Put them in the app's config (never in Git).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/integration.php';

$clientId = $argv[1] ?? '';
if ($clientId === '' || str_starts_with($clientId, '--')) {
    fwrite(STDERR, "Usage: php tools/register-api-client.php <client_id> [--name=...] [--redirect=URL] [--roles=student] [--webhook=URL]\n");
    exit(1);
}

$opts = [];
$redirects = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--redirect=(.+)$/', $arg, $m)) {
        $redirects[] = $m[1];
    } elseif (preg_match('/^--name=(.+)$/', $arg, $m)) {
        $opts['name'] = $m[1];
    } elseif (preg_match('/^--roles=(.+)$/', $arg, $m)) {
        $opts['allowed_roles'] = $m[1];
    } elseif (preg_match('/^--webhook=(.*)$/', $arg, $m)) {
        $opts['webhook_url'] = $m[1] === '' ? null : $m[1];
    } elseif (preg_match('/^--events=(.+)$/', $arg, $m)) {
        $opts['webhook_events'] = $m[1];
    } elseif ($arg === '--rotate-secret') {
        $opts['rotate_secret'] = true;
    } elseif ($arg === '--rotate-webhook-secret') {
        $opts['rotate_webhook_secret'] = true;
    } elseif ($arg === '--disable') {
        smsIntegrationDb()->prepare("UPDATE `sms2_api_clients` SET status = 'disabled' WHERE client_id = ?")->execute([$clientId]);
        echo "Disabled {$clientId}\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}
if ($redirects) {
    $opts['redirect_uris'] = implode("\n", $redirects);
}

try {
    $result = smsApiClientUpsert($clientId, $opts);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Client saved: {$result['client_id']}\n";
if ($result['client_secret'] !== null) {
    echo "CLIENT SECRET (shown once): {$result['client_secret']}\n";
}
if ($result['webhook_secret'] !== null) {
    echo "WEBHOOK SECRET (shown once): {$result['webhook_secret']}\n";
}
if ($result['client_secret'] === null && $result['webhook_secret'] === null) {
    echo "Secrets unchanged. Use --rotate-secret or --rotate-webhook-secret to issue new ones.\n";
}

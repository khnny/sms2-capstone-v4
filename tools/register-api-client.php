<?php
/**
 * SMS 2 - Register or update an integrated app (API client), one per CRAD module.
 *
 * Module presets (roles, scopes and webhook events are filled in for you):
 *   student-portal, registrar, payment, faculty, accreditation
 *
 *   php tools/register-api-client.php registrar \
 *       --redirect=http://localhost/registrar/callback.php \
 *       --webhook=http://localhost/registrar/webhook.php
 *
 *   php tools/register-api-client.php --list           (all clients, no secrets)
 *   php tools/register-api-client.php --presets        (what each preset allows)
 *   php tools/register-api-client.php payment --rotate-secret
 *
 * Options:
 *   --name=TEXT          Display name
 *   --redirect=URL       Allowed login redirect URI (repeat for several)
 *   --roles=a,b          Roles allowed to sign in through this app (* = any)
 *   --scopes="a b"       What the key may read/do (see --presets; * = everything)
 *   --webhook=URL        Where to POST webhooks (--webhook= removes it)
 *   --events=LIST        Webhook event filter, e.g. "*" or "defense.*,research.title_status_changed"
 *   --preset             Reset roles, scopes and events of an existing client to its module preset
 *   --preset=NAME        Use another module's preset (e.g. a second app with the registrar rules)
 *   --rotate-secret      Issue a new client secret (old one stops working at once)
 *   --rotate-webhook-secret  Issue a new webhook signing secret
 *   --disable            Disable the client (all its tokens stop working)
 *
 * Secrets are shown ONCE. Put them in the module app's config or Environment
 * Variables (never in Git, never in chat).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/integration.php';

$first = $argv[1] ?? '';

if ($first === '--presets') {
    foreach (smsApiModulePresets() as $id => $p) {
        echo "{$id}  ({$p['name']})\n";
        echo "  roles:  {$p['roles']}\n";
        echo "  scopes: {$p['scopes']}\n";
        echo "  events: {$p['events']}\n";
        echo "  env:    {$p['env']}_CLIENT_SECRET, {$p['env']}_REDIRECT_URI, {$p['env']}_WEBHOOK_URL, {$p['env']}_WEBHOOK_SECRET\n\n";
    }
    echo "Scopes:\n";
    foreach (smsApiScopeCatalog() as $scope => $text) {
        printf("  %-19s %s\n", $scope, $text);
    }
    exit(0);
}

if ($first === '--list') {
    $rows = smsIntegrationDb()->query('SELECT client_id, name, status, allowed_roles, scopes, webhook_url, webhook_events, updated_at FROM `sms2_api_clients` ORDER BY client_id')->fetchAll();
    if (!$rows) {
        echo "No API clients yet.\n";
    }
    foreach ($rows as $r) {
        echo "{$r['client_id']}  ({$r['name']}, {$r['status']}, updated {$r['updated_at']})\n";
        echo "  roles:   {$r['allowed_roles']}\n";
        echo "  scopes:  {$r['scopes']}\n";
        echo '  webhook: ' . ($r['webhook_url'] ?: '(none)') . "  events: {$r['webhook_events']}\n";
    }
    exit(0);
}

$clientId = $first;
if ($clientId === '' || str_starts_with($clientId, '--')) {
    fwrite(STDERR, "Usage: php tools/register-api-client.php <client_id> [--redirect=URL] [--webhook=URL] [--roles=...] [--scopes=...]\n"
        . "       php tools/register-api-client.php --presets | --list\n"
        . 'Module presets: ' . implode(', ', array_keys(smsApiModulePresets())) . "\n");
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
    } elseif (preg_match('/^--scopes=(.+)$/', $arg, $m)) {
        $opts['scopes'] = $m[1];
    } elseif (preg_match('/^--webhook=(.*)$/', $arg, $m)) {
        $opts['webhook_url'] = $m[1] === '' ? null : $m[1];
    } elseif (preg_match('/^--events=(.+)$/', $arg, $m)) {
        $opts['webhook_events'] = $m[1];
    } elseif ($arg === '--preset') {
        $opts['apply_preset'] = true;
    } elseif (preg_match('/^--preset=(.+)$/', $arg, $m)) {
        $opts['preset'] = $m[1];
    } elseif ($arg === '--rotate-secret') {
        $opts['rotate_secret'] = true;
    } elseif ($arg === '--rotate-webhook-secret') {
        $opts['rotate_webhook_secret'] = true;
    } elseif ($arg === '--disable') {
        smsIntegrationDb()->prepare("UPDATE `sms2_api_clients` SET status = 'disabled' WHERE client_id = ?")->execute([$clientId]);
        smsIntegrationDb()->prepare('UPDATE `sms2_api_refresh_tokens` SET revoked_at = NOW() WHERE client_id = ? AND revoked_at IS NULL')->execute([$clientId]);
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

$preset = smsApiModulePreset((string) ($opts['preset'] ?? $clientId));
if ($preset && smsApiModulePreset($clientId) && trim((string) (sms2_env($preset['env'] . '_CLIENT_SECRET', '') ?? '')) !== '') {
    fwrite(STDERR, "Note: {$preset['env']}_CLIENT_SECRET is set, so SMS 2 resets this client from Environment Variables on the next request.\n"
        . "      Change the Environment Variables instead, or remove them to manage {$clientId} here.\n");
}

try {
    $result = smsApiClientUpsert($clientId, $opts);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$stmt = smsIntegrationDb()->prepare('SELECT allowed_roles, scopes, webhook_events FROM `sms2_api_clients` WHERE client_id = ?');
$stmt->execute([$clientId]);
$saved = $stmt->fetch() ?: [];

echo "Client saved: {$result['client_id']}" . ($preset ? " (preset: {$preset['name']})" : '') . "\n";
echo '  roles:  ' . ($saved['allowed_roles'] ?? '') . "\n";
echo '  scopes: ' . ($saved['scopes'] ?? '') . "\n";
echo '  events: ' . ($saved['webhook_events'] ?? '') . "\n";
if ($result['client_secret'] !== null) {
    echo "CLIENT SECRET (shown once): {$result['client_secret']}\n";
}
if ($result['webhook_secret'] !== null) {
    echo "WEBHOOK SECRET (shown once): {$result['webhook_secret']}\n";
}
if ($result['client_secret'] === null && $result['webhook_secret'] === null) {
    echo "Secrets unchanged. Use --rotate-secret or --rotate-webhook-secret to issue new ones.\n";
} else {
    echo "Store the secret(s) in the module app's config/local.php or Environment Variables. Never commit or paste them in chat.\n";
}

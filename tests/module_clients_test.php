<?php
/**
 * Module API clients test: presets for the five CRAD integrations, per-client
 * scopes (a Registrar key cannot read payments, a Payment key cannot read
 * research data), webhook filtering by scope, Environment Variable setup and
 * secret rotation. Ends with real HTTP calls to api/v1 through PHP's built-in server.
 *
 * Run from the project folder against a TEST database:
 *   php tests/module_clients_test.php
 *
 * Uses only zz-test-* API clients and a zz_test_* account, and removes them at the end.
 * The real student-portal / registrar / payment / faculty / accreditation rows are not touched.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SESSION = [];
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/integration.php';
require_once __DIR__ . '/../includes/webhooks.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}

$pdo = getDatabaseConnection();
smsWebhookEnsureSchema($pdo);
$ids = ['zz-test-registrar', 'zz-test-payment', 'zz-test-legacy', 'zz-test-envmod'];
$in = implode(',', array_fill(0, count($ids), '?'));

$cleanup = static function () use ($pdo, $ids, $in): void {
    foreach (['sms2_api_auth_codes', 'sms2_api_refresh_tokens', 'sms2_api_sso_tickets', 'sms2_webhook_deliveries', 'sms2_api_clients'] as $table) {
        $pdo->prepare("DELETE FROM `{$table}` WHERE client_id IN ({$in})")->execute($ids);
    }
    $pdo->exec("DELETE FROM sms2_users WHERE username = 'zz_test_mod_student'");
};
$cleanup();

$server = null;
try {
    $insert = $pdo->prepare("INSERT INTO sms2_users (username, email, password_hash, full_name, role_key, student_id, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    $insert->execute(['zz_test_mod_student', 'zz_test_mod_student@example.test', password_hash('ZzTest#2026', PASSWORD_DEFAULT), 'Zz Mod Student', 'student', 'ZZMOD0001']);
    $studentId = (int) $pdo->lastInsertId();

    // --- presets
    $presets = smsApiModulePresets();
    check('presets exist for all five CRAD integrations', array_keys($presets) === ['student-portal', 'registrar', 'payment', 'faculty', 'accreditation']);
    $catalog = smsApiScopeCatalog();
    $allKnown = true;
    foreach ($presets as $p) {
        foreach (smsApiParseScopes($p['scopes']) as $s) {
            $allKnown = $allKnown && ($s === '*' || isset($catalog[$s]));
        }
    }
    check('every preset scope is a known scope', $allKnown);
    check('student portal keeps full access', $presets['student-portal']['scopes'] === '*' && $presets['student-portal']['roles'] === 'student');
    $envs = array_column($presets, 'env');
    check('each preset has its own env prefix', count(array_unique($envs)) === 5 && $presets['student-portal']['env'] === 'SMS2_PORTAL');

    // --- clients from presets (named preset on a test client id)
    $reg = smsApiClientUpsert('zz-test-registrar', ['preset' => 'registrar', 'name' => 'Zz Registrar', 'redirect_uris' => 'http://127.0.0.1:9/cb.php', 'webhook_url' => 'http://127.0.0.1:9/hook.php']);
    $pay = smsApiClientUpsert('zz-test-payment', ['preset' => 'payment', 'name' => 'Zz Payment', 'webhook_url' => 'http://127.0.0.1:9/hook.php']);
    $regClient = smsApiClientAuthenticate('zz-test-registrar', (string) $reg['client_secret']);
    $payClient = smsApiClientAuthenticate('zz-test-payment', (string) $pay['client_secret']);
    check('preset client gets a secret and a webhook secret', $regClient !== null && $payClient !== null && $reg['webhook_secret'] !== null);
    check('registrar preset roles and events applied', $regClient['allowed_roles'] === 'student,registrar' && $regClient['webhook_events'] === $presets['registrar']['events']);
    check('registrar key cannot read or upload payments', !smsApiClientHasScope($regClient, 'payment.read') && !smsApiClientHasScope($regClient, 'payment.write'));
    check('registrar key reads research and clearance', smsApiClientHasScope($regClient, 'research.read') && smsApiClientHasScope($regClient, 'clearance.read'));
    check('payment key cannot read research or clearance', !smsApiClientHasScope($payClient, 'research.read') && !smsApiClientHasScope($payClient, 'clearance.read') && !smsApiClientHasScope($payClient, 'statement.read'));
    check('payment key reads payments', smsApiClientHasScope($payClient, 'payment.read'));
    check('registrar sign-in allows students and registrar staff only', smsApiClientAllowsRole($regClient, 'student') && smsApiClientAllowsRole($regClient, 'registrar') && !smsApiClientAllowsRole($regClient, 'finance'));

    // --- scope validation and legacy clients
    $threw = false;
    try {
        smsApiClientUpsert('zz-test-legacy', ['scopes' => 'profile payments.everything']);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check('unknown scope is refused', $threw);
    $threw = false;
    try {
        smsApiClientUpsert('zz-test-legacy', ['preset' => 'library']);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check('unknown preset is refused', $threw);
    smsApiClientUpsert('zz-test-legacy', ['name' => 'Zz Legacy']);
    $legacy = smsApiClientFind('zz-test-legacy');
    check('client without a preset or scopes keeps full access (old behaviour)', $legacy['scopes'] === '*' && smsApiClientHasScope($legacy, 'payment.read'));
    check('scopes column has default *', (bool) $pdo->query("SHOW COLUMNS FROM `sms2_api_clients` WHERE Field = 'scopes' AND `Default` = '*'")->fetch());
    smsApiClientUpsert('zz-test-registrar', ['scopes' => 'profile']);
    smsApiClientUpsert('zz-test-registrar', ['apply_preset' => true, 'preset' => 'registrar']);
    check('--preset resets scopes back to the preset', smsApiClientFind('zz-test-registrar')['scopes'] === $presets['registrar']['scopes']);

    // --- webhooks: filter by scope
    check('payment event needs payment.read', smsApiEventScope('clearance.status_changed', ['kind' => 'payment']) === 'payment.read');
    check('clearance event needs clearance.read', smsApiEventScope('clearance.status_changed', ['kind' => 'clearance']) === 'clearance.read');
    $queued = static function (string $eventId, string $clientId) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sms2_webhook_deliveries d JOIN sms2_webhook_events e ON e.id = d.event_pk WHERE e.event_id = ? AND d.client_id = ?');
        $stmt->execute([$eventId, $clientId]);
        return (int) $stmt->fetchColumn() === 1;
    };
    $payEvent = smsWebhookEmit($pdo, 'clearance.status_changed', ['kind' => 'payment', 'id' => 0], ['student_ids' => ['ZZMOD0001']]);
    $clrEvent = smsWebhookEmit($pdo, 'clearance.status_changed', ['kind' => 'clearance', 'id' => 0], ['student_ids' => ['ZZMOD0001']]);
    $defEvent = smsWebhookEmit($pdo, 'defense.scheduled', ['defense_id' => 0], ['student_ids' => ['ZZMOD0001']]);
    check('payment webhook goes to Payment, not Registrar', $queued($payEvent, 'zz-test-payment') && !$queued($payEvent, 'zz-test-registrar'));
    check('clearance webhook goes to Registrar, not Payment', $queued($clrEvent, 'zz-test-registrar') && !$queued($clrEvent, 'zz-test-payment'));
    check('defense webhook goes to Registrar, not Payment', $queued($defEvent, 'zz-test-registrar') && !$queued($defEvent, 'zz-test-payment'));
    $pdo->prepare('DELETE FROM sms2_webhook_events WHERE event_id IN (?, ?, ?)')->execute([$payEvent, $clrEvent, $defEvent]);

    // --- Environment Variables path (HostForge, no terminal)
    $envPreset = ['name' => 'Zz Env Module', 'env' => 'ZZTEST_MOD'] + $presets['faculty'];
    $fakeSecret = str_repeat('a1', 20);
    putenv('ZZTEST_MOD_CLIENT_SECRET=short');
    check('env secret shorter than 32 chars is refused', smsApiClientSyncOneFromEnv($pdo, 'zz-test-envmod', $envPreset)['status'] === 'invalid');
    putenv('ZZTEST_MOD_CLIENT_SECRET=' . $fakeSecret);
    putenv('ZZTEST_MOD_REDIRECT_URI=http://127.0.0.1:9/faculty/callback.php');
    putenv('ZZTEST_MOD_WEBHOOK_URL=http://127.0.0.1:9/faculty/webhook.php');
    putenv('ZZTEST_MOD_WEBHOOK_SECRET=' . str_repeat('b2', 20));
    check('env creates the client', smsApiClientSyncOneFromEnv($pdo, 'zz-test-envmod', $envPreset)['status'] === 'created');
    $envClient = smsApiClientAuthenticate('zz-test-envmod', $fakeSecret);
    check('env client signs in with the env secret and gets preset scopes', $envClient !== null && $envClient['scopes'] === $presets['faculty']['scopes'] && $envClient['allowed_roles'] === $presets['faculty']['roles']);
    check('env webhook secret is stored encrypted and readable', smsApiClientWebhookSecret($envClient) === str_repeat('b2', 20) && !str_contains((string) $envClient['webhook_secret'], 'b2b2'));
    check('env sync is a no-op when nothing changed', smsApiClientSyncOneFromEnv($pdo, 'zz-test-envmod', $envPreset)['status'] === 'in_sync');
    putenv('ZZTEST_MOD_SCOPES=profile sso');
    check('env scope override is applied', smsApiClientSyncOneFromEnv($pdo, 'zz-test-envmod', $envPreset)['status'] === 'updated' && smsApiClientFind('zz-test-envmod')['scopes'] === 'profile sso');
    $rotated = str_repeat('c3', 20);
    putenv('ZZTEST_MOD_CLIENT_SECRET=' . $rotated);
    smsApiClientSyncOneFromEnv($pdo, 'zz-test-envmod', $envPreset);
    check('changing the env secret rotates the key', smsApiClientAuthenticate('zz-test-envmod', $rotated) !== null && smsApiClientAuthenticate('zz-test-envmod', $fakeSecret) === null);
    foreach (['CLIENT_SECRET', 'REDIRECT_URI', 'WEBHOOK_URL', 'WEBHOOK_SECRET', 'SCOPES'] as $suffix) {
        putenv('ZZTEST_MOD_' . $suffix);
    }
    check('no env secret means the client is left alone', smsApiClientSyncOneFromEnv($pdo, 'zz-test-envmod', $envPreset)['status'] === 'skipped');

    // --- CLI rotation
    $rot = smsApiClientUpsert('zz-test-payment', ['rotate_secret' => true]);
    check('--rotate-secret: new secret works, old one stops', smsApiClientAuthenticate('zz-test-payment', (string) $rot['client_secret']) !== null
        && smsApiClientAuthenticate('zz-test-payment', (string) $pay['client_secret']) === null);
    $payClient = smsApiClientFind('zz-test-payment');
    check('rotation keeps the scopes', $payClient['scopes'] === $presets['payment']['scopes']);

    // --- tokens carry the scope
    $student = smsApiLoadUser($studentId);
    $regTokens = smsApiIssueTokens(smsApiClientFind('zz-test-registrar'), $student);
    $payTokens = smsApiIssueTokens($payClient, $student);
    check('token response lists the scope', $regTokens['scope'] === $presets['registrar']['scopes']);
    smsApiClientUpsert('zz-test-registrar', ['scopes' => 'profile']);
    $auth = smsApiAuthenticateBearer('Bearer ' . $regTokens['access_token']);
    check('scope change applies to tokens already issued', $auth['ok'] && !smsApiClientHasScope($auth['client'], 'research.read'));
    smsApiClientUpsert('zz-test-registrar', ['preset' => 'registrar']);

    // --- real HTTP calls through api/v1
    $port = 18000 + random_int(0, 999);
    $root = realpath(__DIR__ . '/..');
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [['pipe', 'r'], ['file', sys_get_temp_dir() . '/zz-module-clients-server.log', 'a'], ['file', sys_get_temp_dir() . '/zz-module-clients-server.log', 'a']], $pipes, $root);
    $call = static function (string $route, string $token) use ($port): array {
        $ctx = stream_context_create(['http' => ['header' => 'Authorization: Bearer ' . $token, 'ignore_errors' => true, 'timeout' => 20]]);
        $body = @file_get_contents('http://127.0.0.1:' . $port . '/api/v1/index.php?route=' . rawurlencode($route), false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, json_decode((string) $body, true)];
    };
    $up = false;
    for ($i = 0; $i < 40 && !$up; $i++) {
        usleep(100000);
        $up = @fsockopen('127.0.0.1', $port) !== false;
    }
    if (!$up) {
        echo "SKIP HTTP checks (could not start php -S)\n";
    } else {
        [$s, $b] = $call('me', $regTokens['access_token']);
        check('HTTP registrar GET me works', $s === 200 && (int) ($b['data']['id'] ?? 0) === $studentId);
        [$s, $b] = $call('me/research/payment', $regTokens['access_token']);
        check('HTTP registrar GET me/research/payment is 403 insufficient_scope', $s === 403 && ($b['error']['code'] ?? '') === 'insufficient_scope');
        [$s, $b] = $call('me/research/statement', $regTokens['access_token']);
        check('HTTP registrar cannot read the full statement (has payments)', $s === 403);
        [$s, $b] = $call('me/clearance', $regTokens['access_token']);
        check('HTTP registrar GET me/clearance has clearances but no payments', $s === 200 && array_key_exists('clearances', $b['data'] ?? []) && !array_key_exists('payments', $b['data'] ?? []));
        [$s, $b] = $call('me/clearance', $payTokens['access_token']);
        check('HTTP payment GET me/clearance has payments but no clearances', $s === 200 && array_key_exists('payments', $b['data'] ?? []) && !array_key_exists('clearances', $b['data'] ?? []));
        [$s, $b] = $call('me/research', $payTokens['access_token']);
        check('HTTP payment GET me/research is 403', $s === 403 && ($b['error']['code'] ?? '') === 'insufficient_scope');
        [$s, $b] = $call('me/defenses', $payTokens['access_token']);
        check('HTTP payment GET me/defenses is 403', $s === 403);
        [$s, $b] = $call('me/research/payment', $payTokens['access_token']);
        check('HTTP payment GET me/research/payment is allowed', $s === 200);
    }
} catch (Throwable $e) {
    $fail++;
    echo 'ERROR ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $cleanup();
}

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);

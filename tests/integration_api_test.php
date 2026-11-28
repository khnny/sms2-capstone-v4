<?php
/**
 * Integration layer test: JWT, one-time login codes, refresh-token rotation,
 * SSO tickets and webhook change detection + signing.
 *
 * Run from the project folder against a TEST database:
 *   php tests/integration_api_test.php
 *
 * Uses only zz_test_* accounts and a zz-test-* API client, and removes them at the end.
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
$clientId = 'zz-test-portal';
$redirect = 'http://127.0.0.1:9/callback.php';

$cleanup = static function () use ($pdo, $clientId): void {
    $pdo->prepare('DELETE FROM sms2_api_auth_codes WHERE client_id = ?')->execute([$clientId]);
    $pdo->prepare('DELETE FROM sms2_api_refresh_tokens WHERE client_id = ?')->execute([$clientId]);
    $pdo->prepare('DELETE FROM sms2_api_sso_tickets WHERE client_id = ?')->execute([$clientId]);
    $pdo->prepare('DELETE FROM sms2_webhook_deliveries WHERE client_id = ?')->execute([$clientId]);
    $pdo->prepare('DELETE FROM sms2_api_clients WHERE client_id = ?')->execute([$clientId]);
    $pdo->exec("DELETE FROM sms2_users WHERE username IN ('zz_test_int_student', 'zz_test_int_adviser')");
};
$cleanup();

try {
    $hash = password_hash('ZzTest#2026', PASSWORD_DEFAULT);
    $insert = $pdo->prepare("INSERT INTO sms2_users (username, email, password_hash, full_name, role_key, student_id, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    $insert->execute(['zz_test_int_student', 'zz_test_int_student@example.test', $hash, 'Zz Int Student', 'student', 'ZZINT0001']);
    $studentId = (int) $pdo->lastInsertId();
    $insert->execute(['zz_test_int_adviser', 'zz_test_int_adviser@example.test', $hash, 'Zz Int Adviser', 'adviser', null]);
    $adviserId = (int) $pdo->lastInsertId();

    // --- JWT
    $secret = str_repeat('k', 40);
    $jwt = smsJwtEncode(['iss' => 'i', 'aud' => 'a', 'sub' => '1', 'exp' => time() + 60], $secret);
    check('JWT round trip', smsJwtDecode($jwt, $secret, 'i', 'a')['ok']);
    check('JWT rejects wrong secret', !smsJwtDecode($jwt, str_repeat('x', 40), 'i', 'a')['ok']);
    check('JWT rejects wrong audience', !smsJwtDecode($jwt, $secret, 'i', 'other')['ok']);
    $expired = smsJwtEncode(['iss' => 'i', 'aud' => 'a', 'exp' => time() - 120], $secret);
    check('JWT rejects expired token', smsJwtDecode($expired, $secret, 'i', 'a')['error'] === 'expired');
    [$h, $p] = explode('.', $jwt);
    $none = smsJwtBase64UrlEncode('{"alg":"none","typ":"JWT"}') . '.' . $p . '.';
    check('JWT rejects alg=none', !smsJwtDecode($none, $secret, 'i', 'a')['ok']);

    // --- client + login code
    $created = smsApiClientUpsert($clientId, ['name' => 'Zz Test', 'redirect_uris' => $redirect, 'allowed_roles' => 'student']);
    $client = smsApiClientAuthenticate($clientId, (string) $created['client_secret']);
    check('client authenticates with its secret', $client !== null);
    check('client rejects wrong secret', smsApiClientAuthenticate($clientId, 'wrong') === null);
    check('redirect URI must match exactly', smsApiClientRedirectAllowed($client, $redirect) && !smsApiClientRedirectAllowed($client, $redirect . '?x=1'));

    $code = smsApiCreateAuthCode($client, $studentId, $redirect);
    check('code with wrong redirect is refused', !smsApiRedeemAuthCode($client, $code, 'http://evil.test/')['ok']);
    $redeemed = smsApiRedeemAuthCode($client, $code, $redirect);
    check('code redeems once', $redeemed['ok']);
    check('code cannot be reused', !smsApiRedeemAuthCode($client, $code, $redirect)['ok']);

    $adviserCode = smsApiCreateAuthCode($client, $adviserId, $redirect);
    check('student-only client refuses an adviser', smsApiRedeemAuthCode($client, $adviserCode, $redirect)['error'] === 'access_denied');

    // --- tokens
    $tokens = smsApiIssueTokens($client, $redeemed['user']);
    $auth = smsApiAuthenticateBearer('Bearer ' . $tokens['access_token']);
    check('access token authenticates the student', $auth['ok'] && (int) $auth['user']['id'] === $studentId);
    $rotated = smsApiRefreshTokens($client, $tokens['refresh_token']);
    check('refresh token rotates', $rotated['ok'] && $rotated['tokens']['refresh_token'] !== $tokens['refresh_token']);
    check('old refresh token is refused', !smsApiRefreshTokens($client, $tokens['refresh_token'])['ok']);
    check('reuse revokes the newer refresh token too', !smsApiRefreshTokens($client, $rotated['tokens']['refresh_token'])['ok']);

    $pdo->prepare("UPDATE sms2_users SET status = 'inactive' WHERE id = ?")->execute([$studentId]);
    check('deactivated account loses API access at once', !smsApiAuthenticateBearer('Bearer ' . $tokens['access_token'])['ok']);
    $pdo->prepare("UPDATE sms2_users SET status = 'active' WHERE id = ?")->execute([$studentId]);

    // --- SSO tickets
    check('ticket path blocks other sites', smsApiSafeNextPath('//evil.test/x') === null && smsApiSafeNextPath('https://evil.test') === null);
    check('ticket path blocks traversal', smsApiSafeNextPath('/modules/student-portal/../../config/local.php') === null);
    $next = (string) smsApiSafeNextPath('/modules/student-portal/pages/submit-chapters.php');
    $ticket = smsApiCreateSsoTicket($client, $studentId, $next);
    $used = smsApiConsumeSsoTicket($ticket);
    check('ticket opens the page for the same user', $used !== null && (int) $used['user']['id'] === $studentId && $used['next_path'] === $next);
    check('ticket works once', smsApiConsumeSsoTicket($ticket) === null);

    // --- webhooks
    check('event filter supports wildcards', smsWebhookClientWants(['webhook_events' => 'defense.*'], 'defense.rescheduled') && !smsWebhookClientWants(['webhook_events' => 'defense.*'], 'research.adviser_assigned'));
    $sig = smsWebhookSign('s3cret', 1700000000, '{"a":1}');
    check('signature format', $sig === 'sha256=' . hash_hmac('sha256', '1700000000.{"a":1}', 's3cret'));
    check('title status label', smsWebhookTitleLabel(['status' => 'Approved', 'coordinator_status' => 'Approved', 'crad_status' => 'Approved']) === 'CRAD Approved'
        && smsWebhookTitleLabel(['status' => 'Approved', 'coordinator_status' => 'Returned', 'crad_status' => '']) === 'Returned');

    smsApiClientUpsert($clientId, ['webhook_url' => 'http://127.0.0.1:9/webhook.php', 'webhook_events' => '*']);
    $eventId = smsWebhookEmit($pdo, 'defense.scheduled', ['defense_id' => 0], ['student_ids' => ['ZZINT0001']]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sms2_webhook_deliveries d JOIN sms2_webhook_events e ON e.id = d.event_pk WHERE e.event_id = ? AND d.client_id = ?');
    $stmt->execute([$eventId, $clientId]);
    check('emitted event is queued for the subscribed client', (int) $stmt->fetchColumn() === 1);
    $pdo->prepare('DELETE e FROM sms2_webhook_events e WHERE e.event_id = ?')->execute([$eventId]);
} catch (Throwable $e) {
    $fail++;
    echo 'ERROR ' . $e->getMessage() . PHP_EOL;
} finally {
    $cleanup();
}

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);

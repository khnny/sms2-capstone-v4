<?php
/**
 * Password grant test: the Student Portal's own login page checks the password
 * at SMS 2 (lockout, role, Authenticator challenge, one-time use).
 *
 * Run from the project folder against a TEST database:
 *   php tests/portal_password_login_test.php
 *
 * Uses only zz_test_* accounts and a zz-test-* API client, and removes them at the end.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int(10, 250);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authentication.php';
require_once __DIR__ . '/../includes/integration.php';
require_once __DIR__ . '/../includes/totp.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}

$pdo = getDatabaseConnection();
$clientId = 'zz-test-pwportal';
$users = ['zz_test_pw_student', 'zz_test_pw_mfa', 'zz_test_pw_adviser'];
$cleanup = static function () use ($pdo, $clientId, $users): void {
    $pdo->prepare('DELETE FROM sms2_api_mfa_challenges WHERE client_id = ?')->execute([$clientId]);
    $pdo->prepare('DELETE FROM sms2_api_refresh_tokens WHERE client_id = ?')->execute([$clientId]);
    $pdo->prepare('DELETE FROM sms2_api_clients WHERE client_id = ?')->execute([$clientId]);
    $in = implode(',', array_fill(0, count($users), '?'));
    $pdo->prepare("DELETE FROM sms2_user_authenticators WHERE user_id IN (SELECT id FROM sms2_users WHERE username IN ($in))")->execute($users);
    $pdo->prepare("DELETE FROM sms2_users WHERE username IN ($in)")->execute($users);
    $pdo->prepare('DELETE FROM sms2_login_throttles WHERE ip_address = ?')->execute([smsClientIp()]);
};

try {
    smsEnsureAuthenticatorTable();
    $cleanup();
    $hash = password_hash('ZzTest#2026', PASSWORD_DEFAULT);
    $insert = $pdo->prepare("INSERT INTO sms2_users (username, email, password_hash, full_name, role_key, student_id, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    $insert->execute(['zz_test_pw_student', 'zz_test_pw_student@example.test', $hash, 'Zz Pw Student', 'student', 'ZZPW0001']);
    $insert->execute(['zz_test_pw_mfa', 'zz_test_pw_mfa@example.test', $hash, 'Zz Pw Mfa', 'student', 'ZZPW0002']);
    $mfaId = (int) $pdo->lastInsertId();
    $insert->execute(['zz_test_pw_adviser', 'zz_test_pw_adviser@example.test', $hash, 'Zz Pw Adviser', 'adviser', null]);
    smsApiClientUpsert($clientId, ['name' => 'Zz Portal', 'redirect_uris' => 'http://127.0.0.1:9/cb', 'allowed_roles' => 'student']);
    $client = smsApiClientFind($clientId);

    $r = smsApiPasswordLogin($client, 'zz_test_pw_student', 'ZzTest#2026');
    check('right password signs the student in', !empty($r['ok']) && $r['user']['student_id'] === 'ZZPW0001');
    check('tokens can be issued', isset(smsApiIssueTokens($client, $r['user'])['access_token']));

    $r = smsApiPasswordLogin($client, 'zz_test_pw_student', 'wrong');
    check('wrong password is refused', empty($r['ok']) && $r['error'] === 'invalid_grant');
    check('refusal says attempts left', str_contains((string) $r['message'], 'attempt'));
    smsClearLoginThrottle('zz_test_pw_student');
    $pdo->exec("UPDATE sms2_users SET failed_login_attempts = 0 WHERE username = 'zz_test_pw_student'");

    $r = smsApiPasswordLogin($client, 'zz_test_pw_adviser', 'ZzTest#2026');
    check('staff account is refused', empty($r['ok']) && $r['error'] === 'access_denied');

    $pdo->exec("UPDATE sms2_users SET must_change_password = 1 WHERE username = 'zz_test_pw_student'");
    $r = smsApiPasswordLogin($client, 'zz_test_pw_student', 'ZzTest#2026');
    check('forced password change is reported', empty($r['ok']) && $r['error'] === 'password_change_required');
    $pdo->exec("UPDATE sms2_users SET must_change_password = 0 WHERE username = 'zz_test_pw_student'");

    $secret = smsAuthenticatorBeginSetup($mfaId);
    smsAuthenticatorConfirmEnable($mfaId, smsTotpCode((string) $secret));
    $r = smsApiPasswordLogin($client, 'zz_test_pw_mfa', 'ZzTest#2026');
    check('Authenticator account gets a challenge, not tokens', empty($r['ok']) && !empty($r['mfa']['mfa_token']));
    $token = (string) ($r['mfa']['mfa_token'] ?? '');
    $bad = smsApiMfaVerify($client, $token, 'authenticator', '000000');
    check('wrong code is refused', empty($bad['ok']) && $bad['error'] === 'invalid_code');
    $other = $client;
    $other['client_id'] = 'someone-else';
    check('challenge is bound to the client', smsApiMfaVerify($other, $token, 'authenticator', smsTotpCode((string) $secret))['error'] === 'mfa_expired');
    $ok = smsApiMfaVerify($client, $token, 'authenticator', smsTotpCode((string) $secret));
    check('right code finishes sign-in', !empty($ok['ok']) && (int) $ok['user']['id'] === $mfaId);
    check('challenge works once', smsApiMfaVerify($client, $token, 'authenticator', smsTotpCode((string) $secret))['error'] === 'mfa_expired');

    $r = smsApiPasswordLogin($client, 'zz_test_pw_mfa', 'ZzTest#2026');
    $token = (string) $r['mfa']['mfa_token'];
    $last = [];
    for ($i = 0; $i < smsCodeChallengeMaxAttempts(); $i++) {
        $last = smsApiMfaVerify($client, $token, 'authenticator', '000000');
    }
    check('too many wrong codes closes the challenge', ($last['error'] ?? '') === 'mfa_locked');
    check('closed challenge refuses the right code', empty(smsApiMfaVerify($client, $token, 'authenticator', smsTotpCode((string) $secret))['ok']));
} finally {
    $cleanup();
}

echo PHP_EOL . $pass . ' passed, ' . $fail . ' failed' . PHP_EOL;
exit($fail === 0 ? 0 : 1);

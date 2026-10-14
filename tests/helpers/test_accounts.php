<?php
/**
 * Test-account helpers for the tests in tests/.
 *
 * Every helper here works only on accounts whose username starts with "zz_test_".
 * Real accounts are never read, changed, or deleted by these helpers.
 *
 * CLI (run from the project root):
 *   php tests/helpers/test_accounts.php create <username> <role_key> <password>
 *   php tests/helpers/test_accounts.php delete <username>
 *   php tests/helpers/test_accounts.php enable <username>     (turns the Authenticator on)
 *   php tests/helpers/test_accounts.php code <username>       (prints the current 6-digit code)
 *   php tests/helpers/test_accounts.php state <username>      (prints enabled flag and secret hash)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/totp.php';

const SMS2_TEST_USER_PREFIX = 'zz_test_';

function smsTestRequireName(string $username): void
{
    if (!str_starts_with($username, SMS2_TEST_USER_PREFIX) || preg_match('/^[a-z0-9_]{9,40}$/', $username) !== 1) {
        throw new InvalidArgumentException('Test helpers only work on accounts named zz_test_*.');
    }
}

function smsTestUserId(string $username): int
{
    smsTestRequireName($username);
    $pdo = db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database unavailable. Start MySQL and check config/database settings before running the tests.');
    }
    $stmt = $pdo->prepare('SELECT id FROM `sms2_users` WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    return (int) $stmt->fetchColumn();
}

function smsTestCreateUser(string $username, string $roleKey, string $password): int
{
    smsTestRequireName($username);
    $pdo = db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $existing = smsTestUserId($username);
    if ($existing > 0) {
        $pdo->prepare(
            "UPDATE `sms2_users` SET password_hash = ?, role_key = ?, status = 'active',
             failed_login_attempts = 0, locked_until = NULL, must_change_password = 0 WHERE id = ?"
        )->execute([$hash, $roleKey, $existing]);
        return $existing;
    }
    $pdo->prepare(
        "INSERT INTO `sms2_users` (username, email, password_hash, full_name, role_key, status, must_change_password, password_changed_at)
         VALUES (?, ?, ?, ?, ?, 'active', 0, NOW())"
    )->execute([$username, $username . '@example.test', $hash, 'Test ' . $username, $roleKey]);
    return (int) $pdo->lastInsertId();
}

function smsTestDeleteUser(string $username): void
{
    smsTestRequireName($username);
    $uid = smsTestUserId($username);
    if ($uid <= 0) {
        return;
    }
    $pdo = db();
    foreach (['sms2_user_authenticators', 'sms2_security_otps', 'sms2_password_resets', 'sms2_user_passkeys'] as $table) {
        try {
            $pdo->prepare("DELETE FROM `{$table}` WHERE user_id = ?")->execute([$uid]);
        } catch (Throwable $e) {
            // Table may not exist in every installation.
        }
    }
    $pdo->prepare('DELETE FROM `sms2_users` WHERE id = ?')->execute([$uid]);
}

function smsTestAuthenticatorEnable(string $username): bool
{
    $uid = smsTestUserId($username);
    $secret = smsAuthenticatorBeginSetup($uid);
    return smsAuthenticatorConfirmEnable($uid, smsTotpCode((string) $secret));
}

function smsTestAuthenticatorCode(string $username): string
{
    $uid = smsTestUserId($username);
    $row = db()->query("SELECT secret FROM `sms2_user_authenticators` WHERE user_id = {$uid}")->fetch(PDO::FETCH_ASSOC);
    return smsTotpCode(smsSecretDecrypt((string) $row['secret']));
}

function smsTestAuthenticatorState(string $username): array
{
    $uid = smsTestUserId($username);
    $row = db()->query("SELECT secret FROM `sms2_user_authenticators` WHERE user_id = {$uid}")->fetch(PDO::FETCH_ASSOC);
    return [
        'enabled' => smsAuthenticatorIsEnabled($uid),
        'secret_sha256' => $row ? hash('sha256', (string) $row['secret']) : null,
    ];
}

// CLI dispatch. Only runs when this file is executed directly, not when included by a test.
if (PHP_SAPI === 'cli' && isset($argv[0]) && basename($argv[0]) === 'test_accounts.php') {
    $cmd = $argv[1] ?? '';
    $user = $argv[2] ?? '';
    try {
        switch ($cmd) {
            case 'create':
                echo smsTestCreateUser($user, (string) ($argv[3] ?? ''), (string) ($argv[4] ?? '')) . "\n";
                break;
            case 'delete':
                smsTestDeleteUser($user);
                echo "deleted\n";
                break;
            case 'enable':
                echo smsTestAuthenticatorEnable($user) ? "enabled\n" : "failed\n";
                break;
            case 'code':
                echo smsTestAuthenticatorCode($user) . "\n";
                break;
            case 'state':
                echo json_encode(smsTestAuthenticatorState($user)) . "\n";
                break;
            default:
                fwrite(STDERR, "unknown command\n");
                exit(1);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

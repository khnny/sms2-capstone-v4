<?php
/**
 * Test helper for tests/p0_authenticator_http_test.py. Run from the project root.
 * Usage:
 *   php tests/helpers/authenticator_helper.php enable <username>
 *   php tests/helpers/authenticator_helper.php code <username>
 *   php tests/helpers/authenticator_helper.php state <username>
 *   php tests/helpers/authenticator_helper.php reset <username> <password>
 * Only use on a local test database.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/totp.php';

$cmd = $argv[1] ?? '';
$username = $argv[2] ?? '';
$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM `sms2_users` WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$uid = (int) $stmt->fetchColumn();
if ($uid <= 0) {
    fwrite(STDERR, "unknown user\n");
    exit(1);
}

switch ($cmd) {
    case 'enable':
        $secret = smsAuthenticatorBeginSetup($uid);
        echo smsAuthenticatorConfirmEnable($uid, smsTotpCode((string) $secret)) ? "enabled\n" : "failed\n";
        break;
    case 'code':
        $row = $pdo->query("SELECT secret FROM `sms2_user_authenticators` WHERE user_id = {$uid}")->fetch(PDO::FETCH_ASSOC);
        echo smsTotpCode(smsSecretDecrypt((string) $row['secret'])) . "\n";
        break;
    case 'state':
        $row = $pdo->query("SELECT secret FROM `sms2_user_authenticators` WHERE user_id = {$uid}")->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'enabled' => smsAuthenticatorIsEnabled($uid),
            'secret_sha256' => $row ? hash('sha256', (string) $row['secret']) : null,
        ]) . "\n";
        break;
    case 'reset':
        smsAuthenticatorDisable($uid);
        smsSetUserPassword($uid, (string) ($argv[3] ?? ''), false);
        echo "reset\n";
        break;
    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

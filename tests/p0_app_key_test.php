<?php
/**
 * P0 functional test (function level): the Authenticator (app key) must survive a password change.
 * Uses a dedicated zz_test_ account that is created and deleted by this test.
 * Run from the project root: php tests/p0_app_key_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/helpers/test_accounts.php';

$user = 'zz_test_p0fn';
$ok = true;
$check = function (string $label, bool $cond) use (&$ok) {
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$cond) { $ok = false; }
};

smsTestCreateUser($user, 'superadmin', 'P0Start!2026');
try {
    $uid = smsTestUserId($user);
    smsAuthenticatorDisable($uid);   // known starting state; re-encrypts any stored secret with the current app key
    $secret = smsAuthenticatorBeginSetup($uid);
    $check('setup produced a secret', is_string($secret) && $secret !== '');
    $check('confirm enable with valid code', smsAuthenticatorConfirmEnable($uid, smsTotpCode((string) $secret)));
    $check('authenticator enabled before change', smsAuthenticatorIsEnabled($uid));

    $pdo = db();
    $rowBefore = $pdo->query("SELECT secret FROM sms2_user_authenticators WHERE user_id=$uid")->fetch(PDO::FETCH_ASSOC);
    $keyBefore = @file_get_contents(smsCryptoKeyPath());

    $changed = smsSetUserPassword($uid, 'NewStrongPass!2026', false);
    $check('password change succeeded', $changed);

    $rowAfter = $pdo->query("SELECT secret FROM sms2_user_authenticators WHERE user_id=$uid")->fetch(PDO::FETCH_ASSOC);
    $check('authenticator still enabled after password change', smsAuthenticatorIsEnabled($uid));
    $check('stored secret unchanged after password change', $rowBefore['secret'] === $rowAfter['secret']);
    $check('app key file unchanged', $keyBefore === @file_get_contents(smsCryptoKeyPath()));
    $check('valid code still verifies at login', smsAuthenticatorVerifyLogin($uid, smsTotpCode($secret)));
} finally {
    smsTestDeleteUser($user);
}

echo $ok ? "P0 RESULT: PASS\n" : "P0 RESULT: FAIL\n";
exit($ok ? 0 : 1);

<?php
/**
 * OTP lifetime test: every OTP must expire after SMS2_OTP_TTL_MINUTES (5 minutes).
 * Uses a dedicated zz_test_ account that is created and deleted by this test.
 * Run from the project root: php tests/otp_ttl_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/helpers/test_accounts.php';
require_once ROOT_PATH . '/includes/security-workflow.php';
require_once ROOT_PATH . '/includes/mail.php';

$user = 'zz_test_otpttl';
$ok = true;
$check = function (string $label, bool $cond) use (&$ok) {
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$cond) { $ok = false; }
};

smsTestCreateUser($user, 'superadmin', 'OtpTest!2026');
try {
    $uid = smsTestUserId($user);
    $pdo = db();
    $check('constant is 5 minutes', defined('SMS2_OTP_TTL_MINUTES') && SMS2_OTP_TTL_MINUTES === 5);
    $code = smsCreateOtp($uid, 'ttl_test', null);   // default TTL path
    $check('OTP code issued', is_string($code) && $code !== '');
    $row = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, created_at, expires_at) AS ttl FROM sms2_security_otps WHERE user_id=$uid AND purpose='ttl_test' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $check('stored OTP lifetime is 300 seconds (5 min), got ' . ($row['ttl'] ?? 'none'), (int) ($row['ttl'] ?? 0) === 300);
    $check('mail default TTL resolves to 5', (new ReflectionFunction('smsSendOtpEmail'))->getParameters()[3]->getDefaultValue() === 5);
} finally {
    smsTestDeleteUser($user);
}

echo $ok ? "OTP TTL RESULT: PASS\n" : "OTP TTL RESULT: FAIL\n";
exit($ok ? 0 : 1);

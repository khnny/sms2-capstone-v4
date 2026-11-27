<?php
/**
 * SMS 2 - Single sign-on for integrated apps (Student Portal and others).
 *
 * GET /login/sso-authorize.php?client_id=...&redirect_uri=...&state=...
 *
 * Not signed in: the request is remembered and the normal SMS 2 login runs
 * (password, OTP, Authenticator, passkey). Signed in: a one-time code is sent
 * back to the app's registered redirect_uri. The app exchanges it
 * server-to-server at POST /api/v1/auth/token.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/integration.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function smsSsoFail(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Sign-in error</title></head>'
        . '<body style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:60px auto;color:#0f172a">'
        . '<h1 style="font-size:20px">Sign-in could not continue</h1><p>' . e($message) . '</p>'
        . '<p><a href="' . e(BASE_URL . '/login/login.php') . '">Go to SMS 2 login</a></p></body></html>';
    exit;
}

function smsSsoRedirect(string $redirectUri, array $params): never
{
    $sep = str_contains($redirectUri, '?') ? '&' : '?';
    header('Location: ' . $redirectUri . $sep . http_build_query($params));
    exit;
}

$fromQuery = isset($_GET['client_id']);
if ($fromQuery) {
    $request = [
        'client_id' => trim((string) ($_GET['client_id'] ?? '')),
        'redirect_uri' => trim((string) ($_GET['redirect_uri'] ?? '')),
        'state' => trim((string) ($_GET['state'] ?? '')),
        'at' => time(),
    ];
} else {
    $request = $_SESSION['sso_pending'] ?? null;
    if (!is_array($request) || (time() - (int) ($request['at'] ?? 0)) > 900) {
        unset($_SESSION['sso_pending']);
        smsSsoFail('This sign-in link has expired. Go back to the portal and sign in again.');
    }
}

try {
    $client = smsApiClientFind((string) $request['client_id']);
} catch (Throwable $e) {
    error_log('SSO client lookup failed: ' . $e->getMessage());
    smsSsoFail('Sign-in is temporarily unavailable.', 503);
}
if (!$client) {
    smsSsoFail('Unknown application.');
}
if (!smsApiClientRedirectAllowed($client, (string) $request['redirect_uri'])) {
    // Never redirect to an unregistered URI.
    smsSsoFail('This application is not allowed to receive the sign-in at that address.');
}
$state = (string) $request['state'];
if ($state === '' || strlen($state) > 200 || !preg_match('/^[A-Za-z0-9_-]+$/', $state)) {
    smsSsoFail('Missing or invalid sign-in state.');
}

if (!isAuthenticated()) {
    $_SESSION['sso_pending'] = $request;
    header('Location: ' . BASE_URL . '/login/login.php');
    exit;
}
if (!empty($_SESSION['must_change_password'])) {
    $_SESSION['sso_pending'] = $request;
    header('Location: ' . BASE_URL . '/login/change-password.php');
    exit;
}

unset($_SESSION['sso_pending']);
$user = smsApiLoadUser((int) $_SESSION['user_id']);
if (!$user || !smsApiClientAllowsRole($client, (string) $user['role_key'])) {
    smsSsoRedirect((string) $request['redirect_uri'], ['error' => 'access_denied', 'state' => $state]);
}

$code = smsApiCreateAuthCode($client, (int) $user['id'], (string) $request['redirect_uri']);
logActivity('sso', 'Signed in to ' . (string) $client['name'], 'System', (int) $user['id'], (string) $user['full_name'], (string) $user['role_key'], false);
smsSsoRedirect((string) $request['redirect_uri'], ['code' => $code, 'state' => $state]);

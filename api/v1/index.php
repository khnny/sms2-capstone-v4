<?php
/**
 * SMS 2 - Integration REST API, version 1.
 *
 * Base URL: <SMS2>/api/v1/   (also works as <SMS2>/api/v1/index.php/<route>
 * or <SMS2>/api/v1/index.php?route=<route> when mod_rewrite is off)
 *
 * Auth:   POST auth/token   (code, refresh token, or password + 2FA code -> JWT access token)
 *         POST auth/mfa/email (email a login code while a password sign-in waits for 2FA)
 *         POST auth/revoke  (log out: revoke a refresh token)
 *         POST auth/ticket  (one-time link that opens an SMS 2 page, same user)
 * Data:   GET  me, me/research, me/research/statement, me/research/members, me/research/timeline,
 *              me/research/submissions, me/research/feedback, me/defenses,
 *              me/clearance, me/notifications, announcements
 * Ops:    GET  health
 *         POST internal/webhooks/run  (X-Cron-Token header, for web cron)
 *
 * See docs/INTEGRATION-API.md for request and response examples.
 */
declare(strict_types=1);

// Stateless: no cookie session. Existing helpers read $_SESSION, so an
// in-memory session is filled from the access token on each request.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid', '0');
ini_set('session.cache_limiter', '');
session_set_save_handler(new class implements SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max): int|false { return 0; }
}, true);
session_id('api' . bin2hex(random_bytes(12)));
session_start();
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/integration.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function apiRespond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(int $status, string $code, string $message): never
{
    apiRespond($status, ['error' => ['code' => $code, 'message' => $message]]);
}

function apiData($data): never
{
    apiRespond(200, ['data' => $data]);
}

/** JSON or form body. */
function apiInput(): array
{
    static $input = null;
    if ($input !== null) {
        return $input;
    }
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($type, 'application/json')) {
        $raw = (string) file_get_contents('php://input', false, null, 0, 1048576);
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : [];
    } else {
        $input = $_POST;
    }
    return $input;
}

function apiInputString(string $key): string
{
    $value = apiInput()[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
}

function apiHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }
    if ($name === 'Authorization') {
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    return (string) $v;
                }
            }
        }
    }
    return '';
}

/** Client credentials from HTTP Basic or the body. */
function apiAuthenticateClient(): array
{
    $id = apiInputString('client_id');
    $secret = apiInputString('client_secret');
    $basic = apiHeader('Authorization');
    if (preg_match('/^Basic\s+(\S+)$/i', $basic, $m)) {
        $pair = base64_decode($m[1], true);
        if (is_string($pair) && str_contains($pair, ':')) {
            [$id, $secret] = array_map('rawurldecode', explode(':', $pair, 2));
        }
    }
    $client = smsApiClientAuthenticate($id, $secret);
    if (!$client) {
        apiError(401, 'invalid_client', 'Unknown client or wrong client secret.');
    }
    return $client;
}

/** @return array{user: array, client: array, claims: array} */
function apiRequireUser(?string $role = null): array
{
    $auth = smsApiAuthenticateBearer(apiHeader('Authorization'));
    if (!$auth['ok']) {
        header('WWW-Authenticate: Bearer error="invalid_token"');
        apiError(401, (string) $auth['error'], 'A valid access token is required.');
    }
    if ($role !== null && $auth['user']['role_key'] !== $role) {
        apiError(403, 'forbidden', 'This endpoint is only available to ' . $role . ' accounts.');
    }
    require_once ROOT_PATH . '/includes/integration-data.php';
    smsApiActAsUser($auth['user']);
    return $auth;
}

function apiPublicBaseUrl(): string
{
    $configured = (string) (sms2_env('SMS2_PUBLIC_URL', '') ?? '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host . BASE_URL;
}

function apiRoute(): string
{
    $route = (string) ($_GET['route'] ?? '');
    if ($route === '' && !empty($_SERVER['PATH_INFO'])) {
        $route = (string) $_SERVER['PATH_INFO'];
    }
    if ($route === '') {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $pos = strpos($path, '/api/v1/');
        $route = $pos === false ? '' : substr($path, $pos + strlen('/api/v1/'));
        if (str_starts_with($route, 'index.php')) {
            $route = substr($route, strlen('index.php'));
        }
    }
    return trim($route, '/');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$route = apiRoute();

try {
    switch ($method . ' ' . $route) {
        case 'GET health':
        case 'GET ':
            apiData(['ok' => true, 'api' => 'sms2', 'version' => 'v1', 'time' => date('c')]);

        case 'POST auth/token':
            $client = apiAuthenticateClient();
            $grant = apiInputString('grant_type');
            if ($grant === 'authorization_code') {
                $result = smsApiRedeemAuthCode($client, apiInputString('code'), apiInputString('redirect_uri'));
                if (!$result['ok']) {
                    apiError($result['error'] === 'access_denied' ? 403 : 400, (string) $result['error'], 'The sign-in code is invalid, expired or already used.');
                }
                apiRespond(200, smsApiIssueTokens($client, $result['user']));
            }
            if ($grant === 'password' || $grant === 'mfa_code') {
                // The app's own login page. The app sends its visitor's IP so the
                // per-IP login throttle applies to the student, not to the app server.
                $visitorIp = apiInputString('client_ip');
                if ($visitorIp !== '' && filter_var($visitorIp, FILTER_VALIDATE_IP)) {
                    $_SERVER['REMOTE_ADDR'] = $visitorIp;
                }
                $result = $grant === 'password'
                    ? smsApiPasswordLogin($client, apiInputString('username'), (is_scalar(apiInput()['password'] ?? null) ? (string) apiInput()['password'] : ''))
                    : smsApiMfaVerify($client, apiInputString('mfa_token'), apiInputString('method'), apiInputString('code'));
                if (!empty($result['mfa'])) {
                    apiRespond(200, ['mfa_required' => true] + $result['mfa']);
                }
                if (empty($result['ok'])) {
                    apiError((int) $result['status'], (string) $result['error'], (string) $result['message']);
                }
                apiRespond(200, smsApiIssueTokens($client, $result['user']));
            }
            if ($grant === 'refresh_token') {
                $result = smsApiRefreshTokens($client, apiInputString('refresh_token'));
                if (!$result['ok']) {
                    apiError($result['error'] === 'access_denied' ? 403 : 400, (string) $result['error'], 'The refresh token is invalid or expired. Sign in again.');
                }
                apiRespond(200, $result['tokens']);
            }
            apiError(400, 'unsupported_grant_type', 'grant_type must be authorization_code, refresh_token, password or mfa_code.');

        case 'POST auth/mfa/email':
            $client = apiAuthenticateClient();
            $visitorIp = apiInputString('client_ip');
            if ($visitorIp !== '' && filter_var($visitorIp, FILTER_VALIDATE_IP)) {
                $_SERVER['REMOTE_ADDR'] = $visitorIp;
            }
            $result = smsApiMfaSendEmail($client, apiInputString('mfa_token'));
            if (empty($result['ok'])) {
                apiError((int) $result['status'], (string) $result['error'], (string) $result['message']);
            }
            apiData(['sent_to' => $result['sent_to']]);

        case 'POST auth/revoke':
            $client = apiAuthenticateClient();
            smsApiRevokeRefreshToken($client, apiInputString('refresh_token'));
            apiData(['revoked' => true]);

        case 'POST auth/ticket':
            $auth = apiRequireUser();
            $next = smsApiSafeNextPath(apiInputString('path'));
            if ($next === null) {
                apiError(422, 'invalid_path', 'path must be an SMS 2 page path such as /modules/student-portal/pages/submit-chapters.php');
            }
            $ticket = smsApiCreateSsoTicket($auth['client'], (int) $auth['user']['id'], $next);
            apiData([
                'url' => apiPublicBaseUrl() . '/login/sso-launch.php?ticket=' . rawurlencode($ticket),
                'expires_in' => SMS_API_TICKET_TTL,
            ]);

        case 'GET me':
            $auth = apiRequireUser();
            $me = smsApiPublicUser($auth['user']);
            if ($auth['user']['role_key'] === 'student') {
                $me['profile'] = smsApiStudentProfile($auth['user']);
            }
            apiData($me);

        case 'GET me/research':
            $auth = apiRequireUser('student');
            apiData(smsApiResearchSummary(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/research/statement':
            $auth = apiRequireUser('student');
            require_once ROOT_PATH . '/includes/research-statement.php';
            apiData(smsResearchStatement(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/research/members':
            $auth = apiRequireUser('student');
            apiData(smsApiGroupMembers(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/research/timeline':
            $auth = apiRequireUser('student');
            apiData(smsApiTimeline(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/research/submissions':
            $auth = apiRequireUser('student');
            apiData(smsApiSubmissions(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/research/feedback':
            $auth = apiRequireUser('student');
            apiData(smsApiFeedback(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/defenses':
            $auth = apiRequireUser('student');
            apiData(smsApiDefenses(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/clearance':
            $auth = apiRequireUser('student');
            apiData(smsApiClearance(getCradDatabaseConnection(), $auth['user']));

        case 'GET me/notifications':
            apiRequireUser();
            apiData(smsApiNotifications());

        case 'GET announcements':
            $auth = apiRequireUser();
            apiData(smsApiAnnouncements($auth['user']));

        case 'POST internal/webhooks/run':
            $expected = (string) (defined('SMS2_CRON_TOKEN') ? SMS2_CRON_TOKEN : (sms2_env('SMS2_CRON_TOKEN', '') ?? ''));
            if (strlen($expected) < 16 || !hash_equals($expected, apiHeader('X-Cron-Token'))) {
                apiError(404, 'not_found', 'Unknown endpoint.');
            }
            require_once ROOT_PATH . '/includes/webhooks.php';
            apiData(smsWebhookRun());
    }

    $known = ['health', 'auth/token', 'auth/mfa/email', 'auth/revoke', 'auth/ticket', 'me', 'me/research', 'me/research/statement', 'me/research/members', 'me/research/timeline',
        'me/research/submissions', 'me/research/feedback', 'me/defenses', 'me/clearance', 'me/notifications', 'announcements'];
    if (in_array($route, $known, true)) {
        apiError(405, 'method_not_allowed', 'Method not allowed for this endpoint.');
    }
    apiError(404, 'not_found', 'Unknown endpoint.');
} catch (Throwable $e) {
    error_log('API v1 ' . $method . ' ' . $route . ' failed: ' . $e->getMessage());
    apiError(500, 'server_error', 'Something went wrong. Try again later.');
}

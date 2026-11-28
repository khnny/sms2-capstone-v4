<?php
/**
 * SMS 2 - Integration layer for separately deployed apps (Student Portal and
 * future modules such as Registrar, Payment and Accreditation).
 *
 *   - Login:   login/sso-authorize.php issues a one-time code after the normal SMS 2
 *              login (password, OTP, Authenticator, passkey all unchanged).
 *   - Tokens:  POST api/v1/auth/token swaps the code (server-to-server, with
 *              the client secret) for a short-lived JWT access token and a
 *              rotating refresh token.
 *   - SSO:     POST api/v1/auth/ticket + login/sso-launch.php opens an existing SMS 2
 *              page for the same user without a second login.
 *   - Webhooks: includes/webhooks.php.
 *
 * Every table here is an sms2_* table (auth side). Nothing in this file writes
 * to CRAD tables.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/crypto.php';

const SMS_API_AUDIENCE = 'sms2-api';
const SMS_API_ACCESS_TTL = 900;            // 15 minutes
const SMS_API_REFRESH_TTL = 8 * 3600;      // 8 hours, rotated on every use
const SMS_API_CODE_TTL = 60;               // one-time login code
const SMS_API_TICKET_TTL = 60;             // one-time SSO ticket

function smsIntegrationJwtSecret(): string
{
    static $secret = null;
    if (is_string($secret)) {
        return $secret;
    }
    $configured = '';
    if (defined('SMS2_JWT_SECRET') && is_string(SMS2_JWT_SECRET)) {
        $configured = SMS2_JWT_SECRET;
    } elseif (function_exists('sms2_env')) {
        $configured = (string) sms2_env('SMS2_JWT_SECRET', '');
    }
    if ($configured !== '') {
        if (strlen($configured) < 32) {
            throw new RuntimeException('SMS2_JWT_SECRET must be at least 32 characters.');
        }
        return $secret = $configured;
    }

    // Local fallback: a random key kept next to app.key (storage/keys is web-denied).
    $path = ROOT_PATH . '/storage/keys/jwt.key';
    if (is_readable($path)) {
        $stored = trim((string) file_get_contents($path));
        if (strlen($stored) >= 64) {
            return $secret = $stored;
        }
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $generated = bin2hex(random_bytes(32));
    if (@file_put_contents($path, $generated, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write storage/keys/jwt.key. Set SMS2_JWT_SECRET instead.');
    }
    @chmod($path, 0600);
    return $secret = $generated;
}

function smsIntegrationIssuer(): string
{
    if (defined('SMS2_JWT_ISSUER') && is_string(SMS2_JWT_ISSUER) && SMS2_JWT_ISSUER !== '') {
        return SMS2_JWT_ISSUER;
    }
    return (string) (sms2_env('SMS2_JWT_ISSUER', 'sms2-auth') ?? 'sms2-auth');
}

function smsIntegrationEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $statements = [
        "CREATE TABLE IF NOT EXISTS `sms2_api_clients` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `client_id` varchar(80) NOT NULL,
            `name` varchar(150) NOT NULL,
            `secret_hash` varchar(255) NOT NULL,
            `redirect_uris` text NOT NULL,
            `allowed_roles` varchar(255) NOT NULL DEFAULT 'student',
            `webhook_url` varchar(500) DEFAULT NULL,
            `webhook_secret` text DEFAULT NULL,
            `webhook_events` varchar(500) NOT NULL DEFAULT '*',
            `status` enum('active','disabled') NOT NULL DEFAULT 'active',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_api_client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `sms2_api_auth_codes` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `code_hash` char(64) NOT NULL,
            `client_id` varchar(80) NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            `redirect_uri` varchar(500) NOT NULL,
            `expires_at` datetime NOT NULL,
            `used_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_api_code_hash` (`code_hash`),
            KEY `idx_api_code_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `sms2_api_refresh_tokens` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `token_hash` char(64) NOT NULL,
            `client_id` varchar(80) NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            `expires_at` datetime NOT NULL,
            `revoked_at` datetime DEFAULT NULL,
            `last_used_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_api_refresh_hash` (`token_hash`),
            KEY `idx_api_refresh_user` (`user_id`, `client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `sms2_api_sso_tickets` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `ticket_hash` char(64) NOT NULL,
            `client_id` varchar(80) NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            `next_path` varchar(500) NOT NULL,
            `expires_at` datetime NOT NULL,
            `used_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_api_ticket_hash` (`ticket_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
    $done = true;
}

function smsIntegrationDb(): PDO
{
    $pdo = getDatabaseConnection();
    smsIntegrationEnsureSchema($pdo);
    smsApiClientSyncFromEnv($pdo);
    return $pdo;
}

/**
 * Hosting without a terminal (HostForge): the Student Portal client can be
 * defined by Environment Variables instead of tools/register-api-client.php.
 *
 *   SMS2_PORTAL_CLIENT_SECRET   at least 32 characters (same value on the portal)
 *   SMS2_PORTAL_REDIRECT_URI    https://<portal>/callback.php
 *   SMS2_PORTAL_WEBHOOK_URL     https://<portal>/webhook.php (optional)
 *   SMS2_PORTAL_WEBHOOK_SECRET  at least 32 characters (same value on the portal)
 *
 * The database row is created or corrected to match on the next request.
 */
function smsApiClientSyncFromEnv(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $secret = (string) (sms2_env('SMS2_PORTAL_CLIENT_SECRET', '') ?? '');
    $redirect = trim((string) (sms2_env('SMS2_PORTAL_REDIRECT_URI', '') ?? ''));
    if ($secret === '' || $redirect === '') {
        return;
    }
    if (strlen($secret) < 32) {
        error_log('SMS2_PORTAL_CLIENT_SECRET is shorter than 32 characters; portal client not synced.');
        return;
    }
    $webhookUrl = trim((string) (sms2_env('SMS2_PORTAL_WEBHOOK_URL', '') ?? '')) ?: null;
    $webhookSecret = (string) (sms2_env('SMS2_PORTAL_WEBHOOK_SECRET', '') ?? '');

    try {
        $stmt = $pdo->prepare('SELECT * FROM `sms2_api_clients` WHERE client_id = ?');
        $stmt->execute(['student-portal']);
        $row = $stmt->fetch() ?: null;
        $storedWebhookSecret = '';
        if ($row && !empty($row['webhook_secret'])) {
            try {
                $storedWebhookSecret = smsSecretDecrypt((string) $row['webhook_secret']);
            } catch (Throwable $e) {
                $storedWebhookSecret = '';
            }
        }
        $inSync = $row
            && $row['status'] === 'active'
            && password_verify($secret, (string) $row['secret_hash'])
            && trim((string) $row['redirect_uris']) === $redirect
            && ($row['webhook_url'] ?: null) === $webhookUrl
            && $storedWebhookSecret === $webhookSecret;
        if ($inSync) {
            return;
        }
        $values = [
            'secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'redirect_uris' => $redirect,
            'webhook_url' => $webhookUrl,
            'webhook_secret' => $webhookSecret !== '' ? smsSecretEncrypt($webhookSecret) : null,
            'client_id' => 'student-portal',
        ];
        if ($row) {
            $pdo->prepare("UPDATE `sms2_api_clients` SET secret_hash = :secret_hash, redirect_uris = :redirect_uris, webhook_url = :webhook_url,
                           webhook_secret = :webhook_secret, allowed_roles = 'student', status = 'active' WHERE client_id = :client_id")->execute($values);
        } else {
            $pdo->prepare("INSERT INTO `sms2_api_clients` (client_id, name, secret_hash, redirect_uris, allowed_roles, webhook_url, webhook_secret, webhook_events)
                           VALUES (:client_id, 'Student Portal', :secret_hash, :redirect_uris, 'student', :webhook_url, :webhook_secret, '*')")->execute($values);
        }
    } catch (Throwable $e) {
        error_log('Portal client sync from environment failed: ' . $e->getMessage());
    }
}

function smsIntegrationRandomToken(int $bytes = 32): string
{
    return smsJwtBase64UrlEncode(random_bytes($bytes));
}

function smsIntegrationHash(string $raw): string
{
    return hash('sha256', $raw);
}

function smsApiClientFind(string $clientId): ?array
{
    if ($clientId === '' || strlen($clientId) > 80) {
        return null;
    }
    $stmt = smsIntegrationDb()->prepare("SELECT * FROM `sms2_api_clients` WHERE client_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function smsApiClientAuthenticate(string $clientId, string $clientSecret): ?array
{
    $client = smsApiClientFind($clientId);
    if (!$client || $clientSecret === '' || !password_verify($clientSecret, (string) $client['secret_hash'])) {
        return null;
    }
    return $client;
}

function smsApiClientRedirectAllowed(array $client, string $redirectUri): bool
{
    $allowed = preg_split('/\s+/', trim((string) $client['redirect_uris'])) ?: [];
    // Exact match only: no prefix or wildcard matching.
    return $redirectUri !== '' && in_array($redirectUri, $allowed, true);
}

/** @return list<string> */
function smsApiClientRoles(array $client): array
{
    $roles = array_filter(array_map('trim', explode(',', (string) $client['allowed_roles'])));
    return array_values($roles);
}

function smsApiClientAllowsRole(array $client, string $roleKey): bool
{
    $roles = smsApiClientRoles($client);
    return in_array('*', $roles, true) || in_array($roleKey, $roles, true);
}

/**
 * Create or update a client. Returns the plain secret when one is (re)generated.
 *
 * @param array{name?: string, redirect_uris?: string, allowed_roles?: string, webhook_url?: ?string, webhook_events?: string, rotate_secret?: bool, rotate_webhook_secret?: bool} $opts
 * @return array{client_id: string, client_secret: ?string, webhook_secret: ?string}
 */
function smsApiClientUpsert(string $clientId, array $opts): array
{
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,79}$/', $clientId)) {
        throw new InvalidArgumentException('client_id must be 3-80 chars: lowercase letters, digits, dot, dash, underscore.');
    }
    $pdo = smsIntegrationDb();
    $stmt = $pdo->prepare('SELECT * FROM `sms2_api_clients` WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $existing = $stmt->fetch() ?: null;

    $plainSecret = null;
    $plainWebhookSecret = null;
    if (!$existing || !empty($opts['rotate_secret'])) {
        $plainSecret = smsIntegrationRandomToken(32);
    }
    if (array_key_exists('webhook_url', $opts) && $opts['webhook_url'] && (!$existing || empty($existing['webhook_secret']) || !empty($opts['rotate_webhook_secret']))) {
        $plainWebhookSecret = smsIntegrationRandomToken(32);
    }

    $fields = [
        'name' => (string) ($opts['name'] ?? ($existing['name'] ?? $clientId)),
        'redirect_uris' => trim((string) ($opts['redirect_uris'] ?? ($existing['redirect_uris'] ?? ''))),
        'allowed_roles' => (string) ($opts['allowed_roles'] ?? ($existing['allowed_roles'] ?? 'student')),
        'webhook_url' => array_key_exists('webhook_url', $opts) ? ($opts['webhook_url'] ?: null) : ($existing['webhook_url'] ?? null),
        'webhook_events' => (string) ($opts['webhook_events'] ?? ($existing['webhook_events'] ?? '*')),
    ];
    foreach (preg_split('/\s+/', $fields['redirect_uris']) ?: [] as $uri) {
        if ($uri !== '' && !filter_var($uri, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid redirect URI: ' . $uri);
        }
    }
    if ($fields['webhook_url'] !== null && !filter_var($fields['webhook_url'], FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid webhook URL.');
    }

    if ($existing) {
        $sql = 'UPDATE `sms2_api_clients` SET name = :name, redirect_uris = :redirect_uris, allowed_roles = :allowed_roles,
                webhook_url = :webhook_url, webhook_events = :webhook_events, status = \'active\''
            . ($plainSecret !== null ? ', secret_hash = :secret_hash' : '')
            . ($plainWebhookSecret !== null ? ', webhook_secret = :webhook_secret' : '')
            . ' WHERE client_id = :client_id';
    } else {
        $sql = 'INSERT INTO `sms2_api_clients` (client_id, name, secret_hash, redirect_uris, allowed_roles, webhook_url, webhook_secret, webhook_events)
                VALUES (:client_id, :name, :secret_hash, :redirect_uris, :allowed_roles, :webhook_url, :webhook_secret, :webhook_events)';
    }
    $params = $fields + ['client_id' => $clientId];
    if ($plainSecret !== null) {
        $params['secret_hash'] = password_hash($plainSecret, PASSWORD_DEFAULT);
    }
    if ($plainWebhookSecret !== null) {
        $params['webhook_secret'] = smsSecretEncrypt($plainWebhookSecret);
    } elseif (!$existing) {
        $params['webhook_secret'] = null;
    }
    $pdo->prepare($sql)->execute($params);

    return ['client_id' => $clientId, 'client_secret' => $plainSecret, 'webhook_secret' => $plainWebhookSecret];
}

function smsApiClientWebhookSecret(array $client): string
{
    $stored = (string) ($client['webhook_secret'] ?? '');
    return $stored === '' ? '' : smsSecretDecrypt($stored);
}

/** Active user with role label, or null. */
function smsApiLoadUser(int $userId): ?array
{
    $stmt = getDatabaseConnection()->prepare(
        "SELECT u.id, u.username, u.email, u.full_name, u.role_key, u.student_id, u.status,
                COALESCE(r.label, u.role_key) AS role_label
         FROM `sms2_users` u
         LEFT JOIN `sms2_roles` r ON r.role_key = u.role_key
         WHERE u.id = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !in_array((string) $user['status'], ['active'], true)) {
        return null;
    }
    return $user;
}

function smsApiCreateAuthCode(array $client, int $userId, string $redirectUri): string
{
    $raw = smsIntegrationRandomToken(32);
    $pdo = smsIntegrationDb();
    $pdo->exec('DELETE FROM `sms2_api_auth_codes` WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
    $pdo->prepare(
        'INSERT INTO `sms2_api_auth_codes` (code_hash, client_id, user_id, redirect_uri, expires_at)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . SMS_API_CODE_TTL . ' SECOND)'
    )->execute([smsIntegrationHash($raw), (string) $client['client_id'], $userId, $redirectUri]);
    return $raw;
}

/**
 * One-time, atomic code redemption.
 *
 * @return array{ok: bool, user?: array, error?: string}
 */
function smsApiRedeemAuthCode(array $client, string $rawCode, string $redirectUri): array
{
    $pdo = smsIntegrationDb();
    $hash = smsIntegrationHash($rawCode);
    $update = $pdo->prepare(
        'UPDATE `sms2_api_auth_codes` SET used_at = NOW()
         WHERE code_hash = ? AND client_id = ? AND redirect_uri = ? AND used_at IS NULL AND expires_at >= NOW()'
    );
    $update->execute([$hash, (string) $client['client_id'], $redirectUri]);
    if ($update->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'invalid_grant'];
    }
    $stmt = $pdo->prepare('SELECT user_id FROM `sms2_api_auth_codes` WHERE code_hash = ?');
    $stmt->execute([$hash]);
    $user = smsApiLoadUser((int) $stmt->fetchColumn());
    if (!$user || !smsApiClientAllowsRole($client, (string) $user['role_key'])) {
        return ['ok' => false, 'error' => 'access_denied'];
    }
    return ['ok' => true, 'user' => $user];
}

/** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, user: array} */
function smsApiIssueTokens(array $client, array $user): array
{
    $now = time();
    $claims = [
        'iss' => smsIntegrationIssuer(),
        'aud' => SMS_API_AUDIENCE,
        'azp' => (string) $client['client_id'],
        'sub' => (string) $user['id'],
        'role' => (string) $user['role_key'],
        'student_id' => $user['student_id'] !== null ? (string) $user['student_id'] : null,
        'name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + SMS_API_ACCESS_TTL,
        'jti' => bin2hex(random_bytes(12)),
    ];
    $refresh = smsIntegrationRandomToken(32);
    smsIntegrationDb()->prepare(
        'INSERT INTO `sms2_api_refresh_tokens` (token_hash, client_id, user_id, expires_at)
         VALUES (?, ?, ?, NOW() + INTERVAL ' . SMS_API_REFRESH_TTL . ' SECOND)'
    )->execute([smsIntegrationHash($refresh), (string) $client['client_id'], (int) $user['id']]);

    return [
        'access_token' => smsJwtEncode($claims, smsIntegrationJwtSecret()),
        'token_type' => 'Bearer',
        'expires_in' => SMS_API_ACCESS_TTL,
        'refresh_token' => $refresh,
        'user' => smsApiPublicUser($user),
    ];
}

function smsApiPublicUser(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role_key'],
        'role_label' => (string) ($user['role_label'] ?? $user['role_key']),
        'student_id' => $user['student_id'] !== null ? (string) $user['student_id'] : null,
    ];
}

/**
 * Rotate a refresh token: the old one is revoked, a new pair is issued.
 * A revoked token presented again revokes the whole session for that client (reuse detection).
 */
function smsApiRefreshTokens(array $client, string $rawRefresh): array
{
    $pdo = smsIntegrationDb();
    $hash = smsIntegrationHash($rawRefresh);
    $stmt = $pdo->prepare('SELECT *, (expires_at < NOW()) AS is_expired FROM `sms2_api_refresh_tokens` WHERE token_hash = ? AND client_id = ? LIMIT 1');
    $stmt->execute([$hash, (string) $client['client_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'invalid_grant'];
    }
    if ($row['revoked_at'] !== null) {
        $pdo->prepare('UPDATE `sms2_api_refresh_tokens` SET revoked_at = NOW() WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL')
            ->execute([(int) $row['user_id'], (string) $client['client_id']]);
        return ['ok' => false, 'error' => 'invalid_grant'];
    }
    if ((int) $row['is_expired'] === 1) { // compared in SQL: PHP and MySQL time zones may differ
        return ['ok' => false, 'error' => 'invalid_grant'];
    }
    $revoke = $pdo->prepare('UPDATE `sms2_api_refresh_tokens` SET revoked_at = NOW(), last_used_at = NOW() WHERE id = ? AND revoked_at IS NULL');
    $revoke->execute([(int) $row['id']]);
    if ($revoke->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'invalid_grant'];
    }
    $user = smsApiLoadUser((int) $row['user_id']);
    if (!$user || !smsApiClientAllowsRole($client, (string) $user['role_key'])) {
        return ['ok' => false, 'error' => 'access_denied'];
    }
    return ['ok' => true, 'tokens' => smsApiIssueTokens($client, $user)];
}

function smsApiRevokeRefreshToken(array $client, string $rawRefresh): void
{
    smsIntegrationDb()->prepare('UPDATE `sms2_api_refresh_tokens` SET revoked_at = NOW() WHERE token_hash = ? AND client_id = ? AND revoked_at IS NULL')
        ->execute([smsIntegrationHash($rawRefresh), (string) $client['client_id']]);
}

/** Validates a Bearer token. @return array{ok: bool, claims?: array, user?: array, client?: array, error?: string} */
function smsApiAuthenticateBearer(string $authorizationHeader): array
{
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorizationHeader), $m)) {
        return ['ok' => false, 'error' => 'missing_token'];
    }
    $decoded = smsJwtDecode($m[1], smsIntegrationJwtSecret(), smsIntegrationIssuer(), SMS_API_AUDIENCE);
    if (!$decoded['ok']) {
        return ['ok' => false, 'error' => 'invalid_token'];
    }
    $claims = $decoded['claims'];
    $client = smsApiClientFind((string) ($claims['azp'] ?? ''));
    $user = smsApiLoadUser((int) ($claims['sub'] ?? 0));
    // Re-checked on every request: a deactivated account or client loses access at once.
    if (!$client || !$user || !smsApiClientAllowsRole($client, (string) $user['role_key'])) {
        return ['ok' => false, 'error' => 'invalid_token'];
    }
    return ['ok' => true, 'claims' => $claims, 'user' => $user, 'client' => $client];
}

/** Only relative paths inside this app; blocks open redirects. */
function smsApiSafeNextPath(string $next): ?string
{
    $next = trim($next);
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\')
        || preg_match('/[\x00-\x1f]/', $next) || str_contains($next, '..')) {
        return null;
    }
    $allowedPrefixes = ['/modules/student-portal/', '/modules/crad/', '/communication/', '/notifications/', '/dashboard/', '/account/'];
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($next, $prefix)) {
            return $next;
        }
    }
    return null;
}

function smsApiCreateSsoTicket(array $client, int $userId, string $nextPath): string
{
    $raw = smsIntegrationRandomToken(32);
    $pdo = smsIntegrationDb();
    $pdo->exec('DELETE FROM `sms2_api_sso_tickets` WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
    $pdo->prepare(
        'INSERT INTO `sms2_api_sso_tickets` (ticket_hash, client_id, user_id, next_path, expires_at)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . SMS_API_TICKET_TTL . ' SECOND)'
    )->execute([smsIntegrationHash($raw), (string) $client['client_id'], $userId, $nextPath]);
    return $raw;
}

/** @return array{user: array, next_path: string}|null */
function smsApiConsumeSsoTicket(string $rawTicket): ?array
{
    if ($rawTicket === '' || strlen($rawTicket) > 200) {
        return null;
    }
    $pdo = smsIntegrationDb();
    $hash = smsIntegrationHash($rawTicket);
    $update = $pdo->prepare('UPDATE `sms2_api_sso_tickets` SET used_at = NOW() WHERE ticket_hash = ? AND used_at IS NULL AND expires_at >= NOW()');
    $update->execute([$hash]);
    if ($update->rowCount() !== 1) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM `sms2_api_sso_tickets` WHERE ticket_hash = ?');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    $client = $row ? smsApiClientFind((string) $row['client_id']) : null;
    $user = $row ? smsApiLoadUser((int) $row['user_id']) : null;
    if (!$row || !$client || !$user || !smsApiClientAllowsRole($client, (string) $user['role_key'])) {
        return null;
    }
    return ['user' => $user, 'next_path' => (string) $row['next_path']];
}

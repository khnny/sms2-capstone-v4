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
 *   - Portal login form: POST api/v1/auth/token with grant_type=password lets a
 *              trusted app show its own login page. The password is still checked
 *              here, with the same lockout and Authenticator rules (smsApiPasswordLogin).
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
const SMS_API_MFA_TTL = 600;               // password OK, waiting for the 2FA code
const SMS_API_MFA_MAX_EMAILS = 3;          // email codes per sign-in attempt
const SMS_API_MFA_EMAIL_COOLDOWN = 60;     // seconds between email codes

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
            `scopes` varchar(500) NOT NULL DEFAULT '*',
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
        "CREATE TABLE IF NOT EXISTS `sms2_api_mfa_challenges` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `token_hash` char(64) NOT NULL,
            `client_id` varchar(80) NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            `login_name` varchar(190) NOT NULL DEFAULT '',
            `attempts` int(10) unsigned NOT NULL DEFAULT 0,
            `email_sends` int(10) unsigned NOT NULL DEFAULT 0,
            `last_email_at` datetime DEFAULT NULL,
            `expires_at` datetime NOT NULL,
            `used_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_api_mfa_hash` (`token_hash`),
            KEY `idx_api_mfa_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
    // Older installs: add the per-client scopes column. Existing clients keep full access ('*').
    $hasScopes = $pdo->query("SHOW COLUMNS FROM `sms2_api_clients` LIKE 'scopes'")->fetch();
    if (!$hasScopes) {
        $pdo->exec("ALTER TABLE `sms2_api_clients` ADD COLUMN `scopes` varchar(500) NOT NULL DEFAULT '*' AFTER `webhook_events`");
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

/* ------------------------------------------------------- module clients and scopes */

/**
 * What a client may read or do. A route needs its scope; '*' means every scope
 * (the Student Portal, and clients registered before scopes existed).
 *
 * @return array<string, string> scope => description
 */
function smsApiScopeCatalog(): array
{
    return [
        'profile' => 'GET me (name, email, role; student profile)',
        'research.read' => 'Research group, title, members, timeline, submissions, feedback, step forms (GET)',
        'research.write' => 'Student research actions: group, title, chapters, progress, panel, manuscript (POST)',
        'defenses.read' => 'GET me/defenses and defense.* webhooks',
        'clearance.read' => 'Research clearances in GET me/clearance, GET me/research/clearance-form, clearance webhooks',
        'clearance.write' => 'POST me/research/clearance-form (signed clearance upload)',
        'payment.read' => 'Payments in GET me/clearance, GET me/research/payment, payment webhooks',
        'payment.write' => 'POST me/research/payment (proof of payment upload)',
        'statement.read' => 'GET me/research/statement (the whole CRAD process, payments included)',
        'notifications.read' => 'GET me/notifications',
        'announcements.read' => 'GET announcements',
        'sso' => 'POST auth/ticket (open an SMS 2 page as the same user)',
    ];
}

/**
 * One preset per CRAD integration. tools/register-api-client.php and the
 * Environment Variables path use these defaults, so every module key is set
 * up the same way. A registrar key cannot read payments and a payment key
 * cannot read research data.
 *
 * @return array<string, array{name: string, env: string, roles: string, scopes: string, events: string}>
 */
function smsApiModulePresets(): array
{
    return [
        'student-portal' => [
            'name' => 'Student Portal',
            'env' => 'SMS2_PORTAL',
            'roles' => 'student',
            'scopes' => '*',
            'events' => '*',
        ],
        'registrar' => [
            'name' => 'Registrar',
            'env' => 'SMS2_REGISTRAR',
            'roles' => 'student,registrar',
            'scopes' => 'profile research.read defenses.read clearance.read notifications.read announcements.read sso',
            'events' => 'research.*,defense.*,clearance.status_changed,manuscript.final_approved',
        ],
        'payment' => [
            'name' => 'Payment',
            'env' => 'SMS2_PAYMENT',
            'roles' => 'student,finance',
            'scopes' => 'profile payment.read payment.write notifications.read announcements.read sso',
            'events' => 'clearance.status_changed',
        ],
        'faculty' => [
            'name' => 'Faculty',
            'env' => 'SMS2_FACULTY',
            'roles' => 'adviser,panel,grammarian,research_coordinator,research_director,hr',
            'scopes' => 'profile defenses.read notifications.read announcements.read sso',
            'events' => 'research.adviser_assigned,defense.*',
        ],
        'accreditation' => [
            'name' => 'Accreditation',
            'env' => 'SMS2_ACCREDITATION',
            'roles' => 'qa',
            'scopes' => 'profile research.read announcements.read sso',
            'events' => 'research.title_status_changed,manuscript.final_approved',
        ],
    ];
}

function smsApiModulePreset(string $clientId): ?array
{
    return smsApiModulePresets()[$clientId] ?? null;
}

/** @return list<string> */
function smsApiParseScopes(string $raw): array
{
    $scopes = preg_split('/[\s,]+/', trim($raw)) ?: [];
    return array_values(array_unique(array_filter($scopes, 'strlen')));
}

/** Normalised scope string; throws on an unknown scope. */
function smsApiNormalizeScopes(string $raw): string
{
    $scopes = smsApiParseScopes($raw);
    if ($scopes === [] || in_array('*', $scopes, true)) {
        return '*';
    }
    $known = smsApiScopeCatalog();
    foreach ($scopes as $scope) {
        if (!isset($known[$scope])) {
            throw new InvalidArgumentException('Unknown scope: ' . $scope . '. Known: ' . implode(' ', array_keys($known)));
        }
    }
    return implode(' ', $scopes);
}

/** @return list<string> */
function smsApiClientScopes(array $client): array
{
    return smsApiParseScopes((string) ($client['scopes'] ?? '*') ?: '*');
}

function smsApiClientHasScope(array $client, string $scope): bool
{
    $scopes = smsApiClientScopes($client);
    return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
}

/** Scope needed to receive a webhook event (null = no data scope needed). */
function smsApiEventScope(string $eventType, array $data = []): ?string
{
    if ($eventType === 'clearance.status_changed') {
        return (($data['kind'] ?? '') === 'payment') ? 'payment.read' : 'clearance.read';
    }
    if (str_starts_with($eventType, 'defense.')) {
        return 'defenses.read';
    }
    if (str_starts_with($eventType, 'research.') || str_starts_with($eventType, 'submission.') || str_starts_with($eventType, 'manuscript.')) {
        return 'research.read';
    }
    return null;
}

/**
 * Hosting without a terminal (HostForge): each module client can be defined
 * by Environment Variables instead of tools/register-api-client.php.
 * PREFIX is SMS2_PORTAL, SMS2_REGISTRAR, SMS2_PAYMENT, SMS2_FACULTY or SMS2_ACCREDITATION.
 *
 *   PREFIX_CLIENT_SECRET   at least 32 characters (same value on the module app)
 *   PREFIX_REDIRECT_URI    https://<module>/callback.php (space-separate for several; optional)
 *   PREFIX_WEBHOOK_URL     https://<module>/webhook.php (optional)
 *   PREFIX_WEBHOOK_SECRET  at least 32 characters (same value on the module app)
 *   PREFIX_ROLES, PREFIX_SCOPES, PREFIX_EVENTS  optional overrides of the preset
 *
 * The database row is created or corrected to match on the next request.
 * Leave PREFIX_CLIENT_SECRET unset to manage that client with the CLI instead.
 */
function smsApiClientSyncFromEnv(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (smsApiModulePresets() as $clientId => $preset) {
        smsApiClientSyncOneFromEnv($pdo, $clientId, $preset);
    }
}

/** @return array{status: string, message?: string} status: skipped|invalid|in_sync|created|updated|error */
function smsApiClientSyncOneFromEnv(PDO $pdo, string $clientId, array $preset): array
{
    $env = static fn(string $suffix): string => trim((string) (sms2_env($preset['env'] . '_' . $suffix, '') ?? ''));
    $secret = $env('CLIENT_SECRET');
    if ($secret === '') {
        return ['status' => 'skipped'];
    }
    if (strlen($secret) < 32) {
        error_log($preset['env'] . '_CLIENT_SECRET is shorter than 32 characters; ' . $clientId . ' client not synced.');
        return ['status' => 'invalid', 'message' => 'client secret shorter than 32 characters'];
    }
    $webhookSecret = $env('WEBHOOK_SECRET');
    if ($webhookSecret !== '' && strlen($webhookSecret) < 32) {
        error_log($preset['env'] . '_WEBHOOK_SECRET is shorter than 32 characters; ' . $clientId . ' client not synced.');
        return ['status' => 'invalid', 'message' => 'webhook secret shorter than 32 characters'];
    }
    try {
        $redirect = implode("\n", preg_split('/\s+/', $env('REDIRECT_URI'), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $webhookUrl = $env('WEBHOOK_URL') ?: null;
        $roles = $env('ROLES') ?: $preset['roles'];
        $scopes = smsApiNormalizeScopes($env('SCOPES') ?: $preset['scopes']);
        $events = $env('EVENTS') ?: $preset['events'];

        $stmt = $pdo->prepare('SELECT * FROM `sms2_api_clients` WHERE client_id = ?');
        $stmt->execute([$clientId]);
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
            && $storedWebhookSecret === $webhookSecret
            && (string) $row['allowed_roles'] === $roles
            && (string) ($row['scopes'] ?? '*') === $scopes
            && (string) $row['webhook_events'] === $events;
        if ($inSync) {
            return ['status' => 'in_sync'];
        }
        $values = [
            'secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'redirect_uris' => $redirect,
            'webhook_url' => $webhookUrl,
            'webhook_secret' => $webhookSecret !== '' ? smsSecretEncrypt($webhookSecret) : null,
            'allowed_roles' => $roles,
            'scopes' => $scopes,
            'webhook_events' => $events,
            'client_id' => $clientId,
        ];
        if ($row) {
            $pdo->prepare("UPDATE `sms2_api_clients` SET secret_hash = :secret_hash, redirect_uris = :redirect_uris, webhook_url = :webhook_url,
                           webhook_secret = :webhook_secret, allowed_roles = :allowed_roles, scopes = :scopes, webhook_events = :webhook_events,
                           status = 'active' WHERE client_id = :client_id")->execute($values);
            return ['status' => 'updated'];
        }
        $pdo->prepare("INSERT INTO `sms2_api_clients` (client_id, name, secret_hash, redirect_uris, allowed_roles, scopes, webhook_url, webhook_secret, webhook_events)
                       VALUES (:client_id, :name, :secret_hash, :redirect_uris, :allowed_roles, :scopes, :webhook_url, :webhook_secret, :webhook_events)")
            ->execute($values + ['name' => $preset['name']]);
        return ['status' => 'created'];
    } catch (Throwable $e) {
        error_log($clientId . ' client sync from environment failed: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
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
 * @param array{name?: string, redirect_uris?: string, allowed_roles?: string, scopes?: string, webhook_url?: ?string, webhook_events?: string, preset?: string, apply_preset?: bool, rotate_secret?: bool, rotate_webhook_secret?: bool} $opts
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

    // Module presets (registrar, payment, ...) fill the defaults of a new client,
    // or reset an existing one when 'apply_preset' (or a named 'preset') is given.
    // Explicit options win.
    $presetName = (string) ($opts['preset'] ?? $clientId);
    $preset = smsApiModulePreset($presetName);
    if (isset($opts['preset']) && !$preset) {
        throw new InvalidArgumentException('Unknown preset: ' . $presetName . '. Presets: ' . implode(', ', array_keys(smsApiModulePresets())));
    }
    $base = $existing ?? [];
    if ($preset && (!$existing || !empty($opts['apply_preset']) || isset($opts['preset']))) {
        $base = [
            'name' => $existing['name'] ?? $preset['name'],
            'allowed_roles' => $preset['roles'],
            'scopes' => $preset['scopes'],
            'webhook_events' => $preset['events'],
        ] + $base;
    }
    $fields = [
        'name' => (string) ($opts['name'] ?? ($base['name'] ?? $clientId)),
        'redirect_uris' => trim((string) ($opts['redirect_uris'] ?? ($existing['redirect_uris'] ?? ''))),
        'allowed_roles' => (string) ($opts['allowed_roles'] ?? ($base['allowed_roles'] ?? 'student')),
        'scopes' => smsApiNormalizeScopes((string) ($opts['scopes'] ?? ($base['scopes'] ?? '*'))),
        'webhook_url' => array_key_exists('webhook_url', $opts) ? ($opts['webhook_url'] ?: null) : ($existing['webhook_url'] ?? null),
        'webhook_events' => (string) ($opts['webhook_events'] ?? ($base['webhook_events'] ?? '*')),
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
        $sql = 'UPDATE `sms2_api_clients` SET name = :name, redirect_uris = :redirect_uris, allowed_roles = :allowed_roles, scopes = :scopes,
                webhook_url = :webhook_url, webhook_events = :webhook_events, status = \'active\''
            . ($plainSecret !== null ? ', secret_hash = :secret_hash' : '')
            . ($plainWebhookSecret !== null ? ', webhook_secret = :webhook_secret' : '')
            . ' WHERE client_id = :client_id';
    } else {
        $sql = 'INSERT INTO `sms2_api_clients` (client_id, name, secret_hash, redirect_uris, allowed_roles, scopes, webhook_url, webhook_secret, webhook_events)
                VALUES (:client_id, :name, :secret_hash, :redirect_uris, :allowed_roles, :scopes, :webhook_url, :webhook_secret, :webhook_events)';
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
        'scope' => implode(' ', smsApiClientScopes($client)),
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
        'scope' => implode(' ', smsApiClientScopes($client)),
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

/* ------------------------------------------------- app login form (password grant) */

/**
 * Check a student's SMS 2 password for a trusted app's own login page.
 * Uses smsLoginAttempt(), so lockout, IP throttle and audit logs are the same
 * as the SMS 2 login page. When the account has an Authenticator, a short-lived
 * challenge is returned instead and smsApiMfaVerify() finishes the sign-in.
 *
 * @return array{ok: bool, user?: array, mfa?: array, status?: int, error?: string, message?: string}
 */
function smsApiPasswordLogin(array $client, string $login, string $password): array
{
    require_once __DIR__ . '/module-controls.php';
    if (smsIsSystemInMaintenance()) {
        return ['ok' => false, 'status' => 503, 'error' => 'maintenance', 'message' => 'SMS 2 is under maintenance. Please try again later.'];
    }
    $login = trim($login);
    if ($login === '' || $password === '' || strlen($login) > 190 || strlen($password) > 1024) {
        return ['ok' => false, 'status' => 400, 'error' => 'invalid_request', 'message' => 'Please enter your email or student number and password.'];
    }

    $result = smsLoginAttempt($login, $password);
    unset($_SESSION['pending_2fa']);

    if (!empty($result['needs_2fa']) || ($result['code'] ?? '') === 'needs_2fa') {
        $row = smsFindUserByLogin($login);
        $user = $row ? smsApiLoadUser((int) $row['id']) : null;
        if (!$user || !smsApiClientAllowsRole($client, (string) $user['role_key'])) {
            return smsApiPasswordLoginDenied($client, $row);
        }
        return ['ok' => false, 'mfa' => smsApiCreateMfaChallenge($client, (int) $user['id'], $login)];
    }

    if (empty($result['ok'])) {
        $locked = !empty($result['locked']);
        return [
            'ok' => false,
            'status' => $locked ? 423 : (($result['code'] ?? '') === 'inactive' ? 403 : 400),
            'error' => $locked ? 'locked' : ((($result['code'] ?? '') === 'inactive') ? 'inactive' : 'invalid_grant'),
            'message' => (string) ($result['message'] ?? 'Sign in failed.'),
        ];
    }

    return smsApiPasswordLoginFinish($client, (int) ($_SESSION['user_id'] ?? 0));
}

/** Common checks once the password (and code, when needed) are correct. */
function smsApiPasswordLoginFinish(array $client, int $userId): array
{
    $user = $userId > 0 ? smsApiLoadUser($userId) : null;
    if (!$user || !smsApiClientAllowsRole($client, (string) $user['role_key'])) {
        return smsApiPasswordLoginDenied($client, $user);
    }
    if (!empty($_SESSION['must_change_password'])) {
        return [
            'ok' => false, 'status' => 403, 'error' => 'password_change_required',
            'message' => 'You need to set a new password first. Sign in once on SMS 2 to change it, then come back here.',
        ];
    }
    logActivity('sso', 'Signed in to ' . (string) $client['name'] . ' (app login page)', 'System', (int) $user['id'], (string) $user['full_name'], (string) $user['role_key'], false);
    return ['ok' => true, 'user' => $user];
}

function smsApiPasswordLoginDenied(array $client, ?array $user): array
{
    if ($user) {
        logActivity('sso', 'Sign-in to ' . (string) $client['name'] . ' refused: role not allowed', 'System', (int) $user['id'], (string) ($user['full_name'] ?? ''), (string) ($user['role_key'] ?? ''), false);
    }
    $roles = smsApiClientRoles($client);
    $message = $roles === ['student']
        ? 'This portal is for student accounts only. Staff and faculty should use SMS 2 directly.'
        : 'Your account cannot sign in to ' . (string) $client['name'] . '. Use SMS 2 directly.';
    return ['ok' => false, 'status' => 403, 'error' => 'access_denied', 'message' => $message];
}

/** @return array{mfa_token: string, methods: list<string>, expires_in: int} */
function smsApiCreateMfaChallenge(array $client, int $userId, string $login): array
{
    $raw = smsIntegrationRandomToken(32);
    $pdo = smsIntegrationDb();
    $pdo->exec('DELETE FROM `sms2_api_mfa_challenges` WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
    $pdo->prepare(
        'INSERT INTO `sms2_api_mfa_challenges` (token_hash, client_id, user_id, login_name, expires_at)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . SMS_API_MFA_TTL . ' SECOND)'
    )->execute([smsIntegrationHash($raw), (string) $client['client_id'], $userId, substr($login, 0, 190)]);
    return ['mfa_token' => $raw, 'methods' => ['authenticator', 'email'], 'expires_in' => SMS_API_MFA_TTL];
}

function smsApiFindMfaChallenge(array $client, string $rawToken): ?array
{
    if ($rawToken === '' || strlen($rawToken) > 200) {
        return null;
    }
    $stmt = smsIntegrationDb()->prepare(
        'SELECT *, TIMESTAMPDIFF(SECOND, last_email_at, NOW()) AS email_age FROM `sms2_api_mfa_challenges`
         WHERE token_hash = ? AND client_id = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1'
    );
    $stmt->execute([smsIntegrationHash($rawToken), (string) $client['client_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Finish a sign-in that needs a code: Authenticator (method=authenticator) or email OTP (method=email).
 * Wrong codes are counted on the challenge; after the limit the challenge is closed.
 */
function smsApiMfaVerify(array $client, string $rawToken, string $method, string $code): array
{
    require_once __DIR__ . '/totp.php';
    $challenge = smsApiFindMfaChallenge($client, $rawToken);
    if (!$challenge) {
        return ['ok' => false, 'status' => 400, 'error' => 'mfa_expired', 'message' => 'Your sign-in expired. Please enter your password again.'];
    }
    $max = smsCodeChallengeMaxAttempts();
    if ((int) $challenge['attempts'] >= $max) {
        return ['ok' => false, 'status' => 423, 'error' => 'mfa_locked', 'message' => 'Too many wrong codes. Please enter your password again.'];
    }
    $userId = (int) $challenge['user_id'];
    $code = preg_replace('/\s+/', '', $code) ?? '';
    $ok = $method === 'email'
        ? smsVerifyOtp($userId, 'login_2fa', $code)
        : (preg_match('/^\d{6}$/', $code) === 1 && smsAuthenticatorVerifyLogin($userId, $code));

    $pdo = smsIntegrationDb();
    if (!$ok) {
        $pdo->prepare('UPDATE `sms2_api_mfa_challenges` SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $challenge['id']]);
        $left = max(0, $max - ((int) $challenge['attempts'] + 1));
        if ($left === 0) {
            $pdo->prepare('UPDATE `sms2_api_mfa_challenges` SET used_at = NOW() WHERE id = ?')->execute([(int) $challenge['id']]);
            return ['ok' => false, 'status' => 423, 'error' => 'mfa_locked', 'message' => 'Too many wrong codes. Please enter your password again.'];
        }
        return ['ok' => false, 'status' => 400, 'error' => 'invalid_code', 'message' => 'Wrong code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.'];
    }

    $close = $pdo->prepare('UPDATE `sms2_api_mfa_challenges` SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $close->execute([(int) $challenge['id']]);
    if ($close->rowCount() !== 1) {
        return ['ok' => false, 'status' => 400, 'error' => 'mfa_expired', 'message' => 'Your sign-in expired. Please enter your password again.'];
    }

    $stmt = db()->prepare(
        'SELECT u.*, COALESCE(r.label, u.role_key) AS role_label
         FROM `sms2_users` u LEFT JOIN `sms2_roles` r ON r.role_key = u.role_key WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return smsApiPasswordLoginDenied($client, null);
    }
    smsCompleteLoginSession($row, (string) $challenge['login_name']);
    return smsApiPasswordLoginFinish($client, $userId);
}

/** Email a login code for a waiting challenge (limited per challenge). */
function smsApiMfaSendEmail(array $client, string $rawToken): array
{
    $challenge = smsApiFindMfaChallenge($client, $rawToken);
    if (!$challenge) {
        return ['ok' => false, 'status' => 400, 'error' => 'mfa_expired', 'message' => 'Your sign-in expired. Please enter your password again.'];
    }
    if ((int) $challenge['email_sends'] >= SMS_API_MFA_MAX_EMAILS) {
        return ['ok' => false, 'status' => 429, 'error' => 'too_many_requests', 'message' => 'Too many code requests. Please enter your password again later.'];
    }
    if ($challenge['email_age'] !== null) {
        // Age comes from the database clock, so PHP and MySQL time zones may differ safely.
        $wait = SMS_API_MFA_EMAIL_COOLDOWN - (int) $challenge['email_age'];
        if ($wait > 0) {
            return ['ok' => false, 'status' => 429, 'error' => 'too_many_requests', 'message' => 'Please wait ' . smsFormatDuration($wait) . ' before requesting another code.'];
        }
    }
    smsIntegrationDb()->prepare('UPDATE `sms2_api_mfa_challenges` SET email_sends = email_sends + 1, last_email_at = NOW() WHERE id = ?')
        ->execute([(int) $challenge['id']]);
    $issued = smsIssueOtpToEmail((int) $challenge['user_id'], 'login_2fa', 'System', SMS2_OTP_TTL_MINUTES, 'login verification');
    if (empty($issued['ok']) || empty($issued['emailed'])) {
        error_log('Portal login email OTP failed: ' . (string) ($issued['error'] ?? ''));
        return ['ok' => false, 'status' => 502, 'error' => 'email_failed', 'message' => 'We could not email a code right now. Use your Authenticator app or try again later.'];
    }
    return ['ok' => true, 'sent_to' => smsApiMaskEmail((string) $issued['to'])];
}

function smsApiMaskEmail(string $email): string
{
    if (!str_contains($email, '@')) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
}

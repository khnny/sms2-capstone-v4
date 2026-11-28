<?php
/**
 * SMS 2 – Automatic data backup to Google Drive.
 *
 * One backup = one .zip with:
 *   database/sms2.sql        full dump of the main database (SMS2 + CRAD tables)
 *   database/crad.sql        only when CRAD_DB_NAME is a separate database
 *   files/uploads/...        ROOT_PATH/uploads
 *   files/storage/uploads/.. ROOT_PATH/storage/uploads
 *   manifest.json
 *
 * Upload uses the Google Drive REST API over plain cURL (no Composer / SDK)
 * with OAuth on the school's own Google account. Scope is drive.file, so the
 * app can only see the files and folder it created itself.
 *
 * Setup guide: docs/DATA-BACKUP.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit.php';

const SMS_BACKUP_FORMAT = 'sms2-drive-backup-v1';
const SMS_BACKUP_STMT_END = '-- sms2:end';
const SMS_GDRIVE_SCOPES = 'https://www.googleapis.com/auth/drive.file openid email';

/* ───────────────────────── Configuration ───────────────────────── */

/** Read a backup setting from config/local.php define() or an environment variable. */
function smsBackupConfig(string $key, string $default = ''): string
{
    if (defined($key)) {
        $value = constant($key);
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }
    $value = sms2_env($key);
    return $value !== null ? trim($value) : $default;
}

/**
 * OAuth client credentials. Either set SMS2_GDRIVE_CLIENT_ID + SMS2_GDRIVE_CLIENT_SECRET,
 * or drop the JSON downloaded from Google Cloud at storage/keys/google-oauth-client.json
 * (or the path in SMS2_GDRIVE_CLIENT_JSON).
 *
 * @return array{id:string,secret:string,source:string}
 */
function smsGdriveClient(): array
{
    $id = smsBackupConfig('SMS2_GDRIVE_CLIENT_ID');
    $secret = smsBackupConfig('SMS2_GDRIVE_CLIENT_SECRET');
    if ($id !== '' && $secret !== '') {
        return ['id' => $id, 'secret' => $secret, 'source' => 'config'];
    }

    $jsonPath = smsBackupConfig('SMS2_GDRIVE_CLIENT_JSON', ROOT_PATH . '/storage/keys/google-oauth-client.json');
    if (is_readable($jsonPath)) {
        $data = json_decode((string) file_get_contents($jsonPath), true);
        $node = is_array($data) ? ($data['web'] ?? $data['installed'] ?? null) : null;
        if (is_array($node) && !empty($node['client_id']) && !empty($node['client_secret'])) {
            return ['id' => (string) $node['client_id'], 'secret' => (string) $node['client_secret'], 'source' => 'json'];
        }
    }

    return ['id' => '', 'secret' => '', 'source' => ''];
}

function smsGdriveIsConfigured(): bool
{
    $client = smsGdriveClient();
    return $client['id'] !== '' && $client['secret'] !== '';
}

function smsGdriveIsConnected(): bool
{
    return smsSetting('gdrive_refresh_token', '') !== '';
}

/** Absolute URL of the app (scheme + host + BASE_URL). */
function smsBackupAbsoluteUrl(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', explode(',', $host)[0]) ?: 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . rtrim((string) BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** Must match an "Authorized redirect URI" of the OAuth client exactly. */
function smsGdriveRedirectUri(): string
{
    return smsBackupConfig('SMS2_GDRIVE_REDIRECT_URI', smsBackupAbsoluteUrl('backup/google-callback.php'));
}

function smsBackupCronToken(): string
{
    $token = smsBackupConfig('SMS2_BACKUP_CRON_TOKEN');
    return strlen($token) >= 16 ? $token : '';
}

function smsBackupKeepDaily(): int
{
    return max(1, (int) smsBackupConfig('SMS2_BACKUP_KEEP_DAILY', '7'));
}

function smsBackupKeepWeekly(): int
{
    return max(0, (int) smsBackupConfig('SMS2_BACKUP_KEEP_WEEKLY', '4'));
}

function smsBackupFolderName(): string
{
    return smsBackupConfig('SMS2_GDRIVE_FOLDER_NAME', 'SMS2 Backups');
}

function smsBackupWorkDir(): string
{
    $dir = ROOT_PATH . '/storage/backups/work';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/* ───────────────────────── Run log table ───────────────────────── */

function smsBackupEnsureTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `sms2_backup_runs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `trigger_type` VARCHAR(20) NOT NULL,
            `status` VARCHAR(20) NOT NULL,
            `file_name` VARCHAR(190) NULL,
            `size_bytes` BIGINT UNSIGNED NULL,
            `drive_file_id` VARCHAR(128) NULL,
            `deleted_count` INT NOT NULL DEFAULT 0,
            `message` TEXT NULL,
            `started_by` VARCHAR(120) NULL,
            `started_at` DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_backup_runs_started` (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** @return list<array<string,mixed>> */
function smsBackupRecentRuns(int $limit = 20): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        smsBackupEnsureTable($pdo);
        // A run that never finished (server killed it) is shown as failed.
        // Times are stored in PHP's timezone (Asia/Manila), not the DB server's.
        $stale = $pdo->prepare("UPDATE `sms2_backup_runs` SET status = 'failed', finished_at = ?,
                    message = 'The backup stopped before finishing (server timeout or restart).'
                    WHERE status = 'running' AND started_at < ?");
        $stale->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - 7200)]);
        $stmt = $pdo->prepare('SELECT * FROM `sms2_backup_runs` ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('SMS2 backup runs read failed: ' . $e->getMessage());
        return [];
    }
}

/* ───────────────────────── HTTP / OAuth ───────────────────────── */

// Guarded so tests can swap in a fake Google Drive (tests/gdrive_backup_test.php).
if (!function_exists('smsGdriveHttp')) {
    /**
     * @param array<string,string> $headers
     * @param array{sink?:resource,timeout?:int} $opts
     * @return array{status:int,body:string,headers:array<string,string>,error:string}
     */
    function smsGdriveHttp(string $method, string $url, array $headers = [], ?string $body = null, array $opts = []): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'The PHP cURL extension is not enabled.'];
        }
        $ch = curl_init($url);
        $respHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 300),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (isset($opts['sink'])) {
            // WRITEFUNCTION (not CURLOPT_FILE) so php://output works too.
            $sink = $opts['sink'];
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use ($sink): int {
                return (int) fwrite($sink, $data);
            });
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $result === false ? curl_error($ch) : '';
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($result) ? $result : '',
            'headers' => $respHeaders,
            'error' => $error,
        ];
    }
}

/** Short human message from a Google API error response. */
function smsGdriveErrorText(array $resp): string
{
    if ($resp['error'] !== '') {
        return 'Network error: ' . $resp['error'];
    }
    $data = json_decode($resp['body'], true);
    $msg = '';
    if (is_array($data)) {
        if (isset($data['error']['message'])) {
            $msg = (string) $data['error']['message'];
        } elseif (isset($data['error_description'])) {
            $msg = (string) $data['error_description'];
        } elseif (isset($data['error']) && is_string($data['error'])) {
            $msg = $data['error'];
        }
    }
    return 'Google API error ' . $resp['status'] . ($msg !== '' ? ': ' . $msg : '');
}

function smsGdriveAuthUrl(string $state): string
{
    $client = smsGdriveClient();
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $client['id'],
        'redirect_uri' => smsGdriveRedirectUri(),
        'response_type' => 'code',
        'scope' => SMS_GDRIVE_SCOPES,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state,
    ]);
}

/**
 * Exchange the ?code from the callback and save the refresh token.
 *
 * @return array{ok:bool,error:string,email:string}
 */
function smsGdriveHandleCallback(string $code): array
{
    $client = smsGdriveClient();
    $resp = smsGdriveHttp('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], http_build_query([
        'code' => $code,
        'client_id' => $client['id'],
        'client_secret' => $client['secret'],
        'redirect_uri' => smsGdriveRedirectUri(),
        'grant_type' => 'authorization_code',
    ]), ['timeout' => 30]);

    $data = json_decode($resp['body'], true);
    if ($resp['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
        return ['ok' => false, 'error' => smsGdriveErrorText($resp), 'email' => ''];
    }
    if (empty($data['refresh_token'])) {
        return [
            'ok' => false,
            'error' => 'Google did not return a refresh token. Remove "SMS2" access at myaccount.google.com/permissions, then connect again.',
            'email' => '',
        ];
    }
    $scopes = ' ' . (string) ($data['scope'] ?? '') . ' ';
    if (!str_contains($scopes, 'auth/drive.file')) {
        return ['ok' => false, 'error' => 'Google Drive permission was not granted. Tick the Drive checkbox on the Google sign-in page.', 'email' => ''];
    }

    $email = '';
    if (!empty($data['id_token']) && is_string($data['id_token'])) {
        $parts = explode('.', $data['id_token']);
        if (count($parts) >= 2) {
            $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
            $email = is_array($payload) ? (string) ($payload['email'] ?? '') : '';
        }
    }

    $saved = smsSetSetting('gdrive_refresh_token', (string) $data['refresh_token']);
    if (!$saved) {
        return ['ok' => false, 'error' => 'Could not save the Google sign-in to the database.', 'email' => ''];
    }
    smsSetSetting('gdrive_account_email', $email);
    smsSetSetting('gdrive_connected_at', date('Y-m-d H:i:s'));
    $GLOBALS['__sms_gdrive_access_token'] = (string) $data['access_token'];

    return ['ok' => true, 'error' => '', 'email' => $email];
}

/** Revoke the token at Google and forget it locally. */
function smsGdriveDisconnect(): void
{
    $token = smsSetting('gdrive_refresh_token', '');
    if ($token !== '') {
        smsGdriveHttp('POST', 'https://oauth2.googleapis.com/revoke', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query(['token' => $token]), ['timeout' => 15]);
    }
    smsSetSetting('gdrive_refresh_token', '');
    smsSetSetting('gdrive_account_email', '');
    smsSetSetting('gdrive_connected_at', '');
    unset($GLOBALS['__sms_gdrive_access_token']);
}

function smsGdriveAccessToken(): string
{
    if (!empty($GLOBALS['__sms_gdrive_access_token'])) {
        return (string) $GLOBALS['__sms_gdrive_access_token'];
    }
    $refresh = smsSetting('gdrive_refresh_token', '');
    if ($refresh === '') {
        throw new RuntimeException('Google Drive is not connected. Open Data Backup and click "Connect Google Drive".');
    }
    $client = smsGdriveClient();
    if ($client['id'] === '') {
        throw new RuntimeException('Google OAuth client is not configured (SMS2_GDRIVE_CLIENT_ID / SMS2_GDRIVE_CLIENT_SECRET).');
    }
    $resp = smsGdriveHttp('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], http_build_query([
        'client_id' => $client['id'],
        'client_secret' => $client['secret'],
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
    ]), ['timeout' => 30]);
    $data = json_decode($resp['body'], true);
    if ($resp['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
        $hint = (is_array($data) && ($data['error'] ?? '') === 'invalid_grant')
            ? ' The Google sign-in expired or was revoked: click "Connect Google Drive" again.'
              . ' (If this happens every 7 days, publish the OAuth consent screen to "In production".)'
            : '';
        throw new RuntimeException(smsGdriveErrorText($resp) . $hint);
    }
    $GLOBALS['__sms_gdrive_access_token'] = (string) $data['access_token'];
    return $GLOBALS['__sms_gdrive_access_token'];
}

/** Drive API call with JSON response. */
function smsGdriveApi(string $method, string $url, ?array $json = null, array $extraHeaders = []): array
{
    $headers = ['Authorization' => 'Bearer ' . smsGdriveAccessToken()] + $extraHeaders;
    $body = null;
    if ($json !== null) {
        $headers['Content-Type'] = 'application/json; charset=UTF-8';
        $body = (string) json_encode($json, JSON_UNESCAPED_SLASHES);
    }
    $resp = smsGdriveHttp($method, $url, $headers, $body, ['timeout' => 60]);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException(smsGdriveErrorText($resp));
    }
    $data = json_decode($resp['body'], true);
    return is_array($data) ? $data : [];
}

/** Find or create the backup folder (created by this app, so drive.file can see it). */
function smsGdriveFolderId(): string
{
    $folderId = smsSetting('gdrive_folder_id', '');
    if ($folderId !== '') {
        try {
            $meta = smsGdriveApi('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folderId)
                . '?fields=id,trashed');
            if (empty($meta['trashed'])) {
                return $folderId;
            }
        } catch (Throwable $e) {
            // Folder deleted or not visible any more; create a new one below.
        }
    }
    $meta = smsGdriveApi('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', [
        'name' => smsBackupFolderName(),
        'mimeType' => 'application/vnd.google-apps.folder',
        'appProperties' => ['sms2_backup_folder' => '1'],
    ]);
    $folderId = (string) ($meta['id'] ?? '');
    if ($folderId === '') {
        throw new RuntimeException('Could not create the Google Drive backup folder.');
    }
    smsSetSetting('gdrive_folder_id', $folderId);
    return $folderId;
}

/**
 * Resumable upload in 8 MB chunks (works for big zips without loading them in memory).
 *
 * @return array<string,mixed> Drive file metadata
 */
function smsGdriveUpload(string $path, string $name, string $folderId, array $appProperties): array
{
    $size = (int) filesize($path);
    $init = smsGdriveHttp('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,size,createdTime', [
        'Authorization' => 'Bearer ' . smsGdriveAccessToken(),
        'Content-Type' => 'application/json; charset=UTF-8',
        'X-Upload-Content-Type' => 'application/zip',
        'X-Upload-Content-Length' => (string) $size,
    ], (string) json_encode([
        'name' => $name,
        'parents' => [$folderId],
        'appProperties' => $appProperties,
    ]), ['timeout' => 60]);
    $session = $init['headers']['location'] ?? '';
    if ($init['status'] !== 200 || $session === '') {
        throw new RuntimeException('Upload could not start. ' . smsGdriveErrorText($init));
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Could not read the backup file for upload.');
    }
    try {
        $chunk = 8 * 1024 * 1024;
        $offset = 0;
        while (true) {
            $data = $size > 0 ? (string) fread($fh, $chunk) : '';
            $len = strlen($data);
            $range = $size === 0 ? 'bytes */0' : 'bytes ' . $offset . '-' . ($offset + $len - 1) . '/' . $size;
            $tries = 0;
            do {
                $resp = smsGdriveHttp('PUT', $session, [
                    'Content-Range' => $range,
                ], $data, ['timeout' => 300]);
                $tries++;
                $retry = $resp['status'] === 0 || $resp['status'] >= 500;
                if ($retry && $tries < 4) {
                    sleep(2 * $tries);
                }
            } while ($retry && $tries < 4);

            if ($resp['status'] === 200 || $resp['status'] === 201) {
                $meta = json_decode($resp['body'], true);
                return is_array($meta) ? $meta : [];
            }
            if ($resp['status'] !== 308) {
                throw new RuntimeException('Upload failed. ' . smsGdriveErrorText($resp));
            }
            $offset += $len;
            if ($offset >= $size) {
                throw new RuntimeException('Upload did not complete (Google kept asking for more data).');
            }
        }
    } finally {
        fclose($fh);
    }
}

/** @return list<array<string,mixed>> newest first */
function smsGdriveListBackups(): array
{
    $folderId = smsGdriveFolderId();
    $files = [];
    $pageToken = '';
    do {
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(array_filter([
            'q' => "'" . str_replace("'", "\\'", $folderId) . "' in parents and trashed = false",
            'fields' => 'nextPageToken,files(id,name,size,createdTime,appProperties)',
            'orderBy' => 'createdTime desc',
            'pageSize' => '200',
            'pageToken' => $pageToken,
        ]));
        $data = smsGdriveApi('GET', $url);
        foreach ((array) ($data['files'] ?? []) as $file) {
            if (is_array($file) && (($file['appProperties']['sms2_backup'] ?? '') === '1')) {
                $files[] = $file;
            }
        }
        $pageToken = (string) ($data['nextPageToken'] ?? '');
    } while ($pageToken !== '');

    usort($files, static fn ($a, $b) => strcmp((string) $b['createdTime'], (string) $a['createdTime']));
    return $files;
}

/** Make sure a Drive file id is one of our backups before download / restore. */
function smsGdriveGetBackup(string $fileId): array
{
    if (!preg_match('/^[A-Za-z0-9_\-]{10,128}$/', $fileId)) {
        throw new RuntimeException('Invalid backup id.');
    }
    $meta = smsGdriveApi('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId)
        . '?fields=id,name,size,createdTime,parents,trashed,appProperties');
    if (!empty($meta['trashed']) || ($meta['appProperties']['sms2_backup'] ?? '') !== '1'
        || !in_array(smsGdriveFolderId(), (array) ($meta['parents'] ?? []), true)) {
        throw new RuntimeException('That file is not an SMS2 backup.');
    }
    return $meta;
}

/** Download a backup either to a local file path or straight to the browser (php://output). */
function smsGdriveDownload(string $fileId, string $destPath): void
{
    $fh = fopen($destPath, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Could not open download destination.');
    }
    $resp = smsGdriveHttp('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media', [
        'Authorization' => 'Bearer ' . smsGdriveAccessToken(),
    ], null, ['sink' => $fh, 'timeout' => 1800]);
    fclose($fh);
    if ($resp['status'] !== 200) {
        throw new RuntimeException('Download failed. ' . smsGdriveErrorText($resp));
    }
}

/* ───────────────────────── Retention ───────────────────────── */

/**
 * Decide which backups to keep: the newest backup of each of the last N days
 * that have a backup, plus the newest backup of each of the M weeks before that.
 *
 * @param list<array<string,mixed>> $files newest first
 * @return array{keep:list<array>,delete:list<array>}
 */
function smsBackupRetentionPlan(array $files, int $keepDaily, int $keepWeekly): array
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $keep = [];
    $delete = [];
    $days = [];
    $weeks = [];
    $oldestDailyDay = null;

    $now = time();
    foreach ($files as $file) {
        // Safety copies made right before a restore are kept for 7 days and
        // do not take the place of a normal daily backup.
        if (($file['appProperties']['trigger'] ?? '') === 'pre-restore') {
            if (strtotime((string) $file['createdTime']) > $now - 7 * 86400) {
                $keep[] = $file;
            } else {
                $delete[] = $file;
            }
            continue;
        }
        $dt = (new DateTimeImmutable((string) $file['createdTime']))->setTimezone($tz);
        $day = $dt->format('Y-m-d');
        $week = $dt->format('o-\WW');

        if (count($days) < $keepDaily && !isset($days[$day])) {
            $days[$day] = true;
            $oldestDailyDay = $day;
            $keep[] = $file;
            continue;
        }
        if (isset($days[$day])) {
            $delete[] = $file; // older copy from a day we already keep
            continue;
        }
        if ($oldestDailyDay !== null && $day < $oldestDailyDay && count($weeks) < $keepWeekly && !isset($weeks[$week])) {
            $weeks[$week] = true;
            $keep[] = $file;
            continue;
        }
        $delete[] = $file;
    }

    return ['keep' => $keep, 'delete' => $delete];
}

/** @return int number of deleted backups */
function smsBackupApplyRetention(): int
{
    $plan = smsBackupRetentionPlan(smsGdriveListBackups(), smsBackupKeepDaily(), smsBackupKeepWeekly());
    $deleted = 0;
    foreach ($plan['delete'] as $file) {
        try {
            smsGdriveApi('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode((string) $file['id']));
            $deleted++;
        } catch (Throwable $e) {
            error_log('SMS2 backup retention delete failed for ' . ($file['name'] ?? '?') . ': ' . $e->getMessage());
        }
    }
    return $deleted;
}

/* ───────────────────────── Database dump ───────────────────────── */

/**
 * Stream a full dump of the PDO's current database to $path.
 * Each statement is followed by a "-- sms2:end" line so restore can split
 * statements quickly. The file is still valid SQL for phpMyAdmin / mysql CLI.
 *
 * @param list<string> $excludeTables
 * @return array{tables:int,rows:int}
 */
function smsBackupDumpDatabase(PDO $pdo, string $path, array $excludeTables = []): array
{
    $out = fopen($path, 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not write the database dump file.');
    }
    $q = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $w = static function (string $sql) use ($out): void {
        fwrite($out, $sql . ";\n" . SMS_BACKUP_STMT_END . "\n");
    };

    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    fwrite($out, '-- ' . SMS_BACKUP_FORMAT . "\n-- Database: " . $dbName . "\n-- Generated: " . date('c') . "\n\n");
    $w('SET NAMES utf8mb4');
    $w('SET FOREIGN_KEY_CHECKS=0');
    $w('SET UNIQUE_CHECKS=0');
    $w("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

    $tables = [];
    $views = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $name = (string) $row[0];
        if (in_array($name, $excludeTables, true)) {
            continue;
        }
        if (strtoupper((string) $row[1]) === 'VIEW') {
            $views[] = $name;
        } else {
            $tables[] = $name;
        }
    }

    $totalRows = 0;
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    try {
        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE ' . $q($table))->fetch(PDO::FETCH_NUM);
            if (!$create || empty($create[1])) {
                continue;
            }
            fwrite($out, "\n-- Table " . $table . "\n");
            $w('DROP TABLE IF EXISTS ' . $q($table));
            $w((string) $create[1]);

            $binaryCols = [];
            $colNames = [];
            foreach ($pdo->query('SHOW COLUMNS FROM ' . $q($table))->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $colNames[] = $q((string) $col['Field']);
                $type = strtolower((string) $col['Type']);
                $binaryCols[(string) $col['Field']] = str_contains($type, 'blob') || str_contains($type, 'binary')
                    || str_starts_with($type, 'bit') || str_starts_with($type, 'geometry') || str_starts_with($type, 'point');
            }
            $prefix = 'INSERT INTO ' . $q($table) . ' (' . implode(',', $colNames) . ') VALUES ';

            // Unbuffered so very large tables are not loaded into memory at once.
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $stmt = $pdo->query('SELECT * FROM ' . $q($table));
            $batch = [];
            $batchBytes = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vals = [];
                foreach ($row as $col => $val) {
                    if ($val === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($val)) {
                        $vals[] = (string) $val;
                    } elseif (is_float($val)) {
                        $vals[] = (string) json_encode($val);
                    } elseif (!empty($binaryCols[$col])) {
                        $vals[] = $val === '' ? "''" : '0x' . bin2hex((string) $val);
                    } else {
                        $vals[] = $pdo->quote((string) $val);
                    }
                }
                $tuple = '(' . implode(',', $vals) . ')';
                $batch[] = $tuple;
                $batchBytes += strlen($tuple);
                $totalRows++;
                if (count($batch) >= 200 || $batchBytes > 1000000) {
                    $w($prefix . implode(",\n", $batch));
                    $batch = [];
                    $batchBytes = 0;
                }
            }
            $stmt->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            if ($batch) {
                $w($prefix . implode(",\n", $batch));
            }
        }

        foreach ($views as $view) {
            $create = $pdo->query('SHOW CREATE VIEW ' . $q($view))->fetch(PDO::FETCH_NUM);
            if (!$create || empty($create[1])) {
                continue;
            }
            // Drop DEFINER so the view restores under a different DB user too.
            $sql = (string) preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', (string) $create[1]);
            $w('DROP VIEW IF EXISTS ' . $q($view));
            $w($sql);
        }
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
    }

    $w('SET FOREIGN_KEY_CHECKS=1');
    $w('SET UNIQUE_CHECKS=1');
    fclose($out);

    return ['tables' => count($tables), 'rows' => $totalRows];
}

/** Run a dump made by smsBackupDumpDatabase() against $pdo. */
function smsBackupImportSql(PDO $pdo, string $path): int
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Could not read SQL file from the backup.');
    }
    $first = (string) fgets($fh);
    if (!str_contains($first, SMS_BACKUP_FORMAT)) {
        fclose($fh);
        throw new RuntimeException('This SQL file was not made by the SMS2 Drive backup, so it cannot be restored here.');
    }
    $count = 0;
    $stmt = '';
    try {
        while (($line = fgets($fh)) !== false) {
            if (rtrim($line, "\r\n") === SMS_BACKUP_STMT_END) {
                $sql = rtrim(trim($stmt), ';');
                if ($sql !== '') {
                    $pdo->exec($sql);
                    $count++;
                }
                $stmt = '';
                continue;
            }
            if ($stmt === '' && str_starts_with($line, '--')) {
                continue;
            }
            $stmt .= $line;
        }
    } finally {
        fclose($fh);
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
        }
    }
    return $count;
}

/* ───────────────────────── Zip build ───────────────────────── */

/** @return array<string,string> zip prefix => absolute folder */
function smsBackupFileRoots(): array
{
    return [
        'files/uploads' => ROOT_PATH . '/uploads',
        'files/storage/uploads' => ROOT_PATH . '/storage/uploads',
    ];
}

/** Tables never put in the dump (the run log must survive a restore). */
function smsBackupExcludedTables(): array
{
    return ['sms2_backup_runs'];
}

function smsBackupSeparateCradDb(): bool
{
    return defined('CRAD_DB_NAME') && (string) CRAD_DB_NAME !== '' && (string) CRAD_DB_NAME !== (string) DB_NAME;
}

function smsBackupCradPdo(): PDO
{
    $cradConfig = ROOT_PATH . '/modules/crad/config/config.php';
    if (!function_exists('getCradDatabaseConnection')) {
        require_once $cradConfig;
    }
    return getCradDatabaseConnection();
}

/**
 * @return array{path:string,name:string,manifest:array}
 */
function smsBackupBuildZip(string $trigger): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not enabled (enable extension=zip in php.ini).');
    }
    $pdo = getDatabaseConnection();
    $work = smsBackupWorkDir();
    $stamp = date('Y-m-d_H-i-s');
    $name = 'sms2-backup-' . $stamp . ($trigger === 'pre-restore' ? '-before-restore' : '') . '.zip';
    $zipPath = $work . '/' . $name;
    $sqlMain = $work . '/sms2-' . $stamp . '.sql';
    $sqlCrad = $work . '/crad-' . $stamp . '.sql';

    $manifest = [
        'format' => SMS_BACKUP_FORMAT,
        'created_at' => date('c'),
        'trigger' => $trigger,
        'app_version' => defined('APP_VERSION') ? APP_VERSION : '',
        'databases' => [],
        'files' => 0,
    ];

    try {
        $manifest['databases']['sms2'] = ['name' => (string) DB_NAME] + smsBackupDumpDatabase($pdo, $sqlMain, smsBackupExcludedTables());
        if (smsBackupSeparateCradDb()) {
            $manifest['databases']['crad'] = ['name' => (string) CRAD_DB_NAME] + smsBackupDumpDatabase(smsBackupCradPdo(), $sqlCrad, smsBackupExcludedTables());
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup zip.');
        }
        $zip->addFile($sqlMain, 'database/sms2.sql');
        if (is_file($sqlCrad)) {
            $zip->addFile($sqlCrad, 'database/crad.sql');
        }
        foreach (smsBackupFileRoots() as $prefix => $root) {
            if (!is_dir($root)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $zip->addFile($file->getPathname(), $prefix . '/' . $rel);
                $manifest['files']++;
            }
        }
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$zip->close()) {
            throw new RuntimeException('Could not finish writing the backup zip.');
        }
    } catch (Throwable $e) {
        @unlink($zipPath);
        throw $e;
    } finally {
        @unlink($sqlMain);
        @unlink($sqlCrad);
    }

    return ['path' => $zipPath, 'name' => $name, 'manifest' => $manifest];
}

/* ───────────────────────── Backup run ───────────────────────── */

/**
 * Full backup: dump + zip + upload + retention + log + failure email.
 *
 * @param string $trigger cron | manual | cli | pre-restore
 * @return array{ok:bool,error:string,file_name:string,size:int,deleted:int,run_id:int}
 */
function smsBackupRun(string $trigger, string $startedBy = ''): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    $result = ['ok' => false, 'error' => '', 'file_name' => '', 'size' => 0, 'deleted' => 0, 'run_id' => 0];
    $pdo = db();
    if (!$pdo) {
        $result['error'] = 'Database unavailable.';
        smsBackupNotifyFailure($trigger, $result['error']);
        return $result;
    }
    smsBackupEnsureTable($pdo);

    $lockPath = smsBackupWorkDir() . '/backup.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        $result['error'] = 'Another backup is already running. Try again in a few minutes.';
        return $result;
    }

    $ins = $pdo->prepare("INSERT INTO `sms2_backup_runs` (trigger_type, status, started_by, started_at) VALUES (?, 'running', ?, ?)");
    $ins->execute([$trigger, $startedBy !== '' ? mb_substr($startedBy, 0, 120) : null, date('Y-m-d H:i:s')]);
    $runId = (int) $pdo->lastInsertId();
    $result['run_id'] = $runId;

    $zip = null;
    try {
        if (!smsGdriveIsConfigured()) {
            throw new RuntimeException('Google OAuth client is not configured. See docs/DATA-BACKUP.md.');
        }
        smsGdriveAccessToken(); // fail fast if the sign-in expired
        $zip = smsBackupBuildZip($trigger);
        $folderId = smsGdriveFolderId();
        $meta = smsGdriveUpload($zip['path'], $zip['name'], $folderId, [
            'sms2_backup' => '1',
            'trigger' => $trigger,
        ]);
        $result['file_name'] = $zip['name'];
        $result['size'] = (int) filesize($zip['path']);

        if ($trigger !== 'pre-restore') {
            try {
                $result['deleted'] = smsBackupApplyRetention();
            } catch (Throwable $e) {
                error_log('SMS2 backup retention failed: ' . $e->getMessage());
            }
        }

        $upd = $pdo->prepare("UPDATE `sms2_backup_runs` SET status = 'success', file_name = ?, size_bytes = ?,
                              drive_file_id = ?, deleted_count = ?, message = ?, finished_at = ? WHERE id = ?");
        $upd->execute([
            $zip['name'],
            $result['size'],
            (string) ($meta['id'] ?? ''),
            $result['deleted'],
            sprintf('%d tables, %d rows, %d files', (int) array_sum(array_column($zip['manifest']['databases'], 'tables')),
                (int) array_sum(array_column($zip['manifest']['databases'], 'rows')), (int) $zip['manifest']['files']),
            date('Y-m-d H:i:s'),
            $runId,
        ]);
        $result['ok'] = true;

        if (function_exists('logActivity')) {
            logActivity('export', 'Google Drive backup created: ' . $zip['name'] . ' (' . $trigger . ')', 'user-management',
                null, $startedBy !== '' ? $startedBy : 'System (backup)');
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        error_log('SMS2 backup failed: ' . $e->getMessage());
        try {
            $upd = $pdo->prepare("UPDATE `sms2_backup_runs` SET status = 'failed', message = ?, finished_at = ? WHERE id = ?");
            $upd->execute([mb_substr($result['error'], 0, 2000), date('Y-m-d H:i:s'), $runId]);
        } catch (Throwable $ignored) {
        }
        if ($trigger !== 'pre-restore') {
            smsBackupNotifyFailure($trigger, $result['error']);
        }
    } finally {
        if ($zip !== null) {
            @unlink($zip['path']);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return $result;
}

/** Email the admin when a backup fails. */
function smsBackupNotifyFailure(string $trigger, string $error): void
{
    try {
        require_once __DIR__ . '/mail.php';
        $to = smsBackupConfig('SMS2_BACKUP_NOTIFY_EMAIL');
        if ($to === '') {
            $to = smsSetting('mail_admin_email', '');
        }
        if ($to === '') {
            $to = smsSetting('gdrive_account_email', '');
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $page = PHP_SAPI === 'cli'
            ? 'User Management → Data Backup'
            : '<a href="' . e(smsBackupAbsoluteUrl('modules/user-management/pages/data-backup.php')) . '">Data Backup</a>';
        $html = '<p>The <strong>' . e($trigger) . '</strong> backup to Google Drive failed on '
            . e(date('F j, Y g:i A')) . '.</p><p><strong>Error:</strong> ' . e($error) . '</p>'
            . '<p>Open ' . $page . ' in SMS2 to check and run "Back up now".</p>';
        $body = function_exists('smsMailWrapHtml') ? smsMailWrapHtml('Backup failed', $html) : $html;
        smsSendMail($to, '[SMS2] Google Drive backup failed', $body);
    } catch (Throwable $e) {
        error_log('SMS2 backup failure email not sent: ' . $e->getMessage());
    }
}

/* ───────────────────────── Restore ───────────────────────── */

function smsBackupSafeZipPath(string $entry): bool
{
    return $entry !== '' && !str_contains($entry, '\\') && !str_contains($entry, "\0")
        && !str_starts_with($entry, '/') && !preg_match('#(^|/)\.\.(/|$)#', $entry) && !preg_match('/^[A-Za-z]:/', $entry);
}

/**
 * Restore a Drive backup: makes a safety backup first, then replaces the
 * database(s) and puts back uploaded files (existing files with the same
 * name are overwritten; newer extra files are left alone).
 *
 * @return array{ok:bool,error:string,statements:int,files:int,safety:string}
 */
function smsBackupRestore(string $fileId, string $startedBy = ''): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    $result = ['ok' => false, 'error' => '', 'statements' => 0, 'files' => 0, 'safety' => ''];

    $work = smsBackupWorkDir();
    $zipPath = $work . '/restore-' . bin2hex(random_bytes(6)) . '.zip';
    $tmpSql = [];
    try {
        $meta = smsGdriveGetBackup($fileId);

        $safety = smsBackupRun('pre-restore', $startedBy);
        if (!$safety['ok']) {
            throw new RuntimeException('Safety backup before restore failed, so nothing was changed: ' . $safety['error']);
        }
        $result['safety'] = $safety['file_name'];

        smsGdriveDownload($fileId, $zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The downloaded backup is not a valid zip.');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== SMS_BACKUP_FORMAT || $zip->locateName('database/sms2.sql') === false) {
            $zip->close();
            throw new RuntimeException('This zip is not an SMS2 Drive backup.');
        }

        // Keep the current Google connection: the backup may hold an older one.
        $pdo = getDatabaseConnection();
        $keepKeys = ['gdrive_refresh_token', 'gdrive_account_email', 'gdrive_connected_at', 'gdrive_folder_id'];
        $kept = [];
        $sel = $pdo->prepare('SELECT setting_value FROM `sms2_system_settings` WHERE setting_key = ?');
        foreach ($keepKeys as $k) {
            $sel->execute([$k]);
            $v = $sel->fetchColumn();
            if ($v !== false) {
                $kept[$k] = (string) $v;
            }
        }

        foreach (['sms2' => 'database/sms2.sql', 'crad' => 'database/crad.sql'] as $key => $entry) {
            if ($zip->locateName($entry) === false) {
                continue;
            }
            $tmp = $work . '/restore-' . $key . '-' . bin2hex(random_bytes(4)) . '.sql';
            $tmpSql[] = $tmp;
            $in = $zip->getStream($entry);
            $out = fopen($tmp, 'wb');
            if (!$in || !$out) {
                throw new RuntimeException('Could not extract ' . $entry . ' from the backup.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $target = $key === 'crad' && smsBackupSeparateCradDb() ? smsBackupCradPdo() : $pdo;
            $result['statements'] += smsBackupImportSql($target, $tmp);
        }

        $up = $pdo->prepare('INSERT INTO `sms2_system_settings` (setting_key, setting_value) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($kept as $k => $v) {
            $up->execute([$k, $v]);
        }
        unset($GLOBALS['__sms_settings_cache']);

        $roots = smsBackupFileRoots();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (str_ends_with($entry, '/') || !smsBackupSafeZipPath($entry)) {
                continue;
            }
            foreach ($roots as $prefix => $root) {
                if (!str_starts_with($entry, $prefix . '/')) {
                    continue;
                }
                $dest = $root . '/' . substr($entry, strlen($prefix) + 1);
                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    break;
                }
                $in = $zip->getStream($entry);
                $out = @fopen($dest, 'wb');
                if ($in && $out) {
                    stream_copy_to_stream($in, $out);
                    $result['files']++;
                }
                if ($in) {
                    fclose($in);
                }
                if ($out) {
                    fclose($out);
                }
                break;
            }
        }
        $zip->close();

        $result['ok'] = true;
        if (function_exists('logActivity')) {
            logActivity('update', 'Restored Google Drive backup ' . (string) ($meta['name'] ?? $fileId)
                . ' (safety copy: ' . $result['safety'] . ')', 'user-management', null, $startedBy !== '' ? $startedBy : null);
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        error_log('SMS2 restore failed: ' . $e->getMessage());
    } finally {
        @unlink($zipPath);
        foreach ($tmpSql as $tmp) {
            @unlink($tmp);
        }
    }
    return $result;
}

function smsBackupFormatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($size, 1)) . ' ' . $units[$i];
}

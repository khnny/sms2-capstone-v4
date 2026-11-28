<?php
/**
 * Google Drive backup: dump/restore round trip, chunked upload, retention and
 * download, against a fake in-memory Google Drive (no network, no Google account).
 *
 *   php tests/gdrive_backup_test.php
 *
 * Env: SMS2_BACKUP_TEST_HOST / _PORT / _USER / _PASSWORD (default 127.0.0.1 / 3306 / root / '').
 * Destructive only to the database it creates (sms2_qa_backup_*), dropped at the end.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$host = getenv('SMS2_BACKUP_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SMS2_BACKUP_TEST_PORT') ?: '3306';
$user = getenv('SMS2_BACKUP_TEST_USER') ?: 'root';
$pass = getenv('SMS2_BACKUP_TEST_PASSWORD') ?: '';
$dbName = 'sms2_qa_backup_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

define('DB_HOST', $host);
define('DB_PORT', $port);
define('DB_NAME', $dbName);
define('DB_USER', $user);
define('DB_PASS', $pass);
define('CRAD_DB_NAME', $dbName);
define('SMS2_APP_KEY', str_repeat('k', 32));
define('SMS2_GDRIVE_CLIENT_ID', 'test-client.apps.googleusercontent.com');
define('SMS2_GDRIVE_CLIENT_SECRET', 'test-secret');
define('SMS2_BACKUP_CRON_TOKEN', 'test-token-1234567890');

/* ── Fake Google Drive ─────────────────────────────────────── */
$GLOBALS['fake'] = ['files' => [], 'sessions' => [], 'next' => 1, 'puts' => 0];

function smsGdriveHttp(string $method, string $url, array $headers = [], ?string $body = null, array $opts = []): array
{
    $f =& $GLOBALS['fake'];
    $ok = static fn (array $data, int $status = 200): array => ['status' => $status, 'body' => (string) json_encode($data), 'headers' => [], 'error' => ''];
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $newId = static function () use (&$f): string { return 'fakefile' . str_pad((string) $f['next']++, 6, '0', STR_PAD_LEFT); };

    if (str_contains($url, 'oauth2.googleapis.com/token')) {
        return $ok(['access_token' => 'fake-access', 'expires_in' => 3600]);
    }
    if (str_contains($url, 'oauth2.googleapis.com/revoke')) {
        return $ok([]);
    }
    if (str_starts_with($url, 'fake://session/')) {
        $sid = substr($url, strlen('fake://session/'));
        $s =& $f['sessions'][$sid];
        $f['puts']++;
        $s['data'] .= (string) $body;
        if (strlen($s['data']) < $s['size']) {
            return ['status' => 308, 'body' => '', 'headers' => [], 'error' => ''];
        }
        $id = $newId();
        $f['files'][$id] = $s['meta'] + ['id' => $id, 'content' => $s['data'], 'size' => (string) strlen($s['data']),
            'createdTime' => gmdate('Y-m-d\TH:i:s.000\Z', time() + $f['next']), 'trashed' => false];
        return $ok(['id' => $id, 'name' => $s['meta']['name']]);
    }
    if ($method === 'POST' && $path === '/upload/drive/v3/files') {
        $sid = (string) count($f['sessions']);
        $f['sessions'][$sid] = ['meta' => json_decode((string) $body, true), 'size' => (int) $headers['X-Upload-Content-Length'], 'data' => ''];
        return ['status' => 200, 'body' => '', 'headers' => ['location' => 'fake://session/' . $sid], 'error' => ''];
    }
    if ($method === 'POST' && $path === '/drive/v3/files') {
        $id = $newId();
        $f['files'][$id] = json_decode((string) $body, true) + ['id' => $id, 'trashed' => false, 'createdTime' => gmdate('c')];
        return $ok(['id' => $id]);
    }
    if ($method === 'GET' && $path === '/drive/v3/files') {
        preg_match("/'([^']+)' in parents/", (string) $query['q'], $m);
        $list = [];
        foreach ($f['files'] as $file) {
            if (in_array($m[1], (array) ($file['parents'] ?? []), true) && !$file['trashed']) {
                $list[] = array_diff_key($file, ['content' => 1]);
            }
        }
        return $ok(['files' => $list]);
    }
    if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
        $file = $f['files'][$m[1]] ?? null;
        if ($file === null) {
            return $ok(['error' => ['message' => 'File not found']], 404);
        }
        if ($method === 'DELETE') {
            unset($f['files'][$m[1]]);
            return ['status' => 204, 'body' => '', 'headers' => [], 'error' => ''];
        }
        if (($query['alt'] ?? '') === 'media') {
            fwrite($opts['sink'], (string) $file['content']);
            return ['status' => 200, 'body' => '', 'headers' => [], 'error' => ''];
        }
        return $ok(array_diff_key($file, ['content' => 1]));
    }
    return $ok(['error' => ['message' => "Unhandled fake call $method $url"]], 400);
}

$passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $message); }
    $passed++;
    echo 'PASS ' . $message . PHP_EOL;
};

$testUploadDir = null;
try {
    require_once __DIR__ . '/../config/config.php';
    require_once ROOT_PATH . '/includes/gdrive-backup.php';

    // Retention plan (pure function)
    $mk = static fn (string $t, string $trigger = 'cron'): array => ['id' => md5($t . $trigger), 'createdTime' => $t, 'appProperties' => ['trigger' => $trigger]];
    $files = [];
    for ($d = 0; $d < 60; $d++) {
        $files[] = $mk(gmdate('Y-m-d\T01:00:00\Z', strtotime("2026-11-28 -$d days")));
    }
    $files[] = $mk('2026-11-28T05:00:00Z', 'manual');
    usort($files, static fn ($a, $b) => strcmp($b['createdTime'], $a['createdTime']));
    $plan = smsBackupRetentionPlan($files, 7, 4);
    $check(count($plan['keep']) === 11, 'retention keeps 7 daily + 4 weekly (got ' . count($plan['keep']) . ')');
    $check($plan['keep'][0]['appProperties']['trigger'] === 'manual', 'retention keeps the newest backup of today');
    $check(count($plan['keep']) + count($plan['delete']) === count($files), 'retention accounts for every file');

    // Schema + tricky data
    $pdo = getDatabaseConnection();
    $schema = (string) file_get_contents(ROOT_PATH . '/database/sms2_db.sql');
    $server->exec("USE `$dbName`");
    $server->exec($schema);
    $pdo->exec('CREATE TABLE qa_values (id INT AUTO_INCREMENT PRIMARY KEY, txt TEXT, bin BLOB, num DOUBLE, dec_col DECIMAL(12,4), dt DATETIME NULL)');
    $tricky = "Line1\nLine2 'quoted' \"dq\" back\\slash ; -- sms2:end ñ 漢字 😀\r\n-- sms2:end\n";
    $binary = random_bytes(512) . "\0\xff\xfe";
    $ins = $pdo->prepare('INSERT INTO qa_values (txt, bin, num, dec_col, dt) VALUES (?, ?, ?, ?, ?)');
    $ins->bindValue(1, $tricky);
    $ins->bindValue(2, $binary, PDO::PARAM_LOB);
    $ins->bindValue(3, 0.1 + 0.2);
    $ins->bindValue(4, '12345678.1234');
    $ins->bindValue(5, null, PDO::PARAM_NULL);
    $ins->execute();
    for ($i = 0; $i < 700; $i++) {
        $pdo->exec("INSERT INTO qa_values (txt, num) VALUES ('row $i', $i)");
    }
    $pdo->exec('CREATE VIEW qa_view AS SELECT id, txt FROM qa_values');
    $checksum = static fn (): string => (string) json_encode($pdo->query('CHECKSUM TABLE qa_values')->fetchAll(PDO::FETCH_NUM));
    $before = $checksum();

    // Upload files (big random file -> several 8 MB chunks)
    $testUploadDir = ROOT_PATH . '/uploads/__backup_test_' . bin2hex(random_bytes(3));
    mkdir($testUploadDir . '/sub', 0777, true);
    file_put_contents($testUploadDir . '/sub/big.bin', random_bytes(9 * 1024 * 1024));
    file_put_contents($testUploadDir . '/note.txt', 'original');

    // Not connected yet -> fails cleanly and is logged
    $r = smsBackupRun('manual', 'QA');
    $check(!$r['ok'] && str_contains($r['error'], 'not connected'), 'backup fails clearly when Drive is not connected');

    smsSetSetting('gdrive_refresh_token', 'fake-refresh');
    $raw = (string) $pdo->query("SELECT setting_value FROM sms2_system_settings WHERE setting_key = 'gdrive_refresh_token'")->fetchColumn();
    $check(str_starts_with($raw, 'sms2enc1.'), 'refresh token is encrypted at rest');

    $r = smsBackupRun('manual', 'QA');
    $check($r['ok'], 'manual backup succeeds: ' . $r['error']);
    $check($GLOBALS['fake']['puts'] >= 2, 'upload used multiple chunks (' . $GLOBALS['fake']['puts'] . ' PUTs)');
    $list = smsGdriveListBackups();
    $check(count($list) === 1 && $list[0]['name'] === $r['file_name'], 'backup appears in the Drive folder list');
    $runs = smsBackupRecentRuns();
    $check($runs[0]['status'] === 'success' && $runs[1]['status'] === 'failed', 'run log records success and earlier failure');
    $check(count(glob(ROOT_PATH . '/storage/backups/work/*.zip') ?: []) === 0, 'temp zip is deleted after upload');

    // Zip content
    $tmp = tempnam(sys_get_temp_dir(), 'bk');
    smsGdriveDownload($list[0]['id'], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $rel = 'files/uploads/' . basename($testUploadDir);
    $check($zip->locateName('database/sms2.sql') !== false && $zip->locateName('manifest.json') !== false, 'zip has database dump and manifest');
    $check($zip->locateName($rel . '/sub/big.bin') !== false, 'zip has uploaded files');
    $check(!str_contains((string) $zip->getFromName('database/sms2.sql'), 'sms2_backup_runs'), 'run log table is not in the dump');
    $zip->close();
    unlink($tmp);

    // Change data, then restore
    $pdo->exec("UPDATE qa_values SET txt = 'changed' WHERE id = 1");
    $pdo->exec('DELETE FROM qa_values WHERE id > 100');
    file_put_contents($testUploadDir . '/note.txt', 'changed');
    unlink($testUploadDir . '/sub/big.bin');
    smsSetSetting('gdrive_account_email', 'new@example.com');
    $check($checksum() !== $before, 'data changed before restore');

    $res = smsBackupRestore($list[0]['id'], 'QA');
    $check($res['ok'], 'restore succeeds: ' . $res['error']);
    $check($checksum() === $before, 'database is identical after restore (text, binary, floats, decimals, NULL)');
    $txt = (string) $pdo->query('SELECT txt FROM qa_values WHERE id = 1')->fetchColumn();
    $check($txt === $tricky, 'tricky text restored byte-for-byte');
    $check((int) $pdo->query('SELECT COUNT(*) FROM qa_view')->fetchColumn() === 701, 'view restored');
    $check(file_get_contents($testUploadDir . '/note.txt') === 'original' && is_file($testUploadDir . '/sub/big.bin'), 'uploaded files restored');
    unset($GLOBALS['__sms_settings_cache']);
    $check(smsSetting('gdrive_account_email') === 'new@example.com', 'current Google connection kept after restore');
    $check($res['safety'] !== '' && count(smsGdriveListBackups()) === 2, 'safety backup uploaded before restore');
    $check(smsBackupRecentRuns()[0]['trigger_type'] === 'pre-restore', 'run log survives restore');

    // Not our file -> refused
    $GLOBALS['fake']['files']['otherfile0001'] = ['id' => 'otherfile0001', 'name' => 'x', 'parents' => ['elsewhere'], 'trashed' => false, 'createdTime' => gmdate('c')];
    $bad = smsBackupRestore('otherfile0001', 'QA');
    $check(!$bad['ok'] && str_contains($bad['error'], 'not an SMS2 backup'), 'restore refuses files that are not our backups');

    $check(!smsBackupSafeZipPath('../evil.php') && !smsBackupSafeZipPath('files/uploads/../../x') && smsBackupSafeZipPath('files/uploads/a/b.pdf'), 'zip-slip paths are rejected');

    echo PHP_EOL . "All $passed checks passed." . PHP_EOL;
    $exit = 0;
} catch (Throwable $e) {
    echo $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    $exit = 1;
} finally {
    if ($testUploadDir && is_dir($testUploadDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testUploadDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $p) { $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname()); }
        rmdir($testUploadDir);
    }
    $server->exec("DROP DATABASE IF EXISTS `$dbName`");
}
exit($exit);

<?php
/** Destructive only to databases created by this test (crad_qa_migration_*). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../database/migrate-lib.php';
$root = dirname(__DIR__);
$host = getenv('SMS2_MIGRATION_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SMS2_MIGRATION_TEST_PORT') ?: '3306';
$user = getenv('SMS2_MIGRATION_TEST_USER') ?: 'root';
$pass = getenv('SMS2_MIGRATION_TEST_PASSWORD') ?: '';
$server = sms2MigrateConnectServer($host, $port, $user, $pass, 'utf8mb4');
$prefix = 'crad_qa_migration_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
$created = [];
$passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) throw new RuntimeException($message);
    $passed++;
    echo 'PASS ' . $message . PHP_EOL;
};
$connect = static function (string $name) use ($host, $port, $user, $pass): PDO {
    return new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};
$create = static function (string $suffix) use ($server, $prefix, &$created, $connect): array {
    $name = $prefix . '_' . $suffix;
    $server->exec('CREATE DATABASE ' . sms2MigrateQuoteIdentifier($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created[] = $name;
    return [$name, $connect($name)];
};
$sms = $root . '/database/sms2_db.sql';
$crad = $root . '/modules/crad/database/crad_db.sql';
$accounts = json_decode((string) file_get_contents(__DIR__ . '/fixtures/deployment-accounts.json'), true, 512, JSON_THROW_ON_ERROR);
$verifyAccounts = static function (PDO $pdo) use ($accounts, $check): void {
    foreach ($accounts as $table => $expected) {
        $rows = $pdo->query('SELECT * FROM ' . sms2MigrateQuoteIdentifier($table) . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $check(count($rows) === $expected['count'] && hash('sha256', json_encode($rows)) === $expected['digest'], $table . ' records exactly preserved');
    }
    // The source dump already has two orphan links. Preserve rather than invent
    // accounts or silently reassign personal records during an import repair.
    $check((int) $pdo->query('SELECT COUNT(*) FROM sms2_student_profiles p LEFT JOIN sms2_users u ON u.id=p.user_id WHERE u.id IS NULL')->fetchColumn() === 2, 'profile links unchanged (2 pre-existing orphan references retained)');
    $check((int) $pdo->query('SELECT COUNT(*) FROM sms2_users u LEFT JOIN sms2_roles r ON r.role_key=u.role_key WHERE r.role_key IS NULL')->fetchColumn() === 0, 'users retain valid roles');
    $max = (int) $pdo->query('SELECT MAX(id) FROM sms2_users')->fetchColumn();
    $next = (int) $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms2_users'")->fetchColumn();
    $check($next > $max, 'next user ID cannot collide with preserved accounts');
};
$run = static function (string $name, array $args = []) use ($root, $host, $port, $user, $pass): array {
    $env = getenv();
    foreach (['SMS2', 'CRAD'] as $p) {
        foreach (['HOST' => $host, 'PORT' => $port, 'NAME' => $name, 'USER' => $user, 'PASS' => $pass] as $key => $value) $env[$p . '_DB_' . $key] = $value;
    }
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, $root . '/database/migrate.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch migration runner');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out . $err];
};
try {
    foreach ([$sms, $crad] as $file) {
        $sql = (string) file_get_contents($file);
        $check(!preg_match('/^\s*(?:CREATE\s+DATABASE|USE\s|GRANT\s)|\bDEFINER\s*=/im', $sql), basename($file) . ' has no hosting-specific database/grant directives');
    }
    [$smsName, $smsPdo] = $create('sms');
    sms2MigrateApplySqlFile($smsPdo, $sms); sms2MigrateValidateSchema($smsPdo, $sms);
    $check(count(sms2MigrateSqlCreateTables($sms)) === 14, 'standalone SMS2 import has all 14 tables');
    $verifyAccounts($smsPdo);
    foreach (['sms2_security_otps', 'sms2_password_resets', 'sms2_password_reset_requests', 'sms2_login_throttles'] as $table) {
        $check((int) $smsPdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 0, $table . ' begins empty');
    }
    $badSql = tempnam(sys_get_temp_dir(), 'crad-migration-error-');
    try {
        file_put_contents($badSql, "SET FOREIGN_KEY_CHECKS=0;\nINSERT INTO sms2_roles (role_key,label) VALUES ('private_fixture','Test'),('private_fixture','Test');\n");
        try {
            sms2MigrateApplySqlFile($smsPdo, $badSql);
            throw new RuntimeException('Expected duplicate-key import failure');
        } catch (RuntimeException $e) {
            $check(str_contains($e->getMessage(), 'statement 2') && str_contains($e->getMessage(), '1062') && !str_contains($e->getMessage(), 'private_fixture'), 'import failure identifies statement/code without seeded values');
        }
        $check((int) $smsPdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() === 1, 'failed import restores foreign-key checks');
    } finally {
        unlink($badSql);
    }
    [$cradName, $cradPdo] = $create('crad');
    sms2MigrateApplySqlFile($cradPdo, $crad); sms2MigrateValidateSchema($cradPdo, $crad);
    $tables = sms2MigrateSqlCreateTables($crad);
    $check(count($tables) === 61, 'standalone CRAD import has all 61 tables');
    $nonempty = 0;
    foreach ($tables as $table) $nonempty += (int) $cradPdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    $check($nonempty === 0, 'all CRAD tables start without demo records');
    sms2MigrateApplySqlFile($smsPdo, $crad);
    sms2MigrateValidateSchema($smsPdo, $sms); sms2MigrateValidateSchema($smsPdo, $crad);
    $verifyAccounts($smsPdo);
    $check((bool) $smsPdo->query("SHOW COLUMNS FROM crad_research_group_members LIKE 'research_stage'")->fetch(), 'combined import retains member research_stage');
    [$code] = $run($smsName);
    $check($code === 0, 'runner adopts validated manual imports');
    [$code] = $run($smsName);
    $check($code === 0, 'repeat migration succeeds without reimporting accounts');
    $verifyAccounts($smsPdo);
    // A recorded migration must still reject a missing required index/column.
    $smsPdo->exec('ALTER TABLE crad_research_group_members DROP INDEX idx_rgm_group_stage');
    [$code, $out] = $run($smsName);
    $check($code !== 0 && str_contains($out, 'idx_rgm_group_stage'), 'recorded migration rejects missing required index');
    $smsPdo->exec('ALTER TABLE crad_research_group_members DROP COLUMN research_stage');
    [$code, $out] = $run($smsName);
    $check($code !== 0 && str_contains($out, 'research_stage'), 'recorded migration rejects missing required column');
    [$runnerName, $runnerPdo] = $create('runner');
    [$code] = $run($runnerName);
    $check($code === 0, 'normal runner imports both dumps into an empty database');
    $verifyAccounts($runnerPdo);
    $runnerPdo = null;
    [$code, $out] = $run($runnerName, ['--fresh']);
    $check($code === 0 && substr_count($out, 'Shared database reset once') === 1, '--fresh resets shared database once and completes both imports');
    $freshPdo = $connect($runnerName);
    sms2MigrateValidateSchema($freshPdo, $sms); sms2MigrateValidateSchema($freshPdo, $crad);
    $verifyAccounts($freshPdo);
    [$code] = $run($runnerName);
    $check($code === 0, 'repeat migration after --fresh succeeds');
    echo "DEPLOYMENT MIGRATION RESULT: PASS ($passed checks)\n";
} finally {
    // Only exact database names successfully created by this invocation are removed.
    foreach ($created as $name) {
        if (!str_starts_with($name, $prefix . '_') || !preg_match('/^crad_qa_migration_[a-z0-9_]+$/', $name)) throw new RuntimeException('Unsafe cleanup target');
        $server->exec('DROP DATABASE ' . sms2MigrateQuoteIdentifier($name));
    }
}

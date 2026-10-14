<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../database/migrate-lib.php';
$root = dirname(__DIR__);
$server = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$files = ['database/sms2_db.sql', 'modules/crad/database/crad_db.sql'];
$report = ['server_version' => $server->getAttribute(PDO::ATTR_SERVER_VERSION), 'runs' => []];
$stamp = date('Ymd_His');
foreach (['sms2' => [$files[0]], 'crad' => [$files[1]], 'combined' => $files] as $kind => $sources) {
    $name = 'crad_qa_dump_' . $stamp . '_' . $kind;
    if (!preg_match('/^crad_qa_dump_[0-9_]+_(sms2|crad|combined)$/', $name)) throw new RuntimeException('Unsafe fixture name');
    $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $name . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $run = ['database' => $name, 'mode' => 'diagnostic continue after errors; not successful standard migration', 'errors' => [], 'success_count' => 0];
    foreach ($sources as $file) {
        $sql = (string) file_get_contents($root . '/' . $file);
        $offset = 0;
        foreach (sms2MigrateSplitSql($sql) as $i => $statement) {
            $pos = strpos(str_replace("\r\n", "\n", $sql), $statement, $offset);
            $line = $pos === false ? null : substr_count(substr(str_replace("\r\n", "\n", $sql), 0, $pos), "\n") + 1;
            if ($pos !== false) $offset = $pos + strlen($statement);
            $clean = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $statement);
            if (preg_match('/\b(?:USE\s+`|CREATE\s+DATABASE|DROP\s+DATABASE)/i', $clean)) throw new RuntimeException('Refusing cross-database statement');
            try { $pdo->exec($statement); $run['success_count']++; }
            catch (PDOException $e) {
                preg_match('/\b(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE|INSERT\s+INTO)\s+`([^`]+)`/i', $clean, $m);
                $run['errors'][] = ['file' => $file, 'statement_index' => $i, 'line' => $line, 'operation' => $m[1] ?? 'other', 'table' => $m[2] ?? null, 'sqlstate' => $e->errorInfo[0] ?? null, 'mysql_code' => $e->errorInfo[1] ?? null, 'detail' => preg_match('/^(?:CREATE|ALTER)/i', trim($clean)) ? ($e->errorInfo[2] ?? '') : 'Data statement failed; values omitted'];
            }
        }
    }
    $q = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $q->execute([$name]);
    $run['columns'] = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
    $q->execute([$name]); $run['indexes'] = $q->fetchAll(PDO::FETCH_ASSOC);
    $report['runs'][$kind] = $run;
    echo $kind . ': ' . count($run['errors']) . ' SQL errors; ' . count(array_unique(array_column($run['columns'], 'TABLE_NAME'))) . ' tables', PHP_EOL;
}
file_put_contents($root . '/docs/schema-audit-2026-10-14/import-diagnostics.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

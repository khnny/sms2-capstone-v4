<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// Disposable fixture preparation only; never use against an application database.
require_once __DIR__ . '/../../database/migrate-lib.php';
$name = 'crad_qa_flow_20261014c';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $name, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (in_array('--panel-schemas', $argv, true)) {
    require_once __DIR__ . '/../../modules/crad/includes/panelist-workflow.php';
    cradEnsurePanelistWorkflowSchema($pdo);
    cradEnsurePanelistAssignmentSchema($pdo);
    echo 'Prepared panel workflow fixture prerequisites', PHP_EOL;
    exit;
}
$deferred = [];
foreach (['database/sms2_db.sql', 'modules/crad/database/crad_db.sql'] as $file) {
    foreach (sms2MigrateSplitSql((string) file_get_contents(__DIR__ . '/../../' . $file)) as $i => $sql) {
        if (in_array('--finish', $argv, true)) {
            if ($file === 'database/sms2_db.sql' && $i >= 60 && $i <= 76) $deferred[] = $sql;
            continue;
        }
        try { $pdo->exec($sql); }
        catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1146) {
                $deferred[] = $sql;
                echo "$file statement $i: deferred until CRAD tables exist", PHP_EOL;
                continue;
            }
            if (($e->errorInfo[1] ?? 0) !== 1068) {
                echo "$file statement $i: ", $e->getMessage(), PHP_EOL;
                throw $e;
            }
            // The source repeats PRIMARY KEY for some tables. Fixture-only workaround.
            $retry = preg_replace('/ADD\s+PRIMARY\s+KEY\s*\([^)]*\)\s*,?/i', '', $sql);
            $retry = preg_replace('/,\s*$/', '', trim((string) $retry));
            if (!preg_match('/ALTER\s+TABLE\s+`[^`]+`\s*$/i', $retry)) $pdo->exec($retry);
            echo "$file statement $i: fixture-only duplicate-primary-key workaround", PHP_EOL;
        }
    }
}
foreach ($deferred as $sql) {
    try { $pdo->exec($sql); }
    catch (PDOException $e) {
        if (!in_array($e->errorInfo[1] ?? 0, [1060, 1061], true)) throw $e;
        echo 'Fixture-only already-present column/index skipped', PHP_EOL;
    }
}
$pdo->exec("UPDATE sms2_system_settings SET setting_value='' WHERE setting_key LIKE 'smtp_%'");
$pdo->exec("UPDATE sms2_system_settings SET setting_value='0' WHERE setting_key='login_captcha_enabled'");
echo "Prepared disposable schema $name", PHP_EOL;

<?php
/**
 * Helper for tools/update-local.ps1 (CLI only).
 *
 *   php tools/local-db.php --dsn             JSON with host, port, name, user, pass for mysqldump
 *   php tools/local-db.php --apply a.sql ...  run SQL patch files against the local database
 *
 * Reads the same settings the app uses (config/local.php, env vars, defaults).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

$args = array_slice($argv, 1);
$mode = array_shift($args) ?? '';

if ($mode === '--dsn') {
    echo json_encode([
        'host' => DB_HOST,
        'port' => (string) DB_PORT,
        'name' => DB_NAME,
        'user' => DB_USER,
        'pass' => DB_PASS,
    ]);
    exit(0);
}

if ($mode !== '--apply' || $args === []) {
    fwrite(STDERR, "Usage: php tools/local-db.php --dsn | --apply file.sql [file.sql ...]\n");
    exit(2);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect to the local database: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($args as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing patch file: {$file}\n");
        exit(1);
    }
    $sql = (string) file_get_contents($file);
    // Patches are plain statements (no DELIMITER blocks): split on ';' at line end.
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $count = 0;
    foreach ($statements as $statement) {
        $body = trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? '');
        if ($body === '') {
            continue;
        }
        try {
            $pdo->exec($body);
            $count++;
        } catch (Throwable $e) {
            fwrite(STDERR, basename($file) . ': ' . $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo basename($file) . ": {$count} statement(s) applied\n";
}

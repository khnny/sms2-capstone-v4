<?php
/**
 * Shared SMS 2 deployment migration logic (CLI + web deploy).
 */
declare(strict_types=1);

function sms2MigrateOut(string $message, ?callable $sink = null): void
{
    if ($sink) {
        $sink($message);
        return;
    }

    echo $message . PHP_EOL;
}

function sms2MigrateQuoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function sms2MigrateConnectServer(string $host, string $port, string $user, string $pass, string $charset): PDO
{
    return new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';charset=' . $charset,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function sms2MigrateSqlCreateTables(string $sqlFile): array
{
    if (!is_readable($sqlFile)) {
        return [];
    }

    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', (string) file_get_contents($sqlFile), $matches);

    return array_values(array_unique($matches[1] ?? []));
}

function sms2MigrateExistingTargetTableCount(PDO $pdo, array $tables): int
{
    if (!$tables) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')'
    );
    $stmt->execute($tables);

    return (int) $stmt->fetchColumn();
}

function sms2MigrateEnsureDatabase(PDO $pdo, string $database, ?callable $sink = null): void
{
    $quotedDatabase = sms2MigrateQuoteIdentifier($database);

    try {
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $quotedDatabase .
            ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        return;
    } catch (PDOException $createError) {
        try {
            $pdo->exec('USE ' . $quotedDatabase);
            sms2MigrateOut('Create database skipped by host permissions; existing database is accessible.', $sink);
            return;
        } catch (PDOException) {
            throw $createError;
        }
    }
}

function sms2MigrateSplitSql(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $delimiter = ';';
    $statement = '';
    $statements = [];

    foreach (explode("\n", $sql) as $line) {
        $trimmed = trim($line);

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $matches)) {
            $delimiter = $matches[1];
            continue;
        }

        if ($delimiter !== ';') {
            if (str_ends_with($trimmed, $delimiter)) {
                $statement .= substr($line, 0, strrpos($line, $delimiter)) . "\n";
                $candidate = trim($statement);
                if ($candidate !== '') {
                    $statements[] = $candidate;
                }
                $statement = '';
                continue;
            }

            $statement .= $line . "\n";
            continue;
        }

        $statement .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $candidate = trim(substr($statement, 0, strrpos($statement, ';')));
            if ($candidate !== '') {
                $statements[] = $candidate;
            }
            $statement = '';
        }
    }

    $candidate = trim($statement);
    if ($candidate !== '') {
        $statements[] = $candidate;
    }

    return $statements;
}

function sms2MigrateApplySqlFile(PDO $pdo, string $sqlFile): int
{
    if (!is_readable($sqlFile)) {
        throw new RuntimeException('SQL file not readable: ' . $sqlFile);
    }

    $sql = (string) file_get_contents($sqlFile);
    $statements = sms2MigrateSplitSql($sql);
    $applied = 0;

    try {
        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Driver messages can contain seeded account values. Report codes only.
                throw new RuntimeException(
                    basename($sqlFile) . ' statement ' . ($index + 1)
                    . ' failed (SQLSTATE ' . ($e->errorInfo[0] ?? $e->getCode())
                    . ', MySQL ' . ($e->errorInfo[1] ?? 'unknown') . ').'
                );
            }
            $applied++;
        }
    } finally {
        // Dumps temporarily disable FK checks while creating dependent account tables.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    return $applied;
}

/** Required columns and ordered indexes are read from the canonical fresh schema. */
function sms2MigrateSchemaContract(string $sqlFile): array
{
    $sql = file_get_contents($sqlFile);
    if ($sql === false) throw new RuntimeException('Schema file is not readable: ' . basename($sqlFile));
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\((.*?)\)\s*ENGINE/is', $sql, $tables, PREG_SET_ORDER);
    $contract = [];
    foreach ($tables as $table) {
        preg_match_all('/^\s*`([^`]+)`\s+/m', $table[2], $columns);
        $indexes = [];
        preg_match_all('/^\s*(PRIMARY KEY|(?:UNIQUE )?KEY `[^`]+`)\s*\(([^\n]+)\)/m', $table[2], $keys, PREG_SET_ORDER);
        foreach ($keys as $key) {
            $name = 'PRIMARY';
            if ($key[1] !== 'PRIMARY KEY') {
                preg_match('/`([^`]+)`/', $key[1], $match);
                $name = $match[1];
            }
            preg_match_all('/`([^`]+)`(?:\((\d+)\))?/', $key[2], $parts, PREG_SET_ORDER);
            $indexes[$name] = [
                'unique' => $name === 'PRIMARY' || str_starts_with($key[1], 'UNIQUE'),
                'parts' => array_map(static fn(array $part): array => [$part[1], isset($part[2]) ? (int) $part[2] : null], $parts),
            ];
        }
        $contract[$table[1]] = ['columns' => $columns[1], 'indexes' => $indexes];
    }
    if (!$contract) throw new RuntimeException('No schema definitions found: ' . basename($sqlFile));
    return $contract;
}

function sms2MigrateValidateSchema(PDO $pdo, string $sqlFile): void
{
    $columns = [];
    foreach ($pdo->query('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }
    $indexes = [];
    foreach ($pdo->query('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $index = &$indexes[$row['TABLE_NAME']][$row['INDEX_NAME']];
        $index['unique'] = (int) $row['NON_UNIQUE'] === 0;
        $index['parts'][] = [$row['COLUMN_NAME'], $row['SUB_PART'] === null ? null : (int) $row['SUB_PART']];
        unset($index);
    }
    $errors = [];
    foreach (sms2MigrateSchemaContract($sqlFile) as $table => $required) {
        if (!isset($columns[$table])) {
            $errors[] = 'missing table ' . $table;
            continue;
        }
        foreach (array_diff($required['columns'], $columns[$table]) as $column) $errors[] = 'missing column ' . $table . '.' . $column;
        foreach ($required['indexes'] as $name => $expected) {
            if (($indexes[$table][$name] ?? null) !== $expected) $errors[] = 'missing or incompatible index ' . $table . '.' . $name;
        }
    }
    if ($errors) {
        throw new RuntimeException('Schema validation failed for ' . basename($sqlFile) . ': ' . implode('; ', array_slice($errors, 0, 15)) . '. Repair with a data-preserving schema upgrade; do not re-import seeded dumps over existing accounts.');
    }
}

function sms2MigrateEnsureTrackingTable(PDO $pdo): void
{
    // Prefer prefixed name; keep creating legacy name only if migrating old installs mid-cutover.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration_key` varchar(120) NOT NULL,
            `source_file` varchar(255) NOT NULL,
            `source_sha256` char(64) NOT NULL,
            `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_migration_key` (`migration_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function sms2MigrateWasRecorded(PDO $pdo, string $migrationKey): bool
{
    sms2MigrateEnsureTrackingTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT 1 FROM `schema_migrations` WHERE `migration_key` = ? LIMIT 1'
    );
    $stmt->execute([$migrationKey]);

    return (bool) $stmt->fetchColumn();
}

function sms2MigrateRecord(PDO $pdo, string $migrationKey, string $sourceFile): void
{
    sms2MigrateEnsureTrackingTable($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO `schema_migrations` (`migration_key`, `source_file`, `source_sha256`)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
             `source_file` = VALUES(`source_file`),
             `source_sha256` = VALUES(`source_sha256`),
             `applied_at` = current_timestamp()'
    );
    $stmt->execute([
        $migrationKey,
        str_replace('\\', '/', $sourceFile),
        hash_file('sha256', $sourceFile),
    ]);
}

function sms2MigrateOneDatabase(array $target, array $options, ?callable $sink = null): void
{
    $label = $target['label'];
    $database = $target['database'];
    $quotedDatabase = sms2MigrateQuoteIdentifier($database);

    sms2MigrateOut('', $sink);
    sms2MigrateOut('== ' . $label . ' ==', $sink);
    sms2MigrateOut('SQL: ' . $target['sql_file'], $sink);
    sms2MigrateOut('DB : ' . $target['host'] . '/' . $database, $sink);

    $pdo = sms2MigrateConnectServer(
        $target['host'],
        $target['port'],
        $target['user'],
        $target['pass'],
        $target['charset']
    );

    sms2MigrateEnsureDatabase($pdo, $database, $sink);
    $pdo->exec('USE ' . $quotedDatabase);

    if (!$options['fresh'] && !$options['force']) {
        if (sms2MigrateWasRecorded($pdo, $target['migration_key'])) {
            sms2MigrateValidateSchema($pdo, $target['sql_file']);
            sms2MigrateOut('Skipped: recorded migration schema validated.', $sink);
            return;
        }

        $targetTables = sms2MigrateSqlCreateTables($target['sql_file']);
        $existingTargetTables = sms2MigrateExistingTargetTableCount($pdo, $targetTables);
        if ($targetTables && $existingTargetTables === count($targetTables)) {
            sms2MigrateValidateSchema($pdo, $target['sql_file']);
            sms2MigrateRecord($pdo, $target['migration_key'], $target['sql_file']);
            sms2MigrateOut('Skipped: target tables already exist; recorded this migration as applied.', $sink);
            return;
        }

        if ($existingTargetTables > 0) {
            throw new RuntimeException(
                'Partial schema detected for ' . $label .
                '. Restore or repair the schema before retrying; do not re-import seeded dumps over existing accounts.'
            );
        }
    }

    $applied = sms2MigrateApplySqlFile($pdo, $target['sql_file']);
    sms2MigrateValidateSchema($pdo, $target['sql_file']);
    sms2MigrateRecord($pdo, $target['migration_key'], $target['sql_file']);

    sms2MigrateOut('Applied ' . $applied . ' SQL statement(s).', $sink);
}

/** Keep clearance/payment images in HostForge's managed database across redeploys. */
function sms2MigrateEnsureDurableUploadColumns(PDO $pdo, ?callable $sink = null): void
{
    $tables = [
        'crad_research_clearance_payments',
        'crad_research_services_clearances',
    ];
    $columns = [
        'uploaded_blob' => 'MEDIUMBLOB NULL',
        'uploaded_mime' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'uploaded_size' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($tables as $table) {
        // MariaDB does not accept native PDO placeholders in SHOW statements.
        // Query information_schema instead so this works on HostForge.
        $exists = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $exists->execute([$table]);
        if (!$exists->fetchColumn()) continue;
        foreach ($columns as $column => $definition) {
            $check = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $check->execute([$table, $column]);
            if ($check->fetchColumn()) continue;
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            sms2MigrateOut('Added durable upload column ' . $table . '.' . $column, $sink);
        }
    }
}

/**
 * @return array<int, string>
 */
function sms2RunMigrations(array $options = []): array
{
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../modules/crad/config/config.php';

    $options = array_merge(['fresh' => false, 'force' => false], $options);
    $lines = [];
    $sink = static function (string $message) use (&$lines): void {
        $lines[] = $message;
    };

    // Single-database architecture: both dumps apply into DB_NAME (sms2_* + crad_*).
    $targets = [
        [
            'label' => 'SMS2 schema (sms2_*)',
            'migration_key' => '2026_09_21_sms2_prefixed_dump',
            'host' => DB_HOST,
            'port' => DB_PORT,
            'database' => DB_NAME,
            'user' => DB_USER,
            'pass' => DB_PASS,
            'charset' => DB_CHARSET,
            'sql_file' => __DIR__ . '/sms2_db.sql',
        ],
        [
            'label' => 'CRAD schema (crad_*)',
            'migration_key' => '2026_09_21_crad_prefixed_dump',
            'host' => DB_HOST,
            'port' => DB_PORT,
            'database' => DB_NAME,
            'user' => DB_USER,
            'pass' => DB_PASS,
            'charset' => DB_CHARSET,
            'sql_file' => dirname(__DIR__) . '/modules/crad/database/crad_db.sql',
        ],
    ];

    sms2MigrateOut('Single-database mode: applying SMS2 + CRAD into ' . DB_NAME, $sink);

    $connection = strtolower((string) sms2_env_first(['SMS2_DB_CONNECTION', 'DB_CONNECTION'], 'mysql'));
    if (!in_array($connection, ['mysql', 'mariadb'], true)) {
        throw new RuntimeException(
            'Unsupported DB_CONNECTION "' . $connection . '". Use MySQL/MariaDB for SMS 2.'
        );
    }

    sms2MigrateOut('SMS 2 deployment migration started.', $sink);
    if ($options['fresh']) {
        // Both target files share DB_NAME: resetting inside the loop erases SMS2
        // immediately before CRAD is imported.
        $server = sms2MigrateConnectServer(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_CHARSET);
        $server->exec('DROP DATABASE IF EXISTS ' . sms2MigrateQuoteIdentifier(DB_NAME));
        sms2MigrateOut('Shared database reset once because --fresh was provided.', $sink);
    }
    foreach ($targets as $target) {
        sms2MigrateOneDatabase($target, $options, $sink);
    }
    $pdo = sms2MigrateConnectServer(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_CHARSET);
    $pdo->exec('USE ' . sms2MigrateQuoteIdentifier(DB_NAME));
    sms2MigrateEnsureDurableUploadColumns($pdo, $sink);
    sms2MigrateOut('', $sink);
    sms2MigrateOut('Migration complete.', $sink);

    return $lines;
}

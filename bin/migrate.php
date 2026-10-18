<?php
declare(strict_types=1);

/**
 * Creates the UMPCSL database and applies database/schema.sql.
 * Usage:  php bin/migrate.php
 */

const APP_ROOT = __DIR__ . '/..';

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/accounting.php';

$dbName = (string) config('db.database');

echo "UMPCSL database migration\n";
echo "-------------------------\n";

try {
    $server = db_server();
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "✓ Database `$dbName` ready.\n";

    $pdo = db();
    $sql = file_get_contents(APP_ROOT . '/database/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read database/schema.sql');
    }

    // Strip line comments, then split into individual statements.
    $lines = preg_split('/\r?\n/', $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (strpos($trimmed, '--') === 0) {
            continue;
        }
        $clean[] = $line;
    }
    $statements = array_filter(array_map('trim', explode(';', implode("\n", $clean))));

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    echo "✓ Applied " . count($statements) . " schema statements.\n";

    ensure_default_accounts();
    echo "✓ Default chart of accounts ready.\n";

    echo "Migration complete.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

<?php
declare(strict_types=1);

/**
 * Database connection helpers (PDO / MySQL).
 */

/**
 * Return a shared PDO connection to the application database.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int) $cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Open a connection to the MySQL server without selecting a database.
 * Used by the migration script to create the schema.
 */
function db_server(): PDO
{
    $cfg = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['charset']);

    return new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/**
 * Run a query and return the statement.
 */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row or null. */
function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Fetch a single scalar value. */
function fetch_value(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

/** Insert a row and return its id. */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $cols);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $cols),
        implode(', ', $placeholders)
    );
    q($sql, $data);
    return (int) db()->lastInsertId();
}

/** Update rows in a table by primary key. */
function db_update(string $table, int $id, array $data): void
{
    if ($data === []) {
        return;
    }
    $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
    $data['__id'] = $id;
    q("UPDATE $table SET $sets WHERE id = :__id", $data);
}

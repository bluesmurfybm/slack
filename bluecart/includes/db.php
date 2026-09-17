<?php
/**
 * PDO 래퍼. 모든 질의는 prepared statement 로만 실행한다.
 */

declare(strict_types=1);

function bc_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = bc_config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $pdo->exec("SET time_zone = '+09:00'");

    return $pdo;
}

function bc_query(string $sql, array $params = []): PDOStatement
{
    $stmt = bc_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function bc_fetch_all(string $sql, array $params = []): array
{
    return bc_query($sql, $params)->fetchAll();
}

function bc_fetch_one(string $sql, array $params = []): ?array
{
    $row = bc_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function bc_fetch_value(string $sql, array $params = [], mixed $default = null): mixed
{
    $value = bc_query($sql, $params)->fetchColumn();
    return $value === false ? $default : $value;
}

function bc_transaction(callable $fn): mixed
{
    $pdo = bc_db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

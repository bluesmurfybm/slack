<?php

namespace Dti;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config) {}

    public function pdo(): PDO
    {
        if ($this->pdo) return $this->pdo;

        $db = $this->config->db;
        $dsn = "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}`
                    CHARACTER SET {$db['charset']} COLLATE {$db['charset']}_unicode_ci");
        $pdo->exec("USE `{$db['name']}`");

        return $this->pdo = $pdo;
    }

    public function migrate(): void
    {
        $pdo = $this->pdo();
        foreach (Schema::tables() as $sql) {
            $pdo->exec($sql);
        }
        // 컬럼 추가는 저장소 공통 헬퍼로만 한다 — 동시 요청에서 "확인 후 ALTER" 는 레이스가 난다
        require_once __DIR__ . '/../../db.php';
        foreach (Schema::alters() as $sql) {
            add_column_if_missing($pdo, $sql);
        }
        $this->seedFields();
    }

    public function seedFields(): void
    {
        $pdo = $this->pdo();
        if ((int)$pdo->query("SELECT COUNT(*) FROM dti_fields")->fetchColumn() > 0) return;

        $insert = $pdo->prepare("INSERT INTO dti_fields (name) VALUES (?)");
        foreach (Config::DEFAULT_FIELDS as $name) {
            $insert->execute([$name]);
        }
    }
}

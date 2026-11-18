<?php

namespace Schooltodo\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

require_once __DIR__ . '/../../bootstrap.php';

abstract class TestCase extends BaseTestCase
{
    /** @var array */
    protected $config;
    /** @var \PDO */
    protected $pdo;

    protected function setUp(): void
    {
        $portal = require __DIR__ . '/../../../config.php';
        $db = $portal['db'];
        $db['name'] = getenv('SCHOOLTODO_TEST_DB') ?: 'slackapi_test';

        $this->config = schooltodo_config(['db' => $db]);
        $this->pdo = schooltodo_connect($this->config);
        schooltodo_migrate($this->pdo);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `schools` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(200) NOT NULL,
            `ver` VARCHAR(20) NOT NULL DEFAULT '',
            `dev` VARCHAR(500) NOT NULL DEFAULT '',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // VPN·repo 열만 쓴다
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `school_access` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `vpn` VARCHAR(80) NOT NULL DEFAULT '',
            `repo` TEXT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // TRUNCATE 는 InnoDB 에서 테이블을 다시 만드는 DDL 이라 DELETE 보다 훨씬 느리다
        $this->pdo->beginTransaction();
        $this->pdo->exec("DELETE FROM schooltodo_cell_history");
        $this->pdo->exec("DELETE FROM schooltodo_cells");
        $this->pdo->exec("DELETE FROM schooltodo_sheets");
        $this->pdo->exec("DELETE FROM schools");
        $this->pdo->exec("DELETE FROM school_access");
        $this->pdo->commit();
    }
}

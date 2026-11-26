<?php

namespace Bluecluster\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

require_once __DIR__ . '/../../inc/settings.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/repo.php';

abstract class TestCase extends BaseTestCase
{
    /** @var \PDO */
    protected $pdo;

    protected function setUp(): void
    {
        $portal = require __DIR__ . '/../../../config.php';
        $db = $portal['db'];
        $db['name'] = getenv('BLUECLUSTER_TEST_DB') ?: 'slackapi_test';

        $this->pdo = bcl_connect($db);
        bcl_migrate($this->pdo);

        // TRUNCATE 는 InnoDB 에서 테이블을 다시 만드는 DDL 이라 DELETE 보다 훨씬 느리다
        $this->pdo->beginTransaction();
        foreach (['bcl_attempts', 'bcl_pieces', 'bcl_jobs', 'bcl_nodes'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->commit();
    }
}

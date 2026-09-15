<?php

namespace Dti\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * DB 를 쓰는 테스트의 공통 바탕. 매 테스트마다 새 설정·연결을 만들고
 * dti_* 를 비운다 — 전역 캐시가 없으므로 초기화할 상태는 테이블뿐이다.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    /** 포털 계정. 구성원 명단·배정·점수 테스트가 이 명단을 기준으로 본다. */
    public const PORTAL_USERS = [
        ['김호영', 'kimhy@bluesoft.co.kr'],
        ['김지안', 'jian@bluesoft.co.kr'],
        ['박성철', 'scpark@bluesoft.co.kr'],
        ['김태주', 'pink@bluesoft.co.kr'],
        ['안정민', 'venus@bluesoft.co.kr'],
        ['조성훈', 'akddd@bluesoft.co.kr'],
        ['진소현', 'lenda83@bluesoft.co.kr'],
        ['김아랑', 'amitoa@bluesoft.co.kr'],
        ['박화랑', 'phr@bluesoft.co.kr'],
        ['유병문', 'bnmmnbhj@bluesoft.co.kr'],
        ['유승인', 'siyu@bluesoft.co.kr'],
        ['이한재', 'hjlee@bluesoft.co.kr'],
        ['이준영', 'jun0@bluesoft.co.kr'],
    ];

    protected array $config;
    protected \PDO $pdo;
    protected string $uploadDir;
    protected \Closure $mover;
    protected RecordingWebhook $webhook;

    protected function setUp(): void
    {
        $this->uploadDir = DTI_TEST_TMP . '/' . uniqid('up', true);
        mkdir($this->uploadDir, 0777, true);

        $this->config = $this->makeConfig();
        // 진짜 업로드가 아니라 move_uploaded_file 이 통하지 않는다. 옮기는 방법만 갈아끼운다
        $this->mover = static fn (string $from, string $to) => rename($from, $to);
        $this->webhook = new RecordingWebhook();
        $this->pdo = dti_connect($this->config);
        if (!self::$migrated) {
            dti_migrate($this->pdo);
            $this->createPortalUsers();
            self::$migrated = true;
        }
        // 이 환경은 커밋마다 fsync 가 돌아 쓰기 한 건이 0.2초다. 준비 작업은 한 번에 커밋한다.
        $pdo = $this->pdo;
        $pdo->beginTransaction();
        $this->truncate();
        $this->seedPortalUsers();
        $pdo->commit();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir . '/*') ?: [] as $file) unlink($file);
        @rmdir($this->uploadDir);
    }

    protected function makeConfig(array $over = []): array
    {
        $portal = require __DIR__ . '/../../../config.php';
        $db = $portal['db'];
        $db['name'] = DTI_TEST_DB;

        return dti_config([
            'db' => $db,
            'upload_dir' => $over['uploadDir'] ?? $this->uploadDir,
            'max_upload_mb' => $over['maxUploadMb'] ?? 50,
            'admin_emails' => $over['adminEmails'] ?? ['jian@bluesoft.co.kr'],
            'slack_webhook' => $over['slackWebhook'] ?? null,
        ]);
    }

    /** 포털 테이블이지만 테스트 DB 에도 있어야 명단·배정을 볼 수 있다 */
    private function createPortalUsers(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `portal_users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(60) NOT NULL,
            `email` VARCHAR(190) NOT NULL,
            `pw_hash` VARCHAR(255) NOT NULL DEFAULT '',
            `color` VARCHAR(7) NULL,
            `created_at` DATETIME NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    protected function seedPortalUsers(array $users = self::PORTAL_USERS): void
    {
        $pdo = $this->pdo;
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        $pdo->exec("DELETE FROM portal_users");
        $insert = $pdo->prepare("INSERT INTO portal_users (name, email, color) VALUES (?, ?, '#606D79')");
        foreach ($users as [$name, $email]) {
            $insert->execute([$name, $email]);
        }
        if ($own) $pdo->commit();
    }

    /**
     * TRUNCATE 가 아니라 DELETE 로 비운다. TRUNCATE 는 InnoDB 에서 테이블을 다시 만드는
     * DDL 이라 이 환경에서 한 번에 3초 가까이 걸린다(DELETE 는 사실상 0초).
     */
    protected function truncate(): void
    {
        $pdo = $this->pdo;
        foreach (['dti_related', 'dti_emotions', 'dti_presentations', 'dti_topics', 'dti_fields'] as $table) {
            $pdo->exec("DELETE FROM `{$table}`");
        }
        dti_seed_fields($this->pdo);
    }

    /* ---------- 요청 헬퍼 ---------- */

    protected function admin(): array
    {
        return ['email' => 'jian@bluesoft.co.kr', 'name' => '김지안'];
    }

    protected function user(): array
    {
        return ['email' => 'siyu@bluesoft.co.kr', 'name' => '유승인'];
    }

    protected function other(): array
    {
        return ['email' => 'hjlee@bluesoft.co.kr', 'name' => '이한재'];
    }

    protected function call(string $method, array $segments, ?array $identity, array $body = [], array $query = [], array $files = []): array
    {
        return dti_handle([
            'config' => $this->config,
            'pdo' => $this->pdo,
            'identity' => $identity,
            'mover' => $this->mover,
            'webhook' => \Closure::fromCallable($this->webhook),
        ], ['method' => $method, 'seg' => $segments, 'body' => $body,
            'query' => $query, 'files' => $files]);
    }

    protected function get(array $segments, ?array $identity = null, array $query = []): array
    {
        return $this->call('GET', $segments, $identity ?? $this->user(), [], $query);
    }

    protected function post(array $segments, ?array $identity = null, array $body = [], array $files = []): array
    {
        return $this->call('POST', $segments, $identity ?? $this->user(), $body, [], $files);
    }

    protected function put(array $segments, ?array $identity = null, array $body = []): array
    {
        return $this->call('PUT', $segments, $identity ?? $this->user(), $body);
    }

    protected function delete(array $segments, ?array $identity = null): array
    {
        return $this->call('DELETE', $segments, $identity ?? $this->user());
    }

    /* ---------- 데이터 헬퍼 ---------- */

    /** $_FILES 모양의 업로드 한 건을 만든다 */
    protected function upload(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dtiup');
        file_put_contents($tmp, $content);

        return ['file' => [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'error' => UPLOAD_ERR_OK,
        ]];
    }

    /** API 를 거치지 않고 아티클을 심는다. 조회·권한 테스트가 준비 단계에서 쓴다. */
    protected function makeTopic(array $values = []): int
    {
        $values = array_merge(['title' => '주제', 'created_at' => '2026-01-01 09:00:00'], $values);
        $columns = array_keys($values);
        $sql = 'INSERT INTO dti_topics (`' . implode('`, `', $columns) . '`) VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($values));
        return (int)$this->pdo->lastInsertId();
    }

    protected function makePresentation(int $topicId, array $values = []): int
    {
        $values = array_merge(['topic_id' => $topicId, 'created_at' => '2026-01-01 09:00:00'], $values);
        $columns = array_keys($values);
        $sql = 'INSERT INTO dti_presentations (`' . implode('`, `', $columns) . '`) VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($values));
        return (int)$this->pdo->lastInsertId();
    }
}

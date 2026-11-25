<?php

namespace Core\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api_token.php';

final class api_token_test extends TestCase
{
    /** @var PDO */
    private $pdo;

    protected function setUp(): void
    {
        // portal_users 에 쓰고 지우므로 운영 DB 를 가리키는 config.php 에서는 돌지 않는다
        $cfg = require __DIR__ . '/../../config.php';
        if (($cfg['db']['driver'] ?? 'mysql') !== 'sqlite' && !str_ends_with($cfg['db']['name'], '_test')) {
            $this->markTestSkipped('config.php 의 DB 가 시험용(sqlite 또는 *_test)이 아닙니다');
        }
        $this->pdo = portal_db();
        $this->pdo->exec("DELETE FROM portal_users WHERE email LIKE '%@token.test'");
    }

    /** portal_api_token_ensure: 처음엔 발급, 두 번째는 같은 값 */
    public function test_ensure_재사용(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();

        $first = portal_api_token_ensure($this->pdo, $uid);

        $this->assertMatchesRegularExpression('/^iwk_[0-9a-f]{64}$/', $first);
        $this->assertSame($first, portal_api_token_ensure($this->pdo, $uid));
    }

    /** portal_api_token_ensure: 없는 user_id(0) 면 PDOException */
    public function test_ensure_없는_user_id(): void
    {
        $this->expectException(PDOException::class);

        portal_api_token_ensure($this->pdo, 0);
    }

    /** portal_api_token_user: 맞는 토큰 → 사용자 행 */
    public function test_user_맞는_토큰(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        $token = portal_api_token_ensure($this->pdo, $uid);

        $user = portal_api_token_user($this->pdo, $token, '203.0.113.7');

        $this->assertSame('홍길동', $user['name']);
        $this->assertSame('a@token.test', $user['email']);
    }

    /** portal_api_token_user: 틀린 토큰·빈 값 → null */
    public function test_user_틀린_토큰(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        portal_api_token_ensure($this->pdo, $uid);

        $this->assertNull(portal_api_token_user($this->pdo, 'iwk_' . str_repeat('0', 64), '203.0.113.7'));
        $this->assertNull(portal_api_token_user($this->pdo, '', '203.0.113.7'));
    }

    /** portal_api_token_user: iwk_ 형식 아님 → DB 를 찾지 않고 null */
    public function test_user_형식_아님(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        // 형식을 안 보고 찾으면 걸리도록 'abc123' 의 해시를 넣어 둔다
        $this->pdo->prepare("INSERT INTO portal_api_token (user_id, token_hash, token_enc, created_at) VALUES (?, ?, 'x', '2026-11-25 09:00:00')")
            ->execute([$uid, hash('sha256', 'abc123')]);

        $this->assertNull(portal_api_token_user($this->pdo, 'abc123', '203.0.113.7'));
        $this->assertNull(portal_api_token_user($this->pdo, 'iwk_XYZ', '203.0.113.7'));
    }

    /** portal_api_token_rotate: 새 값 발급, 옛 값 null, rotated_at 기록 */
    public function test_rotate(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        $old = portal_api_token_ensure($this->pdo, $uid);

        $new = portal_api_token_rotate($this->pdo, $uid);

        $this->assertMatchesRegularExpression('/^iwk_[0-9a-f]{64}$/', $new);
        $this->assertNotSame($old, $new);
        $this->assertNull(portal_api_token_user($this->pdo, $old, '203.0.113.7'));
        $this->assertSame('홍길동', portal_api_token_user($this->pdo, $new, '203.0.113.7')['name']);
        $this->assertNotNull(portal_api_token_get($this->pdo, $uid)['rotated_at']);
    }

    /** portal_api_token_rotate: 토큰이 없으면 새로 발급 */
    public function test_rotate_토큰_없음(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();

        $token = portal_api_token_rotate($this->pdo, $uid);

        $this->assertSame('홍길동', portal_api_token_user($this->pdo, $token, '203.0.113.7')['name']);
        $this->assertSame($token, portal_api_token_get($this->pdo, $uid)['token']);
    }

    /** portal_api_token_delete: 삭제 뒤 user null, has false */
    public function test_delete(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        $token = portal_api_token_ensure($this->pdo, $uid);

        portal_api_token_delete($this->pdo, $uid);

        $this->assertNull(portal_api_token_user($this->pdo, $token, '203.0.113.7'));
        $this->assertFalse(portal_api_token_get($this->pdo, $uid)['has']);
    }

    /** portal_api_token_get: token 복호화와 created_at 조회 */
    public function test_get(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        $token = portal_api_token_ensure($this->pdo, $uid);

        $got = portal_api_token_get($this->pdo, $uid);

        $this->assertTrue($got['has']);
        $this->assertSame($token, $got['token']);
        $this->assertNotNull($got['created_at']);
        $this->assertNull($got['rotated_at']);
        $this->assertNull($got['last_used_at']);
        $this->assertNull($got['last_used_ip']);
    }

    /** portal_api_token_get: 토큰 없음 → has false, token '' */
    public function test_get_토큰_없음(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();

        $got = portal_api_token_get($this->pdo, $uid);

        $this->assertSame(['has' => false, 'token' => '', 'created_at' => null, 'rotated_at' => null, 'last_used_at' => null, 'last_used_ip' => null], $got);
    }

    /** portal_api_token_user: last_used_at·last_used_ip 는 60초 간격으로만 갱신 */
    public function test_user_last_used_60초_간격(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        $token = portal_api_token_ensure($this->pdo, $uid);

        portal_api_token_user($this->pdo, $token, '203.0.113.7');
        $first = portal_api_token_get($this->pdo, $uid);
        $this->assertNotNull($first['last_used_at']);
        $this->assertSame('203.0.113.7', $first['last_used_ip']);

        $before30 = date('Y-m-d H:i:s', time() - 30);
        $this->pdo->prepare("UPDATE portal_api_token SET last_used_at = ? WHERE user_id = {$uid}")->execute([$before30]);
        portal_api_token_user($this->pdo, $token, '198.51.100.1');
        $this->assertSame($before30, portal_api_token_get($this->pdo, $uid)['last_used_at']);
        $this->assertSame('203.0.113.7', portal_api_token_get($this->pdo, $uid)['last_used_ip']);

        $before90 = date('Y-m-d H:i:s', time() - 90);
        $this->pdo->prepare("UPDATE portal_api_token SET last_used_at = ? WHERE user_id = {$uid}")->execute([$before90]);
        portal_api_token_user($this->pdo, $token, '198.51.100.1');
        $this->assertNotSame($before90, portal_api_token_get($this->pdo, $uid)['last_used_at']);
        $this->assertSame('198.51.100.1', portal_api_token_get($this->pdo, $uid)['last_used_ip']);
    }

    /** portal_users 삭제: portal_api_token 행도 삭제(CASCADE) */
    public function test_사용자_삭제_cascade(): void
    {
        $this->pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('홍길동', 'a@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$this->pdo->lastInsertId();
        portal_api_token_ensure($this->pdo, $uid);

        $this->pdo->exec("DELETE FROM portal_users WHERE id = {$uid}");

        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_api_token WHERE user_id = {$uid}")->fetchColumn());
    }
}

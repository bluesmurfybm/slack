<?php

namespace Core\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api_token.php';

/**
 * core/auth.php 는 include 하는 순간 세션 여부가 정해지므로 테스트마다 따로 프로세스를 띄운다.
 */
final class auth_bearer_test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // portal_users 에 쓰고 지우므로 운영 DB 를 가리키는 config.php 에서는 돌지 않는다
        $cfg = require __DIR__ . '/../../config.php';
        if (($cfg['db']['driver'] ?? 'mysql') !== 'sqlite' && !str_ends_with($cfg['db']['name'], '_test')) {
            self::markTestSkipped('config.php 의 DB 가 시험용(sqlite 또는 *_test)이 아닙니다');
        }
        // 처음 연결할 때 남기는 로그가 자식 프로세스의 출력으로 잡히지 않도록 부모에서 먼저 연결한다
        portal_db();
    }

    protected function setUp(): void
    {
        portal_db()->exec("DELETE FROM portal_users WHERE email LIKE '%@token.test'");
    }

    /** current_portal_user: HTTPS + 맞는 Bearer → 토큰 주인, 세션 없음, last_used_ip 기록 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_bearer_로그인(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);
        $_SERVER['HTTP_HOST'] = 'iworks.example.com';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        require __DIR__ . '/../auth.php';

        $this->assertSame('김토큰', current_portal_user()['name']);
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertTrue(portal_auth_via_token());
        $this->assertSame('203.0.113.7', portal_api_token_get($pdo, $uid)['last_used_ip']);
    }

    /** current_portal_user: REDIRECT_HTTP_AUTHORIZATION 만 있어도 인정 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_redirect_http_authorization(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);
        $_SERVER['HTTP_HOST'] = 'iworks.example.com';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        require __DIR__ . '/../auth.php';

        $this->assertSame('김토큰', current_portal_user()['name']);
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    /** current_portal_user: http(HTTPS 아님) → null, 세션 없음 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_http_거절(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);
        $_SERVER['HTTP_HOST'] = 'iworks.example.com';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        require __DIR__ . '/../auth.php';

        $this->assertNull(current_portal_user());
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertNull(portal_api_token_get($pdo, $uid)['last_used_at']);
    }

    /** current_portal_user: HTTP_HOST localhost:8080 은 http 도 인정 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_localhost_http(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        require __DIR__ . '/../auth.php';

        $this->assertSame('김토큰', current_portal_user()['name']);
    }

    /** portal_request_is_https: HTTPS·X-Forwarded-Proto·HTTP_HOST 값별 판정 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_portal_request_is_https(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer iwk_test';
        require __DIR__ . '/../auth.php';

        $_SERVER['HTTP_HOST'] = 'iworks.example.com';
        $this->assertFalse(portal_request_is_https());
        $_SERVER['HTTPS'] = 'off';
        $this->assertFalse(portal_request_is_https());
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(portal_request_is_https());
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(portal_request_is_https());
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertFalse(portal_request_is_https());
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

        foreach (['localhost', 'LOCALHOST:8080', '127.0.0.1:18080', '[::1]', '[::1]:8080', 'iworks.localhost'] as $host) {
            $_SERVER['HTTP_HOST'] = $host;
            $this->assertTrue(portal_request_is_https(), $host);
        }
        $_SERVER['HTTP_HOST'] = 'localhost.example.com';
        $this->assertFalse(portal_request_is_https());
    }

    /** current_portal_user: 틀린 토큰 → null, 세션 없음 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_틀린_토큰(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$pdo->lastInsertId();
        portal_api_token_ensure($pdo, $uid);
        $_SERVER['HTTP_HOST'] = 'iworks.example.com';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer iwk_' . str_repeat('0', 64);

        require __DIR__ . '/../auth.php';

        $this->assertNull(current_portal_user());
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertTrue(portal_auth_via_token());
    }

    /** current_portal_user: Bearer + BLUEIWORK_SESSID 쿠키 → 토큰만 봄 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_bearer_우선(): void
    {
        $_SERVER['HTTP_HOST'] = 'iworks.example.com';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
        $_COOKIE['BLUEIWORK_SESSID'] = 'testsessionid';

        require __DIR__ . '/../auth.php';

        $this->assertNull(current_portal_user());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    /** current_portal_user: Bearer 없음 → 세션 시작 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_bearer_없음(): void
    {
        $_SERVER['HTTP_HOST'] = 'iworks.example.com';
        $_SERVER['HTTPS'] = 'on';

        require __DIR__ . '/../auth.php';

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertFalse(portal_auth_via_token());
        $this->assertNull(current_portal_user());
    }
}

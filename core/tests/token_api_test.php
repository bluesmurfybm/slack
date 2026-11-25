<?php

namespace Core\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api_token.php';

final class token_api_test extends TestCase
{
    // 127.0.0.1 이라 http 로도 토큰이 통한다
    private const BASE = 'http://127.0.0.1:18081/';

    /** @var resource|null */
    private static $server = null;

    public static function setUpBeforeClass(): void
    {
        // portal_users 에 쓰고 지우므로 운영 DB 를 가리키는 config.php 에서는 돌지 않는다
        $cfg = require __DIR__ . '/../../config.php';
        if (($cfg['db']['driver'] ?? 'mysql') !== 'sqlite' && !str_ends_with($cfg['db']['name'], '_test')) {
            self::markTestSkipped('config.php 의 DB 가 시험용(sqlite 또는 *_test)이 아닙니다');
        }
        portal_db();

        $root = dirname(__DIR__, 2);
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18081', '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', 18081);
            if ($sock) { fclose($sock); return; }
            usleep(100000);
        }
        self::fail('PHP 내장 서버가 뜨지 않았습니다');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    protected function setUp(): void
    {
        portal_db()->exec("DELETE FROM portal_users WHERE email LIKE '%@token.test'");
    }

    /**
     * @param string $method
     * @param string $path
     * @param string[] $headers
     * @param array|null $body
     * @return array [HTTP 상태, JSON 본문, 응답 헤더]
     */
    private function http($method, $path, $headers = [], $body = null)
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
            'content' => $body === null ? '' : json_encode($body),
            'ignore_errors' => true,
        ]]);
        $raw = file_get_contents(self::BASE . $path, false, $ctx);
        preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0], $m);
        return [(int)$m[1], json_decode($raw, true), $http_response_header];
    }

    /** api/token.php: Bearer 로 GET·POST rotate·DELETE → 403, 토큰 그대로 */
    public function test_token_bearer_403(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);

        [$get] = $this->http('GET', 'api/token.php', ['Authorization: Bearer ' . $token]);
        [$rotate, $body] = $this->http('POST', 'api/token.php', ['Authorization: Bearer ' . $token], ['action' => 'rotate']);
        [$delete] = $this->http('DELETE', 'api/token.php', ['Authorization: Bearer ' . $token]);

        $this->assertSame([403, 403, 403], [$get, $rotate, $delete]);
        $this->assertSame('이 작업은 브라우저 로그인으로만 할 수 있습니다', $body['error']);
        $this->assertSame($token, portal_api_token_get($pdo, $uid)['token']);
    }

    /** api/token.php: 로그인·Bearer 없음 → 401 */
    public function test_token_로그인_없음(): void
    {
        [$status] = $this->http('GET', 'api/token.php');

        $this->assertSame(401, $status);
    }

    /** api/me.php: Bearer GET → 200, name, has_api_token true */
    public function test_me_bearer_get(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $token = portal_api_token_ensure($pdo, (int)$pdo->lastInsertId());

        [$status, $body] = $this->http('GET', 'api/me.php', ['Authorization: Bearer ' . $token]);

        $this->assertSame(200, $status);
        $this->assertSame('김토큰', $body['name']);
        $this->assertTrue($body['has_api_token']);
    }

    /** api/me.php: 틀린 Bearer GET → 401, Set-Cookie 없음 */
    public function test_me_틀린_bearer(): void
    {
        [$status, , $headers] = $this->http('GET', 'api/me.php', ['Authorization: Bearer iwk_' . str_repeat('0', 64)]);

        $this->assertSame(401, $status);
        $this->assertSame([], preg_grep('~^Set-Cookie:~i', $headers));
    }

    /** api/me.php: Bearer PUT password → 403, pw_hash 그대로 */
    public function test_me_bearer_put_403(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);

        [$status] = $this->http('PUT', 'api/me.php', ['Authorization: Bearer ' . $token], ['password' => 'x']);

        $this->assertSame(403, $status);
        $this->assertSame('x', $pdo->query("SELECT pw_hash FROM portal_users WHERE id = {$uid}")->fetchColumn());
    }

    /** api/token.php: 세션 로그인 → GET has false, ensure 같은 값, rotate 새 값, DELETE 뒤 has false */
    public function test_token_세션(): void
    {
        $pdo = portal_db();
        $pdo->prepare("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김세션', 's@token.test', ?, '2026-11-25 09:00:00')")
            ->execute([password_hash('pw1234', PASSWORD_DEFAULT)]);
        [$login, , $headers] = $this->http('POST', 'api/login.php', [], ['email' => 's@token.test', 'password' => 'pw1234']);
        $this->assertSame(200, $login);
        preg_match('~^Set-Cookie:\s*(BLUEIWORK_SESSID=[^;]+)~mi', implode("\n", $headers), $m);
        $cookie = 'Cookie: ' . $m[1];

        [$status, $empty, $getHeaders] = $this->http('GET', 'api/token.php', [$cookie]);
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'has' => false, 'token' => '', 'created_at' => null, 'rotated_at' => null, 'last_used_at' => null, 'last_used_ip' => null], $empty);
        $this->assertContains('Cache-Control: no-store', $getHeaders);

        [, $first] = $this->http('POST', 'api/token.php', [$cookie], ['action' => 'ensure']);
        [, $again] = $this->http('POST', 'api/token.php', [$cookie], ['action' => 'ensure']);
        $this->assertMatchesRegularExpression('/^iwk_[0-9a-f]{64}$/', $first['token']);
        $this->assertSame($first['token'], $again['token']);

        [, $got] = $this->http('GET', 'api/token.php', [$cookie]);
        $this->assertTrue($got['has']);
        $this->assertSame($first['token'], $got['token']);

        [, $rotated] = $this->http('POST', 'api/token.php', [$cookie], ['action' => 'rotate']);
        $this->assertTrue($rotated['ok']);
        $this->assertNotSame($first['token'], $rotated['token']);
        [$old] = $this->http('GET', 'api/me.php', ['Authorization: Bearer ' . $first['token']]);
        $this->assertSame(401, $old);

        [$deleted, $deleteBody] = $this->http('DELETE', 'api/token.php', [$cookie]);
        $this->assertSame([200, ['ok' => true]], [$deleted, $deleteBody]);
        [, $after] = $this->http('GET', 'api/token.php', [$cookie]);
        $this->assertFalse($after['has']);
    }

    /** api/me.php: 세션 PUT password → 토큰 폐기 */
    public function test_me_비밀번호_변경_토큰_폐기(): void
    {
        $pdo = portal_db();
        $pdo->prepare("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김세션', 's@token.test', ?, '2026-11-25 09:00:00')")
            ->execute([password_hash('pw1234', PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);
        [, , $headers] = $this->http('POST', 'api/login.php', [], ['email' => 's@token.test', 'password' => 'pw1234']);
        preg_match('~^Set-Cookie:\s*(BLUEIWORK_SESSID=[^;]+)~mi', implode("\n", $headers), $m);

        [$status] = $this->http('PUT', 'api/me.php', ['Cookie: ' . $m[1]], ['password' => 'new5678']);
        [$old] = $this->http('GET', 'api/me.php', ['Authorization: Bearer ' . $token]);

        $this->assertSame([200, 401], [$status, $old]);
        $this->assertFalse(portal_api_token_get($pdo, $uid)['has']);
    }

    /** api/me.php: 세션 PUT name → 토큰 유지 */
    public function test_me_이름_변경_토큰_유지(): void
    {
        $pdo = portal_db();
        $pdo->prepare("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김세션', 's@token.test', ?, '2026-11-25 09:00:00')")
            ->execute([password_hash('pw1234', PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
        $token = portal_api_token_ensure($pdo, $uid);
        [, , $headers] = $this->http('POST', 'api/login.php', [], ['email' => 's@token.test', 'password' => 'pw1234']);
        preg_match('~^Set-Cookie:\s*(BLUEIWORK_SESSID=[^;]+)~mi', implode("\n", $headers), $m);

        [$status] = $this->http('PUT', 'api/me.php', ['Cookie: ' . $m[1]], ['name' => '김바뀜']);

        $this->assertSame(200, $status);
        $this->assertSame($token, portal_api_token_get($pdo, $uid)['token']);
    }

    /** api/admins.php: Bearer POST → 403 */
    public function test_admins_bearer_403(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김토큰', 't@token.test', 'x', '2026-11-25 09:00:00')");
        $token = portal_api_token_ensure($pdo, (int)$pdo->lastInsertId());

        [$status, $body] = $this->http('POST', 'api/admins.php', ['Authorization: Bearer ' . $token], ['email' => 'x@token.test']);

        $this->assertSame(403, $status);
        $this->assertSame('이 작업은 브라우저 로그인으로만 할 수 있습니다', $body['error']);
    }

    /** api/token.php: POST action 이 ensure·rotate 가 아니면 400 */
    public function test_token_알_수_없는_action(): void
    {
        $pdo = portal_db();
        $pdo->prepare("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김세션', 's@token.test', ?, '2026-11-25 09:00:00')")
            ->execute([password_hash('pw1234', PASSWORD_DEFAULT)]);
        [, , $headers] = $this->http('POST', 'api/login.php', [], ['email' => 's@token.test', 'password' => 'pw1234']);
        preg_match('~^Set-Cookie:\s*(BLUEIWORK_SESSID=[^;]+)~mi', implode("\n", $headers), $m);

        [$status, $body] = $this->http('POST', 'api/token.php', ['Cookie: ' . $m[1]], ['action' => 'show']);
        [$missing] = $this->http('POST', 'api/token.php', ['Cookie: ' . $m[1]], []);

        $this->assertSame([400, 400], [$status, $missing]);
        $this->assertSame(['ok' => false, 'error' => '알 수 없는 action'], $body);
    }
}

<?php

namespace Core\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api_token.php';

final class apidocs_http_test extends TestCase
{
    // 127.0.0.1 이라 http 로도 토큰이 통한다
    private const BASE = 'http://127.0.0.1:18082/';

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
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18082', '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', 18082);
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
        portal_db()->exec("DELETE FROM portal_users WHERE email LIKE '%@apidocs.test'");
    }

    /**
     * @param string $method
     * @param string $path
     * @param string[] $headers
     * @param array|null $body
     * @return array [HTTP 상태, 응답 본문, 응답 헤더]
     */
    private function http($method, $path, $headers = [], $body = null)
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
            'content' => $body === null ? '' : json_encode($body),
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = file_get_contents(self::BASE . $path, false, $ctx);
        preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0], $m);
        return [(int)$m[1], $raw, $http_response_header];
    }

    /** apidocs/spec.php: 로그인·Bearer 없음 → 401 */
    public function test_spec_로그인_없음(): void
    {
        [$status, $raw] = $this->http('GET', 'apidocs/spec.php');

        $this->assertSame(401, $status);
        $this->assertSame(['error' => '로그인이 필요합니다'], json_decode($raw, true));
    }

    /** apidocs/spec.php: 틀린 Bearer → 401 */
    public function test_spec_틀린_bearer(): void
    {
        [$status] = $this->http('GET', 'apidocs/spec.php', ['Authorization: Bearer iwk_' . str_repeat('0', 64)]);

        $this->assertSame(401, $status);
    }

    /** apidocs/openapi.php: 로그인 없이 직접 GET → 본문 비어 있음 */
    public function test_openapi_직접_요청(): void
    {
        [, $raw] = $this->http('GET', 'apidocs/openapi.php');

        $this->assertSame('', $raw);
    }

    /** apidocs/spec.php: Bearer → 200, application/json, no-store, openapi.php 명세 그대로 */
    public function test_spec_bearer(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김문서', 't@apidocs.test', 'x', '2026-11-25 09:00:00')");
        $token = portal_api_token_ensure($pdo, (int)$pdo->lastInsertId());

        [$status, $raw, $headers] = $this->http('GET', 'apidocs/spec.php', ['Authorization: Bearer ' . $token]);

        $this->assertSame(200, $status);
        $this->assertContains('Content-Type: application/json; charset=utf-8', $headers);
        $this->assertContains('Cache-Control: no-store', $headers);
        $this->assertSame(require dirname(__DIR__, 2) . '/apidocs/openapi.php', $raw);
    }

    /** apidocs/spec.php: 세션 로그인 → 200 */
    public function test_spec_세션(): void
    {
        $pdo = portal_db();
        $pdo->prepare("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김세션', 's@apidocs.test', ?, '2026-11-25 09:00:00')")
            ->execute([password_hash('pw1234', PASSWORD_DEFAULT)]);
        [, , $headers] = $this->http('POST', 'api/login.php', [], ['email' => 's@apidocs.test', 'password' => 'pw1234']);
        preg_match('~^Set-Cookie:\s*(BLUEIWORK_SESSID=[^;]+)~mi', implode("\n", $headers), $m);

        [$status, $raw] = $this->http('GET', 'apidocs/spec.php', ['Cookie: ' . $m[1]]);

        $this->assertSame(200, $status);
        $this->assertSame('3.0.3', json_decode($raw, true)['openapi']);
    }

    /** apidocs/index.php: 로그인 없음 → 302 ../index.php?need_login=apidocs */
    public function test_index_로그인_없음(): void
    {
        [$status, , $headers] = $this->http('GET', 'apidocs/index.php');

        $this->assertSame(302, $status);
        $this->assertContains('Location: ../index.php?need_login=apidocs', $headers);
    }

    /** apidocs/index.php: Bearer → 200, swagger-ui 5.32.15, url spec.php, supportedSubmitMethods get */
    public function test_index_bearer(): void
    {
        $pdo = portal_db();
        $pdo->exec("INSERT INTO portal_users (name, email, pw_hash, created_at) VALUES ('김문서', 't@apidocs.test', 'x', '2026-11-25 09:00:00')");
        $token = portal_api_token_ensure($pdo, (int)$pdo->lastInsertId());

        [$status, $raw] = $this->http('GET', 'apidocs/index.php', ['Authorization: Bearer ' . $token]);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.32.15/swagger-ui-bundle.min.js', $raw);
        $this->assertStringContainsString('url: "spec.php"', $raw);
        $this->assertStringContainsString('supportedSubmitMethods: ["get"]', $raw);
    }
}

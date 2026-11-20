<?php

namespace Schooltodo\Tests;

use Schooltodo\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';

final class version_test extends TestCase
{
    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES
            (1, '가대', '4.5', 1), (2, '나대', '3.9', 1), (3, '다대', '4.5', 1), (4, '라대', '3.10', 1), (5, '마대', '3.9', 0)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, updated_by, updated_at) VALUES
            (10, 1, '운영반영', '사용자', '2026-11-20 10:00:00'),
            (10, 2, '진행중',  '봇',     '2026-11-19 09:00:00'),
            (10, 5, '운영반영', '사용자', '2026-11-18 09:00:00')");
    }

    private function call(array $ctx, array $query = [], int $id = 10): array
    {
        return schooltodo_handle(
            $ctx + ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => null],
            ['method' => 'GET', 'seg' => ['sheets', (string)$id, 'versions'], 'body' => [], 'query' => $query]
        );
    }

    /** schooltodo_sheet_by_version: 버전 숫자 순으로 묶고 상태·갱신 날짜를 준다 */
    public function test_버전별_묶음(): void
    {
        $this->seed();
        $res = $this->call(['token_ok' => true]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['3.9', '3.10', '4.5'], array_column($res['data']['versions'], 'ver'));
        $this->assertSame(4, $res['data']['total']);   // 미사용(마대) 제외

        $v45 = $res['data']['versions'][2];
        $this->assertSame(2, $v45['total']);
        $this->assertSame(1, $v45['counts']['운영반영']);
        $this->assertSame(1, $v45['counts']['미조치']);
        $this->assertSame(['school_id' => 1, 'name' => '가대', 'code' => '', 'status' => '운영반영',
                           'updated_at' => '2026-11-20 10:00:00', 'updated_by' => '사용자'], $v45['schools'][0]);
        $this->assertSame(['미조치', ''], [$v45['schools'][1]['status'], $v45['schools'][1]['updated_at']]);
    }

    /** ?ver, ?status 로 거른다. 개수는 거르기 전 기준 */
    public function test_거르기(): void
    {
        $this->seed();
        $res = $this->call(['token_ok' => true], ['ver' => '4.5', 'status' => '미조치']);

        $this->assertSame(['4.5'], array_column($res['data']['versions'], 'ver'));
        $this->assertSame(['다대'], array_column($res['data']['versions'][0]['schools'], 'name'));
        $this->assertSame(2, $res['data']['versions'][0]['total']);
    }

    /** 로그인 세션으로도 부를 수 있다 */
    public function test_세션으로_호출(): void
    {
        $this->seed();
        $res = $this->call(['identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']]);
        $this->assertSame(200, $res['status']);
    }

    /** 토큰도 세션도 없으면 401 */
    public function test_인증_없으면_401(): void
    {
        $this->seed();
        $this->assertSame(401, $this->call([])['status']);
    }

    /** 토큰은 이 경로에서만 통한다 — 시트 목록·쓰기는 여전히 401 */
    public function test_토큰은_다른_경로에_안_통함(): void
    {
        $this->seed();
        $ctx = ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => null, 'token_ok' => true];

        $list = schooltodo_handle($ctx, ['method' => 'GET', 'seg' => ['sheets'], 'body' => [], 'query' => []]);
        $put = schooltodo_handle($ctx, ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'],
                                        'body' => ['items' => [['school_id' => 1, 'status' => '미조치']]], 'query' => []]);
        $del = schooltodo_handle($ctx, ['method' => 'DELETE', 'seg' => ['sheets', '10'], 'body' => [], 'query' => []]);

        $this->assertSame([401, 401, 401], [$list['status'], $put['status'], $del['status']]);
    }

    /** 없는 시트는 404, 잘못된 상태는 422 */
    public function test_오류(): void
    {
        $this->seed();
        $this->assertSame(404, $this->call(['token_ok' => true], [], 99)['status']);
        $this->assertSame(422, $this->call(['token_ok' => true], ['status' => '끝'])['status']);
    }

    /** schooltodo_bearer_ok: 토큰 일치·HTTPS 여부 */
    public function test_bearer_검사(): void
    {
        $keep = $_SERVER;
        try {
            $_SERVER['HTTP_HOST'] = 'iworks.example.com';
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
            $this->assertTrue(schooltodo_bearer_ok('abc123'));
            $this->assertFalse(schooltodo_bearer_ok('other'));
            $this->assertFalse(schooltodo_bearer_ok(''));          // 설정 안 하면 꺼짐

            unset($_SERVER['HTTPS']);
            $this->assertFalse(schooltodo_bearer_ok('abc123'));    // http 는 거절

            $_SERVER['HTTP_HOST'] = 'localhost:8080';
            $this->assertTrue(schooltodo_bearer_ok('abc123'));     // 개발용 localhost 는 허용
        } finally {
            $_SERVER = $keep;
        }
    }
}

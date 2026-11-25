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
        $res = $this->call(['identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['3.9', '3.10', '4.5'], array_column($res['data']['versions'], 'ver'));
        $this->assertSame(4, $res['data']['total']);   // 미사용(마대) 제외

        $v45 = $res['data']['versions'][2];
        $this->assertSame(2, $v45['total']);
        $this->assertSame(['todo' => 1, 'in_progress' => 0, 'dev_applied' => 0, 'prod_applied' => 1, 'not_applicable' => 0],
                          $v45['counts']);
        $this->assertSame(['school_id' => 1, 'name' => '가대', 'code' => '', 'status' => 'prod_applied', 'status_label' => '운영반영',
                           'updated_at' => '2026-11-20 10:00:00', 'updated_by' => '사용자'], $v45['schools'][0]);
        $this->assertSame(['todo', ''], [$v45['schools'][1]['status'], $v45['schools'][1]['updated_at']]);
        $this->assertSame(['todo', 'in_progress', 'dev_applied', 'prod_applied', 'not_applicable'],
                          array_keys($res['data']['counts']));
    }

    /** ?ver, ?status 로 거른다(영문 코드·한글 둘 다). 개수는 거르기 전 기준 */
    public function test_거르기(): void
    {
        $this->seed();
        foreach (['todo', '미조치'] as $status) {
            $res = $this->call(['identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']], ['ver' => '4.5', 'status' => $status]);

            $this->assertSame(['4.5'], array_column($res['data']['versions'], 'ver'));
            $this->assertSame(['다대'], array_column($res['data']['versions'][0]['schools'], 'name'));
            $this->assertSame(2, $res['data']['versions'][0]['total']);
        }
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

    /** schooltodo_route: identity 없으면 token_ok 가 있어도 401 */
    public function test_identity_없으면_401(): void
    {
        $this->seed();
        $this->assertSame(401, $this->call(['token_ok' => true])['status']);
    }

    /** 없는 시트는 404, 잘못된 상태는 422 */
    public function test_오류(): void
    {
        $this->seed();
        $identity = ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => ''];
        $this->assertSame(404, $this->call(['identity' => $identity], [], 99)['status']);
        $this->assertSame(422, $this->call(['identity' => $identity], ['status' => '끝'])['status']);
    }
}

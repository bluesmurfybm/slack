<?php

namespace Schooltodo\Tests;

use Schooltodo\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';

final class status_test extends TestCase
{
    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES
            (1, '가대', '4.5', 1), (2, '나대', '3.9', 1), (3, '나대', '4.5', 1), (4, '마대', '3.9', 0)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, note, updated_by, updated_at) VALUES
            (10, 1, '진행중', '작업중', '사용자', '2026-11-20 10:00:00')");
    }

    private function put(array $ctx, array $body, int $id = 10): array
    {
        return schooltodo_handle(
            $ctx + ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => null],
            ['method' => 'PUT', 'seg' => ['sheets', (string)$id, 'status'], 'body' => $body, 'query' => []]
        );
    }

    private function cell(int $schoolId): array|false
    {
        $st = $this->pdo->prepare("SELECT status, note, updated_by FROM schooltodo_cells WHERE sheet_id = 10 AND school_id = ?");
        $st->execute([$schoolId]);
        return $st->fetch();
    }

    /** schooltodo_status_change: 영문 코드·한글 status 변경, 응답은 영문 코드, updated_by 는 identity name */
    public function test_상태_변경(): void
    {
        $this->seed();
        $res = $this->put(['identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자2', 'color' => '']], ['items' => [
            ['school_id' => 1, 'status' => 'prod_applied', 'note' => '반영 끝'],
            ['school' => '나대', 'ver' => '3.9', 'status' => '해당없음'],
        ]]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['data']['updated']);
        $byId = array_column($res['data']['schools'], null, 'school_id');
        $this->assertSame(['prod_applied', '운영반영', '반영 끝', '사용자2'],
            [$byId[1]['status'], $byId[1]['status_label'], $byId[1]['note'], $byId[1]['updated_by']]);
        $this->assertSame('not_applicable', $byId[2]['status']);

        $this->assertSame(['status' => '운영반영', 'note' => '반영 끝', 'updated_by' => '사용자2'], $this->cell(1));
        $this->assertSame(['status' => '해당없음', 'note' => '', 'updated_by' => '사용자2'], $this->cell(2));

        // 바뀌기 전 값은 이력으로 남는다
        $history = $this->pdo->query("SELECT status, note FROM schooltodo_cell_history WHERE sheet_id = 10 AND school_id = 1")->fetchAll();
        $this->assertSame([['status' => '진행중', 'note' => '작업중']], $history);
    }

    /** schooltodo_status_change: note 를 안 보내면 기존 note 유지 */
    public function test_메모_유지(): void
    {
        $this->seed();
        $res = $this->put(['identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자2', 'color' => '']],
                          ['items' => [['school_id' => 1, 'status' => 'dev_applied']]]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['status' => '개발반영', 'note' => '작업중', 'updated_by' => '사용자2'], $this->cell(1));
    }

    /** 로그인 세션으로 부르면 by 를 무시하고 로그인 이름으로 남긴다 */
    public function test_세션으로_호출(): void
    {
        $this->seed();
        $res = $this->put(['identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자2', 'color' => '']],
                          ['by' => '가짜', 'items' => [['school_id' => 1, 'status' => 'todo']]]);

        $this->assertSame(200, $res['status']);
        $this->assertSame('사용자2', $this->cell(1)['updated_by']);
    }

    /** 토큰도 세션도 없으면 401 */
    public function test_인증_없으면_401(): void
    {
        $this->seed();
        $body = ['items' => [['school_id' => 1, 'status' => 'todo']]];

        $this->assertSame(401, $this->put([], $body)['status']);
        $this->assertSame('진행중', $this->cell(1)['status']);
    }

    /** schooltodo_route: identity 없으면 token_ok 가 있어도 401, status 그대로 */
    public function test_identity_없으면_401(): void
    {
        $this->seed();

        $res = $this->put(['token_ok' => true], ['by' => 'safepush', 'items' => [['school_id' => 1, 'status' => 'todo']]]);

        $this->assertSame(401, $res['status']);
        $this->assertSame('진행중', $this->cell(1)['status']);
    }

    /** 오류 — 한 건이라도 틀리면 아무것도 저장하지 않는다 */
    public function test_오류(): void
    {
        $this->seed();
        $ctx = ['identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']];

        $this->assertSame(422, $this->put($ctx, [])['status']);
        $this->assertSame(404, $this->put($ctx, ['items' => [['school_id' => 1, 'status' => 'todo']]], 99)['status']);

        $bad = $this->put($ctx, ['items' => [['school_id' => 2, 'status' => 'todo'], ['school_id' => 1, 'status' => 'done']]]);
        $this->assertSame(422, $bad['status']);
        $this->assertStringStartsWith('items[1]:', $bad['data']['detail']);

        $noStatus = $this->put($ctx, ['items' => [['school_id' => 1, 'note' => '메모만']]]);
        $this->assertSame(422, $noStatus['status']);

        $dup = $this->put($ctx, ['items' => [['school_id' => 2, 'status' => 'todo'], ['school' => '나대', 'status' => 'todo']]]);
        $this->assertSame(409, $dup['status']);
        $this->assertFalse($this->cell(2));    // 앞 건도 롤백

        $this->assertSame(404, $this->put($ctx, ['items' => [['school_id' => 4, 'status' => 'todo']]])['status']);  // 미사용 대학
    }
}

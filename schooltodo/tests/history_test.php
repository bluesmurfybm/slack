<?php

namespace Schooltodo\Tests;

use Schooltodo\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';

final class history_test extends TestCase
{
    /** schooltodo_cell_save: 바뀌기 전 status, note, updated_by, updated_at 을 이력에 저장 */
    public function test_바뀌기_전_값_저장()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, note, updated_by, updated_at)
                          VALUES (10, 1, '개발반영', '개발서버 반영', '이전사람', '2026-11-01 09:00:00')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영', 'note' => '운영 반영'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('사용자', $res['data']['rows'][0]['updated_by']);
        $history = $this->pdo->query("SELECT sheet_id, school_id, status, note, updated_by, updated_at FROM schooltodo_cell_history")->fetchAll();
        $this->assertEquals([
            ['sheet_id' => 10, 'school_id' => 1, 'status' => '개발반영', 'note' => '개발서버 반영', 'updated_by' => '이전사람', 'updated_at' => '2026-11-01 09:00:00'],
        ], $history);
    }

    /** schooltodo_cell_save: schooltodo_cells 행이 없던 대학은 이력 없음 */
    public function test_새_셀은_이력_없음()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_cell_history")->fetchColumn());
    }

    /** schooltodo_cell_save: status, note 가 그대로면 이력 없이 updated_by, updated_at 유지 */
    public function test_값이_같으면_이력_없음()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, note, updated_by, updated_at)
                          VALUES (10, 1, '운영반영', '운영 반영', '이전사람', '2026-11-01 09:00:00')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영', 'note' => '운영 반영'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('이전사람', $res['data']['rows'][0]['updated_by']);
        $this->assertSame('2026-11-01 09:00:00', $res['data']['rows'][0]['updated_at']);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_cell_history")->fetchColumn());
    }

    /** schooltodo_history_push: 셀마다 최근 SCHOOLTODO_HISTORY_MAX 건만 남김 */
    public function test_최근_10건만_남김()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1), (2, '나대', '3.9', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, note) VALUES (10, 1, '진행중', '0')");
        $this->pdo->exec("INSERT INTO schooltodo_cell_history (sheet_id, school_id, status, note) VALUES (10, 2, '진행중', '나대 이력')");

        for ($i = 1; $i <= 12; $i++) {
            $res = schooltodo_handle(
                ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
                ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                    ['school_id' => 1, 'note' => "{$i}"],
                ]], 'query' => []]
            );
            $this->assertSame(200, $res['status']);
        }

        $notes = $this->pdo->query("SELECT note FROM schooltodo_cell_history WHERE school_id = 1 ORDER BY id DESC")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['11', '10', '9', '8', '7', '6', '5', '4', '3', '2'], $notes);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_cell_history WHERE school_id = 2")->fetchColumn());
    }

    /** schooltodo_cells_fill: items 중 한 건이 오류면 앞선 건의 이력도 저장하지 않음 */
    public function test_한_건이_오류면_이력도_되돌림()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, '개발반영')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영'],
                ['school_id' => 99, 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_cell_history")->fetchColumn());
    }

    /** schooltodo_sheet_delete: 그 sheet_id 의 이력 삭제 */
    public function test_시트_삭제하면_이력_삭제()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery'), (11, '관리자 동시접속')");
        $this->pdo->exec("INSERT INTO schooltodo_cell_history (sheet_id, school_id, status) VALUES (10, 1, '진행중'), (11, 1, '진행중')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'DELETE', 'seg' => ['sheets', '10'], 'body' => [], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(['11'], array_map('strval', $this->pdo->query("SELECT sheet_id FROM schooltodo_cell_history")->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** schooltodo_cell_history: 그 sheet_id, school_id 의 이력을 최근 것부터 조회 */
    public function test_이력_조회()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1), (2, '나대', '3.9', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery'), (11, '관리자 동시접속')");
        $this->pdo->exec("INSERT INTO schooltodo_cell_history (id, sheet_id, school_id, status, note, updated_by, updated_at) VALUES
            (1, 10, 1, '미조치', '', '', ''),
            (2, 10, 1, '진행중', '확인 중', '가사람', '2026-11-02 10:00:00'),
            (3, 10, 2, '진행중', '나대', '나사람', '2026-11-02 11:00:00'),
            (4, 11, 1, '진행중', '다른 시트', '다사람', '2026-11-02 12:00:00')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets', '10', 'cells', '1', 'history'], 'body' => [], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['data']['school_id']);
        $this->assertSame([
            ['status' => '진행중', 'note' => '확인 중', 'updated_by' => '가사람', 'updated_at' => '2026-11-02 10:00:00'],
            ['status' => '미조치', 'note' => '', 'updated_by' => '', 'updated_at' => ''],
        ], $res['data']['history']);
    }

    /** schooltodo_cell_history: 없는 sheet id 오류 */
    public function test_이력_없는_시트는_404()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets', '10', 'cells', '1', 'history'], 'body' => [], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
    }

    /** schooltodo_cell_history: 없는 school_id 오류 */
    public function test_이력_없는_대학은_404()
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets', '10', 'cells', '99', 'history'], 'body' => [], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
    }
}

<?php

namespace Schooltodo\Tests;

use Schooltodo\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';

final class cell_test extends TestCase
{
    /** schooltodo_cells_fill: school_id 로 status, note, updated_by 저장 */
    public function test_school_id_로_저장(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1), (2, '나대', '3.9', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 2, 'status' => '운영반영', 'note' => '3.5.1 반영'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['data']['updated']);
        $this->assertSame(2, $res['data']['rows'][0]['school_id']);
        $this->assertSame('운영반영', $res['data']['rows'][0]['status']);
        $this->assertSame('3.5.1 반영', $res['data']['rows'][0]['note']);
        $this->assertSame('봇', $res['data']['rows'][0]['updated_by']);
        $this->assertNotSame('', $res['data']['rows'][0]['updated_at']);
    }

    /** schooltodo_cells_fill: 여러 items 한 번에 저장 */
    public function test_여러_건_저장(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1), (2, '나대', '3.9', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '개발반영'],
                ['school_id' => 2, 'status' => '해당없음'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['data']['updated']);
        $this->assertSame(['개발반영', '해당없음'], array_column($res['data']['rows'], 'status'));
    }

    /** schooltodo_school_resolve: school 이름으로 대학 조회 */
    public function test_school_이름으로_저장(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1), (2, '나대', '3.9', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school' => '나대', 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['data']['rows'][0]['school_id']);
    }

    /** schooltodo_school_resolve: name 이 같은 대학을 school + ver 로 조회 */
    public function test_school_과_ver_로_저장(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1), (2, '가대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school' => '가대', 'ver' => '4.5', 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['data']['rows'][0]['school_id']);
    }

    /** schooltodo_school_resolve: name 이 같은 대학이 여럿인데 ver 없는 요청 오류 */
    public function test_school_이름이_여럿이면_409(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1), (2, '가대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school' => '가대', 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(409, $res['status']);
        $this->assertStringContainsString('3.5, 4.5', $res['data']['detail']);
    }

    /** schooltodo_school_resolve: 없는 school 이름 오류 */
    public function test_school_이름이_없으면_404(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school' => '없는대', 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
    }

    /** schooltodo_school_resolve: 없는 school_id 오류 */
    public function test_school_id_가_없으면_404(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 99, 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
    }

    /** schooltodo_school_resolve: active=0 대학 오류 */
    public function test_active_0_대학은_404(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 0)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $byId = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영'],
            ]], 'query' => []]
        );
        $byName = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school' => '가대', 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(404, $byId['status']);
        $this->assertSame(404, $byName['status']);
    }

    /** schooltodo_school_resolve: school_id 도 school 도 없는 요청 오류 */
    public function test_대학_지정이_없으면_422(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_cell_save: status 만 보내면 note 유지 */
    public function test_status_만_보내면_note_유지(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, note) VALUES (10, 1, '개발반영', '담당자 확인 중')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('운영반영', $res['data']['rows'][0]['status']);
        $this->assertSame('담당자 확인 중', $res['data']['rows'][0]['note']);
        $this->assertSame('사용자', $res['data']['rows'][0]['updated_by']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_cells")->fetchColumn());
    }

    /** schooltodo_cell_save: note 만 보내면 status 유지 */
    public function test_note_만_보내면_status_유지(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, note) VALUES (10, 1, '개발반영', '')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'note' => '금요일 배포'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('개발반영', $res['data']['rows'][0]['status']);
        $this->assertSame('금요일 배포', $res['data']['rows'][0]['note']);
    }

    /** schooltodo_cell_save: 셀 없는 대학에 note 만 보내면 status 미조치 */
    public function test_새_셀에_note_만_보내면_미조치(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'note' => '금요일 배포'],
            ]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('미조치', $res['data']['rows'][0]['status']);
        $this->assertSame('금요일 배포', $res['data']['rows'][0]['note']);
    }

    /** schooltodo_cell_save: status 도 note 도 없는 요청 오류 */
    public function test_status_와_note_가_없으면_422(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1],
            ]], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_cell_save: 목록에 없는 status 오류 */
    public function test_status_가_잘못되면_422(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => 'done'],
            ]], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_cells_fill: items 없는 요청 오류 */
    public function test_items_가_없으면_422(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => [], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_cells_fill: items 중 한 건이 오류면 앞선 건도 저장하지 않고 몇 번째인지 알림 */
    public function test_한_건이_오류면_전부_되돌림(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영'],
                ['school_id' => 99, 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
        $this->assertStringStartsWith('items[1]:', $res['data']['detail']);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_cells")->fetchColumn());
    }

    /** schooltodo_cells_fill: 없는 sheet id 오류 */
    public function test_없는_시트는_404(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'PUT', 'seg' => ['sheets', '999', 'cells'], 'body' => ['items' => [
                ['school_id' => 1, 'status' => '운영반영'],
            ]], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
    }
}

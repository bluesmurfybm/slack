<?php

namespace Schooltodo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Schooltodo\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';

final class sheet_test extends TestCase
{
    /** schooltodo_route_sheets: identity 없는 요청 오류 */
    public function test_identity_없으면_401(): void
    {
        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => null],
            ['method' => 'GET', 'seg' => ['sheets'], 'body' => [], 'query' => []]
        );

        $this->assertSame(401, $res['status']);
    }

    /** schooltodo_sheets_all: active=1 대학 기준 counts 조회 */
    public function test_목록_counts(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES
            (1, '가대', '3.5', 1), (2, '나대', '3.9', 1), (3, '다대', '4.5', 1), (4, '라대', '4.5', 0)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery'), (11, '빈 시트')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES
            (10, 1, '완료'), (10, 2, '해당없음'), (10, 4, '완료')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'GET', 'seg' => ['sheets'], 'body' => [], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(['jQuery', '빈 시트'], array_column($res['data'], 'title'));
        $this->assertSame(['미조치' => 1, '진행중' => 0, '완료' => 1, '해당없음' => 1], $res['data'][0]['counts']);
        $this->assertSame(3, $res['data'][0]['total']);
        $this->assertSame(['미조치' => 3, '진행중' => 0, '완료' => 0, '해당없음' => 0], $res['data'][1]['counts']);
    }

    /** schooltodo_sheet_create: title, description, created_by 저장 */
    public function test_등록(): void
    {
        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'POST', 'seg' => ['sheets'], 'body' => ['title' => ' jQuery 3.5.1 ', 'description' => '전 대학 반영'], 'query' => []]
        );

        $this->assertSame(201, $res['status']);
        $this->assertSame('jQuery 3.5.1', $res['data']['title']);
        $this->assertSame('전 대학 반영', $res['data']['description']);
        $this->assertSame('사용자', $res['data']['created_by']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_sheets WHERE title = 'jQuery 3.5.1'")->fetchColumn());
    }

    /** schooltodo_sheet_create: title 없는 등록 오류 */
    public function test_등록_title_없으면_422(): void
    {
        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'POST', 'seg' => ['sheets'], 'body' => ['description' => '설명만'], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_sheet_create: 이미 있는 title 등록 오류 */
    public function test_등록_title_중복이면_409(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'POST', 'seg' => ['sheets'], 'body' => ['title' => 'jQuery'], 'query' => []]
        );

        $this->assertSame(409, $res['status']);
    }

    /** schooltodo_sheet_detail: active=1 대학 rows 를 name 순으로 조회, 셀 없는 대학은 status 미조치 */
    public function test_상세_rows(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES
            (1, '나대', '3.9', 1), (2, '가대', '3.5', 1), (3, '다대', '4.5', 0)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status, note, updated_by, updated_at) VALUES
            (10, 1, '완료', '3.5.1 반영', '봇', '2026-11-17 10:00:00')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'GET', 'seg' => ['sheets', '10'], 'body' => [], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame([
            ['school_id' => 2, 'name' => '가대', 'ver' => '3.5', 'status' => '미조치', 'note' => '', 'updated_by' => '', 'updated_at' => ''],
            ['school_id' => 1, 'name' => '나대', 'ver' => '3.9', 'status' => '완료', 'note' => '3.5.1 반영', 'updated_by' => '봇', 'updated_at' => '2026-11-17 10:00:00'],
        ], $res['data']['rows']);
        $this->assertSame(['미조치' => 1, '진행중' => 0, '완료' => 1, '해당없음' => 0], $res['data']['counts']);
    }

    /** @return array[] */
    public static function statuses(): array
    {
        return [
            '미조치' => ['미조치', [1]],
            '진행중' => ['진행중', [2]],
            '완료' => ['완료', [3]],
            '해당없음' => ['해당없음', [4]],
        ];
    }

    /** schooltodo_sheet_detail: status 조건별 rows 조회 */
    #[DataProvider('statuses')]
    public function test_상세_status_필터($status, $schoolIds): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES
            (1, '가대', '3.5', 1), (2, '나대', '3.5', 1), (3, '다대', '3.5', 1), (4, '라대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES
            (10, 2, '진행중'), (10, 3, '완료'), (10, 4, '해당없음')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'GET', 'seg' => ['sheets', '10'], 'body' => [], 'query' => ['status' => $status]]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame($schoolIds, array_column($res['data']['rows'], 'school_id'));
        $this->assertSame(4, $res['data']['total']);
    }

    /** schooltodo_sheet_detail: 목록에 없는 status 조건 오류 */
    public function test_상세_status_잘못되면_422(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'GET', 'seg' => ['sheets', '10'], 'body' => [], 'query' => ['status' => '보류']]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_sheet_detail: 없는 id 오류 */
    public function test_상세_없는_id_는_404(): void
    {
        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'GET', 'seg' => ['sheets', '999'], 'body' => [], 'query' => []]
        );

        $this->assertSame(404, $res['status']);
    }

    /** schooltodo_sheet_update: title 만 보내면 description 유지 */
    public function test_수정_title(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, description) VALUES (10, 'jQuery', '설명')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['title' => 'jQuery 3.5.1'], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('jQuery 3.5.1', $res['data']['title']);
        $this->assertSame('설명', $res['data']['description']);
    }

    /** schooltodo_sheet_update: description 만 보내면 title 유지 */
    public function test_수정_description(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, description) VALUES (10, 'jQuery', '설명')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['description' => '바뀐 설명'], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('jQuery', $res['data']['title']);
        $this->assertSame('바뀐 설명', $res['data']['description']);
    }

    /** schooltodo_sheet_update: 빈 title 수정 오류 */
    public function test_수정_title_비면_422(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['title' => '  '], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_sheet_update: 다른 시트의 title 로 수정 오류, 자기 title 그대로는 통과 */
    public function test_수정_title_중복이면_409(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery'), (11, '관리자 동시접속')");

        $other = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['title' => '관리자 동시접속'], 'query' => []]
        );
        $same = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['title' => 'jQuery'], 'query' => []]
        );

        $this->assertSame(409, $other['status']);
        $this->assertSame(200, $same['status']);
    }

    /** schooltodo_sheet_delete: 시트와 그 sheet_id 의 셀 삭제 */
    public function test_삭제(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '3.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery'), (11, '관리자 동시접속')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, '완료'), (11, 1, '완료')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '', 'bot' => false]],
            ['method' => 'DELETE', 'seg' => ['sheets', '10'], 'body' => [], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(['11'], array_map('strval', $this->pdo->query("SELECT id FROM schooltodo_sheets")->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame(['11'], array_map('strval', $this->pdo->query("SELECT sheet_id FROM schooltodo_cells")->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** schooltodo_sheet_delete: bot=true identity 삭제 오류 */
    public function test_삭제_bot_은_403(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => '', 'name' => '봇', 'color' => '', 'bot' => true]],
            ['method' => 'DELETE', 'seg' => ['sheets', '10'], 'body' => [], 'query' => []]
        );

        $this->assertSame(403, $res['status']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_sheets")->fetchColumn());
    }
}

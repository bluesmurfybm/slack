<?php

namespace Schooltodo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Schooltodo\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';

final class stage_test extends TestCase
{
    /** @return array[] */
    public static function envs(): array
    {
        return [
            'dev 만' => ['https://dev.example.kr', '', '', ['미조치', '진행중', '개발반영', '해당없음'], '개발반영'],
            'ops 만' => ['', 'https://lms.example.kr', '', ['미조치', '진행중', '운영반영', '해당없음'], '운영반영'],
            'ops 와 test_srv' => ['', 'https://lms.example.kr', 'enc:v1:x', ['미조치', '진행중', '개발반영', '운영반영', '해당없음'], '운영반영'],
            'dev 와 ops' => ['https://dev.example.kr', 'https://lms.example.kr', '', ['미조치', '진행중', '개발반영', '운영반영', '해당없음'], '운영반영'],
        ];
    }

    /** schooltodo_sheet_detail: schools.dev·ops, school_access.test_srv 조건별 rows.stages, 마지막 단계에서 done 조회 */
    #[DataProvider('envs')]
    public function test_상세_대학별_단계($dev, $ops, $testSrv, $stages, $last): void
    {
        $this->pdo->prepare("INSERT INTO schools (id, name, ver, dev, ops, active) VALUES (1, '가대', '4.5', ?, ?, 1), (2, '나대', '4.5', ?, ?, 1)")
            ->execute([$dev, $ops, $dev, $ops]);
        $this->pdo->prepare("INSERT INTO school_access (school_id, test_srv) VALUES (1, ?), (2, ?)")->execute([$testSrv, $testSrv]);
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->prepare("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, ?), (10, 2, '진행중')")->execute([$last]);

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets', '10'], 'body' => [], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame($stages, $res['data']['rows'][0]['stages']);
        $this->assertSame([true, false], array_column($res['data']['rows'], 'done'));
        $this->assertSame(1, $res['data']['done']);
        $this->assertSame(2, $res['data']['target']);
    }

    /** schooltodo_sheet_detail: 마지막 단계보다 뒤인 status 도 done, 해당없음은 done 아님·target 에서 빠짐 */
    public function test_상세_done_뒤_단계와_해당없음(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, dev, ops, active) VALUES
            (1, '가대', '4.5', 'https://dev.example.kr', '', 1), (2, '나대', '4.5', '', '', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, '운영반영'), (10, 2, '해당없음')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets', '10'], 'body' => [], 'query' => []]
        );

        $this->assertSame([true, false], array_column($res['data']['rows'], 'done'));
        $this->assertSame(1, $res['data']['done']);
        $this->assertSame(1, $res['data']['target']);
    }

    /** schooltodo_sheets_all: 시트마다 stages, done, target 조회 */
    public function test_목록_done(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, dev, ops, active) VALUES
            (1, '가대', '4.5', 'https://dev.example.kr', '', 1), (2, '나대', '4.5', 'https://dev.example.kr', 'https://lms.example.kr', 1),
            (3, '다대', '4.5', '', '', 1), (4, '라대', '4.5', '', '', 0)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, 'jQuery', ''), (11, '검수', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수완료\"]')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES
            (10, 1, '개발반영'), (10, 2, '개발반영'), (10, 3, '해당없음'), (10, 4, '운영반영'), (11, 2, '운영반영'), (11, 3, '검수완료')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets'], 'body' => [], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(['미조치', '진행중', '개발반영', '운영반영', '해당없음'], $res['data'][0]['stages']);
        $this->assertSame([1, 2], [$res['data'][0]['done'], $res['data'][0]['target']]);
        $this->assertSame(['미조치', '진행중', '개발반영', '운영반영', '검수완료', '해당없음'], $res['data'][1]['stages']);
        $this->assertSame(['미조치' => 1, '진행중' => 0, '개발반영' => 0, '운영반영' => 1, '검수완료' => 1, '해당없음' => 0], $res['data'][1]['counts']);
        $this->assertSame([1, 3], [$res['data'][1]['done'], $res['data'][1]['target']]);
    }

    /** schooltodo_cells_fill: 그 대학 stages 에 없는 status 오류 */
    public function test_저장_고를_수_없는_status_는_422(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, dev, ops, active) VALUES (1, '가대', '4.5', 'https://dev.example.kr', '', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [['school_id' => 1, 'status' => '운영반영']]], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM schooltodo_cells")->fetchColumn());
    }

    /** schooltodo_cells_fill: 추가 단계 status 저장, 응답 sheet 에 done·target */
    public function test_저장_추가_단계(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '4.5', 1), (2, '나대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, '검수', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수완료\"]')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'cells'], 'body' => ['items' => [['school_id' => 1, 'status' => '검수완료']]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('검수완료', $res['data']['rows'][0]['status']);
        $this->assertSame([1, 2], [$res['data']['sheet']['done'], $res['data']['sheet']['target']]);
    }

    /** schooltodo_sheet_update: stages 저장 */
    public function test_수정_stages(): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['stages' => ['검토', '진행중', '개발반영', '운영반영', '검수완료']], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(['미조치', '검토', '진행중', '개발반영', '운영반영', '검수완료', '해당없음'], $res['data']['stages']);
        $this->assertSame('["검토","진행중","개발반영","운영반영","검수완료"]', $this->pdo->query("SELECT stages FROM schooltodo_sheets WHERE id = 10")->fetchColumn());
    }

    /** schooltodo_sheet_update: renames 로 단계 이름을 바꾸면 cells·cell_history status 도 바뀜 */
    public function test_수정_renames(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, '검수', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수\"]'), (11, '다른 시트', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수\"]')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, '검수'), (11, 1, '검수')");
        $this->pdo->exec("INSERT INTO schooltodo_cell_history (sheet_id, school_id, status) VALUES (10, 1, '검수')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['stages' => ['진행중', '개발반영', '운영반영', '검수완료'], 'renames' => ['검수' => '검수완료']], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(['검수완료', '검수'], $this->pdo->query("SELECT status FROM schooltodo_cells ORDER BY sheet_id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('검수완료', $this->pdo->query("SELECT status FROM schooltodo_cell_history")->fetchColumn());
    }

    /** schooltodo_sheet_update: 대학이 있는 단계를 빼면 409, stages 그대로 */
    public function test_수정_쓰는_단계_빼면_409(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, '검수', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수\"]')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, '검수')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['title' => '새 이름', 'stages' => ['진행중', '개발반영', '운영반영']], 'query' => []]
        );

        $this->assertSame(409, $res['status']);
        $this->assertSame(['검수', '["진행중","개발반영","운영반영","검수"]'],
            $this->pdo->query("SELECT title, stages FROM schooltodo_sheets WHERE id = 10")->fetch(\PDO::FETCH_NUM));
    }

    /** @return array[] */
    public static function badStages(): array
    {
        return [
            '목록 아님' => [['a' => '진행중']],
            '빈 이름' => [['진행중', '', '개발반영', '운영반영']],
            '20자 넘음' => [['진행중', '개발반영', '운영반영', str_repeat('가', 21)]],
            '겹침' => [['진행중', '검수', '개발반영', '운영반영', '검수']],
            '미조치' => [['미조치', '진행중', '개발반영', '운영반영']],
            '해당없음' => [['진행중', '개발반영', '운영반영', '해당없음']],
            '기본 단계 빠짐' => [['진행중', '운영반영']],
            '기본 단계 순서 바뀜' => [['개발반영', '진행중', '운영반영']],
        ];
    }

    /** schooltodo_sheet_update: 잘못된 stages 오류 */
    #[DataProvider('badStages')]
    public function test_수정_stages_잘못되면_422($stages): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title) VALUES (10, 'jQuery')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['stages' => $stages], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** @return array[] */
    public static function badRenames(): array
    {
        return [
            '기본 단계' => [['개발반영' => '개발완료']],
            '없는 단계' => [['보류' => '검수']],
            '새 이름이 stages 에 없음' => [['검수' => '검수완료']],
        ];
    }

    /** schooltodo_sheet_update: 잘못된 renames 오류 */
    #[DataProvider('badRenames')]
    public function test_수정_renames_잘못되면_422($renames): void
    {
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, '검수', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수\"]')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10'], 'body' => ['stages' => ['진행중', '개발반영', '운영반영', '검수'], 'renames' => $renames], 'query' => []]
        );

        $this->assertSame(422, $res['status']);
    }

    /** schooltodo_sheet_create: stages 와 함께 만들기, stages 없으면 기본 단계 */
    public function test_만들기_stages(): void
    {
        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'POST', 'seg' => ['sheets'], 'body' => ['title' => '검수', 'stages' => ['진행중', '개발반영', '운영반영', '검수완료']], 'query' => []]
        );
        $plain = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'POST', 'seg' => ['sheets'], 'body' => ['title' => 'jQuery'], 'query' => []]
        );

        $this->assertSame(201, $res['status']);
        $this->assertSame(['미조치', '진행중', '개발반영', '운영반영', '검수완료', '해당없음'], $res['data']['stages']);
        $this->assertSame(['미조치', '진행중', '개발반영', '운영반영', '해당없음'], $plain['data']['stages']);
    }

    /** schooltodo_sheet_by_version: 추가 단계는 이름 그대로 status·counts 키 */
    public function test_버전별_추가_단계(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, '검수', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수완료\"]')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, '검수완료')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets', '10', 'versions'], 'body' => [], 'query' => ['status' => '검수완료']]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame(['todo' => 0, 'in_progress' => 0, 'dev_applied' => 0, 'prod_applied' => 0, '검수완료' => 1, 'not_applicable' => 0], $res['data']['counts']);
        $this->assertSame('검수완료', $res['data']['versions'][0]['schools'][0]['status']);
    }

    /** schooltodo_status_change: 추가 단계 이름으로 status 변경 */
    public function test_상태_변경_추가_단계(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, '검수', '[\"진행중\",\"개발반영\",\"운영반영\",\"검수완료\"]')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'PUT', 'seg' => ['sheets', '10', 'status'], 'body' => ['items' => [['school_id' => 1, 'status' => '검수완료']]], 'query' => []]
        );

        $this->assertSame(200, $res['status']);
        $this->assertSame('검수완료', $this->pdo->query("SELECT status FROM schooltodo_cells")->fetchColumn());
    }

    /** schooltodo_sheet_detail: sort=status 는 시트 stages 순 */
    public function test_상세_sort_status_추가_단계(): void
    {
        $this->pdo->exec("INSERT INTO schools (id, name, ver, active) VALUES (1, '가대', '4.5', 1), (2, '나대', '4.5', 1), (3, '다대', '4.5', 1)");
        $this->pdo->exec("INSERT INTO schooltodo_sheets (id, title, stages) VALUES (10, '검수', '[\"검토\",\"진행중\",\"개발반영\",\"운영반영\"]')");
        $this->pdo->exec("INSERT INTO schooltodo_cells (sheet_id, school_id, status) VALUES (10, 1, '진행중'), (10, 2, '검토'), (10, 3, '미조치')");

        $res = schooltodo_handle(
            ['config' => $this->config, 'pdo' => $this->pdo, 'identity' => ['email' => 'user1@bluesoft.co.kr', 'name' => '사용자', 'color' => '']],
            ['method' => 'GET', 'seg' => ['sheets', '10'], 'body' => [], 'query' => ['sort' => 'status']]
        );

        $this->assertSame([3, 2, 1], array_column($res['data']['rows'], 'school_id'));
    }
}

<?php

namespace Bluecluster\Tests;

use Bluecluster\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';
require_once __DIR__ . '/../lib/files.php';
require_once __DIR__ . '/../lib/pages.php';

final class page_test extends TestCase
{
    /** @var string 테스트마다 새로 만드는 files_dir */
    private $filesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesDir = sys_get_temp_dir() . '/bcl_page_test_' . bin2hex(random_bytes(4));
        mkdir($this->filesDir . '/job-1', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->filesDir . '/job-1/*') ?: [] as $file) unlink($file);
        @rmdir($this->filesDir . '/job-1');
        @rmdir($this->filesDir);
        @unlink(sys_get_temp_dir() . '/bcl_page_outside.txt');
        parent::tearDown();
    }

    /** bcl_page_submit: title 없으면 오류, 작업 만들지 않음 */
    public function test_submit_title_없음()
    {
        $result = bcl_page_submit($this->pdo, 'a@bluesoft.co.kr', ['title' => '  ', 'repos' => "repo-a\n", 'instructions' => '지시']);

        $this->assertNull($result['job_key']);
        $this->assertSame(['제목을 입력하세요.'], $result['errors']);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM bcl_jobs")->fetchColumn());
    }

    /** bcl_page_submit: repos 가 빈 줄뿐이면 오류 */
    public function test_submit_repos_비어있음()
    {
        $result = bcl_page_submit($this->pdo, 'a@bluesoft.co.kr', ['title' => '제목', 'repos' => " \r\n\n  ", 'instructions' => '지시']);

        $this->assertNull($result['job_key']);
        $this->assertSame(['저장소를 한 줄에 하나씩 입력하세요.'], $result['errors']);
    }

    /** bcl_page_submit: instructions 없으면 오류 */
    public function test_submit_instructions_없음()
    {
        $result = bcl_page_submit($this->pdo, 'a@bluesoft.co.kr', ['title' => '제목', 'repos' => 'repo-a', 'instructions' => '']);

        $this->assertNull($result['job_key']);
        $this->assertSame(['지시를 입력하세요.'], $result['errors']);
    }

    /** bcl_page_submit: 모두 없으면 오류 3개 */
    public function test_submit_입력_없음()
    {
        $result = bcl_page_submit($this->pdo, 'a@bluesoft.co.kr', []);

        $this->assertNull($result['job_key']);
        $this->assertCount(3, $result['errors']);
    }

    /** bcl_page_submit: 정상이면 repos 를 줄 단위로 나눠 공백·빈 줄 없이 submitter 와 함께 저장 */
    public function test_submit_정상()
    {
        $result = bcl_page_submit($this->pdo, 'ljy@bluesoft.co.kr', [
            'title' => ' 출석부 수정 ',
            'repos' => "lms-a\r\n\n  lms-b  \n",
            'instructions' => '결석 표시를 고친다',
        ]);

        $this->assertSame([], $result['errors']);
        $job = bcl_job_get($this->pdo, $result['job_key']);
        $this->assertSame('출석부 수정', $job['title']);
        $this->assertSame('ljy@bluesoft.co.kr', $job['submitter']);
        $this->assertSame(['lms-a', 'lms-b'], $job['repos']);
        $this->assertSame('결석 표시를 고친다', $job['instructions']);
    }

    /** bcl_page_jobs: state open 은 open 작업만 */
    public function test_jobs_open_탭()
    {
        $open = bcl_job_create($this->pdo, '열림', 'a@bluesoft.co.kr', ['r'], '지시');
        $done = bcl_job_create($this->pdo, '끝남', 'a@bluesoft.co.kr', ['r'], '지시');
        $this->pdo->prepare("UPDATE bcl_jobs SET state = 'done', finished_at = 1700000500 WHERE job_key = ?")->execute([$done]);

        $jobs = bcl_page_jobs($this->pdo, 'open');

        $this->assertSame([$open], array_column($jobs, 'job_id'));
    }

    /** bcl_page_jobs: state done 은 done 작업만, created_at·finished_at 포함 */
    public function test_jobs_done_탭()
    {
        bcl_job_create($this->pdo, '열림', 'a@bluesoft.co.kr', ['r'], '지시');
        $done = bcl_job_create($this->pdo, '끝남', 'a@bluesoft.co.kr', ['r'], '지시');
        $this->pdo->prepare("UPDATE bcl_jobs SET state = 'done', finished_at = 1700000500 WHERE job_key = ?")->execute([$done]);

        $jobs = bcl_page_jobs($this->pdo, 'done');

        $this->assertSame([$done], array_column($jobs, 'job_id'));
        $this->assertSame(1700000500, $jobs[0]['finished_at']);
        $this->assertGreaterThan(0, $jobs[0]['created_at']);
    }

    /** bcl_page_tab: open|done 만 받고 나머지는 open */
    public function test_tab_값()
    {
        $this->assertSame('open', bcl_page_tab('open'));
        $this->assertSame('done', bcl_page_tab('done'));
        $this->assertSame('open', bcl_page_tab('all'));
        $this->assertSame('open', bcl_page_tab(null));
    }

    /** bcl_page_job: 시도에 attempt_id·token_user·내려받을 수 있는 files 포함 */
    public function test_job_시도_정보()
    {
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['r'], '지시');
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = '{$key}'");
        $result = $this->filesDir . '/job-1/p1-1.result.txt';
        file_put_contents($result, '결과');
        $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, token_user, status, started, result_path, patch_path)
            SELECT id, 1, 'pc-1', 'acc', 'owner@bluesoft.co.kr', 'done', 1700000000, ?, ? FROM bcl_pieces WHERE piece_key = 'p1'")
            ->execute([$result, $this->filesDir . '/job-1/없는파일.patch']);

        $job = bcl_page_job($this->pdo, $key, $this->filesDir);

        $attempt = $job['pieces'][0]['attempts'][0];
        $this->assertSame('owner@bluesoft.co.kr', $attempt['token_user']);
        $this->assertGreaterThan(0, $attempt['attempt_id']);
        $this->assertSame(['result'], $attempt['files']);
        $this->assertGreaterThan(0, $job['created_at']);
    }

    /** bcl_page_job: 시도 여러 건의 files 가 시도마다 존재하는 files_dir 안 파일만 */
    public function test_job_시도_여러_건_files()
    {
        $dir = $this->filesDir . '/job-1';
        $outside = sys_get_temp_dir() . '/bcl_page_outside.txt';
        file_put_contents($outside, '비밀');
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['r'], '지시');
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = '{$key}'");
        $insert = $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, result_path, patch_path, log_path)
            SELECT id, ?, 'pc-1', 'acc', 'done', 1700000000, ?, ?, ? FROM bcl_pieces WHERE piece_key = 'p1'");
        file_put_contents("{$dir}/p1-1.result.txt", 'x');
        file_put_contents("{$dir}/p1-1.patch", 'x');
        file_put_contents("{$dir}/p1-2.log.json", 'x');
        $insert->execute([1, "{$dir}/p1-1.result.txt", "{$dir}/p1-1.patch", null]);
        $insert->execute([2, null, $outside, "{$dir}/p1-2.log.json"]);
        $insert->execute([3, "{$dir}/../../bcl_page_outside.txt", "{$dir}/없음.patch", null]);

        $job = bcl_page_job($this->pdo, $key, $this->filesDir);

        $attempts = $job['pieces'][0]['attempts'];
        $this->assertSame(['result', 'patch'], $attempts[0]['files']);
        $this->assertSame(['log'], $attempts[1]['files']);
        $this->assertSame([], $attempts[2]['files']);
    }

    /** bcl_page_job_merge: extra·times 에 시도·작업이 없어도 빈 값으로 만들어짐 */
    public function test_job_merge_extra_없음()
    {
        $job = [
            'job_id' => 'job-9',
            'pieces' => [['piece_id' => 'p1', 'attempts' => [['attempt_no' => 1]]]],
        ];

        $merged = bcl_page_job_merge($job, [], [], $this->filesDir);

        $attempt = $merged['pieces'][0]['attempts'][0];
        $this->assertNull($attempt['attempt_id']);
        $this->assertSame('', $attempt['token_user']);
        $this->assertSame([], $attempt['files']);
        $this->assertNull($merged['created_at']);
        $this->assertNull($merged['finished_at']);
    }

    /** bcl_page_job: 없는 job_key 는 null */
    public function test_job_없는_키()
    {
        $this->assertNull(bcl_page_job($this->pdo, 'job-999999', $this->filesDir));
    }

    /** bcl_page_redactions: null, 빈 객체, 빈 배열은 가림 없음 / 종류별 횟수 */
    public function test_redactions_세_모양()
    {
        $this->assertSame('가림 없음', bcl_page_redactions(null));
        $this->assertSame('가림 없음', bcl_page_redactions(new \stdClass()));
        $this->assertSame('가림 없음', bcl_page_redactions([]));
        $this->assertSame('secret_assign 2, email 1', bcl_page_redactions(['secret_assign' => 2, 'email' => 1]));
    }

    /** bcl_page_status_label: 시도 상태 한글 */
    public function test_status_label()
    {
        $this->assertSame('실행 중', bcl_page_status_label('running'));
        $this->assertSame('완료', bcl_page_status_label('done'));
        $this->assertSame('주인 중지', bcl_page_status_label('stopped_by_owner'));
        $this->assertSame('유실', bcl_page_status_label('lost'));
        $this->assertSame('other', bcl_page_status_label('other'));
    }

    /** bcl_page_time: unix 를 Asia/Seoul Y-m-d H:i:s 로, null 은 - */
    public function test_time()
    {
        $this->assertSame('2023-11-15 07:13:20', bcl_page_time(1700000000));
        $this->assertSame('-', bcl_page_time(null));
    }

    /** bcl_page_download_path: files_dir 안의 result·patch·log 경로를 돌려줌 */
    public function test_download_kind_세_가지()
    {
        $dir = $this->filesDir . '/job-1';
        $this->pdo->exec("INSERT INTO bcl_jobs (job_key, title, submitter, repos, instructions, created_at)
            VALUES ('job-1', '제목', 'a@bluesoft.co.kr', '[\"r\"]', '지시', 1700000000)");
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = 'job-1'");
        $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, result_path, patch_path, log_path)
            SELECT id, 1, 'pc-1', 'acc', 'done', 1700000000, ?, ?, ? FROM bcl_pieces WHERE piece_key = 'p1'")
            ->execute(["{$dir}/p1-1.result.txt", "{$dir}/p1-1.patch", "{$dir}/p1-1.log.json"]);
        $attemptId = (int)$this->pdo->query("SELECT id FROM bcl_attempts")->fetchColumn();
        foreach (['p1-1.result.txt', 'p1-1.patch', 'p1-1.log.json'] as $name) file_put_contents("{$dir}/{$name}", 'x');

        $this->assertSame(realpath("{$dir}/p1-1.result.txt"), bcl_page_download_path($this->pdo, $attemptId, 'result', $this->filesDir));
        $this->assertSame(realpath("{$dir}/p1-1.patch"), bcl_page_download_path($this->pdo, $attemptId, 'patch', $this->filesDir));
        $this->assertSame(realpath("{$dir}/p1-1.log.json"), bcl_page_download_path($this->pdo, $attemptId, 'log', $this->filesDir));
    }

    /** bcl_page_download_path: 알 수 없는 kind 는 null */
    public function test_download_알_수_없는_kind()
    {
        $dir = $this->filesDir . '/job-1';
        $this->pdo->exec("INSERT INTO bcl_jobs (job_key, title, submitter, repos, instructions, created_at)
            VALUES ('job-1', '제목', 'a@bluesoft.co.kr', '[\"r\"]', '지시', 1700000000)");
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = 'job-1'");
        $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, result_path)
            SELECT id, 1, 'pc-1', 'acc', 'done', 1700000000, ? FROM bcl_pieces WHERE piece_key = 'p1'")
            ->execute(["{$dir}/p1-1.result.txt"]);
        $attemptId = (int)$this->pdo->query("SELECT id FROM bcl_attempts")->fetchColumn();
        file_put_contents("{$dir}/p1-1.result.txt", 'x');

        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, 'result_path', $this->filesDir));
        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, '', $this->filesDir));
    }

    /** bcl_page_download_path: 없는 attempt 와 저장 경로 없음은 null */
    public function test_download_없는_시도_없는_경로()
    {
        $dir = $this->filesDir . '/job-1';
        $this->pdo->exec("INSERT INTO bcl_jobs (job_key, title, submitter, repos, instructions, created_at)
            VALUES ('job-1', '제목', 'a@bluesoft.co.kr', '[\"r\"]', '지시', 1700000000)");
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = 'job-1'");
        $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, result_path)
            SELECT id, 1, 'pc-1', 'acc', 'done', 1700000000, ? FROM bcl_pieces WHERE piece_key = 'p1'")
            ->execute(["{$dir}/p1-1.result.txt"]);
        $attemptId = (int)$this->pdo->query("SELECT id FROM bcl_attempts")->fetchColumn();
        file_put_contents("{$dir}/p1-1.result.txt", 'x');

        $this->assertNull(bcl_page_download_path($this->pdo, 999999, 'result', $this->filesDir));
        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, 'patch', $this->filesDir));
    }

    /** bcl_page_download_path: 파일이 없으면 null */
    public function test_download_파일_없음()
    {
        $dir = $this->filesDir . '/job-1';
        $this->pdo->exec("INSERT INTO bcl_jobs (job_key, title, submitter, repos, instructions, created_at)
            VALUES ('job-1', '제목', 'a@bluesoft.co.kr', '[\"r\"]', '지시', 1700000000)");
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = 'job-1'");
        $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, result_path)
            SELECT id, 1, 'pc-1', 'acc', 'done', 1700000000, ? FROM bcl_pieces WHERE piece_key = 'p1'")
            ->execute(["{$dir}/없음.txt"]);
        $attemptId = (int)$this->pdo->query("SELECT id FROM bcl_attempts")->fetchColumn();

        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, 'result', $this->filesDir));
    }

    /** bcl_page_download_path: files_dir 밖을 가리키는 ../ 경로와 절대 경로는 null */
    public function testDownloadRejectsPathOutsideFilesDir()
    {
        $dir = $this->filesDir . '/job-1';
        $outside = sys_get_temp_dir() . '/bcl_page_outside.txt';
        file_put_contents($outside, '비밀');
        $this->pdo->exec("INSERT INTO bcl_jobs (job_key, title, submitter, repos, instructions, created_at)
            VALUES ('job-1', '제목', 'a@bluesoft.co.kr', '[\"r\"]', '지시', 1700000000)");
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = 'job-1'");
        $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, result_path, patch_path, log_path)
            SELECT id, 1, 'pc-1', 'acc', 'done', 1700000000, ?, ?, ? FROM bcl_pieces WHERE piece_key = 'p1'")
            ->execute(["{$dir}/../../bcl_page_outside.txt", $outside, "{$dir}/../../../../etc/hostname"]);
        $attemptId = (int)$this->pdo->query("SELECT id FROM bcl_attempts")->fetchColumn();

        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, 'result', $this->filesDir));
        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, 'patch', $this->filesDir));
        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, 'log', $this->filesDir));
    }

    /** bcl_page_download_path: files_dir 이름으로 시작하는 형제 폴더는 null */
    public function test_download_형제_폴더()
    {
        $sibling = $this->filesDir . '-evil';
        mkdir($sibling);
        file_put_contents($sibling . '/x.txt', 'x');
        $this->pdo->exec("INSERT INTO bcl_jobs (job_key, title, submitter, repos, instructions, created_at)
            VALUES ('job-1', '제목', 'a@bluesoft.co.kr', '[\"r\"]', '지시', 1700000000)");
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p1', 'r', 'd' FROM bcl_jobs WHERE job_key = 'job-1'");
        $this->pdo->prepare("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, result_path)
            SELECT id, 1, 'pc-1', 'acc', 'done', 1700000000, ? FROM bcl_pieces WHERE piece_key = 'p1'")
            ->execute([$sibling . '/x.txt']);
        $attemptId = (int)$this->pdo->query("SELECT id FROM bcl_attempts")->fetchColumn();

        $this->assertNull(bcl_page_download_path($this->pdo, $attemptId, 'result', $this->filesDir));

        unlink($sibling . '/x.txt');
        rmdir($sibling);
    }

    /** bcl_page_download_name: kind 별 확장자를 붙인 파일 이름 */
    public function test_download_name()
    {
        $this->pdo->exec("INSERT INTO bcl_jobs (job_key, title, submitter, repos, instructions, created_at)
            VALUES ('job-1', '제목', 'a@bluesoft.co.kr', '[\"r\"]', '지시', 1700000000)");
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc) SELECT id, 'p/1 ../x', 'r', 'd' FROM bcl_jobs WHERE job_key = 'job-1'");
        $this->pdo->exec("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started)
            SELECT id, 2, 'pc-1', 'acc', 'done', 1700000000 FROM bcl_pieces");
        $attemptId = (int)$this->pdo->query("SELECT id FROM bcl_attempts")->fetchColumn();

        $this->assertSame('job-1-p_1_.._x-2.result.txt', bcl_page_download_name($this->pdo, $attemptId, 'result'));
        $this->assertSame('job-1-p_1_.._x-2.patch', bcl_page_download_name($this->pdo, $attemptId, 'patch'));
        $this->assertSame('job-1-p_1_.._x-2.log.json', bcl_page_download_name($this->pdo, $attemptId, 'log'));
    }
}

<?php

namespace Bluecluster\Tests;

use Bluecluster\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';

final class repo_test extends TestCase
{
    /** bcl_migrate: 두 번 불러도 오류 없이 테이블 4개 유지 */
    public function test_migrate_두_번()
    {
        bcl_migrate($this->pdo);
        bcl_migrate($this->pdo);

        $tables = $this->pdo->query("SELECT table_name FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name LIKE 'bcl\\_%' ORDER BY table_name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['bcl_attempts', 'bcl_jobs', 'bcl_nodes', 'bcl_pieces'], $tables);
    }

    /** bcl_job_create: job_key 가 job-<id> 이고 두 번째는 다른 키 */
    public function test_job_create_job_key()
    {
        $first = bcl_job_create($this->pdo, '제목1', 'a@bluesoft.co.kr', ['repo-a'], '지시1');
        $second = bcl_job_create($this->pdo, '제목2', 'a@bluesoft.co.kr', ['repo-a'], '지시2');

        $id = (int)$this->pdo->query("SELECT id FROM bcl_jobs WHERE title = '제목1'")->fetchColumn();
        $this->assertSame('job-' . $id, $first);
        $this->assertNotSame($first, $second);
    }

    /** bcl_job_get: 규약 job 모양(title, submitter, repos, instructions, state, leader, epoch, lease_until, summary, pieces) */
    public function test_job_get_모양()
    {
        $key = bcl_job_create($this->pdo, '출석부 수정', 'ljy@bluesoft.co.kr', ['lms-a', 'lms-b'], '결석 표시를 고친다');

        $job = bcl_job_get($this->pdo, $key);

        $this->assertSame([
            'job_id' => $key,
            'title' => '출석부 수정',
            'submitter' => 'ljy@bluesoft.co.kr',
            'repos' => ['lms-a', 'lms-b'],
            'instructions' => '결석 표시를 고친다',
            'state' => 'open',
            'leader' => null,
            'epoch' => 0,
            'lease_until' => 0,
            'summary' => null,
            'pieces' => [],
        ], $job);
    }

    /** bcl_job_get: 없는 job_key 는 null */
    public function test_job_get_없는_키()
    {
        $this->assertNull(bcl_job_get($this->pdo, 'job-999999'));
    }

    /** bcl_job_get: pieces 와 attempts 모양, 끝나기 전 attempt 의 null 값 */
    public function test_job_get_조각과_시도()
    {
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $jobid = (int)$this->pdo->query("SELECT id FROM bcl_jobs WHERE job_key = '{$key}'")->fetchColumn();
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc, state)
            VALUES ({$jobid}, 'p1', 'lms-a', '첫 조각', 'done'), ({$jobid}, 'p2', 'lms-a', '둘째 조각', 'running')");
        $p1 = (int)$this->pdo->query("SELECT id FROM bcl_pieces WHERE piece_key = 'p1'")->fetchColumn();
        $p2 = (int)$this->pdo->query("SELECT id FROM bcl_pieces WHERE piece_key = 'p2'")->fetchColumn();
        $this->pdo->exec("INSERT INTO bcl_attempts
            (piece_id, attempt_no, node_id, account, token_user, status, started, ended, result_path, patch_path, log_path, redactions, closed_by)
            VALUES
            ({$p1}, 1, 'n1', 'acct1', 'a@bluesoft.co.kr', 'lost', 100, 105, NULL, NULL, NULL, NULL, 'n2'),
            ({$p1}, 2, 'n2', 'acct2', 'b@bluesoft.co.kr', 'done', 110, 120, '/f/p1-2.result.txt', '/f/p1-2.patch', '/f/p1-2.log.json', '{\"secret_assign\":2}', NULL),
            ({$p2}, 1, 'n1', 'acct1', 'a@bluesoft.co.kr', 'running', 130, NULL, NULL, NULL, NULL, NULL, NULL)");

        $job = bcl_job_get($this->pdo, $key);

        $this->assertSame([
            [
                'piece_id' => 'p1',
                'repo' => 'lms-a',
                'desc' => '첫 조각',
                'state' => 'done',
                'attempts' => [
                    [
                        'attempt_no' => 1,
                        'node_id' => 'n1',
                        'account' => 'acct1',
                        'status' => 'lost',
                        'started' => 100,
                        'ended' => 105,
                        'result_path' => null,
                        'patch_path' => null,
                        'log_path' => null,
                        'redactions' => null,
                        'closed_by' => 'n2',
                    ],
                    [
                        'attempt_no' => 2,
                        'node_id' => 'n2',
                        'account' => 'acct2',
                        'status' => 'done',
                        'started' => 110,
                        'ended' => 120,
                        'result_path' => '/f/p1-2.result.txt',
                        'patch_path' => '/f/p1-2.patch',
                        'log_path' => '/f/p1-2.log.json',
                        'redactions' => ['secret_assign' => 2],
                        'closed_by' => null,
                    ],
                ],
            ],
            [
                'piece_id' => 'p2',
                'repo' => 'lms-a',
                'desc' => '둘째 조각',
                'state' => 'running',
                'attempts' => [
                    [
                        'attempt_no' => 1,
                        'node_id' => 'n1',
                        'account' => 'acct1',
                        'status' => 'running',
                        'started' => 130,
                        'ended' => null,
                        'result_path' => null,
                        'patch_path' => null,
                        'log_path' => null,
                        'redactions' => null,
                        'closed_by' => null,
                    ],
                ],
            ],
        ], $job['pieces']);
    }

    /** bcl_job_get: attempts.redactions '{}' 는 JSON 으로 다시 내보내도 {} */
    public function test_job_get_redactions_빈_객체()
    {
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $jobid = (int)$this->pdo->query("SELECT id FROM bcl_jobs WHERE job_key = '{$key}'")->fetchColumn();
        $this->pdo->exec("INSERT INTO bcl_pieces (job_id, piece_key, repo, piece_desc, state)
            VALUES ({$jobid}, 'p1', 'lms-a', '조각', 'done')");
        $pid = (int)$this->pdo->query("SELECT id FROM bcl_pieces WHERE piece_key = 'p1'")->fetchColumn();
        $this->pdo->exec("INSERT INTO bcl_attempts (piece_id, attempt_no, node_id, account, status, started, ended, redactions)
            VALUES ({$pid}, 1, 'n1', 'acct1', 'done', 100, 110, '{}')");

        $job = bcl_job_get($this->pdo, $key);

        $this->assertSame('{}', json_encode($job['pieces'][0]['attempts'][0]['redactions']));
    }

    /** bcl_job_create: title 없음 */
    public function test_job_create_title_없음()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('필수 값 없음: title');

        bcl_job_create($this->pdo, '', 'a@bluesoft.co.kr', ['lms-a'], '지시');
    }

    /** bcl_job_create: submitter 없음 */
    public function test_job_create_submitter_없음()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('필수 값 없음: submitter');

        bcl_job_create($this->pdo, '제목', '', ['lms-a'], '지시');
    }

    /** bcl_job_create: repos 없음 */
    public function test_job_create_repos_없음()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('필수 값 없음: repos');

        bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', [], '지시');
    }

    /** bcl_job_create: instructions 없음 */
    public function test_job_create_instructions_없음()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('필수 값 없음: instructions');

        bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['lms-a'], '');
    }

    /** bcl_jobs_open: leader null 인 open 작업 */
    public function test_jobs_open_leader_없음()
    {
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['lms-a'], '지시');

        $jobs = bcl_jobs_open($this->pdo, 1000);

        $this->assertSame([$key], array_column($jobs, 'job_id'));
    }

    /** bcl_jobs_open: leader 있고 lease_until 이 now 보다 큰 작업 제외 */
    public function test_jobs_open_리스_유효()
    {
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $this->pdo->exec("UPDATE bcl_jobs SET leader = 'n1', epoch = 1, lease_until = 1001 WHERE job_key = '{$key}'");

        $this->assertSame([], bcl_jobs_open($this->pdo, 1000));
    }

    /** bcl_jobs_open: leader 있고 lease_until 이 now 와 같으면 리스 끝 */
    public function test_jobs_open_리스_경계()
    {
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $this->pdo->exec("UPDATE bcl_jobs SET leader = 'n1', epoch = 1, lease_until = 1000 WHERE job_key = '{$key}'");

        $jobs = bcl_jobs_open($this->pdo, 1000);

        $this->assertSame([$key], array_column($jobs, 'job_id'));
        $this->assertSame('n1', $jobs[0]['leader']);
        $this->assertSame(1000, $jobs[0]['lease_until']);
    }

    /** bcl_jobs_open: state done 작업 제외 */
    public function test_jobs_open_done()
    {
        $key = bcl_job_create($this->pdo, '제목', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $this->pdo->exec("UPDATE bcl_jobs SET state = 'done', leader = NULL WHERE job_key = '{$key}'");

        $this->assertSame([], bcl_jobs_open($this->pdo, 1000));
    }

    /** bcl_jobs_list: state open */
    public function test_jobs_list_open()
    {
        $open = bcl_job_create($this->pdo, '열린 것', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $done = bcl_job_create($this->pdo, '끝난 것', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $this->pdo->exec("UPDATE bcl_jobs SET state = 'done' WHERE job_key = '{$done}'");

        $jobs = bcl_jobs_list($this->pdo, 'open');

        $this->assertSame([$open], array_column($jobs, 'job_id'));
    }

    /** bcl_jobs_list: state done */
    public function test_jobs_list_done()
    {
        $open = bcl_job_create($this->pdo, '열린 것', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $done = bcl_job_create($this->pdo, '끝난 것', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $this->pdo->exec("UPDATE bcl_jobs SET state = 'done' WHERE job_key = '{$done}'");

        $jobs = bcl_jobs_list($this->pdo, 'done');

        $this->assertSame([$done], array_column($jobs, 'job_id'));
    }

    /** bcl_jobs_list: 최근 등록 순 */
    public function test_jobs_list_정렬()
    {
        $first = bcl_job_create($this->pdo, '먼저', 'a@bluesoft.co.kr', ['lms-a'], '지시');
        $second = bcl_job_create($this->pdo, '나중', 'a@bluesoft.co.kr', ['lms-a'], '지시');

        $jobs = bcl_jobs_list($this->pdo, 'open');

        $this->assertSame([$second, $first], array_column($jobs, 'job_id'));
    }

    /** bcl_nodes_live: last_seen 이 now 보다 NODE_TTL_S 앞이면 생존 */
    public function test_nodes_live_경계()
    {
        $this->pdo->exec("INSERT INTO bcl_nodes (node_id, account, token_user, addr, free_cpu, free_mem, running, attempts, last_seen)
            VALUES ('n-edge', 'acct1', 'a@bluesoft.co.kr', '10.0.0.1:9000', 2.5, 4096, 1, '[\"job-1/p1/1\"]', 997)");

        $nodes = bcl_nodes_live($this->pdo, 1000);

        $this->assertSame([[
            'node_id' => 'n-edge',
            'account' => 'acct1',
            'addr' => '10.0.0.1:9000',
            'free_cpu' => 2.5,
            'free_mem' => 4096,
            'running' => 1,
            'attempts' => ['job-1/p1/1'],
            'last_seen' => 997,
        ]], $nodes);
    }

    /** bcl_nodes_live: last_seen 이 now 보다 NODE_TTL_S 넘게 앞이면 제외 */
    public function test_nodes_live_만료()
    {
        $this->pdo->exec("INSERT INTO bcl_nodes (node_id, account, token_user, addr, free_cpu, free_mem, running, attempts, last_seen)
            VALUES ('n-old', 'acct1', 'a@bluesoft.co.kr', '10.0.0.1:9000', 2, 4096, 0, '[]', 996)");

        $this->assertSame([], bcl_nodes_live($this->pdo, 1000));
    }

    /** bcl_nodes_live: node_id 순 */
    public function test_nodes_live_정렬()
    {
        $this->pdo->exec("INSERT INTO bcl_nodes (node_id, account, token_user, addr, free_cpu, free_mem, running, attempts, last_seen)
            VALUES ('n-b', 'acct1', 'a@bluesoft.co.kr', '10.0.0.2:9000', 1, 1024, 0, '[]', 1000),
                   ('n-a', 'acct2', 'b@bluesoft.co.kr', '10.0.0.3:9000', 1, 1024, 0, '[]', 1000)");

        $nodes = bcl_nodes_live($this->pdo, 1000);

        $this->assertSame(['n-a', 'n-b'], array_column($nodes, 'node_id'));
    }
}

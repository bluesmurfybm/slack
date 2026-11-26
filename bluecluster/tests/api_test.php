<?php

namespace Bluecluster\Tests;

use Bluecluster\Tests\Support\TestCase;

require_once __DIR__ . '/Support/TestCase.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/files.php';
require_once __DIR__ . '/../lib/cluster.php';
require_once __DIR__ . '/../routes/jobs.php';
require_once __DIR__ . '/../routes/nodes.php';

final class api_test extends TestCase
{
    /** @var string 테스트마다 새로 쓰는 결과 폴더 */
    private $filesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesDir = sys_get_temp_dir() . '/bcl_api_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->filesDir)) unlink($this->filesDir);
        if (is_dir($this->filesDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->filesDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->filesDir);
        }
    }

    /** api.php: Authorization 없으면 401 JSON reason 인증 필요 */
    public function testApiWithoutTokenReturns401Json()
    {
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18083', '-t', dirname(__DIR__, 2)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && !($sock = @fsockopen('127.0.0.1', 18083)); $i++) {
                usleep(100000);
            }
            $this->assertNotFalse($sock, 'PHP 내장 서버가 뜨지 않았습니다');
            fclose($sock);

            $raw = file_get_contents('http://127.0.0.1:18083/bluecluster/api.php?p=/jobs/open', false, stream_context_create(['http' => [
                'ignore_errors' => true,
                'follow_location' => 0,
            ]]));
            $headers = $http_response_header;
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        $this->assertMatchesRegularExpression('~^HTTP/\S+ 401~', $headers[0]);
        $this->assertContains('Content-Type: application/json; charset=utf-8', $headers);
        $this->assertSame([], preg_grep('~^Location:~i', $headers));
        $this->assertSame('{"reason":"인증 필요"}', $raw);
    }

    /** bcl_api_handle: user 가 null 이면 401 reason 인증 필요 */
    public function testHandleWithoutUser401()
    {
        $res = bcl_api_handle(['pdo' => $this->pdo, 'user' => null, 'now' => 1000, 'files_dir' => $this->filesDir], 'GET', '/jobs/open', '');

        $this->assertSame(['status' => 401, 'data' => ['reason' => '인증 필요']], $res);
    }

    /** bcl_api_handle: 없는 경로는 404 reason 없는 경로 */
    public function testUnknownPath404()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $this->assertSame(['status' => 404, 'data' => ['reason' => '없는 경로']], bcl_api_handle($ctx, 'GET', '/nope', ''));
        $this->assertSame(['status' => 404, 'data' => ['reason' => '없는 경로']], bcl_api_handle($ctx, 'GET', '', ''));
    }

    /** bcl_api_handle: 경로는 있지만 method 가 다르면 404 reason 없는 경로 */
    public function testWrongMethod404()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'GET', '/jobs/' . $job . '/claim', '');

        $this->assertSame(['status' => 404, 'data' => ['reason' => '없는 경로']], $res);
    }

    /** bcl_api_handle: 본문이 JSON 이 아니면 400 reason JSON 형식 오류 */
    public function testBadJson400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'JSON 형식 오류']], bcl_api_handle($ctx, 'POST', '/jobs', '{"title":'));
        $this->assertSame(['status' => 400, 'data' => ['reason' => 'JSON 형식 오류']], bcl_api_handle($ctx, 'POST', '/jobs', '"title"'));
    }

    /** bcl_api_handle: 본문이 없으면 첫 필수 값 title 이 없는 400 */
    public function testPostWithoutBody400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs', '');

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: title']], $res);
    }

    /** bcl_route_job_create: submitter 는 본문 값이 아니라 user email, 응답은 job_id 만 */
    public function testPostJobsSubmitterIsUser()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs', json_encode([
            'title' => '출석부 수정', 'submitter' => 'other@bluesoft.co.kr', 'repos' => ['lms-a'], 'instructions' => '결석 표시를 고친다',
        ]));

        $this->assertSame(200, $res['status']);
        $this->assertSame(['job_id'], array_keys($res['data']));
        $job = bcl_api_handle($ctx, 'GET', '/jobs/' . $res['data']['job_id'], '');
        $this->assertSame(200, $job['status']);
        $this->assertSame([$res['data']['job_id'], '출석부 수정', 'user1@bluesoft.co.kr', ['lms-a'], '결석 표시를 고친다', 'open', null, 0, []], [
            $job['data']['job_id'], $job['data']['title'], $job['data']['submitter'], $job['data']['repos'],
            $job['data']['instructions'], $job['data']['state'], $job['data']['leader'], $job['data']['epoch'], $job['data']['pieces'],
        ]);
    }

    /** bcl_route_job_create: title 없음 */
    public function testPostJobsWithoutTitle400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs', json_encode(['repos' => ['r'], 'instructions' => 'i']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: title']], $res);
    }

    /** bcl_route_job_create: repos 없음 */
    public function testPostJobsWithoutRepos400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs', json_encode(['title' => 't', 'instructions' => 'i']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: repos']], $res);
    }

    /** bcl_route_job_create: instructions 없음 */
    public function testPostJobsWithoutInstructions400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs', json_encode(['title' => 't', 'repos' => ['r']]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: instructions']], $res);
    }

    /** bcl_route_job_create: 빈 title 은 400, 작업을 만들지 않음 */
    public function testPostJobsEmptyTitle400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs', json_encode(['title' => ' ', 'repos' => ['r'], 'instructions' => 'i']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: title']], $res);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM bcl_jobs")->fetchColumn());
    }

    /** bcl_route_job_create: 빈 repos 는 400, 작업을 만들지 않음 */
    public function testPostJobsEmptyRepos400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs', json_encode(['title' => 't', 'repos' => [], 'instructions' => 'i']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: repos']], $res);
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM bcl_jobs")->fetchColumn());
    }

    /** bcl_route_jobs: GET 미등록 작업 404 */
    public function testGetUnregisteredJob404()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'GET', '/jobs/nope', '');

        $this->assertSame(['status' => 404, 'data' => ['reason' => '등록되지 않은 작업']], $res);
    }

    /** bcl_job_claim: 먼저 온 node_id 가 leader, epoch 1, lease_until now + BCL_LEASE_S */
    public function testClaimFirstWins()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $a = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        $b = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-B']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true, 'leader' => 'PC-A', 'epoch' => 1]], $a);
        $this->assertSame(['status' => 200, 'data' => ['ok' => false, 'leader' => 'PC-A', 'epoch' => 1]], $b);
        $this->assertSame(1003, bcl_job_get($this->pdo, $job)['lease_until']);
    }

    /** bcl_job_claim: lease_until 전에는 거절, lease_until 부터 다른 node_id 가 epoch 2 leader */
    public function testClaimAfterLeaseExpiry()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $ctx['now'] = 1002;
        $held = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-B']));
        $ctx['now'] = 1003;
        $expired = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-B']));

        $this->assertSame(['ok' => false, 'leader' => 'PC-A', 'epoch' => 1], $held['data']);
        $this->assertSame(['ok' => true, 'leader' => 'PC-B', 'epoch' => 2], $expired['data']);
    }

    /** bcl_job_claim: state done 작업은 리스가 끝나도 거절 */
    public function testClaimDoneJobRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/finish', json_encode(['node_id' => 'PC-A', 'epoch' => 1, 'summary' => 's']));

        $ctx['now'] = 1010;
        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-B']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false, 'leader' => 'PC-A', 'epoch' => 1]], $res);
    }

    /** bcl_job_claim: 미등록 작업 404 */
    public function testClaimUnregisteredJob404()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/jobs/nope/claim', json_encode(['node_id' => 'PC-A']));

        $this->assertSame(['status' => 404, 'data' => ['reason' => '등록되지 않은 작업']], $res);
    }

    /** bcl_job_claim: node_id 없음 */
    public function testClaimWithoutNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(new \stdClass()));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: node_id']], $res);
    }

    /** bcl_route_job_claim: 문자열이 아닌 node_id 는 400 */
    public function testClaimNumberNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 5]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: 5']], $res);
        $this->assertNull(bcl_job_get($this->pdo, $job)['leader']);
    }

    /** bcl_route_job_claim: 빈 node_id 는 400 */
    public function testClaimEmptyNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => '']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: ']], $res);
    }

    /** bcl_route_job_claim: 앞에 공백이 있는 node_id 는 400 */
    public function testClaimLeadingSpaceNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => ' PC-A']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류:  PC-A']], $res);
    }

    /** bcl_route_job_claim: 뒤에 공백이 있는 node_id 는 400 */
    public function testClaimTrailingSpaceNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => "PC-A\t"]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => "node_id 값 오류: PC-A\t"]], $res);
    }

    /** bcl_route_job_claim: node_id 는 BCL_NODE_ID_MAX(80) 글자까지, 81 글자는 400 */
    public function testClaimNodeIdLength()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $long = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => str_repeat('가', 81)]));
        $fit = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => str_repeat('가', 80)]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: ' . str_repeat('가', 81)]], $long);
        $this->assertSame(['status' => 200, 'data' => ['ok' => true, 'leader' => str_repeat('가', 80), 'epoch' => 1]], $fit);
    }

    /** bcl_job_claim: 다른 연결의 claim 이 commit 되기 전에 들어온 claim 은 기다렸다가 ok false */
    public function testClaimConcurrentOnlyOneWins()
    {
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        $first = bcl_connect($this->db);
        $first->beginTransaction();
        try {
            $first->prepare("SELECT id FROM bcl_jobs WHERE job_key = ? FOR UPDATE")->execute([$job]);
            $first->prepare("UPDATE bcl_jobs SET leader = 'PC-A', epoch = 1, lease_until = 1003 WHERE job_key = ?")->execute([$job]);

            // 두 번째 claim 은 다른 프로세스의 다른 연결에서 실제로 동시에 부른다
            $child = proc_open([PHP_BINARY, '-r', '
                $root = $argv[1];
                foreach (["inc/settings.php", "db.php", "lib/http.php", "lib/files.php", "lib/cluster.php"] as $file) require $root . "/" . $file;
                $in = json_decode(stream_get_contents(STDIN), true);
                echo json_encode(bcl_job_claim(bcl_connect($in["db"]), $in["job"], "PC-B", 1000));
            ', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], json_encode(['db' => $this->db, 'job' => $job]));
            fclose($pipes[0]);
            for ($i = 0; $i < 50; $i++) {
                $waiting = (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT' AND trx_query LIKE '%bcl_jobs%'")->fetchColumn();
                if ($waiting) break;
                usleep(100000);
            }
            $first->commit();
            $second = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            proc_close($child);
        } finally {
            if ($first->inTransaction()) $first->rollBack();
        }

        $this->assertSame(1, $waiting);
        $this->assertSame('', $errors);
        $this->assertSame('{"ok":false,"leader":"PC-A","epoch":1}', $second);
        $after = bcl_job_get($this->pdo, $job);
        $this->assertSame(['PC-A', 1], [$after['leader'], $after['epoch']]);
    }

    /** bcl_job_lease: 갱신하면 lease_until 이 now + BCL_LEASE_S 로 늘어 다른 node_id 의 claim 거절 */
    public function testLeaseRenewExtends()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $ctx['now'] = 1002;
        $renewed = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/lease', json_encode(['node_id' => 'PC-A', 'epoch' => 1]));
        $ctx['now'] = 1004;
        $b = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-B']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $renewed);
        $this->assertSame(1005, bcl_job_get($this->pdo, $job)['lease_until']);
        $this->assertFalse($b['data']['ok']);
    }

    /** bcl_job_lease: 리스가 끝났어도 다른 claim 전이면 같은 leader·epoch 는 ok true */
    public function testLeaseAfterExpiryBeforeOtherClaim()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $ctx['now'] = 1010;
        $renewed = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/lease', json_encode(['node_id' => 'PC-A', 'epoch' => 1]));

        $this->assertSame(['ok' => true], $renewed['data']);
        $this->assertSame(1013, bcl_job_get($this->pdo, $job)['lease_until']);
    }

    /** bcl_job_lease: 다른 epoch 는 ok false, lease_until 그대로 */
    public function testLeaseWrongEpoch()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $ctx['now'] = 1002;
        $renewed = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/lease', json_encode(['node_id' => 'PC-A', 'epoch' => 0]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $renewed);
        $this->assertSame(1003, bcl_job_get($this->pdo, $job)['lease_until']);
    }

    /** bcl_job_lease: leader 가 아닌 node_id 는 ok false */
    public function testLeaseWrongNode()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $renewed = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/lease', json_encode(['node_id' => 'pc-a', 'epoch' => 1]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $renewed);
    }

    /** bcl_job_lease: epoch 없음 */
    public function testLeaseWithoutEpoch400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/lease', json_encode(['node_id' => 'PC-A']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: epoch']], $res);
    }

    /** bcl_route_job_lease: node_id 형식 오류 */
    public function testLeaseBadNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/lease', json_encode(['node_id' => 'PC-A ', 'epoch' => 1]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: PC-A ']], $res);
    }

    /** bcl_jobs_open: leader 없는 작업과 lease_until 이 지난 작업, state done 제외 */
    public function testOpenJobsExcludesLedAndDone()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $free = bcl_job_create($this->pdo, 'free', 'user1@bluesoft.co.kr', ['r'], 'i');
        $led = bcl_job_create($this->pdo, 'led', 'user1@bluesoft.co.kr', ['r'], 'i');
        $done = bcl_job_create($this->pdo, 'done', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $led . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $done . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $done . '/finish', json_encode(['node_id' => 'PC-A', 'epoch' => 1, 'summary' => 's']));

        $before = bcl_api_handle($ctx, 'GET', '/jobs/open', '');
        $ctx['now'] = 1003;
        $after = bcl_api_handle($ctx, 'GET', '/jobs/open', '');

        $this->assertSame(200, $before['status']);
        $this->assertSame([$free], array_column($before['data']['jobs'], 'job_id'));
        $this->assertSame([$free, $led], array_column($after['data']['jobs'], 'job_id'));
    }

    /** bcl_job_pieces: 두 번째 등록은 ok true 이지만 pieces 변경 없음, 등록 직후 state pending */
    public function testPiecesRegisteredOnce()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $first = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => '첫째']],
        ]));
        $second = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p2', 'repo' => 'r', 'desc' => '둘째']],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $first);
        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $second);
        $this->assertSame([['piece_id' => 'p1', 'repo' => 'r', 'desc' => '첫째', 'state' => 'pending', 'attempts' => []]],
            bcl_job_get($this->pdo, $job)['pieces']);
    }

    /** bcl_job_pieces: leader 가 아니면 ok false, pieces 없음 */
    public function testPiecesFromNonLeaderRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-B', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $this->assertSame([], bcl_job_get($this->pdo, $job)['pieces']);
    }

    /** bcl_job_pieces: pieces 없음 */
    public function testPiecesWithoutPieces400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode(['node_id' => 'PC-A', 'epoch' => 1]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: pieces']], $res);
    }

    /** bcl_route_job_pieces: node_id 형식 오류 */
    public function testPiecesBadNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => ['PC-A'], 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: ["PC-A"]']], $res);
    }

    /** bcl_job_pieces: pieces 가 배열이 아니면 400 */
    public function testPiecesNotArray400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode(['node_id' => 'PC-A', 'epoch' => 1, 'pieces' => 'p1']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'pieces 값 오류: p1']], $res);
    }

    /** bcl_job_pieces: pieces 항목에 desc 없음 */
    public function testPiecesItemWithoutDesc400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: desc']], $res);
    }

    /** bcl_job_pieces: pieces 항목이 객체가 아니면 piece_id 없음 */
    public function testPiecesItemNotObject400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode(['node_id' => 'PC-A', 'epoch' => 1, 'pieces' => ['p1']]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: piece_id']], $res);
    }

    /** bcl_job_pieces: piece_id 에 / 가 있으면 400, pieces 없음 */
    public function testPiecesSlashInPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => '../p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: ../p1']], $res);
        $this->assertSame([], bcl_job_get($this->pdo, $job)['pieces']);
    }

    /** bcl_job_pieces: 역슬래시가 있는 piece_id 는 400 */
    public function testPiecesBackslashInPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'a\\b', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: a\\b']], $res);
    }

    /** bcl_job_pieces: 빈 piece_id 는 400 */
    public function testPiecesEmptyPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => '', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: ']], $res);
    }

    /** bcl_job_pieces: NUL 이 있는 piece_id 는 400 */
    public function testPiecesNulInPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => "a\u{0000}b", 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => "piece_id 값 오류: a\u{0000}b"]], $res);
    }

    /** bcl_job_pieces: 문자열이 아닌 piece_id 는 400 */
    public function testPiecesNumberPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 5, 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: 5']], $res);
    }

    /** bcl_job_pieces: piece_id 는 80 글자까지 등록, 81 글자는 400 */
    public function testPiecesPieceIdLength()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $long = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => str_repeat('a', 81), 'repo' => 'r', 'desc' => 'd']],
        ]));
        $fit = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => str_repeat('a', 80), 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: ' . str_repeat('a', 81)]], $long);
        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $fit);
        $this->assertSame([str_repeat('a', 80)], array_column(bcl_job_get($this->pdo, $job)['pieces'], 'piece_id'));
    }

    /** bcl_job_pieces: 영문·숫자 밖의 글자가 든 piece_id 는 400 */
    public function testPiecesKoreanPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => '조각1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: 조각1']], $res);
    }

    /** bcl_job_pieces: piece_id .. 는 400 */
    public function testPiecesDotDotPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => '..', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: ..']], $res);
    }

    /** bcl_job_pieces: 드라이브 문자가 든 piece_id C:x 는 400 */
    public function testPiecesDrivePieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'C:x', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: C:x']], $res);
    }

    /** bcl_job_pieces: Windows 예약 이름 piece_id CON 은 400 */
    public function testPiecesReservedPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'CON', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: CON']], $res);
    }

    /** bcl_job_pieces: 확장자가 붙은 소문자 예약 이름 piece_id con.txt 는 400 */
    public function testPiecesReservedWithExtensionPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'con.txt', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: con.txt']], $res);
    }

    /** bcl_job_pieces: 예약 이름 COM1·LPT9 도 400, COM10 은 등록 */
    public function testPiecesReservedComLptPieceId()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $com = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'com1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        $lpt = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'LPT9.log', 'repo' => 'r', 'desc' => 'd']],
        ]));
        $fit = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'COM10', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: com1']], $com);
        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: LPT9.log']], $lpt);
        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $fit);
    }

    /** bcl_job_pieces: 점으로 끝나는 piece_id 는 400 */
    public function testPiecesTrailingDotPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1.', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: p1.']], $res);
    }

    /** bcl_job_pieces: - 로 시작하는 piece_id 는 400 */
    public function testPiecesLeadingDashPieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => '-p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 값 오류: -p1']], $res);
    }

    /** bcl_job_pieces: 줄바꿈으로 끝나는 piece_id 는 400 */
    public function testPiecesTrailingNewlinePieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => "p1\n", 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => "piece_id 값 오류: p1\n"]], $res);
    }

    /** bcl_job_pieces: 영문·숫자·. _ - 로 된 piece_id 와 한 글자 piece_id 는 등록 */
    public function testPiecesValidPieceId()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1,
            'pieces' => [['piece_id' => 'Lms-a.part_2', 'repo' => 'r', 'desc' => 'd'], ['piece_id' => '7', 'repo' => 'r', 'desc' => 'e']],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $this->assertSame(['Lms-a.part_2', '7'], array_column(bcl_job_get($this->pdo, $job)['pieces'], 'piece_id'));
    }

    /** bcl_job_pieces: 다른 epoch 는 ok false, pieces 없음 */
    public function testPiecesWrongEpochRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 2, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $this->assertSame([], bcl_job_get($this->pdo, $job)['pieces']);
    }

    /** bcl_job_pieces: 같은 piece_id 가 두 번 오면 400, pieces 없음 */
    public function testPiecesDuplicatePieceId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1,
            'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd'], ['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'e']],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'piece_id 중복: p1']], $res);
        $this->assertSame([], bcl_job_get($this->pdo, $job)['pieces']);
    }

    /** bcl_job_pieces: 대소문자만 다른 piece_id 는 다른 조각, start 도 그 조각만 */
    public function testPiecesCaseSensitivePieceId()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1,
            'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => '소문자'], ['piece_id' => 'P1', 'repo' => 'r', 'desc' => '대문자']],
        ]));
        $started = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/P1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $this->assertSame(['status' => 200, 'data' => ['ok' => true, 'attempt_no' => 1]], $started);
        $pieces = bcl_job_get($this->pdo, $job)['pieces'];
        $this->assertSame([['p1', 'pending'], ['P1', 'running']], array_map(fn($piece) => [$piece['piece_id'], $piece['state']], $pieces));
    }

    /** bcl_piece_start: attempt_no 1, 조각 running, attempt status running·started now, token_user 는 user email */
    public function testStartRecordsTokenUser()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $ctx['now'] = 1001;
        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true, 'attempt_no' => 1]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame('running', $piece['state']);
        $this->assertSame([[
            'attempt_no' => 1, 'node_id' => 'PC-B', 'account' => 'acc', 'status' => 'running', 'started' => 1001, 'ended' => null,
            'result_path' => null, 'patch_path' => null, 'log_path' => null, 'redactions' => null, 'closed_by' => null,
        ]], $piece['attempts']);
        $this->assertSame('user1@bluesoft.co.kr', $this->pdo->query("SELECT token_user FROM bcl_attempts")->fetchColumn());
    }

    /** bcl_piece_start: 없는 조각 404 */
    public function testStartUnknownPiece404()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p9/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $this->assertSame(['status' => 404, 'data' => ['reason' => '없는 조각']], $res);
    }

    /** bcl_piece_start: account 없음 */
    public function testStartWithoutAccount400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: account']], $res);
    }

    /** bcl_route_piece_start: node_id 형식 오류 */
    public function testStartBadNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => ' PC-B', 'account' => 'acc']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류:  PC-B']], $res);
    }

    /** bcl_piece_start: running 조각은 ok false, attempt_no null, 시도 추가 없음 */
    public function testStartRejectedWhenRunning()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $first = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        $second = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-C', 'account' => 'acc']));

        $this->assertSame(['ok' => true, 'attempt_no' => 1], $first['data']);
        $this->assertSame(['status' => 200, 'data' => ['ok' => false, 'attempt_no' => null]], $second);
        $this->assertSame(['PC-B'], array_column(bcl_job_get($this->pdo, $job)['pieces'][0]['attempts'], 'node_id'));
    }

    /** bcl_piece_start: done 조각은 ok false, 시도 추가 없음 */
    public function testStartRejectedWhenDone()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $again = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-C', 'account' => 'acc']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false, 'attempt_no' => null]], $again);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame('done', $piece['state']);
        $this->assertCount(1, $piece['attempts']);
    }

    /** bcl_piece_start: state done 작업의 pending 조각도 ok false, 시도 추가 없음 */
    public function testStartRejectedWhenJobDone()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/finish', json_encode(['node_id' => 'PC-A', 'epoch' => 1, 'summary' => 's']));

        $started = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false, 'attempt_no' => null]], $started);
        $this->assertSame([], bcl_job_get($this->pdo, $job)['pieces'][0]['attempts']);
    }

    /** bcl_piece_start: files_dir 에 쓸 수 없으면 ok false, attempt_no null, 시도 추가 없음 */
    public function testStartRejectedWhenFilesDirNotWritable()
    {
        file_put_contents($this->filesDir, 'x');
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir . '/sub'];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false, 'attempt_no' => null]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['pending', []], [$piece['state'], $piece['attempts']]);
    }

    /** bcl_piece_finish: 1 MB 넘는 result·patch·log 원본 파일 저장, attempt 에는 result_path·patch_path·log_path 만 */
    public function testFinishSavesOriginalFiles()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        $result = str_repeat("첫 줄\n\"둘째\" 줄\r\n끝\u{0000}\\", 60000);
        $patch = str_repeat("--- a/x\n+++ b/x\n@@ -1 +1 @@\n-\"a\"\n+'b'\r\n", 30000);

        $ctx['now'] = 1005;
        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => $result, 'patch' => $patch,
            'log' => [['role' => 'user', 'text' => "줄바꿈\n따옴표\"", 'input' => new \stdClass(), 'score' => 1.0, 'path' => 'a/b']],
        ], JSON_PRESERVE_ZERO_FRACTION));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $this->assertGreaterThan(1024 * 1024, strlen($result));
        $this->assertGreaterThan(1024 * 1024, strlen($patch));
        $this->assertSame($result, file_get_contents($this->filesDir . '/' . $job . '/p1-1.result.txt'));
        $this->assertSame($patch, file_get_contents($this->filesDir . '/' . $job . '/p1-1.patch'));
        $this->assertSame('[{"role":"user","text":"줄바꿈\n따옴표\"","input":{},"score":1.0,"path":"a/b"}]',
            file_get_contents($this->filesDir . '/' . $job . '/p1-1.log.json'));
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame('done', $piece['state']);
        $attempt = $piece['attempts'][0];
        $this->assertSame(['attempt_no', 'node_id', 'account', 'status', 'started', 'ended', 'result_path', 'patch_path', 'log_path', 'redactions', 'closed_by'],
            array_keys($attempt));
        $this->assertSame(['done', 1005], [$attempt['status'], $attempt['ended']]);
        $this->assertSame([
            $this->filesDir . '/' . $job . '/p1-1.result.txt',
            $this->filesDir . '/' . $job . '/p1-1.patch',
            $this->filesDir . '/' . $job . '/p1-1.log.json',
        ], [$attempt['result_path'], $attempt['patch_path'], $attempt['log_path']]);
    }

    /** bcl_piece_finish: 작업 폴더에 쓸 수 없으면 경로 없는 500 reason, 경로는 error_log, attempt 는 running 그대로 */
    public function testFinishNotWritable500()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        file_put_contents($this->filesDir . '/' . $job, 'x');
        $log = $this->filesDir . '/error.log';
        $previous = ini_set('error_log', $log);

        try {
            $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
                'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => 'r', 'patch' => 'p', 'log' => [],
            ]));
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertSame(['status' => 500, 'data' => ['reason' => '결과 파일을 저장하지 못했습니다']], $res);
        $this->assertStringContainsString($this->filesDir . '/' . $job, file_get_contents($log));
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['running', 'running', null], [$piece['state'], $piece['attempts'][0]['status'], $piece['attempts'][0]['result_path']]);
    }

    /** bcl_piece_finish: status lost 는 조각 pending, attempt lost */
    public function testFinishLostReturnsToPending()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'lost', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['ok' => true], $res['data']);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['pending', 'lost'], [$piece['state'], $piece['attempts'][0]['status']]);
    }

    /** bcl_piece_finish: status stopped_by_owner 는 조각 pending, attempt stopped_by_owner */
    public function testFinishStoppedByOwnerReturnsToPending()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'stopped_by_owner', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['ok' => true], $res['data']);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['pending', 'stopped_by_owner'], [$piece['state'], $piece['attempts'][0]['status']]);
    }

    /** bcl_piece_finish: 시도의 node_id 가 아니면 ok false, 조각·attempt running 그대로 */
    public function testFinishFromOtherNodeRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-C', 'attempt_no' => 1, 'status' => 'lost', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['running', 'running'], [$piece['state'], $piece['attempts'][0]['status']]);
        $this->assertFalse(is_dir($this->filesDir . '/' . $job));
    }

    /** bcl_piece_finish: 시도의 token_user 가 아닌 user 는 403, 조각·attempt running, 파일 없음 */
    public function testFinishFromOtherUser403()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $ctx['user'] = ['email' => 'user2@bluesoft.co.kr'];
        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => 'r', 'patch' => 'p', 'log' => [],
        ]));

        $this->assertSame(['status' => 403, 'data' => ['reason' => '다른 사용자의 시도']], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['running', 'running'], [$piece['state'], $piece['attempts'][0]['status']]);
        $this->assertFalse(is_dir($this->filesDir . '/' . $job));
    }

    /** bcl_piece_finish: 이미 끝난 시도는 ok false, attempt status 그대로 */
    public function testFinishEndedAttemptRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'lost', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['pending', 'lost'], [$piece['state'], $piece['attempts'][0]['status']]);
    }

    /** bcl_piece_finish: 현재(마지막)가 아닌 attempt 는 attempt 만 done, 조각 running 그대로 */
    public function testFinishNonCurrentAttemptKeepsPiece()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        $this->pdo->exec("UPDATE bcl_pieces SET state = 'pending'");
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-C', 'account' => 'acc']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame('running', $piece['state']);
        $this->assertSame(['done', 'running'], array_column($piece['attempts'], 'status'));
    }

    /** bcl_piece_finish: redactions 를 그대로 기록, 끝나기 전에는 null */
    public function testFinishStoresRedactions()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        $before = bcl_job_get($this->pdo, $job)['pieces'][0]['attempts'][0]['redactions'];

        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
            'redactions' => ['secret_assign' => 2, 'owner_path' => 1],
        ]));

        $this->assertNull($before);
        $this->assertSame(['secret_assign' => 2, 'owner_path' => 1], bcl_job_get($this->pdo, $job)['pieces'][0]['attempts'][0]['redactions']);
    }

    /** bcl_piece_finish: redactions 가 없으면 '{}' 로 기록해 {} 로 나감 */
    public function testFinishWithoutRedactions()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame('{}', $this->pdo->query("SELECT redactions FROM bcl_attempts")->fetchColumn());
        $this->assertSame('{}', json_encode(bcl_job_get($this->pdo, $job)['pieces'][0]['attempts'][0]['redactions']));
    }

    /** bcl_piece_finish: 빈 redactions {} 도 '{}' 로 기록 */
    public function testFinishEmptyRedactions()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [], 'redactions' => new \stdClass(),
        ]));

        $this->assertSame('{}', $this->pdo->query("SELECT redactions FROM bcl_attempts")->fetchColumn());
    }

    /** bcl_piece_finish: status 값 오류 */
    public function testFinishWrongStatus400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'running', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'status 값 오류: running']], $res);
    }

    /** bcl_piece_finish: result 가 문자열이 아니면 400 */
    public function testFinishResultNotString400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => 5, 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'result 값 오류: 5']], $res);
    }

    /** bcl_piece_finish: patch 가 문자열이 아니면 400 */
    public function testFinishPatchNotString400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => ['x'], 'log' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'patch 값 오류: ["x"]']], $res);
    }

    /** bcl_piece_finish: log 없음 */
    public function testFinishWithoutLog400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '',
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: log']], $res);
    }

    /** bcl_route_piece_finish: node_id 형식 오류 */
    public function testFinishBadNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => '', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: ']], $res);
    }

    /** bcl_piece_finish: 없는 시도 404 */
    public function testFinishUnknownAttempt404()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $this->assertSame(['status' => 404, 'data' => ['reason' => '없는 시도']], $res);
    }

    /** bcl_piece_close: leader 가 닫으면 조각 pending, attempt lost, closed_by leader node_id, ended now, redactions null */
    public function testCloseByLeader()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $ctx['now'] = 1005;
        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'attempt_no' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame('pending', $piece['state']);
        $this->assertSame(['lost', 'PC-A', 1005, null], [
            $piece['attempts'][0]['status'], $piece['attempts'][0]['closed_by'], $piece['attempts'][0]['ended'], $piece['attempts'][0]['redactions'],
        ]);
    }

    /** bcl_piece_close: leader 가 아니면 ok false, 조각·attempt running, closed_by null */
    public function testCloseRejectedFromNonLeader()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-C', 'epoch' => 1, 'attempt_no' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['running', 'running', null], [$piece['state'], $piece['attempts'][0]['status'], $piece['attempts'][0]['closed_by']]);
    }

    /** bcl_piece_close: 같은 leader 라도 지난 epoch 는 ok false */
    public function testCloseRejectedWithOldEpoch()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        $ctx['now'] = 1004;
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'attempt_no' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $after = bcl_job_get($this->pdo, $job);
        $this->assertSame([2, 'running'], [$after['epoch'], $after['pieces'][0]['attempts'][0]['status']]);
    }

    /** bcl_piece_close: status 는 lost 만, done 은 400 */
    public function testCloseWrongStatus400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'attempt_no' => 1, 'status' => 'done',
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'status 값 오류: done']], $res);
    }

    /** bcl_piece_close: attempt_no 없음 */
    public function testCloseWithoutAttemptNo400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: attempt_no']], $res);
    }

    /** bcl_route_piece_close: node_id 형식 오류 */
    public function testCloseBadNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => null, 'epoch' => 1, 'attempt_no' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: null']], $res);
    }

    /** bcl_piece_close: 없는 시도 404 */
    public function testCloseUnknownAttempt404()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'attempt_no' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 404, 'data' => ['reason' => '없는 시도']], $res);
    }

    /** bcl_piece_close: 이미 끝난 시도는 ok false, 조각·attempt done, closed_by null */
    public function testCloseEndedAttemptRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/finish', json_encode([
            'node_id' => 'PC-B', 'attempt_no' => 1, 'status' => 'done', 'result' => '', 'patch' => '', 'log' => [],
        ]));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'attempt_no' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame(['done', 'done', null], [$piece['state'], $piece['attempts'][0]['status'], $piece['attempts'][0]['closed_by']]);
    }

    /** bcl_piece_close: 현재(마지막)가 아닌 attempt 는 attempt 만 lost, 조각 running 그대로 */
    public function testCloseNonCurrentAttemptKeepsPiece()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'pieces' => [['piece_id' => 'p1', 'repo' => 'r', 'desc' => 'd']],
        ]));
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-B', 'account' => 'acc']));
        $this->pdo->exec("UPDATE bcl_pieces SET state = 'pending'");
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/start', json_encode(['node_id' => 'PC-C', 'account' => 'acc']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/pieces/p1/close', json_encode([
            'node_id' => 'PC-A', 'epoch' => 1, 'attempt_no' => 1, 'status' => 'lost',
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $piece = bcl_job_get($this->pdo, $job)['pieces'][0];
        $this->assertSame('running', $piece['state']);
        $this->assertSame(['lost', 'running'], array_column($piece['attempts'], 'status'));
    }

    /** bcl_job_finish: leader 가 끝내면 state done, summary 기록, finished_at now */
    public function testJobFinishByLeader()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $ctx['now'] = 1002;
        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/finish', json_encode(['node_id' => 'PC-A', 'epoch' => 1, 'summary' => '조각 1개 완료']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $after = bcl_job_get($this->pdo, $job);
        $this->assertSame(['done', '조각 1개 완료'], [$after['state'], $after['summary']]);
        $this->assertSame(1002, (int)$this->pdo->query("SELECT finished_at FROM bcl_jobs")->fetchColumn());
    }

    /** bcl_job_finish: 지난 epoch 는 ok false, state open */
    public function testJobFinishWrongEpochRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/finish', json_encode(['node_id' => 'PC-A', 'epoch' => 2, 'summary' => 's']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $this->assertSame('open', bcl_job_get($this->pdo, $job)['state']);
    }

    /** bcl_job_finish: leader 가 아닌 node_id 는 ok false, state open */
    public function testJobFinishWrongNodeRejected()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');
        bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/claim', json_encode(['node_id' => 'PC-A']));

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/finish', json_encode(['node_id' => 'PC-B', 'epoch' => 1, 'summary' => 's']));

        $this->assertSame(['status' => 200, 'data' => ['ok' => false]], $res);
        $this->assertSame('open', bcl_job_get($this->pdo, $job)['state']);
    }

    /** bcl_job_finish: summary 없음 */
    public function testJobFinishWithoutSummary400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/finish', json_encode(['node_id' => 'PC-A', 'epoch' => 1]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: summary']], $res);
    }

    /** bcl_route_job_finish: node_id 형식 오류 */
    public function testJobFinishBadNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        $job = bcl_job_create($this->pdo, 't', 'user1@bluesoft.co.kr', ['r'], 'i');

        $res = bcl_api_handle($ctx, 'POST', '/jobs/' . $job . '/finish', json_encode(['node_id' => "PC-A\n", 'epoch' => 1, 'summary' => 's']));

        $this->assertSame(['status' => 400, 'data' => ['reason' => "node_id 값 오류: PC-A\n"]], $res);
    }

    /** bcl_node_heartbeat: 같은 node_id 는 마지막 하트비트로 바뀌고 token_user 는 user email */
    public function testHeartbeatRecordsTokenUser()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 1, 'attempts' => ['job-1/p1/1'],
        ]));

        $ctx['now'] = 1001;
        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc2', 'addr' => '127.0.0.1:2', 'free_cpu' => 40, 'free_mem' => 30, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $this->assertSame([[
            'node_id' => 'PC-A', 'account' => 'acc2', 'addr' => '127.0.0.1:2', 'free_cpu' => 40.0, 'free_mem' => 30, 'running' => 0, 'attempts' => [], 'last_seen' => 1001,
        ]], bcl_nodes_live($this->pdo, 1001));
        $this->assertSame(['user1@bluesoft.co.kr'], $this->pdo->query("SELECT token_user FROM bcl_nodes")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** bcl_node_heartbeat: 살아 있는 다른 token_user 의 node_id 는 403, 노드 그대로 */
    public function testHeartbeatOtherUserLiveNode403()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $ctx['now'] = 1003;
        $ctx['user'] = ['email' => 'user2@bluesoft.co.kr'];
        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc2', 'addr' => '10.0.0.9:1', 'free_cpu' => 40, 'free_mem' => 30, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 403, 'data' => ['reason' => '다른 사용자의 노드']], $res);
        $this->assertSame([['user1@bluesoft.co.kr', '127.0.0.1:1', 1000]], array_map(
            fn($row) => [$row['token_user'], $row['addr'], (int)$row['last_seen']],
            $this->pdo->query("SELECT token_user, addr, last_seen FROM bcl_nodes")->fetchAll()
        ));
    }

    /** bcl_node_heartbeat: last_seen 이 BCL_NODE_TTL_S 보다 오래된 다른 token_user 의 node_id 는 넘겨받음 */
    public function testHeartbeatOtherUserStaleNodeTakenOver()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $ctx['now'] = 1004;
        $ctx['user'] = ['email' => 'user2@bluesoft.co.kr'];
        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc2', 'addr' => '10.0.0.9:1', 'free_cpu' => 40, 'free_mem' => 30, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $this->assertSame([['user2@bluesoft.co.kr', '10.0.0.9:1', 1004]], array_map(
            fn($row) => [$row['token_user'], $row['addr'], (int)$row['last_seen']],
            $this->pdo->query("SELECT token_user, addr, last_seen FROM bcl_nodes")->fetchAll()
        ));
    }

    /** bcl_node_heartbeat: 대소문자만 다른 node_id 는 다른 노드 */
    public function testHeartbeatCaseSensitiveNodeId()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'pc-a', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:2', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $this->assertSame([['PC-A', '127.0.0.1:2'], ['pc-a', '127.0.0.1:1']],
            array_map(fn($node) => [$node['node_id'], $node['addr']], bcl_nodes_live($this->pdo, 1000)));
    }

    /** bcl_nodes_live: last_seen 이 BCL_NODE_TTL_S 안인 노드만, attempts 포함 */
    public function testLiveNodesTtl()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];
        bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 1, 'attempts' => ['job-1/p1/1'],
        ]));

        $live = bcl_api_handle($ctx, 'GET', '/nodes/live', '');
        $ctx['now'] = 1004;
        $gone = bcl_api_handle($ctx, 'GET', '/nodes/live', '');

        $this->assertSame(200, $live['status']);
        $this->assertSame(['PC-A'], array_column($live['data']['nodes'], 'node_id'));
        $this->assertSame(['job-1/p1/1'], $live['data']['nodes'][0]['attempts']);
        $this->assertSame(['status' => 200, 'data' => ['nodes' => []]], $gone);
    }

    /** bcl_node_heartbeat: attempts 없음 */
    public function testHeartbeatWithoutAttempts400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0,
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => '필수 값 없음: attempts']], $res);
    }

    /** bcl_route_heartbeat: node_id 형식 오류, 노드 기록 없음 */
    public function testHeartbeatBadNodeId400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A ', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'node_id 값 오류: PC-A ']], $res);
        $this->assertSame([], bcl_nodes_live($this->pdo, 1000));
    }

    /** bcl_route_heartbeat: 문자열이 아닌 account 는 400 */
    public function testHeartbeatAccountNotString400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => ['acc'], 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'account 값 오류: ["acc"]']], $res);
    }

    /** bcl_route_heartbeat: 문자열이 아닌 addr 는 400 */
    public function testHeartbeatAddrNotString400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => 8080, 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'addr 값 오류: 8080']], $res);
    }

    /** bcl_route_heartbeat: 숫자가 아닌 free_cpu 는 400 */
    public function testHeartbeatFreeCpuNotNumber400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => '50', 'free_mem' => 50, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'free_cpu 값 오류: 50']], $res);
    }

    /** bcl_route_heartbeat: true 인 free_mem 은 400 */
    public function testHeartbeatFreeMemBool400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => true, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'free_mem 값 오류: 1']], $res);
    }

    /** bcl_route_heartbeat: null 인 running 은 400 */
    public function testHeartbeatRunningNull400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => null, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'running 값 오류: null']], $res);
    }

    /** bcl_route_heartbeat: 소수 free_cpu·free_mem 은 받음 */
    public function testHeartbeatFloatNumbers()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 12.5, 'free_mem' => 40.4, 'running' => 0, 'attempts' => [],
        ]));

        $this->assertSame(['status' => 200, 'data' => ['ok' => true]], $res);
        $live = bcl_nodes_live($this->pdo, 1000);
        $this->assertSame([12.5, 40], [$live[0]['free_cpu'], $live[0]['free_mem']]);
    }

    /** bcl_route_heartbeat: 목록이 아닌 attempts 는 400, 노드 기록 없음 */
    public function testHeartbeatAttemptsNotList400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => ['a' => 'job-1/p1/1'],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'attempts 값 오류: {"a":"job-1/p1/1"}']], $res);
        $this->assertSame([], bcl_nodes_live($this->pdo, 1000));
    }

    /** bcl_route_heartbeat: 문자열 목록이 아닌 attempts 는 400 */
    public function testHeartbeatAttemptsItemNotString400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => ['job-1/p1/1', 7],
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'attempts 값 오류: ["job-1/p1/1",7]']], $res);
    }

    /** bcl_route_heartbeat: 문자열인 attempts 는 400 */
    public function testHeartbeatAttemptsString400()
    {
        $ctx = ['pdo' => $this->pdo, 'user' => ['email' => 'user1@bluesoft.co.kr'], 'now' => 1000, 'files_dir' => $this->filesDir];

        $res = bcl_api_handle($ctx, 'POST', '/nodes/heartbeat', json_encode([
            'node_id' => 'PC-A', 'account' => 'acc', 'addr' => '127.0.0.1:1', 'free_cpu' => 50, 'free_mem' => 50, 'running' => 0, 'attempts' => 'job-1/p1/1',
        ]));

        $this->assertSame(['status' => 400, 'data' => ['reason' => 'attempts 값 오류: job-1/p1/1']], $res);
    }

    /** bcl_files_dir: config 의 bluecluster.files_dir */
    public function testFilesDirFromConfig()
    {
        $this->assertSame('/data/bcl', bcl_files_dir(['bluecluster' => ['files_dir' => '/data/bcl/']]));
    }

    /** bcl_files_dir: bluecluster.files_dir 가 없으면 /var/www/iworks-data/bluecluster */
    public function testFilesDirDefault()
    {
        $this->assertSame('/var/www/iworks-data/bluecluster', bcl_files_dir(['db' => []]));
    }
}

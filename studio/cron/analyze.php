<?php
/**
 * 분석 작업 워커. crontab 에 1분 주기로 등록합니다.
 *
 *   * * * * *  /usr/bin/php /home/blueapp_core/studio/cron/analyze.php >> /var/log/bs-analyze.log 2>&1
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 웹 요청이 아니라 크론인가                                      │
 * │                                                                  │
 * │ IA 항목이 100개면 피그마·드라이브를 100번 부른다. 한 번에 몇      │
 * │ 초씩이라 웹 요청 안에서는 타임아웃으로 죽는다. 중간에 끊기면      │
 * │ 어디까지 했는지도 모른다.                                          │
 * │                                                                  │
 * │ bluecart/cron 이 쓰는 방식 그대로다.                              │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 한 번 돌 때 정해진 시간만 일하고 물러난다. 1분마다 다시 불리므로 남은
 * 일은 다음 번에 이어 간다 — 한 번에 다 하려다 오래 매달리지 않는다.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('명령줄에서만 실행할 수 있습니다.');
}

require_once __DIR__ . '/../inc/bootstrap.php';
require_once BS_ROOT . '/inc/repo/JobRepo.php';
require_once BS_ROOT . '/inc/service/DocumentParser.php';
require_once BS_ROOT . '/inc/service/OfficeDocumentParser.php';
require_once BS_ROOT . '/inc/service/Integration.php';
require_once BS_ROOT . '/inc/service/RemoteSource.php';
require_once BS_ROOT . '/inc/service/LinkAnalyzer.php';

/** 한 번 돌 때 최대로 머무는 시간(초). 다음 크론과 겹치지 않게 넉넉히 짧게. */
const RUN_SECONDS = 50;

/**
 * 바깥 호출 사이에 쉬는 시간(밀리초).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 전에는 이게 없었다                                                │
 * │                                                                  │
 * │ 2026-10-04 로그에 `미룸` 이 **같은 1초 안에** 수십 줄 찍혔다.     │
 * │ 429 를 받고도 쉬지 않고 다음 링크를 두드린 것이다. 그렇게 상대의  │
 * │ 제한을 계속 때리면 벌칙이 길어지기만 한다.                        │
 * │                                                                  │
 * │ 묶어 받기 덕에 호출 수 자체가 수십 분의 일로 줄었으니, 한 번에    │
 * │ 1초씩 쉬어도 전체 시간은 오히려 짧다.                             │
 * └──────────────────────────────────────────────────────────────────┘
 */
const PACE_MS = 1000;

$pdo  = bs_db();
$jobs = new JobRepo($pdo);
$log  = static function (string $m): void {
    fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $m . PHP_EOL);
};

$job = $jobs->claimNext();
if ($job === null) {
    exit(0);                       // 할 일 없음. 조용히 끝낸다
}

$jobId     = (int)$job['id'];
$projectId = (int)$job['project_id'];
$log("작업 #$jobId 시작 (프로젝트 $projectId, {$job['kind']})");

$until = time() + RUN_SECONDS;

try {
    if ($job['kind'] === 'links') {
        $an    = new LinkAnalyzer($pdo);
        $store = new Integration($pdo);

        /**
         * 이 회차를 여기서 끝내고 큐로 되돌린다.
         *
         * **호출 제한을 만나면 다음 링크로 넘어가지 않는다.** 전에는 넘어
         * 갔고, 그래서 429 하나가 수십 번의 추가 429 를 불렀다.
         */
        $standDown = static function (string $why) use ($jobs, $jobId, $log): never {
            $jobs->finish($jobId, 'queued', $why);
            $log("작업 #$jobId 물러남 — $why");
            exit(0);
        };
        $pace = static function (): void { usleep(PACE_MS * 1000); };

        while (time() < $until) {
            // 사람이 멈췄는지 매번 본다. 멈춤이 바로 먹혀야 한다.
            $now = $jobs->find($jobId);
            if ($now === null || $now['status'] === 'canceled') {
                $log("작업 #$jobId 멈춤");
                exit(0);
            }

            // ┌──────────────────────────────────────────────────────┐
            // │ 기다리면 될 일만 미룬다                                │
            // │                                                      │
            // │ 쉬는 중·꺼 둠·하루 상한은 **기다리면 풀린다.** 그런    │
            // │ 연동의 링크는 건드리지 않고 대기로 둔다. 피그마가 2일 │
            // │ 쉬는 중이어도 구글은 멀쩡하므로 한쪽만 미룬다.        │
            // │                                                      │
            // │ 반면 **연결이 아예 안 된 것은 기다려도 안 풀린다.**   │
            // │ 사람이 토큰을 넣어야 한다. 그런 링크는 집어서 '실패'  │
            // │ 로 못 박아야 관리자가 목록에서 사유를 보고 고친다 —   │
            // │ 대기로 두면 영영 오지 않을 것을 기다리게 된다.        │
            // └──────────────────────────────────────────────────────┘
            $skip      = [];
            $blockedBy = [];
            foreach ([Integration::FIGMA, Integration::GOOGLE] as $p) {
                $why = $store->blockedReason($p);
                if ($why !== null && $store->isReady($p)) {
                    $skip[]      = $p;
                    $blockedBy[] = $why;
                }
            }

            // ---------------------------------------------------------
            // 1) 피그마는 **묶어서** 받는다. 호출 한 번에 수십 건.
            // ---------------------------------------------------------
            if (!in_array(Integration::FIGMA, $skip, true)) {
                try {
                    $b = $an->figmaBatchOnce($projectId);
                    if ($b !== null) {
                        $jobs->progressBy($jobId, $b['ok'], $b['fail']);
                        $log(sprintf('  묶음  %s — %d개 물어 읽음 %d · 실패 %d',
                                     $b['file'], $b['asked'], $b['ok'], $b['fail']));
                        $pace();
                        continue;
                    }
                } catch (RemoteSourceError $e) {
                    if ($e->retryable) {
                        // 호출 제한·상대 서버 오류. 쉬는 시각은 이미 DB 에 박혔다.
                        // **다음 묶음으로 넘어가지 않는다.** 이 회차는 여기서 끝.
                        $standDown($e->getMessage());
                    }
                    // 설정 문제는 묶음으로 풀리지 않는다. 아래 한 건씩 경로가
                    // 링크마다 사유를 적게 둔다.
                    $log('  묶음 실패 — ' . $e->getMessage());
                }
            }

            // ---------------------------------------------------------
            // 2) 나머지(구글, node-id 없는 피그마)는 한 건씩.
            // ---------------------------------------------------------
            $link = $an->nextPending($projectId, $skip);
            if ($link === null) {
                $left = $an->pendingCount($projectId);
                if ($left > 0) {
                    // 대기는 남았는데 집을 것이 없다 = 전부 쉬는 중이거나
                    // 꺼져 있다. 끝났다고 하면 안 된다 — 큐로 되돌린다.
                    $standDown($blockedBy !== []
                        ? sprintf('%d건 대기 — %s', $left, implode(' / ', $blockedBy))
                        : sprintf('%d건이 남았습니다. 쉬었다가 이어서 진행합니다.', $left));
                }
                $jobs->finish($jobId, 'done', sprintf(
                    '%d건 중 %d건 실패', (int)$now['done'], (int)$now['failed']
                ));
                $log("작업 #$jobId 끝");
                $store->pruneUsage();        // 오래된 호출 기록을 가끔 턴다
                exit(0);
            }

            $r = $an->fetchOne($link);
            if ($r === 'retry') {
                // 기다리면 될 일이다. **다음 링크로 넘어가지 않는다** —
                // 넘어가 봐야 같은 벽에 부딪히고 상대의 제한만 길어진다.
                $standDown($an->lastMessage() !== ''
                    ? $an->lastMessage()
                    : '잠시 쉬었다가 이어서 진행합니다.');
            }
            $jobs->progress($jobId, $r === 'ok');
            $log(($r === 'ok' ? '  읽음  ' : '  실패  ') . mb_substr((string)$link['url'], 0, 80));
            $pace();
        }
        // 시간이 다 됐다. 대기로 돌려 다음 크론이 이어 가게 한다.
        $jobs->finish($jobId, 'queued', '이어서 진행합니다.');
        $log("작업 #$jobId 시간 종료 — 다음 회차에 이어 감");
        exit(0);
    }

    // =====================================================================
    // 난이도 판정
    //
    // ┌──────────────────────────────────────────────────────────────────┐
    // │ 링크 읽기와 같은 틀을 쓴다                                        │
    // │                                                                  │
    // │ 태스크가 100개면 모델을 100번 부른다. 웹 요청 안에서는 못 한다.   │
    // │ 호출 제한·크레딧·비용도 피그마와 똑같이 다뤄야 한다 — 그래서     │
    // │ 같은 큐, 같은 물러남 규칙, 같은 사용량 기록을 쓴다.               │
    // │                                                                  │
    // │ 다만 **AI 가 막혀도 멈추지 않는다.** 규칙 판정이 바탕으로 늘     │
    // │ 돌기 때문에 난이도는 어떻든 채워진다. 물러나는 것은 '기다리면    │
    // │ 될 일' 일 때뿐이다.                                               │
    // └──────────────────────────────────────────────────────────────────┘
    // =====================================================================
    if ($job['kind'] === 'difficulty') {
        require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
        require_once BS_ROOT . '/inc/repo/TaskRepo.php';
        require_once BS_ROOT . '/inc/service/DifficultyScorer.php';

        $tasks   = new TaskRepo($pdo);
        $an      = new LinkAnalyzer($pdo);
        $scorer  = new DifficultyScorer();
        $project = (new ProjectRepo($pdo))->find($projectId);
        $pname   = (string)($project['name'] ?? '');

        // 다시 매기기로 넣은 작업인지는 message 에 적어 두었다.
        $redo = str_contains((string)($job['message'] ?? ''), 'redo');
        $todo = $tasks->pendingDifficulty($projectId, $redo);

        if ($todo === []) {
            $jobs->finish($jobId, 'done', '매길 태스크가 없습니다.');
            $log("작업 #$jobId 끝 — 대상 없음");
            exit(0);
        }

        $ok = $aiCount = 0;
        foreach ($todo as $t) {
            if (time() >= $until) {
                $jobs->finish($jobId, 'queued', '이어서 진행합니다.');
                $log("작업 #$jobId 시간 종료 — 다음 회차에 이어 감");
                exit(0);
            }
            $now = $jobs->find($jobId);
            if ($now === null || $now['status'] === 'canceled') {
                $log("작업 #$jobId 멈춤");
                exit(0);
            }

            $ctx = $an->contextFor($projectId, (string)$t['title']);
            $r   = $scorer->score($t, $ctx['text'] ?? '', $pname);

            $note = $r['note'];
            if ($ctx !== null) {
                // 어느 기획 글을 보고 매겼는지 남긴다. 어림짐작으로 고른
                // 것이라, 틀렸을 때 사람이 바로 알아볼 수 있어야 한다.
                $note = mb_substr($note . ' / 참고: ' . ($ctx['title'] ?: $ctx['url']), 0, 500);
            }
            $tasks->setDifficulty((int)$t['id'], (int)$r['difficulty'], $r['by'], $note);

            $ok++;
            if ($r['by'] === 'ai') {
                $aiCount++;
            }
            $jobs->progress($jobId, true);
            $log(sprintf('  난이도 ★%d (%s) %s',
                 $r['difficulty'], $r['by'], mb_substr((string)$t['title'], 0, 50)));

            // AI 를 실제로 쓴 회차만 쉰다. 규칙으로 떨어진 건은 네트워크를
            // 타지 않으므로 쉴 이유가 없다 — 쉬면 괜히 느려진다.
            if ($r['by'] === 'ai') {
                usleep(PACE_MS * 1000);
            }
        }

        $jobs->finish($jobId, 'done', sprintf('%d건 — AI %d · 규칙 %d', $ok, $aiCount, $ok - $aiCount));
        $log("작업 #$jobId 끝 — $ok 건 (AI $aiCount)");
        $store = new Integration($pdo);
        $store->pruneUsage();
        exit(0);
    }

    $jobs->finish($jobId, 'failed', '모르는 작업 종류입니다: ' . (string)$job['kind']);
    $log("작업 #$jobId 모르는 종류");
    exit(1);

} catch (Throwable $e) {
    // 작업 하나가 통째로 죽어도 큐는 살아 있어야 한다.
    error_log('[BlueStudio] cron/analyze #' . $jobId . ': ' . $e);
    $jobs->finish($jobId, 'failed', '처리 중 오류가 났습니다: ' . $e->getMessage());
    $log("작업 #$jobId 실패 — " . $e->getMessage());
    exit(1);
}

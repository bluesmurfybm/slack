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
require_once BS_ROOT . '/inc/service/LinkAnalyzer.php';

/** 한 번 돌 때 최대로 머무는 시간(초). 다음 크론과 겹치지 않게 넉넉히 짧게. */
const RUN_SECONDS = 50;

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
        $an = new LinkAnalyzer($pdo);
        while (time() < $until) {
            // 사람이 멈췄는지 매번 본다. 멈춤이 바로 먹혀야 한다.
            $now = $jobs->find($jobId);
            if ($now === null || $now['status'] === 'canceled') {
                $log("작업 #$jobId 멈춤");
                exit(0);
            }

            $link = $an->nextPending($projectId);
            if ($link === null) {
                // 대기는 남았는데 집을 것이 없다 = 전부 쉬는 중(호출 제한).
                // 끝났다고 하면 안 된다 — 큐로 되돌려 다음 회차에 이어 간다.
                if ($an->pendingCount($projectId) > 0) {
                    $jobs->finish($jobId, 'queued', sprintf(
                        '호출 제한으로 쉬는 중입니다. %d분 뒤 다시 시도합니다.',
                        LinkAnalyzer::RETRY_AFTER_MINUTES
                    ));
                    $log("작업 #$jobId 대기 — 호출 제한");
                    exit(0);
                }
                $jobs->finish($jobId, 'done', sprintf(
                    '%d건 중 %d건 실패', (int)$now['done'], (int)$now['failed']
                ));
                $log("작업 #$jobId 끝");
                exit(0);
            }

            $r = $an->fetchOne($link);
            if ($r === 'retry') {
                // 아직 안 끝났다. 진행률을 올리면 되시도할 때 두 번 세어
                // done 이 total 을 넘는다. 살아 있다는 신호만 보낸다.
                $jobs->beat($jobId);
                $log('  미룸  ' . mb_substr((string)$link['url'], 0, 80));
                continue;
            }
            $jobs->progress($jobId, $r === 'ok');
            $log(($r === 'ok' ? '  읽음  ' : '  실패  ') . mb_substr((string)$link['url'], 0, 80));
        }
        // 시간이 다 됐다. 대기로 돌려 다음 크론이 이어 가게 한다.
        $jobs->finish($jobId, 'queued', '이어서 진행합니다.');
        $log("작업 #$jobId 시간 종료 — 다음 회차에 이어 감");
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

<?php
/**
 * Slack Lists → DB 증분 동기화.
 *  - items.list 는 created순 정렬이라 API 페이지네이션은 전체를 받지만,
 *    updated_timestamp 가 마지막 동기화 이후인 항목만 DB upsert (이름변환 비용 절감)
 *  - locked=1(로컬 수정) 항목은 보존
 *  전체 재스캔: ?full=1 (브라우저) 또는 CLI 인자 full
 *  호출: 브라우저(로그인 필요) 또는 CLI
 */
require_once __DIR__ . '/slack_lib.php';
require_once __DIR__ . '/db.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/auth.php';
    require_login();
    session_release();   // 세션 잠금 즉시 해제(느린 Slack 동기화 동안 경합 방지)
    header('Content-Type: application/json; charset=utf-8');
}

// 전체 재스캔 여부
$full = $isCli
    ? (isset($argv[1]) && $argv[1] === 'full')
    : (isset($_GET['full']) && $_GET['full'] == '1');

// 중복 실행 방지 잠금 (스케줄러 + 페이지 트리거 + 수동 버튼이 겹치지 않게)
$lockFp = fopen(sys_get_temp_dir() . '/slackapi_sync.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $msg = ['ok' => true, 'skipped' => 'locked'];   // 이미 다른 동기화 진행 중
    echo $isCli ? "이미 동기화 진행 중 → 스킵\n" : json_encode($msg, JSON_UNESCAPED_UNICODE);
    exit;
}
// $lockFp 는 스크립트 종료 시 자동 해제

$cfg = require __DIR__ . '/config.php';
$pdo = db();
$now = date('Y-m-d H:i:s');

// Slack 호출 토큰: 웹은 로그인 사용자 토큰(세션), CLI는 환경변수 SLACK_TOKEN
$token = $isCli ? (getenv('SLACK_TOKEN') ?: '') : current_token();
if (!$token) {
    echo $isCli
        ? "토큰 없음: 환경변수 SLACK_TOKEN 을 설정해 실행하세요 (예: SLACK_TOKEN=xoxp-... php sync.php)\n"
        : json_encode(['ok' => false, 'error' => '로그인 토큰이 없습니다. 다시 로그인하세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== 리스트(레코드) 증분 동기화 =====
$listSince = $full ? 0 : (int)meta_get('list_updated_max', 0);
$data = slackFetchRows($token, $cfg['list_id'], $listSince);

if (isset($data['error'])) {
    echo json_encode(['ok' => false, 'error' => $data['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

// locked 된 ID 목록 (로컬 수정 보존 대상)
$locked = [];
foreach ($pdo->query("SELECT id FROM requests WHERE locked = 1") as $r) {
    $locked[$r['id']] = true;
}

$insertSql = "
    INSERT INTO requests
        (id, title, body, momo, lms, req_id, req, asg_id, asg, status_id, status, priority_id, priority, team_id, team,
         `eta`, `date`, `done`, created, updated, synced_at)
    VALUES
        (:id, :title, :body, :momo, :lms, :req_id, :req, :asg_id, :asg, :status_id, :status, :priority_id, :priority, :team_id, :team,
         :eta, :date, :done, :created, :updated, :synced_at)
    ON DUPLICATE KEY UPDATE
        title=VALUES(title), body=VALUES(body), momo=VALUES(momo), lms=VALUES(lms),
        req_id=VALUES(req_id), req=VALUES(req), asg_id=VALUES(asg_id), asg=VALUES(asg),
        status_id=VALUES(status_id), status=VALUES(status),
        priority_id=VALUES(priority_id), priority=VALUES(priority),
        team_id=VALUES(team_id), team=VALUES(team),
        `eta`=VALUES(`eta`), `date`=VALUES(`date`), `done`=VALUES(`done`),
        updated=VALUES(updated), synced_at=VALUES(synced_at)
";
$stmt = $pdo->prepare($insertSql);
$prevStmt = $pdo->prepare("SELECT `updated` FROM requests WHERE id = ?");

$inserted = 0; $updated = 0; $skipped = 0;
$changedIds = [];   // 실제 갱신된 항목 → 읽음 기록 초기화(다시 안읽음) 대상
foreach ($data['rows'] as $row) {
    if (isset($locked[$row['id']])) { $skipped++; continue; }

    // 실제 변경 판정: 신규거나, Slack updated_timestamp 가 저장값보다 클 때만 '변경'
    $prevStmt->execute([$row['id']]);
    $prev = $prevStmt->fetchColumn();           // 없으면 false
    if ($prev === false)                 { $inserted++; }
    elseif ((int)$row['updated'] > (int)$prev) { $updated++; $changedIds[] = $row['id']; }
    // else: 경계 항목 등 실제 변화 없음 → 카운트 안 함(upsert는 synced_at 갱신 위해 그대로 수행)

    $stmt->execute([
        ':id'        => $row['id'],
        ':title'     => $row['title'],
        ':body'      => $row['body'],
        ':momo'      => $row['momo'],
        ':lms'       => $row['lms'],
        ':req_id'    => $row['req_id'],
        ':req'       => $row['req'],
        ':asg_id'    => $row['asg_id'],
        ':asg'       => $row['asg'],
        ':status_id'   => $row['status_id'],
        ':status'      => $row['status'],
        ':priority_id' => $row['priority_id'],
        ':priority'    => $row['priority'],
        ':team_id'     => $row['team_id'],
        ':team'        => $row['team'],
        ':eta'       => $row['eta'] ?: null,
        ':date'      => $row['date'] ?: null,
        ':done'      => $row['done'] ?: null,
        ':created'   => $row['created'],
        ':updated'   => $row['updated'],
        ':synced_at' => $now,
    ]);
}
// 다음 증분 기준 = 이번에 본 최대 updated_timestamp
if (!empty($data['maxUpdated'])) meta_set('list_updated_max', $data['maxUpdated']);

// 갱신된 항목은 모든 사용자에게 다시 '안읽음'으로 (읽음 기록 삭제)
if ($changedIds) {
    $ph = implode(',', array_fill(0, count($changedIds), '?'));
    $pdo->prepare("DELETE FROM user_reads WHERE request_id IN ($ph)")->execute($changedIds);
}

// 댓글 수 스캔 (비용 큼) — 전체동기화이거나 마지막 스캔 5분 경과 시에만
$cmtCh = $cfg['comment_channel'] ?? '';
if ($cmtCh !== '' && ($full || (time() - (int)meta_get('cmt_scan_at', 0)) >= 300)) {
    $cc = slackCommentCounts($token, $cmtCh);
    if (empty($cc['error'])) {
        $pdo->exec("UPDATE requests SET cmt_count = 0");
        $cu = $pdo->prepare("UPDATE requests SET cmt_count = ? WHERE id = ?");
        foreach ($cc['counts'] as $rid => $n) { if ($n > 0) $cu->execute([$n, $rid]); }
        meta_set('cmt_scan_at', time());
        meta_set('data_changed_at', time());   // 댓글 수 변동 → 화면 새로고침 트리거
    }
}

// 완료시각 기록. 실제 변경이 있을 때만 data_changed_at 갱신(화면 자동 새로고침 트리거)
$completed = time();
meta_set('last_synced_at', $completed);
if (($inserted + $updated) > 0) meta_set('data_changed_at', $completed);

$result = [
    'ok'       => true,
    'mode'     => $full ? 'full' : 'incremental',
    'scanned'  => isset($data['scanned']) ? $data['scanned'] : count($data['rows']),
    'changed'  => $inserted + $updated,   // 실제 변경(신규+갱신) 건수
    'inserted' => $inserted,
    'updated'  => $updated,
    'skipped'  => $skipped,   // 로컬 수정 보존
    'synced_at'=> $now,
];

if ($isCli) {
    echo "[{$result['mode']}] 스캔 {$result['scanned']}건 중 변경 {$result['changed']}건 "
       . "(신규 {$inserted}, 갱신 {$updated}, 보존 {$skipped})\n";
} else {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

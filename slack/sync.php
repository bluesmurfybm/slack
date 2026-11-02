<?php
/**
 * Slack Lists → DB 증분 동기화 (여러 리스트 통합: boards.php).
 *  - 리스트별 증분 워터마크(list_updated_max_<listId>)
 *  - locked=1(로컬 수정) 보존, 변경 항목은 user_reads 삭제(안읽음 복귀)
 *  - 보드별 댓글 채널 스캔으로 cmt_count 갱신
 *  전체 재스캔: ?full=1 또는 CLI 인자 full
 */
require_once __DIR__ . '/slack_lib.php';
require_once __DIR__ . '/db.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/auth.php';
    require_login();
    session_release();
    header('Content-Type: application/json; charset=utf-8');
}

$full = $isCli ? (isset($argv[1]) && $argv[1] === 'full') : (isset($_GET['full']) && $_GET['full'] == '1');

$lockFp = fopen(slack_runtime_dir() . '/slackapi_sync.lock', 'c');   // 웹·데몬 공용 잠금(slack_lib.php)
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $m = ['ok' => true, 'skipped' => 'locked'];
    echo $isCli ? "이미 동기화 진행 중 → 스킵\n" : json_encode($m, JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg    = require __DIR__ . '/../config.php';
$boards = require __DIR__ . '/boards.php';
$pdo    = db();
$now    = date('Y-m-d H:i:s');
$token  = $isCli ? (getenv('SLACK_TOKEN') ?: '') : current_token();
if (!$token) {
    echo $isCli
        ? "토큰 없음: SLACK_TOKEN 환경변수 설정 후 실행\n"
        : json_encode(['ok' => false, 'error' => '로그인 토큰이 없습니다. 다시 로그인하세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$locked = [];
foreach ($pdo->query("SELECT id FROM requests WHERE locked = 1") as $r) $locked[$r['id']] = true;

$insertSql = "
    INSERT INTO requests
        (id, list_id, board, title, body, momo, lms, req_id, req, asg_id, asg, status_id, status,
         priority_id, priority, team_id, team, kind_id, kind, `eta`, `date`, `done`, attachments, created, updated, synced_at)
    VALUES
        (:id,:list_id,:board,:title,:body,:momo,:lms,:req_id,:req,:asg_id,:asg,:status_id,:status,
         :priority_id,:priority,:team_id,:team,:kind_id,:kind,:eta,:date,:done,:attachments,:created,:updated,:synced_at)
    ON DUPLICATE KEY UPDATE
        list_id=VALUES(list_id), board=VALUES(board), title=VALUES(title), body=VALUES(body),
        momo=VALUES(momo), lms=VALUES(lms), req_id=VALUES(req_id), req=VALUES(req),
        asg_id=VALUES(asg_id), asg=VALUES(asg), status_id=VALUES(status_id), status=VALUES(status),
        priority_id=VALUES(priority_id), priority=VALUES(priority), team_id=VALUES(team_id), team=VALUES(team),
        kind_id=VALUES(kind_id), kind=VALUES(kind),
        `eta`=VALUES(`eta`), `date`=VALUES(`date`), `done`=VALUES(`done`),
        attachments=VALUES(attachments), updated=VALUES(updated), synced_at=VALUES(synced_at)
";
$stmt     = $pdo->prepare($insertSql);
$prevStmt = $pdo->prepare("SELECT `updated` FROM requests WHERE id = ?");
// locked(로컬 수정) 항목도 접수분류는 로컬 편집 대상이 아니므로 Slack 값으로 갱신
$kindStmt = $pdo->prepare("UPDATE requests SET kind_id = ?, kind = ? WHERE id = ?");

// 운영 MySQL(엄격 모드)은 컬럼보다 긴 값을 자르지 않고 거부한다(1406) — 그 항목 하나 때문에 동기화 전체가
// 매번 실패한다. 컬럼 폭(db.php)에 맞춰 자른다. 담당자 ID 목록은 ID 가 반쪽 나지 않게 쉼표 단위로 자른다.
$fit = fn($s, $n) => $s === null ? null : mb_substr((string)$s, 0, $n);
$fitList = function ($s, $n) {
    if ($s === null || strlen($s) <= $n) return $s;
    $p = strrpos(substr($s, 0, $n + 1), ',');
    return $p === false ? substr($s, 0, $n) : substr($s, 0, $p);
};

// 접수분류 컬럼 신설 → 기존 행 채우기 위해 1회 전체 동기화 (증분은 변경 항목만 처리하므로)
$kindBackfill = !$full && meta_get('kind_backfill_v1') === null;
if ($kindBackfill) $full = true;

$inserted = 0; $updated = 0; $skipped = 0; $scanned = 0; $deleted = 0; $archivedNow = 0;
$changedIds = []; $errors = [];

foreach ($boards as $listId => $b) {
    $since = $full ? 0 : (int)meta_get('list_updated_max_' . $listId, 0);
    $data  = slackFetchRows($token, $listId, $since, $b['col']);
    if (isset($data['error'])) { $errors[$b['label']] = $data['error']; continue; }
    $scanned += $data['scanned'] ?? count($data['rows']);

    foreach ($data['rows'] as $row) {
        // 제목 없는 항목 제외 (보드 설정)
        if (!empty($b['skip_empty_title'])) {
            $t = trim((string)$row['title']);
            if ($t === '' || $t === '(제목 없음)') continue;
        }
        // 고객사(momo 슬롯 캡처) → 제목 앞 [고객사], momo 슬롯은 비움
        if (!empty($b['title_customer'])) {
            $cust = trim((string)($row['momo'] ?? ''));
            if ($cust !== '') $row['title'] = '[' . $cust . '] ' . $row['title'];
            $row['momo'] = '';
        }

        if (isset($locked[$row['id']])) { $kindStmt->execute([$row['kind_id'], $fit($row['kind'], 40), $row['id']]); $skipped++; continue; }

        $prevStmt->execute([$row['id']]);
        $prev = $prevStmt->fetchColumn();
        if ($prev === false)                       { $inserted++; }
        elseif ((int)$row['updated'] > (int)$prev) { $updated++; $changedIds[] = $row['id']; }

        $stmt->execute([
            ':id' => $row['id'], ':list_id' => $listId, ':board' => $b['label'],
            ':title' => $fit($row['title'], 500), ':body' => $row['body'], ':momo' => $fit($row['momo'], 500), ':lms' => $fit($row['lms'], 500),
            ':req_id' => $row['req_id'], ':req' => $fit($row['req'], 120), ':asg_id' => $fitList($row['asg_id'], 255), ':asg' => $fit($row['asg'], 255),
            ':status_id' => $row['status_id'], ':status' => $fit($row['status'], 60),
            ':priority_id' => $row['priority_id'], ':priority' => $fit($row['priority'], 40),
            ':team_id' => $row['team_id'], ':team' => $fit($row['team'], 60),
            ':kind_id' => $row['kind_id'], ':kind' => $fit($row['kind'], 40),
            ':eta' => $row['eta'] ?: null, ':date' => $row['date'] ?: null, ':done' => $row['done'] ?: null,
            ':attachments' => $row['attachments'] ?? null,
            ':created' => $row['created'], ':updated' => $row['updated'], ':synced_at' => $now,
        ]);
    }
    // 라이브에서 빠진 항목 처리 (allIds 비면 안전상 스킵 = 전체삭제 방지)
    if (!empty($data['allIds'])) {
        $liveSet = array_flip($data['allIds']);
        // 보관 해제되어 라이브로 돌아온 항목 → archived=0
        $ph = implode(',', array_fill(0, count($data['allIds']), '?'));
        $pdo->prepare("UPDATE requests SET archived=0 WHERE list_id=? AND archived=1 AND id IN ($ph)")
            ->execute(array_merge([$listId], $data['allIds']));
        // '비보관'인데 라이브에 없는 항목만 검사 → 보관이면 표시, 진짜 삭제면 제거
        $cands = [];
        foreach ($pdo->query("SELECT id FROM requests WHERE list_id=" . $pdo->quote($listId) . " AND archived=0") as $r) {
            if (!isset($liveSet[$r['id']])) $cands[] = $r['id'];
        }
        foreach ($cands as $rid) {
            $info = slackGet('slackLists.items.info', $token, ['list_id' => $listId, 'id' => $rid]);
            $rec  = $info['record'] ?? $info['item'] ?? [];
            if (!empty($info['ok']) && !empty($rec['archived'])) {
                $pdo->prepare("UPDATE requests SET archived=1 WHERE id=?")->execute([$rid]);
                $archivedNow++;
            } elseif (($info['error'] ?? '') === 'record_not_found') {
                // Slack 이 '없다' 고 확답한 것만 지운다. 예전엔 보관 확답이 아니면 다 지워서
                // 일시 오류(ratelimited·네트워크)에도 항목이 DB 에서 사라졌고, 증분 동기화는 바뀐 항목만
                // 다시 넣으므로 전체 동기화 전까지 현황판에서 빠져 있었다.
                $pdo->prepare("DELETE FROM requests WHERE id=?")->execute([$rid]);
                $deleted++;
            }
            // 그 밖(ok 인데 보관 아님 = 아직 살아 있음 / 일시 오류)은 그대로 두고 다음 동기화 때 다시 본다
        }
    }
    if (!empty($data['maxUpdated'])) meta_set('list_updated_max_' . $listId, $data['maxUpdated']);
}

if ($kindBackfill && !$errors) meta_set('kind_backfill_v1', time());

// 삭제된 항목의 사용자 상태(읽음/고정/숨김/배정) 고아 행 정리
if ($deleted > 0) {
    foreach (['user_reads', 'user_pins', 'user_hides', 'local_assignments'] as $t) {
        $pdo->exec("DELETE FROM `$t` WHERE request_id NOT IN (SELECT id FROM requests)");
    }
}

// 변경 항목 → 모든 사용자 안읽음 복귀
if ($changedIds) {
    $ph = implode(',', array_fill(0, count($changedIds), '?'));
    $pdo->prepare("DELETE FROM user_reads WHERE request_id IN ($ph)")->execute($changedIds);
}

// 댓글 수 스캔 (보드별 채널) — 전체이거나 5분 경과 시
if ($full || (time() - (int)meta_get('cmt_scan_at', 0)) >= 300) {
    $anyCmt = false;
    foreach ($boards as $listId => $b) {
        $chn = $b['comment_channel']; if ($chn === '') continue;
        $cc = slackCommentCounts($token, $chn);
        if (!empty($cc['error'])) { $errors['댓글수:' . $b['label']] = $cc['error']; continue; }
        $pdo->prepare("UPDATE requests SET cmt_count = 0 WHERE list_id = ?")->execute([$listId]);
        $cu = $pdo->prepare("UPDATE requests SET cmt_count = ? WHERE id = ?");
        foreach ($cc['counts'] as $rid => $n) { if ($n > 0) $cu->execute([$n, $rid]); }
        $anyCmt = true;
    }
    if ($anyCmt) { meta_set('cmt_scan_at', time()); meta_set('data_changed_at', time()); }
}

$completed = time();
meta_set('last_synced_at', $completed);
if (($inserted + $updated + $deleted) > 0) meta_set('data_changed_at', $completed);

$result = ['ok' => true, 'mode' => $full ? 'full' : 'incremental', 'scanned' => $scanned,
           'changed' => $inserted + $updated, 'inserted' => $inserted, 'updated' => $updated,
           'deleted' => $deleted, 'archived' => $archivedNow, 'skipped' => $skipped, 'errors' => $errors, 'synced_at' => $now];

if ($isCli) {
    echo "[{$result['mode']}] 스캔 {$scanned} 변경 " . ($inserted + $updated)
       . " (신규 {$inserted} 갱신 {$updated} 삭제 {$deleted} 보존 {$skipped})"
       . ($errors ? " 오류:" . json_encode($errors, JSON_UNESCAPED_UNICODE) : "") . "\n";
} else {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

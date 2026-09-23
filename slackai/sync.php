<?php
/**
 * Slack Lists → DB 동기화 (slackai).
 *  slack/sync.php 와의 차이:
 *   - 신규(INSERT) 항목을 감지해 ai_jobs 에 triage 잡을 등록한다 (ai/ai_lib.php::ai_enqueue).
 *   - 단건 모드 item=<Rec> [list=<F…>] : Slack 이벤트(워크플로 커스텀 스텝 → 워커 ingest 잡)가 알려준 항목 1건만
 *     slackLists.items.info 로 받아 같은 정규화로 upsert. 주기 폴링 없이 이벤트로만 최신 상태를 유지하는 기본 경로.
 *   - 댓글 모드 comments=<채널ID> : 그 채널만 훑어 cmt_count 갱신 (댓글 이벤트 수신 시).
 *   - 잠금은 파일 flock 대신 MySQL GET_LOCK('slackai_sync') — Apache 사용자와 워커 CLI 의 %TEMP% 가 달라 파일 락은 서로 안 보인다.
 *   - 댓글 수 전체 스캔은 full 또는 ai_settings.comment_scan_sec 경과 시에만(기본 0 = full 에서만).
 *  실행:
 *   웹  : sync.php[?full=1][&enqueue=1]
 *   CLI : php sync.php [full] [json] [enqueue] [item=Rec… [list=F…]] [comments=C…]   (SLACK_TOKEN 또는 SLACK_BOT_TOKEN 환경변수)
 */
require_once __DIR__ . '/slack_lib.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai/ai_lib.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/auth.php';
    require_login();
    session_release();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

// ---- 인자 ----
$flags = []; $kv = [];
if ($isCli) {
    foreach (array_slice($argv, 1) as $a) {
        if (strpos($a, '=') !== false) { [$k, $v] = explode('=', $a, 2); $kv[$k] = $v; }
        else $flags[] = $a;
    }
} else {
    $kv = $_GET;
}
$full          = $isCli ? in_array('full', $flags, true) : (($kv['full'] ?? '') == '1');
$asJson        = !$isCli || in_array('json', $flags, true);
$enqueueOnFull = $isCli ? in_array('enqueue', $flags, true) : !empty($kv['enqueue']);
$itemId        = trim((string)($kv['item'] ?? ''));
$itemList      = trim((string)($kv['list'] ?? ''));
$cmtChannel    = trim((string)($kv['comments'] ?? ''));
$mode          = $itemId !== '' ? 'item' : ($cmtChannel !== '' ? 'comments' : 'list');

$cfg    = require __DIR__ . '/../config.php';
$boards = require __DIR__ . '/boards.php';
$pdo    = db();
$now    = date('Y-m-d H:i:s');
$by     = $isCli ? 'sync:cli' : ('sync:' . ai_actor());

function sync_out(array $result, bool $asJson, bool $isCli, int $exit = 0) {
    if ($asJson) echo json_encode($result, JSON_UNESCAPED_UNICODE) . ($isCli ? "\n" : '');
    else echo $result['text'] ?? json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit($exit);
}

// ---- 토큰: 웹은 로그인 사용자 개인 토큰, CLI 는 환경변수(개인 xoxp 또는 봇 xoxb — lists:read 필요) ----
$token = $isCli ? (getenv('SLACK_TOKEN') ?: (getenv('SLACK_BOT_TOKEN') ?: '')) : current_token();
if (!$token) {
    sync_out(['ok' => false, 'error' => 'no_token',
              'text' => "토큰 없음: SLACK_TOKEN(또는 SLACK_BOT_TOKEN) 환경변수 설정 후 실행"], $asJson, $isCli, 1);
}

// ---- 잠금: 리스트 모드는 단일 실행, 단건/댓글 모드는 서로만 직렬화(리스트 동기화와는 동시 허용 — 둘 다 upsert) ----
$lockName = $mode === 'list' ? 'slackai_sync' : 'slackai_sync_' . $mode;
$lockWait = $mode === 'list' ? 0 : 20;
$got = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", $lockWait)")->fetchColumn();
if ($got !== 1) {
    sync_out(['ok' => true, 'skipped' => 'locked', 'mode' => $mode, 'text' => "이미 동기화 진행 중 → 스킵"], $asJson, $isCli);
}
register_shutdown_function(function () use ($pdo, $lockName) {
    try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")"); } catch (Throwable $e) {}
});

/** upsert 문 (slack/sync.php 와 동일) */
$insertSql = "
    INSERT INTO requests
        (id, list_id, board, title, body, momo, lms, req_id, req, asg_id, asg, status_id, status,
         priority_id, priority, team_id, team, `eta`, `date`, `done`, attachments, created, updated, synced_at)
    VALUES
        (:id,:list_id,:board,:title,:body,:momo,:lms,:req_id,:req,:asg_id,:asg,:status_id,:status,
         :priority_id,:priority,:team_id,:team,:eta,:date,:done,:attachments,:created,:updated,:synced_at)
    ON DUPLICATE KEY UPDATE
        list_id=VALUES(list_id), board=VALUES(board), title=VALUES(title), body=VALUES(body),
        momo=VALUES(momo), lms=VALUES(lms), req_id=VALUES(req_id), req=VALUES(req),
        asg_id=VALUES(asg_id), asg=VALUES(asg), status_id=VALUES(status_id), status=VALUES(status),
        priority_id=VALUES(priority_id), priority=VALUES(priority), team_id=VALUES(team_id), team=VALUES(team),
        `eta`=VALUES(`eta`), `date`=VALUES(`date`), `done`=VALUES(`done`),
        attachments=VALUES(attachments), updated=VALUES(updated), synced_at=VALUES(synced_at)
";
$stmt     = $pdo->prepare($insertSql);
$prevStmt = $pdo->prepare("SELECT `updated` FROM requests WHERE id = ?");

$locked = [];
foreach ($pdo->query("SELECT id FROM requests WHERE locked = 1") as $r) $locked[$r['id']] = true;

$inserted = 0; $updated = 0; $skipped = 0; $scanned = 0; $deleted = 0; $archivedNow = 0;
$changedIds = []; $newIds = []; $errors = [];

/**
 * 정규화된 행 1개를 보드 규칙에 맞춰 upsert. 신규/갱신을 구분해 카운트한다.
 * @return string|null 'inserted'|'updated'|'skipped'|null(제외)
 */
$applyRow = function (array $row, string $listId, array $b) use ($pdo, $stmt, $prevStmt, &$locked, &$inserted, &$updated, &$skipped, &$changedIds, &$newIds, $now) {
    if (!empty($b['skip_empty_title'])) {
        $t = trim((string)$row['title']);
        if ($t === '' || $t === '(제목 없음)') return null;
    }
    if (!empty($b['title_customer'])) {                  // 고객사(momo 슬롯) → 제목 앞 [고객사], momo 비움
        $cust = trim((string)($row['momo'] ?? ''));
        if ($cust !== '') $row['title'] = '[' . $cust . '] ' . $row['title'];
        $row['momo'] = '';
    }
    if (isset($locked[$row['id']])) { $skipped++; return 'skipped'; }

    $prevStmt->execute([$row['id']]);
    $prev = $prevStmt->fetchColumn();
    $kind = null;
    if ($prev === false)                       { $inserted++; $newIds[] = $row['id']; $kind = 'inserted'; }
    elseif ((int)$row['updated'] > (int)$prev) { $updated++;  $changedIds[] = $row['id']; $kind = 'updated'; }
    else                                       { $kind = 'same'; }

    $stmt->execute([
        ':id' => $row['id'], ':list_id' => $listId, ':board' => $b['label'],
        ':title' => $row['title'], ':body' => $row['body'], ':momo' => $row['momo'], ':lms' => $row['lms'],
        ':req_id' => $row['req_id'], ':req' => $row['req'], ':asg_id' => $row['asg_id'], ':asg' => $row['asg'],
        ':status_id' => $row['status_id'], ':status' => $row['status'],
        ':priority_id' => $row['priority_id'], ':priority' => $row['priority'],
        ':team_id' => $row['team_id'], ':team' => $row['team'],
        ':eta' => $row['eta'] ?: null, ':date' => $row['date'] ?: null, ':done' => $row['done'] ?: null,
        ':attachments' => $row['attachments'] ?? null,
        ':created' => $row['created'], ':updated' => $row['updated'], ':synced_at' => $now,
    ]);
    return $kind;
};

/** 신규 항목 triage 등록 (auto_triage 설정, 보관 아님, 아직 분석 없음). $recentOnly 면 7일 이내 생성만(리스트 모드의 옛 항목 방지) */
$enqueueNew = function (array $ids, bool $recentOnly) use ($pdo, $by) {
    if (!$ids || ai_setting('auto_triage', '1') !== '1') return 0;
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT r.id FROM requests r LEFT JOIN ai_triage t ON t.request_id = r.id
            WHERE r.id IN ($ph) AND r.archived = 0 AND t.request_id IS NULL" . ($recentOnly ? " AND r.created >= ?" : "");
    $st  = $pdo->prepare($sql);
    $st->execute($recentOnly ? array_merge($ids, [time() - 7 * 86400]) : $ids);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        if (ai_enqueue('triage', $rid, ['source' => 'sync', 'reason' => 'new'], $by) !== null) $n++;
    }
    if ($n > 0) {
        meta_set('ai_last_enqueue_at', time());
        ai_event(null, $by, 'job.enqueue', 'ai_jobs', null, ['kind' => 'triage', 'count' => $n, 'ids' => array_slice($ids, 0, 20)]);
    }
    return $n;
};

try {
    // =============================== 단건 모드 ===============================
    if ($mode === 'item') {
        if ($itemList === '') {
            $st = $pdo->prepare("SELECT list_id FROM requests WHERE id = ?"); $st->execute([$itemId]);
            $itemList = (string)($st->fetchColumn() ?: '');
        }
        if ($itemList === '' || !isset($boards[$itemList])) {
            // 리스트를 모르면 보드 전부에서 찾아본다(2개뿐)
            foreach ($boards as $lid => $b0) {
                $probe = slackGet('slackLists.items.info', $token, ['list_id' => $lid, 'id' => $itemId]);
                if (!empty($probe['ok'])) { $itemList = $lid; $info = $probe; break; }
            }
            if ($itemList === '' || !isset($boards[$itemList])) {
                sync_out(['ok' => false, 'error' => 'unknown_list', 'id' => $itemId, 'text' => "항목의 리스트를 찾을 수 없음: $itemId"], $asJson, $isCli, 1);
            }
        }
        $b = $boards[$itemList];
        $info = $info ?? slackGet('slackLists.items.info', $token, ['list_id' => $itemList, 'id' => $itemId]);
        if (empty($info['ok'])) {
            $err = (string)($info['error'] ?? 'unknown');
            if (preg_match('/not_found|record_not_found|item_not_found|invalid_record/i', $err)) {
                $pdo->prepare("DELETE FROM requests WHERE id = ?")->execute([$itemId]);
                foreach (['user_reads', 'user_pins', 'user_hides', 'local_assignments'] as $t) {
                    $pdo->prepare("DELETE FROM `$t` WHERE request_id = ?")->execute([$itemId]);
                }
                meta_set('data_changed_at', time()); meta_set('ai_last_ingest_at', time());
                sync_out(['ok' => true, 'mode' => 'item', 'id' => $itemId, 'deleted' => 1, 'text' => "삭제됨: $itemId"], $asJson, $isCli);
            }
            sync_out(['ok' => false, 'error' => $err, 'mode' => 'item', 'id' => $itemId, 'text' => "Slack 조회 실패: $err"], $asJson, $isCli, 1);
        }
        $rec = $info['record'] ?? ($info['item'] ?? []);
        if (empty($rec['id'])) $rec['id'] = $itemId;
        $data = slackFetchRowsFromItems($token, $itemList, [$rec], 0, $b['col']);
        if (isset($data['error'])) sync_out(['ok' => false, 'error' => $data['error'], 'mode' => 'item', 'text' => "정규화 실패: {$data['error']}"], $asJson, $isCli, 1);

        $kind = null;
        foreach ($data['rows'] as $row) $kind = $applyRow($row, $itemList, $b);
        $isArchived = !empty($rec['archived']) ? 1 : 0;
        $pdo->prepare("UPDATE requests SET archived = ? WHERE id = ?")->execute([$isArchived, $itemId]);
        if ($changedIds) $pdo->prepare("DELETE FROM user_reads WHERE request_id = ?")->execute([$itemId]);

        $enq = $enqueueNew($newIds, false);
        // 갱신 항목 재분석 옵션(내용 해시는 워커가 비교해 같으면 스킵)
        if ($kind === 'updated' && ai_setting('retriage_on_update', '0') === '1') {
            if (ai_enqueue('triage', $itemId, ['source' => 'sync', 'reason' => 'updated'], $by) !== null) $enq++;
        }
        if ($kind !== null && $kind !== 'skipped') meta_set('data_changed_at', time());
        meta_set('ai_last_ingest_at', time());
        if ($newIds || $changedIds) meta_set('ai_changed_at', time());   // 목록 배지 갱신용

        sync_out(['ok' => true, 'mode' => 'item', 'id' => $itemId, 'list_id' => $itemList, 'result' => $kind ?? 'excluded',
                  'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'archived' => $isArchived, 'enqueued' => $enq,
                  'text' => "[item] $itemId → " . ($kind ?? 'excluded') . " (등록 $enq)"], $asJson, $isCli);
    }

    // =============================== 댓글 모드 ===============================
    if ($mode === 'comments') {
        $listId = null;
        foreach ($boards as $lid => $b0) if (($b0['comment_channel'] ?? '') === $cmtChannel) { $listId = $lid; break; }
        if ($listId === null) sync_out(['ok' => false, 'error' => 'unknown_channel', 'text' => "댓글 채널이 보드에 없음: $cmtChannel"], $asJson, $isCli, 1);
        $cc = slackCommentCounts($token, $cmtChannel);
        if (!empty($cc['error'])) sync_out(['ok' => false, 'error' => $cc['error'], 'text' => "댓글 스캔 실패: {$cc['error']}"], $asJson, $isCli, 1);
        $cu = $pdo->prepare("UPDATE requests SET cmt_count = ? WHERE id = ? AND cmt_count <> ?");
        $n = 0;
        $pdo->prepare("UPDATE requests SET cmt_count = 0 WHERE list_id = ?")->execute([$listId]);
        foreach ($cc['counts'] as $rid => $cnt) { if ($cnt > 0) { $cu->execute([$cnt, $rid, $cnt]); $n += $cu->rowCount(); } }
        meta_set('cmt_scan_at', time()); meta_set('data_changed_at', time()); meta_set('ai_last_ingest_at', time());
        sync_out(['ok' => true, 'mode' => 'comments', 'channel' => $cmtChannel, 'list_id' => $listId, 'changed' => $n,
                  'text' => "[comments] $cmtChannel 갱신 $n 건"], $asJson, $isCli);
    }

    // =============================== 리스트(증분/전체) 모드 ===============================
    foreach ($boards as $listId => $b) {
        $since = $full ? 0 : (int)meta_get('list_updated_max_' . $listId, 0);
        $data  = slackFetchRows($token, $listId, $since, $b['col']);
        if (isset($data['error'])) { $errors[$b['label']] = $data['error']; continue; }
        $scanned += $data['scanned'] ?? count($data['rows']);

        foreach ($data['rows'] as $row) $applyRow($row, $listId, $b);

        // 라이브에서 빠진 항목 처리 (allIds 비면 안전상 스킵 = 전체삭제 방지)
        if (!empty($data['allIds'])) {
            $liveSet = array_flip($data['allIds']);
            $ph = implode(',', array_fill(0, count($data['allIds']), '?'));
            $pdo->prepare("UPDATE requests SET archived=0 WHERE list_id=? AND archived=1 AND id IN ($ph)")
                ->execute(array_merge([$listId], $data['allIds']));
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
                } else {
                    $pdo->prepare("DELETE FROM requests WHERE id=?")->execute([$rid]);
                    $deleted++;
                }
            }
        }
        if (!empty($data['maxUpdated'])) meta_set('list_updated_max_' . $listId, $data['maxUpdated']);
    }

    if ($deleted > 0) {
        foreach (['user_reads', 'user_pins', 'user_hides', 'local_assignments'] as $t) {
            $pdo->exec("DELETE FROM `$t` WHERE request_id NOT IN (SELECT id FROM requests)");
        }
    }
    if ($changedIds) {
        $ph = implode(',', array_fill(0, count($changedIds), '?'));
        $pdo->prepare("DELETE FROM user_reads WHERE request_id IN ($ph)")->execute($changedIds);
    }

    // 신규 → triage 등록 (full 임포트는 enqueue 플래그/설정이 있을 때만; 리스트 모드는 7일 이내 생성 항목만)
    $enqueued = 0;
    if ($newIds && (!$full || $enqueueOnFull || ai_setting('enqueue_on_full', '0') === '1')) {
        $enqueued = $enqueueNew($newIds, true);
    }

    // 댓글 수 전체 스캔: full 이거나 comment_scan_sec(기본 0=안 함) 경과 시
    $scanSec = (int)ai_setting('comment_scan_sec', 0);
    if ($full || ($scanSec > 0 && (time() - (int)meta_get('cmt_scan_at', 0)) >= $scanSec)) {
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
    if ($enqueued > 0) meta_set('ai_changed_at', $completed);

    $result = ['ok' => true, 'mode' => $full ? 'full' : 'incremental', 'scanned' => $scanned,
               'changed' => $inserted + $updated, 'inserted' => $inserted, 'updated' => $updated,
               'deleted' => $deleted, 'archived' => $archivedNow, 'skipped' => $skipped, 'enqueued' => $enqueued,
               'errors' => $errors, 'synced_at' => $now];
    $result['text'] = "[{$result['mode']}] 스캔 {$scanned} 변경 " . ($inserted + $updated)
        . " (신규 {$inserted} 갱신 {$updated} 삭제 {$deleted} 보존 {$skipped} 등록 {$enqueued})"
        . ($errors ? " 오류:" . json_encode($errors, JSON_UNESCAPED_UNICODE) : "");
    sync_out($result, $asJson, $isCli);
} catch (Throwable $e) {
    sync_out(['ok' => false, 'error' => $e->getMessage(), 'mode' => $mode, 'text' => "오류: " . $e->getMessage()], $asJson, $isCli, 1);
}

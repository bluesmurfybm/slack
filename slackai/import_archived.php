<?php
/**
 * 보관(archived) 항목 일괄 임포트 (CLI, 재실행 안전).
 *  - items.list 는 보관 항목을 안 주므로, 댓글 백엔드 채널에서 전체 레코드 id 를 열거한 뒤
 *    라이브/기존DB 에 없는 id 를 items.info 로 조회해 archived=1 이면 requests 에 저장.
 *  - 수천 건이면 items.info 를 건당 호출하므로 오래 걸림(수~수십 분). 중단·재실행하면 이어서 진행.
 *  사용:  SLACK_TOKEN=xoxp-... php import_archived.php
 */
require_once __DIR__ . '/slack_lib.php';
require_once __DIR__ . '/db.php';
$boards = require __DIR__ . '/boards.php';

$token = getenv('SLACK_TOKEN') ?: '';
if ($token === '') { fwrite(STDERR, "SLACK_TOKEN 환경변수 필요\n"); exit(1); }
$pdo = db();
$now = date('Y-m-d H:i:s');

$ins = $pdo->prepare("
    INSERT INTO requests
        (id, list_id, board, title, body, momo, lms, req_id, req, asg_id, asg, status_id, status,
         priority_id, priority, team_id, team, `eta`, `date`, `done`, attachments, created, updated, archived, synced_at)
    VALUES
        (:id,:list_id,:board,:title,:body,:momo,:lms,:req_id,:req,:asg_id,:asg,:status_id,:status,
         :priority_id,:priority,:team_id,:team,:eta,:date,:done,:attachments,:created,:updated,1,:synced_at)
    ON DUPLICATE KEY UPDATE archived=1
");

$total = 0;
foreach ($boards as $listId => $b) {
    $COL = $b['col']; $ch = $b['comment_channel'];
    $live = []; foreach (slackFetchItems($token, $listId)['items'] as $it) $live[$it['id']] = 1;
    $dbIds = []; foreach ($pdo->query("SELECT id FROM requests WHERE list_id=" . $pdo->quote($listId)) as $r) $dbIds[$r['id']] = 1;
    $cc = $ch ? slackCommentCounts($token, $ch) : ['counts' => []];
    $allRec = array_keys($cc['counts'] ?? []);
    $sampleId = array_key_first($live);
    $sel = slackSelectMaps($token, $listId, $sampleId);
    $statusMap = $sel[$COL['status']] ?? []; $priMap = $sel[$COL['priority']] ?? []; $teamMap = $sel[$COL['team']] ?? [];

    $arch = 0; $seen = 0;
    $cand = array_values(array_filter($allRec, fn($id) => !isset($live[$id]) && !isset($dbIds[$id])));
    fwrite(STDERR, "[{$b['label']}] 후보 " . count($cand) . "건 조회 시작…\n");
    foreach ($cand as $rid) {
        $seen++;
        $info = slackGet('slackLists.items.info', $token, ['list_id' => $listId, 'id' => $rid]);
        if (empty($info['ok'])) continue;                       // 삭제됨
        $rec = $info['record'] ?? $info['item'] ?? null;
        if (!$rec || empty($rec['archived'])) continue;         // 보관 아님
        $m = slackIndexFields($rec['fields'] ?? []);
        $g = fn($k) => ($COL[$k] !== '' && isset($m[$COL[$k]])) ? $m[$COL[$k]] : null;
        $reqId = ($f = $g('req')) ? slackFieldUser($f) : null;
        $asgId = ($f = $g('asg')) ? slackFieldUser($f) : null;
        $fileIds = ($f = $g('attach')) ? slackFieldAttach($f) : [];
        $names = slackResolveUsers($token, array_filter([$reqId, $asgId]));
        $files = slackResolveFiles($token, $fileIds);
        $title = ($f = $g('title')) ? slackFieldText($f) : '(제목 없음)';
        $momo  = ($f = $g('momo'))  ? slackFieldText($f) : '';
        if (!empty($b['title_customer'])) { $cust = trim($momo); if ($cust !== '') $title = '[' . $cust . '] ' . $title; $momo = ''; }
        if (!empty($b['skip_empty_title'])) { $t = trim($title); if ($t === '' || $t === '(제목 없음)') continue; }
        $sId = ($f = $g('status'))   ? slackFieldSelect($f) : null;
        $pId = ($f = $g('priority')) ? slackFieldSelect($f) : null;
        $tId = ($f = $g('team'))     ? slackFieldSelect($f) : null;
        $atts = []; foreach ($fileIds as $fid) { if (isset($files[$fid]) && empty($files[$fid]['gone'])) $atts[] = $files[$fid]; }
        $ins->execute([
            ':id' => $rid, ':list_id' => $listId, ':board' => $b['label'],
            ':title' => $title, ':body' => ($f = $g('body')) ? slackFieldBody($f) : '', ':momo' => $momo,
            ':lms' => ($f = $g('lms')) ? slackFieldText($f) : '',
            ':req_id' => $reqId, ':req' => $reqId ? ($names[$reqId] ?? $reqId) : '—',
            ':asg_id' => $asgId, ':asg' => $asgId ? ($names[$asgId] ?? $asgId) : '—',
            ':status_id' => $sId, ':status' => $sId ? ($statusMap[$sId] ?? $sId) : '',
            ':priority_id' => $pId, ':priority' => $pId ? ($priMap[$pId] ?? $pId) : '',
            ':team_id' => $tId, ':team' => $tId ? ($teamMap[$tId] ?? $tId) : '',
            ':eta' => ($f = $g('eta')) ? (slackFieldDate($f) ?: null) : null,
            ':date' => ($f = $g('date')) ? (slackFieldDate($f) ?: null) : null,
            ':done' => ($f = $g('done')) ? (slackFieldDate($f) ?: null) : null,
            ':attachments' => $atts ? json_encode($atts, JSON_UNESCAPED_UNICODE) : null,
            ':created' => (int)($rec['date_created'] ?? 0), ':updated' => (int)($rec['updated_timestamp'] ?? 0),
            ':synced_at' => $now,
        ]);
        $arch++;
        if ($seen % 200 === 0) fwrite(STDERR, "  ...{$seen}/" . count($cand) . " (보관 {$arch})\n");
    }
    echo "[{$b['label']}] 보관 임포트 {$arch}건\n";
    $total += $arch;
}
echo "총 {$total}건 임포트 완료\n";

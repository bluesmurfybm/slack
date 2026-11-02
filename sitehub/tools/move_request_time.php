<?php
/**
 * 1회용 변환 — Slack 리스트 'BlueLab-유지보수처리목록'의 '시간 - 작업내용' 앞머리 시간을 '요청일시'(글자 열)로 옮긴다.
 *
 *  리스트의 날짜 열은 시간을 못 담는다(Slack Lists date 는 날짜만). 그래서 '요청일시'를 글자 열로 새로 만들고
 *  기존 날짜 열은 '요청일(날짜)'로 이름만 바꿔 남긴다(열 작업은 Slack 화면/커넥터에서). 이 스크립트는 그 다음 단계 —
 *  각 항목의 [요청일 날짜] + [작업내용 앞머리 시간] → 새 열에 "YYYY-MM-DD HH:MM", 작업내용에서는 그 시간 글자만 뗀다.
 *
 *  CLI : php sitehub/tools/move_request_time.php [apply] [limit=N] [target=Col…]
 *  웹  : sitehub/tools/move_request_time.php (미리보기 JSON) · ?apply=1[&limit=N] (적용) — 통계 권한 계정만(guard sitehub_can_stats)
 *
 *  - 최상위 항목만 (하위 작업은 건드리지 않는다)
 *  - 작업내용은 rich_text 를 직접 고친다 — 앞머리 글자만 떼고 굵게·목록 같은 서식은 그대로. 시간이 링크·이모지와 섞여 있거나
 *    시간이 여러 개('11:55:51, 14:47:28')거나 본문 날짜가 요청일과 다르면 손대지 않고 'review' 로 돌려준다(사람이 판단)
 *  - 적용 전 원본 셀을 var/sitehub/move_time_<시각>.json 에 백업 (gitignore, 웹 직접 접근 차단)
 *  - 여러 번 돌려도 안전 — 이미 옮긴 항목은 앞머리 시간이 없고 새 열 값이 같으면 건너뛴다
 *  - 토큰에 lists:write 가 있어야 적용된다 (미리보기는 lists:read 로 충분)
 */
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../../slack/slack_lib.php';
require_once __DIR__ . '/../db.php';

$isCli = PHP_SAPI === 'cli';
$cfg   = require __DIR__ . '/../../config.php';
$args  = $isCli ? array_slice($argv, 1) : [];
$opt   = function (string $k) use ($isCli, $args) {
    if (!$isCli) return isset($_GET[$k]) ? (string)$_GET[$k] : null;
    foreach ($args as $a) { if ($a === $k) return '1'; if (strncmp($a, $k . '=', strlen($k) + 1) === 0) return substr($a, strlen($k) + 1); }
    return null;
};

if (!$isCli) {
    require_once __DIR__ . '/../guard.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $me = current_portal_user();
    if (!$me || !sitehub_can_stats($me)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => '권한이 없습니다'], JSON_UNESCAPED_UNICODE); exit; }
    sitehub_session_release();
    $token = sitehub_sync_token($me);
} else {
    $token = (string)($cfg['sitehub_slack_token'] ?? '') ?: (string)getenv('SLACK_TOKEN');
}

function mt_out(array $o, bool $isCli) {
    if ($isCli) { echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit(empty($o['ok']) ? 1 : 0); }
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$listId = (string)($cfg['sitehub_list_id'] ?? '');
if ($listId === '' || !$token) mt_out(['ok' => false, 'error' => 'sitehub_list_id 또는 동기화 토큰이 없습니다.'], $isCli);
$apply = (string)$opt('apply') === '1';

/* ── 앞머리 시간 읽기 ─────────────────────────────────────────────────────────────────────
   '13:57:15  간병인…' · '10:15 운영작가…' · '오후 4:20 / 365 두드림…' · '2026. 9. 7. (월) 14:47  스팸…' · '2026-02-03 11:07:54  홈페이지…'
   → ['hm' => 'HH:MM', 'len' => 떼어 낼 글자 수(뒤 구분자 포함), 'date' => 본문에 날짜가 있었으면 YYYY-MM-DD, 'multi' => 시간이 여럿] */
function mt_time_prefix(string $s): ?array {
    $date = null;
    if (preg_match('/^\s*(\d{4})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\.?\s*(?:\([^)]{1,3}\))?\s*(?:(오전|오후)\s*)?(\d{1,2})[:：](\d{2})(?:[:：]\d{2})?/u', $s, $m)) {
        $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        [$ap, $h, $i] = [$m[4], (int)$m[5], (int)$m[6]];
    } elseif (preg_match('/^\s*(?:(오전|오후)\s*)?(\d{1,2})[:：](\d{2})(?:[:：]\d{2})?/u', $s, $m)) {
        [$ap, $h, $i] = [$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return null;
    }
    if ($ap === '오후' && $h < 12) $h += 12;
    if ($ap === '오전' && $h === 12) $h = 0;
    if ($h > 23 || $i > 59) return null;
    $len  = mb_strlen($m[0]);
    $rest = mb_substr($s, $len);
    $multi = (bool)preg_match('/^\s*[,~\-]\s*\d{1,2}[:：]\d{2}/u', $rest);
    preg_match('/^[\s\/,\-–—·|]*/u', $rest, $sep);
    return ['hm' => sprintf('%02d:%02d', $h, $i), 'len' => $len + mb_strlen($sep[0]), 'date' => $date, 'multi' => $multi];
}

/** rich_text 첫 문단의 앞머리 글자(링크·이모지 전까지의 text 요소들) */
function mt_lead_text(array $blocks): ?string {
    $sec = $blocks[0]['elements'][0] ?? null;
    if (!$sec || ($sec['type'] ?? '') !== 'rich_text_section') return null;
    $s = '';
    foreach ($sec['elements'] ?? [] as $e) { if (($e['type'] ?? '') !== 'text') break; $s .= (string)$e['text']; }
    return $s;
}

/** rich_text 첫 문단 앞에서 $n 글자를 뗀다 — 서식 요소는 그대로. 떼고 나서 비면 null(본문을 비우지 않는다) */
function mt_strip_rich(array $blocks, int $n): ?array {
    $sec = $blocks[0]['elements'][0];
    $left = $n; $out = [];
    foreach ($sec['elements'] as $e) {
        if ($left > 0) {
            if (($e['type'] ?? '') !== 'text') return null;
            $len = mb_strlen((string)$e['text']);
            if ($len <= $left) { $left -= $len; continue; }
            $e['text'] = mb_substr((string)$e['text'], $left);
            $left = 0;
        }
        $out[] = $e;
    }
    if ($left > 0) return null;
    $blocks[0]['elements'][0]['elements'] = $out;
    $plain = '';
    foreach ($blocks as $b) foreach ($b['elements'] ?? [] as $el) foreach ($el['elements'] ?? [] as $x) $plain .= (string)($x['text'] ?? 'x');
    if (trim($plain) === '') return null;
    if (!$out) array_shift($blocks[0]['elements']);   // 첫 문단이 시간뿐이었으면 그 문단만 뺀다
    return $blocks;
}

function mt_rich(string $text): array {
    return [['type' => 'rich_text', 'elements' => [['type' => 'rich_text_section', 'elements' => [['type' => 'text', 'text' => $text]]]]]];
}

/* ── 열 찾기 ── 날짜 열(요청일)·작업내용 열은 list_cols.php, 새 '요청일시' 글자 열은 이름으로(또는 target=Col…) */
$COL = require __DIR__ . '/../list_cols.php';
$dateCol = (string)($COL['date_old'] ?? '') ?: (string)$COL['date'];
$bodyCol = (string)$COL['body'];
$all = slackFetchItems($token, $listId);
if (isset($all['error'])) mt_out(['ok' => false, 'error' => 'Slack 리스트를 읽지 못했습니다: ' . $all['error']], $isCli);
$items = $all['items'];
// 스키마는 최상위 항목 하나를 items.info 로 읽어 꺼낸다 (sync.php 와 같은 방법)
$sample = '';
foreach ($items as $it) if (empty($it['parent_record_id'])) { $sample = (string)$it['id']; break; }
$info = slackGet('slackLists.items.info', $token, ['list_id' => $listId, 'id' => $sample]);
$schema = $info['list']['list_metadata']['schema'] ?? [];
if (!$schema) mt_out(['ok' => false, 'error' => '리스트 스키마를 읽지 못했습니다: ' . ($info['error'] ?? '응답에 schema 없음')], $isCli);
// list_cols.php 의 값은 열 key 일 수도 있다 — 항목 fields 는 column_id 로만 오므로 스키마로 ID 를 찾는다 (sync.php sitehub_resolve_cols 와 같은 일)
$toId = function (string $k) use ($schema) { foreach ($schema as $c) if (($c['id'] ?? '') === $k || ($c['key'] ?? '') === $k) return (string)$c['id']; return $k; };
$dateCol = $toId($dateCol);
$bodyCol = $toId($bodyCol);
$target = (string)$opt('target');
if ($target !== '') $target = $toId($target);
$dateType = null;
foreach ($schema as $c) {
    if ($target === '' && ($c['name'] ?? '') === '요청일시' && in_array($c['type'] ?? '', ['text', 'rich_text'], true)) $target = (string)$c['id'];
    if (($c['id'] ?? '') === $dateCol || ($c['key'] ?? '') === $dateCol) $dateType = $c['type'] ?? null;
}
if ($dateType !== 'date') mt_out(['ok' => false, 'error' => "요청일 날짜 열({$dateCol})을 못 찾았습니다 — list_cols.php 의 date_old/date 를 확인하세요.",
                                   'columns' => array_map(fn($c) => ($c['id'] ?? '') . ' ' . ($c['key'] ?? '') . ' ' . ($c['type'] ?? '') . ' ' . ($c['name'] ?? ''), $schema)], $isCli);

/* ── 계획 ── */
$plan = []; $review = []; $noTime = 0; $skipDone = 0; $top = 0;
foreach ($items as $it) {
    if (!empty($it['parent_record_id']) || !empty($it['parent_item_id'])) continue;   // 하위 작업
    $top++;
    $f = slackIndexFields($it['fields'] ?? []);
    $date = $f[$dateCol]['date'][0] ?? null;
    if (!$date) { $review[] = ['id' => $it['id'], 'why' => '요청일이 비어 있음']; continue; }
    $body = $f[$bodyCol] ?? null;
    $rich = $body['rich_text'] ?? null;
    $plainLead = $rich ? mt_lead_text($rich) : null;
    if ($plainLead === null) $plainLead = (string)($body['text'] ?? '');
    $cur = $target !== '' ? trim((string)($f[$target]['text'] ?? '')) : '';

    $tp = mt_time_prefix($plainLead);
    $row = ['id' => $it['id'], 'date' => $date, 'before' => mb_substr((string)($body['text'] ?? ''), 0, 60)];
    if (!$tp) {
        $noTime++;
        if ($cur === '' || ($cur !== $date && !preg_match('/^' . preg_quote($date, '/') . '\s+\d{2}:\d{2}$/', $cur))) $plan[] = $row + ['value' => $date, 'body' => null];
        else $skipDone++;
        continue;
    }
    if ($tp['date'] && $tp['date'] !== $date) { $review[] = $row + ['why' => "본문 날짜 {$tp['date']} 가 요청일과 다름"]; continue; }
    $value = $date . ' ' . $tp['hm'];
    if ($tp['multi']) { $review[] = $row + ['why' => '시간이 여러 개 — 첫 시간만 요청일시로, 본문은 그대로', 'value' => $value]; $plan[] = $row + ['value' => $value, 'body' => null]; continue; }
    $newRich = $rich ? mt_strip_rich($rich, $tp['len']) : null;
    if ($rich && $newRich === null) { $review[] = $row + ['why' => '시간 글자가 링크·서식과 섞였거나 본문이 시간뿐 — 본문은 그대로', 'value' => $value]; $plan[] = $row + ['value' => $value, 'body' => null]; continue; }
    if (!$rich) { $rest = mb_substr((string)($body['text'] ?? ''), $tp['len']); $newRich = trim($rest) === '' ? null : mt_rich($rest); }
    $plan[] = $row + ['value' => $value, 'body' => $newRich, 'after' => $newRich ? mb_substr((string)mt_lead_text($newRich), 0, 60) : null];
}

$summary = ['items' => count($items), 'top_level' => $top,
            'to_update' => count($plan), 'move_time' => count(array_filter($plan, fn($p) => $p['body'] !== null)),
            'date_only' => $noTime, 'already' => $skipDone, 'review' => count($review), 'target_column' => $target ?: '(아직 없음 — 요청일시 글자 열을 먼저 만드세요)'];

if (!$apply) {
    mt_out(['ok' => true, 'mode' => 'preview', 'summary' => $summary,
            'samples' => array_map(fn($p) => ['id' => $p['id'], 'value' => $p['value'], 'before' => $p['before'], 'after' => $p['after'] ?? null], array_slice($plan, 0, 25)),
            'review' => $review], $isCli);
}
if ($target === '') mt_out(['ok' => false, 'error' => "'요청일시' 글자 열이 리스트에 없습니다. 먼저 만들고 다시 실행하세요.", 'summary' => $summary], $isCli);

/* ── 백업 → 적용 (셀 100개씩) ── */
$dir = dirname(__DIR__, 2) . '/var/sitehub';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
$backup = [];
foreach ($items as $it) {
    $f = slackIndexFields($it['fields'] ?? []);
    $backup[] = ['id' => $it['id'], 'date' => $f[$dateCol] ?? null, 'body' => $f[$bodyCol] ?? null, 'target' => $f[$target] ?? null];
}
$bf = $dir . '/move_time_' . date('Ymd_His') . '.json';
if (@file_put_contents($bf, json_encode(['list_id' => $listId, 'date_col' => $dateCol, 'body_col' => $bodyCol, 'target_col' => $target, 'rows' => $backup], JSON_UNESCAPED_UNICODE)) === false) {
    mt_out(['ok' => false, 'error' => '백업 파일을 쓰지 못해 멈췄습니다: ' . $bf], $isCli);
}

$limit = (int)$opt('limit');   // limit=N — 처음 N건만 (시험용)
if ($limit > 0) $plan = array_slice($plan, 0, $limit);
$cells = [];
foreach ($plan as $p) {
    $cells[] = ['row_id' => $p['id'], 'column_id' => $target, 'rich_text' => mt_rich($p['value'])];
    if ($p['body'] !== null) $cells[] = ['row_id' => $p['id'], 'column_id' => $bodyCol, 'rich_text' => $p['body']];
}
$done = 0; $errors = [];
foreach (array_chunk($cells, 100) as $chunk) {
    $res = null; $net = '';
    for ($try = 0; $try < 4; $try++) {
        $ch = curl_init('https://slack.com/api/slackLists.items.update');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,   // slack_lib.php 와 같은 설정 — 이 서버 PHP 에는 CA 묶음이 없다
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode(['list_id' => $listId, 'cells' => $chunk], JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $net  = $body === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($code == 429) { sleep(1 << $try); continue; }
        $res = json_decode((string)$body, true);
        break;
    }
    if (!empty($res['ok'])) $done += count($chunk);
    else {
        $errors[] = ($res['error'] ?? ($net !== '' ? '연결 오류: ' . $net : '응답 없음')) . (isset($res['needed']) ? ' (needed: ' . $res['needed'] . ')' : '');
        if (in_array($res['error'] ?? '', ['missing_scope', 'invalid_auth', 'not_authed', 'no_permission'], true)) break;
    }
    usleep(300000);
}
mt_out(['ok' => !$errors, 'mode' => 'apply', 'summary' => $summary, 'cells_written' => $done, 'cells_total' => count($cells),
        'errors' => $errors, 'backup' => basename($bf), 'review' => $review], $isCli);

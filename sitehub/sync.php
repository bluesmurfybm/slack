<?php
/**
 * Slack 리스트 'BlueLab-유지보수처리목록' → lab_history 증분 동기화. slack/sync.php 의 축소판.
 *
 *  CLI    : php sitehub/sync.php [full] [schema]     토큰은 config.local.php 의 sitehub_slack_token 또는 SLACK_TOKEN 환경변수
 *  브라우저: 화면의 [이력 동기화] 버튼 (포털 로그인 필요). ?full=1 이면 전체 재스캔, ?schema=1 이면 스키마만
 *
 *  - 리스트별 증분 워터마크 lab_sync_meta.updated_max_<listId>
 *  - site_locked=1(사람이 지정) 행의 site_id 는 보존, 나머지는 slack_names 로 다시 매칭
 *  - 라이브에서 빠진 항목은 archived=1, 돌아오면 archived=0. 라이브 목록이 비면 안전상 건너뛴다(전체 보관 방지)
 *  - list_cols.php 가 비어 있으면 스키마를 출력하고 멈춘다. 값이 컬럼 key(name·priority)면 스키마로 ID 를 찾아 쓴다
 *  - 이 리스트는 제목 열 = 업체명이라 title/site 가 같은 열. 우선순위·요청구분·무/유상·완료시간-비고 열은 그대로 담고,
 *    완료일(done)은 완료시간-비고 앞머리의 날짜에서 파생한다
 */
date_default_timezone_set('Asia/Seoul');   // CLI 는 core/auth.php 를 안 거친다 — 여기서 못 박아야 동기화 시각이 안 어긋난다
require_once __DIR__ . '/../slack/slack_lib.php';   // slackGet · slackFetchItems · slackField* 만 쓴다 (SLACK_COL 은 Coursemos 것)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/match_lib.php';

$isCli = PHP_SAPI === 'cli';
$cfg   = require __DIR__ . '/../config.php';
$me    = null;

if (!$isCli) {
    require_once __DIR__ . '/guard.php';
    $me = current_portal_user();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$me) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    sitehub_session_release();
}

$full   = $isCli ? in_array('full', array_slice($argv, 1), true)   : !empty($_GET['full']);
$schema = $isCli ? in_array('schema', array_slice($argv, 1), true) : !empty($_GET['schema']);
// 항목 원본 보기(진단) — `php sitehub/sync.php probe=Rec…` 또는 ?probe=Rec… . 리스트 목록 응답에 그 항목이 어떤 모양으로 오는지와
// items.info 결과를 그대로 돌려준다(쓰지 않음). 화면에 안 보이는 항목이 왜 들어오는지(하위 항목·보관 등) 확인할 때 쓴다.
$probe = '';
if ($isCli) { foreach (array_slice($argv, 1) as $a) if (strncmp($a, 'probe=', 6) === 0) $probe = substr($a, 6); }
else $probe = (string)($_GET['probe'] ?? '');
$probe = preg_match('/^Rec[A-Z0-9]+$/', $probe) ? $probe : '';

function sync_done(array $out, bool $isCli) {
    if ($isCli) {
        if (empty($out['ok'])) { fwrite(STDERR, ($out['error'] ?? '실패') . "\n"); }
        if (!empty($out['schema'])) {
            echo "리스트 컬럼 — 아래 ID(또는 key) 를 sitehub/list_cols.php 에 적는다\n";
            foreach ($out['schema'] as $c) {
                printf("  %-16s %-10s %-12s %s%s\n", $c['id'], ($c['key'] ?? '') !== $c['id'] ? 'key=' . $c['key'] : '', $c['type'], $c['name'],
                    $c['options'] ? '  [' . implode(' / ', $c['options']) . ']' : '');
            }
        }
        if (!empty($out['ok'])) {
            printf("훑음 %d · 반영 %d (매칭 %d · 미매칭 %d) · 보관 %d · %dms\n",
                $out['scanned'], $out['upserted'], $out['matched'], $out['unmatched'], $out['archived'], $out['took_ms']);
        }
        exit(empty($out['ok']) ? 1 : 0);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$listId = (string)($cfg['sitehub_list_id'] ?? '');
$GLOBALS['SITEHUB_LIST_ID'] = $listId;
if ($listId === '') sync_done(['ok' => false, 'error' => 'config.php 의 sitehub_list_id 가 비어 있습니다. BlueLab-유지보수처리목록 리스트의 ID(F…)를 적어 주세요.'], $isCli);

$token = $isCli
    ? ((string)($cfg['sitehub_slack_token'] ?? '') ?: (string)getenv('SLACK_TOKEN'))
    : sitehub_sync_token($me);
// 오류 안내에 "어느 토큰이 거절됐는지" 를 적는다 — 값은 절대 싣지 않는다
$GLOBALS['SITEHUB_TOKEN_SRC'] = !empty($cfg['sitehub_slack_token']) ? 'config.local.php 의 sitehub_slack_token'
    : ($isCli ? 'SLACK_TOKEN 환경변수' : '포털에 등록한 개인 Slack 토큰(coursemos 용이라 이 리스트를 못 읽는다)');
if (!$token) sync_done(['ok' => false, 'error' => $isCli
    ? '토큰 없음: config.local.php 의 sitehub_slack_token 또는 SLACK_TOKEN 환경변수를 설정하세요'
    : '동기화 토큰이 없습니다. config.local.php 의 sitehub_slack_token 을 채우거나 마이페이지에 Slack 토큰을 등록하세요.'], $isCli);

$COL = require __DIR__ . '/list_cols.php';
$mapped = array_filter($COL, fn($v) => (string)$v !== '');

/** Slack 오류 코드 → 사람이 바로 고칠 수 있는 안내. 토큰 값은 싣지 않고 출처만 적는다. */
function sitehub_slack_error(string $code, string $what = '리스트를 읽지 못했습니다', string $needed = ''): string {
    $src  = $GLOBALS['SITEHUB_TOKEN_SRC'] ?? '동기화 토큰';
    $list = $GLOBALS['SITEHUB_LIST_ID'] ?? '';
    switch ($code) {
        case 'invalid_auth': case 'not_authed': case 'token_revoked': case 'token_expired': case 'account_inactive':
            return "Slack 이 토큰을 거절했습니다({$code}). {$src} 값이 잘렸거나 폐기된 토큰입니다 — "
                 . "api.slack.com/apps → 앱 → OAuth & Permissions 의 User OAuth Token(xoxp-…)을 [Copy] 버튼으로 통째로 다시 복사해 넣으세요.";
        case 'missing_scope':
            return "토큰에 권한이 없습니다(missing_scope" . ($needed !== '' ? ": {$needed}" : '') . "). 앱의 User Token Scopes 에 lists:read · files:read 를 더하고 "
                 . "Reinstall to Workspace 한 뒤 새 토큰을 {$src} 에 넣으세요.";
        case 'list_not_found': case 'not_found': case 'access_denied': case 'not_allowed': case 'restricted_action':
            return "리스트 {$list} 를 볼 수 없습니다({$code}). 토큰이 thebluesoft.slack.com 워크스페이스에서 발급된 것인지, "
                 . "토큰 주인이 BlueLab-유지보수처리목록 을 열 수 있는지 확인하세요. (토큰 출처: {$src})";
        case 'ratelimited':
            return 'Slack 호출 한도에 걸렸습니다(ratelimited). 1~2분 뒤 다시 동기화하세요.';
        case 'unknown': case '':
            return "{$what}: Slack 응답이 없습니다 — 서버에서 slack.com 으로 나가는 연결(방화벽·프록시·SSL 인증서)을 확인하세요.";
    }
    return "{$what}: {$code} (토큰 출처: {$src})";
}

/** 리스트 스키마(컬럼 ID·key·이름·타입·select 옵션). 샘플 항목 하나를 items.info 로 읽어 list_metadata 를 꺼낸다. */
function sitehub_list_schema(string $token, string $listId, ?string $sampleId = null): array {
    if ($sampleId === null) {
        $r = slackGet('slackLists.items.list', $token, ['list_id' => $listId, 'limit' => 1]);
        if (empty($r['ok'])) throw new RuntimeException(sitehub_slack_error((string)($r['error'] ?? 'unknown'), '리스트를 읽지 못했습니다', (string)($r['needed'] ?? '')));
        $sampleId = $r['items'][0]['id'] ?? null;
    }
    if (!$sampleId) throw new RuntimeException('리스트에 항목이 하나도 없어 스키마를 읽을 수 없습니다.');
    $info = slackGet('slackLists.items.info', $token, ['list_id' => $listId, 'id' => $sampleId]);
    if (empty($info['ok'])) throw new RuntimeException(sitehub_slack_error((string)($info['error'] ?? 'unknown'), '리스트 스키마를 읽지 못했습니다', (string)($info['needed'] ?? '')));
    $schema = $info['list']['list_metadata']['schema'] ?? [];
    $out = [];
    foreach ($schema as $c) {
        $opts = [];
        foreach (($c['options']['choices'] ?? ($c['options'] ?? [])) as $o) {
            if (is_array($o) && isset($o['value'])) $opts[] = isset($o['label']) ? "{$o['label']}({$o['value']})" : (string)$o['value'];
        }
        $out[] = ['id' => $c['id'] ?? ($c['key'] ?? ''), 'key' => $c['key'] ?? '', 'name' => $c['name'] ?? '', 'type' => $c['type'] ?? '', 'options' => $opts];
    }
    return $out;
}

/** list_cols.php 의 값이 컬럼 key('name'·'priority' 같은 기본 열)면 스키마를 보고 실제 컬럼 ID(Col…)로 바꾼다. 항목 fields 는 column_id 로만 온다. */
function sitehub_resolve_cols(array $COL, array $schema): array {
    $byKey = []; $ids = [];
    foreach ($schema as $c) { if ($c['key'] !== '') $byKey[$c['key']] = $c['id']; $ids[$c['id']] = true; }
    foreach ($COL as $role => $v) {
        $v = (string)$v;
        if ($v === '' || isset($ids[$v])) continue;
        if (isset($byKey[$v])) $COL[$role] = $byKey[$v];
    }
    return $COL;
}

/** 요청일시 칸 — 날짜 열이면 ['YYYY-MM-DD', null], 글자 열이면 'YYYY-MM-DD HH:MM' 을 읽어 ['YYYY-MM-DD', 'YYYY-MM-DD HH:MM:00'] */
function sitehub_req_date(?array $f): ?array {
    if (!$f) return null;
    if (!empty($f['date'][0])) return [(string)$f['date'][0], null];
    $t = trim((string)($f['text'] ?? ''));
    if (!preg_match('/(\d{4})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})(?:\D+?(\d{1,2})[:：](\d{2}))?/u', $t, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    $d = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    $at = isset($m[4]) && $m[4] !== '' && (int)$m[4] < 24 && (int)$m[5] < 60 ? sprintf('%s %02d:%02d:00', $d, $m[4], $m[5]) : null;
    return [$d, $at];
}

/** '2026-10-07 11:35:09 서버 반영 확인' 처럼 앞머리가 날짜인 텍스트에서 날짜만 */
function sitehub_lead_date(string $s): ?string {
    return preg_match('/^\s*(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})/u', $s, $m) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
}

try {
    if ($probe !== '') {
        $all = slackFetchItems($token, $listId);
        $raw = null;
        foreach ($all['items'] ?? [] as $it) if (($it['id'] ?? '') === $probe) { $raw = $it; break; }
        $info = slackGet('slackLists.items.info', $token, ['list_id' => $listId, 'id' => $probe]);
        unset($info['list']);   // 스키마는 길어서 뺀다
        $out = ['ok' => true, 'probe' => $probe, 'in_list_response' => $raw !== null, 'list_item_keys' => $raw ? array_keys($raw) : [],
                'list_item' => $raw, 'items_info' => $info, 'list_total' => count($all['items'] ?? [])];
        if ($isCli) { echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }
        sync_done($out, false);
    }

    if ($schema || empty($mapped['title'])) {
        sync_done(['ok' => false,
                   'error' => empty($mapped['title'])
                        ? 'sitehub/list_cols.php 가 비어 있습니다. 아래 스키마를 보고 컬럼 ID 를 채운 뒤 다시 동기화하세요.'
                        : '리스트 스키마입니다(토큰·리스트 연결 정상). list_cols.php 와 다르면 아래를 보고 고치세요.',
                   'schema' => sitehub_list_schema($token, $listId)], $isCli);
    }

    $lockFp = fopen(sys_get_temp_dir() . '/sitehub_sync.lock', 'c');
    if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        sync_done(['ok' => true, 'skipped' => 'locked', 'scanned' => 0, 'upserted' => 0, 'matched' => 0, 'unmatched' => 0, 'archived' => 0, 'took_ms' => 0], $isCli);
    }

    $t0  = microtime(true);
    $pdo = sitehub_db();
    $now = date('Y-m-d H:i:s');

    // 사이트 매칭 — 규칙은 match_lib.php (표기 흔들림·오타·어순까지). 업체명 칸 먼저, 못 찾으면 제목.
    $index = sitehub_site_index($pdo, 'locked');   // 사람이 미매칭 시트에서 고른 표기도 배운다
    $matchSite = function (string $siteText, string $title) use ($index): ?int {
        $m = $siteText !== '' ? sitehub_match_name($index, $siteText) : null;
        if (!$m && $title !== '' && $title !== $siteText) $m = sitehub_match_name($index, $title);
        return $m ? (int)$m['id'] : null;
    };

    $metaKey = 'updated_max_' . $listId;
    $since = 0;
    if (!$full) {
        $st = $pdo->prepare("SELECT v FROM lab_sync_meta WHERE k = ?");
        $st->execute([$metaKey]);
        $since = (int)$st->fetchColumn();
    }

    $data = slackFetchItems($token, $listId);
    if (isset($data['error'])) throw new RuntimeException(sitehub_slack_error((string)$data['error'], 'Slack 리스트를 읽지 못했습니다'));
    $sampleId = $data['items'][0]['id'] ?? null;
    if ($sampleId) $COL = sitehub_resolve_cols($COL, sitehub_list_schema($token, $listId, $sampleId));

    $locked = [];
    foreach ($pdo->query("SELECT id FROM lab_history WHERE site_locked = 1") as $r) $locked[$r['id']] = true;
    // 실제로 바뀐 건수(새 항목·Slack 에서 고쳐진 항목·보관에서 돌아온 항목) — 화면 자동 동기화가 이 숫자로 다시 그릴지 정한다.
    // upserted 는 워터마크와 같은 시각의 항목을 매번 다시 넣으므로 바뀐 게 없어도 0 이 아니다.
    $prev = [];
    $st = $pdo->prepare("SELECT id, updated, archived FROM lab_history WHERE list_id = ?");
    $st->execute([$listId]);
    foreach ($st as $r) $prev[$r['id']] = [(int)$r['updated'], (int)$r['archived']];

    $rows = []; $userIds = []; $fileIds = []; $allIds = []; $scanned = 0; $maxUpdated = 0;
    foreach ($data['items'] as $item) {
        $scanned++;
        $id = (string)($item['id'] ?? '');
        if ($id === '') continue;
        $allIds[] = $id;
        $upd = (int)($item['updated_timestamp'] ?? 0);
        if ($upd > $maxUpdated) $maxUpdated = $upd;
        if ($since > 0 && $upd < $since) continue;

        $m   = slackIndexFields($item['fields'] ?? []);
        $get = fn($role) => (!empty($COL[$role]) && isset($m[$COL[$role]])) ? $m[$COL[$role]] : null;

        $reqId  = ($f = $get('req')) ? slackFieldUser($f) : null;
        $asgIds = ($f = $get('asg')) ? slackFieldUsers($f) : [];
        if ($reqId) $userIds[] = $reqId;
        foreach ($asgIds as $u) $userIds[] = $u;
        $files = ($f = $get('attach')) ? slackFieldAttach($f) : [];
        foreach ($files as $fid) $fileIds[] = $fid;

        $siteField = $get('site');
        $remark    = ($f = $get('remark')) ? slackFieldText($f) : '';
        $req       = sitehub_req_date($get('date')) ?? sitehub_req_date($get('date_old'));
        $rows[] = [
            'id'          => $id,
            'parent_id'   => (string)($item['parent_record_id'] ?? ($item['parent_item_id'] ?? '')) ?: null,   // 하위 항목(subtask)이면 부모 항목 ID
            'title'       => ($f = $get('title')) ? slackFieldText($f) : '(제목 없음)',
            'body'        => ($f = $get('body')) ? slackFieldBody($f) : '',
            'site_sel'    => $siteField ? slackFieldSelect($siteField) : null,   // select 옵션 ID (라벨은 아래서 변환)
            'site_text'   => $siteField ? slackFieldText($siteField) : '',
            'status_id'   => ($f = $get('status')) ? slackFieldSelect($f) : null,
            'priority_id' => ($f = $get('priority')) ? slackFieldSelect($f) : null,
            'channel_id'  => ($f = $get('channel'))  ? slackFieldSelect($f) : null,
            'paid_id'     => ($f = $get('paid'))     ? slackFieldSelect($f) : null,
            'remark'      => $remark,
            'req_id'      => $reqId,
            'asg_ids'     => $asgIds,
            'date'        => $req[0] ?? null,   // 빈 날짜 칸은 '' 로 올 수 있다 — DATE 컬럼엔 NULL
            'req_at'      => $req[1] ?? null,
            'done'        => ((($f = $get('done')) ? slackFieldDate($f) : null) ?: null) ?? sitehub_lead_date($remark),
            'files'       => $files,
            'created'     => (int)($item['date_created'] ?? 0),
            'updated'     => $upd,
        ];
    }

    $sel      = slackSelectMaps($token, $listId, $sampleId);
    $label    = function (string $role, $optId) use ($sel, $COL): string {
        if ($optId === null || $optId === '') return '';
        $map = !empty($COL[$role]) ? ($sel[$COL[$role]] ?? []) : [];
        return (string)($map[$optId] ?? $optId);
    };
    $siteMap   = !empty($COL['site'])   ? ($sel[$COL['site']]   ?? []) : [];
    $names = $userIds ? slackResolveUsers($token, $userIds) : [];
    $fmeta = $fileIds ? slackResolveFiles($token, $fileIds) : [];

    $ins = $pdo->prepare("
        INSERT INTO lab_history
            (id, list_id, parent_id, site_id, site_locked, site_text, title, body, status_id, status, priority, channel, paid, remark,
             req_id, req, asg_id, asg, `date`, req_at, done, attachments, archived, created, updated, synced_at)
        VALUES
            (:id, :list_id, :parent_id, :site_id, 0, :site_text, :title, :body, :status_id, :status, :priority, :channel, :paid, :remark,
             :req_id, :req, :asg_id, :asg, :date, :req_at, :done, :attachments, 0, :created, :updated, :synced_at)
        ON DUPLICATE KEY UPDATE
            list_id=VALUES(list_id), parent_id=VALUES(parent_id),
            site_id=IF(site_locked=1, site_id, VALUES(site_id)),
            site_text=VALUES(site_text), title=VALUES(title), body=VALUES(body),
            status_id=VALUES(status_id), status=VALUES(status), priority=VALUES(priority), channel=VALUES(channel),
            paid=VALUES(paid), remark=VALUES(remark), req_id=VALUES(req_id), req=VALUES(req),
            asg_id=VALUES(asg_id), asg=VALUES(asg), `date`=VALUES(`date`), req_at=VALUES(req_at), done=VALUES(done),
            attachments=VALUES(attachments), archived=0, updated=VALUES(updated), synced_at=VALUES(synced_at)
    ");

    $upserted = 0; $matched = 0; $unmatched = 0; $inserted = 0; $changed = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $siteText = $r['site_sel'] !== null ? (string)($siteMap[$r['site_sel']] ?? $r['site_text']) : (string)$r['site_text'];
        $siteId   = $matchSite($siteText, $r['title']);
        if (!isset($locked[$r['id']]) && !$r['parent_id']) { if ($siteId) $matched++; else $unmatched++; }   // 하위 항목은 부모를 따르므로 안 센다
        $atts = [];
        foreach ($r['files'] as $fid) if (isset($fmeta[$fid]) && empty($fmeta[$fid]['gone'])) $atts[] = $fmeta[$fid];
        $ins->execute([
            ':id'          => $r['id'],
            ':list_id'     => $listId,
            ':parent_id'   => $r['parent_id'],
            ':site_id'     => $siteId,
            ':site_text'   => mb_substr($siteText, 0, 200),
            ':title'       => mb_substr($r['title'], 0, 500),
            ':body'        => $r['body'],
            ':status_id'   => $r['status_id'],
            ':status'      => mb_substr($label('status', $r['status_id']), 0, 60),
            ':priority'    => mb_substr($label('priority', $r['priority_id']), 0, 60),
            ':channel'     => mb_substr($label('channel', $r['channel_id']), 0, 60),
            ':paid'        => mb_substr($label('paid', $r['paid_id']), 0, 20),
            ':remark'      => $r['remark'] !== '' ? $r['remark'] : null,
            ':req_id'      => $r['req_id'],
            ':req'         => $r['req_id'] ? (string)($names[$r['req_id']] ?? $r['req_id']) : '—',
            ':asg_id'      => $r['asg_ids'] ? implode(',', $r['asg_ids']) : null,
            ':asg'         => $r['asg_ids'] ? implode(', ', array_map(fn($u) => (string)($names[$u] ?? $u), $r['asg_ids'])) : '—',
            ':date'        => $r['date'],
            ':req_at'      => $r['req_at'],
            ':done'        => $r['done'],
            ':attachments' => $atts ? json_encode($atts, JSON_UNESCAPED_UNICODE) : null,
            ':created'     => $r['created'],
            ':updated'     => $r['updated'],
            ':synced_at'   => $now,
        ]);
        $upserted++;
        if (!isset($prev[$r['id']])) $inserted++;
        elseif ($prev[$r['id']][0] !== (int)$r['updated'] || $prev[$r['id']][1] === 1) $changed++;
    }

    // 하위 항목은 업체명 칸에 작업 내용이 적히는 일이 많다 — 사람이 정한 것만 빼고 부모 항목의 사이트를 따른다
    sitehub_children_follow($pdo);

    // 라이브에서 빠진 항목 → 보관. 목록이 비면(권한 문제 등) 전체를 보관 처리해 버리지 않도록 건너뛴다.
    $archived = 0;
    if ($allIds) {
        $ph = implode(',', array_fill(0, count($allIds), '?'));
        $st = $pdo->prepare("UPDATE lab_history SET archived=1 WHERE list_id=? AND archived=0 AND id NOT IN ({$ph})");
        $st->execute(array_merge([$listId], $allIds));
        $archived = $st->rowCount();
    }

    $meta = $pdo->prepare("INSERT INTO lab_sync_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    if ($maxUpdated > 0) $meta->execute([$metaKey, (string)$maxUpdated]);
    $meta->execute(['last_sync_at', $now]);
    $pdo->commit();

    $unmatchedTotal = (int)$pdo->query("SELECT COUNT(*) FROM lab_history WHERE site_id IS NULL AND archived = 0 AND parent_id IS NULL")->fetchColumn();
    sync_done([
        'ok' => true, 'scanned' => $scanned, 'upserted' => $upserted, 'matched' => $matched, 'unmatched' => $unmatched,
        'inserted' => $inserted, 'changed' => $inserted + $changed + $archived,
        'archived' => $archived, 'took_ms' => (int)round((microtime(true) - $t0) * 1000),
        'meta' => ['unmatched' => $unmatchedTotal, 'last_sync' => $now, 'sync_age' => 0, 'can_sync' => true],
    ], $isCli);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    sync_done(['ok' => false, 'error' => $e->getMessage()], $isCli);
}

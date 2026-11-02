<?php
/**
 * 이력(lab_history) 쓰기 — sitehub 화면에서 추가·수정·삭제하면 Slack 리스트 'BlueLab-유지보수처리목록' 에도 같이 반영한다.
 *  api.php 의 history_add / history_update / history_delete 가 부른다.
 *
 *  - 토큰은 로그인한 사람의 개인 Slack 토큰만 쓴다(sitehub_slack_token 공용 토큰은 쓰지 않는다) — Slack 쪽 등록자·수정자가 그 사람으로 남는다.
 *    lists:write 권한이 필요하다.
 *  - Slack 이 먼저, DB 는 그 다음이다. Slack 호출이 실패하면 DB 도 건드리지 않는다.
 *  - 칸은 list_cols.php 의 역할 이름 그대로: 업체명(title=site)·요청일시(date)·요청구분(channel)·작업내용(body)·처리상태(status)·무/유상(paid)·우선순위(priority)·비고(remark).
 *    첨부(attach)·요청자·담당자는 다루지 않는다(Slack 에서). 글자 열은 rich_text 셀, 선택 열은 select 셀로 쓴다.
 *  - 스키마(열 ID·선택지)는 6시간 임시 캐시. 항목 id 는 Slack 이 만들어 준 것을 그대로 lab_history.id 로 쓰므로 다음 동기화 때 중복 없이 합쳐진다.
 */

const SITEHUB_HIST_SELECTS = ['channel', 'status', 'paid', 'priority'];   // select 열 역할
const SITEHUB_HIST_FIELDS  = ['req_at', 'channel', 'body', 'status', 'paid', 'priority', 'remark'];   // 화면에서 쓰는 칸

/** 로그인한 사람의 개인 토큰 — 없으면 예외 */
function sitehub_hw_token(array $me): string {
    $t = !empty($me['slack_token_enc']) ? dec_token($me['slack_token_enc']) : '';
    if ($t === '') throw new Exception('개인 Slack 토큰이 없습니다. 마이페이지에 Slack 토큰(lists:write 권한)을 등록하세요.');
    return $t;
}

/** Slack 오류 코드 → 안내 */
function sitehub_hw_error(array $r, string $what): string {
    $c = (string)($r['error'] ?? 'unknown');
    switch ($c) {
        case 'missing_scope':
            return "{$what} 실패: 개인 토큰에 권한이 없습니다(missing_scope" . (!empty($r['needed']) ? ': ' . $r['needed'] : '') . "). 앱의 User Token Scopes 에 lists:write 를 더하고 Reinstall 한 뒤 토큰을 다시 등록하세요.";
        case 'invalid_auth': case 'not_authed': case 'token_revoked': case 'token_expired': case 'account_inactive':
            return "{$what} 실패: 개인 Slack 토큰이 거절됐습니다({$c}). 마이페이지에서 토큰을 다시 등록하세요.";
        case 'list_not_found': case 'item_not_found': case 'not_found': case 'access_denied': case 'not_allowed': case 'restricted_action':
            return "{$what} 실패: Slack 리스트/항목에 접근할 수 없습니다({$c}). 토큰 주인이 리스트를 편집할 수 있는지 확인하세요.";
        case 'ratelimited':
            return "{$what} 실패: Slack 호출 한도에 걸렸습니다. 잠시 뒤 다시 하세요.";
        case 'unknown':
            return "{$what} 실패: Slack 응답이 없습니다 — 서버에서 slack.com 으로 나가는 연결을 확인하세요.";
    }
    return "{$what} 실패: {$c}";
}

/** 리스트 스키마 → ['cols' => [역할 => 열ID], 'opts' => [역할 => [옵션ID => 라벨]]]. 6시간 캐시 */
function sitehub_hw_schema(PDO $pdo, string $token, string $listId): array {
    $cache = sys_get_temp_dir() . '/sitehub_hw_schema_' . md5($listId) . '.json';
    if (is_file($cache) && time() - filemtime($cache) < 6 * 3600) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c) && !empty($c['cols'])) return $c;
    }
    $sample = (string)$pdo->query("SELECT id FROM lab_history WHERE list_id = " . $pdo->quote($listId) . " AND parent_id IS NULL AND archived = 0 ORDER BY updated DESC LIMIT 1")->fetchColumn();
    if ($sample === '') {
        $l = slackGet('slackLists.items.list', $token, ['list_id' => $listId, 'limit' => 1]);
        if (empty($l['ok'])) throw new Exception(sitehub_hw_error($l, '리스트 스키마 읽기'));
        $sample = (string)($l['items'][0]['id'] ?? '');
    }
    if ($sample === '') throw new Exception('리스트에 항목이 하나도 없어 스키마를 읽을 수 없습니다.');
    $info = slackGet('slackLists.items.info', $token, ['list_id' => $listId, 'id' => $sample]);
    if (empty($info['ok'])) throw new Exception(sitehub_hw_error($info, '리스트 스키마 읽기'));
    $schema = $info['list']['list_metadata']['schema'] ?? [];
    if (!$schema) throw new Exception('Slack 응답에 리스트 스키마가 없습니다.');

    $byId = []; $byKey = [];
    foreach ($schema as $c) { $id = (string)($c['id'] ?? ''); if ($id === '') continue; $byId[$id] = $c; if (($c['key'] ?? '') !== '') $byKey[(string)$c['key']] = $id; }
    $COL = require __DIR__ . '/list_cols.php';
    $cols = []; $opts = [];
    foreach ($COL as $role => $v) {
        $v = (string)$v;
        if ($v === '') continue;
        $id = isset($byId[$v]) ? $v : ($byKey[$v] ?? '');
        if ($id === '') continue;
        $cols[$role] = $id;
        if (in_array($role, SITEHUB_HIST_SELECTS, true)) {
            $m = [];
            foreach (($byId[$id]['options']['choices'] ?? ($byId[$id]['options'] ?? [])) as $o) {
                if (!is_array($o)) continue;
                $oid = (string)($o['value'] ?? ($o['id'] ?? ''));
                if ($oid !== '') $m[$oid] = (string)($o['label'] ?? ($o['text'] ?? $oid));
            }
            $opts[$role] = $m;
        }
    }
    $out = ['cols' => $cols, 'opts' => $opts];
    @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/** 화면 폼에 줄 선택지 — [역할 => [라벨, …]] (처리상태·요청구분·무/유상·우선순위) */
function sitehub_hw_options(PDO $pdo, array $me): array {
    $listId = (string)(sitehub_config()['sitehub_list_id'] ?? '');
    if ($listId === '') throw new Exception('config.php 의 sitehub_list_id 가 비어 있습니다.');
    $sc = sitehub_hw_schema($pdo, sitehub_hw_token($me), $listId);
    $out = [];
    foreach (SITEHUB_HIST_SELECTS as $r) $out[$r] = array_values($sc['opts'][$r] ?? []);
    return $out;
}

function sitehub_hw_rich(string $text): array {
    return [['type' => 'rich_text', 'elements' => [['type' => 'rich_text_section', 'elements' => [['type' => 'text', 'text' => $text]]]]]];
}

/** 입력 → 'YYYY-MM-DD HH:MM' · DATE · DATETIME. 비면 null. 형식이 틀리면 예외 */
function sitehub_hw_when(string $s): ?array {
    $s = trim($s);
    if ($s === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new Exception('요청일시 형식이 올바르지 않습니다.');
    $d = "{$m[1]}-{$m[2]}-{$m[3]}";
    if (!isset($m[4]) || $m[4] === '') return ['text' => $d, 'date' => $d, 'at' => null];
    if ((int)$m[4] > 23 || (int)$m[5] > 59) throw new Exception('요청일시 형식이 올바르지 않습니다.');
    return ['text' => "{$d} {$m[4]}:{$m[5]}", 'date' => $d, 'at' => "{$d} {$m[4]}:{$m[5]}:00"];
}

/** 화면 입력을 검증·정리 → ['text' => [역할 => 글], 'select' => [역할 => 옵션ID|null], 'db' => lab_history 칸]. $partial 이면 보낸 칸만 */
function sitehub_hw_prepare(array $sc, array $in, bool $partial): array {
    $text = []; $sel = []; $db = [];
    $has = fn($k) => !$partial || array_key_exists($k, $in);
    if ($has('req_at')) {
        $w = sitehub_hw_when((string)($in['req_at'] ?? ''));
        $text['date'] = $w['text'] ?? '';
        $db['date'] = $w['date'] ?? null; $db['req_at'] = $w['at'] ?? null;
    }
    if ($has('body')) {
        $b = trim((string)($in['body'] ?? ''));
        if ($b === '') throw new Exception('작업내용을 입력하세요.');
        $text['body'] = $b; $db['body'] = $b;
    }
    if ($has('remark')) {
        $r = trim((string)($in['remark'] ?? ''));
        $text['remark'] = $r; $db['remark'] = $r !== '' ? $r : null;
        $db['done'] = preg_match('/^\s*(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})/u', $r, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    foreach (SITEHUB_HIST_SELECTS as $role) {
        if (!$has($role)) continue;
        $label = trim((string)($in[$role] ?? ''));
        if ($label === '') { $sel[$role] = null; $db[$role] = ''; if ($role === 'status') $db['status_id'] = null; continue; }
        $oid = array_search($label, $sc['opts'][$role] ?? [], true);
        if ($oid === false) throw new Exception("알 수 없는 선택값입니다: {$label}");
        $sel[$role] = (string)$oid; $db[$role] = $label; if ($role === 'status') $db['status_id'] = (string)$oid;
    }
    return ['text' => $text, 'select' => $sel, 'db' => $db];
}

/** Slack 셀 목록 — create 는 column_id 만, update 는 row_id 도 붙인다 */
function sitehub_hw_cells(array $sc, array $p, ?string $rowId, ?string $siteName = null): array {
    $cells = [];
    $add = function (string $role, array $val) use (&$cells, $sc, $rowId) {
        if (empty($sc['cols'][$role])) return;
        $c = ['column_id' => $sc['cols'][$role]] + $val;
        if ($rowId !== null) $c = ['row_id' => $rowId] + $c;
        $cells[] = $c;
    };
    if ($siteName !== null) $add('title', ['rich_text' => sitehub_hw_rich($siteName)]);   // 이 리스트는 제목 열이 곧 업체명
    // 만들 때는 빈 칸을 보내지 않는다. 고칠 때만 빈 값으로 비운다
    foreach ($p['text'] as $role => $t) { if ($t !== '' || $rowId !== null) $add($role, ['rich_text' => $t === '' ? [] : sitehub_hw_rich($t)]); }
    foreach ($p['select'] as $role => $oid) { if ($oid !== null || $rowId !== null) $add($role, ['select' => $oid === null ? [] : [$oid]]); }
    return $cells;
}

function sitehub_hw_listid(): string {
    $listId = (string)(sitehub_config()['sitehub_list_id'] ?? '');
    if ($listId === '') throw new Exception('config.php 의 sitehub_list_id 가 비어 있습니다.');
    return $listId;
}

/** 추가 → 새 항목 id */
function sitehub_history_add(PDO $pdo, array $me, int $siteId, array $in): string {
    $listId = sitehub_hw_listid();
    $token  = sitehub_hw_token($me);
    $st = $pdo->prepare("SELECT name FROM lab_sites WHERE id = ?");
    $st->execute([$siteId]);
    $name = (string)$st->fetchColumn();
    if ($name === '') throw new Exception('없는 사이트입니다.');
    $sc = sitehub_hw_schema($pdo, $token, $listId);
    if (empty($sc['cols']['title']) || empty($sc['cols']['body'])) throw new Exception('list_cols.php 에 업체명(title)·작업내용(body) 열이 필요합니다.');
    if (trim((string)($in['req_at'] ?? '')) === '') $in['req_at'] = date('Y-m-d H:i');   // 비워 두면 지금
    $p = sitehub_hw_prepare($sc, $in, false);

    $r = slackPost('slackLists.items.create', $token, ['list_id' => $listId, 'initial_fields' => json_encode(sitehub_hw_cells($sc, $p, null, $name), JSON_UNESCAPED_UNICODE)]);
    if (empty($r['ok'])) throw new Exception(sitehub_hw_error((array)$r, 'Slack 리스트 등록'));
    $id = (string)($r['item']['id'] ?? ($r['record']['id'] ?? ($r['id'] ?? '')));
    if ($id === '') throw new Exception('Slack 에는 등록됐지만 항목 id 를 받지 못했습니다. [지금 동기화]로 가져오세요.');

    $d = $p['db'] + ['status_id' => null, 'status' => '', 'priority' => '', 'channel' => '', 'paid' => '', 'remark' => null, 'done' => null, 'req_at' => null, 'date' => null];
    $now = time();
    $pdo->prepare("INSERT INTO lab_history (id, list_id, parent_id, site_id, site_locked, site_text, title, body, status_id, status, priority, channel, paid, remark,
                       req_id, req, asg_id, asg, `date`, req_at, done, attachments, archived, created, updated, synced_at)
                   VALUES (?, ?, NULL, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, '—', NULL, '—', ?, ?, ?, NULL, 0, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE site_id=VALUES(site_id), site_locked=1, body=VALUES(body), synced_at=NOW()")
        ->execute([$id, $listId, $siteId, mb_substr($name, 0, 200), mb_substr($name, 0, 500), $d['body'], $d['status_id'], mb_substr($d['status'], 0, 60),
                   mb_substr($d['priority'], 0, 60), mb_substr($d['channel'], 0, 60), mb_substr($d['paid'], 0, 20), $d['remark'], $d['date'], $d['req_at'], $d['done'], $now, $now]);
    return $id;
}

function sitehub_hw_row(PDO $pdo, string $id): array {
    $st = $pdo->prepare("SELECT id, list_id, site_id, parent_id FROM lab_history WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new Exception('이력을 찾을 수 없습니다.');
    return $row;
}

/** 수정 — 보낸 칸만 */
function sitehub_history_update(PDO $pdo, array $me, string $id, array $in): void {
    $row    = sitehub_hw_row($pdo, $id);
    $listId = (string)($row['list_id'] ?: sitehub_hw_listid());
    $token  = sitehub_hw_token($me);
    $sc = sitehub_hw_schema($pdo, $token, $listId);
    $in = array_intersect_key($in, array_flip(SITEHUB_HIST_FIELDS));
    if (!$in) throw new Exception('바꿀 내용이 없습니다.');
    $p = sitehub_hw_prepare($sc, $in, true);
    $cells = sitehub_hw_cells($sc, $p, $id);
    if (!$cells) throw new Exception('바꿀 내용이 없습니다.');

    $r = slackPost('slackLists.items.update', $token, ['list_id' => $listId, 'cells' => json_encode($cells, JSON_UNESCAPED_UNICODE)]);
    if (empty($r['ok'])) throw new Exception(sitehub_hw_error((array)$r, 'Slack 리스트 수정'));

    $sets = []; $args = [];
    foreach ($p['db'] as $k => $v) { $sets[] = "`{$k}` = ?"; $args[] = in_array($k, ['status', 'priority', 'channel'], true) ? mb_substr((string)$v, 0, 60) : $v; }
    $sets[] = 'updated = ?'; $args[] = time();
    $sets[] = 'synced_at = NOW()';
    $args[] = $id;
    $pdo->prepare("UPDATE lab_history SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
}

/** 삭제 — Slack 리스트에서 지우고 DB 의 그 항목·하위 항목·절차 연결도 지운다 */
function sitehub_history_delete(PDO $pdo, array $me, string $id): ?int {
    $row    = sitehub_hw_row($pdo, $id);
    $listId = (string)($row['list_id'] ?: sitehub_hw_listid());
    $token  = sitehub_hw_token($me);

    $r = slackPost('slackLists.items.delete', $token, ['list_id' => $listId, 'id' => $id]);
    // 이미 Slack 에서 지워진 항목이면 DB 만 정리한다
    if (empty($r['ok']) && !in_array((string)($r['error'] ?? ''), ['item_not_found', 'not_found'], true)) throw new Exception(sitehub_hw_error((array)$r, 'Slack 리스트 삭제'));

    $ids = [$id];
    $st = $pdo->prepare("SELECT id FROM lab_history WHERE parent_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) $ids[] = (string)$k;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM lab_procedure_uses WHERE history_id IN ({$in})")->execute($ids);
    $pdo->prepare("DELETE FROM lab_history WHERE id IN ({$in})")->execute($ids);
    return $row['site_id'] === null ? null : (int)$row['site_id'];
}

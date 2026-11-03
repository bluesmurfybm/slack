<?php
/**
 * 변경 알림 — access_api.php 의 쓰기를 포털 알림함·웹 푸시(core/push.php, 종류 access.change)로 보낸다.
 *  - 한 요청의 변경은 대학마다 한 건으로 묶어 응답을 보낸 뒤에 보낸다. 요청이 실패하면 버린다.
 *  - 값은 싣지 않고 칸 이름만 싣는다. 비밀번호·DB 접속 정보가 든 칸이고, 푸시는 잠금 화면에도 뜬다.
 */

const ACCESS_NOTICE_QUIET_MIN = 10;

/** 칸 key → 알림에 쓸 이름. 테스트·운영 묶음은 '아이디'·'비밀번호' 가 겹쳐 묶음 이름을 붙인다 */
function access_notice_labels(): array {
    $out = ['ver' => '버전'];
    foreach (access_field_groups() as $g) {
        foreach ($g['fields'] as $f) {
            $out[$f['key']] = $g['title'] === '공통 정보' || ($f['type'] ?? '') === 'url' ? $f['label'] : $g['title'] . ' ' . $f['label'];
        }
    }
    return $out;
}

/** 견줄 값 — 암호문은 풀고, Editor.js 값은 불러올 때마다 바뀌는 블록 id 를 뺀다 */
function access_notice_norm(string $key, $v): string {
    $v = trim((string)$v);
    if (in_array($key, access_secret_cols(), true)) $v = trim(access_dec($v));
    if (in_array($key, access_rich_cols(), true) && ($j = json_decode($v, true)) && isset($j['blocks'])) {
        $v = json_encode(array_map(fn($b) => ['type' => $b['type'] ?? '', 'data' => $b['data'] ?? []], $j['blocks']), JSON_UNESCAPED_UNICODE);
    }
    return $v;
}

/** 옛 값과 새 값을 견줘 바뀐 칸 이름만 */
function access_notice_changed(array $old, array $new): array {
    $labels = access_notice_labels();
    $out = [];
    foreach ($new as $k => $v) {
        if (!isset($labels[$k])) continue;
        if (access_notice_norm($k, $old[$k] ?? '') !== access_notice_norm($k, $v)) $out[] = $labels[$k];
    }
    return $out;
}

function &access_notice_buf(): array {
    static $buf = ['me' => null, 'schools' => []];
    return $buf;
}

/** $quiet 면 같은 대학의 안 읽은 알림이 10분 안에 있는 사람에게는 다시 쌓지 않는다(상세는 칸 하나씩 저장한다) */
function access_notice_add(array $me, int $schoolId, string $phrase, bool $quiet = true): void {
    if ($schoolId <= 0) return;
    $buf = &access_notice_buf();
    if ($buf['me'] === null) register_shutdown_function('access_notice_flush');
    $buf['me'] = $me;
    $s = &$buf['schools'][$schoolId];
    $s['phrases'][] = $phrase;
    $s['quiet'] = ($s['quiet'] ?? true) && $quiet;
}

function access_notice_drop(): void {
    $buf = &access_notice_buf();
    $buf['schools'] = [];
}

function access_notice_flush(): void {
    $buf = &access_notice_buf();
    $schools = $buf['schools'];
    $buf['schools'] = [];
    if (!$schools || $buf['me'] === null) return;
    // 웹 푸시는 기기마다 HTTP 를 부른다 — 저장한 사람의 응답과 다음 요청(세션 잠금)이 그만큼 기다리지 않게 먼저 놓는다
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    foreach ($schools as $schoolId => $s) {
        try {
            access_notice_send($buf['me'], (int)$schoolId, array_values(array_unique($s['phrases'])), $s['quiet']);
        } catch (Throwable $e) {
            error_log('[access] 변경 알림 실패: ' . $e->getMessage());
        }
    }
}

function access_notice_url(int $schoolId): string {
    return 'access/access.php?school=' . $schoolId;
}

function access_notice_send(array $me, int $schoolId, array $phrases, bool $quiet): void {
    require_once __DIR__ . '/../core/push.php';
    $st = access_db()->prepare("SELECT name, ver FROM schools WHERE id = ?");
    $st->execute([$schoolId]);
    $s = $st->fetch();
    if (!$s) return;
    $name = $s['name'] . ($s['ver'] !== '' ? ' ' . $s['ver'] : '');
    $url = access_notice_url($schoolId);
    $except = [(string)$me['email']];
    if ($quiet) {
        $q = portal_db()->prepare("SELECT u.email FROM portal_notification n JOIN portal_users u ON u.id = n.user_id
                                    WHERE n.kind = 'access.change' AND n.read_at IS NULL AND n.url = ?
                                      AND n.created_at > NOW() - INTERVAL " . ACCESS_NOTICE_QUIET_MIN . " MINUTE");
        $q->execute([$url]);
        $except = array_merge($except, $q->fetchAll(PDO::FETCH_COLUMN));
    }
    push_send([
        'kind' => 'access.change',
        'title' => $name . ' · ' . mb_strimwidth($phrases[0], 0, 60, '…') . (count($phrases) > 1 ? ' 외 ' . (count($phrases) - 1) . '건' : ''),
        'body' => mb_strimwidth(trim(($me['name'] ?? '') . ' · ' . implode(', ', $phrases)), 0, 200, '…'),
        'url' => $url,
        'tag' => 'access-' . $schoolId,
        'except' => $except,
    ]);
}

function access_notice_seen(int $uid, int $schoolId): void {
    if (!$uid || $schoolId <= 0) return;
    try {
        require_once __DIR__ . '/../core/push.php';
        push_inbox_mark_read_url($uid, 'access.', access_notice_url($schoolId));
    } catch (Throwable $e) {
        error_log('[access] 알림 읽음: ' . $e->getMessage());
    }
}

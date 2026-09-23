<?php
/**
 * 이모지 메타 (사용자별). 로그인 필요.
 *  - frequent: 내가 최근 반응한 이모지 사용 빈도 상위 (reactions.list 집계 — Slack '자주 사용' 근사)
 *  - custom  : 워크스페이스 커스텀 이모지 name → 이미지 URL (emoji.list)
 *  스코프 없으면 해당 항목만 비워서 반환(기능 자체는 동작).
 *  사용자별 1시간 캐시.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');

$me  = current_user();
$tok = current_token();
$uid = $me['id'] ?? 'anon';

$cacheFile = sys_get_temp_dir() . '/slack_emoji_meta_' . preg_replace('/\W/', '', $uid) . '.json';
if (is_file($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true) ?: [];
    // 스코프 부족 상태는 5분만 캐시 (스코프 추가·재로그인 후 빨리 회복되게), 정상 상태는 1시간
    $ttl = !empty($cached['custom_missing_scope']) ? 300 : 3600;
    if (time() - filemtime($cacheFile) < $ttl) { readfile($cacheFile); exit; }
}

// ---- 자주 사용: 내가 반응한 최근 항목들에서 "내가 누른" 이모지 빈도 집계 ----
$freq = [];
$cursor = null; $pages = 0;
do {
    $p = ['limit' => 100];
    if ($cursor) $p['cursor'] = $cursor;
    $r = slackGet('reactions.list', $tok, $p);
    if (empty($r['ok'])) break;                            // missing_scope 등 → 빈 목록
    foreach (($r['items'] ?? []) as $it) {
        foreach (($it['message']['reactions'] ?? ($it['file']['reactions'] ?? [])) as $rc) {
            if (in_array($uid, $rc['users'] ?? [], true)) {
                $n = $rc['name'] ?? '';
                if ($n !== '') $freq[$n] = ($freq[$n] ?? 0) + 1;
            }
        }
    }
    $cursor = $r['response_metadata']['next_cursor'] ?? null;
} while ($cursor && ++$pages < 3);
arsort($freq);
$frequent = array_slice(array_keys($freq), 0, 16);

// ---- 워크스페이스 커스텀 이모지 ----
$custom = [];
$customMissingScope = false;
$r = slackGet('emoji.list', $tok, []);
if (empty($r['ok']) && ($r['error'] ?? '') === 'missing_scope') $customMissingScope = true;   // emoji:read 없음
if (!empty($r['ok'])) {
    $all = $r['emoji'] ?? [];
    foreach ($all as $name => $url) {
        if (strpos($url, 'alias:') === 0) {                // 별칭 → 원본 URL 로 해석
            $target = substr($url, 6);
            if (isset($all[$target]) && strpos($all[$target], 'alias:') !== 0) $custom[$name] = $all[$target];
        } else {
            $custom[$name] = $url;
        }
    }
    ksort($custom);
}

$out = json_encode(['ok' => true, 'frequent' => $frequent, 'custom' => $custom,
                    'custom_missing_scope' => $customMissingScope], JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $out);
echo $out;

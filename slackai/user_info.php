<?php
/**
 * Slack 사용자 정보. 로그인 필요.
 *  GET ?ids=U1,U2   → { ok, users: {id: 이름} }        (멘션 이름 해석용, 영구 캐시)
 *  GET ?id=U...     → { ok, name, real_name, title, phone, email, image, status }  (프로필 팝업용, 6h 캐시)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/slack_lib.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');
$tok = current_token();

if (isset($_GET['ids'])) {                                 // 이름 일괄 해석
    $ids = array_values(array_filter(explode(',', (string)$_GET['ids']),
                                     fn($x) => preg_match('/^[UW][A-Z0-9]{5,}$/', $x)));
    echo json_encode(['ok' => true, 'users' => $ids ? slackResolveUsers($tok, $ids) : []], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match('/^[UW][A-Z0-9]{5,}$/', $id)) { echo json_encode(['ok' => false]); exit; }

// 프로필 캐시 (6시간)
$cacheFile = sys_get_temp_dir() . '/slack_profile_cache.json';
$cache = is_file($cacheFile) ? (json_decode(file_get_contents($cacheFile), true) ?: []) : [];
if (isset($cache[$id]) && (time() - ($cache[$id]['ts'] ?? 0) < 6 * 3600)) {
    echo json_encode($cache[$id]['p'], JSON_UNESCAPED_UNICODE);
    exit;
}

$r = slackGet('users.info', $tok, ['user' => $id]);
if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => $r['error'] ?? '?']); exit; }
$u = $r['user'] ?? [];
$p = $u['profile'] ?? [];
$out = [
    'ok'        => true,
    'name'      => ($p['display_name'] ?? '') !== '' ? $p['display_name'] : ($p['real_name'] ?? ($u['name'] ?? $id)),
    'real_name' => $p['real_name'] ?? '',
    'title'     => $p['title'] ?? '',
    'phone'     => $p['phone'] ?? '',
    'email'     => $p['email'] ?? '',
    'image'     => $p['image_192'] ?? ($p['image_72'] ?? ''),
    'status'    => trim(($p['status_emoji'] ?? '') . ' ' . ($p['status_text'] ?? '')),
];
$cache[$id] = ['ts' => time(), 'p' => $out];
@file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
echo json_encode($out, JSON_UNESCAPED_UNICODE);

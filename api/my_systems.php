<?php
/**
 * 내 업무 시스템 메뉴 — 메인 카드와 같은 묶음·순서·알림 수.
 *
 *   GET   → { groups:[{name|null, items:[{key, emoji, label, url, badge}]}], badges:{key:n} }
 *
 * PHP 모듈은 core/worksystems.php::work_systems_menu() 로 바로 그린다. 이 API 는
 * PHP 를 못 쓰는 book(FastAPI, 다른 포트)이 상단바 드롭다운을 같은 모양으로
 * 그리려고 부른다. url 은 포털 루트 기준이다 — 앞에 포털 주소를 붙이는 것은 book 이다.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 다른 출처 허용은 **같은 호스트(포트만 다른 곳)** 에만             │
 * │                                                                  │
 * │ book 은 포털과 같은 호스트의 다른 포트다(readme — SSO 쿠키 전제). │
 * │ 브라우저에게는 다른 출처라 CORS 가 없으면 응답을 못 읽는다.       │
 * │ 아무 Origin 이나 되돌려 주면 남의 사이트가 로그인한 사람의 메뉴를 │
 * │ 읽어 갈 수 있으니, 호스트 이름이 이 요청과 같을 때만 허용한다.    │
 * │ 읽기 전용(GET)이고 내용도 첫 화면에 보이는 값뿐이다.              │
 * └──────────────────────────────────────────────────────────────────┘
 */
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/worksystems.php';
header('Content-Type: application/json; charset=utf-8');

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $oHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $sHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($oHost !== '' && $oHost === $sHost) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => '허용되지 않는 요청입니다'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_portal_login();

$data = work_systems_menu_data('');
$data['groups'] = array_map(static function ($g) {
    return [
        'name'  => $g['name'],
        'items' => array_map(static function ($s) {
            return [
                'key'   => $s['key'],
                'emoji' => $s['emoji'],
                'label' => $s['label'],
                'url'   => $s['url'],
                'badge' => !empty($s['badge']),
            ];
        }, $g['items']),
    ];
}, $data['groups']);
$data['badges'] = (object)$data['badges'];   // 비어도 {} 로 — 화면이 키로 찾는다

echo json_encode($data, JSON_UNESCAPED_UNICODE);

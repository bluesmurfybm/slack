<?php
/**
 * 공통 상단바 드롭다운의 "업무 시스템" 목록을 읽어 그린다.
 * 목록 자체의 원본은 worksystems.json 하나다 — 시스템이 늘거나 이름이 바뀌면 거기만 고친다.
 * (별도 프로세스인 book 은 PHP 를 못 쓰니 book/app.py 가 같은 json 을 직접 읽는다.)
 *
 * json 의 path 는 모두 "포털 루트 기준"이라 페이지는 자기 위치에서 포털 루트까지 되짚는
 * 접두사($base)를 넘겨야 한다 (포털 = '', dti/learn/moodle/slack = '../',
 * slack/xxx/ 하위 폴더 = '../../', access = '../slack/' + '../').
 */

/** 포털 루트 기준 path 에 $base 를 붙인 업무 시스템 목록. */
function work_systems(string $base = ''): array {
    // 한 요청에서 여러 번 불린다(대시보드 타일 + 드롭다운) — 원본 읽기는 한 번만 한다
    static $table = null;
    static $bookUrl = null;
    if ($table === null) {
        $table = json_decode(file_get_contents(__DIR__ . '/worksystems.json'), true);
        $cfg = require dirname(__DIR__) . '/config.php';
        $bookUrl = $cfg['links']['book'] ?? null;
    }

    $systems = $table;
    foreach ($systems as &$sys) {
        // book 만은 별도 프로세스라 config.local.php 의 'book_url' 로 절대주소를 덮어쓸 수 있다.
        $url = $sys['key'] === 'book' && $bookUrl !== null ? $bookUrl : $sys['path'];
        // 절대주소면 그대로 두고, 상대경로면 포털 루트까지 되짚어준다.
        $sys['url'] = preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : $base . $url;
    }
    unset($sys);

    return $systems;
}

/** key 에 해당하는 업무 시스템 이름. 모르는 key 면 null. */
function work_system_label(string $key): ?string {
    foreach (work_systems() as $sys) {
        if ($sys['key'] === $key) {
            return $sys['label'];
        }
    }
    return null;
}

/**
 * 미로그인으로 포털에 되돌려보내진 사용자에게 보여줄 문구.
 * 모듈들이 ?need_login=<key> 를 붙여 보내므로 왜 튕겼는지 이름까지 말해 줄 수 있다.
 * key 가 없거나 모르는 값이면 이름 없이 알린다.
 */
function need_login_notice(?string $key): string {
    if ($key === null || $key === '') {
        return '';
    }
    $label = work_system_label($key);
    return $label === null ? '로그인이 필요합니다' : '로그인이 필요합니다 — ' . $label;
}

/**
 * dd-menu 안에 넣을 "업무 시스템" 섹션 HTML.
 * $current 에 자기 모듈 key 를 주면 그 줄이 현재 위치로 표시된다(.on).
 * $newTab 은 포털 대시보드용 — 타일과 똑같이 새 탭으로 열리게 한다.
 */
function work_systems_menu(string $base = '', string $current = '', bool $newTab = false): string {
    $html = '<div class="dd-label">업무 시스템</div>';
    foreach (work_systems($base) as $sys) {
        $html .= '<a href="' . htmlspecialchars($sys['url'], ENT_QUOTES, 'UTF-8') . '"'
            . ($sys['key'] === $current ? ' class="on" aria-current="page"' : '')
            . ($newTab ? ' target="_blank" rel="noopener"' : '')
            . '>' . $sys['emoji'] . ' ' . htmlspecialchars($sys['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }
    return $html;
}

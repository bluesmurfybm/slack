<?php

const DTI_PAGES = [
    'list' => ['path' => 'index.php', 'label' => '아티클 목록', 'admin' => false],
    'articles' => ['path' => 'admin/index.php', 'label' => '아티클 관리', 'admin' => true],
    'archive' => ['path' => 'admin/archive.php', 'label' => '보관함', 'admin' => true],
    'rounds' => ['path' => 'admin/rounds.php', 'label' => '회차', 'admin' => true],
    'fields' => ['path' => 'admin/fields.php', 'label' => '기타 관리', 'admin' => true],
    'stats' => ['path' => 'admin/stats.php', 'label' => '통계', 'admin' => true],
    'score' => ['path' => 'admin/score.php', 'label' => '점수', 'admin' => true],
];

// 클래식 스크립트라 인라인 onclick 이 전역에서 함수를 찾는다. 앞 파일의 최상위 코드가 뒤 파일을 부르지 않는 한 순서는 자유다
const DTI_LIST_SCRIPTS = ['material', 'related', 'drawer', 'claim'];
const DTI_ADMIN_LIST_SCRIPTS = [...DTI_LIST_SCRIPTS, 'form', 'assign', 'admin'];
const DTI_PAGE_SCRIPTS = [
    'list' => DTI_LIST_SCRIPTS,
    'articles' => DTI_ADMIN_LIST_SCRIPTS,
    'archive' => DTI_ADMIN_LIST_SCRIPTS,
    'rounds' => [],
    'fields' => ['admin'],
    'stats' => ['assign', 'stats'],
    'score' => ['score'],
];

function dti_page(string $key): array {
    if (!isset(DTI_PAGES[$key])) throw new InvalidArgumentException("없는 페이지입니다: {$key}");

    $page = DTI_PAGES[$key];
    return $page + [
        'key' => $key,
        'base' => str_repeat('../', substr_count($page['path'], '/')),
        'scripts' => ['core', 'topics', 'emotion', 'rounds', 'url', ...DTI_PAGE_SCRIPTS[$key], 'main'],
    ];
}

function dti_page_href(array $from, string $to): string {
    return $from['base'] . DTI_PAGES[$to]['path'];
}

/** 들어와도 되면 null, 아니면 보낼 곳 */
function dti_page_redirect(array $config, ?array $identity, array $page): ?string {
    if (!$identity) return $page['base'] . '../index.php?need_login=dti';
    if ($page['admin'] && !dti_is_admin($config, $identity['email'])) return dti_page_href($page, 'list');
    return null;
}

/** dti/ 기준 경로에 수정시각 판 번호를 붙인다 — 고칠 때마다 손으로 올리는 값은 언젠가 어긋난다 */
function dti_asset(array $page, string $rel): string {
    $mtime = @filemtime(__DIR__ . '/../' . $rel);
    return $page['base'] . $rel . '?v=' . ($mtime ?: 0);
}

function dti_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

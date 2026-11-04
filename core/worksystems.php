<?php
/**
 * 공통 상단바 드롭다운의 "업무 시스템" 목록을 읽어 그린다.
 * 목록 자체의 원본은 worksystems.json 하나다 — 시스템이 늘거나 이름이 바뀌면 거기만 고친다.
 *
 * json 의 path 는 모두 "포털 루트 기준"이라 페이지는 자기 위치에서 포털 루트까지 되짚는
 * 접두사($base)를 넘겨야 한다 (포털 = '', book/dti/learn/moodle/slack = '../',
 * slack/xxx/ 하위 폴더 = '../../', access = '../slack/' + '../').
 */

/** 포털 루트 기준 path 에 $base 를 붙인 업무 시스템 목록. */
function work_systems(string $base = ''): array {
    // 한 요청에서 여러 번 불린다(대시보드 타일 + 드롭다운) — 원본 읽기는 한 번만 한다
    static $table = null;
    if ($table === null) {
        $table = json_decode(file_get_contents(__DIR__ . '/worksystems.json'), true);
    }

    $systems = $table;
    foreach ($systems as &$sys) {
        // 절대주소면 그대로 두고, 상대경로면 포털 루트까지 되짚어준다.
        $url = $sys['path'];
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

/** key → 카드 아이콘 SVG(styles/icons/<key>.svg). */
function work_system_icons(): array {
    $icons = [];
    foreach (glob(dirname(__DIR__) . '/styles/icons/*.svg') as $file) {
        $icons[basename($file, '.svg')] = trim(file_get_contents($file));
    }
    return $icons;
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
 * 드롭다운에 그릴 업무 시스템 — 메인 카드와 같은 묶음·순서·알림 수.
 *
 *   ['groups' => [['name' => 묶음 이름|null, 'items' => [시스템, …]], …],
 *    'badges' => [key => 안 읽은 알림 수]]
 *
 * 규칙은 메인(index.php::groupedTiles)과 같다.
 *   - 순서는 사람마다(portal_users.tile_order), 묶음과 소속은 전사 공통
 *   - 어느 묶음에도 안 든 시스템은 맨 끝 '기타'. 빈 묶음은 뺀다
 *   - 메인에서 접어 둔 묶음도 여기서는 펼쳐 보인다 — 드롭다운은 바로 가기다
 * 묶음이 하나도 없으면 name 이 null 인 묶음 하나로 돌려준다(예전처럼 한 줄 나열).
 *
 * 로그인 전이거나 포털 DB 를 못 읽으면 json 순서 그대로 한 묶음이다.
 * 메뉴는 곁가지라 여기서 실패해도 페이지는 떠야 한다.
 *
 * current_portal_user() 를 쓰지 않는다 — SSO 쿠키를 다시 굽는데, 메뉴는 본문
 * 출력이 시작된 뒤에 그려지므로 헤더를 보낼 수 없다.
 */
function work_systems_menu_data(string $base = ''): array {
    $systems = work_systems($base);
    $plain   = ['groups' => [['name' => null, 'items' => $systems]], 'badges' => []];

    $uid = (int)($_SESSION['portal_uid'] ?? 0);
    // auth.php(세션 시작)를 이미 거친 페이지에서만 — 여기서 처음 부르면 출력 뒤라 세션을 못 연다
    if (!$uid || !function_exists('current_portal_user')) {
        return $plain;
    }
    try {
        // board.php 는 이 파일을 부른다. 위에서 부르면 돌고 돌아 순서가 꼬이니 쓸 때 부른다.
        require_once __DIR__ . '/board.php';
        require_once __DIR__ . '/push.php';

        $st = portal_db()->prepare("SELECT tile_order FROM portal_users WHERE id = ?");
        $st->execute([$uid]);
        $sorted = board_sort_tiles($systems, $st->fetchColumn() ?: null);

        $assign  = board_tile_assignments();
        $buckets = [];
        foreach (board_tile_groups() as $g) {
            $buckets[(int)$g['id']] = ['name' => (string)$g['name'], 'items' => []];
        }
        $rest = [];
        foreach ($sorted as $sys) {
            $gid = $assign[$sys['key']] ?? null;
            if ($gid !== null && isset($buckets[$gid])) {
                $buckets[$gid]['items'][] = $sys;
            } else {
                $rest[] = $sys;
            }
        }

        if (!$buckets) {
            $groups = [['name' => null, 'items' => $sorted]];
        } else {
            $groups = array_values(array_filter($buckets, function ($b) { return $b['items']; }));
            if ($rest) {
                $groups[] = ['name' => '기타', 'items' => $rest];
            }
        }
        return ['groups' => $groups, 'badges' => push_tile_badges($uid, $systems),
                'previews' => push_tile_previews($uid, $systems)];
    } catch (Throwable $e) {
        error_log('[worksystems] 메뉴 묶음: ' . $e->getMessage());
        return $plain;
    }
}

/**
 * dd-menu 안에 넣을 "업무 시스템" 섹션 HTML.
 * $current 에 자기 모듈 key 를 주면 그 줄이 현재 위치로 표시된다(.on).
 * $newTab 은 포털 대시보드용 — 타일과 똑같이 새 탭으로 열리게 한다.
 * 묶음·순서·알림 수는 work_systems_menu_data() 가 정한다.
 */
function work_systems_menu(string $base = '', string $current = '', bool $newTab = false): string {
    $h    = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $data = work_systems_menu_data($base);

    $html = '<div class="dd-label">업무 시스템</div><div class="dd-systems">';
    foreach ($data['groups'] as $i => $g) {
        if ($g['name'] !== null) {
            $html .= ($i ? '<div class="dd-sep"></div>' : '')
                . '<div class="dd-group">' . $h($g['name']) . '</div>';
        }
        foreach ($g['items'] as $sys) {
            $n = (int)($data['badges'][$sys['key']] ?? 0);
            $html .= '<a href="' . $h($sys['url']) . '" data-key="' . $h($sys['key']) . '"'
                . ($sys['key'] === $current ? ' class="on" aria-current="page"' : '')
                . ($newTab ? ' target="_blank" rel="noopener"' : '')
                . '>' . $sys['emoji'] . ' ' . $h($sys['label'])
                . (!empty($sys['badge'])
                    ? '<span class="dd-badge"' . ($n ? '' : ' hidden') . ' title="'
                      . $h(function_exists('push_tile_tip')
                          ? push_tile_tip($n, $data['previews'][$sys['key']] ?? [])
                          : '안 읽은 알림 ' . $n . '건') . '">'
                      . ($n > 99 ? '99+' : $n) . '</span>'
                    : '')
                . '</a>';
        }
    }
    return $html . '</div>';
}

/**
 * 상단바 종 — 그 모듈에서 온 안 읽은 알림 수와 최근 소식 몇 줄.
 *
 * 포털 메인에만 종이 있어서 모듈 안에 있는 동안에는 새 소식을 몰랐다. studio
 * (bs_layout_bell)·sitehub(sitehub_notice_bell) 에 먼저 붙인 모양을 전 모듈로 넓힌 것이다.
 * 숫자에 마우스를 올리거나 누르면 최근 소식이 펼쳐진다.
 *
 * 원본은 포털 알림함(portal_notification) — 따로 세지 않는다. 어느 모듈 것인지는
 * push_kinds.json 의 sys 로 가른다. 카드 배지·메뉴 숫자(push_inbox_unread_by_sys)와 같은 규칙이라
 * 세 곳의 숫자가 늘 같다.
 *
 * 메뉴(work_systems_menu_data)와 같은 이유로 로그인 세션을 거친 페이지에서만 그린다.
 * 곁가지라 실패하면 아무것도 그리지 않는다. 화면은 떠야 한다.
 */
function work_systems_bell(string $base, string $key): string {
    $h     = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $uid   = (int)($_SESSION['portal_uid'] ?? 0);
    $label = work_system_label($key);
    if (!$uid || $label === null || !function_exists('current_portal_user')) {
        return '';
    }
    try {
        require_once __DIR__ . '/push.php';
        $kinds = array_keys(array_filter(push_kinds(), function ($k) use ($key) { return $k['sys'] === $key; }));
        $n = 0;
        $rows = [];
        if ($kinds) {
            $in = implode(',', array_fill(0, count($kinds), '?'));
            $st = portal_db()->prepare("SELECT COUNT(*) FROM portal_notification
                                         WHERE user_id = ? AND read_at IS NULL AND kind IN ($in)");
            $st->execute(array_merge([$uid], $kinds));
            $n = (int)$st->fetchColumn();
            if ($n) {
                $st = portal_db()->prepare("SELECT title, body, url, created_at FROM portal_notification
                                             WHERE user_id = ? AND read_at IS NULL AND kind IN ($in)
                                             ORDER BY id DESC LIMIT 6");
                $st->execute(array_merge([$uid], $kinds));
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        error_log('[worksystems] 상단 종(' . $key . '): ' . $e->getMessage());
        return '';
    }

    $tip = push_tile_tip($n, array_map(function ($r) { return mb_strimwidth((string)$r['title'], 0, 60, '…'); },
                                      array_slice($rows, 0, 3)));
    $items = '';
    foreach ($rows as $r) {
        $url  = (string)$r['url'];
        $href = $url === '' ? $base . 'index.php'
              : (preg_match('#^https?://#i', $url) ? $url : $base . ltrim($url, '/'));
        $body = (string)$r['body'];
        $items .= '<a class="tb-bell__item" href="' . $h($href) . '">'
            . '<b>' . $h($r['title']) . '</b>'
            . ($body !== '' ? '<span>' . $h(mb_strimwidth($body, 0, 80, '…')) . '</span>' : '')
            . '<i>' . $h(date('m-d H:i', strtotime((string)$r['created_at']))) . '</i></a>';
    }
    return '<div class="tb-bell' . ($n ? ' has-new' : '') . '">'
        . '<button type="button" class="tb-bell__btn" aria-haspopup="true" aria-expanded="false"'
        . ' aria-label="' . $h($label) . ' 알림 ' . $n . '건" title="' . $h($tip) . '"'
        . ' onclick="var p=this.parentNode;p.classList.toggle(\'open\');this.setAttribute(\'aria-expanded\',p.classList.contains(\'open\'))">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>'
        . ($n ? '<span class="tb-bell__n">' . ($n > 99 ? '99+' : $n) . '</span>' : '')
        . '</button>'
        . '<div class="tb-bell__pop" role="menu">'
        . '<div class="tb-bell__head">' . $h($label) . ' 새 소식' . ($n ? ' <em>' . $n . '</em>' : '') . '</div>'
        . ($items ?: '<p class="tb-bell__empty">새 소식이 없습니다.</p>')
        . '<a class="tb-bell__all" href="' . $h($base) . 'index.php">포털 알림함에서 모두 보기</a>'
        . '</div></div>';
}

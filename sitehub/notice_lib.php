<?php
/**
 * 알림 — 포털 알림함·웹 푸시(core/push.php)로 보낸다. 새로 등록된 것과 BLUMO 장애만 알린다.
 *  - 등록: sitehub_audit() 의 create 만. api.php 의 사용자 요청에서만 켠다. 엑셀 가져오기·미매칭 자동 정리는 알리지 않는다.
 *    한 요청의 등록은 사이트마다 한 건으로 묶어 응답을 보낸 뒤에 보낸다. 요청이 실패하면 버린다. 값은 싣지 않는다.
 *  - 장애: 장애가 확정될 때 한 번. 지속·복구는 보내지 않고, 같은 사이트는 SITEHUB_NOTICE_DOWN_QUIET_MIN 분 안에 다시 보내지 않는다.
 */

const SITEHUB_NOTICE_QUIET_MIN = 10;
const SITEHUB_NOTICE_DOWN_QUIET_MIN = 60;
const SITEHUB_NOTICE_TARGETS = ['site' => '사이트', 'accounts' => '접속 정보', 'contacts' => '담당자', 'contracts' => '계약',
    'costs' => '비용 내역', 'monitors' => '감시 주소', 'procedures' => '처리 절차', 'history' => '이력'];
const SITEHUB_NOTICE_TABS = ['accounts' => 'accounts', 'contacts' => 'contacts', 'contracts' => 'contracts', 'costs' => 'costs',
    'monitors' => 'monitor', 'history' => 'history'];

function &sitehub_notice_buf(): array {
    static $buf = ['me' => null, 'sites' => []];
    return $buf;
}

function sitehub_notice_start(array $me): void {
    $buf = &sitehub_notice_buf();
    if ($buf['me'] === null) register_shutdown_function('sitehub_notice_flush');
    $buf['me'] = $me;
}

function sitehub_notice_drop(): void {
    $buf = &sitehub_notice_buf();
    $buf['sites'] = [];
}

function sitehub_notice_add(int $siteId, string $action, string $target, ?int $rowId, string $rowLabel): void {
    $buf = &sitehub_notice_buf();
    if ($buf['me'] === null || $siteId <= 0 || $action !== 'create' || !isset(SITEHUB_NOTICE_TARGETS[$target])) return;
    $buf['sites'][$siteId][$target . ':' . (int)$rowId] = ['target' => $target, 'label' => $rowLabel];
}

function sitehub_notice_flush(): void {
    $buf = &sitehub_notice_buf();
    $sites = $buf['sites'];
    $buf['sites'] = [];
    if (!$sites || $buf['me'] === null) return;
    // 웹 푸시는 기기마다 HTTP 를 부른다 — 저장한 사람의 응답과 다음 요청(세션 잠금)이 그만큼 기다리지 않게 먼저 놓는다
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    foreach ($sites as $siteId => $events) {
        try {
            sitehub_notice_send($buf['me'], (int)$siteId, array_values($events));
        } catch (Throwable $e) {
            error_log('[sitehub] 변경 알림 실패: ' . $e->getMessage());
        }
    }
}

function sitehub_notice_phrase(array $e): string {
    if ($e['target'] === 'site') return '새 사이트 등록';
    return SITEHUB_NOTICE_TARGETS[$e['target']] . ($e['label'] !== '' ? " '" . $e['label'] . "'" : '') . ' 등록';
}

function sitehub_notice_send(array $me, int $siteId, array $events): void {
    require_once __DIR__ . '/../core/push.php';
    $st = sitehub_db()->prepare("SELECT name FROM lab_sites WHERE id = ?");
    $st->execute([$siteId]);
    $name = $st->fetchColumn();
    if ($name === false) return;
    $structural = (bool)array_filter($events, fn($e) => $e['target'] === 'site');
    $kind = $structural ? 'sitehub.site' : 'sitehub.change';
    $base = 'sitehub/index.php?site=' . $siteId;
    $tab = SITEHUB_NOTICE_TABS[$events[0]['target']] ?? '';
    $to = sitehub_notice_recipients($me, $structural ? null : $base);
    if (!$to) return;
    $phrases = array_map('sitehub_notice_phrase', $events);
    push_send([
        'kind' => $kind,
        'title' => $name . ' · ' . mb_strimwidth($phrases[0], 0, 60, '…') . (count($phrases) > 1 ? ' 외 ' . (count($phrases) - 1) . '건' : ''),
        'body' => mb_strimwidth(trim(($me['name'] ?? '') . ' · ' . implode(', ', $phrases)), 0, 200, '…'),
        'url' => $base . ($tab !== '' ? '&tab=' . $tab : ''),
        'to' => $to,
        'tag' => 'sitehub-' . $siteId,
    ]);
}

/** BLUMO 가 장애를 확정했을 때. blumo_state() 가 장애 한 번에 한 번 부른다 */
function sitehub_notice_down(array $m, array $r): void {
    try {
        require_once __DIR__ . '/../core/push.php';
        $url = 'sitehub/index.php?site=' . (int)$m['site_id'] . '&tab=monitor';
        // 한 서버의 주소 여러 개가 같이 죽거나 죽었다 살았다 할 때 쌓이지 않게
        $st = portal_db()->prepare("SELECT 1 FROM portal_notification WHERE kind = 'sitehub.down' AND url = ?
                                     AND created_at > NOW() - INTERVAL " . SITEHUB_NOTICE_DOWN_QUIET_MIN . " MINUTE LIMIT 1");
        $st->execute([$url]);
        if ($st->fetchColumn()) return;
        push_send([
            'kind' => 'sitehub.down',
            'title' => '장애 · ' . $m['name'],
            'body' => mb_strimwidth((string)$r['cause'], 0, 200, '…'),
            'url' => $url,
            'tag' => 'sitehub-down-' . (int)$m['site_id'],
            'urgency' => 'high',
        ]);
    } catch (Throwable $e) {
        error_log('[sitehub] 장애 알림 실패: ' . $e->getMessage());
    }
}

function sitehub_notice_recipients(array $me, ?string $quietUrl): array {
    $pdo = portal_db();
    $skip = [strtolower((string)($me['email'] ?? ''))];
    if ($quietUrl !== null) {   // 드로어는 칸 하나씩 저장한다 — 저장할 때마다 쌓이지 않게
        $st = $pdo->prepare("SELECT u.email FROM portal_notification n JOIN portal_users u ON u.id = n.user_id
                              WHERE n.kind = 'sitehub.change' AND n.read_at IS NULL
                                AND n.created_at > NOW() - INTERVAL " . SITEHUB_NOTICE_QUIET_MIN . " MINUTE
                                AND (n.url = ? OR n.url LIKE ?)");
        $st->execute([$quietUrl, addcslashes($quietUrl, '%_\\') . '&%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $skip[] = strtolower((string)$e);
    }
    $emails = $pdo->query("SELECT email FROM portal_users")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter($emails, fn($e) => !in_array(strtolower((string)$e), $skip, true)));
}

/** 안 읽은 알림이 걸린 사이트 — site_id => 건수 */
function sitehub_notice_unread(int $uid): array {
    $out = [];
    try {
        require_once __DIR__ . '/../core/push.php';
        foreach (push_inbox_unread_urls($uid, 'sitehub.') as $url => $n) {
            if (preg_match('#^sitehub/index\.php\?site=(\d+)#', $url, $m)) $out[(int)$m[1]] = ($out[(int)$m[1]] ?? 0) + $n;
        }
    } catch (Throwable $e) {
        error_log('[sitehub] 안 읽은 알림: ' . $e->getMessage());
    }
    return $out;
}

function sitehub_notice_count(int $uid): int {
    try {
        require_once __DIR__ . '/../core/push.php';
        $st = portal_db()->prepare("SELECT COUNT(*) FROM portal_notification WHERE user_id = ? AND read_at IS NULL AND kind LIKE 'sitehub.%'");
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[sitehub] 알림 수: ' . $e->getMessage());
        return 0;
    }
}

function sitehub_notice_seen(int $uid, int $siteId): void {
    try {
        require_once __DIR__ . '/../core/push.php';
        push_inbox_mark_read_url($uid, 'sitehub.', 'sitehub/index.php?site=' . $siteId);
    } catch (Throwable $e) {
        error_log('[sitehub] 알림 읽음: ' . $e->getMessage());
    }
}

/** 상단바 종. 실패하면 아무것도 그리지 않는다(화면은 떠야 한다) */
function sitehub_notice_bell(array $me): string {
    $uid = (int)($me['id'] ?? 0);
    if (!$uid) return '';
    try {
        require_once __DIR__ . '/../core/push.php';
        $st = portal_db()->prepare("SELECT title, body, url, created_at FROM portal_notification
                                     WHERE user_id = ? AND read_at IS NULL AND kind LIKE 'sitehub.%' ORDER BY id DESC LIMIT 6");
        $st->execute([$uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $n = sitehub_notice_count($uid);
    } catch (Throwable $e) {
        error_log('[sitehub] 상단 종: ' . $e->getMessage());
        return '';
    }
    $tip = push_tile_tip($n, array_map(fn($r) => mb_strimwidth((string)$r['title'], 0, 60, '…'), array_slice($rows, 0, 3)));
    $items = '';
    foreach ($rows as $r) {
        $site = preg_match('#[?&]site=(\d+)#', (string)$r['url'], $m) ? $m[1] : '';
        $items .= '<a class="sh-bell__item" data-site="' . $site . '" href="../' . sitehub_h(ltrim((string)$r['url'], '/')) . '">'
            . '<b>' . sitehub_h((string)$r['title']) . '</b>'
            . ($r['body'] !== '' ? '<span>' . sitehub_h(mb_strimwidth((string)$r['body'], 0, 80, '…')) . '</span>' : '')
            . '<i>' . sitehub_h(date('m-d H:i', strtotime((string)$r['created_at']))) . '</i></a>';
    }
    return '<div class="sh-bell' . ($n ? ' has-new' : '') . '" id="shBell">'
        . '<button type="button" class="sh-bell__btn" aria-haspopup="true" aria-expanded="false"'
        . ' aria-label="SiteHub 알림 ' . $n . '건" title="' . sitehub_h($tip) . '"'
        . ' onclick="var p=this.parentNode;p.classList.toggle(\'open\');this.setAttribute(\'aria-expanded\',p.classList.contains(\'open\'))">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>'
        . '<span class="sh-bell__n"' . ($n ? '' : ' hidden') . '>' . ($n > 99 ? '99+' : $n) . '</span>'
        . '</button>'
        . '<div class="sh-bell__pop" role="menu">'
        . '<div class="sh-bell__head">SiteHub 새 소식 <em>' . ($n ?: '') . '</em></div>'
        . $items
        . '<p class="sh-bell__empty"' . ($items === '' ? '' : ' hidden') . '>새 소식이 없습니다.</p>'
        . '<a class="sh-bell__all" href="../index.php">포털 알림함에서 모두 보기</a>'
        . '</div></div>';
}

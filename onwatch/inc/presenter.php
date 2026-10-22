<?php
/**
 * 권한과 할 수 있는 일을 서버가 계산한다. 화면은 이 값만 보고 단추를 그린다.
 *
 *  - 보기·새로 만들기·복제·받기(구독): 로그인한 누구나
 *  - 고치기·일시정지·종료·연장·기준 상태 반영·지우기: 등록자, 포털 관리자
 *  - 지금 실행·소식 평가: 등록자, 받는 사람, 포털 관리자 — 실행은 돈이 들고
 *    평가는 다음 실행을 바꾸므로 지나가는 사람에게는 열지 않는다
 */

function ow_is_admin(array $u): bool
{
    if (!function_exists('board_is_admin')) require_once OW_PORTAL_ROOT . '/core/board.php';
    return board_is_admin($u['email'] ?? '');
}

function ow_perms(array $w, array $u, ?bool $subscribed = null): array
{
    $uid     = (int)$u['id'];
    $owner   = (int)$w['owner_id'] === $uid;
    $admin   = ow_is_admin($u);
    $sub     = $subscribed ?? ow_is_subscribed((int)$w['id'], $uid);
    $manage  = $owner || $admin;
    $member  = $manage || $sub;
    $ended   = $w['status'] === 'ended';
    $busy    = !empty($w['locked_until']) && strtotime((string)$w['locked_until']) > time();
    $queued  = !empty($w['run_requested_at']);
    return [
        'is_owner'        => $owner,
        'is_subscribed'   => $sub,
        'can_edit'        => $manage && !$ended,
        'can_pause'       => $manage && $w['status'] === 'active',
        'can_resume'      => $manage && $w['status'] === 'paused',
        'can_end'         => $manage && !$ended,
        'can_extend'      => $manage,
        'can_delete'      => $manage,
        'can_baseline'    => $manage && !$ended,
        'can_run'         => $member && !$ended && !$busy && !$queued,
        'can_cancel_run'  => $member && $queued && !$busy,
        'can_feedback'    => $member,
        'can_subscribe'   => !$sub && !$ended,
        'can_unsubscribe' => $sub,
        'state'           => $busy ? 'running' : ($queued ? 'queued' : 'idle'),
    ];
}

/** 상세 화면이 몇 초마다 묻는 실행 상태 */
function ow_present_state(array $w, array $u): array
{
    $runs = ow_runs((int)$w['id'], 1);
    $last = $runs[0] ?? null;
    return [
        'id'           => (int)$w['id'],
        'status'       => $w['status'],
        'state'        => ow_perms($w, $u)['state'],
        'requested_at' => $w['run_requested_at'],
        'last_run_id'  => $last ? (int)$last['id'] : null,
        'last_status'  => $last['status'] ?? null,
        'next_run_at'  => $w['next_run_at'],
    ];
}

/** sources_json → [{url,title,published,seen}] — 링크는 http(s) 만 남긴다 */
function ow_sources(?string $json): array
{
    $arr = json_decode((string)$json, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $s) {
        $url = trim((string)($s['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) continue;
        $out[] = [
            'url'       => $url,
            'title'     => (string)($s['title'] ?? '') ?: (string)parse_url($url, PHP_URL_HOST),
            'published' => (string)($s['published'] ?? ''),
            'seen'      => !empty($s['seen']),
        ];
    }
    return $out;
}

/**
 * 아주 작은 마크다운 → HTML. 조사 노트와 소식 본문에만 쓴다.
 * 먼저 전부 이스케이프하고 나서 굵게·코드·링크·목록·제목만 살린다 — 원문 HTML 은 통과시키지 않는다.
 * 정규식은 모두 /u 다. 빠지면 \R 이 '입'·'업'(EC 9E 85 처럼 0x85 로 끝나는 한글)의 끝 바이트를
 * 줄바꿈(NEL)으로 보고 글자를 반으로 자른다.
 */
function ow_md(?string $md): string
{
    $md = (string)$md;
    if (trim($md) === '') return '';
    $lines = preg_split('/\R/u', $md);
    $html = '';
    $inList = false;
    $para = [];
    $flush = function () use (&$para, &$html) {
        if ($para) { $html .= '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
    };
    $inline = function (string $t): string {
        $t = h($t);
        $t = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $t);
        $t = preg_replace('/\*\*([^*]+)\*\*/u', '<b>$1</b>', $t);
        $t = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u', function ($m) {
            return '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
        }, $t);
        // 맨 주소(앞이 따옴표·> 가 아닌 것)도 링크로
        $t = preg_replace('#(?<![">=])(https?://[^\s<]+[^\s<.,)])#u', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $t);
        return $t;
    };
    foreach ($lines as $line) {
        if (preg_match('/^\s*[-*]\s+(.*)$/u', $line, $m)) {
            $flush();
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if ($inList) { $html .= '</ul>'; $inList = false; }
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $line, $m)) {
            $flush();
            $lv = min(4, strlen($m[1]) + 2);
            $html .= "<h$lv>" . $inline($m[2]) . "</h$lv>";
        } elseif (trim($line) === '') {
            $flush();
        } else {
            $para[] = $inline($line);
        }
    }
    if ($inList) $html .= '</ul>';
    $flush();
    return $html;
}

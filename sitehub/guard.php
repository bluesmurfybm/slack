<?php
/**
 * sitehub(BlueLab SiteHub) 모듈 가드.
 *  - 포털(blue-iWorks) 로그인만 요구한다. slack 모듈처럼 개인 Slack 토큰까지 강제하지 않는다
 *    — 사이트 정보 조회는 슬랙과 무관해서 토큰 없는 사람이 막히면 안 된다. 토큰은 이력 동기화(sync.php)에만 쓴다.
 */

date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/../core/auth.php';   // 세션 시작 + current_portal_user()

/** 화면(HTML) 페이지용 — 미로그인이면 포털 로그인 화면으로 보낸다 */
function sitehub_require_login() {
    $u = current_portal_user();
    if (!$u) {
        header('Location: ../index.php?need_login=sitehub');
        exit;
    }
    return $u;
}

/** 읽기 전용 요청에서 세션 잠금을 바로 풀어 동시 AJAX 가 직렬화되지 않게 한다 */
function sitehub_session_release() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

function sitehub_config() {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/../config.php';
    return $cfg;
}

function sitehub_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 모듈 폴더 기준 상대 경로에 파일 수정시각을 붙여 브라우저 캐시를 깨뜨린다 */
function sitehub_asset($rel) {
    $mtime = @filemtime(__DIR__ . '/' . $rel);
    return $rel . '?v=' . ($mtime ?: 0);
}

/**
 * 이력 동기화에 쓸 Slack 토큰. BlueLab 리스트는 thebluesoft.slack.com 에 있어 포털의 개인 토큰(coursemos 용)으로는
 * 못 읽는 게 보통이라 config.local.php 의 토큰을 먼저 본다. 없으면 개인 토큰, CLI 는 SLACK_TOKEN 환경변수.
 */
function sitehub_sync_token(?array $u = null) {
    $cfg = sitehub_config();
    if (!empty($cfg['sitehub_slack_token'])) return (string)$cfg['sitehub_slack_token'];
    if ($u && !empty($u['slack_token_enc'])) {
        $t = dec_token($u['slack_token_enc']);
        if ($t !== '') return $t;
    }
    if (PHP_SAPI === 'cli' && getenv('SLACK_TOKEN')) return (string)getenv('SLACK_TOKEN');
    return null;
}

/** 동기화 버튼을 보여 줄 수 있는가 — 리스트 ID 와 토큰이 둘 다 있을 때만 */
function sitehub_can_sync(?array $u = null) {
    $cfg = sitehub_config();
    return !empty($cfg['sitehub_list_id']) && sitehub_sync_token($u) !== null;
}

/* ── 통계 열람 권한 ─────────────────────────────────────────────────────────────
   요약 네 칸 · 통계 화면(stats.php) · API ?stats 는 지정 계정만. dti 의 DTI_DEFAULT_ADMINS 와 같은 방식 —
   config.php 의 'sitehub_stats_emails' 가 있으면 그 목록, 없으면 아래 기본값. 화면에서 숨기는 것만으로 끝내지 않고
   API 도 403 으로 막는다(주소를 직접 쳐서 보는 것 방지). */
const SITEHUB_STATS_DEFAULT_EMAILS = ['pink@bluesoft.co.kr'];

function sitehub_stats_emails(): array {
    $list = sitehub_config()['sitehub_stats_emails'] ?? SITEHUB_STATS_DEFAULT_EMAILS;
    return array_values(array_filter(array_map(fn($e) => strtolower(trim((string)$e)), (array)$list)));
}

function sitehub_can_stats(?array $u): bool {
    return $u !== null && !empty($u['email']) && in_array(strtolower((string)$u['email']), sitehub_stats_emails(), true);
}

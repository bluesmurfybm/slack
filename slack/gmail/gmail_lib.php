<?php
/**
 * Gmail 공용 헬퍼 — gmail_sync.php(동기화) / index.php(뷰어) 가 공유.
 *  - 설정 로드, IMAP 접속, gmail_mails 테이블 보장, MIME 디코딩.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../slack_lib.php';   // slack_runtime_dir() — 동기화 잠금을 웹·감시 데몬(CLI)이 같은 폴더에서 공유

/** Gmail 접속 설정 — 사용자별.
 *  - 웹: 로그인한 포털 사용자가 마이페이지에 등록한 주소 + 앱 비밀번호
 *        (portal_users.gmail_user / gmail_pass_enc, enc_token() 암호문).
 *        등록하지 않았으면 user 가 빈 값이다. config.local.php 의 공용 계정으로 대신 붙지 않는다 —
 *        그러면 미등록 사용자에게 남의 메일함이 보인다.
 *  - CLI(gmail_watch/gmail_sync): --account=<포털 로그인 이메일|Gmail 주소> 또는
 *        GMAIL_ACCOUNT 환경변수로 그 사용자의 등록 정보를 쓴다. 둘 다 없으면 예전처럼 config.local.php 의 단일 계정.
 *  - host/label/search/limit/label_colors 같은 공통 값은 config.local.php 의 gmail 설정을 기본값으로 쓴다. */
function gmail_cfg() {
    static $g = null;
    if ($g !== null) return $g;
    $l = is_file(__DIR__ . '/../config.local.php') ? (require __DIR__ . '/../config.local.php') : [];
    $shared = ($l['gmail'] ?? []) + [
        // 인증서 확인: Linux(운영)는 시스템 CA 로 검증한다 — 앱 비밀번호가 실리는 연결이다.
        // Windows 의 php_imap(c-client)은 시스템 인증서 저장소를 못 찾아 검증이 실패해 로컬만 끈다.
        'host' => PHP_OS_FAMILY === 'Windows'
            ? '{imap.gmail.com:993/imap/ssl/novalidate-cert}'
            : '{imap.gmail.com:993/imap/ssl}',
        'label' => 'INBOX', 'search' => 'ALL', 'limit' => 50,   // 목록 한 쪽 기본 개수
        // 첫 수집 기준일. 메일함이 수만 건이면 전체 백필이 오래 걸려 이 날짜 이후 메일만 모은다.
        // config.local.php 의 gmail.since 로 바꿀 수 있고, '' 로 두면 예전처럼 전체를 모은다.
        'since' => '2026-10-01',
    ];
    if (PHP_SAPI !== 'cli') {
        $cred = gmail_cred_web();
    } elseif (($who = gmail_cli_account()) !== '') {
        $cred = gmail_cred_cli($who);
    } else {
        return $g = $shared;                               // CLI 단일 계정(예전 방식)
    }
    return $g = ['user' => $cred['user'] ?? '', 'pass' => $cred['pass'] ?? ''] + $shared;
}

/** 주소·앱 비밀번호가 모두 등록돼 있는가 (화면에서 '연동 필요' 안내를 띄울지 판단) */
function gmail_configured() {
    $g = gmail_cfg();
    return !empty($g['user']) && (string)($g['pass'] ?? '') !== '';
}

/** portal_users 행 → ['user','pass'] (비밀번호는 여기서만 복호화, 저장·출력하지 않는다) */
function gmail_cred_row($r) {
    if (!$r || empty($r['gmail_user'])) return [];
    return ['user' => strtolower($r['gmail_user']),
            'pass' => $r['gmail_pass_enc'] ? dec_token($r['gmail_pass_enc']) : ''];
}

/** 웹: 세션의 포털 사용자(slack/auth.php → core/auth.php 가 이미 로드돼 있음) */
function gmail_cred_web() {
    $uid = $_SESSION['portal_uid'] ?? null;
    if (!$uid || !function_exists('portal_db') || !function_exists('dec_token')) return [];
    $st = portal_db()->prepare("SELECT gmail_user, gmail_pass_enc FROM portal_users WHERE id = ?");
    $st->execute([$uid]);
    return gmail_cred_row($st->fetch());
}

/** CLI: 대상 계정 — --account=… 인자 우선, 없으면 GMAIL_ACCOUNT 환경변수 */
function gmail_cli_account() {
    foreach (($GLOBALS['argv'] ?? []) as $a) {
        if (strncmp($a, '--account=', 10) === 0) return trim(substr($a, 10));
    }
    return trim((string)getenv('GMAIL_ACCOUNT'));
}

/** CLI: 포털 로그인 이메일 또는 등록된 Gmail 주소로 그 사용자의 연동 정보를 찾는다 */
function gmail_cred_cli($who) {
    if (!function_exists('dec_token')) {
        // dec_token() 은 core/auth.php 에 있고, 그 파일은 include 할 때 포털 .sessions 에 세션을 연다.
        // onwatch/bin/secret.php 처럼 임시 폴더에 세션을 먼저 열어 두고 저장 없이 닫는다.
        // 이미 무언가 출력한 뒤라면(CLI 는 출력 = 헤더 전송) 세션을 못 여는데, 복호화엔 세션이 필요 없다 —
        // core/auth.php 의 세션 설정 경고만 조용히 넘긴다.
        $quiet = headers_sent();
        if (!$quiet && session_status() === PHP_SESSION_NONE) {
            session_save_path(sys_get_temp_dir());
            session_id('gmailcli');
            @session_start();
        }
        $lvl = $quiet ? error_reporting(error_reporting() & ~E_WARNING) : null;
        require_once __DIR__ . '/../../core/auth.php';
        if ($lvl !== null) error_reporting($lvl);
        if (session_status() === PHP_SESSION_ACTIVE) session_abort();
    }
    $st = portal_db()->prepare("SELECT gmail_user, gmail_pass_enc FROM portal_users WHERE email = ? OR gmail_user = ? LIMIT 1");
    $st->execute([$who, strtolower($who)]);
    $c = gmail_cred_row($st->fetch());
    if (!$c) fwrite(STDERR, "[gmail] '$who' 의 Gmail 연동 정보가 없습니다 — 포털 마이페이지에서 등록하세요.
");
    return $c;
}

/** 메일 데이터 소유 구분 키 = Gmail 계정 주소.
 *  회원가입 후에는 각 사용자가 자기 Gmail 을 등록하므로 계정 주소가 곧 사용자 구분이 된다. */
function gmail_owner() {
    return strtolower(trim((string)(gmail_cfg()['user'] ?? '')));
}

/** 계정별 sync_meta (키에 계정 접미사) */
function gmeta_get($key, $default = null) { return meta_get($key . ':' . gmail_owner(), $default); }
function gmeta_set($key, $value) { meta_set($key . ':' . gmail_owner(), $value); }

/** IMAP 접속. [$imap, $err] 반환 (n_retries=1: 반복 실패 시 계정 잠금 방지) */
/** PHP imap 확장 설치 여부. PHP 8.4 부터 기본 설치에서 빠져서 서버에 따로 깔아야 한다(php8.x-imap 또는 PECL). */
const GMAIL_NO_IMAP_MSG = '서버 PHP 에 imap 확장이 없습니다 — 서버에 php-imap 을 설치하고 웹 서버(Apache/php-fpm)를 재시작하세요.';
function gmail_imap_ready() { return function_exists('imap_open'); }

/** @param string|null $mailbox 열 폴더(mUTF-7 이름, 예: gmail_sent_folder()). 비우면 설정의 라벨(기본 받은편지함) */
function gmail_open($mailbox = null) {
    $g = gmail_cfg();
    if (!gmail_imap_ready()) return [null, GMAIL_NO_IMAP_MSG];   // 'Call to undefined function imap_open()' 대신 원인을 바로
    if (!gmail_configured()) {
        return [null, PHP_SAPI === 'cli'
            ? 'Gmail 계정 설정이 없습니다 (config.local.php 의 gmail 또는 --account=<이메일>).'
            : 'Gmail 연동 정보가 없습니다. 포털 마이페이지에서 Gmail 주소와 앱 비밀번호를 등록하세요.'];
    }
    $label = $g['label'] ?: 'INBOX';
    $mb = $g['host'] . ($mailbox !== null && $mailbox !== '' ? $mailbox
                       : (strtoupper($label) === 'INBOX' ? 'INBOX' : imap_utf8_to_mutf7($label)));
    $im = @imap_open($mb, $g['user'], str_replace(' ', '', (string)$g['pass']), 0, 1);
    return $im ? [$im, null] : [null, 'IMAP 접속 실패: ' . imap_last_error()];
}

/** 메일 캐시 테이블 보장. 목록은 이 테이블에서 즉시 조회, 본문은 첫 열람 때 채움.
 *  account = 소유 Gmail 계정(사용자별 데이터 구분). IMAP UID 는 계정(메일함) 안에서만 유일하므로 PK(account,uid). */
function gmail_ensure_table() {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gmail_mails` (
            `account`   VARCHAR(190)  NOT NULL DEFAULT '' COMMENT '소유 Gmail 계정(사용자 구분)',
            `uid`       INT UNSIGNED  NOT NULL COMMENT 'IMAP UID (UIDVALIDITY 바뀌면 전체 리셋)',
            `subject`   VARCHAR(500)  NOT NULL DEFAULT '',
            `sender`    VARCHAR(300)  NOT NULL DEFAULT '',
            `udate`     INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '수신 unixtime',
            `seen`      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1=읽음(\\Seen)',
            `flagged`   TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1=별표(\\Flagged)',
            `body`      MEDIUMTEXT    NULL COMMENT '본문 텍스트(첫 열람 때 채움, NULL=미수집)',
            `body_html` MEDIUMTEXT    NULL COMMENT '정제된 HTML 본문(빈문자열=plain 전용 메일)',
            `atts`      TEXT          NULL COMMENT '첨부 파일명 JSON 배열',
            `thrid`     BIGINT UNSIGNED NULL COMMENT 'Gmail 대화 ID(X-GM-THRID, 첫 열람 때 채움)',
            `labels`    TEXT          NULL COMMENT '사용자 라벨 |a|b| 형식(NULL=미수집, |=라벨 없음)',
            `synced_at` DATETIME      NULL,
            PRIMARY KEY (`account`, `uid`),
            KEY `idx_acct_thrid` (`account`, `thrid`),
            KEY `idx_acct_udate` (`account`, `udate`),
            KEY `idx_acct_seen`  (`account`, `seen`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 보낸편지함 헤더 캐시 — 목록의 대화 건수·최신 활동에 내가 보낸 메일을 넣기 위한 것(본문 없음, 목록에 따로 안 나옴).
    // 동기화는 받은편지함만 읽으므로, 이게 없으면 Gmail 대화에서 내 답장이 빠진다.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gmail_sent` (
            `account` VARCHAR(190)    NOT NULL DEFAULT '',
            `uid`     INT UNSIGNED    NOT NULL COMMENT '보낸편지함 IMAP UID (INBOX 와 별개 공간)',
            `thrid`   BIGINT UNSIGNED NULL COMMENT 'Gmail 대화 ID(X-GM-THRID)',
            `udate`   INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY (`account`, `uid`),
            KEY `idx_acct_thrid` (`account`, `thrid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 보낸 메일 본문 캐시 — 대화를 열 때마다 Gmail 에서 받으면 한 통에 수 초(인용·이미지 포함)라 한 번만 받는다
    add_column_if_missing($pdo, "ALTER TABLE `gmail_sent` ADD COLUMN `subject` VARCHAR(500) NOT NULL DEFAULT '' AFTER `udate`");
    add_column_if_missing($pdo, "ALTER TABLE `gmail_sent` ADD COLUMN `sender` VARCHAR(300) NOT NULL DEFAULT '' AFTER `subject`");
    add_column_if_missing($pdo, "ALTER TABLE `gmail_sent` ADD COLUMN `body` MEDIUMTEXT NULL AFTER `sender`");
    add_column_if_missing($pdo, "ALTER TABLE `gmail_sent` ADD COLUMN `body_html` MEDIUMTEXT NULL COMMENT 'NULL=미수집' AFTER `body`");
    add_column_if_missing($pdo, "ALTER TABLE `gmail_sent` ADD COLUMN `atts` TEXT NULL AFTER `body_html`");
    // 기존 설치본(단일 계정, PK=uid) 마이그레이션: account 컬럼 추가 + 데이터/메타 백필
    $cfg = require __DIR__ . '/../../config.php';
    $has = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='gmail_mails' AND COLUMN_NAME='account'");
    $has->execute([$cfg['db']['name']]);
    if (!$has->fetchColumn()) {
        $acct = gmail_owner();
        $pdo->exec("ALTER TABLE `gmail_mails` ADD COLUMN `account` VARCHAR(190) NOT NULL DEFAULT '' FIRST");
        $pdo->prepare("UPDATE `gmail_mails` SET account = ? WHERE account = ''")->execute([$acct]);
        $pdo->exec("ALTER TABLE `gmail_mails` DROP PRIMARY KEY, ADD PRIMARY KEY (`account`, `uid`)");
        $pdo->exec("ALTER TABLE `gmail_mails` DROP INDEX `idx_udate`, ADD INDEX `idx_acct_udate` (`account`, `udate`)");
        $pdo->exec("ALTER TABLE `gmail_mails` DROP INDEX `idx_seen`, ADD INDEX `idx_acct_seen` (`account`, `seen`)");
        foreach (['gmail_uidvalidity', 'gmail_backfill_next', 'gmail_last_uid', 'gmail_last_sync'] as $k) {
            $v = meta_get($k);
            if ($v !== null && meta_get($k . ':' . $acct) === null) meta_set($k . ':' . $acct, $v);
        }
    }
    // body_html/flagged/labels/thrid 컬럼 마이그레이션. add_column_if_missing()(db.php)이 이미 있는
    // 컬럼 에러(42S21)를 조용히 무시하므로, 페이지 로드 시 여러 요청이 동시에 db()를 불러도 안전하다
    // (신규 설치는 CREATE TABLE에 이미 다 있어 사실상 no-op).
    add_column_if_missing($pdo, "ALTER TABLE `gmail_mails` ADD COLUMN `body_html` MEDIUMTEXT NULL COMMENT '정제된 HTML 본문(빈문자열=plain 전용 메일)' AFTER `body`");
    add_column_if_missing($pdo, "ALTER TABLE `gmail_mails` ADD COLUMN `flagged` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=별표(\\\\Flagged)' AFTER `seen`");
    add_column_if_missing($pdo, "ALTER TABLE `gmail_mails` ADD COLUMN `labels` TEXT NULL COMMENT '사용자 라벨 |a|b| 형식(NULL=미수집, |=라벨 없음)' AFTER `thrid`");
    $hasThrid = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='gmail_mails' AND COLUMN_NAME='thrid'");
    $hasThrid->execute([$cfg['db']['name']]);
    if (!$hasThrid->fetchColumn()) {
        add_column_if_missing($pdo, "ALTER TABLE `gmail_mails` ADD COLUMN `thrid` BIGINT UNSIGNED NULL COMMENT 'Gmail 대화 ID(X-GM-THRID, 첫 열람 때 채움)' AFTER `atts`");
        try { $pdo->exec("ALTER TABLE `gmail_mails` ADD INDEX `idx_acct_thrid` (`account`, `thrid`)"); }
        catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 1061) throw $e; }   // 1061=이미 있는 인덱스면 무시
    }
    // [향후 회원가입] 사용자 계정 테이블 — id/pw 로그인 + 개인 Slack 토큰/Gmail 앱 비밀번호 보관.
    // 지금은 구조만 준비(가입/로그인 로직은 메일 기능 완료 후).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `login_id`    VARCHAR(64)   NOT NULL COMMENT '로그인 ID',
            `pass_hash`   VARCHAR(255)  NOT NULL COMMENT 'password_hash()',
            `name`        VARCHAR(120)  NOT NULL DEFAULT '',
            `slack_token` TEXT          NULL COMMENT '개인 Slack 토큰(xoxp)',
            `gmail_user`  VARCHAR(190)  NULL COMMENT 'Gmail 주소',
            `gmail_pass`  VARCHAR(255)  NULL COMMENT 'Gmail 앱 비밀번호(암호화 저장)',
            `label_colors` TEXT         NULL COMMENT '라벨 색 매핑 JSON {\"라벨\":\"#hex\"}',
            `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_login` (`login_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // users.label_colors 마이그레이션 (기존 설치본)
    $has = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND COLUMN_NAME='label_colors'");
    $has->execute([$cfg['db']['name']]);
    if (!$has->fetchColumn()) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `label_colors` TEXT NULL COMMENT '라벨 색 매핑 JSON' AFTER `gmail_pass`");
    }
}

/** 라벨 색 매핑: 로그인 사용자의 설정($_SESSION['gmail_label_colors'] — users.label_colors 를
 *  로그인 시 넣어줌) 우선, 없으면 config.local.php 공용 매핑 폴백 */
function gmail_label_colors() {
    if (!empty($_SESSION['gmail_label_colors']) && is_array($_SESSION['gmail_label_colors'])) {
        return $_SESSION['gmail_label_colors'];
    }
    return gmail_cfg()['label_colors'] ?? [];
}

/** 메일 텍스트 → 올바른 UTF-8.
 *  - PHP 8 의 mb_convert_encoding() 은 모르는 문자셋 이름에 ValueError 를 던진다(@ 로 안 막힌다).
 *    한국 Outlook 메일의 'ks_c_5601-1987' 이 대표적 — 그 메일 하나 때문에 동기화 묶음 전체가 매번 실패했다.
 *    흔한 별칭은 바꿔 주고, mbstring 이 모르면 iconv, 그래도 안 되면 원문으로 물러난다.
 *  - 문자셋 표시 없이 온 8비트 텍스트(옛 한국 메일의 생 EUC-KR 제목 등)가 UTF-8 이 아니면 CP949 로 읽어 본다.
 *  - 마지막에 깨진 바이트를 걷어 낸다: 운영 MySQL(엄격 모드)은 잘못된 UTF-8 저장을 거부해(1366) 동기화가 멈춘다. */
function gmail_to_utf8($s, $cs) {
    $s = (string)$s;
    if ($s === '') return '';
    static $alias = [
        'ks_c_5601-1987' => 'CP949', 'ks_c_5601-1989' => 'CP949', 'ks_c_5601' => 'CP949', 'ksc5601' => 'CP949',
        'x-windows-949' => 'CP949', 'windows-949' => 'CP949', 'ms949' => 'CP949', 'euc_kr' => 'EUC-KR',
        'iso-8859-8-i' => 'ISO-8859-8', 'default' => 'UTF-8', 'us-ascii' => 'UTF-8', 'ascii' => 'UTF-8',
        'utf8' => 'UTF-8', 'x-unknown' => 'UTF-8', 'unknown-8bit' => 'UTF-8', 'unknown' => 'UTF-8', '' => 'UTF-8',
    ];
    $c = strtolower(trim((string)$cs, " \t\"'"));
    $c = $alias[$c] ?? $c;
    $r = false;
    if (strtoupper($c) === 'UTF-8') {
        $r = mb_check_encoding($s, 'UTF-8') ? $s : false;
        if ($r === false) $c = 'CP949';                    // UTF-8 이라더니 아니다 → 한국 메일이면 대개 CP949
    }
    if ($r === false) {
        try { $r = mb_convert_encoding($s, 'UTF-8', $c); }
        catch (ValueError $e) { $r = false; }              // mbstring 이 모르는 문자셋 이름
        if ($r === false && function_exists('iconv')) $r = @iconv($c, 'UTF-8//IGNORE', $s);
        if ($r === false) $r = $s;
    }
    return mb_scrub($r, 'UTF-8');
}

/** MIME 인코딩 헤더(제목/보낸사람) → UTF-8 */
function gmail_dec_header($s) {
    if ($s === null || $s === '') return '';
    $out = '';
    foreach (imap_mime_header_decode($s) as $p) $out .= gmail_to_utf8($p->text, $p->charset);
    return $out;
}

function gmail_part_charset($struct) {
    foreach (array_merge((array)($struct->parameters ?? []), (array)($struct->dparameters ?? [])) as $p) {
        if (strtolower($p->attribute) === 'charset') return $p->value;
    }
    return 'UTF-8';
}

function gmail_dec_body($raw, $enc) {
    if ($enc == 3) return base64_decode($raw);            // BASE64
    if ($enc == 4) return quoted_printable_decode($raw);  // QUOTED-PRINTABLE
    return $raw;
}

/** 본문(plain/html)과 첨부 파일명 수집. FT_PEEK — 읽음 상태는 호출측이 의도적으로만 변경.
 *  inline 이미지(cid: 참조 — 서명 로고, 붙여넣은 캡처 등)는 data URI 로 본문에 직접 심는다. */
function gmail_extract($imap, $msgno) {
    $struct = imap_fetchstructure($imap, $msgno);
    $plain = ''; $html = ''; $atts = []; $inline = [];
    $budget = 8 * 1024 * 1024;                             // inline 이미지 총량 제한(DB MEDIUMTEXT 보호)
    $toUtf8 = fn($raw, $struct) => gmail_to_utf8($raw, gmail_part_charset($struct));
    if (empty($struct->parts)) {
        $raw = $toUtf8(gmail_dec_body(imap_body($imap, $msgno, FT_PEEK), $struct->encoding ?? 0), $struct);
        if (strtolower($struct->subtype ?? '') === 'html') $html = $raw; else $plain = $raw;
        return [$plain, $html, $atts];
    }
    $walk = function ($parts, $prefix) use (&$walk, &$plain, &$html, &$atts, &$inline, &$budget, $imap, $msgno, $toUtf8) {
        foreach ($parts as $i => $p) {
            $no = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);
            if (!empty($p->parts)) { $walk($p->parts, $no); continue; }
            $fname = '';
            foreach (array_merge((array)($p->parameters ?? []), (array)($p->dparameters ?? [])) as $pp) {
                if (in_array(strtolower($pp->attribute), ['name', 'filename'])) $fname = gmail_dec_header($pp->value);
            }
            // inline 이미지(Content-ID 보유): 바이트를 받아 cid → data URI 매핑
            $cid = (!empty($p->ifid) && !empty($p->id)) ? trim($p->id, '<> ') : '';
            if (($p->type ?? -1) == 5 && $cid !== '') {
                $raw = gmail_dec_body(imap_fetchbody($imap, $msgno, $no, FT_PEEK), $p->encoding ?? 0);
                $len = strlen($raw);
                if ($len > 0 && $len <= 2 * 1024 * 1024 && $budget - $len > 0) {   // 개당 2MB 제한
                    $inline[$cid] = 'data:image/' . strtolower($p->subtype ?: 'png') . ';base64,' . base64_encode($raw);
                    $budget -= $len;
                }
                // 첨부로도 명시된 이미지(disposition=attachment)만 첨부 목록에 표기
                if ($fname !== '' && isset($p->disposition) && strtolower($p->disposition) === 'attachment') $atts[] = $fname;
                continue;
            }
            $isAtt = ($fname !== '') || (isset($p->disposition) && strtolower($p->disposition) === 'attachment');
            if ($isAtt) { if ($fname !== '') $atts[] = $fname; continue; }
            if (($p->type ?? -1) == 0) {
                $raw = $toUtf8(gmail_dec_body(imap_fetchbody($imap, $msgno, $no, FT_PEEK), $p->encoding ?? 0), $p);
                $sub = strtolower($p->subtype ?? '');
                if ($sub === 'plain' && $plain === '') $plain = $raw;
                elseif ($sub === 'html' && $html === '') $html = $raw;
            }
        }
    };
    $walk($struct->parts, '');
    // cid: 참조를 data URI 로 치환 → 브라우저에서 inline 이미지 표시
    if ($html !== '' && $inline) {
        $html = preg_replace_callback('/src\s*=\s*(["\']?)cid:([^"\'\s>]+)\1/i', function ($m) use ($inline) {
            $cid = urldecode($m[2]);
            return isset($inline[$cid]) ? 'src="' . $inline[$cid] . '"' : $m[0];
        }, $html);
    }
    return [$plain, $html, $atts];
}

/** 첨부 파트 목록 — gmail_extract 의 첨부 판정과 동일 규칙·순서. [['no','name','mime','enc'],...]
 *  (atts 에 이름만 저장하므로, 실제 바이트는 이 목록으로 파트 번호를 찾아 스트리밍) */
function gmail_att_parts($struct) {
    $out = [];
    if (empty($struct->parts)) return $out;
    $typeMap = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video'];
    $walk = function ($parts, $prefix) use (&$walk, &$out, $typeMap) {
        foreach ($parts as $i => $p) {
            $no = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);
            if (!empty($p->parts)) { $walk($p->parts, $no); continue; }
            $fname = '';
            foreach (array_merge((array)($p->parameters ?? []), (array)($p->dparameters ?? [])) as $pp) {
                if (in_array(strtolower($pp->attribute), ['name', 'filename'])) $fname = gmail_dec_header($pp->value);
            }
            $cid = (!empty($p->ifid) && !empty($p->id)) ? trim($p->id, '<> ') : '';
            $isAttDisp = isset($p->disposition) && strtolower($p->disposition) === 'attachment';
            if (($p->type ?? -1) == 5 && $cid !== '') {    // inline 이미지: disposition=attachment 일 때만 첨부
                if ($fname !== '' && $isAttDisp) {
                    $out[] = ['no' => $no, 'name' => $fname, 'mime' => 'image/' . strtolower($p->subtype ?: 'png'), 'enc' => $p->encoding ?? 0];
                }
                continue;
            }
            if (($fname !== '') || $isAttDisp) {
                if ($fname !== '') {
                    $out[] = ['no' => $no, 'name' => $fname,
                              'mime' => strtolower(($typeMap[$p->type ?? 3] ?? 'application') . '/' . ($p->subtype ?: 'octet-stream')),
                              'enc' => $p->encoding ?? 0];
                }
            }
        }
    };
    $walk($struct->parts, '');
    return $out;
}

/** 라벨 배열 → DB 저장 형식 |a|b| (빈 배열 = '|') */
function gmail_labels_pack(array $names) {
    return $names ? '|' . implode('|', $names) . '|' : '|';
}

/** 표시용 본문: plain 우선, 없으면 html 태그 제거 */
function gmail_body_text($plain, $html) {
    $t = $plain !== '' ? $plain : ($html !== '' ? strip_tags($html) : '');
    $t = preg_replace("/\r\n|\r/", "\n", $t);
    return preg_replace("/\n{3,}/", "\n\n", trim($t));
}

/** 본문/inline 이미지를 수집해 DB에 채움 (예열·열람 공용). 성공 시 true */
function gmail_fill_body($im, $pdo, $acct, $uid) {
    $msgno = imap_msgno($im, (int)$uid);
    if ($msgno < 1) return false;
    [$plain, $html, $atts] = gmail_extract($im, $msgno);
    $pdo->prepare("UPDATE gmail_mails SET body = ?, body_html = ?, atts = ? WHERE account = ? AND uid = ?")
        ->execute([gmail_body_text($plain, $html), ($html !== '') ? gmail_sanitize_html($html) : '',
                   json_encode($atts, JSON_UNESCAPED_UNICODE), $acct, (int)$uid]);
    return true;
}

/** HTML 본문 정제: script/iframe/폼/이벤트핸들러/javascript: 제거.
 *  서식(스타일·표·이미지)은 유지 — 표시할 때 sandbox iframe 에 격리 렌더. */
function gmail_sanitize_html($html) {
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__mail_root__">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    foreach (['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'base', 'audio', 'video'] as $tag) {
        while (($nodes = $doc->getElementsByTagName($tag))->length) {
            $n = $nodes->item(0);
            $n->parentNode->removeChild($n);
        }
    }
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//*') as $el) {
        if ($el->hasAttributes()) {
            $rm = [];
            foreach ($el->attributes as $a) {
                $an = strtolower($a->name);
                if (strpos($an, 'on') === 0) $rm[] = $a->name;                                   // onclick 등
                elseif (in_array($an, ['href', 'src']) && preg_match('/^\s*(javascript|vbscript):/i', $a->value)) $rm[] = $a->name;
            }
            foreach ($rm as $r) $el->removeAttribute($r);
        }
        if (strtolower($el->nodeName) === 'a') {                                                  // 링크는 새 탭
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }
    $root = $doc->getElementById('__mail_root__');
    $out = '';
    if ($root) foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

/** 보낸편지함 폴더 이름(mUTF-7). Gmail 언어 설정마다 이름이 달라('[Gmail]/보낸편지함', '[Gmail]/Sent Mail')
 *  \Sent 속성으로 찾고 계정별로 캐시한다. 못 찾으면 ''. */
function gmail_sent_folder() {
    $mb = (string)gmeta_get('gmail_sent_folder', '');
    if ($mb !== '') return $mb;
    try {
        $raw = new GmailRaw();
        $mb = (string)$raw->specialFolder('\\Sent');
        $raw->close();
    } catch (Throwable $e) { return ''; }
    if ($mb !== '') gmeta_set('gmail_sent_folder', $mb);
    return $mb;
}

/** 보낸편지함 메일 1건의 제목·보낸사람·본문을 받아 gmail_sent 에 캐시한다. $im = 보낸편지함을 연 php_imap 연결.
 *  반환: 채운 행(subject/sender/body/body_html/atts). 보낸편지함에서 사라졌으면 안내 문구로 채워 다시 묻지 않는다. */
function gmail_sent_fill($im, $pdo, $acct, $uid) {
    $msgno = imap_msgno($im, (int)$uid);
    if ($msgno < 1) {
        $row = ['body' => '(보낸편지함에서 찾을 수 없습니다 — 삭제/이동됨)', 'body_html' => '', 'atts' => '[]'];
        $pdo->prepare("UPDATE gmail_sent SET body = ?, body_html = ?, atts = ? WHERE account = ? AND uid = ?")
            ->execute([$row['body'], $row['body_html'], $row['atts'], $acct, (int)$uid]);
        return $row;
    }
    $ov = imap_fetch_overview($im, (string)(int)$uid, FT_UID)[0] ?? null;
    [$plain, $html, $atts] = gmail_extract($im, $msgno);
    $row = [
        'subject'   => mb_substr(gmail_dec_header($ov->subject ?? '') ?: '(제목 없음)', 0, 500),
        'sender'    => mb_substr(gmail_dec_header($ov->from ?? ''), 0, 300),
        'body'      => gmail_body_text($plain, $html),
        'body_html' => ($html !== '') ? gmail_sanitize_html($html) : '',
        'atts'      => json_encode($atts, JSON_UNESCAPED_UNICODE),
    ];
    $pdo->prepare("UPDATE gmail_sent SET subject = ?, sender = ?, body = ?, body_html = ?, atts = ? WHERE account = ? AND uid = ?")
        ->execute([$row['subject'], $row['sender'], $row['body'], $row['body_html'], $row['atts'], $acct, (int)$uid]);
    return $row;
}

/** 보낼 때 From 에 붙일 내 이름 — Gmail 웹에서 보낸 것과 똑같이 보이게.
 *  1) 메일함에 있는 '내가 Gmail 에서 보낸 메일' 의 보낸사람 이름 = Gmail 계정 설정의 이름 그대로
 *     (이름 없이 주소만 찍힌 메일 — 예전에 이 화면에서 보낸 답장 등 — 은 건너뛴다)
 *  2) 그런 메일이 없으면 포털 이름 */
function gmail_display_name() {
    static $name = null;
    if ($name !== null) return $name;
    $name = '';
    $me = gmail_owner();
    if ($me !== '') {
        $q = db()->prepare("SELECT sender FROM gmail_mails WHERE account = ? AND sender LIKE ? ESCAPE '\\\\' ORDER BY udate DESC LIMIT 20");
        $q->execute([$me, '%<' . addcslashes($me, '\\%_') . '>%']);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $s) {
            if (preg_match('/^\s*"?(.*?)"?\s*<\s*([^>]+?)\s*>\s*$/u', (string)$s, $m)
                && strcasecmp($m[2], $me) === 0 && trim($m[1]) !== '') {
                $name = trim($m[1]);
                break;
            }
        }
    }
    if ($name === '' && !empty($_SESSION['portal_uid']) && function_exists('portal_db')) {
        $st = portal_db()->prepare("SELECT name FROM portal_users WHERE id = ?");
        $st->execute([$_SESSION['portal_uid']]);
        $name = trim((string)$st->fetchColumn());
    }
    return $name;
}

/** From 헤더 값: '이름 <주소>'. 한글 이름은 MIME 인코딩(=?UTF-8?B?…?=), 영문은 따옴표로 감싼다. */
function gmail_from_header($addr, $name) {
    $name = trim(preg_replace('/[\r\n]+/', ' ', (string)$name));   // 헤더 주입 방지
    if ($name === '') return $addr;
    $disp = preg_match('/[^\x20-\x7E]/', $name)
        ? mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n")
        : '"' . addcslashes($name, '"\\') . '"';
    return $disp . ' <' . $addr . '>';
}

/* ================= Gmail SMTP 발송 (답장용, ssl 소켓 — 앱 비밀번호 재사용) ================= */
function gmail_smtp_send(array $to, array $cc, $subject, $text, array $extraHeaders = []) {
    $g = gmail_cfg();
    $user = $g['user'];
    $pass = str_replace(' ', '', (string)$g['pass']);
    $fp = @stream_socket_client('ssl://smtp.gmail.com:465', $ec, $es, 20);
    if (!$fp) throw new RuntimeException("SMTP 접속 실패: $es");
    stream_set_timeout($fp, 30);
    $read = function () use ($fp) {                        // 멀티라인 응답(250-...) 끝까지
        $out = '';
        while (($l = fgets($fp, 2048)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; }
        return $out;
    };
    $expect = function ($code, $step = '') use ($read) {
        $r = $read();
        if (strpos($r, (string)$code) !== 0) throw new RuntimeException('SMTP 오류' . ($step ? "[$step]" : '') . ': ' . trim($r));
    };
    $send = function ($c) use ($fp) { fwrite($fp, $c . "\r\n"); };
    $expect(220, 'greeting');
    $send('EHLO slackapi.local');   $expect(250, 'EHLO');
    $send('AUTH LOGIN');            $expect(334, 'AUTH');
    $send(base64_encode($user));    $expect(334, 'USER');
    $send(base64_encode($pass));    $expect(235, 'PASS');
    $send('MAIL FROM:<' . $user . '>'); $expect(250, 'MAIL FROM');
    foreach (array_unique(array_merge($to, $cc)) as $rcpt) { $send('RCPT TO:<' . $rcpt . '>'); $expect(250, 'RCPT'); }
    $send('DATA'); $expect(354, 'DATA');
    $hdr = ['From: ' . gmail_from_header($user, gmail_display_name()), 'To: ' . implode(', ', $to)];
    if ($cc) $hdr[] = 'Cc: ' . implode(', ', $cc);
    $hdr[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $hdr[] = 'Date: ' . date('r');
    $hdr[] = 'MIME-Version: 1.0';
    $hdr[] = 'Content-Type: text/plain; charset=UTF-8';
    $hdr[] = 'Content-Transfer-Encoding: base64';          // base64 본문 → dot-stuffing 불필요
    foreach ($extraHeaders as $k => $v) if ($v !== '') $hdr[] = $k . ': ' . $v;
    fwrite($fp, implode("\r\n", $hdr) . "\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n") . ".\r\n");
    $expect(250, 'BODY');
    $send('QUIT');
    @fclose($fp);
    return true;
}

/* ================= Gmail 라벨 (raw IMAP — X-GM-LABELS 확장) =================
 * php-imap(c-client)은 Gmail 전용 X-GM-LABELS 를 지원하지 않으므로
 * 라벨 조회/변경만 소켓으로 IMAP 명령을 직접 보낸다. */
class GmailRaw {
    private $fp;
    private $n = 0;

    public function __construct() {
        $g = gmail_cfg();
        $this->fp = @stream_socket_client('ssl://imap.gmail.com:993', $ec, $es, 15);
        if (!$this->fp) throw new RuntimeException("IMAP 소켓 접속 실패: $es");
        stream_set_timeout($this->fp, 20);
        $this->line();                                     // 서버 greeting
        $r = $this->cmd('LOGIN ' . $this->q($g['user']) . ' ' . $this->q(str_replace(' ', '', (string)$g['pass'])));
        if (!$r['ok']) throw new RuntimeException('IMAP 로그인 실패');
        $label = $g['label'] ?: 'INBOX';
        $mb = strtoupper($label) === 'INBOX' ? 'INBOX' : imap_utf8_to_mutf7($label);
        $r = $this->cmd('SELECT ' . $this->q($mb));
        if (!$r['ok']) throw new RuntimeException('메일함 선택 실패');
    }

    private function line() { return fgets($this->fp, 65536); }
    private function q($s) { return '"' . addcslashes($s, "\\\"") . '"'; }

    /** 태그된 명령 실행 → ['ok'=>bool, 'lines'=>untagged 응답들] */
    private function cmd($c) {
        $tag = 'A' . (++$this->n);
        fwrite($this->fp, "$tag $c\r\n");
        $untagged = [];
        while (($l = $this->line()) !== false) {
            if (strpos($l, "$tag ") === 0) return ['ok' => (bool)preg_match('/^' . $tag . ' OK/', $l), 'lines' => $untagged];
            $untagged[] = rtrim($l, "\r\n");
        }
        return ['ok' => false, 'lines' => $untagged];
    }

    public function close() { @fwrite($this->fp, 'A999 LOGOUT' . "\r\n"); @fclose($this->fp); }

    /** 전체 사용자 라벨 목록 (UTF-8, 시스템 폴더 제외) */
    public function allLabels() {
        $r = $this->cmd('LIST "" "*"');
        $out = [];
        foreach ($r['lines'] as $l) {
            if (!preg_match('/^\* LIST \(([^)]*)\) "." (.+)$/', $l, $m)) continue;
            if (stripos($m[1], '\\Noselect') !== false) continue;
            $name = trim($m[2]);
            if ($name !== '' && $name[0] === '"') $name = stripcslashes(substr($name, 1, -1));
            if ($name === 'INBOX' || strpos($name, '[Gmail]') === 0) continue;   // 시스템 폴더 제외
            $out[] = imap_mutf7_to_utf8($name);
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values(array_unique($out));
    }

    /** FETCH 응답 한 줄에서 X-GM-LABELS (...) 안의 라벨들 파싱 (따옴표/이스케이프 처리) */
    private function parseLabels($line) {
        $p = strpos($line, 'X-GM-LABELS (');
        if ($p === false) return null;
        $i = $p + strlen('X-GM-LABELS (');
        $depth = 1; $inQ = false; $buf = ''; $labels = [];
        for ($len = strlen($line); $i < $len; $i++) {
            $c = $line[$i];
            if ($inQ) {
                if ($c === '\\') { $buf .= $line[++$i] ?? ''; continue; }
                if ($c === '"') { $inQ = false; $labels[] = $buf; $buf = ''; continue; }
                $buf .= $c;
            } else {
                if ($c === '"') { $inQ = true; continue; }
                if ($c === '(') { $depth++; continue; }
                if ($c === ')') { if ($buf !== '') { $labels[] = $buf; $buf = ''; } if (--$depth === 0) break; continue; }
                if ($c === ' ') { if ($buf !== '') { $labels[] = $buf; $buf = ''; } continue; }
                $buf .= $c;
            }
        }
        return $labels;
    }

    /** UID 하나의 라벨들 → [['name'=>UTF-8, 'system'=>bool], ...] */
    public function labelsOf($uid) {
        $r = $this->cmd('UID FETCH ' . (int)$uid . ' (X-GM-LABELS)');
        foreach ($r['lines'] as $l) {
            $labels = $this->parseLabels($l);
            if ($labels === null) continue;
            $out = [];
            foreach ($labels as $lb) {
                $sys = ($lb !== '' && $lb[0] === '\\');
                $out[] = ['name' => $sys ? $lb : imap_mutf7_to_utf8($lb), 'system' => $sys];
            }
            return $out;
        }
        return [];
    }

    /** UID 의 Gmail 대화 ID (X-GM-THRID). 없으면 null. */
    public function thridOf($uid) {
        $r = $this->cmd('UID FETCH ' . (int)$uid . ' (X-GM-THRID)');
        foreach ($r['lines'] as $l) {
            if (preg_match('/X-GM-THRID (\d+)/', $l, $m)) return $m[1];
        }
        return null;
    }

    /** UID 집합의 라벨 일괄 조회 → [uid => [사용자 라벨들(UTF-8)]] (1커맨드, 시스템 라벨 제외) */
    public function labelsMap($uidSet) {
        $r = $this->cmd('UID FETCH ' . $uidSet . ' (X-GM-LABELS)');
        $map = [];
        foreach ($r['lines'] as $l) {
            $labels = $this->parseLabels($l);
            if ($labels === null || !preg_match('/UID (\d+)/', $l, $u)) continue;
            $names = [];
            foreach ($labels as $lb) {
                if ($lb !== '' && $lb[0] !== '\\') $names[] = imap_mutf7_to_utf8($lb);
            }
            $map[(int)$u[1]] = $names;
        }
        return $map;
    }

    /** UID 집합("1,2,3" 또는 "1:100")의 대화 ID 일괄 조회 → [uid => thrid] (1커맨드) */
    public function thridMap($uidSet) {
        $r = $this->cmd('UID FETCH ' . $uidSet . ' (X-GM-THRID)');
        $map = [];
        foreach ($r['lines'] as $l) {
            if (preg_match('/X-GM-THRID (\d+)/', $l, $t) && preg_match('/UID (\d+)/', $l, $u)) {
                $map[(int)$u[1]] = $t[1];
            }
        }
        return $map;
    }

    /** 대화 ID 로 현재 메일함의 모든 UID 검색 (시간순 아님 — DB udate 로 정렬) */
    /** SPECIAL-USE 시스템 폴더 찾기 (예: '\Sent' → '[Gmail]/보낸편지함') — 계정 언어 무관, mUTF-7 이름 반환 */
    public function specialFolder($attr) {
        $r = $this->cmd('LIST "" "[Gmail]/%"');
        foreach ($r['lines'] as $l) {
            if (!preg_match('/^\* LIST \(([^)]*)\) "." (.+)$/', $l, $m)) continue;
            if (stripos($m[1], $attr) === false) continue;
            $name = trim($m[2]);
            if ($name !== '' && $name[0] === '"') $name = stripcslashes(substr($name, 1, -1));
            return $name;
        }
        return null;
    }
    /** 다른 폴더를 읽기 전용으로 선택 (보낸편지함 등) */
    public function examine($mailbox) {
        return $this->cmd('EXAMINE ' . $this->q($mailbox))['ok'];
    }
    public function searchThreadIn($mailbox, $thrid) {
        if (!$this->examine($mailbox)) return [];
        return $this->searchThread($thrid);
    }
    /** UID → Gmail 메시지 ID(X-GM-MSGID). 폴더가 달라도 같은 메일이면 같은 값 → 받은편지함·보낸편지함 사본 중복 제거용 */
    public function msgidMap($uidSet) {
        if ($uidSet === '') return [];
        $r = $this->cmd('UID FETCH ' . $uidSet . ' (X-GM-MSGID)');
        $map = [];
        foreach ($r['lines'] as $l) {
            if (preg_match('/UID (\d+)/', $l, $u) && preg_match('/X-GM-MSGID (\d+)/', $l, $g)) $map[(int)$u[1]] = $g[1];
        }
        return $map;
    }
    public function searchThread($thrid) {
        $r = $this->cmd('UID SEARCH X-GM-THRID ' . preg_replace('/\D/', '', (string)$thrid));
        foreach ($r['lines'] as $l) {
            if (strpos($l, '* SEARCH') === 0) {
                return array_values(array_filter(array_map('intval', explode(' ', trim(substr($l, 8))))));
            }
        }
        return [];
    }

    /** 라벨 붙이기/떼기 (사용자 라벨만; Gmail 원본에 즉시 반영) */
    public function storeLabel($uid, $label, $add) {
        $enc = imap_utf8_to_mutf7($label);
        $r = $this->cmd('UID STORE ' . (int)$uid . ' ' . ($add ? '+' : '-') . 'X-GM-LABELS (' . $this->q($enc) . ')');
        return $r['ok'];
    }
}

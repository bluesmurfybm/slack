<?php
/**
 * Gmail 공용 헬퍼 — gmail_sync.php(동기화) / gmail_test.php(뷰어) 가 공유.
 *  - 설정 로드, IMAP 접속, gmail_mails 테이블 보장, MIME 디코딩.
 */
require_once __DIR__ . '/db.php';

/** Gmail 접속 설정.
 *  [향후 회원가입] 로그인 사용자가 등록한 자격증명($_SESSION['gmail_cfg'])이 있으면 그것을 우선 사용.
 *  없으면 config.local.php (현행 단일 계정) 폴백. */
function gmail_cfg() {
    if (!empty($_SESSION['gmail_cfg']) && is_array($_SESSION['gmail_cfg'])) {
        return $_SESSION['gmail_cfg'] + [
            'host' => '{imap.gmail.com:993/imap/ssl/novalidate-cert}',
            'label' => 'INBOX', 'search' => 'ALL', 'limit' => 30,
        ];
    }
    static $g = null;
    if ($g === null) {
        $l = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
        $g = $l['gmail'] ?? [];
    }
    return $g;
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
function gmail_open() {
    $g = gmail_cfg();
    if (!$g || empty($g['user'])) return [null, 'config.local.php 에 gmail 설정이 없습니다.'];
    $label = $g['label'] ?: 'INBOX';
    $mb = $g['host'] . (strtoupper($label) === 'INBOX' ? 'INBOX' : imap_utf8_to_mutf7($label));
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
    // 보낸편지함 헤더 캐시 — 대화 카운트/병합용 (본문 없음, 목록에는 안 나옴)
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
    // 기존 설치본(단일 계정, PK=uid) 마이그레이션: account 컬럼 추가 + 데이터/메타 백필
    $cfg = require __DIR__ . '/config.php';
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
    // body_html 컬럼 마이그레이션 (HTML 메일을 서식 그대로 표시)
    $has = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='gmail_mails' AND COLUMN_NAME='body_html'");
    $has->execute([$cfg['db']['name']]);
    if (!$has->fetchColumn()) {
        $pdo->exec("ALTER TABLE `gmail_mails` ADD COLUMN `body_html` MEDIUMTEXT NULL COMMENT '정제된 HTML 본문(빈문자열=plain 전용 메일)' AFTER `body`");
    }
    // flagged 컬럼 마이그레이션 (별표)
    $has = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='gmail_mails' AND COLUMN_NAME='flagged'");
    $has->execute([$cfg['db']['name']]);
    if (!$has->fetchColumn()) {
        $pdo->exec("ALTER TABLE `gmail_mails` ADD COLUMN `flagged` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=별표(\\\\Flagged)' AFTER `seen`");
    }
    // labels 컬럼 마이그레이션 (라벨 필터)
    $has = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='gmail_mails' AND COLUMN_NAME='labels'");
    $has->execute([$cfg['db']['name']]);
    if (!$has->fetchColumn()) {
        $pdo->exec("ALTER TABLE `gmail_mails` ADD COLUMN `labels` TEXT NULL COMMENT '사용자 라벨 |a|b| 형식(NULL=미수집, |=라벨 없음)' AFTER `thrid`");
    }
    // thrid 컬럼 마이그레이션 (Gmail 대화 묶음)
    $has = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA=? AND TABLE_NAME='gmail_mails' AND COLUMN_NAME='thrid'");
    $has->execute([$cfg['db']['name']]);
    if (!$has->fetchColumn()) {
        $pdo->exec("ALTER TABLE `gmail_mails` ADD COLUMN `thrid` BIGINT UNSIGNED NULL COMMENT 'Gmail 대화 ID(X-GM-THRID, 첫 열람 때 채움)' AFTER `atts`");
        $pdo->exec("ALTER TABLE `gmail_mails` ADD INDEX `idx_acct_thrid` (`account`, `thrid`)");
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

/** charset → UTF-8 안전 변환.
 *  한국 메일의 옛 별칭(ks_c_5601-1987 = CP949 등)은 mbstring 이 몰라서 PHP8 에선
 *  ValueError 예외로 동기화 전체가 죽는다 → 별칭 매핑 + 실패 시 폴백으로 방어. */
function gmail_to_utf8($raw, $cs) {
    $cs = strtoupper(trim((string)$cs));
    static $alias = [
        'KS_C_5601-1987' => 'CP949', 'KS_C_5601-1989' => 'CP949', 'KSC5601' => 'CP949',
        'X-WINDOWS-949' => 'CP949', 'WINDOWS-949' => 'CP949', 'KS_C_5601' => 'CP949',
        'DEFAULT' => 'UTF-8', '' => 'UTF-8', 'ANSI_X3.4-1968' => 'US-ASCII',
    ];
    if (isset($alias[$cs])) $cs = $alias[$cs];
    if ($cs === 'UTF-8' || $cs === 'US-ASCII') return $raw;
    try {
        $out = mb_convert_encoding($raw, 'UTF-8', $cs);
        return ($out === false || $out === null) ? $raw : $out;
    } catch (Throwable $e) {
        // 미지의 인코딩: 한글 메일 가능성이 높으니 CP949 한 번 더 시도 → 안 되면 원문 유지
        try { return mb_convert_encoding($raw, 'UTF-8', 'CP949'); } catch (Throwable $e2) { return $raw; }
    }
}

/** MIME 인코딩 헤더(제목/보낸사람) → UTF-8 */
function gmail_dec_header($s) {
    if ($s === null || $s === '') return '';
    $out = '';
    foreach (imap_mime_header_decode($s) as $p) {
        $cs = ($p->charset === 'default') ? 'UTF-8' : $p->charset;
        $out .= gmail_to_utf8($p->text, $cs);
    }
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
    $toUtf8 = function ($raw, $struct) {
        return gmail_to_utf8($raw, gmail_part_charset($struct));   // 별칭/미지 charset 안전 처리
    };
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

/* ================= Gmail SMTP 발송 (답장용, ssl 소켓 — 앱 비밀번호 재사용) ================= */
/** SMTP 발송. $html 을 주면 Gmail 처럼 multipart/alternative(텍스트+HTML)로 보냄.
 *  $inlines = [['cid'=>..,'mime'=>..,'data'=>바이트], ...] → multipart/related 인라인 이미지(cid: 참조).
 *  $fromName: From 표시 이름 (로그인 사용자별) — 빈값이면 config 의 gmail.name, 그것도 없으면 주소만. */
function gmail_smtp_send(array $to, array $cc, $subject, $text, array $extraHeaders = [], $html = '', array $inlines = [], $fromName = '') {
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
    // From 표시 이름 (Gmail 식 "이름 <주소>"). 호출자 지정 > config gmail.name > 주소만 (기존 동작)
    $fromName = trim((string)$fromName) !== '' ? trim((string)$fromName) : trim((string)($g['name'] ?? ''));
    $from = $fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $user . '>' : $user;
    $hdr = ['From: ' . $from, 'To: ' . implode(', ', $to)];
    if ($cc) $hdr[] = 'Cc: ' . implode(', ', $cc);
    $hdr[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $hdr[] = 'Date: ' . date('r');
    $hdr[] = 'MIME-Version: 1.0';
    $b64 = fn($s) => chunk_split(base64_encode($s), 76, "\r\n");   // base64 본문 → dot-stuffing 불필요
    if ($html === '') {                                    // 기존 그대로: 순수 텍스트
        $hdr[] = 'Content-Type: text/plain; charset=UTF-8';
        $hdr[] = 'Content-Transfer-Encoding: base64';
        $body = $b64($text);
    } else {                                               // Gmail 식: 텍스트+HTML 대안, 인라인 이미지는 related
        $bAlt = 'alt_' . bin2hex(random_bytes(8));
        $alt = "--$bAlt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($text)
             . "--$bAlt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html)
             . "--$bAlt--\r\n";
        if ($inlines) {
            $bRel = 'rel_' . bin2hex(random_bytes(8));
            $hdr[] = 'Content-Type: multipart/related; boundary="' . $bRel . '"';
            $body = "--$bRel\r\nContent-Type: multipart/alternative; boundary=\"$bAlt\"\r\n\r\n" . $alt;
            foreach ($inlines as $im) {
                $body .= "--$bRel\r\nContent-Type: " . $im['mime'] . "\r\nContent-Transfer-Encoding: base64\r\n"
                       . "Content-ID: <" . $im['cid'] . ">\r\nContent-Disposition: inline\r\n\r\n" . $b64($im['data']);
            }
            $body .= "--$bRel--\r\n";
        } else {
            $hdr[] = 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"';
            $body = $alt;
        }
    }
    foreach ($extraHeaders as $k => $v) if ($v !== '') $hdr[] = $k . ': ' . $v;
    fwrite($fp, implode("\r\n", $hdr) . "\r\n\r\n" . $body . ".\r\n");
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

    /** SPECIAL-USE 시스템 폴더 찾기 (예: '\Sent' → '[Gmail]/보낸편지함') — 계정 언어 무관, mutf7 이름 반환 */
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

    /** 다른 메일함(mutf7)을 읽기전용으로 선택. 이후 UID 명령은 그 메일함 기준이 됨에 주의 */
    public function examine($mailbox) {
        return $this->cmd('EXAMINE ' . $this->q($mailbox))['ok'];
    }

    /** 다른 메일함(mutf7)을 읽기전용 선택 후 대화 검색 */
    public function searchThreadIn($mailbox, $thrid) {
        if (!$this->examine($mailbox)) return [];
        return $this->searchThread($thrid);
    }

    /** UID 집합의 X-GM-MSGID 맵 → [uid => msgid] (메일함 간 동일 메일 판별용 전역 ID) */
    public function msgidMap($uidSet) {
        if ($uidSet === '') return [];
        $r = $this->cmd('UID FETCH ' . $uidSet . ' (X-GM-MSGID)');
        $map = [];
        foreach ($r['lines'] as $l) {
            if (preg_match('/UID (\d+)/', $l, $u) && preg_match('/X-GM-MSGID (\d+)/', $l, $g)) $map[(int)$u[1]] = $g[1];
        }
        return $map;
    }
}

<?php
/**
 * access(학교 접속 정보) 모듈 DB.
 *
 *  구조: slack 모듈의 `schools` 가 마스터, 이 모듈의 `school_access` 가 1:1 상세.
 *    - `schools`       : 대학명 / 버전 / 개발·운영·로그 URL / 사용여부   (slack/schools 에서 관리)
 *    - `school_access` : 거기에 붙는 접속·배포 정보(svn·git, 계정, DB, plink, 배포방법 …)
 *  대학명과 URL을 여기서 다시 들고 있지 않는다 — 한쪽만 고쳐서 둘이 어긋나는 걸 막기 위함.
 *  school_access.school_id 로 schools.id 를 가리키고, 조회는 항상 JOIN 으로 한다.
 *
 *  원본 데이터는 'SVN_배포_디비정보(블루내부공유).xlsx' — 시트 5개(3.5 이하 / 3.9 / 3.9-saas /
 *  4.5 / 그 외)를 한 테이블로 합쳐 넣는다.
 *
 *  ※ slack/db.php 의 db() 를 부르지 않고 여기서 자체 연결을 만든다. slack 쪽은 requests 등
 *    무거운 마이그레이션이 딸려 있고, access 는 slack 토큰 없이도 써야 하기 때문.
 *    schools 테이블은 CREATE TABLE IF NOT EXISTS 라 어느 쪽이 먼저 떠도 결과가 같다.
 */

require_once __DIR__ . '/../db.php';   // add_column_if_missing() 재사용

function access_db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = require __DIR__ . '/../config.php';
    $d   = $cfg['db'];
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $dsn = "mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], $opt);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}`
                CHARACTER SET {$d['charset']} COLLATE {$d['charset']}_unicode_ci");
    $pdo->exec("USE `{$d['name']}`");

    // 마스터. slack/db.php 의 DDL 과 동일 — slack 모듈을 한 번도 안 띄운 설치본에서도
    // access 만으로 동작하도록 여기서도 보장한다(이미 있으면 아무 일도 안 일어남).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schools` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(200) NOT NULL COMMENT '대학(기관)명',
            `ver`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '버전(3.5/3.9/4.5 등)',
            `dev`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '개발 URL',
            `ops`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '운영 URL',
            `log`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '로그 관리 URL',
            `active`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=사용,0=미사용',
            `created_at` DATETIME     NULL,
            `updated_at` DATETIME     NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ver`  (`ver`),
            KEY `idx_name` (`name`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 상세. 컬럼은 엑셀 5개 시트의 합집합 — 시트마다 있는 칸이 조금씩 다르다
    // (운영 웹서버는 3.9-saas 에만, 무들 버전/기타는 3.5 시트에만 있음).
    //
    // 학교당 1행이 원칙이지만 UNIQUE 를 걸지는 않는다. 한 대학이 여러 시트에 걸쳐 있는데
    // (예: 혜전대 = 3.5 시트의 svn + 4.5 시트의 git) schools 에는 행이 하나뿐인 경우가 5곳
    // 있어서, 유일 제약을 걸면 나중 시트가 앞 시트를 덮어써 접속 정보가 통째로 사라진다.
    // 행은 각자의 id 로 구분하고, 조회는 school_id 로 JOIN 한다.
    //
    // FK 는 일부러 안 건다: slack/schools/schools_import.php 가 schools 를 TRUNCATE 하는데
    // 참조 제약이 걸려 있으면 그 스크립트가 깨진다. 대신 school_id + JOIN 으로 다룬다.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `school_access` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id`   INT UNSIGNED NOT NULL COMMENT 'schools.id (마스터)',
            `opened`      VARCHAR(120) NOT NULL DEFAULT '' COMMENT '사업시작/최초운영오픈년월',
            `vpn`         VARCHAR(80)  NOT NULL DEFAULT '' COMMENT 'VPN/접근제어 프로그램명. 비어 있으면 별도 실행 불필요',
            `vpn_note`    TEXT         NULL COMMENT 'VPN 접속 방법 / 판정 근거가 된 문장',
            `repo`        TEXT         NULL COMMENT 'svn 또는 git 주소',
            `dev_note`    TEXT         NULL COMMENT '개발 URL 칸 원문 — URL 말고 조건이 같이 적힌 경우',
            `ops_note`    TEXT         NULL COMMENT '운영 URL 칸 원문 — 위와 동일',
            `login_ops_id` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '운영 계정 ID (대부분 csmsathena/admin)',
            `login_ops`   TEXT         NULL COMMENT '운영 사이트 비밀번호',
            `login_dev_id` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '테스트 계정 ID',
            `login_dev`   TEXT         NULL COMMENT '테스트(개발) 사이트 비밀번호',
            `login_info`  TEXT         NULL COMMENT '로그인 정보 중 운영/테스트로 못 가른 나머지',
            `dev_db`      TEXT         NULL COMMENT '개발 DB 정보',
            `ops_web`     TEXT         NULL COMMENT '운영 웹서버 (3.9-saas 전용)',
            `ops_db`      TEXT         NULL COMMENT '운영 DB 정보',
            `haksa_db`    TEXT         NULL COMMENT '학사 DB 정보',
            `plink`       TEXT         NULL COMMENT 'plink 터널링 명령',
            `note`        TEXT         NULL COMMENT '비고',
            `etc`         TEXT         NULL COMMENT 'etc',
            `deploy`      TEXT         NULL COMMENT '배포 방법',
            `deploy_acct` TEXT         NULL COMMENT '배포 계정 정보',
            `extra`       TEXT         NULL COMMENT '기타(jquery 업그레이드 등)',
            `sort_no`     INT          NOT NULL DEFAULT 0 COMMENT '엑셀 원본 행 순서',
            `created_at`  DATETIME     NOT NULL,
            `updated_at`  DATETIME     NULL,
            PRIMARY KEY (`id`),
            KEY `ix_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 예전 설치본에 있던 유일 제약 제거. 둘 다 한 학교에 접속정보가 두 벌인 경우를 못 담는다.
    // (인덱스는 add_column_if_missing() 을 쓸 수 없다 — 중복 오류코드가 42S21 이 아니라
    //  그대로 예외가 터진다. 정보스키마로 있는지 보고 판단한다.)
    foreach (['uq_school', 'uq_school_grp'] as $ix) {
        if (access_has_index($pdo, $ix)) $pdo->exec("ALTER TABLE `school_access` DROP INDEX `{$ix}`");
    }
    if (!access_has_index($pdo, 'ix_school')) {
        $pdo->exec("ALTER TABLE `school_access` ADD INDEX `ix_school` (`school_id`)");
    }

    // 로그인 정보를 운영/테스트로 나누기 전에 만들어진 설치본 마이그레이션.
    // 컬럼을 새로 붙인 그 순간에만 기존 login_info 를 갈라 채운다(엑셀 재가져오기 없이도
    // 바로 나뉘도록). 새 설치본은 위 CREATE 에 이미 들어 있어 이 블록을 타지 않는다.
    if (!access_has_column($pdo, 'login_ops')) {
        add_column_if_missing($pdo, "ALTER TABLE `school_access` ADD COLUMN `login_ops` TEXT NULL COMMENT '운영 사이트 로그인' AFTER `ops_note`");
        add_column_if_missing($pdo, "ALTER TABLE `school_access` ADD COLUMN `login_dev` TEXT NULL COMMENT '테스트(개발) 사이트 로그인' AFTER `login_ops`");
        access_backfill_login($pdo);
    }

    // VPN 항목을 추가하기 전 설치본 마이그레이션 — 같은 요령으로 1회만 자동 판정한다
    if (!access_has_column($pdo, 'vpn')) {
        add_column_if_missing($pdo, "ALTER TABLE `school_access` ADD COLUMN `vpn` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'VPN/접근제어 프로그램명. 비어 있으면 별도 실행 불필요' AFTER `opened`");
        add_column_if_missing($pdo, "ALTER TABLE `school_access` ADD COLUMN `vpn_note` TEXT NULL COMMENT 'VPN 접속 방법 / 판정 근거가 된 문장' AFTER `vpn`");
        access_backfill_vpn($pdo);
    }

    // 로그인 값에서 계정 ID를 떼내기 전 설치본 마이그레이션
    if (!access_has_column($pdo, 'login_ops_id')) {
        add_column_if_missing($pdo, "ALTER TABLE `school_access` ADD COLUMN `login_ops_id` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '운영 계정 ID (대부분 csmsathena/admin)' AFTER `ops_note`");
        add_column_if_missing($pdo, "ALTER TABLE `school_access` ADD COLUMN `login_dev_id` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '테스트 계정 ID' AFTER `login_ops`");
        access_backfill_account($pdo);
    }

    // 비밀번호는 DB에 평문으로 두지 않는다. 값만 바뀌는 일이라 컬럼 존재로 가늠할 수 없어서
    // '아직 평문인 행'을 조건으로 걸러 그것만 옮긴다(여러 번 돌려도 안전).
    access_encrypt_secrets($pdo);

    // repo 칸의 "git : https://…" 라벨 제거. 스키마 변경이 아니라 값 정리라서 컬럼 존재로
    // 가늠할 수 없다 — 대신 남은 게 있을 때만 손대고, 없으면 조회 한 번으로 끝난다.
    access_clean_repo_labels($pdo);

    // 무들 상세 버전은 안 쓰기로 해서 뺀다. schools.ver(3.5/3.9/4.5)로 충분하고,
    // 엑셀의 '무들 버전' 칸은 가져오기가 학교를 짝지을 때만 쓰고 저장하지 않는다.
    if (access_has_column($pdo, 'moodle_ver')) {
        $pdo->exec("ALTER TABLE `school_access` DROP COLUMN `moodle_ver`");
    }

    // 엑셀 시트명(grp)도 뺀다 — 화면에서 안 쓰고, 행 구분은 각자의 id 로 한다.
    if (access_has_column($pdo, 'grp')) {
        $pdo->exec("ALTER TABLE `school_access` DROP COLUMN `grp`");
    }

    return $pdo;
}

function access_has_index(PDO $pdo, $name) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_access'
                           AND INDEX_NAME = ? LIMIT 1");
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
}

function access_has_column(PDO $pdo, $col) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_access'
                           AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
}

/**
 * vpn 컬럼을 새로 붙인 직후, 기존 행들의 VPN 프로그램을 본문에서 1회 자동 판정한다.
 * 어디까지나 초깃값이다 — 오탐/누락은 화면에서 고치면 되고, 판정 근거가 된 문장을
 * vpn_note 에 남겨서 사람이 바로 확인할 수 있게 한다.
 */
function access_backfill_vpn(PDO $pdo) {
    $rows = $pdo->query("SELECT * FROM school_access")->fetchAll();
    if (!$rows) return;
    $st = $pdo->prepare("UPDATE school_access SET vpn=?, vpn_note=? WHERE id=?");
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $d = access_detect_vpn($r);
        if ($d['vpn'] === '') continue;
        $st->execute([$d['vpn'], $d['vpn_note'], $r['id']]);
    }
    $pdo->commit();
}

/** 본문에서 찾을 VPN·접근제어 프로그램. 표기가 제각각이라(HIWARE/Hiware/하이웨어) 패턴 → 표준명. */
function access_vpn_programs() {
    return [
        'HIWARE'      => '/hiware|하이웨어/i',
        'FortiClient' => '/forti/i',
        'Citrix'      => '/citrix/i',
        'SecuwaySSL'  => '/secuway/i',
        'Arcon'       => '/arcon|아콘/i',
        'WinNGS'      => '/winngs/i',
    ];
}

/**
 * 엑셀 본문에서 "접속 전에 따로 실행해야 하는 프로그램"을 뽑는다.
 * 엑셀에는 VPN 전용 칸이 없고 비고·배포방법·DB 설명 안에 흩어져 있어서
 * (예: "VPN 사용(HIWARE)", "※ vpn 연결상태에서만 svn 연결 가능") 본문을 훑는다.
 * Arcon 은 엄밀히는 VPN 이 아니라 접근제어(PAM)지만, "먼저 켜야 접속된다"는 점이 같아 함께 잡는다.
 *
 * @return array ['vpn'=>'FortiClient, Arcon' 같은 프로그램명(없으면 ''), 'vpn_note'=>근거 문장들]
 */
function access_detect_vpn(array $row) {
    $srcs = ['note', 'etc', 'deploy', 'repo', 'dev_note', 'ops_note', 'dev_db', 'ops_db', 'haksa_db'];
    $hits = [];
    foreach ($srcs as $k) {
        foreach (explode("\n", (string)($row[$k] ?? '')) as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if (preg_match('/vpn|forti|citrix|secuway|hiware|하이웨어|arcon|아콘|winngs/i', $t)) {
                $hits[$t] = true;   // 같은 문장이 여러 칸에 중복돼 있는 경우가 많아 키로 중복 제거
            }
        }
    }
    $hits = array_keys($hits);
    if (!$hits) return ['vpn' => '', 'vpn_note' => ''];

    $blob  = implode("\n", $hits);
    $names = [];
    foreach (access_vpn_programs() as $name => $re) {
        if (preg_match($re, $blob)) $names[] = $name;
    }
    // 제품명 없이 "vpn" 이라고만 적힌 곳도 많다(104문장) — 그때는 이름을 지어내지 않는다
    if (!$names) $names[] = 'VPN';

    return ['vpn' => implode(', ', $names), 'vpn_note' => implode("\n", array_slice($hits, 0, 8))];
}

/* ─────────── 비밀번호 암·복호화 ───────────
 * 화면에 복사 버튼이 있어야 하므로 되돌릴 수 있는 방식이어야 한다. 포털이 슬랙 토큰에 쓰는
 * 것과 같은 AES-256-GCM + config.php 의 'key'(config.local.php 에서 1회 자동 생성)를 쓴다.
 * auth.php 의 enc_token()/dec_token() 을 그대로 부르지 않는 이유는 그 파일이 include 시점에
 * 세션을 여는데, 가져오기 스크립트는 CLI 로도 돌기 때문이다.
 *
 * 저장 형태는 'enc:v1:<base64(iv|tag|cipher)>'. 접두사가 붙은 값만 암호문으로 보고, 없으면
 * 아직 안 옮긴 평문으로 보고 그대로 돌려준다 — 마이그레이션이 멱등해진다.
 */
function access_secret_cols() {
    return ['login_ops', 'login_dev', 'login_info'];
}

function access_key() {
    static $key = null;
    if ($key === null) {
        $cfg = require __DIR__ . '/../config.php';
        $key = base64_decode($cfg['key']);
    }
    return $key;
}

function access_is_enc($v) {
    return strncmp((string)$v, 'enc:v1:', 7) === 0;
}

function access_enc($plain) {
    $plain = (string)$plain;
    if ($plain === '' || access_is_enc($plain)) return $plain;
    $iv  = random_bytes(12);
    $tag = '';
    $c   = openssl_encrypt($plain, 'aes-256-gcm', access_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('비밀번호 암호화에 실패했습니다.');
    return 'enc:v1:' . base64_encode($iv . $tag . $c);
}

function access_dec($v) {
    $v = (string)$v;
    if (!access_is_enc($v)) return $v;          // 아직 안 옮긴 평문
    $raw = base64_decode(substr($v, 7), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', access_key(), OPENSSL_RAW_DATA,
                         substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;              // 키가 바뀌었으면 빈 값(원문을 흘리지 않는다)
}

/** 아직 평문으로 남아 있는 비밀번호만 골라 암호화한다 */
function access_encrypt_secrets(PDO $pdo) {
    $cols  = access_secret_cols();
    $sel   = implode(',', array_map(fn($c) => "`$c`", $cols));
    $where = implode(' OR ', array_map(fn($c) => "(`$c` IS NOT NULL AND `$c` <> '' AND `$c` NOT LIKE 'enc:v1:%')", $cols));
    $rows  = $pdo->query("SELECT id, {$sel} FROM school_access WHERE {$where}")->fetchAll();
    if (!$rows) return;

    $set = implode(',', array_map(fn($c) => "`$c`=?", $cols));
    $st  = $pdo->prepare("UPDATE school_access SET {$set} WHERE id=?");
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $vals = [];
        foreach ($cols as $c) $vals[] = access_enc($r[$c]);
        $st->execute(array_merge($vals, [$r['id']]));
    }
    $pdo->commit();
}

/**
 * repo 칸 앞머리의 "git :" 라벨을 떼고 주소만 남긴다.
 * 엑셀에 `git : https://…/ubgit/xxx.git` 처럼 적힌 게 10건 있는데, 라벨이 붙어 있으면
 * 그대로 복사했을 때 못 쓴다. 종류는 화면의 svn/git 칩이 따로 보여 주므로 라벨은 필요 없다.
 * (주소가 뒤따를 때만 뗀다 — "Git 사용" 같은 설명문은 건드리지 않는다.)
 */
function access_strip_repo_label($raw) {
    $out = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$raw)) as $line) {
        $out[] = preg_replace('~^\s*git\s*[:：]\s*(?=[a-z][a-z0-9+.-]*://)~i', '', $line);
    }
    return implode("\n", $out);
}

/** 이미 저장된 행들 중 라벨이 남아 있는 것만 골라 한 번 정리한다 */
function access_clean_repo_labels(PDO $pdo) {
    $rows = $pdo->query("SELECT id, repo FROM school_access
                         WHERE repo REGEXP '(^|[[:space:]])git[[:space:]]*:'")->fetchAll();
    if (!$rows) return;
    $st = $pdo->prepare("UPDATE school_access SET repo=? WHERE id=?");
    foreach ($rows as $r) {
        $fixed = access_strip_repo_label($r['repo']);
        if ($fixed !== $r['repo']) $st->execute([$fixed, $r['id']]);
    }
}

/** login_*_id 컬럼을 새로 붙인 직후, 기존 값에서 계정 ID를 1회 떼어 낸다 */
function access_backfill_account(PDO $pdo) {
    $rows = $pdo->query("SELECT id, login_ops, login_dev FROM school_access
                         WHERE login_ops <> '' OR login_dev <> ''")->fetchAll();
    if (!$rows) return;
    $st = $pdo->prepare("UPDATE school_access SET login_ops_id=?, login_ops=?, login_dev_id=?, login_dev=? WHERE id=?");
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $o = access_split_account($r['login_ops']);
        $d = access_split_account($r['login_dev']);
        if ($o['id'] === '' && $d['id'] === '') continue;
        $st->execute([$o['id'], $o['pw'], $d['id'], $d['pw'], $r['id']]);
    }
    $pdo->commit();
}

/**
 * "csmsathena / Zhtm&ahtm1" 처럼 계정과 비밀번호가 한 칸에 붙어 있는 값을 가른다.
 * 복사 버튼이 비밀번호 칸에 그대로 붙여 넣을 값을 줘야 해서 비밀번호만 login_ops/login_dev 에
 * 남기고, 계정은 login_ops_id/login_dev_id 로 뺀다. 대부분 csmsathena(65) · admin(13) 이지만
 * obj007 · geladmin · manager 같은 고유 계정이 9건 있어 버리지 않는다.
 *
 * 여러 줄짜리 값은 설명이 섞인 것이라 건드리지 않는다. 왼쪽은 아이디로 쓸 만한 글자
 * (영숫자 . _ @ -)만 허용해서 '&' '!' '%' 가 든 비밀번호를 아이디로 오인하지 않게 한다.
 *
 * @return array ['id'=>계정(없으면 ''), 'pw'=>비밀번호]
 */
function access_split_account($val) {
    $val = trim((string)$val);
    if ($val === '' || strpos($val, "\n") !== false) return ['id' => '', 'pw' => $val];
    // "아이디 / 비번" · "아이디 // 비번" 둘 다 쓰인다
    if (preg_match('~^([A-Za-z0-9._@-]{2,30})\s*/{1,2}\s*(\S.*)$~', $val, $m)) {
        return ['id' => $m[1], 'pw' => trim($m[2])];
    }
    return ['id' => '', 'pw' => $val];
}

/** login_ops/login_dev 컬럼을 새로 붙인 직후, 기존 login_info 원문을 갈라 채운다 */
function access_backfill_login(PDO $pdo) {
    $rows = $pdo->query("SELECT id, login_info FROM school_access WHERE login_info <> ''")->fetchAll();
    if (!$rows) return;
    $st = $pdo->prepare("UPDATE school_access
                         SET login_ops_id=?, login_ops=?, login_dev_id=?, login_dev=?, login_info=? WHERE id=?");
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $s = access_split_login($r['login_info']);
        if ($s['dev'] === '' && $s['ops'] === '') continue;   // 못 가른 건 원문 그대로 둔다
        $o = access_split_account($s['ops']);
        $d = access_split_account($s['dev']);
        $st->execute([$o['id'], $o['pw'], $d['id'], $d['pw'], $s['rest'], $r['id']]);
    }
    $pdo->commit();
}

/**
 * '로그인 정보' 원문을 운영/테스트로 가른다. (화면의 '테스트' = 엑셀의 '개발')
 *
 * 엑셀 255건을 훑어보면 형태가 셋뿐이다.
 *   1) 줄 앞 라벨   "개발 : Zhtm&ahtm1" ⏎ "운영 : Csms@..."     162건
 *   2) 줄 뒤 라벨   "csmsathena / Zhtm&ahtm1 (개발)"
 *   3) 라벨 없이 2줄 "Zhtm&ahtm1" ⏎ "Csms@21GjjN"               16건 — 앞이 테스트, 뒤가 운영
 * 나머지(라벨 없는 한 줄 75건 등)는 어느 쪽인지 단정할 수 없어 가르지 않고 원문에 남긴다.
 * 비어 있지 않은 줄은 반드시 셋 중 한 곳에 들어가므로 내용이 사라지지 않는다.
 *
 * @return array ['dev'=>테스트, 'ops'=>운영, 'rest'=>가르지 못한 나머지]
 */
function access_split_login($raw) {
    $raw = trim(str_replace(["\r\n", "\r"], "\n", (string)$raw));
    if ($raw === '') return ['dev' => '', 'ops' => '', 'rest' => ''];

    $buf = ['dev' => [], 'ops' => [], 'rest' => []];
    $cur = 'rest';   // 라벨이 나오기 전 줄들("ID : admin" 같은 머리말)은 나머지로
    foreach (explode("\n", $raw) as $line) {
        $t = trim($line);
        if ($t === '') continue;

        if (preg_match('/^(?:개발|테스트|dev)\s*(?:서버|사이트)?\s*[:：]\s*(.*)$/iu', $t, $m)) {
            $cur = 'dev'; $t = trim($m[1]);
        } elseif (preg_match('/^(?:운영|운용|oper\w*)\s*(?:서버|사이트)?\s*[:：]\s*(.*)$/iu', $t, $m)) {
            $cur = 'ops'; $t = trim($m[1]);
        } elseif (preg_match('/^(.*\S)\s*[（(]\s*(개발|테스트|운영|운용)\s*[)）]$/u', $t, $m)) {
            $cur = ($m[2] === '운영' || $m[2] === '운용') ? 'ops' : 'dev';
            $t = trim($m[1]);
        }
        if ($t !== '') $buf[$cur][] = $t;
    }

    // 라벨이 하나도 없는데 딱 두 줄이면 관례상 (테스트, 운영) 순이다
    if (!$buf['dev'] && !$buf['ops'] && count($buf['rest']) === 2) {
        $buf = ['dev' => [$buf['rest'][0]], 'ops' => [$buf['rest'][1]], 'rest' => []];
    }
    return [
        'dev'  => implode("\n", $buf['dev']),
        'ops'  => implode("\n", $buf['ops']),
        'rest' => implode("\n", $buf['rest']),
    ];
}

/** school_access 에서 사용자가 편집할 수 있는 컬럼 (school_id/sort_no 는 제외) */
function access_cols() {
    return ['opened','vpn','vpn_note','repo','dev_note','ops_note',
            'login_ops_id','login_ops','login_dev_id','login_dev','login_info',
            'dev_db','ops_web','ops_db','haksa_db','plink','note','etc','deploy','deploy_acct','extra'];
}

/**
 * 상세 화면에 뿌릴 필드 정의 — 라벨과 복사버튼 노출 여부를 한 곳에서 관리.
 * dev/ops URL(마스터인 schools 에서 옴)과 vpn(프로그램명+설명을 한 칸에 묶어 보여줌)은
 * 여기 목록에 없고 화면에서 따로 그린다.
 */
function access_fields() {
    return [
        ['key' => 'repo',        'label' => 'svn / git 주소', 'copy' => 1],
        ['key' => 'login_ops',   'label' => '운영 로그인',     'copy' => 1, 'acct' => 'login_ops_id'],
        ['key' => 'login_dev',   'label' => '테스트 로그인',   'copy' => 1, 'acct' => 'login_dev_id'],
        ['key' => 'login_info',  'label' => '로그인 정보(구분 없음)', 'copy' => 1],
        ['key' => 'dev_db',      'label' => '개발 DB',        'copy' => 1],
        ['key' => 'ops_web',     'label' => '운영 웹서버',     'copy' => 1],
        ['key' => 'ops_db',      'label' => '운영 DB',        'copy' => 1],
        ['key' => 'haksa_db',    'label' => '학사 DB',        'copy' => 1],
        ['key' => 'plink',       'label' => 'plink',          'copy' => 1],
        ['key' => 'deploy',      'label' => '배포 방법',       'copy' => 1],
        ['key' => 'deploy_acct', 'label' => '배포 계정',       'copy' => 1],
        ['key' => 'note',        'label' => '비고',           'copy' => 0],
        ['key' => 'etc',         'label' => 'etc',            'copy' => 0],
        ['key' => 'extra',       'label' => '기타',           'copy' => 0],
    ];
}

/**
 * 대학명 매칭용 정규화 — 엑셀 A열은 줄바꿈/괄호 앞 공백이 제각각이라
 * schools.name 과 글자만 같으면 같은 학교로 본다.
 */
function access_norm_name($s) {
    $s = preg_replace('/\s+/u', '', (string)$s);
    return mb_strtolower($s, 'UTF-8');
}

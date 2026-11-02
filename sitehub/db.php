<?php
/**
 * sitehub(BlueLab SiteHub) 모듈 DB.
 *
 *  사이트 기준이다. `lab_sites` 가 마스터(1행 = 사이트 1개)이고 나머지는 전부 site_id 로 그 아래에 매달린다.
 *    - lab_sites           사이트 — 이름·고객사·URL·호스팅·스택·저장소·유지보수 방법(서술형)
 *    - lab_site_accounts   접속정보 N건 (관리자/호스팅/FTP/SSH/DB/도메인/메일/기타) — 비밀번호는 암호화
 *    - lab_site_contacts   고객사 담당자 N명
 *    - lab_site_contracts  계약 N건 (갱신마다 행 추가 → 과거 계약도 남는다)
 *    - lab_site_monitors   BLUMO 감시 주소 N건 (monitor=1 인 사이트의 것을 monitor_run.php 가 점검) — monitor_*.php
 *    - lab_monitor_state / _checks / _incidents   감시 상태(목록의 초록/빨강 원) · 점검 기록 · 확정된 장애
 *    - lab_history         Slack 리스트 'BlueLab-유지보수처리목록' 동기화 결과 (site_id 로 매칭, NULL 이면 미매칭)
 *    - lab_sync_meta       동기화 워터마크
 *  FK 는 걸지 않고 site_id + JOIN 으로 다룬다(저장소 관례). 계약 상태는 저장하지 않고 종료일로 파생한다.
 *
 *  ※ slack/db.php 의 db() 를 부르지 않고 자체 연결을 만든다. slack 쪽은 requests 등 무거운 마이그레이션이
 *    딸려 있고, sitehub 는 slack 토큰 없이도 써야 하기 때문. 비밀번호 암호화는 access 모듈과 같은
 *    포맷(enc:v1)·같은 키를 쓰지만 access/db.php 도 include 하지 않는다 — 그 파일은 include 시점에
 *    access 마이그레이션을 돌린다.
 */

require_once __DIR__ . '/../core/db.php';   // add_column_if_missing() 재사용
require_once __DIR__ . '/audit_lib.php';     // 변경 기록 (누가 언제 무엇을)
require_once __DIR__ . '/monitor_lib.php';   // BLUMO 감시 (점검 엔진 monitor_engine.php 포함)

function sitehub_db() {
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
    $pdo->exec("SET time_zone = '+09:00'");

    foreach (sitehub_schema() as $table => $def) {
        $pdo->exec(sitehub_create_sql($table, $def));
    }
    sitehub_migrate($pdo);
    // 이미 만들어진 설치본에도 테이블·컬럼 코멘트를 한 번 입힌다 (컬럼 정의가 같으므로 데이터는 그대로)
    sitehub_apply_comments($pdo);

    // 접속정보 비밀번호는 DB에 평문으로 두지 않는다 — 아직 평문인 행만 골라 옮긴다
    sitehub_encrypt_secrets($pdo);

    return $pdo;
}

/* ── 스키마 ──────────────────────────────────────────────────────────────────────
   테이블·컬럼 정의는 여기 한 곳. CREATE 와 코멘트 마이그레이션이 같은 정의를 쓴다.
   컬럼을 더할 때는 여기 적고 add_column_if_missing() 으로 ALTER 를 따로 돌린다(CREATE IF NOT EXISTS 는 기존 테이블을 안 바꾼다). */

function sitehub_schema() {
    return [
        'lab_sites' => [
            'comment' => 'BlueLab 유지보수 사이트 마스터 — 1행 = 사이트 1개. 접속정보·담당자·계약·이력이 site_id 로 매달린다',
            'cols' => [
                'id'          => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '사이트 id'",
                'name'        => "VARCHAR(200) NOT NULL COMMENT '사이트명'",
                'customer'    => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '고객사명 (사이트의 속성일 뿐 별도 테이블 없음)'",
                'url_ops'     => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '운영 URL'",
                'url_admin'   => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '관리자 URL'",
                'url_dev'     => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '개발/테스트 URL'",
                'hosting'     => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '호스팅 업체·플랜 (쉼표로 여러 개, 필터에 씀)'",
                'stack'       => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '서버/스택 — PHP 버전, 프레임워크, DB 를 쉼표로 (값 하나하나가 필터)'",
                'repo'        => "TEXT NULL COMMENT '소스 저장소 (svn/git 주소)'",
                'slack_names' => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Slack 리스트에서 이 사이트를 부르는 표기 (쉼표) — 이력 매칭용'",
                'our_staff'   => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '블루소프트 담당자 portal_users.email (쉼표)'",
                'monitor'     => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=BLUMO 감시 대상'",
                'deploy'      => "TEXT NULL COMMENT '유지보수 방법 — 배포 방법'",
                'backup'      => "TEXT NULL COMMENT '유지보수 방법 — 백업 위치·방법'",
                'caution'     => "TEXT NULL COMMENT '유지보수 방법 — 작업 시 주의사항'",
                'note'        => "TEXT NULL COMMENT '비고 (엑셀 가져오기의 못 가른 원문도 여기)'",
                'archived'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=보관 — 미사용·종료된 사이트. 기본 목록·요약에서 빠지고 보관함에서만 보인다. 데이터는 그대로'",
                'archived_at' => "DATETIME NULL COMMENT '보관 시각 (복원하면 NULL)'",
                'archive_note' => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '보관 사유'",
                'sort_no'     => "INT NOT NULL DEFAULT 0 COMMENT '목록 순서'",
                'created_at'  => "DATETIME NOT NULL COMMENT '등록 시각'",
                'updated_at'  => "DATETIME NULL COMMENT '마지막 수정 시각'",
                'updated_by' => "VARCHAR(200) NULL COMMENT '마지막 수정자 — portal_users.email · import:xlsx(엑셀 가져오기) · system'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `idx_name` (`name`(191))', 'KEY `idx_customer` (`customer`(191))'],
        ],
        'lab_site_accounts' => [
            'comment' => '사이트 접속정보 N건 — 관리자/호스팅/FTP/SSH/DB/도메인/메일/기타. 비밀번호는 enc:v1 암호문',
            'cols' => [
                'id'         => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '접속정보 id'",
                'site_id'    => "INT UNSIGNED NOT NULL COMMENT 'lab_sites.id (FK 없이 JOIN)'",
                'kind'       => "VARCHAR(20) NOT NULL DEFAULT 'etc' COMMENT '종류 admin/hosting/ftp/ssh/db/domain/mail/etc'",
                'label'      => "VARCHAR(120) NOT NULL DEFAULT '' COMMENT '라벨 — 예: 카페24 관리자, 운영 DB'",
                'host'       => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '호스트 또는 URL'",
                'port'       => "VARCHAR(10) NOT NULL DEFAULT '' COMMENT '포트 (비우면 기본)'",
                'login_id'   => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '아이디'",
                'login_pw'   => "TEXT NULL COMMENT '비밀번호 — enc:v1:<base64(iv|tag|cipher)> AES-256-GCM, config.php key'",
                'expires_on' => "DATE NULL COMMENT '만료일 — 호스팅·도메인·SSL 만 (30일 전부터 목록 위에 안내)'",
                'note'       => "TEXT NULL COMMENT '메모'",
                'sort_no'    => "INT NOT NULL DEFAULT 0 COMMENT '사이트 안에서의 순서'",
                'created_at' => "DATETIME NOT NULL COMMENT '등록 시각'",
                'updated_at' => "DATETIME NULL COMMENT '마지막 수정 시각'",
                'updated_by' => "VARCHAR(200) NULL COMMENT '마지막 수정자 — portal_users.email · import:xlsx(엑셀 가져오기) · system'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site` (`site_id`)'],
        ],
        'lab_site_contacts' => [
            'comment' => '사이트별 고객사 담당자 N명',
            'cols' => [
                'id'         => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '담당자 id'",
                'site_id'    => "INT UNSIGNED NOT NULL COMMENT 'lab_sites.id (FK 없이 JOIN)'",
                'name'       => "VARCHAR(100) NOT NULL COMMENT '이름'",
                'dept'       => "VARCHAR(120) NOT NULL DEFAULT '' COMMENT '부서/직책'",
                'role'       => "VARCHAR(20) NOT NULL DEFAULT 'work' COMMENT '역할 work=실무, approve=결재, head=대표, etc=기타'",
                'phone'      => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '전화 (유선)'",
                'mobile'     => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '휴대폰'",
                'email'      => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '이메일'",
                'note'       => "TEXT NULL COMMENT '메모'",
                'sort_no'    => "INT NOT NULL DEFAULT 0 COMMENT '사이트 안에서의 순서 (첫 사람이 목록의 연락처 복사 버튼)'",
                'created_at' => "DATETIME NOT NULL COMMENT '등록 시각'",
                'updated_at' => "DATETIME NULL COMMENT '마지막 수정 시각'",
                'updated_by' => "VARCHAR(200) NULL COMMENT '마지막 수정자 — portal_users.email · import:xlsx(엑셀 가져오기) · system'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site` (`site_id`)'],
        ],
        'lab_site_contracts' => [
            'comment' => '사이트별 유지보수 계약 N건 — 갱신마다 행 추가. 계약 상태는 저장하지 않고 종료일이 가장 늦은 행으로 파생',
            'cols' => [
                'id'           => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '계약 id'",
                'site_id'      => "INT UNSIGNED NOT NULL COMMENT 'lab_sites.id (FK 없이 JOIN)'",
                'kind'         => "VARCHAR(20) NOT NULL DEFAULT 'annual' COMMENT '형태 annual=연간, monthly=월간, case=건별, hosting=호스팅만, free=무상, none=미계약'",
                'start_date'   => "DATE NULL COMMENT '계약 시작일'",
                'end_date'     => "DATE NULL COMMENT '계약 종료일 — 계약중/만료 임박(30일)/만료 판정 기준'",
                'amount'       => "BIGINT NULL COMMENT '연간 금액 (원) — 1년 기준 계약 금액. 월·분기로 나눠 받아도 1년 합계 (납부 주기는 billing)'",
                'amount_unit'  => "VARCHAR(10) NOT NULL DEFAULT 'year' COMMENT '(쓰지 않음) 금액은 항상 연간 — 옛 month/once 값은 year 로 옮겼다'",
                'vat_included' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=VAT 포함 금액'",
                'billing'      => "VARCHAR(20) NOT NULL DEFAULT '' COMMENT '납부(청구) 주기 — 월/분기/반기/연/선납/건별. 나눠 받는 주기일 뿐 금액은 연간 그대로'",
                'doc_url'      => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '계약서 링크 (공유 드라이브 URL)'",
                'note'         => "TEXT NULL COMMENT '메모'",
                'created_at'   => "DATETIME NOT NULL COMMENT '등록 시각'",
                'updated_at'   => "DATETIME NULL COMMENT '마지막 수정 시각'",
                'updated_by' => "VARCHAR(200) NULL COMMENT '마지막 수정자 — portal_users.email · import:xlsx(엑셀 가져오기) · system'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site` (`site_id`)', 'KEY `ix_end` (`end_date`)'],
        ],
        'lab_site_costs' => [
            'comment' => '사이트별 지출 비용(결제내역) N건 — 도메인·SSL·호스팅·외주·라이선스 등 사이트 유지에 우리가 낸 돈. 고객에게 받는 계약 금액(lab_site_contracts)과는 별개',
            'cols' => [
                'id'           => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '비용 id'",
                'site_id'      => "INT UNSIGNED NOT NULL COMMENT 'lab_sites.id (FK 없이 JOIN)'",
                'paid_on'      => "DATE NULL COMMENT '결제일'",
                'kind'         => "VARCHAR(20) NOT NULL DEFAULT 'etc' COMMENT '구분 domain · ssl · hosting · outsource · license · server · etc'",
                'item'         => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '항목 — 예: happy-i.net 도메인 연장'",
                'vendor'       => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '결제처 — 예: 가비아, 카페24'",
                'amount'       => "BIGINT NULL COMMENT '결제 금액 (원)'",
                'vat_included' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=VAT 포함 금액'",
                'method'       => "VARCHAR(20) NOT NULL DEFAULT '' COMMENT '결제 수단 — 법인카드/계좌이체/현금/예치금/기타'",
                'period_note'  => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '이용 기간 — 예: 2026-10 ~ 2027-09'",
                'doc_url'      => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '증빙 링크 (영수증·세금계산서 공유 URL)'",
                'note'         => "TEXT NULL COMMENT '메모'",
                'created_at'   => "DATETIME NOT NULL COMMENT '등록 시각'",
                'updated_at'   => "DATETIME NULL COMMENT '마지막 수정 시각'",
                'updated_by'   => "VARCHAR(200) NULL COMMENT '마지막 수정자 — portal_users.email · system'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site` (`site_id`)', 'KEY `ix_paid` (`paid_on`)'],
        ],
        'lab_site_monitors' => [
            'comment' => 'BLUMO 감시 주소 N건 — 사이트의 monitor=1 이면 monitor_run.php 가 5분마다 점검한다 (monitor_engine.php)',
            'cols' => [
                'id'         => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '감시 주소 id'",
                'site_id'    => "INT UNSIGNED NOT NULL COMMENT 'lab_sites.id (FK 없이 JOIN)'",
                'label'      => "VARCHAR(60) NOT NULL DEFAULT '' COMMENT '구분 이름 — 국문/영문처럼 주소가 둘 이상일 때만'",
                'url'        => "VARCHAR(500) NOT NULL COMMENT '점검할 주소 (http/https 포함)'",
                'expect'     => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '정상 판정 문구 — 정상 페이지에 반드시 있는 글. 없으면 200 이어도 CONTENT_MISMATCH'",
                'health'     => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'BLUMO 에이전트 주소 (blumo-agent.php)'",
                'health_key' => "TEXT NULL COMMENT '에이전트 키 — enc:v1 암호문 (점검할 때 풀어서 ?key= 로 보낸다)'",
                'slow_ms'    => "INT NULL COMMENT '느림 기준 ms (NULL=BLUMO 기본값)'",
                'timeout'    => "INT NULL COMMENT '대기 한도 초 (NULL=BLUMO 기본값)'",
                'min_size'   => "INT NULL COMMENT '최소 응답 크기 bytes (NULL=BLUMO 기본값)'",
                'note'       => "TEXT NULL COMMENT '메모'",
                'enabled'    => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=자동 점검 멈춤 (사이트 감시는 켠 채 이 주소만 잠깐 뺄 때)'",
                'mute_until' => "DATETIME NULL COMMENT '이 시각까지 알림 무음 — 점검·기록은 계속'",
                'blumo_id'   => "INT NULL COMMENT '예전 BLUMO(blumo.sqlite) sites.id — 그쪽 점검 기록을 옮겨 올 때 짝'",
                'sort_no'    => "INT NOT NULL DEFAULT 0 COMMENT '사이트 안에서의 순서'",
                'created_at' => "DATETIME NOT NULL COMMENT '등록 시각'",
                'updated_at' => "DATETIME NULL COMMENT '마지막 수정 시각'",
                'updated_by' => "VARCHAR(200) NULL COMMENT '마지막 수정자 — portal_users.email · system'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site` (`site_id`)'],
        ],
        'lab_monitor_state' => [
            'comment' => '감시 주소별 현재 상태 — 목록의 초록/빨강 원. 장애는 confirm_failures 회 연속 실패로 확정 (monitor_engine.php blumo_state)',
            'cols' => [
                'monitor_id' => "INT UNSIGNED NOT NULL COMMENT 'lab_site_monitors.id'",
                'site_id'    => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'lab_sites.id'",
                'status'     => "VARCHAR(10) NOT NULL DEFAULT 'up' COMMENT 'up=정상 · down=장애(확정)'",
                'since'      => "DATETIME NULL COMMENT '지금 상태가 시작된 시각'",
                'fail_count' => "INT NOT NULL DEFAULT 0 COMMENT '연속 실패 횟수'",
                'last_alert' => "DATETIME NULL COMMENT '마지막 알림 시각 — 재알림 간격 계산'",
                'class'      => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '마지막 점검 판정 (OK, SLOW, HTTP_503, TIMEOUT …)'",
                'cause'      => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '마지막 점검 원인 한 줄'",
                'code'       => "INT NULL COMMENT 'HTTP 상태 코드'",
                'total_ms'   => "INT NULL COMMENT '응답 시간 ms'",
                'cert_days'  => "INT NULL COMMENT 'SSL 인증서 남은 날'",
                'checked_at' => "DATETIME NULL COMMENT '마지막 점검 시각'",
                'updated_at' => "DATETIME NULL COMMENT '이 행을 바꾼 시각'",
            ],
            'keys' => ['PRIMARY KEY (`monitor_id`)', 'KEY `ix_site` (`site_id`)'],
        ],
        'lab_monitor_checks' => [
            'comment' => '감시 점검 기록 — 점검 한 번 = 행 하나. retention_days(기본 180일) 지나면 지운다. 호스팅사 문의 때의 증거',
            'cols' => [
                'id'         => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '점검 id'",
                'monitor_id' => "INT UNSIGNED NOT NULL COMMENT 'lab_site_monitors.id'",
                'site_id'    => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'lab_sites.id'",
                'at'         => "DATETIME NOT NULL COMMENT '점검 시각'",
                'class'      => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '판정'",
                'cause'      => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '원인 한 줄'",
                'code'       => "INT NULL COMMENT 'HTTP 상태 코드'",
                'dns_ms'     => "INT NULL COMMENT 'DNS ms'",
                'connect_ms' => "INT NULL COMMENT '연결 ms'",
                'tls_ms'     => "INT NULL COMMENT 'TLS ms'",
                'ttfb_ms'    => "INT NULL COMMENT '첫 바이트까지 ms'",
                'total_ms'   => "INT NULL COMMENT '전체 ms'",
                'size'       => "INT NULL COMMENT '받은 크기 bytes'",
                'cert_days'  => "INT NULL COMMENT 'SSL 남은 날'",
                'health'     => "MEDIUMTEXT NULL COMMENT '에이전트 응답 JSON 원문'",
                'snippet'    => "TEXT NULL COMMENT '오류 당시 화면 앞부분 (정상이면 NULL)'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_monitor_at` (`monitor_id`, `at`)', 'KEY `ix_at` (`at`)'],
        ],
        'lab_monitor_incidents' => [
            'comment' => '확정된 장애 — 시작·복구. 점검 기록을 지워도 이건 남긴다',
            'cols' => [
                'id'         => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '장애 id'",
                'monitor_id' => "INT UNSIGNED NOT NULL COMMENT 'lab_site_monitors.id'",
                'site_id'    => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'lab_sites.id'",
                'started'    => "DATETIME NOT NULL COMMENT '장애 확정 시각'",
                'ended'      => "DATETIME NULL COMMENT '복구 시각 (NULL=진행 중)'",
                'class'      => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '판정'",
                'cause'      => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '원인 한 줄'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_monitor` (`monitor_id`, `started`)', 'KEY `ix_started` (`started`)'],
        ],
        'lab_history' => [
            'comment' => 'Slack 리스트 BlueLab-유지보수처리목록 동기화 결과 (slack 모듈 requests 의 축소판). site_id 로 사이트에 매칭, NULL 이면 미매칭',
            'cols' => [
                'id'          => "VARCHAR(32) NOT NULL COMMENT 'Slack 리스트 항목 ID (Rec…)'",
                'list_id'     => "VARCHAR(32) NULL COMMENT 'Slack 리스트 ID (config.php sitehub_list_id)'",
                'parent_id'   => "VARCHAR(32) NULL COMMENT 'Slack 하위 항목(subtask)이면 부모 항목 ID(parent_record_id). 사이트는 부모를 따르고, 건수·통계·미매칭에서는 빠진다'",
                'site_id'     => "INT UNSIGNED NULL COMMENT 'lab_sites.id — NULL=미매칭'",
                'site_locked' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=사람이 지정한 매칭, 재동기화 때 보존'",
                'site_text'   => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '리스트의 사이트/고객사 칸 원문 (select 라벨 또는 텍스트)'",
                'title'       => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '제목'",
                'body'        => "MEDIUMTEXT NULL COMMENT '내용 (rich text → mrkdwn)'",
                'status_id'   => "VARCHAR(32) NULL COMMENT '진행상태 select 옵션 ID'",
                'status'      => "VARCHAR(60) NOT NULL DEFAULT '' COMMENT '진행상태 라벨 (1_시작전/2_작업중/4_검토중/처리완료/보류중/취소)'",
                'priority'    => "VARCHAR(60) NOT NULL DEFAULT '' COMMENT '우선순위 라벨 (높음/보통/낮음)'",
                'channel'     => "VARCHAR(60) NOT NULL DEFAULT '' COMMENT '요청구분 라벨 (이메일/전화/카카오톡/내부점검/문자/방문)'",
                'paid'        => "VARCHAR(20) NOT NULL DEFAULT '' COMMENT '무/유상 라벨'",
                'remark'      => "TEXT NULL COMMENT '완료시간·비고 원문 (리스트의 텍스트 열)'",
                'req_id'      => "VARCHAR(32) NULL COMMENT '요청자 Slack 사용자 ID'",
                'req'         => "VARCHAR(120) NOT NULL DEFAULT '—' COMMENT '요청자 이름'",
                'asg_id'      => "VARCHAR(255) NULL COMMENT '담당자 Slack 사용자 ID (쉼표로 여러 명)'",
                'asg'         => "VARCHAR(255) NOT NULL DEFAULT '—' COMMENT '담당자 이름 (쉼표로 여러 명)'",
                'date'        => "DATE NULL COMMENT '요청일 (통계·기간은 이 날짜로)'",
                'req_at'      => "DATETIME NULL COMMENT '요청일시 — 리스트의 요청일시 글자 열(YYYY-MM-DD HH:MM)에 시간이 있으면'",
                'done'        => "DATE NULL COMMENT '완료일 (date 열이 없으면 remark 앞머리의 날짜)'",
                'attachments' => "MEDIUMTEXT NULL COMMENT '첨부 메타 JSON 배열 (files.info 결과)'",
                'archived'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=라이브 리스트에서 사라짐 (화면에서 숨김)'",
                'created'     => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Slack 생성 unixtime'",
                'updated'     => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Slack 갱신 unixtime (증분 워터마크 기준)'",
                'synced_at'   => "DATETIME NULL COMMENT '마지막 동기화 시각'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site` (`site_id`)', 'KEY `idx_date` (`date`)', 'KEY `idx_created` (`created`)'],
        ],
        'lab_procedures' => [
            'comment' => '자주 있는 유지보수 처리 절차(런북). site_id=0 이면 모든 사이트에서 쓰는 공통 절차, 아니면 그 사이트 전용',
            'cols' => [
                'id'         => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '절차 id'",
                'site_id'    => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'lab_sites.id — 0=공통 절차 (FK 없이 JOIN)'",
                'category'   => "VARCHAR(20) NOT NULL DEFAULT 'etc' COMMENT '요청 유형 content/account/board/deploy/backup/domain/etc'",
                'title'      => "VARCHAR(200) NOT NULL COMMENT '절차 제목 (예: 메인 팝업 교체)'",
                'steps'      => "MEDIUMTEXT NULL COMMENT '처리 절차 — 순서대로 한 줄씩'",
                'duration'   => "VARCHAR(40) NOT NULL DEFAULT '' COMMENT '예상 소요 시간 (예: 10분)'",
                'caution'    => "TEXT NULL COMMENT '주의사항'",
                'link_url'   => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '관련 링크 (매뉴얼·위키)'",
                'sort_no'    => "INT NOT NULL DEFAULT 0 COMMENT '같은 범위(사이트/공통) 안의 순서'",
                'created_at' => "DATETIME NOT NULL COMMENT '등록 시각'",
                'updated_at' => "DATETIME NULL COMMENT '마지막 수정 시각'",
                'updated_by' => "VARCHAR(200) NULL COMMENT '마지막 수정자 — portal_users.email'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site` (`site_id`)'],
        ],
        'lab_procedure_uses' => [
            'comment' => '이력(lab_history) 한 건을 어떤 절차로 처리했는지 연결 — 절차별 처리 횟수의 근거',
            'cols' => [
                'id'           => "INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '연결 id'",
                'procedure_id' => "INT UNSIGNED NOT NULL COMMENT 'lab_procedures.id'",
                'history_id'   => "VARCHAR(32) NOT NULL COMMENT 'lab_history.id (Slack 리스트 항목 ID)'",
                'site_id'      => "INT UNSIGNED NULL COMMENT '연결 당시 이력의 사이트 lab_sites.id'",
                'used_by'      => "VARCHAR(200) NULL COMMENT '연결한 사람 portal_users.email'",
                'used_at'      => "DATETIME NOT NULL COMMENT '연결 시각'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'UNIQUE KEY `uq_proc_hist` (`procedure_id`, `history_id`)', 'KEY `ix_hist` (`history_id`)'],
        ],
        'lab_procedure_ignores' => [
            'comment' => '처리 절차 등록 제안에서 사람이 무시한 키워드 — 다시 제안하지 않는다 (proc_suggest.php)',
            'cols' => [
                'keyword'    => "VARCHAR(60) NOT NULL COMMENT '무시한 키워드'",
                'ignored_by' => "VARCHAR(200) NULL COMMENT '무시한 사람 portal_users.email'",
                'ignored_at' => "DATETIME NOT NULL COMMENT '무시한 시각'",
            ],
            'keys' => ['PRIMARY KEY (`keyword`)'],
        ],
        'lab_site_changes' => [
            'comment' => '사이트 정보 변경 기록 — 누가 언제 무엇을(칸 하나 = 행 하나). 비밀번호는 값 없이 (변경됨)만',
            'cols' => [
                'id'          => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '기록 id'",
                'site_id'     => "INT UNSIGNED NOT NULL COMMENT 'lab_sites.id (사이트를 지워도 기록은 남는다)'",
                'at'          => "DATETIME NOT NULL COMMENT '바꾼 시각'",
                'user_email'  => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '바꾼 사람 portal_users.email (system = 사람 아님)'",
                'user_name'   => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '바꾼 사람 이름 (그때 이름)'",
                'action'      => "VARCHAR(20) NOT NULL COMMENT 'create · update · delete · archive · restore · import(엑셀)'",
                'target'      => "VARCHAR(20) NOT NULL COMMENT 'site · accounts · contacts · contracts · costs'",
                'row_id'      => "INT UNSIGNED NULL COMMENT '하위 행 id (사이트 자체면 사이트 id)'",
                'row_label'   => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '행 이름 — 사이트명·접속정보 라벨·담당자 이름·계약 형태와 기간'",
                'field'       => "VARCHAR(40) NULL COMMENT '바뀐 칸 key (만들기·삭제 등은 NULL)'",
                'field_label' => "VARCHAR(60) NULL COMMENT '바뀐 칸 이름'",
                'old_value'   => "MEDIUMTEXT NULL COMMENT '이전 값 (사람이 읽는 글, 비밀번호는 NULL)'",
                'new_value'   => "MEDIUMTEXT NULL COMMENT '새 값 (비밀번호는 (변경됨))'",
                'note'        => "VARCHAR(500) NULL COMMENT '덧붙임 — 보관 사유, 자동 정리 등'",
            ],
            'keys' => ['PRIMARY KEY (`id`)', 'KEY `ix_site_at` (`site_id`, `at`)'],
        ],
        'lab_sync_meta' => [
            'comment' => 'sitehub 동기화 워터마크 — updated_max_<listId>, last_sync_at, schema_comments',
            'cols' => [
                'k' => "VARCHAR(64) NOT NULL COMMENT '키'",
                'v' => "TEXT NULL COMMENT '값'",
            ],
            'keys' => ['PRIMARY KEY (`k`)'],
        ],
    ];
}

function sitehub_create_sql($table, array $def) {
    $lines = [];
    foreach ($def['cols'] as $c => $d) $lines[] = "`{$c}` {$d}";
    foreach ($def['keys'] as $k) $lines[] = $k;
    return "CREATE TABLE IF NOT EXISTS `{$table}` (\n    " . implode(",\n    ", $lines) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=" . sitehub_sql_str($def['comment']);
}

function sitehub_sql_str($s) {
    return "'" . str_replace(["\\\\", "'"], ["\\\\\\\\", "''"], (string)$s) . "'";
}

/**
 * 컬럼 추가 마이그레이션. CREATE IF NOT EXISTS 는 기존 테이블을 안 바꾸므로 여기서 add_column_if_missing() 으로 더한다
 * (존재 확인 후 ALTER 를 직접 짜면 첫 화면의 AJAX 동시 요청에서 레이스가 난다 — CLAUDE.md).
 */
function sitehub_migrate(PDO $pdo) {
    $schema = sitehub_schema();
    foreach (['archived', 'archived_at', 'archive_note'] as $c) {
        add_column_if_missing($pdo, "ALTER TABLE lab_sites ADD COLUMN `{$c}` {$schema['lab_sites']['cols'][$c]}");
    }
    // 계약 금액은 항상 연간이다(월 납부라도 월 금액이 아님) — 1차의 월/1회 단위를 연으로 맞춘다. 금액 숫자는 그대로.
    $pdo->exec("UPDATE lab_site_contracts SET amount_unit = 'year' WHERE amount_unit <> 'year'");
    // 엑셀 가져오기가 서버/스택을 ' · ' 로 이어 넣었다 — 화면 규칙(쉼표 구분)에 맞춘다. 이미 맞춘 행은 안 걸린다.
    $pdo->exec("UPDATE lab_sites SET stack = TRIM(REPLACE(REPLACE(stack, ' · ', ', '), '·', ', ')) WHERE stack LIKE '%·%'");
    $pdo->exec("UPDATE lab_sites SET hosting = TRIM(REPLACE(REPLACE(hosting, ' · ', ', '), '·', ', ')) WHERE hosting LIKE '%·%'");
    add_column_if_missing($pdo, "ALTER TABLE lab_site_accounts ADD COLUMN `expires_on` {$schema['lab_site_accounts']['cols']['expires_on']}");
    foreach (['lab_sites', 'lab_site_accounts', 'lab_site_contacts', 'lab_site_contracts'] as $t) {
        add_column_if_missing($pdo, "ALTER TABLE `{$t}` ADD COLUMN `updated_by` {$schema[$t]['cols']['updated_by']}");
    }
    // 변경 기록을 시작하기 전 데이터 — 엑셀에서 가져온 뒤 아무도 안 고친 행은 'import:xlsx' 로 표시(화면: '엑셀 가져옴').
    // 한 번만(lab_sync_meta.audit_seed). 그 사이 고친 행은 누가 고쳤는지 몰라 비워 둔다(화면: '수정 날짜'만).
    $seeded = $pdo->query("SELECT v FROM lab_sync_meta WHERE k = 'audit_seed'")->fetchColumn();
    if ($seeded !== '1') {
        $pdo->exec("UPDATE lab_sites SET updated_by = 'import:xlsx'
                    WHERE updated_by IS NULL AND note LIKE '[엑셀]%' AND (updated_at IS NULL OR updated_at <= created_at + INTERVAL 1 MINUTE)");
        foreach (['lab_site_accounts', 'lab_site_contacts', 'lab_site_contracts'] as $t) {
            $pdo->exec("UPDATE `{$t}` r JOIN lab_sites s ON s.id = r.site_id SET r.updated_by = 'import:xlsx'
                        WHERE r.updated_by IS NULL AND s.note LIKE '[엑셀]%' AND r.updated_at IS NULL
                          AND r.created_at <= s.created_at + INTERVAL 10 MINUTE");
        }
        $pdo->exec("INSERT INTO lab_sync_meta (k, v) VALUES ('audit_seed', '1') ON DUPLICATE KEY UPDATE v = '1'");
    }
    foreach (['parent_id', 'priority', 'channel', 'paid', 'remark', 'req_at'] as $c) {
        add_column_if_missing($pdo, "ALTER TABLE lab_history ADD COLUMN `{$c}` {$schema['lab_history']['cols'][$c]}");
    }
    // 1차의 '사용 여부(active)' 를 보관함으로 바꿨다 — 미사용이던 사이트는 보관으로 옮기고 컬럼은 지운다.
    // 동시 요청이 둘 다 여기 들어와도 UPDATE 는 같은 결과, DROP 은 두 번째가 '없는 컬럼' 오류를 내므로 삼킨다.
    if (sitehub_has_column($pdo, 'lab_sites', 'active')) {
        $pdo->exec("UPDATE lab_sites SET archived=1, archived_at=COALESCE(archived_at, NOW()), archive_note=IF(archive_note='', '1차 미사용 표시에서 옮김', archive_note)
                    WHERE active=0 AND archived=0");
        try { $pdo->exec("ALTER TABLE lab_sites DROP COLUMN `active`"); }
        catch (PDOException $e) { if ($e->getCode() !== '42000') throw $e; }
    }
}

/**
 * 코멘트 없이 만들어진 설치본에 테이블·컬럼 코멘트를 한 번 입힌다. MODIFY 는 정의가 같아 데이터에 손대지 않는다.
 * 한 번 돌면 lab_sync_meta.schema_comments 에 버전을 적어 다시 안 돈다 — 코멘트 문구를 바꾸면 버전을 올린다.
 */
function sitehub_apply_comments(PDO $pdo) {
    $ver = '5';
    $st = $pdo->prepare("SELECT v FROM lab_sync_meta WHERE k = 'schema_comments'");
    $st->execute();
    if ($st->fetchColumn() === $ver) return;
    foreach (sitehub_schema() as $table => $def) {
        $pdo->exec("ALTER TABLE `{$table}` COMMENT=" . sitehub_sql_str($def['comment']));
        foreach ($def['cols'] as $c => $d) {
            if (!sitehub_has_column($pdo, $table, $c)) continue;   // 아직 없는 컬럼은 add_column_if_missing 쪽 일
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$c}` {$d}");
        }
    }
    $pdo->prepare("INSERT INTO lab_sync_meta (k, v) VALUES ('schema_comments', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([$ver]);
}

function sitehub_has_column(PDO $pdo, $table, $col) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

function sitehub_has_index(PDO $pdo, $table, $name) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    $st->execute([$table, $name]);
    return (bool)$st->fetchColumn();
}

/* ── 암호화 (access 모듈과 같은 포맷·같은 키) ───────────────────────────────────
   저장 형태 enc:v1:<base64(iv|tag|cipher)>. 접두사가 없으면 아직 안 옮긴 평문으로 보고 그대로 돌려준다. */

function sitehub_key() {
    static $key = null;
    if ($key === null) {
        $cfg = require __DIR__ . '/../config.php';
        $key = base64_decode($cfg['key']);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('config.php 의 key 가 32바이트 base64 가 아닙니다.');
    }
    return $key;
}

function sitehub_is_enc($v) {
    return is_string($v) && strncmp($v, 'enc:v1:', 7) === 0;
}

function sitehub_enc($plain) {
    $plain = (string)$plain;
    if ($plain === '' || sitehub_is_enc($plain)) return $plain;
    $iv  = random_bytes(12);
    $tag = '';
    $c   = openssl_encrypt($plain, 'aes-256-gcm', sitehub_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('비밀번호 암호화에 실패했습니다.');
    return 'enc:v1:' . base64_encode($iv . $tag . $c);
}

function sitehub_dec($v) {
    $v = (string)$v;
    if (!sitehub_is_enc($v)) return $v;
    $raw = base64_decode(substr($v, 7), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', sitehub_key(), OPENSSL_RAW_DATA,
                         substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;              // 키가 바뀌었으면 빈 값(원문을 흘리지 않는다)
}

/** 아직 평문으로 남아 있는 비밀번호만 골라 암호화한다 (여러 번 돌려도 안전) */
function sitehub_encrypt_secrets(PDO $pdo) {
    $rows = $pdo->query("SELECT id, login_pw FROM lab_site_accounts
                         WHERE login_pw IS NOT NULL AND login_pw <> '' AND login_pw NOT LIKE 'enc:v1:%'")->fetchAll();
    if (!$rows) return;
    $st = $pdo->prepare("UPDATE lab_site_accounts SET login_pw=? WHERE id=?");
    $pdo->beginTransaction();
    foreach ($rows as $r) $st->execute([sitehub_enc($r['login_pw']), $r['id']]);
    $pdo->commit();
}

/* ── 칸 정의 — 상세 드로어·시트 폼·검색·API 화이트리스트가 전부 이 한 곳을 본다 ──────────
   type: input(한 줄) · area(여러 줄) · url(링크) · pw(가림+복사) · select(options) · date · number ·
         bool(스위치) · staff(portal_users 다중 선택)
   copy: 1 이면 복사 버튼. required: 1 이면 빈 값 거절. hint: 입력 도움말. */

function sitehub_field_groups() {
    return [
        ['key' => 'basic', 'title' => '기본', 'fields' => [
            ['key' => 'name',        'label' => '사이트명',        'type' => 'input', 'required' => 1],
            ['key' => 'customer',    'label' => '고객사',          'type' => 'input'],
            ['key' => 'url_ops',     'label' => '운영 URL',        'type' => 'url',   'copy' => 1],
            ['key' => 'url_admin',   'label' => '관리자 URL',      'type' => 'url',   'copy' => 1],
            ['key' => 'url_dev',     'label' => '개발/테스트 URL', 'type' => 'url',   'copy' => 1],
            ['key' => 'hosting',     'label' => '호스팅',          'type' => 'input', 'hint' => '업체·플랜. 여러 개면 쉼표'],
            ['key' => 'stack',       'label' => '서버/스택',       'type' => 'input', 'ci' => true, 'hint' => 'PHP 버전, 프레임워크, DB 를 쉼표로 구분 — 값 하나하나로 거를 수 있다 (예: PHP 7.0, MariaDB 10.0, CIboard)'],
            ['key' => 'repo',        'label' => '소스 저장소',     'type' => 'area',  'copy' => 1],
            ['key' => 'our_staff',   'label' => '블루소프트 담당자', 'type' => 'staff'],
            ['key' => 'slack_names', 'label' => 'Slack 표기',      'type' => 'input', 'hint' => '리스트에서 이 사이트를 부르는 이름. 여러 개면 쉼표 — 이력 매칭에 쓴다'],
        ]],
        ['key' => 'method', 'title' => '유지보수 방법', 'fields' => [
            ['key' => 'deploy',  'label' => '배포 방법',        'type' => 'area', 'copy' => 1],
            ['key' => 'backup',  'label' => '백업 위치·방법',   'type' => 'area'],
            ['key' => 'caution', 'label' => '작업 시 주의사항', 'type' => 'area'],
            ['key' => 'note',    'label' => '비고',             'type' => 'area'],
        ]],
    ];
}

/** lab_sites 에서 화면이 고칠 수 있는 컬럼(화이트리스트) */
function sitehub_cols() {
    $out = [];
    foreach (sitehub_field_groups() as $g) foreach ($g['fields'] as $f) $out[] = $f['key'];
    return $out;
}

function sitehub_field($key) {
    foreach (sitehub_field_groups() as $g) foreach ($g['fields'] as $f) if ($f['key'] === $key) return $f;
    return null;
}

function sitehub_account_kinds() {
    return ['admin' => '사이트 관리자', 'hosting' => '호스팅 콘솔', 'ftp' => 'FTP·SFTP', 'ssh' => 'SSH',
            'db' => 'DB', 'domain' => '도메인·DNS', 'ssl' => 'SSL 인증서', 'mail' => '메일', 'etc' => '기타'];
}

/** 만료일을 받는 접속정보 종류 — 이 셋만 만료일 칸이 보이고, 만료 안내 대상이다 */
const SITEHUB_EXPIRY_KINDS = ['hosting', 'domain', 'ssl'];

/** 만료일 → ['dday' => 남은 날, 'code' => expired|soon|ok]. 계약과 같은 30일(SITEHUB_SOON_DAYS) 기준, 날짜는 PHP 의 오늘(Asia/Seoul) */
function sitehub_expiry(?string $date, ?string $today = null): ?array {
    if (!$date) return null;
    $today = $today ?: date('Y-m-d');
    $days = (int)floor((strtotime($date) - strtotime($today)) / 86400);
    return ['dday' => $days, 'code' => $days < 0 ? 'expired' : ($days <= SITEHUB_SOON_DAYS ? 'soon' : 'ok')];
}

function sitehub_contact_roles() {
    return ['work' => '실무', 'approve' => '결재', 'head' => '대표', 'etc' => '기타'];
}

function sitehub_contract_kinds() {
    return ['annual' => '연간', 'monthly' => '월간', 'case' => '건별', 'hosting' => '호스팅만', 'free' => '무상', 'none' => '미계약'];
}

function sitehub_cost_kinds() {
    return ['domain' => '도메인', 'ssl' => 'SSL', 'hosting' => '호스팅·서버', 'outsource' => '외주·작업비', 'license' => '라이선스·솔루션', 'etc' => '기타'];
}

/** 하위 테이블 정의. 키(accounts/contacts/contracts/costs)는 API 의 table 인자와 같다. */
function sitehub_sub_fields($table = null) {
    $defs = [
        'accounts' => ['table' => 'lab_site_accounts', 'title' => '접속 정보', 'one' => '접속 정보', 'fields' => [
            ['key' => 'kind',     'label' => '종류',        'type' => 'select', 'options' => sitehub_account_kinds(), 'required' => 1],
            ['key' => 'label',    'label' => '라벨',        'type' => 'input',  'hint' => '예: 카페24 관리자, 운영 DB'],
            ['key' => 'host',     'label' => '호스트 / URL', 'type' => 'input', 'copy' => 1],
            ['key' => 'port',     'label' => '포트',        'type' => 'input'],
            ['key' => 'login_id', 'label' => '아이디',      'type' => 'input',  'copy' => 1],
            ['key' => 'login_pw', 'label' => '비밀번호',    'type' => 'pw',     'copy' => 1],
            ['key' => 'expires_on', 'label' => '만료일',    'type' => 'date', 'kinds' => SITEHUB_EXPIRY_KINDS,
             'hint' => '호스팅·도메인·SSL 의 만료(갱신) 날짜 — 30일 전부터 사이트 목록 위에 안내한다'],
            ['key' => 'note',     'label' => '메모',        'type' => 'area'],
        ]],
        'contacts' => ['table' => 'lab_site_contacts', 'title' => '담당자', 'one' => '담당자', 'fields' => [
            ['key' => 'name',   'label' => '이름',      'type' => 'input', 'required' => 1],
            ['key' => 'dept',   'label' => '부서/직책', 'type' => 'input'],
            ['key' => 'role',   'label' => '역할',      'type' => 'select', 'options' => sitehub_contact_roles()],
            ['key' => 'phone',  'label' => '전화',      'type' => 'input', 'copy' => 1],
            ['key' => 'mobile', 'label' => '휴대폰',    'type' => 'input', 'copy' => 1],
            ['key' => 'email',  'label' => '이메일',    'type' => 'input', 'copy' => 1],
            ['key' => 'note',   'label' => '메모',      'type' => 'area'],
        ]],
        'contracts' => ['table' => 'lab_site_contracts', 'title' => '계약', 'one' => '계약', 'fields' => [
            ['key' => 'kind',         'label' => '형태',      'type' => 'select', 'options' => sitehub_contract_kinds(), 'required' => 1],
            ['key' => 'start_date',   'label' => '시작일',    'type' => 'date'],
            ['key' => 'end_date',     'label' => '종료일',    'type' => 'date', 'hint' => '계약 상태(계약중·만료 임박·만료)는 이 날짜로 판정한다'],
            ['key' => 'amount',       'label' => '연간 금액(원)', 'type' => 'number', 'hint' => '1년 기준 계약 금액. 월·분기로 나눠 받아도 1년 합계를 적는다'],
            ['key' => 'vat_included', 'label' => 'VAT 포함',  'type' => 'bool'],
            ['key' => 'billing',      'label' => '납부 주기', 'type' => 'select', 'hint' => '나눠 받는 주기일 뿐 — 금액은 위의 연간 금액 그대로', 'options' => ['' => '—', '월' => '월', '분기' => '분기', '반기' => '반기', '연' => '연', '선납' => '선납', '건별' => '건별']],
            ['key' => 'doc_url',      'label' => '계약서 링크', 'type' => 'url', 'copy' => 1],
            ['key' => 'note',         'label' => '메모',      'type' => 'area'],
        ]],
        'costs' => ['table' => 'lab_site_costs', 'title' => '비용', 'one' => '비용 내역', 'fields' => [
            ['key' => 'paid_on',      'label' => '결제일',    'type' => 'date', 'required' => 1],
            ['key' => 'kind',         'label' => '구분',      'type' => 'select', 'options' => sitehub_cost_kinds(), 'required' => 1],
            ['key' => 'item',         'label' => '항목',      'type' => 'input', 'required' => 1, 'hint' => '예: happy-i.net 도메인 연장, 서버 호스팅 1년'],
            ['key' => 'vendor',       'label' => '결제처',    'type' => 'input', 'hint' => '예: 가비아, 카페24'],
            ['key' => 'amount',       'label' => '금액(원)',  'type' => 'number', 'required' => 1, 'hint' => '실제로 결제한 금액'],
            ['key' => 'vat_included', 'label' => 'VAT 포함',  'type' => 'bool'],
            ['key' => 'method',       'label' => '결제 수단', 'type' => 'select', 'options' => ['' => '—', '법인카드' => '법인카드', '계좌이체' => '계좌이체', '현금' => '현금', '예치금' => '예치금', '기타' => '기타']],
            ['key' => 'period_note',  'label' => '이용 기간', 'type' => 'input', 'hint' => '예: 2026-10 ~ 2027-09'],
            ['key' => 'doc_url',      'label' => '증빙 링크', 'type' => 'url', 'copy' => 1],
            ['key' => 'note',         'label' => '메모',      'type' => 'area'],
        ]],
        'monitors' => ['table' => 'lab_site_monitors', 'title' => '감시 주소', 'one' => '감시 주소', 'fields' => [
            ['key' => 'url',        'label' => '감시 주소',      'type' => 'url', 'required' => 1, 'copy' => 1,
             'hint' => 'BLUMO 가 5분마다 여는 주소. http/https 를 빼면 https 로 본다'],
            ['key' => 'label',      'label' => '구분',           'type' => 'input', 'hint' => '주소가 둘 이상일 때만 — 예: 국문, 영문'],
            ['key' => 'expect',     'label' => '정상 판정 문구', 'type' => 'input',
             'hint' => '정상 페이지에 반드시 있는 글(보통 사이트 제목). 없으면 200 이어도 화면 이상으로 본다. 비우면 응답 크기로만'],
            ['key' => 'health',     'label' => '에이전트 주소',  'type' => 'url', 'hint' => '사이트에 올린 blumo-agent.php — 붙이면 DB·디스크·세션·에러로그까지 원인을 본다'],
            ['key' => 'health_key', 'label' => '에이전트 키',    'type' => 'pw', 'gen' => 1, 'copy' => 1,
             'hint' => '사이트마다 다른 키. 바꾸면 사이트에 올린 에이전트 파일도 다시 올려야 한다'],
            ['key' => 'slow_ms',    'label' => '느림 기준(ms)',  'type' => 'number', 'hint' => '비우면 BLUMO 기본값'],
            ['key' => 'timeout',    'label' => '대기 한도(초)',  'type' => 'number', 'hint' => '비우면 BLUMO 기본값'],
            ['key' => 'min_size',   'label' => '최소 응답 크기(bytes)', 'type' => 'number', 'hint' => '이보다 작으면 백지로 본다. 비우면 기본값'],
            ['key' => 'note',       'label' => '메모',           'type' => 'area'],
        ]],
    ];
    if ($table === null) return $defs;
    return $defs[$table] ?? null;
}

/* ── 처리 절차(런북) ─────────────────────────────────────────────────────────
   site_id=0 은 공통 절차 — 모든 사이트의 '유지보수 방법' 탭에 같이 보인다. 하위 테이블 공통 경로(sub_save)를 안 타고 proc_* 액션을 쓴다. */

function sitehub_procedure_categories() {
    return ['content' => '콘텐츠 수정', 'account' => '계정·권한', 'board' => '게시판·회원', 'deploy' => '배포·서버',
            'backup' => '백업·복구', 'domain' => '도메인·SSL·호스팅', 'etc' => '기타'];
}

function sitehub_procedure_fields() {
    return [
        ['key' => 'title',    'label' => '제목',         'type' => 'input',  'required' => 1, 'hint' => '요청이 오는 말로 — 예: 메인 팝업 교체, 관리자 비밀번호 초기화'],
        ['key' => 'category', 'label' => '요청 유형',    'type' => 'select', 'options' => sitehub_procedure_categories()],
        ['key' => 'duration', 'label' => '소요 시간',    'type' => 'input',  'hint' => '예: 10분'],
        ['key' => 'link_url', 'label' => '관련 링크',    'type' => 'url',    'copy' => 1],
        ['key' => 'steps',    'label' => '처리 절차',    'type' => 'area',   'copy' => 1, 'hint' => '순서대로 한 줄에 한 단계씩'],
        ['key' => 'caution',  'label' => '주의사항',     'type' => 'area'],
    ];
}

/** 절차 전체(공통 + 사이트별) — 처리 횟수(use_count)·마지막 처리일(last_used)과 함께. 화면이 사이트에 맞게 가른다 */
function sitehub_load_procedures(PDO $pdo, ?int $id = null) {
    $sql = "SELECT p.*, COUNT(u.id) use_count, MAX(u.used_at) last_used
            FROM lab_procedures p LEFT JOIN lab_procedure_uses u ON u.procedure_id = p.id"
         . ($id ? " WHERE p.id = " . (int)$id : "") . " GROUP BY p.id ORDER BY p.site_id, p.sort_no, p.id";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        foreach (['id', 'site_id', 'sort_no', 'use_count'] as $c) $r[$c] = (int)$r[$c];
        foreach (['steps', 'caution'] as $c) $r[$c] = (string)($r[$c] ?? '');
    }
    unset($r);
    return $rows;
}

/** 하위 테이블 하나의 컬럼 화이트리스트 */
function sitehub_sub_cols($table) {
    $d = sitehub_sub_fields($table);
    return $d ? array_map(fn($f) => $f['key'], $d['fields']) : [];
}

/* ── 계약 상태 파생 ──────────────────────────────────────────────────────────
   저장하지 않는다. 오늘이 기간 안에 있는 계약 행 하나로 판정한다(sitehub_current_contract) — 지남=expired, 30일 이내=soon,
   그 외=active, 계약 행이 없거나 kind=none 이면 none. 무상(free)도 기간이 있는 계약으로 본다. 날짜 비교는 PHP 의 오늘(Asia/Seoul). */

const SITEHUB_SOON_DAYS = 30;

/** 오늘이 기간 안에 있는 계약을 현재 계약으로 고른다. 없으면 가장 최근에 끝난 계약, 그것도 없으면 가장 먼저 시작하는 예정 계약.
 *  (내년에 유상으로 바뀌는 사이트가 종료일이 늦다는 이유로 미리 현재 계약이 되면 안 된다) */
function sitehub_current_contract(array $contracts, $today = null) {
    $today = $today ?: date('Y-m-d');
    $cover = $past = $future = [];
    foreach ($contracts as $c) {
        $s = (string)($c['start_date'] ?? '');
        $e = (string)($c['end_date'] ?? '');
        if ($s !== '' && $s > $today)      $future[] = $c;
        elseif ($e !== '' && $e < $today)  $past[] = $c;
        else                               $cover[] = $c;
    }
    $pick = function (array $rows, callable $better) {
        $best = null;
        foreach ($rows as $c) if ($best === null || $better($c, $best)) $best = $c;
        return $best;
    };
    if ($cover) return $pick($cover, fn($c, $b) => [(string)$c['start_date'], (int)$c['id']] > [(string)$b['start_date'], (int)$b['id']]);
    if ($past)  return $pick($past,  fn($c, $b) => [(string)$c['end_date'], (int)$c['id']] > [(string)$b['end_date'], (int)$b['id']]);
    return $pick($future, fn($c, $b) => [(string)$c['start_date'], -(int)$c['id']] < [(string)$b['start_date'], -(int)$b['id']]);
}

function sitehub_contract_status(array $contracts, $today = null) {
    $today = $today ?: date('Y-m-d');
    $latest = sitehub_current_contract($contracts, $today);
    // group: 목록 정렬 묶음 — 1 계약(유상) · 2 무상 · 3 미계약
    if ($latest === null || ($latest['kind'] ?? '') === 'none') {
        return ['code' => 'none', 'label' => '미계약', 'dday' => null, 'contract_id' => $latest['id'] ?? null, 'kind' => 'none', 'group' => 3];
    }
    $kind  = (string)$latest['kind'];
    $group = $kind === 'free' ? 2 : 1;
    $end = (string)($latest['end_date'] ?? '');
    if ($end === '') {
        return ['code' => 'active', 'label' => '계약중', 'dday' => null, 'contract_id' => (int)$latest['id'], 'kind' => $kind, 'group' => $group];
    }
    $days = (int)floor((strtotime($end) - strtotime($today)) / 86400);
    if ($days < 0)                   $code = 'expired';
    elseif ($days <= SITEHUB_SOON_DAYS) $code = 'soon';
    else                             $code = 'active';
    $labels = ['expired' => '만료', 'soon' => '만료 임박', 'active' => '계약중'];
    return ['code' => $code, 'label' => $labels[$code], 'dday' => $days, 'contract_id' => (int)$latest['id'], 'kind' => $kind, 'group' => $group];
}

/** 계약 목록 순서: 현재 계약 → 예정(시작일 빠른 순) → 지난 계약(종료일 늦은 순) */
function sitehub_sort_contracts(array $contracts, $currentId, $today = null) {
    $today = $today ?: date('Y-m-d');
    $rank = function ($c) use ($currentId, $today) {
        if ((int)$c['id'] === (int)$currentId) return 0;
        $st = (string)($c['start_date'] ?? '');
        return $st !== '' && $st > $today ? 1 : 2;
    };
    usort($contracts, function ($a, $b) use ($rank) {
        $ra = $rank($a); $rb = $rank($b);
        if ($ra !== $rb) return $ra <=> $rb;
        if ($ra === 1) return [(string)$a['start_date'], $a['id']] <=> [(string)$b['start_date'], $b['id']];
        return [(string)($b['end_date'] ?? ''), $b['id']] <=> [(string)($a['end_date'] ?? ''), $a['id']];
    });
    return $contracts;
}

/** 쉼표 목록 → 공백 제거한 배열 */
function sitehub_split_list($s) {
    return array_values(array_filter(array_map('trim', explode(',', (string)$s)), fn($x) => $x !== ''));
}

/* ── 조회 ─────────────────────────────────────────────────────────────────────
   화면이 쓰는 모양으로 사이트를 묶어 준다: 하위 테이블 세 벌 + 이력 건수 + 파생 계약 상태.
   비밀번호는 복사 버튼이 써야 하므로 여기서 풀어 보낸다 — 응답 본문에 평문이 실리니 운영은 HTTPS. */

function sitehub_load_sites(PDO $pdo, ?array $ids = null, bool $includeArchived = true) {
    $where = [];
    $args  = [];
    if (!$includeArchived) $where[] = 'archived = 0';
    if ($ids !== null) {
        if (!$ids) return [];
        $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_map('intval', $ids);
    }
    $sql = "SELECT * FROM lab_sites" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY sort_no, name, id";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $sites = [];
    foreach ($st->fetchAll() as $r) {
        $r['id']      = (int)$r['id'];
        $r['monitor']  = (int)$r['monitor'];
        $r['archived'] = (int)$r['archived'];
        $r['sort_no']  = (int)$r['sort_no'];
        foreach (['repo', 'deploy', 'backup', 'caution', 'note', 'archive_note'] as $c) $r[$c] = (string)($r[$c] ?? '');
        $r['accounts'] = $r['contacts'] = $r['contracts'] = $r['costs'] = $r['monitors'] = [];
        $r['history_count'] = 0;
        $sites[$r['id']] = $r;
    }
    if (!$sites) return [];

    $in   = implode(',', array_keys($sites));
    $kindOrder = "FIELD(kind, " . implode(',', array_map(fn($k) => $pdo->quote($k), array_keys(sitehub_account_kinds()))) . "), sort_no, id";
    foreach (sitehub_sub_fields() as $key => $def) {
        // 계약은 최신(종료일이 늦은) 것이 위. 접속정보는 종류 순서(sitehub_account_kinds 순) 뒤 사람이 정한 순서, 담당자는 사람이 정한 순서.
        $order = $key === 'accounts' ? $kindOrder : ($key === 'contracts' ? 'end_date IS NULL, end_date DESC, id DESC' : ($key === 'costs' ? 'paid_on IS NULL, paid_on DESC, id DESC' : 'sort_no, id'));
        $rows = $pdo->query("SELECT * FROM `{$def['table']}` WHERE site_id IN ({$in}) ORDER BY {$order}")->fetchAll();
        foreach ($rows as $row) {
            $row['id']      = (int)$row['id'];
            $row['site_id'] = (int)$row['site_id'];
            if ($key === 'accounts') {
                $row['login_pw'] = sitehub_dec($row['login_pw']);
                $row['expiry'] = in_array($row['kind'], SITEHUB_EXPIRY_KINDS, true) ? sitehub_expiry($row['expires_on'] ?? null) : null;
            }
            if ($key === 'contracts' || $key === 'costs') {
                $row['amount']       = $row['amount'] === null ? null : (int)$row['amount'];
                $row['vat_included'] = (int)$row['vat_included'];
            }
            if (isset($row['note'])) $row['note'] = (string)($row['note'] ?? '');
            $sites[$row['site_id']][$key][] = $row;
        }
    }
    // 이력 건수는 요청(최상위 항목)만 — 하위 항목은 그 요청의 세부 작업이다
    foreach ($pdo->query("SELECT site_id, COUNT(*) n FROM lab_history WHERE archived = 0 AND parent_id IS NULL AND site_id IN ({$in}) GROUP BY site_id") as $h) {
        $sites[(int)$h['site_id']]['history_count'] = (int)$h['n'];
    }
    sitehub_monitor_attach($pdo, $sites);
    foreach ($sites as &$s) {
        $s['contract_status'] = sitehub_contract_status($s['contracts']);
        $s['contracts'] = sitehub_sort_contracts($s['contracts'], $s['contract_status']['contract_id']);
        // 만료 안내 — 호스팅·도메인·SSL 중 30일 안에 끝나거나 이미 끝난 것, 급한 순
        $alerts = [];
        foreach ($s['accounts'] as $a) {
            if (!$a['expiry'] || $a['expiry']['code'] === 'ok') continue;
            $alerts[] = ['account_id' => $a['id'], 'kind' => $a['kind'], 'label' => $a['label'], 'expires_on' => $a['expires_on']] + $a['expiry'];
        }
        usort($alerts, fn($x, $y) => $x['dday'] <=> $y['dday']);
        $s['expiry_alerts'] = $alerts;
    }
    unset($s);
    return array_values($sites);
}

function sitehub_load_site(PDO $pdo, int $id) {
    $rows = sitehub_load_sites($pdo, [$id]);
    return $rows[0] ?? null;
}

/**
 * Slack 하위 항목(subtask)은 업체명 칸에 작업 내용이 적히고 처리상태도 기본값(1_시작전)으로 남는 게 보통이라 따로 매칭하지 않고
 * 부모 항목의 사이트를 따른다. 사람이 직접 지정한 것(site_locked=1)만 예외. 동기화·미매칭 지정·자동 정리 뒤에 부른다.
 */
function sitehub_children_follow(PDO $pdo): void {
    $pdo->exec("UPDATE lab_history c JOIN lab_history p ON p.id = c.parent_id
                SET c.site_id = p.site_id
                WHERE c.site_locked = 0 AND NOT (c.site_id <=> p.site_id)");
}

/** 입력값을 칸 type 에 맞게 정규화한다. 잘못된 값은 예외로 거절. */
function sitehub_normalize(array $f, $v) {
    $t = $f['type'] ?? 'input';
    if ($t === 'bool')   return (!empty($v) && $v !== '0' && $v !== 'false') ? 1 : 0;
    if ($t === 'number') {
        if ($v === null || $v === '') return null;
        $n = str_replace([',', ' '], '', (string)$v);
        if (!is_numeric($n)) throw new Exception("{$f['label']} 은(는) 숫자여야 합니다.");
        return (int)$n;
    }
    if ($t === 'date') {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new Exception("{$f['label']} 은(는) YYYY-MM-DD 형식이어야 합니다.");
        return $v;
    }
    if ($t === 'select') {
        $v = (string)$v;
        if (!array_key_exists($v, $f['options'] ?? [])) throw new Exception("{$f['label']} 값이 올바르지 않습니다: {$v}");
        return $v;
    }
    if ($t === 'staff') {
        $list = is_array($v) ? $v : sitehub_split_list($v);
        return implode(',', array_values(array_unique(array_map('trim', $list))));
    }
    $v = is_array($v) ? implode(',', $v) : (string)$v;
    $v = str_replace("\r\n", "\n", $v);
    $v = $t === 'area' ? rtrim($v) : trim($v);
    if (!empty($f['required']) && $v === '') throw new Exception("{$f['label']} 을(를) 입력하세요.");
    if ($t === 'pw') return sitehub_enc($v);
    return $v;
}

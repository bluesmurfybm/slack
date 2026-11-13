# sitehub (BlueLab SiteHub)

BlueLab 팀이 유지보수하는 **사이트** 정보를  관리하는 모듈(`sitehub/index.php`). —
동작·API·암호화는 access, 화면은 dti 를 따른다. 포털 로그인한 사람은 누구나 본다.

## 구조
- **사이트 기준** — 1행 = `lab_sites` 1개. 접속정보·담당자·계약·비용·이력이 `site_id` 로 매달린다(FK 없음).
  중복은 사이트명·고객사가 둘 다 같을 때만 거절한다.
- **보관함** — 미사용 사이트는 지우지 않고 `archived=1`(+사유). 완전 삭제는 보관함에서만 — 하위 행은 지우고 이력은 `site_id` 만 NULL.
- **인증** — `guard.php` 는 포털 로그인만 본다. 화면은 `?need_login=sitehub`, API 는 401. 슬랙 토큰은 이력 동기화에만 쓴다.
- **DB** — 자체 `sitehub_db()`(slack 의 `db()` 를 안 부른다). 테이블은 최초 접속 때 생기고, 컬럼은 `sitehub_schema()` 에 적고
  `sitehub_migrate()` 에서 `add_column_if_missing()`.
- **칸 정의는 `db.php` 한 곳**(`sitehub_field_groups()` · `sitehub_sub_fields()`) — 폼·검색·API 화이트리스트가 같이 본다.
- **화면** — dti 복사본(`views/`, `styles/style.css`). `static/*.js` 는 인라인 onclick 이 전역 함수를 찾으므로 클래식 스크립트다.
  상세 드로어는 주소 `?site=<id>&tab=<key>` 로 남는다.

## 지켜야 할 규칙
- **비밀번호(`login_pw`)** 는 access 와 같은 `enc:v1:` AES-256-GCM 이지만 키는 sitehub 전용 `sitehub_key`(`config.local.php`)다.
  없으면 포털 공용 `key` 로 암호화하고, 풀 때는 `sitehub_key` → `key` 순서로 시도한다. `sitehub_enc()/dec()` 를 일부러 따로 둔다
  (`access/db.php` 를 include 하면 access 마이그레이션이 돈다). API 응답에 평문이 실리니 운영은 HTTPS. 수정 때 빈칸 = 그대로 두기.
- **계약 상태는 저장하지 않는다** — 종료일이 가장 늦은 계약으로 판정(`sitehub_contract_status()`, 30일 안이면 `만료 임박`).
  **계약 금액은 항상 연간 금액**이고 납부 주기는 `billing` 칸에만 적는다.
- **변경 이력(`audit_lib.php`)** — 모든 쓰기가 `updated_at/by` 와 `lab_site_changes` 에 남는다. 비밀번호는 값 대신 `(변경됨)` 만.
- **알림(`notice_lib.php`)** — 새로 등록된 것과 장애만 포털 알림함·웹 푸시로 보낸다. 수정·삭제·보관·복원은 알리지 않는다.
  - 등록: `sitehub_audit()` 의 create 와 이력 추가를 등록한 사람을 뺀 전원에게. 종류는 `sitehub.site`(새 사이트)·`sitehub.change`(그 밖의 항목).
    한 요청의 등록은 사이트마다 한 건으로 묶고, `sitehub.change` 는 그 사이트의 안 읽은 알림을 10분 안에 받은 사람에게는 다시 쌓지 않는다.
    지금 `sitehub.change` 는 `core/push_kinds.json` 에서 `"hidden": true` 로 꺼 두었다 — 마이페이지 알림 옵션에 안 보이고 아무에게도 가지 않는다(지우면 다시 켜짐).
    `api.php` 의 사용자 요청에서만 켜므로 엑셀 가져오기·미매칭 자동 정리는 알리지 않는다.
  - 장애(`sitehub.down`): BLUMO 가 장애를 확정할 때 한 번 전원에게. 지속·복구는 보내지 않고(복구는 Slack 웹훅만, 웹훅도 장애가 이어지는 동안 재알림하지 않는다), 무음 중이면 보내지 않으며,
    같은 사이트는 1시간 안에 다시 보내지 않는다(주소 여러 개가 같이 죽거나 오락가락할 때).
  - 상단바 종·포털 카드 배지·목록의 NEW 로 보이고, 드로어를 열면 그 사이트의 알림이 읽음이 된다.
- 원본 엑셀 `호스팅 정보*.xlsx` 에는 전 고객 계정이 있어 **커밋 금지**.

## Slack 이력
- **동기화(`sync.php`)** — 리스트 `BlueLab-유지보수처리목록`(`F088VTXBD0R`)은 thebluesoft.slack.com 에 있어 포털 개인 토큰(coursemos)으로는
  못 읽는다. 토큰은 `config.local.php` 의 `sitehub_slack_token` → 로그인 사용자의 개인 토큰 → CLI 는 `SLACK_TOKEN` 순서.
  리스트 ID·URL 은 `config.php` 의 `sitehub_list_id` · `sitehub_list_url` 이고, ID·토큰 중 하나라도 없으면 동기화 버튼만 숨는다.
- **컬럼 매핑은 `list_cols.php`**(컬럼 ID 또는 기본 열 key). 비어 있으면 `php sitehub/sync.php schema` 가 스키마를 찍고 멈춘다.
  요청일시는 글자 열(`YYYY-MM-DD HH:MM`)이다 — Slack 날짜 열은 시간을 못 담는다.
- **자동 동기화는 화면 기반** — 누군가 화면을 열어 두면 `sitehub_auto_sync_min`(기본 10분)마다 증분 동기화, flock 으로 한 번만 돈다.
  서버 예약 실행은 두지 않았다. 전체 동기화는 `php sitehub/sync.php full`.
- **사이트 매칭은 `match_lib.php` 한 곳**. 못 찾은 이력은 툴바 `미매칭` 에서 사람이 고르면 `site_locked=1` 로 유지된다.
  자주 다르게 적히는 업체는 기본 탭 `Slack 표기` 에 더해 둔다. `자동 정리` 는 미리보기 뒤 적용.
- 라이브에서 빠진 항목은 `archived=1`. 라이브 목록이 비면 보관을 건너뛴다(권한 오류로 전부 보관되는 사고 방지).
- **이력 쓰기(`history_write.php`)** — Slack 에 먼저 쓰고 성공하면 DB 에 반영. **로그인한 사람의 개인 토큰만**(`lists:write`) 쓴다.
- CLI 는 core/auth.php 를 거치지 않아 `sync.php` 가 직접 `Asia/Seoul` 을 건다.

## 그 밖의 기능
- **처리 절차(런북)** — `static/procs.js`, `lab_procedures`(`site_id=0` 은 공통), 이력 연결 `lab_procedure_uses`.
  `제안 보기`(`proc_suggest.php`)는 이력의 반복 낱말로 묶는 규칙 기반이다(AI 호출 없음).
- **비용** 탭(`lab_site_costs`) — 우리가 낸 돈. 고객에게 받는 계약 금액과 별개.
- **엑셀 가져오기(`import_xlsx.php`)** — 시트 하나 = 사이트 하나. `php sitehub/import_xlsx.php "호스팅 정보_v.xlsx" [--force] [--dry-run]`.
  못 가른 글은 메모에 원문으로 남긴다.
- **통계(`stats.php` · `api.php?stats`)** — `config.php` 의 `sitehub_stats_emails` 계정만 본다. 줄이 없으면 `pink@bluesoft.co.kr`,
  `[]` 면 로그인한 누구나. BLUMO 설정·연동과 `tools/move_request_time.php` 는 `sitehub_can_manage()` 로 따로 막아
  목록이 비어 있어도 기본 계정만 쓴다(웹훅 주소가 실린다).

## BLUMO 감시(`monitor_*.php`)
- 따로 돌던 예전 BLUMO 를 SiteHub 안으로 옮겼다. 옛 폴더는 지웠고 원본 SQLite 는 `var/sitehub/blumo_legacy.sqlite`(웹훅 주소 포함, 웹 차단).
- 감시 대상은 사이트 상세의 `감시 설정` 탭(`lab_site_monitors`). 판정 규칙(`blumo_classify` · `blumo_cause`)은 예전 그대로 —
  바꾸면 장애 이력의 뜻이 달라진다.
- 자동 점검은 작업 스케줄러가 `monitor_run.php` 를 5분마다(리눅스 운영 서버는 `sudo -u <웹서버 계정> sh sitehub/tools/monitor-cron.sh [php 경로]` 로 cron 등록 — root 로 등록하면 var/sitehub 잠금·로그가 root 소유가 되어 화면 점검이 막힌다. 윈도우는 `sitehub/tools/monitor-task.bat`, 이때는 **관리자 명령창**에서 돌려야 SYSTEM 계정으로 등록돼 로그오프 중에도 돈다. PHP 를 못 찾으면 인자로 php.exe 경로를 준다. 등록 전에 `--doctor` 로 CLI PHP 를 진단하고, 실행 오류는 `var/sitehub/monitor_run.log`). `confirm_failures`(기본 2)회
  연속 실패면 장애로 확정하고, 상태가 바뀔 때만 웹훅을 보낸다.
- 설정은 `lab_sync_meta` 의 `blumo_*`(웹훅 주소는 enc:v1). https 점검은 `monitor/cacert.pem` 으로 인증서를 검증한다 —
  끄면 TLS_FAIL 을 못 잡는다. `monitor/` 는 `.htaccess` 로 막는다.
- 점검 요청은 브라우저처럼 보낸다(`BLUMO_UA` = 크롬 UA + `Blumo/1.0`, Accept 머리글). 403·406 이면 순수 브라우저 UA 로 한 번 더 열어
  되면 정상으로 보고 원인에 `Blumo 점검 요청만 차단` 을, 그래도 막히면 `브라우저 UA 로도 403 — IP·서버 설정 차단 · 응답 서버 <IP>` 를 붙인다
  (`blumo_probe_note`). 응답 서버 IP 가 실제 사이트 IP 와 다르면 점검 서버의 DNS 문제, 같으면 사이트 쪽에서 점검 서버의 나가는 IP(환경 진단)를 허용해야 한다.

## 포털 등록
`core/worksystems.json`(`key: sitehub`), 루트 `index.php` 의 `sitehubIcon` · ICONS 맵, 모듈 안의 `need_login=sitehub` ·
`work_systems_menu(..., 'sitehub')` 세 곳을 함께 고친다.

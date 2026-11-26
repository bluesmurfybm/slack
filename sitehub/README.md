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

## 유지보수 내역서(`report_lib.php` · `static/report.js`)
- 드로어 `내역서` 탭. 고객사에 내던 한글 내역서를 **사이트마다 양식으로 올려 두고**(`lab_report_templates`, 파일은 `var/sitehub/reports/tpl/`),
  기간을 골라 불러오면 그 기간의 행을 만들어 보여 준다 — 사람이 고친 뒤 `내역서 만들기` 로 양식 그대로 채운 `.hwpx` 와 `.pdf` 를 받는다
  (`lab_reports` 에 행 JSON 과 파일 `var/sitehub/reports/out/` 이 남아 다시 받거나 불러와 고칠 수 있다).
- **PDF(`report_pdf.php`)** 는 채운 hwpx 를 다시 읽어 쪽 크기·여백·칸 너비·테두리·칸 배경·정렬·글자 크기/굵기·그림대로 직접 그린다(라이브러리 없음).
  글꼴은 `sitehub/fonts/Pretendard-{Regular,Bold}.ttf`(OFL, `fonts/OFL.txt`)에서 쓴 글자만 넣는다(서브셋, GID 유지 + ToUnicode).
  글꼴 파일이 없으면 PDF 표준 한글 글꼴(HYGoThic-Medium, CP949)을 이름만 쓴다. 그림은 BinData 그대로 — JPEG 는 그대로, PNG·GIF 는 직접 풀어
  (투명은 SMask) 넣고 그 밖의 형식은 GD 가 있을 때만. 글상자·도형·머리말·표 머리 행 반복은 그리지 않는다. PDF 가 실패해도 hwpx 는 남고, 받을 때 다시 만든다.
- **hwpx 글꼴** 은 규칙의 `font`(기본 `pretendard`)면 header.xml 의 한글·영문 등 글꼴 이름을 Pretendard 로 바꾼다(글꼴 파일은 넣지 않아 없는 PC 는 비슷한 글꼴로 보임).
  `template` 이면 양식 글꼴 그대로. 양식 칸 앞의 띄움(` - 작업`)은 새 줄에도 그대로 둔다.
- **제목 크기** 는 규칙의 `title_grow`(기본 +4pt) — 제목 글자 모양을 복제해(양식의 다른 글은 그대로) 가장 큰 조각 크기 + n pt 로 맞춘다.
  제목 칸(또는 본문) 폭에 한 줄로 안 들어가면(Pretendard 폭으로 잼) 1pt 씩 줄이고, 양식 크기 밑으로는 내리지 않는다.
- **공휴일(`sitehub_holidays`)** — 양력 공휴일은 규칙, 설·추석·부처님오신날은 `SITEHUB_LUNAR` 표(2024~2030, 2024~2028 은 천문연구원 월력요항으로 확인),
  대체공휴일은 관공서 공휴일 규정대로 계산, 선거일·임시공휴일은 `SITEHUB_HOLIDAY_EXTRA`. 5월 1일(노동절)은 회사가 쉬어 해마다 넣고, 제헌절은 2026년부터.
  화면의 '추가 휴일'(모든 사이트 공통, `lab_sync_meta.report_holidays`)로 `2026-10-02 임시공휴일` 처럼 더하거나 `-2026-05-01` 로 뺀다. 2031년부터는 음력 표를 늘려야 한다.
  규칙 `holiday_shift`(기본 켬)면 정기 점검일이 공휴일(평일 요일이면 주말도)일 때 그 전 평일로 옮긴다(목·금이 쉬면 수). 앞 점검일·기간 시작을 넘으면 다음 평일,
  그 주가 다 쉬면 뺀다. 옮긴 줄은 미리보기에 `09-25(금) 추석 → 09-23(수)` 로 보인다.
- `내역서` 탭 맨 위에 하위 탭 두 개 — **유지보수내역서 · 청구서**. 고른 하위 탭은 그 브라우저에 기억한다(`localStorage.sh_rep_sub`).
  각 하위 탭은 번호 붙은 세 칸 — 1 양식 · 2 만들기(기간마다) · 3 만든 것 — 과 맨 위 단계 표시로 나눈다.
- **양식은 `.hwpx` 만.** 예전 `.hwp`(바이너리)는 채울 수 없어 한글에서 `한글 표준 문서(*.hwpx)` 로 다시 저장해 올린다. ZipArchive·DOM 만 쓴다(vendor 없음).
- 양식 읽기(`sitehub_report_analyze`): 날짜 이름표(수정일시·일자·날짜)와 '내용' 이 같은 행에 있는 표의 그 행 아래가 내용 행, `비고` 행 앞까지.
  내용 행이 3칸 이상이면 마지막 칸이 결과, 2칸이면 결과를 첫 줄 끝에 ` : 이상없음` 처럼 붙인다. `일자·유지보수대상·점검자·비고` 는 이름표 옆 칸
  (다른 표에 있어도, 이름표가 없으면 글 속 `YYYY년 M월 D일`). 제목은 `YYYY년 M월 ~ M월` 이 든 문단이고 기간만 바꾼다.
  견본 행에서 정기 점검 요일·문구·결과, 달마다 마지막 점검의 `정기점검` 문구(숫자는 `____` 빈칸), 날짜 모양(`2026. 06. 05`)을 뽑아 채우기 규칙으로 둔다.
- 채우기(`sitehub_report_fill`): 첫 내용 행을 복제해 행을 만들고 `rowAddr`·`rowCnt`·표 높이를 다시 매긴다. 고친 문단은 `linesegarray`(줄 배치 캐시)를
  지워 한글이 다시 계산하게 한다 — 남기면 글자가 겹쳐 보인다. 글자 모양은 그 칸 첫 글의 것을 쓴다.
- 행(`sitehub_report_rows`): 정기 점검 요일마다 한 줄 + 그 기간 Slack 이력(하위 작업 빼고, 기본은 처리완료 · 처리일 기준). 같은 날이면 한 행.
  이 리스트는 제목이 업체명이라 작업내용(`body`)을 줄로 쓴다. BLUMO 장애가 그 주에 있었으면 결과를 `장애 n건 복구` 로 두고 노랗게 표시한다.
- 파일 이름은 양식 파일 이름의 `YYYY_N분기` 를 기간만큼 옮긴다(양식 제목의 기간이 그 분기 — 계약 분기가 사이트마다 달라서).
- 양식은 다른 사이트 것을 가져다 쓸 수 있다(유지보수대상은 이 사이트 운영 URL 로). 올리기·규칙·만들기·삭제는 `변경 이력` 에 남는다.

## 대금청구서(`bill_lib.php` · `bill_pdf.php` · `static/bill.js`)
- `내역서` 탭의 `청구서` 하위 탭. **양식은 모든 사이트 공통 하나로 고정** — `templates/bill.xlsx`(블루소프트 대금청구서, 엑셀). 화면에서 올리거나 바꾸지 않는다.
  공급자·계좌·대표자 등을 고칠 때는 이 파일을 엑셀에서 고쳐 같은 이름으로 바꿔 넣는다(칸을 이름표로 찾으므로 위치가 바뀌어도 된다).
  계좌번호·사업자번호가 들어 있는 파일이라 저장소 공개 범위를 바꿀 때 주의.
- 칸 찾기(`sitehub_bill_analyze`): `수신·참조·건명·담당자` 이름표 오른쪽 칸, `품명` 머리 행 아래 ~ `합계` 행 위가 품목 줄(10줄),
  `합계액` 행의 수식 칸, `YYYY년 M월 일` 모양 칸이 청구 날짜. 품목 표 머리 칸은 No·품명·규격·단위·수량·단가·금액.
- 채우기(`sitehub_bill_fill`): 글은 공유 문자열에 더하고, 수식 칸(금액 `IF(J*M=0,"",J*M)`·합계·합계액)은 수식을 남긴 채 계산값만 넣는다.
  수식이 빠진 품목 줄(양식 15행)을 쓰면 같은 수식을 넣는다. `fullCalcOnLoad` 를 켜고 `calcChain.xml` 은 지워 엑셀이 열 때 다시 계산하게 한다.
  양식 칸 앞의 띄움(` 충북대학교…`)은 그대로 두고, 날짜는 양식처럼 '일'을 비운다(규칙 `day_blank`). 글꼴은 규칙 `font`(기본 Pretendard — 0번 기본 글꼴은 열 너비 기준이라 그대로).
- 금액: 양식 금액이 `세액포함` 서식이라 **품목 = 개월 수 × 월 단가(VAT 포함)** 한 줄. 월 단가는 규칙의 `price`, 비우면 계약 탭(기간과 가장 많이 겹치는 계약)의
  연간 금액 ÷ 12 — VAT 별도면 × 1.1. 안 떨어지면 원 미만 버림(규칙 `rounding`)하고 경고. 무상·미계약, 계약 기간 밖·일부, 납부 주기와 개월 수가 다르면 미리보기에 노랗게.
  합계액의 `일금 …원정` 은 엑셀 `[DBNum4]` 서식이 쓴다(LibreOffice 는 이 서식을 잘못 보여 준다 — 엑셀·PDF 는 맞음).
- 건명·품명은 규칙의 틀 — 기본 `{학년도}학년도 {분기}분기 {기관} 홈페이지 유지보수비` / `{분기}분기 홈페이지 유지보수({시작월}~{끝월}월)`.
  분기는 1분기 시작 월(기본 3월, 학년도)부터 3개월씩, 12~2월은 그 해 4분기. 그 밖에 `{연도}·{개월}`. `{기관}` 은 규칙 `org`, 비우면 사이트명.
  수신 기본값은 `고객사 사이트명`, 담당자 기본값은 내역서 점검자 → 양식 값. 파일 이름은 `{기관}_대금청구서_{학년도}_{분기}분기.xlsx`(분기에 맞지 않으면 `YYYY_MM-MM월`).
- **PDF(`bill_pdf.php`)** 는 채운 xlsx 의 첫 시트를 직접 그린다 — 열 너비(1 = 5.6pt)·행 높이·합친 칸·테두리(맞닿으면 굵은 쪽, 흰 선은 안 그림)·칸 배경(테마 색+tint)·
  정렬·셀에 맞춤·밑줄 서식 조각·숫자 서식(`#,##0`·따옴표·`\`→₩·`[DBNum4]`)·도장 그림. 인쇄 설정(A4·여백·가로 가운데)대로 한 쪽에, 넘치면 줄인다. 글꼴은 Pretendard.
- 기록: 사이트별 규칙 `lab_bill_settings`, 만든 청구서 `lab_bills`(채운 값 JSON·합계·파일 `var/sitehub/reports/bill/`). 규칙 저장·만들기·삭제는 `변경 이력` 에 남는다.

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
- 사이트 폴더 용량은 에이전트 v1.1(`monitor/agent.tpl`) 이 잰다 — 에이전트가 있는 폴더 전체와 업로드 폴더(`uploads`·`files`·`data`, 에이전트 `upload_dirs` 로 지정).
  하루 한 번만 새로 재고, 파일이 많으면 한 번 호출에 `usage_budget_s`(8초)까지만 훑고 다음 점검에서 잇는다(진행 상태는 쓰기 가능한 폴더의 `.blumo_usage.php`,
  첫 줄 `exit` 라 웹으로 열어도 안 보인다). `exec`·`du` 는 쓰지 않는다. v1.0 에이전트에는 이 값이 없으니 새로 받아 올려야 한다.
- 용량은 판정(class)·장애 이력과 따로 간다(`blumo_usage`). 하루 한 행 `lab_monitor_usage`(2년 보관), 감시 주소의 `disk_quota_mb`(호스팅 용량 한도)가 있으면
  설정의 `disk_warn_pct`(기본 85%) 와 100% 를 처음 넘을 때 웹훅, 내려가면 해소 웹훅. 단계는 `lab_monitor_state.disk_level`. 무음이면 단계만 바꾼다.
  `free_mb`·`used_pct` 는 서버 디스크 전체 기준이라 공유 호스팅의 계정 한도와 다르다 — 알림은 한도 대비 폴더 용량으로만 한다.

## 포털 등록
`core/worksystems.json`(`key: sitehub`), 루트 `index.php` 의 `sitehubIcon` · ICONS 맵, 모듈 안의 `need_login=sitehub` ·
`work_systems_menu(..., 'sitehub')` 세 곳을 함께 고친다.

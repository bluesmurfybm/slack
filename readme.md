# blue-iWorks — 사내 업무 포털

Bluesoft 사내 포털. 로그인 하나로 **BlueBooks(book, 도서구매신청)**, **DTI 발표(dti)**,
**BlueLearn(learning)**, **MoodleUp?(moodle)**, **업무현황판(slack 연동)**, **Gmail 뷰어**를 오가는 구조. 이 문서는 이어받아 작업할
개발자를 위한 현황 정리다.

## 전체 구조

```
D:\lms\slackapi\                 ← 포털(PHP) — 이 저장소의 루트
├── index.php                    로그인/대시보드/프로필 (SPA 한 페이지)
├── auth.php, db.php, config.php 포털 세션·DB·SSO 헬퍼
├── worksystems.json/.php        상단바 드롭다운 "업무 시스템" 목록의 유일한 원본 + 렌더러
├── api/                         login.php, logout.php, me.php
├── styles/                      default.css, favicon.ico, logo-blue.png
│
├── book/                        BlueBooks(도서구매신청) — Python/FastAPI, 별도 프로세스(포트 8000)
│   ├── app.py
│   ├── index.html
│   └── styles/
│
├── moodle/                      MoodleUp?(무들 동향) — PHP 뷰어 + Python 주간 배치
│   ├── index.php, db.php        주차별 리포트 화면(읽기 전용, 포털 세션)
│   ├── styles/moodle.css
│   └── watch/                   주 1회 수집·요약 배치 (systemd timer)
│       ├── run_weekly.py        진입점: 수집 → 스냅샷 → 요약 → MySQL INSERT → 슬랙 알림
│       ├── collectors/          moodleorg(PAG 코스 WS) · tracker(Jira) · github · devdocs · moodlecom(RSS)
│       ├── core/                config(pydantic-settings) · http · items · snapshot · store(PyMySQL)
│       ├── summarizer.py        anthropic SDK → claude CLI → 요약 없음 순으로 폴백
│       ├── var/                 state.json · snapshots/ · pages/ (gitignore)
│       └── tests/
│
├── access/                      Coursemos EnvHub — PHP, slack의 schools를 마스터로 씀
│   ├── access.php               목록 + 상세 + 편집 (복사 버튼)
│   ├── access_api.php           JSON API
│   ├── access_import.php        접속정보 엑셀 → DB (CLI / 화면 업로드 겸용)
│   ├── db.php, guard.php        전용 DB 연결 · 포털 로그인 가드
│   └── styles/access.css
│
├── learn/                       BlueLearn(강의 수강료 지원) — PHP, 포털 세션·DB 공유
│   ├── index.php                화면 한 장(SPA)
│   ├── api.php                  JSON API 프런트 컨트롤러 (?p=/requests/3 로 라우팅)
│   ├── db.php, guard.php        learn_* 테이블 · 시드 · 포털 로그인 가드
│   ├── lib/                     http(응답·검증) · status(요청상태 파생·전이) ·
│   │                            policy(환급 정책) · storage(이수증 파일)
│   ├── routes/                  identity · requests · certs · review ·
│   │                            sites · categories · policy · admins
│   ├── static/, styles/         도메인별 js · css
│   └── var/uploads/             이수증 원본 (gitignore, .htaccess 로 직접 접근 차단)
│
├── dti/                         DTI 발표 — PHP, 포털 세션·DB 공유
│   ├── index.php                화면 한 장(SPA). magazine/web/index.html 이식
│   ├── api.php                  프런트 컨트롤러 — 출력하는 유일한 자리
│   ├── bootstrap.php            require 목록 + 시간대
│   ├── db.php                   설정 · 연결 · 스키마 · 시드
│   ├── guard.php                포털 세션 신원 · 관리자 판정
│   ├── lib/
│   │   ├── http.php             DtiError · 요청 파싱 · 응답 · 입력 검증 · 라우팅
│   │   ├── topics.php           아티클 읽기/쓰기 · 상태 판정 · 화면용 배열
│   │   ├── presentations.php    발표 행 생성 · 삭제 · 배정 해제
│   │   ├── emotions.php, fields.php, members.php
│   │   ├── related.php, score.php     연관 점수 · 멤버 점수
│   │   ├── slots.php, storage.php, notify.php
│   ├── routes/                  topics · materials · fields · scores · identity
│   ├── static/, styles/         magazine/web/ 이식 (core.js 에 경로 변환만 추가)
│   ├── tools/                   rebuild_related.php · backfill_materials.php(일회성)
│   ├── tests/                   PHPUnit 169건
│   └── var/uploads/             발표자료 원본 (gitignore, .htaccess 로 직접 접근 차단)
│
└── slack/                       업무현황판 — PHP, 포털과 같은 Apache/세션 공유
    ├── auth.php, db.php, config.php, slack_lib.php, header.php   (공통)
    ├── lists.php, comments.php, data.php, assign.php, ...        (핵심 요청 목록 기능)
    ├── difficulty/   난이도 분석
    ├── category/     카테고리 분석
    ├── similar/      유사 요청 찾기
    ├── schools/      대학 사이트 관리
    ├── gmail/        Gmail 뷰어/동기화(IMAP)
    └── styles/       페이지별 css + header.css(공통 상단바)
```

**book(8000)만 다른 프로세스/포트**(FastAPI)다. **portal과 slack은 완전히 같은
PHP 앱**이라고 봐도 된다 — slack/은 물리적으로 하위 폴더일 뿐, 세션도 같은 걸 공유한다.

---

## 로그인 / SSO 구조 (제일 먼저 이해해야 할 부분)

- **회원 저장소**: MySQL `slackapi` DB의 `portal_users` 테이블 하나. 이메일이 유일한 식별자.
  최초 실행 시 사내 인원 시드 + 초기 비번 `blue$123`(bcrypt).
- **포털 로그인**: `index.php` → `api/login.php`. 로그인 성공 시 PHP 세션(`BLUEIWORK_SESSID`)에
  `portal_uid` 저장. "자동 로그인" 체크박스에 따라 세션 쿠키 수명이 30일 슬라이딩(체크) 또는
  브라우저 세션(미체크)으로 갈림.
- **초기 설정 강제**: 초기 비번(`blue$123`)을 안 바꿨으면(`auth.php::needs_setup()`) 로그인 직후
  무조건 프로필 화면에 묶인다(취소 버튼도 비활성화). **슬랙 토큰 미등록은 이 조건에 안 들어간다** —
  book은 토큰 없이도 써야 하기 때문. 토큰이 필요한 건 slack 진입 시점뿐.
- **slack 모듈**: 별도 로그인 없음. `slack/auth.php::require_login()`이 포털 세션을 그대로 읽고,
  `portal_users.slack_token_enc`(AES-256-GCM 암호화된 개인 Slack 토큰)를 복호화해 Slack
  `auth.test`로 검증한 뒤 세션에 캐시한다. 포털 로그인이 없으면 `../index.php?need_login=slack`로,
  토큰이 없거나 무효면 `../index.php?need_token=1`로 리다이렉트 → 포털이 알림과 함께 프로필
  화면을 띄운다.
- **미로그인으로 튕길 때는 이유를 알린다**: 화면(뷰) 라우트는 미로그인 사용자를 포털로 되돌릴 때
  `?need_login=<key>`를 붙인다(key는 `worksystems.json`의 key). 포털은 `need_login_notice()`로
  이름까지 붙인 문구를 만들어 로그인 화면에 토스트로 띄우고, URL에서 그 플래그만 지운다
  (`pathname`으로 싹 지우면 `need_token`·`view=profile`까지 날아간다). 붙이는 곳은
  slack `auth.php`, dti·moodle `index.php`, moodle `bookmarks.php`, learn·access `guard.php`,
  book·learning `app.py` — 여덟 곳이다. **API는 다르다** — 401을 그대로 응답하고, 화면 JS가
  그 401을 받아 포털로 보낸다(book `authedFetch`).
- **book 모듈(다른 프로세스)**: 포털이 로그인 시 `blueiwork_id` 쿠키를 심는다 — 이메일+이름을
  HMAC-SHA256으로 서명한 값(`auth.php::issue_sso_cookie()`). book(Python, `app.py`)은 같은
  비밀키(`sso_secret.key`, 포털이 최초 실행 시 자동 생성)로 **서명만 검증**해서 이메일/이름을
  얻는다. book은 MySQL에 붙지 않는다 — 쿠키 자체가 신원 증명.
  - **전제: 포털과 book이 같은 호스트**(포트만 달라도 됨)여야 브라우저가 쿠키를 같이
    보낸다. 다른 PC에서 띄우면 SSO가 동작하지 않는다.
  - book 쪽 로그아웃 링크는 포털의 `api/logout.php`를 GET으로 직접 연다(`api/logout.php`가
    POST면 JSON, GET이면 `index.php`로 리다이렉트하도록 나뉘어 있음).
- **공통 상단바**: `slack/header.php`(PHP include)와 `book/index.html`의 `.bw-topbar`가 시각적으로
  동일한 blue-iWorks 상단바(로고+사용자명+로그아웃)를 각자 방식으로 그린다. slack 하위 폴더
  페이지는 include 전에 `$__bwBase = '../';`를 반드시 설정해야 링크가 안 깨진다(폴더 깊이 보정용).
- **상단바 드롭다운의 "업무 시스템" 목록**: 원본은 `worksystems.json` 하나다(key·이모지·라벨 +
  포털 루트 기준 경로). 시스템이 늘거나 이름이 바뀌면 여기만 고치면 전 모듈 상단바와 포털
  대시보드 타일이 같이 바뀐다.
  - PHP 쪽(포털·slack·access·moodle·dti·learn)은 `worksystems.php::work_systems_menu($base, $current)`
    로 서버에서 그린다. `$base`는 그 페이지에서 **포털 루트까지 되짚는 접두사**(포털 `''`,
    dti/learn/moodle/slack `'../'`, slack 하위 폴더 `'../../'`), `$current`는 현재 시스템 key —
    그 줄이 `.on`(`styles/topbar.css`)으로 표시된다. `slack/header.php`를 쓰는 페이지는
    `$__bwCurrent`만 넘기면 된다.
  - 별도 프로세스인 book·learning은 PHP를 못 쓰니 `app.py`/`router.py`가 **같은 json을 직접 읽어**
    `whoami` 로 내려주고 화면 JS(`renderWorkSystems`)가 그린다. 포털 트리가 안 보이면 목록만
    비고 화면은 정상 동작한다.

---

## book 권한 모델

- 신청자는 항상 로그인한 본인 — 프론트 피커도 본인만 뜨고, 서버(`app.py`)도 클라이언트가 보낸
  `applicant` 값을 무시하고 SSO 쿠키의 신원으로 강제 기록(`applicant_email` 컬럼).
- 수정/삭제: 본인 글이거나 관리자(`ADMIN_EMAIL` = `jian@bluesoft.co.kr`, `app.py`에 하드코딩)만 가능.
- 완료 처리(`done`)는 **관리자만** 가능(작성자 본인도 불가).
- 레거시 데이터(로그인 연동 이전 신청)는 이름→이메일 매핑으로 최선 노력 백필했음(`NAME_TO_EMAIL`).

---

## dti (DTI 발표)

매거진(DI, MIT TR) 아티클 발표 주제를 관리한다. 원래 xlsx 로 돌리던 걸 파이썬(FastAPI/SQLite)
으로 옮겼다가, 다시 포털과 같은 PHP 앱 안으로 들여왔다(`learning/` → `learn/` 과 같은 이유 —
별도 프로세스라서 필요했던 uvicorn 유닛·nginx 프록시·venv·SSO 쿠키가 전부 사라진다).

- **learn/ 과 같은 모양이다** — `lib/` 와 `routes/` 에 `dti_` 접두사 전역 함수를 나열한다.
  소스에 도메인 클래스는 없고, 남는 클래스는 예외 하나(`DtiError`)와 PHPUnit 테스트뿐이다.
  행은 PDO 연관 배열을 그대로 넘긴다.
- **다만 라우트는 값을 돌려준다** — learn 의 `jsend()` 는 출력하고 `exit` 해서 라우트 단위
  테스트를 붙일 자리가 없다. dti 의 라우트는 `['status' => .., 'data' => ..]` 를 반환하고
  출력은 `api.php` 의 `dti_send()` 한 곳에서만 한다. 169건 중 129건이 이 덕분에 라우트를
  통째로 검증한다.
- **전역 `static` 캐시를 쓰지 않는다** — learn 의 `learn_policy()`·`body_json()` 같은 캐시는
  테스트 간에 상태가 남는다. 설정·연결·신원은 `$ctx` 배열로, 요청은 `$req` 배열로 넘긴다.
- **PPT 를 올리면 변환된 PDF 가 자료로 하나 더 들어간다** — pptx·ppt·odp 를 올리면 업로드
  시점에 `soffice --headless` 로 PDF 를 만들어 **같은 칸에 별개 자료 행으로** 넣는다. 원본과
  변환본은 **독립이다** — 각자 내려받고, 각자 지우고, PDF 는 기존 PDF 뷰어에 그대로 태워진다.
  변환에 실패하면 PPT 만 올라가고 PDF 행이 안 생긴다(업로드는 성공). 변환기는
  `$ctx['converter']` 로 갈아끼운다(`$ctx['mover']` 와 같은 자리). 키노트(`.key`)는 변환할 수
  없어 제외한다. **설치·PHP 설정·증상별 확인은 `dti/README.md`** 에 있다(LibreOffice 패키지,
  `max_execution_time` 과 `soffice_timeout` 관계 등).
- **테이블은 `dti_*`** — `dti_topics`, `dti_presentations`, `dti_emotions`, `dti_fields`,
  `dti_related`. slackapi DB 를 포털·slack·learn 과 공유하므로 맨이름을 쓸 수 없다.
  컬럼 추가는 `add_column_if_missing()` 을 거친다.
- **자료는 칸(slot)당 여러 건이다** — `dti_materials` 가 원본이고 `topic_id + slot` 으로 건다
  (발표는 아티클과 1:1 이라 발표자료도 topic_id 로 잡는다). 응답의 `material_kind`·`material_name`
  ·`material_url`·`material_path` 는 **첫 자료에서 파생한 값**이고, 목록은 `materials`·`scans`
  배열에 실린다. 파생 키를 남겨 둔 건 카드 정렬(`topics.js`)과 멤버 점수가 그걸 보고 있어서다.
  멤버 점수의 "자료 3점" 은 몇 건을 올리든 한 번이다.
- **자료 슬롯은 NULL 을 유지한다** — `material_*`·`scan_*` 는 "없음"이 NULL 이고 화면이 그
  구분에 기댄다. learn 의 `NOT NULL DEFAULT ''` 관례를 여기 적용하면 안 된다.
  날짜는 VARCHAR 다(`''` 가 없음).
- **인증은 포털 세션**(`guard.php` 의 `dti_identity()`). 구성원 명단은 `portal_users` 에서
  오고, 팀 매핑과 관리자 명단만 `db.php` 의 `DTI_` 상수다. 개발 로그인(`DEV_LOGIN`)은 화면·API 에서 **없앴다** —
  포털 세션을 쓰는 이상 로그인 없이 화면을 보는 경로가 없다.
- **화면은 거의 그대로다** — `core.js` 의 `dtiApiURL()` 이 `/magazineapi/topics/3` 을
  `api.php?p=/topics/3` 으로 바꾼다. 나머지 도메인 스크립트는 손대지 않았다(`material.js` 의
  다운로드 URL 한 줄만 같은 함수를 쓴다).
- **연관 점수**는 `dti_related` 에 저장하고 등록·수정·삭제 때 다시 계산한다. 파이썬은 기동할
  때도 계산했지만 PHP 에는 기동 훅이 없으므로, 배점 상수를 바꾸면
  `php dti/tools/rebuild_related.php` 를 한 번 돌린다.
- **런타임 의존성이 0이다** — 소스가 쓰는 외부 라이브러리는 PHP 내장 `PDO` 뿐이고, 파일
  로딩은 `dti/bootstrap.php` 의 `require_once` 목록이 한다. **composer 는 테스트에만 쓴다.**
  서버에 composer 가 없어도, `vendor/` 를 올리지 않아도 파일만 복사하면 돌아간다
  (access·moodle·learn 과 같은 배포).
- **테스트**: `vendor/bin/phpunit` (169건). 테스트를 돌릴 때만 `composer install` 이 필요하다. 테스트 DB 는 `slackapi_test` 를 쓴다
  (`dti/tests/bootstrap.php`, 환경변수 `DTI_TEST_DB` 로 바꿀 수 있다).
  **이 환경은 커밋마다 fsync 가 돌아 쓰기 한 건이 0.2초다** — 픽스처는 트랜잭션으로 묶고
  테이블은 TRUNCATE 가 아니라 DELETE 로 비운다(TRUNCATE 는 InnoDB 에서 DDL 이라 3초 가까이
  걸린다). 로컬을 더 빠르게 하려면 MySQL 에서
  `SET GLOBAL innodb_flush_log_at_trx_commit=2, sync_binlog=0` (내구성 대신 속도, 개발 전용).
- **이관 검증**: 실데이터 사본으로 파이썬 앱과 PHP 의 응답을 통째로 비교해 **차이가 없음을
  확인했다**(아티클 31건 전체 키·값, 목록 순서, 분야, 멤버 점수 13행, 연관 31건).
  연관 점수는 파이썬이 저장해 둔 106쌍과 점수까지 일치한다.

### 남은 전환 작업

파이썬 magazine 은 저장소에서 지웠고 실데이터도 `dti_*` 로 옮겼다. 서버에 남은 건 이것뿐이다.

1. nginx 의 `/magazine/`·`/magazineapi/` 블록 제거
2. `magazine.service`(uvicorn 8001) 중지·비활성화, `/home/blueapp_core/magazine` 정리
3. `php dti/tools/backfill_materials.php` — 컬럼에 있던 자료를 `dti_materials` 로 옮긴다
   (`--dry-run` 으로 먼저 확인, 여러 번 돌려도 안전). 끝나면 이 도구도 지운다
4. `dti_topics`·`dti_presentations` 의 죽은 컬럼(`presenter`·`planned_date`·`material_*`
   ·`scan_*`) 정리

---

## moodle (MoodleUp? · 무들 동향)

moodle.org **Technical Transformation PAG** 코스(id 17257), Moodle Tracker(Jira Cloud), GitHub
`moodle/moodle`, moodledev.io(`moodle/devdocs`), moodle.com 뉴스를 **주 1회 모아 한국어로 요약**하고
코스모스 관점의 영향도(고/중/저)를 붙여 보여준다. 기획 원문은 `moodle/moodle-weekly-followup-checking.md`
(gitignore, 개인 문서).

- **두 조각이다.** `moodle/watch/`(Python 배치)가 쓰고, `moodle/index.php`(PHP)가 읽는다. 배치는
  실행당 **한 트랜잭션**이고 화면은 SELECT 만 한다 — 폴링·UPDATE 는 없다. 별도 포트·nginx 블록도
  없다. `/moodle/` 는 포털 PHP 그대로다.
- **테이블**: `moodle_weekly_report`(주차 1행: 요약 md·헤드라인·액션·소스별 상태·갱신 횟수) +
  `moodle_weekly_item`(원문 항목: 제목·링크·발췌·영향도·처음 들어온 실행 번호 `added_run`) +
  `moodle_weekly_run`(실행 이력: 회차·시각·주체·새 항목 수·갱신 요약). DDL 은 `watch/core/store.py`
  와 `moodle/db.php` 두 곳에 같은 내용이 있다 — 컬럼을 바꾸면 둘 다 고친다. 컬럼 추가는 양쪽 모두
  "있으면 무시" 방식(`MIGRATIONS` / `add_column_if_missing`)으로 붙인다.
- **한 주차는 여러 번 돈다.** 월요일 timer 가 1회차(처음 생성)를 만들고, 화면의 **요약하기**
  버튼이나 `--refresh WEEK` 가 같은 주차를 갱신한다. 갱신은 항목을 다시 넣되 `added_run` 을
  보존해서 처음 들어온 것과 나중에 추가된 것을 가른다. 가장 최근 주차를 갱신하면 구간 끝을 지금
  시각까지 늘려 이번 주에 새로 생긴 것까지 잡고, 지난 주차는 같은 구간을 다시 본다.
- **갱신은 기존 요약을 다시 쓰지 않는다.** 형광펜·메모가 텍스트 앵커라 본문이 바뀌면 자리를 잃기
  때문이다. 갱신 실행은 이전 실행 이후 새로 들어온 항목만 골라(`digest.build_update`) 추가분 요약을
  받고(`summarizer.summarize_update`, `UPDATE_SCHEMA`), 기존 본문 아래에 `---` 구분선과
  `### 갱신 YYYY-MM-DD HH:MM · 새 항목 N건` 제목으로 덧붙인다(`run_weekly.append_update`). 헤드라인·
  모델은 1회차 것을 유지하고 액션은 뒤에 이어 붙인다. 새 항목이 없으면 모델을 부르지 않고 항목·이력만
  갱신한다(상태 ok). 화면은 NEW 배지·필터로 새 항목을 표시한다.
- **버튼은 PHP 가 Python 을 띄우는 게 아니다.** `moodle/refresh.php` 가 `watch/var/requests/<week>.json`
  을 남기고, 서버에서는 systemd **path 유닛**이 그 파일이 생기면 `run_weekly.py --requests` 를
  실행한다(아래 배포 절). 로컬에서는 `python run_weekly.py --serve` 를 켜 두면 3초마다 폴더를 보고
  처리한다. 파일 상태가 진행 표시다: `<week>.json`(대기) → `<week>.running`(처리 중) →
  삭제(완료) 또는 `<week>.failed`(실패 사유, 화면에 표시되고 버튼을 다시 누르면 재시도).
  화면은 처리 중일 때만 5초마다 `refresh.php?status=` 로 파일 상태를 묻는다(DB 는 안 본다).
  php-fpm 사용자와 배치 사용자가 다르므로 그 폴더는 둘이 같이 쓸 수 있어야 한다(배포 절 참고).
  배치 `DATA_DIR` 을 기본값에서 바꿨으면 `moodle/config.local.php` 에 `return ['data_dir' => '...'];`
  로 PHP 에도 알려준다. 대기가 3분을 넘으면(배치가 안 떠 있음) 화면이 노랗게 알리고 **요청 취소**
  버튼이 뜬다(대기 파일 삭제). 처리 중인 요청은 30분 넘게 멈춘 경우에만 취소할 수 있다.
- **관리자 화면과 일반 화면.** `moodle/db.php::MOODLE_ADMINS`(현재 amitoa) 만 요약하기 버튼·처리 상태·
  갱신 이력·상태 배지·모델명·실행 노트를 본다. `refresh.php` 도 관리자가 아니면 거절한다. 나머지
  계정은 주차 목록, 요약, 소스 칩(건수), 원문, 형광펜·메모만 본다. 관리자는 **일반계정화면** 버튼
  (`?as=user`)으로 일반 계정이 보는 그대로를 확인할 수 있다. 판정은 `$showAdmin` 하나로 모인다.
- **상태 배지**(관리자 화면): `ok` 모든 소스 수집 + 요약 생성 / `partial` 일부 소스 실패 또는 요약 없음(항목은 저장)
  / `failed` 모든 소스 실패(저장 안 함). 소스 칩의 `skipped` 는 설정이 없어 건너뛴 것(토큰 없음).
  판정은 `run_weekly.py::decide_status`.
- **PAG 가 중심이다.** 요약 프롬프트는 `## PAG 동향` 절을 생략 불가로 두고 헤드라인·한눈에 첫 줄도
  PAG 소식을 앞세운다. 화면은 moodle.org 소스 칩·원문 묶음에 '핵심' 표식, 제목에 PAG 가 들어간
  요약 절을 주황 상자(`moodle_md_emphasize_pag`)로, 본문의 PAG 낱말을 태그로 강조한다.
- **형광펜·메모·북마크**: 요약 글을 드래그하면 미니 도구막대가 떠서 형광펜(노랑·주황·초록), 메모(파랑,
  클릭하면 말풍선), 북마크(보라, 🔖)를 남긴다. 북마크는 `moodle/bookmarks.php` 에 주차별로 모이고
  단락·주차·헤드라인·작성자로 검색(LIKE)할 수 있다. 단락을 누르면 그 주차 요약의 자리(`#note-ID`)로 간다. 팀이 함께 보고, 지우기는 작성자만. 저장은 `moodle/notes.php`(사용자가 누를
  때만 INSERT/DELETE), 테이블은 `moodle_note`(PHP 전용, 배치는 모른다). 위치는 DOM 이 아니라 **텍스트
  앵커**(선택한 글 + 앞뒤 40자)로 저장하고 JS 가 다시 찍는다 — 요약이 갱신되어 문장이 바뀌면 그 표시는
  목록에 "위치를 못 찾았습니다" 로만 남는다.
- **수집기는 서로 독립**(`collectors/base.py::run_safely`). 하나가 죽어도 나머지는 저장되고 리포트
  상태가 `partial` 이 된다. 다 죽으면 `failed` 이고 DB 에 넣지 않는다.
- **moodle.org 는 토큰이 있어야 한다**(`MOODLE_ORG_TOKEN`, 모바일 WS 토큰). 없으면 그 소스만
  `skipped`. 페이지 본문은 `mod_page_get_pages_by_courses` 가 JSON 으로 준다. 해시로 변경을 잡고, 첫
  실행은 기준만 잡는다(`var/pages/*.txt` 에 이전 본문을 두어 다음 변경 때 unified diff 를 보여준다).
  **책(book) 챕터는 못 받는다** — WS 가 본문을 안 주고 `webservice/pluginfile.php` 는 moodle.org 의
  Cloudflare 가 봇으로 보고 403 을 낸다(토큰·등록은 정상이어도). 그 모듈만 건너뛰고 소스 칩의
  툴팁(note)에 이름이 남는다. 포럼 글·페이지·통계는 전부 REST 로 받으므로 영향이 없다.
- **트래커는 주당 수백 건**이라 전부 저장하되 요약 입력에는 주목(★) 항목만 넘긴다 — Fixed 이면서
  Improvement/New Feature/Task/Epic 이거나 `core/config.py::FOCUS_KEYWORDS`(react, composer, oauth,
  deprecat …)에 걸리는 것. 나머지는 통계(컴포넌트·fixVersion 분포)로만 간다.
- **요약**: `summarizer.py`. `ANTHROPIC_API_KEY` 가 있으면 anthropic SDK(`claude-opus-5`, JSON 스키마
  출력, 안전 분류기 거부 시 서버측 fallbacks), 없으면 서버의 `claude -p`, 둘 다 없으면 요약 없이
  원문 항목만 저장하고 `partial` 로 남긴다. 모델이 매긴 영향도는 URL 로 항목에 되돌려 붙인다
  (`run_weekly.py::apply_impacts`).
- **DB 접속 정보는 두 번 적지 않는다.** `DB_HOST` 등이 없으면 배치가 `php -r` 로 포털 `config.php`
  의 `db` 배열을 읽는다(`core/config.py::_db_from_php`). 서버에 php CLI 가 있어야 한다.
- **테스트**: `cd moodle/watch && python -m pytest`(47건, 외부 호출은 전부 가짜 HTTP). `ruff check .`
  무경고. 실제 API 로 돌려보려면 `python run_weekly.py --dry-run --no-summary --since 2026-09-01`
  (moodle.org 외 4개 소스는 익명으로 된다. GitHub 는 시간당 60회 제한 — `GITHUB_TOKEN` 을 주면 5000회).
  로컬에서 버튼까지 써 보려면 터미널 하나에 `python run_weekly.py --serve` 를 켜 두고 화면에서 누른다.

---

## access (학교 접속 정보)

대학별 svn/git 주소 · 사이트 로그인 · 개발/운영/학사 DB · plink 터널링 · 배포 방법을 찾아
**클립보드로 복사**하는 화면(`access/access.php`). 원본은 사내 공유 엑셀
`SVN_배포_디비정보(블루내부공유).xlsx`.

- **`schools` 가 마스터다.** 대학명 · 버전 · 개발/운영/로그 URL 은 slack 의 `schools` 를 그대로
  쓰고(= `slack/schools/schools_admin.php` 와 같은 데이터), 접속·배포 정보만 `school_access` 에
  둔다. 대학명과 URL을 양쪽에 복제하지 않는 게 이 구조의 핵심.
- **학교는 (이름, 버전)으로 식별한다.** 이름만 같고 버전이 다르면 서버·저장소·계정이 전부
  다른 별개 사이트라(강원대 3.2 의 svn 과 강원대 4.5 의 git) 한 대학으로 묶으면 안 된다.
  가져오기는 이름과 버전이 **둘 다** 맞는 학교에만 붙이고, 버전이 안 맞으면 기존 학교에
  얹지 않고 `schools` 에 행을 새로 만든다. 현재 이렇게 갈린 곳이 10군데(강원대·부산대·
  서울시립대·한림대·송곡대·우송정보대·혜전대·소프트랩·한국기술교육대·국립보건연구원).
- **초기 데이터 넣기** — 엑셀을 루트에 두고:
  ```
  php access/access_import.php                 # 이미 데이터가 있으면 중단
  php access/access_import.php --force         # 덮어쓰기
  ```
  화면의 `[엑셀 가져오기]` 버튼으로 업로드해도 같은 코드가 돈다. 엑셀에만 있는 학교는
  `schools` 에 새로 등록되지만, **이미 있는 학교의 이름·URL은 덮어쓰지 않는다**(관리 화면에서
  손본 값 보호). URL 칸이 비어 있을 때만 채워 준다.
- **로그인은 운영/테스트로 갈라 저장하고, 계정과 비밀번호도 나눈다.** 엑셀은 한 칸에
  `개발 : … ⏎ 운영 : …` 처럼 몰아 적어 놨는데 실제로 필요한 건 "지금 이 사이트 비번" 하나다.
  - `access_split_login()` — 운영/테스트로 가른다. 라벨이 붙은 162건과 라벨 없이 두 줄인
    16건(앞이 테스트, 뒤가 운영)은 갈라지고, 라벨 없는 한 줄 79건은 단정할 수 없어
    `login_info` 원문에 남는다.
  - `access_split_account()` — `csmsathena / Zhtm&ahtm1` 을 계정과 비밀번호로 가른다.
    **`login_ops`/`login_dev` 에는 비밀번호만** 들어가서 복사 버튼이 곧바로 비밀번호 칸에
    붙는다. 계정은 `login_ops_id`/`login_dev_id` 로 뺀다 — 대부분 csmsathena(65)·admin(13)
    이지만 obj007·geladmin·manager 같은 고유 계정이 9건 있어 버리면 로그인이 안 된다.
    한 줄짜리 값만 가르고(여러 줄은 설명이 섞인 것), 왼쪽은 영숫자 `. _ @ -` 만 허용해
    `&`·`!` 가 든 비밀번호를 계정으로 오인하지 않는다.
- **`school_access` 가 안 갖는 것** — 대학명·버전·URL은 `schools` 것을 쓴다. 무들 상세버전
  (`moodle_ver`)과 엑셀 시트명(`grp`)은 컬럼째 없앴다. 엑셀의 '무들 버전' 칸은 가져오기가
  학교를 짝지을 때만 쓰고 저장하지 않는다.
- **`school_access` 에는 유일 제약이 없다.** 위 규칙 덕에 실질적으로 학교당 1행이지만,
  가톨릭성서모임처럼 같은 버전(3.9)으로 두 시트(3.9 · 3.9-saas)에 다 올라온 경우가 남아
  있어서 `UNIQUE(school_id)` 를 걸면 뒤 시트가 앞 시트를 덮어쓴다. 행은 각자의 `id` 로
  구분하고 `school_id` 로 JOIN 한다. 가져오기는 TRUNCATE 후 넣으므로 upsert 가 필요 없다.
- 3.9-saas 시트에만 있는 '운영 웹서버' 칸은 전용 컬럼(`ops_web`)을 두지 않고 비고(`note`)에
  `[운영 웹서버]` 머리말을 달아 합쳐 넣는다(`access_merge_note()`). 값이 한 건뿐이라 컬럼을
  따로 유지할 이유가 없었다.
- `dev_note`/`ops_note` 는 URL 칸 원문 중 **마스터에 없는 것만** 남긴다
  (`ax_strip_known_urls()`). 주소 하나만 적힌 줄이고 그 호스트가 이미 `schools.dev/ops` 에
  있으면 버려서 33·47건이 17·12건으로 줄었다. 남는 건 여분의 도메인이나
  "개발서버는 블루에서만 접근 가능" 같은 진짜 메모다.
- 목록의 `svn / git` 칸은 종류를 칩으로 보여 주고, git 인데 주소만 적힌 경우
  복사 버튼이 `git clone <주소>` 로 만들어 준다(`repoInfo()`). 현재 svn 241 · git 18 ·
  주소 자체가 없는 것 15.
- **비밀번호는 DB에 암호화해 둔다.** 복사 버튼이 있어야 하니 되돌릴 수 있어야 해서, 포털이
  슬랙 토큰에 쓰는 것과 같은 AES-256-GCM + `config.php` 의 `key` 를 쓴다(`access_enc()`/
  `access_dec()`). 대상은 `login_ops`·`login_dev`·`login_info` 세 칸. 저장 형태는
  `enc:v1:<base64(iv|tag|cipher)>` 이고, 접두사가 없으면 아직 안 옮긴 평문으로 보고 그대로
  돌려주므로 마이그레이션을 여러 번 돌려도 안전하다. API가 내려줄 때 풀어서 보내므로
  **응답 본문에는 평문이 실린다** — 운영에서는 HTTPS 로 서비스해야 한다.
  `auth.php` 의 `enc_token()` 을 그대로 안 쓰는 건 그 파일이 include 시점에 세션을 여는데
  가져오기 스크립트는 CLI 로도 돌기 때문.
  **아직 평문인 것**: `dev_db`·`ops_db`·`haksa_db`·`plink`·`deploy_acct` 안에 섞여 있는
  서버·DB 비밀번호는 자유 서술이라 손대지 않았다.
- **화면은 공통 / 테스트 서버 / 운영 서버 세 묶음이다.** 구성은 `access_field_groups()` 한
  곳에 있고 상세 패널과 편집 폼이 같은 정의를 쓴다.

  | 묶음 | 칸 |
  |---|---|
  | 공통 정보 | 최초 오픈 · svn/git 주소 · VPN 프로그램 · VPN 접속 방법 · 배포 방법 · 비고 |
  | 테스트 서버 | 개발 URL · 아이디 · 비밀번호 · 메모 · 개발 DB |
  | 운영 서버 | 운영 URL · 로그 관리 · 아이디 · 비밀번호 · 메모 · 운영 DB · 학사 DB |

  이 구조로 정리하면서 흩어져 있던 칸을 합쳤다. **어느 것이든 "옮긴 다음 지운다"** 순서를
  지켜야 내용이 사라지지 않는다(`access_merge_dropped_cols()`).
  - `etc`(46) · `기타`(3) · `plink`(1) → **비고**. `[etc]` 처럼 머리말을 달아 출처를 남긴다.
  - `배포 계정`(115) → **배포 방법**
  - `로그인 정보(구분 없음)`(81) → **운영 비밀번호**. 라벨 없이 한 줄만 적힌 값이 대부분이고
    테스트는 거의 `Zhtm&ahtm1` 고정이라 운영 쪽으로 본다. `csmsathena / 비번` 처럼 계정이
    붙어 있으면 아이디도 떼어 낸다.
  - 가져오기도 같은 결과를 내도록 고쳤다. 매핑에서 `_` 로 시작하는 키(`_etc`·`_plink`·
    `_deploy_acct`·`_login_info`)가 전용 컬럼 없이 합쳐 넣는 칸이다.
- 필드 `type` 이 화면을 정한다 — `input`(한 줄) · `area`(여러 줄) · `pw`(가림) · `rich`(Editor.js) ·
  `url`(마스터인 schools 값이라 편집은 '학교 정보'에서).
- **비밀번호는 `input[type=password]` + 눈 아이콘**으로 가린다. 저장값에 줄바꿈이 있는 행이
  7건 있는데 input 은 줄바꿈을 못 담으므로, 화면에는 한 줄로 펴서 보여 주되 **그 칸을 손대지
  않았으면 저장할 때 원본을 되돌린다**(access.php 의 `save()`). 안 건드린 값이 조용히 잘리면 안 된다.
- **설명성 칸은 Editor.js 로 편집한다**(굵기·글자색·취소선·목록). 대상은 `type: rich` 인 칸 —
  VPN 접속 방법 · 배포 방법 · 비고 · 개발/운영 메모 · 개발/운영/학사 DB.
  저장소 주소와 계정 ID·비밀번호는 복사해서 그대로 붙여 넣는 값이라 뺐다.
  - 저장값은 Editor.js 의 블록 JSON 이지만, 엑셀에서 가져온 값은 전부 평문이라
    **"JSON 으로 안 읽히면 평문"** 으로 보고 둘 다 받는다(`ejParse`). 마이그레이션이 없다.
  - 화면에 그릴 때는 `ejSanitize()` 로 서식 태그(b/s/span 등)만 남기고 속성은 색상 style 만
    통과시킨다. script·style·iframe 류는 벗기지 않고 통째로 버린다 — 껍데기만 벗기면 안에
    있던 코드가 글자로 남는다.
  - **복사 버튼과 검색은 항상 평문**을 쓴다(`ejToText`). plink 추출도 평문화 후에 돌린다.
  - Editor.js 는 자기 CSS 를 런타임에 head 끝으로 밀어 넣어서 우리 스타일이 항상 진다.
    다크 대응은 `.ejholder` 접두로 특이도를 올리고, 전역으로 덮이는 `::selection` 만 `!important`
    로 되돌린다.
- 목록은 **리스트/카드 두 가지 보기**를 지원한다(툴바 오른쪽 토글, 선택은 `localStorage`).
  저장소 칸은 종류 칩과 복사 버튼만 두고 주소는 상세에서 본다.
- 필터는 버전 칩(버전별 학교 수 표시)과 VPN 드롭다운 두 가지다. VPN 은 `FortiClient, Arcon`
  처럼 둘을 같이 쓰는 곳이 있어서 값 전체가 아니라 쉼표로 나눈 프로그램 하나하나로 고르고
  포함 여부로 거른다.
- **대학 추가는 `schools` 부터 넣는다.** 목록이 `schools LEFT JOIN school_access` 라, 접속 정보만
  만들고 마스터에 안 넣으면 화면에 아예 안 나온다. `action:create` 가 `schools` INSERT →
  그 id 로 `school_access` INSERT 순으로 처리한다. 이름만으로는 막지 않고(강원대 3.2/4.5 처럼
  같은 대학의 다른 버전은 별도 행이 정상) **이름·버전이 똑같을 때만** 중복으로 거절한다.
- 로그인 계정은 목록에서 칩으로 구분한다 — `csmsathena`(34) · `admin`(6) · 그 외 고유 계정(4:
  obj007 · mmaster · geladmin · yadmin). 운영·테스트 계정이 같으면 칩 하나로 묶는다.
- **`vpn` 은 프로그램명(varchar)이다.** 있으면 그 이름이 목록에 그대로 뜨고, 비어 있으면
  별도 실행이 필요 없다는 뜻. 엑셀엔 VPN 전용 칸이 없어 비고·배포방법·DB 설명을 훑어
  HIWARE / FortiClient / Citrix / SecuwaySSL / Arcon / WinNGS 를 찾아 채운다
  (`access_detect_vpn()`, 274건 중 51건). 어디까지나 초깃값이고 근거 문장을 `vpn_note` 에
  남기니 화면에서 고치면 된다. Arcon 은 엄밀히는 VPN이 아니라 접근제어(PAM)지만
  "먼저 켜야 접속된다"는 점이 같아 함께 잡는다.
- 복사 버튼은 `navigator.clipboard` 가 없을 때(개발 URL이 http 라 포털도 http 로 여는 경우)를
  대비해 `execCommand` 폴백을 반드시 거친다. 사이트 주소는 복사 대상이 아니다 — 목록의
  링크를 바로 누르면 되기 때문.
- 엑셀의 `plink` 전용 칸은 거의 비어 있고 실제 터널링 명령은 **학사 DB · 운영 DB 설명 안에**
  섞여 있다. 그래서 목록의 plink 복사 버튼은 그 칸들까지 훑어서 명령 줄만 뽑아 준다.
- 엑셀의 나머지 시트(표절 · 보안취약점조치 · 참고 사이트)는 아직 안 가져온다.

---

## 로컬에서 새로 만들어야 하는 파일 (전부 gitignore됨 — git엔 없음)

| 파일 | 용도 | 비고 |
|---|---|---|
| `config.local.php` (루트) | 포털 SSO 토큰 암호화 키 | **자동 생성됨**(최초 실행 시) |
| `sso_secret.key` (루트) | book과 공유하는 SSO 서명 키 | **자동 생성됨**(최초 실행 시) |
| `slack/config.local.php` | Gmail IMAP 계정 정보 | **직접 생성 필요**, 아래 형식 |
| `book/config_local.py` | Slack 웹훅 URL(선택) | 없으면 알림 기능만 비활성 |
| `book/kakao_keys.json` | 카카오 도서검색 API 키(선택) | 없으면 검색 자동완성만 비활성 |
| `config.php` 의 `dti_slack_webhook` | dti 모듈 Slack 웹훅 URL(선택) | 없으면 발표자 등록 알림만 비활성 |
| `SVN_배포_디비정보(블루내부공유).xlsx` (루트) | access 모듈 초기 데이터 | **직접 가져다 둘 것.** 전 대학 계정/비번이 들어 있어 커밋 금지 |

`slack/config.php` 형식:
```php
<?php
/**
 * blue-iwork 포털 설정.
 *  - DB는 slack 모듈(slack/config.php)과 동일한 slackapi MySQL을 그대로 재사용                                                                                                     한다.
 *    (같은 물리 DB, 포털 전용 테이블만 추가로 생성)
 *  - 슬랙 토큰 암호화 키는 git에 올리지 않는 config.local.php 에 최초 실행 시 1                                                                                                     회 자동 생성.
 */

$local = __DIR__ . '/config.local.php';
if (!is_file($local)) {
    $key = base64_encode(random_bytes(32));
    file_put_contents($local, "<?php\nreturn [\n    'key' => '" . $key . "',\n];\n");
}
$localCfg = require $local;

return [
    // DB 접속 정보 (slack/config.php 와 동일한 slackapi DB)
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'user'    => 'root',
        'pass'    => '계정비밀번호',
        'name'    => 'slack_db',
        'charset' => 'utf8mb4',
    ],

    // 슬랙 토큰 암호화(AES-256-GCM) 키 — base64, 32바이트. config.local.php 최초 생성.
    'key' => $localCfg['key'],

    // slack 모듈(업무현황판)이 쓰는 Slack Lists 설정 — 원래 slack/config.php 에 있던 값
    // (slack/config.php 는 제거하고 여기 하나로 통합)
    'list_id'         => 'F083TU7F0BZ',
    'comment_channel' => 'C083TU7F0BZ',
    'list_url'        => 'https://coursemos.slack.com/lists/T04LNBX6L/F083TU7F0BZ',

    // dti(DTI 발표) 슬랙 알림 웹훅 — 선택. 없으면 발표자 등록 알림만 조용히 꺼진다.
    // (파이썬 magazine 의 config_local.py 에 있던 SLACK_WEBHOOK_URL 자리다)
    'dti_slack_webhook' => null,

    // 대시보드/공통 헤더 드롭다운이 참조하는 외부 모듈 링크. book은 별도 프로세스라 절대주소 필요.
    // 실제 배포 주소가 다르면 config.local.php 에 'book_url' => '...' 을 넣어 덮어쓸 수 있음.
    // dti·learn 은 포털과 같은 PHP 앱이라 여기 주소가 없다 — index.php가
    // slack·access처럼 상대경로(dti/index.php)로 직접 건다.
    'links' => [
        'book' => $localCfg['book_url'] ?? 'book',
    ],
];
```

`slack/config.local.php` 형식:
```php
<?php
return [
    'key' => 'p1WLBZerg/PAf8y7lae4DMSw0v1r8+LsQF3AjiV9Sas=',
];
```

---

## 환경변수 / 설정값 확인 필요

- **`PORTAL_URL`** (book 실행 시 환경변수) — book이 "로그인 안 됨" 상태에서 리다이렉트할 포털 주소.
  기본값 `http://localhost/` 플레이스홀더 그대로면 실제 배포에서 안 맞을 수 있음.
- **`index.php`의 `LINKS.book`** — 대시보드 타일이 여는 실제 주소. `config.php`의 `links`에서
  읽는다. 나머지 모듈은 같은 PHP 앱이라 `index.php` 가 상대경로로 직접 건다.
- **moodle/watch 환경변수** — 전부 선택. 서버는 systemd 유닛의 `Environment=` 로 주고, 로컬은
  `moodle/watch/.env`(`.env.example` 복사, git 제외)에 적어 두면 CLI 와 `--serve` 가 같이 읽는다.
  환경변수가 있으면 `.env` 보다 우선한다. `MOODLE_ORG_TOKEN`(없으면 PAG 코스 skipped), `ANTHROPIC_API_KEY`
  (없으면 `claude -p` 시도), `GITHUB_TOKEN`(선택), `SLACK_WEBHOOK_URL`(완료/실패 알림),
  `PORTAL_URL`(알림 링크용), `DATA_DIR`(기본 `moodle/watch/var`), `SUMMARIZER`(auto|anthropic|cli|none),
  `DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_NAME`(없으면 config.php 를 php 로 읽음).
- **PHP IMAP 확장** — Gmail 기능(`slack/gmail/`)에 필요. 이 서버(WAMP php8.2.28 등)엔 이미 켜져
  있는 것 확인함. 다른 서버로 옮기면 `extension=imap` 활성화 확인.
- **`slack/gmail/start_gmail_watch.bat`** — PHP 실행 경로가 `c:\wamp64\bin\php\php8.1.0\php.exe`로
  고정돼 있음. 다른 PHP 버전 쓰는 서버면 경로 수정 필요.

---

## DB

MySQL 하나(`slackapi`)를 portal/slack/gmail이 공유한다. 전부 최초 접속 시 테이블
자동 생성/마이그레이션(`db.php`의 `db()`/`portal_db()`). 주요 테이블:

- `portal_users` — 포털 계정(이메일/비번해시/암호화된 슬랙 토큰)
- `requests` — slack 유지보수 요청 목록(Slack Lists 동기화본)
- `schools`, `user_reads`, `user_pins`, `user_hides`, `local_assignments`, `sync_meta` — slack 부가기능
- `gmail_mails` — Gmail 캐시(계정별 구분, `account` 컬럼)
- `dti_topics`, `dti_presentations`, `dti_emotions`, `dti_fields`, `dti_related`,
  `dti_materials` — dti 모듈
- `school_access` — 대학별 접속·배포 정보(access 모듈). `schools` 가 마스터이고 여기는 상세라
  `school_id` 로 붙는다. 한 대학이 버전군별로 여러 행을 가질 수 있어(강원대 3.5 + 4.5)
  키는 `(school_id, grp)` 다.

컬럼 추가 마이그레이션은 전부 `add_column_if_missing()`(`slack/db.php`)을 거쳐 동시 요청에도
안전하게(이미 있으면 조용히 무시) 처리하도록 통일돼 있다. **새로 컬럼 추가 마이그레이션을 짤 때
"확인 후 ALTER" 패턴을 직접 쓰지 말 것** — 페이지 로드 시 여러 AJAX가 동시에 뜨면서 레이스가 난다.

---

## 배포 (systemd)

book 은 uvicorn 프로세스로 돈다. 유닛 파일은 서버에만 두고 저장소에는 올리지
않는다(실제 호스트명이 들어가기 때문). `/etc/systemd/system/book.service`:

```ini
[Unit]
Description=book (BlueBooks 도서구매신청)
After=network-online.target

[Service]
WorkingDirectory=/home/blueapp_core/book
ExecStart=/home/blueapp_core/book/venv/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8000
Restart=always
User=blueapp_core
Environment=PORTAL_URL=http://포털주소/

[Install]
WantedBy=multi-user.target
```

- `WorkingDirectory` 아래에 런타임 산출물이 생긴다. 실행 사용자(`blueapp_core`)에게 쓰기
  권한이 있어야 한다.
- SSO 서명 키는 `../sso_secret.key`(= `/home/blueapp_core/sso_secret.key`)를 본다. 포털이
  최초 실행 때 만든 그 파일이다.

### nginx

book 은 nginx 가 경로 접두사로 포트에 넘긴다(`/etc/nginx/sites-available/slack`).
새 FastAPI 모듈을 올리면 **화면 경로와 API 접두사 두 블록**을 함께 추가해야 한다. 빠지면 그 경로가
문서루트의 소스 폴더에 떨어져 403 이 난다.

```nginx
location /learning/    { proxy_pass http://127.0.0.1:8002/; }
location /learningapi/ { proxy_pass http://127.0.0.1:8002/learningapi/; }
```

(`proxy_set_header`·`client_max_body_size` 줄을 같이 둔다 — 업로드가 50MB 까지다.)
dti·learn·moodle 은 PHP 라 블록이 필요 없다. 대신 배치 소스가 문서루트 아래(`moodle/watch/`)에 있으니
정적으로 새지 않게 막아둔다:

```nginx
location ^~ /moodle/watch/ { deny all; }
```

### moodle-watch (systemd timer)

주 1회 배치라 service 는 `oneshot`, timer 가 월요일 06:00 KST 에 부른다.
`/etc/systemd/system/moodle-watch.service`:

```ini
[Unit]
Description=moodle-watch (MoodleUp? 주간 수집·요약)
After=network-online.target mysql.service

[Service]
Type=oneshot
WorkingDirectory=/home/blueapp_core/moodle/watch
ExecStart=/home/blueapp_core/moodle/watch/venv/bin/python run_weekly.py
User=blueapp_core
Environment=PORTAL_URL=http://포털주소/
Environment=MOODLE_ORG_TOKEN=...
Environment=ANTHROPIC_API_KEY=...
Environment=SLACK_WEBHOOK_URL=...
```

`/etc/systemd/system/moodle-watch.timer`:

```ini
[Unit]
Description=moodle-watch 주 1회

[Timer]
OnCalendar=Mon *-*-* 06:00:00 Asia/Seoul
Persistent=true
RandomizedDelaySec=10m

[Install]
WantedBy=timers.target
```

```sh
cd /home/blueapp_core/moodle/watch && python3 -m venv venv && venv/bin/pip install -r requirements.txt
sudo systemctl daemon-reload && sudo systemctl enable --now moodle-watch.timer
sudo systemctl start moodle-watch.service     # 첫 회는 손으로 한 번 돌려 화면에 주차가 뜨는지 본다
journalctl -u moodle-watch -n 50 --no-pager
```

- `var/` 는 WorkingDirectory 아래에 생긴다(state.json, 주차별 스냅샷, PAG 페이지 본문). 실행 사용자에게
  쓰기 권한이 있어야 한다. 지우면 다음 실행이 "첫 실행"으로 돌아가 페이지 변경 감지 기준을 다시 잡는다.
- `Persistent=true` 라 서버가 꺼져 있던 월요일은 켜진 뒤 바로 한 번 돈다. 마지막 실행 이후 구간을
  보되 최대 21일(`MAX_LOOKBACK_DAYS`)까지만 본다. timer 가 부르는 service 의 ExecStart 에는
  `--trigger timer` 를 붙여 이력에 '자동' 으로 남긴다:
  `ExecStart=/home/blueapp_core/moodle/watch/venv/bin/python run_weekly.py --trigger timer`
- 서버에 Claude Code 로 요약하려면(API 키 없이) 배치 계정으로 설치·로그인한 뒤
  `Environment=CLAUDE_CLI=/home/blueapp_core/.local/bin/claude` 를 service 에 넣는다. systemd 는
  `.bashrc` 의 PATH 를 모른다.

### 화면 '지금 다시 가져오기' (systemd path)

버튼이 남기는 요청 파일을 감시하는 유닛 두 개. `/etc/systemd/system/moodle-watch-refresh.path`:

```ini
[Unit]
Description=moodle-watch 갱신 요청 감시

[Path]
PathExistsGlob=/home/blueapp_core/moodle/watch/var/requests/*.json
Unit=moodle-watch-refresh.service

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/moodle-watch-refresh.service` 는 moodle-watch.service 를 복사해 ExecStart 만
바꾼다(Environment 줄은 그대로):

```ini
ExecStart=/home/blueapp_core/moodle/watch/venv/bin/python run_weekly.py --requests
```

요청 폴더는 php-fpm(www-data)이 쓰고 배치(blueapp_core)가 지운다. 둘이 같이 쓸 수 있게 만든다:

```sh
sudo install -d -o blueapp_core -g www-data -m 2775 /home/blueapp_core/moodle/watch/var/requests
sudo systemctl daemon-reload
sudo systemctl enable --now moodle-watch-refresh.path
```

### 문제 해결 체크리스트 (MoodleUp?)

| 증상 | 원인 | 조치 |
|---|---|---|
| `claude CLI(/root/.local/bin/claude) 를 찾을 수 없다` | 배치를 root 로 실행 | `sudo -iu blueapp_core` 로 전환해 실행. root 가 만든 파일은 `chown -R blueapp_core:blueapp_core moodle/watch` |
| 요약하기 눌러도 `journalctl -u moodle-watch-refresh -f` 에 아무것도 없음 | path 유닛 정지 또는 요청 폴더 권한 | `ls -la var/requests/` 로 `<week>.json` 생성 여부 확인 → 생기면 `sudo systemctl restart moodle-watch-refresh.path`, 안 생기면 폴더가 `blueapp_core:www-data 2775` 인지와 php-fpm 계정 확인 |
| `start-limit-hit` 로 refresh 서비스 반복 실패 | 요청 폴더에 처리 못 한 파일이 남아 glob 에 계속 걸림 | `sudo rm -f var/requests/*` → `systemctl reset-failed moodle-watch-refresh.service` → `systemctl restart moodle-watch-refresh.path` |
| 리포트가 `partial` 이고 노트에 `요약 없음` | claude 로그인 만료 또는 CLI 경로 | blueapp_core 로 `claude auth login`, `/etc/moodle-watch.env` 의 CLAUDE_CLI 확인. 다음 요약하기가 전체 요약을 다시 만든다 |
| moodle.org 책(book) 두 권 `HTTPError` | Cloudflare 가 pluginfile 차단 | 구조적 제약. 재시도 무의미, 코스에서 직접 읽는다 |

`PathExistsGlob` 은 파일이 남아 있는 동안 계속 service 를 부르므로, 처리 후 파일을 지우는 배치
쪽 동작이 곧 종료 조건이다. 처리중·실패 파일(`*.running`, `*.failed`)은 `.json` 으로 끝나지 않아 glob 에 걸리지 않는다.
요청 파일은 www-data 소유라 배치는 그 파일에 쓰지 않고 자기 소유의 처리중 파일을 새로 만든 뒤 원본을 지운다.
- moodle.org 토큰: 요약용 계정으로 코스 17257 자가등록 → `https://moodle.org/login/token.php`
  (service=moodle_mobile_app) 로 발급. 만료는 `https://moodle.org/user/managetoken.php` 에서 확인.
  만료되면 그 소스만 `failed` 로 슬랙에 뜬다.

---

## 알려진 제약 / TODO

- [ ] book이 포털과 다른 호스트에 있으면 SSO 쿠키가 전달되지 않음 — 같은 서버로 이전 필요.
- [ ] `PORTAL_URL`, `LINKS.book` 플레이스홀더를 실제 주소로 확정.
- [ ] 관리자(`ADMIN_EMAIL`)가 book `app.py`에 하드코딩 — 여러 명이 되면 배열/DB 플래그로 전환 고려.
      dti 는 `db.php` 의 `DTI_DEFAULT_ADMINS` 로 분리돼 있다.
- [ ] dti: 자료 순서를 바꿀 수 없다(등록 순 고정). 필요해지면 `dti_materials` 에 정렬 컬럼 추가.
- [ ] dti: 응답의 `material_*`·`scan_*` 파생 키는 화면이 `materials`·`scans` 배열만 보게
      정리되면 뺄 수 있다. 지금은 카드 정렬과 멤버 점수가 그 키에 걸려 있다.
- [ ] slack 모듈 관리자 기능(회원 추가/삭제, 비번 초기화) 없음.
- [ ] Gmail 연동은 계정 1개 고정(`config.local.php`) 기반 — 다계정 지원은 `gmail_lib.php` 주석의
      "[향후 회원가입]" 부분에 걸이 남아 있음.
- [ ] `.bak-migrate/`는 예전 구조 백업(사용 안 함, 삭제 검토 가능).
- [ ] moodle: PAG 코스 슬라이드(folder 8882) 텍스트 추출과 일반 개발자 포럼 키워드 필터는 아직 없다
      (기획 문서 6단계). BBB 녹화는 수집 대상 아님.
- [ ] moodle: 요약 품질은 첫 몇 주 실제 리포트를 보고 `summarizer.py::SYSTEM` 을 손봐야 한다.

---

## 로컬 개발 팁

- `.gitignore`가 `*.md`를 전부 막아놨다(이 `readme.md`만 예외로 풀어둠 — 다른 md 문서는 계속
  개인 작업용으로 git에 안 올라간다).
- PHP 파일 수정 후 `php -l 파일명`으로 문법 검사만이라도 하고 커밋할 것.
- 포털·slack은 `php -S 127.0.0.1:PORT`로 즉석 기동 가능(세션/DB만 붙어 있으면 됨).
  book은 `PORTAL_URL=http://127.0.0.1:PORT/ python -m uvicorn app:app --port 8098`.
- dti·learn 은 포털과 같은 앱이라 포털을 띄우면 같이 뜬다 — `php -S 127.0.0.1:PORT` 로 루트를
  서빙하고 `/dti/index.php` 로 들어가면 된다. 포털 로그인이 있어야 화면이 뜬다.
- 주석은 거의 달지 않는다. 이름으로 설명하고, 주석은 "코드를 잘못 고치는 걸 막는
  정보"(동시성·보안·외부 제약)일 때만 그 줄 옆에 남긴다.

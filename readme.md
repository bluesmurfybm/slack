# blue-iWorks — 사내 업무 포털

Bluesoft 사내 포털. 로그인 하나로 **BlueBooks(book, 도서구매신청)**, **BlueMagazine(dti, DTI 발표)**,
**BlueLearn(learn)**, **MoodleUp?(moodle)**, **OnWatch(onwatch, 수시 감시)**, **업무현황판(slack 연동)**, **Gmail 뷰어**를 오가는 구조. 이 문서는 이어받아 작업할
개발자를 위한 현황 정리다.

## 전체 구조

```
./                               ← 포털(PHP) — 이 저장소의 루트
├── index.php                    로그인/대시보드/프로필 (SPA 한 페이지)
├── core/                        모듈들이 공유하는 포털 공용 소스 (아래 참고)
│   ├── auth.php                 세션·SSO·current_portal_user()
│   ├── db.php                   portal_db() · 테이블 자동 생성
│   ├── board.php                알림판 — 공지·중요 일정·포털 관리자
│   ├── push.php                 웹 푸시 알림 — 암호화·발송·구독·종류별 설정 ('PWA · 웹 푸시 알림' 절)
│   ├── push_kinds.json          알림 종류 목록의 유일한 원본
│   ├── pwa.php                  pwa_head_tags() — 모든 모듈 <head> 에 넣는 PWA 태그
│   └── worksystems.php/.json    상단바 "업무 시스템" 목록의 렌더러 + 유일한 원본
├── sw.js, pwa.js                service worker(알림 수신·클릭) · 등록/구독 도우미 — 루트에 있어야 한다
├── manifest.webmanifest         PWA 설치 정보
├── config.php                   접속 정보 (gitignore, 루트에 그대로 둔다)
├── sso_secret.key               book 과 나눠 쓰는 HMAC 키 (자동 생성, 루트)
├── vapid.key                    웹 푸시 서명 키쌍 (자동 생성, 루트, gitignore)
├── .sessions/                   PHP 세션 저장 경로 (루트)
├── api/                         login.php, logout.php, me.php
│                                notices.php, notice_file.php, events.php, event_words.php, admins.php, push.php, notifications.php
│                                my_systems.php, tile_groups.php
├── styles/                      default.css, board.css, wxfx.css, favicon.ico, logo-blue.png
│                                icons/(카드 아이콘) · favicons/(시스템별 탭 파비콘, gen_favicons.py)
├── dev/                         gen_tiles.py(날씨 타일 생성기) · cheer_test.php · kind_test.php(일정 분류 시험)
├── var/                         공지 첨부 원본 (.htaccess 로 직접 접근 차단, notice/ 는 gitignore)
│
├── book/                        BlueBooks(도서구매신청) — PHP, 포털 세션·DB 공유. book/README.md
│   ├── index.php                화면 한 장(SPA) — 상단바는 PHP 가, 목록·폼은 static/*.js 가 그린다
│   ├── api.php                  JSON API 프런트 컨트롤러 (?p=/requests/3 로 라우팅)
│   ├── bootstrap.php, db.php, guard.php   require 목록 · book_requests 표/시드/설정 · 포털 세션 신원
│   ├── lib/                     http(응답·검증·라우팅) · requests(읽기/쓰기·권한) · lookup(교보문고·카카오) · notify(슬랙)
│   ├── routes/                  identity · requests · books
│   ├── static/, styles/         core(상태·api·유틸) · list · form · main / dti 토큰을 따른 style.css
│   ├── tools/migrate_from_sqlite.php   book.db(FastAPI/SQLite) → book_requests 이관 (CLI, 일회성)
│   └── seed.json                표가 비어 있을 때 넣는 초기 127건
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
├── onwatch/                     OnWatch(수시 감시) — PHP 화면 + Python 배치(1분 timer). onwatch/README.md
│   ├── index.php, view.php, edit.php   목록 · 상세 · 만들기/고치기/복제
│   ├── api/, inc/, sql/, bin/   JSON API · 공용 로직 · 스키마(화면이 자동 적용) · CLI(migrate, secret)
│   └── worker/                  run.py → 조사(Claude+웹 검색) → 판정(JSON) → 중복 거르기 → 알림
│
├── access/                      Coursemos EnvHub — PHP, slack의 schools를 마스터로 씀
│   ├── access.php               목록 + 상세 + 편집 (복사 버튼)
│   ├── access_api.php           JSON API
│   ├── access_import.php        접속정보 엑셀 → DB (CLI / 화면 업로드 겸용)
│   ├── db.php, guard.php        전용 DB 연결 · 포털 로그인 가드
│   └── styles/access.css
│
├── envlog/                      Coursemos EnvLog — 대학 운영 웹서버 아파치 로그 뷰어. envlog/README.md
│   ├── index.php, api.php       화면 · JSON API(대학 목록은 DB 에서 직접, 로그 조회는 데몬에 중계)
│   ├── guard.php, static/, styles/
│   └── daemon/                  ★ 상주 SSH 로그 엔진(Python, 127.0.0.1:8766) — envlogd.py · ssh_jump.py · envlog_remote.sh
│
├── sitehub/                     BlueLab SiteHub — PHP, 포털 세션·DB 공유. 유지보수 사이트 정보(사이트 기준). sitehub/README.md
│   ├── index.php                화면 한 장 — 요약 · 툴바 · 목록(리스트/카드) · 상세 드로어 · 시트
│   ├── api.php                  JSON API (access_api.php 와 같은 모양)
│   ├── import_xlsx.php          호스팅 정보_v.xlsx → 사이트·접속정보·담당자·계약 시딩 (CLI / 화면 업로드)
│   ├── sync.php, list_cols.php  Slack 리스트 BlueLab-유지보수처리목록 → lab_history 동기화 · 컬럼 매핑(사람이 채움)
│   ├── db.php, guard.php        lab_* 테이블 · enc:v1 암호화 · 칸 정의 · 포털 로그인 가드 · 동기화 토큰 선택
│   └── views/, static/, styles/ dti 와 같은 상단바·헤더 / 클래식 스크립트 / dti style.css 복사본
│
├── learn/                       BlueLearn(강의 수강료 지원) — PHP, 포털 세션·DB 공유
│   ├── index.php                화면 한 장(SPA)
│   ├── api.php                  JSON API 프런트 컨트롤러 (?p=/requests/3 로 라우팅)
│   ├── db.php, guard.php        learn_* 테이블 · 시드 · 포털 로그인 가드
│   ├── lib/                     http(응답·검증) · status(요청상태 파생·전이) ·
│   │                            policy(환급 정책) · storage(이수증 파일)
│   ├── routes/                  identity · requests · certs · review · sites ·
│   │                            categories · policy · admins · catalog(추천·필수 강의)
│   ├── tools/                   migrate_from_learning.php (SQLite → MySQL 이관)
│   ├── static/, styles/         도메인별 js · css
│   └── var/uploads/             이수증 원본 (gitignore, .htaccess 로 직접 접근 차단)
│
├── slackai/                     WorkHub AI(테스트베드) — slack/ 복제 + AI 레이어. DB 는 slackai_db 로 분리. slackai/README.md
│   ├── ai/                      ai_lib.php(큐·이벤트·설정 헬퍼) · ai_state.php/ai_action.php(패널 API) · panel.php/js · jobs/lessons/settings 관리
│   ├── tags/, repos/            태그 CRUD · 고객사→작업사본 매핑
│   ├── tools/                   clone_db.php · comments_cli.php · post_cli.php (워커 전용 PHP CLI)
│   ├── similar/                 similar_lib.php(TF-IDF, 웹 API 와 CLI 공유) · similar_cli.php
│   └── worker/                  Python 워커: ai_jobs 폴링, Slack Socket Mode 수신, claude/codex/svn/git 실행. .env 는 gitignore
│
├── dti/                         BlueMagazine(DTI 발표) — PHP, 포털 세션·DB 공유
│   ├── index.php                구성원 화면(아티클 목록)
│   ├── admin/                   관리자 탭마다 한 장 — index(아티클 관리)·archive·rounds·fields·stats·score
│   ├── views/                   레이아웃 조각 — head·tabs·foot, 목록 3장이 쓰는 list·topic_modals
│   ├── api.php                  프런트 컨트롤러 — 출력하는 유일한 자리
│   ├── bootstrap.php            require 목록 + 시간대
│   ├── db.php                   설정 · 연결 · 스키마 · 시드
│   ├── guard.php                포털 세션 신원 · 관리자 판정
│   ├── lib/
│   │   ├── http.php             DtiError · 요청 파싱 · 응답 · 입력 검증 · 라우팅
│   │   ├── topics.php           아티클 읽기/쓰기 · 상태 판정 · 화면용 배열
│   │   ├── presentations.php    발표 행 생성 · 삭제 · 배정 해제
│   │   ├── rounds.php           회차 · 호 담기 · 일괄 보관/숨김
│   │   ├── pages.php            페이지 표(DTI_PAGES) · 스크립트 목록 · 접근 판정
│   │   ├── emotions.php, fields.php, members.php
│   │   ├── related.php, score.php     연관 점수 · 멤버 점수
│   │   ├── slots.php, storage.php, notify.php
│   ├── routes/                  topics · materials · fields · rounds · scores · identity
│   ├── static/, styles/         magazine/web/ 이식 (core.js 에 경로 변환만 추가)
│   ├── tools/                   rebuild_related.php · backfill_materials.php(일회성)
│   ├── tests/                   PHPUnit 214건
│   └── var/uploads/             발표자료 원본 (gitignore, .htaccess 로 직접 접근 차단)
│
├── bluecart/                    BlueCart(물품 요청) — bluecart/README.md
├── blueledger/                  BlueExpense(출장여비) — blueledger/README.md
├── studio/                      BlueStudio(개발 과업 편성) — studio/README.md
├── chatbot_api/, blui_api/      포털 챗봇 서버 두 벌(FastAPI) — nginx 가 /chatapi(8003)·/bluiapi(8004) 로 넘긴다
│
└── slack/                       업무현황판 — PHP, 포털과 같은 Apache/세션 공유
    ├── auth.php, db.php, slack_lib.php, header.php   (공통. 설정은 루트 config.php 를 읽는다)
    ├── lists.php, comments.php, data.php, assign.php, ...        (핵심 요청 목록 기능)
    ├── difficulty/   난이도 분석
    ├── category/     카테고리 분석
    ├── similar/      유사 요청 찾기
    ├── schools/      대학 사이트 관리
    ├── gmail/        Gmail 뷰어/동기화(IMAP)
    └── styles/       페이지별 css + header.css(공통 상단바)
```

**모듈 전부가 포털과 같은 PHP 앱**이다 — slack/·book/ 등은 물리적으로 하위 폴더일 뿐, 세션도
같은 걸 공유한다. (book 은 2026-10 까지 FastAPI 별도 프로세스(포트 8000)였고, 지금은 PHP 로 들어왔다.
파이썬 배치(moodle/watch, onwatch/worker, envlog/daemon, slackai/worker)는 화면이 아니라 뒤에서 도는 일꾼이다.)

---

## 시간대

`core/auth.php` 가 맨 앞에서 `date_default_timezone_set('Asia/Seoul')` 을 부르고,
`core/db.php` 가 접속 직후 `SET time_zone = '+09:00'` 을 건다. **양쪽을 다 못 박아야
한다.**

php.ini 에 `date.timezone` 이 없으면 PHP 는 UTC 로 돌고 MySQL 은 서버 시간대로 돈다.
그러면 **자정부터 아홉 시간 동안 두 쪽의 '오늘' 이 하루 어긋난다.**

- D-day 가 하루씩 틀린다(`board_decorate_event` 는 PHP 의 오늘을 본다)
- 공지 노출 기간이 맞지 않는다(`NOTICE_LIVE_WHERE` 는 MySQL 의 `CURDATE()` 를 본다)
- 목록의 딱지와 실제로 보이는 것이 서로 다르다

`learn`·`dti`·`moodle` 은 각자 시간대를 정하고 있었는데 포털 `core/` 만 빠져 있었다.
자정 무렵에만 드러나는 탈이라 시험(`[0] 시간대`)으로 붙잡아 둔다.

---

## core/ — 공용 소스의 자리

모듈이 늘면서 포털 루트에 공용 소스와 설정·산출물이 뒤섞였다. 여러 모듈이 함께
쓰는 **소스만** `core/` 로 내렸다.

| core/ 에 있는 것 | 쓰는 곳 |
|---|---|
| `auth.php` | api, access, book, dti, learn, moodle, slack, bluecart, sitehub |
| `db.php` | access, book, dti, learn, moodle, sitehub (`add_column_if_missing()` 재사용) |
| `worksystems.php` / `.json` | index, dti, learn, moodle, slack/header, bluecart, book, sitehub |
| `board.php` | api/notices·notice_file·events·admins |
| `push.php` / `push_kinds.json` | index, api/me·push·notifications, dti, onwatch, dev/push_*, slackai·onwatch 워커(dev/push_cli.php 경유) |
| `pwa.php` | 모든 모듈의 `<head>` |

모듈에서는 한 단계 위의 `core/` 를 부른다.

```php
require_once __DIR__ . '/../core/auth.php';        // 모듈 폴더에서
require_once __DIR__ . '/core/worksystems.php';    // 포털 루트(index.php)에서
```

**루트에 그대로 두는 것들** — `core/` 안에서는 `dirname(__DIR__)` 으로 짚는다.

- `config.php` — 서버마다 사람이 직접 만드는 파일이다. 자리를 옮기면 이미 돌고 있는
  설치본을 전부 손봐야 한다. `slack`, `learn`, `dti` 도 루트 기준으로 읽고 있다.
- `sso_secret.key` — book 이 파이썬이던 때 `../sso_secret.key` 로 직접 읽던 파일이다. 지금 읽는
  모듈은 없지만 `auth.php` 가 계속 만들고 서명 쿠키도 계속 심는다 — 다른 프로세스 모듈이 다시
  생기면 그대로 쓰면 된다.
- `.sessions/` — 옮기면 열려 있던 세션을 못 찾아 전원이 로그아웃된다.
- `var/` — 공지 첨부 원본. 직접 접근을 막는 `.htaccess` 가 여기 있다.

---

## PWA · 웹 푸시 알림

포털을 PWA 로 설치할 수 있게 하고(홈 화면·작업 표시줄 아이콘), 모듈들이 **웹 푸시 알림**을
보낼 수 있는 공용 뼈대를 `core/push.php` 에 둔다. 알림을 내는 곳은 모듈마다 다르지만
암호화·전송·구독 관리·종류별 켜고 끄기는 포털이 한 번만 갖는다.

### 모듈에서 알림 보내기

1. `core/push_kinds.json` 에 종류를 한 줄 더한다. `sys` 는 `worksystems.json` 의 key(또는 `portal`)이고,
   마이페이지가 그 시스템 이름 아래에 묶어 보여 준다. `default` 는 사람이 고른 적 없을 때의 켜짐 여부다.
2. 알릴 자리에서 부른다.

```php
require_once __DIR__ . '/../core/push.php';
push_send([
    'kind'  => 'dti.presenter',
    'title' => 'DTI 발표자 등록',
    'body'  => '아티클 제목 — 홍길동 (2026-09-01)',
    'url'   => 'dti/index.php',          // 알림을 누르면 열 주소. 포털 루트 기준 또는 https:// 절대주소. 비면 포털 첫 화면
    'tag'   => 'dti-topic-12',           // 같은 tag 는 기기에서 하나로 겹쳐진다(선택)
    'to'    => ['a@bluesoft.co.kr'],     // 생략하면 이 종류를 켜 둔 전원
]);
```

- **`push_send()` 는 예외를 던지지 않는다.** 결과는 `{sent, failed, pruned, skipped, recipients, detail}` 로
  돌려주고 `portal_push_log` 에 남긴다. 본 작업(발표자 등록 등)이 알림 때문에 실패하지 않게 하려는 약속이다.
- 시험에서는 dti 처럼 `$ctx['push']` 로 기록용 함수를 끼워 넣는다(`dti/tests/Support/TestCase.php`).
  dti 의 실제 예는 `dti/lib/notify.php` 의 `dti_push_new_presenter()`.
- Python(slackai 워커)은 `tools/php_cli.push()` 또는 `notify.push(ctx, ...)` 를 쓴다. 안에서
  `php dev/push_cli.php --json-file …` 를 부른다(한글 인자가 Windows 코드페이지에서 깨지지 않게 파일로 넘긴다).

### 받는 쪽

- 마이페이지 **알림** 칸에서 "이 기기에서 알림 받기" 를 누르면 브라우저가 권한을 묻고 구독을 서버에
  남긴다. 기기(브라우저)마다 한 번씩 눌러야 한다. 종류별 체크는 누르는 즉시 저장된다 —
  다른 칸처럼 '수정/저장' 을 거치지 않는다. 권한 창이 뜨는 일은 '취소' 로 되돌릴 수 없기 때문이다.
- 알림을 누르면 `url` 이 열린다. 상대경로는 sw.js 가 자기 scope(포털 루트)로 풀고, 같은 주소의 탭이
  열려 있으면 그 탭을 앞으로 가져온다. 동시에 알림함에서 그 알림을 읽음으로 바꾼다.
- 마이페이지 알림 칸에 **알림 받는 기기 목록**이 있다. 기기마다 '삭제' 로 끊을 수 있다(잃어버린 휴대폰 등).
  지금 보고 있는 기기는 "이 기기" 로 표시되고, 이 기기를 지우면 브라우저 구독까지 끊는다.
- **알림함**(상단바 배경 단추 옆 🔔 단추): 받은 알림을 모아 본다. 안 읽은 수가 종 단추 오른쪽 위에 숫자로 보인다.
  누르면 읽음으로 바뀌고 그 주소로 간다. 모두 읽음·읽은 알림 지우기·하나씩 지우기가 된다.
  알림함은 **사람 기준**이라 기기가 없거나 발송이 실패해도 남는다. 180일이 지나면 지운다.
- **iOS 는 공유 → '홈 화면에 추가' 한 뒤 그 아이콘으로 연 화면에서만** 알림을 받을 수 있다(16.4+).
- https 또는 localhost 에서만 동작한다. 그 밖의 주소에서는 알림 칸이 "받을 수 없음" 으로 보인다.

### 카드 배지 (업무 시스템 카드의 안 읽은 알림 수)

대시보드 카드 아이콘 모서리에 **그 시스템에서 온 안 읽은 알림 수**를 붙인다. 원본은 알림함
(`portal_notification`) 하나라 상단바 종 숫자와 늘 맞는다. OnWatch 에 먼저 붙였다.

- **켜기**: `core/worksystems.json` 의 그 카드 줄에 `"badge": "inbox"` 를 더한다. 모듈은 자기 sys 의 종류로
  `push_send()` 만 하고 있으면 된다 — 종류 → 시스템은 `push_kinds.json` 의 `sys` 로 가른다.
- **세는 곳**: `push_tile_badges($userId, work_systems())`(core/push.php). 첫 화면은 서버가 그려 내리고
  (`TILE_BADGES`), 알림함 숫자가 바뀌는 응답(`api/notifications.php` 의 `tiles`)마다 `paintTileBadges()` 가 갈아 끼운다.
  다른 탭에서 돌아오면(visibilitychange) 다시 센다.
- **모듈 화면에서 읽음 처리**: 알림을 누르지 않고 모듈로 바로 들어가 봐도 배지가 남지 않게,
  모듈이 그 건을 열 때 `push_inbox_mark_read_url($uid, 'onwatch.', 'onwatch/view.php?id=3')` 를 부른다
  (주소가 같거나 뒤에 `&`·`#` 가 붙은 것만 — `id=3` 이 `id=31` 에 걸리지 않는다). 읽음 처리 전에
  `push_inbox_oldest_unread_url()` 로 시각을 받아 두면 그 뒤 항목에 NEW 를 붙일 수 있다.
  모듈 목록의 건별 숫자는 `push_inbox_unread_urls($uid, 'onwatch.')`.
- 다른 기준(처리 대기 건수 등)이 필요해지면 `badge` 에 새 값을 정의하고 `push_tile_badges()` 에 갈래를 더한다.

### 모듈 상단바 종 (그 모듈의 안 읽은 알림)

모듈 화면 상단바의 사용자 메뉴 왼쪽에 **그 모듈에서 온 안 읽은 알림 수**를 종으로 보인다. 마우스를 올리거나
누르면 최근 6건(제목·본문 앞부분·시각)이 펼쳐지고 누르면 그 건으로 간다. 세는 규칙(`push_kinds.json` 의 `sys`)이
카드 배지·사용자 메뉴의 시스템별 숫자와 같아 세 곳 숫자가 늘 맞는다.

- **그리는 곳**: `work_systems_bell($base, $key)`(core/worksystems.php). 상단바 `.top-right` 에서
  `.user-menu` 앞에 넣는다. 모양은 `styles/topbar.css` 의 `.tb-bell`.
- studio(`bs_layout_bell`)·sitehub(`sitehub_notice_bell`)는 먼저 만든 자기 종을 그대로 쓴다 — sitehub 는 드로어를
  열면 종에서 그 건을 지우는 동작이 붙어 있다.
- 알림을 아직 보내지 않는 모듈(WorkHub·EnvLog)은 종이 비어 "새 소식이 없습니다" 로 보인다.

### 앱 설치 안내

브라우저는 설치할 수 있는 사이트에도 팝업을 띄우지 않는다(주소창 아이콘뿐). 그래서 `pwa.js` 가
직접 안내 띠를 왼쪽 아래에 띄운다. pwa.js 를 싣는 모든 페이지에서 같이 뜬다.

- Chrome·Edge·Android: 브라우저가 설치 가능하다고 알려 오면(`beforeinstallprompt`) "설치 / 다음에" 띠를 띄우고,
  "설치" 를 누르면 브라우저 설치 창이 열린다.
- iPhone·iPad: 그런 신호가 없어 "공유 → 홈 화면에 추가" 방법을 글로 안내한다. 홈 화면에 추가해야 알림을 받을 수 있다.
- "다음에" 를 누르면 그 브라우저에서는 14일 동안 저절로 뜨지 않는다. 포털 사용자 메뉴의 "📲 앱으로 설치" 로는 언제든 다시 부를 수 있다.
- 이미 설치한 앱으로 열었으면 띄우지 않는다. https·localhost 가 아니면 설치 대상이 아니라 뜨지 않는다.

### 시험 도구

알림은 포털 사용자 전원에게 열려 있다. 받을지 말지는 사람마다 마이페이지에서 종류별로 정한다.

- `dev/push_test.php` — 브라우저에서 종류·제목·본문·이동 주소를 넣고 보내 본다. 로그인한 사람은 누구나 열 수 있다.
  - 받는 사람은 처음에 본인이 골라져 있다. 포털 관리자는 여러 사람을 골라 보낼 수 있고,
    그 밖의 사람은 본인에게만 보낸다. 그 종류를 꺼 둔 사람은 빠진다.
  - 결과(푸시 서비스 HTTP 응답)와 발송 기록을 보여 준다. 다른 사람의 구독 현황과 전체 기록은 관리자에게만 보인다.
- `php dev/push_cli.php --kind test.ping --title 시험 --body 본문 --url dti/index.php --to akddd@bluesoft.co.kr`
- `php dev/push_crypto_test.php` — 서명(ES256)·암호화(RFC 8291) 왕복과 `push_send()` 의 뒷정리 규칙. DB·네트워크를 쓰지 않는다.

### 구성 요소

| 무엇 | 어디 | 메모 |
|---|---|---|
| 서명 키쌍 | 루트 `vapid.key` | 첫 사용 때 자동 생성. **바꾸면 모든 구독이 403 으로 끊긴다** — 서버를 옮길 때 같이 옮긴다 |
| 구독 | `portal_push_subscription` | 기기마다 한 줄. 404/410 이면 바로 지우고, 5xx·타임아웃이 연달아 5번이면 지운다 |
| 종류별 설정 | `portal_push_pref` | 고른 것만 줄로 남는다. 없으면 json 의 default |
| 발송 기록 | `portal_push_log` | 기기·시도마다 한 줄. 이동 주소·HTTP 응답·오류(운영용) |
| 알림함 | `portal_notification` | 사람마다 한 줄. 읽음 여부. `api/notifications.php` |
| 머리 태그 | `core/pwa.php` `pwa_head_tags($포털루트까지)` | 포털 `''`, 모듈 `'../'`, 하위 폴더 `'../../'`, dti `$page['base'].'../'` |
| 아이콘 | `styles/icon-*.png`, `apple-touch-icon.png` | `python dev/gen_icons.py` — favicon 과 같은 심볼 |

왜 composer 라이브러리(minishlink/web-push)를 쓰지 않았나 — 운영 서버에는 `vendor/` 를 깔지 않는다.
필요한 것(P-256 서명·ECDH·HKDF·AES-128-GCM)은 PHP openssl 확장에 다 있다. Windows PHP 는
`openssl.cnf` 를 못 찾으면 EC 키 생성이 실패하므로 그때만 `core/push_openssl.cnf`(빈 설정)를 넘긴다.

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
- **슬랙 계정 이메일**(`portal_users.slack_email`): 슬랙 **개인 DM** 을 보낼 때 받는 사람을
  찾는 주소다. 포털 로그인 이메일은 전원 회사 메일(`@bluesoft.co.kr`)인데 **슬랙 계정은
  협업사 도메인을 쓰는 사람이 있어**, 회사 메일로는 슬랙 사용자를 찾지 못하고 알림이
  조용히 빠졌다. 마이페이지에서 **본인이** 적는다.
  - **다른 사람만 적는다.** `NULL` 이면 "로그인 이메일과 같다"는 뜻이다. 로그인 이메일과
    같은 값을 보내면 `api/me.php` 가 `NULL` 로 정규화한다(대소문자 무시) — 같은 사실이
    두 군데 있으면 한쪽만 바뀌었을 때 어느 쪽이 맞는지 알 수 없다.
  - **바로 위 슬랙 토큰과 규칙이 반대다.** 토큰은 민감정보라 저장 후 다시 보여 주지
    않으므로 빈칸이 "그대로 두기"지만, 이 칸은 화면에 원문이 그대로 보이므로 빈칸은
    "지우기"다. 안내 문구도 그렇게 갈라 놓았다.
  - 쓰는 쪽은 지금 bluecart 하나다(`iworks.member.col_slack_email`). 다른 모듈이 슬랙
    DM 을 보낼 일이 생기면 같은 컬럼을 보면 된다.
  - 누가 아직 안 적었는지, 적은 주소가 슬랙에서 실제로 잡히는지는
    `bluecart/cron/slack_check.php` 가 사람별로 찍어 준다.
- **미로그인으로 튕길 때는 이유를 알린다**: 화면(뷰) 라우트는 미로그인 사용자를 포털로 되돌릴 때
  `?need_login=<key>`를 붙인다(key는 `worksystems.json`의 key). 포털은 `need_login_notice()`로
  이름까지 붙인 문구를 만들어 로그인 화면에 토스트로 띄우고, URL에서 그 플래그만 지운다
  (`pathname`으로 싹 지우면 `need_token`·`view=profile`까지 날아간다). 붙이는 곳은
  slack `auth.php`, dti·moodle `index.php`, moodle `bookmarks.php`, learn·access·book `guard.php`
  — 일곱 곳이다. **API는 다르다** — 401을 그대로 응답하고, 화면 JS가 그 401을 받아
  포털로 보낸다(book·dti·learn 의 `static/core.js::api()`).
- **SSO 서명 쿠키(`blueiwork_id`)**: 포털이 로그인 시 이메일+이름을 HMAC-SHA256 으로 서명해
  심는다(`auth.php::issue_sso_cookie()`, 키는 `sso_secret.key`). book 이 파이썬 별도 프로세스이던
  때 그쪽이 서명만 검증해 신원을 얻던 장치다. **지금은 검증하는 모듈이 없다** — book 도 PHP 로
  들어와 세션을 그대로 쓴다. 다른 언어/프로세스 모듈이 다시 생기면 같은 호스트(포트만 달라도
  됨)에 두고 이 쿠키를 검증하면 된다.
- **공통 상단바**: `slack/header.php`(PHP include)를 쓰는 slack 과, 각자 head 에 같은 마크업을
  그리는 book·dti·learn 이 시각적으로 동일한 blue-iWorks 상단바(로고+사용자명+로그아웃)를 그린다.
  slack 하위 폴더 페이지는 include 전에 `$__bwBase = '../';`를 반드시 설정해야 링크가 안
  깨진다(폴더 깊이 보정용).
- **상단바 드롭다운의 "업무 시스템" 목록**: 원본은 `worksystems.json` 하나다(key·이모지·라벨 +
  포털 루트 기준 경로). 시스템이 늘거나 이름이 바뀌면 여기만 고치면 전 모듈 상단바와 포털
  대시보드 타일이 같이 바뀐다.
  - 전 모듈(포털·slack·access·moodle·dti·learn·book)이 `worksystems.php::work_systems_menu($base, $current)`
    로 서버에서 그린다. `$base`는 그 페이지에서 **포털 루트까지 되짚는 접두사**(포털 `''`,
    book/dti/learn/moodle/slack `'../'`, slack 하위 폴더 `'../../'`), `$current`는 현재 시스템 key —
    그 줄이 `.on`(`styles/topbar.css`)으로 표시된다. `slack/header.php`를 쓰는 페이지는
    `$__bwCurrent`만 넘기면 된다.
  - `api/my_systems.php` 는 PHP 를 못 쓰는 모듈(다른 프로세스)이 같은 메뉴를 그리려고 부르던
    API 다. book 이 PHP 로 들어온 뒤로 부르는 곳은 없지만 그런 모듈이 다시 생기면 쓴다.
- **카드 아이콘과 탭 파비콘**: 시스템마다 포털 카드의 색 배지(서비스 색 둥근 사각 + 흰 아이콘)를
  그대로 줄여 탭 파비콘으로 쓴다. 시스템을 더하면 다음을 함께 한다.
  1. `styles/icons/<key>.svg` 에 카드 아이콘을 둔다(24 격자, `stroke="currentColor"`). 포털 `ICONS` 는
     `worksystems.php::work_system_icons()` 로 이 폴더를 읽는다.
  2. `python dev/gen_favicons.py` 로 `styles/favicons/<key>.svg` 를 만들어 커밋한다(색은 `worksystems.json`).
  3. 그 시스템의 모든 페이지 `<head>` 에 `<link rel="icon" type="image/svg+xml" href="<포털 루트>styles/favicons/<key>.svg">`
     를 건다. book·learn 은 `../styles/favicons/<key>.svg`, dti 는 `dti_asset($page, '../styles/favicons/dti.svg')`.
  포털 루트(blue-iWorks)만 `styles/favicon.ico`(세 줄)를 그대로 쓴다.

---

## 알림판 (주요 공지 · 중요 일정)

로그인하면 첫 화면 타일 위에 두 칸이 뜬다. 왼쪽이 **주요 공지**, 오른쪽이
**중요 일정(D-day)** 이다. 둘 다 포털 본체 기능이라 모듈 폴더가 따로 없고
`core/board.php` 하나가 도메인 계층을 전부 들고 있다.

### 누가 등록할 수 있나

`portal_admin` 테이블이 명단이고, 화면(포털 관리 → 관리자)에서 늘리고 줄인다.
**`core/board.php` 의 `OWNER_ADMINS` 는 코드에 고정**이라 DB 가 비거나 잘못 저장돼도
관리자 없는 상태로 잠기지 않는다. learn 의 `OWNER_EMAILS` 와 같은 생각이다.

```php
const OWNER_ADMINS = ['kimhy@bluesoft.co.kr'];   // 화면에서 뺄 수 없다
const SEED_ADMINS  = ['kimhy@bluesoft.co.kr'];   // 최초 1회만 심는다
```

명단을 옮기려면 `OWNER_ADMINS` 를 고치고 배포한다. 그 외 인원은 화면에서 바꾼다.
포털 계정(`portal_users`)에 없는 이메일은 추가되지 않는다 — 오타로 아무 주소나
들어가면 명단이 지저분해지기 때문.

**화면에서 버튼을 감추는 것은 거들 뿐이고, 막는 쪽은 언제나 서버다.**
`api/*.php` 가 매번 `board_require_admin()` 을 다시 부른다.

### 공지

- 본문은 **일반 텍스트**다. 화면에서 이스케이프한 뒤 줄바꿈만 살리고 주소만
  링크로 바꾼다(`linkify`). 이스케이프를 먼저 하고 링크를 나중에 만든다 —
  순서를 뒤집으면 만들어 둔 `<a>` 까지 이스케이프돼 글자로 보인다.
- **맨 위 고정은 없다.** 목록은 언제나 최신순이다. `is_important` 를 켜면 제목 앞에
  **별(★)** 이 붙고 제목이 굵어질 뿐 순서는 그대로다 — 고정을 쓰기 시작하면 중요한
  글이 계속 쌓여 맨 위가 굳어 버린다.
  경고 삼각형은 "문제가 생겼다" 로 읽혀서 공지에는 별이 맞다. 별 하나로는 훑을 때
  잘 안 걸려 제목도 같이 굵게 한다.
- 올린 지 사흘이 안 지났으면 NEW.
- 첨부는 공지 하나에 10개, 파일당 20MB. 확장자 화이트리스트 밖은 아예 받지 않는다.
  **HTML/SVG 를 받으면 같은 오리진에서 열려 포털 세션을 노린 XSS 가 된다.**
  첨부가 있으면 목록에 클립 아이콘과 개수가 붙는다(`clipTag`).

### 공지 노출 기간

`starts_on` · `ends_on` 두 날짜로 정한다. **둘 다 비우면 올린 즉시부터 내릴 때까지**
계속 보인다 — 기간을 안 쓰던 때와 똑같이 동작한다.

```sql
-- 구성원에게 보이는 조건 (NOTICE_LIVE_WHERE)
(starts_on IS NULL OR starts_on <= CURDATE())
AND (ends_on IS NULL OR ends_on >= CURDATE())
```

- 구성원 화면(대시보드·목록)에는 노출 중인 것만 나온다.
- **관리 화면(`?scope=manage`)에서만** 예약분과 종료분까지 보이고, `공개 예정` ·
  `내림` 딱지가 붙는다. 이 scope 는 서버가 `board_require_admin()` 으로 막는다.
- 기간이 지나도 글은 지워지지 않는다. 목록에서 내려갈 뿐이라 언제든 되살릴 수 있다.

게시판마다 흔한 "상단 노출 N일" 같은 규칙 대신 날짜 두 개로 둔 이유는, 워크숍
안내처럼 **행사가 끝나면 자동으로 내려가야 하는 공지**가 실제로 많아서다.
등록할 때 안 적으면 예전과 똑같으니 부담도 없다.

### 날씨 위젯과 대시보드 배경

알림판 오른쪽에 **정사각 날씨 위젯**이 붙는다. 제목 줄 없이 그림과 숫자만 둬서
'칸' 이 아니라 '위젯' 으로 읽히게 했다.

**자료는 브라우저가 [Open-Meteo](https://open-meteo.com) 에서 직접 받는다.**
열쇠가 없고 CORS 가 열려 있어 서버를 거치지 않아도 된다 — **사내망이 밖으로
못 나가도 각자의 브라우저에서는 뜬다.** 실패하면 칸만 조용히 비우고 화면은 그대로다.

사무실 좌표는 `core/board.php` 의 `OFFICE_WEATHER` 다. `config.php` 에
`'weather' => ['lat'=>…, 'lon'=>…, 'label'=>'…']` 를 넣으면 그쪽이 이긴다.

```php
// 충북 청주시 청원구 내덕동
const OFFICE_WEATHER = ['lat' => 36.6553, 'lon' => 127.4890, 'label' => '청주'];
```

위젯 크기는 `--wx-size: calc(var(--win-h) + 65px)` 로 옆 칸 높이를 따라간다.
`--win-h` 를 바꾸면 날씨 칸도 같이 따라오므로 픽셀을 다시 재지 않아도 된다.

**개인 설정** — 상단바 프로필 옆 단추로 `날씨에 따라` ↔ `기본` 을 고른다.
값은 `portal_users.bg_pref` 에 남아 **다시 로그인해도 유지된다.** 화면이 한 번
번쩍이지 않게 서버가 `<body data-bg>` 로 먼저 내려 준다.

```
PUT api/me.php   {"bg_pref":"weather"|"plain"}
```

---

### 날씨 배경 — 하늘과 움직임

WMO 날씨 코드를 아홉 갈래(맑음·구름조금·흐림·안개·이슬비·비·눈·뇌우·밤)로 묶어
`body[data-wx]` 에 싣는다. 그 한 글자가 아래 두 겹을 모두 몬다. 전부
`styles/wxfx.css` 에 있다.

```
1) 하늘   body 의 background-image. 위쪽만 물들고 아래로 사라진다.
2) 움직임 .wx-fx — 눈·비·구름·햇살·번개. transform 만 움직인다.
```

대시보드는 업무 중에 계속 보는 화면이 아니라 **들어올 때 지나는 대문**이다.
그래서 처음의 '옅게 물 들이기' 에서 한 발 더 나갔다 — 맑으면 하늘색이 제대로
돌고(`#77BFF5`), 밤이면 정말 밤하늘이 된다(`#141D35`).

| 날씨 | 하늘 | 움직임 |
|---|---|---|
| 맑음 | 하늘색 | 오른쪽 위에서 쏟아지는 햇살 + 먼지알 |
| 구름 조금 | 하늘색 | 구름 두어 덩이 |
| 흐림 | 회청색 | 구름이 하늘을 덮는다 |
| 안개 | 잿빛 | 납작한 띠가 옆으로 흐른다 |
| 이슬비 · 비 | 푸른 잿빛 | 빗줄기(굵기·속도 두 층) |
| 뇌우 | 보랏빛 | 빗줄기 + 번개 두 줄기 |
| 눈 | 옅은 하늘색 | 눈발이 좌우로 흔들리며 내린다 |
| 맑은 밤 | 짙은 남색 | 별이 깜박이고 달무리가 진다 |

**세 가지 원칙**

- **글자 위로는 아무것도 지나가지 않는다.** `.wx-fx` 는 `z-index:-1` 이다
- **움직이는 것은 `transform` 뿐이다.** 매 프레임 다시 칠하지 않고 합성만 하므로
  종일 켜 둬도 부담이 없다. `requestAnimationFrame` 루프는 하나도 없다
- **아래쪽은 마스크로 지운다**(250px 까지 또렷, 565px 에서 사라짐). 업무 시스템
  타일 뒤에는 아무것도 없다

`prefers-reduced-motion: reduce` 면 모든 움직임이 멈추고 번개는 아예 뜨지 않는다.
하늘 색만 남는다.

**이음매** — 내리는 층은 한 바퀴 이동 거리가 타일 한 장 높이(`--th`)와 정확히
같아서 끊기는 곳이 없다. 흐르는 층은 가로 한 장(`--tw`)이다.

```css
@keyframes wxFall{to{transform:translate3d(0,var(--th),0)}}
.wx-fx > i{top:calc(-1 * var(--th));height:calc(100% + var(--th))}
```

**타일은 `dev/gen_tiles.py` 가 만든다.** 눈·비·별은 무작위로 흩은 점과 획이고,
구름·안개는 `feGaussianBlur` 로 뭉갠 덩어리다. **가장자리에 걸친 모양은 반대편에도
찍어** 반복해도 자국이 남지 않게 한다. 결과는 SVG 데이터 URI라 바깥으로 나가는
요청이 없다(`styles/wxfx.css` 57KB, 그중 타일 35KB).

```
python dev/gen_tiles.py      # tiles.css 를 만든 뒤 wxfx.css 꼬리에 붙인다
```

**손대면서 물린 것들** — 같은 실수를 되풀이하지 않게 남겨 둔다.

- 구름·안개를 **흰색**으로 그렸더니 밝은 하늘 위에서 아예 안 보였다. 하늘보다
  한 단 어두운 회청색이라야 구름으로 읽힌다
- 구름을 **통 타원 하나**로 그렸더니 뿌연 얼룩이었다. 원을 겹쳐 뭉게구름
  실루엣을 만들어야 구름이 된다
- 구름 조금에 흐림과 **같은 층**을 썼더니 하늘에 구름이 가득 찼다. 타일을 화면보다
  훨씬 넓게(1700·2300px) 잡은 전용 층을 따로 뒀다
- 번개를 **무작위 걸음**으로 뽑으면 잔물결만 많아 낙서로 보이고, 꺾임을 키우면
  거의 직선이 된다. 갈지자는 손으로 잡았다
- 번개 빛무리를 **파랗게** 뒀더니 보랏빛 뇌우 하늘과 따로 놀았다. 연보라로 바꿨다
- 첫 번개까지 **15초**를 기다려야 했다. 음수 지연으로 2.5초·5.8초에 당겼다

---

### D-day 색

점판 색은 **제목·메모의 낱말로 고른 종류**(`board_event_kind`)를 따른다. 등록 창의
`D-day 색` 칸에 그 색이 '자동' 으로 미리 켜져 있고, 글을 고치면 따라 바뀐다.
다른 칸을 누르면 그 색이 `portal_event.color_kind` 에 남고 더는 글을 따라가지 않는다.
`자동으로` 를 누르면 다시 비운다(NULL).

- 사람이 고르는 것은 **색뿐이다.** 그림과 폭죽 문구는 여전히 낱말로 고른 종류를
  따른다 — 색을 경사로 바꿨다고 부고에 폭죽이 터지면 안 된다
- 자동 색은 서버가 고른다(`GET api/events.php?scope=kind&text=…`). 낱말 표를 JS 에
  한 벌 더 두지 않는다
- 서버는 `color`(실제로 칠할 종류 키)와 `color_kind`(고른 값, 자동이면 null)를 함께 내려 준다

---

### 축하 폭죽

**폭죽은 일정마다 켠다.** 일정 등록 창의 `축하 폭죽` 칸에서 고른다. 종류는 안
따진다 — 경사뿐 아니라 **공휴일이나 회식도 재미있게 알리자**는 뜻이라, 등록하는
사람이 고르게 두었다.

| 칸 | 기본값 | 열 |
|---|---|---|
| `cheer_on` | **아니오** | 그날 들어오면 폭죽을 쏠지 |
| `cheer_early` | 예 | 주말·공휴일이면 그 앞 평일에 미리 쏠지 |

`cheer_on` 을 끄면 `cheer_early` 는 물어볼 것이 없으므로 화면에서 잠긴다.
**감추지는 않는다** — 사라지면 그런 설정이 있었다는 것도 모른다.

**터뜨릴 날은 서버가 정해서 `cheer` 로 실어 준다**(`board_decorate_event`).
화면은 그 값만 보고 판단한다 — 주말·공휴일 셈을 브라우저로 내리지 않는다.

| `cheer` | 언제 |
|---|---|
| `today` | 일정 당일 |
| `early` | 일정이 주말·공휴일이라 그 앞 마지막 평일 |
| `null`  | 그 밖, 또는 그 일정에서 꺼 둠 — 안 터진다 |

**문구는 종류를 따라간다**(`BOARD_EVENT_KINDS` 의 `cheer` = `[당일, 미리]`).
서버가 아이콘까지 붙여 `cheer_cap` 으로 내려 주므로 화면은 받은 대로 쓴다 —
같은 표를 JS 에 한 벌 더 두면 언젠가 어긋난다.

| 종류 | 당일 | 미리 |
|---|---|---|
| 경사 | 🎉 축하합니다 | 🎉 미리 축하합니다 |
| 회식 | 🍜 오늘은 먹는 날! | 🍜 곧 먹는 날! |
| 휴무 | 🌴 오늘은 쉬는 날! | 🌴 곧 쉬는 날! |
| 마감 | ⏳ 오늘까지입니다 | ⏳ 곧 마감입니다 |
| 행사 | 🎈 오늘입니다! | 🎈 곧 있습니다! |
| 교육·작업·회의·그 밖 | 오늘입니다 | 곧 있습니다 |
| **조사** | — | — |

**조사에는 `cheer` 가 없다(`null`). 폭죽을 켜 두었더라도 안 터진다** — 부고 위로
색종이가 날리는 것은 실수로라도 일어나면 안 되는 일이라, 고를 수 있게 두지 않고
아예 막았다.

여러 건이 같은 날 걸리면 **첫 건의 문구**를 쓴다. 종류가 섞이는 날은 드물고,
문구를 여러 줄 쌓으면 정작 일정 이름이 눈에 안 들어온다.

**한동안 '경사 종류면 무조건' 이었다.** 그다음엔 포털 전체 스위치 하나로 켜고
끄게 만들었는데, 둘 다 물렸다 — 어떤 결혼식은 조용히 넘기고 싶고 어떤 회식은
크게 알리고 싶은데, 종류나 전체 스위치로는 그 결을 낼 수 없었다. 지금은
일정 한 건 한 건이 자기 몫을 쥔다.

`renderBoardEvents(rows)` 끝의 `maybeCelebrate(rows)` 한 줄이 전부다.

#### 미리 축하 — 쉬는 날이면 앞당긴다

토요일 결혼식을 월요일에 축하해 봐야 늦다. **일정 당일이 쉬는 날이면 그 앞의
마지막 평일**에 터뜨린다. 규칙이 하나라 토·일, 공휴일, 연휴 한가운데가 모두
같은 길로 풀린다.

```
토요일 경사   → 금요일
일요일 경사   → 금요일
월요일이 공휴일 → 그 전 금요일
연휴 한가운데  → 연휴 앞 마지막 평일
평일 경사     → 미리 없음(당일에 터진다)
```

**쉬는 날은 어디서 아나** — 세 곳을 겹쳐 본다.

1. `FIXED_HOLIDAYS` — 날짜가 고정된 국경일 (1/1, 3/1, 5/5, 6/6, 8/15, 10/3, 10/9, 12/25)
2. `config.php` 의 `'holidays' => ['2027-02-06', …]`
3. **달력에 등록된 휴무 일정** — `board_event_kind()` 가 `holiday` 로 고른 일정.
   연휴는 시작일부터 종료일까지 하루씩 전부 쉬는 날로 친다

**설날·추석·부처님오신날은 음력이라 코드로 셈할 수 없다.** 2번이나 3번으로
알려 줘야 한다. 안 알려 줘도 탈은 안 난다 — 그날을 평일로 보고 당일에 축하할
뿐이다. 음력 표를 코드에 박아 두고 해마다 고치는 것보다 이쪽이 낫다고 봤다.

```php
// config.php
'holidays' => ['2027-02-06', '2027-02-07', '2027-02-08'],   // 설날 연휴
```

날짜 셈은 `dev/cheer_test.php` 가 지킨다(DB 없이 도는 14건).

```
php dev/cheer_test.php
```

**한 번만** — `sessionStorage` 에 **현지 날짜**로 표를 남긴다. `toISOString` 으로
뽑으면 자정부터 아홉 시간 동안 어제 키가 나온다.

| 상황 | 다시 터지나 |
|---|---|
| 다른 화면 갔다가 대시보드로 복귀 | 아니오 |
| 새로고침 | 아니오 |
| 창을 새로 열거나 다시 로그인 | 예 |
| 다음 날 | 예 |

**canvas 를 쓴다.** 날씨 배경과 달리 **한 번 터지고 끝나므로** 마지막 알갱이가
사라지면 canvas 와 리사이즈 리스너까지 지운다. 상시 도는 루프가 남지 않는다.
CSS 로는 알갱이 200여 개를 각각 다른 포물선으로 던질 수 없다.

- 세 번에 나눠 터진다(0 / 0.36 / 0.76초). 한꺼번에 터뜨리면 '펑' 한 번으로 끝난다
- 알갱이는 점이 아니라 **짧은 꼬리**로 긋는다. 점만 찍으면 흩뿌린 색종이로 보인다
- 위에서 색종이가 나풀거리며 내려온다. 전체 3.4초
- z-index 250 — 상단바(200) 위, 겹쳐 뜨는 창(300) 아래

**제목은 판때기 없이 글자만** 뜬다. 흰 상자를 깔면 애써 만든 하늘이 가려진다.
대신 글자마다 흰 빛무리를 둘러(`text-shadow` 네 겹) 어떤 하늘 위에서도 읽히게
했다. **밤하늘에서는 거꾸로** 흰 글자에 분홍 빛무리다 — 짙은 남색 글자에 흰 테를
두르면 글자가 아니라 테만 보인다. 제목은 사용자가 입력하는 값이라 `esc()` 로 감싼다.

`prefers-reduced-motion: reduce` 면 폭죽은 건너뛰고 **카드만 조용히 떴다 진다.**
움직임이 싫다고 축하까지 못 볼 이유는 없다.

---

### 알림판과 업무 시스템 타일 구분

둘 다 흰 판이라 그냥 두면 "타일이 더 있는 것" 으로 읽힌다. 색을 더 쓰거나 그림자를
얹는 대신 **생김새**로 갈랐다.

| | 알림판 | 타일 |
|---|---|---|
| 머리말 띠 | 있음 (`#F4F7FD` + 아래 실선) | 없음 |
| hover | 반응 없음 | 떠오름 |
| 제목 | `Notice` / `Schedule` (Space Grotesk) | 한글 서비스 이름 |

그 사이에 `업무 시스템` 구역 이름과 가는 선을 하나 둬서 경계를 만든다.

> 처음에는 알림판에만 그림자를 줬는데, 포털의 다른 화면에는 그림자를 쓰는 곳이
> 없어 혼자 겉돌았다. 머리말 띠는 "누르는 게 아니라 읽는 칸" 이라는 걸
> 구조로 말해 주고, 색도 새로 만들지 않고 바탕과 흰색 사이 값 하나만 쓴다.

---

### 대시보드 세 칸의 너비

```css
.board{ grid-template-columns: minmax(0,1fr)  440px  var(--panel-h); }
/*                              공지          일정    날씨(정사각) */
```

**일정 칸만 붙박이 너비다.** 안에 든 것이 점판(130px)과 두 줄 글자뿐이라 비율로
늘리면 오른쪽이 텅 빈다. 440px 면 점판·여백을 빼고 제목에 **276px** 이 남고,
열대여섯 자쯤 들어간다. 넘치면 `…` 으로 줄이고 `title` 로 전체 제목을 띄운다.

남는 너비는 **공지가 다 가져간다** — 줄 수가 많은 쪽이 넓어야 한다. 1180px 아래로
좁아지면 날씨부터 접고 일정을 400px 로 줄이며, 1000px 아래에서는 한 줄로 쌓는다.

### 대시보드 두 칸의 높이와 자동 슬라이딩

높이를 픽셀로 박지 않고 **"한 번에 보여 줄 것"** 으로 정한다.

```css
.board{ --row-h:36px;                      /* 공지 한 줄 */
        --win-h:calc(var(--row-h) * 2); }  /* 공지 2줄 = 일정 카드 1장 */
```

공지 창도 일정 창도 같은 `--win-h` 를 쓰고, 칸 바깥 높이는 `머리말 + 창` 으로
저절로 정해진다. 두 칸의 머리말 높이가 같아서 결과도 같다(실측 137px / 137px).
더 보여 주고 싶으면 곱셈수만 바꾸면 되고, 픽셀을 다시 재지 않아도 된다.

내용이 쌓여도 바깥 높이는 그대로다 — 공지 수에 따라 업무 시스템 타일이 아래로
밀려나면 안 된다. 넘치는 만큼은 **한 줄(장)씩 위로 올라가며 돌아간다**(`makeSlider`).
공지는 3.6초, 일정은 4.2초 간격이다.

**일정은 큰 카드 한 장씩 돈다.** 가까운 한 건만 세워 두면 나머지를 영영 못 보고,
여러 개를 줄글로 늘어놓으면 칸이 길어진다. 한 장씩 돌리면 칸은 짧고 전부 눈에 든다.

두 칸 모두 같은 방식이다.

```
.slide-win   고정 높이 창 (overflow:hidden)
  └ .slide-track   실제 목록.
       다음 → transform 으로 한 줄 높이만큼 위로 민 뒤, 맨 윗줄을 맨 아래로 옮긴다
       이전 → 맨 뒷줄을 먼저 앞에 붙여 놓고, 밀린 상태에서 제자리로 내린다
```

> 일정은 한때 큰 카드 한 장씩 **달력처럼 넘겼다**(`rotateX` 경첩). 접히는 각도는
> 제대로 먹었지만 실제 화면에서 효과가 잘 살지 않았고, 카드 자체가 화면의 시선을
> 가져가는 문제도 있었다. 카드를 걷어내고 줄로 바꾸면서 넘기기도 함께 뺐다 —
> 얇은 글줄이 3차원으로 접히는 건 어차피 어울리지 않는다.

목록을 복제하지 않아 줄이 늘어도 DOM 이 두 배가 되지 않는다.

**손으로 넘기기** — 공지는 머리말의 `⌃ 3 / 8 ⌄`, 일정은 위아래 화살표로 옮긴다. 건수가 많으면
보고 싶은 게 돌아올 때까지 기다려야 해서 넣었다. 숫자를 같이 보여 주는 이유도
같다 — 몇 건인지 모르면 "다 본 건가" 싶어 계속 기다리게 된다.

- 자동은 자동대로 계속 돈다. 누르면 타이머만 다시 센다 —
  누르자마자 자동으로 또 넘어가면 두 칸이 지나간 것처럼 보인다.
- 마우스를 올려 둔 상태(자동 멈춤)에서도 단추는 움직인다.
- 다 보이면(넘칠 게 없으면) 단추와 숫자를 자리만 두고 감춘다 — 사라지면 줄이 들썩인다.

**자동이 멈추는 조건** — 마우스를 올리거나 키보드 초점이 들어오면 멈춘다(읽는 중에
바뀌면 안 된다). `prefers-reduced-motion: reduce` 면 자동으로는 돌리지 않는다.
이때도 단추로는 넘길 수 있다.

> **한 칸 옮기는 일이 끝났는지를 `transitionend` 만으로 판단하면 안 된다.**
> 창이 숨어 있으면(다른 화면에 가 있을 때) 전이가 시작조차 안 해 이벤트가 영영
> 오지 않고, `busy` 가 풀리지 않아 슬라이더가 죽는다. 시간 제한(`SLIDE_MS+250`)을
> 같이 걸어 두 경로 중 먼저 오는 쪽이 정리하게 했다. 실제로 이것 때문에 한 번 멎었다.

### 겹쳐 뜨는 창

읽기와 쓰기를 다르게 띄운다.

| 하는 일 | 모양 | 요소 |
|---|---|---|
| 공지 읽기 | 가운데 팝업 | `#nd-modal` (`.modal--center`) |
| 공지 쓰기·고치기 | 오른쪽 드로어 | `#ne-modal` (`.modal--right`) |
| 일정 등록·수정 | 오른쪽 드로어 | `#ev-modal` (`.modal--right`) |

쓰는 동안에는 뒤쪽 목록이 보이는 편이 낫고, 읽기는 가운데가 눈이 편하다.
bluecart 도 같은 방식이다.

상단바가 `z-index:200` 이라 **창은 300** 으로 올려야 머리말이 가리지 않는다.
토스트는 그보다 위(400)여야 저장 결과가 보인다.

바깥을 누르거나 `Esc` 로 닫는다. 여러 겹이면 맨 위 하나만 닫힌다 — 상세를 보다
수정을 열었을 때 `Esc` 한 번에 둘 다 닫히면 당황스럽다.

### 움직임

훑어볼 때 눈이 가라고 넣은 것들이다. 전부 `prefers-reduced-motion: reduce` 면 꺼진다.

- 일정이 바뀔 때 살짝 밀려 올라온다(`evRise`)
- 오늘 일정은 D-day 점과 전원 표시등이 아주 천천히 숨 쉰다(`pixBreathe`)
- 날씨에 따라 배경색이 0.6초에 걸쳐 갈아든다
- 날씨에 맞춰 눈·비·구름·햇살·번개가 화면 위쪽에서 움직인다 → 「날씨 배경」
- 오늘 경사 일정이 있으면 들어올 때 한 번 폭죽이 터진다 → 「축하 폭죽」
  (이것만은 `reduce` 여도 축하 제목 카드는 뜬다)

### 공지 첨부 저장

learn 과 같은 방식이다.

- 저장 위치는 `var/notice`, 저장 이름은 서버가 난수로 짓고 원본명은 DB 에만 둔다
- 웹으로 직접 못 받게 `var/.htaccess` 가 막고, 열람은 반드시
  `api/notice_file.php`(로그인 검사 + Content-Disposition 판정)를 거친다
- 이미지·PDF 만 브라우저에서 바로 열고(`inline`) 나머지는 강제로 내려받게 한다
- 공지를 지우면 첨부 실물도 함께 지운다(`board_delete_notice`)

**첨부가 안 올라갈 때 볼 곳** — 조용히 실패하기 쉬운 자리가 셋 있어 전부 막아 뒀다.

| 원인 | 증상 | 대응 |
|---|---|---|
| `var/notice` 가 없거나 쓰기 불가 | `move_uploaded_file` 만 실패 | `board_upload_dir()` 이 먼저 잡아 경로와 함께 알린다 |
| `post_max_size` 초과 | PHP 가 본문을 통째로 버려 `$_FILES` 가 빔. **아무 오류도 안 남** | `api/notice_file.php` 가 `CONTENT_LENGTH` 를 보고 잡는다 |
| `upload_max_filesize` 초과 | `UPLOAD_ERR_INI_SIZE` | 실제 한계와 ini 값을 같이 알린다 |

`var/notice` 는 `.gitignore` 대상이라 **배포 직후에는 없다.** 웹서버 계정이 포털
루트에 못 쓰면 `@mkdir` 이 조용히 실패한다. 배포할 때 한 번 만들어 두는 편이 낫다.

```bash
mkdir -p <포털루트>/var/notice
chown -R www-data:www-data <포털루트>/var
```

화면에 적는 한계(`파일당 NMB`)는 `board_max_upload_label()` 이 **php.ini 를 반영해**
계산한다. `NOTICE_MAX_MB` 는 20 이지만 php.ini 기본값이 `upload_max_filesize=2M`,
`post_max_size=8M` 이라 그대로 두면 2MB 에서 막힌다. 20MB 를 쓰려면 php.ini 를
올려야 하고, 안 올리면 화면에도 2MB 로 정직하게 표시된다.

첨부가 실패하면 **드로어를 닫지 않는다.** 글은 이미 저장됐으므로 그 글의 수정
상태로 남겨 두고 이유를 오류 상자에 띄운다 — 토스트는 2초면 사라져 놓치기 쉽다.

> **nginx 로 서비스한다면** `.htaccess` 는 읽히지 않는다. 서버 설정에
> `location ~ ^/var/ { deny all; }` 를 따로 넣어야 한다. 저장 이름이 난수라
> 주소를 찍어 맞히기는 어렵지만, 막아 두는 편이 확실하다.

### 중요 일정 · D-day

- `starts_on` 이 D-day 기준일이다. `ends_on` 을 주면 그 날까지 "진행중" 으로 남는다
  — 워크숍 둘째 날에 목록에서 사라지면 곤란하다.
- 첫 화면에는 **다가오는 것만** 가까운 순으로 4건. 지난 일정은 관리 화면에만 남는다.
- D-day 는 `board_decorate_event()` 가 계산한다. 시각을 자정으로 맞춰 날짜만
  비교한다 — 그러지 않으면 오후에 본 '내일' 이 D-0 으로 나온다.
### 일정 — D-day 픽셀 판

**일정 카드는 없다.** 왼쪽은 모니터 테두리를 두른 점판이고, 오른쪽에 제목과
날짜가 붙는다.

```
┌───────────────────┐   ← 은색 베젤
│ · · · · · · · · · │
│ · ██ · ·  █ █  ██ │       🎉 축!! 유병문선임 결혼
│ · █ █ ██  ███  ██ │   ← 짙은 화면, 켜진 점만 빛난다
│ · ██ · ·  · █  ██ │       2026.10.31(토) | 더빈컨벤션 웨딩홀
│ · · · · · · · · · │
└──────────────── ● ┘   ← 전원 표시등(종류 색)
```

핵심은 **판이 고정되어 보이는 것**이다.

- 판은 **21×9 = 189점 붙박이**. `D-3` 이든 `D-128` 이든 크기도 점 개수도 같다
- 글자는 **3칸 × 7줄** 픽셀 글자(`PIX_FONT`)로 찍고, **점 칸 단위로 가운데를
  맞춘다** — 글자 수로 맞추면 네 글자일 때 한쪽으로 한 칸 치우친다
- 여백은 **위아래·좌우 한 줄씩만**. 나머지는 글자가 차지한다(판 높이의 78%)
- **실물 도트 판을 흉내 낸다.** 화면은 짙은 네이비(`#101828`)고 **꺼진 점은 그보다
  한 단 밝다**(`#28344C`). 불이 안 들어와도 점이 거기 박혀 있는 게 보여야 '점으로
  찍는 판' 이 된다 — 둘의 대비 **1.42** 가 그 정도다. 화면을 밝게 가는 길도 한참
  가 봤는데(`#fff` 바탕 + 짙은 글자) 종이에 찍은 것처럼 보이지 도트 판으로는
  안 읽혔다
- **켜진 점은 뒤에서 빛이 들어온 것처럼 밝다**(`--k-lit`). 본래 색을 그대로 쓰면
  회의(`#1C5DE5`)·조사(`#5B6577`) 같은 어두운 색이 짙은 화면에서 대비 3 언저리라
  글자가 뭉개진다. 밝게 올린 값으로 **5.7~8.3** 을 맞추고, 아주 옅은
  번짐(`drop-shadow(0 0 .35px)`)을 얹어 점이 빛을 뿜는 것처럼 보이게 했다.
  번짐 길이는 viewBox 단위라 `.35` 면 점 하나의 절반이 채 안 된다
- 한글은 못 찍으므로 `진행중` 은 `NOW` 로 옮긴다(`pixText`)
- 제목이 칸보다 길면 `…` 으로 줄이고 `title` 로 전체를 띄운다. 날짜·장소 줄과
  공지 제목도 같다 — 줄여 놓고 전체를 볼 길이 없으면 못 읽는 것과 같다
- **테두리는 모니터 베젤**이다. 점만 떠 있을 때보다 '기기' 로 읽혀 손맛이 산다.
  위에서 아래로 흰빛이 빠지는 결(`#FDFEFF`→`#D6DEEA`)에 한 단 짙은 테두리선
  (`#9AA8BD`)을 둘러 **은색 금속**처럼 보이게 했다. 짙은 차콜 베젤도 만들어
  봤는데 화면에서 너무 셌다
- **화면 바탕은 svg 자체에 깐다**(`.pix{background:#101828}`). 안 깔면 점 사이 틈으로
  은색 베젤이 비쳐 짙은 화면이 되지 않는다. 안쪽 그림자를 한 겹 넣어 화면이
  테두리보다 한 턱 꺼져 보이게 했다
- 아래 테두리만 두꺼운 건 실제 모니터가 그래서고, 거기 붙는 **전원 표시등이 종류
  색**(`--k-lit`)을 따라가므로 색이 바뀌는 게 한 군데 더 보인다. 발광색은 밝아서
  은색 테두리 위에 그냥 두면 묻히므로 짙은 테를 한 줄 둘렀다. 오늘 일정이면
  점과 표시등이 함께 천천히 숨 쉰다
- 테두리를 두르고도 **한 줄 높이(`--win-h` 72px) 안에 들어간다** — 점판 114px
  (높이 48.8) + 위아래 테두리 20px = 68.8px. `--win-h` 를 줄일 거면 점판 너비도
  같이 줄여야 한다

| 종류 | 본래 색 | 발광색 | 꺼진 점(`#28344C`) 대비 |
|---|---|---|---|
| 조사 | `#5B6577` | `#C3CEDE` | 8.3 |
| 경사 | `#D6336C` | `#FF86AE` | 5.8 |
| 휴무 | `#0F9B8E` | `#3FD9C7` | 7.5 |
| 마감 | `#D9820B` | `#FFB43F` | 7.4 |
| 교육 | `#7B4DD8` | `#B79BFF` | 5.7 |
| 작업 | `#4A6B8A` | `#93B9DB` | 6.4 |
| 행사 | `#E2622A` | `#FF9457` | 6.0 |
| 회의 | `#1C5DE5` | `#79B0FF` | 5.9 |
| 회식 | — | `#8FDD72` | 7.6 |
| 그 밖 | `#5A73A8` | `#ABBFE6` | 7.1 |

아홉 종류 모두 **5.7 이상** — WCAG AA(4.5) 를 넉넉히 넘는다. 본래 색은 관리
화면의 `.dday` 알약이 그대로 쓰므로 두 곳의 색이 어긋나 보이지 않게 계열을 맞췄다.

**넘어갈 때 판은 가만히 있고 글자만 바뀐다.**

```
.ev-wrap
  ├─ .ev-pix        점판 — 창 바깥에 고정. 슬라이더가 alerts 로 다시 칠한다
  └─ .slide-win     제목·날짜만 이 안에서 밀려 올라간다
```

점 147개는 **한 번만 그려 두고**(`pixSkeleton`) 일정이 바뀌면 각 점의 class 만
`on`/`off` 로 갈아 끼운다(`paintPix`). DOM 을 새로 만들지 않으므로 판이 다시 그려지지
않고, `fill` 에 전이를 걸어 뒀으니 **바뀌는 점만 스르르 물든다**.
슬라이더는 넘어갈 때마다 `onMove(pos)` 로 알려 준다.

> 달력 틀(굵은 위 테두리 + 고리)과 검은 판 네온 7세그먼트를 거쳐 여기까지 왔다.
> 테두리를 두르면 그 칸만 무겁게 튀고, 네온은 포털의 다른 화면과 겉돌았다.
> 점만 남기니 결이 맞는다.

오늘 것은 켜진 점이 2.4초에 걸쳐 아주 천천히 숨 쉰다. 깜빡이면 업무 화면에서
성가시다. `prefers-reduced-motion: reduce` 면 전이도 숨쉬기도 모두 끈다.

한 건이 창(`--win-h`)을 꽉 채우므로 옆 공지 칸(2줄)과 바깥 높이가 저절로 같다.

종류는 `board_event_kind()` 가 제목·메모의 낱말을 보고 고른다.

| key | 낱말 예 | 그림 | 색 |
|---|---|---|---|
| `condolence` | 장례, 부고, 발인, 빈소, **부친상·모친상·조부상·장인상…** | 🕯 | 회색 |
| `congrats` | 결혼, 청첩, 출산, 승진, **추카, 경축**, `축 `, `축!` | 🎉 | 자홍 |
| `holiday` | 휴무, 연휴, 휴가, 창립 | 🌴 | 청록 |
| `deadline` | 마감, 제출, 만료, 기한 | ⏳ | 주황 |
| `edu` | 교육, 세미나, 특강, 연수 | 🎓 | 보라 |
| `ops` | 점검, 배포, 릴리스, 이전 | 🛠 | 남회색 |
| `meal` | 먹자, 회식, 만찬, 오찬, 다과 | 🍜 | 연두 |
| `event` | 워크숍, MT, 송년, 축제 | 🎈 | 주홍 |
| `meeting` | 회의, 미팅, 보고, 킥오프 | 📋 | 파랑 |
| `etc` | (아무것도 안 걸림) | 📅 | 청회색 |

**목록의 순서가 곧 우선순위다.** `condolence` 가 맨 위에 있어야 '부친상' 같은 제목에
축하 색이 붙지 않고, `meal` 이 `event`·`meeting` 보다 위에 있어야 '먹자클럽 정기 회의'
가 회의가 아니라 회식으로 걸린다.

한동안 **'부친상' 이 아무 낱말에도 안 걸려 달력 아이콘이 붙었다**('장례'·'부고'·'조문'
만 보고 있었다). 상을 당한 일정에 그러면 안 되므로 '…상' 형태를 전부 넣었다.
낱말을 늘릴 때는 `dev/kind_test.php` 에 한 줄 같이 넣어 두면 다음 사람이 안 깬다.

#### 낱말은 화면에서 고친다 — 포털 관리 → 중요 일정 → `설정`

낱말 하나 늘리자고 소스를 고치고 배포할 일은 없다.

```
GET    api/event_words.php              종류별 낱말 + 코드 기본값      관리자
PUT    api/event_words.php              저장 { congrats: "결혼, …" }   관리자
DELETE api/event_words.php?kind=…       한 종류를 기본값으로           관리자
```

하나라도 잘못되면 **아무것도 저장하지 않는다.** 반만 들어가면 화면과 DB 가
어긋나 무엇이 저장됐는지 알 수 없게 된다.

폭죽을 쏠지는 여기가 아니라 **일정 등록 창**에 있다 → 「축하 폭죽」

저장한 값은 `portal_event_word` 에 들어간다. **줄이 없는 종류는 코드 기본값**
(`BOARD_EVENT_KINDS`)을 쓰므로, 표를 비우면 언제든 처음 상태로 돌아온다.
코드 기본값과 같아지면 줄을 아예 지워 둔다 — 표에 군더더기를 안 남긴다.

**화면에서 바꿀 수 있는 것은 낱말뿐이다.** 순서(우선순위)와 아이콘·색은 코드가
쥐고 있다. 순서까지 열면 조사를 아래로 내려 '부친상' 에 축하 색이 붙는 일이
생긴다 — 그건 실수로라도 만들 수 있으면 안 되는 상태다.

**빈칸이 뜻을 갖는 낱말은 따옴표로 감싼다.**

```
결혼, 청첩, 추카, 경축, "축 ", 축!
```

쉼표 뒤에 한 칸 띄우는 게 자연스러워서 낱말 앞뒤 빈칸은 떼어 내는데, 그러면
`축 ` 이 `축` 이 되어 '축구 대회'·'개회 축사' 까지 경사가 된다. 따옴표 안은
손대지 않는다. 화면에 되돌려 줄 때도 빈칸이 붙은 낱말만 따옴표를 씌워 주므로,
설정 화면을 열면 지금 상태가 그대로 보인다.

| 막아 둔 것 | 값 |
|---|---|
| 낱말 하나의 길이 | `KIND_WORD_LEN_MAX` 40자 |
| 한 종류의 낱말 수 | `KIND_WORD_MAX` 60개 |
| 같은 낱말 | 한 번만 남긴다 |
| 표가 망가진 종류 | 조용히 코드 기본값으로 — 화면은 살린다 |

**낱말 뒤에 빈칸이나 느낌표를 붙여 둔 것들이 있다** — `축 `, `축!` 이 그렇다.
'축' 한 글자로 잡으면 '축구 대회', '개회 축사' 까지 경사가 되어 폭죽이 터진다.
`추카`·`경축` 은 그런 걱정이 없어 그냥 넣었다.

**`축하` 는 일부러 안 넣었다.** 넣으면 '축하 공연 준비 회의' 같은 준비 회의까지
경사가 되어 폭죽이 터진다. 경사가 회의보다 우선순위가 높기 때문이다. 낱말을 늘리려면 해당 줄에 덧붙이기만 하면 되고, 종류를
새로 만들 때만 `styles/board.css` 에 `.ev-pix.k-<key>{--k-lit:…}` 한 줄을 더한다.

### 화면

| 화면 | 가는 길 |
|---|---|
| 대시보드 알림판 | 로그인 직후 |
| 공지 목록 | 알림판 `전체 보기` 또는 사용자 메뉴 `📢 공지사항` |
| 공지 상세 | 목록·대시보드에서 제목 클릭 → 가운데 팝업 |
| 공지 전체 | 알림판 머리의 `전체 보기 →` (대시보드는 2줄만 돌린다) |
| 공지 작성/수정 | 관리자만. `+ 새 공지` / 상세의 `수정` → 오른쪽 드로어 |
| 포털 관리 | 관리자만. 사용자 메뉴 `⚙️ 포털 관리` (중요 일정 · 공지 · 관리자 3개 탭) |

화면 전환은 `index.php` 의 `showView(id)` 한 곳을 거친다. 화면을 새로 추가하면
`VIEWS` 배열에 id 를 넣어야 한다 — 화면마다 서로를 숨기게 두면 하나 추가할 때마다
빠뜨리는 곳이 생긴다. 겹쳐 뜨는 창은 화면이 아니라서 `VIEWS` 에 넣지 않고
`OVERLAYS` 가 따로 관리한다.

---

## 모듈별 문서

모듈마다 구조·규칙·이관 이력·배포를 그 폴더의 README 에 적는다. 이 문서는 포털 공통만 다룬다.

| 모듈 | 문서 |
|---|---|
| book (BlueBooks · 도서구매신청) | [book/README.md](book/README.md) |
| dti (BlueMagazine · DTI 발표) | [dti/README.md](dti/README.md) |
| learn (BlueLearn · 강의 수강료 지원) | [learn/README.md](learn/README.md) |
| moodle (MoodleUp? · 무들 동향) | [moodle/README.md](moodle/README.md) |
| onwatch (OnWatch · 수시 감시) | [onwatch/README.md](onwatch/README.md) |
| envlog (Coursemos EnvLog · 운영 웹서버 로그 뷰어) | [envlog/README.md](envlog/README.md) |
| access (Coursemos EnvHub · 학교 접속 정보) | [access/README.md](access/README.md) |
| sitehub (BlueLab SiteHub) | [sitehub/README.md](sitehub/README.md) |
| slackai (WorkHub AI · 테스트베드) | [slackai/README.md](slackai/README.md) |
| bluecart (BlueCart · 물품 요청) | [bluecart/README.md](bluecart/README.md) |
| blueledger (BlueExpense · 출장여비) | [blueledger/README.md](blueledger/README.md) |
| studio (BlueStudio · 개발 과업 편성) | [studio/README.md](studio/README.md) |

---

## 로컬에서 새로 만들어야 하는 파일 (전부 gitignore됨 — git엔 없음)

| 파일 | 용도 | 비고 |
|---|---|---|
| `slackai/worker/.env` | slackai 워커 설정(PHP 경로, Slack 앱 토큰 xapp/xoxb, 개인 xoxp 토큰, API 키, 예산) | `slackai/worker/.env.example` 복사. `config.php` 에 `'slackai'` 절도 추가 |
| `config.local.php` (루트) | 포털 SSO 토큰 암호화 키 | **자동 생성됨**(최초 실행 시) |
| `sso_secret.key` (루트) | book과 공유하는 SSO 서명 키 | **자동 생성됨**(최초 실행 시) |
| `vapid.key` (루트) | 웹 푸시 서명 키쌍 | **자동 생성됨**(첫 알림 사용 시). 서버를 옮기면 같이 옮겨야 구독이 유지된다 |
| `slack/config.local.php` | Gmail IMAP 계정 정보 | **직접 생성 필요**. 키는 `slack/gmail/gmail_lib.php` 머리 주석 참고 |
| `config.local.php` 의 `book_slack_webhook` | book Slack 웹훅 URL(선택) | 없으면 알림 기능만 비활성. 파이썬 시절 `book/config_local.py` 에서 옮긴다 |
| `config.local.php` 의 `kakao_rest_api_key` 또는 `book/kakao_keys.json` | 카카오 도서검색 API 키(선택) | 없으면 검색 자동완성만 비활성 |
| `config.php` 의 `dti_slack_webhook` | dti 모듈 Slack 웹훅 URL(선택) | 없으면 발표자 등록 알림만 비활성 |
| `SVN_배포_디비정보(블루내부공유).xlsx` (루트) | access 모듈 초기 데이터 | **직접 가져다 둘 것.** 전 대학 계정/비번이 들어 있어 커밋 금지 |
| `config.php` 의 `sitehub_list_id` · `sitehub_list_url` | sitehub 모듈이 읽는 Slack 리스트(BlueLab-유지보수처리목록) ID·주소 | 없으면 이력 동기화만 꺼진다 |
| `config.local.php` 의 `sitehub_key` | sitehub 비밀번호·BLUMO 웹훅·에이전트 키 암호화 키 (base64, 32바이트) | **직접 추가.** 없으면 포털 공용 `key`. 바꾸면 그 키로 암호화한 값이 안 풀리니 서버를 옮길 때 같이 옮긴다 |
| `config.local.php` 의 `sitehub_slack_token` | sitehub 이력 동기화 토큰 (thebluesoft.slack.com — 포털 개인 토큰과 다른 워크스페이스) | **직접 추가.** 없으면 로그인 사용자의 개인 토큰을 시도 |
| `config.php` 의 `sitehub_stats_emails` | sitehub 통계를 볼 수 있는 계정 목록 | 선택. 없으면 `pink@bluesoft.co.kr` 하나, `[]` 면 로그인한 누구나 |
| `sitehub/list_cols.php` 의 값 | BlueLab 리스트의 컬럼 매핑 | 저장소에 **채워져** 있다(리스트 F088VTXBD0R 기준). 리스트 열이 바뀌면 `php sitehub/sync.php schema` 로 확인해 고친다 |

루트 `config.php` 형식:
```php
<?php
/**
 * blue-iwork 포털 설정.
 *  - DB는 slack 모듈과 같은 slackapi MySQL을 그대로 재사용한다.
 *    (같은 물리 DB, 포털 전용 테이블만 추가로 생성)
 *  - 슬랙 토큰 암호화 키는 git에 올리지 않는 config.local.php 에 최초 실행 시 1회 자동 생성.
 */

$local = __DIR__ . '/config.local.php';
if (!is_file($local)) {
    $key = base64_encode(random_bytes(32));
    file_put_contents($local, "<?php\nreturn [\n    'key' => '" . $key . "',\n];\n");
}
$localCfg = require $local;

return [
    // DB 접속 정보 (slack 모듈과 같은 slackapi DB)
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

    // sitehub(BlueLab SiteHub) — Slack 리스트 'BlueLab-유지보수처리목록'. 워크스페이스는 thebluesoft.slack.com 이라
    // 포털의 개인 토큰(coursemos)으로는 못 읽는다 → 토큰은 config.local.php 의 'sitehub_slack_token'.
    // 비어 있으면 이력 동기화만 꺼지고 나머지 화면은 그대로 쓴다.
    'sitehub_list_id'     => '',   // 예: F0XXXXXXXXX
    'sitehub_list_url'    => '',   // 예: https://thebluesoft.slack.com/lists/T0XXXXXXX/F0XXXXXXXXX
    'sitehub_slack_token' => $localCfg['sitehub_slack_token'] ?? null,
    // sitehub 전용 암호화 키 — 없으면 위의 공용 key 를 쓴다
    'sitehub_key' => $localCfg['sitehub_key'] ?? null,

    // dti(DTI 발표) 슬랙 알림 웹훅 — 선택. 없으면 발표자 등록 알림만 조용히 꺼진다.
    // (파이썬 magazine 의 config_local.py 에 있던 SLACK_WEBHOOK_URL 자리다)
    'dti_slack_webhook' => null,

    // envlog AI 분석(Claude) — 'envlog_ai_enabled' => false 면 AI 상자가 아예 안 보인다(기본 true).
    // 회사 공용 Anthropic API 키(console.anthropic.com 발급). 개인 키를 등록한 사용자는 그 키가 우선.
    // 요금 통제(선택): 'envlog_ai_monthly_cap_usd' => 100, 'envlog_ai_max_per_request_krw' => 50, 'envlog_ai_usd_krw' => 1400, 'envlog_rotate_hour' => 5
    'anthropic_api_key' => $localCfg['anthropic_api_key'] ?? '',

    // envlog 의 SSH 로그 엔진(envlog/daemon/envlogd.py) 주소. 기본 포트(8766)면 이 줄은 없어도 된다.
    // (그 외 선택: 'envlog_system_python' 파이썬 경로, 'envlog_autostart' => false, 'envlog_schools_from' => 'db'|'daemon')
    'envlog_url' => $localCfg['envlog_url'] ?? 'http://127.0.0.1:8766',

    // book(BlueBooks) — 둘 다 선택. 웹훅이 없으면 신청 알림만, 카카오 키가 없으면 도서명 자동완성만 꺼진다.
    // (파이썬 시절 book/config_local.py 의 SLACK_WEBHOOK_URL · kakao_keys.json 의 rest_api_key 자리)
    'book_slack_webhook' => $localCfg['book_slack_webhook'] ?? null,
    'kakao_rest_api_key' => $localCfg['kakao_rest_api_key'] ?? null,

    // dti 의 발표자료 PDF 변환에 쓰는 LibreOffice 실행 파일. PATH 에서 찾는다.
    // 웹 서버의 PATH 에 없으면 절대경로로 바꾼다(이 파일 자체가 서버마다 따로 만드는 파일이다).
    'soffice' => 'soffice',
];
```

루트 `config.local.php` 형식:
```php
<?php
return [
    'key' => 'p1WLBZerg/PAf8y7lae4DMSw0v1r8+LsQF3AjiV9Sas=',
    'sitehub_slack_token' => 'xoxb-… 또는 xoxp-…',   // sitehub 이력 동기화용 (선택)
];
```

---

## 환경변수 / 설정값 확인 필요

- **book 의 `PORTAL_URL`·`links.book`** 은 없어졌다(2026-10, PHP 이관). 서버 `config.local.php` 에
  남아 있는 `book_url` 은 읽는 곳이 없으니 지워도 된다.
- **moodle/watch 환경변수** — 전부 선택. 서버는 systemd 유닛의 `Environment=` 로 주고, 로컬은
  `moodle/watch/.env`(`.env.example` 복사, git 제외)에 적어 두면 CLI 와 `--serve` 가 같이 읽는다.
  환경변수가 있으면 `.env` 보다 우선한다. `MOODLE_ORG_TOKEN`(없으면 PAG 코스 skipped), `ANTHROPIC_API_KEY`
  (없으면 `claude -p` 시도), `GITHUB_TOKEN`(선택), `SLACK_WEBHOOK_URL`(완료/실패 알림),
  `PORTAL_URL`(알림 링크용), `DATA_DIR`(기본 `moodle/watch/var`), `SUMMARIZER`(auto|anthropic|cli|none),
  `DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_NAME`(없으면 config.php 를 php 로 읽음).
- **PHP IMAP 확장** — Gmail 기능(`slack/gmail/`)에 필요. 이 서버(WAMP php8.2.28 등)엔 이미 켜져
  있는 것 확인함. 다른 서버로 옮기면 `extension=imap` 활성화 확인.
- **`slack/gmail/start_gmail_watch.bat`** · **`slack/start_slack_watch.bat`** — PHP 실행 경로가
  WAMP php8.1.0 으로 고정돼 있음. 다른 PHP 버전 쓰는 서버면 배치 파일의 경로 수정 필요.

---

## DB

MySQL 하나(`slackapi`)를 portal/slack/gmail이 공유한다. 전부 최초 접속 시 테이블
자동 생성/마이그레이션(`db.php`의 `db()`/`portal_db()`). 주요 테이블:

- `portal_users` — 포털 계정(이메일/비번해시/암호화된 슬랙 토큰/대시보드 배경 설정 `bg_pref`)
- `portal_admin`, `portal_notice`, `portal_notice_file`, `portal_event` — 알림판(아래 참고).
  `portal_notice.is_pinned` 는 `is_important` 로 이름이 바뀌었다(`db.php` 가 `CHANGE COLUMN` 으로
  한 번만 처리). 값은 그대로 넘어간다.
- `requests` — slack 유지보수 요청 목록(Slack Lists 동기화본)
- `schools`, `user_reads`, `user_pins`, `user_hides`, `local_assignments`, `sync_meta` — slack 부가기능
- `gmail_mails` — Gmail 캐시(계정별 구분, `account` 컬럼)
- `dti_topics`, `dti_presentations`, `dti_emotions`, `dti_fields`, `dti_related`,
  `dti_materials`, `dti_rounds` — dti 모듈
- `school_access` — 대학별 접속·배포 정보(access 모듈). `schools` 가 마스터이고 여기는 상세라
  `school_id` 로 붙는다. 한 대학이 버전군별로 여러 행을 가질 수 있어(강원대 3.5 + 4.5)
  키는 `(school_id, grp)` 다.
- **`slackai_db`(별도 데이터베이스)** — slackai 모듈 전용. slack 8개 테이블의 복제본 + `ai_*` 테이블. `slackai/README.md` 참고.

컬럼 추가 마이그레이션은 전부 `add_column_if_missing()`(`core/db.php`)을 거쳐 동시 요청에도
안전하게(이미 있으면 조용히 무시) 처리하도록 통일돼 있다. **새로 컬럼 추가 마이그레이션을 짤 때
"확인 후 ALTER" 패턴을 직접 쓰지 말 것** — 페이지 로드 시 여러 AJAX가 동시에 뜨면서 레이스가 난다.

---

## 배포 (systemd)

화면 모듈은 전부 PHP 라 systemd 유닛이 없다. **book 의 uvicorn 유닛(`book.service`)은 2026-10 PHP
이관으로 필요 없어졌다** — 서버에서 이렇게 걷어낸다.

```bash
sudo systemctl disable --now book.service && sudo rm /etc/systemd/system/book.service
# nginx 의 /book/ · /bookapi/ proxy_pass 두 블록을 지우고 reload
php book/tools/migrate_from_sqlite.php --db=/home/blueapp_core/book/book.db --force   # book.db → book_requests
```

`book.db`·`venv/`·`app.py` 는 이관 결과를 확인한 뒤 지운다(`book.db` 는 백업으로 한 벌 남겨 둔다).

### nginx

PHP 모듈은 프록시 블록이 필요 없다. 대신 **문서루트 아래에 있으면 안 되는 것들을 막아둔다.**
배치 소스(`moodle/watch/`), 업로드 보관함(`learn/var/`), CLI 도구(`*/tools/`), 남아 있는
`book.db` 가 그렇다 — 업로드 보관함에는 이수증 원본이 들어가므로 URL 로 바로 열리면 안 된다:

```nginx
location ^~ /moodle/watch/  { deny all; }
location ^~ /learn/var/     { deny all; }
location ^~ /envlog/daemon/ { deny all; }
location ^~ /book/tools/    { deny all; }
location ~ \.db$            { deny all; }
```

루트의 비밀 키 파일(`sso_secret.key`, `vapid.key`)도 문서루트 안에 있어 막지 않으면 URL 로 그대로
내려받힌다. PWA 파일 두 개는 갱신이 바로 퍼지도록·형식이 맞도록 따로 적는다:

```nginx
location ~ \.key$                 { deny all; }
location = /sw.js                 { add_header Cache-Control "no-cache"; }
location = /manifest.webmanifest  { default_type application/manifest+json; }
```

`learn/var/.htaccess` 가 같은 내용을 담고 있지만 **nginx 는 .htaccess 를 읽지 않는다.** 위 블록이
없으면 아무 보호도 걸리지 않는다. 그리고 learn 은 이수증을 50MB 까지 받으므로 server 블록에
`client_max_body_size 52m;` 가 있어야 한다(없으면 413).

### envlog (유닛 없음)

EnvLog 의 SSH 로그 엔진은 systemd 유닛이 없다 — `envlog/api.php` 가 필요할 때 띄우고 유휴면 스스로 끝난다.
서버에 `python3`(venv 모듈)만 있으면 되고, 웹 서버 계정이 `envlog/daemon/` 에 쓸 수 있어야 한다
(`venv/`·`var/` 가 생긴다). 자세한 건 `envlog/README.md` 의 배포 절.

### moodle-watch (systemd timer · path)

주간 배치·토큰 점검·화면의 '지금 다시 가져오기' 유닛과 문제 해결 체크리스트는 [moodle/README.md](moodle/README.md) 의 배포 절.

---

## 알려진 제약 / TODO

- [ ] book: 관리자 명단이 `db.php` 의 `BOOK_DEFAULT_ADMINS` 상수다(dti 와 같다). 화면에서 바꾸려면
      learn 처럼 `*_admins` 표로 옮긴다.
- [ ] book: 서버 이관 뒤 `book.service`·nginx 프록시·`book.db` 정리('배포' 절).
- [ ] dti: 자료 순서를 바꿀 수 없다(등록 순 고정). 필요해지면 `dti_materials` 에 정렬 컬럼 추가.
- [ ] dti: 응답의 `material_*`·`scan_*` 파생 키는 화면이 `materials`·`scans` 배열만 보게
      정리되면 뺄 수 있다. 지금은 카드 정렬과 멤버 점수가 그 키에 걸려 있다.
- [ ] slack 모듈 관리자 기능(회원 추가/삭제, 비번 초기화) 없음.
- [ ] Gmail 연동은 계정 1개 고정(`config.local.php`) 기반 — 다계정 지원은 `gmail_lib.php` 주석의
      "[향후 회원가입]" 부분에 걸이 남아 있음.
- [ ] `.bak-migrate/`는 예전 구조 백업(사용 안 함, 삭제 검토 가능).
- [ ] moodle: PAG 코스 슬라이드(folder 8882) 텍스트 추출과 일반 개발자 포럼 키워드 필터는 아직 없다.
      BBB 녹화는 수집 대상 아님.
- [ ] moodle: 요약 품질은 첫 몇 주 실제 리포트를 보고 `summarizer.py::SYSTEM` 을 손봐야 한다.

---

## 로컬에서 포털 전체 띄우기 (MySQL 없이)

MySQL 이 없는 PC 에서 포털과 모든 PHP 모듈을 SQLite 로 띄운다. **운영 코드는 그대로다** —
DB 를 여는 일곱 곳(`core/db.php`, access·learn·slack·slackai·dti·bluecart 의 db.php)이 `new PDO` 대신
`db_connect()`(`core/sqlite_compat.php`)를 부르고, 이 함수는 운영에서 `new PDO` 와 똑같다.

- 켜지는 조건: 루트 `config.php` 의 `db.driver` 가 `'sqlite'` **이고** PHP 내장 서버(`php -S`)·명령줄일 때.
  운영(php-fpm)에서는 config 가 어떻든 켜지지 않는다.
- 준비물: PHP 8.2 — `로컬_실행.bat` 맨 위 `set PHP=` 가 가리키는 자리에 둔다 (php.ini 에서 pdo_sqlite·sqlite3·mbstring·openssl·fileinfo·curl 켬).
- 실행: 루트 `로컬_실행.bat` 더블클릭 → `dev/local_setup.php`(설정 파일 생성·스키마 적용, 여러 번 돌려도 됨) →
  http://127.0.0.1:8780/ . 계정은 사내 13명, 처음 비밀번호 `blue$123`. 8765 는 파이썬 출장여비가 쓰고 있어 피했다.
- 데이터: `var/localdb/<DB이름>.sqlite` (gitignore). 지우고 다시 실행하면 빈 상태로 돌아간다.
- `local_setup.php` 가 만드는 것: SQLite 용 루트 `config.php`(이미 있으면 손대지 않고, driver 가 sqlite 가
  아니면 멈춘다), `bluecart/config/config.php`(포털 연동 표본 복사), bluecart `sql/*.sql` 과 studio
  `sql/apply.sh` 의 FILES 목록을 번역기로 적용(`_local_sql_applied` 에 기록).
- 문지기: `dev/local_router.php` 가 nginx deny 와 같은 것(설정·키·DB 파일·업로드 원본·배치 소스)을 막는다.
- 번역기 범위: 이 저장소가 쓰는 MySQL 문법만(DDL·ALTER·upsert·INSERT IGNORE·information_schema·SHOW·
  NOW/DATE_SUB/TIMESTAMPDIFF/FIELD/GROUP_CONCAT …). 문자열 비교는 `_ci` 처럼 대소문자를 무시한다.
  모르는 문법이 나오면 서버 창에 `[sqlite_compat]` 로 원문·번역문이 찍힌다 — 그걸 보고 규칙을 늘린다.
  한계: `ON UPDATE CURRENT_TIMESTAMP` 무시, 문자열 안 `\t` 같은 백슬래시 이스케이프는 글자 그대로.
- 안 되는 것: 슬랙(개인 토큰)·Gmail(IMAP)·웹훅·LLM 등 바깥 연동. (book 도 PHP 라 같이 뜬다 —
  교보문고·카카오 조회만 이 PC 의 PHP curl 에 CA 번들이 없어 비어 나온다.)
- 2026-10-21 확인: 포털 로그인·공지·일정, BlueCart 요청, BlueLearn 신청, BlueStudio 프로젝트 생성, BlueLedger
  출장여비(46건 이관), access·moodle·dti 화면과 그 화면들의 API 요청 전부 정상.

## 로컬 개발 팁

- `.gitignore`가 `*.md`를 전부 막아놨다(이 `readme.md`와 모듈별 `README.md`만 예외로 풀어둠 — 다른
  md 문서는 계속 개인 작업용으로 git에 안 올라간다). 모듈 README 를 새로 만들면 예외 줄도 더한다.
- PHP 파일 수정 후 `php -l 파일명`으로 문법 검사만이라도 하고 커밋할 것.
- 포털·slack은 `php -S 127.0.0.1:PORT`로 즉석 기동 가능(세션/DB만 붙어 있으면 됨).
- book·dti·learn 은 포털과 같은 앱이라 포털을 띄우면 같이 뜬다 — `php -S 127.0.0.1:PORT` 로 루트를
  서빙하고 `/book/index.php` 로 들어가면 된다. 포털 로그인이 있어야 화면이 뜬다.
- 주석은 거의 달지 않는다. 이름으로 설명하고, 주석은 "코드를 잘못 고치는 걸 막는
  정보"(동시성·보안·외부 제약)일 때만 그 줄 옆에 남긴다.

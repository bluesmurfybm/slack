# blue-iWorks — 사내 업무 포털

Bluesoft 사내 포털. 로그인 하나로 **BlueBooks(book, 도서구매신청)**, **DTI 발표(dti)**,
**BlueLearn(learn)**, **MoodleUp?(moodle)**, **업무현황판(slack 연동)**, **Gmail 뷰어**를 오가는 구조. 이 문서는 이어받아 작업할
개발자를 위한 현황 정리다.

## 전체 구조

```
D:\lms\slackapi\                 ← 포털(PHP) — 이 저장소의 루트
├── index.php                    로그인/대시보드/프로필 (SPA 한 페이지)
├── core/                        모듈들이 공유하는 포털 공용 소스 (아래 참고)
│   ├── auth.php                 세션·SSO·current_portal_user()
│   ├── db.php                   portal_db() · 테이블 자동 생성
│   ├── board.php                알림판 — 공지·중요 일정·포털 관리자
│   └── worksystems.php/.json    상단바 "업무 시스템" 목록의 렌더러 + 유일한 원본
├── config.php                   접속 정보 (gitignore, 루트에 그대로 둔다)
├── sso_secret.key               book 과 나눠 쓰는 HMAC 키 (자동 생성, 루트)
├── .sessions/                   PHP 세션 저장 경로 (루트)
├── api/                         login.php, logout.php, me.php
│                                notices.php, notice_file.php, events.php, admins.php
├── styles/                      default.css, board.css, wxfx.css, favicon.ico, logo-blue.png
├── dev/                         gen_tiles.py(날씨 타일 생성기) · cheer_test.php · kind_test.php(일정 분류 시험)
├── var/                         공지 첨부 원본 (.htaccess 로 직접 접근 차단, notice/ 는 gitignore)
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
│   ├── routes/                  identity · requests · certs · review · sites ·
│   │                            categories · policy · admins · catalog(추천·필수 강의)
│   ├── tools/                   migrate_from_learning.php (SQLite → MySQL 이관)
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
| `auth.php` | api, access, dti, learn, moodle, slack, bluecart |
| `db.php` | access, dti, learn, moodle (`add_column_if_missing()` 재사용) |
| `worksystems.php` / `.json` | index, dti, learn, moodle, slack/header, bluecart, book |
| `board.php` | api/notices·notice_file·events·admins |

모듈에서는 한 단계 위의 `core/` 를 부른다.

```php
require_once __DIR__ . '/../core/auth.php';        // 모듈 폴더에서
require_once __DIR__ . '/core/worksystems.php';    // 포털 루트(index.php)에서
```

**루트에 그대로 두는 것들** — `core/` 안에서는 `dirname(__DIR__)` 으로 짚는다.

- `config.php` — 서버마다 사람이 직접 만드는 파일이다. 자리를 옮기면 이미 돌고 있는
  설치본을 전부 손봐야 한다. `slack`, `learn`, `dti` 도 루트 기준으로 읽고 있다.
- `sso_secret.key` — `book/app.py` 가 `../sso_secret.key` 로 같은 파일을 직접 읽는다.
  자리를 옮기면 키가 새로 생겨 book 쪽 SSO 검증이 전부 깨진다.
- `.sessions/` — 옮기면 열려 있던 세션을 못 찾아 전원이 로그아웃된다.
- `var/` — 공지 첨부 원본. 직접 접근을 막는 `.htaccess` 가 여기 있다.

`book` 은 PHP 가 아니라 경로를 직접 적는다. 목록 원본을 옮겼으니 같이 고쳐 뒀다.

```python
WORK_SYSTEMS = os.path.join(BASE, "..", "core", "worksystems.json")
SSO_SECRET_PATH = os.path.join(BASE, "..", "sso_secret.key")   # 루트 그대로
```

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
  book `app.py` — 일곱 곳이다. **API는 다르다** — 401을 그대로 응답하고, 화면 JS가
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
  - 별도 프로세스인 book 은 PHP 를 못 쓰니 `app.py` 가 **같은 json 을 직접 읽어**
    `whoami` 로 내려주고 화면 JS(`renderWorkSystems`)가 그린다. 포털 트리가 안 보이면 목록만
    비고 화면은 정상 동작한다.

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

### 축하 폭죽

**경사 일정**이 있으면 들어올 때 한 번 폭죽이 터지고 무엇을 축하하는지 제목이
함께 뜬다. `board_event_kind()` 가 경사로 분류한 일정이면 저절로 걸린다.

**터뜨릴 날은 서버가 정해서 `cheer` 로 실어 준다**(`board_decorate_event`).
화면은 그 값만 보고 판단한다 — 주말·공휴일 셈을 브라우저로 내리지 않는다.

| `cheer` | 언제 | 문구 |
|---|---|---|
| `today` | 일정 당일 | 🎉 **축하합니다** |
| `early` | 일정이 주말·공휴일이라 그 앞 마지막 평일 | 🎉 **미리 축하합니다** |
| `null`  | 그 밖 | 안 터진다 |

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

## learn (BlueLearn · 강의 수강료 지원)

파이썬 `learning/`(FastAPI + SQLModel/SQLite, 포트 8002)을 **기능 그대로 PHP + MySQL 로 옮긴 것**이다
(dti 와 같은 이유 — 포털과 세션·DB 를 공유하면 SSO 쿠키 검증도, 별도 프로세스도, nginx 프록시
블록도 필요 없어진다).

- **스키마는 1:1 이다.** 테이블 이름만 `learning_*` → `learn_*` 로 바뀌었고 컬럼은 그대로다.
  **날짜를 VARCHAR 로 둔 건 의도다** — 신청 상태를 저장하지 않고 날짜 문자열 비교로 파생시키기
  때문이다(`lib/status.php::learn_derive_status`). 상태를 컬럼으로 들고 있으면 날짜를 고칠 때마다
  둘이 어긋난다.
- **PDO 는 모든 값을 문자열로 준다.** JS 에서 `"0"` 은 참이라, `is_free`·`archived` 를 그대로
  내보내면 무료/보관 행이 뒤집힌다. `REQUEST_INT_COLS` 로 캐스팅해서 내보내고 테스트가 이를 잠근다.
- **추천·필수 강의(`learn_catalog`)**: 관리자가 외부 플랫폼 강의를 등록해 **추천/필수** 등급을
  매기면 직원 화면에 목록으로 뜬다. 필수 강의는 개인 연간 한도를 쓰지 않고 승인 절차도 없다
  (`routes/requests.php`). 신청이 카탈로그에서 나오면 `catalog_id` 로 묶인다.
- **진도율은 만들어내지 않는다.** 외부 플랫폼의 실제 수강률은 알 방법이 없으므로, 막대는
  **수강 기간 경과율**이고 라벨도 `기간 N% 지남` 이다. "N시간 남음" 류 문구가 생기지 않는지
  테스트가 확인한다.
- **이수증은 문서루트 아래(`learn/var/uploads/`)에 있다.** nginx 차단 블록이 필수다 — 위
  [nginx](#nginx) 참고.

### 남은 전환 작업

파이썬 `learning/` 은 저장소에서 지웠다. 서버 정리는 `learn/tools/cutover_learning.sh` 가
순서대로 해 준다. **순서가 중요하다** — 이관보다 폴더 삭제가 먼저면 원본 SQLite 가 사라진다.

```bash
sudo -iu blueapp_core          # 저장소 소유 계정. 다른 계정이면 git 이 dubious ownership 으로 멈춘다
cd /home/blueapp_core && ./deploy.sh

./learn/tools/cutover_learning.sh             # 점검만 — 아무것도 쓰지 않는다
./learn/tools/cutover_learning.sh --migrate   # 백업 → 이관 → 대조
./learn/tools/cutover_learning.sh --remove    # 대조를 통과하면 learning/ 삭제
```

스크립트가 대신 막아 주는 것들이다.

- **실행 계정이 저장소 소유자가 아니면 멈춘다.** `safe.directory` 로 넘기면 `.git` 에 쓰지 못해
  곧 막히고, 통과하더라도 새로 생긴 파일이 서비스 계정 소유가 아니게 된다
- **`learn` 에서 만들어진 신청이 있으면 멈춘다.** 이관은 `learn_requests` 를 비우고 다시 채우므로
  추천·필수 강의로 들어온 신청(`catalog_id > 0`)이 있으면 그게 날아간다
- **이관 전에 백업을 뜬다** — MySQL `learn_*` 전체를 `.sql` 로, SQLite 원본을 사본으로
  (`learn/var/cutover-backup/`). 되돌리려면 `mysql <DB> < learn-<시각>.sql`
- **대조를 통과해야 지운다.** `verify_migration.php` 가 SQLite 와 MySQL 을 값 단위로 비교하고
  (신청·이수증·이력 전 컬럼, 이수증 파일 존재, 플랫폼·분류 누락, 관리자 명단), 불일치가 하나라도
  있으면 `--remove` 는 거부한다. 지우기 직전에도 `learning/` 을 통째로 tar 로 남긴다

`git pull` 은 추적 파일만 지우므로 `learning/var/`(SQLite·이수증 원본)는 남아 있다 — 이관의
원본이 여기다.

root 권한이 필요한 것들(`learning.service` 중지, nginx 블록 정리)은 스크립트가 건드리지 않고
마지막에 할 일로 찍어 준다. 서버 `config.php` 의 `links` 에서 `'learning'` 을 지우는 것도
잊지 않는다 — gitignore 라 `git pull` 로 오지 않는다.

끝나면 `cutover_learning.sh`·`migrate_from_learning.php`·`verify_migration.php` 를 지운다
(전부 일회성 도구다).

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

    // dti 의 발표자료 PDF 변환에 쓰는 LibreOffice 실행 파일. PATH 에서 찾는다.
    // 웹 서버의 PATH 에 없으면 절대경로로 바꾼다(이 파일 자체가 서버마다 따로 만드는 파일이다).
    'soffice' => 'soffice',
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

- `portal_users` — 포털 계정(이메일/비번해시/암호화된 슬랙 토큰/대시보드 배경 설정 `bg_pref`)
- `portal_admin`, `portal_notice`, `portal_notice_file`, `portal_event` — 알림판(아래 참고).
  `portal_notice.is_pinned` 는 `is_important` 로 이름이 바뀌었다(`db.php` 가 `CHANGE COLUMN` 으로
  한 번만 처리). 값은 그대로 넘어간다.
- `requests` — slack 유지보수 요청 목록(Slack Lists 동기화본)
- `schools`, `user_reads`, `user_pins`, `user_hides`, `local_assignments`, `sync_meta` — slack 부가기능
- `gmail_mails` — Gmail 캐시(계정별 구분, `account` 컬럼)
- `dti_topics`, `dti_presentations`, `dti_emotions`, `dti_fields`, `dti_related`,
  `dti_materials` — dti 모듈
- `school_access` — 대학별 접속·배포 정보(access 모듈). `schools` 가 마스터이고 여기는 상세라
  `school_id` 로 붙는다. 한 대학이 버전군별로 여러 행을 가질 수 있어(강원대 3.5 + 4.5)
  키는 `(school_id, grp)` 다.

컬럼 추가 마이그레이션은 전부 `add_column_if_missing()`(`core/db.php`)을 거쳐 동시 요청에도
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
location /book/    { proxy_pass http://127.0.0.1:8000/; }
location /bookapi/ { proxy_pass http://127.0.0.1:8000/bookapi/; }
```

(`proxy_set_header`·`client_max_body_size` 줄을 같이 둔다 — 업로드가 50MB 까지다.)
dti·learn·moodle 은 PHP 라 프록시 블록이 필요 없다. 대신 **문서루트 아래에 있으면 안 되는 것들을
막아둔다.** 배치 소스(`moodle/watch/`)와 업로드 보관함(`learn/var/`)이 그렇다 — 후자에는 이수증
원본이 들어가므로 URL 로 바로 열리면 안 된다:

```nginx
location ^~ /moodle/watch/ { deny all; }
location ^~ /learn/var/    { deny all; }
```

`learn/var/.htaccess` 가 같은 내용을 담고 있지만 **nginx 는 .htaccess 를 읽지 않는다.** 위 블록이
없으면 아무 보호도 걸리지 않는다. 그리고 learn 은 이수증을 50MB 까지 받으므로 server 블록에
`client_max_body_size 52m;` 가 있어야 한다(없으면 413).

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

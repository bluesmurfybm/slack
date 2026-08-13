# blue-iWorks — 사내 업무 포털

Bluesoft 사내 포털. 로그인 하나로 **도서구매신청(book)**, **DTI 발표주제(magazine)**,
**업무현황판(slack 연동)**, **Gmail 뷰어**를 오가는 구조. 이 문서는 이어받아 작업할
개발자를 위한 현황 정리다.

## 전체 구조

```
D:\lms\slackapi\                 ← 포털(PHP) — 이 저장소의 루트
├── index.php                    로그인/대시보드/프로필 (SPA 한 페이지)
├── auth.php, db.php, config.php 포털 세션·DB·SSO 헬퍼
├── api/                         login.php, logout.php, me.php
├── styles/                      default.css, favicon.ico, logo-blue.png
│
├── book/                        도서구매신청 — Python/FastAPI, 별도 프로세스(포트 8000)
│   ├── app.py
│   ├── index.html
│   └── styles/
│
├── magazine/                    DTI 발표주제 — Python/FastAPI, 별도 프로세스(포트 8001)
│   ├── app.py                   조립만(create_app 팩토리)
│   ├── core/                    config(pydantic-settings), db
│   ├── features/                identity · topics · material · notify
│   ├── web/                     index.html, static/(도메인별 js), styles/
│   ├── data/seed.json           초기 데이터 31건
│   ├── var/                     DB·업로드 (gitignore)
│   └── tests/
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

**book(8000)과 magazine(8001)만 다른 프로세스/포트**(FastAPI)다. **portal과 slack은 완전히 같은
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
  `auth.test`로 검증한 뒤 세션에 캐시한다. 포털 로그인이 없으면 `../index.php`로,
  토큰이 없거나 무효면 `../index.php?need_token=1`로 리다이렉트 → 포털이 알림과 함께 프로필
  화면을 띄운다.
- **book / magazine 모듈(다른 프로세스)**: 포털이 로그인 시 `blueiwork_id` 쿠키를 심는다 — 이메일+이름을
  HMAC-SHA256으로 서명한 값(`auth.php::issue_sso_cookie()`). book(Python, `app.py`)은 같은
  비밀키(`sso_secret.key`, 포털이 최초 실행 시 자동 생성)로 **서명만 검증**해서 이메일/이름을
  얻는다. book은 MySQL에 붙지 않는다 — 쿠키 자체가 신원 증명.
  - magazine도 같은 방식이다(`features/identity/auth.py`). 쿠키 형식·서명 키를 book과 공유하므로
    포털에서 한 번 로그인하면 셋 다 통한다.
  - **전제: 포털과 book/magazine이 같은 호스트**(포트만 달라도 됨)여야 브라우저가 쿠키를 같이
    보낸다. 다른 PC에서 띄우면 SSO가 동작하지 않는다.
  - book 쪽 로그아웃 링크는 포털의 `api/logout.php`를 GET으로 직접 연다(`api/logout.php`가
    POST면 JSON, GET이면 `index.php`로 리다이렉트하도록 나뉘어 있음).
- **공통 상단바**: `slack/header.php`(PHP include)와 `book/index.html`의 `.bw-topbar`가 시각적으로
  동일한 blue-iWorks 상단바(로고+사용자명+로그아웃)를 각자 방식으로 그린다. slack 하위 폴더
  페이지는 include 전에 `$__bwBase = '../';`를 반드시 설정해야 링크가 안 깨진다(폴더 깊이 보정용).

---

## book 권한 모델

- 신청자는 항상 로그인한 본인 — 프론트 피커도 본인만 뜨고, 서버(`app.py`)도 클라이언트가 보낸
  `applicant` 값을 무시하고 SSO 쿠키의 신원으로 강제 기록(`applicant_email` 컬럼).
- 수정/삭제: 본인 글이거나 관리자(`ADMIN_EMAIL` = `jian@bluesoft.co.kr`, `app.py`에 하드코딩)만 가능.
- 완료 처리(`done`)는 **관리자만** 가능(작성자 본인도 불가).
- 레거시 데이터(로그인 연동 이전 신청)는 이름→이메일 매핑으로 최선 노력 백필했음(`NAME_TO_EMAIL`).

---

## magazine (DTI 발표주제)

매거진(DI, MIT TR) 아티클 발표 주제를 관리한다. 원래 xlsx로 돌리던 걸 옮긴 것.

- **상태는 저장하지 않고 파생한다** — `done_date`면 발표완료, `presenter_email`이면 발표예정,
  둘 다 없으면 미지정(`features/topics/service.py`). 원본 xlsx에 "발표자·예정일이 있는데
  비고는 미지정"인 행이 실제로 있어서, 컬럼으로 저장하면 계속 어긋난다.
- **선점(claim)**: 미지정 주제를 일반 사용자가 직접 가져간다. 동시 선점은 조건부 UPDATE
  한 방으로 막고 409를 준다. 발표가 끝난 주제는 발표자가 비어 있어도 선점 대상이 아니다.
- **권한**: 주제 등록·수정·삭제·발표자 지정·발표완료 처리는 관리자만(`ADMIN_EMAILS`, 콤마 구분
  환경변수). 선점·선점취소·예정일 변경은 본인 또는 관리자. **판정은 항상 서버에서** 하고 화면은
  버튼을 감추기만 한다.
- **발표 자료**: 주제당 하나(파일 또는 링크). 발표자 본인이나 관리자만 올린다. 저장 파일명은
  서버가 만들고, HTML/SVG는 같은 오리진 인라인 시 XSS가 되므로 강제로 내려받기 처리한다
  (`features/material/storage.py`).
- **구성원 명단**: 발표자 지정 드롭다운용으로 `core/config.py`의 `MEMBERS`에 하드코딩.
  magazine은 포털 MySQL을 보지 않기 때문. 입·퇴사 시 이 목록을 고친다.
- **테스트**: `cd magazine && python -m pytest` (71건). 앱은 `create_app(settings)` 팩토리라
  테스트가 `Settings`만 갈아끼워 새 앱을 만든다.

---

## 로컬에서 새로 만들어야 하는 파일 (전부 gitignore됨 — git엔 없음)

| 파일 | 용도 | 비고 |
|---|---|---|
| `config.local.php` (루트) | 포털 SSO 토큰 암호화 키 | **자동 생성됨**(최초 실행 시) |
| `sso_secret.key` (루트) | book과 공유하는 SSO 서명 키 | **자동 생성됨**(최초 실행 시) |
| `slack/config.local.php` | Gmail IMAP 계정 정보 | **직접 생성 필요**, 아래 형식 |
| `book/config_local.py` | Slack 웹훅 URL(선택) | 없으면 알림 기능만 비활성 |
| `book/kakao_keys.json` | 카카오 도서검색 API 키(선택) | 없으면 검색 자동완성만 비활성 |
| `magazine/config_local.py` | Slack 웹훅 URL(선택) | `config_local.exam.py` 복사해서 사용 |

`slack/config.local.php` 형식:
```php
<?php
return [
    'gmail' => [
        'user'  => 'you@gmail.com',
        'pass'  => '앱 비밀번호(일반 비번 아님)',
        'label' => 'INBOX',
    ],
];
```

---

## 환경변수 / 설정값 확인 필요

- **`PORTAL_URL`** (book 실행 시 환경변수) — book이 "로그인 안 됨" 상태에서 리다이렉트할 포털 주소.
  기본값 `http://localhost/` 플레이스홀더 그대로면 실제 배포에서 안 맞을 수 있음.
- **`index.php`의 `LINKS.book` / `LINKS.magazine`** — 대시보드 타일이 여는 실제 주소.
  둘 다 `config.php`의 `links`에서 읽는다. magazine을 추가했으면 `'magazine' => 'http://호스트:8001'`
  한 줄이 있어야 한다(없으면 PHP 경고).
- **magazine 환경변수** — `PORTAL_URL`, `SLACK_URL`, `ADMIN_EMAILS`(콤마 구분), `DB_PATH`,
  `UPLOAD_DIR`, `SSO_SECRET_PATH`, `MAX_UPLOAD_MB`(기본 50). 전부 `core/config.py`의
  `Settings`(pydantic-settings)가 읽는다. **설정을 읽는 곳은 여기 한 군데다.**
  `DEV_LOGIN=1`은 포털 없이 화면을 보기 위한 개발 전용 스위치라 **운영에서는 절대 켜지 않는다**
  (켜면 로그인 없이 계정 전환 바가 뜬다).
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

컬럼 추가 마이그레이션은 전부 `add_column_if_missing()`(`slack/db.php`)을 거쳐 동시 요청에도
안전하게(이미 있으면 조용히 무시) 처리하도록 통일돼 있다. **새로 컬럼 추가 마이그레이션을 짤 때
"확인 후 ALTER" 패턴을 직접 쓰지 말 것** — 페이지 로드 시 여러 AJAX가 동시에 뜨면서 레이스가 난다.

---

## 배포 (systemd)

book·magazine은 각각 uvicorn 프로세스로 돈다. 유닛 파일은 서버에만 두고 저장소에는 올리지
않는다(실제 호스트명이 들어가기 때문). `/etc/systemd/system/magazine.service`:

```ini
[Unit]
Description=magazine (BlueUP-DTI 발표주제)
After=network-online.target

[Service]
WorkingDirectory=/home/blueapp_core/magazine
ExecStart=/home/blueapp_core/magazine/venv/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8001
Restart=always
User=blueapp_core
Environment=PORTAL_URL=http://포털주소/
Environment=SLACK_URL=http://포털주소/slack/lists.php
Environment=ADMIN_EMAILS=jian@bluesoft.co.kr

[Install]
WantedBy=multi-user.target
```

`DEV_LOGIN` 은 넣지 않는다 — 켜면 로그인 없이 계정 전환 바가 뜬다.

- `WorkingDirectory`가 `/home/blueapp_core/magazine`이고, DB·업로드가 그 아래 `var/`에 생긴다.
  실행 사용자(`blueapp_core`)에게 쓰기 권한이 있어야 한다.
- SSO 서명 키는 `../sso_secret.key`(= `/home/blueapp_core/sso_secret.key`)를 본다. book과 같은
  파일이다.
- 포털 공용 상단바 CSS는 `../styles/`를 마운트한다. 없으면 상단바만 스타일이 빠진 채 뜬다
  (book과 달리 magazine은 없어도 기동은 된다).

---

## 알려진 제약 / TODO

- [ ] book이 포털과 다른 호스트에 있으면 SSO 쿠키가 전달되지 않음 — 같은 서버로 이전 필요.
- [ ] `PORTAL_URL`, `LINKS.book` 플레이스홀더를 실제 주소로 확정.
- [ ] 관리자(`ADMIN_EMAIL`)가 book `app.py`에 하드코딩 — 여러 명이 되면 배열/DB 플래그로 전환 고려.
      magazine은 `ADMIN_EMAILS` 환경변수(콤마 구분)로 이미 분리해뒀다.
- [ ] magazine의 구성원 명단(`core/config.py` `MEMBERS`)이 포털 `portal_users`와 따로 논다 —
      입·퇴사 때 두 곳을 고쳐야 한다.
- [ ] slack 모듈 관리자 기능(회원 추가/삭제, 비번 초기화) 없음.
- [ ] Gmail 연동은 계정 1개 고정(`config.local.php`) 기반 — 다계정 지원은 `gmail_lib.php` 주석의
      "[향후 회원가입]" 부분에 걸이 남아 있음.
- [ ] `.bak-migrate/`는 예전 구조 백업(사용 안 함, 삭제 검토 가능).

---

## 로컬 개발 팁

- `.gitignore`가 `*.md`를 전부 막아놨다(이 `readme.md`만 예외로 풀어둠 — 다른 md 문서는 계속
  개인 작업용으로 git에 안 올라간다).
- PHP 파일 수정 후 `php -l 파일명`으로 문법 검사만이라도 하고 커밋할 것.
- 포털·slack은 `php -S 127.0.0.1:PORT`로 즉석 기동 가능(세션/DB만 붙어 있으면 됨).
  book은 `PORTAL_URL=http://127.0.0.1:PORT/ python -m uvicorn app:app --port 8098`.
- magazine은 포털 없이도 볼 수 있다 — `cd magazine && DEV_LOGIN=1 python -m uvicorn app:app --port 8001`
  로 띄우면 상단에 계정 전환 바가 뜬다. 도커도 있다: `cd magazine && docker compose up --build`.
  (Docker Desktop + WSL에서 `error getting credentials`가 나면
  `ln -s /Docker/host/bin/docker-credential-desktop.exe ~/.local/bin/docker-credential-desktop`)
- magazine 코드에는 주석을 거의 달지 않는다. 이름으로 설명하고, 주석은 "코드를 잘못 고치는 걸
  막는 정보"(동시성·보안·외부 제약)일 때만 그 줄 옆에 남긴다.

# blue-iWorks — 사내 업무 포털

Bluesoft 사내 포털. 로그인 하나로 **도서구매신청(book)**, **DTI 발표(magazine)**,
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
├── magazine/                    DTI 발표 — Python/FastAPI, 별도 프로세스(포트 8001)
│   ├── app.py                   조립만(create_app 팩토리)
│   ├── core/                    config(pydantic-settings), db(SQLModel)
│   ├── features/                identity · topics · material · notify
│   ├── web/                     index.html, static/(도메인별 js), styles/
│   ├── data/seed.json           초기 데이터 31건
│   ├── var/                     DB·업로드 (gitignore)
│   └── tests/
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

## magazine (DTI 발표)

매거진(DI, MIT TR) 아티클 발표 주제를 관리한다. 원래 xlsx로 돌리던 걸 옮긴 것.

- **상태는 저장하지 않고 파생한다** — `done_date`면 발표완료, `presenter_email`이면 발표예정,
  둘 다 없으면 미지정(`features/topics/service.py`). 원본 xlsx에 "발표자·예정일이 있는데
  비고는 미지정"인 행이 실제로 있어서, 컬럼으로 저장하면 계속 어긋난다.
- **DB 접근은 SQLModel ORM** — `core/db.py`의 `Topic` 모델 하나가 스키마의 원본이다.
  엔진은 `create_app`이 만들어 `app.state.engine`에 두고, 라우터는 `get_session` 의존성으로
  세션을 받는다. 마이그레이션은 모델에 있고 테이블에 없는 컬럼만 `ALTER TABLE`로 붙이는
  방식이라, 컬럼을 추가할 때 모델만 고치면 된다. **기존 행까지 값이 채워져야 하는 컬럼은
  `sa_column_kwargs={"server_default": ...}`를 반드시 준다** — 파이썬 기본값만으로는
  운영 DB의 기존 행에 NULL이 남는다(`tests/test_db.py`가 이걸 지킨다).
- **선점(claim)**: 미지정 주제를 일반 사용자가 직접 가져간다. 동시 선점은 조건부 UPDATE
  한 방으로 막고 409를 준다. 발표가 끝난 주제는 발표자가 비어 있어도 선점 대상이 아니다.
  숨김(`active=0`)·보관(`archived=1`) 주제도 선점 대상이 아니다.
- **노출과 보관**: `active`는 구성원 화면 노출 스위치, `archived`는 보관함이다. 목록 API가
  관리자가 아닌 요청에서 둘을 걸러낸다 — 화면에서만 숨기지 않는다.
- **발표 구분은 3단계**: `required`(필수) / `recommended`(권장) / `normal`(일반).
- **화면**: 구성원 화면은 내 활동 스트립 + 진행 레일(미지정 → 발표예정 → 자료준비 완료 →
  발표완료) + 리스트/카드 전환 + 상세 드로어. 관리자는 상단에서 관리자 화면으로 전환하면
  아티클 관리·보관함 탭이 뜬다. **'자료준비 완료'는 서버 상태가 아니라 화면에서 파생한다**
  (발표예정 + 자료 등록). 서버 `status`는 그대로 3단계다.
- **연관 아티클**(드로어): 분야·키워드·팀·매거진 일치로 점수를 매겨 상위 3건(`web/static/related.js`).
  난수를 쓰지 않는다 — 같은 두 주제는 언제 봐도 같은 점수여야 한다.
- **등록 폼 자동 입력**: 매거진을 고르면 그 매거진의 **가장 최근 호**(Volume 앞머리 숫자 →
  년도 → 등록 순) Volume/Page를 채운다. 등록 순(`id`)을 먼저 보면 안 된다 — xlsx에서 넘어온
  행의 `id`는 등록 시점이 아니라 시트 행 순서라서 DI가 279가 아니라 2024년 275호로 잡힌다.
  년도를 먼저 봐도 안 된다 — 년도는 비워 둘 수 있어서, 년도 없이 등록한 새 호가 옛 호보다
  뒤로 밀린다. 수정 중에는 채우지 않는다.
- **권한**: 주제 등록·수정·삭제·발표자 지정·발표완료 처리는 관리자만. 관리자 명단은
  `core/config.py` 의 `Settings.admin_emails` 기본값이 출처다. 선점·선점취소·예정일 변경은
  본인 또는 관리자. **판정은 항상 서버에서** 하고 화면은 버튼을 감추기만 한다.
- **발표 자료**: 주제당 하나(파일 또는 링크). 발표자 본인이나 관리자만 올린다. 저장 파일명은
  서버가 만들고, HTML/SVG는 같은 오리진 인라인 시 XSS가 되므로 강제로 내려받기 처리한다
  (`features/material/storage.py`).
- **구성원 명단**: 발표자 지정 드롭다운용으로 `core/config.py`의 `MEMBERS`에 하드코딩.
  magazine은 포털 MySQL을 보지 않기 때문. 입·퇴사 시 이 목록을 고친다.
- **테스트**: `cd magazine && python -m pytest` (82건). 앱은 `create_app(settings)` 팩토리라
  테스트가 `Settings`만 갈아끼워 새 앱을 만든다.

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
| `magazine/config_local.py` | Slack 웹훅 URL(선택) | `config_local.exam.py` 복사해서 사용 |
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

    // 대시보드/공통 헤더 드롭다운이 참조하는 외부 모듈 링크. book은 별도 프로세스라 절대주소 필요.
    // 실제 배포 주소가 다르면 config.local.php 에 'book_url' => '...' 을 넣어 덮어쓸 수 있음.
    // learn(BlueLearn)은 포털과 같은 PHP 앱이라 여기 주소가 없다 — index.php가
    // slack·access처럼 상대경로(learn/index.php)로 직접 건다.
    'links' => [
        'book' => $localCfg['book_url'] ?? 'book',
        'magazine' => $localCfg['magazine_url'] ?? 'magazine',
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
- **`index.php`의 `LINKS.book` / `LINKS.magazine`** — 대시보드 타일이 여는 실제 주소.
  둘 다 `config.php`의 `links`에서 읽는다. magazine을 추가했으면 `'magazine' => 'http://호스트:8001'`
  한 줄이 있어야 한다(없으면 PHP 경고).
- **magazine 환경변수** — `PORTAL_URL`, `SLACK_URL`, `DB_PATH`,
  `UPLOAD_DIR`, `SSO_SECRET_PATH`, `MAX_UPLOAD_MB`(기본 50). 전부 `core/config.py`의
  `Settings`(pydantic-settings)가 읽는다. **설정을 읽는 곳은 여기 한 군데다.**
  **관리자 명단(`ADMIN_EMAILS`)은 환경변수로 주지 않는다** — pydantic-settings 는 환경변수를
  필드 기본값보다 우선하므로, 한 번 넣어두면 `config.py` 에서 명단을 고쳐도 조용히 무시된다.
  명단은 `Settings.admin_emails` 기본값에서만 관리한다.
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
- `school_access` — 대학별 접속·배포 정보(access 모듈). `schools` 가 마스터이고 여기는 상세라
  `school_id` 로 붙는다. 한 대학이 버전군별로 여러 행을 가질 수 있어(강원대 3.5 + 4.5)
  키는 `(school_id, grp)` 다.

컬럼 추가 마이그레이션은 전부 `add_column_if_missing()`(`slack/db.php`)을 거쳐 동시 요청에도
안전하게(이미 있으면 조용히 무시) 처리하도록 통일돼 있다. **새로 컬럼 추가 마이그레이션을 짤 때
"확인 후 ALTER" 패턴을 직접 쓰지 말 것** — 페이지 로드 시 여러 AJAX가 동시에 뜨면서 레이스가 난다.

---

## 배포 (systemd)

book·magazine은 각각 uvicorn 프로세스로 돈다. 유닛 파일은 서버에만 두고 저장소에는 올리지
않는다(실제 호스트명이 들어가기 때문). `/etc/systemd/system/magazine.service`:

```ini
[Unit]
Description=magazine (BlueUP-DTI 발표)
After=network-online.target

[Service]
WorkingDirectory=/home/blueapp_core/magazine
ExecStart=/home/blueapp_core/magazine/venv/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8001
Restart=always
User=blueapp_core
Environment=PORTAL_URL=http://포털주소/
Environment=SLACK_URL=http://포털주소/slack/lists.php

[Install]
WantedBy=multi-user.target
```

`DEV_LOGIN` 은 넣지 않는다 — 켜면 로그인 없이 계정 전환 바가 뜬다.
`ADMIN_EMAILS` 도 넣지 않는다 — 넣으면 `config.py` 의 관리자 명단이 무시된다(위 환경변수 항목 참고).

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
      magazine은 `core/config.py` 의 `admin_emails`(frozenset)로 이미 분리해뒀다.
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

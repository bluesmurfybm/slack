# BlueUP-DTI 발표 주제 관리 앱 설계

- 날짜: 2026-08-11
- 브랜치: `amitoa`
- 대상 경로: `magazine/`

## 배경

BlueUP-DTI(Digital Technology Insight)는 사내에서 매거진(DI, MIT TR 등) 아티클을
골라 발표하는 프로그램이다. 현재는 `2025 BlueUP-DTI (Digital Technology Insight).xlsx`
스프레드시트로 관리한다. 시트는 `발표 예정 및 미지정`(15건), `발표완료`(16건) 두 개이고
컬럼은 `분야 / 제목(주제) / 중요 키워드 / 발표자 / 발표예정일 / 발표일 / 매거진 / Page /
년도 / Volume / 비고`이다.

스프레드시트 운영의 문제는 두 가지다. 발표자가 비어 있는 "미지정" 주제를 누가 가져갈지
파일을 열어 직접 고쳐야 하고, 동시에 편집하면 선점이 충돌한다.

이 스펙은 그 스프레드시트를 대체하는 웹 앱을 정의한다. 기존 `book/`(도서구매신청) 앱의
UI와 SSO 인증 골격을 재사용하되, 도서 전용 기능은 모두 걷어낸다.

## 요구사항

1. 관리자 계정이 있다.
2. 관리자가 발표 주제를 등록한다.
3. 주제마다 발표가 **필수 / 권장** 중 하나로 지정된다.
4. 일반 사용자는 미지정 주제를 **선점**해 발표자가 될 수 있고, 목록을 열람한다.
5. xlsx의 기존 31건을 초기 데이터로 적재한다.

## 접근

`book/` 앱의 골격(FastAPI + SQLite 단일 파일, 포털 SSO 쿠키 검증, SPA `index.html`,
포털 `styles/` 마운트)을 그대로 재사용하고 스키마·라우트·화면만 교체한다.

컬럼명만 바꿔 최소 수정하는 방식은 가격·교보문고·카카오 도서검색 로직이 잔존해
유지보수 부담이 남으므로 택하지 않는다. 신규 스택은 SSO·공통 스타일·배포 방식을
다시 만들어야 하므로 택하지 않는다.

## 구조

| 항목 | 값 | 비고 |
|---|---|---|
| 앱 | `magazine/app.py` | FastAPI + SQLite |
| 화면 | `magazine/index.html` | 단일 파일 SPA |
| DB | `magazine/magazine.db` | 최초 실행 시 `seed.json` 자동 적재 |
| 라우트 | `/magazineapi/*` | `book/`의 `/bookapi/*`와 동일 규칙 |
| 포트 | **8001** | `book/`이 8000 고정이라 충돌 회피 |
| 관리자 | `ADMIN_EMAILS = ["jian@bluesoft.co.kr"]` | 환경변수 `ADMIN_EMAILS`로 override |

인증은 `book/app.py`의 `verify_sso_cookie()` / `get_identity()` / `require_identity()`를
그대로 가져온다. 포털(PHP `auth.php`)이 심는 `blueiwork_id` 서명 쿠키를 `../sso_secret.key`로
검증하는 방식이며, 앱은 검증만 하고 로그인 화면을 갖지 않는다. 따라서 포털과 같은 호스트에
서빙되어야 한다(포트는 달라도 됨).

`book/`과 달리 시크릿 경로를 `SSO_SECRET_PATH` 환경변수로 override 할 수 있게 한다
(기본값은 동일한 `../sso_secret.key`). 개발 환경에는 이 파일이 없어 테스트가 불가능하기
때문이며, 운영 동작은 달라지지 않는다.

## 데이터 모델

```sql
CREATE TABLE topics(
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    field           TEXT,     -- 분야: UI/UX | Trend | Marketing | Etc
    title           TEXT NOT NULL,
    keywords        TEXT,     -- 중요 키워드
    magazine        TEXT,     -- DI | MIT TR | Etc
    volume          TEXT,
    page            TEXT,
    year            INTEGER,
    requirement     TEXT,     -- 'required' | 'recommended'
    team            TEXT,     -- App | LAB | SQUARE 등 사람이 아닌 배정 대상
    presenter       TEXT,     -- 발표자 표시명
    presenter_email TEXT,     -- 선점자 신원(SSO email)
    planned_date    TEXT,     -- 발표예정일 YYYY-MM-DD
    done_date       TEXT,     -- 발표일 YYYY-MM-DD
    note            TEXT,     -- 비고
    created_by      TEXT,
    created_at      TEXT
)
```

### 상태는 저장하지 않고 파생한다

`done_date`가 있으면 `발표완료`, 없고 `presenter_email`이 있으면 `발표예정`, 둘 다 없으면
`미지정`이다. 별도 `status` 컬럼을 두지 않는 이유는 xlsx가 이미 어긋난 상태를 갖고 있기
때문이다 — `발표자=김태주, 발표예정일=2025-09-08`인데 `비고=미지정`인 행이 존재한다.
파생하면 이런 불일치가 구조적으로 생기지 않는다.

xlsx의 `비고`는 상태로 해석하지 않고 `note`에 원문 그대로 보존한다.

### `team`을 따로 두는 이유

xlsx의 `발표자` 컬럼에는 사람 이름(`김태주`)과 팀(`App`, `LAB`, `SQUARE`)이 섞여 있다.
팀은 발표자가 아니라 "이 팀에서 누군가 가져가라"는 배정 힌트다. 임포트 시
`App|LAB|SQUARE`는 `team`으로, 나머지는 `presenter`로 나눈다. `presenter_email`은
`book/app.py`의 `NAME_TO_EMAIL` 매핑으로 채우고, 매핑에 없으면 비워 둔다.

## 권한

| 동작 | 관리자 | 일반 |
|---|---|---|
| 주제 등록 / 수정 / 삭제 | O | X |
| 필수·권장 지정 | O | X |
| 주제 선점 | O | O |
| 선점 취소 | 전체 | 본인 것만 |
| 발표완료 처리 | O | X |
| 열람 | O | O |

`is_admin`은 서버가 SSO 이메일을 `ADMIN_EMAILS`와 대조해 판정한다. 화면은 `whoami`가
돌려준 `is_admin`으로 버튼을 감추기만 하고, **권한 검사는 항상 서버에서 한다.**

## API

모두 `require_identity` 의존성을 가지며, 미로그인은 401이다.

| 메서드 | 경로 | 설명 |
|---|---|---|
| GET | `/` | `index.html`. 미로그인 시 포털로 리다이렉트 |
| GET | `/magazineapi/whoami` | `{email, name, color, is_admin, portal_url, slack_url}` |
| GET | `/magazineapi/topics` | 전체 목록(파생 상태 포함) |
| POST | `/magazineapi/topics` | 주제 등록 — 관리자만 |
| PUT | `/magazineapi/topics/{id}` | 주제 수정 — 관리자만 |
| DELETE | `/magazineapi/topics/{id}` | 삭제 — 관리자만 |
| POST | `/magazineapi/topics/{id}/claim` | 선점. body에 `planned_date` 선택 |
| POST | `/magazineapi/topics/{id}/release` | 선점 취소 — 본인 또는 관리자 |
| POST | `/magazineapi/topics/{id}/complete` | 발표완료 처리(`done_date` 지정) — 관리자만 |

### 동시 선점 방지

`claim`은 조건부 갱신 한 방으로 처리한다 — `UPDATE topics SET presenter_email=?, ...
WHERE id=? AND (presenter_email IS NULL OR presenter_email='')`. 임포트된 행의
`presenter_email`이 빈 문자열일 수 있으므로 `IS NULL`만으로는 부족하다. 갱신 행 수가
0이면 **409 Conflict**를 돌려준다.
두 사람이 같은 주제를 동시에 누르면 나중 요청만 실패한다.

## 화면

`book/index.html`의 뼈대를 유지한다: 상단바(`topbar`), 헤더(`header.site`), 툴바,
카드 목록, 시트 모달(`.sheet`), 토스트, 확인 다이얼로그, 유저 메뉴.

**교체**

- 툴바 필터: `분야 / 매거진 / 상태(미지정·발표예정·발표완료) / 필수·권장 / 검색어`
  \+ `내가 선점한 것만` 토글
- 목록 행: 제목, 분야·키워드 배지, `매거진 Vol.N p.NN`, 발표자(또는 팀), 예정일,
  상태 배지, 필수/권장 배지

**추가**

- 미지정 행의 `내가 발표할게요` 버튼 → `claim`
- 본인이 선점한 행의 `취소` 버튼 → `release`
- 관리자 전용 `주제 등록` 버튼 → 시트 모달 재사용

**제거**

- 카카오 도서검색 자동완성(`booksearch`, `searchBooks`, `pickBook`, `lastBookResults` 등)
- 교보문고 링크(`resolve_kyobo_url`, `kyobo_url`)
- 가격·지원금 입력과 표시(`displayPrice`, `formatPriceInput`, `parsePriceInput`)
- 신청자 필터(`buildApplicantFilter`) → 발표자 드롭다운은 두지 않고 위의
  `내가 선점한 것만` 토글로 대체한다. 31건 규모에서 발표자 목록 드롭다운은 값이 적다.

## 초기 데이터

`magazine/import_xlsx.py`를 만들어 xlsx → `seed.json` 변환을 스크립트로 남긴다
(재실행 가능해야 하고, 변환 규칙이 코드로 드러나야 한다). `openpyxl`이 없는 환경이므로
표준 라이브러리 `zipfile` + `xml.etree`로 읽는다.

변환 규칙:

- 엑셀 날짜 serial은 **1899-12-30 기준**으로 변환한다. 확인: `45838` → `2025-06-30`,
  `45845` → `2025-07-07`.
- `발표완료` 시트 행은 `done_date`를 채운다.
- `requirement`는 xlsx에 없는 신규 항목이므로 **전부 `recommended`(권장)** 로 초기화한다.
- 총 31건(예정·미지정 15 + 완료 16).

### 숫자 셀의 부동소수 아티팩트 처리

xlsx는 숫자 셀의 원시값을 그대로 갖고 있어 `Volume`에 `20.202500000000001` 같은 값이
들어 있다(sheet1 r11, r12 — 화면 표시는 `20. 2025`). `275. Oct-Nov`, `278`처럼 문자열인
행과 섞여 있다.

규칙: 원시값이 float로 파싱되면 `f"{v:g}"`로 유효자릿수만 남겨 문자열화하고
(`20.202500000000001` → `20.2025`), 파싱되지 않으면 원문을 유지한다. 같은 규칙을
`Page`, `년도`에도 적용한다. `년도`만 정수로 캐스팅한다.

### xlsx는 저장소에 넣지 않는다

`2025 BlueUP-DTI (Digital Technology Insight).xlsx`는 **외부 입력**으로 취급한다.
저장소에 커밋되는 산출물은 `seed.json`이고, `import_xlsx.py`는 변환 규칙을 코드로
남겨 두기 위한 문서 역할이다. 원본 xlsx가 갱신되면 담당자가 로컬에서 다시 돌려
`seed.json`을 갱신·커밋한다. (바이너리를 공개 저장소에 넣지 않기 위함 —
`bluesmurfybm/slack`은 public이다.)

## 정리 대상

`magazine/`에서 삭제: `backfill_kyobo.py`, `backfill_thumbnail.py`,
`kakao_keys.example.json`.

Slack 웹훅(`_load_slack_webhook`, `notify_slack`)은 **새 주제 등록 시 공지** 용도로만
남긴다. 미지정 주제가 올라온 걸 알려야 선점이 일어나므로 값이 있다.

`magazine/seed.json`에 남아 있는 도서구매신청 데이터(실명·가격 포함)는 DTI 데이터로
전량 교체한다.

## Docker 테스트 환경

### 왜 개발 전용 로그인이 필요한가

이 앱은 로그인 화면이 없다. 포털(PHP `auth.php`)이 심은 `blueiwork_id` 쿠키를 검증만 한다.
따라서 컨테이너만 띄우면 **로그인할 방법이 없어 아무 화면도 볼 수 없다.**

포털을 함께 띄우는 선택지는 불가능하다. 포털은 MySQL을 요구하고(`db.php`의 PDO mysql DSN),
접속정보가 담긴 `config.php`는 `.gitignore` 대상이라 저장소에 없다.

그래서 **개발 전용 로그인 shim**을 넣는다.

- `POST /magazineapi/devlogin` — body `{email, name}`. 포털과 **똑같은 형식**의 서명 쿠키를
  발급한다: `b64url(email \t name \t color \t exp)` + `.` + `hmac_sha256(payload, secret)`.
- `DEV_LOGIN=1` 일 때만 라우트가 등록된다. 기본값은 꺼짐이라 운영에는 존재하지 않는다.
- 검증 경로(`verify_sso_cookie`)는 손대지 않는다. 스텁은 "너는 누구인가"만 대신하고,
  이후 인증·권한 로직은 운영과 동일하게 탄다.
- 개발 모드일 때 화면 우상단에 계정 전환 셀렉터를 띄운다(관리자 `jian@` / 일반 사용자 몇 명).
  관리자와 일반 사용자 권한 차이를 클릭 몇 번으로 확인하기 위함이다.

### 구성

- `magazine/Dockerfile` — `python:3.12-slim`, `requirements.txt` 설치, `uvicorn`으로 8001 기동
- `magazine/docker-compose.yml` — 서비스 하나. `8001:8001`, `DEV_LOGIN=1`,
  `ADMIN_EMAILS=jian@bluesoft.co.kr`, `SSO_SECRET_PATH=/app/data/sso_secret.key`
- 이름 있는 볼륨을 `/app/data`에 마운트해 `magazine.db`와 시크릿을 컨테이너 재시작 후에도 유지
- 엔트리포인트가 시크릿 파일이 없으면 랜덤 생성 (포털 `auth.php`의 `sso_secret()`과 동일한 동작)

기동은 `cd magazine && docker compose up --build` → `http://localhost:8001`.

## 검증

### 환경 제약

이 개발 환경에는 `fastapi`/`uvicorn`이 시스템에 설치되어 있지 않고, 포털이 생성하는
`sso_secret.key`도 없다. 따라서 검증은 **스크래치패드 venv + FastAPI `TestClient`**로
수행한다(venv에 `fastapi 0.128.8`, `httpx` 설치 확인 완료). 브라우저로 실제 포털 SSO를
태우는 확인은 사내 포털 호스트에서만 가능하므로 아래 (B)로 분리한다.

이를 위해 `SSO_SECRET_PATH`를 환경변수로 override 가능하게 만든다(기본값은 기존과 동일한
`../sso_secret.key`). 테스트는 임시 시크릿 파일을 가리키고 직접 서명한 쿠키를 넣는다.

### (A) 이 환경에서 자동 검증 — `magazine/test_app.py`

1. `python3 -m py_compile magazine/app.py magazine/import_xlsx.py`
2. `seed.json` 건수 31, JSON 파싱 성공, 날짜가 `YYYY-MM-DD` 형식
3. `Volume`에 `20.202500000000001` 같은 원시 float가 남아 있지 않을 것
4. 쿠키 없이 `GET /magazineapi/topics` → **401**
5. 일반 사용자 쿠키로 `POST /magazineapi/topics` → **403** (서버 권한 검사)
6. 관리자(`jian@`) 쿠키로 `POST /magazineapi/topics` → 201, 목록에 `미지정`으로 등장
7. 일반 사용자로 `claim` → 상태 `발표예정`, `presenter_email`이 본인
8. 다른 사용자가 같은 주제에 `claim` → **409**
9. 선점자 본인 `release` → 다시 `미지정`. 제3자의 `release` → **403**
10. `done_date` 채운 뒤 상태가 `발표완료`로 파생되는지
11. 만료된 쿠키 / 서명이 틀린 쿠키 → **401**

### (B) Docker로 화면 확인

`cd magazine && docker compose up --build` → `http://localhost:8001`

1. 컨테이너가 기동하고 `/`가 200으로 응답
2. 개발 로그인 셀렉터에서 관리자(`jian@`) 선택 → `주제 등록` 버튼이 보임
3. 주제를 등록하면 목록에 `미지정`으로 뜸
4. 일반 사용자로 전환 → `주제 등록` 버튼이 사라짐
5. 미지정 행에서 `내가 발표할게요` → `발표예정`으로 바뀌고 본인 행에만 `취소` 노출
6. 다시 관리자로 전환 → 발표완료 처리 가능
7. 필터(분야·매거진·상태·필수/권장·검색·내가 선점한 것만) 동작
8. `docker compose restart` 후에도 데이터가 남아 있음(볼륨)

### (C) 포털 호스트에서 최종 확인

`DEV_LOGIN` 없이 기동한 뒤:

1. SSO 쿠키 없이 `/` 접근 → 포털로 리다이렉트
2. 포털 로그인 후 접근 → 실제 계정 이름·권한으로 동작
3. `book/`(8000)과 `magazine/`(8001) 동시 기동

## 저장소 반영

`.gitignore`에 `book/` 항목과 대칭으로 다음을 추가한다.

```
magazine/config_local.py
magazine/magazine.db
```

주의: 현재 `magazine/`은 `book/`을 `cp -a`한 상태라 `seed.json`과 `index.html` 148행에
실명·가격이 포함된 도서구매 신청 데이터가 그대로 들어 있다. 저장소가 public이므로
**두 파일을 DTI 데이터로 교체하기 전에는 `magazine/`을 스테이징하지 않는다.**

## 범위 밖

- 발표 일정 캘린더 뷰, 알림 예약
- 매거진 원문 링크·썸네일
- 발표 자료 업로드
- 통계·대시보드

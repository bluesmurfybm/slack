# book (BlueBooks · 도서구매신청)

파이썬 `app.py`(FastAPI + SQLite `book.db`, 포트 8000)를 **기능 그대로 PHP + MySQL 로 옮긴 것**이다
(dti·learn 과 같은 이유 — 포털과 세션·DB 를 공유하면 SSO 쿠키 검증도, 별도 프로세스도, nginx 프록시
블록도 필요 없어진다). 2026-10-30 이관.

- **모양은 dti 와 같다** — `lib/`·`routes/` 에 `book_` 접두사 전역 함수, 라우트는 `['status','data']`
  를 돌려주고 출력은 `api.php` 의 `book_send()` 한 곳. 화면 JS 는 FastAPI 시절 경로(`/bookapi/…`)를
  그대로 쓰고 `static/core.js::bookApiURL()` 이 `api.php?p=…` 로 바꾼다.
- **표는 `book_requests` 하나**(`db.php`). 파이썬 `requests` 표와 컬럼이 같고 `yes24_url` 만 뺐다(교보문고로
  바뀐 뒤 아무도 안 읽던 컬럼). 날짜는 `YYYY-MM-DD` 문자열(learn·dti 와 같다). PDO 가 문자열로 주는
  `done`·`price` 는 `lib/requests.php::book_row_out()` 이 캐스팅해 내보낸다.
- **권한** — 신청자는 항상 로그인한 본인(클라이언트가 보낸 `applicant` 는 보지 않고 세션 신원을
  `applicant_email` 에 기록). 수정/삭제는 본인 글이거나 관리자. 완료 처리(`done`)는 **관리자만**.
  관리자 명단은 `db.php` 의 `BOOK_DEFAULT_ADMINS`(dti 의 `DTI_DEFAULT_ADMINS` 와 같은 자리).
- **구성원 명단·뱃지 색은 `portal_users`** 다(`routes/identity.php::book_members`). 파이썬 시절 코드에
  박혀 있던 13명 명단·색상표·`NAME_TO_EMAIL` 은 없앴다. 신청자 칩도 포털 계정만 보인다. 계정이 없는
  이름(퇴사자)은 칩이 없고 목록·합계에만 보이며, 이름 해시로 색을 만들고, 본인 판정은 못 하니 관리자만
  고칠 수 있다.
- **표가 비면 `seed.json`(원본 엑셀 127건)을 넣는다.** 이름→이메일은 `portal_users` 로 맞춘다.
  **운영 이관은 시드가 아니라 `tools/migrate_from_sqlite.php` 다** — 실제 신청은 `book.db` 에 쌓여
  있다(2026-10 기준 138건). 첫 화면이 먼저 열려 시드가 들어갔으면 `--force` 로 비우고 다시 넣는다.
  ```bash
  php book/tools/migrate_from_sqlite.php --db=/home/blueapp_core/book/book.db --dry-run
  php book/tools/migrate_from_sqlite.php --db=/home/blueapp_core/book/book.db --force
  ```
  id 를 그대로 유지한다. 오래된 `book.db`(`applicant_email` 컬럼 없음)도 받는다 — 이름으로 이메일을 맞춘다.
- **바깥 조회(`lib/lookup.php`)** — 교보문고 상품 URL(검색 결과 첫 상품, 등록·제목/저자 수정 때 동기
  호출, 5초 타임아웃)과 카카오 책 검색(도서명 자동완성·표지). 둘 다 curl 이고 실패하면 조용히 빈 값이다.
  윈도 개발 PC 의 PHP 는 CA 번들이 없어 둘 다 SSL 오류로 비어 나온다 — 리눅스 서버에서는 된다.
- **설정은 `config.php` 두 키** — `book_slack_webhook`(신청 알림, 없으면 알림만 꺼짐),
  `kakao_rest_api_key`(없으면 `book/kakao_keys.json` 을 예전처럼 읽고, 그것도 없으면 자동완성만 꺼짐).
  파이썬 시절 `book/config_local.py` 의 `SLACK_WEBHOOK_URL` 을 `config.local.php` 의
  `book_slack_webhook` 으로 옮기면 된다.
- **디자인** — dti 의 토큰(`--cos50` 파랑, `--r8` 라운드, Pretendard + Space Grotesk h1, 카드 목록)을
  그대로 따르고 **페이지 배경(`#FAF8F1`)만 book 의 원래 베이지**를 유지했다. Orbit 폰트·지브라 줄은 뺐다.

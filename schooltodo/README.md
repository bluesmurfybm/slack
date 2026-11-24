# schooltodo (Coursemos TaskSheet · 대학 일괄 할일)

대학마다 하나씩 해야 하는 일(예: 취약점 조치)을 **탭**으로 만들고, 탭 안에서 대학별 조치 상태를 체크한다.
화면은 `schooltodo/index.php`, 포털 로그인한 사람은 누구나 보고 고친다.

## 구조
- **모양은 book·dti 와 같다** — `lib/`·`routes/` 에 `schooltodo_` 접두사 전역 함수, 라우트는 `['status','data']` 를
  돌려주고 출력은 `api.php` 한 곳.
- **대학 목록은 `schools`(EnvHub 와 같은 표)** 의 `active=1` 행이다. 따로 입력하지 않는다. 같은 이름에 버전이
  여럿이면 행도 여럿이다. **VPN** 열은 EnvHub 의 `school_access.vpn` 을 보여 주기만 한다(접속정보가 두 벌이면 합쳐서,
  표가 없으면 빈 값). 고치려면 EnvHub 에서 고친다. 대학 이름 뒤 괄호의 **학교 코드**(`code`)는 `school_access.repo` 의
  저장소 이름이고, 없으면 `schools.dev` 의 moodler.kr 앞머리다.
- **표는 셋** — `schooltodo_sheets`(탭), `schooltodo_cells`(탭 × 대학의 상태·메모), `schooltodo_cell_history`(셀이
  바뀌기 전 상태·메모·갱신한 사람·시각). 셀 행이 없는 대학은 `미조치` 다.
- **이력** — 상태나 메모가 실제로 바뀔 때만 바뀌기 전 값을 남기고, 셀마다 최근 10건(`SCHOOLTODO_HISTORY_MAX`)만 둔다.
  같은 값을 다시 보내면 이력도 갱신 시각도 그대로다. 화면에서는 갱신 열을 누르면 보인다.
- **상태** — `미조치` → `진행중` → `개발반영` → `운영반영`, 그리고 `해당없음`. 진행률은 `운영반영 / (전체 − 해당없음)`.
- **탭** — 탭마다 ⋮ 메뉴에서 이름·설명 수정, 링크 복사, 보관, 삭제를 한다. 보관한 탭은 탭 줄에서 빠지고
  머리의 **보관함** 에서 보고, 꺼내거나 지운다. 목록 API(`GET /sheets`)는 보관한 것을 빼고 주므로 봇·스킬(apply-svn-projects 등)에는
  끝난 할일이 보이지 않는다. 화면은 `all=1` 로 전부 받아 `archived_at` 으로 가른다.
- **표** — 항상 버전별로 나뉜다. 열 머리 ▾ 메뉴에서 정렬·거르기를 하고, 상태·메모 열 메뉴에서는 지금 보이는 대학을 일괄 변경한다.
  **버전별 일괄 변경**(보기 줄 오른쪽)은 무들 버전을 여러 개 골라 그 버전 대학 전부의 상태를 바꾼다 — 거르기·숨김과 무관.
  버전 묶음 머리줄의 ▾ 도 그 버전 전부를 바꾸는 지름길이다. 해당 없는 버전(예: 3.2)을 통째로 `해당없음` 으로 돌릴 때 쓴다.
  `해당없음 숨기기`(기본 켬, 브라우저에 기억)가 켜져 있으면 해당없음 대학은 표에서 빠지고, 상태 거르기에서 해당없음을
  고르면 그때만 보인다. 일괄 변경은 보이는 대학만 대상이라 숨긴 해당없음은 건드리지 않는다.
- **화면** — 상태는 고르는 즉시, 메모는 칸을 벗어나거나 Enter 를 누르면 저장된다. 단추는 역할별로 나뉜다 — 머리(엑셀(CSV) 내려받기·보관함·새 할일),
  탭의 ⋮(수정·링크 복사·보관·삭제), 진행률 줄(갱신·자동), 보기 줄(검색·상태 거르기 | 해당없음 숨기기·버전별 일괄 변경).
- **따라가기** — `static/sync.js` 가 30초마다 탭 목록과 지금 탭의 행을 다시 받아 다른 사람(봇)이 바꾼 것만 다시 그린다.
  표 안의 칸을 편집 중이면 칸을 벗어날 때까지 미루고, 창이 숨겨지면 멈췄다가 다시 보이면 바로 한 번 받는다.
  `자동 갱신` 체크는 기본이 꺼져 있어 `지금 갱신` 단추로만 받고, 켜면 30초마다 받는다(브라우저에 기억).

## API
화면과 AI 도구가 같은 API 를 쓴다. 따로 발급하는 키는 없다 — AI 도구도 포털 계정으로 `/api/login.php` 에 로그인해
받은 세션 쿠키로 부른다(EnvHub 를 읽는 issh·safepush·ai-coach 와 같은 방식). 갱신한 사람은 로그인한 계정 이름으로 남는다.

| 호출 | 하는 일 |
|---|---|
| `GET api.php?p=/sheets` | 탭 목록과 상태별 대학 수. 보관한 탭은 `&all=1` 일 때만 |
| `POST api.php?p=/sheets` `{title, description}` | 탭 만들기. 같은 이름이 있으면 409 |
| `GET api.php?p=/sheets/{id}` | 탭 + 대학별 행. `&status=미조치` 로 거른다 |
| `PUT api.php?p=/sheets/{id}` `{title, description, archived}` | 이름·설명 수정. `archived: true/false` 로 보관·꺼내기(`archived_at` 에 시각) |
| `PUT api.php?p=/sheets/{id}/cells` `{items: [...]}` | 대학별 상태·메모 채우기 |
| `GET api.php?p=/sheets/{id}/cells/{school_id}/history` | 그 대학 셀의 이전 값, 최근 것부터 |
| `GET api.php?p=/sheets/{id}/versions` | 버전별 대학 상태·갱신 날짜(키·상태는 영문 코드). `&ver=4.5`·`&status=todo` 로 거른다. **토큰으로도 부를 수 있다**(아래) |
| `PUT api.php?p=/sheets/{id}/status` `{items: [...], by}` | 대학별 상태 변경(외부용, 상태는 영문 코드·한글 둘 다). **쓰기 토큰으로도 부를 수 있다**(아래) |
| `DELETE api.php?p=/sheets/{id}` | 탭 삭제 |

`items` 한 건은 `{school_id}` 또는 `{school, ver}` 로 대학을 가리키고 `status`·`note` 중 보낸 것만 바꾼다.
이름이 같은 대학이 여럿인데 `ver` 가 없으면 409, 한 건이라도 오류면 전부 저장하지 않고 `items[n]:` 으로 알려 준다.

### 버전별 조회 토큰 (`/sheets/{id}/versions` 전용)
대시보드·외부 서버처럼 포털 계정으로 로그인하기 어려운 곳을 위해 **이 읽기 전용 경로 하나만** Bearer 토큰을 받는다.
시트 목록·쓰기·삭제 등 나머지는 지금처럼 로그인 세션만 받는다(`schooltodo_is_token_route()`).
상태를 바꾸려면 아래 **상태 변경 토큰**(쓰기 토큰)을 따로 쓴다.

- 토큰: 루트 `config.php` 의 `'schooltodo_api_token' => '…'` (없으면 환경변수 `SCHOOLTODO_API_TOKEN`). 비면 토큰 호출은 꺼진다.
  EnvHub 토큰(`envhub_api_token`)과 따로 둔다 — 이쪽은 상태만 보는 토큰이라 비밀번호가 실리는 EnvHub 와 섞지 않는다.
- HTTPS 요청만 인정한다(localhost 예외). 비교는 `hash_equals()`.

```bash
curl -s -H "Authorization: Bearer $TOKEN" "https://<포털>/schooltodo/api.php?p=/sheets/1/versions"
curl -s -H "Authorization: Bearer $TOKEN" "https://<포털>/schooltodo/api.php?p=/sheets/1/versions&ver=4.5&status=todo"
```

응답 — 버전은 숫자 순(3.9 → 3.10 → 4.5), 버전이 빈 학교는 맨 뒤. `total`·`counts` 는 `status` 로 거르기 전 기준이다.
셀이 없는 대학은 `todo`, `updated_at`·`updated_by` 는 빈 값이다.

**키와 상태는 영문 코드다**(이 경로만 — 화면이 쓰는 다른 API 는 한글 그대로). 한글 이름은 `status_label` 에 있고,
`?status=` 는 영문 코드와 한글을 둘 다 받는다(`SCHOOLTODO_STATUS_CODES`).

| 코드 | 상태 |
|---|---|
| `todo` | 미조치 |
| `in_progress` | 진행중 |
| `dev_applied` | 개발반영 |
| `prod_applied` | 운영반영 |
| `not_applicable` | 해당없음 |

```json
{"id": 1, "title": "jQuery 3.5.1", "description": "", "archived_at": "", "total": 260,
 "counts": {"todo": 120, "in_progress": 10, "dev_applied": 5, "prod_applied": 100, "not_applicable": 25},
 "versions": [
   {"ver": "3.9", "total": 80, "counts": {"todo": 30, …},
    "schools": [{"school_id": 12, "name": "가대", "code": "gadae", "status": "prod_applied", "status_label": "운영반영",
                 "updated_at": "2026-11-20 10:00:00", "updated_by": "홍길동"}, …]}
 ]}
```

### 상태 변경 토큰 (`PUT /sheets/{id}/status`)
봇·배포 스크립트가 로그인 없이 조치 상태를 바꿀 수 있게 **쓰기 토큰**을 따로 둔다. 읽기 토큰으로는 바꿀 수 없다.

- 토큰: 루트 `config.php` 의 `'schooltodo_api_write_token' => '…'` (없으면 환경변수 `SCHOOLTODO_API_WRITE_TOKEN`). 비면 꺼진다.
- 쓰기 토큰으로 부를 수 있는 것은 `PUT /sheets/{id}/status` 와 `GET /sheets/{id}/versions` 둘뿐이다
  (`schooltodo_is_write_token_route()`). 시트 만들기·수정·삭제·화면용 `/cells` 는 로그인 세션만 받는다.
- HTTPS(또는 localhost)만, 비교는 `hash_equals()` — 읽기 토큰과 같다.

`items` 한 건은 `/cells` 처럼 `{school_id}` 또는 `{school, ver}` 로 대학을 가리키고, **`status` 는 꼭 있어야 한다**
(영문 코드 `todo`·`in_progress`·`dev_applied`·`prod_applied`·`not_applicable` 또는 한글). `note` 는 보낼 때만 바꾼다.
갱신한 사람은 토큰 호출이면 본문 `by`(60자까지, 없으면 `API`), 로그인 세션이면 `by` 를 무시하고 계정 이름이다.
한 건이라도 오류면 전부 저장하지 않고 `items[n]:` 으로 알려 주며, 이력은 화면에서 바꿀 때와 똑같이 남는다.

```bash
curl -s -X PUT -H "Authorization: Bearer $WRITE_TOKEN" -H "Content-Type: application/json" \
  "https://<포털>/schooltodo/api.php?p=/sheets/1/status" \
  -d '{"by": "safepush", "items": [
        {"school_id": 12, "status": "prod_applied", "note": "3.5.1 반영"},
        {"school": "한림대", "ver": "3.5", "status": "dev_applied"}]}'
```

응답 — 바뀐(또는 보낸) 대학만, 상태는 영문 코드.
```json
{"updated": 2,
 "schools": [{"school_id": 12, "name": "가대", "ver": "3.9", "code": "gadae", "status": "prod_applied",
              "status_label": "운영반영", "note": "3.5.1 반영", "updated_at": "2026-11-24 10:00:00", "updated_by": "safepush"}, …]}
```

`tools/tasksheet.py` 가 로그인과 호출을 묶어 둔 명령이다. 계정은 `~/.config/safepush/iworks.json`
(`{"email", "password"}`, `IWORKS_CREDENTIALS` 로 바꾼다), 포털 주소는 `IWORKS_URL` 이다. 시트는 번호나 이름으로 가리킨다.

```bash
python3 schooltodo/tools/tasksheet.py sheets            # --all 이면 보관한 시트도
python3 schooltodo/tools/tasksheet.py create "jQuery 3.5.1" --description "전 대학 반영"
python3 schooltodo/tools/tasksheet.py rows "jQuery 3.5.1" --status 미조치
python3 schooltodo/tools/tasksheet.py fill "jQuery 3.5.1" --school 한림대 --ver 3.5 --status 운영반영 --note "3.5.1 반영"
python3 schooltodo/tools/tasksheet.py archive "jQuery 3.5.1"     # unarchive 로 꺼낸다
```

## 포털 등록
`core/worksystems.json`(`key: schooltodo`), `styles/icons/schooltodo.svg` → `python dev/gen_favicons.py`.

## 테스트
`docker exec -u 1000:1000 -w /var/www/repos/slack php82 php vendor/bin/phpunit --testsuite schooltodo`
— `slackapi_test` 의 `schooltodo_*`·`schools` 를 매번 비운다(`SCHOOLTODO_TEST_DB` 로 바꾼다).

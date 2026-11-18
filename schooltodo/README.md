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
- **표는 둘** — `schooltodo_sheets`(탭), `schooltodo_cells`(탭 × 대학의 상태·메모). 셀 행이 없는 대학은 `미조치` 다.
- **상태** — `미조치` → `진행중` → `개발반영` → `운영반영`, 그리고 `해당없음`. 진행률은 `운영반영 / (전체 − 해당없음)`.
- **탭** — 탭마다 ⋮ 메뉴에서 이름·설명 수정, 링크 복사, 보관, 삭제를 한다. 보관한 탭은 탭 줄에서 빠지고
  탭 줄 오른쪽 **보관함** 에서 보고, 꺼내거나 지운다. 목록 API(`GET /sheets`)는 보관한 것도 함께 주고 `archived_at` 으로 가른다.
- **표** — 항상 버전별로 나뉜다. 열 머리 ▾ 메뉴에서 정렬·거르기를 하고, 상태·메모 열 메뉴에서는 지금 보이는 대학을 일괄 변경한다.
- **화면** — 상태는 고르는 즉시, 메모는 칸을 벗어나거나 Enter 를 누르면 저장된다. `엑셀(CSV) 내려받기` 는 지금 탭 전체를 내려받는다.
- **따라가기** — `static/sync.js` 가 30초마다 탭 목록과 지금 탭의 행을 다시 받아 다른 사람(봇)이 바꾼 것만 다시 그린다.
  표 안의 칸을 편집 중이면 칸을 벗어날 때까지 미루고, 창이 숨겨지면 멈췄다가 다시 보이면 바로 한 번 받는다.

## API
화면과 AI 도구가 같은 API 를 쓴다. 따로 발급하는 키는 없다 — AI 도구도 포털 계정으로 `/api/login.php` 에 로그인해
받은 세션 쿠키로 부른다(EnvHub 를 읽는 issh·safepush·ai-coach 와 같은 방식). 갱신한 사람은 로그인한 계정 이름으로 남는다.

| 호출 | 하는 일 |
|---|---|
| `GET api.php?p=/sheets` | 탭 목록과 상태별 대학 수 |
| `POST api.php?p=/sheets` `{title, description}` | 탭 만들기. 같은 이름이 있으면 409 |
| `GET api.php?p=/sheets/{id}` | 탭 + 대학별 행. `&status=미조치` 로 거른다 |
| `PUT api.php?p=/sheets/{id}` `{title, description, archived}` | 이름·설명 수정. `archived: true/false` 로 보관·꺼내기(`archived_at` 에 시각) |
| `PUT api.php?p=/sheets/{id}/cells` `{items: [...]}` | 대학별 상태·메모 채우기 |
| `DELETE api.php?p=/sheets/{id}` | 탭 삭제 |

`items` 한 건은 `{school_id}` 또는 `{school, ver}` 로 대학을 가리키고 `status`·`note` 중 보낸 것만 바꾼다.
이름이 같은 대학이 여럿인데 `ver` 가 없으면 409, 한 건이라도 오류면 전부 저장하지 않고 `items[n]:` 으로 알려 준다.

`tools/tasksheet.py` 가 로그인과 호출을 묶어 둔 명령이다. 계정은 `~/.config/safepush/iworks.json`
(`{"email", "password"}`, `IWORKS_CREDENTIALS` 로 바꾼다), 포털 주소는 `IWORKS_URL` 이다. 시트는 번호나 이름으로 가리킨다.

```bash
python3 schooltodo/tools/tasksheet.py sheets
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

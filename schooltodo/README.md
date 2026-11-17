# schooltodo (Coursemos TaskSheet · 대학 일괄 할일)

대학마다 하나씩 해야 하는 일(예: 취약점 조치)을 **탭**으로 만들고, 탭 안에서 대학별 조치 상태를 체크한다.
화면은 `schooltodo/index.php`, 포털 로그인한 사람은 누구나 보고 고친다.

## 구조
- **모양은 book·dti 와 같다** — `lib/`·`routes/` 에 `schooltodo_` 접두사 전역 함수, 라우트는 `['status','data']` 를
  돌려주고 출력은 `api.php` 한 곳.
- **대학 목록은 `schools`(EnvHub 와 같은 표)** 의 `active=1` 행이다. 따로 입력하지 않는다. 같은 이름에 버전이
  여럿이면 행도 여럿이다.
- **표는 둘** — `schooltodo_sheets`(탭), `schooltodo_cells`(탭 × 대학의 상태·메모). 셀 행이 없는 대학은 `미조치` 다.
- **상태** — `미조치` · `진행중` · `완료` · `해당없음`. 진행률은 `완료 / (전체 − 해당없음)`.
- **화면** — 상태는 고르는 즉시, 메모는 칸을 벗어나거나 Enter 를 누르면 저장된다. `엑셀(CSV) 내려받기` 는 지금 탭 전체를 내려받는다.

## 봇 API
포털 세션 대신 `X-Api-Key` 헤더로 부른다. 키는 `config.local.php` 에 두고 `config.php` 가 넘긴다. 키가 없으면 봇 접근은 꺼진다.

```php
// config.local.php
'schooltodo_bot_key' => '<임의의 긴 문자열>',
// config.php
'schooltodo_bot_key' => $localCfg['schooltodo_bot_key'] ?? null,
```

| 호출 | 하는 일 |
|---|---|
| `GET api.php?p=/sheets` | 탭 목록과 상태별 대학 수 |
| `POST api.php?p=/sheets` `{title, description}` | 탭 만들기. 같은 이름이 있으면 409 |
| `GET api.php?p=/sheets/{id}` | 탭 + 대학별 행. `&status=미조치` 로 거른다 |
| `PUT api.php?p=/sheets/{id}` `{title, description}` | 이름·설명 수정 |
| `PUT api.php?p=/sheets/{id}/cells` `{items: [...]}` | 대학별 상태·메모 채우기 |
| `DELETE api.php?p=/sheets/{id}` | 탭 삭제. **API 키로는 못 한다**(화면에서만) |

`items` 한 건은 `{school_id}` 또는 `{school, ver}` 로 대학을 가리키고 `status`·`note` 중 보낸 것만 바꾼다.
이름이 같은 대학이 여럿인데 `ver` 가 없으면 409, 한 건이라도 오류면 전부 저장하지 않고 `items[n]:` 으로 알려 준다.
갱신한 사람은 `봇` 으로 남는다.

```bash
curl -X PUT 'https://<포털>/schooltodo/api.php?p=/sheets/3/cells' \
  -H 'X-Api-Key: <키>' -H 'Content-Type: application/json' \
  -d '{"items":[{"school":"한림대","ver":"3.5","status":"완료","note":"3.5.1 반영"}]}'
```

## 포털 등록
`core/worksystems.json`(`key: schooltodo`), `styles/icons/schooltodo.svg` → `python dev/gen_favicons.py`.

## 테스트
`docker exec -u 1000:1000 -w /var/www/repos/slack php82 php vendor/bin/phpunit --testsuite schooltodo`
— `slackapi_test` 의 `schooltodo_*`·`schools` 를 매번 비운다(`SCHOOLTODO_TEST_DB` 로 바꾼다).

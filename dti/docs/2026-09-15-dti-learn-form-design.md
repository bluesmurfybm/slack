# dti 를 learn 형태로 — 클래스 없는 함수 구조와 단위 테스트 유지

- 날짜: 2026-09-15
- 브랜치: `magazine_v2`
- 대상: `dti/` (DTI 발표, magazine 의 PHP 이관본)

## 1. 배경

`dti/` 는 `Kernel` 이 `Request` 를 받아 `Controller` → `Service` → `Repository` 를
거쳐 `Response` 를 돌려주는 객체지향 구조다. 같은 저장소의 다른 PHP 모듈
(`learn/`, `slack/`, `access/`)은 `lib/` 와 `routes/` 에 전역 함수를 나열한다.

| | dti | learn |
|---|---|---|
| 파일 수 | 37 (`src/` 35 + `autoload.php` + `bootstrap.php`) | 17 |
| 소스 줄 수 | 2,475 | 1,992 |
| 한 곳을 고칠 때 경유하는 계층 | 4단 | 2단 |
| 테스트 | 169건 (라우트 경유 129건) | 0건 |

`Controller` 7개 중 5개는 20~53줄이며 위임만 한다. `Controller/RelatedController`
는 22줄 전체가 "존재 확인 후 리포지터리 결과 반환"이다.

dti 의 외부 접점은 `api.php` 가 제공하는 REST API 하나다. 화면(`dti/static/*.js`)은
그 API 만 호출하고, 경로는 `api.php?p=/topics/3` 형태로 들어온다.

이 문서는 dti 를 learn 구조로 옮기면서 REST API 와 테스트 169건을 유지하는 방법을 정한다.

## 2. 목표와 비목표

### 목표

1. `lib/` 와 `routes/` 에 전역 함수를 나열하는 learn 배치로 옮긴다
2. `api.php` 하나로 REST API 를 제공한다 — 경로·메서드·응답 JSON 은 현행 그대로다
3. 소스에 도메인 클래스를 남기지 않는다
4. 테스트 169건을 전부 유지한다
5. 파일 37 → 20, 경유 계층 4단 → 2단

### 비목표

- **API 경로·요청·응답 JSON** — 변경 없음. `dti/static/*.js` 도 변경 없음
- **`dti_*` 테이블 스키마** — 컬럼 추가·삭제·형변경 없음
- **`NULL` 과 `''` 의 구분** — dti 는 "값 없음"과 "빈 값"을 구분한다(readme 199행).
  learn 의 `NOT NULL DEFAULT ''` 관례를 적용하지 않는다. 행을 배열로 바꾼 뒤에도 같다
- **`Migration/MagazineImporter`** — 실데이터 이관 후 삭제할 일회성 코드다.
  `lib/migrate.php` 로 위치만 옮기고 내용은 유지한다
- **`dti/index.php`, `dti/static/`, `dti/styles/`** — 변경 없음

## 3. 규약

### 3.1 라우트 함수는 값을 반환한다

learn 규약과 다른 유일한 지점이다. learn 의 `jsend()` 는 출력 후 `exit` 하므로
라우트 함수에 반환값이 없고, 라우트 단위 테스트를 붙일 수 없다. dti 의 라우트는
배열을 반환하고, 출력은 `api.php` 에서만 한다.

```php
// lib/http.php
function dti_json($data, $status = 200) {
    return ['status' => $status, 'data' => $data];
}

function dti_file($path, $name) {
    return ['status' => 200, 'file' => $path, 'name' => $name];
}

// api.php — 출력하는 유일한 자리
dti_send(dti_handle($ctx, $req));
```

### 3.2 인자는 배열 둘 — 환경과 요청

learn 은 `learn_route_requests($pdo, $identity, $seg, $method)` 로 넘기고 본문은
전역 `static` 캐시(`body_json()`)에서 읽는다. dti 는 전역 캐시를 쓰지 않으므로
(3.4 참조) 배열 둘을 인자로 넘긴다.

```php
function dti_route_topics(array $ctx, array $req): array
```

```php
// $ctx — 환경
[
    'config'   => array,        // 3.3 참조
    'pdo'      => PDO,
    'identity' => ['email' => string, 'name' => string] | null,
    'mover'    => callable(string $from, string $to): bool,     // 기본 move_uploaded_file
    'webhook'  => callable(string $url, array $payload): void,  // 기본 curl
]

// $req — 요청
[
    'method' => string,   // GET|POST|PUT|DELETE ('HEAD' 는 GET 으로 접는다)
    'seg'    => string[], // api.php?p=/topics/3 → ['topics', '3']
    'body'   => array,
    'query'  => array,
    'files'  => array,    // $_FILES 모양
]
```

`mover` 와 `webhook` 은 테스트 대역 자리다. 업로드가 아닌 파일에는
`move_uploaded_file()` 이 실패하고, 테스트는 슬랙으로 실제 요청을 보내면 안 된다.
현재 `Service/Storage` 와 `Service/Notifier` 가 같은 목적의 생성자 인자를 갖고 있다.

### 3.3 설정 — 어휘는 상수, 조정값은 배열

```php
// db.php — 상수
const DTI_TEAMS = ['APP', 'SQUARE', 'LAB'];
const DTI_MAGAZINES = ['DI', 'MIT TR', 'Etc'];
const DTI_REQUIREMENTS = ['required', 'recommended', 'normal'];
const DTI_EMOTIONS = ['like', 'apply', 'easy', 'new'];
const DTI_DEFAULT_FIELDS = ['UI/UX', 'Marketing', 'Trend', 'AX', 'Etc'];
const DTI_DEFAULT_ADMINS = ['jian@bluesoft.co.kr', 'kimhy@bluesoft.co.kr'];
const DTI_DEFAULT_TEAMS = [ /* Config::DEFAULT_TEAMS 의 이메일 => 팀 목록 */ ];
```

상수에는 `DTI_` 접두사를 붙인다. 포털 `index.php` 는 learn 도 dti 도 include 하지
않으므로 런타임 충돌은 없으나, 같은 PHP 프로세스에서 두 모듈의 테스트를 함께 돌릴
경우에 대비한다.

테스트가 갈아끼워야 하는 값은 배열로 둔다.

```php
// db.php
// $over 로 포털 config.php 의 'db' 와 'dti_slack_webhook' 이 들어온다.
// 테스트는 같은 자리에 slackapi_test 와 임시 업로드 폴더를 넣는다.
function dti_config(array $over = []): array {
    $cfg = $over + [
        'db'             => [],   // $over 로 반드시 들어와야 한다
        'upload_dir'     => __DIR__ . '/var/uploads',
        'max_upload_mb'  => 50,
        'admin_emails'   => DTI_DEFAULT_ADMINS,
        'teams_by_email' => DTI_DEFAULT_TEAMS,
        'slack_webhook'  => null,
        'portal_url'     => '..',
        'slack_url'      => '../slack/lists.php',
    ];
    $cfg['admin_emails'] = array_map('strtolower', $cfg['admin_emails']);
    dti_config_check($cfg);
    return $cfg;
}
```

`dti_config_check()` 는 `teams_by_email` 에 `DTI_TEAMS` 에 없는 팀이 있으면
`InvalidArgumentException` 을 던진다. 현재 `Config` 생성자가 하는 검사이며
`ConfigTest::test_팀_매핑에_없는_팀이_들어가면_거부한다` 가 이를 확인한다.

`Config::isAdmin()`·`teamsOf()`·`maxUploadBytes()` 는 `dti_is_admin($cfg, $email)`,
`dti_teams_of($cfg, $email)`, `dti_max_upload_bytes($cfg)` 가 된다.

### 3.4 전역 `static` 캐시를 쓰지 않는다

learn 의 `learn_policy()` 와 `body_json()` 은 `static` 으로 값을 캐시한다.
테스트 간에 상태가 남으므로 dti 에는 두지 않는다. 값은 `$ctx` 로 받는다.

## 4. 최종 배치

```
dti/
  api.php              프런트 컨트롤러 — 출력하는 유일한 자리
  index.php            화면
  bootstrap.php        require 목록 + 타임존
  db.php               연결 · 스키마 · 시드 · 설정
  guard.php            포털 세션 신원
  lib/
    http.php           DtiError · 응답 · 요청 파싱 · 입력 검증
    topics.php         아티클 읽기/쓰기 · 상태 판정 · 화면용 배열
    presentations.php  발표 행 생성 · 삭제 · 배정 해제
    emotions.php       반응 집계 · 토글
    fields.php         분야
    related.php        연관 아티클 저장 · 점수 계산
    score.php          멤버 점수 집계
    slots.php          발표자료 슬롯 판정
    storage.php        업로드 저장 · 열기 · Content-Disposition
    notify.php         슬랙 웹훅
    members.php        구성원 명단
    migrate.php        magazine 이관 (일회성)
  routes/
    topics.php  materials.php  fields.php  scores.php  identity.php
  tools/  static/  styles/  var/
```

파일 수 **37 → 20** (`api.php`·`index.php` 제외, 양쪽 동일 기준).

### 대응표

| 현재 | 이행 후 |
|---|---|
| `Http/Request` `Http/Response` `Http/Input` `Http/ApiException` | `lib/http.php` |
| `Repository/TopicRepository` `Service/TopicPresenter` `Entity/Topic` | `lib/topics.php` |
| `Repository/PresentationRepository` `Service/PresentationService` `Entity/Presentation` | `lib/presentations.php` |
| `Repository/EmotionRepository` | `lib/emotions.php` |
| `Repository/FieldRepository` | `lib/fields.php` |
| `Repository/RelatedRepository` `Service/RelatedService` | `lib/related.php` |
| `Service/ScoreService` | `lib/score.php` |
| `Service/Slot` | `lib/slots.php` |
| `Service/Storage` | `lib/storage.php` |
| `Service/Notifier` `Service/Webhook` `Service/CurlWebhook` | `lib/notify.php` |
| `Identity/Members` | `lib/members.php` |
| `Migration/MagazineImporter` | `lib/migrate.php` |
| `Controller/TopicController` `Controller/EmotionController` `Controller/RelatedController` | `routes/topics.php` |
| `Controller/MaterialController` | `routes/materials.php` |
| `Controller/FieldController` | `routes/fields.php` |
| `Controller/ScoreController` | `routes/scores.php` |
| `Controller/IdentityController` | `routes/identity.php` |
| `Kernel` | `dti_handle()` (`lib/http.php`) |
| `Config` `Database` `Schema` | `db.php` |
| `Identity/Identity` `Identity/SessionIdentity` | `guard.php` |
| `autoload.php` | 삭제 — `bootstrap.php` 의 `require_once` 목록 |

`RelatedController` 와 `EmotionController` 가 `routes/topics.php` 로 가는 것은
둘 다 `/topics/{id}/…` 하위 경로이기 때문이다.

## 5. 함수 이름 규칙

- 접두사는 `dti_` (learn 의 `learn_` 과 같은 자리)
- 라우트는 `dti_route_<주제>($ctx, $req)`
- 읽기는 `dti_<주제>_find` / `_list`, 쓰기는 `_insert` / `_update` / `_delete`
- 판정은 `dti_<주제>_<판정>` (예: `dti_topic_status`)
- 검증 실패는 반환하지 않고 `DtiError` 를 던진다 (learn 의 `want_*` 와 같다)

## 6. 테스트 이행

기준선은 169건 green (363 assertions).

테스트 로직은 바뀌지 않는다. 바뀌는 것은 응답을 읽는 방식과 조립 방식이다.

| 현재 | 이행 후 | 줄 수 |
|---|---|---|
| `$res->status` | `$res['status']` | 81 |
| `$res->data` | `$res['data']` | 115 |
| `$res->filePath` | `$res['file']` | 2 |
| `$res->fileName` | `$res['name']` | 2 |
| `\Dti\Service\Storage::disposition(...)` | `dti_disposition(...)` | 1 |
| `new Identity($email, $name)` | `['email' => $email, 'name' => $name]` | 10 |
| `new Kernel(...)->handle(new Request(...))` | `dti_handle($ctx, $req)` | `TestCase::call()` 한 곳 |
| `new Config(db: ..., uploadDir: ...)` | `dti_config([...])` | `TestCase::makeConfig()` + `ConfigTest` |
| `RecordingWebhook implements Webhook` | 호출을 배열에 쌓는 클로저 | `Support/RecordingWebhook.php` |

고쳐야 하는 테스트 파일 9개: `EmotionTest` `IdentityTest` `KernelRoutingTest`
`MaterialTest` `NotifyTest` `ScoreTest` `Http/InputTest` `Support/RecordingWebhook`
`Support/TestCase`. `Http/InputTest.php` 는 `tests/InputTest.php` 로 올린다.

`TestCase` 의 DB 헬퍼(`truncate`, `seedPortalUsers`, `makeTopic`,
`makePresentation`)와 느린 fsync 대응(픽스처를 트랜잭션으로 묶고 `TRUNCATE` 대신
`DELETE` 사용)은 유지한다.

설정 파일도 함께 고친다.

- `composer.json` — `autoload.psr-4` 의 `Dti\` 항목 삭제. `autoload-dev` 의
  `Dti\Tests\` 는 유지 (PHPUnit 이 테스트 클래스를 찾아야 한다)
- `phpunit.xml` — `<source><include>` 의 `<directory>dti/src</directory>` 를
  `<directory>dti/lib</directory>`·`<directory>dti/routes</directory>` 와
  `<file>dti/db.php</file>`·`<file>dti/guard.php</file>` 로 (파일은 `<file>`)
- `dti/tests/bootstrap.php` — `dti/bootstrap.php` 를 require

## 7. 작업 순서

테스트가 직접 참조하는 클래스는 `Kernel`·`Request`·`Response`·`Input`
·`ApiException`·`Identity`·`Config`·`Notifier`·`MagazineImporter` 뿐이다.
`Controller`·`Service`·`Repository` 는 어느 테스트도 직접 참조하지 않는다.
따라서 잎부터 뿌리로 올라가면 대부분의 단계에서 테스트를 수정할 필요가 없다.

아래 "고쳐야 하는 테스트" 열의 "없음"은 테스트를 수정하지 않아도 그대로 통과해야
한다는 뜻이다. 모든 단계에서 169건이 계속 돌아간다.

| 단계 | 내용 | 고쳐야 하는 테스트 |
|---|---|---|
| 0 | 기준선 169건 green 확인 | — |
| 1 | `lib/storage.php` `lib/slots.php` `lib/notify.php` | `NotifyTest` `MaterialTest` `RecordingWebhook` |
| 2 | `lib/emotions.php` `lib/fields.php` `lib/related.php` `lib/score.php` | 없음 |
| 3 | `lib/topics.php` `lib/presentations.php` — 엔티티를 배열로 | 없음 |
| 4 | `lib/members.php` `guard.php` | `IdentityTest` |
| 5 | `db.php` — `Config`·`Database`·`Schema` | `ConfigTest` `SchemaTest` `TestCase` |
| 6a | `Controller/*` → `routes/*` 함수로 하나씩<br>(`fields` → `scores` → `identity` → `materials` → `topics`).<br>`Kernel` 과 `Response` 클래스는 아직 유지 | 없음 |
| 6b | `Kernel` → `dti_handle()`, `Http/*` → `lib/http.php`.<br>응답이 객체에서 배열로 — 단언 196줄 치환 | 9개 파일 |
| 7 | `lib/migrate.php` | `MigrateTest` |
| 8 | `autoload.php` 삭제, `bootstrap.php` require 목록,<br>`composer.json`·`phpunit.xml`·`tests/bootstrap.php` | — |
| 9 | readme 의 dti 절 갱신 | — |

3단계와 6b 가 가장 크다. 6a 와 6b 를 나누는 것은 "함수로 옮기는 변경"과
"응답 모양을 바꾸는 변경"을 같은 커밋에 섞지 않기 위해서다.

각 단계 끝에 `php vendor/bin/phpunit` 으로 169건 green 을 확인하고 커밋한다.
전체 실행에는 수 분이 걸리므로 단계 중간에는 `--filter` 로 좁혀 돌린다.

## 8. 위험

| 위험 | 대응 |
|---|---|
| 엔티티를 배열로 바꾸며 `NULL`/`''` 구분이 무너진다 | 2절 비목표. `TopicReadTest` 의 `assertNull` 단언들이 3단계에서 잡는다 |
| 전역 함수 이름이 다른 모듈과 충돌한다 | 함수는 `dti_`, 상수는 `DTI_` 접두사. 포털은 두 모듈을 함께 include 하지 않는다 |
| 전역 `static` 캐시가 유입된다 | 3.4 에서 금지. 유입되면 테스트가 실행 순서에 따라 깨진다 |
| 단계 중간에 API 응답이 바뀐다 | 라우트 테스트 129건이 상태코드와 본문을 확인한다 |
| 로컬 `config.php` 가 없어 테스트가 전부 에러난다 | `.gitignore` 대상이라 저장소에 없다. readme 의 템플릿으로 만든다 |

## 9. 범위 밖

readme 의 "남은 전환 작업"(실데이터 이관, 포털 링크 교체, `magazine/` 정리,
`dti_topics` 죽은 컬럼 정리)은 별건이다. 이 작업은 스키마도 API 도 건드리지 않으므로
그 앞뒤 어느 쪽에 놓아도 된다.

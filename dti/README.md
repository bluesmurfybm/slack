# dti (BlueMagazine · DTI 발표)

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
  `dti_related`, `dti_materials`, `dti_rounds`. slackapi DB 를 포털·slack·learn 과 공유하므로 맨이름을 쓸 수 없다.
  컬럼 추가는 `add_column_if_missing()` 을 거친다.
- **회차는 아티클이 가리킨다** — `dti_topics.round_id` 가 `dti_rounds` 를 가리키고 `NULL` 이
  회차 없음이다. 회차는 여러 매거진 호를 묶은 발표 단위이고, "호 담기"(`POST /rounds/{id}/issue`)는
  매거진·Volume 이 글자 그대로 같은 아티클을 한 번에 넣는 편의 기능일 뿐이다. **보관·숨김은 회차에
  두지 않는다** — 일괄 보관·숨김(`POST /rounds/{id}/topics`)은 아티클의 `archived`·`active` 를
  한꺼번에 바꾸므로, 목록·예약 차단·통계는 아티클 값만 보면 된다. 대신 일괄 해제는 따로 보관·숨김해
  둔 아티클까지 푼다. 회차별 통계는 화면이 `APP.topics` 로 계산한다.
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
- **화면은 탭마다 페이지다** — 구성원은 `dti/index.php`, 관리자 탭은 `dti/admin/*.php` 한 장씩이고,
  페이지 목록은 `lib/pages.php` 의 `DTI_PAGES` 하나다(탭 링크·스크립트 목록·접근 판정이 여기서 나온다).
  관리자가 아닌 사람이 `admin/` 을 열면 `dti_page_begin()` 이 목록으로 돌려보낸다. 페이지 안은 여전히
  JS 가 API 로 그리며, `<body data-page>` 로 진입 함수를 고른다. **필터·열린 상세·통계 회차·점수 연도는
  주소 쿼리에 있다**(`static/url.js`) — 필터는 `replaceState`, 상세 열기만 `pushState` 라 뒤로가기가
  상세를 닫는다. `core.js` 의 `dtiApiURL()` 은 `/magazineapi/topics/3` 을 `DTI_BASE + api.php?p=/topics/3`
  으로 바꾼다(`DTI_BASE` 는 `admin/` 에서 `../`). `views/*.php` 는 `$page` 없이 직접 열리면 404 다.
- **연관 점수**는 `dti_related` 에 저장하고 등록·수정·삭제 때 다시 계산한다. 파이썬은 기동할
  때도 계산했지만 PHP 에는 기동 훅이 없으므로, 배점 상수를 바꾸면
  `php dti/tools/rebuild_related.php` 를 한 번 돌린다.
- **런타임 의존성이 0이다** — 소스가 쓰는 외부 라이브러리는 PHP 내장 `PDO` 뿐이고, 파일
  로딩은 `dti/bootstrap.php` 의 `require_once` 목록이 한다. **composer 는 테스트에만 쓴다.**
  서버에 composer 가 없어도, `vendor/` 를 올리지 않아도 파일만 복사하면 돌아간다
  (access·moodle·learn 과 같은 배포).
- **테스트**: `vendor/bin/phpunit` (214건). 테스트를 돌릴 때만 `composer install` 이 필요하다. 테스트 DB 는 `slackapi_test` 를 쓴다
  (`dti/tests/bootstrap.php`, 환경변수 `DTI_TEST_DB` 로 바꿀 수 있다).
  **이 환경은 커밋마다 fsync 가 돌아 쓰기 한 건이 0.2초다** — 픽스처는 트랜잭션으로 묶고
  테이블은 TRUNCATE 가 아니라 DELETE 로 비운다(TRUNCATE 는 InnoDB 에서 DDL 이라 3초 가까이
  걸린다). 로컬을 더 빠르게 하려면 MySQL 에서
  `SET GLOBAL innodb_flush_log_at_trx_commit=2, sync_binlog=0` (내구성 대신 속도, 개발 전용).
- **이관 검증**: 실데이터 사본으로 파이썬 앱과 PHP 의 응답을 통째로 비교해 **차이가 없음을
  확인했다**(아티클 31건 전체 키·값, 목록 순서, 분야, 멤버 점수 13행, 연관 31건).
  연관 점수는 파이썬이 저장해 둔 106쌍과 점수까지 일치한다.

## 남은 전환 작업

파이썬 magazine 은 저장소에서 지웠고 실데이터도 `dti_*` 로 옮겼다. 서버에 남은 건 이것뿐이다.

1. nginx 의 `/magazine/`·`/magazineapi/` 블록 제거
2. `magazine.service`(uvicorn 8001) 중지·비활성화, `/home/blueapp_core/magazine` 정리
3. `php dti/tools/backfill_materials.php` — 컬럼에 있던 자료를 `dti_materials` 로 옮긴다
   (`--dry-run` 으로 먼저 확인, 여러 번 돌려도 안전). 끝나면 이 도구도 지운다
4. `dti_topics`·`dti_presentations` 의 죽은 컬럼(`presenter`·`planned_date`·`material_*`
   ·`scan_*`) 정리

---

## 설치 · 운영 세팅

이 절은 **dti 를 서버에 올릴 때 따로 해줘야 하는
일**만 적는다. dti 자체는 포털과 같은 PHP 앱이라 파일 복사 외에 필요한 건 발표자료 PDF 변환
뿐이다.

---

### 1. 발표자료 PDF 변환 (LibreOffice)

pptx·ppt·odp 를 올리면 업로드 시점에 PDF 로 변환해 **같은 칸에 별개 자료로 하나 더** 넣는다.
원본과 변환본은 독립이라 각자 내려받고 각자 지운다.

**이 기능을 안 쓰면 아무것도 설치하지 않아도 된다** — 변환에 실패하면 PPT 만 올라가고 PDF 행이
안 생긴다. 업로드 자체는 성공하므로 기존 기능은 그대로 동작한다.

#### 설치

```bash
sudo apt install libreoffice-impress fonts-noto-cjk
which soffice
```

`fonts-noto-cjk` 를 빼면 변환은 되지만 **PDF 의 한글이 빈 사각형**으로 나온다.

#### 설치 후 확인 — 웹 서버 사용자로 돌려볼 것

여기서 가장 자주 막힌다. `root` 로는 되고 웹 서버 사용자로는 안 되는 경우가 흔하다.

```bash
sudo -u www-data soffice --headless --norestore \
  -env:UserInstallation=file:///tmp/dti-check \
  --convert-to pdf --outdir /tmp /경로/샘플.pptx
```

`-env:UserInstallation` 을 반드시 붙여서 확인한다. LibreOffice 는 쓸 수 있는 홈이 없으면
프로필을 만들다 실패하는데, 웹 서버 사용자에게는 보통 홈이 없다. 앱도 같은 이유로 호출마다
프로필 경로를 새로 만들어 넘긴다(동시에 뜬 인스턴스가 같은 프로필을 공유하면 서로를 막는다).

---

### 2. PHP 설정

변환은 **업로드 요청 안에서** 끝난다. 그래서 웹 SAPI(`/etc/php/*/apache2/php.ini`) 값이 중요하다.

```ini
max_execution_time = 120
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 256M
```

- `max_execution_time` 이 `soffice_timeout`(기본 60초)보다 짧으면 **PHP 가 먼저 죽는다.**
  자료 행 삽입이 변환보다 앞이라, 이때 PPT 행은 남고 응답만 끊겨 화면에는 실패로 보인다.
- `upload_max_filesize`·`post_max_size` 는 코드의 `max_upload_mb`(50)와 맞춘다. 더 작으면
  PHP 가 `$_FILES` 를 비워 보내고 앱은 413 을 돌려준다.
- `disable_functions` 에 `exec` 가 있으면 변환만 조용히 실패한다(업로드는 성공, PDF 없음).
  CLI 가 아니라 **웹 SAPI 기준으로** 확인할 것.

---

### 3. 설정값

| 값 | 기본 | 바꾸는 자리 |
| --- | --- | --- |
| `soffice` | `soffice` (PATH 에서 찾음) | 포털 `config.php` |
| `soffice_timeout` | `60` (초) | `dti/db.php` |
| `upload_dir` | `dti/var/uploads` | `dti/db.php` |
| `max_upload_mb` | `50` | `dti/db.php` |

웹 서버의 PATH 에 `soffice` 가 있으면 손댈 것이 없다. 경로가 다르면 **포털 `config.php`** 에서
바꾼다 — 그 파일은 git 에 없고 서버마다 따로 만드는 파일이라 배포에 덮이지 않는다. dti 는 DB
접속·슬랙 웹훅과 같은 길로 이 값을 받아온다(`dti_config_from_portal()`).

```php
// config.php
'soffice' => '/usr/bin/soffice',
```

키가 없으면 `soffice` 로 동작한다(구 버전 `config.php` 를 쓰는 서버도 그대로 돌아간다).

`soffice_timeout` 등 나머지는 `dti/db.php` 에 있다. 그 파일은 git 에 추적되므로 서버에서 고치면
다음 배포에 덮인다 — 바꿀 일이 생기면 저장소에서 고쳐 배포한다.

---

### 4. 업로드 디렉터리

`dti/var/uploads` 는 문서루트 아래라 직접 접근이 가능하다. `.htaccess`(`Require all denied`)로
막아두고 내려받기는 앱을 거치게 한다. 이 파일이 빠지면 업로드 원본이 URL 로 노출된다.

웹 서버 사용자에게 쓰기 권한이 필요하다. 변환본도 같은 디렉터리에 들어가므로 PPT 를 많이
올리면 저장 용량이 원본 + PDF 만큼 쓰인다.

---

### 5. 증상별 확인

| 증상 | 확인할 것 |
| --- | --- |
| PPT 는 올라가는데 PDF 자료가 안 생긴다 | `sudo -u www-data soffice ...` 를 직접 돌려본다. 웹 SAPI 의 `disable_functions` 에 `exec` 가 있는지 본다 |
| PDF 는 생기는데 한글이 빈 사각형 | `fonts-noto-cjk` 미설치 |
| 큰 PPT 를 올리면 화면에 실패로 뜨는데 PPT 는 들어가 있다 | `max_execution_time` < `soffice_timeout` |
| 50MB 보다 작은 파일도 413 | `upload_max_filesize`·`post_max_size` |
| 업로드가 계속 느리다 | 변환이 동기라 원래 몇 초 걸린다. 첫 변환은 프로필을 새로 만들어 더 느리다 |
| `/tmp` 에 `dti-soffice-*`·`dti-convert-*` 가 쌓인다 | 변환기가 작업 디렉터리를 정리한다. 남아 있으면 프로세스가 강제 종료된 흔적이다 |

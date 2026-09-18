# 변경 이력

어떤 배포본을 받으셨는지에 따라 덮어써야 할 파일이 다릅니다.
확실하지 않으면 아래 명령으로 대조하세요.

```cmd
php dev\verify.php
```

`MANIFEST.sha256` 와 실제 파일을 비교해 **덮어써야 할 파일만** 알려 줍니다.

---

## 2026-09-18 · 필터 정리 · 집계 요약 손질

**1. [내 신청만] · [엑셀 받기] 를 필터 영역으로**

목록 구분 탭 줄에 있던 둘을 필터 상자 오른쪽으로 되돌렸습니다.
탭 줄에는 **보기 방식**(목록 / 카드)만 남습니다.

**2. 집계 오른쪽 요약 칸**

`올해 전체 5건 / 반려 1 · 철회 0` 을 글줄 두 개로만 두던 것을 고쳤습니다.

- 앞의 네 칸과 같은 얼개(라벨 + 큰 숫자)로 맞춰 눈높이가 같아졌습니다
- 테두리를 점선으로 두고 배경을 깔아 **누르는 칸이 아니라는 게** 드러납니다
- 반려·철회는 본 흐름에서 빠진 것들이라 각자 색의 작은 알약으로 묶었습니다

**바뀐 파일**

```
views/member.php     (내 신청만 · 엑셀 받기 위치)
assets/app.js        (요약 칸 마크업)
assets/app.css       (요약 칸 스타일)
```

---

## 2026-09-18 · 포털 공용 소스가 core/ 로 내려갔습니다

포털 쪽 변경에 맞춘 것입니다. bluecart 자체 기능은 그대로입니다.

```
<포털>/auth.php          →  <포털>/core/auth.php
<포털>/db.php            →  <포털>/core/db.php
<포털>/worksystems.php   →  <포털>/core/worksystems.php
<포털>/worksystems.json  →  <포털>/core/worksystems.json
```

`config.php` 와 `styles/` 는 포털 루트에 그대로 있습니다. 그래서 `BC_PORTAL_ROOT`
도 계속 포털 루트를 가리키고, 그 아래 `core/` 를 부르는 식으로 바꿨습니다.

**포털 없이 단독으로 쓰던 분은 손댈 것이 없습니다.**

**바뀐 파일**

```
includes/bootstrap.php           (포털 탐지 · auth.php 경로)
index.php                        (worksystems.php 경로)
dev/check.php, dev/router.php    (포털 탐지)
config/config.iworks.sample.php  (주석)
```

---

## 2026-09-18 · 구성원 화면 정리

**1. 머리말**

`BlueUP-Cart / 물품 구매 요청` 이던 두 줄을 `물품 구매 요청 / BlueCart` 로 뒤집었습니다.
어느 모듈인지가 먼저 읽히도록 이름 쪽을 크게 뒀습니다.

**2. 화면 탭이 오른쪽 위로**

`구성원 / 관리자` 탭을 본문 맨 위에서 머리말 오른쪽으로 옮기고 분절 버튼으로 바꿨습니다.
본문 맨 위에 있으면 바로 아래 집계·필터와 층이 겹쳐 보였습니다.
관리자 탭을 볼 수 없는 사람에게는 탭 줄 자체가 나오지 않습니다.

역할 배지(`관리자` 등)는 뺐습니다. 바로 옆 `관리자` 탭과 같은 말이 두 번 나왔습니다.
역할은 관리자 화면의 `처리 역할 배정` 에서 봅니다.

**3. 집계**

한 덩어리 표에서 카드 네 장으로 바꿨습니다. 단계 색을 위쪽 띠·점·숫자 세 곳에 함께 쓰고,
누른 칸은 그 색으로 테두리와 배경이 물듭니다. 어느 단계로 걸러 놨는지 한눈에 보입니다.

**4. 목록 도구 줄**

`내 신청만` · 보기 방식 · `엑셀 받기` 를 필터 상자에서 빼내어 목록 구분 탭과 같은 줄
오른쪽에 모았습니다. 셋 다 "지금 보는 목록"을 다루는 것이라 필터와 층을 나눴습니다.

- `내 요청만` → **`내 신청만`**. 알약 모양으로 바꿔 켜져 있으면 파랗게 물듭니다.
- 처리 역할이 없는 사람은 **기본으로 켜집니다.** 끄면 전체가 보입니다.
- **보기 방식**(목록 / 카드)을 새로 넣었습니다. 카드는 표와 같은 데이터를 담고
  처리 버튼도 그대로 붙습니다.

**5. 필터 초기화 삭제**

값을 하나씩 되돌리면 되는 일이라 뺐습니다. 관리자 화면에는 그대로 있습니다.

**바뀐 파일**

```
index.php            (머리말, 탭 위치)
views/member.php     (도구 줄, 카드 자리)
assets/app.css       (머리말·탭·집계·도구 줄·카드)
assets/app.js        (보기 전환, 카드 렌더, 내 신청만 기본값)
```

---

## 2026-09-17 · 수령 장소를 목록에서 선택

구매 요청 화면의 **수령 장소** 를 자유 입력에서 목록 선택으로 바꿨습니다.

```
선택 안 함(빈 값) / 사무실 / CAFE / 기타
```

사람마다 '사무실', '회사', '본사' 처럼 다르게 적어 집계가 되지 않던 칸입니다.
층·호실 같은 세부 위치는 비고란에 적습니다.

선택지를 바꾸려면 `includes/workflow.php` 의 `BC_DELIVER_PLACES` 만 고치면
화면과 서버 검증에 함께 반영됩니다. 이미 쓰고 있는 값은 그대로 두고,
새 값을 뒤에 덧붙이세요.

**기존 데이터** 는 손대지 않습니다. 목록에 없는 값이 저장된 예전 건을 수정하면
그 값이 '(이전 입력값)' 으로 선택된 채 열리고, 건드리지 않으면 그대로 다시
저장됩니다. 다른 값으로 바꾸려면 목록 안에서 골라야 합니다. DB 이관 스크립트는
없습니다.

**바뀐 파일**

```
includes/workflow.php            (BC_DELIVER_PLACES, bc_is_deliver_place)
includes/model/PurchaseRequest.php  (validate 의 장소 검증)
views/modals.php                 (input → select)
assets/app.js                    (setDeliver)
tests/workflow_test.php          (검증 시험 4건)
```

---

## 2026-09-16 · iworks 포털 모듈로 전환

독립 프로그램에서 iworks 포털(`bluesmurfybm/slack`)의 한 모듈로 바꿨습니다.
`learn`, `dti` 와 같은 구조입니다.

**물려받는 것**

- 로그인 — 포털 `core/auth.php` 의 세션과 `current_portal_user()`.
  자체 로그인 없음. 미로그인이면 `../index.php?need_login=bluecart` 로 보냄.
- 로그아웃 — 포털 `../api/logout.php`
- 상단바 — 포털 `styles/topbar.css`, 로고·사용자 칩·아바타 색·업무 시스템 드롭다운
- 구성원 명단 — `portal_users`
- DB 접속 정보 — 포털 `config.php` 에서 물려받음 (두 군데 적지 않음)

**바뀐 것**

- 사용자 식별자가 **이메일**입니다. `learn` 의 관리자 명단도 이메일 기준이라 맞췄습니다.
- `bootstrap.php` 가 포털 `core/auth.php` 를 먼저 require 합니다.
  포털이 세션 이름(`BLUEIWORK_SESSID`)과 저장 경로를 정한 뒤 세션을 열기 때문에,
  우리가 먼저 `session_start()` 를 부르면 포털 세션을 보지 못합니다.
- `.htaccess` 를 폴더 이름에 의존하지 않도록 `RewriteRule` 로 바꿨습니다.
- 색·글꼴을 포털 `styles/default.css` 값에 맞췄습니다.

**포털이 없으면** 단독 모드로 그대로 뜹니다. `dev/login.php` 로 계정을 고르고
상단바는 `assets/topbar-fallback.css` 로 그립니다.

**새 파일**

```
config/config.iworks.sample.php  포털 모듈용 설정 (DB 는 포털에서 물려받음)
assets/topbar-fallback.css       단독 실행용 상단바
dev/apply_sql.php                mysql 클라이언트 없이 .sql 적용
```

**바뀐 파일**

```
index.php                 포털 상단바 + 모듈 머리말
includes/bootstrap.php    BC_PORTAL_ROOT 감지, 세션 순서
includes/auth.php         current_portal_user() 연동
assets/app.css            포털 팔레트
.htaccess                 폴더 이름 비의존
dev/router.php            포털 루트를 문서 루트로
dev/setup.bat             포털 모드 설치 경로
dev/check.php             포털 연동 진단
dev/make_config.php       --portal 모드
dev/seed_dev.sql          포털 DB 에 실행하지 말라는 경고
README.md
```

**포털에 등록** — `core/worksystems.json` 에 추가해야 상단바 메뉴에 나옵니다.

```json
{ "key": "bluecart", "emoji": "🛒", "label": "BlueCart", "path": "bluecart/index.php" }
```

---

## 2026-09-16 · 승인 생략 · 1인 담당 · 드로어 UI

**1. 승인없이 구매진행**

검토승인자·관리자가 직접 요청을 올릴 때 검토 단계를 건너뛸 수 있습니다.
요청 작성 화면의 [요청 올리기] 옆에 버튼이 생깁니다. 새 요청일 때만 보이고,
서버에서 권한을 다시 확인합니다. 이력에는 등록과 승인이 모두 남습니다.

**2. 구매담당자가 한 명이면 담당 지정 생략**

지정할 대상이 없으므로 담당 지정 버튼과 선택 칸이 나오지 않습니다.
목록에는 그 담당자 이름이 바로 표시되고, 그 사람이 그대로 처리합니다.
두 명 이상이 되면 지정 단계가 다시 살아납니다.

**3. 팝업 → 우측 드로어**

가운데 팝업 대신 우측에서 밀려 나오는 드로어로 바꿨습니다.
뒤 목록이 보이고, 드로어가 떠 있는 동안 배경 스크롤은 잠깁니다.
좁은 화면에서는 전체 폭으로 펼쳐집니다.

**바뀐 파일**

```
includes/workflow.php            (assign 조건, bc_needs_assignee)
includes/model/RoleAssign.php    (역할 캐시, soleBuyer)
api/request_save.php             (skip_review)
index.php                        (권한 플래그)
views/modals.php                 (버튼 추가, 드로어 클래스)
assets/app.css                   (드로어)
assets/app.js
tests/workflow_test.php          (139건)
README.md
```

DB 변경은 없습니다. 마이그레이션 불필요.

---

## 2026-09-16 · 첨부 저장소 경로 판정 오탐 수정

**고친 것**

- `dev/check.php` 가 `.../bluecart-data` 를 "프로그램 폴더 안에 있다" 고
  잘못 경고하던 문제. 경로를 단순 문자열 접두사로 비교해서
  `.../bluecart-data` 가 `.../bluecart` 로 시작한다고 판단했습니다.
  구분자를 붙여 디렉터리 경계를 맞추고, Windows 의 대소문자 무시도 반영했습니다.

  프로그램 동작에는 영향이 없던 진단 메시지만의 문제였습니다.
  `bluecart-data` 는 원래대로 올바른 위치입니다.

**바뀐 파일**

```
dev/check.php
MANIFEST.sha256
```

---

## 2026-09-16 · 배치 파일 재작성

**고친 것**

- `for /f` 안에 따옴표 붙은 실행 경로를 넣어 명령이 깨지던 문제.
  cmd 는 `cmd /c "..."` 로 실행할 때 문자열이 따옴표로 시작하고 끝나면
  바깥 따옴표 한 쌍을 떼어냅니다. 그래서 `"php" -r "echo X"` 가
  `php" -r "echo X` 로 망가져 `'php' -r "echo"은(는) 내부 또는 외부 명령` 오류가
  났습니다. 버전·포트 값을 임시 파일로 주고받도록 바꿨습니다.
- `serve.bat` 의 구조 손상. 이전 수정에서 서브루틴이 파일 중간에 끼어들어
  있었습니다. 중첩 블록 없이 평탄한 `goto` 흐름으로 다시 썼습니다.

**추가된 동작**

- `setup.bat` / `serve.bat` 이 PHP 8 을 자동으로 찾습니다.
  PATH 에 PHP 7 이 잡혀 있어도 PC 에 설치된 PHP 8 을 찾아 쓰고
  `dev\php-path.txt` 에 저장합니다.
- `find-tools.bat /auto` — 묻지 않고 찾아 저장하는 모드.

**바뀐 파일**

```
dev/serve.bat        (다시 씀)
dev/setup.bat        (다시 씀)
dev/find-tools.bat   (/auto 모드)
dev/verify.php       (새 파일)
dev/make_manifest.php(새 파일)
MANIFEST.sha256      (새 파일)
CHANGELOG.md         (새 파일)
README.md
```

---

## 2026-09-15 · 포트 자동 선택

**고친 것**

- `Failed to listen on 127.0.0.1:8080 (액세스 권한에 의해...)`.
  Windows 의 `WSAEACCES` 입니다. 포트가 비어 있어도 Hyper-V, WSL2, Docker 가
  대역을 예약해 두면 바인딩이 거부됩니다. "사용 중인지" 를 묻는 대신
  실제로 바인딩을 시도해 되는 포트를 고르도록 했습니다.
- 포트가 바뀌어도 알림 링크가 맞도록, 개발 서버가 실제 주소를 프로그램에
  알려 줍니다. `config.php` 를 고칠 필요가 없습니다.

**바뀐 파일**

```
dev/find-port.php       (새 파일)
dev/serve.bat
dev/router.php          (BC_BASE_URL 전달)
includes/bootstrap.php  (BC_BASE_URL 반영)
README.md
```

---

## 2026-09-15 · XAMPP · WampServer 공존 대응

**고친 것**

- 배치 파일이 UTF-8 이라 cmd 가 한글 줄을 중간에서 자르던 문제.
  `'를' is not recognized as an internal or external command` 오류의 원인입니다.
  전부 CP949 + CRLF 로 바꾸고 `chcp 65001` 을 뺐습니다.

**추가**

- `dev/find-tools.bat` — 설치된 PHP 와 mysql 을 전부 찾아 버전과 함께 보여주고,
  PHP 8 을 골라 저장합니다.
- `dev/mysql-path.txt` 지원.

**바뀐 파일**

```
dev/find-tools.bat        (새 파일)
dev/mysql-path.txt.sample (새 파일)
dev/setup.bat, serve.bat, test.bat   (CP949 변환, 경로 파일 지원)
.gitattributes            (*.bat 을 바이너리로)
README.md
```

---

## 2026-09-15 · PHP 경로 지정

- PATH 를 건드리지 않고 이 프로젝트만 특정 PHP 를 쓰도록 `dev/php-path.txt` 추가.

```
dev/php-path.txt.sample  (새 파일)
dev/setup.bat, serve.bat, test.bat
README.md                (PHP 8 설치 안내)
```

---

## 2026-09-15 · 환경 점검 · 원격 MySQL

- `dev/check.php` — PHP 버전, 확장 모듈, DB 접속·버전·문자셋·권한,
  첨부 저장소 권한을 한 번에 진단. PHP 7 에서도 실행됩니다.
- `dev/make_config.php` — 접속 정보를 안전하게 `config.php` 로 씀
  (비밀번호에 따옴표가 있어도 깨지지 않습니다).
- 원격 MySQL 5.x 지원 확인. 스키마와 질의를 MySQL 5.7 기본 sql_mode 에서 검증.
- **테스트가 `config/config.php` 를 덮어쓰고 지우던 문제 수정.**
  `BC_CONFIG_FILE` 로 테스트 전용 설정만 쓰도록 바꿨습니다.

```
dev/check.php, dev/make_config.php   (새 파일)
dev/setup.bat, test.bat
includes/bootstrap.php               (BC_CONFIG_FILE)
config/config.test.php               (환경변수 지원)
tests/workflow_test.php
README.md
```

---

## 2026-09-15 · 로컬 개발 환경

- `dev/` 추가. iworks 세션이 없는 로컬에서 계정을 골라 로그인하는 화면과
  PHP 내장 서버용 라우터, 샘플 데이터.

```
dev/router.php, dev/login.php, dev/seed_dev.sql
dev/config.local.sample.php
dev/setup.bat, serve.bat, test.bat
tests/fixture.sql
.gitattributes
README.md
```

---

## 2026-09-15 · 첨부파일 · 엑셀 · 건별 담당자

- 첨부파일 업로드/내려받기 (웹 루트 바깥 저장, 내용 검사, 시점별 권한)
- 엑셀 내보내기 (목록·통계). PhpSpreadsheet 도 ZipArchive 도 쓰지 않습니다.
- 건별 구매담당자 지정. 지정된 건은 그 사람만 처리할 수 있습니다.

```
includes/model/Attachment.php   (새 파일)
includes/XlsxWriter.php         (새 파일)
api/attachments.php, api/attachment_download.php, api/export.php  (새 파일)
sql/03_migration_v2.sql         (새 파일 · 기존 설치본은 반드시 실행)
sql/01_schema.sql, sql/02_seed.sql
includes/workflow.php, presenter.php, bootstrap.php
includes/model/PurchaseRequest.php, RoleAssign.php
includes/notify/Notifier.php
api/requests.php, request_action.php
views/*.php, assets/app.js, assets/app.css
tests/workflow_test.php
README.md
```

---

## 2026-09-15 · 최초 구현

사내 물품 구매 요청 시스템. 요청 → 검토 → 구매 진행 → 구비 완료 워크플로,
역할 배정, 프로세스별 알림, 통계, 카테고리 관리.

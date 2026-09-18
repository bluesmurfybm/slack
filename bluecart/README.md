# BlueCart — 사내 물품 구매 요청

사내 구성원이 필요한 물품을 요청하고, 검토승인자와 구매담당자가 처리해
구비 완료까지 추적하는 iworks 내부 프로그램입니다. 기존 `회사물품구입신청서.xlsx`
운영을 대체합니다.

```
요청 ──▶ 검토(승인/반려) ──▶ 구매 진행 ──▶ 구비 완료
          └─ 반려 ──▶ 수정 후 재요청 또는 철회
```

**진행 순서**: 2장(로컬에서 확인) → 14장(git) → 15장(운영 배포).
운영에 올리기 전에 4장(iworks 연동)을 반드시 맞춰야 합니다.

**새 배포본을 받으셨다면** — 무엇이 바뀌었는지는 `CHANGELOG.md` 에 있고,
내 파일 중 무엇을 덮어써야 하는지는 아래로 확인합니다.

```cmd
php dev\verify.php
```

---

## 1. 요구 환경

코드는 **PHP 8 + MySQL 8**(운영 환경)을 기준으로 작성했습니다.
로컬은 운영과 완전히 같지 않아도 되지만 PHP 만큼은 8 이상이어야 합니다.

| | 로컬 개발 | 운영 |
|---|---|---|
| PHP | **8.0 이상** (필수) | 8.x |
| MySQL | 5.7 이상 (5.x 원격 서버 사용 가능) | 8.x |
| 웹서버 | PHP 내장 서버 | Apache / Nginx |
| 로그인 | `dev/login.php` 로 계정 선택 | iworks 세션 |

### 1.1 PHP 는 반드시 8 이상

7.x 에서는 **실행 자체가 안 됩니다.** 문법 오류로 백지 화면이 뜹니다.
`match` 식, `str_starts_with()`, `never` 반환형, 생성자 프로퍼티 등
PHP 8 문법을 곳곳에서 쓰기 때문입니다.

버전 확인:

```cmd
php -v
```

지금 쓰는 PHP 가 어디 것인지부터 확인합니다.

```cmd
where php
php --ini
```

`C:\laragon\...` 이면 Laragon, `C:\xampp\...` 이면 XAMPP, 그 밖이면 직접 설치한 것입니다.

#### 방법 A — Laragon 에서 버전 바꾸기

Laragon 은 상단 메뉴 막대가 없습니다. **Laragon 창 안에서 마우스 오른쪽 버튼**을
누르거나, 창 오른쪽 아래 `Menu` 버튼을 누르면 메뉴가 뜹니다.
작업 표시줄 오른쪽 트레이의 Laragon 아이콘을 오른쪽 클릭해도 같은 메뉴입니다.

```
(오른쪽 클릭) → PHP → Version → php-8.3.x-...  선택
```

목록에 8.x 가 없으면 먼저 받아야 합니다.

```
(오른쪽 클릭) → Tools → Quick add → PHP 8.3
```

내려받기가 끝나면 Laragon 이 알아서 목록에 넣어 줍니다. 그다음 위의
`PHP → Version` 에서 고르고, **Laragon 을 Stop → Start** 로 다시 시작합니다.

Quick add 에 PHP 가 안 보이는 구버전 Laragon 이라면 방법 B 로 가세요.
받은 PHP 를 `C:\laragon\bin\php\php-8.3.x` 에 풀어 넣어도 목록에 나타납니다.

#### 방법 B — PHP 를 직접 받아 쓰기 (어떤 스택이든 됨)

Laragon 이 없거나 위에서 막히면 이쪽이 확실합니다.

1. <https://windows.php.net/download/> 에서 **PHP 8.3 → VS16 x64 Non Thread Safe**
   의 `Zip` 을 받습니다.
   (Apache 로 붙일 일이 나중에 생길 것 같으면 Thread Safe 를 받으세요.
    우리는 PHP 내장 서버로 띄우므로 둘 다 됩니다.)
2. `C:\php83` 에 풉니다. 폴더 안에 `php.exe` 가 바로 보여야 합니다.
3. 같은 폴더의 `php.ini-development` 를 복사해 `php.ini` 로 이름을 바꿉니다.
4. `php.ini` 를 열어 아래 줄들의 맨 앞 세미콜론(`;`)을 지웁니다.

```ini
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_mysql
```

5. 실행이 안 되고 "VCRUNTIME140.dll 이 없습니다" 가 뜨면
   **Microsoft Visual C++ 재배포 패키지(x64)** 를 설치하세요.
   windows.php.net 의 다운로드 화면에도 링크가 있습니다.

확인:

```cmd
C:\php83\php.exe -v
C:\php83\php.exe -m
```

#### 여러 스택이 깔려 있다면 (XAMPP + WampServer 등)

XAMPP 의 PHP 7 과 WampServer 의 PHP 8 이 같이 있을 때, PATH 를 바꾸면
다른 프로젝트가 영향을 받습니다. 이 프로젝트에만 PHP 8 을 쓰게 하면 됩니다.

```cmd
cd J:\kimhy\private_project\bluecart
dev\find-tools.bat
```

흔한 설치 경로를 훑어 **깔려 있는 PHP 를 전부 버전과 함께 보여주고**,
그중 8.x 를 골라 `dev\php-path.txt` 에 저장합니다. mysql 클라이언트도 같이 찾습니다.

```
=== 설치된 PHP 찾기 ===
  PHP 8.3.6    C:\wamp64\bin\php\php8.3.6\php.exe   <-- 사용 가능
  PHP 7.4.33   C:\xampp\php\php.exe
```

직접 적으셔도 됩니다. `dev\php-path.txt` 에 한 줄만:

```
C:\wamp64\bin\php\php8.3.6\php.exe
```

WampServer 의 PHP 폴더 이름은 버전마다 다르니 먼저 확인하세요.

```cmd
dir C:\wamp64\bin\php /b
dir C:\wamp64\bin\mysql /b
```

mysql 클라이언트 경로는 `dev\mysql-path.txt` 에 같은 방식으로 적습니다.

```
C:\wamp64\bin\mysql\mysql8.0.31\bin\mysql.exe
```

`setup.bat`, `serve.bat`, `test.bat` 이 이 두 파일을 먼저 읽습니다.
없으면 PATH 의 `php` / `mysql` 을 씁니다.

> **WampServer 주의** — PHP 폴더에 `php.ini` 와 `phpForApache.ini` 가 같이 있습니다.
> 우리가 쓰는 명령줄 PHP 는 `php.ini` 쪽을 읽습니다. 확장 모듈이 없다고 나오면
> `phpForApache.ini` 가 아니라 `php.ini` 를 고쳐야 합니다.
> 어느 파일을 읽는지는 `php --ini` 로 확인됩니다.
>
> WampServer 트레이 메뉴의 `PHP > Version` 은 **Apache 용** 설정입니다.
> 명령줄 PHP 와는 별개라서, 거기서 바꿔도 `php -v` 결과는 그대로일 수 있습니다.

#### PATH 를 바꿀 것인가, 말 것인가

기존 PHP 7.4 를 다른 데서 쓰고 있다면 PATH 를 통째로 바꾸는 게 부담스러울 수
있습니다. 그럴 때는 **PATH 를 건드리지 말고** 이 프로젝트에만 알려 주면 됩니다.

`dev\php-path.txt` 파일을 만들고 php.exe 전체 경로를 한 줄만 적으세요.

```
C:\php83\php.exe
```

`dev\setup.bat`, `serve.bat`, `test.bat` 이 이 파일을 먼저 읽어 그 PHP 를 씁니다.
(`dev\php-path.txt.sample` 을 복사해 쓰셔도 됩니다.)

PATH 를 바꾸실 거라면 PowerShell 에서:

```powershell
$p = [Environment]::GetEnvironmentVariable("Path", "User")
[Environment]::SetEnvironmentVariable("Path", "C:\php83;$p", "User")
```

앞에 붙여야 기존 7.4 보다 먼저 잡힙니다.
**바꾼 뒤에는 명령창을 새로 열어야** 반영됩니다.

지금 창에서만 잠깐 써 보려면:

```powershell
$env:Path = "C:\php83;" + $env:Path
php -v
```

### 1.2 PHP 확장 모듈

| 모듈 | 쓰는 곳 |
|---|---|
| `pdo_mysql` | DB 접속 |
| `mbstring` | 한글 문자열 처리 |
| `curl` | 슬랙 발송 |
| `fileinfo` | 첨부파일 내용 검사 |
| `zlib` | 엑셀 생성 (PHP 기본 내장) |

```cmd
php -m
```

빠진 게 있으면 `php.ini` 에서 해당 `extension=` 줄 앞의 세미콜론을 지웁니다.
`php.ini` 위치는 `php --ini` 로 확인합니다.

`php-zip`(ZipArchive)은 **필요 없습니다.** 엑셀을 만들 때 ZIP 컨테이너를
직접 쓰기 때문에 기본 내장된 `zlib` 만 있으면 됩니다.

### 1.3 MySQL — 로컬 5.x, 운영 8.x

로컬에서 원격 MySQL 5.x 를 써도 됩니다. 스키마와 모든 질의를
**MySQL 5.7 기본 sql_mode**(`ONLY_FULL_GROUP_BY` 포함)와 MySQL 8 양쪽에서
확인했습니다. 윈도우 함수나 CTE 처럼 8 전용 문법은 쓰지 않았습니다.

- **5.7 이상**: 그대로 동작합니다
- **5.6**: 동작하지만 지원이 끝난 버전입니다
- **5.5 이하**: 쓸 수 없습니다. `DATETIME` 컬럼의 기본값
  (`DEFAULT CURRENT_TIMESTAMP`)을 5.5 가 지원하지 않습니다

버전 확인:

```cmd
mysql -h <서버주소> -u <계정> -p -e "SELECT VERSION()"
```

로컬과 운영의 버전이 다르므로, **운영 배포 전에 운영 DB 에서도 점검을 한 번**
돌리세요 (13장).

### 1.4 한 번에 점검하기

아래 스크립트가 위 항목을 전부 확인하고 무엇을 고쳐야 하는지 알려 줍니다.
**PHP 7 에서도 실행되도록** 따로 작성했으니, 버전이 낮아도 진단은 볼 수 있습니다.

```cmd
php dev\check.php
```

PATH 를 안 바꾸셨다면 전체 경로로 실행하세요.

```cmd
C:\php83\php.exe dev\check.php
```

설정 파일 없이 원격 DB 만 먼저 확인할 수도 있습니다.

```cmd
php dev\check.php --host=192.168.0.252 --user=bluecart --pass=비밀번호 --db=bluecart_dev
```

확인하는 것: PHP 버전, 확장 모듈, 업로드 설정, 설정 파일,
DB 포트 연결 · 접속 · 버전 · 문자셋 · 권한 · 테이블 존재, 첨부 저장소 쓰기 권한.

---

## 2. 로컬에서 먼저 돌려보기 (Windows)

운영에 올리기 전에 로컬에서 확인하는 절차입니다.
프로젝트 위치는 `J:\kimhy\private_project\bluecart` 를 기준으로 적었습니다.

시작 전에 1장의 PHP 8 확인을 먼저 끝내세요. 거기서 막히면 나머지가 다 막힙니다.

### 2.1 소스 배치

```cmd
J:
cd \kimhy\private_project
```

받은 `bluecart.tar.gz` 를 여기에 풀면 `J:\kimhy\private_project\bluecart` 가 됩니다.
(반디집·7-Zip 으로 풀거나 `tar -xzf bluecart.tar.gz`)

### 2.2 DB 준비

로컬 MySQL 을 써도 되고, 원격 서버를 써도 됩니다.
**어느 쪽이든 개발 전용 데이터베이스를 따로 만드세요.** 운영 DB 를 직접 쓰면
개발용 샘플 데이터가 섞여 들어갑니다.

원격 서버를 쓰신다면 그 서버에서 계정을 먼저 만들어야 합니다.
`<내PC아이피>` 는 개발 PC 의 주소입니다. 사내망이면 대역으로 열어도 됩니다.

```sql
-- 원격 MySQL 서버에서 실행
CREATE DATABASE bluecart_dev DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- MySQL 5.7 이상
CREATE USER 'bluecart'@'<내PC아이피>' IDENTIFIED BY '비밀번호';
GRANT ALL PRIVILEGES ON bluecart_dev.* TO 'bluecart'@'<내PC아이피>';

-- MySQL 5.6 이하라면
-- GRANT ALL PRIVILEGES ON bluecart_dev.* TO 'bluecart'@'<내PC아이피>' IDENTIFIED BY '비밀번호';

FLUSH PRIVILEGES;
```

서버가 외부 접속을 받는지도 확인합니다. `my.cnf` 의 `bind-address` 가
`127.0.0.1` 로 묶여 있으면 원격에서 닿지 않습니다.

개발 PC 에서 닿는지 먼저 확인하세요.

```cmd
php dev\check.php --host=192.168.0.252 --user=bluecart --pass=비밀번호 --db=bluecart_dev
```

### 2.3 자동 준비

**포털 안에 두셨다면** — `dev\setup.bat` 이 알아서 포털 모드로 설치합니다.
DB 접속 정보를 묻지 않고 포털 `config.php` 에서 물려받고, mysql 클라이언트
없이 PDO 로 스키마를 적용합니다. 개발용 샘플 데이터는 **넣지 않습니다**
(포털 DB 의 실제 요청을 지우기 때문입니다).

클론 직후에는 포털 `config.php` 가 없습니다(`.gitignore` 대상).
포털 담당자에게 받거나 `core/db.php` 형식에 맞춰 직접 만들어야 합니다.

```php
<?php
return [
  'db' => ['host'=>'...','port'=>3306,'name'=>'slackapi',
           'user'=>'...','pass'=>'...','charset'=>'utf8mb4'],
  'links' => [],
];
```

**단독 폴더에 두셨다면** — 아래 그대로 진행하면 됩니다.


```cmd
cd J:\kimhy\private_project\bluecart
dev\setup.bat
```

PATH 에 PHP 7 이 잡혀 있어도 괜찮습니다. `setup.bat` 과 `serve.bat` 은 PHP 가
8 미만이면 **PC 에 설치된 PHP 8 을 알아서 찾아** 쓰고, 찾은 경로를
`dev\php-path.txt` 에 저장합니다. PATH 는 건드리지 않습니다.

어떤 PHP 들이 잡히는지 미리 보고 싶다면:

```cmd
dev\find-tools.bat
```

순서대로 이렇게 진행합니다.

1. PHP 8 여부와 확장 모듈 확인 — 여기서 막히면 무엇을 고칠지 알려 주고 멈춥니다
2. DB 접속 정보 입력 (호스트, 포트, DB 이름, 계정, 비밀번호)
3. 접속 확인 후 데이터베이스 생성
4. 스키마 · 기본 데이터 · 개발용 샘플 데이터 입력
5. `config\config.php` 자동 생성
6. 첨부 저장소 `J:\kimhy\private_project\bluecart-data` 생성
7. `dev\check.php` 로 최종 점검

비밀번호에 `%` 나 `^` 가 들어 있으면 배치 파일이 제대로 넘기지 못합니다.
그럴 때는 `config\config.php` 의 `'password'` 를 직접 고치세요.

### 2.4 실행

```cmd
dev\serve.bat
```

포털 안이면 **포털 전체**를 띄우고(문서 루트가 포털 루트),
단독이면 모듈만 띄웁니다. 어느 쪽인지와 주소를 화면에 찍어 줍니다.

```
 iworks 포털 모드
 포털      http://127.0.0.1:8080/
 BlueCart  http://127.0.0.1:8080/bluecart/
```

포털 모드에서는 **포털 계정으로 로그인**한 뒤 BlueCart 로 들어갑니다.
개발용 로그인 화면(`dev/login.php`)은 단독 모드에서만 뜹니다 — 가짜 세션을
섞으면 실제 세션 구조와 다른 상태로 시험하게 되기 때문입니다.

실제로 열리는 포트를 찾아 띄우고, 화면에 주소를 찍어 줍니다.
보통 <http://127.0.0.1:8080> 이지만 그 포트가 막혀 있으면 8081, 8088 … 순으로 넘어갑니다.

포트를 직접 정하려면 인자로 넘기세요.

```cmd
dev\serve.bat 9123
```

`127.0.0.1` 에만 붙으므로 같은 네트워크의 다른 PC 에서는 보이지 않습니다.

로컬에는 iworks 로그인이 없으므로 **개발용 로그인 화면**이 먼저 뜹니다.
샘플 구성원 중 하나를 고르면 그 사람으로 들어갑니다.

| 계정 | 역할 | 확인할 것 |
|---|---|---|
| 김호영 | 관리자 · 검토승인자 | 관리자 탭 전부 (카테고리, 역할 배정, 알림 설정) |
| 김지안 | 검토승인자 · 구매담당자 | 승인/반려, 구매 진행, 담당 지정 |
| 박성철 | 구매담당자 | 자기에게 배정된 건만 처리되는지 |
| 유병문 | 없음 | 일반 구성원 화면, 관리자 탭이 안 보이는지 |

계정을 바꾸려면 <http://127.0.0.1:8080/dev/logout> 으로 나갔다 다시 고릅니다.

샘플 데이터로 각 상태가 하나씩 들어 있어 화면을 바로 볼 수 있습니다.

### 2.5 확인해 볼 것

- 새 구매 요청을 올리고 첨부를 붙여 봅니다
- 김지안으로 바꿔 승인하면서 담당을 박성철로 지정합니다
- 다시 김지안으로 구매 진행을 눌러 보면 **버튼이 없어야** 합니다 (담당이 아니므로)
- 박성철로 바꾸면 처리 버튼이 보입니다
- 엑셀 받기를 눌러 파일이 제대로 열리는지 봅니다
- 알림은 실제로 보내지 않고 기록만 남습니다. 내용 확인:

```sql
SELECT event_code, target_role, channel, recipient, subject
  FROM bc_notify_log ORDER BY id DESC LIMIT 20;
```

### 2.6 자동 점검 실행

```cmd
dev\test.bat
```

별도 DB(`iworks_test`)를 쓰므로 개발 데이터도 `config\config.php` 도 건드리지
않습니다. 123건이 통과해야 합니다.

운영 배포 전에는 **운영 DB(MySQL 8)에서도 한 번 돌려 보세요.** 로컬이 5.x 라
버전 차이가 있습니다 (13장).

### 2.7 로컬에서 자주 겪는 문제

| 증상 | 원인과 조치 |
|---|---|
| 화면이 백지이거나 `syntax error, unexpected` | PHP 가 7.x 입니다. 1.1 참고 |
| 배치 실행 중 `'를' is not recognized...` | 배치 파일이 UTF-8 로 저장됐습니다. 아래 2.9 참고 |
| `'php' -r "echo"은(는) 내부 또는 외부 명령...` | 구버전 배치 파일입니다. 새 압축으로 덮어쓰세요 |
| `설정 파일이 없습니다` | `dev\setup.bat` 을 실행하지 않았습니다 |
| `SQLSTATE[HY000] [1045]` | DB 비밀번호 불일치. `config\config.php` 의 `'password'` 확인 |
| `SQLSTATE[HY000] [2002]` / `[2003]` | 서버에 닿지 않습니다. 원격이면 주소·포트·방화벽, 로컬이면 MySQL 이 켜져 있는지 |
| `[1130] Host ... is not allowed` | 원격 계정이 이 PC 의 IP 를 허용하지 않습니다. 2.2 참고 |
| 한글이 `???` 로 보임 | DB 를 만들 때 `utf8mb4` 를 빼먹었습니다. DB 를 지우고 setup 을 다시 실행 |
| 구성원 목록이 비어 있음 | `dev\seed_dev.sql` 이 안 돌았습니다 |
| 첨부 업로드 실패 | `bluecart-data` 폴더가 없거나 쓰기 권한이 없습니다 |
| `Failed to listen on 127.0.0.1:8080` | 아래 2.8 참고. 보통은 `dev\serve.bat` 이 알아서 다른 포트를 씁니다 |

`config\config.php` 의 `'debug' => true` 라서 오류가 화면에 그대로 나옵니다.

### 2.8 포트가 막힐 때

```
Failed to listen on 127.0.0.1:8080
(reason: 액세스 권한에 의해 숨겨진 소켓에 액세스를 시도했습니다)
```

Windows 의 `WSAEACCES` 입니다. 원인은 둘 중 하나입니다.

**1) 다른 프로그램이 쓰고 있다**

```cmd
netstat -ano | findstr :8080
```

나온 PID 를 작업 관리자 → 세부 정보에서 찾아보세요.
WampServer 나 XAMPP 의 Apache 가 잡고 있는 경우가 많습니다.

**2) Windows 가 대역을 예약했다**

이쪽이 더 흔한데 눈에 잘 안 띕니다. Hyper-V, WSL2, Docker Desktop 을 쓰면
포트 대역이 통째로 예약되어, **아무도 안 쓰는 포트인데도** 바인딩이 거부됩니다.

```cmd
netsh interface ipv4 show excludedportrange protocol=tcp
```

출력에 `8080` 이 포함된 구간이 있으면 이 경우입니다.

**해결**

`dev\serve.bat` 이 알아서 처리합니다. "사용 중인지" 를 묻는 게 아니라
실제로 바인딩을 시도해 보고 되는 포트를 고르기 때문에, 예약 대역도 걸러집니다.
후보는 8080, 8081, 8088, 8000, 8001, 8888, 9000, 9090, 3000, 5000, 7070, 8181 순입니다.

직접 정하려면:

```cmd
dev\serve.bat 9123
```

후보를 전부 시도해 보려면:

```cmd
php dev\find-port.php --verbose
```

포트가 바뀌어도 `config.php` 를 고칠 필요는 없습니다. 개발 서버가 실제 주소를
프로그램에 알려 주므로 알림 링크 같은 것도 그 포트를 따라갑니다.

---

### 2.9 배치 파일 인코딩 — 건드리지 마세요

`dev\*.bat` 은 **CP949(한글 Windows 기본 코드페이지) + CRLF** 로 저장되어 있습니다.

VS Code 등에서 열어 UTF-8 로 다시 저장하면 실행이 깨집니다.
cmd.exe 는 배치 파일을 바이트 단위로 읽으면서 파일 위치를 되짚는데, UTF-8
멀티바이트 한글이 있으면 그 계산이 어긋나 줄이 중간에서 잘립니다.
잘린 뒷부분을 명령어로 해석해서 이런 오류가 납니다.

```
'를' is not recognized as an internal or external command,
operable program or batch file.
```

`chcp 65001` 을 앞에 붙여도 해결되지 않습니다. 오히려 이 조합이 문제입니다.

VS Code 에서 고칠 일이 있으면 오른쪽 아래 인코딩 표시를 눌러
`Save with Encoding` → `Korean (Windows 949)` 로 저장하세요.
`.gitattributes` 에서 `*.bat` 을 바이너리로 잡아 git 이 인코딩을 바꾸지 않게 해 두었습니다.

---

### 2.10 개발용 파일에 대해

`dev\` 안의 파일은 **로컬 전용**입니다.

- `router.php`, `login.php` 는 비밀번호 확인 없이 계정을 바꿔 줍니다.
  PHP 내장 서버가 아니면 스스로 실행을 거부하고, 루프백 주소에서만 응답합니다.
- `.htaccess` 로도 막아 두었지만, 배포할 때 `dev\` 폴더는 아예 빼는 편이 낫습니다 (7장 참고).

---

## 3. iworks 모듈로 설치

BlueCart 는 iworks 포털(`bluesmurfybm/slack`) 저장소 안의 한 폴더로 들어갑니다.
`learn`, `dti` 와 같은 구조이고, 로그인·상단바·구성원 명단을 포털에서 물려받습니다.

```
<포털 루트>/
├── core/
│   ├── auth.php        ← 세션(BLUEIWORK_SESSID) + current_portal_user()
│   ├── db.php          ← portal_db(), portal_users
├── config.php          ← DB 접속 정보 (.gitignore 대상)
│   ├── worksystems.php ← 상단바 "업무 시스템" 메뉴
│   └── worksystems.json ← 그 목록의 원본
├── styles/topbar.css   ← 공통 상단바 스타일
├── learn/  dti/  book/ ...
└── bluecart/           ← 여기
```

### 3.1 소스 배치

```bash
cd <포털 루트>
# 저장소에 이미 들어 있으면 git pull 로 끝납니다
```

폴더 이름은 `bluecart` 를 기준으로 맞춰 두었습니다. 바꾸려면
`config/config.php` 의 `iworks.module_key` 와 `core/worksystems.json` 의 `path` 도
함께 고치세요.

### 3.2 설정

```bash
cd bluecart
cp config/config.iworks.sample.php config/config.php
```

**DB 접속 정보는 적지 않습니다.** 이 템플릿이 포털 `config.php` 를 읽어
그대로 물려받습니다. 접속 정보가 두 군데 있으면 한쪽만 바뀌었을 때
원인을 찾기 어렵기 때문입니다.

고칠 것은 아래 정도입니다.

```php
'superadmins' => ['kimhy@bluesoft.co.kr'],   // 최초 관리자 (이메일)
'app' => [
    'base_url'   => 'http://iworks.bizblue.co.kr/bluecart',
    'upload_dir' => '/var/www/iworks-data/bluecart',   // 웹 루트 바깥
],
```

### 3.3 스키마

포털과 같은 DB(`slackapi`)에 `bc_` 접두사 테이블로 들어갑니다.
mysql 클라이언트 없이 적용할 수 있습니다.

```bash
php dev/apply_sql.php sql/01_schema.sql sql/02_seed.sql
```

`mysql` 명령을 쓰셔도 됩니다.

```bash
mysql -u <user> -p --default-character-set=utf8mb4 slackapi < sql/01_schema.sql
mysql -u <user> -p --default-character-set=utf8mb4 slackapi < sql/02_seed.sql
```

### 3.4 첨부 저장소

```bash
mkdir -p /var/www/iworks-data/bluecart
chown www-data:www-data /var/www/iworks-data/bluecart
chmod 750 /var/www/iworks-data/bluecart
```

### 3.5 포털에 등록

상단바 메뉴와 포털 홈 타일에 나오게 하려면 `core/worksystems.json` 에 한 줄 추가합니다.
`book` 다음 자리가 무난합니다.

```json
{ "key": "bluecart", "emoji": "🛒", "label": "BlueCart", "path": "bluecart/index.php" }
```

`key` 는 `config.php` 의 `iworks.module_key` 와 같아야 합니다. 이 값으로
상단바에서 현재 위치(`.on`)를 표시하고, 미로그인으로 튕길 때
`?need_login=bluecart` 를 붙입니다.

### 3.6 crontab

```cron
*/5 * * * *  /usr/bin/php <포털 루트>/bluecart/cron/notify_retry.php >> /var/log/bluecart.log 2>&1
30 2 * * *   /usr/bin/php <포털 루트>/bluecart/cron/close_stale_rejected.php >> /var/log/bluecart.log 2>&1
```

### 3.7 확인

```bash
php dev/check.php
```

"포털 모듈로 동작합니다" 와 "core/worksystems.json 에 등록됨" 이 나와야 합니다.

---

## 4. 포털에서 무엇을 물려받는가

### 4.1 로그인

자체 로그인이 없습니다. 포털 `core/auth.php` 를 읽어 세션과 사용자 정보를 씁니다.

```php
require_once BC_PORTAL_ROOT . '/core/auth.php';   // includes/bootstrap.php
$row = current_portal_user();                // includes/auth.php
```

**순서가 중요합니다.** 포털은 세션 이름(`BLUEIWORK_SESSID`)과 저장 경로를
지정한 뒤 세션을 엽니다. BlueCart 가 먼저 `session_start()` 를 부르면 기본
이름으로 열려 포털 세션을 보지 못합니다. `bootstrap.php` 가 포털 `auth.php` 를
먼저 require 하도록 해 두었습니다.

미로그인이면 `../index.php?need_login=bluecart` 로 보냅니다.
로그아웃은 포털 `../api/logout.php` 를 그대로 씁니다.

### 4.2 사용자 식별자는 이메일

`portal_users.email` 을 씁니다. `learn` 의 관리자 명단(`learn_admins`)도
이메일 기준이라 맞췄습니다. `bc_request.requester_id`, `bc_role_assign.user_id`
같은 컬럼에 이메일이 들어갑니다.

`portal_users.id` 를 쓰지 않은 이유는, 다른 모듈과 명단을 대조할 때
이메일이 공통 키이기 때문입니다.

### 4.3 구성원 명단

`portal_users` 가 곧 명단입니다. 역할 배정 화면의 구성원 목록도 여기서 옵니다.

주의할 점 두 가지입니다.

- **퇴사 구분 컬럼이 없습니다.** `active_where` 를 비워 두어 전원이 나옵니다.
  퇴사자 구분이 생기면 그때 조건을 넣으면 됩니다.
- **슬랙 사용자 ID 컬럼이 없습니다.** `slack_token_enc` 는 개인 토큰이라
  용도가 다릅니다. 슬랙 DM 은 이메일로 `users.lookupByEmail` 을 호출해 찾습니다
  (5.3 참고).

### 4.4 화면

상단바는 포털 `styles/topbar.css` 를 그대로 가져다 씁니다. 로고, 사용자 칩,
아바타 색(`user_color()`), 업무 시스템 드롭다운, 로그아웃이 다른 모듈과 같습니다.

모듈 자체 스타일(`assets/app.css`)은 `.bc-` 네임스페이스만 쓰고, 색과 글꼴은
포털 `styles/default.css` 의 값을 그대로 가져와 맞췄습니다.
포털 스타일을 덮어쓰지 않습니다.

### 4.5 포털이 없으면

`bluecart/` 를 단독 폴더에 두면 포털 파일을 못 찾고 단독 모드로 뜹니다.
이때는 `dev/login.php` 로 계정을 골라 들어가고, 상단바는
`assets/topbar-fallback.css` 로 비슷하게 그립니다.
로컬에서 포털 없이 화면만 손볼 때 쓰라고 남겨 둔 경로입니다.

---

## 5. 알림

### 5.1 설정 방식

**관리자 탭 → 알림 설정**에서 (처리 단계 × 수신 대상 × 발송 방법) 매트릭스를
체크박스로 켜고 끕니다.

| 처리 단계 | 기본 수신 대상 |
|---|---|
| 구매 요청 등록 | 검토승인자 |
| 반려 후 재요청 | 검토승인자 |
| 검토 승인 | 구매담당자, 요청자 |
| 구매 담당 지정 | 지정된 구매담당자 |
| 검토 반려 | 요청자 |
| 구매 진행 시작 | 요청자 |
| 구비 완료 | 요청자, 검토승인자 |

발송 방법은 이메일 / 슬랙 채널 / 슬랙 개인 DM 을 다중 선택합니다.

"구매담당자"에게 가는 알림은 담당이 지정된 건이면 **그 사람에게만**, 지정 전이면
**배정된 구매담당자 전원에게** 갑니다. 7장 참고.

### 5.2 이메일

`notify.mail.transport` 가 `mail` 이면 PHP `mail()`, `smtp` 면 내장 SMTP
구현(AUTH LOGIN, STARTTLS)을 씁니다. PHPMailer를 이미 쓰고 있다면
`includes/notify/MailChannel.php` 의 `send()` 내부만 갈아 끼우면 됩니다.

### 5.3 슬랙

개인 DM 을 쓰려면 봇 토큰이 필요합니다.

1. Slack 앱 생성 → **OAuth & Permissions**
2. Bot Token Scopes: `chat:write`, `users:read`, `users:read.email`
3. 워크스페이스에 설치하고 `xoxb-` 토큰을 `BLUECART_SLACK_BOT_TOKEN` 에 설정
4. 채널 발송을 쓸 채널에 봇을 초대

`col_slack_id` 매핑이 없으면 이메일로 슬랙 사용자를 조회합니다
(`users.lookupByEmail`). 채널 발송만 필요하면 Incoming Webhook 만으로도 됩니다.

### 5.4 실패 처리

발송은 `bc_notify_log` 에 적재 후 시도하고, 실패 건은 `FAILED` 로 남습니다.
`cron/notify_retry.php` 가 5회까지 재시도합니다. **알림 실패가 업무 처리
자체를 막지는 않습니다.** 승인·구매 처리는 정상 완료되고 알림만 따로 재시도됩니다.

발송 현황 확인:

```sql
SELECT channel, status, COUNT(*) FROM bc_notify_log GROUP BY channel, status;
SELECT * FROM bc_notify_log WHERE status='FAILED' ORDER BY id DESC LIMIT 20;
```

---

## 6. 화면

상세와 입력 화면은 **우측에서 밀려 나오는 드로어**입니다. iworks 안의 다른
프로그램과 조작 방식을 맞춘 것이고, 뒤쪽 목록이 그대로 보여서 한 건 처리하고
다음 건으로 넘어가기 쉽습니다. 바깥 어두운 영역을 누르거나 `Esc` 로 닫습니다.

### 6.1 구성원 탭

- **새 구매 요청** — 사용처, 필요 물품, 갯수/단위, 예상 금액, 수령 장소,
  희망 수령일, 참고 링크, 비고, 첨부파일
- **수령 장소** 는 목록에서 고릅니다 — 선택 안 함 / 사무실 / CAFE / 기타.
  선택지는 `includes/workflow.php` 의 `BC_DELIVER_PLACES` 에 있습니다.
  자세한 위치는 비고란에 적습니다
- 검토승인 권한자에게는 **승인없이 구매진행** 버튼이 함께 보입니다 (7.1 참고)
- **집계 영역** — 검토 대기 / 구매 대기 / 구매 진행 / 구비 완료 건수.
  각 칸을 누르면 그 상태로 목록이 걸러집니다
- **필터** — 연도(기본값 올해), 사용처, 요청일 범위, 키워드, 정렬
- **목록 탭** — 구매 진행중 물품 / 구비완료 물품 / 반려·철회 / 전체
- 기본 정렬은 최신 요청 순
- 목록 탭 오른쪽에 **내 신청만 · 보기 방식 · 엑셀 받기** 가 함께 있습니다
- **내 신청만** — 처리 역할(검토승인자/구매담당자/관리자)이 없는 사람은 기본으로 켜집니다.
  끄면 전체가 보입니다
- **보기 방식** — 목록(표)과 카드 중에서 고릅니다. 담는 내용은 같습니다
- **엑셀 받기** — 화면에 걸린 필터 그대로 내보냅니다

### 6.2 관리자 탭

검토승인자·구매담당자는 **신청 물품 관리**와 **통계**를, 관리자는 추가로
**물품 카테고리**, **처리 역할 배정**, **알림 설정**을 볼 수 있습니다.

- **신청 물품 관리** — "내가 처리할 건" 탭이 기본. 역할에 따라 승인/반려 또는
  구매 진행/구비 완료 버튼이 뜹니다. "내가 맡은 건" 탭은 나에게 배정된 건만 봅니다
- **통계** — 상태별 건수, 월별·사용처별·요청자별·구매담당자별 건수,
  자주 구비한 물품, 평균 검토 소요 시간, 요청→구비 평균 소요 시간.
  엑셀로 내보낼 수 있습니다
- **처리 역할 배정** — 구성원 목록에서 다중 선택. 배정된 전원에게 알림이 갑니다

---

## 7. 승인 생략과 구매 담당 지정

### 7.1 승인 생략

검토승인자나 관리자가 직접 요청을 올릴 때는 자기가 자기 요청을 검토하는 셈이라
검토 단계가 비어 있습니다. 요청 작성 화면의 **승인없이 구매진행** 버튼을 누르면
등록과 동시에 승인 처리되어 바로 구매 대기로 넘어갑니다.

- 이 버튼은 **검토승인자와 관리자에게만**, **새 요청을 올릴 때만** 보입니다.
  남의 요청을 대신 수정하는 경우에는 나오지 않습니다. 그때까지 건너뛰면
  누가 검토했는지 기록이 비어 버리기 때문입니다.
- 이력에는 `구매 요청 등록` 과 `검토 승인` 이 **둘 다** 남고, 검토 의견란에
  생략했다는 사실이 기록됩니다. 나중에 왜 검토 없이 넘어갔는지 확인할 수 있습니다.
- 서버에서 권한을 다시 확인합니다. 화면에서 버튼이 안 보여도 요청을 직접 보내면
  403 으로 막힙니다.
- 구매담당자에게는 평소 승인과 똑같이 알림이 갑니다.

### 7.2 구매 담당 지정

승인된 건을 누가 살지 정하는 방식입니다.

**구매담당자가 한 명뿐이면 이 단계는 아예 없습니다.** 지정할 대상이 없으니
담당 지정 버튼도 나오지 않고, 목록의 구매담당 칸에는 그 사람 이름이 바로 뜹니다.
그 사람이 구매 진행과 구비 완료를 그대로 처리합니다.

아래는 **구매담당자가 두 명 이상일 때** 이야기입니다.

- 검토승인자가 **승인하면서 구매담당자를 지목**할 수 있습니다. 선택 사항입니다.
- 지목하지 않으면 배정된 구매담당자 **전원에게 알림**이 가고, 그중 누구나
  집어 갈 수 있습니다. 소규모 팀에서는 이 방식이 더 편합니다.
- 지목된 건은 **그 사람과 관리자만** 구매 진행·구비 완료로 바꿀 수 있습니다.
  다른 구매담당자에게는 처리 버튼이 아예 보이지 않습니다.
- 담당은 승인 이후 언제든 **담당 지정** 버튼으로 바꿀 수 있습니다.
  검토승인자, 구매담당자, 관리자가 바꿀 수 있습니다.
- 담당으로 지정되면 그 사람에게만 알림이 갑니다.
- 배정된 구매담당자 중에서만 고를 수 있습니다. 역할이 없는 사람에게 떠넘기면
  그 사람 화면에는 처리 버튼이 뜨지 않아 아무것도 못 하기 때문입니다.
- 담당자를 한 명으로 줄이면 지정 단계가 다시 사라지고, 두 명 이상으로 늘리면
  다시 나타납니다. 이미 지정된 건의 담당자는 그대로 유지됩니다.

`assignee_*` 는 "맡기로 한 사람", `buyer_*` 는 "실제로 상태를 바꾼 사람"으로
나뉘어 기록됩니다. 보통 같지만 관리자가 대신 처리하면 달라집니다.

---

## 8. 첨부파일

견적서, 제품 사진, 영수증 등을 요청에 붙일 수 있습니다.

### 8.1 올릴 수 있는 사람과 시점

| | 검토 대기 / 반려 | 승인 · 구매 진행 | 구비 완료 | 철회 |
|---|---|---|---|---|
| 요청자 | O | X | X | X |
| 검토승인자 · 구매담당자 | O | O | X | X |
| 관리자 | O | O | O | O |

요청자는 심사 중인 내용이 바뀌면 곤란하므로 승인 이후에는 붙일 수 없고,
구매담당자는 영수증을 남겨야 하므로 구비 완료 전까지 붙일 수 있습니다.
삭제는 올린 본인이나 관리자만 할 수 있습니다.

요청당 10개까지, 파일당 기본 10MB 입니다. 한도는 `config.php` 의
`app.upload_max`, `api/attachments.php` 의 `MAX_PER_REQUEST` 에 있습니다.

### 8.2 안전장치

- 저장 위치가 **웹 루트 바깥**이라 URL 로 직접 열 수 없습니다.
  `api/attachment_download.php` 를 거쳐야만 내려받아집니다.
- 저장 파일명은 **난수**로 바꿉니다. 원본 이름은 DB 에만 두고 내려받을 때 복원합니다.
- 확장자 화이트리스트(`app.upload_ext`)에 더해, **파일 내용이 확장자와
  맞는지** `fileinfo` 로 확인합니다. `.php` 를 `.png` 로 바꿔 올려도 막힙니다.
- 내려받기는 항상 `application/octet-stream` + `nosniff` 입니다.
  HTML 이나 SVG 가 같은 출처에서 실행되지 않게 하려는 것입니다.
- 파일 이름의 경로 구분자와 제어문자는 걷어냅니다.

### 8.3 저장 구조

```
/var/www/iworks-data/bluecart/2026/{요청id}/{난수32자}.{확장자}
```

요청을 지우면 DB 레코드는 외래키로 함께 지워집니다.
디스크 파일은 `Attachment::delete()` 를 거칠 때만 지워지므로, 요청을 직접
`DELETE` 하면 파일이 남습니다. 정리가 필요하면 아래로 고아 파일을 찾습니다.

```sql
SELECT stored_path FROM bc_attachment;   -- 이 목록에 없는 파일이 고아
```

---

## 9. 엑셀 내보내기

### 9.1 목록

구성원 탭과 관리자 탭의 **엑셀 받기** 버튼은 화면에 걸린 필터(연도, 사용처,
탭, 검색어, 기간, 정렬)를 그대로 넘겨 같은 조건으로 내보냅니다.
화면은 30건씩 끊어 보여주지만 엑셀은 **조건에 맞는 전체**를 담습니다.

컬럼: No., 요청번호, 사용처, 필요 물품, 필요 갯수, 단위, 신청일, 신청자,
처리상태, 처리일, 검토자, 구매담당, 처리자, 예상금액, 실구매금액, 수령장소,
희망수령일, 참고링크, 비고, 검토의견/반려사유, 구매메모, 재요청횟수

### 9.2 통계

관리자 탭 → 통계의 **통계 엑셀 받기** 는 시트 6장으로 내보냅니다.
요약 / 월별 / 사용처별 / 요청자별 / 구매담당자별 / 자주 구비한 물품.

### 9.3 구현 메모

`includes/XlsxWriter.php` 는 PhpSpreadsheet 없이 xlsx 를 직접 씁니다.
iworks 에 composer 를 들이지 않으려는 선택입니다. ZipArchive 확장에도
의존하지 않고 ZIP 컨테이너를 직접 씁니다.

지원: 여러 시트, 굵은 머리글, 열 너비, 숫자/문자 구분, 틀 고정, 자동 필터.
지원하지 않음: 수식, 차트, 셀 서식, 이미지.

수량이 많아지면 메모리를 쓰므로 10만 건에서 멈추게 해 두었습니다.
그 이상을 내보낼 일이 생기면 스트리밍 방식으로 바꿔야 합니다.

---

## 10. 반려 후 처리 정책

요건 4.2-2에 대한 판단입니다.

1. 반려 시 **사유 입력이 필수**입니다. 빈 값이면 저장되지 않습니다.
2. 요청자는 반려 건을 **수정해 재요청**하거나 **철회**할 수 있습니다.
   재요청해도 요청번호는 그대로 유지되고 재요청 횟수만 누적됩니다.
   이전 반려 사유는 처리 이력에 남습니다.
3. 반려 건은 목록 기본 필터에서 빠지고 "반려·철회" 탭에서 봅니다.
4. 반려 후 **14일** 동안 아무 조치가 없으면 cron이 자동 철회합니다.
   기간 변경:

```sql
UPDATE bc_setting SET v='30' WHERE k='reject_auto_close_days';
-- 0 으로 두면 자동 철회를 끕니다
```

---

## 11. 데이터 구조

| 테이블 | 용도 |
|---|---|
| `bc_category` | 사용처(BLUESOFT / CAFE45CM / 그 외 기타) |
| `bc_request` | 구매 요청 본문 |
| `bc_request_history` | 상태 전이 감사 로그 |
| `bc_attachment` | 첨부파일 메타데이터 (실제 파일은 웹 루트 바깥) |
| `bc_role_assign` | 검토승인자 / 구매담당자 / 관리자 배정 |
| `bc_notify_setting` | 이벤트 × 역할 × 채널 알림 매트릭스 |
| `bc_notify_log` | 발송 로그 겸 재시도 큐 |
| `bc_setting` | 키-값 운영 설정 |

요청번호는 `2026-0001` 형식으로 연도별 채번합니다.
동시 등록 시 번호가 겹치지 않게 트랜잭션 안에서 `FOR UPDATE` 로 잠급니다.

기존 엑셀 양식과의 대응:

| 엑셀 | BlueCart |
|---|---|
| 사용처 | `category_id` |
| 필요 물품 | `item_name` |
| 필요 갯수 | `quantity` + `unit` |
| 신청일 / 신청자 | `requested_at` / `requester_id`, `requester_name` |
| 처리상태 | `status` |
| 처리일 / 처리자 | `reviewed_at`, `stocked_at` / `reviewer_*`, `assignee_*`, `buyer_*` |
| 비고 | `note` + `deliver_to`, `ref_url`, `actual_amount` 로 분리 |

비고란에 반복적으로 적히던 배송지·상품 링크·결제 금액은 별도 컬럼으로 뺐습니다.
통계에 쓸 수 있고 검색도 됩니다.

---

## 12. 기존 엑셀 데이터 이관

`sql/02_seed.sql` 실행 후, 엑셀을 CSV로 저장해 옮깁니다.
2026년 시트 기준 예시:

```sql
-- 임시 적재 테이블
CREATE TABLE tmp_import (
  no INT, usage_name VARCHAR(80), item VARCHAR(200), qty VARCHAR(20),
  req_date VARCHAR(20), requester VARCHAR(80), status_txt VARCHAR(40),
  done_date VARCHAR(20), handler VARCHAR(80), note TEXT
);
-- LOAD DATA INFILE 또는 클라이언트로 CSV 적재 후

INSERT INTO bc_request
  (req_year, req_seq, req_no, category_id, item_name, quantity, unit,
   note, status, requester_id, requester_name, requested_at, stocked_at, buyer_name)
SELECT
  2026, t.no, CONCAT('2026-', LPAD(t.no, 4, '0')),
  COALESCE(c.id, (SELECT id FROM bc_category WHERE code='ETC')),
  t.item,
  CASE WHEN t.qty REGEXP '^[0-9]+$' THEN CAST(t.qty AS UNSIGNED) ELSE 1 END,
  '개',
  CONCAT_WS(' / ', NULLIF(t.note,''),
            CASE WHEN t.qty NOT REGEXP '^[0-9]+$' THEN CONCAT('수량 원문: ', t.qty) END),
  CASE WHEN REPLACE(t.status_txt,' ','') = '구비완료' THEN 'STOCKED'
       WHEN REPLACE(t.status_txt,' ','') = '주문완료' THEN 'PURCHASING'
       ELSE 'REQUESTED' END,
  '', t.requester,
  STR_TO_DATE(t.req_date, '%Y.%m.%d'),
  STR_TO_DATE(t.done_date, '%Y.%m.%d'),
  t.handler
FROM tmp_import t
LEFT JOIN bc_category c ON c.code = t.usage_name;
```

주의할 점:

- `requester_id` 는 빈 문자열로 들어갑니다. 엑셀에는 성명만 있고 iworks
  아이디가 없기 때문입니다. 이관 후 성명↔아이디 대응표로 채우세요.
  채우지 않으면 이관 건이 "내 요청" 필터에 잡히지 않습니다.
- 수량이 `소량` 처럼 숫자가 아닌 행이 있습니다. 위 SQL은 1로 넣고 원문을
  비고에 붙입니다.
- 신청일에 `20226.03.19` 같은 오타가 있습니다. `STR_TO_DATE` 가 NULL을
  반환하므로 이관 전에 정리하거나 이관 후 확인하세요.
- 처리일이 `46100` 같은 엑셀 일련값으로 남은 행이 있습니다.
  `DATE_ADD('1899-12-30', INTERVAL 46100 DAY)` 로 변환됩니다.

이관 검증:

```sql
SELECT status, COUNT(*) FROM bc_request WHERE req_year=2026 GROUP BY status;
SELECT * FROM bc_request WHERE requested_at IS NULL OR requester_name='';
```

---

## 13. 테스트

개발 환경에서 워크플로 전체를 검증합니다.
**운영 DB를 가리키지 않도록 `config/config.test.php` 를 먼저 확인하세요.**

```bash
mysql -u root -p -e "CREATE DATABASE iworks_test DEFAULT CHARSET utf8mb4"
mysql -u root -p --default-character-set=utf8mb4 iworks_test < sql/01_schema.sql
mysql -u root -p --default-character-set=utf8mb4 iworks_test < sql/02_seed.sql
mysql -u root -p --default-character-set=utf8mb4 iworks_test < tests/fixture.sql
php tests/workflow_test.php
```

123건을 확인합니다.

- 요청 등록과 연도별 번호 채번, 입력 검증
- 역할별 권한 차단, 승인/반려/재요청/철회 전 경로, 처리 이력
- 알림 적재와 설정 on/off 반영
- 목록 필터, 정렬, SQL 주입 방어
- 통계, 카테고리 관리, 반려 건 자동 철회 판정
- 건별 담당 지정, 담당자 외 처리 차단, 담당 기준 알림 분기
- 첨부 저장·삭제, 확장자/내용 불일치 차단, 시점별 첨부 권한
- 엑셀 생성(ZIP 구조, 특수문자 이스케이프)

마이그레이션을 검증하려면 이전 스키마로 만든 DB 에 `sql/03_migration_v2.sql`
을 적용한 뒤, 과거 건의 `assignee_name` 이 `buyer_name` 으로 채워졌는지
확인하세요.

### 13.1 운영 DB(MySQL 8)에서도 확인

로컬은 MySQL 5.x, 운영은 8.x 라 버전 차이가 있습니다. 스키마와 질의는 양쪽
모두에서 확인했지만, 배포 전에 운영 서버에서도 한 번 돌려 두면 안전합니다.

```bash
# 운영 서버에서 — 별도 테스트 DB 를 씁니다
mysql -u root -p -e "CREATE DATABASE iworks_test DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p --default-character-set=utf8mb4 iworks_test < sql/01_schema.sql
mysql -u root -p --default-character-set=utf8mb4 iworks_test < sql/02_seed.sql
mysql -u root -p --default-character-set=utf8mb4 iworks_test < tests/fixture.sql

BCTEST_DB_USER=root BCTEST_DB_PASS=비밀번호 php tests/workflow_test.php

# 끝나면 지웁니다
mysql -u root -p -e "DROP DATABASE iworks_test"
```

환경 점검도 운영에서 그대로 쓸 수 있습니다.

```bash
php dev/check.php
```

`dev/` 를 이미 지웠다면 `check.php` 하나만 따로 올려 실행해도 됩니다.
다른 파일에 의존하지 않습니다.

`tests/` 와 `config/config.test.php` 는 개발용입니다. 운영 배포 시 제외하세요.

---

## 14. git 저장소 만들기

로컬에서 확인이 끝났으면 형상 관리에 올립니다.

### 14.1 커밋 전 확인

접속 정보가 섞여 들어가지 않는지 먼저 봅니다.

```cmd
cd J:\kimhy\private_project\bluecart
git init
git add .
git status
```

`config/config.php` 가 목록에 **없어야** 합니다. `.gitignore` 가 막고 있습니다.
보인다면 `.gitignore` 가 제대로 들어왔는지 확인하세요.

```cmd
git diff --cached | findstr /I "password passwd token secret xoxb-"
```

아무것도 안 나와야 합니다.

### 14.2 첫 커밋

```cmd
git commit -m "BlueCart: 사내 물품 구매 요청 시스템 초기 구현"
git branch -M main
git remote add origin <저장소 주소>
git push -u origin main
```

### 14.3 저장소에 들어가는 것 / 빠지는 것

| 들어감 | 빠짐 (`.gitignore`) |
|---|---|
| 소스 전체, `sql/`, `dev/`, `tests/` | `config/config.php` (접속 정보) |
| `config/config.sample.php` | `bluecart-data/` (첨부 실물) |
| `config/config.test.php` (접속정보 없음) | `*.log`, `.vscode/`, `.idea/` |

`dev/` 와 `tests/` 는 저장소에 두되 **배포할 때 빼는** 방식입니다.
다른 사람이 받아서 바로 로컬 환경을 만들 수 있어야 하기 때문입니다.

### 14.4 줄바꿈 주의

`dev\*.bat` 은 Windows 배치 파일이라 CRLF 여야 합니다.
git 이 줄바꿈을 바꾸지 않도록 `.gitattributes` 를 함께 넣어 두었습니다.

---

## 15. 운영 서버에 올리기

로컬 확인 → git → 서빙 순서의 마지막 단계입니다.

### 15.1 소스 배포

```bash
cd /var/www/iworks
git clone <저장소 주소> bluecart
cd bluecart

# 점검 스크립트는 운영에서도 쓸모가 있으니 먼저 한 번 돌립니다
php dev/check.php

# 그다음 개발 전용 파일을 지웁니다
rm -rf dev tests config/config.test.php
```

`dev/` 는 비밀번호 없이 계정을 바꿔 주는 파일이 들어 있습니다.
`.htaccess` 와 코드 양쪽에서 막고 있지만, 아예 없는 편이 확실합니다.

이후 절차는 3장(운영 서버 설치)을 따릅니다. DB 생성, `config/config.php` 작성,
권한 설정, 첨부 저장소 생성, crontab 등록.

### 15.2 로컬과 운영의 차이

| | 로컬 | 운영 |
|---|---|---|
| 로그인 | `dev/login.php` 로 계정 선택 | iworks 세션 (4장) |
| 회원 목록 | `bc_dev_member` (가짜) | iworks 회원 테이블 |
| 웹서버 | PHP 내장 서버 | Apache / Nginx |
| 알림 | 기록만 (`notify.enabled = false`) | 실제 발송 |
| 오류 표시 | 화면에 그대로 (`debug = true`) | 로그에만 (`debug = false`) |
| 첨부 저장소 | `J:\kimhy\private_project\bluecart-data` | `/var/www/iworks-data/bluecart` |
| MySQL | 5.x (원격 개발 서버) | 8.x |
| PHP | 8.x | 8.x |

운영 `config.php` 에서 **`debug` 는 반드시 `false`**, **`notify.enabled` 는 `true`** 로 두세요.

### 15.3 배포 후 점검

```bash
# 1. 화면이 뜨는가
curl -I http://iworks.bizblue.co.kr/bluecart/

# 2. 보호 디렉터리가 막혔는가 (모두 403 또는 404 여야 함)
for p in config/config.php includes/db.php sql/01_schema.sql; do
  curl -s -o /dev/null -w "$p -> %{http_code}\n" "http://iworks.bizblue.co.kr/bluecart/$p"
done

# 3. 첨부 저장소에 웹으로 닿지 않는가 (404 여야 함)
curl -s -o /dev/null -w "%{http_code}\n" http://iworks.bizblue.co.kr/iworks-data/
```

```bash
# 4. 환경 점검 (dev/ 를 지우기 전에 한 번 돌려 두면 좋습니다)
php dev/check.php
```

PHP 버전, 확장 모듈, DB 접속과 버전, 첨부 저장소 권한을 한 번에 봅니다.
`upload_dir` 이 프로그램 폴더 안에 있으면 오류로 잡아 줍니다.

브라우저로 들어가 로그인 사용자 이름이 맞게 나오는지, 관리자 탭이
권한대로 보이는지 확인합니다. 그다음 시험 요청을 하나 올려
알림이 실제로 오는지 봅니다.

### 15.4 이후 배포

```bash
cd /var/www/iworks/bluecart
git pull
# 스키마 변경이 있으면 해당 마이그레이션 실행
```

`config/config.php` 는 저장소에 없으므로 `git pull` 로 덮어써지지 않습니다.

---

## 16. 아직 안 된 것

- **슬랙 설정 화면** — 봇 토큰은 `config.php` 에만 있고 화면에서 못 바꿉니다.
  연결 테스트 버튼, 이벤트별 채널 지정, 슬랙 계정 매핑 현황도 없습니다. 5장 참고.
- **퇴사자 처리** — `portal_users` 에 구분 컬럼이 없어 전원이 명단에 나옵니다.
- **첨부 미리보기** — 이미지도 내려받기만 됩니다.
- **엑셀 스트리밍** — 전체를 메모리에 올려 만듭니다(10만 건에서 중단).
- **알림 문구 편집** — 메일·슬랙 본문 형식이 코드에 있습니다.
- **고아 첨부파일 정리** — 요청을 SQL 로 직접 지우면 디스크 파일이 남습니다. 8.3 참고.

# dti 설치 · 운영 세팅

포털(`readme.md`)이 전체 구조를 다루고, 이 문서는 **dti 를 서버에 올릴 때 따로 해줘야 하는
일**만 적는다. dti 자체는 포털과 같은 PHP 앱이라 파일 복사 외에 필요한 건 발표자료 PDF 변환
뿐이다.

---

## 1. 발표자료 PDF 변환 (LibreOffice)

pptx·ppt·odp 를 올리면 업로드 시점에 PDF 로 변환해 **같은 칸에 별개 자료로 하나 더** 넣는다.
원본과 변환본은 독립이라 각자 내려받고 각자 지운다.

**이 기능을 안 쓰면 아무것도 설치하지 않아도 된다** — 변환에 실패하면 PPT 만 올라가고 PDF 행이
안 생긴다. 업로드 자체는 성공하므로 기존 기능은 그대로 동작한다.

### 설치

```bash
sudo apt install libreoffice-impress fonts-noto-cjk
which soffice
```

`fonts-noto-cjk` 를 빼면 변환은 되지만 **PDF 의 한글이 빈 사각형**으로 나온다.

### 설치 후 확인 — 웹 서버 사용자로 돌려볼 것

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

## 2. PHP 설정

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

## 3. 설정값

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

## 4. 업로드 디렉터리

`dti/var/uploads` 는 문서루트 아래라 직접 접근이 가능하다. `.htaccess`(`Require all denied`)로
막아두고 내려받기는 앱을 거치게 한다. 이 파일이 빠지면 업로드 원본이 URL 로 노출된다.

웹 서버 사용자에게 쓰기 권한이 필요하다. 변환본도 같은 디렉터리에 들어가므로 PPT 를 많이
올리면 저장 용량이 원본 + PDF 만큼 쓰인다.

---

## 5. 증상별 확인

| 증상 | 확인할 것 |
| --- | --- |
| PPT 는 올라가는데 PDF 자료가 안 생긴다 | `sudo -u www-data soffice ...` 를 직접 돌려본다. 웹 SAPI 의 `disable_functions` 에 `exec` 가 있는지 본다 |
| PDF 는 생기는데 한글이 빈 사각형 | `fonts-noto-cjk` 미설치 |
| 큰 PPT 를 올리면 화면에 실패로 뜨는데 PPT 는 들어가 있다 | `max_execution_time` < `soffice_timeout` |
| 50MB 보다 작은 파일도 413 | `upload_max_filesize`·`post_max_size` |
| 업로드가 계속 느리다 | 변환이 동기라 원래 몇 초 걸린다. 첫 변환은 프로필을 새로 만들어 더 느리다 |
| `/tmp` 에 `dti-soffice-*`·`dti-convert-*` 가 쌓인다 | 변환기가 작업 디렉터리를 정리한다. 남아 있으면 프로세스가 강제 종료된 흔적이다 |

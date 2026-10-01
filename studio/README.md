# BlueStudio — 운영 서버에 올리는 사람을 위한 문서

이 문서는 **서버에 올리고 DB 를 적용하는 사람**이 보는 것입니다.
기능 설명이 아니라 절차와, 틀리기 쉬운 자리만 적었습니다.

개발용 안내는 `dev/README.md`, 수집기는 `collector/README.md` 에 따로 있습니다.
설계 근거는 `docs/bluestudio-spec.md` 입니다. 셋 다 저장소에 올라가지 않습니다
(`.gitignore` 가 `*.md` 를 막고 있고, 이 파일만 예외로 열어 두었습니다).

---

## 0. 포털 등록 — 끝났습니다

세 곳 모두 `studio` 로 맞췄습니다 (2026-10-01).

| 위치 | 값 |
|---|---|
| `core/worksystems.json` | `key: "studio"`, `path: "studio/index.php"`, `label: "BlueStudio"` |
| 루트 `index.php` ICONS 맵 | `studio: studioIcon` |
| `studio/inc/bootstrap.php` | `const BS_MODULE_KEY = 'studio';` |

**한 곳만 고치면 어긋납니다.** json 만 고치면 상단바에서 현재 위치 표시가
안 되고, `BS_MODULE_KEY` 만 고치면 타일이 없는데 모듈만 자기를 `studio` 라고
부릅니다. 나중에 키를 또 바꾼다면 세 곳을 함께 보십시오.

`worksystems.json` 의 `color` 는 `assets/assign.css` 의 `--ba-brand` 와 같은
값이어야 합니다(`#2B7A4B`). 한쪽만 바꾸면 포털 타일과 모듈 안의 색이
어긋납니다.

---

## 1. DB 적용

```sh
sh studio/sql/dump.sh  <db> -u <user> -p -o ~/backup    # 먼저. 반드시.
sh studio/sql/apply.sh <db> -u <user> -p
```

`-p` 는 **비밀번호를 한 번만 묻습니다.** 받은 값은 600 권한의 임시 설정
파일에 넣고 `--defaults-extra-file` 로 넘긴 뒤, 끝날 때(중간에 끊겨도) 지웁니다.
명령줄에 `-p비번` 처럼 적지 마십시오 — `ps` 에 그대로 보입니다.

**`sh` 를 붙여 부르십시오.** 윈도우에서 만든 저장소라 실행 권한이 붙지 않은
채로 올라갈 수 있습니다(`core.fileMode=false`). 그대로 부르면
`Permission denied` 가 납니다. `sh` 로 부르면 권한과 무관하게 돌아갑니다.
직접 실행하고 싶으면 `chmod +x studio/sql/*.sh` 를 한 번 하십시오.

### 운영은 MariaDB 입니다

`mysql` 을 부르면 `Deprecated program name` 경고가 납니다. MariaDB 가
`mysql` 을 `mariadb` 의 옛 이름으로 보기 때문이며, 동작에는 지장이 없습니다.

스키마는 **MySQL 5.7 호환 범위로만** 썼습니다 — 윈도 함수·CTE·JSON 컬럼·
함수 인덱스·`utf8mb4_0900` 콜레이션을 쓰지 않았습니다(`001_schema.sql` 머리말).
그래서 MariaDB 에서도 그대로 돕니다.

`dump.sh` 는 MySQL 전용 옵션(`--set-gtid-purged`)을 `mysqldump --help` 로
물어본 뒤 있을 때만 붙입니다. MariaDB 에서는 붙이지 않습니다.

### 처음 올리는 DB 라면

`bs_` 로 시작하는 표가 하나도 없다고 나옵니다. **정상입니다.**
`dump.sh` 는 그때 전체 덤프만 뜨고 `bs_*` 덤프는 건너뜁니다 —
운영 DB 에는 다른 모듈의 표가 들어 있으므로 전체 덤프는 그래도 떠야 합니다.

DB 이름은 포털 `config.php` 의 `db.name` 과 같아야 합니다. BlueStudio 는
포털과 같은 DB 를 씁니다(`bs_db()` → `portal_db()`).


### 왜 스크립트를 쓰는가

**번호 순서대로 다 돌리면 003 에서 멈춥니다.**
`003`·`004` 가 추가하는 컬럼은 `001_schema.sql` 에 이미 들어 있습니다.
빈 DB 에 `001` 부터 넣으면 `003` 은 `ERROR 1060 Duplicate column` 으로 죽고,
`mysql` 은 그 파일을 중단합니다. 배포 스크립트가 `set -e` 면 거기서 전체가
멈춰 **004~012 가 통째로 빠집니다.** `apply.sh` 는 두 파일을 건너뜁니다.

**`--default-character-set=utf8mb4` 를 빠뜨리면 한글 COMMENT 가 깨집니다.**
표와 컬럼은 멀쩡히 생기고 주석만 `????` 가 되므로 알아채기 어렵습니다.
`apply.sh` 는 항상 붙입니다. 손으로 돌릴 때도 반드시 붙이십시오.

### 적용 순서

```
001_schema.sql                      전체 스키마
002_seed_domain.sql                 분야 마스터
  003 · 004                         ← 건너뜀 (001 에 이미 있음)
005_migration_domain_category.sql   분야 계열 교체
006_migration_category_score.sql    계열 단위 역량 점수 표
007_migration_notify_outbox.sql     bs_notification        ← 빠지면 알림 적재가 깨짐
008_migration_workload_source.sql   bs_workload 출처·작성자 ← 빠지면 직접 등록 점유가 깨짐
009_migration_metric_comment.sql    컬럼 주석 정리
010_migration_rnd.sql               R&D 과제 표 4개 + bs_project 컬럼
011_migration_rnd_cap.sql           bs_setting + 점유 상한 4행
012_migration_rnd_domain.sql        R&D 분야 태그
013_migration_domain_group.sql      분야 묶음(코스모스 LXP / 일반) + 일반 분야 6개
```

### 적용 뒤 확인

```sh
mysql <db> -e "SHOW TABLES LIKE 'bs\_%'"           # 표가 다 생겼는가
mysql <db> -e "SHOW FULL COLUMNS FROM bs_project"  # 주석이 ???? 가 아닌가
mysql <db> -e "SELECT * FROM bs_setting"           # 4행인가
```

### 다시 돌려도 되는가

| 파일 | 재실행 |
|---|---|
| 002 · 005 · 006 · 007 · 010 · 011 · 012 | 안전 (`IF NOT EXISTS` / `ON DUPLICATE KEY`) |
| 013 | **안전하지 않음** — 맨 `ALTER` 라 두 번째에 죽습니다 (INSERT 쪽은 안전) |
| 001 | 안전하지 않음 (`CREATE TABLE`) |
| 008 · 009 | **안전하지 않음** — 맨 `ALTER` 라 두 번째에 죽습니다 |

중간에 실패했으면 다시 돌리지 말고, **덤프를 되돌린 뒤 처음부터** 하십시오.

---

## 2. 되돌리기

되돌리기 스크립트는 `010`·`011`·`012`·`013` 에만 있습니다.
**`001`~`009` 에는 없습니다.** 그 구간을 되돌리는 방법은 덤프 복원 하나뿐입니다.
그래서 `apply.sh` 전에 `dump.sh` 를 먼저 돌려야 합니다.

`dump.sh` 는 두 벌을 뜹니다 —

| 파일 | 쓰임 |
|---|---|
| `<db>_full_<시각>.sql.gz` | DB 전체. 최후의 보루 |
| `<db>_bs_<시각>.sql.gz` | `bs_*` 표만. **실제로 쓸 쪽** |

운영 `iworks` DB 에는 다른 모듈의 표도 함께 들어 있습니다. 전체를 되돌리면
그 사이 쌓인 **다른 모듈의 데이터까지 같이 날아갑니다.**

```sh
# BlueStudio 만 되돌리기
sh studio/sql/dump.sh --drop-sql <db> | mysql <db>
gunzip -c <db>_bs_<시각>.sql.gz | mysql --default-character-set=utf8mb4 <db>

# 010~012 만 되돌리기 (R&D 기능만 물리고 싶을 때)
mysql --default-character-set=utf8mb4 <db> < studio/sql/013_rollback_domain_group.sql
mysql --default-character-set=utf8mb4 <db> < studio/sql/012_rollback_rnd_domain.sql
mysql --default-character-set=utf8mb4 <db> < studio/sql/011_rollback_rnd_cap.sql
mysql --default-character-set=utf8mb4 <db> < studio/sql/010_rollback_rnd.sql
```

롤백 스크립트는 **번호 역순**으로 돌리십시오. 012 가 010 이 만든 표를 참조합니다.

---

## 3. 환경별로 달라야 하는 값

### 저장소에 올라가지 않는 파일 — 서버에서 직접 만듭니다

| 파일 | 들어가는 것 |
|---|---|
| `<포털>/config.php` | DB 접속, `key`(암호화 키 — **운영 키로 교체**), `links` |
| `<포털>/sso_secret.key` | book 모듈 SSO |
| `studio/inc/env.config.php` | 업로드 경로 · pdftotext · 슬랙 채널 (표본: `inc/env.config.sample.php`) |
| `studio/inc/llm.config.php` | LLM 백엔드. 없으면 WBS 자동 도출만 안 됩니다 (수동 입력은 가능) |
| `studio/collector/config.prod.ini` | 수집기 DB·터널·`list_url` |

`env.config.php` 와 `llm.config.php` 는 **없어도 모듈은 돌아갑니다.**
하지만 `env.config.php` 는 운영에서 반드시 만드십시오 — 아래 사유 때문입니다.

### 업로드 경로 — 기본값이 웹 루트 **안**입니다

```php
// 기본값
BS_UPLOAD_DIR = <studio>/var/source
```

`studio/.htaccess` 와 `var/.htaccess` 가 막고 있지만, **Apache `AllowOverride`
가 꺼져 있거나 nginx 로 받으면 `.htaccess` 를 아예 읽지 않습니다.** 그러면
올려 둔 사업 문서가 그대로 열립니다. 운영에서는 웹 루트 바깥으로 옮기십시오.

```php
// studio/inc/env.config.php
return ['upload_dir' => '/var/www/iworks-data/studio/source'];
```

```sh
mkdir -p /var/www/iworks-data/studio/source
chown -R www-data:www-data /var/www/iworks-data/studio
chmod 750 /var/www/iworks-data/studio/source
```

오픈 전이라 올라온 파일이 없습니다. 오픈 뒤에 옮긴다면
`bs_project_source.file_path` 의 절대경로도 함께 고쳐야 합니다.

### 수집기 `list_url` — 표본 값이 그대로 남아 있습니다

```
list_url = https://bluesoft.slack.com/lists/T0000000/F0000000   ← 가짜
```

이 값으로 `bs_work_item.source_url` 을 만들어 **DB 에 저장합니다.** 가짜인
채로 수집하면 열리지 않는 링크가 쌓이고, 나중에 고쳐도 이미 적재된 행은
되돌아오지 않습니다 — 다시 수집해야 합니다. 진짜 값은 포털 `config.php` 의
`list_url` 과 같습니다. 그대로 두면 수집기가 실행할 때마다 경고를 냅니다.

### 수집기 접속 정보

`config.prod.ini` 에 읽기 전용 계정의 **평문 비밀번호**가 들어갑니다.
저장소에는 올라가지 않지만, 서버에 둘 때 권한을 좁히십시오.

```sh
chown root:root studio/collector/config.prod.ini
chmod 600       studio/collector/config.prod.ini
```

SSH 터널(`127.0.0.1:13306`)이 떠 있어야 동작합니다.

### 슬랙 채널

진행상황 알림은 `#bluestudio-알림` 으로 갑니다 (`env.config.php` 의
`progress_channel` 로 바꿀 수 있습니다). **이 이름의 채널을 슬랙에 먼저
만드십시오.** 지금은 없어도 티가 나지 않습니다 — 적재만 하고 보내지 않기
때문입니다. 발송 경로가 생기는 순간 전부 실패합니다.

---

## 4. 알림은 아직 **보내지 않습니다**

`bs_notification` 에 `queued` 로 쌓기만 하고, 읽어 가서 보내는 코드가
없습니다. 설계상 의도입니다 — 슬랙이 멎어도 배정 확정이 실패하면 안 되기
때문입니다 (명세서 §11.2-B).

화면은 "보낼 예정" 이라고만 말하고 "보냈다" 고 하지 않습니다. 그래도
**배정을 확정하거나 합류를 승인한 사람은 상대가 알림을 받았다고 믿기
쉽습니다.** 오픈 공지에 아래 문장을 반드시 넣으십시오.

> **알림은 아직 발송되지 않습니다.**
> BlueStudio 는 배정 확정·합류 승인·진행 지연 같은 일을 알림함에 쌓아 두지만,
> 지금은 슬랙으로 보내지 않습니다. 쌓인 내용은 나중에 발송 기능이 붙을 때
> 한꺼번에 나갑니다.
> **확정하거나 승인한 뒤에는 상대에게 직접 알려 주십시오.**

쌓이기만 하므로 `bs_notification` 은 계속 늘어납니다. 발송 경로가 생길
때까지 정리 기준이 없습니다. 오픈 뒤 한 번씩 건수를 보십시오.

```sh
mysql <db> -e "SELECT status, COUNT(*) FROM bs_notification GROUP BY status"
```

---

## 5. 올리지 말 것

`dev/` 와 `collector/` 는 운영에 필요 없습니다.
`.htaccess` 와 각 폴더의 `Require all denied` 로 이중으로 막아 두었지만,
**`AllowOverride` 가 꺼져 있으면 둘 다 읽히지 않습니다.**
아예 올리지 않는 쪽이 확실합니다.

수집기를 서버에서 돌려야 한다면 `collector/` 만 웹 루트 **바깥**에 두십시오.

---

## 6. 올린 뒤 손으로 볼 것

- [ ] 포털 타일이 뜨고 아이콘이 `＋` 가 아닌가
- [ ] 타일을 눌러 `studio/index.php` 로 들어가지는가
- [ ] 상단바에서 BlueStudio 가 현재 위치로 표시되는가
- [ ] 로그아웃 상태로 `studio/` 에 들어가면 로그인 안내가 뜨는가
- [ ] `studio/dev/`, `studio/sql/`, `studio/inc/`, `studio/var/` 가 404 인가
- [ ] 문서를 하나 올려 보고, 저장된 경로가 웹에서 열리지 **않는지**
- [ ] 프로젝트를 하나 만들어 보고 대시보드에 뜨는가
- [ ] `bs_setting` 4행이 화면(관리자 R&D 점유 현황)에 그대로 보이는가

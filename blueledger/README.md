# BlueLedger (blueledger)

사내 재무회계 프로그램 BlueLedger 를 포털(blue-iWorks)로 옮기는 모듈. **지금은 출장여비만 옮겼다.**
회계 장부(통장·카드·급여·정산 등, 파이썬 `app.py`)는 아직 지안 님 PC 의 파이썬 버전에 있다.

화면에 보이는 이름은 **BlueExpense**(출장여비)다. 폴더·주소(`blueledger/`)·
업무 시스템 key(`blueledger`)·표 이름(`bl_*`)은 그대로 둔다. 바꾸면 배포 주소·nginx 규칙·DB 를 함께 옮겨야 한다.

## 구성

```
blueledger/
├── index.php            입구 → travel.php
├── travel.php           출장여비 화면(한 장). 상단바는 포털 공통
├── api.php              JSON API (?p=state, trips, receipts, settings, prices/*, roles, export, receipt)
├── guard.php            로그인·권한 상수·CSRF
├── db.php               테이블 정의(BL_SCHEMA) — MySQL/SQLite 둘 다 여기서 만든다
├── lib/                 settings(설정·유가·오피넷) · trips(청구·권한 판정·CSV) · storage(영수증)
├── static/travel.js     화면 스크립트 (파이썬 버전 화면 그대로 + 서버 주소만 바꿈)
├── styles/travel.css
├── tools/import_travel.php   파이썬 travel.db → 새 DB 옮기기
└── var/                 영수증 원본(receipts/). 웹에서 직접 열리면 안 된다
```

## 로그인·권한

- 운영: 포털 로그인 그대로. 직원 명단 = `portal_users`. 출장여비 전용 비밀번호(처음 1234)는 없어졌다.
- 권한은 `bl_travel_roles` 에 정산 담당(admin)·대표 승인(approver)만 적는다. 나머지는 전부 직원.
  처음엔 jian=정산 담당, kimhy=대표 승인. 설정 화면에서 바꾼다(본인 정산 권한은 못 끈다).
- 화면에서는 승인(approver)·정산(admin)을 **관리자**로 통칭하고, 나머지는 **구성원**이라 부른다.
- 관리자도 처음엔 구성원 화면으로 열린다. 머리글의 구성원/관리자 탭으로 관리자 화면에 간다.
  구성원 화면에서는 본인 청구만 보이고 구성원과 똑같이 쓴다. 서버 권한은 화면과 상관없이 그대로다.
- 구성원: 본인이 올렸거나 본인이 출장자인 청구만 보고, 승인대기일 때만 고친다. 승인·정산 칸은 보내도 서버가 무시한다.
- 관리자(승인): 전체 조회 + 승인 칸(승인은 이 권한만 바꾼다). 본인 청구는 구성원처럼 쓰고 승인대기일 때 고친다.
  남의 청구를 승인할 때 같이 오는 내용 칸은 무시한다.
- 관리자(정산): 내용·정산·삭제·설정. 승인 칸은 승인 관리자가 고른 결과를 보기만 한다(서버도 무시한다).
- 모든 POST 는 `X-CSRF-Token` 필요(없으면 419).

## 유가

크론 없음. 누군가 화면을 열 때(`GET state`) 마지막 확인에서 3시간이 지났으면 오피넷 API(`avgLastWeek.do`,
안 되면 `avgAllPrice.do`)에서 받는다. 동시 요청은 `GET_LOCK` 으로 하나만 나간다.
응답 `WEEK` 는 `연4+월2+주1`(2026102 = 2026년 10월 2주).
받은 유가는 **주유비 산정 기준에 바로 적용**된다(설정 `autoFuel`, 기본 켜짐 — 2026-10-22 부터). 유가를 받을 때와
화면을 열 때(`GET state`) `bl_sync_auto_fuel()` 이 기준을 최근 유가로 맞춘다. 설정에서 끄면 예전처럼 정산 관리자가
'이 가격을 산정 기준으로 적용'을 눌러야 바뀐다. 이미 저장한 청구서 금액은 어느 쪽이든 바뀌지 않는다.

## 운영 서버에 올리기

1. 이 폴더를 포털 트리(`.../blueledger/`)에 올린다. `core/worksystems.json` 의 blueledger 항목과
   `styles/icons/blueledger.svg`·`styles/favicons/blueledger.svg` 도 함께 올라가야 포털 카드가 생긴다.
2. nginx server 블록에 추가 — nginx 는 `.htaccess` 를 읽지 않는다:
   ```nginx
   location ^~ /blueledger/var/   { deny all; }
   location ^~ /blueledger/tools/ { deny all; }
   location ^~ /blueledger/lib/   { deny all; }
   location = /blueledger/config.local.php { deny all; }
   ```
   영수증이 20MB 까지라 `client_max_body_size` 25m 이상, php.ini `upload_max_filesize=20M`, `post_max_size=25M`.
3. `var/` 에 웹 서버 사용자가 쓸 수 있어야 한다(영수증 저장).
4. 테이블은 첫 접속 때 저절로 생긴다(`bl_travel_*`).
5. 옛 데이터 옮기기 — 파이썬 출장여비를 끈 뒤 `travel.db` 와 `receipts/` 를 서버로 복사해서:
   ```
   php blueledger/tools/import_travel.php /경로/travel.db --receipts=/경로/receipts --dry-run
   php blueledger/tools/import_travel.php /경로/travel.db --receipts=/경로/receipts
   ```
   같은 id 는 건너뛰므로 여러 번 돌려도 된다. 권한·설정(오피넷 키 포함)·유가도 같이 옮긴다.
   2026-10-21 로컬에서 46건 전부 원본과 같음, 합계 899,794원 일치 확인.
   **PHP 도구 대신 SQL 파일 하나로 옮길 수도 있다(영수증 첨부가 없을 때).** 지안 님 PC 에서
   `php blueledger/tools/export_travel_sql.php <travel.db> --out=bluexpense_migrate.sql` 로 만들고 서버에서
   `mysql --default-character-set=utf8mb4 -u <계정> -p <DB> < bluexpense_migrate.sql` 하면 표 만들기·청구·권한·설정
   (식대 15,000원, 엑셀 시절 7건 완료 처리 포함)이 한 번에 들어가고 끝에 건수·합계가 나온다. 이 길이면 아래 두 단계는 필요 없다.
   PHP 도구로 옮긴 뒤에는 두 가지를 더 한다.
   - `php blueledger/tools/mark_legacy_done.php` — 엑셀 시절 기록 중 '승인대기·미처리'로 남은 7건을
     승인·처리완료로(실제로 전부 끝난 건이다. 2026-10-22 지안 님 확인). `--dry-run` 으로 먼저 본다.
   - 설정 화면에서 1인 식대를 15,000원으로(옛 설정이 10,000원으로 따라 들어온다).
6. HTTPS 는 서버(nginx) 쪽에서 건다. 출장지에서 휴대폰으로 쓰려면 필수.

## 백업

DB 는 포털 MySQL 백업에 같이 들어간다(`bl_travel_*` 네 테이블). 영수증은 파일이라 **`blueledger/var/receipts/`
를 따로 백업**해야 한다. 예: 매일 `mysqldump ... bl_travel_trips bl_travel_files bl_travel_roles bl_travel_settings`
+ `tar czf receipts-$(date +%F).tgz blueledger/var/receipts`.

## 로컬 시험

포털 전체를 SQLite 로 띄우는 루트의 `로컬_실행.bat` 으로 같이 뜬다(루트 readme.md 의 '로컬에서 포털 전체 띄우기').
옛 기록은 그 뒤 `D:\php82\php.exe blueledger\tools\import_travel.php <travel.db>` 로 넣는다.
BlueLedger 의 SQL 은 MySQL/SQLite 둘 다 읽는 모양으로만 썼다(`db.php` 의 bl_upsert 등) — 번역기에 기대지 않는다.

## 알려진 남은 일

- 포털 로그인(`api/login.php`)에 로그인 시도 횟수 제한이 없다 — 외부 공개 전에 포털 쪽에 넣어야 한다.
- 회계 장부 화면 이식.

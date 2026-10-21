# BlueLedger (blueledger)

사내 재무회계 프로그램 BlueLedger 를 포털(blue-iWorks)로 옮기는 모듈. **지금은 출장여비만 옮겼다.**
회계 장부(통장·카드·급여·정산 등, 파이썬 `app.py`)는 아직 지안 님 PC 의 파이썬 버전에 있다.

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
- 직원: 본인이 올렸거나 본인이 출장자인 청구만 보고, 승인대기일 때만 고친다. 승인·정산 칸은 보내도 서버가 무시한다.
- 대표: 전체 조회 + 승인 칸만. 영수증 열람 가능, 새 청구·영수증 올리기 불가.
- 정산 담당: 전부. 삭제는 정산 담당만.
- 모든 POST 는 `X-CSRF-Token` 필요(없으면 419).

## 유가

크론 없음. 누군가 화면을 열 때(`GET state`) 마지막 확인에서 3시간이 지났으면 오피넷 API(`avgLastWeek.do`,
안 되면 `avgAllPrice.do`)에서 받는다. 동시 요청은 `GET_LOCK` 으로 하나만 나간다.
응답 `WEEK` 는 `연4+월2+주1`(2026102 = 2026년 10월 2주). 화면 유가가 바뀌어도 **주유비 산정 기준은
정산 담당자가 '이 가격을 산정 기준으로 적용'을 눌러야** 바뀐다.

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

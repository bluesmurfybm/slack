# 리조트 예약 · 식대 정산 (BlueLedger 안)

(주)블루소프트 직원 리조트(소노호텔앤리조트 회원권) 복리후생 예약과 식대 경비 정산 화면입니다.
claude.ai 아티팩트로 운영하던 화면을 PHP로 옮겼고, 2026-10-22 부터 **BlueLedger 모듈의 한 화면**으로 들어갔습니다.
출장여비와 같이 포털 로그인 · 포털 직원 명단 · 포털 DB 를 씁니다. 따로 쓰는 계정 · 비밀번호 · 설정 파일은 없습니다.

- 주소: `https://서버주소/blueledger/resort/` (포털 → BlueLedger → 상단 메뉴 '리조트 예약')
- 필요 환경: 포털과 같음. 추가 라이브러리 · 크론 · 파이썬 없음

## 1. 폴더 구성

| 경로 | 내용 |
|---|---|
| `index.php` | 화면. 포털 로그인 없이 오면 포털 로그인 화면으로 보냄 |
| `api.php` | 화면이 부르는 서버 기능 (조회, 저장, 삭제, 설정, 영수증, 관리자 지정) |
| `lib/bootstrap.php` | 포털 세션 → 로그인 사용자(`current_user()`), DB(`bl_db()`), 공통 함수 |
| `lib/repo.php` | 저장 규칙(직원 수정 범위 서버 검사), 첨부파일, 설정, 관리자 권한 |
| `lib/view_app.html`, `assets/` | 화면 (app.js, app.css, 엑셀 생성 xlsx.full.min.js) |
| `data/seed.php` | 2026년도 기존 이용내역 16건 (한 번만 사용) |
| `tools/import_seed.php` | 기존 16건 · 설정 넣기 + 검산 |
| `tools/cleanup.php` | (선택) 저장 안 하고 버려진 첨부파일 정리 |
| `tests/smoke.php` | 자동 시험 40개 (로컬 SQLite 에서만) |

표는 `blueledger/db.php` 의 `BL_SCHEMA` 에 출장여비 표와 함께 적혀 있고, 처음 접속할 때 저절로 생깁니다.
영수증 원본은 `blueledger/var/resort/` 에 저장됩니다.

## 2. 로그인 · 권한

- 로그인: 포털 계정(회사 이메일) 그대로. 리조트 전용 비밀번호는 없어졌습니다.
- 관리자: `bl_resort_roles` 에 적힌 사람. 처음엔 김지안(jian@)·김호영(kimhy@). 설정 화면 맨 아래 '관리자' 표에서 바꿉니다(본인 권한은 못 내림).
- 관리자: 6개 탭 전체, 확정 · 반려 · 취소, 금액 · 카드 · 정산, 설정
- 직원: 예약 신청, 식대비 지출현황 2개 탭 — **본인 예약만** 서버에서 내려줌
- 본인 예약 = 본인이 올린 것 + 직원 이름이 본인 포털 이름과 같은 것. 그래서 한도 표의 성명은 포털 이름과 같아야 합니다.
- 직원은 '신청' 상태일 때만 예약 내용을, '확정'된 뒤 정산 전까지만 식대 · 영수증을 고칠 수 있음 (서버가 검사)
- 영수증은 이미지(JPG·PNG·GIF·WEBP)와 PDF만, 파일 내용으로 형식 확인. 본인 · 관리자만 열람
- 두 사람이 같은 예약을 동시에 고치면 나중 사람에게 "다른 사람이 먼저 고쳤습니다" 안내
- 모든 쓰기 요청은 화면 토큰(CSRF) 필요. 저장 · 삭제 · 권한 변경은 `bl_resort_log` 에 기록

## 3. DB 표 (포털 DB, 출장여비 `bl_travel_*` 옆)

| 표 | 내용 |
|---|---|
| `bl_resort_roles` | 관리자 (이메일) |
| `bl_resort_settings` | 정산 담당, 회원권, 결제수단, 안내문 |
| `bl_resort_limits` | 연도별 직원 한도 (숙박비 · 식대 · 박수, 이름 기준) |
| `bl_resort_bookings` | 예약 한 건 (신청 내용 + 처리 결과 + 정산). 올린 사람은 이메일 |
| `bl_resort_meals` | 예약별 식대 줄 |
| `bl_resort_files` | 영수증 · 예약확인 첨부 |
| `bl_resort_log` | 작업 기록 |

## 4. 내 PC에서 시험

1. 저장소 맨 위의 `로컬_실행.bat` 을 더블클릭합니다 (포털 전체가 SQLite 로 뜸).
2. 처음 한 번 기존 16건을 넣습니다:
   ```
   D:\php82\php.exe blueledger\resort\tools\import_seed.php
   ```
3. `http://127.0.0.1:8780/` 에서 회사 이메일 + 로컬 비밀번호(처음 `blue$123`)로 로그인 → `http://127.0.0.1:8780/blueledger/resort/`
4. 자동 시험(포털이 떠 있는 상태에서):
   ```
   D:\php82\php.exe blueledger\resort\tests\smoke.php http://127.0.0.1:8780/blueledger/resort
   ```

## 5. 운영 서버에 올리기

1. BlueLedger 폴더째 올립니다(`blueledger/resort/` 포함). 따로 만들 설정 파일은 없습니다.
2. nginx 에 아래를 더합니다 (`blueledger/README.md` 의 설정과 같은 자리):
   ```nginx
   location ~ ^/blueledger/resort/(lib|tools|data|tests)/ { deny all; }
   ```
   `/blueledger/var/` 차단은 출장여비 때 이미 들어가 있어야 합니다 — 리조트 영수증도 그 아래(`var/resort/`)입니다.
3. 첫 접속 때 표가 생깁니다. 그다음 서버에서 한 번:
   ```
   php blueledger/resort/tools/import_seed.php
   ```
   2026년도 검산 숙박 8,868,200원 / 식대 2,474,050원이 나오면 정상입니다. 여러 번 돌려도 안전합니다.
4. 업로드 한도: php.ini `upload_max_filesize` 12M 이상 (출장여비 기준 20M 이면 충분)

## 6. 크론 · 백업

- 크론 없이 동작합니다. 선택: `php blueledger/resort/tools/cleanup.php --dry` 로 버려진 첨부 확인 후 정리
- 백업: 포털 MySQL 백업에 `bl_resort_*` 일곱 표가 같이 들어갑니다. 영수증 파일은 **`blueledger/var/resort/` 를 따로 백업**하세요.

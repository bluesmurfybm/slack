# learn (BlueLearn · 강의 수강료 지원)

파이썬 `learning/`(FastAPI + SQLModel/SQLite, 포트 8002)을 **기능 그대로 PHP + MySQL 로 옮긴 것**이다
(dti 와 같은 이유 — 포털과 세션·DB 를 공유하면 SSO 쿠키 검증도, 별도 프로세스도, nginx 프록시
블록도 필요 없어진다).

- **스키마는 1:1 이다.** 테이블 이름만 `learning_*` → `learn_*` 로 바뀌었고 컬럼은 그대로다.
  **날짜를 VARCHAR 로 둔 건 의도다** — 신청 상태를 저장하지 않고 날짜 문자열 비교로 파생시키기
  때문이다(`lib/status.php::learn_derive_status`). 상태를 컬럼으로 들고 있으면 날짜를 고칠 때마다
  둘이 어긋난다.
- **PDO 는 모든 값을 문자열로 준다.** JS 에서 `"0"` 은 참이라, `is_free`·`archived` 를 그대로
  내보내면 무료/보관 행이 뒤집힌다. `REQUEST_INT_COLS` 로 캐스팅해서 내보내고 테스트가 이를 잠근다.
- **추천·필수 강의(`learn_catalog`)**: 관리자가 외부 플랫폼 강의를 등록해 **추천/필수** 등급을
  매기면 직원 화면에 목록으로 뜬다. 필수 강의는 개인 연간 한도를 쓰지 않고 승인 절차도 없다
  (`routes/requests.php`). 신청이 카탈로그에서 나오면 `catalog_id` 로 묶인다.
- **진도율은 만들어내지 않는다.** 외부 플랫폼의 실제 수강률은 알 방법이 없으므로, 막대는
  **수강 기간 경과율**이고 라벨도 `기간 N% 지남` 이다. "N시간 남음" 류 문구가 생기지 않는지
  테스트가 확인한다.
- **이수증은 문서루트 아래(`learn/var/uploads/`)에 있다.** nginx 차단 블록이 필수다 — 포털
  `readme.md` 의 nginx 절 참고.

## 남은 전환 작업

파이썬 `learning/` 은 저장소에서 지웠다. 서버 정리는 `learn/tools/cutover_learning.sh` 가
순서대로 해 준다. **순서가 중요하다** — 이관보다 폴더 삭제가 먼저면 원본 SQLite 가 사라진다.

```bash
sudo -iu blueapp_core          # 저장소 소유 계정. 다른 계정이면 git 이 dubious ownership 으로 멈춘다
cd /home/blueapp_core && ./deploy.sh

./learn/tools/cutover_learning.sh             # 점검만 — 아무것도 쓰지 않는다
./learn/tools/cutover_learning.sh --migrate   # 백업 → 이관 → 대조
./learn/tools/cutover_learning.sh --remove    # 대조를 통과하면 learning/ 삭제
```

스크립트가 대신 막아 주는 것들이다.

- **실행 계정이 저장소 소유자가 아니면 멈춘다.** `safe.directory` 로 넘기면 `.git` 에 쓰지 못해
  곧 막히고, 통과하더라도 새로 생긴 파일이 서비스 계정 소유가 아니게 된다
- **`learn` 에서 만들어진 신청이 있으면 멈춘다.** 이관은 `learn_requests` 를 비우고 다시 채우므로
  추천·필수 강의로 들어온 신청(`catalog_id > 0`)이 있으면 그게 날아간다
- **이관 전에 백업을 뜬다** — MySQL `learn_*` 전체를 `.sql` 로, SQLite 원본을 사본으로
  (`learn/var/cutover-backup/`). 되돌리려면 `mysql <DB> < learn-<시각>.sql`
- **대조를 통과해야 지운다.** `verify_migration.php` 가 SQLite 와 MySQL 을 값 단위로 비교하고
  (신청·이수증·이력 전 컬럼, 이수증 파일 존재, 플랫폼·분류 누락, 관리자 명단), 불일치가 하나라도
  있으면 `--remove` 는 거부한다. 지우기 직전에도 `learning/` 을 통째로 tar 로 남긴다

`git pull` 은 추적 파일만 지우므로 `learning/var/`(SQLite·이수증 원본)는 남아 있다 — 이관의
원본이 여기다.

root 권한이 필요한 것들(`learning.service` 중지, nginx 블록 정리)은 스크립트가 건드리지 않고
마지막에 할 일로 찍어 준다. 서버 `config.php` 의 `links` 에서 `'learning'` 을 지우는 것도
잊지 않는다 — gitignore 라 `git pull` 로 오지 않는다.

끝나면 `cutover_learning.sh`·`migrate_from_learning.php`·`verify_migration.php` 를 지운다
(전부 일회성 도구다).

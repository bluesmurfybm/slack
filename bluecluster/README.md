# 블루 클러스터 에이전트 (test)

사무실 PC(노드)들이 Claude 작업을 나눠 처리하도록 작업을 모으고 나눠 주는 iWorks 모듈이다. 설계 아이디어는 blue-cluster 저장소의 `docs/2026-11-26-blue-cluster-design.md`, 노드와 주고받는 규약은 같은 저장소의 `docs/iworks-api.md` 를 따른다.

## 화면

- 작업 목록(`index.php`): 진행 중·끝난 작업
- 작업 상세(`view.php`): 조각과 시도, 리더, 리스
- 작업 등록(`edit.php`)
- PC 목록(`nodes.php`): 살아 있는 PC(마지막 신호 3초 안), 3초마다 새로 고침
- 내려받기(`download.php?attempt=&kind=`): 시도의 결과·패치·로그 원본

## API

- `api.php?p=/jobs`, `p=/jobs/open`, `p=/jobs/{id}/claim` 처럼 `p` 로 경로를 준다. 경로 목록은 `api.php` 머리 주석과 `apidocs/openapi.php` 에 있다.
- 인증은 마이페이지의 개인 API 토큰을 `Authorization: Bearer <토큰>` 으로 보낸다. HTTPS 전용이다.
- POST 는 `Content-Type: application/json` 만 받는다(그 밖은 415). 다른 사이트의 폼이 로그인 세션으로 API 를 부르지 못하게 하려는 것이다.
- 오류 응답은 항상 JSON `{"reason": "..."}` 이다. `ok: false` 는 오류가 아니라 거절이라 200 이다.
- 리스(`LEASE_S`)와 노드 생존(`NODE_TTL_S`)은 PHP `time()` 한 가지로만 판단한다.
- `node_id` 는 하트비트를 보낸 토큰 주인의 것이다. 살아 있는 동안 다른 사용자의 토큰으로 하트비트를 보내면 403 이고, 시도는 시작한 토큰 주인만 `finish` 할 수 있다.
- `node_id`, `piece_id` 는 대소문자를 구분한다(열 정렬 규칙 `utf8mb4_bin`).

## 데이터

- DB 테이블: `bcl_jobs`, `bcl_pieces`, `bcl_attempts`, `bcl_nodes` (`db.php` 가 `CREATE TABLE IF NOT EXISTS` 로 만든다)
- 시도 결과 원본 파일: `files_dir/{job_id}/{piece_id}-{attempt_no}.{result.txt,patch,log.json}`

## 서버에서 처음 한 번 할 일

`amitoa` 반영 = 운영 배포다. 아래를 배포 전에 서버에 해 둔다.

1. `files_dir` 폴더를 만들고 PHP 실행 사용자(php-fpm, 보통 `www-data`)에게 넘긴다. 폴더가 없거나 쓸 수 없으면 조각 `start` 가 모두 `ok: false` 로 거절되어 작업이 진행되지 않는다. 기본은 `/var/www/iworks-data/bluecluster` 이고, 바꾸려면 `config.php` 의 `bluecluster.files_dir` 에 적는다. 웹 루트 밖에 둔다.

   ```bash
   sudo mkdir -p /var/www/iworks-data/bluecluster
   sudo chown www-data:www-data /var/www/iworks-data/bluecluster
   ```

2. 조각 `finish` 본문(결과·패치·로그)이 수 MB 일 수 있다. nginx server 블록에 `client_max_body_size 64m;`, php.ini 에 `post_max_size = 64M`, `memory_limit = 256M` 을 둔다(본문을 JSON 으로 두 번 읽으므로 `memory_limit` 은 `post_max_size` 보다 넉넉해야 한다). 작으면 413 이나 500 이 난다.
3. nginx 는 `bluecluster/.htaccess` 를 읽지 않으므로 server 블록에 아래를 넣는다. `^~` 접두 location 은 `\.php$` 정규식 location 보다 먼저 적용되어 `lib/*.php` 같은 파일도 실행되지 않는다.

   ```nginx
   location ^~ /bluecluster/inc/    { deny all; }
   location ^~ /bluecluster/lib/    { deny all; }
   location ^~ /bluecluster/routes/ { deny all; }
   location ^~ /bluecluster/tests/  { deny all; }
   location ^~ /bluecluster/var/    { deny all; }
   location ~ ^/bluecluster/.*\.md$ { deny all; }
   ```

4. 노드는 iWorks 를 HTTPS 로 불러야 한다. Bearer 개인 토큰은 localhost 가 아니면 http 로는 거절되어, 노드가 http 주소를 쓰면 401 `인증 필요` 가 온다. 노드의 기준 주소는 `https://<iWorks 주소>/bluecluster/api.php?p=` 형태다.

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
- 오류 응답은 항상 JSON `{"reason": "..."}` 이다. `ok: false` 는 오류가 아니라 거절이라 200 이다.
- 리스(`LEASE_S`)와 노드 생존(`NODE_TTL_S`)은 PHP `time()` 한 가지로만 판단한다.

## 데이터

- DB 테이블: `bcl_jobs`, `bcl_pieces`, `bcl_attempts`, `bcl_nodes` (`db.php` 가 `CREATE TABLE IF NOT EXISTS` 로 만든다)
- 시도 결과 원본 파일: `files_dir/{job_id}/{piece_id}-{attempt_no}.{result.txt,patch,log.json}`

## 서버에서 처음 한 번 할 일

1. `files_dir` 폴더를 만들고 PHP 실행 사용자가 쓸 수 있게 한다. 기본은 `/var/www/iworks-data/bluecluster` 이고, 바꾸려면 `config.php` 의 `bluecluster.files_dir` 에 적는다. 웹 루트 밖에 둔다.
2. 조각 `finish` 본문(결과·패치·로그)이 수 MB 일 수 있으니 nginx `client_max_body_size`, PHP `post_max_size`, `memory_limit` 이 그보다 큰지 확인한다.
3. `amitoa` 브랜치에 푸시하면 운영에 바로 배포되므로 확인이 끝나기 전에는 푸시하지 않는다.

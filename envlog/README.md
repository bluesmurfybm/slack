# envlog — Coursemos EnvLog (대학 운영 웹서버 아파치 로그 뷰어)

EnvHub(access)에 등록된 테스트서버를 거쳐 그 대학 운영 웹서버 여러 대의 아파치 로그를 브라우저에서
나란히 보는 포털 모듈. 그래프·요약 없음. 큰 파일도 전체를 읽지 않고 **바이트 구간 단위**로 읽어
응답 없음이 생기지 않는다.

개인 PC 용 도구(bluelog)를 포털 안으로 들여온 것이다. 포털과 같은 서버에서 돌기 때문에
**대학 목록·테스트서버 접속 정보는 `config.php` 로 DB 에 바로 붙어 읽는다** — DB 용 SSH 터널도,
DB 비밀번호 입력도 없다.

## 구조

```
envlog/
├── index.php            화면 (포털 로그인 필요, 공통 상단바)
├── api.php              JSON API — 대학 목록(DB), 접속, 로그 조회 중계
├── guard.php            포털 로그인 가드
├── static/envlog.js     화면 스크립트
├── styles/envlog.css
└── daemon/              ★ SSH 로그 엔진 — 상주 데몬 (문서루트에서 막는다, 아래 배포)
    ├── envlogd.py       Flask, 127.0.0.1:8766. 사용자별 SSH 세션(테스트서버 + 운영서버 N대)
    ├── ssh_jump.py      점프서버 경유 접속 모듈 (asyncssh, 레거시 sshd 폴백)
    ├── envlog_remote.sh 원격 서버에서 실행되는 보조 스크립트 (bash/awk/grep 만)
    └── requirements.txt
```

```
브라우저 ──▶ api.php (포털 세션 확인)
               ├─ ?a=schools     : schools JOIN school_access → test_srv 복호화(access_dec) → 자동완성
               ├─ ?a=connect     : 그 대학의 테스트서버 IP/계정/비번을 데몬에 넘김
               └─ 그 외           : 그대로 데몬에 중계 (X-Envlog-User = portal_users.id)
                                     │
                              envlogd.py (127.0.0.1:8766, DB 를 모름)
                                     ├─ 테스트서버 SSH → WebRoot/*/checkWeb.sh 에서 운영서버 접속 정보
                                     └─ 운영서버 N대: asyncssh 터널 (안 되면 expect 폴백)
                                           └─ envlog_remote.sh: ls / logdirs / info / chunk / seek / grep
```

- **왜 데몬인가** — 테스트서버 → 운영서버 2단 SSH 를 요청마다 새로 맺을 수 없다(수 초). PHP 는
  요청이 끝나면 연결을 못 들고 있으므로, 연결을 들고 있는 프로세스가 따로 필요하다. 데몬은
  127.0.0.1 에만 묶고 인증은 하지 않는다 — **포털 세션 확인은 api.php 가 한다.** nginx 프록시 블록은
  필요 없다(api.php 가 중계).
- **서비스 등록은 없다 — api.php 가 필요할 때 띄운다.** 데몬이 응답하지 않으면 `api.php` 가 웹 서버
  계정으로 백그라운드에 띄우고(`nohup setsid`), 데몬은 **한 시간 동안 아무 요청이 없으면 스스로 끝난다**
  (`ENVLOG_EXIT_IDLE`). 파이썬 패키지는 `daemon/venv` 에 **처음 한 번 자동으로** 깐다(서버에 `python3` 와
  venv 모듈만 있으면 됨, 1분쯤). 로그는 `daemon/var/envlogd.log`. 그래서 systemd 유닛도, 서버에서 손으로
  할 일도 없다.
- **사용자별 세션** — 데몬은 `X-Envlog-User`(포털 사용자 id)마다 세션을 하나씩 둔다. 두 사람이
  다른 대학을 동시에 볼 수 있다. 30분 동안 요청이 없으면 그 사람의 SSH 연결을 닫는다(`ENVLOG_IDLE`).
- **데몬 주소** — `config.php` 의 `'envlog_url'`. 없으면 `http://127.0.0.1:8766`.
- 원격 명령은 매번 `envlog_remote.sh` 를 base64 로 보내 실행한다(서버에 설치할 것 없음, python 불필요).
  로그 읽기 권한이 없으면 그 서버 비밀번호로 `sudo` 재시도.
- 운영서버에 터널 접속이 안 되면(TCP 포워딩 차단) expect 폴백으로 명령마다 접속한다(느리지만 동작,
  서버 목록에 "폴백" 표시).

## 대학 목록

`schools`(active=1) JOIN `school_access` 에서 `test_srv`(AES-256-GCM 암호화)가 있는 대학만.
복호화는 `access/db.php::access_dec()` 그대로(키 = `config.php` 의 `key`). `test_srv` 형식은 EnvHub 가 정한 것:

```
IP[:포트]
아이디 / 비밀번호
/data/WebRoot/사이트폴더      ← 다중 사이트 테스트서버일 때(선택). 그 폴더의 checkWeb*.sh 만 쓴다
-- 운영서버 없음              ← 이 주석이 있으면 운영서버를 찾지 않는다
```

DB 에 없는 서버는 화면의 **직접 입력**(IP:포트 / 아이디 / 비밀번호)으로 접속한다.

## 화면 사용

1. **대학 검색**(자동완성: 이름/사이트/IP) → **접속**. VPN 이 필요한 대학은 자동완성에 VPN 이름이 붙는다 —
   포털 서버에서 그 테스트서버에 닿을 수 있어야 한다.
2. 왼쪽 **서버** 체크박스로 볼 서버 선택(기본: 운영서버 전부). `S1`, `S2` … 가 서버 번호.
3. **로그 파일** 목록은 체크된 모든 서버의 목록을 합쳐 보여준다(오른쪽 S번호 = 그 파일이 있는 서버).
   폴더(📁)는 클릭해 들어가고, 심볼릭 링크는 따라간다. 파일 클릭 → 체크된 모든 서버에서 같은 경로의
   파일을 연다(처음엔 파일 끝).
   - **설정에서 찾은 로그 위치**: 아파치 설정(httpd.conf, conf/extra, conf.d …)의 CustomLog/ErrorLog
     (rotatelogs 파이프 포함)에서 실제 로그 디렉터리를 찾아 칩으로 보여준다.
4. 보기 — 서버마다 패널이 나란히. 시간 / IP / 메시지 / User-Agent 로 분리 표시, 줄 클릭 → 아래에 전체 내용.
   - **동일 시간 스크롤**(기본 켬): 한 패널을 스크롤하면 다른 서버 패널도 같은 시각으로 이동.
   - **병합 모드**: 모든 서버 로그를 시간순으로 한 화면에 합쳐 보여준다(맨 앞에 서버 번호).
   - 패널 아래 슬라이더로 파일 내 위치 이동, ⏮ 처음 / ⏭ 끝 / ↻ 다시 읽기.
5. **검색** — IP(단어 일치) / 텍스트(부분 일치) / 정규식: 체크된 서버 전부에서 `grep` → 결과 클릭하면
   그 위치로 이동. 서버당 300건까지, 더 보려면 **현재 위치 이후**. 시간: `14:03`, `10-29 14:03`,
   `2026-10-29 14:03:22` → 모든 패널을 그 시각으로 이동.

## 배포

파일만 올리면 된다(다른 PHP 모듈과 같다). 서버에 필요한 것은 둘뿐이다.

1. **`python3`(venv 모듈 포함)** — 처음 쓸 때 `api.php` 가 `envlog/daemon/venv` 를 만들고 `requirements.txt` 를 깐다.
   없으면 화면에 "python3 를 찾지 못했습니다" 가 뜬다. 경로가 특이하면 `config.php` 에
   `'envlog_system_python' => '/usr/bin/python3.11'`.
2. **웹 서버 계정이 `envlog/daemon/` 아래에 쓸 수 있어야 한다** — `venv/`·`var/`(로그·기동 표시) 가 거기 생긴다.
   ```bash
   sudo -u <php-fpm 계정> mkdir -p /home/blueapp_core/envlog/daemon/var   # 안 되면 chown
   ```

nginx — 데몬 소스·venv·로그는 문서루트 안에 있으므로 막는다(`moodle/watch` 와 같은 줄):

```nginx
location ^~ /envlog/daemon/ { deny all; }
```

`config.php` 로 바꿀 수 있는 것(전부 선택): `'envlog_url'`(기본 `http://127.0.0.1:8766`),
`'envlog_python'`(venv 대신 쓸 파이썬), `'envlog_autostart' => false`(직접 관리하고 싶을 때 — 그때는
`venv/bin/python envlogd.py` 를 알아서 띄운다), `'envlog_schools_from' => 'db'|'daemon'`.

문제가 생기면 `envlog/daemon/var/envlogd.log` 를 본다. 데몬을 강제로 새로 띄우고 싶으면 프로세스를 죽이면 된다
(`pkill -f envlogd.py`) — 다음 요청이 다시 띄운다.

## 개발 PC 에서 돌려 보기

### 실서버 없이 (로컬 폴더를 서버로)

```
cd envlog/daemon
pip install -r requirements.txt
python envlogd.py --local-test <로그폴더1> [<로그폴더2> ...]
```

각 폴더를 "서버" 하나로 보고 보조 스크립트를 로컬 bash(Git Bash)로 실행한다. 이 모드에서는
화면에서 어느 대학을 골라 접속해도(또는 직접 입력으로 아무 값이나) 그 폴더를 본다.

### 실제 대학에 붙어 보기 — 대학 목록은 iWorks 서버 DB 에서 (`--iworks-db`)

개발 PC 의 포털 DB 에는 대학 접속 정보가 없다. 이 모드로 띄우면 **데몬이 iWorks 서버(35.184.155.46)에
SSH 로 붙어 MySQL 을 터널로 읽고, `test_srv` 복호화 키도 그 서버의 `config.local.php` 에서 읽는다.**
로컬에는 아무것도 저장하지 않는다. 비밀번호는 실행할 때 콘솔에서 묻는다.

```
cd envlog/daemon
python envlogd.py --iworks-db --key G:\01_Bluesoft\0000_iWorks\02_산출물\gcp_key.ppk
   [iWorks DB] root@slack_db 비밀번호: ********
```

- 키 파일이 없으면 `--key` 를 빼고 SSH 비밀번호를 콘솔에 넣는다. 배스천 계정/호스트가 다르면 `--bastion 계정@호스트[:포트]`,
  서버의 포털 루트가 다르면 `--remote-root /home/blueapp_core`.
- `api.php` 는 **설정 없이** 떠 있는 데몬에게 물어 `--iworks-db` 모드면 대학 목록·접속을 그쪽에 맡긴다.
  같은 포트에 자동 기동된 보통 데몬이 이미 떠 있으면 `--iworks-db` 데몬이 그것을 끝내고 자리를 받는다.
- 손으로 띄운 데몬(`--iworks-db`·`--local-test`)은 유휴 자동 종료를 하지 않는다. Ctrl+C 로 끝낸다.

포털은 로컬 WAMP(`dev.iworks.co.kr`) 또는 `로컬_실행.bat`(`http://127.0.0.1:8780/`) 어느 쪽이든 된다 —
`envlog/index.php` 를 연다.

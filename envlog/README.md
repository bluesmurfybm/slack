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

## AI 분석 (Claude) — 로그 패널 아래 "AI 분석" 상자

IP 또는 시간 범위(최대 2시간)로 php_errors.log 와 access_log 를 서버에서 먼저 추린 뒤, 사용자가 보낼 자료를 확인하고
Claude 에게 묻는다. 코드는 `envlog/ai.php`(수집·호출·요금 통제), 화면은 `static/envlog.js` 의 AI 절.

- **입력**: IP(선택), 시간 범위(필수 — "시간 없이(IP 만)" 를 켜면 current 파일만 IP 로 검색), access_log 선택(대학마다
  `ssl_호스트_access_log` 등 이름이 달라 **용량이 가장 큰 것을 기본**으로 고름), php_errors 선택.
- **로테이션**: 매일 `envlog_rotate_hour`(기본 05:00)에 돌므로 `[D-1 05:00, D 05:00)` 기록은 `<이름>-D` 에 있다.
  시간 범위로 파일을 자동 계산한다(10-30 09:00 에 10-29 13~15시를 찾으면 `php_errors.log-20261030`). 아직 안 돈 구간은 current.
- **추출**: 원격 `envlog_remote.sh extract` 가 시간 범위를 이진탐색으로 찾아 그 구간만 읽고, IP 가 있으면 access_log 는 그 IP 줄만
  (php_errors 는 IP 가 없어 시간 범위만). 서버·파일당 access 400줄 / php 300줄, 전체 160KB 상한. 넘치면 표시하고 잘라 보낸다.
- **보내기 전 확인**: "자료 모으기" 결과(서버·파일·줄 수·크기·예상 비용)를 보고 "내용 보기" 로 실제 줄을 볼 수 있다. 이 단계까지는 AI 호출이 없다.
- **요금 통제** (config.php 로 조정): 기본 모델 Haiku 5.5. 1회 예상 비용이 `envlog_ai_max_per_request_krw`(기본 50원)를 넘으면
  거절, 이번 달 누적이 `envlog_ai_monthly_cap_usd`(기본 $100)를 넘으면 거절. 환율은 `envlog_ai_usd_krw`(기본 1400).
  모든 호출은 `envlog_ai_usage` 표에 토큰·비용을 남기고, 화면 머리글에 "AI 사용 ₩누적 / ₩상한" 으로 보인다(80% 넘으면 주황).
- **키**: `config.php` 의 `'anthropic_api_key'`(회사 공용) 또는 사용자가 상자의 "API 키" 로 등록한 개인 키(우선, `portal_users.anthropic_key_enc`).
  키는 서버에서만 쓴다. 호출은 curl 로 직접(composer 없음), 답은 SSE 로 흘려 보여 준다.
- 전송되는 것은 추린 로그 줄 + 질문이다. Anthropic API 로 보낸 데이터는 학습에 쓰이지 않고 30일 뒤 삭제된다(약관 기준).

## 대학 테스트서버가 사무실 IP 만 허용할 때 — 사무실 중계 (office_relay.bat)

대학 테스트서버 대부분이 **블루소프트 사무실 IP 에서만** SSH 를 받는다. 포털 서버(GCP)에서는 닿지 않으므로
SSH 엔진(envlogd)을 **사무실 PC 에서 돌리고**, 포털 서버는 그 엔진을 **역방향 SSH 터널**로 쓴다.
api.php 는 어차피 `127.0.0.1:8766` 으로 HTTP 만 보내므로 코드 변경이 없다.

```
브라우저 → 포털 api.php → 127.0.0.1:8766 ──(역방향 터널, plink -R)──▶ 사무실 PC 의 envlogd → 대학 테스트서버
```

1. 포털 서버 `config.php` 에 `'envlog_autostart' => false` (서버가 자기 데몬을 띄우지 않게).
2. 사무실의 항상 켜 두는 PC 에서 `envlog/daemon/office_relay.bat` 실행. 처음엔 venv 를 만들고, 그 뒤
   엔진(127.0.0.1:8766, 유휴 종료 없음)과 터널(`plink -R 127.0.0.1:8766:127.0.0.1:8766`, 끊기면 자동 재연결)을
   한 창에서 돌린다. bat 머리의 `PORTAL_HOST / PORTAL_USER / PORTAL_KEY`(gcp_key.ppk) 를 환경에 맞춘다.
3. 포털에서 EnvLog 를 열면 그대로 동작한다. 터널·엔진 상태는 그 창에 찍힌다.

- 터널은 서버의 127.0.0.1 에만 묶이므로 외부에서는 닿지 않고, 인증은 여전히 포털 세션(api.php)이 한다.
- 중계 PC 가 꺼지면 "EnvLog 엔진에 연결하지 못했습니다" 가 뜬다 — 그 PC 에서 bat 을 다시 실행.
- **사무실 리눅스 서버에서 돌리는 쪽이 더 낫다** — `office_relay.sh` (같은 일을 `ssh -R` 로, 포워딩 거부·끊김 시 자동 재접속).
  준비는 스크립트 머리말에 있다: ppk 를 OpenSSH 키로 변환(`puttygen … -O private-openssh`), 호스트키 저장, config 의 autostart=false.
  부팅 때 자동으로 뜨게 하려면 `/etc/systemd/system/envlog-relay.service`:
  ```ini
  [Unit]
  Description=EnvLog office relay (envlogd + reverse tunnel to portal)
  After=network-online.target

  [Service]
  WorkingDirectory=/opt/iworks/envlog/daemon        # 저장소를 받아 둔 곳의 envlog/daemon
  ExecStart=/opt/iworks/envlog/daemon/office_relay.sh
  Environment=PORTAL_KEY=/home/<계정>/.ssh/gcp_key
  User=<계정>
  Restart=always
  RestartSec=10

  [Install]
  WantedBy=multi-user.target
  ```
  `sudo systemctl enable --now envlog-relay` · 상태 `systemctl status envlog-relay` · 엔진 로그 `var/relay.log`.
- 윈도우 PC 용은 `office_relay.bat` (plink 사용). 둘 중 하나만 돌린다 — 서버의 8766 포트는 한 터널만 잡을 수 있다.

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

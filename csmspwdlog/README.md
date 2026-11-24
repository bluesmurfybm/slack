# csmspwdlog (Coursemos PwdLog)

지정한 슬랙 채널에 대학 사이트 주소를 올리면, 봇이 EnvHub 데이터(`schools` + `school_access`)에서 그 대학의
사이트 관리자 계정을 찾아 **요청한 사람에게만 DM** 으로 보낸다. 누가·언제·어느 대학을 요청했는지는
`csmspwdlog` 표에 남고 이 모듈 화면(`index.php`)에서 본다.

- 이용 자격: 그 채널에 글을 쓸 수 있는 사람 누구나. 채널 구성원 관리가 곧 권한 관리다.
- 이력 화면: 포털에 로그인한 사람 누구나.
- 보내는 것: 사이트 주소, 관리자 ID, 비밀번호뿐이다. SSH·DB·VPN·배포 정보는 보내지 않는다.
- 운영 주소를 올리면 운영 계정만, 테스트 주소를 올리면 테스트 계정만 보낸다.
- **대학 계정 값은 표에도 로그에도 남기지 않는다.**

## 구조

```
Slack ──(Socket Mode)── bot/bot.py        상주, 받기만 한다
                           │ 메시지 1건을 JSON 으로 stdin 에
                           ▼
                      bot/handle.php      대학 찾기 · 복호화 · DM · 기록
                           │
              schools / school_access (읽기) · csmspwdlog (쓰기)
                           ▲
                 index.php + api.php      요청 이력 화면
```

iWorks 는 회사 안에서만 접근할 수 있어 Slack 이 HTTP 로 이벤트를 보낼 수 없다. 그래서 서버에서 Slack 으로
나가는 웹소켓(Socket Mode)만 쓴다. 암호화 키와 `access_dec()` 가 PHP 쪽에 있어서 Python 은 받기만 한다.

| 파일 | 역할 |
|---|---|
| `lib.php` | 표 생성, 주소 정규화, 대학 찾기, 계정 조회, DM 문구, Slack 호출, 이력 조회 |
| `bot/bot.py` | Socket Mode 수신. `--selftest` 로 설정 점검 |
| `bot/handle.php` | CLI 전용. 메시지 1건 처리. `--dry-run` 은 Slack·DB 기록 없이 문구만(비밀번호는 가림) |
| `index.php`, `api.php` | 요청 이력 화면과 조회 API |
| `guard.php` | 포털 로그인 확인 |

DB 는 `portal_db()` 로 붙는다. `access_db()` 는 부를 때마다 EnvHub 마이그레이션이 돌아서 쓰지 않는다.

## 주소를 찾는 규칙

- 슬랙이 링크로 바꾼 주소와 링크가 안 걸린 맨 도메인을 둘 다 읽는다. 메시지 하나에 최대 5개.
- 스킴·`www.`·포트·경로를 떼고 소문자로 만든 호스트를, 사용 중인 대학(`schools.active=1`)의 운영(`ops`)·테스트(`dev`) 주소 호스트와 **완전 일치**로 비교한다.
- 주소가 없는 글은 무시한다. 주소가 있는데 대학을 못 찾으면 그 사실을 DM 으로 알리고 `대학 없음` 으로 남긴다.
- 같은 메시지는 한 번만 처리한다(`UNIQUE(channel, msg_ts, host)`).

## 표 `csmspwdlog`

| 컬럼 | 내용 |
|---|---|
| `created_at` | 요청 시각 |
| `channel`, `msg_ts` | 슬랙 채널 ID, 메시지 ts |
| `slack_user_id`, `slack_name`, `slack_email` | 요청자 |
| `url_raw`, `host` | 입력한 주소 원문, 정규화한 호스트 |
| `env` | 입력한 주소가 어느 쪽인지: `ops` 운영 / `dev` 테스트 / 빈 값은 대학을 못 찾음 |
| `school_id`, `school_name`, `ver` | 대학. 이름·버전은 요청 시점 값 |
| `result`, `detail` | `sent` 전송 / `not_found` 대학 없음 / `no_info` 계정 없음 / `dm_failed` DM 실패 / `pending` 처리 중, 오류코드 |

화면의 요청자 이름은 `slack_email` 을 포털 사용자(`portal_users.slack_email`, 비어 있으면 `email`)와 맞춰 보여 준다.

## Slack 앱 설정

1. https://api.slack.com/apps → Create New App → From a manifest → `bot/slack_app_manifest.json` 붙여 넣기.
2. Basic Information → App-Level Tokens → `connections:write` 로 토큰 발급(`xapp-`).
3. Install App → 워크스페이스에 설치 → Bot User OAuth Token(`xoxb-`).
4. 주소를 받을 채널에 봇을 초대하고 채널 ID(`C…`)를 확인.
5. `bot/.env.example` 을 `bot/.env` 로 복사해 토큰·채널 ID 를 채운다.

봇 스코프: `channels:history`, `groups:history`, `chat:write`, `users:read`, `users:read.email`.
이벤트: `message.channels`, `message.groups`.

## 기동

봇은 **한 곳에서만** 띄운다. 두 곳에서 띄우면 이벤트가 둘로 갈린다.

```bash
cd csmspwdlog/bot
python3 -m venv venv && venv/bin/pip install -r requirements.txt
venv/bin/python bot.py --selftest     # 토큰 · slack.com 연결 · php · DB 점검
venv/bin/python bot.py                # 상주 실행
```

서버(systemd) 예시 — 유닛 파일은 저장소에 두지 않는다. 계정과 경로는 서버에 맞춘다.

```ini
[Unit]
Description=csmspwdlog slack bot
After=network-online.target

[Service]
User=apache
WorkingDirectory=/var/www/repos/slack/csmspwdlog/bot
ExecStart=/var/www/repos/slack/csmspwdlog/bot/venv/bin/python bot.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

- 실행 계정이 `bot/var/` 에 쓸 수 있어야 한다(로그 `bot/var/bot.log`, 1MB 회전).
- `bot/` 은 웹에서 열리면 안 된다(`.env` 와 CLI 가 여기 있다). Apache 는 `bot/.htaccess`(`Require all denied`)가 막는다 — `AllowOverride` 가 꺼져 있으면 읽히지 않으니 배포 뒤 `…/csmspwdlog/bot/.env.example` 이 403 인지 확인한다. nginx 로 서비스한다면 `location ^~ /csmspwdlog/bot/ { deny all; }` 를 넣는다.
- 개발 PC(Windows)는 `bot\start_bot.bat`.

## 점검

```bash
echo '{"channel":"C1","ts":"1.1","user":"U1","text":"<https://대학주소>"}' | php csmspwdlog/bot/handle.php --dry-run
```

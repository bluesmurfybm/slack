# moodle (MoodleUp? · 무들 동향)

moodle.org **Technical Transformation PAG** 코스(id 17257), Moodle Tracker(Jira Cloud), GitHub
`moodle/moodle`, moodledev.io(`moodle/devdocs`), moodle.com 뉴스를 **주 1회 모아 한국어로 요약**하고
코스모스 관점의 영향도(고/중/저)를 붙여 보여준다.

- **두 조각이다.** `moodle/watch/`(Python 배치)가 쓰고, `moodle/index.php`(PHP)가 읽는다. 배치는
  실행당 **한 트랜잭션**이고 화면은 SELECT 만 한다 — 폴링·UPDATE 는 없다. 별도 포트·nginx 블록도
  없다. `/moodle/` 는 포털 PHP 그대로다.
- **테이블**: `moodle_weekly_report`(주차 1행: 요약 md·헤드라인·액션·소스별 상태·갱신 횟수) +
  `moodle_weekly_item`(원문 항목: 제목·링크·발췌·영향도·처음 들어온 실행 번호 `added_run`) +
  `moodle_weekly_run`(실행 이력: 회차·시각·주체·새 항목 수·갱신 요약). DDL 은 `watch/core/store.py`
  와 `moodle/db.php` 두 곳에 같은 내용이 있다 — 컬럼을 바꾸면 둘 다 고친다. 컬럼 추가는 양쪽 모두
  "있으면 무시" 방식(`MIGRATIONS` / `add_column_if_missing`)으로 붙인다.
- **한 주차는 여러 번 돈다.** 월요일 timer 가 1회차(처음 생성)를 만들고, 화면의 **요약하기**
  버튼이나 `--refresh WEEK` 가 같은 주차를 갱신한다. 갱신은 항목을 다시 넣되 `added_run` 을
  보존해서 처음 들어온 것과 나중에 추가된 것을 가른다. 가장 최근 주차를 갱신하면 구간 끝을 지금
  시각까지 늘려 이번 주에 새로 생긴 것까지 잡고, 지난 주차는 같은 구간을 다시 본다.
- **갱신은 기존 요약을 다시 쓰지 않는다.** 형광펜·메모가 텍스트 앵커라 본문이 바뀌면 자리를 잃기
  때문이다. 갱신 실행은 이전 실행 이후 새로 들어온 항목만 골라(`digest.build_update`) 추가분 요약을
  받고(`summarizer.summarize_update`, `UPDATE_SCHEMA`), `### 갱신 YYYY-MM-DD HH:MM · 새 항목 N건`
  제목으로 **맨 위에** 얹고 `---` 구분선 아래에 기존 본문을 그대로 둔다(`run_weekly.append_update`).
  최신 갱신이 위, 처음 요약이 맨 밑이다 — 아래에 덧붙이면 열 때마다 첫 화면이 같아 바뀐 게 없어 보였다.
  예전 방식으로 아래에 붙어 저장된 주차는 배치가 시작할 때(`run()`, `ensure_schema` 직후) 알아서 위로
  올린다. 손으로 확인하려면 `run_weekly.py --reorder-updates --dry-run` 이 대상 주차를 보여 준다. 헤드라인·
  모델은 1회차 것을 유지하고 액션은 뒤에 이어 붙인다. 새 항목이 없으면 모델을 부르지 않고 항목·이력만
  갱신한다(상태 ok). 화면은 NEW 배지·필터로 새 항목을 표시한다.
- **버튼은 PHP 가 Python 을 띄우는 게 아니다.** `moodle/refresh.php` 가 `watch/var/requests/<week>.json`
  을 남기고, 서버에서는 systemd **path 유닛**이 그 파일이 생기면 `run_weekly.py --requests` 를
  실행한다(아래 배포 절). 로컬에서는 `python run_weekly.py --serve` 를 켜 두면 3초마다 폴더를 보고
  처리한다. 파일 상태가 진행 표시다: `<week>.json`(대기) → `<week>.running`(처리 중) →
  삭제(완료) 또는 `<week>.failed`(실패 사유, 화면에 표시되고 버튼을 다시 누르면 재시도).
  화면은 처리 중일 때만 5초마다 `refresh.php?status=` 로 파일 상태를 묻는다(DB 는 안 본다).
  php-fpm 사용자와 배치 사용자가 다르므로 그 폴더는 둘이 같이 쓸 수 있어야 한다(배포 절 참고).
  배치 `DATA_DIR` 을 기본값에서 바꿨으면 `moodle/config.local.php` 에 `return ['data_dir' => '...'];`
  로 PHP 에도 알려준다. 대기가 3분을 넘으면(배치가 안 떠 있음) 화면이 노랗게 알리고 **요청 취소**
  버튼이 뜬다(대기 파일 삭제). 처리 중인 요청은 30분 넘게 멈춘 경우에만 취소할 수 있다.
- **관리자 화면과 일반 화면.** `moodle/db.php::MOODLE_ADMINS`(현재 amitoa) 만 요약하기 버튼·처리 상태·
  갱신 이력·상태 배지·모델명·실행 노트를 본다. `refresh.php` 도 관리자가 아니면 거절한다. 나머지
  계정은 주차 목록, 요약, 소스 칩(건수), 원문, 형광펜·메모만 본다. 관리자는 **일반계정화면** 버튼
  (`?as=user`)으로 일반 계정이 보는 그대로를 확인할 수 있다. 판정은 `$showAdmin` 하나로 모인다.
- **상태 배지**(관리자 화면): `ok` 모든 소스 수집 + 요약 생성 / `partial` 일부 소스 실패 또는 요약 없음(항목은 저장)
  / `failed` 모든 소스 실패(저장 안 함). 소스 칩의 `skipped` 는 설정이 없어 건너뛴 것(토큰 없음).
  판정은 `run_weekly.py::decide_status`.
- **PAG 가 중심이다.** 요약 프롬프트는 `## PAG 동향` 절을 생략 불가로 두고 헤드라인·한눈에 첫 줄도
  PAG 소식을 앞세운다. 화면은 moodle.org 소스 칩·원문 묶음에 '핵심' 표식, 제목에 PAG 가 들어간
  요약 절을 주황 상자(`moodle_md_emphasize_pag`)로, 본문의 PAG 낱말을 태그로 강조한다.
- **형광펜·메모·북마크**: 요약 글을 드래그하면 미니 도구막대가 떠서 형광펜(노랑·주황·초록), 메모(파랑,
  클릭하면 말풍선), 북마크(보라, 🔖)를 남긴다. 북마크는 `moodle/bookmarks.php` 에 주차별로 모이고
  단락·주차·헤드라인·작성자로 검색(LIKE)할 수 있다. 단락을 누르면 그 주차 요약의 자리(`#note-ID`)로 간다. 팀이 함께 보고, 지우기는 작성자만. 저장은 `moodle/notes.php`(사용자가 누를
  때만 INSERT/DELETE), 테이블은 `moodle_note`(PHP 전용, 배치는 모른다). 위치는 DOM 이 아니라 **텍스트
  앵커**(선택한 글 + 앞뒤 40자)로 저장하고 JS 가 다시 찍는다 — 요약이 갱신되어 문장이 바뀌면 그 표시는
  목록에 "위치를 못 찾았습니다" 로만 남는다.
- **수집기는 서로 독립**(`collectors/base.py::run_safely`). 하나가 죽어도 나머지는 저장되고 리포트
  상태가 `partial` 이 된다. 다 죽으면 `failed` 이고 DB 에 넣지 않는다.
- **moodle.org 는 토큰이 있어야 한다**(`MOODLE_ORG_TOKEN`, 모바일 WS 토큰). 없으면 그 소스만
  `skipped`. 페이지 본문은 `mod_page_get_pages_by_courses` 가 JSON 으로 준다. 해시로 변경을 잡고, 첫
  실행은 기준만 잡는다(`var/pages/*.txt` 에 이전 본문을 두어 다음 변경 때 unified diff 를 보여준다).
  **책(book) 챕터는 못 받는다** — WS 가 본문을 안 주고 `webservice/pluginfile.php` 는 moodle.org 의
  Cloudflare 가 봇으로 보고 403 을 낸다(토큰·등록은 정상이어도). 그 모듈만 건너뛰고 소스 칩의
  툴팁(note)에 이름이 남는다. 포럼 글·페이지·통계는 전부 REST 로 받으므로 영향이 없다.
- **트래커는 주당 수백 건**이라 전부 저장하되 요약 입력에는 주목(★) 항목만 넘긴다 — Fixed 이면서
  Improvement/New Feature/Task/Epic 이거나 `core/config.py::FOCUS_KEYWORDS`(react, composer, oauth,
  deprecat …)에 걸리는 것. 나머지는 통계(컴포넌트·fixVersion 분포)로만 간다.
- **요약**: `summarizer.py`. `ANTHROPIC_API_KEY` 가 있으면 anthropic SDK(`claude-opus-5`, JSON 스키마
  출력, 안전 분류기 거부 시 서버측 fallbacks), 없으면 서버의 `claude -p`, 둘 다 없으면 요약 없이
  원문 항목만 저장하고 `partial` 로 남긴다. 모델이 매긴 영향도는 URL 로 항목에 되돌려 붙인다
  (`run_weekly.py::apply_impacts`).
- **DB 접속 정보는 두 번 적지 않는다.** `DB_HOST` 등이 없으면 배치가 `php -r` 로 포털 `config.php`
  의 `db` 배열을 읽는다(`core/config.py::_db_from_php`). 서버에 php CLI 가 있어야 한다.
- **테스트**: `cd moodle/watch && python -m pytest`(47건, 외부 호출은 전부 가짜 HTTP). `ruff check .`
  무경고. 실제 API 로 돌려보려면 `python run_weekly.py --dry-run --no-summary --since 2026-09-01`
  (moodle.org 외 4개 소스는 익명으로 된다. GitHub 는 시간당 60회 제한 — `GITHUB_TOKEN` 을 주면 5000회).
  로컬에서 버튼까지 써 보려면 터미널 하나에 `python run_weekly.py --serve` 를 켜 두고 화면에서 누른다.

---

## 배포

### moodle-watch (systemd timer)

주 1회 배치라 service 는 `oneshot`, timer 가 월요일 06:00 KST 에 부른다.
`/etc/systemd/system/moodle-watch.service`:

```ini
[Unit]
Description=moodle-watch (MoodleUp? 주간 수집·요약)
After=network-online.target mysql.service

[Service]
Type=oneshot
WorkingDirectory=/home/blueapp_core/moodle/watch
ExecStart=/home/blueapp_core/moodle/watch/venv/bin/python run_weekly.py
User=blueapp_core
Environment=PORTAL_URL=http://포털주소/
Environment=MOODLE_ORG_TOKEN=...
Environment=ANTHROPIC_API_KEY=...
Environment=SLACK_WEBHOOK_URL=...
```

`/etc/systemd/system/moodle-watch.timer`:

```ini
[Unit]
Description=moodle-watch 주 1회

[Timer]
OnCalendar=Mon *-*-* 06:00:00 Asia/Seoul
Persistent=true
RandomizedDelaySec=10m

[Install]
WantedBy=timers.target
```

```sh
cd /home/blueapp_core/moodle/watch && python3 -m venv venv && venv/bin/pip install -r requirements.txt
sudo systemctl daemon-reload && sudo systemctl enable --now moodle-watch.timer
sudo systemctl start moodle-watch.service     # 첫 회는 손으로 한 번 돌려 화면에 주차가 뜨는지 본다
journalctl -u moodle-watch -n 50 --no-pager
```

- `var/` 는 WorkingDirectory 아래에 생긴다(state.json, 주차별 스냅샷, PAG 페이지 본문). 실행 사용자에게
  쓰기 권한이 있어야 한다. 지우면 다음 실행이 "첫 실행"으로 돌아가 페이지 변경 감지 기준을 다시 잡는다.
- `Persistent=true` 라 서버가 꺼져 있던 월요일은 켜진 뒤 바로 한 번 돈다. 마지막 실행 이후 구간을
  보되 최대 21일(`MAX_LOOKBACK_DAYS`)까지만 본다. timer 가 부르는 service 의 ExecStart 에는
  `--trigger timer` 를 붙여 이력에 '자동' 으로 남긴다:
  `ExecStart=/home/blueapp_core/moodle/watch/venv/bin/python run_weekly.py --trigger timer`
- 서버에 Claude Code 로 요약하려면(API 키 없이) 배치 계정으로 설치·로그인한 뒤
  `Environment=CLAUDE_CLI=/home/blueapp_core/.local/bin/claude` 를 service 에 넣는다. systemd 는
  `.bashrc` 의 PATH 를 모른다.

### moodle.org 토큰 점검 (systemd timer, 금요일 오후)

moodle.org 모바일 WS 토큰은 만료돼도 알려 주는 곳이 없다 — 보안 키 페이지에 유효기간이 안 보이고,
만료·삭제되면 그냥 사라져 월요일 수집이 통째로 실패한다. 그래서 금요일 14:00 KST 에 `--check-token`
으로 살아 있는지만 보고 `var/token_status.json` 에 남긴다. 주간 수집도 코스를 부르기 전에 같은 점검을
해 이 파일을 갱신한다. 뷰어(`moodle/index.php`)는 이 파일의 `ok` 가 false 면 **관리자(`MOODLE_ADMINS`)에게만**
토스트를 띄운다. 새 토큰을 넣고 요약하기를 한 번 누르면(또는 다음 점검이 돌면) 파일이 바뀌어 토스트가 사라진다.

`/etc/systemd/system/moodle-token-check.service` 는 moodle-watch.service 를 복사해 ExecStart 만 바꾼다
(Environment/EnvironmentFile 줄은 그대로):

```ini
ExecStart=/home/blueapp_core/moodle/watch/venv/bin/python run_weekly.py --check-token
```

`/etc/systemd/system/moodle-token-check.timer`:

```ini
[Unit]
Description=moodle.org 토큰 점검 (금요일 오후)

[Timer]
OnCalendar=Fri *-*-* 14:00:00 Asia/Seoul
Persistent=true

[Install]
WantedBy=timers.target
```

```sh
sudo systemctl daemon-reload && sudo systemctl enable --now moodle-token-check.timer
sudo systemctl start moodle-token-check.service && cat /home/blueapp_core/moodle/watch/var/token_status.json
```

무효면 서비스가 종료 코드 1 로 끝나 `systemctl status` 에도 failed 로 남는다. 토큰 재발급은
`login/token.php` 를 다시 부르면 된다(응답 JSON 의 `token` 값, 32자 16진수. 살아 있는 토큰이 있으면
같은 값을, 없으면 새 값을 준다):

```sh
curl -s -A "BluesoftMoodleWatch/1.0" https://moodle.org/login/token.php \
  --data-urlencode "username=amitoa" --data-urlencode "password=..." --data-urlencode "service=moodle_mobile_app"
```

### 화면 '지금 다시 가져오기' (systemd path)

버튼이 남기는 요청 파일을 감시하는 유닛 두 개. `/etc/systemd/system/moodle-watch-refresh.path`:

```ini
[Unit]
Description=moodle-watch 갱신 요청 감시

[Path]
PathExistsGlob=/home/blueapp_core/moodle/watch/var/requests/*.json
Unit=moodle-watch-refresh.service

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/moodle-watch-refresh.service` 는 moodle-watch.service 를 복사해 ExecStart 만
바꾼다(Environment 줄은 그대로):

```ini
ExecStart=/home/blueapp_core/moodle/watch/venv/bin/python run_weekly.py --requests
```

요청 폴더는 php-fpm(www-data)이 쓰고 배치(blueapp_core)가 지운다. 둘이 같이 쓸 수 있게 만든다:

```sh
sudo install -d -o blueapp_core -g www-data -m 2775 /home/blueapp_core/moodle/watch/var/requests
sudo systemctl daemon-reload
sudo systemctl enable --now moodle-watch-refresh.path
```

### 문제 해결 체크리스트 (MoodleUp?)

| 증상 | 원인 | 조치 |
|---|---|---|
| `claude CLI(/root/.local/bin/claude) 를 찾을 수 없다` | 배치를 root 로 실행 | `sudo -iu blueapp_core` 로 전환해 실행. root 가 만든 파일은 `chown -R blueapp_core:blueapp_core moodle/watch` |
| 요약하기 눌러도 `journalctl -u moodle-watch-refresh -f` 에 아무것도 없음 | path 유닛 정지 또는 요청 폴더 권한 | `ls -la var/requests/` 로 `<week>.json` 생성 여부 확인 → 생기면 `sudo systemctl restart moodle-watch-refresh.path`, 안 생기면 폴더가 `blueapp_core:www-data 2775` 인지와 php-fpm 계정 확인 |
| `start-limit-hit` 로 refresh 서비스 반복 실패 | 요청 폴더에 처리 못 한 파일이 남아 glob 에 계속 걸림 | `sudo rm -f var/requests/*` → `systemctl reset-failed moodle-watch-refresh.service` → `systemctl restart moodle-watch-refresh.path` |
| 리포트가 `partial` 이고 노트에 `요약 없음` | claude 로그인 만료 또는 CLI 경로 | blueapp_core 로 `claude auth login`, `/etc/moodle-watch.env` 의 CLAUDE_CLI 확인. 다음 요약하기가 전체 요약을 다시 만든다 |
| moodle.org 책(book) 두 권 `HTTPError` | Cloudflare 가 pluginfile 차단 | 구조적 제약. 재시도 무의미, 코스에서 직접 읽는다 |
| moodleorg 소스 노트가 `토큰이 무효(invalidtoken …)` / 관리자 화면에 토큰 토스트 | 모바일 WS 토큰이 만료·삭제됨(moodle.org 는 알려 주지 않는다) | `login/token.php` 로 재발급 → `/etc/moodle-watch.env` 의 `MOODLE_ORG_TOKEN` 교체 → 요약하기. 노트가 `토큰은 유효하지만 PAG 코스에 접근할 수 없음` 이면 코스 17257 수강 등록을 확인 |

`PathExistsGlob` 은 파일이 남아 있는 동안 계속 service 를 부르므로, 처리 후 파일을 지우는 배치
쪽 동작이 곧 종료 조건이다. 처리중·실패 파일(`*.running`, `*.failed`)은 `.json` 으로 끝나지 않아 glob 에 걸리지 않는다.
요청 파일은 www-data 소유라 배치는 그 파일에 쓰지 않고 자기 소유의 처리중 파일을 새로 만든 뒤 원본을 지운다.
- moodle.org 토큰: 요약용 계정으로 코스 17257 자가등록 → `https://moodle.org/login/token.php`
  (service=moodle_mobile_app) 로 발급. 만료는 `https://moodle.org/user/managetoken.php` 에서 확인.
  만료되면 그 소스만 `failed` 로 슬랙에 뜬다.

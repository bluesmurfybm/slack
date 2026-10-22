# OnWatch — 수시 감시

필요할 때 켜 두는 감시. 사용자가 **지시문**으로 "무엇을, 어디서, 어떤 경우에 알릴지" 적어 두면
정한 **기간** 동안 정한 **주기**로 공개 웹을 살피고, **새 소식이 있을 때만** 알린다.
누구나 만들고 누구나 본다. 블루·코스모스 업무에 한정하지 않는 범용 기능이다.

## 흐름

```
화면(PHP)                    DB(ow_*)                  배치(Python, 1분마다)
등록·고치기 ──────────────▶ ow_watch ◀──────────────── 때가 된 감시를 잠그고(claim) 실행
'지금 실행' ── run_requested_at ──▶                      1. 예산 확인(감시별·전체 월 상한)
                                                         2. 조사: Claude + 웹 검색·읽기 → 조사 노트
                                                         3. 판정: 노트 → 정해진 JSON(새 소식만)
                                                         4. fingerprint 로 중복 거르기 → ow_finding
                                                         5. 알림: dev/push_cli.php → push_send()
                                                                  슬랙 웹훅(기본 채널 또는 감시 전용)
                                                         6. 다음 실행 시각 계산
```

- **알림 한 번에 네 곳**: `push_send()` 가 포털 알림함·상단바 종 숫자·**OnWatch 카드 배지**·기기 웹 푸시를
  모두 처리한다. 받는 사람은 그 감시를 '받는' 사람 전원(등록자는 처음부터 들어 있다).
  슬랙은 채널로 한 번. 종료 임박·종료·연속 실패·예산 초과 같은 상태 알림은 등록자에게만, 슬랙 없이.
- **중복 방지 세 겹**: 이미 알린 목록을 모델에 준다 → 판정이 대상 키·구별 표식을 같은 규칙으로 쓴다 →
  코드가 `sha1(대상 키|변화 종류|표식)` 으로 한 번 더 거른다(`uk_ow_finding_fp`). 사람이 '중복·틀림' 으로
  평가한 소식도 다음 실행에 "알리지 말 것" 으로 넘어간다.
- **기준 상태**: 이미 알고 있는 것. 새 소식이 나오면 판정이 바뀐 기준 상태를 제안하고, 상세 화면에서
  사람이 고쳐 반영한다(자동으로 바뀌지 않는다).
- **확인 수준**: 조사 중 실제로 연 주소가 아니면 링크 옆에 '미열람' 을 붙이고, 그런 주소뿐인
  '원문 확인' 은 '신뢰할 만한 보도' 로 낮춘다.

## 파일

| 경로 | 역할 |
|---|---|
| `index.php` `view.php` `edit.php` | 목록 / 상세(소식·실행 이력·설정·변경 기록) / 만들기·고치기·복제 |
| `api/` | `save` `action`(실행·일시정지·연장·구독·기준 상태·지우기) `feedback` `preview`(일정·비용 미리보기) `state` |
| `inc/` | `bootstrap` `settings`(설정값·추정 비용) `db`(스키마 적용) `schedule` `repo` `presenter`(권한) `layout` `templates` |
| `sql/NNN_*.sql` | 스키마 원본. 화면이 처음 DB 에 붙을 때 안 된 파일을 적용하고 `ow_schema` 에 남긴다 |
| `bin/migrate.php` | 스키마 적용 CLI. `bin/secret.php` — 감시 전용 슬랙 웹훅 복호화(배치용, CLI 전용) |
| `worker/` | 배치. `run.py`(진입점) `pipeline` `engine` `prompts` `dedup` `notify` `store` `core/{config,schedule}` |
| `tests/` | `schedule_test.php` + `schedule_cases.json`(PHP·Python 이 같이 읽는 일정 사례) |

포털 쪽 연결: `core/worksystems.json`(카드, `"badge": "inbox"`), `core/push_kinds.json`(`onwatch.finding`,
`onwatch.system`), `styles/icons/onwatch.svg`·`styles/favicons/onwatch.svg`.

## 권한

| | 누구 |
|---|---|
| 보기 · 새로 만들기 · 복제 · 소식 받기 | 로그인한 누구나 |
| 지금 실행 · 소식 평가 | 등록자 · 받는 사람 · 포털 관리자 (실행은 돈이 들고 평가는 다음 실행을 바꾼다) |
| 고치기 · 일시정지 · 종료 · 연장 · 기준 상태 반영 · 지우기 | 등록자 · 포털 관리자 |

## 설정

**화면(감시마다)**: 기간(최대 180일 — 끝 없는 감시는 잊힌 채 비용만 쓴다), 주기(1·3·6·12시간 / 매일 / 매주),
실행 시각·요일·시간대·평일만, 알림 방식(새 소식만 / 매번), 알릴 최소 중요도, 슬랙으로도 보내기(선택, 기본 꺼짐 — 기본 채널 / 전용 웹훅),
먼저 볼 출처, 보지 않을 도메인(검색 도구에 그대로 건다), 한 번의 검색 상한, 월 비용 상한.

**서버(`onwatch/config.local.php`, git 제외)** — 기본값 덮어쓰기:

```php
<?php return ['max_days' => 180, 'default_days' => 30, 'default_budget_usd' => 20, 'max_budget_usd' => 200,
              'default_searches' => 15, 'max_searches' => 50, 'prompt_max_chars' => 8000,
              'slack_enabled' => true];
```

`slack_enabled` 는 슬랙 알림 전체 스위치다. `false` 면 화면에서 슬랙 칸이 사라지고 배치도 보내지 않는다.
감시마다 저장된 선택은 지우지 않아 다시 켜면 그대로 살아난다. 배치는 `bin/settings.php` 로 이 값을
읽는다(1분마다 다시 읽음, 읽지 못하면 끈 것으로 본다) — 스위치의 원본은 이 파일 하나다.

**배치(`worker/.env` 또는 systemd `Environment=`)** — `worker/.env.example` 참고.

| 변수 | 기본 | 메모 |
|---|---|---|
| `ONWATCH_ENGINE` | `auto` | 키가 있으면 `anthropic`, 없으면 `cli`(서버에 로그인된 claude CLI) |
| `ANTHROPIC_API_KEY` | — | API 를 쓰면 검색 횟수 상한·제외 도메인이 도구에 직접 걸리고 비용이 정확하다 |
| `ONWATCH_MODEL` | `claude-opus-5-5` | 조사·판정 모두 |
| `ONWATCH_RESEARCH_EFFORT` / `ONWATCH_JUDGE_EFFORT` | `high` / `medium` | |
| `ONWATCH_MONTHLY_BUDGET_USD` | `100` | OnWatch 전체 월 상한. 넘으면 모든 감시를 건너뛴다 |
| `ONWATCH_SLACK_WEBHOOK_URL` | — | 슬랙 기본 채널 |
| `PORTAL_URL` | `/` | 슬랙 메시지의 '자세히 보기' 링크 앞부분(절대주소로) |
| `PHP_BIN` | `php` | 포털 알림 다리(`dev/push_cli.php`)·DB 설정 읽기 |
| `DB_*` | — | 비우면 포털 `config.php` 를 php CLI 로 읽는다 |

### 엔진을 무엇으로

**API 를 권한다.** 조사 한 번이 웹 검색 수~수십 회라 비용 통제가 핵심인데, API 는 `max_uses`·`blocked_domains`
를 도구에 직접 걸고 응답 사용량으로 비용을 정확히 계산해 감시별 월 상한을 지킨다. 실제로 연 주소도 알 수 있다.
claude CLI 는 키 없이 돌릴 수 있지만(MoodleUp? 과 같은 계정) 검색 횟수를 강제할 수 없고 열어 본 주소도
모른다 — 노트에 적힌 주소를 '본 것' 으로 친다.

추정 비용(Opus 5.5, 검색 15회 상한): 한 번 약 $0.1~0.65. 6시간마다 한 달이면 $15~80 안팎.
등록 화면이 주기·기간·검색 상한으로 추정치를 보여 주고, 월 상한을 넘을 것 같으면 경고한다.
실제 비용은 실행 이력에 남는다.

## 배포

1. 코드를 올린다(`onwatch/`, `core/` 변경분, `index.php`, `api/notifications.php`, `styles/`).
2. 스키마: `/onwatch/` 를 한 번 열거나 `php onwatch/bin/migrate.php`.
3. 배치 계정(claude 로그인 또는 API 키가 있는 계정 — moodle-watch 와 같은 `blueapp_core`)으로 가상환경:
   ```bash
   cd /home/blueapp_core/onwatch/worker
   python3 -m venv venv && venv/bin/pip install -r requirements.txt
   venv/bin/python run.py --show-prompt 1   # 모델을 부르지 않고 지시문만 찍어 본다(감시가 있을 때)
   ```
4. systemd — 유닛은 저장소에 두지 않는다(서버 경로·키가 들어간다. moodle-watch 와 같은 방식).
   `/etc/systemd/system/onwatch-worker.service`:
   ```ini
   [Unit]
   Description=OnWatch worker (one tick)
   After=network-online.target mysql.service

   [Service]
   Type=oneshot
   WorkingDirectory=/home/blueapp_core/onwatch/worker
   ExecStart=/home/blueapp_core/onwatch/worker/venv/bin/python run.py
   User=blueapp_core
   TimeoutStartSec=3600
   Nice=10
   Environment=PORTAL_URL=http://포털주소/
   Environment=ANTHROPIC_API_KEY=...
   Environment=ONWATCH_SLACK_WEBHOOK_URL=...
   Environment=ONWATCH_MONTHLY_BUDGET_USD=100
   ```
   `/etc/systemd/system/onwatch-worker.timer`:
   ```ini
   [Unit]
   Description=OnWatch worker every minute

   [Timer]
   OnCalendar=*:*:00
   AccuracySec=5s

   [Install]
   WantedBy=timers.target
   ```
   `systemctl daemon-reload && systemctl enable --now onwatch-worker.timer`.
   1분마다 깨어나며 할 일이 없으면 DB 질의 두세 번으로 끝난다. 앞 실행이 끝나기 전에는 timer 가 다시 띄우지 않는다
   (그래서 '지금 실행' 은 길면 앞 실행이 끝날 때까지 기다린다).
   로그: `journalctl -u onwatch-worker -n 100`.
5. 배치 계정이 포털 `vapid.key` 를 읽을 수 있어야 기기 웹 푸시가 나간다(알림함·배지는 DB 만으로 된다).

로컬: `cd onwatch/worker && .venv/Scripts/python run.py --serve` (20초마다 깨어남).

## 시험

```bash
php onwatch/tests/schedule_test.php                    # 일정·검증·마크다운 30건(DB 없음)
cd onwatch/worker && .venv/Scripts/python -m pytest -q # 39건(DB·API·php 를 부르지 않음)
.venv/Scripts/python -m ruff check .
```

`schedule_cases.json` 은 PHP(`inc/schedule.php`)와 Python(`worker/core/schedule.py`)이 같이 읽는다 —
두 쪽 일정 계산이 어긋나면 화면의 '다음 실행' 과 실제 실행이 달라진다. 한쪽을 고치면 다른 쪽도 고친다.

## 알려진 제약

- 모델 판단에 기대는 기능이다. 지시문이 모호하면 소식도 모호하다 — 처음 며칠은 '매번 알림' 으로 두고
  조사 노트(실행 이력)를 보며 지시문을 다듬는 것을 권한다.
- 웹 페이지 안의 지시문(프롬프트 주입)은 따르지 말라고 일러 두었지만 완전한 방어는 아니다. 판정 결과는
  늘 원문 링크와 함께 사람이 확인하는 '알림' 이다.
- 감시 하나가 수 분 걸린다. 같은 때에 여러 감시가 몰리면 차례로 돈다(한 번 깨어날 때 최대 5건).

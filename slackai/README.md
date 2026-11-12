# slackai (WorkHub AI · 테스트베드)

`slack/` 을 복사해 AI 레이어를 얹은 모듈. **`slack/` 은 건드리지 않고, 데이터베이스도 `slackai_db` 로 분리**한다
(같은 Slack 리스트를 보지만 요청·읽음·태그·AI 결과가 전부 따로 산다). 인증·세션·개인 Slack 토큰은 그대로
`core/auth.php` → `slack_db.portal_users` 를 쓴다. 상단 드롭다운 "🤖 WorkHub AI (test)" (`core/worksystems.json`).

## 무엇을 하나 — 문의 1건의 흐름
1. **접수(자동)** — Slack 리스트에 항목이 생기거나 바뀌면 Workflow Builder("When a list item is updated") →
   우리 Slack 앱의 커스텀 스텝 `workhub_item_changed` → 워커(Socket Mode)가 `ingest` 잡을 넣고
   `php slackai/sync.php item=Rec… json` 으로 그 항목만 받아 upsert. **신규(INSERT)면 `triage` 잡 자동 등록.**
   주기 폴링은 없다(`SYNC_INTERVAL_SEC=0`). 워커 기동 시 1회 증분 동기화, 화면 진입 시 1회(5분 내 동기화됐으면 생략).
2. **분석(triage, 자동)** — 요약·문제유형·긴급도·난이도(★, `requests.ai_stars` 도 채움)·태그 1~3개(`ai_tags` 에서만)·
   대상 레포 판별(`requests.lms` 호스트 ↔ `schools.dev/ops` → `ai_repos`, 안 되면 LLM 추정 ≥0.75)·
   유사 과거 문의(TF-IDF 20건 → LLM 상위 5건 + 처리 내용 요약).
3. **플랜(자동)** — 분석이 끝나고 레포가 확정되면(또는 패널에서 레포를 고르면) **플랜까지 자동 실행**한다(`auto_plan`, 기본 켬).
   플랜 프롬프트는 워커가 문의·AI 요약·유사 사례·승인된 교훈·레포 지식으로 자동 조립하고 `ai_plans.prompt_md` 에 남긴다(패널에서 확인).
   Claude Code 를 고객사 작업 사본에서 **읽기 전용**으로 실행해 수정 파일·단계·리스크·테스트 계획을 JSON+마크다운으로 저장.
   세션 ID 를 함께 저장해 실행 때 `--resume` 으로 이어간다. 승인~검토 중인 플랜이 있으면 새로 만들지 않는다.
   **자동 플랜은 Haiku**(`ai_settings.plan_model_auto`, 기본 `claude-haiku-4-5`)로 돌려 토큰 비용을 줄인다. 더 깊은 조사가 필요하면
   패널 플랜 섹션의 모델 선택(🧠 Haiku / Sonnet / Opus)으로 [플랜 생성]·[🔁 플랜 재생성] 을 눌러 따로 조회한다(새 버전으로 쌓임).
4. **승인(사람)** — 상세의 🤖 AI 패널에서 [✅ 승인하고 작업 진행] → 확인 모달(레포 경로·파일·예산·체크박스).
5. **실행(자동)** — 작업 사본이 깨끗한지 확인 → Claude 가 같은 세션으로 수정(커밋·푸시·리버트 금지) → `php -l` → diff 저장.
6. **커밋(사람)** — [커밋] → 통계/lint 확인 → 커밋 메시지 편집(`fix(area): 제목 [Rec…]`) → 워커가 `svn commit`/`git commit`.
7. **검토(버튼/자동)** — Claude 가 아닌 다른 AI(`codex` → `openai` → `gemini` → Claude 다른 모델)가 문의+플랜+diff 를 검토.
8. **학습(자동 → 사람 승인)** — 플랜 예측 파일 vs 실제 변경 파일, 검토 결과, 태그 교정·반려 사유 → 교훈(`ai_lessons`) 제안 →
   승인된 것만 다음 플랜 프롬프트에 주입, 주기적으로 `slackai/worker/knowledge/<repo>.md` 로 증류.

## 구성
- PHP(화면·API): `slackai/lists.php`(slack/ 과 같은 현황판 + AI 배지/패널), `ai/ai_state.php`·`ai/ai_action.php`(패널 상태/버튼),
  `ai/panel.php`·`ai/panel.js`·`styles/ai.css`, 관리 페이지 `tags/tags.php`(태그 CRUD) · `repos/repos.php`(레포 매핑) ·
  `ai/jobs.php`(작업 로그·비용) · `ai/lessons.php`(학습 노트) · `ai/settings.php`(AI 설정). **PHP 는 DB 만 읽고 쓴다** — 프로세스 실행 없음.
- 워커(Python, `slackai/worker/`): `ai_jobs` 를 폴링해 `claude`/`codex`/`svn`/`git`/`php` 를 실행하는 유일한 프로세스.
  개발 PC(Windows)에서 `start_worker.bat` 로 상주(로그인 사용자 계정이어야 `claude` OAuth 를 재사용). 설정은 `slackai/worker/.env`
  (`.env.example` 참고). `python run_worker.py --selftest` 로 php/claude/svn/git·DB·Slack 연결을 점검.
- 워커 전용 PHP CLI: `slackai/tools/clone_db.php`(slack_db → slackai_db 최초 복제), `slackai/similar/similar_cli.php`,
  `slackai/tools/comments_cli.php`, `slackai/tools/post_cli.php`, `php slackai/sync.php [full|json|enqueue|item=…|comments=…]`.
- Slack 앱(신설, 매니페스트 `slackai/worker/slack_app_manifest.json`): Socket Mode, 봇 스코프 `lists:read channels:history chat:write`,
  이벤트 `function_executed`·`message.channels`, 커스텀 스텝 `workhub_item_changed`. Workflow Builder 에서 리스트 2개에
  "When a list item is updated → WorkHub AI 단계" 를 연결하고 봇을 댓글 채널에 초대한다. `.env` 의 `SLACK_APP_TOKEN`(xapp)·`SLACK_BOT_TOKEN`(xoxb).
  생성 이벤트가 "updated" 로 오지 않는 워크스페이스면 접수 폼(Lists 자동화 Form) 뒤에 같은 단계를 붙인다.

## 설정·권한
- `config.php`: `'slackai' => ['db_name' => 'slackai_db', 'approvers' => ['…@bluesoft.co.kr']]`.
- **환경별 설정 파일 보호** — `config.php`, `local/ubion/config.php` 는 테스트/운영 서버마다 값이 달라 AI 가 보지도·근거로 쓰지도·고치지도·커밋하지도 않는다
  (`.env` 의 `PROTECTED_PATHS`, 기본 이 두 개). 워커 `core/protect.py` 가 Claude 권한 규칙(Read/Edit 거부 — Grep·Glob 에도 적용,
  이 경로를 언급하는 svn cat/diff·git show/diff 거부, 경로 없는 전체 `svn diff`/`git diff` 거부)·프롬프트 규칙·플랜 files 제거·
  실행 dirty 판정/diff/커밋 대상 제외·실행 전후 해시 비교(바뀌면 로그 경고)를 한 곳에서 건다. codex/openai 검토는 프롬프트 규칙 + 필터된 diff 만 받는다.
  참고: Claude CLI 는 cmd 에서 띄운 워커에서는 Bash 도구를 주지만 Git Bash 안에서 띄우면 PowerShell 을 준다 — 워커는 `start_worker.bat` 로 띄울 것.
- 워커가 쓰는 PHP 는 `slackai/worker/.env` 의 `PHP_BIN`(기본 `php` — PATH 에서 찾는다. Apache 와 같은 버전을 가리키게 한다).
  레포별로 다른 PHP 로 `php -l` 을 돌리려면 레포 화면의 "php 실행 파일" 칸(`ai_repos.php_bin`). `.env` 를 바꾸면 워커를 다시 시작한다.
- **레포 자동 등록** — 레포 화면 [📥 school_access 로 자동 등록] → 워커 `discover_repos` 잡이 `REPO_SCAN_ROOTS`(`;` 로 나눈 작업 사본 루트 목록, `slackai/worker/.env`)
  아래 `*\03_Source\*` 작업 사본의 svn/git 주소를 `school_access.repo` 와 맞춰 `ai_repos` 를 만든다/갱신한다(school_id·버전·LMS 호스트 포함).
  `school_access` 는 `school_id, repo` 두 컬럼만 읽는다(계정·비번 컬럼은 읽지 않음). 같은 학교 사본이 여럿이면 최근 커밋 것만 '사용'.
- 승인자(플랜 승인·커밋·교훈 승인) = `config.php slackai.approvers` ∪ `ai_settings.approvers` ∪ `portal_admin`. 나머지 버튼은 로그인 사용자 누구나.
  모든 버튼·승인·비용은 `ai_events`/`ai_jobs` 에 남는다.
- `ai_settings`(화면 ⚙️ AI 설정): `auto_triage`·`auto_plan`·`auto_review`·`post_to_slack`(기본 0)·예산(`budget_*_usd`, `max_daily_usd`)·`reviewer`·`paused`.
- 비용 안전장치: Claude 실행마다 `--max-budget-usd`, 일일 상한, execute/commit 재시도 없음, full 동기화는 신규 enqueue 안 함(`enqueue` 플래그 필요),
  리스트 모드 신규 enqueue 는 7일 이내 생성 항목만.

## DB
`slackai_db` = slack 8개 테이블 복제본 + `ai_jobs`(큐) `ai_triage` `ai_tags` `request_tags` `ai_similar` `ai_comment_cache` `ai_plans`
`ai_executions` `ai_commits` `ai_reviews` `ai_lessons` `ai_events` `ai_settings` `ai_repos`. DDL 은 `slackai/db.php` 와 워커
`worker/core/store.py` **두 곳에 같은 내용** — 컬럼을 바꾸면 둘 다 고친다. 잠금은 파일 flock 대신 MySQL `GET_LOCK('slackai_sync')`
(Apache 와 워커 CLI 의 `%TEMP%` 가 달라 파일 락이 서로 안 보인다). `slackai/vendor/cacert.pem`(Mozilla CA 번들)이 있으면 Slack HTTPS 인증서 검증을 켠다.

## 알려진 제약
- Slack Events API 에 Lists 항목 이벤트가 없어 앱이 리스트 변경을 받는 경로는 Workflow 커스텀 스텝만이다. 이벤트를 놓치면 다음 워커 기동/화면 진입 동기화가 보정한다.
- PHP 는 `ai_repos.local_path` 를 검증하지 않는다(워커 호스트의 경로) — 레포 화면의 [점검] 이 워커에 `check_repo` 잡을 보낸다.
- Socket Mode 는 Slack 이 "개발용" 으로 안내하는 방식이다. 운영 서버로 옮길 때는 같은 핸들러를 HTTP Request URL(nginx HTTPS) 로 받는다.

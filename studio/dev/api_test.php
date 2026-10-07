<?php
/** HTTP 레벨 API 시험 — 실제로 뜬 서버에 붙어 로그인·CSRF·권한·업로드까지 통째로 확인한다. */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 이것이 단위 테스트와 다른 점                                       │
   │                                                                  │
   │ ProjectRepo / SourceUploader 는 클래스를 직접 불러 시험했다.       │
   │ 여기서는 **브라우저가 하는 것과 같은 방식**으로 HTTP 를 탄다 —     │
   │ 세션 쿠키, CSRF 헤더, multipart 업로드, 권한 401/403 까지.        │
   │ 화면과 API 사이의 배선 오류는 이 층에서만 잡힌다.                  │
   │                                                                  │
   │ 먼저 서버를 띄워야 한다:  studio\dev\serve.bat                    │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('명령줄에서만 실행할 수 있습니다.');
}

$BASE = getenv('BS_TEST_BASE') ?: 'http://127.0.0.1:8099';

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 시험 전용 계정을 쓴다. 사람이 쓰는 계정을 빌리지 않는다.           │
   │                                                                  │
   │ 처음에는 kimhy@bluesoft.co.kr / blue$123 을 썼는데, 그 계정으로    │
   │ 브라우저에서 한 번 로그인하자 포털이 초기 비밀번호 변경을 강제해   │
   │ (core/auth.php 의 needs_setup) 시험이 통째로 401 이 됐다.         │
   │ 사람이 손대는 계정에 시험을 묶으면 이런 일이 반복된다.             │
   │                                                                  │
   │ 아래 계정은 ensure_test_accounts() 가 로컬 DB 에 직접 만든다.      │
   │ 사람은 이 계정으로 로그인할 일이 없으므로 비밀번호가 바뀌지 않는다.│
   └──────────────────────────────────────────────────────────────────┘ */
const TEST_PASSWORD = 'ba-test-1234';
$ADMIN = ['email' => 'batest-admin@bluesoft.co.kr', 'password' => TEST_PASSWORD];
$USER  = ['email' => 'batest-user@bluesoft.co.kr',  'password' => TEST_PASSWORD];

$pass = 0; $fail = 0; $failures = [];

function ok(string $what, bool $cond, string $extra = ''): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else { $fail++; $failures[] = $what . ($extra ? " — $extra" : ''); echo "  FAIL $what" . ($extra ? " — $extra" : '') . "\n"; }
}
function section(string $s): void { echo "\n$s\n"; }

// ---------------------------------------------------------------------
// HTTP 클라이언트 — 사용자별로 쿠키 항아리를 따로 둔다
// ---------------------------------------------------------------------
final class Client
{
    private string $jar;
    public ?string $csrf = null;

    public function __construct(private string $base, string $tag)
    {
        $this->jar = sys_get_temp_dir() . "/bs_cookie_$tag.txt";
        @unlink($this->jar);
    }

    /** @return array{status:int,body:string,json:?array} */
    public function req(string $path, array $opt = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $headers = [];
        if (!empty($opt['json'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'], JSON_UNESCAPED_UNICODE));
            $headers[] = 'Content-Type: application/json';
        } elseif (!empty($opt['form'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['form']);   // multipart
        }
        if (!empty($opt['csrf']) && $this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body   = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        // curl_close() 는 PHP 8 에서 deprecated (CurlHandle 객체라 GC 가 정리한다)

        if ($err !== '') {
            fwrite(STDERR, "\n[연결 실패] $err\n서버가 떠 있습니까?  studio\\dev\\serve.bat\n");
            exit(1);
        }
        $json = json_decode($body, true);

        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    /** 포털 로그인 후 BlueStudio 화면에서 CSRF 토큰을 긁어 온다. */
    public function login(array $cred): bool
    {
        $r = $this->req('/api/login.php', ['json' => $cred]);
        if ($r['status'] !== 200) {
            return false;
        }
        $page = $this->req('/studio/project_list.php');
        if (preg_match('/data-csrf="([a-f0-9]{64})"/', $page['body'], $m)) {
            $this->csrf = $m[1];
        }
        return $this->csrf !== null;
    }
}

/**
 * 시험 전용 계정을 만들고(없으면) 비밀번호를 맞춰 둔다.
 *
 * 로컬 개발 DB 에만 쓴다. 포털 config.php 를 그대로 읽으므로 운영 설정을
 * 가리키고 있으면 그쪽에 계정이 생긴다 — 그래서 로컬 호스트인지 먼저 본다.
 */
function ensure_test_accounts(string $adminEmail, string $userEmail, string $password): void
{
    $portal = dirname(__DIR__, 2);
    $cfg    = require $portal . '/config.php';
    $d      = $cfg['db'];

    if (!in_array($d['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "\n[중단] config.php 의 DB 가 로컬이 아닙니다 ({$d['host']}).\n"
            . "시험 계정을 만들지 않았습니다. 운영 DB 에서 돌리지 마십시오.\n");
        exit(2);
    }

    $pdo = new PDO(
        "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
        $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins  = $pdo->prepare(
        'INSERT INTO portal_users (name, email, pw_hash, color, created_at)
              VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE pw_hash = VALUES(pw_hash)'
    );
    $ins->execute(['시험관리자', $adminEmail, $hash, '#5A667F']);
    $ins->execute(['시험사용자', $userEmail,  $hash, '#8C7055']);

    // 관리자 판정은 core/board.php 의 portal_admin 명단을 본다.
    $pdo->prepare('INSERT INTO portal_admin (email, added_by, created_at)
                        VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE email = email')
        ->execute([$adminEmail, 'api_test.php']);

    // 일반 사용자 쪽이 관리자 명단에 남아 있으면 권한 시험이 무의미해진다.
    $pdo->prepare('DELETE FROM portal_admin WHERE email = ?')->execute([$userEmail]);

    // ── 시험용 구성원 ────────────────────────────────────────────────
    //
    // **여기서 만든다. [R] 에서 만들지 않는다.**
    //
    // 전에는 [R] 이 자기가 쓰기 직전에 만들었다. 그런데 그보다 앞선 [Q] 가
    // 시험관리자를 담당자로 세워야 해서, 앞 회차의 뒷정리가 배정 가능을
    // 꺼 두면 [Q] 가 "본인이 배정받은 태스크만" 403 으로 깨졌다. 준비는
    // 앞에서 한 번에 하고, 배정 가능 여부도 매 회차 확실히 되돌린다.
    //
    //   시험관리자 — 배정 가능. [Q] 가 담당자로 세우고 [R] 이 후보 표에서 찾는다
    //   시험사용자 — 배정 **불가**. '본인' 경로만 쓴다. 배정 가능으로 두면
    //                시험이 끝난 뒤에도 남아, 누가 배정을 돌릴 때 엔진이
    //                이 유령에게 일을 맡긴다
    $member = $pdo->prepare(
        'INSERT INTO bs_member (user_id, emp_name, is_assignable) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE emp_name = VALUES(emp_name),
                                 is_assignable = VALUES(is_assignable)'
    );
    $member->execute([$adminEmail, '시험관리자', 1]);
    $member->execute([$userEmail,  '시험사용자', 0]);
}

/**
 * 시험용 역량 판정 회차와 점수.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 후보 표 시험은 **점수가 붙은 사람**이 있어야 성립한다.            │
 * │                                                                  │
 * │ 전에는 개발 DB 에 쌓여 있던 실수집 데이터에 기대고 있었다.        │
 * │ DB 를 새로 만들면 그게 없어 `domain_fit` 이 전부 null 이 되고,    │
 * │ [M] 부터 [P] 까지 줄줄이 깨진다. 시드 업무이력은 12건뿐이라       │
 * │ 표본 임계선(10건/20건)을 넘지 못해 점수가 나오지 않는다.          │
 * │                                                                  │
 * │ 그래서 **시험이 자기가 쓸 점수를 직접 만든다.** alloc_test 와     │
 * │ 같은 방식이다. 수집기나 score.py 를 돌려 두지 않아도 돌아간다.    │
 * └──────────────────────────────────────────────────────────────────┘
 */
function ensure_test_scores(string $adminEmail): void
{
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $d   = $cfg['db'];
    $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
                   $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $mid = $pdo->prepare('SELECT id FROM bs_member WHERE user_id = ?');
    $mid->execute([$adminEmail]);
    $memberId = (int)$mid->fetchColumn();
    if ($memberId === 0) {
        return;
    }

    // 회차는 매번 새로 만든다. latestEvalVer() 가 MAX(id) 를 보므로
    // 이 회차가 곧 최신이 되고, 앞 회차 값에 흔들리지 않는다.
    $pdo->prepare(
        'INSERT INTO bs_eval_run (started_at, finished_at, period_from, period_to, status)
         VALUES (NOW(), NOW(), ?, ?, "ok")'
    )->execute([date('Y-m-d', strtotime('-180 day')), date('Y-m-d')]);
    $evalVer = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO bs_member_metric (member_id, eval_ver, total_cases, cap_score,
                                       breadth_score, career_score, insufficient_data)
         VALUES (?,?,?,?,?,?,0)'
    )->execute([$memberId, $evalVer, 48, 82.00, 70.00, 60.00]);

    // 표본이 충분한(=confidence full) 계열을 몇 개 둔다. 분야 1·2 가 속한
    // activity 가 반드시 있어야 [M] 의 '분야 매치도' 가 성립한다.
    $cat = $pdo->prepare(
        'INSERT INTO bs_member_category (member_id, category, eval_ver, case_count,
                                         weighted_qty, baseline, score, confidence,
                                         insufficient_data)
         VALUES (?,?,?,?,?,?,?,"full",0)'
    );
    foreach ([['activity', 24, 88.00], ['presentation', 21, 74.00], ['platform', 20, 65.00]] as $c) {
        $cat->execute([$memberId, $c[0], $evalVer, $c[1], (float)$c[1], 24.00, $c[2]]);
    }
}

/**
 * 가용도가 100% 가 아닌 사람 한 명.
 *
 * [M] 의 `min_availability=100` 은 **걸러지는 사람이 하나는 있어야** 성립한다.
 * 백지 DB 에서는 점유가 하나도 없어 전원이 100% 라 아무도 안 걸리고,
 * 그러면 'filtered_out 으로 표시' 가 깨진다. 전에는 개발 DB 에 쌓여 있던
 * 점유에 기대고 있었다.
 *
 * 시험사용자에게 건다 — is_assignable=0 이라 배정 엔진이 집어가지 않으므로
 * 뒤의 배정 시험을 흔들지 않는다. kind='manual' 이라 [R] 의 뒷정리가
 * 지우지만, 다음 회차 시작 때 여기서 다시 만든다.
 */
function ensure_busy_member(string $userEmail): void
{
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $d   = $cfg['db'];
    $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
                   $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $st = $pdo->prepare('SELECT id FROM bs_member WHERE user_id = ?');
    $st->execute([$userEmail]);
    $memberId = (int)$st->fetchColumn();
    if ($memberId === 0) {
        return;
    }

    $pdo->prepare("DELETE FROM bs_workload WHERE member_id = ? AND label = '시험용 선점유'")
        ->execute([$memberId]);

    // 어떤 프로젝트 기간을 잡든 겹치도록 앞뒤로 넉넉히 둔다.
    $pdo->prepare(
        "INSERT INTO bs_workload
            (member_id, kind, source, label, start_date, end_date, load_ratio, confidence,
             created_by, created_by_name)
         VALUES (?, 'manual', 'meeting', '시험용 선점유', ?, ?, 0.600, 1.000,
                 'apitest@local', '시험 준비')"
    )->execute([
        $memberId,
        date('Y-m-d', strtotime('-1 year')),
        date('Y-m-d', strtotime('+1 year')),
    ]);
}

ensure_test_accounts($ADMIN['email'], $USER['email'], TEST_PASSWORD);
ensure_test_scores($ADMIN['email']);
ensure_busy_member($USER['email']);

echo "\nBlueStudio API 시험  ($BASE)\n" . str_repeat('=', 62) . "\n";

$admin = new Client($BASE, 'admin');
$guest = new Client($BASE, 'guest');
$anon  = new Client($BASE, 'anon');

// =====================================================================
section('[A] 인증 — 로그인 없이');
$r = $anon->req('/studio/api/project.php?act=list');
ok('API 는 401', $r['status'] === 401, 'status=' . $r['status']);
ok('오류 형식 {ok:false,error:{code,message}}',
   ($r['json']['ok'] ?? null) === false && isset($r['json']['error']['code']),
   substr($r['body'], 0, 90));
ok('code=LOGIN_REQUIRED', ($r['json']['error']['code'] ?? '') === 'LOGIN_REQUIRED');

$r = $anon->req('/studio/project_list.php');
ok('화면은 포털로 리다이렉트', $r['status'] === 302, 'status=' . $r['status']);

// =====================================================================
section('[B] .htaccess 가 막아야 할 경로 (라우터가 같은 규칙으로 대신)');
foreach ([
    '/studio/inc/bootstrap.php'            => '부트스트랩',
    '/studio/inc/repo/ProjectRepo.php'     => '리포지토리',
    '/studio/sql/001_schema.sql'           => '스키마',
    '/studio/dev/setup_local.php'          => '개발 스크립트',
    '/studio/var/source/'                  => '업로드 폴더',
    '/studio/docs/conventions.md'          => '문서',
    '/config.php'                          => '포털 설정',
] as $p => $label) {
    $r = $anon->req($p);
    ok("$label 차단 ($p)", $r['status'] === 404, 'status=' . $r['status']);
}

// =====================================================================
section('[C] 로그인');
ok('관리자 로그인 + CSRF 토큰 확보', $admin->login($ADMIN));
ok('일반 사용자 로그인', $guest->login($USER));

$r = $admin->req('/studio/api/project.php?act=list');
ok('로그인 후 목록 200', $r['status'] === 200, 'status=' . $r['status']);
ok('응답이 {ok:true,data:{...}}', ($r['json']['ok'] ?? null) === true && isset($r['json']['data']));
ok('counts 포함', isset($r['json']['data']['counts']));

// =====================================================================
section('[D] CSRF');
$r = $admin->req('/studio/api/project.php?act=create', ['json' => ['name' => 'CSRF 없이']]);
ok('토큰 없으면 419', $r['status'] === 419, 'status=' . $r['status']);
ok('code=CSRF_EXPIRED', ($r['json']['error']['code'] ?? '') === 'CSRF_EXPIRED');

$r = $admin->req('/studio/api/project.php?act=list', ['json' => ['x' => 1], 'csrf' => true]);
ok('GET 전용 act 는 POST 여도 동작', $r['status'] === 200);

// =====================================================================
section('[E] 권한');
$r = $guest->req('/studio/api/project.php?act=create',
                 ['json' => ['name' => '권한 없는 등록'], 'csrf' => true]);
ok('일반 사용자 등록은 403', $r['status'] === 403, 'status=' . $r['status']);
ok('code=FORBIDDEN', ($r['json']['error']['code'] ?? '') === 'FORBIDDEN');

$r = $guest->req('/studio/api/project.php?act=list');
ok('일반 사용자도 조회는 200', $r['status'] === 200);

// =====================================================================
section('[F] 등록 / 기간 검증');
$r = $admin->req('/studio/api/project.php?act=create', ['json' => ['name' => ''], 'csrf' => true]);
ok('이름 없으면 400', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'MISSING_PARAM');

$r = $admin->req('/studio/api/project.php?act=create', ['csrf' => true, 'json' => [
    'name' => '기간 역전', 'dev_start' => '2026-05-01', 'dev_end' => '2026-04-01']]);
ok('기간 역전 400', $r['status'] === 400, 'status=' . $r['status']);
ok('사람이 읽을 메시지', str_contains($r['json']['error']['message'] ?? '', '뒤집'),
   $r['json']['error']['message'] ?? '');

$r = $admin->req('/studio/api/project.php?act=create', ['csrf' => true, 'json' => [
    'name' => 'API 시험 프로젝트', 'client' => 'Z대학교', 'track' => 'lxp_campus',
    'summary' => 'HTTP 레벨 시험용',
    'dev_start' => '2026-03-01', 'dev_end' => '2026-05-31',
    'test_start' => '2026-05-01', 'test_end' => '2026-06-30',
    'deploy_date' => '2026-07-10']]);
ok('정상 등록 200', $r['status'] === 200, substr($r['body'], 0, 120));
$pid = (int)($r['json']['data']['id'] ?? 0);
ok('id 반환', $pid > 0);
ok('코드 자동 채번', preg_match('/^PRJ-2026-\d{3}$/', $r['json']['data']['project']['code'] ?? '') === 1,
   $r['json']['data']['project']['code'] ?? '');
ok('개발·테스트 겹침 허용', ($r['json']['data']['project']['test_start'] ?? '') === '2026-05-01');

// =====================================================================
section('[G] 조회 / 수정');
$r = $admin->req('/studio/api/project.php?act=get&id=' . $pid);
ok('상세 200', $r['status'] === 200);
ok('sources 배열 포함', isset($r['json']['data']['sources']));
ok('can.manage true (등록자=관리자)', ($r['json']['data']['can']['manage'] ?? null) === true);
ok('file_path 는 노출 안 됨', !str_contains($r['body'], 'file_path'));

$r = $admin->req('/studio/api/project.php?act=get&id=99999');
ok('없는 id 404', $r['status'] === 404 && ($r['json']['error']['code'] ?? '') === 'NOT_FOUND');

$r = $admin->req('/studio/api/project.php?act=update',
    ['csrf' => true, 'json' => ['id' => $pid, 'name' => 'API 시험 프로젝트 (수정)', 'status' => 'scoping']]);
ok('수정 200', $r['status'] === 200);
ok('이름 반영', ($r['json']['data']['project']['name'] ?? '') === 'API 시험 프로젝트 (수정)');
ok('상태 반영', ($r['json']['data']['project']['status'] ?? '') === 'scoping');

// 회귀 — 안 보낸 칸이 지워지면 안 된다.
// 예전에 bs_read_project_input() 이 늘 열한 칸을 돌려줘서, 이름만 고쳐도
// 고객·트랙·기간이 전부 NULL 이 됐다. 화면은 전 칸을 보내 눈에 안 띄었다.
$r = $admin->req('/studio/api/project.php?act=get&id=' . $pid);
$before = $r['json']['data']['project'];
ok('수정 전 — 고객/기간이 살아 있다',
   $before['client'] === 'Z대학교' && $before['dev_start'] === '2026-03-01',
   json_encode([$before['client'], $before['dev_start']], JSON_UNESCAPED_UNICODE));

$admin->req('/studio/api/project.php?act=update',
    ['csrf' => true, 'json' => ['id' => $pid, 'name' => '이름만 바꾼다']]);
$after = $admin->req('/studio/api/project.php?act=get&id=' . $pid)['json']['data']['project'];
ok('이름만 보내도 고객이 안 지워진다', $after['client'] === 'Z대학교', var_export($after['client'], true));
ok('이름만 보내도 트랙이 안 지워진다', $after['track'] === 'lxp_campus', var_export($after['track'], true));
ok('이름만 보내도 개발기간이 안 지워진다',
   $after['dev_start'] === '2026-03-01' && $after['dev_end'] === '2026-05-31',
   $after['dev_start'] . '~' . $after['dev_end']);
ok('이름만 보내도 배포일이 안 지워진다', $after['deploy_date'] === '2026-07-10',
   var_export($after['deploy_date'], true));
ok('보낸 칸은 바뀐다', $after['name'] === '이름만 바꾼다');

// 빈 문자열을 **명시적으로** 보내면 지워지는 게 맞다(안 보낸 것과 다르다).
$admin->req('/studio/api/project.php?act=update',
    ['csrf' => true, 'json' => ['id' => $pid, 'client' => '']]);
$after2 = $admin->req('/studio/api/project.php?act=get&id=' . $pid)['json']['data']['project'];
ok('빈 값을 보내면 지워진다', $after2['client'] === null, var_export($after2['client'], true));
ok('그래도 다른 칸은 그대로', $after2['track'] === 'lxp_campus');

// 뒤 절(J)이 이 프로젝트의 이름·고객으로 검색한다. 바꿔 놓은 값을 되돌린다.
// 시험끼리 상태를 물려주면 엉뚱한 곳이 빨개진다 — 실제로 한 번 그랬다.
$admin->req('/studio/api/project.php?act=update', ['csrf' => true, 'json' => [
    'id' => $pid, 'name' => 'API 시험 프로젝트 (수정)', 'client' => 'Z대학교']]);
$restored = $admin->req('/studio/api/project.php?act=get&id=' . $pid)['json']['data']['project'];
ok('상태 되돌리기', $restored['client'] === 'Z대학교'
   && $restored['name'] === 'API 시험 프로젝트 (수정)'
   && $restored['dev_start'] === '2026-03-01');

// PM 권한: 이 프로젝트의 owner 는 관리자다. 일반 사용자는 PM 이 아니다.
$r = $guest->req('/studio/api/project.php?act=update',
    ['csrf' => true, 'json' => ['id' => $pid, 'name' => '남의 프로젝트 수정']]);
ok('PM 아닌 사람의 수정은 403', $r['status'] === 403, 'status=' . $r['status']);

// =====================================================================
section('[H] 출처 — 파일 업로드 (multipart)');
/* ┌──────────────────────────────────────────────────────────────────┐
   │ 시험용 파일에 웹셸 문자열(<?php system(...))을 쓰지 마세요.        │
   │                                                                  │
   │ 윈도 디펜더가 그 내용을 탐지해 **파일을 만들자마자 지워 버립니다.** │
   │ 그러면 curl 이 읽을 파일이 없어 errno 42(aborted by callback)로   │
   │ 끊기고, 마치 업로드 기능이 깨진 것처럼 보입니다. 실제로 그렇게     │
   │ 한 번 헤맸습니다.                                                 │
   │                                                                  │
   │ 확인하려는 것은 "내용이 확장자와 다르면 거부하는가" 이므로         │
   │ 평범한 텍스트로도 똑같이 증명됩니다.                              │
   └──────────────────────────────────────────────────────────────────┘ */
$tmp = sys_get_temp_dir() . '/bs_api_up_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

$png = "$tmp/시안.png";
file_put_contents($png, base64_decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

// 확장자로 먼저 걸리므로 내용은 아무래도 상관없다.
$evil = "$tmp/notallowed.php";
file_put_contents($evil, "평범한 텍스트입니다. 확장자만으로 걸려야 합니다.");

// 확장자는 png 인데 내용은 텍스트 → MIME 재검증에서 걸려야 한다.
$fakePng = "$tmp/fake.png";
file_put_contents($fakePng, '이것은 PNG 가 아니라 그냥 글자입니다.');

/** 업로드 직전에 파일이 실제로 있는지 본다. 없으면 시험 환경 문제다. */
function fixture(string $path): CURLFile
{
    clearstatcache(true, $path);
    if (!is_file($path) || @file_get_contents($path) === false) {
        fwrite(STDERR, "\n[시험 환경 문제] 시험용 파일을 읽지 못했습니다: $path\n"
            . "백신이 지웠을 수 있습니다. 제품 결함이 아닙니다.\n");
        exit(2);
    }
    return new CURLFile($path, 'image/png', basename($path));
}

$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'form' => [
    'project_id' => $pid, 'source_type' => 'file',
    'files[]' => fixture($png),
]]);
ok('PNG 업로드 200', $r['status'] === 200, substr($r['body'], 0, 120));
ok('1건 저장', count($r['json']['data']['saved'] ?? []) === 1);
ok('parse_status=pending', ($r['json']['data']['saved'][0]['parse_status'] ?? '') === 'pending');
ok('원본 파일명 보존', ($r['json']['data']['saved'][0]['title'] ?? '') === '시안.png');
$srcId = (int)($r['json']['data']['sources'][0]['id'] ?? 0);

$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'form' => [
    'project_id' => $pid, 'source_type' => 'file',
    'files[]' => fixture($evil),
]]);
ok('php 업로드 거부', count($r['json']['data']['failed'] ?? []) === 1, substr($r['body'], 0, 140));

$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'form' => [
    'project_id' => $pid, 'source_type' => 'file',
    'files[]' => fixture($fakePng),
]]);
ok('확장자 위장 거부', count($r['json']['data']['failed'] ?? []) === 1);
ok('거부 사유가 내용 불일치',
   str_contains($r['json']['data']['failed'][0]['message'] ?? '', '맞지 않'),
   $r['json']['data']['failed'][0]['message'] ?? '');

// 부분 성공
$png2 = "$tmp/b.png"; copy($png, $png2);
$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'form' => [
    'project_id' => $pid, 'source_type' => 'file',
    'files[0]' => fixture($png2),
    'files[1]' => fixture($evil),
]]);
ok('부분 성공 — 1건 저장 / 1건 실패',
   count($r['json']['data']['saved'] ?? []) === 1 && count($r['json']['data']['failed'] ?? []) === 1,
   substr($r['body'], 0, 160));

// 업로드된 파일에 웹으로 직접 접근 불가
$r = $anon->req('/studio/var/source/' . date('Y') . '/' . $pid . '/');
ok('업로드 경로 직접 접근 404', $r['status'] === 404);

// =====================================================================
section('[I] 출처 — 링크 / 직접 입력');
$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'source_type' => 'link',
    'url' => 'https://www.figma.com/file/abc/Design']]);
ok('피그마 링크 200', $r['status'] === 200);
ok('kind=figma', ($r['json']['data']['saved'][0]['kind'] ?? '') === 'figma');

$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'source_type' => 'link', 'url' => 'javascript:alert(1)']]);
ok('javascript: 거부 400', $r['status'] === 400, 'status=' . $r['status']);

$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'source_type' => 'text',
    'text' => "첫 줄이 제목\n본문 내용입니다"]]);
ok('직접 입력 200', $r['status'] === 200);
ok('첫 줄을 제목으로', ($r['json']['data']['saved'][0]['title'] ?? '') === '첫 줄이 제목');
ok('parse_status=ok', ($r['json']['data']['saved'][0]['parse_status'] ?? '') === 'ok');

$r = $guest->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'source_type' => 'text', 'text' => '권한 없음']]);
ok('PM 아닌 사람의 출처 등록 403', $r['status'] === 403);

// =====================================================================
section('[J] 목록 필터');
$r = $admin->req('/studio/api/project.php?act=list&keyword=' . rawurlencode('Z대학교'));
ok('키워드 검색', ($r['json']['data']['total'] ?? 0) >= 1);

$r = $admin->req('/studio/api/project.php?act=list&keyword=' . rawurlencode('%'));
ok('% 가 전건 조회를 만들지 않음', ($r['json']['data']['total'] ?? -1) === 0,
   '총 ' . ($r['json']['data']['total'] ?? '?'));

$r = $admin->req('/studio/api/project.php?act=list&sort=' . rawurlencode('x; DROP TABLE bs_project'));
ok('정렬 주입 무시', $r['status'] === 200 && ($r['json']['data']['total'] ?? 0) > 0);

$r = $admin->req('/studio/api/project.php?act=list&from=2026-04-01&to=2026-04-30');
$names = array_column($r['json']['data']['rows'] ?? [], 'name');
ok('기간 겹침 — 3~5월 건이 4월 조회에 잡힘',
   in_array('API 시험 프로젝트 (수정)', $names, true), implode(' / ', $names));

$r = $admin->req('/studio/api/project.php?act=list&size=2&page=1');
ok('페이징 size=2', count($r['json']['data']['rows'] ?? []) <= 2);

$r = $admin->req('/studio/api/project.php?act=list&mine=1');
ok('내 프로젝트만', ($r['json']['data']['total'] ?? 0) >= 1);

// =====================================================================
section('[K] 삭제');
$r = $admin->req('/studio/api/project.php?act=delete_source',
                 ['csrf' => true, 'json' => ['source_id' => $srcId]]);
ok('출처 삭제 200', $r['status'] === 200);

$r = $admin->req('/studio/api/project.php?act=delete',
                 ['csrf' => true, 'json' => ['id' => $pid, 'confirm' => '엉뚱한값']]);
ok('코드 확인 틀리면 400', $r['status'] === 400 &&
   ($r['json']['error']['code'] ?? '') === 'CONFIRM_REQUIRED', 'status=' . $r['status']);

$code = $admin->req('/studio/api/project.php?act=get&id=' . $pid)['json']['data']['project']['code'];
$r = $admin->req('/studio/api/project.php?act=delete',
                 ['csrf' => true, 'json' => ['id' => $pid, 'confirm' => $code, 'reason' => 'API 시험']]);
ok('코드 맞으면 삭제', $r['status'] === 200, substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/project.php?act=get&id=' . $pid);
ok('삭제 후 조회 — 관리자는 보임', $r['status'] === 200);
ok('is_deleted=true', ($r['json']['data']['project']['is_deleted'] ?? null) === true);

$r = $guest->req('/studio/api/project.php?act=get&id=' . $pid);
ok('삭제 후 일반 사용자는 404', $r['status'] === 404);

$r = $admin->req('/studio/api/project.php?act=list&with_deleted=1');
$del = array_filter($r['json']['data']['rows'] ?? [], fn($x) => !empty($x['is_deleted']));
ok('with_deleted 로 보임 (관리자)', count($del) >= 1);

$r = $guest->req('/studio/api/project.php?act=list&with_deleted=1');
$del = array_filter($r['json']['data']['rows'] ?? [], fn($x) => !empty($x['is_deleted']));
ok('일반 사용자는 with_deleted 무시', count($del) === 0);

$r = $admin->req('/studio/api/project.php?act=restore', ['csrf' => true, 'json' => ['id' => $pid]]);
ok('복구 200', $r['status'] === 200);

// =====================================================================
section('[L] 잘못된 요청');
$r = $admin->req('/studio/api/project.php?act=nonsense');
ok('모르는 act 400', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'UNKNOWN_ACT');
ok('가능한 act 를 알려 줌', str_contains($r['json']['error']['message'] ?? '', 'create'));

$r = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid);
ok('아직 안 만든 기능도 형식은 지킴(200 + data)',
   $r['status'] === 200 && isset($r['json']['data']));


// =====================================================================
section('[M] 후보 리스트 (명세서 §5 · §7.1 Step2)');

// ┌────────────────────────────────────────────────────────────────┐
// │ 후보 목록은 **배정을 짜는 사람만** 부를 수 있다.                 │
// │                                                                │
// │ 한때 로그인만 보고 내주었다. 그러면 profile.php 가 403 을 내는  │
// │ 같은 점수를 이쪽이 12명분 한 번에 내주게 되고, 정렬 한 번이면   │
// │ CLAUDE.md 가 금지한 전사 랭킹이 된다. 아래 단언이 그 문을 잠근다.│
// └────────────────────────────────────────────────────────────────┘
$r = $guest->req('/studio/api/candidate.php?act=list&project_id=' . $pid);
ok('배정 권한이 없으면 후보 목록 403', $r['status'] === 403, 'status=' . $r['status']);
ok('code=FORBIDDEN', ($r['json']['error']['code'] ?? '') === 'FORBIDDEN');
ok('점수가 한 건도 새지 않는다', empty($r['json']['data']['rows']),
   substr($r['body'], 0, 120));

$r = $guest->req('/studio/api/candidate.php?act=detail&project_id=' . $pid . '&member_id=1');
ok('후보 상세도 403 (계열별 점수가 여기로 샜었다)', $r['status'] === 403,
   'status=' . $r['status']);

// 화면도 같은 선을 쓴다. API 만 막고 화면을 열어 두면 빈 표가 뜨고,
// 화면만 막으면 API 를 직접 불러 뚫린다.
$r = $guest->req('/studio/project_view.php?id=' . $pid);
ok('화면의 2단계 탭이 잠긴다',
   str_contains($r['body'], 'data-pv-tab="candidate"')
   && str_contains($r['body'], 'disabled title="배정을 맡은'),
   '탭이 열려 있다');
ok('왜 못 보는지 알려 준다',
   str_contains($r['body'], '배정을 맡은 PM 과 관리자만 볼 수 있습니다'));
ok('본인 프로파일로 가는 길을 함께 준다',
   str_contains($r['body'], 'member_profile.php'));

// 프로젝트 번호 없이는 후보를 낼 수 없다 — 전역 순위를 만들지 않기 위한 방어다.
$r = $admin->req('/studio/api/candidate.php?act=list');
ok('project_id 없으면 400', $r['status'] === 400, 'status=' . $r['status']);
ok('code=MISSING_PARAM', ($r['json']['error']['code'] ?? '') === 'MISSING_PARAM');

$r = $admin->req('/studio/api/candidate.php?act=list&project_id=99999999');
ok('없는 프로젝트 404', $r['status'] === 404);

$r = $anon->req('/studio/api/candidate.php?act=list&project_id=' . $pid);
ok('로그인 없이 401', $r['status'] === 401, 'status=' . $r['status']);

// 기간이 비면 가용도를 낼 수 없다. 0% 로 때우지 않고 거절해야 한다.
$admin->req('/studio/api/project.php?act=update', ['csrf' => true, 'json' => [
    'id' => $pid, 'dev_start' => '', 'dev_end' => '',
    'test_start' => '', 'test_end' => '', 'deploy_date' => '']]);
$r = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid);
ok('기간 없으면 400 NO_PERIOD',
   $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'NO_PERIOD',
   'status=' . $r['status'] . ' ' . ($r['json']['error']['code'] ?? ''));

$admin->req('/studio/api/project.php?act=update', ['csrf' => true, 'json' => [
    'id' => $pid, 'dev_start' => '2026-10-01', 'dev_end' => '2026-12-18']]);

$r = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid);
ok('기간 넣으면 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 120));
$d = $r['json']['data'] ?? [];
ok('rows 반환', isset($d['rows']) && is_array($d['rows']) && count($d['rows']) > 0,
   'total=' . ($d['total'] ?? '?'));
ok('전역 순위가 아님을 응답이 밝힘', ($d['scope']['is_global_ranking'] ?? null) === false);
ok('scope 에 기간이 담김', ($d['scope']['period']['from'] ?? '') === '2026-10-01');

$row = $d['rows'][0] ?? [];

// --- 여기가 이 화면의 핵심 규칙이다 --------------------------------
// 확정 점유와 추정 점유를 하나의 숫자로 합쳐 내보내면 안 된다.
ok('가용도에 확정/추정이 따로 들어 있다',
   array_key_exists('confirmed_pct', $row['availability'] ?? [])
   && array_key_exists('inferred_pct', $row['availability'] ?? []));
ok('합친 점유 필드를 내보내지 않는다',
   !array_key_exists('load_pct', $row['availability'] ?? [])
   && !array_key_exists('total_load', $row['availability'] ?? []));
ok('추정에는 신뢰도가 붙는다', array_key_exists('confidence', $row['availability'] ?? []));

// 합이 언제나 100 인 것은 아니다. 두 가지 때문이다 —
//   · 반일 근무자는 기준이 50 이다(capacity_pct)
//   · 점유가 기준을 넘으면 가용은 0 에서 멈춘다(확정+추정 > 기준)
// 그래서 "합 100" 이 아니라 **가용 = max(0, 기준 - 확정 - 추정)** 을 본다.
$sumOk = true; $negOk = true; $capOk = true; $bad = '';
foreach ($d['rows'] as $x) {
    $a = $x['availability'] ?? null;
    if (!$a) { continue; }
    if (!array_key_exists('capacity_pct', $a)) { $capOk = false; continue; }
    $expect = max(0, $a['capacity_pct'] - $a['confirmed_pct'] - $a['inferred_pct']);
    if (abs($a['available_pct'] - $expect) > 1) {
        $sumOk = false;
        $bad = $x['emp_name'] . ' ' . json_encode($a);
    }
    if ($a['available_pct'] < 0 || $a['confirmed_pct'] < 0 || $a['inferred_pct'] < 0) { $negOk = false; }
}
ok('기준 근무량을 함께 준다', $capOk);
ok('가용 = max(0, 기준 - 확정 - 추정)', $sumOk, $bad);
ok('음수 가용도 없음', $negOk);

// 표본 부족은 '낮은 점수' 가 아니다 — 0 점으로 깔면 영원히 배정되지 않는다.
$nullFit = array_filter($d['rows'], fn($x) => $x['fit_score'] === null);
$zeroFit = array_filter($d['rows'], fn($x) => $x['fit_score'] === 0.0);
ok('판단 보류는 null 이지 0 이 아니다', count($zeroFit) === 0 || count($nullFit) > 0,
   'null=' . count($nullFit) . ' zero=' . count($zeroFit));

// 정렬 — 걸러진 사람은 뒤, 점수 없는 사람은 그 앞. 지우지는 않는다.
$seenOut = false; $orderOk = true; $seenNull = false; $nullOrderOk = true;
foreach ($d['rows'] as $x) {
    if ($x['filtered_out']) { $seenOut = true; }
    elseif ($seenOut) { $orderOk = false; }
    if (!$x['filtered_out']) {
        if ($x['fit_score'] === null) { $seenNull = true; }
        elseif ($seenNull) { $nullOrderOk = false; }
    }
}
ok('걸러진 후보는 뒤로 간다', $orderOk);
ok('점수 없는 후보는 0 점이 아니라 뒤로 간다', $nullOrderOk);

$total = $d['total'];
$r = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid . '&min_availability=100');
$d2 = $r['json']['data'];
ok('조건을 올려도 명단에서 지우지 않는다', $d2['total'] === $total,
   "{$d2['total']} vs $total");
ok('대신 filtered_out 으로 표시', count(array_filter($d2['rows'], fn($x) => $x['filtered_out'])) > 0);

$r = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid . '&min_availability=9999');
ok('범위를 벗어난 조건도 안전하게 처리', $r['status'] === 200);

// 분야를 고르면 그 분야가 속한 '계열' 로 본다 (분야 단위는 표본이 안 찬다)
$r = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid
                 . '&domains[]=1&domains[]=2');
$d3 = $r['json']['data'];
ok('분야 선택 200', $r['status'] === 200);
ok('scope 에 계열이 잡힌다', count($d3['scope']['categories'] ?? []) > 0,
   json_encode($d3['scope']['categories'] ?? [], JSON_UNESCAPED_UNICODE));
ok('계열로 묶인다 (분야 2개 → activity 1계열)',
   count($d3['scope']['categories']) === 1
   && ($d3['scope']['categories'][0]['code'] ?? '') === 'activity');
$withFit = array_filter($d3['rows'], fn($x) => $x['domain_fit'] !== null);
ok('분야 매치도가 붙는다', count($withFit) > 0, 'n=' . count($withFit));

// 점수를 내지 않는 계열(기획)은 조건에서 빠진다.
//
// 분야 id 를 적어 두지 않는다 — 002 시드와 005 마이그레이션의 적재 순서에
// 따라 번호가 밀린다. 실제로 전에는 21 을 기획으로 적어 두었는데, DB 를
// 새로 만들면 21 이 'UI/UX 퍼블리싱' 이 되어 시험이 깨졌다. code 로 찾는다.
$planningId = (int)(function () {
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $d   = $cfg['db'];
    $p   = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
                   $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return $p->query("SELECT id FROM bs_domain WHERE category = 'planning' ORDER BY id LIMIT 1")
             ->fetchColumn();
})();
ok('기획 계열 분야가 시드에 있다', $planningId > 0);

$r = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid
                 . '&domains[]=' . $planningId);
ok('기획 분야는 계열 조건이 되지 않는다',
   count($r['json']['data']['scope']['categories'] ?? []) === 0);

// --- 근거 드로어 ----------------------------------------------------
$mid = (int)($d['rows'][0]['member_id'] ?? 0);
$r = $admin->req('/studio/api/candidate.php?act=detail&project_id=' . $pid);
ok('member_id 없으면 400', $r['status'] === 400);

$r = $admin->req('/studio/api/candidate.php?act=detail&project_id=' . $pid . '&member_id=99999999');
ok('없는 구성원 404', $r['status'] === 404);

$r = $admin->req('/studio/api/candidate.php?act=detail&project_id=' . $pid . '&member_id=' . $mid);
ok('근거 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 120));
$dt = $r['json']['data'] ?? [];
ok('확정 내역과 추정 내역이 따로 담긴다',
   array_key_exists('confirmed_breakdown', $dt) && array_key_exists('inferred_items', $dt));
ok('영업일 수가 담긴다', ($dt['period']['workdays'] ?? 0) > 0, 'workdays=' . ($dt['period']['workdays'] ?? '?'));
ok('계열별 점수가 담긴다', is_array($dt['categories'] ?? null));

// 추정 건은 'load', 확정 건은 'load_ratio' 로 이름이 다르다. 일부러 그렇다 —
// 확정은 사람이 정한 배정률이고 추정은 난이도로 어림한 값이라 같은 종류의
// 숫자가 아니다. 이름이 같으면 화면이나 뒷사람이 둘을 더하게 된다.
$infOk = true;
foreach ($dt['inferred_items'] ?? [] as $it) {
    if (!array_key_exists('load', $it) || array_key_exists('load_ratio', $it)) { $infOk = false; }
}
ok('추정 건마다 계수를 밝히되 확정과 다른 이름을 쓴다', $infOk);
$cfOk = true;
foreach ($dt['confirmed_breakdown'] ?? [] as $it) {
    if (($it['source'] ?? '') !== 'confirmed') { $cfOk = false; }
}
ok('확정 내역에 추정이 섞이지 않는다', $cfOk);

$r = $anon->req('/studio/api/candidate.php?act=detail&project_id=' . $pid . '&member_id=' . $mid);
ok('근거도 로그인 없이는 401', $r['status'] === 401);

$r = $admin->req('/studio/api/candidate.php?act=nope&project_id=' . $pid);
ok('모르는 act 400', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'UNKNOWN_ACT');


// =====================================================================
section('[N] WBS (명세서 §7.1 Step3 앞단)');

$r = $anon->req('/studio/api/task.php?act=tree&project_id=' . $pid);
ok('로그인 없이 401', $r['status'] === 401, 'status=' . $r['status']);

$r = $admin->req('/studio/api/task.php?act=tree');
ok('project_id 없으면 400', $r['status'] === 400);
$r = $admin->req('/studio/api/task.php?act=tree&project_id=99999999');
ok('없는 프로젝트 404', $r['status'] === 404);

$r = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid);
ok('빈 트리도 200', $r['status'] === 200 && is_array($r['json']['data']['tree'] ?? null));
$rev = $r['json']['data']['revision'] ?? '';
ok('revision 을 내려 준다', strlen($rev) === 16, $rev);
ok('편집·확정 권한을 같이 알려 준다',
   isset($r['json']['data']['can_edit']) && isset($r['json']['data']['can_confirm']));

// --- 저장 -----------------------------------------------------------
$wbsTree = [
    ['title' => '분석', 'children' => [
        ['title' => '현행 조사', 'est_md' => 3, 'difficulty' => 2],
        ['title' => '인터뷰', 'children' => [
            ['title' => '교수 인터뷰', 'est_md' => 1.5, 'domain_ids' => [1, 5]],
        ]],
    ]],
    ['title' => '개발', 'children' => [
        ['title' => '출석부 개선', 'est_md' => 8, 'difficulty' => 4,
         'plan_start' => '2026-07-06', 'plan_end' => '2026-07-31'],
    ]],
];

$r = $admin->req('/studio/api/task.php?act=save_tree',
                 ['json' => ['project_id' => $pid, 'tree' => $wbsTree]]);
ok('CSRF 없으면 419', $r['status'] === 419, 'status=' . $r['status']);

$r = $guest->req('/studio/api/task.php?act=save_tree',
                 ['csrf' => true, 'json' => ['project_id' => $pid, 'tree' => $wbsTree]]);
ok('PM 아닌 사람은 403', $r['status'] === 403, 'status=' . $r['status']);

$r = $admin->req('/studio/api/task.php?act=save_tree',
                 ['csrf' => true, 'json' => ['project_id' => $pid, 'tree' => $wbsTree, 'revision' => $rev]]);
ok('저장 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 140));
$d = $r['json']['data'];
ok('6건 저장', ($d['created'] ?? 0) === 6, json_encode($d['created'] ?? null));
ok('저장 응답이 트리를 같이 돌려준다', count($d['tree'] ?? []) === 2);
ok('번호는 서버가 매긴다', ($d['tree'][0]['wbs_no'] ?? '') === '1'
   && ($d['tree'][0]['children'][0]['wbs_no'] ?? '') === '1.1',
   json_encode(array_column($d['tree'], 'wbs_no')));
ok('새 revision 을 돌려준다', ($d['revision'] ?? '') !== $rev);
$rev = $d['revision'];

$counts = $d['counts'];
ok('아직 확정 0건', $counts['confirmed'] === 0);
ok('배정 가능 0건', $counts['assignable'] === 0, json_encode($counts));
ok('총 공수 12.5', abs($counts['est_md_total'] - 12.5) < 0.01, (string)$counts['est_md_total']);

// --- 확정 -----------------------------------------------------------
$taskIds = [];
$walk = function (array $ns) use (&$walk, &$taskIds): void {
    foreach ($ns as $n) {
        if (!$n['children']) { $taskIds[] = $n['id']; }
        $walk($n['children']);
    }
};
$walk($d['tree']);
ok('말단 3건', count($taskIds) === 3, (string)count($taskIds));

$r = $guest->req('/studio/api/task.php?act=confirm',
                 ['csrf' => true, 'json' => ['task_ids' => $taskIds]]);
ok('확정도 PM 아니면 403', $r['status'] === 403, 'status=' . $r['status']);

$r = $admin->req('/studio/api/task.php?act=confirm', ['csrf' => true, 'json' => ['task_ids' => []]]);
ok('대상 없으면 400', $r['status'] === 400);

$r = $admin->req('/studio/api/task.php?act=confirm',
                 ['csrf' => true, 'json' => ['task_ids' => $taskIds]]);
ok('확정 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 120));
ok('배정 가능 3건이 됨', ($r['json']['data']['counts']['assignable'] ?? 0) === 3,
   json_encode($r['json']['data']['counts'] ?? null));
$rev = $r['json']['data']['revision'];

// 이 화면의 핵심 규칙 — 트리 저장으로 확정이 딸려 바뀌면 안 된다.
$strip = function (array $ns) use (&$strip): array {
    return array_map(static fn($n) => [
        'id' => $n['id'], 'title' => $n['title'],
        'confirmed' => false, 'status' => 'done',     // 일부러 끼워 넣는다
        'children' => $strip($n['children']),
    ], $ns);
};
$cur = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data'];
$r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'tree' => $strip($cur['tree']), 'revision' => $cur['revision']]]);
ok('save_tree 에 confirmed 를 끼워 보내도 무시된다',
   ($r['json']['data']['counts']['confirmed'] ?? -1) === 3,
   json_encode($r['json']['data']['counts'] ?? null));
ok('status 도 끼워 넣기로 못 바꾼다',
   ($r['json']['data']['counts']['assignable'] ?? -1) === 3);
$rev = $r['json']['data']['revision'];

// --- 낙관적 잠금 -----------------------------------------------------
$stale = $rev;
$cur = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data'];
$mod = $cur['tree'];
$mod[0]['title'] = '분석 (먼저 저장)';
$r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'tree' => $mod, 'revision' => $cur['revision']]]);
ok('먼저 저장한 쪽은 통과', $r['status'] === 200);

$r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'tree' => $mod, 'revision' => $stale]]);
ok('낡은 revision 은 거절', $r['status'] === 400
   && str_contains($r['json']['error']['message'] ?? '', '먼저 저장'),
   'status=' . $r['status'] . ' ' . substr($r['body'], 0, 120));

$rev = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data']['revision'];

// --- 검증 -----------------------------------------------------------
$r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'revision' => $rev,
    'tree' => [['title' => 'A', 'children' => [['title' => 'B', 'children' => [
        ['title' => 'C', 'children' => [['title' => 'D']]]]]]]]]]);
ok('4단계는 400', $r['status'] === 400 && str_contains($r['json']['error']['message'] ?? '', '3단계'),
   substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'revision' => $rev, 'tree' => [['title' => '   ']]]]);
ok('빈 제목은 400', $r['status'] === 400);

$after = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data'];
ok('거절된 저장이 트리를 건드리지 않았다', ($after['counts']['total'] ?? 0) === 6,
   json_encode($after['counts'] ?? null));

// --- 한 건 수정 ------------------------------------------------------
$one = $taskIds[0];
$r = $admin->req('/studio/api/task.php?act=update', ['csrf' => true, 'json' => [
    'id' => $one, 'est_md' => 5.5, 'difficulty' => 3]]);
ok('한 건 수정 200', $r['status'] === 200, substr($r['body'], 0, 120));
ok('값이 바뀜', abs(($r['json']['data']['task']['est_md'] ?? 0) - 5.5) < 0.01);
ok('확정은 그대로', ($r['json']['data']['task']['confirmed'] ?? null) === true);

$r = $admin->req('/studio/api/task.php?act=update', ['csrf' => true, 'json' => [
    'id' => $one, 'confirmed' => false]]);
ok('update 로는 확정을 못 바꾼다 (바꿀 내용 없음)', $r['status'] === 400,
   'status=' . $r['status']);

$r = $guest->req('/studio/api/task.php?act=update', ['csrf' => true, 'json' => [
    'id' => $one, 'title' => '남의 태스크']]);
ok('PM 아니면 수정 403', $r['status'] === 403);

// --- 이동 ------------------------------------------------------------
$r = $admin->req('/studio/api/task.php?act=move', ['csrf' => true, 'json' => ['id' => $one]]);
ok('parent_id 를 빼면 400', $r['status'] === 400
   && str_contains($r['json']['error']['message'] ?? '', 'parent_id'),
   substr($r['body'], 0, 120));

$cur  = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data'];
$last = end($cur['tree'])['id'];
$r = $admin->req('/studio/api/task.php?act=move',
                 ['csrf' => true, 'json' => ['id' => $last, 'parent_id' => null, 'seq' => 0]]);
ok('최상위로 이동 200', $r['status'] === 200, substr($r['body'], 0, 120));
ok('맨 앞으로 갔고 번호가 다시 매겨짐',
   ($r['json']['data']['tree'][0]['id'] ?? 0) === $last
   && ($r['json']['data']['tree'][0]['wbs_no'] ?? '') === '1');

// --- 확정 해제 / 삭제 -------------------------------------------------
$r = $admin->req('/studio/api/task.php?act=unconfirm',
                 ['csrf' => true, 'json' => ['task_ids' => [$one]]]);
ok('확정 해제 200', $r['status'] === 200);
ok('배정 가능이 2건으로 줄어듦', ($r['json']['data']['counts']['assignable'] ?? 0) === 2,
   json_encode($r['json']['data']['counts'] ?? null));

$r = $guest->req('/studio/api/task.php?act=delete', ['csrf' => true, 'json' => ['id' => $one]]);
ok('PM 아니면 삭제 403', $r['status'] === 403);

$r = $admin->req('/studio/api/task.php?act=delete', ['csrf' => true, 'json' => ['id' => $one]]);
ok('삭제 200', $r['status'] === 200, substr($r['body'], 0, 120));

// --- 직접 입력한 글도 도출 입력이다 ---------------------------------------
// 앞 [I] 에서 넣은 text 출처가 parse_status='ok' 로 남아 있다. 파일만
// 입력인 것이 아니다 — 회의 중에 적어 넣은 글도 그대로 근거가 된다.
$r = $admin->req('/studio/api/task.php?act=extract',
                 ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('직접 입력한 글에서도 도출된다', $r['status'] === 200, 'status=' . $r['status']);
ok('초안이 나온다', !empty($r['json']['data']['tree']));
$beforeX = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)
                 ['json']['data']['counts']['total'];
$admin->req('/studio/api/task.php?act=extract', ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('도출은 저장하지 않는다',
   $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)
         ['json']['data']['counts']['total'] === $beforeX,
   '도출 전 ' . $beforeX . '건');

$r = $admin->req('/studio/api/task.php?act=nope&project_id=' . $pid);
ok('모르는 act 400', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'UNKNOWN_ACT');

// 뒷정리 — 이 프로젝트는 뒤에서 삭제 시험에 쓰이므로 태스크를 비워 둔다.
$cur = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data'];
$admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'tree' => [], 'revision' => $cur['revision']]]);


// =====================================================================
section('[O] 문서 분석 → WBS 도출');

// 시험용 문서를 이 프로젝트에 붙인다. 파일은 dev/fixtures 의 실제 문서다.
$fixDir = __DIR__ . '/fixtures';
if (!is_file("$fixDir/sample.xlsx")) {
    echo "  (건너뜀) dev/fixtures 에 시험 문서가 없습니다.\n"
       . "  만들려면: python studio/dev/fixtures/make_fixtures.py studio/dev/fixtures\n";
} else {
    $pdoX = (function () {
        $cfg = require dirname(__DIR__, 2) . '/config.php';
        $d = $cfg['db'];
        return new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
            $d['user'], $d['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    })();
    $pdoX->prepare('DELETE FROM bs_project_source WHERE project_id = ?')->execute([$pid]);
    $pdoX->prepare('DELETE FROM bs_task WHERE project_id = ?')->execute([$pid]);
    $insX = $pdoX->prepare(
        'INSERT INTO bs_project_source (project_id, kind, title, file_path, parse_status, uploaded_by)
         VALUES (?,?,?,?, "pending", "api_test")'
    );
    foreach ([['xlsx', '요구사항 정의서', 'sample.xlsx'],
              ['pptx', '킥오프 자료',     'sample.pptx'],
              ['docx', '회의록',          'sample.docx'],
              ['xlsx', '깨진 파일',       'broken.xlsx']] as [$k, $t, $fn]) {
        $insX->execute([$pid, $k, $t, "$fixDir/$fn"]);
    }
    $pdoX->prepare('INSERT INTO bs_project_source (project_id, kind, title, url, parse_status, uploaded_by)
                    VALUES (?, "figma", "화면 시안", "https://figma.com/x", "pending", "api_test")')
         ->execute([$pid]);

    // --- 권한 ---
    $r = $guest->req('/studio/api/task.php?act=parse',
                     ['csrf' => true, 'json' => ['project_id' => $pid]]);
    ok('문서 분석은 PM 아니면 403', $r['status'] === 403, 'status=' . $r['status']);

    $r = $admin->req('/studio/api/task.php?act=parse', ['json' => ['project_id' => $pid]]);
    ok('CSRF 없으면 419', $r['status'] === 419);

    // --- 분석 ---
    $r = $admin->req('/studio/api/task.php?act=parse',
                     ['csrf' => true, 'json' => ['project_id' => $pid]]);
    ok('문서 분석 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 140));
    $sum = $r['json']['data']['summary'] ?? [];
    ok('3건 읽음', ($sum['ok'] ?? 0) === 3, json_encode($sum));

    // 한 파일이 깨져도 나머지가 계속 가야 한다. 이게 이 단계의 핵심이다.
    ok('깨진 파일 1건만 실패', ($sum['fail'] ?? 0) === 1, json_encode($sum));
    ok('링크는 실패가 아니라 건너뜀', ($sum['skip'] ?? 0) === 1, json_encode($sum));

    $bad = null;
    foreach ($r['json']['data']['sources'] as $s) {
        if ($s['status'] === 'fail') { $bad = $s; }
    }
    ok('실패 사유를 남긴다', !empty($bad['error']), json_encode($bad, JSON_UNESCAPED_UNICODE));
    ok('읽은 문서는 글자 수를 알려 준다',
       count(array_filter($r['json']['data']['sources'],
             fn($x) => $x['status'] === 'ok' && $x['chars'] > 0)) === 3);

    $r = $admin->req('/studio/api/task.php?act=parse',
                     ['csrf' => true, 'json' => ['project_id' => $pid]]);
    ok('두 번째는 대기 중인 것만 본다', ($r['json']['data']['summary']['touched'] ?? -1) === 0,
       json_encode($r['json']['data']['summary'] ?? null));

    $r = $admin->req('/studio/api/task.php?act=parse',
                     ['csrf' => true, 'json' => ['project_id' => $pid, 'all' => 1]]);
    ok('all=1 이면 다시 읽는다', ($r['json']['data']['summary']['touched'] ?? 0) === 5,
       json_encode($r['json']['data']['summary'] ?? null));

    // --- 도출 ---
    $r = $guest->req('/studio/api/task.php?act=extract',
                     ['csrf' => true, 'json' => ['project_id' => $pid]]);
    ok('도출도 PM 아니면 403', $r['status'] === 403);

    $r = $admin->req('/studio/api/task.php?act=extract',
                     ['csrf' => true, 'json' => ['project_id' => $pid]]);
    ok('도출 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 160));
    $dx = $r['json']['data'];
    ok('초안 트리가 온다', !empty($dx['tree']));
    ok('저장하지 않는다',
       $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)
             ['json']['data']['counts']['total'] === 0,
       '도출만으로 태스크가 생기면 안 된다');

    $flatX = [];
    $walkX = function (array $ns) use (&$walkX, &$flatX): void {
        foreach ($ns as $n) { $flatX[] = $n; $walkX($n['children']); }
    };
    $walkX($dx['tree']);
    ok('전부 origin=auto',
       count(array_filter($flatX, fn($n) => $n['origin'] === 'auto')) === count($flatX));
    ok('전부 미확정',
       count(array_filter($flatX, fn($n) => !empty($n['confirmed']))) === 0);
    ok('출처가 붙어 있다',
       count(array_filter($flatX, fn($n) => !empty($n['source_ref']))) === count($flatX),
       json_encode(array_column($flatX, 'source_ref'), JSON_UNESCAPED_UNICODE));
    ok('어느 문서에서 왔는지도 남는다',
       count(array_filter($flatX, fn($n) => !empty($n['source_id']))) === count($flatX));

    // LLM 이 없으면 조용히 규칙으로 내려가지 않고 그렇다고 말해야 한다.
    ok('LLM 이 없으면 그 사실을 알려 준다',
       ($dx['meta']['llm_available'] ?? true) === false
       && !empty($dx['meta']['fallback_reason']),
       json_encode($dx['meta'] ?? null, JSON_UNESCAPED_UNICODE));
    ok('규칙 결과는 품질 주의를 함께 준다', !empty($dx['meta']['quality_note']));
    ok('검토가 필요하다고 못 박는다',
       str_contains($dx['notice'] ?? '', '확정해야 배정 대상'));

    // --- 초안을 저장해도 배정 대상이 되지 않는다 ---
    $revX = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)
                  ['json']['data']['revision'];
    $r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
        'project_id' => $pid, 'tree' => $dx['tree'], 'revision' => $revX]]);
    ok('초안 저장 200', $r['status'] === 200, substr($r['body'], 0, 140));
    ok('저장해도 배정 가능 0건', ($r['json']['data']['counts']['assignable'] ?? -1) === 0,
       json_encode($r['json']['data']['counts'] ?? null));
    ok('전부 미확정으로 들어간다',
       $r['json']['data']['counts']['confirmed'] === 0);

    $savedX = [];
    $walkY = function (array $ns) use (&$walkY, &$savedX): void {
        foreach ($ns as $n) { $savedX[] = $n; $walkY($n['children']); }
    };
    $walkY($r['json']['data']['tree']);
    ok('저장 뒤에도 origin=auto 가 남는다',
       count(array_filter($savedX, fn($n) => $n['origin'] === 'auto')) === count($savedX),
       json_encode(array_count_values(array_column($savedX, 'origin'))));
    ok('저장 뒤에도 출처가 남는다',
       count(array_filter($savedX, fn($n) => !empty($n['source_ref']))) === count($savedX));

    // --- 남의 문서를 출처로 끼워 넣기 ---
    $otherSrcId = (int)$pdoX->query(
        'SELECT id FROM bs_project_source WHERE project_id <> ' . (int)$pid . ' LIMIT 1'
    )->fetchColumn();
    if ($otherSrcId) {
        $revX = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)
                      ['json']['data']['revision'];
        $r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
            'project_id' => $pid, 'revision' => $revX,
            'tree' => [['title' => '가로채기', 'source_id' => $otherSrcId]]]]);
        ok('남의 프로젝트 문서를 출처로 못 쓴다', $r['status'] === 400
           && str_contains($r['json']['error']['message'] ?? '', '문서가 아닌 출처'),
           'status=' . $r['status'] . ' ' . substr($r['body'], 0, 120));
    }

    // --- 문서가 없으면 ---
    $r = $admin->req('/studio/api/task.php?act=extract',
                     ['csrf' => true, 'json' => ['project_id' => 99999999]]);
    ok('없는 프로젝트 404', $r['status'] === 404);

    // ┌──────────────────────────────────────────────────────────────┐
    // │ AI 도출은 큐로 간다 (2026-10-06)                              │
    // │                                                              │
    // │ 웹 요청 안에서 모델을 부르다 100초가 넘어 두 번 끊겼다.       │
    // │ 화면에는 "서버 응답을 읽지 못했습니다" 만 떴다.               │
    // │                                                              │
    // │ 여기서는 AI 가 연결돼 있지 않아 **규칙 경로로 즉시 끝나야**    │
    // │ 한다. 멀쩡한 길을 큐로 보내 1분 기다리게 하면 안 된다.        │
    // └──────────────────────────────────────────────────────────────┘
    $r = $admin->req('/studio/api/task.php?act=extract',
                     ['csrf' => true, 'json' => ['project_id' => $pid, 'use_llm' => 1]]);
    ok('★ AI 가 없으면 규칙으로 즉시 끝낸다',
       $r['status'] === 200 && !isset($r['json']['data']['queued'])
       && isset($r['json']['data']['tree']), substr($r['body'], 0, 160));
    ok('왜 규칙으로 갔는지 알려 준다',
       str_contains((string)($r['json']['data']['meta']['fallback_reason'] ?? ''), 'LLM'),
       json_encode($r['json']['data']['meta'] ?? [], JSON_UNESCAPED_UNICODE));

    $r = $admin->req('/studio/api/task.php?act=extract',
                     ['csrf' => true, 'json' => ['project_id' => $pid, 'use_llm' => 0]]);
    ok('규칙만 쓰라고 해도 즉시 끝낸다',
       $r['status'] === 200 && isset($r['json']['data']['tree']), substr($r['body'], 0, 120));

    // 큐에 넣은 적이 없으면 'none'. 화면이 괜히 되묻지 않게 한다.
    $r = $admin->req('/studio/api/task.php?act=extract_status&project_id=' . $pid);
    ok('도출 작업이 없으면 none', ($r['json']['data']['status'] ?? '') === 'none', $r['body']);

    $r = $guest->req('/studio/api/task.php?act=extract_status&project_id=' . $pid);
    ok('도출 상태도 권한이 있어야', $r['status'] === 403, '상태 ' . $r['status']);

    // ---- 큐 배관을 끝까지 돌린다 ----
    // AI 가 없으니 워커 안에서도 규칙으로 떨어지지만, **작업을 집어
    // 결과를 담고 화면이 받아 가는 길**은 그대로 돈다. 거기가 새로 만든
    // 부분이고, 끊기면 초안이 영영 안 올라온다.
    // 앞선 시험이 이미 저장해 둔 태스크가 있다. 도출 때문에 늘었는지만
    // 보려면 **전후를 비교**해야 한다.
    $before = (int)$pdoX->query("SELECT COUNT(*) FROM bs_task WHERE project_id = $pid")
                        ->fetchColumn();

    $pdoX->prepare('INSERT INTO bs_analysis_job (project_id, kind, status, total, created_by)
                    VALUES (?, "wbs", "queued", 1, "batest-admin@bluesoft.co.kr")')
         ->execute([$pid]);

    $w = shell_exec(escapeshellarg(PHP_BINARY) . ' '
       . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');
    ok('워커가 도출 작업을 집어 간다', str_contains((string)$w, '초안'), trim((string)$w));

    $r = $admin->req('/studio/api/task.php?act=extract_status&project_id=' . $pid);
    $d = $r['json']['data'] ?? [];
    ok('★ 끝나면 상태가 done', ($d['status'] ?? '') === 'done', json_encode($d['status'] ?? null));
    // ★ 초안을 돌려줄 뿐 저장하지 않는다. 저장하면 "도출이 곧 저장" 이
    //   되어 검토 단계가 형식만 남는다.
    ok('★ 초안 트리를 돌려준다', isset($d['tree']) && is_array($d['tree']) && $d['tree'] !== [],
       substr($r['body'], 0, 160));
    $after = (int)$pdoX->query("SELECT COUNT(*) FROM bs_task WHERE project_id = $pid")
                       ->fetchColumn();
    ok('★ 돌려줄 뿐 저장하지는 않는다', $after === $before,
       "태스크가 $before → $after 로 늘었다");
    ok('사람이 할 일을 적어 준다',
       str_contains((string)($d['notice'] ?? ''), '검토'), (string)($d['notice'] ?? ''));

    $pdoX->prepare('DELETE FROM bs_analysis_job WHERE project_id = ?')->execute([$pid]);

    // 뒷정리 — 뒤의 삭제 시험이 쓰도록 비워 둔다
    $revX = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)
                  ['json']['data']['revision'];
    $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
        'project_id' => $pid, 'tree' => [], 'revision' => $revX]]);
    $pdoX->prepare('DELETE FROM bs_project_source WHERE project_id = ?')->execute([$pid]);
}


// =====================================================================
section('[P] 배정안 (명세서 §6)');

// 이 프로젝트에는 확정된 태스크가 없다. 그 상태부터 확인한다.
$r = $anon->req('/studio/api/allocate.php?act=versions&project_id=' . $pid);
ok('로그인 없이 401', $r['status'] === 401, 'status=' . $r['status']);

$r = $admin->req('/studio/api/allocate.php?act=versions');
ok('project_id 없으면 400', $r['status'] === 400);

$r = $admin->req('/studio/api/allocate.php?act=current&project_id=' . $pid);
// 주의: `$a['k'] ?? 'x'` 는 값이 null 이어도 'x' 를 준다. null 인지 보려면
// 키가 있는지와 값이 null 인지를 따로 봐야 한다.
$d0 = $r['json']['data'];
ok('확정본이 없으면 null',
   array_key_exists('allocation', $d0) && $d0['allocation'] === null,
   substr($r['body'], 0, 120));
ok('그 사실을 말로 알려 준다',
   str_contains($r['json']['data']['message'] ?? '', '확정'));

$r = $admin->req('/studio/api/allocate.php?act=propose',
                 ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('확정된 태스크가 없으면 400', $r['status'] === 400
   && ($r['json']['error']['code'] ?? '') === 'NO_TASKS',
   'status=' . $r['status'] . ' ' . substr($r['body'], 0, 100));

// --- WBS 를 만들고 확정한다 -------------------------------------------
$cur = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data'];
$r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'revision' => $cur['revision'],
    'tree' => [
        ['title' => '배정 시험 대분류', 'children' => [
            ['title' => '가 태스크', 'est_md' => 4, 'difficulty' => 3, 'domain_ids' => [1]],
            ['title' => '나 태스크', 'est_md' => 3, 'difficulty' => 2, 'domain_ids' => [20]],
        ]],
    ],
]]);
ok('시험용 WBS 저장', $r['status'] === 200, substr($r['body'], 0, 120));

$leafIds = [];
$walkP = function (array $ns) use (&$walkP, &$leafIds): void {
    foreach ($ns as $n) {
        if (!$n['children']) { $leafIds[] = $n['id']; }
        $walkP($n['children']);
    }
};
$walkP($r['json']['data']['tree']);
$admin->req('/studio/api/task.php?act=confirm', ['csrf' => true, 'json' => ['task_ids' => $leafIds]]);

// --- 권한 -------------------------------------------------------------
$r = $guest->req('/studio/api/allocate.php?act=propose',
                 ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('PM 아니면 산출 403', $r['status'] === 403, 'status=' . $r['status']);

$r = $admin->req('/studio/api/allocate.php?act=propose', ['json' => ['project_id' => $pid]]);
ok('CSRF 없으면 419', $r['status'] === 419);

// --- 산출 -------------------------------------------------------------
$r = $admin->req('/studio/api/allocate.php?act=propose',
                 ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('산출 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 160));
$al   = $r['json']['data'];
$aid  = $al['allocation_id'];
ok('1차로 시작', $al['version'] === 1, (string)$al['version']);
ok('proposed 로 시작', $al['status'] === 'proposed');
ok('항목 2건', count($al['items']) === 2, (string)count($al['items']));
ok('부하가 함께 온다', count($al['load']) > 0);
ok('결정론임을 밝힌다', ($al['meta']['deterministic'] ?? null) === true);
ok('가중치에 comm 이 없다', !array_key_exists('comm', $al['meta']['weights'] ?? []),
   json_encode($al['meta']['weights'] ?? null));
ok('모든 항목에 근거가 있다',
   count(array_filter($al['items'], fn($i) => !empty($i['reason']['lines']))) === count($al['items']));
ok('근거는 3줄', count($al['items'][0]['reason']['lines']) === 3);

// 같은 입력이면 같은 결과 — HTTP 로도 확인한다.
$sigP = function (array $items): string {
    $a = [];
    foreach ($items as $i) { $a[] = $i['task_id'] . ':' . $i['member_id'] . ':' . $i['fit_score']; }
    sort($a);
    return sha1(implode('|', $a));
};
$r2 = $admin->req('/studio/api/allocate.php?act=propose',
                  ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('다시 산출해도 같은 결과', $sigP($r2['json']['data']['items']) === $sigP($al['items']));
ok('버전은 올라간다', $r2['json']['data']['version'] === 2);
$aid2 = $r2['json']['data']['allocation_id'];

// ---- 무작위 배정 (HTTP 경로) -----------------------------------------
// 엔진 시험(alloc_test)이 로직을 보고, 여기서는 **씨앗이 끝까지 남는가**를
// 본다. params_json 에 안 남으면 재현도 못 하고, 나중에 "이 실적이 무작위
// 배정에서 나온 것인가" 도 알 수 없다.
$rnd = $admin->req('/studio/api/allocate.php?act=propose', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'method' => 'random_even', 'seed' => 4242]]);
ok('무작위로 산출된다', $rnd['status'] === 200, substr($rnd['body'], 0, 160));
$rd = $rnd['json']['data'];
ok('방식과 씨앗을 돌려준다',
   ($rd['meta']['method'] ?? '') === 'random_even' && (int)($rd['meta']['seed'] ?? 0) === 4242,
   json_encode($rd['meta']['method'] ?? null) . '/' . json_encode($rd['meta']['seed'] ?? null));
ok('★ 무작위라고 말해 준다', str_contains((string)($rd['message'] ?? ''), '무작위'),
   (string)($rd['message'] ?? ''));

// ★ 재현이 이 기능의 전부다. 같은 씨앗으로 다시 부르면 같은 배정이어야 한다.
$rnd2 = $admin->req('/studio/api/allocate.php?act=propose', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'method' => 'random_even', 'seed' => 4242]]);
ok('★ 같은 씨앗이면 같은 배정 (HTTP 로도)',
   $sigP($rnd2['json']['data']['items']) === $sigP($rd['items']));

// ★ 씨앗이 params_json 에 남아야 나중에 재현·추적이 된다.
$saved = $pdoX->query("SELECT params_json FROM bs_allocation WHERE id = "
                    . (int)$rd['allocation_id'])->fetchColumn();
$sp = json_decode((string)$saved, true);
ok('★ 방식과 씨앗이 배정안에 저장된다',
   ($sp['method'] ?? '') === 'random_even' && (int)($sp['seed'] ?? 0) === 4242,
   (string)$saved);

// 적합도와 근거는 무작위여도 그대로 나와야 한다 — 초과 경고가 사라지면 안 된다.
ok('★ 무작위여도 적합도를 계산한다',
   $rd['items'][0]['fit_score'] !== null, json_encode($rd['items'][0]['fit_score'] ?? null));
ok('무작위여도 근거가 남는다', !empty($rd['items'][0]['reason']['lines']));

// 모르는 방식은 가중치로. 오타 하나로 배정 방식이 바뀌면 안 된다.
$rbad = $admin->req('/studio/api/allocate.php?act=propose', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'method' => '아무거나']]);
ok('★ 모르는 방식은 가중치로 돌린다',
   ($rbad['json']['data']['meta']['method'] ?? '') === 'weighted',
   json_encode($rbad['json']['data']['meta']['method'] ?? null));

// --- 확정 전 노출 차단 -------------------------------------------------
$r = $admin->req('/studio/api/allocate.php?act=current&project_id=' . $pid);
$d1 = $r['json']['data'];
ok('산출만으로는 대시보드에 안 나온다',
   array_key_exists('allocation', $d1) && $d1['allocation'] === null,
   substr($r['body'], 0, 120));

$r = $guest->req('/studio/api/allocate.php?act=detail&allocation_id=' . $aid2);
ok('PM 아니면 초안을 못 연다', $r['status'] === 403, 'status=' . $r['status']);

$r = $guest->req('/studio/api/allocate.php?act=versions&project_id=' . $pid);
ok('목록에서도 초안이 빠진다', count($r['json']['data']['rows']) === 0,
   (string)count($r['json']['data']['rows']));
// 몇 차까지 만들었는지는 앞선 시험이 늘어나면 달라진다. 숫자를 박아 두면
// 배정안을 하나 더 만드는 시험을 추가할 때마다 여기가 깨진다 — 관계로 본다.
$adminVers = count($admin->req('/studio/api/allocate.php?act=versions&project_id=' . $pid)
                         ['json']['data']['rows']);
ok('몇 개가 감춰졌는지는 알려 준다',
   ($r['json']['data']['hidden_drafts'] ?? 0) === $adminVers && $adminVers > 0,
   ($r['json']['data']['hidden_drafts'] ?? -1) . ' / 관리자에게는 ' . $adminVers . '건');

$r = $guest->req('/studio/api/allocate.php?act=members&project_id=' . $pid);
ok('구성원 목록도 PM 전용', $r['status'] === 403);

// --- 가중치 ------------------------------------------------------------
$r = $admin->req('/studio/api/allocate.php?act=propose', ['csrf' => true, 'json' => [
    'project_id' => $pid,
    'weights' => ['domain' => 1.0, 'cap' => 0, 'avail' => 0, 'career' => 0, 'growth' => 0]]]);
ok('가중치를 바꿔 산출 200', $r['status'] === 200);
ok('바꾼 가중치가 기록된다',
   abs(($r['json']['data']['meta']['weights']['domain'] ?? 0) - 1.0) < 0.001);
$aid3 = $r['json']['data']['allocation_id'];

$r = $admin->req('/studio/api/allocate.php?act=propose', ['csrf' => true, 'json' => [
    'project_id' => $pid,
    'weights' => ['domain' => 0, 'cap' => 0, 'avail' => 0, 'career' => 0, 'growth' => 0]]]);
ok('가중치가 전부 0 이면 400', $r['status'] === 400, 'status=' . $r['status']);

// --- 수동 조정 ---------------------------------------------------------
$det   = $admin->req('/studio/api/allocate.php?act=detail&allocation_id=' . $aid3)['json']['data'];
$item  = $det['items'][0];
$mlist = $admin->req('/studio/api/allocate.php?act=members&project_id=' . $pid)['json']['data']['rows'];
$otherM = null;
foreach ($mlist as $m) { if ($m['id'] !== $item['member_id']) { $otherM = $m['id']; break; } }

$r = $admin->req('/studio/api/allocate.php?act=update_item',
                 ['csrf' => true, 'json' => ['item_id' => $item['id'], 'member_id' => $otherM]]);
ok('사유 없이 담당자 변경은 400', $r['status'] === 400
   && str_contains($r['json']['error']['message'] ?? '', '사유'),
   substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/allocate.php?act=update_item', ['csrf' => true, 'json' => [
    'item_id' => $item['id'], 'member_id' => $otherM, 'manual_note' => '본인 요청']]);
ok('사유와 함께면 200', $r['status'] === 200, substr($r['body'], 0, 120));
ok('adjusted 로 바뀐다', ($r['json']['data']['allocation']['status'] ?? '') === 'adjusted');
$changed = null;
foreach ($r['json']['data']['items'] as $x) { if ($x['id'] === $item['id']) { $changed = $x; } }
ok('수동 표시가 붙는다', $changed['is_manual'] === true);
ok('엔진 점수는 지운다', $changed['fit_score'] === null,
   json_encode($changed['fit_score']));
ok('부하가 다시 계산돼 온다', count($r['json']['data']['load']) > 0);

$r = $guest->req('/studio/api/allocate.php?act=update_item', ['csrf' => true, 'json' => [
    'item_id' => $item['id'], 'member_id' => $otherM, 'manual_note' => 'x']]);
ok('PM 아니면 조정 403', $r['status'] === 403);

// --- 확정 --------------------------------------------------------------
$r = $guest->req('/studio/api/allocate.php?act=confirm',
                 ['csrf' => true, 'json' => ['allocation_id' => $aid3]]);
ok('PM 아니면 확정 403', $r['status'] === 403);

$r = $admin->req('/studio/api/allocate.php?act=confirm',
                 ['csrf' => true, 'json' => ['allocation_id' => $aid3]]);
if ($r['status'] === 409) {
    ok('과배정이면 한 번 더 묻는다', ($r['json']['error']['code'] ?? '') === 'OVERLOAD');
    $r = $admin->req('/studio/api/allocate.php?act=confirm', ['csrf' => true, 'json' => [
        'allocation_id' => $aid3, 'accept_overload' => 1]]);
} else {
    ok('과배정이 없으면 바로 확정', $r['status'] === 200, 'status=' . $r['status']);
}
ok('확정 200', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 140));
ok('보냈다고 말하지 않는다',
   str_contains($r['json']['data']['notify_notice'] ?? '', '적재만'),
   $r['json']['data']['notify_notice'] ?? '(없음)');

$r = $admin->req('/studio/api/allocate.php?act=current&project_id=' . $pid);
ok('이제 대시보드에 나온다', ($r['json']['data']['allocation']['id'] ?? 0) === $aid3);
ok('항목도 함께', count($r['json']['data']['items']) === 2);

$r = $guest->req('/studio/api/allocate.php?act=detail&allocation_id=' . $aid3);
ok('확정본은 일반 사용자도 볼 수 있다', $r['status'] === 200, 'status=' . $r['status']);
ok('편집 권한은 없다', ($r['json']['data']['can_edit'] ?? true) === false);

$r = $admin->req('/studio/api/allocate.php?act=update_item', ['csrf' => true, 'json' => [
    'item_id' => $item['id'], 'member_id' => $item['member_id'], 'manual_note' => '되돌리기']]);
ok('확정본은 고칠 수 없다', $r['status'] === 400
   && str_contains($r['json']['error']['message'] ?? '', '확정'),
   substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/allocate.php?act=confirm',
                 ['csrf' => true, 'json' => ['allocation_id' => $aid3]]);
ok('두 번 확정 불가', $r['status'] === 400);

$r = $admin->req('/studio/api/allocate.php?act=versions&project_id=' . $pid);
$confirmedN = count(array_filter($r['json']['data']['rows'],
                                 fn($x) => $x['status'] === 'confirmed'));
ok('확정본은 언제나 하나', $confirmedN === 1, (string)$confirmedN);

$r = $admin->req('/studio/api/allocate.php?act=nope&project_id=' . $pid);
ok('모르는 act 400', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'UNKNOWN_ACT');

// 뒷정리는 따로 하지 않는다. 뒤의 프로젝트 삭제는 소프트 삭제라
// 배정안이 남아 있어도 걸리지 않는다(bs_allocation 은 FK CASCADE 라
// 실제로 지울 때 같이 사라진다).

// 뒷정리

// =====================================================================
section('[Q] 대시보드와 진행상황 (명세서 §7.1 Step4 · §7.2)');

// 앞 [P] 구간이 확정 배정안을 남겨 둔다. 여기서는 '확정 전' 상태부터
// 봐야 하므로 배정안을 치운다. 태스크가 배정안에 묶여 있으면 WBS 도
// 못 고친다(그게 맞는 동작이다) — 그래서 DB 에서 직접 지운다.
(function () use ($pid) {
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $dbq = $cfg['db'];
    $p = new PDO(
        "mysql:host={$dbq['host']};port={$dbq['port']};dbname={$dbq['name']};charset=utf8mb4",
        $dbq['user'], $dbq['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->prepare('DELETE FROM bs_allocation WHERE project_id = ?')->execute([$pid]);
    $p->prepare(
        'DELETE pr FROM bs_progress pr JOIN bs_task t ON t.id = pr.task_id
          WHERE t.project_id = ?'
    )->execute([$pid]);
})();

$r = $anon->req('/studio/api/dashboard.php?act=projects');
ok('로그인 없이 401', $r['status'] === 401, 'status=' . $r['status']);
$r = $anon->req('/studio/index.php');
ok('대시보드 화면도 로그인 필요', $r['status'] === 302, 'status=' . $r['status']);

$r = $admin->req('/studio/index.php');
ok('대시보드 화면 200', $r['status'] === 200);
ok('플레이스홀더가 남아 있지 않다', !str_contains($r['body'], 'ba-placeholder'));
foreach (['ba-mine' => '내 일', 'ba-cards' => '프로젝트 카드', 'ba-bd-kanban' => '칸반',
          'ba-bd-gantt' => '간트', 'ba-bd-late' => '지연', 'ba-bd-feed' => '피드',
          'ba-pg-drawer' => '진행 드로어'] as $id => $label) {
    ok("화면에 $label 있음", str_contains($r['body'], 'id="' . $id . '"'));
}

// --- 확정 전에는 보드가 비어 있다 -------------------------------------
$r = $admin->req('/studio/api/dashboard.php?act=board&project_id=' . $pid);
$dq = $r['json']['data'];
ok('확정 배정안이 없으면 allocation=null',
   array_key_exists('allocation', $dq) && $dq['allocation'] === null,
   substr($r['body'], 0, 120));
ok('왜 비었는지 말해 준다', str_contains($dq['message'] ?? '', '확정'));
ok('칸반 뼈대는 그려 준다(열이 사라지지 않게)', count($dq['columns']) === 7,
   (string)count($dq['columns']));
$empty = array_sum(array_column($dq['columns'], 'count'));
ok('다만 카드는 없다', $empty === 0, (string)$empty);

$r = $admin->req('/studio/api/dashboard.php?act=board');
ok('project_id 없으면 400', $r['status'] === 400);
$r = $admin->req('/studio/api/dashboard.php?act=board&project_id=99999999');
ok('없는 프로젝트 404', $r['status'] === 404);

// --- WBS 확정 → 배정 확정 ---------------------------------------------
$cur = $admin->req('/studio/api/task.php?act=tree&project_id=' . $pid)['json']['data'];
$r = $admin->req('/studio/api/task.php?act=save_tree', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'revision' => $cur['revision'],
    'tree' => [['title' => '대시보드 시험', 'children' => [
        ['title' => '지난 태스크', 'est_md' => 5, 'difficulty' => 3,
         'plan_start' => '2026-01-05', 'plan_end' => '2026-02-01'],
        ['title' => '앞으로 태스크', 'est_md' => 3, 'difficulty' => 2,
         'plan_start' => '2026-05-01', 'plan_end' => '2099-12-31'],
    ]]],
]]);
ok('시험용 WBS 저장', $r['status'] === 200, substr($r['body'], 0, 120));
$leafQ = [];
$wq = function (array $ns) use (&$wq, &$leafQ): void {
    foreach ($ns as $n) { if (!$n['children']) { $leafQ[$n['title']] = $n['id']; } $wq($n['children']); }
};
$wq($r['json']['data']['tree']);
$admin->req('/studio/api/task.php?act=confirm',
            ['csrf' => true, 'json' => ['task_ids' => array_values($leafQ)]]);

$r = $admin->req('/studio/api/allocate.php?act=propose',
                 ['csrf' => true, 'json' => ['project_id' => $pid]]);
$aidQ = $r['json']['data']['allocation_id'];

// 시험 계정을 담당자로 바꿔 둔다 — 진행상황은 본인만 올릴 수 있다.
$myMid = null;
foreach ($admin->req('/studio/api/allocate.php?act=members&project_id=' . $pid)
                ['json']['data']['rows'] as $m) {
    if ($m['emp_name'] === '시험관리자') { $myMid = $m['id']; }
}
// 못 찾으면 여기서 말한다. 전에는 그냥 넘어가 60줄 뒤에 엉뚱한 403 과
// 치명적 오류로 터졌고, 원인을 찾는 데 한참 걸렸다.
ok('배정 가능한 시험관리자가 있다', $myMid !== null,
   'bs_member 에 없거나 is_assignable=0 이다');
$itemsQ = $admin->req('/studio/api/allocate.php?act=detail&allocation_id=' . $aidQ)
                ['json']['data']['items'];
if ($myMid) {
    $admin->req('/studio/api/allocate.php?act=update_item', ['csrf' => true, 'json' => [
        'item_id' => $itemsQ[0]['id'], 'member_id' => $myMid, 'manual_note' => '시험용']]);
}
$r = $admin->req('/studio/api/allocate.php?act=confirm',
                 ['csrf' => true, 'json' => ['allocation_id' => $aidQ]]);
if ($r['status'] === 409) {
    $r = $admin->req('/studio/api/allocate.php?act=confirm', ['csrf' => true, 'json' => [
        'allocation_id' => $aidQ, 'accept_overload' => 1]]);
}
ok('배정 확정', $r['status'] === 200, substr($r['body'], 0, 120));

// --- 확정 후 보드 -------------------------------------------------------
$r = $admin->req('/studio/api/dashboard.php?act=board&project_id=' . $pid);
$dq = $r['json']['data'];
ok('이제 배정안이 보인다', ($dq['allocation']['id'] ?? 0) === $aidQ);
$cards = array_sum(array_column($dq['columns'], 'count'));
ok('칸반에 카드 2건', $cards === 2, (string)$cards);
ok('담당자 카드가 있다', count($dq['by_member']) > 0);
ok('지연 1건(기한 지난 것)', count($dq['overdue']) === 1,
   json_encode(array_column($dq['overdue'], 'title'), JSON_UNESCAPED_UNICODE));
ok('지연은 며칠 늦었는지 함께', ($dq['overdue'][0]['overdue_days'] ?? 0) > 0);
ok('간트 막대 2개', count($dq['gantt']['bars']) === 2, (string)count($dq['gantt']['bars']));
ok('진척률이 계산된다', isset($dq['summary']['pct']));
ok('공수 미입력 건수를 함께 알려 준다', array_key_exists('no_est', $dq['summary']));

// 칸반 열은 명세서의 6개 + 보류
$keys = array_column($dq['columns'], 'key');
ok('칸반 열이 명세서대로',
   array_slice($keys, 0, 6) === ['todo', 'doing', 'review', 'dev_deployed', 'prod_deployed', 'done'],
   json_encode($keys));
ok('보류는 따로 끝에 둔다(버리지 않는다)', end($keys) === 'hold');

// --- 프로젝트 카드 -------------------------------------------------------
$r = $admin->req('/studio/api/dashboard.php?act=projects');
ok('카드 200', $r['status'] === 200);
$mine = null;
foreach ($r['json']['data']['rows'] as $c) { if ($c['id'] === $pid) { $mine = $c; } }
ok('이 프로젝트 카드가 있다', $mine !== null);
ok('D-day 를 준다', array_key_exists('dday', $mine));
ok('무엇을 기준으로 셌는지도', !empty($mine['dday_of']), json_encode($mine['dday_of']));
ok('단계 상태를 준다', isset($mine['stage']['dev']['state']));
ok('확정 배정안을 표시', ($mine['allocation']['id'] ?? 0) === $aidQ);
ok('지연 건수를 표시', ($mine['overdue'] ?? -1) === 1, (string)($mine['overdue'] ?? -1));

// --- 진행상황 (§7.2) -----------------------------------------------------
$myTask = $itemsQ[0]['task_id'];
$r = $admin->req('/studio/api/progress.php?act=task&task_id=' . $myTask);
ok('드로어 조회 200', $r['status'] === 200);
ok('본인 태스크면 올릴 수 있다', ($r['json']['data']['can_write'] ?? false) === true,
   $r['json']['data']['why'] ?? '');
ok('상태 목록을 함께 준다', count($r['json']['data']['statuses'] ?? []) === 7);

$r = $admin->req('/studio/api/progress.php?act=create', ['json' => ['task_id' => $myTask]]);
ok('CSRF 없으면 419', $r['status'] === 419);

$r = $admin->req('/studio/api/progress.php?act=create', ['csrf' => true, 'json' => [
    'task_id' => $myTask, 'status' => 'doing', 'progress_pct' => 40, 'content' => '절반쯤']]);
ok('등록 200', $r['status'] === 200, substr($r['body'], 0, 140));
ok('태스크 상태가 함께 바뀐다', ($r['json']['data']['task']['status'] ?? '') === 'doing');
ok('진행률도', ($r['json']['data']['task']['progress_pct'] ?? 0) === 40);
ok('보냈다고 말하지 않는다', str_contains($r['json']['data']['notify_notice'] ?? '', '적재만'));
ok('팀 채널로 한 건 접수', ($r['json']['data']['notify']['queued'] ?? 0) >= 1,
   json_encode($r['json']['data']['notify'] ?? null, JSON_UNESCAPED_UNICODE));

$r = $admin->req('/studio/api/progress.php?act=create', ['csrf' => true, 'json' => [
    'task_id' => $myTask, 'blocker' => 'API 스펙 대기', 'content' => '막힘']]);
ok('블로커 등록 200', $r['status'] === 200);
ok('PM 에게 따로 알린다고 말한다', str_contains($r['json']['data']['message'] ?? '', 'PM'));
$ch = array_column($r['json']['data']['notify']['detail'] ?? [], 'channel');
ok('알림이 2건(채널 + PM)', count($ch) === 2, json_encode($ch));

$r = $admin->req('/studio/api/progress.php?act=create', ['csrf' => true, 'json' => [
    'task_id' => $myTask]]);
ok('빈 등록은 400', $r['status'] === 400, substr($r['body'], 0, 100));
$r = $admin->req('/studio/api/progress.php?act=create', ['csrf' => true, 'json' => [
    'task_id' => $myTask, 'progress_pct' => 999]]);
ok('진행률 범위 밖 400', $r['status'] === 400);

// 본인 것이 아니면 못 올린다 — 이 화면의 핵심 규칙이다.
$r = $guest->req('/studio/api/progress.php?act=task&task_id=' . $myTask);
ok('남은 can_write=false', ($r['json']['data']['can_write'] ?? true) === false);
ok('왜 안 되는지 말해 준다', !empty($r['json']['data']['why']));
$r = $guest->req('/studio/api/progress.php?act=create', ['csrf' => true, 'json' => [
    'task_id' => $myTask, 'progress_pct' => 10]]);
ok('남의 태스크에 등록하면 403', $r['status'] === 403, 'status=' . $r['status']);

// --- 댓글 ---------------------------------------------------------------
$rows = $admin->req('/studio/api/progress.php?act=list&task_id=' . $myTask)['json']['data']['rows'];
ok('기록 목록에 댓글 칸이 있다', array_key_exists('comments', $rows[0]));
$pgQ = $rows[0]['id'];

$r = $admin->req('/studio/api/progress.php?act=comment',
                 ['csrf' => true, 'json' => ['progress_id' => $pgQ, 'content' => '확인했습니다']]);
ok('댓글 200', $r['status'] === 200);
ok('댓글이 붙는다', count($r['json']['data']['comments']) === 1);

// 댓글은 본인 태스크가 아니어도 단다 — PM 과 동료가 묻고 답하는 자리다.
$r = $guest->req('/studio/api/progress.php?act=comment',
                 ['csrf' => true, 'json' => ['progress_id' => $pgQ, 'content' => '남이 다는 댓글']]);
ok('남도 댓글은 달 수 있다', $r['status'] === 200, 'status=' . $r['status']);

$r = $admin->req('/studio/api/progress.php?act=comment',
                 ['csrf' => true, 'json' => ['progress_id' => $pgQ, 'content' => '   ']]);
ok('빈 댓글은 400', $r['status'] === 400);

$cmts = $admin->req('/studio/api/progress.php?act=list&task_id=' . $myTask)
              ['json']['data']['rows'][0]['comments'];
$otherCm = null;
foreach ($cmts as $c) { if ($c['user_name'] !== '시험관리자') { $otherCm = $c['id']; } }
if ($otherCm) {
    $r = $guest->req('/studio/api/progress.php?act=delete_comment',
                     ['csrf' => true, 'json' => ['id' => $otherCm]]);
    ok('본인 댓글은 지울 수 있다', $r['status'] === 200, 'status=' . $r['status']);
}

// --- 내가 맡은 일 ---------------------------------------------------------
$r = $admin->req('/studio/api/dashboard.php?act=mine');
ok('내 일 200', $r['status'] === 200);
ok('맡은 태스크가 나온다', count($r['json']['data']['rows']) >= 1,
   (string)count($r['json']['data']['rows']));
$mineRow = null;
foreach ($r['json']['data']['rows'] as $x) { if ($x['task_id'] === $myTask) { $mineRow = $x; } }
ok('내 태스크가 들어 있다', $mineRow !== null);
ok('지연 여부를 표시', array_key_exists('overdue', $mineRow ?? []));
ok('마지막 진행 기록을 함께', array_key_exists('last', $mineRow ?? []));

$r = $guest->req('/studio/api/dashboard.php?act=mine');
ok('구성원이 아니면 빈 목록 + 설명', $r['status'] === 200
   && count($r['json']['data']['rows']) === 0,
   substr($r['body'], 0, 120));

// --- 피드에 반영 -----------------------------------------------------------
$r = $admin->req('/studio/api/progress.php?act=feed&project_id=' . $pid);
ok('피드 200', $r['status'] === 200);
ok('올린 것이 피드에 있다', count($r['json']['data']['rows']) >= 2,
   (string)count($r['json']['data']['rows']));

$r = $admin->req('/studio/api/dashboard.php?act=nope');
ok('모르는 act 400', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'UNKNOWN_ACT');
$r = $admin->req('/studio/api/progress.php?act=nope');
ok('진행 API 도 모르는 act 400', $r['status'] === 400);


// =====================================================================
section('[R] 직접 등록한 점유 — 슬랙·메일에 없는 업무');

// 시험용 구성원. 시험 계정 자신이 구성원이어야 '본인' 경로를 볼 수 있다.
$pdoR = (function () {
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $dbr = $cfg['db'];
    return new PDO(
        "mysql:host={$dbr['host']};port={$dbr['port']};dbname={$dbr['name']};charset=utf8mb4",
        $dbr['user'], $dbr['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
})();
$pdoR->exec("DELETE FROM bs_workload WHERE kind = 'manual'");
// 구성원은 ensure_test_accounts() 가 시작할 때 이미 만들어 두었다.
$selfMid  = (int)$pdoR->query("SELECT id FROM bs_member WHERE user_id='batest-user@bluesoft.co.kr'")
                      ->fetchColumn();
$otherMid = (int)$pdoR->query("SELECT id FROM bs_member WHERE user_id='batest-admin@bluesoft.co.kr'")
                      ->fetchColumn();

$wlBase = ['label' => '상주 지원', 'note' => '4월 한 달 상주 확정',
           'start_date' => '2026-04-01', 'end_date' => '2026-04-30'];

$r = $anon->req('/studio/api/workload.php?act=list&member_id=' . $otherMid);
ok('로그인 없이 401', $r['status'] === 401, 'status=' . $r['status']);

$r = $admin->req('/studio/api/workload.php?act=list');
ok('member_id 없으면 400', $r['status'] === 400);
$r = $admin->req('/studio/api/workload.php?act=list&member_id=99999999');
ok('없는 구성원 404', $r['status'] === 404);

// --- 등록 -------------------------------------------------------------
$r = $admin->req('/studio/api/workload.php?act=create',
                 ['json' => ['member_id' => $otherMid] + $wlBase]);
ok('CSRF 없으면 419', $r['status'] === 419, 'status=' . $r['status']);

$r = $admin->req('/studio/api/workload.php?act=create', ['csrf' => true, 'json' =>
    ['member_id' => $otherMid, 'label' => '상주 지원',
     'start_date' => '2026-04-01', 'end_date' => '2026-04-30']]);
ok('사유 없이는 못 넣는다', $r['status'] === 400
   && str_contains($r['json']['error']['message'] ?? '', '사유'),
   substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/workload.php?act=create', ['csrf' => true, 'json' =>
    ['member_id' => $otherMid, 'note' => '사유만 있음',
     'start_date' => '2026-04-01', 'end_date' => '2026-04-30']]);
ok('제목 없이도 못 넣는다', $r['status'] === 400);

foreach ([
    ['기간이 뒤집히면 거절', ['start_date' => '2026-05-01', 'end_date' => '2026-04-01']],
    ['없는 날짜 거절',       ['start_date' => '2026-02-30', 'end_date' => '2026-04-01']],
    ['점유율 0 거절',        ['load_pct' => 0]],
    ['점유율 150 거절',      ['load_pct' => 150]],
    ['모르는 출처 거절',      ['source' => 'zzz']],
    ['javascript 링크 거절',  ['source_url' => 'javascript:alert(1)']],
] as [$what, $bad]) {
    $r = $admin->req('/studio/api/workload.php?act=create',
                     ['csrf' => true, 'json' => ['member_id' => $otherMid] + $bad + $wlBase]);
    ok($what, $r['status'] === 400, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 90));
}

$r = $admin->req('/studio/api/workload.php?act=create', ['csrf' => true, 'json' =>
    ['member_id' => $otherMid, 'source' => 'email',
     'source_url' => 'https://mail.example.com/x', 'load_pct' => 80,
     'from' => '2026-03-02', 'to' => '2026-06-22'] + $wlBase]);
ok('제대로 넣으면 200', $r['status'] === 200, substr($r['body'], 0, 140));
$wlId = $r['json']['data']['id'];
$row  = $r['json']['data']['rows'][0] ?? [];
ok('작성자가 남는다', ($row['created_by'] ?? '') === 'batest-admin@bluesoft.co.kr',
   json_encode($row['created_by'] ?? null));
ok('작성자 이름도', !empty($row['created_by_name']));
ok('출처가 남는다', ($row['source'] ?? '') === 'email' && !empty($row['source_label']));
ok('근거 링크도', !empty($row['source_url']));
ok('사유가 남는다', !empty($row['note']));
ok('가용도를 함께 돌려준다', isset($r['json']['data']['availability']['available_pct']));

// --- 가용도에 반영되는가 -------------------------------------------------
$before = null;
$after  = null;
foreach ($admin->req('/studio/api/candidate.php?act=list&project_id=1')
                ['json']['data']['rows'] as $x) {
    if ($x['member_id'] === $otherMid) { $after = $x['availability']; }
}
ok('후보 표의 가용도에 반영된다', $after !== null && $after['confirmed_pct'] > 0,
   json_encode($after));

$r = $admin->req('/studio/api/candidate.php?act=detail&project_id=1&member_id=' . $otherMid);
$manual = null;
foreach ($r['json']['data']['confirmed_breakdown'] as $w) {
    if (($w['kind'] ?? '') === 'manual') { $manual = $w; }
}
ok('드로어 내역에 직접 등록이 나온다', $manual !== null);
ok('배정과 구분해 표시한다', ($manual['kind_label'] ?? '') === '직접 등록',
   json_encode($manual['kind_label'] ?? null));
ok('누가 넣었는지 함께', !empty($manual['created_by_name']));
ok('사유도 함께', !empty($manual['note']));
ok('출처도 함께', !empty($manual['origin_label']));
ok('등록 단추를 그릴지 알려 준다',
   ($r['json']['data']['can_add_workload'] ?? null) === true);

// --- 배정이 만든 점유는 못 건드린다 ---------------------------------------
$asgnId = (int)$pdoR->query("SELECT id FROM bs_workload WHERE kind='assigned' LIMIT 1")
                    ->fetchColumn();
if ($asgnId) {
    $r = $admin->req('/studio/api/workload.php?act=delete',
                     ['csrf' => true, 'json' => ['id' => $asgnId]]);
    ok('배정이 만든 점유는 못 지운다', $r['status'] === 400
       && str_contains($r['json']['error']['message'] ?? '', '직접 등록'),
       substr($r['body'], 0, 120));
    $r = $admin->req('/studio/api/workload.php?act=update',
                     ['csrf' => true, 'json' => ['id' => $asgnId, 'label' => '바꿔보기']]);
    ok('배정이 만든 점유는 못 고친다', $r['status'] === 400);
}

// --- 권한 ---------------------------------------------------------------
$r = $guest->req('/studio/api/workload.php?act=list&member_id=' . $otherMid);
ok('PM 아닌 사람은 남의 것을 못 본다', $r['status'] === 403, 'status=' . $r['status']);

$r = $guest->req('/studio/api/workload.php?act=create',
                 ['csrf' => true, 'json' => ['member_id' => $otherMid] + $wlBase]);
ok('PM 아닌 사람은 남의 것을 못 넣는다', $r['status'] === 403);

$r = $guest->req('/studio/api/workload.php?act=delete',
                 ['csrf' => true, 'json' => ['id' => $wlId]]);
ok('PM 아닌 사람은 남의 것을 못 지운다', $r['status'] === 403);

// 등록 단추를 그릴지는 후보 상세가 알려 주는데, 이제 그 화면 자체가
// 배정 권한을 요구한다. 단추를 그릴 기회조차 없다는 것을 확인한다.
$r = $guest->req('/studio/api/candidate.php?act=detail&project_id=1&member_id=' . $otherMid);
ok('후보 상세를 아예 못 연다', $r['status'] === 403, 'status=' . $r['status']);

// 본인 것은 언제나 — 자기 가용도가 왜 그런지 알 권리가 있다
$r = $guest->req('/studio/api/workload.php?act=list&member_id=' . $selfMid);
ok('본인은 자기 것을 볼 수 있다', $r['status'] === 200, 'status=' . $r['status']);
ok('본인은 넣을 수도 있다', ($r['json']['data']['can_write'] ?? false) === true);

$r = $guest->req('/studio/api/workload.php?act=create', ['csrf' => true, 'json' =>
    ['member_id' => $selfMid, 'label' => '사내 교육', 'note' => '3일 교육',
     'source' => 'meeting', 'start_date' => '2026-04-01', 'end_date' => '2026-04-03']]);
ok('본인이 자기 것을 넣는다', $r['status'] === 200, substr($r['body'], 0, 120));
$selfWl = $r['json']['data']['id'];

$r = $guest->req('/studio/api/workload.php?act=delete',
                 ['csrf' => true, 'json' => ['id' => $selfWl]]);
ok('본인이 자기 것을 지운다', $r['status'] === 200);

// --- 고치기 -------------------------------------------------------------
$r = $admin->req('/studio/api/workload.php?act=update', ['csrf' => true, 'json' =>
    ['id' => $wlId, 'load_pct' => 40, 'note' => '절반으로 줄어 재조정',
     'from' => '2026-03-02', 'to' => '2026-06-22']]);
ok('점유율을 고친다', $r['status'] === 200, substr($r['body'], 0, 120));
ok('고친 값이 반영된다', ($r['json']['data']['rows'][0]['load_pct'] ?? 0) === 40,
   (string)($r['json']['data']['rows'][0]['load_pct'] ?? -1));

$r = $admin->req('/studio/api/workload.php?act=update',
                 ['csrf' => true, 'json' => ['id' => $wlId, 'note' => '   ']]);
ok('사유를 비울 수 없다', $r['status'] === 400,
   'status=' . $r['status'] . ' ' . substr($r['body'], 0, 90));

$r = $admin->req('/studio/api/workload.php?act=update',
                 ['csrf' => true, 'json' => ['id' => $wlId]]);
ok('바꿀 내용이 없으면 400', $r['status'] === 400);

$r = $admin->req('/studio/api/workload.php?act=update',
                 ['csrf' => true, 'json' => ['id' => 99999999, 'label' => 'x']]);
ok('없는 기록 404', $r['status'] === 404);

// --- 지우면 가용도가 돌아온다 ----------------------------------------------
$avBefore = $admin->req('/studio/api/workload.php?act=list&member_id=' . $otherMid
                        . '&from=2026-03-02&to=2026-06-22')
                  ['json']['data']['availability']['available_pct'];
$r = $admin->req('/studio/api/workload.php?act=delete', ['csrf' => true, 'json' =>
    ['id' => $wlId, 'from' => '2026-03-02', 'to' => '2026-06-22']]);
ok('지우기 200', $r['status'] === 200);
ok('가용도가 돌아온다', ($r['json']['data']['availability']['available_pct'] ?? 0) > $avBefore,
   $avBefore . ' → ' . ($r['json']['data']['availability']['available_pct'] ?? '?'));
ok('목록에서도 사라진다', count($r['json']['data']['rows']) === 0);

$r = $admin->req('/studio/api/workload.php?act=nope&member_id=' . $otherMid);
ok('모르는 act 400', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'UNKNOWN_ACT');

// 이 시험이 만든 배정안과 점유 기록을 치운다.
//
// 프로젝트 삭제는 소프트 삭제라 bs_workload 의 assigned 기록이 남는다.
// 그대로 두면 **다음 회차의 가용도가 조금씩 깎여** 후보 시험이 흔들린다.
// 실제로 그렇게 새서 후보 구간의 가용도 단언이 깨졌다.
(function () use ($pid) {
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $dbc = $cfg['db'];
    $p = new PDO(
        "mysql:host={$dbc['host']};port={$dbc['port']};dbname={$dbc['name']};charset=utf8mb4",
        $dbc['user'], $dbc['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->prepare(
        "DELETE w FROM bs_workload w
           JOIN bs_allocation_item i ON i.id = w.ref_id
           JOIN bs_task t            ON t.id = i.task_id
          WHERE w.kind = 'assigned' AND w.ref_type = 'allocation_item' AND t.project_id = ?"
    )->execute([$pid]);
    $p->prepare('DELETE FROM bs_allocation WHERE project_id = ?')->execute([$pid]);
    $p->prepare("DELETE FROM bs_notification WHERE ref_type = 'allocation'")->execute();
    // [R] 이 넣은 직접 등록 점유도 치운다. 남으면 다음 회차의 가용도가
    // 깎인 채로 시작해 후보 구간이 흔들린다.
    $p->prepare("DELETE FROM bs_workload WHERE kind = 'manual'")->execute();

    // 시험용 구성원을 후보 명단에서 내린다.
    //
    // bs_member 행 자체는 남긴다 — 9개 표가 이 행을 참조하고 있어 지우려면
    // 그 표들까지 건드려야 하는데, 뒷정리가 그렇게까지 할 일은 아니다.
    // 대신 배정 대상에서 빼 두면 데모 화면의 후보 표에 섞이지 않고,
    // 다음 회차에 누가 배정을 돌려도 이 유령이 일을 받지 않는다.
    // [R] 이 시작할 때 필요한 만큼 다시 올린다.
    $p->prepare("UPDATE bs_member SET is_assignable = 0 WHERE user_id LIKE 'batest-%'")
      ->execute();
})();

// =====================================================================
section('[S] 대시보드 통합 뷰 (P11)');

$r = $anon->req('/studio/api/dashboard.php?act=overview');
ok('미로그인은 401', $r['status'] === 401, 'status=' . $r['status']);

$r = $admin->req('/studio/api/dashboard.php?act=overview');
ok('통합 뷰 머리 200', $r['status'] === 200, substr($r['body'], 0, 160));
$d = $r['json']['data'] ?? [];

ok('조직 지표 세 가지가 온다',
   isset($d['org']['project_running'], $d['org']['rnd_running'], $d['org']['output_quarter']),
   json_encode($d['org'] ?? null));
ok('분기 시작일을 함께 준다 — 화면이 기간을 지어내지 않게',
   !empty($d['org']['quarter_from']));

ok('내 점유 구성이 온다', isset($d['me']['donut']), json_encode(array_keys($d['me'] ?? [])));
$dn = $d['me']['donut'] ?? [];
ok('도넛이 네 조각으로 나뉜다 (합산 하나로 주지 않는다)',
   array_key_exists('project_pct', $dn) && array_key_exists('rnd_pct', $dn)
   && array_key_exists('inferred_pct', $dn) && array_key_exists('available_pct', $dn),
   json_encode(array_keys($dn)));
ok('네 조각의 합이 기준 근무량과 같다',
   ($dn['project_pct'] + $dn['rnd_pct'] + $dn['inferred_pct'] + $dn['available_pct'])
   === $dn['capacity_pct'],
   sprintf('%d+%d+%d+%d vs %d', $dn['project_pct'], $dn['rnd_pct'],
           $dn['inferred_pct'], $dn['available_pct'], $dn['capacity_pct']));
ok('정체 과제 경고 자리가 온다', array_key_exists('stale', $d['me'] ?? []));
ok('기간을 함께 준다', !empty($d['me']['period']['from']) && !empty($d['me']['period']['to']));

// 통합 뷰는 mine 응답에 R&D 를 함께 싣는다.
$r = $admin->req('/studio/api/dashboard.php?act=mine');
ok('mine 이 R&D 과제를 함께 돌려준다', array_key_exists('rnd', $r['json']['data'] ?? []),
   json_encode(array_keys($r['json']['data'] ?? [])));

// 대시보드는 역량 점수를 내보내지 않는다 — 점유와 진행 상황만 본다.
ok('대시보드 응답에 역량 점수가 없다',
   !str_contains($r['body'], 'cap_score') && !str_contains($r['body'], 'breadth_score'),
   '점수 칸이 샜다');

// ---------------------------------------------------------------------
section('[T] 구성원 목록 화면');

// 이 화면은 서버에서 직접 그린다(API 가 없다). 그래서 HTML 을 받아 본다.
$r = $anon->req('/studio/member_list.php');
ok('미로그인은 못 본다', $r['status'] === 302, '상태 ' . $r['status']);

$r = $admin->req('/studio/member_list.php');
ok('관리자가 연다', $r['status'] === 200, '상태 ' . $r['status']);

// 뼈대가 남아 있으면 바로 여기서 걸린다. 이 화면은 P3~P4 내내 자리만
// 잡아 둔 채였고, 그 사실이 배포 전 점검에서 드러났다.
ok('자리표시(placeholder)가 아니다',
   !str_contains($r['body'], 'ba-placeholder'), '아직 뼈대다');
ok('표를 그린다', str_contains($r['body'], '주로 해 온 분야'));
ok('구성원 이름이 나온다', str_contains($r['body'], '시험사용자') || str_contains($r['body'], '시험관리자'),
   '이름이 하나도 없다');
ok('본인 프로파일 입구가 있다', str_contains($r['body'], 'member_profile.php"'),
   '내 프로파일 링크가 없다');

// ┌──────────────────────────────────────────────────────────────────┐
// │ CLAUDE.md — "구성원 간 종합점수 전체 랭킹 화면을 만들지 않는다"   │
// │ 점수 열이나 점수 정렬이 생기면 여기서 막는다.                     │
// └──────────────────────────────────────────────────────────────────┘
ok('종합점수가 화면에 없다',
   !str_contains($r['body'], 'cap_score') && !str_contains($r['body'], 'breadth_score'),
   '점수 칸이 샜다');
ok('점수로 정렬하는 길이 없다',
   !str_contains($r['body'], 'sort=score') && !str_contains($r['body'], 'order=cap'),
   '점수 정렬이 생겼다');

// 가용도는 합산 숫자만 보여 주면 안 된다 (P10-2).
ok('가용도를 분해해 적는다',
   str_contains($r['body'], '프로젝트 ') && str_contains($r['body'], '추정 '),
   '합산만 보여 준다');

// 거르개가 실제로 걸러야 한다. 없는 팀을 주면 아무도 안 나온다.
$r2 = $admin->req('/studio/member_list.php?team=' . rawurlencode('없는팀__' . mt_rand()));
ok('조건이 맞지 않으면 빈 목록', str_contains($r2['body'], '조건에 맞는 구성원이 없습니다'));

// 키워드에 LIKE 메타문자를 넣어도 전체 조회가 되지 않아야 한다.
$all  = substr_count($r['body'], 'member_profile.php?member_id=');
$pcts = $admin->req('/studio/member_list.php?keyword=%25');
$some = substr_count($pcts['body'], 'member_profile.php?member_id=');
ok('키워드의 % 가 와일드카드로 새지 않는다', $some < $all || $all === 0,
   "전체 $all / '%' 조회 $some");

// ---------------------------------------------------------------------
// 구성원 가져오기
//
// 이 표가 비면 후보·배정·역량이 전부 멈춘다. 그런데 채우는 길이 코드
// 안에만 있고(syncFromPortalUsers 가 빈 TODO 였다) 화면에는 없었다.
// 화면은 "돌려야 합니다" 라 적어 두고 돌릴 방법을 주지 않았다.
// ---------------------------------------------------------------------
ok('관리자에게 가져오기 단추가 보인다', str_contains($r['body'], 'ba-m-sync'));

$r4 = $anon->req('/studio/api/member.php?act=sync', ['json' => ['_' => 1]]);
ok('미로그인은 못 돌린다', $r4['status'] === 401, '상태 ' . $r4['status']);

$r4 = $guest->req('/studio/api/member.php?act=sync', ['csrf' => true, 'json' => ['_' => 1]]);
ok('일반 사용자는 못 돌린다', $r4['status'] === 403, '상태 ' . $r4['status']);

$r4 = $admin->req('/studio/api/member.php?act=sync', ['json' => ['_' => 1]]);
ok('CSRF 없이는 못 돌린다', $r4['status'] === 419, '상태 ' . $r4['status']);

$r4 = $admin->req('/studio/api/member.php?act=sync');
ok('GET 으로는 못 돌린다', $r4['status'] === 405, '상태 ' . $r4['status']);

// 지금 상태를 적어 두고 돌린다. 시험 계정 둘은 이미 구성원이라 다시
// 넣으면 안 된다 — 같은 이메일로 두 줄이 생기면 가용도가 쪼개진다.
$before = (int)$pdoR->query('SELECT COUNT(*) FROM bs_member')->fetchColumn();
$keep   = $pdoR->query(
    "SELECT is_evaluable, eval_exclude_reason FROM bs_member
      WHERE user_id = 'batest-user@bluesoft.co.kr'"
)->fetch(PDO::FETCH_ASSOC);

$r4 = $admin->req('/studio/api/member.php?act=sync', ['csrf' => true, 'json' => ['_' => 1]]);
ok('관리자가 돌린다', $r4['status'] === 200, $r4['body']);
$sync = $r4['json']['data'] ?? [];
ok('결과를 사람이 읽을 문장으로 준다', ($sync['message'] ?? '') !== '');
ok('전체 인원을 돌려준다', ($sync['total'] ?? 0) > 0, json_encode($sync));

$after = (int)$pdoR->query('SELECT COUNT(*) FROM bs_member')->fetchColumn();
ok('포털 사용자를 가져온다', $after >= $before, "$before → $after");

// 두 번 돌려도 늘지 않아야 한다. 같은 이메일로 줄이 겹치면 그 사람의
// 가용도와 배정이 두 쪽으로 갈린다.
$r4 = $admin->req('/studio/api/member.php?act=sync', ['csrf' => true, 'json' => ['_' => 1]]);
$again = (int)$pdoR->query('SELECT COUNT(*) FROM bs_member')->fetchColumn();
ok('다시 돌려도 늘지 않는다', $again === $after, "$after → $again");
ok('두 번째는 새로 넣은 것이 없다', (int)($r4['json']['data']['added'] ?? -1) === 0);

// 중복 이메일이 없어야 한다.
$dup = (int)$pdoR->query(
    'SELECT COUNT(*) FROM (SELECT user_id FROM bs_member
      GROUP BY user_id HAVING COUNT(*) > 1) d'
)->fetchColumn();
ok('같은 이메일로 두 줄이 생기지 않는다', $dup === 0, "$dup 건");

// ┌──────────────────────────────────────────────────────────────────┐
// │ is_evaluable 은 사람이 손으로 정한 값이다. 동기화가 돌 때마다 1 로│
// │ 되돌리면 평가 제외가 조용히 풀린다 (CLAUDE.md).                   │
// └──────────────────────────────────────────────────────────────────┘
$nowKeep = $pdoR->query(
    "SELECT is_evaluable, eval_exclude_reason FROM bs_member
      WHERE user_id = 'batest-user@bluesoft.co.kr'"
)->fetch(PDO::FETCH_ASSOC);
ok('is_evaluable 을 건드리지 않는다', $nowKeep == $keep,
   json_encode([$keep, $nowKeep], JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------------
// 배정 제외
//
// 개발 사업과 무관한 직무(경영지원 등)를 후보에서 뺀다. 스키마는 처음부터
// is_assignable 로 그 길을 열어 뒀지만 사람이 바꿀 수단이 없었다.
// ---------------------------------------------------------------------
// 시험 계정은 둘 다 is_assignable=0 이다 — 시험사용자는 처음부터 그렇고
// (배정 유령 방지), 시험관리자는 [S] 앞의 뒷정리가 내려 둔다.
// 여기서는 **빼고 넣는 것**을 재야 하므로 한 명을 올려 두고 시작한다.
// 끝나면 뒷정리가 의도한 상태(0)로 되돌린다.
$exMid = (int)$pdoR->query(
    "SELECT id FROM bs_member WHERE user_id = 'batest-admin@bluesoft.co.kr'"
)->fetchColumn();
$pdoR->exec("UPDATE bs_member SET is_assignable = 1 WHERE id = $exMid");
ok('대상을 배정 가능으로 세웠다',
   (int)$pdoR->query("SELECT is_assignable FROM bs_member WHERE id = $exMid")->fetchColumn() === 1);

$r6 = $guest->req('/studio/api/member.php?act=set_assignable',
    ['csrf' => true, 'json' => ['member_id' => $exMid, 'assignable' => 0]]);
ok('일반 사용자는 배정 대상을 못 바꾼다', $r6['status'] === 403, '상태 ' . $r6['status']);

$r6 = $admin->req('/studio/api/member.php?act=set_assignable',
    ['csrf' => true, 'json' => ['member_id' => 0, 'assignable' => 0]]);
ok('구성원을 안 주면 거절', $r6['status'] === 400, '상태 ' . $r6['status']);

$r6 = $admin->req('/studio/api/member.php?act=set_assignable',
    ['csrf' => true, 'json' => ['member_id' => $exMid, 'assignable' => 7]]);
ok('0/1 아닌 값은 거절', $r6['status'] === 400, '상태 ' . $r6['status']);

// 빼기 전 후보 수를 센다.
$candBefore = count($admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid)
                     ['json']['data']['rows'] ?? []);

$r6 = $admin->req('/studio/api/member.php?act=set_assignable',
    ['csrf' => true, 'json' => ['member_id' => $exMid, 'assignable' => 0]]);
ok('관리자가 배정에서 뺀다', $r6['status'] === 200, $r6['body']);
ok('무엇이 바뀌는지 알려 준다',
   str_contains($r6['json']['data']['message'] ?? '', '역량 점수는'), $r6['body']);

// ┌──────────────────────────────────────────────────────────────────┐
// │ 뺀 사람이 **후보 리스트에서 실제로 빠져야** 한다.                 │
// │ 전에는 후보 쿼리가 is_evaluable 로 걸러서, 배정에서 빼 둔 사람이  │
// │ 그대로 나오고 점수만 못 내는 기획 담당자가 사라졌다.              │
// └──────────────────────────────────────────────────────────────────┘
$cand = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid)
          ['json']['data']['rows'] ?? [];
$ids  = array_column($cand, 'member_id');
ok('뺀 사람이 후보에서 사라진다', !in_array($exMid, $ids, true),
   '여전히 후보에 있다');
ok('나머지는 그대로 남는다', count($cand) === $candBefore - 1,
   "$candBefore → " . count($cand));

// 행을 지우지 않는다 — 과거 배정 기록의 주인이다.
$still = (int)$pdoR->query("SELECT COUNT(*) FROM bs_member WHERE id = $exMid")->fetchColumn();
ok('행을 지우지 않는다', $still === 1);

// 되돌리면 다시 나온다.
$r6 = $admin->req('/studio/api/member.php?act=set_assignable',
    ['csrf' => true, 'json' => ['member_id' => $exMid, 'assignable' => 1]]);
ok('되돌릴 수 있다', $r6['status'] === 200, $r6['body']);
$cand = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid)
          ['json']['data']['rows'] ?? [];
ok('되돌리면 후보에 다시 나온다',
   in_array($exMid, array_column($cand, 'member_id'), true));

// 평가 제외는 **다른 축**이다. 점수를 못 내도 배정 후보에는 남아야 한다
// (기획 담당자 — 001_schema.sql 의 is_evaluable 주석).
$pdoR->exec("UPDATE bs_member SET is_evaluable = 0 WHERE id = $exMid");
$cand = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pid)
          ['json']['data']['rows'] ?? [];
$row  = null;
foreach ($cand as $c) { if ((int)$c['member_id'] === $exMid) { $row = $c; break; } }
ok('평가 제외자도 후보에는 남는다', $row !== null, '후보에서 사라졌다');
ok('평가 제외를 표본 부족과 구분해 내려준다',
   $row !== null && ($row['evaluable'] ?? true) === false, json_encode($row));
$pdoR->exec("UPDATE bs_member SET is_evaluable = 1 WHERE id = $exMid");
// 뒷정리가 의도한 상태로 되돌린다 — 시험 계정이 다음 회차의 후보 표에
// 유령으로 섞이면 안 된다.
$pdoR->exec("UPDATE bs_member SET is_assignable = 0 WHERE user_id LIKE 'batest-%'");

// 돌린 뒤에는 목록에 사람이 보여야 한다.
$r5 = $admin->req('/studio/member_list.php');
ok('가져온 뒤 목록이 비어 있지 않다',
   !str_contains($r5['body'], '구성원이 없습니다'), '여전히 비었다');

// 일반 사용자도 목록은 본다. 다만 남의 프로파일 링크는 안 걸린다.
$r3 = $guest->req('/studio/member_list.php');
ok('일반 사용자도 목록은 본다', $r3['status'] === 200, '상태 ' . $r3['status']);
ok('일반 사용자에게 남의 프로파일 링크가 적다',
   substr_count($r3['body'], 'member_profile.php?member_id=')
     < substr_count($r['body'], 'member_profile.php?member_id='),
   '남의 프로파일 링크가 그대로 보인다');

// ---------------------------------------------------------------------
section('[U] 외부 연동 — 구글 드라이브 · 피그마');

// ┌──────────────────────────────────────────────────────────────────┐
// │ RemoteSource 는 **혼자서도 실려야 한다.**                         │
// │                                                                  │
// │ 설정 화면의 '읽어 보기'(api/integration.php)는 파서를 싣지 않고   │
// │ 들어온다. 그런데 RemoteSource 가 OfficeDocumentParser::MAX_CHARS  │
// │ 를 쓰면서 그 파일을 읽지 않아, 운영에서 피그마를 읽는 순간        │
// │ Class not found 로 터졌다. 화면에는 "처리 중 오류" 만 떴다.       │
// │                                                                  │
// │ 자기가 쓰는 것은 자기가 읽어야 한다. 그걸 여기서 막는다 —         │
// │ 부트스트랩 말고는 아무것도 안 실은 채로 불러 본다.                │
// └──────────────────────────────────────────────────────────────────┘
$probe = sys_get_temp_dir() . '/bs_remote_probe_' . getmypid() . '.php';
file_put_contents($probe, "<?php
"
    . 'require ' . var_export(dirname(__DIR__) . '/inc/bootstrap.php', true) . ";
"
    . 'require ' . var_export(dirname(__DIR__) . '/inc/service/RemoteSource.php', true) . ";
"
    . 'echo RemoteSource::identify("https://www.figma.com/design/AbCdEf123456/x")["id"];' . "
"
    . 'echo "|", OfficeDocumentParser::MAX_CHARS;' . "
");
$sub = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
@unlink($probe);
ok('RemoteSource 는 혼자서도 실린다 (의존 파일을 스스로 읽는다)',
   str_contains((string)$sub, 'AbCdEf123456|'), trim((string)$sub));

// 주소 알아보기는 네트워크를 타지 않는 순수 함수다. 여기서 촘촘히 막는다.
require_once dirname(__DIR__) . '/inc/service/RemoteSource.php';
$idCases = [
    ['https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOp/edit#gid=0', 'google', '1AbCdEfGhIjKlMnOp'],
    ['https://docs.google.com/document/d/1AbCdEfGhIjKlMnOp/edit',           'google', '1AbCdEfGhIjKlMnOp'],
    ['https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp/view?usp=drive_link','google', '1AbCdEfGhIjKlMnOp'],
    ['https://drive.google.com/open?id=1AbCdEfGhIjKlMnOp',                  'google', '1AbCdEfGhIjKlMnOp'],
    ['https://www.figma.com/design/AbCdEf123456/LXP',                       'figma',  'AbCdEf123456'],
    ['https://www.figma.com/file/AbCdEf123456/X?node-id=1',                 'figma',  'AbCdEf123456'],
];
foreach ($idCases as [$u, $p, $id]) {
    $hit = RemoteSource::identify($u);
    ok('주소에서 id 를 뽑는다: ' . parse_url($u, PHP_URL_HOST) . substr(parse_url($u, PHP_URL_PATH), 0, 14),
       $hit !== null && $hit['provider'] === $p && $hit['id'] === $id, json_encode($hit));
}

// ┌──────────────────────────────────────────────────────────────────┐
// │ 사용자가 준 주소로 서버가 그대로 나가면 사내 망이 사정권이 된다.  │
// │ 주소에서 **문서 id 만** 뽑아 정해진 API 로만 나가므로, 아래는     │
// │ 전부 "못 알아봄" 이어야 한다.                                     │
// └──────────────────────────────────────────────────────────────────┘
foreach ([
    'http://127.0.0.1:8099/api/login.php',
    'http://169.254.169.254/latest/meta-data/',
    'https://evil.example.com/docs.google.com/d/1AbCdEfGhIjKlMnOp',
    'file:///etc/passwd',
    'https://docs.google.com/',
    'https://notion.so/1AbCdEfGhIjKlMnOp',
] as $bad) {
    ok('사내·엉뚱한 주소를 집지 않는다: ' . mb_strimwidth($bad, 0, 44, '…'),
       RemoteSource::identify($bad) === null, json_encode(RemoteSource::identify($bad)));
}

// ┌──────────────────────────────────────────────────────────────────┐
// │ node-id 를 **반드시** 집어내야 한다                               │
// │                                                                  │
// │ IA 시트는 항목마다 그 항목의 기획 화면을 node-id 로 가리킨다 —    │
// │ 한 파일 안의 서로 다른 지점이다. 이걸 놓치면 수십 개 링크가 같은  │
// │ 파일 전체를 반복해 받아 429 에 걸리고, 결과가 전부 똑같아져       │
// │ 항목별 판정에 쓸 수 없게 된다.                                     │
// └──────────────────────────────────────────────────────────────────┘
foreach ([
    ['https://www.figma.com/design/AbCdEf123456/x?node-id=40006486-417499', '40006486:417499'],
    ['https://www.figma.com/design/AbCdEf123456/x?node-id=4000%3A417',       '4000:417'],
    ['https://www.figma.com/design/AbCdEf123456/x',                          ''],
    ['https://www.figma.com/design/AbCdEf123456/x?node-id=abc',              ''],
] as [$u, $want]) {
    $hit = RemoteSource::identify($u);
    ok('node-id 를 집어낸다: ' . ($want === '' ? '(없음)' : $want),
       (string)($hit['node'] ?? '!') === $want, json_encode($hit));
}

// 피그마가 자동으로 붙이는 이름은 버린다. 남겨 두면 WBS 초안이
// 'Frame 12' 로 가득 차 쓸 수 없게 된다.
$noiseFn = new ReflectionMethod('RemoteSource', 'isNoiseName');
$noiseFn->setAccessible(true);
$drop = ['Frame 12', 'Group 5', 'Rectangle 3', 'IMG_5203', 'Vector 2', 'Instance', 'Ellipse 1'];
$keep = ['출석부 - 목록', '01.Wireframe', 'COURSEMOS LXP Plan', '로그인 화면', 'Frame 작업 정의'];
$bad = [];
foreach ($drop as $n) { if (!$noiseFn->invoke(null, $n)) { $bad[] = "못 버림:$n"; } }
foreach ($keep as $n) { if ($noiseFn->invoke(null, $n))  { $bad[] = "잘못 버림:$n"; } }
ok('자동 생성 이름만 골라 버린다', $bad === [], implode(' · ', $bad));

// ---- 권한 ----
$r = $anon->req('/studio/api/integration.php?act=status');
ok('미로그인은 상태를 못 본다', $r['status'] === 401, '상태 ' . $r['status']);
$r = $guest->req('/studio/api/integration.php?act=status');
ok('일반 사용자는 상태를 못 본다', $r['status'] === 403, '상태 ' . $r['status']);
$r = $guest->req('/studio/api/integration.php?act=save_figma',
    ['csrf' => true, 'json' => ['token' => 'figd_x']]);
ok('일반 사용자는 저장도 못 한다', $r['status'] === 403, '상태 ' . $r['status']);
$r = $guest->req('/studio/settings.php');
ok('일반 사용자는 설정 화면에서 튕긴다', $r['status'] === 302, '상태 ' . $r['status']);

$r = $admin->req('/studio/api/integration.php?act=status');
ok('관리자는 상태를 본다', $r['status'] === 200, $r['body']);
$ig = $r['json']['data'] ?? [];
ok('리디렉션 주소를 만들어 준다',
   str_ends_with((string)($ig['redirect_uri'] ?? ''), '/studio/api/google_oauth.php'),
   (string)($ig['redirect_uri'] ?? ''));

// ---- 저장 ----
$r = $admin->req('/studio/api/integration.php?act=save_google',
    ['csrf' => true, 'json' => ['client_id' => '', 'client_secret' => '']]);
ok('클라이언트 ID 없이는 저장 못 한다', $r['status'] === 400, '상태 ' . $r['status']);

$r = $admin->req('/studio/api/integration.php?act=save_figma',
    ['csrf' => true, 'json' => ['token' => 'figd_SECRET_TOKEN_FOR_TEST']]);
ok('피그마 토큰을 저장한다', $r['status'] === 200, $r['body']);
ok('저장하면 바로 연결됨', ($r['json']['data']['figma']['connected'] ?? false) === true);

// ┌──────────────────────────────────────────────────────────────────┐
// │ 넣을 수는 있어도 꺼내 볼 수는 없다. 어떤 경로로도 비밀이 나오면   │
// │ 안 된다 — 응답에도, 화면 HTML 에도, DB 평문으로도.                │
// └──────────────────────────────────────────────────────────────────┘
ok('상태 응답에 토큰이 없다',
   !str_contains($admin->req('/studio/api/integration.php?act=status')['body'], 'figd_SECRET'));
ok('설정 화면 HTML 에 토큰이 없다',
   !str_contains($admin->req('/studio/settings.php')['body'], 'figd_SECRET'));
$plain = (int)$pdoR->query(
    "SELECT COUNT(*) FROM bs_integration WHERE secret_enc LIKE '%figd_SECRET%'"
)->fetchColumn();
ok('DB 에 평문으로 남지 않는다', $plain === 0, "$plain 건");

// ---- 확인 버튼 ----
$r = $admin->req('/studio/api/integration.php?act=test',
    ['csrf' => true, 'json' => ['url' => 'https://notion.so/abc']]);
ok('모르는 주소는 확인을 거절한다', $r['status'] === 400, '상태 ' . $r['status']);
$r = $admin->req('/studio/api/integration.php?act=test',
    ['csrf' => true, 'json' => ['url' => 'http://127.0.0.1:8099/api/login.php']]);
ok('사내 주소로는 나가지 않는다', $r['status'] === 400, '상태 ' . $r['status']);

// 구글은 동의 전이라 나가 보지도 않고 거절해야 한다.
$r = $admin->req('/studio/api/integration.php?act=test',
    ['csrf' => true, 'json' => ['url' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOp/edit']]);
ok('연결 전 구글은 나가기 전에 막는다',
   $r['status'] === 400 && str_contains((string)($r['json']['error']['message'] ?? ''), '연결되지'),
   $r['body']);

// ---- 연결 끊기 ----
$r = $admin->req('/studio/api/integration.php?act=disconnect',
    ['csrf' => true, 'json' => ['provider' => 'figma']]);
ok('연결을 끊는다', $r['status'] === 200 && ($r['json']['data']['figma']['connected'] ?? true) === false,
   $r['body']);
ok('끊어도 줄은 남는다 — 누가 언제 껐는지가 남아야 한다',
   (int)$pdoR->query("SELECT COUNT(*) FROM bs_integration WHERE provider='figma'")->fetchColumn() === 1);

$r = $admin->req('/studio/api/integration.php?act=disconnect',
    ['csrf' => true, 'json' => ['provider' => 'nope']]);
ok('모르는 연동은 거절', $r['status'] === 400, '상태 ' . $r['status']);

// ---- 링크 등록이 자격 정보에 따라 달라진다 ----
// 연결 전이면 '분석 안 함'(skip) 이다. '분석 대기' 로 두면 영영 오지 않을
// 것을 기다리게 된다.
$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'source_type' => 'link',
    'url' => 'https://www.figma.com/design/ZzTestKey9999/link-test',
    'title' => '연동 시험 링크',
]]);
$linkRow = $r['json']['data']['saved'][0] ?? [];
ok('연결 전 링크는 분석 안 함', ($linkRow['parse_status'] ?? '') === 'skip',
   $r['status'] . ' ' . json_encode($linkRow, JSON_UNESCAPED_UNICODE));

// 자격 정보를 넣으면 같은 주소가 '분석 대기' 로 들어온다.
$admin->req('/studio/api/integration.php?act=save_figma',
    ['csrf' => true, 'json' => ['token' => 'figd_SECRET_TOKEN_FOR_TEST']]);
$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'source_type' => 'link',
    'url' => 'https://www.figma.com/design/ZzTestKey9999/link-test-2',
    'title' => '연동 시험 링크 2',
]]);
$linkRow2 = $r['json']['data']['saved'][0] ?? [];
ok('연결 뒤 링크는 분석 대기', ($linkRow2['parse_status'] ?? '') === 'pending',
   $r['status'] . ' ' . json_encode($linkRow2, JSON_UNESCAPED_UNICODE));

// 읽을 수 없는 서비스는 연결 여부와 상관없이 분석 안 함이다.
$r = $admin->req('/studio/api/project.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $pid, 'source_type' => 'link',
    'url' => 'https://notion.so/ZzTestKey9999', 'title' => '노션 링크',
]]);
ok('모르는 서비스 링크는 그대로 분석 안 함',
   ($r['json']['data']['saved'][0]['parse_status'] ?? '') === 'skip', $r['body']);

// ---------------------------------------------------------------------
// 연동 켜고 끄기 · 쉬는 시각 · 사용량
//
// ┌──────────────────────────────────────────────────────────────────┐
// │ 2026-10-04 에 이것이 없어서 하루를 날렸다                         │
// │                                                                  │
// │ 피그마가 며칠짜리 호출 제한을 걸었는데 화면 어디에도 그 말이      │
// │ 없었다. 끄는 길도 없어서, 막힌 줄 모르고 크론이 밤새 두드렸다.    │
// └──────────────────────────────────────────────────────────────────┘
// ---------------------------------------------------------------------
$r = $guest->req('/studio/api/integration.php?act=set_enabled',
    ['csrf' => true, 'json' => ['provider' => 'figma', 'enabled' => '0']]);
ok('연동 켜고 끄기는 관리자만', $r['status'] === 403, '상태 ' . $r['status']);

$r = $admin->req('/studio/api/integration.php?act=set_enabled',
    ['csrf' => true, 'json' => ['provider' => 'figma', 'enabled' => '0']]);
ok('관리자는 연동을 끌 수 있다',
   $r['status'] === 200 && ($r['json']['data']['status']['enabled'] ?? true) === false, $r['body']);
// ★ 끄는 것과 끊는 것은 다르다. 끊으면 토큰이 날아가 피그마에서 다시
//   발급받아야 한다 — 그러면 아무도 끄지 않는다.
ok('★ 꺼도 토큰은 남는다',
   (int)$pdoR->query("SELECT COUNT(*) FROM bs_integration
                       WHERE provider='figma' AND secret_enc IS NOT NULL")->fetchColumn() === 1);
ok('꺼도 연결됨은 유지된다',
   ($r['json']['data']['status']['connected'] ?? false) === true, $r['body']);
ok('usable 만 거짓이 된다',
   ($r['json']['data']['status']['usable'] ?? true) === false, $r['body']);

$r = $admin->req('/studio/api/integration.php?act=test',
    ['csrf' => true, 'json' => ['url' => 'https://www.figma.com/design/ZzTestKey9999/x?node-id=1-2']]);
ok('★ 꺼 두면 바깥으로 나가지 않는다',
   $r['status'] === 400 && str_contains((string)($r['json']['error']['message'] ?? ''), '꺼 두었'),
   $r['body']);

$r = $admin->req('/studio/api/integration.php?act=set_enabled',
    ['csrf' => true, 'json' => ['provider' => 'figma', 'enabled' => '1']]);
ok('다시 켤 수 있다',
   ($r['json']['data']['status']['enabled'] ?? false) === true, $r['body']);

// ---- 쉬는 시각: 상대가 말한 시각까지는 호출을 만들지 않는다 ----
$pdoR->prepare("UPDATE bs_integration
                   SET cooldown_until = DATE_ADD(NOW(), INTERVAL 224862 SECOND),
                       cooldown_reason = '피그마가 2일 14시간 뒤에 다시 오라고 했습니다.'
                 WHERE provider = 'figma'")->execute();

$r = $admin->req('/studio/api/integration.php?act=test',
    ['csrf' => true, 'json' => ['url' => 'https://www.figma.com/design/ZzTestKey9999/x?node-id=1-2']]);
ok('★ 쉬는 중에는 바깥으로 나가지 않는다',
   $r['status'] === 400 && str_contains((string)($r['json']['error']['message'] ?? ''), '쉽니다'),
   $r['body']);
ok('남은 시간을 사람 말로 알려 준다',
   str_contains((string)($r['json']['error']['message'] ?? ''), '2일'), $r['body']);

$r = $admin->req('/studio/api/integration.php?act=status');
$fg = $r['json']['data']['figma'] ?? [];
ok('status 가 쉬는 시각을 알려 준다', (int)($fg['cooldown_left'] ?? 0) > 224000,
   json_encode($fg, JSON_UNESCAPED_UNICODE));
ok('사용량 묶음이 14칸이다', count($r['json']['data']['usage']['figma'] ?? []) === 14);

// ★ 설정 화면이 그 사실을 **눈에 보이게** 적어야 한다. API 에만 있고
//   화면에 없으면, 관리자는 또 서버 로그를 뒤지게 된다.
$page = $admin->req('/studio/settings.php')['body'];
ok('★ 설정 화면에 쉬는 중이라고 쓴다', str_contains($page, '쉬는 중'), '화면에 안 보임');
ok('설정 화면에 사용량 그래프가 뜬다', str_contains($page, 'ba-spark'), '그래프 없음');
ok('설정 화면에 켜고 끄는 단추가 있다', str_contains($page, 'data-ig-toggle'), '단추 없음');
ok('★ 설정 화면에도 토큰은 안 나온다', !str_contains($page, 'figd_SECRET'));

$r = $admin->req('/studio/api/integration.php?act=clear_cooldown',
    ['csrf' => true, 'json' => ['provider' => 'figma']]);
ok('쉬는 시각을 지울 수 있다',
   $r['status'] === 200 && (int)($r['json']['data']['status']['cooldown_left'] ?? 1) === 0, $r['body']);

// ---- 하루 상한 ----
$r = $admin->req('/studio/api/integration.php?act=set_cap',
    ['csrf' => true, 'json' => ['provider' => 'figma', 'cap' => '5']]);
ok('하루 상한을 건다', (int)($r['json']['data']['status']['daily_cap'] ?? 0) === 5, $r['body']);
$r = $admin->req('/studio/api/integration.php?act=set_cap',
    ['csrf' => true, 'json' => ['provider' => 'figma', 'cap' => '0']]);
ok('상한을 없앤다', (int)($r['json']['data']['status']['daily_cap'] ?? 1) === 0, $r['body']);

$pdoR->exec("DELETE FROM bs_api_usage");
$pdoR->exec("DELETE FROM bs_integration");

// ---------------------------------------------------------------------
section('[V] 링크 분석 — 찾기·큐·워커');

require_once dirname(__DIR__) . '/inc/service/LinkAnalyzer.php';
require_once dirname(__DIR__) . '/inc/repo/JobRepo.php';

// 출처 문서에 IA 시트 모양의 글자를 심는다. 엑셀 파서가 셀 링크를
// `글자 <주소>` 로 붙여 두므로 그 모양 그대로.
$iaText = "대분류	화면명	기획
"
        . "학습	출석부	기획안 <https://www.figma.com/design/TESTKEY1234/plan?node-id=1-2>
"
        . "학습	과제	설계 <https://docs.google.com/spreadsheets/d/TESTSHEET123/edit>
"
        . "공통	로그인	메모 https://notion.so/zz
"
        . "학습	출석부2	같은 화면 <https://www.figma.com/design/TESTKEY1234/plan?node-id=1-2>";
$pdoR->prepare(
    'INSERT INTO bs_project_source (project_id, kind, title, parsed_text, parse_status,
                                    uploaded_by, uploaded_by_name)
          VALUES (?, "xlsx", "IA 시트", ?, "ok", "t@t", "시험")'
)->execute([$pid, $iaText]);

// ---- 권한 ----
$r = $anon->req('/studio/api/analysis.php?act=status&project_id=' . $pid);
ok('미로그인은 못 본다', $r['status'] === 401, '상태 ' . $r['status']);
$r = $guest->req('/studio/api/analysis.php?act=scan',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('권한 없으면 못 찾는다', $r['status'] === 403, '상태 ' . $r['status']);

// ---- 찾기 ----
$r = $admin->req('/studio/api/analysis.php?act=scan',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('링크를 찾는다', $r['status'] === 200, $r['body']);
$d = $r['json']['data'] ?? [];
ok('같은 주소는 한 번만 담는다', ($d['total'] ?? 0) === 3,
   '담긴 수 ' . ($d['total'] ?? -1));
ok('읽을 수 있는 것은 대기로', ($d['count']['pending'] ?? 0) === 2, json_encode($d['count'] ?? []));
ok('읽을 수 없는 서비스는 건너뜀으로', ($d['count']['skip'] ?? 0) === 1, json_encode($d['count'] ?? []));

// ┌──────────────────────────────────────────────────────────────────┐
// │ 어느 항목의 링크인지가 남아야 한다. 이게 없으면 읽어 온 글이      │
// │ 어느 태스크 것인지 알 수 없다.                                     │
// └──────────────────────────────────────────────────────────────────┘
$ctx = '';
foreach (($d['links'] ?? []) as $l) {
    if (str_contains((string)$l['url'], 'TESTKEY1234')) { $ctx = (string)$l['context']; }
}
ok('그 주소가 있던 줄을 함께 담는다', str_contains($ctx, '출석부'), $ctx);

// 다시 찾아도 이미 담긴 것은 그대로다. 읽어 둔 내용이 날아가면 안 된다.
$r = $admin->req('/studio/api/analysis.php?act=scan',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('다시 찾아도 늘지 않는다', ($r['json']['data']['total'] ?? 0) === 3);

// ---- 큐 ----
$r = $admin->req('/studio/api/analysis.php?act=start',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('큐에 넣는다', $r['status'] === 200, $r['body']);
$job1 = $r['json']['data']['job']['id'] ?? 0;
ok('진행 중 작업을 알려 준다', $job1 > 0, json_encode($r['json']['data']['job'] ?? null));

// 두 사람이 동시에 눌러도 두 번 돌면 안 된다 — 같은 링크를 두 번 읽으면
// 상대 서비스의 호출 제한에 걸린다.
$r = $admin->req('/studio/api/analysis.php?act=start',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('두 번 눌러도 작업은 하나', (int)($r['json']['data']['job']['id'] ?? 0) === (int)$job1,
   $r['body']);

// ---- 워커 ----
// 자격 정보가 없으므로 전부 실패한다. 중요한 것은 **끝까지 돌고 사유가
// 남는가** 다. 조용히 멈추면 "왜 안 되지" 를 알 길이 없다.
$worker = shell_exec(escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');
ok('워커가 작업을 집어 간다', str_contains((string)$worker, '작업 #' . $job1 . ' 시작'), trim((string)$worker));

$r = $admin->req('/studio/api/analysis.php?act=status&project_id=' . $pid);
$d = $r['json']['data'] ?? [];
ok('워커가 끝까지 돈다', ($d['count']['pending'] ?? 1) === 0, json_encode($d['count'] ?? []));
ok('연결 전이라 실패로 남는다', ($d['count']['fail'] ?? 0) === 2, json_encode($d['count'] ?? []));
ok('끝난 뒤에는 진행 중 작업이 없다', ($d['job'] ?? null) === null, json_encode($d['job'] ?? null));

$why = '';
foreach (($d['links'] ?? []) as $l) {
    if ($l['status'] === 'fail') { $why = (string)$l['error']; break; }
}
ok('왜 실패했는지 사람 말로 남는다', str_contains($why, '연결') || str_contains($why, '등록'), $why);

// ---- 다시 읽기 ----
$r = $admin->req('/studio/api/analysis.php?act=retry',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('실패한 것만 되돌린다',
   ($r['json']['data']['count']['pending'] ?? 0) === 2
   && ($r['json']['data']['count']['skip'] ?? 0) === 1, $r['body']);

// ---------------------------------------------------------------------
// ┌──────────────────────────────────────────────────────────────────┐
// │ 쉬는 중인 것과 연결이 안 된 것은 **다르게 다뤄야 한다**           │
// │                                                                  │
// │  · 쉬는 중·꺼 둠 → 기다리면 풀린다. 대기로 **남겨 둔다**          │
// │  · 연결 안 됨    → 기다려도 안 풀린다. 실패로 **못 박는다**       │
// │                                                                  │
// │ 둘을 같게 다루면 한쪽이 반드시 망가진다. 쉬는 중을 실패로 박으면  │
// │ 제한이 풀린 뒤 사람이 수백 건을 손으로 되돌려야 하고, 연결 안 됨을│
// │ 대기로 두면 영영 오지 않을 것을 기다리게 된다.                    │
// └──────────────────────────────────────────────────────────────────┘
// ---------------------------------------------------------------------
$admin->req('/studio/api/integration.php?act=save_figma',
    ['csrf' => true, 'json' => ['token' => 'figd_SECRET_TOKEN_FOR_TEST']]);
$pdoR->exec("UPDATE bs_integration
                SET cooldown_until = DATE_ADD(NOW(), INTERVAL 224862 SECOND),
                    cooldown_reason = '시험용 쉼'
              WHERE provider = 'figma'");

$admin->req('/studio/api/analysis.php?act=start', ['csrf' => true, 'json' => ['project_id' => $pid]]);
$worker = shell_exec(escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');
ok('★ 쉬는 중이면 워커가 물러난다', str_contains((string)$worker, '물러남'), trim((string)$worker));

$d = $admin->req('/studio/api/analysis.php?act=status&project_id=' . $pid)['json']['data'] ?? [];
// ★ 피그마는 쉬는 중이라 대기로 남고, 구글은 연결이 안 돼 실패로 박힌다.
//   한 회차 안에서 둘이 서로 다르게 다뤄져야 한다.
$byStatus = [];
foreach (($d['links'] ?? []) as $l) { $byStatus[(string)$l['provider']] = (string)$l['status']; }
ok('★ 쉬는 중인 피그마 링크는 대기로 남는다 (실패로 박지 않는다)',
   ($byStatus['figma'] ?? '') === 'pending', json_encode($byStatus));
ok('★ 연결 안 된 구글 링크는 실패로 박힌다 (영영 기다리게 두지 않는다)',
   ($byStatus['google'] ?? '') === 'fail', json_encode($byStatus));
ok('★ 화면에 왜 멈췄는지 적힌다',
   str_contains((string)($d['job']['message'] ?? ''), '쉽니다'),
   (string)($d['job']['message'] ?? ''));
// 쓰지도 않을 연동의 사정까지 늘어놓으면 읽지 않는다 — **대기 건이 있는
// 연동만** 말한다. 구글은 실패로 끝났으므로 여기 안 나온다.
ok('막힌 연동만 화면에 알린다',
   count($d['blocked'] ?? []) === 1 && ($d['blocked'][0]['provider'] ?? '') === 'figma',
   json_encode($d['blocked'] ?? [], JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------------
// ┌──────────────────────────────────────────────────────────────────┐
// │ 안 쓰기로 한 연동의 대기 건 정리                                  │
// │                                                                  │
// │ 피그마를 안 쓰기로 했는데 대기 89건이 그대로 남아 큐를 매분 돌고  │
// │ 화면에도 띠가 계속 떴다. 그렇다고 '실패' 로 박으면 거짓이 된다 —  │
// │ 고장난 것이 아니라 **안 읽기로 한 것**이고, 실패로 보이면 누군가  │
// │ 고치려 든다.                                                      │
// └──────────────────────────────────────────────────────────────────┘
// ---------------------------------------------------------------------
$r = $guest->req('/studio/api/analysis.php?act=skip_provider',
    ['csrf' => true, 'json' => ['project_id' => $pid, 'provider' => 'figma']]);
ok('정리는 권한이 있어야', $r['status'] === 403, '상태 ' . $r['status']);

$r = $admin->req('/studio/api/analysis.php?act=skip_provider',
    ['csrf' => true, 'json' => ['project_id' => $pid, 'provider' => 'nope']]);
ok('모르는 연동은 거절', $r['status'] === 400, '상태 ' . $r['status']);

$was = $pdoR->query("SELECT provider, status FROM bs_source_link WHERE project_id = $pid")
            ->fetchAll(PDO::FETCH_ASSOC);
$figPending = count(array_filter($was,
    fn($x) => $x['provider'] === 'figma' && $x['status'] === 'pending'));
$gooBefore  = count(array_filter($was, fn($x) => $x['provider'] === 'google'));

$r = $admin->req('/studio/api/analysis.php?act=skip_provider',
    ['csrf' => true, 'json' => ['project_id' => $pid, 'provider' => 'figma']]);
ok('피그마 대기를 건너뜀으로 정리한다', $r['status'] === 200, $r['body']);

$now = $pdoR->query("SELECT provider, status, error FROM bs_source_link WHERE project_id = $pid")
            ->fetchAll(PDO::FETCH_ASSOC);
$figSkip = array_values(array_filter($now,
    fn($x) => $x['provider'] === 'figma' && $x['status'] === 'skip'));
ok('★ 실패가 아니라 건너뜀이다', count($figSkip) === $figPending && $figPending > 0,
   count($figSkip) . ' / ' . $figPending);
ok('★ 왜 안 읽는지 적어 둔다',
   str_contains((string)($figSkip[0]['error'] ?? ''), '쓰지 않기로'),
   (string)($figSkip[0]['error'] ?? ''));
// ★ 다른 연동을 건드리면 멀쩡한 링크가 조용히 사라진 것처럼 보인다.
ok('★ 다른 연동은 안 건드린다',
   count(array_filter($now, fn($x) => $x['provider'] === 'google')) === $gooBefore);

$d = $admin->req('/studio/api/analysis.php?act=status&project_id=' . $pid)['json']['data'] ?? [];
ok('정리하면 막힘 띠가 사라진다', ($d['blocked'] ?? []) === [],
   json_encode($d['blocked'] ?? [], JSON_UNESCAPED_UNICODE));
ok('되살릴 수 있다고 알려 준다',
   (int)($d['by_provider']['figma']['skip'] ?? 0) === $figPending,
   json_encode($d['by_provider'] ?? []));

// ---- 되돌리기 ----
$r = $admin->req('/studio/api/analysis.php?act=revive_provider',
    ['csrf' => true, 'json' => ['project_id' => $pid, 'provider' => 'figma']]);
ok('★ 되돌릴 수 있다 — 아무것도 잃지 않는다',
   (int)($r['json']['data']['by_provider']['figma']['pending'] ?? 0) === $figPending,
   json_encode($r['json']['data']['by_provider'] ?? []));
ok('되돌린 뒤 사유는 지워진다',
   (int)$pdoR->query("SELECT COUNT(*) FROM bs_source_link
                       WHERE project_id = $pid AND provider='figma' AND error IS NOT NULL")
             ->fetchColumn() === 0);

$admin->req('/studio/api/analysis.php?act=cancel', ['csrf' => true, 'json' => ['project_id' => $pid]]);
$pdoR->exec("DELETE FROM bs_api_usage");
$pdoR->exec("DELETE FROM bs_integration");

// ---------------------------------------------------------------------
// 난이도 판정 — 큐 → 워커 → bs_task
//
// AI 는 연결돼 있지 않다. **그래도 난이도가 채워져야 한다** — 규칙이
// 바탕으로 늘 돌기 때문이다. 이것이 'ⓐ+AI' 방침의 핵심이고, AI 가
// 막혔다고 WBS 가 난이도 없이 남으면 배정 엔진이 그 태스크를 못 다룬다.
// ---------------------------------------------------------------------
$pdoR->prepare('UPDATE bs_task SET difficulty = NULL, difficulty_by = NULL WHERE project_id = ?')
     ->execute([$pid]);

$r = $guest->req('/studio/api/analysis.php?act=score',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('난이도 판정은 권한이 있어야', $r['status'] === 403, '상태 ' . $r['status']);

$r = $admin->req('/studio/api/analysis.php?act=score',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('난이도 판정을 큐에 넣는다', $r['status'] === 200, $r['body']);
ok('★ AI 가 꺼져 있으면 그 사실을 미리 말한다',
   str_contains((string)($r['json']['data']['message'] ?? ''), 'AI 가 꺼져 있어'),
   (string)($r['json']['data']['message'] ?? ''));

$worker = shell_exec(escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');
// 로그 한 줄에 난이도·공수와 **각각 누가 매겼는지**가 같이 찍힌다.
//   ★4 (ai) · 3.5 M/D (ai) · 수강 신청
ok('워커가 난이도와 공수를 매긴다',
   str_contains((string)$worker, '★') && str_contains((string)$worker, 'M/D'),
   trim((string)$worker));

$rows = $pdoR->query("SELECT difficulty, difficulty_by, difficulty_note
                        FROM bs_task WHERE project_id = $pid
                         AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id = bs_task.id)")
             ->fetchAll(PDO::FETCH_ASSOC);
$filled = array_filter($rows, fn($x) => $x['difficulty'] !== null);
ok('★ AI 없이도 난이도가 전부 채워진다', count($filled) === count($rows) && $rows !== [],
   count($filled) . '/' . count($rows));
ok('규칙으로 매겼다고 남는다',
   count(array_filter($rows, fn($x) => $x['difficulty_by'] === 'rule')) === count($rows),
   json_encode(array_column($rows, 'difficulty_by')));
// ★ 근거 없는 숫자는 배정 근거로 못 쓴다.
ok('★ 왜 그 난이도인지 근거가 남는다',
   count(array_filter($rows, fn($x) => trim((string)$x['difficulty_note']) !== '')) === count($rows),
   json_encode(array_column($rows, 'difficulty_note'), JSON_UNESCAPED_UNICODE));
ok('1~5 를 벗어나지 않는다',
   count(array_filter($rows, fn($x) => (int)$x['difficulty'] >= 1 && (int)$x['difficulty'] <= 5))
   === count($rows));

$r = $admin->req('/studio/api/analysis.php?act=score',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('빈 것이 없으면 거절하고 길을 알려 준다',
   $r['status'] === 400 && str_contains((string)($r['json']['error']['message'] ?? ''), '다시 매기기'),
   $r['body']);

// ★ 사람이 고친 값은 자동 판정이 건드리지 않는다. 한 번이라도 덮어쓰면
//   아무도 고치지 않는다.
$one = (int)$pdoR->query("SELECT id FROM bs_task WHERE project_id = $pid
                           AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id = bs_task.id)
                           ORDER BY id LIMIT 1")->fetchColumn();
$pdoR->exec("UPDATE bs_task SET difficulty = 5, difficulty_by = 'human',
                                difficulty_note = '내가 봤다' WHERE id = $one");

$r = $admin->req('/studio/api/analysis.php?act=score',
    ['csrf' => true, 'json' => ['project_id' => $pid, 'redo' => '1']]);
ok('다시 매기기는 받아 준다', $r['status'] === 200, $r['body']);
shell_exec(escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');

$kept = $pdoR->query("SELECT difficulty, difficulty_by, difficulty_note, est_md, est_md_by
                        FROM bs_task WHERE id = $one")->fetch(PDO::FETCH_ASSOC);
ok('★ 사람이 매긴 값은 다시 매기기에도 그대로다',
   (int)$kept['difficulty'] === 5 && $kept['difficulty_by'] === 'human'
   && $kept['difficulty_note'] === '내가 봤다',
   json_encode($kept, JSON_UNESCAPED_UNICODE));
// ★ 난이도만 사람이 고쳐 둔 태스크라도 **공수는 채워져야 한다.** 행 단위로
//   "사람이 손댔나" 를 보면 그 공수가 영영 안 채워진다.
ok('★ 난이도를 사람이 고쳐도 공수는 채운다',
   $kept['est_md'] !== null && $kept['est_md_by'] === 'rule',
   json_encode($kept, JSON_UNESCAPED_UNICODE));

// 거꾸로 — 사람이 넣은 공수는 자동 판정이 덮지 않는다
$pdoR->exec("UPDATE bs_task SET est_md = 12.5, est_md_by = 'human',
                                est_md_note = '내가 넣었다' WHERE id = $one");
$admin->req('/studio/api/analysis.php?act=score',
    ['csrf' => true, 'json' => ['project_id' => $pid, 'redo' => '1']]);
shell_exec(escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');
$kept2 = $pdoR->query("SELECT est_md, est_md_by, est_md_note FROM bs_task WHERE id = $one")
              ->fetch(PDO::FETCH_ASSOC);
ok('★ 사람이 넣은 공수도 그대로다',
   (float)$kept2['est_md'] === 12.5 && $kept2['est_md_by'] === 'human'
   && $kept2['est_md_note'] === '내가 넣었다',
   json_encode($kept2, JSON_UNESCAPED_UNICODE));

// 근거 없는 숫자는 일정 근거로 못 쓴다
$ests = $pdoR->query("SELECT est_md, est_md_by, est_md_note FROM bs_task
                       WHERE project_id = $pid AND est_md_by IS NOT NULL
                         AND est_md_by <> 'human'")->fetchAll(PDO::FETCH_ASSOC);
ok('★ 자동으로 매긴 공수에는 근거가 늘 있다',
   $ests !== [] && count(array_filter($ests, fn($x) => trim((string)$x['est_md_note']) !== ''))
   === count($ests),
   json_encode(array_column($ests, 'est_md_note'), JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------------
// ┌──────────────────────────────────────────────────────────────────┐
// │ 막힌 작업이 뒤를 굶기면 안 된다 (2026-10-06)                      │
// │                                                                  │
// │ 피그마가 꺼진 링크 작업이 매분 집혔다가 즉시 물러나는데 id 는     │
// │ 그대로라 늘 큐 맨 앞이었다. 뒤에 넣은 난이도 작업은 영영 차례가   │
// │ 오지 않았고, 화면에는 '이미 진행 중입니다' 만 떴다.               │
// └──────────────────────────────────────────────────────────────────┘
// ---------------------------------------------------------------------
$pdoR->prepare('DELETE FROM bs_analysis_job WHERE project_id = ?')->execute([$pid]);

// 먼저 들어왔지만 **방금 물러난** 작업 (피그마가 꺼져 영영 못 도는 것)
$pdoR->prepare(
    'INSERT INTO bs_analysis_job (project_id, kind, status, total, created_at, finished_at, message)
     VALUES (?, "links", "queued", 9, DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW(), "막혀서 물러남")'
)->execute([$pid]);
$stuck = (int)$pdoR->lastInsertId();

// 나중에 들어왔고 **한 번도 안 돈** 작업
$pdoR->prepare(
    'INSERT INTO bs_analysis_job (project_id, kind, status, total, created_at)
     VALUES (?, "difficulty", "queued", 1, DATE_SUB(NOW(), INTERVAL 10 MINUTE))'
)->execute([$pid]);
$fresh = (int)$pdoR->lastInsertId();

$w = shell_exec(escapeshellarg(PHP_BINARY) . ' '
   . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');
ok('★ 방금 물러난 작업을 또 집지 않는다',
   !str_contains((string)$w, '작업 #' . $stuck . ' 시작'), trim((string)$w));
ok('★ 한 번도 안 돈 작업이 차례를 받는다',
   str_contains((string)$w, '작업 #' . $fresh . ' 시작'), trim((string)$w));

$pdoR->prepare('DELETE FROM bs_analysis_job WHERE project_id = ?')->execute([$pid]);

// 링크 목록은 드로어로 뺐다. 300건짜리 표가 늘 펼쳐져 있으면 바로 아래
// WBS 칸까지 내려가는 데만 한참 걸린다.
$page = $admin->req('/studio/project_view.php?id=' . $pid)['body'];
ok('★ 링크 목록이 드로어 안에 있다',
   str_contains($page, 'id="ba-lk-drawer"') && str_contains($page, 'id="ba-lk-table"'));
ok('상태별 단추 자리가 있다', str_contains($page, 'id="ba-lk-chips"'));
ok('드로어 안에 거르는 자리가 있다', str_contains($page, 'id="ba-lk-filter"'));
ok('★ 표가 본문에 펼쳐져 있지 않다',
   strpos($page, 'id="ba-lk-table"') > strpos($page, 'id="ba-lk-drawer"'),
   '표가 드로어 바깥에 있다');

// ┌──────────────────────────────────────────────────────────────────┐
// │ <td> 에 display:flex 를 주면 표가 깨진다 (2026-10-06)             │
// │                                                                  │
// │ 그 칸이 표 레이아웃에서 빠져 행 높이가 따로 놀고, 가로줄이 두     │
// │ 겹으로 갈라져 보였다. 늘어놓는 일은 안쪽 div 가 해야 한다.        │
// └──────────────────────────────────────────────────────────────────┘
$css = (string)@file_get_contents(dirname(__DIR__) . '/assets/assign.css');
$js  = (string)@file_get_contents(dirname(__DIR__) . '/assets/assign.js');
ok('★ WBS 표의 td 에 flex 를 주지 않는다',
   !preg_match('/td\.ba-wr__(title|pd)\s*\{[^}]*display:\s*flex/', $css),
   'td 에 flex 가 다시 들어왔다');
ok('늘어놓기는 안쪽 div 가 한다',
   str_contains($css, '.ba-wr__tin') && str_contains($css, '.ba-wr__pdin')
   && str_contains($js, 'ba-wr__tin') && str_contains($js, 'ba-wr__pdin'));
ok('★ 행 높이를 고정해 줄을 맞춘다',
   (bool)preg_match('/\.ba-wbs td\s*\{[^}]*height:/', $css), '행 높이 고정이 사라졌다');

// 가중치는 "올리면 무엇이 달라지는가" 를 한 줄로 알려 줘야 한다. 숫자만
// 다섯 개 늘어놓으면 무엇을 올릴지 판단할 근거가 없다.
ok('★ 배정 방식 고르는 자리가 있다',
   str_contains($page, 'name="ba-al-method"') && str_contains($page, 'value="random_even"')
   && str_contains($page, 'value="random_pure"'));
ok('씨앗 칸이 있다', str_contains($page, 'id="ba-al-seed"'));
// 슬라이더마다 붙은 한 줄 설명과 '전체 규칙' 문장이 맞붙으면 같은 종류의
// 말로 읽혀 둘 다 안 읽힌다. 줄을 그어 성격이 다르다는 것을 보여 준다.
ok('전체 규칙 문장을 항목 설명과 갈라 놓는다',
   str_contains($page, 'ba-wpanel__note')
   && (bool)preg_match('/\.ba-wpanel__note::before\s*\{[^}]*content/', $css),
   '표식이 사라졌다');

// ┌──────────────────────────────────────────────────────────────────┐
// │ WBS 접기 — 숨기는 일은 **그리는 자리에서만** 한다                 │
// │                                                                  │
// │ flat() 결과를 저장(toPayload)·합계(syncSummary)·이동·검증이 전부  │
// │ 쓴다. 거기서 줄을 빼면 **접어 둔 태스크가 저장에서 사라진다.**     │
// │ 105줄짜리 WBS 를 접어 두고 저장했다가 날리는 일은 돌이킬 수 없다. │
// └──────────────────────────────────────────────────────────────────┘
ok('접기 단추가 있다',
   str_contains($page, 'id="ba-w-fold"') && str_contains($page, 'id="ba-w-unfold"'));
ok('★ 접기는 그리는 자리에서만 한다',
   str_contains($js, 'visibleRows(flat(MODEL'), 'render 가 거르지 않는다');
ok('★ 합계는 접어도 전체를 센다',
   (bool)preg_match('/function syncSummary\(\)\s*\{\s*var rows = flat\(MODEL/', $js),
   '합계가 보이는 줄만 센다');
ok('★ 저장은 접어도 전체를 보낸다',
   (bool)preg_match('/toPayload\(MODEL\)/', $js), '저장이 보이는 줄만 보낸다');
ok('접은 상태는 태스크 id 로 기억한다 — key 는 다시 매겨진다',
   str_contains($js, 'bs.wbs.fold.') && str_contains($js, 'r.node.id && COLLAPSED'));

ok('★ 가중치 다섯 가지에 설명이 다 붙어 있다',
   (bool)preg_match('/W_HINT\s*=\s*\{(.+?)\};/s', $js, $m)
   && count(array_filter(['domain', 'cap', 'avail', 'career', 'growth'],
            fn($k) => str_contains($m[1], $k . ':'))) === 5,
   '빠진 항목이 있다');

$pdoR->prepare('DELETE FROM bs_analysis_job WHERE project_id = ?')->execute([$pid]);

// ---- 멈추기 ----
$admin->req('/studio/api/analysis.php?act=start', ['csrf' => true, 'json' => ['project_id' => $pid]]);
$r = $admin->req('/studio/api/analysis.php?act=cancel',
    ['csrf' => true, 'json' => ['project_id' => $pid]]);
ok('멈출 수 있다', ($r['json']['data']['job'] ?? null) === null, $r['body']);

$worker = shell_exec(escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/cron/analyze.php') . ' 2>&1');
ok('멈춘 뒤에는 워커가 집어갈 것이 없다', trim((string)$worker) === '', trim((string)$worker));

// 뒷정리
$pdoR->prepare('DELETE FROM bs_analysis_job WHERE project_id = ?')->execute([$pid]);
$pdoR->prepare('DELETE FROM bs_source_link WHERE project_id = ?')->execute([$pid]);

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
$admin->req('/studio/api/project.php?act=delete',
    ['csrf' => true, 'json' => ['id' => $pid, 'confirm' => $code, 'reason' => '시험 뒷정리']]);

echo "\n" . str_repeat('=', 62) . "\n";
echo "통과 $pass / 실패 $fail\n";
if ($failures) {
    echo "\n실패 목록:\n";
    foreach ($failures as $f) { echo "  · $f\n"; }
}
echo "\n";
exit($fail > 0 ? 1 : 0);

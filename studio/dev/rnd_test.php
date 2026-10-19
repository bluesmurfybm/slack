<?php
/** R&D 과제 시험 — 가시성 강제, 발의·수정 권한, 승인·반려 경로 (명세서 §8). */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 가시성은 **HTTP 로** 시험한다                                     │
   │                                                                  │
   │ "화면에서만 숨기지 마라"(명세서 §8.6)를 지켰는지 보려면, 화면을    │
   │ 거치지 않고 API 를 직접 불러 봐야 한다. Repo 를 클래스로 불러      │
   │ 시험하면 bs_current_user() 가 세션을 보므로 다른 사람인 척할 수    │
   │ 없다 — 그래서 로그인 세션 두 개를 띄워 서로 남의 과제를 노린다.    │
   │                                                                  │
   │ 먼저 서버를 띄워야 한다:  studio\dev\serve.bat                    │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('명령줄에서만 실행할 수 있습니다.');
}

$BASE = getenv('BS_TEST_BASE') ?: 'http://127.0.0.1:8099';

// api_test.php 와 **같은 계정·같은 비밀번호**를 쓴다. 사람이 쓰는 계정을
// 빌리지 않는다. 값이 서로 다르면 두 시험이 상대의 비밀번호를 덮어써서,
// 번갈아 돌릴 때 먼저 돌린 쪽이 401 로 깨진다.
const TEST_PASSWORD = 'ba-test-1234';
$ADMIN = 'batest-admin@bluesoft.co.kr';
$USER  = 'batest-user@bluesoft.co.kr';

// =====================================================================

final class RndClient
{
    private ?string $csrf = null;
    private string $jar;

    public function __construct(private string $base, string $tag)
    {
        $this->jar = sys_get_temp_dir() . "/bs_rnd_$tag.txt";
        @unlink($this->jar);
    }

    public function req(string $path, array $o = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $h = [];
        if (!empty($o['json'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'], JSON_UNESCAPED_UNICODE));
            $h[] = 'Content-Type: application/json';
        } elseif (!empty($o['form'])) {
            // 파일 올리기 (2026-10-19 — 참고자료·첨부)
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $o['form']);
        }
        if (!empty($o['csrf']) && $this->csrf !== null) {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($h) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            fwrite(STDERR, "\n[연결 실패] " . curl_error($ch)
                . "\n서버가 떠 있습니까?  studio\\dev\\serve.bat\n");
            exit(2);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string)$body,
                'json'   => json_decode((string)$body, true)];
    }

    public function login(string $email): bool
    {
        $this->req('/api/login.php', ['json' => ['email' => $email, 'password' => TEST_PASSWORD]]);
        $page = $this->req('/studio/project_list.php');
        if (preg_match('/data-csrf="([a-f0-9]{64})"/', $page['body'], $m)) {
            $this->csrf = $m[1];
        }
        return $this->csrf !== null;
    }
}

/** 시험 계정과 구성원. api_test.php 의 것과 같은 모양으로 만든다. */
function rnd_setup(string $adminEmail, string $userEmail): PDO
{
    $cfg = require dirname(__DIR__, 2) . '/config.php';
    $d   = $cfg['db'];

    if (!in_array($d['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "\n[중단] config.php 의 DB 가 로컬이 아닙니다 ({$d['host']}).\n"
            . "운영 DB 에서 돌리지 마십시오.\n");
        exit(2);
    }

    $pdo = new PDO(
        "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
        $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);
    $ins  = $pdo->prepare(
        'INSERT INTO portal_users (name, email, pw_hash, color, created_at)
              VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE pw_hash = VALUES(pw_hash)'
    );
    $ins->execute(['시험관리자', $adminEmail, $hash, '#5A667F']);
    $ins->execute(['시험사용자', $userEmail,  $hash, '#8C7055']);

    $pdo->prepare('INSERT INTO portal_admin (email, added_by, created_at) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE email = email')
        ->execute([$adminEmail, 'rnd_test.php']);
    $pdo->prepare('DELETE FROM portal_admin WHERE email = ?')->execute([$userEmail]);

    $member = $pdo->prepare(
        'INSERT INTO bs_member (user_id, emp_name, is_assignable) VALUES (?,?,0)
         ON DUPLICATE KEY UPDATE emp_name = VALUES(emp_name)'
    );
    $member->execute([$adminEmail, '시험관리자']);
    $member->execute([$userEmail,  '시험사용자']);

    // 앞 회차가 남긴 시험용 과제를 먼저 치운다. 쌓이면 보드 건수 단언이 흔들린다.
    $pdo->exec("DELETE FROM bs_project WHERE project_type = 'rnd' AND name LIKE '[시험]%'");

    return $pdo;
}

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

// =====================================================================

$pdo = rnd_setup($ADMIN, $USER);

$admin = new RndClient($BASE, 'admin');
$user  = new RndClient($BASE, 'user');
$anon  = new RndClient($BASE, 'anon');

if (!$admin->login($ADMIN) || !$user->login($USER)) {
    fwrite(STDERR, "\n[중단] 로그인에 실패했습니다. 서버가 떠 있는지 확인하십시오.\n");
    exit(2);
}

echo "\nBlueStudio R&D 과제 시험  ($BASE)\n" . str_repeat('=', 62) . "\n";

// ---------------------------------------------------------------------
echo "\n[1] 미로그인은 아무것도 못 한다\n";

$r = $anon->req('/studio/api/rnd.php?act=board');
ok('보드 401', $r['status'] === 401, 'status=' . $r['status']);
ok('과제 이름이 한 건도 새지 않는다', !str_contains($r['body'], 'RND-'));

// ---------------------------------------------------------------------
echo "\n[2] 발의 — 권한으로 막지 않는다\n";

$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 비공개 과제', 'visibility' => 'private', 'rnd_category' => 'poc',
    'summary' => '가시성 확인용', 'load_cap' => '0.2', 'status' => 'proposed']]);
ok('일반 사용자도 발의한다 (R&D 는 지시가 아니라 발의다)',
   $r['status'] === 200, substr($r['body'], 0, 160));
$privId = (int)($r['json']['data']['id'] ?? 0);
ok('코드는 RND- 로 채번된다',
   str_starts_with((string)($r['json']['data']['rnd']['code'] ?? ''), 'RND-'),
   (string)($r['json']['data']['rnd']['code'] ?? ''));
ok('발의 직후 상태는 proposed', ($r['json']['data']['rnd']['status'] ?? '') === 'proposed');
ok('발의자가 기록된다', ($r['json']['data']['rnd']['proposer_id'] ?? '') === $USER);

$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 공개 모집 과제', 'visibility' => 'open', 'recruiting' => '1',
    'status' => 'proposed']]);
$openId = (int)($r['json']['data']['id'] ?? 0);
ok('공개 과제 발의 200', $r['status'] === 200);

$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => ['name' => '   ']]);
ok('과제명이 비면 400', $r['status'] === 400, substr($r['body'], 0, 120));

$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 잘못된 갈래', 'rnd_category' => 'nonsense']]);
ok('모르는 갈래는 400', $r['status'] === 400);

$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 점유율 범위', 'load_cap' => '1.5']]);
ok('점유율이 1 을 넘으면 400', $r['status'] === 400);

$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 기간 거꾸로', 'dev_start' => '2026-06-01', 'dev_end' => '2026-05-01']]);
ok('종료일이 시작일보다 빠르면 400', $r['status'] === 400);

$r = $user->req('/studio/api/rnd.php?act=propose', ['json' => ['name' => '[시험] CSRF 없이']]);
ok('CSRF 없으면 통과하지 못한다', $r['status'] !== 200, 'status=' . $r['status']);

$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] approved 로 바로', 'status' => 'approved']]);
$sneak = (int)($r['json']['data']['id'] ?? 0);
ok('발의로 바로 approved 가 될 수 없다 (§9.1 자동 승인 금지)',
   ($r['json']['data']['rnd']['status'] ?? '') === 'proposed',
   (string)($r['json']['data']['rnd']['status'] ?? ''));

// ---------------------------------------------------------------------
echo "\n[3] 가시성 — 화면이 아니라 Repo 가 막는다 (§8.6)\n";

$r = $admin->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 관리자 비공개 과제', 'visibility' => 'private', 'status' => 'proposed']]);
$admPrivId = (int)($r['json']['data']['id'] ?? 0);

$r = $user->req('/studio/api/rnd.php?act=get&id=' . $admPrivId);
ok('남의 비공개 과제는 404', $r['status'] === 404, 'status=' . $r['status']);
ok('403 이 아니다 — 존재 자체를 흘리지 않는다', $r['status'] !== 403);
ok('응답 어디에도 과제명이 없다', !str_contains($r['body'], '관리자 비공개 과제'));

$r = $user->req('/studio/api/rnd.php?act=board&size=100');
$names = json_encode(array_column($r['json']['data']['rows'] ?? [], 'name'), JSON_UNESCAPED_UNICODE);
ok('보드 목록에도 남의 비공개 과제가 없다', !str_contains($names, '관리자 비공개 과제'));
ok('내가 발의한 비공개 과제는 보인다', str_contains($names, '비공개 과제'));
ok('공개 과제는 보인다', str_contains($names, '공개 모집 과제'));

$counts = $r['json']['data']['counts'] ?? [];
ok('상태별 건수도 같은 가시성 안에서 센다', isset($counts['proposed']));
$stats = $r['json']['data']['stats'] ?? [];
ok('머리 통계가 내려온다', isset($stats['running_count'], $stats['done_quarter'], $stats['output_total']));

$r = $admin->req('/studio/api/rnd.php?act=board&size=100');
$namesA = json_encode(array_column($r['json']['data']['rows'] ?? [], 'name'), JSON_UNESCAPED_UNICODE);
ok('관리자는 비공개까지 전부 본다',
   str_contains($namesA, '관리자 비공개 과제') && str_contains($namesA, '비공개 과제'));

// ---------------------------------------------------------------------
echo "\n[4] 수정 — 발의자와 관리자만, 승인 전까지만\n";

$r = $user->req('/studio/api/rnd.php?act=update', ['csrf' => true, 'json' => [
    'id' => $admPrivId, 'name' => '[시험] 몰래 바꾸기']]);
ok('남의 과제는 못 고친다', $r['status'] !== 200, 'status=' . $r['status']);

$r = $user->req('/studio/api/rnd.php?act=update', ['csrf' => true, 'json' => [
    'id' => $privId, 'summary' => '고친 개요']]);
ok('내 과제는 고친다', $r['status'] === 200, substr($r['body'], 0, 160));
ok('고친 값이 반영된다', ($r['json']['data']['rnd']['summary'] ?? '') === '고친 개요');
ok('안 보낸 칸은 지워지지 않는다',
   ($r['json']['data']['rnd']['name'] ?? '') === '[시험] 비공개 과제',
   (string)($r['json']['data']['rnd']['name'] ?? ''));

$r = $user->req('/studio/api/rnd.php?act=update', ['csrf' => true, 'json' => ['id' => $privId]]);
ok('바꿀 내용이 없으면 400', $r['status'] === 400);

$r = $user->req('/studio/api/rnd.php?act=update', ['csrf' => true, 'json' => [
    'id' => $privId, 'status' => 'approved']]);
ok('수정으로 상태를 올릴 수 없다', $r['status'] === 400, substr($r['body'], 0, 120));
$chk = $user->req('/studio/api/rnd.php?act=get&id=' . $privId);
ok('상태가 그대로 proposed', ($chk['json']['data']['rnd']['status'] ?? '') === 'proposed');

// ---------------------------------------------------------------------
echo "\n[5] 승인 · 반려\n";

$r = $user->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('발의자가 자기 과제를 스스로 승인할 수 없다', $r['status'] !== 200, 'status=' . $r['status']);

$r = $admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('관리자는 승인한다', $r['status'] === 200, substr($r['body'], 0, 160));
ok('상태가 approved', ($r['json']['data']['rnd']['status'] ?? '') === 'approved');
ok('승인자가 남는다', ($r['json']['data']['rnd']['approved_by'] ?? '') === $ADMIN);
ok('승인 시각이 찬다', !empty($r['json']['data']['rnd']['approved_at']));
ok('승인이 점유를 만들지 않는다고 알려 준다', !empty($r['json']['data']['notice']));

// 과제가 승인되면 주도자의 점유가 그 자리에서 올라간다 (P10-2).
$st = $pdo->prepare(
    "SELECT w.* FROM bs_workload w JOIN bs_member m ON m.id = w.member_id
      WHERE w.ref_type = 'rnd' AND w.ref_id = ? AND m.user_id = ?"
);
$st->execute([$openId, $USER]);
$wl = $st->fetch();
ok('과제 승인과 함께 주도자의 점유가 올라간다', $wl !== false);
ok("kind='assigned'", ($wl['kind'] ?? '') === 'assigned');
ok("ref_type='rnd', ref_id=과제번호",
   ($wl['ref_type'] ?? '') === 'rnd' && (int)($wl['ref_id'] ?? 0) === $openId);
ok('정체가 아니면 confidence=1.0', abs((float)($wl['confidence'] ?? 0) - 1.0) < 0.001,
   (string)($wl['confidence'] ?? ''));

$r = $admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('두 번 승인할 수 없다', $r['status'] !== 200, 'status=' . $r['status']);

$r = $admin->req('/studio/api/rnd.php?act=reject', ['csrf' => true, 'json' => ['id' => $privId]]);
ok('사유 없이 반려할 수 없다', $r['status'] !== 200, substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/rnd.php?act=reject', ['csrf' => true,
    'json' => ['id' => $privId, 'reason' => '범위가 너무 넓습니다']]);
ok('사유와 함께면 반려 200', $r['status'] === 200, substr($r['body'], 0, 160));
ok('반려하면 draft 로 되돌아간다 — 고쳐서 다시 낼 수 있다',
   ($r['json']['data']['rnd']['status'] ?? '') === 'draft');
ok('반려 사유가 기록에 남는다',
   str_contains((string)($r['json']['data']['rnd']['notes'] ?? ''), '범위가 너무 넓습니다'));
ok('승인 흔적은 지워진다', empty($r['json']['data']['rnd']['approved_at']));

$r = $admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => 99999999]]);
ok('없는 과제는 승인 불가', $r['status'] !== 200);

// ---------------------------------------------------------------------
echo "\n[6] 서버가 계산해 내려주는 권한 (inc/presenter.php)\n";

$r = $user->req('/studio/api/rnd.php?act=get&id=' . $privId);
$d = $r['json']['data']['rnd'] ?? [];
ok('발의자에게 is_proposer=true', ($d['is_proposer'] ?? null) === true);
ok('발의자는 수정 가능', ($d['can']['edit'] ?? null) === true);
ok('발의자는 승인 불가', ($d['can']['approve'] ?? null) === false);
ok('actions 에 approve 가 없다', !in_array('approve', $d['actions'] ?? [], true),
   json_encode($d['actions'] ?? []));
ok('actions 에 update 는 있다', in_array('update', $d['actions'] ?? [], true));

$r = $admin->req('/studio/api/rnd.php?act=get&id=' . $admPrivId);
$d = $r['json']['data']['rnd'] ?? [];
ok('관리자에게는 approve 가 열린다', in_array('approve', $d['actions'] ?? [], true),
   json_encode($d['actions'] ?? []));
ok('reject 도 함께', in_array('reject', $d['actions'] ?? [], true));

$r = $admin->req('/studio/api/rnd.php?act=get&id=' . $openId);
$d = $r['json']['data']['rnd'] ?? [];
ok('승인된 open + 모집중 과제는 join 가능 상태', ($d['can']['join'] ?? null) === true);

$r = $user->req('/studio/api/rnd.php?act=get&id=' . $sneak);
$d = $r['json']['data']['rnd'] ?? [];
ok('비공개 과제는 모집중이어도 join 불가', ($d['can']['join'] ?? null) === false);

// ---------------------------------------------------------------------
echo "\n[7] 프로젝트 쪽과 섞이지 않는다\n";

$r = $admin->req('/studio/api/project.php?act=list&size=100');
$pn = json_encode(array_column($r['json']['data']['rows'] ?? [], 'name'), JSON_UNESCAPED_UNICODE);
ok('프로젝트 목록에 R&D 과제가 없다', !str_contains($pn, '[시험]'), $pn);

$r = $admin->req('/studio/api/rnd.php?act=get&id=1');
ok('RndRepo 로는 지시형 프로젝트를 집을 수 없다',
   $r['status'] === 404 || ($r['json']['data']['rnd']['code'] ?? '') !== 'PRJ-2026-001',
   'status=' . $r['status']);

$r = $admin->req('/studio/api/rnd.php?act=nonsense');
ok('모르는 act 는 400', $r['status'] === 400);

// ---------------------------------------------------------------------
echo "\n[8] 합류 (명세서 §8.4)\n";

// 승인된 open+모집 과제는 $openId. 발의자는 시험사용자, lead 도 시험사용자.
$r = $admin->req('/studio/api/rnd.php?act=get&id=' . $openId);
$lead = null;
foreach ($r['json']['data']['members'] ?? [] as $m) {
    if ($m['role'] === 'lead') { $lead = $m; }
}
ok('과제가 승인되면 발의자가 주도자(lead)로 앉는다', $lead !== null,
   json_encode($r['json']['data']['members'] ?? [], JSON_UNESCAPED_UNICODE));
ok('주도자는 승인 상태', ($lead['status'] ?? '') === 'approved');

$r = $admin->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $openId, 'join_reason' => '', 'load_ratio' => '0.1']]);
ok('사유 없이 합류 신청 불가', $r['status'] === 400, substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $openId, 'join_reason' => '인프라 쪽을 돕고 싶습니다', 'load_ratio' => '1.5']]);
ok('점유율이 1 을 넘으면 거절', $r['status'] === 400);

$r = $admin->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $openId, 'join_reason' => '인프라 쪽을 돕고 싶습니다', 'load_ratio' => '0.15']]);
ok('합류 신청 200', $r['status'] === 200, substr($r['body'], 0, 160));

$joinRow = null;
foreach ($r['json']['data']['members'] ?? [] as $m) {
    if (($m['status'] ?? '') === 'requested') { $joinRow = $m; }
}
ok('신청 상태로 들어간다', $joinRow !== null);
ok('사유가 남는다', ($joinRow['join_reason'] ?? '') === '인프라 쪽을 돕고 싶습니다');

$r = $admin->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $openId, 'join_reason' => '또', 'load_ratio' => '0.1']]);
ok('두 번 신청할 수 없다', $r['status'] !== 200, 'status=' . $r['status']);

$r = $admin->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $privId, 'join_reason' => '비공개인데', 'load_ratio' => '0.1']]);
ok('받지 않는 과제에는 신청할 수 없다', $r['status'] !== 200);

echo "\n[9] 합류 승인 — lead 또는 관리자\n";

$pdo->exec("DELETE FROM bs_notification WHERE ref_type = 'rnd_member'");

// 시험사용자가 lead 다. 관리자도 권한이 있다.
$r = $user->req('/studio/api/rnd.php?act=approve_member', ['csrf' => true,
    'json' => ['member_row_id' => (int)$joinRow['id']]]);
ok('주도자는 합류를 승인한다', $r['status'] === 200, substr($r['body'], 0, 180));

$approved = null;
foreach ($r['json']['data']['members'] ?? [] as $m) {
    if ((int)$m['id'] === (int)$joinRow['id']) { $approved = $m; }
}
ok('상태가 참여 중으로 바뀐다', ($approved['status'] ?? '') === 'approved');
ok('승인자가 남는다', !empty($approved['approved_at']));

$n = (int)$pdo->query("SELECT COUNT(*) AS n FROM bs_notification
                        WHERE ref_type = 'rnd_member'")->fetch()['n'];
ok('승인 알림이 적재된다', $n > 0, "적재 {$n}건");
ok('보내지는 않는다 (발송 경로 없음)',
   (int)$pdo->query("SELECT COUNT(*) AS n FROM bs_notification
                      WHERE ref_type='rnd_member' AND status='sent'")->fetch()['n'] === 0);

$st = $pdo->prepare(
    "SELECT w.load_ratio FROM bs_workload w JOIN bs_member m ON m.id = w.member_id
      WHERE w.ref_type = 'rnd' AND w.ref_id = ? AND m.user_id = ?"
);
$st->execute([$openId, $ADMIN]);
$wl2 = $st->fetch();
ok('합류 승인과 함께 그 사람의 점유가 올라간다', $wl2 !== false);
ok('신고한 점유율 그대로 올라간다 (0.15)',
   abs((float)($wl2['load_ratio'] ?? 0) - 0.15) < 0.001,
   (string)($wl2['load_ratio'] ?? ''));

$r = $user->req('/studio/api/rnd.php?act=approve_member', ['csrf' => true,
    'json' => ['member_row_id' => (int)$joinRow['id']]]);
ok('이미 승인된 사람을 또 승인할 수 없다', $r['status'] !== 200);

echo "\n[10] 진행 기록 — content 와 finding 을 나눈다 (§8.3)\n";

$r = $admin->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $openId, 'content' => '']]);
ok('내용이 비면 거절', $r['status'] === 400);

$r = $admin->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $openId, 'content' => '캐시 계층을 붙여 봤다',
    'finding' => '병목은 캐시가 아니라 인덱스였다', 'worked_on' => '2026-10-01']]);
ok('승인된 참여자는 기록을 남긴다', $r['status'] === 200, substr($r['body'], 0, 180));

$log = ($r['json']['data']['logs'] ?? [])[0] ?? [];
ok('content 가 그대로', ($log['content'] ?? '') === '캐시 계층을 붙여 봤다');
ok('finding 이 따로 남는다', ($log['finding'] ?? '') === '병목은 캐시가 아니라 인덱스였다');
ok('작업한 날이 남는다', ($log['worked_on'] ?? '') === '2026-10-01');

$r = $admin->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $openId, 'content' => 'x', 'worked_on' => '엉터리']]);
ok('날짜 형식이 틀리면 거절', $r['status'] === 400);

// 참여하지 않는 사람 — 관리자 비공개 과제에 시험사용자가 기록을 남기려 한다.
$r = $user->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $admPrivId, 'content' => '남의 과제']]);
ok('참여하지 않는 과제에는 기록을 못 남긴다', $r['status'] !== 200);

echo "\n[11] 산출물\n";

$r = $admin->req('/studio/api/rnd.php?act=output', ['csrf' => true, 'json' => [
    'id' => $openId, 'title' => '']]);
ok('제목이 비면 거절', $r['status'] === 400);

$r = $admin->req('/studio/api/rnd.php?act=output', ['csrf' => true, 'json' => [
    'id' => $openId, 'title' => '측정 결과', 'kind' => 'nonsense']]);
ok('모르는 갈래는 거절', $r['status'] === 400);

$r = $admin->req('/studio/api/rnd.php?act=output', ['csrf' => true, 'json' => [
    'id' => $openId, 'title' => '측정 결과', 'kind' => 'report', 'url' => 'ftp://x']]);
ok('http(s) 가 아닌 링크는 거절', $r['status'] === 400);

$r = $admin->req('/studio/api/rnd.php?act=output', ['csrf' => true, 'json' => [
    'id' => $openId, 'title' => '측정 결과', 'kind' => 'report',
    'url' => 'https://example.com/r', 'summary' => '인덱스 교체 전후 비교']]);
ok('산출물 등록 200', $r['status'] === 200, substr($r['body'], 0, 180));
$out = ($r['json']['data']['outputs'] ?? [])[0] ?? [];
ok('갈래 이름이 함께 온다', ($out['kind_label'] ?? '') === '보고서');
ok('file_path 는 내보내지 않는다', !array_key_exists('file_path', $out),
   json_encode(array_keys($out)));

echo "\n[11-2] 참여자 수정 권한 — 주도자·관리자가 사람마다 (2026-10-14)\n";

// 관리자는 [9] 에서 일반 참여자로 승인됐다. 시험사용자가 주도자다.
$r = $user->req('/studio/api/rnd.php?act=member_edit', ['csrf' => true, 'json' => [
    'member_row_id' => (int)$joinRow['id'], 'on' => '1']]);
ok('주도자는 참여자에게 수정 권한을 준다', $r['status'] === 200, substr($r['body'], 0, 160));
$granted = null; $leadRow = null;
foreach ($r['json']['data']['members'] ?? [] as $m) {
    if ((int)$m['id'] === (int)$joinRow['id']) { $granted = $m; }
    if (($m['role'] ?? '') === 'lead') { $leadRow = $m; }
}
ok('목록에 허용으로 보인다', ($granted['can_edit'] ?? false) === true, json_encode($granted['can_edit'] ?? null));
ok('누가 줬는지 남는다', ($granted['edit_granted_by'] ?? '') === $USER);
ok('주도자는 원래 고칠 수 있어 칸이 늘 켜져 있다', ($leadRow['can_edit'] ?? false) === true);

$r = $user->req('/studio/api/rnd.php?act=member_edit', ['csrf' => true, 'json' => [
    'member_row_id' => (int)$leadRow['id'], 'on' => '0']]);
ok('주도자의 권한은 끌 수 없다', $r['status'] === 400, substr($r['body'], 0, 120));

// 받은 사람이 실제로 고칠 수 있는가 — 관리자는 원래 고칠 수 있어 HTTP 로는
// 가릴 수 없다. 판정 함수를 직접 부른다(로그인 없음 = 관리자 아님).
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/presenter.php';
$base = ['status' => 'approved', 'proposer_id' => 'x@x', 'my_status' => 'approved', 'my_role' => 'member'];
ok('★ 권한을 받은 참여자는 승인 뒤에도 고친다', bs_rnd_can_edit($base + ['my_can_edit' => 1]));
ok('★ 받지 않은 참여자는 못 고친다', !bs_rnd_can_edit($base + ['my_can_edit' => 0]));
ok('신청 중인 사람은 받아도 못 고친다',
   !bs_rnd_can_edit(['my_status' => 'requested', 'my_can_edit' => 1] + $base));
ok('끝난 과제는 관리자만', !bs_rnd_can_edit(['status' => 'done', 'my_can_edit' => 1] + $base));

// 승인 뒤 기간을 고치면 이미 올라간 점유의 기간도 따라간다.
$newEnd = date('Y-m-d', strtotime('+120 day'));
$r = $user->req('/studio/api/rnd.php?act=update', ['csrf' => true, 'json' => [
    'id' => $openId, 'dev_end' => $newEnd]]);
ok('주도자는 승인 뒤에도 기간을 고친다', $r['status'] === 200, substr($r['body'], 0, 160));
$we = $pdo->prepare("SELECT MAX(end_date) FROM bs_workload
                      WHERE ref_type = 'rnd' AND ref_id = ? AND kind = 'assigned'");
$we->execute([$openId]);
ok('★ 점유 기간이 새 기간으로 바뀐다', (string)$we->fetchColumn() === $newEnd, $newEnd);

$user->req('/studio/api/rnd.php?act=member_edit', ['csrf' => true, 'json' => [
    'member_row_id' => (int)$joinRow['id'], 'on' => '0']]);

echo "\n[12] 관심 표시 — 점유를 만들지 않는다\n";

$r = $user->req('/studio/api/rnd.php?act=interest', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('관심 표시 켜기', ($r['json']['data']['interested'] ?? null) === true, substr($r['body'], 0, 140));

// '관심 과제만' — 내가 표시한 과제만 남는다 (2026-10-12).
$ids = fn($c) => array_map(static fn($x) => (int)$x['id'],
    $c->req('/studio/api/rnd.php?act=board&interest=1&size=100')['json']['data']['rows'] ?? []);
ok('관심 과제만 — 표시한 과제가 나온다', in_array((int)$openId, $ids($user), true));
ok('관심 과제만 — 표시하지 않은 사람에게는 안 나온다', !in_array((int)$openId, $ids($admin), true));

$r = $user->req('/studio/api/rnd.php?act=interest', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('다시 누르면 꺼진다', ($r['json']['data']['interested'] ?? null) === false);
ok('끄면 관심 과제에서 빠진다', !in_array((int)$openId, $ids($user), true));

echo "\n[13] 나가기 — 본인만, 주도자는 못 나간다\n";

$r = $user->req('/studio/api/rnd.php?act=leave', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('주도자는 혼자 나갈 수 없다', $r['status'] !== 200, substr($r['body'], 0, 140));

// 관리자는 [9] 에서 일반 참여자로 승인됐다. 그 사람은 나갈 수 있다.
$r = $admin->req('/studio/api/rnd.php?act=leave', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('참여자는 스스로 나간다', $r['status'] === 200, substr($r['body'], 0, 160));
$left = null;
foreach ($r['json']['data']['members'] ?? [] as $m) {
    if ((int)$m['id'] === (int)$joinRow['id']) { $left = $m; }
}
ok('상태가 이탈로 바뀐다', ($left['status'] ?? '') === 'left', (string)($left['status'] ?? ''));
ok('나간 시각이 남는다', !empty($left['left_at']));

$r = $admin->req('/studio/api/rnd.php?act=leave', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('두 번 나갈 수 없다', $r['status'] !== 200);

$r = $admin->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $openId, 'content' => '나간 뒤 기록']]);
ok('나간 뒤에는 기록을 못 남긴다', $r['status'] !== 200, 'status=' . $r['status']);

// 다시 신청하면 받아 준다 — 한 번 나갔다가 돌아오는 경우가 있다.
$r = $admin->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $openId, 'join_reason' => '다시 돕겠습니다', 'load_ratio' => '0.1']]);
ok('나간 사람도 다시 신청할 수 있다', $r['status'] === 200, substr($r['body'], 0, 160));

echo "\n[14] 종료 (명세서 §8.5)\n";

// 산출물이 없는 과제를 하나 만들어 승인한다.
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 산출물 없는 과제', 'visibility' => 'open', 'status' => 'proposed']]);
$emptyId = (int)($r['json']['data']['id'] ?? 0);
$admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $emptyId]]);

$r = $admin->req('/studio/api/rnd.php?act=finish', ['csrf' => true, 'json' => ['id' => $emptyId]]);
ok('산출물이 없으면 종료 불가', $r['status'] === 400, 'status=' . $r['status']);
ok('전용 코드 RND_NO_OUTPUT 로 알려 준다',
   ($r['json']['error']['code'] ?? '') === 'RND_NO_OUTPUT',
   (string)($r['json']['error']['code'] ?? ''));

$r = $admin->req('/studio/api/rnd.php?act=finish', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('산출물이 있으면 종료 200', $r['status'] === 200, substr($r['body'], 0, 180));
ok('상태가 done', ($r['json']['data']['rnd']['status'] ?? '') === 'done');
ok('모집이 꺼진다', ($r['json']['data']['rnd']['recruiting'] ?? null) === false);
ok('점유를 지우지 않는다고 알려 준다',
   str_contains((string)($r['json']['data']['notice'] ?? ''), '지우지 않습니다'));

$st = $pdo->prepare("SELECT COUNT(*) AS n FROM bs_rnd_member WHERE project_id = ? AND status = 'approved'");
$st->execute([$openId]);
ok('참여자도 함께 종료 처리된다', (int)$st->fetch()['n'] === 0);

$r = $admin->req('/studio/api/rnd.php?act=finish', ['csrf' => true, 'json' => ['id' => $openId]]);
ok('끝난 과제를 또 끝낼 수 없다', $r['status'] !== 200);

$r = $admin->req('/studio/api/rnd.php?act=drop', ['csrf' => true, 'json' => ['id' => $emptyId]]);
ok('사유 없이 중단 불가', $r['status'] !== 200);

$r = $admin->req('/studio/api/rnd.php?act=drop', ['csrf' => true,
    'json' => ['id' => $emptyId, 'reason' => '다른 과제로 대체']]);
ok('사유와 함께면 중단 200', $r['status'] === 200, substr($r['body'], 0, 180));
ok('상태가 dropped', ($r['json']['data']['rnd']['status'] ?? '') === 'dropped');
ok('중단 사유가 기록에 남는다',
   str_contains((string)($r['json']['data']['rnd']['notes'] ?? ''), '다른 과제로 대체'));
ok('역량에 반영하지 않는다고 알려 준다',
   str_contains((string)($r['json']['data']['notice'] ?? ''), '반영하지 않습니다'));

echo "\n[15] 끝난 과제에는 더 쓸 수 없다\n";

$r = $admin->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $openId, 'content' => '끝난 뒤 기록']]);
ok('종료된 과제에는 기록을 못 남긴다', $r['status'] !== 200, 'status=' . $r['status']);

$r = $admin->req('/studio/api/rnd.php?act=get&id=' . $openId);
$can = $r['json']['data']['rnd']['can'] ?? [];
ok('종료 뒤에는 write_log 가 닫힌다', ($can['write_log'] ?? null) === false);
ok('종료 뒤에는 finish 도 닫힌다', ($can['finish'] ?? null) === false);
ok('종료 뒤에는 join 이 닫힌다', ($can['join'] ?? null) === false);

echo "\n[16] 종료·이탈은 점유를 지우지 않고 끝 날짜만 당긴다 (§8.5)\n";

// [14] 에서 $openId 를 종료했다. 그 점유 행이 어떻게 됐는지 본다.
$st = $pdo->prepare("SELECT member_id, end_date FROM bs_workload
                      WHERE ref_type = 'rnd' AND ref_id = ?");
$st->execute([$openId]);
$after = $st->fetchAll();
ok('종료해도 점유 행은 남는다', count($after) > 0, '행 ' . count($after) . '건');
$allClosed = true;
foreach ($after as $w) {
    if ($w['end_date'] > date('Y-m-d')) { $allClosed = false; }
}
ok('끝 날짜가 오늘 이하로 당겨진다', $allClosed,
   json_encode(array_column($after, 'end_date')));

// 끝난 과제의 점유는 지금 기간의 가용도를 더는 먹지 않는다.
$st = $pdo->prepare("SELECT COUNT(*) AS n FROM bs_workload
                      WHERE ref_type = 'rnd' AND ref_id = ?
                        AND start_date <= CURDATE() AND end_date >= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$st->execute([$openId]);
ok('다음 주 가용도에는 더 이상 잡히지 않는다', (int)$st->fetch()['n'] === 0);

// ---------------------------------------------------------------------
echo "\n[17] 점유 통제 (명세서 §9, P10-1)\n";

// 설정은 **표에서** 읽는다. 값을 바꿔 보고 통제가 따라 움직이는지 본다.
$setting = $pdo->query("SELECT k, v FROM bs_setting WHERE k LIKE 'rnd\\_%'")->fetchAll();
$sv = [];
foreach ($setting as $s) { $sv[$s['k']] = $s['v']; }
ok('상한값이 bs_setting 에 있다',
   isset($sv['rnd_total_cap'], $sv['rnd_per_project_cap'],
         $sv['rnd_concurrent_max'], $sv['rnd_stale_weeks']),
   json_encode($sv));

/**
 * 데모용 과제 하나를 만들어 승인한다. 관리자가 발의해 lead 가 된다.
 *
 * **승인하면 발의자도 점유를 쓴다**(상한 우회를 막은 뒤부터). 여기서 재려는
 * 것은 '시험사용자' 의 상한이므로, 과제를 만드는 동안에는 상한을 잠시
 * 올려 두어 발의자(관리자) 쪽에 걸리지 않게 한다. 바로 되돌린다.
 */
$withCapRaised = function (callable $fn) use ($pdo) {
    $pdo->exec("UPDATE bs_setting SET v='0.99' WHERE k='rnd_total_cap'");
    $pdo->exec("UPDATE bs_setting SET v='0.99' WHERE k='rnd_per_project_cap'");
    $pdo->exec("UPDATE bs_setting SET v='99'   WHERE k='rnd_concurrent_max'");
    try   { return $fn(); }
    finally {
        $pdo->exec("UPDATE bs_setting SET v='0.30' WHERE k='rnd_total_cap'");
        $pdo->exec("UPDATE bs_setting SET v='0.20' WHERE k='rnd_per_project_cap'");
        $pdo->exec("UPDATE bs_setting SET v='2'    WHERE k='rnd_concurrent_max'");
    }
};
$mkRnd = function (string $name) use ($admin, $withCapRaised): int {
  return $withCapRaised(function () use ($admin, $name): int {
    $r = $admin->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
        'name' => '[시험] ' . $name, 'visibility' => 'open', 'recruiting' => '1',
        'status' => 'proposed']]);
    $id = (int)($r['json']['data']['id'] ?? 0);
    $admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $id]]);
    return $id;
  });
};
$rowIdOf = function (int $pid) use ($pdo, $USER): int {
    $st = $pdo->prepare('SELECT rm.id FROM bs_rnd_member rm JOIN bs_member m ON m.id = rm.member_id
                          WHERE rm.project_id = ? AND m.user_id = ?');
    $st->execute([$pid, $USER]);
    return (int)($st->fetch()['id'] ?? 0);
};

// --- ① CAP_PROJECT ---
$c1 = $mkRnd('과제당 초과');
$user->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $c1, 'join_reason' => '해보고 싶습니다', 'load_ratio' => '0.25']]);
$r = $admin->req('/studio/api/rnd.php?act=approve_member', ['csrf' => true,
    'json' => ['member_row_id' => $rowIdOf($c1)]]);
ok('과제당 상한을 넘으면 승인이 막힌다', $r['status'] === 400, 'status=' . $r['status']);
ok('코드는 RND_CAP_EXCEEDED', ($r['json']['error']['code'] ?? '') === 'RND_CAP_EXCEEDED');
ok('위반 코드 CAP_PROJECT',
   ($r['json']['error']['detail']['violations'][0]['code'] ?? '') === 'CAP_PROJECT',
   json_encode($r['json']['error']['detail']['violations'] ?? []));
ok('현재 점유와 상한을 함께 돌려준다',
   isset($r['json']['error']['detail']['current'], $r['json']['error']['detail']['limit']));

$st = $pdo->prepare('SELECT status FROM bs_rnd_member WHERE id = ?');
$st->execute([$rowIdOf($c1)]);
ok('막혔으면 신청 상태 그대로다 (자동 승인 없음)',
   ($st->fetch()['status'] ?? '') === 'requested');

// --- ② CAP_TOTAL ---
$c2 = $mkRnd('합계 1');
$user->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $c2, 'join_reason' => '첫 과제', 'load_ratio' => '0.2']]);
$r = $admin->req('/studio/api/rnd.php?act=approve_member', ['csrf' => true,
    'json' => ['member_row_id' => $rowIdOf($c2)]]);
ok('상한 안이면 승인된다 (0.2)', $r['status'] === 200, substr($r['body'], 0, 160));

$c3 = $mkRnd('합계 2');
$user->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $c3, 'join_reason' => '둘째 과제', 'load_ratio' => '0.15']]);
$r = $admin->req('/studio/api/rnd.php?act=approve_member', ['csrf' => true,
    'json' => ['member_row_id' => $rowIdOf($c3)]]);
ok('합계가 상한을 넘으면 막힌다 (0.2 + 0.15)', $r['status'] === 400);
ok('위반 코드 CAP_TOTAL',
   ($r['json']['error']['detail']['violations'][0]['code'] ?? '') === 'CAP_TOTAL');
ok('지금 점유가 얼마인지 알려 준다',
   abs((float)($r['json']['error']['detail']['current']['total'] ?? 0) - 0.2) < 0.001,
   (string)($r['json']['error']['detail']['current']['total'] ?? ''));

// --- ③ CAP_CONCURRENT ---
// 합계가 안 걸리도록 낮춰 두 건을 채운다.
$pdo->exec("UPDATE bs_rnd_member SET load_ratio = 0.05 WHERE id = " . $rowIdOf($c2));
$pdo->exec("UPDATE bs_rnd_member SET load_ratio = 0.05 WHERE id = " . $rowIdOf($c3));
$admin->req('/studio/api/rnd.php?act=approve_member', ['csrf' => true,
    'json' => ['member_row_id' => $rowIdOf($c3)]]);

$c4 = $mkRnd('동시 3번째');
$user->req('/studio/api/rnd.php?act=join', ['csrf' => true, 'json' => [
    'id' => $c4, 'join_reason' => '셋째', 'load_ratio' => '0.05']]);
$r = $admin->req('/studio/api/rnd.php?act=approve_member', ['csrf' => true,
    'json' => ['member_row_id' => $rowIdOf($c4)]]);
ok('동시 참여 건수를 넘으면 막힌다', $r['status'] === 400, substr($r['body'], 0, 200));
ok('위반 코드 CAP_CONCURRENT',
   ($r['json']['error']['detail']['violations'][0]['code'] ?? '') === 'CAP_CONCURRENT');
ok('총량은 여유가 있어도 건수로 막힌다',
   (float)($r['json']['error']['detail']['current']['total'] ?? 1) < 0.3,
   (string)($r['json']['error']['detail']['current']['total'] ?? ''));

// --- 사전 확인 ---
$r = $user->req('/studio/api/rnd.php?act=load_check&id=' . $c4 . '&load_ratio=0.05');
ok('load_check 는 신청 전에도 부를 수 있다', $r['status'] === 200);
ok('승인 때와 같은 판정을 돌려준다', ($r['json']['data']['ok'] ?? null) === false);
ok('같은 위반 코드',
   ($r['json']['data']['violations'][0]['code'] ?? '') === 'CAP_CONCURRENT');
ok('상한값도 함께 온다', isset($r['json']['data']['limit']['total_cap']));

// --- ④ 정체 ---
$pdo->exec("UPDATE bs_project SET approved_at = DATE_SUB(NOW(), INTERVAL 70 DAY) WHERE id = $c2");
$r = $user->req('/studio/api/rnd.php?act=load_check&id=' . $c4 . '&load_ratio=0.05');
$stale = $r['json']['data']['stale_projects'] ?? [];
ok('정체 과제를 함께 알려 준다', count($stale) > 0, json_encode($stale, JSON_UNESCAPED_UNICODE));

$r = $admin->req('/studio/api/rnd.php?act=board&size=100');
$found = null;
foreach ($r['json']['data']['rows'] ?? [] as $row2) {
    if ((int)$row2['id'] === $c2) { $found = $row2; }
}
ok('보드 카드에 stale 이 선다', ($found['stale'] ?? null) === true);

// 설정을 바꾸면 판정이 따라 움직인다 — 하드코딩이 아님을 확인한다.
$pdo->exec("UPDATE bs_setting SET v = '52' WHERE k = 'rnd_stale_weeks'");
$r = $admin->req('/studio/api/rnd.php?act=board&size=100');
$found2 = null;
foreach ($r['json']['data']['rows'] ?? [] as $row2) {
    if ((int)$row2['id'] === $c2) { $found2 = $row2; }
}
ok('rnd_stale_weeks 를 늘리면 정체가 풀린다 (설정값이 실제로 쓰인다)',
   ($found2['stale'] ?? null) === false);
$pdo->exec("UPDATE bs_setting SET v = '4' WHERE k = 'rnd_stale_weeks'");

// --- ⑤ 발의 제한 ---
$pdo->exec("UPDATE bs_project SET status = 'dropped', proposer_id = '$USER'
             WHERE id IN ($c2, $c3)");
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 세 번째 발의', 'visibility' => 'open', 'status' => 'proposed']]);
ok('연속 중단이어도 발의를 막지는 않는다', $r['status'] === 200);
ok('경고를 함께 돌려준다',
   ($r['json']['data']['warning']['code'] ?? '') === 'RND_RECENT_DROPS',
   json_encode($r['json']['data']['warning'] ?? null, JSON_UNESCAPED_UNICODE));
ok('어느 과제가 중단됐는지 적어 준다',
   count($r['json']['data']['warning']['dropped'] ?? []) === 2);

$newId = (int)($r['json']['data']['id'] ?? 0);
$g = $admin->req('/studio/api/rnd.php?act=get&id=' . $newId);
ok('승인하는 사람도 그 이력을 본다',
   ($g['json']['data']['rnd']['proposer_warning']['code'] ?? '') === 'RND_RECENT_DROPS');

// 한 건만 중단이면 경고하지 않는다.
$pdo->exec("UPDATE bs_project SET status = 'done' WHERE id = $c3");
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 네 번째 발의', 'visibility' => 'open', 'status' => 'proposed']]);
ok('연속이 아니면 경고하지 않는다', ($r['json']['data']['warning'] ?? null) === null,
   json_encode($r['json']['data']['warning'] ?? null, JSON_UNESCAPED_UNICODE));

// 상한에 막힌 승인은 점유도 만들지 않는다 — 승인과 적재가 한 트랜잭션이다.
$st = $pdo->prepare("SELECT COUNT(*) AS n FROM bs_workload
                      WHERE ref_type = 'rnd' AND ref_id = ?");
$st->execute([$c1]);
ok('상한에 막힌 과제에는 신청자의 점유가 없다',
   (int)$st->fetch()['n'] <= 1,   // 주도자(관리자) 몫 1건까지만
   '행 수');

// ---------------------------------------------------------------------
echo "\n[18] 회귀 — R&D 가 0인 사람의 가용도는 **완전히 그대로다**\n";

/* ┌──────────────────────────────────────────────────────────────────┐
   │ P10-2 가 가장 조심해야 할 것                                      │
   │                                                                  │
   │ 가용도 계산에 손을 대면, R&D 와 아무 상관없는 사람의 숫자가       │
   │ 조용히 1~2% 틀어질 수 있다. 그러면 기존 배정안의 적합도가 바뀌고  │
   │ 아무도 왜 바뀌었는지 모른다.                                      │
   │                                                                  │
   │ 그래서 **같은 사람을 두 가지 방법으로 재어 맞대어 본다.**         │
   │   ① 지금 코드가 내는 값                                          │
   │   ② R&D 행을 아예 제외하고 손으로 다시 계산한 값                 │
   │ R&D 가 0인 사람은 둘이 소수점까지 같아야 한다.                    │
   └──────────────────────────────────────────────────────────────────┘ */

$cfgR = require dirname(__DIR__, 2) . '/config.php';
$dR   = $cfgR['db'];
putenv('BS_SKIP'); // (환경 오염 방지용 자리표시 — 아무 일도 하지 않는다)

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/service/AvailabilityCalculator.php';

$calc = new AvailabilityCalculator($pdo);
$from = date('Y-m-d');
$to   = date('Y-m-d', strtotime('+60 day'));

// R&D 점유가 **하나도 없는** 구성원을 고른다.
$noRnd = $pdo->query(
    "SELECT m.id, m.emp_name FROM bs_member m
      WHERE NOT EXISTS (SELECT 1 FROM bs_workload w
                         WHERE w.member_id = m.id AND w.ref_type = 'rnd')
      ORDER BY m.id LIMIT 5"
)->fetchAll();
ok('R&D 점유가 없는 구성원을 찾았다', count($noRnd) > 0, (string)count($noRnd));

$ids  = array_map(static fn($r) => (int)$r['id'], $noRnd);
$res  = $calc->forMembers($ids, $from, $to);

$same = true; $detail = '';
foreach ($ids as $mid) {
    $a = $res[$mid] ?? null;
    if (!$a) { continue; }
    // rnd_load 는 0 이어야 하고, project_load 가 곧 confirmed_load 다.
    if (abs($a['rnd_load']) > 0.00001) { $same = false; $detail .= "m$mid rnd≠0 "; }
    if (abs($a['project_load'] - $a['confirmed_load']) > 0.00001) {
        $same = false; $detail .= "m$mid project≠confirmed ";
    }
    if ($a['project_pct'] !== $a['confirmed_pct'] || $a['rnd_pct'] !== 0) {
        $same = false; $detail .= "m$mid pct 어긋남 ";
    }
    if ($a['capped'] !== false && $a['confirmed_raw'] <= $a['base_capacity']) {
        $same = false; $detail .= "m$mid capped 오탐 ";
    }
}
ok('R&D 0인 사람: rnd_load=0 이고 project_load == confirmed_load', $same, $detail);

// 분해가 **언제나** 합과 맞는지 — R&D 가 있든 없든.
$all = $pdo->query('SELECT id FROM bs_member ORDER BY id')->fetchAll();
$allIds = array_map(static fn($r) => (int)$r['id'], $all);
$res2 = $calc->forMembers($allIds, $from, $to);
$sumOk = true; $bad = '';
foreach ($res2 as $mid => $a) {
    if (abs(($a['project_load'] + $a['rnd_load']) - $a['confirmed_load']) > 0.0002) {
        $sumOk = false; $bad .= "m$mid ";
    }
    if ($a['project_pct'] + $a['rnd_pct'] !== $a['confirmed_pct']) {
        $sumOk = false; $bad .= "m{$mid}%";
    }
}
ok('모든 구성원에서 project + rnd = confirmed (소수·정수 둘 다)', $sumOk, $bad);

// 기존 계산식이 그대로인지 — available 을 손으로 다시 계산해 맞대어 본다.
$formulaOk = true; $fb = '';
foreach ($res2 as $mid => $a) {
    $expect = round(max(0.0, $a['base_capacity'] - $a['confirmed_load'] - $a['inferred_load']), 4);
    if (abs($a['available'] - $expect) > 0.00001) {
        $formulaOk = false; $fb .= "m$mid ";
    }
}
ok('available = base - confirmed - inferred (식이 그대로다)', $formulaOk, $fb);

echo "\n[19] 확정 배정의 가용도가 흔들리지 않았다\n";

// 확정 배정(ref_type='allocation_item')이 만든 점유는 project 쪽으로만 간다.
$st = $pdo->query(
    "SELECT DISTINCT member_id FROM bs_workload
      WHERE kind = 'assigned' AND ref_type <> 'rnd'"
);
$allocIds = array_map(static fn($r) => (int)$r['member_id'], $st->fetchAll());
if ($allocIds) {
    $res3 = $calc->forMembers($allocIds, $from, $to);
    $ok3 = true; $b3 = '';
    foreach ($res3 as $mid => $a) {
        // 이 사람들에게 R&D 점유가 없다면 project 가 confirmed 전부여야 한다.
        if ($a['rnd_load'] == 0.0 && abs($a['project_load'] - $a['confirmed_load']) > 0.00001) {
            $ok3 = false; $b3 .= "m$mid ";
        }
    }
    ok('배정 점유는 전부 project 쪽으로 분류된다', $ok3, $b3);
} else {
    ok('배정 점유를 가진 구성원이 없어 건너뜀 (확인할 것 없음)', true);
}

// ---------------------------------------------------------------------
echo "\n[20] 화면이 받는 값 — 합산 숫자만 주는 자리가 없다\n";

$r = $admin->req('/studio/api/rnd.php?act=member_load');
ok('본인 점유 내역은 언제나 볼 수 있다', $r['status'] === 200, 'status=' . $r['status']);
$d = $r['json']['data'] ?? [];
ok('과제 목록과 상한·여유가 함께 온다',
   isset($d['projects'], $d['limit'], $d['headroom'], $d['stale']));
ok('역량 점수가 섞여 있지 않다',
   !str_contains($r['body'], 'cap_score') && !str_contains($r['body'], 'breadth_score')
   && !str_contains($r['body'], 'fit_score'),
   '점수 칸이 샜다');

// 남의 것은 배정을 짜는 사람만.
$mAdmin = $pdo->query("SELECT id FROM bs_member WHERE user_id='$ADMIN'")->fetch()['id'];
$r = $user->req('/studio/api/rnd.php?act=member_load&member_id=' . (int)$mAdmin);
ok('일반 사용자는 남의 점유 내역을 못 본다', $r['status'] === 403, 'status=' . $r['status']);

$r = $user->req('/studio/api/rnd.php?act=admin_load');
ok('관리자용 현황은 관리자만', $r['status'] === 403, 'status=' . $r['status']);

$r = $admin->req('/studio/api/rnd.php?act=admin_load');
ok('관리자는 본다', $r['status'] === 200);
ok('상한값이 함께 온다', isset($r['json']['data']['limit']['total_cap']));
ok('여기에도 역량 점수가 없다',
   !str_contains($r['body'], 'cap_score') && !str_contains($r['body'], 'breadth_score'),
   '점수 칸이 샜다');

// 후보 표의 가용도가 분해되어 오는지.
$pidP = (int)$pdo->query("SELECT id FROM bs_project WHERE project_type='project'
                           AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch()['id'];
if ($pidP) {
    $r = $admin->req('/studio/api/candidate.php?act=list&project_id=' . $pidP);
    $rows = $r['json']['data']['rows'] ?? [];
    $hasSplit = true;
    foreach ($rows as $row2) {
        $a = $row2['availability'] ?? null;
        if ($a === null) { continue; }
        if (!array_key_exists('project_pct', $a) || !array_key_exists('rnd_pct', $a)) {
            $hasSplit = false;
        } elseif ($a['project_pct'] + $a['rnd_pct'] !== $a['confirmed_pct']) {
            $hasSplit = false;
        }
    }
    ok('후보 표 가용도에 프로젝트/R&D 분해가 실린다', $hasSplit && count($rows) > 0,
       '행 ' . count($rows) . '건');
} else {
    ok('후보 표를 볼 프로젝트가 없어 건너뜀', true);
}

// ---------------------------------------------------------------------
echo "\n[21] 분야 태그 — 역량 반영의 계열 근거 (명세서 §4.7)\n";

$dGrading = (int)$pdo->query("SELECT id FROM bs_domain WHERE category='grading' ORDER BY id LIMIT 1")
                     ->fetch()['id'];
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 태그 붙은 과제', 'visibility' => 'open', 'rnd_category' => 'poc',
    'status' => 'proposed', 'domain_ids' => [$dGrading]]]);
$tagId = (int)($r['json']['data']['id'] ?? 0);
ok('발의할 때 분야 태그를 받는다', $r['status'] === 200, substr($r['body'], 0, 160));

$st = $pdo->prepare('SELECT COUNT(*) AS n FROM bs_rnd_domain WHERE project_id = ?');
$st->execute([$tagId]);
ok('태그가 저장된다', (int)$st->fetch()['n'] === 1);

$r = $user->req('/studio/api/rnd.php?act=get&id=' . $tagId);
ok('상세에 분야가 함께 온다',
   count($r['json']['data']['rnd']['domains'] ?? []) === 1,
   json_encode($r['json']['data']['rnd']['domains'] ?? [], JSON_UNESCAPED_UNICODE));

$r = $user->req('/studio/api/rnd.php?act=update', ['csrf' => true, 'json' => [
    'id' => $tagId, 'domain_ids' => []]]);
ok('태그만 비우는 수정도 된다', $r['status'] === 200, substr($r['body'], 0, 140));
$st->execute([$tagId]);
ok('비워진다', (int)$st->fetch()['n'] === 0);

// ---------------------------------------------------------------------
echo "\n[22] 역량 반영 — R&D 가 들어와도 남의 점수는 흔들리지 않는다 (§4.7)\n";

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 절대 기준이라는 말이 사실인지 본다                                │
   │                                                                  │
   │ 기준값(자)을 R&D 를 포함해 내면, 한 사람의 과제가 분포를 밀어     │
   │ **모두의 점수가 바뀐다.** breadth 분모를 데이터에서 유도해도      │
   │ 마찬가지다 — 새 계열이 하나 생기면 전원의 breadth 가 내려간다.    │
   │                                                                  │
   │ 그래서 산출기를 두 번 돌려 맞대어 본다.                           │
   │   ① R&D 가 하나도 없는 상태                                      │
   │   ② 한 사람에게만 R&D 를 붙인 상태                                │
   │ **R&D 가 없는 사람의 수치는 한 자리도 달라지면 안 된다.**         │
   └──────────────────────────────────────────────────────────────────┘ */

$py = trim((string)@file_get_contents(dirname(__DIR__, 2) . '/studio/dev/php-path.txt'));
$scoreDir = dirname(__DIR__) . '/collector';

/** score.py 를 돌리고 최신 회차의 지표를 가져온다. */
$runScore = function () use ($pdo, $scoreDir): array {
    $cmd = 'cd ' . escapeshellarg($scoreDir) . ' && python score.py --config config.ini 2>&1';
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        return [];
    }
    $st = $pdo->query(
        'SELECT m.emp_name, t.breadth_score, t.cap_score
           FROM bs_member_metric t JOIN bs_member m ON m.id = t.member_id
          WHERE t.eval_ver = (SELECT MAX(id) FROM bs_eval_run)
          ORDER BY m.id'
    );
    $o = [];
    foreach ($st->fetchAll() as $r2) {
        $o[$r2['emp_name']] = [$r2['breadth_score'], $r2['cap_score']];
    }
    return $o;
};

// 시험용 R&D 흔적을 먼저 치운다.
$pdo->exec("DELETE FROM bs_work_item WHERE source = 'rnd'");
$pdo->exec("DELETE FROM bs_project WHERE project_type='rnd' AND code LIKE 'RNDT-%'");

$before = $runScore();
if (!$before) {
    ok('score.py 를 돌릴 수 없어 건너뜀 (python 또는 config 확인)', true);
} else {
    ok('① R&D 없이 산출 성공', count($before) > 0, (string)count($before));

    // 한 사람에게만 R&D 를 붙인다. 참여자는 '시험사용자' 로 한정한다.
    $targetMid = (int)$pdo->query("SELECT id FROM bs_member WHERE user_id='batest-user@bluesoft.co.kr'")
                          ->fetch()['id'];
    $pdo->exec("INSERT INTO bs_project (code, project_type, visibility, name, status,
                    rnd_category, owner_id, owner_name, proposer_id, proposer_name,
                    approved_at, updated_at)
                VALUES ('RNDT-0001','rnd','open','[시험] 역량 반영 과제','done','poc',
                        't@x.kr','시험','t@x.kr','시험',
                        DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY))");
    $tp = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO bs_rnd_domain (project_id, domain_id) VALUES (?,?)')
        ->execute([$tp, $dGrading]);
    $pdo->prepare("INSERT INTO bs_rnd_output (project_id, kind, title) VALUES (?,'report','시험 산출물')")
        ->execute([$tp]);
    $pdo->prepare("INSERT INTO bs_rnd_member (project_id, member_id, role, load_ratio, status,
                       approved_at, joined_at) VALUES (?,?,'member',0.100,'done',NOW(),NOW())")
        ->execute([$tp, $targetMid]);

    $after = $runScore();
    ok('② R&D 를 붙이고 산출 성공', count($after) > 0);

    $st = $pdo->prepare("SELECT COUNT(*) AS n FROM bs_work_item WHERE source='rnd' AND member_id = ?");
    $st->execute([$targetMid]);
    ok('종료·산출물·태그가 갖춰진 과제는 업무 이력으로 적재된다',
       (int)$st->fetch()['n'] === 1);

    // **핵심** — R&D 가 없는 사람은 한 자리도 달라지지 않아야 한다.
    $moved = [];
    foreach ($before as $name => $v) {
        if ($name === '시험사용자') { continue; }   // 유일하게 R&D 를 받은 사람
        $w = $after[$name] ?? null;
        if ($w === null) { $moved[] = $name . '(사라짐)'; continue; }
        if ((string)$v[0] !== (string)$w[0] || (string)$v[1] !== (string)$w[1]) {
            $moved[] = sprintf('%s(%s→%s / %s→%s)', $name, $v[0], $w[0], $v[1], $w[1]);
        }
    }
    ok('R&D 가 없는 사람의 breadth·cap 이 한 자리도 안 바뀐다',
       $moved === [], implode(' ', $moved));

    // 제외 규칙 — dropped / 산출물 없음 / 태그 없음은 반영되지 않는다.
    foreach ([['RNDT-0002', 'dropped', 1, true],
              ['RNDT-0003', 'done', 0, true],
              ['RNDT-0004', 'done', 1, false]] as [$code, $status, $outs, $withDom]) {
        $pdo->exec("INSERT INTO bs_project (code, project_type, visibility, name, status,
                        rnd_category, owner_id, owner_name, proposer_id, proposer_name,
                        approved_at, updated_at)
                    VALUES ('$code','rnd','open','[시험] 제외 $code','$status','poc',
                            't@x.kr','시험','t@x.kr','시험',
                            DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY))");
        $xp = (int)$pdo->lastInsertId();
        if ($withDom) {
            $pdo->prepare('INSERT INTO bs_rnd_domain (project_id, domain_id) VALUES (?,?)')
                ->execute([$xp, $dGrading]);
        }
        for ($i = 0; $i < $outs; $i++) {
            $pdo->prepare("INSERT INTO bs_rnd_output (project_id, kind, title) VALUES (?,'report','x')")
                ->execute([$xp]);
        }
        $pdo->prepare("INSERT INTO bs_rnd_member (project_id, member_id, role, load_ratio, status,
                           approved_at, joined_at) VALUES (?,?,'member',0.100,'done',NOW(),NOW())")
            ->execute([$xp, $targetMid]);
    }
    $runScore();
    $st = $pdo->prepare("SELECT COUNT(*) AS n FROM bs_work_item WHERE source='rnd' AND member_id = ?");
    $st->execute([$targetMid]);
    $nAfterExcl = (int)$st->fetch()['n'];
    ok('중단·산출물 없음·태그 없음은 반영되지 않는다 (여전히 1건)',
       $nAfterExcl === 1, '적재 ' . $nAfterExcl . '건');

    // 삭제된 지표를 되살리지 않았는지.
    $row = $pdo->query('SELECT speed_score, comm_score FROM bs_member_metric
                         WHERE eval_ver = (SELECT MAX(id) FROM bs_eval_run) LIMIT 1')->fetch();
    ok('speed_score 는 여전히 NULL (되살리지 않았다)', $row['speed_score'] === null);
    ok('comm_score 도 NULL', $row['comm_score'] === null);

    // 새 회차로 적재했는지 — 기존 회차를 덮어쓰지 않았다.
    $n = (int)$pdo->query('SELECT COUNT(*) AS n FROM bs_eval_run')->fetch()['n'];
    ok('판정 회차가 새로 쌓인다 (기존을 덮어쓰지 않는다)', $n >= 2, (string)$n);

    $pdo->exec("DELETE FROM bs_project WHERE project_type='rnd' AND code LIKE 'RNDT-%'");
    $pdo->exec("DELETE FROM bs_work_item WHERE source = 'rnd'");
}

// ---------------------------------------------------------------------
echo "\n[23] 리뷰에서 나온 구멍 — 상한 우회와 이름 누출\n";

/* ① 과제 승인 경로의 상한 우회
   승인되면 발의자가 lead 로 앉으며 점유가 올라간다. 그 자리에 상한 검사가
   없으면, 과제를 여러 개 발의해 승인받는 것만으로 상한을 통째로 넘긴다. */
$pdo->exec("DELETE FROM bs_project WHERE project_type='rnd' AND code LIKE 'RNDX-%'");

// 앞 구간이 남긴 참여를 비운다. 여기서는 **발의자 경로만** 재야 한다.
$pdo->exec("DELETE rm FROM bs_rnd_member rm JOIN bs_member m ON m.id = rm.member_id
             WHERE m.user_id = '$USER'");
$pdo->exec("DELETE w FROM bs_workload w JOIN bs_member m ON m.id = w.member_id
             WHERE w.ref_type = 'rnd' AND m.user_id = '$USER'");

$mkOwn = function (string $code, float $cap) use ($pdo, $USER): int {
    $pdo->exec("INSERT INTO bs_project (code, project_type, visibility, name, status,
                    rnd_category, owner_id, owner_name, proposer_id, proposer_name, load_cap)
                VALUES ('$code','rnd','open','[시험] $code','proposed','poc',
                        '$USER','시험사용자','$USER','시험사용자', $cap)");
    return (int)$pdo->lastInsertId();
};

// 상한(0.30) 안에서 두 건까지는 승인된다.
$x1 = $mkOwn('RNDX-0001', 0.15);
$r = $admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $x1]]);
ok('첫 과제 승인 200', $r['status'] === 200, substr($r['body'], 0, 140));

$x2 = $mkOwn('RNDX-0002', 0.15);
$r = $admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $x2]]);
ok('둘째 과제도 승인 200 (합계 0.30, 아직 상한 안)', $r['status'] === 200,
   substr($r['body'], 0, 200));

// 셋째는 총량(0.45 > 0.30)과 동시 건수(3 > 2) 양쪽에 걸린다.
$x3 = $mkOwn('RNDX-0003', 0.15);
$r = $admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $x3]]);
ok('셋째 과제 승인이 상한에 막힌다 (발의자 경로도 검사한다)',
   $r['status'] === 400, 'status=' . $r['status']);
ok('코드는 RND_CAP_EXCEEDED', ($r['json']['error']['code'] ?? '') === 'RND_CAP_EXCEEDED',
   (string)($r['json']['error']['code'] ?? ''));

$st = $pdo->prepare('SELECT status FROM bs_project WHERE id = ?');
$st->execute([$x3]);
ok('막혔으면 과제도 승인되지 않는다', ($st->fetch()['status'] ?? '') === 'proposed');

$st = $pdo->prepare("SELECT COUNT(*) AS n FROM bs_workload WHERE ref_type='rnd' AND ref_id = ?");
$st->execute([$x3]);
ok('점유도 올라가지 않는다', (int)$st->fetch()['n'] === 0);

/* ② 비공개 과제 이름 누출
   가시성을 Repo 에서 막아 놓고, 점유 내역과 근거 목록으로 이름이 샜다. */
$pdo->exec("INSERT INTO bs_project (code, project_type, visibility, name, status,
                rnd_category, owner_id, owner_name, proposer_id, proposer_name,
                load_cap, approved_at)
            VALUES ('RNDX-9001','rnd','private','[시험] 관리자 비밀 과제','approved','poc',
                    '$ADMIN','시험관리자','$ADMIN','시험관리자', 0.100, NOW())");
$secret = (int)$pdo->lastInsertId();
$mAdmin2 = (int)$pdo->query("SELECT id FROM bs_member WHERE user_id='$ADMIN'")->fetch()['id'];
$pdo->prepare("INSERT INTO bs_rnd_member (project_id, member_id, role, load_ratio, status,
                   approved_at, joined_at) VALUES (?,?,'lead',0.100,'approved',NOW(),NOW())")
    ->execute([$secret, $mAdmin2]);
$pdo->prepare("INSERT INTO bs_workload (member_id, kind, ref_type, ref_id, label,
                   start_date, end_date, load_ratio, confidence)
               VALUES (?, 'assigned','rnd', ?, '비밀', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 0.100, 1.000)")
    ->execute([$mAdmin2, $secret]);

// 일반 사용자에게는 배정 권한이 없으므로 애초에 403 이어야 한다.
$r = $user->req('/studio/api/rnd.php?act=member_load&member_id=' . $mAdmin2);
ok('권한 없는 사람은 남의 점유 내역을 못 본다', $r['status'] === 403);

// 관리자는 자기 것이므로 이름을 본다.
$r = $admin->req('/studio/api/rnd.php?act=member_load&member_id=' . $mAdmin2);
ok('관리자는 자기 비공개 과제 이름을 본다',
   str_contains($r['body'], '관리자 비밀 과제'), substr($r['body'], 0, 200));

// 가려진 응답 모양 — 점유율은 남고 이름만 사라져야 한다.
$d23 = $r['json']['data'] ?? [];
$hasRatio = true;
foreach ($d23['projects'] ?? [] as $p23) {
    if (!array_key_exists('load_ratio', $p23)) { $hasRatio = false; }
}
ok('과제마다 점유율이 남는다 (합계가 맞아야 하므로)', $hasRatio);
ok('합계가 과제별 점유율의 합과 같다',
   abs(array_sum(array_column($d23['projects'] ?? [], 'load_ratio')) - (float)($d23['total'] ?? -1)) < 0.001,
   json_encode([$d23['total'] ?? null, array_column($d23['projects'] ?? [], 'load_ratio')]));

$pdo->exec("DELETE FROM bs_project WHERE project_type='rnd' AND code LIKE 'RNDX-%'");

// ---------------------------------------------------------------------
echo "\n[24] 점유 상한 — 관리자가 고치고, 과제의 신고 점유율이 그 과제의 상한 (2026-10-14)\n";

$capKeys = ['rnd_total_cap', 'rnd_per_project_cap', 'rnd_concurrent_max', 'rnd_stale_weeks'];
$capWas  = $pdo->query("SELECT k, v FROM bs_setting WHERE k IN ('" . implode("','", $capKeys) . "')")
               ->fetchAll(PDO::FETCH_KEY_PAIR);

$r = $user->req('/studio/api/setting.php?act=save_rnd_caps', ['csrf' => true, 'json' => [
    'total_pct' => '50', 'project_pct' => '30', 'concurrent' => '3', 'stale_weeks' => '4']]);
ok('관리자가 아니면 못 바꾼다', $r['status'] === 403, 'status=' . $r['status']);

$r = $admin->req('/studio/api/setting.php?act=save_rnd_caps', ['csrf' => true, 'json' => [
    'total_pct' => '0', 'project_pct' => '30', 'concurrent' => '3', 'stale_weeks' => '4']]);
ok('0% 같은 값은 받지 않는다', $r['status'] === 400, substr($r['body'], 0, 120));
$r = $admin->req('/studio/api/setting.php?act=save_rnd_caps', ['csrf' => true, 'json' => [
    'total_pct' => '30', 'project_pct' => '40', 'concurrent' => '3', 'stale_weeks' => '4']]);
ok('과제당이 합계보다 크면 받지 않는다', $r['status'] === 400, substr($r['body'], 0, 120));

$r = $admin->req('/studio/api/setting.php?act=save_rnd_caps', ['csrf' => true, 'json' => [
    'total_pct' => '50', 'project_pct' => '30', 'concurrent' => '3', 'stale_weeks' => '4']]);
ok('관리자는 바꾼다', $r['status'] === 200, substr($r['body'], 0, 120));
$now = $pdo->query("SELECT k, v FROM bs_setting WHERE k IN ('" . implode("','", $capKeys) . "')")
           ->fetchAll(PDO::FETCH_KEY_PAIR);
ok('비율로 저장된다', (float)$now['rnd_total_cap'] === 0.5 && (float)$now['rnd_per_project_cap'] === 0.3
   && (int)$now['rnd_concurrent_max'] === 3, json_encode($now));
ok('누가 바꿨는지 남는다', (string)$pdo->query(
    "SELECT updated_by FROM bs_setting WHERE k = 'rnd_total_cap'")->fetchColumn() === $ADMIN);
$page = $admin->req('/studio/settings.php')['body'];
ok('설정 화면에 상한 칸이 있다', str_contains($page, 'id="ba-caps"') && str_contains($page, 'value="50"'));

// 과제의 신고 점유율(load_cap)이 있으면 그 과제는 그 값을 상한으로 쓴다.
// 판정만 본다 — 서비스를 직접 부른다(설정 캐시가 없는 새 요청이어야 하므로
// 전역 상한은 위에서 바꾼 30% 가 아니라 DB 값을 그대로 읽는다).
require_once dirname(__DIR__) . '/inc/service/RndLoadService.php';
$svc  = new RndLoadService($pdo);
$who  = (int)$pdo->query("SELECT id FROM bs_member WHERE user_id = '$ADMIN'")->fetchColumn();
$lim  = $svc->limits()['per_project_cap'];
$over = round($lim + 0.1, 2);
$pdo->prepare('UPDATE bs_project SET load_cap = NULL WHERE id = ?')->execute([$openId]);
$c0 = $svc->checkCap($who, $openId, $over);
$pdo->prepare('UPDATE bs_project SET load_cap = ? WHERE id = ?')->execute([$over, $openId]);
$c1 = $svc->checkCap($who, $openId, $over);
$codes = static fn($c) => array_column($c['violations'], 'code');
ok('신고 점유율이 없으면 전역 과제당 상한에 걸린다',
   in_array(RndLoadService::CAP_PROJECT, $codes($c0), true), json_encode($codes($c0)));
ok('★ 신고 점유율이 있으면 그 값까지는 과제당 상한에 안 걸린다',
   !in_array(RndLoadService::CAP_PROJECT, $codes($c1), true), json_encode($codes($c1)));
$pdo->prepare('UPDATE bs_project SET load_cap = NULL WHERE id = ?')->execute([$openId]);

// 설정을 시험 전 값으로 되돌린다.
$back = $pdo->prepare('UPDATE bs_setting SET v = ? WHERE k = ?');
foreach ($capWas as $k => $v) { $back->execute([$v, $k]); }

// ---------------------------------------------------------------------
echo "\n[25] 삭제 · 복구 · 영구삭제 (2026-10-16)\n";

// 보드에서 그 과제 한 줄을 집는다.
$rowOf = static function ($who, int $id, bool $withDeleted = false): ?array {
    $q = '/studio/api/rnd.php?act=board&size=100' . ($withDeleted ? '&with_deleted=1' : '');
    foreach ($who->req($q)['json']['data']['rows'] ?? [] as $x) {
        if ((int)$x['id'] === $id) { return $x; }
    }
    return null;
};

// A. 시작 전 과제 — 발의자가 지운다
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 지울 과제', 'visibility' => 'open', 'status' => 'proposed']]);
$delId = (int)($r['json']['data']['id'] ?? 0);
$row = $rowOf($user, $delId);
ok('발의자 카드에 삭제가 있다', in_array('delete', $row['actions'] ?? [], true), json_encode($row['actions'] ?? null));
$r = $admin->req('/studio/api/rnd.php?act=delete', ['csrf' => true, 'json' => ['id' => 0]]);
ok('번호 없으면 400', $r['status'] === 400);
$r = $user->req('/studio/api/rnd.php?act=delete', ['csrf' => true, 'json' => ['id' => $delId, 'reason' => '잘못 올림']]);
ok('발의자가 시작 전 과제를 지운다', $r['status'] === 200, substr($r['body'], 0, 160));
ok('보드에서 사라진다', $rowOf($user, $delId) === null);
ok('★ 행은 남는다 — 삭제 상태일 뿐', (int)$pdo->query(
    "SELECT COUNT(*) FROM bs_project WHERE id = $delId AND deleted_at IS NOT NULL")->fetchColumn() === 1);
ok('누가 왜 지웠는지 남는다', (string)$pdo->query(
    "SELECT CONCAT(deleted_by, '|', delete_reason) FROM bs_project WHERE id = $delId")->fetchColumn()
    === $USER . '|잘못 올림');
ok('일반 사용자는 [삭제 포함] 을 줘도 못 본다', $rowOf($user, $delId, true) === null);
$row = $rowOf($admin, $delId, true);
ok('관리자는 [삭제 포함] 에서 본다', $row !== null && ($row['deleted'] ?? false) === true);
ok('삭제된 과제는 복구·영구삭제만', ($row['actions'] ?? null) === ['restore', 'purge'], json_encode($row['actions'] ?? null));
ok('관리자도 [삭제 포함] 을 끄면 안 보인다', $rowOf($admin, $delId) === null);

// B. 복구
$r = $user->req('/studio/api/rnd.php?act=restore', ['csrf' => true, 'json' => ['id' => $delId]]);
ok('일반 사용자는 복구 못 한다', $r['status'] === 400, 'status=' . $r['status']);
$r = $admin->req('/studio/api/rnd.php?act=restore', ['csrf' => true, 'json' => ['id' => $delId]]);
ok('관리자가 복구한다', $r['status'] === 200, substr($r['body'], 0, 160));
ok('복구하면 보드에 다시 나온다', $rowOf($user, $delId) !== null);

// C. 굴러간 과제 — 발의자는 못 지우고 [중단] 으로 끝낸다. 관리자는 지운다.
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 굴러간 과제', 'visibility' => 'open', 'status' => 'proposed']]);
$runId = (int)($r['json']['data']['id'] ?? 0);
$admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $runId]]);
$r = $user->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $runId, 'content' => '[시험] 해 본 일']]);
ok('발의자가 기록을 남겼다', $r['status'] === 200, substr($r['body'], 0, 160));
$row = $rowOf($user, $runId);
ok('★ 기록이 있으면 발의자 카드에 삭제가 없다', !in_array('delete', $row['actions'] ?? [], true));
ok('★ 대신 카드에 중단이 있다', in_array('drop', $row['actions'] ?? [], true), json_encode($row['actions'] ?? null));
$r = $user->req('/studio/api/rnd.php?act=delete', ['csrf' => true, 'json' => ['id' => $runId]]);
ok('★ 발의자는 굴러간 과제를 못 지운다', $r['status'] === 400, 'status=' . $r['status']);
ok('왜 못 하는지 [중단] 을 알려 준다', str_contains($r['json']['error']['message'] ?? '', '중단'));
$r = $admin->req('/studio/api/rnd.php?act=delete', ['csrf' => true, 'json' => ['id' => $runId]]);
ok('관리자는 굴러간 과제도 지운다', $r['status'] === 200, substr($r['body'], 0, 160));
ok('★ 지우면 점유가 오늘로 닫힌다', (int)$pdo->query(
    "SELECT COUNT(*) FROM bs_workload WHERE ref_type = 'rnd' AND ref_id = $runId AND end_date > CURDATE()")
    ->fetchColumn() === 0);

// D. 영구삭제
$r = $admin->req('/studio/api/rnd.php?act=purge', ['csrf' => true, 'json' => ['id' => $delId]]);
ok('★ 삭제 상태가 아니면 영구삭제 못 한다', $r['status'] === 400, 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd.php?act=purge', ['csrf' => true, 'json' => ['id' => $runId]]);
ok('일반 사용자는 영구삭제 못 한다', $r['status'] === 400, 'status=' . $r['status']);
$r = $admin->req('/studio/api/rnd.php?act=purge', ['csrf' => true, 'json' => ['id' => $runId]]);
ok('관리자가 영구삭제한다', $r['status'] === 200, substr($r['body'], 0, 160));
ok('과제 행이 없어진다', (int)$pdo->query("SELECT COUNT(*) FROM bs_project WHERE id = $runId")->fetchColumn() === 0);
ok('기록·참여자도 함께 없어진다', (int)$pdo->query(
    "SELECT (SELECT COUNT(*) FROM bs_rnd_log WHERE project_id = $runId)
          + (SELECT COUNT(*) FROM bs_rnd_member WHERE project_id = $runId)")->fetchColumn() === 0);
ok('★ 점유 행도 남지 않는다', (int)$pdo->query(
    "SELECT COUNT(*) FROM bs_workload WHERE ref_type = 'rnd' AND ref_id = $runId")->fetchColumn() === 0);

// E. 화면 — 상세 머리의 삭제, 보드의 [삭제 포함]
$page = $admin->req('/studio/rnd_board.php')['body'];
ok('관리자 보드에 [삭제 포함] 이 있다', str_contains($page, 'id="ba-r-deleted"'));
ok('일반 사용자 보드에는 없다', !str_contains($user->req('/studio/rnd_board.php')['body'], 'id="ba-r-deleted"'));
$d = $user->req('/studio/api/rnd.php?act=get&id=' . $delId)['json']['data']['rnd'] ?? [];
ok('상세에도 삭제 판정이 실린다', ($d['can']['delete'] ?? null) === true, json_encode($d['can'] ?? null));

// ---------------------------------------------------------------------
echo "\n[26] 참고 자료 (2026-10-19)\n";

// 1x1 PNG — 업로드 검사(확장자·내용 일치)를 통과하는 진짜 그림.
$pngPath = sys_get_temp_dir() . '/bs_rnd_ref_' . getmypid() . '.png';
file_put_contents($pngPath, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

// 관리자가 발의한 비공개 과제 — 일반 사용자는 볼 수 없다.
$r = $admin->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 참고자료 과제', 'visibility' => 'private', 'status' => 'proposed']]);
$refRnd = (int)($r['json']['data']['id'] ?? 0);

$r = $admin->req('/studio/api/rnd.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $refRnd, 'source_type' => 'text', 'text' => "조사 메모\n경쟁 제품 셋을 봤다"]]);
ok('직접 입력 자료를 올린다', $r['status'] === 200, substr($r['body'], 0, 160));
$txtSrc = (int)($r['json']['data']['saved'][0]['id'] ?? 0);

$r = $admin->req('/studio/api/rnd.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $refRnd, 'source_type' => 'link', 'url' => 'https://www.figma.com/file/abc/Ref']]);
ok('링크 자료를 올린다', $r['status'] === 200, substr($r['body'], 0, 160));

$r = $admin->req('/studio/api/rnd.php?act=upload_source', ['csrf' => true, 'form' => [
    'project_id' => $refRnd, 'source_type' => 'file',
    'files[]' => new CURLFile($pngPath, 'image/png', '화면시안.png')]]);
ok('파일 자료를 올린다', $r['status'] === 200 && count($r['json']['data']['saved'] ?? []) === 1,
   substr($r['body'], 0, 200));
$pngSrc = (int)($r['json']['data']['saved'][0]['id'] ?? 0);

$d = $admin->req('/studio/api/rnd.php?act=get&id=' . $refRnd)['json']['data'] ?? [];
$srcs = $d['sources'] ?? [];
ok('상세에 자료 3건이 실린다', count($srcs) === 3, json_encode(array_column($srcs, 'title')));
ok('★ 모두 참고 자료(보관용)로 들어간다',
   array_unique(array_column($srcs, 'purpose')) === ['reference'], json_encode(array_column($srcs, 'purpose')));
$png = null;
foreach ($srcs as $s) { if ((int)$s['id'] === $pngSrc) { $png = $s; } }
ok('그림은 보기·내려받기가 된다', ($png['has_file'] ?? false) && ($png['viewable'] ?? false), json_encode($png));

$r = $admin->req('/studio/api/source_download.php?id=' . $pngSrc);
ok('볼 수 있는 사람은 내려받는다', $r['status'] === 200 && $r['body'] === file_get_contents($pngPath),
   'status=' . $r['status']);
$r = $user->req('/studio/api/source_download.php?id=' . $pngSrc);
ok('★ 비공개 과제를 못 보는 사람은 못 받는다', $r['status'] === 404, 'status=' . $r['status']);
$r = $user->req('/studio/api/project.php?act=source_text&id=' . $txtSrc);
ok('★ 본문도 못 본다', $r['status'] === 404, 'status=' . $r['status']);
$r = $admin->req('/studio/api/project.php?act=source_text&id=' . $txtSrc);
ok('볼 수 있는 사람은 본문을 본다', str_contains($r['json']['data']['text'] ?? '', '경쟁 제품'), substr($r['body'], 0, 120));

$r = $user->req('/studio/api/rnd.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $refRnd, 'source_type' => 'text', 'text' => '남의 과제']]);
ok('★ 못 보는 과제에는 못 올린다', $r['status'] === 404, 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd.php?act=delete_source', ['csrf' => true, 'json' => ['source_id' => $txtSrc]]);
ok('★ 못 보는 과제의 자료는 못 지운다', $r['status'] === 404, 'status=' . $r['status']);
$r = $user->req('/studio/api/project.php?act=delete_source', ['csrf' => true, 'json' => ['source_id' => $txtSrc]]);
ok('★ 프로젝트 API 로 돌아가도 못 지운다', $r['status'] === 403, 'status=' . $r['status']);

// 내 과제에는 올린다
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 내 참고자료 과제', 'visibility' => 'open', 'status' => 'proposed']]);
$myRnd = (int)($r['json']['data']['id'] ?? 0);
$r = $user->req('/studio/api/rnd.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $myRnd, 'source_type' => 'text', 'text' => '내 메모']]);
ok('발의자는 자기 과제에 올린다', $r['status'] === 200, substr($r['body'], 0, 160));

// 화면
$page = $admin->req('/studio/rnd_form.php?id=' . $refRnd)['body'];
ok('수정 화면에 참고 자료 패널이 있다',
   str_contains($page, 'data-src-panel="reference"') && str_contains($page, 'data-src-api="api/rnd.php"'));
ok('수정 화면 목록에 보기·내려받기', str_contains($page, 'data-src-view="' . $pngSrc . '"')
   && str_contains($page, 'source_download.php?id=' . $pngSrc . '"'));
ok('새 과제 화면은 먼저 저장하라고 안내한다',
   str_contains($admin->req('/studio/rnd_form.php')['body'], '과제를 먼저 저장하면'));
ok('상세 화면에 보기 창이 있다', str_contains($admin->req('/studio/rnd_view.php?id=' . $refRnd)['body'], 'id="ba-src-viewer"'));

// 지우기 — 파일도 함께
$path = (string)$pdo->query("SELECT file_path FROM bs_project_source WHERE id = $pngSrc")->fetchColumn();
$r = $admin->req('/studio/api/rnd.php?act=delete_source', ['csrf' => true, 'json' => ['source_id' => $pngSrc]]);
ok('자료를 지운다', $r['status'] === 200 && count($r['json']['data']['sources'] ?? []) === 2, substr($r['body'], 0, 160));
ok('올린 파일도 지워진다', $path !== '' && !is_file($path), $path);
@unlink($pngPath);

echo "\n[27] 진행 기록·산출물 고치기·지우기, 첨부 (2026-10-19)\n";

$png2 = sys_get_temp_dir() . '/bs_rnd_att_' . getmypid() . '.png';
file_put_contents($png2, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

// 일반 사용자가 발의 → 관리자 승인. 발의자는 승인된 주도자다.
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 첨부 과제', 'visibility' => 'private', 'status' => 'proposed']]);
$attRnd = (int)($r['json']['data']['id'] ?? 0);
$admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $attRnd]]);

// --- 진행 기록 ---
$r = $user->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $attRnd, 'content' => '[시험] 처음 기록', 'finding' => '알아낸 것']]);
$logId = (int)($r['json']['data']['log_id'] ?? 0);
ok('기록을 남기면 새 번호를 돌려준다', $logId > 0, substr($r['body'], 0, 160));
$lg = $r['json']['data']['logs'][0] ?? [];
ok('작성자에게 고치기 권한이 실린다', ($lg['can']['edit'] ?? null) === true, json_encode($lg['can'] ?? null));

$r = $user->req('/studio/api/rnd.php?act=file_upload', ['csrf' => true, 'form' => [
    'owner_type' => 'log', 'owner_id' => $logId,
    'files[]' => new CURLFile($png2, 'image/png', '실험결과.png')]]);
ok('기록에 파일을 붙인다', $r['status'] === 200 && count($r['json']['data']['saved'] ?? []) === 1, substr($r['body'], 0, 200));
$lg  = $r['json']['data']['logs'][0] ?? [];
$fid = (int)($lg['files'][0]['id'] ?? 0);
ok('기록 목록에 첨부가 실린다', $fid > 0 && ($lg['files'][0]['viewable'] ?? false) === true
   && ($lg['files'][0]['name'] ?? '') === '실험결과.png', json_encode($lg['files'] ?? null));
ok('첨부에 실경로를 싣지 않는다', !str_contains($r['body'], 'file_path') && !str_contains($r['body'], 'var\/source'));

$r = $user->req('/studio/api/rnd_file.php?id=' . $fid);
ok('첨부를 내려받는다', $r['status'] === 200 && $r['body'] === file_get_contents($png2), 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd_file.php?id=' . $fid . '&inline=1');
ok('그림은 바로 보기로도 열린다', $r['status'] === 200);
$r = $anon->req('/studio/api/rnd_file.php?id=' . $fid);
ok('로그인 안 하면 401', $r['status'] === 401, 'status=' . $r['status']);

$r = $user->req('/studio/api/rnd.php?act=log_update', ['csrf' => true, 'json' => [
    'log_id' => $logId, 'content' => '[시험] 고친 기록', 'finding' => '', 'worked_on' => '2026-10-18']]);
ok('작성자가 기록을 고친다', $r['status'] === 200, substr($r['body'], 0, 160));
$lg = $r['json']['data']['logs'][0] ?? [];
ok('고친 내용이 반영된다', ($lg['content'] ?? '') === '[시험] 고친 기록' && ($lg['worked_on'] ?? '') === '2026-10-18');
ok('누가 언제 고쳤는지 남는다', !empty($lg['updated_at']) && !empty($lg['updated_by_name']), json_encode($lg));
$r = $user->req('/studio/api/rnd.php?act=log_update', ['csrf' => true, 'json' => ['log_id' => $logId, 'content' => '  ']]);
ok('내용이 비면 거절', $r['status'] === 400);

// --- 산출물 ---
$r = $user->req('/studio/api/rnd.php?act=output', ['csrf' => true, 'json' => [
    'id' => $attRnd, 'kind' => 'doc', 'title' => '[시험] 결과 보고서']]);
$outId = (int)($r['json']['data']['output_id'] ?? 0);
ok('산출물을 등록하면 새 번호를 돌려준다', $outId > 0, substr($r['body'], 0, 160));
$r = $user->req('/studio/api/rnd.php?act=file_upload', ['csrf' => true, 'form' => [
    'owner_type' => 'output', 'owner_id' => $outId,
    'files[]' => new CURLFile($png2, 'image/png', '보고서표지.png')]]);
ok('산출물에 파일을 붙인다', $r['status'] === 200 && count($r['json']['data']['saved'] ?? []) === 1, substr($r['body'], 0, 200));
$ofid = (int)($r['json']['data']['outputs'][0]['files'][0]['id'] ?? 0);
$opath = (string)$pdo->query("SELECT file_path FROM bs_rnd_file WHERE id = $ofid")->fetchColumn();

$r = $user->req('/studio/api/rnd.php?act=output_update', ['csrf' => true, 'json' => [
    'output_id' => $outId, 'kind' => 'report', 'title' => '[시험] 최종 보고서', 'url' => 'ftp://x']]);
ok('http(s) 아닌 주소는 거절', $r['status'] === 400);
$r = $user->req('/studio/api/rnd.php?act=output_update', ['csrf' => true, 'json' => [
    'output_id' => $outId, 'kind' => 'report', 'title' => '[시험] 최종 보고서', 'url' => '']]);
ok('작성자가 산출물을 고친다', $r['status'] === 200
   && ($r['json']['data']['outputs'][0]['title'] ?? '') === '[시험] 최종 보고서', substr($r['body'], 0, 160));

// 시험 관리자 계정은 앞 시험들로 R&D 점유가 차 있어 자기 발의를 승인하면 상한에 걸린다.
// 여기서 보려는 것은 첨부·권한이므로, 승인 상태(과제 approved + 주도자 행)를 DB 로 만든다.
$forceApprove = static function (int $id) use ($pdo, $ADMIN): void {
    $mid = (int)$pdo->query("SELECT id FROM bs_member WHERE user_id = " . $pdo->quote($ADMIN))->fetchColumn();
    $pdo->prepare("UPDATE bs_project SET status = 'approved', approved_at = NOW() WHERE id = ?")->execute([$id]);
    $pdo->prepare("INSERT INTO bs_rnd_member (project_id, member_id, role, load_ratio, status, join_reason, approved_at, joined_at)
                   VALUES (?, ?, 'lead', 0.1, 'approved', '발의자', NOW(), NOW())
                   ON DUPLICATE KEY UPDATE role = 'lead', status = 'approved'")->execute([$id, $mid]);
};

// --- 남은 못 한다 · 관리자는 한다 ---
$r = $admin->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 관리자 과제', 'visibility' => 'open', 'status' => 'proposed']]);
$admRnd = (int)($r['json']['data']['id'] ?? 0);
$forceApprove($admRnd);
$r = $admin->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => ['id' => $admRnd, 'content' => '[시험] 관리자 기록']]);
$admLog = (int)($r['json']['data']['log_id'] ?? 0);
$lg = $user->req('/studio/api/rnd.php?act=get&id=' . $admRnd)['json']['data']['logs'][0] ?? [];
ok('★ 남의 기록에는 고치기 권한이 안 실린다', ($lg['can']['edit'] ?? null) === false, json_encode($lg['can'] ?? null));
$r = $user->req('/studio/api/rnd.php?act=log_update', ['csrf' => true, 'json' => ['log_id' => $admLog, 'content' => '가로채기']]);
ok('★ 남의 기록은 못 고친다', $r['status'] === 400, 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd.php?act=log_delete', ['csrf' => true, 'json' => ['log_id' => $admLog]]);
ok('★ 남의 기록은 못 지운다', $r['status'] === 400, 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd.php?act=file_upload', ['csrf' => true, 'form' => [
    'owner_type' => 'log', 'owner_id' => $admLog, 'files[]' => new CURLFile($png2, 'image/png', 'x.png')]]);
ok('★ 남의 기록에는 파일을 못 붙인다', $r['status'] === 400, 'status=' . $r['status']);
$r = $admin->req('/studio/api/rnd.php?act=log_update', ['csrf' => true, 'json' => [
    'log_id' => $logId, 'content' => '[시험] 관리자가 고침']]);
ok('관리자는 남의 기록도 고친다', $r['status'] === 200, substr($r['body'], 0, 160));

// --- 비공개 과제의 첨부는 못 보는 사람에게 새지 않는다 ---
// 관리자 비공개 과제에 관리자가 붙인 파일을 일반 사용자가 받으려 한다.
$r = $admin->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 관리자 비공개', 'visibility' => 'private', 'status' => 'proposed']]);
$prvRnd = (int)($r['json']['data']['id'] ?? 0);
$forceApprove($prvRnd);
$r = $admin->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => ['id' => $prvRnd, 'content' => '[시험] 비공개 기록']]);
$prvLog = (int)($r['json']['data']['log_id'] ?? 0);
$r = $admin->req('/studio/api/rnd.php?act=file_upload', ['csrf' => true, 'form' => [
    'owner_type' => 'log', 'owner_id' => $prvLog, 'files[]' => new CURLFile($png2, 'image/png', '비밀.png')]]);
$prvFile = (int)($r['json']['data']['logs'][0]['files'][0]['id'] ?? 0);
$r = $user->req('/studio/api/rnd_file.php?id=' . $prvFile);
ok('★ 비공개 과제의 첨부는 못 보는 사람이 못 받는다', $prvFile > 0 && $r['status'] === 404, 'status=' . $r['status']);

// --- 지우기 — 파일도 함께 ---
$r = $user->req('/studio/api/rnd.php?act=file_delete', ['csrf' => true, 'json' => ['file_id' => $fid]]);
ok('붙인 파일 하나를 지운다', $r['status'] === 200 && ($r['json']['data']['logs'][0]['files'] ?? null) === []);
$r = $user->req('/studio/api/rnd.php?act=output_delete', ['csrf' => true, 'json' => ['output_id' => $outId]]);
ok('작성자가 산출물을 지운다', $r['status'] === 200 && ($r['json']['data']['outputs'] ?? null) === [], substr($r['body'], 0, 160));
ok('★ 산출물을 지우면 붙은 파일도 지워진다', $opath !== '' && !is_file($opath)
   && (int)$pdo->query("SELECT COUNT(*) FROM bs_rnd_file WHERE id = $ofid")->fetchColumn() === 0, $opath);
$r = $user->req('/studio/api/rnd.php?act=log_delete', ['csrf' => true, 'json' => ['log_id' => $logId]]);
ok('작성자가 기록을 지운다', $r['status'] === 200 && ($r['json']['data']['logs'] ?? null) === [], substr($r['body'], 0, 160));

// 화면
$page = $user->req('/studio/rnd_view.php?id=' . $attRnd)['body'];
ok('상세 화면에 보기 창이 있다', str_contains($page, 'id="ba-src-viewer"'));
@unlink($png2);

echo "\n[28] 긴 링크 (030, 2026-10-19)\n";
// 문서 뷰어 주소는 700자를 넘는다. 전에는 산출물 링크가 DB 에서 거절돼 500 이 났다.
$longUrl  = 'https://docviewer.worksmobile.com/rl/24101/officeview?x=' . str_repeat('A', 720);
$tooLong  = 'https://a.com/?x=' . str_repeat('B', 2100);
$r = $user->req('/studio/api/rnd.php?act=propose', ['csrf' => true, 'json' => [
    'name' => '[시험] 긴 링크 과제', 'visibility' => 'open', 'status' => 'proposed']]);
$urlRnd = (int)($r['json']['data']['id'] ?? 0);
$admin->req('/studio/api/rnd.php?act=approve', ['csrf' => true, 'json' => ['id' => $urlRnd]]);
$r = $user->req('/studio/api/rnd.php?act=output', ['csrf' => true, 'json' => [
    'id' => $urlRnd, 'kind' => 'doc', 'title' => '[시험] 긴 링크', 'url' => $longUrl]]);
ok('★ 700자 넘는 산출물 링크도 저장된다', $r['status'] === 200, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 120));
ok('잘리지 않고 그대로', ($r['json']['data']['outputs'][0]['url'] ?? '') === $longUrl);
$r = $user->req('/studio/api/rnd.php?act=output', ['csrf' => true, 'json' => [
    'id' => $urlRnd, 'kind' => 'doc', 'title' => '[시험] 너무 긴 링크', 'url' => $tooLong]]);
ok('★ 2000자를 넘으면 500 이 아니라 400 으로 이유를 알린다',
   $r['status'] === 400 && str_contains($r['json']['error']['message'] ?? '', '2,000자'), 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd.php?act=upload_source', ['csrf' => true, 'json' => [
    'project_id' => $urlRnd, 'source_type' => 'link', 'url' => $longUrl]]);
ok('★ 참고 자료 링크도 700자를 넘어도 된다', $r['status'] === 200, substr($r['body'], 0, 120));

echo "\n[29] 진행 기록 링크 (031, 2026-10-19)\n";
$r = $user->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $urlRnd, 'content' => '[시험] 링크 기록', 'url' => $longUrl]]);
ok('★ 진행 기록에 링크를 붙인다(700자 넘어도)', $r['status'] === 200
   && ($r['json']['data']['logs'][0]['url'] ?? '') === $longUrl, substr($r['body'], 0, 160));
$luId = (int)($r['json']['data']['log_id'] ?? 0);
$r = $user->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $urlRnd, 'content' => '[시험] 이상한 링크', 'url' => 'javascript:alert(1)']]);
ok('http(s) 아닌 링크는 거절', $r['status'] === 400, 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd.php?act=log', ['csrf' => true, 'json' => [
    'id' => $urlRnd, 'content' => '[시험] 너무 긴 링크', 'url' => $tooLong]]);
ok('2000자를 넘으면 이유를 알린다', $r['status'] === 400
   && str_contains($r['json']['error']['message'] ?? '', '2,000자'), 'status=' . $r['status']);
$r = $user->req('/studio/api/rnd.php?act=log_update', ['csrf' => true, 'json' => [
    'log_id' => $luId, 'content' => '[시험] 링크 기록', 'url' => '']]);
$lg = null;
foreach ($r['json']['data']['logs'] ?? [] as $x) { if ((int)$x['id'] === $luId) { $lg = $x; } }
ok('고칠 때 링크를 비우면 지워진다', $r['status'] === 200 && $lg !== null && $lg['url'] === null, json_encode($lg));

echo "\n[뒷정리]\n";
$pdo->exec("DELETE FROM bs_notification WHERE ref_type = 'rnd_member'");
$n = $pdo->exec("DELETE FROM bs_project WHERE project_type = 'rnd' AND name LIKE '[시험]%'");
echo "  시험용 과제 {$n}건 삭제\n";

echo "\n" . str_repeat('=', 62) . "\n통과 $pass / 실패 $fail\n";
exit($fail > 0 ? 1 : 0);

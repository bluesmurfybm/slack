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

$row = $pdo->prepare('SELECT COUNT(*) AS n FROM bs_workload WHERE ref_type = ?');
$row->execute(['rnd']);
ok('bs_workload 에 아무것도 적재되지 않았다 (합류는 P9-3)',
   (int)$row->fetch()['n'] === 0);

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
echo "\n[뒷정리]\n";
$n = $pdo->exec("DELETE FROM bs_project WHERE project_type = 'rnd' AND name LIKE '[시험]%'");
echo "  시험용 과제 {$n}건 삭제\n";

echo "\n" . str_repeat('=', 62) . "\n통과 $pass / 실패 $fail\n";
exit($fail > 0 ? 1 : 0);

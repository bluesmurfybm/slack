<?php
/** 아이디어·제안 시험 (032) — 익명, 평가 공개 범위, 피드백, 알림, 검토·묶기, 과제·프로젝트로 올리기. */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ HTTP 로 시험한다 — rnd_test.php 와 같은 까닭                      │
   │                                                                  │
   │ "개별 점수는 본인만", "익명은 관리자만 작성자를 본다" 는 사람마다 │
   │ 다르게 보여야 맞는 것이라, 로그인 세션 넷을 띄워 서로 엿본다.     │
   │                                                                  │
   │ 먼저 서버를 띄워야 한다:  studio\dev\serve.bat                    │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('명령줄에서만 실행할 수 있습니다.');
}

$BASE = getenv('BS_TEST_BASE') ?: 'http://127.0.0.1:8099';

// api_test·rnd_test 와 같은 비밀번호. 다르면 번갈아 돌릴 때 서로 덮어쓴다.
const TEST_PASSWORD = 'ba-test-1234';
$ADMIN = 'batest-admin@bluesoft.co.kr';
$USER  = 'batest-user@bluesoft.co.kr';
$U2    = 'batest-user2@bluesoft.co.kr';
$U3    = 'batest-user3@bluesoft.co.kr';

final class IdeaClient
{
    private ?string $csrf = null;
    private string $jar;

    public function __construct(private string $base, string $tag)
    {
        $this->jar = sys_get_temp_dir() . "/bs_idea_$tag.txt";
        @unlink($this->jar);
    }

    public function get(string $act, array $q = []): array
    {
        return $this->req('/studio/api/idea.php?' . http_build_query(['act' => $act] + $q));
    }

    public function post(string $act, array $body, string $api = 'idea'): array
    {
        return $this->req("/studio/api/$api.php?act=$act", ['json' => $body, 'csrf' => true]);
    }

    public function req(string $path, array $o = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 30,
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
            fwrite(STDERR, "\n[연결 실패] " . curl_error($ch) . "\n서버가 떠 있습니까?  studio\\dev\\serve.bat\n");
            exit(2);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$body, 'json' => json_decode((string)$body, true)];
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

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function d(array $r): array { return $r['json']['data'] ?? []; }
function err(array $r): string { return (string)($r['json']['error']['message'] ?? substr($r['body'], 0, 160)); }

// =====================================================================
// 준비
// =====================================================================
$cfg = require dirname(__DIR__, 2) . '/config.php';
$dbc = $cfg['db'];
if (!in_array($dbc['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "\n[중단] config.php 의 DB 가 로컬이 아닙니다 ({$dbc['host']}).\n");
    exit(2);
}
$pdo = new PDO("mysql:host={$dbc['host']};port={$dbc['port']};dbname={$dbc['name']};charset=utf8mb4",
    $dbc['user'], $dbc['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);
$ins  = $pdo->prepare('INSERT INTO portal_users (name, email, pw_hash, color, created_at) VALUES (?,?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE pw_hash = VALUES(pw_hash)');
foreach ([[$ADMIN, '시험관리자'], [$USER, '시험사용자'], [$U2, '시험둘'], [$U3, '시험셋']] as [$e, $n]) {
    $ins->execute([$n, $e, $hash, '#5A667F']);
}
$pdo->prepare('INSERT INTO portal_admin (email, added_by, created_at) VALUES (?,?,NOW())
               ON DUPLICATE KEY UPDATE email = email')->execute([$ADMIN, 'idea_test.php']);
foreach ([$USER, $U2, $U3] as $e) {
    $pdo->prepare('DELETE FROM portal_admin WHERE email = ?')->execute([$e]);
}
$mem = $pdo->prepare('INSERT INTO bs_member (user_id, emp_name, is_assignable) VALUES (?,?,0)
                      ON DUPLICATE KEY UPDATE emp_name = VALUES(emp_name)');
foreach ([[$ADMIN, '시험관리자'], [$USER, '시험사용자'], [$U2, '시험둘'], [$U3, '시험셋']] as [$e, $n]) {
    $mem->execute([$e, $n]);
}
// 시험사용자는 프로젝트 등록 권한이 없다 — 건너뛰기(아이디어 → 프로젝트)가 막혀야 한다.
$pdo->prepare('UPDATE bs_member SET can_create_project = 0 WHERE user_id = ?')->execute([$USER]);

$cleanup = static function () use ($pdo, $ADMIN, $USER, $U2, $U3): void {
    $pdo->exec("UPDATE bs_project SET origin_idea_id = NULL, origin_project_id = NULL WHERE name LIKE '[시험아이디어]%'");
    $pdo->exec("DELETE FROM bs_project WHERE name LIKE '[시험아이디어]%'");
    $pdo->exec("DELETE FROM bs_idea WHERE title LIKE '[시험아이디어]%'");
    $ph = implode(',', array_fill(0, 4, '?'));
    $pdo->prepare("DELETE n FROM portal_notification n JOIN portal_users u ON u.id = n.user_id
                    WHERE u.email IN ($ph) AND n.kind LIKE 'studio.idea%'")->execute([$ADMIN, $USER, $U2, $U3]);
};
$cleanup();

$admin = new IdeaClient($BASE, 'admin');
$user  = new IdeaClient($BASE, 'user');
$u2    = new IdeaClient($BASE, 'u2');
$u3    = new IdeaClient($BASE, 'u3');
$anon  = new IdeaClient($BASE, 'anon');
foreach ([[$admin, $ADMIN], [$user, $USER], [$u2, $U2], [$u3, $U3]] as [$c, $e]) {
    if (!$c->login($e)) {
        fwrite(STDERR, "\n[중단] $e 로그인 실패. 서버가 떠 있는지 확인하십시오.\n");
        exit(2);
    }
}
$unread = static function (string $email, string $kind) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM portal_notification n JOIN portal_users u ON u.id = n.user_id
                          WHERE u.email = ? AND n.kind = ? AND n.read_at IS NULL");
    $st->execute([$email, $kind]);
    return (int)$st->fetchColumn();
};

// 읽음 여부와 상관없이 받은 알림 수. 상세를 열면 읽음이 되므로 받았는지는 이걸로 본다.
$received = static function (string $email, string $kind, string $title) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM portal_notification n JOIN portal_users u ON u.id = n.user_id
                          WHERE u.email = ? AND n.kind = ? AND n.title LIKE ?");
    $st->execute([$email, $kind, $title]);
    return (int)$st->fetchColumn();
};

echo "\nBlueStudio 아이디어 시험  ($BASE)\n" . str_repeat('=', 62) . "\n";

// =====================================================================
echo "\n[1] 올리기 — 검증·채번·익명\n";
// =====================================================================
ok('로그인 안 하면 못 본다', $anon->get('board')['status'] === 401);
$r = $user->post('create', ['title' => '', 'proposal' => 'x']);
ok('제목 없으면 400', $r['status'] === 400, err($r));
$r = $user->post('create', ['title' => '[시험아이디어] 빈 본문']);
ok('문제·제안 둘 다 비면 400', $r['status'] === 400 && str_contains(err($r), '하나는'), err($r));
$r = $user->post('create', ['title' => '[시험아이디어] 링크', 'proposal' => 'x', 'url' => 'javascript:alert(1)']);
ok('http(s) 아닌 링크는 400', $r['status'] === 400, err($r));
$r = $user->post('create', ['title' => '[시험아이디어] 갈래', 'proposal' => 'x', 'rnd_category' => 'nope']);
ok('없는 갈래는 400', $r['status'] === 400, err($r));

$domainId = (int)$pdo->query('SELECT id FROM bs_domain ORDER BY id LIMIT 1')->fetchColumn();
$r = $user->post('create', [
    'title' => '[시험아이디어] 출석부 자동 알림', 'problem' => '결석 학생을 늦게 안다',
    'proposal' => '3회 결석 시 담당 교수에게 알림', 'rnd_category' => 'enhance',
    'domain_ids' => [$domainId], 'url' => 'https://example.com/a',
]);
ok('올린다', $r['status'] === 200, err($r));
$A = (int)(d($r)['id'] ?? 0);
$ideaA = d($r)['idea'] ?? [];
ok('번호는 IDEA-연도-NNN', (bool)preg_match('/^IDEA-\d{4}-\d{3}$/', $ideaA['code'] ?? ''), $ideaA['code'] ?? '');
ok('갈래·분야가 붙는다', ($ideaA['category_label'] ?? '') === '고도화' && count($ideaA['domains'] ?? []) === 1);
ok('제안됨으로 시작', ($ideaA['status'] ?? '') === 'open');

$r = $user->post('create', ['title' => '[시험아이디어] 익명 제안', 'proposal' => '회의를 줄이자', 'is_anonymous' => 1]);
$ANON = (int)(d($r)['id'] ?? 0);
ok('익명으로 올린다', $r['status'] === 200 && str_contains(d($r)['message'] ?? '', '익명'));
ok('본인은 자기 이름을 본다', (d($user->get('get', ['id' => $ANON]))['idea']['author_name'] ?? '') === '시험사용자');
ok('★ 남은 "익명" 만 본다', (d($u2->get('get', ['id' => $ANON]))['idea']['author_name'] ?? '') === '익명');
ok('관리자는 작성자를 본다', (d($admin->get('get', ['id' => $ANON]))['idea']['author_name'] ?? '') === '시험사용자');
ok('★ 응답 어디에도 작성자 이메일이 없다', !str_contains($u2->get('get', ['id' => $ANON])['body'], $USER));
$b = d($u2->get('board', ['q' => '시험사용자']));
ok('★ 작성자 이름으로 검색해 익명을 찾아낼 수 없다',
   !in_array($ANON, array_column($b['rows'] ?? [], 'id'), true));

// =====================================================================
echo "\n[2] 보드 — 검색·필터·건수\n";
// =====================================================================
$b = d($u2->get('board', ['q' => '[시험아이디어]']));
ok('검색으로 찾는다', count($b['rows'] ?? []) >= 2, (string)count($b['rows'] ?? []));
ok('상태별 건수를 준다', isset($b['counts']['open']) && $b['counts']['open'] >= 2);
$b = d($user->get('board', ['mine' => 1, 'q' => '[시험아이디어]']));
ok('내 아이디어만', count($b['rows'] ?? []) === 2 && !array_filter($b['rows'], fn($x) => !$x['is_mine']));
$b = d($u2->get('board', ['category' => 'enhance', 'q' => '[시험아이디어]']));
ok('갈래로 거른다', array_column($b['rows'] ?? [], 'id') === [$A]);
ok('목록은 본문 대신 앞부분만', isset($b['rows'][0]['excerpt']) && !isset($b['rows'][0]['proposal']));
$r = $u2->get('similar', ['title' => '출석부 알림 개선']);
ok('비슷한 제목을 찾아 준다', in_array($A, array_column(d($r)['rows'] ?? [], 'id'), true));

// =====================================================================
echo "\n[3] 고치기\n";
// =====================================================================
$r = $u2->post('update', ['id' => $A, 'title' => '[시험아이디어] 남이 고침']);
ok('남의 아이디어는 못 고친다', $r['status'] === 400, err($r));
$r = $user->post('update', ['id' => $A, 'effect' => '결석률 감소']);
ok('올린 사람은 고친다', $r['status'] === 200 && (d($r)['idea']['effect'] ?? '') === '결석률 감소', err($r));
ok('넘어오지 않은 칸은 그대로', (d($r)['idea']['proposal'] ?? '') === '3회 결석 시 담당 교수에게 알림');

// =====================================================================
echo "\n[4] 평가 — 두 축, 본인 것만, 셋부터 평균\n";
// =====================================================================
$r = $user->post('rate', ['id' => $A, 'value' => 5, 'feasibility' => 5]);
ok('★ 자기 아이디어는 평가 못 한다', $r['status'] === 400, err($r));
$r = $u2->post('rate', ['id' => $A, 'value' => 6, 'feasibility' => 3]);
ok('1~5 밖은 400', $r['status'] === 400, err($r));
$r = $u2->post('rate', ['id' => $A, 'value' => 4, 'feasibility' => 2, 'opinion' => '좋은데 학사 연동이 어렵다']);
ok('평가한다', $r['status'] === 200, err($r));
$rt = d($r)['rating'] ?? [];
ok('★ 한 명이면 평균을 감춘다', ($rt['count'] ?? 0) === 1 && $rt['shown'] === false && $rt['value'] === null,
   json_encode($rt));
ok('내 점수는 내가 본다', ($rt['mine']['value'] ?? 0) === 4 && ($rt['mine']['feasibility'] ?? 0) === 2);
$u3->post('rate', ['id' => $A, 'value' => 2, 'feasibility' => 4]);
$rt = d($u3->get('get', ['id' => $A]))['idea']['rating'] ?? [];
ok('★ 둘이어도 감춘다(내 점수를 빼면 남의 점수가 보인다)', $rt['count'] === 2 && $rt['value'] === null);
ok('★ 남의 점수는 내게 안 온다', ($rt['mine']['value'] ?? 0) === 2);
$admin->post('rate', ['id' => $A, 'value' => 3, 'feasibility' => 3]);
$rt = d($user->get('get', ['id' => $A]))['idea']['rating'] ?? [];
ok('셋부터 평균을 연다', $rt['count'] === 3 && $rt['shown'] === true, json_encode($rt));
ok('평균이 맞다 (4+2+3)/3, (2+4+3)/3', (float)$rt['value'] === 3.0 && (float)$rt['feasibility'] === 3.0, json_encode($rt));
ok('작성자에게는 내 점수가 없다', $rt['mine'] === null);
ok('한줄 의견은 이름과 함께 보인다', ($rt['opinions'][0]['name'] ?? '') === '시험둘');
ok('★ 의견에 점수는 붙지 않는다', !array_key_exists('value', $rt['opinions'][0] ?? ['value' => 1]));
$r = $u2->post('rate', ['id' => $A, 'value' => 5, 'feasibility' => 2, 'opinion' => '좋은데 학사 연동이 어렵다']);
ok('다시 매기면 고친다(인원 그대로)', (d($r)['rating']['count'] ?? 0) === 3 && str_contains(d($r)['message'] ?? '', '고쳤'));
ok('★ 평가 표에 제안자 칸이 없다(사람 줄 세우기 금지)',
   !$pdo->query("SHOW COLUMNS FROM bs_idea_rating LIKE 'author%'")->fetch());

// =====================================================================
echo "\n[5] 피드백 — 답글 한 단계, 고치기·지우기\n";
// =====================================================================
$r = $u2->post('comment', ['id' => $A, 'content' => '학사 API 는 어디 것을 쓰나요?']);
ok('피드백을 단다', $r['status'] === 200, err($r));
$c1 = (int)(d($r)['comments'][0]['id'] ?? 0);
$r = $user->post('comment', ['id' => $A, 'parent_id' => $c1, 'content' => '코스모스 학사 연동 API 입니다']);
$c2 = (int)(d($r)['comments'][0]['replies'][0]['id'] ?? 0);
ok('답글은 원 피드백 아래로', $c2 > 0);
$r = $u3->post('comment', ['id' => $A, 'parent_id' => $c2, 'content' => '답글의 답글']);
$cm = d($r)['comments'] ?? [];
ok('★ 답글의 답글도 한 단계로 붙는다', count($cm[0]['replies'] ?? []) === 2 && count($cm) === 1);
$r = $u3->post('comment_update', ['comment_id' => $c1, 'content' => '남이 고침']);
ok('남의 피드백은 못 고친다', $r['status'] === 400, err($r));
$r = $u2->post('comment_update', ['comment_id' => $c1, 'content' => '학사 API 는 어느 것인가요?']);
ok('자기 피드백은 고친다', $r['status'] === 200 && d($r)['comments'][0]['content'] === '학사 API 는 어느 것인가요?');
$r = $u2->post('comment_delete', ['comment_id' => $c1]);
$cm = d($r)['comments'] ?? [];
ok('답글이 달린 피드백은 자리만 남긴다', ($cm[0]['deleted'] ?? false) === true && $cm[0]['content'] === null
   && count($cm[0]['replies']) === 2);
$r = $u2->post('comment', ['id' => $A, 'content' => '   ']);
ok('빈 피드백은 400', $r['status'] === 400);

// =====================================================================
echo "\n[6] 알림 — 포털 알림함\n";
// =====================================================================
ok('★ 새 아이디어는 다른 사람에게 간다', $received($U2, 'studio.idea', '새 아이디어%') >= 2,
   (string)$received($U2, 'studio.idea', '새 아이디어%'));
ok('★ 올린 사람에게는 안 간다', $unread($USER, 'studio.idea') === 0);
ok('피드백·의견은 올린 사람에게', $unread($USER, 'studio.idea_mine') >= 2, (string)$unread($USER, 'studio.idea_mine'));
ok('답글은 원 피드백 쓴 사람에게도', $unread($U2, 'studio.idea_mine') >= 1);
$before = $unread($U3, 'studio.idea');
$b = d($u3->get('board', ['q' => '[시험아이디어]']));
ok('안 읽은 것에 NEW', (bool)array_filter($b['rows'] ?? [], fn($x) => $x['id'] === $ANON && $x['is_new']));
$u3->get('get', ['id' => $ANON]);
ok('★ 열어 보면 그 아이디어 알림만 읽음', $unread($U3, 'studio.idea') === $before - 1,
   $before . ' → ' . $unread($U3, 'studio.idea'));
$b = d($u3->get('board', ['q' => '[시험아이디어]']));
ok('읽은 것은 NEW 가 빠진다', !array_filter($b['rows'] ?? [], fn($x) => $x['id'] === $ANON && $x['is_new']));
$tiles = $pdo->prepare("SELECT COUNT(*) FROM portal_notification n JOIN portal_users u ON u.id = n.user_id
                          WHERE u.email = ? AND n.read_at IS NULL AND n.kind LIKE 'studio.%'");
$tiles->execute([$U2]);
ok('studio 카드 배지로 셀 수 있다(sys=studio)', (int)$tiles->fetchColumn() > 0);

// =====================================================================
echo "\n[7] 검토 — 관리자만, 반려는 사유 필수\n";
// =====================================================================
$r = $user->post('review', ['id' => $A, 'status' => 'adopted']);
ok('관리자 아니면 403', $r['status'] === 403, (string)$r['status']);
$r = $admin->post('review', ['id' => $ANON, 'status' => 'rejected']);
ok('반려는 사유가 있어야', $r['status'] === 400 && str_contains(err($r), '사유'), err($r));
$r = $admin->post('review', ['id' => $A, 'status' => 'promoted']);
ok('★ 검토로 과제화 상태를 만들 수 없다', $r['status'] === 400, err($r));
$r = $admin->post('review', ['id' => $A, 'status' => 'adopted', 'note' => '다음 분기 과제 후보']);
ok('채택한다', $r['status'] === 200 && (d($r)['idea']['status'] ?? '') === 'adopted', err($r));
ok('상태 기록이 피드백 흐름에 남는다',
   (bool)array_filter(d($r)['comments'] ?? [], fn($c) => $c['kind'] === 'status' && str_contains((string)$c['content'], '채택')));
ok('검토 의견이 보인다', (d($r)['idea']['review_note'] ?? '') === '다음 분기 과제 후보');
$r = $user->post('update', ['id' => $A, 'title' => '[시험아이디어] 채택 뒤 고침']);
ok('★ 채택 뒤에는 본문이 잠긴다', $r['status'] === 400, err($r));
ok('검토 결과가 올린 사람에게 간다', $unread($USER, 'studio.idea_mine') >= 3);

// =====================================================================
echo "\n[8] 묶기 — 비슷한 아이디어\n";
// =====================================================================
$r = $u2->post('create', ['title' => '[시험아이디어] 결석 알림', 'proposal' => '결석하면 알려 주자']);
$B = (int)(d($r)['id'] ?? 0);
$r = $admin->post('merge', ['id' => $B, 'target_id' => $A]);
ok('묶는다', $r['status'] === 200 && (d($r)['idea']['duplicate_target']['id'] ?? 0) === $A, err($r));
ok('묶인 쪽은 평가를 받지 않는다', $u3->post('rate', ['id' => $B, 'value' => 3, 'feasibility' => 3])['status'] === 400);
ok('원래 쪽에 묶인 목록이 보인다', in_array($B, array_column(d($u3->get('get', ['id' => $A]))['idea']['merged'] ?? [], 'id'), true));
$r = $admin->post('merge', ['id' => $ANON, 'target_id' => $B]);
ok('★ 묶인 것에 또 묶지 않는다(사슬 금지)', $r['status'] === 400, err($r));
$r = $admin->post('merge', ['id' => $A, 'target_id' => $ANON]);
ok('★ 묶여 들어온 게 있으면 다른 데 묶지 않는다', $r['status'] === 400, err($r));
$r = $admin->post('merge', ['id' => $B, 'target_id' => 0]);
ok('묶음을 푼다', $r['status'] === 200 && d($r)['idea']['duplicate_of'] === null, err($r));

// =====================================================================
echo "\n[9] 아이디어 → R&D 과제 / 프로젝트\n";
// =====================================================================
$r = $u3->post('promote_rnd', ['id' => $B]);
ok('★ 남의 채택 안 된 아이디어는 과제로 못 가져간다', $r['status'] === 400, err($r));
$r = $user->post('promote_project', ['id' => $A]);
ok('★ 프로젝트 등록 권한이 없으면 건너뛰기 못 한다', $r['status'] === 400, err($r));

$r = $u3->post('promote_rnd', ['id' => $A, 'name' => '[시험아이디어] 출석부 자동 알림 과제']);
ok('채택된 아이디어는 누구나 과제로 올린다', $r['status'] === 200, err($r));
$RND = (int)(d($r)['project_id'] ?? 0);
$row = $pdo->query("SELECT * FROM bs_project WHERE id = $RND")->fetch();
ok('★ 과제는 발의됨에서 시작한다(승인을 건너뛰지 않는다)', $row['status'] === 'proposed' && $row['project_type'] === 'rnd');
ok('발의자는 올린 사람', $row['proposer_id'] === $U3);
ok('갈래가 따라간다', $row['rnd_category'] === 'enhance');
ok('분야가 따라간다', (int)$pdo->query("SELECT COUNT(*) FROM bs_rnd_domain WHERE project_id = $RND")->fetchColumn() === 1);
ok('개요에 아이디어 본문과 번호가 들어간다', str_contains((string)$row['summary'], '[제안]') && str_contains((string)$row['summary'], 'IDEA-'));
ok('출발 아이디어가 이어진다', (int)$row['origin_idea_id'] === $A);
$g = d($u2->get('get', ['id' => $A]))['idea'] ?? [];
ok('아이디어는 과제화로', ($g['status'] ?? '') === 'promoted' && ($g['promoted']['type'] ?? '') === 'rnd', json_encode($g['promoted'] ?? null));
ok('★ 과제화된 아이디어는 못 지운다', $user->post('delete', ['id' => $A])['status'] === 400);
ok('★ 두 번 올리지 않는다', $u3->post('promote_rnd', ['id' => $A])['status'] === 400);
ok('함께한 사람에게 알린다', $received($U2, 'studio.idea_mine', 'R&D 과제%') === 1);
ok('올린 사람 본인에게는 알리지 않는다', $received($U3, 'studio.idea_mine', 'R&D 과제%') === 0);
$rd = d($u2->req("/studio/api/rnd.php?act=get&id=$RND"));
ok('과제 화면에 출발 아이디어', ($rd['lineage']['origin_idea']['id'] ?? 0) === $A, json_encode($rd['lineage'] ?? null));

$admin->post('review', ['id' => $B, 'status' => 'adopted', 'note' => '바로 프로젝트로']);
$r = $admin->post('promote_project', ['id' => $B, 'name' => '[시험아이디어] 결석 알림 프로젝트']);
ok('과제를 건너뛰어 프로젝트로', $r['status'] === 200, err($r));
$PRJ = (int)(d($r)['project_id'] ?? 0);
$row = $pdo->query("SELECT * FROM bs_project WHERE id = $PRJ")->fetch();
ok('★ 프로젝트는 작성 중에서 시작', $row['project_type'] === 'project' && $row['status'] === 'draft');
ok('출발 아이디어가 이어진다', (int)$row['origin_idea_id'] === $B);

// =====================================================================
echo "\n[10] R&D 과제 → 프로젝트\n";
// =====================================================================
$r = $admin->post('promote_project', ['id' => $RND], 'rnd');
ok('★ 승인 전 과제는 프로젝트로 못 넘긴다', $r['status'] === 400, err($r));
$r = $admin->post('approve', ['id' => $RND], 'rnd');
ok('(관리자가 과제를 승인)', $r['status'] === 200, err($r));
ok('과제 단추에 프로젝트로 넘기기', in_array('promote_project', d($admin->req("/studio/api/rnd.php?act=get&id=$RND"))['rnd']['actions'] ?? [], true));
$r = $u2->post('promote_project', ['id' => $RND], 'rnd');
ok('주도자·관리자가 아니면 못 넘긴다', $r['status'] === 400, err($r));
$r = $admin->post('promote_project', ['id' => $RND, 'name' => '[시험아이디어] 출석부 알림 구축'], 'rnd');
ok('승인된 과제를 프로젝트로', $r['status'] === 200, err($r));
$P2 = (int)(d($r)['project_id'] ?? 0);
$row = $pdo->query("SELECT * FROM bs_project WHERE id = $P2")->fetch();
ok('★ 과제와 아이디어가 함께 이어진다', (int)$row['origin_project_id'] === $RND && (int)$row['origin_idea_id'] === $A);
ok('과제는 그대로 남는다', $pdo->query("SELECT status FROM bs_project WHERE id = $RND")->fetchColumn() === 'approved');
ok('★ 한 과제에서 프로젝트는 하나', $admin->post('promote_project', ['id' => $RND], 'rnd')['status'] === 400);
$g = d($u2->get('get', ['id' => $A]))['idea'] ?? [];
ok('아이디어 화면에서 과제 → 프로젝트까지 이어 보인다', ($g['promoted']['next']['id'] ?? 0) === $P2, json_encode($g['promoted'] ?? null));
$rd = d($u2->req("/studio/api/rnd.php?act=get&id=$RND"));
ok('과제 화면에 만든 프로젝트', ($rd['lineage']['project']['id'] ?? 0) === $P2);

// =====================================================================
echo "\n[11] 지우기\n";
// =====================================================================
$r = $u2->post('delete', ['id' => $ANON]);
ok('남의 것은 못 지운다', $r['status'] === 400, err($r));
$r = $user->post('delete', ['id' => $ANON]);
ok('올린 사람은 지운다', $r['status'] === 200, err($r));
ok('지운 것은 남에게 404', $u2->get('get', ['id' => $ANON])['status'] === 404);
ok('보드에서 빠진다', !in_array($ANON, array_column(d($u2->get('board', ['q' => '[시험아이디어]']))['rows'] ?? [], 'id'), true));
ok('관리자는 지운 것까지 볼 수 있다',
   in_array($ANON, array_column(d($admin->get('board', ['q' => '[시험아이디어]', 'with_deleted' => 1]))['rows'] ?? [], 'id'), true));

$cleanup();

echo "\n" . str_repeat('=', 29) . "\n통과 $pass / 실패 $fail\n\n";
exit($fail > 0 ? 1 : 0);

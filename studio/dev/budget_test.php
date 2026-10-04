<?php
/**
 * 바깥 호출 예산 관리 검증 — 켜고 끄기 · 쉬는 시각 · 묶어 받기 · 사용량.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 이 시험이 있는가                                               │
 * │                                                                  │
 * │ 2026-10-04 에 피그마 링크 분석이 하룻밤 돌고 300건 중 3건만       │
 * │ 읽었다. 피그마가 `retry-after: 224862`(2일 14시간) 를 보내고      │
 * │ 있었는데 코드가 그 헤더를 안 봤고, 429 를 받고도 다음 링크로      │
 * │ 계속 넘어가 며칠치 예산을 하룻밤에 태웠다.                        │
 * │                                                                  │
 * │ 여기서 지키는 불변식은 넷이다.                                    │
 * │   [A] 막혀 있으면 **호출을 만들지 않는다**                        │
 * │   [B] 끄는 것은 연결을 끊는 것과 다르다 — 토큰이 남는다           │
 * │   [C] 상대가 말한 시간을 **줄이지 않는다**                        │
 * │   [D] 링크 N건이 호출 N번이 되지 않는다 (묶어 받기)               │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   php studio/dev/budget_test.php
 */

declare(strict_types=1);

define('ROOT', dirname(__DIR__, 2));
$TESTDB = getenv('BS_TEST_DB') ?: 'blueassign_test';
require ROOT . '/studio/inc/bootstrap.php';
require ROOT . '/studio/inc/service/Integration.php';
require ROOT . '/studio/inc/service/RemoteSource.php';
require ROOT . '/studio/inc/service/LinkAnalyzer.php';

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname={$TESTDB};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "시험 DB '{$TESTDB}' 에 붙지 못했습니다.\n"
        . "  mysql -u root {$TESTDB} < studio/sql/017_migration_api_budget.sql\n");
    exit(2);
}

$pass = $fail = 0;
function ok(string $what, bool $cond, string $why = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($why !== '' ? " — $why" : '') . "\n"; }
}

$FIGMA = Integration::FIGMA;
$actor = ['id' => 'kimhy@bluesoft.co.kr', 'name' => '김호영'];

// 이 시험이 만든 흔적만 치운다. 다른 시험의 자료를 지우지 않는다.
$pdo->exec("DELETE FROM bs_api_usage WHERE act LIKE 'test_%'");
$pdo->exec("DELETE FROM bs_integration WHERE provider = '{$FIGMA}'");

$ig = new Integration($pdo);

// =====================================================================
echo "\n[A] 연결 안 됨 — 토큰이 없으면 막는다\n";
// =====================================================================
$why = $ig->blockedReason($FIGMA);
ok('토큰 없으면 막힌다', $why !== null);
ok('사유에 "등록" 이 보인다', $why !== null && str_contains($why, '등록'), (string)$why);

// 이제 토큰을 넣는다. 바깥으로 나가지 않는 가짜 값이다.
$ig->save($FIGMA, ['secret' => 'figd_TESTONLY_not_a_real_token'], $actor);
ok('토큰을 넣으면 풀린다', $ig->blockedReason($FIGMA) === null, (string)$ig->blockedReason($FIGMA));

// =====================================================================
echo "\n[B] 연동 제외 — 끄는 것은 연결을 끊는 것과 다르다\n";
// =====================================================================
$ig->setEnabled($FIGMA, false, $actor);
$why = $ig->blockedReason($FIGMA);
ok('끄면 막힌다', $why !== null);
ok('사유가 "꺼 두었습니다" 다', $why !== null && str_contains($why, '꺼 두었'), (string)$why);

// ★ 여기가 핵심이다. 끄는 것과 끊는 것이 같아지면 관리자가 피그마에 가서
//   토큰을 다시 발급받아야 한다 — 그러면 아무도 끄지 않는다.
ok('★ 꺼도 토큰은 남는다', $ig->secret($FIGMA) === 'figd_TESTONLY_not_a_real_token');
$st = $ig->status($FIGMA);
ok('꺼도 connected 는 참이다', $st['connected'] === true);
ok('usable 만 거짓이 된다', $st['usable'] === false && $st['enabled'] === false);

$ig->setEnabled($FIGMA, true, $actor);
ok('다시 켜면 풀린다', $ig->blockedReason($FIGMA) === null);

// =====================================================================
echo "\n[C] 쉬는 시각 — 상대가 말한 시간을 줄이지 않는다\n";
// =====================================================================
$ig->clearCooldown($FIGMA);
$ig->startCooldown($FIGMA, 224862, '피그마가 2일 14시간 뒤에 다시 오라고 했습니다.');
$left = $ig->cooldownLeft($FIGMA);
ok('224862초가 그대로 걸린다', $left > 224000 && $left <= 224862, "left=$left");

$why = $ig->blockedReason($FIGMA);
ok('막힌다', $why !== null);
ok('남은 시간을 사람 말로 적는다',
   $why !== null && str_contains($why, '2일'), (string)$why);

// ★ 짧은 쉼이 긴 쉼을 덮어쓰면, 429 한 번만 더 맞아도 2일이 5분으로
//   줄어 다시 두드리기 시작한다. 그게 어젯밤 사고의 모양이었다.
$ig->startCooldown($FIGMA, 300, '짧은 쉼');
$after = $ig->cooldownLeft($FIGMA);
ok('★ 짧은 쉼이 긴 쉼을 줄이지 못한다', $after > 224000, "left=$after");

// 말로 바꾸는 규칙
ok('humanSpan 60초 → 1분',       Integration::humanSpan(60) === '1분');
ok('humanSpan 224862초 → 2일…',  str_starts_with(Integration::humanSpan(224862), '2일'));

// =====================================================================
echo "\n[D] 막혀 있으면 호출을 만들지 않는다\n";
// =====================================================================
// RemoteSource 는 이 상태에서 **바깥으로 나가면 안 된다.** 나갔다면
// 가짜 토큰으로 401 이 나거나 네트워크 오류가 났을 것이다 — 사유가
// 우리가 적어 둔 쉼 안내여야 한다.
$remote = new RemoteSource($pdo);
$threw  = null;
try {
    $remote->fetch('https://www.figma.com/design/ABCDEFGHIJ1234567890/X?node-id=1-2');
} catch (RemoteSourceError $e) {
    $threw = $e;
}
ok('부르기 전에 막는다', $threw !== null);
ok('사유가 쉼 안내다', $threw !== null && str_contains($threw->getMessage(), '쉽니다'),
   $threw === null ? '' : $threw->getMessage());
// ★ '기다리면 될 일' 로 분류돼야 한다. 실패로 못 박으면 제한이 풀린 뒤
//   사람이 수백 건을 손으로 되돌려야 한다.
ok('★ retryable 이다', $threw !== null && $threw->retryable === true);

$n = (int)$pdo->query("SELECT COUNT(*) c FROM bs_api_usage WHERE provider='{$FIGMA}'")
               ->fetch()['c'];
ok('★ 호출 기록이 하나도 안 남는다 (= 안 나갔다)', $n === 0, "rows=$n");

$ig->clearCooldown($FIGMA);

// =====================================================================
echo "\n[E] 하루 상한 — 상대가 막기 전에 우리가 먼저 멈춘다\n";
// =====================================================================
$ig->setDailyCap($FIGMA, 3, $actor);
for ($i = 0; $i < 2; $i++) {
    $ig->logCall($FIGMA, 'test_call', true, 200, 120, 40);
}
ok('2회 썼으면 아직 쓸 수 있다', $ig->blockedReason($FIGMA) === null);
$ig->logCall($FIGMA, 'test_call', false, 429, 90, 40);
$why = $ig->blockedReason($FIGMA);
ok('3회째에 상한에 걸린다', $why !== null && str_contains($why, '한도'), (string)$why);
// 실패도 상대의 예산을 쓴다. 성공만 세면 상한이 헐거워진다.
ok('★ 실패한 호출도 센다', $ig->callsToday($FIGMA) === 3);

$ig->setDailyCap($FIGMA, 0, $actor);
ok('상한 0 이면 제한 없음', $ig->blockedReason($FIGMA) === null);

// =====================================================================
echo "\n[F] 사용량 그래프 — 빈 날도 채운다\n";
// =====================================================================
$series = $ig->usageSeries($FIGMA, 14);
ok('14칸이다', count($series) === 14, (string)count($series));
ok('맨 끝이 오늘이다', $series[13]['d'] === date('Y-m-d'), (string)$series[13]['d']);
ok('오래된 쪽이 앞이다', $series[0]['d'] < $series[13]['d']);
ok('오늘 3회가 잡힌다', (int)$series[13]['calls'] === 3, (string)$series[13]['calls']);
// ★ 호출 없던 날이 빠지면 막대가 밀려 그려진다 — "어제 많이 썼다" 가
//   "오늘 많이 썼다" 로 보인다.
ok('★ 어제는 0 으로 채워진다', (int)$series[12]['calls'] === 0);
ok('묶음 건수가 쌓인다', (int)$series[13]['items'] === 120, (string)$series[13]['items']);

// =====================================================================
echo "\n[G] Retry-After 읽기 — 초도 받고 날짜도 받는다\n";
// =====================================================================
$m = new ReflectionMethod(RemoteSource::class, 'retryAfterSeconds');
$m->setAccessible(true);
ok('초를 읽는다',      $m->invoke($remote, ['retry-after' => '224862']) === 224862);
ok('날짜를 읽는다',    abs($m->invoke($remote, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 600)]) - 600) <= 5);
// ★ 모를 때는 **길게** 쉰다. 짧게 잡아 또 맞는 쪽이 늦게 푸는 쪽보다 비싸다.
ok('★ 헤더가 없으면 1시간', $m->invoke($remote, []) === 3600);
ok('헛소리면 1시간',        $m->invoke($remote, ['retry-after' => 'soon']) === 3600);
ok('너무 짧으면 올려 잡는다', $m->invoke($remote, ['retry-after' => '1']) === 60);

// =====================================================================
echo "\n[H] 묶어 받기 — 링크 N건이 호출 N번이 되지 않는다\n";
// =====================================================================
$pdo->exec("DELETE FROM bs_source_link WHERE url LIKE '%TESTBATCH%'");
$pdo->exec("DELETE FROM bs_project WHERE code = 'BUDGET-TEST'");
$pdo->prepare(
    'INSERT INTO bs_project (code, name, owner_id, owner_name) VALUES (?,?,?,?)'
)->execute(['BUDGET-TEST', '예산 시험', $actor['id'], $actor['name']]);
$pid = (int)$pdo->lastInsertId();

// 한 파일 안의 서로 다른 화면 60개 + 다른 파일 5개.
// 그중 둘은 **같은 화면을 가리키는 다른 주소**다(IA 시트에서 흔하다).
$ins = $pdo->prepare(
    'INSERT INTO bs_source_link (project_id, url, provider, status) VALUES (?,?,?,"pending")'
);
for ($i = 1; $i <= 60; $i++) {
    $ins->execute([$pid, "https://www.figma.com/design/TESTBATCHAAA111/Plan?node-id=10-$i", $FIGMA]);
}
for ($i = 1; $i <= 5; $i++) {
    $ins->execute([$pid, "https://www.figma.com/design/TESTBATCHBBB222/Design?node-id=20-$i", $FIGMA]);
}
// 같은 node-id 를 가리키는 두 번째 주소. url 이 달라 행은 둘이다.
$ins->execute([$pid, "https://www.figma.com/design/TESTBATCHAAA111/Plan?node-id=10-1&t=xyz", $FIGMA]);
// node-id 가 없는 것은 묶을 수 없다 — 한 건씩 경로가 맡는다.
$ins->execute([$pid, "https://www.figma.com/design/TESTBATCHAAA111/Plan", $FIGMA]);

$an = new LinkAnalyzer($pdo);
$g  = new ReflectionMethod(LinkAnalyzer::class, 'nextFigmaGroup');
$g->setAccessible(true);
$grp = $g->invoke($an, $pid);

ok('묶을 것이 있다', $grp !== null);
ok('가장 많이 쌓인 파일부터', $grp !== null && $grp[0] === 'TESTBATCHAAA111', (string)($grp[0] ?? ''));
ok('★ 한 번에 FIGMA_BATCH 개까지만',
   $grp !== null && count($grp[1]) === LinkAnalyzer::FIGMA_BATCH,
   (string)count($grp[1] ?? []));
// ★ 같은 화면을 두 주소가 가리키면 **한 번만 묻고 둘에 나눠 담아야** 한다.
//   id 를 두 번 보내면 그만큼 상대의 예산을 더 쓴다.
ok('★ 같은 node-id 는 한 번만 묻는다',
   $grp !== null && count($grp[1]['10:1'] ?? []) === 2, json_encode($grp[1]['10:1'] ?? null));
ok('node-id 없는 주소는 묶지 않는다',
   $grp !== null && !in_array('', array_keys($grp[1]), true));

// 호출 횟수 가늠: 66건 중 묶을 수 있는 65건이 FIGMA_BATCH(40) 단위로
// 나뉘면 2 + 1 = 3번. 링크마다 부르면 65번이다.
$calls = (int)ceil(60 / LinkAnalyzer::FIGMA_BATCH) + (int)ceil(5 / LinkAnalyzer::FIGMA_BATCH);
ok("★ 65건이 호출 {$calls}번 (전에는 65번)", $calls <= 3, (string)$calls);

// =====================================================================
echo "\n[I] 상한은 읽을 수 있는 링크만 센다\n";
// =====================================================================
// 못 읽는 주소가 상한을 먹으면 정작 읽어야 할 링크가 잘려 나간다 —
// 실제 IA 시트에서 300건 중 183건이 사이트 주소였다.
$pdo->exec("DELETE FROM bs_source_link WHERE project_id = $pid");
$lines = [];
for ($i = 1; $i <= 400; $i++) {                    // 못 읽는 주소 400개
    $lines[] = "메뉴 $i <https://csms45.moodler.kr/course/view.php?id=$i>";
}
for ($i = 1; $i <= 50; $i++) {                     // 읽을 수 있는 주소 50개
    $lines[] = "화면 $i <https://www.figma.com/design/TESTBATCHCCC333/P?node-id=30-$i>";
}
$pdo->prepare(
    'INSERT INTO bs_project_source (project_id, kind, parse_status, parsed_text)
     VALUES (?, "paste", "ok", ?)'
)->execute([$pid, implode("\n", $lines)]);

$r = $an->scan($pid);
$cnt = $pdo->query(
    "SELECT status, COUNT(*) c FROM bs_source_link WHERE project_id = $pid GROUP BY status"
)->fetchAll();
$by = [];
foreach ($cnt as $row) { $by[(string)$row['status']] = (int)$row['c']; }

ok('읽을 수 있는 50개가 전부 담긴다', ($by['pending'] ?? 0) === 50, json_encode($by));
ok('★ 못 읽는 400개가 상한을 먹지 않는다', ($by['skip'] ?? 0) === 400, json_encode($by));
ok('한도 초과로 버린 것이 없다', (int)$r['skipped'] === 0, (string)$r['skipped']);

// =====================================================================
echo "\n[J] 비밀은 밖으로 나가지 않는다\n";
// =====================================================================
$st   = $ig->status($FIGMA);
$json = json_encode($st, JSON_UNESCAPED_UNICODE);
ok('★ status() 에 토큰이 없다',  !str_contains((string)$json, 'figd_'), (string)$json);
ok('status() 에 암호문도 없다',
   !array_key_exists('secret_enc', $st) && !array_key_exists('token_enc', $st));

// 치우기
$pdo->exec("DELETE FROM bs_source_link WHERE project_id = $pid");
$pdo->exec("DELETE FROM bs_project_source WHERE project_id = $pid");
$pdo->exec("DELETE FROM bs_project WHERE id = $pid");
$pdo->exec("DELETE FROM bs_api_usage WHERE act LIKE 'test_%'");
$pdo->exec("DELETE FROM bs_integration WHERE provider = '{$FIGMA}'");

echo "\n" . str_repeat('=', 56) . "\n";
echo "통과 $pass · 실패 $fail\n";
exit($fail === 0 ? 0 : 1);

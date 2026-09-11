<?php
/**
 * learning(FastAPI/SQLite) → learn(PHP/MySQL) 데이터 이관. CLI 전용, 일회성.
 *
 *   php learn/tools/migrate_from_learning.php [--db=경로] [--uploads=경로] [--force] [--dry-run]
 *
 * 두 스키마는 테이블 이름(learning_* → learn_*)만 다르고 컬럼은 34개까지 1:1로 같다.
 * 그래서 값을 변환하지 않고 그대로 옮긴다.
 *
 *  - 신청·이수증·이력은 **id 를 그대로 유지한다.** 이수증과 이력이 request_id 로 신청을
 *    가리키고 있어, 번호를 다시 매기면 연결이 끊긴다.
 *  - 플랫폼·분류는 이미 learn 쪽에 같은 값이 시드로 들어가 있다. 통째로 넣으면 142행이
 *    두 벌이 되므로, (site, large, medium) 으로 맞춰 recommended·active·sort_order 만
 *    갱신하고 없는 것만 새로 넣는다.
 *  - 관리자 명단은 합집합이 아니라 **그대로 복제한다.** 화면에서 뺀 사람이 이관하면서
 *    되살아나면 안 된다. 고정 관리자(OWNER_EMAILS)는 명단에 없어도 코드가 항상 포함한다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI 에서만 실행한다\n");
}

require_once __DIR__ . '/../guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/storage.php';   // learn_upload_dir()

$opt = getopt('', ['db::', 'uploads::', 'force', 'dry-run']);
$SQLITE  = $opt['db']      ?? __DIR__ . '/../../learning/var/learning.db';
$UPLOADS = $opt['uploads'] ?? __DIR__ . '/../../learning/var/uploads';
$FORCE   = array_key_exists('force', $opt);
$DRY     = array_key_exists('dry-run', $opt);

function say($s = '') { echo $s, "\n"; }
function die_with($s) { fwrite(STDERR, $s . "\n"); exit(1); }

if (!is_file($SQLITE)) die_with("learning.db 를 찾을 수 없다: {$SQLITE}");

$src = new PDO('sqlite:' . $SQLITE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$dst = learn_db();

say('원본: ' . realpath($SQLITE));
say('대상: MySQL learn_* 테이블' . ($DRY ? '  [--dry-run: 쓰지 않는다]' : ''));
say();

/* ---------- 안전장치 ---------- */

$have = (int)$dst->query("SELECT COUNT(*) FROM learn_requests")->fetchColumn();
if ($have && !$FORCE) {
    die_with("learn_requests 에 이미 {$have}건이 있다. 덮어쓰려면 --force 를 준다\n"
           . "(--force 는 learn_requests / learn_certs / learn_histories 를 비우고 다시 넣는다.\n"
           . " 플랫폼·분류·정책·관리자는 지우지 않고 값만 맞춘다.)");
}

$counts = [];
foreach (['requests', 'certs', 'histories', 'sites', 'categories', 'admins', 'policy'] as $t) {
    $counts[$t] = (int)$src->query("SELECT COUNT(*) FROM learning_{$t}")->fetchColumn();
}
say('원본 건수: ' . implode(', ', array_map(fn($k) => "{$k} {$counts[$k]}", array_keys($counts))));
say();

if ($DRY) { say('--dry-run 이므로 여기서 멈춘다.'); exit(0); }

$dst->beginTransaction();

/* ---------- 신청 · 이력 · 이수증 (id 유지) ---------- */

if ($have) {
    $dst->exec("DELETE FROM learn_certs");
    $dst->exec("DELETE FROM learn_histories");
    $dst->exec("DELETE FROM learn_requests");
    say("기존 {$have}건을 비웠다 (--force)");
}

$REQ_COLS = ['id', 'site', 'category_large', 'category_medium', 'level', 'title', 'url',
    'account_type', 'applicant', 'applicant_email', 'duration_min', 'is_free', 'price',
    'start_date', 'end_date', 'progress', 'progress_at', 'approved_at', 'rejected_at',
    'reject_reason', 'claimed_at', 'claim_approved_at', 'claim_rejected_at',
    'claim_reject_reason', 'refunded_at', 'refund_cap_at_request', 'refund_amount',
    'rating', 'recommend', 'review_note', 'active', 'archived', 'created_by', 'created_at'];

/** SQLite 는 NOT NULL 이 아니어도 NULL 이 올 수 있다. 별점만 NULL 을 살리고 나머지는 빈 값으로. */
function clean(array $row, array $cols, array $nullable = []) {
    $out = [];
    foreach ($cols as $c) {
        $v = $row[$c] ?? null;
        $out[$c] = ($v === null && !in_array($c, $nullable, true)) ? '' : $v;
    }
    return $out;
}

function insert_rows(PDO $dst, $table, array $cols, array $rows, array $nullable = []) {
    if (!$rows) return 0;
    $sql = "INSERT INTO {$table} (`" . implode('`,`', $cols) . '`) VALUES ('
         . implode(',', array_fill(0, count($cols), '?')) . ')';
    $st = $dst->prepare($sql);
    foreach ($rows as $r) $st->execute(array_values(clean($r, $cols, $nullable)));
    return count($rows);
}

$rows = $src->query("SELECT * FROM learning_requests ORDER BY id")->fetchAll();
$n = insert_rows($dst, 'learn_requests', $REQ_COLS, $rows, ['rating', 'recommend']);
say("신청 {$n}건");

$rows = $src->query("SELECT * FROM learning_histories ORDER BY id")->fetchAll();
$n = insert_rows($dst, 'learn_histories',
    ['id', 'request_id', 'status', 'memo', 'actor', 'actor_email', 'created_at'], $rows);
say("이력 {$n}건");

/* 이수증은 DB 행과 파일이 따로다 — 행을 옮기고 파일은 복사한다. */
$rows = $src->query("SELECT * FROM learning_certs ORDER BY id")->fetchAll();
$n = insert_rows($dst, 'learn_certs',
    ['id', 'request_id', 'name', 'path', 'uploaded_by', 'created_at'], $rows);

$copied = 0;
$missing = [];
$destDir = learn_upload_dir();
foreach ($rows as $c) {
    $from = rtrim($UPLOADS, '/\\') . '/' . basename((string)$c['path']);
    if (!is_file($from)) { $missing[] = $c['path']; continue; }
    if (copy($from, $destDir . '/' . basename((string)$c['path']))) $copied++;
}
say("이수증 {$n}건 (파일 {$copied}개 복사"
    . ($missing ? ', 원본 없음 ' . count($missing) . '개' : '') . ')');

/* ---------- 플랫폼 (이름으로 맞춘다) ---------- */

$added = $updated = 0;
$find = $dst->prepare("SELECT id FROM learn_sites WHERE name=?");
$upd  = $dst->prepare("UPDATE learn_sites SET url=?, sort_order=?, active=? WHERE id=?");
$ins  = $dst->prepare("INSERT INTO learn_sites (name, url, sort_order, active) VALUES (?,?,?,?)");
foreach ($src->query("SELECT * FROM learning_sites ORDER BY sort_order, id")->fetchAll() as $s) {
    $find->execute([$s['name']]);
    if ($id = $find->fetchColumn()) {
        $upd->execute([(string)$s['url'], (int)$s['sort_order'], (int)$s['active'], $id]);
        $updated++;
    } else {
        $ins->execute([$s['name'], (string)$s['url'], (int)$s['sort_order'], (int)$s['active']]);
        $added++;
    }
}
say("플랫폼 갱신 {$updated}건, 추가 {$added}건");

/* ---------- 분류 (site+large+medium 으로 맞춘다) ---------- */

$added = $updated = 0;
$find = $dst->prepare("SELECT id FROM learn_categories WHERE site=? AND large=? AND medium=?");
$upd  = $dst->prepare("UPDATE learn_categories SET sort_order=?, recommended=?, active=? WHERE id=?");
$ins  = $dst->prepare("INSERT INTO learn_categories (site, large, medium, sort_order, recommended, active)
                       VALUES (?,?,?,?,?,?)");
foreach ($src->query("SELECT * FROM learning_categories ORDER BY sort_order, id")->fetchAll() as $c) {
    $key = [(string)$c['site'], (string)$c['large'], (string)$c['medium']];
    $find->execute($key);
    if ($id = $find->fetchColumn()) {
        $upd->execute([(int)$c['sort_order'], (int)$c['recommended'], (int)$c['active'], $id]);
        $updated++;
    } else {
        $ins->execute(array_merge($key,
            [(int)$c['sort_order'], (int)$c['recommended'], (int)$c['active']]));
        $added++;
    }
}
$rec = (int)$dst->query("SELECT COUNT(*) FROM learn_categories WHERE recommended=1")->fetchColumn();
say("분류 갱신 {$updated}건, 추가 {$added}건 (추천 지정 {$rec}건)");

/* ---------- 관리자 (그대로 복제) ---------- */

$dst->exec("DELETE FROM learn_admins");
$ins = $dst->prepare("INSERT INTO learn_admins (email, added_by, created_at) VALUES (?,?,?)");
$n = 0;
foreach ($src->query("SELECT * FROM learning_admins")->fetchAll() as $a) {
    $ins->execute([strtolower($a['email']), (string)$a['added_by'], (string)$a['created_at']]);
    $n++;
}
say("관리자 {$n}명");

/* ---------- 정책 ---------- */

$p = $src->query("SELECT * FROM learning_policy WHERE id=1")->fetch();
if ($p) {
    $cols = ['partial_enabled', 'partial_cap', 'annual_amount_enabled', 'annual_amount_limit',
             'annual_count_enabled', 'annual_count_limit', 'claim_deadline_enabled',
             'claim_deadline_days'];
    $set = implode(',', array_map(fn($c) => "`$c`=?", $cols));
    $args = array_map(fn($c) => (int)$p[$c], $cols);
    $args[] = (string)$p['updated_by'];
    $args[] = (string)$p['updated_at'];
    $dst->prepare("UPDATE learn_policy SET {$set}, updated_by=?, updated_at=? WHERE id=" . POLICY_ID)
        ->execute($args);
    say('환급 정책 1건');
}

$dst->commit();

/* ---------- AUTO_INCREMENT 를 최대 id 다음으로 ---------- */

foreach (['learn_requests', 'learn_certs', 'learn_histories'] as $t) {
    $max = (int)$dst->query("SELECT COALESCE(MAX(id),0) FROM {$t}")->fetchColumn();
    $dst->exec("ALTER TABLE {$t} AUTO_INCREMENT = " . ($max + 1));
}

say();
say('이관 완료.');
if ($missing) {
    say();
    say('※ 아래 이수증은 DB 행만 있고 원본 파일이 없다. 행은 옮겼으므로 목록의 첨부 개수는');
    say('   그대로지만, 열기를 누르면 404 가 난다. (seed_demo.py 가 파일 없이 만든 행이다)');
    foreach ($missing as $m) say('   - ' . $m);
}

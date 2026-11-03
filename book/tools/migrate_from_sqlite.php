<?php
/**
 * book.db(FastAPI/SQLite) → book_requests(PHP/MySQL) 데이터 이관. CLI 전용, 일회성.
 *
 *   php book/tools/migrate_from_sqlite.php [--db=경로] [--force] [--dry-run]
 *
 *  - id 를 그대로 유지한다. 슬랙 알림·북마크 등 바깥에서 번호로 가리킬 수 있다.
 *  - 컬럼은 있는 것만 옮긴다. 오래된 book.db 에는 applicant_email·thumbnail 이 없을 수 있다 —
 *    그때는 포털 계정 이름으로 이메일을 맞춘다(app.py 의 NAME_TO_EMAIL 백필과 같은 일).
 *  - yes24_url 은 옮기지 않는다(교보문고로 바뀐 뒤 아무도 읽지 않는다).
 *  - 대상 표에 행이 있으면 멈춘다. 첫 화면이 seed.json 을 넣었을 수 있으니 --force 로 비우고 다시 넣는다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI 에서만 실행한다\n");
}

require_once __DIR__ . '/../bootstrap.php';

$opt = getopt('', ['db::', 'force', 'dry-run']);
$SQLITE = $opt['db'] ?? __DIR__ . '/../book.db';
$FORCE = array_key_exists('force', $opt);
$DRY = array_key_exists('dry-run', $opt);

function say($s = '') { echo $s, "\n"; }
function die_with($s) { fwrite(STDERR, $s . "\n"); exit(1); }

if (!is_file($SQLITE)) die_with("book.db 를 찾을 수 없다: {$SQLITE}");

$src = new PDO('sqlite:' . $SQLITE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$config = book_config_from_portal(['seed' => '']);   // 비어 있어도 seed.json 을 넣지 않는다
$dst = book_connect($config);
book_migrate($dst, $config);

say('원본: ' . realpath($SQLITE));
say('대상: book_requests' . ($DRY ? '  [--dry-run: 쓰지 않는다]' : ''));

$cols = array_column($src->query("PRAGMA table_info(requests)")->fetchAll(), 'name');
$has = fn(string $c) => in_array($c, $cols, true);
$rows = $src->query("SELECT * FROM requests ORDER BY id")->fetchAll();
say('원본 ' . count($rows) . '건, 컬럼: ' . implode(', ', $cols));

$have = (int)$dst->query("SELECT COUNT(*) FROM book_requests")->fetchColumn();
if ($have) say("대상에 이미 {$have}건이 있다" . ($FORCE ? ' — --force: 비우고 다시 넣는다' : ''));
if ($have && !$FORCE && !$DRY) {
    die_with('비우고 다시 넣으려면 --force 를 준다');
}

$emails = book_member_emails();
$missingEmail = [];

if ($DRY) {
    foreach ($rows as $r) {
        $email = $has('applicant_email') ? trim((string)($r['applicant_email'] ?? '')) : '';
        if ($email === '' && !isset($emails[trim((string)$r['applicant'])])) $missingEmail[trim((string)$r['applicant'])] = true;
    }
    say('이메일을 못 맞춘 이름: ' . ($missingEmail ? implode(', ', array_keys($missingEmail)) : '없음'));
    say('[--dry-run] 여기까지.');
    exit(0);
}

$dst->beginTransaction();
try {
    if ($have) $dst->exec("DELETE FROM book_requests");

    $insert = $dst->prepare("INSERT INTO book_requests
        (`id`, `year`, `date`, `applicant`, `applicant_email`, `gubun`, `title`, `author`, `publisher`,
         `price`, `done`, `note`, `kyobo_url`, `thumbnail`, `created_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $r) {
        $name = trim((string)($r['applicant'] ?? ''));
        $email = $has('applicant_email') ? trim((string)($r['applicant_email'] ?? '')) : '';
        if ($email === '') $email = $emails[$name] ?? '';
        if ($email === '') $missingEmail[$name] = true;
        $date = (string)($r['date'] ?? '');
        $insert->execute([
            (int)$r['id'],
            isset($r['year']) && is_numeric($r['year']) ? (int)$r['year'] : book_year_of($date),
            $date,
            $name,
            $email,
            in_array($r['gubun'] ?? '', BOOK_GUBUNS, true) ? $r['gubun'] : BOOK_DEFAULT_GUBUN,
            trim((string)($r['title'] ?? '')),
            trim((string)($r['author'] ?? '')),
            trim((string)($r['publisher'] ?? '')),
            isset($r['price']) && is_numeric($r['price']) ? (int)$r['price'] : null,
            !empty($r['done']) ? 1 : 0,
            trim((string)($r['note'] ?? '')),
            ($has('kyobo_url') && trim((string)($r['kyobo_url'] ?? '')) !== '') ? trim((string)$r['kyobo_url']) : null,
            ($has('thumbnail') && trim((string)($r['thumbnail'] ?? '')) !== '') ? trim((string)$r['thumbnail']) : null,
            $now,
        ]);
    }
    $dst->commit();
} catch (Throwable $e) {
    $dst->rollBack();
    die_with('실패해서 되돌렸다: ' . $e->getMessage());
}

$got = (int)$dst->query("SELECT COUNT(*) FROM book_requests")->fetchColumn();
say("넣은 건수: {$got}" . ($got === count($rows) ? '' : '  ← 원본과 다르다!'));
say('이메일을 못 맞춘 이름(본인 수정·삭제 불가, 관리자만 가능): ' . ($missingEmail ? implode(', ', array_keys($missingEmail)) : '없음'));
say('완료');

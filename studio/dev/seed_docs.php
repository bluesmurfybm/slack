<?php
/** 시험 문서를 프로젝트에 붙여 둔다 — [문서 분석]·[WBS 도출] 을 눌러 볼 수 있게. */

declare(strict_types=1);

/* ┌──────────────────────────────────────────────────────────────────┐
   │ 왜 seed_dev.sql 이 아니라 PHP 인가                                │
   │                                                                  │
   │ ba_project_source.file_path 는 **절대 경로**다(업로드된 파일의    │
   │ 실제 위치). SQL 파일에는 이 저장소가 어디에 풀렸는지 적을 수      │
   │ 없다. 여기서 __DIR__ 로 구해 넣는다.                              │
   │                                                                  │
   │   php studio/dev/seed_docs.php            (기본: 1번 프로젝트)    │
   │   php studio/dev/seed_docs.php 2                                  │
   └──────────────────────────────────────────────────────────────────┘ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('명령줄에서만 실행할 수 있습니다.');
}

$portal = dirname(__DIR__, 2);
$cfg    = require $portal . '/config.php';
$d      = $cfg['db'];

if (!in_array($d['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "[중단] config.php 의 DB 가 로컬이 아닙니다 ({$d['host']}).\n");
    exit(2);
}

$pdo = new PDO(
    "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
    $d['user'], $d['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$projectId = (int)($argv[1] ?? 1);
$row = $pdo->prepare('SELECT name FROM ba_project WHERE id = ?');
$row->execute([$projectId]);
$name = $row->fetchColumn();
if ($name === false) {
    fwrite(STDERR, "프로젝트 #$projectId 가 없습니다. 먼저 seed_dev.sql 을 적재하세요.\n");
    exit(2);
}

$fix = __DIR__ . '/fixtures';
$docs = [
    ['xlsx', '요구사항 정의서 (시험용)', 'sample.xlsx'],
    ['pptx', '킥오프 자료 (시험용)',     'sample.pptx'],
    ['docx', '회의록 (시험용)',          'sample.docx'],
    // 하나는 일부러 깨진 파일을 넣는다. 한 건이 실패해도 나머지가
    // 계속 간다는 것을 화면에서 확인할 수 있어야 한다.
    ['xlsx', '깨진 파일 (일부러)',       'broken.xlsx'],
];

$missing = [];
foreach ($docs as [, , $f]) {
    if (!is_file("$fix/$f")) { $missing[] = $f; }
}
if ($missing) {
    fwrite(STDERR, "시험 문서가 없습니다: " . implode(', ', $missing) . "\n"
        . "만들려면:  python studio/dev/fixtures/make_fixtures.py studio/dev/fixtures\n");
    exit(2);
}

// 같은 것을 두 번 넣지 않는다. 제목으로 알아본다.
$del = $pdo->prepare('DELETE FROM ba_project_source WHERE project_id = ? AND title LIKE ?');
$del->execute([$projectId, '%(시험용)']);
$del->execute([$projectId, '%(일부러)']);

$ins = $pdo->prepare(
    'INSERT INTO ba_project_source
        (project_id, kind, title, file_path, file_size, parse_status, uploaded_by, uploaded_by_name)
     VALUES (?,?,?,?,?, "pending", "seed_docs.php", "시드")'
);
foreach ($docs as [$kind, $title, $f]) {
    $ins->execute([$projectId, $kind, $title, "$fix/$f", filesize("$fix/$f")]);
}

echo "프로젝트 #$projectId ($name) 에 문서 " . count($docs) . "건을 붙였습니다.\n";
echo "화면에서 3단계 탭 → [문서 분석] → [문서에서 WBS 도출] 순으로 눌러 보십시오.\n";
echo "\nLLM 은 설정하지 않았으므로 규칙만으로 뽑습니다. 화면이 그 사실을 알려 줍니다.\n";
echo "고정 응답으로 LLM 경로까지 보려면:\n";
echo "  cp studio/inc/llm.config.sample.php studio/inc/llm.config.php\n";

<?php
/** SourceUploader 검증 테스트 — 확장자·MIME·파일명·용량 방어가 실제로 도는지 본다. */
declare(strict_types=1);

// 저장소 어디에 두어도 돌도록 이 파일 위치에서 포털 루트를 찾는다
define('ROOT', dirname(__DIR__, 2));
// 운영/개발 DB 를 건드리지 않도록 **전용 시험 DB** 를 쓴다.
// 없으면 아래 안내대로 만들면 된다.
$TESTDB = getenv('BA_TEST_DB') ?: 'blueassign_test';
require ROOT . '/studio/inc/bootstrap.php';
require ROOT . '/studio/inc/repo/ProjectRepo.php';
require ROOT . '/studio/inc/service/SourceUploader.php';

try {
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$TESTDB};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
} catch (PDOException $e) {
    fwrite(STDERR, "
시험 DB '{$TESTDB}' 에 붙지 못했습니다.
"
        . "먼저 만드세요:
"
        . "  mysql -u root -e \"CREATE DATABASE {$TESTDB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"
"
        . "  mysql -u root {$TESTDB} < studio/sql/001_schema.sql
"
        . "  mysql -u root {$TESTDB} < studio/sql/002_seed_domain.sql

");
    exit(2);
}
$pdo->exec('DELETE FROM ba_project_source');
$pdo->exec('DELETE FROM ba_project');

$repo  = new ProjectRepo($pdo);
$up    = new SourceUploader($repo);
$actor = ['id' => 'kimhy@bluesoft.co.kr', 'name' => '김호영'];
$pid   = $repo->create(['name' => '업로드 테스트'], $actor);

$tmp = sys_get_temp_dir() . '/ba_up_test';
@mkdir($tmp, 0777, true);

$pass = 0; $fail = 0;
function ok(string $w, bool $c, string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  OK   $w\n"; } else { $fail++; echo "  FAIL $w" . ($x ? " — $x" : '') . "\n"; }
}
function throws(string $w, callable $fn): void {
    global $pass, $fail;
    try { $fn(); $fail++; echo "  FAIL $w — 예외가 안 났다\n"; }
    catch (Throwable $e) { $pass++; echo "  OK   $w → " . $e->getMessage() . "\n"; }
}
/** $_FILES 한 건을 흉내낸다. */
function fakeFile(string $path, string $name): array {
    return ['name' => $name, 'type' => 'application/octet-stream',
            'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}
function makePng(string $p): string {
    // 1x1 PNG
    file_put_contents($p, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    return $p;
}
function makePdf(string $p): string {
    file_put_contents($p, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    return $p;
}
function makeZip(string $p): string {
    $z = new ZipArchive();
    $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $z->close();
    return $p;
}

echo "\n[1] 정상 업로드\n";
$r = $up->storeOne($pid, fakeFile(makePng("$tmp/a.png"), '시안 이미지.png'), $actor);
ok('PNG 저장', $r['kind'] === 'image' && $r['id'] > 0);
ok('원본명 보존', $r['title'] === '시안 이미지.png');
ok('parse_status=pending', $r['parse_status'] === 'pending');

$row = $repo->findSource($r['id']);
ok('저장 경로가 BA_UPLOAD_DIR 아래', str_starts_with(str_replace('\\','/',$row['file_path']),
                                                     str_replace('\\','/',BA_UPLOAD_DIR)));
ok('저장 파일명에 원본명이 안 들어간다', !str_contains($row['file_path'], '시안'));
ok('저장 파일명은 해시_난수.확장자',
   (bool)preg_match('/[0-9a-f]{16}_[0-9a-f]{8}\.png$/', $row['file_path']), basename($row['file_path']));
ok('파일이 실제로 있다', is_file($row['file_path']));
ok('MIME 은 감지값', $row['mime'] === 'image/png', (string)$row['mime']);

$r2 = $up->storeOne($pid, fakeFile(makePdf("$tmp/b.pdf"), 'spec.pdf'), $actor);
ok('PDF 저장', $r2['kind'] === 'pdf');
$r3 = $up->storeOne($pid, fakeFile(makeZip("$tmp/c.xlsx"), '범위.xlsx'), $actor);
ok('xlsx(zip) 저장', $r3['kind'] === 'xlsx');

echo "\n[2] 확장자 화이트리스트\n";
throws('exe 거부',  fn() => $up->storeOne($pid, fakeFile(makePng("$tmp/d.exe"), 'evil.exe'), $actor));
throws('php 거부',  fn() => $up->storeOne($pid, fakeFile(makePng("$tmp/e.php"), 'shell.php'), $actor));
throws('확장자 없음 거부', fn() => $up->storeOne($pid, fakeFile(makePng("$tmp/f"), 'noext'), $actor));
throws('svg 거부(실행 위험)', fn() => $up->storeOne($pid, fakeFile(makePng("$tmp/g.svg"), 'x.svg'), $actor));

echo "\n[3] MIME 재검증 — 확장자 위장\n";
file_put_contents("$tmp/fake.png", "<?php system(\$_GET['c']); ?>");
throws('내용이 PHP 인데 .png', fn() => $up->storeOne($pid, fakeFile("$tmp/fake.png", 'fake.png'), $actor));
file_put_contents("$tmp/fake2.pdf", 'not a pdf at all, just text');
throws('내용이 텍스트인데 .pdf', fn() => $up->storeOne($pid, fakeFile("$tmp/fake2.pdf", 'fake2.pdf'), $actor));

echo "\n[4] 파일명 정리\n";
$r4 = $up->storeOne($pid, fakeFile(makePng("$tmp/h.png"), '../../../etc/passwd.png'), $actor);
ok('경로 조작 제거', $repo->findSource($r4['id'])['title'] === 'passwd.png',
   $repo->findSource($r4['id'])['title']);
$r5 = $up->storeOne($pid, fakeFile(makePng("$tmp/i.png"), "탭\t과 개행\n.png"), $actor);
ok('제어문자 제거', !preg_match('/[\x00-\x1F]/', $repo->findSource($r5['id'])['title']));

echo "\n[5] 용량\n";
file_put_contents("$tmp/empty.png", '');
throws('빈 파일 거부', fn() => $up->storeOne($pid, fakeFile("$tmp/empty.png", 'empty.png'), $actor));

echo "\n[6] 다중 업로드 — 일부 실패해도 나머지는 살린다\n";
$multi = [
    'name'     => ['good1.png', 'bad.exe', 'good2.png'],
    'type'     => ['', '', ''],
    'tmp_name' => [makePng("$tmp/m1.png"), makePng("$tmp/m2.exe"), makePng("$tmp/m3.png")],
    'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK],
    'size'     => [filesize("$tmp/m1.png"), filesize("$tmp/m2.exe"), filesize("$tmp/m3.png")],
];
$res = $up->storeMany($pid, $multi, $actor);
ok('성공 2건', count($res['saved']) === 2, (string)count($res['saved']));
ok('실패 1건', count($res['failed']) === 1);
ok('실패 항목에 이름과 사유', $res['failed'][0]['name'] === 'bad.exe' && $res['failed'][0]['message'] !== '');

echo "\n[7] 링크\n";
$l = $up->storeLink($pid, 'https://www.figma.com/file/abc123/Design', '', $actor);
ok('figma 로 분류', $l['kind'] === 'figma');
ok('제목 자동 생성', $l['title'] !== '');
ok('parse_status=skip', $l['parse_status'] === 'skip');
$l2 = $up->storeLink($pid, 'https://drive.google.com/x', '설계서', $actor);
ok('일반 링크는 url', $l2['kind'] === 'url');
throws('javascript: 거부', fn() => $up->storeLink($pid, 'javascript:alert(1)', '', $actor));
throws('상대경로 거부',   fn() => $up->storeLink($pid, '/etc/passwd', '', $actor));
throws('빈 주소 거부',    fn() => $up->storeLink($pid, '', '', $actor));

echo "\n[8] 직접 입력\n";
$t = $up->storeText($pid, "첫 줄이 제목이 된다\n본문 내용", '', $actor);
ok('첫 줄을 제목으로', $t['title'] === '첫 줄이 제목이 된다', $t['title']);
ok('parse_status=ok', $t['parse_status'] === 'ok');
ok('parsed_text 저장', $repo->findSource($t['id'])['parsed_text'] !== null);
throws('빈 내용 거부', fn() => $up->storeText($pid, '   ', '', $actor));

echo "\n[9] 삭제 시 실제 파일도 지운다\n";
$path = $repo->findSource($r['id'])['file_path'];
ok('삭제 전 파일 있음', is_file($path));
$repo->deleteSource($r['id']);
ok('삭제 후 파일 없음', !is_file($path));

echo "\n=============================\n";
echo "통과 $pass / 실패 $fail\n";

// 뒷정리
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
exit($fail > 0 ? 1 : 0);

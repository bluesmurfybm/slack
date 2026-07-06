<?php
/**
 * 대학 사이트 목록 xlsx → schools 테이블 시딩 (CLI, 최초 1회)
 *   시트별 버전: 3.5 / 3.9 / 4.5
 *   컬럼:  3.5 → 대학명 A, 개발 E, 운영 F  /  3.9·4.5 → 대학명 A, 개발 D, 운영 E
 *   사용:  php schools_import.php ["xlsx경로"] [--force]
 *          --force 없으면 테이블에 데이터가 있을 때 중단(기존 편집 보호)
 */
require_once __DIR__ . '/db.php';

$args   = $argv;
$force  = in_array('--force', $args, true);
$args   = array_values(array_filter($args, fn($a) => $a !== '--force'));
$path   = $args[1] ?? 'C:/Users/bluebm/OneDrive/바탕 화면/bbbbb.xlsx';

$pdo = db();
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn();
if ($cnt > 0 && !$force) {
    fwrite(STDERR, "schools 테이블에 이미 {$cnt}건 있습니다. 덮어쓰려면 --force 를 붙이세요.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($path) !== true) { fwrite(STDERR, "xlsx 열기 실패: $path\n"); exit(1); }

$shared = [];
if (($s = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
    $xml = simplexml_load_string($s);
    foreach ($xml->si as $si) {
        $t = '';
        if (isset($si->t)) $t = (string)$si->t;
        else foreach ($si->r as $r) $t .= (string)$r->t;
        $shared[] = $t;
    }
}
$colL = fn($ref) => preg_replace('/[0-9]/', '', $ref);

function readSheet($zip, $shared, $colL, $file) {
    $x = simplexml_load_string($zip->getFromName($file));
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $c = [];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r']; $t = (string)$cell['t']; $v = (string)$cell->v;
            $val = ($t === 's') ? ($shared[(int)$v] ?? '') : (($t === 'inlineStr') ? (string)$cell->is->t : $v);
            $c[$colL($ref)] = $val;
        }
        $rows[] = $c;
    }
    return $rows;
}
function normUrl($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (preg_match('~https?://[^\s]+~i', $u, $m)) return rtrim($m[0], " \t\r\n.,;");
    $tok = rtrim(preg_split('/\s+/', $u)[0], " \t\r\n.,;/");
    if ($tok === '' || !preg_match('~[a-z0-9]\.[a-z]~i', $tok)) return '';
    return (stripos($tok, 'moodler.kr') !== false ? 'http://' : 'https://') . $tok;
}

$sheets = [
    // 3.5 시트: 실제 버전이 2.9/3.2/3.5 혼재 → 컬럼 C(무들 버전)에서 major.minor 추출
    ['file' => 'xl/worksheets/sheet1.xml', 'ver' => null, 'verCol' => 'C', 'dev' => 'E', 'ops' => 'F'],
    ['file' => 'xl/worksheets/sheet2.xml', 'ver' => '3.9', 'dev' => 'D', 'ops' => 'E'],
    ['file' => 'xl/worksheets/sheet3.xml', 'ver' => '4.5', 'dev' => 'D', 'ops' => 'E'],
];

$pdo->exec("TRUNCATE TABLE schools");   // DDL → 트랜잭션 밖에서 실행(암묵적 커밋)
$pdo->beginTransaction();
$ins = $pdo->prepare("INSERT INTO schools (name, ver, dev, ops, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
$seed = [];
foreach ($sheets as $sh) {
    $rows = readSheet($zip, $shared, $colL, $sh['file']);
    for ($i = 2; $i < count($rows); $i++) {
        $name = trim($rows[$i]['A'] ?? '');
        if ($name === '' || $name === '대학 ( 기관 ) 명') continue;
        $ver = $sh['ver'];
        if ($ver === null) {   // 컬럼 C에서 major.minor(소수점 1자리) 추출, 없으면 3.5
            $cval = trim($rows[$i][$sh['verCol']] ?? '');
            $ver = preg_match('/(\d+)\.(\d+)/', $cval, $m) ? "{$m[1]}.{$m[2]}" : '3.5';
        }
        $dev = normUrl($rows[$i][$sh['dev']] ?? '');
        $ops = normUrl($rows[$i][$sh['ops']] ?? '');
        $ins->execute([$name, $ver, $dev, $ops]);
        $seed[] = ['name' => $name, 'ver' => $ver, 'dev' => $dev, 'ops' => $ops, 'active' => 1];
    }
}
$pdo->commit();
$zip->close();
echo count($seed) . "개 학교를 schools 테이블에 시딩했습니다.\n";
echo "※ git 배포용 seed 파일은 'php schools_export.php' 로 생성하세요(현재 테이블 그대로 반영).\n";

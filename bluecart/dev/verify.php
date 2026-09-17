<?php
/**
 * 내 로컬 파일이 배포본과 같은지 확인한다.
 *
 *   php dev/verify.php
 *   php dev/verify.php --manifest=다른경로\MANIFEST.sha256
 *
 * MANIFEST.sha256 에 적힌 해시와 실제 파일을 대조해
 * 무엇을 덮어써야 하는지 알려 줍니다.
 *
 * 설정 파일, 첨부 저장소, 경로 지정 파일처럼 사람마다 다른 것은 제외합니다.
 *
 * PHP 5.6 문법으로 작성되어 있습니다. PHP 가 낮아도 확인은 할 수 있어야 하니까요.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('CLI 전용입니다.');
}

$root = dirname(__DIR__);

$manifest = $root . '/MANIFEST.sha256';
foreach ($argv as $a) {
    if (strpos($a, '--manifest=') === 0) {
        $manifest = substr($a, 11);
    }
}

echo PHP_EOL;
echo "BlueCart 파일 대조" . PHP_EOL;
echo str_repeat('-', 62) . PHP_EOL;

if (!is_file($manifest)) {
    echo "MANIFEST.sha256 를 찾을 수 없습니다: $manifest" . PHP_EOL;
    echo "배포본 압축에 함께 들어 있습니다." . PHP_EOL . PHP_EOL;
    exit(1);
}

// ---------------------------------------------------------------------
$expected = array();
$version  = '(알 수 없음)';

foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, '# version:') === 0) {
        $version = trim(substr($line, 10));
        continue;
    }
    if ($line[0] === '#') {
        continue;
    }
    // "해시  경로" 형식
    $parts = preg_split('/\s+/', $line, 2);
    if (count($parts) === 2) {
        $expected[trim($parts[1])] = trim($parts[0]);
    }
}

echo "배포본: $version" . PHP_EOL;
echo "대상 파일 " . count($expected) . "개" . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------
$missing  = array();
$changed  = array();
$same     = 0;

foreach ($expected as $rel => $hash) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        $missing[] = $rel;
        continue;
    }
    if (strtolower(hash_file('sha256', $path)) !== strtolower($hash)) {
        $changed[] = $rel;
        continue;
    }
    $same++;
}

if (!$missing && !$changed) {
    echo "모든 파일이 배포본과 같습니다. ($same 개)" . PHP_EOL . PHP_EOL;
    exit(0);
}

if ($missing) {
    echo "없는 파일 (" . count($missing) . "개) — 새로 추가된 파일입니다" . PHP_EOL;
    foreach ($missing as $m) {
        echo "  + $m" . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($changed) {
    echo "내용이 다른 파일 (" . count($changed) . "개) — 덮어쓰셔야 합니다" . PHP_EOL;
    foreach ($changed as $c) {
        echo "  * $c" . PHP_EOL;
    }
    echo PHP_EOL;
}

echo "같은 파일 $same 개" . PHP_EOL;
echo str_repeat('-', 62) . PHP_EOL;
echo "위 파일들만 배포본에서 복사하면 됩니다." . PHP_EOL;
echo "일부러 고치신 파일이 있다면 그건 그대로 두셔도 됩니다." . PHP_EOL . PHP_EOL;

exit(1);

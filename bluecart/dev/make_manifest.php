<?php
/**
 * MANIFEST.sha256 를 만든다. 배포본을 묶기 전에 실행한다.
 *
 *   php dev/make_manifest.php [버전표시]
 *
 * 사람마다 달라지는 파일(설정, 경로 지정, 첨부 실물)은 제외한다.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI 전용입니다.');
}

$root  = dirname(__DIR__);
$label = $argv[1] ?? date('Y-m-d');

// 사람마다 다른 파일은 대조 대상이 아니다.
$skipExact = [
    'config/config.php',
    'dev/php-path.txt',
    'dev/mysql-path.txt',
    'MANIFEST.sha256',
];
$skipDirs = ['.git', 'bluecart-data', 'node_modules'];

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $path => $info) {
    $rel = str_replace('\\', '/', substr((string)$path, strlen($root) + 1));

    foreach ($skipDirs as $d) {
        if ($rel === $d || str_starts_with($rel, $d . '/')) {
            continue 2;
        }
    }
    if (!$info->isFile() || in_array($rel, $skipExact, true)) {
        continue;
    }
    $files[$rel] = hash_file('sha256', (string)$path);
}

ksort($files);

$out  = "# BlueCart 파일 목록과 해시\n";
$out .= "# version: {$label}\n";
$out .= "# 확인:  php dev/verify.php\n";
$out .= "#\n";
$out .= "# config/config.php, dev/php-path.txt, dev/mysql-path.txt 는\n";
$out .= "# 사람마다 다르므로 대조 대상에서 뺐습니다.\n";
$out .= "#\n";
foreach ($files as $rel => $hash) {
    $out .= sprintf("%s  %s\n", $hash, $rel);
}

file_put_contents($root . '/MANIFEST.sha256', $out);
echo "MANIFEST.sha256 생성: " . count($files) . "개 파일, 버전 {$label}" . PHP_EOL;

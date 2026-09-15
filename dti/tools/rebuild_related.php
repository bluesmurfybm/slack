<?php
/**
 * 연관 아티클 점수를 다시 계산한다. CLI 전용.
 *
 *   php dti/tools/rebuild_related.php
 *
 * 등록·수정·삭제 때는 앱이 알아서 다시 계산한다. 이 도구는 배점 상수를 바꿨을 때 쓴다 —
 * FastAPI 시절에는 기동할 때마다 다시 계산했지만 PHP 에는 기동 훅이 없다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI 에서만 실행한다\n");
}

require __DIR__ . '/../bootstrap.php';

$config = dti_config_from_portal();
$pdo = dti_connect($config);
dti_migrate($pdo);

$count = dti_related_rebuild($pdo);
echo "연관 {$count}쌍을 다시 계산했다\n";

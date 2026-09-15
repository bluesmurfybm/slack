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

require __DIR__ . '/../../vendor/autoload.php';

$config = Dti\Config::fromPortal();
$database = new Dti\Database($config);
$database->migrate();

$pdo = $database->pdo();
$service = new Dti\Service\RelatedService(
    new Dti\Repository\TopicRepository($pdo),
    new Dti\Repository\RelatedRepository($pdo),
);

$count = $service->rebuild();
echo "연관 {$count}쌍을 다시 계산했다\n";

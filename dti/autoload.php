<?php
/**
 * dti 전용 PSR-4 오토로더.
 *
 * dti 는 런타임 의존성이 없다 — 소스가 쓰는 건 PHP 내장 PDO 뿐이다. composer 는 테스트
 * (PHPUnit)에만 쓴다. 서버에 composer 가 없어도 파일만 복사하면 돌아가야 하기 때문이다
 * (access·moodle·learn 과 같은 배포 방식).
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dti\\';
    if (!str_starts_with($class, $prefix)) return;

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

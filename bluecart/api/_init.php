<?php
/** 모든 API 진입점 공통 전처리. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

set_exception_handler(function (Throwable $e) {
    if ($e instanceof DomainException || $e instanceof InvalidArgumentException) {
        bc_json_error($e->getMessage(), 400);
    }
    error_log('[BlueCart] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $msg = bc_config('app.debug') ? $e->getMessage() : '처리 중 오류가 발생했습니다.';
    bc_json_error($msg, 500);
});

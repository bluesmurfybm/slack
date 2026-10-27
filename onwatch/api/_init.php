<?php
/** 모든 API 진입점 공통 전처리 — bluecart/api/_init.php 와 같은 모양. */
require_once dirname(__DIR__) . '/inc/bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

set_exception_handler(function (Throwable $e) {
    if ($e instanceof DomainException || $e instanceof InvalidArgumentException) {
        ow_json_error($e->getMessage(), 400);
    }
    error_log('[OnWatch] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    ow_json_error('처리 중 오류가 발생했습니다.', 500);
});

/** id 로 감시를 찾고 없으면 404 */
function ow_api_watch(): array
{
    $id = ow_param_int('id');
    $w = $id ? ow_watch_get($id) : null;
    if (!$w) ow_json_error('감시를 찾을 수 없습니다.', 404);
    return $w;
}

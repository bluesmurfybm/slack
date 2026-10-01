<?php
/** 모든 API 진입점의 공통 전처리 — 부트스트랩, 보안 헤더, 전역 예외 핸들러, act 라우팅 헬퍼. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/**
 * 전역 예외 핸들러 (BlueCart api/_init.php 와 같은 방식).
 *
 * 검증 실패는 예외를 던지면 자동으로 400 이 된다. 도메인/서비스 계층이
 * 이걸 이용해 스스로 응답 형식을 알 필요가 없게 한다.
 */
set_exception_handler(function (Throwable $e) {
    if ($e instanceof InvalidArgumentException) {
        bs_json_error('INVALID_ARGUMENT', $e->getMessage(), 400);
    }
    if ($e instanceof DomainException) {
        bs_json_error('DOMAIN_ERROR', $e->getMessage(), 400);
    }
    error_log('[BlueStudio] ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    // TODO(P1): 디버그 여부를 어디서 읽을지 정한다. BlueCart 는 자체 config 의
    //           app.debug 를 보지만 이 모듈은 별도 config 가 없다.
    //           지금은 항상 감춘다 — 운영에서 내부 메시지가 새는 쪽이 더 나쁘다.
    bs_json_error('INTERNAL_ERROR', '처리 중 오류가 발생했습니다.', 500);
});

/**
 * act 라우팅.
 *
 * 각 엔드포인트가 아래처럼 쓴다.
 *
 *   bs_route(bs_param_str('act', 'list'), [
 *       'list'   => fn() => ...,
 *       'create' => fn() => ...,
 *   ]);
 *
 * 없는 act 면 400 으로 끊고, 어떤 값이 가능한지 함께 알려준다.
 * 핸들러 안에서 bs_json_ok() 를 부르므로 정상 경로는 여기로 돌아오지 않는다.
 */
function bs_route(string $act, array $handlers): never
{
    if (!isset($handlers[$act])) {
        bs_json_error(
            'UNKNOWN_ACT',
            '알 수 없는 요청입니다: ' . $act . ' (가능: ' . implode(', ', array_keys($handlers)) . ')',
            400
        );
    }
    $handlers[$act]();

    // 핸들러가 응답하지 않고 끝난 경우 — 구현이 덜 된 것이다.
    bs_json_error('NOT_IMPLEMENTED', '아직 구현되지 않은 기능입니다: ' . $act, 501);
}

/**
 * 쓰기 요청 공통 전처리.
 * POST 확인 → 로그인 → CSRF 순. BlueCart 의 진입부 순서와 같다.
 */
function bs_begin_write(): array
{
    bs_require_post();
    $user = bs_require_login_api();
    bs_verify_csrf();
    return $user;
}

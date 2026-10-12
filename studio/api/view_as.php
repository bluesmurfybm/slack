<?php
/**
 * 관리자의 "사용자로 보기" — 보기 전용, BlueStudio 안에서만 (2026-10-12).
 *
 * POST api/view_as.php?act=start   {user_id: 이메일}   그 사람의 눈으로 본다
 * POST api/view_as.php?act=stop                        원래대로
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ bs_begin_write() 를 쓰지 않는다                                    │
 * │                                                                  │
 * │ 그 함수는 보는 중이면 막는다. [돌아가기] 가 막히면 빠져나올 길이  │
 * │ 없다. 대신 POST·CSRF 를 직접 보고, 권한은 **실제로 로그인한 사람** │
 * │ (bs_real_user)이 관리자인지로 본다 — 보는 동안 bs_is_admin() 은    │
 * │ 대상 사람 기준이라 거짓이다.                                       │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

bs_route(bs_param_str('act', ''), [

    'start' => function (): void {
        bs_require_post();
        bs_require_login_api();
        bs_verify_csrf();
        $real = bs_real_user();
        if (!board_is_admin((string)$real['id'])) {
            bs_json_error('FORBIDDEN', '관리자만 다른 사용자로 볼 수 있습니다.', 403);
        }

        $email = trim(bs_param_str('user_id'));
        if ($email === '') {
            bs_json_error('MISSING_PARAM', '누구로 볼지 정하세요.', 400);
        }
        if (strcasecmp($email, (string)$real['id']) === 0) {
            bs_json_error('INVALID_ARGUMENT', '자기 자신으로는 볼 필요가 없습니다.', 400);
        }
        $target = bs_portal_user_by_email($email);
        if ($target === null) {
            bs_json_error('NOT_FOUND', '포털 사용자 명단에 없는 사람입니다: ' . $email, 404);
        }

        bs_view_as_log_end((string)$real['id']);       // 앞에 보던 것이 있으면 닫는다
        $_SESSION['bs_view_as'] = (string)$target['email'];
        bs_view_as_log_start($real, $target);

        bs_json_ok([
            'target'  => ['id' => $target['email'], 'name' => $target['name']],
            'message' => $target['name'] . ' 님으로 봅니다. 보기만 되고 저장은 막힙니다.',
        ]);
    },

    'stop' => function (): void {
        bs_require_post();
        bs_require_login_api();
        bs_verify_csrf();
        $real = bs_real_user();
        unset($_SESSION['bs_view_as']);
        bs_view_as_log_end((string)$real['id']);
        bs_json_ok(['message' => '원래대로 돌아왔습니다.']);
    },
]);

/**
 * 기록. 표(025)가 없는 서버에서도 보기 기능은 돌아야 하므로 실패는 삼킨다 —
 * 대신 오류 기록에는 남긴다.
 */
function bs_view_as_log_start(array $real, array $target): void
{
    try {
        bs_db()->prepare(
            'INSERT INTO bs_view_as_log (admin_id, admin_name, target_id, target_name, ip)
             VALUES (?,?,?,?,?)'
        )->execute([
            (string)$real['id'], (string)($real['name'] ?? ''),
            (string)$target['email'], (string)$target['name'],
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('[BlueStudio] view_as 기록 실패: ' . $e->getMessage());
    }
}

function bs_view_as_log_end(string $adminId): void
{
    try {
        bs_db()->prepare(
            'UPDATE bs_view_as_log SET ended_at = NOW()
              WHERE admin_id = ? AND ended_at IS NULL'
        )->execute([$adminId]);
    } catch (Throwable $e) {
        error_log('[BlueStudio] view_as 기록 실패: ' . $e->getMessage());
    }
}

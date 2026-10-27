<?php
/**
 * 전역 설정 — 관리자 전용 (2026-10-14).
 *
 * POST api/setting.php?act=save_rnd_caps   R&D 점유 상한 넷을 한 번에 저장
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 상한을 없애지 않고 **관리자가 고치게** 한다                        │
 * │                                                                  │
 * │ 통제 없이는 R&D 가 배정 회피 수단이 된다(CLAUDE.md 2항). 그래서   │
 * │ 값은 bs_setting 에 두고 코드에 박지 않았는데, 고칠 화면이 없어     │
 * │ DB 를 직접 만져야 했다. 범위를 벗어난 값은 받지 않는다 — 실수로   │
 * │ 0 을 넣어 모든 승인이 막히거나 통제가 통째로 풀리지 않게.          │
 * │ 사실상 풀고 싶으면 1.00(100%) 으로 둔다. 누가 바꿨는지 남는다.    │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

bs_route(bs_param_str('act', ''), [

    'save_rnd_caps' => function (): void {
        $user = bs_begin_write();
        if (!bs_is_admin()) {
            bs_json_error('FORBIDDEN', '관리자만 바꿀 수 있습니다.', 403);
        }

        // 화면은 % 로 받는다. 저장은 비율(0~1)과 정수다.
        $vals = [
            'rnd_total_cap'       => bs_setting_input_pct('total_pct',   5, 100, '1인 합계 상한'),
            'rnd_per_project_cap' => bs_setting_input_pct('project_pct', 5, 100, '과제 하나당 상한'),
            'rnd_concurrent_max'  => (string)bs_setting_input_int('concurrent', 1, 20, '동시 참여 과제 수'),
            'rnd_stale_weeks'     => (string)bs_setting_input_int('stale_weeks', 1, 52, '정체 판정 기간'),
        ];
        if ((float)$vals['rnd_per_project_cap'] > (float)$vals['rnd_total_cap']) {
            bs_json_error('INVALID_ARGUMENT',
                '과제 하나당 상한이 1인 합계 상한보다 클 수 없습니다 — 그 값으로는 한 과제도 다 못 채웁니다.', 400);
        }

        $st = bs_db()->prepare(
            'INSERT INTO bs_setting (k, v, label, updated_by, updated_by_name)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v),
                updated_by = VALUES(updated_by), updated_by_name = VALUES(updated_by_name)'
        );
        foreach ($vals as $k => $v) {
            $st->execute([$k, $v, $k, $user['id'] ?? null, $user['name'] ?? null]);
        }

        bs_json_ok([
            'values'  => $vals,
            'message' => 'R&D 점유 상한을 저장했습니다. 이미 승인된 참여는 그대로이고, '
                       . '이후의 승인부터 새 값으로 봅니다.',
        ]);
    },
]);

/** 5~100 같은 % 입력 → '0.300' 같은 비율 문자열. 범위를 벗어나면 400. */
function bs_setting_input_pct(string $k, int $min, int $max, string $label): string
{
    $raw = trim(bs_param_str($k));
    if ($raw === '' || !is_numeric($raw) || (float)$raw < $min || (float)$raw > $max) {
        bs_json_error('INVALID_ARGUMENT', sprintf('%s 은 %d~%d%% 사이로 넣어 주세요.', $label, $min, $max), 400);
    }
    return number_format((float)$raw / 100, 3, '.', '');
}

function bs_setting_input_int(string $k, int $min, int $max, string $label): int
{
    $raw = trim(bs_param_str($k));
    if ($raw === '' || !ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
        bs_json_error('INVALID_ARGUMENT', sprintf('%s 은 %d~%d 사이 정수로 넣어 주세요.', $label, $min, $max), 400);
    }
    return (int)$raw;
}

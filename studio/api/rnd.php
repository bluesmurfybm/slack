<?php
/**
 * R&D 과제 API — 보드·상세·발의·수정·승인·반려 (명세서 §8).
 *
 * GET  api/rnd.php?act=board     보드 목록 + 상태별 건수 + 머리 통계
 * GET  api/rnd.php?act=get&id=1  상세
 * POST api/rnd.php?act=propose   발의
 * POST api/rnd.php?act=update    수정 (발의자는 승인 전까지만)
 * POST api/rnd.php?act=approve   승인
 * POST api/rnd.php?act=reject    반려 (사유 필수)
 *
 * **가시성은 RndRepo 가 쿼리에서 강제한다.** 이 파일은 그 결과를 그대로
 * 내보낸다 — 여기서 다시 거르지 않는다. 두 곳에서 거르면 언젠가 어긋난다.
 *
 * 합류와 점유 반영은 P9-3 범위다. 이 파일에는 없다.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/presenter.php';
require_once BS_ROOT . '/inc/repo/RndRepo.php';

$repo = new RndRepo(bs_db());

bs_route(bs_param_str('act', 'board'), [

    // =================================================================
    // 조회 — 로그인만 하면 부를 수 있다. 무엇이 보이는지는 Repo 가 정한다
    // =================================================================

    'board' => function () use ($repo): void {
        bs_require_login_api();

        $filter = [
            'category'   => bs_param_str('category'),
            'status'     => bs_param_str('status'),
            'recruiting' => bs_param_str('recruiting') === '1',
            'mine'       => bs_param_str('mine') === '1',
            'keyword'    => bs_param_str('keyword'),
            'sort'       => bs_param_str('sort', 'recent'),
            'page'       => bs_param_int('page', 1),
            'size'       => bs_param_int('size', 24),
        ];

        $result = $repo->board($filter);

        bs_json_ok([
            'rows'   => array_map('bs_present_rnd_row', $result['rows']),
            'total'  => $result['total'],
            'page'   => $result['page'],
            'size'   => $result['size'],
            'pages'  => $result['pages'],
            'counts' => $repo->statusCounts($filter),
            'stats'  => $repo->stats(),
            // 화면이 단추를 그릴 때 쓴다. 발의는 로그인한 사람 누구나 한다.
            'can'    => [
                'propose' => true,
                'approve' => bs_can(BS_CAP_RND_APPROVE),
            ],
        ]);
    },

    'get' => function () use ($repo): void {
        bs_require_login_api();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        $r = $repo->find($id);
        if (!$r) {
            // 못 보는 과제와 없는 과제를 **같게** 답한다. 가르면
            // 비공개 과제가 존재한다는 사실이 샌다.
            bs_json_error('NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
        }

        bs_json_ok(['rnd' => bs_present_rnd($r)]);
    },

    // =================================================================
    // 변경
    // =================================================================

    'propose' => function () use ($repo): void {
        $user = bs_begin_write();

        $id = $repo->propose(bs_read_rnd_input(), $user);
        $r  = $repo->find($id);

        bs_json_ok([
            'id'      => $id,
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'message' => '과제를 발의했습니다. 승인되면 보드에 올라갑니다.',
        ]);
    },

    'update' => function () use ($repo): void {
        bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        // 권한 판정은 Repo 안에 있다(bs_rnd_can_edit). 여기서 또 보지 않는다.
        $repo->update($id, bs_read_rnd_input(true));
        $r = $repo->find($id);

        bs_json_ok([
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'message' => '수정했습니다.',
        ]);
    },

    'approve' => function () use ($repo): void {
        $user = bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        $repo->approve($id, $user);
        $r = $repo->find($id);

        bs_json_ok([
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'message' => '승인했습니다.',
            // 사람이 오해하지 않게 적어 둔다 — 승인은 점유를 만들지 않는다.
            'notice'  => '승인은 과제를 열어 줄 뿐입니다. 가용도는 사람이 합류해야 바뀝니다.',
        ]);
    },

    'reject' => function () use ($repo): void {
        $user = bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '과제 번호가 없습니다.', 400);
        }

        $repo->reject($id, bs_param_str('reason'), $user);
        $r = $repo->find($id);

        bs_json_ok([
            'rnd'     => $r ? bs_present_rnd($r) : null,
            'message' => '반려했습니다. 발의자가 고쳐서 다시 낼 수 있습니다.',
        ]);
    },
]);

// =====================================================================

/**
 * 발의·수정 입력 읽기.
 *
 * $partial 이면 **넘어온 키만** 돌려준다. 수정에서 빠진 칸을 null 로
 * 덮으면, 화면이 한 칸만 보냈을 때 나머지가 조용히 지워진다.
 */
function bs_read_rnd_input(bool $partial = false): array
{
    $keys = ['name', 'summary', 'notes', 'rnd_category', 'visibility',
             'dev_start', 'dev_end', 'load_cap', 'recruiting', 'status'];

    $out = [];
    foreach ($keys as $k) {
        if ($partial && !bs_has_param($k)) {
            continue;
        }
        $out[$k] = match ($k) {
            'recruiting' => bs_param_str($k) === '1' || bs_param_str($k) === 'true',
            'load_cap'   => bs_param_str($k),
            default      => bs_param_str($k),
        };
    }

    // status 는 발의에서만 쓴다(draft 로 저장할지 바로 낼지). 수정에서는
    // 무시한다 — 상태 전이는 approve/reject 전용 경로다.
    if ($partial) {
        unset($out['status']);
    }
    return $out;
}

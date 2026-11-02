<?php
/**
 * 아이디어·제안 API (032).
 *
 * GET  api/idea.php?act=board&q=&status=&category=&mine=1&sort=new&page=1
 * GET  api/idea.php?act=get&id=1              상세 + 피드백. 열면 그 아이디어 알림을 읽음으로
 * GET  api/idea.php?act=similar&title=...     쓰는 중에 비슷한 아이디어 찾기
 * POST api/idea.php?act=create                올리기
 * POST api/idea.php?act=update                고치기 (올린 사람 — 채택 전까지)
 * POST api/idea.php?act=delete
 * POST api/idea.php?act=rate                  평가 (가치·실현 가능성 1~5 + 한줄 의견)
 * POST api/idea.php?act=unrate
 * POST api/idea.php?act=comment               피드백·답글
 * POST api/idea.php?act=comment_update
 * POST api/idea.php?act=comment_delete
 * POST api/idea.php?act=review                채택·보류·반려 (관리자)
 * POST api/idea.php?act=merge                 비슷한 아이디어로 묶기·풀기 (관리자)
 * POST api/idea.php?act=promote_rnd           R&D 과제로 (발의됨에서 시작)
 * POST api/idea.php?act=promote_project       프로젝트로 바로 (과제 건너뛰기)
 *
 * 권한은 presenter.php 의 bs_idea_can_*() 가 정하고, 화면은 각 아이디어의
 * actions 배열만 보고 단추를 그린다. 쓰기는 IdeaRepo 가 한 번 더 묻는다.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/presenter.php';
require_once BS_ROOT . '/inc/repo/IdeaRepo.php';
require_once BS_ROOT . '/inc/service/IdeaNotice.php';
require_once BS_ROOT . '/inc/service/PromoteService.php';

$pdo   = bs_db();
$ideas = new IdeaRepo($pdo);

// 032 가 없는 DB 면 무엇을 눌러도 "처리 중 오류" 한 줄만 떴다(2026-11-02, 코드만
// 먼저 올라간 서버). 원인과 할 일을 그대로 알린다.
if (!IdeaRepo::schemaReady($pdo)) {
    bs_json_error('SCHEMA_MISSING',
        '이 서버의 DB 에 아이디어 표가 아직 없습니다. 관리자가 studio/sql/032_migration_idea.sql 을 적용해야 쓸 수 있습니다.',
        503);
}

bs_route(bs_param_str('act', 'board'), [

    // =================================================================
    // 조회 — 로그인한 사람 누구나
    // =================================================================

    'board' => function () use ($ideas): void {
        bs_require_login_api();
        $page = max(1, bs_param_int('page', 1));
        $size = 30;
        $r = $ideas->board([
            'q'            => bs_param_str('q'),
            'status'       => bs_param_str('status'),
            'category'     => bs_param_str('category'),
            'mine'         => bs_param_str('mine') === '1',
            'with_deleted' => bs_param_str('with_deleted') === '1',
            'sort'         => bs_param_str('sort', 'new'),
        ], $size, ($page - 1) * $size);

        // 안 읽은 알림이 걸린 아이디어에 NEW 를 붙인다. 따로 '본 기록' 표를
        // 두지 않는다 — 알림함이 이미 사람마다 읽음을 들고 있다.
        $unread = bs_idea_portal_uid() ? IdeaNotice::unreadIds(bs_idea_portal_uid()) : [];
        foreach ($r['rows'] as &$row) {
            $row['is_new'] = isset($unread[$row['id']]);
        }
        unset($row);

        bs_json_ok($r + [
            'page'  => $page,
            'pages' => max(1, (int)ceil($r['total'] / $size)),
            'can'   => ['create' => true, 'review' => bs_can(BS_CAP_IDEA_REVIEW)],
            'sorts' => IdeaRepo::SORTS,
        ]);
    },

    'get' => function () use ($ideas): void {
        bs_require_login_api();
        $id = bs_idea_id_param();
        $p  = $ideas->get($id);
        if (!$p) {
            bs_json_error('NOT_FOUND', '아이디어를 찾을 수 없습니다.', 404);
        }
        // 열어 봤으면 이 아이디어의 알림은 읽은 것이다 — OnWatch 와 같은 방식.
        if (bs_idea_portal_uid()) {
            IdeaNotice::markRead(bs_idea_portal_uid(), $id);
        }
        bs_json_ok([
            'idea'     => $p,
            'comments' => $ideas->comments($id),
            'axes'     => BS_IDEA_AXES,
        ]);
    },

    'similar' => function () use ($ideas): void {
        bs_require_login_api();
        bs_json_ok(['rows' => $ideas->similar(bs_param_str('title'), bs_param_int('exclude', 0))]);
    },

    // =================================================================
    // 본문
    // =================================================================

    'create' => function () use ($ideas): void {
        $user = bs_begin_write();
        $id   = $ideas->create(bs_read_idea_input(), $user);
        $raw  = $ideas->find($id);
        IdeaNotice::created($raw);
        bs_json_ok([
            'id'      => $id,
            'idea'    => $ideas->get($id),
            'message' => '아이디어를 올렸습니다.' . (!empty($raw['is_anonymous'])
                ? ' 익명으로 올렸습니다 — 관리자 말고는 작성자를 볼 수 없습니다.' : ''),
        ]);
    },

    'update' => function () use ($ideas): void {
        bs_begin_write();
        $id = bs_idea_id_param();
        $ideas->update($id, bs_read_idea_input(true));
        bs_json_ok(['idea' => $ideas->get($id), 'message' => '고쳤습니다.']);
    },

    'delete' => function () use ($ideas): void {
        $user = bs_begin_write();
        $id   = bs_idea_id_param();
        $ideas->softDelete($id, $user);
        bs_json_ok(['deleted' => $id, 'message' => '아이디어를 지웠습니다.']);
    },

    // =================================================================
    // 평가·피드백
    // =================================================================

    'rate' => function () use ($ideas): void {
        $user = bs_begin_write();
        $id   = bs_idea_id_param();
        $before  = $ideas->ratingSummary($id)['mine'];
        $opinion = trim(bs_param_str('opinion'));
        $ideas->rate($id, $user, bs_param_int('value', 0), bs_param_int('feasibility', 0), $opinion);
        // 의견이 새로 생겼거나 바뀌었을 때만 알린다. 점수만 고친 것은 알리지 않는다.
        if ($opinion !== '' && $opinion !== (string)($before['opinion'] ?? '')) {
            IdeaNotice::opinion($ideas->find($id), $user, $opinion);
        }
        bs_json_ok(['rating' => $ideas->ratingSummary($id),
                    'message' => $before ? '평가를 고쳤습니다.' : '평가했습니다.']);
    },

    'unrate' => function () use ($ideas): void {
        $user = bs_begin_write();
        $id   = bs_idea_id_param();
        $ideas->unrate($id, (string)$user['id']);
        bs_json_ok(['rating' => $ideas->ratingSummary($id), 'message' => '평가를 지웠습니다.']);
    },

    'comment' => function () use ($ideas): void {
        $user     = bs_begin_write();
        $id       = bs_idea_id_param();
        $parentId = bs_param_int('parent_id', 0) ?: null;
        $content  = bs_param_str('content');
        $cid      = $ideas->addComment($id, $user, $content, $parentId);

        $parentAuthor = null;
        if ($parentId) {
            $root = $ideas->findComment((int)($ideas->findComment($cid)['parent_id'] ?? 0));
            $parentAuthor = $root['user_id'] ?? null;
        }
        IdeaNotice::commented($ideas->find($id), $user, $content, $parentAuthor);
        bs_json_ok(['comments' => $ideas->comments($id), 'message' => '피드백을 남겼습니다.']);
    },

    'comment_update' => function () use ($ideas): void {
        $user = bs_begin_write();
        $cid  = bs_param_int('comment_id', 0);
        $ideas->updateComment($cid, $user, bs_param_str('content'));
        $c = $ideas->findComment($cid);
        bs_json_ok(['comments' => $ideas->comments((int)$c['idea_id']), 'message' => '고쳤습니다.']);
    },

    'comment_delete' => function () use ($ideas): void {
        $user = bs_begin_write();
        $cid  = bs_param_int('comment_id', 0);
        $c    = $ideas->findComment($cid);
        $ideas->deleteComment($cid, $user);
        bs_json_ok(['comments' => $ideas->comments((int)($c['idea_id'] ?? 0)), 'message' => '지웠습니다.']);
    },

    // =================================================================
    // 검토·묶기 (관리자)
    // =================================================================

    'review' => function () use ($ideas): void {
        $user   = bs_begin_write();
        $id     = bs_idea_id_param();
        $status = bs_param_str('status');
        $note   = bs_param_str('note');
        bs_require_cap_api(BS_CAP_IDEA_REVIEW);
        $ideas->review($id, $status, $note, $user);
        IdeaNotice::reviewed($ideas->find($id), $status, $note);
        bs_json_ok(['idea' => $ideas->get($id), 'comments' => $ideas->comments($id),
                    'message' => '「' . (BS_IDEA_STATUS[$status] ?? $status) . '」(으)로 바꿨습니다.']);
    },

    'merge' => function () use ($ideas): void {
        $user = bs_begin_write();
        $id   = bs_idea_id_param();
        bs_require_cap_api(BS_CAP_IDEA_REVIEW);
        $target = bs_param_int('target_id', 0) ?: null;
        $ideas->merge($id, $target, $user);
        bs_json_ok(['idea' => $ideas->get($id), 'comments' => $ideas->comments($id),
                    'message' => $target ? '비슷한 아이디어로 묶었습니다.' : '묶음을 풀었습니다.']);
    },

    // =================================================================
    // 올리기 — 과제·프로젝트로
    // =================================================================

    'promote_rnd' => function () use ($pdo, $ideas): void {
        $user  = bs_begin_write();
        $id    = bs_idea_id_param();
        $rndId = bs_idea_promoter($pdo, $ideas)->ideaToRnd($id, bs_read_promote_input(), $user);
        bs_json_ok([
            'project_id' => $rndId,
            'url'        => 'rnd_view.php?id=' . $rndId,
            'message'    => 'R&D 과제로 발의했습니다. 승인되면 보드에 올라갑니다.',
        ]);
    },

    'promote_project' => function () use ($pdo, $ideas): void {
        $user = bs_begin_write();
        $id   = bs_idea_id_param();
        $pid  = bs_idea_promoter($pdo, $ideas)->ideaToProject($id, bs_read_promote_input(), $user);
        bs_json_ok([
            'project_id' => $pid,
            'url'        => 'project_view.php?id=' . $pid,
            'message'    => '프로젝트로 등록했습니다(작성 중). 범위를 채우고 WBS 로 이어 가세요.',
        ]);
    },
]);


// =====================================================================
// 공통
// =====================================================================

function bs_idea_id_param(): int
{
    $id = bs_param_int('id', 0);
    if (!$id) {
        bs_json_error('MISSING_PARAM', '아이디어 번호가 없습니다.', 400);
    }
    return $id;
}

/**
 * 포털 사용자 번호(portal_users.id). 알림함이 이 번호로 묶인다.
 * 사용자로 보기 중이면 0 — 남의 눈으로 본 것을 그 사람이 읽은 것으로 치면 안 된다.
 */
function bs_idea_portal_uid(): int
{
    $me = bs_current_user();
    $pu = current_portal_user();
    if (!$me || !$pu || !empty($me['view_as'])) {
        return 0;
    }
    return (int)$pu['id'];
}

function bs_read_idea_input(bool $partial = false): array
{
    $out = [];
    foreach (['title', 'problem', 'proposal', 'effect', 'url', 'rnd_category'] as $k) {
        if (!$partial || bs_has_param($k)) {
            $out[$k] = bs_param_str($k);
        }
    }
    if (!$partial || bs_has_param('is_anonymous')) {
        $out['is_anonymous'] = in_array(bs_param_str('is_anonymous'), ['1', 'true'], true);
    }
    if (!$partial || bs_has_param('domain_ids')) {
        $out['domain_ids'] = array_map('intval', (array)bs_param_array('domain_ids'));
    }
    return $out;
}

/** 올릴 때 덧붙이는 값 — 비면 아이디어에서 가져온다(PromoteService). */
function bs_read_promote_input(): array
{
    $out = [];
    foreach (['name', 'summary', 'rnd_category', 'visibility', 'dev_start', 'dev_end',
              'load_cap', 'client', 'track', 'test_start', 'test_end', 'deploy_date'] as $k) {
        if (bs_has_param($k) && bs_param_str($k) !== '') {
            $out[$k] = bs_param_str($k);
        }
    }
    if (bs_has_param('domain_ids')) {
        $out['domain_ids'] = array_map('intval', (array)bs_param_array('domain_ids'));
    }
    if (bs_has_param('recruiting')) {
        $out['recruiting'] = in_array(bs_param_str('recruiting'), ['1', 'true'], true);
    }
    return $out;
}

function bs_idea_promoter(PDO $pdo, IdeaRepo $ideas): PromoteService
{
    return new PromoteService($pdo, $ideas, new RndRepo($pdo), new ProjectRepo($pdo));
}

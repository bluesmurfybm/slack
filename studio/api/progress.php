<?php
/**
 * 진행상황 API — 등록, 조회, 댓글 (명세서 §7.2).
 *
 * GET  api/progress.php?act=list&task_id=1      한 태스크의 기록 + 댓글
 * GET  api/progress.php?act=feed&project_id=1   프로젝트 피드
 * GET  api/progress.php?act=task&task_id=1      드로어가 여는 한 태스크
 * POST api/progress.php?act=create              진행상황 등록
 * POST api/progress.php?act=comment             댓글
 * POST api/progress.php?act=delete
 * POST api/progress.php?act=delete_comment
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 등록은 **본인이 맡은 태스크만** 이다 (명세서 §7.2).               │
 * │ 확인은 ba_progress_allowed() 한 곳에서만 한다 — 확정된 배정안에    │
 * │ 내 member_id 가 들어 있는지를 본다. 관리자도 남의 것을 대신        │
 * │ 올리지 않는다. 대신 올려 주면 기록의 주인이 흐려진다.             │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BA_ROOT . '/inc/repo/ProjectRepo.php';
require_once BA_ROOT . '/inc/repo/TaskRepo.php';
require_once BA_ROOT . '/inc/repo/MemberRepo.php';
require_once BA_ROOT . '/inc/repo/AllocationRepo.php';
require_once BA_ROOT . '/inc/repo/ProgressRepo.php';
require_once BA_ROOT . '/inc/service/Notifier.php';

$pdo      = ba_db();
$projects = new ProjectRepo($pdo);
$tasks    = new TaskRepo($pdo);
$members  = new MemberRepo($pdo);
$allocs   = new AllocationRepo($pdo);
$progress = new ProgressRepo($pdo);

ba_route(ba_param_str('act', 'list'), [

    'list' => function () use ($tasks, $progress): void {
        ba_require_login_api();
        $taskId = ba_param_int('task_id', 0);
        if (!$taskId) {
            ba_json_error('MISSING_PARAM', '태스크 번호가 없습니다.', 400);
        }
        if (!$tasks->find($taskId)) {
            ba_json_error('NOT_FOUND', '태스크를 찾을 수 없습니다.', 404);
        }

        $rows = $progress->byTask($taskId);
        // 댓글은 한 번에 받아 붙인다. 기록마다 물으면 N+1 이 된다.
        $cm   = $progress->commentsFor(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['comments'] = $cm[$r['id']] ?? [];
        }
        unset($r);

        ba_json_ok(['rows' => $rows]);
    },

    /** 드로어가 여는 한 태스크 — 기록·댓글·내가 올릴 수 있는지. */
    'task' => function () use ($tasks, $members, $allocs, $progress): void {
        $me     = ba_require_login_api();
        $taskId = ba_param_int('task_id', 0);
        if (!$taskId) {
            ba_json_error('MISSING_PARAM', '태스크 번호가 없습니다.', 400);
        }
        $task = $tasks->find($taskId);
        if (!$task) {
            ba_json_error('NOT_FOUND', '태스크를 찾을 수 없습니다.', 404);
        }

        $rows = $progress->byTask($taskId);
        $cm   = $progress->commentsFor(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['comments'] = $cm[$r['id']] ?? [];
        }
        unset($r);

        [$allowed, $why, $mine] = ba_progress_allowed($members, $allocs, $me, $task);

        ba_json_ok([
            'task' => [
                'id'           => (int)$task['id'],
                'project_id'   => (int)$task['project_id'],
                'wbs_no'       => $task['wbs_no'],
                'title'        => $task['title'],
                'description'  => $task['description'],
                'status'       => $task['status'],
                'status_label' => BA_TASK_STATUS[$task['status']] ?? $task['status'],
                'progress_pct' => (int)$task['progress_pct'],
                'plan_start'   => $task['plan_start'],
                'plan_end'     => $task['plan_end'],
                'est_md'       => $task['est_md'] !== null ? (float)$task['est_md'] : null,
            ],
            'rows'      => $rows,
            'can_write' => $allowed,
            'why'       => $why,
            'member_id' => $mine,
            'statuses'  => BA_TASK_STATUS,
        ]);
    },

    'feed' => function () use ($projects, $progress): void {
        ba_require_login_api();
        $projectId = ba_param_int('project_id', 0);
        if (!$projectId) {
            ba_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
        }
        if (!$projects->find($projectId)) {
            ba_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }
        ba_json_ok([
            'rows' => $progress->feedByProject($projectId, ba_param_int('limit', 30) ?? 30),
        ]);
    },

    /**
     * 진행상황 등록.
     *
     * ba_progress 기록과 ba_task.status/progress_pct 를 같은 트랜잭션에서
     * 바꾼다(ProgressRepo::create). 알림은 **그 뒤**에 따로 접수한다 —
     * 슬랙 사정으로 등록이 실패하면 안 된다.
     */
    'create' => function () use ($pdo, $projects, $tasks, $members, $allocs, $progress): void {
        $me     = ba_begin_write();
        $taskId = ba_param_int('task_id', 0);
        if (!$taskId) {
            ba_json_error('MISSING_PARAM', '태스크 번호가 없습니다.', 400);
        }
        $task = $tasks->find($taskId);
        if (!$task) {
            ba_json_error('NOT_FOUND', '태스크를 찾을 수 없습니다.', 404);
        }

        [$allowed, $why, $mine] = ba_progress_allowed($members, $allocs, $me, $task);
        if (!$allowed) {
            ba_json_error('FORBIDDEN', $why, 403);
        }

        $id = $progress->create($taskId, $mine, [
            'status'       => ba_has_param('status') ? ba_param_str('status') : null,
            'progress_pct' => ba_has_param('progress_pct') ? ba_param_int('progress_pct') : null,
            'content'      => ba_param_str('content'),
            'blocker'      => ba_param_str('blocker'),
            'worked_on'    => ba_param_str('worked_on'),
        ]);

        // 알림 — 여기서 실패해도 등록은 이미 끝났다.
        $row      = $progress->find($id);
        $notifier = new OutboxNotifier($pdo);
        $sent     = $notifier->send(
            ba_progress_notices($projects, $members, $allocs, $task, $row, $me)
        );

        $rows = $progress->byTask($taskId);
        $cm   = $progress->commentsFor(array_column($rows, 'id'));
        foreach ($rows as &$r) { $r['comments'] = $cm[$r['id']] ?? []; }
        unset($r);

        $after = $tasks->find($taskId);
        ba_json_ok([
            'id'   => $id,
            'rows' => $rows,
            'task' => [
                'id'           => $taskId,
                'status'       => $after['status'],
                'status_label' => BA_TASK_STATUS[$after['status']] ?? $after['status'],
                'progress_pct' => (int)$after['progress_pct'],
            ],
            'notify'  => $sent,
            'message' => '진행상황을 올렸습니다.'
                . ($row['blocker'] ? ' 블로커는 담당 PM 에게 따로 알립니다.' : ''),
            // 보냈다고 말하지 않는다. 아직 내보내는 경로가 없다.
            'notify_notice' => '알림은 적재만 된 상태입니다. 실제 발송 경로는 아직 없습니다.',
        ]);
    },

    'comment' => function () use ($progress): void {
        $me = ba_begin_write();
        $progressId = ba_param_int('progress_id', 0);
        if (!$progressId) {
            ba_json_error('MISSING_PARAM', '진행 기록 번호가 없습니다.', 400);
        }
        if (!$progress->find($progressId)) {
            ba_json_error('NOT_FOUND', '진행 기록을 찾을 수 없습니다.', 404);
        }

        // 댓글은 본인 태스크가 아니어도 단다. PM 과 동료가 묻고 답하는 자리다.
        $id = $progress->addComment($progressId, $me, ba_param_str('content'));

        ba_json_ok([
            'id'       => $id,
            'comments' => $progress->comments($progressId),
            'message'  => '댓글을 남겼습니다.',
        ]);
    },

    /**
     * 진행 기록 삭제.
     *
     * 본인이 올린 것이거나 관리자만. 기록은 남기는 쪽이 기본이라
     * 잘못 올린 것을 지우는 용도다.
     *
     * 태스크의 status/progress_pct 는 되돌리지 않는다 — 기록을 지운다고
     * 그 뒤에 일어난 일까지 없던 것이 되지는 않는다.
     */
    'delete' => function () use ($members, $progress): void {
        $me = ba_begin_write();
        $id = ba_param_int('id', 0);
        if (!$id) {
            ba_json_error('MISSING_PARAM', '진행 기록 번호가 없습니다.', 400);
        }
        $row = $progress->find($id);
        if (!$row) {
            ba_json_error('NOT_FOUND', '진행 기록을 찾을 수 없습니다.', 404);
        }

        $mine = $members->findByUserId((string)$me['id']);
        $isMe = $mine && (int)$mine['id'] === (int)$row['member_id'];
        if (!$isMe && !ba_is_admin()) {
            ba_json_error('FORBIDDEN', '본인이 올린 기록만 지울 수 있습니다.', 403);
        }

        $progress->delete($id);
        ba_json_ok([
            'message' => '진행 기록을 지웠습니다. 태스크의 상태와 진행률은 그대로입니다 '
                       . '— 되돌리려면 새 기록을 올려 주세요.',
            'rows'    => $progress->byTask((int)$row['task_id']),
        ]);
    },

    'delete_comment' => function () use ($progress): void {
        $me = ba_begin_write();
        $id = ba_param_int('id', 0);
        if (!$id) {
            ba_json_error('MISSING_PARAM', '댓글 번호가 없습니다.', 400);
        }
        $c = $progress->findComment($id);
        if (!$c) {
            ba_json_error('NOT_FOUND', '댓글을 찾을 수 없습니다.', 404);
        }
        if ((string)$c['user_id'] !== (string)$me['id'] && !ba_is_admin()) {
            ba_json_error('FORBIDDEN', '본인이 쓴 댓글만 지울 수 있습니다.', 403);
        }

        $progress->deleteComment($id);
        ba_json_ok([
            'comments' => $progress->comments((int)$c['progress_id']),
            'message'  => '댓글을 지웠습니다.',
        ]);
    },
]);


// =====================================================================
// 공통
// =====================================================================

/**
 * 이 사람이 이 태스크에 진행상황을 올릴 수 있는가.
 *
 * **확정된 배정안에 들어 있어야 한다.** 초안 배정으로 올리게 하면
 * 확정 전 배정이 사실처럼 굳는다.
 *
 * 관리자도 남의 것을 대신 올리지 않는다. 대신 올려 주면 "누가 한 일인가"
 * 가 흐려지고, 그 기록은 나중에 역량 판정의 근거가 되지 못한다.
 *
 * @return array{0:bool,1:string,2:?int} [가능한가, 안 되는 이유, 내 member_id]
 */
function ba_progress_allowed(MemberRepo $members, AllocationRepo $allocs,
                             array $me, array $task): array
{
    $mine = $members->findByUserId((string)$me['id']);
    if (!$mine) {
        return [false, '구성원 명단에 없어 진행상황을 올릴 수 없습니다. 관리자에게 문의하세요.', null];
    }
    $mid = (int)$mine['id'];

    $alloc = $allocs->confirmed((int)$task['project_id']);
    if ($alloc === null) {
        return [false, '확정된 배정안이 없습니다. 배정이 확정돼야 진행상황을 올릴 수 있습니다.', $mid];
    }

    foreach ($allocs->items((int)$alloc['id']) as $it) {
        if ((int)$it['task_id'] === (int)$task['id'] && (int)$it['member_id'] === $mid) {
            return [true, '', $mid];
        }
    }
    return [false, '본인이 배정받은 태스크만 올릴 수 있습니다.', $mid];
}

/**
 * 진행상황 알림 (명세서 §7.2).
 *
 *   · 채널 `#blueassign-알림` 에 한 건
 *   · 블로커가 있으면 담당 PM 에게 DM 한 건 더
 *
 * 보내지 않고 적재한다 — 내보내는 경로가 아직 없다
 * (sql/007_migration_notify_outbox.sql 의 설명 참고).
 *
 * @return Notice[]
 */
function ba_progress_notices(ProjectRepo $projects, MemberRepo $members,
                             AllocationRepo $allocs, array $task, array $row, array $me): array
{
    $p = $projects->find((int)$task['project_id']);
    if (!$p) {
        return [];
    }

    $head = sprintf('[%s] %s %s',
        $p['name'], $row['wbs_no'] ?: '', $row['task_title']);

    $lines = [$head, ''];
    $lines[] = $row['emp_name'] . ' 님이 진행상황을 올렸습니다.';
    if ($row['status_label'] !== null) {
        $lines[] = '상태: ' . $row['status_label'];
    }
    if ($row['progress_pct'] !== null) {
        $lines[] = '진행률: ' . $row['progress_pct'] . '%';
    }
    if ($row['content']) {
        $lines[] = '';
        $lines[] = $row['content'];
    }
    if ($row['blocker']) {
        $lines[] = '';
        $lines[] = '[블로커] ' . $row['blocker'];
    }
    $body = implode("\n", $lines);

    $out = [];
    // 1) 팀 채널
    $out[] = new Notice('slack', (int)$row['member_id'], BA_PROGRESS_CHANNEL,
                        $body, $head, 'progress', (int)$row['id']);

    // 2) 블로커면 담당 PM 에게 DM
    if ($row['blocker']) {
        $pm = $members->findByUserId((string)$p['owner_id']);
        $to = $pm['slack_handle'] ?? '';
        $out[] = new Notice('slack', $pm ? (int)$pm['id'] : null, (string)$to,
            "[블로커] " . $head . "\n\n"
            . $row['emp_name'] . ' 님이 막혀 있습니다.' . "\n\n" . $row['blocker'],
            '[블로커] ' . $head, 'progress_blocker', (int)$row['id']);
    }
    return $out;
}

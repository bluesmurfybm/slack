<?php
/**
 * 프로젝트 API — 목록·상세·생성·수정·삭제와 출처 문서 등록.
 *
 * GET  api/project.php?act=list
 * GET  api/project.php?act=get&id=1
 * POST api/project.php?act=create
 * POST api/project.php?act=update
 * POST api/project.php?act=delete          소프트 삭제
 * POST api/project.php?act=restore         삭제 취소
 * POST api/project.php?act=upload_source   파일(multipart) | 링크 | 직접입력
 * POST api/project.php?act=delete_source
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
require_once BS_ROOT . '/inc/service/SourceUploader.php';

$repo = new ProjectRepo(bs_db());

bs_route(bs_param_str('act', 'list'), [

    // =================================================================
    // 조회 — 로그인만 하면 볼 수 있다
    // =================================================================

    'list' => function () use ($repo): void {
        bs_require_login_api();

        // 지워진 것까지 보려면 관리자여야 한다.
        $withDeleted = bs_param_str('with_deleted') === '1' && bs_is_admin();

        $filter = [
            'status'       => bs_param_str('status'),
            'track'        => bs_param_str('track'),
            'owner_id'     => bs_param_str('owner_id'),
            'keyword'      => bs_param_str('keyword'),
            'from'         => bs_param_str('from'),
            'to'           => bs_param_str('to'),
            'sort'         => bs_param_str('sort', 'recent'),
            'page'         => bs_param_int('page', 1),
            'size'         => bs_param_int('size', 20),
            'with_deleted' => $withDeleted,
        ];
        // 내 프로젝트만 보기
        if (bs_param_str('mine') === '1') {
            $filter['owner_id'] = bs_current_user()['id'];
        }

        $result = $repo->search($filter);

        bs_json_ok([
            'rows'   => array_map('bs_present_project_row', $result['rows']),
            'total'  => $result['total'],
            'page'   => $result['page'],
            'size'   => $result['size'],
            'pages'  => $result['pages'],
            'counts' => $repo->statusCounts($filter),
        ]);
    },

    'get' => function () use ($repo): void {
        bs_require_login_api();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
        }

        $row = $repo->find($id, bs_is_admin());
        if (!$row) {
            bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }

        bs_json_ok([
            'project' => bs_present_project($row),
            'sources' => array_map('bs_present_source', $repo->sources($id)),
            'can'     => [
                'manage' => bs_can(BS_CAP_PROJECT_MANAGE, $id),
            ],
        ]);
    },

    // =================================================================
    // 변경 — PM/관리자만
    // =================================================================

    'create' => function () use ($repo): void {
        $user = bs_begin_write();
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE);

        $data = bs_read_project_input(true);
        if ($data['name'] === '') {
            bs_json_error('MISSING_PARAM', '프로젝트명을 입력하세요.', 400);
        }

        // 기간 역전은 ProjectRepo::assertPeriods 가 InvalidArgumentException 을
        // 던지고 _init.php 의 핸들러가 400 으로 바꾼다.
        $id = $repo->create($data, $user);
        $row = $repo->find($id);

        bs_json_ok([
            'id'      => $id,
            'project' => bs_present_project($row),
            'message' => '프로젝트를 등록했습니다.',
        ]);
    },

    'update' => function () use ($repo): void {
        $user = bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
        }
        if (!$repo->find($id)) {
            bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $id);

        // 보낸 칸만 바꾼다. 안 보낸 칸은 건드리지 않는다.
        $data = bs_read_project_input();
        if (array_key_exists('name', $data) && $data['name'] === '') {
            bs_json_error('MISSING_PARAM', '프로젝트명은 비울 수 없습니다.', 400);
        }

        $repo->update($id, $data);

        // 상태는 별도 칸으로 받는다. 넘어온 경우에만 바꾼다.
        $status = bs_param_str('status');
        if ($status !== '') {
            $repo->updateStatus($id, $status, $user);
        }

        bs_json_ok([
            'id'      => $id,
            'project' => bs_present_project($repo->find($id)),
            'message' => '프로젝트를 수정했습니다.',
        ]);
    },

    /**
     * 소프트 삭제.
     * 행을 지우지 않고 감춘다 — 태스크·배정·진행기록이 FK CASCADE 로 함께
     * 사라지면 안 되기 때문(003_migration_soft_delete.sql 주석 참고).
     */
    'delete' => function () use ($repo): void {
        $user = bs_begin_write();

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
        }
        $row = $repo->find($id);
        if (!$row) {
            bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $id);

        // 실수로 지우는 것을 막는다. BlueCart 가 영구 삭제에 요청번호를 받는 것과 같은 장치.
        if (bs_param_str('confirm') !== $row['code']) {
            bs_json_error(
                'CONFIRM_REQUIRED',
                '확인을 위해 프로젝트 코드(' . $row['code'] . ')를 그대로 입력하세요.',
                400
            );
        }

        $repo->softDelete($id, $user, bs_param_str('reason'));

        bs_json_ok(['message' => '프로젝트를 삭제했습니다. 관리자가 복구할 수 있습니다.']);
    },

    'restore' => function () use ($repo): void {
        bs_begin_write();
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE);   // 복구는 관리자만(프로젝트 특정 전이라 PM 경로 없음)

        $id = bs_param_int('id', 0);
        if (!$id) {
            bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
        }
        if (!$repo->find($id, true)) {
            bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }

        $repo->restore($id);
        bs_json_ok(['message' => '프로젝트를 복구했습니다.']);
    },

    // =================================================================
    // 출처 문서
    // =================================================================

    /**
     * 출처 등록. 세 종류를 한 엔드포인트가 받는다.
     *   source_type=file  multipart/form-data 의 files[]
     *   source_type=link  url + title
     *   source_type=text  text + title
     */
    'upload_source' => function () use ($repo): void {
        $user = bs_begin_write();

        $projectId = bs_param_int('project_id', 0);
        if (!$projectId) {
            bs_json_error('MISSING_PARAM', '프로젝트 번호가 없습니다.', 400);
        }
        if (!$repo->find($projectId)) {
            bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, $projectId);

        $uploader = new SourceUploader($repo);
        $type     = bs_param_str('source_type', 'file');

        switch ($type) {
            case 'file':
                if (empty($_FILES['files'])) {
                    bs_json_error('MISSING_PARAM', '선택된 파일이 없습니다.', 400);
                }
                $r = $uploader->storeMany($projectId, $_FILES['files'], $user);

                $msg = count($r['saved']) . '개를 등록했습니다.';
                if ($r['failed']) {
                    $msg .= ' ' . count($r['failed']) . '개는 실패했습니다.';
                }
                bs_json_ok([
                    'saved'   => $r['saved'],
                    'failed'  => $r['failed'],
                    'sources' => array_map('bs_present_source', $repo->sources($projectId)),
                    'message' => $msg,
                ]);
                // no break — bs_json_ok 이 exit 한다

            case 'link':
                $saved = $uploader->storeLink(
                    $projectId,
                    bs_param_str('url'),
                    bs_param_str('title'),
                    $user
                );
                bs_json_ok([
                    'saved'   => [$saved],
                    'failed'  => [],
                    'sources' => array_map('bs_present_source', $repo->sources($projectId)),
                    'message' => '링크를 등록했습니다.',
                ]);

            case 'text':
                $saved = $uploader->storeText(
                    $projectId,
                    bs_param_str('text'),
                    bs_param_str('title'),
                    $user
                );
                bs_json_ok([
                    'saved'   => [$saved],
                    'failed'  => [],
                    'sources' => array_map('bs_present_source', $repo->sources($projectId)),
                    'message' => '내용을 등록했습니다.',
                ]);

            default:
                bs_json_error('INVALID_ARGUMENT', '알 수 없는 출처 종류입니다: ' . $type, 400);
        }
    },

    'delete_source' => function () use ($repo): void {
        bs_begin_write();

        $sourceId = bs_param_int('source_id', 0);
        if (!$sourceId) {
            bs_json_error('MISSING_PARAM', '문서 번호가 없습니다.', 400);
        }

        $src = $repo->findSource($sourceId);
        if (!$src) {
            bs_json_error('NOT_FOUND', '출처 문서를 찾을 수 없습니다.', 404);
        }
        // 권한은 그 문서가 속한 프로젝트 기준으로 본다.
        bs_require_cap_api(BS_CAP_PROJECT_MANAGE, (int)$src['project_id']);

        $repo->deleteSource($sourceId);

        bs_json_ok([
            'sources' => array_map('bs_present_source', $repo->sources((int)$src['project_id'])),
            'message' => '출처 문서를 삭제했습니다.',
        ]);
    },
]);


// =====================================================================
// 입력 / 출력 변환
//
// BlueCart 는 이런 변환을 includes/presenter.php 에 따로 두지만, P1 은
// 프로젝트 하나뿐이라 여기 둔다. 태스크·배정이 붙으면 inc/presenter.php 로 뺀다.
// =====================================================================

/**
 * 폼에서 온 값을 ProjectRepo 가 쓰는 모양으로.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ **요청에 들어 있던 칸만** 돌려준다.                                │
 * │                                                                  │
 * │ 예전에는 열한 칸을 늘 돌려줬는데, 그러면 수정 API 가 안 보낸 칸까지 │
 * │ 빈 값으로 덮어쓴다. {id, name} 만 보내 이름을 고치면 고객·트랙·    │
 * │ 기간이 전부 NULL 이 되는 식이다. HTTP 시험에서 잡은 실제 사고다.   │
 * │                                                                  │
 * │ 화면은 늘 전 칸을 보내므로 눈에 띄지 않았다. 다른 호출자(배치,     │
 * │ 외부 연동)가 생기면 조용히 데이터를 잃는다.                        │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * @param bool $requireName true 면 name 이 없어도 키를 만들어 준다(등록용).
 */
function bs_read_project_input(bool $requireName = false): array
{
    $cols = ['name', 'summary', 'client', 'track',
             'dev_start', 'dev_end', 'test_start', 'test_end', 'deploy_date',
             'notes', 'extra'];

    $out = [];
    foreach ($cols as $c) {
        if (bs_has_param($c)) {
            $out[$c] = bs_param_str($c);
        }
    }

    // 등록은 name 이 필수다. 없으면 빈 값으로 넣어 두고 호출부가 막게 한다.
    if ($requireName && !array_key_exists('name', $out)) {
        $out['name'] = '';
    }
    return $out;
}

/** 상세용. */
function bs_present_project(array $r): array
{
    return [
        'id'           => (int)$r['id'],
        'code'         => $r['code'],
        'name'         => $r['name'],
        'summary'      => $r['summary'],
        'client'       => $r['client'],
        'track'        => $r['track'],
        'track_label'  => BS_PROJECT_TRACK[$r['track']] ?? $r['track'],
        'dev_start'    => bs_date($r['dev_start']),
        'dev_end'      => bs_date($r['dev_end']),
        'test_start'   => bs_date($r['test_start']),
        'test_end'     => bs_date($r['test_end']),
        'deploy_date'  => bs_date($r['deploy_date']),
        'notes'        => $r['notes']  ?? null,
        'extra'        => $r['extra']  ?? null,
        'status'       => $r['status'],
        'status_label' => BS_PROJECT_STATUS[$r['status']] ?? $r['status'],
        'owner_id'     => $r['owner_id'],
        'owner_name'   => $r['owner_name'],
        'is_deleted'   => !empty($r['deleted_at']),
        'deleted_at'   => bs_date($r['deleted_at'] ?? null, 'Y-m-d H:i'),
        'created_at'   => bs_date($r['created_at'], 'Y-m-d H:i'),
        'updated_at'   => bs_date($r['updated_at'], 'Y-m-d H:i'),
    ];
}

/** 목록용. 상세보다 가볍고 화면이 쓰는 파생값을 붙인다. */
function bs_present_project_row(array $r): array
{
    $me = bs_current_user()['id'] ?? '';
    return [
        'id'           => (int)$r['id'],
        'code'         => $r['code'],
        'name'         => $r['name'],
        'client'       => $r['client'],
        'track'        => $r['track'],
        'track_label'  => BS_PROJECT_TRACK[$r['track']] ?? $r['track'],
        'status'       => $r['status'],
        'status_label' => BS_PROJECT_STATUS[$r['status']] ?? $r['status'],
        'dev_start'    => bs_date($r['dev_start']),
        'dev_end'      => bs_date($r['dev_end']),
        'test_start'   => bs_date($r['test_start']),
        'test_end'     => bs_date($r['test_end']),
        'deploy_date'  => bs_date($r['deploy_date']),
        'owner_name'   => $r['owner_name'],
        'is_mine'      => $r['owner_id'] === $me,
        'is_deleted'   => !empty($r['deleted_at']),
        'source_count' => (int)($r['source_count'] ?? 0),
        'created_at'   => bs_date($r['created_at']),
    ];
}

/**
 * 출처 문서.
 * file_path 는 절대 내보내지 않는다 — 서버 디렉터리 구조를 알려 줄 이유가 없다.
 */
function bs_present_source(array $r): array
{
    return [
        'id'           => (int)$r['id'],
        'kind'         => $r['kind'],
        'kind_label'   => BS_SOURCE_KIND[$r['kind']] ?? $r['kind'],
        'title'        => $r['title'],
        'url'          => $r['url'],
        'file_size'    => isset($r['file_size']) ? (int)$r['file_size'] : null,
        'size_label'   => isset($r['file_size']) && $r['file_size'] !== null
                            ? SourceUploader::humanSize((int)$r['file_size']) : null,
        'mime'         => $r['mime'],
        'parse_status' => $r['parse_status'],
        'parse_error'  => $r['parse_error'],
        'parsed_len'   => isset($r['parsed_len']) ? (int)$r['parsed_len'] : null,
        'uploaded_by'  => $r['uploaded_by_name'] ?? null,
        'created_at'   => bs_date($r['created_at'], 'Y-m-d H:i'),
    ];
}

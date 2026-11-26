<?php

/**
 * 보관한 시트는 기본으로 뺀다 — 봇·스킬이 목록을 받을 때 끝난 할일이 후보로 섞이지 않게. 화면은 all=1 로 전부 받는다.
 * @param PDO $pdo
 * @param bool $withArchived
 * @return array[]
 */
function schooltodo_sheets_all($pdo, $withArchived = false) {
    $envs = schooltodo_school_envs($pdo);
    $cells = [];
    foreach ($pdo->query("SELECT sheet_id, school_id, `status` FROM schooltodo_cells") as $r) {
        $cells[(int)$r['sheet_id']][(int)$r['school_id']] = $r['status'];
    }

    $sheets = [];
    $where = $withArchived ? '' : "WHERE archived_at = ''";
    foreach ($pdo->query("SELECT * FROM schooltodo_sheets {$where} ORDER BY id") as $r) {
        $stages = schooltodo_stages($r);
        $rows = [];
        foreach ($envs as $schoolId => $env) {
            $rows[] = schooltodo_stage_row($stages, $env, $cells[(int)$r['id']][$schoolId] ?? SCHOOLTODO_STATUS_TODO);
        }
        $sheets[] = schooltodo_sheet_out($r, schooltodo_summary($stages, $rows));
    }
    return $sheets;
}

/**
 * @param array $row
 * @param array $summary 단계와 단계별 대학 수
 * @return array
 */
function schooltodo_sheet_out($row, $summary) {
    return [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'description' => (string)$row['description'],
        'created_by' => (string)$row['created_by'],
        'created_at' => (string)$row['created_at'],
        'archived_at' => (string)($row['archived_at'] ?? ''),
    ] + $summary;
}

/**
 * @param PDO $pdo
 * @param int $id
 * @return array
 */
function schooltodo_sheet_find($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM schooltodo_sheets WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new SchooltodoError('없는 시트입니다', 404);
    return $row;
}

/**
 * @param PDO $pdo
 * @param int $sheetId
 * @return array[]
 */
function schooltodo_sheet_rows($pdo, $sheetId) {
    $stages = schooltodo_stages(schooltodo_sheet_find($pdo, $sheetId));
    $access = schooltodo_school_access($pdo);
    $st = $pdo->prepare("SELECT s.id AS school_id, s.name, s.ver, s.dev, s.ops, c.status, c.note, c.updated_by, c.updated_at
                         FROM schools s
                         LEFT JOIN schooltodo_cells c ON c.school_id = s.id AND c.sheet_id = ?
                         WHERE s.active = 1
                         ORDER BY s.name, s.ver, s.id");
    $st->execute([$sheetId]);

    $rows = [];
    foreach ($st as $r) {
        $a = $access[(int)$r['school_id']] ?? ['vpn' => [], 'repo' => '', 'test_srv' => false];
        sort($a['vpn']);
        $rows[] = [
            'school_id' => (int)$r['school_id'],
            'name' => (string)$r['name'],
            'code' => schooltodo_school_code($a['repo'], (string)$r['dev']),
            'ver' => (string)$r['ver'],
            'vpn' => implode(', ', array_unique($a['vpn'])),
        ] + schooltodo_stage_row($stages, schooltodo_school_env($r, $a), $r['status'] ?? SCHOOLTODO_STATUS_TODO) + [
            'note' => (string)($r['note'] ?? ''),
            'updated_by' => (string)($r['updated_by'] ?? ''),
            'updated_at' => (string)($r['updated_at'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * 대학별 EnvHub 접속 정보. 한 대학에 접속 정보가 두 벌일 수 있어 모아서 준다.
 * @param PDO $pdo
 * @return array[] school_id => 접속 정보
 */
function schooltodo_school_access($pdo) {
    if (!schooltodo_has_table($pdo, 'school_access')) return [];
    $out = [];
    foreach ($pdo->query("SELECT school_id, vpn, repo, test_srv FROM school_access ORDER BY id") as $r) {
        $id = (int)$r['school_id'];
        $out[$id] ??= ['vpn' => [], 'repo' => '', 'test_srv' => false];
        if ((string)$r['vpn'] !== '') $out[$id]['vpn'][] = $r['vpn'];
        if ((string)$r['repo'] !== '') $out[$id]['repo'] .= $r['repo'] . "\n";
        $out[$id]['test_srv'] = $out[$id]['test_srv'] || (string)$r['test_srv'] !== '';
    }
    return $out;
}

/**
 * @param string $repo
 * @param string $dev
 * @return string
 */
function schooltodo_school_code($repo, $dev) {
    if (preg_match('~(?:svn://[^/\s]+|/(?:ub)?git)/([A-Za-z0-9_-]+?)(?:\.git)?(?![\w.-])~', $repo, $m)) return $m[1];
    if (preg_match('~^https?://([a-z0-9-]+)\.moodler\.kr~i', $dev, $m)) return $m[1];
    return '';
}

/**
 * @param PDO $pdo
 * @param string $table
 * @return bool
 */
function schooltodo_has_table($pdo, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        $cache[$table] = (int)$st->fetchColumn() > 0;
    }
    return $cache[$table];
}

/**
 * @param PDO $pdo
 * @param int $id
 * @param string|null $status
 * @param string|null $sort
 * @return array
 */
function schooltodo_sheet_detail($pdo, $id, $status, $sort = null) {
    $sheet = schooltodo_sheet_find($pdo, $id);
    $stages = schooltodo_stages($sheet);
    $rows = schooltodo_sheet_rows($pdo, $id);
    $summary = schooltodo_summary($stages, $rows);

    if ($status !== null) {
        schooltodo_want_stage($status, $stages);
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $status));
    }
    if ($sort !== null) $rows = schooltodo_rows_sort($rows, $sort, $stages);
    return schooltodo_sheet_out($sheet, $summary) + ['rows' => $rows];
}

/**
 * 대학 행을 정렬한다.
 * @param array[] $rows
 * @param string $sort
 * @param string[] $stages 시트의 단계
 * @return array[]
 */
function schooltodo_rows_sort($rows, $sort, $stages) {
    $desc = str_starts_with($sort, '-');
    $key = $desc ? substr($sort, 1) : $sort;
    if (!in_array($key, SCHOOLTODO_SORTS, true)) {
        throw new SchooltodoError('sort 는 ' . implode(', ', SCHOOLTODO_SORTS) . ' 중 하나여야 합니다(내림차순은 앞에 -)', 422);
    }
    $value = $key === 'status' ? fn($r) => array_search($r['status'], $stages, true) : fn($r) => $r[$key];
    usort($rows, fn($a, $b) => ($desc ? -1 : 1) * strnatcmp((string)$value($a), (string)$value($b))
        ?: strnatcmp($a['ver'], $b['ver'])
        ?: strnatcmp($a['name'], $b['name']));
    return $rows;
}

/**
 * @param array $ctx
 * @param array $body
 * @return array
 */
function schooltodo_sheet_create($ctx, $body) {
    $pdo = $ctx['pdo'];
    $title = schooltodo_want_title($pdo, $body['title'] ?? '', 0);
    $description = schooltodo_want_text($body['description'] ?? '', 'description', 2000);

    $stages = array_key_exists('stages', $body) ? schooltodo_want_stages($body['stages']) : SCHOOLTODO_STAGES_DEFAULT;

    $pdo->prepare("INSERT INTO schooltodo_sheets (`title`, `description`, `stages`, `created_by`, `created_at`) VALUES (?, ?, ?, ?, ?)")
        ->execute([$title, $description, json_encode($stages, JSON_UNESCAPED_UNICODE), $ctx['identity']['name'], date('Y-m-d H:i:s')]);

    return schooltodo_sheet_detail($pdo, (int)$pdo->lastInsertId(), null);
}

/**
 * @param array $ctx
 * @param int $id
 * @param array $body
 * @return array
 */
function schooltodo_sheet_update($ctx, $id, $body) {
    $pdo = $ctx['pdo'];
    $sheet = schooltodo_sheet_find($pdo, $id);

    $title = array_key_exists('title', $body) ? schooltodo_want_title($pdo, $body['title'], $id) : $sheet['title'];
    $description = array_key_exists('description', $body)
        ? schooltodo_want_text($body['description'], 'description', 2000)
        : $sheet['description'];

    // archived: true 면 보관(시각 기록), false 면 꺼내기. 이미 그 상태면 시각을 덮어쓰지 않는다.
    $archivedAt = (string)($sheet['archived_at'] ?? '');
    if (array_key_exists('archived', $body)) {
        if (!is_bool($body['archived'])) throw new SchooltodoError('archived 는 true/false 여야 합니다', 422);
        if ($body['archived'] && $archivedAt === '') $archivedAt = date('Y-m-d H:i:s');
        if (!$body['archived']) $archivedAt = '';
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE schooltodo_sheets SET `title` = ?, `description` = ?, `archived_at` = ? WHERE id = ?")
            ->execute([$title, $description, $archivedAt, $id]);
        if (array_key_exists('stages', $body)) schooltodo_stages_save($pdo, $sheet, $body['stages'], $body['renames'] ?? []);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return schooltodo_sheet_detail($pdo, $id, null);
}

/**
 * @param PDO $pdo
 * @param mixed $value
 * @param int $exceptId
 * @return string
 */
function schooltodo_want_title($pdo, $value, $exceptId) {
    $title = schooltodo_want_text($value, 'title', 200);
    if ($title === '') throw new SchooltodoError('title 을 입력해 주세요', 422);

    $st = $pdo->prepare("SELECT 1 FROM schooltodo_sheets WHERE title = ? AND id <> ?");
    $st->execute([$title, $exceptId]);
    if ($st->fetchColumn()) throw new SchooltodoError('같은 이름의 시트가 이미 있습니다', 409);

    return $title;
}

/**
 * @param array $ctx
 * @param int $id
 */
function schooltodo_sheet_delete($ctx, $id) {
    $pdo = $ctx['pdo'];
    schooltodo_sheet_find($pdo, $id);
    $pdo->prepare("DELETE FROM schooltodo_cell_history WHERE sheet_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM schooltodo_cells WHERE sheet_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM schooltodo_sheets WHERE id = ?")->execute([$id]);
}

/**
 * 한 시트의 대학별 상태·갱신 날짜를 버전별로 묶는다 — 외부 조회용(GET /sheets/{id}/versions).
 * 버전 순서는 숫자 순(2.9 → 3.2 → … → 4.5), 버전이 빈 학교는 맨 뒤. 버전마다 상태별 개수를 함께 준다.
 * @param PDO $pdo
 * @param int $id
 * 응답의 상태는 영문 코드(SCHOOLTODO_STATUS_CODES)로 주고, 한글 이름은 status_label 에 둔다.
 * @param string|null $status 이 상태인 학교만 (한글 상태)
 * @param string|null $ver    이 버전만
 * @return array
 */
function schooltodo_sheet_by_version($pdo, $id, $status, $ver) {
    $sheet = schooltodo_sheet_find($pdo, $id);
    $stages = schooltodo_stages($sheet);
    $rows = schooltodo_sheet_rows($pdo, $id);
    $summary = schooltodo_summary($stages, $rows);
    if ($status !== null) schooltodo_want_stage($status, $stages);

    $groups = [];
    foreach ($rows as $r) {
        if ($ver !== null && $r['ver'] !== $ver) continue;
        $groups[$r['ver']][] = $r;
    }
    uksort($groups, function ($a, $b) {
        $a = (string)$a; $b = (string)$b;
        if ($a === '' || $b === '') return ($a === '') <=> ($b === '');
        return version_compare($a, $b);
    });

    $versions = [];
    foreach ($groups as $v => $list) {
        $schools = [];
        foreach ($list as $r) {
            if ($status !== null && $r['status'] !== $status) continue;
            $schools[] = [
                'school_id' => $r['school_id'],
                'name' => $r['name'],
                'code' => $r['code'],
                'status' => SCHOOLTODO_STATUS_CODES[$r['status']] ?? $r['status'],
                'status_label' => $r['status'],
                'updated_at' => $r['updated_at'],
                'updated_by' => $r['updated_by'],
            ];
        }
        $versions[] = [
            'ver' => (string)$v,
            // 개수는 status 로 거르기 전 전체 기준 — 진행률을 볼 때 거른 결과에 휘둘리지 않게
            'total' => count($list),
            'counts' => schooltodo_counts_en(schooltodo_summary($stages, $list)['counts']),
            'schools' => $schools,
        ];
    }

    return [
        'id' => (int)$sheet['id'],
        'title' => (string)$sheet['title'],
        'description' => (string)$sheet['description'],
        'archived_at' => (string)($sheet['archived_at'] ?? ''),
        'total' => $summary['total'],
        'counts' => schooltodo_counts_en($summary['counts']),
        'versions' => $versions,
    ];
}

/**
 * 단계 이름 키 개수표를 영문 코드 키 개수표로 바꾼다.
 * @param int[] $counts
 * @return int[]
 */
function schooltodo_counts_en($counts) {
    $out = [];
    foreach ($counts as $ko => $n) $out[SCHOOLTODO_STATUS_CODES[$ko] ?? $ko] = $n;
    return $out;
}

/**
 * 영문 코드나 단계 이름을 단계 이름으로 바꾼다.
 * @param mixed $value
 * @return string
 */
function schooltodo_want_status_any($value) {
    if (!is_string($value)) throw new SchooltodoError('status 는 글자여야 합니다', 422);
    $ko = array_search($value, SCHOOLTODO_STATUS_CODES, true);
    return $ko === false ? $value : $ko;
}

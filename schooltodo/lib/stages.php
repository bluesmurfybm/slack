<?php

/**
 * 시트의 단계 목록.
 * @param array $sheet schooltodo_sheets 행
 * @return string[]
 */
function schooltodo_stages($sheet) {
    return schooltodo_stages_around(json_decode((string)($sheet['stages'] ?? ''), true) ?: SCHOOLTODO_STAGES_DEFAULT);
}

/**
 * 가운데 단계에 처음·끝 단계를 붙인다.
 * @param string[] $middle
 * @return string[]
 */
function schooltodo_stages_around($middle) {
    return array_merge([SCHOOLTODO_STATUS_TODO], $middle, [SCHOOLTODO_STATUS_EXCLUDED]);
}

/**
 * 대학에 개발·운영 서버가 있는지 본다.
 * @param array $school dev, ops 가 있는 schools 행
 * @param array $access 그 대학의 EnvHub 접속 정보
 * @return bool[] ['dev' => bool, 'ops' => bool]
 */
function schooltodo_school_env($school, $access) {
    $dev = (string)$school['dev'] !== '' || !empty($access['test_srv']);
    $ops = (string)$school['ops'] !== '';
    // 둘 다 없으면 서버 정보를 아직 적지 않은 대학이라 막지 않는다
    return $dev || $ops ? ['dev' => $dev, 'ops' => $ops] : ['dev' => true, 'ops' => true];
}

/**
 * 사용하는 대학마다 개발·운영 서버가 있는지 본다.
 * @param PDO $pdo
 * @return array[] school_id => ['dev' => bool, 'ops' => bool]
 */
function schooltodo_school_envs($pdo) {
    $access = schooltodo_school_access($pdo);
    $envs = [];
    foreach ($pdo->query("SELECT id, dev, ops FROM schools WHERE active = 1") as $r) {
        $envs[(int)$r['id']] = schooltodo_school_env($r, $access[(int)$r['id']] ?? []);
    }
    return $envs;
}

/**
 * 대학이 고를 수 있는 단계.
 * @param string[] $stages 시트의 단계
 * @param bool[] $env 그 대학에 개발·운영 서버가 있는지
 * @return string[]
 */
function schooltodo_school_stages($stages, $env) {
    return array_values(array_filter($stages, fn($s) => ($s !== SCHOOLTODO_STATUS_DEV || $env['dev'])
        && ($s !== SCHOOLTODO_STATUS_OPS || $env['ops'])));
}

/**
 * 대학 행의 상태, 고를 수 있는 단계, 완료 여부.
 * @param string[] $stages 시트의 단계
 * @param bool[] $env 그 대학에 개발·운영 서버가 있는지
 * @param string $status
 * @return array ['status' => string, 'stages' => string[], 'done' => bool]
 */
function schooltodo_stage_row($stages, $env, $status) {
    $allowed = schooltodo_school_stages($stages, $env);
    $at = array_search($status, $stages, true);
    $last = array_search($allowed[count($allowed) - 2], $stages, true);
    return [
        'status' => $status,
        'stages' => $allowed,
        'done' => $status !== SCHOOLTODO_STATUS_EXCLUDED && $at !== false && $at >= $last,
    ];
}

/**
 * 시트의 단계와 단계별 대학 수, 완료·대상 수.
 * @param string[] $stages
 * @param array[] $rows status, done 이 있는 대학 행
 * @return array
 */
function schooltodo_summary($stages, $rows) {
    $counts = array_fill_keys($stages, 0);
    foreach ($rows as $r) {
        if (isset($counts[$r['status']])) $counts[$r['status']]++;
    }
    return [
        'stages' => $stages,
        'total' => count($rows),
        'counts' => $counts,
        'done' => count(array_filter($rows, fn($r) => $r['done'])),
        'target' => count($rows) - $counts[SCHOOLTODO_STATUS_EXCLUDED],
    ];
}

/**
 * @param mixed $value 미조치·해당없음을 뺀 단계 이름 목록
 * @return string[]
 */
function schooltodo_want_stages($value) {
    if (!is_array($value) || !array_is_list($value)) throw new SchooltodoError('stages 는 단계 이름 목록이어야 합니다', 422);
    $names = array_map(fn($v) => schooltodo_want_text($v, 'stages', 20), $value);
    foreach ($names as $name) {
        if ($name === '') throw new SchooltodoError('단계 이름을 입력해 주세요', 422);
        if ($name === SCHOOLTODO_STATUS_TODO || $name === SCHOOLTODO_STATUS_EXCLUDED) {
            throw new SchooltodoError("{$name} 은(는) 처음·끝에 늘 있는 단계라 stages 에 넣지 않습니다", 422);
        }
    }
    if (count(array_unique($names)) !== count($names)) throw new SchooltodoError('단계 이름이 겹칩니다', 422);
    if (array_values(array_intersect($names, SCHOOLTODO_STAGES_DEFAULT)) !== SCHOOLTODO_STAGES_DEFAULT) {
        throw new SchooltodoError(implode('·', SCHOOLTODO_STAGES_DEFAULT) . ' 은(는) 빼거나 순서를 바꿀 수 없습니다', 422);
    }
    return $names;
}

/**
 * 시트의 단계를 바꾼다.
 * @param PDO $pdo
 * @param array $sheet schooltodo_sheets 행
 * @param mixed $stages
 * @param mixed $renames 이전 이름 => 새 이름
 */
function schooltodo_stages_save($pdo, $sheet, $stages, $renames) {
    $id = (int)$sheet['id'];
    $middle = schooltodo_want_stages($stages);
    if (!is_array($renames)) throw new SchooltodoError('renames 는 {이전 이름: 새 이름} 이어야 합니다', 422);

    $old = schooltodo_stages($sheet);
    foreach ($renames as $from => $to) {
        if (!in_array($from, $old, true) || in_array($from, SCHOOLTODO_STATUSES, true)) {
            throw new SchooltodoError("{$from} 은(는) 이름을 바꿀 수 있는 단계가 아닙니다", 422);
        }
        if (!in_array($to, $middle, true)) throw new SchooltodoError("{$to} 이(가) stages 에 없습니다", 422);
        foreach (['schooltodo_cells', 'schooltodo_cell_history'] as $table) {
            $pdo->prepare("UPDATE `{$table}` SET `status` = ? WHERE sheet_id = ? AND `status` = ?")->execute([$to, $id, $from]);
        }
    }

    $st = $pdo->prepare("SELECT DISTINCT `status` FROM schooltodo_cells WHERE sheet_id = ?");
    $st->execute([$id]);
    $gone = array_diff($st->fetchAll(PDO::FETCH_COLUMN), schooltodo_stages_around($middle));
    if ($gone) throw new SchooltodoError(reset($gone) . ' 단계에 대학이 있어 지울 수 없습니다', 409);

    $pdo->prepare("UPDATE schooltodo_sheets SET `stages` = ? WHERE id = ?")
        ->execute([json_encode($middle, JSON_UNESCAPED_UNICODE), $id]);
}

/**
 * @param mixed $value
 * @param string[] $allowed 그 대학이 고를 수 있는 단계
 * @return string
 */
function schooltodo_want_stage($value, $allowed) {
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new SchooltodoError('status 는 이 대학에서 ' . implode(', ', $allowed) . ' 중 하나여야 합니다', 422);
    }
    return $value;
}

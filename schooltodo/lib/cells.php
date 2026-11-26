<?php

/**
 * @param array $ctx
 * @param int $sheetId
 * @param array $body
 * @return array
 */
function schooltodo_cells_fill($ctx, $sheetId, $body) {
    $pdo = $ctx['pdo'];
    $sheet = schooltodo_sheet_find($pdo, $sheetId);
    $stages = schooltodo_stages($sheet);
    $envs = schooltodo_school_envs($pdo);

    $items = $body['items'] ?? null;
    if (!is_array($items) || !$items) throw new SchooltodoError('items 를 한 건 이상 보내 주세요', 422);

    $by = $ctx['by'] ?? $ctx['identity']['name'];
    $now = date('Y-m-d H:i:s');
    $schoolIds = [];

    $pdo->beginTransaction();
    try {
        foreach (array_values($items) as $i => $item) {
            try {
                if (!is_array($item)) throw new SchooltodoError('객체여야 합니다', 422);
                $schoolId = schooltodo_school_resolve($pdo, $item);
                schooltodo_cell_save($pdo, $sheetId, $schoolId, $item, $by, $now, schooltodo_school_stages($stages, $envs[$schoolId]));
            } catch (SchooltodoError $e) {
                throw new SchooltodoError("items[{$i}]: " . $e->getMessage(), $e->status());
            }
            $schoolIds[] = $schoolId;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $all = schooltodo_sheet_rows($pdo, $sheetId);
    $rows = array_values(array_filter($all, fn($r) => in_array($r['school_id'], $schoolIds, true)));
    return ['updated' => count($rows), 'rows' => $rows, 'sheet' => schooltodo_sheet_out($sheet, schooltodo_summary($stages, $all))];
}

/**
 * @param PDO $pdo
 * @param array $item
 * @return int
 */
function schooltodo_school_resolve($pdo, $item) {
    if (isset($item['school_id'])) {
        $st = $pdo->prepare("SELECT id FROM schools WHERE id = ? AND active = 1");
        $st->execute([(int)$item['school_id']]);
        $id = $st->fetchColumn();
        if ($id === false) throw new SchooltodoError("없는 대학입니다 (school_id {$item['school_id']})", 404);
        return (int)$id;
    }

    $name = schooltodo_want_text($item['school'] ?? '', 'school', 200);
    if ($name === '') throw new SchooltodoError('school_id 나 school 로 대학을 지정해 주세요', 422);

    $sql = "SELECT id, ver FROM schools WHERE name = ? AND active = 1";
    $args = [$name];
    if (isset($item['ver'])) {
        $sql .= " AND ver = ?";
        $args[] = schooltodo_want_text($item['ver'], 'ver', 20);
    }
    $st = $pdo->prepare($sql . " ORDER BY id");
    $st->execute($args);
    $found = $st->fetchAll();

    if (!$found) throw new SchooltodoError("없는 대학입니다 ({$name})", 404);
    if (count($found) > 1) {
        $vers = implode(', ', array_column($found, 'ver'));
        throw new SchooltodoError("{$name} 이(가) 여러 개입니다. ver 를 지정해 주세요 ({$vers})", 409);
    }
    return (int)$found[0]['id'];
}

/**
 * @param PDO $pdo
 * @param int $sheetId
 * @param int $schoolId
 * @param array $item
 * @param string $by
 * @param string $now
 * @param string[] $allowed 그 대학이 고를 수 있는 단계
 */
function schooltodo_cell_save($pdo, $sheetId, $schoolId, $item, $by, $now, $allowed) {
    $hasStatus = array_key_exists('status', $item);
    $hasNote = array_key_exists('note', $item);
    if (!$hasStatus && !$hasNote) throw new SchooltodoError('status 나 note 중 하나는 있어야 합니다', 422);

    $st = $pdo->prepare("SELECT id, status, note, updated_by, updated_at FROM schooltodo_cells WHERE sheet_id = ? AND school_id = ?");
    $st->execute([$sheetId, $schoolId]);
    $cell = $st->fetch();

    $status = $hasStatus ? schooltodo_want_stage($item['status'], $allowed) : ($cell['status'] ?? SCHOOLTODO_STATUS_TODO);
    $note = $hasNote ? schooltodo_want_text($item['note'], 'note', 2000) : ($cell['note'] ?? '');

    if ($cell) {
        // 같은 값을 다시 보내면(일괄 변경 등) 이력이 중복으로 차서 앞선 이력이 밀려난다
        if ($cell['status'] === $status && $cell['note'] === $note) return;

        schooltodo_history_push($pdo, $sheetId, $schoolId, $cell);
        $pdo->prepare("UPDATE schooltodo_cells SET `status` = ?, `note` = ?, `updated_by` = ?, `updated_at` = ? WHERE id = ?")
            ->execute([$status, $note, $by, $now, $cell['id']]);
        return;
    }
    $pdo->prepare("INSERT INTO schooltodo_cells (`sheet_id`, `school_id`, `status`, `note`, `updated_by`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$sheetId, $schoolId, $status, $note, $by, $now]);
}

/**
 * @param PDO $pdo
 * @param int $sheetId
 * @param int $schoolId
 * @param array $cell
 */
function schooltodo_history_push($pdo, $sheetId, $schoolId, $cell) {
    $pdo->prepare("INSERT INTO schooltodo_cell_history (`sheet_id`, `school_id`, `status`, `note`, `updated_by`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$sheetId, $schoolId, $cell['status'], $cell['note'], $cell['updated_by'], $cell['updated_at']]);

    $st = $pdo->prepare("SELECT id FROM schooltodo_cell_history WHERE sheet_id = ? AND school_id = ?
                         ORDER BY id DESC LIMIT 1 OFFSET " . SCHOOLTODO_HISTORY_MAX);
    $st->execute([$sheetId, $schoolId]);
    $cut = $st->fetchColumn();
    if ($cut === false) return;

    $pdo->prepare("DELETE FROM schooltodo_cell_history WHERE sheet_id = ? AND school_id = ? AND id <= ?")
        ->execute([$sheetId, $schoolId, $cut]);
}

/**
 * @param array $ctx
 * @param int $sheetId
 * @param int $schoolId
 * @return array
 */
function schooltodo_cell_history($ctx, $sheetId, $schoolId) {
    $pdo = $ctx['pdo'];
    schooltodo_sheet_find($pdo, $sheetId);
    $schoolId = schooltodo_school_resolve($pdo, ['school_id' => $schoolId]);

    $st = $pdo->prepare("SELECT status, note, updated_by, updated_at FROM schooltodo_cell_history
                         WHERE sheet_id = ? AND school_id = ? ORDER BY id DESC");
    $st->execute([$sheetId, $schoolId]);

    return ['school_id' => $schoolId, 'history' => $st->fetchAll()];
}

/**
 * 도구용 대학별 상태 변경.
 * @param array $ctx
 * @param int $sheetId
 * @param array $body
 * @return array
 */
function schooltodo_status_change($ctx, $sheetId, $body) {
    $items = $body['items'] ?? null;
    if (!is_array($items) || !$items) throw new SchooltodoError('items 를 한 건 이상 보내 주세요', 422);

    $fill = [];
    foreach (array_values($items) as $i => $item) {
        try {
            if (!is_array($item)) throw new SchooltodoError('객체여야 합니다', 422);
            if (!array_key_exists('status', $item)) throw new SchooltodoError('status 가 필요합니다', 422);
            $item['status'] = schooltodo_want_status_any($item['status']);
        } catch (SchooltodoError $e) {
            throw new SchooltodoError("items[{$i}]: " . $e->getMessage(), $e->status());
        }
        $fill[] = $item;
    }

    $res = schooltodo_cells_fill(['by' => $ctx['identity']['name']] + $ctx, $sheetId, ['items' => $fill]);

    return [
        'updated' => $res['updated'],
        'schools' => array_map(fn($r) => [
            'school_id' => $r['school_id'],
            'name' => $r['name'],
            'ver' => $r['ver'],
            'code' => $r['code'],
            'status' => SCHOOLTODO_STATUS_CODES[$r['status']] ?? $r['status'],
            'status_label' => $r['status'],
            'note' => $r['note'],
            'updated_at' => $r['updated_at'],
            'updated_by' => $r['updated_by'],
        ], $res['rows']),
    ];
}

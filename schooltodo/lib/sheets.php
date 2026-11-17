<?php

/**
 * @param PDO $pdo
 * @return array[]
 */
function schooltodo_sheets_all($pdo) {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM schools WHERE active = 1")->fetchColumn();

    $counted = [];
    $sql = "SELECT c.sheet_id, c.status, COUNT(*) AS n
            FROM schooltodo_cells c
            JOIN schools s ON s.id = c.school_id AND s.active = 1
            GROUP BY c.sheet_id, c.status";
    foreach ($pdo->query($sql) as $r) {
        $counted[(int)$r['sheet_id']][$r['status']] = (int)$r['n'];
    }

    $sheets = [];
    foreach ($pdo->query("SELECT * FROM schooltodo_sheets ORDER BY id") as $r) {
        $sheets[] = schooltodo_sheet_out($r, schooltodo_counts($counted[(int)$r['id']] ?? [], $total));
    }
    return $sheets;
}

/**
 * @param int[] $counted
 * @param int $total
 * @return int[]
 */
function schooltodo_counts($counted, $total) {
    $counts = array_fill_keys(SCHOOLTODO_STATUSES, 0);
    foreach ($counted as $status => $n) {
        if (isset($counts[$status])) $counts[$status] = $n;
    }
    $counts[SCHOOLTODO_STATUS_TODO] = 0;
    $counts[SCHOOLTODO_STATUS_TODO] = $total - array_sum($counts);
    return $counts;
}

/**
 * @param array $row
 * @param int[] $counts
 * @return array
 */
function schooltodo_sheet_out($row, $counts) {
    return [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'description' => (string)$row['description'],
        'created_by' => (string)$row['created_by'],
        'created_at' => (string)$row['created_at'],
        'total' => array_sum($counts),
        'counts' => $counts,
    ];
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
    // VPN 은 EnvHub(access)의 school_access 에서 가져온다. 한 대학에 접속정보가 두 벌인 경우가 있어
    // (access/db.php 참고) 값을 모아 하나로 만든다. access 를 아직 설치하지 않은 곳에서는 빈 값.
    $vpnJoin = schooltodo_has_table($pdo, 'school_access')
        ? "LEFT JOIN (SELECT school_id, GROUP_CONCAT(DISTINCT vpn ORDER BY vpn SEPARATOR ', ') AS vpn
                      FROM school_access WHERE vpn <> '' GROUP BY school_id) a ON a.school_id = s.id"
        : "LEFT JOIN (SELECT NULL AS school_id, NULL AS vpn) a ON 0";
    $st = $pdo->prepare("SELECT s.id AS school_id, s.name, s.ver, a.vpn, c.status, c.note, c.updated_by, c.updated_at
                         FROM schools s
                         LEFT JOIN schooltodo_cells c ON c.school_id = s.id AND c.sheet_id = ?
                         {$vpnJoin}
                         WHERE s.active = 1
                         ORDER BY s.name, s.ver, s.id");
    $st->execute([$sheetId]);

    $rows = [];
    foreach ($st as $r) {
        $rows[] = [
            'school_id' => (int)$r['school_id'],
            'name' => (string)$r['name'],
            'ver' => (string)$r['ver'],
            'vpn' => (string)($r['vpn'] ?? ''),
            'status' => $r['status'] ?? SCHOOLTODO_STATUS_TODO,
            'note' => (string)($r['note'] ?? ''),
            'updated_by' => (string)($r['updated_by'] ?? ''),
            'updated_at' => (string)($r['updated_at'] ?? ''),
        ];
    }
    return $rows;
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
 * @return array
 */
function schooltodo_sheet_detail($pdo, $id, $status) {
    $sheet = schooltodo_sheet_find($pdo, $id);
    $rows = schooltodo_sheet_rows($pdo, $id);
    $counts = schooltodo_counts(array_count_values(array_column($rows, 'status')), count($rows));

    if ($status !== null) {
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $status));
    }
    return schooltodo_sheet_out($sheet, $counts) + ['rows' => $rows];
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

    $pdo->prepare("INSERT INTO schooltodo_sheets (`title`, `description`, `created_by`, `created_at`) VALUES (?, ?, ?, ?)")
        ->execute([$title, $description, $ctx['identity']['name'], date('Y-m-d H:i:s')]);

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

    $pdo->prepare("UPDATE schooltodo_sheets SET `title` = ?, `description` = ? WHERE id = ?")
        ->execute([$title, $description, $id]);

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
    if ($ctx['identity']['bot']) throw new SchooltodoError('시트 삭제는 화면에서만 할 수 있습니다', 403);

    $pdo = $ctx['pdo'];
    schooltodo_sheet_find($pdo, $id);
    $pdo->prepare("DELETE FROM schooltodo_cells WHERE sheet_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM schooltodo_sheets WHERE id = ?")->execute([$id]);
}

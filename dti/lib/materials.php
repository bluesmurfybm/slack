<?php
/**
 * 발표자료·스캔 원본. 아티클 하나가 칸(slot)별로 여러 건을 가진다.
 *
 * 발표는 아티클과 1:1(`uq_topic`)이라 발표자료도 topic_id 로 건다 — 조회가 한 번으로 끝난다.
 * dti_topics·dti_presentations 의 material_*·scan_* 컬럼은 이 테이블이 생기면서 죽었다.
 * 화면 계약을 지키려고 첫 자료에서 파생해 응답에만 채운다(dti_topic_present).
 */

const DTI_MATERIAL_DEFAULTS = [
    'id' => null,
    'topic_id' => 0,
    'slot' => 'material',
    'kind' => 'file',
    'name' => '',
    'url' => null,
    'path' => null,
    'created_by' => '',
    'created_at' => '',
];

function dti_material_columns(): array {
    return array_keys(DTI_MATERIAL_DEFAULTS);
}

function dti_material_from_row(array $row): array {
    $material = DTI_MATERIAL_DEFAULTS;
    foreach (dti_material_columns() as $column) {
        if (!array_key_exists($column, $row)) continue;
        $value = $row[$column];

        $material[$column] = match (true) {
            in_array($column, ['id', 'topic_id'], true) => (int)$value,
            in_array($column, ['url', 'path'], true) => $value === null ? null : (string)$value,
            default => (string)$value,
        };
    }
    return $material;
}

function dti_material_list(PDO $pdo, int $topicId, string $slot): array {
    $stmt = $pdo->prepare("SELECT * FROM dti_materials WHERE topic_id = ? AND slot = ? ORDER BY id");
    $stmt->execute([$topicId, $slot]);
    return array_map('dti_material_from_row', $stmt->fetchAll());
}

/** 목록 화면용 — 아티클마다 따로 묻지 않고 한 번에 읽어 topic_id·slot 으로 묶는다 */
function dti_material_grouped(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT * FROM dti_materials ORDER BY id")->fetchAll() as $row) {
        $material = dti_material_from_row($row);
        $out[$material['topic_id']][$material['slot']][] = $material;
    }
    return $out;
}

function dti_material_find(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM dti_materials WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? dti_material_from_row($row) : null;
}

function dti_material_find_or_fail(PDO $pdo, int $topicId, string $slot, int $id): array {
    $material = dti_material_find($pdo, $id);
    if ($material === null || $material['topic_id'] !== $topicId || $material['slot'] !== $slot) {
        throw new DtiError('없는 자료입니다', 404);
    }
    return $material;
}

function dti_material_insert(PDO $pdo, array &$material): int {
    $columns = array_values(array_diff(dti_material_columns(), ['id']));
    $sql = 'INSERT INTO dti_materials (`' . implode('`, `', $columns) . '`) VALUES ('
         . implode(', ', array_fill(0, count($columns), '?')) . ')';

    $pdo->prepare($sql)->execute(array_map(static fn ($c) => $material[$c], $columns));

    return $material['id'] = (int)$pdo->lastInsertId();
}

function dti_material_create(PDO $pdo, int $topicId, string $slot, array $values): array {
    $material = DTI_MATERIAL_DEFAULTS;
    $material['topic_id'] = $topicId;
    $material['slot'] = $slot;
    $material['created_at'] = date('Y-m-d H:i:s');
    foreach ($values as $field => $value) {
        $material[$field] = $value;
    }
    dti_material_insert($pdo, $material);
    return $material;
}

/** 행과 올라온 파일을 같이 지운다 */
function dti_material_remove(PDO $pdo, string $uploadDir, array $material): void {
    dti_remove_upload($uploadDir, $material['path']);
    $pdo->prepare("DELETE FROM dti_materials WHERE id = ?")->execute([$material['id']]);
}

/** $slot 이 null 이면 그 아티클의 자료를 전부 지운다 */
function dti_material_remove_all(PDO $pdo, string $uploadDir, int $topicId, ?string $slot = null): void {
    $sql = "SELECT * FROM dti_materials WHERE topic_id = ?" . ($slot === null ? '' : ' AND slot = ?');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($slot === null ? [$topicId] : [$topicId, $slot]);

    foreach ($stmt->fetchAll() as $row) {
        dti_material_remove($pdo, $uploadDir, dti_material_from_row($row));
    }
}

/** 응답에 싣는 모양 — 저장 컬럼 그대로 두되 내부용 필드는 뺀다 */
function dti_material_out(array $material): array {
    return [
        'id' => $material['id'],
        'kind' => $material['kind'],
        'name' => $material['name'],
        'url' => $material['url'],
        'path' => $material['path'],
        'created_at' => $material['created_at'],
    ];
}

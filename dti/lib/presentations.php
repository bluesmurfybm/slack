<?php
/** 발표. 아티클당 하나이며, 예약·발표일·발표자료를 들고 있다. */

const DTI_PRESENTATION_DEFAULTS = [
    'id' => null,
    'topic_id' => 0,
    'presenter' => '',
    'presenter_email' => '',
    'planned_date' => '',
    'done_date' => '',
    'material_kind' => null,
    'material_name' => null,
    'material_url' => null,
    'material_path' => null,
    'created_at' => '',
];

const DTI_PRESENTATION_NULLABLE = ['material_kind', 'material_name', 'material_url', 'material_path'];

function dti_presentation_columns(): array {
    return array_keys(DTI_PRESENTATION_DEFAULTS);
}

function dti_presentation_from_row(array $row): array {
    $pres = DTI_PRESENTATION_DEFAULTS;
    foreach (dti_presentation_columns() as $column) {
        if (!array_key_exists($column, $row)) continue;
        $value = $row[$column];

        $pres[$column] = match (true) {
            in_array($column, ['id', 'topic_id'], true) => (int)$value,
            in_array($column, DTI_PRESENTATION_NULLABLE, true) => $value === null ? null : (string)$value,
            default => (string)$value,
        };
    }
    return $pres;
}

function dti_presentation_find(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM dti_presentations WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? dti_presentation_from_row($row) : null;
}

function dti_presentation_of_topic(PDO $pdo, int $topicId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM dti_presentations WHERE topic_id = ?");
    $stmt->execute([$topicId]);
    $row = $stmt->fetch();
    return $row ? dti_presentation_from_row($row) : null;
}

function dti_presentation_all_with_topics(PDO $pdo): array {
    $sql = "SELECT p.* FROM dti_presentations p JOIN dti_topics t ON t.id = p.topic_id";
    return array_map('dti_presentation_from_row', $pdo->query($sql)->fetchAll());
}

function dti_presentation_insert(PDO $pdo, array &$pres): int {
    $columns = array_values(array_diff(dti_presentation_columns(), ['id']));
    $sql = 'INSERT INTO dti_presentations (`' . implode('`, `', $columns) . '`) VALUES ('
         . implode(', ', array_fill(0, count($columns), '?')) . ')';

    $pdo->prepare($sql)->execute(array_map(static fn ($c) => $pres[$c], $columns));

    return $pres['id'] = (int)$pdo->lastInsertId();
}

function dti_presentation_update(PDO $pdo, array $pres): void {
    $columns = array_values(array_diff(dti_presentation_columns(), ['id']));
    $sql = 'UPDATE dti_presentations SET ' . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $columns))
         . ' WHERE id = ?';

    $values = array_map(static fn ($c) => $pres[$c], $columns);
    $values[] = $pres['id'];
    $pdo->prepare($sql)->execute($values);
}

function dti_presentation_delete(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM dti_presentations WHERE id = ?")->execute([$id]);
}

/**
 * 아무도 잡지 않은 발표 행을 조건부 UPDATE 로 차지한다. 동시 선점은 여기서 갈린다.
 * 잡았으면 true, 이미 임자가 있거나 행이 없으면 false.
 */
function dti_presentation_claim(PDO $pdo, int $topicId, string $email, string $name,
                                ?string $plannedDate): bool {
    $sql = "UPDATE dti_presentations SET presenter_email = ?, presenter = ?"
         . ($plannedDate === null ? '' : ', planned_date = ?')
         . " WHERE topic_id = ? AND presenter_email = '' AND done_date = ''";

    $values = [$email, $name];
    if ($plannedDate !== null) $values[] = $plannedDate;
    $values[] = $topicId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    return $stmt->rowCount() > 0;
}

function dti_presentation_create(PDO $pdo, int $topicId, array $values = []): array {
    $pres = DTI_PRESENTATION_DEFAULTS;
    $pres['topic_id'] = $topicId;
    $pres['created_at'] = date('Y-m-d H:i:s');
    foreach ($values as $field => $value) {
        $pres[$field] = $value;
    }
    dti_presentation_insert($pdo, $pres);
    return $pres;
}

/**
 * 발표를 통째로 지운다 — 발표자료와 반응까지 같이 사라진다.
 * 스캔 원본은 아티클에 딸린 것이라 남긴다.
 */
function dti_presentation_purge(PDO $pdo, string $uploadDir, array $pres): void {
    dti_material_remove_all($pdo, $uploadDir, (int)$pres['topic_id'], 'material');
    dti_emotion_delete_by_presentation($pdo, (int)$pres['id']);
    dti_presentation_delete($pdo, (int)$pres['id']);
}

/**
 * 예약 취소·배정 해제. 발표가 이미 끝났으면 기록을 남겨야 하므로 발표자만 지우고,
 * 아직이면 발표 행 자체를 없앤다.
 */
function dti_presentation_unassign(PDO $pdo, string $uploadDir, array $pres): void {
    if ($pres['done_date'] !== '') {
        $pres['presenter'] = '';
        $pres['presenter_email'] = '';
        $pres['planned_date'] = '';
        dti_presentation_update($pdo, $pres);
        return;
    }
    dti_presentation_purge($pdo, $uploadDir, $pres);
}

function dti_may_manage(?array $pres, string $email, bool $isAdmin): bool {
    return ($pres !== null && $pres['presenter_email'] === $email) || $isAdmin;
}

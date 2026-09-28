<?php

function dti_round_from_row(array $row): array {
    return [
        'id' => (int)$row['id'],
        'no' => (int)$row['no'],
        'title' => (string)$row['title'],
        'created_at' => (string)$row['created_at'],
    ];
}

function dti_round_all(PDO $pdo): array {
    return array_map('dti_round_from_row',
        $pdo->query("SELECT * FROM dti_rounds ORDER BY `no` DESC")->fetchAll());
}

function dti_round_find(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM dti_rounds WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? dti_round_from_row($row) : null;
}

function dti_round_find_or_fail(PDO $pdo, int $id): array {
    return dti_round_find($pdo, $id) ?? throw new DtiError('없는 회차입니다', 404);
}

function dti_round_no_taken(PDO $pdo, int $no, ?int $exceptId = null): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dti_rounds WHERE `no` = ? AND id <> ?");
    $stmt->execute([$no, $exceptId ?? 0]);
    return (int)$stmt->fetchColumn() > 0;
}

function dti_round_insert(PDO $pdo, array &$round): int {
    $pdo->prepare("INSERT INTO dti_rounds (`no`, title, created_at) VALUES (?, ?, ?)")
        ->execute([$round['no'], $round['title'], $round['created_at']]);
    return $round['id'] = (int)$pdo->lastInsertId();
}

function dti_round_update(PDO $pdo, array $round): void {
    $pdo->prepare("UPDATE dti_rounds SET `no` = ?, title = ? WHERE id = ?")
        ->execute([$round['no'], $round['title'], $round['id']]);
}

function dti_round_delete(PDO $pdo, int $id): void {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE dti_topics SET round_id = NULL WHERE round_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM dti_rounds WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 매거진·Volume 이 글자 그대로 같은 아티클을 모두 담는다. [담은 수, 다른 회차에서 옮겨 온 수] */
function dti_round_add_issue(PDO $pdo, int $id, string $magazine, string $volume): array {
    $stmt = $pdo->prepare("SELECT round_id FROM dti_topics WHERE magazine = ? AND volume = ?");
    $stmt->execute([$magazine, $volume]);
    $rounds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $moved = count(array_filter($rounds, static fn ($rid) => $rid !== null && (int)$rid !== $id));

    $pdo->prepare("UPDATE dti_topics SET round_id = ? WHERE magazine = ? AND volume = ?")
        ->execute([$id, $magazine, $volume]);

    return [count($rounds), $moved];
}

/**
 * 보관·숨김은 회차가 아니라 아티클이 들고 있다 — 목록·예약 차단·통계가 아티클 값만 보게 하려는 것이다.
 * $flags 는 active·archived 중 바꿀 것만. 값이 실제로 바뀐 아티클 수를 돌려준다.
 */
function dti_round_set_flags(PDO $pdo, int $id, array $flags): int {
    $sets = implode(', ', array_map(static fn ($column) => "`{$column}` = ?", array_keys($flags)));
    $stmt = $pdo->prepare("UPDATE dti_topics SET {$sets} WHERE round_id = ?");
    $stmt->execute([...array_values($flags), $id]);
    return $stmt->rowCount();
}

function dti_want_round_no(mixed $value): int {
    $no = is_string($value) ? trim($value) : $value;
    $no = is_int($no) || is_string($no) ? filter_var($no, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($no === false) throw new DtiError('회차 번호는 1 이상의 정수여야 합니다', 422);
    return $no;
}

/** 아티클의 회차. 빈 값은 회차 없음이다. */
function dti_want_round_id(PDO $pdo, mixed $value): ?int {
    if ($value === null || $value === '') return null;
    $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    if ($id === false || dti_round_find($pdo, $id) === null) {
        throw new DtiError('없는 회차입니다', 422);
    }
    return $id;
}

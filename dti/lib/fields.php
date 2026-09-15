<?php
/** 분야. 팀·매거진과 달리 코드가 아니라 DB 에서 관리한다. */

function dti_field_all(PDO $pdo) {
    return array_map(
        static fn (array $row) => ['id' => (int)$row['id'], 'name' => $row['name']],
        $pdo->query("SELECT id, name FROM dti_fields ORDER BY id")->fetchAll()
    );
}

function dti_field_exists(PDO $pdo, $name) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dti_fields WHERE name = ?");
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() > 0;
}

function dti_field_insert(PDO $pdo, $name) {
    $pdo->prepare("INSERT INTO dti_fields (name) VALUES (?)")->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function dti_field_delete(PDO $pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM dti_fields WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

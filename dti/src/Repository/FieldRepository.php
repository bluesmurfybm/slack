<?php

namespace Dti\Repository;

use PDO;

final class FieldRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array{id: int, name: string}> */
    public function all(): array
    {
        return array_map(
            static fn (array $row) => ['id' => (int)$row['id'], 'name' => $row['name']],
            $this->pdo->query("SELECT id, name FROM dti_fields ORDER BY id")->fetchAll()
        );
    }

    public function exists(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM dti_fields WHERE name = ?");
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function insert(string $name): int
    {
        $this->pdo->prepare("INSERT INTO dti_fields (name) VALUES (?)")->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM dti_fields WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}

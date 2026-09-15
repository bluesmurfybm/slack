<?php

namespace Dti\Repository;

use Dti\Entity\Presentation;
use PDO;

final class PresentationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(int $id): ?Presentation
    {
        $stmt = $this->pdo->prepare("SELECT * FROM dti_presentations WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? Presentation::fromRow($row) : null;
    }

    public function ofTopic(int $topicId): ?Presentation
    {
        $stmt = $this->pdo->prepare("SELECT * FROM dti_presentations WHERE topic_id = ?");
        $stmt->execute([$topicId]);
        $row = $stmt->fetch();
        return $row ? Presentation::fromRow($row) : null;
    }

    /** @return array<int, array{0: Presentation, 1: int}> 발표와 아티클 id */
    public function allWithTopics(): array
    {
        $sql = "SELECT p.* FROM dti_presentations p JOIN dti_topics t ON t.id = p.topic_id";
        return array_map(
            static fn (array $row) => Presentation::fromRow($row),
            $this->pdo->query($sql)->fetchAll()
        );
    }

    public function insert(Presentation $pres): int
    {
        $columns = array_values(array_diff(Presentation::COLUMNS, ['id']));
        $sql = 'INSERT INTO dti_presentations (`' . implode('`, `', $columns) . '`) VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $values = array_map(fn (string $column) => $pres->$column, $columns);
        $this->pdo->prepare($sql)->execute($values);

        return $pres->id = (int)$this->pdo->lastInsertId();
    }

    public function update(Presentation $pres): void
    {
        $columns = array_values(array_diff(Presentation::COLUMNS, ['id']));
        $sql = 'UPDATE dti_presentations SET ' . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $columns))
             . ' WHERE id = ?';

        $values = array_map(fn (string $column) => $pres->$column, $columns);
        $values[] = $pres->id;
        $this->pdo->prepare($sql)->execute($values);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM dti_presentations WHERE id = ?")->execute([$id]);
    }

    /**
     * 아무도 잡지 않은 발표 행을 조건부 UPDATE 로 차지한다. 동시 선점은 여기서 갈린다.
     * 잡았으면 true, 이미 임자가 있거나 행이 없으면 false.
     */
    public function claim(int $topicId, string $email, string $name, ?string $plannedDate): bool
    {
        $sql = "UPDATE dti_presentations SET presenter_email = ?, presenter = ?"
             . ($plannedDate === null ? '' : ', planned_date = ?')
             . " WHERE topic_id = ? AND presenter_email = '' AND done_date = ''";

        $values = [$email, $name];
        if ($plannedDate !== null) $values[] = $plannedDate;
        $values[] = $topicId;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount() > 0;
    }
}

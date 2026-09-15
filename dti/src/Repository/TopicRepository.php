<?php

namespace Dti\Repository;

use Dti\Entity\Presentation;
use Dti\Entity\Topic;
use Dti\Http\ApiException;
use PDO;

final class TopicRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(int $id): ?Topic
    {
        $stmt = $this->pdo->prepare("SELECT * FROM dti_topics WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? Topic::fromRow($row) : null;
    }

    public function findOrFail(int $id): Topic
    {
        return $this->find($id) ?? throw new ApiException('없는 아티클입니다', 404);
    }

    /** @return Topic[] */
    public function all(): array
    {
        return array_map(
            static fn (array $row) => Topic::fromRow($row),
            $this->pdo->query("SELECT * FROM dti_topics")->fetchAll()
        );
    }

    /**
     * 목록. 날짜(발표일 > 예정일) 없는 것이 먼저, 그다음 날짜 최신순, 마지막으로 등록 역순이다.
     *
     * @return array<int, array{0: Topic, 1: ?Presentation}>
     */
    public function listWithPresentations(bool $includeHidden): array
    {
        $on = "COALESCE(NULLIF(p.done_date, ''), NULLIF(p.planned_date, ''))";
        $where = $includeHidden ? '' : 'WHERE t.active = 1 AND t.archived = 0';

        $sql = "SELECT t.*, p.id AS p_id, p.topic_id AS p_topic_id, p.presenter AS p_presenter,
                       p.presenter_email AS p_presenter_email, p.planned_date AS p_planned_date,
                       p.done_date AS p_done_date, p.material_kind AS p_material_kind,
                       p.material_name AS p_material_name, p.material_url AS p_material_url,
                       p.material_path AS p_material_path, p.created_at AS p_created_at
                FROM dti_topics t
                LEFT JOIN dti_presentations p ON p.topic_id = t.id
                {$where}
                ORDER BY ({$on} IS NULL) DESC, {$on} DESC, t.id DESC";

        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $pres = null;
            if ($row['p_id'] !== null) {
                $presRow = [];
                foreach (Presentation::COLUMNS as $column) {
                    $presRow[$column] = $row['p_' . $column];
                }
                $pres = Presentation::fromRow($presRow);
            }
            $out[] = [Topic::fromRow($row), $pres];
        }
        return $out;
    }

    public function insert(Topic $topic): int
    {
        $columns = array_values(array_diff(Topic::COLUMNS, ['id']));
        $sql = 'INSERT INTO dti_topics (`' . implode('`, `', $columns) . '`) VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $values = array_map(fn (string $column) => $topic->$column, $columns);
        $this->pdo->prepare($sql)->execute($values);

        return $topic->id = (int)$this->pdo->lastInsertId();
    }

    public function update(Topic $topic): void
    {
        $columns = array_values(array_diff(Topic::COLUMNS, ['id']));
        $sql = 'UPDATE dti_topics SET ' . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $columns))
             . ' WHERE id = ?';

        $values = array_map(fn (string $column) => $topic->$column, $columns);
        $values[] = $topic->id;
        $this->pdo->prepare($sql)->execute($values);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM dti_topics WHERE id = ?")->execute([$id]);
    }
}

<?php

namespace Dti\Repository;

use PDO;

final class RelatedRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<int, array{0: int, 1: int, 2: int}> $pairs */
    public function replaceAll(array $pairs): void
    {
        // 한 트랜잭션으로 넣는다 — 커밋마다 fsync 가 도는 환경에서 건별 커밋은 너무 느리다
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();

        $this->pdo->exec("DELETE FROM dti_related");
        $insert = $this->pdo->prepare("INSERT INTO dti_related (topic_id, related_id, score) VALUES (?, ?, ?)");
        foreach ($pairs as [$topicId, $relatedId, $score]) {
            $insert->execute([$topicId, $relatedId, $score]);
        }

        if ($own) $this->pdo->commit();
    }

    /** 숨김·보관은 빼고 점수 높은 순으로. 화면 드로어가 쓰는 모양 그대로 돌려준다. */
    public function topFor(int $topicId, int $limit): array
    {
        $sql = "SELECT t.id, t.title, t.field, t.magazine, t.volume, t.page, r.score
                FROM dti_related r
                JOIN dti_topics t ON t.id = r.related_id
                WHERE r.topic_id = ? AND t.active = 1 AND t.archived = 0
                ORDER BY r.score DESC, t.id DESC
                LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$topicId]);

        return array_map(static fn (array $row) => [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'field' => $row['field'],
            'magazine' => $row['magazine'],
            'volume' => $row['volume'],
            'page' => $row['page'],
            'score' => (int)$row['score'],
        ], $stmt->fetchAll());
    }
}

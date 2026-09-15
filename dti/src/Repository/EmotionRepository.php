<?php

namespace Dti\Repository;

use Dti\Config;
use PDO;

final class EmotionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public static function emptyCounts(): array
    {
        return array_fill_keys(Config::EMOTIONS, 0);
    }

    /**
     * 목록용 집계. 화면은 아티클 단위로 그리므로 발표가 아니라 아티클 id 로 묶어 돌려준다.
     *
     * @return array{0: array<int, array<string, int>>, 1: array<int, string[]>}
     */
    public function summary(string $me): array
    {
        $sql = "SELECT p.topic_id, e.kind, e.email
                FROM dti_emotions e
                JOIN dti_presentations p ON p.id = e.presentation_id";

        $counts = [];
        $mine = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $topicId = (int)$row['topic_id'];
            $counts[$topicId] ??= self::emptyCounts();
            $counts[$topicId][$row['kind']]++;
            if ($row['email'] === $me) {
                $mine[$topicId][] = $row['kind'];
            }
        }
        return [$counts, $mine];
    }

    public function countFor(int $presentationId, string $kind): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM dti_emotions WHERE presentation_id = ? AND kind = ?");
        $stmt->execute([$presentationId, $kind]);
        return (int)$stmt->fetchColumn();
    }

    /** 있으면 지우고 없으면 남긴다. 남겼으면 true. */
    public function toggle(int $presentationId, string $email, string $kind, string $now): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM dti_emotions WHERE presentation_id = ? AND email = ? AND kind = ?");
        $stmt->execute([$presentationId, $email, $kind]);
        if ($stmt->rowCount() > 0) return false;

        $this->pdo->prepare(
            "INSERT INTO dti_emotions (presentation_id, email, kind, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$presentationId, $email, $kind, $now]);
        return true;
    }

    public function deleteByPresentation(int $presentationId): void
    {
        $this->pdo->prepare("DELETE FROM dti_emotions WHERE presentation_id = ?")
            ->execute([$presentationId]);
    }

    /** @return array<int, array{email: string, date: string, count: int}> 사람·날짜별 반응 수 */
    public function dailyCounts(): array
    {
        $sql = "SELECT email, LEFT(created_at, 10) AS day, COUNT(*) AS n
                FROM dti_emotions GROUP BY email, day";

        return array_map(static fn (array $row) => [
            'email' => $row['email'],
            'date' => $row['day'],
            'count' => (int)$row['n'],
        ], $this->pdo->query($sql)->fetchAll());
    }
}

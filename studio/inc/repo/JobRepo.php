<?php
/**
 * 오래 걸리는 분석 작업 큐.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 한 프로젝트에 한 번에 하나만 돈다                                 │
 * │                                                                  │
 * │ 두 사람이 동시에 눌러도 두 번 돌면 안 된다. 같은 링크를 두 번     │
 * │ 읽으면 상대 서비스의 호출 제한에 걸리고, 진행률이 두 벌로 갈려    │
 * │ 화면이 뒤죽박죽이 된다.                                           │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 워커가 죽어도 큐가 영영 막히지 않아야 한다. running 인데 heartbeat 가
 * 오래 멈춘 작업은 다른 워커가 집어 간다.
 */

declare(strict_types=1);

final class JobRepo
{
    /** 이보다 오래 소식이 없으면 죽은 것으로 본다(분). */
    public const STALE_MINUTES = 15;

    public function __construct(private PDO $pdo) {}

    /**
     * 큐에 넣는다. 이미 도는 것이 있으면 그것을 돌려준다.
     *
     * @return array{job:array, created:bool}
     */
    public function enqueue(int $projectId, string $kind, int $total, array $actor): array
    {
        $live = $this->liveOf($projectId, $kind);
        if ($live !== null) {
            return ['job' => $live, 'created' => false];
        }

        $st = $this->pdo->prepare(
            'INSERT INTO bs_analysis_job
                    (project_id, kind, status, total, created_by, created_by_name)
             VALUES (?,?, "queued", ?, ?, ?)'
        );
        $st->execute([
            $projectId, $kind, max(0, $total),
            (string)($actor['id'] ?? ''), (string)($actor['name'] ?? ''),
        ]);
        return ['job' => $this->find((int)$this->pdo->lastInsertId()) ?? [], 'created' => true];
    }

    /** 지금 대기 중이거나 도는 중인 작업. 죽은 것은 치고 본다. */
    public function liveOf(int $projectId, ?string $kind = null): ?array
    {
        $this->reviveStale();

        $sql = 'SELECT * FROM bs_analysis_job
                 WHERE project_id = ? AND status IN ("queued","running")';
        $arg = [$projectId];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $arg[] = $kind;
        }
        $sql .= ' ORDER BY id LIMIT 1';

        $st = $this->pdo->prepare($sql);
        $st->execute($arg);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_analysis_job WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** 최근 작업 몇 건. 화면이 "지난번엔 어땠나" 를 보여 준다. */
    public function recent(int $projectId, int $limit = 5): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_analysis_job WHERE project_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------
    // 워커가 쓰는 것
    // -----------------------------------------------------------------

    /**
     * 다음 할 일을 하나 집는다. **집는 순간 running 으로 바꾼다.**
     *
     * UPDATE 로 먼저 찜하고 그 뒤에 읽는다. SELECT 로 고른 뒤 UPDATE 하면
     * 워커가 둘일 때 같은 작업을 둘 다 집는다.
     */
    public function claimNext(): ?array
    {
        $this->reviveStale();

        $st = $this->pdo->prepare(
            'UPDATE bs_analysis_job
                SET status = "running", started_at = COALESCE(started_at, NOW()),
                    heartbeat_at = NOW()
              WHERE status = "queued"
              ORDER BY id LIMIT 1'
        );
        $st->execute();
        if ($st->rowCount() === 0) {
            return null;
        }

        // 방금 찜한 것을 되찾는다. heartbeat 가 가장 최근인 running 하나다.
        $sel = $this->pdo->query(
            'SELECT * FROM bs_analysis_job WHERE status = "running"
              ORDER BY heartbeat_at DESC, id DESC LIMIT 1'
        );
        $r = $sel->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** 한 건 끝낼 때마다 진행률과 생존 신호를 함께 올린다. */
    public function progress(int $jobId, bool $ok): void
    {
        $this->pdo->prepare(
            'UPDATE bs_analysis_job
                SET done = done + 1, failed = failed + ?, heartbeat_at = NOW()
              WHERE id = ?'
        )->execute([$ok ? 0 : 1, $jobId]);
    }

    public function beat(int $jobId): void
    {
        $this->pdo->prepare('UPDATE bs_analysis_job SET heartbeat_at = NOW() WHERE id = ?')
                  ->execute([$jobId]);
    }

    public function finish(int $jobId, string $status, string $message = ''): void
    {
        $this->pdo->prepare(
            'UPDATE bs_analysis_job
                SET status = ?, message = ?, finished_at = NOW(), heartbeat_at = NULL
              WHERE id = ?'
        )->execute([$status, mb_substr($message, 0, 300), $jobId]);
    }

    /** 사람이 멈춘다. 워커는 다음 한 건을 끝내고 멈춘다. */
    public function cancel(int $projectId): int
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_analysis_job SET status = "canceled", finished_at = NOW(),
                    message = "사람이 멈췄습니다."
              WHERE project_id = ? AND status IN ("queued","running")'
        );
        $st->execute([$projectId]);
        return $st->rowCount();
    }

    /**
     * 죽은 작업을 되살린다.
     *
     * 워커가 중간에 죽으면 running 인 채로 남는다. 그대로 두면 그 프로젝트는
     * 영영 새 작업을 못 넣는다. 오래 소식이 없으면 대기로 되돌려 다음 워커가
     * 이어 가게 한다 — 이미 읽은 링크는 'ok' 라 다시 읽지 않는다.
     */
    private function reviveStale(): void
    {
        $this->pdo->prepare(
            'UPDATE bs_analysis_job
                SET status = "queued", heartbeat_at = NULL,
                    message = "워커가 멈춰 있어 다시 집어 갑니다."
              WHERE status = "running"
                AND (heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))'
        )->execute([self::STALE_MINUTES]);
    }
}

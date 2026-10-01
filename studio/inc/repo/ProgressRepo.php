<?php
/** ba_progress / ba_progress_comment 접근 담당 DAO. 개발자가 올리는 진행상황과 댓글을 다룬다. */

declare(strict_types=1);

/**
 * 진행상황 저장소.
 *
 * ba_progress 는 기록이라 수정하지 않는다(001_schema.sql 에 updated_at 이 없다).
 * 내용을 고쳐야 하면 새 기록을 하나 더 남긴다.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 여기 있는 조회는 전부 **태스크 수와 무관하게 질의 수가 고정**이다. │
 * │ 대시보드가 태스크마다 한 번씩 물으면 수백 번이 된다.              │
 * │ 목록을 받는 메서드는 IN (…) 한 번으로 끝내고, 짝짓기는 PHP 에서   │
 * │ 한다. 새 메서드를 넣을 때도 이 규칙을 지킬 것.                    │
 * └──────────────────────────────────────────────────────────────────┘
 */
final class ProgressRepo
{
    /** 진행 기록에 붙는 공통 조회 칼럼. 사람 이름까지 한 번에 가져온다. */
    private const SELECT = '
        p.id, p.task_id, p.member_id, p.status, p.progress_pct,
        p.content, p.blocker, p.worked_on, p.created_at,
        m.emp_name, t.wbs_no, t.title AS task_title, t.project_id';

    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 진행상황 (ba_progress)
    // =================================================================

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT ' . self::SELECT . '
               FROM ba_progress p
               JOIN ba_member m ON m.id = p.member_id
               JOIN ba_task   t ON t.id = p.task_id
              WHERE p.id = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->present($r) : null;
    }

    /** 한 태스크의 진행 기록. 최근 것이 앞. */
    public function byTask(int $taskId, int $limit = 50): array
    {
        // ix_ba_progress_task (task_id, created_at) 를 탄다.
        $st = $this->pdo->prepare(
            'SELECT ' . self::SELECT . '
               FROM ba_progress p
               JOIN ba_member m ON m.id = p.member_id
               JOIN ba_task   t ON t.id = p.task_id
              WHERE p.task_id = ?
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ' . $this->lim($limit)
        );
        $st->execute([$taskId]);
        return array_map([$this, 'present'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** 한 사람의 최근 진행 기록. 대시보드가 쓴다. */
    public function byMember(int $memberId, int $limit = 50): array
    {
        // ix_ba_progress_member (member_id, created_at) 를 탄다.
        $st = $this->pdo->prepare(
            'SELECT ' . self::SELECT . '
               FROM ba_progress p
               JOIN ba_member m ON m.id = p.member_id
               JOIN ba_task   t ON t.id = p.task_id
              WHERE p.member_id = ?
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ' . $this->lim($limit)
        );
        $st->execute([$memberId]);
        return array_map([$this, 'present'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** 프로젝트 전체의 최근 진행 피드. */
    public function feedByProject(int $projectId, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT ' . self::SELECT . '
               FROM ba_progress p
               JOIN ba_task   t ON t.id = p.task_id
               JOIN ba_member m ON m.id = p.member_id
              WHERE t.project_id = ?
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ' . $this->lim($limit)
        );
        $st->execute([$projectId]);
        return array_map([$this, 'present'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 태스크별 최신 기록 하나씩. 목록 화면이 N+1 을 내지 않도록.
     *
     * MySQL 8 의 윈도 함수(ROW_NUMBER)를 쓰면 한 줄이지만 **5.7 호환**을
     * 지켜야 해서 (task_id, MAX(id)) 부분질의와 조인으로 짰다.
     * created_at 이 아니라 id 로 최신을 고른다 — 같은 초에 두 건이
     * 들어오면 created_at 만으로는 못 가른다.
     *
     * @return array<int, array> task_id => row
     */
    public function latestPerTask(array $taskIds): array
    {
        $ids = $this->ids($taskIds);
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT " . self::SELECT . "
               FROM ba_progress p
               JOIN (SELECT task_id, MAX(id) AS mx
                       FROM ba_progress
                      WHERE task_id IN ($ph)
                      GROUP BY task_id) x ON x.mx = p.id
               JOIN ba_member m ON m.id = p.member_id
               JOIN ba_task   t ON t.id = p.task_id"
        );
        $st->execute($ids);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['task_id']] = $this->present($r);
        }
        return $out;
    }

    /** 여러 진행 기록의 댓글을 한 번에. @return array<int, array> progress_id => rows */
    public function commentsFor(array $progressIds): array
    {
        $ids = $this->ids($progressIds);
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // ix_ba_pcomment_progress (progress_id, id)
        $st = $this->pdo->prepare(
            "SELECT id, progress_id, user_id, user_name, content, created_at
               FROM ba_progress_comment
              WHERE progress_id IN ($ph)
              ORDER BY progress_id, id"
        );
        $st->execute($ids);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['progress_id']][] = [
                'id'         => (int)$r['id'],
                'user_id'    => $r['user_id'],
                'user_name'  => $r['user_name'],
                'content'    => $r['content'],
                'created_at' => $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * 진행상황 등록.
     *
     * ba_task.status / progress_pct 도 **같은 트랜잭션에서** 갱신한다.
     * 따로 하면 기록은 남았는데 태스크 상태는 옛것인 상태가 생기고,
     * 칸반과 피드가 서로 다른 말을 하게 된다.
     *
     * 본인이 맡은 태스크인지 확인은 호출부(API)가 한다 — 여기서는
     * 배정안을 모른다.
     */
    public function create(int $taskId, int $memberId, array $data): int
    {
        $status = $data['status'] ?? null;
        if ($status !== null && !isset(BA_TASK_STATUS[$status])) {
            throw new InvalidArgumentException('알 수 없는 상태입니다: ' . $status);
        }

        $pct = $data['progress_pct'] ?? null;
        if ($pct !== null) {
            if (!is_numeric($pct)) {
                throw new InvalidArgumentException('진행률은 숫자여야 합니다.');
            }
            $pct = (int)$pct;
            if ($pct < 0 || $pct > 100) {
                throw new InvalidArgumentException('진행률은 0~100 사이여야 합니다.');
            }
        }

        $content = $this->nn($data['content'] ?? null);
        $blocker = $this->nn($data['blocker'] ?? null);
        if ($status === null && $pct === null && $content === null && $blocker === null) {
            throw new InvalidArgumentException('올릴 내용이 없습니다.');
        }

        $worked = $this->nn($data['worked_on'] ?? null) ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$worked)) {
            throw new InvalidArgumentException('작업일 형식이 올바르지 않습니다: ' . $worked);
        }

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO ba_progress
                    (task_id, member_id, status, progress_pct, content, blocker, worked_on)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $st->execute([$taskId, $memberId, $status, $pct, $content, $blocker, $worked]);
            $id = (int)$this->pdo->lastInsertId();

            // 태스크 쪽은 **준 것만** 바꾼다. 상태만 올렸는데 진행률이
            // 0 으로 덮이면 사람이 올린 적 없는 값이 기록된다.
            $set = [];
            $par = [];
            if ($status !== null) { $set[] = 'status = ?';       $par[] = $status; }
            if ($pct !== null)    { $set[] = 'progress_pct = ?'; $par[] = $pct; }
            // done 으로 올리면 진행률도 100 으로 맞춘다. 92% 인 완료는
            // 칸반과 진척률이 어긋나 보인다.
            if ($status === 'done' && $pct === null) {
                $set[] = 'progress_pct = 100';
            }
            if ($set) {
                $par[] = $taskId;
                $this->pdo->prepare(
                    'UPDATE ba_task SET ' . implode(', ', $set) . ' WHERE id = ?'
                )->execute($par);
            }

            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 삭제. 잘못 올린 기록을 지울 때만.
     * 기본적으로 기록은 남기는 쪽이라 관리자만 부를 수 있게 한다(확인은 호출부).
     *
     * 태스크의 status/progress_pct 는 되돌리지 않는다. 기록을 지운다고
     * 그 뒤에 일어난 일까지 없던 것이 되지는 않는다 — 되돌리려면
     * 새 기록을 올려야 한다.
     */
    public function delete(int $id): void
    {
        // ba_progress_comment 는 FK CASCADE 로 함께 지워진다.
        $this->pdo->prepare('DELETE FROM ba_progress WHERE id = ?')->execute([$id]);
    }

    // =================================================================
    // 댓글 (ba_progress_comment)
    // =================================================================

    public function comments(int $progressId): array
    {
        $m = $this->commentsFor([$progressId]);
        return $m[$progressId] ?? [];
    }

    /**
     * 댓글 등록.
     *
     * 작성자는 ba_member 가 아니라 포털 계정이다(구성원이 아닌 PM 도 단다).
     * user_id 는 이메일, user_name 은 그 시점 이름 스냅샷.
     */
    public function addComment(int $progressId, array $actor, string $content): int
    {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('내용을 입력하세요.');
        }
        $st = $this->pdo->prepare(
            'INSERT INTO ba_progress_comment (progress_id, user_id, user_name, content)
             VALUES (?,?,?,?)'
        );
        $st->execute([
            $progressId, $actor['id'] ?? '', $actor['name'] ?? '',
            mb_substr($content, 0, 5000),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findComment(int $commentId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ba_progress_comment WHERE id = ?'
        );
        $st->execute([$commentId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteComment(int $commentId): void
    {
        $this->pdo->prepare('DELETE FROM ba_progress_comment WHERE id = ?')
                  ->execute([$commentId]);
    }

    // =================================================================
    // 집계
    // =================================================================

    /**
     * 프로젝트 진척률. 태스크 progress_pct 를 공수(est_md)로 가중 평균한다.
     *
     * **공수가 빈 태스크를 어떻게 셀 것인가** — 빼면 그 일은 없는 것이 되고,
     * 1 로 두면 10 M/D 짜리와 같은 무게가 된다. 그 프로젝트 말단 태스크
     * 공수의 **중앙값**으로 놓는다. 지어낸 값이 아니라 그 프로젝트의
     * 실제 값이고, 몇 건을 그렇게 놓았는지 함께 돌려준다.
     *
     * 상위 태스크는 세지 않는다 — 하위의 묶음이라 이중 계산된다.
     *
     * @return array{pct:float,total_md:float,counted:int,no_est:int,median:float}
     */
    public function projectProgress(int $projectId): array
    {
        $st = $this->pdo->prepare(
            'SELECT t.id, t.est_md, t.progress_pct, t.status
               FROM ba_task t
              WHERE t.project_id = ?
                AND t.confirmed = 1
                AND NOT EXISTS (SELECT 1 FROM ba_task c WHERE c.parent_id = t.id)'
        );
        $st->execute([$projectId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return ['pct' => 0.0, 'total_md' => 0.0, 'counted' => 0, 'no_est' => 0, 'median' => 0.0];
        }

        $known = [];
        foreach ($rows as $r) {
            if ($r['est_md'] !== null && (float)$r['est_md'] > 0) {
                $known[] = (float)$r['est_md'];
            }
        }
        sort($known);
        $median = $known ? $this->median($known) : 1.0;

        $wsum = 0.0; $psum = 0.0; $noEst = 0;
        foreach ($rows as $r) {
            $w = ($r['est_md'] !== null && (float)$r['est_md'] > 0)
               ? (float)$r['est_md'] : $median;
            if ($r['est_md'] === null || (float)$r['est_md'] <= 0) {
                $noEst++;
            }
            $wsum += $w;
            $psum += $w * (int)$r['progress_pct'];
        }

        return [
            'pct'      => $wsum > 0 ? round($psum / $wsum, 1) : 0.0,
            'total_md' => round($wsum, 2),
            'counted'  => count($rows),
            'no_est'   => $noEst,
            'median'   => round($median, 2),
        ];
    }

    /**
     * 여러 프로젝트의 진척률을 한 번에.
     * 카드 목록이 프로젝트마다 물으면 그만큼 질의가 늘어난다.
     *
     * @return array<int, array>
     */
    public function projectProgressMany(array $projectIds): array
    {
        $ids = $this->ids($projectIds);
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT t.project_id, t.id, t.est_md, t.progress_pct
               FROM ba_task t
              WHERE t.project_id IN ($ph)
                AND t.confirmed = 1
                AND NOT EXISTS (SELECT 1 FROM ba_task c WHERE c.parent_id = t.id)"
        );
        $st->execute($ids);

        $byProject = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byProject[(int)$r['project_id']][] = $r;
        }

        $out = [];
        foreach ($ids as $pid) {
            $rows = $byProject[$pid] ?? [];
            if (!$rows) {
                $out[$pid] = ['pct' => 0.0, 'total_md' => 0.0, 'counted' => 0,
                              'no_est' => 0, 'median' => 0.0];
                continue;
            }
            $known = [];
            foreach ($rows as $r) {
                if ($r['est_md'] !== null && (float)$r['est_md'] > 0) { $known[] = (float)$r['est_md']; }
            }
            sort($known);
            $median = $known ? $this->median($known) : 1.0;

            $wsum = 0.0; $psum = 0.0; $noEst = 0;
            foreach ($rows as $r) {
                $has = $r['est_md'] !== null && (float)$r['est_md'] > 0;
                $w = $has ? (float)$r['est_md'] : $median;
                if (!$has) { $noEst++; }
                $wsum += $w;
                $psum += $w * (int)$r['progress_pct'];
            }
            $out[$pid] = [
                'pct'      => $wsum > 0 ? round($psum / $wsum, 1) : 0.0,
                'total_md' => round($wsum, 2),
                'counted'  => count($rows),
                'no_est'   => $noEst,
                'median'   => round($median, 2),
            ];
        }
        return $out;
    }

    /**
     * 지연 태스크 — plan_end 가 지났는데 아직 안 끝난 것.
     *
     * 명세서 §7.1 Step4: `plan_end < today AND status != done` → 빨강.
     * `prod_deployed` 도 뺀다 — 운영에 올라간 일을 지연이라 부르면
     * 화면이 계속 빨갛고, 그러면 아무도 빨간색을 안 본다.
     */
    public function overdueTasks(int $projectId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $ph = implode(',', array_fill(0, count(BA_TASK_OVERDUE_EXEMPT), '?'));
        $st = $this->pdo->prepare(
            "SELECT t.id, t.wbs_no, t.title, t.plan_end, t.status, t.progress_pct,
                    DATEDIFF(?, t.plan_end) AS overdue_days
               FROM ba_task t
              WHERE t.project_id = ?
                AND t.confirmed = 1
                AND t.plan_end IS NOT NULL
                AND t.plan_end < ?
                AND t.status NOT IN ($ph)
                AND NOT EXISTS (SELECT 1 FROM ba_task c WHERE c.parent_id = t.id)
              ORDER BY t.plan_end"
        );
        $st->execute(array_merge([$today, $projectId, $today], BA_TASK_OVERDUE_EXEMPT));
        return array_map(static function (array $r): array {
            $r['id']           = (int)$r['id'];
            $r['progress_pct'] = (int)$r['progress_pct'];
            $r['overdue_days'] = (int)$r['overdue_days'];
            $r['status_label'] = BA_TASK_STATUS[$r['status']] ?? $r['status'];
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // =================================================================
    // 안쪽
    // =================================================================

    private function present(array $r): array
    {
        return [
            'id'           => (int)$r['id'],
            'task_id'      => (int)$r['task_id'],
            'project_id'   => (int)$r['project_id'],
            'member_id'    => (int)$r['member_id'],
            'emp_name'     => $r['emp_name'],
            'wbs_no'       => $r['wbs_no'],
            'task_title'   => $r['task_title'],
            'status'       => $r['status'],
            'status_label' => $r['status'] !== null
                              ? (BA_TASK_STATUS[$r['status']] ?? $r['status']) : null,
            'progress_pct' => $r['progress_pct'] !== null ? (int)$r['progress_pct'] : null,
            'content'      => $r['content'],
            'blocker'      => $r['blocker'],
            'worked_on'    => $r['worked_on'],
            'created_at'   => $r['created_at'],
        ];
    }

    private function median(array $sorted): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);
        return $n % 2 === 1
            ? (float)$sorted[$mid]
            : ((float)$sorted[$mid - 1] + (float)$sorted[$mid]) / 2.0;
    }

    /** LIMIT 에 값을 바로 넣어야 해서 정수임을 여기서 보장한다. */
    private function lim(int $n): string
    {
        return (string)max(1, min(500, $n));
    }

    /** @return int[] */
    private function ids(array $v): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $v), static fn($x) => $x > 0
        )));
    }

    private function nn(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}

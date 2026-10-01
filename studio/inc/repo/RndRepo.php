<?php
/**
 * R&D 과제 저장소 (명세서 §3.4 · §8).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 가시성을 여기서 강제한다 (명세서 §8.6)                            │
 * │                                                                  │
 * │ 모든 읽기가 bs_rnd_visible_sql() 을 붙인다. 못 볼 과제는 **쿼리가 │
 * │ 애초에 집어 오지 않는다.** 화면에서 거르면 API 를 직접 불러 뚫린다│
 * │ — 후보 목록에서 실제로 겪은 일이다(api_test [M] 주석).           │
 * │                                                                  │
 * │ 쓰기는 한 번 더 본다. 읽기로 집은 뒤 고치는 경로라도, 그 사이에   │
 * │ 공개 범위가 바뀌었을 수 있다.                                     │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * project_type='rnd' 조건도 같은 함수가 함께 붙인다. 이 Repo 로는
 * 지시형 프로젝트를 집을 수 없다.
 */

declare(strict_types=1);

require_once __DIR__ . '/../presenter.php';

final class RndRepo
{
    private const COLS = 'p.id, p.code, p.name, p.summary, p.notes, p.extra,
        p.status, p.visibility, p.rnd_category,
        p.dev_start, p.dev_end,
        p.owner_id, p.owner_name,
        p.proposer_id, p.proposer_name,
        p.approved_by, p.approved_by_name, p.approved_at,
        p.load_cap, p.recruiting,
        p.created_at, p.updated_at';

    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 조회
    // =================================================================

    /**
     * 한 건. **볼 수 없으면 null** — 없는 것과 같게 다룬다.
     *
     * "권한이 없습니다" 와 "없습니다" 를 가르면, 비공개 과제가 **존재한다는
     * 사실**이 샌다. 제목은 못 봐도 몇 번에 무엇이 있는지는 알게 된다.
     */
    public function find(int $id): ?array
    {
        [$vis, $visParams] = bs_rnd_visible_sql('p');

        $st = $this->pdo->prepare(
            'SELECT ' . self::COLS . ',
                    (SELECT COUNT(*) FROM bs_rnd_member m
                      WHERE m.project_id = p.id AND m.status = \'approved\') AS member_count,
                    (SELECT COUNT(*) FROM bs_rnd_output o WHERE o.project_id = p.id) AS output_count,
                    (SELECT COUNT(*) FROM bs_rnd_log    l WHERE l.project_id = p.id) AS log_count,
                    (SELECT COUNT(*) FROM bs_rnd_interest i WHERE i.project_id = p.id) AS interest_count,
                    (SELECT MAX(l2.created_at) FROM bs_rnd_log l2 WHERE l2.project_id = p.id) AS last_log_at
               FROM bs_project p
              WHERE p.id = ? AND p.deleted_at IS NULL AND ' . $vis
        );
        $st->execute(array_merge([$id], $visParams));
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ?: null;
    }

    /**
     * 보드 목록 (명세서 §8.1).
     *
     * @param array $f
     *   category    rnd_category 하나
     *   status      상태 하나 또는 배열
     *   recruiting  '1' 이면 모집 중만
     *   mine        '1' 이면 내가 발의했거나 참여 중인 것만
     *   keyword     코드·과제명·개요
     *   sort        recent(기본) | active | recruiting
     *   page, size
     */
    public function board(array $f): array
    {
        [$where, $params] = $this->buildWhere($f);

        $page = max(1, (int)($f['page'] ?? 1));
        $size = max(1, min(100, (int)($f['size'] ?? 24)));

        $total = (int)$this->scalar(
            'SELECT COUNT(*) FROM bs_project p WHERE ' . $where,
            $params
        );
        $pages  = $total > 0 ? (int)ceil($total / $size) : 1;
        $page   = min($page, $pages);
        $offset = ($page - 1) * $size;

        // ORDER BY 는 바인딩할 수 없으므로 화이트리스트로만 고른다.
        $order = match ((string)($f['sort'] ?? 'recent')) {
            // 활동 많은 순 — 진행 기록이 많은 과제가 위로.
            'active'     => 'log_count DESC, p.id DESC',
            // 모집중 우선 — 사람을 찾고 있는 과제를 먼저 보여 준다.
            'recruiting' => 'p.recruiting DESC, p.id DESC',
            default      => 'p.id DESC',
        };

        $sql = 'SELECT ' . self::COLS . ',
                       (SELECT COUNT(*) FROM bs_rnd_member m
                         WHERE m.project_id = p.id AND m.status = \'approved\') AS member_count,
                       (SELECT COUNT(*) FROM bs_rnd_output o WHERE o.project_id = p.id) AS output_count,
                       (SELECT COUNT(*) FROM bs_rnd_log    l WHERE l.project_id = p.id) AS log_count,
                       (SELECT COUNT(*) FROM bs_rnd_interest i WHERE i.project_id = p.id) AS interest_count,
                       (SELECT MAX(l2.created_at) FROM bs_rnd_log l2 WHERE l2.project_id = p.id) AS last_log_at
                  FROM bs_project p
                 WHERE ' . $where . '
                 ORDER BY ' . $order . '
                 LIMIT ? OFFSET ?';

        $st = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $st->bindValue($i + 1, $v);
        }
        $st->bindValue(count($params) + 1, $size, PDO::PARAM_INT);
        $st->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $st->execute();

        return [
            'rows'  => $st->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'pages' => $pages,
        ];
    }

    /** 상태별 건수. 보드 탭에 쓴다. 가시성은 목록과 같게 걸린다. */
    public function statusCounts(array $f): array
    {
        $f2 = $f;
        unset($f2['status']);                 // 상태 탭은 자기 자신으로 거르지 않는다
        [$where, $params] = $this->buildWhere($f2);

        $st = $this->pdo->prepare(
            'SELECT p.status, COUNT(*) AS cnt FROM bs_project p WHERE ' . $where . ' GROUP BY p.status'
        );
        $st->execute($params);

        $out = ['' => 0];
        foreach (array_keys(BS_RND_STATUS) as $k) {
            $out[$k] = 0;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['status']] = (int)$r['cnt'];
            $out['']                  += (int)$r['cnt'];
        }
        return $out;
    }

    /** 보드 머리의 숫자 (명세서 §8.1). 가시성 안에서만 센다. */
    public function stats(): array
    {
        [$vis, $visParams] = bs_rnd_visible_sql('p');
        $base = 'FROM bs_project p WHERE p.deleted_at IS NULL AND ' . $vis;

        $running = (int)$this->scalar(
            "SELECT COUNT(*) $base AND p.status IN ('approved','running')", $visParams
        );
        $doneQ = (int)$this->scalar(
            "SELECT COUNT(*) $base AND p.status = 'done'
               AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)", $visParams
        );
        $outputs = (int)$this->scalar(
            "SELECT COUNT(*) FROM bs_rnd_output o
              WHERE o.project_id IN (SELECT p.id $base)", $visParams
        );

        return [
            'running_count' => $running,
            'done_quarter'  => $doneQ,
            'output_total'  => $outputs,
        ];
    }

    // =================================================================
    // 변경
    // =================================================================

    /**
     * 발의 (명세서 §8.2).
     *
     * 발의자는 **부른 사람 자신**이다. 남의 이름으로 발의할 수 없다.
     * 상태는 draft 또는 proposed 로만 들어온다 — approved 로 바로 만들 수 없다.
     */
    public function propose(array $data, array $actor): int
    {
        $this->validate($data, true);

        $status = ($data['status'] ?? 'proposed') === 'draft' ? 'draft' : 'proposed';
        $year   = (int)date('Y', strtotime((string)($data['dev_start'] ?? date('Y-m-d'))) ?: time());

        for ($try = 0; $try < 5; $try++) {
            try {
                $st = $this->pdo->prepare(
                    "INSERT INTO bs_project
                        (code, project_type, visibility, name, summary, notes,
                         rnd_category, dev_start, dev_end, status,
                         owner_id, owner_name, proposer_id, proposer_name,
                         load_cap, recruiting)
                     VALUES (?, 'rnd', ?, ?,?,?, ?,?,?,?, ?,?,?,?, ?,?)"
                );
                $st->execute([
                    $this->nextCode($year),
                    $data['visibility'] ?? 'private',
                    $data['name'],
                    $this->nn($data['summary'] ?? null),
                    $this->nn($data['notes'] ?? null),
                    $this->nn($data['rnd_category'] ?? null),
                    $this->nn($data['dev_start'] ?? null),
                    $this->nn($data['dev_end'] ?? null),
                    $status,
                    // owner 는 발의자와 같다. 나중에 lead 가 바뀌면 그때 옮긴다.
                    $actor['id'], $actor['name'],
                    $actor['id'], $actor['name'],
                    $this->nn($data['load_cap'] ?? null),
                    !empty($data['recruiting']) ? 1 : 0,
                ]);
                return (int)$this->pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // 채번이 겹쳤다. 다시 뽑는다.
            }
        }
        throw new RuntimeException('과제 코드를 채번하지 못했습니다. 다시 시도해 주세요.');
    }

    /**
     * 수정. 넘어온 키만 바꾼다.
     *
     * status 는 여기서 바꾸지 않는다 — approve()/reject() 가 전용 경로다.
     * 그 둘을 거치지 않고 approved 로 올릴 수 있으면 승인 통제(§9.1)가 뚫린다.
     */
    public function update(int $id, array $data): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('과제를 찾을 수 없습니다.');
        }
        if (!bs_rnd_can_edit($cur)) {
            throw new DomainException('이 과제를 수정할 권한이 없습니다.');
        }
        $this->validate($data, false);

        $map = [
            'name'         => 'name',
            'summary'      => 'summary',
            'notes'        => 'notes',
            'rnd_category' => 'rnd_category',
            'visibility'   => 'visibility',
            'dev_start'    => 'dev_start',
            'dev_end'      => 'dev_end',
            'load_cap'     => 'load_cap',
            'recruiting'   => 'recruiting',
        ];
        $sets = [];
        $p    = [];
        foreach ($map as $key => $col) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $sets[] = "`$col` = ?";
            $p[]    = $col === 'recruiting'
                ? (!empty($data[$key]) ? 1 : 0)
                : $this->nn($data[$key]);
        }
        if (!$sets) {
            throw new InvalidArgumentException('바꿀 내용이 없습니다.');
        }

        $p[] = $id;
        $this->pdo->prepare(
            'UPDATE bs_project SET ' . implode(', ', $sets)
            . " WHERE id = ? AND project_type = 'rnd' AND deleted_at IS NULL"
        )->execute($p);
    }

    /**
     * 승인 (명세서 §8.2 · §9.1).
     *
     * **approved_at 이 차야 점유를 적재할 수 있다.** 이 메서드 말고 다른
     * 경로로 approved 가 되면 안 된다. 자동 승인을 만들지 마라.
     *
     * 점유(bs_workload) 적재는 **여기서 하지 않는다.** 과제 승인은
     * "이 과제를 해도 좋다" 이고, 점유는 사람이 합류해야 생긴다(P9-3).
     */
    public function approve(int $id, array $actor): void
    {
        $cur = $this->requireForDecision($id);

        $this->pdo->prepare(
            "UPDATE bs_project
                SET status = 'approved', approved_by = ?, approved_by_name = ?,
                    approved_at = NOW()
              WHERE id = ? AND project_type = 'rnd' AND status = 'proposed'"
        )->execute([$actor['id'] ?? null, $actor['name'] ?? null, $id]);
    }

    /**
     * 반려.
     *
     * 사유를 받는다. 반려만 하고 이유를 안 남기면 발의자가 무엇을 고쳐야
     * 하는지 모른다. 상태는 draft 로 되돌려 **고쳐서 다시 낼 수 있게** 한다.
     */
    public function reject(int $id, string $reason, array $actor): void
    {
        $cur = $this->requireForDecision($id);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('반려 사유를 입력하세요.');
        }

        $note = '[반려 ' . date('Y-m-d H:i') . ' ' . ($actor['name'] ?? '') . "]\n" . $reason;
        $this->pdo->prepare(
            "UPDATE bs_project
                SET status = 'draft',
                    notes = CONCAT_WS('\n\n', notes, ?),
                    approved_by = NULL, approved_by_name = NULL, approved_at = NULL
              WHERE id = ? AND project_type = 'rnd' AND status = 'proposed'"
        )->execute([$note, $id]);
    }

    // =================================================================
    // 안쪽
    // =================================================================

    /** 승인·반려 공통 전처리. 볼 수 있고, 권한이 있고, proposed 여야 한다. */
    private function requireForDecision(int $id): array
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('과제를 찾을 수 없습니다.');
        }
        if ((string)$cur['status'] !== 'proposed') {
            throw new DomainException('발의된 과제만 승인·반려할 수 있습니다. 지금 상태: '
                . (BS_RND_STATUS[$cur['status']] ?? $cur['status']));
        }
        if (!bs_can(BS_CAP_RND_APPROVE)) {
            throw new DomainException('과제를 승인·반려할 권한이 없습니다.');
        }
        return $cur;
    }

    /** 목록·건수 공통 WHERE. 가시성이 여기서 반드시 붙는다. */
    private function buildWhere(array $f): array
    {
        [$vis, $p] = bs_rnd_visible_sql('p');
        $w = ['p.deleted_at IS NULL', $vis];

        if (!empty($f['category']) && isset(BS_RND_CATEGORY[$f['category']])) {
            $w[] = 'p.rnd_category = ?';
            $p[] = $f['category'];
        }
        if (!empty($f['status'])) {
            $wanted = array_values(array_filter(
                (array)$f['status'],
                static fn($x) => is_string($x) && isset(BS_RND_STATUS[$x])
            ));
            if ($wanted) {
                $w[] = 'p.status IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
                foreach ($wanted as $x) { $p[] = $x; }
            }
        }
        if (!empty($f['recruiting'])) {
            $w[] = 'p.recruiting = 1';
        }
        if (!empty($f['keyword'])) {
            $kw  = '%' . $this->escapeLike((string)$f['keyword']) . '%';
            $w[] = "(p.code LIKE ? ESCAPE '!' OR p.name LIKE ? ESCAPE '!'
                     OR p.summary LIKE ? ESCAPE '!')";
            array_push($p, $kw, $kw, $kw);
        }
        if (!empty($f['mine'])) {
            $me  = bs_current_user();
            $mid = $this->memberIdOf((string)($me['id'] ?? ''));
            // 발의했거나, 참여 중이거나.
            $w[] = '(p.proposer_id = ? OR EXISTS (
                        SELECT 1 FROM bs_rnd_member m
                         WHERE m.project_id = p.id AND m.member_id = ?
                           AND m.status IN (\'requested\', \'approved\')))';
            $p[] = (string)($me['id'] ?? '');
            $p[] = $mid ?? 0;
        }

        return [implode(' AND ', $w), $p];
    }

    /** 이메일 → bs_member.id. 없으면 null. */
    private function memberIdOf(string $userId): ?int
    {
        if ($userId === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT id FROM bs_member WHERE user_id = ?');
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * 채번 — RND-2026-001.
     *
     * ProjectRepo::nextCode() 와 **접두사만 다르다.** 두 벌로 두는 것이
     * 마음에 걸리지만, 그쪽을 인자화하면 프로젝트 채번의 시그니처가
     * 바뀌어 기존 시험이 함께 흔들린다. 합칠지는 P9 가 끝난 뒤에 본다.
     */
    private function nextCode(int $year): string
    {
        $prefix = sprintf('RND-%04d-', $year);
        $max = (int)$this->scalar(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)), 0)
               FROM bs_project
              WHERE code LIKE ?",
            [strlen($prefix) + 1, $prefix . '%']
        );
        return $prefix . sprintf('%03d', $max + 1);
    }

    /** 입력 검증. $isNew 면 필수 항목까지 본다. */
    private function validate(array $d, bool $isNew): void
    {
        if ($isNew || array_key_exists('name', $d)) {
            $name = trim((string)($d['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('과제명을 입력하세요.');
            }
            if (mb_strlen($name) > 200) {
                throw new InvalidArgumentException('과제명이 너무 깁니다(200자 이내).');
            }
        }
        if (array_key_exists('rnd_category', $d) && $d['rnd_category'] !== null && $d['rnd_category'] !== '') {
            if (!isset(BS_RND_CATEGORY[$d['rnd_category']])) {
                throw new InvalidArgumentException('알 수 없는 과제 갈래입니다.');
            }
        }
        if (array_key_exists('visibility', $d) && $d['visibility'] !== null && $d['visibility'] !== '') {
            if (!isset(BS_RND_VISIBILITY[$d['visibility']])) {
                throw new InvalidArgumentException('알 수 없는 공개 범위입니다.');
            }
        }
        if (array_key_exists('load_cap', $d) && $d['load_cap'] !== null && $d['load_cap'] !== '') {
            $v = (float)$d['load_cap'];
            if ($v <= 0 || $v > 1) {
                throw new InvalidArgumentException('점유율은 0 보다 크고 1 이하여야 합니다.');
            }
        }
        $from = $d['dev_start'] ?? null;
        $to   = $d['dev_end'] ?? null;
        if ($from && $to && $from > $to) {
            throw new InvalidArgumentException('종료일이 시작일보다 빠릅니다.');
        }
    }

    private function nn(mixed $v): mixed
    {
        if ($v === null) { return null; }
        $v = is_string($v) ? trim($v) : $v;
        return $v === '' ? null : $v;
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}

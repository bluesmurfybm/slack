<?php
/** bs_task / bs_task_domain 접근 담당 DAO. WBS 트리와 태스크-분야 연결을 다룬다. */

declare(strict_types=1);

/**
 * 태스크(WBS) 저장소.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 이 클래스가 지키는 것 (CLAUDE.md human-in-the-loop)                │
 * │                                                                  │
 * │ 1. confirmed=0 인 태스크는 배정 대상이 될 수 없다.                 │
 * │    배정용 조회 경로는 confirmedForAllocation() **하나뿐**이고,     │
 * │    그 안에서 조건을 건다. 호출자가 필터를 잊을 수 없게 한다.       │
 * │                                                                  │
 * │ 2. confirmed 는 confirm() 으로만 바뀐다.                          │
 * │    saveTree()/update() 는 이 칸을 쓰지 않는다. 트리를 고치다가     │
 * │    확정이 딸려 올라가면 사람이 검토했다는 보장이 사라진다.         │
 * │                                                                  │
 * │ 3. wbs_no / depth / seq 는 서버가 정한다.                         │
 * │    화면이 보낸 값을 믿지 않는다. 번호는 위치에서 나오는 값이라     │
 * │    두 군데서 계산하면 반드시 어긋난다.                            │
 * └──────────────────────────────────────────────────────────────────┘
 */
final class TaskRepo
{
    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 조회
    // =================================================================

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_task WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 프로젝트의 전체 태스크를 평면 배열로.
     * 트리로 묶는 것은 toTree() 에서 한다.
     */
    public function allByProject(int $projectId): array
    {
        // ix_bs_task_project (project_id, depth, seq) 를 탄다.
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_task WHERE project_id = ? ORDER BY depth, seq, id'
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 배정 대상 태스크만.
     *
     * **이 필터를 우회하는 경로를 만들지 말 것.** 배정 엔진은 다른 조회
     * 메서드를 쓰면 안 된다. allByProject() 는 화면 편집용이다.
     */
    public function confirmedForAllocation(int $projectId): array
    {
        $ph = implode(',', array_fill(0, count(BS_TASK_NOT_ASSIGNABLE_STATUS), '?'));
        // ix_bs_task_confirm (project_id, confirmed, status)
        $st = $this->pdo->prepare(
            "SELECT * FROM bs_task
              WHERE project_id = ?
                AND confirmed = 1
                AND status NOT IN ($ph)
              ORDER BY depth, seq, id"
        );
        $st->execute(array_merge([$projectId], BS_TASK_NOT_ASSIGNABLE_STATUS));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 배정에 넣으려는 태스크들이 정말 배정 가능한지 확인한다.
     *
     * confirmedForAllocation() 은 "목록을 고를 때" 쓰는 것이고, 이쪽은
     * "이미 고른 것을 저장하기 직전" 에 쓴다. 목록을 뽑은 뒤 저장까지
     * 사이에 누가 확정을 풀 수 있기 때문에 둘 다 필요하다.
     *
     * @throws DomainException 하나라도 배정 대상이 아니면
     */
    public function assertAssignable(array $taskIds): void
    {
        $taskIds = $this->ids($taskIds);
        if (!$taskIds) {
            return;
        }
        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        $st = $this->pdo->prepare(
            "SELECT id, wbs_no, title, confirmed, status FROM bs_task WHERE id IN ($ph)"
        );
        $st->execute($taskIds);
        $rows  = $st->fetchAll(PDO::FETCH_ASSOC);
        $found = array_column($rows, 'id');

        $missing = array_diff($taskIds, array_map('intval', $found));
        if ($missing) {
            throw new DomainException('없는 태스크가 들어 있습니다: ' . implode(', ', $missing));
        }

        $bad = [];
        foreach ($rows as $r) {
            if ((int)$r['confirmed'] !== 1) {
                $bad[] = ($r['wbs_no'] ?: $r['id']) . ' ' . $r['title'] . ' (미확정)';
            } elseif (in_array($r['status'], BS_TASK_NOT_ASSIGNABLE_STATUS, true)) {
                $bad[] = ($r['wbs_no'] ?: $r['id']) . ' ' . $r['title']
                       . ' (' . (BS_TASK_STATUS[$r['status']] ?? $r['status']) . ')';
            }
        }
        if ($bad) {
            throw new DomainException(
                "배정할 수 없는 태스크가 있습니다. 먼저 확정하세요.\n · " . implode("\n · ", $bad)
            );
        }
    }

    /**
     * 평면 목록을 parent_id 기준 트리로 묶는다. DB 를 타지 않는 순수 변환.
     *
     * 각 노드에 `est_md_roll` 을 함께 담는다 — 하위가 있으면 하위 합계,
     * 없으면 자기 값이다. 화면마다 따로 더하면 숫자가 갈리므로 여기서
     * 한 번만 계산한다. 상위에도 자기 공수가 따로 적혀 있으면
     * `est_md_own_ignored` 로 알려 준다(합계에서 빠졌다는 뜻).
     *
     * @param array<int, array> $domainsByTask 있으면 각 노드에 domains 를 붙인다
     */
    public function toTree(array $flatRows, array $domainsByTask = []): array
    {
        $byParent = [];
        foreach ($flatRows as $r) {
            $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
        }
        foreach ($byParent as &$list) {
            usort($list, static fn($a, $b) => ((int)$a['seq'] <=> (int)$b['seq'])
                                           ?: ((int)$a['id'] <=> (int)$b['id']));
        }
        unset($list);

        // 고아 노드(상위가 이 목록에 없는 것)도 뿌리로 올린다. 그래야 화면에서
        // 사라지지 않는다 — 안 보이면 지울 수도 없다.
        $known = [];
        foreach ($flatRows as $r) {
            $known[(int)$r['id']] = true;
        }
        foreach ($byParent as $pid => $list) {
            if ($pid !== 0 && !isset($known[$pid])) {
                $byParent[0] = array_merge($byParent[0] ?? [], $list);
                unset($byParent[$pid]);
            }
        }

        $seen = [];
        $build = function (int $parentId) use (&$build, &$byParent, &$seen, $domainsByTask): array {
            $out = [];
            foreach ($byParent[$parentId] ?? [] as $r) {
                $id = (int)$r['id'];
                if (isset($seen[$id])) {
                    continue;   // DB 에 고리가 생긴 경우의 안전장치. 무한 재귀를 막는다.
                }
                $seen[$id] = true;

                $r['id']         = $id;
                $r['depth']      = (int)$r['depth'];
                $r['seq']        = (int)$r['seq'];
                $r['confirmed']  = (int)$r['confirmed'] === 1;
                $r['difficulty'] = $r['difficulty'] !== null ? (int)$r['difficulty'] : null;
                $r['est_md']     = $r['est_md'] !== null ? (float)$r['est_md'] : null;
                $r['domains']    = $domainsByTask[$id] ?? [];
                $r['children']   = $build($id);

                if ($r['children']) {
                    $sum = 0.0;
                    foreach ($r['children'] as $c) {
                        $sum += (float)$c['est_md_roll'];
                    }
                    $r['est_md_roll']        = round($sum, 2);
                    $r['est_md_own_ignored'] = $r['est_md'] !== null;
                } else {
                    $r['est_md_roll']        = $r['est_md'] ?? 0.0;
                    $r['est_md_own_ignored'] = false;
                }
                $out[] = $r;
            }
            return $out;
        };

        return $build(0);
    }

    /** 자식 태스크. */
    public function children(int $parentId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_task WHERE parent_id = ? ORDER BY seq, id'
        );
        $st->execute([$parentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 트리 저장의 낙관적 잠금표.
     *
     * saveTree() 는 트리를 **통째로 갈아 끼운다.** 두 사람이 같은 WBS 를
     * 열어 두고 각자 저장하면 나중에 저장한 쪽이 앞사람 작업을 조용히
     * 지운다. 화면은 성공했다고 말한다. 그래서 화면이 읽을 때의 표를
     * 들고 있다가 저장할 때 같이 보내게 하고, 달라졌으면 거절한다.
     */
    public function revision(int $projectId): string
    {
        // updated_at 에 기대지 않는다. DATETIME 은 1초 단위라, 저장과 확정이
        // 같은 초에 일어나면 표가 그대로여서 변화를 놓친다. 실제로 그렇게
        // 새는 것을 HTTP 시험에서 잡았다 — 낡은 표를 들고 저장했는데 통과했다.
        //
        // 그래서 **바뀔 수 있는 칸을 직접 다 섞는다.** 분야 연결도 저장 대상이라
        // 같이 센다. 태스크 수가 많아야 1000 이라 한 번 훑어도 부담이 없다.
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) n,
                    IFNULL(MAX(t.id), 0) x,
                    IFNULL(SUM(CRC32(CONCAT_WS('|',
                        t.id, IFNULL(t.parent_id, 0), t.depth, t.seq, IFNULL(t.wbs_no, ''),
                        t.title, IFNULL(t.est_md, ''), IFNULL(t.difficulty, ''),
                        IFNULL(t.plan_start, ''), IFNULL(t.plan_end, ''),
                        t.confirmed, t.status, t.progress_pct,
                        CRC32(IFNULL(t.description, ''))))), 0) s,
                    (SELECT IFNULL(SUM(CRC32(CONCAT_WS('|',
                                td.task_id, td.domain_id, td.weight))), 0)
                       FROM bs_task_domain td
                       JOIN bs_task t2 ON t2.id = td.task_id
                      WHERE t2.project_id = ?) d
               FROM bs_task t WHERE t.project_id = ?"
        );
        $st->execute([$projectId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'x' => 0, 's' => 0, 'd' => 0];
        return substr(sha1($r['n'] . '|' . $r['x'] . '|' . $r['s'] . '|' . $r['d']), 0, 16);
    }

    /** 태스크가 속한 프로젝트. 여러 개면 전부 같은 프로젝트여야 한다. */
    public function projectIdOf(array $taskIds): int
    {
        $taskIds = $this->ids($taskIds);
        if (!$taskIds) {
            throw new InvalidArgumentException('태스크를 지정하세요.');
        }
        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        $st = $this->pdo->prepare(
            "SELECT DISTINCT project_id FROM bs_task WHERE id IN ($ph)"
        );
        $st->execute($taskIds);
        $pids = $st->fetchAll(PDO::FETCH_COLUMN);

        if (!$pids) {
            throw new DomainException('태스크를 찾을 수 없습니다.');
        }
        if (count($pids) > 1) {
            // 권한은 프로젝트 단위로 본다. 섞여 들어오면 어느 쪽 기준으로
            // 판단할지 정할 수 없다 — 권한 우회의 흔한 통로다.
            throw new DomainException('서로 다른 프로젝트의 태스크를 한 번에 처리할 수 없습니다.');
        }
        return (int)$pids[0];
    }

    // =================================================================
    // 변경
    // =================================================================

    /**
     * WBS 트리를 통째로 저장한다.
     *
     * 넘어온 트리가 저장 후의 모습 전부다. 빠진 태스크는 지운다.
     *
     * 받는 노드 모양:
     *   { id?, title, description?, est_md?, difficulty?,
     *     plan_start?, plan_end?, domain_ids?: [], children?: [] }
     *
     * 다음 칸은 **받지 않는다** — 서버가 정하거나, 다른 경로로만 바뀐다.
     *   depth, seq, wbs_no  : 위치에서 나온다
     *   confirmed           : confirm() 전용
     *   status, progress_pct: 진행 관리(P6) 전용
     *
     * origin / source_id / source_ref 는 **새로 만드는 노드에서만** 받는다.
     * 어디서 왔는지는 만들어진 순간에 정해지는 것이라, 나중 편집으로
     * 바뀌면 근거 추적이 끊긴다. 기존 노드의 값은 그대로 둔다.
     *
     * @param  string|null $baseRev 화면이 읽을 때의 revision(). 다르면 거절.
     * @return array{saved:int,created:int,updated:int,deleted:int,revision:string}
     */
    public function saveTree(int $projectId, array $tree, array $actor, ?string $baseRev = null): array
    {
        // $actor 는 아직 쓰지 않는다. 변경 이력 표(누가 언제 무엇을 바꿨는지)를
        // 붙일 때 여기서 쓰려고 시그니처에 남겨 둔다 — 나중에 호출부를 전부
        // 고치지 않기 위해서다.

        if ($baseRev !== null && $baseRev !== '' && $baseRev !== $this->revision($projectId)) {
            throw new DomainException(
                '다른 사람이 이 WBS 를 먼저 저장했습니다. 화면을 새로 고쳐 확인한 뒤 다시 저장하세요.'
            );
        }

        $flat = [];
        $this->flatten($tree, null, 1, $flat);

        if (count($flat) > BS_TASK_MAX_PER_PROJECT) {
            throw new DomainException(
                '태스크가 너무 많습니다(' . count($flat) . '건). '
                . '한 프로젝트에 ' . BS_TASK_MAX_PER_PROJECT . '건까지만 넣을 수 있습니다. '
                . '엑셀을 통째로 붙여 넣은 것은 아닌지 확인해 주세요.'
            );
        }

        // 기존 것과 대조해 무엇이 지워지는지 먼저 확정한다.
        $existing = [];
        foreach ($this->allByProject($projectId) as $r) {
            $existing[(int)$r['id']] = $r;
        }
        $keep    = array_values(array_filter(array_column($flat, 'id')));
        $unknown = array_diff($keep, array_keys($existing));
        if ($unknown) {
            // 다른 프로젝트의 태스크 id 를 끼워 넣어 남의 것을 가져오는 것을 막는다.
            throw new DomainException(
                '이 프로젝트의 태스크가 아닌 항목이 들어 있습니다: ' . implode(', ', $unknown)
            );
        }
        $doomed = array_values(array_diff(array_keys($existing), $keep));
        $this->assertNotAllocated($doomed);

        // source_id 는 "이 태스크가 어느 문서에서 나왔는가" 다. 남의 프로젝트
        // 문서 번호를 적어 두면 근거를 눌렀을 때 볼 수 없는 문서로 간다.
        $srcIds = array_values(array_unique(array_filter(array_column($flat, 'source_id'))));
        if ($srcIds) {
            $ph = implode(',', array_fill(0, count($srcIds), '?'));
            $st = $this->pdo->prepare(
                "SELECT id FROM bs_project_source WHERE id IN ($ph) AND project_id = ?"
            );
            $st->execute(array_merge($srcIds, [$projectId]));
            $good = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $bad  = array_diff($srcIds, $good);
            if ($bad) {
                throw new DomainException(
                    '이 프로젝트의 문서가 아닌 출처가 들어 있습니다: ' . implode(', ', $bad));
            }
        }

        $created = 0;
        $updated = 0;

        $this->pdo->beginTransaction();
        try {
            // 1) 살아남는 노드를 위에서부터 넣고 고친다.
            //    자기참조 FK 라 부모의 실제 id 가 정해진 뒤에야 자식을 넣을 수 있다.
            //    flatten() 이 이미 부모 → 자식 순으로 늘어놓았다.
            $idMap = [];    // 임시 위치키 → 실제 id
            foreach ($flat as &$n) {
                $parentId = $n['parent_key'] !== null ? ($idMap[$n['parent_key']] ?? null) : null;

                if ($n['id'] !== null) {
                    $this->writeRow((int)$n['id'], $projectId, $parentId, $n);
                    $idMap[$n['key']] = (int)$n['id'];
                    $updated++;
                } else {
                    $idMap[$n['key']] = $this->insertRow($projectId, $parentId, $n);
                    $created++;
                }
                $this->replaceDomains($idMap[$n['key']], $n['domain_ids']);
            }
            unset($n);

            // 2) 남은 것을 지운다. 잎부터 — 자기참조 CASCADE 가 여러 단계를
            //    이어서 돌지 않는다(001_schema.sql 주석).
            $deleted = $this->deleteMany($doomed);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'saved'    => count($flat),
            'created'  => $created,
            'updated'  => $updated,
            'deleted'  => $deleted,
            'revision' => $this->revision($projectId),
        ];
    }

    public function create(int $projectId, array $data): int
    {
        $parentId = isset($data['parent_id']) && $data['parent_id'] !== null
            ? (int)$data['parent_id'] : null;

        $depth = 1;
        if ($parentId !== null) {
            $p = $this->find($parentId);
            if (!$p || (int)$p['project_id'] !== $projectId) {
                throw new DomainException('상위 태스크를 찾을 수 없습니다.');
            }
            $depth = (int)$p['depth'] + 1;
            if ($depth > BS_TASK_MAX_DEPTH) {
                throw new DomainException(
                    BS_TASK_MAX_DEPTH . '단계(대/중/소)까지만 만들 수 있습니다.');
            }
        }

        $n = $this->normalize($data, $depth);
        $id = $this->insertRow($projectId, $parentId, $n);
        $this->replaceDomains($id, $n['domain_ids']);
        $this->renumber($projectId);
        return $id;
    }

    /**
     * 태스크 한 건 수정. 넘어온 키만 바꾼다.
     *
     * confirmed / status / progress_pct / depth / seq / wbs_no 는 여기서
     * 바뀌지 않는다. 각각 전용 경로가 있다.
     */
    public function update(int $id, array $data): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('태스크를 찾을 수 없습니다.');
        }

        $set = [];
        $par = [];
        foreach (['title', 'description', 'est_md', 'difficulty',
                  'plan_start', 'plan_end', 'source_ref'] as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $set[] = "`$k` = ?";
            $par[] = match ($k) {
                'title'      => $this->title($data[$k]),
                'est_md'     => $this->estMd($data[$k]),
                'difficulty' => $this->difficulty($data[$k]),
                'plan_start', 'plan_end' => $this->date($data[$k], $k),
                default      => $this->nn($data[$k]),
            };
        }

        if ($set) {
            $par[] = $id;
            $st = $this->pdo->prepare('UPDATE bs_task SET ' . implode(', ', $set) . ' WHERE id = ?');
            $st->execute($par);
        }

        if (array_key_exists('domain_ids', $data)) {
            $this->replaceDomains($id, $this->domainIds($data['domain_ids']));
        }

        $this->assertPlanPeriod($this->find($id) ?? []);
    }

    // =================================================================
    // 난이도 판정 — 자동으로 매기는 좁은 길
    // =================================================================

    /**
     * 아직 난이도가 없는 태스크. 자동 판정이 이걸 돌린다.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 사람이 매긴 것은 건드리지 않는다                               │
     * │                                                              │
     * │ difficulty_by='human' 은 누가 보고 고친 값이다. 자동 판정이   │
     * │ 그걸 덮으면, 고쳐 놓은 것이 다음 실행에서 말없이 되돌아간다.  │
     * │ 한 번이라도 그러면 아무도 고치지 않는다.                      │
     * └──────────────────────────────────────────────────────────────┘
     *
     * 잎(자식 없는 태스크)만 본다. 상위 묶음은 일이 아니라 분류라서
     * 난이도를 매겨 봐야 쓸 데가 없고, 모델 호출만 늘린다.
     *
     * @param bool $redo 참이면 **이미 매긴 것도 다시** 매긴다(사람 것만 빼고)
     */
    public function pendingDifficulty(int $projectId, bool $redo = false): array
    {
        // ┌──────────────────────────────────────────────────────────┐
        // │ 난이도와 공수를 **따로** 본다                              │
        // │                                                          │
        // │ 한 태스크에서 난이도는 사람이 고쳤는데 공수는 비어 있을    │
        // │ 수 있다. 행 단위로 "사람이 손댔나" 를 보면 그 공수가 영영  │
        // │ 안 채워진다. 칸마다 따로 판정한다.                        │
        // └──────────────────────────────────────────────────────────┘
        $needDiff = $redo ? '1' : 't.difficulty IS NULL';
        $needEst  = $redo ? '1' : 't.est_md IS NULL';

        $sql = 'SELECT t.id, t.title, t.description, t.difficulty, t.difficulty_by,
                       t.est_md, t.est_md_by
                  FROM bs_task t
                 WHERE t.project_id = ?
                   AND NOT EXISTS (SELECT 1 FROM bs_task c WHERE c.parent_id = t.id)
                   AND ( (COALESCE(t.difficulty_by, "") <> "human" AND ' . $needDiff . ')
                      OR (COALESCE(t.est_md_by, "")    <> "human" AND ' . $needEst . ') )
                 ORDER BY t.seq, t.id';

        $st = $this->pdo->prepare($sql);
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 난이도별 실제 공수. 예상공수를 환산할 때 쓴다.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 사람이 세운 숫자만 센다                                       │
     * │                                                              │
     * │ 자동 판정이 넣은 공수까지 평균에 넣으면, 기본표에서 나온 값이  │
     * │ 다시 기본표를 떠받치는 **자기 참조**가 된다. 그러면 표가       │
     * │ 영원히 처음 값에 묶인다.                                      │
     * │                                                              │
     * │ est_md_by 가 NULL 인 줄은 019 이전에 들어온 것이라 사람이      │
     * │ 넣은 것으로 본다 — 그때는 자동 판정이 없었다.                 │
     * └──────────────────────────────────────────────────────────────┘
     *
     * @param int $minSamples 이보다 적으면 그 난이도는 돌려주지 않는다
     * @return array<int, array{md:float, n:int}>
     */
    public function effortTable(int $minSamples = 5): array
    {
        // ┌──────────────────────────────────────────────────────────────┐
        // │ AIDD 실적은 **되돌려서** 평균낸다 (2026-10-08)                │
        // │                                                              │
        // │ 이 표는 "AIDD 없이 얼마나 걸리는가" 여야 한다. 쓸 때 계수를   │
        // │ 곱하기 때문이다(estFromDifficulty). AIDD 프로젝트에서 사람이  │
        // │ 적은 공수는 이미 AIDD 가 반영된 값이라, 그대로 평균에 넣으면  │
        // │ 다음 프로젝트에서 **계수가 두 번 걸린다.**                    │
        // │                                                              │
        // │ 빼 버리지 않고 나눠 되돌린다 — 빼면 AIDD 를 쓰는 동안 표가    │
        // │ 영영 안 쌓여 기본표에 묶인다.                                 │
        // │                                                              │
        // │ aidd_enabled=0 이면 나누는 수가 1.00 이라 **이 변경 전과      │
        // │ 한 글자도 다르지 않다.** 시험이 그것을 지킨다.                │
        // └──────────────────────────────────────────────────────────────┘
        try {
            $st = $this->pdo->query(
                'SELECT t.difficulty, COUNT(*) n,
                        AVG(t.est_md / IF(p.aidd_enabled = 1,
                                          GREATEST(p.aidd_effort, 0.01), 1)) md
                   FROM bs_task t
                   JOIN bs_project p ON p.id = t.project_id
                  WHERE t.est_md IS NOT NULL AND t.est_md > 0 AND t.difficulty IS NOT NULL
                    AND COALESCE(t.est_md_by, "human") = "human"
                  GROUP BY t.difficulty'
            );
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int)$r['n'] >= $minSamples) {
                    $out[(int)$r['difficulty']] = ['md' => round((float)$r['md'], 1),
                                                   'n'  => (int)$r['n']];
                }
            }
            return $out;
        } catch (Throwable $e) {
            // 019 를 아직 안 올린 서버에서도 판정은 돌아야 한다.
            return [];
        }
    }

    /** 자동 판정이 매긴 예상공수를 적는다. **근거를 반드시 함께 적는다.** */
    public function setEstimate(int $id, float $md, string $by, string $note): void
    {
        $this->pdo->prepare(
            'UPDATE bs_task SET est_md = ?, est_md_by = ?, est_md_note = ? WHERE id = ?'
        )->execute([
            max(0.1, min((float)BS_TASK_MAX_EST_MD, $md)),
            in_array($by, ['human', 'rule', 'ai'], true) ? $by : 'rule',
            mb_substr($note, 0, 500),
            $id,
        ]);
    }

    /**
     * 자동 판정 결과를 적는다. **근거를 반드시 함께 적는다.**
     *
     * update() 를 쓰지 않고 따로 둔 이유: update() 는 사람이 화면에서 고치는
     * 길이라 difficulty_by 를 다루지 않는다. 거기에 끼워 넣으면 화면 수정이
     * 판정 출처를 'ai' 로 덮어쓸 수 있다.
     */
    public function setDifficulty(int $id, int $level, string $by, string $note): void
    {
        $this->pdo->prepare(
            'UPDATE bs_task SET difficulty = ?, difficulty_by = ?, difficulty_note = ?
              WHERE id = ?'
        )->execute([
            max(1, min(5, $level)),
            in_array($by, ['human', 'rule', 'ai'], true) ? $by : 'rule',
            mb_substr($note, 0, 500),
            $id,
        ]);
    }

    /**
     * 사람이 검토해 확정. 또는 확정 해제.
     *
     * **confirmed 를 건드리는 유일한 경로다.** 다른 메서드에서 이 칸을
     * 쓰지 말 것. 확정을 풀 때는 이미 배정에 들어간 태스크인지 본다 —
     * 배정된 일의 확정을 풀면 배정안이 근거를 잃는다.
     *
     * @return int 실제로 바뀐 건수
     */
    public function confirm(array $taskIds, bool $on, array $actor): int
    {
        $taskIds = $this->ids($taskIds);
        if (!$taskIds) {
            return 0;
        }
        if (!$on) {
            $this->assertNotAllocated($taskIds, '확정을 풀 수 없습니다');
        }

        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        $st = $this->pdo->prepare(
            "UPDATE bs_task SET confirmed = ? WHERE id IN ($ph) AND confirmed <> ?"
        );
        $st->execute(array_merge([$on ? 1 : 0], $taskIds, [$on ? 1 : 0]));
        return $st->rowCount();
    }

    /**
     * 태스크를 다른 부모 아래로 / 형제 사이 다른 자리로 옮긴다.
     *
     * 화면의 WBS 편집기는 전체 트리를 한 번에 저장하므로 보통 이 메서드를
     * 쓰지 않는다. 트리가 커서 한 건만 옮기고 싶을 때를 위한 경로다.
     * 번호 매김은 saveTree() 와 **같은 renumber() 를 쓴다** — 두 벌로
     * 만들면 어느 한쪽이 먼저 틀어진다.
     */
    public function move(int $id, ?int $newParentId, int $newSeq): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('태스크를 찾을 수 없습니다.');
        }
        $projectId = (int)$cur['project_id'];

        $depth = 1;
        if ($newParentId !== null) {
            if ($newParentId === $id) {
                throw new DomainException('자기 자신 아래로 옮길 수 없습니다.');
            }
            $p = $this->find($newParentId);
            if (!$p || (int)$p['project_id'] !== $projectId) {
                throw new DomainException('옮길 위치의 상위 태스크를 찾을 수 없습니다.');
            }
            if ($this->isDescendant($newParentId, $id)) {
                // 이걸 막지 않으면 트리에서 떨어져 나간 고리가 생긴다.
                // 화면에서는 아예 사라지고 DB 에는 남는다.
                throw new DomainException('자기 하위 태스크 아래로 옮길 수 없습니다.');
            }
            $depth = (int)$p['depth'] + 1;
        }

        $sub = $this->subtreeDepth($id);   // 자기 포함 하위까지의 높이
        if ($depth + $sub - 1 > BS_TASK_MAX_DEPTH) {
            throw new DomainException(
                '하위 태스크까지 옮기면 ' . BS_TASK_MAX_DEPTH . '단계를 넘습니다.');
        }

        $this->pdo->beginTransaction();
        try {
            // 끼어들 자리를 비운다. seq 는 renumber() 가 다시 촘촘하게 만든다.
            $st = $this->pdo->prepare(
                $newParentId === null
                    ? 'UPDATE bs_task SET seq = seq + 1
                        WHERE project_id = ? AND parent_id IS NULL AND seq >= ? AND id <> ?'
                    : 'UPDATE bs_task SET seq = seq + 1
                        WHERE project_id = ? AND parent_id = ? AND seq >= ? AND id <> ?'
            );
            $st->execute($newParentId === null
                ? [$projectId, $newSeq, $id]
                : [$projectId, $newParentId, $newSeq, $id]);

            $st = $this->pdo->prepare(
                'UPDATE bs_task SET parent_id = ?, depth = ?, seq = ? WHERE id = ?'
            );
            $st->execute([$newParentId, $depth, $newSeq, $id]);

            $this->shiftSubtreeDepth($id, $depth);
            $this->renumber($projectId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateProgress(int $id, string $status, int $progressPct): void
    {
        // TODO(P6): bs_progress 등록과 함께 불린다. 두 표가 어긋나지 않게 같은 트랜잭션 안에서.
        if (!isset(BS_TASK_STATUS[$status])) {
            throw new InvalidArgumentException('알 수 없는 상태입니다: ' . $status);
        }
        $st = $this->pdo->prepare(
            'UPDATE bs_task SET status = ?, progress_pct = ? WHERE id = ?'
        );
        $st->execute([$status, max(0, min(100, $progressPct)), $id]);
    }

    /** 태스크 한 건(과 그 하위 전부) 삭제. */
    public function delete(int $id): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('태스크를 찾을 수 없습니다.');
        }
        $ids = $this->subtreeIds($id);
        $this->assertNotAllocated($ids);

        $this->pdo->beginTransaction();
        try {
            $this->deleteMany($ids);
            $this->renumber((int)$cur['project_id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // =================================================================
    // 분야 연결 (bs_task_domain)
    // =================================================================

    /**
     * 쓰이고 있는 분야 전체. code 를 열쇠로.
     *
     * WBS 도출기가 쓴다. 거기서 bs_db() 를 직접 부르면 시험이 시험 DB 를
     * 못 보게 된다 — 실제로 그렇게 막혔다. DB 접근은 리포지토리에 모은다.
     *
     * @return array<string, array{id:int,name:string,category:string,keywords:string[]}>
     */
    public function activeDomains(): array
    {
        $st = $this->pdo->query(
            'SELECT id, code, name, category, keywords FROM bs_domain
              WHERE is_active = 1 ORDER BY sort_no'
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['code']] = [
                'id'       => (int)$r['id'],
                'name'     => $r['name'],
                'category' => $r['category'],
                'keywords' => array_values(array_filter(array_map(
                    'trim', explode(',', (string)($r['keywords'] ?? ''))
                ))),
            ];
        }
        return $out;
    }

    /** @return array 태스크에 걸린 분야 목록(weight 포함) */
    public function domains(int $taskId): array
    {
        $m = $this->domainsFor([$taskId]);
        return $m[$taskId] ?? [];
    }

    /**
     * 여러 태스크의 분야를 한 번에. 트리 응답이 N+1 질의를 내지 않도록.
     *
     * @return array<int, array>
     */
    public function domainsFor(array $taskIds): array
    {
        $taskIds = $this->ids($taskIds);
        if (!$taskIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        $st = $this->pdo->prepare(
            "SELECT td.task_id, td.domain_id, td.weight,
                    d.code, d.name, d.category
               FROM bs_task_domain td
               JOIN bs_domain d ON d.id = td.domain_id
              WHERE td.task_id IN ($ph)
              ORDER BY d.sort_no, d.id"
        );
        $st->execute($taskIds);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['task_id']][] = [
                'domain_id' => (int)$r['domain_id'],
                'code'      => $r['code'],
                'name'      => $r['name'],
                'category'  => $r['category'],
                'cat_label' => BS_DOMAIN_CATEGORY[$r['category']]['label'] ?? $r['category'],
                'weight'    => (float)$r['weight'],
            ];
        }
        return $out;
    }

    /**
     * 분야 연결을 통째로 교체한다.
     *
     * @param array $domainIds 분야 id 목록, 또는 [domain_id => weight]
     */
    public function replaceDomains(int $taskId, array $domainIds): void
    {
        // 가중치를 따로 주지 않으면 고른 분야끼리 균등하게 나눈다.
        // 한 태스크의 분야 가중치 합이 1 이 되게 두어야 나중에 계열 점수를
        // 섞을 때 태스크마다 총량이 달라지지 않는다.
        $weights = $this->domainIds($domainIds);
        $auto    = count($weights) > 0 ? round(1 / count($weights), 3) : 0.0;

        $this->pdo->prepare('DELETE FROM bs_task_domain WHERE task_id = ?')->execute([$taskId]);
        if (!$weights) {
            return;
        }
        $st = $this->pdo->prepare(
            'INSERT INTO bs_task_domain (task_id, domain_id, weight) VALUES (?, ?, ?)'
        );
        foreach ($weights as $did => $w) {
            $st->execute([$taskId, $did, max(0.0, min(1.0, $w ?? $auto))]);
        }
    }

    // =================================================================
    // 안쪽
    // =================================================================

    /**
     * 트리를 부모 → 자식 순 평면 목록으로 편다.
     * 동시에 depth/seq/wbs_no 를 **서버가 새로 매긴다.**
     */
    private function flatten(array $nodes, ?string $parentKey, int $depth, array &$out,
                             array &$wbsByKey = []): void
    {
        if ($depth > BS_TASK_MAX_DEPTH) {
            throw new DomainException(
                BS_TASK_MAX_DEPTH . '단계(대/중/소)까지만 만들 수 있습니다.');
        }
        $seq = 0;
        foreach ($nodes as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('태스크 형식이 올바르지 않습니다.');
            }
            $n = $this->normalize($raw, $depth);

            $key = ($parentKey === null ? '' : $parentKey . '.') . $seq;
            $n['key']        = $key;
            $n['parent_key'] = $parentKey;
            $n['seq']        = $seq;
            // wbs_no 는 1 부터 보이는 번호다. seq 는 0 부터인 내부 순서다.
            $n['wbs_no']     = ($parentKey === null ? '' : $wbsByKey[$parentKey] . '.')
                             . ($seq + 1);
            $wbsByKey[$key]  = $n['wbs_no'];
            $out[] = $n;

            $kids = $raw['children'] ?? [];
            if ($kids) {
                if (!is_array($kids)) {
                    throw new InvalidArgumentException('하위 태스크 형식이 올바르지 않습니다.');
                }
                $this->flatten($kids, $key, $depth + 1, $out, $wbsByKey);
            }
            $seq++;
        }
    }

    /** 들어온 노드 한 건을 DB 에 넣을 수 있는 모양으로 검증·정리한다. */
    private function normalize(array $raw, int $depth): array
    {
        $n = [
            'id'          => isset($raw['id']) && $raw['id'] !== null && $raw['id'] !== ''
                             ? (int)$raw['id'] : null,
            'depth'       => $depth,
            'title'       => $this->title($raw['title'] ?? ''),
            'description' => $this->nn($raw['description'] ?? null),
            'est_md'      => $this->estMd($raw['est_md'] ?? null),
            'difficulty'  => $this->difficulty($raw['difficulty'] ?? null),
            'plan_start'  => $this->date($raw['plan_start'] ?? null, 'plan_start'),
            'plan_end'    => $this->date($raw['plan_end'] ?? null, 'plan_end'),
            'source_id'   => isset($raw['source_id']) && $raw['source_id'] ? (int)$raw['source_id'] : null,
            'source_ref'  => $this->nn($raw['source_ref'] ?? null),
            // 'auto' 는 문서에서 뽑았다는 표시다. 거짓으로 적어도 권한이
            // 올라가지 않는다 — 오히려 덜 믿을 것으로 보이게 될 뿐이다.
            'origin'      => ($raw['origin'] ?? '') === 'auto' ? 'auto' : 'manual',
            'domain_ids'  => $this->domainIds($raw['domain_ids'] ?? []),
            'seq'         => 0,
            'wbs_no'      => null,
            'key'         => '',
            'parent_key'  => null,
        ];
        $this->assertPlanPeriod($n);
        return $n;
    }

    private function insertRow(int $projectId, ?int $parentId, array $n): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO bs_task
                (project_id, parent_id, depth, seq, wbs_no, title, description,
                 est_md, difficulty, plan_start, plan_end, source_id, source_ref, origin)
             VALUES (?,?,?,?,?,?,?, ?,?,?,?,?,?, ?)'
        );
        $st->execute([
            $projectId, $parentId, $n['depth'], $n['seq'], $n['wbs_no'],
            $n['title'], $n['description'],
            $n['est_md'], $n['difficulty'], $n['plan_start'], $n['plan_end'],
            $n['source_id'], $n['source_ref'],
            // 문서에서 뽑은 초안은 'auto', 사람이 손으로 만든 것은 'manual'.
            // confirmed 는 여기서 쓰지 않는다 — 스키마 기본값 0 으로 들어가고,
            // confirm() 만 1 로 올린다. 초안이든 수동이든 확정은 사람 몫이다.
            $n['origin'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function writeRow(int $id, int $projectId, ?int $parentId, array $n): void
    {
        // confirmed / status / progress_pct / origin 은 건드리지 않는다.
        $st = $this->pdo->prepare(
            'UPDATE bs_task
                SET parent_id = ?, depth = ?, seq = ?, wbs_no = ?,
                    title = ?, description = ?, est_md = ?, difficulty = ?,
                    plan_start = ?, plan_end = ?, source_ref = ?
              WHERE id = ? AND project_id = ?'
        );
        $st->execute([
            $parentId, $n['depth'], $n['seq'], $n['wbs_no'],
            $n['title'], $n['description'], $n['est_md'], $n['difficulty'],
            $n['plan_start'], $n['plan_end'], $n['source_ref'],
            $id, $projectId,
        ]);
    }

    /**
     * 잎부터 지워 올라간다.
     *
     * InnoDB 는 자기참조 FK 의 CASCADE 를 여러 단계로 이어서 수행하지
     * 않는다(001_schema.sql 주석). depth 내림차순으로 지우면 항상
     * 자식이 먼저 사라진다.
     */
    private function deleteMany(array $ids): int
    {
        $ids = $this->ids($ids);
        if (!$ids) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT id FROM bs_task WHERE id IN ($ph) ORDER BY depth DESC, id DESC"
        );
        $st->execute($ids);
        $ordered = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $del = $this->pdo->prepare('DELETE FROM bs_task WHERE id = ?');
        $n = 0;
        foreach ($ordered as $id) {
            $del->execute([$id]);
            $n += $del->rowCount();
        }
        return $n;
    }

    /**
     * 배정에 들어간 태스크인지 본다.
     *
     * 배정안에 올라간 일을 지우거나 확정을 풀면, 배정안은 살아 있는데
     * 근거가 사라진다. 그 상태를 화면에서 알아채기 어렵다.
     */
    private function assertNotAllocated(array $taskIds, string $what = '삭제할 수 없습니다'): void
    {
        $taskIds = $this->ids($taskIds);
        if (!$taskIds) {
            return;
        }
        $ph = implode(',', array_fill(0, count($taskIds), '?'));
        $st = $this->pdo->prepare(
            "SELECT t.wbs_no, t.title, COUNT(*) n
               FROM bs_allocation_item ai
               JOIN bs_task t ON t.id = ai.task_id
              WHERE ai.task_id IN ($ph)
              GROUP BY t.id, t.wbs_no, t.title
              ORDER BY t.wbs_no"
        );
        $st->execute($taskIds);
        $rows = bs_wbs_sort($st->fetchAll(PDO::FETCH_ASSOC));
        if (!$rows) {
            return;
        }
        $list = array_map(static fn($r) => ($r['wbs_no'] ?: '-') . ' ' . $r['title'], $rows);
        throw new DomainException(
            "배정안에 들어 있는 태스크라 $what:\n · " . implode("\n · ", $list)
            . "\n먼저 배정안에서 빼 주세요."
        );
    }

    /** 자기 포함 하위 전체의 id. depth 얕은 것부터. */
    private function subtreeIds(int $id): array
    {
        $out   = [$id];
        $level = [$id];
        for ($d = 0; $d < BS_TASK_MAX_DEPTH; $d++) {
            $ph = implode(',', array_fill(0, count($level), '?'));
            $st = $this->pdo->prepare("SELECT id FROM bs_task WHERE parent_id IN ($ph)");
            $st->execute($level);
            $level = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$level) {
                break;
            }
            $out = array_merge($out, $level);
        }
        return array_values(array_unique($out));
    }

    /** 자기 포함 하위 트리의 높이(자기만 있으면 1). */
    private function subtreeDepth(int $id): int
    {
        $cur = $this->find($id);
        if (!$cur) {
            return 1;
        }
        $ids = $this->subtreeIds($id);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $this->pdo->prepare("SELECT MAX(depth) FROM bs_task WHERE id IN ($ph)");
        $st->execute($ids);
        return max(1, (int)$st->fetchColumn() - (int)$cur['depth'] + 1);
    }

    private function isDescendant(int $maybeChild, int $ancestor): bool
    {
        return in_array($maybeChild, $this->subtreeIds($ancestor), true);
    }

    /** 옮긴 뒤 하위 노드들의 depth 를 새 기준에 맞춘다. */
    private function shiftSubtreeDepth(int $rootId, int $rootDepth): void
    {
        $st  = $this->pdo->prepare('UPDATE bs_task SET depth = ? WHERE id = ?');
        $walk = function (int $id, int $depth) use (&$walk, $st): void {
            $st->execute([$depth, $id]);
            foreach ($this->children($id) as $c) {
                $walk((int)$c['id'], $depth + 1);
            }
        };
        $walk($rootId, $rootDepth);
    }

    /**
     * seq 를 0,1,2… 로 촘촘하게 하고 wbs_no 를 다시 매긴다.
     * saveTree() 는 flatten() 에서 이미 매기므로 부르지 않는다.
     */
    private function renumber(int $projectId): void
    {
        $rows = $this->allByProject($projectId);
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
        }
        foreach ($byParent as &$list) {
            usort($list, static fn($a, $b) => ((int)$a['seq'] <=> (int)$b['seq'])
                                           ?: ((int)$a['id'] <=> (int)$b['id']));
        }
        unset($list);

        $st = $this->pdo->prepare('UPDATE bs_task SET seq = ?, wbs_no = ? WHERE id = ?');

        $walk = function (int $parentKey, string $prefix) use (&$walk, &$byParent, $st): void {
            foreach ($byParent[$parentKey] ?? [] as $i => $r) {
                $no = ($prefix === '' ? '' : $prefix . '.') . ($i + 1);
                $st->execute([$i, $no, (int)$r['id']]);
                $walk((int)$r['id'], $no);
            }
        };
        $walk(0, '');
    }

    // ---- 값 검증 ------------------------------------------------------

    private function title(mixed $v): string
    {
        $v = trim((string)$v);
        if ($v === '') {
            throw new InvalidArgumentException('태스크 제목을 입력하세요.');
        }
        return mb_substr($v, 0, 300);
    }

    private function estMd(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            throw new InvalidArgumentException('추정 공수는 숫자여야 합니다: ' . (string)$v);
        }
        $f = (float)$v;
        if ($f < 0) {
            throw new InvalidArgumentException('추정 공수는 0 이상이어야 합니다.');
        }
        if ($f > BS_TASK_MAX_EST_MD) {
            throw new InvalidArgumentException(
                '추정 공수가 너무 큽니다(' . $f . ' M/D). 태스크를 쪼개 주세요.');
        }
        return round($f, 2);
    }

    private function difficulty(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $i = (int)$v;
        if ($i < BS_TASK_DIFFICULTY_MIN || $i > BS_TASK_DIFFICULTY_MAX) {
            throw new InvalidArgumentException(
                '난이도는 ' . BS_TASK_DIFFICULTY_MIN . '~' . BS_TASK_DIFFICULTY_MAX
                . ' 사이여야 합니다: ' . (string)$v);
        }
        return $i;
    }

    private function date(mixed $v, string $field): ?string
    {
        $v = $this->nn($v);
        if ($v === null) {
            return null;
        }
        $v = (string)$v;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new InvalidArgumentException("날짜 형식이 올바르지 않습니다($field): $v");
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        if (!checkdate($m, $d, $y)) {
            throw new InvalidArgumentException("없는 날짜입니다($field): $v");
        }
        return $v;
    }

    private function assertPlanPeriod(array $n): void
    {
        $s = $n['plan_start'] ?? null;
        $e = $n['plan_end'] ?? null;
        if ($s !== null && $e !== null && $s > $e) {
            throw new InvalidArgumentException(
                '계획 시작일이 종료일보다 늦습니다: ' . $s . ' ~ ' . $e);
        }
    }

    /**
     * 분야 지정을 [분야id => 가중치|null] 로 고른다.
     *
     * 화면은 [3, 7] 처럼 목록으로 보내고, 가중치까지 정할 때는
     * {"3":0.7,"7":0.3} 처럼 보낸다. 둘 다 받는다. null 은 "균등하게 나눠라".
     *
     * @return array<int, float|null>
     */
    private function domainIds(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $isList = array_is_list($v);
        $out    = [];
        foreach ($v as $k => $x) {
            if ($isList) {
                $id = (int)$x;
                $w  = null;
            } else {
                $id = (int)$k;
                $w  = is_numeric($x) ? (float)$x : null;
            }
            if ($id > 0) {
                $out[$id] = $w;
            }
        }
        if (count($out) > BS_TASK_MAX_DOMAINS) {
            throw new InvalidArgumentException(
                '태스크 하나에 분야는 ' . BS_TASK_MAX_DOMAINS . '개까지만 붙일 수 있습니다.');
        }
        return $out;
    }

    /** @return int[] 양수 id 만, 중복 제거 */
    private function ids(array $v): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $v), static fn($x) => $x > 0)));
    }

    private function nn(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }
        return $v;
    }
}

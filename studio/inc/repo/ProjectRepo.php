<?php
/** bs_project / bs_project_source 접근 담당 DAO. 프로젝트와 개발범위 출처 문서를 읽고 쓴다. */

declare(strict_types=1);

/**
 * 프로젝트 저장소.
 *
 * 규칙(CLAUDE.md): SQL 문자열 결합 금지. 값은 전부 바인딩한다.
 * 목록 필터처럼 조건 개수가 달라지는 곳은 WHERE 조각만 배열로 모으고
 * 값은 따로 담아 넘긴다 — 조각에는 사용자 입력이 들어가지 않는다.
 *
 * 삭제는 소프트 삭제다(deleted_at). 특별히 말하지 않는 한 모든 조회는
 * 지워진 행을 빼고 본다.
 */
final class ProjectRepo
{
    /** 목록·상세에서 돌려주는 컬럼. parsed_text 처럼 큰 값은 여기 넣지 않는다. */
    private const COLS = 'id, code, name, summary, client, track,
        dev_start, dev_end, test_start, test_end, deploy_date,
        aidd_enabled, aidd_effort, aidd_load,
        notes, extra, status, owner_id, owner_name,
        deleted_at, deleted_by, deleted_by_name, delete_reason,
        created_at, updated_at';

    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 조회
    // =================================================================

    /**
     * 한 건. 없으면 null.
     * @param bool $withDeleted true 면 지워진 것도 찾는다.
     */
    public function find(int $id, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT ' . self::COLS . ' FROM bs_project WHERE id = ?';
        if (!$withDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $st = $this->pdo->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** 표시용 코드(PRJ-2026-001)로 찾는다. 코드는 지워진 프로젝트도 계속 점유한다. */
    public function findByCode(string $code, bool $withDeleted = true): ?array
    {
        $sql = 'SELECT ' . self::COLS . ' FROM bs_project WHERE code = ?';
        if (!$withDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $st = $this->pdo->prepare($sql);
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * 목록 + 총 건수.
     *
     * @param array $filter
     *   status       bs_project.status 하나
     *   track        bs_project.track 하나
     *   owner_id     담당 PM 이메일
     *   keyword      코드·이름·고객·개요에서 찾는다
     *   from, to     'YYYY-MM-DD'. 프로젝트 기간이 이 구간과 **겹치는** 것
     *   with_deleted 1 이면 지워진 것도 포함(관리자용)
     *   sort         recent(기본) | oldest | name | dev_start | status
     *   page, size
     * @return array{rows:array,total:int,page:int,size:int,pages:int}
     */
    public function search(array $filter): array
    {
        [$where, $params] = $this->buildWhere($filter);

        $page = max(1, (int)($filter['page'] ?? 1));
        $size = (int)($filter['size'] ?? 30);
        $size = max(1, min(100, $size));    // 상한을 두지 않으면 한 번에 전부 긁어갈 수 있다

        $total = (int)$this->scalar(
            'SELECT COUNT(*) FROM bs_project WHERE ' . $where,
            $params
        );

        // 마지막 페이지보다 큰 page 가 오면 빈 목록이 된다. 마지막으로 당겨 준다.
        $pages = $total > 0 ? (int)ceil($total / $size) : 1;
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $size;

        // ORDER BY 는 바인딩할 수 없으므로 화이트리스트로만 고른다.
        $order = match ((string)($filter['sort'] ?? 'recent')) {
            'oldest'    => 'p.id ASC',
            'name'      => 'p.name ASC, p.id DESC',
            'dev_start' => 'p.dev_start IS NULL, p.dev_start ASC, p.id DESC',
            // 대시보드 카드는 마감이 가까운 것부터 본다.
            'deploy_date' => 'COALESCE(p.deploy_date, p.dev_end) IS NULL,
                              COALESCE(p.deploy_date, p.dev_end) ASC, p.id DESC',
            'status'    => 'FIELD(p.status, ' . $this->statusOrderList() . '), p.dev_start IS NULL, p.dev_start ASC',
            default     => 'p.id DESC',
        };

        // 목록은 상세와 달리 notes/extra 를 싣지 않는다(표에 안 쓴다).
        // LIMIT/OFFSET 도 바인딩한다 — 정수로 좁혀 뒀지만 습관을 깨지 않는다.
        $sql = 'SELECT p.id, p.code, p.name, p.summary, p.client, p.track,
                       p.dev_start, p.dev_end, p.test_start, p.test_end, p.deploy_date,
                       p.status, p.owner_id, p.owner_name, p.deleted_at,
                       p.created_at, p.updated_at,
                       (SELECT COUNT(*) FROM bs_project_source s WHERE s.project_id = p.id) AS source_count
                  FROM bs_project p
                 WHERE ' . $this->prefixWhere($where) . '
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

    /** 목록 화면의 상태별 건수. 탭에 숫자를 달아 준다. */
    public function statusCounts(array $filter): array
    {
        // 상태 축만 빼고 같은 조건으로 센다 — 탭마다 제 건수가 나와야 한다.
        $f = $filter;
        unset($f['status']);
        [$where, $params] = $this->buildWhere($f);

        $st = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS cnt FROM bs_project WHERE ' . $where . ' GROUP BY status'
        );
        $st->execute($params);

        $out = ['' => 0];   // '' = 전체
        foreach (array_keys(BS_PROJECT_STATUS) as $code) {
            $out[$code] = 0;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['status']] = (int)$r['cnt'];
            $out[''] += (int)$r['cnt'];
        }
        return $out;
    }

    /**
     * 다음 프로젝트 코드. 예: PRJ-2026-001
     *
     * 지워진 프로젝트의 코드도 세어 건너뛴다. 이미 밖으로 나간 코드가
     * 다른 프로젝트를 가리키면 안 된다(BlueCart 채번과 같은 생각).
     */
    public function nextCode(int $year): string
    {
        $prefix = sprintf('PRJ-%04d-', $year);

        // code 는 'PRJ-2026-001' 꼴이라 뒤 세 자리만 숫자로 끊어 최대값을 본다.
        $max = (int)$this->scalar(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)), 0)
               FROM bs_project
              WHERE code LIKE ?",
            [strlen($prefix) + 1, $prefix . '%']
        );

        return $prefix . sprintf('%03d', $max + 1);
    }

    // =================================================================
    // 변경
    // =================================================================

    /**
     * 새 프로젝트. 만들어진 id 를 돌려준다.
     *
     * code 는 채번과 INSERT 사이에 다른 요청이 끼어들 수 있어, 충돌하면
     * 몇 번 다시 시도한다(uk_bs_project_code 가 잡아 준다).
     */
    public function create(array $data, array $actor): int
    {
        $this->assertPeriods($data);

        $year = $this->yearFor($data);

        for ($try = 0; $try < 5; $try++) {
            $code = $data['code'] ?? '';
            if ($code === '') {
                $code = $this->nextCode($year);
            }

            try {
                $st = $this->pdo->prepare(
                    'INSERT INTO bs_project
                        (code, name, summary, client, track,
                         dev_start, dev_end, test_start, test_end, deploy_date,
                         aidd_enabled, aidd_effort, aidd_load,
                         notes, extra, status, owner_id, owner_name)
                     VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,?,?,?)'
                );
                $st->execute([
                    $code,
                    $data['name'],
                    $this->nn($data['summary'] ?? null),
                    $this->nn($data['client'] ?? null),
                    $this->nn($data['track'] ?? null),
                    $this->nn($data['dev_start'] ?? null),
                    $this->nn($data['dev_end'] ?? null),
                    $this->nn($data['test_start'] ?? null),
                    $this->nn($data['test_end'] ?? null),
                    $this->nn($data['deploy_date'] ?? null),
                    // AIDD 는 **기본이 켬**이다. 안 주면 기본값으로 들어간다.
                    array_key_exists('aidd_enabled', $data)
                        ? (int)!empty($data['aidd_enabled']) : BS_AIDD_DEFAULT['enabled'],
                    $this->aiddFactor($data['aidd_effort'] ?? null, BS_AIDD_DEFAULT['effort']),
                    $this->aiddFactor($data['aidd_load']   ?? null, BS_AIDD_DEFAULT['load']),
                    $this->nn($data['notes'] ?? null),
                    $this->nn($data['extra'] ?? null),
                    $data['status'] ?? 'draft',
                    $actor['id'],
                    $actor['name'],
                ]);
                return (int)$this->pdo->lastInsertId();
            } catch (PDOException $e) {
                // 23000 = 무결성 위반. 코드를 사용자가 직접 준 경우면 다시 시도해도 같다.
                if ($e->getCode() !== '23000' || ($data['code'] ?? '') !== '') {
                    throw $e;
                }
                // 채번이 겹친 것이니 다시 뽑는다.
            }
        }

        throw new RuntimeException('프로젝트 코드를 채번하지 못했습니다. 다시 시도해 주세요.');
    }

    /**
     * 수정. 넘어온 키만 바꾼다.
     * code / owner_id / status 는 여기서 바꾸지 않는다(각각 전용 경로가 있다).
     */
    public function update(int $id, array $data): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('프로젝트를 찾을 수 없습니다.');
        }

        // 기간 검증은 "바뀐 뒤의 모습" 으로 해야 한다.
        // 한 칸만 고쳐도 나머지와 어긋날 수 있기 때문.
        $this->assertPeriods(array_merge($cur, $data));

        $allowed = ['name', 'summary', 'client', 'track',
                    'dev_start', 'dev_end', 'test_start', 'test_end', 'deploy_date',
                    'notes', 'extra'];

        $sets   = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]   = "`$col` = ?";           // 컬럼명은 위 화이트리스트에서만 온다
                $params[] = $this->nn($data[$col]);
            }
        }

        // AIDD 세 칸은 nn() 을 태우지 않는다 — 빈 문자열이 NULL 이 되면
        // NOT NULL 칸에 부딪히고, 0 으로 들어가면 공수가 전부 0 이 된다.
        if (array_key_exists('aidd_enabled', $data)) {
            $sets[]   = '`aidd_enabled` = ?';
            $params[] = (int)!empty($data['aidd_enabled']);
        }
        foreach (['aidd_effort' => BS_AIDD_DEFAULT['effort'],
                  'aidd_load'   => BS_AIDD_DEFAULT['load']] as $col => $def) {
            if (array_key_exists($col, $data)) {
                $sets[]   = "`$col` = ?";
                $params[] = $this->aiddFactor($data[$col], $def);
            }
        }
        if (!$sets) {
            return;     // 바꿀 게 없으면 updated_at 도 건드리지 않는다
        }

        $params[] = $id;
        $st = $this->pdo->prepare(
            'UPDATE bs_project SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL'
        );
        $st->execute($params);
    }

    /**
     * AIDD 계수를 받아들일 수 있는 값으로.
     *
     * 범위를 벗어난 값은 **거절하지 않고 자른다.** 프로젝트 저장이 계수 하나
     * 때문에 통째로 막히면 사람은 그 칸을 비우고 지나간다. 다만 0 쪽으로는
     * 바닥(BS_AIDD_MIN)이 있다 — 0.1 이면 공수가 10분의 1 이 되고, 그건
     * 옵션이 아니라 사고다.
     */
    private function aiddFactor(mixed $v, float $default): float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }
        return round(max(BS_AIDD_MIN, min(BS_AIDD_MAX, (float)$v)), 2);
    }

    /** 상태 전이. 허용된 값인지 확인한다. */
    public function updateStatus(int $id, string $status, array $actor): void
    {
        if (!isset(BS_PROJECT_STATUS[$status])) {
            throw new InvalidArgumentException('알 수 없는 상태입니다: ' . $status);
        }
        $st = $this->pdo->prepare(
            'UPDATE bs_project SET status = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $st->execute([$status, $id]);
    }

    /** 담당 PM 변경. */
    public function changeOwner(int $id, string $ownerId, string $ownerName): void
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_project SET owner_id = ?, owner_name = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $st->execute([$ownerId, $ownerName, $id]);
    }

    /**
     * 소프트 삭제.
     *
     * 행을 지우지 않는다. 진짜로 지우면 FK CASCADE 가 태스크·배정·진행기록까지
     * 끌고 가는데, 그것들은 "누가 무엇을 맡았는지" 의 근거라 사라지면 안 된다.
     */
    public function softDelete(int $id, array $actor, string $reason = ''): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('프로젝트를 찾을 수 없습니다.');
        }

        $st = $this->pdo->prepare(
            'UPDATE bs_project
                SET deleted_at = NOW(), deleted_by = ?, deleted_by_name = ?, delete_reason = ?
              WHERE id = ? AND deleted_at IS NULL'
        );
        $st->execute([$actor['id'], $actor['name'], $this->nn($reason), $id]);
    }

    /** 삭제 취소. */
    public function restore(int $id): void
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_project
                SET deleted_at = NULL, deleted_by = NULL, deleted_by_name = NULL, delete_reason = NULL
              WHERE id = ?'
        );
        $st->execute([$id]);
    }

    // =================================================================
    // 출처 문서 (bs_project_source)
    // =================================================================

    /** 해당 프로젝트의 출처 문서 목록. parsed_text 는 크니까 빼고 길이만 준다. */
    public function sources(int $projectId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, project_id, kind, title, url, file_size, mime,
                    parse_status, parse_error,
                    CHAR_LENGTH(COALESCE(parsed_text, "")) AS parsed_len,
                    uploaded_by, uploaded_by_name, created_at
               FROM bs_project_source
              WHERE project_id = ?
              ORDER BY id ASC'
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 파싱이 끝난 출처를 **본문까지** 가져온다. WBS 도출의 입력이다.
     *
     * sources() 와 나눠 둔다. 그쪽은 목록 화면용이라 MEDIUMTEXT 를 일부러
     * 빼고 길이만 준다. 목록을 그릴 때마다 본문을 끌고 오면 문서가 몇 건만
     * 돼도 응답이 수 MB 가 된다. 본문이 필요한 곳에서만 이쪽을 쓸 것.
     *
     * file_path 는 담지 않는다 — 응답에 실릴 일이 없게.
     */
    public function parsedSources(int $projectId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, project_id, kind, title, url, parse_status, parsed_text
               FROM bs_project_source
              WHERE project_id = ?
                AND parse_status = "ok"
                AND parsed_text IS NOT NULL
                AND parsed_text <> ""
              ORDER BY id ASC'
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 한 건. file_path 를 포함하므로 응답에 그대로 싣지 말 것. */
    public function findSource(int $sourceId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_project_source WHERE id = ?');
        $st->execute([$sourceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function countSources(int $projectId): int
    {
        return (int)$this->scalar(
            'SELECT COUNT(*) FROM bs_project_source WHERE project_id = ?',
            [$projectId]
        );
    }

    /**
     * 출처 문서 등록.
     *
     * 파일 저장 자체는 SourceUploader 가 하고, 여기는 행만 넣는다.
     * $data: kind, title, url, file_path, file_size, mime,
     *        parsed_text, parse_status
     */
    public function addSource(int $projectId, array $data, array $actor): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO bs_project_source
                (project_id, kind, title, url, file_path, file_size, mime,
                 parsed_text, parse_status, uploaded_by, uploaded_by_name)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $projectId,
            $data['kind'],
            $this->nn($data['title'] ?? null),
            $this->nn($data['url'] ?? null),
            $this->nn($data['file_path'] ?? null),
            isset($data['file_size']) ? (int)$data['file_size'] : null,
            $this->nn($data['mime'] ?? null),
            $this->nn($data['parsed_text'] ?? null),
            $data['parse_status'] ?? 'pending',
            $actor['id'],
            $actor['name'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** 파싱 결과 반영. P2 의 파서가 부른다. */
    public function updateSourceParse(int $sourceId, string $status, ?string $parsedText, ?string $error): void
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_project_source
                SET parse_status = ?, parsed_text = ?, parse_error = ?
              WHERE id = ?'
        );
        $st->execute([$status, $parsedText, $error !== null ? mb_substr($error, 0, 500) : null, $sourceId]);
    }

    /**
     * 출처 문서 삭제. 실제 파일도 지운다.
     * bs_task.source_id 는 FK SET NULL 이라 이미 도출된 태스크는 남는다.
     */
    public function deleteSource(int $sourceId): void
    {
        $row = $this->findSource($sourceId);
        if (!$row) {
            throw new DomainException('출처 문서를 찾을 수 없습니다.');
        }

        $st = $this->pdo->prepare('DELETE FROM bs_project_source WHERE id = ?');
        $st->execute([$sourceId]);

        // DB 를 먼저 지우고 파일을 지운다. 순서를 뒤집으면 파일만 사라지고
        // 행이 남아 "있다고 하는데 없는" 상태가 된다.
        if (!empty($row['file_path']) && is_file($row['file_path'])) {
            @unlink($row['file_path']);
        }
    }

    /** 파싱 대기 중인 출처 문서. P2 의 배치가 집어 간다. */
    public function pendingSources(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $st = $this->pdo->prepare(
            'SELECT id, project_id, kind, file_path, url, mime
               FROM bs_project_source
              WHERE parse_status = ?
              ORDER BY id ASC
              LIMIT ?'
        );
        $st->bindValue(1, 'pending');
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // =================================================================
    // 검증
    // =================================================================

    /**
     * 기간 역전 검증.
     *
     * 지키게 하는 것:
     *   · 각 구간은 시작 <= 끝
     *   · 테스트는 개발 시작보다 먼저 시작할 수 없다
     *   · 배포는 개발/테스트 시작보다 먼저일 수 없다
     *
     * 일부러 안 막는 것:
     *   · 개발과 테스트가 겹치는 것 (실제로 늘 겹친다)
     *   · 배포일이 테스트 종료보다 앞선 것 (부분 배포·핫픽스가 있다)
     *
     * 값이 비어 있으면(아직 안 정했으면) 그 쌍은 검사하지 않는다.
     */
    public function assertPeriods(array $d): void
    {
        $devS    = $this->nn($d['dev_start']   ?? null);
        $devE    = $this->nn($d['dev_end']     ?? null);
        $testS   = $this->nn($d['test_start']  ?? null);
        $testE   = $this->nn($d['test_end']    ?? null);
        $deploy  = $this->nn($d['deploy_date'] ?? null);

        foreach ([['개발', $devS, $devE], ['테스트', $testS, $testE]] as [$label, $start, $end]) {
            if ($start && $end && $start > $end) {
                throw new InvalidArgumentException(
                    $label . ' 기간이 뒤집혔습니다. 시작일이 종료일보다 늦습니다.'
                );
            }
        }

        if ($devS && $testS && $testS < $devS) {
            throw new InvalidArgumentException('테스트 시작일이 개발 시작일보다 빠릅니다.');
        }
        if ($devS && $deploy && $deploy < $devS) {
            throw new InvalidArgumentException('배포일이 개발 시작일보다 빠릅니다.');
        }
        if ($testS && $deploy && $deploy < $testS) {
            throw new InvalidArgumentException('배포일이 테스트 시작일보다 빠릅니다.');
        }
    }

    // =================================================================
    // 내부
    // =================================================================

    /**
     * 목록/집계 공통 WHERE.
     * @return array{0:string,1:array} [WHERE 문자열, 바인딩 값]
     */
    private function buildWhere(array $f): array
    {
        $w = [];
        $p = [];

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 유형을 안 주면 **지시형 프로젝트만** 본다                     │
        // │                                                              │
        // │ P9 에서 bs_project 에 R&D 과제(project_type='rnd')가 함께     │
        // │ 들어왔다. 필터가 없으면 R&D 과제가 프로젝트 목록과 배정       │
        // │ 대상에 섞인다.                                               │
        // │                                                              │
        // │ 기본을 'project' 로 두는 이유 — 부르는 쪽이 빠뜨렸을 때       │
        // │ **안 보이는 쪽으로 틀리게** 하기 위해서다. 반대로 두면        │
        // │ 빠뜨린 화면마다 R&D 가 조용히 섞여 들어오고, 그건 아무도      │
        // │ 눈치채지 못한다.                                             │
        // │                                                              │
        // │ R&D 보드는 'rnd' 를, 둘 다 볼 화면은 'all' 을 명시한다.      │
        // └──────────────────────────────────────────────────────────────┘
        $type = (string)($f['project_type'] ?? 'project');
        if ($type !== 'all') {
            $w[] = 'project_type = ?';
            $p[] = $type;
        }

        if (empty($f['with_deleted'])) {
            $w[] = 'deleted_at IS NULL';
        }

        // status 는 하나만 오기도 하고 여러 개가 오기도 한다(대시보드는
        // '진행 중' 으로 볼 상태를 묶어서 본다). 배열을 그대로 isset() 에
        // 넣으면 조용히 false 가 되어 **필터가 통째로 사라진다** — 전건이
        // 나오는데 아무도 모른다. 그래서 형을 나눠 다룬다.
        if (!empty($f['status'])) {
            $wanted = array_values(array_filter(
                (array)$f['status'],
                static fn($x) => is_string($x) && isset(BS_PROJECT_STATUS[$x])
            ));
            if ($wanted) {
                $w[] = 'status IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
                foreach ($wanted as $x) { $p[] = $x; }
            }
        }
        if (!empty($f['track'])) {
            $w[] = 'track = ?';
            $p[] = $f['track'];
        }
        if (!empty($f['owner_id'])) {
            $w[] = 'owner_id = ?';
            $p[] = $f['owner_id'];
        }
        if (!empty($f['keyword'])) {
            // LIKE 특수문자를 그대로 두면 %만 넣어 전건 조회가 된다. 이스케이프한다.
            $kw  = '%' . $this->escapeLike((string)$f['keyword']) . '%';
            $w[] = "(code LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!'
                     OR client LIKE ? ESCAPE '!' OR summary LIKE ? ESCAPE '!')";
            array_push($p, $kw, $kw, $kw, $kw);
        }

        // 기간 필터 — 프로젝트 기간이 주어진 구간과 겹치는 것.
        // 프로젝트 기간은 dev_start ~ COALESCE(deploy_date, test_end, dev_end) 로 본다.
        if (!empty($f['from'])) {
            $w[] = 'COALESCE(deploy_date, test_end, dev_end, dev_start) >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'COALESCE(dev_start, test_start, deploy_date) <= ?';
            $p[] = $f['to'];
        }

        return [$w ? implode(' AND ', $w) : '1', $p];
    }

    /** buildWhere 가 만든 조각의 컬럼에 p. 별칭을 붙인다(목록 쿼리는 조인이 있다). */
    private function prefixWhere(string $where): string
    {
        return preg_replace(
            '/\b(deleted_at|project_type|status|track|owner_id|code|name|client|summary|dev_start|dev_end|test_start|test_end|deploy_date)\b/',
            'p.$1',
            $where
        ) ?? $where;
    }

    /** 상태 탭 순서 — 손이 필요한 것이 위로 오게. */
    private function statusOrderList(): string
    {
        // FIELD() 인자는 상수 목록이라 바인딩 대상이 아니다. 값은 코드가 정한 것뿐이다.
        $order = ['allocating', 'scoping', 'draft', 'confirmed', 'running', 'hold', 'done'];
        return "'" . implode("','", $order) . "'";
    }

    /** 채번에 쓸 연도. 개발 시작일이 있으면 그 해, 없으면 올해. */
    private function yearFor(array $d): int
    {
        $s = $this->nn($d['dev_start'] ?? null);
        return $s !== null ? (int)substr($s, 0, 4) : (int)date('Y');
    }

    /** 빈 문자열을 NULL 로. 날짜 칸을 비워 보내면 ''가 오는데 DATE 컬럼에 못 넣는다. */
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

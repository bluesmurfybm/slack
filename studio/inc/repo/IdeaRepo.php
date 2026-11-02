<?php
/**
 * 아이디어·제안 저장소 (032).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 평가는 아이디어에 매기는 것이다 (CLAUDE.md §1)                    │
 * │                                                                  │
 * │ 집계는 언제나 **아이디어 단위**다. 제안자별 평균·건수 순위를 내는 │
 * │ 메서드를 이 클래스에 더하지 말 것 — 그 순간 사람 줄 세우기가 된다.│
 * │ 두 축 평균을 합친 값도 저장하지 않는다. 정렬할 때 그때 계산한다.  │
 * │                                                                  │
 * │ 개별 점수는 본인 것만 돌려준다. 평가 인원이 BS_IDEA_MIN_RATERS     │
 * │ 미만이면 평균도 null — 한두 명이면 평균이 곧 그 사람 점수다.      │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 권한 판정은 presenter.php 의 bs_idea_can_*() 를 쓴다. 쓰기 메서드가 한 번
 * 더 묻는다 — API 를 거치지 않고 불려도 뚫리지 않게(RndRepo 와 같은 방식).
 */

declare(strict_types=1);

require_once __DIR__ . '/../presenter.php';

final class IdeaRepo
{
    private const COLS = 'i.id, i.code, i.title, i.problem, i.proposal, i.effect, i.url,
        i.rnd_category, i.author_id, i.author_name, i.is_anonymous,
        i.status, i.review_note, i.reviewed_by, i.reviewed_by_name, i.reviewed_at,
        i.promoted_project_id, i.promoted_by, i.promoted_by_name, i.promoted_at,
        i.duplicate_of, i.deleted_at, i.created_at, i.updated_at';

    /** 목록이 함께 싣는 집계. 아이디어 한 줄마다 따로 세지만 쪽당 30줄이라 문제되지 않는다. */
    private const STATS = '
        (SELECT COUNT(*) FROM bs_idea_rating r WHERE r.idea_id = i.id) AS rating_n,
        (SELECT AVG(r.value_score) FROM bs_idea_rating r WHERE r.idea_id = i.id) AS value_avg,
        (SELECT AVG(r.feasibility_score) FROM bs_idea_rating r WHERE r.idea_id = i.id) AS feas_avg,
        (SELECT COUNT(*) FROM bs_idea_comment c
          WHERE c.idea_id = i.id AND c.kind = \'comment\' AND c.deleted_at IS NULL) AS comment_n';

    public const SORTS = [
        'new'      => '최신 순',
        'score'    => '평가 높은 순',
        'feedback' => '피드백 많은 순',
        'pending'  => '검토 기다리는 순',
    ];

    public function __construct(private PDO $pdo) {}

    // =================================================================
    // 읽기
    // =================================================================

    /** 원본 행. 지운 것도 돌려준다 — 판정은 부르는 쪽이 한다. */
    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM bs_idea i WHERE i.id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 보드.
     *
     * @param array{q?:string,status?:string,category?:string,mine?:bool,
     *              with_deleted?:bool,sort?:string} $f
     * @return array{rows:array,total:int,counts:array<string,int>}
     */
    public function board(array $f, int $limit = 30, int $offset = 0): array
    {
        $me = bs_current_user();
        [$where, $par] = $this->boardWhere($f, $me);

        // 상태 칩의 숫자는 상태 조건만 빼고 센다 — 칩을 눌러 보기 전에 몇 건인지 보이게.
        [$whereNoStatus, $parNoStatus] = $this->boardWhere(['status' => ''] + $f, $me);
        $st = $this->pdo->prepare(
            "SELECT i.status, COUNT(*) n FROM bs_idea i WHERE $whereNoStatus GROUP BY i.status");
        $st->execute($parNoStatus);
        $counts = array_fill_keys(array_keys(BS_IDEA_STATUS), 0);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[$r['status']] = (int)$r['n'];
        }

        $st = $this->pdo->prepare("SELECT COUNT(*) FROM bs_idea i WHERE $where");
        $st->execute($par);
        $total = (int)$st->fetchColumn();

        $min = BS_IDEA_MIN_RATERS;
        $order = match ($f['sort'] ?? 'new') {
            // 평가 인원이 모자란 것은 뒤로. 한 명이 5점 준 아이디어가 맨 위에 서지 않게.
            'score'    => "(rating_n >= $min) DESC, (COALESCE(value_avg,0) + COALESCE(feas_avg,0)) DESC, i.id DESC",
            'feedback' => 'comment_n DESC, i.id DESC',
            // 아직 아무도 보지 않은 것부터, 오래된 것부터.
            'pending'  => "FIELD(i.status, 'open', 'reviewing') = 0, FIELD(i.status, 'open', 'reviewing'), i.created_at ASC",
            default    => 'i.id DESC',
        };

        $st = $this->pdo->prepare(
            'SELECT ' . self::COLS . ', ' . self::STATS . "
               FROM bs_idea i
              WHERE $where
              ORDER BY $order
              LIMIT " . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset));
        $st->execute($par);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $doms = $this->domainsOf(array_map(static fn($r) => (int)$r['id'], $rows));
        $out  = [];
        foreach ($rows as $r) {
            $p = bs_present_idea($r, false);
            $p['domains'] = $doms[(int)$r['id']] ?? [];
            $p['rating']  = $this->summaryFromRow($r);
            $p['comment_count'] = (int)$r['comment_n'];
            $out[] = $p;
        }
        return ['rows' => $out, 'total' => $total, 'counts' => $counts];
    }

    /** 상세 한 건 — 본문·분야·평가 요약·묶음 관계까지. 못 찾으면 null. */
    public function get(int $id): ?array
    {
        $r = $this->find($id);
        if (!$r || (bs_idea_is_deleted($r) && !bs_is_admin() && !bs_idea_is_author($r))) {
            return null;
        }
        $p = bs_present_idea($r, true);
        $p['domains'] = $this->domainsOf([$id])[$id] ?? [];
        $p['rating']  = $this->ratingSummary($id);

        // 묶은 대상과 이쪽으로 묶여 들어온 것들.
        $p['duplicate_target'] = null;
        if ($r['duplicate_of'] !== null) {
            $t = $this->find((int)$r['duplicate_of']);
            if ($t && !bs_idea_is_deleted($t)) {
                $p['duplicate_target'] = ['id' => (int)$t['id'], 'code' => $t['code'], 'title' => $t['title']];
            }
        }
        $st = $this->pdo->prepare(
            'SELECT id, code, title FROM bs_idea WHERE duplicate_of = ? AND deleted_at IS NULL ORDER BY id');
        $st->execute([$id]);
        $p['merged'] = array_map(static fn($x) => ['id' => (int)$x['id'], 'code' => $x['code'], 'title' => $x['title']],
                                 $st->fetchAll(PDO::FETCH_ASSOC));

        $p['promoted'] = $r['promoted_project_id'] !== null
            ? $this->projectBrief((int)$r['promoted_project_id']) : null;
        return $p;
    }

    /**
     * 넘어간 과제·프로젝트의 요약. 과제를 거쳐 프로젝트까지 갔으면 그 프로젝트도.
     * 과제의 공개 범위는 지킨다 — 비공개 과제면 이름 대신 "비공개 과제" 만.
     */
    public function projectBrief(int $projectId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, code, name, project_type, status, visibility, proposer_id, deleted_at
               FROM bs_project WHERE id = ?');
        $st->execute([$projectId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            return null;
        }
        $isRnd = $p['project_type'] === 'rnd';
        $me    = bs_current_user();
        $hidden = $isRnd && $p['visibility'] === 'private' && !bs_is_admin()
                  && (string)$p['proposer_id'] !== (string)($me['id'] ?? '');
        $out = [
            'id'           => (int)$p['id'],
            'type'         => $p['project_type'],
            'type_label'   => $isRnd ? 'R&D 과제' : '프로젝트',
            'code'         => $p['code'],
            'name'         => $hidden ? '비공개 과제' : $p['name'],
            'status_label' => ($isRnd ? BS_RND_STATUS : BS_PROJECT_STATUS)[$p['status']] ?? $p['status'],
            'url'          => $hidden || $p['deleted_at'] ? null
                : ($isRnd ? 'rnd_view.php?id=' : 'project_view.php?id=') . (int)$p['id'],
            'deleted'      => $p['deleted_at'] !== null,
            'next'         => null,
        ];
        if ($isRnd) {
            $st = $this->pdo->prepare(
                "SELECT id FROM bs_project WHERE origin_project_id = ? AND project_type = 'project'
                    AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
            $st->execute([$projectId]);
            $next = $st->fetchColumn();
            if ($next) {
                $out['next'] = $this->projectBrief((int)$next);
            }
        }
        return $out;
    }

    /** 과제·프로젝트 화면의 "출발 아이디어". 지운 아이디어는 알리지 않는다. */
    public function originOf(int $projectId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT i.id, i.code, i.title FROM bs_project p
               JOIN bs_idea i ON i.id = p.origin_idea_id AND i.deleted_at IS NULL
              WHERE p.id = ?');
        $st->execute([$projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int)$r['id'], 'code' => $r['code'], 'title' => $r['title'],
                     'url' => 'idea_view.php?id=' . (int)$r['id']] : null;
    }

    /**
     * 비슷한 제목의 아이디어. 쓰는 중에 "이미 올라온 게 있다" 를 보여 줘
     * 같은 생각이 두 번 흩어지지 않게 한다. 두 글자 이상 낱말 아무거나 겹치면.
     */
    public function similar(string $title, int $excludeId = 0, int $limit = 5): array
    {
        $words = array_values(array_unique(array_filter(
            preg_split('/[\s,.\/·()\[\]]+/u', mb_strtolower(trim($title))) ?: [],
            static fn($w) => mb_strlen($w) >= 2)));
        if (!$words) {
            return [];
        }
        $words = array_slice($words, 0, 6);
        $like  = implode(' OR ', array_fill(0, count($words), "LOWER(i.title) LIKE ? ESCAPE '!'"));
        $par   = array_map(fn($w) => '%' . $this->escapeLike($w) . '%', $words);
        $par[] = $excludeId;
        $st = $this->pdo->prepare(
            "SELECT i.id, i.code, i.title, i.status FROM bs_idea i
              WHERE ($like) AND i.id <> ? AND i.deleted_at IS NULL
              ORDER BY i.id DESC LIMIT " . max(1, min(10, $limit)));
        $st->execute($par);
        return array_map(static fn($r) => [
            'id' => (int)$r['id'], 'code' => $r['code'], 'title' => $r['title'],
            'status_label' => BS_IDEA_STATUS[$r['status']] ?? $r['status'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, list<array{id:int,name:string}>> */
    public function domainsOf(array $ideaIds): array
    {
        $ideaIds = array_values(array_unique(array_filter(array_map('intval', $ideaIds))));
        if (!$ideaIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ideaIds), '?'));
        $st = $this->pdo->prepare(
            "SELECT x.idea_id, d.id, d.name FROM bs_idea_domain x
               JOIN bs_domain d ON d.id = x.domain_id
              WHERE x.idea_id IN ($ph) ORDER BY d.name");
        $st->execute($ideaIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['idea_id']][] = ['id' => (int)$r['id'], 'name' => $r['name']];
        }
        return $out;
    }

    // =================================================================
    // 쓰기 — 본문
    // =================================================================

    public function create(array $data, array $actor): int
    {
        $d = $this->clean($data, true);
        $year = (int)date('Y');

        for ($try = 0; $try < 5; $try++) {
            try {
                $this->pdo->beginTransaction();
                $st = $this->pdo->prepare(
                    'INSERT INTO bs_idea
                        (code, title, problem, proposal, effect, url, rnd_category,
                         author_id, author_name, is_anonymous)
                     VALUES (?,?,?,?,?,?,?,?,?,?)');
                $st->execute([
                    $this->nextCode($year), $d['title'], $d['problem'], $d['proposal'],
                    $d['effect'], $d['url'], $d['rnd_category'],
                    (string)$actor['id'], $actor['name'] ?? null, $d['is_anonymous'],
                ]);
                $id = (int)$this->pdo->lastInsertId();
                $this->saveDomains($id, $d['domain_ids']);
                $this->pdo->commit();
                return $id;
            } catch (PDOException $e) {
                $this->pdo->rollBack();
                // 채번이 겹치면 다시 뽑는다. 분야 FK 위반도 23000 이라 그대로 두면
                // 다섯 번 돌고 엉뚱한 말을 한다 — 분야는 saveDomains 가 먼저 거른다.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('아이디어 번호를 매기지 못했습니다. 다시 시도해 주세요.');
    }

    /** 넘어온 키만 바꾼다. 상태는 여기서 바꾸지 않는다 — review()/markPromoted() 가 따로 있다. */
    public function update(int $id, array $data): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('아이디어를 찾을 수 없습니다.');
        }
        if (!bs_idea_can_edit($cur)) {
            throw new DomainException('이 아이디어는 고칠 수 없습니다. 채택·반려되었거나 과제로 넘어간 뒤에는 잠깁니다.');
        }
        $d = $this->clean($data, false);

        $set = [];
        $par = [];
        foreach (['title', 'problem', 'proposal', 'effect', 'url', 'rnd_category', 'is_anonymous'] as $k) {
            if (array_key_exists($k, $d)) {
                $set[] = "$k = ?";
                $par[] = $d[$k];
            }
        }
        $this->pdo->beginTransaction();
        try {
            if ($set) {
                $par[] = $id;
                $this->pdo->prepare('UPDATE bs_idea SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
            }
            if (array_key_exists('domain_ids', $d)) {
                $this->saveDomains($id, $d['domain_ids']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function softDelete(int $id, array $actor): void
    {
        $cur = $this->find($id);
        if (!$cur) {
            throw new DomainException('아이디어를 찾을 수 없습니다.');
        }
        if (!bs_idea_can_delete($cur)) {
            throw new DomainException($cur['status'] === 'promoted'
                ? '과제·프로젝트로 넘어간 아이디어는 출발점이라 지울 수 없습니다.'
                : '지울 수 없는 아이디어입니다.');
        }
        $this->pdo->prepare(
            "UPDATE bs_idea SET deleted_at = NOW(), deleted_by = ?, deleted_by_name = ?
              WHERE id = ? AND deleted_at IS NULL AND status <> 'promoted'"
        )->execute([(string)$actor['id'], $actor['name'] ?? null, $id]);
    }

    /** 분야 태그를 통째로 바꾼다. 없는 분야 id 는 조용히 버린다. */
    public function saveDomains(int $id, array $domainIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $domainIds))));
        $this->pdo->prepare('DELETE FROM bs_idea_domain WHERE idea_id = ?')->execute([$id]);
        if (!$ids) {
            return;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare(
            "INSERT INTO bs_idea_domain (idea_id, domain_id)
             SELECT ?, d.id FROM bs_domain d WHERE d.id IN ($ph)"
        )->execute(array_merge([$id], $ids));
    }

    // =================================================================
    // 평가
    // =================================================================

    /** 평가를 남기거나 고친다. 한 사람 한 건. */
    public function rate(int $id, array $actor, int $value, int $feasibility, ?string $opinion): void
    {
        $cur = $this->find($id);
        if (!$cur || bs_idea_is_deleted($cur)) {
            throw new DomainException('아이디어를 찾을 수 없습니다.');
        }
        if ((string)$cur['author_id'] === (string)$actor['id']) {
            throw new DomainException('자기 아이디어는 평가하지 않습니다.');
        }
        if (!bs_idea_can_rate($cur)) {
            throw new DomainException('지금은 평가할 수 없는 아이디어입니다('
                . (BS_IDEA_STATUS[$cur['status']] ?? $cur['status'])
                . ($cur['duplicate_of'] ? ' · 다른 아이디어로 묶임' : '') . ').');
        }
        foreach (['가치' => $value, '실현 가능성' => $feasibility] as $label => $v) {
            if ($v < 1 || $v > 5) {
                throw new InvalidArgumentException("$label 은 1~5 점으로 매겨 주세요.");
            }
        }
        $opinion = $this->nn($opinion);
        if ($opinion !== null && mb_strlen($opinion) > 300) {
            throw new InvalidArgumentException('한줄 의견은 300자 이내로 적어 주세요.');
        }
        $this->pdo->prepare(
            'INSERT INTO bs_idea_rating (idea_id, user_id, user_name, value_score, feasibility_score, opinion)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE user_name = VALUES(user_name), value_score = VALUES(value_score),
                 feasibility_score = VALUES(feasibility_score), opinion = VALUES(opinion)'
        )->execute([$id, (string)$actor['id'], $actor['name'] ?? null, $value, $feasibility, $opinion]);
    }

    public function unrate(int $id, string $userId): void
    {
        $this->pdo->prepare('DELETE FROM bs_idea_rating WHERE idea_id = ? AND user_id = ?')
                  ->execute([$id, $userId]);
    }

    /**
     * 평가 요약. **개별 점수는 본인 것만.**
     *
     * 한줄 의견은 이름과 함께 보이지만 점수는 붙이지 않는다 — "누가 몇 점"
     * 을 의견 목록으로 맞춰 볼 수 없게.
     */
    public function ratingSummary(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) n, AVG(value_score) v, AVG(feasibility_score) f
               FROM bs_idea_rating WHERE idea_id = ?');
        $st->execute([$id]);
        $agg = $st->fetch(PDO::FETCH_ASSOC);
        $out = $this->summaryFromRow(['rating_n' => $agg['n'], 'value_avg' => $agg['v'], 'feas_avg' => $agg['f']]);

        $me = bs_current_user();
        $out['mine'] = null;
        if ($me) {
            $st = $this->pdo->prepare(
                'SELECT value_score, feasibility_score, opinion FROM bs_idea_rating
                  WHERE idea_id = ? AND user_id = ?');
            $st->execute([$id, (string)$me['id']]);
            if ($m = $st->fetch(PDO::FETCH_ASSOC)) {
                $out['mine'] = ['value' => (int)$m['value_score'],
                                'feasibility' => (int)$m['feasibility_score'],
                                'opinion' => $m['opinion']];
            }
        }

        $st = $this->pdo->prepare(
            "SELECT user_name, opinion, updated_at FROM bs_idea_rating
              WHERE idea_id = ? AND opinion IS NOT NULL AND opinion <> ''
              ORDER BY updated_at DESC");
        $st->execute([$id]);
        $out['opinions'] = array_map(static fn($r) => [
            'name' => $r['user_name'], 'opinion' => $r['opinion'],
            'at'   => bs_date($r['updated_at'], 'Y-m-d H:i'),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return $out;
    }

    /** 집계 행 → 화면 모양. 인원이 모자라면 평균을 null 로 감춘다. */
    private function summaryFromRow(array $r): array
    {
        $n    = (int)$r['rating_n'];
        $open = $n >= BS_IDEA_MIN_RATERS;
        return [
            'count'       => $n,
            'shown'       => $open,
            'min_raters'  => BS_IDEA_MIN_RATERS,
            'value'       => $open ? round((float)$r['value_avg'], 1) : null,
            'feasibility' => $open ? round((float)$r['feas_avg'], 1) : null,
        ];
    }

    // =================================================================
    // 피드백
    // =================================================================

    /** 피드백과 상태 기록. 답글은 원 피드백 아래 replies 로 들어간다. */
    public function comments(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, parent_id, kind, user_id, user_name, content, deleted_at, created_at, updated_at
               FROM bs_idea_comment WHERE idea_id = ? ORDER BY id');
        $st->execute([$id]);
        $me    = bs_current_user();
        $admin = bs_is_admin();

        $byId = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mine = $me !== null && (string)$r['user_id'] === (string)$me['id'];
            $gone = $r['deleted_at'] !== null;
            $byId[(int)$r['id']] = [
                'id'        => (int)$r['id'],
                'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                'kind'      => $r['kind'],
                'user_name' => $gone ? null : $r['user_name'],
                'content'   => $gone ? null : $r['content'],
                'deleted'   => $gone,
                'edited'    => !$gone && $r['kind'] === 'comment' && $r['updated_at'] !== $r['created_at'],
                'created_at'=> bs_date($r['created_at'], 'Y-m-d H:i'),
                'can_edit'  => !$gone && $r['kind'] === 'comment' && $mine,
                'can_delete'=> !$gone && $r['kind'] === 'comment' && ($mine || $admin),
                'replies'   => [],
            ];
        }
        $out = [];
        foreach ($byId as $cid => $c) {
            if ($c['parent_id'] !== null && isset($byId[$c['parent_id']])) {
                $byId[$c['parent_id']]['replies'][] = $c;
            }
        }
        foreach ($byId as $c) {
            if ($c['parent_id'] !== null) {
                continue;
            }
            // 지운 피드백은 답글이 매달려 있을 때만 자리를 남긴다.
            if ($c['deleted'] && !$c['replies']) {
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }

    public function findComment(int $commentId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_idea_comment WHERE id = ?');
        $st->execute([$commentId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** 피드백을 단다. 답글의 답글은 원 피드백 아래로 붙인다(한 단계). */
    public function addComment(int $id, array $actor, string $content, ?int $parentId = null): int
    {
        $cur = $this->find($id);
        if (!$cur || !bs_idea_can_comment($cur)) {
            throw new DomainException('피드백을 달 수 없는 아이디어입니다.');
        }
        $content = $this->checkContent($content);
        if ($parentId) {
            $p = $this->findComment($parentId);
            if (!$p || (int)$p['idea_id'] !== $id || $p['kind'] !== 'comment') {
                throw new DomainException('답글을 달 피드백을 찾을 수 없습니다.');
            }
            $parentId = $p['parent_id'] !== null ? (int)$p['parent_id'] : (int)$p['id'];
        }
        $this->pdo->prepare(
            "INSERT INTO bs_idea_comment (idea_id, parent_id, kind, user_id, user_name, content)
             VALUES (?,?, 'comment', ?,?,?)"
        )->execute([$id, $parentId ?: null, (string)$actor['id'], $actor['name'] ?? null, $content]);
        return (int)$this->pdo->lastInsertId();
    }

    /** 본인만 고친다. 관리자도 남의 말은 고치지 않는다 — 지울 수만 있다. */
    public function updateComment(int $commentId, array $actor, string $content): void
    {
        $c = $this->findComment($commentId);
        if (!$c || $c['deleted_at'] !== null || $c['kind'] !== 'comment') {
            throw new DomainException('피드백을 찾을 수 없습니다.');
        }
        if ((string)$c['user_id'] !== (string)$actor['id']) {
            throw new DomainException('자기가 쓴 피드백만 고칠 수 있습니다.');
        }
        $this->pdo->prepare('UPDATE bs_idea_comment SET content = ? WHERE id = ?')
                  ->execute([$this->checkContent($content), $commentId]);
    }

    public function deleteComment(int $commentId, array $actor): void
    {
        $c = $this->findComment($commentId);
        if (!$c || $c['deleted_at'] !== null || $c['kind'] !== 'comment') {
            throw new DomainException('피드백을 찾을 수 없습니다.');
        }
        if ((string)$c['user_id'] !== (string)$actor['id'] && !bs_is_admin()) {
            throw new DomainException('자기가 쓴 피드백만 지울 수 있습니다.');
        }
        $this->pdo->prepare('UPDATE bs_idea_comment SET deleted_at = NOW() WHERE id = ?')
                  ->execute([$commentId]);
    }

    // =================================================================
    // 검토·묶기·승격
    // =================================================================

    /**
     * 검토 — 채택·보류·반려(또는 되돌리기). 사유는 피드백 흐름에도 남는다.
     * promoted 로는 여기서 갈 수 없다. markPromoted() 만 그 길이다.
     */
    public function review(int $id, string $status, ?string $note, array $actor): void
    {
        $cur = $this->find($id);
        if (!$cur || !bs_idea_can_review($cur)) {
            throw new DomainException('검토할 수 없는 아이디어입니다.');
        }
        if (!in_array($status, BS_IDEA_REVIEW_STATUS, true)) {
            throw new InvalidArgumentException('알 수 없는 상태입니다: ' . $status);
        }
        $note = $this->nn($note);
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('검토 의견은 500자 이내로 적어 주세요.');
        }
        if ($status === 'rejected' && $note === null) {
            // 반려는 사유가 있어야 한다. 이유 없이 닫히면 다음 제안이 안 올라온다.
            throw new InvalidArgumentException('반려할 때는 사유를 적어 주세요.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE bs_idea SET status = ?, review_note = ?, reviewed_by = ?, reviewed_by_name = ?,
                        reviewed_at = NOW() WHERE id = ?'
            )->execute([$status, $note, (string)$actor['id'], $actor['name'] ?? null, $id]);
            $this->logStatus($id, $actor, '상태를 「' . BS_IDEA_STATUS[$status] . '」(으)로 바꿨습니다.'
                . ($note !== null ? "\n" . $note : ''));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 비슷한 아이디어로 묶는다. $targetId 가 null 이면 묶음을 푼다.
     * 사슬은 만들지 않는다 — 이미 다른 데 묶인 것에는 묶지 않는다.
     */
    public function merge(int $id, ?int $targetId, array $actor): void
    {
        $cur = $this->find($id);
        if (!$cur || !bs_idea_can_review($cur)) {
            throw new DomainException('묶을 수 없는 아이디어입니다.');
        }
        if ($targetId !== null) {
            $t = $this->find($targetId);
            if (!$t || bs_idea_is_deleted($t) || $targetId === $id) {
                throw new DomainException('묶을 대상 아이디어를 찾을 수 없습니다.');
            }
            if ($t['duplicate_of'] !== null) {
                throw new DomainException('대상도 다른 아이디어에 묶여 있습니다. 그 원래 아이디어로 묶어 주세요.');
            }
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM bs_idea WHERE duplicate_of = ? AND deleted_at IS NULL');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                throw new DomainException('이 아이디어로 묶인 것이 있어 다른 데 묶을 수 없습니다. 반대 방향으로 묶어 주세요.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE bs_idea SET duplicate_of = ? WHERE id = ?')->execute([$targetId, $id]);
            if ($targetId !== null) {
                $this->logStatus($id, $actor, "비슷한 아이디어 {$t['code']} 로 묶었습니다. 평가와 피드백은 그쪽에서 받습니다.");
                $this->logStatus($targetId, $actor, "{$cur['code']} 「{$cur['title']}」 를 이 아이디어로 묶었습니다.");
            } else {
                $this->logStatus($id, $actor, '묶음을 풀었습니다.');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 과제·프로젝트로 넘어갔음을 적는다. **promoted 로 가는 유일한 길.**
     * 트랜잭션은 부르는 쪽(IdeaPromoter)이 연다 — 과제 생성과 한 묶음이어야 한다.
     */
    public function markPromoted(int $id, int $projectId, string $label, array $actor): void
    {
        $st = $this->pdo->prepare(
            "UPDATE bs_idea SET status = 'promoted', promoted_project_id = ?, promoted_by = ?,
                    promoted_by_name = ?, promoted_at = NOW()
              WHERE id = ? AND status <> 'promoted' AND deleted_at IS NULL");
        $st->execute([$projectId, (string)$actor['id'], $actor['name'] ?? null, $id]);
        if ($st->rowCount() === 0) {
            throw new DomainException('이미 넘어갔거나 지운 아이디어입니다.');
        }
        $this->logStatus($id, $actor, $label . ' (으)로 넘어갔습니다.');
    }

    /** 이 아이디어에 평가·피드백을 남긴 사람들. 승격 알림을 받을 사람이다. */
    public function participants(int $id): array
    {
        $st = $this->pdo->prepare(
            "SELECT user_id FROM bs_idea_rating WHERE idea_id = ?
             UNION SELECT user_id FROM bs_idea_comment WHERE idea_id = ? AND kind = 'comment' AND deleted_at IS NULL");
        $st->execute([$id, $id]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    // =================================================================
    // 안쪽
    // =================================================================

    private function logStatus(int $id, array $actor, string $text): void
    {
        $this->pdo->prepare(
            "INSERT INTO bs_idea_comment (idea_id, kind, user_id, user_name, content)
             VALUES (?, 'status', ?, ?, ?)"
        )->execute([$id, (string)$actor['id'], $actor['name'] ?? null, $text]);
    }

    /** @return array{0:string,1:array} */
    private function boardWhere(array $f, ?array $me): array
    {
        $w = ['1 = 1'];
        $p = [];
        // 지운 것은 관리자가 고를 때만. 본인 것도 보드에서는 빠진다.
        if (!(bs_is_admin() && !empty($f['with_deleted']))) {
            $w[] = 'i.deleted_at IS NULL';
        }
        $status = (string)($f['status'] ?? '');
        if ($status !== '' && isset(BS_IDEA_STATUS[$status])) {
            $w[] = 'i.status = ?';
            $p[] = $status;
        }
        $cat = (string)($f['category'] ?? '');
        if ($cat !== '' && isset(BS_RND_CATEGORY[$cat])) {
            $w[] = 'i.rnd_category = ?';
            $p[] = $cat;
        }
        if (!empty($f['mine']) && $me) {
            $w[] = 'i.author_id = ?';
            $p[] = (string)$me['id'];
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $this->escapeLike($q) . '%';
            // 익명 아이디어를 작성자 이름으로 찾아내지 못하게 이름은 검색하지 않는다.
            $w[] = "(i.code LIKE ? ESCAPE '!' OR i.title LIKE ? ESCAPE '!'
                     OR i.problem LIKE ? ESCAPE '!' OR i.proposal LIKE ? ESCAPE '!')";
            array_push($p, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $p];
    }

    /**
     * 입력 정리·검증. $isNew 면 필수까지 본다. 넘어온 키만 돌려준다(수정용).
     */
    private function clean(array $d, bool $isNew): array
    {
        $out = [];
        if ($isNew || array_key_exists('title', $d)) {
            $t = trim((string)($d['title'] ?? ''));
            if ($t === '') {
                throw new InvalidArgumentException('제목을 입력하세요.');
            }
            if (mb_strlen($t) > 200) {
                throw new InvalidArgumentException('제목이 너무 깁니다(200자 이내).');
            }
            $out['title'] = $t;
        }
        foreach (['problem', 'proposal', 'effect'] as $k) {
            if ($isNew || array_key_exists($k, $d)) {
                $v = $this->nn($d[$k] ?? null);
                if ($v !== null && mb_strlen($v) > 10000) {
                    throw new InvalidArgumentException('본문이 너무 깁니다(칸마다 10,000자 이내).');
                }
                $out[$k] = $v;
            }
        }
        if ($isNew && $out['problem'] === null && $out['proposal'] === null) {
            throw new InvalidArgumentException('무엇이 문제인지, 또는 어떻게 하자는지 중 하나는 적어 주세요.');
        }
        if ($isNew || array_key_exists('url', $d)) {
            $out['url'] = $this->checkUrl($d['url'] ?? null);
        }
        if ($isNew || array_key_exists('rnd_category', $d)) {
            $c = $this->nn($d['rnd_category'] ?? null);
            if ($c !== null && !isset(BS_RND_CATEGORY[$c])) {
                throw new InvalidArgumentException('알 수 없는 갈래입니다.');
            }
            $out['rnd_category'] = $c;
        }
        if ($isNew || array_key_exists('is_anonymous', $d)) {
            $out['is_anonymous'] = !empty($d['is_anonymous']) ? 1 : 0;
        }
        if ($isNew || array_key_exists('domain_ids', $d)) {
            $out['domain_ids'] = (array)($d['domain_ids'] ?? []);
        }
        return $out;
    }

    private function checkContent(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('내용을 입력하세요.');
        }
        if (mb_strlen($content) > 2000) {
            throw new InvalidArgumentException('피드백은 2,000자 이내로 적어 주세요.');
        }
        return $content;
    }

    private function checkUrl(mixed $raw): ?string
    {
        $url = $this->nn($raw);
        if ($url === null) {
            return null;
        }
        if (!preg_match('#^https?://#i', (string)$url)) {
            throw new InvalidArgumentException('http:// 또는 https:// 로 시작하는 주소만 등록할 수 있습니다.');
        }
        if (mb_strlen((string)$url) > BS_URL_MAX) {
            throw new InvalidArgumentException(sprintf('주소가 너무 깁니다. %s자 이내로 넣어 주세요.',
                number_format(BS_URL_MAX)));
        }
        return (string)$url;
    }

    /** 채번 — IDEA-2026-001. 지운 것도 포함해 최대값을 본다(번호를 다시 쓰지 않는다). */
    private function nextCode(int $year): string
    {
        $prefix = sprintf('IDEA-%04d-', $year);
        $st = $this->pdo->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)), 0) FROM bs_idea WHERE code LIKE ?');
        $st->execute([strlen($prefix) + 1, $prefix . '%']);
        return $prefix . sprintf('%03d', (int)$st->fetchColumn() + 1);
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
}

<?php
/** bs_member / bs_member_skill / bs_member_metric / bs_eval_run 접근 담당 DAO. 구성원과 역량 스냅샷을 다룬다. */

declare(strict_types=1);

/**
 * 구성원·역량 저장소.
 *
 * 주의(CLAUDE.md): 종합점수로 전체 순위를 매기는 조회를 여기에 만들지 말 것.
 * 정렬이 허용되는 범위는 "특정 분야 기준"(skillsByDomain) 까지다.
 */
final class MemberRepo
{
    public function __construct(private PDO $pdo) {}

    // -----------------------------------------------------------------
    // 구성원
    // -----------------------------------------------------------------

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_member WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** 포털 이메일로 찾는다. bs_member.user_id 가 이메일이다. */
    public function findByUserId(string $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_member WHERE user_id = ?');
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @param array $filter role_label, team, keyword, is_assignable */
    /**
     * 구성원 목록 조회.
     *
     * `ix_bs_member_assignable (is_assignable, role_label)` 를 타도록
     * is_assignable 을 맨 앞 조건으로 둔다.
     *
     * **정렬은 이름 순으로 고정이다.** 점수로 줄 세우는 길을 열어 두면
     * 그것이 곧 전사 랭킹이 된다 (CLAUDE.md 가 금지한 것). 이 메서드는
     * 점수 표를 아예 조인하지 않는다.
     *
     * @param array{role_label?:string,team?:string,keyword?:string,is_assignable?:int|string|null} $filter
     *        is_assignable 에 null 이나 '' 을 주면 배정 제외자까지 모두 돌려준다.
     */
    public function search(array $filter): array
    {
        $where  = [];
        $params = [];

        // ┌──────────────────────────────────────────────────────────────┐
        // │ ?? 가 null 을 삼켜 '전체 보기' 가 동작하지 않았다              │
        // │                                                              │
        // │ 전에는 `$filter['is_assignable'] ?? 1` 이었다. ?? 는 **null 을│
        // │ '안 준 것'으로 보므로**, "가리지 말라" 는 뜻으로 null 을      │
        // │ 넘겨도 1 로 바뀌어 배정 가능한 사람만 나왔다. [배정 제외자    │
        // │ 포함] 을 켜도 그대로였다(2026-10-07).                         │
        // │                                                              │
        // │ "안 줬다" 와 "null 을 줬다" 는 다르다. array_key_exists 로    │
        // │ 가른다 — bs_has_param() 이 같은 이유로 있는 것과 같다.        │
        // │                                                              │
        // │   키 없음 — 배정 가능만(기본)                                 │
        // │   null    — 가리지 않는다(전체)                               │
        // │   0       — 배정 제외만                                       │
        // │   그 밖    — 배정 가능만                                      │
        // └──────────────────────────────────────────────────────────────┘
        $assignable = array_key_exists('is_assignable', $filter) ? $filter['is_assignable'] : 1;
        if ($assignable !== null && $assignable !== '') {
            $where[]  = 'is_assignable = ?';
            $params[] = (int)$assignable === 0 ? 0 : 1;
        }
        if (($filter['role_label'] ?? '') !== '') {
            $where[]  = 'role_label = ?';
            $params[] = (string)$filter['role_label'];
        }
        if (($filter['team'] ?? '') !== '') {
            $where[]  = 'team = ?';
            $params[] = (string)$filter['team'];
        }

        $kw = trim((string)($filter['keyword'] ?? ''));
        if ($kw !== '') {
            // LIKE 메타문자를 막는다. 안 막으면 '%' 한 글자가 전체 조회가 된다.
            $like = '%' . addcslashes($kw, '%_') . '%';
            $where[] = '(emp_name LIKE ? OR team LIKE ? OR role_label LIKE ? OR slack_handle LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $sql = 'SELECT id, user_id, emp_name, role_label, team, base_capacity,
                       career_months, join_date, slack_handle, is_assignable,
                       is_evaluable, eval_exclude_reason
                  FROM bs_member';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY emp_name, id';

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 배정 후보로 올릴지 말지를 바꾼다.
     *
     * 개발 사업과 무관한 직무(경영지원 등)나 휴직·퇴사자를 후보 목록에서
     * 뺀다. **행을 지우지 않는다** — 과거 배정 기록의 주인이기 때문이다.
     *
     * `is_evaluable` 과 섞지 말 것. 여기서 빼도 역량 점수는 그대로 나고,
     * 평가에서 빼도 배정 후보에는 남는다. 기획 담당자가 뒤쪽 경우다 —
     * 슬랙 취합 데이터가 없어 점수를 못 내지만 기획 과업에는 배정된다.
     */
    public function setAssignable(int $memberId, bool $assignable): bool
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_member SET is_assignable = ? WHERE id = ?'
        );
        $st->execute([$assignable ? 1 : 0, $memberId]);
        return $st->rowCount() > 0;
    }

    /** 목록 화면의 거르개에 채울 값. 있는 것만 보여 준다. */
    public function filterOptions(): array
    {
        $q = static function (PDO $pdo, string $col): array {
            $st = $pdo->query(
                "SELECT DISTINCT `$col` AS v FROM bs_member
                  WHERE `$col` IS NOT NULL AND `$col` <> '' ORDER BY v"
            );
            return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'v');
        };
        // $col 은 아래 두 리터럴뿐이다. 바깥에서 들어오는 값이 아니다.
        return ['role' => $q($this->pdo, 'role_label'), 'team' => $q($this->pdo, 'team')];
    }

    /**
     * 여러 사람의 주요 분야를 **한 번에** 가져온다.
     *
     * 목록에서 skills() 를 사람마다 부르면 인원수 비례 질의가 된다
     * (CLAUDE.md 가 금지한 N+1). 한 질의로 받아 PHP 에서 자른다.
     *
     * 점수가 아니라 **건수**로 고른다. bs_member_skill.score 는 설계상 늘
     * NULL 이다 — 점수는 계열 단위로만 낸다.
     *
     * @return array<int, list<array{name:string,case_count:int}>> member_id => 분야들
     */
    public function primaryDomains(array $memberIds, ?int $evalVer = null, int $perMember = 3): array
    {
        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        if ($ids === []) {
            return [];
        }
        $evalVer = $evalVer ?? $this->latestEvalVer();
        if ($evalVer === null) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT s.member_id, d.name, s.case_count
               FROM bs_member_skill s
               JOIN bs_domain d ON d.id = s.domain_id
              WHERE s.eval_ver = ? AND s.member_id IN ($ph)
              ORDER BY s.member_id, s.case_count DESC, d.sort_no"
        );
        $st->execute(array_merge([$evalVer], $ids));

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mid = (int)$r['member_id'];
            if (count($out[$mid] ?? []) >= $perMember) {
                continue;
            }
            $out[$mid][] = ['name' => (string)$r['name'], 'case_count' => (int)$r['case_count']];
        }
        return $out;
    }

    /**
     * 여러 사람의 표본 충분 여부를 한 번에.
     *
     * **점수는 돌려주지 않는다.** 목록 화면이 쓰는 것은 "표본이 찼는가" 뿐이고,
     * cap_score 를 같이 내려보내면 그 열을 만들고 싶어진다.
     *
     * @return array<int, array{insufficient:bool,total_cases:int}>
     */
    public function sampleStatusFor(array $memberIds, ?int $evalVer = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        if ($ids === []) {
            return [];
        }
        $evalVer = $evalVer ?? $this->latestEvalVer();
        if ($evalVer === null) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT member_id, total_cases, insufficient_data
               FROM bs_member_metric
              WHERE eval_ver = ? AND member_id IN ($ph)"
        );
        $st->execute(array_merge([$evalVer], $ids));

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['member_id']] = [
                'insufficient' => (int)$r['insufficient_data'] === 1,
                'total_cases'  => (int)$r['total_cases'],
            ];
        }
        return $out;
    }

    /**
     * 배정 후보로 올릴 수 있는 사람.
     *
     * `is_assignable` 만 본다. **`is_evaluable` 로 거르지 않는다** — 아래
     * 주석대로 둘은 다른 개념이다. 점수를 못 내는 사람도 일은 한다.
     * 점수가 없는 것은 배정 엔진이 표본 없음으로 따로 다룬다.
     */
    public function assignable(): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_member WHERE is_assignable = 1 ORDER BY id'
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------
    // 평가 제외
    //
    // is_assignable 과 **다른 개념**이다.
    //   is_assignable = 0  배정 후보로 올리지 않는다
    //   is_evaluable  = 0  역량 점수를 내지 않는다
    // 기획 담당자처럼 슬랙 취합 데이터가 없는 직무는 평가만 빼고 배정은 살린다.
    //
    // 근거: docs/data-quality-report.md — 자사 구성원 13명 중 3명이 6개월간
    // 슬랙 취합 시스템 활동 0건이었다. 이 사람들에게 낮은 점수를 주면 안 되고,
    // '표본 부족' 으로 두어도 안 된다(영영 안 채워질 표본이므로).
    // -----------------------------------------------------------------

    /**
     * 점수 산출 대상인 사람만. CapabilityScorer 가 이걸로 훑는다.
     * 평가 제외자를 여기서 빼지 않으면 0점짜리 스냅샷이 쌓인다.
     */
    public function evaluable(): array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_member WHERE is_evaluable = 1 ORDER BY id');
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 평가 대상 여부를 바꾼다.
     *
     * 제외할 때는 사유가 **필수**다. 본인이 자기 프로파일에서 "왜 점수가 없는지"
     * 를 볼 수 있어야 하기 때문이다(CLAUDE.md: 본인은 항상 열람 가능 + 이의 제기).
     * 사유 없는 제외는 설명할 수 없는 제외다.
     */
    public function setEvaluable(int $memberId, bool $evaluable, string $reason, array $actor): void
    {
        if (!$evaluable && trim($reason) === '') {
            throw new InvalidArgumentException('평가 제외 사유를 입력하세요. 본인에게 표시됩니다.');
        }
        if ($evaluable) {
            $st = $this->pdo->prepare(
                'UPDATE bs_member SET is_evaluable = 1, eval_exclude_reason = NULL,
                        eval_excluded_at = NULL, eval_excluded_by = NULL
                  WHERE id = ?'
            );
            $st->execute([$memberId]);
            return;
        }
        $st = $this->pdo->prepare(
            'UPDATE bs_member SET is_evaluable = 0, eval_exclude_reason = ?,
                    eval_excluded_at = NOW(), eval_excluded_by = ?
              WHERE id = ?'
        );
        $st->execute([mb_substr(trim($reason), 0, 200), $actor['id'], $memberId]);
    }

    /**
     * 이 사람이 점수 산출 대상인가. 화면이 안내 문구를 고를 때 쓴다.
     *
     * @return array{evaluable:bool,reason:?string}
     */
    public function evaluationStatus(int $memberId): array
    {
        $st = $this->pdo->prepare(
            'SELECT is_evaluable, eval_exclude_reason FROM bs_member WHERE id = ?'
        );
        $st->execute([$memberId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return ['evaluable' => true, 'reason' => null];
        }
        $ok = (int)$r['is_evaluable'] === 1;
        return ['evaluable' => $ok, 'reason' => $ok ? null : $r['eval_exclude_reason']];
    }

    public function create(array $data): int
    {
        // TODO(P3)
        return 0;
    }

    public function update(int $id, array $data): void
    {
        // TODO(P3)
    }

    /**
     * 포털 계정(portal_users)을 훑어 빠진 구성원을 채운다.
     * 사람이 들어오고 나갈 때마다 손으로 넣지 않으려는 장치.
     */
    /**
     * 포털 사용자(`portal_users`)를 구성원 표로 가져온다.
     *
     * **구성원의 원본은 `portal_users` 하나다.** 슬랙 취합 시스템의 담당자
     * 이름으로 구성원을 만들지 않는다 — 거기에는 협력사 인력이 섞여 있고,
     * 평가 대상은 자사 구성원뿐이다 (CLAUDE.md).
     *
     * 세 가지만 한다.
     *
     *   1. 포털에 있는데 구성원 표에 없는 사람을 넣는다
     *   2. 포털에서 이름이 바뀐 사람의 이름을 맞춘다
     *   3. 포털에서 사라진 사람을 `is_assignable = 0` 으로 내린다
     *
     * **행을 지우지 않는다.** 퇴사자도 과거 배정·업무 이력의 주인이라
     * 남아야 한다. 지우면 그 기록이 주인 없는 것이 된다.
     *
     * **`is_evaluable` 은 건드리지 않는다.** 사람이 손으로 정한 값이라
     * 동기화가 돌 때마다 1 로 되돌리면 평가 제외가 조용히 풀린다
     * (기획 담당자처럼 이 데이터로 평가할 수 없는 직무가 있다).
     * 새로 들어온 사람만 스키마 기본값 1 로 들어간다.
     *
     * **한 번 내려간 `is_assignable` 을 다시 올리지 않는다.** 그 값은 휴직
     * 처리로도 사람이 직접 내린다. 자동으로 올리면 그 판단을 덮어쓴다.
     * 포털에 있는데 배정 제외인 사람 수를 따로 세어 돌려주므로, 화면이
     * 그 사실을 알리고 사람이 판단하면 된다.
     *
     * 역할·팀·경력·기본 가용은 `portal_users` 에 없다(이름·이메일·색뿐이다).
     * 그래서 채우지 못하고 비워 둔다 — HR 정보라 사람이 넣어야 한다.
     * 비어 있어도 점수는 멀쩡하다. 절대 기준이라 role_label 로 정규화하지
     * 않는다(명세서 §4). 화면 표시와 후보 거르개에만 쓰인다.
     *
     * @return array{added:int, renamed:int, deactivated:int, excluded_but_active:int, total:int}
     */
    public function syncFromPortalUsers(): array
    {
        // portal_users 는 포털 DB 에 있고 BlueStudio 는 같은 DB 를 쓴다
        // (bs_db() → portal_db()). 그래서 한 연결에서 그대로 읽는다.
        $this->pdo->beginTransaction();
        try {
            // 1. 새로 들어온 사람.
            //    역할·팀·경력은 비워 둔다. 나머지는 스키마 기본값이다
            //    (base_capacity 1.00 / is_assignable 1 / is_evaluable 1).
            $added = $this->pdo->exec(
                'INSERT INTO bs_member (user_id, emp_name)
                 SELECT u.email, u.name
                   FROM portal_users u
                   LEFT JOIN bs_member m ON m.user_id = u.email
                  WHERE m.id IS NULL'
            );

            // 2. 포털에서 이름이 바뀐 사람. 이름의 원본은 포털이다.
            $renamed = $this->pdo->exec(
                'UPDATE bs_member m
                   JOIN portal_users u ON u.email = m.user_id
                    SET m.emp_name = u.name
                  WHERE m.emp_name <> u.name'
            );

            // 3. 포털에서 사라진 사람. 지우지 않고 배정 후보에서만 내린다.
            $deactivated = $this->pdo->exec(
                'UPDATE bs_member m
                   LEFT JOIN portal_users u ON u.email = m.user_id
                    SET m.is_assignable = 0
                  WHERE u.id IS NULL AND m.is_assignable = 1'
            );

            // 포털에는 있는데 배정 제외인 사람. 자동으로 올리지 않으므로
            // 사람이 보고 판단하라고 수만 세어 준다.
            $st = $this->pdo->query(
                'SELECT COUNT(*) FROM bs_member m
                   JOIN portal_users u ON u.email = m.user_id
                  WHERE m.is_assignable = 0'
            );
            $excluded = (int)$st->fetchColumn();

            $total = (int)$this->pdo->query('SELECT COUNT(*) FROM bs_member')->fetchColumn();

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'added'               => (int)$added,
            'renamed'             => (int)$renamed,
            'deactivated'         => (int)$deactivated,
            'excluded_but_active' => $excluded,
            'total'               => $total,
        ];
    }

    // -----------------------------------------------------------------
    // 역량 스냅샷 (bs_member_skill)
    // -----------------------------------------------------------------

    /**
     * 이 분야를 잘하는 사람들. 후보 리스트의 주 경로.
     *
     * 반환에 insufficient_data 를 반드시 함께 실어야 한다. 화면이 점수만 받으면
     * 표본 부족인 사람을 "점수 낮은 사람" 으로 잘못 보여준다(CLAUDE.md).
     */
    public function skillsByDomain(int $domainId, ?int $evalVer = null, int $limit = 50): array
    {
        // TODO(P4): ix_bs_mskill_domain (domain_id, eval_ver, score) 를 탄다.
        return [];
    }

    /** 역량 스냅샷을 통째로 기록한다. 판정 배치가 부른다. */
    public function saveSkills(int $memberId, int $evalVer, array $rows): void
    {
        // TODO(P3): 한 회차분을 트랜잭션으로 묶어 넣는다.
    }

    // -----------------------------------------------------------------
    // 종합 지표 (bs_member_metric)
    // -----------------------------------------------------------------

    public function saveMetric(int $memberId, int $evalVer, array $data): void
    {
        // TODO(P3)
    }

    /**
     * 관리자 보정치.
     * 범위(BS_ADJUST_MIN ~ BS_ADJUST_MAX)와 사유 필수를 여기서 확인한다.
     */
    public function applyManualAdjust(int $memberId, int $evalVer, float $adjust, string $reason, array $actor): void
    {
        if ($adjust < BS_ADJUST_MIN || $adjust > BS_ADJUST_MAX) {
            throw new InvalidArgumentException(
                sprintf('보정치는 %+.0f ~ %+.0f 사이여야 합니다.', BS_ADJUST_MIN, BS_ADJUST_MAX)
            );
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('보정 사유를 입력하세요. 본인에게 표시됩니다.');
        }
        // 사유에 누가 언제 했는지를 덧붙인다 — 본인이 보고 물어볼 수 있어야 한다.
        $note = sprintf('%s (%s, %s)', trim($reason), $actor['name'], date('Y-m-d'));
        $st = $this->pdo->prepare(
            'UPDATE bs_member_metric SET manual_adjust = ?, adjust_reason = ?
              WHERE member_id = ? AND eval_ver = ?'
        );
        $st->execute([$adjust, mb_substr($note, 0, 300), $memberId, $evalVer]);
        if ($st->rowCount() === 0) {
            throw new DomainException('그 회차에 해당 구성원의 지표가 없습니다.');
        }
    }

    // -----------------------------------------------------------------
    // 판정 실행 (bs_eval_run)
    // -----------------------------------------------------------------

    /** 판정 시작. 돌려주는 id 가 곧 eval_ver 다. */
    public function startEvalRun(array $params): int
    {
        // TODO(P3): status 를 running 으로 넣는다.
        return 0;
    }

    public function finishEvalRun(int $evalVer, string $status, ?string $note = null): void
    {
        // TODO(P3): $status 는 ok|fail
    }

    // -----------------------------------------------------------------
    // 이의 제기 (bs_profile_objection)
    // -----------------------------------------------------------------

    public function addObjection(int $memberId, array $data): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO bs_profile_objection (member_id, eval_ver, domain_id, content, status)
             VALUES (?,?,?,?,"open")'
        );
        $st->execute([
            $memberId,
            $data['eval_ver']  ?: null,
            $data['domain_id'] ?: null,
            $data['content'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** 미처리(open) 우선. 관리자 화면용. */
    public function objections(array $filter = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filter['status'])) {
            $where[]  = 'o.status = ?';
            $params[] = $filter['status'];
        }
        $sql = 'SELECT o.*, m.emp_name, d.name AS domain_name
                  FROM bs_profile_objection o
                  JOIN bs_member m ON m.id = o.member_id
             LEFT JOIN bs_domain d ON d.id = o.domain_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY FIELD(o.status, "open", "reviewed", "applied", "rejected"), o.id DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reviewObjection(int $objectionId, string $status, string $note, array $actor): void
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_profile_objection
                SET status = ?, review_note = ?, reviewed_by = ?, reviewed_by_name = ?,
                    reviewed_at = NOW()
              WHERE id = ?'
        );
        $st->execute([$status, $note, $actor['id'], $actor['name'], $objectionId]);
    }

    // =================================================================
    // P3 구현분 — 점수 조회와 근거 추적
    // =================================================================

    /** 가장 최근 성공(status=ok)한 판정 회차. 없으면 null. */
    public function latestEvalVer(): ?int
    {
        $st = $this->pdo->prepare(
            'SELECT id FROM bs_eval_run WHERE status = "ok" ORDER BY id DESC LIMIT 1'
        );
        $st->execute();
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    public function evalRun(int $evalVer): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, started_at, finished_at, period_from, period_to,
                    formula_ver, status, note
               FROM bs_eval_run WHERE id = ?'
        );
        $st->execute([$evalVer]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * 계열별 역량 점수. **레이더 차트가 쓰는 값이다.**
     *
     * 표본이 모자란 칸은 score 를 아예 null 로 내보낸다. 화면이 실수로
     * 점수처럼 그리지 못하게 하려는 것이다(CLAUDE.md).
     */
    public function categoryScores(int $memberId, int $evalVer): array
    {
        $st = $this->pdo->prepare(
            'SELECT category, case_count, weighted_qty, baseline, score,
                    confidence, insufficient_data
               FROM bs_member_category
              WHERE member_id = ? AND eval_ver = ?
              ORDER BY FIELD(confidence, "full", "partial", "none"), score DESC'
        );
        $st->execute([$memberId, $evalVer]);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $insuf = (int)$r['insufficient_data'] === 1;
            $out[] = [
                'category'     => $r['category'],
                'label'        => BS_DOMAIN_CATEGORY[$r['category']]['label'] ?? $r['category'],
                'moodle'       => BS_DOMAIN_CATEGORY[$r['category']]['moodle'] ?? null,
                'case_count'   => (int)$r['case_count'],
                'weighted_qty' => $r['weighted_qty'] !== null ? (float)$r['weighted_qty'] : null,
                'baseline'     => $r['baseline'] !== null ? (float)$r['baseline'] : null,
                'score'        => $insuf ? null : ($r['score'] !== null ? (float)$r['score'] : null),
                'confidence'   => $r['confidence'],
                'insufficient_data' => $insuf,
                'note'         => $insuf
                    ? sprintf('표본 부족 (%d건)', (int)$r['case_count'])
                    : ($r['confidence'] === 'partial'
                        ? sprintf('참고 (%d건)', (int)$r['case_count']) : null),
            ];
        }
        return $out;
    }

    /** 분야별 실적. 점수는 없다 — 근거 표시용이다. */
    public function skills(int $memberId, ?int $evalVer = null): array
    {
        $evalVer = $evalVer ?? $this->latestEvalVer();
        if ($evalVer === null) {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT s.domain_id, d.code, d.name, d.category,
                    s.case_count, s.weighted_qty
               FROM bs_member_skill s
               JOIN bs_domain d ON d.id = s.domain_id
              WHERE s.member_id = ? AND s.eval_ver = ?
              ORDER BY s.case_count DESC'
        );
        $st->execute([$memberId, $evalVer]);

        return array_map(static function (array $r): array {
            return [
                'domain_id'    => (int)$r['domain_id'],
                'code'         => $r['code'],
                'name'         => $r['name'],
                'category'     => $r['category'],
                'cat_label'    => BS_DOMAIN_CATEGORY[$r['category']]['label'] ?? $r['category'],
                'case_count'   => (int)$r['case_count'],
                'weighted_qty' => (float)$r['weighted_qty'],
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function metric(int $memberId, ?int $evalVer = null): ?array
    {
        $evalVer = $evalVer ?? $this->latestEvalVer();
        if ($evalVer === null) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT total_cases, cap_score, breadth_score, career_score,
                    manual_adjust, adjust_reason, insufficient_data
               FROM bs_member_metric WHERE member_id = ? AND eval_ver = ?'
        );
        $st->execute([$memberId, $evalVer]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $f = static function ($v) { return $v === null ? null : (float)$v; };
        return [
            'total_cases'   => (int)$r['total_cases'],
            'cap_score'     => $f($r['cap_score']),
            // speed/comm 은 산출하지 않는다(scoring-design.md §2.4). 항상 null.
            'speed_score'   => null,
            'comm_score'    => null,
            'breadth_score' => $f($r['breadth_score']),
            'career_score'  => $f($r['career_score']),
            'manual_adjust' => $f($r['manual_adjust']),
            'adjust_reason' => $r['adjust_reason'],
            'insufficient_data' => (int)$r['insufficient_data'] === 1,
        ];
    }

    /**
     * 점수의 근거가 된 업무 이력.
     *
     * CLAUDE.md: "모든 점수는 원천 근거로 역추적 가능해야 한다."
     * source_url 이 빈 건도 그대로 싣는다 — 빼면 건수가 안 맞아 더 헷갈린다.
     * 화면이 '링크 없음' 을 표시한다.
     */
    public function evidence(int $memberId, string $category = '', ?int $domainId = null,
                             ?int $evalVer = null): array
    {
        // R&D 에서 온 건도 함께 보여 준다 (P10-3). 점수에 들어갔으면
        // 근거에도 보여야 한다 — 되짚을 수 없는 점수를 만들지 않는다(CLAUDE.md).
        $where  = ['w.member_id = ?', "w.source IN ('slack', 'rnd')"];
        $params = [$memberId];

        if ($domainId) {
            $where[]  = 'd.id = ?';
            $params[] = $domainId;
        } elseif ($category !== '') {
            $where[]  = 'd.category = ?';
            $params[] = $category;
        }

        // 그 회차가 본 기간으로 좁힌다. 점수와 근거의 모집단이 같아야 한다.
        $run = $evalVer !== null ? $this->evalRun($evalVer) : null;
        if ($run && !empty($run['period_from']) && !empty($run['period_to'])) {
            $where[]  = 'COALESCE(w.closed_at, w.requested_at) BETWEEN ? AND ?';
            $params[] = $run['period_from'] . ' 00:00:00';
            $params[] = $run['period_to'] . ' 23:59:59';
        }

        // 한 건이 같은 계열의 분야 둘에 걸리면 행이 둘 나온다. 건 단위로 접는다.
        $st = $this->pdo->prepare(
            'SELECT w.id, w.title, w.source_url, w.org_name, w.status_raw,
                    w.difficulty, w.difficulty_by, w.msg_count, w.source, w.source_key,
                    w.requested_at, w.closed_at,
                    GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_no SEPARATOR ", ") AS domains
               FROM bs_work_item w
               JOIN bs_work_item_domain wd ON wd.work_item_id = w.id
               JOIN bs_domain d            ON d.id = wd.domain_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY w.id
              ORDER BY w.difficulty DESC, w.closed_at DESC
              LIMIT 300'
        );
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

}

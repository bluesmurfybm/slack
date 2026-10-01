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
    public function search(array $filter): array
    {
        // TODO(P4): ix_bs_member_assignable (is_assignable, role_label) 를 탄다.
        return [];
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
    public function syncFromPortalUsers(): int
    {
        // TODO(P3): portal_users 를 읽어 bs_member 에 없는 이메일만 넣는다.
        //           지우지는 않는다 — 퇴사자도 과거 기록의 주인이라 남겨야 한다.
        //           대신 is_assignable 을 0 으로 내린다.
        //
        // 주의: **is_evaluable 을 건드리지 말 것.** 사람이 손으로 정한 값이라
        //       동기화가 돌 때마다 1 로 되돌리면 평가 제외가 조용히 풀린다.
        //       새로 들어온 사람만 기본값 1 로 들어간다.
        //
        // 주의: 슬랙 취합 시스템의 담당자 이름으로 구성원을 만들지 말 것.
        //       거기에는 협력사 인력이 섞여 있고, 평가 대상은 자사 구성원뿐이다.
        //       구성원의 원본은 portal_users 하나다.
        return 0;
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

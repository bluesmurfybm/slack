<?php
/**
 * 후보 리스트 API — 프로젝트 조건에 맞는 참여 가능 개발자와 그 근거.
 *
 * GET api/candidate.php?act=list&project_id=1&min_availability=30&min_capability=50&domains[]=3
 * GET api/candidate.php?act=detail&project_id=1&member_id=5
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 이 응답은 "이 프로젝트의 이 조건 기준" 순위다. 전역 순위가 아니다. │
 * │ project_id 를 필수로 받는 이유가 그것이다(명세서 §1.4).           │
 * │ 여러 사람의 점수를 프로젝트 없이 돌려주는 경로를 만들지 말 것.     │
 * │                                                                  │
 * │ **배정을 짜는 사람만 부를 수 있다.**                              │
 * │                                                                  │
 * │ 처음에는 로그인만 보게 두었는데, 그러면 남의 프로파일을 막아 둔    │
 * │ 의미가 사라진다 — profile.php 는 403 을 내는 같은 점수를 이쪽은    │
 * │ 아무에게나 12명분 한 번에 내주고 있었다. 정렬 한 번이면 그게       │
 * │ CLAUDE.md 가 금지한 전사 랭킹이다.                                │
 * │                                                                  │
 * │ 이 화면(2단계 '참여 가능 개발자')은 PM 이 인원을 고르는 자리다.    │
 * │ 일반 구성원이 쓸 일이 없으므로 BS_CAP_ALLOCATION_PROPOSE 로 막는다.│
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
require_once BS_ROOT . '/inc/repo/MemberRepo.php';
require_once BS_ROOT . '/inc/service/AvailabilityCalculator.php';

$pdo      = bs_db();
$projects = new ProjectRepo($pdo);
$members  = new MemberRepo($pdo);
$avail    = new AvailabilityCalculator($pdo);

bs_route(bs_param_str('act', 'list'), [

    'list' => function () use ($projects, $members, $avail, $pdo): void {
        bs_require_login_api();

        $projectId = bs_param_int('project_id', 0);
        if (!$projectId) {
            bs_json_error('MISSING_PARAM',
                '프로젝트 번호가 없습니다. 후보는 과업 기준으로만 낼 수 있습니다.', 400);
        }
        $project = $projects->find($projectId);
        if (!$project) {
            bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }
        // 여러 사람의 점수를 한 번에 내주는 응답이다. 배정을 짜는 사람만 본다.
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, $projectId);

        [$from, $to] = bs_project_window($project);
        if ($from === null) {
            bs_json_error('NO_PERIOD',
                '프로젝트 기간이 비어 있어 가용도를 계산할 수 없습니다. 개발 기간을 먼저 입력하세요.', 400);
        }

        $minAvail = max(0, min(100, bs_param_int('min_availability', 0) ?? 0));
        $minCap   = max(0, min(100, bs_param_int('min_capability', 0) ?? 0));
        $domains  = array_values(array_filter(array_map('intval', bs_param_array('domains'))));

        // 고른 분야들이 속한 계열. 분야가 아니라 **계열 단위로 점수를 본다**
        // (분야 단위로는 표본이 안 찬다 — docs/scoring-design.md §1.3).
        $cats = bs_categories_of($pdo, $domains);

        $evalVer = $members->latestEvalVer();
        $rows    = bs_candidate_rows($pdo, $evalVer, $cats);
        if (!$rows) {
            bs_json_ok([
                'rows' => [], 'total' => 0,
                'scope' => bs_scope($project, $from, $to, $cats, $evalVer),
                'message' => $evalVer === null
                    ? '아직 역량 판정을 돌린 적이 없습니다.'
                    : '평가 대상 구성원이 없습니다.',
            ]);
        }

        $av = $avail->forMembers(array_column($rows, 'member_id'), $from, $to);

        $out = [];
        foreach ($rows as $r) {
            $a   = $av[$r['member_id']] ?? null;
            $fit = bs_fit_score($r, $a);

            $row = [
                'member_id'   => $r['member_id'],
                'emp_name'    => $r['emp_name'],
                'role_label'  => $r['role_label'],
                'team'        => $r['team'],

                // 가용도 — 확정과 추정을 **분리해서** 담는다. 합치지 않는다.
                // 확정은 다시 프로젝트와 R&D 로 나눠 담는다(P10-2).
                // 합산 숫자만 보여 주는 자리를 만들지 않는다.
                'availability' => $a ? [
                    'available_pct' => $a['available_pct'],
                    'confirmed_pct' => $a['confirmed_pct'],
                    'project_pct'   => $a['project_pct'],
                    'rnd_pct'       => $a['rnd_pct'],
                    'inferred_pct'  => $a['inferred_pct'],
                    'confidence'    => $a['confidence'],
                    'workdays'      => $a['workdays'],
                    'base_capacity' => $a['base_capacity'],
                    'capacity_pct'  => $a['capacity_pct'],
                    // 기간 평균이 여유로워도 **특정 달에 꽉 찬** 사람이 있다.
                    // 목록에서 바로 경고할 수 있게 넘긴다. 달별 띠 전체는
                    // 무거우니 근거 드로어(detail)에서만 준다.
                    'over_months'   => $a['over_months'],
                    'peak_pct'      => $a['peak_pct'],
                    'aidd_on'       => $a['aidd_on'],
                    'aidd_pct'      => $a['aidd_pct'],
                ] : null,

                'domain_fit'   => $r['domain_fit'],       // 고른 계열 평균. 없으면 null
                'capability'   => $r['cap_score'],
                'breadth'      => $r['breadth_score'],
                'career'       => $r['career_score'],
                'active_items' => $a['active_items'] ?? 0,

                // 표본 부족은 '낮은 점수' 가 아니다. 화면이 구분해 그려야 한다.
                // 평가 제외는 또 다른 상태다 — 표본이 영영 차지 않는다.
                'insufficient_data' => $r['insufficient_data'],
                'evaluable'         => $r['evaluable'],
                'matched_categories' => $r['matched'],

                'fit_score' => $fit,
            ];

            // 조건 필터. 표본 부족인 사람은 **점수 조건으로 걸러내지 않는다** —
            // 점수가 없는 것이지 낮은 것이 아니다. 대신 별도 구간으로 내보낸다.
            $row['filtered_out'] = false;
            if ($a && $a['available_pct'] < $minAvail) {
                $row['filtered_out'] = true;
                $row['filter_reason'] = '가용도 미달';
            } elseif (!$r['insufficient_data'] && $r['cap_score'] !== null
                      && $r['cap_score'] < $minCap) {
                $row['filtered_out'] = true;
                $row['filter_reason'] = '역량 조건 미달';
            }
            $out[] = $row;
        }

        // 정렬 — 이 프로젝트 기준 적합도 순.
        // 점수를 낼 수 없는 사람(fit_score === null)은 **뒤로 보내되 지우지 않는다.**
        // 0 점으로 깔면 그 사람은 영원히 배정되지 않는다(scoring-design.md §5).
        usort($out, static function (array $x, array $y): int {
            if ($x['filtered_out'] !== $y['filtered_out']) {
                return $x['filtered_out'] ? 1 : -1;
            }
            $xn = $x['fit_score'] === null;
            $yn = $y['fit_score'] === null;
            if ($xn !== $yn) {
                return $xn ? 1 : -1;
            }
            if ($xn) {
                return strcmp((string)$x['emp_name'], (string)$y['emp_name']);
            }
            return $y['fit_score'] <=> $x['fit_score'];
        });

        bs_json_ok([
            'rows'  => $out,
            'total' => count($out),
            'scope' => bs_scope($project, $from, $to, $cats, $evalVer),
        ]);
    },

    /**
     * 한 사람의 근거 — 현재 점유 내역 + 최근 처리 건.
     * 후보 표의 행을 눌렀을 때 드로어에 뿌린다.
     */
    'detail' => function () use ($projects, $members, $avail): void {
        bs_require_login_api();

        $projectId = bs_param_int('project_id', 0);
        $memberId  = bs_param_int('member_id', 0);
        if (!$projectId || !$memberId) {
            bs_json_error('MISSING_PARAM', '프로젝트와 구성원을 지정하세요.', 400);
        }
        $project = $projects->find($projectId);
        if (!$project) {
            bs_json_error('NOT_FOUND', '프로젝트를 찾을 수 없습니다.', 404);
        }
        // 계열별 점수까지 내주는 응답이다. 목록과 같은 선을 쓴다.
        bs_require_cap_api(BS_CAP_ALLOCATION_PROPOSE, $projectId);

        $member = $members->find($memberId);
        if (!$member) {
            bs_json_error('NOT_FOUND', '구성원을 찾을 수 없습니다.', 404);
        }

        [$from, $to] = bs_project_window($project);
        if ($from === null) {
            bs_json_error('NO_PERIOD', '프로젝트 기간이 비어 있습니다.', 400);
        }

        $a       = $avail->forMember($memberId, $from, $to);
        $evalVer = $members->latestEvalVer();

        // 최근 처리 건은 **근거**다. 프로파일 열람 권한과 같은 선을 적용한다.
        $canSeeEvidence = bs_can_view_profile((string)$member['user_id'], $projectId);
        $recent = $canSeeEvidence && $evalVer !== null
            ? array_slice($members->evidence($memberId, '', null, $evalVer), 0, 20)
            : [];

        bs_json_ok([
            'member' => [
                'id'         => (int)$member['id'],
                'emp_name'   => $member['emp_name'],
                'role_label' => $member['role_label'],
                'team'       => $member['team'],
            ],
            'period'      => ['from' => $from, 'to' => $to, 'workdays' => $a['workdays']],
            'availability' => [
                'available_pct' => $a['available_pct'],
                'confirmed_pct' => $a['confirmed_pct'],
                'project_pct'   => $a['project_pct'],
                'rnd_pct'       => $a['rnd_pct'],
                'inferred_pct'  => $a['inferred_pct'],
                'confidence'    => $a['confidence'],
                'base_capacity' => $a['base_capacity'],
                'capacity_pct'  => $a['capacity_pct'],
                // 배정 화면은 M/D 로만 말하고 이 화면은 % 로만 말해서, 같은
                // 사람의 두 숫자가 이어지는지 알 수가 없었다. 여기서 잇는다.
                'available'     => $a['available'],
                'workdays'      => $a['workdays'],
                // 달별 띠. 평균이 지우는 것을 되살린다.
                'months'        => $a['months'],
                'over_months'   => $a['over_months'],
                'peak_pct'      => $a['peak_pct'],
                // AIDD 여유는 available 과 **합치지 않는다.** 화면이 두
                // 숫자를 나란히 놓고 사람이 가려 읽는다.
                'aidd_on'       => $a['aidd_on'],
                'aidd_factor'   => $a['aidd_factor'],
                'aidd_pct'      => $a['aidd_pct'],
                'available_aidd'=> $a['available_aidd'],
            ],
            // 확정 내역과 추정 내역을 따로 담는다. 화면이 섞지 못하게.
            'confirmed_breakdown' => $a['breakdown'],
            'inferred_items'      => $a['inferred_items'],
            'categories' => $evalVer !== null ? $members->categoryScores($memberId, $evalVer) : [],
            'recent'     => array_map('bs_present_candidate_evidence', $recent),
            'can_see_evidence' => $canSeeEvidence,
            // 슬랙·메일에 안 잡히는 업무를 여기서 넣을 수 있는가.
            // 판단은 api/workload.php 와 같은 규칙을 쓴다 — 두 벌로 두면
            // 화면에는 단추가 보이는데 눌리면 403 이 나는 일이 생긴다.
            'can_add_workload' => bs_can_edit_workload((string)$member['user_id']),
        ]);
    },
]);


// =====================================================================
// 공통
// =====================================================================

/**
 * 프로젝트의 가용도 판정 기간.
 * 개발 기간이 기본이고, 비어 있으면 테스트·배포일로 넓힌다.
 */
function bs_project_window(array $p): array
{
    $from = $p['dev_start'] ?: ($p['test_start'] ?: null);
    $to   = $p['deploy_date'] ?: ($p['test_end'] ?: ($p['dev_end'] ?: null));
    if ($from === null || $to === null || $from > $to) {
        return [null, null];
    }
    return [$from, $to];
}

/** 고른 분야들이 속한 계열 코드. 중복 제거. */
function bs_categories_of(PDO $pdo, array $domainIds): array
{
    if (!$domainIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($domainIds), '?'));
    $st = $pdo->prepare("SELECT DISTINCT category FROM bs_domain WHERE id IN ($ph)");
    $st->execute($domainIds);
    return array_values(array_filter(
        $st->fetchAll(PDO::FETCH_COLUMN),
        static fn($c) => $c !== null && $c !== '' && !in_array($c, BS_CATEGORY_NOT_SCORED, true)
    ));
}

/**
 * 평가 대상 구성원 + 역량 지표 + 고른 계열의 평균 점수.
 *
 * 평가 제외자(is_evaluable=0)는 여기서 빠진다. 점수가 없는 사람을
 * 후보 표에 0 점으로 올리면 안 된다.
 */
function bs_candidate_rows(PDO $pdo, ?int $evalVer, array $cats): array
{
    // ┌──────────────────────────────────────────────────────────────┐
    // │ 거르는 칸은 `is_assignable` 이다. `is_evaluable` 이 아니다.   │
    // │                                                              │
    // │   is_assignable = 0   배정 후보로 올리지 않는다 (휴직·퇴사,   │
    // │                       개발 사업과 무관한 직무)                │
    // │   is_evaluable  = 0   역량 점수를 내지 않는다 (이 데이터로    │
    // │                       평가할 수 없는 직무)                    │
    // │                                                              │
    // │ 전에는 `is_evaluable = 1` 로 걸렀다. 둘을 맞바꿔 쓴 것이라    │
    // │ 반대로 돌았다 — 배정에서 빼 둔 사람이 후보에 그대로 나오고,  │
    // │ 점수만 못 내는 기획 담당자가 후보에서 사라졌다. 스키마 주석이 │
    // │ "기획 과업에는 배정되어야 한다" 고 적어 둔 바로 그 경우다.    │
    // │                                                              │
    // │ 점수가 없는 사람도 후보에는 올린다. 점수가 '없는' 것이지      │
    // │ '낮은' 것이 아니므로 화면이 구분해 그린다.                    │
    // └──────────────────────────────────────────────────────────────┘
    if ($evalVer === null) {
        // 판정 전이라도 명단은 보여 준다 — 점수 없이.
        $st = $pdo->prepare(
            'SELECT id AS member_id, emp_name, role_label, team, is_evaluable
               FROM bs_member WHERE is_assignable = 1 ORDER BY emp_name'
        );
        $st->execute();
        return array_map(static function (array $r): array {
            return $r + ['cap_score' => null, 'breadth_score' => null, 'career_score' => null,
                         'domain_fit' => null, 'matched' => [], 'insufficient_data' => true];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    $st = $pdo->prepare(
        'SELECT m.id AS member_id, m.emp_name, m.role_label, m.team, m.is_evaluable,
                t.cap_score, t.breadth_score, t.career_score, t.insufficient_data
           FROM bs_member m
      LEFT JOIN bs_member_metric t ON t.member_id = m.id AND t.eval_ver = ?
          WHERE m.is_assignable = 1
          ORDER BY m.emp_name'
    );
    $st->execute([$evalVer]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    // 고른 계열의 점수를 붙인다. 계열을 안 골랐으면 domain_fit 은 null 이다
    // (조건이 없으니 분야 적합도를 말할 수 없다).
    $byMember = [];
    if ($cats) {
        $mph = implode(',', array_fill(0, count($rows), '?'));
        $cph = implode(',', array_fill(0, count($cats), '?'));
        $q = $pdo->prepare(
            "SELECT member_id, category, score, case_count, confidence, insufficient_data
               FROM bs_member_category
              WHERE eval_ver = ? AND member_id IN ($mph) AND category IN ($cph)"
        );
        $q->execute(array_merge([$evalVer], array_column($rows, 'member_id'), $cats));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byMember[(int)$r['member_id']][] = $r;
        }
    }

    foreach ($rows as &$r) {
        $mid  = (int)$r['member_id'];
        $r['member_id'] = $mid;
        $r['cap_score']     = $r['cap_score'] !== null ? (float)$r['cap_score'] : null;
        $r['breadth_score'] = $r['breadth_score'] !== null ? (float)$r['breadth_score'] : null;
        $r['career_score']  = $r['career_score'] !== null ? (float)$r['career_score'] : null;
        $r['insufficient_data'] = (int)($r['insufficient_data'] ?? 1) === 1;
        // "데이터가 모자랍니다" 와 "평가 대상이 아닙니다" 는 다른 말이다
        // (CLAUDE.md). 평가 제외자는 표본이 영영 안 차므로 '표본 부족' 으로
        // 적으면 거짓말이 된다. 화면이 가려 쓰도록 칸을 따로 내려보낸다.
        $r['evaluable'] = (int)($r['is_evaluable'] ?? 1) === 1;
        unset($r['is_evaluable']);

        $matched = [];
        $sum = 0.0; $n = 0;
        foreach ($byMember[$mid] ?? [] as $c) {
            $has = (int)$c['insufficient_data'] === 0 && $c['score'] !== null;
            $matched[] = [
                'category'   => $c['category'],
                'label'      => BS_DOMAIN_CATEGORY[$c['category']]['label'] ?? $c['category'],
                'score'      => $has ? (float)$c['score'] : null,
                'case_count' => (int)$c['case_count'],
                'confidence' => $c['confidence'],
            ];
            if ($has) { $sum += (float)$c['score']; $n++; }
        }
        $r['matched']    = $matched;
        $r['domain_fit'] = $n > 0 ? round($sum / $n, 2) : null;
    }
    unset($r);

    return $rows;
}

/**
 * 이 프로젝트 기준 적합도.
 *
 * 가중치는 docs/scoring-design.md §3 을 따른다.
 *   cap 0.55 / domain_fit 0.25 / breadth 0.10 / career 0.10
 * speed·comm 은 산출하지 않으므로 들어가지 않는다.
 *
 * 가용도는 점수에 **섞지 않는다.** 가용도는 "지금 시간이 있는가" 이고
 * 적합도는 "이 일을 할 수 있는가" 다. 섞으면 바쁜 전문가가 초보보다
 * 낮게 나온다. 화면에서 두 축을 나란히 보여 주고 사람이 판단한다.
 *
 * @return float|null 점수를 낼 수 없으면 null — 0 이 아니다
 */
function bs_fit_score(array $r, ?array $avail): ?float
{
    if ($r['insufficient_data'] && $r['domain_fit'] === null) {
        return null;    // 판단 보류
    }
    $parts = [];
    if ($r['cap_score'] !== null)     { $parts[] = [0.55, $r['cap_score']]; }
    if ($r['domain_fit'] !== null)    { $parts[] = [0.25, $r['domain_fit']]; }
    if ($r['breadth_score'] !== null) { $parts[] = [0.10, $r['breadth_score']]; }
    if ($r['career_score'] !== null)  { $parts[] = [0.10, $r['career_score']]; }
    if (!$parts) {
        return null;
    }
    // 빠진 요소가 있으면 남은 가중치로 정규화한다. 없는 값을 0 으로 치면
    // 그 사람만 부당하게 낮아진다.
    $wsum = array_sum(array_column($parts, 0));
    $ssum = 0.0;
    foreach ($parts as [$w, $v]) { $ssum += $w * $v; }
    return round($ssum / $wsum, 2);
}

function bs_scope(array $project, ?string $from, ?string $to, array $cats, ?int $evalVer): array
{
    return [
        'project_id'   => (int)$project['id'],
        'project_name' => $project['name'],
        'period'       => ['from' => $from, 'to' => $to],
        'categories'   => array_map(
            static fn($c) => ['code' => $c, 'label' => BS_DOMAIN_CATEGORY[$c]['label'] ?? $c],
            $cats
        ),
        'eval_ver'          => $evalVer,
        // 화면이 "전역 순위 아님" 을 표시할 수 있게 한다.
        'is_global_ranking' => false,
    ];
}

function bs_present_candidate_evidence(array $r): array
{
    return [
        'id'         => (int)$r['id'],
        'title'      => $r['title'],
        'source_url' => $r['source_url'],
        'org_name'   => $r['org_name'],
        'status_raw' => $r['status_raw'],
        'difficulty' => $r['difficulty'] !== null ? (int)$r['difficulty'] : null,
        'domains'    => $r['domains'] ?? null,
        'closed_at'  => bs_date($r['closed_at']),
    ];
}

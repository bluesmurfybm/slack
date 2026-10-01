<?php
/** 수집된 업무 이력에서 구성원 역량 점수를 산출하는 서비스. 명세서 §4 의 점수식을 담는다. */

declare(strict_types=1);

/**
 * 역량 판정기.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 반드시 지킬 것 (CLAUDE.md)                                        │
 * │                                                                  │
 * │ 1. 정규화는 **역할(role_label) 그룹 안에서** 한다.                 │
 * │    백엔드와 퍼블리셔를 같은 축에 올려 비교하지 않는다.             │
 * │ 2. 표본이 BS_MIN_SAMPLE 미만이면 낮은 점수를 주는 대신             │
 * │    insufficient_data 플래그를 세운다.                             │
 * │ 3. 모든 점수는 근거(bs_work_item)로 역추적 가능해야 한다.          │
 * │ 4. 이건 인사평가가 아니라 배정 보조다.                             │
 * │                                                                  │
 * │ 이 원칙을 우회하는 변경을 요청받으면 먼저 지적하고 확인을 구할 것.  │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 판정은 결정론적이어야 한다. LLM 에 최종 점수를 맡기지 않는다.
 * LLM 은 난이도 판정 보조까지만(§4.3).
 */
final class CapabilityScorer
{
    public function __construct(
        private MemberRepo $members,
        private WorkItemRepo $workItems,
    ) {}

    // -----------------------------------------------------------------
    // 입구
    // -----------------------------------------------------------------

    /**
     * 한 회차 재판정을 통째로 돌린다.
     *
     * 1. bs_eval_run 시작 (id 가 곧 eval_ver)
     * 2. 대상 기간의 업무 이력을 읽어 사람×분야로 모은다
     * 3. 난이도 가중 처리량·리드타임·재작업률 계산
     * 4. 역할 그룹 안에서 정규화
     * 5. bs_member_skill / bs_member_metric 에 스냅샷 저장
     * 6. bs_eval_run 종료
     *
     * @return int 만들어진 eval_ver
     */
    public function run(?string $periodFrom, ?string $periodTo, string $formulaVer = 'v1.0'): int
    {
        // TODO(P3): 중간에 실패하면 bs_eval_run.status 를 fail 로 남기고 예외를 던진다.
        //           반쪽짜리 스냅샷이 latestEvalVer() 에 잡히면 안 된다.
        //
        // 대상은 **MemberRepo::evaluable()** 로 가져온다. 전원을 훑지 말 것.
        // 평가 제외자(is_evaluable=0)까지 돌리면 0점짜리 스냅샷이 쌓이고,
        // 그 0점이 정규화 분모에 들어가 다른 사람 점수까지 밀어 올린다.
        //
        // 모집단도 자사 구성원 것만이다. bs_work_item 에는 협력사가 처리한 건이
        // member_id=NULL 로 함께 들어 있으므로, 집계·정규화 쿼리는 항상
        // member_id IS NOT NULL 로 한정한다.
        // (근거: docs/data-quality-report.md — 6개월 1457건 중 289건이 그렇다)
        return 0;
    }

    // -----------------------------------------------------------------
    // §4.2 정규화
    // -----------------------------------------------------------------

    /**
     * 역할 그룹 안에서 0~100 으로 정규화한다.
     *
     * @param array $rawByMember [member_id => raw]
     * @param array $roleOf      [member_id => role_label]
     * @return array [member_id => 0~100]
     */
    public function normalizeWithinRole(array $rawByMember, array $roleOf): array
    {
        // TODO(P3): 그룹 인원이 너무 적으면(예: 1~2명) 정규화가 의미를 잃는다.
        //           그 경우 어떻게 할지 정해야 한다 — 전체 평균으로 두거나 점수를 비우거나.
        return [];
    }

    // -----------------------------------------------------------------
    // §4.3 난이도 판정 (1~5)
    // -----------------------------------------------------------------

    /**
     * 규칙 기반 난이도. 결정론적이어야 한다.
     * 입력 예: 스레드 왕복 수, 본문 길이, 재오픈 횟수, 리드타임, 분야.
     */
    public function judgeDifficultyByRule(array $workItem): int
    {
        // TODO(P2): 규칙을 여기 한 곳에만 둔다. 수집기(Python)와 규칙이 갈리면
        //           같은 건이 다른 난이도를 받는다 — 어느 쪽이 원본인지 먼저 정할 것.
        return 1;
    }

    /**
     * LLM 보조 난이도. 규칙으로 가르기 애매한 건만 맡긴다.
     * spec §11-5(외부 API 반출 정책)이 정해지기 전에는 부르지 않는다.
     */
    public function judgeDifficultyByLlm(array $workItem): ?int
    {
        // TODO(P7)
        return null;
    }

    // -----------------------------------------------------------------
    // §4.4 점수 산출
    // -----------------------------------------------------------------

    /**
     * 분야 역량 점수.
     * 입력: 난이도 가중 처리량, 평균 리드타임, 재작업률, 처리 건수.
     *
     * @return array score / case_count / weighted_qty / avg_lead_hr / rework_rate
     *               / insufficient_data 를 담은 배열
     */
    public function domainScore(int $memberId, int $domainId, array $workItems): array
    {
        // TODO(P3): count($workItems) < BS_MIN_SAMPLE 이면 insufficient_data=1 로 두고
        //           score 는 채우지 않는다(null). 0 을 넣으면 "못하는 사람" 이 된다.
        return [];
    }

    /** 개발 역량 — 난이도·처리량·속도·재작업을 묶은 값. */
    public function capScore(array $workItems): ?float
    {
        // TODO(P3)
        return null;
    }

    /** 처리 속도 — 요청 → 첫 응답 → 배포까지의 리드타임. */
    public function speedScore(array $workItems): ?float
    {
        // TODO(P3): 리드타임은 분야마다 자연스러운 길이가 다르다.
        //           인프라 작업과 오탈자 수정을 같은 자로 재지 않도록 주의.
        return null;
    }

    /** 소통·분석 역량 — 스레드 왕복 수, 첫 응답 시간, 본문 길이 등. */
    public function commScore(array $workItems): ?float
    {
        // TODO(P3): 왕복이 많은 것이 꼭 나쁜 신호는 아니다(어려운 건일 수 있다).
        //           난이도로 보정할지 정해야 한다.
        return null;
    }

    /** 분야 커버리지 — 몇 개 분야를 얼마나 고르게 다뤘는지. */
    public function breadthScore(array $countByDomain): ?float
    {
        // TODO(P3)
        return null;
    }

    /** 경력 환산 — bs_member.career_months 기반. */
    public function careerScore(int $careerMonths): float
    {
        // TODO(P3): 경력이 길수록 단조 증가하되 어느 지점부터 완만해지게.
        return 0.0;
    }

    // -----------------------------------------------------------------
    // 근거 추적
    // -----------------------------------------------------------------

    /**
     * 이 점수가 어떤 건들에서 나왔는지.
     * 프로파일 화면과 api/candidate.php?act=evidence 가 그대로 보여준다.
     *
     * source_url 이 빠진 항목을 돌려주지 말 것 — 근거로 쓸 수 없다.
     */
    public function evidenceFor(int $memberId, int $domainId, ?int $evalVer = null): array
    {
        // TODO(P3): WorkItemRepo::evidence() 를 감싸 화면용으로 다듬는다.
        return [];
    }
}

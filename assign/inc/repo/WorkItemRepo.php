<?php
/** ba_work_item / ba_work_item_domain / ba_sync_log 접근 담당 DAO. 수집된 원천 업무 이력을 다룬다. */

declare(strict_types=1);

/**
 * 원천 업무 이력 저장소.
 *
 * 이 표는 **모든 점수의 근거**다(CLAUDE.md). 조회 결과에서 source_url 을
 * 빼지 말 것 — 근거 링크가 없으면 이의 제기에 답할 수 없다.
 *
 * 적재는 Python 수집기(collector/)가 직접 하지만, 화면 조회와 수동 보정을
 * 위해 PHP 쪽에서도 같은 표를 읽는다. 두 쪽이 같은 규칙으로 움직여야 하므로
 * upsert 규칙을 바꿀 때는 수집기도 함께 고친다.
 */
final class WorkItemRepo
{
    public function __construct(private PDO $pdo) {}

    // -----------------------------------------------------------------
    // 조회
    // -----------------------------------------------------------------

    public function find(int $id): ?array
    {
        // TODO(P2)
        return null;
    }

    /** 원천 고유키로 찾는다. 중복 적재 방지용. */
    public function findBySourceKey(string $source, string $sourceKey): ?array
    {
        // TODO(P2): uk_ba_witem_source (source, source_key) 를 탄다.
        return null;
    }

    /**
     * 한 사람의 기간 내 업무 이력. 역량 판정의 입력.
     *
     * @param string|null $from 'YYYY-MM-DD'
     * @param string|null $to   'YYYY-MM-DD'
     */
    public function byMemberPeriod(int $memberId, ?string $from, ?string $to): array
    {
        // TODO(P3): ix_ba_witem_member (member_id, closed_at) 를 탄다.
        return [];
    }

    /**
     * 기간 전체. 정규화 모집단을 만들 때 쓴다.
     * 건수가 많아질 수 있으니 필요한 컬럼만 고른다.
     */
    public function byPeriod(?string $from, ?string $to): array
    {
        // TODO(P3): ix_ba_witem_closed (closed_at) 를 탄다.
        return [];
    }

    /**
     * 특정 사람의 특정 분야 처리 건 — 점수의 근거로 화면에 그대로 보여줄 목록.
     * api/candidate.php?act=evidence 가 이걸 쓴다.
     */
    public function evidence(int $memberId, int $domainId, ?int $evalVer = null, int $limit = 50): array
    {
        // TODO(P3): ba_work_item_domain 과 조인. source_url 을 반드시 싣는다.
        return [];
    }

    /** 담당자를 못 찾아 member_id 가 비어 있는 건. 손으로 이어 붙일 목록. */
    public function unmatched(int $limit = 100): array
    {
        // TODO(P2): ix_ba_witem_org 로 기관명 단서를 같이 본다.
        return [];
    }

    // -----------------------------------------------------------------
    // 변경
    // -----------------------------------------------------------------

    /**
     * 수집 결과를 넣거나 갱신한다(있으면 update).
     *
     * @return int ba_work_item.id
     */
    public function upsert(array $data): int
    {
        // TODO(P2): uk_ba_witem_source 기준. Python 수집기와 같은 규칙으로 맞춘다.
        return 0;
    }

    /** 담당자를 손으로 이어 붙인다. */
    public function assignMember(int $workItemId, int $memberId): void
    {
        // TODO(P2)
    }

    /** 난이도를 손으로 고친다. difficulty_by 가 manual 로 바뀐다. */
    public function setDifficulty(int $workItemId, int $difficulty, string $by, array $actor): void
    {
        // TODO(P2): $by 는 rule|llm|manual
    }

    // -----------------------------------------------------------------
    // 분야 연결 (ba_work_item_domain)
    // -----------------------------------------------------------------

    public function domains(int $workItemId): array
    {
        // TODO(P2)
        return [];
    }

    /** ba_domain.keywords 매칭 결과를 통째로 교체한다. */
    public function replaceDomains(int $workItemId, array $domainConfidence): void
    {
        // TODO(P2): $domainConfidence = [domain_id => confidence]
    }

    /**
     * 분야별 처리 건수. 역량 점수의 case_count 입력이자
     * 표본 부족(BA_MIN_SAMPLE) 판정의 기준이 된다.
     *
     * @return array [domain_id => count]
     */
    public function countByDomain(int $memberId, ?string $from, ?string $to): array
    {
        // TODO(P3)
        return [];
    }

    // -----------------------------------------------------------------
    // 수집 로그 (ba_sync_log)
    // -----------------------------------------------------------------

    public function startSync(string $source): int
    {
        // TODO(P2): $source 는 slack|gmail
        return 0;
    }

    public function finishSync(int $syncId, array $counts, string $status, ?string $message = null): void
    {
        // TODO(P2): $counts = ['fetched'=>, 'inserted'=>, 'updated'=>, 'skipped'=>]
    }

    /** 이 원천을 마지막으로 언제까지 긁었나. 증분 수집의 기준점. */
    public function lastSync(string $source): ?array
    {
        // TODO(P2): ix_ba_synclog_src (source, id) 를 탄다.
        return null;
    }
}

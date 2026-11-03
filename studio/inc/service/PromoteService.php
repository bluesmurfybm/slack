<?php
/**
 * 아이디어 → R&D 과제 → 프로젝트 로 올리는 길 (032).
 *
 *   아이디어 ──→ R&D 과제 ──→ 프로젝트
 *        └───────(건너뛰기)──────┘
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 올린다고 단계를 건너뛰지 않는다                                   │
 * │                                                                  │
 * │ · 과제는 **발의됨**으로 시작한다. 승인(점유 통제, CLAUDE.md §2)은 │
 * │   그대로 거친다. 아이디어가 채택됐다고 과제 승인을 대신하지 않는다.│
 * │ · 프로젝트는 **draft** 로 시작한다. WBS·배정 흐름은 그대로다.     │
 * │                                                                  │
 * │ 생성과 "넘어갔다" 표시는 한 트랜잭션이다. 과제만 생기고 아이디어는│
 * │ 그대로 남으면 같은 아이디어로 과제가 두 번 만들어진다.            │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 알림은 트랜잭션이 끝난 뒤에 보낸다 — 알림이 실패해도 올리기는 끝난다.
 *
 * 아이디어의 참고 자료(033)는 새 과제·프로젝트의 **참고 자료로 복사**한다.
 * 파일도 따로 복사한다 — 한쪽에서 지워도 다른 쪽은 남는다.
 */

declare(strict_types=1);

require_once __DIR__ . '/../repo/IdeaRepo.php';
require_once __DIR__ . '/../repo/RndRepo.php';
require_once __DIR__ . '/../repo/ProjectRepo.php';
require_once __DIR__ . '/IdeaNotice.php';

final class PromoteService
{
    public function __construct(
        private PDO $pdo,
        private IdeaRepo $ideas,
        private RndRepo $rnds,
        private ProjectRepo $projects,
    ) {}

    /**
     * 아이디어 → R&D 과제(발의됨).
     *
     * 이름·개요·갈래·분야는 아이디어에서 가져오고, $data 로 덮어쓸 수 있다
     * (기간·공개 범위·점유율처럼 아이디어에 없는 것은 $data 로만 온다).
     * 발의자는 **올리는 사람**이다 — 과제를 책임질 사람이기 때문이다.
     * 아이디어를 낸 사람은 출발 아이디어 연결로 남는다.
     */
    public function ideaToRnd(int $ideaId, array $data, array $actor): int
    {
        $idea = $this->ideas->find($ideaId);
        if (!$idea || !bs_idea_can_promote_rnd($idea)) {
            throw new DomainException('이 아이디어는 지금 R&D 과제로 올릴 수 없습니다.');
        }
        $domainIds = array_column($this->ideas->domainsOf([$ideaId])[$ideaId] ?? [], 'id');
        $copied    = [];   // 참고 자료 파일 복사본 — 되돌리면 지운다

        $this->pdo->beginTransaction();
        try {
            $rndId = $this->rnds->propose([
                'name'         => $data['name']    ?? $idea['title'],
                'summary'      => $data['summary'] ?? $this->summaryOf($idea),
                'rnd_category' => array_key_exists('rnd_category', $data) ? $data['rnd_category'] : $idea['rnd_category'],
                'domain_ids'   => $data['domain_ids'] ?? $domainIds,
                // 나누려고 올린 아이디어에서 왔으니 기본은 열어 둔다.
                'visibility'   => $data['visibility'] ?? 'open',
                'recruiting'   => $data['recruiting'] ?? 1,
                'dev_start'    => $data['dev_start'] ?? null,
                'dev_end'      => $data['dev_end']   ?? null,
                'load_cap'     => $data['load_cap']  ?? null,
            ], $actor);
            $this->setOrigin($rndId, $ideaId, null);
            $this->ideas->copySourcesTo($ideaId, $rndId, $actor, $copied);
            $code = $this->codeOf($rndId);
            $this->ideas->markPromoted($ideaId, $rndId, "R&D 과제 $code", $actor);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->unlinkAll($copied);
            throw $e;
        }

        IdeaNotice::promoted($idea, $this->ideas->participants($ideaId), $actor,
                             'R&D 과제', 'studio/rnd_view.php?id=' . $rndId);
        return $rndId;
    }

    /**
     * 아이디어 → 프로젝트(draft). 과제 단계를 건너뛰는 길.
     * 프로젝트 등록 권한(027)이 있어야 한다 — presenter 가 본다.
     */
    public function ideaToProject(int $ideaId, array $data, array $actor): int
    {
        $idea = $this->ideas->find($ideaId);
        if (!$idea || !bs_idea_can_promote_project($idea)) {
            throw new DomainException('이 아이디어는 지금 프로젝트로 올릴 수 없습니다.');
        }
        $copied = [];   // 참고 자료 파일 복사본 — 되돌리면 지운다

        $this->pdo->beginTransaction();
        try {
            $pid = $this->projects->create($this->projectData($data, $idea['title'], $this->summaryOf($idea)), $actor);
            $this->setOrigin($pid, $ideaId, null);
            $this->ideas->copySourcesTo($ideaId, $pid, $actor, $copied);
            $code = $this->codeOf($pid);
            $this->ideas->markPromoted($ideaId, $pid, "프로젝트 $code", $actor);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->unlinkAll($copied);
            throw $e;
        }

        IdeaNotice::promoted($idea, $this->ideas->participants($ideaId), $actor,
                             '프로젝트', 'studio/project_view.php?id=' . $pid);
        return $pid;
    }

    /**
     * R&D 과제 → 프로젝트(draft). 승인된 뒤(승인됨·진행 중·종료)에만.
     *
     * 과제는 그대로 둔다 — 산출물과 진행 기록, 점유가 과제에 남아 있어야
     * 한다. 한 과제에서 살아 있는 프로젝트는 하나만 만든다.
     * 과제가 아이디어에서 왔으면 그 아이디어도 프로젝트에 이어 적는다.
     */
    public function rndToProject(int $rndId, array $data, array $actor): int
    {
        $rnd = $this->rnds->find($rndId);
        if (!$rnd || !bs_rnd_can_promote_project($rnd)) {
            throw new DomainException('이 과제는 지금 프로젝트로 넘길 수 없습니다. 승인된 과제의 주도자·관리자가 프로젝트 등록 권한이 있을 때 넘깁니다.');
        }
        $st = $this->pdo->prepare(
            "SELECT code FROM bs_project
              WHERE origin_project_id = ? AND project_type = 'project' AND deleted_at IS NULL LIMIT 1");
        $st->execute([$rndId]);
        if ($dup = $st->fetchColumn()) {
            throw new DomainException("이 과제에서 이미 프로젝트 $dup 를 만들었습니다.");
        }

        $this->pdo->beginTransaction();
        try {
            $pid = $this->projects->create(
                $this->projectData($data, (string)$rnd['name'], $rnd['summary'] ?? null), $actor);
            $originIdea = $this->scalar('SELECT origin_idea_id FROM bs_project WHERE id = ?', [$rndId]);
            $this->setOrigin($pid, $originIdea !== null ? (int)$originIdea : null, $rndId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $pid;
    }

    // ------------------------------------------------------------------

    /** 프로젝트에 넘길 값. 이름·개요 말고는 $data 에서만 온다(기간·사업 유형 등). */
    private function projectData(array $data, string $name, ?string $summary): array
    {
        $out = ['name' => $data['name'] ?? $name, 'summary' => $data['summary'] ?? $summary];
        foreach (['client', 'track', 'dev_start', 'dev_end', 'test_start', 'test_end', 'deploy_date'] as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $out[$k] = $data[$k];
            }
        }
        if (trim((string)$out['name']) === '') {
            throw new InvalidArgumentException('프로젝트명을 입력하세요.');
        }
        return $out;
    }

    /** 아이디어 본문을 과제·프로젝트 개요 한 덩이로. */
    private function summaryOf(array $idea): ?string
    {
        $parts = [];
        foreach (['problem' => '배경', 'proposal' => '제안', 'effect' => '기대 효과'] as $k => $label) {
            if (trim((string)($idea[$k] ?? '')) !== '') {
                $parts[] = "[$label]\n" . trim((string)$idea[$k]);
            }
        }
        $parts[] = '— 출발 아이디어 ' . $idea['code'];
        return implode("\n\n", $parts);
    }

    /** 트랜잭션이 되돌려졌을 때 복사해 둔 파일을 치운다. 행은 롤백으로 이미 없다. */
    private function unlinkAll(array $paths): void
    {
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    private function setOrigin(int $projectId, ?int $ideaId, ?int $rndId): void
    {
        $this->pdo->prepare('UPDATE bs_project SET origin_idea_id = ?, origin_project_id = ? WHERE id = ?')
                  ->execute([$ideaId, $rndId, $projectId]);
    }

    private function codeOf(int $projectId): string
    {
        return (string)$this->scalar('SELECT code FROM bs_project WHERE id = ?', [$projectId]);
    }

    private function scalar(string $sql, array $par): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($par);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }
}

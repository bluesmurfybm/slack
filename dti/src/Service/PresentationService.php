<?php

namespace Dti\Service;

use Dti\Config;
use Dti\Entity\Presentation;
use Dti\Identity\Identity;
use Dti\Repository\EmotionRepository;
use Dti\Repository\PresentationRepository;

final class PresentationService
{
    public function __construct(
        private readonly PresentationRepository $presentations,
        private readonly EmotionRepository $emotions,
        private readonly Storage $storage,
    ) {}

    public function create(int $topicId, array $values = []): Presentation
    {
        $pres = new Presentation();
        $pres->topic_id = $topicId;
        $pres->created_at = date('Y-m-d H:i:s');
        foreach ($values as $field => $value) {
            $pres->$field = $value;
        }
        $this->presentations->insert($pres);
        return $pres;
    }

    /** 발표를 통째로 지운다 — 자료 파일과 반응까지 같이 사라진다 */
    public function purge(Presentation $pres): void
    {
        $this->storage->remove($pres->material_path);
        $this->emotions->deleteByPresentation((int)$pres->id);
        $this->presentations->delete((int)$pres->id);
    }

    /**
     * 예약 취소·배정 해제. 발표가 이미 끝났으면 기록을 남겨야 하므로 발표자만 지우고,
     * 아직이면 발표 행 자체를 없앤다.
     */
    public function unassign(Presentation $pres): void
    {
        if ($pres->done_date !== '') {
            $pres->presenter = '';
            $pres->presenter_email = '';
            $pres->planned_date = '';
            $this->presentations->update($pres);
            return;
        }
        $this->purge($pres);
    }

    public function mayManage(?Presentation $pres, Identity $identity, Config $config): bool
    {
        return ($pres !== null && $pres->presenter_email === $identity->email)
            || $config->isAdmin($identity->email);
    }
}

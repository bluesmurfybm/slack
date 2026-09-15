<?php

namespace Dti\Controller;

use Dti\Config;
use Dti\Http\ApiException;
use Dti\Http\Input;
use Dti\Http\Response;
use Dti\Identity\Identity;
use Dti\Repository\PresentationRepository;
use Dti\Repository\TopicRepository;

final class EmotionController
{
    public function __construct(
        private readonly TopicRepository $topics,
        private readonly PresentationRepository $presentations,
        private readonly \PDO $pdo,
        private readonly Identity $identity,
    ) {}

    public function toggle(int $tid, string $kind): Response
    {
        $this->topics->findOrFail($tid);
        $kind = Input::oneOf($kind, Config::EMOTIONS, '반응', blankOk: false);

        $pres = $this->presentations->ofTopic($tid);
        if ($pres === null || $pres->done_date === '') {
            throw new ApiException('발표가 끝난 아티클에만 반응을 남길 수 있습니다', 409);
        }

        $left = dti_emotion_toggle(
            $this->pdo,
            (int)$pres->id, $this->identity->email, $kind, date('Y-m-d H:i:s'));

        return Response::json([
            'kind' => $kind,
            'count' => dti_emotion_count_for($this->pdo, (int)$pres->id, $kind),
            'mine' => $left,
        ]);
    }
}

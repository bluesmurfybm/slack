<?php

namespace Dti\Controller;

use Dti\Config;
use Dti\Http\ApiException;
use Dti\Http\Input;
use Dti\Http\Response;

final class EmotionController
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly array $identity,
    ) {}

    public function toggle(int $tid, string $kind): Response
    {
        dti_topic_find_or_fail($this->pdo, $tid);
        $kind = Input::oneOf($kind, Config::EMOTIONS, '반응', blankOk: false);

        $pres = dti_presentation_of_topic($this->pdo, $tid);
        if ($pres === null || $pres['done_date'] === '') {
            throw new ApiException('발표가 끝난 아티클에만 반응을 남길 수 있습니다', 409);
        }

        $left = dti_emotion_toggle(
            $this->pdo,
            (int)$pres['id'], $this->identity['email'], $kind, date('Y-m-d H:i:s'));

        return Response::json([
            'kind' => $kind,
            'count' => dti_emotion_count_for($this->pdo, (int)$pres['id'], $kind),
            'mine' => $left,
        ]);
    }
}

<?php

namespace Dti\Service;

use Dti\Entity\Presentation;
use Dti\Entity\Topic;

/**
 * 아티클 하나를 화면 계약으로 옮긴다. 파이썬 features/topics/service.py 의 to_dict 자리다.
 * 상태는 저장하지 않고 발표 행에서 파생한다 — 원본 xlsx 에 "발표자·예정일이 있는데 비고는
 * 미지정" 인 행이 있어서 컬럼으로 들고 있으면 계속 어긋난다.
 */
final class TopicPresenter
{
    public const STATUS_OPEN = '미지정';
    public const STATUS_PLANNED = '발표예정';
    public const STATUS_DONE = '발표완료';

    /** 발표 행이 없을 때 화면에 나가는 값 */
    private const DEFAULTS = [
        'presenter' => '', 'presenter_email' => '', 'planned_date' => '', 'done_date' => '',
        'material_kind' => null, 'material_name' => null, 'material_url' => null,
        'material_path' => null,
    ];

    public static function deriveStatus(?Presentation $pres): string
    {
        if ($pres && $pres->done_date !== '') return self::STATUS_DONE;
        if ($pres && $pres->presenter_email !== '') return self::STATUS_PLANNED;
        return self::STATUS_OPEN;
    }

    public static function present(Topic $topic, ?Presentation $pres,
                                   ?array $emotions = null, ?array $mine = null): array
    {
        $flat = self::DEFAULTS;
        if ($pres) {
            foreach (array_keys(self::DEFAULTS) as $field) {
                $flat[$field] = $pres->$field;
            }
        }

        return [
            ...$topic->toArray(),
            ...$flat,
            'status' => self::deriveStatus($pres),
            'emotions' => $emotions ?? dti_emotion_empty_counts(),
            'my_emotions' => $mine ?? [],
        ];
    }
}

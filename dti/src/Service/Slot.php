<?php

namespace Dti\Service;

use Dti\Entity\Presentation;
use Dti\Entity\Topic;
use Dti\Http\ApiException;

/**
 * 자료 칸. 이름이 곧 컬럼 접두어다 — 발표자료(material)는 발표 행에, 스캔 원본(scan)은
 * 아티클 행에 있다. 어느 쪽이든 kind·name·url·path 네 컬럼 한 벌이다.
 */
final class Slot
{
    public const NAMES = ['material', 'scan'];

    public function __construct(public readonly string $name)
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new ApiException('없는 자료 칸입니다', 404);
        }
    }

    public function holder(Topic $topic, ?Presentation $pres): Topic|Presentation|null
    {
        return $this->name === 'scan' ? $topic : $pres;
    }

    public function get(Topic|Presentation|null $holder, string $field): ?string
    {
        if ($holder === null) return null;
        $column = "{$this->name}_{$field}";
        return $holder->$column;
    }

    /** @param array<string, ?string> $values kind·name·url·path 중 채울 것 */
    public function set(Topic|Presentation $holder, array $values): void
    {
        foreach ($values as $field => $value) {
            $column = "{$this->name}_{$field}";
            $holder->$column = $value;
        }
    }
}

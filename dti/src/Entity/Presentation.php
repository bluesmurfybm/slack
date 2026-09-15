<?php

namespace Dti\Entity;

/** 발표. 아티클당 하나이며, 예약·발표일·발표자료를 들고 있다. */
final class Presentation
{
    public const COLUMNS = [
        'id', 'topic_id', 'presenter', 'presenter_email', 'planned_date', 'done_date',
        'material_kind', 'material_name', 'material_url', 'material_path', 'created_at',
    ];

    public ?int $id = null;
    public int $topic_id = 0;
    public string $presenter = '';
    public string $presenter_email = '';
    public string $planned_date = '';
    public string $done_date = '';
    public ?string $material_kind = null;
    public ?string $material_name = null;
    public ?string $material_url = null;
    public ?string $material_path = null;
    public string $created_at = '';

    public static function fromRow(array $row): self
    {
        $pres = new self();
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $row)) continue;
            $value = $row[$column];

            $pres->$column = match ($column) {
                'id', 'topic_id' => (int)$value,
                'material_kind', 'material_name', 'material_url', 'material_path' => $value === null ? null : (string)$value,
                default => (string)$value,
            };
        }
        return $pres;
    }

    public function toArray(): array
    {
        $out = [];
        foreach (self::COLUMNS as $column) {
            $out[$column] = $this->$column;
        }
        return $out;
    }
}

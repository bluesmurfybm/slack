<?php

namespace Dti\Entity;

/**
 * 아티클. 속성 이름은 DB 컬럼·API 키와 같은 snake_case 로 둔다 — 셋이 같은 이름이라야
 * 화면 계약이 어긋날 자리가 없다.
 *
 * presenter·presenter_email·planned_date·done_date·material_* 는 발표 분리(24ca257) 뒤로
 * 쓰지 않는 컬럼이다. 값은 남아 있고, 화면에는 발표 행의 값이 나간다(TopicPresenter).
 */
final class Topic
{
    public const COLUMNS = [
        'id', 'title', 'field', 'keywords', 'magazine', 'volume', 'page', 'year',
        'requirement', 'team', 'presenter', 'presenter_email', 'planned_date', 'done_date',
        'note', 'active', 'archived',
        'material_kind', 'material_name', 'material_url', 'material_path',
        'scan_kind', 'scan_name', 'scan_url', 'scan_path',
        'created_by', 'created_at',
    ];

    public ?int $id = null;
    public string $title = '';
    public string $field = '';
    public string $keywords = '';
    public string $magazine = '';
    public string $volume = '';
    public string $page = '';
    public ?int $year = null;
    public string $requirement = 'recommended';
    public string $team = '';
    public string $presenter = '';
    public string $presenter_email = '';
    public string $planned_date = '';
    public string $done_date = '';
    public string $note = '';
    public int $active = 1;
    public int $archived = 0;
    public ?string $material_kind = null;
    public ?string $material_name = null;
    public ?string $material_url = null;
    public ?string $material_path = null;
    public ?string $scan_kind = null;
    public ?string $scan_name = null;
    public ?string $scan_url = null;
    public ?string $scan_path = null;
    public string $created_by = '';
    public string $created_at = '';

    /** PDO 는 컬럼을 문자열로 줄 수 있다. 정수는 여기서 한 번만 캐스팅한다. */
    public static function fromRow(array $row): self
    {
        $topic = new self();
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $row)) continue;
            $value = $row[$column];

            $topic->$column = match ($column) {
                'id', 'active', 'archived' => (int)$value,
                'year' => $value === null ? null : (int)$value,
                'material_kind', 'material_name', 'material_url', 'material_path',
                'scan_kind', 'scan_name', 'scan_url', 'scan_path' => $value === null ? null : (string)$value,
                default => (string)$value,
            };
        }
        return $topic;
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

<?php
/**
 * 아티클. 배열 키는 DB 컬럼·API 키와 같은 snake_case 다 — 셋이 같은 이름이라야
 * 화면 계약이 어긋날 자리가 없다.
 *
 * presenter·presenter_email·planned_date·done_date·material_* 는 발표 분리 뒤로
 * 쓰지 않는 컬럼이다. 값은 남아 있고, 화면에는 발표 행의 값이 나간다.
 */

const DTI_TOPIC_DEFAULTS = [
    'id' => null,
    'title' => '',
    'field' => '',
    'keywords' => '',
    'magazine' => '',
    'volume' => '',
    'page' => '',
    'year' => null,
    'requirement' => 'recommended',
    'team' => '',
    'presenter' => '',
    'presenter_email' => '',
    'planned_date' => '',
    'done_date' => '',
    'note' => '',
    'active' => 1,
    'archived' => 0,
    'material_kind' => null,
    'material_name' => null,
    'material_url' => null,
    'material_path' => null,
    'scan_kind' => null,
    'scan_name' => null,
    'scan_url' => null,
    'scan_path' => null,
    'created_by' => '',
    'created_at' => '',
];

const DTI_TOPIC_NULLABLE = [
    'material_kind', 'material_name', 'material_url', 'material_path',
    'scan_kind', 'scan_name', 'scan_url', 'scan_path',
];

const DTI_STATUS_OPEN = '미지정';
const DTI_STATUS_PLANNED = '발표예정';
const DTI_STATUS_DONE = '발표완료';

function dti_topic_columns() {
    return array_keys(DTI_TOPIC_DEFAULTS);
}

function dti_topic_new() {
    return DTI_TOPIC_DEFAULTS;
}

/** PDO 는 컬럼을 문자열로 줄 수 있다. 정수는 여기서 한 번만 캐스팅한다. */
function dti_topic_from_row(array $row) {
    $topic = DTI_TOPIC_DEFAULTS;
    foreach (dti_topic_columns() as $column) {
        if (!array_key_exists($column, $row)) continue;
        $value = $row[$column];

        $topic[$column] = match (true) {
            in_array($column, ['id', 'active', 'archived'], true) => (int)$value,
            $column === 'year' => $value === null ? null : (int)$value,
            in_array($column, DTI_TOPIC_NULLABLE, true) => $value === null ? null : (string)$value,
            default => (string)$value,
        };
    }
    return $topic;
}

function dti_topic_find(PDO $pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM dti_topics WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? dti_topic_from_row($row) : null;
}

function dti_topic_find_or_fail(PDO $pdo, $id) {
    return dti_topic_find($pdo, $id) ?? throw new Dti\Http\ApiException('없는 아티클입니다', 404);
}

function dti_topic_all(PDO $pdo) {
    return array_map('dti_topic_from_row', $pdo->query("SELECT * FROM dti_topics")->fetchAll());
}

/**
 * 목록. 날짜(발표일 > 예정일) 없는 것이 먼저, 그다음 날짜 최신순, 마지막으로 등록 역순이다.
 * 아티클과 발표를 짝지어 돌려준다.
 */
function dti_topic_list_with_presentations(PDO $pdo, $includeHidden) {
    $on = "COALESCE(NULLIF(p.done_date, ''), NULLIF(p.planned_date, ''))";
    $where = $includeHidden ? '' : 'WHERE t.active = 1 AND t.archived = 0';

    $sql = "SELECT t.*, p.id AS p_id, p.topic_id AS p_topic_id, p.presenter AS p_presenter,
                   p.presenter_email AS p_presenter_email, p.planned_date AS p_planned_date,
                   p.done_date AS p_done_date, p.material_kind AS p_material_kind,
                   p.material_name AS p_material_name, p.material_url AS p_material_url,
                   p.material_path AS p_material_path, p.created_at AS p_created_at
            FROM dti_topics t
            LEFT JOIN dti_presentations p ON p.topic_id = t.id
            {$where}
            ORDER BY ({$on} IS NULL) DESC, {$on} DESC, t.id DESC";

    $out = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $pres = null;
        if ($row['p_id'] !== null) {
            $presRow = [];
            foreach (dti_presentation_columns() as $column) {
                $presRow[$column] = $row['p_' . $column];
            }
            $pres = dti_presentation_from_row($presRow);
        }
        $out[] = [dti_topic_from_row($row), $pres];
    }
    return $out;
}

function dti_topic_insert(PDO $pdo, array &$topic) {
    $columns = array_values(array_diff(dti_topic_columns(), ['id']));
    $sql = 'INSERT INTO dti_topics (`' . implode('`, `', $columns) . '`) VALUES ('
         . implode(', ', array_fill(0, count($columns), '?')) . ')';

    $pdo->prepare($sql)->execute(array_map(static fn ($c) => $topic[$c], $columns));

    return $topic['id'] = (int)$pdo->lastInsertId();
}

function dti_topic_update(PDO $pdo, array $topic) {
    $columns = array_values(array_diff(dti_topic_columns(), ['id']));
    $sql = 'UPDATE dti_topics SET ' . implode(', ', array_map(static fn ($c) => "`{$c}` = ?", $columns))
         . ' WHERE id = ?';

    $values = array_map(static fn ($c) => $topic[$c], $columns);
    $values[] = $topic['id'];
    $pdo->prepare($sql)->execute($values);
}

function dti_topic_delete(PDO $pdo, $id) {
    $pdo->prepare("DELETE FROM dti_topics WHERE id = ?")->execute([$id]);
}

/**
 * 상태는 저장하지 않고 발표 행에서 파생한다 — 원본 xlsx 에 "발표자·예정일이 있는데 비고는
 * 미지정" 인 행이 있어서 컬럼으로 들고 있으면 계속 어긋난다.
 */
function dti_topic_status($pres) {
    if ($pres && $pres['done_date'] !== '') return DTI_STATUS_DONE;
    if ($pres && $pres['presenter_email'] !== '') return DTI_STATUS_PLANNED;
    return DTI_STATUS_OPEN;
}

/** 아티클 하나를 화면 계약으로 옮긴다. */
function dti_topic_present(array $topic, $pres, $emotions = null, $mine = null) {
    $flat = [
        'presenter' => '', 'presenter_email' => '', 'planned_date' => '', 'done_date' => '',
        'material_kind' => null, 'material_name' => null, 'material_url' => null,
        'material_path' => null,
    ];
    if ($pres) {
        foreach (array_keys($flat) as $field) {
            $flat[$field] = $pres[$field];
        }
    }

    return [
        ...$topic,
        ...$flat,
        'status' => dti_topic_status($pres),
        'emotions' => $emotions ?? dti_emotion_empty_counts(),
        'my_emotions' => $mine ?? [],
    ];
}

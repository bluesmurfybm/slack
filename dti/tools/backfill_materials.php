<?php
/**
 * material_*·scan_* 컬럼에 들어 있던 자료를 dti_materials 로 옮긴다. CLI 전용, 일회성.
 *
 *   php dti/tools/backfill_materials.php [--dry-run]
 *
 * 여러 번 돌려도 안전하다 — 이미 자료가 있는 칸은 건너뛴다.
 * 옮긴 뒤에도 원본 컬럼은 지우지 않는다(되돌릴 수 있게). 컬럼 정리는 별건이다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI 에서만 실행한다\n");
}

require __DIR__ . '/../bootstrap.php';

$dryRun = array_key_exists('dry-run', getopt('', ['dry-run']));

$config = dti_config_from_portal();
$pdo = dti_connect($config);
dti_migrate($pdo);

$taken = [];
foreach ($pdo->query("SELECT topic_id, slot FROM dti_materials")->fetchAll() as $row) {
    $taken[$row['topic_id'] . '/' . $row['slot']] = true;
}

$sources = [
    'scan' => "SELECT id AS topic_id, scan_kind AS kind, scan_name AS name, scan_url AS url,
                      scan_path AS path, created_by, created_at
               FROM dti_topics WHERE scan_kind IS NOT NULL",
    'material' => "SELECT topic_id, material_kind AS kind, material_name AS name,
                          material_url AS url, material_path AS path,
                          presenter_email AS created_by, created_at
                   FROM dti_presentations WHERE material_kind IS NOT NULL",
];

$pdo->beginTransaction();
$moved = ['scan' => 0, 'material' => 0];
$skipped = 0;

foreach ($sources as $slot => $sql) {
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $tid = (int)$row['topic_id'];
        if (isset($taken[$tid . '/' . $slot])) { $skipped++; continue; }

        dti_material_create($pdo, $tid, $slot, [
            'kind' => $row['kind'],
            'name' => (string)($row['name'] ?? ''),
            'url' => $row['url'],
            'path' => $row['path'],
            'created_by' => (string)($row['created_by'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ]);
        $taken[$tid . '/' . $slot] = true;
        $moved[$slot]++;
    }
}

if ($dryRun) {
    $pdo->rollBack();
} else {
    $pdo->commit();
}

echo $dryRun ? "[--dry-run: 쓰지 않는다]\n" : '';
printf("  스캔 원본  %d건\n", $moved['scan']);
printf("  발표자료   %d건\n", $moved['material']);
printf("  건너뜀     %d건 (이미 옮겨진 칸)\n", $skipped);

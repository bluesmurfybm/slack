<?php
/**
 * 옛 엑셀에서 옮겨 온 청구 중 '승인대기·미처리'로 남은 건을 승인·처리완료로 바꾼다. 명령줄 전용.
 *
 *   php blueledger/tools/mark_legacy_done.php --dry-run   # 바뀔 건만 보여 준다
 *   php blueledger/tools/mark_legacy_done.php
 *
 * 2026-10-22 지안 님 확인: 엑셀 시절 기록은 실제로 전부 처리가 끝났다. 엑셀에 상태 칸이 비어 있어
 * 옮길 때 기본값(승인대기·미처리)이 들어갔을 뿐이다. 운영 서버에서는 import_travel.php 로 옮긴 직후
 * 한 번 돌린다. 승인자·처리자·날짜는 기록이 없어 비워 둔다(지어내지 않는다).
 * BlueExpense 에서 새로 올린 청구(source 가 '기존 엑셀'이 아닌 것)는 건드리지 않는다.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../guard.php';

$dry = in_array('--dry-run', array_slice($argv, 1), true);
$pdo = bl_db();
$rows = $pdo->query("SELECT id, traveler, trip_date, place, total, approval, status FROM bl_travel_trips
                     WHERE source = '기존 엑셀' AND (approval <> '승인' OR status <> '처리완료')
                     ORDER BY trip_date, id")->fetchAll();

foreach ($rows as $r) {
    printf("  %s  %s  %s  %s원  (%s · %s)\n", $r['trip_date'], $r['traveler'], $r['place'], number_format((int)$r['total']), $r['approval'], $r['status']);
}
if (!$rows) { echo "바꿀 건이 없습니다.\n"; exit; }
if ($dry) { echo "[연습] 위 " . count($rows) . "건을 승인·처리완료로 바꿉니다. --dry-run 을 빼고 다시 돌리면 실제로 바뀝니다.\n"; exit; }

$now = now_stamp();
$st = $pdo->prepare("UPDATE bl_travel_trips SET approval = '승인', status = '처리완료', reviewed_at = ?, updated_at = ? WHERE id = ?");
$pdo->beginTransaction();
foreach ($rows as $r) $st->execute([$now, $now, $r['id']]);
$pdo->commit();
echo count($rows) . "건을 승인·처리완료로 바꿨습니다.\n";

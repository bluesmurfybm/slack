<?php
declare(strict_types=1);
/*
 * 저장하지 않고 버려진 첨부파일 정리 (선택 사항)
 *
 *   php blueledger/resort/tools/cleanup.php          → 하루 넘게 어느 예약에도 연결되지 않은 파일 삭제
 *   php blueledger/resort/tools/cleanup.php --dry    → 지우지 않고 목록만 보기
 *
 * 크론이 꼭 필요하지는 않습니다. 한 달에 한 번 손으로 실행해도 되고,
 * 서버에서 자동으로 돌리려면 예: 매주 일요일 새벽 3시
 *   0 3 * * 0  php /경로/blueledger/resort/tools/cleanup.php >/dev/null 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/repo.php';

$dry = in_array('--dry', $argv, true);
$before = date('Y-m-d H:i:s', time() - 86400);
$st = db()->prepare('SELECT id, path, orig_name, created_at FROM bl_resort_files WHERE booking_id IS NULL AND created_at < ?');
$st->execute([$before]);
$n = 0;
foreach ($st->fetchAll() as $f) {
    echo ($dry ? '[목록] ' : '[삭제] '), $f['created_at'], ' ', $f['orig_name'], "\n";
    if (!$dry) delete_file_row($f);
    $n++;
}
echo $n ? "$n 개 " . ($dry ? '대상' : '정리') . "\n" : "정리할 파일이 없습니다\n";

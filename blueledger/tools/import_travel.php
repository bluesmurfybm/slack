<?php
/**
 * 파이썬 버전 출장여비(travel.db + receipts 폴더)를 포털 DB 로 옮긴다. 명령줄 전용.
 *
 *   php blueledger/tools/import_travel.php <travel.db 경로> [--receipts=<receipts 폴더>] [--dry-run]
 *
 *  - 청구서: 같은 id 가 이미 있으면 건너뛴다. 몇 번을 돌려도 두 번 들어가지 않는다.
 *  - 권한   : travel.db 의 users.role(admin/approver)을 이름으로 포털 계정에 맞춰 옮긴다.
 *  - 설정   : main(오피넷 키 포함)·prices 를 그대로 옮긴다. 이미 있으면 덮어쓴다.
 *  - 영수증 : receipts 폴더의 파일을 var/receipts 로 복사하고 청구서에 묶는다.
 *  - 비밀번호(users.pin_hash)는 옮기지 않는다 — 포털 로그인을 쓰므로 필요 없다.
 *
 * pdo_sqlite 확장이 있어야 한다(대부분의 PHP 에 기본으로 들어 있다).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../guard.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/trips.php';
require_once __DIR__ . '/../lib/storage.php';

$args = array_slice($argv, 1);
$src = null; $rcDir = null; $dry = false;
foreach ($args as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif (str_starts_with($a, '--receipts=')) $rcDir = substr($a, 11);
    elseif ($src === null) $src = $a;
}
if (!$src || !is_file($src)) {
    fwrite(STDERR, "사용법: php import_travel.php <travel.db> [--receipts=<폴더>] [--dry-run]\n");
    exit(1);
}
if ($rcDir === null) $rcDir = dirname($src) . '/receipts';
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite 확장이 없습니다. php.ini 에서 extension=pdo_sqlite 를 켜 주세요.\n");
    exit(1);
}

$old = new PDO('sqlite:' . $src, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo = bl_db();
$nameToEmail = array_column(bl_members(), 'email', 'name');
$say = fn($s) => print($s . "\n");
$say($dry ? '[연습 — DB 에 쓰지 않습니다]' : '[옮기기 시작]');

/* ---------- 권한 ---------- */
foreach ($old->query("SELECT name, role FROM users WHERE active=1") as $u) {
    if (!in_array($u['role'], [ROLE_ADMIN, ROLE_APPROVER], true)) continue;
    $email = $nameToEmail[$u['name']] ?? null;
    if (!$email) { $say("  권한: {$u['name']} — 포털 계정이 없어 건너뜀"); continue; }
    $say("  권한: {$u['name']} → {$u['role']}");
    if (!$dry) {
        bl_upsert($pdo, 'bl_travel_roles', ['email' => $email, 'role' => $u['role'], 'updated_by' => 'import', 'updated_at' => now_stamp()], ['email']);
    }
}

/* ---------- 설정 ---------- */
foreach ($old->query("SELECT key, value FROM settings") as $s) {
    $v = json_decode($s['value'], true);
    if (!is_array($v) || !in_array($s['key'], ['main', 'prices'], true)) continue;
    $say("  설정: {$s['key']}" . ($s['key'] === 'main' && !empty($v['opinetKey']) ? ' (오피넷 키 포함)' : ''));
    if (!$dry) bl_setting_put($pdo, $s['key'], $v);
}

/* ---------- 청구서 + 영수증 ---------- */
$exists = $pdo->prepare("SELECT 1 FROM bl_travel_trips WHERE id=?");
$added = 0; $skipped = 0; $files = 0; $missing = 0;
foreach ($old->query("SELECT id, data, created_by, updated_at FROM trips ORDER BY id") as $t) {
    $exists->execute([$t['id']]);
    if ($exists->fetchColumn()) { $skipped++; continue; }
    $d = json_decode($t['data'], true) ?: [];
    $by = (string)($t['created_by'] ?: ($d['createdBy'] ?? ''));

    $receipts = [];
    $fileRows = [];
    foreach (RC_KINDS as $k) {
        $receipts[$k] = [];
        foreach ((array)($d['receipts'][$k] ?? []) as $r) {
            $fid = basename((string)($r['id'] ?? ''));
            $from = $rcDir . '/' . $fid;
            if ($fid === '' || !is_file($from)) { $missing++; continue; }
            $mime = bl_sniff_mime($from);
            if (!isset(RECEIPT_TYPES[$mime])) { $missing++; continue; }
            $receipts[$k][] = ['id' => $fid, 'type' => $mime, 'name' => (string)($r['name'] ?? '')];
            $fileRows[] = [$fid, $t['id'], $k, (string)($r['name'] ?? ''), $mime, filesize($from), $nameToEmail[$by] ?? '', $from];
        }
    }

    $row = [
        'id' => $t['id'], 'traveler' => (string)($d['traveler'] ?? ''), 'companions' => (string)($d['companions'] ?? ''),
        'companion_count' => (int)($d['companionCount'] ?? 0), 'trip_date' => (string)($d['date'] ?? ''),
        'period' => (string)($d['period'] ?? '당일') ?: '당일', 'place' => (string)($d['place'] ?? ''),
        'purpose' => (string)($d['purpose'] ?? ''), 'mode' => (string)($d['mode'] ?? ''), 'km' => (float)($d['km'] ?? 0),
        'transport' => to_won($d['transport'] ?? 0), 'toll' => to_won($d['toll'] ?? 0), 'meal' => to_won($d['meal'] ?? 0),
        'etc' => to_won($d['etc'] ?? 0), 'etc_note' => (string)($d['etcNote'] ?? ''),
        'receipts' => json_encode($receipts, JSON_UNESCAPED_UNICODE),
        'approval' => in_array($d['approval'] ?? '', APPROVALS, true) ? $d['approval'] : '승인대기',
        'approver' => (string)($d['approver'] ?? ''), 'approved_at' => (string)($d['approvedAt'] ?? ''),
        'status' => in_array($d['status'] ?? '', STATUSES, true) ? $d['status'] : '미처리',
        'handler' => (string)($d['handler'] ?? ''), 'processed_at' => (string)($d['processedAt'] ?? ''),
        'pay_method' => (string)($d['payMethod'] ?? ''), 'note' => (string)($d['note'] ?? ''),
        'source' => (string)($d['source'] ?? ''),
        'created_by' => $nameToEmail[$by] ?? '', 'created_by_name' => $by,
        'created_at' => bl_import_stamp($d['createdAt'] ?? ''), 'updated_at' => bl_import_stamp($t['updated_at'] ?? ''),
        'staff_updated_at' => bl_import_stamp($d['staffUpdatedAt'] ?? ''), 'reviewed_at' => bl_import_stamp($d['reviewedAt'] ?? ''),
    ];
    $row['total'] = $row['transport'] + $row['toll'] + $row['meal'] + $row['etc'];
    $added++;
    $files += count($fileRows);
    if ($dry) continue;

    $pdo->beginTransaction();
    bl_write_trip($pdo, $row, false);
    foreach ($fileRows as $f) {
        $to = bl_receipt_dir() . '/' . $f[0];
        if (!is_file($to) && !copy($f[7], $to)) throw new RuntimeException("영수증 복사 실패: {$f[7]}");
        bl_insert_ignore($pdo, 'bl_travel_files', ['id' => $f[0], 'trip_id' => $f[1], 'kind' => $f[2], 'orig_name' => $f[3],
            'mime' => $f[4], 'size' => $f[5], 'uploaded_by' => $f[6], 'created_at' => now_stamp()]);
    }
    $pdo->commit();
}

$say("  청구서: 새로 {$added}건, 이미 있어 건너뜀 {$skipped}건");
$say("  영수증: {$files}개" . ($missing ? ", 파일을 못 찾음 {$missing}개" : ''));
$say($dry ? '[연습 끝 — 바뀐 것 없음]' : '[끝]');

/** '2026-10-20T17:51:11' · '2020-12-11' → DB 형식 'Y-m-d H:i:s' (날짜만이면 그대로) */
function bl_import_stamp($v) {
    $v = str_replace('T', ' ', trim((string)$v));
    return substr(preg_replace('/(\.\d+)?([+-]\d{2}:?\d{2}|Z)?$/', '', substr($v, 0, 26)), 0, 19);
}

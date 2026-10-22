<?php
/**
 * 파이썬 출장여비 travel.db → 운영 MySQL 에 그대로 넣을 SQL 파일 하나. 명령줄 전용.
 *
 *   php blueledger/tools/export_travel_sql.php <travel.db> [--out=<파일.sql>]
 *
 * 서버에서는 PHP 도구 없이 이 파일만 넣으면 된다.
 *   mysql --default-character-set=utf8mb4 -u <계정> -p <DB이름> < bluexpense_migrate.sql
 *
 * 파일이 하는 일 (몇 번 넣어도 같다):
 *  1) bl_travel_* 네 표가 없으면 만든다(db.php 의 BL_SCHEMA 와 같은 정의)
 *  2) 청구서를 넣는다 — 같은 id 가 있으면 건너뛴다(INSERT IGNORE)
 *     엑셀 시절 '승인대기·미처리'로 남은 건은 승인·처리완료로 넣는다(2026-10-22 지안 님 확인)
 *  3) 권한(정산·승인 관리자)과 설정을 넣는다. 설정은 1인 식대 15,000원·유가 자동 적용 켬
 *  4) 끝에 건수·합계를 SELECT 로 보여 준다
 * 사람 이메일은 이 PC 의 명단이 아니라 서버의 portal_users 에서 이름으로 찾는다(서버 주소가 다를 수 있다).
 * 영수증 파일은 SQL 에 담지 않는다 — 원본에 첨부가 하나도 없어서다. 있으면 멈춘다.
 *
 * 만들어진 파일에는 직원 이름·금액·오피넷 키가 들어 있다. 저장소에 올리지 말 것.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// BL_SCHEMA 만 쓴다. guard.php 를 부르면 세션·DB 를 열려 하므로 표 정의만 가져온다.
const BL_SEED_ROLES = [];
require_once __DIR__ . '/../db.php';

$src = null; $out = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--out=')) $out = substr($a, 6);
    elseif ($src === null) $src = $a;
}
if (!$src || !is_file($src)) { fwrite(STDERR, "사용법: php export_travel_sql.php <travel.db> [--out=<파일.sql>]\n"); exit(1); }
$out = $out ?: dirname($src) . '/bluexpense_migrate.sql';

$old = new PDO('sqlite:' . $src, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/** MySQL 문자열 — 작은따옴표와 역슬래시를 이스케이프한다 */
function q($v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return "'" . str_replace(["\\", "'", "\0", "\n", "\r"], ["\\\\", "\\'", "\\0", "\\n", "\\r"], (string)$v) . "'";
}
/** 이름 → 서버 portal_users 의 이메일(없으면 빈칸) */
function email_of(string $name): string {
    return $name === '' ? "''" : "COALESCE((SELECT LOWER(email) FROM portal_users WHERE name=" . q($name) . " ORDER BY id LIMIT 1), '')";
}
function stamp($v): string {
    $v = str_replace('T', ' ', trim((string)$v));
    return substr(preg_replace('/(\.\d+)?([+-]\d{2}:?\d{2}|Z)?$/', '', substr($v, 0, 26)), 0, 19);
}
function won($v): int { $n = (float)preg_replace('/[^\d.\-]/', '', (string)$v); return $n > 0 ? (int)round($n) : 0; }

/* ---------- 표 정의 (db.php 의 MySQL DDL 과 같게) ---------- */
$ddl = [];
foreach (BL_SCHEMA as $table => $def) {
    if (!str_starts_with($table, 'bl_travel_')) continue;
    $lines = [];
    foreach ($def['cols'] as $name => [$type, $default, $comment]) {
        $l = "`$name` $type" . ($default === null ? ' NULL' : ($default === false ? ' NOT NULL' : ' NOT NULL DEFAULT ' . q($default)));
        if ($comment !== '') $l .= ' COMMENT ' . q($comment);
        $lines[] = $l;
    }
    $lines[] = "PRIMARY KEY (`{$def['pk']}`)";
    foreach ($def['keys'] as $k => $cols) $lines[] = "KEY `$k` (`" . implode('`,`', $cols) . "`)";
    $ddl[] = "CREATE TABLE IF NOT EXISTS `$table` (\n  " . implode(",\n  ", $lines) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=" . q($def['comment']) . ';';
}

/* ---------- 청구서 ---------- */
$cols = ['id', 'traveler', 'companions', 'companion_count', 'trip_date', 'period', 'place', 'purpose', 'mode', 'km',
    'transport', 'toll', 'meal', 'etc', 'etc_note', 'total', 'receipts', 'approval', 'approver', 'approved_at',
    'status', 'handler', 'processed_at', 'pay_method', 'note', 'source', 'created_by', 'created_by_name',
    'created_at', 'updated_at', 'staff_updated_at', 'reviewed_at'];
$inserts = []; $sum = 0; $done = 0; $now = date('Y-m-d H:i:s');
foreach ($old->query("SELECT id, data, created_by, updated_at FROM trips ORDER BY id") as $t) {
    $d = json_decode($t['data'], true) ?: [];
    foreach (['transport', 'toll', 'meal', 'etc'] as $k) {
        if (!empty($d['receipts'][$k])) { fwrite(STDERR, "영수증 첨부가 있는 청구가 있습니다({$t['id']}). 이 도구는 영수증을 옮기지 않습니다 — import_travel.php 를 쓰세요.\n"); exit(1); }
    }
    $by = (string)($t['created_by'] ?: ($d['createdBy'] ?? ''));
    $approval = in_array($d['approval'] ?? '', ['승인대기', '승인', '반려'], true) ? $d['approval'] : '승인대기';
    $status = in_array($d['status'] ?? '', ['미처리', '처리완료'], true) ? $d['status'] : '미처리';
    $reviewed = stamp($d['reviewedAt'] ?? '');
    // 엑셀 시절 기록은 모두 끝난 건이다(지안 님 확인). 상태 칸이 비어 기본값이 들어갔을 뿐이다
    if (($d['source'] ?? '') === '기존 엑셀' && ($approval !== '승인' || $status !== '처리완료')) {
        $approval = '승인'; $status = '처리완료'; $reviewed = $now; $done++;
    }
    $v = [
        q($t['id']), q((string)($d['traveler'] ?? '')), q((string)($d['companions'] ?? '')), (int)($d['companionCount'] ?? 0),
        q((string)($d['date'] ?? '')), q((string)($d['period'] ?? '') ?: '당일'), q((string)($d['place'] ?? '')), q((string)($d['purpose'] ?? '')),
        q((string)($d['mode'] ?? '')), (float)($d['km'] ?? 0),
        won($d['transport'] ?? 0), won($d['toll'] ?? 0), won($d['meal'] ?? 0), won($d['etc'] ?? 0), q((string)($d['etcNote'] ?? '')),
        $total = won($d['transport'] ?? 0) + won($d['toll'] ?? 0) + won($d['meal'] ?? 0) + won($d['etc'] ?? 0),
        q(json_encode(['transport' => [], 'toll' => [], 'meal' => [], 'etc' => []])),
        q($approval), q((string)($d['approver'] ?? '')), q((string)($d['approvedAt'] ?? '')),
        q($status), q((string)($d['handler'] ?? '')), q((string)($d['processedAt'] ?? '')), q((string)($d['payMethod'] ?? '')),
        q((string)($d['note'] ?? '')), q((string)($d['source'] ?? '')),
        email_of($by), q($by), q(stamp($d['createdAt'] ?? '')), q(stamp($t['updated_at'] ?? '')), q(stamp($d['staffUpdatedAt'] ?? '')), q($reviewed),
    ];
    $sum += $total;
    $inserts[] = "INSERT IGNORE INTO `bl_travel_trips` (`" . implode('`,`', $cols) . "`) SELECT " . implode(', ', $v) . ';';
}

/* ---------- 권한 ---------- */
$roles = [];
foreach ($old->query("SELECT name, role FROM users WHERE active=1 AND role IN ('admin','approver')") as $u) {
    $roles[] = "INSERT INTO `bl_travel_roles` (`email`,`role`,`updated_by`,`updated_at`)\n  SELECT LOWER(email), " . q($u['role']) . ", 'migrate', " . q($now)
        . " FROM portal_users WHERE name=" . q($u['name']) . " ORDER BY id LIMIT 1\n  ON DUPLICATE KEY UPDATE `role`=VALUES(`role`);";
}

/* ---------- 설정 ---------- */
$settings = [];
foreach ($old->query("SELECT key, value FROM settings WHERE key IN ('main','prices')") as $s) {
    $v = json_decode($s['value'], true);
    if (!is_array($v)) continue;
    if ($s['key'] === 'main') { $v['mealUnit'] = 15000; $v['autoFuel'] = true; }   // 2026-10-22 작성기준 개정
    $settings[] = "INSERT INTO `bl_travel_settings` (`k`,`v`,`updated_at`) VALUES (" . q($s['key']) . ', ' . q(json_encode($v, JSON_UNESCAPED_UNICODE)) . ', ' . q($now) . ")\n  ON DUPLICATE KEY UPDATE `v`=VALUES(`v`), `updated_at`=VALUES(`updated_at`);";
}

$n = count($inserts);
$sql = "-- BlueExpense(출장여비) 운영 이관 — " . basename($src) . " 에서 " . date('Y-m-d H:i') . " 에 만듦\n"
    . "-- 청구 {$n}건, 합계 " . number_format($sum) . "원 (그중 엑셀 시절 미완료 {$done}건은 승인·처리완료로)\n"
    . "-- 넣는 법: mysql --default-character-set=utf8mb4 -u <계정> -p <포털 DB 이름> < 이 파일\n"
    . "-- 몇 번 넣어도 같다(이미 있는 청구는 건너뛴다). 직원 이름·금액·오피넷 키가 들어 있다 — 공개된 곳에 두지 말 것.\n\n"
    . "SET NAMES utf8mb4;\nSET time_zone = '+09:00';\n\n"
    . "-- 1) 표 (없을 때만)\n" . implode("\n\n", $ddl) . "\n\n"
    . "START TRANSACTION;\n\n"
    . "-- 2) 청구서\n" . implode("\n", $inserts) . "\n\n"
    . "-- 3) 권한 (이메일은 이 서버의 portal_users 에서 이름으로 찾는다)\n" . implode("\n", $roles) . "\n\n"
    . "-- 4) 설정 (1인 식대 15,000원 · 유가 자동 적용 켬)\n" . implode("\n", $settings) . "\n\n"
    . "COMMIT;\n\n"
    . "-- 5) 확인 — 청구 {$n}건, 합계 " . number_format($sum) . "원, 진행 중 0건이어야 한다\n"
    . "SELECT COUNT(*) AS `청구_건수`, SUM(total) AS `합계`, SUM(approval<>'승인' OR status<>'처리완료') AS `진행중` FROM `bl_travel_trips`;\n"
    . "SELECT email, role FROM `bl_travel_roles`;\n";

file_put_contents($out, $sql);
printf("만들었습니다: %s\n  청구 %d건, 합계 %s원, 엑셀 시절 미완료 → 완료 %d건, 권한 %d명, 설정 %d개\n", $out, $n, number_format($sum), $done, count($roles), count($settings));

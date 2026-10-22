<?php
declare(strict_types=1);
/*
 * 리조트 예약 · 식대 정산 — 2026년도 기존 이용내역(엑셀) 넣기. 명령줄 전용.
 *
 *   php blueledger/resort/tools/import_seed.php
 *
 * 표는 포털 DB 에 bl_resort_* 로 처음 접속할 때 저절로 생기고, 계정은 포털 계정을 그대로 쓴다.
 * 이 도구는 남은 한 가지 — 엑셀에서 옮긴 16건과 설정(한도 · 회원권 · 결제수단 · 안내문) — 만 넣는다.
 * 예약이 이미 있으면 건너뛰므로 여러 번 돌려도 된다. 끝에 합계를 엑셀 원본과 맞춰 본다.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/repo.php';

function say(string $s = ''): void { echo $s, "\n"; }

$pdo = db();
say('== 리조트 기존 이용내역 넣기 (DB: ' . (is_mysql() ? 'MySQL/MariaDB' : 'SQLite') . ')');

$seed = require __DIR__ . '/../data/seed.php';

// 한도 표의 이름이 포털 계정 이름과 맞는지 — 다르면 그 직원은 본인 예약이 안 보인다
$members = array_column(bl_members(), 'name');
foreach ($seed['settings']['staffByPeriod']['2026'] ?? [] as $s) {
    if (empty($s['company']) && !in_array($s['name'], $members, true)) say("   ! 포털 계정에 없는 이름: {$s['name']} — 포털 이름과 맞춰 주세요");
}

$bookingCount = (int)$pdo->query('SELECT COUNT(*) FROM bl_resort_bookings')->fetchColumn();
if ($bookingCount === 0) {
    save_settings($seed['settings'], 'seed');
    $pdo->beginTransaction();
    $t = now();
    $insB = null;
    $insM = $pdo->prepare('INSERT INTO bl_resort_meals (booking_id, cid, seq, use_date, place, amount) VALUES (?,?,?,?,?,?)');
    foreach ($seed['bookings'] as $b) {
        $n = normalize_booking($b)['cols'];
        $n['id'] = $b['id'];
        $n['created_by'] = null; // 관리자 등록분(엑셀에서 옮김)
        $n['created_at'] = $t;
        $n['updated_at'] = $t;
        $n['decided_at'] = null;
        $keys = array_keys($n);
        $insB ??= $pdo->prepare('INSERT INTO bl_resort_bookings (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $insB->execute(array_values($n));
        foreach (array_values($b['meals'] ?? []) as $i => $m) {
            $insM->execute([$b['id'], (string)$m['id'], $i, date_or_null($m['date'] ?? ''), cut($m['place'] ?? '', 100), money($m['amount'] ?? 0)]);
        }
    }
    $pdo->commit();
    say('1) 기존 이용내역 ' . count($seed['bookings']) . '건과 설정을 넣었습니다');
} else {
    say("1) 예약 $bookingCount 건이 이미 있어 건너뜀");
}

$stay = (int)$pdo->query("SELECT COALESCE(SUM(room_fee + add_fee),0) FROM bl_resort_bookings WHERE status = '확정' AND period = '2026'")->fetchColumn();
$meal = (int)$pdo->query("SELECT COALESCE(SUM(m.amount),0) FROM bl_resort_meals m JOIN bl_resort_bookings b ON b.id = m.booking_id WHERE b.period = '2026' AND b.status NOT IN ('반려','취소')")->fetchColumn();
say(sprintf('2) 2026년도 검산: 숙박 %s원 / 식대 %s원 (엑셀 원본: 8,868,200원 / 2,474,050원)', number_format($stay), number_format($meal)));

$admins = $pdo->query("SELECT email FROM bl_resort_roles WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
say('3) 관리자: ' . implode(', ', $admins) . ' (설정 화면에서 바꿉니다)');
say('완료. 영수증 폴더: ' . upload_dir());

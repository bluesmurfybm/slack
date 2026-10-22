<?php
declare(strict_types=1);
/*
 * 동작 시험 (개발용). 로컬 시험 DB(SQLite)에서만 돈다 — 포털 계정 세 개의 비밀번호를 잠시 바꿨다가 끝나면 되돌린다.
 *   (로컬_실행.bat 으로 포털을 띄우고 tools/import_seed.php 로 기존 16건을 넣은 뒤)
 *   D:\php82\php.exe blueledger/resort/tests/smoke.php http://127.0.0.1:8780/blueledger/resort
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/repo.php';
if (is_mysql()) { fwrite(STDERR, "운영 DB(MySQL)에서는 돌리지 않습니다 — 포털 비밀번호를 바꾸기 때문입니다.\n"); exit(1); }

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8780/blueledger/resort', '/');
$portal = preg_replace('#/blueledger/resort$#', '', $base);
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ✔ $m\n"; } else { $fail++; echo "  ✘ $m\n"; } }

// 시험 계정 비밀번호 고정 — 원래 값과 관리자 목록은 끝나면(실패해도) 되돌린다
const T_ADMIN = 'jian@bluesoft.co.kr';
const T_STAFF = 'bnmmnbhj@bluesoft.co.kr';   // 유병문
const T_OTHER = 'scpark@bluesoft.co.kr';     // 박성철
$saved = [];
foreach ([T_ADMIN => 'admin-test-pw', T_STAFF => 'staff-test-pw1', T_OTHER => 'staff-test-pw2'] as $em => $pw) {
    $q = db()->prepare('SELECT pw_hash FROM portal_users WHERE email = ?');
    $q->execute([$em]);
    $saved[$em] = $q->fetchColumn();
    $q->closeCursor();   // SQLite: 열린 읽기가 남으면 서버 쪽 쓰기가 잠긴다
    db()->prepare('UPDATE portal_users SET pw_hash = ? WHERE email = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $em]);
}
$savedRoles = db()->query('SELECT * FROM bl_resort_roles')->fetchAll();
register_shutdown_function(function () use ($saved, $savedRoles) {
    foreach ($saved as $em => $h) if ($h !== false) db()->prepare('UPDATE portal_users SET pw_hash = ? WHERE email = ?')->execute([$h, $em]);
    db()->exec('DELETE FROM bl_resort_roles');
    foreach ($savedRoles as $r) bl_upsert(db(), 'bl_resort_roles', $r, ['email']);
});

final class Client {
    public array $cookies = []; public string $csrf = '';
    public function __construct(public string $base, public string $portal) {}
    public function req(string $method, string $path, $body = null, array $headers = [], bool $raw = false, ?string $root = null): array {
        $h = $headers;
        if ($this->cookies) $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        if ($method === 'POST' && $this->csrf !== '' && !array_filter($h, fn($x) => stripos($x, 'X-CSRF-Token') === 0)) $h[] = 'X-CSRF-Token: ' . $this->csrf;
        $content = null;
        if (is_array($body)) { $h[] = 'Content-Type: application/json'; $content = json_encode($body, JSON_UNESCAPED_UNICODE); }
        elseif (is_string($body)) { $content = $body; }
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0]]);
        $res = file_get_contents(($root ?? $this->base) . '/' . $path, false, $ctx);
        $code = (int)explode(' ', $http_response_header[0])[1];
        foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) $this->cookies[$m[1]] = $m[2];
        $ctype = ''; foreach ($http_response_header as $line) if (stripos($line, 'Content-Type:') === 0) $ctype = trim(substr($line, 13));
        return ['code' => $code, 'json' => $raw ? null : json_decode((string)$res, true), 'body' => (string)$res, 'type' => $ctype];
    }
    public function open(): array { $r = $this->req('GET', 'index.php'); preg_match('/name="csrf-token" content="([^"]+)"/', $r['body'], $m); $this->csrf = $m[1] ?? ''; return $r; }
    /** 포털 로그인(api/login.php) 뒤 리조트 화면을 열어 CSRF 토큰을 받는다 */
    public function login(string $email, string $pw): array {
        $r = $this->req('POST', 'api/login.php', ['email' => $email, 'password' => $pw], [], false, $this->portal);
        if ($r['code'] === 200) $this->open();
        return $r;
    }
    public function upload(string $fileName, string $bytes): array {
        $b = '----resort' . bin2hex(random_bytes(6));
        $body = "--$b\r\nContent-Disposition: form-data; name=\"f\"; filename=\"$fileName\"\r\nContent-Type: application/octet-stream\r\n\r\n$bytes\r\n--$b--\r\n";
        return $this->req('POST', 'api.php?a=upload', $body, ["Content-Type: multipart/form-data; boundary=$b"]);
    }
}
$sum = function (array $bs): array {
    $stay = 0; $meal = 0;
    foreach ($bs as $b) { if ($b['period'] !== '2026') continue; if ($b['status'] === '확정') $stay += $b['roomFee'] + $b['addFee']; if (!in_array($b['status'], ['반려', '취소'], true)) foreach ($b['meals'] as $m) $meal += $m['amount']; }
    return [$stay, $meal];
};
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

echo "DB: " . (is_mysql() ? 'MySQL/MariaDB' : 'SQLite') . "  /  $base\n";

echo "[1] 로그인\n";
$x = new Client($base, $portal);
ok($x->req('GET', 'api.php?a=data')['code'] === 401, '로그인 전 데이터 요청은 거절(401)');
ok($x->req('GET', 'index.php')['code'] === 302, '로그인 전 화면은 포털 로그인으로 보냄');
ok($x->login(T_ADMIN, 'wrong-password')['code'] === 401, '틀린 비밀번호 거절 (포털 로그인)');
$admin = new Client($base, $portal);
ok($admin->login(T_ADMIN, 'admin-test-pw')['code'] === 200 && $admin->csrf !== '', '관리자(김지안) 포털 로그인 → 리조트 화면');
$noCsrf = $admin->req('POST', 'api.php?a=settings', ['handler' => 'x'], ['X-CSRF-Token: wrong']);
ok($noCsrf['code'] === 419, '화면 토큰(CSRF) 없는 저장 요청 거절');

echo "[2] 기존 데이터\n";
$d = $admin->req('GET', 'api.php?a=data')['json'];
ok(count($d['bookings']) === 16, '예약 16건 (' . count($d['bookings']) . ')');
[$st, $ml] = $sum($d['bookings']);
ok($st === 8868200 && $ml === 2474050, '숙박 ' . number_format($st) . ' / 식대 ' . number_format($ml) . ' — 엑셀 원본과 일치');
ok(count($d['settings']['staffByPeriod']['2026']) === 14 && $d['settings']['notice'] !== '', '직원 한도 13명 + 회사 공용, 안내문 문구');
ok(count($d['users']) === 13 && count(array_filter($d['users'], fn($u) => $u['role'] === 'admin')) === 2, '포털 계정 13명, 관리자 2명(김지안 · 김호영)');

echo "[3] 직원 화면은 본인 것만\n";
$staff = new Client($base, $portal);
ok($staff->login(T_STAFF, 'staff-test-pw1')['code'] === 200, '직원(유병문) 포털 로그인');
$sd = $staff->req('GET', 'api.php?a=data')['json'];
$names = array_unique(array_column($sd['bookings'], 'name'));
ok(count($sd['bookings']) === 3 && $names === ['유병문'], '유병문 예약 3건만 보임 (' . count($sd['bookings']) . '건)');
ok(!isset($sd['users']), '직원에게 계정 목록은 내려가지 않음');
ok($staff->req('POST', 'api.php?a=settings', ['handler' => 'x'])['code'] === 403, '직원의 설정 변경 거절');
ok($staff->req('POST', 'api.php?a=delete', ['id' => 'b2026_08'])['code'] === 403, '직원의 삭제 거절');
$led = array_values(array_filter($sd['bookings'], fn($b) => $b['id'] === 'b2026_09'))[0];
$r = $staff->req('POST', 'api.php?a=save', ['id' => $led['id'], 'data' => ['meals' => []] + $led, 'prevVer' => $led['ver']]);
ok($r['code'] === 403, '관리자가 등록한 예약을 직원이 고치려 하면 거절');

echo "[4] 신청 → 확정 → 식대 · 영수증\n";
$new = ['name' => '박성철', 'resort' => '소노캄 거제 스위트', 'ci' => '2026-12-24', 'co' => '2026-12-26', 'rooms' => 1,
    'status' => '확정', 'roomFee' => 1, 'settle' => '정산완료', 'meals' => [['id' => 'mx1', 'date' => '2026-12-24', 'place' => '몰래', 'amount' => 99]]];
$r = $staff->req('POST', 'api.php?a=save', ['id' => 'rtest0001', 'data' => $new]);
ok($r['code'] === 200, '직원 예약 신청 저장');
$a = array_values(array_filter($admin->req('GET', 'api.php?a=data')['json']['bookings'], fn($b) => $b['id'] === 'rtest0001'))[0] ?? null;
ok($a && $a['name'] === '유병문' && $a['status'] === '신청' && $a['roomFee'] === 0 && !$a['meals'] && $a['nights'] === 2 && $a['_src'] === 'req' && $a['_uid'] === T_STAFF,
    '서버가 이름은 본인으로, 상태는 신청으로 고정 (직원이 넣은 확정 · 금액 · 식대 무시)');
$r = $admin->req('POST', 'api.php?a=save', ['id' => 'rtest0001', 'data' => ['status' => '확정', 'roomFee' => 420000, 'resvId' => 'bluesoft', 'card' => '2987'] + $a, 'prevVer' => $a['ver']]);
ok($r['code'] === 200, '관리자 예약 확정 (객실요금 420,000원)');
$stale = $admin->req('POST', 'api.php?a=save', ['id' => 'rtest0001', 'data' => ['status' => '반려'] + $a, 'prevVer' => $a['ver']]);
ok($stale['code'] === 409, '오래된 화면에서 저장하면 충돌 안내(409) — 남의 수정 덮어쓰기 방지');

$up = $staff->upload('영수증.png', $png);
ok($up['code'] === 200 && ($up['json']['type'] ?? '') === 'image/png', '직원 영수증 업로드');
$bad = $staff->upload('악성.php', "<?php echo 1;");
ok($bad['code'] === 400 && $bad['json']['code'] === 'unsupported_type', '이미지 · PDF가 아닌 파일은 거절');
$cur = array_values(array_filter($staff->req('GET', 'api.php?a=data')['json']['bookings'], fn($b) => $b['id'] === 'rtest0001'))[0];
$r = $staff->req('POST', 'api.php?a=save', ['id' => 'rtest0001', 'prevVer' => $cur['ver'], 'data' => ['resort' => '바꿔치기', 'roomFee' => 1,
    'meals' => [['id' => 'm1', 'date' => '2026-12-24', 'place' => '거제 횟집', 'amount' => 120000, 'rc' => [['id' => $up['json']['id']]]],
                ['id' => 'm2', 'date' => '2026-12-25', 'place' => '카페', 'amount' => 15000]], 'facFee' => 30000] + $cur]);
ok($r['code'] === 200, '확정 후 직원 식대 2줄 + 영수증 저장');
$cur = array_values(array_filter($staff->req('GET', 'api.php?a=data')['json']['bookings'], fn($b) => $b['id'] === 'rtest0001'))[0];
ok(count($cur['meals']) === 2 && $cur['meals'][0]['rc'][0]['id'] === $up['json']['id'] && $cur['facFee'] === 30000, '식대 · 영수증 · 부대비용 반영');
ok($cur['resort'] === '소노캄 거제 스위트' && $cur['roomFee'] === 420000, '확정 후에는 직원이 예약 정보 · 금액을 바꿀 수 없음');
$f = $staff->req('GET', 'api.php?a=file&id=' . $up['json']['id'], null, [], true);
ok($f['code'] === 200 && $f['type'] === 'image/png' && $f['body'] === $png, '본인 영수증 열람');
$other = new Client($base, $portal);
$other->login(T_OTHER, 'staff-test-pw2');
ok($other->req('GET', 'api.php?a=file&id=' . $up['json']['id'], null, [], true)['code'] === 404, '다른 직원은 영수증을 열 수 없음');
$od = $other->req('GET', 'api.php?a=data')['json'];
ok(!array_filter($od['bookings'], fn($b) => $b['name'] !== '박성철'), '다른 직원(박성철) 화면에 유병문 예약 없음');
ok($admin->req('GET', 'api.php?a=file&id=' . $up['json']['id'], null, [], true)['code'] === 200, '관리자는 영수증 열람 가능');

echo "[5] 설정 · 권한 · 삭제\n";
$s = $admin->req('GET', 'api.php?a=data')['json']['settings'];
$s['notice'] .= "\n😊 시험 문구";
ok($admin->req('POST', 'api.php?a=settings', $s)['code'] === 200, '설정 저장');
$s2 = $admin->req('GET', 'api.php?a=data')['json']['settings'];
ok(str_ends_with($s2['notice'], '😊 시험 문구') && count($s2['staffByPeriod']['2026']) === 14, '설정 다시 읽기 (이모지 · 한도 유지)');
ok($staff->req('POST', 'api.php?a=role', ['email' => T_STAFF, 'role' => 'admin'])['code'] === 403, '직원은 권한을 바꿀 수 없음');
ok($admin->req('POST', 'api.php?a=role', ['email' => T_ADMIN, 'role' => 'staff'])['code'] === 400, '관리자 본인 권한 내리기 막음');
ok($admin->req('POST', 'api.php?a=role', ['email' => 'nobody@example.com', 'role' => 'admin'])['code'] === 404, '포털에 없는 계정은 관리자로 못 올림');
ok($admin->req('POST', 'api.php?a=role', ['email' => T_STAFF, 'role' => 'admin'])['code'] === 200
    && count($staff->req('GET', 'api.php?a=data')['json']['bookings']) === 17, '유병문을 관리자로 → 전체 17건이 보임');
ok($admin->req('POST', 'api.php?a=role', ['email' => T_STAFF, 'role' => 'staff'])['code'] === 200
    && count($staff->req('GET', 'api.php?a=data')['json']['bookings']) === 4, '다시 직원으로 → 본인 4건만');
$path = db()->query("SELECT path FROM bl_resort_files WHERE id = '" . $up['json']['id'] . "'")->fetchAll(PDO::FETCH_COLUMN)[0] ?? '';
ok($admin->req('POST', 'api.php?a=delete', ['id' => 'rtest0001'])['code'] === 200, '관리자 삭제');
ok(!is_file(upload_dir() . '/' . $path) && (int)db()->query("SELECT COUNT(*) FROM bl_resort_meals WHERE booking_id = 'rtest0001'")->fetchAll(PDO::FETCH_COLUMN)[0] === 0, '삭제 시 식대 줄 · 영수증 파일도 정리');
$s2['notice'] = str_replace("\n😊 시험 문구", '', $s2['notice']);
$admin->req('POST', 'api.php?a=settings', $s2);
$d = $admin->req('GET', 'api.php?a=data')['json'];
[$st, $ml] = $sum($d['bookings']);
ok(count($d['bookings']) === 16 && $st === 8868200 && $ml === 2474050, '시험 후 원래 데이터 그대로 (16건, 합계 일치)');
$logs = (int)db()->query('SELECT COUNT(*) FROM bl_resort_log')->fetchAll(PDO::FETCH_COLUMN)[0];
ok($logs > 5, "작업 기록(log) $logs 건 남음");
ok(is_file(__DIR__ . '/../../var/.htaccess') && str_starts_with(str_replace('\\', '/', upload_dir()), str_replace('\\', '/', realpath(__DIR__ . '/../../var'))), '영수증은 blueledger/var/resort 아래에 저장');

echo "\n결과: 통과 $pass / 실패 $fail\n";
exit($fail ? 1 : 0);

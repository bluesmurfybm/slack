<?php
declare(strict_types=1);

/* 리조트 예약 · 식대 정산 — DB 읽기/쓰기 */

class ApiError extends RuntimeException
{
    public string $codeName;
    public int $http;
    public function __construct(string $codeName, string $message, int $http = 400)
    {
        parent::__construct($message);
        $this->codeName = $codeName;
        $this->http = $http;
    }
}

// 출장여비(guard.php)의 STATUSES 와 이름이 겹치지 않게 RESORT_ 를 붙였다
const RESORT_STATUSES = ['신청', '확정', '반려', '취소'];
const RESORT_SETTLES = ['미정산', '정산완료'];

/* ── 설정 ─────────────────────────────────────────────── */

function load_settings(): array
{
    $out = [];
    foreach (db()->query('SELECT k, v FROM bl_resort_settings') as $r) {
        $out[$r['k']] = json_decode($r['v'], true);
    }
    $sp = [];
    foreach (db()->query('SELECT * FROM bl_resort_limits ORDER BY period, sort, name') as $r) {
        $row = ['name' => $r['name'], 'stay' => (int)$r['stay_limit'], 'meal' => (int)$r['meal_limit'], 'nights' => (int)$r['nights']];
        if ((int)$r['is_company']) $row['company'] = true;
        $sp[$r['period']][] = $row;
    }
    if ($sp) $out['staffByPeriod'] = $sp;
    return $out;
}

function save_settings(array $in, string $by): void
{
    $pdo = db();
    $handler = cut($in['handler'] ?? '김지안', 40) ?: '김지안';
    $accounts = [];
    foreach ((array)($in['accounts'] ?? []) as $a) {
        $id = cut($a['id'] ?? '', 40);
        if ($id === '') continue;
        $accounts[] = ['id' => $id, 'no' => cut($a['no'] ?? '', 40), 'nights' => min(999, money($a['nights'] ?? 0))];
    }
    $cards = [];
    foreach ((array)($in['cards'] ?? []) as $c) {
        $code = cut($c['code'] ?? '', 40);
        if ($code === '') continue;
        $cards[] = ['code' => $code, 'label' => cut($c['label'] ?? '', 80) ?: $code];
    }
    $notice = cut($in['notice'] ?? '', 5000);

    $limits = [];
    foreach ((array)($in['staffByPeriod'] ?? []) as $period => $list) {
        if (!preg_match('/^\d{4}$/', (string)$period)) continue;
        $seen = [];
        foreach (array_values((array)$list) as $i => $s) {
            $name = cut($s['name'] ?? '', 40);
            if ($name === '' || isset($seen[$name])) continue;
            $seen[$name] = 1;
            $limits[] = [(string)$period, $name, money($s['stay'] ?? 0), money($s['meal'] ?? 0), min(366, money($s['nights'] ?? 0)), !empty($s['company']) ? 1 : 0, $i];
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM bl_resort_settings');
        $st = $pdo->prepare('INSERT INTO bl_resort_settings (k, v, updated_at, updated_by) VALUES (?,?,?,?)');
        $t = now();
        foreach (['handler' => $handler, 'accounts' => $accounts, 'cards' => $cards, 'notice' => $notice] as $k => $v) {
            $st->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), $t, $by]);
        }
        if (isset($in['staffByPeriod'])) {
            $pdo->exec('DELETE FROM bl_resort_limits');
            $st = $pdo->prepare('INSERT INTO bl_resort_limits (period, name, stay_limit, meal_limit, nights, is_company, sort) VALUES (?,?,?,?,?,?,?)');
            foreach ($limits as $row) $st->execute($row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ── 예약 읽기 ────────────────────────────────────────── */

/** 이 사용자가 볼 수 있는 예약 조건 (직원: 본인이 올린 것 + 본인 이름으로 등록된 것) */
function visible_where(array $u, string $alias = 'b'): array
{
    if (is_admin($u)) return ['1=1', []];
    return ["($alias.created_by = ? OR $alias.name = ?)", [$u['id'], $u['name']]];
}

function list_bookings(array $u): array
{
    [$w, $p] = visible_where($u);
    $sql = "SELECT b.*, r.role AS creator_role FROM bl_resort_bookings b LEFT JOIN bl_resort_roles r ON r.email = b.created_by WHERE $w ORDER BY b.ci, b.name";
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    if (!$rows) return [];

    $st = db()->prepare("SELECT m.* FROM bl_resort_meals m JOIN bl_resort_bookings b ON b.id = m.booking_id WHERE $w ORDER BY m.booking_id, m.seq, m.cid");
    $st->execute($p);
    $meals = [];
    foreach ($st as $m) $meals[$m['booking_id']][] = $m;

    $st = db()->prepare("SELECT f.id, f.booking_id, f.meal_cid, f.mime, f.orig_name FROM bl_resort_files f JOIN bl_resort_bookings b ON b.id = f.booking_id WHERE $w ORDER BY f.created_at, f.id");
    $st->execute($p);
    $docs = [];
    $mealFiles = [];
    foreach ($st as $f) {
        $o = ['id' => $f['id'], 'type' => $f['mime'], 'name' => $f['orig_name']];
        if ($f['meal_cid'] !== null && $f['meal_cid'] !== '') $mealFiles[$f['booking_id']][$f['meal_cid']][] = $o;
        else $docs[$f['booking_id']][] = $o;
    }

    $out = [];
    foreach ($rows as $r) {
        $id = $r['id'];
        $ms = [];
        foreach ($meals[$id] ?? [] as $m) {
            $ms[] = [
                'id' => $m['cid'], 'date' => (string)($m['use_date'] ?? ''), 'place' => $m['place'],
                'amount' => (int)$m['amount'], 'rc' => $mealFiles[$id][$m['cid']] ?? [],
            ];
        }
        $out[] = [
            'id' => $id, 'period' => $r['period'], 'name' => $r['name'], 'user' => $r['user_name'],
            'dept' => $r['dept'], 'phone' => $r['phone'], 'resort' => $r['resort'],
            'ci' => (string)$r['ci'], 'co' => (string)$r['co'], 'rooms' => (int)$r['rooms'], 'nights' => (int)$r['nights'],
            'purpose' => $r['purpose'], 'wish' => $r['wish'],
            'status' => $r['status'], 'reason' => $r['reason'], 'resvId' => $r['resv_id'], 'card' => $r['card'],
            'resvDate' => (string)($r['resv_date'] ?? ''), 'payDate' => (string)($r['pay_date'] ?? ''),
            'roomFee' => (int)$r['room_fee'], 'addFee' => (int)$r['add_fee'], 'facFee' => (int)$r['fac_fee'], 'etcFee' => (int)$r['etc_fee'],
            'mealCard' => $r['meal_card'], 'cardOut' => (string)($r['card_out'] ?? ''), 'cardIn' => (string)($r['card_in'] ?? ''),
            'settle' => $r['settle'], 'settledAt' => (string)($r['settled_at'] ?? ''), 'adminNote' => $r['admin_note'],
            'meals' => $ms, 'docs' => $docs[$id] ?? [],
            'createdAt' => (string)$r['created_at'], 'updatedAt' => (string)$r['updated_at'], 'ver' => (int)$r['ver'], 'decidedAt' => (string)($r['decided_at'] ?? ''),
            // 'ledger' = 관리자가 등록한 예약, 'req' = 직원이 신청한 예약
            '_src' => ($r['created_by'] === null || $r['creator_role'] === 'admin') ? 'ledger' : 'req',
            '_uid' => $r['created_by'],
        ];
    }
    return $out;
}

/* ── 예약 저장 ────────────────────────────────────────── */

const INFO_COLS  = ['name', 'user_name', 'dept', 'phone', 'resort', 'ci', 'co', 'rooms', 'nights', 'period', 'purpose', 'wish'];
const ADMIN_COLS = ['status', 'reason', 'resv_id', 'card', 'resv_date', 'pay_date', 'room_fee', 'add_fee', 'meal_card', 'card_out', 'card_in', 'settle', 'settled_at', 'admin_note'];
const SPEND_COLS = ['fac_fee', 'etc_fee'];

/** 화면에서 온 값을 검사해 DB 칸 이름으로 바꿈 */
function normalize_booking(array $d): array
{
    $ci = date_or_null($d['ci'] ?? '');
    $co = date_or_null($d['co'] ?? '');
    if (!$ci || !$co) throw new ApiError('bad_date', '체크인 · 체크아웃 날짜를 확인해 주세요.');
    $nights = (int)((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights < 1 || $nights > 60) throw new ApiError('bad_date', '체크아웃은 체크인 다음 날 이후여야 합니다.');
    $resort = cut($d['resort'] ?? '', 200);
    if ($resort === '') throw new ApiError('bad_resort', '리조트 · 객실을 적어 주세요.');
    $status = in_array($d['status'] ?? '', RESORT_STATUSES, true) ? $d['status'] : '신청';
    $settle = in_array($d['settle'] ?? '', RESORT_SETTLES, true) ? $d['settle'] : '미정산';

    $meals = [];
    $seen = [];
    foreach (array_slice((array)($d['meals'] ?? []), 0, 200) as $m) {
        if (!is_array($m)) continue;
        $cid = preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string)($m['id'] ?? '')) ? (string)$m['id'] : 'm' . bin2hex(random_bytes(6));
        if (isset($seen[$cid])) $cid = 'm' . bin2hex(random_bytes(6));
        $seen[$cid] = 1;
        $place = cut($m['place'] ?? '', 100);
        $amount = money($m['amount'] ?? 0);
        $rc = array_values(array_filter(array_map(fn($x) => is_array($x) ? (string)($x['id'] ?? '') : '', (array)($m['rc'] ?? []))));
        if ($place === '' && $amount === 0 && !$rc) continue;
        $meals[] = ['cid' => $cid, 'use_date' => date_or_null($m['date'] ?? ''), 'place' => $place, 'amount' => $amount, 'rc' => $rc];
    }
    $docs = array_values(array_filter(array_map(fn($x) => is_array($x) ? (string)($x['id'] ?? '') : '', (array)($d['docs'] ?? []))));

    return [
        'cols' => [
            'name' => cut($d['name'] ?? '', 40), 'user_name' => cut($d['user'] ?? '', 40),
            'dept' => cut($d['dept'] ?? '', 80), 'phone' => cut($d['phone'] ?? '', 40),
            'resort' => $resort, 'ci' => $ci, 'co' => $co,
            'rooms' => max(1, min(20, (int)($d['rooms'] ?? 1))), 'nights' => $nights, 'period' => period_of($ci),
            'purpose' => cut($d['purpose'] ?? '', 100), 'wish' => cut($d['wish'] ?? '', 1000),
            'status' => $status, 'reason' => cut($d['reason'] ?? '', 200),
            'resv_id' => cut($d['resvId'] ?? '', 40), 'card' => cut($d['card'] ?? '', 40),
            'resv_date' => date_or_null($d['resvDate'] ?? ''), 'pay_date' => date_or_null($d['payDate'] ?? ''),
            'room_fee' => money($d['roomFee'] ?? 0), 'add_fee' => money($d['addFee'] ?? 0),
            'fac_fee' => money($d['facFee'] ?? 0), 'etc_fee' => money($d['etcFee'] ?? 0),
            'meal_card' => cut($d['mealCard'] ?? '', 40), 'card_out' => date_or_null($d['cardOut'] ?? ''), 'card_in' => date_or_null($d['cardIn'] ?? ''),
            'settle' => $settle, 'settled_at' => date_or_null($d['settledAt'] ?? ''), 'admin_note' => cut($d['adminNote'] ?? '', 300),
        ],
        'meals' => $meals,
        'docs' => $docs,
    ];
}

function get_booking_row(string $id, bool $lock = false): ?array
{
    $sql = 'SELECT * FROM bl_resort_bookings WHERE id = ?' . ($lock && is_mysql() ? ' FOR UPDATE' : '');
    $st = db()->prepare($sql);
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * 예약 저장. 직원은 본인 신청만, 정해진 단계에서만 고칠 수 있음 (화면이 아니라 서버에서 검사).
 *  - '신청' 상태: 예약 정보 수정 가능
 *  - '확정' + 정산 전: 식대 · 부대비용 · 영수증 수정 가능
 *  - 처리 칸(확정/반려, 금액, 카드, 정산)은 관리자만
 */
function save_booking(array $u, string $id, array $data, ?int $prevVer): array
{
    $admin = is_admin($u);
    if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $id)) $id = 'r' . bin2hex(random_bytes(8));
    $n = normalize_booking($data);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cur = get_booking_row($id, true);
        if (!$cur && $prevVer) throw new ApiError('gone', '그 사이 삭제된 예약입니다. 화면을 새로 불러왔습니다.', 409);
        if ($cur && $prevVer !== null && $prevVer !== (int)$cur['ver']) {
            throw new ApiError('conflict', '다른 사람이 먼저 이 예약을 고쳤습니다. 새로 불러온 내용을 확인한 뒤 다시 저장해 주세요.', 409);
        }
        $cols = $n['cols'];
        $editMeals = true;

        if (!$admin) {
            $cols['name'] = $u['name'];
            if (!$cur) {
                foreach (ADMIN_COLS as $c) $cols[$c] = null;
                $cols['status'] = '신청';
                $cols['settle'] = '미정산';
                foreach (['reason', 'resv_id', 'card', 'meal_card', 'admin_note'] as $c) $cols[$c] = '';
                foreach (['room_fee', 'add_fee'] as $c) $cols[$c] = 0;
                foreach (SPEND_COLS as $c) $cols[$c] = 0;
                $editMeals = false;
            } else {
                if ($cur['created_by'] !== $u['id']) throw new ApiError('forbidden', '본인이 신청한 예약만 고칠 수 있습니다. 정산 담당자에게 문의해 주세요.', 403);
                $infoOk = $cur['status'] === '신청';
                $spendOk = $cur['status'] === '확정' && $cur['settle'] !== '정산완료';
                if (!$infoOk && !$spendOk) throw new ApiError('locked', '이 단계에서는 고칠 수 없습니다. 정산 담당자에게 문의해 주세요.', 403);
                foreach (ADMIN_COLS as $c) $cols[$c] = $cur[$c];
                if (!$infoOk) foreach (INFO_COLS as $c) $cols[$c] = $cur[$c];
                if (!$spendOk) {
                    foreach (SPEND_COLS as $c) $cols[$c] = $cur[$c];
                    $editMeals = false;
                }
            }
        }

        $t = now();
        if (!$cur) {
            $cols['id'] = $id;
            $cols['created_by'] = $u['id'];
            $cols['created_at'] = $t;
            $cols['updated_at'] = $t;
            $cols['decided_at'] = $cols['status'] !== '신청' ? $t : null;
            $keys = array_keys($cols);
            $pdo->prepare('INSERT INTO bl_resort_bookings (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')
                ->execute(array_values($cols));
        } else {
            $cols['updated_at'] = $t;
            $cols['decided_at'] = $cols['status'] !== $cur['status'] ? $t : $cur['decided_at'];
            $cols['ver'] = (int)$cur['ver'] + 1;
            $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($cols)));
            $pdo->prepare("UPDATE bl_resort_bookings SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
        }

        if ($editMeals) {
            $pdo->prepare('DELETE FROM bl_resort_meals WHERE booking_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO bl_resort_meals (booking_id, cid, seq, use_date, place, amount) VALUES (?,?,?,?,?,?)');
            foreach ($n['meals'] as $i => $m) $ins->execute([$id, $m['cid'], $i, $m['use_date'], $m['place'], $m['amount']]);
            link_files($u, $id, $n['docs'], $n['meals']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($u['id'], $cur ? 'update' : 'create', $id, ($cols['name'] ?? '') . ' ' . $cols['status'] . ' ' . $cols['ci']);
    return ['id' => $id, 'updatedAt' => $t, 'ver' => $cur ? (int)$cur['ver'] + 1 : 1];
}

/** 첨부파일을 예약/식대 줄에 연결. 빠진 파일은 지움. 남의 파일은 연결 못 함 */
function link_files(array $u, string $bookingId, array $docIds, array $meals): void
{
    $pdo = db();
    $want = [];
    foreach ($docIds as $fid) $want[$fid] = '';
    foreach ($meals as $m) foreach ($m['rc'] as $fid) $want[$fid] = $m['cid'];

    $st = $pdo->prepare('SELECT id, path FROM bl_resort_files WHERE booking_id = ?');
    $st->execute([$bookingId]);
    $removed = [];
    foreach ($st as $f) if (!isset($want[$f['id']])) $removed[] = $f;

    $get = $pdo->prepare('SELECT id, booking_id, uploaded_by FROM bl_resort_files WHERE id = ?');
    $upd = $pdo->prepare('UPDATE bl_resort_files SET booking_id = ?, meal_cid = ? WHERE id = ?');
    foreach ($want as $fid => $mealCid) {
        if (!preg_match('/^[a-f0-9]{24}$/', (string)$fid)) continue;
        $get->execute([$fid]);
        $f = $get->fetch();
        if (!$f) continue;
        $mine = $f['uploaded_by'] === $u['id'];
        $same = $f['booking_id'] === $bookingId;
        if (!$same && !(is_admin($u) || ($mine && $f['booking_id'] === null))) continue;
        $upd->execute([$bookingId, $mealCid === '' ? null : $mealCid, $fid]);
    }
    foreach ($removed as $f) delete_file_row($f);
}

function delete_file_row(array $f): void
{
    db()->prepare('DELETE FROM bl_resort_files WHERE id = ?')->execute([$f['id']]);
    $abs = upload_dir() . '/' . $f['path'];
    if (is_file($abs)) @unlink($abs);
}

function delete_booking(array $u, string $id): void
{
    if (!is_admin($u)) throw new ApiError('forbidden', '삭제는 관리자만 할 수 있습니다.', 403);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, path FROM bl_resort_files WHERE booking_id = ?');
        $st->execute([$id]);
        $files = $st->fetchAll();
        $pdo->prepare('DELETE FROM bl_resort_meals WHERE booking_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM bl_resort_bookings WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    foreach ($files as $f) delete_file_row($f);
    audit($u['id'], 'delete', $id);
}

/* ── 첨부파일 ─────────────────────────────────────────── */

function upload_dir(): string
{
    if (!is_dir(RESORT_UPLOAD_DIR)) mkdir(RESORT_UPLOAD_DIR, 0775, true);
    return realpath(RESORT_UPLOAD_DIR) ?: RESORT_UPLOAD_DIR;
}

function store_upload(array $u, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $big = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        throw new ApiError($big ? 'too_large' : 'upload_failed', $big ? '파일이 너무 큽니다.' : '파일을 받지 못했습니다.');
    }
    if ((int)$file['size'] > RESORT_MAX_UPLOAD_MB * 1024 * 1024) throw new ApiError('too_large', '파일은 ' . RESORT_MAX_UPLOAD_MB . 'MB 이하만 올릴 수 있습니다.');
    $type = sniff_type($file['tmp_name']);
    if (!$type) throw new ApiError('unsupported_type', 'JPG · PNG · GIF · WEBP · PDF 파일만 올릴 수 있습니다.');
    [$mime, $ext] = $type;

    $id = bin2hex(random_bytes(12));
    $rel = date('Y/m') . '/' . $id . '.' . $ext;
    $abs = upload_dir() . '/' . $rel;
    if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
    $ok = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $abs) : rename($file['tmp_name'], $abs);
    if (!$ok) throw new ApiError('upload_failed', '파일을 저장하지 못했습니다. 서버 폴더 권한을 확인해 주세요.', 500);

    $name = cut(basename(str_replace('\\', '/', (string)($file['name'] ?? 'file'))), 200);
    db()->prepare('INSERT INTO bl_resort_files (id, booking_id, meal_cid, orig_name, mime, size, path, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, null, null, $name, $mime, (int)filesize($abs), $rel, $u['id'], now()]);
    return ['id' => $id, 'type' => $mime, 'name' => $name];
}

function can_read_file(array $u, array $f): bool
{
    if (is_admin($u) || $f['uploaded_by'] === $u['id']) return true;
    if ($f['booking_id'] === null) return false;
    [$w, $p] = visible_where($u);
    $st = db()->prepare("SELECT 1 FROM bl_resort_bookings b WHERE b.id = ? AND $w");
    $st->execute([$f['booking_id'], ...$p]);
    return (bool)$st->fetchColumn();
}

/* ── 관리자 권한 ──────────────────────────────────────── */
// 계정·비밀번호는 포털이 맡는다. 여기서는 포털 계정 중 누가 리조트 관리자인지만 고른다.

function list_roles(): array
{
    $admins = array_flip(db()->query("SELECT email FROM bl_resort_roles WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    foreach (bl_members() as $m) {
        $out[] = ['email' => $m['email'], 'name' => $m['name'], 'role' => isset($admins[$m['email']]) ? 'admin' : 'staff'];
    }
    return $out;
}

function set_role(array $me, string $email, string $role): void
{
    $email = strtolower(trim($email));
    if (!in_array($email, array_column(bl_members(), 'email'), true)) throw new ApiError('bad_user', '포털에 없는 계정입니다.', 404);
    if ($email === $me['email'] && $role !== 'admin') throw new ApiError('self', '본인 계정의 관리자 권한은 직접 내릴 수 없습니다.');
    if ($role === 'admin') {
        bl_upsert(db(), 'bl_resort_roles', ['email' => $email, 'role' => 'admin', 'updated_by' => $me['email'], 'updated_at' => now()], ['email']);
    } else {
        db()->prepare('DELETE FROM bl_resort_roles WHERE email = ?')->execute([$email]);
    }
}

<?php
/**
 * 출장여비 청구서 — 조회·저장·삭제와 권한 판정.
 *
 * 화면은 파이썬 버전과 같은 camelCase 객체를 주고받는다(traveler, date, etcNote …).
 * DB 컬럼과의 대응은 bl_trip_out() 한 곳에만 둔다.
 */

/** DB 행 → 화면 객체 */
function bl_trip_out(array $r) {
    $rc = json_decode((string)$r['receipts'], true);
    $receipts = [];
    foreach (RC_KINDS as $k) $receipts[$k] = is_array($rc[$k] ?? null) ? $rc[$k] : [];
    return [
        'id' => $r['id'], 'traveler' => $r['traveler'], 'companions' => $r['companions'],
        'companionCount' => (int)$r['companion_count'], 'date' => $r['trip_date'], 'period' => $r['period'],
        'place' => $r['place'], 'purpose' => $r['purpose'], 'mode' => $r['mode'], 'km' => (float)$r['km'],
        'transport' => (int)$r['transport'], 'toll' => (int)$r['toll'], 'meal' => (int)$r['meal'],
        'etc' => (int)$r['etc'], 'etcNote' => $r['etc_note'], 'total' => (int)$r['total'],
        'receipts' => $receipts,
        'approval' => $r['approval'], 'approver' => $r['approver'], 'approvedAt' => $r['approved_at'],
        'status' => $r['status'], 'handler' => $r['handler'], 'processedAt' => $r['processed_at'],
        'payMethod' => $r['pay_method'], 'note' => $r['note'], 'source' => $r['source'],
        'createdBy' => $r['created_by_name'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at'],
        'staffUpdatedAt' => $r['staff_updated_at'], 'reviewedAt' => $r['reviewed_at'],
    ];
}

/** 직원은 본인이 올렸거나 본인이 출장자인 건만 본다 */
function bl_is_mine(array $row, array $me) {
    return ($row['created_by'] !== '' && $row['created_by'] === $me['email'])
        || $row['traveler'] === $me['name'];
}

function bl_visible_trips(PDO $pdo, array $me) {
    if (bl_can_see_all($me)) {
        $rows = $pdo->query("SELECT * FROM bl_travel_trips ORDER BY trip_date, id")->fetchAll();
    } else {
        $st = $pdo->prepare("SELECT * FROM bl_travel_trips WHERE created_by=? OR traveler=? ORDER BY trip_date, id");
        $st->execute([$me['email'], $me['name']]);
        $rows = $st->fetchAll();
    }
    return array_map('bl_trip_out', $rows);
}

function bl_fetch_trip(PDO $pdo, $id, $lock = false) {
    $st = $pdo->prepare("SELECT * FROM bl_travel_trips WHERE id=?" . ($lock ? bl_for_update($pdo) : ''));
    $st->execute([(string)$id]);
    return $st->fetch() ?: null;
}

/**
 * 저장(새로 쓰기·고치기·승인·정산이 모두 여기로 온다). 누가 무엇을 바꿀 수 있는지:
 *  - 정산 담당: 내용 + 승인 + 정산 전부
 *  - 대표 승인: 승인 칸(승인/반려·승인자·승인일)만. 새 청구는 쓰지 않는다
 *  - 직원    : 본인 청구의 내용만, 승인대기일 때만. 승인·정산 칸은 보내도 무시한다
 */
function bl_save_trip(PDO $pdo, array $me, array $body) {
    $id   = trim((string)($body['id'] ?? ''));
    $data = is_array($body['data'] ?? null) ? $body['data'] : [];
    $role = $me['role'];
    $now  = now_stamp();

    $pdo->beginTransaction();
    try {
        $prev = $id !== '' ? bl_fetch_trip($pdo, $id, true) : null;
        if ($id !== '' && !$prev) throw new BlError('청구서를 찾을 수 없습니다. 이미 삭제되었을 수 있습니다', 404);

        if (!$prev) {
            if ($role === ROLE_APPROVER) throw new BlError('대표 승인 계정은 청구서를 새로 쓰지 않습니다', 403);
            $row = [
                'id' => 't' . bin2hex(random_bytes(6)), 'approval' => '승인대기', 'status' => '미처리',
                'created_by' => $me['email'], 'created_by_name' => $me['name'], 'created_at' => $now,
                'receipts' => null, 'source' => '', 'staff_updated_at' => '', 'reviewed_at' => '',
                'approver' => '', 'approved_at' => '', 'handler' => '', 'processed_at' => '',
                'pay_method' => '', 'note' => '', 'companion_count' => 0,
            ];
        } else {
            $row = $prev;
            if ($role === ROLE_STAFF) {
                if (!bl_is_mine($prev, $me)) throw new BlError('본인 청구만 고칠 수 있습니다', 403);
                if ($prev['approval'] !== '승인대기') throw new BlError('승인된 청구는 고칠 수 없습니다. 정산 담당자에게 알려 주세요', 403);
            }
        }

        if ($role === ROLE_ADMIN || $role === ROLE_STAFF) {
            bl_apply_content($pdo, $row, $data, $me, $prev);
            if ($role === ROLE_STAFF) $row['staff_updated_at'] = $now;
        }
        if ($role === ROLE_ADMIN || $role === ROLE_APPROVER) {
            $before = [$row['approval'], $row['approver'], $row['approved_at']];
            if (array_key_exists('approval', $data)) {
                $a = (string)$data['approval'];
                if (!in_array($a, APPROVALS, true)) throw new BlError('승인 상태가 올바르지 않습니다', 400);
                $row['approval'] = $a;
            }
            if (array_key_exists('approver', $data))   $row['approver']    = clip($data['approver'], 60);
            if (array_key_exists('approvedAt', $data)) $row['approved_at'] = to_date_str($data['approvedAt']);
            if ($before !== [$row['approval'], $row['approver'], $row['approved_at']]) $row['reviewed_at'] = $now;
        }
        if ($role === ROLE_ADMIN) {
            if (array_key_exists('status', $data)) {
                $s = (string)$data['status'];
                if (!in_array($s, STATUSES, true)) throw new BlError('처리 상태가 올바르지 않습니다', 400);
                $row['status'] = $s;
            }
            if (array_key_exists('handler', $data))     $row['handler']      = clip($data['handler'], 60);
            if (array_key_exists('processedAt', $data)) $row['processed_at'] = to_date_str($data['processedAt']);
            if (array_key_exists('payMethod', $data))   $row['pay_method']   = clip($data['payMethod'], 30);
            if (array_key_exists('note', $data))        $row['note']         = clip($data['note'], 500);
        }

        if (trim((string)($row['traveler'] ?? '')) === '' || ($row['trip_date'] ?? '') === '' || trim((string)($row['place'] ?? '')) === '') {
            throw new BlError('출장자, 출장일, 출장지는 꼭 입력해야 합니다', 400);
        }
        $row['total'] = (int)$row['transport'] + (int)$row['toll'] + (int)$row['meal'] + (int)$row['etc'];
        $row['updated_at'] = $now;

        bl_write_trip($pdo, $row, (bool)$prev);
        bl_link_files($pdo, $row);
        $pdo->commit();
        return $row['id'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** 청구 내용 칸(출장 정보·명세·영수증) */
function bl_apply_content(PDO $pdo, array &$row, array $d, array $me, $prev) {
    $map = ['traveler' => 60, 'companions' => 500, 'place' => 200, 'purpose' => 500, 'mode' => 50, 'etcNote' => 300];
    $col = ['traveler' => 'traveler', 'companions' => 'companions', 'place' => 'place', 'purpose' => 'purpose', 'mode' => 'mode', 'etcNote' => 'etc_note'];
    foreach ($map as $k => $len) {
        if (array_key_exists($k, $d)) $row[$col[$k]] = clip($d[$k], $len);
        elseif (!$prev) $row[$col[$k]] = '';
    }
    if (array_key_exists('date', $d) || !$prev) $row['trip_date'] = to_date_str($d['date'] ?? '');
    if (array_key_exists('period', $d) || !$prev) {
        $p = clip($d['period'] ?? '당일', 20);
        $row['period'] = $p === '' ? '당일' : $p;
    }
    if (array_key_exists('companionCount', $d)) $row['companion_count'] = max(0, (int)$d['companionCount']);
    if (array_key_exists('km', $d) || !$prev)   $row['km'] = max(0, min(99999, round((float)($d['km'] ?? 0), 1)));
    foreach (RC_KINDS as $k) {
        if (array_key_exists($k, $d) || !$prev) $row[$k] = to_won($d[$k] ?? 0);
    }
    if (array_key_exists('receipts', $d) || !$prev) {
        $row['receipts'] = json_encode(bl_clean_receipts($pdo, $d['receipts'] ?? [], $me, $prev), JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 화면이 보낸 영수증 목록을 믿지 않고 서버 기록과 맞춰 본다.
 * 남는 것: 이 청구서에 이미 붙어 있던 파일 + 내가 올렸고 아직 어디에도 안 붙은 파일.
 */
function bl_clean_receipts(PDO $pdo, $in, array $me, $prev) {
    $out = [];
    $st = $pdo->prepare("SELECT * FROM bl_travel_files WHERE id=?");
    foreach (RC_KINDS as $k) {
        $out[$k] = [];
        foreach ((is_array($in[$k] ?? null) ? $in[$k] : []) as $r) {
            $fid = basename((string)($r['id'] ?? ''));
            if ($fid === '') continue;
            $st->execute([$fid]);
            $f = $st->fetch();
            if (!$f) continue;
            $ownTrip = $prev && $f['trip_id'] === $prev['id'];
            $fresh   = $f['trip_id'] === null && ($f['uploaded_by'] === $me['email'] || $me['role'] === ROLE_ADMIN);
            if (!$ownTrip && !$fresh) continue;
            $out[$k][] = ['id' => $fid, 'type' => $f['mime'], 'name' => clip($r['name'] ?? $f['orig_name'], 120)];
        }
    }
    return $out;
}

function bl_write_trip(PDO $pdo, array $row, $exists) {
    $cols = ['traveler', 'companions', 'companion_count', 'trip_date', 'period', 'place', 'purpose', 'mode', 'km',
        'transport', 'toll', 'meal', 'etc', 'etc_note', 'total', 'receipts', 'approval', 'approver', 'approved_at',
        'status', 'handler', 'processed_at', 'pay_method', 'note', 'source', 'created_by', 'created_by_name',
        'created_at', 'updated_at', 'staff_updated_at', 'reviewed_at'];
    $vals = array_map(fn($c) => $row[$c] ?? '', $cols);
    if ($exists) {
        $set = implode(',', array_map(fn($c) => "`$c`=?", $cols));
        $pdo->prepare("UPDATE bl_travel_trips SET $set WHERE id=?")->execute([...$vals, $row['id']]);
    } else {
        $names = implode(',', array_map(fn($c) => "`$c`", ['id', ...$cols]));
        $qs = implode(',', array_fill(0, count($cols) + 1, '?'));
        $pdo->prepare("INSERT INTO bl_travel_trips ($names) VALUES ($qs)")->execute([$row['id'], ...$vals]);
    }
}

/** 청구서에서 빠진 영수증은 다시 '안 붙음'으로, 들어간 것은 이 청구서로 묶는다 */
function bl_link_files(PDO $pdo, array $row) {
    $rc  = json_decode((string)$row['receipts'], true) ?: [];
    $ids = [];
    foreach (RC_KINDS as $k) {
        foreach ($rc[$k] ?? [] as $r) $ids[$r['id']] = $k;
    }
    $pdo->prepare("UPDATE bl_travel_files SET trip_id=NULL, kind='' WHERE trip_id=?")->execute([$row['id']]);
    $up = $pdo->prepare("UPDATE bl_travel_files SET trip_id=?, kind=? WHERE id=?");
    foreach ($ids as $fid => $k) $up->execute([$row['id'], $k, $fid]);
}

function bl_delete_trip(PDO $pdo, $id) {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM bl_travel_trips WHERE id=?")->execute([(string)$id]);
    // 파일 본체는 바로 지우지 않는다 — 실수로 지운 청구를 되살릴 때 영수증이 남아 있어야 한다.
    // 어디에도 안 붙은 파일로 돌려놓을 뿐이다.
    $pdo->prepare("UPDATE bl_travel_files SET trip_id=NULL WHERE trip_id=?")->execute([(string)$id]);
    $pdo->commit();
}

/* ---------- CSV ---------- */

function bl_send_csv(array $trips, $year) {
    $fname = '출장여비_' . ($year === 'all' ? '전체' : $year) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($fname));
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // 엑셀이 한글을 깨지 않게
    fputcsv($out, ['No.', '출장자', '동행자', '동행자수', '출장일', '기간', '출장지', '출장목적', '교통수단', '교통비', '통행료', '식대', '기타', '기타내역', '합계',
        '영수증', '승인', '승인자', '승인일', '처리여부', '처리자', '처리일시', '지급방법', '비고']);
    foreach (array_values($trips) as $i => $t) {
        $rc = 0;
        foreach ($t['receipts'] as $list) $rc += count($list);
        fputcsv($out, [$i + 1, $t['traveler'], $t['companions'], $t['companionCount'], $t['date'], $t['period'], $t['place'], $t['purpose'],
            $t['mode'], $t['transport'], $t['toll'], $t['meal'], $t['etc'], $t['etcNote'], $t['total'],
            $rc, $t['approval'], $t['approver'], $t['approvedAt'], $t['status'], $t['handler'], $t['processedAt'], $t['payMethod'], $t['note']]);
    }
    fclose($out);
    exit;
}

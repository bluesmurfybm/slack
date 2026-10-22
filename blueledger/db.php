<?php
/**
 * BlueLedger 모듈 DB.
 *
 *  포털과 같은 DB 에 bl_* 테이블로 들어간다(portal_db()). 운영은 MySQL, 로컬 시험은 SQLite 다
 *  (core/sqlite_compat.php). 테이블은 아래 BL_SCHEMA 한 곳에 적고 DDL 은 드라이버에 맞춰 만들며,
 *  둘이 다르게 읽는 문법(upsert·잠금)은 아래 도우미로만 쓴다 — 번역기에 기대지 않는다.
 *
 *  파이썬 버전(travel/server.py)은 청구 한 건을 JSON 덩어리 하나로 들고 있었지만, 여기서는
 *  칸마다 컬럼을 둔다 — 나중에 회계 장부 쪽이 "정산 완료된 출장여비"를 금액·지급일로 바로
 *  집계해야 하기 때문이다. 날짜는 learn 과 같이 문자열(YYYY-MM-DD)로 둔다.
 */

require_once __DIR__ . '/../core/db.php';   // portal_db()

// 컬럼은 [MySQL 타입, 기본값(null 이면 NULL 허용), 설명]. 키 이름 => 컬럼 목록.
const BL_SCHEMA = [
    'bl_travel_trips' => [
        'comment' => '출장여비 청구서',
        'pk' => 'id',
        'cols' => [
            'id'               => ['VARCHAR(40)',  false, '옛 기록은 파이썬 시절 id 그대로(x20201211-29), 새 건은 t+난수'],
            'traveler'         => ['VARCHAR(60)',  '',    '출장자 이름'],
            'companions'       => ['VARCHAR(500)', '',    '동행자 이름, 쉼표 구분(외부 동행자 포함)'],
            'companion_count'  => ['INT',          0,     '옛 엑셀 기록은 이름 없이 인원만 있다'],
            'trip_date'        => ['VARCHAR(10)',  '',    '출장일 YYYY-MM-DD'],
            'period'           => ['VARCHAR(20)',  '당일', ''],
            'place'            => ['VARCHAR(200)', '',    ''],
            'purpose'          => ['VARCHAR(500)', '',    ''],
            'mode'             => ['VARCHAR(50)',  '',    '교통 수단'],
            'km'               => ['DECIMAL(8,1)', 0,     '자가용 편도 거리'],
            'transport'        => ['INT',          0,     ''],
            'toll'             => ['INT',          0,     ''],
            'meal'             => ['INT',          0,     ''],
            'etc'              => ['INT',          0,     ''],
            'etc_note'         => ['VARCHAR(300)', '',    ''],
            'total'            => ['INT',          0,     '네 항목 합. 저장할 때 서버가 다시 계산한다'],
            'receipts'         => ['TEXT',         null,  '{"transport":[{id,type,name}],...} — 파일 본체는 bl_travel_files'],
            'approval'         => ['VARCHAR(10)',  '승인대기', '승인대기/승인/반려'],
            'approver'         => ['VARCHAR(60)',  '',    ''],
            'approved_at'      => ['VARCHAR(10)',  '',    ''],
            'status'           => ['VARCHAR(10)',  '미처리', '미처리/처리완료'],
            'handler'          => ['VARCHAR(60)',  '',    ''],
            'processed_at'     => ['VARCHAR(10)',  '',    ''],
            'pay_method'       => ['VARCHAR(30)',  '',    ''],
            'note'             => ['VARCHAR(500)', '',    ''],
            'source'           => ['VARCHAR(30)',  '',    '옛 엑셀에서 옮긴 기록이면 그 표시'],
            'created_by'       => ['VARCHAR(190)', '',    '올린 사람 이메일. 옛 기록은 빈칸'],
            'created_by_name'  => ['VARCHAR(60)',  '',    ''],
            'created_at'       => ['VARCHAR(19)',  '',    ''],
            'updated_at'       => ['VARCHAR(19)',  '',    ''],
            'staff_updated_at' => ['VARCHAR(19)',  '',    '직원이 마지막으로 고친 시각 — 승인 뒤에 고쳤는지 표시용'],
            'reviewed_at'      => ['VARCHAR(19)',  '',    '승인 상태를 마지막으로 바꾼 시각'],
        ],
        'keys' => ['ix_trip_date' => ['trip_date'], 'ix_trip_owner' => ['created_by'], 'ix_trip_traveler' => ['traveler']],
    ],
    // 영수증 파일. 청구서를 저장하기 전에 먼저 올라오므로 trip_id 는 비어 있을 수 있다.
    // 누가 올렸는지를 남겨 두어야 직원이 남의 영수증 번호를 청구서에 끼워 넣지 못한다.
    'bl_travel_files' => [
        'comment' => '출장여비 영수증 파일',
        'pk' => 'id',
        'cols' => [
            'id'          => ['VARCHAR(64)',  false, '저장 파일명(서버가 지은 난수). var/receipts 아래'],
            'trip_id'     => ['VARCHAR(40)',  null,  '붙은 청구서. 저장 전이면 NULL'],
            'kind'        => ['VARCHAR(10)',  '',    'transport/toll/meal/etc'],
            'orig_name'   => ['VARCHAR(255)', '',    ''],
            'mime'        => ['VARCHAR(60)',  '',    ''],
            'size'        => ['INT',          0,     ''],
            'uploaded_by' => ['VARCHAR(190)', '',    ''],
            'created_at'  => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => ['ix_file_trip' => ['trip_id'], 'ix_file_owner' => ['uploaded_by']],
    ],
    // 권한. 여기 없는 계정은 전부 직원(staff)이다.
    'bl_travel_roles' => [
        'comment' => '출장여비 권한 — 정산 담당·대표 승인만 적는다',
        'pk' => 'email',
        'cols' => [
            'email'      => ['VARCHAR(190)', false, ''],
            'role'       => ['VARCHAR(10)',  '',    'admin(정산 담당) / approver(대표 승인)'],
            'updated_by' => ['VARCHAR(190)', '',    ''],
            'updated_at' => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => [],
    ],
    // 설정·유가. 키 하나에 JSON 하나 — 칸이 자주 늘어나는 값이라 컬럼으로 두지 않는다.
    'bl_travel_settings' => [
        'comment' => '출장여비 설정',
        'pk' => 'k',
        'cols' => [
            'k'          => ['VARCHAR(30)', false, 'main(기본 설정) / prices(최근 유가)'],
            'v'          => ['MEDIUMTEXT',  false, ''],
            'updated_at' => ['VARCHAR(19)', '',    ''],
        ],
        'keys' => [],
    ],
];

function bl_db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $pdo = portal_db();
    foreach (BL_SCHEMA as $table => $def) {
        foreach (bl_ddl($pdo, $table, $def) as $sql) $pdo->exec($sql);
    }
    bl_seed($pdo);
    return $pdo;
}

function bl_is_sqlite(PDO $pdo) {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/** BL_SCHEMA 한 항목 → CREATE TABLE (+ SQLite 는 CREATE INDEX) */
function bl_ddl(PDO $pdo, $table, array $def) {
    $sqlite = bl_is_sqlite($pdo);
    $lines = [];
    foreach ($def['cols'] as $name => [$type, $default, $comment]) {
        if ($sqlite) {
            $type = preg_match('/INT/', $type) ? 'INTEGER' : (preg_match('/DECIMAL/', $type) ? 'REAL' : 'TEXT');
        }
        $line = "`$name` $type";
        if ($default === null) $line .= ' NULL';
        elseif ($default === false) $line .= ' NOT NULL';
        else $line .= ' NOT NULL DEFAULT ' . (is_int($default) ? $default : $pdo->quote($default));
        if (!$sqlite && $comment !== '') $line .= ' COMMENT ' . $pdo->quote($comment);
        $lines[] = $line;
    }
    $lines[] = 'PRIMARY KEY (`' . implode('`,`', (array)$def['pk']) . '`)';
    $out = [];
    if ($sqlite) {
        $out[] = "CREATE TABLE IF NOT EXISTS `$table` (\n  " . implode(",\n  ", $lines) . "\n)";
        foreach ($def['keys'] as $k => $cols) {
            $out[] = "CREATE INDEX IF NOT EXISTS `{$k}` ON `$table` (`" . implode('`,`', $cols) . "`)";
        }
    } else {
        foreach ($def['keys'] as $k => $cols) $lines[] = "KEY `$k` (`" . implode('`,`', $cols) . "`)";
        $out[] = "CREATE TABLE IF NOT EXISTS `$table` (\n  " . implode(",\n  ", $lines) . "\n)"
               . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=' . $pdo->quote($def['comment']);
    }
    return $out;
}

/** 있으면 고치고 없으면 넣는다. $keys 는 기본키 컬럼. */
function bl_upsert(PDO $pdo, $table, array $data, array $keys) {
    $cols = array_keys($data);
    $names = implode(',', array_map(fn($c) => "`$c`", $cols));
    $qs = implode(',', array_fill(0, count($cols), '?'));
    $upd = array_values(array_diff($cols, $keys));
    if (bl_is_sqlite($pdo)) {
        $set = implode(',', array_map(fn($c) => "`$c`=excluded.`$c`", $upd));
        $sql = "INSERT INTO `$table` ($names) VALUES ($qs) ON CONFLICT(`" . implode('`,`', $keys) . "`) DO UPDATE SET $set";
    } else {
        $set = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $upd));
        $sql = "INSERT INTO `$table` ($names) VALUES ($qs) ON DUPLICATE KEY UPDATE $set";
    }
    $pdo->prepare($sql)->execute(array_values($data));
}

/** 기본키가 겹치면 조용히 건너뛴다 */
function bl_insert_ignore(PDO $pdo, $table, array $data) {
    $cols = array_keys($data);
    $names = implode(',', array_map(fn($c) => "`$c`", $cols));
    $qs = implode(',', array_fill(0, count($cols), '?'));
    $verb = bl_is_sqlite($pdo) ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $pdo->prepare("$verb INTO `$table` ($names) VALUES ($qs)")->execute(array_values($data));
}

/** 한 번에 한 요청만 하게 할 일(유가 받기)의 잠금. SQLite(혼자 쓰는 로컬)는 잠그지 않는다. */
function bl_try_lock(PDO $pdo, $name) {
    if (bl_is_sqlite($pdo)) return true;
    $st = $pdo->prepare("SELECT GET_LOCK(?, 0)");
    $st->execute([$name]);
    return (bool)(int)$st->fetchColumn();
}

function bl_unlock(PDO $pdo, $name) {
    if (bl_is_sqlite($pdo)) return;
    $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$name]);
}

/** 트랜잭션 안에서 읽은 행을 남이 못 고치게. SQLite 는 쓰기 트랜잭션 자체가 파일을 잠근다. */
function bl_for_update(PDO $pdo) {
    return bl_is_sqlite($pdo) ? '' : ' FOR UPDATE';
}

/** 초기 데이터는 비어 있을 때만 넣는다 */
function bl_seed(PDO $pdo) {
    if (!(int)$pdo->query("SELECT COUNT(*) FROM bl_travel_roles")->fetchColumn()) {
        foreach (BL_SEED_ROLES as $email => $role) {
            bl_insert_ignore($pdo, 'bl_travel_roles', ['email' => $email, 'role' => $role, 'updated_by' => 'seed', 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }
}

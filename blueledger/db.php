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

    /* ---------- 회계 장부(BlueLedger) ----------
     * 파이썬 버전(app.py)의 SQLite 표를 그대로 옮겼다. 컬럼 이름·뜻은 같고, 표 이름에 bl_ledger_ 를 붙였다.
     * 다른 점: settings 의 key/value 는 MySQL 예약어라 k/v 로, 비어 있으면 '' 인 글자 칸은 NOT NULL 로 했다.
     * 파이썬의 users(이름+비밀번호) 표는 없다 — 포털 로그인 + bl_ledger_roles(출입 명단)로 바뀌었다.
     * 'auto' 는 새 행을 넣을 때 번호가 저절로 붙는 컬럼, 'unique' 는 겹치면 안 되는 컬럼 묶음이다.
     */
    'bl_ledger_accounts' => [
        'comment' => '장부 계좌·카드',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'              => ['INT',          false, ''],
            'name'            => ['VARCHAR(100)', false, ''],
            'kind'            => ['VARCHAR(10)',  false, '계좌 / 카드'],
            'institution'     => ['VARCHAR(100)', '',    ''],
            'opening_balance' => ['BIGINT',       0,     '기초잔액'],
            'opening_date'    => ['VARCHAR(10)',  '',    ''],
            'mapping'         => ['TEXT',         null,  '엑셀 열 설정 기억(JSON)'],
            'pay_keyword'     => ['VARCHAR(200)', '',    '카드대금 출금 적요 글자, 쉼표 구분'],
            'pay_day'         => ['INT',          0,     '매달 결제일(0 이면 23일로 본다)'],
            'pay_start'       => ['VARCHAR(10)',  '',    '카드대금 연결 시작일'],
            'pay_end'         => ['VARCHAR(10)',  '',    '카드대금 연결 종료일'],
            'pay_group'       => ['INT',          null,  '함께 결제되는 대표 카드 id'],
            'created_at'      => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => [],
    ],
    'bl_ledger_tx' => [
        'comment' => '장부 거래(통장·카드 한 줄)',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'             => ['INT',          false, ''],
            'account_id'     => ['INT',          false, ''],
            'tx_date'        => ['VARCHAR(10)',  false, 'YYYY-MM-DD'],
            'content'        => ['VARCHAR(300)', '',    '은행·카드 적요(가맹점)'],
            'amount_in'      => ['BIGINT',       0,     ''],
            'amount_out'     => ['BIGINT',       0,     ''],
            'category'       => ['VARCHAR(60)',  '',    '계정과목. 빈칸이면 미분류'],
            'memo'           => ['VARCHAR(500)', '',    ''],
            'source_file'    => ['VARCHAR(200)', '',    '가져온 파일 이름 / 수기 입력 / 맞춰보기 조정'],
            'dedup_key'      => ['VARCHAR(120)', null,  '같은 거래를 두 번 넣지 않기 위한 열쇠'],
            'linked_card_id' => ['INT',          null,  '카드대금 결제로 연결된 카드'],
            'card_no'        => ['VARCHAR(20)',  '',    '카드번호 끝 4자리'],
            'bill_due'       => ['VARCHAR(10)',  '',    '카드 명세서의 결제예정일'],
            'split_of'       => ['INT',          null,  '나눈 조각이면 원래 거래 id'],
            'note'           => ['VARCHAR(500)', '',    '은행 메모'],
            'detail'         => ['VARCHAR(500)', '',    '출납장 내용'],
            'bank_balance'   => ['BIGINT',       null,  '은행 엑셀의 거래 후 잔액'],
            'created_at'     => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => ['ix_bll_tx_date' => ['tx_date'], 'ix_bll_tx_acc' => ['account_id', 'tx_date'],
                   'ix_bll_tx_split' => ['split_of'], 'ix_bll_tx_card' => ['linked_card_id']],
        'unique' => ['ux_bll_tx_dedup' => ['dedup_key']],
    ],
    'bl_ledger_categories' => [
        'comment' => '장부 계정과목',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'     => ['INT',           false, ''],
            'name'   => ['VARCHAR(60)',   false, ''],
            'grp'    => ['VARCHAR(10)',   false, '수익 / 비용 / 자금이동'],
            'sort'   => ['DECIMAL(10,2)', 0,     '파이썬 버전이 사이에 끼워 넣을 때 0.5 를 썼다'],
            'hidden' => ['INT',           0,     ''],
        ],
        'keys' => [],
        'unique' => ['ux_bll_cat_name' => ['name']],
    ],
    'bl_ledger_rules' => [
        'comment' => '장부 자동 분류 규칙',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'         => ['INT',          false, ''],
            'keyword'    => ['VARCHAR(100)', false, '적요에 들어간 글자'],
            'category'   => ['VARCHAR(60)',  false, ''],
            'direction'  => ['VARCHAR(10)',  '전체', '입금 / 출금 / 전체'],
            'created_at' => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => [],
    ],
    'bl_ledger_partners' => [
        'comment' => '장부 거래처',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'         => ['INT',          false, ''],
            'biz_no'     => ['VARCHAR(20)',  null,  '사업자번호'],
            'name'       => ['VARCHAR(200)', false, ''],
            'ceo'        => ['VARCHAR(100)', '',    ''],
            'address'    => ['VARCHAR(300)', '',    ''],
            'email'      => ['VARCHAR(200)', '',    ''],
            'phone'      => ['VARCHAR(50)',  '',    ''],
            'manager'    => ['VARCHAR(100)', '',    ''],
            'aliases'    => ['VARCHAR(500)', '',    '통장에 찍히는 다른 이름'],
            'memo'       => ['VARCHAR(500)', '',    ''],
            'created_at' => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => [],
        'unique' => ['ux_bll_partner_biz' => ['biz_no']],
    ],
    'bl_ledger_invoices' => [
        'comment' => '장부 세금계산서',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'           => ['INT',          false, ''],
            'kind'         => ['VARCHAR(10)',  false, '매출 / 매입'],
            'doc_type'     => ['VARCHAR(30)',  '',    ''],
            'approval_no'  => ['VARCHAR(40)',  '',    ''],
            'write_date'   => ['VARCHAR(10)',  false, ''],
            'issue_date'   => ['VARCHAR(10)',  '',    ''],
            'partner_id'   => ['INT',          null,  ''],
            'partner_name' => ['VARCHAR(200)', '',    ''],
            'total'        => ['BIGINT',       0,     ''],
            'supply'       => ['BIGINT',       0,     ''],
            'tax'          => ['BIGINT',       0,     ''],
            'item'         => ['VARCHAR(300)', '',    ''],
            'note'         => ['VARCHAR(1000)', '',   ''],
            'receipt'      => ['VARCHAR(10)',  '',    '영수 / 청구'],
            'closed'       => ['VARCHAR(100)', '',    '정산 완료로 닫은 사유'],
            'source_file'  => ['VARCHAR(200)', '',    ''],
            'dedup_key'    => ['VARCHAR(120)', null,  ''],
            'created_at'   => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => ['ix_bll_inv_date' => ['write_date']],
        'unique' => ['ux_bll_inv_dedup' => ['dedup_key']],
    ],
    'bl_ledger_invoice_links' => [
        'comment' => '장부 세금계산서 ↔ 거래 정산 연결',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'         => ['INT',         false, ''],
            'invoice_id' => ['INT',         false, ''],
            'tx_id'      => ['INT',         false, ''],
            'amount'     => ['BIGINT',      false, ''],
            'method'     => ['VARCHAR(40)', '',    ''],
            'created_at' => ['VARCHAR(19)', '',    ''],
        ],
        'keys' => ['ix_bll_links_inv' => ['invoice_id'], 'ix_bll_links_tx' => ['tx_id']],
    ],
    'bl_ledger_month_closes' => [
        'comment' => '장부 월마감(잠긴 달)',
        'pk' => 'ym',
        'cols' => [
            'ym'        => ['VARCHAR(7)',  false, 'YYYY-MM'],
            'closed_at' => ['VARCHAR(19)', '',    ''],
            'summary'   => ['TEXT',        null,  ''],
        ],
        'keys' => [],
    ],
    'bl_ledger_employees' => [
        'comment' => '장부 급여대장 직원',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'         => ['INT',          false, ''],
            'name'       => ['VARCHAR(60)',  false, ''],
            'position'   => ['VARCHAR(60)',  '',    ''],
            'join_date'  => ['VARCHAR(10)',  '',    ''],
            'leave_date' => ['VARCHAR(10)',  '',    ''],
            'memo'       => ['VARCHAR(500)', '',    ''],
            'sort'       => ['INT',          0,     ''],
            'email'      => ['VARCHAR(200)', '',    ''],
            'phone'      => ['VARCHAR(50)',  '',    ''],
        ],
        'keys' => [],
    ],
    'bl_ledger_payroll' => [
        'comment' => '장부 급여(월·직원별 합계)',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'          => ['INT',          false, ''],
            'ym'          => ['VARCHAR(7)',   false, ''],
            'employee_id' => ['INT',          false, ''],
            'gross'       => ['BIGINT',       0,     ''],
            'np'          => ['BIGINT',       0,     '국민연금'],
            'hi'          => ['BIGINT',       0,     '건강보험'],
            'ltc'         => ['BIGINT',       0,     '장기요양'],
            'ei'          => ['BIGINT',       0,     '고용보험'],
            'income_tax'  => ['BIGINT',       0,     ''],
            'local_tax'   => ['BIGINT',       0,     ''],
            'other_ded'   => ['BIGINT',       0,     ''],
            'memo'        => ['VARCHAR(500)', '',    ''],
            'bonus'       => ['BIGINT',       0,     ''],
            'bonus_ded'   => ['BIGINT',       0,     ''],
            'source'      => ['VARCHAR(200)', '',    ''],
            'hi_next'     => ['BIGINT',       0,     ''],
            'taxable'     => ['BIGINT',       0,     ''],
            'nontax'      => ['BIGINT',       0,     ''],
            'round_adj'   => ['BIGINT',       0,     ''],
        ],
        'keys' => [],
        'unique' => ['ux_bll_payroll' => ['ym', 'employee_id']],
    ],
    'bl_ledger_payroll_company' => [
        'comment' => '장부 급여 회사 부담분(월)',
        'pk' => 'ym',
        'cols' => [
            'ym'   => ['VARCHAR(7)',   false, ''],
            'c_np' => ['BIGINT',       0,     ''],
            'c_hi' => ['BIGINT',       0,     ''],
            'c_ei' => ['BIGINT',       0,     ''],
            'c_ia' => ['BIGINT',       0,     ''],
            'memo' => ['VARCHAR(500)', '',    ''],
        ],
        'keys' => [],
    ],
    'bl_ledger_payroll_items' => [
        'comment' => '장부 급여 항목(지급·공제)',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'     => ['INT',           false, ''],
            'kind'   => ['VARCHAR(10)',   false, '지급 / 공제'],
            'name'   => ['VARCHAR(60)',   false, ''],
            'tax'    => ['VARCHAR(10)',   '',    '과세 / 비과세'],
            'map'    => ['VARCHAR(20)',   '',    '합계 칸 이름(np, hi ...)'],
            'sort'   => ['DECIMAL(10,2)', 0,     ''],
            'active' => ['INT',           1,     ''],
        ],
        'keys' => [],
        'unique' => ['ux_bll_pitem' => ['kind', 'name']],
    ],
    'bl_ledger_payroll_values' => [
        'comment' => '장부 급여 항목별 금액',
        'pk' => ['ym', 'employee_id', 'item_id'],
        'cols' => [
            'ym'          => ['VARCHAR(7)', false, ''],
            'employee_id' => ['INT',        false, ''],
            'item_id'     => ['INT',        false, ''],
            'amount'      => ['BIGINT',     0,     ''],
        ],
        'keys' => [],
    ],
    'bl_ledger_settings' => [
        'comment' => '장부 설정',
        'pk' => 'k',
        'cols' => [
            'k' => ['VARCHAR(40)', false, '예: daily_start(출납장 시작일)'],
            'v' => ['TEXT',        null,  ''],
        ],
        'keys' => [],
    ],
    // 출입 명단. 여기 없는 포털 계정은 장부 화면에 들어올 수 없다.
    'bl_ledger_roles' => [
        'comment' => '장부 출입 명단 — 담당자(입력)·대표(보기)',
        'pk' => 'email',
        'cols' => [
            'email'      => ['VARCHAR(190)', false, ''],
            'role'       => ['VARCHAR(10)',  '',    'clerk(담당자) / ceo(대표, 보기 전용)'],
            'updated_by' => ['VARCHAR(190)', '',    ''],
            'updated_at' => ['VARCHAR(19)',  '',    ''],
        ],
        'keys' => [],
    ],
    // 작업 기록. 누가 무엇을 바꿨는지, 지운 거래는 원래 내용까지 남긴다(파이썬 버전의 '지우기 전 백업' 대신).
    'bl_ledger_log' => [
        'comment' => '장부 작업 기록',
        'pk' => 'id', 'auto' => 'id',
        'cols' => [
            'id'         => ['INT',          false, ''],
            'at'         => ['VARCHAR(19)',  false, ''],
            'email'      => ['VARCHAR(190)', '',    ''],
            'action'     => ['VARCHAR(60)',  '',    ''],
            'summary'    => ['VARCHAR(500)', '',    ''],
            'data'       => ['MEDIUMTEXT',   null,  '지운 거래 등 원래 내용(JSON)'],
        ],
        'keys' => ['ix_bll_log_at' => ['at']],
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
        // 번호 자동: SQLite 는 INTEGER 기본키 하나면 저절로 붙는다
        if (!$sqlite && ($def['auto'] ?? '') === $name) $line .= ' AUTO_INCREMENT';
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
        foreach ($def['unique'] ?? [] as $k => $cols) {
            $out[] = "CREATE UNIQUE INDEX IF NOT EXISTS `{$k}` ON `$table` (`" . implode('`,`', $cols) . "`)";
        }
    } else {
        foreach ($def['keys'] as $k => $cols) $lines[] = "KEY `$k` (`" . implode('`,`', $cols) . "`)";
        foreach ($def['unique'] ?? [] as $k => $cols) $lines[] = "UNIQUE KEY `$k` (`" . implode('`,`', $cols) . "`)";
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

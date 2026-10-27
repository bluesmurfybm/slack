<?php
/**
 * BlueLedger 회계 장부 — 화면마다 보여 줄 값 만들기(GET)와 저장 처리(POST).
 *
 *  파이썬 버전의 화면 함수(home, accounts, transactions, daybook, today, cashbook)를 옮겼다.
 *  GET 함수는 HTML 을 만들지 않고 값만 돌려준다 — 같은 값을 숫자 대조 도구(tools/verify_ledger.php)도
 *  그대로 불러 파이썬 결과와 비교한다. HTML 은 lib/ledger_views.php 가 만든다.
 *  POST 함수는 [알림 목록, 돌아갈 주소] 를 돌려준다. 알림은 ['ok'|'error', 글].
 */

require_once __DIR__ . '/ledger.php';

// 홈 화면의 업무 메뉴: [제목, 설명, 색, 아이콘, 화면(p) — null 이면 아직 옮기지 않음]
const LG_MENU = [
    ['거래 가져오기', '은행·카드사 엑셀을 넣어 하나의 장부에 쌓습니다.', 'c-blue', 'upload', null],
    ['일일출납장', '오늘의 통장·카드 거래를 출납장 모양으로 매일 기록합니다.', 'c-sky', 'pencil', 'today'],
    ['분류', '계정과목을 자동 지정하고 미분류 건을 정리합니다.', 'c-indigo', 'tag', null],
    ['증빙·정산', '세금계산서와 입출금을 짝짓고 미수·미지급을 확인합니다.', 'c-orange', 'link', null],
    ['잔액·자금일보', '계좌별 잔액 흐름과 자금일보를 확인합니다.', 'c-teal', 'wallet', 'cashbook'],
    ['월마감', '잔액을 대조하고 마감한 달을 잠급니다.', 'c-green', 'lock', null],
    ['보고서', '월 손익, 경비 요약, 세무사 전달 자료를 만듭니다.', 'c-purple', 'chart', null],
    ['기준정보', '계좌·카드, 계정과목, 거래처를 관리합니다.', 'c-pink', 'sliders', 'accounts'],
    ['수기 입력', '현금 거래나 빠진 건을 직접 입력합니다.', 'c-slate', 'pencil', null],
    ['급여대장', '직원별 급여 지급 내역을 기록하고 조회합니다.', 'c-sky', 'users', null],
];
// 대표 홈 화면에 보이는 메뉴 (파이썬 CEO_MENU: 자금일보·보고서·증빙·급여·월마감)
const LG_CEO_MENU = ['잔액·자금일보', '보고서', '증빙·정산', '급여대장', '월마감'];

/** 장부 화면 주소: ledger.php?p=화면&... (빈 값은 뺀다) */
function lg_url($page, array $params = []) {
    $params = array_filter(['p' => $page] + $params, fn($v) => $v !== '' && $v !== null);
    return 'ledger.php?' . http_build_query($params);
}

/** 돌아갈 주소로 받은 값이 이 장부 화면일 때만 쓴다(다른 사이트로 보내지 않게) */
function lg_safe_back($s) {
    return is_string($s) && preg_match('/^ledger\.php(\?[^\s<>"]*)?$/', $s) ? $s : '';
}

function lg_q(array $q, $k, $default = '') {
    $v = $q[$k] ?? '';
    return is_string($v) && $v !== '' ? $v : $default;
}

function lg_valid_date($s) {
    return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4));
}

function lg_qdate(array $q, $k, $default) {
    $v = lg_q($q, $k);
    return lg_valid_date($v) ? $v : $default;
}

/* ================================================================ 홈 */

function lg_page_home(array $q, array $me) {
    $today = lg_today();
    $uncategorized = (int)lg_val("SELECT COUNT(*) FROM bl_ledger_tx WHERE category=''");
    $open_inv = array_map('lg_inv_status', lg_invoice_rows("closed='' AND total>0"));
    $n_open = count(array_filter($open_inv, fn($s) => in_array($s, ['미수', '미지급', '일부 정산'], true)));
    $n_check = count(array_filter($open_inv, fn($s) => $s === '확인 필요'));
    $month_start = substr($today, 0, 8) . '01';
    $month_cnt = (int)lg_val("SELECT COUNT(*) FROM bl_ledger_tx WHERE tx_date>=?", [$month_start]);
    $accs = lg_balances();
    $day = (int)substr($today, 8, 2);
    $prev_month = lg_add_days($month_start, -1);
    $close_due = $day <= 10 ? substr($today, 0, 8) . '10' : lg_ym_add(substr($today, 0, 7), 1) . '-10';
    $close_label = $day <= 10 ? (int)substr($prev_month, 5, 2) . '월 장부 마감' : (int)substr($today, 5, 2) . '월 장부 마감';
    $vat = lg_next_vat_deadline($today);
    $card_sched = [];
    foreach ($accs as $a) {
        if ($a['kind'] === '카드' && ($a['pay_day'] || $a['pay_keyword']) && !$a['group_of']) {
            [, $bills] = lg_card_bills($a['id']);
            $nxt = null;
            foreach ($bills as $b) { if ($b['status'] === '결제 예정') { $nxt = $b; break; } }
            $due = $nxt ? $nxt['due'] : lg_next_day_of_month($today, $a['pay_day'] ?: 23);
            $card_sched[] = ['name' => $a['name'] . ($a['members'] ? ' 외 ' . count($a['members']) . '장' : ''),
                             'due' => $due, 'dday' => lg_d_day($due), 'unpaid' => $nxt['remain'] ?? 0,
                             'use_ym' => $nxt['use_ym'] ?? ''];
        }
    }
    $td = max($today, lg_daily_start());
    $t = lg_row("SELECT COALESCE(SUM(amount_in),0) AS i, COALESCE(SUM(amount_out),0) AS o, COUNT(*) AS n,
                        SUM(CASE WHEN COALESCE(detail,'')='' THEN 1 ELSE 0 END) AS nod FROM bl_ledger_tx
                 WHERE tx_date=? AND split_of IS NULL AND content NOT LIKE '" . LG_OVERSEAS_FEE . "%'", [$td]);
    $today_info = ['d' => $td, 'in' => (int)$t['i'], 'out' => (int)$t['o'], 'n' => (int)$t['n'], 'nod' => (int)($t['nod'] ?? 0)];
    $close_ym = $day <= 10 ? substr($prev_month, 0, 7) : substr($today, 0, 7);
    $menu = $me['role'] === LG_ROLE_CEO ? array_values(array_filter(LG_MENU, fn($m) => in_array($m[0], LG_CEO_MENU, true))) : LG_MENU;
    return [
        'today_info' => $today_info, 'card_sched' => $card_sched, 'n_open' => $n_open, 'n_check' => $n_check,
        'close_ym' => $close_ym, 'is_closed' => in_array($close_ym, lg_closed_months(), true),
        'menu_view' => $menu, 'today' => $today, 'uncategorized' => $uncategorized, 'month_cnt' => $month_cnt,
        'accounts' => $accs, 'total' => array_sum(array_map(fn($a) => $a['kind'] === '계좌' ? $a['balance'] : 0, $accs)),
        'n_bank' => count(array_filter($accs, fn($a) => $a['kind'] === '계좌')),
        'n_card' => count(array_filter($accs, fn($a) => $a['kind'] === '카드')),
        'close_label' => $close_label, 'close_due' => $close_due, 'close_dday' => lg_d_day($close_due),
        'vat' => $vat, 'vat_dday' => lg_d_day($vat),
    ];
}

/* ================================================================ 기준정보: 계좌·카드 */

function lg_page_accounts(array $q, array $me) {
    return ['accounts' => lg_balances()];
}

function lg_post_accounts(array $p, array $me) {
    $back = lg_url('accounts');
    $do = $p['do'] ?? 'add';
    $aid = (int)($p['aid'] ?? 0);
    if ($do === 'add') {
        $name = trim($p['name'] ?? '');
        if ($name === '') return [[['error', '이름을 입력해 주세요.']], $back];
        $kind = ($p['kind'] ?? '') === '카드' ? '카드' : '계좌';
        lg_exec("INSERT INTO bl_ledger_accounts(name, kind, institution, opening_balance, opening_date, created_at) VALUES (?,?,?,?,?,?)",
            [$name, $kind, trim($p['institution'] ?? ''), lg_to_int($p['opening_balance'] ?? ''),
             lg_valid_date($p['opening_date'] ?? '') ? $p['opening_date'] : '', date('Y-m-d H:i:s')]);
        lg_log($me, '계좌·카드 등록', "$kind $name");
        return [[['ok', "'{$name}'을(를) 등록했어요."]], $back];
    }
    $acc = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$aid]);
    if (!$acc) return [[['error', '없는 계좌·카드예요.']], $back];

    if ($do === 'card') {
        $kw = trim($p['pay_keyword'] ?? '');
        $day = max(0, min(31, lg_to_int($p['pay_day'] ?? '')));
        $start = lg_valid_date($p['pay_start'] ?? '') ? $p['pay_start'] : '';
        $end = lg_valid_date($p['pay_end'] ?? '') ? $p['pay_end'] : '';
        if ($start && $end && $start > $end) return [[['error', '연결 시작일이 종료일보다 늦어요.']], $back];
        lg_exec("UPDATE bl_ledger_tx SET linked_card_id=NULL, category='' WHERE linked_card_id=? AND category=?", [$aid, LG_CARD_PAY]);
        lg_exec("UPDATE bl_ledger_accounts SET pay_keyword=?, pay_day=?, pay_start=?, pay_end=? WHERE id=?", [$kw, $day, $start, $end, $aid]);
        $n = $kw !== '' ? lg_link_card_payments($aid) : 0;
        lg_log($me, '카드대금 연결', "{$acc['name']} 글자 '$kw' 결제일 $day 기간 $start~$end → {$n}건 연결");
        return [[['ok', $kw !== '' ? "저장했어요. 통장 거래 {$n}건을 '" . LG_CARD_PAY . "'로 연결했어요." : '저장했어요.']], $back];
    }
    if ($do === 'group') {
        $lead = (int)($p['lead_id'] ?? 0);
        if (!$lead) {
            lg_exec("UPDATE bl_ledger_accounts SET pay_group=NULL WHERE id=?", [$aid]);
            lg_log($me, '카드 묶음', "{$acc['name']} 묶음에서 뺌");
            return [[['ok', '묶음에서 뺐어요. 이제 따로 계산돼요.']], $back];
        }
        $row = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=? AND kind='카드'", [$lead]);
        if ($lead === $aid || !$row || $row['pay_group']) return [[['error', '대표 카드로 고를 수 없는 카드예요.']], $back];
        if ((int)lg_val("SELECT COUNT(*) FROM bl_ledger_accounts WHERE pay_group=?", [$aid])) {
            return [[['error', '이 카드는 이미 다른 카드들의 대표 카드예요.']], $back];
        }
        // 이 카드에 연결돼 있던 결제는 풀고, 대표 카드 기준으로 다시 연결
        lg_exec("UPDATE bl_ledger_tx SET linked_card_id=NULL, category='' WHERE linked_card_id=? AND category=?", [$aid, LG_CARD_PAY]);
        lg_exec("UPDATE bl_ledger_accounts SET pay_group=? WHERE id=?", [$lead, $aid]);
        lg_link_card_payments();
        lg_log($me, '카드 묶음', "{$acc['name']} → 대표 카드 {$row['name']}");
        return [[['ok', "'{$row['name']}'와(과) 함께 결제되는 카드로 묶었어요."]], $back];
    }
    if ($do === 'opening') {
        $name = trim($p['name'] ?? '');
        lg_exec("UPDATE bl_ledger_accounts SET opening_balance=?, opening_date=? WHERE id=?",
            [lg_to_int($p['opening_balance'] ?? ''), lg_valid_date($p['opening_date'] ?? '') ? $p['opening_date'] : '', $aid]);
        if ($name !== '') lg_exec("UPDATE bl_ledger_accounts SET name=? WHERE id=?", [$name, $aid]);
        lg_log($me, '기초잔액 고침', "{$acc['name']} → " . ($name ?: $acc['name']) . ' 기초잔액 ' . lg_to_int($p['opening_balance'] ?? '') . ' 기초일자 ' . ($p['opening_date'] ?? ''));
        return [[['ok', '고쳤어요.']], $back];
    }
    if ($do === 'delete') {
        $cnt = (int)lg_val("SELECT COUNT(*) FROM bl_ledger_tx WHERE account_id=?", [$aid]);
        if ($cnt) return [[['error', "거래가 {$cnt}건 들어 있어서 삭제할 수 없어요."]], $back];
        lg_exec("DELETE FROM bl_ledger_accounts WHERE id=?", [$aid]);
        lg_log($me, '계좌·카드 삭제', $acc['name'], $acc);
        return [[['ok', '삭제했어요.']], $back];
    }
    return [[['error', '알 수 없는 요청이에요.']], $back];
}

/* ================================================================ 거래 목록 */

function lg_page_transactions(array $q, array $me) {
    $today = lg_today();
    $start = lg_qdate($q, 'start', substr($today, 0, 8) . '01');
    $end = lg_qdate($q, 'end', $today);
    $aid = preg_match('/^\d+$/', lg_q($q, 'account_id')) ? lg_q($q, 'account_id') : '';
    $card_no = lg_q($q, 'card_no');
    $sql = "SELECT t.*, a.name AS acc_name FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
            WHERE t.tx_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($aid !== '') { $sql .= " AND t.account_id=?"; $params[] = (int)$aid; }
    if ($card_no !== '') { $sql .= " AND t.card_no=?"; $params[] = $card_no; }
    $rows = lg_all($sql . " ORDER BY t.tx_date DESC, t.id DESC", $params);
    $card_nos = array_column(lg_all("SELECT DISTINCT card_no FROM bl_ledger_tx WHERE card_no<>'' ORDER BY card_no"), 'card_no');
    $by_card = $card_nos ? lg_all("SELECT card_no, COUNT(*) AS n, SUM(amount_out)-SUM(amount_in) AS used
                                   FROM ($sql) x GROUP BY card_no ORDER BY card_no", $params) : [];
    return [
        'rows' => $rows, 'accounts' => lg_all("SELECT * FROM bl_ledger_accounts ORDER BY kind, id"),
        'start' => $start, 'end' => $end, 'aid' => $aid, 'card_no' => $card_no, 'card_nos' => $card_nos, 'by_card' => $by_card,
        'sum_in' => array_sum(array_map(fn($r) => (int)$r['amount_in'], $rows)),
        'sum_out' => array_sum(array_map(fn($r) => (int)$r['amount_out'], $rows)),
    ];
}

function lg_post_transactions(array $p, array $me) {
    $do = $p['do'] ?? '';
    if ($do === 'delete_selected') {
        $back = lg_safe_back($p['back'] ?? '') ?: lg_url('transactions');
        $ids = array_map('intval', (array)($p['del_id'] ?? []));
        if (!$ids) return [[['error', '지울 거래를 골라주세요.']], $back];
        [$n, $skipped] = lg_delete_txs($ids, '선택 삭제', $me);
        if (!$n) return [[['error', '고른 거래가 모두 마감한 달이라 지우지 않았어요. 월마감에서 마감을 풀고 해주세요.']], $back];
        return [[['ok', "고른 거래 {$n}건을 지웠어요. 지운 거래의 원래 내용은 작업 기록에 남겨 뒀어요."
                        . ($skipped ? " 마감한 달 {$skipped}건은 건너뛰었어요." : '')]], $back];
    }
    if ($do === 'delete_period') {
        $aid = (int)($p['account_id'] ?? 0);
        $start = lg_valid_date($p['start'] ?? '') ? $p['start'] : '';
        $end = lg_valid_date($p['end'] ?? '') ? $p['end'] : '';
        $back = lg_url('transactions', ['start' => $start, 'end' => $end, 'account_id' => $aid ?: '']);
        if (!($aid && $start && $end)) return [[['error', '계좌·카드와 기간을 골라야 지울 수 있어요.']], $back];
        $lk = array_values(array_filter(lg_closed_months(), fn($m) => substr($start, 0, 7) <= $m && $m <= substr($end, 0, 7)));
        sort($lk);
        if ($lk) return [[['error', '마감한 달(' . implode(', ', $lk) . ')이 기간에 들어 있어서 지울 수 없어요.']], $back];
        $ids = array_column(lg_all("SELECT id FROM bl_ledger_tx WHERE account_id=? AND tx_date BETWEEN ? AND ? AND split_of IS NULL", [$aid, $start, $end]), 'id');
        [$n] = $ids ? lg_delete_txs($ids, '기간 삭제', $me) : [0];
        return [[['ok', "$start ~ $end 거래 {$n}건을 지웠어요. 지운 거래의 원래 내용은 작업 기록에 남겨 뒀어요."]], $back];
    }
    return [[['error', '알 수 없는 요청이에요.']], lg_url('transactions')];
}

/* ================================================================ 일일출납장 (여러 날) */

function lg_page_daybook(array $q, array $me) {
    $today = lg_today();
    $start = lg_qdate($q, 'start', lg_add_days($today, -6));
    $end = lg_qdate($q, 'end', $today);
    $only_empty = lg_q($q, 'empty') === '1';
    $sql = "SELECT t.*, a.name AS acc_name, a.kind AS acc_kind,
                   (SELECT COUNT(*) FROM bl_ledger_tx p WHERE p.split_of=t.id) AS parts
            FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
            WHERE t.tx_date BETWEEN ? AND ? AND t.split_of IS NULL
              AND t.content NOT LIKE '" . LG_OVERSEAS_FEE . "%' AND t.source_file<>?";
    if ($only_empty) $sql .= " AND COALESCE(t.detail,'')=''";
    $rows = lg_all($sql . " ORDER BY a.kind DESC, t.tx_date, t.id", [$start, $end, LG_ADJUST]);
    [$banks, $leads] = lg_daybook_groups();
    return ['rows' => $rows, 'start' => $start, 'end' => $end, 'only_empty' => $only_empty,
            'options' => lg_category_options(), 'banks' => $banks, 'leads' => $leads, 'today' => $today];
}

function lg_post_daybook(array $p, array $me, $back) {
    $ids = [];
    $changed = 0;
    $locked = array_flip(lg_closed_months());
    foreach ((array)($p['tx_id'] ?? []) as $tid) {
        $tid = (int)$tid;
        $detail = trim($p["detail_$tid"] ?? '');
        $cat = $p["cat_$tid"] ?? null;
        $old = lg_row("SELECT detail, category, tx_date FROM bl_ledger_tx WHERE id=?", [$tid]);
        if (!$old || isset($locked[substr($old['tx_date'], 0, 7)])) continue;
        if ($detail !== ($old['detail'] ?? '')) { lg_exec("UPDATE bl_ledger_tx SET detail=? WHERE id=?", [$detail, $tid]); $changed++; }
        if ($cat !== null && $cat !== $old['category']) {
            if ($old['category'] === LG_CARD_PAY) lg_exec("UPDATE bl_ledger_tx SET linked_card_id=NULL WHERE id=?", [$tid]);
            lg_exec("UPDATE bl_ledger_tx SET category=? WHERE id=?", [$cat, $tid]);
            $changed++;
        }
        $ids[] = $tid;
    }
    $auto = lg_apply_detail_words($ids);
    lg_log($me, '출납장 저장', "거래 " . count($ids) . "건 중 {$changed}칸 바뀜, 자동 분류 " . count($auto) . "건");
    return [[['ok', '저장했어요.' . ($auto ? ' 적은 내용을 보고 ' . count($auto) . '건에 계정과목을 붙였어요.' : '')]], $back];
}

/* ================================================================ 일일출납장 (하루) */

function lg_today_ctx(array $q) {
    $banks = lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='계좌' ORDER BY id");
    $leads = lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='카드' AND pay_group IS NULL ORDER BY id");
    $d = lg_qdate($q, 'd', lg_today());
    $bank_id = (int)(lg_q($q, 'bank_id') ?: ($banks[0]['id'] ?? 0));
    $lead_id = (int)(lg_q($q, 'lead_id') ?: ($leads[0]['id'] ?? 0));
    $start = lg_daily_start();
    $editable = $d >= $start && !in_array(substr($d, 0, 7), lg_closed_months(), true);
    return [$d, $banks, $leads, $bank_id, $lead_id, $start, $editable];
}

function lg_page_today(array $q, array $me) {
    [$d, $banks, $leads, $bank_id, $lead_id, $start, $editable] = lg_today_ctx($q);
    $group = $lead_id ? lg_card_group_ids($lead_id) : [];
    // 통장 (전일 이월 → 거래 → 잔액)
    $acc = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$bank_id]);
    $prev = (int)($acc['opening_balance'] ?? 0) + (int)(lg_val(
        "SELECT SUM(amount_in)-SUM(amount_out) FROM bl_ledger_tx WHERE account_id=? AND tx_date<?", [$bank_id, $d]) ?? 0);
    $rows = lg_all("SELECT t.*, (SELECT COALESCE(SUM(p.amount_in),0) FROM bl_ledger_tx p WHERE p.split_of=t.id) AS p_in,
                           (SELECT COALESCE(SUM(p.amount_out),0) FROM bl_ledger_tx p WHERE p.split_of=t.id) AS p_out,
                           (SELECT COUNT(*) FROM bl_ledger_tx p WHERE p.split_of=t.id) AS parts
                    FROM bl_ledger_tx t WHERE t.account_id=? AND t.tx_date=? AND t.split_of IS NULL ORDER BY t.id", [$bank_id, $d]);
    $bal = $prev;
    $bank_rows = [];
    foreach ($rows as $t) {
        $a_in = (int)$t['amount_in'] + (int)$t['p_in'];
        $a_out = (int)$t['amount_out'] + (int)$t['p_out'];
        $bal += $a_in - $a_out;
        $bank_rows[] = $t + ['a_in' => $a_in, 'a_out' => $a_out, 'bal' => $bal];
    }
    $card_rows = [];
    $card_accs = [];
    if ($group) {
        $ph = lg_ph($group);
        $card_accs = lg_all("SELECT * FROM bl_ledger_accounts WHERE id IN ($ph) ORDER BY id", $group);
        $rows_c = lg_all("SELECT t.*, a.name AS acc_name FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
                WHERE t.account_id IN ($ph) AND t.tx_date=? AND t.split_of IS NULL AND t.category<>?
                  AND t.content NOT LIKE '" . LG_OVERSEAS_FEE . "%' AND t.source_file<>? ORDER BY t.card_no, t.id",
            array_merge($group, [$d, LG_CANCELLED, LG_ADJUST]));
        // 해외 결제 수수료는 같은 줄 금액에 더해서 보여줌 (출납장 엑셀·자금일보와 같은 금액이 되도록)
        $fees = lg_overseas_fees($group);
        foreach ($rows_c as $t) $card_rows[] = $t + ['fee' => $fees[$t['dedup_key'] ?? ''] ?? 0];
    }
    return [
        'd' => $d, 'start' => $start, 'editable' => $editable, 'banks' => $banks, 'leads' => $leads,
        'bank_id' => $bank_id, 'lead_id' => $lead_id, 'prev' => $prev, 'bank_rows' => $bank_rows, 'close' => $bal,
        'card_rows' => $card_rows, 'card_accs' => $card_accs,
        'sum_in' => array_sum(array_column($bank_rows, 'a_in')), 'sum_out' => array_sum(array_column($bank_rows, 'a_out')),
        'card_sum' => array_sum(array_map(fn($r) => (int)$r['amount_out'] - (int)$r['amount_in'] + $r['fee'], $card_rows)),
        'options' => lg_category_options(), 'prev_day' => lg_add_days($d, -1), 'next_day' => lg_add_days($d, 1),
    ];
}

function lg_post_today(array $p, array $me) {
    [$d, , , $bank_id, $lead_id, $start, $editable] = lg_today_ctx($p);
    $back = lg_url('today', ['d' => $d, 'bank_id' => $bank_id, 'lead_id' => $lead_id]);
    if (!$editable) return [[['error', "{$d}은(는) 기록할 수 없는 날이에요. ({$start}부터 기록하고, 마감한 달은 잠겨 있어요)"]], $back];
    $act = $p['do'] ?? '';
    if ($act === 'add_bank' || $act === 'add_card') {
        $amt_in = $act === 'add_bank' ? lg_to_int($p['amount_in'] ?? '') : 0;
        $amt_out = lg_to_int($p['amount_out'] ?? '');
        $acc = $act === 'add_bank' ? $bank_id : ((int)($p['card_acc'] ?? 0) ?: $lead_id);
        if ($amt_in <= 0 && $amt_out <= 0) return [[['error', '금액을 넣어주세요.']], $back];
        if (!lg_row("SELECT id FROM bl_ledger_accounts WHERE id=?", [$acc])) return [[['error', '없는 계좌·카드예요.']], $back];
        $cat = (string)($p['category'] ?? '');
        lg_exec("INSERT INTO bl_ledger_tx(account_id, tx_date, content, amount_in, amount_out, category, detail,
                                          source_file, dedup_key, card_no, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$acc, $d, trim($p['content'] ?? '') ?: LG_MANUAL, $amt_in, $amt_out, $cat, trim($p['detail'] ?? ''), LG_MANUAL,
             'MAN|' . bin2hex(random_bytes(16)), lg_last4($p['card_no'] ?? ''), date('Y-m-d H:i:s')]);
        $new_id = (int)lg_db()->lastInsertId();
        if ($cat === '') { lg_apply_detail_words([$new_id]); lg_apply_rules([$new_id]); }
        lg_log($me, '출납장 한 줄 넣기', "$d 계좌·카드 $acc 입금 $amt_in 출금 $amt_out " . trim($p['content'] ?? ''));
        return [[['ok', '한 줄 넣었어요. 나중에 통장·카드 엑셀을 가져오면 같은 날·같은 금액 거래와 자동으로 합쳐져요.']], $back];
    }
    if ($act === 'delete') {
        $t = lg_row("SELECT * FROM bl_ledger_tx WHERE id=? AND source_file=? AND tx_date=?", [(int)($p['id'] ?? 0), LG_MANUAL, $d]);
        if ($t && !lg_val("SELECT 1 FROM bl_ledger_invoice_links WHERE tx_id=?", [$t['id']])) {
            lg_exec("DELETE FROM bl_ledger_tx WHERE id=?", [$t['id']]);
            lg_log($me, '출납장 한 줄 지우기', "$d {$t['content']}", $t);
            return [[['ok', '지웠어요.']], $back];
        }
        return [[], $back];
    }
    $ids = [];
    foreach ((array)($p['tx_id'] ?? []) as $tid) {
        $tid = (int)$tid;
        $old = lg_row("SELECT * FROM bl_ledger_tx WHERE id=? AND tx_date=?", [$tid, $d]);
        if (!$old) continue;
        $detail = trim($p["detail_$tid"] ?? '');
        $cat = $p["cat_$tid"] ?? null;
        if ($detail !== ($old['detail'] ?? '')) lg_exec("UPDATE bl_ledger_tx SET detail=? WHERE id=?", [$detail, $tid]);
        if ($cat !== null && $cat !== $old['category']) lg_exec("UPDATE bl_ledger_tx SET category=? WHERE id=?", [$cat, $tid]);
        $ids[] = $tid;
    }
    $auto = lg_apply_detail_words($ids);
    lg_log($me, '일일출납장 저장', "$d 거래 " . count($ids) . "건, 자동 분류 " . count($auto) . "건");
    return [[['ok', '저장했어요.' . ($auto ? ' 적은 내용으로 ' . count($auto) . '건에 계정과목을 붙였어요.' : '')]], $back];
}

/* ================================================================ 잔액·자금일보 */

function lg_page_cashbook(array $q, array $me) {
    $last = lg_val("SELECT MAX(tx_date) FROM bl_ledger_tx WHERE account_id IN (SELECT id FROM bl_ledger_accounts WHERE kind='계좌')");
    $day = lg_qdate($q, 'd', $last ?: lg_today());
    [$banks, $cards, $txs] = lg_cash_report($day);
    $prev_day = lg_val("SELECT MAX(tx_date) FROM bl_ledger_tx WHERE tx_date<?", [$day]);
    $next_day = lg_val("SELECT MIN(tx_date) FROM bl_ledger_tx WHERE tx_date>?", [$day]);
    $total = [];
    foreach (['prev', 'in', 'out', 'close'] as $k) $total[$k] = array_sum(array_column($banks, $k));
    return ['day' => $day, 'banks' => $banks, 'cards' => $cards, 'txs' => $txs, 'prev_day' => $prev_day,
            'next_day' => $next_day, 'last' => $last, 'no_detail' => count(array_filter($txs, fn($t) => !$t['detail'])),
            'total' => $total];
}

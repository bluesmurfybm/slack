<?php
/**
 * BlueLedger 회계 장부 — ④ 분류·계정과목·거래 나누기·수기 입력.
 *
 *  파이썬 버전의 classify·classify_groups·categories·split_tx·manual 을 옮겼다.
 *  POST 함수는 [알림 목록, 돌아갈 주소], 화면 함수는 값을 돌려준다. 화면 함수가 다른 화면으로 보내야 하면
 *  ['__redirect' => 주소, '__flash' => 알림] 을 돌려준다(ledger.php 가 처리).
 */

require_once __DIR__ . '/ledger_import.php';

// 거래 나누기 줄 구성 — 이름: [적요에 이 글자가 있으면 자동 선택, [[설명, 계정과목], ...]]
const LG_SPLIT_PRESETS = [
    '국민연금' => ['국민연금', [['직원 부담분', '원천세·4대보험 납부'], ['회사 부담분', '세금과공과']]],
    '건강보험' => ['건강', [['직원 부담분', '원천세·4대보험 납부'], ['회사 부담분', '복리후생비']]],
    '고용보험' => ['고용보험', [['직원 부담분', '원천세·4대보험 납부'], ['회사 부담분', '보험료']]],
];

function lg_uncategorized() {
    return (int)lg_val("SELECT COUNT(*) FROM bl_ledger_tx WHERE category=''");
}

/** 규칙 하나를 새로 (같은 글자·방향 규칙은 바꿔 끼운다) */
function lg_put_rule($kw, $cat, $direction) {
    lg_exec("DELETE FROM bl_ledger_rules WHERE keyword=? AND direction=?", [$kw, $direction]);
    lg_exec("INSERT INTO bl_ledger_rules(keyword, category, direction, created_at) VALUES (?,?,?,?)", [$kw, $cat, $direction, date('Y-m-d H:i:s')]);
}

/* ================================================================ 분류 (한 건씩) */

function lg_page_classify(array $q, array $me) {
    $start = lg_qdate($q, 'start', '2000-01-01');
    $end = lg_qdate($q, 'end', lg_today());
    $aid = preg_match('/^\d+$/', lg_q($q, 'account_id')) ? lg_q($q, 'account_id') : '';
    $show = lg_q($q, 'show', '미분류') === '전체' ? '전체' : '미분류';
    $kw = lg_strip($q['q'] ?? '');
    $sql = "SELECT t.*, a.name AS acc_name, a.kind AS acc_kind FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
            WHERE t.tx_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($kw !== '') { $sql .= " AND REPLACE(t.content,' ','') LIKE ?"; $params[] = '%' . str_replace(' ', '', $kw) . '%'; }
    if ($aid !== '') { $sql .= " AND t.account_id=?"; $params[] = (int)$aid; }
    if ($show === '미분류') $sql .= " AND t.category=''";
    $options = lg_category_options();
    $names = [];
    foreach ($options as [, $ns]) foreach ($ns as $n) $names[$n] = true;
    return [
        'rows' => lg_all($sql . " ORDER BY t.tx_date DESC, t.id DESC LIMIT 200", $params),
        'total' => (int)lg_val("SELECT COUNT(*) FROM ($sql) x", $params),
        'accounts' => lg_all("SELECT * FROM bl_ledger_accounts ORDER BY kind, id"),
        'start' => $start, 'end' => $end, 'aid' => $aid, 'show' => $show, 'kw' => $kw,
        'options' => $options, 'uncategorized' => lg_uncategorized(), 'all_names' => $names,
    ];
}

function lg_post_classify(array $p, array $me, $self) {
    if (($p['do'] ?? '') === 'rerun') {
        $n = lg_apply_rules();
        lg_log($me, '규칙 다시 적용', "{$n}건 분류");
        return [[['ok', "규칙으로 {$n}건을 분류했어요."]], $self];
    }
    $changed = 0;
    $new_rules = 0;
    $locked = array_flip(lg_closed_months());
    foreach ((array)($p['tx_id'] ?? []) as $tid) {
        $tid = (int)$tid;
        $cat = (string)($p["cat_$tid"] ?? '');
        $old = (string)($p["old_$tid"] ?? '');
        if ($cat === $old) continue;
        $td = lg_val("SELECT tx_date FROM bl_ledger_tx WHERE id=?", [$tid]);
        if ($td && isset($locked[substr($td, 0, 7)])) continue;
        if ($old === LG_CARD_PAY) lg_exec("UPDATE bl_ledger_tx SET linked_card_id=NULL WHERE id=?", [$tid]);   // 카드대금 결제에서 바꾸면 카드 연결도 풂
        lg_exec("UPDATE bl_ledger_tx SET category=? WHERE id=?", [$cat, $tid]);
        $changed++;
        $kw = lg_strip($p["kw_$tid"] ?? '');
        if ($cat !== '' && !empty($p["rule_$tid"]) && $kw !== '') {
            $in = (int)lg_val("SELECT amount_in FROM bl_ledger_tx WHERE id=?", [$tid]);
            lg_put_rule($kw, $cat, $in > 0 ? '입금' : '출금');
            $new_rules++;
        }
    }
    $auto = $new_rules ? lg_apply_rules() : 0;
    lg_log($me, '분류', "{$changed}건 저장, 새 규칙 {$new_rules}개, 자동 {$auto}건");
    $msg = "{$changed}건을 저장했어요.";
    if ($new_rules) $msg .= " 새 규칙 {$new_rules}개를 만들었고, 같은 적요 {$auto}건이 자동으로 분류됐어요.";
    return [[['ok', $msg]], $self];
}

/* ================================================================ 분류 (적요별 묶음) */

function lg_page_classify_groups(array $q, array $me) {
    $aid = preg_match('/^\d+$/', lg_q($q, 'account_id')) ? lg_q($q, 'account_id') : '';
    $where = "t.category=''";
    $params = [];
    if ($aid !== '') { $where .= " AND t.account_id=?"; $params[] = (int)$aid; }
    $groups = lg_all("SELECT t.content, CASE WHEN t.amount_in>0 THEN '입금' ELSE '출금' END AS dir,
               COUNT(*) AS n, SUM(t.amount_in) AS sin, SUM(t.amount_out) AS sout,
               MIN(t.tx_date) AS first, MAX(t.tx_date) AS last,
               GROUP_CONCAT(DISTINCT a.name) AS accs,
               GROUP_CONCAT(DISTINCT NULLIF(t.note,'')) AS notes,
               GROUP_CONCAT(DISTINCT NULLIF(t.detail,'')) AS details
        FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
        WHERE $where
        GROUP BY t.content, CASE WHEN t.amount_in>0 THEN '입금' ELSE '출금' END
        ORDER BY n DESC, (SUM(t.amount_in)+SUM(t.amount_out)) DESC
        LIMIT 150", $params);
    $total = (int)lg_val("SELECT COUNT(*) FROM (SELECT 1 FROM bl_ledger_tx t WHERE $where
        GROUP BY t.content, CASE WHEN t.amount_in>0 THEN 1 ELSE 0 END) x", $params);
    return ['groups' => $groups, 'total_groups' => $total, 'aid' => $aid, 'accounts' => lg_all("SELECT * FROM bl_ledger_accounts ORDER BY kind, id"),
            'uncategorized' => lg_uncategorized(), 'options' => lg_category_options()];
}

function lg_post_classify_groups(array $p, array $me, $self) {
    $done_tx = 0;
    $done_groups = 0;
    $new_rules = 0;
    foreach ((array)($p['gid'] ?? []) as $gid) {
        $gid = (int)$gid;
        $cat = (string)($p["cat_$gid"] ?? '');
        if ($cat === '') continue;
        $content = (string)($p["content_$gid"] ?? '');
        $direction = ($p["dir_$gid"] ?? '출금') === '입금' ? '입금' : '출금';
        $cond = $direction === '입금' ? 'amount_in>0' : 'amount_out>0';
        $done_tx += lg_exec("UPDATE bl_ledger_tx SET category=? WHERE category='' AND content=? AND $cond AND " . LG_LOCK_SQL, [$cat, $content]);
        $done_groups++;
        $kw = lg_strip($p["kw_$gid"] ?? '');
        if (!empty($p["rule_$gid"]) && $kw !== '') { lg_put_rule($kw, $cat, $direction); $new_rules++; }
    }
    $auto = $new_rules ? lg_apply_rules() : 0;
    lg_log($me, '묶음 분류', "{$done_groups}묶음 {$done_tx}건, 새 규칙 {$new_rules}개, 자동 {$auto}건");
    $msg = "{$done_groups}묶음 {$done_tx}건을 분류했어요.";
    if ($new_rules) $msg .= " 규칙 {$new_rules}개를 만들어 {$auto}건이 더 자동 분류됐어요.";
    return [[['ok', $msg]], $self];
}

/* ================================================================ 계정과목·규칙 */

function lg_page_categories(array $q, array $me) {
    return [
        'groups' => LG_GROUPS,
        'cats' => lg_all("SELECT c.*, (SELECT COUNT(*) FROM bl_ledger_tx t WHERE t.category=c.name) AS used FROM bl_ledger_categories c ORDER BY sort, id"),
        'rules' => lg_all("SELECT * FROM bl_ledger_rules ORDER BY category, keyword"),
        'options' => lg_category_options(),
    ];
}

function lg_post_categories(array $p, array $me) {
    $back = lg_url('categories');
    $act = $p['do'] ?? '';
    if ($act === 'add_cat') {
        $name = lg_strip($p['name'] ?? '');
        $grp = (string)($p['grp'] ?? '비용');
        if ($name === '' || !in_array($grp, LG_GROUPS, true)) return [[], $back];
        if (lg_val("SELECT 1 FROM bl_ledger_categories WHERE name=?", [$name])) {
            lg_exec("UPDATE bl_ledger_categories SET hidden=0, grp=? WHERE name=?", [$grp, $name]);
            lg_log($me, '계정과목', "$name 다시 보이기");
            return [[['ok', "'{$name}'은(는) 이미 있어서 다시 보이게 했어요."]], $back];
        }
        $mx = (float)lg_val("SELECT COALESCE(MAX(sort),0) FROM bl_ledger_categories");
        lg_exec("INSERT INTO bl_ledger_categories(name, grp, sort) VALUES (?,?,?)", [$name, $grp, $mx + 1]);
        lg_log($me, '계정과목', "$name($grp) 추가");
        return [[['ok', "'{$name}' 계정과목을 추가했어요."]], $back];
    }
    if ($act === 'toggle_cat') {
        lg_exec("UPDATE bl_ledger_categories SET hidden=1-hidden WHERE id=?", [(int)($p['id'] ?? 0)]);
        lg_log($me, '계정과목', '숨기기/보이기 ' . (int)($p['id'] ?? 0));
        return [[], $back];
    }
    if ($act === 'add_rule') {
        $kw = lg_strip($p['keyword'] ?? '');
        $cat = (string)($p['category'] ?? '');
        $dir = in_array($p['direction'] ?? '', ['전체', '출금', '입금'], true) ? $p['direction'] : '전체';
        if ($kw === '' || $cat === '') return [[], $back];
        lg_exec("INSERT INTO bl_ledger_rules(keyword, category, direction, created_at) VALUES (?,?,?,?)", [$kw, $cat, $dir, date('Y-m-d H:i:s')]);
        $n = lg_apply_rules();
        lg_log($me, '규칙 추가', "$kw → $cat ($dir), {$n}건 분류");
        return [[['ok', "규칙을 추가했어요. 미분류 {$n}건이 분류됐어요."]], $back];
    }
    if ($act === 'del_rule') {
        $r = lg_row("SELECT * FROM bl_ledger_rules WHERE id=?", [(int)($p['id'] ?? 0)]);
        lg_exec("DELETE FROM bl_ledger_rules WHERE id=?", [(int)($p['id'] ?? 0)]);
        if ($r) lg_log($me, '규칙 지움', "{$r['keyword']} → {$r['category']}", $r);
        return [[['ok', '규칙을 지웠어요. (이미 분류된 거래는 그대로예요)']], $back];
    }
    return [[], $back];
}

/* ================================================================ 거래 나누기 */

/** 적요 끝의 연월 숫자를 뗀 이름 (국민연금2605 → 국민연금) */
function lg_content_stem($c) {
    return preg_replace('/[\d\s]+$/u', '', (string)$c);
}

/** [조각들, 입금인지, 나누기 전 원래 금액] */
function lg_split_total(array $t) {
    $parts = lg_all("SELECT * FROM bl_ledger_tx WHERE split_of=? ORDER BY id", [$t['id']]);
    $is_in = (int)$t['amount_in'] > 0;
    foreach ($parts as $x) if ((int)$x['amount_in'] > 0) $is_in = true;
    $col = $is_in ? 'amount_in' : 'amount_out';
    $total = (int)$t[$col];
    foreach ($parts as $x) $total += (int)$x[$col];
    return [$parts, $is_in, $total];
}

function lg_split_merge_back(array $t, $total, $is_in) {
    lg_exec("DELETE FROM bl_ledger_tx WHERE split_of=?", [$t['id']]);
    lg_exec("UPDATE bl_ledger_tx SET " . ($is_in ? 'amount_in' : 'amount_out') . "=?, memo='', category='' WHERE id=?", [$total, $t['id']]);
}

function lg_split_tx_row($tid) {
    return lg_row("SELECT t.*, a.name AS acc_name FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id WHERE t.id=?", [$tid]);
}

function lg_page_split(array $q, array $me) {
    $tid = (int)($q['tid'] ?? 0);
    $t = lg_split_tx_row($tid);
    if ($t && $t['split_of']) return ['__redirect' => lg_url('split', ['tid' => $t['split_of']])];     // 나눠진 조각을 열면 원래 거래로
    if (!$t) return ['__redirect' => lg_url('classify'), '__flash' => [['error', '거래를 찾을 수 없어요.']]];
    if ($t['linked_card_id']) return ['__redirect' => lg_url('classify'), '__flash' => [['error', '카드대금 결제로 연결된 거래는 나눌 수 없어요.']]];
    [$parts, $is_in, $total] = lg_split_total($t);
    $current = $parts ? array_merge([$t], $parts) : [];
    // 같은 적요의 지난 나누기를 찾아 줄 구성(설명·계정과목)을 불러오기
    $stem = lg_content_stem($t['content']);
    $last_rows = [];
    if ($stem !== '') {
        $last = lg_val("SELECT t.id FROM bl_ledger_tx t WHERE t.content LIKE ? AND t.id<>? AND t.split_of IS NULL
                AND EXISTS (SELECT 1 FROM bl_ledger_tx p WHERE p.split_of=t.id) ORDER BY t.tx_date DESC LIMIT 1", [$stem . '%', $tid]);
        if ($last) {
            $lt = lg_row("SELECT * FROM bl_ledger_tx WHERE id=?", [$last]);
            $last_rows[] = [$lt['memo'], $lt['category']];
            foreach (lg_all("SELECT * FROM bl_ledger_tx WHERE split_of=? ORDER BY id", [$last]) as $x) $last_rows[] = [$x['memo'], $x['category']];
        }
    }
    $suggested = '';
    foreach (LG_SPLIT_PRESETS as $n => [$kw]) { if (str_contains($t['content'], $kw)) { $suggested = $n; break; } }
    $presets = [];
    foreach (LG_SPLIT_PRESETS as $n => [, $rows]) $presets[$n] = $rows;
    return ['t' => $t, 'total' => $total, 'is_in' => $is_in, 'current' => $current, 'presets' => $presets,
            'suggested' => $suggested, 'last_rows' => $last_rows, 'options' => lg_category_options()];
}

function lg_post_split(array $p, array $me) {
    $tid = (int)($_GET['tid'] ?? 0);
    $back = lg_url('split', ['tid' => $tid]);
    $t = lg_split_tx_row($tid);
    if (!$t || $t['split_of'] || $t['linked_card_id']) return [[['error', '나눌 수 없는 거래예요.']], lg_url('classify')];
    if (in_array(substr($t['tx_date'], 0, 7), lg_closed_months(), true)) {
        return [[['error', (int)substr($t['tx_date'], 5, 2) . '월은 마감한 달이라 바꿀 수 없어요. 월마감에서 마감을 풀고 해주세요.']], $back];
    }
    [$parts, $is_in, $total] = lg_split_total($t);
    if (($p['do'] ?? '') === 'undo') {
        lg_split_merge_back($t, $total, $is_in);
        lg_log($me, '나누기 취소', "{$t['tx_date']} {$t['content']} " . lg_won($total) . '원');
        return [[['ok', '나누기를 취소하고 원래 한 건으로 되돌렸어요. 계정과목은 다시 골라주세요.']], $back];
    }
    $items = [];
    $memos = (array)($p['memo'] ?? []);
    $amts = (array)($p['amount'] ?? []);
    $cats = (array)($p['category'] ?? []);
    for ($i = 0; $i < min(count($memos), count($amts), count($cats)); $i++) {
        $a = lg_to_int($amts[$i]);
        if ($a > 0) $items[] = [lg_strip($memos[$i]), $a, (string)$cats[$i]];
    }
    $sum = array_sum(array_column($items, 1));
    if (count($items) < 2) return [[['error', '두 줄 이상 금액을 넣어야 나눌 수 있어요.']], $back];
    if ($sum !== $total) return [[['error', '나눈 금액의 합계(' . lg_won($sum) . '원)가 원래 금액(' . lg_won($total) . '원)과 달라요.']], $back];
    $pdo = lg_db();
    $pdo->beginTransaction();
    try {
        lg_split_merge_back($t, $total, $is_in);
        $col = $is_in ? 'amount_in' : 'amount_out';
        [$memo0, $a0, $c0] = $items[0];
        lg_exec("UPDATE bl_ledger_tx SET $col=?, memo=?, category=? WHERE id=?", [$a0, $memo0, $c0, $tid]);
        $base = $t['dedup_key'] ?: "SPLIT|$tid";
        foreach (array_slice($items, 1) as $k => [$memo, $a, $cat]) {
            lg_exec("INSERT INTO bl_ledger_tx(account_id, tx_date, content, $col, category, memo, source_file,
                     dedup_key, card_no, bill_due, split_of, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$t['account_id'], $t['tx_date'], $t['content'], $a, $cat, $memo, '거래 나누기',
                 "$base|P" . ($k + 2) . '|' . substr(bin2hex(random_bytes(4)), 0, 8), $t['card_no'], $t['bill_due'], $tid, date('Y-m-d H:i:s')]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    lg_log($me, '거래 나누기', "{$t['tx_date']} {$t['content']} " . lg_won($total) . '원 → ' . count($items) . '줄', $items);
    return [[['ok', lg_won($total) . '원을 ' . count($items) . '줄로 나눴어요.']], $back];
}

/* ================================================================ 수기 입력 */

/** '현금(시재)' 계좌 (없으면 만든다) */
function lg_cash_account() {
    $a = lg_row("SELECT * FROM bl_ledger_accounts WHERE name='현금(시재)'");
    if (!$a) {
        lg_exec("INSERT INTO bl_ledger_accounts(name, kind, institution, opening_balance, created_at) VALUES ('현금(시재)', '계좌', '현금', 0, ?)", [date('Y-m-d H:i:s')]);
        $a = lg_row("SELECT * FROM bl_ledger_accounts WHERE name='현금(시재)'");
    }
    return $a;
}

function lg_page_manual(array $q, array $me) {
    $accs = lg_all("SELECT * FROM bl_ledger_accounts WHERE pay_group IS NULL ORDER BY kind, id");
    return [
        'accounts' => $accs, 'has_cash' => in_array('현금(시재)', array_column($accs, 'name'), true),
        'recent' => lg_all("SELECT t.*, a.name AS acc_name FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
                            WHERE t.source_file=? ORDER BY t.tx_date DESC, t.id DESC LIMIT 50", [LG_MANUAL]),
        'options' => lg_category_options(), 'today' => lg_today(),
    ];
}

function lg_post_manual(array $p, array $me) {
    $back = lg_url('manual');
    if (($p['do'] ?? 'add') === 'delete') {
        $t = lg_row("SELECT * FROM bl_ledger_tx WHERE id=? AND source_file=?", [(int)($p['id'] ?? 0), LG_MANUAL]);
        if (!$t) return [[['error', '수기 입력한 거래만 지울 수 있어요.']], $back];
        if (in_array(substr($t['tx_date'], 0, 7), lg_closed_months(), true)) return [[['error', '마감한 달이라 지울 수 없어요.']], $back];
        if (lg_val("SELECT 1 FROM bl_ledger_invoice_links WHERE tx_id=?", [$t['id']])) return [[['error', '세금계산서와 연결된 거래예요. 증빙·정산에서 연결을 먼저 풀어주세요.']], $back];
        lg_exec("DELETE FROM bl_ledger_tx WHERE id=?", [$t['id']]);
        lg_log($me, '수기 입력 지움', "{$t['tx_date']} {$t['content']}", $t);
        return [[['ok', '지웠어요.']], $back];
    }
    $d = lg_to_date($p['tx_date'] ?? '');
    $amt = lg_to_int($p['amount'] ?? '');
    $acc_id = (string)($p['account_id'] ?? '');
    if ($acc_id === 'cash') $acc_id = lg_cash_account()['id'];
    $acc = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [(int)$acc_id]);
    if (!$d || $amt <= 0 || !$acc) return [[['error', '날짜, 금액, 계좌·카드를 확인해 주세요.']], $back];
    if (in_array(substr($d, 0, 7), lg_closed_months(), true)) return [[['error', (int)substr($d, 5, 2) . '월은 마감한 달이라 넣을 수 없어요.']], $back];
    $is_in = ($p['dir'] ?? '') === '입금';
    $cat = (string)($p['category'] ?? '');
    lg_exec("INSERT INTO bl_ledger_tx(account_id, tx_date, content, amount_in, amount_out, category, detail, memo,
             source_file, dedup_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        [$acc['id'], $d, lg_strip($p['content'] ?? '') ?: LG_MANUAL, $is_in ? $amt : 0, $is_in ? 0 : $amt, $cat,
         lg_strip($p['detail'] ?? ''), lg_strip($p['memo'] ?? ''), LG_MANUAL, 'MAN|' . bin2hex(random_bytes(16)), date('Y-m-d H:i:s')]);
    $new_id = (int)lg_db()->lastInsertId();
    if ($cat === '') { lg_apply_detail_words([$new_id]); lg_apply_rules([$new_id]); }
    lg_log($me, '수기 입력', "$d {$acc['name']} " . ($is_in ? '입금' : '출금') . " $amt");
    return [[['ok', "{$acc['name']}에 " . ($is_in ? '입금' : '출금') . ' ' . lg_won($amt) . '원을 넣었어요.']], $back];
}

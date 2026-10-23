<?php
/**
 * BlueLedger 회계 장부 — ⑦ 월마감·보고서 (파이썬 month_close·close_checks·profit_loss·invoice_summary·
 * reports·reports_export + templates/month_close.html, reports.html).
 *  달라진 점: 마감할 때 파이썬은 장부 파일을 백업했다. 서버에서는 DB 백업을 운영에서 따로 하므로
 *  마감 시점의 요약(계좌 잔액·수익·비용·남은 확인 사항)을 마감 기록과 작업 기록에 남긴다.
 */

require_once __DIR__ . '/ledger_settle.php';
require_once __DIR__ . '/ledger_xlsx.php';
require_once __DIR__ . '/ledger_views.php';

function lg_month_bounds($ym) {
    $first = "$ym-01";
    return [$first, lg_add_days(lg_ym_add($ym, 1) . '-01', -1)];
}

/** 마감 전 점검: 통장 잔액, 카드 결제, 미분류, 출납장 내용, 세금계산서. [점검 목록, 계좌별 잔액] */
function lg_close_checks($ym) {
    [$f, $l] = lg_month_bounds($ym);
    $checks = [];
    $banks = [];
    foreach (lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='계좌' ORDER BY id") as $a) {
        $book = (int)$a['opening_balance'] + (int)(lg_val("SELECT SUM(amount_in)-SUM(amount_out) FROM bl_ledger_tx WHERE account_id=? AND tx_date<=?", [$a['id'], $l]) ?? 0);
        $lastday = lg_val("SELECT MAX(tx_date) FROM bl_ledger_tx WHERE account_id=? AND tx_date<=? AND bank_balance IS NOT NULL", [$a['id'], $l]);
        $ok = null;
        if ($lastday) {
            $bk = (int)$a['opening_balance'] + (int)(lg_val("SELECT SUM(amount_in)-SUM(amount_out) FROM bl_ledger_tx WHERE account_id=? AND tx_date<=?", [$a['id'], $lastday]) ?? 0);
            $ok = in_array($bk, array_map('intval', array_column(lg_all("SELECT bank_balance FROM bl_ledger_tx WHERE account_id=? AND tx_date=? AND bank_balance IS NOT NULL", [$a['id'], $lastday]), 'bank_balance')), true);
        }
        $banks[] = ['name' => $a['name'], 'book' => $book, 'ok' => $ok, 'day' => $lastday];
    }
    $bad = array_filter($banks, fn($b) => $b['ok'] === false);
    $none = array_filter($banks, fn($b) => $b['ok'] === null);
    $checks[] = ['title' => '통장 잔액이 은행과 맞나요?', 'level' => $bad ? 'bad' : ($none ? 'warn' : 'ok'),
                 'text' => implode(', ', array_map(fn($b) => "{$b['name']} " . lg_won($b['book']) . '원' . ($b['ok'] ? ' ✓' : ($b['ok'] === null ? ' (은행 잔액 정보 없음)' : ' ✗ 차이')), $banks)),
                 'link' => lg_url('cashbook', ['d' => $l])];
    // 카드: 이 달에 결제일이 있는 청구분이 맞는지
    $card_txt = [];
    $card_lv = 'ok';
    foreach (lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='카드' AND pay_group IS NULL ORDER BY id") as $lead) {
        [, $bills] = lg_card_bills($lead['id']);
        foreach ($bills as $b) {
            if (substr($b['due'], 0, 7) !== $ym) continue;
            if ($b['status'] === '결제 완료' && $b['diff'] !== 0) { $card_lv = 'bad'; $card_txt[] = "{$lead['name']} {$b['due']} 결제분 차이 " . lg_won($b['diff']) . '원'; }
            elseif ($b['status'] === '결제 완료') $card_txt[] = "{$lead['name']} {$b['due']} 결제분 일치 ✓";
            else { if ($card_lv === 'ok') $card_lv = 'warn'; $card_txt[] = "{$lead['name']} {$b['due']} 결제 예정 " . lg_won($b['remain']) . '원'; }
        }
    }
    $lead0 = lg_val("SELECT id FROM bl_ledger_accounts WHERE kind='카드' AND pay_group IS NULL ORDER BY id");
    $checks[] = ['title' => '카드대금이 청구액과 맞나요?', 'level' => $card_lv,
                 'text' => $card_txt ? implode(' · ', $card_txt) : '이 달에 결제일인 카드 청구가 없어요.',
                 'link' => ($lead0 && lg_val("SELECT 1 FROM bl_ledger_accounts WHERE kind='카드'")) ? lg_url('card_check', ['aid' => $lead0]) : null];
    $unc = (int)lg_val("SELECT COUNT(*) FROM bl_ledger_tx WHERE category='' AND tx_date BETWEEN ? AND ?", [$f, $l]);
    $checks[] = ['title' => '미분류 거래가 없나요?', 'level' => $unc ? 'bad' : 'ok', 'text' => $unc ? "미분류 {$unc}건" : '모두 분류됐어요 ✓',
                 'link' => lg_url('classify', ['start' => $f, 'end' => $l])];
    $nod = (int)lg_val("SELECT COUNT(*) FROM bl_ledger_tx WHERE COALESCE(detail,'')='' AND split_of IS NULL AND tx_date BETWEEN ? AND ?
                         AND content NOT LIKE '" . LG_OVERSEAS_FEE . "%' AND source_file<>? AND category NOT IN (?, ?)", [$f, $l, LG_ADJUST, LG_CARD_PAY, LG_CANCELLED]);
    $checks[] = ['title' => '출납장 내용을 다 적었나요?', 'level' => $nod ? 'warn' : 'ok',
                 'text' => $nod ? "내용 안 적은 거래 {$nod}건 (마감은 할 수 있어요)" : '모두 적었어요 ✓',
                 'link' => lg_url('daybook', ['start' => $f, 'end' => $l, 'empty' => 1])];
    $inv = array_map(fn($r) => $r + ['status' => lg_inv_status($r)], lg_invoice_rows("write_date BETWEEN ? AND ?", [$f, $l]));
    $n = count($inv);
    $chk = count(array_filter($inv, fn($r) => $r['status'] === '확인 필요'));
    $opn = count(array_filter($inv, fn($r) => in_array($r['status'], ['미수', '미지급', '일부 정산'], true)));
    $checks[] = ['title' => '세금계산서를 가져오고 정산을 확인했나요?', 'level' => (!$n || $chk) ? 'warn' : 'ok',
                 'text' => $n ? "이 달 세금계산서 {$n}건" . ($chk ? ", 확인 필요 {$chk}건" : '') . ($opn ? ", 아직 미수·미지급 {$opn}건 (다음 달로 넘어가도 괜찮아요)" : '')
                              : '이 달 세금계산서가 아직 없어요. 홈택스에서 가져오세요.',
                 'link' => lg_url('settle', ['start' => $f, 'end' => $l])];
    return [$checks, $banks];
}

function lg_page_close(array $q, array $me) {
    $months = array_column(lg_all("SELECT DISTINCT substr(tx_date,1,7) AS m FROM bl_ledger_tx ORDER BY 1"), 'm');
    $closes = [];
    foreach (lg_all("SELECT * FROM bl_ledger_month_closes") as $r) $closes[$r['ym']] = $r + ['info' => json_decode($r['summary'] ?: '{}', true) ?: []];
    $default = substr(lg_add_days(substr(lg_today(), 0, 8) . '01', -1), 0, 7);
    $ym = preg_match('/^\d{4}-\d{2}$/', lg_q($q, 'ym')) ? lg_q($q, 'ym') : null;
    if (!$ym) {
        $ym = $default;
        foreach ($months as $m) { if (!isset($closes[$m]) && $m <= $default) { $ym = $m; break; } }
    }
    [$checks] = lg_close_checks($ym);
    return ['ym' => $ym, 'months' => $months, 'closes' => $closes, 'checks' => $checks, 'closed' => $closes[$ym] ?? null];
}

function lg_post_close(array $p, array $me) {
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)($p['ym'] ?? '')) ? $p['ym'] : '';
    if (!$ym) return [[], lg_url('close')];
    $label = substr($ym, 0, 4) . '년 ' . (int)substr($ym, 5) . '월';
    if (($p['do'] ?? '') === 'reopen') {
        lg_exec("DELETE FROM bl_ledger_month_closes WHERE ym=?", [$ym]);
        lg_log($me, '마감 풀기', $label);
        return [[['ok', "$label 마감을 풀었어요. 고칠 게 끝나면 다시 마감해 주세요."]], lg_url('close', ['ym' => $ym])];
    }
    [$checks, $banks] = lg_close_checks($ym);
    [$first, $last] = lg_month_bounds($ym);
    $pl = lg_profit_loss($first, $last);
    $summary = ['banks' => array_map(fn($b) => [$b['name'], $b['book']], $banks), 'revenue' => $pl['rev_total'], 'expense' => $pl['exp_total'],
                'warnings' => array_values(array_map(fn($c) => $c['title'], array_filter($checks, fn($c) => $c['level'] !== 'ok')))];
    bl_upsert(lg_db(), 'bl_ledger_month_closes', ['ym' => $ym, 'closed_at' => date('Y-m-d H:i:s'), 'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)], ['ym']);
    lg_log($me, '월마감', $label, $summary);
    return [[['ok', "{$label}을 마감했어요. 마감 시점 요약(계좌 잔액·수익·비용)은 작업 기록에 남겨 뒀어요."]], lg_url('close', ['ym' => $ym])];
}

/* ================================================================ 보고서 */

/** 현금 기준 손익: 수익 계정은 (입금-출금), 비용 계정은 (출금-입금). 자금이동은 제외 */
function lg_profit_loss($start, $end) {
    $grp = [];
    $order = [];
    foreach (lg_all("SELECT name, grp, sort FROM bl_ledger_categories") as $c) { $grp[$c['name']] = $c['grp']; $order[$c['name']] = (float)$c['sort']; }
    $rows = lg_all("SELECT category, substr(tx_date,1,7) AS ym, SUM(amount_in) AS i, SUM(amount_out) AS o, COUNT(*) AS n
                    FROM bl_ledger_tx WHERE tx_date BETWEEN ? AND ? GROUP BY category, substr(tx_date,1,7)", [$start, $end]);
    $months = array_values(array_unique(array_column($rows, 'ym')));
    sort($months);
    $rev = $exp = $moves = $unc = [];
    foreach ($rows as $r) {
        $cat = $r['category'];
        $g = $cat !== '' ? ($grp[$cat] ?? '비용') : '미분류';
        $i = (int)$r['i'];
        $o = (int)$r['o'];
        if ($g === '수익') $rev[$cat][$r['ym']] = ($rev[$cat][$r['ym']] ?? 0) + $i - $o;
        elseif ($g === '비용') $exp[$cat][$r['ym']] = ($exp[$cat][$r['ym']] ?? 0) + $o - $i;
        elseif ($g === '미분류') $unc[$r['ym']] = ($unc[$r['ym']] ?? 0) + $i + $o;
        else $moves[$cat][$r['ym']] = ($moves[$cat][$r['ym']] ?? 0) + $i - $o;
    }
    $table = function ($d) use ($order) {
        $names = array_map('strval', array_keys($d));
        usort($names, fn($a, $b) => (($order[$a] ?? 9999) <=> ($order[$b] ?? 9999)) ?: strcmp($a, $b));
        return array_map(fn($k) => ['name' => $k, 'by' => $d[$k], 'total' => array_sum($d[$k])], $names);
    };
    $rev_t = $table($rev);
    $exp_t = $table($exp);
    $rev_m = $exp_m = [];
    foreach ($months as $m) {
        $rev_m[$m] = array_sum(array_map(fn($r) => $r['by'][$m] ?? 0, $rev_t));
        $exp_m[$m] = array_sum(array_map(fn($r) => $r['by'][$m] ?? 0, $exp_t));
    }
    return ['months' => $months, 'rev' => $rev_t, 'exp' => $exp_t, 'moves' => $table($moves), 'unc' => $unc,
            'rev_m' => $rev_m, 'exp_m' => $exp_m, 'rev_total' => array_sum($rev_m), 'exp_total' => array_sum($exp_m)];
}

function lg_invoice_summary($start, $end) {
    $out = [];
    foreach (['매출', '매입'] as $kind) {
        $r = lg_row("SELECT COUNT(*) AS n, COALESCE(SUM(supply),0) AS supply, COALESCE(SUM(tax),0) AS tax, COALESCE(SUM(total),0) AS total
                     FROM bl_ledger_invoices WHERE kind=? AND write_date BETWEEN ? AND ?", [$kind, $start, $end]);
        $out[$kind] = array_map('intval', $r);
    }
    $top = lg_all("SELECT MAX(partner_name) AS partner_name, COUNT(*) AS n, SUM(supply) AS supply FROM bl_ledger_invoices
                   WHERE kind='매출' AND write_date BETWEEN ? AND ? GROUP BY partner_id ORDER BY SUM(supply) DESC LIMIT 10", [$start, $end]);
    $quarters = [];
    $y = (int)substr($start, 0, 4);
    for ($q = 1; $q <= 4; $q++) {
        $qs = sprintf('%04d-%02d-01', $y, 3 * $q - 2);
        $qe = lg_add_days(sprintf('%04d-%02d-01', $y + ($q === 4 ? 1 : 0), (3 * $q) % 12 + 1), -1);
        if ($qe < $start || $qs > $end) continue;
        $quarters[] = ['q' => $q, 'start' => $qs, 'end' => $qe,
                       'sale_tax' => (int)lg_val("SELECT COALESCE(SUM(tax),0) FROM bl_ledger_invoices WHERE kind='매출' AND write_date BETWEEN ? AND ?", [$qs, $qe]),
                       'buy_tax' => (int)lg_val("SELECT COALESCE(SUM(tax),0) FROM bl_ledger_invoices WHERE kind='매입' AND write_date BETWEEN ? AND ?", [$qs, $qe])];
    }
    return [$out, $top, $quarters];
}

function lg_reports_range(array $q) {
    $today = lg_today();
    return [lg_qdate($q, 'start', substr($today, 0, 4) . '-01-01'), lg_qdate($q, 'end', lg_add_days(substr($today, 0, 8) . '01', -1))];
}

function lg_page_reports(array $q, array $me) {
    [$start, $end] = lg_reports_range($q);
    $pl = lg_profit_loss($start, $end);
    [$inv, $top, $quarters] = lg_invoice_summary($start, $end);
    $opens = array_map(fn($r) => $r + ['status' => lg_inv_status($r)], lg_invoice_rows("closed='' AND total>0"));
    $ar = array_sum(array_map(fn($r) => (int)$r['total'] - (int)$r['paid'], array_filter($opens, fn($r) => $r['kind'] === '매출' && in_array($r['status'], ['미수', '일부 정산'], true))));
    $ap = array_sum(array_map(fn($r) => (int)$r['total'] - (int)$r['paid'], array_filter($opens, fn($r) => $r['kind'] === '매입' && in_array($r['status'], ['미지급', '일부 정산'], true))));
    $biggest = $pl['exp'] ? max(array_column($pl['exp'], 'total')) : 1;
    return ['start' => $start, 'end' => $end, 'pl' => $pl, 'inv' => $inv, 'top' => $top, 'quarters' => $quarters,
            'ar' => $ar, 'ap' => $ap, 'biggest' => $biggest, 'closed' => lg_closed_months()];
}

/** 세무사 전달용 엑셀: 손익요약 · 계정과목별 내역 · 세금계산서 · 미수·미지급 */
function lg_reports_book(array $q) {
    [$start, $end] = lg_reports_range($q);
    $pl = lg_profit_loss($start, $end);
    $F = fn($sz = 10, $b = false) => ['font' => [$sz, $b]];
    $box = ['border' => true];
    $head = ['fill' => 'DDEBF7'];
    $sub = ['fill' => 'F2F2F2'];
    $acct = '#,##0;-#,##0;"-"';
    $C = fn($r, $c) => lg_xlsx_col($c) . $r;
    $wb = new LgXlsx();
    // ① 손익요약
    $ws = $wb->addSheet('손익요약');
    $ws->v('A1', "손익 요약 (현금 기준, 부가세 포함)  $start ~ $end"); $ws->s('A1', $F(14, true));
    $ws->v('A2', '통장·카드에서 실제로 들어오고 나간 돈 기준이에요. 카드대금 결제·계좌 이체 같은 자금이동은 빠져 있어요.'); $ws->s('A2', $F(9));
    $months = $pl['months'];
    $nm = count($months);
    $r = 4;
    $header = function ($r) use ($ws, $months, $F, $head, $box, $C) {
        $cells = array_merge(['계정과목'], array_map(fn($m) => (int)substr($m, 5) . '월', $months), ['합계']);
        foreach ($cells as $j => $hd) { $ws->v($C($r, $j + 1), $hd); $ws->s($C($r, $j + 1), $F(10, true) + $head + $box + ['align' => ['h' => 'center']]); }
    };
    $block = function ($title, $items, $r) use ($ws, $months, $nm, $F, $box, $sub, $acct, $header, $C) {
        $ws->v($C($r, 1), $title); $ws->s($C($r, 1), $F(11, true)); $r++;
        $header($r); $r++;
        $first = $r;
        foreach ($items as $it) {
            $ws->v($C($r, 1), $it['name']); $ws->s($C($r, 1), $F());
            foreach ($months as $j => $m) { $ws->v($C($r, $j + 2), ($it['by'][$m] ?? 0) ?: null); $ws->s($C($r, $j + 2), ['fmt' => $acct] + $F()); }
            $ws->v($C($r, $nm + 2), ['f' => 'SUM(B' . $r . ':' . lg_xlsx_col($nm + 1) . $r . ')']); $ws->s($C($r, $nm + 2), ['fmt' => $acct] + $F(10, true));
            for ($j = 1; $j <= $nm + 2; $j++) $ws->s($C($r, $j), $box);
            $r++;
        }
        $ws->v($C($r, 1), "$title 합계"); $ws->s($C($r, 1), $F(10, true));
        for ($j = 2; $j <= $nm + 2; $j++) {
            $col = lg_xlsx_col($j);
            $ws->v($C($r, $j), $items ? ['f' => "SUM($col$first:$col" . ($r - 1) . ')'] : 0); $ws->s($C($r, $j), ['fmt' => $acct] + $F(10, true));
        }
        for ($j = 1; $j <= $nm + 2; $j++) $ws->s($C($r, $j), $sub + $box);
        return [$r, $r + 2];
    };
    [$rev_row, $r] = $block('수익', $pl['rev'], $r);
    [$exp_row, $r] = $block('비용', $pl['exp'], $r);
    $ws->v($C($r, 1), '이익 (수익 - 비용)'); $ws->s($C($r, 1), $F(11, true));
    for ($j = 2; $j <= $nm + 2; $j++) {
        $col = lg_xlsx_col($j);
        $ws->v($C($r, $j), ['f' => "$col$rev_row-$col$exp_row"]); $ws->s($C($r, $j), ['fmt' => $acct] + $F(11, true) + $head + $box);
    }
    $ws->s($C($r, 1), $head + $box);
    $ws->widths[1] = 26;
    for ($j = 2; $j <= $nm + 2; $j++) $ws->widths[$j] = 14;
    $ws->freeze = 'B4';
    // ② 계정과목별 거래내역
    $ws2 = $wb->addSheet('계정과목별 내역');
    foreach (['구분', '계정과목', '날짜', '통장·카드', '적요', '내용(출납장)', '입금', '출금', '메모'] as $j => $hd) { $ws2->v($C(1, $j + 1), $hd); $ws2->s($C(1, $j + 1), $F(10, true) + $head + $box); }
    $grp = [];
    foreach (lg_all("SELECT name, grp FROM bl_ledger_categories") as $c) $grp[$c['name']] = $c['grp'];
    $gord = ['수익' => 0, '비용' => 1, '자금이동' => 2];
    $rows = lg_all("SELECT t.*, a.name AS acc FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
                    WHERE t.tx_date BETWEEN ? AND ? ORDER BY t.category, t.tx_date, t.id", [$start, $end]);
    $gname = fn($t) => $t['category'] !== '' ? ($grp[$t['category']] ?? '비용') : '미분류';
    usort($rows, fn($a, $b) => (($gord[$gname($a)] ?? 3) <=> ($gord[$gname($b)] ?? 3)) ?: strcmp($a['category'], $b['category']) ?: strcmp($a['tx_date'], $b['tx_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
    foreach ($rows as $k => $t) {
        $i = $k + 2;
        $vals = [$gname($t), $t['category'] ?: '미분류', $t['tx_date'], $t['acc'], $t['content'], $t['detail'] ?: '', (int)$t['amount_in'] ?: null, (int)$t['amount_out'] ?: null, $t['memo'] ?: ($t['note'] ?: '')];
        foreach ($vals as $j => $val) {
            $ws2->v($C($i, $j + 1), $val); $ws2->s($C($i, $j + 1), $F(9));
            if ($j === 6 || $j === 7) $ws2->s($C($i, $j + 1), ['fmt' => $acct]);
        }
    }
    $n2 = count($rows) + 1;
    $ws2->v($C($n2 + 1, 6), '합계'); $ws2->s($C($n2 + 1, 6), $F(10, true));
    foreach (['G', 'H'] as $col) { $ws2->v("$col" . ($n2 + 1), ['f' => "SUBTOTAL(9,{$col}2:$col$n2)"]); $ws2->s("$col" . ($n2 + 1), ['fmt' => $acct] + $F(10, true)); }
    $ws2->autoFilter = "A1:I$n2";
    $ws2->freeze = 'A2';
    foreach ([9, 20, 11, 22, 26, 34, 13, 13, 20] as $j => $w) $ws2->widths[$j + 1] = $w;
    // ③ 세금계산서
    $ws3 = $wb->addSheet('세금계산서');
    foreach (['구분', '작성일', '거래처', '사업자번호', '품목', '공급가액', '세액', '합계', '정산 상태'] as $j => $hd) { $ws3->v($C(1, $j + 1), $hd); $ws3->s($C(1, $j + 1), $F(10, true) + $head + $box); }
    $invs = array_map(fn($x) => $x + ['status' => lg_inv_status($x)], lg_invoice_rows("write_date BETWEEN ? AND ?", [$start, $end]));
    $pmap = [];
    foreach (lg_all("SELECT id, biz_no FROM bl_ledger_partners") as $pp) $pmap[(int)$pp['id']] = (string)$pp['biz_no'];
    $idx = array_keys($invs);
    usort($idx, fn($a, $b) => strcmp($invs[$a]['kind'], $invs[$b]['kind']) ?: strcmp($invs[$a]['write_date'], $invs[$b]['write_date']) ?: ($a <=> $b));
    foreach ($idx as $k => $ii) {
        $x = $invs[$ii];
        $i = $k + 2;
        $b = $pmap[(int)$x['partner_id']] ?? '';
        $vals = [$x['kind'] . ($x['doc_type'] === '계산서' ? '(면세)' : ''), $x['write_date'], $x['partner_name'],
                 $b !== '' ? substr($b, 0, 3) . '-' . substr($b, 3, 2) . '-' . substr($b, 5) : '', $x['item'], (int)$x['supply'], (int)$x['tax'], (int)$x['total'], $x['status']];
        foreach ($vals as $j => $val) {
            $ws3->v($C($i, $j + 1), $val); $ws3->s($C($i, $j + 1), $F(9));
            if ($j >= 5 && $j <= 7) $ws3->s($C($i, $j + 1), ['fmt' => $acct]);
        }
    }
    $n3 = count($invs) + 1;
    foreach (['F', 'G', 'H'] as $col) { $ws3->v("$col" . ($n3 + 1), ['f' => "SUBTOTAL(9,{$col}2:$col$n3)"]); $ws3->s("$col" . ($n3 + 1), ['fmt' => $acct] + $F(10, true)); }
    $ws3->autoFilter = "A1:I$n3";
    $ws3->freeze = 'A2';
    foreach ([10, 11, 30, 14, 34, 14, 12, 14, 11] as $j => $w) $ws3->widths[$j + 1] = $w;
    // ④ 미수·미지급
    $ws4 = $wb->addSheet('미수·미지급');
    foreach (['구분', '작성일', '거래처', '품목', '합계', '받은·준 금액', '남은 금액'] as $j => $hd) { $ws4->v($C(1, $j + 1), $hd); $ws4->s($C(1, $j + 1), $F(10, true) + $head + $box); }
    $opens = array_values(array_filter(array_map(fn($x) => $x + ['status' => lg_inv_status($x)], lg_invoice_rows("closed='' AND total>0")),
        fn($x) => in_array($x['status'], ['미수', '미지급', '일부 정산'], true)));
    $idx = array_keys($opens);
    usort($idx, fn($a, $b) => strcmp($opens[$a]['kind'], $opens[$b]['kind']) ?: strcmp($opens[$a]['write_date'], $opens[$b]['write_date']) ?: ($a <=> $b));
    foreach ($idx as $k => $ii) {
        $x = $opens[$ii];
        $i = $k + 2;
        $vals = [$x['kind'] === '매출' ? '미수' : '미지급', $x['write_date'], $x['partner_name'], $x['item'], (int)$x['total'], (int)$x['paid'], null];
        foreach ($vals as $j => $val) {
            $ws4->v($C($i, $j + 1), $val); $ws4->s($C($i, $j + 1), $F(9));
            if ($j >= 4) $ws4->s($C($i, $j + 1), ['fmt' => $acct]);
        }
        $ws4->v("G$i", ['f' => "E$i-F$i"]);
    }
    foreach ([8, 11, 30, 34, 14, 14, 14] as $j => $w) $ws4->widths[$j + 1] = $w;
    return [$wb, "BlueLedger_보고서_{$start}_{$end}.xlsx"];
}

/* ================================================================ 화면 */

function lg_view_close(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    $ym = $v['ym'];
    $label = substr($ym, 0, 4) . '년 ' . (int)substr($ym, 5) . '월';
    $closed = $v['closed'];
    $badge = ['ok' => ['b-green', '좋아요'], 'warn' => ['b-orange', '확인'], 'bad' => ['b-orange', '고쳐야 해요']];
    ob_start(); ?>
<div class="box">
  <p class="help">한 달 장부를 점검하고 <b>마감</b>하면, 그 달 거래는 바뀌지 않게 잠겨요(분류·출납장 내용·나누기·삭제·새로 가져오기 모두). 고칠 게 생기면 마감을 풀고 고친 뒤 다시 마감하면 돼요. 마감할 때 그 시점의 요약을 작업 기록에 남겨요.</p>
  <div class="bar-l">
    <?php foreach ($v['months'] as $m): ?><a class="btn <?= $m === $ym ? '' : 'ghost' ?>" href="<?= h(lg_url('close', ['ym' => $m])) ?>" ><?= substr($m, 2, 2) ?>.<?= substr($m, 5) ?><?= isset($v['closes'][$m]) ? ' 🔒' : '' ?></a><?php endforeach ?>
  </div>
</div>

<div class="box">
  <h2><?= h($label) ?> <?php if ($closed): ?><span class="badge b-green">마감 완료 · <?= h(substr($closed['closed_at'], 0, 16)) ?></span><?php else: ?><span class="badge b-orange">마감 전</span><?php endif ?></h2>
  <div class="scroll"><table>
    <tr><th>점검</th><th>결과</th><th></th><th></th></tr>
    <?php foreach ($v['checks'] as $c): ?>
    <tr><td><b><?= h($c['title']) ?></b></td>
      <td style="white-space:normal"><?= h($c['text']) ?></td>
      <td><span class="badge <?= $badge[$c['level']][0] ?>"><?= $badge[$c['level']][1] ?></span></td>
      <td><?php if ($c['link']): ?><a href="<?= h($c['link']) ?>" style="font-size:13px;white-space:nowrap">보러 가기</a><?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <?php if ($closed): ?>
    <?php if (!empty($closed['info']['warnings'])): ?><p class="help">마감할 때 남아 있던 확인 사항: <?= h(implode(', ', $closed['info']['warnings'])) ?></p><?php endif ?>
    <?php if (!$ceo): ?><form method="post" action="<?= h(lg_url('close')) ?>" data-confirm="<?= h($label) ?> 마감을 풀까요? 이 달 거래를 다시 고칠 수 있게 돼요."><?= lg_csrf_field() ?><input type="hidden" name="ym" value="<?= h($ym) ?>"><input type="hidden" name="do" value="reopen"><button class="btn ghost" type="submit">마감 풀기</button></form><?php endif ?>
  <?php else: ?>
    <?php $bad = count(array_filter($v['checks'], fn($c) => $c['level'] === 'bad')); ?>
    <?php if ($bad): ?><div class="flash error">"고쳐야 해요" 항목이 <?= $bad ?>개 있어요. 고친 뒤 마감하는 걸 추천해요. 그래도 마감은 할 수 있어요.</div><?php endif ?>
    <?php if (!$ceo): ?><form method="post" action="<?= h(lg_url('close')) ?>" data-confirm="<?= h($label) ?>을 마감할까요? 이 달 거래가 잠겨요."><?= lg_csrf_field() ?><input type="hidden" name="ym" value="<?= h($ym) ?>"><button class="btn" type="submit"><?= (int)substr($ym, 5) ?>월 마감하기</button></form><?php endif ?>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_reports(array $v, array $me) {
    $pl = $v['pl'];
    $closed = array_flip($v['closed']);
    $exp_sorted = $pl['exp'];
    usort($exp_sorted, fn($a, $b) => $b['total'] <=> $a['total']);
    ob_start(); ?>
<div class="box">
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="reports">
    <label>시작일<input type="date" name="start" value="<?= h($v['start']) ?>"></label>
    <label>종료일<input type="date" name="end" value="<?= h($v['end']) ?>"></label>
    <button class="btn" type="submit">조회</button>
    <a class="btn ghost" href="<?= h(lg_url('print', ['kind' => 'reports', 'start' => $v['start'], 'end' => $v['end']])) ?>" data-popup>세무사 전달용 보기·인쇄</a>
  </form>
  <p class="help" style="margin:0">손익은 통장·카드에서 <b>실제로 들어오고 나간 돈 기준(부가세 포함)</b>이에요. 카드대금 결제·계좌 이체 같은 자금이동은 빠져 있어요. 세금계산서 기준 금액은 아래에 따로 보여드려요.
  <?php if ($pl['unc']): ?><br><b class="warn">미분류 거래가 있어서 손익이 정확하지 않을 수 있어요</b> (<a href="<?= h(lg_url('classify_groups')) ?>">분류하러 가기</a>).<?php endif ?></p>
</div>

<div class="box">
  <div class="result">
    <div>수익<strong class="in"><?= lg_won($pl['rev_total']) ?></strong></div>
    <div>비용<strong class="out"><?= lg_won($pl['exp_total']) ?></strong></div>
    <div>이익<strong><?= lg_won($pl['rev_total'] - $pl['exp_total']) ?></strong></div>
    <div>지금 미수금 / 미지급금<strong style="font-size:18px"><?= lg_won($v['ar']) ?> / <?= lg_won($v['ap']) ?></strong></div>
  </div>
</div>

<div class="box">
  <h2>월별 손익</h2>
  <div class="scroll"><table>
    <tr><th>계정과목</th><?php foreach ($pl['months'] as $m): ?><th class="num"><?= (int)substr($m, 5) ?>월<?= isset($closed[$m]) ? ' 🔒' : '' ?></th><?php endforeach ?><th class="num">합계</th></tr>
    <tr class="sum"><td>수익</td><?php foreach ($pl['months'] as $m): ?><td class="num in"><?= lg_won($pl['rev_m'][$m]) ?></td><?php endforeach ?><td class="num in"><?= lg_won($pl['rev_total']) ?></td></tr>
    <?php foreach ($pl['rev'] as $r): ?><tr><td style="padding-left:24px"><?= h($r['name']) ?></td><?php foreach ($pl['months'] as $m): ?><td class="num"><?= lg_won_or_blank($r['by'][$m] ?? 0) ?></td><?php endforeach ?><td class="num"><?= lg_won($r['total']) ?></td></tr><?php endforeach ?>
    <tr class="sum"><td>비용</td><?php foreach ($pl['months'] as $m): ?><td class="num out"><?= lg_won($pl['exp_m'][$m]) ?></td><?php endforeach ?><td class="num out"><?= lg_won($pl['exp_total']) ?></td></tr>
    <?php foreach ($pl['exp'] as $r): ?><tr><td style="padding-left:24px"><?= h($r['name']) ?></td><?php foreach ($pl['months'] as $m): ?><td class="num"><?= lg_won_or_blank($r['by'][$m] ?? 0) ?></td><?php endforeach ?><td class="num"><?= lg_won($r['total']) ?></td></tr><?php endforeach ?>
    <tr class="sum"><td>이익</td><?php foreach ($pl['months'] as $m): ?><td class="num"><?= lg_won($pl['rev_m'][$m] - $pl['exp_m'][$m]) ?></td><?php endforeach ?><td class="num"><?= lg_won($pl['rev_total'] - $pl['exp_total']) ?></td></tr>
  </table></div>
</div>

<div class="cols" style="grid-template-columns:1fr 1fr">
  <div class="box">
    <h2>비용 많은 순서</h2>
    <?php foreach (array_slice($exp_sorted, 0, 12) as $r): $w = $r['total'] > 0 ? round($r['total'] / $v['biggest'] * 100, 1) : 0; ?>
    <div class="barrow"><span class="bn"><?= h($r['name']) ?></span><span class="bt"><span style="width:<?= $w ?>%"></span></span><span class="bv num"><?= lg_won($r['total']) ?></span></div>
    <?php endforeach ?>
  </div>
  <div class="box">
    <h2>세금계산서 기준</h2>
    <div class="scroll"><table>
      <tr><th></th><th class="num">건수</th><th class="num">공급가액</th><th class="num">세액</th><th class="num">합계</th></tr>
      <?php foreach (['매출', '매입'] as $k): $x = $v['inv'][$k]; ?><tr><td><?= $k ?></td><td class="num"><?= $x['n'] ?></td><td class="num"><?= lg_won($x['supply']) ?></td><td class="num"><?= lg_won($x['tax']) ?></td><td class="num"><?= lg_won($x['total']) ?></td></tr><?php endforeach ?>
    </table></div>
    <?php if ($v['quarters']): ?><p class="help" style="margin:8px 0 4px"><b>분기별 부가세 참고</b> (세금계산서만 · 카드 매입세액은 빠져 있어요)</p>
    <div class="scroll"><table><tr><th>분기</th><th class="num">매출세액</th><th class="num">매입세액</th><th class="num">차이</th></tr>
      <?php foreach ($v['quarters'] as $q): ?><tr><td><?= $q['q'] ?>분기</td><td class="num"><?= lg_won($q['sale_tax']) ?></td><td class="num"><?= lg_won($q['buy_tax']) ?></td><td class="num"><b><?= lg_won($q['sale_tax'] - $q['buy_tax']) ?></b></td></tr><?php endforeach ?>
    </table></div><?php endif ?>
    <?php if ($v['top']): ?><p class="help" style="margin:8px 0 4px"><b>매출 거래처 (공급가액)</b></p>
    <div class="scroll"><table><?php foreach ($v['top'] as $t): ?><tr><td style="white-space:normal"><?= h($t['partner_name']) ?></td><td class="num"><?= (int)$t['n'] ?>건</td><td class="num"><?= lg_won($t['supply']) ?></td></tr><?php endforeach ?></table></div><?php endif ?>
  </div>
</div>
<div class="box"><p class="help" style="margin:0"><b>세무사 전달용 엑셀</b>에는 손익요약(월별), 계정과목별 거래내역, 세금계산서 목록, 미수·미지급 시트가 들어가요. 합계는 수식이라 엑셀에서 고쳐도 다시 계산돼요.</p></div>
<?php
    return ob_get_clean();
}

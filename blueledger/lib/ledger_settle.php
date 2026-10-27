<?php
/**
 * BlueLedger 회계 장부 — ⑥ 증빙·정산 (홈택스 세금계산서 ↔ 통장·카드).
 *
 *  파이썬 버전의 norm_party·name_match·month_set·read_invoice_file·auto_settle·prepaid_charges·
 *  allocate_prepaid·import_invoice_paths 와 settle·invoice_detail·settle_bulk·prepaid·partners 화면을 옮겼다.
 *  자동 정산은 순서가 결과를 바꾸므로(먼저 짝지은 거래는 다음 계산서에 못 쓴다) 파이썬과 같은 순서로 돈다:
 *   ① 수정세금계산서 상쇄 → ② 한 건=한 거래 → ③ 여러 건 합산 → ③-2 같은 날 계산서 합산 → ④ 나눠서 입금 → ⑤ 충전금 차감
 *  SQLite 전용 날짜 함수(julianday·date('-90 day'))는 PHP 에서 계산해 MySQL 에서도 같게 돈다.
 */

require_once __DIR__ . '/ledger_import.php';

const LG_PREPAID_RE = '/충전(?!기|케이블|선|패드|식)|캐시|캐쉬|비즈머니|크레딧|선결제|예치/u';

/* ================================================================ 이름·달 비교 */

function lg_norm_party($s) {
    $s = lg_falsy($s) ? '' : lg_str($s);
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_KC);
    else {
        $s = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn($m) => mb_chr(mb_ord($m[0]) - 0xFEE0), $s);
        $s = str_replace(["\u{3000}", '㈜'], [' ', '(주)'], $s);
    }
    $s = str_replace(['주식회사', '유한회사', '재단법인', '사단법인', '사회복지법인', '(주)', '(유)', '(재)', '(사)', '㈜'], '', $s);
    return mb_strtolower(preg_replace('/[\s()\[\]\-_.,·\/]/u', '', $s));
}

/** 두 글자열에서 가장 길게 이어서 같은 부분의 길이 (difflib find_longest_match 의 size) */
function lg_longest_common($a, $b) {
    $x = mb_str_split($a);
    $y = mb_str_split($b);
    $best = 0;
    $prev = array_fill(0, count($y) + 1, 0);
    foreach ($x as $i => $ch) {
        $cur = [0];
        foreach ($y as $j => $dh) {
            $v = $ch === $dh ? $prev[$j] + 1 : 0;
            $cur[] = $v;
            if ($v > $best) $best = $v;
        }
        $prev = $cur;
    }
    return $best;
}

/** 두 이름이 같은 곳을 가리키는지: 공통으로 이어진 글자가 3자 이상이고 짧은 쪽의 절반 이상 → 비율, 아니면 0 */
function lg_name_match($a, $b) {
    static $memo = [];
    $na = lg_norm_party($a);
    $nb = lg_norm_party($b);
    if ($na === '' || $nb === '') return 0;
    $k = "$na\x1f$nb";
    if (isset($memo[$k])) return $memo[$k];
    $size = lg_longest_common($na, $nb);
    $short = min(mb_strlen($na), mb_strlen($nb));
    return $memo[$k] = ($size >= min(3, $short) && $size / $short >= 0.5) ? $size / $short : 0;
}

function lg_tx_text(array $t) {
    return implode(' ', array_filter([$t['content'], $t['note'] ?? '', $t['detail'] ?? ''], fn($x) => $x !== '' && $x !== null));
}

function lg_partner_names(array $p) {
    $names = [$p['name']];
    foreach (explode(',', (string)($p['aliases'] ?? '')) as $a) if (lg_strip($a) !== '') $names[] = lg_strip($a);
    return $names;
}

function lg_partner_match(array $p, array $t) {
    $text = lg_tx_text($t);
    $best = 0;
    foreach (lg_partner_names($p) as $n) $best = max($best, lg_name_match($n, $t['content']), lg_name_match($n, $text) * 0.9);
    return $best;
}

/** 통장 적요(거래처명 칸)만으로 비교 — 출납장 메모 속 다른 회사 이름에 끌려가지 않게 */
function lg_partner_match_content(array $p, array $t) {
    $best = 0;
    foreach (lg_partner_names($p) as $n) $best = max($best, lg_name_match($n, $t['content']));
    return $best;
}

/** '3,4월분', '_9월분', '26.09월', '2026년 7월분' → [3,4] / [9] / [7] (1~12 만) */
function lg_month_set($text) {
    $text = (string)$text;
    $ms = [];
    if (preg_match_all('/(?<![\d.])(\d{1,2})\s*월?\s*[~\-]\s*(\d{1,2})\s*월/u', $text, $mm, PREG_SET_ORDER)) {   // 4월~6월 → 4,5,6
        foreach ($mm as $m) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($a >= 1 && $a <= 12 && $b >= 1 && $b <= 12) {
                foreach ($a <= $b ? range($a, $b) : array_merge(range($a, 12), range(1, $b)) as $x) $ms[$x] = true;
            }
        }
    }
    if (preg_match_all('/((?:\d{1,2}\s*[,·\/]\s*)*)(?<![\d.])0?(\d{1,2})\s*월/u', $text, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            if (preg_match_all('/\d{1,2}/', $m[1], $d)) foreach ($d[0] as $x) $ms[(int)$x] = true;
            $ms[(int)$m[2]] = true;
        }
    }
    if (preg_match_all('/\d{2,4}\.(\d{1,2})\s*월/u', $text, $mm, PREG_SET_ORDER)) foreach ($mm as $m) $ms[(int)$m[1]] = true;
    return array_filter($ms, fn($v, $x) => $x >= 1 && $x <= 12, ARRAY_FILTER_USE_BOTH);
}

/** 계산서가 가리키는 달: 작성월, 그 전달(월초 발행분), 품목에 적힌 달 */
function lg_inv_months(array $inv) {
    $m = (int)substr($inv['write_date'], 5, 2);
    return [$m => true, ($m === 1 ? 12 : $m - 1) => true] + lg_month_set($inv['item']);
}

/** 통장·출납장에 'N월분'이 있으면: 계산서 달과 맞으면 0, 표시가 없으면 1, 다른 달이면 2 */
function lg_hint_rank(array $inv, array $t) {
    $hs = lg_month_set(lg_tx_text($t));
    if (!$hs) return 1;
    return array_intersect_key($hs, lg_inv_months($inv)) ? 0 : 2;
}

function lg_days($a, $b) {
    return (int)round((strtotime($a . ' 12:00:00') - strtotime($b . ' 12:00:00')) / 86400);
}

/** n개씩 고르는 모든 조합 (파이썬 itertools.combinations 와 같은 순서) */
function lg_combinations(array $items, $n) {
    $items = array_values($items);
    $k = count($items);
    if ($n > $k || $n < 1) return;
    $idx = range(0, $n - 1);
    while (true) {
        yield array_map(fn($i) => $items[$i], $idx);
        $i = $n - 1;
        while ($i >= 0 && $idx[$i] === $i + $k - $n) $i--;
        if ($i < 0) return;
        $idx[$i]++;
        for ($j = $i + 1; $j < $n; $j++) $idx[$j] = $idx[$j - 1] + 1;
    }
}

/* ================================================================ 홈택스 목록 파일 */

/** 홈택스 '매출/매입 전자(세금)계산서 목록조회' 엑셀 → 계산서 목록 */
function lg_read_invoice_file($path) {
    $rows = lg_read_table($path);
    $s = fn($v) => lg_str($v);
    $own = '';
    if ($rows && str_replace(' ', '', $s($rows[0][0] ?? null)) === '사업자등록번호') $own = preg_replace('/\D/', '', $s($rows[0][1] ?? null));
    $title = '';
    foreach (array_slice($rows, 0, 10) as $r) { if ($r && str_contains($s($r[0]), '목록조회')) { $title = $s($r[0]); break; } }
    $hi = null;
    foreach (array_slice($rows, 0, 15) as $i => $r) { if ($r && lg_strip($s($r[0])) === '작성일자') { $hi = $i; break; } }
    if ($hi === null) throw new LgReadError('홈택스 세금계산서 목록 파일이 아닌 것 같아요. (작성일자 열을 못 찾음)');
    $H = array_map(fn($x) => lg_falsy($x) ? '' : lg_strip(lg_str($x)), $rows[$hi]);
    $idx = function ($name, $after = 0) use ($H) {
        for ($i = (int)$after; $i < count($H); $i++) if ($H[$i] === $name) return $i;
        return null;
    };
    $s0 = $idx('공급자사업자등록번호');
    $b0 = $idx('공급받는자사업자등록번호');
    $col = ['write' => $idx('작성일자'), 'appr' => $idx('승인번호'), 'issue' => $idx('발급일자'),
            's_no' => $s0, 's_name' => $idx('상호', $s0), 's_ceo' => $idx('대표자명', $s0), 's_addr' => $idx('주소', $s0),
            'b_no' => $b0, 'b_name' => $idx('상호', $b0), 'b_ceo' => $idx('대표자명', $b0), 'b_addr' => $idx('주소', $b0),
            'total' => $idx('합계금액'), 'supply' => $idx('공급가액'), 'tax' => $idx('세액'),
            'doc' => $idx('전자세금계산서 분류'), 'note' => $idx('비고'), 'receipt' => $idx('영수/청구 구분'),
            's_email' => $idx('공급자 이메일'), 'b_email' => $idx('공급받는자 이메일1'), 'item' => $idx('품목명')];
    $get = fn($r, $k) => ($col[$k] !== null && $col[$k] < count($r) && $r[$col[$k]] !== null) ? lg_strip(lg_str($r[$col[$k]])) : '';
    $out = [];
    foreach (array_slice($rows, $hi + 1) as $r) {
        $d = lg_to_date($get($r, 'write'));
        if (!$d) continue;
        $s_no = preg_replace('/\D/', '', $get($r, 's_no'));
        $b_no = preg_replace('/\D/', '', $get($r, 'b_no'));
        $kind = $own !== '' ? ($s_no === $own ? '매출' : '매입') : (str_contains($title, '매출') ? '매출' : '매입');
        $side = $kind === '매출' ? 'b' : 's';
        $out[] = ['kind' => $kind, 'write' => $d, 'issue' => lg_to_date($get($r, 'issue')) ?: '', 'appr' => $get($r, 'appr'),
                  'doc' => $get($r, 'doc'), 'total' => lg_to_int($get($r, 'total')), 'supply' => lg_to_int($get($r, 'supply')),
                  'tax' => lg_to_int($get($r, 'tax')), 'item' => $get($r, 'item'), 'note' => $get($r, 'note'), 'receipt' => $get($r, 'receipt'),
                  'p_no' => $side === 'b' ? $b_no : $s_no, 'p_name' => $get($r, $side . '_name'), 'p_ceo' => $get($r, $side . '_ceo'),
                  'p_addr' => $get($r, $side . '_addr'), 'p_email' => $get($r, $side . '_email')];
    }
    return $out;
}

/** 계산서 파일들([경로, 이름])을 가져와 거래처 등록·자동 정산까지 */
function lg_import_invoice_paths(array $pairs) {
    $st = ['added' => 0, 'dup' => 0, 'partners' => 0, 'files' => 0, 'errors' => []];
    $now = date('Y-m-d H:i:s');
    foreach ($pairs as [$path, $fname]) {
        try {
            $items = lg_read_invoice_file($path);
        } catch (Throwable $e) {
            $st['errors'][] = "$fname: " . $e->getMessage();
            continue;
        }
        $st['files']++;
        $seen = [];
        foreach ($items as $it) {
            $pid = null;
            if ($it['p_no'] !== '') {
                $p = lg_row("SELECT * FROM bl_ledger_partners WHERE biz_no=?", [$it['p_no']]);
                if (!$p) {
                    lg_exec("INSERT INTO bl_ledger_partners(biz_no, name, ceo, address, email, created_at) VALUES (?,?,?,?,?,?)",
                        [$it['p_no'], $it['p_name'], $it['p_ceo'], $it['p_addr'], $it['p_email'], $now]);
                    $st['partners']++;
                    $p = lg_row("SELECT * FROM bl_ledger_partners WHERE biz_no=?", [$it['p_no']]);
                } else {   // 비어 있는 칸만 채움 (직접 고친 정보는 그대로)
                    lg_exec("UPDATE bl_ledger_partners SET ceo=CASE WHEN ceo='' THEN ? ELSE ceo END,
                             address=CASE WHEN address='' THEN ? ELSE address END,
                             email=CASE WHEN email='' THEN ? ELSE email END WHERE id=?", [$it['p_ceo'], $it['p_addr'], $it['p_email'], $p['id']]);
                }
                $pid = (int)$p['id'];
            }
            $base = "{$it['kind']}|{$it['appr']}|{$it['total']}";
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = "$base|{$seen[$base]}";
            if (lg_val("SELECT 1 FROM bl_ledger_invoices WHERE dedup_key=?", [$key])) { $st['dup']++; continue; }
            lg_exec("INSERT INTO bl_ledger_invoices(kind, doc_type, approval_no, write_date, issue_date, partner_id,
                     partner_name, total, supply, tax, item, note, receipt, source_file, dedup_key, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$it['kind'], $it['doc'], $it['appr'], $it['write'], $it['issue'], $pid, $it['p_name'], $it['total'], $it['supply'],
                 $it['tax'], $it['item'], $it['note'], $it['receipt'], $fname, $key, $now]);
            $st['added']++;
        }
    }
    return $st + lg_auto_settle();
}

/* ================================================================ 자동 정산 */

function lg_invoice_paid($inv_id) {
    return (int)lg_val("SELECT COALESCE(SUM(amount),0) FROM bl_ledger_invoice_links WHERE invoice_id=?", [$inv_id]);
}

function lg_tx_amount(array $t, $kind) {
    return (int)($kind === '매출' ? $t['amount_in'] : $t['amount_out']);
}

/** 연결 한 줄 + 미분류 거래면 계정과목도 붙임: 매출 입금 → 매출, 매입 출금 → 품목 내용으로 추정 */
function lg_link(array $inv, array $t, $amount, $method) {
    lg_exec("INSERT INTO bl_ledger_invoice_links(invoice_id, tx_id, amount, method, created_at) VALUES (?,?,?,?,?)",
        [$inv['id'], $t['id'], $amount, $method, date('Y-m-d H:i:s')]);
    if ($t['category'] !== '') return;
    $cat = '';
    if ($inv['kind'] === '매출') {
        $cat = preg_match('/소프트웨어|라이선스|라이센스|솔루션/u', (string)$inv['item']) ? '매출(소프트웨어)' : '매출(용역·유지보수)';
    } else {
        $text = str_replace(' ', '', (string)$inv['item']);
        foreach (LG_DETAIL_WORDS as [$words, $c, $d]) {
            if ($d !== '출금') continue;
            foreach ($words as $w) if (str_contains($text, str_replace(' ', '', $w))) { $cat = $c; break 2; }
        }
    }
    if ($cat !== '') lg_exec("UPDATE bl_ledger_tx SET category=? WHERE id=? AND category='' AND " . LG_LOCK_SQL, [$cat, $t['id']]);
}

function lg_open_invoices() {
    return lg_all("SELECT i.*, COALESCE((SELECT SUM(amount) FROM bl_ledger_invoice_links l WHERE l.invoice_id=i.id),0) AS paid
                   FROM bl_ledger_invoices i WHERE closed='' AND total>0 ORDER BY write_date, id");
}

/** 미정산 세금계산서를 통장·카드 거래와 자동으로 짝짓는다 */
function lg_auto_settle() {
    $stats = ['pair' => 0, 'single' => 0, 'amount_only' => 0, 'combo' => 0, 'split' => 0, 'card' => 0];
    $partners = [];
    foreach (lg_all("SELECT * FROM bl_ledger_partners ORDER BY id") as $p) $partners[(int)$p['id']] = $p;
    $pdo = lg_db();
    $pdo->beginTransaction();
    try {
        // ① 수정세금계산서: 같은 거래처의 +금액과 -금액이 같으면 서로 지움
        foreach (lg_all("SELECT * FROM bl_ledger_invoices WHERE total<0 AND closed='' ORDER BY id") as $n) {
            $cands = lg_all("SELECT * FROM bl_ledger_invoices WHERE partner_id=? AND kind=? AND total=? AND closed='' AND id<>? ORDER BY id",
                [$n['partner_id'], $n['kind'], -(int)$n['total'], $n['id']]);
            $pos = null;
            foreach ($cands as $c) {
                if (!$pos || abs(lg_days($c['write_date'], $n['write_date'])) < abs(lg_days($pos['write_date'], $n['write_date']))) $pos = $c;
            }
            if ($pos && !lg_invoice_paid($pos['id'])) {
                lg_exec("UPDATE bl_ledger_invoices SET closed='수정세금계산서로 취소' WHERE id IN (?,?)", [$n['id'], $pos['id']]);
                $stats['pair']++;
            }
        }
        $acc_kind = [];
        foreach (lg_all("SELECT id, kind FROM bl_ledger_accounts") as $a) $acc_kind[(int)$a['id']] = $a['kind'];
        $txs = lg_all("SELECT * FROM bl_ledger_tx WHERE split_of IS NULL AND linked_card_id IS NULL
                       AND category NOT IN (?, ?, '계좌 간 이체') ORDER BY id", [LG_CARD_PAY, LG_CANCELLED]);
        $used = [];
        foreach (lg_all("SELECT tx_id, SUM(amount) AS s FROM bl_ledger_invoice_links GROUP BY tx_id") as $r) $used[(int)$r['tx_id']] = (int)$r['s'];
        // 화살표 함수(fn)는 $used 를 만들 때 값으로 복사해 버려서, 짝지을 때마다 바뀌는 값을 보려면 참조로 받아야 한다
        $free = function ($t, $kind) use (&$used) { return lg_tx_amount($t, $kind) - ($used[(int)$t['id']] ?? 0); };
        $do_link = function ($inv, $t, $amt, $method) use (&$used) {
            lg_link($inv, $t, $amt, $method);
            $used[(int)$t['id']] = ($used[(int)$t['id']] ?? 0) + $amt;
        };
        $others = function ($t, $pid) use ($partners) {
            foreach ($partners as $op) if ((int)$op['id'] !== (int)$pid && lg_partner_match_content($op, $t)) return true;
            return false;
        };

        // ② 한 건 = 한 거래 (같은 금액, 이름이 비슷하고 날짜가 가까운 것)
        foreach (lg_open_invoices() as $inv) {
            $need = (int)$inv['total'] - (int)$inv['paid'];
            if ($need <= 0) continue;
            $p = $partners[(int)$inv['partner_id']] ?? null;
            [$lo, $hi] = $inv['kind'] === '매출' ? [-45, 200] : [-60, 150];
            $cands = [];
            foreach ($txs as $t) {
                $k = $acc_kind[(int)$t['account_id']] ?? null;
                if ($inv['kind'] === '매출' && ($k !== '계좌' || (int)$t['amount_in'] <= 0)) continue;
                if ($inv['kind'] === '매입' && (int)$t['amount_out'] <= 0) continue;
                if ($free($t, $inv['kind']) !== $need) continue;
                $dd = lg_days($t['tx_date'], $inv['write_date']);
                if ($dd < $lo || $dd > $hi) continue;
                $score = $p ? lg_partner_match($p, $t) : lg_name_match($inv['partner_name'], $t['content']);
                $cands[] = [$score, $dd, $t, $k];
            }
            $cands = array_values(array_filter($cands, fn($c) => lg_hint_rank($inv, $c[2]) < 2));   // 다른 달 표시가 있는 거래는 제외
            $named = array_values(array_filter($cands, fn($c) => $c[0] > 0));
            $same_day = array_values(array_filter($cands, fn($c) => $c[0] == 0 && abs($c[1]) <= 3 && $c[3] === '계좌' && !$others($c[2], $inv['partner_id'])));
            $pick = null;
            $method = '';
            if ($named) {
                $key = fn($c) => [lg_hint_rank($inv, $c[2]), $c[1] >= -7 ? 0 : 1, abs($c[1])];
                $pick = $named[0];
                foreach ($named as $c) if ($key($c) < $key($pick)) $pick = $c;
                $method = '자동(이름·금액)';
                if (abs($pick[1]) > 20 && count($same_day) === 1) [$pick, $method] = [$same_day[0], '자동(금액·날짜) 확인 필요'];   // 이름 맞는 건 멀고, 같은 날 같은 금액이 있으면 그쪽
            } else {
                // 이름이 안 맞을 때는 통장 거래만, 그리고 그 거래가 다른 거래처 이름과 맞지 않을 때만
                $near = array_values(array_filter($cands, fn($c) => $c[1] >= -7 && $c[1] <= 60 && $c[3] === '계좌' && !$others($c[2], $inv['partner_id'])));
                usort($near, fn($a, $b) => abs($a[1]) <=> abs($b[1]));
                // 하나뿐이거나, 가장 가까운 것이 20일 안이고 다음 후보보다 확실히 가까우면 (매달 같은 금액 대비)
                if (count($near) === 1 || ($near && abs($near[0][1]) <= 20 && abs($near[1][1]) - abs($near[0][1]) >= 10)) [$pick, $method] = [$near[0], '자동(금액·날짜) 확인 필요'];
            }
            if ($pick) {
                $do_link($inv, $pick[2], $need, $method);
                $stats[$pick[3] === '카드' ? 'card' : ($pick[0] ? 'single' : 'amount_only')]++;
            }
        }
        // ③ 여러 건을 한 번에 입금·출금 (같은 거래처 계산서 2~4건 합계 = 거래 금액)
        $open_by_partner = [];
        foreach (lg_open_invoices() as $inv) {
            if ((int)$inv['total'] - (int)$inv['paid'] > 0) $open_by_partner[(string)$inv['partner_id']][] = $inv;
        }
        foreach ($txs as $t) {
            if (($acc_kind[(int)$t['account_id']] ?? null) !== '계좌') continue;
            $kind = (int)$t['amount_in'] > 0 ? '매출' : '매입';
            $amt = $free($t, $kind);
            if ($amt <= 0) continue;
            foreach ($open_by_partner as $pid => &$invs) {
                $p = $partners[(int)$pid] ?? null;
                if ($pid === '' || !$p || !lg_partner_match($p, $t)) continue;
                $pool = array_slice(array_values(array_filter($invs, fn($i) => $i['kind'] === $kind && (int)$i['total'] - (int)$i['paid'] > 0
                    && lg_days($t['tx_date'], $i['write_date']) >= -200 && lg_days($t['tx_date'], $i['write_date']) <= 45)), 0, 12);
                $hit = null;
                $hs = lg_month_set(lg_tx_text($t));
                foreach ([2, 3, 4] as $n) {
                    $found = [];
                    foreach (lg_combinations($pool, $n) as $combo) {
                        if (array_sum(array_map(fn($i) => (int)$i['total'] - (int)$i['paid'], $combo)) === $amt) $found[] = $combo;
                    }
                    if (!$found) continue;
                    // 'N,M월분' 표시가 있으면 그 달의 계산서 조합을 먼저
                    $hits = fn($cb) => count(array_filter($cb, fn($i) => (bool)array_intersect_key($hs, lg_inv_months($i))));
                    usort($found, fn($a, $b) => $hits($b) <=> $hits($a));
                    if ($hs && !$hits($found[0])) continue;
                    $hit = $found[0];
                    break;
                }
                if ($hit) {
                    foreach ($hit as $i) $do_link($i, $t, (int)$i['total'] - (int)$i['paid'], '자동(여러 건 합산)');
                    $ids = array_column($hit, 'id');
                    $invs = array_values(array_filter($invs, fn($i) => !in_array($i['id'], $ids, true)));
                    $stats['combo']++;
                    break;
                }
            }
            unset($invs);
        }
        // ③-2 이름 없이 같은 날 발행된 계산서 2~3장을 합쳐 낸 경우 (예: 입주자부담금 과세 + 면세)
        $groups = [];
        foreach (lg_open_invoices() as $inv) {
            if ((int)$inv['total'] - (int)$inv['paid'] > 0 && $inv['partner_id']) $groups[$inv['kind'] . '|' . $inv['partner_id'] . '|' . $inv['write_date']][] = $inv;
        }
        foreach ($groups as $gk => $invs) {
            if (count($invs) < 2) continue;
            [$kind, $pid, $wd] = explode('|', $gk);
            for ($n = count($invs); $n > 1; $n--) {
                $done = false;
                foreach (lg_combinations($invs, $n) as $combo) {
                    $need = array_sum(array_map(fn($i) => (int)$i['total'] - (int)$i['paid'], $combo));
                    $hits = [];
                    foreach ($txs as $t) {
                        if (($acc_kind[(int)$t['account_id']] ?? null) === '계좌' && $free($t, $kind) === $need
                            && lg_days($t['tx_date'], $wd) >= -7 && lg_days($t['tx_date'], $wd) <= 120 && !$others($t, $pid)) $hits[] = $t;
                    }
                    if (count($hits) === 1) {
                        foreach ($combo as $i) $do_link($i, $hits[0], (int)$i['total'] - (int)$i['paid'], '자동(같은 날 계산서 합산) 확인 필요');
                        $stats['combo']++;
                        $done = true;
                        break;
                    }
                }
                if ($done) break;
            }
        }
        // ④ 한 건을 두 번에 나눠 입금·출금
        foreach (lg_open_invoices() as $inv) {
            $need = (int)$inv['total'] - (int)$inv['paid'];
            $p = $partners[(int)$inv['partner_id']] ?? null;
            if ($need <= 0 || !$p) continue;
            $cands = array_values(array_filter($txs, fn($t) => ($acc_kind[(int)$t['account_id']] ?? null) === '계좌' && lg_tx_amount($t, $inv['kind']) > 0
                && $free($t, $inv['kind']) > 0 && $free($t, $inv['kind']) < $need
                && lg_days($t['tx_date'], $inv['write_date']) >= -30 && lg_days($t['tx_date'], $inv['write_date']) <= 200 && lg_partner_match($p, $t)));
            foreach (lg_combinations($cands, 2) as [$a, $b]) {
                if ($free($a, $inv['kind']) + $free($b, $inv['kind']) === $need) {
                    foreach ([$a, $b] as $t) $do_link($inv, $t, $free($t, $inv['kind']), '자동(나눠서 입금)');
                    $stats['split']++;
                    break;
                }
            }
        }
        // ⑤ 충전금(캐시·비즈머니)으로 쓰는 거래처: 충전 결제에서 계산서 금액을 차례로 차감
        $stats['prepaid'] = 0;
        foreach ($partners as $pid => $p) {
            $charges = array_values(array_filter(lg_prepaid_charges($p, [], true), fn($c) => $c['is_prepaid'] && $c['remain'] > 0));
            if (!$charges) continue;
            $invs = array_values(array_filter(lg_open_invoices(), fn($i) => (int)$i['partner_id'] === $pid && $i['kind'] === '매입' && (int)$i['total'] - (int)$i['paid'] > 0));
            if ($invs) $stats['prepaid'] += lg_allocate_prepaid($invs, $charges)[0];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $stats;
}

/** 이 거래처에 미리 넣어둔 충전금 결제(출금·카드) 목록과 남은 금액 */
function lg_prepaid_charges(array $partner, array $include_ids = [], $words_only = false) {
    static $rows = null;
    static $stamp = null;
    // 같은 요청 안에서 연결이 바뀌면 다시 읽는다
    $now = (int)lg_val("SELECT COUNT(*) + COALESCE(SUM(amount),0) FROM bl_ledger_invoice_links");
    if ($rows === null || $stamp !== $now) {
        $rows = lg_all("SELECT t.*, a.name AS acc_name,
                COALESCE((SELECT SUM(amount) FROM bl_ledger_invoice_links l WHERE l.tx_id=t.id),0) AS used
                FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
                WHERE t.amount_out>0 AND t.split_of IS NULL AND t.linked_card_id IS NULL ORDER BY t.tx_date, t.id");
        $stamp = $now;
    }
    $linked = array_flip(array_map('intval', array_column(lg_all("SELECT DISTINCT l.tx_id FROM bl_ledger_invoice_links l
        JOIN bl_ledger_invoices i ON i.id=l.invoice_id WHERE l.method LIKE '충전금%' AND i.partner_id=?", [$partner['id']]), 'tx_id')));
    $inc = array_flip(array_map('intval', $include_ids));
    $out = [];
    foreach ($rows as $t) {
        $id = (int)$t['id'];
        $is_word = (bool)preg_match(LG_PREPAID_RE, lg_tx_text($t));
        if ($words_only && !$is_word && !isset($inc[$id]) && !(int)$t['used']) continue;
        $lp = isset($linked[$id]);
        if (isset($inc[$id]) || $lp || ($is_word && lg_partner_match($partner, $t)) || lg_partner_match_content($partner, $t)) {
            $out[] = $t + ['remain' => (int)$t['amount_out'] - (int)$t['used'], 'is_prepaid' => $is_word || $lp];
        }
    }
    return $out;
}

/** 계산서(작성일 순)를 충전금(충전일 순)에서 차례로 차감. 충전일이 계산서보다 너무 늦은 충전금은 쓰지 않음.
 *  돌려주는 값: [다 차감된 계산서 수, [[계산서, 모자란 금액], ...]] */
function lg_allocate_prepaid(array $invs, array $charges, $method = '충전금 차감') {
    $linked = 0;
    $short = [];
    usort($charges, fn($a, $b) => strcmp($a['tx_date'], $b['tx_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
    usort($invs, fn($a, $b) => strcmp($a['write_date'], $b['write_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
    $now = date('Y-m-d H:i:s');
    foreach ($invs as $inv) {
        $need = (int)$inv['total'] - lg_invoice_paid($inv['id']);
        $limit = lg_add_days($inv['write_date'], 31);
        foreach ($charges as &$c) {
            if ($need <= 0) break;
            if ($c['remain'] <= 0 || $c['tx_date'] > $limit) continue;
            $amt = min($need, $c['remain']);
            lg_exec("INSERT INTO bl_ledger_invoice_links(invoice_id, tx_id, amount, method, created_at) VALUES (?,?,?,?,?)", [$inv['id'], $c['id'], $amt, $method, $now]);
            $c['remain'] -= $amt;
            $need -= $amt;
            if ($c['category'] === '') {
                lg_exec("UPDATE bl_ledger_tx SET category='지급수수료' WHERE id=? AND category=''", [$c['id']]);
                $c['category'] = '지급수수료';
            }
        }
        unset($c);
        if ($need <= 0) {
            $linked++;
            if ($inv['closed']) lg_exec("UPDATE bl_ledger_invoices SET closed='' WHERE id=?", [$inv['id']]);
        } else {
            $short[] = [$inv, $need];
        }
    }
    return [$linked, $short];
}

/* ================================================================ 화면: 증빙·정산 */

function lg_page_settle(array $q, array $me) {
    $kind = in_array(lg_q($q, 'kind'), ['매출', '매입'], true) ? lg_q($q, 'kind') : '';
    $status = lg_q($q, 'status');
    $start = lg_qdate($q, 'start', '2000-01-01');
    $end = lg_qdate($q, 'end', lg_today());
    $pid = preg_match('/^\d+$/', lg_q($q, 'partner_id')) ? lg_q($q, 'partner_id') : '';
    $where = ['write_date BETWEEN ? AND ?'];
    $params = [$start, $end];
    if ($kind !== '') { $where[] = 'kind=?'; $params[] = $kind; }
    if ($pid !== '') { $where[] = 'partner_id=?'; $params[] = (int)$pid; }
    $withStatus = fn($rows) => array_map(fn($r) => $r + ['status' => lg_inv_status($r)], $rows);
    $rows = $withStatus(lg_invoice_rows(implode(' AND ', $where), $params));
    if ($status !== '') $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $status));
    $every = $withStatus(lg_invoice_rows());
    $total = fn($k, $sts) => array_sum(array_map(fn($r) => (int)$r['total'] - (int)$r['paid'], array_filter($every, fn($r) => $r['kind'] === $k && in_array($r['status'], $sts, true))));
    $summary = ['ar' => $total('매출', ['미수', '일부 정산']), 'ap' => $total('매입', ['미지급', '일부 정산']), 'n' => count($every),
                'done' => count(array_filter($every, fn($r) => $r['status'] === '정산 완료')),
                'check' => count(array_filter($every, fn($r) => $r['status'] === '확인 필요'))];
    $by = [];
    foreach ($every as $r) {
        if (!in_array($r['status'], ['미수', '미지급', '일부 정산'], true)) continue;
        $k = json_encode([$r['kind'], $r['partner_id'] === null ? null : (int)$r['partner_id'], $r['partner_name']], JSON_UNESCAPED_UNICODE);
        $by[$k] ??= ['kind' => $r['kind'], 'partner_id' => $r['partner_id'], 'partner_name' => $r['partner_name'], 'n' => 0, 'remain' => 0, 'oldest' => $r['write_date']];
        $by[$k]['n']++;
        $by[$k]['remain'] += (int)$r['total'] - (int)$r['paid'];
        $by[$k]['oldest'] = min($by[$k]['oldest'], $r['write_date']);
    }
    foreach ($by as &$v) {
        $v['prepaid'] = null;
        if ($v['kind'] === '매입' && $v['partner_id']) {
            $p = lg_row("SELECT * FROM bl_ledger_partners WHERE id=?", [$v['partner_id']]);
            $ch = array_filter(lg_prepaid_charges($p, [], true), fn($c) => $c['is_prepaid']);
            if ($ch) $v['prepaid'] = array_sum(array_column($ch, 'remain'));
        }
    }
    unset($v);
    $side = function ($kind) use ($by) {
        $l = array_values(array_filter($by, fn($v) => $v['kind'] === $kind));
        usort($l, fn($a, $b) => $b['remain'] <=> $a['remain']);
        return $l;
    };
    return ['rows' => array_slice($rows, 0, 300), 'n_rows' => count($rows), 'summary' => $summary, 'ar' => $side('매출'), 'ap' => $side('매입'),
            'kind' => $kind, 'status' => $status, 'start' => $start !== '2000-01-01' ? $start : '', 'end' => $end, 'pid' => $pid,
            'partners' => lg_all("SELECT id, name FROM bl_ledger_partners ORDER BY name")];
}

function lg_post_settle(array $p, array $me) {
    $back = lg_url('settle');
    if (($p['mode'] ?? '') === 'reset') {
        $n = lg_exec("DELETE FROM bl_ledger_invoice_links WHERE method LIKE '자동(%' AND method<>'자동(확인함)'");
        lg_auto_settle();
        lg_log($me, '자동 연결 다시 하기', "자동 연결 {$n}건 지우고 다시 짝지음");
        return [[['ok', "자동 연결 {$n}건을 지우고 새 규칙으로 다시 짝지었어요. (직접 연결·확인 완료·충전금 차감·완료 처리한 건은 그대로예요)"]], $back];
    }
    $st = lg_auto_settle();
    $n = $st['single'] + $st['amount_only'] + $st['card'] + $st['combo'] + $st['split'];
    lg_log($me, '빈 곳 자동 정산', "{$n}건 연결");
    return [[['ok', "자동 정산을 다시 돌려 {$n}건을 더 연결했어요."]], $back];
}

/** 연결된 통장 적요가 거래처 이름과 다르면 거래처의 '다른 이름'으로 기억 */
function lg_learn_aliases(array $inv) {
    if (!$inv['partner_id']) return;
    $p = lg_row("SELECT * FROM bl_ledger_partners WHERE id=?", [$inv['partner_id']]);
    $al = array_values(array_filter(explode(',', (string)$p['aliases']), fn($a) => lg_strip($a) !== ''));
    foreach (lg_all("SELECT t.* FROM bl_ledger_invoice_links l JOIN bl_ledger_tx t ON t.id=l.tx_id
                     JOIN bl_ledger_accounts a ON a.id=t.account_id WHERE l.invoice_id=? AND a.kind='계좌' ORDER BY l.id", [$inv['id']]) as $t) {
        $generic = preg_match('/\d+월|인터넷|당행|타행|이체|입금|출금|송금|급여|식대|이자/u', (string)$t['content']);
        if (!$generic && !lg_name_match($p['name'], $t['content']) && !in_array($t['content'], $al, true)) $al[] = $t['content'];   // '인터넷당행', '2월식대' 같은 흔한 적요는 기억하지 않음
    }
    lg_exec("UPDATE bl_ledger_partners SET aliases=? WHERE id=?", [implode(',', $al), $p['id']]);
}

function lg_post_settle_bulk(array $p, array $me) {
    $ids = array_map('intval', (array)($p['inv_id'] ?? []));
    $act = $p['do'] ?? '';
    foreach ($ids as $iid) {
        $inv = lg_row("SELECT * FROM bl_ledger_invoices WHERE id=?", [$iid]);
        if (!$inv) continue;
        if ($act === 'confirm') {
            lg_exec("UPDATE bl_ledger_invoice_links SET method='자동(확인함)' WHERE invoice_id=? AND method LIKE '%확인 필요%'", [$iid]);
            lg_learn_aliases($inv);
        } elseif ($act === 'close') {
            lg_exec("UPDATE bl_ledger_invoices SET closed=? WHERE id=? AND closed=''", [lg_strip($p['reason'] ?? '') ?: '직접 완료 처리', $iid]);
        }
    }
    lg_log($me, '세금계산서 한꺼번에', ($act === 'confirm' ? '확인 완료 ' : '정산 완료 ') . count($ids) . '건');
    return [[['ok', count($ids) . '건을 ' . ($act === 'confirm' ? '확인 완료로 표시' : '정산 완료로 처리') . '했어요.']], lg_safe_back($p['back'] ?? '') ?: lg_url('settle')];
}

/* ---------------------------------------------------------------- 세금계산서 가져오기 */

function lg_page_invoice_import(array $q, array $me) {
    return [];
}

function lg_post_invoice_import(array $p, array $me) {
    $pairs = [];
    foreach (lg_upload_list('files') as $f) {
        if (($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
        $token = lg_upload_save($f);
        if ($token) $pairs[] = [lg_upload_path($token), clip($f['name'], 200)];
    }
    if (!$pairs) return [[['error', '파일을 선택해 주세요.']], lg_url('invoice_import')];
    try {
        $st = lg_import_invoice_paths($pairs);
    } finally {
        foreach ($pairs as [$path]) @unlink($path);
    }
    lg_log($me, '세금계산서 가져오기', count($pairs) . "개 파일, 새 계산서 {$st['added']}건, 새 거래처 {$st['partners']}곳");
    $_SESSION['bl_ledger_result'] = ['kind' => 'invoice'] + $st;
    return [[], lg_url('invoice_done')];
}

/* ---------------------------------------------------------------- 세금계산서 하나 */

function lg_page_invoice(array $q, array $me) {
    $iid = (int)($q['iid'] ?? 0);
    $inv = lg_row("SELECT * FROM bl_ledger_invoices WHERE id=?", [$iid]);
    if (!$inv) return ['__redirect' => lg_url('settle')];
    $paid = lg_invoice_paid($iid);
    $links = lg_all("SELECT l.*, t.tx_date, t.content, t.amount_in, t.amount_out, a.name AS acc_name
                     FROM bl_ledger_invoice_links l JOIN bl_ledger_tx t ON t.id=l.tx_id JOIN bl_ledger_accounts a ON a.id=t.account_id
                     WHERE l.invoice_id=? ORDER BY t.tx_date, l.id", [$iid]);
    $partner = $inv['partner_id'] ? lg_row("SELECT * FROM bl_ledger_partners WHERE id=?", [$inv['partner_id']]) : null;
    // 후보: 같은 방향의 거래 중 아직 남은 금액이 있는 것. 이름이 비슷하거나 금액이 맞는 것부터
    $qq = lg_strip($q['q'] ?? '');
    $col = $inv['kind'] === '매출' ? 'amount_in' : 'amount_out';
    $rows = lg_all("SELECT t.*, a.name AS acc_name, a.kind AS acc_kind,
            COALESCE((SELECT SUM(amount) FROM bl_ledger_invoice_links l WHERE l.tx_id=t.id),0) AS used
            FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
            WHERE t.$col>0 AND t.split_of IS NULL AND t.linked_card_id IS NULL AND t.tx_date BETWEEN ? AND ? ORDER BY t.id",
        [lg_add_days($inv['write_date'], -90), lg_add_days($inv['write_date'], 240)]);
    $remain = (int)$inv['total'] - $paid;
    $cands = [];
    foreach ($rows as $t) {
        $free_amt = (int)$t[$col] - (int)$t['used'];
        if ($free_amt <= 0) continue;
        $score = $partner ? lg_partner_match($partner, $t) : lg_name_match($inv['partner_name'], $t['content']);
        if ($qq !== '' && !str_contains(str_replace(' ', '', lg_tx_text($t)), str_replace(' ', '', $qq)) && str_replace(',', '', $qq) !== (string)(int)$t[$col]) continue;
        if ($qq === '' && !$score && $free_amt !== $remain) continue;
        $cands[] = $t + ['free' => $free_amt, 'score' => $score, 'same' => $free_amt === $remain];
    }
    $key = fn($c) => [!$c['same'], -$c['score'], abs(lg_days($c['tx_date'], $inv['write_date']))];
    usort($cands, fn($a, $b) => $key($a) <=> $key($b));
    return ['__heading' => $inv['kind'] . ' ' . ($inv['doc_type'] ?: '세금계산서') . ' · ' . $inv['partner_name'],
            'inv' => $inv, 'paid' => $paid, 'remain' => $remain, 'links' => $links, 'partner' => $partner, 'cands' => array_slice($cands, 0, 40),
            'q' => $qq, 'back' => lg_safe_back($q['back'] ?? ''),
            'status' => lg_inv_status($inv + ['paid' => $paid, 'methods' => implode(',', array_column($links, 'method'))])];
}

function lg_post_invoice(array $p, array $me) {
    $iid = (int)($_GET['iid'] ?? 0);
    $inv = lg_row("SELECT * FROM bl_ledger_invoices WHERE id=?", [$iid]);
    if (!$inv) return [[], lg_url('settle')];
    $back = lg_url('invoice', ['iid' => $iid, 'q' => $_GET['q'] ?? '', 'back' => lg_safe_back($p['back'] ?? ($_GET['back'] ?? ''))]);
    $act = $p['do'] ?? '';
    $what = "{$inv['kind']} {$inv['write_date']} {$inv['partner_name']} " . lg_won($inv['total']);
    if ($act === 'link') {
        $t = lg_row("SELECT * FROM bl_ledger_tx WHERE id=?", [(int)($p['tx_id'] ?? 0)]);
        $amt = lg_to_int($p['amount'] ?? '');
        if (!$t || $amt <= 0) return [[], $back];
        lg_link($inv, $t, $amt, '직접 연결');
        if ($inv['partner_id'] && !empty($p['learn'])) {   // 통장 이름을 거래처 별칭으로 기억
            $pt = lg_row("SELECT * FROM bl_ledger_partners WHERE id=?", [$inv['partner_id']]);
            if ($pt && !lg_partner_match($pt, $t)) {
                $al = array_values(array_filter(explode(',', (string)$pt['aliases']), fn($a) => lg_strip($a) !== ''));
                $al[] = $t['content'];
                lg_exec("UPDATE bl_ledger_partners SET aliases=? WHERE id=?", [implode(',', array_unique($al)), $pt['id']]);
            }
        }
        lg_log($me, '세금계산서 직접 연결', "$what ← 거래 {$t['id']} {$amt}");
        return [[['ok', '연결했어요.']], $back];
    }
    if ($act === 'unlink') {
        $l = lg_row("SELECT * FROM bl_ledger_invoice_links WHERE id=? AND invoice_id=?", [(int)($p['link_id'] ?? 0), $iid]);
        lg_exec("DELETE FROM bl_ledger_invoice_links WHERE id=? AND invoice_id=?", [(int)($p['link_id'] ?? 0), $iid]);
        lg_log($me, '세금계산서 연결 풀기', $what, $l);
        return [[['ok', '연결을 풀었어요.']], $back];
    }
    if ($act === 'confirm') {
        lg_exec("UPDATE bl_ledger_invoice_links SET method='자동(확인함)' WHERE invoice_id=? AND method LIKE '%확인 필요%'", [$iid]);
        lg_learn_aliases($inv);
        lg_log($me, '세금계산서 확인 완료', $what);
        return [[['ok', '확인 완료로 표시했어요. 통장에 찍힌 이름을 이 거래처의 다른 이름으로 기억할게요.']], $back];
    }
    if ($act === 'close') {
        lg_exec("UPDATE bl_ledger_invoices SET closed=? WHERE id=?", [lg_strip($p['reason'] ?? '') ?: '직접 완료 처리', $iid]);
        lg_log($me, '세금계산서 정산 완료 처리', "$what (" . ($p['reason'] ?? '') . ')');
        return [[['ok', '정산 완료로 처리했어요.']], $back];
    }
    if ($act === 'reopen') {
        lg_exec("UPDATE bl_ledger_invoices SET closed='' WHERE id=?", [$iid]);
        lg_log($me, '세금계산서 미정산으로', $what);
        return [[['ok', '다시 미정산으로 돌렸어요.']], $back];
    }
    return [[], $back];
}

/* ---------------------------------------------------------------- 충전금으로 정산 */

function lg_page_prepaid(array $q, array $me) {
    $pid = (int)($q['pid'] ?? 0);
    $p = lg_row("SELECT * FROM bl_ledger_partners WHERE id=?", [$pid]);
    if (!$p) return ['__redirect' => lg_url('settle')];
    $charges = lg_prepaid_charges($p);
    $invs = lg_all("SELECT i.*, COALESCE((SELECT SUM(amount) FROM bl_ledger_invoice_links l WHERE l.invoice_id=i.id),0) AS paid
                    FROM bl_ledger_invoices i WHERE partner_id=? AND kind='매입' AND total>0 ORDER BY write_date, id", [$pid]);
    $open = array_values(array_filter($invs, fn($i) => (int)$i['total'] - (int)$i['paid'] > 0 && (!$i['closed'] || str_contains($i['closed'], '충전'))));
    $done = array_values(array_filter($invs, fn($i) => (int)$i['paid'] > 0 && lg_val("SELECT 1 FROM bl_ledger_invoice_links WHERE invoice_id=? AND method LIKE '충전금%'", [$i['id']])));
    return ['__heading' => '충전금으로 정산 · ' . $p['name'], 'p' => $p, 'charges' => $charges, 'open_invs' => $open, 'done_invs' => $done,
            'balance' => array_sum(array_map(fn($c) => $c['is_prepaid'] ? $c['remain'] : 0, $charges)), 'back' => lg_safe_back($q['back'] ?? '')];
}

function lg_post_prepaid(array $p, array $me) {
    $pid = (int)($_GET['pid'] ?? 0);
    $pt = lg_row("SELECT * FROM bl_ledger_partners WHERE id=?", [$pid]);
    if (!$pt) return [[], lg_url('settle')];
    $back = lg_url('prepaid', ['pid' => $pid, 'back' => lg_safe_back($p['back'] ?? '')]);
    if (($p['do'] ?? '') === 'unlink_all') {
        $n = lg_exec("DELETE FROM bl_ledger_invoice_links WHERE method LIKE '충전금%' AND invoice_id IN (SELECT id FROM bl_ledger_invoices WHERE partner_id=?)", [$pid]);
        lg_log($me, '충전금 차감 모두 풀기', "{$pt['name']} {$n}건");
        return [[['ok', '이 거래처의 충전금 차감을 모두 풀었어요.']], $back];
    }
    $charge_ids = array_map('intval', (array)($p['charge_id'] ?? []));
    $inv_ids = array_map('intval', (array)($p['inv_id'] ?? []));
    $charges = array_values(array_filter(lg_prepaid_charges($pt, $charge_ids), fn($c) => in_array((int)$c['id'], $charge_ids, true) && $c['remain'] > 0));
    $invs = $inv_ids ? lg_all("SELECT * FROM bl_ledger_invoices WHERE id IN (" . lg_ph($inv_ids) . ") ORDER BY id", $inv_ids) : [];
    if (!$charges || !$invs) return [[['error', '충전 결제와 계산서를 하나 이상씩 골라주세요.']], $back];
    $pdo = lg_db();
    $pdo->beginTransaction();
    try {
        [$n, $short] = lg_allocate_prepaid($invs, $charges);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    lg_log($me, '충전금 차감', "{$pt['name']} 계산서 {$n}건");
    $msg = "계산서 {$n}건을 충전금에서 차감했어요.";
    if ($short) $msg .= ' ' . count($short) . '건은 충전금이 모자라거나 충전일이 늦어서 다 차감하지 못했어요(부족 ' . lg_won(array_sum(array_column($short, 1))) . '원).';
    return [[[$short ? 'error' : 'ok', $msg]], $back];
}

/* ---------------------------------------------------------------- 거래처 */

function lg_page_partners(array $q, array $me) {
    $qq = lg_strip($q['q'] ?? '');
    $rows = lg_all("SELECT p.*,
          (SELECT COUNT(*) FROM bl_ledger_invoices i WHERE i.partner_id=p.id AND i.kind='매출') AS n_sale,
          (SELECT COALESCE(SUM(total),0) FROM bl_ledger_invoices i WHERE i.partner_id=p.id AND i.kind='매출') AS sale,
          (SELECT COUNT(*) FROM bl_ledger_invoices i WHERE i.partner_id=p.id AND i.kind='매입') AS n_buy,
          (SELECT COALESCE(SUM(total),0) FROM bl_ledger_invoices i WHERE i.partner_id=p.id AND i.kind='매입') AS buy,
          (SELECT MAX(write_date) FROM bl_ledger_invoices i WHERE i.partner_id=p.id) AS last
          FROM bl_ledger_partners p ORDER BY p.id");
    usort($rows, fn($a, $b) => ((int)$b['sale'] + (int)$b['buy']) <=> ((int)$a['sale'] + (int)$a['buy']));
    if ($qq !== '') {
        $needle = str_replace([' ', '-'], '', $qq);
        $rows = array_values(array_filter($rows, fn($r) => str_contains(str_replace([' ', '-'], '', $r['name'] . $r['biz_no'] . $r['aliases'] . $r['manager']), $needle)));
    }
    // 매출처 / 매입처 / 매출·매입 으로 나누고, 그 안에서는 이름순
    $order = ['매출처' => 0, '매입처' => 1, '매출·매입' => 2];
    foreach ($rows as &$r) $r['group'] = ((int)$r['n_sale'] && (int)$r['n_buy']) ? '매출·매입' : ((int)$r['n_sale'] ? '매출처' : '매입처');
    unset($r);
    $counts = [];
    foreach ($order as $g => $_) $counts[$g] = count(array_filter($rows, fn($r) => $r['group'] === $g));
    $sel = isset($order[lg_q($q, 'group')]) ? lg_q($q, 'group') : '';
    if ($sel !== '') $rows = array_values(array_filter($rows, fn($r) => $r['group'] === $sel));
    usort($rows, fn($a, $b) => ($order[$a['group']] <=> $order[$b['group']]) ?: strcmp(lg_norm_party($a['name']), lg_norm_party($b['name'])));
    return ['rows' => $rows, 'q' => $qq, 'sel' => $sel, 'counts' => $counts, 'back' => lg_safe_back($q['back'] ?? '')];
}

function lg_post_partners(array $p, array $me) {
    $n = 0;
    foreach ((array)($p['pid'] ?? []) as $pid) {
        $pid = (int)$pid;
        $vals = array_map(fn($k) => lg_strip($p["{$k}_$pid"] ?? ''), ['aliases', 'manager', 'phone', 'email', 'memo']);
        $old = lg_row("SELECT aliases, manager, phone, email, memo FROM bl_ledger_partners WHERE id=?", [$pid]);
        if ($old && array_values($old) !== $vals) {
            lg_exec("UPDATE bl_ledger_partners SET aliases=?, manager=?, phone=?, email=?, memo=? WHERE id=?", array_merge($vals, [$pid]));
            $n++;
        }
    }
    if ($n) lg_log($me, '거래처 정보', "{$n}곳 저장");
    return [[['ok', $n ? "거래처 {$n}곳의 정보를 저장했어요." : '바뀐 내용이 없어요.']],
            lg_url('partners', ['q' => $_GET['q'] ?? '', 'group' => $_GET['group'] ?? '', 'back' => lg_safe_back($p['back'] ?? '')])];
}

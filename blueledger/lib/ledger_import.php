<?php
/**
 * BlueLedger 회계 장부 — ③ 거래 가져오기.
 *
 *  파이썬 버전의 import_upload·import_map·do_import·do_import_card_statement·ledger_import·
 *  duplicates·imports·inbox 를 옮겼다. 같은 파일을 넣으면 파이썬과 같은 거래가 같은 열쇠(dedup_key)로 들어간다.
 *  달라진 점:
 *   - '가져올 파일 폴더' 대신 화면에서 여러 파일을 한 번에 올린다(서버엔 내 PC 폴더가 없다).
 *   - 올린 파일은 blueledger/var/ledger_uploads 에 잠깐 두고, 가져오면 지운다(1시간 지난 것도 지운다).
 *   - 지우기 전 '장부 파일 백업' 대신 지운 줄의 원래 내용을 작업 기록에 남긴다(lg_delete_txs).
 */

require_once __DIR__ . '/ledger_pages.php';

const LG_HEADER_WORDS = ['일자', '일시', '날짜', '거래일', '이용일', '승인일', '적요', '내용', '입금', '출금', '금액', '가맹점'];
const LG_IMPORT_COLS = ['date', 'content', 'in', 'out', 'card', 'appr', 'note1', 'note2', 'note3', 'bal',
                        'cancel_amt', 'cancel_date', 'due', 'buy_date', 'usage_type'];

/** 파이썬 str.strip() — 유니코드 공백(전각 공백·nbsp 포함)까지 앞뒤를 자른다 */
function lg_strip($s) {
    return preg_replace('/^[\s\x{1c}-\x{1f}\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+|[\s\x{1c}-\x{1f}\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+$/u', '', (string)$s);
}

/** `str(x or "").strip()` */
function lg_cell_text($v) {
    return lg_falsy($v) ? '' : lg_strip(lg_str($v));
}

/** 제목 줄 글자들 — `str(c).strip() if c is not None else ""` */
function lg_headers_at(array $rows, $i) {
    return array_map(fn($c) => $c === null ? '' : lg_strip(lg_str($c)), $rows[$i] ?? []);
}

function lg_guess_header_row(array $rows) {
    foreach (array_slice($rows, 0, 30) as $i => $r) {
        $cells = [];
        foreach ($r as $c) if ($c !== null && $c !== '') $cells[] = lg_strip(lg_str($c));
        $hits = 0;
        foreach ($cells as $c) {
            foreach (LG_HEADER_WORDS as $w) { if (str_contains($c, $w)) { $hits++; break; } }
        }
        if (count($cells) >= 3 && $hits >= 2) return $i;
    }
    return 0;
}

/** 열 이름으로 어느 열이 무엇인지 짐작. 값은 열 번호(0부터) 또는 '' */
function lg_guess_columns(array $headers) {
    $find = function (array $words, array $exclude = []) use ($headers) {
        foreach ($headers as $i => $h) {
            $hit = false;
            foreach ($words as $w) if (str_contains($h, $w)) { $hit = true; break; }
            if (!$hit) continue;
            foreach ($exclude as $x) if (str_contains($h, $x)) continue 2;
            return $i;
        }
        return '';
    };
    $g = [
        'date' => $find(['거래일', '일자', '일시', '날짜', '이용일', '승인일']),
        'content' => $find(['적요', '내용', '가맹점', '기재', '거래처', '메모']),
        'in' => $find(['입금', '맡기신']),
        'out' => $find(['출금', '찾으신', '이용금액', '승인금액', '금액'], ['입금', '잔액']),
        'card' => $find(['카드번호', '카드 번호', '카드No', '이용카드']),
        'bal' => $find(['거래후잔액', '거래후 잔액', '거래 후 잔액', '잔액']),
    ];
    $n = 0;
    foreach ($headers as $j => $h) {
        foreach (['메모', '기록사항', '예금주'] as $w) {
            if (str_contains($h, $w)) { if ($n < 3) $g['note' . (++$n)] = $j; break; }
        }
    }
    return $g + [
        'appr' => $find(['승인번호']),
        'cancel_amt' => $find(['취소금액']),
        'cancel_date' => $find(['취소일자', '취소일']),
        'due' => $find(['결제예정일', '결제일자']),
        'buy_date' => $find(['매출표접수일', '매입일']),
        'usage_type' => $find(['이용구분']),
    ];
}

/** 열 설정에서 칸 하나 (`cell(r, k)`) */
function lg_col_cell(array $r, array $cols, $k) {
    $i = $cols[$k] ?? '';
    if ($i === '' || $i === null) return null;
    $i = (int)$i;
    return $i < count($r) ? $r[$i] : null;
}

function lg_tx_exists($key) {
    return (bool)lg_val("SELECT 1 FROM bl_ledger_tx WHERE dedup_key=?", [$key]);
}

/** 통장(또는 금액 열 하나짜리 카드) 거래 넣기 */
function lg_do_import(array $rows, $hdr, array $cols, $sign_mode, $aid, $fname, $manual_card_no = '') {
    $added = $dup = $skipped = $tagged = $noted = $locked = $merged = 0;
    $seen = [];
    $seen_old = [];
    $locked_months = array_flip(lg_closed_months());
    $manual = lg_last4($manual_card_no);
    $now = date('Y-m-d H:i:s');
    $pdo = lg_db();
    $pdo->beginTransaction();
    try {
        foreach (array_slice($rows, $hdr + 1) as $r) {
            $d = lg_to_date(lg_col_cell($r, $cols, 'date'));
            if (!$d) { $skipped++; continue; }                         // 합계 줄, 빈 줄 등
            if (isset($locked_months[substr($d, 0, 7)])) { $locked++; continue; }   // 마감한 달
            $content = lg_cell_text(lg_col_cell($r, $cols, 'content'));
            $a_in = lg_to_int(lg_col_cell($r, $cols, 'in'));
            $a_out = lg_to_int(lg_col_cell($r, $cols, 'out'));
            if ($sign_mode === 'card' && $a_out < 0) { $a_in = -$a_out; $a_out = 0; }   // 카드: 음수(취소)는 입금으로
            if ($a_in === 0 && $a_out === 0) { $skipped++; continue; }
            $card_no = lg_last4(lg_col_cell($r, $cols, 'card')) ?: $manual;
            $notes = [];
            foreach (['note1', 'note2', 'note3'] as $k) {
                $raw = lg_col_cell($r, $cols, $k);
                if (lg_cell_text($raw) !== '') $notes[lg_strip(lg_str($raw))] = true;
            }
            $note = implode(' / ', array_keys($notes));
            $bal = (($cols['bal'] ?? '') !== '' && lg_cell_text(lg_col_cell($r, $cols, 'bal')) !== '') ? lg_to_int(lg_col_cell($r, $cols, 'bal')) : null;
            $base_old = "$aid|$d|$content|$a_in|$a_out";
            $seen_old[$base_old] = ($seen_old[$base_old] ?? 0) + 1;
            $base = $card_no !== '' ? "$base_old|#$card_no" : $base_old;
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = "$base|{$seen[$base]}";
            if ($card_no !== '') {
                // 예전에 카드번호 없이 넣은 같은 거래가 있으면 새로 넣지 않고 카드번호만 붙임
                $old = lg_val("SELECT id FROM bl_ledger_tx WHERE dedup_key=? AND card_no=''", ["$base_old|{$seen_old[$base_old]}"]);
                if ($old) {
                    lg_exec("UPDATE bl_ledger_tx SET card_no=? WHERE id=?", [$card_no, $old]);
                    $tagged++;
                    continue;
                }
            }
            $man = lg_val("SELECT id FROM bl_ledger_tx WHERE account_id=? AND tx_date=? AND amount_in=? AND amount_out=?
                           AND source_file=? AND dedup_key LIKE 'MAN|%' ORDER BY id LIMIT 1", [$aid, $d, $a_in, $a_out, LG_MANUAL]);
            if ($man && !lg_tx_exists($key)) {
                // 적요는 통장에 찍힌 그대로, 내가 적은 내용·계정과목은 유지
                lg_exec("UPDATE bl_ledger_tx SET dedup_key=?, source_file=?, content=?, note=CASE WHEN note='' THEN ? ELSE note END,
                         detail=CASE WHEN detail='' THEN content ELSE detail END,
                         bank_balance=COALESCE(?, bank_balance) WHERE id=?", [$key, $fname, $content, $note, $bal, $man]);
                $merged++;
                continue;
            }
            if (!lg_tx_exists($key)) {
                lg_exec("INSERT INTO bl_ledger_tx(account_id, tx_date, content, amount_in, amount_out, source_file, dedup_key, card_no, note, bank_balance, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$aid, $d, $content, $a_in, $a_out, $fname, $key, $card_no, $note, $bal, $now]);
                $added++;
            } else {
                $dup++;
                if ($bal !== null) lg_exec("UPDATE bl_ledger_tx SET bank_balance=? WHERE dedup_key=? AND bank_balance IS NULL", [$bal, $key]);
                if ($note !== '' && lg_exec("UPDATE bl_ledger_tx SET note=? WHERE dedup_key=? AND note=''", [$note, $key])) $noted++;   // 이미 있던 거래에 메모를 채움
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $linked = lg_link_card_payments();
    $ruled = lg_apply_rules();
    return compact('added', 'dup', 'skipped', 'linked', 'ruled', 'tagged', 'noted', 'locked', 'merged');
}

/** 카드사 '승인내역/이용내역' 파일 (승인구분·취소금액·결제예정일자 열이 있는 것).
 *  - 승인 1건 = 사용 1줄 (결제예정일 기록)
 *  - 취소금액이 있으면 취소 1줄을 따로 (전액·매입 전 취소는 '결제 취소(상계)')
 *  - 해외 이용은 해외이용수수료(추정) 1줄 추가 (승인금액 × 수수료율, 원 단위 버림) */
function lg_do_import_card_statement(array $rows, $hdr, array $cols, $aid, $fname, $fee_rate, $manual_card_no = '') {
    $fr = trim(str_replace('%', '', (string)$fee_rate));
    $rate = $fr === '' ? 0.0 : (is_numeric($fr) ? (float)$fr / 100 : 0.0118);
    $group = lg_all("SELECT id, name FROM bl_ledger_accounts WHERE id IN (" . lg_ph(lg_card_group_ids($aid)) . ") ORDER BY id", lg_card_group_ids($aid));
    // 묶인 카드들: 카드번호 끝 4자리가 이름에 들어 있는 카드로 나눠 넣기
    $route = function ($no) use ($group, $aid) {
        foreach ($group as $g) {
            if ($no !== '' && str_ends_with(preg_replace('/\D/', '', $g['name']), $no)) return (int)$g['id'];
        }
        return (int)$aid;
    };
    $st = ['added' => 0, 'dup' => 0, 'skipped' => 0, 'updated' => 0, 'cancels' => 0, 'fees' => 0, 'locked' => 0];
    $locked_months = array_flip(lg_closed_months());
    $seen = [];
    $seen_old = [];
    $now = date('Y-m-d H:i:s');
    $put = function ($acc, $d, $content, $a_in, $a_out, $key, $card_no, $due, $category = '') use (&$st, $fname, $now) {
        $old = lg_row("SELECT id, bill_due FROM bl_ledger_tx WHERE dedup_key=?", [$key]);
        if ($old) {
            if ($due && !$old['bill_due']) {
                lg_exec("UPDATE bl_ledger_tx SET bill_due=? WHERE id=?", [$due, $old['id']]);
                $st['updated']++;
            } else {
                $st['dup']++;
            }
            return false;
        }
        lg_exec("INSERT INTO bl_ledger_tx(account_id, tx_date, content, amount_in, amount_out, source_file,
                 dedup_key, card_no, bill_due, category, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$acc, $d, $content, $a_in, $a_out, $fname, $key, $card_no, $due, $category, $now]);
        return true;
    };
    $pdo = lg_db();
    $pdo->beginTransaction();
    try {
        foreach (array_slice($rows, $hdr + 1) as $r) {
            $d = lg_to_date(lg_col_cell($r, $cols, 'date'));
            $amt = lg_to_int(lg_col_cell($r, $cols, 'out'));
            if (!$d || $amt <= 0) { $st['skipped']++; continue; }
            if (isset($locked_months[substr($d, 0, 7)])) { $st['locked']++; continue; }
            $content = lg_cell_text(lg_col_cell($r, $cols, 'content'));
            $card_no = lg_last4(lg_col_cell($r, $cols, 'card')) ?: lg_last4($manual_card_no);
            $acc = $route($card_no);
            $due = lg_to_date(lg_col_cell($r, $cols, 'due')) ?: '';
            $c_amt = lg_to_int(lg_col_cell($r, $cols, 'cancel_amt'));
            $c_date = lg_to_date(lg_col_cell($r, $cols, 'cancel_date')) ?: $d;
            $appr = lg_cell_text(lg_col_cell($r, $cols, 'appr'));
            $full_cancel_before_buy = $c_amt >= $amt && !$due;
            $base = "$acc|S|$d|$appr|$amt";
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = "$base|{$seen[$base]}";
            // 예전 방식(승인번호 없이)으로 들어간 같은 거래가 있으면 그 줄을 이어서 씀
            $base_old = "$acc|$d|$content|0|$amt";
            $seen_old[$base_old] = ($seen_old[$base_old] ?? 0) + 1;
            $old = lg_val("SELECT id FROM bl_ledger_tx WHERE dedup_key=?", ["$base_old|{$seen_old[$base_old]}"]);
            $man = ($old || lg_tx_exists($key)) ? null : lg_val(
                "SELECT id FROM bl_ledger_tx WHERE account_id=? AND tx_date=? AND amount_out=? AND source_file=?
                 AND dedup_key LIKE 'MAN|%' ORDER BY id LIMIT 1", [$acc, $d, $amt, LG_MANUAL]);
            if ($man) {     // 출납장에 미리 적어둔 카드 사용 → 명세서 내용으로 채우고 내가 적은 내용·계정과목은 유지
                lg_exec("UPDATE bl_ledger_tx SET dedup_key=?, bill_due=?, card_no=?, source_file=?,
                         detail=CASE WHEN detail='' THEN content ELSE detail END, content=? WHERE id=?",
                    [$key, $due, $card_no, $fname, $content, $man]);
                $st['merged'] = ($st['merged'] ?? 0) + 1;
            } elseif ($old) {
                lg_exec("UPDATE bl_ledger_tx SET dedup_key=?, bill_due=?, card_no=? WHERE id=?", [$key, $due, $card_no, $old]);
                $st['updated']++;
            } elseif ($put($acc, $d, $content, 0, $amt, $key, $card_no, $due, $full_cancel_before_buy ? LG_CANCELLED : '')) {
                $st['added']++;
            }
            if ($full_cancel_before_buy) lg_exec("UPDATE bl_ledger_tx SET category=? WHERE dedup_key=? AND category=''", [LG_CANCELLED, $key]);
            if ($c_amt > 0) {
                $c_due = !$due ? '' : ($c_date < $due ? $due : lg_due_after($c_date));
                if ($put($acc, $c_date, "$content (취소)", $c_amt, 0, "$key|C", $card_no, $c_due, $c_amt >= $amt ? LG_CANCELLED : '')) $st['cancels']++;
            }
            if ($due && str_contains(lg_falsy($u = lg_col_cell($r, $cols, 'usage_type')) ? '' : lg_str($u), '해외') && $rate > 0) {
                $fee = (int)floor($amt * $rate);
                $buy = lg_to_date(lg_col_cell($r, $cols, 'buy_date')) ?: $d;
                if ($fee && $put($acc, $buy, "해외이용수수료(추정) · $content", 0, $fee, "$key|F", $card_no, $due, '지급수수료')) $st['fees']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $st['linked'] = lg_link_card_payments();
    $st['ruled'] = lg_apply_rules();
    $st['tagged'] = 0;
    return $st;
}

/* ================================================================ 올린 파일 잠깐 두기 */

function lg_upload_dir() {
    $d = __DIR__ . '/../var/ledger_uploads';
    if (!is_dir($d)) @mkdir($d, 0770, true);
    // 1시간 지난 파일 정리
    foreach (glob($d . '/*') ?: [] as $f) { if (is_file($f) && time() - filemtime($f) > 3600) @unlink($f); }
    return $d;
}

/** 올린 파일 하나 저장 → 표(token). 엑셀·CSV 만 받는다 */
function lg_upload_save(array $f) {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return null;
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) return null;
    $token = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], lg_upload_dir() . '/' . $token)) return null;
    return $token;
}

function lg_upload_path($token) {
    return preg_match('/^[0-9a-f]{32}\.(xlsx|xls|csv)$/', (string)$token) ? lg_upload_dir() . '/' . $token : null;
}

/** 여러 파일이 한 칸(files[])으로 오면 [이름, tmp_name, error] 목록으로 */
function lg_upload_list($field) {
    $f = $_FILES[$field] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    return $out;
}

/* ================================================================ 화면: 파일 올리기 → 열 맞추기 → 결과 */

function lg_page_import(array $q, array $me) {
    return ['accounts' => lg_all("SELECT * FROM bl_ledger_accounts ORDER BY kind, id")];
}

function lg_post_import(array $p, array $me) {
    $back = lg_url('import');
    if (!lg_val("SELECT 1 FROM bl_ledger_accounts")) return [[['error', '먼저 기준정보에서 계좌나 카드를 등록해 주세요.']], lg_url('accounts')];
    $files = lg_upload_list('file');
    if (!$files || ($files[0]['error'] ?? 4) === UPLOAD_ERR_NO_FILE) return [[['error', '파일을 선택해 주세요.']], $back];
    $token = lg_upload_save($files[0]);
    if (!$token) return [[['error', '파일을 올리지 못했어요. xlsx, xls, csv 파일인지 확인해 주세요.']], $back];
    return [[], lg_url('import_map', ['token' => $token, 'account_id' => (int)($p['account_id'] ?? 0), 'name' => clip($files[0]['name'], 200)])];
}

/** 열 맞추기 화면에 필요한 값 — 파일·계좌가 없으면 null 과 알림 */
function lg_import_map_ctx(array $q) {
    $token = (string)($q['token'] ?? '');
    $path = lg_upload_path($token);
    $aid = (int)($q['account_id'] ?? 0);
    $fname = clip($q['name'] ?? $token, 200);
    $acc = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$aid]);
    if (!$path || !is_file($path) || !$acc) return [null, '파일을 다시 올려주세요.'];
    try {
        $rows = lg_read_table($path);
    } catch (LgReadError $e) {
        return [null, $e->getMessage()];
    }
    if (!$rows) return [null, '파일 안에 내용이 없어요. 은행에서 다시 내려받아 주세요.'];
    $saved = $acc['mapping'] ? (json_decode($acc['mapping'], true) ?: []) : [];
    if (lg_q($q, 'header_row') !== '') {
        $hdr = (int)$q['header_row'] - 1;                           // 사용자가 직접 지정
    } else {
        $hdr = lg_guess_header_row($rows);
        $sh = (int)($saved['header_row'] ?? 0) - 1;                 // 지난번과 같은 양식이면 그 설정 사용
        if ($sh >= 0 && $sh < count($rows) && lg_headers_at($rows, $sh) === ($saved['headers'] ?? null)) $hdr = $sh;
    }
    $hdr = max(0, min($hdr, count($rows) - 1));
    $headers = lg_headers_at($rows, $hdr);
    $guess = lg_guess_columns($headers);
    if ($saved && ($saved['headers'] ?? null) === $headers) {
        foreach ($saved['cols'] ?? [] as $k => $v) if ($v !== '') $guess[$k] = $v;
    }
    return [compact('token', 'path', 'aid', 'fname', 'acc', 'rows', 'saved', 'hdr', 'headers', 'guess'), null];
}

function lg_page_import_map(array $q, array $me) {
    [$c, $err] = lg_import_map_ctx($q);
    if (!$c) return ['error' => $err];
    return ['acc' => $c['acc'], 'token' => $c['token'], 'fname' => $c['fname'], 'headers' => $c['headers'],
            'header_row' => $c['hdr'] + 1, 'preview' => array_slice($c['rows'], $c['hdr'] + 1, 7), 'guess' => $c['guess'],
            'total_rows' => max(0, count($c['rows']) - $c['hdr'] - 1), 'is_card' => $c['acc']['kind'] === '카드',
            'fee_rate' => $c['saved']['fee_rate'] ?? '1.18'];
}

function lg_post_import_map(array $p, array $me) {
    [$c, $err] = lg_import_map_ctx($p);
    if (!$c) return [[['error', $err]], lg_url('import')];
    $back = lg_url('import_map', ['token' => $c['token'], 'account_id' => $c['aid'], 'name' => $c['fname'], 'header_row' => $c['hdr'] + 1]);
    $cols = [];
    foreach (LG_IMPORT_COLS as $k) $cols[$k] = (string)($p[$k] ?? '');
    $sign_mode = $p['sign_mode'] ?? 'split';
    if ($cols['date'] === '' || ($cols['out'] === '' && $cols['in'] === '')) {
        return [[['error', '날짜와 금액(입금 또는 출금) 열은 꼭 골라주세요.']], $back];
    }
    $fee_rate = (string)($p['fee_rate'] ?? '1.18');
    if ($c['acc']['kind'] === '카드' && $cols['due'] !== '') {
        $result = lg_do_import_card_statement($c['rows'], $c['hdr'], $cols, $c['aid'], $c['fname'], $fee_rate, $p['manual_card_no'] ?? '');
    } else {
        $result = lg_do_import($c['rows'], $c['hdr'], $cols, $sign_mode, $c['aid'], $c['fname'], $p['manual_card_no'] ?? '');
    }
    lg_exec("UPDATE bl_ledger_accounts SET mapping=? WHERE id=?",
        [json_encode(['header_row' => $c['hdr'] + 1, 'headers' => $c['headers'], 'cols' => $cols, 'fee_rate' => $p['fee_rate'] ?? '1.18'], JSON_UNESCAPED_UNICODE), $c['aid']]);
    @unlink($c['path']);
    lg_log($me, '거래 가져오기', "{$c['fname']} → {$c['acc']['name']}: 새 거래 {$result['added']}건, 이미 있음 {$result['dup']}건");
    $_SESSION['bl_ledger_result'] = ['kind' => 'import', 'acc' => $c['acc'], 'fname' => $c['fname']] + $result;
    return [[], lg_url('import_done')];
}

function lg_page_import_done(array $q, array $me) {
    return $_SESSION['bl_ledger_result'] ?? [];
}

/* ================================================================ 일일출납장 가져오기 */

/** 일일출납장: 월별 시트마다 왼쪽(통장)과 오른쪽(법인카드) 표를 읽는다 */
function lg_read_ledger_book($path) {
    $raw = @file_get_contents($path);
    if ($raw === false || !str_starts_with($raw, 'PK')) throw new LgReadError('xlsx 가 아니에요.');
    $bank = [];
    $card = [];
    foreach (lg_xlsx_book($raw) as [$title, $rows]) {
        $hi = null;
        foreach (array_slice($rows, 0, 10) as $i => $r) {
            if ($r && str_replace(' ', '', lg_falsy($r[0] ?? null) ? '' : lg_str($r[0])) === '일자') { $hi = $i; break; }
        }
        if ($hi === null) continue;
        $H = array_map(fn($x) => str_replace([' ', "\n"], '', lg_falsy($x) ? '' : lg_str($x)), $rows[$hi]);
        $ci = [];
        foreach ($H as $i => $h) if ($h !== '') $ci[$h] = $i;
        foreach (array_slice($rows, $hi + 1) as $r) {
            $r = array_pad($r, count($H), null);
            $d = (!is_string($r[0]) || ($r[0] !== '' && ctype_digit($r[0][0]))) ? lg_to_date($r[0]) : null;
            if ($d && (lg_to_int($r[2]) || lg_to_int($r[3]))) {
                $bank[] = ['sheet' => $title, 'date' => $d, 'text' => lg_cell_text($r[1]), 'in' => lg_to_int($r[2]), 'out' => lg_to_int($r[3])];
            }
            if (isset($ci['카드번호'], $ci['사용금액'])) {
                $cd = lg_to_date($r[$ci['사용일자'] ?? $ci['카드번호'] + 1] ?? null);
                $amt = lg_to_int($r[$ci['사용금액']]);
                if ($cd && $amt) {
                    $card[] = ['sheet' => $title, 'date' => $cd, 'card' => lg_last4($r[$ci['카드번호']]), 'amount' => $amt,
                               'shop' => isset($ci['가맹점명']) ? lg_cell_text($r[$ci['가맹점명']]) : '',
                               'text' => isset($ci['사용내용']) ? lg_cell_text($r[$ci['사용내용']]) : ''];
                }
            }
        }
    }
    return [$bank, $card];
}

function lg_page_ledger_import(array $q, array $me) {
    [$banks, $leads] = lg_daybook_groups();
    return ['banks' => $banks, 'leads' => $leads];
}

function lg_post_ledger_import(array $p, array $me) {
    $back = lg_url('ledger_import');
    $files = lg_upload_list('file');
    $token = $files ? lg_upload_save($files[0]) : null;
    if (!$token) return [[['error', '출납장 파일을 선택해 주세요.']], $back];
    $path = lg_upload_path($token);
    try {
        [$bank_rows, $card_rows] = lg_read_ledger_book($path);
    } catch (Throwable $e) {
        return [[['error', '출납장 파일을 읽지 못했어요. xlsx 파일인지 확인해 주세요.']], $back];
    } finally {
        @unlink($path);
    }
    $result = lg_apply_ledger_book($bank_rows, $card_rows, $p);
    lg_log($me, '출납장 가져오기', "통장 {$result['m_bank']}/{$result['n_bank']}, 카드 {$result['m_card']}/{$result['n_card']}, 내용 붙임 {$result['touched']}건");
    $_SESSION['bl_ledger_result'] = ['kind' => 'ledger'] + $result;
    return [[], lg_url('ledger_done')];
}

/** 출납장 줄을 장부 거래에 맞춰 내용(detail)을 붙이고 자동 분류 */
function lg_apply_ledger_book(array $bank_rows, array $card_rows, array $p) {
    [$banks, $leads] = lg_daybook_groups();
    $bank_id = (int)(($p['bank_id'] ?? '') ?: ($banks[0]['id'] ?? 0));
    $lead_id = (int)(($p['lead_id'] ?? '') ?: ($leads[0]['id'] ?? 0));
    $touched = [];
    $miss_bank = [];
    $miss_card = [];
    // 통장: 같은 날짜 + 같은 금액, 여러 건이면 이름이 비슷한 것
    $pool = [];
    foreach (lg_all("SELECT id, tx_date, content, amount_in, amount_out FROM bl_ledger_tx WHERE account_id=? AND split_of IS NULL ORDER BY id", [$bank_id]) as $t) {
        $pool[$t['tx_date'] . '|' . (int)$t['amount_in'] . '|' . (int)$t['amount_out']][] = $t;
    }
    foreach ($bank_rows as $b) {
        $k = "{$b['date']}|{$b['in']}|{$b['out']}";
        if (empty($pool[$k])) { $miss_bank[] = $b; continue; }
        $nb = lg_norm_name($b['text']);
        $bi = 0;
        foreach ($pool[$k] as $i => $c) {
            $nc = lg_norm_name($c['content']);
            if ($nc !== '' && (str_starts_with($nb, mb_substr($nc, 0, 4)) || str_starts_with($nc, mb_substr($nb, 0, 4)))) { $bi = $i; break; }
        }
        $best = $pool[$k][$bi];
        array_splice($pool[$k], $bi, 1);
        if ($b['text'] !== '' && lg_norm_name($b['text']) !== lg_norm_name($best['content'])) {
            lg_exec("UPDATE bl_ledger_tx SET detail=? WHERE id=?", [$b['text'], $best['id']]);
            $touched[] = (int)$best['id'];
        }
    }
    // 카드: 같은 사용일 + 같은 금액, 카드번호가 맞는 카드 먼저
    $group = $lead_id ? lg_card_group_ids($lead_id) : [];
    $cpool = [];
    if ($group) {
        foreach (lg_all("SELECT t.id, t.account_id, t.tx_date, t.amount_out, t.content, a.name FROM bl_ledger_tx t
                JOIN bl_ledger_accounts a ON a.id=t.account_id WHERE t.account_id IN (" . lg_ph($group) . ") AND t.amount_out>0
                AND t.content NOT LIKE '" . LG_OVERSEAS_FEE . "%' ORDER BY t.id", $group) as $t) {
            $cpool[$t['tx_date'] . '|' . (int)$t['amount_out']][] = $t;
        }
    }
    foreach ($card_rows as $c) {
        $k = "{$c['date']}|{$c['amount']}";
        if (empty($cpool[$k])) { $miss_card[] = $c; continue; }
        $bi = 0;
        foreach ($cpool[$k] as $i => $x) {
            if ($c['card'] !== '' && str_ends_with(preg_replace('/\D/', '', $x['name']), $c['card'])) { $bi = $i; break; }
        }
        $best = $cpool[$k][$bi];
        array_splice($cpool[$k], $bi, 1);
        if ($c['text'] !== '') {
            lg_exec("UPDATE bl_ledger_tx SET detail=? WHERE id=?", [$c['text'], $best['id']]);
            $touched[] = (int)$best['id'];
        }
    }
    $auto = lg_apply_detail_words(array_unique($touched));
    $ruled = lg_apply_rules();
    $ids = array_column($auto, 0);
    $names = [];
    if ($ids) {
        foreach (lg_all("SELECT t.id, t.tx_date, t.content, t.detail, t.amount_in, t.amount_out, a.name AS acc FROM bl_ledger_tx t
                         JOIN bl_ledger_accounts a ON a.id=t.account_id WHERE t.id IN (" . lg_ph($ids) . ")", $ids) as $r) $names[(int)$r['id']] = $r;
    }
    $auto_rows = [];
    foreach ($auto as [$i, $cat]) if (isset($names[$i])) $auto_rows[] = $names[$i] + ['category' => $cat];
    $idx = array_keys($auto_rows);
    usort($idx, fn($a, $b) => strcmp($auto_rows[$a]['category'], $auto_rows[$b]['category']) ?: strcmp($auto_rows[$a]['tx_date'], $auto_rows[$b]['tx_date']) ?: ($a <=> $b));
    $auto_rows = array_map(fn($i) => $auto_rows[$i], $idx);
    return ['n_bank' => count($bank_rows), 'n_card' => count($card_rows),
            'm_bank' => count($bank_rows) - count($miss_bank), 'm_card' => count($card_rows) - count($miss_card),
            'touched' => count(array_unique($touched)), 'auto_rows' => $auto_rows, 'ruled' => $ruled,
            'miss_bank' => array_slice($miss_bank, 0, 60), 'miss_card' => array_slice($miss_card, 0, 60),
            'n_miss_bank' => count($miss_bank), 'n_miss_card' => count($miss_card)];
}

/* ================================================================ 중복 거래 찾기 */

function lg_page_duplicates(array $q, array $me) {
    $aid = preg_match('/^\d+$/', lg_q($q, 'account_id')) ? lg_q($q, 'account_id') : '';
    $sql = "SELECT t.*, a.name AS acc_name,
               (SELECT COUNT(*) FROM bl_ledger_invoice_links l WHERE l.tx_id=t.id) AS links,
               (SELECT COUNT(*) FROM bl_ledger_tx p WHERE p.split_of=t.id) AS parts
            FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
            WHERE t.split_of IS NULL AND t.content NOT LIKE '" . LG_OVERSEAS_FEE . "%' AND t.source_file<>?";
    $params = [LG_ADJUST];
    if ($aid !== '') { $sql .= " AND t.account_id=?"; $params[] = (int)$aid; }
    $groups = [];
    foreach (lg_all($sql . " ORDER BY t.tx_date, t.id", $params) as $t) {
        $groups[$t['account_id'] . '|' . $t['tx_date'] . '|' . (int)$t['amount_in'] . '|' . (int)$t['amount_out']][] = $t;
    }
    $locked = array_flip(lg_closed_months());
    $out = [];
    foreach ($groups as $rows) {
        if (count($rows) < 2) continue;
        $files = array_unique(array_column($rows, 'source_file'));
        $names = array_map(fn($r) => lg_norm_name($r['content']), $rows);
        $same = true;
        foreach ($names as $n) {
            if (!($n === $names[0] || ($n !== '' && $names[0] !== '' && (str_contains($names[0], $n) || str_contains($n, $names[0]))))) { $same = false; break; }
        }
        // 의심 정도: 다른 파일에서 같은 거래가 또 들어왔거나(가장 흔함), 이름까지 같은 경우
        $likely = $same && (count($files) > 1 || in_array(LG_MANUAL, array_column($rows, 'source_file'), true));
        // 남길 줄: 정보가 많은 줄 (세금계산서 연결·나눔·계정과목·출납장 내용·은행 잔액)
        $score = fn($r) => [(int)$r['links'] > 0, (int)$r['parts'] > 0, $r['category'] !== '', ($r['detail'] ?? '') !== '', $r['bank_balance'] !== null, -(int)$r['id']];
        $keep = $rows[0];
        foreach ($rows as $r) if ($score($r) > $score($keep)) $keep = $r;
        foreach ($rows as &$r) {
            $r['suggest_del'] = $likely && $r['id'] !== $keep['id'];
            $r['locked'] = isset($locked[substr($r['tx_date'], 0, 7)]);
        }
        unset($r);
        $out[] = ['date' => $rows[0]['tx_date'], 'amount' => (int)$rows[0]['amount_in'] ?: (int)$rows[0]['amount_out'],
                  'rows' => $rows, 'likely' => $likely, 'acc' => $rows[0]['acc_name']];
    }
    $idx = array_keys($out);
    usort($idx, fn($a, $b) => ((int)!$out[$a]['likely'] <=> (int)!$out[$b]['likely']) ?: strcmp($out[$a]['date'], $out[$b]['date']) ?: ($a <=> $b));
    $out = array_map(fn($i) => $out[$i], $idx);
    return ['groups' => $out, 'accounts' => lg_all("SELECT * FROM bl_ledger_accounts ORDER BY kind, id"), 'aid' => $aid,
            'n_likely' => count(array_filter($out, fn($g) => $g['likely']))];
}

function lg_post_duplicates(array $p, array $me) {
    $back = lg_url('duplicates', ['account_id' => $p['account_id'] ?? '']);
    $ids = array_map('intval', (array)($p['del_id'] ?? []));
    if (!$ids) return [[['error', '지울 거래를 골라주세요.']], $back];
    [$n, $skipped] = lg_delete_txs($ids, '중복 삭제', $me);
    if (!$n) return [[['error', '고른 거래가 모두 마감한 달이라 지우지 않았어요. 월마감에서 마감을 풀고 해주세요.']], $back];
    return [[['ok', "중복 거래 {$n}건을 지웠어요. 지운 거래의 원래 내용은 작업 기록에 남겨 뒀어요." . ($skipped ? " 마감한 달 {$skipped}건은 건너뛰었어요." : '')]], $back];
}

/* ================================================================ 가져온 파일별로 보기·지우기 */

function lg_page_imports(array $q, array $me) {
    $special = [LG_MANUAL, '거래 나누기', LG_ADJUST];
    $rows = lg_all("SELECT t.source_file, t.account_id, a.name AS acc_name, COUNT(*) AS n, MIN(t.tx_date) AS first,
            MAX(t.tx_date) AS last, MIN(t.created_at) AS at, SUM(t.amount_in) AS sin, SUM(t.amount_out) AS sout
            FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
            WHERE t.split_of IS NULL AND t.source_file NOT IN (" . lg_ph($special) . ") AND t.source_file<>''
            GROUP BY t.source_file, t.account_id, a.name ORDER BY at DESC", $special);
    return ['rows' => $rows];
}

function lg_post_imports(array $p, array $me) {
    $back = lg_url('imports');
    $sf = (string)($p['source_file'] ?? '');
    $ids = array_column(lg_all("SELECT id FROM bl_ledger_tx WHERE source_file=? AND account_id=? AND split_of IS NULL", [$sf, (int)($p['account_id'] ?? 0)]), 'id');
    [$n, $skipped] = $ids ? lg_delete_txs($ids, '파일 삭제', $me) : [0, 0];
    if (!$n) return [[['error', '이 파일의 거래가 모두 마감한 달이라 지우지 않았어요. 월마감에서 마감을 풀고 해주세요.']], $back];
    return [[['ok', "'{$sf}'에서 가져온 거래 {$n}건을 지웠어요. 지운 거래의 원래 내용은 작업 기록에 남겨 뒀어요." . ($skipped ? " 마감한 달 {$skipped}건은 건너뛰었어요." : '')]], $back];
}

/* ================================================================ 여러 파일 한 번에 (파이썬의 '가져올 파일 폴더') */

/** 파일 종류: bank(통장) / card(카드 승인내역) / invoice(홈택스 세금계산서) / payroll(급여대장·정산) / unknown */
function lg_detect_file($path, $name) {
    try {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx') {
            try {
                foreach (lg_xlsx_book(file_get_contents($path)) as [, $rows]) {
                    $t = lg_falsy($rows[0][0] ?? null) ? '' : lg_str($rows[0][0]);
                    if (str_contains($t, '급여대장') || str_contains($t, '정산') || str_contains($t, '상여')) return ['type' => 'payroll', 'label' => '급여대장·정산'];
                }
            } catch (Throwable $e) {
            }
        }
        $rows = lg_read_table($path);
    } catch (Throwable $e) {
        return ['type' => 'unknown', 'label' => '읽을 수 없음', 'error' => $e->getMessage()];
    }
    $parts = [];
    foreach (array_slice($rows, 0, 12) as $r) foreach ($r as $c) if ($c !== null && $c !== '') $parts[] = lg_str($c);
    $head = implode(' ', $parts);
    if (str_contains($head, '목록조회') && str_contains($head, '작성일자')) return ['type' => 'invoice', 'label' => '홈택스 세금계산서'];
    $hdr = lg_guess_header_row($rows);
    $headers = $rows ? lg_headers_at($rows, $hdr) : [];
    if (in_array('승인구분', $headers, true) || (in_array('결제예정일자', $headers, true) && in_array('카드번호', $headers, true))) {
        $lead = lg_row("SELECT * FROM bl_ledger_accounts WHERE kind='카드' AND pay_group IS NULL ORDER BY id");
        return ['type' => 'card', 'label' => '카드 승인내역', 'target' => $lead['id'] ?? null, 'rows' => $rows, 'hdr' => $hdr, 'headers' => $headers];
    }
    $hits = [];
    foreach (lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='계좌' AND COALESCE(mapping,'')<>'' ORDER BY id") as $a) {
        $m = json_decode($a['mapping'], true) ?: [];
        // 저장된 열 이름과 똑같은 줄을 앞쪽 20줄에서 찾음 (조회 기간에 따라 제목 줄 수가 달라져도 되도록)
        for ($sh = 0; $sh < min(20, count($rows)); $sh++) {
            if (lg_headers_at($rows, $sh) === ($m['headers'] ?? null)) { $hits[] = [$a, $sh, ['header_row' => $sh + 1] + $m]; break; }
        }
    }
    if ($hits) {
        return ['type' => 'bank', 'label' => '통장 거래내역', 'target' => (int)$hits[0][0]['id'], 'candidates' => array_map(fn($h) => (int)$h[0]['id'], $hits),
                'rows' => $rows, 'hdr' => $hits[0][1], 'map' => $hits[0][2]];
    }
    return ['type' => 'unknown', 'label' => '처음 보는 양식', 'error' => '거래 가져오기에서 한 번 열 맞추기를 해주세요. 그다음부터 자동으로 알아봐요.'];
}

function lg_post_inbox_upload(array $p, array $me) {
    $saved = $_SESSION['bl_ledger_inbox'] ?? [];
    $n = 0;
    foreach (lg_upload_list('files') as $f) {
        if (($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
        $token = lg_upload_save($f);
        if ($token) { $saved[] = ['token' => $token, 'name' => clip($f['name'], 200), 'size' => (int)$f['size'], 'time' => date('Y-m-d H:i:s')]; $n++; }
    }
    $_SESSION['bl_ledger_inbox'] = $saved;
    return [$n ? [] : [['error', '올릴 파일을 골라주세요. (xlsx, xls, csv)']], lg_url('inbox')];
}

function lg_inbox_files() {
    return array_values(array_filter($_SESSION['bl_ledger_inbox'] ?? [], fn($f) => ($p = lg_upload_path($f['token'])) && is_file($p)));
}

function lg_page_inbox(array $q, array $me) {
    $items = [];
    foreach (lg_inbox_files() as $f) {
        $info = lg_detect_file(lg_upload_path($f['token']), $f['name']);
        unset($info['rows']);
        $items[] = $f + $info;
    }
    return ['items' => $items, 'banks' => lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='계좌' ORDER BY id")];
}

function lg_post_inbox(array $p, array $me) {
    $files = lg_inbox_files();
    if (($p['do'] ?? '') === 'clear') {
        foreach ($files as $f) @unlink(lg_upload_path($f['token']));
        $_SESSION['bl_ledger_inbox'] = [];
        return [[['ok', '올려 둔 파일을 모두 치웠어요.']], lg_url('inbox')];
    }
    $report = [];
    $inv = [];
    $done = [];
    foreach ($files as $i => $f) {
        if (empty($p["use_$i"])) continue;
        $path = lg_upload_path($f['token']);
        $info = lg_detect_file($path, $f['name']);
        try {
            if ($info['type'] === 'bank') {
                $aid = (int)(($p["target_$i"] ?? '') ?: $info['target']);
                $acc = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$aid]);
                $m = $acc && $acc['mapping'] ? (json_decode($acc['mapping'], true) ?: []) : $info['map'];
                $rows = $info['rows'];
                $hdr = $info['hdr'];
                for ($k = 0; $k < min(20, count($rows)); $k++) { if (lg_headers_at($rows, $k) === ($m['headers'] ?? null)) { $hdr = $k; break; } }
                $cols = lg_guess_columns(lg_headers_at($rows, $hdr));
                foreach ($m['cols'] ?? [] as $k => $v) if ($v !== '') $cols[$k] = $v;
                $r = lg_do_import($rows, $hdr, $cols, 'split', $aid, $f['name'], '');
                $report[] = [$f['name'], "통장 → {$acc['name']}", "새 거래 {$r['added']}건 · 이미 있음 {$r['dup']}건"
                    . ($r['merged'] ? " · 출납장과 합침 {$r['merged']}건" : '') . ($r['locked'] ? " · 마감한 달이라 건너뜀 {$r['locked']}건" : ''), true];
                $done[] = $i;
            } elseif ($info['type'] === 'card') {
                $aid = $info['target'];
                $acc = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$aid]);
                $m = $acc && $acc['mapping'] ? (json_decode($acc['mapping'], true) ?: []) : [];
                $cols = lg_guess_columns($info['headers']);
                if (($m['headers'] ?? null) === $info['headers']) foreach ($m['cols'] ?? [] as $k => $v) if ($v !== '') $cols[$k] = $v;
                $r = lg_do_import_card_statement($info['rows'], $info['hdr'], $cols, $aid, $f['name'], $m['fee_rate'] ?? '1.18');
                $report[] = [$f['name'], '카드 승인내역', "새 사용 {$r['added']}건 · 결제예정일 보완 {$r['updated']}건 · 취소 {$r['cancels']}건"
                    . (!empty($r['merged']) ? " · 출납장과 합침 {$r['merged']}건" : ''), true];
                $done[] = $i;
            } elseif ($info['type'] === 'invoice') {
                $inv[$i] = [$path, $f['name']];
            } elseif ($info['type'] === 'payroll') {
                $report[] = [$f['name'], '급여대장', '급여대장은 아직 PHP 로 옮기지 않았어요. PC 의 파이썬 BlueLedger 에서 가져와 주세요.', false];
            } else {
                $report[] = [$f['name'], $info['label'], $info['error'] ?? '', false];
            }
        } catch (Throwable $e) {
            $report[] = [$f['name'], $info['label'], '문제가 생겼어요: ' . $e->getMessage(), false];
        }
    }
    if ($inv) {
        if (function_exists('lg_import_invoice_paths')) {
            $st = lg_import_invoice_paths(array_values($inv));
            $report[] = [implode(', ', array_column($inv, 1)), '홈택스 세금계산서',
                "새 계산서 {$st['added']}건 · 새 거래처 {$st['partners']}곳 · 자동 연결 " . ($st['single'] + $st['card'] + $st['combo'] + $st['split'] + ($st['prepaid'] ?? 0)) . '건',
                !$st['errors']];
            $done = array_merge($done, array_keys($inv));
        } else {
            foreach ($inv as [, $n]) $report[] = [$n, '홈택스 세금계산서', '증빙·정산은 아직 PHP 로 옮기는 중이에요.', false];
        }
    }
    if (!$report) return [[['error', '가져올 파일을 골라주세요.']], lg_url('inbox')];
    // 가져온 파일은 치운다
    $keep = [];
    foreach ($files as $i => $f) {
        if (in_array($i, $done, true)) @unlink(lg_upload_path($f['token']));
        else $keep[] = $f;
    }
    $_SESSION['bl_ledger_inbox'] = $keep;
    lg_log($me, '여러 파일 가져오기', count($report) . '개 파일');
    $_SESSION['bl_ledger_result'] = ['kind' => 'inbox', 'report' => $report];
    return [[], lg_url('inbox_done')];
}

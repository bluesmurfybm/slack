<?php
/**
 * BlueLedger 회계 장부 — 계산 로직.
 *
 *  파이썬 버전(app.py)의 함수를 같은 이름·같은 순서로 옮겼다(앞에 lg_ 를 붙임). 숫자가 파이썬과
 *  똑같이 나와야 하므로 계산 방식은 바꾸지 않았다. 바꾼 곳은 표 이름(bl_ledger_*)과,
 *  SQLite·MySQL 이 다르게 읽는 문법(글자 잇기)뿐이다.
 *  화면은 ledger.php, 엑셀은 lib/ledger_xlsx.php 가 맡는다.
 */

require_once __DIR__ . '/../guard.php';
require_once __DIR__ . '/ledger_read.php';

const LG_CARD_PAY  = '카드대금 결제';
const LG_CANCELLED = '결제 취소(상계)';     // 승인 후 전액 취소된 카드 결제: 승인·취소 두 줄이 서로 지워짐
const LG_GROUPS    = ['수익', '비용', '자금이동'];
const LG_WEEKDAYS  = ['월', '화', '수', '목', '금', '토', '일'];
const LG_ADJUST    = '맞춰보기 조정';
const LG_MANUAL    = '수기 입력';
const LG_OVERSEAS_FEE = '해외이용수수료';
const LG_LOCK_SQL  = "substr(tx_date,1,7) NOT IN (SELECT ym FROM bl_ledger_month_closes)";

// 출입 명단의 역할. 대표는 보기만 한다.
const LG_ROLE_CLERK = 'clerk';
const LG_ROLE_CEO   = 'ceo';
const LG_ROLE_LABEL = [LG_ROLE_CLERK => '담당자', LG_ROLE_CEO => '대표'];
const LG_SEED_ROLES = ['jian@bluesoft.co.kr' => LG_ROLE_CLERK, 'kimhy@bluesoft.co.kr' => LG_ROLE_CEO];

// 계정과목 기본 목록 — 파이썬 버전이 처음 만들 때 넣던 것 + 나중에 더한 것(비품·법인세·기부금·결제 취소)
// 세무사 사무실 계정과목표를 받으면 이 목록 대신 그 표로 바꿀 예정
const LG_DEFAULT_CATEGORIES = [
    '수익' => ['매출(용역·유지보수)', '매출(소프트웨어)', '이자수익', '잡이익'],
    '비용' => ['급여', '상여금', '퇴직급여', '복리후생비', '여비교통비', '기업업무추진비(접대비)', '통신비',
              '수도광열비', '세금과공과', '지급임차료', '수선비', '보험료', '차량유지비', '운반비',
              '교육훈련비', '도서인쇄비', '사무용품비', '소모품비', '비품', '지급수수료', '광고선전비',
              '외주용역비', '회의비', '이자비용', '잡손실', '법인세', '이전 카드 사용분', '기부금'],
    '자금이동' => [LG_CARD_PAY, LG_CANCELLED, '계좌 간 이체', '부가세 납부', '원천세·4대보험 납부', '가지급금',
                 '차입금 입금·상환', '투자자산 이체', '보증금'],
];
const LG_DEFAULT_RULES = [  // (적요에 들어간 글자, 계정과목, 방향)
    ['이자', '이자수익', '입금'], ['택시', '여비교통비', '출금'], ['코레일', '여비교통비', '출금'],
    ['주유', '차량유지비', '출금'], ['우체국', '운반비', '출금'], ['수수료', '지급수수료', '출금'],
    ['산재보험', '보험료', '출금'],
];

// 출납장 내용에 이 낱말이 있으면 이 계정과목 (위에 있을수록 먼저 봄 — 거래처·외부 손님이 직원보다 먼저)
const LG_DETAIL_WORDS = [
    [['외부손님', '외부 손님', '외부미팅', '거래처', '단체선물', '접대', '고객사'], '기업업무추진비(접대비)', '출금'],
    [['기부금', '기부'], '기부금', '출금'],
    [['법인세', '법인지방소득세', '법인 지방소득세', '중간예납'], '법인세', '출금'],
    [['갑근세', '근로소득세', '사업소득세', '사소세', '원천세'], '원천세·4대보험 납부', '출금'],
    [['부가세', '부가가치세'], '부가세 납부', '출금'],
    [['주민세', '면허세', '협의회비', '회비'], '세금과공과', '출금'],
    [['화재보험', '보증보험', '보험료', '보험증권'], '보험료', '출금'],
    [['세무기장료', '기장료', '등기부등본', '승인안내', '호스팅', '클라우드', '네이버웍스', '수수료', '클로드', '구독료'], '지급수수료', '출금'],
    [['입주자부담금', '입주자 부담금', '관리비', '임대료', '임차료'], '지급임차료', '출금'],
    [['현수막', '광고', '홍보물'], '광고선전비', '출금'],
    [['도서', '구독', '명함', '인쇄', '매거진'], '도서인쇄비', '출금'],
    [['워크샵', '워크숍', '생일', '간식', '회식', '소모임', '요가', '외식', '식대', '점심', '저녁', '커피', '다과',
      '케이크', '근속', '환영', '추석선물', '설선물', '명절', '경조', '직원선물', '음료'], '복리후생비', '출금'],
    [['주유', '주차', '세차', '차량'], '차량유지비', '출금'],
    [['숙박', '출장', '기차', 'KTX', 'SRT', '택시', '교통'], '여비교통비', '출금'],
    [['교육', '강의', '세미나'], '교육훈련비', '출금'],
    [['비품', '모니터', '노트북', '미니pc', '미니PC', '데스크탑', '의자', '책상', '프린터', '복합기', '태블릿', '아이패드'], '비품', '출금'],
    [['종이컵', '물티슈', '클리너', '소모품', '펌프', '램카드', '메인보드', '부품'], '소모품비', '출금'],
    [['라벨', '파일', '문구', '볼펜', '토너', '복사용지', '사무용품'], '사무용품비', '출금'],
    [['유지보수', '홈페이지수정', '서버유지', '용역'], '매출(용역·유지보수)', '입금'],
];

/* ---------------------------------------------------------------- DB 도우미 */

function lg_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = bl_db();
    lg_seed($pdo);
    return $pdo;
}

function lg_all($sql, array $params = []) {
    $st = lg_db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function lg_row($sql, array $params = []) {
    $st = lg_db()->prepare($sql);
    $st->execute(array_values($params));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function lg_val($sql, array $params = []) {
    $st = lg_db()->prepare($sql);
    $st->execute(array_values($params));
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function lg_exec($sql, array $params = []) {
    $st = lg_db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->rowCount();
}

function lg_ph(array $ids) {
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

/** 글자 잇기 — SQLite 는 ||, MySQL 은 CONCAT() (MySQL 에서 || 는 '또는'이다) */
function lg_concat(...$parts) {
    return bl_is_sqlite(lg_db()) ? '(' . implode(' || ', $parts) . ')' : 'CONCAT(' . implode(', ', $parts) . ')';
}

/** 빈 DB 에서 처음 열 때만: 출입 명단·계정과목·기본 규칙 */
function lg_seed(PDO $pdo) {
    $now = date('Y-m-d H:i:s');
    if (!(int)$pdo->query("SELECT COUNT(*) FROM bl_ledger_roles")->fetchColumn()) {
        foreach (LG_SEED_ROLES as $email => $role) {
            bl_insert_ignore($pdo, 'bl_ledger_roles', ['email' => $email, 'role' => $role, 'updated_by' => 'seed', 'updated_at' => $now]);
        }
    }
    if (!(int)$pdo->query("SELECT COUNT(*) FROM bl_ledger_categories")->fetchColumn()) {
        $n = 0;
        $ins = $pdo->prepare("INSERT INTO bl_ledger_categories(name, grp, sort) VALUES (?,?,?)");
        foreach (LG_DEFAULT_CATEGORIES as $grp => $names) {
            foreach ($names as $name) $ins->execute([$name, $grp, ++$n]);
        }
        $ins = $pdo->prepare("INSERT INTO bl_ledger_rules(keyword, category, direction, created_at) VALUES (?,?,?,?)");
        foreach (LG_DEFAULT_RULES as [$kw, $cat, $d]) $ins->execute([$kw, $cat, $d, $now]);
    }
}

/* ---------------------------------------------------------------- 출입·기록 */

/** 출입 명단의 역할(clerk/ceo). 명단에 없으면 null */
function lg_role_of($email) {
    $r = lg_val("SELECT role FROM bl_ledger_roles WHERE email=?", [strtolower((string)$email)]);
    return in_array($r, [LG_ROLE_CLERK, LG_ROLE_CEO], true) ? $r : null;
}

function lg_log(array $me, $action, $summary, $data = null) {
    lg_exec("INSERT INTO bl_ledger_log(at, email, action, summary, data) VALUES (?,?,?,?,?)",
        [date('Y-m-d H:i:s'), $me['email'] ?? '', $action, mb_substr($summary, 0, 500),
         $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

/* ---------------------------------------------------------------- 공통 도우미 */

function lg_won($n) {
    return number_format((int)($n ?? 0));
}

function lg_today() {
    return date('Y-m-d');
}

function lg_day_label($d) {
    return $d . '(' . lg_weekday($d) . ')';
}

function lg_weekday($d) {
    return LG_WEEKDAYS[(int)date('N', strtotime($d)) - 1];
}

function lg_add_days($d, $n) {
    return date('Y-m-d', strtotime("$d " . ($n >= 0 ? '+' : '') . "$n day"));
}

/** '12,000원', '(3,000)' 같은 값도 숫자로 (괄호는 마이너스). 반올림은 파이썬 round() 처럼 짝수 쪽으로. */
function lg_to_int($v) {
    if ($v === null) return 0;
    if (is_bool($v)) return (int)$v;
    if (is_int($v)) return $v;
    if (is_float($v)) return (int)round($v, 0, PHP_ROUND_HALF_EVEN);
    $s = str_replace([',', '원', ' '], '', trim(lg_str($v)));
    if ($s === '' || $s === '-') return 0;
    $neg = str_starts_with($s, '(') && str_ends_with($s, ')');
    $s = trim($s, '()');
    if (!is_numeric($s)) return 0;
    $n = (int)round((float)$s, 0, PHP_ROUND_HALF_EVEN);
    return $neg ? -$n : $n;
}

/** 날짜 칸 → 'YYYY-MM-DD' 또는 null. 엑셀 날짜, 일련번호(20000~80000), '2026.01.02', '2026년 1월 2일' 모두 */
function lg_to_date($v) {
    if ($v === null || $v === '') return null;
    if (is_object($v) && method_exists($v, 'date')) return $v->date();
    if ((is_int($v) || is_float($v)) && $v > 20000 && $v < 80000) {
        return gmdate('Y-m-d', gmmktime(0, 0, 0, 12, 30, 1899) + (int)$v * 86400);
    }
    if (!preg_match('/^(\d{4})[.\-\/년\s]*(\d{1,2})[.\-\/월\s]*(\d{1,2})/u', trim(lg_str($v)), $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
}

/** 파이썬 str() 과 같은 글자 (엑셀 숫자 1234.0 은 "1234.0", 빈칸은 "None") */
function lg_str($v) {
    return lg_pystr($v);
}

/** 파이썬에서 거짓으로 치는 값 (None, '', 0, 0.0, False) — `x or ""` 를 옮길 때 쓴다 */
function lg_falsy($v) {
    return $v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false;
}

/** 파이썬 str.strip() — 유니코드 공백(전각 공백·nbsp 포함)까지 앞뒤를 자른다 */
function lg_strip($s) {
    return preg_replace('/^[\s\x{1c}-\x{1f}\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+|[\s\x{1c}-\x{1f}\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+$/u', '', (string)$s);
}

function lg_last4($v) {
    $digits = preg_replace('/\D/', '', lg_falsy($v) ? '' : lg_str($v));
    return $digits !== '' ? substr($digits, -4) : '';
}

/** 거래처 이름 비교용 — (주)·주식회사 등과 띄어쓰기·기호를 뺀다.
 *  파이썬은 NFKC 정규화를 먼저 했다. 이 서버엔 intl 이 없을 수 있어, 장부에 나오는 경우(전각 글자·㈜)만 직접 바꾼다. */
function lg_norm_name($s) {
    $s = (string)($s ?? '');
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_KC);
    } else {
        $s = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn($m) => mb_chr(mb_ord($m[0]) - 0xFEE0), $s);
        $s = str_replace("\u{3000}", ' ', $s);
    }
    $s = str_replace(['(주)', '주식회사', '(사)', '사단법인', '(재)', '재단법인', '㈜'], '', $s);
    return preg_replace('/[\s()\-_.,·]/u', '', $s);
}

function lg_closed_months() {
    return array_column(lg_all("SELECT ym FROM bl_ledger_month_closes"), 'ym');
}

function lg_setting($key, $default = '') {
    $v = lg_val("SELECT v FROM bl_ledger_settings WHERE k=?", [$key]);
    return ($v === null || $v === '') ? $default : $v;
}

function lg_daily_start() {
    return lg_setting('daily_start', '2026-01-01');
}

/** 계정과목 고르기 목록: [[묶음, [이름...]], ...] */
function lg_category_options() {
    $rows = lg_all("SELECT name, grp FROM bl_ledger_categories WHERE hidden=0 ORDER BY sort, id");
    $out = [];
    foreach (LG_GROUPS as $g) {
        $out[] = [$g, array_values(array_map(fn($r) => $r['name'], array_filter($rows, fn($r) => $r['grp'] === $g)))];
    }
    return $out;
}

/* ---------------------------------------------------------------- 잔액 */

/** 계좌·카드별 현재 잔액 = 기초잔액 + 입금 - 출금 */
function lg_balances() {
    $rows = lg_all("SELECT a.*, COALESCE(SUM(t.amount_in),0) AS tin, COALESCE(SUM(t.amount_out),0) AS tout,
                           COUNT(t.id) AS cnt, MAX(t.tx_date) AS last_date
                    FROM bl_ledger_accounts a LEFT JOIN bl_ledger_tx t ON t.account_id=a.id
                    GROUP BY a.id ORDER BY a.kind, a.id");
    $paid = [];
    foreach (lg_all("SELECT linked_card_id, SUM(amount_out) AS s FROM bl_ledger_tx
                     WHERE linked_card_id IS NOT NULL GROUP BY linked_card_id") as $r) {
        $paid[(int)$r['linked_card_id']] = (int)$r['s'];
    }
    $out = [];
    foreach ($rows as $d) {
        $d['id'] = (int)$d['id'];
        foreach (['opening_balance', 'tin', 'tout', 'cnt', 'pay_day'] as $k) $d[$k] = (int)$d[$k];
        $d['pay_group'] = $d['pay_group'] === null ? null : (int)$d['pay_group'];
        $d['paid'] = $d['kind'] === '카드' ? ($paid[$d['id']] ?? 0) : 0;
        $d['own'] = $d['opening_balance'] + $d['tin'] - $d['tout'];       // 이 카드만의 사용 잔액
        $d['balance'] = $d['own'] + $d['paid'];
        $d['group_of'] = null;
        $d['members'] = [];
        $out[$d['id']] = $d;
    }
    // 대금을 함께 내는 카드 묶음: 함께 쓰는 카드의 사용분을 대표 카드에 합산
    foreach ($out as $id => $d) {
        $lead = ($d['kind'] === '카드' && $d['pay_group'] !== null) ? ($out[$d['pay_group']] ?? null) : null;
        if ($lead && $lead['id'] !== $id && !$lead['pay_group']) {
            $out[$lead['id']]['balance'] += $d['own'];
            $out[$lead['id']]['members'][] = $d['name'];
            $out[$id]['group_of'] = $lead['name'];
            $out[$id]['balance'] = 0;          // 묶음 합계는 대표 카드 줄에서 봄
        }
    }
    return array_values($out);
}

/** 통장 적요에 카드사 글자가 있는 출금을 '카드대금 결제'로 표시하고 카드와 연결한다. */
function lg_link_card_payments($card_id = null) {
    $cards = lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='카드' AND pay_keyword<>'' AND pay_group IS NULL"
                    . ($card_id ? " AND id=?" : ''), $card_id ? [$card_id] : []);
    $total = 0;
    foreach ($cards as $card) {
        $start = $card['pay_start'] ?: '0000-01-01';      // 이 카드의 결제로 볼 기간
        $end = $card['pay_end'] ?: '9999-12-31';
        // 쉼표로 여러 글자 가능 (예: 비씨카드출금, 비씨카드선결제)
        foreach (preg_split('/[,，]/u', $card['pay_keyword']) as $k) {
            if (trim($k) === '') continue;
            $kw = str_replace(' ', '', $k);
            $total += lg_exec("UPDATE bl_ledger_tx SET linked_card_id=?, category=?
                WHERE linked_card_id IS NULL AND amount_out>0
                  AND account_id IN (SELECT id FROM bl_ledger_accounts WHERE kind='계좌')
                  AND REPLACE(content,' ','') LIKE ? AND tx_date BETWEEN ? AND ?",
                [$card['id'], LG_CARD_PAY, "%$kw%", $start, $end]);
        }
    }
    return $total;
}

/** 미분류 거래에 규칙을 적용한다. 긴 글자 규칙이 먼저 (예: '택시비 환급'이 '택시'보다 우선). */
function lg_apply_rules($only_ids = null) {
    $rules = lg_all("SELECT id, keyword, category, direction FROM bl_ledger_rules ORDER BY id");
    usort($rules, fn($a, $b) => (mb_strlen($b['keyword']) <=> mb_strlen($a['keyword'])) ?: ((int)$a['id'] <=> (int)$b['id']));
    $idSql = '';
    $idParams = [];
    if ($only_ids) {
        $idParams = array_values(array_map('intval', $only_ids));
        $idSql = ' AND id IN (' . lg_ph($idParams) . ')';
    }
    $text = 'REPLACE(' . lg_concat('content', "' '", "COALESCE(note,'')", "' '", "COALESCE(detail,'')") . ",' ','')";
    $total = 0;
    foreach ($rules as $r) {
        $kw = str_replace(' ', '', $r['keyword']);
        if ($kw === '') continue;
        $cond = ['입금' => ' AND amount_in>0', '출금' => ' AND amount_out>0'][$r['direction']] ?? '';
        $total += lg_exec("UPDATE bl_ledger_tx SET category=? WHERE category='' AND " . LG_LOCK_SQL . " AND $text LIKE ?" . $cond . $idSql,
            array_merge([$r['category'], "%$kw%"], $idParams));
    }
    // 메모에 계정과목 이름이 적혀 있으면 그대로 (예: 메모 "복리후생비 간식" → 복리후생비)
    $keys = [];
    foreach (lg_all("SELECT name FROM bl_ledger_categories WHERE hidden=0") as $c) {
        $n = $c['name'];
        $keys[] = [str_replace(' ', '', $n), $n];
        foreach (preg_split('/[()·]/u', $n) as $part) {          // "기업업무추진비(접대비)" → "기업업무추진비", "접대비"
            $part = str_replace(' ', '', trim($part));
            if (mb_strlen($part) >= 2 && $part !== str_replace(' ', '', $n)) $keys[] = [$part, $n];
        }
    }
    // 파이썬 sort 는 안정 정렬 — 같은 길이면 넣은 순서를 지킨다
    $idx = array_keys($keys);
    usort($idx, fn($a, $b) => (mb_strlen($keys[$b][0]) <=> mb_strlen($keys[$a][0])) ?: ($a <=> $b));
    foreach ($idx as $i) {
        [$kw, $n] = $keys[$i];
        $total += lg_exec("UPDATE bl_ledger_tx SET category=? WHERE category='' AND " . LG_LOCK_SQL
            . " AND COALESCE(note,'')<>'' AND REPLACE(note,' ','') LIKE ?" . $idSql,
            array_merge([$n, "%$kw%"], $idParams));
    }
    return $total;
}

/** 출납장 내용(detail)과 메모로 미분류 거래 자동 분류. 바뀐 거래 목록을 돌려줌 */
function lg_apply_detail_words($ids = null) {
    $rows = lg_all("SELECT id, content, detail, note, amount_in FROM bl_ledger_tx
                    WHERE category='' AND (detail<>'' OR note<>'') AND " . LG_LOCK_SQL);
    $want = $ids === null ? null : array_flip(array_map('intval', $ids));
    $done = [];
    foreach ($rows as $r) {
        if ($want !== null && !isset($want[(int)$r['id']])) continue;
        $text_n = str_replace(' ', '', ($r['detail'] ?? '') . ' ' . ($r['note'] ?? ''));
        $direction = (int)$r['amount_in'] > 0 ? '입금' : '출금';
        foreach (LG_DETAIL_WORDS as [$words, $cat, $d]) {
            if ($d !== $direction) continue;
            foreach ($words as $w) {
                if (str_contains($text_n, str_replace(' ', '', $w))) {
                    lg_exec("UPDATE bl_ledger_tx SET category=? WHERE id=?", [$cat, $r['id']]);
                    $done[] = [(int)$r['id'], $cat];
                    continue 3;
                }
            }
        }
    }
    return $done;
}

/* ---------------------------------------------------------------- 일정 */

/** 이번 달(또는 다음 달)의 day일. 그 달에 없는 날짜면 말일. */
function lg_next_day_of_month($today, $day) {
    [$y, $m] = [(int)substr($today, 0, 4), (int)substr($today, 5, 2)];
    $d = $today;
    for ($i = 0; $i < 2; $i++) {
        $d = sprintf('%04d-%02d-%02d', $y, $m, min($day, lg_cal_days($y, $m)));
        if ($d >= $today) return $d;
        [$y, $m] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
    }
    return $d;
}

function lg_cal_days($y, $m) {
    return (int)date('t', mktime(0, 0, 0, $m, 1, $y));
}

function lg_d_day($target) {
    return (int)round((strtotime($target) - strtotime(lg_today())) / 86400);
}

/** 부가세 신고 기한: 1·4·7·10월 25일 (주말·공휴일이면 다음 영업일로 미뤄짐) */
function lg_next_vat_deadline($today) {
    $y0 = (int)substr($today, 0, 4);
    foreach ([$y0, $y0 + 1] as $y) {
        foreach ([1, 4, 7, 10] as $m) {
            $dl = sprintf('%04d-%02d-25', $y, $m);
            if ($dl >= $today) return $dl;
        }
    }
    return null;
}

/* ---------------------------------------------------------------- 카드 청구서 */

function lg_ym_add($ym, $n) {
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2) + $n;
    while ($m > 12) { $y++; $m -= 12; }
    while ($m < 1) { $y--; $m += 12; }
    return sprintf('%04d-%02d', $y, $m);
}

/** 그 날짜가 속한 달의 다음 달 결제일 (예: 8/16 → 9/23) */
function lg_due_after($d_iso, $pay_day = 23) {
    $y = (int)substr($d_iso, 0, 4);
    $m = (int)substr($d_iso, 5, 2) + 1;
    if ($m > 12) { $y++; $m = 1; }
    return sprintf('%04d-%02d-%02d', $y, $m, min($pay_day, lg_cal_days($y, $m)));
}

function lg_card_group_ids($aid) {
    $a = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$aid]);
    if (!$a) return [];
    $lead = $a['pay_group'] ?: $a['id'];
    return array_map('intval', array_column(lg_all("SELECT id FROM bl_ledger_accounts WHERE id=? OR pay_group=? ORDER BY id", [$lead, $lead]), 'id'));
}

/** 카드 청구서 단위 맞춰보기.
 *  - 명세서로 가져온 거래(결제예정일 있음): 결제예정일별로 묶음
 *  - 그 밖의 거래: 이용월 1일~말일 → 다음 달 결제일
 *  통장 결제는 '결제일-4일 이후의 가장 가까운 결제일' 청구분으로 봄 (선결제, 주말 밀림 포함)
 *  돌려주는 값: [카드들, 청구서들, 결제 목록, 아직 매입 전 금액] */
function lg_card_bills($lead_id) {
    $lead = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$lead_id]);
    $cards = array_merge([$lead], lg_all("SELECT * FROM bl_ledger_accounts WHERE pay_group=? ORDER BY id", [$lead_id]));
    $ids = array_map(fn($c) => (int)$c['id'], $cards);
    $pay_day = (int)$lead['pay_day'] ?: 23;
    $ph = lg_ph($ids);
    $usage = [];
    $pending = [];
    $statement = (int)lg_val("SELECT COUNT(*) FROM bl_ledger_tx WHERE account_id IN ($ph) AND bill_due<>''", $ids);
    foreach (lg_all("SELECT account_id, tx_date, bill_due, amount_out-amount_in AS net FROM bl_ledger_tx WHERE account_id IN ($ph)", $ids) as $r) {
        $cid = (int)$r['account_id'];
        $due = $r['bill_due'];
        $net = (int)$r['net'];
        if (!$due) {
            if ($statement) {
                $pending[$cid] = ($pending[$cid] ?? 0) + $net;      // 명세서상 아직 매입 전 (전액 취소 건은 서로 지워져 0)
                continue;
            }
            $due = lg_due_after($r['tx_date'], $pay_day);
        }
        $usage[$due][$cid] = ($usage[$due][$cid] ?? 0) + $net;
    }
    $dues = array_keys($usage);
    sort($dues, SORT_STRING);
    $pays = [];
    $pay_list = [];
    foreach (lg_all("SELECT t.id, t.tx_date, t.content, t.amount_out, a.name AS bank FROM bl_ledger_tx t
                     JOIN bl_ledger_accounts a ON a.id=t.account_id WHERE t.linked_card_id=? ORDER BY t.tx_date, t.id", [$lead_id]) as $p) {
        $limit = lg_add_days($p['tx_date'], -4);
        $due = null;
        foreach ($dues as $d) { if ($d >= $limit) { $due = $d; break; } }
        $due = $due ?: lg_due_after($p['tx_date'], $pay_day);
        $pays[$due] = ($pays[$due] ?? 0) + (int)$p['amount_out'];
        $pay_list[] = $p + ['due' => $due];
    }
    $today = lg_today();
    $all = array_unique(array_merge(array_keys($usage), array_keys($pays)));
    sort($all, SORT_STRING);
    $bills = [];
    foreach ($all as $due) {
        $per = array_map(fn($c) => $usage[$due][(int)$c['id']] ?? 0, $cards);
        $total = array_sum($per);
        $paid = $pays[$due] ?? 0;
        $bills[] = ['due' => $due, 'use_ym' => lg_ym_add(substr($due, 0, 7), -1), 'per' => $per, 'total' => $total,
                    'paid' => $paid, 'diff' => $paid - $total, 'remain' => max(0, $total - $paid),
                    'status' => $due < $today ? '결제 완료' : '결제 예정'];
    }
    $pend = array_map(fn($c) => $pending[(int)$c['id']] ?? 0, $cards);
    return [$cards, $bills, $pay_list, $pend];
}

/* ---------------------------------------------------------------- 일일출납장 */

/** 출납장 적요: 내용이 없으면 통장 적요, 내용이 거래처 이름으로 시작하면 내용만, 아니면 '적요 - 내용' */
function lg_daybook_text($content, $detail) {
    $content = trim((string)($content ?? ''));
    $detail = trim((string)($detail ?? ''));
    if ($detail === '') return $content;
    $nc = lg_norm_name($content);
    $nd = lg_norm_name($detail);
    if ($nc === '' || str_starts_with($nd, mb_substr($nc, 0, 3)) || str_contains($nd, $nc)) return $detail;
    return "$content - $detail";
}

function lg_daybook_groups() {
    return [lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='계좌' ORDER BY id"),
            lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='카드' AND pay_group IS NULL ORDER BY id")];
}

/** 해외 결제 수수료(따로 들어온 줄)를 원래 결제 줄의 dedup_key 로 모은다 */
function lg_overseas_fees(array $group) {
    $fees = [];
    foreach (lg_all("SELECT dedup_key, amount_out FROM bl_ledger_tx WHERE account_id IN (" . lg_ph($group) . ")
                     AND content LIKE '" . LG_OVERSEAS_FEE . "%' AND dedup_key LIKE '%|F'", $group) as $f) {
        $k = substr($f['dedup_key'], 0, -2);
        $fees[$k] = ($fees[$k] ?? 0) + (int)$f['amount_out'];
    }
    return $fees;
}

/* ---------------------------------------------------------------- 잔액·자금일보 */

/** 그날의 계좌별 전일잔액·입금·출금·잔액, 은행 잔액과 대조, 카드 사용 */
function lg_cash_report($d) {
    $m0 = substr($d, 0, 8) . '01';
    $banks = [];
    foreach (lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='계좌' ORDER BY id") as $a) {
        $f = fn($q, ...$p) => (int)(lg_val($q, array_merge([$a['id']], $p)) ?? 0);
        $prev = (int)$a['opening_balance'] + $f("SELECT SUM(amount_in)-SUM(amount_out) FROM bl_ledger_tx WHERE account_id=? AND tx_date<?", $d);
        $din = $f("SELECT SUM(amount_in) FROM bl_ledger_tx WHERE account_id=? AND tx_date=?", $d);
        $dout = $f("SELECT SUM(amount_out) FROM bl_ledger_tx WHERE account_id=? AND tx_date=?", $d);
        $mon_in = $f("SELECT SUM(amount_in) FROM bl_ledger_tx WHERE account_id=? AND tx_date BETWEEN ? AND ?", $m0, $d);
        $mon_out = $f("SELECT SUM(amount_out) FROM bl_ledger_tx WHERE account_id=? AND tx_date BETWEEN ? AND ?", $m0, $d);
        $close = $prev + $din - $dout;
        // 은행 엑셀의 '거래 후 잔액'과 대조: 그날(없으면 그 전 마지막 거래일) 은행 잔액 중 장부 잔액과 같은 게 있으면 일치
        $last_day = lg_val("SELECT MAX(tx_date) FROM bl_ledger_tx WHERE account_id=? AND tx_date<=? AND bank_balance IS NOT NULL", [$a['id'], $d]);
        $check = null;
        if ($last_day) {
            $book = (int)$a['opening_balance'] + $f("SELECT SUM(amount_in)-SUM(amount_out) FROM bl_ledger_tx WHERE account_id=? AND tx_date<=?", $last_day);
            $bals = array_map('intval', array_column(lg_all("SELECT bank_balance FROM bl_ledger_tx WHERE account_id=? AND tx_date=? AND bank_balance IS NOT NULL ORDER BY id", [$a['id'], $last_day]), 'bank_balance'));
            $check = ['day' => $last_day, 'ok' => in_array($book, $bals, true), 'book' => $book, 'bank' => $bals];
        }
        $banks[] = ['acc' => $a, 'prev' => $prev, 'in' => $din, 'out' => $dout, 'close' => $close,
                    'mon_in' => $mon_in, 'mon_out' => $mon_out, 'check' => $check];
    }
    $cards = [];
    foreach (lg_all("SELECT * FROM bl_ledger_accounts WHERE kind='카드' AND pay_group IS NULL ORDER BY id") as $lead) {
        $ids = lg_card_group_ids($lead['id']);
        $ph = lg_ph($ids);
        $used = (int)lg_val("SELECT COALESCE(SUM(amount_out)-SUM(amount_in),0) FROM bl_ledger_tx WHERE account_id IN ($ph)
                             AND tx_date=? AND category<>? AND source_file<>?", array_merge($ids, [$d, LG_CANCELLED, LG_ADJUST]));
        $mon = (int)lg_val("SELECT COALESCE(SUM(amount_out)-SUM(amount_in),0) FROM bl_ledger_tx WHERE account_id IN ($ph)
                            AND tx_date BETWEEN ? AND ? AND category<>? AND source_file<>?", array_merge($ids, [$m0, $d, LG_CANCELLED, LG_ADJUST]));
        [, $bills] = lg_card_bills($lead['id']);
        $nxt = null;
        foreach ($bills as $b) { if ($b['due'] >= $d && $b['status'] !== '결제 완료') { $nxt = $b; break; } }
        $cards[] = ['lead' => $lead, 'n' => count($ids), 'used' => $used, 'mon' => $mon,
                    'next_due' => $nxt['due'] ?? null, 'next_amt' => $nxt['remain'] ?? 0];
    }
    $txs = lg_all("SELECT t.*, a.name AS acc_name, a.kind AS acc_kind FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
                   WHERE t.tx_date=? AND t.split_of IS NULL AND t.content NOT LIKE '" . LG_OVERSEAS_FEE . "%'
                   AND t.source_file<>? AND t.category<>? ORDER BY a.kind DESC, a.id, t.id", [$d, LG_ADJUST, LG_CANCELLED]);
    return [$banks, $cards, $txs];
}

/* ---------------------------------------------------------------- 세금계산서 (홈 화면 할 일 수만 쓴다) */

function lg_invoice_rows($where = '1=1', array $params = []) {
    return lg_all("SELECT i.*, COALESCE((SELECT SUM(amount) FROM bl_ledger_invoice_links l WHERE l.invoice_id=i.id),0) AS paid,
                          (SELECT GROUP_CONCAT(DISTINCT method) FROM bl_ledger_invoice_links l WHERE l.invoice_id=i.id) AS methods
                   FROM bl_ledger_invoices i WHERE $where ORDER BY i.write_date DESC, i.id DESC", $params);
}

function lg_inv_status(array $i) {
    if ($i['closed']) return '정산 완료';
    $remain = (int)$i['total'] - (int)$i['paid'];
    if ((int)$i['total'] <= 0) return '수정(마이너스)';
    if ($remain === 0) return str_contains((string)($i['methods'] ?? ''), '확인 필요') ? '확인 필요' : '정산 완료';
    if ((int)$i['paid'] > 0) return '일부 정산';
    return $i['kind'] === '매출' ? '미수' : '미지급';
}

/* ---------------------------------------------------------------- 거래 지우기 */

/** 거래 지우기: 마감한 달은 건너뛰고, 나눈 조각·세금계산서 연결도 함께 정리.
 *  파이썬 버전은 지우기 전에 장부 파일을 통째로 백업했다. 여기서는 지운 줄의 원래 내용을 작업 기록에 남긴다.
 *  돌려주는 값: [지운 수, 마감이라 건너뛴 수] */
function lg_delete_txs(array $ids, $reason, array $me) {
    $locked = array_flip(lg_closed_months());
    $rows = [];
    foreach ($ids as $i) {
        $r = lg_row("SELECT id, tx_date FROM bl_ledger_tx WHERE id=?", [(int)$i]);
        if ($r) $rows[] = $r;
    }
    $ok = array_values(array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => !isset($locked[substr($r['tx_date'], 0, 7)]))));
    if (!$ok) return [0, count($rows)];
    $pdo = lg_db();
    $pdo->beginTransaction();
    try {
        $kids = array_map('intval', array_column(lg_all("SELECT id FROM bl_ledger_tx WHERE split_of IN (" . lg_ph($ok) . ")", $ok), 'id'));
        $allids = array_merge($ok, $kids);
        $ph2 = lg_ph($allids);
        $saved = ['tx' => lg_all("SELECT * FROM bl_ledger_tx WHERE id IN ($ph2)", $allids),
                  'invoice_links' => lg_all("SELECT * FROM bl_ledger_invoice_links WHERE tx_id IN ($ph2)", $allids)];
        lg_exec("DELETE FROM bl_ledger_invoice_links WHERE tx_id IN ($ph2)", $allids);
        lg_exec("DELETE FROM bl_ledger_tx WHERE id IN ($ph2)", $allids);
        lg_log($me, $reason, '거래 ' . count($ok) . '건 지움(나눈 조각 ' . count($kids) . '건 함께)', $saved);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [count($ok), count($rows) - count($ok)];
}

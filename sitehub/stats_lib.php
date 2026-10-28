<?php
/**
 * SiteHub 통계 집계. api.php 의 GET ?stats 가 부른다 — 권한 확인(sitehub_can_stats)은 부르는 쪽 일.
 *
 *  - 계약 · 사이트: 오늘 기준 스냅숏, 보관함 제외. 계약 상태는 화면과 같은 sitehub_contract_status() 로 판정하므로
 *    목록 배지·요약 네 칸과 숫자가 어긋나지 않는다.
 *  - 연간 계약액(연도 선택, 기본 올해): 유상(형태 연간·월간·건별) 사이트 중 올해와 기간이 겹치는 계약(종료일 가장 늦은 한 건)의 금액 — 올해 안에 만료된 계약도 포함. 계약 금액은 항상 1년 기준이라
 *    (월 납부라도 월 금액이 아님) 그대로 더한다. VAT 포함/별도는 섞인 채 금액 칸 그대로.
 *  - 지출 비용: lab_site_costs(사이트 상세 '비용' 탭) 결제일 기준. 월별·사이트·구분·결제처는 고른 기간, 연별·결제처×연도 표는 기간과 무관한 전체.
 *    금액은 입력한 그대로(VAT 포함/별도 섞임). 결제일이 비었거나 결제처가 비면 '(미기재)'.
 *  - 유지보수 이력: lab_history(Slack 리스트 동기화본) 중 라이브에 있는 요청(archived=0, 하위 항목 제외), 요청일 기준 기간.
 */

const SITEHUB_STATS_EXPIRING_PAST = 30;   // 최근 만료: 지난 30일
const SITEHUB_STATS_EXPIRING_NEXT = 90;   // 만료 예정: 앞으로 90일
const SITEHUB_STATS_MAX_MONTHS    = 36;   // 월별 막대는 최대 36개월 (전체 기간이 더 길면 최근 36개월)

/** 기간 키 → [키, 시작일|null, 종료일, 라벨] */
function sitehub_stats_period(string $p): array {
    $today = new DateTimeImmutable('today');
    switch ($p) {
        case 'ytd':
            return ['ytd', $today->modify('first day of january this year'), $today, $today->format('Y') . '년'];
        case 'lastyear':
            $y = (int)$today->format('Y') - 1;
            return ['lastyear', new DateTimeImmutable("{$y}-01-01"), new DateTimeImmutable("{$y}-12-31"), "{$y}년"];
        case 'all':
            return ['all', null, $today, '전체 기간'];
        default:
            return ['12m', $today->modify('first day of this month')->modify('-11 months'), $today, '최근 12개월'];
    }
}

/** 계약 한 건의 연간 금액 — 금액 칸이 곧 1년 기준 금액이다. 금액이 없으면 null */
function sitehub_stats_annual(?array $c): ?int {
    if (!$c || $c['amount'] === null) return null;
    return (int)$c['amount'];
}

/** 고른 연도(1/1~12/31)와 기간이 겹치는 유상 계약 중 종료일이 가장 늦은 한 건 — 연간 계약액 집계용. 없으면 null */
function sitehub_stats_year_contract(array $contracts, string $y): ?array {
    $best = null;
    foreach ($contracts as $c) {
        if (in_array($c['kind'] ?? '', ['free', 'none'], true)) continue;
        $st = (string)($c['start_date'] ?? ''); $en = (string)($c['end_date'] ?? '');
        if (($st !== '' && $st > "{$y}-12-31") || ($en !== '' && $en < "{$y}-01-01")) continue;
        if ($best === null || ($en === '' ? '9999' : $en) > (($best['end_date'] ?? '') === '' ? '9999' : $best['end_date'])) $best = $c;
    }
    return $best;
}

function sitehub_stats(PDO $pdo, string $periodKey, string $costPeriodKey = '12m', string $year = ''): array {
    $year = preg_match('/^\d{4}$/', $year) ? $year : date('Y');
    $last = $pdo->query("SELECT v FROM lab_sync_meta WHERE k = 'last_sync_at'")->fetchColumn() ?: null;
    return [
        'today'     => date('Y-m-d'),
        'contracts' => sitehub_stats_contracts($pdo, $year),
        'history'   => sitehub_stats_history($pdo, $periodKey),
        'costs'     => sitehub_stats_costs($pdo, $costPeriodKey),
        'last_sync' => $last,
        'sync_age'  => $last ? max(0, time() - strtotime($last)) : null,
    ];
}

function sitehub_stats_contracts(PDO $pdo, string $year = ''): array {
    $year = $year !== '' ? $year : date('Y');
    $minY = (int)$year; $maxY = max((int)date('Y') + 1, (int)$year);
    $sites = sitehub_load_sites($pdo, null, false);
    $kinds = sitehub_contract_kinds();
    $accKinds = sitehub_account_kinds();
    $kpi = ['sites' => count($sites), 'archived' => (int)$pdo->query("SELECT COUNT(*) FROM lab_sites WHERE archived = 1")->fetchColumn(),
            'paid' => 0, 'paid_soon' => 0, 'free' => 0, 'free_soon' => 0, 'expired' => 0, 'none' => 0,
            'annual_total' => 0, 'annual_sites' => 0, 'once_excluded' => 0, 'no_amount' => 0];
    $top = []; $expiring = []; $hosting = [];

    foreach ($sites as $s) {
        $cs = $s['contract_status'];
        $latest = null;
        foreach ($s['contracts'] as $c) if ($c['id'] === $cs['contract_id']) { $latest = $c; break; }

        $code = $cs['code'];
        if ($code === 'none')        $kpi['none']++;
        elseif ($code === 'expired') $kpi['expired']++;
        elseif ((int)$cs['group'] === 1) {
            $kpi['paid']++;
            if ($code === 'soon') $kpi['paid_soon']++;
        } else {
            $kpi['free']++;
            if ($code === 'soon') $kpi['free_soon']++;
        }

        $yc = sitehub_stats_year_contract($s['contracts'], $year);
        foreach ($s['contracts'] as $c) foreach (['start_date', 'end_date'] as $dk) if (preg_match('/^(\d{4})-/', (string)($c[$dk] ?? ''), $m)) $minY = min($minY, (int)$m[1]);
        $annual = sitehub_stats_annual($yc);
        if ($annual !== null) {
            $kpi['annual_total'] += $annual;
            $kpi['annual_sites']++;
            $top[] = ['site_id' => $s['id'], 'name' => $s['name'], 'value' => $annual,
                      'unit' => $yc['amount_unit'], 'amount' => (int)$yc['amount'], 'vat' => (int)$yc['vat_included']];
        } elseif ($yc && $yc['amount'] !== null) $kpi['once_excluded']++;
        elseif ($yc) $kpi['no_amount']++;

        // 만료 예정 · 최근 만료 — 미계약(kind=none) 행은 기간이 의미 없어 뺀다
        if ($latest && $code !== 'none' && $cs['dday'] !== null
            && $cs['dday'] >= -SITEHUB_STATS_EXPIRING_PAST && $cs['dday'] <= SITEHUB_STATS_EXPIRING_NEXT) {
            $expiring[] = ['site_id' => $s['id'], 'name' => $s['name'], 'customer' => $s['customer'], 'what' => 'contract',
                           'kind' => '계약 · ' . ($kinds[$latest['kind']] ?? $latest['kind']), 'end_date' => $latest['end_date'], 'dday' => $cs['dday'],
                           'code' => $code, 'amount' => $latest['amount'], 'unit' => $latest['amount_unit']];
        }
        // 호스팅·도메인·SSL 만료일도 같은 구간으로
        foreach ($s['accounts'] as $a) {
            $ex = $a['expiry'] ?? null;
            if (!$ex || $ex['dday'] < -SITEHUB_STATS_EXPIRING_PAST || $ex['dday'] > SITEHUB_STATS_EXPIRING_NEXT) continue;
            $expiring[] = ['site_id' => $s['id'], 'name' => $s['name'], 'customer' => $s['customer'], 'what' => 'account',
                           'kind' => ($accKinds[$a['kind']] ?? $a['kind']) . ($a['label'] !== '' ? ' · ' . $a['label'] : ''),
                           'end_date' => $a['expires_on'], 'dday' => $ex['dday'], 'code' => $ex['code'] === 'ok' ? 'active' : $ex['code'],
                           'amount' => null, 'unit' => null];
        }

        $h = sitehub_split_list($s['hosting'])[0] ?? '';
        $h = $h === '' ? '(미기재)' : $h;
        $hosting[$h] = ($hosting[$h] ?? 0) + 1;
    }

    usort($top, fn($a, $b) => $b['value'] <=> $a['value']);
    usort($expiring, fn($a, $b) => $a['dday'] <=> $b['dday']);
    arsort($hosting);
    $hostRows = [];
    $rest = 0;
    $i = 0;
    foreach ($hosting as $label => $n) {
        if ($i++ < 7 || count($hosting) <= 8) $hostRows[] = ['label' => (string)$label, 'n' => $n];
        else $rest += $n;
    }
    if ($rest) $hostRows[] = ['label' => '그 외', 'n' => $rest];

    $kpi['year'] = (int)$year;
    $kpi['years'] = range($maxY, max($minY, 2000));
    return ['kpi' => $kpi, 'top' => array_slice($top, 0, 10), 'expiring' => $expiring, 'hosting' => $hostRows,
            'window' => ['past' => SITEHUB_STATS_EXPIRING_PAST, 'next' => SITEHUB_STATS_EXPIRING_NEXT]];
}

function sitehub_stats_history(PDO $pdo, string $periodKey): array {
    [$key, $from, $to, $label] = sitehub_stats_period($periodKey);
    // 요청일이 비어 있으면 Slack 생성일로 — 기간에서 빠지는 행이 없게. $a 는 JOIN 할 때의 테이블 별칭
    $dateOf = fn(string $a = '') => "COALESCE({$a}`date`, DATE(FROM_UNIXTIME(NULLIF({$a}created, 0))))";
    $whereOf = fn(string $a = '') => "{$a}archived = 0 AND {$a}parent_id IS NULL" . ($from ? " AND " . $dateOf($a) . " BETWEEN ? AND ?" : '');
    $d = $dateOf();
    $where = $whereOf();
    $args = $from ? [$from->format('Y-m-d'), $to->format('Y-m-d')] : [];

    $q = function (string $sql) use ($pdo, $args) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };

    $k = $q("SELECT COUNT(*) n, SUM(paid = '유상') paid, SUM(paid = '무상') free, SUM(site_id IS NULL) unmatched,
                    SUM(status LIKE '%완료%') done FROM lab_history WHERE {$where}")[0];
    // 진행 중은 기간과 무관한 "지금" 숫자 — 끝나지 않은 일
    $open = (int)$pdo->query("SELECT COUNT(*) FROM lab_history WHERE archived = 0 AND parent_id IS NULL AND status <> ''
                              AND status NOT LIKE '%완료%' AND status NOT LIKE '%취소%' AND status NOT LIKE '%보류%'")->fetchColumn();

    // 월별 — 빈 달도 0 으로 채운다
    $byMonth = [];
    foreach ($q("SELECT DATE_FORMAT({$d}, '%Y-%m') ym, SUM(paid = '유상') paid, SUM(paid = '무상') free, COUNT(*) n
                 FROM lab_history WHERE {$where} AND {$d} IS NOT NULL GROUP BY ym ORDER BY ym") as $r) {
        $byMonth[$r['ym']] = ['paid' => (int)$r['paid'], 'free' => (int)$r['free'], 'n' => (int)$r['n']];
    }
    $start = $from ?: ($byMonth ? new DateTimeImmutable(array_key_first($byMonth) . '-01') : $to->modify('first day of this month'));
    $start = $start->modify('first day of this month');
    $endM  = $to->modify('first day of this month');
    $span  = ((int)$endM->format('Y') - (int)$start->format('Y')) * 12 + (int)$endM->format('n') - (int)$start->format('n') + 1;
    $clipped = $span > SITEHUB_STATS_MAX_MONTHS;
    if ($clipped) $start = $endM->modify('-' . (SITEHUB_STATS_MAX_MONTHS - 1) . ' months');
    $months = [];
    for ($m = $start; $m <= $endM; $m = $m->modify('+1 month')) {
        $ym = $m->format('Y-m');
        $v = $byMonth[$ym] ?? ['paid' => 0, 'free' => 0, 'n' => 0];
        $months[] = ['ym' => $ym, 'free' => $v['free'], 'paid' => $v['paid'], 'other' => $v['n'] - $v['free'] - $v['paid'], 'n' => $v['n']];
    }

    $group = function (string $col) use ($q, $where) {
        $rows = $q("SELECT {$col} label, COUNT(*) n FROM lab_history WHERE {$where} GROUP BY {$col} ORDER BY n DESC");
        return array_map(fn($r) => ['label' => (string)$r['label'] === '' ? '(미기재)' : (string)$r['label'], 'n' => (int)$r['n']], $rows);
    };

    $st = $pdo->prepare("SELECT h.site_id, s.name, s.archived, COUNT(*) n FROM lab_history h JOIN lab_sites s ON s.id = h.site_id
                         WHERE " . $whereOf('h.') . "
                         GROUP BY h.site_id, s.name, s.archived ORDER BY n DESC, s.name LIMIT 10");
    $st->execute($args);
    $sites = array_map(fn($r) => ['site_id' => (int)$r['site_id'], 'label' => $r['name'], 'archived' => (int)$r['archived'], 'n' => (int)$r['n']], $st->fetchAll());

    return [
        'period'  => ['key' => $key, 'label' => $label, 'from' => $from ? $from->format('Y-m-d') : null, 'to' => $to->format('Y-m-d'), 'clipped' => $clipped],
        'kpi'     => ['total' => (int)$k['n'], 'paid' => (int)$k['paid'], 'free' => (int)$k['free'], 'done' => (int)$k['done'],
                      'unmatched' => (int)$k['unmatched'], 'open' => $open],
        'months'  => $months,
        'status'  => $group('status'),
        'channel' => $group('channel'),
        'sites'   => $sites,
    ];
}

function sitehub_stats_costs(PDO $pdo, string $periodKey): array {
    [$key, $from, $to, $label] = sitehub_stats_period($periodKey);
    $vend = "IF(c.vendor = '', '(미기재)', c.vendor)";
    $where = $from ? "c.paid_on BETWEEN ? AND ?" : "c.paid_on IS NOT NULL";
    $args = $from ? [$from->format('Y-m-d'), $to->format('Y-m-d')] : [];
    $q = function (string $sql, ?array $a = null) use ($pdo, $args) { $st = $pdo->prepare($sql); $st->execute($a ?? $args); return $st->fetchAll(); };
    $row = fn(array $r, string $labelKey = 'label') => ['label' => (string)$r[$labelKey], 'amount' => (int)$r['amount'], 'n' => (int)$r['n']];

    $k = $q("SELECT COALESCE(SUM(c.amount),0) amount, COUNT(*) n, COUNT(DISTINCT {$vend}) vendors FROM lab_site_costs c WHERE {$where}")[0];
    $undated = (int)$pdo->query("SELECT COUNT(*) FROM lab_site_costs WHERE paid_on IS NULL")->fetchColumn();

    $byMonth = [];
    foreach ($q("SELECT DATE_FORMAT(c.paid_on, '%Y-%m') ym, COALESCE(SUM(c.amount),0) amount, COUNT(*) n FROM lab_site_costs c WHERE {$where} GROUP BY ym ORDER BY ym") as $r) {
        $byMonth[$r['ym']] = ['amount' => (int)$r['amount'], 'n' => (int)$r['n']];
    }
    $start = $from ?: ($byMonth ? new DateTimeImmutable(array_key_first($byMonth) . '-01') : $to->modify('first day of this month'));
    $start = $start->modify('first day of this month');
    $endM  = $to->modify('first day of this month');
    $span  = ((int)$endM->format('Y') - (int)$start->format('Y')) * 12 + (int)$endM->format('n') - (int)$start->format('n') + 1;
    $clipped = $span > SITEHUB_STATS_MAX_MONTHS;
    if ($clipped) $start = $endM->modify('-' . (SITEHUB_STATS_MAX_MONTHS - 1) . ' months');
    $months = [];
    for ($m = $start; $m <= $endM; $m = $m->modify('+1 month')) {
        $v = $byMonth[$m->format('Y-m')] ?? ['amount' => 0, 'n' => 0];
        $months[] = ['ym' => $m->format('Y-m')] + $v;
    }

    $vendors = array_map($row, $q("SELECT {$vend} label, COALESCE(SUM(c.amount),0) amount, COUNT(*) n FROM lab_site_costs c WHERE {$where} GROUP BY label ORDER BY amount DESC, n DESC"));
    $kinds = [];
    $kindNames = sitehub_cost_kinds();
    foreach ($q("SELECT c.kind label, COALESCE(SUM(c.amount),0) amount, COUNT(*) n FROM lab_site_costs c WHERE {$where} GROUP BY c.kind ORDER BY amount DESC") as $r) {
        $kinds[] = $row(['label' => $kindNames[$r['label']] ?? $r['label']] + $r);
    }
    $sites = array_map(fn($r) => $row($r) + ['site_id' => (int)$r['site_id']],
        $q("SELECT s.id site_id, s.name label, COALESCE(SUM(c.amount),0) amount, COUNT(*) n FROM lab_site_costs c JOIN lab_sites s ON s.id = c.site_id
            WHERE {$where} GROUP BY s.id, s.name ORDER BY amount DESC, s.name LIMIT 10"));

    // 연별 · 결제처×연도 — 기간과 무관한 전체
    $years = array_map(fn($r) => ['year' => (string)$r['y'], 'amount' => (int)$r['amount'], 'n' => (int)$r['n']],
        $pdo->query("SELECT YEAR(paid_on) y, COALESCE(SUM(amount),0) amount, COUNT(*) n FROM lab_site_costs WHERE paid_on IS NOT NULL GROUP BY y ORDER BY y")->fetchAll());
    $yearList = array_column($years, 'year');
    $pivot = [];
    foreach ($pdo->query("SELECT IF(vendor = '', '(미기재)', vendor) label, YEAR(paid_on) y, COALESCE(SUM(amount),0) amount FROM lab_site_costs WHERE paid_on IS NOT NULL GROUP BY label, y")->fetchAll() as $r) {
        $pivot[$r['label']]['by'][(string)$r['y']] = (int)$r['amount'];
        $pivot[$r['label']]['total'] = ($pivot[$r['label']]['total'] ?? 0) + (int)$r['amount'];
    }
    uasort($pivot, fn($a, $b) => $b['total'] <=> $a['total']);
    $vendorYears = [];
    foreach ($pivot as $lbl => $p) $vendorYears[] = ['label' => (string)$lbl, 'by' => (object)$p['by'], 'total' => $p['total']];

    return [
        'period'  => ['key' => $key, 'label' => $label, 'from' => $from ? $from->format('Y-m-d') : null, 'to' => $to->format('Y-m-d'), 'clipped' => $clipped],
        'kpi'     => ['amount' => (int)$k['amount'], 'n' => (int)$k['n'], 'vendors' => (int)$k['vendors'], 'undated' => $undated],
        'months'  => $months,
        'years'   => $years,
        'vendors' => $vendors,
        'kinds'   => $kinds,
        'sites'   => $sites,
        'vendor_years' => ['years' => $yearList, 'rows' => $vendorYears],
    ];
}

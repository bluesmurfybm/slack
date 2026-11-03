<?php
/**
 * 사이트 매칭 — Slack 리스트의 업체명 표기를 lab_sites 에 붙인다. sync.php(동기화)와 api.php 의 미매칭 자동 정리가 같이 쓴다.
 *
 *  리스트의 업체명은 사람이 그때그때 친 글자라 표기가 흔들린다 — 띄어쓰기('유네스코 국제회의'), 줄임('충북라이온스'),
 *  군더더기('365복지포털두드림'), 어순('청주창작공예지원센터'), 오타('층북라이온스클럽'). 그래서 정확히 같지 않아도
 *  아래 규칙 중 하나에 걸리면 같은 사이트로 본다(점수 0~1, 0.7 이상). 지역명이 다른 이름끼리('보은군…' vs '옥천군…')
 *  붙지 않도록 대부분의 규칙은 앞 글자가 같아야 한다.
 *    1.00 정규화(소문자·공백·괄호·기호·(주) 제거) 후 같음
 *    0.90+ 한쪽이 다른 쪽에 통째로 들어 있음 (짧은 쪽 한글 3자·영문 4자 이상)
 *    0.90  같은 길이 5자 이상에서 1~2자만 다름 (오타). 앞 두 글자가 다르면 1자·7자 이상만
 *    0.88  같은 글자들의 어순만 다름 (5자 이상, 첫 글자 같음)
 *    0.85  짧은 쪽 글자가 순서대로 다 들어 있음 (5자 이상, 앞 두 글자 같음)
 *    0.82  짧은 쪽 글자가 순서 무관하게 다 들어 있음 (6자 이상) — '라이온스충북지구' ↔ '충북라이온스'
 *    ~0.7+ 두 글자씩 끊은 조각의 겹침(Dice) 0.7 이상 (앞 두 글자 같음)
 *  새 표기끼리 묶을 때만(loose) 앞 세 글자가 같고 Dice 0.5 이상이면 0.75 — '증평군34plus센터' ↔ '증평군34플러스센터'.
 *  가장 높은 두 사이트가 거의 같은 점수면(예: '충북대' 가 여러 충북대 사이트에 들어 있음) 고르지 않는다. 다만 한쪽은 사이트
 *  '이름'이고 다른 쪽은 다른 사이트의 Slack 표기일 뿐이면 이름 쪽을 고른다(어떤 사이트의 slack_names 에 '충북대 박물관' 이
 *  잘못 들어 있어도 '충북대박물관' 사이트로 간다).
 */

const SITEHUB_MATCH_MIN = 0.7;

function sitehub_norm_name(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/\(주\)|㈜|주식회사|\(사\)|사단법인|재단법인/u', '', $s);
    return preg_replace('/[\s()\[\]{}<>·•\-_\/.,~!?\'"]+/u', '', $s);
}

function sitehub_chars(string $s): array {
    return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
}

function sitehub_dice(array $a, array $b): float {
    $bg = function (array $c) { $o = []; for ($i = 0, $n = count($c) - 1; $i < $n; $i++) { $k = $c[$i] . $c[$i + 1]; $o[$k] = ($o[$k] ?? 0) + 1; } return $o; };
    $x = $bg($a); $y = $bg($b);
    $tx = array_sum($x); $ty = array_sum($y);
    if ($tx + $ty === 0) return 0.0;
    $common = 0;
    foreach ($x as $k => $n) if (isset($y[$k])) $common += min($n, $y[$k]);
    return 2 * $common / ($tx + $ty);
}

function sitehub_is_subseq(array $short, array $long): bool {
    $i = 0; $n = count($short);
    foreach ($long as $c) { if ($i < $n && $c === $short[$i]) $i++; }
    return $i === $n;
}

function sitehub_char_subset(array $short, array $long): bool {
    $have = array_count_values($long);
    foreach (array_count_values($short) as $c => $n) if (($have[$c] ?? 0) < $n) return false;
    return true;
}

/** 정규화된 두 표기가 같은 사이트를 가리킬 점수. $loose 는 새 표기끼리 묶을 때만 */
function sitehub_name_score(string $a, string $b, bool $loose = false): float {
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    $ca = sitehub_chars($a); $cb = sitehub_chars($b);
    [$s, $l, $cs, $cl] = count($ca) <= count($cb) ? [$a, $b, $ca, $cb] : [$b, $a, $cb, $ca];
    $ns = count($cs); $nl = count($cl);

    $ascii = !preg_match('/[^\x00-\x7f]/', $s);
    if ($ns >= ($ascii ? 4 : 3) && mb_strpos($l, $s) !== false) return 0.9 + 0.05 * $ns / $nl;

    $p1 = $ca[0] === $cb[0];
    $p2 = $p1 && ($ca[1] ?? '') === ($cb[1] ?? '');
    $p3 = $p2 && ($ca[2] ?? '') === ($cb[2] ?? '');

    if ($ns === $nl && $ns >= 5) {
        $diff = 0; $headDiff = 0;
        for ($i = 0; $i < $ns; $i++) if ($ca[$i] !== $cb[$i]) { $diff++; if ($i < 2) $headDiff++; }
        if ($diff <= max(1, intdiv($ns, 5)) && ($headDiff === 0 || ($diff === 1 && $ns >= 7))) return 0.9;
        $sa = $ca; $sb = $cb; sort($sa); sort($sb);
        if ($p1 && $sa === $sb) return 0.88;
    }
    if ($ns >= 5 && $p2 && sitehub_is_subseq($cs, $cl)) return 0.85;
    if ($ns >= 6 && sitehub_char_subset($cs, $cl)) return 0.82;
    $d = sitehub_dice($ca, $cb);
    if ($p2 && $d >= 0.7) return min(0.84, $d);
    if ($loose && $p3 && $d >= 0.5) return 0.75;
    return 0.0;
}

/**
 * 매칭 사전 — 사이트명(w=1)과 slack_names(w=0)의 표기 하나하나. 보관 사이트 포함(보관된 사이트 이력도 그 사이트로 가야 한다).
 * $history: 'locked' 면 사람이 미매칭 시트에서 고른 이력의 표기도(같은 표기는 다음부터 저절로), 'all' 이면 이미 붙은 이력 표기 전부
 * (자동 정리 계획용 — '충북라이온스' 로 붙은 이력이 있으면 '라이온스충북지구' 도 그 사이트로). 작업 내용 같은 표기는 배우지 않는다.
 */
function sitehub_site_index(PDO $pdo, ?string $history = null): array {
    $idx = [];
    $sites = [];
    foreach ($pdo->query("SELECT id, name, slack_names FROM lab_sites") as $s) {
        $sites[(int)$s['id']] = $s['name'];
        foreach (array_merge([$s['name']], sitehub_split_list($s['slack_names'])) as $i => $n) {
            $w = $i === 0 ? 1 : 0;
            $k = sitehub_norm_name($n);
            if ($k !== '') $idx[] = ['k' => $k, 'id' => (int)$s['id'], 'name' => $s['name'], 'w' => $w];
            // '패스랩(목과슴)' → '패스랩' 처럼 괄호 안을 뺀 표기도
            $bare = sitehub_norm_name(preg_replace('/\([^)]*\)/u', '', $n));
            if ($bare !== $k && mb_strlen($bare) >= 3) $idx[] = ['k' => $bare, 'id' => (int)$s['id'], 'name' => $s['name'], 'w' => 0];
        }
    }
    if ($history) {
        $where = $history === 'locked' ? 'site_locked = 1' : 'site_id IS NOT NULL';
        $vote = [];
        foreach ($pdo->query("SELECT site_text, title, site_id, COUNT(*) n FROM lab_history
                              WHERE {$where} AND site_id IS NOT NULL AND archived = 0 AND parent_id IS NULL GROUP BY site_text, title, site_id") as $r) {
            $raw = trim((string)$r['site_text']) !== '' ? trim((string)$r['site_text']) : trim((string)$r['title']);
            if (sitehub_not_a_site($raw)) continue;
            $vote[sitehub_norm_name($raw)][(int)$r['site_id']] = ($vote[sitehub_norm_name($raw)][(int)$r['site_id']] ?? 0) + (int)$r['n'];
        }
        foreach ($vote as $k => $bySite) {
            arsort($bySite);
            $ids = array_keys($bySite);
            if (count($ids) > 1 && $bySite[$ids[0]] === $bySite[$ids[1]]) continue;   // 반반이면 배우지 않는다
            if ($k !== '' && isset($sites[$ids[0]])) $idx[] = ['k' => $k, 'id' => $ids[0], 'name' => $sites[$ids[0]], 'w' => 0];
        }
    }
    return $idx;
}

/** 표기 하나를 사전에서 찾는다 → ['id','name','score','key'] 또는 null (못 찾음·애매함) */
function sitehub_match_name(array $index, string $text, ?bool &$ambiguous = null): ?array {
    $ambiguous = false;
    $t = sitehub_norm_name($text);
    if ($t === '') return null;
    $best = [];   // site id → 최고 점수 후보 (같은 점수면 사이트 이름에서 온 것)
    foreach ($index as $e) {
        $sc = sitehub_name_score($t, $e['k']);
        if ($sc < SITEHUB_MATCH_MIN) continue;
        $w = (int)($e['w'] ?? 0);
        $cur = $best[$e['id']] ?? null;
        if (!$cur || $sc > $cur['score'] || ($sc === $cur['score'] && $w > $cur['w'])) {
            $best[$e['id']] = ['id' => $e['id'], 'name' => $e['name'], 'score' => $sc, 'key' => $e['k'], 'w' => $w];
        }
    }
    if (!$best) return null;
    usort($best, fn($a, $b) => $b['score'] <=> $a['score'] ?: $b['w'] <=> $a['w']);
    // 1등과 0.03 안쪽인 다른 사이트가 있으면 모른다 — 단 1등만 사이트 '이름'에서 왔고 나머지는 Slack 표기일 뿐이면 1등
    $close = array_filter(array_slice($best, 1), fn($b) => $best[0]['score'] - $b['score'] < 0.03);
    if ($close && !($best[0]['w'] === 1 && !array_filter($close, fn($b) => $b['w'] === 1))) { $ambiguous = true; return null; }
    return $best[0];
}

/** 업체명 칸에 사이트가 아니라 작업 내용이 적힌 행인가 → 이유 또는 null */
function sitehub_not_a_site(string $raw): ?string {
    $t = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($t === '' || $t === '(제목 없음)') return '업체명 칸이 비어 있음';
    if (mb_strlen($t) > 25 || strpos($raw, "\n") !== false || count(explode(' ', $t)) >= 4) return '업체명 칸에 작업 내용이 적혀 있음';
    if (preg_match('/->|https?:|@|%|\d{1,2}:\d{2}|^\s*(-|\d+\.|\d{4}-\d{2})/u', $t)) return '업체명 칸에 작업 내용이 적혀 있음';
    if (strpos($t, ' ') !== false && preg_match('/\d/', $t)) return '업체명 칸에 작업 내용이 적혀 있음';   // '교육가능인원 99에서 35로'
    if (preg_match('/(함|됨|했음|음|요청|문의|진행|추가|삭제|수정|변경|작업|연장|정렬|완료|확인|처리|설정|조절|차단|교체|오류|문제|필요|예정|의 건)\.?\)?$/u', $t)) {
        return '작업 내용으로 보임';
    }
    return null;
}

/**
 * 미매칭 이력 자동 정리 계획. 쓰지 않고 계획만 만든다(api.php 가 미리보기로 보여 주고, 적용 때 다시 만들어 그대로 쓴다).
 *  link   — 기존 사이트의 다른 표기: 그 사이트에 연결하고 표기를 slack_names 에 더한다
 *  create — 처음 보는 업체: 비슷한 표기끼리 묶어 사이트 하나로 새로 만든다(이름은 가장 많이 쓴 표기)
 *  skip   — 업체명 칸에 작업 내용이 적힌 행: 그대로 미매칭으로 둔다(미매칭 시트에서 사람이 고른다)
 */
function sitehub_auto_resolve_plan(PDO $pdo): array {
    // 하위 항목은 부모를 따라가므로 계획에서 뺀다(업체명 칸에 작업 내용이 적힌 것이 대부분)
    $rows = $pdo->query("SELECT id, site_text, title FROM lab_history WHERE site_id IS NULL AND site_locked = 0 AND archived = 0 AND parent_id IS NULL")->fetchAll();
    $names = [];   // 원문 표기 → 건수
    foreach ($rows as $r) {
        $raw = trim((string)$r['site_text']) !== '' ? trim((string)$r['site_text']) : trim((string)$r['title']);
        $names[$raw] = ($names[$raw] ?? 0) + 1;
    }
    arsort($names);

    $index = sitehub_site_index($pdo, 'all');
    $link = []; $rest = []; $skip = [];

    // 1) 기존 사이트 — 두 번 돈다: 처음 붙은 표기를 사전에 더하면 '라이온스충북지구' 처럼 그 표기를 거쳐야 붙는 것도 잡힌다
    $amb = [];
    for ($pass = 0; $pass < 2; $pass++) {
        $rest = [];
        foreach ($names as $raw => $n) {
            if (isset($link[$raw])) continue;
            $m = sitehub_match_name($index, (string)$raw, $a1);
            $a2 = false;
            if (!$m) {   // '충북대 (유지보수 모든부서)' — 괄호를 뺀 표기로 한 번 더
                $bare = trim(preg_replace('/\([^)]*\)/u', '', (string)$raw));
                if ($bare !== '' && $bare !== (string)$raw) $m = sitehub_match_name($index, $bare, $a2);
            }
            if (!$m && ($a1 || $a2)) { $amb[$raw] = $n; $rest[$raw] = $n; continue; }
            unset($amb[$raw]);
            if ($m) {
                $link[$raw] = ['name' => (string)$raw, 'n' => $n, 'site_id' => $m['id'], 'site_name' => $m['name'], 'score' => round($m['score'], 2)];
                $index[] = ['k' => sitehub_norm_name((string)$raw), 'id' => $m['id'], 'name' => $m['name'], 'w' => 0];
            } else {
                $rest[$raw] = $n;
            }
        }
    }

    // 2) 남은 것 — 작업 내용은 빼고, 비슷한 표기끼리 묶는다(많이 쓴 표기가 머리)
    $clusters = [];
    foreach ($rest as $raw => $n) {
        if (isset($amb[$raw])) { $skip[] = ['name' => (string)$raw, 'n' => $n, 'reason' => '여러 사이트에 걸치는 표기 — 미매칭 목록에서 골라 주세요']; continue; }
        $why = sitehub_not_a_site((string)$raw);
        if ($why) { $skip[] = ['name' => (string)$raw, 'n' => $n, 'reason' => $why]; continue; }
        $k = sitehub_norm_name((string)$raw);
        $bestI = null; $bestS = 0;
        foreach ($clusters as $i => $c) {
            foreach ($c['keys'] as $ck) {
                $sc = sitehub_name_score($k, $ck, true);
                if ($sc > $bestS || ($sc === $bestS && $bestI !== null && $c['n'] > $clusters[$bestI]['n'])) { $bestS = $sc; $bestI = $i; }
            }
        }
        if ($bestI !== null && $bestS >= SITEHUB_MATCH_MIN) {
            $clusters[$bestI]['variants'][] = ['name' => (string)$raw, 'n' => $n];
            $clusters[$bestI]['keys'][] = $k;
            $clusters[$bestI]['n'] += $n;
        } else {
            $clusters[] = ['name' => (string)$raw, 'n' => $n, 'variants' => [['name' => (string)$raw, 'n' => $n]], 'keys' => [$k]];
        }
    }
    // 한 건짜리 머리는 나중에 생긴 묶음에 붙을 수 있으면 붙인다 ('유네스코GCED' 가 'GCED' 보다 먼저 와서 따로 선 경우)
    foreach (array_keys($clusters) as $i) {
        if (!isset($clusters[$i])) continue;
        $c = $clusters[$i];
        if (count($c['variants']) !== 1 || $c['n'] > 1) continue;
        $bestJ = null; $bestS = 0;
        foreach ($clusters as $j => $d) {
            if ($j === $i) continue;
            foreach ($d['keys'] as $dk) { $sc = sitehub_name_score($c['keys'][0], $dk, true); if ($sc > $bestS) { $bestS = $sc; $bestJ = $j; } }
        }
        if ($bestJ !== null && $bestS >= SITEHUB_MATCH_MIN) {
            $clusters[$bestJ]['variants'][] = $c['variants'][0];
            $clusters[$bestJ]['keys'][] = $c['keys'][0];
            $clusters[$bestJ]['n'] += $c['n'];
            unset($clusters[$i]);
        }
    }
    $create = [];
    $known = array_flip(array_column($index, 'k'));
    foreach ($clusters as $c) {
        $dup = array_values(array_filter($c['variants'], fn($v) => isset($known[sitehub_norm_name($v['name'])])));
        if ($dup) {   // 이미 있는 표기인데 사이트가 둘 이상이라 못 붙은 것 — 새로 만들면 중복이다
            foreach ($c['variants'] as $v) $skip[] = ['name' => $v['name'], 'n' => $v['n'], 'reason' => '같은 표기를 쓰는 사이트가 둘 이상 — 미매칭 목록에서 골라 주세요'];
            continue;
        }
        usort($c['variants'], fn($a, $b) => $b['n'] <=> $a['n'] ?: mb_strlen($b['name']) <=> mb_strlen($a['name']));
        $create[] = ['name' => $c['variants'][0]['name'], 'n' => $c['n'], 'variants' => $c['variants']];
    }
    usort($create, fn($a, $b) => $b['n'] <=> $a['n']);
    $link = array_values($link);
    usort($link, fn($a, $b) => $b['n'] <=> $a['n']);

    return ['link' => $link, 'create' => $create, 'skip' => $skip,
            'total' => count($rows), 'counts' => ['link' => array_sum(array_column($link, 'n')), 'create' => array_sum(array_column($create, 'n')),
                                                  'skip' => array_sum(array_column($skip, 'n'))]];
}

/**
 * 계획 적용. $exclude 는 미리보기에서 사람이 뺀 표기(link 의 name, create 의 대표 name).
 * 연결·등록한 이력은 site_locked=0 으로 둔다 — 표기가 slack_names 에 들어갔으니 다음 동기화도 같은 사이트로 간다.
 */
function sitehub_auto_resolve_apply(PDO $pdo, array $exclude = [], ?array $me = null): array {
    $plan = sitehub_auto_resolve_plan($pdo);
    $ex = array_flip(array_map('strval', $exclude));
    $map = [];      // 원문 표기 → site_id
    $made = 0; $linked = 0;

    $pdo->beginTransaction();
    $getNames = $pdo->prepare("SELECT slack_names, name FROM lab_sites WHERE id = ?");
    $setNames = $pdo->prepare("UPDATE lab_sites SET slack_names = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
    $by = (string)($me['email'] ?? 'system');
    $namesField = ['key' => 'slack_names', 'label' => 'Slack 표기', 'type' => 'input'];
    $addName = function (int $siteId, array $variants, bool $fresh = false) use ($pdo, $getNames, $setNames, $by, $me, $namesField) {
        $getNames->execute([$siteId]);
        $cur = $getNames->fetch() ?: ['slack_names' => '', 'name' => ''];
        $before = (string)$cur['slack_names'];
        $list = sitehub_split_list($before);
        $have = array_map('sitehub_norm_name', $list);
        foreach ($variants as $v) {
            $v = str_replace(',', ' ', trim($v));
            if ($v === '' || in_array(sitehub_norm_name($v), $have, true)) continue;
            if (mb_strlen(implode(',', array_merge($list, [$v]))) > 500) break;   // 컬럼 길이 — 넘치면 더 안 넣는다
            $list[] = $v; $have[] = sitehub_norm_name($v);
        }
        $after = implode(',', $list);
        if ($after === $before) return;
        $setNames->execute([$after, $by, $siteId]);
        if (!$fresh) sitehub_audit($pdo, $me, $siteId, 'update', 'site', $siteId, (string)$cur['name'],
            sitehub_audit_diff($pdo, [$namesField], ['slack_names' => $before], ['slack_names' => $after]), 'Slack 미매칭 자동 정리');
    };

    foreach ($plan['link'] as $l) {
        if (isset($ex[$l['name']])) continue;
        $addName($l['site_id'], [$l['name']]);
        $map[$l['name']] = $l['site_id'];
        $linked++;
    }

    $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM lab_sites")->fetchColumn();
    $find = $pdo->prepare("SELECT id FROM lab_sites WHERE name = ? LIMIT 1");
    $ins  = $pdo->prepare("INSERT INTO lab_sites (name, customer, slack_names, note, sort_no, created_at, updated_at, updated_by) VALUES (?, '', '', ?, ?, NOW(), NOW(), ?)");
    foreach ($plan['create'] as $c) {
        if (isset($ex[$c['name']])) continue;
        $find->execute([$c['name']]);
        $id = (int)$find->fetchColumn();
        if (!$id) {
            $note = '[Slack 자동 등록] ' . date('Y-m-d') . ' 유지보수처리목록의 미매칭 이력 ' . $c['n'] . '건에서 만듦 — 표기: '
                  . implode(', ', array_map(fn($v) => $v['name'] . '(' . $v['n'] . ')', $c['variants']))
                  . "\nURL·접속정보·담당자·계약은 비어 있으니 채워 주세요.";
            $ins->execute([mb_substr($c['name'], 0, 200), $note, ++$sort, $by]);
            $id = (int)$pdo->lastInsertId();
            $made++;
            sitehub_audit($pdo, $me, $id, 'create', 'site', $id, mb_substr($c['name'], 0, 200), [], 'Slack 미매칭 자동 정리 — 이력 ' . $c['n'] . '건');
            $addName($id, array_column($c['variants'], 'name'), true);
        } else {
            $addName($id, array_column($c['variants'], 'name'));
        }
        foreach ($c['variants'] as $v) $map[$v['name']] = $id;
    }

    // 이력 행에 붙인다 — 계획을 만든 것과 같은 규칙(업체명 칸, 비면 제목)으로 원문 표기를 다시 만든다
    $upd = $pdo->prepare("UPDATE lab_history SET site_id = ? WHERE id = ? AND site_id IS NULL AND site_locked = 0");
    $rows = $pdo->query("SELECT id, site_text, title FROM lab_history WHERE site_id IS NULL AND site_locked = 0 AND archived = 0 AND parent_id IS NULL")->fetchAll();
    $assigned = 0;
    foreach ($rows as $r) {
        $raw = trim((string)$r['site_text']) !== '' ? trim((string)$r['site_text']) : trim((string)$r['title']);
        if (isset($map[$raw])) { $upd->execute([$map[$raw], $r['id']]); $assigned += $upd->rowCount(); }
    }
    sitehub_children_follow($pdo);
    $pdo->commit();
    return ['linked_names' => $linked, 'created_sites' => $made, 'assigned' => $assigned];
}

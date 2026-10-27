<?php
/**
 * '호스팅 정보_v.xlsx' → lab_sites + 하위 테이블 시딩. access/access_import.php 와 같은 역할.
 *
 *  CLI    : php sitehub/import_xlsx.php "xlsx경로" [--force] [--dry-run]
 *  브라우저: index.php 의 [엑셀 가져오기] 에서 파일 업로드 (포털 로그인 필요)
 *
 *  엑셀 구조
 *   - '!!전체목록' 시트: 코드(G01/C06…)·업체명·담당자·연락처·이메일·유지보수상태·계약만료일·비고 — 사이트 보강용
 *   - 사이트 시트 'G15-우진교통', 'C06-매경', '종료G04-…', 그 밖에 '바로텍'·'아이윈': A열 라벨(이름·url·서버정보·호스팅정보·
 *     ftp·db·관리자계정·도메인·개발서버…)과 B~E 값, F열 라벨(계약종료일·입금형태·계약담당자·기타)과 G~I 값
 *   - 나머지(카드결제내역·샘플·호스팅정보2020/2021·개발서버정보·리눅스명령어·(호스팅계약정보))는 건너뛴다
 *
 *  동작
 *   1) 시트 하나 = 사이트 하나. 같은 사이트명이 이미 있으면 건너뛰고(--force 면 그 사이트의 하위 행을 지우고 다시 넣는다)
 *   2) 자유 서술 칸은 "아이디 / 비번", 두 줄(아이디↵비번), URL, 포트를 가능한 만큼 가른다. 못 가른 글은 메모(note)에
 *      원문 그대로 남긴다 — 정보가 사라지지 않는 게 우선이고, 화면에서 고치면 된다
 *   3) 비밀번호는 sitehub_enc() 로 암호화해 넣는다
 *
 *  ※ 이 엑셀에는 전 고객사 계정/비밀번호가 그대로 들어 있다. 저장소(git)에 커밋하지 말 것.
 */
require_once __DIR__ . '/db.php';

$IS_CLI = PHP_SAPI === 'cli';

/* ── xlsx 읽기 (ZipArchive + SimpleXML, 외부 라이브러리 없음) ───────────────────────────── */

function sx_shared_strings(ZipArchive $zip) {
    $out = [];
    $s = $zip->getFromName('xl/sharedStrings.xml');
    if ($s === false) return $out;
    $xml = simplexml_load_string($s);
    foreach ($xml->si as $si) {
        if (isset($si->t) && !isset($si->r)) { $out[] = (string)$si->t; continue; }
        $t = '';
        foreach ($si->r as $r) $t .= (string)$r->t;
        $out[] = $t;
    }
    return $out;
}

function sx_sheet_targets(ZipArchive $zip) {
    $wb   = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $rmap = [];
    foreach ($rels->Relationship as $r) $rmap[(string)$r['Id']] = (string)$r['Target'];
    $out = [];
    foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        $tgt = ltrim($rmap[$rid] ?? '', '/');
        if ($tgt === '') continue;
        if (strpos($tgt, 'xl/') !== 0) $tgt = 'xl/' . $tgt;
        $out[(string)$sh['name']] = $tgt;
    }
    return $out;
}

/** 셀 서식 인덱스 → 날짜 서식인지. 만기일·계약종료일 같은 칸이 진짜 날짜(일련번호)로 들어 있어서 필요하다. */
function sx_date_styles(ZipArchive $zip) {
    $s = $zip->getFromName('xl/styles.xml');
    if ($s === false) return [];
    $xml = simplexml_load_string($s);
    $custom = [];
    if (isset($xml->numFmts)) {
        foreach ($xml->numFmts->numFmt as $f) $custom[(int)$f['numFmtId']] = (string)$f['formatCode'];
    }
    $isDate = function ($id) use ($custom) {
        if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47)) return true;
        $code = $custom[$id] ?? '';
        if ($code === '') return false;
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $code);   // 리터럴·조건은 뺀다
        return (bool)preg_match('/[yd]|m{2,}|h|s/i', $code) && !preg_match('/[#0]/', $code);
    };
    $out = [];
    if (isset($xml->cellXfs)) {
        $i = 0;
        foreach ($xml->cellXfs->xf as $xf) { $out[$i++] = $isDate((int)$xf['numFmtId']); }
    }
    return $out;
}

function sx_serial_to_date($n) {
    $n = (float)$n;
    if ($n < 1 || $n > 80000) return null;
    return date('Y-m-d', (int)round(($n - 25569) * 86400));
}

/** 한 시트를 [행번호][컬럼문자] => 값 으로 읽는다. 날짜 서식 셀은 'Y-m-d' 문자열로 바꾼다. */
function sx_read_sheet(ZipArchive $zip, array $shared, array $dateStyles, $file) {
    $x = simplexml_load_string($zip->getFromName($file));
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $rn = (int)$row['r'];
        foreach ($row->c as $cell) {
            $t = (string)$cell['t'];
            if ($t === 'inlineStr')   $val = (string)$cell->is->t;
            elseif ($t === 's')       $val = $shared[(int)(string)$cell->v] ?? '';
            elseif ($t === 'str' || $t === 'b' || $t === 'e') $val = (string)$cell->v;
            else {
                $val = (string)$cell->v;
                $st  = isset($cell['s']) ? (int)$cell['s'] : -1;
                if ($val !== '' && is_numeric($val) && !empty($dateStyles[$st])) {
                    $d = sx_serial_to_date($val);
                    if ($d) $val = $d;
                } elseif ($val !== '' && is_numeric($val) && strpos($val, 'E') !== false) {
                    $val = rtrim(rtrim(sprintf('%.10F', (float)$val), '0'), '.');
                }
            }
            $val = sx_clean($val);
            if ($val === '') continue;
            $rows[$rn][preg_replace('/[0-9]/', '', (string)$cell['r'])] = $val;
        }
    }
    return $rows;
}

function sx_clean($v) {
    $v = str_replace(["\r\n", "\r", '：', '／'], ["\n", "\n", ':', '/'], (string)$v);
    $v = preg_replace('/[ \t\x{00A0}]+\n/u', "\n", $v);
    $v = preg_replace('/\x{00A0}/u', ' ', $v);
    return trim($v);
}

/* ── 자유 서술 칸 가르기 ───────────────────────────────────────────────────────────────── */

const SX_PORT_RE = '/\(?\s*(?:포트|port|sftp|ssh)\s*[:：]?\s*(\d{2,5})\s*\)?/iu';

/**
 * "homepage / nemam4123   (포트 : 2022)", "cjhappyi↵happyi!772", "http://x/admin ↵admin / pw↵hadmin / pw2",
 * "아이디: a 비번: b" 같은 글에서 [url들, 호스트들, 포트, [[id,pw,prefix],…], 남는 글] 을 뽑는다.
 * 못 가른 줄은 전부 note 로 돌려주므로 정보가 사라지지는 않는다.
 */
function sx_parse_cred($text) {
    $out = ['urls' => [], 'hosts' => [], 'port' => '', 'pairs' => [], 'note' => []];
    $text = (string)$text;
    if (trim($text) === '') return $out;

    if (preg_match_all('~https?://[^\s,()]+~iu', $text, $m)) {
        foreach ($m[0] as $u) { $out['urls'][] = rtrim($u, '.,;'); }
        $text = preg_replace('~https?://[^\s,()]+~iu', ' ', $text);
    }
    if (preg_match(SX_PORT_RE, $text, $m)) {
        $out['port'] = $m[1];
        $text = preg_replace(SX_PORT_RE, ' ', $text, 1);
    }

    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
    $pending = null;   // 두 줄짜리(아이디↵비번) 대기
    $flushPending = function () use (&$pending, &$out) {
        if ($pending !== null) { $out['note'][] = $pending; $pending = null; }
    };
    foreach ($lines as $line) {
        // 호스트/IP 만 적힌 줄 (cjhappyi.cafe24.com, 211.181.136.106, /home/homepage 는 아님)
        if (preg_match('~^(?:[a-z0-9-]+\.)+[a-z]{2,}$|^\d{1,3}(?:\.\d{1,3}){3}$~i', $line)) {
            $flushPending();
            $out['hosts'][] = $line;
            continue;
        }
        // 아이디: x / 비번: y 라벨형
        if (preg_match('~(?:아이디|ID|계정|user(?:name)?)\s*[:=]\s*(\S+)~iu', $line, $a) &&
            preg_match('~(?:비번|비밀번호|PW|pass(?:word)?)\s*[:=]\s*(\S+)~iu', $line, $p)) {
            $flushPending();
            $out['pairs'][] = [$a[1], $p[1], ''];
            continue;
        }
        // "id / pw" 또는 "id/pw" — 왼쪽에 설명이 붙어 있으면(최고관리자 admin/pw) 마지막 토큰만 아이디
        if (preg_match('~^(.*?)\s*/\s*(\S+)\s*(.*)$~u', $line, $m) && trim($m[1]) !== '' && strpos($m[1], ' / ') === false) {
            $left = trim($m[1]);
            $toks = preg_split('/\s+/', $left);
            $id   = array_pop($toks);
            $prefix = trim(implode(' ', $toks));
            $tail = trim($m[3]);
            if ($id !== '' && !preg_match('~^[/.]~', $id)) {
                $flushPending();
                $out['pairs'][] = [$id, $m[2], trim($prefix . ($tail !== '' ? ' ' . $tail : ''))];
                continue;
            }
        }
        // 줄 안의 IP 는 호스트 후보로도 뽑아 둔다 (설명 줄에 섞여 있는 경우: "… uws7-167 | 210.114.6.139")
        if (preg_match_all('~\b\d{1,3}(?:\.\d{1,3}){3}\b~', $line, $ips)) foreach ($ips[0] as $ip) $out['hosts'][] = $ip;
        // 공백 없는 한 토큰 줄 두 개가 이어지면 아이디↵비번 (경로·URL 조각은 제외)
        if (!preg_match('/\s/', $line) && !preg_match('~[/\\\\]~', $line) && mb_strlen($line) <= 60) {
            if ($pending === null) { $pending = $line; continue; }
            $out['pairs'][] = [$pending, $line, ''];
            $pending = null;
            continue;
        }
        $flushPending();
        $out['note'][] = $line;
    }
    $flushPending();
    return $out;
}

/** 라벨 블록 → 접속정보 행들. 값이 하나도 안 갈리면 메모만 든 행 하나. */
function sx_accounts_from($kind, $label, $text, $defaultHost = '') {
    $p = sx_parse_cred($text);
    $host = $p['urls'][0] ?? ($p['hosts'][0] ?? $defaultHost);
    $extraHosts = array_slice(array_merge($p['urls'], $p['hosts']), 1);
    $note = array_merge($p['note'], $extraHosts ? ['그 외 주소: ' . implode(', ', $extraHosts)] : []);
    $rows = [];
    if ($p['pairs']) {
        foreach ($p['pairs'] as $i => [$id, $pw, $prefix]) {
            $rows[] = ['kind' => $kind, 'label' => $label . ($prefix !== '' ? " ({$prefix})" : ($i > 0 ? ' ' . ($i + 1) : '')),
                       'host' => $host, 'port' => $p['port'], 'login_id' => $id, 'login_pw' => $pw,
                       'note' => $i === 0 ? implode("\n", $note) : ''];
        }
    } elseif ($host !== '' || $note) {
        $rows[] = ['kind' => $kind, 'label' => $label, 'host' => $host, 'port' => $p['port'], 'login_id' => '', 'login_pw' => '',
                   'note' => implode("\n", $note)];
    }
    return $rows;
}

/** '2023-12-03', '2022.05.01', '22.12.30', '2024.1.14', '5.31'(연도 없음→null) */
function sx_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (preg_match('/^(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})/', $raw, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (preg_match('/^(\d{2})[.\/](\d{1,2})[.\/](\d{1,2})$/', $raw, $m)) return sprintf('20%02d-%02d-%02d', $m[1], $m[2], $m[3]);
    return null;
}

function sx_billing($raw) {
    $r = (string)$raw;
    if (preg_match('/연납|^연$/u', $r))         return '연';
    if (preg_match('/월납|매월|^월$|월단위/u', $r)) return '월';
    if (preg_match('/일시납|선납/u', $r))       return '선납';
    if (preg_match('/건별/u', $r))             return '건별';
    if (preg_match_all('/\d{1,2}\s*월?/u', $r, $m) && preg_match('/청구/u', $r)) {
        $n = count($m[0]);
        if ($n >= 4) return '분기';
        if ($n == 2) return '반기';
        if ($n == 1) return '연';
    }
    return '';
}

/** 담당자 "장두환 팀장", "김수지간사", "최일준 대표" → [이름, 직책] */
function sx_person($raw) {
    $raw = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    if ($raw === '') return ['', ''];
    if (preg_match('/^(.+?)\s*(팀장|대리|과장|차장|부장|대표|간사|원장|전문관|사회복지사|사무국장|연구원|주임|실장|국장|센터장|이사|사원|선생님|교수|조교|직원|담당자?)(\(.*\))?$/u', $raw, $m)) {
        return [trim($m[1]), trim($m[2] . ($m[3] ?? ''))];
    }
    return [$raw, ''];
}

/* ── 시트 → 사이트 ─────────────────────────────────────────────────────────────────────── */

const SX_ROOT_XLSX = '호스팅 정보_v.xlsx';   // 레포 루트에 두면 업로드 없이 가져올 수 있다 (git 에 올리지 말 것 — .gitignore 에 있다)
const SX_SKIP_SHEETS = ['!!전체목록', '카드결제내역2021', '샘플', '호스팅 정보2021', '호스팅정보2020', '개발서버정보', '리눅스명령어 모음', '(호스팅계약정보)'];
const SX_NAMED_SITE_SHEETS = ['바로텍', '아이윈'];

function sx_is_site_sheet($name) {
    if (in_array($name, SX_SKIP_SHEETS, true)) return false;
    if (in_array($name, SX_NAMED_SITE_SHEETS, true)) return true;
    return (bool)preg_match('/^(종료)?[GC]\d+/u', $name);
}

/** '!!전체목록' → [코드 => 행] */
function sx_master(array $rows) {
    $out = [];
    foreach ($rows as $r) {
        $code = trim($r['A'] ?? '');
        if (!preg_match('/^[GC]\d+$/', $code)) continue;
        $out[$code] = ['name' => trim($r['B'] ?? ''), 'person' => trim($r['D'] ?? ''), 'phone' => trim($r['E'] ?? ''),
                       'email' => trim($r['F'] ?? ''), 'status' => trim($r['G'] ?? ''), 'end' => trim($r['H'] ?? ''), 'note' => trim($r['I'] ?? '')];
    }
    return $out;
}

/** 라벨 행에서 B~E(또는 G~I)의 값을 줄바꿈으로 이어 붙인다 */
function sx_vals(array $row, array $cols) {
    $v = [];
    foreach ($cols as $c) if (isset($row[$c]) && $row[$c] !== '') $v[] = $row[$c];
    return implode("\n", $v);
}

function sx_norm_label($s) {
    return mb_strtolower(preg_replace('/[\s\x{00A0}]+/u', '', (string)$s));
}

/**
 * 사이트 시트 하나 → ['site'=>[...], 'accounts'=>[...], 'contacts'=>[...], 'contracts'=>[...]]
 */
function sx_build_site($sheetName, array $rows, array $master) {
    $closed = strpos($sheetName, '종료') === 0;
    $code = ''; $title = $sheetName;
    if (preg_match('/^(?:종료)?([GC]\d+)-?(.*)$/u', $sheetName, $m)) { $code = $m[1]; $title = trim($m[2]) ?: $sheetName; }
    $mrow = $master[$code] ?? null;

    // A열 라벨 → 값. 같은 라벨이 두 번이면 이어 붙인다. F열(계약 블록)은 1~14행만.
    $A = []; $F = [];
    foreach ($rows as $rn => $row) {
        if ($rn > 60) continue;
        $la = isset($row['A']) ? sx_norm_label($row['A']) : '';
        if ($la !== '') {
            $v = sx_vals($row, ['B', 'C', 'D', 'E']);
            if ($v !== '') $A[$la] = isset($A[$la]) ? $A[$la] . "\n" . $v : $v;
            $A['__label_' . $la] = trim(str_replace("\n", ' ', $row['A']));
            if (!isset($A['__B_' . $la]) && isset($row['B'])) $A['__B_' . $la] = $row['B'];
        }
        if ($rn <= 14 && isset($row['F'])) {
            $lf = sx_norm_label($row['F']);
            $F[$rn] = ['label' => $lf, 'G' => $row['G'] ?? '', 'H' => $row['H'] ?? '', 'I' => $row['I'] ?? '', 'raw' => $row['F']];
        }
    }
    $get = fn(...$keys) => (function () use ($A, $keys) { foreach ($keys as $k) if (isset($A[$k]) && $A[$k] !== '') return $A[$k]; return ''; })();

    // 이름은 B열만 본다 — D1 에는 '정보 수정일' 날짜가 들어 있다
    $name = trim(preg_split('/\n/', (string)($A['__B_이름'] ?? ''))[0] ?? '');
    if ($name === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) $name = $title;
    $customer = $mrow['name'] ?? $name;
    $siteNote = []; $deploy = []; $accounts = []; $contacts = []; $contracts = [];
    $used = ['이름' => 1];
    $mark = function (...$ks) use (&$used) { foreach ($ks as $k) $used[$k] = 1; };

    // url
    $url = sx_parse_cred($get('url'));
    $urlOps = $url['urls'][0] ?? (!empty($url['hosts'][0]) ? 'http://' . $url['hosts'][0] : '');
    $rest = array_merge(array_slice($url['urls'], 1), array_slice($url['hosts'], $url['urls'] ? 0 : 1), $url['note']);
    if ($rest) $siteNote[] = "[url]\n" . implode("\n", $rest);
    $mark('url');

    // 서버정보 (+ 실서버 줄) → ssh 접속 + 스택
    $server = trim($get('실서버') . "\n" . $get('서버정보'));
    $stack = [];
    if (preg_match('/php\s*([\d.]+)/i', $server, $m)) $stack[] = 'PHP ' . $m[1];
    if (preg_match('/(mariadb|mysql)[-\s]*([\d.x]*)/i', $server, $m)) $stack[] = trim($m[1] . ' ' . $m[2]);
    if ($get('cms타입') !== '') $stack[] = str_replace("\n", ' ', $get('cms타입'));
    $hosting = '';
    foreach ([$server, $get('호스팅정보'), $get('호스팅서비스정보'), $get('호스팅업체정보'), $get('카페24서비스정보'), $get('구입처')] as $t) {
        if ($hosting === '' && preg_match('/cafe24|카페24/iu', $t)) $hosting = '카페24';
        if ($hosting === '' && preg_match('/gabia|가비아/iu', $t)) $hosting = '가비아';
    }
    if ($hosting === '' && preg_match('/211\.181\.136\.106|블루\s*호스팅|blue\s*hosting/iu', $server . $get('호스팅정보'))) $hosting = '블루소프트 서버';
    if ($server !== '') {
        // 카페24 같은 호스팅 서비스 정보가 '서버정보' 칸에 적힌 사이트가 많다 — 그건 ssh 가 아니라 호스팅 계정이다
        $isHosting = (bool)preg_match('/cafe24|카페24|가비아|gabia|호스팅/iu', $server);
        foreach (sx_accounts_from($isHosting ? 'hosting' : 'ssh', $isHosting ? '호스팅 (서버정보)' : '실서버', $server) as $r) $accounts[] = $r;
    }
    $mark('실서버', '서버정보', 'cms타입');

    // 호스팅 계열
    $hostingHost = '';
    foreach (['호스팅정보' => '호스팅', '호스팅서비스정보' => '호스팅 서비스', '호스팅업체정보' => '호스팅 업체', '카페24서비스정보' => '카페24 서비스'] as $k => $label) {
        if ($get($k) === '') continue;
        foreach (sx_accounts_from('hosting', $label, $get($k)) as $r) { $accounts[] = $r; if ($hostingHost === '' && $r['host'] !== '') $hostingHost = $r['host']; }
        $mark($k);
    }
    // ftp · db · 관리자
    if ($get('ftp') !== '') { foreach (sx_accounts_from('ftp', 'FTP', $get('ftp'), $hostingHost) as $r) $accounts[] = $r; $mark('ftp'); }
    foreach (['db' => 'DB', 'db정보' => 'DB'] as $k => $label) {
        if ($get($k) === '' || $k === 'db정보') continue;   // db정보는 개발서버 쪽
        foreach (sx_accounts_from('db', $label, $get($k), $hostingHost) as $r) $accounts[] = $r; $mark($k);
    }
    $urlAdmin = '';
    foreach (['관리자계정' => '사이트 관리자', '관리자(staff)계정' => '관리자(STAFF)', 'cms계정' => 'CMS 계정', '사용자테스트계정' => '사용자 테스트 계정'] as $k => $label) {
        if ($get($k) === '') continue;
        foreach (sx_accounts_from($k === '사용자테스트계정' ? 'etc' : 'admin', $label, $get($k)) as $r) {
            $accounts[] = $r;
            if ($urlAdmin === '' && preg_match('~^https?://~', $r['host'])) $urlAdmin = $r['host'];
        }
        $mark($k);
    }
    // 도메인 블록
    $dom = trim($get('구입처'));
    $domAcct = $get('계정');
    $domNote = [];
    if ($get('만기일') !== '') $domNote[] = '만기일 ' . str_replace("\n", ' ', $get('만기일'));
    if ($get('결제유무') !== '') $domNote[] = '결제유무 ' . str_replace("\n", ' ', $get('결제유무'));
    if ($get('도메인') !== '') $domNote[] = str_replace("\n", ' ', $get('도메인'));
    if ($dom !== '' || $domAcct !== '' || $domNote) {
        $rows2 = sx_accounts_from('domain', '도메인' . ($dom !== '' ? " ({$dom})" : ''), $domAcct, $dom);
        if (!$rows2) $rows2 = [['kind' => 'domain', 'label' => '도메인' . ($dom !== '' ? " ({$dom})" : ''), 'host' => $dom, 'port' => '', 'login_id' => '', 'login_pw' => '', 'note' => '']];
        $rows2[0]['note'] = trim($rows2[0]['note'] . "\n" . implode(' · ', $domNote));
        foreach ($rows2 as $r) $accounts[] = $r;
    }
    $mark('도메인', '구입처', '계정', '만기일', '결제유무');

    // 개발서버 블록
    $devUrlRaw = sx_parse_cred($get('개발서버url') . "\n" . $get('개발서버'));
    $urlDev = $devUrlRaw['urls'][0] ?? (!empty($devUrlRaw['hosts'][0]) ? 'http://' . $devUrlRaw['hosts'][0] : '');
    $devNote = array_merge(array_slice($devUrlRaw['urls'], 1), $devUrlRaw['note']);
    if ($get('개발서버관리자계정') !== '') {
        foreach (sx_accounts_from('admin', '개발서버 관리자', $get('개발서버관리자계정'), $urlDev) as $r) $accounts[] = $r;
    }
    if ($get('db정보') !== '') { foreach (sx_accounts_from('db', '개발 DB', $get('db정보')) as $r) $accounts[] = $r; }
    // svn 칸: 주소는 저장소 칸으로, 같이 적힌 계정은 접속정보로
    $repo = '';
    if ($get('svn') !== '') {
        $sv = sx_parse_cred($get('svn'));
        $svnUrls = [];
        if (preg_match_all('~\bsvn(?:\+ssh)?://\S+|\bgit@\S+|\S+\.git\b~iu', $get('svn'), $m)) $svnUrls = $m[0];
        $repo = implode("\n", array_unique(array_merge($svnUrls, $sv['urls'], $sv['note'])));
        foreach ($sv['pairs'] as $i => [$id, $pw, $prefix]) {
            $accounts[] = ['kind' => 'etc', 'label' => 'SVN' . ($prefix !== '' ? " ({$prefix})" : ''), 'host' => $svnUrls[0] ?? ($sv['hosts'][0] ?? ''),
                           'port' => $sv['port'], 'login_id' => $id, 'login_pw' => $pw, 'note' => ''];
        }
    }
    if ($get('호스트파일') !== '') $deploy[] = "[호스트파일]\n" . $get('호스트파일');
    if ($devNote) $deploy[] = "[개발서버]\n" . implode("\n", $devNote);
    foreach (['운영적용방법', '운영매뉴얼', '유지보수매뉴얼', '운영접속정보', '운영서버정보'] as $k) {
        if ($get($k) !== '') { $deploy[] = '[' . $A['__label_' . $k] . "]\n" . $get($k); $mark($k); }
    }
    $mark('개발서버url', '개발서버', '개발서버관리자계정', 'db정보', 'svn', '호스트파일');

    // 그 밖의 계정류 라벨은 etc 접속정보로, 설명 라벨은 사이트 메모로
    foreach ($A as $k => $v) {
        if (strpos($k, '__') === 0 || isset($used[$k]) || $v === '') continue;
        $label = $A['__label_' . $k] ?? $k;
        if (preg_match('/기타|메모|비고|정보$/u', $k) && !preg_match('/계정|account|api|ssl|sms|vimeo|youtube|문자|폴더/iu', $k)) {
            $siteNote[] = "[{$label}]\n{$v}";
            continue;
        }
        $rows2 = sx_accounts_from('etc', $label, $v);
        if ($rows2) foreach ($rows2 as $r) $accounts[] = $r;
        else $siteNote[] = "[{$label}]\n{$v}";
    }

    // 계약 블록 (F열). 2행: 계약종료일 G2 / 비용 I2, 3행: 입금형태 G3 / 구분 I3, 4행: 계약담당자 G4(H4 연락처), 5행: 기타 G5
    $endRaw = ''; $amountRaw = ''; $billingRaw = ''; $kindRaw = ''; $cNote = []; $cPerson = '';
    foreach ($F as $rn => $f) {
        $l = $f['label'];
        if ($l === '유지보수계약정보' || $l === '호스팅계약정보' || $l === 'cdn계약정보') continue;
        if (strpos($l, '계약종료일') !== false) { $endRaw = $f['G']; if (preg_match('/비용|금액/u', $f['H'])) $amountRaw = $f['I']; continue; }
        if (preg_match('/입금|청구/u', $l)) { $billingRaw = $f['G']; if (preg_match('/구분/u', $f['H'])) $kindRaw = $f['I']; continue; }
        if (preg_match('/담당자/u', $l)) { $cPerson = trim($f['G'] . ' ' . $f['H'] . ' ' . $f['I']); continue; }
        if ($l === '기타') { $v = trim($f['G'] . "\n" . $f['H'] . "\n" . $f['I']); if ($v !== '') $cNote[] = $v; continue; }
        if ($rn >= 6) { $v = trim($f['raw'] . ' ' . $f['G'] . ' ' . $f['H'] . ' ' . $f['I']); if ($v !== '' && strpos($f['raw'], '정보수정 후') === false) $cNote[] = $v; }
    }
    $end = sx_date($mrow['end'] ?? '') ?: sx_date($endRaw);
    foreach (array_filter([$mrow['end'] ?? '', $endRaw]) as $raw) {
        if (sx_date($raw) === null) $cNote[] = '종료일 원문: ' . $raw;
    }
    $amount = null;
    if (preg_match('/[\d,]+/', str_replace(' ', '', $amountRaw), $m)) { $n = (int)str_replace(',', '', $m[0]); if ($n > 0) $amount = $n; }
    $billing = sx_billing($billingRaw) ?: sx_billing($mrow['note'] ?? '');
    $status = $mrow['status'] ?? '';
    $kind = 'annual';
    $monthly = preg_match('/^월$|월납|매월|월단위/u', $billingRaw) || preg_match('/월납|매월|월단위|^월\s*[\d,]+$/u', trim(($mrow['end'] ?? '') . ' ' . ($mrow['note'] ?? '')));
    if (preg_match('/무상/u', $kindRaw . $status)) $kind = 'free';
    elseif ($monthly) $kind = 'monthly';
    if ($status === '중단' || $closed) { if ($end === null && $amount === null) $kind = 'none'; }
    if ($status !== '' && $status !== '계약') $cNote[] = '유지보수상태(전체목록): ' . $status;
    if (($mrow['note'] ?? '') !== '') $cNote[] = $mrow['note'];
    if ($kindRaw !== '') $cNote[] = '구분: ' . $kindRaw;
    if ($end !== null || $amount !== null || $cNote || $kindRaw !== '') {
        $contracts[] = ['kind' => $kind, 'start_date' => null, 'end_date' => $end, 'amount' => $amount, 'amount_unit' => 'year',   // 계약 금액은 항상 1년 기준 — 월 납부라도 월 금액이 아니다
                        'vat_included' => $amount !== null ? 1 : 0, 'billing' => $billing, 'doc_url' => '', 'note' => implode("\n", array_unique($cNote))];
    }

    // 담당자: 전체목록 행 + 시트의 계약담당자
    $seen = [];
    $addContact = function ($raw, $phone = '', $email = '', $note = '') use (&$contacts, &$seen) {
        [$nm, $dept] = sx_person($raw);
        if ($nm === '') return;
        $key = sx_norm_label(preg_split('/\s+/u', $nm)[0]);   // '김수정 사무' 와 '김수정' 은 같은 사람
        if (isset($seen[$key])) {
            $i = $seen[$key];
            if ($contacts[$i]['mobile'] === '' && $phone !== '') $contacts[$i]['mobile'] = $phone;
            if ($contacts[$i]['email'] === '' && $email !== '') $contacts[$i]['email'] = $email;
            return;
        }
        $seen[$key] = count($contacts);
        $isMobile = (bool)preg_match('/^01\d/', str_replace([' ', '-'], '', $phone));
        $contacts[] = ['name' => $nm, 'dept' => $dept, 'role' => 'work', 'phone' => $isMobile ? '' : $phone, 'mobile' => $isMobile ? $phone : '', 'email' => $email, 'note' => $note];
    };
    if ($mrow && $mrow['person'] !== '') $addContact($mrow['person'], $mrow['phone'], $mrow['email']);
    elseif ($mrow && ($mrow['phone'] !== '' || $mrow['email'] !== '')) $addContact('담당자', $mrow['phone'], $mrow['email']);
    if ($cPerson !== '') {
        $phone = ''; $email = '';
        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $cPerson, $m)) { $email = $m[0]; $cPerson = str_replace($m[0], '', $cPerson); }
        if (preg_match_all('/(0\d{1,2}[-\s]?\d{3,4}[-\s]?\d{4}|\d{3}[-\s]?\d{4}(?:~\d)?)/', $cPerson, $m)) {
            $phones = array_map('trim', $m[1]);
            $cPerson = str_replace($m[1], '', $cPerson);
            $mobiles = array_values(array_filter($phones, fn($p) => preg_match('/^01\d/', str_replace([' ', '-'], '', $p))));
            $lands   = array_values(array_diff($phones, $mobiles));
            $phone = $mobiles[0] ?? ($lands[0] ?? '');
            if ($mobiles && $lands) $cPerson .= ' ';   // 둘 다 있으면 아래 addContact 가 하나만 받으므로 나머지는 메모로
            $extra = array_slice($phones, 1);
        } else { $extra = []; }
        $cPerson = trim(preg_replace('~[/,]+|\s{2,}~', ' ', $cPerson));
        if ($cPerson !== '') $addContact($cPerson, $phone, $email, trim('계약담당자' . ($extra ? ' · 그 외 연락처 ' . implode(', ', $extra) : '')));
    }

    // 사이트 메모 머리말: 시트 코드와 전체목록 메모
    array_unshift($siteNote, '[엑셀] 시트 ' . $sheetName . ($mrow ? ' · 전체목록 ' . $code : ''));

    $slackNames = array_values(array_unique(array_filter([$name, $customer, $title])));
    $site = [
        'name' => $name, 'customer' => $customer,
        'url_ops' => $urlOps, 'url_admin' => $urlAdmin, 'url_dev' => $urlDev,
        'hosting' => $hosting, 'stack' => mb_substr(implode(', ', array_unique($stack)), 0, 200),   // 쉼표 구분 — 화면 필터가 값 하나하나로 거른다 'repo' => $repo,
        'slack_names' => implode(',', $slackNames), 'our_staff' => '', 'monitor' => 0,
        'deploy' => implode("\n\n", $deploy), 'backup' => '', 'caution' => '', 'note' => implode("\n\n", $siteNote),
    ];
    // 전체목록에서 중단이거나 시트가 종료 표시면 보관함으로 — lab_sites 화이트리스트 밖이라 site 와 따로 둔다
    $archive = ($status === '중단' || $closed) ? ('[엑셀] ' . ($status === '중단' ? '전체목록 상태 중단' : '시트 종료 표시')) : '';
    return ['site' => $site, 'archive' => $archive, 'accounts' => $accounts, 'contacts' => $contacts, 'contracts' => $contracts];
}

/* ── 적재 ───────────────────────────────────────────────────────────────────────────────── */

function sitehub_import_xlsx($path, $force = false, $dryRun = false) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('xlsx 를 열 수 없습니다: ' . $path);
    $shared  = sx_shared_strings($zip);
    $dstyles = sx_date_styles($zip);
    $targets = sx_sheet_targets($zip);
    if (!isset($targets['!!전체목록'])) throw new RuntimeException("'!!전체목록' 시트가 없습니다 — 호스팅 정보 엑셀이 맞는지 확인하세요.");
    $master = sx_master(sx_read_sheet($zip, $shared, $dstyles, $targets['!!전체목록']));

    $built = []; $skippedSheets = [];
    foreach ($targets as $name => $file) {
        if (!sx_is_site_sheet($name)) { $skippedSheets[] = $name; continue; }
        $rows = sx_read_sheet($zip, $shared, $dstyles, $file);
        $built[$name] = sx_build_site($name, $rows, $master);
    }
    $zip->close();

    $res = ['sheets' => count($built), 'inserted' => 0, 'replaced' => 0, 'archived' => 0, 'skipped' => [], 'accounts' => 0, 'contacts' => 0, 'contracts' => 0,
            'skipped_sheets' => $skippedSheets, 'per' => []];
    if ($dryRun) {
        foreach ($built as $sheet => $b) $res['per'][$sheet] = ['site' => $b['site']['name'], 'accounts' => count($b['accounts']), 'contacts' => count($b['contacts']), 'contracts' => count($b['contracts']), 'data' => $b];
        return $res;
    }

    $pdo = sitehub_db();
    $find = $pdo->prepare("SELECT id FROM lab_sites WHERE name = ?");
    $cols = sitehub_cols();
    $sel  = implode(',', array_map(fn($c) => "`$c`", $cols));
    $ph   = implode(',', array_fill(0, count($cols), '?'));
    $insSite = $pdo->prepare("INSERT INTO lab_sites ({$sel}, sort_no, created_at, updated_at, updated_by) VALUES ({$ph}, ?, NOW(), NOW(), 'import:xlsx')");
    $updSite = $pdo->prepare("UPDATE lab_sites SET " . implode(',', array_map(fn($c) => "`$c`=?", $cols)) . ", updated_at=NOW(), updated_by='import:xlsx' WHERE id=?");
    $insAcc  = $pdo->prepare("INSERT INTO lab_site_accounts (site_id, kind, label, host, port, login_id, login_pw, note, sort_no, created_at, updated_by) VALUES (?,?,?,?,?,?,?,?,?,NOW(),'import:xlsx')");
    $insCon  = $pdo->prepare("INSERT INTO lab_site_contacts (site_id, name, dept, role, phone, mobile, email, note, sort_no, created_at, updated_by) VALUES (?,?,?,?,?,?,?,?,?,NOW(),'import:xlsx')");
    $insCtr  = $pdo->prepare("INSERT INTO lab_site_contracts (site_id, kind, start_date, end_date, amount, amount_unit, vat_included, billing, doc_url, note, created_at, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),'import:xlsx')");
    // 보관은 새로 넣는 사이트에만 건다 — --force 로 다시 읽을 때 사람이 복원/보관한 상태를 엑셀이 뒤집지 않게
    $archSite = $pdo->prepare("UPDATE lab_sites SET archived=1, archived_at=NOW(), archive_note=? WHERE id=?");
    $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM lab_sites")->fetchColumn();
    $importer = ['email' => 'import:xlsx', 'name' => '엑셀 가져옴'];

    $pdo->beginTransaction();
    foreach ($built as $sheet => $b) {
        $s = $b['site'];
        $find->execute([$s['name']]);
        $id = (int)$find->fetchColumn();
        if ($id > 0 && !$force) { $res['skipped'][] = $s['name']; continue; }
        $vals = array_map(fn($c) => $s[$c], $cols);
        if ($id > 0) {
            $updSite->execute(array_merge($vals, [$id]));
            foreach (['lab_site_accounts', 'lab_site_contacts', 'lab_site_contracts'] as $t) $pdo->prepare("DELETE FROM `{$t}` WHERE site_id=?")->execute([$id]);
            $res['replaced']++;
            sitehub_audit($pdo, $importer, $id, 'import', 'site', $id, (string)$s['name'], [], "엑셀 다시 가져오기(--force) — 시트 '{$sheet}', 접속정보·담당자·계약을 엑셀 내용으로 바꿈");
        } else {
            $insSite->execute(array_merge($vals, [++$sort]));
            $id = (int)$pdo->lastInsertId();
            $res['inserted']++;
            sitehub_audit($pdo, $importer, $id, 'import', 'site', $id, (string)$s['name'], [], "엑셀 가져오기 — 시트 '{$sheet}'");
            if ($b['archive'] !== '') { $archSite->execute([$b['archive'], $id]); $res['archived']++; }
        }
        foreach ($b['accounts'] as $i => $a) {
            $insAcc->execute([$id, $a['kind'], mb_substr($a['label'], 0, 120), mb_substr($a['host'], 0, 500), mb_substr($a['port'], 0, 10),
                              mb_substr($a['login_id'], 0, 200), sitehub_enc($a['login_pw']), $a['note'], $i + 1]);
        }
        foreach ($b['contacts'] as $i => $c) {
            $insCon->execute([$id, mb_substr($c['name'], 0, 100), mb_substr($c['dept'], 0, 120), $c['role'], mb_substr($c['phone'], 0, 40), mb_substr($c['mobile'], 0, 40), mb_substr($c['email'], 0, 200), $c['note'], $i + 1]);
        }
        foreach ($b['contracts'] as $c) {
            $insCtr->execute([$id, $c['kind'], $c['start_date'], $c['end_date'], $c['amount'], $c['amount_unit'], $c['vat_included'], $c['billing'], $c['doc_url'], $c['note']]);
        }
        $res['accounts'] += count($b['accounts']); $res['contacts'] += count($b['contacts']); $res['contracts'] += count($b['contracts']);
        $res['per'][$sheet] = ['site' => $s['name'], 'accounts' => count($b['accounts']), 'contacts' => count($b['contacts']), 'contracts' => count($b['contracts'])];
    }
    $pdo->commit();
    return $res;
}

/* ── 진입점 ─────────────────────────────────────────────────────────────────────────────── */

// api.php 가 첫 화면 시딩용으로 require 하면 여기서 끝낸다 (함수만 쓴다)
if (defined('SITEHUB_IMPORT_LIB')) return;
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__ && !$IS_CLI) return;

if ($IS_CLI) {
    $args  = array_slice($argv, 1);
    $force = in_array('--force', $args, true);
    $dry   = in_array('--dry-run', $args, true);
    $paths = array_values(array_filter($args, fn($a) => strpos($a, '--') !== 0));
    $path  = $paths[0] ?? (__DIR__ . '/../호스팅 정보_v.xlsx');
    if (!is_file($path)) { fwrite(STDERR, "파일이 없습니다: {$path}\n"); exit(1); }
    try {
        $r = sitehub_import_xlsx($path, $force, $dry);
        if ($dry && ($i = array_search('--dump', $args, true)) !== false) {
            $want = $args[$i + 1] ?? '';
            foreach ($r['per'] as $sheet => $p) {
                if ($want !== '' && strpos($sheet, $want) === false) continue;
                $d = $p['data'];
                foreach ($d['accounts'] as &$a) $a['login_pw'] = $a['login_pw'] === '' ? '' : '***' . strlen($a['login_pw']);
                unset($a);
                echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
            }
            exit(0);
        }
        if ($dry) {
            foreach ($r['per'] as $sheet => $p) printf("%-28s → %-18s 접속 %2d · 담당자 %d · 계약 %d\n", $sheet, $p['site'], $p['accounts'], $p['contacts'], $p['contracts']);
            echo "건너뛴 시트: " . implode(', ', $r['skipped_sheets']) . "\n";
            exit(0);
        }
        printf("시트 %d · 신규 %d (보관 %d) · 교체 %d · 건너뜀 %d (%s)\n접속정보 %d · 담당자 %d · 계약 %d\n",
            $r['sheets'], $r['inserted'], $r['archived'], $r['replaced'], count($r['skipped']), implode(', ', $r['skipped']), $r['accounts'], $r['contacts'], $r['contracts']);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

require_once __DIR__ . '/guard.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_portal_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    if (!empty($_POST['use_root'])) {
        $root = dirname(__DIR__) . '/' . SX_ROOT_XLSX;
        if (!is_file($root)) throw new Exception('레포 루트에 ' . SX_ROOT_XLSX . ' 가 없습니다.');
        $r = sitehub_import_xlsx($root, !empty($_POST['force']), false);
        unset($r['per']);
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $f = $_FILES['xlsx'] ?? null;
    // 7MB 짜리 엑셀이라 PHP 기본 upload_max_filesize(2M) 에 걸린다 — 왜 안 되는지 한도와 함께 알려 준다
    if (!$f && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new Exception('업로드가 post_max_size(' . ini_get('post_max_size') . ') 를 넘었습니다. php.ini 의 post_max_size · upload_max_filesize 를 올리거나 CLI 로 돌리세요: php sitehub/import_xlsx.php "파일.xlsx"');
    }
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) throw new Exception('xlsx 파일을 올려 주세요.');
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new Exception('파일이 upload_max_filesize(' . ini_get('upload_max_filesize') . ') 보다 큽니다. php.ini 를 올리거나 CLI 로 돌리세요: php sitehub/import_xlsx.php "파일.xlsx"');
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new Exception('업로드 오류 (코드 ' . $f['error'] . ')');
    $r = sitehub_import_xlsx($f['tmp_name'], !empty($_POST['force']), false);
    unset($r['per']);
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

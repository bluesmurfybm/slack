<?php
/**
 * 유지보수 내역서 — 사이트마다 고객사에 내던 한글 내역서(.hwpx)를 '양식'으로 올려 두면,
 * 기간을 골라 그 기간의 정기 점검 줄과 Slack 이력을 그 양식 그대로 채워 내려준다.
 *
 *  - 양식(lab_report_templates, 사이트당 하나, 파일은 var/sitehub/reports/tpl/): 올릴 때 표를 읽어
 *    '수정일시 | 세부내용' 머리 행 아래를 내용 행으로, '일자 · 유지보수대상 · 점검자 · 비고' 이름표 옆 칸을 값 칸으로 본다.
 *    내용 행의 첫 줄을 복제해 새 행을 만들므로 글꼴·테두리·칸 너비는 양식 그대로다.
 *    예전 .hwp 는 묶음(zip+XML)이 아니라 채울 수 없다 — 한글에서 .hwpx 로 다시 저장해 올린다.
 *  - 채우기 규칙(settings): 정기 점검 요일·문구·결과, 달마다 마지막 점검 문구, Slack 이력 상태·기준일.
 *    처음 값은 양식의 내용 행에서 뽑는다(sitehub_report_analyze). 사람이 고친 값은 그대로 둔다.
 *  - 만든 내역서(lab_reports): 기간·행(JSON)·파일. 파일은 var/sitehub/reports/out/ 에 .hwpx 와 같은 이름의 .pdf(report_pdf.php) 두 개.
 *  - BLUMO 장애가 그 점검일 사이에 있었으면 '이상없음' 대신 장애를 적고 미리보기에서 표시한다 — 사람이 보고 고친다.
 * 한글 파일은 ZipArchive·DOM 만으로 다룬다(운영 서버에 vendor/ 를 깔지 않는다).
 */

const SITEHUB_REPORT_MAX_BYTES = 20 * 1024 * 1024;
const SITEHUB_REPORT_DEFAULTS = [
    'weekday'     => 5,                                 // 정기 점검 요일 (ISO 1=월 … 7=일, 0=정기 점검 줄 없음)
    'mon_text'    => '- 모니터링',
    'mon_result'  => '이상없음',
    'month_on'    => 1,                                 // 달마다 마지막 점검일은 month_text 로
    'month_text'  => '- 모니터링 및 서버 정기점검',
    'work_result' => '완료',                            // Slack 이력 줄의 결과 (결과 칸이 없는 양식이면 비운다)
    'hist_status' => 'done',                            // done=처리완료만, open=취소·보류만 빼고 전부
    'hist_date'   => 'done',                            // done=처리일(없으면 요청일), req=요청일
    'incidents'   => 1,                                 // BLUMO 장애를 결과에 반영
    'holiday_shift' => 1,                               // 정기 점검일이 공휴일·주말이면 그 전 평일로(안 되면 다음 평일)
    'font'        => 'pretendard',                      // hwpx 글꼴: pretendard=Pretendard 로 통일, template=양식 그대로 (PDF 는 늘 Pretendard)
    'title_grow'  => 4,                                 // 제목 글자를 양식보다 몇 pt 키울지 (0=양식 그대로). 제목 조각들은 가장 큰 크기에 맞춘다
    'inline_sep'  => ' : ',                             // 결과 칸이 없는 양식에서 첫 줄 뒤에 붙이는 구분
    'date_fmt'    => ['sep1' => '. ', 'sep2' => '. ', 'tail' => '', 'pad' => 1],
    'target'      => '',                                // 유지보수대상 칸
    'inspector'   => '',                                // 점검자 칸
    'remark'      => '',                                // 비고 칸
];
const SITEHUB_REPORT_WEEKDAYS = [0 => '없음', 1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'];

/* ── 저장 위치 ─────────────────────────────────────────────────────────────────── */

/** var/sitehub/reports/{tpl|out} — var/sitehub/.htaccess 가 웹에서 막는다 */
function sitehub_report_dir(string $sub): string {
    $d = dirname(__DIR__) . '/var/sitehub/reports/' . $sub;
    if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) throw new Exception("저장 폴더를 만들 수 없습니다: var/sitehub/reports/{$sub} (웹서버 계정의 쓰기 권한)");
    return $d;
}

/* ── hwpx 읽기 · 쓰기 ──────────────────────────────────────────────────────────── */

function sh_hx_kids(DOMNode $n, string $local): array {
    $o = [];
    foreach ($n->childNodes as $c) if ($c instanceof DOMElement && $c->localName === $local) $o[] = $c;
    return $o;
}

function sh_hx_kid(DOMNode $n, string $local): ?DOMElement {
    foreach ($n->childNodes as $c) if ($c instanceof DOMElement && $c->localName === $local) return $c;
    return null;
}

function sh_hx_norm(string $s): string {
    return preg_replace('/\s+/u', '', $s) ?? $s;
}

/** <hp:t> 하나의 글 — 줄바꿈·탭 요소는 글자로 */
function sh_hx_t_text(DOMElement $t): string {
    $s = '';
    foreach ($t->childNodes as $c) {
        if ($c instanceof DOMText) $s .= $c->data;
        elseif ($c instanceof DOMElement) $s .= match ($c->localName) { 'lineBreak' => "\n", 'tab' => "\t", 'nbSpace', 'fwSpace' => ' ', default => '' };
    }
    return $s;
}

/** 문단의 <hp:t> 들 — 문단 안에 든 표(다른 run 의 tbl)는 들어가지 않는다 */
function sh_hx_para_ts(DOMElement $p): array {
    $o = [];
    foreach (sh_hx_kids($p, 'run') as $r) foreach (sh_hx_kids($r, 't') as $t) $o[] = $t;
    return $o;
}

function sh_hx_para_text(DOMElement $p): string {
    return implode('', array_map('sh_hx_t_text', sh_hx_para_ts($p)));
}

function sh_hx_cell_paras(DOMElement $tc): array {
    $sub = sh_hx_kid($tc, 'subList');
    return $sub ? sh_hx_kids($sub, 'p') : [];
}

function sh_hx_cell_text(DOMElement $tc): string {
    return implode("\n", array_map('sh_hx_para_text', sh_hx_cell_paras($tc)));
}

/** run 이 표·그림·컨트롤을 품고 있나 — 그런 run 에는 글을 넣지 않는다 */
function sh_hx_run_has_object(DOMElement $run): bool {
    foreach ($run->childNodes as $c) if ($c instanceof DOMElement && $c->localName !== 't') return true;
    return false;
}

/**
 * 문단 글을 통째로 바꾼다. 글이 있던 첫 run 의 글자 모양을 쓰고, 다른 run 의 글은 지운다(표·컨트롤은 그대로).
 * 줄 배치 캐시(linesegarray)는 지워 한글이 다시 계산하게 한다 — 남겨 두면 글자가 겹쳐 보인다.
 */
function sh_hx_para_set(DOMElement $p, string $text): void {
    $doc = $p->ownerDocument;
    $runs = sh_hx_kids($p, 'run');
    $target = null;
    foreach ($runs as $r) {
        foreach (sh_hx_kids($r, 't') as $t) {
            if (!$target) $target = $r;
            $r->removeChild($t);
        }
    }
    if (!$target) {
        foreach ($runs as $r) if (!sh_hx_run_has_object($r)) { $target = $r; break; }
    }
    if (!$target) {
        $target = $doc->createElementNS($p->namespaceURI, ($p->prefix ? $p->prefix . ':' : '') . 'run');
        $target->setAttribute('charPrIDRef', $runs ? ($runs[0]->getAttribute('charPrIDRef') ?: '0') : '0');
        $ls = sh_hx_kid($p, 'linesegarray');
        $ls ? $p->insertBefore($target, $ls) : $p->appendChild($target);
    }
    $t = $doc->createElementNS($p->namespaceURI, ($p->prefix ? $p->prefix . ':' : '') . 't');
    $t->appendChild($doc->createTextNode(str_replace(["\r", "\n", "\t"], ['', ' ', ' '], $text)));
    $target->appendChild($t);
    foreach (sh_hx_kids($p, 'linesegarray') as $l) $p->removeChild($l);
}

/** 문단 안에서 정규식에 맞는 곳만 바꾼다 — 한 <t> 안에서 맞으면 그 자리만(다른 글자 모양 유지), 걸쳐 있으면 문단을 다시 쓴다 */
function sh_hx_para_replace(DOMElement $p, string $re, callable $fn): bool {
    foreach (sh_hx_para_ts($p) as $t) {
        $s = sh_hx_t_text($t);
        $hasEl = false;
        foreach ($t->childNodes as $c) if ($c instanceof DOMElement) { $hasEl = true; break; }
        if (!$hasEl && preg_match($re, $s)) {
            while ($t->firstChild) $t->removeChild($t->firstChild);
            $t->appendChild($t->ownerDocument->createTextNode(preg_replace_callback($re, $fn, $s, 1)));
            foreach (sh_hx_kids($p, 'linesegarray') as $l) $p->removeChild($l);
            return true;
        }
    }
    $full = sh_hx_para_text($p);
    if (!preg_match($re, $full)) return false;
    sh_hx_para_set($p, preg_replace_callback($re, $fn, $full, 1));
    return true;
}

/** 칸의 글을 줄 목록으로 — 첫 문단을 본으로 줄마다 문단 하나 */
function sh_hx_cell_set(DOMElement $tc, array $lines): void {
    $sub = sh_hx_kid($tc, 'subList');
    if (!$sub) return;
    $ps = sh_hx_kids($sub, 'p');
    if (!$ps) return;
    $proto = $ps[0];
    foreach (array_slice($ps, 1) as $p) $sub->removeChild($p);
    $lines = array_values($lines) ?: [''];
    $clean = $proto->cloneNode(true);
    sh_hx_para_set($proto, (string)$lines[0]);
    $prev = $proto;
    foreach (array_slice($lines, 1) as $line) {
        $p = $clean->cloneNode(true);
        sh_hx_para_set($p, (string)$line);
        $prev->nextSibling ? $sub->insertBefore($p, $prev->nextSibling) : $sub->appendChild($p);
        $prev = $p;
    }
}

/** 칸 첫 줄 앞의 띄움(공백) */
function sh_hx_lead(DOMElement $tc): string {
    $ps = sh_hx_cell_paras($tc);
    return $ps && preg_match('/^[ \x{3000}]+/u', sh_hx_para_text($ps[0]), $m) ? $m[0] : '';
}

function sh_hx_indent(array $lines, string $lead): array {
    if ($lead === '') return $lines;
    return array_map(fn($l) => $l !== '' && !preg_match('/^\s/u', $l) ? $lead . $l : $l, $lines);
}

/**
 * 글꼴을 하나로 — header.xml 의 한글·영문·한자·일어·기타·사용자 글꼴 이름을 바꾼다(기호 글꼴은 그대로).
 * 글꼴 파일은 넣지 않으므로 그 글꼴이 없는 PC 의 한글은 비슷한 글꼴로 바꿔 보여 준다.
 */
function sh_hx_set_font(DOMDocument $h, string $face): void {
    foreach ((new DOMXPath($h))->query('//*[local-name()="fontface"]') as $ff) {
        if (!in_array($ff->getAttribute('lang'), ['HANGUL', 'LATIN', 'HANJA', 'JAPANESE', 'OTHER', 'USER'], true)) continue;
        foreach (sh_hx_kids($ff, 'font') as $f) {
            $f->setAttribute('face', $face);
            $f->setAttribute('type', 'TTF');
            $f->setAttribute('isEmbedded', '0');
            foreach (sh_hx_kids($f, 'substFont') as $sub) $f->removeChild($sub);
        }
    }
}

function sh_hx_header(ZipArchive $zip): ?DOMDocument {
    $xml = $zip->getFromName('Contents/header.xml');
    if ($xml === false) return null;
    $d = new DOMDocument();
    $d->preserveWhiteSpace = true;
    return @$d->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE) ? $d : null;
}

/** 제목 글자 크기(pt) — 글이 든 run 들의 글자 모양 중 가장 큰 것 */
function sh_hx_title_size(DOMDocument $h, DOMElement $p): ?float {
    $xp = new DOMXPath($h);
    $max = null;
    foreach (sh_hx_kids($p, 'run') as $r) {
        if (!sh_hx_kids($r, 't')) continue;
        $c = $xp->query('//*[local-name()="charPr"][@id="' . (int)$r->getAttribute('charPrIDRef') . '"]')->item(0);
        if ($c) $max = max($max ?? 0, (int)$c->getAttribute('height') / 100);
    }
    return $max;
}

/** 제목이 들어갈 폭(pt) — 표 칸 안이면 칸 너비에서 안쪽 여백을 뺀 것, 아니면 본문 폭 */
function sh_hx_title_room(DOMElement $p): ?float {
    $tc = $p;
    while ($tc && !($tc instanceof DOMElement && $tc->localName === 'tc')) $tc = $tc->parentNode;
    if ($tc) {
        $sz = sh_hx_kid($tc, 'cellSz');
        $tbl = $tc->parentNode ? $tc->parentNode->parentNode : null;
        $m = $tc->getAttribute('hasMargin') === '1' ? sh_hx_kid($tc, 'cellMargin') : ($tbl instanceof DOMElement ? sh_hx_kid($tbl, 'inMargin') : null);
        return $sz ? ((int)$sz->getAttribute('width') - ($m ? (int)$m->getAttribute('left') + (int)$m->getAttribute('right') : 1020)) / 100 : null;
    }
    $pp = null;
    foreach ($p->ownerDocument->getElementsByTagNameNS('*', 'pagePr') as $e) { $pp = $e; break; }
    $mg = $pp ? sh_hx_kid($pp, 'margin') : null;
    return $pp && $mg ? ((int)$pp->getAttribute('width') - (int)$mg->getAttribute('left') - (int)$mg->getAttribute('right')) / 100 : null;
}

/**
 * 제목을 키운다 — 제목 run 들의 글자 모양을 복제해 크기를 (가장 큰 크기 + $grow pt)로 맞춘 새 글자 모양을 붙인다.
 * 한 줄에 안 들어가면(Pretendard 폭으로 잰다) 들어갈 때까지 1pt 씩 줄인다(양식 크기 밑으로는 안 내린다).
 * 양식의 다른 글이 같은 글자 모양을 쓰고 있을 수 있어 원래 것은 고치지 않는다.
 */
function sh_hx_grow_title(DOMDocument $h, DOMElement $p, int $grow): ?float {
    $size = sh_hx_title_size($h, $p);
    if ($size === null || $grow <= 0) return $size;
    $xp = new DOMXPath($h);
    $props = $xp->query('//*[local-name()="charProperties"]')->item(0);
    if (!$props) return $size;
    $want = $size + $grow;
    $room = sh_hx_title_room($p);
    if ($room) {
        require_once __DIR__ . '/report_pdf.php';
        $bold = false;
        foreach (sh_hx_kids($p, 'run') as $r) {
            $c = $xp->query('//*[local-name()="charPr"][@id="' . (int)$r->getAttribute('charPrIDRef') . '"]')->item(0);
            if ($c && $c->getElementsByTagNameNS('*', 'bold')->length) $bold = true;
        }
        $f = function_exists('mb_ord') ? ShTtf::load(SH_PDF_FONT_DIR . '/Pretendard-' . ($bold ? 'Bold' : 'Regular') . '.ttf') : null;
        if ($f) {
            $em = $f->width(sh_hx_para_text($p));
            while ($want > $size && $em * $want > $room * 0.98) $want -= 1;
        }
    }
    if ($want <= $size) return $size;
    $target = (int)round($want * 100);
    $nextId = 0;
    foreach (sh_hx_kids($props, 'charPr') as $c) $nextId = max($nextId, (int)$c->getAttribute('id') + 1);
    $made = [];
    foreach (sh_hx_kids($p, 'run') as $r) {
        if (!sh_hx_kids($r, 't')) continue;
        $old = (int)$r->getAttribute('charPrIDRef');
        if (!isset($made[$old])) {
            $src = $xp->query('//*[local-name()="charPr"][@id="' . $old . '"]')->item(0);
            if (!$src) continue;
            $copy = $src->cloneNode(true);
            $copy->setAttribute('id', (string)$nextId);
            $copy->setAttribute('height', (string)$target);
            $props->appendChild($copy);
            $made[$old] = $nextId++;
        }
        $r->setAttribute('charPrIDRef', (string)$made[$old]);
    }
    $props->setAttribute('itemCnt', (string)count(sh_hx_kids($props, 'charPr')));
    return $target / 100;
}

/** hwpx 를 연다 → [zip, [이름 => DOMDocument]] (본문 구역만) */
function sitehub_hwpx_open(string $path, int $flags = ZipArchive::RDONLY): array {
    if (!class_exists('ZipArchive')) throw new Exception('PHP zip 확장이 없어 한글 파일을 열 수 없습니다 (php-zip 설치 필요).');
    if (!class_exists('DOMDocument')) throw new Exception('PHP dom 확장이 없어 한글 파일을 열 수 없습니다 (php-xml 설치 필요).');
    $zip = new ZipArchive();
    if ($zip->open($path, $flags) !== true) throw new Exception('한글 파일(.hwpx)을 열 수 없습니다 — 예전 .hwp 라면 한글에서 .hwpx 로 다시 저장해 올리세요.');
    $mime = trim((string)$zip->getFromName('mimetype'));
    if ($mime !== 'application/hwp+zip') { $zip->close(); throw new Exception('한글 표준 문서(.hwpx)가 아닙니다.'); }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if (preg_match('#^Contents/section(\d+)\.xml$#', $n, $m)) $names[(int)$m[1]] = $n;
    }
    ksort($names);
    if (!$names) { $zip->close(); throw new Exception('한글 파일에 본문(section)이 없습니다.'); }
    $docs = [];
    foreach ($names as $n) {
        $d = new DOMDocument();
        $d->preserveWhiteSpace = true;
        if (!@$d->loadXML((string)$zip->getFromName($n), LIBXML_NONET | LIBXML_PARSEHUGE)) { $zip->close(); throw new Exception("한글 파일의 {$n} 을 읽지 못했습니다."); }
        $docs[$n] = $d;
    }
    return [$zip, $docs];
}

/**
 * 내역서 표 찾기 — 날짜 이름표(수정일시·일자·날짜)와 '내용' 이 같은 행에 있는 표.
 * → [tbl, rows, header, start, end] 내용 행은 rows[start..end-1], end 는 '비고' 행(없으면 행 수)
 */
function sh_hx_find_table(array $docs): ?array {
    foreach ($docs as $name => $doc) {
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*[local-name()="tbl"]') as $tbl) {
            $rows = sh_hx_kids($tbl, 'tr');
            foreach ($rows as $i => $tr) {
                $cells = array_map(fn($tc) => sh_hx_norm(sh_hx_cell_text($tc)), sh_hx_kids($tr, 'tc'));
                if (count($cells) < 2) continue;
                $dateCol = false;
                foreach ($cells as $c) if (preg_match('/^(수정|작업|점검|처리)?(일시|일자|날짜|일)$/u', $c)) { $dateCol = true; break; }
                $bodyCol = false;
                foreach ($cells as $c) if (mb_strpos($c, '내용') !== false || mb_strpos($c, '내역') !== false) { $bodyCol = true; break; }
                if (!$dateCol || !$bodyCol) continue;
                $start = $i + 1;
                $end = count($rows);
                for ($j = $start; $j < count($rows); $j++) {
                    $first = sh_hx_norm(sh_hx_cell_text(sh_hx_kids($rows[$j], 'tc')[0] ?? $rows[$j]));
                    if (preg_match('/^(비고|특이사항|기타)$/u', $first)) { $end = $j; break; }
                }
                return ['section' => $name, 'doc' => $doc, 'tbl' => $tbl, 'rows' => $rows, 'header' => $i, 'start' => $start, 'end' => $end];
            }
        }
    }
    return null;
}

/** 이름표 칸 옆의 값 칸 — 내용 행 위(머리 영역)와 비고 행에서만 찾는다 */
function sh_hx_label_cell(array $T, string $re, bool $tail = false): ?DOMElement {
    if ($tail ? $T['end'] >= count($T['rows']) : $T['header'] < 1) return null;
    foreach ($tail ? range($T['end'], count($T['rows']) - 1) : range(0, $T['header'] - 1) as $i) {
        $cells = sh_hx_kids($T['rows'][$i], 'tc');
        foreach ($cells as $k => $tc) {
            if (preg_match($re, sh_hx_norm(sh_hx_cell_text($tc))) && isset($cells[$k + 1])) return $cells[$k + 1];
        }
    }
    return null;
}

/** 내역 표 위쪽이 아닌 다른 표(머리 칸만 따로 그린 양식)에서도 이름표를 찾는다 */
function sh_hx_label_any(array $docs, array $T, string $re): ?DOMElement {
    if ($c = sh_hx_label_cell($T, $re)) return $c;
    foreach ($docs as $doc) {
        foreach ((new DOMXPath($doc))->query('//*[local-name()="tbl"]') as $tbl) {
            if ($tbl === $T['tbl']) continue;
            foreach (sh_hx_kids($tbl, 'tr') as $tr) {
                $cells = sh_hx_kids($tr, 'tc');
                foreach ($cells as $k => $tc) if (isset($cells[$k + 1]) && preg_match($re, sh_hx_norm(sh_hx_cell_text($tc)))) return $cells[$k + 1];
            }
        }
    }
    return null;
}

/** 제목 문단 — 'YYYY년 M월 ~ M월' 기간이 든 문단(유지보수·내역 글이 있는 것 먼저) */
const SH_HX_PERIOD_RE = '/(\d{4})\s*년\s*(\d{1,2})\s*월(?:\s*(\d{1,2})\s*일)?\s*[~∼～\-–]\s*(?:(\d{4})\s*년\s*)?(\d{1,2})\s*월(?:\s*(\d{1,2})\s*일)?/u';

function sh_hx_title_para(array $docs, ?array $T): ?DOMElement {
    $skip = [];
    if ($T) for ($i = $T['start']; $i < $T['end']; $i++) $skip[spl_object_id($T['rows'][$i])] = true;
    $cands = [];
    foreach ($docs as $doc) {
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*[local-name()="p"]') as $p) {
            $tr = $p;
            while ($tr && !($tr instanceof DOMElement && $tr->localName === 'tr')) $tr = $tr->parentNode;
            if ($tr && isset($skip[spl_object_id($tr)])) continue;
            $text = sh_hx_para_text($p);
            if (!preg_match(SH_HX_PERIOD_RE, $text)) continue;
            $score = (mb_strpos($text, '유지보수') !== false ? 2 : 0) + (mb_strpos($text, '내역') !== false ? 1 : 0);
            $cands[] = [$score, $p];
        }
    }
    if (!$cands) return null;
    usort($cands, fn($a, $b) => $b[0] <=> $a[0]);
    return $cands[0][1];
}

/* ── 양식 읽기 ─────────────────────────────────────────────────────────────────── */

/** 날짜 글자의 모양 — '2026. 06. 05' → 구분자·0 채움을 기억해 같은 모양으로 쓴다 */
function sitehub_report_date_fmt(string $s): ?array {
    if (!preg_match('/^\s*(\d{4})(\D{1,3})(\d{1,2})(\D{1,3})(\d{1,2})(.*)$/u', $s, $m)) return null;
    $tail = trim($m[6]);
    return ['sep1' => $m[2], 'sep2' => $m[4], 'tail' => preg_match('/^\.?$/u', $tail) ? $tail : (preg_match('/[월화수목금토일]/u', $tail) ? '(요일)' : ''),
            'pad' => strlen($m[3]) === 2 ? 1 : 0];
}

function sitehub_report_fmt_date(string $ymd, array $f): string {
    $t = strtotime($ymd);
    if (!$t) return $ymd;
    $pad = !empty($f['pad']);
    $tail = (string)($f['tail'] ?? '');
    if ($tail === '(요일)') $tail = '(' . ['일', '월', '화', '수', '목', '금', '토'][(int)date('w', $t)] . ')';
    return date('Y', $t) . ($f['sep1'] ?? '. ') . ($pad ? date('m', $t) : date('n', $t)) . ($f['sep2'] ?? '. ') . ($pad ? date('d', $t) : date('j', $t)) . $tail;
}

/** 칸 글에서 날짜(Y-m-d)를 읽는다 — '2026. 06. 05', '2026-06-05', '2026년 6월 5일' */
function sitehub_report_parse_date(string $s): ?string {
    if (preg_match('/(\d{4})\s*(?:[.\-\/]|년)\s*(\d{1,2})\s*(?:[.\-\/]|월)\s*(\d{1,2})/u', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    return null;
}

/**
 * 양식 분석 — 찾은 것(표 모양·이름표 값·견본 행)과 처음 채우기 규칙.
 * 못 찾으면 사람이 알아들을 이유로 예외.
 */
function sitehub_report_analyze(string $path): array {
    [$zip, $docs] = sitehub_hwpx_open($path);
    $head = sh_hx_header($zip);
    $zip->close();
    $T = sh_hx_find_table($docs);
    if (!$T) throw new Exception("양식에서 내역 표를 찾지 못했습니다 — '수정일시 | 세부내용' 처럼 날짜와 내용 이름표가 한 행에 있는 표가 있어야 합니다.");
    if ($T['start'] >= $T['end']) throw new Exception('내역 표의 머리 행 아래에 내용 행이 한 줄도 없습니다 — 견본으로 한 줄 이상 남겨 두고 올리세요.');
    $headCells = sh_hx_kids($T['rows'][$T['header']], 'tc');
    $tplCells = sh_hx_kids($T['rows'][$T['start']], 'tc');
    $cols = count($tplCells);
    if ($cols < 2) throw new Exception('내용 행에 칸이 하나뿐입니다 — 날짜 칸과 내용 칸이 있어야 합니다.');
    $hasResult = $cols >= 3;

    $samples = [];
    for ($i = $T['start']; $i < $T['end']; $i++) {
        $cells = sh_hx_kids($T['rows'][$i], 'tc');
        if (count($cells) < 2) continue;
        $date = trim(sh_hx_cell_text($cells[0]));
        $lines = array_values(array_filter(array_map('trim', explode("\n", sh_hx_cell_text($cells[1]))), fn($l) => $l !== ''));
        $result = count($cells) >= 3 ? trim(sh_hx_cell_text($cells[count($cells) - 1])) : '';
        $samples[] = ['date_text' => $date, 'date' => sitehub_report_parse_date($date), 'lines' => $lines, 'result' => $result];
    }

    $s = SITEHUB_REPORT_DEFAULTS;
    $fmt = null;
    foreach ($samples as $r) if ($fmt = sitehub_report_date_fmt($r['date_text'])) break;
    if ($fmt) $s['date_fmt'] = $fmt;

    // 결과 칸이 없는 양식: '- 모니터링 : 이상없음' 처럼 첫 줄 끝에 붙어 있다
    $sep = ' : ';
    $split = function (string $line) use (&$sep, $hasResult): array {
        if ($hasResult) return [$line, ''];
        if (preg_match('/^(.*?)(\s*[:：]\s*)(이상\s*없음|정상|완료|처리\s*완료|이상\s*있음.*)$/u', $line, $m)) { $sep = $m[2]; return [$m[1], $m[3]]; }
        return [$line, ''];
    };
    $mon = []; $monRes = []; $wd = []; $month = null; $workRes = [];
    foreach ($samples as $r) {
        if (!$r['lines']) continue;
        [$first, $inl] = $split($r['lines'][0]);
        $res = $hasResult ? $r['result'] : $inl;
        $all = implode(' ', $r['lines']);
        if (mb_strpos($all, '모니터링') !== false) {
            if ($r['date']) $wd[] = (int)date('N', strtotime($r['date']));
            if (preg_match('/정기\s*점검/u', $all)) {
                if ($month === null) {
                    $lines = $r['lines'];
                    $lines[0] = $first;
                    // 용량 같은 숫자는 달마다 다르다 — 빈칸으로 두고 미리보기에서 채운다
                    $month = array_map(fn($l) => preg_replace('/\d[\d,\.]*/u', '____', $l), $lines);
                }
            } else {
                $mon[] = $first;
            }
            if ($res !== '') $monRes[] = $res;
        } elseif ($res !== '') {
            $workRes[] = $res;
        }
    }
    $mode = function (array $a) { if (!$a) return null; $c = array_count_values($a); arsort($c); return (string)array_key_first($c); };
    if ($wd) $s['weekday'] = (int)$mode(array_map('strval', $wd));
    if ($m = $mode($mon)) $s['mon_text'] = $m;
    if ($m = $mode($monRes)) $s['mon_result'] = $m;
    if ($month) { $s['month_text'] = implode("\n", $month); $s['month_on'] = 1; }
    $s['work_result'] = $hasResult ? ($mode($workRes) ?? '완료') : '';
    $s['inline_sep'] = $sep;

    $field = function (string $re, bool $tail = false) use ($T, $docs): ?string {
        $c = $tail ? sh_hx_label_cell($T, $re, true) : sh_hx_label_any($docs, $T, $re);
        return $c ? trim(sh_hx_cell_text($c)) : null;
    };
    $dateText = $field('/^(작성)?(일자|일시|날짜|작성일)$/u');
    $target = $field('/^(유지보수)?(대상|사이트|홈페이지|사이트주소|주소|url)$/iu');
    $inspector = $field('/^(점검자|작성자|담당자|점검)$/u');
    $remark = $field('/^(비고|특이사항|기타)$/u', true);
    $s['target'] = (string)$target;
    $s['inspector'] = (string)$inspector;
    $s['remark'] = (string)$remark;

    $titleP = sh_hx_title_para($docs, $T);
    $titleSize = $titleP && $head ? sh_hx_title_size($head, $titleP) : null;
    $title = $titleP ? trim(sh_hx_para_text($titleP)) : '';
    $period = null;
    if ($title && preg_match(SH_HX_PERIOD_RE, $title, $m)) {
        $period = ['from' => sprintf('%04d-%02d', $m[1], $m[2]), 'to' => sprintf('%04d-%02d', $m[4] !== '' && $m[4] !== null ? $m[4] : $m[1], $m[5])];
        if ($period['to'] < $period['from']) $period['to'] = sprintf('%04d-%02d', (int)$m[1] + 1, $m[5]);
    }

    return [
        'analysis' => [
            'title' => $title, 'title_size' => $titleSize, 'period' => $period, 'columns' => $cols, 'has_result' => $hasResult,
            'head' => array_map(fn($tc) => trim(preg_replace('/\s+/u', ' ', sh_hx_cell_text($tc))), $headCells),
            'sample_rows' => count($samples), 'samples' => array_slice($samples, 0, 6),
            'fields' => ['date' => $dateText, 'target' => $target, 'inspector' => $inspector, 'remark' => $remark],
        ],
        'settings' => $s,
    ];
}

/* ── 채우기 ─────────────────────────────────────────────────────────────────────── */

/** 'YYYY년 M월 ~ M월' 기간 글 — 양식이 끝 연도·일까지 적었으면 같은 모양으로 */
function sitehub_report_period_text(array $m, string $from, string $to): string {
    $f = strtotime($from); $t = strtotime($to);
    $endYear = ($m[4] ?? '') !== '' || date('Y', $f) !== date('Y', $t);
    $days = ($m[3] ?? '') !== '';
    return date('Y', $f) . '년 ' . date('n', $f) . '월' . ($days ? ' ' . date('j', $f) . '일' : '')
         . ' ~ ' . ($endYear ? date('Y', $t) . '년 ' : '') . date('n', $t) . '월' . ($days ? ' ' . date('j', $t) . '일' : '');
}

/**
 * 양식에 채워 새 파일로 — $r: from, to(Y-m-d), report_date, target, inspector, remark, rows[{date, lines[], result}], settings
 * 내용 행은 양식의 첫 내용 행을 복제한다. 행 번호(rowAddr)·행 수(rowCnt)는 다시 매긴다.
 */
function sitehub_report_fill(string $tplPath, string $outPath, array $r): array {
    if (!@copy($tplPath, $outPath)) throw new Exception('내역서 파일을 만들 수 없습니다 (저장 폴더 쓰기 권한).');
    [$zip, $docs] = sitehub_hwpx_open($outPath, 0);
    try {
        $T = sh_hx_find_table($docs);
        if (!$T || $T['start'] >= $T['end']) throw new Exception('양식에서 내역 표를 찾지 못했습니다 — 양식을 다시 올려 주세요.');
        $s = ($r['settings'] ?? []) + SITEHUB_REPORT_DEFAULTS;
        $tbl = $T['tbl'];
        $rows = $T['rows'];
        $titleP = sh_hx_title_para($docs, $T);
        $tplRow = $rows[$T['start']];
        $tplH = 0;
        foreach (sh_hx_kids($tplRow, 'tc') as $tc) { $sz = sh_hx_kid($tc, 'cellSz'); if ($sz) $tplH = max($tplH, (int)$sz->getAttribute('height')); }

        // 머리 칸: 일자 · 유지보수대상 · 점검자, 꼬리 칸: 비고
        $d = strtotime($r['report_date'] ?: $r['to']);
        $ymdRe = '/(\d{4})\s*년\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일/u';
        $ymdFn = fn() => date('Y', $d) . '년 ' . date('n', $d) . '월 ' . date('j', $d) . '일';
        if ($c = sh_hx_label_any($docs, $T, '/^(작성)?(일자|일시|날짜|작성일)$/u')) {
            $done = false;
            foreach (sh_hx_cell_paras($c) as $p) {
                $done = sh_hx_para_replace($p, $ymdRe, $ymdFn)
                     || sh_hx_para_replace($p, '/(\d{4})\s*([.\-\/])\s*(\d{1,2})\s*\2\s*(\d{1,2})/u', fn($m) => sitehub_report_fmt_date(date('Y-m-d', $d), sitehub_report_date_fmt($m[0]) ?: []));
                if ($done) break;
            }
            if (!$done) sh_hx_cell_set($c, [$ymdFn()]);
        } else {
            // 이름표 칸이 없는 양식 — '일자: 2026년 8월 31일' 처럼 글 안에 든 날짜(내용 행·제목 밖)
            $skip = [];
            for ($i = $T['start']; $i < $T['end']; $i++) $skip[spl_object_id($rows[$i])] = true;
            foreach ($docs as $doc) {
                foreach ((new DOMXPath($doc))->query('//*[local-name()="p"]') as $p) {
                    if ($p === $titleP) continue;
                    $tr = $p;
                    while ($tr && !($tr instanceof DOMElement && $tr->localName === 'tr')) $tr = $tr->parentNode;
                    if ($tr && isset($skip[spl_object_id($tr)])) continue;
                    if (sh_hx_para_replace($p, $ymdRe, $ymdFn)) break 2;
                }
            }
        }
        foreach (['target' => '/^(유지보수)?(대상|사이트|홈페이지|사이트주소|주소|url)$/iu', 'inspector' => '/^(점검자|작성자|담당자|점검)$/u'] as $k => $re) {
            if (isset($r[$k]) && ($c = sh_hx_label_any($docs, $T, $re))) sh_hx_cell_set($c, sh_hx_indent(preg_split('/\r?\n/', (string)$r[$k]), sh_hx_lead($c)));
        }
        if (isset($r['remark']) && ($c = sh_hx_label_cell($T, '/^(비고|특이사항|기타)$/u', true))) sh_hx_cell_set($c, preg_split('/\r?\n/', (string)$r['remark']));

        // 내용 행 바꾸기
        $after = $rows[$T['end']] ?? null;
        if (!$after) { $last = $rows[$T['end'] - 1]; $after = $last->nextSibling; }
        for ($i = $T['start']; $i < $T['end']; $i++) $tbl->removeChild($rows[$i]);
        $gen = $r['rows'] ?: [['date' => '', 'lines' => [''], 'result' => '']];
        $lead = array_map('sh_hx_lead', sh_hx_kids($tplRow, 'tc'));   // 양식이 ' - 작업' 처럼 앞에 띄운 칸은 그대로 띄운다
        foreach ($gen as $g) {
            $tr = $tplRow->cloneNode(true);
            $cells = sh_hx_kids($tr, 'tc');
            $lines = sh_hx_indent(array_values(array_map('strval', (array)($g['lines'] ?? []))), $lead[1] ?? '');
            $result = trim((string)($g['result'] ?? ''));
            sh_hx_cell_set($cells[0], sh_hx_indent([($g['date'] ?? '') !== '' ? sitehub_report_fmt_date($g['date'], (array)$s['date_fmt']) : ''], $lead[0] ?? ''));
            if (count($cells) >= 3) {
                sh_hx_cell_set($cells[1], $lines);
                sh_hx_cell_set($cells[count($cells) - 1], sh_hx_indent([$result], end($lead) ?: ''));
            } else {
                if ($result !== '' && $lines) $lines[0] .= $s['inline_sep'] . $result;
                sh_hx_cell_set($cells[1], $lines);
            }
            $after ? $tbl->insertBefore($tr, $after) : $tbl->appendChild($tr);
        }
        $all = sh_hx_kids($tbl, 'tr');
        foreach ($all as $i => $tr) foreach (sh_hx_kids($tr, 'tc') as $tc) { $a = sh_hx_kid($tc, 'cellAddr'); if ($a) $a->setAttribute('rowAddr', (string)$i); }
        $tbl->setAttribute('rowCnt', (string)count($all));
        if (($sz = sh_hx_kid($tbl, 'sz')) && $tplH > 0) {
            $sz->setAttribute('height', (string)max(0, (int)$sz->getAttribute('height') + (count($gen) - ($T['end'] - $T['start'])) * $tplH));
        }
        if ($tbl->getAttribute('pageBreak') === 'NONE' && count($gen) > $T['end'] - $T['start']) $tbl->setAttribute('pageBreak', 'CELL');   // 늘어난 표가 쪽을 넘으면 나뉘게

        // 제목 기간
        $title = '';
        if ($titleP) {
            sh_hx_para_replace($titleP, SH_HX_PERIOD_RE, fn($m) => sitehub_report_period_text($m, $r['from'], $r['to']));
            $title = trim(sh_hx_para_text($titleP));
        }

        foreach ($docs as $name => $doc) {
            $zip->addFromString($name, $doc->saveXML());
            $zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
        }
        // 글자 모양(header.xml): 제목 키우기 · 글꼴 통일
        $grow = (int)($s['title_grow'] ?? 0);
        $font = ($s['font'] ?? 'pretendard') === 'pretendard';
        if (($grow > 0 && $titleP) || $font) {
            $head = sh_hx_header($zip);
            if ($head) {
                if ($grow > 0 && $titleP) {
                    sh_hx_grow_title($head, $titleP, $grow);
                    foreach ($docs as $name => $doc) if ($titleP->ownerDocument === $doc) $zip->addFromString($name, $doc->saveXML());   // 제목 run 의 글자 모양 번호가 바뀌었다
                }
                if ($font) sh_hx_set_font($head, 'Pretendard');
                $zip->addFromString('Contents/header.xml', $head->saveXML());
                $zip->setCompressionName('Contents/header.xml', ZipArchive::CM_DEFLATE);
            }
        }
        // 미리보기 글(목록 미리보기용)은 새 내용으로
        if ($zip->locateName('Preview/PrvText.txt') !== false) {
            $prv = $title . "\n" . implode("\n", array_map(fn($g) => ($g['date'] ?? '') . ' ' . implode(' ', (array)($g['lines'] ?? [])), $gen));
            $zip->addFromString('Preview/PrvText.txt', mb_substr($prv, 0, 1000));
        }
        $zip->close();
        return ['title' => $title, 'rows' => count($gen)];
    } catch (Throwable $e) {
        @$zip->close();
        @unlink($outPath);
        throw $e;
    }
}

/* ── 공휴일 ─────────────────────────────────────────────────────────────────────
   양력 공휴일은 규칙으로, 설·추석·부처님오신날은 표(SITEHUB_LUNAR, 2024~2028 은 천문연구원 월력요항으로 확인)로,
   대체공휴일은 「관공서의 공휴일에 관한 규정」 규칙으로 만든다. 선거일·임시공휴일은 아는 것만 SITEHUB_HOLIDAY_EXTRA 에 두고,
   나머지는 화면의 '추가 휴일'(lab_sync_meta.report_holidays, 모든 사이트 공통)로 더하거나 뺀다.
   5월 1일(근로자의 날·노동절)은 회사가 쉬는 날이라 해마다 넣는다. 표에 없는 해의 음력 공휴일은 '추가 휴일'로 넣어야 한다. */

const SITEHUB_LUNAR = [   // 연도 => [설날(가운데 날), 부처님오신날, 추석(가운데 날)]
    2024 => ['2024-02-10', '2024-05-15', '2024-09-17'], 2025 => ['2025-01-29', '2025-05-05', '2025-10-06'],
    2026 => ['2026-02-17', '2026-05-24', '2026-09-25'], 2027 => ['2027-02-07', '2027-05-13', '2027-09-15'],
    2028 => ['2028-01-27', '2028-05-02', '2028-10-03'], 2029 => ['2029-02-13', '2029-05-20', '2029-09-22'],
    2030 => ['2030-02-03', '2030-05-09', '2030-09-12'],
];
const SITEHUB_HOLIDAY_EXTRA = [   // 선거일 · 임시공휴일
    '2024-04-10' => '국회의원선거', '2024-10-01' => '임시공휴일(국군의 날)', '2025-01-27' => '임시공휴일',
    '2025-06-03' => '대통령선거', '2026-06-03' => '전국동시지방선거', '2028-04-12' => '국회의원선거',
];

/** 그 해의 쉬는 날 [Y-m-d => 이름] — 대체공휴일 포함, 추가 휴일(사람이 적은 것) 반영 */
function sitehub_holidays(int $year, ?string $extra = null): array {
    static $cache = [];
    $key = $year . '|' . $extra;
    if (isset($cache[$key])) return $cache[$key];
    $h = [];
    $add = function (string $d, string $name) use (&$h) { $h[$d] = isset($h[$d]) ? $h[$d] . '·' . $name : $name; };
    $fixed = ['01-01' => '신정', '03-01' => '삼일절', '05-01' => '노동절', '05-05' => '어린이날', '06-06' => '현충일',
              '08-15' => '광복절', '10-03' => '개천절', '10-09' => '한글날', '12-25' => '성탄절'];
    if ($year >= 2026) $fixed['07-17'] = '제헌절';
    foreach ($fixed as $md => $name) $add("{$year}-{$md}", $name);
    $three = [];
    if (isset(SITEHUB_LUNAR[$year])) {
        [$seol, $buddha, $chuseok] = SITEHUB_LUNAR[$year];
        foreach ([[$seol, '설날'], [$chuseok, '추석']] as [$mid, $name]) {
            $days = [date('Y-m-d', strtotime("{$mid} -1 day")), $mid, date('Y-m-d', strtotime("{$mid} +1 day"))];
            foreach ($days as $d) $add($d, $name);
            $three[] = [$days, $name];
        }
        $add($buddha, '부처님오신날');
    }
    foreach (SITEHUB_HOLIDAY_EXTRA as $d => $name) if ((int)substr($d, 0, 4) === $year) $add($d, $name);

    // 대체공휴일 — 날짜 순서대로, 이미 정한 대체일도 공휴일로 보고 그 다음 날을 찾는다
    $base = $h;
    $next = function (string $d) use (&$h) {   // 대체일을 정할 때마다 $h 가 늘어나므로 참조로 본다
        do { $d = date('Y-m-d', strtotime("{$d} +1 day")); } while (isset($h[$d]) || (int)date('N', strtotime($d)) >= 6);
        return $d;
    };
    $subs = [];
    foreach ($three as [$days, $name]) {   // 설·추석: 연휴에 일요일이 끼거나 다른 공휴일과 겹치면 연휴 다음 첫 평일
        $hit = false;
        foreach ($days as $d) if ((int)date('N', strtotime($d)) === 7 || mb_strpos($base[$d], '·') !== false) $hit = true;
        if ($hit) $subs[] = [end($days), $name, false];
    }
    $weekendSub = ['삼일절', '어린이날', '부처님오신날', '광복절', '개천절', '한글날', '성탄절'];
    if ($year >= 2026) array_push($weekendSub, '노동절', '제헌절');
    foreach ($base as $d => $names) {
        foreach (explode('·', $names) as $name) {
            if (!in_array($name, $weekendSub, true)) continue;
            $overlap = count(explode('·', $names)) > 1 && $name !== explode('·', $names)[0];   // 같은 날 겹치면 뒤의 것이 밀린다
            if ((int)date('N', strtotime($d)) >= 6 || $overlap) $subs[] = [$d, $name, true];
        }
    }
    usort($subs, fn($a, $b) => strcmp($a[0], $b[0]));
    foreach ($subs as [$d, $name]) {
        $n = $next($d);
        if ((int)substr($n, 0, 4) === $year) $add($n, "대체공휴일({$name})");
    }

    // 추가 휴일: 'YYYY-MM-DD 이름' 은 더하고, '-YYYY-MM-DD' 는 뺀다
    foreach (preg_split('/\r?\n/', (string)$extra) as $line) {
        if (!preg_match('/^\s*(-)?\s*(\d{4})-(\d{2})-(\d{2})\s*(.*)$/u', $line, $m) || (int)$m[2] !== $year || !checkdate((int)$m[3], (int)$m[4], (int)$m[2])) continue;
        $d = "{$m[2]}-{$m[3]}-{$m[4]}";
        if ($m[1] === '-') unset($h[$d]);
        else $h[$d] = trim($m[5]) !== '' ? trim($m[5]) : '휴일';
    }
    ksort($h);
    return $cache[$key] = $h;
}

/** 기간 안의 쉬는 날 */
function sitehub_holidays_between(string $from, string $to, ?string $extra = null): array {
    $out = [];
    for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
        foreach (sitehub_holidays($y, $extra) as $d => $n) if ($d >= $from && $d <= $to) $out[$d] = $n;
    }
    return $out;
}

function sitehub_report_holiday_extra(PDO $pdo): string {
    $st = $pdo->prepare("SELECT v FROM lab_sync_meta WHERE k = 'report_holidays'");
    $st->execute();
    return (string)$st->fetchColumn();
}

/* ── 행 만들기 ─────────────────────────────────────────────────────────────────── */

/** 이력 한 건 → 내역서 줄. 이 리스트는 제목이 업체명이라 작업내용(body)을 쓴다 */
function sitehub_report_hist_lines(array $h, array $site): array {
    $norm = fn($v) => mb_strtolower(preg_replace('/\s+/u', '', (string)$v));
    $names = array_map($norm, array_merge([$site['name'] ?? ''], explode(',', (string)($site['slack_names'] ?? ''))));
    $titleIsSite = in_array($norm($h['title'] ?? ''), $names, true) || sitehub_report_title_is_site();
    $text = trim((string)($titleIsSite && trim((string)$h['body']) !== '' ? $h['body'] : ($h['title'] ?: $h['body'])));
    $out = [];
    foreach (preg_split('/\r?\n/', $text) as $l) {
        $l = trim(preg_replace('/^[\-•·\*▪◦]+\s*/u', '', trim($l)));
        if ($l !== '') $out[] = '- ' . $l;
    }
    return $out ?: ['- (내용 없음)'];
}

function sitehub_report_title_is_site(): bool {
    static $v = null;
    if ($v === null) { $lc = @include __DIR__ . '/list_cols.php'; $v = is_array($lc) && ($lc['title'] ?? '') !== '' && ($lc['title'] ?? '') === ($lc['site'] ?? null); }
    return $v;
}

/**
 * 기간의 내역서 행 — 정기 점검 줄(요일마다, 달마다 마지막은 정기점검 문구) + Slack 이력. 같은 날이면 한 행.
 * → ['rows' => [{date, lines, result, kind, refs, warn}], 'stats' => [...]]
 */
function sitehub_report_rows(PDO $pdo, array $site, string $from, string $to, array $s): array {
    $s += SITEHUB_REPORT_DEFAULTS;
    $byDate = [];
    $stats = ['monitor' => 0, 'month' => 0, 'work' => 0, 'skipped' => 0, 'incident' => 0, 'shifted' => 0];
    $holidays = sitehub_holidays_between(date('Y-m-d', strtotime("{$from} -7 days")), date('Y-m-d', strtotime("{$to} +7 days")), sitehub_report_holiday_extra($pdo));

    // 정기 점검 날짜 — 공휴일(평일 요일이면 주말도)이면 그 전 평일로. 앞 점검일·기간 시작을 넘으면 다음 평일, 그것도 안 되면 그 주는 뺀다
    $mon = [];
    $notes = [];
    $wd = (int)$s['weekday'];
    if ($wd >= 1 && $wd <= 7) {
        $isOff = fn(string $d) => isset($holidays[$d]) || ($wd <= 5 && (int)date('N', strtotime($d)) >= 6);
        $dname = fn(string $d) => substr($d, 5) . '(' . ['', '월', '화', '수', '목', '금', '토', '일'][(int)date('N', strtotime($d))] . ')';
        $d = strtotime($from);
        $end = strtotime($to);
        while ($d <= $end && (int)date('N', $d) !== $wd) $d = strtotime('+1 day', $d);
        $prev = date('Y-m-d', strtotime("{$from} -1 day"));
        for (; $d <= $end; $d = strtotime('+7 days', $d)) {
            $day = date('Y-m-d', $d);
            if (!empty($s['holiday_shift']) && $isOff($day)) {
                $why = $dname($day) . ' ' . ($holidays[$day] ?? '주말');
                $c = date('Y-m-d', strtotime("{$day} -1 day"));
                while ($c > $prev && $c >= $from && $isOff($c)) $c = date('Y-m-d', strtotime("{$c} -1 day"));
                if (!($c > $prev && $c >= $from)) {
                    $limit = min($to, date('Y-m-d', strtotime("{$day} +6 days")));
                    $c = date('Y-m-d', strtotime("{$day} +1 day"));
                    while ($c <= $limit && $isOff($c)) $c = date('Y-m-d', strtotime("{$c} +1 day"));
                    if ($c > $limit) { $stats['shifted']++; continue; }   // 그 주는 쉬는 날뿐 — 빼고 넘어간다
                }
                $notes[$c] = "{$why} → {$dname($c)}";
                $stats['shifted']++;
                $day = $c;
            }
            $mon[] = $day;
            $prev = $day;
        }
    }
    $lastOfMonth = [];
    foreach ($mon as $d) $lastOfMonth[substr($d, 0, 7)] = $d;

    // BLUMO 장애 (그 점검일까지, 앞 점검일 다음부터)
    $inc = [];
    if (!empty($s['incidents']) && $mon) {
        $st = $pdo->prepare("SELECT started, ended, cause FROM lab_monitor_incidents WHERE site_id = ? AND started <= ? AND (ended IS NULL OR ended >= ?) ORDER BY started");
        $st->execute([(int)$site['id'], $to . ' 23:59:59', date('Y-m-d', strtotime($from . ' -7 days')) . ' 00:00:00']);
        $inc = $st->fetchAll();
    }
    foreach ($mon as $i => $d) {
        $isMonth = !empty($s['month_on']) && $lastOfMonth[substr($d, 0, 7)] === $d;
        $lines = preg_split('/\r?\n/', trim((string)($isMonth ? $s['month_text'] : $s['mon_text']))) ?: [''];
        $winFrom = $i === 0 ? date('Y-m-d', strtotime($d . ' -6 days')) : date('Y-m-d', strtotime($mon[$i - 1] . ' +1 day'));
        $hits = array_values(array_filter($inc, fn($x) => substr($x['started'], 0, 10) <= $d && ($x['ended'] === null || substr($x['ended'], 0, 10) >= $winFrom)));
        $row = ['date' => $d, 'lines' => $lines, 'result' => (string)$s['mon_result'], 'kind' => $isMonth ? 'month' : 'monitor', 'refs' => [], 'warn' => '', 'note' => $notes[$d] ?? ''];
        if ($hits) {
            $open = array_filter($hits, fn($x) => $x['ended'] === null);
            $row['result'] = $open ? '장애 진행 중' : '장애 ' . count($hits) . '건 복구';
            $row['warn'] = 'BLUMO 장애: ' . implode(' / ', array_map(fn($x) => substr($x['started'], 5, 11) . ($x['ended'] ? '~' . substr($x['ended'], 5, 11) : '~') . ' ' . $x['cause'], $hits));
            $stats['incident']++;
        }
        $byDate[$d] = $row;
        $stats[$isMonth ? 'month' : 'monitor']++;
    }

    // Slack 이력 — 하위 작업은 부모 요청의 단계라 빼고, 기준일이 기간 안인 것
    $dateExpr = $s['hist_date'] === 'req' ? '`date`' : 'COALESCE(`done`, `date`)';
    $st = $pdo->prepare("SELECT id, title, body, status, `date`, `done`, req_at FROM lab_history
                         WHERE site_id = ? AND archived = 0 AND parent_id IS NULL AND {$dateExpr} BETWEEN ? AND ?
                         ORDER BY {$dateExpr}, COALESCE(req_at, `date`), created");
    $st->execute([(int)$site['id'], $from, $to]);
    foreach ($st->fetchAll() as $h) {
        $status = (string)$h['status'];
        $isDone = mb_strpos($status, '완료') !== false;
        $isOff = (bool)preg_match('/취소|보류/u', $status);
        if ($s['hist_status'] === 'done' ? !$isDone : $isOff) { $stats['skipped']++; continue; }
        $d = $s['hist_date'] === 'req' ? $h['date'] : ($h['done'] ?: $h['date']);
        $lines = sitehub_report_hist_lines($h, $site);
        $result = $isDone ? (string)$s['work_result'] : preg_replace('/^\d+_/', '', $status);
        if (isset($byDate[$d])) {
            $byDate[$d]['lines'] = array_merge($byDate[$d]['lines'], $lines);
            $byDate[$d]['refs'][] = $h['id'];
            if (in_array($byDate[$d]['kind'], ['monitor', 'month'], true)) $byDate[$d]['kind'] .= '+work';
        } else {
            $byDate[$d] = ['date' => $d, 'lines' => $lines, 'result' => $result, 'kind' => 'work', 'refs' => [$h['id']], 'warn' => '', 'note' => ''];
        }
        $stats['work']++;
    }
    ksort($byDate);
    $inPeriod = array_filter($holidays, fn($d) => $d >= $from && $d <= $to, ARRAY_FILTER_USE_KEY);
    return ['rows' => array_values($byDate), 'stats' => $stats, 'holidays' => array_map(fn($d, $n) => ['date' => $d, 'name' => $n], array_keys($inPeriod), $inPeriod)];
}

/* ── 저장 · 불러오기 ───────────────────────────────────────────────────────────── */

function sitehub_report_tpl(PDO $pdo, int $siteId): ?array {
    $st = $pdo->prepare("SELECT t.*, u.name AS updated_name FROM lab_report_templates t LEFT JOIN portal_users u ON u.email = t.updated_by WHERE t.site_id = ?");
    $st->execute([$siteId]);
    $t = $st->fetch();
    if (!$t) return null;
    $t['site_id'] = (int)$t['site_id'];
    $t['file_size'] = (int)$t['file_size'];
    $t['analysis'] = json_decode((string)$t['analysis'], true) ?: [];
    $t['settings'] = (json_decode((string)$t['settings'], true) ?: []) + SITEHUB_REPORT_DEFAULTS;
    $t['exists'] = is_file(sitehub_report_dir('tpl') . '/' . $t['file_path']);
    if ($t['exists'] && !array_key_exists('title_size', $t['analysis'])) {   // 제목 크기를 기록하기 전에 올린 양식 — 한 번 재서 남긴다
        try {
            [$zip, $docs] = sitehub_hwpx_open(sitehub_report_dir('tpl') . '/' . $t['file_path']);
            $head = sh_hx_header($zip);
            $zip->close();
            $p = sh_hx_title_para($docs, sh_hx_find_table($docs));
            $t['analysis']['title_size'] = $p && $head ? sh_hx_title_size($head, $p) : null;
            $pdo->prepare("UPDATE lab_report_templates SET analysis = ? WHERE site_id = ?")->execute([json_encode($t['analysis'], JSON_UNESCAPED_UNICODE), $siteId]);
        } catch (Throwable $e) { /* 못 재면 표시만 빠진다 */ }
    }
    return $t;
}

/** 다른 사이트 양식을 가져다 쓸 수 있게 — 양식이 있는 사이트 목록 */
function sitehub_report_tpl_sites(PDO $pdo, int $except): array {
    $st = $pdo->prepare("SELECT t.site_id, s.name, t.file_name FROM lab_report_templates t JOIN lab_sites s ON s.id = t.site_id WHERE t.site_id <> ? ORDER BY s.name");
    $st->execute([$except]);
    return array_map(fn($r) => ['site_id' => (int)$r['site_id'], 'name' => $r['name'], 'file_name' => $r['file_name']], $st->fetchAll());
}

function sitehub_report_list(PDO $pdo, int $siteId): array {
    $st = $pdo->prepare("SELECT r.id, r.site_id, r.period_from, r.period_to, r.report_date, r.title, r.file_name, r.row_count, r.created_at, r.created_by,
                                COALESCE(NULLIF(r.created_name, ''), u.name, r.created_by) AS created_name
                         FROM lab_reports r LEFT JOIN portal_users u ON u.email = r.created_by WHERE r.site_id = ? ORDER BY r.period_to DESC, r.id DESC LIMIT 50");
    $st->execute([$siteId]);
    return array_map(function ($r) { $r['id'] = (int)$r['id']; $r['site_id'] = (int)$r['site_id']; $r['row_count'] = (int)$r['row_count']; return $r; }, $st->fetchAll());
}

/** 올린 파일 검사 → 저장 → 분석. 같은 사이트의 예전 양식 파일은 지운다. 사람이 고친 규칙(목록에 없는 칸 빼고)은 남긴다 */
function sitehub_report_tpl_save(PDO $pdo, array $me, int $siteId, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception(match ((int)($file['error'] ?? 4)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '파일이 너무 큽니다 (서버 업로드 한도).',
            UPLOAD_ERR_NO_FILE => '파일을 고르세요.',
            default => '파일을 받지 못했습니다 (오류 ' . (int)$file['error'] . ').',
        });
    }
    $name = basename(str_replace('\\', '/', (string)$file['name']));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'hwp') throw new Exception('.hwp(예전 한글 파일)는 채울 수 없습니다. 한글에서 [파일 → 다른 이름으로 저장 → 파일 형식: 한글 표준 문서(*.hwpx)]로 저장해 그 파일을 올려 주세요.');
    if ($ext !== 'hwpx') throw new Exception('한글 표준 문서(.hwpx)만 올릴 수 있습니다.');
    if ((int)$file['size'] > SITEHUB_REPORT_MAX_BYTES) throw new Exception('파일이 너무 큽니다 (최대 20MB).');
    if (!is_uploaded_file((string)$file['tmp_name']) && PHP_SAPI !== 'cli') throw new Exception('올린 파일이 아닙니다.');

    $an = sitehub_report_analyze((string)$file['tmp_name']);
    $dir = sitehub_report_dir('tpl');
    $stored = 'site' . $siteId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.hwpx';
    if (!@move_uploaded_file((string)$file['tmp_name'], "{$dir}/{$stored}") && !@copy((string)$file['tmp_name'], "{$dir}/{$stored}")) {
        throw new Exception('양식 파일을 저장할 수 없습니다 (var/sitehub/reports 쓰기 권한).');
    }
    $old = sitehub_report_tpl($pdo, $siteId);
    $settings = $an['settings'];
    if ($old) {
        // 바꿔 올려도 사람이 고른 이력 규칙은 남긴다 — 양식 모양에서 나오는 값(날짜 모양·결과 칸·견본 문구)만 새로
        foreach (['hist_status', 'hist_date', 'incidents'] as $k) $settings[$k] = $old['settings'][$k];
        @unlink($dir . '/' . $old['file_path']);
    }
    $pdo->prepare("INSERT INTO lab_report_templates (site_id, file_name, file_path, file_size, analysis, settings, created_at, updated_at, updated_by)
                   VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
                   ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path), file_size = VALUES(file_size),
                       analysis = VALUES(analysis), settings = VALUES(settings), updated_at = NOW(), updated_by = VALUES(updated_by)")
        ->execute([$siteId, mb_substr($name, 0, 255), $stored, (int)$file['size'],
                   json_encode($an['analysis'], JSON_UNESCAPED_UNICODE), json_encode($settings, JSON_UNESCAPED_UNICODE), (string)($me['email'] ?? '')]);
    sitehub_audit($pdo, $me, $siteId, $old ? 'update' : 'create', 'report_tpl', null, '내역서 양식', [], $name);
    return sitehub_report_tpl($pdo, $siteId);
}

/** 규칙 저장 — 아는 칸만, 범위 안의 값만 */
function sitehub_report_clean_settings(array $in, array $base): array {
    $s = $base + SITEHUB_REPORT_DEFAULTS;
    if (isset($in['weekday'])) $s['weekday'] = max(0, min(7, (int)$in['weekday']));
    foreach (['mon_text' => 200, 'mon_result' => 60, 'month_text' => 500, 'work_result' => 60, 'inline_sep' => 10, 'target' => 300, 'inspector' => 100, 'remark' => 1000] as $k => $max) {
        if (array_key_exists($k, $in)) $s[$k] = mb_substr(str_replace("\r", '', (string)$in[$k]), 0, $max);
    }
    foreach (['month_on', 'incidents', 'holiday_shift'] as $k) if (array_key_exists($k, $in)) $s[$k] = $in[$k] ? 1 : 0;
    if (isset($in['hist_status'])) $s['hist_status'] = $in['hist_status'] === 'open' ? 'open' : 'done';
    if (isset($in['hist_date'])) $s['hist_date'] = $in['hist_date'] === 'req' ? 'req' : 'done';
    if (isset($in['font'])) $s['font'] = $in['font'] === 'template' ? 'template' : 'pretendard';
    if (isset($in['title_grow'])) $s['title_grow'] = max(0, min(20, (int)$in['title_grow']));
    return $s;
}

/**
 * 파일 이름 — 양식 파일 이름의 'YYYY_N분기' 를 기간에 맞춰 옮긴다(양식 제목의 기간이 그 분기). 없으면 사이트명_유지보수내역서_기간.
 */
function sitehub_report_file_name(array $site, ?array $tpl, string $from, string $to): string {
    $f = strtotime($from); $t = strtotime($to);
    $span = date('Y', $f) === date('Y', $t) ? date('Y', $f) . '_' . date('m', $f) . '-' . date('m', $t) . '월' : date('Ym', $f) . '-' . date('Ym', $t);
    $base = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', (string)$site['name']) . '_유지보수내역서_' . $span;
    if ($tpl && !empty($tpl['analysis']['period']['from'])) {
        $orig = pathinfo((string)$tpl['file_name'], PATHINFO_FILENAME);
        if (preg_match('/(\d{4})([_\-\s]*)([1-4])(\s*분기)/u', $orig, $m)) {
            [$ty, $tm] = array_map('intval', explode('-', $tpl['analysis']['period']['from']));
            $months = ((int)date('Y', $f) - $ty) * 12 + ((int)date('n', $f) - $tm);
            if ($months % 3 === 0) {
                $idx = ((int)$m[3] - 1) + intdiv($months, 3);
                $year = (int)$m[1] + intdiv($idx - (($idx % 4 + 4) % 4), 4);
                $q = ($idx % 4 + 4) % 4 + 1;
                $base = str_replace($m[0], $year . $m[2] . $q . $m[4], $orig);
            }
        }
    }
    return $base . '.hwpx';
}

/** 내역서 만들기 → 파일 저장 + lab_reports 기록 */
function sitehub_report_build(PDO $pdo, array $me, array $site, array $in): array {
    $tpl = sitehub_report_tpl($pdo, (int)$site['id']);
    if (!$tpl || !$tpl['exists']) throw new Exception('이 사이트에 올린 양식이 없습니다 — 먼저 내역서 양식(.hwpx)을 올리세요.');
    $from = (string)($in['from'] ?? ''); $to = (string)($in['to'] ?? '');
    if (!sitehub_report_parse_date($from) || !sitehub_report_parse_date($to) || $from > $to) throw new Exception('기간을 확인하세요.');
    $rows = [];
    foreach ((array)($in['rows'] ?? []) as $g) {
        $lines = array_values(array_filter(array_map(fn($l) => rtrim((string)$l), (array)($g['lines'] ?? [])), fn($l) => trim($l) !== ''));
        $date = sitehub_report_parse_date((string)($g['date'] ?? '')) ?? '';
        if ($date === '' && !$lines) continue;
        $rows[] = ['date' => $date, 'lines' => array_map(fn($l) => mb_substr($l, 0, 1000), $lines), 'result' => mb_substr(trim((string)($g['result'] ?? '')), 0, 100)];
        if (count($rows) >= 500) break;
    }
    usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
    $fields = [
        'target' => mb_substr((string)($in['target'] ?? $tpl['settings']['target']), 0, 300),
        'inspector' => mb_substr((string)($in['inspector'] ?? $tpl['settings']['inspector']), 0, 100),
        'remark' => mb_substr(str_replace("\r", '', (string)($in['remark'] ?? $tpl['settings']['remark'])), 0, 1000),
    ];
    $reportDate = sitehub_report_parse_date((string)($in['report_date'] ?? '')) ?? $to;
    $name = trim((string)($in['file_name'] ?? '')) ?: sitehub_report_file_name($site, $tpl, $from, $to);
    $name = preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', '_', $name);
    if (!preg_match('/\.hwpx$/i', $name)) $name .= '.hwpx';
    $stored = 'site' . (int)$site['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.hwpx';
    $out = sitehub_report_dir('out') . '/' . $stored;
    $res = sitehub_report_fill(sitehub_report_dir('tpl') . '/' . $tpl['file_path'], $out,
        ['from' => $from, 'to' => $to, 'report_date' => $reportDate, 'rows' => $rows, 'settings' => $tpl['settings']] + $fields);
    // PDF 는 채운 hwpx 를 다시 읽어 그린다 — 실패해도 hwpx 는 남기고 이유만 알린다(받을 때 다시 만들어 본다)
    $pdfError = null;
    try { sitehub_report_make_pdf($out, $res['title']); } catch (Throwable $e) { $pdfError = $e->getMessage(); }
    $pdo->prepare("INSERT INTO lab_reports (site_id, period_from, period_to, report_date, title, items, fields, row_count, file_name, file_path, created_at, created_by, created_name)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)")
        ->execute([(int)$site['id'], $from, $to, $reportDate, mb_substr($res['title'], 0, 300), json_encode($rows, JSON_UNESCAPED_UNICODE),
                   json_encode($fields, JSON_UNESCAPED_UNICODE), count($rows), mb_substr($name, 0, 255), $stored, (string)($me['email'] ?? ''), (string)($me['name'] ?? '')]);
    $id = (int)$pdo->lastInsertId();
    sitehub_audit($pdo, $me, (int)$site['id'], 'create', 'reports', $id, $name, [], "{$from} ~ {$to} · " . count($rows) . '줄');
    return ['id' => $id, 'file_name' => $name, 'pdf_name' => sitehub_report_pdf_name($name), 'title' => $res['title'], 'rows' => count($rows), 'pdf_error' => $pdfError];
}

function sitehub_report_pdf_name(string $hwpxName): string {
    return preg_replace('/\.hwpx$/i', '', $hwpxName) . '.pdf';
}

/** 채운 hwpx 옆에 같은 이름의 .pdf */
function sitehub_report_make_pdf(string $hwpxPath, string $title = ''): string {
    require_once __DIR__ . '/report_pdf.php';
    $pdf = sitehub_report_pdf_name($hwpxPath);
    sitehub_report_pdf($hwpxPath, $pdf, $title);
    return $pdf;
}

/** 파일 내려주기 — 한글 이름은 filename* 로 */
function sitehub_report_send(string $path, string $name): void {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$ext] ?? 'application/hwp+zip';
    if (!is_file($path)) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => '파일이 없습니다 (지워졌거나 옮겨짐).'], JSON_UNESCAPED_UNICODE); exit; }
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

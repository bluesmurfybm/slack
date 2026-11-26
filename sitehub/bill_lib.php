<?php
/**
 * 대금청구서 — 모든 사이트가 블루소프트 공통 고정 양식(templates/bill.xlsx, 엑셀) 하나를 쓴다.
 * 사이트·기간 값을 채워 .xlsx 와 같은 이름의 .pdf(bill_pdf.php) 두 개를 만든다.
 *
 *  - 칸은 주소가 아니라 이름표로 찾는다: 수신·참조·건명·담당자·합계액 이름표 옆 칸, '품명' 머리 행 아래 ~ '합계' 행 위가 품목 줄,
 *    'YYYY년 M월 일' 모양의 칸이 청구 날짜. 양식 파일을 고쳐 바꿔 넣어도(계좌·대표자 등) 그대로 동작한다.
 *    공급자·계좌·지급기한·대표자·도장 그림은 손대지 않는다.
 *  - 금액: 양식 금액이 '세액포함' 이라 공급가액·부가세를 따로 적지 않는다. 품목 1줄 = 개월 수 × 월 단가(VAT 포함).
 *    월 단가는 사이트 규칙에 적은 값, 없으면 계약 탭(lab_site_contracts)의 연간 금액 ÷ 12 (VAT 별도면 ×1.1).
 *  - 건명·품명은 사이트 규칙의 틀로 만든다 — {학년도}학년도 {분기}분기 … 분기는 학년도 기준(1분기 시작 월 = 규칙, 기본 3월).
 *  - 수식 칸(금액·합계·합계액)은 수식을 남기고 계산값만 넣는다 + 엑셀이 열 때 다시 계산(fullCalcOnLoad).
 *  - 사이트별 규칙: lab_bill_settings, 만든 청구서: lab_bills, 파일: var/sitehub/reports/bill/
 * 엑셀 파일은 ZipArchive·DOM 만으로 다룬다(운영 서버에 vendor/ 를 깔지 않는다).
 */

require_once __DIR__ . '/report_lib.php';

const SITEHUB_BILL_TPL = __DIR__ . '/templates/bill.xlsx';
const SITEHUB_BILL_DEFAULTS = [
    'recipient' => '',                                  // 수신 — 비우면 고객사 + 사이트명
    'ref'       => '',                                  // 참조
    'org'       => '',                                  // 건명·품명의 {기관} — 비우면 사이트명
    'subject'   => '{학년도}학년도 {분기}분기 {기관} 홈페이지 유지보수비',
    'item'      => '{분기}분기 홈페이지 유지보수({시작월}~{끝월}월)',
    'spec'      => '',                                  // 규격
    'unit'      => '식',                                // 단위
    'manager'   => '',                                  // 담당자 — 비우면 내역서 점검자, 그것도 없으면 양식 값
    'price'     => 0,                                   // 월 단가(VAT 포함) — 0 이면 계약 탭 연간 금액에서 계산
    'q_start'   => 3,                                   // 1분기 시작 월 (학년도: 3월)
    'day_blank' => 1,                                   // 청구 날짜의 '일' 은 비워 둔다 (양식처럼 'YYYY년 M월 일')
    'rounding'  => 'floor',                             // 월 단가가 원 단위로 안 떨어질 때 floor=버림, round=반올림
    'font'      => 'pretendard',                        // xlsx 글꼴: pretendard=Pretendard 로 통일, template=양식 그대로 (PDF 는 늘 Pretendard)
];
const SITEHUB_BILL_TOKENS = ['학년도', '분기', '연도', '시작월', '끝월', '개월', '기관'];
const SITEHUB_BILL_LABELS = [   // 양식에서 찾는 이름표 (띄어쓰기 빼고)
    'recipient' => '수신', 'ref' => '참조', 'subject' => '건명', 'manager' => '담당자', 'grand' => '합계액',
];
const SITEHUB_BILL_SUPPLIER = ['상호', '사업자번호', '대표자', '소재지', '업태', '종목', '전화번호', '지급기한', '은행명', '계좌번호', '예금주'];

/* ── xlsx 읽기 · 쓰기 ──────────────────────────────────────────────────────────── */

const SH_XL_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const SH_XL_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function sh_xl_col(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n;
}

function sh_xl_letters(int $c): string {
    $s = '';
    while ($c > 0) { $m = ($c - 1) % 26; $s = chr(65 + $m) . $s; $c = intdiv($c - 1, 26); }
    return $s;
}

/** 'C4' → [4, 3] */
function sh_xl_ref(string $ref): ?array {
    return preg_match('/^\$?([A-Z]{1,3})\$?(\d+)$/', strtoupper(trim($ref)), $m) ? [(int)$m[2], sh_xl_col($m[1])] : null;
}

function sh_xl_norm(string $s): string {
    return preg_replace('/\s+/u', '', $s);
}

/** zip 안의 상대 경로 풀기 — xl/worksheets/ + ../drawings/a.xml → xl/drawings/a.xml */
function sh_xl_path(string $base, string $target): string {
    if ($target !== '' && $target[0] === '/') return ltrim($target, '/');
    $parts = explode('/', rtrim($base, '/') . '/' . $target);
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') array_pop($out); else $out[] = $p;
    }
    return implode('/', $out);
}

/** 관계 파일 → [rId => target 경로] */
function sh_xl_rels(ZipArchive $zip, string $part): array {
    $dir = dirname($part);
    $rels = $zip->getFromName($dir . '/_rels/' . basename($part) . '.rels');
    $out = [];
    if ($rels === false) return $out;
    $d = new DOMDocument();
    if (!@$d->loadXML($rels, LIBXML_NONET)) return $out;
    foreach ($d->getElementsByTagName('Relationship') as $r) {
        $out[$r->getAttribute('Id')] = ['path' => sh_xl_path($dir, $r->getAttribute('Target')), 'type' => basename($r->getAttribute('Type'))];
    }
    return $out;
}

/**
 * 첫 시트 하나를 다루는 묶음 — 칸 찾기·값 읽기·값 쓰기. 쓰면 save() 로 zip 에 되돌린다.
 */
final class ShXlBook {
    public ZipArchive $zip;
    public string $sheetPath = '';
    public DOMDocument $sheet;
    public ?DOMDocument $sst = null;
    public string $sstPath = 'xl/sharedStrings.xml';
    public array $strings = [];   // 공유 문자열 번호 => 글
    public array $siEls = [];     // 공유 문자열 번호 => <si> (서식 조각을 PDF 가 읽는다)
    public array $cells = [];     // "r:c" => <c>
    public array $rowEls = [];    // r => <row>
    public array $merges = [];    // [r1, c1, r2, c2]
    private ?DOMElement $sheetData = null;
    private bool $sstDirty = false;
    public ?Closure $sstFilter = null;   // 저장 직전에 공유 문자열 XML 을 고친다(글꼴)

    public static function open(string $path, bool $write = false): self {
        $b = new self();
        $b->zip = new ZipArchive();
        if ($b->zip->open($path, $write ? 0 : ZipArchive::RDONLY) !== true) throw new Exception('엑셀 파일(.xlsx)을 열 수 없습니다.');
        // workbook → 첫 시트
        $wbRels = sh_xl_rels($b->zip, 'xl/workbook.xml');
        $wb = (string)$b->zip->getFromName('xl/workbook.xml');
        $first = null;
        if (preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/u', $wb, $m)) $first = $wbRels[$m[1]]['path'] ?? null;
        $b->sheetPath = $first ?: 'xl/worksheets/sheet1.xml';
        $xml = $b->zip->getFromName($b->sheetPath);
        $b->sheet = new DOMDocument();
        if ($xml === false || !@$b->sheet->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) throw new Exception('엑셀 시트를 읽을 수 없습니다.');
        foreach ($wbRels as $r) {
            if ($r['type'] !== 'sharedStrings') continue;
            $sx = $b->zip->getFromName($r['path']);
            if ($sx === false) break;
            $b->sst = new DOMDocument();
            if (!@$b->sst->loadXML($sx, LIBXML_NONET | LIBXML_PARSEHUGE)) { $b->sst = null; break; }
            $b->sstPath = $r['path'];
            $i = 0;
            foreach ($b->sst->documentElement->childNodes as $si) {
                if (!$si instanceof DOMElement || $si->localName !== 'si') continue;
                $b->siEls[$i] = $si;
                $b->strings[$i++] = self::siText($si);
            }
        }
        $b->sheetData = $b->sheet->getElementsByTagNameNS(SH_XL_NS, 'sheetData')->item(0);
        if (!$b->sheetData) throw new Exception('엑셀 시트에 칸이 없습니다.');
        foreach ($b->sheetData->childNodes as $row) {
            if (!$row instanceof DOMElement || $row->localName !== 'row') continue;
            $b->rowEls[(int)$row->getAttribute('r')] = $row;
            foreach ($row->childNodes as $c) {
                if (!$c instanceof DOMElement || $c->localName !== 'c') continue;
                $rc = sh_xl_ref($c->getAttribute('r'));
                if ($rc) $b->cells[$rc[0] . ':' . $rc[1]] = $c;
            }
        }
        foreach ($b->sheet->getElementsByTagNameNS(SH_XL_NS, 'mergeCell') as $mc) {
            $p = explode(':', $mc->getAttribute('ref'));
            $a = sh_xl_ref($p[0]); $z = sh_xl_ref($p[1] ?? $p[0]);
            if ($a && $z) $b->merges[] = [min($a[0], $z[0]), min($a[1], $z[1]), max($a[0], $z[0]), max($a[1], $z[1])];
        }
        return $b;
    }

    public static function siText(DOMElement $si): string {
        $s = '';
        foreach ($si->getElementsByTagNameNS(SH_XL_NS, 't') as $t) {
            // 읽는 법 표기(rPh) 안의 t 는 빼고
            if ($t->parentNode instanceof DOMElement && $t->parentNode->localName === 'rPh') continue;
            $s .= $t->textContent;
        }
        return $s;
    }

    public function cell(int $r, int $c): ?DOMElement { return $this->cells["{$r}:{$c}"] ?? null; }

    public function merge(int $r, int $c): ?array {
        foreach ($this->merges as $m) if ($r >= $m[0] && $r <= $m[2] && $c >= $m[1] && $c <= $m[3]) return $m;
        return null;
    }

    private function kid(DOMElement $el, string $local): ?DOMElement {
        foreach ($el->childNodes as $n) if ($n instanceof DOMElement && $n->localName === $local) return $n;
        return null;
    }

    public function hasFormula(int $r, int $c): bool {
        $el = $this->cell($r, $c);
        return $el && $this->kid($el, 'f') !== null;
    }

    /** 칸 값 — 글이면 글, 숫자면 숫자 글자 그대로('264000'), 빈 칸은 '' */
    public function text(int $r, int $c): string {
        $el = $this->cell($r, $c);
        if (!$el) return '';
        $t = $el->getAttribute('t');
        if ($t === 'inlineStr') { $is = $this->kid($el, 'is'); return $is ? self::siText($is) : ''; }
        $v = $this->kid($el, 'v');
        if (!$v) return '';
        if ($t === 's') return $this->strings[(int)$v->textContent] ?? '';
        return $v->textContent;
    }

    /** 서식 조각(rich text)이 있는 공유 문자열이면 [[글, <rPr>|null], …], 아니면 null */
    public function runs(int $r, int $c): ?array {
        $el = $this->cell($r, $c);
        if (!$el || $el->getAttribute('t') !== 's') return null;
        $v = $this->kid($el, 'v');
        $si = $v ? ($this->siEls[(int)$v->textContent] ?? null) : null;
        if (!$si) return null;
        $out = [];
        foreach ($si->childNodes as $n) {
            if (!$n instanceof DOMElement || $n->localName !== 'r') continue;
            $t = $this->kid($n, 't');
            $out[] = [$t ? $t->textContent : '', $this->kid($n, 'rPr')];
        }
        return $out ?: null;
    }

    public function isNumber(int $r, int $c): bool {
        $el = $this->cell($r, $c);
        if (!$el || in_array($el->getAttribute('t'), ['s', 'str', 'inlineStr', 'e', 'b'], true)) return false;
        $v = $this->kid($el, 'v');
        return $v && is_numeric($v->textContent);
    }

    /** 없는 칸은 행 안의 열 순서 자리에 만든다 */
    public function ensure(int $r, int $c): DOMElement {
        if ($el = $this->cell($r, $c)) return $el;
        $row = $this->rowEls[$r] ?? null;
        if (!$row) {
            $row = $this->sheet->createElementNS(SH_XL_NS, 'row');
            $row->setAttribute('r', (string)$r);
            $after = null;
            foreach ($this->rowEls as $rr => $el) if ($rr > $r && (!$after || $rr < (int)$after->getAttribute('r'))) $after = $el;
            $this->sheetData->insertBefore($row, $after);
            $this->rowEls[$r] = $row;
        }
        $el = $this->sheet->createElementNS(SH_XL_NS, 'c');
        $el->setAttribute('r', sh_xl_letters($c) . $r);
        $before = null;
        foreach ($row->childNodes as $n) {
            if (!$n instanceof DOMElement || $n->localName !== 'c') continue;
            $rc = sh_xl_ref($n->getAttribute('r'));
            if ($rc && $rc[1] > $c) { $before = $n; break; }
        }
        $row->insertBefore($el, $before);
        return $this->cells["{$r}:{$c}"] = $el;
    }

    private function clearValue(DOMElement $el, bool $keepFormula = false): void {
        foreach (iterator_to_array($el->childNodes) as $n) {
            if ($n instanceof DOMElement && ($n->localName === 'v' || $n->localName === 'is' || ($n->localName === 'f' && !$keepFormula))) $el->removeChild($n);
        }
        $el->removeAttribute('t');
    }

    private function sstIndex(string $s): int {
        $i = array_search($s, $this->strings, true);
        if ($i !== false) return (int)$i;
        if (!$this->sst) {   // 공유 문자열 묶음이 없는 양식 — 만든다
            $this->sst = new DOMDocument('1.0', 'UTF-8');
            $this->sst->appendChild($this->sst->createElementNS(SH_XL_NS, 'sst'));
        }
        $si = $this->sst->createElementNS(SH_XL_NS, 'si');
        $t = $this->sst->createElementNS(SH_XL_NS, 't');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($this->sst->createTextNode($s));
        $si->appendChild($t);
        $this->sst->documentElement->appendChild($si);
        $this->strings[] = $s;
        $this->sstDirty = true;
        return count($this->strings) - 1;
    }

    public function setText(int $r, int $c, string $s): void {
        $el = $this->ensure($r, $c);
        $this->clearValue($el);
        if ($s === '') return;
        $el->setAttribute('t', 's');
        $el->appendChild($this->sheet->createElementNS(SH_XL_NS, 'v', (string)$this->sstIndex($s)));
    }

    public function setNumber(int $r, int $c, ?float $n): void {
        $el = $this->ensure($r, $c);
        $this->clearValue($el);
        if ($n === null) return;
        $el->appendChild($this->sheet->createElementNS(SH_XL_NS, 'v', self::num($n)));
    }

    /** 수식 칸 — 수식은 두고 계산값만. 수식이 없으면 $formula 로 만든다. null = 빈 글("") 결과 */
    public function setCached(int $r, int $c, ?float $n, ?string $formula = null): void {
        $el = $this->ensure($r, $c);
        $hasF = $this->kid($el, 'f') !== null;
        $this->clearValue($el, true);
        if (!$hasF && $formula !== null) $el->appendChild($this->sheet->createElementNS(SH_XL_NS, 'f'))->appendChild($this->sheet->createTextNode($formula));
        if ($n === null) {
            if ($hasF || $formula !== null) { $el->setAttribute('t', 'str'); $el->appendChild($this->sheet->createElementNS(SH_XL_NS, 'v')); }
            return;
        }
        $el->appendChild($this->sheet->createElementNS(SH_XL_NS, 'v', self::num($n)));
    }

    private static function num(float $n): string {
        return floor($n) == $n && abs($n) < 1e15 ? sprintf('%.0f', $n) : rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    }

    public function save(): void {
        $this->zip->addFromString($this->sheetPath, $this->sheet->saveXML());
        if ($this->sst && ($this->sstDirty || $this->sstFilter)) {
            $root = $this->sst->documentElement;
            $root->setAttribute('uniqueCount', (string)count($this->strings));
            $root->setAttribute('count', (string)max(count($this->strings), (int)$root->getAttribute('count')));
            $xml = $this->sst->saveXML();
            $this->zip->addFromString($this->sstPath, $this->sstFilter ? ($this->sstFilter)($xml) : $xml);
        }
    }
}

/* ── 양식 읽기 ─────────────────────────────────────────────────────────────────── */

/** 이름표 칸의 오른쪽 값 칸(이름표가 합쳐진 칸이면 그 끝 다음) */
function sh_xl_value_of(ShXlBook $b, int $r, int $c): array {
    $m = $b->merge($r, $c);
    return [$r, ($m ? $m[3] : $c) + 1];
}

/**
 * 양식에서 찾은 것 — 채울 칸 주소와 견본 값, 그대로 둘 공급자 정보.
 * 품목 표(품명 머리 행 ~ 합계 행)를 못 찾으면 예외.
 */
function sitehub_bill_analyze_book(ShXlBook $b): array {
    $labels = [];   // 띄어쓰기 뺀 글 => 처음 나온 [r, c]
    $dateCell = null;
    foreach ($b->cells as $k => $el) {
        [$r, $c] = array_map('intval', explode(':', $k));
        $t = $b->text($r, $c);
        if ($t === '') continue;
        $n = sh_xl_norm($t);
        if (!isset($labels[$n])) $labels[$n] = [$r, $c];
        if (!$dateCell && preg_match('/^\s*\d{4}\s*년\s*\d{1,2}\s*월\s*(\d{1,2})?\s*일\s*$/u', $t)) $dateCell = [$r, $c];
    }
    $cells = [];
    foreach (SITEHUB_BILL_LABELS as $key => $lab) if (isset($labels[$lab])) $cells[$key] = sh_xl_value_of($b, ...$labels[$lab]);
    if (!isset($labels['품명'])) throw new Exception('청구서 양식에서 품목 표(‘품명’ 머리 칸)를 찾지 못했습니다.');
    $hr = $labels['품명'][0];
    $cols = [];
    $want = ['no' => '/^(no|번호|순번)$/i', 'name' => '/^품명$/u', 'spec' => '/^규격$/u', 'unit' => '/^단위$/u', 'qty' => '/^수량$/u', 'price' => '/^단가$/u', 'amount' => '/^금액$/u'];
    foreach ($b->cells as $k => $el) {
        [$r, $c] = array_map('intval', explode(':', $k));
        if ($r !== $hr) continue;
        $n = sh_xl_norm($b->text($r, $c));
        foreach ($want as $key => $re) if (!isset($cols[$key]) && preg_match($re, $n)) $cols[$key] = $c;
    }
    foreach (['name', 'qty', 'price', 'amount'] as $need) {
        if (!isset($cols[$need])) throw new Exception('청구서 양식 품목 표에 ‘' . ['name' => '품명', 'qty' => '수량', 'price' => '단가', 'amount' => '금액'][$need] . '’ 칸이 없습니다.');
    }
    $tr = null;
    foreach ($b->cells as $k => $el) {
        [$r, $c] = array_map('intval', explode(':', $k));
        if ($r > $hr && sh_xl_norm($b->text($r, $c)) === '합계') { $tr = $r; break; }
    }
    if (!$tr || $tr <= $hr + 1) throw new Exception('청구서 양식에서 품목 표 아래 ‘합계’ 행을 찾지 못했습니다.');
    $grand = [];
    if (isset($labels['합계액'])) {
        $gr = $labels['합계액'][0];
        foreach ($b->cells as $k => $el) {
            [$r, $c] = array_map('intval', explode(':', $k));
            if ($r === $gr && $c > $labels['합계액'][1] && $b->hasFormula($r, $c)) $grand[] = [$r, $c];
        }
    }
    $supplier = [];
    foreach (SITEHUB_BILL_SUPPLIER as $lab) {
        if (!isset($labels[$lab])) continue;
        [$r, $c] = sh_xl_value_of($b, ...$labels[$lab]);
        $supplier[$lab] = trim($b->text($r, $c));
    }
    $sample = [];
    foreach (['recipient', 'ref', 'subject', 'manager'] as $k) $sample[$k] = isset($cells[$k]) ? trim($b->text(...$cells[$k])) : null;
    $r1 = $hr + 1;
    $sample['item'] = ['name' => trim($b->text($r1, $cols['name'])), 'spec' => isset($cols['spec']) ? trim($b->text($r1, $cols['spec'])) : '',
                       'unit' => isset($cols['unit']) ? trim($b->text($r1, $cols['unit'])) : '', 'qty' => $b->text($r1, $cols['qty']), 'price' => $b->text($r1, $cols['price'])];
    $sample['date'] = $dateCell ? trim($b->text(...$dateCell)) : null;
    return ['cells' => $cells + ['date' => $dateCell], 'items' => ['head' => $hr, 'first' => $hr + 1, 'last' => $tr - 1, 'total' => $tr, 'cols' => $cols],
            'grand' => $grand, 'supplier' => $supplier, 'sample' => $sample];
}

function sitehub_bill_analyze(string $path = SITEHUB_BILL_TPL): array {
    static $cache = [];
    $key = $path . '@' . @filemtime($path);
    if (isset($cache[$key])) return $cache[$key];
    if (!is_file($path)) throw new Exception('청구서 양식 파일이 없습니다 (sitehub/templates/bill.xlsx).');
    $b = ShXlBook::open($path);
    $a = sitehub_bill_analyze_book($b);
    $b->zip->close();
    return $cache[$key] = $a;
}

/** 양식 안내 — 화면 1번 칸에 보여 줄 것 */
function sitehub_bill_tpl_info(): array {
    $a = sitehub_bill_analyze();
    return ['file_name' => '대금청구서.xlsx', 'file_size' => (int)filesize(SITEHUB_BILL_TPL), 'updated_at' => date('Y-m-d H:i', (int)filemtime(SITEHUB_BILL_TPL)),
            'item_rows' => $a['items']['last'] - $a['items']['first'] + 1, 'supplier' => $a['supplier'], 'sample' => $a['sample'],
            'found' => array_keys(array_filter($a['cells']))];
}

/* ── 금액 ──────────────────────────────────────────────────────────────────────── */

/** 일금 … 원정 의 한글 숫자 — 엑셀 [DBNum4] 와 같게 (264000 → 이십육만사천) */
function sitehub_won_hangul(int $n): string {
    if ($n === 0) return '영';
    $neg = $n < 0;
    $n = abs($n);
    $dig = ['', '일', '이', '삼', '사', '오', '육', '칠', '팔', '구'];
    $small = ['', '십', '백', '천'];
    $big = ['', '만', '억', '조', '경'];
    $out = '';
    for ($g = 0; $n > 0; $g++) {
        $part = $n % 10000;
        $n = intdiv($n, 10000);
        if (!$part) continue;
        $s = '';
        for ($i = 3; $i >= 0; $i--) { $d = intdiv($part, 10 ** $i) % 10; if ($d) $s .= $dig[$d] . $small[$i]; }
        $out = $s . $big[$g] . $out;
    }
    return ($neg ? '마이너스' : '') . $out;
}

/** 기간과 가장 많이 겹치는 계약 (없으면 가장 최근 계약 + outside 표시) */
function sitehub_bill_contract(PDO $pdo, int $siteId, string $from, string $to): ?array {
    $st = $pdo->prepare("SELECT * FROM lab_site_contracts WHERE site_id = ? ORDER BY (end_date IS NULL) DESC, end_date DESC, id DESC");
    $st->execute([$siteId]);
    $best = null; $bestDays = 0; $first = null;
    foreach ($st->fetchAll() as $c) {
        $first ??= $c;
        $a = max($from, (string)($c['start_date'] ?: '0000-01-01'));
        $b = min($to, (string)($c['end_date'] ?: '9999-12-31'));
        $days = $a <= $b ? (int)round((strtotime($b) - strtotime($a)) / 86400) + 1 : 0;
        if ($days > $bestDays) { $best = $c; $bestDays = $days; }
    }
    if ($best) {
        $total = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
        return $best + ['outside' => 0, 'partial' => $bestDays < $total ? 1 : 0];
    }
    return $first ? $first + ['outside' => 1, 'partial' => 0] : null;
}

/** 연간 계약 금액 → VAT 포함 월 단가. [월 단가, 나누어떨어지는가] */
function sitehub_bill_monthly(array $c, string $rounding): array {
    $amt = (int)($c['amount'] ?? 0);
    if ($amt <= 0) return [0, true];
    $inc = (int)($c['vat_included'] ?? 0) === 1;
    $num = $inc ? $amt : $amt * 11;
    $den = $inc ? 12 : 120;
    $m = $rounding === 'round' ? intdiv($num * 2 + $den, 2 * $den) : intdiv($num, $den);
    return [$m, $num % $den === 0];
}

/* ── 규칙 · 틀 ─────────────────────────────────────────────────────────────────── */

function sitehub_bill_cfg(PDO $pdo, int $siteId): array {
    $st = $pdo->prepare("SELECT b.settings, b.updated_at, b.updated_by, u.name AS updated_name FROM lab_bill_settings b LEFT JOIN portal_users u ON u.email = b.updated_by WHERE b.site_id = ?");
    $st->execute([$siteId]);
    $row = $st->fetch();
    $s = ($row ? (json_decode((string)$row['settings'], true) ?: []) : []) + SITEHUB_BILL_DEFAULTS;
    return ['settings' => $s, 'saved' => (bool)$row, 'updated_at' => $row['updated_at'] ?? null, 'updated_name' => $row['updated_name'] ?? ($row['updated_by'] ?? null)];
}

function sitehub_bill_clean_settings(array $in, array $base): array {
    $s = $base + SITEHUB_BILL_DEFAULTS;
    foreach (['recipient' => 200, 'ref' => 200, 'org' => 100, 'subject' => 300, 'item' => 300, 'spec' => 60, 'unit' => 20, 'manager' => 100] as $k => $max) {
        if (array_key_exists($k, $in)) $s[$k] = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)$in[$k])), 0, $max);
    }
    if (array_key_exists('price', $in)) $s['price'] = max(0, min(999999999999, (int)preg_replace('/[^\d]/', '', (string)$in['price'])));
    if (isset($in['q_start'])) $s['q_start'] = max(1, min(12, (int)$in['q_start']));
    if (array_key_exists('day_blank', $in)) $s['day_blank'] = $in['day_blank'] ? 1 : 0;
    if (isset($in['rounding'])) $s['rounding'] = $in['rounding'] === 'round' ? 'round' : 'floor';
    if (isset($in['font'])) $s['font'] = $in['font'] === 'template' ? 'template' : 'pretendard';
    return array_intersect_key($s, SITEHUB_BILL_DEFAULTS);
}

/** 틀에 넣는 값 — 분기·학년도는 1분기 시작 월 기준 (3월이면 3~5월 = 1분기, 12~2월 = 4분기, 학년도 = 그 해) */
function sitehub_bill_tokens(string $from, string $to, int $qStart, string $org): array {
    $fy = (int)substr($from, 0, 4); $fm = (int)substr($from, 5, 2);
    $ty = (int)substr($to, 0, 4); $tm = (int)substr($to, 5, 2);
    $idx = ($fm - $qStart + 12) % 12;
    return ['학년도' => $fm >= $qStart ? $fy : $fy - 1, '분기' => intdiv($idx, 3) + 1, '연도' => $fy, '시작월' => $fm, '끝월' => $tm,
            '개월' => ($ty - $fy) * 12 + $tm - $fm + 1, '기관' => $org, '_aligned' => $idx % 3 === 0 ? 1 : 0];
}

function sitehub_bill_apply(string $tpl, array $tok): string {
    return trim(preg_replace_callback('/\{(' . implode('|', SITEHUB_BILL_TOKENS) . ')\}/u', fn($m) => (string)$tok[$m[1]], $tpl));
}

function sitehub_bill_org(array $site, array $s): string {
    return trim((string)$s['org']) !== '' ? trim((string)$s['org']) : trim((string)$site['name']);
}

function sitehub_bill_recipient(array $site, array $s): string {
    if (trim((string)$s['recipient']) !== '') return trim((string)$s['recipient']);
    $cust = trim((string)($site['customer'] ?? '')); $name = trim((string)$site['name']);
    if ($cust === '' || mb_strpos($name, $cust) !== false) return $name;
    return $cust . ' ' . $name;
}

function sitehub_bill_file_name(array $tok): string {
    $org = preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', (string)$tok['기관']);
    $span = $tok['개월'] === 3 && $tok['_aligned'] ? $tok['학년도'] . '_' . $tok['분기'] . '분기' : sprintf('%d_%02d-%02d월', $tok['연도'], $tok['시작월'], $tok['끝월']);
    return trim($org, '_') . '_대금청구서_' . $span . '.xlsx';
}

/**
 * 기간의 청구서 값(저장 안 함) — 화면이 고친 뒤 bill_build 로 보낸다.
 */
function sitehub_bill_preview(PDO $pdo, array $site, string $from, string $to): array {
    $cfg = sitehub_bill_cfg($pdo, (int)$site['id'])['settings'];
    $an = sitehub_bill_analyze();
    $org = sitehub_bill_org($site, $cfg);
    $tok = sitehub_bill_tokens($from, $to, (int)$cfg['q_start'], $org);
    $notes = []; $warn = [];
    $contract = sitehub_bill_contract($pdo, (int)$site['id'], $from, $to);
    $kinds = function_exists('sitehub_contract_kinds') ? sitehub_contract_kinds() : [];
    $csum = null;
    $price = (int)$cfg['price'];
    if ($contract) {
        [$monthly, $exact] = sitehub_bill_monthly($contract, (string)$cfg['rounding']);
        $csum = ['kind' => $kinds[$contract['kind']] ?? $contract['kind'], 'amount' => $contract['amount'] !== null ? (int)$contract['amount'] : null,
                 'vat_included' => (int)$contract['vat_included'], 'billing' => (string)$contract['billing'],
                 'start' => $contract['start_date'], 'end' => $contract['end_date'], 'monthly' => $monthly];
        if (in_array($contract['kind'], ['free', 'none'], true)) $warn[] = "계약 형태가 ‘{$csum['kind']}’ 입니다 — 청구할 금액이 맞는지 확인하세요.";
        if ($contract['outside']) $warn[] = '이 기간에 걸친 계약이 없습니다 — 가장 최근 계약(' . ($contract['start_date'] ?: '?') . ' ~ ' . ($contract['end_date'] ?: '?') . ')으로 계산했습니다.';
        elseif ($contract['partial']) $warn[] = '계약 기간(' . ($contract['start_date'] ?: '?') . ' ~ ' . ($contract['end_date'] ?: '?') . ')이 청구 기간 일부만 덮습니다 — 개월 수를 확인하세요.';
        if ($price <= 0) {
            $price = $monthly;
            if ($monthly > 0) {
                $notes[] = '월 단가 ' . number_format($monthly) . '원 = 계약 연간 ' . number_format((int)$contract['amount']) . '원'
                    . ((int)$contract['vat_included'] ? ' (VAT 포함)' : ' + VAT 10%') . ' ÷ 12';
                if (!$exact) $warn[] = '연간 금액이 12개월로 나누어떨어지지 않아 원 미만을 ' . ($cfg['rounding'] === 'round' ? '반올림' : '버림') . '했습니다 — 마지막 회차는 금액을 맞춰 고치세요.';
            } else {
                $warn[] = '계약 탭에 연간 금액이 없습니다 — 단가를 직접 넣거나 계약 탭에 금액을 적으세요.';
            }
        }
        $per = ['월' => 1, '분기' => 3, '반기' => 6, '연' => 12][$contract['billing']] ?? null;
        if ($per && $per !== $tok['개월']) $warn[] = "납부 주기가 ‘{$contract['billing']}’({$per}개월)인데 청구 기간은 {$tok['개월']}개월입니다.";
    } elseif ($price <= 0) {
        $warn[] = '계약 탭에 계약이 없습니다 — 월 단가를 직접 넣으세요.';
    }
    if ((int)$cfg['price'] > 0) $notes[] = '월 단가 ' . number_format((int)$cfg['price']) . '원 — 이 사이트 규칙에 적은 값';
    $manager = trim((string)$cfg['manager']);
    if ($manager === '') {
        $tpl = sitehub_report_tpl($pdo, (int)$site['id']);
        $manager = trim((string)($tpl['settings']['inspector'] ?? '')) ?: (string)($an['sample']['manager'] ?? '');
    }
    return [
        'recipient' => sitehub_bill_recipient($site, $cfg), 'ref' => (string)$cfg['ref'], 'subject' => sitehub_bill_apply((string)$cfg['subject'], $tok),
        'manager' => $manager,
        'items' => [['name' => sitehub_bill_apply((string)$cfg['item'], $tok), 'spec' => (string)$cfg['spec'], 'unit' => (string)$cfg['unit'], 'qty' => $tok['개월'], 'price' => $price]],
        'bill_date' => date('Y-m-t', strtotime(substr($to, 0, 7) . '-01')), 'day_blank' => (int)$cfg['day_blank'],
        'file_name' => sitehub_bill_file_name($tok), 'contract' => $csum, 'notes' => $notes, 'warn' => $warn,
        'tokens' => array_diff_key($tok, ['_aligned' => 1]),
    ];
}

/* ── 채우기 ────────────────────────────────────────────────────────────────────── */

/** 'YYYY년  M월   일' — 양식 칸의 띄어쓰기를 살려 연·월(·일)만 바꾼다 */
function sitehub_bill_date_text(string $orig, string $ymd, bool $dayBlank): string {
    $t = strtotime($ymd);
    $y = date('Y', $t); $m = date('n', $t); $d = date('j', $t);
    if (preg_match('/^(\s*)\d{4}(\s*년\s*)\d{1,2}(\s*월)(\s*)(\d{1,2})?(\s*일\s*)$/u', $orig, $g)) {
        $gap = $g[4] !== '' ? $g[4] : ' ';
        $hadDay = ($g[5] ?? '') !== '';
        return $g[1] . $y . $g[2] . $m . $g[3] . ($dayBlank ? ($hadDay ? $gap . '  ' : $gap) : $gap . $d) . $g[6];
    }
    return $dayBlank ? "{$y}년  {$m}월   일" : "{$y}년  {$m}월  {$d}일";
}

/** 엑셀 글꼴을 Pretendard 로 — 기본 글꼴(0번)은 열 너비 계산 기준이라 그대로 둔다. 테마 글꼴 지정(scheme)은 빼야 이름이 먹는다 */
function sh_xl_font_pretendard(string $xml, bool $skipFirst): string {
    $i = 0;
    return preg_replace_callback('/<(font|rPr)\b[^>]*>(.*?)<\/\1>/su', function ($m) use (&$i, $skipFirst) {
        if ($m[1] === 'font' && $skipFirst && $i++ === 0) return $m[0];
        $in = preg_replace('/<scheme\b[^>]*\/>/u', '', $m[2]);
        $in = preg_replace('/<(name|rFont)\s+val="[^"]*"\s*\/>/u', '<$1 val="Pretendard"/>', $in);
        return str_replace($m[2], $in, $m[0]);
    }, $xml);
}

/**
 * 양식 복사본에 값 채우기.
 * $d = recipient, ref, subject, manager, items[{name, spec, unit, qty, price}], bill_date, day_blank, font
 */
function sitehub_bill_fill(string $tpl, string $out, array $d): array {
    if (!@copy($tpl, $out)) throw new Exception('청구서 파일을 만들 수 없습니다 (var/sitehub/reports 쓰기 권한).');
    $b = ShXlBook::open($out, true);
    $a = sitehub_bill_analyze_book($b);
    $lead = function (string $orig, string $fallback = '') { return preg_match('/^\s/u', $orig !== '' ? $orig : $fallback) ? ' ' : ''; };
    $recOrig = isset($a['cells']['recipient']) ? $b->text(...$a['cells']['recipient']) : '';
    foreach (['recipient', 'ref', 'subject', 'manager'] as $k) {
        if (!isset($a['cells'][$k])) continue;
        [$r, $c] = $a['cells'][$k];
        $v = trim((string)($d[$k] ?? ''));
        $b->setText($r, $c, $v === '' ? '' : $lead($b->text($r, $c), $recOrig) . $v);
    }
    // 품목 줄 — 쓰는 줄은 채우고 나머지는 비운다. 금액 칸은 수식을 두고 계산값만(수식이 없는 줄은 첫 줄과 같은 수식을 넣는다)
    $it = $a['items']; $cols = $it['cols'];
    $L = fn($c) => sh_xl_letters($c);
    $nameLead = $lead($b->text($it['first'], $cols['name']));
    $items = array_values($d['items'] ?? []);
    $sumQty = 0; $sum = 0;
    for ($r = $it['first'], $i = 0; $r <= $it['last']; $r++, $i++) {
        $x = $items[$i] ?? null;
        if (isset($cols['no'])) $b->setNumber($r, $cols['no'], $x ? $i + 1 : null);
        $b->setText($r, $cols['name'], $x && $x['name'] !== '' ? $nameLead . $x['name'] : '');
        foreach (['spec', 'unit'] as $k) if (isset($cols[$k])) $b->setText($r, $cols[$k], $x ? (string)$x[$k] : '');
        $b->setNumber($r, $cols['qty'], $x && $x['qty'] !== null ? (float)$x['qty'] : null);
        $b->setNumber($r, $cols['price'], $x && $x['price'] !== null ? (float)$x['price'] : null);
        $amt = $x ? (float)$x['qty'] * (float)$x['price'] : 0;
        $f = sprintf('IF(%1$s%3$d*%2$s%3$d=0, "", %1$s%3$d*%2$s%3$d)', $L($cols['qty']), $L($cols['price']), $r);
        if ($b->hasFormula($r, $cols['amount']) || $x) $b->setCached($r, $cols['amount'], $amt ? $amt : null, $x ? $f : null);
        $sumQty += $x ? (float)$x['qty'] : 0;
        $sum += $amt;
    }
    $tr = $it['total'];
    foreach (['qty' => $sumQty, 'amount' => $sum] as $k => $v) {
        if ($b->hasFormula($tr, $cols[$k])) $b->setCached($tr, $cols[$k], $v ? $v : null);
        else $b->setNumber($tr, $cols[$k], $v ? $v : null);
    }
    foreach ($a['grand'] as [$r, $c]) $b->setCached($r, $c, $sum);
    if ($a['cells']['date']) {
        [$r, $c] = $a['cells']['date'];
        $b->setText($r, $c, sitehub_bill_date_text($b->text($r, $c), (string)$d['bill_date'], !empty($d['day_blank'])));
    }
    $pretendard = ($d['font'] ?? 'pretendard') === 'pretendard';
    if ($pretendard) $b->sstFilter = fn(string $x) => sh_xl_font_pretendard($x, false);
    $b->save();
    // 열 때 다시 계산 + 글꼴 (zip 에 넣은 항목은 닫기 전에 다시 못 읽으므로 원본 항목만 읽어 고친다)
    $wb = (string)$b->zip->getFromName('xl/workbook.xml');
    if (preg_match('/<calcPr\b[^>]*\/>/u', $wb, $m)) $wb = str_replace($m[0], preg_match('/fullCalcOnLoad=/', $m[0]) ? $m[0] : str_replace('/>', ' fullCalcOnLoad="1"/>', $m[0]), $wb);
    else $wb = str_replace('</workbook>', '<calcPr fullCalcOnLoad="1"/></workbook>', $wb);
    $b->zip->addFromString('xl/workbook.xml', $wb);
    if ($pretendard) {
        $st = $b->zip->getFromName('xl/styles.xml');
        if ($st !== false && preg_match('/<fonts\b[^>]*>.*?<\/fonts>/su', $st, $m)) $b->zip->addFromString('xl/styles.xml', str_replace($m[0], sh_xl_font_pretendard($m[0], true), $st));
    }
    // 계산 순서 기록(calcChain)은 지운다 — 수식 칸이 양식과 달라지면 엑셀이 '복구' 를 묻는 원인이 된다. 엑셀이 열 때 다시 만든다
    if ($b->zip->locateName('xl/calcChain.xml') !== false) {
        $b->zip->deleteName('xl/calcChain.xml');
        $ct = (string)$b->zip->getFromName('[Content_Types].xml');
        $b->zip->addFromString('[Content_Types].xml', preg_replace('#<Override\b[^>]*PartName="/xl/calcChain\.xml"[^>]*/>#', '', $ct));
        $rels = (string)$b->zip->getFromName('xl/_rels/workbook.xml.rels');
        $b->zip->addFromString('xl/_rels/workbook.xml.rels', preg_replace('#<Relationship\b[^>]*Target="[^"]*calcChain\.xml"[^>]*/>#', '', $rels));
    }
    if (!$b->zip->close()) throw new Exception('청구서 파일을 저장하지 못했습니다.');
    return ['total' => (int)round($sum), 'qty' => $sumQty, 'words' => '일금 ' . sitehub_won_hangul((int)round($sum)) . '원정'];
}

/* ── 저장 · 목록 ───────────────────────────────────────────────────────────────── */

function sitehub_bill_list(PDO $pdo, int $siteId): array {
    $st = $pdo->prepare("SELECT b.id, b.site_id, b.period_from, b.period_to, b.bill_date, b.subject, b.total, b.file_name, b.created_at, b.created_by,
                                COALESCE(NULLIF(b.created_name, ''), u.name, b.created_by) AS created_name
                         FROM lab_bills b LEFT JOIN portal_users u ON u.email = b.created_by WHERE b.site_id = ? ORDER BY b.period_to DESC, b.id DESC LIMIT 50");
    $st->execute([$siteId]);
    return array_map(function ($r) { $r['id'] = (int)$r['id']; $r['site_id'] = (int)$r['site_id']; $r['total'] = (int)$r['total']; return $r; }, $st->fetchAll());
}

/** 화면이 보낸 값 다듬기 */
function sitehub_bill_clean_input(array $in, int $maxItems): array {
    $items = [];
    foreach ((array)($in['items'] ?? []) as $x) {
        $name = mb_substr(trim((string)($x['name'] ?? '')), 0, 300);
        $qty = ($x['qty'] ?? '') === '' || $x['qty'] === null ? null : (float)str_replace(',', '', (string)$x['qty']);
        $price = ($x['price'] ?? '') === '' || $x['price'] === null ? null : (float)str_replace(',', '', (string)$x['price']);
        if ($name === '' && !$qty && !$price) continue;
        $items[] = ['name' => $name, 'spec' => mb_substr(trim((string)($x['spec'] ?? '')), 0, 60), 'unit' => mb_substr(trim((string)($x['unit'] ?? '')), 0, 20),
                    'qty' => $qty, 'price' => $price];
        if (count($items) >= $maxItems) break;
    }
    return [
        'recipient' => mb_substr(trim((string)($in['recipient'] ?? '')), 0, 200), 'ref' => mb_substr(trim((string)($in['ref'] ?? '')), 0, 200),
        'subject' => mb_substr(trim((string)($in['subject'] ?? '')), 0, 300), 'manager' => mb_substr(trim((string)($in['manager'] ?? '')), 0, 100),
        'items' => $items, 'bill_date' => sitehub_report_parse_date((string)($in['bill_date'] ?? '')) ?? '', 'day_blank' => !empty($in['day_blank']) ? 1 : 0,
    ];
}

/** 청구서 만들기 → xlsx·pdf 저장 + lab_bills 기록 */
function sitehub_bill_build(PDO $pdo, array $me, array $site, array $in): array {
    $from = (string)($in['from'] ?? ''); $to = (string)($in['to'] ?? '');
    if (!sitehub_report_parse_date($from) || !sitehub_report_parse_date($to) || $from > $to) throw new Exception('기간을 확인하세요.');
    $an = sitehub_bill_analyze();
    $d = sitehub_bill_clean_input($in, $an['items']['last'] - $an['items']['first'] + 1);
    if (!$d['items']) throw new Exception('품목을 한 줄 이상 넣으세요.');
    if ($d['bill_date'] === '') $d['bill_date'] = date('Y-m-t', strtotime(substr($to, 0, 7) . '-01'));
    $cfg = sitehub_bill_cfg($pdo, (int)$site['id'])['settings'];
    $d['font'] = $cfg['font'];
    $name = trim((string)($in['file_name'] ?? ''));
    if ($name === '') $name = sitehub_bill_file_name(sitehub_bill_tokens($from, $to, (int)$cfg['q_start'], sitehub_bill_org($site, $cfg)));
    $name = preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', '_', $name);
    if (!preg_match('/\.xlsx$/i', $name)) $name .= '.xlsx';
    $stored = 'site' . (int)$site['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.xlsx';
    $out = sitehub_report_dir('bill') . '/' . $stored;
    $res = sitehub_bill_fill(SITEHUB_BILL_TPL, $out, $d);
    $pdfError = null;
    try { sitehub_bill_make_pdf($out, $d['subject']); } catch (Throwable $e) { $pdfError = $e->getMessage(); }
    $pdo->prepare("INSERT INTO lab_bills (site_id, period_from, period_to, bill_date, subject, data, total, file_name, file_path, created_at, created_by, created_name)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)")
        ->execute([(int)$site['id'], $from, $to, $d['bill_date'], mb_substr($d['subject'], 0, 300), json_encode($d, JSON_UNESCAPED_UNICODE), $res['total'],
                   mb_substr($name, 0, 255), $stored, (string)($me['email'] ?? ''), (string)($me['name'] ?? '')]);
    $id = (int)$pdo->lastInsertId();
    sitehub_audit($pdo, $me, (int)$site['id'], 'create', 'bills', $id, $name, [], "{$from} ~ {$to} · " . number_format($res['total']) . '원');
    return ['id' => $id, 'file_name' => $name, 'pdf_name' => sitehub_bill_pdf_name($name), 'total' => $res['total'], 'words' => $res['words'], 'pdf_error' => $pdfError];
}

function sitehub_bill_pdf_name(string $xlsxName): string {
    return preg_replace('/\.xlsx$/i', '', $xlsxName) . '.pdf';
}

/** 채운 xlsx 옆에 같은 이름의 .pdf */
function sitehub_bill_make_pdf(string $xlsxPath, string $title = ''): string {
    require_once __DIR__ . '/bill_pdf.php';
    $pdf = sitehub_bill_pdf_name($xlsxPath);
    sitehub_bill_pdf($xlsxPath, $pdf, $title);
    return $pdf;
}

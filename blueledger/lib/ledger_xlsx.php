<?php
/**
 * BlueLedger 회계 장부 — 엑셀(xlsx) 만들기: 일일출납장·자금일보.
 *
 *  파이썬 버전은 openpyxl 로 '지금까지 쓰던 출납장과 같은 모양'(칸 합치기·테두리·수식·결재란)을 만들었다.
 *  포털에 있는 bluecart/includes/XlsxWriter.php 는 서식·수식을 지원하지 않아, 같은 방식
 *  (composer·ZipArchive 없이 ZIP 을 직접 씀)으로 서식이 되는 작은 작성기를 따로 두었다.
 *
 *  칸 값: 정수·실수·글자, ['f' => '수식(= 없이)'], ['date' => 'YYYY-MM-DD'], null(값 없이 서식만)
 *  서식: ['font' => [크기, 굵게], 'fill' => 'DDEBF7', 'border' => true,
 *         'align' => ['h' => 'center', 'v' => 'center', 'wrap' => true], 'fmt' => '#,##0']
 */

require_once __DIR__ . '/ledger.php';

const LG_XLSX_FONT = '맑은 고딕';
const LG_XLSX_BORDER = 'A6A6A6';

class LgSheet {
    public string $title;
    public array $cells = [];      // [행][열번호] => ['v' => 값, 's' => 서식]
    public array $merges = [];
    public array $widths = [];
    public array $heights = [];
    public ?string $freeze = null;
    public ?string $autoFilter = null;      // 'A1:I20' — 머리줄에 거르기 단추
    public ?string $orientation = null;    // 정하지 않으면 엑셀 기본
    public bool $fitToPage = false;
    public int $fitToWidth = 1;
    public int $fitToHeight = 1;

    public function __construct(string $title) { $this->title = $title; }

    /** 값 넣기 (서식은 그대로 둔다) */
    public function v(string $ref, $value): void {
        [$c, $r] = lg_xlsx_ref($ref);
        $this->cells[$r][$c]['v'] = $value;
        $this->cells[$r][$c]['s'] ??= [];
    }

    /** 서식 더하기 (값은 그대로, 같은 항목은 덮어쓴다) */
    public function s(string $ref, array $style): void {
        [$c, $r] = lg_xlsx_ref($ref);
        $this->cells[$r][$c]['s'] = array_merge($this->cells[$r][$c]['s'] ?? [], $style);
        $this->cells[$r][$c] += ['v' => null];
    }

    public function merge(string $range): void { $this->merges[] = $range; }
}

class LgXlsx {
    /** @var LgSheet[] */
    public array $sheets = [];
    private array $styleIds = [];
    private array $fonts = [];
    private array $fills = [];
    private array $borders = [];
    private array $numFmts = [];
    private array $xfs = [];

    public function addSheet(string $title): LgSheet {
        return $this->sheets[] = new LgSheet($title);
    }

    public function build(): string {
        // 서식 목록 0번은 엑셀 기본값(Calibri 11, 채우기 없음, 테두리 없음)
        $this->fonts = ['<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>' => 0];
        $this->fills = ['<fill><patternFill patternType="none"/></fill>' => 0, '<fill><patternFill patternType="gray125"/></fill>' => 1];
        $this->borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>' => 0];
        $this->numFmts = [];
        $this->xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' => 0];
        $this->styleIds = [];
        $sheetXml = [];
        foreach ($this->sheets as $sh) $sheetXml[] = $this->sheetXml($sh);

        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => $this->styles(),
        ];
        foreach ($sheetXml as $i => $x) $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $x;
        return lg_zip($files);
    }

    public function download(string $filename): never {
        $bin = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
        exit;
    }

    private function styleId(array $s): int {
        if (!$s) return 0;
        $key = json_encode($s);
        if (isset($this->styleIds[$key])) return $this->styleIds[$key];

        [$sz, $bold] = $s['font'] ?? [null, false];
        $fontId = 0;
        if ($sz !== null) {
            $font = '<font>' . ($bold ? '<b/>' : '') . '<sz val="' . $sz . '"/><name val="' . LG_XLSX_FONT . '"/><family val="2"/></font>';
            $fontId = $this->fonts[$font] ??= count($this->fonts);
        }
        $fillId = 0;
        if (!empty($s['fill'])) {
            $fill = '<fill><patternFill patternType="solid"><fgColor rgb="00' . $s['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>';
            $fillId = $this->fills[$fill] ??= count($this->fills);
        }
        $borderId = 0;
        if (!empty($s['border'])) {
            $side = fn($n) => "<$n style=\"thin\"><color rgb=\"00" . LG_XLSX_BORDER . "\"/></$n>";
            $border = '<border>' . $side('left') . $side('right') . $side('top') . $side('bottom') . '<diagonal/></border>';
            $borderId = $this->borders[$border] ??= count($this->borders);
        }
        $numFmtId = 0;
        if (!empty($s['fmt'])) {
            $builtin = ['General' => 0, '0' => 1, '#,##0' => 3, '@' => 49];
            $numFmtId = $builtin[$s['fmt']] ?? ($this->numFmts[$s['fmt']] ??= 164 + count($this->numFmts));
        }
        $align = '';
        if (!empty($s['align'])) {
            $a = $s['align'];
            $align = '<alignment' . (isset($a['h']) ? ' horizontal="' . $a['h'] . '"' : '') . (isset($a['v']) ? ' vertical="' . $a['v'] . '"' : '')
                   . (!empty($a['wrap']) ? ' wrapText="1"' : '') . '/>';
        }
        $xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
            . ($numFmtId ? ' applyNumberFormat="1"' : '') . ($fontId ? ' applyFont="1"' : '') . ($fillId ? ' applyFill="1"' : '')
            . ($borderId ? ' applyBorder="1"' : '') . ($align ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        return $this->styleIds[$key] = $this->xfs[$xf] ??= count($this->xfs);
    }

    private function sheetXml(LgSheet $sh): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($sh->fitToPage) $x .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $x .= '<sheetViews><sheetView workbookViewId="0">';
        if ($sh->freeze) {
            [$c, $r] = lg_xlsx_ref($sh->freeze);
            $attr = ($c > 1 ? ' xSplit="' . ($c - 1) . '"' : '') . ($r > 1 ? ' ySplit="' . ($r - 1) . '"' : '');
            $pane = $c > 1 && $r > 1 ? 'bottomRight' : ($r > 1 ? 'bottomLeft' : 'topRight');
            $x .= '<pane' . $attr . ' topLeftCell="' . $sh->freeze . '" activePane="' . $pane . '" state="frozen"/>'
                . '<selection pane="' . $pane . '" activeCell="' . $sh->freeze . '" sqref="' . $sh->freeze . '"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($sh->widths) {
            $x .= '<cols>';
            $w = $sh->widths;
            ksort($w);
            foreach ($w as $c => $width) $x .= '<col min="' . $c . '" max="' . $c . '" width="' . $width . '" customWidth="1"/>';
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $rows = $sh->cells;
        foreach (array_keys($sh->heights) as $r) $rows[$r] ??= [];
        ksort($rows);
        foreach ($rows as $r => $cols) {
            ksort($cols);
            $x .= '<row r="' . $r . '"' . (isset($sh->heights[$r]) ? ' ht="' . $sh->heights[$r] . '" customHeight="1"' : '') . '>';
            foreach ($cols as $c => $cell) $x .= $this->cellXml(lg_xlsx_col($c) . $r, $cell['v'], $this->styleId($cell['s']));
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sh->autoFilter) $x .= '<autoFilter ref="' . $sh->autoFilter . '"/>';
        if ($sh->merges) {
            $x .= '<mergeCells count="' . count($sh->merges) . '">';
            foreach ($sh->merges as $m) $x .= '<mergeCell ref="' . $m . '"/>';
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.75" right="0.75" top="1" bottom="1" header="0.5" footer="0.5"/>';
        if ($sh->orientation || $sh->fitToPage) {
            $x .= '<pageSetup' . ($sh->orientation ? ' orientation="' . $sh->orientation . '"' : '')
                . ($sh->fitToPage ? ' fitToWidth="' . $sh->fitToWidth . '" fitToHeight="' . $sh->fitToHeight . '"' : '') . '/>';
        }
        return $x . '</worksheet>';
    }

    private function cellXml(string $ref, $v, int $s): string {
        $sa = $s ? ' s="' . $s . '"' : '';
        if ($v === null || $v === '') return "<c r=\"$ref\"$sa/>";
        if (is_array($v) && isset($v['f'])) return "<c r=\"$ref\"$sa><f>" . lg_xml($v['f']) . '</f></c>';
        if (is_array($v) && isset($v['date'])) return "<c r=\"$ref\"$sa><v>" . lg_xlsx_serial($v['date']) . '</v></c>';
        if (is_int($v) || is_float($v)) return "<c r=\"$ref\"$sa><v>" . $v . '</v></c>';
        return "<c r=\"$ref\"$sa t=\"inlineStr\"><is><t xml:space=\"preserve\">" . lg_xml((string)$v) . '</t></is></c>';
    }

    private function contentTypes(): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
           . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
           . '<Default Extension="xml" ContentType="application/xml"/>'
           . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
           . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($this->sheets as $i => $_) {
            $x .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $x . '</Types>';
    }

    private function workbook(): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $sh) {
            $x .= '<sheet name="' . lg_xml($sh->title) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        // 수식 칸은 계산값 없이 저장한다 — 엑셀이 열 때 다시 계산하게
        return $x . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    }

    private function workbookRels(): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $n = count($this->sheets);
        for ($i = 1; $i <= $n; $i++) {
            $x .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $x .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $x . '</Relationships>';
    }

    private function styles(): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($this->numFmts) {
            $x .= '<numFmts count="' . count($this->numFmts) . '">';
            foreach ($this->numFmts as $code => $id) $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . lg_xml($code) . '"/>';
            $x .= '</numFmts>';
        }
        $x .= '<fonts count="' . count($this->fonts) . '">' . implode('', array_keys($this->fonts)) . '</fonts>';
        $x .= '<fills count="' . count($this->fills) . '">' . implode('', array_keys($this->fills)) . '</fills>';
        $x .= '<borders count="' . count($this->borders) . '">' . implode('', array_keys($this->borders)) . '</borders>';
        $x .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $x .= '<cellXfs count="' . count($this->xfs) . '">' . implode('', array_keys($this->xfs)) . '</cellXfs>';
        $x .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        return $x . '</styleSheet>';
    }
}

function lg_xml(string $s): string {
    // XML 에 넣을 수 없는 제어 문자는 뺀다
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** 'E12' → [5, 12] */
function lg_xlsx_ref(string $ref): array {
    preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
    $c = 0;
    foreach (str_split($m[1]) as $ch) $c = $c * 26 + (ord($ch) - 64);
    return [$c, (int)$m[2]];
}

function lg_xlsx_col(int $c): string {
    $s = '';
    while ($c > 0) { $m = ($c - 1) % 26; $s = chr(65 + $m) . $s; $c = intdiv($c - 1, 26); }
    return $s;
}

/** 엑셀 날짜 일련번호 (1899-12-30 부터 센 날 수) */
function lg_xlsx_serial(string $d): int {
    return (int)round((strtotime($d . ' 00:00:00 UTC') - strtotime('1899-12-30 00:00:00 UTC')) / 86400);
}

/** ZIP 묶기 — bluecart/includes/XlsxWriter.php 와 같은 방식(ZipArchive 없이) */
function lg_zip(array $files): string {
    $local = '';
    $central = '';
    $offset = 0;
    $t = getdate();
    $mtime = (($t['year'] - 1980) << 25) | ($t['mon'] << 21) | ($t['mday'] << 16)
           | ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $uncomp = strlen($content);
        $deflated = gzdeflate($content, 6);
        if ($deflated === false || strlen($deflated) >= $uncomp) { $data = $content; $method = 0; }
        else { $data = $deflated; $method = 8; }
        $comp = strlen($data);
        $head = pack('vvvVVVVvv', 20, 0, $method, $mtime, $crc, $comp, $uncomp, strlen($name), 0);
        $local .= "\x50\x4b\x03\x04" . $head . $name . $data;
        $central .= "\x50\x4b\x01\x02" . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 32, $offset) . $name;
        $offset += 30 + strlen($name) + $comp;
    }
    return $local . $central . "\x50\x4b\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), $offset, 0);
}

/* ================================================================ 일일출납장 엑셀 */

const LG_ACCT_FMT = '_-* #,##0_-;\\-* #,##0_-;_-* "-"_-;_-@_-';
const LG_HEAD_FILL = 'DDEBF7';

function lg_f($sz = 9, $b = false) {
    return ['font' => [$sz, $b]];
}

/** 한 달 시트. 지금까지 쓰던 출납장 모양: 왼쪽 통장(전월이월·소계·잔액), 오른쪽 카드별 사용내역, 담당·승인란.
 *  $prev 는 앞 시트 [제목, 잔액 줄] — 있으면 전월이월을 그 시트의 잔액 칸과 수식으로 잇는다. */
function lg_build_daybook_sheet(LgXlsx $wb, $year, $month, $bank_id, $lead_id, $prev, $user_name) {
    $first = sprintf('%04d-%02d-01', $year, $month);
    $nxt = lg_ym_add(substr($first, 0, 7), 1) . '-01';
    $ws = $wb->addSheet("{$month}월");
    $box = ['border' => true];
    $head = ['fill' => LG_HEAD_FILL];
    $center = ['align' => ['h' => 'center', 'v' => 'center']];
    $bank = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=?", [$bank_id]);
    // 제목·결재란
    $ws->merge('A1:C2'); $ws->v('A1', sprintf('%d년 %02d월 일일출납', $year, $month)); $ws->s('A1', lg_f(24, true) + $center);
    $ws->v('D1', '담 당'); $ws->v('E1', '승 인'); $ws->v('D2', $user_name);
    foreach (['D1', 'E1', 'D2', 'E2'] as $c) $ws->s($c, lg_f($c[1] === '1' ? 11 : 13, true) + $box + $center);
    $ws->merge('G1:K2'); $ws->v('G1', "법인카드 이용내역({$month}월)"); $ws->s('G1', lg_f(17, true) + $center);
    $ws->heights[1] = 22; $ws->heights[2] = 22;
    $heads = ['A' => '일 자', 'B' => '적 요', 'C' => '입 금', 'D' => '출 금', 'E' => $bank ? mb_substr($bank['name'], 0, 10) : '잔액',
              'G' => '카드번호', 'H' => '사용일자', 'I' => '사용금액', 'J' => '가맹점명', 'K' => '사용내용'];
    foreach ($heads as $col => $h) {
        $ws->v("{$col}3", $h);
        $ws->s("{$col}3", lg_f(10, true) + $head + $box + ['align' => ['h' => 'center', 'v' => 'center', 'wrap' => true]]);
    }
    // 통장: 전월이월
    $before = (int)lg_val("SELECT COALESCE(SUM(amount_in),0)-COALESCE(SUM(amount_out),0) FROM bl_ledger_tx
                           WHERE account_id=? AND tx_date<?", [$bank_id, $first]);
    $pm = lg_add_days($first, -1);
    $ws->v('A4', '전월이월'); $ws->v('B4', (int)substr($pm, 0, 4) . '년 ' . (int)substr($pm, 5, 2) . '월 이월금액');
    $ws->v('E4', $prev ? ['f' => "'{$prev[0]}'!E{$prev[1]}"] : ((int)($bank['opening_balance'] ?? 0) + $before));
    foreach (str_split('ABCDE') as $col) $ws->s("{$col}4", lg_f() + $box);
    $ws->s('E4', ['fmt' => LG_ACCT_FMT]);
    $rows = lg_all("SELECT t.*, (SELECT COALESCE(SUM(p.amount_in),0) FROM bl_ledger_tx p WHERE p.split_of=t.id) AS p_in,
                           (SELECT COALESCE(SUM(p.amount_out),0) FROM bl_ledger_tx p WHERE p.split_of=t.id) AS p_out
                    FROM bl_ledger_tx t WHERE t.account_id=? AND t.split_of IS NULL AND t.tx_date>=? AND t.tx_date<?
                    ORDER BY t.tx_date, t.id", [$bank_id, $first, $nxt]);
    $r = 5;
    foreach ($rows as $t) {
        $ws->v("A$r", ['date' => $t['tx_date']]); $ws->s("A$r", ['fmt' => 'mm-dd']);
        $ws->v("B$r", lg_daybook_text($t['content'], $t['detail']));
        $a_in = (int)$t['amount_in'] + (int)$t['p_in'];
        $a_out = (int)$t['amount_out'] + (int)$t['p_out'];
        $ws->v("C$r", $a_in ?: null); $ws->v("D$r", $a_out ?: null);
        $ws->v("E$r", ['f' => "N(C$r)-N(D$r)"]);
        foreach (str_split('ABCDE') as $col) $ws->s("$col$r", lg_f() + $box);
        foreach (str_split('CDE') as $col) $ws->s("$col$r", ['fmt' => LG_ACCT_FMT]);
        $r++;
    }
    $last = max($r - 1, 4);
    $ws->merge("A$r:B$r"); $ws->v("A$r", '소 계');
    $ws->v("C$r", ['f' => "SUM(C5:C$last)"]); $ws->v("D$r", ['f' => "SUM(D5:D$last)"]); $ws->v("E$r", ['f' => "SUM(E5:E$last)"]);
    $r1 = $r + 1;
    $ws->merge("A$r1:B$r1"); $ws->v("A$r1", '잔 액'); $ws->v("E$r1", ['f' => "E4+E$r"]);
    foreach ([$r, $r1] as $rr) {
        foreach (str_split('ABCDE') as $col) $ws->s("$col$rr", lg_f(10, true) + $head + $box);
        $ws->s("A$rr", ['align' => ['h' => 'center']]);
        foreach (str_split('CDE') as $col) $ws->s("$col$rr", ['fmt' => LG_ACCT_FMT]);
    }
    $end_row = $r1;
    // 법인카드: 카드번호별 → 사용일 순
    $group = $lead_id ? lg_card_group_ids($lead_id) : [];
    $crow = 4;
    if ($group) {
        $ph = lg_ph($group);
        $cards = lg_all("SELECT t.*, a.name AS acc_name FROM bl_ledger_tx t JOIN bl_ledger_accounts a ON a.id=t.account_id
            WHERE t.account_id IN ($ph) AND t.tx_date>=? AND t.tx_date<?
              AND t.split_of IS NULL AND t.category<>? AND t.content NOT LIKE '" . LG_OVERSEAS_FEE . "%'
              AND t.source_file<>?", array_merge($group, [$first, $nxt, LG_CANCELLED, LG_ADJUST]));
        // 해외 결제는 수수료를 같은 줄에 더해서 (출납장에 청구 금액 그대로 적던 방식)
        $fees = lg_overseas_fees($group);
        $no = fn($t) => $t['card_no'] ?: lg_last4($t['acc_name']);
        usort($cards, fn($a, $b) => strcmp($no($a), $no($b)) ?: strcmp($a['tx_date'], $b['tx_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
        foreach ($cards as $t) {
            $ws->v("G$crow", $no($t)); $ws->s("G$crow", ['fmt' => '@']);
            $ws->v("H$crow", ['date' => $t['tx_date']]); $ws->s("H$crow", ['fmt' => 'mm-dd']);
            $ws->v("I$crow", (int)$t['amount_out'] - (int)$t['amount_in'] + ($fees[$t['dedup_key'] ?? ''] ?? 0));
            $ws->s("I$crow", ['fmt' => '#,##0']);
            $ws->v("J$crow", $t['content']); $ws->v("K$crow", $t['detail'] ?: null);
            foreach (str_split('GHIJK') as $col) $ws->s("$col$crow", lg_f(str_contains('HIJ', $col) ? 10 : 9, $col === 'G') + $box);
            $crow++;
        }
    }
    $ws->merge("G$crow:H$crow"); $ws->v("G$crow", "{$month}월 사용액");
    $ws->v("I$crow", $crow > 4 ? ['f' => 'SUM(I4:I' . ($crow - 1) . ')'] : 0); $ws->s("I$crow", ['fmt' => '#,##0']);
    $ws->v("J$crow", (int)substr($nxt, 5, 2) . '월 23일 결제 예정금액');
    foreach (str_split('GHIJK') as $col) $ws->s("$col$crow", lg_f(10, true) + $head + $box);
    foreach (['A' => 11, 'B' => 34, 'C' => 13, 'D' => 13, 'E' => 14, 'F' => 3, 'G' => 9, 'H' => 9, 'I' => 11, 'J' => 28, 'K' => 34] as $col => $w) {
        $ws->widths[lg_xlsx_ref($col . '1')[0]] = $w;
    }
    $ws->freeze = 'A4';
    $ws->orientation = 'landscape'; $ws->fitToPage = true; $ws->fitToWidth = 1; $ws->fitToHeight = 0;
    return [$ws->title, $end_row];
}

/** 여러 달 출납장. 돌려주는 값: [통합문서, 파일 이름] */
function lg_daybook_book(array $q, $user_name) {
    [$banks, $leads] = lg_daybook_groups();
    $today = lg_today();
    $year = (int)(lg_q($q, 'year') ?: substr($today, 0, 4));
    $m_from = max(1, min(12, (int)(lg_q($q, 'from') ?: substr($today, 5, 2))));
    $m_to = max(1, min(12, (int)(lg_q($q, 'to') ?: $m_from)));
    $bank_id = (int)(lg_q($q, 'bank_id') ?: ($banks[0]['id'] ?? 0));
    $lead_id = (int)(lg_q($q, 'lead_id') ?: ($leads[0]['id'] ?? 0));
    $wb = new LgXlsx();
    $prev = null;
    for ($m = min($m_from, $m_to); $m <= max($m_from, $m_to); $m++) {
        $prev = lg_build_daybook_sheet($wb, $year, $m, $bank_id, $lead_id, $prev, $user_name);
    }
    $name = $m_from === $m_to ? "{$year}_일일출납장_{$m_from}월.xlsx" : "{$year}_일일출납장_{$m_from}~{$m_to}월.xlsx";
    return [$wb, $name];
}

/* ================================================================ 자금일보 엑셀 */

function lg_cashbook_book(array $q, $user_name) {
    $day = lg_qdate($q, 'd', lg_today());
    [$banks, $cards, $txs] = lg_cash_report($day);
    $wb = new LgXlsx();
    $ws = $wb->addSheet(substr($day, 5, 2) . substr($day, 8, 2));
    $F = fn($sz = 10, $b = false) => ['font' => [$sz, $b]];
    $box = ['border' => true];
    $head = ['fill' => LG_HEAD_FILL];
    $acct = '#,##0;-#,##0;"-"';
    $ws->merge('A1:D2');
    $ws->v('A1', '자금일보  ' . (int)substr($day, 0, 4) . '년 ' . (int)substr($day, 5, 2) . '월 ' . (int)substr($day, 8, 2) . '일 (' . lg_weekday($day) . ')');
    $ws->s('A1', $F(18, true) + ['align' => ['h' => 'center', 'v' => 'center']]);
    $ws->v('E1', '담 당'); $ws->v('F1', '승 인'); $ws->v('E2', $user_name);
    foreach (['E1', 'F1', 'E2', 'F2'] as $c) $ws->s($c, $F(11, true) + $box + ['align' => ['h' => 'center', 'v' => 'center']]);
    $header = function ($r, $titles) use ($ws, $F, $head, $box) {
        foreach (array_combine(str_split('ABCDEF'), $titles) as $col => $h) {
            $ws->v("$col$r", $h);
            $ws->s("$col$r", $F(10, true) + $head + $box + ['align' => ['h' => 'center']]);
        }
    };
    $r = 4;
    $header($r, ['구분', '계좌', '전일잔액', '입금', '출금', '금일잔액']);
    $first = $r + 1;
    foreach ($banks as $b) {
        $r++;
        $ws->v("A$r", '예금'); $ws->v("B$r", $b['acc']['name']);
        $ws->v("C$r", $b['prev']); $ws->v("D$r", $b['in'] ?: null); $ws->v("E$r", $b['out'] ?: null);
        $ws->v("F$r", ['f' => "C$r+N(D$r)-N(E$r)"]);
        foreach (str_split('ABCDEF') as $col) $ws->s("$col$r", $F() + $box);
        foreach (str_split('CDEF') as $col) $ws->s("$col$r", ['fmt' => $acct]);
    }
    $r++;
    $ws->merge("A$r:B$r"); $ws->v("A$r", '합 계');
    foreach (str_split('CDEF') as $col) { $ws->v("$col$r", ['f' => "SUM($col$first:$col" . ($r - 1) . ')']); $ws->s("$col$r", ['fmt' => $acct]); }
    foreach (str_split('ABCDEF') as $col) $ws->s("$col$r", $F(10, true) + $head + $box);
    $ws->s("A$r", ['align' => ['h' => 'center']]);
    if ($cards) {
        $r += 2;
        $header($r, ['구분', '카드', '금일 사용', '월 누계', '다음 결제일', '결제 예정액']);
        foreach ($cards as $cd) {
            $r++;
            $ws->v("A$r", '법인카드'); $ws->v("B$r", $cd['lead']['name'] . ($cd['n'] > 1 ? ' 외 ' . ($cd['n'] - 1) . '장' : ''));
            $ws->v("C$r", $cd['used'] ?: null); $ws->v("D$r", $cd['mon'] ?: null);
            $ws->v("E$r", $cd['next_due'] ?: null); $ws->v("F$r", $cd['next_amt'] ?: null);
            foreach (str_split('ABCDEF') as $col) $ws->s("$col$r", $F() + $box);
            foreach (str_split('CDF') as $col) $ws->s("$col$r", ['fmt' => $acct]);
        }
    }
    $r += 2;
    $header($r, ['통장·카드', '적요', '내용', '입금', '출금·사용', '계정과목']);
    foreach ($txs as $t) {
        $r++;
        $ws->v("A$r", $t['acc_name']); $ws->v("B$r", $t['content']); $ws->v("C$r", $t['detail'] ?: null);
        $ws->v("D$r", (int)$t['amount_in'] ?: null); $ws->v("E$r", (int)$t['amount_out'] ?: null); $ws->v("F$r", $t['category'] ?: '미분류');
        foreach (str_split('ABCDEF') as $col) $ws->s("$col$r", $F(9) + $box);
        foreach (str_split('DE') as $col) $ws->s("$col$r", ['fmt' => $acct]);
    }
    foreach (['A' => 20, 'B' => 26, 'C' => 30, 'D' => 15, 'E' => 15, 'F' => 16] as $col => $w) $ws->widths[lg_xlsx_ref($col . '1')[0]] = $w;
    $ws->orientation = 'portrait'; $ws->fitToPage = true; $ws->fitToWidth = 1; $ws->fitToHeight = 0;
    return [$wb, "자금일보_{$day}.xlsx"];
}

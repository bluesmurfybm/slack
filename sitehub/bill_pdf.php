<?php
/**
 * 청구서 PDF — 채운 .xlsx 의 첫 시트를 같은 모양으로 그린다. bill_lib.php 의 sitehub_bill_build 가 xlsx 를 만든 뒤 부른다.
 *
 *  - 열 너비·행 높이·합친 칸·테두리(hair·thin·medium·thick·double·점선)·칸 배경(테마 색·tint 포함)·가로/세로 정렬·
 *    줄바꿈·셀에 맞춤(shrinkToFit)·글자 크기/굵기/밑줄/색·서식 조각(rich text)·숫자 서식·그림(도장 등)을 따른다.
 *  - 숫자 서식은 쓰는 것만: #,##0 류, 따옴표·\ 글자, _x 빈칸, [Red]·[$-412] 무시, [DBNum4](한글 숫자 — 일금 …원정), 날짜 몇 가지.
 *    한글 글꼴에서 \ 는 ₩ 로 보이므로(엑셀 화면과 같게) ₩ 로 그린다.
 *  - 글꼴은 report_pdf.php 의 ShPdf(Pretendard 서브셋). 인쇄 영역은 시트에 쓴 칸 전체, 용지·여백·가운데 맞춤은 시트 인쇄 설정.
 *    한 쪽에 안 들어가면 비율대로 줄인다(청구서는 한 장).
 */

require_once __DIR__ . '/report_pdf.php';
require_once __DIR__ . '/bill_lib.php';

const SH_XLPDF_COL_PT = 5.6;   // 열 너비 1(글자) = 5.6pt — 기본 글꼴 글자 폭 7.5px × 0.75
const SH_XL_DRAW_NS = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
const SH_XL_A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

/** 엑셀 숫자 → 일반 서식 글 */
function sh_xl_general(float $v): string {
    if (floor($v) == $v && abs($v) < 1e15) return sprintf('%.0f', $v);
    return rtrim(rtrim(sprintf('%.10G', $v), '0'), '.');
}

/** 서식 코드를 ; 로 나눈다 (따옴표·대괄호·\ 안은 빼고) */
function sh_xl_sections(string $code): array {
    $out = []; $cur = ''; $q = false; $br = false;
    $n = strlen($code);
    for ($i = 0; $i < $n; $i++) {
        $ch = $code[$i];
        if ($ch === '\\' && !$q && $i + 1 < $n) { $cur .= $ch . $code[++$i]; continue; }
        if ($ch === '"') $q = !$q;
        elseif (!$q && $ch === '[') $br = true;
        elseif (!$q && $ch === ']') $br = false;
        if ($ch === ';' && !$q && !$br) { $out[] = $cur; $cur = ''; continue; }
        $cur .= $ch;
    }
    $out[] = $cur;
    return $out;
}

/** 숫자 자리 표시(#,##0.00 등)로 숫자 쓰기 */
function sh_xl_digits(float $v, string $pat): string {
    $pct = substr_count($pat, '%');
    if ($pct) $v *= 100 ** $pct;
    $pat = rtrim(str_replace('%', '', $pat), ',');   // 끝의 쉼표는 천 단위 나누기 — 쓰지 않는 서식이라 무시
    $dot = strpos($pat, '.');
    $int = $dot === false ? $pat : substr($pat, 0, $dot);
    $dec = $dot === false ? 0 : strlen(preg_replace('/[^0#?]/', '', substr($pat, $dot + 1)));
    $minDec = $dot === false ? 0 : strlen(preg_replace('/[^0]/', '', substr($pat, $dot + 1)));
    $minInt = strlen(preg_replace('/[^0]/', '', $int));
    $comma = str_contains($int, ',');
    $s = number_format(round($v, $dec), $dec, '.', $comma ? ',' : '');
    if ($dec > $minDec && str_contains($s, '.')) {   // # 자리의 끝 0 은 지운다
        $s = rtrim($s, '0');
        $keep = strlen(substr($s, strpos($s, '.') + 1));
        if ($keep < $minDec) $s .= str_repeat('0', $minDec - $keep);
        $s = rtrim($s, '.');
    }
    [$ip] = explode('.', $s);
    if ($ip === '0' && $minInt === 0) $s = substr($s, 1);
    elseif (strlen(str_replace(',', '', $ip)) < $minInt) $s = str_pad(str_replace(',', '', $ip), $minInt, '0', STR_PAD_LEFT) . substr($s, strlen($ip));
    return $s . str_repeat('%', $pct);
}

/** 서식 코드 한 칸을 숫자에 입힌다 */
function sh_xl_apply_section(float $v, string $sec, bool $neg): string {
    $db = 0;
    if (preg_match_all('/\[([^\]]*)\]/', $sec, $mm)) foreach ($mm[1] as $tag) if (preg_match('/^DBNum(\d)$/i', $tag, $g)) $db = (int)$g[1];
    $out = ''; $num = null; $n = strlen($sec);
    $put = function (string $kind) use (&$out, &$num, $v, $db) {
        if ($db) $s = sitehub_won_hangul((int)round($v));
        elseif ($kind === 'General') $s = sh_xl_general($v);
        else $s = sh_xl_digits($v, $kind);
        $out .= $s; $num = true;
    };
    for ($i = 0; $i < $n; $i++) {
        $ch = $sec[$i];
        if ($ch === '"') { $j = strpos($sec, '"', $i + 1); if ($j === false) $j = $n; $out .= substr($sec, $i + 1, $j - $i - 1); $i = $j; continue; }
        if ($ch === '\\') { if ($i + 1 < $n) { $len = strlen(mb_substr(substr($sec, $i + 1, 4), 0, 1)); $out .= substr($sec, $i + 1, $len); $i += $len; } continue; }
        if ($ch === '_') { $i++; $out .= ' '; continue; }
        if ($ch === '*') { $i++; continue; }
        if ($ch === '[') { $j = strpos($sec, ']', $i); $i = $j === false ? $n : $j; continue; }
        if (strncasecmp(substr($sec, $i, 7), 'General', 7) === 0) { $put('General'); $i += 6; continue; }
        if (strpbrk($ch, '0#?') !== false || ($ch === '.' && $i + 1 < $n && strpbrk($sec[$i + 1], '0#?') !== false)) {
            $j = $i;
            while ($j < $n && strpbrk($sec[$j], '0#?,.%') !== false) $j++;
            $put(substr($sec, $i, $j - $i));
            $i = $j - 1;
            continue;
        }
        $out .= $ch;
    }
    if ($num === null) return $out;
    return ($neg ? '-' : '') . $out;
}

const SH_XL_BUILTIN_FMT = [0 => 'General', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 9 => '0%', 10 => '0.00%', 37 => '#,##0 ;(#,##0)', 38 => '#,##0 ;[Red](#,##0)',
    41 => '_-* #,##0_-;\-* #,##0_-;_-* "-"_-;_-@_-', 42 => '_-"₩"* #,##0_-;\-"₩"* #,##0_-;_-"₩"* "-"_-;_-@_-', 49 => '@'];

/** 칸 값 → 보이는 글 */
function sh_xl_display(string $raw, bool $isNum, int $fmtId, string $code): string {
    if (!$isNum) return $raw;
    $v = (float)$raw;
    if (($fmtId >= 14 && $fmtId <= 22) || ($code !== '' && preg_match('/(^|[^"\\\\])(yy|dd|mmm)/i', preg_replace('/"[^"]*"/', '', $code)))) {
        $t = (int)round(($v - 25569) * 86400);
        return $fmtId === 14 || $code === '' ? gmdate('Y-m-d', $t) : gmdate('Y-m-d', $t);
    }
    if ($code === '' || strcasecmp($code, 'General') === 0) return sh_xl_general($v);
    $secs = sh_xl_sections($code);
    if ($v < 0 && isset($secs[1])) return sh_xl_apply_section(-$v, $secs[1], false);
    if ($v == 0 && isset($secs[2])) return sh_xl_apply_section(0, $secs[2], false);
    return sh_xl_apply_section(abs($v), $secs[0], $v < 0);
}

final class ShXlPdf {
    private ShXlBook $b;
    private array $fmts = SH_XL_BUILTIN_FMT;
    private array $fonts = [];     // [size, bold, italic, underline, rgb]
    private array $fills = [];     // rgb|null
    private array $borders = [];   // [left|right|top|bottom => [style, rgb]]
    private array $xfs = [];       // [fmt, font, fill, border, h, v, wrap, shrink, indent]
    private array $theme = [];     // 테마 색 번호 => 'RRGGBB'
    private array $colX = [];      // 열 번호 => 왼쪽 x(pt, 줄이기 전)
    private array $rowY = [];      // 행 번호 => 위 y
    private int $maxR = 1, $maxC = 1;
    private float $s = 1, $x0 = 0, $y0 = 0;
    private array $mergeAt = [];   // "r:c" => 합친 칸 (왼쪽 위 칸만)
    private array $covered = [];   // 합친 칸에 덮인 칸(왼쪽 위 제외)

    public function __construct(ShXlBook $b) { $this->b = $b; }

    /* ── 읽기 ── */

    private function doc(string $path): ?DOMDocument {
        $x = $this->b->zip->getFromName($path);
        if ($x === false) return null;
        $d = new DOMDocument();
        return @$d->loadXML($x, LIBXML_NONET | LIBXML_PARSEHUGE) ? $d : null;
    }

    private static function kid(DOMElement $el, string $local): ?DOMElement {
        foreach ($el->childNodes as $n) if ($n instanceof DOMElement && $n->localName === $local) return $n;
        return null;
    }

    private static function kids(DOMElement $el, string $local): array {
        $o = [];
        foreach ($el->childNodes as $n) if ($n instanceof DOMElement && $n->localName === $local) $o[] = $n;
        return $o;
    }

    private function loadTheme(): void {
        $rels = sh_xl_rels($this->b->zip, 'xl/workbook.xml');
        foreach ($rels as $r) {
            if ($r['type'] !== 'theme') continue;
            $d = $this->doc($r['path']);
            if (!$d) return;
            $order = ['lt1' => 0, 'dk1' => 1, 'lt2' => 2, 'dk2' => 3, 'accent1' => 4, 'accent2' => 5, 'accent3' => 6, 'accent4' => 7, 'accent5' => 8, 'accent6' => 9, 'hlink' => 10, 'folHlink' => 11];
            $scheme = $d->getElementsByTagNameNS(SH_XL_A_NS, 'clrScheme')->item(0);
            if (!$scheme) return;
            foreach ($scheme->childNodes as $n) {
                if (!$n instanceof DOMElement || !isset($order[$n->localName])) continue;
                foreach ($n->childNodes as $c) {
                    if (!$c instanceof DOMElement) continue;
                    $hex = $c->getAttribute('lastClr') ?: $c->getAttribute('val');
                    if (preg_match('/^[0-9A-F]{6}$/i', $hex)) $this->theme[$order[$n->localName]] = $hex;
                }
            }
            return;
        }
    }

    /** 색 요소 → [r, g, b] (0~1). 없으면 null */
    private function color(?DOMElement $c, ?array $auto = [0, 0, 0]): ?array {
        if (!$c) return null;
        $hex = null;
        if ($c->hasAttribute('rgb')) $hex = substr($c->getAttribute('rgb'), -6);
        elseif ($c->hasAttribute('theme')) $hex = $this->theme[(int)$c->getAttribute('theme')] ?? null;
        elseif ($c->hasAttribute('indexed')) {
            $pal = ['000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF', '000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF',
                    '800000', '008000', '000080', '808000', '800080', '008080', 'C0C0C0', '808080'];
            $hex = $pal[(int)$c->getAttribute('indexed')] ?? null;
        }
        if (!$hex || !preg_match('/^[0-9A-F]{6}$/i', $hex)) return $auto;
        $rgb = [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
        $tint = (float)$c->getAttribute('tint');
        return $tint ? self::tint($rgb, $tint) : $rgb;
    }

    /** 엑셀 tint — HLS 의 밝기만 옮긴다 */
    private static function tint(array $rgb, float $t): array {
        [$r, $g, $b] = $rgb;
        $mx = max($r, $g, $b); $mn = min($r, $g, $b);
        $l = ($mx + $mn) / 2; $h = 0; $s = 0;
        if ($mx != $mn) {
            $d = $mx - $mn;
            $s = $l > 0.5 ? $d / (2 - $mx - $mn) : $d / ($mx + $mn);
            $h = $mx == $r ? ($g - $b) / $d + ($g < $b ? 6 : 0) : ($mx == $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
            $h /= 6;
        }
        $l = $t < 0 ? $l * (1 + $t) : $l * (1 - $t) + $t;
        if ($s == 0) return [$l, $l, $l];
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s; $p = 2 * $l - $q;
        $f = function ($x) use ($p, $q) {
            if ($x < 0) $x += 1; if ($x > 1) $x -= 1;
            if ($x < 1 / 6) return $p + ($q - $p) * 6 * $x;
            if ($x < 1 / 2) return $q;
            if ($x < 2 / 3) return $p + ($q - $p) * (2 / 3 - $x) * 6;
            return $p;
        };
        return [$f($h + 1 / 3), $f($h), $f($h - 1 / 3)];
    }

    private function fontOf(DOMElement $f, array $base = [11, false, false, false, [0, 0, 0]]): array {
        $on = function (string $k) use ($f): ?bool {
            $e = self::kid($f, $k);
            if (!$e) return null;
            $v = $e->getAttribute('val');
            return !in_array($v, ['0', 'false', 'none'], true);
        };
        $sz = self::kid($f, 'sz');
        return [$sz ? (float)$sz->getAttribute('val') : $base[0], $on('b') ?? $base[1], $on('i') ?? $base[2], $on('u') ?? $base[3],
                $this->color(self::kid($f, 'color'), null) ?? $base[4]];
    }

    private function loadStyles(): void {
        $path = 'xl/styles.xml';
        foreach (sh_xl_rels($this->b->zip, 'xl/workbook.xml') as $r) if ($r['type'] === 'styles') $path = $r['path'];
        $d = $this->doc($path);
        if (!$d) return;
        foreach ($d->getElementsByTagNameNS(SH_XL_NS, 'numFmt') as $n) $this->fmts[(int)$n->getAttribute('numFmtId')] = $n->getAttribute('formatCode');
        $root = $d->documentElement;
        if ($fs = self::kid($root, 'fonts')) foreach (self::kids($fs, 'font') as $f) $this->fonts[] = $this->fontOf($f);
        if ($fl = self::kid($root, 'fills')) foreach (self::kids($fl, 'fill') as $f) {
            $p = self::kid($f, 'patternFill');
            $this->fills[] = $p && $p->getAttribute('patternType') === 'solid' ? $this->color(self::kid($p, 'fgColor'), [1, 1, 1]) : null;
        }
        if ($bs = self::kid($root, 'borders')) foreach (self::kids($bs, 'border') as $b) {
            $one = [];
            foreach (['left', 'right', 'top', 'bottom'] as $side) {
                $e = self::kid($b, $side);
                $st = $e ? $e->getAttribute('style') : '';
                if ($st !== '' && $st !== 'none') $one[$side] = [$st, $this->color(self::kid($e, 'color')) ?? [0, 0, 0]];
            }
            $this->borders[] = $one;
        }
        if ($cx = self::kid($root, 'cellXfs')) foreach (self::kids($cx, 'xf') as $x) {
            $al = self::kid($x, 'alignment');
            $this->xfs[] = [(int)$x->getAttribute('numFmtId'), (int)$x->getAttribute('fontId'), (int)$x->getAttribute('fillId'), (int)$x->getAttribute('borderId'),
                            $al ? $al->getAttribute('horizontal') : '', $al ? ($al->getAttribute('vertical') ?: 'bottom') : 'bottom',
                            $al && $al->getAttribute('wrapText') === '1', $al && $al->getAttribute('shrinkToFit') === '1', $al ? (int)$al->getAttribute('indent') : 0];
        }
    }

    private function xf(int $r, int $c): array {
        $el = $this->b->cell($r, $c);
        $i = $el && $el->hasAttribute('s') ? (int)$el->getAttribute('s') : 0;
        return $this->xfs[$i] ?? [0, 0, 0, 0, '', 'bottom', false, false, 0];
    }

    /* ── 배치 ── */

    private function layout(): array {
        $sheet = $this->b->sheet->documentElement;
        $fmt = $this->b->sheet->getElementsByTagNameNS(SH_XL_NS, 'sheetFormatPr')->item(0);
        $defW = $fmt && $fmt->getAttribute('defaultColWidth') !== '' ? (float)$fmt->getAttribute('defaultColWidth') : (($fmt && $fmt->getAttribute('baseColWidth') !== '' ? (float)$fmt->getAttribute('baseColWidth') : 8) + 0.71);
        $defH = $fmt && $fmt->getAttribute('defaultRowHeight') !== '' ? (float)$fmt->getAttribute('defaultRowHeight') : 15;
        // 인쇄 범위 = 쓴 칸 전체 (dimension 이 있으면 그것)
        $dim = $this->b->sheet->getElementsByTagNameNS(SH_XL_NS, 'dimension')->item(0);
        $p = $dim ? explode(':', $dim->getAttribute('ref')) : [];
        $z = isset($p[1]) ? sh_xl_ref($p[1]) : null;
        foreach ($this->b->cells as $k => $el) { [$r, $c] = array_map('intval', explode(':', $k)); if ($this->b->text($r, $c) !== '') { $this->maxR = max($this->maxR, $r); $this->maxC = max($this->maxC, $c); } }
        foreach ($this->b->merges as $m) { $this->maxR = max($this->maxR, $m[2]); $this->maxC = max($this->maxC, $m[3]); }
        if ($z) { $this->maxR = max($this->maxR, $z[0]); $this->maxC = max($this->maxC, $z[1]); }
        $w = array_fill(1, $this->maxC, $defW);
        $hidden = [];
        foreach ($this->b->sheet->getElementsByTagNameNS(SH_XL_NS, 'col') as $col) {
            for ($c = (int)$col->getAttribute('min'); $c <= min($this->maxC, (int)$col->getAttribute('max')); $c++) {
                $w[$c] = $col->getAttribute('hidden') === '1' ? 0 : (float)$col->getAttribute('width');
            }
        }
        $x = 0;
        for ($c = 1; $c <= $this->maxC + 1; $c++) { $this->colX[$c] = $x; $x += ($w[$c] ?? $defW) * SH_XLPDF_COL_PT; }
        $W = $this->colX[$this->maxC + 1];
        $y = 0;
        for ($r = 1; $r <= $this->maxR + 1; $r++) {
            $this->rowY[$r] = $y;
            $row = $this->b->rowEls[$r] ?? null;
            $y += $row && $row->getAttribute('hidden') === '1' ? 0 : ($row && $row->getAttribute('ht') !== '' ? (float)$row->getAttribute('ht') : $defH);
        }
        $H = $this->rowY[$this->maxR + 1];
        foreach ($this->b->merges as $m) {
            $this->mergeAt["{$m[0]}:{$m[1]}"] = $m;
            for ($r = $m[0]; $r <= $m[2]; $r++) for ($c = $m[1]; $c <= $m[3]; $c++) if ($r !== $m[0] || $c !== $m[1]) $this->covered["{$r}:{$c}"] = $m;
        }
        // 용지·여백
        $ps = $this->b->sheet->getElementsByTagNameNS(SH_XL_NS, 'pageSetup')->item(0);
        $paper = $ps ? (int)$ps->getAttribute('paperSize') : 9;
        [$pw, $ph] = [1 => [612, 792], 5 => [612, 1008], 8 => [841.89, 1190.55], 9 => [595.28, 841.89], 11 => [419.53, 595.28]][$paper] ?? [595.28, 841.89];
        if ($ps && $ps->getAttribute('orientation') === 'landscape') [$pw, $ph] = [$ph, $pw];
        $pm = $this->b->sheet->getElementsByTagNameNS(SH_XL_NS, 'pageMargins')->item(0);
        $mg = fn($k, $d) => ($pm && $pm->getAttribute($k) !== '' ? (float)$pm->getAttribute($k) : $d) * 72;
        [$ml, $mr, $mt, $mb] = [$mg('left', 0.7), $mg('right', 0.7), $mg('top', 0.75), $mg('bottom', 0.75)];
        $po = $this->b->sheet->getElementsByTagNameNS(SH_XL_NS, 'printOptions')->item(0);
        $this->s = min(1, ($pw - $ml - $mr) / max(1, $W), ($ph - $mt - $mb) / max(1, $H));
        $this->x0 = $po && $po->getAttribute('horizontalCentered') === '1' ? ($pw - $W * $this->s) / 2 : $ml;
        $this->y0 = $po && $po->getAttribute('verticalCentered') === '1' ? ($ph - $H * $this->s) / 2 : $mt;
        return [$pw, $ph];
    }

    private function X(float $x): float { return $this->x0 + $x * $this->s; }
    private function Y(float $y): float { return $this->y0 + $y * $this->s; }

    /** 칸(합친 칸이면 전체)의 상자 [x, y, w, h] — 줄이기 전 단위 */
    private function box(int $r, int $c): array {
        $m = $this->mergeAt["{$r}:{$c}"] ?? [$r, $c, $r, $c];
        $x = $this->colX[$m[1]]; $y = $this->rowY[$m[0]];
        return [$x, $y, $this->colX[min($m[3], $this->maxC) + 1] - $x, $this->rowY[min($m[2], $this->maxR) + 1] - $y];
    }

    /* ── 그리기 ── */

    public function draw(ShPdf $pdf): void {
        $this->loadTheme();
        $this->loadStyles();
        [$pw, $ph] = $this->layout();
        $pdf->addPage($pw, $ph);
        // 1) 칸 배경
        for ($r = 1; $r <= $this->maxR; $r++) for ($c = 1; $c <= $this->maxC; $c++) {
            if (isset($this->covered["{$r}:{$c}"])) continue;
            $fill = $this->fills[$this->xf($r, $c)[2]] ?? null;
            if (!$fill) continue;
            [$x, $y, $w, $h] = $this->box($r, $c);
            if ($w > 0 && $h > 0) $pdf->rect($this->X($x), $this->Y($y), $w * $this->s, $h * $this->s, $fill);
        }
        // 2) 테두리 — 칸마다 자기 테두리, 합친 칸은 바깥 변만. 맞닿은 두 칸이 다르면 굵은 쪽(같으면 나중 칸)
        $rank = ['hair' => 1, 'dotted' => 2, 'dashDotDot' => 3, 'dashDot' => 4, 'dashed' => 5, 'thin' => 6, 'mediumDashDotDot' => 7, 'slantDashDot' => 7, 'mediumDashDot' => 8,
                 'mediumDashed' => 9, 'medium' => 10, 'thick' => 11, 'double' => 12];
        $edges = [];   // "h|v:r:c" => [style, rgb]
        $put = function (string $k, array $b) use (&$edges, $rank) {
            if (!isset($edges[$k]) || ($rank[$b[0]] ?? 6) >= ($rank[$edges[$k][0]] ?? 6)) $edges[$k] = $b;
        };
        for ($r = 1; $r <= $this->maxR; $r++) for ($c = 1; $c <= $this->maxC; $c++) {
            $bd = $this->borders[$this->xf($r, $c)[3]] ?? [];
            if (!$bd) continue;
            $m = $this->covered["{$r}:{$c}"] ?? ($this->mergeAt["{$r}:{$c}"] ?? null);
            if (isset($bd['left']) && (!$m || $c === $m[1])) $put("v:{$r}:{$c}", $bd['left']);
            if (isset($bd['right']) && (!$m || $c === $m[3])) $put('v:' . $r . ':' . ($c + 1), $bd['right']);
            if (isset($bd['top']) && (!$m || $r === $m[0])) $put("h:{$r}:{$c}", $bd['top']);
            if (isset($bd['bottom']) && (!$m || $r === $m[2])) $put('h:' . ($r + 1) . ':' . $c, $bd['bottom']);
        }
        foreach ($edges as $k => [$st, $rgb]) {
            if ($rgb[0] > 0.99 && $rgb[1] > 0.99 && $rgb[2] > 0.99) continue;   // 흰 선은 옆 칸 선을 지우는 용도 — 바탕이 흰색이라 안 그린다
            [$o, $r, $c] = explode(':', $k);
            $r = (int)$r; $c = (int)$c;
            if ($r > $this->maxR + 1 || $c > $this->maxC + 1) continue;
            $lw = ['hair' => 0.3, 'thin' => 0.6, 'medium' => 1.2, 'mediumDashed' => 1.2, 'mediumDashDot' => 1.2, 'mediumDashDotDot' => 1.2, 'slantDashDot' => 1.2, 'thick' => 1.8, 'double' => 0.5][$st] ?? 0.6;
            $dash = in_array($st, ['dotted', 'dashed', 'dashDot', 'dashDotDot', 'mediumDashed', 'mediumDashDot', 'mediumDashDotDot', 'slantDashDot'], true);
            if ($o === 'h') { $x1 = $this->X($this->colX[$c]); $x2 = $this->X($this->colX[$c + 1]); $y1 = $y2 = $this->Y($this->rowY[$r]); }
            else { $y1 = $this->Y($this->rowY[$r]); $y2 = $this->Y($this->rowY[$r + 1]); $x1 = $x2 = $this->X($this->colX[$c]); }
            if ($st === 'double') {
                $d = 0.9;
                if ($o === 'h') { $pdf->line($x1, $y1 - $d, $x2, $y2 - $d, $lw, $rgb); $pdf->line($x1, $y1 + $d, $x2, $y2 + $d, $lw, $rgb); }
                else { $pdf->line($x1 - $d, $y1, $x2 - $d, $y2, $lw, $rgb); $pdf->line($x1 + $d, $y1, $x2 + $d, $y2, $lw, $rgb); }
            } else {
                $pdf->line($x1, $y1, $x2, $y2, $lw * max(0.6, $this->s), $rgb, $dash);
            }
        }
        // 3) 글
        for ($r = 1; $r <= $this->maxR; $r++) for ($c = 1; $c <= $this->maxC; $c++) {
            if (isset($this->covered["{$r}:{$c}"])) continue;
            $raw = $this->b->text($r, $c);
            if ($raw === '') continue;
            $this->cellText($pdf, $r, $c, $raw);
        }
        // 4) 그림
        $this->images($pdf);
        $pdf->done();
    }

    private function cellText(ShPdf $pdf, int $r, int $c, string $raw): void {
        [$fmtId, $fontId, , , $h, $v, $wrap, $shrink, $indent] = $this->xf($r, $c);
        $isNum = $this->b->isNumber($r, $c);
        $font = $this->fonts[$fontId] ?? [11, false, false, false, [0, 0, 0]];
        $runs = !$isNum ? $this->b->runs($r, $c) : null;
        $pieces = [];   // [글, 크기, 굵게, 밑줄, 색]
        if ($runs) {
            foreach ($runs as [$t, $rPr]) {
                $f = $rPr ? $this->fontOf($rPr, $font) : $font;
                $pieces[] = [strtr($t, ['\\' => '₩']), $f[0], $f[1], $f[3], $f[4]];
            }
        } else {
            $txt = sh_xl_display($raw, $isNum, $fmtId, (string)($this->fmts[$fmtId] ?? ''));
            if ($txt === '') return;
            $pieces[] = [strtr($txt, ['\\' => '₩']), $font[0], $font[1], $font[3], $font[4]];
        }
        if ($h === '' || $h === 'general') $h = $isNum ? 'right' : 'left';
        if ($h === 'centerContinuous' || $h === 'distributed' || $h === 'justify') $h = 'center';
        if ($h === 'fill') $h = 'left';
        [$bx, $by, $bw, $bh] = $this->box($r, $c);
        $pad = 2.0;
        $avail = max(1, $bw - 2 * $pad - $indent * 9);
        $s = $this->s;
        // 줄 나누기: 줄바꿈 문자 + (wrapText 면) 칸 너비
        $lines = [[]];
        foreach ($pieces as $pc) {
            $parts = explode("\n", str_replace("\r", '', $pc[0]));
            foreach ($parts as $i => $part) {
                if ($i > 0) $lines[] = [];
                if ($part !== '') $lines[count($lines) - 1][] = [$part, $pc[1], $pc[2], $pc[3], $pc[4]];
            }
        }
        if ($wrap) {
            $out = [];
            foreach ($lines as $l) {
                $cur = []; $w = 0;
                foreach ($l as $p) {
                    foreach (preg_split('/(?<=\s)/u', $p[0], -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        $ww = $pdf->width($word, $p[1], $p[2]);
                        if ($w + $ww > $avail && $cur) { $out[] = $cur; $cur = []; $w = 0; $word = ltrim($word); $ww = $pdf->width($word, $p[1], $p[2]); }
                        $cur[] = [$word, $p[1], $p[2], $p[3], $p[4]]; $w += $ww;
                    }
                }
                $out[] = $cur;
            }
            $lines = $out;
        }
        $lw = fn(array $l, float $k) => array_sum(array_map(fn($p) => $pdf->width($p[0], $p[1] * $k, $p[2]), $l));
        $k = 1.0;   // 셀에 맞춤 — 가장 긴 줄이 칸에 들어가게 글자를 줄인다
        if ($shrink) { $mx = max(array_map(fn($l) => $lw($l, 1), $lines)); if ($mx > $avail) $k = $avail / $mx; }
        $size = max(array_map(fn($l) => $l ? max(array_column($l, 1)) : 0, $lines)) * $k;
        $lineH = $size * 1.25;
        $blockH = $lineH * count($lines);
        if ($v === 'top') $top = $by + $pad;
        elseif ($v === 'center' || $v === 'distributed' || $v === 'justify') $top = $by + ($bh - $blockH) / 2;
        else $top = $by + $bh - $pad * 0.5 - $blockH;
        foreach ($lines as $i => $l) {
            if (!$l) continue;
            $w = $lw($l, $k);
            $x = $h === 'center' ? $bx + ($bw - $w) / 2 : ($h === 'right' ? $bx + $bw - $pad - $w - $indent * 9 : $bx + $pad + $indent * 9);
            $base = $top + $i * $lineH + $lineH / 2 + $size * 0.36;
            foreach ($l as [$t, $sz, $bold, $ul, $rgb]) {
                $sz *= $k;
                $tw = $pdf->width($t, $sz, $bold);
                $pdf->text($this->X($x), $this->Y($base), $t, $sz * $s, $bold, $rgb);
                if ($ul && trim($t) !== '') $pdf->line($this->X($x), $this->Y($base + $sz * 0.14), $this->X($x + $tw), $this->Y($base + $sz * 0.14), max(0.4, $sz * 0.06) * $s, $rgb);
                $x += $tw;
            }
        }
    }

    private function images(ShPdf $pdf): void {
        $rels = sh_xl_rels($this->b->zip, $this->b->sheetPath);
        foreach ($rels as $rel) {
            if ($rel['type'] !== 'drawing') continue;
            $d = $this->doc($rel['path']);
            if (!$d) continue;
            $drels = sh_xl_rels($this->b->zip, $rel['path']);
            foreach ($d->documentElement->childNodes as $an) {
                if (!$an instanceof DOMElement || !in_array($an->localName, ['twoCellAnchor', 'oneCellAnchor', 'absoluteAnchor'], true)) continue;
                $pic = $an->getElementsByTagNameNS(SH_XL_DRAW_NS, 'pic')->item(0);
                $blip = $pic ? $pic->getElementsByTagNameNS(SH_XL_A_NS, 'blip')->item(0) : null;
                if (!$blip) continue;
                $img = $drels[$blip->getAttributeNS(SH_XL_REL, 'embed')]['path'] ?? null;
                $bin = $img ? $this->b->zip->getFromName($img) : false;
                if ($bin === false) continue;
                $pos = function (?DOMElement $e): ?array {
                    if (!$e) return null;
                    $v = [];
                    foreach (['col', 'colOff', 'row', 'rowOff'] as $k) { $x = $e->getElementsByTagNameNS(SH_XL_DRAW_NS, $k)->item(0); $v[$k] = $x ? (int)$x->textContent : 0; }
                    return [($this->colX[$v['col'] + 1] ?? end($this->colX)) + $v['colOff'] / 12700, ($this->rowY[$v['row'] + 1] ?? end($this->rowY)) + $v['rowOff'] / 12700];
                };
                $from = $pos($an->getElementsByTagNameNS(SH_XL_DRAW_NS, 'from')->item(0));
                $ext = $pic->getElementsByTagNameNS(SH_XL_A_NS, 'ext')->item(0);
                $off = $pic->getElementsByTagNameNS(SH_XL_A_NS, 'off')->item(0);
                if (!$from && $an->localName === 'absoluteAnchor' && $off) $from = [(int)$off->getAttribute('x') / 12700, (int)$off->getAttribute('y') / 12700];
                if (!$from) continue;
                if ($ext && (int)$ext->getAttribute('cx') > 0) { $w = (int)$ext->getAttribute('cx') / 12700; $h = (int)$ext->getAttribute('cy') / 12700; }
                else { $to = $pos($an->getElementsByTagNameNS(SH_XL_DRAW_NS, 'to')->item(0)); if (!$to) continue; $w = $to[0] - $from[0]; $h = $to[1] - $from[1]; }
                $pdf->image($img, $bin, $this->X($from[0]), $this->Y($from[1]), $w * $this->s, $h * $this->s);
            }
        }
    }
}

/** 채운 xlsx → PDF 파일 */
function sitehub_bill_pdf(string $xlsx, string $outPdf, string $title = ''): void {
    if (!function_exists('mb_convert_encoding')) throw new Exception('PHP mbstring 확장이 없어 PDF 를 만들 수 없습니다.');
    $b = ShXlBook::open($xlsx);
    $pdf = new ShPdf();
    (new ShXlPdf($b))->draw($pdf);
    $b->zip->close();
    if (@file_put_contents($outPdf, $pdf->output($title)) === false) throw new Exception('PDF 파일을 저장할 수 없습니다.');
}

<?php
/**
 * BlueLedger 회계 장부 — 출력 미리보기 (팝업 창): 엑셀과 같은 내용을 화면 표로 보여주고 인쇄·저장.
 *
 *  엑셀을 만드는 함수(lg_daybook_book 등)가 만든 통합문서를 그대로 받아 그린다.
 *  그래서 미리보기 숫자와 저장되는 엑셀 숫자가 다를 수 없다.
 *  엑셀 파일은 수식만 넣고 계산은 엑셀이 열 때 하므로, 여기서는 쓰이는 수식
 *  (칸 참조·다른 시트 칸·+ - * /·SUM·SUBTOTAL(9)·N)을 직접 계산해서 보여준다.
 */

require_once __DIR__ . '/ledger_xlsx.php';

/* ================================================================ 수식 계산 */

class LgCalc {
    private array $sheets = [];   // 시트 이름 => LgSheet
    private array $memo = [];
    private array $busy = [];
    private array $tok = [];
    private int $pos = 0;
    private string $cur = '';

    public function __construct(LgXlsx $wb) {
        foreach ($wb->sheets as $sh) $this->sheets[$sh->title] = $sh;
    }

    /** 칸의 값 — 수식이면 계산한 값, 계산할 수 없으면 '#VALUE!' 같은 오류 글자 */
    public function value(string $sheet, int $c, int $r) {
        $key = "$sheet!$c,$r";
        if (array_key_exists($key, $this->memo)) return $this->memo[$key];
        $v = $this->sheets[$sheet]->cells[$r][$c]['v'] ?? null;
        if (!is_array($v) || !isset($v['f'])) return $v;
        if (isset($this->busy[$key])) return $this->memo[$key] = new LgCalcErr('#REF!');
        $this->busy[$key] = true;
        [$tok, $pos, $cur] = [$this->tok, $this->pos, $this->cur];
        try {
            $this->tok = $this->tokens($v['f']);
            $this->pos = 0;
            $this->cur = $sheet;
            $res = $this->expr();
            if ($this->pos < count($this->tok)) throw new LgCalcErr('#NAME?');
            if (is_array($res)) throw new LgCalcErr('#VALUE!');
        } catch (LgCalcErr $e) {
            $res = $e;
        }
        [$this->tok, $this->pos, $this->cur] = [$tok, $pos, $cur];
        unset($this->busy[$key]);
        return $this->memo[$key] = $res;
    }

    private function tokens(string $f): array {
        preg_match_all("/\\s*(?:'([^']+)'!|([A-Za-z_][A-Za-z0-9_ ]*)!)?(\\$?[A-Z]+\\$?\\d+)(?::(\\$?[A-Z]+\\$?\\d+))?|\\s*([A-Z][A-Z0-9.]*)\\(|\\s*(\\d+(?:\\.\\d+)?)|\\s*([-+*\\/(),])/A", $f, $m, PREG_SET_ORDER);
        if (strlen(implode('', array_column($m, 0))) !== strlen($f)) throw new LgCalcErr('#NAME?');
        $out = [];
        foreach ($m as $t) {
            if (($t[3] ?? '') !== '') {
                $sh = ($t[1] ?? '') !== '' ? $t[1] : (($t[2] ?? '') !== '' ? $t[2] : null);
                $out[] = ['ref', $sh, str_replace('$', '', $t[3]), ($t[4] ?? '') !== '' ? str_replace('$', '', $t[4]) : null];
            } elseif (($t[5] ?? '') !== '') $out[] = ['fn', $t[5]];
            elseif (($t[6] ?? '') !== '') $out[] = ['num', (float)$t[6] == (int)$t[6] ? (int)$t[6] : (float)$t[6]];
            else $out[] = ['op', $t[7]];
        }
        return $out;
    }

    private function peek($op) {
        $t = $this->tok[$this->pos] ?? null;
        return $t && $t[0] === 'op' && $t[1] === $op;
    }

    private function expr() {
        $v = $this->term();
        while ($this->peek('+') || $this->peek('-')) {
            $op = $this->tok[$this->pos++][1];
            $b = $this->term();
            $v = $op === '+' ? $this->num($v) + $this->num($b) : $this->num($v) - $this->num($b);
        }
        return $v;
    }

    private function term() {
        $v = $this->factor();
        while ($this->peek('*') || $this->peek('/')) {
            $op = $this->tok[$this->pos++][1];
            $b = $this->num($this->factor());
            if ($op === '/' && $b == 0) throw new LgCalcErr('#DIV/0!');
            $v = $op === '*' ? $this->num($v) * $b : $this->num($v) / $b;
        }
        return $v;
    }

    private function factor() {
        $t = $this->tok[$this->pos++] ?? throw new LgCalcErr('#NAME?');
        if ($t[0] === 'op' && $t[1] === '-') return -$this->num($this->factor());
        if ($t[0] === 'op' && $t[1] === '+') return $this->num($this->factor());
        if ($t[0] === 'num') return $t[1];
        if ($t[0] === 'op' && $t[1] === '(') {
            $v = $this->expr();
            if (!$this->peek(')')) throw new LgCalcErr('#NAME?');
            $this->pos++;
            return $v;
        }
        if ($t[0] === 'ref') {
            $sheet = $t[1] ?? $this->cur;
            if (!isset($this->sheets[$sheet])) throw new LgCalcErr('#REF!');
            [$c1, $r1] = lg_xlsx_ref($t[2]);
            if ($t[3] === null) {
                $v = $this->value($sheet, $c1, $r1);
                if ($v instanceof LgCalcErr) throw $v;
                return $v;
            }
            [$c2, $r2] = lg_xlsx_ref($t[3]);
            $vals = [];
            for ($r = min($r1, $r2); $r <= max($r1, $r2); $r++) {
                for ($c = min($c1, $c2); $c <= max($c1, $c2); $c++) $vals[] = $this->value($sheet, $c, $r);
            }
            return $vals;    // 범위는 함수 안에서만 쓴다
        }
        if ($t[0] === 'fn') {
            $args = [];
            if (!$this->peek(')')) {
                do { $args[] = $this->expr(); } while ($this->peek(',') && ++$this->pos);
            }
            if (!$this->peek(')')) throw new LgCalcErr('#NAME?');
            $this->pos++;
            return $this->call($t[1], $args);
        }
        throw new LgCalcErr('#NAME?');
    }

    private function call(string $fn, array $args) {
        // SUM 은 범위 안의 숫자만 더하고 글자·빈칸은 건너뛴다 (엑셀과 같게)
        $sum = function (array $list) {
            $s = 0;
            foreach ($list as $a) {
                foreach (is_array($a) ? $a : [$a] as $v) {
                    if ($v instanceof LgCalcErr) throw $v;
                    if (is_int($v) || is_float($v)) $s += $v;
                }
            }
            return $s;
        };
        switch ($fn) {
            case 'SUM': return $sum($args);
            case 'SUBTOTAL':
                if ((int)($args[0] ?? 0) !== 9) throw new LgCalcErr('#VALUE!');
                return $sum(array_slice($args, 1));
            case 'N':
                $v = $args[0] ?? null;
                if (is_array($v)) $v = $v[0] ?? null;
                if ($v instanceof LgCalcErr) throw $v;
                return is_int($v) || is_float($v) ? $v : 0;
        }
        throw new LgCalcErr('#NAME?');
    }

    /** 더하기·빼기에 쓰는 값: 빈칸은 0, 숫자 아닌 글자는 오류 (엑셀의 #VALUE!) */
    private function num($v) {
        if (is_array($v) && isset($v['date'])) return lg_xlsx_serial($v['date']);
        if (is_array($v)) throw new LgCalcErr('#VALUE!');
        if ($v === null || $v === '') return 0;
        if (is_int($v) || is_float($v)) return $v;
        if (is_numeric($v)) return $v + 0;
        throw new LgCalcErr('#VALUE!');
    }
}

class LgCalcErr extends Exception {
    public function __construct(string $code) { parent::__construct($code); }
}

/* ================================================================ 칸 글자 (표시 형식) */

/** 엑셀이 보여주는 모양 그대로의 글자와, 숫자인지(오른쪽 정렬) 여부 */
function lg_xlsx_show($v, ?string $fmt): array {
    if ($v instanceof LgCalcErr) return [$v->getMessage(), 'err'];
    if ($v === null || $v === '') return ['', ''];
    if (is_array($v) && isset($v['date'])) {
        $d = $v['date'];
        return [$fmt === 'mm-dd' ? substr($d, 5, 2) . '-' . substr($d, 8, 2) : $d, 'num'];
    }
    if (is_bool($v)) return [$v ? 'TRUE' : 'FALSE', 'err'];
    if (!is_int($v) && !is_float($v)) return [(string)$v, ''];
    if ($fmt === '@') return [(string)$v, ''];
    // 쉼표 형식들. 0 을 '-' 로 보이는 형식(회계·자금일보)도 있다
    $dash = $fmt === LG_ACCT_FMT || $fmt === '#,##0;-#,##0;"-"';
    if ($fmt === '#,##0' || $dash) {
        $n = (int)round($v);
        if ($n === 0 && $dash) return ['-', 'num'];
        return [($n < 0 ? '-' : '') . number_format(abs($n)), 'num'];
    }
    if ($fmt === '0') return [(string)(int)round($v), 'num'];
    // 일반 형식: 정수는 쉼표 없이, 소수는 열 자리까지
    if (is_int($v) || floor($v) == $v && abs($v) < 1e15) return [(string)(int)$v, 'num'];
    return [rtrim(rtrim(sprintf('%.10G', $v), '0'), '.'), 'num'];
}

/* ================================================================ 시트 → 표 */

const LG_PRINT_W = ['portrait' => 718, 'landscape' => 1047];   // A4, 여백 10mm 일 때 인쇄되는 너비(px)

/** 시트 하나를 엑셀 모양의 표로. 돌려주는 값: [HTML, 화면 너비(px), 인쇄 방향] */
function lg_sheet_html(LgSheet $sh, LgCalc $calc): array {
    $rows = $sh->cells;
    $maxR = max(array_merge([1], array_keys($rows), array_keys($sh->heights)));
    $maxC = max(array_merge([1], array_keys($sh->widths), ...array_map('array_keys', array_values($rows) ?: [[]])));
    // 칸 합치기: 왼쪽 위 칸에 rowspan/colspan, 나머지 칸은 건너뛴다
    $span = [];
    $skip = [];
    foreach ($sh->merges as $m) {
        [$a, $b] = explode(':', $m);
        [$c1, $r1] = lg_xlsx_ref($a);
        [$c2, $r2] = lg_xlsx_ref($b);
        $span["$r1,$c1"] = [$r2 - $r1 + 1, $c2 - $c1 + 1];
        for ($r = $r1; $r <= $r2; $r++) for ($c = $c1; $c <= $c2; $c++) if ($r !== $r1 || $c !== $c1) $skip["$r,$c"] = true;
    }
    // 열 너비: 엑셀 너비(글자 수) → 화면 px. 정하지 않은 열은 엑셀 기본 8.43
    $px = [];
    for ($c = 1; $c <= $maxC; $c++) $px[$c] = (int)round(($sh->widths[$c] ?? 8.43) * 7 + 5);
    $total = array_sum($px);
    // 인쇄 방향: 엑셀에 정한 방향, 없으면 세로에 안 들어갈 만큼 넓을 때만 가로
    $orient = $sh->orientation ?: ($total > LG_PRINT_W['portrait'] * 1.15 ? 'landscape' : 'portrait');
    $zoom = min(1, LG_PRINT_W[$orient] / $total);
    // 고정된 줄(머리줄)은 인쇄할 때 쪽마다 다시 나오게 thead 로
    $headRows = $sh->freeze ? lg_xlsx_ref($sh->freeze)[1] - 1 : 0;
    foreach ($sh->merges as $m) {   // 합친 칸이 머리줄 경계를 넘으면 머리줄을 쓰지 않는다
        [$a, $b] = explode(':', $m);
        if (lg_xlsx_ref($a)[1] <= $headRows && lg_xlsx_ref($b)[1] > $headRows) $headRows = 0;
    }

    $x = '<table class="xl" style="width:' . $total . 'px"><colgroup>';
    foreach ($px as $w) $x .= '<col style="width:' . round($w / $total * 100, 3) . '%">';
    $x .= '</colgroup>';
    for ($r = 1; $r <= $maxR; $r++) {
        if ($r === 1 && $headRows) $x .= '<thead>';
        if ($r === $headRows + 1) $x .= $headRows ? '</thead><tbody>' : '<tbody>';
        $ht = isset($sh->heights[$r]) ? round($sh->heights[$r] * 4 / 3) : 20;
        $x .= '<tr style="height:' . $ht . 'px">';
        for ($c = 1; $c <= $maxC; $c++) {
            if (isset($skip["$r,$c"])) continue;
            $cell = $rows[$r][$c] ?? ['v' => null, 's' => []];
            $s = $cell['s'] ?? [];
            [$text, $kind] = lg_xlsx_show($calc->value($sh->title, $c, $r), $s['fmt'] ?? null);
            $css = [];
            [$sz, $bold] = $s['font'] ?? [11, false];
            if ($sz != 11) $css[] = 'font-size:' . $sz . 'pt';
            if ($bold) $css[] = 'font-weight:700';
            if (!empty($s['fill'])) $css[] = 'background:#' . $s['fill'];
            $al = $s['align'] ?? [];
            $h = $al['h'] ?? ($kind === 'num' ? 'right' : ($kind === 'err' ? 'center' : null));
            if ($h) $css[] = 'text-align:' . ($h === 'centerContinuous' ? 'center' : $h);
            if (($al['v'] ?? '') === 'center') $css[] = 'vertical-align:middle';
            elseif (($al['v'] ?? '') === 'top') $css[] = 'vertical-align:top';
            // 긴 글자는 엑셀처럼 오른쪽 빈칸으로 넘쳐 보이고, 오른쪽 칸에 값이 있으면 잘린다
            $next = $rows[$r][$c + ($span["$r,$c"][1] ?? 1)]['v'] ?? null;
            $clip = $kind === '' && $next !== null && $next !== '';
            $cls = trim((!empty($s['border']) ? 'b ' : '') . (!empty($al['wrap']) ? 'w ' : '') . ($clip ? 'c' : ''));
            $sp = $span["$r,$c"] ?? null;
            $x .= '<td' . ($cls ? ' class="' . $cls . '"' : '') . ($sp && $sp[0] > 1 ? ' rowspan="' . $sp[0] . '"' : '')
                . ($sp && $sp[1] > 1 ? ' colspan="' . $sp[1] . '"' : '') . ($css ? ' style="' . implode(';', $css) . '"' : '') . '>'
                . nl2br(h($text), false) . '</td>';
        }
        $x .= '</tr>';
    }
    $x .= '</tbody></table>';
    return [$x, $total, $orient, $zoom];
}

/** 팝업 창 한 장: 위쪽 단추 줄(시트 고르기·인쇄·엑셀로 저장·닫기) + 시트마다 종이 한 장 */
function lg_print_page(LgXlsx $wb, string $filename, string $save_url): string {
    $calc = new LgCalc($wb);
    $title = preg_replace('/\.xlsx$/', '', $filename);
    $css = 'styles/ledger.css?v=' . (int)@filemtime(__DIR__ . '/../styles/ledger.css');
    $js = 'static/ledger.js?v=' . (int)@filemtime(__DIR__ . '/../static/ledger.js');
    $multi = count($wb->sheets) > 1;
    ob_start(); ?>
<!DOCTYPE html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · 미리보기</title>
<link rel="icon" href="../styles/favicons/ledger.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($css) ?>">
<style>@page port{size:A4 portrait;margin:10mm}@page land{size:A4 landscape;margin:10mm}</style>
</head><body class="pv">
<header class="pv-bar">
  <div class="pv-name"><?= h($title) ?></div>
  <?php if ($multi): ?><div class="pv-tabs" role="tablist" aria-label="시트">
    <button class="btn ghost on" type="button" data-sheet-tab="all" aria-pressed="true">전체</button>
    <?php foreach ($wb->sheets as $i => $sh): ?><button class="btn ghost" type="button" data-sheet-tab="<?= $i ?>" aria-pressed="false"><?= h($sh->title) ?></button><?php endforeach ?>
  </div><?php endif ?>
  <div class="pv-act">
    <button class="btn" type="button" data-print>인쇄</button>
    <a class="btn ghost" href="<?= h($save_url) ?>">엑셀로 저장</a>
    <button class="btn ghost" type="button" data-close>닫기</button>
  </div>
</header>
<main class="pv-pages">
<?php foreach ($wb->sheets as $i => $sh): [$table, $w, $orient, $zoom] = lg_sheet_html($sh, $calc); ?>
  <section class="pv-sheet <?= $orient === 'landscape' ? 'land' : 'port' ?>" data-sheet="<?= $i ?>" aria-label="<?= h($sh->title) ?>" style="--pz:<?= round($zoom, 4) ?>">
    <?php if ($multi): ?><div class="pv-title"><?= h($sh->title) ?></div><?php endif ?>
    <div class="pv-paper"><?= $table ?></div>
  </section>
<?php endforeach ?>
</main>
<script src="<?= h($js) ?>"></script>
</body></html>
<?php
    return ob_get_clean();
}

<?php
/**
 * 내역서 PDF — 채운 .hwpx 를 다시 읽어 같은 모양(쪽 크기·여백·표 칸 너비·테두리·칸 배경·정렬·글자 크기·굵기·그림)으로 그린다.
 * report_lib.php 의 sitehub_report_build 가 hwpx 를 만든 뒤 부른다. 라이브러리 없이 PDF 를 직접 쓴다(운영 서버에 vendor/ 를 깔지 않는다).
 *
 *  - 글꼴은 Pretendard(sitehub/fonts/*.ttf, OFL) 에서 쓴 글자만 골라 넣는다(서브셋) — 어느 PC 에서 열어도 같은 모양.
 *    글꼴 파일이 없으면 PDF 표준 한글 글꼴(HYGoThic-Medium, CP949)을 이름만 쓴다(보는 프로그램의 시스템 글꼴로 그려짐).
 *  - 그림은 hwpx 의 BinData 를 쓴다 — JPEG 는 그대로, PNG·GIF 는 직접 풀고(투명은 SMask), 그 밖의 형식은 GD 가 있으면 GD 로.
 *    글자처럼 취급한 그림(treatAsChar)은 그 문단 자리에, 본문에 떠 있는 그림은 기준(종이·쪽·문단)과 오프셋대로 놓는다.
 *  - 줄바꿈은 어절 단위(너무 긴 어절은 글자 단위). 표는 행 단위로 쪽을 넘긴다(위아래로 합친 칸이 걸친 행들은 같이).
 *    머리 행 반복·글상자·도형·머리말/꼬리말·칸 안에 떠 있는 그림은 그리지 않는다.
 */

const SH_PDF_FONT_DIR = __DIR__ . '/fonts';
const SH_PDF_STD_FONT = 'HYGoThic-Medium';
// 표준 글꼴일 때 0x20~0x7E 의 폭(1/1000 em) — Adobe-Korea1 비례 로마자(CID 1~95)
const SH_PDF_ASCII_W = [333, 416, 416, 833, 625, 916, 833, 250, 500, 500, 500, 833, 291, 833, 291, 375, 625, 625, 625, 625, 625, 625, 625, 625, 625, 625,
    333, 333, 833, 833, 916, 500, 1000, 791, 708, 708, 750, 708, 666, 750, 791, 375, 500, 791, 666, 916, 791, 750, 666, 750, 708, 666, 791, 791, 750,
    1000, 708, 708, 666, 500, 375, 500, 500, 500, 333, 541, 583, 541, 583, 583, 375, 583, 583, 291, 333, 583, 291, 875, 583, 583, 583, 583, 458, 541,
    375, 583, 583, 833, 625, 625, 500, 583, 583, 583, 750];

/* ── TrueType (Pretendard) — 읽기 · 폭 · 서브셋 ───────────────────────────────── */

final class ShTtf {
    public string $name;
    public int $upm;
    public array $bbox;
    public int $ascent, $descent, $capHeight;
    private string $d;
    private array $tables = [];
    private array $cmap = [];
    private array $adv = [];
    private array $loca = [];
    public array $used = [0 => 0];   // gid => 유니코드(ToUnicode 용)

    /** 같은 요청에서 다시 쓰면 파일을 다시 읽지 않는다. 쓴 글자 목록은 새로 시작 */
    public static function load(string $path): ?self {
        static $cache = [];
        if (!array_key_exists($path, $cache)) $cache[$path] = is_file($path) ? new self($path) : null;
        if ($cache[$path]) $cache[$path]->used = [0 => 0];
        return $cache[$path];
    }

    private function u16(int $o): int { return unpack('n', $this->d, $o)[1]; }
    private function s16(int $o): int { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $o): int { return unpack('N', $this->d, $o)[1]; }

    private function __construct(string $path) {
        $this->d = (string)file_get_contents($path);
        $this->name = preg_replace('/[^A-Za-z0-9\-]/', '', pathinfo($path, PATHINFO_FILENAME));
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            $this->tables[substr($this->d, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $t) if (!isset($this->tables[$t])) throw new Exception("글꼴 파일에 {$t} 표가 없습니다: {$path}");
        [$h] = $this->tables['head'];
        $this->upm = $this->u16($h + 18);
        $this->bbox = [$this->s16($h + 36), $this->s16($h + 38), $this->s16($h + 40), $this->s16($h + 42)];
        $longLoca = $this->s16($h + 50) === 1;
        [$hh] = $this->tables['hhea'];
        $this->ascent = $this->s16($hh + 4);
        $this->descent = $this->s16($hh + 6);
        $nh = $this->u16($hh + 34);
        $ng = $this->u16($this->tables['maxp'][0] + 4);
        $this->capHeight = $this->ascent;
        if (isset($this->tables['OS/2']) && $this->u16($this->tables['OS/2'][0]) >= 2) $this->capHeight = $this->s16($this->tables['OS/2'][0] + 88);
        [$hm] = $this->tables['hmtx'];
        $adv = [];
        for ($i = 0; $i < $nh; $i++) $adv[] = $this->u16($hm + 4 * $i);
        $last = end($adv);
        $this->adv = $ng > $nh ? array_merge($adv, array_fill(0, $ng - $nh, $last)) : $adv;
        [$lo] = $this->tables['loca'];
        $this->loca = array_values($longLoca ? unpack('N' . ($ng + 1), $this->d, $lo) : array_map(fn($v) => $v * 2, unpack('n' . ($ng + 1), $this->d, $lo)));
        $this->readCmap();
    }

    private function readCmap(): void {
        [$c] = $this->tables['cmap'];
        $n = $this->u16($c + 2);
        $best = null;
        for ($i = 0; $i < $n; $i++) {
            $pid = $this->u16($c + 4 + 8 * $i); $eid = $this->u16($c + 6 + 8 * $i); $off = $c + $this->u32($c + 8 + 8 * $i);
            $fmt = $this->u16($off);
            $rank = ($pid === 3 && $eid === 10 && $fmt === 12) ? 3 : (($pid === 3 && $eid === 1 && $fmt === 4) ? 2 : ($pid === 0 && in_array($fmt, [4, 12], true) ? 1 : 0));
            if ($rank && (!$best || $rank > $best[0])) $best = [$rank, $off, $fmt];
        }
        if (!$best) throw new Exception('글꼴 파일의 문자표(cmap)를 읽을 수 없습니다.');
        [, $o, $fmt] = $best;
        if ($fmt === 12) {
            $groups = $this->u32($o + 12);
            for ($g = 0; $g < $groups; $g++) {
                $p = $o + 16 + 12 * $g;
                [$s, $e, $gid] = [$this->u32($p), $this->u32($p + 4), $this->u32($p + 8)];
                for ($cp = $s; $cp <= $e && $cp <= 0x2FFFF; $cp++) $this->cmap[$cp] = $gid + $cp - $s;
            }
            return;
        }
        $seg = intdiv($this->u16($o + 6), 2);
        $ends = $o + 14; $starts = $ends + 2 * $seg + 2; $deltas = $starts + 2 * $seg; $ranges = $deltas + 2 * $seg;
        for ($i = 0; $i < $seg; $i++) {
            $e = $this->u16($ends + 2 * $i); $s = $this->u16($starts + 2 * $i); $delta = $this->s16($deltas + 2 * $i);
            $ro = $this->u16($ranges + 2 * $i);
            for ($cp = $s; $cp <= $e && $cp !== 0xFFFF; $cp++) {
                if ($ro === 0) $gid = ($cp + $delta) & 0xFFFF;
                else { $gid = $this->u16($ranges + 2 * $i + $ro + 2 * ($cp - $s)); if ($gid) $gid = ($gid + $delta) & 0xFFFF; }
                if ($gid) $this->cmap[$cp] = $gid;
            }
        }
    }

    /** 글 폭(1em 단위) */
    public function width(string $s): float {
        $w = 0;
        foreach (mb_str_split($s) as $ch) $w += $this->adv[$this->cmap[mb_ord($ch)] ?? 0] ?? 0;
        return $w / $this->upm;
    }

    /** 글 → GID 2바이트씩 (쓴 글자를 기억해 서브셋·ToUnicode 에 넣는다) */
    public function encode(string $s): string {
        $o = '';
        foreach (mb_str_split($s) as $ch) {
            $cp = mb_ord($ch);
            $gid = $this->cmap[$cp] ?? 0;
            if ($gid && !isset($this->used[$gid])) $this->used[$gid] = $cp;
            $o .= pack('n', $gid);
        }
        return $o;
    }

    public function advance(int $gid): int { return $this->adv[$gid] ?? 0; }

    /** 쓴 글자만 남긴 TrueType — GID 는 그대로 두고(CIDToGIDMap Identity) 안 쓴 글리프만 비운다. 합성 글리프의 부품도 넣는다 */
    public function subset(): string {
        [$go] = $this->tables['glyf'];
        $keep = [];
        $todo = array_merge([0], array_keys($this->used));
        while ($todo) {
            $g = array_pop($todo);
            if (isset($keep[$g])) continue;
            $keep[$g] = true;
            $s = $this->loca[$g] ?? 0; $e = $this->loca[$g + 1] ?? 0;
            if ($e <= $s || $this->s16($go + $s) >= 0) continue;
            $p = $go + $s + 10;
            do {
                $flags = $this->u16($p); $comp = $this->u16($p + 2);
                if (!isset($keep[$comp])) $todo[] = $comp;
                $p += 4 + (($flags & 0x0001) ? 4 : 2) + (($flags & 0x0008) ? 2 : (($flags & 0x0040) ? 4 : (($flags & 0x0080) ? 8 : 0)));
            } while ($flags & 0x0020);
        }
        $ng = count($this->loca) - 1;
        $glyf = '';
        $loca = '';
        for ($g = 0; $g < $ng; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (isset($keep[$g]) && $this->loca[$g + 1] > $this->loca[$g]) {
                $glyf .= substr($this->d, $go + $this->loca[$g], $this->loca[$g + 1] - $this->loca[$g]);
                while (strlen($glyf) % 4) $glyf .= "\0";
            }
        }
        $loca .= pack('N', strlen($glyf));
        $t = [];
        foreach (['cmap', 'hhea', 'hmtx', 'maxp', 'OS/2', 'name'] as $k) if (isset($this->tables[$k])) $t[$k] = substr($this->d, $this->tables[$k][0], $this->tables[$k][1]);
        $head = substr($this->d, $this->tables['head'][0], $this->tables['head'][1]);
        $t['head'] = substr($head, 0, 8) . "\0\0\0\0" . substr($head, 12, 38) . pack('n', 1) . substr($head, 52);   // checkSumAdjustment 0, loca 는 long
        $t['post'] = pack('N', 0x00030000) . (isset($this->tables['post']) ? substr($this->d, $this->tables['post'][0] + 4, 12) : str_repeat("\0", 12)) . str_repeat("\0", 16);
        $t['glyf'] = $glyf;
        $t['loca'] = $loca;
        ksort($t, SORT_STRING);
        $n = count($t);
        $sr = 2 ** (int)floor(log($n, 2));
        $out = pack('Nnnnn', 0x00010000, $n, $sr * 16, (int)log($sr, 2), $n * 16 - $sr * 16);
        $off = 12 + 16 * $n;
        $body = '';
        foreach ($t as $tag => $data) {
            $tag = (string)$tag;
            $len = strlen($data);
            $pad = $data . str_repeat("\0", (4 - $len % 4) % 4);
            $sum = 0;
            foreach (unpack('N*', $pad) as $v) $sum = ($sum + $v) & 0xFFFFFFFF;
            $out .= str_pad($tag, 4) . pack('NNN', $sum, $off + strlen($body), $len);
            $body .= $pad;
        }
        return $out . $body;
    }
}

/* ── 그림 풀기 ─────────────────────────────────────────────────────────────────── */

/** 그림 바이트 → PDF 그림 [w, h, cs, filter, data, smask(압축 전 알파 or null), decode] 또는 null */
function sh_pdf_image(string $bin): ?array {
    $info = @getimagesizefromstring($bin);
    if ($info && $info[2] === IMAGETYPE_JPEG) {
        $ch = (int)($info['channels'] ?? 3);
        return ['w' => $info[0], 'h' => $info[1], 'cs' => $ch === 1 ? 'DeviceGray' : ($ch === 4 ? 'DeviceCMYK' : 'DeviceRGB'), 'filter' => 'DCTDecode',
                'data' => $bin, 'smask' => null, 'decode' => $ch === 4 ? '[1 0 1 0 1 0 1 0]' : null];
    }
    $px = null;
    if (strncmp($bin, "\x89PNG", 4) === 0) $px = sh_png_decode($bin);
    elseif (strncmp($bin, 'GIF8', 4) === 0) $px = sh_gif_decode($bin);
    if (!$px && function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bin))) $px = sh_gd_pixels($im);
    if (!$px) return null;
    return ['w' => $px['w'], 'h' => $px['h'], 'cs' => $px['gray'] ? 'DeviceGray' : 'DeviceRGB', 'filter' => 'FlateDecode',
            'data' => gzcompress($px['rgb']), 'smask' => $px['alpha'], 'decode' => null];
}

function sh_gd_pixels($im): array {
    $w = imagesx($im); $h = imagesy($im);
    $true = imageistruecolor($im);
    $rgb = ''; $a = ''; $hasA = false;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y);
        if (!$true) { $k = imagecolorsforindex($im, $c); $c = ($k['alpha'] << 24) | ($k['red'] << 16) | ($k['green'] << 8) | $k['blue']; }
        $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255);
        $al = ($c >> 24) & 127;
        if ($al) $hasA = true;
        $a .= chr(255 - (int)round($al * 255 / 127));
    }
    return ['w' => $w, 'h' => $h, 'gray' => false, 'rgb' => $rgb, 'alpha' => $hasA ? $a : null];
}

/** PNG — 겹침(Adam7) 없는 것. 8·16비트, 팔레트·회색은 1~8비트 */
function sh_png_decode(string $s): ?array {
    $p = 8; $idat = ''; $pal = ''; $trns = null; $hd = null;
    while ($p + 8 <= strlen($s)) {
        $len = unpack('N', $s, $p)[1]; $type = substr($s, $p + 4, 4); $data = substr($s, $p + 8, $len);
        if ($type === 'IHDR') $hd = unpack('Nw/Nh/Cbd/Cct/Ccm/Cfm/Cil', $data);
        elseif ($type === 'PLTE') $pal = $data;
        elseif ($type === 'tRNS') $trns = $data;
        elseif ($type === 'IDAT') $idat .= $data;
        elseif ($type === 'IEND') break;
        $p += 12 + $len;
    }
    if (!$hd || $hd['il'] || !in_array($hd['ct'], [0, 2, 3, 4, 6], true)) return null;
    $raw = @gzuncompress($idat);
    if ($raw === false) return null;
    [$w, $h, $bd, $ct] = [$hd['w'], $hd['h'], $hd['bd'], $hd['ct']];
    $ch = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ct];
    $bpp = max(1, intdiv($ch * $bd, 8));
    $stride = intdiv($w * $ch * $bd + 7, 8);
    $prev = array_fill(0, $stride, 0);
    $rows = [];
    for ($y = 0, $o = 0; $y < $h; $y++) {
        $f = ord($raw[$o] ?? "\0");
        $line = array_values(unpack('C*', str_pad(substr($raw, $o + 1, $stride), $stride, "\0")));
        $o += $stride + 1;
        if ($f !== 0) {
            for ($i = 0; $i < $stride; $i++) {
                $a = $i >= $bpp ? $line[$i - $bpp] : 0;
                $b = $prev[$i];
                $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                if ($f === 1) $v = $line[$i] + $a;
                elseif ($f === 2) $v = $line[$i] + $b;
                elseif ($f === 3) $v = $line[$i] + intdiv($a + $b, 2);
                else { $pp = $a + $b - $c; $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $c); $v = $line[$i] + (($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c)); }
                $line[$i] = $v & 255;
            }
        }
        $rows[] = $line;
        $prev = $line;
    }
    $sample = function (array $line, int $i) use ($bd): int {   // 16비트는 위 바이트만, 1~4비트는 펼친다
        if ($bd === 8) return $line[$i];
        if ($bd === 16) return $line[2 * $i];
        $byte = $line[intdiv($i * $bd, 8)];
        return ($byte >> (8 - $bd - ($i * $bd) % 8)) & ((1 << $bd) - 1);
    };
    $rgb = ''; $alpha = ''; $hasA = false; $gray = in_array($ct, [0, 4], true);
    $palA = $ct === 3 && $trns !== null ? array_values(unpack('C*', $trns)) : [];
    foreach ($rows as $line) {
        for ($x = 0; $x < $w; $x++) {
            switch ($ct) {
                case 0: $v = $sample($line, $x); if ($bd < 8) $v = (int)($v * 255 / ((1 << $bd) - 1)); $rgb .= chr($v); $alpha .= "\xFF"; break;
                case 2: $rgb .= chr($sample($line, 3 * $x)) . chr($sample($line, 3 * $x + 1)) . chr($sample($line, 3 * $x + 2)); $alpha .= "\xFF"; break;
                case 3: $k = $sample($line, $x); $rgb .= substr($pal, 3 * $k, 3) ?: "\0\0\0"; $av = $palA[$k] ?? 255; if ($av < 255) $hasA = true; $alpha .= chr($av); break;
                case 4: $rgb .= chr($sample($line, 2 * $x)); $av = $sample($line, 2 * $x + 1); if ($av < 255) $hasA = true; $alpha .= chr($av); break;
                case 6: $rgb .= chr($sample($line, 4 * $x)) . chr($sample($line, 4 * $x + 1)) . chr($sample($line, 4 * $x + 2)); $av = $sample($line, 4 * $x + 3); if ($av < 255) $hasA = true; $alpha .= chr($av); break;
            }
        }
    }
    return ['w' => $w, 'h' => $h, 'gray' => $gray, 'rgb' => $rgb, 'alpha' => $hasA ? $alpha : null];
}

/** GIF — 첫 장면만. 투명색은 SMask */
function sh_gif_decode(string $s): ?array {
    if (strlen($s) < 13) return null;
    $pk = ord($s[10]);
    $p = 13;
    $gct = '';
    if ($pk & 0x80) { $n = 3 * (2 ** (($pk & 7) + 1)); $gct = substr($s, $p, $n); $p += $n; }
    $trans = -1;
    $L = strlen($s);
    while ($p < $L) {
        $b = ord($s[$p]);
        if ($b === 0x21) {
            if (ord($s[$p + 1] ?? "\0") === 0xF9 && (ord($s[$p + 3] ?? "\0") & 1)) $trans = ord($s[$p + 6]);
            $p += 2;
            while ($p < $L && ($n = ord($s[$p])) > 0) $p += $n + 1;
            $p++;
        } elseif ($b === 0x2C) {
            $w = unpack('v', $s, $p + 5)[1]; $h = unpack('v', $s, $p + 7)[1];
            $ipk = ord($s[$p + 9]);
            $p += 10;
            $ct = $gct;
            if ($ipk & 0x80) { $n = 3 * (2 ** (($ipk & 7) + 1)); $ct = substr($s, $p, $n); $p += $n; }
            $min = ord($s[$p++]);
            $data = '';
            while ($p < $L && ($n = ord($s[$p])) > 0) { $data .= substr($s, $p + 1, $n); $p += $n + 1; }
            $idx = str_pad(sh_gif_lzw($data, $min, $w * $h), $w * $h, "\0");
            if ($ipk & 0x40) {   // 겹침(interlace) 행 순서를 되돌린다
                $rows = str_split($idx, $w);
                $order = [];
                foreach ([[0, 8], [4, 8], [2, 4], [1, 2]] as [$st, $step]) for ($y = $st; $y < $h; $y += $step) $order[] = $y;
                $fixed = array_fill(0, $h, str_repeat("\0", $w));
                foreach ($order as $i => $y) $fixed[$y] = $rows[$i];
                $idx = implode('', $fixed);
            }
            $map = []; $amap = [];
            for ($k = 0; $k < 256; $k++) { $map[chr($k)] = substr($ct, 3 * $k, 3) ?: "\0\0\0"; $amap[chr($k)] = $k === $trans ? "\0" : "\xFF"; }
            $rgb = strtr($idx, $map);   // strtr 는 글자 하나 → 문자열 바꿈이 빠르다
            $alpha = $trans >= 0 ? strtr($idx, $amap) : null;
            return ['w' => $w, 'h' => $h, 'gray' => false, 'rgb' => $rgb, 'alpha' => $alpha !== null && strpos($alpha, "\0") !== false ? $alpha : null];
        } else {
            break;
        }
    }
    return null;
}

function sh_gif_lzw(string $data, int $min, int $npix): string {
    $clear = 1 << $min; $eoi = $clear + 1;
    $size = $min + 1; $next = $eoi + 1;
    $base = [];
    for ($i = 0; $i < $clear; $i++) $base[$i] = chr($i);
    $dict = $base;
    $out = ''; $prev = null;
    $buf = 0; $bits = 0; $pos = 0; $L = strlen($data);
    while (strlen($out) < $npix) {
        while ($bits < $size && $pos < $L) { $buf |= ord($data[$pos++]) << $bits; $bits += 8; }
        if ($bits < $size) break;
        $code = $buf & ((1 << $size) - 1);
        $buf >>= $size; $bits -= $size;
        if ($code === $clear) { $size = $min + 1; $next = $eoi + 1; $dict = $base; $prev = null; continue; }
        if ($code === $eoi) break;
        if ($prev === null) $entry = $dict[$code] ?? "\0";
        elseif ($code < $next && isset($dict[$code])) { $entry = $dict[$code]; if ($next < 4096) $dict[$next++] = $prev . $entry[0]; }
        else { $entry = $prev . $prev[0]; if ($next < 4096) $dict[$next++] = $entry; }
        $out .= $entry;
        $prev = $entry;
        if ($next === (1 << $size) && $size < 12) $size++;
    }
    return substr($out, 0, $npix);
}

/* ── 문서 모양 (header.xml) ─────────────────────────────────────────────────────── */

function sh_pdf_styles(?DOMDocument $h): array {
    $st = ['char' => [], 'para' => [], 'border' => []];
    if (!$h) return $st;
    $xp = new DOMXPath($h);
    foreach ($xp->query('//*[local-name()="charPr"]') as $c) {
        $bold = false;
        foreach ($c->childNodes as $k) if ($k instanceof DOMElement && $k->localName === 'bold') $bold = true;
        $st['char'][$c->getAttribute('id')] = ['size' => max(4, ((int)$c->getAttribute('height') ?: 1000) / 100), 'bold' => $bold,
                                                'color' => sh_pdf_rgb($c->getAttribute('textColor')) ?? [0, 0, 0]];
    }
    foreach ($xp->query('//*[local-name()="paraPr"]') as $p) {
        $al = $xp->query('./*[local-name()="align"]', $p)->item(0);
        $ls = $xp->query('.//*[local-name()="default"]/*[local-name()="lineSpacing"]', $p)->item(0) ?: $xp->query('.//*[local-name()="lineSpacing"]', $p)->item(0);
        $mg = fn($n) => ($e = ($xp->query('.//*[local-name()="default"]//*[local-name()="' . $n . '"]', $p)->item(0) ?: $xp->query('.//*[local-name()="' . $n . '"]', $p)->item(0))) ? (int)$e->getAttribute('value') / 100 : 0;
        $type = $ls ? $ls->getAttribute('type') : 'PERCENT';
        $st['para'][$p->getAttribute('id')] = [
            'align' => $al ? $al->getAttribute('horizontal') : 'LEFT',
            'ls' => $type === 'PERCENT' ? max(80, (int)($ls ? $ls->getAttribute('value') : 160)) / 100 : 1.6,
            'prev' => max(0, $mg('prev')), 'next' => max(0, $mg('next')), 'left' => max(0, $mg('left')),
        ];
    }
    foreach ($xp->query('//*[local-name()="borderFill"]') as $b) {
        $e = [];
        foreach (['left' => 'leftBorder', 'right' => 'rightBorder', 'top' => 'topBorder', 'bottom' => 'bottomBorder'] as $k => $n) {
            $x = $xp->query('./*[local-name()="' . $n . '"]', $b)->item(0);
            $type = $x ? $x->getAttribute('type') : 'NONE';
            $e[$k] = $type === 'NONE' || !$x ? null : ['w' => max(0.25, (float)$x->getAttribute('width') * 2.835), 'color' => sh_pdf_rgb($x->getAttribute('color')) ?? [0, 0, 0],
                                                        'dash' => in_array($type, ['DASH', 'DOT', 'DASH_DOT', 'DASH_DOT_DOT', 'LONG_DASH', 'CIRCLE'], true)];
        }
        $wb = $xp->query('.//*[local-name()="winBrush"]', $b)->item(0);
        $e['fill'] = $wb ? sh_pdf_rgb($wb->getAttribute('faceColor')) : null;
        $st['border'][$b->getAttribute('id')] = $e;
    }
    return $st;
}

function sh_pdf_rgb(?string $c): ?array {
    if (!$c || !preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $c, $m)) return null;
    return [hexdec($m[1]) / 255, hexdec($m[2]) / 255, hexdec($m[3]) / 255];
}

/* ── PDF 쓰기 ──────────────────────────────────────────────────────────────────── */

final class ShPdf {
    public array $pages = [];
    private string $cur = '';
    public float $w = 595.28, $h = 841.89;
    public ?ShTtf $reg = null;
    public ?ShTtf $bold = null;
    private array $images = [];   // key => [번호, 그림]

    public function __construct() {
        if (function_exists('mb_ord') && function_exists('mb_str_split')) {
            try {
                $this->reg = ShTtf::load(SH_PDF_FONT_DIR . '/Pretendard-Regular.ttf');
                $this->bold = $this->reg ? ShTtf::load(SH_PDF_FONT_DIR . '/Pretendard-Bold.ttf') : null;
            } catch (Throwable $e) { $this->reg = $this->bold = null; }
        }
    }

    /** 글 폭(pt) */
    public function width(string $s, float $size, bool $bold = false): float {
        $f = $bold && $this->bold ? $this->bold : $this->reg;
        if ($f) return $f->width($s) * $size;
        $w = 0;
        foreach (preg_split('//u', strtr($s, ['~' => '∼', '\\' => '＼']), -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $o = ord($ch);
            $w += strlen($ch) === 1 ? ($o >= 0x20 && $o <= 0x7E ? SH_PDF_ASCII_W[$o - 0x20] / 1000 : 0.5) : 1.0;
        }
        return $w * $size;
    }

    public function addPage(float $w, float $h): void {
        if ($this->cur !== '' || $this->pages) $this->pages[] = [$this->w, $this->h, $this->cur];
        $this->cur = '';
        $this->w = $w;
        $this->h = $h;
    }

    public function done(): void { $this->pages[] = [$this->w, $this->h, $this->cur]; $this->cur = ''; }

    private static function n(float $v): string { return rtrim(rtrim(sprintf('%.3F', $v), '0'), '.') ?: '0'; }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->cur .= sprintf("%s %s %s rg %s %s %s %s re f\n", self::n($rgb[0]), self::n($rgb[1]), self::n($rgb[2]),
            self::n($x), self::n($this->h - $y - $h), self::n($w), self::n($h));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lw, array $rgb, bool $dash = false): void {
        $this->cur .= sprintf("%s w %s %s %s RG %s%s %s m %s %s l S%s\n", self::n($lw), self::n($rgb[0]), self::n($rgb[1]), self::n($rgb[2]),
            $dash ? '[2 2] 0 d ' : '', self::n($x1), self::n($this->h - $y1), self::n($x2), self::n($this->h - $y2), $dash ? ' [] 0 d' : '');
    }

    public function text(float $x, float $baseline, string $s, float $size, bool $bold, array $rgb): void {
        if ($s === '') return;
        $c = sprintf('%s %s %s', self::n($rgb[0]), self::n($rgb[1]), self::n($rgb[2]));
        $fake = $bold && !($this->reg && $this->bold);   // 굵은 글꼴이 없으면 획 두께로
        if ($this->reg) {
            $f = $bold && $this->bold ? $this->bold : $this->reg;
            $font = $f === $this->reg ? 'F1' : 'F2';
            $hex = bin2hex($f->encode($s));
        } else {
            $font = 'F1';
            $enc = @mb_convert_encoding(strtr($s, ['~' => '∼', '\\' => '＼']), 'UHC', 'UTF-8');
            if ($enc === false || $enc === '') return;
            $hex = bin2hex($enc);
        }
        $this->cur .= "BT /{$font} " . self::n($size) . " Tf {$c} rg " . ($fake ? "{$c} RG 2 Tr " . self::n($size * 0.035) . ' w ' : '0 Tr ')
            . self::n($x) . ' ' . self::n($this->h - $baseline) . " Td <{$hex}> Tj ET\n";
    }

    /** 그림 — $key 가 같으면 한 번만 넣는다 */
    public function image(string $key, string $bin, float $x, float $y, float $w, float $h): bool {
        if (!isset($this->images[$key])) {
            $img = sh_pdf_image($bin);
            if (!$img) return false;
            $this->images[$key] = [count($this->images) + 1, $img];
        }
        $this->cur .= sprintf("q %s 0 0 %s %s %s cm /Im%d Do Q\n", self::n($w), self::n($h), self::n($x), self::n($this->h - $y - $h), $this->images[$key][0]);
        return true;
    }

    private function toUnicode(ShTtf $f): string {
        $pairs = [];
        foreach ($f->used as $gid => $cp) if ($gid && $cp) $pairs[] = sprintf('<%04X> <%s>', $gid, strtoupper(bin2hex(mb_convert_encoding(mb_chr($cp), 'UTF-16BE', 'UTF-8'))));
        $s = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
           . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($pairs, 100) as $chunk) $s .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        return $s . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    public function output(string $title): string {
        $objs = [];
        $add = function (string $body) use (&$objs): int { $objs[] = $body; return count($objs); };
        $stream = fn(string $dict, string $data) => $add('<< ' . $dict . ' /Length ' . strlen($data) . " >>\nstream\n{$data}\nendstream");
        $catalog = $add('');
        $pagesId = $add('');
        $fontRefs = [];
        if ($this->reg) {
            foreach (['F1' => $this->reg, 'F2' => $this->bold] as $key => $f) {
                if (!$f || ($key === 'F2' && count($f->used) <= 1)) { $fontRefs[$key] = $fontRefs['F1']; continue; }   // 굵은 글자를 안 썼으면 넣지 않는다
                $tag = strtr(substr(strtoupper(md5($f->name . implode(',', array_keys($f->used)))), 0, 6), '0123456789', 'GHIJKLMNOP');
                $name = "{$tag}+{$f->name}";
                $raw = $f->subset();
                $ff = $stream('/Length1 ' . strlen($raw) . ' /Filter /FlateDecode', gzcompress($raw));
                $k = 1000 / $f->upm;
                $fd = $add(sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
                    $name, $f->bbox[0] * $k, $f->bbox[1] * $k, $f->bbox[2] * $k, $f->bbox[3] * $k, $f->ascent * $k, $f->descent * $k, $f->capHeight * $k, $ff));
                $gids = array_keys($f->used);
                sort($gids);
                $w = implode(' ', array_map(fn($g) => $g . ' [' . (int)round($f->advance($g) * $k) . ']', $gids));
                $cid = $add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /{$name} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor {$fd} 0 R /CIDToGIDMap /Identity /DW 1000 /W [{$w}] >>");
                $tu = $stream('/Filter /FlateDecode', gzcompress($this->toUnicode($f)));
                $fontRefs[$key] = $add("<< /Type /Font /Subtype /Type0 /BaseFont /{$name} /Encoding /Identity-H /DescendantFonts [{$cid} 0 R] /ToUnicode {$tu} 0 R >>");
            }
        } else {
            $name = SH_PDF_STD_FONT;
            $fd = $add("<< /Type /FontDescriptor /FontName /{$name} /Flags 6 /FontBBox [-25 -254 1000 880] /ItalicAngle 0 /Ascent 880 /Descent -120 /CapHeight 880 /StemV 93 >>");
            $cid = $add("<< /Type /Font /Subtype /CIDFontType0 /BaseFont /{$name} /CIDSystemInfo << /Registry (Adobe) /Ordering (Korea1) /Supplement 1 >> /FontDescriptor {$fd} 0 R /DW 1000 /W [1 [" . implode(' ', SH_PDF_ASCII_W) . "]] >>");
            $fontRefs['F1'] = $fontRefs['F2'] = $add("<< /Type /Font /Subtype /Type0 /BaseFont /{$name} /Encoding /KSCms-UHC-H /DescendantFonts [{$cid} 0 R] >>");
        }
        $xo = [];
        foreach ($this->images as [$n, $img]) {
            $sm = $img['smask'] !== null ? $stream("/Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode", gzcompress($img['smask'])) : null;
            $xo[] = "/Im{$n} " . $stream("/Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace /{$img['cs']} /BitsPerComponent 8 /Filter /{$img['filter']}"
                . ($img['decode'] ? " /Decode {$img['decode']}" : '') . ($sm ? " /SMask {$sm} 0 R" : ''), $img['data']) . ' 0 R';
        }
        $res = "<< /Font << /F1 {$fontRefs['F1']} 0 R /F2 {$fontRefs['F2']} 0 R >>" . ($xo ? ' /XObject << ' . implode(' ', $xo) . ' >>' : '') . ' >>';
        $kids = [];
        foreach ($this->pages as [$pw, $ph, $content]) {
            $cs = $stream('/Filter /FlateDecode', gzcompress($content));
            $kids[] = $add("<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 " . self::n($pw) . ' ' . self::n($ph) . "] /Resources {$res} /Contents {$cs} 0 R >>");
        }
        $objs[$catalog - 1] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "{$k} 0 R", $kids)) . '] /Count ' . count($kids) . ' >>';
        $info = $add('<< /Title <FEFF' . strtoupper(bin2hex(mb_convert_encoding($title, 'UTF-16BE', 'UTF-8'))) . '> /Producer (BlueLab SiteHub) /CreationDate (D:' . date('YmdHis') . ') >>');
        $out = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($objs as $i => $body) { $offs[] = strlen($out); $out .= ($i + 1) . " 0 obj\n{$body}\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= 'trailer << /Size ' . (count($objs) + 1) . " /Root {$catalog} 0 R /Info {$info} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $out;
    }
}

/* ── 배치 ──────────────────────────────────────────────────────────────────────── */

final class ShPdfLayout {
    private ShPdf $pdf;
    private array $st;
    private $bin;   // 그림 id → 바이트
    private float $x0 = 0, $x1 = 0, $y0 = 0, $y1 = 0, $y = 0, $pw = 0, $ph = 0;
    private array $floats = [];

    public function __construct(ShPdf $pdf, array $st, callable $bin) { $this->pdf = $pdf; $this->st = $st; $this->bin = $bin; }

    /** 구역 하나 = 새 쪽부터. 쪽 크기·여백은 구역의 secPr */
    public function section(DOMDocument $doc): void {
        $xp = new DOMXPath($doc);
        $pp = $xp->query('//*[local-name()="pagePr"]')->item(0);
        $mg = $pp ? $xp->query('./*[local-name()="margin"]', $pp)->item(0) : null;
        $this->pw = $pp ? (int)$pp->getAttribute('width') / 100 : 595.28;
        $this->ph = $pp ? (int)$pp->getAttribute('height') / 100 : 841.89;
        if ($pp && $pp->getAttribute('landscape') === 'NARROWLY' && $this->pw < $this->ph) [$this->pw, $this->ph] = [$this->ph, $this->pw];
        $m = fn($k, $d) => $mg ? (int)$mg->getAttribute($k) / 100 : $d;
        $this->x0 = $m('left', 85); $this->x1 = $this->pw - $m('right', 85);
        $this->y0 = $m('top', 56.7) + $m('header', 42.5); $this->y1 = $this->ph - $m('bottom', 42.5) - $m('footer', 42.5);
        if ($this->y1 - $this->y0 < 100) { $this->y0 = $m('top', 56.7); $this->y1 = $this->ph - $m('bottom', 42.5); }
        $this->newPage();
        foreach ($doc->documentElement->childNodes as $p) if ($p instanceof DOMElement && $p->localName === 'p') $this->paragraph($p, $this->x0, $this->x1 - $this->x0);
    }

    private function newPage(): void { $this->pdf->addPage($this->pw, $this->ph); $this->y = $this->y0; }

    /** 문단 → 그릴 줄들. 글 줄은 [text,size,bold,color,align,lh], 글자처럼 놓인 그림은 [img,w,h]. 떠 있는 그림은 $floats 로 */
    private function lines(DOMElement $p, float $width): array {
        $pp = $this->st['para'][$p->getAttribute('paraPrIDRef')] ?? ['align' => 'LEFT', 'ls' => 1.6, 'prev' => 0, 'next' => 0, 'left' => 0];
        $cp = null;
        $pics = [];
        foreach (sh_hx_kids($p, 'run') as $r) {
            if (!$cp && sh_hx_kids($r, 't')) $cp = $this->st['char'][$r->getAttribute('charPrIDRef')] ?? null;
            foreach (sh_hx_kids($r, 'pic') as $pic) $pics[] = $pic;
        }
        if (!$cp) { $r = sh_hx_kid($p, 'run'); $cp = $this->st['char'][$r ? $r->getAttribute('charPrIDRef') : '0'] ?? null; }
        $cp = $cp ?: ['size' => 10, 'bold' => false, 'color' => [0, 0, 0]];
        $out = [];
        $text = str_replace("\t", '    ', sh_hx_para_text($p));
        if ($text !== '' || !$pics) {
            foreach (explode("\n", $text) as $seg) {
                foreach ($this->wrap($seg, $cp['size'], $cp['bold'], $width - $pp['left']) as $ln) {
                    $out[] = ['text' => $ln, 'align' => $pp['align'], 'lh' => $cp['size'] * $pp['ls'], 'indent' => $pp['left']] + $cp;
                }
            }
        }
        foreach ($pics as $pic) {
            $img = $this->picInfo($pic);
            if (!$img) continue;
            if ($img['inline']) {
                $scale = $img['w'] > $width ? $width / $img['w'] : 1;
                $out[] = ['img' => $img['id'], 'w' => $img['w'] * $scale, 'h' => $img['h'] * $scale, 'align' => $pp['align'], 'lh' => $img['h'] * $scale + 2,
                          'size' => $img['h'] * $scale, 'indent' => $pp['left'], 'bold' => false];
            } else {
                $this->floats[] = $img;
            }
        }
        if ($out) { $out[0]['before'] = $pp['prev']; $out[count($out) - 1]['after'] = $pp['next']; }
        return $out;
    }

    private function picInfo(DOMElement $pic): ?array {
        $img = null;
        foreach ($pic->getElementsByTagNameNS('*', 'img') as $e) { $img = $e; break; }
        if (!$img || ($id = $img->getAttribute('binaryItemIDRef')) === '') return null;
        $sz = sh_hx_kid($pic, 'sz') ?: sh_hx_kid($pic, 'curSz');
        $pos = sh_hx_kid($pic, 'pos');
        $w = $sz ? (int)$sz->getAttribute('width') / 100 : 0; $h = $sz ? (int)$sz->getAttribute('height') / 100 : 0;
        if ($w <= 0 || $h <= 0) return null;
        return ['id' => $id, 'w' => $w, 'h' => $h, 'inline' => !$pos || $pos->getAttribute('treatAsChar') !== '0',
                'hrel' => $pos ? $pos->getAttribute('horzRelTo') : 'COLUMN', 'vrel' => $pos ? $pos->getAttribute('vertRelTo') : 'PARA',
                'halign' => $pos ? $pos->getAttribute('horzAlign') : 'LEFT', 'valign' => $pos ? $pos->getAttribute('vertAlign') : 'TOP',
                'hoff' => $pos ? (int)$pos->getAttribute('horzOffset') / 100 : 0, 'voff' => $pos ? (int)$pos->getAttribute('vertOffset') / 100 : 0];
    }

    /** 떠 있는 그림 — 종이·쪽(본문)·문단 기준 + 정렬 + 오프셋. 글 흐름은 밀지 않는다 */
    private function drawFloats(float $px, float $py, float $pw): void {
        foreach ($this->floats as $f) {
            [$ax, $aw] = match ($f['hrel']) { 'PAPER' => [0, $this->pw], 'PAGE', 'COLUMN' => [$this->x0, $this->x1 - $this->x0], default => [$px, $pw] };
            [$ay, $ah] = match ($f['vrel']) { 'PAPER' => [0, $this->ph], 'PAGE' => [$this->y0, $this->y1 - $this->y0], default => [$py, $f['h']] };
            $x = match ($f['halign']) { 'CENTER' => $ax + ($aw - $f['w']) / 2, 'RIGHT' => $ax + $aw - $f['w'] - $f['hoff'], default => $ax + $f['hoff'] };
            $y = match ($f['valign']) { 'CENTER' => $ay + ($ah - $f['h']) / 2, 'BOTTOM' => $ay + $ah - $f['h'] - $f['voff'], default => $ay + $f['voff'] };
            $this->drawImage($f['id'], $x, $y, $f['w'], $f['h']);
        }
        $this->floats = [];
    }

    private function drawImage(string $id, float $x, float $y, float $w, float $h): void {
        $bin = ($this->bin)($id);
        if ($bin !== null) $this->pdf->image($id, $bin, $x, $y, $w, $h);
    }

    /** 한 줄을 폭에 맞춰 나눈다 — 어절 단위, 긴 어절은 글자 단위 */
    private function wrap(string $text, float $size, bool $bold, float $max): array {
        if ($text === '') return [''];
        if ($max <= $size) $max = $size;
        $W = fn($s) => $this->pdf->width($s, $size, $bold);
        $lines = [];
        $cur = '';
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $tok) {
            if ($W($cur . $tok) <= $max) { $cur .= $tok; continue; }
            if (trim($tok) === '') { $lines[] = rtrim($cur); $cur = ''; continue; }
            if ($cur !== '') { $lines[] = rtrim($cur); $cur = ''; }
            foreach (mb_str_split($tok) as $ch) {
                if ($cur !== '' && $W($cur . $ch) > $max) { $lines[] = $cur; $cur = ''; }
                $cur .= $ch;
            }
        }
        $lines[] = rtrim($cur);
        return $lines;
    }

    /** 줄 묶음의 높이 — 마지막 줄의 줄 간격 여유는 빼서 칸 안 세로 가운데가 맞게 */
    private static function height(array $lines): float {
        $h = 0;
        foreach ($lines as $l) $h += $l['lh'] + ($l['before'] ?? 0) + ($l['after'] ?? 0);
        if ($lines) { $last = end($lines); $h -= isset($last['img']) ? 2 : $last['lh'] - $last['size'] * 1.15; }
        return max(0, $h);
    }

    private function paragraph(DOMElement $p, float $x, float $w): void {
        $tbls = [];
        foreach (sh_hx_kids($p, 'run') as $r) foreach ($r->childNodes as $c) if ($c instanceof DOMElement && $c->localName === 'tbl') $tbls[] = $c;
        $top = $this->y;
        $lines = $this->lines($p, $w);
        $hasText = trim(implode('', array_map(fn($l) => $l['text'] ?? 'x', $lines))) !== '';
        $this->drawFloats($x, $top, $w);
        if ($tbls) {
            $align = $lines[0]['align'] ?? 'LEFT';
            foreach ($tbls as $t) $this->table($t, $x, $w, $align);
            if (!$hasText) return;
        }
        foreach ($lines as $l) {
            $this->y += $l['before'] ?? 0;
            if ($this->y + $l['lh'] > $this->y1 + 0.01 && $this->y > $this->y0) $this->newPage();
            $this->drawLine($l, $x, $w, $this->y);
            $this->y += $l['lh'] + ($l['after'] ?? 0);
        }
    }

    private function drawLine(array $l, float $x, float $w, float $top): void {
        $ix = $x + ($l['indent'] ?? 0);
        $iw = $w - ($l['indent'] ?? 0);
        $tw = isset($l['img']) ? $l['w'] : $this->pdf->width($l['text'], $l['size'], $l['bold']);
        $tx = match ($l['align']) { 'CENTER' => $ix + ($iw - $tw) / 2, 'RIGHT' => $ix + $iw - $tw, default => $ix };
        if (isset($l['img'])) { $this->drawImage($l['img'], $tx, $top, $l['w'], $l['h']); return; }
        $asc = $this->pdf->reg ? $this->pdf->reg->ascent / $this->pdf->reg->upm : 0.88;
        $this->pdf->text($tx, $top + $l['size'] * min(0.95, $asc), $l['text'], $l['size'], $l['bold'], $l['color']);
    }

    /** 표 — 칸 너비는 cellSz, 행 높이는 cellSz 와 글 높이 중 큰 값. 본문보다 넓으면 줄인다 */
    private function table(DOMElement $tbl, float $x, float $w, string $align): void {
        $rows = sh_hx_kids($tbl, 'tr');
        $nC = (int)$tbl->getAttribute('colCnt');
        $nR = count($rows);
        if (!$nR || $nC < 1) return;
        $in = sh_hx_kid($tbl, 'inMargin');
        $defM = $in ? array_map(fn($k) => (int)$in->getAttribute($k) / 100, ['l' => 'left', 'r' => 'right', 't' => 'top', 'b' => 'bottom']) : ['l' => 5.1, 'r' => 5.1, 't' => 1.41, 'b' => 1.41];
        $cells = [];
        foreach ($rows as $ri => $tr) {
            foreach (sh_hx_kids($tr, 'tc') as $tc) {
                $a = sh_hx_kid($tc, 'cellAddr'); $sp = sh_hx_kid($tc, 'cellSpan'); $sz = sh_hx_kid($tc, 'cellSz');
                $cm = sh_hx_kid($tc, 'cellMargin');
                $mar = $tc->getAttribute('hasMargin') === '1' && $cm ? array_map(fn($k) => (int)$cm->getAttribute($k) / 100, ['l' => 'left', 'r' => 'right', 't' => 'top', 'b' => 'bottom']) : $defM;
                $sub = sh_hx_kid($tc, 'subList');
                $cells[] = ['tc' => $tc, 'r' => $ri, 'c' => $a ? (int)$a->getAttribute('colAddr') : 0, 'cs' => $sp ? max(1, (int)$sp->getAttribute('colSpan')) : 1,
                            'rs' => $sp ? max(1, (int)$sp->getAttribute('rowSpan')) : 1, 'w' => $sz ? (int)$sz->getAttribute('width') / 100 : 0,
                            'h' => $sz ? (int)$sz->getAttribute('height') / 100 : 0, 'm' => $mar, 'bf' => $tc->getAttribute('borderFillIDRef'),
                            'va' => $sub ? $sub->getAttribute('vertAlign') : 'CENTER'];
            }
        }
        // 열 경계: 시작 열이 알려진 칸에서 끝 열을 차례로 구한다
        $cx = [0 => 0.0];
        for ($pass = 0; $pass < $nC + 2; $pass++) {
            $changed = false;
            foreach ($cells as $c) {
                if (isset($cx[$c['c']]) && !isset($cx[$c['c'] + $c['cs']])) { $cx[$c['c'] + $c['cs']] = $cx[$c['c']] + $c['w']; $changed = true; }
                if (!isset($cx[$c['c']]) && isset($cx[$c['c'] + $c['cs']])) { $cx[$c['c']] = $cx[$c['c'] + $c['cs']] - $c['w']; $changed = true; }
            }
            if (!$changed) break;
        }
        $total = $cx[$nC] ?? max($cx);
        for ($i = 1; $i <= $nC; $i++) if (!isset($cx[$i])) { $j = $i; while ($j <= $nC && !isset($cx[$j])) $j++; $nx = $cx[$j] ?? $total; $cx[$i] = $cx[$i - 1] + ($nx - $cx[$i - 1]) / ($j - $i + 1); }
        ksort($cx);
        $scale = $total > $w ? $w / $total : 1.0;
        $tw = $total * $scale;
        $tx = match ($align) { 'CENTER' => $x + ($w - $tw) / 2, 'RIGHT' => $x + $w - $tw, default => $x };
        foreach ($cx as $k => $v) $cx[$k] = $tx + $v * $scale;

        $rh = array_fill(0, $nR, 0.0);
        foreach ($cells as &$c) {
            $cw = ($cx[min($nC, $c['c'] + $c['cs'])] ?? $cx[$nC]) - $cx[$c['c']];
            $c['lines'] = [];
            // 칸 안쪽 여백 절반까지는 넘쳐도 줄을 바꾸지 않는다 — 한글과 글꼴 폭이 조금씩 달라 쓸데없이 줄이 바뀌지 않게
            $fit = max(10, $cw - $c['m']['l'] - $c['m']['r']) + 0.5 * ($c['m']['l'] + $c['m']['r']);
            foreach (sh_hx_cell_paras($c['tc']) as $p) $c['lines'] = array_merge($c['lines'], $this->lines($p, $fit));
            $this->floats = [];
            $c['need'] = self::height($c['lines']) + $c['m']['t'] + $c['m']['b'];
            if ($c['rs'] === 1) $rh[$c['r']] = max($rh[$c['r']], $c['h'], $c['need']);
        }
        unset($c);
        foreach ($cells as $c) {
            if ($c['rs'] < 2) continue;
            $last = min($nR - 1, $c['r'] + $c['rs'] - 1);
            $sum = array_sum(array_slice($rh, $c['r'], $last - $c['r'] + 1));
            $need = max($c['need'], $c['h']);
            if ($sum < $need) $rh[$last] += $need - $sum;
        }
        foreach ($rh as $i => $v) if ($v <= 0) $rh[$i] = 12;

        // 위아래로 합친 칸이 걸친 행은 한 덩어리로 쪽을 넘긴다
        $groupEnd = [];
        for ($r = 0; $r < $nR; $r++) $groupEnd[$r] = $r;
        foreach ($cells as $c) $groupEnd[$c['r']] = max($groupEnd[$c['r']], $c['r'] + $c['rs'] - 1);
        $r = 0;
        while ($r < $nR) {
            $end = min($nR - 1, $groupEnd[$r]);
            for ($k = $r; $k <= $end; $k++) $end = min($nR - 1, max($end, $groupEnd[$k]));
            $gh = array_sum(array_slice($rh, $r, $end - $r + 1));
            if ($this->y + $gh > $this->y1 + 0.01 && $this->y > $this->y0 + 0.01) $this->newPage();
            $ry = [$r => $this->y];
            for ($k = $r + 1; $k <= $end + 1; $k++) $ry[$k] = $ry[$k - 1] + $rh[$k - 1];
            $group = array_filter($cells, fn($c) => $c['r'] >= $r && $c['r'] <= $end);
            foreach ($group as $c) $this->cellFill($c, $cx, $ry, $nC);
            foreach ($group as $c) $this->cellText($c, $cx, $ry, $nC);
            foreach ($group as $c) $this->cellBorders($c, $cx, $ry, $nC);
            $this->y = $ry[$end + 1];
            $r = $end + 1;
        }
        $this->y += 2;
    }

    private function box(array $c, array $cx, array $ry, int $nC): array {
        return [$cx[$c['c']], $ry[$c['r']], $cx[min($nC, $c['c'] + $c['cs'])], $ry[$c['r'] + $c['rs']] ?? end($ry)];
    }

    private function cellFill(array $c, array $cx, array $ry, int $nC): void {
        $bf = $this->st['border'][$c['bf']] ?? null;
        if (!$bf || !$bf['fill'] || $bf['fill'] === [1.0, 1.0, 1.0]) return;
        [$x1, $y1, $x2, $y2] = $this->box($c, $cx, $ry, $nC);
        $this->pdf->rect($x1, $y1, $x2 - $x1, $y2 - $y1, $bf['fill']);
    }

    private function cellBorders(array $c, array $cx, array $ry, int $nC): void {
        $def = ['w' => 0.34, 'color' => [0, 0, 0], 'dash' => false];
        $bf = $this->st['border'][$c['bf']] ?? ['left' => $def, 'right' => $def, 'top' => $def, 'bottom' => $def];
        [$x1, $y1, $x2, $y2] = $this->box($c, $cx, $ry, $nC);
        foreach (['top' => [$x1, $y1, $x2, $y1], 'bottom' => [$x1, $y2, $x2, $y2], 'left' => [$x1, $y1, $x1, $y2], 'right' => [$x2, $y1, $x2, $y2]] as $k => $ln) {
            if (!empty($bf[$k])) $this->pdf->line($ln[0], $ln[1], $ln[2], $ln[3], $bf[$k]['w'], $bf[$k]['color'], $bf[$k]['dash']);
        }
    }

    private function cellText(array $c, array $cx, array $ry, int $nC): void {
        if (!$c['lines']) return;
        [$x1, $y1, $x2, $y2] = $this->box($c, $cx, $ry, $nC);
        $ih = $y2 - $y1 - $c['m']['t'] - $c['m']['b'];
        $th = self::height($c['lines']);
        $top = $y1 + $c['m']['t'] + match ($c['va']) { 'TOP' => 0, 'BOTTOM' => max(0, $ih - $th), default => max(0, ($ih - $th) / 2) };
        foreach ($c['lines'] as $l) {
            $top += $l['before'] ?? 0;
            $this->drawLine($l, $x1 + $c['m']['l'], $x2 - $x1 - $c['m']['l'] - $c['m']['r'], $top);
            $top += $l['lh'] + ($l['after'] ?? 0);
        }
    }
}

/** 채운 hwpx → PDF 파일 */
function sitehub_report_pdf(string $hwpx, string $outPdf, string $title = ''): void {
    if (!function_exists('mb_convert_encoding')) throw new Exception('PHP mbstring 확장이 없어 PDF 를 만들 수 없습니다.');
    [$zip, $docs] = sitehub_hwpx_open($hwpx);
    $hx = (string)$zip->getFromName('Contents/header.xml');
    // 그림: content.hpf 의 manifest(id → BinData/…) 로 찾는다
    $items = [];
    $hpf = (string)$zip->getFromName('Contents/content.hpf');
    if ($hpf !== '' && preg_match_all('/<opf:item\b[^>]*>/u', $hpf, $mm)) {
        foreach ($mm[0] as $tag) if (preg_match('/\bid="([^"]+)"/', $tag, $a) && preg_match('/\bhref="([^"]+)"/', $tag, $b)) $items[$a[1]] = $b[1];
    }
    $bins = [];
    $bin = function (string $id) use ($zip, $items, &$bins): ?string {
        if (!array_key_exists($id, $bins)) {
            $href = $items[$id] ?? null;
            $data = $href !== null ? $zip->getFromName($href) : false;
            if ($data === false) {   // manifest 에 없으면 BinData/<id>.* 를 찾는다
                for ($i = 0; $i < $zip->numFiles; $i++) { $n = (string)$zip->getNameIndex($i); if (preg_match('#^BinData/' . preg_quote($id, '#') . '\.#i', $n)) { $data = $zip->getFromName($n); break; } }
            }
            $bins[$id] = $data === false ? null : $data;
        }
        return $bins[$id];
    };
    $head = null;
    if ($hx !== '') { $head = new DOMDocument(); if (!@$head->loadXML($hx, LIBXML_NONET | LIBXML_PARSEHUGE)) $head = null; }
    $pdf = new ShPdf();
    $lay = new ShPdfLayout($pdf, sh_pdf_styles($head), $bin);
    foreach ($docs as $doc) $lay->section($doc);
    $pdf->done();
    $zip->close();
    if (@file_put_contents($outPdf, $pdf->output($title)) === false) throw new Exception('PDF 파일을 저장할 수 없습니다.');
}

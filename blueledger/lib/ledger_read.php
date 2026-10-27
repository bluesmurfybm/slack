<?php
/**
 * BlueLedger — 은행·카드사·홈택스 파일 읽기.
 *
 *  파이썬 버전의 read_table() 을 옮겼다. 확장자가 아니라 '실제 내용'을 보고 읽는다:
 *   - PK…          진짜 xlsx  (파이썬: openpyxl)
 *   - D0 CF 11 E0  옛날 xls   (파이썬: xlrd)   — 홈택스 세금계산서·일부 은행
 *   - <table …>    '.xls' 이름의 웹페이지 (농협 등)
 *   - 그 밖        CSV
 *  이 서버엔 ZipArchive·PhpSpreadsheet 가 없어서 xlsx 의 ZIP 과 xls 의 OLE·BIFF8 을 직접 푼다.
 *
 *  값의 모양도 파이썬과 같게 맞춘다 — 저장해 둔 '열 이름'과 비교하거나 str() 로 글자를 만들 때 결과가 같아야 해서다.
 *   - xlsx: 빈칸 null, 숫자는 정수/실수(소수점이 있으면 실수), 날짜 서식이면 LgDate, 시트 크기만큼 칸을 채운다
 *   - xls : 빈칸 '', 숫자는 모두 실수(1234.0), 날짜 서식이면 LgDate, 시트 크기만큼 칸을 채운다
 */

/** 엑셀의 날짜 칸 (파이썬 datetime 자리) */
final class LgDate {
    public function __construct(public string $iso) {}           // 'YYYY-MM-DD HH:MM:SS'
    public function __toString(): string { return $this->iso; }
    public function date(): string { return substr($this->iso, 0, 10); }
}

class LgReadError extends Exception {}

/** 파이썬 str(값) 과 같은 글자 (None·True·1234.0 처럼) */
function lg_pystr($v) {
    if ($v === null) return 'None';
    if ($v === true) return 'True';
    if ($v === false) return 'False';
    if (is_float($v)) {
        if (is_finite($v) && floor($v) == $v && abs($v) < 1e16) return sprintf('%.1f', $v);
        $s = (string)$v;
        return str_replace(['E+', 'E-'], ['e+', 'e-'], $s);
    }
    return (string)$v;
}

/** 파일 읽기 → 첫(활성) 시트의 줄 목록 */
function lg_read_table($path) {
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') throw new LgReadError('빈 파일이에요. 은행에서 다시 내려받아 주세요.');
    if (str_starts_with($raw, 'PK')) return lg_xlsx_rows($raw);
    if (str_starts_with($raw, "\xD0\xCF\x11\xE0")) {
        try {
            return lg_xls_rows($raw);
        } catch (Throwable $e) {
            throw new LgReadError("이 파일은 암호가 걸려 있거나 읽을 수 없는 형식이에요. 엑셀에서 열어 암호를 풀고 "
                . "'다른 이름으로 저장 → Excel 통합 문서(*.xlsx)'로 저장한 뒤 다시 올려주세요.");
        }
    }
    $text = lg_decode($raw);
    if (str_contains(strtolower($text), '<table')) {
        $rows = lg_html_table($text);
        if ($rows) return $rows;
    }
    return lg_csv_rows($text);
}

/** utf-8(BOM 포함) → cp949 → euc-kr 순서로 풀기 */
function lg_decode($raw) {
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (mb_check_encoding($raw, 'UTF-8')) return $raw;
    foreach (['UHC', 'EUC-KR'] as $enc) {
        $s = @mb_convert_encoding($raw, 'UTF-8', $enc);
        if ($s !== false && mb_check_encoding($s, 'UTF-8')) return $s;
    }
    return mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
}

/* ================================================================ ZIP (xlsx 의 겉) */

/** ZIP 안의 파일들 [이름 => 내용] — 중앙 목록을 읽어 푼다(deflate/stored) */
function lg_unzip($bin) {
    $eocd = strrpos($bin, "\x50\x4b\x05\x06");
    if ($eocd === false) throw new LgReadError('엑셀 파일(zip)이 손상됐어요.');
    $e = unpack('vdisk/vcd_disk/vn_disk/vn/Vsize/Voffset', substr($bin, $eocd + 4, 16));
    $p = $e['offset'];
    $out = [];
    for ($i = 0; $i < $e['n']; $i++) {
        if (substr($bin, $p, 4) !== "\x50\x4b\x01\x02") break;
        $c = unpack('vmade/vneed/vflags/vmethod/Vtime/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Vlocal', substr($bin, $p + 4, 42));
        $name = substr($bin, $p + 46, $c['nlen']);
        $p += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
        $l = unpack('vnlen/velen', substr($bin, $c['local'] + 26, 4));
        $data = substr($bin, $c['local'] + 30 + $l['nlen'] + $l['elen'], $c['csize']);
        if ($c['method'] === 8) $data = @gzinflate($data);
        elseif ($c['method'] !== 0) $data = false;
        if ($data !== false) $out[$name] = $data;
    }
    return $out;
}

/* ================================================================ xlsx (파이썬 openpyxl 과 같은 값) */

/** 이름공간 없는 속성 읽기 (namespace 로 찾아 들어간 요소에서는 $el['r'] 가 비어 나온다) */
function lg_xa($el, $name) {
    return (string)($el->attributes()[$name] ?? '');
}

function lg_xml_load($s) {
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($x === false) throw new LgReadError('엑셀 내용을 읽지 못했어요.');
    return $x;
}

/** 날짜 서식인지 (openpyxl is_date_format 과 같은 규칙) */
function lg_is_date_format($fmt) {
    if ($fmt === null || $fmt === '' || strtolower($fmt) === 'general') return false;
    $f = preg_replace('/"[^"]*"|\\\\.|\[(?!(hh?|mm?|ss?)\])[^\]]*\]/i', '', $fmt);
    $f = explode(';', $f)[0];
    return (bool)preg_match('/[dmyhs]/i', $f);
}

/** 엑셀 일련번호 → 'YYYY-MM-DD HH:MM:SS' (1900 기준, 1900-02-29 버그 보정 · 1904 기준) */
function lg_excel_serial_to_iso($v, $date1904 = false) {
    $days = (int)floor($v);
    $frac = $v - $days;
    if ($date1904) {
        $ts = gmmktime(0, 0, 0, 1, 1, 1904) + $days * 86400;
    } else {
        // 1900 기준: 60 이상이면 1899-12-30 부터, 그 아래는 1899-12-31 부터 (가짜 1900-02-29 때문)
        $ts = $days >= 60 ? gmmktime(0, 0, 0, 12, 30, 1899) + $days * 86400 : gmmktime(0, 0, 0, 12, 31, 1899) + $days * 86400;
    }
    $secs = (int)round($frac * 86400);
    return gmdate('Y-m-d H:i:s', $ts + $secs);
}

/** 첫(활성) 시트 — 파이썬 load_workbook(...).active */
function lg_xlsx_rows($bin) {
    $book = lg_xlsx_book($bin, true);
    return $book[0][1];
}

/** 모든 일반 시트 [[이름, 줄 목록], ...] — 파이썬 wb.worksheets. $onlyActive 면 활성 시트 하나만 */
function lg_xlsx_book($bin, $onlyActive = false) {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $nsr = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $z = lg_unzip($bin);
    if (!isset($z['xl/workbook.xml'])) throw new LgReadError('엑셀 안에서 시트를 찾지 못했어요.');
    $main = lg_xml_load($z['xl/workbook.xml'])->children($ns);
    $date1904 = isset($main->workbookPr) && in_array(lg_xa($main->workbookPr, 'date1904'), ['1', 'true'], true);
    $active = isset($main->bookViews->workbookView) ? (int)lg_xa($main->bookViews->workbookView, 'activeTab') : 0;
    $targets = [];
    if (isset($z['xl/_rels/workbook.xml.rels'])) {
        foreach (lg_xml_load($z['xl/_rels/workbook.xml.rels'])->Relationship as $r) {
            if (str_ends_with((string)$r['Type'], '/worksheet')) $targets[(string)$r['Id']] = (string)$r['Target'];
        }
    }
    $sheets = [];      // 일반 시트만 (차트 시트는 뺀다)
    foreach ($main->sheets->sheet as $s) {
        $rid = (string)$s->attributes($nsr)['id'];
        if (!isset($targets[$rid])) continue;
        $t = $targets[$rid];
        $path = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        $sheets[] = [lg_xa($s, 'name'), preg_replace('#/[^/]+/\.\./#', '/', $path)];
    }
    if (!$sheets) throw new LgReadError('엑셀 안에서 시트를 찾지 못했어요.');
    if ($onlyActive) $sheets = [$sheets[$active] ?? $sheets[0]];

    // 공유 문자열 (글자 꾸밈이 있는 칸은 조각을 이어 붙이고, 읽는 법(rPh)은 뺀다)
    $shared = [];
    if (isset($z['xl/sharedStrings.xml'])) {
        foreach (lg_xml_load($z['xl/sharedStrings.xml'])->children($ns)->si as $si) {
            $c = $si->children($ns);
            if (isset($c->t)) { $shared[] = (string)$c->t; continue; }
            $t = '';
            foreach ($c->r as $r) $t .= (string)$r->children($ns)->t;
            $shared[] = $t;
        }
    }
    // 칸 서식 → 날짜인지
    $xfDate = [];
    if (isset($z['xl/styles.xml'])) {
        $st = lg_xml_load($z['xl/styles.xml'])->children($ns);
        $custom = [];
        if (isset($st->numFmts)) foreach ($st->numFmts->numFmt as $nf) $custom[(int)lg_xa($nf, 'numFmtId')] = lg_xa($nf, 'formatCode');
        if (isset($st->cellXfs)) {
            foreach ($st->cellXfs->xf as $xf) {
                $id = (int)lg_xa($xf, 'numFmtId');
                $xfDate[] = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || (isset($custom[$id]) && lg_is_date_format($custom[$id]));
            }
        }
    }
    $out = [];
    foreach ($sheets as [$title, $path]) {
        if (!isset($z[$path])) throw new LgReadError('엑셀 안에서 시트를 찾지 못했어요.');
        $out[] = [$title, lg_xlsx_sheet($z[$path], $shared, $xfDate, $date1904)];
    }
    return $out;
}

/** 시트 하나 → 1행·1열부터 (가장 큰 행·열까지 빈칸은 null 로 채움) — openpyxl iter_rows(values_only=True) */
function lg_xlsx_sheet($xml, array $shared, array $xfDate, $date1904) {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sheet = lg_xml_load($xml)->children($ns);
    $cells = [];
    $maxR = 0;
    $maxC = 0;
    $rowCounter = 0;
    foreach ($sheet->sheetData->row as $row) {
        $rn = lg_xa($row, 'r');
        $rowCounter = $rn !== '' ? (int)$rn : $rowCounter + 1;
        $next = 1;
        foreach ($row->c as $c) {
            $ref = lg_xa($c, 'r');
            if ($ref !== '' && preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                $col = 0;
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $r = (int)$m[2];
            } else {
                $col = $next;
                $r = $rowCounter;
            }
            $next = $col + 1;
            $t = lg_xa($c, 't');
            $s = (int)lg_xa($c, 's');
            $cc = $c->children($ns);
            $v = isset($cc->v) ? (string)$cc->v : null;
            if ($v === '') $v = null;                     // openpyxl: 빈 <v> 는 값 없음
            if ($t === 's') $val = $v === null ? null : ($shared[(int)$v] ?? null);
            elseif ($t === 'inlineStr') {
                $val = isset($cc->is) ? '' : null;
                if (isset($cc->is->t)) $val = (string)$cc->is->t;
                else foreach ($cc->is->r ?? [] as $rr) $val .= (string)$rr->t;
            } elseif ($t === 'str' || $t === 'e') $val = $v;
            elseif ($t === 'b') $val = $v === null ? null : $v === '1';
            elseif ($t === 'd') $val = $v === null ? null : new LgDate(str_replace('T', ' ', substr($v, 0, 19)) . (strlen($v) <= 10 ? ' 00:00:00' : ''));
            elseif ($v === null) $val = null;
            else {
                $val = (str_contains($v, '.') || stripos($v, 'e') !== false) ? (float)$v : (int)$v;
                if (!empty($xfDate[$s])) $val = new LgDate(lg_excel_serial_to_iso((float)$v, $date1904));
            }
            $cells[$r][$col] = $val;
            $maxR = max($maxR, $r);
            $maxC = max($maxC, $col);
        }
    }
    $rows = [];
    for ($r = 1; $r <= $maxR; $r++) {
        $line = [];
        for ($c = 1; $c <= $maxC; $c++) $line[] = $cells[$r][$c] ?? null;
        $rows[] = $line;
    }
    return $rows;
}

/* ================================================================ 옛날 xls (파이썬 xlrd 와 같은 값) */

/** OLE 복합 문서에서 'Workbook'(또는 'Book') 흐름 꺼내기 */
function lg_ole_stream($bin, array $names) {
    $h = unpack('vminor/vmajor/vorder/vssz/vmssz', substr($bin, 0x18, 10));
    $ss = 1 << $h['ssz'];
    $mss = 1 << $h['mssz'];
    $hd = unpack('Vndir/Vnfat/Vdir/Vtx/Vcutoff/Vminifat/Vnminifat/Vdifat/Vndifat', substr($bin, 0x28, 36));
    $sector = fn($i) => substr($bin, ($i + 1) * $ss, $ss);
    // FAT 자리 목록 (머리말 109개 + DIFAT 사슬)
    $fatSecs = array_values(unpack('V109', substr($bin, 0x4C, 436)));
    $d = $hd['difat'];
    for ($k = 0; $k < $hd['ndifat'] && $d < 0xFFFFFFFA; $k++) {
        $arr = array_values(unpack('V' . ($ss / 4), $sector($d)));
        $d = array_pop($arr);
        $fatSecs = array_merge($fatSecs, $arr);
    }
    $fat = [];
    foreach (array_slice($fatSecs, 0, $hd['nfat']) as $fs) {
        if ($fs >= 0xFFFFFFFA) continue;
        foreach (unpack('V' . ($ss / 4), $sector($fs)) as $x) $fat[] = $x;
    }
    $chain = function ($start, $fat, $get, $limit = 1 << 20) {
        $out = '';
        $seen = 0;
        for ($i = $start; $i < 0xFFFFFFFA && $seen < $limit; $i = $fat[$i] ?? 0xFFFFFFFE, $seen++) $out .= $get($i);
        return $out;
    };
    $dir = $chain($hd['dir'], $fat, $sector);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $e = substr($dir, $o, 128);
        $nlen = unpack('v', substr($e, 64, 2))[1];
        $name = $nlen > 2 ? mb_convert_encoding(substr($e, 0, $nlen - 2), 'UTF-8', 'UTF-16LE') : '';
        $f = unpack('Ctype', substr($e, 66, 1));
        $g = unpack('Vstart/Vsize', substr($e, 116, 8));
        $entries[] = ['name' => $name, 'type' => $f['type'], 'start' => $g['start'], 'size' => $g['size']];
    }
    $root = $entries[0] ?? null;
    foreach ($entries as $e) {
        if ($e['type'] !== 2 || !in_array($e['name'], $names, true)) continue;
        if ($e['size'] >= $hd['cutoff']) return substr($chain($e['start'], $fat, $sector), 0, $e['size']);
        // 작은 흐름: 뿌리 항목의 미니 흐름에서 미니 FAT 사슬로
        $mini = $chain($root['start'], $fat, $sector);
        $minifat = [];
        if ($hd['nminifat']) foreach (unpack('V*', $chain($hd['minifat'], $fat, $sector)) as $x) $minifat[] = $x;
        return substr($chain($e['start'], $minifat, fn($i) => substr($mini, $i * $mss, $mss)), 0, $e['size']);
    }
    throw new LgReadError('xls 안에서 통합 문서를 찾지 못했어요.');
}

/** BIFF8 글자: 압축(1바이트)이면 Latin-1, 아니면 UTF-16LE */
function lg_biff_chars($data, &$p, $cch, $high) {
    if ($high) {
        $s = mb_convert_encoding(substr($data, $p, $cch * 2), 'UTF-8', 'UTF-16LE');
        $p += $cch * 2;
    } else {
        $s = mb_convert_encoding(substr($data, $p, $cch), 'UTF-8', 'ISO-8859-1');
        $p += $cch;
    }
    return $s;
}

/** XLUnicodeString (글자 수 2바이트 또는 1바이트 + 꾸밈 표시) */
function lg_biff_ustr($data, &$p, $lenBytes = 2) {
    $cch = $lenBytes === 2 ? unpack('v', substr($data, $p, 2))[1] : ord($data[$p]);
    $p += $lenBytes;
    $flags = ord($data[$p++]);
    $runs = 0;
    $ext = 0;
    if ($flags & 0x08) { $runs = unpack('v', substr($data, $p, 2))[1]; $p += 2; }
    if ($flags & 0x04) { $ext = unpack('V', substr($data, $p, 4))[1]; $p += 4; }
    $s = lg_biff_chars($data, $p, $cch, $flags & 0x01);
    $p += $runs * 4 + $ext;
    return $s;
}

function lg_rk($rk) {
    if ($rk & 0x02) {
        $v = $rk >> 2;
        if ($v & 0x20000000) $v -= 0x40000000;       // 30비트 부호
        $v = (float)$v;
    } else {
        $v = unpack('d', pack('V', 0) . pack('V', $rk & 0xFFFFFFFC))[1];
    }
    return ($rk & 0x01) ? $v / 100 : $v;
}

function lg_xls_rows($bin) {
    $wbs = lg_ole_stream($bin, ['Workbook', 'Book']);
    $n = strlen($wbs);
    // 1) 레코드 나누기 (CONTINUE 는 앞 레코드에 붙이되, 공유 문자열(SST)은 경계를 기억한다)
    $recs = [];
    for ($p = 0; $p + 4 <= $n;) {
        $h = unpack('vtype/vlen', substr($wbs, $p, 4));
        $recs[] = [$h['type'], substr($wbs, $p + 4, $h['len']), $p];
        $p += 4 + $h['len'];
    }
    $sst = [];
    $formats = [];
    $xfFmt = [];
    $date1904 = false;
    $sheetPos = null;
    $count = count($recs);
    for ($i = 0; $i < $count; $i++) {
        [$type, $data, $pos] = $recs[$i];
        if ($type === 0x0085 && $sheetPos === null) {            // BOUNDSHEET: 첫 '일반 시트'
            $b = unpack('Vpos/Cvis/Ctype', substr($data, 0, 6));
            if ($b['type'] === 0) $sheetPos = $b['pos'];
        } elseif ($type === 0x0022) {
            $date1904 = unpack('v', $data)[1] === 1;
        } elseif ($type === 0x041E) {                             // FORMAT
            $q = 2;
            $formats[unpack('v', $data)[1]] = lg_biff_ustr($data, $q);
        } elseif ($type === 0x00E0) {                             // XF
            $xfFmt[] = unpack('v', substr($data, 2, 2))[1];
        } elseif ($type === 0x00FC) {                             // SST (+ CONTINUE)
            $parts = [$data];
            while ($i + 1 < $count && $recs[$i + 1][0] === 0x003C) $parts[] = $recs[++$i][1];
            $sst = lg_biff_sst($parts);
        }
    }
    if ($sheetPos === null) throw new LgReadError('xls 안에서 시트를 찾지 못했어요.');
    $isDate = function ($xf) use ($xfFmt, $formats) {
        $f = $xfFmt[$xf] ?? 0;
        if (($f >= 14 && $f <= 22) || ($f >= 27 && $f <= 36) || ($f >= 45 && $f <= 47) || ($f >= 50 && $f <= 58)) return true;
        return isset($formats[$f]) && lg_is_date_format($formats[$f]);
    };
    $num = fn($v, $xf) => $isDate($xf) ? new LgDate(lg_excel_serial_to_iso($v, $date1904)) : $v;
    // 2) 시트의 칸들
    $cells = [];
    $maxR = -1;
    $maxC = -1;
    $set = function ($r, $c, $v) use (&$cells, &$maxR, &$maxC) { $cells[$r][$c] = $v; $maxR = max($maxR, $r); $maxC = max($maxC, $c); };
    $start = null;
    foreach ($recs as $k => $rec) { if ($rec[2] === $sheetPos) { $start = $k; break; } }
    if ($start === null) throw new LgReadError('xls 시트 위치가 맞지 않아요.');
    $pendingFormula = null;
    for ($i = $start + 1; $i < $count; $i++) {
        [$type, $d] = $recs[$i];
        if ($type === 0x000A) break;                              // EOF
        if ($type === 0x00FD) { $c = unpack('vr/vc/vxf/Vi', $d); $set($c['r'], $c['c'], $sst[$c['i']] ?? ''); }
        elseif ($type === 0x0203) { $c = unpack('vr/vc/vxf', $d); $set($c['r'], $c['c'], $num(unpack('d', substr($d, 6, 8))[1], $c['xf'])); }
        elseif ($type === 0x027E) { $c = unpack('vr/vc/vxf/Vrk', $d); $set($c['r'], $c['c'], $num(lg_rk($c['rk']), $c['xf'])); }
        elseif ($type === 0x00BD) {                               // MULRK
            $r = unpack('v', $d)[1];
            $c0 = unpack('v', substr($d, 2, 2))[1];
            $k = (strlen($d) - 6) / 6;
            for ($j = 0; $j < $k; $j++) {
                $x = unpack('vxf/Vrk', substr($d, 4 + $j * 6, 6));
                $set($r, $c0 + $j, $num(lg_rk($x['rk']), $x['xf']));
            }
        } elseif ($type === 0x0204) { $c = unpack('vr/vc/vxf', $d); $q = 6; $set($c['r'], $c['c'], lg_biff_ustr($d, $q)); }
        elseif ($type === 0x0205) {                               // BOOLERR
            $c = unpack('vr/vc/vxf/Cv/Ce', $d);
            $set($c['r'], $c['c'], $c['v']);
        } elseif ($type === 0x0006) {                             // FORMULA — 계산해 둔 값
            $c = unpack('vr/vc/vxf', $d);
            $res = substr($d, 6, 8);
            if (substr($res, 6, 2) === "\xFF\xFF") {
                $kind = ord($res[0]);
                if ($kind === 0) $pendingFormula = [$c['r'], $c['c']];          // 글자: 뒤따르는 STRING 레코드
                elseif ($kind === 1 || $kind === 2) $set($c['r'], $c['c'], ord($res[2]));
                else $set($c['r'], $c['c'], '');
            } else {
                $set($c['r'], $c['c'], $num(unpack('d', $res)[1], $c['xf']));
            }
        } elseif ($type === 0x0207 && $pendingFormula) {          // STRING
            $q = 0;
            $set($pendingFormula[0], $pendingFormula[1], lg_biff_ustr($d, $q));
            $pendingFormula = null;
        }
    }
    $rows = [];
    for ($r = 0; $r <= $maxR; $r++) {
        $line = [];
        for ($c = 0; $c <= $maxC; $c++) $line[] = $cells[$r][$c] ?? '';
        $rows[] = $line;
    }
    return $rows;
}

/** 공유 문자열 표 — 글자 하나가 CONTINUE 경계에서 잘리면, 다음 조각 첫 바이트가 새 압축 표시다 */
function lg_biff_sst(array $parts) {
    $out = [];
    $total = unpack('Vtotal/Vuniq', $parts[0])['uniq'];
    $pi = 0;
    $data = $parts[0];
    $p = 8;
    $next = function () use (&$pi, &$data, &$p, $parts) {
        $pi++;
        if (!isset($parts[$pi])) return false;
        $data = $parts[$pi];
        $p = 0;
        return true;
    };
    for ($k = 0; $k < $total; $k++) {
        if ($p >= strlen($data) && !$next()) break;
        $cch = unpack('v', substr($data, $p, 2))[1];
        $p += 2;
        $flags = ord($data[$p++]);
        $runs = 0;
        $ext = 0;
        if ($flags & 0x08) { $runs = unpack('v', substr($data, $p, 2))[1]; $p += 2; }
        if ($flags & 0x04) { $ext = unpack('V', substr($data, $p, 4))[1]; $p += 4; }
        $high = $flags & 0x01;
        $s = '';
        $left = $cch;
        while ($left > 0) {
            $avail = intdiv(strlen($data) - $p, $high ? 2 : 1);
            $take = min($left, $avail);
            $s .= lg_biff_chars($data, $p, $take, $high);
            $left -= $take;
            if ($left > 0) {
                if (!$next()) break;
                $high = ord($data[$p++]) & 0x01;
            }
        }
        // 꾸밈(runs)·확장 정보 건너뛰기 — 이것도 경계를 넘을 수 있다
        $skip = $runs * 4 + $ext;
        while ($skip > 0) {
            $here = min($skip, strlen($data) - $p);
            $p += $here;
            $skip -= $here;
            if ($skip > 0 && !$next()) break;
        }
        $out[] = $s;
    }
    return $out;
}

/* ================================================================ 웹페이지형 xls (파이썬 HTMLParser 와 같은 규칙) */

function lg_html_table($text) {
    $rows = [];
    $row = null;
    $cell = null;
    preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>|<!--.*?-->|([^<]+)|</s', $text, $m, PREG_SET_ORDER);
    $skip = false;
    foreach ($m as $t) {
        if (isset($t[3]) && $t[3] !== '') {
            if ($cell !== null && !$skip) $cell[] = html_entity_decode($t[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            continue;
        }
        if (!isset($t[2]) || $t[2] === '') continue;
        $tag = strtolower($t[2]);
        $close = $t[1] === '/';
        if ($tag === 'script' || $tag === 'style') { $skip = !$close; continue; }
        if (!$close) {
            if ($tag === 'tr') $row = [];
            elseif (($tag === 'td' || $tag === 'th') && $row !== null) $cell = [];
            elseif ($tag === 'br' && $cell !== null) $cell[] = ' ';
        } else {
            if (($tag === 'td' || $tag === 'th') && $cell !== null) {
                $row[] = implode(' ', preg_split('/\s+/u', trim(implode('', $cell)), -1, PREG_SPLIT_NO_EMPTY));
                $cell = null;
            } elseif ($tag === 'tr' && $row !== null) {
                $rows[] = $row;
                $row = null;
            }
        }
    }
    return $rows;
}

/* ================================================================ CSV */

function lg_csv_rows($text) {
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) $rows[] = $r === [null] ? [] : $r;
    fclose($fh);
    return $rows;
}

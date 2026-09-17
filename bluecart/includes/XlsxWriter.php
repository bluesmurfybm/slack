<?php
declare(strict_types=1);

/**
 * 최소 기능 XLSX 작성기.
 *
 * PhpSpreadsheet 를 쓰지 않는 이유는 iworks 에 composer 를 들이지 않기
 * 위해서입니다. ZipArchive 확장에도 의존하지 않고 ZIP 컨테이너를 직접
 * 씁니다(zlib 은 PHP 에 기본 내장). 서버에 php-zip 이 없어도 동작합니다.
 *
 * 지원하는 것: 여러 시트, 굵은 머리글, 열 너비, 숫자/문자 구분, 틀 고정,
 * 자동 필터. 수식·차트·서식은 지원하지 않습니다.
 *
 * 사용:
 *   $x = new XlsxWriter();
 *   $x->addSheet('요청 목록', ['번호','물품'], [['2026-0001','원두']], [14, 30]);
 *   $x->download('목록.xlsx');
 */
final class XlsxWriter
{
    /** @var array<int,array{name:string,head:string[],rows:array[],widths:int[]}> */
    private array $sheets = [];

    /**
     * @param string     $name   시트 이름
     * @param string[]   $head   머리글
     * @param array[]    $rows   각 행은 값의 배열. 숫자는 숫자로, 나머지는 문자로 기록
     * @param int[]      $widths 열 너비(문자 수 기준). 생략 가능
     */
    public function addSheet(string $name, array $head, array $rows, array $widths = []): void
    {
        $this->sheets[] = [
            'name'   => $this->safeSheetName($name),
            'head'   => $head,
            'rows'   => $rows,
            'widths' => $widths,
        ];
    }

    public function build(): string
    {
        $files = [];

        $files['[Content_Types].xml'] = $this->contentTypes();
        $files['_rels/.rels']         = $this->rootRels();
        $files['xl/workbook.xml']     = $this->workbook();
        $files['xl/_rels/workbook.xml.rels'] = $this->workbookRels();
        $files['xl/styles.xml']       = $this->styles();

        foreach ($this->sheets as $i => $sheet) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheetXml($sheet);
        }

        return $this->zip($files);
    }

    public function download(string $filename): never
    {
        $data = $this->build();

        // 한글 파일명: 구형 브라우저용 ASCII 이름과 RFC 5987 이름을 함께 보낸다.
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $ascii . '"; '
             . "filename*=UTF-8''" . rawurlencode($filename));
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo $data;
        exit;
    }

    // -----------------------------------------------------------------
    // 시트 XML
    // -----------------------------------------------------------------

    private function sheetXml(array $sheet): string
    {
        $colCount = max(count($sheet['head']), 1);

        $cols = '';
        if ($sheet['widths']) {
            $cols = '<cols>';
            foreach ($sheet['widths'] as $i => $w) {
                $cols .= sprintf('<col min="%d" max="%d" width="%d" customWidth="1"/>',
                                 $i + 1, $i + 1, max(4, (int)$w));
            }
            $cols .= '</cols>';
        }

        $xml = '<row r="1">';
        foreach ($sheet['head'] as $c => $v) {
            $xml .= $this->cell($this->colName($c) . '1', $v, 1);
        }
        $xml .= '</row>';

        $r = 1;
        foreach ($sheet['rows'] as $row) {
            $r++;
            $xml .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($row as $v) {
                $xml .= $this->cell($this->colName($c) . $r, $v, 0);
                $c++;
            }
            $xml .= '</row>';
        }

        $lastCol = $this->colName($colCount - 1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $xml . '</sheetData>'
            . '<autoFilter ref="A1:' . $lastCol . max(1, $r) . '"/>'
            . '</worksheet>';
    }

    /** 숫자는 숫자 셀로, 그 밖에는 inlineStr 로 기록한다. */
    private function cell(string $ref, mixed $value, int $styleId): string
    {
        $s = $styleId ? ' s="' . $styleId . '"' : '';

        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }

        // "0123" 같은 값이 숫자로 바뀌면 안 되므로 앞자리 0 은 문자 취급
        $isNumber = is_int($value) || is_float($value)
            || (is_string($value) && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $value) === 1);

        if ($isNumber) {
            return '<c r="' . $ref . '"' . $s . '><v>' . $value . '</v></c>';
        }

        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
             . $this->xmlEscape((string)$value) . '</t></is></c>';
    }

    private function colName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $rem   = ($index - 1) % 26;
            $name  = chr(65 + $rem) . $name;
            $index = intdiv($index - 1, 26);
        }
        return $name;
    }

    private function xmlEscape(string $s): string
    {
        // XML 1.0 에서 허용하지 않는 제어문자는 제거해야 파일이 깨지지 않는다.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function safeSheetName(string $name): string
    {
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);
        $name = trim(mb_substr($name, 0, 31));
        return $name === '' ? 'Sheet' : $name;
    }

    // -----------------------------------------------------------------
    // 패키지 뼈대
    // -----------------------------------------------------------------

    private function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml" ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($this->sheets as $i => $_) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                  . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $xml . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Target="xl/workbook.xml"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                               $this->xmlEscape($sheet['name']), $i + 1, $i + 1);
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        $n = count($this->sheets);
        foreach ($this->sheets as $i => $_) {
            $rels .= sprintf('<Relationship Id="rId%d" Target="worksheets/sheet%d.xml"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>',
                $i + 1, $i + 1);
        }
        $rels .= sprintf('<Relationship Id="rId%d" Target="styles.xml"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"/>', $n + 1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    /** 스타일은 두 개만 쓴다: 0 = 기본, 1 = 굵은 머리글(회색 배경). */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="맑은 고딕"/></font>'
            . '<font><b/><sz val="11"/><name val="맑은 고딕"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // -----------------------------------------------------------------
    // ZIP 컨테이너 (ZipArchive 없이 직접 작성)
    // -----------------------------------------------------------------

    /** @param array<string,string> $files 경로 => 내용 */
    private function zip(array $files): string
    {
        $local   = '';
        $central = '';
        $offset  = 0;
        $count   = 0;

        // ZIP 타임스탬프는 DOS 형식(1980 기준)
        $t     = getdate();
        $mtime = (($t['year'] - 1980) << 25) | ($t['mon'] << 21) | ($t['mday'] << 16)
               | ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);

        foreach ($files as $name => $content) {
            $crc      = crc32($content);
            $uncomp   = strlen($content);
            $deflated = gzdeflate($content, 6);

            // 압축이 오히려 커지는 짧은 파일은 그대로 저장한다.
            if ($deflated === false || strlen($deflated) >= $uncomp) {
                $data   = $content;
                $method = 0; // stored
                $comp   = $uncomp;
            } else {
                $data   = $deflated;
                $method = 8; // deflate
                $comp   = strlen($deflated);
            }

            $nameBytes = $name;

            $localHeader = "\x50\x4b\x03\x04"
                . pack('v', 20)          // version needed
                . pack('v', 0)           // flags
                . pack('v', $method)
                . pack('V', $mtime)
                . pack('V', $crc)
                . pack('V', $comp)
                . pack('V', $uncomp)
                . pack('v', strlen($nameBytes))
                . pack('v', 0)           // extra length
                . $nameBytes;

            $local .= $localHeader . $data;

            $central .= "\x50\x4b\x01\x02"
                . pack('v', 20)          // version made by
                . pack('v', 20)          // version needed
                . pack('v', 0)
                . pack('v', $method)
                . pack('V', $mtime)
                . pack('V', $crc)
                . pack('V', $comp)
                . pack('V', $uncomp)
                . pack('v', strlen($nameBytes))
                . pack('v', 0)           // extra
                . pack('v', 0)           // comment
                . pack('v', 0)           // disk number
                . pack('v', 0)           // internal attrs
                . pack('V', 32)          // external attrs
                . pack('V', $offset)
                . $nameBytes;

            $offset += strlen($localHeader) + strlen($data);
            $count++;
        }

        $eocd = "\x50\x4b\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', $count) . pack('v', $count)
            . pack('V', strlen($central))
            . pack('V', $offset)
            . pack('v', 0);

        return $local . $central . $eocd;
    }
}

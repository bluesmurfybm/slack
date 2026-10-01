<?php
/** xlsx·pptx·docx 를 ZIP+XML 로 직접 읽는 파서. pdf 는 외부 도구가 있을 때만. */

declare(strict_types=1);

require_once __DIR__ . '/DocumentParser.php';

/**
 * Office 문서 파서 — 외부 라이브러리 없음.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 PhpSpreadsheet 를 쓰지 않았나                                  │
 * │                                                                  │
 * │ 이 포털은 지금 **실행 시점 Composer 의존성이 하나도 없다**        │
 * │ (composer.json 의 require 는 php 버전뿐이고 vendor/ 는 .gitignore │
 * │ 에 있다). 하나를 들이면 배포 절차가 포털 전체 단위로 바뀐다.      │
 * │                                                                  │
 * │ 그런데 우리가 필요한 것은 "셀 글자와 그 주소" 뿐이다.             │
 * │ xlsx·pptx·docx 는 전부 ZIP + XML 이고, PHP 에 zip·XMLReader 가    │
 * │ 이미 있다(확인함). 그래서 필요한 만큼만 직접 읽는다.              │
 * │                                                                  │
 * │ 나중에 수식 계산·서식·차트까지 필요해지면 PhpSpreadsheet 로       │
 * │ 갈아끼우면 된다 — DocumentParser 인터페이스만 맞추면 부르는       │
 * │ 쪽은 그대로다.                                                    │
 * │                                                                  │
 * │ 2026-09-30: 명세 담당자에게 물어 **이대로 가기로 확정**했다.      │
 * │ 다시 논의하려면 위의 배포 절차 문제부터 풀어야 한다.              │
 * └──────────────────────────────────────────────────────────────────┘
 */
final class OfficeDocumentParser implements DocumentParser
{
    /** 한 문서에서 뽑아낼 글자 수 상한. 넘으면 자르고 사람에게 알린다. */
    public const MAX_CHARS = 400000;

    /** 압축을 풀었을 때의 상한. 압축 폭탄 방어. */
    private const MAX_UNCOMPRESSED = 200 * 1024 * 1024;

    /** 한 블록(시트·슬라이드) 상한. 한 시트가 전부를 먹지 않게. */
    private const MAX_BLOCK_CHARS = 120000;

    public function __construct(
        /** pdftotext 실행 파일 경로. 없으면 pdf 는 실패로 기록된다. */
        private ?string $pdfToText = null,
    ) {}

    public function supports(string $kind): bool
    {
        return in_array($kind, ['xlsx', 'pptx', 'docx', 'pdf'], true);
    }

    public function parse(string $filePath, string $kind): ParsedDoc
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new DocumentParseError('파일을 찾을 수 없거나 읽을 수 없습니다.');
        }
        return match ($kind) {
            'xlsx' => $this->parseXlsx($filePath),
            'pptx' => $this->parsePptx($filePath),
            'docx' => $this->parseDocx($filePath),
            'pdf'  => $this->parsePdf($filePath),
            default => throw new DocumentParseError("다룰 수 없는 형식입니다: $kind"),
        };
    }

    // =================================================================
    // xlsx
    // =================================================================

    /**
     * 시트마다 한 블록. ref 는 `시트명!A1:D25` — 실제로 글자가 있는 범위다.
     * 빈 시트는 건너뛴다(빈 블록을 LLM 에 넘겨 봐야 토큰만 쓴다).
     */
    private function parseXlsx(string $path): ParsedDoc
    {
        $zip    = $this->openZip($path);
        $notes  = [];
        $blocks = [];

        try {
            $shared = $this->xlsxSharedStrings($zip);
            $sheets = $this->xlsxSheetList($zip);

            if (!$sheets) {
                throw new DocumentParseError('시트를 찾지 못했습니다. xlsx 가 맞습니까?');
            }

            $total = 0;
            foreach ($sheets as $sheet) {
                if ($total >= self::MAX_CHARS) {
                    $notes[] = '글자 수 상한을 넘어 이후 시트를 읽지 않았습니다: ' . $sheet['name'];
                    continue;
                }
                $r = $this->xlsxSheetText($zip, $sheet['path'], $shared);
                if ($r['text'] === '') {
                    continue;
                }
                $total += mb_strlen($r['text']);
                if ($r['truncated']) {
                    $notes[] = '시트가 커서 일부만 읽었습니다: ' . $sheet['name'];
                }
                $blocks[] = new ParsedBlock(
                    $sheet['name'] . '!' . $r['range'],
                    $r['text']
                );
            }
        } finally {
            $zip->close();
        }

        if (!$blocks) {
            throw new DocumentParseError('읽을 수 있는 글자가 없습니다. 이미지로만 된 문서일 수 있습니다.');
        }
        return new ParsedDoc($blocks, $notes);
    }

    /** @return string[] 인덱스 → 문자열 */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $this->zipRead($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $out = [];
        $rd  = $this->reader($xml);
        $buf = null;
        while ($rd->read()) {
            if ($rd->nodeType === XMLReader::ELEMENT && $rd->localName === 'si') {
                $buf = '';
            } elseif ($rd->nodeType === XMLReader::ELEMENT && $rd->localName === 't' && $buf !== null) {
                $buf .= $rd->readString();
            } elseif ($rd->nodeType === XMLReader::END_ELEMENT && $rd->localName === 'si') {
                $out[] = $buf ?? '';
                $buf = null;
            }
        }
        $rd->close();
        return $out;
    }

    /** @return array<array{name:string,path:string}> 워크북에 적힌 순서대로 */
    private function xlsxSheetList(ZipArchive $zip): array
    {
        $wb = $this->zipRead($zip, 'xl/workbook.xml');
        if ($wb === null) {
            return [];
        }
        // rId → 실제 파일 경로
        $rels = [];
        $rx = $this->zipRead($zip, 'xl/_rels/workbook.xml.rels');
        if ($rx !== null) {
            $sx = $this->simple($rx);
            foreach ($sx->Relationship ?? [] as $rel) {
                $t = (string)$rel['Target'];
                $rels[(string)$rel['Id']] = str_starts_with($t, '/')
                    ? ltrim($t, '/')
                    : 'xl/' . ltrim($t, './');
            }
        }

        $sx  = $this->simple($wb);
        $ns  = $sx->getNamespaces(true);
        $out = [];
        foreach ($sx->sheets->sheet ?? [] as $i => $sheet) {
            $attrs = $sheet->attributes($ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid   = (string)($attrs['id'] ?? '');
            $path  = $rels[$rid] ?? ('xl/worksheets/sheet' . ($i + 1) . '.xml');
            $out[] = ['name' => (string)$sheet['name'], 'path' => $path];
        }
        return $out;
    }

    /**
     * 시트 하나를 줄글로.
     *
     * 행마다 한 줄, 칸은 탭으로 나눈다 — WBS 붙여넣기 규칙과 같은 모양이라
     * 사람이 보기에도 일관된다.
     *
     * **중간에 빈 칸이 있어도 탭을 넣어 자리를 지킨다.** 처음에는 빈 칸을
     * 그냥 건너뛰었는데, 그러면 `비고 | (빈칸) | 5` 가 `비고 | 5` 가 되어
     * 5 가 공수인지 난이도인지 알 수 없게 된다. 머리글과 값이 세로로
     * 맞아야 표로 읽힌다. 오른쪽 끝의 빈 칸은 넣지 않는다.
     *
     * @return array{text:string,range:string,truncated:bool}
     */
    private function xlsxSheetText(ZipArchive $zip, string $path, array $shared): array
    {
        $xml = $this->zipRead($zip, $path);
        if ($xml === null) {
            return ['text' => '', 'range' => '', 'truncated' => false];
        }

        $rd = $this->reader($xml);
        $lines = [];
        $cells = [];          // 현재 행의 [열번호 => 글자]
        $rowNo = 0;
        $minC = PHP_INT_MAX; $maxC = 0; $minR = PHP_INT_MAX; $maxR = 0;
        $chars = 0; $truncated = false;

        $flush = function () use (&$cells, &$lines, &$chars) {
            if (!$cells) {
                return;
            }
            ksort($cells);
            // A열부터 촘촘하게 채운다. 빈 칸은 빈 문자열로 자리만 지킨다.
            $dense = [];
            $last  = (int)array_key_last($cells);
            for ($c = 1; $c <= $last; $c++) {
                $dense[] = $cells[$c] ?? '';
            }
            $line = implode("\t", $dense);
            if (trim($line) !== '') {
                $lines[] = $line;
                $chars  += mb_strlen($line) + 1;
            }
            $cells = [];
        };

        while ($rd->read()) {
            if ($rd->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($rd->localName === 'row') {
                $flush();
                $rowNo = (int)($rd->getAttribute('r') ?: ($rowNo + 1));
                continue;
            }
            if ($rd->localName !== 'c') {
                continue;
            }

            $ref  = (string)$rd->getAttribute('r');
            $type = (string)$rd->getAttribute('t');
            $node = $rd->readOuterXml();
            $val  = $this->xlsxCellValue($node, $type, $shared);
            if ($val === '') {
                continue;
            }

            [$col, $row] = $this->refToColRow($ref, $rowNo);
            $cells[$col] = $val;
            $minC = min($minC, $col); $maxC = max($maxC, $col);
            $minR = min($minR, $row); $maxR = max($maxR, $row);

            if ($chars > self::MAX_BLOCK_CHARS) {
                $truncated = true;
                break;
            }
        }
        $flush();
        $rd->close();

        $range = $maxR > 0
            ? $this->colName($minC) . $minR . ':' . $this->colName($maxC) . $maxR
            : '';

        return ['text' => implode("\n", $lines), 'range' => $range, 'truncated' => $truncated];
    }

    private function xlsxCellValue(string $cellXml, string $type, array $shared): string
    {
        if ($type === 'inlineStr') {
            if (preg_match_all('#<(?:\w+:)?t[^>]*>(.*?)</(?:\w+:)?t>#s', $cellXml, $m)) {
                return $this->unxml(implode('', $m[1]));
            }
            return '';
        }
        if (!preg_match('#<(?:\w+:)?v[^>]*>(.*?)</(?:\w+:)?v>#s', $cellXml, $m)) {
            return '';
        }
        $v = $this->unxml($m[1]);
        if ($type === 's') {
            $i = (int)$v;
            return $shared[$i] ?? '';
        }
        // t="b" 는 불리언, 빈 t 는 숫자, t="str" 은 수식 결과 문자열.
        // 날짜는 일련번호로 들어오는데, 서식을 봐야 날짜인지 알 수 있다.
        // 여기서는 원본 숫자를 그대로 둔다 — 잘못 바꾸느니 그대로가 낫다.
        if ($type === 'b') {
            return $v === '1' ? 'TRUE' : 'FALSE';
        }
        return $v;
    }

    /** "B7" → [2, 7]. 주소가 없으면 현재 행을 쓰고 열은 순서대로 매긴다. */
    private function refToColRow(string $ref, int $rowFallback): array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
            return [0, $rowFallback];
        }
        $col = 0;
        foreach (str_split(strtoupper($m[1])) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return [$col, (int)$m[2]];
    }

    private function colName(int $col): string
    {
        if ($col < 1) {
            return 'A';
        }
        $s = '';
        while ($col > 0) {
            $r = ($col - 1) % 26;
            $s = chr(65 + $r) . $s;
            $col = intdiv($col - 1, 26);
        }
        return $s;
    }

    // =================================================================
    // pptx
    // =================================================================

    /** 슬라이드마다 한 블록. ref 는 `슬라이드 3`. 발표자 노트는 읽지 않는다. */
    private function parsePptx(string $path): ParsedDoc
    {
        $zip    = $this->openZip($path);
        $blocks = [];
        $notes  = [];

        try {
            // slide1.xml, slide2.xml ... 을 번호순으로. 파일 순서를 믿지 않는다.
            $slides = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', (string)$name, $m)) {
                    $slides[(int)$m[1]] = $name;
                }
            }
            if (!$slides) {
                throw new DocumentParseError('슬라이드를 찾지 못했습니다. pptx 가 맞습니까?');
            }
            ksort($slides);

            $total = 0;
            foreach ($slides as $no => $name) {
                if ($total >= self::MAX_CHARS) {
                    $notes[] = '글자 수 상한을 넘어 ' . $no . '번 슬라이드부터 읽지 않았습니다.';
                    break;
                }
                $xml = $this->zipRead($zip, $name);
                if ($xml === null) {
                    continue;
                }
                $text = $this->pptxSlideText($xml);
                if (trim($text) === '') {
                    continue;
                }
                $total += mb_strlen($text);
                $blocks[] = new ParsedBlock('슬라이드 ' . $no, $text);
            }
        } finally {
            $zip->close();
        }

        if (!$blocks) {
            throw new DocumentParseError('읽을 수 있는 글자가 없습니다. 이미지로만 된 슬라이드일 수 있습니다.');
        }
        return new ParsedDoc($blocks, $notes);
    }

    /**
     * 한 슬라이드의 글자.
     *
     * <a:p> 가 한 문단, <a:t> 가 글자 조각이다. 조각은 서식 때문에 잘려
     * 있으므로 문단 안에서는 붙이고 문단 사이만 줄을 바꾼다. 조각마다
     * 줄을 바꾸면 한 문장이 여러 줄로 흩어진다.
     */
    private function pptxSlideText(string $xml): string
    {
        $rd    = $this->reader($xml);
        $lines = [];
        $buf   = null;
        while ($rd->read()) {
            if ($rd->nodeType === XMLReader::ELEMENT && $rd->localName === 'p') {
                $buf = '';
            } elseif ($rd->nodeType === XMLReader::ELEMENT && $rd->localName === 't' && $buf !== null) {
                $buf .= $rd->readString();
            } elseif ($rd->nodeType === XMLReader::END_ELEMENT && $rd->localName === 'p') {
                if ($buf !== null && trim($buf) !== '') {
                    $lines[] = trim($buf);
                }
                $buf = null;
            }
        }
        $rd->close();
        return implode("\n", $lines);
    }

    // =================================================================
    // docx
    // =================================================================

    /**
     * 본문 전체를 한 블록으로 두지 않고 문단 묶음으로 자른다.
     * 그래야 초안 항목이 "몇 문단쯤" 이라도 가리킬 수 있다.
     */
    private function parseDocx(string $path): ParsedDoc
    {
        $zip = $this->openZip($path);
        try {
            $xml = $this->zipRead($zip, 'word/document.xml');
            if ($xml === null) {
                throw new DocumentParseError('본문을 찾지 못했습니다. docx 가 맞습니까?');
            }
            $paras = $this->docxParagraphs($xml);
        } finally {
            $zip->close();
        }

        if (!$paras) {
            throw new DocumentParseError('읽을 수 있는 글자가 없습니다.');
        }

        $blocks = [];
        $notes  = [];
        $chunk  = 80;                 // 문단 80개씩 한 블록
        $total  = 0;
        for ($i = 0; $i < count($paras); $i += $chunk) {
            if ($total >= self::MAX_CHARS) {
                $notes[] = '글자 수 상한을 넘어 뒷부분을 읽지 않았습니다.';
                break;
            }
            $slice = array_slice($paras, $i, $chunk);
            $text  = implode("\n", $slice);
            $total += mb_strlen($text);
            $blocks[] = new ParsedBlock(
                ($i + 1) . '~' . min($i + $chunk, count($paras)) . '문단',
                $text
            );
        }
        return new ParsedDoc($blocks, $notes);
    }

    /**
     * 문단과 표를 줄글로.
     *
     * 표는 한 행을 한 줄로 모으고 칸을 탭으로 나눈다. 칸마다 줄을 바꾸면
     * 표가 세로 목록으로 풀려서 어느 값이 어느 머리글 것인지 사라진다.
     *
     * 주의: `<w:p/>` 처럼 스스로 닫는 요소는 XMLReader 가 END_ELEMENT 를
     * **주지 않는다.** 처음에 그걸 놓쳐서 빈 문단 하나 뒤의 본문이 통째로
     * 사라졌다(시험 문서의 절반이 안 나왔다). isEmptyElement 를 꼭 볼 것.
     *
     * @return string[] 빈 문단은 버린다
     */
    private function docxParagraphs(string $xml): array
    {
        $rd  = $this->reader($xml);
        $out = [];

        $buf      = '';      // 지금 문단
        $cellBuf  = [];      // 지금 표 칸 안의 문단들
        $rowCells = [];      // 지금 표 행의 칸들
        $inCell   = false;
        $inRow    = false;

        $endPara = function () use (&$buf, &$inCell, &$cellBuf, &$out): void {
            $t = trim($buf);
            $buf = '';
            if ($t === '') {
                return;
            }
            if ($inCell) {
                $cellBuf[] = $t;
            } else {
                $out[] = $t;
            }
        };

        while ($rd->read()) {
            $empty = $rd->isEmptyElement;

            if ($rd->nodeType === XMLReader::ELEMENT) {
                switch ($rd->localName) {
                    case 'tr':
                        if (!$empty) { $inRow = true; $rowCells = []; }
                        break;
                    case 'tc':
                        if (!$empty) { $inCell = true; $cellBuf = []; }
                        break;
                    case 'p':
                        if ($empty) { $buf = ''; }   // 빈 문단 — 버린다
                        break;
                    case 't':
                        if (!$empty) { $buf .= $rd->readString(); }
                        break;
                    case 'tab':
                        $buf .= "\t";
                        break;
                    case 'br':
                    case 'cr':
                        $buf .= "\n";
                        break;
                }
                continue;
            }

            if ($rd->nodeType !== XMLReader::END_ELEMENT) {
                continue;
            }
            switch ($rd->localName) {
                case 'p':
                    $endPara();
                    break;
                case 'tc':
                    $rowCells[] = implode(' ', $cellBuf);
                    $cellBuf = [];
                    $inCell  = false;
                    break;
                case 'tr':
                    $line = implode("\t", $rowCells);
                    if (trim($line) !== '') {
                        $out[] = $line;
                    }
                    $rowCells = [];
                    $inRow    = false;
                    break;
            }
        }
        $rd->close();
        unset($inRow);   // 표 바깥 문단과 구분하기 위해 들고만 있었다
        return $out;
    }

    // =================================================================
    // pdf
    // =================================================================

    /**
     * PDF 는 외부 도구에 맡긴다.
     *
     * PDF 의 글자는 글꼴 안에 좌표로 흩어져 있어서, 직접 읽으면 줄 순서와
     * 띄어쓰기가 원문과 달라지는 일이 잦다. 그렇게 흐트러진 글을 LLM 에
     * 넘기면 엉뚱한 태스크가 나온다 — 못 읽었다고 말하는 편이 낫다.
     *
     * pdftotext(poppler) 경로가 설정돼 있으면 쓰고, 없으면 실패로 기록한다.
     */
    private function parsePdf(string $path): ParsedDoc
    {
        if ($this->pdfToText === null || !is_file($this->pdfToText)) {
            throw new DocumentParseError(
                'PDF 를 읽을 도구가 설정돼 있지 않습니다. '
                . 'pdftotext(poppler) 를 설치하고 BS_PDFTOTEXT 를 지정하거나, '
                . '원본 문서(xlsx·pptx·docx)를 올려 주세요.'
            );
        }

        $out = tempnam(sys_get_temp_dir(), 'bs_pdf');
        if ($out === false) {
            throw new DocumentParseError('임시 파일을 만들지 못했습니다.');
        }
        try {
            // -layout 으로 표 모양을 최대한 지킨다. 인자는 전부 이스케이프한다.
            $cmd = escapeshellarg($this->pdfToText) . ' -layout -enc UTF-8 '
                 . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>&1';
            exec($cmd, $lines, $code);
            if ($code !== 0) {
                throw new DocumentParseError(
                    'pdftotext 가 실패했습니다(' . $code . '): ' . mb_substr(implode(' ', $lines), 0, 200));
            }
            $text = (string)file_get_contents($out);
        } finally {
            @unlink($out);
        }

        $text = $this->clean($text);
        if (trim($text) === '') {
            throw new DocumentParseError(
                '읽을 수 있는 글자가 없습니다. 스캔 이미지로 된 PDF 일 수 있습니다.');
        }

        // \f 가 쪽 구분자다. 쪽마다 블록으로 나눠 ref 를 남긴다.
        $pages  = explode("\f", $text);
        $blocks = [];
        $notes  = [];
        $total  = 0;
        foreach ($pages as $i => $p) {
            if (trim($p) === '') {
                continue;
            }
            if ($total >= self::MAX_CHARS) {
                $notes[] = '글자 수 상한을 넘어 ' . ($i + 1) . '쪽부터 읽지 않았습니다.';
                break;
            }
            $total += mb_strlen($p);
            $blocks[] = new ParsedBlock(($i + 1) . '쪽', trim($p));
        }
        return new ParsedDoc($blocks, $notes);
    }

    // =================================================================
    // 공통
    // =================================================================

    private function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $r = $zip->open($path, ZipArchive::RDONLY);
        if ($r !== true) {
            throw new DocumentParseError(
                '파일을 열지 못했습니다. 손상됐거나 암호가 걸려 있을 수 있습니다(코드 ' . $r . ').');
        }

        // 압축 폭탄 방어 — 풀었을 때 크기를 먼저 본다.
        $sum = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $sum += (int)($st['size'] ?? 0);
            if ($sum > self::MAX_UNCOMPRESSED) {
                $zip->close();
                throw new DocumentParseError('압축을 풀었을 때 너무 커서 읽지 않았습니다.');
            }
        }
        return $zip;
    }

    private function zipRead(ZipArchive $zip, string $name): ?string
    {
        $s = $zip->getFromName($name);
        return $s === false ? null : $s;
    }

    /** XMLReader 를 외부 엔티티 없이 연다(XXE 방어). */
    private function reader(string $xml): XMLReader
    {
        $rd = new XMLReader();
        // LIBXML_NONET: 네트워크 접근 금지. NOENT 를 켜지 않아 엔티티를 펼치지 않는다.
        if (!$rd->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            throw new DocumentParseError('XML 을 읽지 못했습니다.');
        }
        return $rd;
    }

    private function simple(string $xml): SimpleXMLElement
    {
        $x = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($x === false) {
            throw new DocumentParseError('XML 을 읽지 못했습니다.');
        }
        return $x;
    }

    private function unxml(string $s): string
    {
        return $this->clean(html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** 제어문자 정리. 탭·줄바꿈·쪽구분만 남긴다. */
    private function clean(string $s): string
    {
        $s = str_replace("\r\n", "\n", $s);
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', '', $s);
        return $s;
    }
}

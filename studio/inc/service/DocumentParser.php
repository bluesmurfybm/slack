<?php
/** 출처 문서에서 글자와 그 위치를 뽑는 계약. 구현체를 갈아끼울 수 있게 인터페이스로 둔다. */

declare(strict_types=1);

/**
 * 문서에서 뽑아낸 한 덩어리.
 *
 * `ref` 는 **사람이 원문에서 되찾아갈 수 있는 위치**다. WBS 태스크의
 * source_ref 로 그대로 들어간다. 이게 비면 초안을 검토할 때 "이게 어디서
 * 나온 말이냐" 를 확인할 방법이 없어진다.
 *   엑셀  요구사항!A1:D25
 *   PPT   슬라이드 3
 *   워드  1~12문단
 */
final class ParsedBlock
{
    public function __construct(
        public readonly string $ref,
        public readonly string $text,
    ) {}
}

/**
 * 문서 한 건의 파싱 결과.
 *
 * `text` 는 ba_project_source.parsed_text 에 그대로 저장한다. 블록 경계를
 * 표시해 두어서, 나중에 다시 읽어도 위치 정보가 살아 있다 — 컬럼을 새로
 * 만들지 않고 한 칸 안에서 해결한다(스키마 변경을 피한다).
 */
final class ParsedDoc
{
    /** @param ParsedBlock[] $blocks */
    public function __construct(
        public readonly array $blocks,
        public readonly array $notes = [],   // 사람에게 알릴 만한 사항(잘림, 건너뛴 시트 등)
    ) {}

    public function text(): string
    {
        $out = [];
        foreach ($this->blocks as $b) {
            if (trim($b->text) === '') {
                continue;
            }
            $out[] = ba_parse_marker($b->ref) . "\n" . $b->text;
        }
        return implode("\n\n", $out);
    }

    public function charCount(): int
    {
        $n = 0;
        foreach ($this->blocks as $b) {
            $n += mb_strlen($b->text);
        }
        return $n;
    }
}

/** 파싱 실패. 한 파일이 실패해도 나머지는 계속 간다 — 이 예외를 위에서 잡아 기록한다. */
final class DocumentParseError extends RuntimeException
{
}

/**
 * 문서 파서.
 *
 * 지금 구현체는 OfficeDocumentParser 하나다. PhpSpreadsheet 같은 것으로
 * 바꾸고 싶으면 이 인터페이스만 맞추면 된다 — 부르는 쪽은 안 고친다.
 */
interface DocumentParser
{
    /** 이 파서가 다룰 수 있는 kind 인가. (ba_project_source.kind) */
    public function supports(string $kind): bool;

    /**
     * @throws DocumentParseError 열 수 없거나 형식이 아니면
     */
    public function parse(string $filePath, string $kind): ParsedDoc;
}

/**
 * 블록 경계 표시.
 *
 * parsed_text 안에서 위치 정보를 살려 두기 위한 표시다. 이 형식은
 * ba_parse_blocks() 와 짝이다 — 한쪽만 고치지 말 것.
 */
function ba_parse_marker(string $ref): string
{
    // 표시 글자가 본문에 들어 있으면 경계가 깨진다. ref 쪽에서 막는다.
    return '[[' . str_replace([']]', '[['], '', $ref) . ']]';
}

/**
 * parsed_text 를 다시 블록으로 가른다.
 *
 * @return ParsedBlock[] 표시가 없으면 통째로 한 블록(ref 없음)
 */
function ba_parse_blocks(string $parsedText): array
{
    if (!preg_match('/^\[\[.+?\]\]$/m', $parsedText)) {
        return [new ParsedBlock('', $parsedText)];
    }
    $parts = preg_split('/^\[\[(.+?)\]\]$/m', $parsedText, -1,
                        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $out = [];
    // split 결과는 [머리글 없는 앞부분?, ref, 본문, ref, 본문, ...] 이다.
    for ($i = 0; $i < count($parts); $i++) {
        if ($i + 1 < count($parts) && !str_contains($parts[$i], "\n")) {
            $out[] = new ParsedBlock(trim($parts[$i]), trim($parts[$i + 1]));
            $i++;
        }
    }
    return $out ?: [new ParsedBlock('', $parsedText)];
}

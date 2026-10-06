<?php
/** 출처 문서(엑셀·PPT 등) 텍스트에서 WBS 초안을 도출하는 서비스. 결과는 항상 confirmed=0 이다. */

declare(strict_types=1);

require_once __DIR__ . '/DocumentParser.php';
require_once __DIR__ . '/OfficeDocumentParser.php';
require_once __DIR__ . '/LlmClient.php';

/**
 * WBS 도출기.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 반드시 지킬 것 (CLAUDE.md)                                        │
 * │   여기서 나온 태스크는 전부 origin='auto', confirmed=0 이다.       │
 * │   사람이 검토해 확정(TaskRepo::confirm)하기 전에는 배정 대상이     │
 * │   될 수 없다. 이 클래스가 confirmed=1 을 만들 일은 없다.          │
 * │                                                                  │
 * │   그리고 **저장하지 않는다.** 초안을 돌려줄 뿐이다. 사람이 화면    │
 * │   에서 고친 뒤 save_tree 로 저장한다. 도출이 곧 저장이면 검토      │
 * │   단계가 형식만 남는다.                                           │
 * └──────────────────────────────────────────────────────────────────┘
 */
final class WbsExtractor
{
    /** LLM 에 실어 보낼 문서 글자 수 상한. 넘으면 앞에서부터 자른다. */
    public const MAX_PROMPT_CHARS = 60000;

    /** 한 번에 받을 태스크 수 상한. 모델이 폭주하는 것을 막는다. */
    public const MAX_TASKS = 300;

    private ?array $domainCache = null;

    public function __construct(
        private ProjectRepo $projects,
        private TaskRepo $tasks,
        private ?DocumentParser $parser = null,
        private ?LlmClient $llm = null,
        // 링크(구글·피그마)를 읽을 때만 쓴다. 안 주면 모듈 연결을 집는다 —
        // 시험은 자기 DB 를 넘겨 격리한다.
        private ?PDO $pdo = null,
    ) {
        $this->parser ??= new OfficeDocumentParser(
            defined('BS_PDFTOTEXT') && BS_PDFTOTEXT ? BS_PDFTOTEXT : null
        );
        $this->llm ??= bs_llm_client();
    }

    // =================================================================
    // 1단계 — 문서에서 글자 뽑기
    // =================================================================

    /**
     * 출처 문서 한 건을 파싱해 bs_project_source.parsed_text 를 채운다.
     *
     * **예외를 밖으로 던지지 않는다.** 한 파일이 깨졌다고 나머지 파싱이
     * 멈추면 안 된다. 실패는 parse_status='fail' + parse_error 로 남는다.
     *
     * @return array{status:string,chars:int,blocks:int,error:?string,notes:string[]}
     */
    public function parseSource(int $sourceId): array
    {
        $src = $this->projects->findSource($sourceId);
        if (!$src) {
            return $this->parseResult('fail', 0, 0, '출처 문서를 찾을 수 없습니다.');
        }

        $kind = (string)$src['kind'];

        // ┌──────────────────────────────────────────────────────────────┐
        // │ 링크 — 읽을 수 있으면 읽는다                                  │
        // │                                                              │
        // │ 구글 드라이브·피그마는 자격 정보가 등록돼 있으면 서버가 직접  │
        // │ 읽어 온다(RemoteSource). 등록 전이거나 모르는 주소면 예전처럼 │
        // │ 'skip' 이다 — 'skip' 은 실패가 아니라 "원래 안 한다" 는 뜻이라│
        // │ 화면에서 빨갛게 보이면 안 된다.                               │
        // └──────────────────────────────────────────────────────────────┘
        if (in_array($kind, ['url', 'figma'], true)) {
            return $this->parseRemote($sourceId, $src);
        }
        if ($kind === 'text') {
            $this->projects->updateSourceParse(
                $sourceId, 'skip', $src['parsed_text'] ?: null, null);
            return $this->parseResult('skip', 0, 0, null);
        }
        if ($kind === 'image') {
            $this->projects->updateSourceParse($sourceId, 'skip', null, null);
            return $this->parseResult('skip', 0, 0, null);
        }
        if (!$this->parser->supports($kind)) {
            $this->projects->updateSourceParse(
                $sourceId, 'fail', null, "글자를 뽑을 수 없는 형식입니다: $kind");
            return $this->parseResult('fail', 0, 0, "글자를 뽑을 수 없는 형식입니다: $kind");
        }
        if (empty($src['file_path'])) {
            $this->projects->updateSourceParse($sourceId, 'fail', null, '파일 경로가 없습니다.');
            return $this->parseResult('fail', 0, 0, '파일 경로가 없습니다.');
        }

        try {
            $doc  = $this->parser->parse((string)$src['file_path'], $kind);
            $text = $doc->text();
            if (mb_strlen($text) > OfficeDocumentParser::MAX_CHARS) {
                $text = mb_substr($text, 0, OfficeDocumentParser::MAX_CHARS);
            }
            $this->projects->updateSourceParse($sourceId, 'ok', $text, null);
            return $this->parseResult('ok', mb_strlen($text), count($doc->blocks), null, $doc->notes);
        } catch (DocumentParseError $e) {
            $this->projects->updateSourceParse($sourceId, 'fail', null, $e->getMessage());
            return $this->parseResult('fail', 0, 0, $e->getMessage());
        } catch (Throwable $e) {
            // 예상 못 한 오류도 한 파일 안에서 끝낸다. 자세한 내용은 로그로만.
            error_log('[BlueStudio] parseSource#' . $sourceId . ': ' . $e->getMessage());
            $this->projects->updateSourceParse(
                $sourceId, 'fail', null, '파싱 중 오류가 발생했습니다.');
            return $this->parseResult('fail', 0, 0, '파싱 중 오류가 발생했습니다.');
        }
    }

    /**
     * 구글 드라이브·피그마 링크를 읽어 글자를 채운다.
     *
     * 자격 정보가 없거나 모르는 주소면 'skip' — 지금까지와 같다.
     * 읽기에 실패하면 'fail' 로 두고 **왜 안 됐는지를 남긴다.** 조용히
     * skip 으로 돌리면 "왜 분석이 안 되지" 를 사람이 알 길이 없다.
     */
    private function parseRemote(int $sourceId, array $src): array
    {
        $url = trim((string)($src['url'] ?? ''));
        if ($url === '') {
            $this->projects->updateSourceParse($sourceId, 'skip', null, null);
            return $this->parseResult('skip', 0, 0, null);
        }

        require_once __DIR__ . '/RemoteSource.php';
        $remote = new RemoteSource($this->pdo ?? bs_db());

        if (!$remote->canFetch($url)) {
            // 주소를 알아보지 못했거나(지원 안 하는 서비스) 아직 연결 전이다.
            // 둘을 가려 적어 준다 — 할 일이 다르다.
            $known = RemoteSource::identify($url) !== null;
            $this->projects->updateSourceParse($sourceId, 'skip', null, null);
            return $this->parseResult('skip', 0, 0, null, $known
                ? ['연결되지 않은 서비스입니다. 관리자가 설정 화면에서 연결하면 읽을 수 있습니다.']
                : []);
        }

        $tmp = null;
        try {
            $got = $remote->fetch($url);
            $tmp = $got['file'];

            if ($got['text'] !== null) {                 // 피그마 — 이미 글자다
                $text = $got['text'];
                $blocks = substr_count($text, "\n") + 1;
            } else {                                      // 구글 — 파일로 받아 기존 파서로
                // 주소가 시트 하나를 가리켰으면(gid) 그 시트만 읽는다.
                $doc    = $this->parser->parse((string)$tmp, $got['kind'],
                                               $got['sheet'] ?? null);
                $text   = $doc->text();
                $blocks = count($doc->blocks);
            }
            if (mb_strlen($text) > OfficeDocumentParser::MAX_CHARS) {
                $text = mb_substr($text, 0, OfficeDocumentParser::MAX_CHARS);
            }
            $this->projects->updateSourceParse($sourceId, 'ok', $text, null);
            return $this->parseResult('ok', mb_strlen($text), $blocks, null);

        } catch (RemoteSourceError $e) {
            $this->projects->updateSourceParse($sourceId, 'fail', null, $e->getMessage());
            return $this->parseResult('fail', 0, 0, $e->getMessage());
        } catch (DocumentParseError $e) {
            $this->projects->updateSourceParse($sourceId, 'fail', null, $e->getMessage());
            return $this->parseResult('fail', 0, 0, $e->getMessage());
        } catch (Throwable $e) {
            error_log('[BlueStudio] parseRemote#' . $sourceId . ': ' . $e->getMessage());
            $this->projects->updateSourceParse(
                $sourceId, 'fail', null, '링크를 읽는 중 오류가 발생했습니다.');
            return $this->parseResult('fail', 0, 0, '링크를 읽는 중 오류가 발생했습니다.');
        } finally {
            // 임시 파일은 어떤 길로 끝나든 지운다. 안 지우면 서버에 사업
            // 문서가 /tmp 에 쌓인다.
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * 프로젝트의 파일 출처를 전부 파싱한다.
     * 하나가 실패해도 다음으로 넘어간다.
     *
     * @return array<int, array> source_id => parseSource() 결과
     */
    public function parseProjectSources(int $projectId, bool $onlyPending = true): array
    {
        $out = [];
        foreach ($this->projects->sources($projectId) as $s) {
            if ($onlyPending && $s['parse_status'] !== 'pending') {
                continue;
            }
            $out[(int)$s['id']] = $this->parseSource((int)$s['id']);
        }
        return $out;
    }

    private function parseResult(string $status, int $chars, int $blocks,
                                 ?string $error, array $notes = []): array
    {
        return ['status' => $status, 'chars' => $chars, 'blocks' => $blocks,
                'error' => $error, 'notes' => $notes];
    }

    // =================================================================
    // 2단계 — 글자에서 태스크 뽑기
    // =================================================================

    /**
     * 프로젝트의 모든 출처에서 WBS 초안을 만든다.
     * api/task.php?act=extract 의 입구다. **저장하지 않는다.**
     *
     * @param bool $useLlm false 면 규칙만으로 뽑는다(아무것도 밖으로 안 나간다)
     * @return array{tree:array,meta:array}
     */
    public function extractForProject(int $projectId, bool $useLlm = true): array
    {
        $project = $this->projects->find($projectId);
        if (!$project) {
            throw new DomainException('프로젝트를 찾을 수 없습니다.');
        }

        // sources() 가 아니라 parsedSources() 다. 앞엣것은 목록 화면용이라
        // parsed_text 를 아예 담지 않는다(길이만 준다). 그걸로 거르면
        // 조건이 항상 거짓이라 "문서가 없다" 만 나온다 — 실제로 겪었다.
        $sources = $this->projects->parsedSources($projectId);

        if (!$sources) {
            throw new DomainException(
                '읽어 들인 문서가 없습니다. 먼저 [문서 분석] 을 눌러 출처 문서에서 '
                . '글자를 뽑아 주세요. 링크나 직접 입력만 있으면 도출할 내용이 없습니다.'
            );
        }

        $domains = $this->domainTable();

        if ($useLlm && $this->llm->available()) {
            [$tasks, $meta] = $this->extractByLlm($project, $sources, $domains);
        } else {
            if ($useLlm && !$this->llm->available()) {
                // 조용히 규칙으로 내려가지 않는다. 사람이 LLM 결과를 기대했는데
                // 규칙 결과를 받으면 품질이 왜 이런지 알 수 없다.
                $meta0 = ['fallback_reason' => 'LLM 이 설정돼 있지 않아 규칙만으로 뽑았습니다.'];
            } else {
                $meta0 = [];
            }
            [$tasks, $meta] = $this->extractByRule($sources, $domains);
            $meta += $meta0;
        }

        $tree = $this->toDraftTree($tasks, $domains);

        return [
            'tree' => $tree,
            'meta' => $meta + [
                'source_count' => count($sources),
                'task_count'   => count($tasks),
                'llm'          => $this->llm->name(),
                'llm_available' => $this->llm->available(),
            ],
        ];
    }

    /**
     * 규칙 기반 도출 — LLM 없이.
     *
     * 번호 매김(1. / 1.1 / ①)과 들여쓰기로 계층을 잡는다. 엑셀에서 온
     * 블록은 탭으로 갈라진 표라, 첫 칸이 비었는지로 계층을 본다.
     *
     * 품질은 LLM 보다 못하다. 그래도 있어야 한다 — 사외 반출이 막혀 있어도
     * 이 기능이 아예 없는 것보다 낫고, LLM 결과와 견줘 볼 기준이 된다.
     *
     * @return array{0:array,1:array} [태스크 평면 배열, meta]
     */
    public function extractByRule(array $sources, ?array $domains = null): array
    {
        $domains ??= $this->domainTable();
        $tasks = [];

        foreach ($sources as $s) {
            foreach (bs_parse_blocks((string)$s['parsed_text']) as $block) {
                $lines = explode("\n", $block->text);

                // 엑셀 블록(ref 에 시트명!범위가 들어 있다)은 **표로 읽는다.**
                // 줄 단위로만 훑으면 머리글("구분", "비고")까지 태스크가 된다.
                $rows = str_contains($block->ref, '!')
                    ? $this->sheetToTasks($lines)
                    : null;

                if ($rows === null) {
                    $rows = [];
                    foreach ($lines as $i => $line) {
                        $t = $this->lineToTask($line);
                        if ($t !== null) {
                            $t['line'] = $i;
                            $rows[] = $t;
                        }
                    }
                }

                foreach ($rows as $t) {
                    $line = $t['line'] ?? null;
                    unset($t['line']);
                    $t['source_id']  = (int)$s['id'];
                    // 블록 표시 + 몇 번째 줄인지. 실제 셀 주소까지는 알 수 없다
                    // (parsed_text 에 행 번호를 싣지 않는다) — 모르는 것을
                    // 아는 척하지 않고 찾아갈 수 있을 만큼만 적는다.
                    // 직접 입력한 글은 블록 표시가 없어 ref 가 비어 있다.
                    // 그대로 이으면 앞에 빈칸이 남는다.
                    $t['source_ref'] = trim($block->ref
                        . ($line !== null ? ' ' . ($line + 1) . '번째 줄' : ''));
                    $t['domain_codes'] = array_keys($this->guessDomains($t['title'], '', $domains));
                    $tasks[] = $t;
                    if (count($tasks) >= self::MAX_TASKS) {
                        break 3;
                    }
                }
            }
        }

        return [$tasks, [
            'method' => 'rule',
            // 규칙으로 뽑은 것은 문서를 훑은 결과일 뿐이다. 그대로 쓰라고
            // 권하지 않는다 — 화면이 이 말을 그대로 띄운다.
            'quality_note' => '규칙만으로 뽑은 초안입니다. 표와 목록을 훑은 것이라 '
                            . '업무가 아닌 줄(머리글·비고 등)이 섞일 수 있습니다. '
                            . '지울 것을 먼저 지우고 보십시오.',
        ]];
    }

    /**
     * 엑셀 시트를 표로 읽어 태스크를 만든다.
     *
     * 머리글 줄을 찾아 **어느 열이 무엇인지** 정한 뒤, 그 아래 줄만 태스크로
     * 본다. 이렇게 하지 않으면 "구분 / 요구사항 / 공수 / 난이도" 같은 머리글
     * 자체가 태스크가 되고, 오른쪽 숫자 칸이 제목이 되기도 한다.
     *
     * 머리글을 못 찾으면 null 을 돌려준다 — 호출자가 줄 단위로 되돌아간다.
     * 억지로 표로 읽느니 덜 똑똑하게 읽는 편이 낫다.
     *
     * @return array|null
     */
    private function sheetToTasks(array $lines): ?array
    {
        $titleWords = ['요구사항', '요구 사항', '내용', '기능', '업무', '작업', '항목',
                       '태스크', '과업', '세부', '상세', '개발내용'];
        $groupWords = ['구분', '분류', '대분류', '영역', '모듈', '파트', '그룹'];
        $subWords   = ['중분류', '소분류', '하위'];
        $estWords   = ['공수', 'm/d', 'md', '맨먼스', '일수', '투입'];
        $diffWords  = ['난이도', '복잡도'];

        $map = null;
        $headerAt = null;

        foreach ($lines as $i => $line) {
            if ($i > 5) {
                break;      // 머리글이 6줄 아래에 있으면 표로 보지 않는다
            }
            $cells = array_map('trim', explode("\t", $line));
            $m = ['title' => null, 'group' => null, 'sub' => null, 'est' => null, 'diff' => null];
            foreach ($cells as $c => $v) {
                $lv = mb_strtolower($v);
                if ($v === '') {
                    continue;
                }
                if ($m['sub']   === null && $this->hasAny($lv, $subWords))   { $m['sub']   = $c; continue; }
                if ($m['group'] === null && $this->hasAny($lv, $groupWords)) { $m['group'] = $c; continue; }
                if ($m['title'] === null && $this->hasAny($lv, $titleWords)) { $m['title'] = $c; continue; }
                if ($m['est']   === null && $this->hasAny($lv, $estWords))   { $m['est']   = $c; continue; }
                if ($m['diff']  === null && $this->hasAny($lv, $diffWords))  { $m['diff']  = $c; continue; }
            }
            if ($m['title'] !== null) {
                $map = $m;
                $headerAt = $i;
                break;
            }
        }

        if ($map === null) {
            return null;
        }

        $out = [];
        $lastGroup = null;
        $lastSub   = null;
        foreach ($lines as $i => $line) {
            if ($i <= $headerAt) {
                continue;
            }
            $cells = array_map('trim', explode("\t", $line));
            $title = $cells[$map['title']] ?? '';
            if ($title === '' || mb_strlen($title) < 2) {
                continue;
            }

            // 구분 칸이 새 값이면 그것이 상위 태스크가 된다. 같은 값이
            // 이어지면 한 번만 만든다 — 엑셀은 병합 대신 반복해 적는다.
            if ($map['group'] !== null) {
                $g = $cells[$map['group']] ?? '';
                if ($g !== '' && $g !== $lastGroup) {
                    $out[] = ['title' => $g, 'depth' => 1, 'est_md' => null,
                              'difficulty' => null, 'line' => $i];
                    $lastGroup = $g;
                    $lastSub   = null;
                }
            }
            if ($map['sub'] !== null) {
                $sv = $cells[$map['sub']] ?? '';
                if ($sv !== '' && $sv !== $lastSub) {
                    $out[] = ['title' => $sv, 'depth' => $map['group'] !== null ? 2 : 1,
                              'est_md' => null, 'difficulty' => null, 'line' => $i];
                    $lastSub = $sv;
                }
            }

            $depth = 1;
            if ($map['group'] !== null) { $depth++; }
            if ($map['sub'] !== null)   { $depth++; }
            $depth = min($depth, BS_TASK_MAX_DEPTH);

            $est = null;
            if ($map['est'] !== null && is_numeric($cells[$map['est']] ?? '')) {
                $v = (float)$cells[$map['est']];
                if ($v > 0 && $v <= BS_TASK_MAX_EST_MD) { $est = round($v, 2); }
            }
            $diff = null;
            if ($map['diff'] !== null && is_numeric($cells[$map['diff']] ?? '')) {
                $v = (int)$cells[$map['diff']];
                if ($v >= BS_TASK_DIFFICULTY_MIN && $v <= BS_TASK_DIFFICULTY_MAX) { $diff = $v; }
            }

            $out[] = ['title' => mb_substr($title, 0, 300), 'depth' => $depth,
                      'est_md' => $est, 'difficulty' => $diff, 'line' => $i];
        }

        return $out ?: null;
    }

    private function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 한 줄에서 태스크 하나.
     *
     * @return array{title:string,depth:int,est_md:?float,difficulty:?int}|null
     */
    private function lineToTask(string $line): ?array
    {
        $cols  = explode("\t", $line);
        $first = trim($cols[0]);

        // 엑셀 표: 첫 칸이 비어 있으면 한 단계 들여쓴 것으로 본다.
        $indent = 0;
        while ($indent < count($cols) - 1 && trim($cols[$indent]) === '') {
            $indent++;
        }
        $head = trim($cols[$indent] ?? '');
        if ($head === '') {
            return null;
        }

        // 번호 매김에서 깊이를 읽는다. "1.2.3 제목" → 3단계
        $depth = $indent + 1;
        if (preg_match('/^(\d+(?:\.\d+)*)[.)]?\s+(.+)$/u', $head, $m)) {
            $depth = max($depth, substr_count($m[1], '.') + 1);
            $head  = trim($m[2]);
        } elseif (preg_match('/^[-*·•]\s+(.+)$/u', $head, $m)) {
            $depth = max($depth, 2);
            $head  = trim($m[1]);
        }
        $depth = min($depth, BS_TASK_MAX_DEPTH);

        // 너무 짧은 칸은 대개 표 머리글이나 꼬리표다("구분", "비고", "일정").
        // 4자를 기준으로 삼으면 "출석 통합"(5자) 같은 실제 업무는 남는다.
        // 놓치는 것이 생기는 대신 쓰레기가 크게 줄어든다.
        if (mb_strlen($head) < 4 || mb_strlen($head) > 300) {
            return null;
        }
        // 숫자·날짜만 있는 칸은 제목이 아니다.
        if (preg_match('/^[\d\s.,:\/\-%]+$/u', $head)) {
            return null;
        }
        // 날짜·기간만 적힌 줄도 업무가 아니다("킥오프 2026-02" 는 남는다).
        if (preg_match('/^\d{4}[-.\/]\d{1,2}([-.\/]\d{1,2})?\s*[~\-]?\s*\d*[-.\/]*\d*$/u', $head)) {
            return null;
        }

        // 뒤 칸에서 숫자를 주워 공수·난이도로 본다. 첫 숫자가 공수,
        // 1~5 짜리 정수가 난이도. 확신이 없으면 비워 둔다 — 틀린 값보다 낫다.
        $est = null; $diff = null;
        for ($i = $indent + 1; $i < count($cols); $i++) {
            $v = trim($cols[$i]);
            if ($v === '' || !is_numeric($v)) {
                continue;
            }
            $f = (float)$v;
            if ($est === null && $f > 0 && $f <= BS_TASK_MAX_EST_MD) {
                $est = round($f, 2);
            } elseif ($diff === null && $f >= 1 && $f <= 5 && (float)(int)$f === $f) {
                $diff = (int)$f;
            }
        }

        return ['title' => $head, 'depth' => $depth, 'est_md' => $est, 'difficulty' => $diff];
    }

    /**
     * LLM 보조 도출.
     *
     * 스키마를 엄격히 검증하고, 형식이 깨지면 **한 번만** 고쳐 달라고 다시
     * 부른다. 두 번 이상 두드리지 않는다 — 같은 입력으로 계속 실패하는
     * 모델을 반복 호출하면 돈만 든다.
     *
     * @return array{0:array,1:array}
     */
    public function extractByLlm(array $project, array $sources, ?array $domains = null): array
    {
        $domains ??= $this->domainTable();

        if (!$this->llm->available()) {
            throw new LlmError('LLM 이 설정돼 있지 않습니다.', retryable: false);
        }

        $schema = $this->schema($domains);
        $system = $this->systemPrompt($domains);
        $user   = $this->userPrompt($project, $sources);

        $attempt = 0;
        $lastErr = null;
        $repair  = '';

        // 1회차 + 형식 문제일 때만 1회 재시도.
        while ($attempt < 2) {
            $attempt++;
            try {
                $res  = $this->llm->generate($system, $user . $repair, $schema,
                                             ['max_tokens' => 8000]);
                $ok   = $this->validate($res->data, $domains);
                return [$ok, [
                    'method'     => 'llm',
                    'attempts'   => $attempt,
                    'model'      => $res->model,
                    'backend'    => $res->backend,
                    'tokens_in'  => $res->tokensIn,
                    'tokens_out' => $res->tokensOut,
                    'cost_usd'   => $res->costUsd,
                ]];
            } catch (LlmSchemaError $e) {
                $lastErr = $e;
                if ($attempt >= 2) {
                    break;
                }
                // 무엇이 틀렸는지 그대로 알려 준다. "다시 해" 만으로는 안 고쳐진다.
                $repair = "\n\n[이전 응답이 형식에 맞지 않았습니다]\n"
                        . $e->getMessage()
                        . "\n스키마를 정확히 지켜 JSON 만 다시 내주세요.";
            } catch (LlmError $e) {
                $lastErr = $e;
                if (!$e->retryable || $attempt >= 2) {
                    break;
                }
                // 형식이 아닌 일시적 오류는 같은 입력으로 한 번 더.
            }
        }

        throw new LlmError(
            'WBS 도출에 실패했습니다(' . $attempt . '회 시도). '
            . ($lastErr?->getMessage() ?? '알 수 없는 오류'),
            retryable: false
        );
    }

    // =================================================================
    // 스키마와 검증
    // =================================================================

    /** 모델에 넘길 JSON Schema. 검증도 이 모양을 기준으로 한다. */
    public function schema(?array $domains = null): array
    {
        $domains ??= $this->domainTable();
        return [
            'type' => 'object',
            'required' => ['tasks'],
            'additionalProperties' => false,
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_TASKS,
                    'items' => [
                        'type' => 'object',
                        'required' => ['title', 'depth'],
                        'additionalProperties' => false,
                        'properties' => [
                            'title'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                            'depth'       => ['type' => 'integer', 'minimum' => 1,
                                              'maximum' => BS_TASK_MAX_DEPTH],
                            'description' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                            'est_md'      => ['type' => ['number', 'null'], 'minimum' => 0,
                                              'maximum' => BS_TASK_MAX_EST_MD],
                            'difficulty'  => ['type' => ['integer', 'null'],
                                              'minimum' => BS_TASK_DIFFICULTY_MIN,
                                              'maximum' => BS_TASK_DIFFICULTY_MAX],
                            'domain_codes' => [
                                'type' => 'array',
                                'maxItems' => BS_TASK_MAX_DOMAINS,
                                'items' => ['type' => 'string', 'enum' => array_keys($domains)],
                            ],
                            'source_ref'  => ['type' => ['string', 'null'], 'maxLength' => 200],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 돌아온 값을 스키마에 맞춰 검증한다.
     *
     * 모델이 스키마를 지켰다고 믿지 않는다. 구조화 출력을 지원하는
     * 백엔드라도 지원하지 않는 백엔드로 갈아끼우는 순간 이 검증만 남는다.
     *
     * **틀린 항목 하나 때문에 전부 버리지는 않는다.** 고칠 수 있는 것은
     * 고치고(깊이 범위, 숫자 범위), 고칠 수 없는 것만 버린 뒤 몇 건을
     * 왜 버렸는지 남긴다. 다만 전체 모양이 어긋나면 재시도로 넘긴다.
     *
     * @throws LlmSchemaError 구조 자체가 어긋나면
     */
    public function validate(array $data, ?array $domains = null): array
    {
        $domains ??= $this->domainTable();

        if (!isset($data['tasks'])) {
            throw new LlmSchemaError('최상위에 "tasks" 가 없습니다. 받은 키: '
                . implode(', ', array_slice(array_keys($data), 0, 8)));
        }
        if (!is_array($data['tasks'])) {
            throw new LlmSchemaError('"tasks" 가 배열이 아닙니다.');
        }
        if (!array_is_list($data['tasks'])) {
            throw new LlmSchemaError('"tasks" 는 객체가 아니라 배열이어야 합니다.');
        }
        if (count($data['tasks']) === 0) {
            throw new LlmSchemaError('"tasks" 가 비어 있습니다. 문서에서 아무것도 찾지 못했다면 '
                . '그렇게 판단한 이유를 확인해야 합니다.');
        }
        if (count($data['tasks']) > self::MAX_TASKS) {
            throw new LlmSchemaError('태스크가 너무 많습니다(' . count($data['tasks']) . '). '
                . self::MAX_TASKS . '건 이하로 줄여 주세요.');
        }

        $out     = [];
        $dropped = [];
        foreach ($data['tasks'] as $i => $t) {
            if (!is_array($t)) {
                $dropped[] = ($i + 1) . '번째: 객체가 아님';
                continue;
            }
            $title = is_string($t['title'] ?? null) ? trim($t['title']) : '';
            if ($title === '') {
                $dropped[] = ($i + 1) . '번째: 제목 없음';
                continue;
            }

            $depth = (int)($t['depth'] ?? 1);
            $depth = max(1, min(BS_TASK_MAX_DEPTH, $depth));

            $est = $t['est_md'] ?? null;
            $est = (is_numeric($est) && $est >= 0 && $est <= BS_TASK_MAX_EST_MD)
                 ? round((float)$est, 2) : null;

            $diff = $t['difficulty'] ?? null;
            $diff = (is_numeric($diff) && $diff >= BS_TASK_DIFFICULTY_MIN
                     && $diff <= BS_TASK_DIFFICULTY_MAX) ? (int)$diff : null;

            $codes = [];
            foreach ((array)($t['domain_codes'] ?? []) as $c) {
                if (is_string($c) && isset($domains[$c]) && !in_array($c, $codes, true)) {
                    $codes[] = $c;
                }
            }
            $codes = array_slice($codes, 0, BS_TASK_MAX_DOMAINS);

            $out[] = [
                'title'        => mb_substr($title, 0, 300),
                'depth'        => $depth,
                'description'  => is_string($t['description'] ?? null)
                                  ? mb_substr(trim($t['description']), 0, 2000) : null,
                'est_md'       => $est,
                'difficulty'   => $diff,
                'domain_codes' => $codes,
                'source_ref'   => is_string($t['source_ref'] ?? null)
                                  ? mb_substr(trim($t['source_ref']), 0, 200) : null,
                'source_id'    => null,
            ];
        }

        if (!$out) {
            throw new LlmSchemaError('쓸 수 있는 태스크가 하나도 없습니다. ' . implode(' / ', $dropped));
        }
        if ($dropped) {
            // 버린 것을 조용히 묻지 않는다. 화면이 보여 줄 수 있게 붙여 둔다.
            $out[0]['_dropped'] = $dropped;
        }
        return $out;
    }

    // =================================================================
    // 프롬프트
    // =================================================================

    private function systemPrompt(array $domains): string
    {
        $list = [];
        foreach ($domains as $code => $d) {
            $list[] = "  $code — {$d['name']}";
        }

        return <<<TXT
        당신은 학습관리시스템(LMS/LXP) 구축·고도화 프로젝트의 업무 분해(WBS)를 돕습니다.
        주어진 문서에서 **실제로 개발해야 할 일**을 뽑아 대/중/소 3단계로 정리하세요.

        지킬 것:
        - 문서에 적힌 것만 쓰세요. 없는 일을 만들어 내지 마세요.
        - 목차·표지·연락처·용어집 같은 것은 태스크가 아닙니다.
        - depth 는 1(대분류) 2(중분류) 3(소분류)입니다. 1보다 작거나 3보다 클 수 없습니다.
        - 태스크는 문서에 나온 순서대로, 상위가 먼저 오게 늘어놓으세요.
          (중첩 구조가 아니라 평평한 배열입니다. depth 로 계층을 나타냅니다.)
        - source_ref 에는 그 일이 적혀 있던 위치를 **입력에 주어진 [[...]] 표시 그대로**
          적으세요. 지어내지 마세요. 모르면 비우세요.
        - est_md(사람·일)와 difficulty(1~5)는 문서에 근거가 있을 때만 적으세요.
          추측한 값을 적느니 비우는 편이 낫습니다. 사람이 검토해서 채웁니다.
        - domain_codes 는 아래 목록에 있는 코드만 쓰세요. 해당 없으면 빈 배열입니다.

        분야 코드:
        {$this->joinLines($list)}

        JSON 만 출력하세요. 설명 문장을 덧붙이지 마세요.
        TXT;
    }

    private function userPrompt(array $project, array $sources): string
    {
        $head = [];
        $head[] = '# 프로젝트';
        $head[] = '이름: ' . $project['name'];
        if (!empty($project['client'])) { $head[] = '고객: ' . $project['client']; }
        if (!empty($project['track']))  {
            $head[] = '유형: ' . (BS_PROJECT_TRACK[$project['track']] ?? $project['track']);
        }
        if (!empty($project['summary'])) { $head[] = '개요: ' . $project['summary']; }
        if (!empty($project['notes']))   { $head[] = '특이점: ' . $project['notes']; }
        if (!empty($project['extra']))   { $head[] = '기타: ' . $project['extra']; }

        $body   = [];
        $budget = self::MAX_PROMPT_CHARS;
        $cut    = false;
        foreach ($sources as $s) {
            $t = (string)$s['parsed_text'];
            $title = $s['title'] ?: ($s['kind'] . ' 문서');
            $head2 = "\n# 문서: " . $title . ' (' . $s['kind'] . ')';
            $budget -= mb_strlen($head2);
            if ($budget <= 0) { $cut = true; break; }
            if (mb_strlen($t) > $budget) {
                $t = mb_substr($t, 0, $budget);
                $cut = true;
            }
            $budget -= mb_strlen($t);
            $body[] = $head2 . "\n" . $t;
            if ($budget <= 0) { break; }
        }

        // 잘렸으면 모델에게도 알려 준다. 문서가 끝난 줄 알고 결론을 내지 않게.
        $tail = $cut
            ? "\n\n[주의] 문서가 길어 일부만 실었습니다. 실린 부분에서만 뽑으세요."
            : '';

        return $this->joinLines($head) . "\n" . implode("\n", $body) . $tail;
    }

    private function joinLines(array $a): string
    {
        return implode("\n", $a);
    }

    // =================================================================
    // 3단계 — 태스크에 분야 붙이기
    // =================================================================

    /**
     * 분야표. 한 요청 안에서는 한 번만 읽는다.
     *
     * 캐시를 static 이 아니라 인스턴스에 둔다. static 이면 시험에서 DB 를
     * 바꿔 가며 돌릴 때 앞 것이 남아 엉뚱한 결과가 나온다.
     *
     * @return array<string, array{id:int,name:string,category:string,keywords:string[]}>
     */
    public function domainTable(): array
    {
        return $this->domainCache ??= $this->tasks->activeDomains();
    }

    /**
     * bs_domain.keywords 로 분야를 추정한다.
     *
     * 한 낱말이 여러 분야에 걸리는 것은 정상이다 — 맞은 낱말 수로 가린다.
     * 분야 이름 자체도 낱말로 친다.
     *
     * @return array<string, float> code => weight (합이 1)
     */
    public function guessDomains(string $title, string $description = '', ?array $domains = null): array
    {
        $domains ??= $this->domainTable();
        $hay = mb_strtolower($title . ' ' . $description);
        if (trim($hay) === '') {
            return [];
        }

        $hit = [];
        foreach ($domains as $code => $d) {
            $n = 0;
            foreach (array_merge($d['keywords'], [$d['name']]) as $kw) {
                $kw = mb_strtolower(trim((string)$kw));
                // 두 글자 미만은 아무 데나 걸린다.
                if (mb_strlen($kw) < 2) {
                    continue;
                }
                if (str_contains($hay, $kw)) {
                    $n++;
                }
            }
            if ($n > 0) {
                $hit[$code] = $n;
            }
        }
        if (!$hit) {
            return [];
        }

        arsort($hit);
        $hit = array_slice($hit, 0, BS_TASK_MAX_DOMAINS, true);
        $sum = array_sum($hit);
        return array_map(static fn($n) => round($n / $sum, 3), $hit);
    }

    /** 태스크 난이도(1~5) 추정. 근거가 없으면 비운다 — 지어낸 숫자를 넣지 않는다. */
    public function guessDifficulty(array $task): ?int
    {
        return $task['difficulty'] ?? null;
    }

    // =================================================================
    // 초안 트리 만들기
    // =================================================================

    /**
     * depth 만 있는 평면 배열을 화면이 쓰는 트리로.
     *
     * 첫 항목이 2·3단계로 시작하거나 단계가 건너뛰면 끌어올린다 —
     * 붙일 상위가 없는 항목을 버리면 사람은 뭐가 사라졌는지 모른다.
     * 엑셀 붙여넣기 파서와 같은 태도다.
     */
    public function toDraftTree(array $tasks, ?array $domains = null): array
    {
        $domains ??= $this->domainTable();
        $codeToId = array_map(static fn($d) => $d['id'], $domains);

        // 참조로 트리를 엮지 않는다. PHP 배열 참조는 중간에 복사가 끼면
        // 조용히 끊어져서, 붙인 줄 알았던 자식이 사라진다. 납작하게 모은 뒤
        // 부모 번호로 한 번에 조립한다.
        $nodes    = [];          // i => 노드(children 없음)
        $parentOf = [];          // i => 부모 i, 최상위는 -1
        $lastAt   = [];          // depth => 마지막 i

        foreach ($tasks as $t) {
            $depth = max(1, min(BS_TASK_MAX_DEPTH, (int)($t['depth'] ?? 1)));
            // 붙일 상위가 없으면 끌어올린다. 버리지 않는다 —
            // 사라진 항목은 사람이 알아챌 방법이 없다.
            while ($depth > 1 && !isset($lastAt[$depth - 1])) {
                $depth--;
            }

            $doms = [];
            foreach ($t['domain_codes'] ?? [] as $c) {
                if (isset($codeToId[$c])) {
                    $doms[] = [
                        'domain_id' => $codeToId[$c],
                        'name'      => $domains[$c]['name'],
                        'category'  => $domains[$c]['category'],
                    ];
                }
            }

            $node = [
                'id'          => null,
                'title'       => $t['title'],
                'description' => $t['description'] ?? null,
                'est_md'      => $t['est_md'] ?? null,
                'difficulty'  => $t['difficulty'] ?? null,
                'plan_start'  => null,
                'plan_end'    => null,
                'domains'     => $doms,
                // 여기서 나온 것은 전부 초안이다. 화면이 색으로 구분한다.
                'origin'      => 'auto',
                'confirmed'   => false,
                'source_id'   => $t['source_id'] ?? null,
                'source_ref'  => $t['source_ref'] ?? null,
                'children'    => [],
            ];

            $i = count($nodes);
            $nodes[$i]    = $node;
            $parentOf[$i] = $depth === 1 ? -1 : $lastAt[$depth - 1];
            $lastAt[$depth] = $i;
            // 더 깊은 단계는 이제 상위가 바뀌었으므로 버린다.
            for ($d = $depth + 1; $d <= BS_TASK_MAX_DEPTH; $d++) {
                unset($lastAt[$d]);
            }
        }

        $childrenOf = [];
        foreach ($parentOf as $i => $p) {
            $childrenOf[$p][] = $i;
        }
        $build = static function (int $p) use (&$build, &$childrenOf, &$nodes): array {
            $out = [];
            foreach ($childrenOf[$p] ?? [] as $i) {
                $n = $nodes[$i];
                $n['children'] = $build($i);
                $out[] = $n;
            }
            return $out;
        };
        return $build(-1);
    }
}

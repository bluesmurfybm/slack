<?php
/**
 * 태스크 난이도 판정 — 규칙(바탕) + AI(주).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 둘 다 두는가                                                   │
 * │                                                                  │
 * │ 규칙은 `slack/difficulty/difficulty.php` 에서 그대로 가져왔다.    │
 * │ 유지보수 요청 **947건을 AI 로 채점한 라벨**에서 키워드별 평균     │
 * │ 별점을 뽑아 역산한 것이라, 추측이 아니라 **실데이터에서 나온**    │
 * │ 가중치다. 공짜고, 즉시 돌고, 늘 같은 답을 준다.                   │
 * │                                                                  │
 * │ 한계도 분명하다. **낱말만 본다.** "링크 생성" 이 들어가면 ★1 로  │
 * │ 떨어지는데, 그게 "SSO 로그인 뒤 외부 LMS 로 넘어가는 링크 생성"   │
 * │ 이어도 똑같이 ★1 이다. 기획서 맥락을 못 읽는다.                   │
 * │                                                                  │
 * │ 그래서 **AI 를 주로 쓰고 규칙을 바탕으로 둔다.**                  │
 * │   · AI 가 답하면 그 값을 쓴다(difficulty_by='ai')                │
 * │   · AI 가 막히면(키 없음·호출 제한·크레딧) 규칙으로 떨어진다      │
 * │     (difficulty_by='rule') — **판정이 아예 안 나오는 일은 없다**  │
 * │   · 둘이 2단계 이상 갈리면 근거에 **둘 다 적는다**. 사람이 보고   │
 * │     판단할 거리를 남긴다                                          │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 규칙 쪽은 네트워크를 타지 않는 순수 함수다. 시험하기 쉽고, AI 가 꺼져
 * 있어도 WBS 는 난이도를 갖는다.
 */

declare(strict_types=1);

require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/AnthropicLlmClient.php';

final class DifficultyScorer
{
    /**
     * 947건 AI 채점 라벨에서 뽑은 키워드 가중치.
     *
     * 기본 3점(보통)에서 시작해 제목+본문에 든 키워드의 가중치를 더한다.
     * `pts` 는 (그 키워드가 든 요청들의 평균 별점 − 3) 의 근삿값이다.
     *
     * **여기만 고치면 규칙이 바뀐다.** slack 모듈의 RULES 와 같은 값이므로,
     * 한쪽을 고치면 다른 쪽도 보는 편이 맞다.
     */
    public const RULES = [
        // ★5 급 핵심 고난도 (평균 3.8~4.1)
        ['pts' =>  1.5,  'label' => '핵심 고난도',
         'words' => ['무한로딩', '무한 로딩', '정합성', '마이그레이션']],
        // ★4 급: 복구·보안·DB오류·이관 (평균 3.5~4.0)
        ['pts' =>  1.0,  'label' => '복구/보안/DB',
         'words' => ['복구', '취약점', 'xss', 'asm', '데이터베이스 쓰기', '데이터베이스 읽기',
                     'db 쓰기', 'db 읽기', '이관']],
        ['pts' =>  0.7,  'label' => '연동/배치/개발',
         'words' => ['배치', '크론', '스케줄러', '세션', '동기화', '신규 개발', '챗봇', '장바구니',
                     '합반', '인코딩', '변환', '장애', '뷰 생성', 'view 생성', '커스터마이징',
                     '코어', '대규모']],
        // 버그·오류류 (평균 3.3~3.7)
        ['pts' =>  0.4,  'label' => '버그/오류',
         'words' => ['실패', '오류', '에러', '버그', '안됨', '안 됨', '불가', '증상', '현상',
                     '깨짐', '누락', '멈춤', '튕김', 'api', '성적부', '플레이어']],
        // 약한 상향 (평균 3.0~3.3)
        ['pts' =>  0.2,  'label' => '조사/검토',
         'words' => ['export', '엑스포트', '유사도', '개선', '조사', '검토', '집계', '통계',
                     '권한', '진도', '재생', 'sso', '500', '504']],
        ['pts' =>  0.1,  'label' => '경미',
         'words' => ['로그', '연동', '데이터', '모듈', '가능여부', '가능 여부']],
        // 단순 운영성 (평균 2.3~2.7) → 하향
        ['pts' => -0.35, 'label' => '단순 수정',  'words' => ['수정', '추가']],
        ['pts' => -1.0,  'label' => '양식/문구/추출',
         'words' => ['문구', '팝업', '추출', '수료증', 'uuid', '양식 추가', '이수증', '언어팩',
                     '일괄 변경', '일괄변경', '메뉴명']],
        // 매우 단순 (평균 1.0~1.9)
        ['pts' => -1.6,  'label' => '단순 운영작업',
         'words' => ['학사연동 지원', '학사연동지원', '삭제 요청', '삭제요청', '로고', '배너',
                     '이미지 변경', '파비콘', '사이트명', '전화번호', '다운로드 링크']],
        ['pts' => -2.5,  'label' => '단순 생성/치환',
         'words' => ['링크 생성', '링크생성', '링크 변경', '링크변경', '문구 수정', '문구수정',
                     '매뉴얼']],
    ];

    public const LEVELS = [
        5 => ['name' => '매우 어려움', 'desc' => '연동·개발·정합성 복구'],
        4 => ['name' => '어려움',      'desc' => '버그 수정·데이터 복구·기능 개선'],
        3 => ['name' => '보통',        'desc' => '규칙 확인·표시 오류'],
        2 => ['name' => '쉬움',        'desc' => '조회·추출·단순 처리'],
        1 => ['name' => '매우 쉬움',   'desc' => '문구·로고·링크 생성'],
    ];

    /** 상·하한. 장문이나 키워드 과다로 과대·과소 채점되는 것을 막는다. */
    private const CLAMP_HI = 1.4;
    private const CLAMP_LO = -2.2;

    /** 모델에 넣는 기획 글의 상한. 길수록 비싸고, 앞쪽에 요지가 있다. */
    private const CONTEXT_CHARS = 6000;

    /**
     * 안 주면 모듈 설정이 고른 백엔드를 쓴다(WbsExtractor 와 같은 방식).
     * 시험은 FixtureLlmClient 를 넣어 **밖으로 아무것도 보내지 않고** 돌린다.
     */
    public function __construct(private ?LlmClient $llm = null)
    {
        $this->llm ??= bs_llm_client();
    }

    // =================================================================
    // 규칙 — 네트워크를 타지 않는다
    // =================================================================

    /**
     * 낱말만 보고 1~5 를 매긴다.
     *
     * @return array{level:int, score:float, signals:list<array{w:string,pts:float}>}
     */
    public static function byRule(string $title, string $body = '', string $team = ''): array
    {
        $text    = mb_strtolower($title . ' ' . $body);
        $sum     = 0.0;
        $signals = [];

        foreach (self::RULES as $rule) {
            foreach ($rule['words'] as $w) {
                // 키워드 하나가 여러 번 나와도 한 번만 센다. 같은 말이
                // 반복되는 기획서에서 점수가 치솟는 것을 막는다.
                if (str_contains($text, mb_strtolower($w))) {
                    $sum      += $rule['pts'];
                    $signals[] = ['w' => $w, 'pts' => $rule['pts']];
                }
            }
        }

        // 미세 보정: 개발성 팀은 소폭 상향, 본문 길이는 맥락 복잡도의 대리값
        if ($team === '시스템개발') {
            $sum      += 0.3;
            $signals[] = ['w' => '담당팀:시스템개발', 'pts' => 0.3];
        }
        $len = mb_strlen($body);
        if ($len >= 1200) {
            $sum      += 0.5;
            $signals[] = ['w' => '본문 매우 김', 'pts' => 0.5];
        } elseif ($len >= 600) {
            $sum      += 0.25;
            $signals[] = ['w' => '본문 김', 'pts' => 0.25];
        }

        $sum = max(self::CLAMP_LO, min(self::CLAMP_HI, $sum));
        $raw = 3 + $sum;

        // ┌──────────────────────────────────────────────────────────┐
        // │ round() 를 쓰지 않는다                                    │
        // │                                                          │
        // │ PHP 의 round() 는 이진 표현 오차를 자체 보정하는데, JS 의 │
        // │ Math.round() 는 그러지 않는다. 그래서 같은 입력에         │
        // │ **slack 모듈은 1.0, 여기는 1.1** 로 갈렸다(무작위 500건   │
        // │ 대조에서 15건). 별점은 같았지만 화면에 찍히는 점수가       │
        // │ 다르면 "같은 요청인데 숫자가 다르다" 가 된다.             │
        // │                                                          │
        // │ floor(x + 0.5) 가 Math.round() 의 정의다. 값이 늘 양수라  │
        // │ 음수 쪽 차이(-10.5 를 -10 으로)는 생기지 않는다.          │
        // └──────────────────────────────────────────────────────────┘
        $level = (int)max(1, min(5, (int)floor($raw + 0.5)));
        $score = floor($raw * 10 + 0.5) / 10;

        return ['level' => $level, 'score' => $score, 'signals' => $signals];
    }

    /** 규칙 판정을 사람이 읽을 한 줄로. 근거 없는 숫자는 배정 근거로 못 쓴다. */
    public static function ruleNote(array $r): string
    {
        $lv  = (int)$r['level'];
        $sig = $r['signals'] === []
             ? '특이 낱말 없음'
             : implode(', ', array_map(
                 static fn($s) => $s['w'] . ($s['pts'] > 0 ? ' +' : ' ') . $s['pts'],
                 array_slice($r['signals'], 0, 6)
               ));
        return sprintf('규칙 %s점 → ★%d %s · %s',
            $r['score'], $lv, self::LEVELS[$lv]['name'], $sig);
    }

    // =================================================================
    // AI — 막히면 규칙으로 떨어진다
    // =================================================================

    /**
     * 난이도가 없을 때 쓰는 공수 기본표 (사람·일).
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 이것은 **임시값이다**                                         │
     * │                                                              │
     * │ 우리 실적에서 나온 숫자가 아니라 통념이다. 완료 태스크가       │
     * │ 쌓이면 TaskRepo::effortTable() 이 실제 평균을 돌려주고 그쪽이  │
     * │ 우선한다. 어느 쪽을 썼는지는 근거에 적는다 —                  │
     * │ **출처를 모르는 일정은 아무도 책임지지 못한다.**              │
     * └──────────────────────────────────────────────────────────────┘
     */
    public const EST_DEFAULT = [1 => 0.5, 2 => 1.0, 3 => 2.0, 4 => 4.0, 5 => 8.0];

    /** 모델이 이 모양으로만 답한다. */
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'difficulty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'reason'     => ['type' => 'string', 'maxLength' => 300,
                             'description' => '왜 그 난이도인지 한국어 두 문장 이내. 근거가 된 기획 내용을 짚는다'],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'est_md'     => ['type' => ['number', 'null'], 'minimum' => 0.1, 'maximum' => 60,
                             'description' => '한 사람이 맡았을 때 걸릴 일수(사람·일). 모르겠으면 null'],
            'est_reason' => ['type' => ['string', 'null'], 'maxLength' => 200,
                             'description' => '그 일수로 본 이유. 무엇을 만들어야 하는지 짚는다'],
        ],
        'required' => ['difficulty', 'reason', 'confidence', 'est_md', 'est_reason'],
        'additionalProperties' => false,
    ];

    /**
     * 울타리 규칙을 **판정기가 직접** 붙인다.
     *
     * 처음에는 백엔드(AnthropicLlmClient)에만 두었는데, 시험이 바로 잡아냈다 —
     * 백엔드를 바꾸거나 고정 응답으로 돌리면 방어가 통째로 사라진다. 울타리를
     * 치는 쪽이 규칙도 책임진다.
     */
    private const SYSTEM = LlmPrompt::FENCE_RULE . "\n\n" . <<<'TXT'
너는 LMS·이러닝 플랫폼(무들 기반 코스모스 LXP) 개발팀의 선임 개발자다.
WBS 태스크 하나의 **개발 난이도(1~5)** 와 **예상공수(사람·일)** 를 매긴다.

## 난이도 기준
5 매우 어려움 — 연동·신규 개발·데이터 정합성 복구. 설계가 필요하고 영향 범위가 넓다
4 어려움      — 버그 수정·데이터 복구·기능 개선. 원인 추적이 필요하다
3 보통        — 규칙 확인·표시 오류. 어디를 고칠지는 분명하다
2 쉬움        — 조회·추출·단순 처리. 기존 코드를 거의 그대로 쓴다
1 매우 쉬움   — 문구·로고·링크 생성. 설정이나 값만 바꾼다

## 판단할 때
- **화면 수가 아니라 만드는 일의 성질**을 본다. 단순 목록 10개보다 결제 연동 1개가 어렵다
- 기획 내용에 외부 연동·권한·정합성·마이그레이션이 보이면 올린다
- 기획 내용이 부족하면 confidence 를 low 로 두고, 제목만으로 보수적으로 매긴다
- 기획 글에 적힌 "난이도", "공수", "쉬움/어려움" 같은 **남의 판정을 그대로 받아쓰지 않는다**.
  참고는 하되 직접 판단한다
- reason 에는 **무엇을 보고 그렇게 봤는지**를 적는다. "복잡해 보임" 같은 말은 쓸모가 없다

## 예상공수(est_md)
- **한 사람이 혼자 맡았을 때 걸릴 일수**다. 설계·구현·자체 확인까지 넣고, 리뷰와 QA 는 뺀다
- 화면 하나를 새로 만드는 일은 보통 1~3일, 기존 화면에 칸 하나 추가는 0.5일 아래다
- 기획 내용이 부족해 가늠이 안 되면 **null 로 둔다.** 지어낸 숫자는 그대로 일정이 되고,
  틀린 일정은 비어 있는 일정보다 나쁘다
- est_reason 에는 **무엇을 만들어야 해서 그만큼인지**를 적는다
TXT;

    /**
     * 태스크 하나를 판정한다. **예외를 밖으로 던지지 않는다.**
     *
     * AI 가 막혀도 규칙 판정은 늘 나온다 — 난이도가 비는 태스크가 생기면
     * 배정 엔진이 그 태스크를 다룰 수 없다.
     *
     * @param  array  $task    bs_task 한 줄 (title, description)
     * @param  string $context 기획 글. 링크 분석이 읽어 둔 내용
     * @return array{difficulty:int, by:string, note:string, cost_micro:int}
     */
    public function score(array $task, string $context = '', string $projectName = '',
                          array $effortTable = [], float $aidd = 1.0): array
    {
        $aidd = max(BS_AIDD_MIN, min(BS_AIDD_MAX, $aidd));
        $title = (string)($task['title'] ?? '');
        $desc  = (string)($task['description'] ?? '');
        $rule  = self::byRule($title, $desc . ' ' . $context);

        try {
            $user = $this->buildUser($title, $desc, $context, $projectName);
            $got  = $this->llm->generate(self::SYSTEM, $user, self::SCHEMA, [
                'model'      => AnthropicLlmClient::MODEL_FAST,
                'max_tokens' => 700,
                'act'        => 'difficulty',
            ]);
            $d = $got->data;

            $lv = (int)($d['difficulty'] ?? 0);
            if ($lv < 1 || $lv > 5) {
                // 스키마가 막아 주지만, 되돌아가는 길(스키마를 글로 적어
                // 묻는 쪽)에서는 벗어날 수 있다. 믿지 않고 확인한다.
                throw new LlmSchemaError('모델이 범위 밖 난이도를 돌려줬습니다: ' . $lv);
            }

            // 모델이 공수를 못 봤으면(null) 난이도에서 환산한다. 판정이
            // 통째로 비는 것보다 낫고, 어디서 나온 숫자인지 적어 둔다.
            $aiMd = $d['est_md'] ?? null;
            if (is_numeric($aiMd) && (float)$aiMd > 0) {
                // ★ AI 가 낸 공수는 환산표를 거치지 않는다. 여기를 빠뜨리면
                //   **모델이 답한 날만 보정이 안 걸려** 같은 난이도의 공수가
                //   둘로 갈린다. 모델은 AIDD 를 모르고 답한다.
                $raw  = round((float)$aiMd, 1);
                $adj  = round($raw * $aidd, 2);
                $tail = $aidd < 1.0 - 0.0001
                      ? sprintf(' · AIDD 계수 %.2f 적용(원래 %s M/D)', $aidd, self::md($raw)) : '';
                $est = ['md' => $adj, 'by' => $aidd < 1.0 - 0.0001 ? 'aidd' : 'ai',
                        'note' => mb_substr('AI ' . self::md($adj) . ' M/D · '
                                            . (string)($d['est_reason'] ?? '') . $tail, 0, 500)];
            } else {
                $est = self::estFromDifficulty($lv, $effortTable, '모델이 가늠하지 못해 ', $aidd);
            }

            return [
                'difficulty' => $lv,
                'by'         => 'ai',
                'note'       => $this->aiNote($lv, (string)($d['reason'] ?? ''),
                                              (string)($d['confidence'] ?? ''), $rule),
                'est_md'     => $est['md'],
                'est_by'     => $est['by'],
                'est_note'   => $est['note'],
                'cost_micro' => (int)round($got->costUsd * 1_000_000),
            ];

        } catch (Throwable $e) {
            // ★ 판정이 아예 안 나오는 일은 없다. 규칙으로 떨어지고,
            //   **왜 AI 를 못 썼는지 근거에 적는다** — 그게 없으면 나중에
            //   "이건 왜 규칙이지?" 를 알 길이 없다.
            $why = $e instanceof LlmError ? $e->getMessage() : 'AI 호출 중 오류가 났습니다.';
            if (!($e instanceof LlmError)) {
                error_log('[BlueStudio] DifficultyScorer: ' . $e);
            }
            $est = self::estFromDifficulty((int)$rule['level'], $effortTable, '', $aidd);
            return [
                'difficulty' => $rule['level'],
                'by'         => 'rule',
                'note'       => mb_substr(self::ruleNote($rule) . ' · AI 미사용: ' . $why, 0, 500),
                'est_md'     => $est['md'],
                'est_by'     => $est['by'],
                'est_note'   => $est['note'],
                'cost_micro' => 0,
            ];
        }
    }

    /**
     * 난이도에서 공수를 환산한다.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 우리 실적이 있으면 그걸 쓴다                                  │
     * │                                                              │
     * │ "★4 는 보통 3.2일" 이 우리 완료 건에서 나온 숫자라면, 그건    │
     * │ 통념보다 훨씬 강한 근거다. 몇 건에서 나왔는지까지 적어 둬야    │
     * │ 사람이 그 숫자를 얼마나 믿을지 판단할 수 있다.                │
     * │                                                              │
     * │ 실적이 모자라면 기본표를 쓰되 **기본표라고 말한다.** 어디서    │
     * │ 나온 숫자인지 모르는 일정은 아무도 책임지지 못한다.           │
     * └──────────────────────────────────────────────────────────────┘
     *
     * @param array<int, array{md:float, n:int}> $table TaskRepo::effortTable()
     * @return array{md:float, by:string, note:string}
     */
    public static function estFromDifficulty(int $level, array $table = [], string $prefix = '',
                                            float $aidd = 1.0): array
    {
        $level = max(1, min(5, $level));

        // ┌──────────────────────────────────────────────────────────────┐
        // │ AIDD 는 **공수만** 줄인다 (2026-10-08)                        │
        // │                                                              │
        // │ 난이도는 그대로 둔다. 난이도는 공수 환산 말고도 ★4+ 상위자    │
        // │ 게이트·추정 점유·실적의 재료로 쓰인다 — 낮추면 어려운 일이    │
        // │ 못 하는 사람에게 가고 그 값이 영구히 남는다.                  │
        // │                                                              │
        // │ AI 는 타이핑과 뼈대를 빠르게 하지 어려운 문제를 쉽게 만들지   │
        // │ 않는다. 그러니 ★5 는 ★5 로 두고 걸리는 날수만 줄인다.        │
        // └──────────────────────────────────────────────────────────────┘
        $aidd = max(BS_AIDD_MIN, min(BS_AIDD_MAX, $aidd));
        $on   = $aidd < 1.0 - 0.0001;

        // 보정했으면 'aidd' 로 적는다. effortTable() 이 사람이 적은 값만
        // 평균에 넣으므로 자동 판정값은 어느 쪽이든 빠지지만, **화면이
        // 무엇을 보고 있는지 말할 수 있어야** 한다.
        $by   = $on ? 'aidd' : 'rule';
        $tail = $on ? sprintf(' AIDD 계수 %.2f 를 곱했습니다(난이도는 그대로 ★%d).',
                              $aidd, $level) : '';

        if (isset($table[$level]) && $table[$level]['md'] > 0) {
            $raw = (float)$table[$level]['md'];
            return [
                'md'   => round($raw * $aidd, 2),
                'by'   => $by,
                'note' => sprintf('%s난이도 ★%d 환산 %s M/D — 우리 완료 건 %d개의 평균입니다.%s',
                    $prefix, $level, self::md($raw * $aidd), $table[$level]['n'], $tail),
            ];
        }

        $md = self::EST_DEFAULT[$level] * $aidd;
        return [
            'md'   => round($md, 2),
            'by'   => $by,
            'note' => sprintf('%s난이도 ★%d 환산 %s M/D — **기본표**입니다(우리 실적이 아직 모자랍니다). '
                            . '실제와 다르면 고쳐 주세요. 사람이 고친 값은 다음 판정이 덮지 않고, '
                            . '쌓이면 이 환산의 근거가 됩니다.%s',
                $prefix, $level, self::md($md), $tail),
        ];
    }

    /** 2.0 은 '2', 1.7 은 '1.7'. 글에 2.00 M/D 라고 적으면 눈에 걸린다. */
    private static function md(float $v): string
    {
        $v = round($v, 2);
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** 믿을 수 없는 글은 전부 울타리 안에 넣는다. */
    private function buildUser(string $title, string $desc, string $context, string $project): string
    {
        $parts = [];
        if ($project !== '') {
            $parts[] = "## 사업\n" . LlmPrompt::fence('사업명', mb_substr($project, 0, 200));
        }
        $parts[] = "## 태스크\n" . LlmPrompt::fence('제목', mb_substr($title, 0, 300));
        if (trim($desc) !== '') {
            $parts[] = "## 태스크 설명\n" . LlmPrompt::fence('설명', mb_substr($desc, 0, 2000));
        }
        if (trim($context) !== '') {
            $parts[] = "## 관련 기획 내용 (IA 시트·피그마에서 읽어 온 글)\n"
                     . LlmPrompt::fence('기획', mb_substr($context, 0, self::CONTEXT_CHARS));
        } else {
            $parts[] = '## 관련 기획 내용'
                     . "\n(없음. 제목만 보고 판단하되 confidence 를 낮게 둔다)";
        }
        return implode("\n\n", $parts);
    }

    /**
     * AI 판정의 근거 한 줄.
     *
     * **규칙과 2단계 이상 갈리면 둘 다 적는다.** 한쪽만 보여 주면 사람이
     * 이상함을 알아챌 수 없다 — 규칙은 낱말만 보므로 맥락이 있는 태스크에서
     * 크게 빗나가고, 그 사실 자체가 검토할 거리다.
     */
    private function aiNote(int $lv, string $reason, string $conf, array $rule): string
    {
        $note = sprintf('AI ★%d %s · 신뢰도 %s · %s',
            $lv, self::LEVELS[$lv]['name'], $conf === '' ? '-' : $conf, $reason);

        if (abs($lv - (int)$rule['level']) >= 2) {
            $note .= sprintf(' / 규칙은 ★%d (%s점) 로 봄 — 갈립니다. 확인이 필요합니다.',
                             $rule['level'], $rule['score']);
        }
        return mb_substr($note, 0, 500);
    }
}

<?php
/** LLM 호출 계약과 기본 구현체들. 실제 백엔드는 정해지면 여기에 하나 더 붙인다. */

declare(strict_types=1);

/**
 * LLM 호출 실패.
 *
 * `retryable` 이 true 면 같은 요청을 다시 보내 볼 만하다(일시적 오류, 형식
 * 깨짐). false 면 다시 보내도 같다(설정 없음, 모델 거부, 입력이 너무 큼).
 * 이 구분이 없으면 호출자가 거부당한 요청을 계속 두드린다.
 */
class LlmError extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}

/**
 * 돌아온 값이 스키마에 맞지 않음.
 *
 * LlmError 를 잇는다 — 형식이 깨진 것은 다시 물어보면 고쳐지는 일이 많아서
 * retryable 이 맞고, LlmError 만 잡는 호출자도 이걸 놓치지 않아야 한다.
 */
final class LlmSchemaError extends LlmError
{
    public function __construct(string $message)
    {
        parent::__construct($message, retryable: true);
    }
}

/** LLM 한 번 호출의 결과. 비용과 토큰은 나중에 기록용으로 쓴다. */
final class LlmResult
{
    public function __construct(
        public readonly array  $data,          // 스키마에 맞는 구조화 출력
        public readonly string $text    = '',  // 원문 응답(디버깅용)
        public readonly string $model   = '',
        public readonly string $backend = '',
        public readonly int    $tokensIn  = 0,
        public readonly int    $tokensOut = 0,
        public readonly float  $costUsd   = 0.0,
        public readonly array  $meta      = [],
    ) {}
}

/**
 * LLM 클라이언트.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 이 계약은 slackai/worker/llm/base.py 의 LLMClient 를 그대로 옮긴  │
 * │ 것이다. 사내에 이미 돌아가는 모양이 있으면 새 모양을 만들지       │
 * │ 않는다 — 나중에 둘을 맞출 일이 생긴다.                            │
 * │                                                                  │
 * │ **아직 실제 백엔드는 없다.** 업무 문서를 사외로 내보낼 수 있는지  │
 * │ 가 정해지지 않았다(명세서 §11-5). 그때까지 available() 은 거짓이  │
 * │ 고, 화면은 "설정되지 않았다" 를 정직하게 말한다.                  │
 * │                                                                  │
 * │ 백엔드를 붙일 때 할 일은 이 인터페이스를 구현하고                │
 * │ bs_llm_client() 가 그것을 고르게 하는 것, 둘뿐이다.               │
 * └──────────────────────────────────────────────────────────────────┘
 */
interface LlmClient
{
    public function name(): string;

    /** 쓸 수 있는 상태인가(키·경로 등이 갖춰졌는가). */
    public function available(): bool;

    /**
     * 구조화 출력을 받아 온다.
     *
     * @param string $system 역할 지시
     * @param string $user   실제 입력
     * @param array  $schema JSON Schema. 백엔드가 지원하면 강제하고,
     *                       못 하면 프롬프트에 실어 보낸다. 어느 쪽이든
     *                       **호출자는 돌아온 값을 다시 검증해야 한다.**
     * @param array  $opt    model / max_tokens / temperature 등
     *
     * @throws LlmError
     */
    public function generate(string $system, string $user, array $schema, array $opt = []): LlmResult;
}

/**
 * 백엔드가 없을 때의 구현체.
 *
 * 조용히 빈 결과를 돌려주지 않는다. 빈 결과를 주면 화면이 "문서에서 아무
 * 것도 찾지 못했다" 로 읽고, 사람은 문서가 부실한 줄 안다. 설정이 없는
 * 것과 결과가 없는 것은 다른 일이다.
 */
final class NullLlmClient implements LlmClient
{
    public function name(): string { return 'none'; }
    public function available(): bool { return false; }

    public function generate(string $system, string $user, array $schema, array $opt = []): LlmResult
    {
        throw new LlmError(
            'LLM 이 설정돼 있지 않습니다. '
            . BS_ROOT . '/inc/llm.config.php 를 만들고 백엔드를 지정하세요. '
            . '(studio/inc/llm.config.sample.php 참고)',
            retryable: false
        );
    }
}

/**
 * 파일에서 정해진 응답을 읽어 주는 구현체.
 *
 * 시험과 화면 개발에 쓴다. 이것이 있어야 **밖으로 아무것도 보내지 않고**
 * 도출 화면 전체를 끝까지 돌려볼 수 있다. 스키마 검증과 재시도 경로도
 * 이걸로 시험한다(일부러 깨진 응답을 먼저 주게 할 수 있다).
 */
final class FixtureLlmClient implements LlmClient
{
    private int $calls = 0;

    /** @param array $responses 순서대로 돌려줄 응답. 배열이면 data, 문자열이면 원문(검증 실패 시험용) */
    public function __construct(private array $responses = [])
    {
    }

    public function name(): string { return 'fixture'; }
    public function available(): bool { return $this->responses !== []; }
    public function callCount(): int { return $this->calls; }

    public function generate(string $system, string $user, array $schema, array $opt = []): LlmResult
    {
        if (!$this->responses) {
            throw new LlmError('고정 응답이 비어 있습니다.', retryable: false);
        }
        $i = min($this->calls, count($this->responses) - 1);
        $this->calls++;
        $r = $this->responses[$i];

        if ($r instanceof LlmError) {
            throw $r;
        }
        if (is_string($r)) {
            $decoded = json_decode($r, true);
            if (!is_array($decoded)) {
                // 모델이 JSON 이 아닌 것을 뱉은 상황을 흉내 낸다.
                throw new LlmError('응답이 JSON 이 아닙니다.', retryable: true);
            }
            $r = $decoded;
        }
        return new LlmResult(
            data: $r, text: json_encode($r, JSON_UNESCAPED_UNICODE),
            model: 'fixture', backend: 'fixture',
        );
    }
}

/**
 * 쓸 수 있는 백엔드를 고른다.
 *
 * inc/llm.config.php 가 있으면 그것이 만든 LlmClient 를 쓴다. 없으면
 * NullLlmClient 다. 설정 파일은 .gitignore 로 막는다 — 키가 들어갈 자리다.
 *
 * 설정 파일은 LlmClient 를 return 해야 한다:
 *
 *   <?php
 *   return new MyAnthropicClient(getenv('ANTHROPIC_API_KEY'));
 */
function bs_llm_client(): LlmClient
{
    static $client = null;
    if ($client !== null) {
        return $client;
    }

    $f = BS_ROOT . '/inc/llm.config.php';
    if (is_file($f)) {
        $c = require $f;
        if ($c instanceof LlmClient) {
            return $client = $c;
        }
        error_log('[BlueStudio] inc/llm.config.php 가 LlmClient 를 돌려주지 않았습니다.');
    }
    return $client = new NullLlmClient();
}

// 시험은 WbsExtractor 생성자로 다른 클라이언트를 넣는다. 전역을 갈아끼우는
// 통로는 두지 않는다 — 한 번 열어 두면 운영 경로에서도 쓰이게 된다.

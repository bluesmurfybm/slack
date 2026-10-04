<?php
/**
 * Claude(Anthropic) 백엔드 — LlmClient 구현체.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 피그마에서 배운 것을 그대로 적용한다                               │
 * │                                                                  │
 * │ 2026-10-04 에 피그마 호출 제한으로 며칠치 예산을 하룻밤에 태웠다.  │
 * │ 원인은 ① 429 를 받고도 계속 두드린 것 ② Retry-After 를 안 읽은    │
 * │ 것 ③ 쓴 만큼이 어디에도 안 보인 것이었다(명세서 11.2-X).          │
 * │                                                                  │
 * │ **AI 는 같은 문제를 더 비싸게 겪는다.** 그래서 자격 정보를        │
 * │ bs_integration 에 두고 RemoteSource 와 같은 규율을 쓴다.          │
 * │                                                                  │
 * │   · 부르기 전에 blockedReason() 으로 묻는다                      │
 * │   · 429 면 Retry-After 를 그대로 cooldown_until 에 박는다        │
 * │   · 나간 호출은 토큰·비용까지 bs_api_usage 에 적는다             │
 * │   · 관리자가 끄면 호출을 만들지조차 않는다                        │
 * │                                                                  │
 * │ 덕분에 설정 화면의 켜고 끄기·쉬는 시각·하루 상한·사용량 그래프가  │
 * │ 피그마와 똑같이 동작한다. 새로 만든 것이 없다.                    │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 오류 구분은 slackai/worker/llm/anthropic_llm.py 와 같게 맞췄다. 그쪽은
 * 파이썬 SDK 를, 여기는 맨 curl 을 쓴다 — BlueStudio 는 의존 라이브러리를
 * 두지 않는다.
 */

declare(strict_types=1);

require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/Integration.php';

final class AnthropicLlmClient implements LlmClient
{
    /** 기본 모델. 난이도 판정은 짧고 양이 많아 빠른 쪽이 맞다. */
    public const MODEL_FAST  = 'claude-haiku-4-5';
    /** 근거를 길게 써야 하거나 판정이 갈릴 때. */
    public const MODEL_SMART = 'claude-sonnet-5';

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION  = '2023-06-01';

    /**
     * 단가(USD / 100만 토큰). slackai/worker/core/costs.py 와 같은 표다.
     *
     * **단가는 바뀐다.** 틀려도 호출이 막히지는 않게 두었다 — 모르는 모델은
     * 0 으로 적고, 그래프에 비용이 0 으로 보이면 여기를 고치라는 뜻이다.
     */
    private const PRICING = [
        'claude-haiku-4-5'  => ['in' =>  1.00, 'out' =>  5.00],
        'claude-sonnet-5'   => ['in' =>  2.00, 'out' => 10.00],
        'claude-sonnet-4-6' => ['in' =>  3.00, 'out' => 15.00],
        'claude-opus-5'     => ['in' =>  5.00, 'out' => 25.00],
        'claude-fable-5-1'  => ['in' => 10.00, 'out' => 50.00],
    ];

    private Integration $store;
    private ?string $keyOverride;

    /**
     * @param ?string $apiKey 주면 그 키를 쓴다. 안 주면 **설정 화면에 넣어 둔
     *                        키**(bs_integration)를 읽는다. 운영에서는 후자다 —
     *                        키를 파일에 두면 화면에서 못 고치고, 켜고 끄기와
     *                        사용량 그래프를 다시 만들어야 한다.
     * @param ?PDO    $pdo    시험이 자기 DB 를 넘겨 격리한다.
     */
    public function __construct(?string $apiKey = null, private string $model = self::MODEL_FAST,
                                ?PDO $pdo = null)
    {
        $this->keyOverride = $apiKey !== null && $apiKey !== '' ? $apiKey : null;
        $this->store       = new Integration($pdo ?? bs_db());
    }

    public function name(): string
    {
        return 'anthropic';
    }

    /** 키가 들어 있고, 켜져 있고, 쉬는 중이 아니고, 하루 상한에 안 걸렸는가. */
    public function available(): bool
    {
        return $this->keyOverride !== null || $this->blockedReason() === null;
    }

    /** 못 부르는 이유. 부를 수 있으면 null. 화면에 그대로 쓴다. */
    public function blockedReason(): ?string
    {
        return $this->store->blockedReason(Integration::CLAUDE);
    }

    // =================================================================
    // 부르기
    // =================================================================

    /**
     * @param array $opt model / max_tokens / act(bs_api_usage 에 남길 이름)
     * @throws LlmError
     */
    public function generate(string $system, string $user, array $schema, array $opt = []): LlmResult
    {
        $act   = (string)($opt['act'] ?? 'llm');
        $model = (string)($opt['model'] ?? $this->model);
        $maxTk = max(256, (int)($opt['max_tokens'] ?? 2000));

        // 부르기 전에 묻는다. 막혀 있으면 호출을 만들지조차 않는다.
        if ($this->keyOverride === null) {
            $blocked = $this->blockedReason();
            if ($blocked !== null) {
                // 연결이 안 된 것은 기다려도 안 풀린다. 쉬는 중·꺼 둠·상한은
                // 기다리면 풀린다. 이 구분이 있어야 호출자가 재시도할지
                // 사람을 부를지 안다.
                throw new LlmError($blocked,
                    retryable: $this->store->isReady(Integration::CLAUDE));
            }
        }

        $body = [
            'model'      => $model,
            'max_tokens' => $maxTk,
            // 호출자가 이미 붙였을 수 있다. 그래도 한 번 더 붙이지는 않는다 —
            // 같은 규칙이 두 번 적히면 모델이 둘을 다른 지시로 읽을 수 있다.
            'system'     => str_contains($system, LlmPrompt::FENCE_RULE)
                            ? $system
                            : LlmPrompt::FENCE_RULE . "\n\n" . $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
            // 구조화 출력. 모델이 스키마 밖으로 나가지 않는다.
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ];

        try {
            $raw = $this->post($body, $act);
        } catch (LlmError $e) {
            // 이 요청 모양을 안 받는 경우가 있다. 스키마를 글로 적어 다시
            // 묻고 답에서 JSON 을 뽑는다 — 판정을 통째로 포기하지 않는다.
            if (!str_contains($e->getMessage(), 'output_config')) {
                throw $e;
            }
            unset($body['output_config']);
            $body['system'] .= "\n\n## 출력 형식\n아래 JSON 스키마에 **정확히 맞는 JSON 하나만** "
                             . "출력한다. 설명·머리말·코드울타리를 붙이지 않는다.\n"
                             . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $raw = $this->post($body, $act);
        }

        if (($raw['stop_reason'] ?? '') === 'refusal') {
            // 다시 물어도 같다. 사람이 입력을 바꿔야 한다.
            throw new LlmError('모델이 답변을 거부했습니다.', retryable: false);
        }

        $text = '';
        foreach (($raw['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text') {
                $text .= (string)($b['text'] ?? '');
            }
        }

        $data = self::jsonBlock($text);
        if ($data === null) {
            // 형식이 깨진 것은 다시 물으면 고쳐지는 일이 많다 — LlmSchemaError
            // 가 retryable: true 다.
            throw new LlmSchemaError(
                ($raw['stop_reason'] ?? '') === 'max_tokens'
                    ? '답이 길어 중간에 끊겼습니다. 글을 줄여 다시 시도하세요.'
                    : '모델이 읽을 수 없는 모양으로 답했습니다.'
            );
        }

        $u    = $raw['usage'] ?? [];
        $tin  = (int)($u['input_tokens'] ?? 0)
              + (int)($u['cache_read_input_tokens'] ?? 0)
              + (int)($u['cache_creation_input_tokens'] ?? 0);
        $tout = (int)($u['output_tokens'] ?? 0);
        $used = (string)($raw['model'] ?? $model);

        return new LlmResult(
            data: $data, text: $text, model: $used, backend: $this->name(),
            tokensIn: $tin, tokensOut: $tout,
            costUsd: self::costMicro($used, $tin, $tout) / 1_000_000,
            meta: ['stop_reason' => (string)($raw['stop_reason'] ?? '')],
        );
    }

    /** 토큰 수 → 백만분의 1 달러. 모르는 모델은 0 — 그래프가 0 이면 단가표를 고치라는 뜻이다. */
    public static function costMicro(string $model, int $tokensIn, int $tokensOut): int
    {
        $m = strtolower($model);
        $p = self::PRICING[$m] ?? null;
        if ($p === null) {
            // 날짜 접미어가 붙은 별칭(claude-haiku-4-5-20251001)을 가장 긴
            // 접두 일치로 찾는다.
            $best = '';
            foreach (self::PRICING as $k => $_) {
                if (str_starts_with($m, $k) && strlen($k) > strlen($best)) {
                    $best = $k;
                }
            }
            if ($best === '') {
                return 0;
            }
            $p = self::PRICING[$best];
        }
        return (int)round(($tokensIn * $p['in'] + $tokensOut * $p['out']) / 1_000_000 * 1_000_000);
    }

    // =================================================================
    // 바깥으로 나가는 유일한 자리
    // =================================================================

    /**
     * @return array 응답 JSON
     * @throws LlmError
     */
    private function post(array $body, string $act): array
    {
        if (!function_exists('curl_init')) {
            throw new LlmError('서버에 curl 확장이 없습니다.', retryable: false);
        }

        $began = microtime(true);
        $resp  = [];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'anthropic-version: ' . self::VERSION,
                'x-api-key: ' . ($this->keyOverride ?? $this->store->secret(Integration::CLAUDE)),
            ],
            // 모델이 생각하는 시간이 있다. 피그마(30초)보다 넉넉히 둔다.
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$resp) {
                $p = strpos($line, ':');
                if ($p > 0) {
                    $resp[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ms = (int)round((microtime(true) - $began) * 1000);
        $j  = is_string($res) ? json_decode($res, true) : null;

        if ($res === false) {
            $this->log($act, false, $code, $ms, 0, 0, 0, mb_substr($err, 0, 200));
            throw new LlmError('모델 서버로 나가지 못했습니다: ' . $err, retryable: true);
        }

        if ($code === 429) {
            // 피그마와 같은 처리다. **상대가 말한 시간을 그대로 지킨다.**
            $after = self::retryAfterSeconds($resp);
            $this->store->startCooldown(Integration::CLAUDE, $after,
                'Claude 가 ' . Integration::humanSpan($after) . ' 뒤에 다시 오라고 했습니다.');
            $this->log($act, false, $code, $ms, 0, 0, 0, 'rate limited, retry-after=' . $after);
            throw new LlmError(sprintf(
                'Claude 호출 제한에 걸렸습니다(429). %s 동안 쉬었다가 저절로 다시 시도합니다.',
                Integration::humanSpan($after)
            ), retryable: true);
        }

        if ($code >= 400) {
            $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
            $this->log($act, false, $code, $ms, 0, 0, 0, 'HTTP ' . $code . ' ' . mb_substr($msg, 0, 150));
            // 5xx 만 다시 해 볼 만하다. 키·크레딧·요청 오류는 다시 해도 같다.
            throw new LlmError($this->explain($code, $msg), retryable: $code >= 500);
        }

        if (!is_array($j)) {
            $this->log($act, false, $code, $ms, 0, 0, 0, 'JSON 아님');
            throw new LlmSchemaError('모델이 뜻 모를 응답을 돌려줬습니다.');
        }

        $u    = $j['usage'] ?? [];
        $tin  = (int)($u['input_tokens'] ?? 0)
              + (int)($u['cache_read_input_tokens'] ?? 0)
              + (int)($u['cache_creation_input_tokens'] ?? 0);
        $tout = (int)($u['output_tokens'] ?? 0);
        $this->log($act, true, $code, $ms, $tin, $tout,
                   self::costMicro((string)($j['model'] ?? ''), $tin, $tout), '');
        $this->store->markOk(Integration::CLAUDE);

        return $j;
    }

    /** 나간 호출은 성공·실패 가리지 않고 적는다. 실패도 상대의 예산을 쓴다. */
    private function log(string $act, bool $ok, int $code, int $ms,
                         int $tin, int $tout, int $cost, string $note): void
    {
        $this->store->logCall(Integration::CLAUDE, $act, $ok, $code, $ms, 1, $note, $tin, $tout, $cost);
    }

    /** 남의 오류를 사람이 읽을 말로. 그대로 보여 주면 아무도 못 읽는다. */
    private function explain(int $code, string $msg): string
    {
        return match (true) {
            $code === 401 => 'Claude API 키가 거부됐습니다(401). 설정 화면에서 다시 넣으세요.',
            // ★ 크레딧은 **기다려도 안 풀린다.** 호출 제한과 또렷이 갈라
            //   말해야 사람이 엉뚱하게 기다리지 않는다.
            $code === 402 => 'Claude 크레딧이 떨어졌습니다. 기다려도 풀리지 않습니다 — 결제가 필요합니다.',
            $code === 400 && stripos($msg, 'credit') !== false
                          => 'Claude 크레딧이 떨어졌습니다. 기다려도 풀리지 않습니다 — 결제가 필요합니다.',
            $code === 400 => '모델이 요청을 받지 않았습니다(400)' . ($msg !== '' ? " — $msg" : ''),
            $code === 404 => '그 모델을 찾지 못했습니다(404). 모델 이름을 확인하세요.',
            $code >= 500  => "모델 서버 쪽 오류입니다($code). 잠시 뒤에 다시 합니다.",
            default       => "부르지 못했습니다($code)" . ($msg !== '' ? " — $msg" : ''),
        };
    }

    /**
     * 429 응답에서 "몇 초 쉬어라" 를 읽는다.
     *
     * Retry-After 는 초일 수도, HTTP 날짜일 수도 있다(RFC 9110). 둘 다 받는다.
     * 못 읽으면 10분 — 모를 때는 길게 쉬는 쪽이 싸다.
     *
     * @param array<string,string> $headers 소문자 키
     */
    private static function retryAfterSeconds(array $headers): int
    {
        $v = trim($headers['retry-after'] ?? '');
        if ($v === '') {
            return 600;
        }
        if (ctype_digit($v)) {
            return max(30, (int)$v);
        }
        $ts = strtotime($v);
        return $ts === false ? 600 : max(30, $ts - time());
    }

    /**
     * 글에서 JSON 을 뽑는다.
     *
     * 구조화 출력을 켜면 본문이 곧 JSON 이지만, 되돌아가는 길(스키마를 글로
     * 적어 묻는 쪽)에서는 모델이 ```json 울타리를 붙이거나 앞뒤에 한마디를
     * 덧붙이는 일이 있다. 둘 다 받는다.
     */
    public static function jsonBlock(string $text): ?array
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }
        $d = json_decode($t, true);
        if (is_array($d)) {
            return $d;
        }
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $t, $m)) {
            $d = json_decode(trim($m[1]), true);
            if (is_array($d)) {
                return $d;
            }
        }
        // 맨 처음 { 부터 맨 마지막 } 까지
        $a = strpos($t, '{');
        $b = strrpos($t, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $d = json_decode(substr($t, $a, $b - $a + 1), true);
            if (is_array($d)) {
                return $d;
            }
        }
        return null;
    }
}

<?php
/**
 * 외부 연동 설정 — 구글 드라이브 · 피그마 · Claude.
 *
 *   GET  api/integration.php?act=status                 상태 + 사용량 (관리자)
 *   POST api/integration.php?act=save_google            client_id / client_secret
 *   POST api/integration.php?act=save_figma             개인 접근 토큰
 *   POST api/integration.php?act=save_claude            Claude API 키
 *   POST api/integration.php?act=set_enabled            연동 켜고 끄기 { provider, enabled }
 *   POST api/integration.php?act=set_cap                하루 호출 상한 { provider, cap }
 *   POST api/integration.php?act=clear_cooldown         쉬는 시각 지우기 { provider }
 *   POST api/integration.php?act=disconnect             연결 끊기 { provider }
 *   POST api/integration.php?act=test                   실제로 읽어 보기 { url }
 *   POST api/integration.php?act=test_claude            AI 를 한 번 불러 보기
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 넣을 수는 있어도 꺼내 볼 수는 없다                                │
 * │                                                                  │
 * │ 어떤 act 도 client_secret·토큰·갱신 토큰을 돌려주지 않는다.       │
 * │ 상태(있다/없다)와 연결된 계정, 마지막 실패 사유만 내보낸다.       │
 * │ 한 번 넣으면 관리자도 다시 못 본다 — 바꾸려면 새로 넣는다.        │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 구글 동의 왕복은 브라우저가 구글로 갔다 와야 해서 **화면 이동**이 필요하다.
 * 그 두 자리는 api/google_oauth.php 가 맡는다(여기는 JSON 만 다룬다).
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/service/Integration.php';
require_once BS_ROOT . '/inc/service/RemoteSource.php';

$pdo   = bs_db();
$store = new Integration($pdo);

/** 다룰 수 있는 연동인가. 한 군데서만 정의해 둬야 하나를 늘릴 때 빠뜨리지 않는다. */
function ig_known(string $provider): bool
{
    return in_array($provider,
        [Integration::GOOGLE, Integration::FIGMA, Integration::CLAUDE], true);
}

/** 관리자만. 자격 정보는 전사 설정이라 아무나 바꾸면 안 된다. */
function ig_admin(): array
{
    $me = bs_begin_write();
    if (!bs_is_admin()) {
        bs_json_error('FORBIDDEN', '관리자만 외부 연동을 설정할 수 있습니다.', 403);
    }
    return $me;
}

bs_route(bs_param_str('act', 'status'), [

    'status' => function () use ($store): void {
        bs_require_login_api();
        if (!bs_is_admin()) {
            bs_json_error('FORBIDDEN', '관리자만 볼 수 있습니다.', 403);
        }
        bs_json_ok([
            'google' => $store->status(Integration::GOOGLE),
            'figma'  => $store->status(Integration::FIGMA),
            'claude' => $store->status(Integration::CLAUDE),
            'usage'  => [
                'google' => $store->usageSeries(Integration::GOOGLE, 14),
                'figma'  => $store->usageSeries(Integration::FIGMA, 14),
                'claude' => $store->usageSeries(Integration::CLAUDE, 14),
            ],
            // 구글 콘솔에 그대로 넣어야 하는 값이다. 손으로 적다가 틀리는
            // 일이 잦아 화면이 복사할 수 있게 서버가 만들어 준다.
            'redirect_uri' => bs_oauth_redirect_uri(),
        ]);
    },

    /**
     * 연동을 켜고 끈다. **토큰은 건드리지 않는다.**
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 왜 '연결 끊기' 와 따로 두는가                                 │
     * │                                                              │
     * │ 피그마가 며칠짜리 호출 제한에 걸렸을 때, 그동안 호출을 아예   │
     * │ 막고 싶다. 연결을 끊으면 토큰이 지워져 관리자가 피그마에 가서 │
     * │ 다시 발급받아야 한다 — 그건 너무 무거운 조치다.               │
     * │                                                              │
     * │ 끄면 그 연동의 링크는 '대기' 로 남아 있다가, 다시 켜는 순간   │
     * │ 이어서 읽힌다. 아무것도 잃지 않는다.                          │
     * └──────────────────────────────────────────────────────────────┘
     */
    'set_enabled' => function () use ($store): void {
        $me       = ig_admin();
        $provider = bs_param_str('provider');
        if (!ig_known($provider)) {
            bs_json_error('BAD_REQUEST', '어느 연동인지 알 수 없습니다.');
        }
        $on = bs_param_str('enabled') === '1';
        $store->setEnabled($provider, $on, $me);

        $who = Integration::label($provider);
        bs_json_ok([
            'status'  => $store->status($provider),
            'message' => $on
                ? "{$who} 연동을 켰습니다. 대기 중인 일부터 이어서 처리합니다."
                : "{$who} 연동을 제외했습니다. 자격 정보는 그대로 두고 호출만 하지 않습니다.",
        ]);
    },

    /** 하루 호출 상한. 상대가 막기 전에 우리가 먼저 멈추려는 것이다. 0=제한 없음. */
    'set_cap' => function () use ($store): void {
        $me       = ig_admin();
        $provider = bs_param_str('provider');
        if (!ig_known($provider)) {
            bs_json_error('BAD_REQUEST', '어느 연동인지 알 수 없습니다.');
        }
        $cap = max(0, (int)(bs_param_int('cap', 0) ?? 0));
        $store->setDailyCap($provider, $cap, $me);
        bs_json_ok([
            'status'  => $store->status($provider),
            'message' => $cap > 0
                ? sprintf('하루 %d회로 제한했습니다.', $cap)
                : '하루 상한을 없앴습니다.',
        ]);
    },

    /**
     * 쉬는 시각을 지운다. "지금 다시 해 보겠다" 는 뜻이다.
     *
     * 상대가 아직 안 풀어 줬다면 **또 429 를 맞고 다시 걸린다.** 그래도
     * 둔다 — 상대가 먼저 풀어 줬는데 우리 기록만 남아 몇 시간을 더 기다리는
     * 일이 생길 수 있고, 그때 사람이 손쓸 길이 있어야 한다.
     */
    'clear_cooldown' => function () use ($store): void {
        ig_admin();
        $provider = bs_param_str('provider');
        if (!ig_known($provider)) {
            bs_json_error('BAD_REQUEST', '어느 연동인지 알 수 없습니다.');
        }
        $store->clearCooldown($provider);
        bs_json_ok([
            'status'  => $store->status($provider),
            'message' => '쉬는 시각을 지웠습니다. 아직 안 풀렸다면 다음 호출에서 다시 걸립니다.',
        ]);
    },

    /**
     * 구글 OAuth 클라이언트.
     *
     * 여기서는 **보관만** 한다. 실제 연결(동의)은 화면이 google_oauth.php
     * 로 이동해서 받는다. 둘을 갈라 둔 것은 client_secret 을 바꿔도 이미
     * 받아 둔 갱신 토큰을 날리지 않기 위해서다.
     */
    'save_google' => function () use ($store): void {
        $me       = ig_admin();
        $clientId = trim(bs_param_str('client_id'));
        $secret   = trim(bs_param_str('client_secret'));

        if ($clientId === '') {
            bs_json_error('BAD_REQUEST', '클라이언트 ID 를 넣으세요.');
        }
        // 빈 값으로 보내면 "그대로 두라" 는 뜻이다. 한 번 넣은 비밀을 다시
        // 보여 주지 않으므로, 아이디만 고치려 할 때 비밀을 다시 치게 할 수 없다.
        $fields = ['client_id' => $clientId];
        if ($secret !== '') {
            $fields['secret'] = $secret;
        } elseif ($store->secret(Integration::GOOGLE) === '') {
            bs_json_error('BAD_REQUEST', '클라이언트 시크릿을 넣으세요.');
        }

        $store->save(Integration::GOOGLE, $fields, $me);
        bs_json_ok(['google' => $store->status(Integration::GOOGLE),
                    'message' => '저장했습니다. 이제 [구글 연결] 을 눌러 동의하세요.']);
    },

    /** 피그마는 개인 접근 토큰 하나뿐이다. 넣는 순간 쓸 수 있다. */
    'save_figma' => function () use ($store): void {
        $me    = ig_admin();
        $token = trim(bs_param_str('token'));
        if ($token === '') {
            bs_json_error('BAD_REQUEST', '피그마 개인 접근 토큰을 넣으세요.');
        }
        $store->save(Integration::FIGMA, ['secret' => $token, 'last_error' => ''], $me);
        bs_json_ok(['figma' => $store->status(Integration::FIGMA),
                    'message' => '저장했습니다. 아래에서 실제 링크로 확인해 보세요.']);
    },

    /**
     * Claude API 키.
     *
     * 피그마와 같다 — 키 하나뿐이고 넣는 순간 쓸 수 있다. 설정 파일이 아니라
     * 여기 두는 이유는 Integration 클래스 주석에 적어 두었다: 켜고 끄기·쉬는
     * 시각·하루 상한·사용량 그래프를 그대로 물려받는다.
     */
    'save_claude' => function () use ($store): void {
        $me  = ig_admin();
        $key = trim(bs_param_str('api_key'));
        if ($key === '') {
            bs_json_error('BAD_REQUEST', 'Claude API 키를 넣으세요.');
        }
        // 흔한 실수를 미리 잡는다. 키가 아닌 것을 넣고 "왜 안 되지" 하는
        // 시간이 아깝다 — 피그마 토큰을 여기 넣는 일이 실제로 있을 법하다.
        if (!str_starts_with($key, 'sk-ant-')) {
            bs_json_error('BAD_REQUEST',
                'Claude API 키는 sk-ant- 로 시작합니다. 넣으신 값을 다시 확인하세요.');
        }
        $store->save(Integration::CLAUDE, ['secret' => $key, 'last_error' => ''], $me);
        bs_json_ok(['claude' => $store->status(Integration::CLAUDE),
                    'message' => '저장했습니다. 아래 [AI 연결 확인] 으로 실제 호출을 해 보세요.']);
    },

    /**
     * AI 를 실제로 한 번 불러 본다.
     *
     * 키가 맞는지 확인할 길이 없으면, 관리자는 난이도 판정을 통째로 돌려
     * 봐야 안다. **가장 싼 모델로 아주 짧게** 묻는다 — 확인 한 번이 비싸면
     * 아무도 확인하지 않는다.
     */
    'test_claude' => function () use ($pdo): void {
        ig_admin();
        require_once BS_ROOT . '/inc/service/AnthropicLlmClient.php';
        // 설정 화면에 넣어 둔 키로 **실제로** 부른다. bs_llm_client() 를
        // 쓰면 llm.config.php 가 고정 응답을 돌려주도록 돼 있을 때 "연결됐다"
        // 는 거짓말을 하게 된다 — 이 단추는 진짜 왕복을 확인하는 자리다.
        $llm = new AnthropicLlmClient(null, AnthropicLlmClient::MODEL_FAST, $pdo);
        try {
            $got = $llm->generate(
                '너는 연결 확인용 응답기다. 묻는 말에 그대로 답한다.',
                'connected 라는 낱말 하나를 ok 에 넣어 돌려줘.',
                ['type' => 'object',
                 'properties' => ['ok' => ['type' => 'string']],
                 'required' => ['ok'], 'additionalProperties' => false],
                ['max_tokens' => 256, 'act' => 'connect_test']
            );
            bs_json_ok([
                'claude'  => (new Integration($pdo))->status(Integration::CLAUDE),
                'message' => sprintf('연결됐습니다 — %s · 토큰 %d/%d · 약 $%.4f',
                    $got->model, $got->tokensIn, $got->tokensOut, $got->costUsd),
            ]);
        } catch (LlmError $e) {
            bs_json_error('LLM_FAILED', $e->getMessage(), 400);
        } catch (Throwable $e) {
            // 설정을 맞추려고 누르는 단추다. 진짜 이유를 보여 준다 —
            // "처리 중 오류" 만 뜨면 키를 의심하며 헤매게 된다.
            error_log('[BlueStudio] claude test: ' . $e);
            bs_json_error('INTERNAL_ERROR',
                'AI 를 부르는 중 서버 오류가 났습니다 — ' . $e->getMessage(), 500);
        }
    },

    'disconnect' => function () use ($store): void {
        $me       = ig_admin();
        $provider = bs_param_str('provider');
        if (!ig_known($provider)) {
            bs_json_error('BAD_REQUEST', '어느 연동인지 알 수 없습니다.');
        }
        $store->disconnect($provider, $me);
        bs_json_ok([
            'google'  => $store->status(Integration::GOOGLE),
            'figma'   => $store->status(Integration::FIGMA),
            'claude'  => $store->status(Integration::CLAUDE),
            'message' => '연결을 끊었습니다. 이미 읽어 둔 글자와 판정 결과는 그대로 남습니다.',
        ]);
    },

    /**
     * 실제 링크 하나로 확인한다.
     *
     * 설정이 맞는지 확인할 길이 없으면, 관리자는 프로젝트에 링크를 올리고
     * 분석을 돌려 봐야 안다. 그건 남의 프로젝트를 건드리는 일이다.
     *
     * **읽어 보기만 하고 아무것도 저장하지 않는다.**
     */
    'test' => function () use ($pdo): void {
        ig_admin();
        $url = trim(bs_param_str('url'));
        if ($url === '') {
            bs_json_error('BAD_REQUEST', '확인할 링크를 넣으세요.');
        }
        $hit = RemoteSource::identify($url);
        if ($hit === null) {
            bs_json_error('BAD_REQUEST',
                '구글 드라이브나 피그마 주소가 아닙니다. 읽을 수 있는 주소만 확인할 수 있습니다.');
        }

        $remote = new RemoteSource($pdo);
        $tmp    = null;
        try {
            $got = $remote->fetch($url);
            $tmp = $got['file'];
            $len = $got['text'] !== null ? mb_strlen($got['text']) : (int)@filesize((string)$tmp);
            bs_json_ok([
                'provider' => $hit['provider'],
                'name'     => $got['name'],
                'kind'     => $got['kind'],
                'size'     => $len,
                // 제대로 읽혔는지 눈으로 보라고 앞부분만 보여 준다.
                'preview'  => $got['text'] !== null ? mb_substr($got['text'], 0, 400) : null,
                'message'  => sprintf('읽었습니다 — %s (%s)', $got['name'], $got['kind']),
            ]);
        } catch (RemoteSourceError $e) {
            bs_json_error('FETCH_FAILED', $e->getMessage(), 400);
        } catch (Throwable $e) {
            // ┌──────────────────────────────────────────────────────┐
            // │ 여기서는 진짜 이유를 보여 준다                         │
            // │                                                      │
            // │ 이 자리는 **관리자가 설정을 맞추려고 누르는 단추**다. │
            // │ 공통 처리기에 맡기면 "처리 중 오류가 발생했습니다" 만 │
            // │ 뜨고, 그걸 본 사람은 토큰을 의심하며 헤맨다 — 실제로  │
            // │ 그렇게 한 번 헤맸다(클래스를 못 읽어 터진 것이었다).  │
            // │                                                      │
            // │ 관리자에게만 열리는 진단 도구라 내부 사정을 적어도    │
            // │ 된다. 다른 act 는 그대로 공통 처리기에 맡긴다.        │
            // └──────────────────────────────────────────────────────┘
            error_log('[BlueStudio] integration test: ' . $e);
            bs_json_error('INTERNAL_ERROR',
                '설정을 읽는 중 서버 오류가 났습니다 — ' . $e->getMessage(), 500);
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    },
]);

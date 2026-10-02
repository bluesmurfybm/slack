<?php
/**
 * 외부 연동 설정 — 구글 드라이브 · 피그마.
 *
 *   GET  api/integration.php?act=status                 상태 (관리자)
 *   POST api/integration.php?act=save_google            client_id / client_secret
 *   POST api/integration.php?act=save_figma             개인 접근 토큰
 *   POST api/integration.php?act=disconnect             연결 끊기 { provider }
 *   POST api/integration.php?act=test                   실제로 읽어 보기 { url }
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
            // 구글 콘솔에 그대로 넣어야 하는 값이다. 손으로 적다가 틀리는
            // 일이 잦아 화면이 복사할 수 있게 서버가 만들어 준다.
            'redirect_uri' => bs_oauth_redirect_uri(),
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

    'disconnect' => function () use ($store): void {
        $me       = ig_admin();
        $provider = bs_param_str('provider');
        if (!in_array($provider, [Integration::GOOGLE, Integration::FIGMA], true)) {
            bs_json_error('BAD_REQUEST', '어느 연동인지 알 수 없습니다.');
        }
        $store->disconnect($provider, $me);
        bs_json_ok([
            'google'  => $store->status(Integration::GOOGLE),
            'figma'   => $store->status(Integration::FIGMA),
            'message' => '연결을 끊었습니다. 이미 읽어 둔 글자는 그대로 남습니다.',
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
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    },
]);

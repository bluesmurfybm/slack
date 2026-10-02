<?php
/**
 * 구글 동의 왕복.
 *
 *   api/google_oauth.php            구글에서 돌아오는 자리 (code 를 들고 온다)
 *   api/google_oauth.php?start=1    구글 동의 화면으로 보낸다
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 이 파일만 JSON 이 아니다                                          │
 * │                                                                  │
 * │ 브라우저가 구글로 갔다 와야 해서 **화면 이동**이 필요하다. 다른    │
 * │ api/*.php 와 생김새가 다른 이유다. 돌아오는 주소에 질의 문자열을  │
 * │ 붙일 수 없어(구글 콘솔 등록값과 한 글자도 달라선 안 된다) 돌아오는 │
 * │ 자리를 이 파일 자체로 두고, 보내는 쪽만 ?start=1 로 가른다.       │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 받는 권한은 **drive.readonly** 하나다. 읽기만 하면 되는 일에 쓰기 권한을
 * 받으면, 사고가 났을 때 되돌릴 수 없는 범위가 된다.
 */

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once BS_ROOT . '/inc/service/Integration.php';

$user = bs_require_login();              // 미로그인은 포털로 돌려보낸다
if (!bs_is_admin()) {
    bs_oauth_done('관리자만 구글을 연결할 수 있습니다.', false);
}

$store = new Integration(bs_db());

/** 결과를 알리고 설정 화면으로 돌려보낸다. 이 파일은 보여 줄 화면이 없다. */
function bs_oauth_done(string $message, bool $ok): never
{
    $to = '../settings.php?' . http_build_query([
        $ok ? 'ok' : 'err' => $message,
    ]);
    header('Location: ' . $to);
    exit;
}

// ---------------------------------------------------------------------
// 1) 보내기
// ---------------------------------------------------------------------
if (bs_param_str('start') !== '') {
    $clientId = $store->clientId(Integration::GOOGLE);
    if ($clientId === '' || $store->secret(Integration::GOOGLE) === '') {
        bs_oauth_done('먼저 클라이언트 ID 와 시크릿을 저장하세요.', false);
    }

    // 돌아왔을 때 "내가 보낸 요청이 맞는가" 를 확인할 표식. 세션에 둔다.
    $state = bin2hex(random_bytes(16));
    $_SESSION['bs_oauth_state'] = $state;

    $q = http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => bs_oauth_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/drive.readonly email',
        // offline + consent 라야 **갱신 토큰**을 준다. 이게 없으면 한 시간
        // 뒤에 끊기고, 두 번째 동의부터는 조용히 안 주기 때문에 매번 묻는다.
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $q);
    exit;
}

// ---------------------------------------------------------------------
// 2) 돌아오기
// ---------------------------------------------------------------------
$err = bs_param_str('error');
if ($err !== '') {
    bs_oauth_done($err === 'access_denied' ? '구글에서 동의를 취소했습니다.' : "구글이 거부했습니다: $err", false);
}

$state = bs_param_str('state');
$saved = (string)($_SESSION['bs_oauth_state'] ?? '');
unset($_SESSION['bs_oauth_state']);          // 한 번 쓰고 버린다
if ($state === '' || $saved === '' || !hash_equals($saved, $state)) {
    bs_oauth_done('요청이 어긋났습니다. 설정 화면에서 다시 시작하세요.', false);
}

$code = bs_param_str('code');
if ($code === '') {
    bs_oauth_done('구글이 코드를 주지 않았습니다.', false);
}

// 코드를 갱신 토큰으로 바꾼다.
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $store->clientId(Integration::GOOGLE),
        'client_secret' => $store->secret(Integration::GOOGLE),
        'redirect_uri'  => bs_oauth_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]),
]);
$res  = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$j = is_string($res) ? json_decode($res, true) : null;
if (!is_array($j) || empty($j['refresh_token'])) {
    $why = is_array($j) ? (string)($j['error_description'] ?? $j['error'] ?? '') : '';
    // redirect_uri_mismatch 가 가장 흔하다. 콘솔에 넣은 주소와 글자가 다르다.
    bs_oauth_done(
        $why !== ''
            ? "구글이 토큰을 주지 않았습니다($http): $why"
            : "구글이 갱신 토큰을 주지 않았습니다($http). 콘솔의 리디렉션 주소를 확인하세요.",
        false
    );
}

// 누구 권한으로 읽는지 화면에 적어 주려고 계정을 함께 받아 둔다.
$account = '';
if (!empty($j['id_token']) && substr_count((string)$j['id_token'], '.') === 2) {
    // id_token 은 구글이 방금 준 것이라 서명을 다시 확인하지 않는다 —
    // 권한 판단에 쓰지 않고 **화면에 이름을 적는 데만** 쓴다.
    $parts   = explode('.', (string)$j['id_token']);
    $payload = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (is_array($payload) && !empty($payload['email'])) {
        $account = (string)$payload['email'];
    }
}

$store->save(Integration::GOOGLE, [
    'token'      => (string)$j['refresh_token'],
    'account'    => $account,
    'last_error' => '',
], $user);

bs_oauth_done($account !== '' ? "구글에 연결했습니다 ($account)" : '구글에 연결했습니다.', true);

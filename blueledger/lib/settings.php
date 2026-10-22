<?php
/**
 * 출장여비 설정과 유가.
 *
 *  - main  : 승인자·처리자 이름, 주유비 산정 기준 유가, 연비, 1인 식대, 오피넷 키
 *  - prices: 오피넷에서 마지막으로 받은 유가(화면 '오늘의 유가'). 산정 기준(main.fuel)과 따로 둔다 —
 *            화면 유가는 저절로 바뀌어도 주유비 계산 기준은 정산 담당자가 단추를 눌러야 바뀐다.
 */

const DEFAULT_SETTINGS = [
    'approverName' => '김호영',
    'handlerName'  => '김지안',
    'fuel'         => ['gas' => 1866.16, 'diesel' => 1849.17, 'lpg' => 1103.31],
    'eff'          => 10,
    'priceBase'    => '2026년 8월 1주',
    'mealUnit'     => 15000,   // 1인 식대 기준(2026-10-22 작성기준 개정: 10,000 → 15,000)
];

// 페트로넷 첫 화면 게시값(2026년 10월 1주). 오피넷 키를 넣기 전까지 보여 줄 값이다.
const INITIAL_PRICES = [
    'gas' => 1857.45, 'diesel' => 1843.29, 'lpg' => 1143.09,
    'diff' => ['gas' => 0.19, 'diesel' => 0.02, 'lpg' => 38.36],
    'label' => '2026년 10월 1주 주간 평균', 'source' => '페트로넷 게시값(초기 입력)',
    'fetchedAt' => '', 'checkedAt' => '', 'error' => '',
];

// 크론을 쓰지 않는다. 누군가 화면을 열었을 때 마지막 확인에서 이만큼 지났으면 그때 받아 온다.
const PRICE_REFRESH_SEC = 3 * 60 * 60;

function bl_setting_get(PDO $pdo, $key) {
    $st = $pdo->prepare("SELECT v FROM bl_travel_settings WHERE k=?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v === false) return null;
    $d = json_decode($v, true);
    return is_array($d) ? $d : null;
}

function bl_setting_put(PDO $pdo, $key, array $value) {
    bl_upsert($pdo, 'bl_travel_settings', ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now_stamp()], ['k']);
}

/** 오피넷 키까지 들어 있는 원본. 화면에 그대로 내보내지 말 것 — bl_client_settings 를 쓴다. */
function bl_settings(PDO $pdo) {
    return array_merge(DEFAULT_SETTINGS, bl_setting_get($pdo, 'main') ?? []);
}

function bl_prices(PDO $pdo) {
    $p = array_merge(INITIAL_PRICES, bl_setting_get($pdo, 'prices') ?? []);
    // 파이썬 버전은 WEEK 값을 그대로 붙여 '2026102 주간 평균' 으로 저장했다. 옮겨 온 값도 읽기 좋게.
    $p['label'] = preg_replace_callback('/^(\d{4})(\d{2})(\d) 주간 평균$/',
        fn($m) => sprintf('%s년 %d월 %d주 주간 평균', $m[1], (int)$m[2], (int)$m[3]), (string)$p['label']);
    return $p;
}

function bl_client_settings(PDO $pdo, array $me) {
    $s = bl_settings($pdo);
    $hasKey = trim((string)($s['opinetKey'] ?? '')) !== '';
    unset($s['opinetKey']);
    if ($me['role'] === ROLE_ADMIN) $s['hasOpinetKey'] = $hasKey;
    $s['staff']  = array_column(bl_members(), 'name');
    $s['prices'] = bl_prices($pdo);
    return $s;
}

/** 정산 담당자가 설정 화면에서 저장 */
function bl_save_settings(PDO $pdo, array $body) {
    $cur = bl_settings($pdo);
    if (array_key_exists('approverName', $body)) $cur['approverName'] = clip($body['approverName'], 60) ?: DEFAULT_SETTINGS['approverName'];
    if (array_key_exists('handlerName', $body))  $cur['handlerName']  = clip($body['handlerName'], 60) ?: DEFAULT_SETTINGS['handlerName'];
    if (isset($body['fuel']) && is_array($body['fuel'])) {
        foreach (['gas', 'diesel', 'lpg'] as $k) {
            $v = (float)($body['fuel'][$k] ?? 0);
            if ($v > 0) $cur['fuel'][$k] = round($v, 2);
        }
    }
    if (isset($body['eff']) && (float)$body['eff'] > 0)           $cur['eff'] = round((float)$body['eff'], 1);
    if (array_key_exists('priceBase', $body))                     $cur['priceBase'] = clip($body['priceBase'], 60);
    if (isset($body['mealUnit']) && to_won($body['mealUnit']) > 0) $cur['mealUnit'] = to_won($body['mealUnit']);
    // 키는 다시 보여 주지 않으므로 빈칸은 '그대로 두기'다. 지우려면 clearOpinetKey 를 보낸다.
    if (trim((string)($body['opinetKey'] ?? '')) !== '') $cur['opinetKey'] = clip($body['opinetKey'], 100);
    if (!empty($body['clearOpinetKey'])) $cur['opinetKey'] = '';
    bl_setting_put($pdo, 'main', $cur);
}

/** 화면의 유가를 주유비 산정 기준으로 옮긴다 */
function bl_apply_prices(PDO $pdo) {
    $p   = bl_prices($pdo);
    $cur = bl_settings($pdo);
    $cur['fuel']      = ['gas' => (float)$p['gas'], 'diesel' => (float)$p['diesel'], 'lpg' => (float)$p['lpg']];
    $cur['priceBase'] = (string)($p['label'] ?? '');
    bl_setting_put($pdo, 'main', $cur);
}

/**
 * 마지막 확인 뒤 3시간이 지났으면 오피넷에서 다시 받는다.
 * 화면 여러 개가 동시에 열려도 한 요청만 오피넷에 가도록 이름 잠금을 쥔다.
 */
function bl_maybe_refresh_prices(PDO $pdo) {
    $cur  = bl_prices($pdo);
    $last = strtotime((string)($cur['checkedAt'] ?: $cur['fetchedAt'] ?: '')) ?: 0;
    if (time() - $last < PRICE_REFRESH_SEC) return;
    if (trim((string)(bl_settings($pdo)['opinetKey'] ?? '')) === '') return;
    if (!bl_try_lock($pdo, 'bl_travel_prices')) return;
    try {
        bl_refresh_prices($pdo);
    } finally {
        bl_unlock($pdo, 'bl_travel_prices');
    }
}

/** 지금 받아 온다. 실패해도 이전 값은 그대로 두고 error 만 적는다. */
function bl_refresh_prices(PDO $pdo) {
    $key = trim((string)(bl_settings($pdo)['opinetKey'] ?? ''));
    $cur = bl_prices($pdo);
    if ($key === '') throw new BlError('설정에서 오피넷 API 키를 먼저 넣어 주세요', 400);

    [$prices, $label, $err] = bl_fetch_opinet($key);
    $now = date('Y-m-d\TH:i:s');
    if ($prices) {
        $changed = $label !== ($cur['label'] ?? '');
        foreach ($prices as $k => $v) {
            if (abs($v - (float)($cur[$k] ?? 0)) > 0.001) $changed = true;
        }
        if ($changed) {
            $diff = [];
            foreach ($prices as $k => $v) $diff[$k] = round($v - (float)($cur[$k] ?? 0), 2);
            $cur['diff'] = $diff;
        }
        $cur = array_merge($cur, $prices, [
            'label' => $label, 'source' => '오피넷 유가정보 API (페트로넷 게시값과 같은 출처)',
            'fetchedAt' => $now, 'error' => '',
        ]);
    } else {
        $cur['error'] = $err;
    }
    $cur['checkedAt'] = $now;
    bl_setting_put($pdo, 'prices', $cur);
    return $cur;
}

/**
 * 오피넷 공식 무료 API. 페트로넷 첫 화면과 같은 세 가지(휘발유·자동차용경유·자동차용부탄)
 * 전국 평균을 받는다. 1순위는 주간 평균(페트로넷 게시값과 같은 기준), 안 되면 오늘 평균.
 *
 * 응답 모양(2026-10-21 파이썬 버전에서 실제로 받은 것):
 *   {"RESULT":{"OIL":[{"WEEK":"2026102","PRODCD":"B027","PRICE":"1856.61",...}, ...]}}
 * WEEK 는 '연(4)+월(2)+주(1)' 이다 — 2026102 는 2026년 10월 2주.
 */
const OPINET_URLS = [
    'https://www.opinet.co.kr/api/avgLastWeek.do?out=json&code=%s',
    'https://www.opinet.co.kr/api/avgAllPrice.do?out=json&code=%s',
];
const OPINET_PRODCD = ['B027' => 'gas', 'D047' => 'diesel', 'K015' => 'lpg'];

function bl_fetch_opinet($key) {
    $lastErr = '';
    foreach (OPINET_URLS as $i => $tmpl) {
        $raw = bl_http_get(sprintf($tmpl, rawurlencode($key)), $err);
        if ($raw === null) { $lastErr = "오피넷 연결 실패: {$err}"; continue; }
        $data = json_decode($raw, true);
        $items = $data['RESULT']['OIL'] ?? null;
        if (!is_array($items)) { $lastErr = '오피넷 응답을 읽지 못했습니다(키를 확인해 주세요).'; continue; }
        [$out, $label] = bl_parse_opinet($items);
        if (count($out) === 3) return [$out, $label ?: ($i === 0 ? '주간 평균' : '오늘 전국 평균'), ''];
        $lastErr = '응답에서 세 가지 가격을 모두 찾지 못했습니다.';
    }
    return [null, '', $lastErr];
}

/** 응답의 OIL 목록 → [['gas'=>..,'diesel'=>..,'lpg'=>..], '표시용 기간'] */
function bl_parse_opinet(array $items) {
    $out = [];
    $label = '';
    foreach ($items as $it) {
        $sido = trim((string)($it['SIDOCD'] ?? ''));
        if (!in_array($sido, ['', '0', '00'], true)) continue;   // 시도별 값이 섞여 오면 전국만
        $k = OPINET_PRODCD[trim((string)($it['PRODCD'] ?? ''))] ?? null;
        if (!$k || isset($out[$k]) || !is_numeric($it['PRICE'] ?? null)) continue;
        $out[$k] = round((float)$it['PRICE'], 2);
        if ($label !== '') continue;
        if (!empty($it['WEEK'])) {
            $w = trim((string)$it['WEEK']);
            $label = preg_match('/^(\d{4})(\d{2})(\d)$/', $w, $m)
                ? sprintf('%s년 %d월 %d주 주간 평균', $m[1], (int)$m[2], (int)$m[3])
                : "{$w} 주간 평균";
        } elseif (!empty($it['STA_DT']) && !empty($it['END_DT'])) {
            $label = "{$it['STA_DT']}~{$it['END_DT']} 주간 평균";
        } elseif (!empty($it['TRADE_DT'])) {
            $d = (string)$it['TRADE_DT'];
            $label = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) . ' 전국 평균(일간)';
        }
    }
    return [$out, $label];
}

/** curl 이 없는 서버도 있어 stream 으로 받는다. 실패하면 null 과 이유. */
function bl_http_get($url, &$err) {
    $ctx = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "User-Agent: BlueLedger-Travel/2.0\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        $e = error_get_last();
        $err = $e ? preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $e['message']) : '알 수 없는 오류';
        return null;
    }
    return $raw;
}

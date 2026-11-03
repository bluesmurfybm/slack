<?php
/**
 * 설정값. 기본값은 여기, 서버마다 다른 값은 onwatch/config.local.php(git 제외)에서
 * 같은 키로 덮어쓴다.
 *
 *     <?php return ['max_days' => 365, 'default_budget_usd' => 10];
 *
 * 배치(worker/)는 따로 환경변수로 설정한다 — 비용 계산·엔진·슬랙 기본 채널은
 * 배치만 쓰는 값이라 그쪽에 있다(worker/.env.example).
 */

const OW_MODULE_KEY = 'onwatch';

/** 주기(분) → 이름. 화면의 선택지 순서이기도 하다. */
const OW_INTERVALS = [
    60    => '1시간마다',
    180   => '3시간마다',
    360   => '6시간마다',
    720   => '12시간마다',
    1440  => '매일',
    10080 => '매주',
];

const OW_STATUS_LABEL = ['active' => '감시 중', 'paused' => '일시정지', 'ended' => '종료'];

const OW_NOTIFY_MODES = [
    'change' => '새 소식이 있을 때만',
    'always' => '실행할 때마다 결과 알림',
];

const OW_IMPORTANCE = ['high' => '높음', 'medium' => '보통', 'low' => '낮음'];

const OW_CHANGE_TYPE = [
    'new' => '새로 나옴', 'updated' => '바뀜', 'retracted' => '회수·철회', 'defect' => '결함', 'other' => '기타',
];

const OW_CONFIDENCE = [
    'confirmed'  => '원문 확인',
    'reported'   => '신뢰할 만한 보도',
    'unverified' => '미확인',
];

const OW_RUN_STATUS = [
    'running' => '실행 중', 'changed' => '새 소식', 'quiet' => '변화 없음',
    'failed' => '실패', 'budget' => '예산 초과',
];

const OW_TRIGGER_LABEL = ['schedule' => '정기', 'manual' => '지금 실행', 'cli' => '명령줄'];

const OW_FEEDBACK = ['useful' => '유용', 'duplicate' => '중복', 'wrong' => '틀림'];

/**
 * 감시마다 고를 수 있는 모델. 화면의 선택지 순서이기도 하다(비싼 것부터).
 * price 는 1M 토큰당 USD [입력, 출력] — worker/core/config.py 의 PRICES 와 같은 값이다.
 * 한쪽을 고치면 다른 쪽도 고친다. 실제로 고를 수 있는 것은 ow_models() — 관리자가 줄일 수 있다.
 *
 * Fable 5.1 은 넣지 않았다. Opus 의 2.5배 값이라 비용을 나눠 쓰려는 이 기능의 뜻과 맞지 않는다.
 */
const OW_MODELS = [
    'claude-opus-5-5' => [
        'label' => 'Opus 5.5', 'price' => [4.0, 20.0], 'tier' => '정밀',
        'desc'  => '여러 출처를 대조하고 판단이 까다로운 조사. 가장 꼼꼼하지만 가장 비쌉니다.',
    ],
    'claude-sonnet-5-5' => [
        'label' => 'Sonnet 5.5', 'price' => [2.0, 10.0], 'tier' => '균형',
        'desc'  => '대부분의 감시에 알맞습니다. Opus 의 절반 값에 품질은 크게 떨어지지 않습니다.',
    ],
    'claude-haiku-5-5' => [
        'label' => 'Haiku 5.5', 'price' => [0.10, 0.50], 'tier' => '절약',
        'desc'  => '정해진 한두 곳만 확인하는 단순 감시. 가장 싸지만 검색 결과를 걸러 읽는 기능이 없어 넓은 조사에는 맞지 않습니다.',
    ],
];

/** 이 서버에서 고를 수 있는 모델 — OW_MODELS 중 config 'models' 에 든 것만, OW_MODELS 순서로. */
function ow_models(): array
{
    $allow = (array)ow_config('models');
    return array_intersect_key(OW_MODELS, array_flip($allow));
}

/**
 * 폼 값으로 알맞은 모델을 고른다. 화면의 '권장' 표시와 새 감시의 처음 선택에 쓴다.
 * 규칙은 일부러 단순하다 — 사람이 보고 바꿀 수 있는 제안일 뿐이고, 결정은 등록자가 한다.
 *
 *   검색 25회 이상 · 지시문 2,000자 이상 · 보안/법령처럼 틀리면 곤란한 주제  → 정밀(Opus)
 *   검색 5회 이하이면서 지시문 600자 이하(한두 곳 확인)                    → 절약(Haiku)
 *   그 밖                                                                 → 균형(Sonnet)
 *
 * 권장 모델을 관리자가 막아 두었으면 가까운 것으로 내려가거나 올라간다.
 * @return array{0:string,1:string} [모델, 까닭]
 */
function ow_recommend_model(array $row): array
{
    $prompt   = (string)($row['prompt'] ?? '');
    $len      = mb_strlen($prompt);
    $searches = (int)($row['max_searches'] ?? ow_config('default_searches'));
    $risky    = preg_match('/취약점|CVE-|보안|법령|법률|규정|고시|판례|계약|입찰|특허|리콜/u', $prompt);

    if ($searches >= 25 || $len >= 2000 || $risky) {
        $order = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];
        $why = $risky ? '보안·법령처럼 틀리면 곤란한 주제라 꼼꼼한 모델을 권합니다.'
                      : '살필 곳이 많은 조사라 꼼꼼한 모델을 권합니다.';
    } elseif ($searches <= 5 && $len <= 600) {
        $order = ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'];
        $why = '검색이 적고 지시문이 짧은 단순 확인이라 가장 싼 모델로 충분합니다.';
    } else {
        $order = ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'];
        $why = '일반적인 조사라 품질과 비용이 균형 잡힌 모델을 권합니다.';
    }
    $allowed = ow_models();
    foreach ($order as $m) {
        if (isset($allowed[$m])) return [$m, $m === $order[0] ? $why : $why . ' (그 모델을 쓸 수 없어 가까운 것으로 골랐습니다)'];
    }
    $first = (string)array_key_first($allowed);
    return [$first, '고를 수 있는 모델이 하나뿐입니다.'];
}

/**
 * 한 번 실행의 추정 비용(USD) [낮게, 높게] — 등록 화면의 안내용일 뿐이다.
 * 실제 비용은 배치가 응답의 사용량으로 계산해 ow_run.cost_usd 에 남긴다.
 *
 * 근거: 웹 검색 $10 / 1천 회(모델과 무관). 검색 한 번이 결과 본문으로 입력 2~5천 토큰을
 * 더한다고 보고, 조사 노트·판정의 고정분(Opus 5.5 기준 $0.05~0.20)을 더했다. 토큰 몫은
 * 모델의 입력 단가에 비례해 줄인다(출력/입력 단가 비가 모든 모델에서 5배라 함께 줄어든다).
 * 상한을 다 쓰지 않는 실행이 많아 낮은 쪽은 상한의 30% 로 잡았다.
 * 모르는 모델이면 Opus 값으로 친다 — 배치(config.py price())와 같다.
 */
function ow_estimate_run_cost(int $maxSearches, ?string $model = null): array
{
    $pIn = (OW_MODELS[$model] ?? OW_MODELS['claude-opus-5-5'])['price'][0];
    $perSearchLo = 0.01 + 2000 * $pIn / 1e6;
    $perSearchHi = 0.01 + 5000 * $pIn / 1e6;
    $fixedLo = 0.05 * $pIn / 4;
    $fixedHi = 0.20 * $pIn / 4;
    return [$fixedLo + $maxSearches * 0.3 * $perSearchLo, $fixedHi + $maxSearches * $perSearchHi];
}

function ow_config(string $key)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            // 감시는 '임시' 다. 끝이 없는 감시는 잊힌 채 비용만 쓴다.
            'max_days'            => 180,
            'default_days'        => 30,
            'default_budget_usd'  => 20,
            'max_budget_usd'      => 200,
            'default_searches'    => 15,
            'max_searches'        => 50,
            'prompt_max_chars'    => 8000,
            // 슬랙 알림 전체 스위치. false 면 감시마다의 '슬랙으로도 보내기' 를 화면에서 감추고
            // 배치도 보내지 않는다(감시에 저장된 선택은 지우지 않는다 — 다시 켜면 그대로 살아난다).
            // 배치는 bin/settings.php 로 이 값을 읽는다 — 원본은 여기 한 곳이다.
            'slack_enabled'       => true,
            // 감시마다 고를 수 있는 모델(OW_MODELS 의 키). 사내 API 비용을 아끼려면 줄인다 —
            // 예: ['claude-sonnet-5-5', 'claude-haiku-5-5'] 면 Opus 를 고를 수 없다.
            // 빠진 모델을 이미 고른 감시(와 모델을 고르기 전의 감시)는 배치가 기본값(ONWATCH_MODEL)으로,
            // 그것도 빠졌으면 이 목록의 첫 모델로 돌린다. 배치는 bin/settings.php 로 이 값을 읽는다.
            'models'              => array_keys(OW_MODELS),
        ];
        $local = OW_ROOT . '/config.local.php';
        if (is_file($local)) {
            $over = require $local;
            if (is_array($over)) $cfg = array_replace($cfg, $over);
        }
    }
    return $cfg[$key] ?? null;
}

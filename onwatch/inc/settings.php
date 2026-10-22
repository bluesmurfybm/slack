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
 * 한 번 실행의 추정 비용(USD) [낮게, 높게] — 등록 화면의 안내용일 뿐이다.
 * 실제 비용은 배치가 응답의 사용량으로 계산해 ow_run.cost_usd 에 남긴다.
 *
 * 근거: Opus 5.5 입력 $4 / 출력 $20 (1M 토큰당), 웹 검색 $10 / 1천 회.
 * 검색 한 번이 결과 본문으로 입력 2~5천 토큰을 더한다고 보고, 조사 노트·판정의
 * 고정분을 더했다. 상한을 다 쓰지 않는 실행이 많아 낮은 쪽은 상한의 30% 로 잡았다.
 */
function ow_estimate_run_cost(int $maxSearches): array
{
    $perSearchLo = 0.01 + 2000 * 4 / 1e6;
    $perSearchHi = 0.01 + 5000 * 4 / 1e6;
    $fixedLo = 0.05;
    $fixedHi = 0.20;
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
        ];
        $local = OW_ROOT . '/config.local.php';
        if (is_file($local)) {
            $over = require $local;
            if (is_array($over)) $cfg = array_replace($cfg, $over);
        }
    }
    return $cfg[$key] ?? null;
}

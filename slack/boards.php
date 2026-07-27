<?php
/**
 * 공용 보드(리스트) 설정 — 여러 Slack 리스트 통합 운영.
 *  sync.php / update.php / comments.php 가 공유.
 *  키 = Slack list_id, 값 = 보드 메타(라벨·컬럼맵·댓글채널·초기상태 등)
 */
require_once __DIR__ . '/slack_lib.php';
$cfg = require __DIR__ . '/config.php';

return [
    // 블루소프트 (현행)
    $cfg['list_id'] => [
        'label'           => '블루소프트',
        'col'             => SLACK_COL,
        'comment_channel' => $cfg['comment_channel'] ?? '',
        'init_status'     => '등록',      // 미지정 패널 대상 상태
        'has_eta'         => true,        // 예상완료일 사용
        'title_customer'  => false,       // 제목에 고객사 접두 X
        'skip_empty_title'=> true,        // 제목 없는 항목 동기화 제외
    ],
    // 와이오즈
    'F08EEFB15EJ' => [
        'label'           => '와이오즈',
        'col'             => [
            'title'=>'Col08EME51MH9', 'body'=>'Col08EEFTQVHU', 'req'=>'Col08EJQ9A8DT', 'asg'=>'Col08EJQH7077',
            'status'=>'Col08EEGB9P9U', 'priority'=>'Col08EZRT0HEV', 'team'=>'Col08EPV33DB6',
            'eta'=>'Col08FAK8PENL', 'date'=>'Col08EZSA9F6V', 'attach'=>'Col08EZRHEZ09',
            'momo'=>'Col08EME3MQNP',   // (재활용) 고객사 캡처 → 제목 앞에 붙이고 비움
            'lms'=>'', 'done'=>'',
        ],
        'comment_channel' => 'C08EEFB15EJ',
        'init_status'     => '시작 전',
        'has_eta'         => false,
        'title_customer'  => true,        // 고객사(momo 슬롯) → 제목 접두
        'skip_empty_title'=> true,
    ],
];

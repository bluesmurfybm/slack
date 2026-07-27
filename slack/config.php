<?php
return [
    // Slack 토큰은 더 이상 여기에 두지 않음. 모든 Slack 호출은 로그인 시 입력받은
    // 개인 토큰(세션)을 사용. CLI(php sync.php)는 환경변수 SLACK_TOKEN 으로 토큰을 받음.
    'list_id' => 'F083TU7F0BZ',

    // 리스트 댓글이 저장되는 백엔드 채널 (레코드별 thread_ts 의 답글 = 댓글)
    'comment_channel' => 'C083TU7F0BZ',

    // Slack 리스트 permalink (레코드 링크 = 이 뒤에 ?record_id=Rec... 붙임)
    'list_url' => 'https://coursemos.slack.com/lists/T04LNBX6L/F083TU7F0BZ',

    // DB 접속 정보 (slackapi DB / 테이블은 최초 접속 시 자동 생성)
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'user'    => 'root',
        'pass'    => '',
        'name'    => 'slackapi',
        'charset' => 'utf8mb4',
    ],
];

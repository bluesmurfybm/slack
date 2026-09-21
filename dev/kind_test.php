<?php
date_default_timezone_set('Asia/Seoul');
$src = file_get_contents('I:/77.BlueApp/Dev/iWorks/core/board.php');
// 상수와 분류 함수만 떼어 온다
$i = strpos($src, 'const BOARD_EVENT_KINDS');
$j = strpos($src, '];', $i) + 2;
eval(substr($src, $i, $j - $i));
$i = strpos($src, 'function board_event_kind(');
$d = 0; $k = strpos($src, '{', $i);
for ($n = $k; $n < strlen($src); $n++) {
    if ($src[$n] === '{') $d++;
    if ($src[$n] === '}') { $d--; if ($d === 0) break; }
}
eval(substr($src, $i, $n - $i + 1));

$pass = 0; $fail = 0;
function ok($title, $want) {
    global $pass, $fail;
    $got = board_event_kind($title);
    if ($got['key'] === $want) { $pass++; printf("  OK   %-30s → %s %s\n", $title, $got['icon'], $got['label']); }
    else { $fail++; printf("  FAIL %-30s → %s (기대 %s)\n", $title, $got['key'], $want); }
}

echo "\n[먹는 것]\n";
ok('먹자클럽 9월 모임',        'meal');
ok('먹자 클럽',               'meal');
ok('10월 먹자',               'meal');
ok('전사 회식',               'meal');
ok('연말 회식 안내',           'meal');
ok('신입 환영 만찬',           'meal');
ok('임원 오찬',               'meal');
ok('오후 다과 모임',           'meal');
ok('워크숍 뒤풀이',            'meal');

echo "\n[기존 분류가 안 밀렸는지]\n";
ok('故 김영수 부친상',         'condolence');
ok('축!! 유병문선임 결혼',      'congrats');
ok('추석 연휴',               'holiday');
ok('4분기 예산안 제출 마감',    'deadline');
ok('정보보호 교육',            'edu');
ok('서버 정기 점검',           'ops');
ok('가을 워크숍',              'event');
ok('연말 송년회',              'event');
ok('사내 체육대회',            'event');
ok('주간 업무 보고',           'meeting');
ok('창립기념일',              'holiday');
ok('그냥 일정',               'etc');

echo "\n[조사 — '…상' 으로만 적는 경우]\n";
ok('故 김영수 모친상',         'condolence');
ok('박서연책임 조부상',        'condolence');
ok('이준호수석 장인상',        'condolence');

echo "\n[경사 — 추카·경축]\n";
ok('추카추카 !! 축하행사 테스트 일정', 'congrats');
ok('추카 추카 이준호수석',    'congrats');
ok('추카추카 이준호수석',     'congrats');
ok('경축 사옥 이전',          'congrats');
ok('경축! 창립 20주년',       'congrats');
// '축하' 는 일부러 안 넣었다 — '축하 공연 준비 회의' 까지 경사가 되어
// 준비 회의에 폭죽이 터진다.
ok('축하 공연 준비 회의',      'meeting');
// '축' 한 글자로는 경사에 안 걸려야 한다 (둘 다 경사가 아니면 된다)
ok('사내 축구 대회',          'etc');
ok('개회 축사 준비',          'etc');

echo "\n[경계]\n";
ok('먹자클럽 정기 회의',        'meal');     // meeting 보다 앞이라 회식이 이긴다
ok('부친상 조문 후 식사',       'condolence'); // 조사가 맨 앞이라 안 밀린다

echo "\n합계: {$pass} 통과 / {$fail} 실패\n";
exit($fail ? 1 : 0);

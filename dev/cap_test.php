<?php
date_default_timezone_set('Asia/Seoul');
$src = file_get_contents('I:/77.BlueApp/Dev/iWorks/core/board.php');
$i = strpos($src, 'const BOARD_EVENT_KINDS'); $j = strpos($src, "\n];", $i) + 3;
eval(substr($src, $i, $j - $i));
$i = strpos($src, 'function board_event_kind('); $d = 0; $k = strpos($src, '{', $i);
for ($n = $k; $n < strlen($src); $n++) { if ($src[$n]==='{') $d++; if ($src[$n]==='}') { $d--; if (!$d) break; } }
eval(substr($src, $i, $n - $i + 1));

$words = array_map(function ($d) { return $d['words']; }, BOARD_EVENT_KINDS);
$pass = 0; $fail = 0;
function cap(array $kind, $mode) {
    if (!$kind['cheer']) { return '(폭죽 없음)'; }
    return $kind['icon'] . ' ' . ($mode === 'early' ? $kind['cheer'][1] : $kind['cheer'][0]);
}
foreach ([
  '축!! 유병문선임 결혼', '와우~ 먹자클럽 날이 데이', '추석 연휴', '창립기념일',
  '4분기 예산안 제출 마감', '정보보호 교육', '서버 정기 점검', '가을 워크숍',
  '주간 업무 보고', '그냥 일정', '故 김영수 부친상',
] as $t) {
    $k = board_event_kind($t, $words);
    printf("  %-24s  당일: %-18s  미리: %s\n", $t, cap($k, 'today'), cap($k, 'early'));
    $pass++;
}
echo "\n종류 {$pass}가지 문구 확인\n";

<?php
date_default_timezone_set('Asia/Seoul');

// 순수 날짜 함수만 떼어 와서 DB 없이 검사한다.
$src = file_get_contents('I:/77.BlueApp/Dev/iWorks/core/board.php');
foreach (['board_is_workday', 'board_cheer_early_on'] as $fn) {
    $i = strpos($src, "function $fn(");
    $depth = 0; $j = strpos($src, '{', $i);
    for ($k = $j; $k < strlen($src); $k++) {
        if ($src[$k] === '{') $depth++;
        if ($src[$k] === '}') { $depth--; if ($depth === 0) break; }
    }
    eval(substr($src, $i, $k - $i + 1));
}

$pass = 0; $fail = 0;
function ok($name, $got, $want) {
    global $pass, $fail;
    $g = var_export($got, true); $w = var_export($want, true);
    if ($g === $w) { $pass++; echo "  OK   $name\n"; }
    else { $fail++; echo "  FAIL $name  받음 $g / 기대 $w\n"; }
}

// 2026년 달력: 9/18 금, 9/19 토, 9/20 일, 9/21 월
$none = [];
echo "\n[1] 주말\n";
ok('토요일 경사 → 그 전 금요일',  board_cheer_early_on('2026-09-19', $none), '2026-09-18');
ok('일요일 경사 → 그 전 금요일',  board_cheer_early_on('2026-09-20', $none), '2026-09-18');
ok('월요일 경사 → 미리 없음',     board_cheer_early_on('2026-09-21', $none), null);
ok('금요일 경사 → 미리 없음',     board_cheer_early_on('2026-09-18', $none), null);

echo "\n[2] 공휴일\n";
// 2026-10-03(토) 개천절, 2026-10-09(금) 한글날
$h = ['2026-10-03' => true, '2026-10-09' => true];
ok('금요일이 공휴일 → 그 전 목요일', board_cheer_early_on('2026-10-09', $h), '2026-10-08');
ok('토요일이 공휴일 → 그 전 금요일', board_cheer_early_on('2026-10-03', $h), '2026-10-02');
// 월요일이 공휴일이면 금요일로 건너뛴다 (2026-06-15 월 을 쉬는 날로 가정)
$h2 = ['2026-06-15' => true];
ok('월요일이 공휴일 → 그 전 금요일', board_cheer_early_on('2026-06-15', $h2), '2026-06-12');

echo "\n[3] 연휴 한가운데\n";
// 2026-09-24(목)~09-27(일) 연휴로 가정. 25(금)·24(목) 도 쉬는 날.
$h3 = ['2026-09-24'=>true,'2026-09-25'=>true];
ok('연휴 마지막 일요일 → 연휴 앞 수요일',
   board_cheer_early_on('2026-09-27', $h3), '2026-09-23');
ok('연휴 첫 목요일 → 그 전 수요일',
   board_cheer_early_on('2026-09-24', $h3), '2026-09-23');

echo "\n[4] 평일 판정\n";
ok('토요일은 평일 아님', board_is_workday('2026-09-19', $none), false);
ok('일요일은 평일 아님', board_is_workday('2026-09-20', $none), false);
ok('금요일은 평일',      board_is_workday('2026-09-18', $none), true);
ok('쉬는 날은 평일 아님', board_is_workday('2026-10-09', $h), false);

echo "\n[5] 끝없이 쉬는 경우\n";
$all = [];
$d = new DateTimeImmutable('2026-01-01');
for ($i = 0; $i < 40; $i++) { $all[$d->format('Y-m-d')] = true; $d = $d->modify('+1 day'); }
ok('두 주 안에 평일이 없으면 null', board_cheer_early_on('2026-01-30', $all), null);

echo "\n합계: {$pass} 통과 / {$fail} 실패\n";
exit($fail ? 1 : 0);

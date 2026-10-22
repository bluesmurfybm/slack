<?php
/**
 * 다음 실행 시각·입력 검사·마크다운 시험. DB 없이 돈다.
 *
 *     php onwatch/tests/schedule_test.php
 *
 * 일정 사례는 schedule_cases.json — Python(worker/tests/test_schedule.py)과 같은 파일을 읽는다.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OW_ROOT', dirname(__DIR__));
date_default_timezone_set('Asia/Seoul');
require_once OW_ROOT . '/inc/settings.php';
require_once OW_ROOT . '/inc/helpers.php';
require_once OW_ROOT . '/inc/schedule.php';
require_once OW_ROOT . '/inc/presenter.php';
require_once OW_ROOT . '/inc/db.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void
{
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++;
    echo "실패: $name\n  기대 " . var_export($want, true) . "\n  실제 " . var_export($got, true) . "\n";
}

// ---- 일정
$data = json_decode(file_get_contents(__DIR__ . '/schedule_cases.json'), true);
foreach ($data['cases'] as $c) {
    $w = array_merge($data['base'], $c['w']);
    check('일정: ' . $c['name'], ow_next_run($w, $c['after']), $c['expect']);
}

// ---- 일정 설명
check('설명 매일', ow_schedule_label(['interval_min' => 1440, 'run_time' => '09:00', 'weekdays_only' => 1, 'hour_from' => 0, 'hour_to' => 24]), '평일 매일 09:00');
check('설명 시간대', ow_schedule_label(['interval_min' => 180, 'run_time' => null, 'weekdays_only' => 0, 'hour_from' => 8, 'hour_to' => 18]), '3시간마다 (08~18시)');
check('설명 매주', ow_schedule_label(['interval_min' => 10080, 'run_time' => '10:00', 'weekday' => 1]), '매주 월요일 10:00');

// ---- 도메인 정리
check('도메인', ow_clean_domains("https://www.php.net/x\nsecurity-tracker.debian.org, bad\nphp.net"), ['php.net', 'security-tracker.debian.org']);

// ---- 슬랙 웹훅 — hooks.slack.com 만
check('웹훅 정상', ow_valid_slack_webhook('https://hooks.slack.com/services/T0/B0/xyz'), true);
check('웹훅 다른 호스트', ow_valid_slack_webhook('https://hooks.slack.com.evil.io/services/x'), false);
check('웹훅 내부망', ow_valid_slack_webhook('http://127.0.0.1/services/x'), false);

// ---- 마크다운 — 원문 HTML 은 통과시키지 않는다
check('md 이스케이프', ow_md('<script>alert(1)</script>'), '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
check('md 링크', ow_md('[원문](https://php.net/a?b=1&c=2)'), '<p><a href="https://php.net/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">원문</a></p>');
check('md javascript 링크 거부', strpos(ow_md('[x](javascript:alert(1))'), '<a ') === false, true);
check('md 목록', ow_md("- **가**\n- `나`"), '<ul><li><b>가</b></li><li><code>나</code></li></ul>');
check('md 맨 주소', ow_md('보기 https://ex.com/a.'), '<p>보기 <a href="https://ex.com/a" target="_blank" rel="noopener noreferrer">https://ex.com/a</a>.</p>');

// ---- 한글 끝 바이트 0x85('입' EC 9E 85, '업' EC 97 85) 를 줄바꿈으로 자르지 않는다 — /u 가 빠지면 깨진다
check('md 한글 줄', ow_md("유료입니다\n대상입니다 업무"), '<p>유료입니다<br>대상입니다 업무</p>');
check('md 한글 목록', ow_md("- **성격:** 유료입니다"), '<ul><li><b>성격:</b> 유료입니다</li></ul>');
check('SQL 한글 COMMENT', ow_sql_statements("SELECT 'ㄱ임박입니다';"), ["SELECT 'ㄱ임박입니다'"]);
foreach (glob(OW_ROOT . '/sql/*.sql') as $f) {
    foreach (ow_sql_statements(file_get_contents($f)) as $st) {
        if (!mb_check_encoding($st, 'UTF-8') || strpos($st, "\xEF\xBF\xBD") !== false) check('SQL 깨짐 ' . basename($f), false, true);
    }
}

// ---- 출처 — http(s) 만
check('출처 거르기', count(ow_sources('[{"url":"javascript:x"},{"url":"https://a.com","seen":true}]')), 1);

// ---- SQL 나누기
check('SQL 나누기', count(ow_sql_statements(file_get_contents(OW_ROOT . '/sql/001_schema.sql'))), 5);

echo "통과 $pass · 실패 $fail\n";
exit($fail ? 1 : 0);

<?php
/**
 * 다음 실행 시각 계산.
 *
 * 배치(worker/core/schedule.py)에 같은 식이 있다. 둘이 어긋나면 화면에 적힌
 * '다음 실행' 과 실제 실행이 달라진다 — tests/schedule_cases.json 하나를 두 쪽
 * 시험이 같이 읽어 맞는지 본다. 한쪽을 고치면 다른 쪽과 그 파일도 고친다.
 *
 * 규칙
 *  - 하루 안 주기(1·3·6·12시간): 실행 시간대 시작(hour_from 시 정각)부터 주기만큼
 *    더해 간 시각들. 시간대 끝(hour_to 시) 이상은 버린다. 시간대가 0~24 면
 *    자정 기준 정각(3시간이면 0·3·6…시)이 된다.
 *  - 매일: run_time. 매주: weekday 요일의 run_time.
 *  - weekdays_only 면 토·일을 건너뛴다(매주에는 쓰지 않는다).
 *  - after 보다 늦고 starts_at 이상인 첫 시각. ends_at 을 넘으면 null.
 */

const OW_SCHEDULE_HORIZON_DAYS = 400;

/**
 * @param array  $w     interval_min, run_time, weekday, weekdays_only, hour_from, hour_to, starts_at, ends_at
 * @param string $after 'Y-m-d H:i:s' (KST)
 * @return string|null  'Y-m-d H:i:s'
 */
function ow_next_run(array $w, string $after): ?string
{
    $tz       = new DateTimeZone('Asia/Seoul');
    $afterDt  = new DateTimeImmutable($after, $tz);
    $start    = new DateTimeImmutable((string)$w['starts_at'], $tz);
    $end      = new DateTimeImmutable((string)$w['ends_at'], $tz);
    $interval = (int)$w['interval_min'];
    $wkOnly   = !empty($w['weekdays_only']);
    $hFrom    = max(0, min(23, (int)($w['hour_from'] ?? 0)));
    $hTo      = max($hFrom + 1, min(24, (int)($w['hour_to'] ?? 24)));
    [$rh, $rm] = ow_parse_hm($w['run_time'] ?? null);
    $weekday  = (int)($w['weekday'] ?? 1);

    $from = $afterDt > $start ? $afterDt : $start;
    $day  = $from->setTime(0, 0, 0);

    for ($i = 0; $i < OW_SCHEDULE_HORIZON_DAYS; $i++, $day = $day->modify('+1 day')) {
        if ($day > $end) return null;
        $dow = (int)$day->format('N');
        if ($interval >= 10080) {
            if ($dow !== $weekday) continue;
            $slots = [$day->setTime($rh, $rm)];
        } else {
            if ($wkOnly && $dow >= 6) continue;
            if ($interval >= 1440) {
                $slots = [$day->setTime($rh, $rm)];
            } else {
                $slots = [];
                for ($m = $hFrom * 60; $m < $hTo * 60; $m += max(1, $interval)) {
                    $slots[] = $day->setTime(intdiv($m, 60), $m % 60);
                }
            }
        }
        foreach ($slots as $t) {
            if ($t > $afterDt && $t >= $start) {
                return $t > $end ? null : $t->format('Y-m-d H:i:s');
            }
        }
    }
    return null;
}

/** 'HH:MM' → [시, 분]. 비거나 틀리면 09:00. */
function ow_parse_hm(?string $hm): array
{
    if ($hm && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hm, $m)) return [(int)$m[1], (int)$m[2]];
    return [9, 0];
}

/** 사람이 읽는 주기 설명 — '평일 매일 09:00', '3시간마다 (09~18시)' */
function ow_schedule_label(array $w): string
{
    $iv = (int)$w['interval_min'];
    $wk = ['', '월', '화', '수', '목', '금', '토', '일'];
    if ($iv >= 10080) return '매주 ' . ($wk[(int)$w['weekday']] ?? '') . '요일 ' . ($w['run_time'] ?: '09:00');
    $prefix = !empty($w['weekdays_only']) ? '평일 ' : '';
    if ($iv >= 1440) return $prefix . '매일 ' . ($w['run_time'] ?: '09:00');
    $label = $prefix . (OW_INTERVALS[$iv] ?? ($iv . '분마다'));
    $hf = (int)$w['hour_from'];
    $ht = (int)$w['hour_to'];
    if ($hf > 0 || $ht < 24) $label .= sprintf(' (%02d~%02d시)', $hf, $ht);
    return $label;
}

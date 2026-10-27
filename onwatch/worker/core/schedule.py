"""다음 실행 시각 계산 — onwatch/inc/schedule.php 의 ow_next_run() 과 같은 식.

둘이 어긋나면 화면에 적힌 '다음 실행' 과 실제 실행이 달라진다. tests/schedule_cases.json
하나를 PHP·Python 시험이 같이 읽어 맞는지 본다. 한쪽을 고치면 다른 쪽도 고친다.
시각은 모두 KST 기준의 시간대 없는(naive) datetime 이다 — DB 가 그렇게 들고 있다.
"""

from datetime import datetime, timedelta

HORIZON_DAYS = 400


def parse_hm(hm: str | None) -> tuple[int, int]:
    if hm and len(hm) == 5 and hm[2] == ":" and hm[:2].isdigit() and hm[3:].isdigit():
        h, m = int(hm[:2]), int(hm[3:])
        if 0 <= h <= 23 and 0 <= m <= 59:
            return h, m
    return 9, 0


def next_run(w: dict, after: datetime) -> datetime | None:
    start: datetime = w["starts_at"]
    end: datetime = w["ends_at"]
    interval = int(w["interval_min"])
    wk_only = bool(w.get("weekdays_only"))
    h_from = max(0, min(23, int(w.get("hour_from") or 0)))
    h_to = max(h_from + 1, min(24, int(24 if w.get("hour_to") is None else w["hour_to"])))
    rh, rm = parse_hm(w.get("run_time"))
    weekday = int(w.get("weekday") or 1)

    frm = max(after, start)
    day = frm.replace(hour=0, minute=0, second=0, microsecond=0)
    for _ in range(HORIZON_DAYS):
        if day > end:
            return None
        dow = day.isoweekday()
        slots: list[datetime] = []
        if interval >= 10080:
            if dow == weekday:
                slots = [day.replace(hour=rh, minute=rm)]
        elif not (wk_only and dow >= 6):
            if interval >= 1440:
                slots = [day.replace(hour=rh, minute=rm)]
            else:
                m = h_from * 60
                while m < h_to * 60:
                    slots.append(day + timedelta(minutes=m))
                    m += max(1, interval)
        for t in slots:
            if t > after and t >= start:
                return None if t > end else t
        day += timedelta(days=1)
    return None

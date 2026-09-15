<?php
/**
 * 멤버 점수. 저장하지 않고 기존 기록에서 파생한다 — 배점을 바꾸면 지난 점수도 같이 바뀐다.
 */

const DTI_SCORE_DONE = 10;
const DTI_SCORE_REQUIRED_BONUS = 5;
const DTI_SCORE_MATERIAL = 3;
const DTI_SCORE_REACTION = 1;
const DTI_SCORE_REACTION_DAILY_CAP = 3;
const DTI_SCORE_KINDS = ['done', 'required', 'material', 'reaction'];

function dti_score_events(PDO $pdo): array {
    $topics = [];
    foreach (dti_topic_all($pdo) as $topic) {
        $topics[(int)$topic['id']] = $topic;
    }

    $out = [];
    foreach (dti_presentation_all_with_topics($pdo) as $pres) {
        if ($pres['presenter_email'] === '') continue;
        $topic = $topics[$pres['topic_id']] ?? null;
        if ($topic === null) continue;

        // 자료 등록 시각은 저장하지 않아 발표일, 없으면 예약 시각으로 귀속한다
        $date = $pres['done_date'] !== '' ? $pres['done_date'] : substr($pres['created_at'], 0, 10);

        if ($pres['done_date'] !== '') {
            $out[] = dti_score_event($pres['presenter_email'], $date, 'done', DTI_SCORE_DONE);
            if ($topic['requirement'] === 'required') {
                $out[] = dti_score_event($pres['presenter_email'], $date, 'required', DTI_SCORE_REQUIRED_BONUS);
            }
        }
        if ($pres['material_kind'] !== null) {
            $out[] = dti_score_event($pres['presenter_email'], $date, 'material', DTI_SCORE_MATERIAL);
        }
    }

    foreach (dti_emotion_daily_counts($pdo) as $row) {
        $points = min($row['count'], DTI_SCORE_REACTION_DAILY_CAP) * DTI_SCORE_REACTION;
        $out[] = dti_score_event($row['email'], $row['date'], 'reaction', $points);
    }

    return $out;
}

/** $members 는 구성원 명단이다 — 명단에서 빠진 사람도 기록이 있으면 보여 준다. */
function dti_score_summary(PDO $pdo, array $members, string $start = '', string $end = ''): array {
    $rows = [];
    foreach ($members as $member) {
        $rows[$member['email']] = dti_score_empty_row($member['email'], $member['name']);
    }

    foreach (dti_score_events($pdo) as $event) {
        if (($start !== '' && $event['date'] < $start) || ($end !== '' && $event['date'] > $end)) {
            continue;
        }
        $rows[$event['email']] ??= dti_score_empty_row(
            $event['email'], dti_members_name_of($members, $event['email']) ?: $event['email']);
        $rows[$event['email']]['total'] += $event['points'];
        $rows[$event['email']]['breakdown'][$event['kind']] += $event['points'];
    }

    $out = array_values($rows);
    usort($out, static fn (array $a, array $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

    return $out;
}

function dti_score_event(string $email, string $date, string $kind, int $points): array {
    return ['email' => $email, 'date' => $date, 'kind' => $kind, 'points' => $points];
}

function dti_score_empty_row(string $email, string $name): array {
    return [
        'email' => $email,
        'name' => $name,
        'total' => 0,
        'breakdown' => array_fill_keys(DTI_SCORE_KINDS, 0),
    ];
}

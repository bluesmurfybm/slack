<?php

namespace Dti\Service;

use Dti\Identity\Members;
use Dti\Repository\EmotionRepository;
use Dti\Repository\PresentationRepository;
use Dti\Repository\TopicRepository;

/**
 * 멤버 점수. 저장하지 않고 기존 기록에서 파생한다 — 배점을 바꾸면 지난 점수도 같이 바뀐다.
 */
final class ScoreService
{
    private const DONE = 10;
    private const REQUIRED_BONUS = 5;
    private const MATERIAL = 3;
    private const REACTION = 1;
    private const REACTION_DAILY_CAP = 3;

    public const KINDS = ['done', 'required', 'material', 'reaction'];

    public function __construct(
        private readonly TopicRepository $topics,
        private readonly PresentationRepository $presentations,
        private readonly EmotionRepository $emotions,
        private readonly Members $members,
    ) {}

    /** @return array<int, array{email: string, date: string, kind: string, points: int}> */
    public function events(): array
    {
        $topics = [];
        foreach ($this->topics->all() as $topic) {
            $topics[(int)$topic->id] = $topic;
        }

        $out = [];
        foreach ($this->presentations->allWithTopics() as $pres) {
            if ($pres->presenter_email === '') continue;
            $topic = $topics[$pres->topic_id] ?? null;
            if ($topic === null) continue;

            // 자료 등록 시각은 저장하지 않아 발표일, 없으면 예약 시각으로 귀속한다
            $date = $pres->done_date !== '' ? $pres->done_date : substr($pres->created_at, 0, 10);

            if ($pres->done_date !== '') {
                $out[] = $this->event($pres->presenter_email, $date, 'done', self::DONE);
                if ($topic->requirement === 'required') {
                    $out[] = $this->event($pres->presenter_email, $date, 'required', self::REQUIRED_BONUS);
                }
            }
            if ($pres->material_kind !== null) {
                $out[] = $this->event($pres->presenter_email, $date, 'material', self::MATERIAL);
            }
        }

        foreach ($this->emotions->dailyCounts() as $row) {
            $points = min($row['count'], self::REACTION_DAILY_CAP) * self::REACTION;
            $out[] = $this->event($row['email'], $row['date'], 'reaction', $points);
        }

        return $out;
    }

    public function summary(string $start = '', string $end = ''): array
    {
        $rows = [];
        foreach ($this->members->all() as $member) {
            $rows[$member['email']] = $this->emptyRow($member['email'], $member['name']);
        }

        foreach ($this->events() as $event) {
            if (($start !== '' && $event['date'] < $start) || ($end !== '' && $event['date'] > $end)) {
                continue;
            }
            // 명단에서 빠진 사람도 기록이 있으면 보여 준다
            $rows[$event['email']] ??= $this->emptyRow($event['email'], $this->members->nameOf($event['email']) ?: $event['email']);
            $rows[$event['email']]['total'] += $event['points'];
            $rows[$event['email']]['breakdown'][$event['kind']] += $event['points'];
        }

        $out = array_values($rows);
        usort($out, static fn (array $a, array $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $out;
    }

    private function event(string $email, string $date, string $kind, int $points): array
    {
        return ['email' => $email, 'date' => $date, 'kind' => $kind, 'points' => $points];
    }

    private function emptyRow(string $email, string $name): array
    {
        return [
            'email' => $email,
            'name' => $name,
            'total' => 0,
            'breakdown' => array_fill_keys(self::KINDS, 0),
        ];
    }
}

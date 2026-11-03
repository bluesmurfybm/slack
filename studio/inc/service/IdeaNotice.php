<?php
/**
 * 아이디어 알림 — 포털 알림함·웹 푸시(core/push.php)로 보낸다 (032).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ bs_notification(Notifier) 이 아니라 core/push.php 다              │
 * │                                                                  │
 * │ bs_notification 은 슬랙·메일로 내보낼 **보낼 목록**인데 아직 내보 │
 * │ 내는 경로가 없다. 아이디어 알림은 포털 종(알림함)·카드 배지·상단  │
 * │ 메뉴 배지에 바로 떠야 한다 — OnWatch 와 같은 길이다.              │
 * │ 종류는 core/push_kinds.json 의 studio.idea · studio.idea_mine.    │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 모든 메서드는 예외를 던지지 않는다. 알림 때문에 글 올리기가 실패하면
 * 사람은 "올리기가 안 된다" 로 겪는다(push_send 와 같은 약속).
 */

declare(strict_types=1);

final class IdeaNotice
{
    /** 알림을 누르면 열 주소(포털 루트 기준). 읽음 처리도 이 주소로 맞춘다. */
    public static function url(int $ideaId): string
    {
        return 'studio/idea_view.php?id=' . $ideaId;
    }

    /** 새 아이디어 — 올린 사람을 뺀 전원(종류를 켜 둔 사람만). */
    public static function created(array $idea): void
    {
        self::guard(function () use ($idea) {
            $to = self::everyoneBut([(string)$idea['author_id']]);
            if (!$to) {
                return;
            }
            push_send([
                'kind'  => 'studio.idea',
                'title' => '새 아이디어 · ' . $idea['title'],
                'body'  => self::byline($idea),
                'url'   => self::url((int)$idea['id']),
                'to'    => $to,
                'tag'   => 'studio-idea-' . (int)$idea['id'],
            ]);
        });
    }

    /**
     * 내 아이디어에 피드백이 달렸다 — 올린 사람에게. 답글이면 원 피드백을 쓴 사람에게도.
     * 자기 글에 자기가 단 것은 알리지 않는다.
     */
    public static function commented(array $idea, array $actor, string $content, ?string $parentAuthor): void
    {
        self::guard(function () use ($idea, $actor, $content, $parentAuthor) {
            $to = array_diff(array_unique(array_filter([(string)$idea['author_id'], (string)$parentAuthor])),
                             [(string)$actor['id']]);
            if (!$to) {
                return;
            }
            push_send([
                'kind'  => 'studio.idea_mine',
                'title' => '피드백 · ' . $idea['title'],
                'body'  => ($actor['name'] ?? '') . ': ' . mb_strimwidth(preg_replace('/\s+/u', ' ', $content), 0, 120, '…'),
                'url'   => self::url((int)$idea['id']),
                'to'    => array_values($to),
            ]);
        });
    }

    /** 한줄 의견을 남긴 평가 — 올린 사람에게. 점수는 싣지 않는다(본인만 보는 값). */
    public static function opinion(array $idea, array $actor, string $opinion): void
    {
        self::guard(function () use ($idea, $actor, $opinion) {
            push_send([
                'kind'  => 'studio.idea_mine',
                'title' => '평가 의견 · ' . $idea['title'],
                'body'  => ($actor['name'] ?? '') . ': ' . mb_strimwidth($opinion, 0, 120, '…'),
                'url'   => self::url((int)$idea['id']),
                'to'    => [(string)$idea['author_id']],
            ]);
        });
    }

    /** 검토 결과 — 올린 사람에게. */
    public static function reviewed(array $idea, string $status, ?string $note): void
    {
        self::guard(function () use ($idea, $status, $note) {
            push_send([
                'kind'  => 'studio.idea_mine',
                'title' => (BS_IDEA_STATUS[$status] ?? $status) . ' · ' . $idea['title'],
                'body'  => $note !== null && $note !== '' ? mb_strimwidth($note, 0, 140, '…')
                                                         : '아이디어 상태가 바뀌었습니다.',
                'url'   => self::url((int)$idea['id']),
                'to'    => [(string)$idea['author_id']],
            ]);
        });
    }

    /**
     * 과제·프로젝트로 넘어갔다 — 올린 사람과, 평가·피드백으로 함께한 사람들에게.
     * 넘긴 사람 본인은 뺀다.
     */
    public static function promoted(array $idea, array $participants, array $actor, string $label, string $url): void
    {
        self::guard(function () use ($idea, $participants, $actor, $label, $url) {
            $to = array_diff(array_unique(array_merge([(string)$idea['author_id']], $participants)),
                             [(string)$actor['id']]);
            if (!$to) {
                return;
            }
            push_send([
                'kind'  => 'studio.idea_mine',
                'title' => $label . '(으)로 · ' . $idea['title'],
                'body'  => '함께 다듬은 아이디어가 ' . $label . '(으)로 넘어갔습니다.',
                'url'   => $url,
                'to'    => array_values($to),
            ]);
        });
    }

    /**
     * 이 아이디어의 알림을 읽음으로. 상세 화면을 열 때 부른다 — OnWatch 의 ow_mark_seen 과 같다.
     * @param int $portalUserId portal_users.id
     */
    public static function markRead(int $portalUserId, int $ideaId): void
    {
        self::guard(fn() => push_inbox_mark_read_url($portalUserId, 'studio.idea', self::url($ideaId)));
    }

    /**
     * 안 읽은 아이디어 알림이 걸린 아이디어 id. 목록의 NEW 표시용.
     * @return array<int,int> idea_id => 안 읽은 건수
     */
    public static function unreadIds(int $portalUserId): array
    {
        $out = [];
        self::guard(function () use ($portalUserId, &$out) {
            foreach (push_inbox_unread_urls($portalUserId, 'studio.idea') as $url => $n) {
                if (preg_match('#^studio/idea_view\.php\?id=(\d+)#', $url, $m)) {
                    $out[(int)$m[1]] = ($out[(int)$m[1]] ?? 0) + $n;
                }
            }
        });
        return $out;
    }

    // ------------------------------------------------------------------

    private static function byline(array $idea): string
    {
        $cat = $idea['rnd_category'] ? '[' . (BS_RND_CATEGORY[$idea['rnd_category']] ?? '') . '] ' : '';
        $who = !empty($idea['is_anonymous']) ? '익명' : (string)($idea['author_name'] ?? '');
        return trim($cat . $idea['code'] . ' — ' . $who);
    }

    /** 포털 사용자 전원에서 몇 명을 뺀 이메일 목록. push_send 에는 '빼고' 옵션이 없다. */
    private static function everyoneBut(array $emails): array
    {
        $emails = array_map('strtolower', array_filter($emails));
        $rows = portal_db()->query('SELECT email FROM portal_users')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($rows, static fn($e) => !in_array(strtolower((string)$e), $emails, true)));
    }

    private static function guard(callable $fn): void
    {
        try {
            require_once BS_PORTAL_ROOT . '/core/push.php';
            $fn();
        } catch (Throwable $e) {
            error_log('[studio.idea] 알림 실패: ' . $e->getMessage());
        }
    }
}

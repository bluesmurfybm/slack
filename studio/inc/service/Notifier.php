<?php
/** 배정 확정 등에서 사람에게 알리는 계약. 실제 발송 경로는 정해지면 갈아끼운다. */

declare(strict_types=1);

/**
 * 보낼 알림 한 건.
 *
 * `to` 는 **보낼 당시의 주소 스냅샷**이다. 나중에 슬랙 핸들이 바뀌어도
 * "그때 어디로 보냈는가" 는 남아야 한다.
 */
final class Notice
{
    public function __construct(
        public readonly string $channel,     // slack | email
        public readonly ?int   $memberId,
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly string $refType  = '',
        public readonly ?int   $refId    = null,
    ) {}
}

/**
 * 알림 발송기.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 확정은 알림 때문에 실패하면 안 된다                                │
 * │                                                                  │
 * │ 배정 확정은 DB 안에서 끝나는 일이다. 슬랙이 느리거나 죽어 있다고   │
 * │ 확정이 롤백되면, 사람은 "확정 버튼이 안 먹는다" 로 겪는다.         │
 * │                                                                  │
 * │ 그래서 기본 구현은 **적재함에 쌓기만 한다**(OutboxNotifier).       │
 * │ 실제로 내보내는 것은 이 표를 읽어 가는 별도 경로의 몫이다.         │
 * │                                                                  │
 * │ 지금 사내에 쓸 수 있는 발송 경로가 없다:                          │
 * │   · 슬랙 토큰은 운영 config.php 의 AES 키로 암호화돼 있어          │
 * │     이 모듈에서 풀 수 없다                                        │
 * │   · slack/gmail 쪽은 **읽기** 전용이라 보내는 함수가 없다          │
 * │ 그래서 화면은 "보낼 예정" 이라고 말하고 "보냈다" 고 하지 않는다.   │
 * └──────────────────────────────────────────────────────────────────┘
 */
interface Notifier
{
    public function name(): string;

    /**
     * 알림을 접수한다.
     *
     * **예외를 던지지 않는다.** 부르는 쪽(확정)이 이것 때문에 실패하면 안 된다.
     *
     * @param  Notice[] $notices
     * @return array{queued:int,skipped:int,failed:int,detail:array}
     */
    public function send(array $notices): array;
}

/**
 * 적재함에 쌓기만 하는 기본 구현.
 *
 * 보내지 않는다. 보낼 것을 남긴다. 이것이 지금 정직한 상태다.
 */
final class OutboxNotifier implements Notifier
{
    public function __construct(private PDO $pdo) {}

    public function name(): string { return 'outbox'; }

    public function send(array $notices): array
    {
        $queued = 0; $skipped = 0; $failed = 0; $detail = [];

        $st = $this->pdo->prepare(
            'INSERT INTO bs_notification
                (channel, ref_type, ref_id, member_id, to_addr, subject, body, status, error)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );

        foreach ($notices as $n) {
            // 주소가 없으면 실패가 아니라 '건너뜀' 이다. 사람이 슬랙 핸들을
            // 안 적어 둔 것은 시스템 오류가 아니다. 다만 조용히 넘기지 않고
            // 남겨서, 누가 못 받았는지 화면에서 보이게 한다.
            $addr  = trim($n->to);
            $ok    = $addr !== '';
            $state = $ok ? 'queued' : 'skipped';
            $err   = $ok ? null
                   : ($n->channel === 'slack' ? '슬랙 핸들이 등록돼 있지 않습니다.'
                                              : '메일 주소가 등록돼 있지 않습니다.');

            try {
                $st->execute([
                    $n->channel, $n->refType, $n->refId, $n->memberId,
                    $addr, $n->subject, $n->body, $state, $err,
                ]);
                if ($ok) { $queued++; } else { $skipped++; }
            } catch (Throwable $e) {
                // 적재조차 실패하면 그 사실만 기록하고 넘어간다.
                $failed++;
                error_log('[BlueStudio] 알림 적재 실패: ' . $e->getMessage());
            }

            $detail[] = [
                'channel'   => $n->channel,
                'member_id' => $n->memberId,
                'to'        => $addr,
                'status'    => $state,
                'error'     => $err,
            ];
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'failed' => $failed,
                'detail' => $detail];
    }
}

/**
 * 아무것도 하지 않는 구현.
 *
 * 시험에서 적재함을 더럽히지 않으려고 쓴다. 운영 경로의 기본값이 아니다 —
 * 기본값이 되면 알림이 사라진 것을 아무도 모르게 된다.
 */
final class NullNotifier implements Notifier
{
    public function name(): string { return 'none'; }

    public function send(array $notices): array
    {
        return ['queued' => 0, 'skipped' => count($notices), 'failed' => 0, 'detail' => []];
    }
}

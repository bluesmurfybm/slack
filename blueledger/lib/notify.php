<?php
/**
 * 출장비 알림 — 포털 알림함·웹 푸시(core/push.php). 본인·결재자 것이라 전원에게는 보내지 않고, 금액은 싣지 않는다.
 *  - blueledger.review: 새 청구 → 승인·정산 관리자
 *  - blueledger.mine: 승인·반려·정산 → 올린 사람과 출장자
 */

function bl_trip_url(string $id): string {
    return 'blueledger/travel.php?trip=' . rawurlencode($id);
}

/** 저장 앞뒤를 견줘 알릴 것을 고른다. 보내기는 응답을 돌려준 뒤에 한다 */
function bl_notify_trip(array $me, ?array $prev, array $row): void {
    $what = [];
    if (!$prev) {
        $kind = 'blueledger.review';
    } else {
        $kind = 'blueledger.mine';
        if ($row['approval'] !== $prev['approval'] && in_array($row['approval'], ['승인', '반려'], true)) $what[] = $row['approval'];
        if ($row['status'] === '처리완료' && $prev['status'] !== '처리완료') $what[] = '정산 완료';
        if (!$what) return;
    }
    register_shutdown_function(function () use ($me, $row, $kind, $what) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try {
            require_once __DIR__ . '/../../core/push.php';
            $to = $kind === 'blueledger.review' ? bl_reviewer_emails() : bl_owner_emails($row);
            if (!$to) return;
            $trip = trim($row['traveler'] . ' · ' . $row['trip_date'] . ' ' . $row['place']);
            push_send([
                'kind' => $kind,
                'title' => $kind === 'blueledger.review' ? '출장비 결재 대기 · ' . $trip : '출장비 ' . implode(', ', $what) . ' · ' . $row['trip_date'] . ' ' . $row['place'],
                'body' => $kind === 'blueledger.review' ? $me['name'] . ' 님이 청구했습니다.' : $me['name'] . ' 님이 처리했습니다.',
                'url' => bl_trip_url($row['id']),
                'to' => $to,
                'except' => [$me['email']],
                'tag' => 'blueledger-' . $row['id'],
            ]);
        } catch (Throwable $e) {
            error_log('[blueledger] 알림 실패: ' . $e->getMessage());
        }
    });
}

function bl_reviewer_emails(): array {
    $st = bl_db()->prepare("SELECT email FROM bl_travel_roles WHERE role IN (?, ?)");
    $st->execute([ROLE_ADMIN, ROLE_APPROVER]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** 올린 사람과 출장자. 출장자는 이름으로만 남아 명단에서 이메일을 찾는다 */
function bl_owner_emails(array $row): array {
    $out = $row['created_by'] !== '' ? [strtolower($row['created_by'])] : [];
    foreach (bl_members() as $m) {
        if ($m['name'] === $row['traveler']) $out[] = $m['email'];
    }
    return array_values(array_unique($out));
}

function bl_notice_seen(int $uid, string $tripId): void {
    try {
        require_once __DIR__ . '/../../core/push.php';
        push_inbox_mark_read_url($uid, 'blueledger.', bl_trip_url($tripId));
    } catch (Throwable $e) {
        error_log('[blueledger] 알림 읽음: ' . $e->getMessage());
    }
}

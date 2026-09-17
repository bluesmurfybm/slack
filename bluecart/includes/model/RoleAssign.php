<?php
declare(strict_types=1);

final class RoleAssign
{
    public const TYPES = ['REVIEWER', 'BUYER', 'ADMIN'];

    /** @var array<string,array>|null 요청 한 건 안에서만 쓰는 캐시 */
    private static ?array $cache = null;

    /** @return array<int,array{user_id:string,user_name:string}> */
    public static function byType(string $roleType): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            $rows = bc_fetch_all(
                'SELECT role_type, user_id, user_name FROM bc_role_assign
                  WHERE is_active = 1 ORDER BY user_name ASC'
            );
            foreach ($rows as $r) {
                self::$cache[$r['role_type']][] = [
                    'user_id'   => $r['user_id'],
                    'user_name' => $r['user_name'],
                ];
            }
        }
        return self::$cache[$roleType] ?? [];
    }

    /** 배정이 바뀐 직후처럼 다시 읽어야 할 때. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public static function idsByType(string $roleType): array
    {
        return array_column(self::byType($roleType), 'user_id');
    }

    /**
     * 구매담당자가 한 명뿐이면 그 사람을 돌려준다.
     * 이 경우 담당을 따로 지정할 이유가 없으므로 지정 단계를 건너뛴다.
     *
     * @return array{user_id:string,user_name:string}|null
     */
    public static function soleBuyer(): ?array
    {
        $buyers = self::byType('BUYER');
        return count($buyers) === 1 ? $buyers[0] : null;
    }

    /**
     * 역할 구성원을 통째로 교체한다. (관리자 화면의 다중선택 저장)
     * 기존 배정은 비활성화가 아니라 삭제 — 배정 이력은 감사 대상이 아님.
     */
    public static function replace(string $roleType, array $userIds, string $actorId): void
    {
        if (!in_array($roleType, self::TYPES, true)) {
            throw new InvalidArgumentException('알 수 없는 역할: ' . $roleType);
        }
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        $members = bc_directory_map($userIds);

        self::clearCache();

        bc_transaction(function () use ($roleType, $userIds, $members, $actorId) {
            bc_query('DELETE FROM bc_role_assign WHERE role_type = ?', [$roleType]);
            if (!$userIds) {
                return;
            }
            $stmt = bc_db()->prepare(
                'INSERT INTO bc_role_assign (role_type, user_id, user_name, is_active, assigned_by)
                 VALUES (?, ?, ?, 1, ?)'
            );
            foreach ($userIds as $uid) {
                $name = $members[$uid]['name'] ?? $uid;
                $stmt->execute([$roleType, $uid, $name, $actorId]);
            }
        });

        self::clearCache();
    }

    /**
     * 알림 수신자 해석.
     * REQUESTER 는 요청 건에 종속이므로 $request 를 함께 받는다.
     *
     * @return array<int,array{id:string,name:string,email:?string,slack_id:?string}>
     */
    public static function resolveRecipients(string $targetRole, array $request): array
    {
        if ($targetRole === 'REQUESTER') {
            $ids = [$request['requester_id']];
        } elseif ($targetRole === 'BUYER' && !empty($request['assignee_id'])) {
            // 담당이 지정된 건은 그 사람에게만 간다. 지정 전에는 전원에게 간다.
            $ids = [$request['assignee_id']];
        } else {
            $ids = self::idsByType($targetRole);
        }
        if (!$ids) {
            return [];
        }

        $map = bc_directory_map($ids);
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id'       => $id,
                'name'     => $map[$id]['name']     ?? $id,
                'email'    => $map[$id]['email']    ?? null,
                'slack_id' => $map[$id]['slack_id'] ?? null,
            ];
        }
        return $out;
    }
}

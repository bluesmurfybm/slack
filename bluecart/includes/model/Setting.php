<?php
declare(strict_types=1);

final class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = bc_fetch_value('SELECT v FROM bc_setting WHERE k = ?', [$key]);
        return $v === null ? $default : (string)$v;
    }

    public static function set(string $key, ?string $value, ?string $actorId = null): void
    {
        bc_query(
            'INSERT INTO bc_setting (k, v, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)',
            [$key, $value, $actorId]
        );
    }

    public static function forget(string $key): void
    {
        bc_query('DELETE FROM bc_setting WHERE k = ?', [$key]);
        unset(self::$secretCache[$key]);
    }

    // -----------------------------------------------------------------
    // 비밀값 (슬랙 봇 토큰 등)
    //
    // 암호화해서 같은 bc_setting 에 넣는다. 읽기는 한 요청 안에서 여러 번
    // 일어나므로(발송 대상마다 토큰을 본다) 캐시해 둔다.
    // -----------------------------------------------------------------

    /** @var array<string,string> */
    private static array $secretCache = [];

    /** 복호화한 원문. 값이 없거나 키가 맞지 않으면 ''. */
    public static function secret(string $key): string
    {
        if (isset(self::$secretCache[$key])) {
            return self::$secretCache[$key];
        }
        $enc = (string)self::get($key, '');
        if ($enc === '') {
            return self::$secretCache[$key] = '';
        }
        try {
            return self::$secretCache[$key] = bc_decrypt($enc);
        } catch (Throwable $e) {
            // 키를 못 찾는 상황이라도 알림 발송만 조용히 건너뛰게 한다.
            error_log('[BlueCart] secret decrypt failed (' . $key . '): ' . $e->getMessage());
            return self::$secretCache[$key] = '';
        }
    }

    /** 빈 값이나 null 이면 저장된 값을 지운다. */
    public static function setSecret(string $key, ?string $value, ?string $actorId = null): void
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            self::forget($key);
            return;
        }
        self::set($key, bc_encrypt($value), $actorId);
        self::$secretCache[$key] = $value;
    }

    /**
     * 알림 설정 매트릭스 전체.
     * @return array<string,array<string,array<string,bool>>> [event][role][channel] = enabled
     */
    public static function notifyMatrix(): array
    {
        $rows = bc_fetch_all('SELECT event_code, target_role, channel, is_enabled FROM bc_notify_setting');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['event_code']][$r['target_role']][$r['channel']] = (bool)$r['is_enabled'];
        }
        return $out;
    }

    /** 특정 이벤트/역할에 켜져 있는 채널 목록. */
    public static function enabledChannels(string $event, string $role): array
    {
        $rows = bc_fetch_all(
            'SELECT channel FROM bc_notify_setting
              WHERE event_code = ? AND target_role = ? AND is_enabled = 1',
            [$event, $role]
        );
        return array_column($rows, 'channel');
    }

    public static function saveNotifyMatrix(array $matrix, string $actorId): void
    {
        bc_transaction(function () use ($matrix, $actorId) {
            $stmt = bc_db()->prepare(
                'INSERT INTO bc_notify_setting (event_code, target_role, channel, is_enabled, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)'
            );
            foreach ($matrix as $event => $roles) {
                if (!isset(BC_EVENT[$event])) {
                    continue;
                }
                foreach ($roles as $role => $channels) {
                    if (!isset(BC_ROLE_LABEL[$role])) {
                        continue;
                    }
                    foreach (['EMAIL', 'SLACK_CHANNEL', 'SLACK_DM'] as $ch) {
                        $on = !empty($channels[$ch]);
                        $stmt->execute([$event, $role, $ch, $on ? 1 : 0, $actorId]);
                    }
                }
            }
        });
    }
}

<?php
/**
 * 외부 연동 자격 정보 — 구글 드라이브 · 피그마.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 비밀은 평문으로 남기지 않는다                                     │
 * │                                                                  │
 * │ 포털의 enc_token()/dec_token() 을 그대로 쓴다 (AES-256-GCM,       │
 * │ 열쇠는 config.php 의 key). portal_users.slack_token_enc 와 같은   │
 * │ 방식이다. DB 를 통째로 떠 가도 열쇠가 없으면 못 푼다.             │
 * │                                                                  │
 * │ 바꿔 말하면 **열쇠를 잃으면 토큰도 잃는다.** 그때는 다시 연결하면 │
 * │ 된다 — 복구할 수 없는 자료가 아니다.                              │
 * │                                                                  │
 * │ 밖으로 내보낼 때는 **있다/없다만** 말한다. 값 자체는 어떤 API 도  │
 * │ 돌려주지 않는다. 한 번 넣으면 사람도 다시 못 본다.                │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 행은 연결한 적이 있는 연동에만 생긴다. 미리 만들어 두지 않는다.
 */

declare(strict_types=1);

final class Integration
{
    public const GOOGLE = 'google';
    public const FIGMA  = 'figma';

    public function __construct(private PDO $pdo) {}

    /** 날것 한 줄. 비밀이 들어 있으니 화면으로 내보내지 말 것. */
    public function raw(string $provider): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM bs_integration WHERE provider = ?');
        $st->execute([$provider]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * 화면에 내려보낼 상태. **비밀은 담지 않는다.**
     *
     * @return array{provider:string, configured:bool, connected:bool, enabled:bool,
     *               usable:bool, blocked:?string, account:?string, last_error:?string,
     *               last_ok_at:?string, cooldown_until:?string, cooldown_left:int,
     *               cooldown_reason:?string, daily_cap:int, used_today:int,
     *               updated_by_name:?string, updated_at:?string}
     */
    public function status(string $provider): array
    {
        $r = $this->raw($provider);

        // '설정됨' 과 '연결됨' 은 다르다.
        //   구글 — client_id/secret 을 넣으면 설정됨, 동의까지 받아야 연결됨
        //   피그마 — 토큰 하나뿐이라 넣는 순간 둘 다 된다
        if ($provider === self::GOOGLE) {
            $configured = $r !== null && $r['client_id'] !== null && $r['client_id'] !== ''
                          && !empty($r['secret_enc']);
            $connected  = $configured && !empty($r['token_enc']);
        } else {
            $configured = $r !== null && !empty($r['secret_enc']);
            $connected  = $configured;
        }

        // 줄이 없으면 켜진 것으로 본다. 연결한 적 없는 연동을 '꺼짐' 으로
        // 보여 주면, 켜면 될 줄 알고 켜 봐야 아무 일도 안 일어난다.
        $enabled = $r === null || (int)$r['enabled'] === 1;
        $left    = $this->cooldownLeft($provider);
        $cap     = (int)($r['daily_cap'] ?? 0);
        $used    = $cap > 0 || $connected ? $this->callsToday($provider) : 0;

        $blocked = $this->whyBlocked($provider, $connected, $enabled, $left, $cap, $used,
                                     (string)($r['cooldown_reason'] ?? ''), $r['cooldown_until'] ?? null);

        return [
            'provider'        => $provider,
            'configured'      => $configured,
            'connected'       => $connected,
            'enabled'         => $enabled,
            // 지금 실제로 부를 수 있는가. 화면은 이 값 하나로 판단한다.
            'usable'          => $blocked === null,
            'blocked'         => $blocked,
            'account'         => $r['account'] ?? null,
            'last_error'      => $r['last_error'] ?? null,
            'last_ok_at'      => $r['last_ok_at'] ?? null,
            'cooldown_until'  => $r['cooldown_until'] ?? null,
            'cooldown_left'   => $left,
            'cooldown_reason' => $r['cooldown_reason'] ?? null,
            'daily_cap'       => $cap,
            'used_today'      => $used,
            'updated_by_name' => $r['updated_by_name'] ?? null,
            'updated_at'      => $r['updated_at'] ?? null,
        ];
    }

    /** 자격 정보가 갖춰졌는가. **지금 부를 수 있는가와는 다르다** — blockedReason() 을 보라. */
    public function isReady(string $provider): bool
    {
        return $this->status($provider)['connected'];
    }

    // =================================================================
    // 지금 불러도 되는가
    //
    // ┌──────────────────────────────────────────────────────────────┐
    // │ 2026-10-04 에 이것이 없어서 겪은 일                            │
    // │                                                              │
    // │ 피그마가 429 와 함께 `retry-after: 224862`(2일 14시간) 를     │
    // │ 보냈는데 코드가 그 헤더를 안 봤다. 워커는 1분마다 계속         │
    // │ 두드렸고, 며칠치 예산을 하룻밤에 태웠다.                       │
    // │                                                              │
    // │ 이제 **부르기 전에 묻는다.** 막혀 있으면 호출을 만들지조차     │
    // │ 않는다. 막는 이유는 셋이고, 사람에게 각각 다르게 말해 준다 —  │
    // │ "안 됩니다" 하나로 뭉뚱그리면 또 토큰을 의심하며 헤맨다.       │
    // └──────────────────────────────────────────────────────────────┘
    // =================================================================

    /**
     * 지금 부르면 안 되는 이유. 불러도 되면 null.
     *
     * 돌려주는 글은 **그대로 화면에 뜬다.** 사람이 다음에 무엇을 할지
     * 알 수 있게 적는다.
     */
    public function blockedReason(string $provider): ?string
    {
        return $this->status($provider)['blocked'];
    }

    /** status() 안에서만 쓴다. 이미 읽어 둔 값으로 판정해 질의를 늘리지 않는다. */
    private function whyBlocked(string $provider, bool $connected, bool $enabled,
                                int $left, int $cap, int $used,
                                string $reason, ?string $until): ?string
    {
        $who = $provider === self::GOOGLE ? '구글 드라이브' : ($provider === self::FIGMA ? '피그마' : $provider);

        if (!$connected) {
            return $provider === self::GOOGLE
                ? '구글 드라이브가 아직 연결되지 않았습니다. 관리자가 설정 화면에서 연결해야 합니다.'
                : '피그마 토큰이 아직 등록되지 않았습니다. 관리자가 설정 화면에서 넣어야 합니다.';
        }
        if (!$enabled) {
            return "관리자가 {$who} 연동을 꺼 두었습니다. 설정 화면에서 다시 켤 수 있습니다.";
        }
        if ($left > 0) {
            // bs_date() 는 helpers.php 에 있다. 이 클래스만 따로 싣는 경로가
            // 생겨도 터지지 않게 직접 확인한다 — 전에 OfficeDocumentParser
            // 상수를 안 싣고 써서 운영에서 "처리 중 오류" 가 난 적이 있다.
            $when = $until === null ? ''
                  : (function_exists('bs_date') ? bs_date($until, 'n월 j일 H:i')
                                                : (string)$until);
            return sprintf('%s 호출 제한 — %s 까지 쉽니다 (남은 시간 %s).%s',
                $who, $when, self::humanSpan($left),
                $reason === '' ? '' : ' ' . $reason);
        }
        if ($cap > 0 && $used >= $cap) {
            return sprintf('%s 오늘 호출 한도(%d회)를 다 썼습니다. 내일 0시부터 이어서 진행합니다.',
                           $who, $cap);
        }
        return null;
    }

    /** 남은 시간을 사람 말로. "2일 14시간" 이 "224862초" 보다 쓸모 있다. */
    public static function humanSpan(int $sec): string
    {
        if ($sec <= 0)    return '0초';
        if ($sec < 60)    return $sec . '초';
        if ($sec < 3600)  return intdiv($sec, 60) . '분';
        if ($sec < 86400) return intdiv($sec, 3600) . '시간 ' . intdiv($sec % 3600, 60) . '분';
        return intdiv($sec, 86400) . '일 ' . intdiv($sec % 86400, 3600) . '시간';
    }

    /** 쉬어야 하는 남은 초. 안 쉬어도 되면 0. */
    public function cooldownLeft(string $provider): int
    {
        $st = $this->pdo->prepare(
            'SELECT GREATEST(0, COALESCE(TIMESTAMPDIFF(SECOND, NOW(), cooldown_until), 0))
               FROM bs_integration WHERE provider = ?'
        );
        $st->execute([$provider]);
        return (int)$st->fetchColumn();
    }

    /**
     * 상대가 "언제까지 쉬어라" 했다. 그 말을 그대로 적는다.
     *
     * **추측하지 않는다.** Retry-After 가 2일을 가리키면 2일을 쉰다.
     * 우리가 멋대로 5분으로 줄이면 그 5분마다 또 맞고, 벌칙이 길어진다.
     *
     * 이미 더 긴 쉼이 걸려 있으면 줄이지 않는다 — 늘 더 보수적인 쪽.
     */
    public function startCooldown(string $provider, int $seconds, string $reason = ''): void
    {
        $this->ensureRow($provider);
        $seconds = max(1, min($seconds, 14 * 86400));   // 2주가 넘으면 뭔가 잘못 읽은 것이다
        $this->pdo->prepare(
            'UPDATE bs_integration
                SET cooldown_until = GREATEST(COALESCE(cooldown_until, NOW()),
                                              DATE_ADD(NOW(), INTERVAL ? SECOND)),
                    cooldown_reason = ?
              WHERE provider = ?'
        )->execute([$seconds, mb_substr($reason, 0, 200), $provider]);
    }

    /** 사람이 "지금 다시 해 보겠다" 고 할 때. 또 429 를 맞으면 그때 다시 걸린다. */
    public function clearCooldown(string $provider): void
    {
        $this->pdo->prepare(
            'UPDATE bs_integration SET cooldown_until = NULL, cooldown_reason = NULL
              WHERE provider = ?'
        )->execute([$provider]);
    }

    /** 관리자가 연동을 켜고 끈다. **토큰은 건드리지 않는다.** */
    public function setEnabled(string $provider, bool $on, array $actor): void
    {
        $this->ensureRow($provider);
        $this->pdo->prepare(
            'UPDATE bs_integration SET enabled = ?, updated_by = ?, updated_by_name = ?
              WHERE provider = ?'
        )->execute([$on ? 1 : 0, (string)($actor['id'] ?? ''), (string)($actor['name'] ?? ''), $provider]);
    }

    /** 하루 상한. 0 이면 상한 없음. 상대가 막기 전에 우리가 먼저 멈추려는 것이다. */
    public function setDailyCap(string $provider, int $cap, array $actor): void
    {
        $this->ensureRow($provider);
        $this->pdo->prepare(
            'UPDATE bs_integration SET daily_cap = ?, updated_by = ?, updated_by_name = ?
              WHERE provider = ?'
        )->execute([max(0, $cap), (string)($actor['id'] ?? ''), (string)($actor['name'] ?? ''), $provider]);
    }

    // =================================================================
    // 호출 기록 — 사용량 그래프와 한도 판정이 읽는다
    // =================================================================

    /**
     * 바깥으로 나간 호출 한 번을 적는다. **성공·실패 가리지 않고 적는다** —
     * 실패도 상대의 예산을 쓰기 때문이다.
     *
     * 기록이 실패해도 본 일은 계속한다. 기록 때문에 분석이 멈추면 안 된다.
     */
    public function logCall(string $provider, string $act, bool $ok, int $httpCode,
                            int $ms, int $items = 1, string $note = '',
                            int $tokensIn = 0, int $tokensOut = 0, int $costMicro = 0): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO bs_api_usage
                        (provider, act, ok, http_code, items, tokens_in, tokens_out,
                         cost_micro, ms, note)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $provider, mb_substr($act, 0, 40), $ok ? 1 : 0, max(0, $httpCode),
                max(0, $items), max(0, $tokensIn), max(0, $tokensOut), max(0, $costMicro),
                max(0, $ms), $note === '' ? null : mb_substr($note, 0, 200),
            ]);
        } catch (Throwable $e) {
            error_log('[BlueStudio] logCall: ' . $e->getMessage());
        }
    }

    /** 오늘 몇 번 불렀나. 하루 상한을 판정한다. */
    public function callsToday(string $provider): int
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT COUNT(*) FROM bs_api_usage
                  WHERE provider = ? AND created_at >= CURDATE()'
            );
            $st->execute([$provider]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            // 017 을 아직 안 올린 서버에서도 화면은 떠야 한다.
            return 0;
        }
    }

    /**
     * 최근 N일 날짜별 사용량. 그래프가 그대로 그린다.
     *
     * **빈 날도 0 으로 채워** 돌려준다. 호출이 없던 날이 빠지면 막대가
     * 밀려 그려져 "어제 많이 썼다" 가 "오늘 많이 썼다" 로 보인다.
     *
     * @return list<array{d:string, calls:int, fail:int, items:int, cost:int}>
     */
    public function usageSeries(string $provider, int $days = 14): array
    {
        $days = max(1, min($days, 90));
        $rows = [];
        try {
            $st = $this->pdo->prepare(
                'SELECT DATE(created_at) d, COUNT(*) calls, SUM(1 - ok) fail,
                        SUM(items) items, SUM(cost_micro) cost
                   FROM bs_api_usage
                  WHERE provider = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  GROUP BY d'
            );
            $st->execute([$provider, $days - 1]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(string)$r['d']] = $r;
            }
        } catch (Throwable $e) {
            $rows = [];
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $r = $rows[$d] ?? null;
            $out[] = [
                'd'     => $d,
                'calls' => (int)($r['calls'] ?? 0),
                'fail'  => (int)($r['fail'] ?? 0),
                'items' => (int)($r['items'] ?? 0),
                'cost'  => (int)($r['cost'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * 오래된 기록을 지운다. 워커가 가끔 부른다.
     *
     * bs_notification 에 보관 규칙을 안 둬서 계속 쌓이고 있다. 같은 실수를
     * 반복하지 않으려고 이 표는 처음부터 정해 둔다.
     */
    public function pruneUsage(int $keepDays = 90): int
    {
        try {
            $st = $this->pdo->prepare(
                'DELETE FROM bs_api_usage WHERE created_at < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 LIMIT 5000'
            );
            $st->execute([max(7, $keepDays)]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // -----------------------------------------------------------------
    // 쓰기
    // -----------------------------------------------------------------

    /**
     * 칸 몇 개만 고친다. 넘기지 않은 칸은 건드리지 않는다 —
     * client_secret 을 다시 받지 않고 client_id 만 고칠 수 있어야 한다.
     *
     * 값에 '' 을 주면 **지우라는 뜻**이다(null 로 둔다).
     */
    public function save(string $provider, array $fields, array $actor): void
    {
        $this->ensureRow($provider);

        $set = [];
        $val = [];
        foreach (['client_id', 'account', 'last_error'] as $k) {
            if (array_key_exists($k, $fields)) {
                $set[] = "`$k` = ?";
                $val[] = ($fields[$k] === '' || $fields[$k] === null) ? null : (string)$fields[$k];
            }
        }
        // 비밀 두 칸은 받는 즉시 암호화한다. 평문이 변수에 오래 머물지 않게.
        foreach (['secret' => 'secret_enc', 'token' => 'token_enc'] as $in => $col) {
            if (array_key_exists($in, $fields)) {
                $plain = (string)$fields[$in];
                $set[] = "`$col` = ?";
                $val[] = $plain === '' ? null : enc_token($plain);
            }
        }
        if ($set === []) {
            return;
        }

        $set[] = '`updated_by` = ?';
        $val[] = (string)($actor['id'] ?? '');
        $set[] = '`updated_by_name` = ?';
        $val[] = (string)($actor['name'] ?? '');

        $val[] = $provider;
        $st = $this->pdo->prepare(
            'UPDATE bs_integration SET ' . implode(', ', $set) . ' WHERE provider = ?'
        );
        $st->execute($val);
    }

    /** 비밀을 푼다. 없으면 빈 문자열. */
    public function secret(string $provider): string
    {
        $r = $this->raw($provider);
        return $r && $r['secret_enc'] ? (string)dec_token((string)$r['secret_enc']) : '';
    }

    public function token(string $provider): string
    {
        $r = $this->raw($provider);
        return $r && $r['token_enc'] ? (string)dec_token((string)$r['token_enc']) : '';
    }

    public function clientId(string $provider): string
    {
        $r = $this->raw($provider);
        return (string)($r['client_id'] ?? '');
    }

    /** 연결을 끊는다. 줄은 남기고 비밀만 지운다 — 누가 언제 껐는지가 남는다. */
    public function disconnect(string $provider, array $actor): void
    {
        $this->save($provider, [
            'secret'     => '',
            'token'      => '',
            'account'    => '',
            'last_error' => '',
        ], $actor);
    }

    /** 실제로 읽는 데 성공했다. */
    public function markOk(string $provider): void
    {
        $this->ensureRow($provider);
        $this->pdo->prepare(
            'UPDATE bs_integration SET last_ok_at = NOW(), last_error = NULL WHERE provider = ?'
        )->execute([$provider]);
    }

    /**
     * 실패를 남긴다.
     *
     * 토큰은 지우지 않는다 — 한 번 실패했다고 끊으면, 잠깐 네트워크가
     * 끊긴 것만으로 관리자가 다시 연결해야 한다.
     */
    public function markError(string $provider, string $message): void
    {
        $this->ensureRow($provider);
        $this->pdo->prepare(
            'UPDATE bs_integration SET last_error = ? WHERE provider = ?'
        )->execute([mb_substr($message, 0, 300), $provider]);
    }

    private function ensureRow(string $provider): void
    {
        $this->pdo->prepare(
            'INSERT INTO bs_integration (provider) VALUES (?)
             ON DUPLICATE KEY UPDATE provider = provider'
        )->execute([$provider]);
    }
}

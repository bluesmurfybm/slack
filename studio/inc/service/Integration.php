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
     * @return array{provider:string, configured:bool, connected:bool,
     *               account:?string, last_error:?string, last_ok_at:?string,
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

        return [
            'provider'        => $provider,
            'configured'      => $configured,
            'connected'       => $connected,
            'account'         => $r['account'] ?? null,
            'last_error'      => $r['last_error'] ?? null,
            'last_ok_at'      => $r['last_ok_at'] ?? null,
            'updated_by_name' => $r['updated_by_name'] ?? null,
            'updated_at'      => $r['updated_at'] ?? null,
        ];
    }

    /** 쓸 준비가 됐는가. 링크를 'pending' 으로 받을지 가를 때 쓴다. */
    public function isReady(string $provider): bool
    {
        return $this->status($provider)['connected'];
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

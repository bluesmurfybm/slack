<?php
/**
 * 개인 API 토큰. 사람당 하나이고 만료는 없다.
 */

require_once __DIR__ . '/crypto.php';

// 요청마다 쓰지 않도록 마지막 사용 기록은 이 간격(초)보다 자주 고치지 않는다
const PORTAL_API_TOKEN_TOUCH_SEC = 60;

/**
 * @param PDO $pdo
 */
function portal_api_token_migrate($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `portal_api_token` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL COMMENT 'portal_users.id',
            `token_hash` CHAR(64) NOT NULL COMMENT 'sha256(토큰) hex',
            `token_enc` TEXT NOT NULL COMMENT '마이페이지에서 다시 보여 줄 암호화한 원문',
            `created_at` DATETIME NOT NULL,
            `rotated_at` DATETIME NULL,
            `last_used_at` DATETIME NULL,
            `last_used_ip` VARCHAR(45) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_apitoken_user` (`user_id`),
            UNIQUE KEY `uq_apitoken_hash` (`token_hash`),
            CONSTRAINT `fk_apitoken_user` FOREIGN KEY (`user_id`)
                REFERENCES `portal_users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='개인 API 토큰. 사람당 하나'
    ");
}

/**
 * @return string
 */
function portal_api_token_new() {
    return 'iwk_' . bin2hex(random_bytes(32));
}

/**
 * 토큰 주인을 찾는다.
 * @param PDO $pdo
 * @param string $token
 * @param string $ip
 * @return array|null portal_users 행
 */
function portal_api_token_user($pdo, $token, $ip) {
    // 옛 공용 토큰처럼 형식이 다른 값은 DB 까지 가지 않는다
    if (!preg_match('/^iwk_[0-9a-f]{64}$/', (string)$token)) return null;

    $st = $pdo->prepare("SELECT id, user_id, last_used_at FROM portal_api_token WHERE token_hash = ?");
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $now = time();
    if ($row['last_used_at'] === null || $now - strtotime($row['last_used_at']) >= PORTAL_API_TOKEN_TOUCH_SEC) {
        $pdo->prepare("UPDATE portal_api_token SET last_used_at = ?, last_used_ip = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s', $now), substr((string)$ip, 0, 45), $row['id']]);
    }

    $st = $pdo->prepare("SELECT * FROM portal_users WHERE id = ?");
    $st->execute([$row['user_id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 마이페이지에 보여 줄 내 토큰.
 * @param PDO $pdo
 * @param int $userId
 * @return array has, token, created_at, rotated_at, last_used_at, last_used_ip
 */
function portal_api_token_get($pdo, $userId) {
    $st = $pdo->prepare("SELECT token_enc, created_at, rotated_at, last_used_at, last_used_ip FROM portal_api_token WHERE user_id = ?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['has' => false, 'token' => '', 'created_at' => null, 'rotated_at' => null, 'last_used_at' => null, 'last_used_ip' => null];
    }

    return [
        'has' => true,
        'token' => dec_token($row['token_enc']),
        'created_at' => $row['created_at'],
        'rotated_at' => $row['rotated_at'],
        'last_used_at' => $row['last_used_at'],
        'last_used_ip' => $row['last_used_ip'],
    ];
}

/**
 * 내 토큰을 돌려준다. 없으면 새로 발급한다.
 * @param PDO $pdo
 * @param int $userId
 * @return string
 */
function portal_api_token_ensure($pdo, $userId) {
    $have = portal_api_token_get($pdo, $userId);
    if ($have['has']) {
        // 키가 바뀌어 원문을 풀 수 없으면 새 값으로 바꾼다
        return $have['token'] !== '' ? $have['token'] : portal_api_token_rotate($pdo, $userId);
    }

    $token = portal_api_token_new();
    try {
        $pdo->prepare("INSERT INTO portal_api_token (user_id, token_hash, token_enc, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$userId, hash('sha256', $token), enc_token($token), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        // 두 PC 가 동시에 처음 발급하면 늦은 쪽이 UNIQUE(user_id) 에 걸린다. 먼저 만든 값을 함께 쓴다
        $have = portal_api_token_get($pdo, $userId);
        if (!$have['has']) throw $e;
        return $have['token'];
    }
    return $token;
}

/**
 * 내 토큰을 새 값으로 바꾼다.
 * @param PDO $pdo
 * @param int $userId
 * @return string
 */
function portal_api_token_rotate($pdo, $userId) {
    $token = portal_api_token_new();
    $st = $pdo->prepare("UPDATE portal_api_token SET token_hash = ?, token_enc = ?, rotated_at = ?, last_used_at = NULL, last_used_ip = NULL WHERE user_id = ?");
    $st->execute([hash('sha256', $token), enc_token($token), date('Y-m-d H:i:s'), $userId]);
    if ($st->rowCount() === 0) return portal_api_token_ensure($pdo, $userId);
    return $token;
}

/**
 * @param PDO $pdo
 * @param int $userId
 */
function portal_api_token_delete($pdo, $userId) {
    $pdo->prepare("DELETE FROM portal_api_token WHERE user_id = ?")->execute([$userId]);
}

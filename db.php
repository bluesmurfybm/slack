<?php
/**
 * 포털(blue-iwork) DB 연결.
 *  - slack 모듈과 같은 slackapi DB를 그대로 씀. 여기서는 포털 전용 테이블만 관리.
 */

function portal_db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $d   = $cfg['db'];
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $dsn = "mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], $opt);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}`
                CHARACTER SET {$d['charset']} COLLATE {$d['charset']}_unicode_ci");
    $pdo->exec("USE `{$d['name']}`");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `portal_users` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`            VARCHAR(60)  NOT NULL COMMENT '이름',
            `email`           VARCHAR(190) NOT NULL COMMENT '로그인 ID',
            `pw_hash`         VARCHAR(255) NOT NULL COMMENT 'password_hash() 해시',
            `slack_token_enc` TEXT         NULL     COMMENT 'AES-256-GCM 암호화된 슬랙 토큰',
            `color`           VARCHAR(7)   NULL     COMMENT '고유색상(#rrggbb) — 아바타/뱃지 표시용',
            `created_at`      DATETIME     NOT NULL,
            `updated_at`      DATETIME     NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    add_column_if_missing($pdo, "ALTER TABLE `portal_users` ADD COLUMN `color` VARCHAR(7) NULL COMMENT '고유색상(#rrggbb) — 아바타/뱃지 표시용' AFTER `slack_token_enc`");

    // 사내 인원 13명 + book 모듈에서 쓰던 개인별 고유색을 이어받되, 아바타처럼 큰 면적을 단색으로
    // 채우면 book의 원래 뱃지(연한 배경 위 작은 글자)보다 훨씬 쨍하게 보여서 채도를 낮추고
    // 명도를 살짝 올렸다(색상은 원래 값에서 파생 — 사람 구분은 그대로 유지됨).
    $seed = [
        ['김호영', 'kimhy@bluesoft.co.kr',    '#B6574A'], ['김지안', 'jian@bluesoft.co.kr',   '#457797'],
        ['박성철', 'scpark@bluesoft.co.kr',   '#BA7D4D'], ['김태주', 'pink@bluesoft.co.kr',   '#458278'],
        ['안정민', 'venus@bluesoft.co.kr',    '#A58838'], ['조성훈', 'akddd@bluesoft.co.kr',  '#818C46'],
        ['진소현', 'lenda83@bluesoft.co.kr',  '#548058'], ['김아랑', 'amitoa@bluesoft.co.kr', '#5A64AD'],
        ['박화랑', 'phr@bluesoft.co.kr',      '#8164AB'], ['유병문', 'bnmmnbhj@bluesoft.co.kr', '#9B5797'],
        ['유승인', 'siyu@bluesoft.co.kr',     '#B25D7E'], ['이한재', 'hjlee@bluesoft.co.kr',  '#8C7055'],
        ['이준영', 'jun0@bluesoft.co.kr',     '#606D79'],
    ];
    // book에서 쓰던 원래(더 쨍한) 값 — 이미 이 값으로 심어진 설치본을 새 팔레트로 1회 옮기는 데만 사용.
    $oldSeedColors = [
        '#A8392B', '#B0642A', '#94741C', '#6C782C', '#3B6B40', '#2C6D62', '#2A6184',
        '#3A46A0', '#68429F', '#883C84', '#A83964', '#78593B', '#485663',
    ];

    // 최초 실행 시 시드(초기 비번 blue$123, bcrypt 해시)
    $count = (int)$pdo->query("SELECT COUNT(*) FROM portal_users")->fetchColumn();
    if ($count === 0) {
        $hash = password_hash('blue$123', PASSWORD_DEFAULT);
        $ins  = $pdo->prepare("INSERT INTO portal_users (name, email, pw_hash, color, created_at) VALUES (?, ?, ?, ?, NOW())");
        foreach ($seed as [$name, $email, $color]) {
            $ins->execute([$name, $email, $hash, $color]);
        }
        error_log('[blue-iwork] portal_users ' . count($seed) . '명 시드 완료 (초기 비번 blue$123)');
    } else {
        // 이미 있는 설치본: 색이 아예 없으면 채우고, 옛 팔레트(쨍한 값) 그대로면 새 팔레트로 갈아탄다.
        // 그 외(본인이 직접 고른 색)는 건드리지 않는다.
        $placeholders = implode(',', array_fill(0, count($oldSeedColors), '?'));
        $upd = $pdo->prepare("UPDATE portal_users SET color=? WHERE email=? AND (color IS NULL OR color='' OR color IN ($placeholders))");
        foreach ($seed as [$name, $email, $color]) {
            $upd->execute(array_merge([$color, $email], $oldSeedColors));
        }
    }

    return $pdo;
}

/** ALTER TABLE ADD COLUMN 안전 실행 — 이미 있는 컬럼 에러(42S21)만 무시(동시요청 경쟁 대비) */
function add_column_if_missing($pdo, $sql) {
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S21') throw $e;
    }
}

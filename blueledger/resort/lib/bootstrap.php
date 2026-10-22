<?php
declare(strict_types=1);

/*
 * 리조트 예약 · 식대 정산 — 공통 함수 (DB, 로그인 사용자)
 *
 *  BlueLedger 모듈의 한 화면이다. 로그인은 포털 세션, 직원 명단은 portal_users, 표는 포털 DB 의
 *  bl_resort_* (blueledger/db.php 의 BL_SCHEMA) 를 그대로 쓴다. 따로 쓰던 계정·비밀번호·설정 파일은 없앴다.
 */

require_once __DIR__ . '/../../guard.php';   // 포털 세션, bl_db(), CSRF, BlError

const RESORT_VERSION = '1.13.1';
// 영수증 원본. 출장여비와 같이 blueledger/var 아래에 두어 웹에서 직접 열리지 않게 한다(README 의 nginx 설정).
const RESORT_UPLOAD_DIR = __DIR__ . '/../../var/resort';
const RESORT_MAX_UPLOAD_MB = 10;

function db(): PDO
{
    return bl_db();
}

function is_mysql(): bool
{
    return !bl_is_sqlite(db());
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** 한글을 깨뜨리지 않고 글자 수로 자르기 (mbstring 없이 동작) */
function cut($s, int $max): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s) ?? '');
    if ($s === '') return '';
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) return $m[0];
    return '';
}

/** YYYY-MM-DD 이면 그대로, 아니면 null */
function date_or_null($v): ?string
{
    $v = trim((string)$v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
}

function money($v): int
{
    $n = (int)preg_replace('/[^\d]/', '', (string)$v);
    return max(0, min($n, 2000000000));
}

/** 체크인 날짜로 이용기간 연도 계산 (5월~다음해 4월) */
function period_of(string $ymd): string
{
    $y = (int)substr($ymd, 0, 4);
    $m = (int)substr($ymd, 5, 2);
    return (string)($m >= 5 ? $y : $y - 1);
}

function audit(?string $email, string $action, string $target = '', string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO bl_resort_log (id, at, actor, action, target, detail) VALUES (?,?,?,?,?,?)')
            ->execute([bin2hex(random_bytes(12)), now(), (string)$email, cut($action, 20), cut($target, 40), cut($detail, 500)]);
    } catch (Throwable $e) {
        // 기록 실패는 업무를 막지 않음
    }
}

/* ── 로그인 사용자 ─────────────────────────────────────────── */

/**
 * 포털에 로그인한 사람. 돌려주는 값: ['id'(=이메일),'email','name','color','role'('admin'|'staff')]
 * id 를 이메일로 둔 것은 예약·첨부의 created_by·uploaded_by 가 이메일이기 때문이다.
 */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $p = current_portal_user();
    if (!$p) return $cache = null;
    $email = strtolower((string)$p['email']);
    return $cache = [
        'id' => $email, 'email' => $email, 'name' => (string)$p['name'], 'color' => user_color($p),
        'role' => resort_role_of($email),
    ];
}

function resort_role_of(string $email): string
{
    $st = db()->prepare('SELECT role FROM bl_resort_roles WHERE email = ?');
    $st->execute([strtolower($email)]);
    return $st->fetchColumn() === 'admin' ? 'admin' : 'staff';
}

function is_admin(?array $u): bool
{
    return $u !== null && $u['role'] === 'admin';
}

/* ── 첨부파일 형식 확인 (확장자가 아니라 파일 내용으로 판별) ─────── */

function sniff_type(string $path): ?array
{
    $h = @file_get_contents($path, false, null, 0, 16);
    if ($h === false || strlen($h) < 4) return null;
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return ['image/jpeg', 'jpg'];
    if (strncmp($h, "\x89PNG\r\n\x1A\n", 8) === 0) return ['image/png', 'png'];
    if (strncmp($h, 'GIF87a', 6) === 0 || strncmp($h, 'GIF89a', 6) === 0) return ['image/gif', 'gif'];
    if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') return ['image/webp', 'webp'];
    if (strncmp($h, '%PDF-', 5) === 0) return ['application/pdf', 'pdf'];
    return null;
}

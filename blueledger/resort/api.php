<?php
declare(strict_types=1);

/* 리조트 예약 · 식대 정산 — 화면이 호출하는 API (JSON). 로그인은 포털이 맡는다 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/repo.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function need_user(): array
{
    $u = current_user();
    if (!$u) throw new ApiError('login', '로그인이 필요합니다.', 401);
    return $u;
}

function need_admin(): array
{
    $u = need_user();
    if (!is_admin($u)) throw new ApiError('forbidden', '관리자만 할 수 있습니다.', 403);
    return $u;
}

$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        // 포털 세션의 토큰(출장여비와 같은 bl_csrf). 없거나 다르면 419
        $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($tok) || $tok === '' || !hash_equals((string)($_SESSION['bl_csrf'] ?? ''), $tok)) {
            throw new ApiError('csrf', '화면이 오래되었습니다. 새로고침해 주세요.', 419);
        }
    }
    // 세션은 읽기만 한다. 쥐고 있으면 같은 사람의 동시 요청이 줄을 선다.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    switch ("$method $a") {

        case 'GET data': {
            $u = need_user();
            $res = [
                'ok' => true,
                'me' => ['id' => $u['id'], 'name' => $u['name'], 'role' => $u['role']],
                'settings' => load_settings(),
                'bookings' => list_bookings($u),
                'serverTime' => now(),
            ];
            if (is_admin($u)) $res['users'] = list_roles();
            out($res);
        }

        case 'POST save': {
            $u = need_user();
            $b = body();
            $r = save_booking($u, (string)($b['id'] ?? ''), (array)($b['data'] ?? []), isset($b['prevVer']) && $b['prevVer'] !== null ? (int)$b['prevVer'] : null);
            out(['ok' => true] + $r);
        }

        case 'POST delete': {
            $u = need_admin();
            delete_booking($u, (string)(body()['id'] ?? ''));
            out(['ok' => true]);
        }

        case 'POST settings': {
            $u = need_admin();
            save_settings(body(), $u['email']);
            audit($u['id'], 'settings');
            out(['ok' => true]);
        }

        case 'POST upload': {
            $u = need_user();
            if (empty($_FILES['f'])) throw new ApiError('upload_failed', '파일이 없습니다.');
            out(['ok' => true] + store_upload($u, $_FILES['f']));
        }

        case 'GET file': {
            $u = need_user();
            $st = db()->prepare('SELECT * FROM bl_resort_files WHERE id = ?');
            $st->execute([(string)($_GET['id'] ?? '')]);
            $f = $st->fetch();
            if (!$f || !can_read_file($u, $f)) throw new ApiError('not_found', '파일을 찾을 수 없습니다.', 404);
            $abs = upload_dir() . '/' . $f['path'];
            if (!is_file($abs)) throw new ApiError('not_found', '파일이 서버에 없습니다.', 404);
            header('Content-Type: ' . $f['mime']);
            header('Content-Length: ' . filesize($abs));
            header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($f['orig_name'] ?: ('file.' . pathinfo($abs, PATHINFO_EXTENSION))));
            header('Cache-Control: private, max-age=86400');
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
            readfile($abs);
            exit;
        }

        case 'POST role': {
            $u = need_admin();
            $b = body();
            $role = ($b['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            set_role($u, (string)($b['email'] ?? ''), $role);
            audit($u['id'], 'role', cut($b['email'] ?? '', 40), $role);
            out(['ok' => true, 'users' => list_roles()]);
        }

        default:
            throw new ApiError('not_found', '없는 요청입니다.', 404);
    }
} catch (ApiError $e) {
    out(['ok' => false, 'code' => $e->codeName, 'error' => $e->getMessage()], $e->http);
} catch (Throwable $e) {
    error_log('[resort] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['ok' => false, 'code' => 'server', 'error' => '서버 오류가 났습니다. 잠시 후 다시 시도해 주세요.'], 500);
}

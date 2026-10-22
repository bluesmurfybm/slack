<?php
/**
 * BlueLedger 출장여비 JSON API — 프런트 컨트롤러. 경로는 ?p= 로 받는다.
 *
 *   GET  state            로그인 사용자 + 보이는 청구서 + 설정(유가 포함)
 *   GET  receipt&id=      영수증 열기 (권한 확인 뒤)
 *   GET  export&year=     CSV 내보내기 (보이는 건만)
 *   GET  roles            권한 목록 (정산 담당)
 *   POST trips            저장 — 새 청구·수정·승인·정산 모두
 *   POST trips/delete     삭제 (정산 담당)
 *   POST receipts         영수증 올리기 (multipart 'file')
 *   POST settings         설정 저장 (정산 담당)
 *   POST prices/refresh   오피넷에서 지금 받기 (정산 담당)
 *   POST prices/apply     화면 유가를 주유비 산정 기준으로 (정산 담당)
 *   POST roles            권한 바꾸기 (정산 담당)
 *
 * POST 는 모두 X-CSRF-Token 머리가 있어야 한다.
 */

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/trips.php';
require_once __DIR__ . '/lib/storage.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') $method = 'GET';
$path = trim((string)($_GET['p'] ?? ''), '/');

try {
    $me = bl_identity();
    if (!$me) throw new BlError('로그인이 필요합니다', 401);
    if ($method === 'POST') bl_verify_csrf();
    // 세션은 더 쓰지 않는다. 쥐고 있으면 같은 사람의 동시 요청이 줄을 선다.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $pdo = bl_db();

    switch ("$method $path") {
        case 'GET state':
            bl_maybe_refresh_prices($pdo);
            jsend([
                'me'       => ['name' => $me['name'], 'email' => $me['email'], 'role' => $me['role'], 'color' => $me['color']],
                'trips'    => bl_visible_trips($pdo, $me),
                'settings' => bl_client_settings($pdo, $me),
            ]);

        case 'GET receipt':
            bl_send_receipt($pdo, $me, $_GET['id'] ?? '');

        case 'GET export':
            $year  = (string)($_GET['year'] ?? 'all');
            $trips = bl_visible_trips($pdo, $me);
            if ($year !== 'all') $trips = array_filter($trips, fn($t) => strncmp($t['date'], $year, 4) === 0);
            bl_send_csv($trips, $year);

        case 'GET roles':
            bl_require_admin($me, '권한 보기');
            jsend(['users' => bl_role_list($pdo)]);

        case 'POST trips':
            jsend(['ok' => true, 'id' => bl_save_trip($pdo, $me, body_json())]);

        case 'POST trips/delete':
            bl_require_admin($me, '삭제');
            bl_delete_trip($pdo, body_json()['id'] ?? '');
            jsend(['ok' => true]);

        case 'POST receipts':
            jsend(bl_upload_receipt($pdo, $me));

        case 'POST settings':
            bl_require_admin($me, '설정');
            bl_save_settings($pdo, body_json());
            jsend(['ok' => true]);

        case 'POST prices/refresh':
            bl_require_admin($me, '유가 갱신');
            $p = bl_refresh_prices($pdo);
            if ($p['error']) throw new BlError($p['error'], 502);
            jsend(['ok' => true, 'prices' => $p]);

        case 'POST prices/apply':
            bl_require_admin($me, '산정 기준 변경');
            bl_apply_prices($pdo);
            jsend(['ok' => true]);

        case 'POST roles':
            bl_require_admin($me, '권한 변경');
            bl_set_role($pdo, $me, body_json());
            jsend(['ok' => true, 'users' => bl_role_list($pdo)]);
    }
    throw new BlError('없는 주소입니다', 404);
} catch (BlError $e) {
    jfail($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[blueledger] ' . $e);
    jfail('요청을 처리하지 못했습니다', 500);
}

/** 포털 계정 전체 + 각자의 출장여비 권한 */
function bl_role_list(PDO $pdo) {
    $roles = $pdo->query("SELECT email, role FROM bl_travel_roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach (bl_members() as $m) {
        $out[] = ['name' => $m['name'], 'email' => $m['email'], 'role' => $roles[$m['email']] ?? ROLE_STAFF];
    }
    return $out;
}

function bl_set_role(PDO $pdo, array $me, array $body) {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $role  = (string)($body['role'] ?? '');
    if (!in_array($role, ROLES, true)) throw new BlError('권한이 올바르지 않습니다', 400);
    if (!in_array($email, array_column(bl_members(), 'email'), true)) throw new BlError('포털에 없는 계정입니다', 404);
    if ($email === $me['email'] && $role !== ROLE_ADMIN) throw new BlError('본인의 정산 관리자 권한은 해제할 수 없습니다', 400);

    if ($role === ROLE_STAFF) {
        $pdo->prepare("DELETE FROM bl_travel_roles WHERE email=?")->execute([$email]);
    } else {
        bl_upsert($pdo, 'bl_travel_roles', ['email' => $email, 'role' => $role, 'updated_by' => $me['email'], 'updated_at' => now_stamp()], ['email']);
    }
}

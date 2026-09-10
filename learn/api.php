<?php
/**
 * BlueLearn JSON API — 프런트 컨트롤러.
 *
 * 화면(static/*.js)은 FastAPI 시절의 경로를 그대로 쓴다. core.js 의 learnApiURL() 이
 * `/learningapi/requests/3` 을 `api.php?p=/requests/3` 으로 바꿔 여기로 보낸다.
 *
 *   GET    /whoami                                 로그인 사용자 + 화면이 쓰는 상수
 *   GET    /members                                구성원 명단
 *   GET    /requests                               목록 (비관리자는 숨김·보관 제외)
 *   POST   /requests                               신청 등록
 *   GET    /requests/{rid}                         상세 + 이수증 + 이력
 *   PUT    /requests/{rid}                         수정 (본인, 수강승인요청·무료일 때만)
 *   DELETE /requests/{rid}                         삭제 (관리자는 상태 무관)
 *   POST   /requests/{rid}/progress                진행상태 변경 (본인)
 *   POST   /requests/{rid}/{approve|reject|claim|claim-approve|claim-reject|refund}
 *   POST   /requests/{rid}/{archive|unarchive}     보관 (관리자)
 *   GET    /requests/{rid}/certs                   이수증 목록
 *   POST   /requests/{rid}/certs                   이수증 등록 (multipart)
 *   DELETE /requests/{rid}/certs/{cid}             이수증 삭제
 *   GET    /requests/{rid}/certs/{cid}/download    이수증 열기
 *   POST   /requests/{rid}/review                  강의평가·추천도·후기 (본인)
 *   GET|POST /sites, PUT|DELETE /sites/{sid}       교육 플랫폼 (관리자)
 *   GET|POST /categories, PUT /categories/recommended,
 *   PUT|DELETE /categories/{cid}, DELETE /categories/{cid}/purge
 *   GET|PUT  /policy                               환급 정책 (수정은 관리자)
 *   GET|PUT  /admins                               관리자 명단 (수정은 최고 관리자)
 */

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/policy.php';
require_once __DIR__ . '/lib/status.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/routes/identity.php';
require_once __DIR__ . '/routes/requests.php';
require_once __DIR__ . '/routes/certs.php';
require_once __DIR__ . '/routes/review.php';
require_once __DIR__ . '/routes/sites.php';
require_once __DIR__ . '/routes/categories.php';
require_once __DIR__ . '/routes/policy.php';
require_once __DIR__ . '/routes/admins.php';

$identity = learn_identity();
// 화면이 네 엔드포인트를 동시에 부른다 — 세션 잠금을 쥐고 있으면 그게 직렬화된다.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// HEAD 는 GET 과 같은 라우트로 보낸다 — 본문은 SAPI 가 알아서 버린다.
// 매핑하지 않으면 이수증 다운로드 같은 GET 전용 경로가 HEAD 에서만 404 가 된다.
if ($method === 'HEAD') $method = 'GET';
$path   = trim((string)($_GET['p'] ?? ''), '/');
$seg    = $path === '' ? [] : explode('/', $path);

try {
    // whoami 만은 미로그인에서도 답한다 — 화면이 여기서 받은 portal_url 로 되돌아간다.
    if (($seg[0] ?? '') === 'whoami') learn_route_whoami($identity);

    if (!$identity) throw new LearnError('로그인이 필요합니다', 401);
    $pdo = learn_db();

    switch ($seg[0] ?? '') {
        case 'members':    learn_route_members(); break;
        case 'requests':   learn_route_requests($pdo, $identity, $seg, $method); break;
        case 'sites':      learn_route_sites($pdo, $identity, $seg, $method); break;
        case 'categories': learn_route_categories($pdo, $identity, $seg, $method); break;
        case 'policy':     learn_route_policy($pdo, $identity, $method); break;
        case 'admins':     learn_route_admins($pdo, $identity, $method); break;
    }
    throw new LearnError('없는 API 입니다', 404);
} catch (LearnError $e) {
    jfail($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[learn] ' . $e);
    jfail('요청이 실패했습니다', 500);
}

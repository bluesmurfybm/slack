<?php
/**
 * DB 의 요청 목록을 JSON 으로 반환 (뷰어용). 로그인 필요.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();   // 세션 잠금 즉시 해제(동시요청 경합 방지)

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo  = db();
    $uid  = current_user()['id'];
    // 현재 사용자 읽음 여부(is_read) 조인 + 안읽음 우선, 그다음 최신순
    $stmt = $pdo->prepare("
        SELECT r.id, r.title, r.body, r.momo, r.lms, r.req_id, r.req, r.asg_id, r.asg,
               r.status_id, r.status, r.priority_id, r.priority, r.team_id, r.team, r.cmt_count,
               r.`eta`, r.`date`, r.`done`, r.created, r.updated, r.locked, r.edited_by, r.synced_at, r.updated_at,
               r.ai_stars, r.ai_reason, r.ai_conf, r.attachments,
               (rd.request_id IS NOT NULL) AS is_read,
               (pn.request_id IS NOT NULL) AS is_pinned,
               (hd.request_id IS NOT NULL) AS is_hidden
        FROM requests r
        LEFT JOIN user_reads rd ON rd.request_id = r.id AND rd.user_id = :uid
        LEFT JOIN user_pins  pn ON pn.request_id = r.id AND pn.user_id = :uidp
        LEFT JOIN user_hides hd ON hd.request_id = r.id AND hd.user_id = :uidh
        ORDER BY is_pinned DESC, is_read ASC, r.created DESC
    ");
    $stmt->execute([':uid' => $uid, ':uidp' => $uid, ':uidh' => $uid]);
    $rows = $stmt->fetchAll();

    // 숫자형 캐스팅
    foreach ($rows as &$r) {
        $r['created']   = (int)$r['created'];
        $r['updated']   = (int)$r['updated'];
        $r['locked']    = (int)$r['locked'];
        $r['is_read']   = (int)$r['is_read'];
        $r['is_pinned'] = (int)$r['is_pinned'];
        $r['is_hidden'] = (int)$r['is_hidden'];
        $r['cmt_count'] = (int)$r['cmt_count'];
        $r['ai_stars']  = $r['ai_stars'] !== null ? (int)$r['ai_stars'] : null;
        $r['attachments'] = $r['attachments'] ? (json_decode($r['attachments'], true) ?: []) : [];
    }
    unset($r);

    echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

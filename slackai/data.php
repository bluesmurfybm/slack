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

    // 기본: 활성(archived=0) 전체. ?archived=1: 보관 항목 페이지네이션(offset/limit, 최신순).
    $archivedMode = (isset($_GET['archived']) && $_GET['archived'] == '1');
    $params = [':uid' => $uid, ':uidp' => $uid, ':uidh' => $uid];
    $where  = "WHERE r.archived = " . ($archivedMode ? 1 : 0);
    $board  = isset($_GET['board']) ? trim($_GET['board']) : '';
    if ($board !== '' && $board !== 'all') { $where .= " AND r.board = :board"; $params[':board'] = $board; }
    $order  = $archivedMode ? "r.created DESC" : "is_pinned DESC, is_read ASC, r.created DESC";

    $total = null;
    $limitSql = "";
    if ($archivedMode) {
        // 전체 건수(페이지 계산용) — 조인 불필요, board 조건만
        $cntParams = isset($params[':board']) ? [':board' => $params[':board']] : [];
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM requests r $where");
        $cntStmt->execute($cntParams);
        $total = (int)$cntStmt->fetchColumn();

        $limitRaw = $_GET['limit'] ?? 300;
        if ($limitRaw === 'all' || (int)$limitRaw <= 0) {
            $limitSql = "";                                  // 모두
        } else {
            $limit  = min(5000, (int)$limitRaw);
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $limitSql = " LIMIT $limit OFFSET $offset";
        }
    }

    $stmt = $pdo->prepare("
        SELECT r.id, r.board, r.list_id, r.archived, r.title, r.body, r.momo, r.lms, r.req_id, r.req, r.asg_id, r.asg,
               r.status_id, r.status, r.priority_id, r.priority, r.team_id, r.team, r.cmt_count,
               r.`eta`, r.`date`, r.`done`, r.created, r.updated, r.locked, r.edited_by, r.synced_at, r.updated_at,
               r.ai_stars, r.ai_reason, r.ai_conf, r.attachments,
               t.summary_short AS ai_summary, t.difficulty AS ai_difficulty, t.updated_at AS ai_triage_at, t.repo_id AS ai_repo_id,
               p.status AS ai_plan_status, p.version AS ai_plan_version,
               j.kind AS ai_job_kind, j.status AS ai_job_status,
               tg.tags AS ai_tags_raw,
               (rd.request_id IS NOT NULL) AS is_read,
               (pn.request_id IS NOT NULL) AS is_pinned,
               (hd.request_id IS NOT NULL) AS is_hidden
        FROM requests r
        LEFT JOIN user_reads rd ON rd.request_id = r.id AND rd.user_id = :uid
        LEFT JOIN user_pins  pn ON pn.request_id = r.id AND pn.user_id = :uidp
        LEFT JOIN user_hides hd ON hd.request_id = r.id AND hd.user_id = :uidh
        LEFT JOIN ai_triage t ON t.request_id = r.id
        LEFT JOIN ai_plans p ON p.id = (SELECT p2.id FROM ai_plans p2 WHERE p2.request_id = r.id ORDER BY p2.version DESC LIMIT 1)
        LEFT JOIN ai_jobs  j ON j.id = (SELECT j2.id FROM ai_jobs j2 WHERE j2.request_id = r.id AND j2.status IN ('queued','running') ORDER BY j2.id DESC LIMIT 1)
        LEFT JOIN (SELECT rt.request_id, GROUP_CONCAT(CONCAT(g.id, '\t', g.name, '\t', g.color) ORDER BY g.sort_order, g.id SEPARATOR '\n') AS tags
                   FROM request_tags rt JOIN ai_tags g ON g.id = rt.tag_id GROUP BY rt.request_id) tg ON tg.request_id = r.id
        $where
        ORDER BY $order$limitSql
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // 숫자형 캐스팅
    foreach ($rows as &$r) {
        $r['created']   = (int)$r['created'];
        $r['updated']   = (int)$r['updated'];
        $r['locked']    = (int)$r['locked'];
        $r['is_read']   = (int)$r['is_read'];
        $r['is_pinned'] = (int)$r['is_pinned'];
        $r['is_hidden'] = (int)$r['is_hidden'];
        $r['archived']  = (int)$r['archived'];
        $r['cmt_count'] = (int)$r['cmt_count'];
        $r['ai_stars']  = $r['ai_stars'] !== null ? (int)$r['ai_stars'] : null;
        $r['attachments'] = $r['attachments'] ? (json_decode($r['attachments'], true) ?: []) : [];
        // AI 레이어(ai_triage / 최신 ai_plans / 열린 ai_jobs / request_tags) — 목록 행 배지용
        $r['ai_difficulty']   = $r['ai_difficulty']   !== null ? (int)$r['ai_difficulty']   : null;
        $r['ai_repo_id']      = $r['ai_repo_id']      !== null ? (int)$r['ai_repo_id']      : null;
        $r['ai_plan_version'] = $r['ai_plan_version'] !== null ? (int)$r['ai_plan_version'] : null;
        $tags = [];
        if ($r['ai_tags_raw'] !== null && $r['ai_tags_raw'] !== '') {
            foreach (explode("\n", $r['ai_tags_raw']) as $item) {
                $p = explode("\t", $item, 3);
                if (count($p) === 3) $tags[] = ['id' => (int)$p[0], 'name' => $p[1], 'color' => $p[2]];
            }
        }
        $r['ai_tags'] = $tags;
        unset($r['ai_tags_raw']);
    }
    unset($r);

    echo json_encode(['rows' => $rows, 'total' => $total], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

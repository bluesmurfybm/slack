<?php
/**
 * 대학 사이트 목록 API (로그인 필요)
 *  GET                → 전체 목록 JSON
 *  POST {action:create/update/delete, ...} → 추가/수정/삭제
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        // 기본은 사용중(active=1)만. 관리 페이지는 ?all=1 로 미사용 포함 전체 조회
        $where = isset($_GET['all']) ? '' : ' WHERE active=1';
        $rows = $pdo->query("SELECT id, name, ver, dev, ops, active FROM schools{$where} ORDER BY ver, name")->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['active'] = (int)$r['active']; }
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';
    $name   = trim($in['name'] ?? '');
    $ver    = trim($in['ver']  ?? '');
    $dev    = trim($in['dev']  ?? '');
    $ops    = trim($in['ops']  ?? '');
    $active = isset($in['active']) ? (int)!!$in['active'] : 1;
    $id     = (int)($in['id']  ?? 0);

    if ($action === 'create') {
        if ($name === '') throw new Exception('대학명을 입력하세요.');
        $st = $pdo->prepare("INSERT INTO schools (name, ver, dev, ops, active, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $st->execute([$name, $ver, $dev, $ops, $active]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

    } elseif ($action === 'update') {
        if ($id <= 0)      throw new Exception('잘못된 id');
        if ($name === '')  throw new Exception('대학명을 입력하세요.');
        $st = $pdo->prepare("UPDATE schools SET name=?, ver=?, dev=?, ops=?, active=?, updated_at=NOW() WHERE id=?");
        $st->execute([$name, $ver, $dev, $ops, $active, $id]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'toggle') {
        if ($id <= 0) throw new Exception('잘못된 id');
        $pdo->prepare("UPDATE schools SET active=?, updated_at=NOW() WHERE id=?")->execute([$active, $id]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'delete') {
        if ($id <= 0) throw new Exception('잘못된 id');
        $pdo->prepare("DELETE FROM schools WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]);

    } else {
        throw new Exception('알 수 없는 action');
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

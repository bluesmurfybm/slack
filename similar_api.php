<?php
/**
 * [테스트] 유사/중복 요청 찾기 API. 로그인 필요. (보관 포함 전체 대상)
 *  - 제목+내용 토큰 IDF 가중 코사인 유사도 (흔한 단어 down-weight → 노이즈 감소)
 *  - 제목 유사 0.6 + 전체 0.4
 *  GET ?id=Rec...  또는  ?q=<텍스트>   &min=0.15  &limit=30
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function toks($text) {
    $t = mb_strtolower((string)$text, 'UTF-8');
    $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    $o = [];
    foreach (preg_split('/\s+/u', trim($t)) as $w) {
        if ($w !== '' && mb_strlen($w, 'UTF-8') >= 2) $o[$w] = 1;
    }
    return $o;
}

try {
    $pdo = db();
    $id  = trim($_GET['id'] ?? '');
    $q   = trim($_GET['q'] ?? '');
    $min = isset($_GET['min']) ? (float)$_GET['min'] : 0.15;
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));

    // 전체 로드 + 토큰화 + DF
    $rows = $pdo->query("SELECT id, board, archived, title, body, status, req, asg, created FROM requests")->fetchAll();
    $N = count($rows); $df = []; $docs = [];
    foreach ($rows as $r) {
        $tt  = toks($r['title']);
        $all = $tt + toks(mb_substr((string)$r['body'], 0, 400, 'UTF-8'));
        foreach ($all as $k => $_) $df[$k] = ($df[$k] ?? 0) + 1;
        $docs[] = [
            'id' => $r['id'], 'board' => $r['board'], 'archived' => (int)$r['archived'],
            'title' => $r['title'], 'status' => $r['status'], 'req' => $r['req'], 'asg' => $r['asg'],
            'created' => (int)$r['created'],
            'snip' => mb_substr(preg_replace('/\s+/u', ' ', (string)$r['body']), 0, 140, 'UTF-8'),
            'body' => mb_substr((string)$r['body'], 0, 4000, 'UTF-8'),
            'tt' => $tt, 'all' => $all,
        ];
    }
    $wcos = function ($q, $d) use ($df, $N) {
        if (!$q || !$d) return 0.0;
        $dot = 0; $nq = 0; $nd = 0;
        foreach ($q as $t => $_) { $w = log(1 + $N / max(1, ($df[$t] ?? 1))); $w *= $w; $nq += $w; if (isset($d[$t])) $dot += $w; }
        foreach ($d as $t => $_) { $w = log(1 + $N / max(1, ($df[$t] ?? 1))); $nd += $w * $w; }
        return ($nq && $nd) ? $dot / sqrt($nq * $nd) : 0.0;
    };

    // 질의 토큰
    $qtt = null; $qall = null; $excl = ''; $self = null;
    if ($id !== '') {
        foreach ($docs as $d) { if ($d['id'] === $id) { $qtt = $d['tt']; $qall = $d['all']; $excl = $id; $self = $d; break; } }
    }
    if ($qtt === null) {
        if ($q === '') { echo json_encode(['ok' => true, 'results' => [], 'self' => null]); exit; }
        $qtt = toks($q); $qall = $qtt;
    }

    $res = [];
    foreach ($docs as $d) {
        if ($d['id'] === $excl) continue;
        $s = 0.6 * $wcos($qtt, $d['tt']) + 0.4 * $wcos($qall, $d['all']);
        if ($s >= $min) {
            unset($d['tt'], $d['all']);
            $d['score'] = round($s, 3);
            $res[] = $d;
        }
    }
    usort($res, fn($a, $b) => $b['score'] <=> $a['score']);
    if ($self) { unset($self['tt'], $self['all']); }
    echo json_encode(['ok' => true, 'results' => array_slice($res, 0, $limit), 'self' => $self], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * 유사/중복 요청 점수 계산 (순수 함수 — auth/DB 접속 없음).
 *  similar_api.php(웹) 와 similar_cli.php(워커 CLI) 가 같은 구현을 쓴다.
 *  - 제목+내용 토큰 IDF² 가중 코사인 유사도, 제목 0.6 + 전체(제목+본문 400자) 0.4
 */

/** 토큰 집합: 소문자, 문자/숫자 이외 제거, 2글자 이상 */
function similar_toks($text) {
    $t = mb_strtolower((string)$text, 'UTF-8');
    $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    $o = [];
    foreach (preg_split('/\s+/u', trim($t)) as $w) {
        if ($w !== '' && mb_strlen($w, 'UTF-8') >= 2) $o[$w] = 1;
    }
    return $o;
}

/**
 * 요청 행들로 색인 생성.
 * @param array $rows [{id,board,archived,title,body,status,req,asg,created,attachments(,done)}]
 * @return array ['N'=>int, 'df'=>[tok=>n], 'docs'=>[...]]
 */
function similar_index(array $rows) {
    $N = count($rows); $df = []; $docs = [];
    foreach ($rows as $r) {
        $tt  = similar_toks($r['title']);
        $all = $tt + similar_toks(mb_substr((string)$r['body'], 0, 400, 'UTF-8'));
        foreach ($all as $k => $_) $df[$k] = ($df[$k] ?? 0) + 1;
        $docs[] = [
            'id' => $r['id'], 'board' => $r['board'], 'archived' => (int)$r['archived'],
            'title' => $r['title'], 'status' => $r['status'], 'req' => $r['req'], 'asg' => $r['asg'],
            'done' => $r['done'] ?? null,
            'created' => (int)$r['created'],
            'snip' => mb_substr(preg_replace('/\s+/u', ' ', (string)$r['body']), 0, 140, 'UTF-8'),
            'body' => mb_substr((string)$r['body'], 0, 4000, 'UTF-8'),
            'attachments' => !empty($r['attachments']) ? (json_decode($r['attachments'], true) ?: []) : [],
            'tt' => $tt, 'all' => $all,
        ];
    }
    return ['N' => $N, 'df' => $df, 'docs' => $docs];
}

/** DB 전체 로드 → 색인 */
function similar_load_index(PDO $pdo) {
    $rows = $pdo->query("SELECT id, board, archived, title, body, status, req, asg, created, `done`, attachments FROM requests")->fetchAll();
    return similar_index($rows);
}

/**
 * 질의: $id(기존 항목을 질의로, 자기 자신 제외) 또는 $q(자유 텍스트).
 * @return array ['results'=>[...score desc, limit], 'self'=>?array]
 */
function similar_query(array $index, $id, $q, $min = 0.15, $limit = 30) {
    $df = $index['df']; $N = $index['N']; $docs = $index['docs'];
    $wcos = function ($qv, $d) use ($df, $N) {
        if (!$qv || !$d) return 0.0;
        $dot = 0; $nq = 0; $nd = 0;
        foreach ($qv as $t => $_) { $w = log(1 + $N / max(1, ($df[$t] ?? 1))); $w *= $w; $nq += $w; if (isset($d[$t])) $dot += $w; }
        foreach ($d as $t => $_) { $w = log(1 + $N / max(1, ($df[$t] ?? 1))); $nd += $w * $w; }
        return ($nq && $nd) ? $dot / sqrt($nq * $nd) : 0.0;
    };

    $id = trim((string)$id); $q = trim((string)$q);
    $qtt = null; $qall = null; $excl = ''; $self = null;
    if ($id !== '') {
        foreach ($docs as $d) { if ($d['id'] === $id) { $qtt = $d['tt']; $qall = $d['all']; $excl = $id; $self = $d; break; } }
    }
    if ($qtt === null) {
        if ($q === '') return ['results' => [], 'self' => null];
        $qtt = similar_toks($q); $qall = $qtt;
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
    if ($self) unset($self['tt'], $self['all']);
    return ['results' => array_slice($res, 0, max(1, (int)$limit)), 'self' => $self];
}

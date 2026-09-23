<?php
/**
 * 유사 요청 CLI (워커 전용). 웹 API 와 같은 similar_lib.php 를 쓴다.
 *  php slackai/similar/similar_cli.php --id Rec… [--limit 20] [--min 0.15]
 *  php slackai/similar/similar_cli.php --q "텍스트" [--limit 20] [--min 0.15]
 *  → stdout JSON {ok, results:[{id,board,archived,title,status,req,asg,done,created,snip,score,...}], self}
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/similar_lib.php';

$o     = getopt('', ['id:', 'q:', 'limit:', 'min:']);
$id    = trim((string)($o['id'] ?? ''));
$q     = trim((string)($o['q'] ?? ''));
$limit = min(100, max(1, (int)($o['limit'] ?? 20)));
$min   = isset($o['min']) ? (float)$o['min'] : 0.15;

try {
    $out = similar_query(similar_load_index(db()), $id, $q, $min, $limit);
    // CLI 는 첨부/본문 전체는 필요 없다 → 가볍게
    foreach ($out['results'] as &$r) { unset($r['attachments']); $r['body'] = mb_substr((string)$r['body'], 0, 1500, 'UTF-8'); }
    unset($r);
    if ($out['self']) { unset($out['self']['attachments']); }
    echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}

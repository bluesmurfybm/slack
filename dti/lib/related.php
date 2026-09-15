<?php
/**
 * 연관 아티클 점수. 분야·키워드·제목만 본다 — 팀·매거진이 같다는 건 내용이 비슷하다는 뜻이
 * 아니라서 뺐다. 난수를 쓰지 않는다: 같은 두 아티클은 언제 봐도 같은 점수여야 한다.
 */

const DTI_RELATED_SAME_FIELD = 40;
const DTI_RELATED_SHARED_KEYWORD = 22;
const DTI_RELATED_SHARED_KEYWORD_CAP = 2;
const DTI_RELATED_TITLE_WEIGHT = 10;
const DTI_RELATED_TOKEN_MATCH = 0.8;
const DTI_RELATED_MIN_SCORE = 50;
const DTI_MAX_RELATED = 3;

function dti_related_score(array $a, array $b) {
    $total = 0;
    if ($a['field'] !== '' && $a['field'] === $b['field']) {
        $total += DTI_RELATED_SAME_FIELD;
    }

    $mine = dti_related_keyword_grams($a);
    $matched = 0;
    foreach (dti_related_keyword_grams($b) as $grams) {
        foreach ($mine as $ours) {
            if (dti_related_overlap($grams, $ours) >= DTI_RELATED_TOKEN_MATCH) {
                $matched++;
                break;
            }
        }
    }
    $total += min($matched, DTI_RELATED_SHARED_KEYWORD_CAP) * DTI_RELATED_SHARED_KEYWORD;
    $total += (int)round(dti_related_dice(
        dti_related_bigrams($a['title']), dti_related_bigrams($b['title'])) * DTI_RELATED_TITLE_WEIGHT);

    return $total;
}

function dti_related_rebuild(PDO $pdo) {
    $topics = dti_topic_all($pdo);

    $pairs = [];
    foreach ($topics as $a) {
        foreach ($topics as $b) {
            if ($a['id'] === $b['id']) continue;
            $score = dti_related_score($a, $b);
            if ($score >= DTI_RELATED_MIN_SCORE) {
                $pairs[] = [(int)$a['id'], (int)$b['id'], $score];
            }
        }
    }
    dti_related_replace_all($pdo, $pairs);

    return count($pairs);
}

function dti_related_replace_all(PDO $pdo, array $pairs) {
    // 한 트랜잭션으로 넣는다 — 커밋마다 fsync 가 도는 환경에서 건별 커밋은 너무 느리다
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();

    $pdo->exec("DELETE FROM dti_related");
    $insert = $pdo->prepare("INSERT INTO dti_related (topic_id, related_id, score) VALUES (?, ?, ?)");
    foreach ($pairs as [$topicId, $relatedId, $score]) {
        $insert->execute([$topicId, $relatedId, $score]);
    }

    if ($own) $pdo->commit();
}

/** 숨김·보관은 빼고 점수 높은 순으로. 화면 드로어가 쓰는 모양 그대로 돌려준다. */
function dti_related_top_for(PDO $pdo, $topicId, $limit) {
    $limit = (int)$limit;
    $sql = "SELECT t.id, t.title, t.field, t.magazine, t.volume, t.page, r.score
            FROM dti_related r
            JOIN dti_topics t ON t.id = r.related_id
            WHERE r.topic_id = ? AND t.active = 1 AND t.archived = 0
            ORDER BY r.score DESC, t.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$topicId]);

    return array_map(static fn (array $row) => [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'field' => $row['field'],
        'magazine' => $row['magazine'],
        'volume' => $row['volume'],
        'page' => $row['page'],
        'score' => (int)$row['score'],
    ], $stmt->fetchAll());
}

/** 키워드 토큰별 bigram 집합 */
function dti_related_keyword_grams(array $topic) {
    $out = [];
    foreach (preg_split('~[\s,/·]+~u', mb_strtolower($topic['keywords'])) as $token) {
        if ($token === '') continue;
        $grams = dti_related_bigrams($token);
        if ($grams) $out[] = $grams;
    }
    return $out;
}

/** bigram 집합. 키가 곧 원소다 */
function dti_related_bigrams($text) {
    $normalized = preg_replace('~[^0-9a-z가-힣]~u', '', mb_strtolower($text));
    // mb_str_split 이어야 한다 — substr 은 UTF-8 한글을 바이트로 잘라 점수가 달라진다
    $chars = mb_str_split((string)$normalized);

    $out = [];
    for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
        $out[$chars[$i] . $chars[$i + 1]] = true;
    }
    return $out;
}

function dti_related_dice(array $a, array $b) {
    if (!$a || !$b) return 0.0;
    return 2 * count(array_intersect_key($a, $b)) / (count($a) + count($b));
}

function dti_related_overlap(array $a, array $b) {
    if (!$a || !$b) return 0.0;
    return count(array_intersect_key($a, $b)) / min(count($a), count($b));
}

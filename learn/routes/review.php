<?php
/** 강의평가·추천도·한 줄 후기. 별점은 0.5 단위, NULL 이 "미입력"이다. */

const SCORE_STEP = 0.5;
const SCORE_MIN  = 0.5;
const SCORE_MAX  = 5.0;

function learn_want_score($v) {
    if ($v === null) return null;
    if (!is_numeric($v)) throw new LearnError('별점은 숫자여야 합니다', 422);
    $v = (float)$v;
    if ($v < SCORE_MIN || $v > SCORE_MAX) {
        throw new LearnError('별점은 0.5 에서 5.0 사이여야 합니다', 422);
    }
    // 0 은 "미입력"이지 0점이 아니다 — 0.5 단위가 아니면 화면에서 그릴 수 없다
    if (abs(round($v / SCORE_STEP) * SCORE_STEP - $v) > 1e-9) {
        throw new LearnError('별점은 0.5 단위로만 매길 수 있습니다', 422);
    }
    return $v;
}

function learn_route_review(PDO $pdo, array $identity, $rid, $method) {
    if ($method !== 'POST') throw new LearnError('없는 API 입니다', 404);

    $req = learn_fetch_request($pdo, $rid);
    learn_require_applicant($req, $identity, '평가할');
    learn_ensure_transition($req, ACT_REVIEW);

    $body  = body_json();
    $patch = [];
    foreach (['rating', 'recommend'] as $k) {
        if (array_key_exists($k, $body)) $patch[$k] = learn_want_score($body[$k]);
    }
    if (array_key_exists('review_note', $body)) {
        $note = (string)$body['review_note'];
        if (mb_strlen($note) > 2000) throw new LearnError('후기는 2000자까지 쓸 수 있습니다', 422);
        $patch['review_note'] = $note;
    }

    learn_update_request($pdo, $rid, $patch);
    jsend(learn_request_out(learn_fetch_request($pdo, $rid),
                            learn_cert_names_map($pdo, [$rid])[$rid] ?? []));
}

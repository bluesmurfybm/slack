<?php
/** 발표 반응(좋아요·적용해보고싶다·쉽다·새롭다). */

function dti_emotion_empty_counts() {
    return array_fill_keys(DTI_EMOTIONS, 0);
}

/**
 * 목록용 집계. 화면은 아티클 단위로 그리므로 발표가 아니라 아티클 id 로 묶어 돌려준다.
 * [아티클별 종류별 개수, 내가 누른 종류] 두 벌을 준다.
 */
function dti_emotion_summary(PDO $pdo, $me) {
    $sql = "SELECT p.topic_id, e.kind, e.email
            FROM dti_emotions e
            JOIN dti_presentations p ON p.id = e.presentation_id";

    $counts = [];
    $mine = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $topicId = (int)$row['topic_id'];
        $counts[$topicId] ??= dti_emotion_empty_counts();
        $counts[$topicId][$row['kind']]++;
        if ($row['email'] === $me) {
            $mine[$topicId][] = $row['kind'];
        }
    }
    return [$counts, $mine];
}

function dti_emotion_count_for(PDO $pdo, $presentationId, $kind) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dti_emotions WHERE presentation_id = ? AND kind = ?");
    $stmt->execute([$presentationId, $kind]);
    return (int)$stmt->fetchColumn();
}

/** 있으면 지우고 없으면 남긴다. 남겼으면 true. */
function dti_emotion_toggle(PDO $pdo, $presentationId, $email, $kind, $now) {
    $stmt = $pdo->prepare("DELETE FROM dti_emotions WHERE presentation_id = ? AND email = ? AND kind = ?");
    $stmt->execute([$presentationId, $email, $kind]);
    if ($stmt->rowCount() > 0) return false;

    $pdo->prepare("INSERT INTO dti_emotions (presentation_id, email, kind, created_at) VALUES (?, ?, ?, ?)")
        ->execute([$presentationId, $email, $kind, $now]);
    return true;
}

function dti_emotion_delete_by_presentation(PDO $pdo, $presentationId) {
    $pdo->prepare("DELETE FROM dti_emotions WHERE presentation_id = ?")->execute([$presentationId]);
}

/** 사람·날짜별 반응 수 */
function dti_emotion_daily_counts(PDO $pdo) {
    $sql = "SELECT email, LEFT(created_at, 10) AS day, COUNT(*) AS n
            FROM dti_emotions GROUP BY email, day";

    return array_map(static fn (array $row) => [
        'email' => $row['email'],
        'date' => $row['day'],
        'count' => (int)$row['n'],
    ], $pdo->query($sql)->fetchAll());
}

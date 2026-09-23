<?php
/**
 * 동기화/AI 상태(경량). 화면 자동 새로고침 판단용 (2초 폴링).
 *  - changed_at    : 마지막으로 요청 데이터가 "변경"된 시각 (이 값이 늘면 목록 갱신)
 *  - synced_at     : 마지막 리스트 전체(증분) 동기화 완료시각
 *  - ingest_at     : 마지막 Slack 이벤트 단건 반영 시각
 *  - running       : 리스트 동기화 진행 중(MySQL GET_LOCK 'slackai_sync' 보유)
 *  - ai_changed_at : AI 결과(triage/plan/…)가 바뀐 시각 (패널 갱신)
 *  - ai_worker_at  : 워커 heartbeat, ai_push_connected : Slack Socket Mode 연결 여부, ai_active : 열린 잡 수
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();   // 세션 잠금 즉시 해제(폴링 경합 방지)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();
$running = ((int)$pdo->query("SELECT IS_USED_LOCK('slackai_sync') IS NOT NULL")->fetchColumn() === 1);
$active  = (int)$pdo->query("SELECT COUNT(*) FROM ai_jobs WHERE status IN ('queued','running')")->fetchColumn();

echo json_encode([
    'changed_at'         => (int)meta_get('data_changed_at', 0),
    'synced_at'          => (int)meta_get('last_synced_at', 0),
    'ingest_at'          => (int)meta_get('ai_last_ingest_at', 0),
    'running'            => $running,
    'ai_changed_at'      => (int)meta_get('ai_changed_at', 0),
    'ai_worker_at'       => (int)meta_get('ai_worker_heartbeat', 0),
    'ai_push_connected'  => meta_get('ai_push_connected', '0') === '1',
    'ai_push_at'         => (int)meta_get('ai_push_at', 0),
    'ai_active'          => $active,
], JSON_UNESCAPED_UNICODE);

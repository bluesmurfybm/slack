<?php
/**
 * JSON API 공통 — 응답·입력 파싱·오류.
 *
 * 오류 본문은 FastAPI 와 같은 {"detail": "..."} 형태를 유지한다. 화면(static/core.js)이
 * 그 키를 읽어 토스트를 띄우므로 모양을 바꾸면 오류 메시지가 통째로 사라진다.
 */

class LearnError extends Exception {
    public function __construct($message, $code = 400) {
        parent::__construct($message, $code);
    }
}

function jsend($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jfail($message, $status = 400) {
    jsend(['detail' => $message], $status);
}

/** 요청 본문(JSON). 보내지 않았으면 빈 배열. */
function body_json() {
    static $body = null;
    if ($body !== null) return $body;
    $raw  = file_get_contents('php://input');
    $body = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) throw new LearnError('본문을 읽을 수 없습니다', 422);
    return $body;
}

function now_stamp() {
    return date('Y-m-d H:i:s');
}

/* ---------- 입력 검증 ---------- */

function want_str($body, $key, $label, $required = false) {
    $v = trim((string)($body[$key] ?? ''));
    if ($required && $v === '') throw new LearnError("{$label}을(를) 입력해 주세요", 422);
    return $v;
}

function want_int($body, $key, $label) {
    $v = $body[$key] ?? 0;
    if (!is_numeric($v) || (int)$v < 0) throw new LearnError("{$label}은(는) 0 이상의 숫자여야 합니다", 422);
    return (int)$v;
}

function want_one_of($value, array $allowed, $label, $blank_ok = false) {
    $v = trim((string)$value);
    if ($v === '' && $blank_ok) return '';
    if (!in_array($v, $allowed, true)) {
        throw new LearnError("{$label}은(는) " . implode(', ', $allowed) . ' 중 하나여야 합니다', 422);
    }
    return $v;
}

function want_date($value, $label) {
    $v = trim((string)$value);
    if ($v === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        throw new LearnError("{$label}은(는) YYYY-MM-DD 형식이어야 합니다", 422);
    }
    return $v;
}

function want_url($value, $label) {
    $v = trim((string)$value);
    if ($v !== '' && !preg_match('~^https?://~i', $v)) {
        throw new LearnError("{$label}은(는) http(s) 로 시작해야 합니다", 422);
    }
    return $v;
}

/** 화면이 보낸 true/false/1/0 을 DB 의 TINYINT 로 */
function to_flag($v) {
    return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 1 : 0;
}

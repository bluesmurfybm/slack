<?php
/**
 * JSON API 공통 — 응답·입력 파싱·오류.
 *
 * 오류 본문은 {"error": "..."} 다. 파이썬 버전 화면이 그 키를 읽어 토스트를 띄웠고,
 * 옮겨 온 화면(static/travel.js)도 같은 키를 본다.
 */

class BlError extends Exception {
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
    jsend(['error' => $message], $status);
}

/** 요청 본문(JSON). 보내지 않았으면 빈 배열. */
function body_json() {
    static $body = null;
    if ($body !== null) return $body;
    $raw  = file_get_contents('php://input');
    $body = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) throw new BlError('요청 형식이 올바르지 않습니다', 400);
    return $body;
}

function now_stamp() {
    return date('Y-m-d H:i:s');
}

/** '12,000' 처럼 쉼표가 섞여 와도 숫자로. 음수는 0. */
function to_won($v) {
    $n = (float)preg_replace('/[^\d.\-]/', '', (string)$v);
    return $n > 0 ? (int)round($n) : 0;
}

function to_date_str($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new BlError('날짜는 YYYY-MM-DD 형식이어야 합니다', 400);
    return $v;
}

function clip($v, $len) {
    return mb_substr(trim((string)$v), 0, $len);
}

<?php

namespace Dti\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly array $segments,
        public readonly array $body = [],
        public readonly array $query = [],
        public readonly array $files = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // HEAD 는 GET 과 같은 라우트로 보낸다 — 본문은 SAPI 가 알아서 버린다
        if ($method === 'HEAD') $method = 'GET';

        $path = trim((string)($_GET['p'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        $raw = file_get_contents('php://input');
        $body = [];
        if ($raw !== '' && $raw !== false && !str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
            $body = json_decode($raw, true);
            if (!is_array($body)) throw new ApiException('본문을 읽을 수 없습니다', 422);
        }

        return new self($method, $segments, $body, $_GET, $_FILES);
    }

    public function segment(int $index): ?string
    {
        return $this->segments[$index] ?? null;
    }
}

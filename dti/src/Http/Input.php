<?php

namespace Dti\Http;

/**
 * 요청 본문 검증. 오류 문구는 파이썬 것을 그대로 쓴다 — 화면이 토스트로 띄운다.
 */
final class Input
{
    public static function str(array $body, string $key, string $label, bool $required = false): string
    {
        $value = trim((string)($body[$key] ?? ''));
        if ($required && $value === '') {
            throw new ApiException("{$label}을(를) 입력해 주세요", 422);
        }
        return $value;
    }

    public static function nullableInt(array $body, string $key, string $label): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') return null;
        if (!is_numeric($value)) {
            throw new ApiException("{$label}은(는) 숫자여야 합니다", 422);
        }
        return (int)$value;
    }

    public static function date(mixed $value, string $label): string
    {
        $date = trim((string)$value);
        if ($date === '') return '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiException("{$label}은(는) YYYY-MM-DD 형식이어야 합니다", 422);
        }
        return $date;
    }

    public static function url(mixed $value, string $label): string
    {
        $url = trim((string)$value);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            throw new ApiException('http(s) 로 시작하는 주소만 넣을 수 있습니다', 422);
        }
        return $url;
    }

    public static function oneOf(mixed $value, array $allowed, string $label, bool $blankOk = true): string
    {
        $picked = trim((string)$value);
        if ($picked === '' && $blankOk) return '';
        if (!in_array($picked, $allowed, true)) {
            throw new ApiException("없는 {$label}입니다: {$picked}", 422);
        }
        return $picked;
    }

    public static function flag(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 1 : 0;
    }
}

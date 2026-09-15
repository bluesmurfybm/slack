<?php

namespace Dti\Http;

/** 파이썬 HTTPException(status_code, detail) 자리. 본문은 {"detail": "..."} 로 나간다. */
final class ApiException extends \RuntimeException
{
    public function __construct(string $detail, private readonly int $status = 400)
    {
        parent::__construct($detail, $status);
    }

    public function status(): int
    {
        return $this->status;
    }
}

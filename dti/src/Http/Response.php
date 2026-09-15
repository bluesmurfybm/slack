<?php

namespace Dti\Http;

use Dti\Service\Storage;

final class Response
{
    private function __construct(
        public readonly int $status,
        public readonly mixed $data = null,
        public readonly ?string $filePath = null,
        public readonly ?string $fileName = null,
    ) {}

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, $data);
    }

    public static function file(string $path, string $name): self
    {
        return new self(200, null, $path, $name);
    }

    /** 출력하고 끝내는 유일한 자리. 컨트롤러는 값을 돌려주기만 한다. */
    public function send(): void
    {
        http_response_code($this->status);

        if ($this->filePath !== null) {
            [$type, $disposition] = Storage::disposition($this->fileName ?? basename($this->filePath));
            header("Content-Type: {$type}");
            header("Content-Disposition: {$disposition}");
            header('Content-Length: ' . filesize($this->filePath));
            readfile($this->filePath);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

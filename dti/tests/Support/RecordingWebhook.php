<?php

namespace Dti\Tests\Support;

final class RecordingWebhook
{
    /** @var array<int, array{url: string, payload: array}> */
    public array $sent = [];

    public function __invoke(string $url, array $payload): void
    {
        $this->sent[] = ['url' => $url, 'payload' => $payload];
    }
}

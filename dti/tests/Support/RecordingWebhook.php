<?php

namespace Dti\Tests\Support;

use Dti\Service\Webhook;

final class RecordingWebhook implements Webhook
{
    /** @var array<int, array{url: string, payload: array}> */
    public array $sent = [];

    public function post(string $url, array $payload): void
    {
        $this->sent[] = ['url' => $url, 'payload' => $payload];
    }
}

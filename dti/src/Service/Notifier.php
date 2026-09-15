<?php

namespace Dti\Service;

use Dti\Config;
use Dti\Entity\Presentation;
use Dti\Entity\Topic;

final class Notifier
{
    private Webhook $webhook;

    public function __construct(private readonly Config $config, ?Webhook $webhook = null)
    {
        $this->webhook = $webhook ?? new CurlWebhook();
    }

    /** 선점·배정으로 발표자가 정해졌을 때만 보낸다 */
    public function newPresenter(Topic $topic, Presentation $pres): void
    {
        if (!$this->config->slackWebhook) return;

        $text = implode("\n", [
            ':studio_microphone: *DTI 발표자 등록*',
            "• 아티클: {$topic->title}",
            "• 발표자: {$pres->presenter}",
            '• 예정일: ' . ($pres->planned_date !== '' ? $pres->planned_date : '미정'),
        ]);

        $this->webhook->post($this->config->slackWebhook, ['text' => $text]);
    }
}

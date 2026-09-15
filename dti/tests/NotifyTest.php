<?php

namespace Dti\Tests;

use Dti\Service\Notifier;
use Dti\Tests\Support\TestCase;

final class NotifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 웹훅 주소가 있어야 보낸다. 기본 Config 는 주소가 없어 아무 것도 보내지 않는다
        $this->config = $this->makeConfig(['slackWebhook' => 'https://hooks.slack.example/x']);
        $this->notifier = new Notifier($this->config, $this->webhook);
    }

    private function newTopic(): int
    {
        return $this->post(['topics'], $this->admin(), ['title' => '새 주제'])->data['id'];
    }

    private function texts(): array
    {
        return array_map(static fn (array $call) => $call['payload']['text'], $this->webhook->sent);
    }

    public function test_등록만으로는_보내지_않는다(): void
    {
        $this->newTopic();
        $this->assertSame([], $this->webhook->sent);
    }

    public function test_선점하면_한_번_보낸다(): void
    {
        $tid = $this->newTopic();
        $res = $this->post(['topics', (string)$tid, 'claim'], $this->user(), ['planned_date' => '2026-09-01']);

        $this->assertSame(200, $res->status);
        $this->assertCount(1, $this->webhook->sent);

        $text = $this->texts()[0];
        $this->assertStringContainsString('유승인', $text);
        $this->assertStringContainsString('새 주제', $text);
        $this->assertStringContainsString('2026-09-01', $text);
        $this->assertSame('https://hooks.slack.example/x', $this->webhook->sent[0]['url']);
    }

    public function test_예정일이_없으면_미정으로_적는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->assertStringContainsString('미정', $this->texts()[0]);
    }

    public function test_배정도_한_번_보낸다(): void
    {
        $tid = $this->newTopic();
        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'siyu@bluesoft.co.kr']);

        $this->assertSame(200, $res->status);
        $this->assertCount(1, $this->webhook->sent);
        $this->assertStringContainsString('유승인', $this->texts()[0]);
    }

    public function test_배정_해제는_보내지_않는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'siyu@bluesoft.co.kr']);
        $this->webhook->sent = [];

        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => '']);
        $this->assertSame(200, $res->status);
        $this->assertSame([], $this->webhook->sent);
    }

    public function test_실패한_선점은_보내지_않는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->webhook->sent = [];

        $res = $this->post(['topics', (string)$tid, 'claim'], $this->other());
        $this->assertSame(409, $res->status);
        $this->assertSame([], $this->webhook->sent);
    }

    public function test_예약_취소는_보내지_않는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->webhook->sent = [];

        $this->post(['topics', (string)$tid, 'release'], $this->user());
        $this->assertSame([], $this->webhook->sent);
    }

    public function test_웹훅_주소가_없으면_아무_것도_보내지_않는다(): void
    {
        $this->config = $this->makeConfig(['slackWebhook' => null]);
        $this->notifier = new Notifier($this->config, $this->webhook);

        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->assertSame([], $this->webhook->sent);
    }
}

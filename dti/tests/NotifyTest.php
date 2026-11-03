<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class NotifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 웹훅 주소가 있어야 보낸다. 기본 Config 는 주소가 없어 아무 것도 보내지 않는다
        $this->config = $this->makeConfig(['slackWebhook' => 'https://hooks.slack.example/x']);
    }

    private function newTopic(): int
    {
        return $this->post(['topics'], $this->admin(), ['title' => '새 주제'])['data']['id'];
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

        $this->assertSame(200, $res['status']);
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

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $this->webhook->sent);
        $this->assertStringContainsString('유승인', $this->texts()[0]);
    }

    public function test_배정_해제는_보내지_않는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'siyu@bluesoft.co.kr']);
        $this->webhook->sent = [];

        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => '']);
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $this->webhook->sent);
    }

    public function test_실패한_선점은_보내지_않는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->webhook->sent = [];

        $res = $this->post(['topics', (string)$tid, 'claim'], $this->other());
        $this->assertSame(409, $res['status']);
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

        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->assertSame([], $this->webhook->sent);
    }
    // ---- 웹 푸시 (core/push.php) ----

    private function kinds(): array
    {
        return array_column($this->pushed, 'kind');
    }

    public function test_새_아티클은_올린_사람을_뺀_전원에게(): void
    {
        $tid = $this->newTopic();
        $this->assertSame(['dti.topic'], $this->kinds());
        $this->assertSame("dti/index.php?topic={$tid}", $this->pushed[0]['url']);
        $this->assertSame(['jian@bluesoft.co.kr'], $this->pushed[0]['except']);
        $this->assertStringContainsString('새 주제', $this->pushed[0]['title']);
    }

    public function test_숨긴_아티클은_알리지_않는다(): void
    {
        $this->post(['topics'], $this->admin(), ['title' => '숨김', 'active' => 0]);
        $this->assertSame([], $this->pushed);
    }

    public function test_선점하면_웹_푸시도_한_번_보낸다(): void
    {
        $tid = $this->newTopic();
        $this->pushed = [];
        $this->post(['topics', (string)$tid, 'claim'], $this->user(), ['planned_date' => '2026-09-01']);

        $this->assertCount(1, $this->pushed);
        $p = $this->pushed[0];
        $this->assertSame('dti.presenter', $p['kind']);
        $this->assertSame("dti/index.php?topic={$tid}", $p['url']);
        $this->assertContains('siyu@bluesoft.co.kr', $p['except']);
        $this->assertSame("dti-topic-{$tid}", $p['tag']);
        $this->assertStringContainsString('새 주제', $p['body']);
        $this->assertStringContainsString('유승인', $p['body']);
        $this->assertStringContainsString('2026-09-01', $p['body']);
    }

    public function test_배정은_전원과_발표자_본인에게_따로_보낸다(): void
    {
        $tid = $this->newTopic();
        $this->pushed = [];
        $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'siyu@bluesoft.co.kr']);
        $this->assertSame(['dti.presenter', 'dti.mine'], $this->kinds());
        $this->assertSame(['jian@bluesoft.co.kr', 'siyu@bluesoft.co.kr'], $this->pushed[0]['except']);
        $this->assertSame(['siyu@bluesoft.co.kr'], $this->pushed[1]['to']);
    }

    public function test_다른_사람으로_바꾸면_먼저_발표자에게도_알린다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->pushed = [];
        $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'hjlee@bluesoft.co.kr']);
        $mine = array_values(array_filter($this->pushed, static fn ($p) => $p['kind'] === 'dti.mine'));
        $this->assertSame([['hjlee@bluesoft.co.kr'], ['siyu@bluesoft.co.kr']], array_column($mine, 'to'));
    }

    public function test_관리자가_내_발표를_바꾸면_발표자에게(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->pushed = [];

        $this->post(['topics', (string)$tid, 'schedule'], $this->admin(), ['planned_date' => '2026-10-01']);
        $this->post(['topics', (string)$tid, 'complete'], $this->admin());
        $this->assertSame(['dti.mine', 'dti.mine'], $this->kinds());
        $this->assertSame(['siyu@bluesoft.co.kr'], $this->pushed[1]['to']);
        $this->assertStringNotContainsString('2026-10-01', $this->pushed[0]['body']);
    }

    public function test_내가_바꾼_내_발표는_알리지_않는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->pushed = [];

        $this->post(['topics', (string)$tid, 'schedule'], $this->user(), ['planned_date' => '2026-10-01']);
        $this->post(['topics', (string)$tid, 'release'], $this->user());
        $this->assertSame([], $this->pushed);
    }

    public function test_자료를_올리면_전원에게(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->pushed = [];

        $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(), ['url' => 'https://example.com/a']);
        $this->assertSame(['dti.topic'], $this->kinds());
        $this->assertSame(['siyu@bluesoft.co.kr'], $this->pushed[0]['except']);
    }

    public function test_회차를_발행하면_전원에게_한_번(): void
    {
        $this->makeTopic(['magazine' => 'DI', 'volume' => '286']);
        $this->makeTopic(['magazine' => 'DI', 'volume' => '286', 'title' => '둘째']);
        $rid = $this->post(['rounds'], $this->admin(), ['no' => 13, 'title' => '10월'])['data']['id'];

        $this->post(['rounds', (string)$rid, 'issue'], $this->admin(),
            ['issues' => [['magazine' => 'DI', 'volume' => '286']]]);
        $this->assertSame(['dti.topic'], $this->kinds());
        $this->assertSame('13회차 10월 발행', $this->pushed[0]['title']);
        $this->assertStringContainsString('2건', $this->pushed[0]['body']);
    }

    public function test_상세를_열면_그_아티클_알림을_읽음으로(): void
    {
        $tid = $this->newTopic();
        $res = $this->post(['topics', (string)$tid, 'seen'], $this->user());
        $this->assertSame(200, $res['status']);
        $this->assertSame([['siyu@bluesoft.co.kr', "dti/index.php?topic={$tid}"]], $this->seen);
    }

    public function test_웹훅_주소가_없어도_웹_푸시는_보낸다(): void
    {
        $this->config = $this->makeConfig(['slackWebhook' => null]);
        $tid = $this->newTopic();
        $this->pushed = [];
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->assertCount(1, $this->pushed);
        $this->assertStringNotContainsString('(', $this->pushed[0]['body']);   // 예정일 없으면 괄호도 없다
    }

    public function test_실패한_선점과_취소는_웹_푸시를_보내지_않는다(): void
    {
        $tid = $this->newTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->pushed = [];

        $this->post(['topics', (string)$tid, 'claim'], $this->other());
        $this->post(['topics', (string)$tid, 'release'], $this->user());
        $this->assertSame([], $this->pushed);
    }
}

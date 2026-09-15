<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class PresentationTest extends TestCase
{
    private function openTopic(array $over = []): int
    {
        return $this->post(['topics'], $this->admin(), ['title' => '새 주제', ...$over])->data['id'];
    }

    private function claimedTopic(): int
    {
        $tid = $this->openTopic();
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        return $tid;
    }

    /* ---------- 선점 ---------- */

    public function test_미지정_아티클을_선점한다(): void
    {
        $tid = $this->openTopic();
        $res = $this->post(['topics', (string)$tid, 'claim'], $this->user(), ['planned_date' => '2026-09-01']);

        $this->assertSame(200, $res->status);
        $this->assertSame('발표예정', $res->data['status']);
        $this->assertSame('siyu@bluesoft.co.kr', $res->data['presenter_email']);
        $this->assertSame('유승인', $res->data['presenter']);
        $this->assertSame('2026-09-01', $res->data['planned_date']);
    }

    public function test_두_번째_선점은_409(): void
    {
        $tid = $this->claimedTopic();
        $this->assertSame(409, $this->post(['topics', (string)$tid, 'claim'], $this->other())->status);
    }

    public function test_없는_아티클_선점은_404(): void
    {
        $this->assertSame(404, $this->post(['topics', '99999', 'claim'], $this->user())->status);
    }

    public function test_발표가_끝난_아티클은_선점할_수_없다(): void
    {
        $tid = $this->makeTopic(['title' => '끝난 주제']);
        $this->makePresentation($tid, ['done_date' => '2026-03-01']);
        $this->assertSame(409, $this->post(['topics', (string)$tid, 'claim'], $this->user())->status);
    }

    public function test_숨김_아티클은_선점할_수_없다(): void
    {
        $tid = $this->openTopic(['active' => 0]);
        $this->assertSame(409, $this->post(['topics', (string)$tid, 'claim'], $this->user())->status);
    }

    public function test_보관_아티클은_선점할_수_없다(): void
    {
        $tid = $this->openTopic();
        $this->put(['topics', (string)$tid], $this->admin(), ['archived' => 1]);
        $this->assertSame(409, $this->post(['topics', (string)$tid, 'claim'], $this->user())->status);
    }

    public function test_발표자만_비어_있는_행도_선점한다(): void
    {
        // 자료만 먼저 올라가 발표 행이 생긴 경우 — 그 행을 차지해야 한다
        $tid = $this->makeTopic(['title' => '자료만 있는 주제']);
        $this->makePresentation($tid, ['planned_date' => '2026-09-01']);

        $res = $this->post(['topics', (string)$tid, 'claim'], $this->user());
        $this->assertSame(200, $res->status);
        $this->assertSame('siyu@bluesoft.co.kr', $res->data['presenter_email']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM dti_presentations")->fetchColumn());
    }

    /* ---------- 취소 ---------- */

    public function test_본인은_예약을_취소한다(): void
    {
        $tid = $this->claimedTopic();
        $res = $this->post(['topics', (string)$tid, 'release'], $this->user());

        $this->assertSame(200, $res->status);
        $this->assertSame('미지정', $res->data['status']);
        $this->assertSame('', $res->data['presenter_email']);
    }

    public function test_남의_예약은_취소하지_못한다(): void
    {
        $tid = $this->claimedTopic();
        $this->assertSame(403, $this->post(['topics', (string)$tid, 'release'], $this->other())->status);
    }

    public function test_관리자는_남의_예약도_취소한다(): void
    {
        $tid = $this->claimedTopic();
        $this->assertSame(200, $this->post(['topics', (string)$tid, 'release'], $this->admin())->status);
    }

    public function test_발표가_끝났으면_취소해도_기록은_남는다(): void
    {
        $tid = $this->claimedTopic();
        $this->post(['topics', (string)$tid, 'complete'], $this->admin(), ['done_date' => '2026-09-01']);
        $this->post(['topics', (string)$tid, 'release'], $this->admin());

        $row = $this->get(['topics', (string)$tid])->data;
        $this->assertSame('발표완료', $row['status']);
        $this->assertSame('', $row['presenter_email']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM dti_presentations")->fetchColumn());
    }

    /* ---------- 예정일 ---------- */

    public function test_선점_뒤에_예정일을_정한다(): void
    {
        $tid = $this->claimedTopic();
        $res = $this->post(['topics', (string)$tid, 'schedule'], $this->user(), ['planned_date' => '2026-10-15']);

        $this->assertSame(200, $res->status);
        $this->assertSame('2026-10-15', $res->data['planned_date']);
        $this->assertSame('발표예정', $res->data['status']);
    }

    public function test_예정일을_비울_수_있다(): void
    {
        $tid = $this->claimedTopic();
        $this->post(['topics', (string)$tid, 'schedule'], $this->user(), ['planned_date' => '2026-10-15']);
        $res = $this->post(['topics', (string)$tid, 'schedule'], $this->user(), ['planned_date' => '']);
        $this->assertSame('', $res->data['planned_date']);
    }

    public function test_남의_아티클_예정일은_못_바꾼다(): void
    {
        $tid = $this->claimedTopic();
        $res = $this->post(['topics', (string)$tid, 'schedule'], $this->other(), ['planned_date' => '2026-10-15']);
        $this->assertSame(403, $res->status);
    }

    public function test_예약이_없으면_예정일을_정할_수_없다(): void
    {
        $tid = $this->openTopic();
        $res = $this->post(['topics', (string)$tid, 'schedule'], $this->admin(), ['planned_date' => '2026-10-15']);
        $this->assertSame(409, $res->status);
    }

    public function test_끝난_발표는_예정일을_다시_잡지_못한다(): void
    {
        $tid = $this->claimedTopic();
        $this->post(['topics', (string)$tid, 'complete'], $this->admin(), ['done_date' => '2026-09-01']);
        $res = $this->post(['topics', (string)$tid, 'schedule'], $this->user(), ['planned_date' => '2026-10-15']);
        $this->assertSame(409, $res->status);
    }

    public function test_없는_아티클_예정일은_404(): void
    {
        $res = $this->post(['topics', '99999', 'schedule'], $this->user(), ['planned_date' => '2026-10-15']);
        $this->assertSame(404, $res->status);
    }

    /* ---------- 발표완료 ---------- */

    public function test_관리자가_발표완료로_바꾼다(): void
    {
        $tid = $this->claimedTopic();
        $res = $this->post(['topics', (string)$tid, 'complete'], $this->admin(), ['done_date' => '2026-09-01']);
        $this->assertSame(200, $res->status);
        $this->assertSame('발표완료', $res->data['status']);
        $this->assertSame('2026-09-01', $res->data['done_date']);
    }

    public function test_발표일을_안_주면_오늘로_잡는다(): void
    {
        $tid = $this->claimedTopic();
        $res = $this->post(['topics', (string)$tid, 'complete'], $this->admin());
        $this->assertSame(date('Y-m-d'), $res->data['done_date']);
    }

    public function test_일반_사용자는_발표완료로_못_바꾼다(): void
    {
        $tid = $this->claimedTopic();
        $this->assertSame(403, $this->post(['topics', (string)$tid, 'complete'], $this->user())->status);
    }

    /* ---------- 배정 ---------- */

    public function test_관리자가_발표자를_배정한다(): void
    {
        $tid = $this->openTopic();
        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(),
            ['email' => 'siyu@bluesoft.co.kr', 'planned_date' => '2026-11-03']);

        $this->assertSame(200, $res->status);
        $this->assertSame('siyu@bluesoft.co.kr', $res->data['presenter_email']);
        $this->assertSame('유승인', $res->data['presenter']);
        $this->assertSame('2026-11-03', $res->data['planned_date']);
        $this->assertSame('발표예정', $res->data['status']);
    }

    public function test_이미_예약된_아티클도_관리자는_덮어쓴다(): void
    {
        $tid = $this->claimedTopic();
        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'hjlee@bluesoft.co.kr']);
        $this->assertSame('hjlee@bluesoft.co.kr', $res->data['presenter_email']);
    }

    public function test_빈_이메일은_배정_해제다(): void
    {
        $tid = $this->openTopic();
        $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'siyu@bluesoft.co.kr']);
        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => '']);

        $this->assertSame(200, $res->status);
        $this->assertSame('미지정', $res->data['status']);
        $this->assertSame('', $res->data['presenter_email']);
    }

    public function test_명단에_없는_사람은_거부한다(): void
    {
        $tid = $this->openTopic();
        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'nobody@bluesoft.co.kr']);
        $this->assertSame(422, $res->status);
    }

    public function test_일반_사용자는_배정하지_못한다(): void
    {
        $tid = $this->openTopic();
        $res = $this->post(['topics', (string)$tid, 'assign'], $this->user(), ['email' => 'hjlee@bluesoft.co.kr']);
        $this->assertSame(403, $res->status);
    }

    public function test_예정일을_안_주면_기존_예정일을_유지한다(): void
    {
        $tid = $this->openTopic();
        $this->post(['topics', (string)$tid, 'assign'], $this->admin(),
            ['email' => 'siyu@bluesoft.co.kr', 'planned_date' => '2026-11-03']);
        $res = $this->post(['topics', (string)$tid, 'assign'], $this->admin(), ['email' => 'hjlee@bluesoft.co.kr']);

        $this->assertSame('2026-11-03', $res->data['planned_date']);
    }

    public function test_없는_아티클_배정은_404(): void
    {
        $res = $this->post(['topics', '99999', 'assign'], $this->admin(), ['email' => 'siyu@bluesoft.co.kr']);
        $this->assertSame(404, $res->status);
    }
}

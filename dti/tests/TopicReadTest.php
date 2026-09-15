<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class TopicReadTest extends TestCase
{
    public function test_목록은_로그인이_필요하다(): void
    {
        $this->assertSame(401, $this->call('GET', ['topics'], null)['status']);
    }

    public function test_상세는_없으면_404(): void
    {
        $res = $this->get(['topics', '99999']);
        $this->assertSame(404, $res['status']);
        $this->assertSame('없는 아티클입니다', $res['data']['detail']);
    }

    public function test_응답은_화면_계약을_지킨다(): void
    {
        $tid = $this->makeTopic(['title' => '주제', 'field' => 'AX']);
        $row = $this->get(['topics', (string)$tid])['data'];

        $this->assertSame($tid, $row['id']);
        $this->assertSame('주제', $row['title']);
        $this->assertSame('미지정', $row['status']);
        $this->assertSame(['like' => 0, 'apply' => 0, 'easy' => 0, 'new' => 0], $row['emotions']);
        $this->assertSame([], $row['my_emotions']);
        // 자료 없음은 빈 문자열이 아니라 null 이다 — 화면이 이 구분에 기댄다
        $this->assertNull($row['material_kind']);
        $this->assertNull($row['material_name']);
        $this->assertNull($row['scan_url']);
        $this->assertNull($row['year']);
        $this->assertSame(1, $row['active']);
        $this->assertSame(0, $row['archived']);
        // 발표가 없으면 발표 관련 문자열은 빈 문자열이다
        $this->assertSame('', $row['presenter']);
        $this->assertSame('', $row['presenter_email']);
        $this->assertSame('', $row['planned_date']);
        $this->assertSame('', $row['done_date']);
    }

    public function test_상태는_저장하지_않고_파생한다(): void
    {
        $open = $this->makeTopic(['title' => '미지정']);

        $planned = $this->makeTopic(['title' => '예정']);
        $this->makePresentation($planned, ['presenter_email' => 'siyu@bluesoft.co.kr', 'presenter' => '유승인']);

        $done = $this->makeTopic(['title' => '완료']);
        $this->makePresentation($done, ['presenter_email' => 'siyu@bluesoft.co.kr', 'done_date' => '2026-03-01']);

        $this->assertSame('미지정', $this->get(['topics', (string)$open])['data']['status']);
        $this->assertSame('발표예정', $this->get(['topics', (string)$planned])['data']['status']);
        $this->assertSame('발표완료', $this->get(['topics', (string)$done])['data']['status']);
    }

    public function test_발표_행의_값이_아티클_값을_덮는다(): void
    {
        // 발표 분리 뒤에도 topics 에 옛 컬럼이 남아 있다. 화면은 발표 행의 값을 봐야 한다
        $tid = $this->makeTopic(['title' => '주제', 'presenter' => '옛발표자', 'planned_date' => '2020-01-01']);
        $this->makePresentation($tid, ['presenter' => '유승인', 'presenter_email' => 'siyu@bluesoft.co.kr',
                                       'planned_date' => '2026-09-01']);

        $row = $this->get(['topics', (string)$tid])['data'];
        $this->assertSame('유승인', $row['presenter']);
        $this->assertSame('2026-09-01', $row['planned_date']);
    }

    public function test_비관리자_목록에서는_숨김과_보관이_빠진다(): void
    {
        $this->makeTopic(['title' => '보임']);
        $this->makeTopic(['title' => '숨김', 'active' => 0]);
        $this->makeTopic(['title' => '보관', 'archived' => 1]);

        $mine = array_column($this->get(['topics'], $this->user())['data'], 'title');
        $this->assertSame(['보임'], $mine);
        $this->assertCount(3, $this->get(['topics'], $this->admin())['data']);
    }

    public function test_목록은_날짜_없는_것부터_그다음_최신순(): void
    {
        $a = $this->makeTopic(['title' => '날짜없음1']);
        $b = $this->makeTopic(['title' => '날짜없음2']);
        $c = $this->makeTopic(['title' => '옛날']);
        $this->makePresentation($c, ['planned_date' => '2026-01-01']);
        $d = $this->makeTopic(['title' => '최근']);
        $this->makePresentation($d, ['done_date' => '2026-06-01']);

        $titles = array_column($this->get(['topics'])['data'], 'title');
        $this->assertSame(['날짜없음2', '날짜없음1', '최근', '옛날'], $titles);
    }

    public function test_발표일이_예정일보다_우선한다(): void
    {
        $tid = $this->makeTopic(['title' => '주제']);
        $this->makePresentation($tid, ['planned_date' => '2026-01-01', 'done_date' => '2026-05-05']);

        $row = $this->get(['topics'])['data'][0];
        $this->assertSame('2026-05-05', $row['done_date']);
        $this->assertSame('발표완료', $row['status']);
    }
}

<?php

namespace Dti\Tests;

use Dti\Http\Response;
use Dti\Tests\Support\TestCase;

final class EmotionTest extends TestCase
{
    private function doneTopic(): int
    {
        $tid = $this->post(['topics'], $this->admin(), ['title' => '반응 달 주제'])->data['id'];
        $this->post(['topics', (string)$tid, 'complete'], $this->admin());
        return $tid;
    }

    private function react(int $tid, string $kind = 'like', ?array $who = null): Response
    {
        return $this->post(['topics', (string)$tid, 'emotions', $kind], $who ?? $this->user());
    }

    private function row(int $tid): array
    {
        foreach ($this->get(['topics'])->data as $row) {
            if ($row['id'] === $tid) return $row;
        }
        $this->fail("목록에 {$tid} 가 없다");
    }

    public function test_반응은_토글이다(): void
    {
        $tid = $this->doneTopic();
        $this->assertSame(['kind' => 'like', 'count' => 1, 'mine' => true], $this->react($tid)->data);
        $this->assertSame(['kind' => 'like', 'count' => 0, 'mine' => false], $this->react($tid)->data);
    }

    public function test_한_사람은_한_번만_센다(): void
    {
        $tid = $this->doneTopic();
        $this->react($tid);
        $this->react($tid);
        $this->react($tid);
        $this->assertSame(2, $this->react($tid, 'like', $this->other())->data['count']);
    }

    public function test_종류별로_따로_센다(): void
    {
        $tid = $this->doneTopic();
        $this->react($tid, 'like');
        $this->react($tid, 'apply');
        $this->react($tid, 'new');

        $row = $this->row($tid);
        $this->assertSame(['like' => 1, 'apply' => 1, 'easy' => 0, 'new' => 1], $row['emotions']);
        $mine = $row['my_emotions'];
        sort($mine);
        $this->assertSame(['apply', 'like', 'new'], $mine);
    }

    public function test_하나를_꺼도_나머지는_남는다(): void
    {
        $tid = $this->doneTopic();
        $this->react($tid, 'like');
        $this->react($tid, 'apply');
        $this->react($tid, 'like');

        $row = $this->row($tid);
        $this->assertSame(0, $row['emotions']['like']);
        $this->assertSame(1, $row['emotions']['apply']);
    }

    public function test_남의_반응은_내_반응이_아니다(): void
    {
        $tid = $this->doneTopic();
        $this->react($tid);

        $rows = array_column($this->get(['topics'], $this->other())->data, null, 'id');
        $this->assertSame(1, $rows[$tid]['emotions']['like']);
        $this->assertSame([], $rows[$tid]['my_emotions']);
    }

    public function test_아무도_안_누른_아티클은_0이다(): void
    {
        $this->makeTopic(['title' => '조용한 주제']);
        $row = $this->get(['topics'])->data[0];
        $this->assertSame(['like' => 0, 'apply' => 0, 'easy' => 0, 'new' => 0], $row['emotions']);
        $this->assertSame([], $row['my_emotions']);
    }

    public function test_발표_전에는_반응을_남길_수_없다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), ['title' => '아직 안 한 주제'])->data['id'];
        $res = $this->react($tid);
        $this->assertSame(409, $res->status);
        $this->assertSame('발표가 끝난 아티클에만 반응을 남길 수 있습니다', $res->data['detail']);
    }

    public function test_모르는_종류는_422(): void
    {
        $this->assertSame(422, $this->react($this->doneTopic(), 'hate')->status);
    }

    public function test_없는_아티클_반응은_404(): void
    {
        $this->assertSame(404, $this->react(99999)->status);
    }

    public function test_미로그인은_반응하지_못한다(): void
    {
        $tid = $this->doneTopic();
        $this->assertSame(401, $this->call('POST', ['topics', (string)$tid, 'emotions', 'like'], null)->status);
    }

    public function test_아티클을_지우면_반응도_사라진다(): void
    {
        $tid = $this->doneTopic();
        $this->react($tid, 'like');
        $this->react($tid, 'easy');
        $this->delete(['topics', (string)$tid], $this->admin());

        $left = (int)$this->pdo->query("SELECT COUNT(*) FROM dti_emotions")->fetchColumn();
        $this->assertSame(0, $left);
    }
}

<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class RelatedTest extends TestCase
{
    private function newTopic(array $over = []): int
    {
        return $this->post(['topics'], $this->admin(), ['title' => '베이스', ...$over])->data['id'];
    }

    private function related(int $tid): array
    {
        return $this->get(['topics', (string)$tid, 'related'])->data;
    }

    private function hasRelated(int $tid, int $other): bool
    {
        return in_array($other, array_column($this->related($tid), 'id'), true);
    }

    public function test_연관은_로그인이_필요하다(): void
    {
        $this->assertSame(401, $this->call('GET', ['topics', '1', 'related'], null)->status);
    }

    public function test_없는_아티클은_404(): void
    {
        $this->assertSame(404, $this->get(['topics', '99999', 'related'])->status);
    }

    public function test_분야와_키워드가_같으면_연관이다(): void
    {
        $a = $this->newTopic(['title' => '에이전트 하나', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $b = $this->newTopic(['title' => '오브젝트 저장', 'field' => 'AX', 'keywords' => '쌍둥이시험']);

        $rows = array_column($this->related($a), null, 'id');
        $this->assertArrayHasKey($b, $rows);
        $this->assertGreaterThanOrEqual(62, $rows[$b]['score']);
    }

    public function test_띄어쓰기가_달라도_같은_키워드로_본다(): void
    {
        $a = $this->newTopic(['title' => '하나', 'field' => 'AX', 'keywords' => '생성형AI']);
        $b = $this->newTopic(['title' => '둘', 'field' => 'AX', 'keywords' => '생성형 AI']);
        $this->assertTrue($this->hasRelated($a, $b));
    }

    public function test_분야만_같은_건_임계값에_못_미친다(): void
    {
        $a = $this->newTopic(['title' => '사과 재배법', 'field' => 'AX']);
        $b = $this->newTopic(['title' => '바다 건너기', 'field' => 'AX']);
        $this->assertFalse($this->hasRelated($a, $b));
    }

    public function test_같은_팀은_점수에_넣지_않는다(): void
    {
        $a = $this->newTopic(['title' => '사과 재배법', 'field' => 'AX', 'team' => 'APP']);
        $b = $this->newTopic(['title' => '바다 건너기', 'field' => 'AX', 'team' => 'APP']);
        $this->assertFalse($this->hasRelated($a, $b));
    }

    public function test_같은_매거진도_점수에_넣지_않는다(): void
    {
        $a = $this->newTopic(['title' => '사과 재배법', 'field' => 'AX', 'magazine' => 'DI']);
        $b = $this->newTopic(['title' => '바다 건너기', 'field' => 'AX', 'magazine' => 'DI']);
        $this->assertFalse($this->hasRelated($a, $b));
    }

    public function test_제목이_거의_같으면_임계값을_넘는다(): void
    {
        $a = $this->newTopic(['title' => 'AI 코딩의 미래', 'field' => 'AX']);
        $b = $this->newTopic(['title' => 'AI 코딩의 미래!', 'field' => 'AX']);
        $this->assertTrue($this->hasRelated($a, $b));
    }

    public function test_수정하면_점수를_다시_계산한다(): void
    {
        $a = $this->newTopic(['title' => '하나', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $b = $this->newTopic(['title' => '둘', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $this->put(['topics', (string)$b], $this->admin(), ['field' => 'Trend', 'keywords' => '전혀다른것']);
        $this->assertFalse($this->hasRelated($a, $b));
    }

    public function test_삭제하면_점수를_다시_계산한다(): void
    {
        $a = $this->newTopic(['title' => '하나', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $b = $this->newTopic(['title' => '둘', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $this->delete(['topics', (string)$b], $this->admin());
        $this->assertFalse($this->hasRelated($a, $b));
    }

    public function test_숨긴_아티클은_빠진다(): void
    {
        $a = $this->newTopic(['title' => '하나', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $b = $this->newTopic(['title' => '둘', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $this->put(['topics', (string)$b], $this->admin(), ['active' => 0]);
        $this->assertFalse($this->hasRelated($a, $b));
    }

    public function test_최대_세_건까지만_준다(): void
    {
        $a = $this->newTopic(['title' => '기준', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        for ($i = 0; $i < 4; $i++) {
            $this->newTopic(['title' => "복제 {$i}", 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        }
        $this->assertCount(3, $this->related($a));
    }

    public function test_일반_사용자도_읽는다(): void
    {
        $a = $this->newTopic(['title' => '하나', 'field' => 'AX', 'keywords' => '쌍둥이시험']);
        $this->assertSame(200, $this->get(['topics', (string)$a, 'related'], $this->user())->status);
    }
}

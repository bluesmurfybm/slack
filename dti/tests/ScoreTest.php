<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class ScoreTest extends TestCase
{
    private function topic(array $over = []): int
    {
        return $this->post(['topics'], $this->admin(), ['title' => '점수 검증', 'field' => 'AX', ...$over])['data']['id'];
    }

    private function done(?array $who = null, string $doneDate = '2026-09-01', array $over = []): int
    {
        $who ??= $this->user();
        $tid = $this->topic($over);
        $this->post(['topics', (string)$tid, 'claim'], $who);
        $this->post(['topics', (string)$tid, 'complete'], $this->admin(), ['done_date' => $doneDate]);
        return $tid;
    }

    private function scores(array $query = []): array
    {
        return $this->get(['score'], $this->admin(), $query)['data'];
    }

    private function of(array $rows, string $email): array
    {
        foreach ($rows as $row) {
            if ($row['email'] === $email) return $row;
        }
        $this->fail("{$email} 이 점수표에 없다");
    }

    public function test_점수는_관리자만_본다(): void
    {
        $this->assertSame(401, $this->call('GET', ['score'], null)['status']);
        $this->assertSame(403, $this->get(['score'], $this->user())['status']);
    }

    public function test_발표완료는_10점(): void
    {
        $this->done();
        $me = $this->of($this->scores(), 'siyu@bluesoft.co.kr');
        $this->assertSame(10, $me['total']);
        $this->assertSame(10, $me['breakdown']['done']);
    }

    public function test_필수_아티클은_5점_보너스(): void
    {
        $this->done(over: ['requirement' => 'required']);
        $me = $this->of($this->scores(), 'siyu@bluesoft.co.kr');
        $this->assertSame(5, $me['breakdown']['required']);
        $this->assertSame(15, $me['total']);
    }

    public function test_자료를_올리면_3점(): void
    {
        $tid = $this->done();
        $this->post(['topics', (string)$tid, 'material', 'link'], $this->admin(),
            ['url' => 'https://x', 'name' => '슬라이드']);

        $me = $this->of($this->scores(), 'siyu@bluesoft.co.kr');
        $this->assertSame(3, $me['breakdown']['material']);
        $this->assertSame(13, $me['total']);
    }

    public function test_반응은_하루_3점까지(): void
    {
        $tid = $this->topic();
        $this->post(['topics', (string)$tid, 'complete'], $this->admin());
        foreach (['like', 'apply', 'easy', 'new'] as $kind) {
            $this->post(['topics', (string)$tid, 'emotions', $kind], $this->user());
        }

        $me = $this->of($this->scores(), 'siyu@bluesoft.co.kr');
        $this->assertSame(3, $me['breakdown']['reaction']);
        $this->assertSame(3, $me['total']);
    }

    public function test_기간으로_거른다(): void
    {
        $this->done(doneDate: '2025-12-31');
        $this->done(doneDate: '2026-01-01');

        $this->assertSame(20, $this->of($this->scores(), 'siyu@bluesoft.co.kr')['total']);
        $this->assertSame(10, $this->of($this->scores(['start' => '2026-01-01']), 'siyu@bluesoft.co.kr')['total']);
        $this->assertSame(10, $this->of($this->scores(['end' => '2025-12-31']), 'siyu@bluesoft.co.kr')['total']);
        $this->assertSame(0, $this->of($this->scores(['start' => '2026-02-01', 'end' => '2026-02-28']), 'siyu@bluesoft.co.kr')['total']);
    }

    public function test_형식이_틀린_기간은_422(): void
    {
        $this->assertSame(422, $this->get(['score'], $this->admin(), ['start' => '2026'])['status']);
        $this->assertSame(422, $this->get(['score'], $this->admin(), ['end' => '2026-1-1'])['status']);
    }

    public function test_발표자_없는_완료는_아무에게도_점수를_주지_않는다(): void
    {
        $tid = $this->topic();
        $this->post(['topics', (string)$tid, 'complete'], $this->admin());

        foreach ($this->scores() as $row) {
            $this->assertSame(0, $row['total'], $row['email']);
        }
    }

    public function test_명단에_없는_발표자도_점수표에_나온다(): void
    {
        $ghost = ['email' => 'ghost@bluesoft.co.kr', 'name' => '유령'];
        $this->done($ghost);

        $rows = $this->scores();
        $this->assertCount(count(self::PORTAL_USERS) + 1, $rows);
        $this->assertSame(10, $this->of($rows, 'ghost@bluesoft.co.kr')['total']);
    }

    public function test_명단_전체가_총점_순으로_나온다(): void
    {
        $this->done($this->other());

        $rows = $this->scores();
        $this->assertSame(count(self::PORTAL_USERS), count($rows));
        $this->assertSame('hjlee@bluesoft.co.kr', $rows[0]['email']);

        $totals = array_column($rows, 'total');
        $sorted = $totals;
        rsort($sorted);
        $this->assertSame($sorted, $totals);
    }

    public function test_이름은_포털_계정에서_가져온다(): void
    {
        $this->done();
        $this->assertSame('유승인', $this->of($this->scores(), 'siyu@bluesoft.co.kr')['name']);
    }
}

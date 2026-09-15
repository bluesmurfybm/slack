<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class TopicWriteTest extends TestCase
{
    private const NEW = [
        'field' => 'Trend', 'title' => '새 주제', 'keywords' => 'AI',
        'magazine' => 'DI', 'volume' => '280', 'page' => '12', 'year' => 2026,
        'requirement' => 'required',
    ];

    public function test_일반_사용자는_등록할_수_없다(): void
    {
        $this->assertSame(403, $this->post(['topics'], $this->user(), self::NEW)['status']);
    }

    public function test_미로그인은_등록할_수_없다(): void
    {
        $this->assertSame(401, $this->call('POST', ['topics'], null, self::NEW)['status']);
    }

    public function test_관리자가_등록하면_미지정으로_생긴다(): void
    {
        $res = $this->post(['topics'], $this->admin(), self::NEW);
        $this->assertSame(201, $res['status']);
        $this->assertSame('미지정', $res['data']['status']);
        $this->assertSame('required', $res['data']['requirement']);
        $this->assertSame('jian@bluesoft.co.kr', $res['data']['created_by']);
        $this->assertSame(2026, $res['data']['year']);
        $this->assertNotEmpty($res['data']['created_at']);
    }

    public function test_등록한_아티클이_목록에_보인다(): void
    {
        $this->post(['topics'], $this->admin(), self::NEW);
        $this->assertContains('새 주제', array_column($this->get(['topics'])['data'], 'title'));
    }

    public function test_제목은_필수다(): void
    {
        $this->assertSame(422, $this->post(['topics'], $this->admin(), ['field' => 'Etc'])['status']);
    }

    public function test_없는_매거진은_거부한다(): void
    {
        $res = $this->post(['topics'], $this->admin(), ['title' => 't', 'magazine' => '없는매거진']);
        $this->assertSame(422, $res['status']);
    }

    public function test_아는_매거진은_전부_받는다(): void
    {
        foreach (['', 'DI', 'MIT TR', 'Etc'] as $magazine) {
            $res = $this->post(['topics'], $this->admin(), ['title' => "t-{$magazine}", 'magazine' => $magazine]);
            $this->assertSame(201, $res['status'], $magazine);
        }
    }

    public function test_없는_팀은_거부한다(): void
    {
        $res = $this->post(['topics'], $this->admin(), ['title' => 't', 'team' => 'NOPE']);
        $this->assertSame(422, $res['status']);
    }

    public function test_등록에_예정일을_주면_발표가_생긴다(): void
    {
        $res = $this->post(['topics'], $this->admin(), [...self::NEW, 'planned_date' => '2026-09-01']);
        $this->assertSame('2026-09-01', $res['data']['planned_date']);
        // 발표자는 아직 없다 — 상태는 미지정이다
        $this->assertSame('미지정', $res['data']['status']);
    }

    public function test_관리자가_발표구분을_고친다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), self::NEW)['data']['id'];
        $res = $this->put(['topics', (string)$tid], $this->admin(), ['requirement' => 'recommended']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('recommended', $res['data']['requirement']);
        $this->assertSame('새 주제', $res['data']['title']);
    }

    public function test_보내지_않은_값은_건드리지_않는다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), self::NEW)['data']['id'];
        $res = $this->put(['topics', (string)$tid], $this->admin(), ['title' => '제목만 수정']);
        $this->assertSame('제목만 수정', $res['data']['title']);
        $this->assertSame('DI', $res['data']['magazine']);
        $this->assertSame('280', $res['data']['volume']);
        $this->assertSame(2026, $res['data']['year']);
    }

    public function test_일반_사용자는_수정도_삭제도_못_한다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), self::NEW)['data']['id'];
        $this->assertSame(403, $this->put(['topics', (string)$tid], $this->user(), ['title' => 'x'])['status']);
        $this->assertSame(403, $this->delete(['topics', (string)$tid], $this->user())['status']);
    }

    public function test_관리자가_삭제한다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), self::NEW)['data']['id'];
        $res = $this->delete(['topics', (string)$tid], $this->admin());
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertSame(404, $this->get(['topics', (string)$tid])['status']);
    }

    public function test_없는_아티클_수정은_404(): void
    {
        $this->assertSame(404, $this->put(['topics', '99999'], $this->admin(), ['title' => 'x'])['status']);
    }

    public function test_숨김_아티클은_관리자에게만_보인다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), [...self::NEW, 'active' => 0])['data']['id'];
        $this->assertContains($tid, array_column($this->get(['topics'], $this->admin())['data'], 'id'));
        $this->assertNotContains($tid, array_column($this->get(['topics'], $this->user())['data'], 'id'));
    }

    public function test_보관_아티클은_관리자에게만_보인다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), self::NEW)['data']['id'];
        $this->put(['topics', (string)$tid], $this->admin(), ['archived' => 1]);
        $this->assertContains($tid, array_column($this->get(['topics'], $this->admin())['data'], 'id'));
        $this->assertNotContains($tid, array_column($this->get(['topics'], $this->user())['data'], 'id'));
    }

    public function test_노출을_껐다_켰다_한다(): void
    {
        $tid = $this->post(['topics'], $this->admin(), self::NEW)['data']['id'];
        $this->assertSame(0, $this->put(['topics', (string)$tid], $this->admin(), ['active' => 0])['data']['active']);
        $this->assertSame(1, $this->put(['topics', (string)$tid], $this->admin(), ['active' => 1])['data']['active']);
    }

    public function test_삭제하면_발표와_반응도_같이_사라진다(): void
    {
        $tid = $this->makeTopic(['title' => '주제']);
        $pid = $this->makePresentation($tid, ['presenter_email' => 'siyu@bluesoft.co.kr', 'done_date' => '2026-03-01']);
        $this->pdo->exec("INSERT INTO dti_emotions (presentation_id, email, kind, created_at)
                                VALUES ({$pid}, 'siyu@bluesoft.co.kr', 'like', '2026-03-02 10:00:00')");

        $this->delete(['topics', (string)$tid], $this->admin());

        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM dti_presentations")->fetchColumn());
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM dti_emotions")->fetchColumn());
    }
}

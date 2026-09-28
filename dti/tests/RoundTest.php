<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class RoundTest extends TestCase
{
    private function makeRound(int $no, string $title = ''): int
    {
        $this->pdo->prepare("INSERT INTO dti_rounds (`no`, title, created_at) VALUES (?, ?, '2026-01-01 09:00:00')")
            ->execute([$no, $title]);
        return (int)$this->pdo->lastInsertId();
    }

    private function roundOf(int $tid): ?int
    {
        $stmt = $this->pdo->prepare("SELECT round_id FROM dti_topics WHERE id = ?");
        $stmt->execute([$tid]);
        $value = $stmt->fetchColumn();
        return $value === null ? null : (int)$value;
    }

    private function issues(array ...$pairs): array
    {
        return ['issues' => array_map(static fn ($p) => ['magazine' => $p[0], 'volume' => $p[1]], $pairs)];
    }

    private function flagsOf(int $tid): array
    {
        $stmt = $this->pdo->prepare("SELECT active, archived FROM dti_topics WHERE id = ?");
        $stmt->execute([$tid]);
        return array_map('intval', $stmt->fetch());
    }

    /* ---------- 목록 ---------- */

    public function test_목록은_번호_내림차순이다(): void
    {
        $this->makeRound(12, '9월');
        $this->makeRound(13, '10월 정기');

        $res = $this->get(['rounds']);
        $this->assertSame(200, $res['status']);
        $this->assertSame([13, 12], array_column($res['data'], 'no'));
        $this->assertSame('10월 정기', $res['data'][0]['title']);
        $this->assertIsInt($res['data'][0]['id']);
    }

    public function test_목록은_로그인이_필요하다(): void
    {
        $this->assertSame(401, $this->call('GET', ['rounds'], null)['status']);
    }

    /* ---------- 등록·수정·삭제 ---------- */

    public function test_관리자가_회차를_등록한다(): void
    {
        $res = $this->post(['rounds'], $this->admin(), ['no' => 13, 'title' => '10월 정기']);
        $this->assertSame(201, $res['status']);
        $this->assertSame(13, $res['data']['no']);
        $this->assertSame('10월 정기', $res['data']['title']);
        $this->assertContains(13, array_column($this->get(['rounds'])['data'], 'no'));
    }

    public function test_구성원은_회차를_다루지_못한다(): void
    {
        $rid = (string)$this->makeRound(12);

        $this->assertSame(403, $this->post(['rounds'], $this->user(), ['no' => 13])['status']);
        $this->assertSame(403, $this->put(['rounds', $rid], $this->user(), ['title' => 'x'])['status']);
        $this->assertSame(403, $this->delete(['rounds', $rid], $this->user())['status']);
        $this->assertSame(403, $this->post(['rounds', $rid, 'issue'], $this->user(),
            $this->issues(['DI', '286']))['status']);
        $this->assertSame(403, $this->post(['rounds', $rid, 'topics'], $this->user(), ['archived' => true])['status']);
    }

    public function test_번호는_필수이고_양의_정수다(): void
    {
        foreach ([[], ['no' => ''], ['no' => 'abc'], ['no' => 0], ['no' => -1], ['no' => 1.5]] as $body) {
            $this->assertSame(422, $this->post(['rounds'], $this->admin(), $body)['status'], json_encode($body));
        }
    }

    public function test_같은_번호는_409(): void
    {
        $this->makeRound(12);
        $this->assertSame(409, $this->post(['rounds'], $this->admin(), ['no' => 12])['status']);
    }

    public function test_보낸_값만_고친다(): void
    {
        $rid = $this->makeRound(12, '9월');

        $res = $this->put(['rounds', (string)$rid], $this->admin(), ['title' => '9월 정기']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(12, $res['data']['no']);
        $this->assertSame('9월 정기', $res['data']['title']);

        $res = $this->put(['rounds', (string)$rid], $this->admin(), ['no' => 14]);
        $this->assertSame(14, $res['data']['no']);
        $this->assertSame('9월 정기', $res['data']['title']);
    }

    public function test_다른_회차의_번호로는_고칠_수_없다(): void
    {
        $this->makeRound(12);
        $rid = $this->makeRound(13);
        $this->assertSame(409, $this->put(['rounds', (string)$rid], $this->admin(), ['no' => 12])['status']);
        $this->assertSame(200, $this->put(['rounds', (string)$rid], $this->admin(), ['no' => 13])['status']);
    }

    public function test_없는_회차는_404(): void
    {
        $this->assertSame(404, $this->put(['rounds', '999'], $this->admin(), ['title' => 'x'])['status']);
        $this->assertSame(404, $this->delete(['rounds', '999'], $this->admin())['status']);
        $this->assertSame(404, $this->post(['rounds', '999', 'issue'], $this->admin(),
            $this->issues(['DI', '286']))['status']);
        $this->assertSame(404, $this->post(['rounds', '999', 'topics'], $this->admin(), ['active' => false])['status']);
    }

    public function test_지우면_소속_아티클은_회차_없음이_된다(): void
    {
        $rid = $this->makeRound(12);
        $tid = $this->makeTopic(['round_id' => $rid]);

        $res = $this->delete(['rounds', (string)$rid], $this->admin());
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $this->get(['rounds'])['data']);
        $this->assertNull($this->roundOf($tid));
    }

    /* ---------- 호 단위 담기 ---------- */

    public function test_같은_매거진과_Volume_만_담긴다(): void
    {
        $rid = $this->makeRound(13);
        $a = $this->makeTopic(['magazine' => 'DI', 'volume' => '286']);
        $b = $this->makeTopic(['magazine' => 'DI', 'volume' => '286']);
        $otherVolume = $this->makeTopic(['magazine' => 'DI', 'volume' => '279']);
        $otherMagazine = $this->makeTopic(['magazine' => 'MIT TR', 'volume' => '286']);

        $res = $this->post(['rounds', (string)$rid, 'issue'], $this->admin(), $this->issues(['DI', '286']));
        $this->assertSame(200, $res['status']);
        $this->assertSame(['count' => 2, 'moved' => 0], $res['data']);
        $this->assertSame($rid, $this->roundOf($a));
        $this->assertSame($rid, $this->roundOf($b));
        $this->assertNull($this->roundOf($otherVolume));
        $this->assertNull($this->roundOf($otherMagazine));
    }

    public function test_다른_회차에_있던_아티클은_옮겨_오고_센다(): void
    {
        $old = $this->makeRound(12);
        $rid = $this->makeRound(13);
        $moved = $this->makeTopic(['magazine' => 'DI', 'volume' => '279', 'round_id' => $old]);
        $already = $this->makeTopic(['magazine' => 'DI', 'volume' => '279', 'round_id' => $rid]);
        $fresh = $this->makeTopic(['magazine' => 'DI', 'volume' => '279']);

        $res = $this->post(['rounds', (string)$rid, 'issue'], $this->admin(), $this->issues(['DI', '279']));
        $this->assertSame(['count' => 3, 'moved' => 1], $res['data']);
        foreach ([$moved, $already, $fresh] as $tid) {
            $this->assertSame($rid, $this->roundOf($tid));
        }
    }

    public function test_빈_Volume_도_한_호로_담는다(): void
    {
        $rid = $this->makeRound(13);
        $blank = $this->makeTopic(['magazine' => 'Etc', 'volume' => '']);
        $this->makeTopic(['magazine' => 'Etc', 'volume' => '1']);

        $res = $this->post(['rounds', (string)$rid, 'issue'], $this->admin(), $this->issues(['Etc', '']));
        $this->assertSame(['count' => 1, 'moved' => 0], $res['data']);
        $this->assertSame($rid, $this->roundOf($blank));
    }

    public function test_여러_호를_한_번에_담는다(): void
    {
        $old = $this->makeRound(12);
        $rid = $this->makeRound(13);
        $di = $this->makeTopic(['magazine' => 'DI', 'volume' => '286']);
        $mit = $this->makeTopic(['magazine' => 'MIT TR', 'volume' => '28.2026', 'round_id' => $old]);
        $left = $this->makeTopic(['magazine' => 'DI', 'volume' => '279']);

        $res = $this->post(['rounds', (string)$rid, 'issue'], $this->admin(),
            $this->issues(['DI', '286'], ['MIT TR', '28.2026']));
        $this->assertSame(['count' => 2, 'moved' => 1], $res['data']);
        $this->assertSame($rid, $this->roundOf($di));
        $this->assertSame($rid, $this->roundOf($mit));
        $this->assertNull($this->roundOf($left));
    }

    public function test_잘못된_호가_섞이면_하나도_담지_않는다(): void
    {
        $rid = (string)$this->makeRound(13);
        $tid = $this->makeTopic(['magazine' => 'DI', 'volume' => '286']);

        $res = $this->post(['rounds', $rid, 'issue'], $this->admin(), $this->issues(['DI', '286'], ['없는매거진', '1']));
        $this->assertSame(422, $res['status']);
        $this->assertNull($this->roundOf($tid));
    }

    public function test_호를_고르지_않으면_422(): void
    {
        $rid = (string)$this->makeRound(13);
        foreach ([[], ['issues' => []], ['issues' => 'DI'], ['issues' => ['DI']]] as $body) {
            $this->assertSame(422, $this->post(['rounds', $rid, 'issue'], $this->admin(), $body)['status'],
                json_encode($body));
        }
    }

    /* ---------- 일괄 보관·숨김 ---------- */

    public function test_회차_아티클을_한_번에_숨기고_회차_밖은_그대로다(): void
    {
        $rid = $this->makeRound(13);
        $a = $this->makeTopic(['round_id' => $rid]);
        $b = $this->makeTopic(['round_id' => $rid]);
        $outside = $this->makeTopic();

        $res = $this->post(['rounds', (string)$rid, 'topics'], $this->admin(), ['active' => false]);
        $this->assertSame(200, $res['status']);
        $this->assertSame(['count' => 2], $res['data']);
        $this->assertSame(['active' => 0, 'archived' => 0], $this->flagsOf($a));
        $this->assertSame(['active' => 0, 'archived' => 0], $this->flagsOf($b));
        $this->assertSame(['active' => 1, 'archived' => 0], $this->flagsOf($outside));
    }

    public function test_회차_아티클을_한_번에_보관하고_푼다(): void
    {
        $rid = $this->makeRound(13);
        $tid = $this->makeTopic(['round_id' => $rid]);
        $separately = $this->makeTopic(['round_id' => $rid, 'archived' => 1]);

        $this->post(['rounds', (string)$rid, 'topics'], $this->admin(), ['archived' => true]);
        $this->assertSame(['active' => 1, 'archived' => 1], $this->flagsOf($tid));

        $res = $this->post(['rounds', (string)$rid, 'topics'], $this->admin(), ['archived' => false]);
        $this->assertSame(['count' => 2], $res['data']);
        $this->assertSame(0, $this->flagsOf($separately)['archived']);
    }

    public function test_보관과_노출을_함께_바꾼다(): void
    {
        $rid = $this->makeRound(13);
        $tid = $this->makeTopic(['round_id' => $rid, 'active' => 0]);

        $this->post(['rounds', (string)$rid, 'topics'], $this->admin(), ['archived' => true, 'active' => true]);
        $this->assertSame(['active' => 1, 'archived' => 1], $this->flagsOf($tid));
    }

    public function test_바꿀_값이_없으면_422(): void
    {
        $rid = (string)$this->makeRound(13);
        $this->assertSame(422, $this->post(['rounds', $rid, 'topics'], $this->admin(), [])['status']);
    }

    public function test_숨긴_회차의_아티클은_구성원_목록에서_빠지고_예약되지_않는다(): void
    {
        $rid = $this->makeRound(13);
        $tid = $this->makeTopic(['title' => '회차 아티클', 'round_id' => $rid]);

        $this->post(['rounds', (string)$rid, 'topics'], $this->admin(), ['active' => false]);

        $this->assertNotContains('회차 아티클', array_column($this->get(['topics'])['data'], 'title'));
        $this->assertContains('회차 아티클', array_column($this->get(['topics'], $this->admin())['data'], 'title'));
        $this->assertSame(409, $this->post(['topics', (string)$tid, 'claim'])['status']);
    }

    /* ---------- 아티클의 회차 ---------- */

    public function test_아티클_응답에_회차가_있다(): void
    {
        $rid = $this->makeRound(13);
        $in = $this->makeTopic(['title' => '안', 'round_id' => $rid]);
        $this->makeTopic(['title' => '밖']);

        $rows = array_column($this->get(['topics'])['data'], null, 'title');
        $this->assertSame($rid, $rows['안']['round_id']);
        $this->assertNull($rows['밖']['round_id']);
        $this->assertSame($rid, $this->get(['topics', (string)$in])['data']['round_id']);
    }

    public function test_등록과_수정으로_회차를_정한다(): void
    {
        $rid = $this->makeRound(13);

        $res = $this->post(['topics'], $this->admin(), ['title' => '새 주제', 'round_id' => $rid]);
        $this->assertSame(201, $res['status']);
        $this->assertSame($rid, $res['data']['round_id']);
        $tid = (string)$res['data']['id'];

        $this->assertSame($rid, $this->put(['topics', $tid], $this->admin(), ['title' => '고침'])['data']['round_id']);
        $this->assertNull($this->put(['topics', $tid], $this->admin(), ['round_id' => ''])['data']['round_id']);
        $this->assertNull($this->roundOf((int)$tid));
    }

    public function test_회차_없이_등록하면_회차_없음이다(): void
    {
        $res = $this->post(['topics'], $this->admin(), ['title' => '새 주제']);
        $this->assertNull($res['data']['round_id']);
    }

    public function test_없는_회차로는_등록도_수정도_못_한다(): void
    {
        $this->assertSame(422, $this->post(['topics'], $this->admin(), ['title' => 't', 'round_id' => 999])['status']);

        $tid = (string)$this->makeTopic();
        $this->assertSame(422, $this->put(['topics', $tid], $this->admin(), ['round_id' => 999])['status']);
        $this->assertSame(422, $this->put(['topics', $tid], $this->admin(), ['round_id' => 'abc'])['status']);
    }
}

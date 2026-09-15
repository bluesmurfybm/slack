<?php

namespace Dti\Tests;

use Dti\Http\Request;
use Dti\Kernel;
use Dti\Tests\Support\TestCase;

final class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPortalUsers([
            ['김지안', 'jian@bluesoft.co.kr'],
            ['유승인', 'siyu@bluesoft.co.kr'],
            ['진소현', 'lenda83@bluesoft.co.kr'],
        ]);
    }

    private function whoami(?array $identity): array
    {
        return (new Kernel($this->config, $this->pdo, $identity))
            ->handle(new Request('GET', ['whoami']))->data;
    }

    public function test_관리자로_표시된다(): void
    {
        $body = $this->whoami(['email' => 'jian@bluesoft.co.kr', 'name' => '김지안']);
        $this->assertSame('jian@bluesoft.co.kr', $body['email']);
        $this->assertTrue($body['is_admin']);
    }

    public function test_일반_사용자는_관리자가_아니다(): void
    {
        $this->assertFalse($this->whoami(['email' => 'siyu@bluesoft.co.kr', 'name' => ''])['is_admin']);
    }

    public function test_내_팀이_실린다(): void
    {
        $this->assertSame(['APP'], $this->whoami(['email' => 'siyu@bluesoft.co.kr', 'name' => ''])['teams']);
    }

    public function test_두_팀에_걸치면_둘_다_실린다(): void
    {
        $this->assertSame(['APP', 'LAB'], $this->whoami(['email' => 'lenda83@bluesoft.co.kr', 'name' => ''])['teams']);
    }

    public function test_명단에_없으면_팀이_없다(): void
    {
        $this->assertSame([], $this->whoami(['email' => 'nobody@bluesoft.co.kr', 'name' => ''])['teams']);
    }

    public function test_화면이_읽는_상수가_전부_실린다(): void
    {
        $body = $this->whoami(['email' => 'siyu@bluesoft.co.kr', 'name' => '']);
        $this->assertSame(['APP', 'SQUARE', 'LAB'], $body['all_teams']);
        $this->assertSame(['DI', 'MIT TR', 'Etc'], $body['all_magazines']);
        // 화면은 여기에 /?view=profile, /logout.php 를 이어붙인다. 페이지가 아니라 기준 경로다
        $this->assertSame('..', $body['portal_url']);
        $this->assertSame('../slack/lists.php', $body['slack_url']);
    }

    public function test_개발_로그인_키는_없다(): void
    {
        // 포털 세션을 쓰면서 개발 로그인 자체가 없어졌다. 화면도 더 읽지 않는다
        $body = $this->whoami(['email' => 'siyu@bluesoft.co.kr', 'name' => '']);
        $this->assertArrayNotHasKey('dev_login', $body);
        $this->assertArrayNotHasKey('dev_accounts', $body);
    }

    public function test_미로그인_whoami_는_빈_신원(): void
    {
        $body = $this->whoami(null);
        $this->assertNull($body['email']);
        $this->assertNull($body['name']);
        $this->assertFalse($body['is_admin']);
    }

    public function test_구성원_명단은_포털_계정에서_온다(): void
    {
        $res = (new Kernel($this->config, $this->pdo, ['email' => 'siyu@bluesoft.co.kr', 'name' => '']))
            ->handle(new Request('GET', ['members']));
        $this->assertSame(200, $res->status);
        $this->assertSame(['김지안', '유승인', '진소현'], array_column($res->data, 'name'));
        $this->assertSame(['SQUARE'], $res->data[0]['teams']);
    }

    public function test_구성원_명단은_로그인이_필요하다(): void
    {
        $res = (new Kernel($this->config, $this->pdo, null))->handle(new Request('GET', ['members']));
        $this->assertSame(401, $res->status);
    }
}

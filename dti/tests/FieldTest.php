<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class FieldTest extends TestCase
{
    public function test_목록은_기본_분야를_등록순으로_준다(): void
    {
        $res = $this->get(['fields']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(['UI/UX', 'Marketing', 'Trend', 'AX', 'Etc'], array_column($res['data'], 'name'));
        $this->assertIsInt($res['data'][0]['id']);
    }

    public function test_목록은_로그인이_필요하다(): void
    {
        $this->assertSame(401, $this->call('GET', ['fields'], null)['status']);
    }

    public function test_관리자가_분야를_더한다(): void
    {
        $res = $this->post(['fields'], $this->admin(), ['name' => '보안']);
        $this->assertSame(201, $res['status']);
        $this->assertSame('보안', $res['data']['name']);
        $this->assertContains('보안', array_column($this->get(['fields'])['data'], 'name'));
    }

    public function test_일반_사용자는_더하지_못한다(): void
    {
        $this->assertSame(403, $this->post(['fields'], $this->user(), ['name' => '보안'])['status']);
    }

    public function test_빈_이름은_422(): void
    {
        $this->assertSame(422, $this->post(['fields'], $this->admin(), ['name' => '  '])['status']);
    }

    public function test_이미_있는_분야는_409(): void
    {
        $this->assertSame(409, $this->post(['fields'], $this->admin(), ['name' => 'AX'])['status']);
    }

    public function test_관리자가_분야를_지운다(): void
    {
        $fid = $this->get(['fields'])['data'][0]['id'];
        $res = $this->delete(['fields', (string)$fid], $this->admin());
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertNotContains($fid, array_column($this->get(['fields'])['data'], 'id'));
    }

    public function test_없는_분야_삭제는_404(): void
    {
        $this->assertSame(404, $this->delete(['fields', '99999'], $this->admin())['status']);
    }

    public function test_일반_사용자는_지우지_못한다(): void
    {
        $fid = $this->get(['fields'])['data'][0]['id'];
        $this->assertSame(403, $this->delete(['fields', (string)$fid], $this->user())['status']);
    }
}

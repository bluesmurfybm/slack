<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class RoutingTest extends TestCase
{
    public function test_없는_경로는_404(): void
    {
        $res = $this->get(['nope']);
        $this->assertSame(404, $res['status']);
        $this->assertSame('없는 API 입니다', $res['data']['detail']);
    }

    public function test_미로그인은_401(): void
    {
        $res = $this->call('GET', ['topics'], null);
        $this->assertSame(401, $res['status']);
        $this->assertSame('로그인이 필요합니다', $res['data']['detail']);
    }

    public function test_whoami_는_미로그인에도_답한다(): void
    {
        $res = $this->call('GET', ['whoami'], null);
        $this->assertSame(200, $res['status']);
        $this->assertNull($res['data']['email']);
    }
}

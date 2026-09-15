<?php

namespace Dti\Tests;

use Dti\Http\Request;
use Dti\Kernel;
use Dti\Tests\Support\TestCase;

final class KernelRoutingTest extends TestCase
{
    private function kernel(?array $identity): Kernel
    {
        return new Kernel($this->config, $this->pdo, $identity);
    }

    public function test_없는_경로는_404(): void
    {
        $res = $this->kernel(['email' => 'siyu@bluesoft.co.kr', 'name' => ''])->handle(new Request('GET', ['nope']));
        $this->assertSame(404, $res->status);
        $this->assertSame('없는 API 입니다', $res->data['detail']);
    }

    public function test_미로그인은_401(): void
    {
        $res = $this->kernel(null)->handle(new Request('GET', ['topics']));
        $this->assertSame(401, $res->status);
        $this->assertSame('로그인이 필요합니다', $res->data['detail']);
    }

    public function test_whoami_는_미로그인에도_답한다(): void
    {
        $res = $this->kernel(null)->handle(new Request('GET', ['whoami']));
        $this->assertSame(200, $res->status);
        $this->assertNull($res->data['email']);
    }
}

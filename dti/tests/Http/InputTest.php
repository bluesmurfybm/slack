<?php

namespace Dti\Tests\Http;

use Dti\Http\ApiException;
use Dti\Http\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function test_필수값이_비면_422(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(422);
        Input::str([], 'title', '제목', required: true);
    }

    public function test_문자열은_앞뒤_공백을_턴다(): void
    {
        $this->assertSame('제목', Input::str(['title' => '  제목  '], 'title', '제목'));
    }

    public function test_날짜는_YYYY_MM_DD_만_받는다(): void
    {
        $this->assertSame('', Input::date('', '예정일'));
        $this->assertSame('2026-09-01', Input::date('2026-09-01', '예정일'));

        $this->expectException(ApiException::class);
        Input::date('2026/09/01', '예정일');
    }

    public function test_주소는_http_로_시작해야_한다(): void
    {
        $this->assertSame('https://a.b/c', Input::url('https://a.b/c', '주소'));

        $this->expectException(ApiException::class);
        Input::url('javascript:alert(1)', '주소');
    }

    public function test_열거값을_벗어나면_422(): void
    {
        $this->assertSame('APP', Input::oneOf('APP', ['APP', 'LAB'], '팀'));
        $this->assertSame('', Input::oneOf('', ['APP', 'LAB'], '팀'));

        $this->expectException(ApiException::class);
        Input::oneOf('NOPE', ['APP', 'LAB'], '팀');
    }

    public function test_년도는_비면_null(): void
    {
        $this->assertNull(Input::nullableInt([], 'year', '년도'));
        $this->assertNull(Input::nullableInt(['year' => null], 'year', '년도'));
        $this->assertSame(2026, Input::nullableInt(['year' => '2026'], 'year', '년도'));
    }
}

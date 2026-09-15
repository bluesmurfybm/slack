<?php

namespace Dti\Tests;

use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function test_필수값이_비면_422(): void
    {
        $this->expectException(\DtiError::class);
        $this->expectExceptionCode(422);
        dti_want_str([], 'title', '제목', required: true);
    }

    public function test_문자열은_앞뒤_공백을_턴다(): void
    {
        $this->assertSame('제목', dti_want_str(['title' => '  제목  '], 'title', '제목'));
    }

    public function test_날짜는_YYYY_MM_DD_만_받는다(): void
    {
        $this->assertSame('', dti_want_date('', '예정일'));
        $this->assertSame('2026-09-01', dti_want_date('2026-09-01', '예정일'));

        $this->expectException(\DtiError::class);
        dti_want_date('2026/09/01', '예정일');
    }

    public function test_주소는_http_로_시작해야_한다(): void
    {
        $this->assertSame('https://a.b/c', dti_want_url('https://a.b/c', '주소'));

        $this->expectException(\DtiError::class);
        dti_want_url('javascript:alert(1)', '주소');
    }

    public function test_열거값을_벗어나면_422(): void
    {
        $this->assertSame('APP', dti_want_one_of('APP', ['APP', 'LAB'], '팀'));
        $this->assertSame('', dti_want_one_of('', ['APP', 'LAB'], '팀'));

        $this->expectException(\DtiError::class);
        dti_want_one_of('NOPE', ['APP', 'LAB'], '팀');
    }

    public function test_년도는_비면_null(): void
    {
        $this->assertNull(dti_want_nullable_int([], 'year', '년도'));
        $this->assertNull(dti_want_nullable_int(['year' => null], 'year', '년도'));
        $this->assertSame(2026, dti_want_nullable_int(['year' => '2026'], 'year', '년도'));
    }
}

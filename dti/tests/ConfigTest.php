<?php

namespace Dti\Tests;

use Dti\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private function config(array $over = []): Config
    {
        return new Config(
            db: ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => '', 'name' => 'slackapi_test', 'charset' => 'utf8mb4'],
            uploadDir: '/tmp/dti-uploads',
            maxUploadMb: $over['maxUploadMb'] ?? 50,
        );
    }

    public function test_기본_관리자_명단(): void
    {
        $config = $this->config();
        $this->assertTrue($config->isAdmin('jian@bluesoft.co.kr'));
        $this->assertTrue($config->isAdmin('KIMHY@bluesoft.co.kr'));
        $this->assertFalse($config->isAdmin('siyu@bluesoft.co.kr'));
        $this->assertFalse($config->isAdmin(null));
    }

    public function test_업로드_상한은_바이트로_환산된다(): void
    {
        $this->assertSame(3 * 1024 * 1024, $this->config(['maxUploadMb' => 3])->maxUploadBytes());
    }

    public function test_팀은_이메일로_찾고_모르는_사람은_빈_배열(): void
    {
        $config = $this->config();
        $this->assertSame(['APP'], $config->teamsOf('siyu@bluesoft.co.kr'));
        $this->assertSame(['APP', 'LAB'], $config->teamsOf('lenda83@bluesoft.co.kr'));
        $this->assertSame([], $config->teamsOf('nobody@bluesoft.co.kr'));
        $this->assertSame([], $config->teamsOf(null));
    }

    public function test_팀_매핑에_없는_팀이_들어가면_거부한다(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(
            db: ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => '', 'name' => 'slackapi_test', 'charset' => 'utf8mb4'],
            uploadDir: '/tmp/dti-uploads',
            teamsByEmail: ['x@bluesoft.co.kr' => ['NOPE']],
        );
    }
}

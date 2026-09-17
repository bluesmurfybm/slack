<?php

namespace Dti\Tests;

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private function config(array $over = []): array
    {
        return dti_config($over + [
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => '', 'name' => 'slackapi_test', 'charset' => 'utf8mb4'],
            'upload_dir' => '/tmp/dti-uploads',
        ]);
    }

    public function test_기본_관리자_명단(): void
    {
        $config = $this->config();
        $this->assertTrue(dti_is_admin($config, 'jian@bluesoft.co.kr'));
        $this->assertTrue(dti_is_admin($config, 'KIMHY@bluesoft.co.kr'));
        $this->assertFalse(dti_is_admin($config, 'siyu@bluesoft.co.kr'));
        $this->assertFalse(dti_is_admin($config, null));
    }

    public function test_업로드_상한은_바이트로_환산된다(): void
    {
        $this->assertSame(3 * 1024 * 1024, dti_max_upload_bytes($this->config(['max_upload_mb' => 3])));
    }

    public function test_팀은_이메일로_찾고_모르는_사람은_빈_배열(): void
    {
        $config = $this->config();
        $this->assertSame(['APP'], dti_teams_of($config, 'siyu@bluesoft.co.kr'));
        $this->assertSame(['APP', 'LAB'], dti_teams_of($config, 'lenda83@bluesoft.co.kr'));
        $this->assertSame([], dti_teams_of($config, 'nobody@bluesoft.co.kr'));
        $this->assertSame([], dti_teams_of($config, null));
    }

    public function test_변환기_설정은_기본값이_있고_덮어쓸_수_있다(): void
    {
        $this->assertSame('soffice', $this->config()['soffice']);
        $this->assertSame(60, $this->config()['soffice_timeout']);
        $this->assertSame('/opt/libreoffice/soffice', $this->config(['soffice' => '/opt/libreoffice/soffice'])['soffice']);
    }

    public function test_soffice_경로는_포털_설정에서_받아온다(): void
    {
        $config = dti_config_from_portal([], [
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => '', 'name' => 'slackapi_test', 'charset' => 'utf8mb4'],
            'soffice' => '/opt/libreoffice/soffice',
        ]);

        $this->assertSame('/opt/libreoffice/soffice', $config['soffice']);
    }

    public function test_팀_매핑에_없는_팀이_들어가면_거부한다(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config(['teams_by_email' => ['x@bluesoft.co.kr' => ['NOPE']]]);
    }
}

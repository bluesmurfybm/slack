<?php

namespace Dti\Tests;

use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    private function config(): array
    {
        return dti_config(['admin_emails' => ['jian@bluesoft.co.kr']]);
    }

    public function test_미로그인은_포털_로그인으로_보낸다(): void
    {
        $this->assertSame('../index.php?need_login=dti', dti_page_redirect($this->config(), null, dti_page('list')));
        $this->assertSame('../../index.php?need_login=dti', dti_page_redirect($this->config(), null, dti_page('rounds')));
    }

    public function test_구성원은_관리자_페이지에서_목록으로_돌아간다(): void
    {
        $user = ['email' => 'siyu@bluesoft.co.kr', 'name' => '유승인'];
        $this->assertNull(dti_page_redirect($this->config(), $user, dti_page('list')));
        foreach (['articles', 'archive', 'rounds', 'fields', 'stats', 'score'] as $key) {
            $this->assertSame('../index.php', dti_page_redirect($this->config(), $user, dti_page($key)), $key);
        }
    }

    public function test_관리자는_모든_페이지에_들어간다(): void
    {
        $admin = ['email' => 'JIAN@bluesoft.co.kr', 'name' => '김지안'];
        foreach (array_keys(DTI_PAGES) as $key) {
            $this->assertNull(dti_page_redirect($this->config(), $admin, dti_page($key)), $key);
        }
    }

    public function test_관리자_페이지는_한_단계_아래에서_경로를_잡는다(): void
    {
        $this->assertSame('', dti_page('list')['base']);
        $this->assertSame('../', dti_page('stats')['base']);
        $this->assertSame('../admin/rounds.php', dti_page_href(dti_page('stats'), 'rounds'));
        $this->assertSame('admin/index.php', dti_page_href(dti_page('list'), 'articles'));
        $this->assertSame('../index.php', dti_page_href(dti_page('rounds'), 'list'));
    }

    public function test_스크립트는_공통_앞뒤에_페이지_몫이_낀다(): void
    {
        $scripts = dti_page('rounds')['scripts'];
        $this->assertSame('core', $scripts[0]);
        $this->assertSame('main', end($scripts));
        $this->assertContains('rounds', $scripts);
        $this->assertNotContains('drawer', $scripts);
        $this->assertContains('drawer', dti_page('list')['scripts']);
        $this->assertNotContains('form', dti_page('list')['scripts']);
        $this->assertContains('form', dti_page('archive')['scripts']);
        $this->assertContains('schedule', dti_page('list')['scripts']);
        $this->assertNotContains('schedule', dti_page('articles')['scripts']);
    }

    public function test_자산_주소에_수정시각이_붙는다(): void
    {
        $mtime = filemtime(__DIR__ . '/../static/core.js');
        $this->assertSame("../static/core.js?v={$mtime}", dti_asset(dti_page('rounds'), 'static/core.js'));
        $this->assertSame("static/core.js?v={$mtime}", dti_asset(dti_page('list'), 'static/core.js'));
        $this->assertSame('../../styles/topbar.css?v=' . filemtime(__DIR__ . '/../../styles/topbar.css'),
            dti_asset(dti_page('stats'), '../styles/topbar.css'));
    }

    public function test_없는_페이지는_거부한다(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        dti_page('nope');
    }
}

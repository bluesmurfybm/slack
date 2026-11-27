<?php

namespace Bluecluster\Tests;

use PHPUnit\Framework\TestCase;

final class menu_test extends TestCase
{
    /** core/worksystems.json: key bluecluster 항목 1개, label */
    public function test_worksystems_label()
    {
        $root = dirname(__DIR__, 2);
        $list = json_decode(file_get_contents($root . '/core/worksystems.json'), true, 512, JSON_THROW_ON_ERROR);

        $found = array_values(array_filter($list, function ($s) {
            return $s['key'] === 'bluecluster';
        }));

        $this->assertCount(1, $found);
        $this->assertSame('ClusterAgent (test)', $found[0]['label']);
    }

    /** core/worksystems.json: key bluecluster 의 path 파일 */
    public function test_worksystems_path_파일()
    {
        $root = dirname(__DIR__, 2);
        $list = json_decode(file_get_contents($root . '/core/worksystems.json'), true, 512, JSON_THROW_ON_ERROR);

        $found = array_values(array_filter($list, function ($s) {
            return $s['key'] === 'bluecluster';
        }));

        $this->assertCount(1, $found);
        $this->assertSame('bluecluster/index.php', $found[0]['path']);
        $this->assertFileExists($root . '/' . $found[0]['path']);
    }

    /** styles/icons/bluecluster.svg: 카드 아이콘 파일 */
    public function test_icon_파일()
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/styles/icons/bluecluster.svg');
    }

    /** styles/favicons/bluecluster.svg: 탭 파비콘 파일 */
    public function test_favicon_파일()
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/styles/favicons/bluecluster.svg');
    }

    /** bcl_page_head: 사용자 칩 onclick 과 bluecluster.js 스크립트 태그 */
    public function test_layout_사용자_메뉴_스크립트()
    {
        $layout = file_get_contents(dirname(__DIR__) . '/inc/layout.php');

        $this->assertStringContainsString('onclick="bclToggleMenu(event)"', $layout);
        $this->assertStringContainsString('<script src="assets/bluecluster.js?v=', $layout);
        $this->assertFileExists(dirname(__DIR__) . '/assets/bluecluster.js');
    }
}

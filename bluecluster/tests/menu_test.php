<?php

namespace Bluecluster\Tests;

use PHPUnit\Framework\TestCase;

final class menu_test extends TestCase
{
    /** core/worksystems.json: key bluecluster 항목 1개, label */
    public function test_worksystems_label(): void
    {
        $root = dirname(__DIR__, 2);
        $list = json_decode(file_get_contents($root . '/core/worksystems.json'), true, 512, JSON_THROW_ON_ERROR);

        $found = array_values(array_filter($list, function ($s) {
            return $s['key'] === 'bluecluster';
        }));

        $this->assertCount(1, $found);
        $this->assertSame('블루 클러스터 에이전트 (test)', $found[0]['label']);
    }

    /** core/worksystems.json: key bluecluster 의 path 파일 */
    public function test_worksystems_path_파일(): void
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
    public function test_icon_파일(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/styles/icons/bluecluster.svg');
    }

    /** styles/favicons/bluecluster.svg: 탭 파비콘 파일 */
    public function test_favicon_파일(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/styles/favicons/bluecluster.svg');
    }
}

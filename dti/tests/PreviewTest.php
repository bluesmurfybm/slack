<?php

namespace Dti\Tests;

use PHPUnit\Framework\TestCase;

final class PreviewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dti-preview-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) rmdir($this->dir);
    }

    private function put(string $stored, string $body = 'x'): string
    {
        file_put_contents($this->dir . '/' . $stored, $body);
        return $stored;
    }

    public function test_변환기는_받은_실행기로_명령을_돌린다(): void
    {
        $stored = $this->put('7_abc.pptx');
        $seen = null;
        $run = function (string $cmd) use (&$seen) {
            $seen = $cmd;
            preg_match("/--outdir '([^']+)'/", $cmd, $m);
            file_put_contents($m[1] . '/7_abc.pdf', '%PDF-run');
        };
        $before = glob(sys_get_temp_dir() . '/dti-convert-*') ?: [];

        $out = $this->dir . '/7_xyz.pdf';
        dti_soffice_converter('/usr/bin/soffice', 30, $run)($this->dir . '/' . $stored, $out);

        $this->assertStringContainsString('--convert-to pdf', $seen);
        $this->assertSame('%PDF-run', file_get_contents($out),
            'soffice 는 7_abc.pdf 로 내놓으므로 우리가 정한 이름으로 옮겨야 한다');
        $this->assertSame($before, glob(sys_get_temp_dir() . '/dti-convert-*') ?: []);
    }

    public function test_실행기가_터져도_작업_디렉터리를_남기지_않는다(): void
    {
        $before = glob(sys_get_temp_dir() . '/dti-convert-*') ?: [];
        $stored = $this->put('7_abc.pptx');
        $run = function () {
            throw new \RuntimeException('boom');
        };

        try {
            dti_soffice_converter('soffice', 30, $run)($this->dir . '/' . $stored, $this->dir . '/out.pdf');
            $this->fail('실행기의 예외는 삼키지 않는다');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($before, glob(sys_get_temp_dir() . '/dti-convert-*') ?: []);
    }

    public function test_변환_명령은_헤드리스로_pdf_를_만든다(): void
    {
        $cmd = dti_soffice_command('/usr/bin/soffice', '/up/7_a.pptx', '/work', 60);

        $this->assertStringContainsString('--headless', $cmd);
        $this->assertStringContainsString('--convert-to pdf', $cmd);
        $this->assertStringContainsString('--outdir', $cmd);
    }

    public function test_변환_명령은_프로필과_제한시간을_챙긴다(): void
    {
        $a = dti_soffice_command('/usr/bin/soffice', '/up/7_a.pptx', '/work', 60);
        $b = dti_soffice_command('/usr/bin/soffice', '/up/7_a.pptx', '/work', 60);

        $this->assertStringContainsString('-env:UserInstallation=file://', $a,
            '웹 서버 사용자는 쓸 수 있는 홈이 없어 프로필 경로를 직접 줘야 한다');
        $this->assertNotSame($a, $b, '같은 프로필을 쓰면 동시 변환이 서로를 막는다');
        $this->assertStringContainsString('timeout 60', $a);
    }

    public function test_경로에_든_셸_문자는_실행되지_않는다(): void
    {
        $evil = $this->dir . '/7_a; touch ' . $this->dir . '/pwned; true .pptx';
        $cmd = dti_soffice_command('/bin/echo', $evil, $this->dir, 60);

        exec($cmd, $out);

        $this->assertFileDoesNotExist($this->dir . '/pwned');
        $this->assertStringContainsString($evil, implode(' ', $out), '경로는 인자 하나로 그대로 전달된다');
    }

    public function test_저장명은_주제와_난수로_만들고_확장자를_남긴다(): void
    {
        $this->assertMatchesRegularExpression('/^7_[0-9a-f]{32}\.pptx$/', dti_stored_name(7, '발표.pptx'));
        $this->assertMatchesRegularExpression('/^7_[0-9a-f]{32}$/', dti_stored_name(7, '확장자없음'));
        $this->assertNotSame(dti_stored_name(7, 'a.pdf'), dti_stored_name(7, 'a.pdf'));
    }

    public function test_저장명은_원본_경로를_버린다(): void
    {
        $this->assertMatchesRegularExpression('/^7_[0-9a-f]{32}\.pptx$/',
            dti_stored_name(7, '../../etc/발표.pptx'));
    }

    public function test_ppt_를_올리면_pdf_자료_값을_함께_돌려준다(): void
    {
        $stored = $this->put('7_abc.pptx');
        $converter = function (string $src, string $out) {
            file_put_contents($out, '%PDF-conv');
        };

        $pdf = dti_pdf_companion($this->dir, 7, '발표자료.pptx', $stored, $converter);

        $this->assertSame('발표자료.pdf', $pdf['name']);
        $this->assertMatchesRegularExpression('/^7_[0-9a-f]{32}\.pdf$/', $pdf['path']);
        $this->assertSame('%PDF-conv', file_get_contents($this->dir . '/' . $pdf['path']));
    }

    public function test_변환_대상이_아니면_변환기를_부르지_않는다(): void
    {
        $stored = $this->put('7_abc.pdf');
        $called = false;
        $converter = function () use (&$called) {
            $called = true;
        };

        $this->assertNull(dti_pdf_companion($this->dir, 7, '이미.pdf', $stored, $converter));
        $this->assertFalse($called);
    }

    public function test_변환기가_결과물을_못_내면_pdf_자료는_없다(): void
    {
        $stored = $this->put('7_abc.pptx');
        $converter = function () {
        };

        $this->assertNull(dti_pdf_companion($this->dir, 7, '발표.pptx', $stored, $converter));
    }

    public function test_변환기가_터져도_업로드를_망치지_않는다(): void
    {
        $stored = $this->put('7_abc.pptx');
        $converter = function () {
            throw new \RuntimeException('soffice: command not found');
        };

        $this->assertNull(dti_pdf_companion($this->dir, 7, '발표.pptx', $stored, $converter));
    }

    public function test_변환할_수_있는_형식만_고른다(): void
    {
        $this->assertTrue(dti_pdf_convertible('발표.pptx'));
        $this->assertTrue(dti_pdf_convertible('발표.ppt'));
        $this->assertTrue(dti_pdf_convertible('발표.odp'));
        $this->assertTrue(dti_pdf_convertible('DECK.PPTX'), '확장자는 대소문자를 가리지 않는다');
    }

    public function test_변환할_필요도_능력도_없는_형식은_제외한다(): void
    {
        $this->assertFalse(dti_pdf_convertible('이미.pdf'));
        $this->assertFalse(dti_pdf_convertible('사진.png'));
        $this->assertFalse(dti_pdf_convertible('발표.key'), '키노트는 변환할 수 없다');
        $this->assertFalse(dti_pdf_convertible('확장자없음'));
    }
}

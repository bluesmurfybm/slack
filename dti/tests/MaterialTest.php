<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class MaterialTest extends TestCase
{
    private function claimed(): int
    {
        $tid = $this->post(['topics'], $this->admin(), ['title' => '자료 붙일 주제'])->data['id'];
        $this->post(['topics', (string)$tid, 'claim'], $this->user());
        return $tid;
    }

    private function contentOf(int $tid, string $slot = 'material'): string
    {
        $res = $this->get(['topics', (string)$tid, $slot, 'download']);
        return file_get_contents($res->filePath);
    }

    /* ---------- 링크 ---------- */

    public function test_발표자가_링크를_단다(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(),
            ['url' => 'https://example.com/deck.pdf', 'name' => '발표자료']);

        $this->assertSame(200, $res->status);
        $this->assertSame('link', $res->data['material_kind']);
        $this->assertSame('https://example.com/deck.pdf', $res->data['material_url']);
        $this->assertSame('발표자료', $res->data['material_name']);
    }

    public function test_이름을_안_주면_주소를_이름으로_쓴다(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(),
            ['url' => 'https://example.com/a']);
        $this->assertSame('https://example.com/a', $res->data['material_name']);
    }

    public function test_링크는_목록에도_보인다(): void
    {
        $tid = $this->claimed();
        $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(), ['url' => 'https://example.com/a']);

        $rows = array_column($this->get(['topics'])->data, null, 'id');
        $this->assertSame('link', $rows[$tid]['material_kind']);
    }

    public function test_제삼자는_자료를_올리지_못한다(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'material', 'link'], $this->other(), ['url' => 'https://example.com/a']);
        $this->assertSame(403, $res->status);
    }

    public function test_관리자는_남의_아티클에도_올린다(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'material', 'link'], $this->admin(), ['url' => 'https://example.com/a']);
        $this->assertSame(200, $res->status);
    }

    public function test_미로그인은_올리지_못한다(): void
    {
        $tid = $this->claimed();
        $res = $this->call('POST', ['topics', (string)$tid, 'material', 'link'], null, ['url' => 'https://example.com/a']);
        $this->assertSame(401, $res->status);
    }

    public function test_http_가_아닌_주소는_거부한다(): void
    {
        $tid = $this->claimed();
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'ftp://x/y', ''] as $bad) {
            $res = $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(), ['url' => $bad]);
            $this->assertSame(422, $res->status, $bad);
        }
    }

    /* ---------- 파일 ---------- */

    public function test_발표자가_파일을_올린다(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [],
            $this->upload('발표.pdf', '%PDF-1.4 fake'));

        $this->assertSame(200, $res->status);
        $this->assertSame('file', $res->data['material_kind']);
        $this->assertSame('발표.pdf', $res->data['material_name']);
        // 저장 이름은 서버가 만든다
        $this->assertNotEmpty($res->data['material_path']);
        $this->assertNotSame('발표.pdf', $res->data['material_path']);
    }

    public function test_올린_파일을_내려받는다(): void
    {
        $tid = $this->claimed();
        $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [], $this->upload('발표.pdf', '%PDF-1.4 fake'));

        $res = $this->get(['topics', (string)$tid, 'material', 'download']);
        $this->assertSame(200, $res->status);
        $this->assertSame('%PDF-1.4 fake', file_get_contents($res->filePath));
        $this->assertSame('발표.pdf', $res->fileName);
    }

    public function test_svg_와_html_은_강제로_내려받게_한다(): void
    {
        foreach (['x.svg', 'x.html'] as $name) {
            $tid = $this->claimed();
            $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [], $this->upload($name, '<svg onload=1>'));

            $res = $this->get(['topics', (string)$tid, 'material', 'download']);
            [$type, $disposition] = dti_disposition($res->fileName);
            $this->assertSame('application/octet-stream', $type, $name);
            $this->assertStringStartsWith('attachment', $disposition, $name);
        }
    }

    public function test_pdf_는_인라인으로_연다(): void
    {
        [$type, $disposition] = dti_disposition('발표.pdf');
        $this->assertSame('application/pdf', $type);
        $this->assertStringStartsWith('inline', $disposition);
        // 한글 파일명은 인코딩해서 내보낸다
        $this->assertStringContainsString("filename*=UTF-8''", $disposition);
    }

    public function test_상한을_넘는_업로드는_413(): void
    {
        $this->config = $this->makeConfig(['maxUploadMb' => 1]);

        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [],
            $this->upload('big.bin', str_repeat('x', 2 * 1024 * 1024)));

        $this->assertSame(413, $res->status);
        $this->assertSame('1MB 까지 올릴 수 있습니다', $res->data['detail']);
    }

    public function test_자료가_없으면_다운로드는_404(): void
    {
        $tid = $this->claimed();
        $this->assertSame(404, $this->get(['topics', (string)$tid, 'material', 'download'])->status);
    }

    public function test_미로그인은_내려받지_못한다(): void
    {
        $tid = $this->claimed();
        $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [], $this->upload('a.txt', 'hello'));
        $this->assertSame(401, $this->call('GET', ['topics', (string)$tid, 'material', 'download'], null)->status);
    }

    public function test_새로_올리면_앞의_파일은_지운다(): void
    {
        $tid = $this->claimed();
        $first = $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [], $this->upload('a.txt', 'one'))
            ->data['material_path'];
        $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [], $this->upload('b.txt', 'two'));

        $this->assertFileDoesNotExist($this->uploadDir . '/' . $first);
        $this->assertSame('two', $this->contentOf($tid));
    }

    public function test_링크로_바꾸면_올렸던_파일을_지운다(): void
    {
        $tid = $this->claimed();
        $stored = $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [], $this->upload('a.txt', 'one'))
            ->data['material_path'];
        $res = $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(), ['url' => 'https://example.com/a']);

        $this->assertSame('link', $res->data['material_kind']);
        $this->assertNull($res->data['material_path']);
        $this->assertFileDoesNotExist($this->uploadDir . '/' . $stored);
    }

    public function test_발표자가_자료를_뗀다(): void
    {
        $tid = $this->claimed();
        $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(), ['url' => 'https://example.com/a']);

        $res = $this->delete(['topics', (string)$tid, 'material'], $this->user());
        $this->assertSame(200, $res->status);
        $this->assertNull($res->data['material_kind']);
        $this->assertNull($res->data['material_url']);
    }

    public function test_제삼자는_떼지_못한다(): void
    {
        $tid = $this->claimed();
        $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(), ['url' => 'https://example.com/a']);
        $this->assertSame(403, $this->delete(['topics', (string)$tid, 'material'], $this->other())->status);
    }

    /* ---------- 스캔 원본 ---------- */

    public function test_스캔_원본을_올린다(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'scan', 'file'], $this->user(), [], $this->upload('스캔.pdf', '%PDF-1.4 scan'));

        $this->assertSame(200, $res->status);
        $this->assertSame('file', $res->data['scan_kind']);
        $this->assertSame('스캔.pdf', $res->data['scan_name']);
    }

    public function test_스캔도_링크로_받는다(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'scan', 'link'], $this->user(), ['url' => 'https://example.com/scan.pdf']);
        $this->assertSame('link', $res->data['scan_kind']);
        $this->assertSame('https://example.com/scan.pdf', $res->data['scan_url']);
    }

    public function test_스캔과_발표자료는_따로_간다(): void
    {
        $tid = $this->claimed();
        $this->post(['topics', (string)$tid, 'material', 'file'], $this->user(), [], $this->upload('발표.pdf', 'deck'));
        $res = $this->post(['topics', (string)$tid, 'scan', 'file'], $this->user(), [], $this->upload('스캔.pdf', 'scan'));

        $this->assertSame('발표.pdf', $res->data['material_name']);
        $this->assertSame('스캔.pdf', $res->data['scan_name']);
        $this->assertSame('deck', $this->contentOf($tid, 'material'));
        $this->assertSame('scan', $this->contentOf($tid, 'scan'));
    }

    public function test_스캔을_떼도_발표자료는_남는다(): void
    {
        $tid = $this->claimed();
        $this->post(['topics', (string)$tid, 'material', 'link'], $this->user(), ['url' => 'https://example.com/deck']);
        $this->post(['topics', (string)$tid, 'scan', 'link'], $this->user(), ['url' => 'https://example.com/scan']);

        $res = $this->delete(['topics', (string)$tid, 'scan'], $this->user());
        $this->assertNull($res->data['scan_kind']);
        $this->assertSame('link', $res->data['material_kind']);
    }

    public function test_없는_자료_칸은_404(): void
    {
        $tid = $this->claimed();
        $res = $this->post(['topics', (string)$tid, 'bogus', 'link'], $this->user(), ['url' => 'https://example.com/a']);
        $this->assertSame(404, $res->status);
    }

    public function test_경로를_벗어난_파일명은_404(): void
    {
        $tid = $this->claimed();
        $this->db->pdo()->exec("UPDATE dti_presentations SET material_kind='file', material_path='../../../etc/passwd'
                                WHERE topic_id = {$tid}");
        $this->assertSame(404, $this->get(['topics', (string)$tid, 'material', 'download'])->status);
    }
}

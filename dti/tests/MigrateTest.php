<?php

namespace Dti\Tests;

use Dti\Migration\MagazineImporter;
use Dti\Tests\Support\TestCase;
use PDO;

final class MigrateTest extends TestCase
{
    private string $sqlitePath;
    private string $sourceUploads;
    private PDO $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqlitePath = $this->uploadDir . '/magazine.db';
        $this->sourceUploads = $this->uploadDir . '/src-uploads';
        mkdir($this->sourceUploads);

        $this->source = new PDO('sqlite:' . $this->sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->seedSource();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sourceUploads . '/*') ?: [] as $file) unlink($file);
        @rmdir($this->sourceUploads);
        parent::tearDown();
    }

    /** 파이썬 쪽 스키마를 그대로 만든 작은 원본 */
    private function seedSource(): void
    {
        $this->source->exec("CREATE TABLE topics (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, field TEXT DEFAULT '',
            keywords TEXT DEFAULT '', magazine TEXT DEFAULT '', volume TEXT DEFAULT '',
            page TEXT DEFAULT '', year INTEGER, requirement TEXT DEFAULT 'recommended',
            team TEXT DEFAULT '', presenter TEXT DEFAULT '', presenter_email TEXT DEFAULT '',
            planned_date TEXT DEFAULT '', done_date TEXT DEFAULT '', note TEXT DEFAULT '',
            active INTEGER DEFAULT 1, archived INTEGER DEFAULT 0,
            material_kind TEXT, material_name TEXT, material_url TEXT, material_path TEXT,
            scan_kind TEXT, scan_name TEXT, scan_url TEXT, scan_path TEXT,
            created_by TEXT DEFAULT '', created_at TEXT DEFAULT '')");
        $this->source->exec("CREATE TABLE presentations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INTEGER NOT NULL UNIQUE,
            presenter TEXT DEFAULT '', presenter_email TEXT DEFAULT '',
            planned_date TEXT DEFAULT '', done_date TEXT DEFAULT '',
            material_kind TEXT, material_name TEXT, material_url TEXT, material_path TEXT,
            created_at TEXT DEFAULT '')");
        $this->source->exec("CREATE TABLE presentation_emotions (
            presentation_id INTEGER, email TEXT, kind TEXT, created_at TEXT,
            PRIMARY KEY (presentation_id, email, kind))");
        $this->source->exec("CREATE TABLE fields (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE)");
        $this->source->exec("CREATE TABLE topic_related (topic_id INTEGER, related_id INTEGER, score INTEGER,
            PRIMARY KEY (topic_id, related_id))");

        // 아티클 3건 — id 를 띄엄띄엄 둬서 보존 여부가 드러나게 한다
        $this->source->exec("INSERT INTO topics (id, title, field, keywords, created_at) VALUES
            (7, '하나', 'AX', '쌍둥이시험', '2026-01-01 09:00:00'),
            (12, '둘', 'AX', '쌍둥이시험', '2026-01-02 09:00:00'),
            (20, '셋', 'Trend', '', '2026-01-03 09:00:00')");
        $this->source->exec("UPDATE topics SET scan_kind='file', scan_name='스캔.pdf', scan_path='7_scan.pdf' WHERE id=7");

        $this->source->exec("INSERT INTO presentations (id, topic_id, presenter, presenter_email, done_date,
            material_kind, material_name, material_path, created_at) VALUES
            (3, 7, '유승인', 'siyu@bluesoft.co.kr', '2026-03-01', 'file', '발표.pdf', '7_deck.pdf', '2026-02-01 09:00:00'),
            (9, 12, '이한재', 'hjlee@bluesoft.co.kr', '', NULL, NULL, NULL, '2026-02-02 09:00:00')");

        $this->source->exec("INSERT INTO presentation_emotions VALUES
            (3, 'jian@bluesoft.co.kr', 'like', '2026-03-02 10:00:00'),
            (3, 'hjlee@bluesoft.co.kr', 'apply', '2026-03-02 11:00:00')");

        $this->source->exec("INSERT INTO fields (name) VALUES ('UI/UX'), ('AX'), ('보안')");
        $this->source->exec("INSERT INTO topic_related VALUES (7, 12, 91)");

        file_put_contents($this->sourceUploads . '/7_deck.pdf', '%PDF deck');
        // 7_scan.pdf 는 일부러 만들지 않는다 — 행은 있고 원본이 없는 경우
    }

    private function importer(): MagazineImporter
    {
        return new MagazineImporter($this->source, $this->db, $this->config, $this->sourceUploads);
    }

    public function test_원본_건수를_읽는다(): void
    {
        $this->assertSame(
            ['topics' => 3, 'presentations' => 2, 'presentation_emotions' => 2, 'fields' => 3],
            $this->importer()->sourceCounts());
    }

    public function test_아티클_id_를_그대로_옮긴다(): void
    {
        $this->importer()->run();

        $ids = $this->db->pdo()->query("SELECT id FROM dti_topics ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([7, 12, 20], $ids);
    }

    public function test_발표와_반응의_연결이_유지된다(): void
    {
        $this->importer()->run();
        $pdo = $this->db->pdo();

        $row = $pdo->query("SELECT id, topic_id, presenter_email FROM dti_presentations WHERE id = 3")->fetch();
        $this->assertSame(7, $row['topic_id']);
        $this->assertSame('siyu@bluesoft.co.kr', $row['presenter_email']);

        $kinds = $pdo->query("SELECT kind FROM dti_emotions WHERE presentation_id = 3 ORDER BY kind")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['apply', 'like'], $kinds);
    }

    public function test_자료_컬럼의_null_이_그대로_넘어온다(): void
    {
        $this->importer()->run();

        $row = $this->db->pdo()->query("SELECT material_kind, material_name FROM dti_presentations WHERE id = 9")->fetch();
        $this->assertNull($row['material_kind']);
        $this->assertNull($row['material_name']);
    }

    public function test_분야는_시드와_겹치지_않게_넣는다(): void
    {
        $report = $this->importer()->run();

        // 원본 3건 중 UI/UX·AX 는 시드에 이미 있다 — 보안만 늘어난다
        $this->assertSame(1, $report['fields']);
        $names = $this->db->pdo()->query("SELECT name FROM dti_fields ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['UI/UX', 'Marketing', 'Trend', 'AX', 'Etc', '보안'], $names);
    }

    public function test_업로드_파일을_옮기고_없는_것은_보고한다(): void
    {
        $report = $this->importer()->run();

        $this->assertSame(1, $report['files']);
        $this->assertFileExists($this->config->uploadDir . '/7_deck.pdf');
        $this->assertCount(1, $report['missing']);
        $this->assertStringContainsString('7_scan.pdf', $report['missing'][0]);
    }

    public function test_연관_점수는_옮기지_않고_다시_계산한다(): void
    {
        $report = $this->importer()->run();

        // 원본에는 7→12 한 쌍뿐이지만, 다시 계산하면 양쪽 방향이 나온다
        $this->assertSame(2, $report['related']);
        $pairs = $this->db->pdo()->query("SELECT topic_id, related_id FROM dti_related ORDER BY topic_id")
            ->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([[7, 12], [12, 7]], $pairs);
    }

    public function test_dry_run_은_아무_것도_쓰지_않는다(): void
    {
        $report = $this->importer()->run(dryRun: true);

        $this->assertSame(3, $report['topics']);
        $this->assertSame(0, $this->importer()->targetTopicCount());
        $this->assertFileDoesNotExist($this->config->uploadDir . '/7_deck.pdf');
    }

    public function test_force_는_기존_데이터를_비우고_다시_넣는다(): void
    {
        $this->importer()->run();
        $this->importer()->run(force: true);

        $this->assertSame(3, $this->importer()->targetTopicCount());
    }

    public function test_이관한_데이터가_API_로_그대로_보인다(): void
    {
        $this->importer()->run();

        $rows = array_column($this->get(['topics'], $this->admin())->data, null, 'id');
        $this->assertCount(3, $rows);
        $this->assertSame('발표완료', $rows[7]['status']);
        $this->assertSame('유승인', $rows[7]['presenter']);
        $this->assertSame('발표.pdf', $rows[7]['material_name']);
        $this->assertSame('스캔.pdf', $rows[7]['scan_name']);
        $this->assertSame(['like' => 1, 'apply' => 1, 'easy' => 0, 'new' => 0], $rows[7]['emotions']);
        $this->assertSame('발표예정', $rows[12]['status']);
        $this->assertSame('미지정', $rows[20]['status']);
    }
}

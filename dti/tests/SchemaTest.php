<?php

namespace Dti\Tests;

use Dti\Tests\Support\TestCase;

final class SchemaTest extends TestCase
{
    private function columns(string $table): array
    {
        $rows = $this->pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
        $out = [];
        foreach ($rows as $row) $out[$row['Field']] = $row;
        return $out;
    }

    public function test_다섯_테이블이_만들어진다(): void
    {
        foreach (['dti_topics', 'dti_presentations', 'dti_emotions', 'dti_fields', 'dti_related'] as $table) {
            $this->assertNotEmpty($this->columns($table), "{$table} 이 없다");
        }
    }

    public function test_자료_슬롯은_NULL_을_허용한다(): void
    {
        $topics = $this->columns('dti_topics');
        foreach (['material_kind', 'material_name', 'material_url', 'material_path',
                  'scan_kind', 'scan_name', 'scan_url', 'scan_path'] as $column) {
            $this->assertSame('YES', $topics[$column]['Null'], "{$column} 이 NOT NULL 이다");
        }
    }

    public function test_노출과_보관은_기본값을_갖는다(): void
    {
        $topics = $this->columns('dti_topics');
        $this->assertSame('1', $topics['active']['Default']);
        $this->assertSame('0', $topics['archived']['Default']);
    }

    public function test_발표는_아티클당_하나다(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT INTO dti_topics (id, title) VALUES (1, '주제')");
        $pdo->exec("INSERT INTO dti_presentations (topic_id) VALUES (1)");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO dti_presentations (topic_id) VALUES (1)");
    }

    public function test_migrate_는_여러_번_불러도_안전하다(): void
    {
        dti_migrate($this->pdo);
        dti_migrate($this->pdo);
        $this->assertNotEmpty($this->columns('dti_topics'));
    }

    public function test_없던_컬럼은_add_column_if_missing_으로_붙는다(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("ALTER TABLE dti_topics DROP COLUMN note");
        $this->assertArrayNotHasKey('note', $this->columns('dti_topics'));

        dti_migrate($this->pdo);
        $this->assertArrayHasKey('note', $this->columns('dti_topics'));
    }

    public function test_분야는_기본값으로_시드된다(): void
    {
        dti_migrate($this->pdo);
        $names = $this->pdo->query("SELECT name FROM dti_fields ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['UI/UX', 'Marketing', 'Trend', 'AX', 'Etc'], $names);
    }
}

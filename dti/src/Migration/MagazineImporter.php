<?php

namespace Dti\Migration;

use PDO;

/**
 * magazine(FastAPI/SQLite) 데이터를 dti(PHP/MySQL) 로 옮긴다. 일회성이다 — 전환이 끝나면
 * 이 클래스와 tools/migrate_from_magazine.php 를 지운다.
 *
 * **id 를 그대로 유지한다.** 발표가 아티클을, 반응이 발표를 id 로 가리키고 있어 번호를
 * 다시 매기면 연결이 끊긴다.
 *
 * 연관 점수(topic_related)는 옮기지 않고 다시 계산한다. 같은 입력이면 같은 점수가 나오므로
 * 옮길 이유가 없고, 옮기면 원본이 낡았을 때 그대로 굳는다.
 */
final class MagazineImporter
{
    public const TABLES = ['dti_related', 'dti_emotions', 'dti_presentations', 'dti_topics'];

    public function __construct(
        private readonly PDO $source,
        private readonly PDO $target,
        private readonly array $config,
        private readonly string $sourceUploadDir,
    ) {}

    /** 원본 건수. 옮기기 전에 무엇이 얼마나 있는지 보여 준다. */
    public function sourceCounts(): array
    {
        $out = [];
        foreach (['topics', 'presentations', 'presentation_emotions', 'fields'] as $table) {
            $out[$table] = (int)$this->source->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
        return $out;
    }

    public function targetTopicCount(): int
    {
        return (int)$this->target->query("SELECT COUNT(*) FROM dti_topics")->fetchColumn();
    }

    /**
     * @return array{topics: int, presentations: int, emotions: int, fields: int,
     *               files: int, missing: string[], related: int}
     */
    public function run(bool $dryRun = false, bool $force = false): array
    {
        $report = ['topics' => 0, 'presentations' => 0, 'emotions' => 0, 'fields' => 0,
                   'files' => 0, 'missing' => [], 'related' => 0];

        $pdo = $this->target;
        $pdo->beginTransaction();

        if ($force) $this->clear();

        $report['topics'] = $this->copyTopics();
        $report['presentations'] = $this->copyPresentations();
        $report['emotions'] = $this->copyEmotions();
        $report['fields'] = $this->copyFields();
        [$report['files'], $report['missing']] = $this->copyUploads($dryRun);

        if ($dryRun) {
            $pdo->rollBack();
            return $report;
        }

        $pdo->commit();
        $report['related'] = dti_related_rebuild($pdo);

        return $report;
    }

    public function clear(): void
    {
        $pdo = $this->target;
        foreach (self::TABLES as $table) {
            $pdo->exec("DELETE FROM `{$table}`");
        }
    }

    private function copyTopics(): int
    {
        return $this->copyRows('topics', 'dti_topics', dti_topic_columns());
    }

    private function copyPresentations(): int
    {
        return $this->copyRows('presentations', 'dti_presentations', dti_presentation_columns());
    }

    private function copyEmotions(): int
    {
        return $this->copyRows('presentation_emotions', 'dti_emotions',
            ['presentation_id', 'email', 'kind', 'created_at']);
    }

    /** 분야는 시드와 겹친다. 통째로 넣지 않고 이름으로 맞춰 없는 것만 넣는다. */
    private function copyFields(): int
    {
        $pdo = $this->target;
        $have = $pdo->query("SELECT name FROM dti_fields")->fetchAll(PDO::FETCH_COLUMN);
        $insert = $pdo->prepare("INSERT INTO dti_fields (name) VALUES (?)");

        $added = 0;
        foreach ($this->source->query("SELECT name FROM fields ORDER BY id") as $row) {
            if (in_array($row['name'], $have, true)) continue;
            $insert->execute([$row['name']]);
            $added++;
        }
        return $added;
    }

    /** @return array{0: int, 1: string[]} 옮긴 파일 수와, 행은 있는데 원본이 없는 것들 */
    private function copyUploads(bool $dryRun): array
    {
        $wanted = [];
        foreach ($this->source->query("SELECT id, material_path, scan_path FROM topics") as $row) {
            foreach (['material_path' => '자료', 'scan_path' => '스캔'] as $column => $label) {
                if ($row[$column]) $wanted[$row[$column]] = "아티클 {$row['id']} {$label}";
            }
        }
        foreach ($this->source->query("SELECT id, topic_id, material_path FROM presentations") as $row) {
            if ($row['material_path']) $wanted[$row['material_path']] = "발표 {$row['id']} 자료";
        }

        $copied = 0;
        $missing = [];
        foreach ($wanted as $stored => $where) {
            $from = $this->sourceUploadDir . '/' . basename((string)$stored);
            if (!is_file($from)) {
                $missing[] = "{$where}: {$stored}";
                continue;
            }
            if (!$dryRun) {
                if (!is_dir($this->config['upload_dir'])) mkdir($this->config['upload_dir'], 0777, true);
                copy($from, $this->config['upload_dir'] . '/' . basename((string)$stored));
            }
            $copied++;
        }
        return [$copied, $missing];
    }

    /** @param string[] $columns 원본에 없는 컬럼은 건너뛴다 */
    private function copyRows(string $from, string $to, array $columns): int
    {
        $available = array_column($this->source->query("PRAGMA table_info(`{$from}`)")->fetchAll(), 'name');
        $columns = array_values(array_intersect($columns, $available));

        $sql = "INSERT INTO `{$to}` (`" . implode('`, `', $columns) . '`) VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $insert = $this->target->prepare($sql);

        $count = 0;
        foreach ($this->source->query("SELECT * FROM `{$from}`") as $row) {
            $insert->execute(array_map(static fn (string $column) => $row[$column], $columns));
            $count++;
        }
        return $count;
    }
}

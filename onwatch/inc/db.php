<?php
/**
 * DB — 포털 PDO(portal_db) 를 그대로 쓴다. 표 접두사는 ow_.
 *
 * 스키마는 sql/NNN_*.sql 이 원본이다. 처음 붙을 때 아직 적용하지 않은 파일을
 * 번호 순으로 적용하고 ow_schema 에 남긴다. 배포 때 손으로 SQL 을 돌리는 단계를
 * 없애려는 것이다(MoodleUp? 이 화면에서 CREATE TABLE IF NOT EXISTS 를 하는 것과
 * 같은 생각이지만, DDL 을 PHP 와 Python 두 곳에 두지 않으려고 파일로 뺐다).
 * 배치(worker/)는 표를 만들지 않는다 — 없으면 "화면을 한 번 열라" 고 알리고 멈춘다.
 */

function ow_db(): PDO
{
    static $ready = false;
    $pdo = portal_db();
    if (!$ready) {
        $ready = true;
        ow_migrate($pdo);
    }
    return $pdo;
}

/** @return string[] 이번에 적용한 파일 이름 */
function ow_migrate(PDO $pdo): array
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ow_schema` (
            `file`       VARCHAR(100) NOT NULL COMMENT 'sql/ 아래 파일 이름',
            `applied_at` DATETIME     NOT NULL,
            PRIMARY KEY (`file`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='OnWatch 스키마 적용 기록'
    ");
    $done = array_flip($pdo->query("SELECT `file` FROM ow_schema")->fetchAll(PDO::FETCH_COLUMN));
    $files = glob(OW_ROOT . '/sql/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($files);
    $applied = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (isset($done[$name])) continue;
        foreach (ow_sql_statements((string)file_get_contents($path)) as $sql) {
            $pdo->exec($sql);
        }
        $pdo->prepare("INSERT INTO ow_schema (`file`, applied_at) VALUES (?, NOW())")->execute([$name]);
        $applied[] = $name;
    }
    return $applied;
}

/** '-- ' 주석 줄을 빼고 ';' 로 끝나는 줄에서 자른다. */
function ow_sql_statements(string $sql): array
{
    $out = [];
    $buf = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $buf .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            $stmt = trim(preg_replace('/;\s*$/', '', trim($buf)));
            if ($stmt !== '') $out[] = $stmt;
            $buf = '';
        }
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

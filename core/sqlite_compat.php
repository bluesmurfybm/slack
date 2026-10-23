<?php
/**
 * 로컬 시험용 MySQL → SQLite 번역 연결.
 *
 *  포털과 모듈들은 MySQL 문법으로 짜여 있다(ENGINE=InnoDB, ON DUPLICATE KEY UPDATE, NOW(),
 *  information_schema …). 운영 서버는 그대로 MySQL 을 쓰고, MySQL 이 없는 PC 에서 포털을 통째로
 *  띄워 볼 때만 이 파일이 끼어든다. 모듈 코드는 고치지 않고, DB 를 여는 일곱 곳이
 *  `new PDO(...)` 대신 db_connect(...) 를 부르게만 바꿨다.
 *
 *  켜지는 조건 (둘 다):
 *   1) 루트 config.php 의 db.driver 가 'sqlite' 다. 운영 config.php 에는 이 키가 없다.
 *   2) PHP 내장 서버(php -S)나 명령줄로 돌고 있다 — php-fpm(운영)에서는 절대 켜지지 않는다.
 *
 *  DB 이름마다 파일 하나: var/localdb/<이름>.sqlite (slack_db, slackai_db …).
 *
 *  번역은 "이 저장소가 실제로 쓰는 문법"만큼만 한다. 처음 보는 MySQL 문법이 나오면 SQLite 가
 *  오류를 내고, 그 문장은 error_log 에 [sqlite_compat] 로 남는다 — 그걸 보고 여기 규칙을 늘린다.
 *  한계: 트리거·저장 프로시저 없음, ON UPDATE CURRENT_TIMESTAMP 는 무시, 문자열의 \t \n 같은
 *  백슬래시 이스케이프는 글자 그대로 들어간다.
 */

/** 로컬 SQLite 모드인가 */
function db_local_sqlite(): bool {
    static $on = null;
    if ($on !== null) return $on;
    $file = dirname(__DIR__) . '/config.php';
    $cfg = is_file($file) ? (require $file) : [];
    return $on = (($cfg['db']['driver'] ?? 'mysql') === 'sqlite') && in_array(PHP_SAPI, ['cli-server', 'cli'], true);
}

/**
 * new PDO(...) 대신 부른다. 운영에서는 new PDO 와 똑같다.
 * $dbName 은 DSN 에 dbname 이 없을 때(나중에 USE 하는 곳) 넘긴다.
 */
function db_connect(string $dsn, ?string $user, ?string $pass, array $opt = [], string $dbName = ''): PDO {
    if (!db_local_sqlite()) return new PDO($dsn, $user, $pass, $opt);
    if ($dbName === '' && preg_match('/dbname=([^;]+)/', $dsn, $m)) $dbName = $m[1];
    $dbName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $dbName ?: 'main');
    $dir = dirname(__DIR__) . '/var/localdb';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    unset($opt[PDO::ATTR_EMULATE_PREPARES]);   // SQLite 드라이버는 이 옵션을 모른다
    return new SqliteCompatPdo('sqlite:' . $dir . '/' . $dbName . '.sqlite', null, null, $opt);
}

/** MySQL 과 같은 SQLSTATE 를 달고 던지는 예외 — add_column_if_missing() 이 42S21 을 보고 넘어간다 */
class SqliteCompatException extends PDOException {
    public function __construct(string $message, string $state, int $driverCode = 0) {
        parent::__construct($message);
        $this->code = $state;
        $this->errorInfo = [$state, $driverCode, $message];
    }
}

class SqliteCompatPdo extends PDO {
    public function __construct(string $dsn, ?string $u = null, ?string $p = null, ?array $opt = null) {
        parent::__construct($dsn, $u, $p, $opt ?? []);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        parent::exec('PRAGMA journal_mode = WAL');
        parent::exec('PRAGMA busy_timeout = 8000');
        parent::exec('PRAGMA foreign_keys = ON');
        sqlc_register_functions($this);
        sqlc_create_views($this);
    }

    public function exec(string $statement): int|false {
        $sql = sqlc_translate($this, $statement);
        if ($sql === null) return 0;
        try {
            return parent::exec($sql);
        } catch (PDOException $e) {
            sqlc_log($statement, $sql, $e);
            throw $e;
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $sql = sqlc_translate($this, $query) ?? 'SELECT 1 WHERE 0';
        try {
            return $fetchMode === null ? parent::query($sql) : parent::query($sql, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) {
            sqlc_log($query, $sql, $e);
            throw $e;
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $sql = sqlc_translate($this, $query) ?? 'SELECT 1 WHERE 0';
        try {
            return parent::prepare($sql, $options);
        } catch (PDOException $e) {
            sqlc_log($query, $sql, $e);
            throw $e;
        }
    }

    /** 번역 없이 바로 — 번역기 안에서 쓴다 */
    public function rawQuery(string $sql): PDOStatement {
        return parent::query($sql);
    }
}

function sqlc_log(string $orig, string $sql, Throwable $e): void {
    $one = fn($s) => preg_replace('/\s+/', ' ', trim($s));
    error_log('[sqlite_compat] ' . $e->getMessage() . "\n  원문: " . mb_substr($one($orig), 0, 600) . "\n  번역: " . mb_substr($one($sql), 0, 600));
}

/* =====================================================================
 * 번역
 * ===================================================================== */

/** null 이면 "할 일 없음"(CREATE DATABASE, USE, SET time_zone …) */
function sqlc_translate(SqliteCompatPdo $pdo, string $sql): ?string {
    $s = trim($sql);
    $s = rtrim($s, "; \t\r\n");
    if ($s === '') return null;

    if (preg_match('/^(CREATE\s+DATABASE|DROP\s+DATABASE|ALTER\s+DATABASE|USE\s|SET\s+(time_zone|NAMES|SESSION|GLOBAL|FOREIGN_KEY_CHECKS|sql_mode|CHARACTER)|LOCK\s+TABLES|UNLOCK\s+TABLES|OPTIMIZE\s|ANALYZE\s+TABLE)/i', $s)) {
        return null;
    }
    if (preg_match('/^CREATE\s+(TEMPORARY\s+)?TABLE/i', $s)) return sqlc_create_table($s);
    if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $s, $m)) return sqlc_alter_table($pdo, $m[1], $m[2]);
    if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?\s*(\(.*\))$/is', $s, $m)) {
        return "CREATE {$m[1]}INDEX IF NOT EXISTS `{$m[3]}__{$m[2]}` ON `{$m[3]}` " . sqlc_strip_index_lengths($m[4]);
    }
    if (preg_match('/^TRUNCATE\s+(TABLE\s+)?`?(\w+)`?$/i', $s, $m)) return "DELETE FROM `{$m[2]}`";
    if (preg_match('/^SHOW\s+(FULL\s+)?TABLES(\s+LIKE\s+(.+))?$/i', $s, $m)) {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" . (isset($m[3]) ? " AND name LIKE {$m[3]}" : '') . ' ORDER BY name';
    }
    if (preg_match('/^SHOW\s+(FULL\s+)?COLUMNS\s+FROM\s+`?(\w+)`?(\s+LIKE\s+(.+))?$/i', $s, $m)) {
        return "SELECT name AS Field, type AS Type, CASE WHEN \"notnull\" THEN 'NO' ELSE 'YES' END AS \"Null\",
                       CASE WHEN pk THEN 'PRI' ELSE '' END AS \"Key\", dflt_value AS \"Default\", '' AS Extra
                FROM pragma_table_info('{$m[2]}')" . (isset($m[4]) ? " WHERE name LIKE {$m[4]}" : '');
    }
    if (preg_match('/^SHOW\s+(INDEX|INDEXES|KEYS)\s+FROM\s+`?(\w+)`?(.*)$/is', $s, $m)) {
        return "SELECT * FROM (SELECT TABLE_NAME AS \"Table\", INDEX_NAME AS Key_name, COLUMN_NAME AS Column_name, NON_UNIQUE AS Non_unique
                FROM information_schema_STATISTICS WHERE TABLE_NAME='{$m[2]}') " . sqlc_where_only($m[3]);
    }

    return sqlc_expr($s);
}

/** SHOW INDEX FROM t WHERE Key_name='x' 의 꼬리 */
function sqlc_where_only(string $tail): string {
    $tail = trim($tail);
    return preg_match('/^WHERE\s/i', $tail) ? $tail : '';
}

/** 일반 문장(SELECT/INSERT/UPDATE/DELETE)의 함수·문법 바꾸기. 문자열 리터럴 안은 건드리지 않는다. */
function sqlc_expr(string $s): string {
    return sqlc_outside_strings($s, function (string $c) {
        $c = preg_replace('/`?\binformation_schema`?\s*\.\s*`?(TABLES|COLUMNS|STATISTICS)\b`?/i', 'information_schema_$1', $c);
        $c = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $c);
        $c = preg_replace('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', '', $c);
        $c = preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', 'ON CONFLICT DO UPDATE SET', $c);
        // "NOW() - INTERVAL 6 DAY" → DATE_ADD(NOW(), -6, 'DAY')
        $c = preg_replace_callback('/(NOW\(\)|CURDATE\(\)|CURRENT_TIMESTAMP|CURRENT_DATE)\s*([+-])\s*INTERVAL\s+(\?|-?\d+|[\w.`]+)\s+(MICROSECOND|SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR)\b/i',
            fn($m) => "DATE_ADD({$m[1]}, " . ($m[2] === '-' ? "-({$m[3]})" : $m[3]) . ", '" . strtoupper($m[4]) . "')", $c);
        // DATE_SUB(x, INTERVAL n DAY) → DATE_SUB(x, n, 'DAY') — PHP 로 등록한 함수가 받는다
        $c = preg_replace('/\bINTERVAL\s+(\?|-?\d+|[\w.`]+)\s+(MICROSECOND|SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR)\b/i', "\$1, '\$2'", $c);
        $c = preg_replace('/\bTIMESTAMPDIFF\s*\(\s*(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\s*,/i', "TIMESTAMPDIFF('\$1',", $c);
        $c = preg_replace('/(?<![\w.])IF\s*\(/i', 'iif(', $c);
        $c = preg_replace('/\bGREATEST\s*\(/i', 'max(', $c);
        $c = preg_replace('/\bLEAST\s*\(/i', 'min(', $c);
        $c = preg_replace('/\bCAST\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\s+AS\s+(UNSIGNED|SIGNED)(\s+INTEGER)?\s*\)/i', 'CAST($1 AS INTEGER)', $c);
        $c = preg_replace('/\bAS\s+(CHAR|VARCHAR)(\(\d+\))?\s*\)/i', 'AS TEXT)', $c);
        $c = preg_replace('/\bAS\s+DECIMAL(\(\d+\s*,\s*\d+\))?\s*\)/i', 'AS REAL)', $c);
        $c = preg_replace('/\bSQL_CALC_FOUND_ROWS\b/i', '', $c);
        return $c;
    }, function (string $c) {
        // upsert 뒷부분의 VALUES(col) 만 excluded.col 로. 앞의 INSERT … VALUES (…) 는 그대로 둔다.
        $at = stripos($c, 'ON CONFLICT DO UPDATE SET');
        if ($at !== false) {
            $c = substr($c, 0, $at) . preg_replace('/\bVALUES\s*\(\s*`?(\w+)`?\s*\)/i', 'excluded.`$1`', substr($c, $at));
            // INSERT … SELECT … ON CONFLICT 는 SQLite 가 SELECT 의 JOIN 조건으로 잘못 읽는다 — WHERE true 로 끊어 준다
            $head = substr($c, 0, $at);
            if (preg_match('/^\s*INSERT\b.*\bSELECT\b/is', $head) && !preg_match('/\bWHERE\b/i', $head)) {
                $c = rtrim($head) . ' WHERE true ' . substr($c, $at);
            }
        }
        // GROUP_CONCAT(DISTINCT x ORDER BY y SEPARATOR ', ') → group_concat(x, ', ' ORDER BY y)
        // SEPARATOR 다음이 문자열이라 문자열 바깥/안을 함께 봐야 해서 따로 돈다.
        return preg_replace_callback('/\bGROUP_CONCAT\s*\((\s*DISTINCT\s+)?(.*?)(\s+ORDER\s+BY\s+(.*?))?\s+SEPARATOR\s+(\'(?:[^\'\\\\]|\\\\.)*\'|"[^"]*")\s*\)/is',
            fn($m) => 'group_concat(' . $m[2] . ', ' . sqlc_unescape_literal($m[5]) . (!empty($m[4]) ? ' ORDER BY ' . $m[4] : '') . ')', $c);
    });
}

/** MySQL 문자열 리터럴의 \t \n → 실제 글자 */
function sqlc_unescape_literal(string $lit): string {
    $q = $lit[0];
    $body = substr($lit, 1, -1);
    $body = strtr($body, ['\\t' => "\t", '\\n' => "\n", '\\\\' => '\\', "\\'" => "''"]);
    return "'" . ($q === '"' ? str_replace("'", "''", $body) : $body) . "'";
}

/**
 * 문자열 리터럴('…', "…") 바깥 부분에만 $fn 을 적용한다. $whole 은 그 뒤 전체 문장에 적용.
 * "…" 는 MySQL 에서 문자열이지만, 식별자가 아니라는 보장이 없어 그대로 둔다(SQLite 도 문자열로 받아 준다).
 */
function sqlc_outside_strings(string $s, callable $fn, ?callable $whole = null): string {
    $out = '';
    $buf = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $ch = $s[$i];
        if ($ch === "'" || $ch === '"') {
            $out .= $fn($buf);
            $buf = '';
            $j = $i + 1;
            while ($j < $n) {
                if ($s[$j] === '\\') { $j += 2; continue; }
                if ($s[$j] === $ch) {
                    if ($j + 1 < $n && $s[$j + 1] === $ch) { $j += 2; continue; }
                    break;
                }
                $j++;
            }
            $out .= substr($s, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        $buf .= $ch;
    }
    $out .= $fn($buf);
    return $whole ? $whole($out) : $out;
}

/** 괄호 깊이 0 의 쉼표로 자른다(문자열 안 쉼표도 무시) */
function sqlc_split_top(string $s): array {
    $parts = [];
    $depth = 0;
    $cur = '';
    $q = null;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $ch = $s[$i];
        if ($q) {
            $cur .= $ch;
            if ($ch === '\\' && $i + 1 < $n) { $cur .= $s[++$i]; continue; }
            if ($ch === $q) $q = null;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $cur .= $ch; continue; }
        if ($ch === '(') $depth++;
        if ($ch === ')') $depth--;
        if ($ch === ',' && $depth === 0) { $parts[] = trim($cur); $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $parts[] = trim($cur);
    return $parts;
}

/** (`col`(191), `b`) → (`col`, `b`) — SQLite 는 인덱스 접두 길이를 모른다 */
function sqlc_strip_index_lengths(string $cols): string {
    return preg_replace('/(`?\w+`?)\s*\(\d+\)/', '$1', $cols);
}

/** 컬럼 정의 한 줄: 타입·속성을 SQLite 가 받는 모양으로 */
function sqlc_column_def(string $def, ?bool &$autoInc = false, bool $forAlter = false): string {
    $autoInc = (bool)preg_match('/\bAUTO_INCREMENT\b/i', $def);
    // ENUM('a','b') 은 안에 문자열이 있어 문자열 바깥 처리보다 먼저 통째로 바꾼다
    $def = preg_replace('/\b(ENUM|SET)\s*\((?:\'(?:[^\'\\\\]|\\\\.)*\'|"[^"]*"|[^()\'"])*\)/i', 'TEXT', $def);
    $d = sqlc_outside_strings($def, function ($c) {
        $c = preg_replace('/\b(UNSIGNED|ZEROFILL|AUTO_INCREMENT|FIRST)\b/i', '', $c);
        $c = preg_replace('/\bAFTER\s+`?\w+`?/i', '', $c);
        $c = preg_replace('/\b(CHARACTER\s+SET|CHARSET)\s+\w+/i', '', $c);
        $c = preg_replace('/\bCOLLATE\s+\w+/i', '', $c);
        $c = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP(\(\d*\))?/i', '', $c);
        $c = preg_replace('/\bDEFAULT\s+CURRENT_TIMESTAMP(\(\d*\))?/i', "DEFAULT (datetime('now','localtime'))", $c);
        return $c;
    });
    // COMMENT '...' 는 문자열을 품고 있어 바깥에서 지운다
    $d = preg_replace("/\\s+COMMENT\\s+('(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\")/i", '', $d);
    // MySQL _ci 처럼 문자열 비교를 대소문자 무시로
    if (preg_match('/^`?\w+`?\s+(VARCHAR|CHAR|TEXT|TINYTEXT|MEDIUMTEXT|LONGTEXT)\b/i', trim($d))) {
        $d = preg_replace('/^(`?\w+`?\s+\w+(\(\d+\))?)/i', '$1 COLLATE NOCASE', trim($d));
    }
    if ($forAlter) {
        // SQLite ADD COLUMN: NOT NULL 이면 기본값이 있어야 하고, 시각 같은 식은 기본값이 될 수 없다
        if (preg_match("/DEFAULT \\(datetime\\('now','localtime'\\)\\)/", $d)) {
            $d = str_replace("DEFAULT (datetime('now','localtime'))", "DEFAULT ''", $d);
        }
        if (preg_match('/\bNOT\s+NULL\b/i', $d) && !preg_match('/\bDEFAULT\b/i', $d)) {
            $d .= preg_match('/^`?\w+`?\s+\w*(INT|DECIMAL|FLOAT|DOUBLE|BIT|BOOL)/i', trim($d)) ? ' DEFAULT 0' : " DEFAULT ''";
        }
    }
    return preg_replace('/\s+/', ' ', trim($d));
}

function sqlc_create_table(string $s): string {
    if (!preg_match('/^CREATE\s+(TEMPORARY\s+)?TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/is', $s, $m)) {
        return $s;   // CREATE TABLE … LIKE / AS SELECT 등은 그대로 맡긴다
    }
    [$head, $tmp, $ine, $table] = $m;
    // 컬럼 목록의 닫는 괄호를 짝 맞춰 찾는다. 표 COMMENT='…(…)' 안의 괄호에 속지 않게 문자열은 건너뛴다.
    $start = strlen($head);
    $depth = 1;
    $q = null;
    for ($i = $start, $n = strlen($s); $i < $n && $depth > 0; $i++) {
        $c = $s[$i];
        if ($q) { if ($c === '\\') { $i++; } elseif ($c === $q) { $q = null; } continue; }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; continue; }
        if ($c === '(') $depth++;
        if ($c === ')') $depth--;
    }
    $body = substr($s, $start, $i - 1 - $start);
    $cols = [];
    $after = [];
    $pk = null;
    $autoCol = null;
    foreach (sqlc_split_top($body) as $part) {
        if (preg_match('/^PRIMARY\s+KEY\s*(\(.*\))/is', $part, $k)) { $pk = sqlc_strip_index_lengths($k[1]); continue; }
        if (preg_match('/^(UNIQUE\s+)?(KEY|INDEX)\s+`?(\w+)`?\s*(\(.*\))/is', $part, $k)
            || preg_match('/^UNIQUE\s*()()`?(\w+)?`?\s*(\(.*\))/is', $part, $k)) {
            $unique = stripos($part, 'UNIQUE') === 0;
            $name = $k[3] !== '' ? $k[3] : 'u' . substr(md5($part), 0, 8);
            $after[] = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS `{$table}__{$name}` ON `{$table}` " . sqlc_strip_index_lengths($k[4]);
            continue;
        }
        if (preg_match('/^(FULLTEXT|SPATIAL)\b/i', $part)) continue;
        if (preg_match('/^(CONSTRAINT\s+`?\w+`?\s+)?FOREIGN\s+KEY\b/i', $part)) { $cols[] = preg_replace('/^CONSTRAINT\s+`?\w+`?\s+/i', '', $part); continue; }
        if (preg_match('/^CONSTRAINT\b|^CHECK\b/i', $part)) continue;
        $auto = false;
        $def = sqlc_column_def($part, $auto);
        if ($auto) $autoCol = preg_replace('/^`?(\w+)`?.*/s', '$1', $def);
        $cols[] = $def;
    }
    if ($autoCol !== null) {
        // AUTO_INCREMENT 컬럼은 INTEGER PRIMARY KEY 여야 SQLite 가 번호를 매긴다
        foreach ($cols as &$c) {
            if (preg_match('/^`?' . preg_quote($autoCol, '/') . '`?\s/', $c)) {
                $c = "`$autoCol` INTEGER PRIMARY KEY AUTOINCREMENT";
            }
        }
        unset($c);
        $pkCols = $pk ? array_map(fn($x) => trim($x, " `"), explode(',', trim($pk, '()'))) : [];
        if ($pk && $pkCols !== [$autoCol]) $after[] = "CREATE UNIQUE INDEX IF NOT EXISTS `{$table}__pk` ON `{$table}` $pk";
    } elseif ($pk) {
        $cols[] = "PRIMARY KEY $pk";
    }
    $sql = 'CREATE ' . ($tmp ? 'TEMPORARY ' : '') . "TABLE IF NOT EXISTS `$table` (\n  " . implode(",\n  ", $cols) . "\n)";
    return implode(";\n", array_merge([$sql], $after));
}

function sqlc_alter_table(SqliteCompatPdo $pdo, string $table, string $specs): ?string {
    $out = [];
    $existing = array_map('strtolower', array_column($pdo->rawQuery("SELECT name FROM pragma_table_info('$table')")->fetchAll(PDO::FETCH_ASSOC), 'name'));
    foreach (sqlc_split_top($specs) as $spec) {
        if (preg_match('/^ADD\s+(UNIQUE\s+)?(KEY|INDEX)\s+`?(\w+)`?\s*(\(.*\))/is', $spec, $m)
            || preg_match('/^ADD\s+(UNIQUE)\s*()`?(\w+)`?\s*(\(.*\))/is', $spec, $m)) {
            $out[] = 'CREATE ' . ($m[1] ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS `{$table}__{$m[3]}` ON `$table` " . sqlc_strip_index_lengths($m[4]);
        } elseif (preg_match('/^ADD\s+(CONSTRAINT|FOREIGN\s+KEY|PRIMARY\s+KEY|FULLTEXT|SPATIAL|CHECK)\b/i', $spec)) {
            continue;
        } elseif (preg_match('/^ADD\s+(COLUMN\s+)?(\(.*\))$/is', $spec, $m)) {
            foreach (sqlc_split_top(substr(trim($m[2]), 1, -1)) as $c) $out[] = sqlc_add_column($table, $c, $existing);
        } elseif (preg_match('/^ADD\s+(COLUMN\s+)?(.*)$/is', $spec, $m)) {
            $out[] = sqlc_add_column($table, $m[2], $existing);
        } elseif (preg_match('/^(MODIFY|ALTER)\s+(COLUMN\s+)?/i', $spec)) {
            continue;   // SQLite 는 타입을 엄격히 보지 않는다
        } elseif (preg_match('/^CHANGE\s+(COLUMN\s+)?`?(\w+)`?\s+`?(\w+)`?/i', $spec, $m)) {
            if (strcasecmp($m[2], $m[3]) !== 0) $out[] = "ALTER TABLE `$table` RENAME COLUMN `{$m[2]}` TO `{$m[3]}`";
        } elseif (preg_match('/^RENAME\s+(COLUMN\s+)?`?(\w+)`?\s+TO\s+`?(\w+)`?/i', $spec, $m) && !empty($m[1])) {
            $out[] = "ALTER TABLE `$table` RENAME COLUMN `{$m[2]}` TO `{$m[3]}`";
        } elseif (preg_match('/^RENAME\s+(TO\s+|AS\s+)?`?(\w+)`?$/i', $spec, $m)) {
            $out[] = "ALTER TABLE `$table` RENAME TO `{$m[2]}`";
        } elseif (preg_match('/^DROP\s+(INDEX|KEY)\s+`?(\w+)`?/i', $spec, $m)) {
            $out[] = "DROP INDEX IF EXISTS `{$table}__{$m[2]}`";
        } elseif (preg_match('/^DROP\s+(FOREIGN\s+KEY|PRIMARY\s+KEY|CONSTRAINT|CHECK)\b/i', $spec)) {
            continue;
        } elseif (preg_match('/^DROP\s+(COLUMN\s+)?`?(\w+)`?/i', $spec, $m)) {
            if (in_array(strtolower($m[2]), $existing, true)) $out[] = "ALTER TABLE `$table` DROP COLUMN `{$m[2]}`";
        } elseif (preg_match('/^(ENGINE|COMMENT|CONVERT|DEFAULT\s+CHARSET|CHARACTER|COLLATE|AUTO_INCREMENT|ROW_FORMAT|ALGORITHM|LOCK)\b/i', $spec)) {
            continue;
        } else {
            $out[] = "ALTER TABLE `$table` $spec";
        }
    }
    return $out ? implode(";\n", $out) : null;
}

function sqlc_add_column(string $table, string $def, array $existing): string {
    $name = strtolower(preg_replace('/^`?(\w+)`?.*/s', '$1', trim($def)));
    if (in_array($name, $existing, true)) {
        throw new SqliteCompatException("SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name '$name'", '42S21', 1060);
    }
    return "ALTER TABLE `$table` ADD COLUMN " . sqlc_column_def($def, $auto, true);
}

/* =====================================================================
 * information_schema 흉내 — 모듈들이 "이 컬럼·인덱스가 있나"를 여기 묻는다
 * ===================================================================== */

function sqlc_create_views(SqliteCompatPdo $pdo): void {
    $pdo->rawQuery("CREATE TEMP VIEW IF NOT EXISTS information_schema_TABLES AS
        SELECT 'main' AS TABLE_SCHEMA, name AS TABLE_NAME, 'BASE TABLE' AS TABLE_TYPE
        FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    $pdo->rawQuery("CREATE TEMP VIEW IF NOT EXISTS information_schema_COLUMNS AS
        SELECT 'main' AS TABLE_SCHEMA, m.name AS TABLE_NAME, p.name AS COLUMN_NAME, p.cid + 1 AS ORDINAL_POSITION,
               p.type AS COLUMN_TYPE, lower(p.type) AS DATA_TYPE,
               CASE WHEN p.\"notnull\" THEN 'NO' ELSE 'YES' END AS IS_NULLABLE, p.dflt_value AS COLUMN_DEFAULT,
               CASE WHEN instr(p.type, '(') THEN CAST(substr(p.type, instr(p.type, '(') + 1) AS INTEGER) END AS CHARACTER_MAXIMUM_LENGTH,
               CASE WHEN p.pk THEN 'PRI' ELSE '' END AS COLUMN_KEY, '' AS COLUMN_COMMENT, '' AS EXTRA
        FROM sqlite_master m JOIN pragma_table_info(m.name) p
        WHERE m.type='table' AND m.name NOT LIKE 'sqlite_%'");
    // 인덱스 이름은 SQLite 에서 DB 전체에 하나뿐이어야 해서 '<표>__<이름>' 으로 만들었다. 여기서 원래 이름으로 돌린다.
    $pdo->rawQuery("CREATE TEMP VIEW IF NOT EXISTS information_schema_STATISTICS AS
        SELECT 'main' AS TABLE_SCHEMA, m.name AS TABLE_NAME,
               CASE WHEN il.origin = 'pk' THEN 'PRIMARY'
                    WHEN substr(il.name, 1, length(m.name) + 2) = m.name || '__' THEN substr(il.name, length(m.name) + 3)
                    ELSE il.name END AS INDEX_NAME,
               ii.name AS COLUMN_NAME, ii.seqno + 1 AS SEQ_IN_INDEX, CASE WHEN il.\"unique\" THEN 0 ELSE 1 END AS NON_UNIQUE
        FROM sqlite_master m JOIN pragma_index_list(m.name) il JOIN pragma_index_info(il.name) ii
        WHERE m.type='table' AND m.name NOT LIKE 'sqlite_%'");
}

/* =====================================================================
 * MySQL 함수들
 * ===================================================================== */

function sqlc_register_functions(SqliteCompatPdo $pdo): void {
    $f = fn(string $name, callable $cb, int $n = -1) => $pdo->sqliteCreateFunction($name, $cb, $n);

    $f('NOW', fn() => date('Y-m-d H:i:s'), 0);
    $f('SYSDATE', fn() => date('Y-m-d H:i:s'), 0);
    $f('UTC_TIMESTAMP', fn() => gmdate('Y-m-d H:i:s'), 0);
    $f('CURDATE', fn() => date('Y-m-d'), 0);
    $f('CURTIME', fn() => date('H:i:s'), 0);
    $f('UNIX_TIMESTAMP', fn($d = null) => $d === null ? time() : (strtotime((string)$d) ?: 0));
    $f('FROM_UNIXTIME', fn($t, $fmt = null) => $fmt === null ? date('Y-m-d H:i:s', (int)$t) : sqlc_date_format(date('Y-m-d H:i:s', (int)$t), $fmt));
    $f('DATE_FORMAT', fn($d, $fmt) => $d === null ? null : sqlc_date_format((string)$d, (string)$fmt), 2);
    $f('DATE_ADD', fn($d, $n, $u) => sqlc_date_shift($d, (float)$n, (string)$u), 3);
    $f('DATE_SUB', fn($d, $n, $u) => sqlc_date_shift($d, -(float)$n, (string)$u), 3);
    $f('ADDDATE', fn($d, $n, $u = 'DAY') => sqlc_date_shift($d, (float)$n, (string)$u));
    $f('SUBDATE', fn($d, $n, $u = 'DAY') => sqlc_date_shift($d, -(float)$n, (string)$u));
    $f('DATEDIFF', fn($a, $b) => ($a === null || $b === null) ? null
        : (int)round((strtotime(substr((string)$a, 0, 10)) - strtotime(substr((string)$b, 0, 10))) / 86400), 2);
    $f('TIMESTAMPDIFF', function ($u, $a, $b) {
        if ($a === null || $b === null) return null;
        $ta = strtotime((string)$a); $tb = strtotime((string)$b);
        $sec = $tb - $ta;
        return match (strtoupper((string)$u)) {
            'SECOND' => $sec, 'MINUTE' => intdiv($sec, 60), 'HOUR' => intdiv($sec, 3600),
            'DAY' => intdiv($sec, 86400), 'WEEK' => intdiv($sec, 604800),
            'MONTH' => (int)((new DateTime((string)$a))->diff(new DateTime((string)$b))->format('%r%m')) + 12 * (int)((new DateTime((string)$a))->diff(new DateTime((string)$b))->format('%r%y')),
            'YEAR' => (int)(new DateTime((string)$a))->diff(new DateTime((string)$b))->format('%r%y'),
            default => $sec,
        };
    }, 3);
    foreach (['YEAR' => 'Y', 'MONTH' => 'n', 'DAY' => 'j', 'DAYOFMONTH' => 'j', 'HOUR' => 'G', 'MINUTE' => 'i', 'WEEKDAY' => null, 'DAYOFWEEK' => null] as $name => $fmt) {
        $f($name, function ($d) use ($name, $fmt) {
            if ($d === null || ($t = strtotime((string)$d)) === false) return null;
            if ($name === 'WEEKDAY') return ((int)date('N', $t)) - 1;     // 월=0
            if ($name === 'DAYOFWEEK') return ((int)date('w', $t)) + 1;   // 일=1
            return (int)date($fmt, $t);
        }, 1);
    }
    $f('LAST_DAY', fn($d) => $d === null ? null : date('Y-m-t', strtotime((string)$d)), 1);
    $f('CONCAT', function (...$a) { foreach ($a as $x) if ($x === null) return null; return implode('', $a); });
    $f('CONCAT_WS', fn($sep, ...$a) => implode((string)$sep, array_filter($a, fn($x) => $x !== null)));
    $f('FIELD', function ($v, ...$list) { foreach ($list as $i => $x) if ((string)$x === (string)$v && $v !== null) return $i + 1; return 0; });
    $f('FIND_IN_SET', function ($v, $set) { $i = array_search((string)$v, explode(',', (string)$set), true); return $i === false ? 0 : $i + 1; }, 2);
    $f('REGEXP', fn($pattern, $value) => $value === null ? null : (int)preg_match('/' . str_replace('/', '\/', (string)$pattern) . '/iu', (string)$value), 2);   // _ci 처럼 대소문자 무시
    $f('LEFT', fn($s, $n) => $s === null ? null : mb_substr((string)$s, 0, (int)$n), 2);
    $f('RIGHT', fn($s, $n) => $s === null ? null : ((int)$n <= 0 ? '' : mb_substr((string)$s, -(int)$n)), 2);
    $f('LPAD', fn($s, $n, $p) => $s === null ? null : mb_substr(str_pad((string)$s, (int)$n, (string)$p, STR_PAD_LEFT), 0, (int)$n), 3);
    $f('CHAR_LENGTH', fn($s) => $s === null ? null : mb_strlen((string)$s), 1);
    $f('MD5', fn($s) => $s === null ? null : md5((string)$s), 1);
    $f('SHA2', fn($s, $n) => $s === null ? null : hash('sha' . (int)$n, (string)$s), 2);
    $f('UUID', fn() => sprintf('%s-%s-%s-%s-%s', ...str_split(bin2hex(random_bytes(16)), 8) + [0, 0, 0, 0, 0]), 0);
    $f('DATABASE', fn() => 'main', 0);
    $f('GET_LOCK', fn($n, $t) => 1, 2);
    $f('RELEASE_LOCK', fn($n) => 1, 1);
    $f('IS_FREE_LOCK', fn($n) => 1, 1);
    $f('FOUND_ROWS', fn() => 0, 0);
}

function sqlc_date_shift($d, float $n, string $unit): ?string {
    if ($d === null || ($t = strtotime((string)$d)) === false) return null;
    $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
    $unit = strtoupper($unit);
    $map = ['SECOND' => 'second', 'MINUTE' => 'minute', 'HOUR' => 'hour', 'DAY' => 'day', 'WEEK' => 'week', 'MONTH' => 'month', 'YEAR' => 'year'];
    if ($unit === 'QUARTER') { $unit = 'MONTH'; $n *= 3; }
    $t = strtotime(sprintf('%+d %s', (int)$n, $map[$unit] ?? 'day'), $t);
    return ($dateOnly && in_array($unit, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true)) ? date('Y-m-d', $t) : date('Y-m-d H:i:s', $t);
}

/** MySQL DATE_FORMAT 의 자주 쓰는 지정자만 */
function sqlc_date_format(string $d, string $fmt): ?string {
    if (($t = strtotime($d)) === false) return null;
    $map = ['%Y' => 'Y', '%y' => 'y', '%m' => 'm', '%c' => 'n', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%k' => 'G',
            '%i' => 'i', '%s' => 's', '%S' => 's', '%p' => 'A', '%h' => 'h', '%l' => 'g', '%W' => 'l', '%a' => 'D',
            '%M' => 'F', '%b' => 'M', '%j' => 'z', '%u' => 'W', '%T' => 'H:i:s', '%%' => '%'];
    return preg_replace_callback('/%[a-zA-Z%]/', fn($m) => isset($map[$m[0]]) ? date($map[$m[0]], $t) : $m[0], $fmt);
}

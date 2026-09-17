<?php
/**
 * .sql 파일을 BlueCart 설정의 DB 접속으로 실행한다.
 *
 *   php dev/apply_sql.php sql/01_schema.sql sql/02_seed.sql
 *
 * mysql 클라이언트가 PATH 에 없어도 되고, 포털 모듈로 쓸 때는 접속 정보를
 * 포털 config.php 에서 그대로 물려받으므로 비밀번호를 다시 입력할 필요가 없다.
 *
 * 주의: 프로시저·트리거처럼 본문에 세미콜론이 들어가는 구문은 다루지 않는다.
 *       BlueCart 스키마에는 그런 게 없다. DELIMITER 도 지원하지 않는다.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI 전용입니다.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$files = array_slice($argv, 1);
if (!$files) {
    fwrite(STDERR, "실행할 .sql 파일을 지정하세요.\n");
    exit(1);
}

/**
 * SQL 을 문장 단위로 쪼갠다.
 * 문자열 리터럴과 주석 안의 세미콜론은 구분자가 아니므로 상태를 보며 훑는다.
 *
 * @return string[]
 */
function bc_split_sql(string $sql): array
{
    $out  = [];
    $buf  = '';
    $len  = strlen($sql);
    $i    = 0;
    $quote = '';          // ' " ` 중 현재 열려 있는 것
    $line  = false;       // -- 또는 # 주석 안
    $block = false;       // /* */ 주석 안

    while ($i < $len) {
        $c  = $sql[$i];
        $c2 = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($line) {
            if ($c === "\n") { $line = false; $buf .= $c; }
            $i++;
            continue;
        }
        if ($block) {
            if ($c === '*' && $c2 === '/') { $block = false; $i += 2; continue; }
            $i++;
            continue;
        }
        if ($quote !== '') {
            $buf .= $c;
            if ($c === '\\' && $c2 !== '') {      // 이스케이프
                $buf .= $c2;
                $i += 2;
                continue;
            }
            if ($c === $quote) {
                // 같은 따옴표가 두 번이면 리터럴 안의 따옴표
                if ($c2 === $quote) { $buf .= $c2; $i += 2; continue; }
                $quote = '';
            }
            $i++;
            continue;
        }

        // 주석 시작
        if ($c === '-' && $c2 === '-' && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n")) {
            $line = true; $i += 2; continue;
        }
        if ($c === '#') { $line = true; $i++; continue; }
        if ($c === '/' && $c2 === '*') { $block = true; $i += 2; continue; }

        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buf .= $c; $i++; continue; }

        if ($c === ';') {
            if (trim($buf) !== '') { $out[] = trim($buf); }
            $buf = '';
            $i++;
            continue;
        }

        $buf .= $c;
        $i++;
    }

    if (trim($buf) !== '') { $out[] = trim($buf); }
    return $out;
}

$pdo   = bc_db();
$total = 0;

foreach ($files as $rel) {
    $path = $rel;
    if (!is_file($path)) {
        $path = BC_ROOT . '/' . ltrim($rel, '/\\');
    }
    if (!is_file($path)) {
        fwrite(STDERR, "파일을 찾을 수 없습니다: {$rel}\n");
        exit(1);
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "읽지 못했습니다: {$path}\n");
        exit(1);
    }

    $stmts = bc_split_sql($sql);
    $n = 0;
    foreach ($stmts as $stmt) {
        try {
            $pdo->exec($stmt);
            $n++;
        } catch (PDOException $e) {
            fwrite(STDERR, PHP_EOL . "실패: " . basename($path) . PHP_EOL);
            fwrite(STDERR, "  " . $e->getMessage() . PHP_EOL);
            fwrite(STDERR, "  구문: " . mb_substr(preg_replace('/\s+/', ' ', $stmt) ?? '', 0, 160) . PHP_EOL);
            exit(1);
        }
    }
    printf("  %-28s %d개 구문 실행%s", basename($path), $n, PHP_EOL);
    $total += $n;
}

printf("완료: 총 %d개 구문%s", $total, PHP_EOL);

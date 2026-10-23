<?php
/**
 * 파이썬 장부(blueledger.db) → 포털 DB 의 bl_ledger_* 표로 옮기기. 명령줄 전용.
 *
 *   D:\php82\php.exe blueledger\tools\import_ledger.php "<장부 복사본 경로>" [--replace]
 *
 *  - 원본 장부가 아니라 반드시 '복사본'을 넘긴다. 이 도구는 넘긴 파일을 읽기 전용으로만 연다.
 *  - 옮길 곳에 거래가 이미 있으면 멈춘다. 지우고 다시 옮기려면 --replace 를 붙인다.
 *  - 번호(id)는 그대로 옮긴다 — 거래·세금계산서 연결이 번호로 이어져 있기 때문이다.
 *  - 파이썬의 users(이름+비밀번호) 표는 옮기지 않는다. 포털 로그인 + 출입 명단(bl_ledger_roles)으로 바뀌었다.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/ledger.php';

$src = $argv[1] ?? '';
$replace = in_array('--replace', $argv, true);
if ($src === '' || !is_file($src)) {
    fwrite(STDERR, "장부 복사본 경로를 넣어 주세요.\n");
    exit(1);
}

// 파이썬 표 → 새 표, 이름이 바뀐 컬럼
const MAP = [
    'accounts'        => ['bl_ledger_accounts', []],
    'transactions'    => ['bl_ledger_tx', []],
    'categories'      => ['bl_ledger_categories', []],
    'rules'           => ['bl_ledger_rules', []],
    'partners'        => ['bl_ledger_partners', []],
    'invoices'        => ['bl_ledger_invoices', []],
    'invoice_links'   => ['bl_ledger_invoice_links', []],
    'month_closes'    => ['bl_ledger_month_closes', []],
    'employees'       => ['bl_ledger_employees', []],
    'payroll'         => ['bl_ledger_payroll', []],
    'payroll_company' => ['bl_ledger_payroll_company', []],
    'payroll_items'   => ['bl_ledger_payroll_items', []],
    'payroll_values'  => ['bl_ledger_payroll_values', []],
    'settings'        => ['bl_ledger_settings', ['key' => 'k', 'value' => 'v']],
];

$in = new PDO('sqlite:' . $src, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$in->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$in->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo = lg_db();
if ((int)$pdo->query("SELECT COUNT(*) FROM bl_ledger_tx")->fetchColumn() && !$replace) {
    fwrite(STDERR, "옮길 곳에 이미 거래가 있습니다. 지우고 다시 옮기려면 --replace 를 붙여 주세요.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    foreach (MAP as $from => [$to, $rename]) {
        $cols = BL_SCHEMA[$to]['cols'];
        $pdo->exec("DELETE FROM `$to`");
        $n = 0;
        $st = null;
        foreach ($in->query("SELECT * FROM `$from`") as $row) {
            $data = [];
            foreach ($row as $c => $v) {
                $c = $rename[$c] ?? $c;
                if (!isset($cols[$c])) continue;
                $default = $cols[$c][1];
                // 파이썬에서 비어 있던(NULL) 칸은, 새 표가 NULL 을 받지 않으면 기본값으로
                if ($v === null && $default !== null && $default !== false) $v = $default;
                $data[$c] = $v;
            }
            if (!$st) {
                $names = array_keys($data);
                $st = $pdo->prepare("INSERT INTO `$to` (`" . implode('`,`', $names) . "`) VALUES (" . lg_ph($names) . ")");
            }
            $st->execute(array_values($data));
            $n++;
        }
        printf("%-18s → %-26s %5d줄\n", $from, $to, $n);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "옮기지 못했습니다(아무것도 바뀌지 않았습니다): " . $e->getMessage() . "\n");
    exit(1);
}
echo "끝났습니다.\n";

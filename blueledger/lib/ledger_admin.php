<?php
/**
 * BlueLedger 회계 장부 — 관리: 장부 내려받기·출입 명단·작업 기록.
 *  파이썬 버전의 '백업'과 '사용자' 메뉴를 서버에 맞게 바꾼 것이다.
 *   - 백업: 서버 DB 백업은 운영(mysqldump)에서 한다. 여기서는 지금 장부 전체를 엑셀 한 파일로 내려받는다.
 *   - 사용자: 로그인은 포털이 맡으므로, 장부에 들어올 수 있는 사람(출입 명단)과 역할만 관리한다.
 *   - 작업 기록: 누가 언제 무엇을 바꿨는지, 지운 거래의 원래 내용까지 본다.
 */

require_once __DIR__ . '/ledger_xlsx.php';
require_once __DIR__ . '/ledger_views.php';

/* ================================================================ 장부 내려받기 */

// [시트 이름, 표, 컬럼(머리줄 => 컬럼), 정렬]
const LG_EXPORT_SHEETS = [
    ['계좌·카드', 'bl_ledger_accounts', ['번호' => 'id', '이름' => 'name', '종류' => 'kind', '금융기관' => 'institution', '기초잔액' => 'opening_balance',
        '기초일자' => 'opening_date', '카드대금 글자' => 'pay_keyword', '결제일' => 'pay_day', '연결 시작일' => 'pay_start', '연결 종료일' => 'pay_end', '대표 카드' => 'pay_group'], 'kind, id'],
    ['거래', 'bl_ledger_tx', ['번호' => 'id', '계좌·카드' => 'account_id', '날짜' => 'tx_date', '적요' => 'content', '입금' => 'amount_in', '출금' => 'amount_out',
        '계정과목' => 'category', '출납장 내용' => 'detail', '은행 메모' => 'note', '메모' => 'memo', '카드번호' => 'card_no', '결제예정일' => 'bill_due',
        '카드대금 연결' => 'linked_card_id', '나눈 원래 거래' => 'split_of', '거래 후 잔액' => 'bank_balance', '가져온 곳' => 'source_file', '열쇠' => 'dedup_key'], 'tx_date, id'],
    ['계정과목', 'bl_ledger_categories', ['번호' => 'id', '이름' => 'name', '구분' => 'grp', '순서' => 'sort', '숨김' => 'hidden'], 'sort, id'],
    ['자동 분류 규칙', 'bl_ledger_rules', ['번호' => 'id', '적요에 들어간 글자' => 'keyword', '계정과목' => 'category', '방향' => 'direction'], 'category, keyword'],
    ['거래처', 'bl_ledger_partners', ['번호' => 'id', '사업자번호' => 'biz_no', '상호' => 'name', '대표자' => 'ceo', '주소' => 'address', '메일' => 'email',
        '전화' => 'phone', '담당자' => 'manager', '통장 표시 이름' => 'aliases', '메모' => 'memo'], 'name'],
    ['세금계산서', 'bl_ledger_invoices', ['번호' => 'id', '구분' => 'kind', '분류' => 'doc_type', '승인번호' => 'approval_no', '작성일' => 'write_date', '발급일' => 'issue_date',
        '거래처 번호' => 'partner_id', '거래처' => 'partner_name', '합계' => 'total', '공급가액' => 'supply', '세액' => 'tax', '품목' => 'item', '비고' => 'note',
        '영수/청구' => 'receipt', '완료 사유' => 'closed', '가져온 파일' => 'source_file', '열쇠' => 'dedup_key'], 'write_date, id'],
    ['세금계산서 연결', 'bl_ledger_invoice_links', ['번호' => 'id', '세금계산서 번호' => 'invoice_id', '거래 번호' => 'tx_id', '금액' => 'amount', '방법' => 'method', '연결한 때' => 'created_at'], 'id'],
    ['월마감', 'bl_ledger_month_closes', ['달' => 'ym', '마감한 때' => 'closed_at', '요약' => 'summary'], 'ym'],
    ['설정', 'bl_ledger_settings', ['이름' => 'k', '값' => 'v'], 'k'],
];

function lg_page_backup(array $q, array $me) {
    $counts = [];
    foreach (LG_EXPORT_SHEETS as [$title, $table]) $counts[$title] = (int)lg_val("SELECT COUNT(*) FROM `$table`");
    return ['counts' => $counts, 'logs' => (int)lg_val("SELECT COUNT(*) FROM bl_ledger_log")];
}

/** 장부 전체를 엑셀 한 파일로 (표마다 한 시트, 머리줄 고정·거르기) */
function lg_backup_book() {
    $wb = new LgXlsx();
    foreach (LG_EXPORT_SHEETS as [$title, $table, $cols, $order]) {
        $ws = $wb->addSheet($title);
        $c = 1;
        foreach ($cols as $label => $_) { $ws->v(lg_xlsx_col($c) . '1', $label); $ws->s(lg_xlsx_col($c) . '1', ['font' => [10, true], 'fill' => 'DDEBF7', 'border' => true]); $c++; }
        $r = 2;
        foreach (lg_all("SELECT * FROM `$table` ORDER BY $order") as $row) {
            $c = 1;
            foreach ($cols as $col) {
                $v = $row[$col] ?? null;
                if ($v !== null && $v !== '' && preg_match('/^-?\d+$/', (string)$v) && !in_array($col, ['biz_no', 'card_no', 'approval_no', 'dedup_key', 'k', 'v'], true)) $v = (int)$v;
                $ws->v(lg_xlsx_col($c) . $r, $v);
                $c++;
            }
            $r++;
        }
        $ws->freeze = 'A2';
        $ws->autoFilter = 'A1:' . lg_xlsx_col(count($cols)) . max(1, $r - 1);
        for ($i = 1; $i <= count($cols); $i++) $ws->widths[$i] = 14;
    }
    return [$wb, 'BlueLedger_장부_' . date('Y-m-d_His') . '.xlsx'];
}

/* ================================================================ 출입 명단 */

function lg_page_members(array $q, array $me) {
    return [
        'rows' => lg_all("SELECT r.*, u.name FROM bl_ledger_roles r LEFT JOIN portal_users u ON LOWER(u.email)=r.email ORDER BY CASE r.role WHEN 'clerk' THEN 0 ELSE 1 END, r.email"),
        'users' => lg_all("SELECT name, LOWER(email) AS email FROM portal_users WHERE LOWER(email) NOT IN (SELECT email FROM bl_ledger_roles) ORDER BY name"),
    ];
}

function lg_post_members(array $p, array $me) {
    $back = lg_url('members');
    $email = strtolower(trim((string)($p['email'] ?? '')));
    $role = ($p['role'] ?? '') === LG_ROLE_CEO ? LG_ROLE_CEO : LG_ROLE_CLERK;
    $now = date('Y-m-d H:i:s');
    $clerks = (int)lg_val("SELECT COUNT(*) FROM bl_ledger_roles WHERE role=?", [LG_ROLE_CLERK]);
    $cur = lg_val("SELECT role FROM bl_ledger_roles WHERE email=?", [$email]);
    switch ($p['do'] ?? '') {
        case 'add':
            if (!lg_val("SELECT 1 FROM portal_users WHERE LOWER(email)=?", [$email])) return [[['error', '포털 계정이 있는 사람만 넣을 수 있어요.']], $back];
            bl_upsert(lg_db(), 'bl_ledger_roles', ['email' => $email, 'role' => $role, 'updated_by' => $me['email'], 'updated_at' => $now], ['email']);
            lg_log($me, '출입 명단', "$email 추가 (" . LG_ROLE_LABEL[$role] . ')');
            return [[['ok', "$email 님을 " . LG_ROLE_LABEL[$role] . '(으)로 넣었어요.']], $back];
        case 'role':
            if (!$cur) return [[], $back];
            if ($cur === LG_ROLE_CLERK && $role !== LG_ROLE_CLERK && $clerks <= 1) return [[['error', '담당자가 한 명은 있어야 해요. 다른 담당자를 먼저 넣어 주세요.']], $back];
            lg_exec("UPDATE bl_ledger_roles SET role=?, updated_by=?, updated_at=? WHERE email=?", [$role, $me['email'], $now, $email]);
            lg_log($me, '출입 명단', "$email → " . LG_ROLE_LABEL[$role]);
            return [[['ok', '역할을 바꿨어요.']], $back];
        case 'remove':
            if (!$cur) return [[], $back];
            if ($cur === LG_ROLE_CLERK && $clerks <= 1) return [[['error', '담당자가 한 명은 있어야 해요. 마지막 담당자는 뺄 수 없어요.']], $back];
            lg_exec("DELETE FROM bl_ledger_roles WHERE email=?", [$email]);
            lg_log($me, '출입 명단', "$email 뺌");
            return [[['ok', "$email 님을 출입 명단에서 뺐어요. 이제 장부 화면에 들어올 수 없어요."]], $back];
    }
    return [[], $back];
}

/* ================================================================ 작업 기록 */

function lg_page_log(array $q, array $me) {
    $kw = lg_strip($q['q'] ?? '');
    $sql = "SELECT l.*, u.name FROM bl_ledger_log l LEFT JOIN portal_users u ON LOWER(u.email)=l.email";
    $params = [];
    if ($kw !== '') { $sql .= " WHERE l.action LIKE ? OR l.summary LIKE ?"; $params = ["%$kw%", "%$kw%"]; }
    return ['rows' => lg_all($sql . " ORDER BY l.id DESC LIMIT 300", $params), 'q' => $kw,
            'total' => (int)lg_val("SELECT COUNT(*) FROM bl_ledger_log")];
}

/* ================================================================ 화면 */

function lg_admin_tabs($on) {
    $tabs = ['backup' => '장부 내려받기', 'members' => '출입 명단', 'log' => '작업 기록'];
    $o = '<nav class="subtabs">';
    foreach ($tabs as $p => $label) $o .= '<a class="' . ($p === $on ? 'on' : '') . '" href="' . h(lg_url($p)) . '">' . h($label) . '</a>';
    return $o . '</nav>';
}

function lg_view_backup(array $v, array $me) {
    ob_start(); ?>
<?= lg_admin_tabs('backup') ?>
<div class="box">
  <p class="help">지금 장부를 <b>엑셀 한 파일</b>로 내려받아요. 표마다 시트가 하나씩(계좌·카드, 거래, 계정과목, 규칙, 거래처, 세금계산서, 연결, 월마감, 설정) 들어가요. 큰 작업 전후나 월마감 뒤에 받아 두면, 세무사 사무실에 보내거나 USB·NAS 에 보관할 수 있어요.</p>
  <p class="help">서버 장부 자체의 백업(데이터베이스)은 회사 서버에서 매일 따로 해요. 급여대장은 아직 PC 의 파이썬 버전에 있어서 이 파일에는 넣지 않았어요.</p>
  <div><a class="btn" href="<?= h(lg_url('backup_xlsx')) ?>">지금 장부 내려받기 (엑셀)</a></div>
</div>
<div class="box">
  <h2>들어갈 내용</h2>
  <div class="scroll"><table style="max-width:420px">
    <tr><th>시트</th><th class="num">줄 수</th></tr>
    <?php foreach ($v['counts'] as $t => $n): ?><tr><td><?= h($t) ?></td><td class="num"><?= lg_won($n) ?></td></tr><?php endforeach ?>
  </table></div>
  <p class="help">작업 기록 <?= lg_won($v['logs']) ?>건은 [작업 기록]에서 볼 수 있어요.</p>
</div>
<?php
    return ob_get_clean();
}

function lg_view_members(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    ob_start(); ?>
<?= lg_admin_tabs('members') ?>
<div class="box">
  <p class="help">BlueLedger 장부는 <b>이 명단에 있는 사람만</b> 들어올 수 있어요. 로그인은 blue-iWorks 포털 계정 그대로 쓰고, 역할은 두 가지예요.<br>
  <b>담당자</b>: 모든 기능(가져오기·분류·정산·마감·명단 관리). <b>대표</b>: 모든 화면을 볼 수 있지만 저장·삭제는 못 해요. 명단에 없는 직원은 포털 카드도 보이지 않아요.</p>
  <div class="scroll"><table>
    <tr><th>이름</th><th>포털 이메일</th><th>역할</th><th>바꾼 때</th><th></th></tr>
    <?php foreach ($v['rows'] as $r): ?>
    <tr><td><?= h($r['name'] ?: '(포털 계정 없음)') ?><?= $r['email'] === $me['email'] ? ' <span class="badge b-blue">나</span>' : '' ?></td><td><?= h($r['email']) ?></td>
      <td><?php if ($ceo): ?><?= h(LG_ROLE_LABEL[$r['role']] ?? $r['role']) ?><?php else: ?>
        <form method="post" action="<?= h(lg_url('members')) ?>" class="bar-l"><?= lg_csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="email" value="<?= h($r['email']) ?>">
          <select name="role" data-autosubmit aria-label="역할"><?php foreach (LG_ROLE_LABEL as $k => $label): ?><option value="<?= $k ?>"<?= lg_sel($r['role'], $k) ?>><?= $label ?></option><?php endforeach ?></select></form><?php endif ?></td>
      <td class="sub-s"><?= h(substr((string)$r['updated_at'], 0, 16)) ?></td>
      <td><?php if (!$ceo): ?><form method="post" action="<?= h(lg_url('members')) ?>" data-confirm="<?= h($r['email']) ?> 님을 출입 명단에서 뺄까요?"><?= lg_csrf_field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="email" value="<?= h($r['email']) ?>"><button class="btn danger">빼기</button></form><?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <?php if (!$ceo && $v['users']): ?>
  <form class="inline period-del" method="post" action="<?= h(lg_url('members')) ?>"><?= lg_csrf_field() ?><input type="hidden" name="do" value="add">
    <label>포털 계정<select name="email"><?php foreach ($v['users'] as $x): ?><option value="<?= h($x['email']) ?>"><?= h($x['name']) ?> (<?= h($x['email']) ?>)</option><?php endforeach ?></select></label>
    <label>역할<select name="role"><?php foreach (LG_ROLE_LABEL as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach ?></select></label>
    <button class="btn" type="submit">넣기</button>
  </form>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_log(array $v, array $me) {
    ob_start(); ?>
<?= lg_admin_tabs('log') ?>
<div class="box">
  <p class="help" style="margin:0">장부를 바꾼 일이 모두 남아요(누가·언제·무엇을). 거래를 지웠을 때는 지운 줄의 원래 내용도 [내용 보기]로 볼 수 있어요. 전체 <?= lg_won($v['total']) ?>건 중 최근 300건을 보여드려요.</p>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="log"><label>찾기<input name="q" value="<?= h($v['q']) ?>" placeholder="예: 삭제, 마감, 쿠팡"></label><button class="btn ghost" type="submit">찾기</button></form>
</div>
<div class="box">
  <?php if ($v['rows']): ?><div class="scroll"><table>
    <tr><th>때</th><th>누가</th><th>무엇을</th><th>내용</th><th></th></tr>
    <?php foreach ($v['rows'] as $r): ?><tr>
      <td class="num" style="font-size:13px"><?= h(substr($r['at'], 0, 16)) ?></td><td style="font-size:13px"><?= h($r['name'] ?: $r['email']) ?></td>
      <td><b><?= h($r['action']) ?></b></td><td style="white-space:normal;max-width:520px;font-size:13px"><?= h($r['summary']) ?></td>
      <td><?php if ($r['data']): ?><details><summary style="cursor:pointer;font-size:13px;color:var(--link)">내용 보기</summary><pre class="logdata"><?= h(json_encode(json_decode($r['data'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></details><?php endif ?></td></tr><?php endforeach ?>
  </table></div><?php else: ?><p class="help">기록이 없어요.</p><?php endif ?>
</div>
<?php
    return ob_get_clean();
}

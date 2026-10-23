<?php
/**
 * BlueLedger — ⑥ 증빙·정산 화면 HTML (파이썬 templates/settle.html, invoice_import.html, invoice_result.html,
 * invoice_detail.html, prepaid.html, partners.html 을 같은 문구로).
 */

require_once __DIR__ . '/ledger_views.php';
require_once __DIR__ . '/ledger_settle.php';

function lg_settle_tabs($on) {
    $tabs = ['settle' => '미수·미지급', 'invoice_import' => '세금계산서 가져오기', 'partners' => '거래처'];
    $o = '<nav class="subtabs">';
    foreach ($tabs as $p => $label) $o .= '<a class="' . ($p === $on ? 'on' : '') . '" href="' . h(lg_url($p)) . '">' . h($label) . '</a>';
    return $o . '</nav>';
}

const LG_STATUS_BADGE = ['정산 완료' => 'b-green', '확인 필요' => 'b-blue', '일부 정산' => 'b-orange', '미수' => 'b-orange', '미지급' => 'b-orange'];

function lg_view_settle(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    $s = $v['summary'];
    $self = lg_url('settle', ['kind' => $v['kind'], 'status' => $v['status'], 'start' => $v['start'], 'end' => $v['end'], 'partner_id' => $v['pid']]);
    ob_start(); ?>
<?= lg_settle_tabs('settle') ?>
<div class="box">
  <div class="bar">
    <p class="help" style="margin:0">세금계산서마다 통장 입출금·카드 결제가 연결됐는지 보여드려요. 연결 안 된 매출은 <b>미수금</b>, 매입은 <b>미지급금</b>이에요.</p>
    <?php if (!$ceo): ?><div class="bar-l">
      <form method="post" action="<?= h(lg_url('settle')) ?>"><?= lg_csrf_field() ?><button class="btn ghost" type="submit">빈 곳 자동 정산</button></form>
      <form method="post" action="<?= h(lg_url('settle')) ?>" data-confirm="자동으로 연결된 것만 지우고 새 규칙으로 다시 짝지을까요? 직접 연결·확인 완료·충전금 차감·완료 처리한 건은 그대로 둬요."><?= lg_csrf_field() ?><input type="hidden" name="mode" value="reset"><button class="btn ghost" type="submit">자동 연결 다시 하기</button></form>
    </div><?php endif ?>
  </div>
  <div class="result">
    <div>미수금 (받을 돈)<strong class="in"><?= lg_won($s['ar']) ?></strong></div>
    <div>미지급금 (줄 돈)<strong class="out"><?= lg_won($s['ap']) ?></strong></div>
    <div>정산 완료<strong><?= $s['done'] ?> / <?= $s['n'] ?>건</strong></div>
    <div>확인 필요<strong><?= $s['check'] ?>건</strong></div>
  </div>
</div>

<div class="cols" style="grid-template-columns:1fr 1fr">
  <div class="box">
    <h2>거래처별 미수금</h2>
    <?php if ($v['ar']): ?><div class="scroll"><table><tr><th>거래처</th><th class="num">건수</th><th class="num">미수금</th><th>가장 오래된 것</th></tr>
      <?php foreach ($v['ar'] as $x): ?><tr><td><a href="<?= h(lg_url('settle', ['partner_id' => $x['partner_id'], 'kind' => '매출'])) ?>"><?= h($x['partner_name']) ?></a></td><td class="num"><?= $x['n'] ?></td><td class="num in"><b><?= lg_won($x['remain']) ?></b></td><td><?= h($x['oldest']) ?></td></tr><?php endforeach ?>
    </table></div><?php else: ?><p class="help">미수금이 없어요.</p><?php endif ?>
  </div>
  <div class="box">
    <h2>거래처별 미지급금</h2>
    <?php if ($v['ap']): ?><div class="scroll"><table><tr><th>거래처</th><th class="num">건수</th><th class="num">미지급금</th><th>가장 오래된 것</th><th></th></tr>
      <?php foreach ($v['ap'] as $x): ?><tr><td><a href="<?= h(lg_url('settle', ['partner_id' => $x['partner_id'], 'kind' => '매입'])) ?>"><?= h($x['partner_name']) ?></a></td><td class="num"><?= $x['n'] ?></td><td class="num out"><b><?= lg_won($x['remain']) ?></b></td><td><?= h($x['oldest']) ?></td>
        <td><?php if ($x['partner_id']): ?><a href="<?= h(lg_url('prepaid', ['pid' => $x['partner_id'], 'back' => $self])) ?>" style="font-size:13px;white-space:nowrap">충전금으로 정산<?php if ($x['prepaid'] !== null): ?><br><span class="sub-s">잔액 <?= lg_won($x['prepaid']) ?></span><?php endif ?></a><?php endif ?></td></tr><?php endforeach ?>
    </table></div><?php else: ?><p class="help">미지급금이 없어요.</p><?php endif ?>
  </div>
</div>

<div class="box">
  <h2>세금계산서 목록</h2>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="settle">
    <label>구분<select name="kind"><option value="">전체</option><option<?= lg_sel($v['kind'], '매출') ?>>매출</option><option<?= lg_sel($v['kind'], '매입') ?>>매입</option></select></label>
    <label>상태<select name="status"><option value="">전체</option><?php foreach (['미수', '미지급', '일부 정산', '확인 필요', '정산 완료', '수정(마이너스)'] as $st): ?><option<?= lg_sel($v['status'], $st) ?>><?= $st ?></option><?php endforeach ?></select></label>
    <label>거래처<select name="partner_id"><option value="">전체</option><?php foreach ($v['partners'] as $pp): ?><option value="<?= $pp['id'] ?>"<?= lg_sel($v['pid'], $pp['id']) ?>><?= h($pp['name']) ?></option><?php endforeach ?></select></label>
    <label>작성일 시작<input type="date" name="start" value="<?= h($v['start']) ?>"></label>
    <label>끝<input type="date" name="end" value="<?= h($v['end']) ?>"></label>
    <button class="btn" type="submit">조회</button>
  </form>
  <?php if ($v['rows']): ?>
  <form method="post" action="<?= h(lg_url('settle_bulk')) ?>"><?= lg_csrf_field() ?><input type="hidden" name="back" value="<?= h($self) ?>">
  <div class="scroll"><table>
    <tr><th><?php if (!$ceo): ?><input type="checkbox" class="chk" aria-label="모두 선택" data-pick-all=".pick"><?php endif ?></th><th>작성일</th><th>구분</th><th>거래처</th><th>품목</th><th class="num">합계금액</th><th class="num">연결된 금액</th><th>상태</th><th></th></tr>
    <?php foreach ($v['rows'] as $r): ?>
    <tr>
      <td><?php if (!$ceo && in_array($r['status'], ['미수', '미지급', '일부 정산', '확인 필요'], true)): ?><input type="checkbox" class="pick chk" name="inv_id[]" value="<?= $r['id'] ?>" aria-label="선택"><?php endif ?></td>
      <td><?= h($r['write_date']) ?></td><td><?= h($r['kind']) ?><?php if ($r['doc_type'] === '계산서'): ?><br><span class="sub-s">면세</span><?php endif ?></td>
      <td style="white-space:normal;max-width:220px"><?= h($r['partner_name']) ?></td>
      <td style="white-space:normal;max-width:260px;font-size:13px"><?= h($r['item']) ?></td>
      <td class="num"><?= lg_won($r['total']) ?></td><td class="num"><?= (int)$r['paid'] ? lg_won($r['paid']) : '-' ?></td>
      <td><span class="badge <?= LG_STATUS_BADGE[$r['status']] ?? 'b-gray' ?>"><?= h($r['status']) ?></span><?php if ($r['closed'] && $r['closed'] !== '정산 완료'): ?><br><span class="sub-s"><?= h($r['closed']) ?></span><?php endif ?></td>
      <td><a href="<?= h(lg_url('invoice', ['iid' => $r['id'], 'back' => $self])) ?>" style="font-size:13px">자세히</a></td>
    </tr>
    <?php endforeach ?>
  </table></div>
  <?php if (!$ceo): ?>
  <div class="period-del">
    <span class="help">고른 계산서를</span>
    <button class="btn ghost" name="do" value="confirm" type="submit">확인 완료로 표시 (확인 필요 건)</button>
    <select name="reason" aria-label="완료 사유"><option>이전 카드로 결제</option><option>대표·개인 카드로 결제</option><option>다른 통장으로 입출금</option><option>상계 처리</option><option>현금 결제</option><option>기타</option></select>
    <button class="btn ghost" name="do" value="close" type="submit">연결 없이 정산 완료 처리</button>
  </div>
  <?php endif ?>
  </form>
  <?php if ($v['n_rows'] > count($v['rows'])): ?><p class="help"><?= $v['n_rows'] ?>건 중 <?= count($v['rows']) ?>건만 보여드려요. 조건을 좁혀보세요.</p><?php endif ?>
  <?php else: ?><p class="help">조건에 맞는 세금계산서가 없어요.</p><?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_invoice_import(array $v, array $me) {
    ob_start(); ?>
<?= lg_settle_tabs('invoice_import') ?>
<div class="box">
  <p class="help">홈택스 → 전자(세금)계산서 → 목록조회에서 받은 엑셀을 올려주세요. <b>매출·매입, 세금계산서·계산서(면세) 파일을 한 번에 여러 개</b> 골라도 돼요. 매출인지 매입인지는 파일을 보고 알아서 구분해요.</p>
  <p class="help">올리면 ① 거래처가 사업자번호 기준으로 자동 등록되고 ② 계산서마다 통장 입출금·카드 결제를 찾아 자동으로 연결돼요. 같은 파일을 다시 올려도 중복으로 들어가지 않아요.</p>
  <?php if ($me['role'] !== LG_ROLE_CEO): ?>
  <form class="inline" method="post" action="<?= h(lg_url('invoice_import')) ?>" enctype="multipart/form-data"><?= lg_csrf_field() ?>
    <label>세금계산서 목록 파일 (여러 개 선택 가능)<input type="file" name="files[]" accept=".xls,.xlsx,.csv" multiple required></label>
    <button class="btn" type="submit">가져오기</button>
  </form>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_invoice_done(array $v, array $me) {
    if (($v['kind'] ?? '') !== 'invoice') return '<p class="help">보여줄 결과가 없어요.</p>';
    ob_start(); ?>
<div class="box">
  <?php foreach ($v['errors'] as $e): ?><div class="flash error"><?= h($e) ?></div><?php endforeach ?>
  <div class="result">
    <div>읽은 파일<strong><?= $v['files'] ?>개</strong></div>
    <div>새로 들어간 계산서<strong><?= $v['added'] ?>건</strong></div>
    <div>이미 있어서 건너뜀<strong><?= $v['dup'] ?>건</strong></div>
    <div>새로 등록된 거래처<strong><?= $v['partners'] ?>곳</strong></div>
  </div>
  <h2>자동 연결</h2>
  <div class="result">
    <div>이름·금액이 맞아 연결<strong><?= $v['single'] ?>건</strong></div>
    <div>카드 결제와 연결<strong><?= $v['card'] ?>건</strong></div>
    <div>여러 건 합산 입금<strong><?= $v['combo'] ?>번</strong></div>
    <div>나눠서 입금<strong><?= $v['split'] ?>건</strong></div>
    <div>금액·날짜만 맞음 (확인 필요)<strong><?= $v['amount_only'] ?>건</strong></div>
    <div>수정세금계산서 상쇄<strong><?= $v['pair'] ?>쌍</strong></div>
    <div>충전금에서 차감<strong><?= $v['prepaid'] ?>건</strong></div>
  </div>
  <div class="bar-l"><a class="btn" href="<?= h(lg_url('settle')) ?>">미수금·미지급금 보러 가기</a> <a class="btn ghost" href="<?= h(lg_url('partners')) ?>">거래처 보기</a></div>
</div>
<?php
    return ob_get_clean();
}

function lg_view_invoice(array $v, array $me) {
    $inv = $v['inv'];
    $ceo = $me['role'] === LG_ROLE_CEO;
    $act = lg_url('invoice', ['iid' => $inv['id'], 'q' => $v['q'], 'back' => $v['back']]);
    $f = fn($do, $extra = '') => '<form method="post" action="' . h($act) . '"' . $extra . '>' . lg_csrf_field() . '<input type="hidden" name="do" value="' . $do . '">';
    ob_start(); ?>
<?php if ($v['back']): ?><div class="crumb"><a href="<?= h($v['back']) ?>">← 목록으로</a></div><?php endif ?>
<div class="box">
  <div class="result">
    <div>작성일<strong style="font-size:18px"><?= h($inv['write_date']) ?></strong></div>
    <div>합계금액<strong><?= lg_won($inv['total']) ?></strong><span style="font-size:12.5px">공급가 <?= lg_won($inv['supply']) ?> · 세액 <?= lg_won($inv['tax']) ?></span></div>
    <div>연결된 금액<strong class="<?= $inv['kind'] === '매출' ? 'in' : 'out' ?>"><?= lg_won($v['paid']) ?></strong></div>
    <div>남은 금액<strong><?= lg_won($v['remain']) ?></strong></div>
  </div>
  <p class="help">품목: <?= h($inv['item']) ?><?= $inv['note'] ? ' · 비고: ' . h($inv['note']) : '' ?> · <?= h($inv['receipt']) ?> · 승인번호 <?= h($inv['approval_no']) ?><br>
  상태: <b><?= h($v['status']) ?></b><?= $inv['closed'] ? ' (' . h($inv['closed']) . ')' : '' ?><?= $v['partner'] ? ' · 거래처 사업자번호 ' . h($v['partner']['biz_no']) : '' ?></p>
  <?php if (!$ceo): ?><div class="bar-l">
    <?php if ($v['status'] === '확인 필요'): ?><?= $f('confirm') ?><button class="btn" type="submit">연결이 맞아요 (확인 완료)</button></form><?php endif ?>
    <?php if ($inv['closed']): ?><?= $f('reopen') ?><button class="btn ghost" type="submit">미정산으로 되돌리기</button></form>
    <?php elseif ($v['remain'] > 0): ?><?= $f('close', ' class="inline"') ?>
      <select name="reason" aria-label="완료 사유"><option>상계 처리</option><option>대표·개인 카드로 결제</option><option>다른 통장으로 입출금</option><option>현금 결제</option><option>대손·포기</option><option>기타</option></select>
      <button class="btn ghost" type="submit">연결 없이 정산 완료 처리</button></form><?php endif ?>
  </div><?php endif ?>
</div>

<div class="box">
  <h2>연결된 거래</h2>
  <?php if ($v['links']): ?><div class="scroll"><table><tr><th>날짜</th><th>통장·카드</th><th>적요</th><th class="num">거래 금액</th><th class="num">이 계산서에 쓴 금액</th><th>방법</th><th></th></tr>
    <?php foreach ($v['links'] as $l): ?><tr><td><?= h($l['tx_date']) ?></td><td><?= h($l['acc_name']) ?></td><td><?= h($l['content']) ?></td><td class="num"><?= lg_won((int)$l['amount_in'] ?: (int)$l['amount_out']) ?></td><td class="num"><b><?= lg_won($l['amount']) ?></b></td><td style="font-size:13px"><?= h($l['method']) ?></td>
      <td><?php if (!$ceo): ?><?= $f('unlink', ' data-confirm="연결을 풀까요?"') ?><input type="hidden" name="link_id" value="<?= $l['id'] ?>"><button class="btn danger">풀기</button></form><?php endif ?></td></tr><?php endforeach ?>
  </table></div><?php else: ?><p class="help">아직 연결된 거래가 없어요.</p><?php endif ?>
</div>

<?php if ($v['remain'] > 0 && !$inv['closed']): ?>
<div class="box">
  <h2>연결할 거래 찾기</h2>
  <p class="help"><?= $inv['kind'] === '매출' ? '입금' : '출금·카드 사용' ?> 중 남은 금액과 같거나 거래처 이름이 비슷한 것을 보여드려요. 없으면 적요나 금액으로 검색하세요.</p>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="invoice"><input type="hidden" name="iid" value="<?= $inv['id'] ?>"><input type="hidden" name="back" value="<?= h($v['back']) ?>"><label>적요·금액 검색<input name="q" value="<?= h($v['q']) ?>" placeholder="예: 유비온 또는 128332600"></label><button class="btn ghost" type="submit">찾기</button></form>
  <?php if ($v['cands']): ?><div class="scroll"><table><tr><th>날짜</th><th>통장·카드</th><th>적요</th><th class="num">남은 거래 금액</th><th></th><th>연결할 금액</th></tr>
    <?php foreach ($v['cands'] as $c): ?><tr>
      <td><?= h($c['tx_date']) ?></td><td><?= h($c['acc_name']) ?></td>
      <td><?= h($c['content']) ?><?php if ($c['detail']): ?><br><span class="dbk">출납장: <?= h($c['detail']) ?></span><?php endif ?></td>
      <td class="num"><?= lg_won($c['free']) ?></td>
      <td style="font-size:12.5px"><?php if ($c['same']): ?><span class="badge b-green">금액 같음</span><?php endif ?><?php if ($c['score']): ?> <span class="badge b-blue">이름 비슷</span><?php endif ?></td>
      <td><?php if (!$ceo): ?><?= $f('link', ' class="bar-l"') ?><input type="hidden" name="tx_id" value="<?= $c['id'] ?>">
        <input name="amount" value="<?= min($c['free'], $v['remain']) ?>" inputmode="numeric" aria-label="연결할 금액" style="width:130px;text-align:right">
        <?php if ($v['partner'] && !$c['score']): ?><label class="row-l" style="font-size:12px;font-weight:400"><input type="checkbox" class="chk" name="learn" value="1" checked>이 적요 기억</label><?php endif ?>
        <button class="btn ghost">연결</button></form><?php endif ?></td>
    </tr><?php endforeach ?>
  </table></div><?php else: ?><p class="help">후보가 없어요. 검색해 보거나, 다른 방법으로 정산됐다면 위에서 "연결 없이 정산 완료 처리"를 해주세요.</p><?php endif ?>
  <p class="help">"이 적요 기억"을 켜두면, 통장에 찍힌 이름을 이 거래처의 다른 이름으로 기억해서 다음부터 자동으로 연결해요.</p>
</div>
<?php endif;
    return ob_get_clean();
}

function lg_view_prepaid(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    $act = lg_url('prepaid', ['pid' => $v['p']['id'], 'back' => $v['back']]);
    ob_start(); ?>
<?php if ($v['back']): ?><div class="crumb"><a href="<?= h($v['back']) ?>">← 증빙·정산으로</a></div><?php endif ?>
<div class="box">
  <p class="help">캐시·비즈머니처럼 <b>미리 충전해 두고 쓸 때마다 계산서가 나오는 곳</b>은, 계산서 금액을 충전 결제에서 차례로 빼서 정산해요. 오래된 계산서부터, 먼저 충전한 돈부터 써요.</p>
  <div class="result">
    <div>남은 충전금<strong class="in"><?= lg_won($v['balance']) ?></strong></div>
    <div>충전금에서 차감된 계산서<strong><?= count($v['done_invs']) ?>건</strong></div>
    <div>아직 정산 안 된 계산서<strong><?= count($v['open_invs']) ?>건</strong></div>
  </div>
</div>

<form method="post" action="<?= h($act) ?>"><?= lg_csrf_field() ?>
<input type="hidden" name="back" value="<?= h($v['back']) ?>">
<div class="cols" style="grid-template-columns:1fr 1fr">
  <div class="box">
    <h2>① 충전 결제 고르기</h2>
    <p class="help">적요나 출납장 내용에 "충전·캐시·비즈머니·크레딧"이 있는 결제는 미리 골라뒀어요. 충전인데 표시가 없는 결제(예: 통장 이체)는 직접 체크하세요.</p>
    <?php if ($v['charges']): ?><div class="scroll"><table><tr><th></th><th>날짜</th><th>통장·카드</th><th>내용</th><th class="num">충전액</th><th class="num">남은 금액</th></tr>
      <?php foreach ($v['charges'] as $c): ?><tr>
        <td><?php if ($c['remain'] > 0 && !$ceo): ?><input type="checkbox" class="chk" name="charge_id[]" value="<?= $c['id'] ?>"<?= $c['is_prepaid'] ? ' checked' : '' ?> aria-label="충전 결제로 사용"><?php endif ?></td>
        <td><?= h($c['tx_date']) ?></td><td style="font-size:13px"><?= h($c['acc_name']) ?></td>
        <td><?= h($c['content']) ?><?php if ($c['detail']): ?><br><span class="dbk">출납장: <?= h($c['detail']) ?></span><?php endif ?></td>
        <td class="num"><?= lg_won($c['amount_out']) ?></td><td class="num"><b><?= lg_won($c['remain']) ?></b></td></tr><?php endforeach ?>
    </table></div><?php else: ?><p class="help">이 거래처 이름으로 나간 결제를 못 찾았어요.</p><?php endif ?>
  </div>
  <div class="box">
    <h2>② 차감할 계산서 고르기</h2>
    <p class="help">정산 안 된 매입 계산서예요. 전에 "연결 없이 정산 완료"로 처리한 충전금 건도 여기 다시 보여드려요.</p>
    <?php if ($v['open_invs']): ?><div class="scroll"><table><tr><th><?php if (!$ceo): ?><input type="checkbox" class="chk" checked aria-label="모두 선택" data-pick-all=".inv"><?php endif ?></th><th>작성일</th><th>품목</th><th class="num">남은 금액</th></tr>
      <?php foreach ($v['open_invs'] as $i): ?><tr><td><?php if (!$ceo): ?><input type="checkbox" class="inv chk" name="inv_id[]" value="<?= $i['id'] ?>" checked aria-label="차감할 계산서"><?php endif ?></td>
        <td><?= h($i['write_date']) ?></td><td style="font-size:13px"><?= h($i['item']) ?><?php if ($i['closed']): ?><br><span class="sub-s"><?= h($i['closed']) ?></span><?php endif ?></td><td class="num"><?= lg_won((int)$i['total'] - (int)$i['paid']) ?></td></tr><?php endforeach ?>
    </table></div><?php else: ?><p class="help">정산할 계산서가 없어요.</p><?php endif ?>
  </div>
</div>
<?php if (!$ceo): ?><div class="box"><div><button class="btn" type="submit">고른 계산서를 충전금에서 차감</button></div>
  <p class="help" style="margin:0">충전일이 계산서보다 한 달 넘게 늦은 충전금은 그 계산서에 쓰지 않아요. 충전금이 모자란 계산서는 그대로 남고, 얼마가 부족한지 알려드려요.</p></div><?php endif ?>
</form>

<?php if ($v['done_invs']): ?>
<div class="box">
  <h2>충전금에서 차감된 계산서</h2>
  <div class="scroll"><table><tr><th>작성일</th><th>품목</th><th class="num">금액</th></tr>
    <?php foreach ($v['done_invs'] as $i): ?><tr><td><?= h($i['write_date']) ?></td><td style="font-size:13px"><?= h($i['item']) ?></td><td class="num"><?= lg_won($i['total']) ?></td></tr><?php endforeach ?>
  </table></div>
  <?php if (!$ceo): ?><form method="post" action="<?= h($act) ?>" data-confirm="이 거래처의 충전금 차감을 모두 풀까요?"><?= lg_csrf_field() ?><input type="hidden" name="do" value="unlink_all"><input type="hidden" name="back" value="<?= h($v['back']) ?>"><button class="btn danger" type="submit">충전금 차감 모두 풀기</button></form><?php endif ?>
</div>
<?php endif;
    return ob_get_clean();
}

function lg_view_partners(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    $dis = $ceo ? ' disabled' : '';
    $total = array_sum($v['counts']);
    ob_start(); ?>
<?= lg_settle_tabs('partners') ?>
<div class="box">
  <p class="help">세금계산서에서 자동으로 모은 거래처예요. <b>담당자·전화·메일주소·메모</b>는 바로 적고 맨 아래 [저장]을 누르면 한 번에 저장돼요. 메일주소는 세금계산서를 받는(보내는) 주소예요.<br>
  거래처 이름 아래 작은 칸은 <b>통장에 다르게 찍히는 이름</b>(예: 한기대, NBP)이에요. 적어두면 자동 정산이 더 잘 돼요. 여러 개는 쉼표로 이어주세요.</p>
  <div class="bar-l">
    <?php foreach (['' => '전체', '매출처' => '매출처', '매입처' => '매입처', '매출·매입' => '매출·매입 둘 다'] as $g => $label): ?>
    <a class="btn <?= $v['sel'] === $g ? '' : 'ghost' ?>" href="<?= h(lg_url('partners', ['group' => $g, 'q' => $v['q']])) ?>"><?= h($label) ?> <?= $g !== '' ? $v['counts'][$g] : $total ?>곳</a>
    <?php endforeach ?>
  </div>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="partners"><input type="hidden" name="group" value="<?= h($v['sel']) ?>"><label>찾기<input name="q" value="<?= h($v['q']) ?>" placeholder="상호, 사업자번호, 담당자"></label><button class="btn ghost" type="submit">찾기</button></form>
</div>
<div class="box">
  <?php if ($v['rows']): ?>
  <form method="post" action="<?= h(lg_url('partners', ['q' => $v['q'], 'group' => $v['sel']])) ?>"><?= lg_csrf_field() ?>
    <?php if (!$ceo): ?><div style="display:flex;justify-content:flex-end"><button class="btn" type="submit">저장</button></div><?php endif ?>
    <div class="scroll"><table>
      <tr><th>거래처</th><th>사업자번호</th><th>대표자</th><th>주소</th><th>담당자</th><th>전화</th><th>메일주소 (세금계산서)</th><th>메모</th></tr>
      <?php $prevG = null; foreach ($v['rows'] as $p): ?>
      <?php if ($p['group'] !== $prevG): $prevG = $p['group']; ?>
      <tr class="grp"><td colspan="8"><?= h($p['group'] !== '매출·매입' ? $p['group'] : '매출·매입 둘 다') ?> · <?= $v['counts'][$p['group']] ?>곳</td></tr>
      <?php endif ?>
      <tr>
        <td style="white-space:normal;min-width:180px"><input type="hidden" name="pid[]" value="<?= $p['id'] ?>"><b><?= h($p['name']) ?></b>
          <input name="aliases_<?= $p['id'] ?>" value="<?= h($p['aliases']) ?>" placeholder="통장 표시 이름" aria-label="<?= h($p['name']) ?> 통장에 찍히는 다른 이름" style="display:block;margin-top:6px;width:100%"<?= $dis ?>></td>
        <td style="font-size:13px"><?= h(substr((string)$p['biz_no'], 0, 3) . '-' . substr((string)$p['biz_no'], 3, 2) . '-' . substr((string)$p['biz_no'], 5)) ?></td>
        <td style="font-size:13px;white-space:normal;max-width:110px"><?= h($p['ceo']) ?></td>
        <td class="sub-s" style="white-space:normal;min-width:200px;max-width:260px"><?= h($p['address']) ?></td>
        <td><input name="manager_<?= $p['id'] ?>" value="<?= h($p['manager']) ?>" aria-label="<?= h($p['name']) ?> 담당자" style="width:100px"<?= $dis ?>></td>
        <td><input name="phone_<?= $p['id'] ?>" value="<?= h($p['phone']) ?>" inputmode="tel" aria-label="<?= h($p['name']) ?> 전화" style="width:130px"<?= $dis ?>></td>
        <td><input name="email_<?= $p['id'] ?>" value="<?= h($p['email']) ?>" type="email" aria-label="<?= h($p['name']) ?> 메일주소" style="width:210px"<?= $dis ?>></td>
        <td><input name="memo_<?= $p['id'] ?>" value="<?= h($p['memo']) ?>" aria-label="<?= h($p['name']) ?> 메모" style="width:160px"<?= $dis ?>></td>
      </tr>
      <?php endforeach ?>
    </table></div>
    <?php if (!$ceo): ?><div style="display:flex;justify-content:flex-end"><button class="btn" type="submit">저장</button></div><?php endif ?>
  </form>
  <?php else: ?><p class="help">거래처가 없어요. <a href="<?= h(lg_url('invoice_import')) ?>">세금계산서 가져오기</a>로 자동 등록하세요.</p><?php endif ?>
</div>
<?php
    return ob_get_clean();
}

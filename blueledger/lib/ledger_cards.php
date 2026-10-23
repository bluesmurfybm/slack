<?php
/**
 * BlueLedger 회계 장부 — ⑤ 카드대금 맞춰보기 (파이썬 card_check·card_adjust + templates/card_check.html).
 *  결제일별 청구분(lg_card_bills)과 통장에서 낸 카드대금을 비교하고, 명세서에만 있는 금액(연회비·환율 차이)은
 *  그 결제분에 한 줄로 넣어 맞춘다.
 */

require_once __DIR__ . '/ledger_views.php';

function lg_page_card_check(array $q, array $me) {
    $aid = (int)($q['aid'] ?? 0);
    $lead = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=? AND kind='카드'", [$aid]);
    if (!$lead) return ['__redirect' => lg_url('accounts')];
    [$cards, $bills, $pay_list, $pend] = lg_card_bills($aid);
    $done = array_filter($bills, fn($b) => $b['status'] === '결제 완료');
    return ['__heading' => '카드대금 맞춰보기 · ' . $lead['name'] . (count($cards) > 1 ? ' 외 ' . (count($cards) - 1) . '장' : ''),
            'lead' => $lead, 'cards' => $cards, 'bills' => $bills, 'pay_list' => $pay_list,
            'bad' => array_values(array_filter($done, fn($b) => $b['diff'] !== 0)), 'pay_day' => (int)$lead['pay_day'] ?: 23,
            'pend' => $pend, 'options' => lg_category_options()];
}

/** 명세서에만 있는 금액(연회비, 환율 차이 등)을 그 결제분에 한 줄로 넣어 맞춤 */
function lg_post_card_check(array $p, array $me) {
    $aid = (int)($_GET['aid'] ?? 0);
    $back = lg_url('card_check', ['aid' => $aid]);
    $lead = lg_row("SELECT * FROM bl_ledger_accounts WHERE id=? AND kind='카드'", [$aid]);
    $due = lg_valid_date($p['due'] ?? '') ? $p['due'] : '';
    $diff = lg_to_int($p['diff'] ?? '');
    $memo = lg_strip($p['memo'] ?? '') ?: '명세서 차이';
    $cat = (string)($p['category'] ?? '');
    if (!$lead || !$due || !$diff) return [[], $back];
    $ym = lg_ym_add(substr($due, 0, 7), -1);
    if (in_array($ym, lg_closed_months(), true)) return [[['error', '조정이 들어갈 달이 마감돼 있어요. 월마감에서 마감을 풀고 해주세요.']], $back];
    lg_exec("INSERT INTO bl_ledger_tx(account_id, tx_date, content, amount_in, amount_out, category, source_file, dedup_key, bill_due, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$aid, "$ym-28", "$memo ($due 결제분 조정)", max(0, -$diff), max(0, $diff), $cat, LG_ADJUST, 'ADJ|' . bin2hex(random_bytes(16)), $due, date('Y-m-d H:i:s')]);
    lg_log($me, '카드대금 맞추기', "{$lead['name']} $due 결제분 $memo $diff");
    return [[['ok', "$due 결제분에 '$memo' " . lg_won(abs($diff)) . '원을 넣어 맞췄어요.']], $back];
}

function lg_view_card_check(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    $act = lg_url('card_check', ['aid' => $v['lead']['id']]);
    ob_start(); ?>
<div class="box">
  <p class="help">결제일별 청구분으로 묶어서 통장 결제와 비교해요. 카드사 명세서로 가져온 거래는 <b>결제예정일자</b> 그대로, 그 밖의 거래는 <b>이용월 1일~말일 → 다음 달 <?= $v['pay_day'] ?>일</b>로 계산해요. 통장의 선결제와 주말로 밀린 출금도 가장 가까운 결제일 몫으로 묶어요.</p>
  <?php if ($v['bad']): ?><div class="flash error">결제 완료된 <?= count($v['bad']) ?>개 결제분에서 청구 금액과 통장 결제가 달라요. 명세서에만 있는 금액(연회비, 환율 차이 등)이면 아래 [맞추기]로 넣으세요.</div>
  <?php else: ?><div class="flash ok">결제 완료된 결제분은 모두 원 단위까지 맞아요.</div><?php endif ?>
  <div class="scroll"><table>
    <tr><th>결제일</th><?php foreach ($v['cards'] as $c): ?><th class="num"><?= h($c['name']) ?></th><?php endforeach ?><th class="num">청구 금액</th><th class="num">통장에서 낸 금액</th><th class="num">차이<br>(낸 금액 − 청구)</th><th>상태</th><th></th></tr>
    <?php foreach ($v['bills'] as $b): $done = $b['status'] === '결제 완료'; ?>
    <tr>
      <td><?= h($b['due']) ?><br><span class="sub-s">주로 <?= (int)substr($b['use_ym'], 5) ?>월 사용분</span></td>
      <?php foreach ($b['per'] as $x): ?><td class="num"><?= $x ? lg_won($x) : '-' ?></td><?php endforeach ?>
      <td class="num"><b><?= lg_won($b['total']) ?></b></td>
      <td class="num"><?= $b['paid'] ? lg_won($b['paid']) : '-' ?></td>
      <td class="num"><?php if (!$done): ?><span class="sub-s">남은 금액 <?= lg_won($b['remain']) ?></span><?php elseif ($b['diff'] === 0): ?><span class="badge b-green">0</span><?php else: ?><span class="badge <?= $b['diff'] > 0 ? 'b-blue' : 'b-orange' ?>"><?= $b['diff'] > 0 ? '+' : '' ?><?= lg_won($b['diff']) ?></span><?php endif ?></td>
      <td><span class="badge <?= $done ? 'b-green' : 'b-orange' ?>"><?= h($b['status']) ?></span></td>
      <td><?php if ($done && $b['diff'] !== 0 && !$ceo): ?>
        <form method="post" action="<?= h($act) ?>" class="bar-l"><?= lg_csrf_field() ?>
          <input type="hidden" name="due" value="<?= h($b['due']) ?>"><input type="hidden" name="diff" value="<?= $b['diff'] ?>">
          <input name="memo" placeholder="<?= $b['diff'] > 0 ? '연회비' : '환급' ?>" aria-label="무슨 금액인지" style="width:120px;min-height:34px;padding:4px 8px">
          <select name="category" aria-label="계정과목" style="min-height:34px;padding:4px 8px"><?= lg_cat_options($v['options'], '지급수수료') ?></select>
          <button class="btn ghost" style="min-height:34px;padding:4px 12px;font-size:13px">맞추기</button>
        </form><?php endif ?></td>
    </tr>
    <?php endforeach ?>
    <?php if (array_sum($v['pend'])): ?>
    <tr><td>매입 대기<br><span class="sub-s">결제일 아직 안 정해짐</span></td><?php foreach ($v['pend'] as $x): ?><td class="num"><?= $x ? lg_won($x) : '-' ?></td><?php endforeach ?><td class="num"><b><?= lg_won(array_sum($v['pend'])) ?></b></td><td></td><td></td><td><span class="badge b-gray">매입 전</span></td><td></td></tr>
    <?php endif ?>
  </table></div>
  <p class="help"><b class="in">+ 플러스</b>: 청구보다 많이 냄 → 명세서에만 있는 금액(연회비, 해외 수수료 차이 등)이 있어요. <b class="out">− 마이너스</b>: 청구보다 적게 냄 → 결제가 연결 안 됐거나 취소·할인이 반영된 경우예요.<br>
  "매입 대기"는 승인은 됐지만 가맹점이 아직 청구하지 않은 건이에요. 다음에 명세서를 다시 가져오면 결제일이 채워져요.</p>
</div>
<div class="box">
  <h2>통장에서 연결된 카드대금 결제</h2>
  <?php if ($v['pay_list']): ?>
  <div class="scroll"><table>
    <tr><th>빠져나간 날</th><th>통장</th><th>적요</th><th class="num">금액</th><th>어느 결제분</th></tr>
    <?php foreach ($v['pay_list'] as $x): ?><tr><td><?= h($x['tx_date']) ?></td><td><?= h($x['bank']) ?></td><td><?= h($x['content']) ?></td><td class="num"><?= lg_won($x['amount_out']) ?></td><td><?= h($x['due']) ?></td></tr><?php endforeach ?>
  </table></div>
  <?php else: ?><p class="help">연결된 결제가 없어요. 기준정보의 카드대금 연결에서 글자와 시작일을 확인해 주세요.</p><?php endif ?>
</div>
<?php
    return ob_get_clean();
}

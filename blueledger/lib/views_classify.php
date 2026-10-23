<?php
/**
 * BlueLedger — ④ 분류·계정과목·거래 나누기·수기 입력 화면 HTML
 * (파이썬 templates/classify.html, classify_groups.html, categories.html, split.html, manual.html 을 같은 문구로).
 */

require_once __DIR__ . '/ledger_views.php';
require_once __DIR__ . '/ledger_classify.php';

function lg_classify_tabs($on) {
    $tabs = ['classify_groups' => '적요별로 묶어서', 'classify' => '한 건씩 보기', 'categories' => '계정과목·규칙 관리'];
    $o = '<nav class="subtabs">';
    foreach ($tabs as $p => $label) $o .= '<a class="' . ($p === $on ? 'on' : '') . '" href="' . h(lg_url($p)) . '">' . h($label) . '</a>';
    return $o . '</nav>';
}

function lg_acc_select(array $accounts, $aid) {
    $o = '<label>계좌·카드<select name="account_id"><option value="">전체</option>';
    foreach ($accounts as $a) $o .= '<option value="' . $a['id'] . '"' . lg_sel($aid, $a['id']) . '>[' . h($a['kind']) . '] ' . h($a['name']) . '</option>';
    return $o . '</select></label>';
}

function lg_view_classify(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    $dis = $ceo ? ' disabled' : '';
    ob_start(); ?>
<?= lg_classify_tabs('classify') ?>
<div class="box">
  <p class="help" style="margin:0">아직 계정과목이 없는 거래가 <b><?= $v['uncategorized'] ?>건</b> 있어요. 계정과목을 고르고 맨 아래 [저장]을 누르세요.</p>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="classify">
    <label>시작일<input type="date" name="start" value="<?= $v['start'] !== '2000-01-01' ? h($v['start']) : '' ?>"></label>
    <label>종료일<input type="date" name="end" value="<?= h($v['end']) ?>"></label>
    <?= lg_acc_select($v['accounts'], $v['aid']) ?>
    <label>적요 검색<input name="q" value="<?= h($v['kw']) ?>" placeholder="예: 쿠팡"></label>
    <label>보기<select name="show"><option<?= lg_sel($v['show'], '미분류') ?>>미분류</option><option<?= lg_sel($v['show'], '전체') ?>>전체</option></select></label>
    <button class="btn" type="submit">조회</button>
  </form>
</div>

<div class="box">
  <?php if ($v['rows']): ?>
  <p class="help"><b>"앞으로도"</b>에 체크하면 같은 적요가 나올 때마다 자동으로 그 계정과목이 붙어요. 적요 칸의 글자를 줄여서(예: "스타벅스 청주점" → "스타벅스") 더 넓게 적용할 수도 있어요.
  <?php if ($v['total'] > count($v['rows'])): ?><br>한 번에 <?= count($v['rows']) ?>건씩 보여드려요. 저장하면 다음 건들이 나와요. (전체 <?= $v['total'] ?>건)<?php endif ?></p>
  <form method="post" action="<?= h(lg_url('classify', ['start' => $v['start'] !== '2000-01-01' ? $v['start'] : '', 'end' => $v['end'], 'account_id' => $v['aid'], 'q' => $v['kw'], 'show' => $v['show']])) ?>"><?= lg_csrf_field() ?>
    <div class="scroll"><table>
      <tr><th>날짜</th><th>계좌·카드</th><th>적요 (규칙에 쓸 글자)</th><th class="num">입금</th><th class="num">출금</th><th>계정과목</th><th>앞으로도</th><th></th></tr>
      <?php foreach ($v['rows'] as $r): ?>
      <tr>
        <td><?= h($r['tx_date']) ?></td><td><?= h($r['acc_name']) ?></td>
        <td><input type="hidden" name="tx_id[]" value="<?= $r['id'] ?>"><input type="hidden" name="old_<?= $r['id'] ?>" value="<?= h($r['category']) ?>">
          <input name="kw_<?= $r['id'] ?>" value="<?= h($r['content']) ?>" aria-label="규칙에 쓸 글자" class="cell-in" style="min-width:220px"<?= $dis ?>><?php if ($r['memo']): ?><br><span class="sub-s">· <?= h($r['memo']) ?></span><?php endif ?><?php if ($r['note']): ?><br><span class="memo">메모: <?= h($r['note']) ?></span><?php endif ?><?php if ($r['detail']): ?><br><span class="dbk">출납장: <?= h($r['detail']) ?></span><?php endif ?></td>
        <td class="num in"><?= lg_won_or_blank($r['amount_in']) ?></td>
        <td class="num out"><?= lg_won_or_blank($r['amount_out']) ?></td>
        <td><select name="cat_<?= $r['id'] ?>" aria-label="계정과목" class="cell-sel"<?= $dis ?>>
          <option value="">(미분류)</option><?= lg_cat_options($v['options'], $r['category']) ?>
          <?php if ($r['category'] !== '' && !isset($v['all_names'][$r['category']])): ?><option selected><?= h($r['category']) ?></option><?php endif ?>
        </select></td>
        <td style="text-align:center"><input type="checkbox" class="chk" name="rule_<?= $r['id'] ?>" value="1" aria-label="같은 적요는 앞으로도 이 계정과목"<?= $dis ?>></td>
        <td><?php if (!$r['linked_card_id']): ?><a href="<?= h(lg_url('split', ['tid' => $r['id']])) ?>" style="font-size:13px;white-space:nowrap"><?= ($r['memo'] || $r['split_of']) ? '나눔 보기' : '나누기' ?></a><?php endif ?></td>
      </tr>
      <?php endforeach ?>
    </table></div>
    <?php if (!$ceo): ?><div><button class="btn" type="submit">저장</button></div><?php endif ?>
  </form>
  <?php else: ?>
  <p class="help"><?= $v['show'] === '미분류' ? '미분류 거래가 없어요. 모두 정리됐어요!' : '이 조건에 맞는 거래가 없어요.' ?></p>
  <?php endif ?>
  <?php if (!$ceo): ?>
  <form method="post" action="<?= h(lg_url('classify')) ?>" class="period-del"><?= lg_csrf_field() ?>
    <input type="hidden" name="do" value="rerun">
    <span class="help">규칙을 바꾼 뒤에는 이 버튼으로 미분류 거래 전체에 다시 적용할 수 있어요.</span>
    <button class="btn ghost" type="submit">규칙 다시 적용</button>
  </form>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_classify_groups(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    $cut = fn($s) => mb_strlen($s) > 80 ? mb_substr($s, 0, 80) . '…' : $s;
    ob_start(); ?>
<?= lg_classify_tabs('classify_groups') ?>
<div class="box">
  <div class="bar">
    <p class="help" style="margin:0">미분류 <b><?= $v['uncategorized'] ?>건</b>을 같은 적요끼리 <b><?= $v['total_groups'] ?>묶음</b>으로 모았어요. 많이 나오는 것부터 보여드려요.</p>
    <a class="btn ghost" href="<?= h(lg_url('ledger_import')) ?>">출납장으로 자동 분류</a>
  </div>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="classify_groups">
    <?= lg_acc_select($v['accounts'], $v['aid']) ?>
    <button class="btn" type="submit">조회</button>
  </form>
  <p class="help">계정과목을 고른 묶음만 저장돼요. 고르지 않은 묶음은 그대로 남아요.<br>
  <b>앞으로도</b>: 체크하면 같은 적요가 다음 달에 들어올 때도 자동으로 분류돼요. 쿠팡처럼 그때그때 쓰임이 다른 곳은 체크하지 말고 [펼쳐보기]로 한 건씩 고르세요.</p>
</div>

<div class="box">
  <?php if ($v['groups']): ?>
  <form method="post" action="<?= h(lg_url('classify_groups', ['account_id' => $v['aid']])) ?>"><?= lg_csrf_field() ?>
    <div class="scroll"><table>
      <tr><th>적요 (규칙에 쓸 글자)</th><th class="num">건수</th><th class="num">금액 합계</th><th>기간</th><th>계좌·카드</th><th>계정과목</th><th>앞으로도</th><th></th></tr>
      <?php foreach ($v['groups'] as $i => $g): $gid = $i + 1; $in = $g['dir'] === '입금'; ?>
      <tr>
        <td><input type="hidden" name="gid[]" value="<?= $gid ?>"><input type="hidden" name="content_<?= $gid ?>" value="<?= h($g['content']) ?>"><input type="hidden" name="dir_<?= $gid ?>" value="<?= h($g['dir']) ?>">
          <input name="kw_<?= $gid ?>" value="<?= h($g['content']) ?>" aria-label="규칙에 쓸 글자" class="cell-in" style="min-width:220px"<?= $ceo ? ' disabled' : '' ?>><?php if ($g['details']): ?><br><span class="dbk" style="white-space:normal">출납장: <?= h($cut($g['details'])) ?></span><?php endif ?><?php if ($g['notes']): ?><br><span class="memo" style="white-space:normal">메모: <?= h($cut($g['notes'])) ?></span><?php endif ?></td>
        <td class="num"><b><?= (int)$g['n'] ?></b></td>
        <td class="num <?= $in ? 'in' : 'out' ?>"><?= lg_won($in ? $g['sin'] : $g['sout']) ?><br><span style="font-size:12px"><?= h($g['dir']) ?></span></td>
        <td style="font-size:13px"><?= h($g['first']) ?><?= $g['last'] !== $g['first'] ? '<br>~ ' . h($g['last']) : '' ?></td>
        <td style="font-size:13px;white-space:normal;max-width:200px"><?= h($g['accs']) ?></td>
        <td><select name="cat_<?= $gid ?>" aria-label="계정과목" class="cell-sel"<?= $ceo ? ' disabled' : '' ?>><option value="">(고르지 않음)</option><?= lg_cat_options($v['options']) ?></select></td>
        <td style="text-align:center"><input type="checkbox" class="chk" name="rule_<?= $gid ?>" value="1" aria-label="앞으로도 이 계정과목"<?= (int)$g['n'] >= 3 ? ' checked' : '' ?><?= $ceo ? ' disabled' : '' ?>></td>
        <td><a href="<?= h(lg_url('classify', ['q' => $g['content'], 'show' => '미분류'])) ?>" style="font-size:13px;white-space:nowrap">펼쳐보기</a></td>
      </tr>
      <?php endforeach ?>
    </table></div>
    <?php if ($v['total_groups'] > count($v['groups'])): ?><p class="help">한 번에 <?= count($v['groups']) ?>묶음까지 보여드려요. 저장하면 다음 묶음이 나와요.</p><?php endif ?>
    <?php if (!$ceo): ?><div><button class="btn" type="submit">고른 묶음 저장</button></div><?php endif ?>
  </form>
  <?php else: ?>
  <p class="help">미분류 거래가 없어요. 모두 정리됐어요!</p>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_categories(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    ob_start(); ?>
<?= lg_classify_tabs('categories') ?>
<div class="box">
  <h2>계정과목</h2>
  <p class="help">지금은 소프트웨어 회사에서 흔히 쓰는 <b>기본 목록</b>이에요. 세무사 사무실 계정과목표를 받으면 그 표에 맞춰 바꿀 예정이에요.<br>
  <b>자금이동</b>은 돈이 옮겨갈 뿐 비용·수익이 아닌 것(카드대금 결제, 계좌 간 이체 등)이라 나중에 손익 보고서에서 빠져요. 안 쓰는 과목은 [숨기기]하면 분류 목록에서 사라져요.</p>
  <?php if (!$ceo): ?>
  <form class="inline" method="post" action="<?= h(lg_url('categories')) ?>"><?= lg_csrf_field() ?>
    <input type="hidden" name="do" value="add_cat">
    <label>새 계정과목<input name="name" placeholder="예: 소프트웨어 구독료" required></label>
    <label>구분<select name="grp"><?php foreach ($v['groups'] as $g): ?><option<?= lg_sel($g, '비용') ?>><?= h($g) ?></option><?php endforeach ?></select></label>
    <button class="btn" type="submit">추가</button>
  </form>
  <?php endif ?>
  <?php foreach ($v['groups'] as $g): ?>
  <div>
    <div style="font-weight:700;margin:8px 0"><?= h($g) ?></div>
    <div class="chips">
      <?php foreach ($v['cats'] as $c): if ($c['grp'] !== $g) continue; ?>
      <form method="post" action="<?= h(lg_url('categories')) ?>" class="chip-f<?= $c['hidden'] ? ' off' : '' ?>"><?= lg_csrf_field() ?>
        <input type="hidden" name="do" value="toggle_cat"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <span><?= h($c['name']) ?><?php if ((int)$c['used']): ?> <span class="sub-s">· <?= (int)$c['used'] ?>건</span><?php endif ?></span>
        <?php if (!$ceo): ?><button class="btn ghost"><?= $c['hidden'] ? '다시 보이기' : '숨기기' ?></button><?php endif ?>
      </form>
      <?php endforeach ?>
    </div>
  </div>
  <?php endforeach ?>
</div>

<div class="box">
  <h2>자동 분류 규칙</h2>
  <p class="help">적요에 이 글자가 들어 있으면 자동으로 계정과목이 붙어요. 분류 화면에서 "앞으로도"를 체크해도 여기에 추가돼요.</p>
  <?php if (!$ceo): ?>
  <form class="inline" method="post" action="<?= h(lg_url('categories')) ?>"><?= lg_csrf_field() ?>
    <input type="hidden" name="do" value="add_rule">
    <label>적요에 들어간 글자<input name="keyword" placeholder="예: 스타벅스" required></label>
    <label>계정과목<select name="category"><?= lg_cat_options($v['options']) ?></select></label>
    <label>방향<select name="direction"><option>전체</option><option>출금</option><option>입금</option></select></label>
    <button class="btn" type="submit">규칙 추가</button>
  </form>
  <?php endif ?>
  <?php if ($v['rules']): ?>
  <div class="scroll"><table>
    <tr><th>적요에 들어간 글자</th><th>계정과목</th><th>방향</th><th></th></tr>
    <?php foreach ($v['rules'] as $r): ?>
    <tr><td><?= h($r['keyword']) ?></td><td><?= h($r['category']) ?></td><td><?= h($r['direction']) ?></td>
      <td><?php if (!$ceo): ?><form method="post" action="<?= h(lg_url('categories')) ?>" data-confirm="이 규칙을 지울까요?"><?= lg_csrf_field() ?><input type="hidden" name="do" value="del_rule"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn danger">지우기</button></form><?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_split(array $v, array $me) {
    $t = $v['t'];
    $in = $v['is_in'];
    $ceo = $me['role'] === LG_ROLE_CEO;
    $act = lg_url('split', ['tid' => $t['id']]);
    ob_start(); ?>
<div class="box">
  <div class="result">
    <div>날짜<strong style="font-size:18px"><?= h($t['tx_date']) ?></strong></div>
    <div>계좌·카드<strong style="font-size:18px"><?= h($t['acc_name']) ?></strong></div>
    <div>적요<strong style="font-size:18px"><?= h($t['content']) ?></strong></div>
    <div><?= $in ? '입금' : '출금' ?> 금액<strong class="<?= $in ? 'in' : 'out' ?>"><?= lg_won($v['total']) ?>원</strong></div>
  </div>
  <p class="help">한 번에 빠져나간 돈을 성격별로 나눠요. 나눠도 통장 잔액은 그대로이고, 계정과목만 줄마다 따로 붙어요. 나눈 금액의 합계가 원래 금액과 같아야 저장돼요.</p>
</div>

<?php if ($v['current']): ?>
<div class="box">
  <h2>지금 나뉜 상태</h2>
  <div class="scroll"><table>
    <tr><th>설명</th><th class="num">금액</th><th>계정과목</th></tr>
    <?php foreach ($v['current'] as $x): ?><tr><td><?= h($x['memo'] ?: '-') ?></td><td class="num"><?= lg_won($in ? $x['amount_in'] : $x['amount_out']) ?></td><td><?= h($x['category'] ?: '미분류') ?></td></tr><?php endforeach ?>
  </table></div>
  <?php if (!$ceo): ?><form method="post" action="<?= h($act) ?>" data-confirm="나누기를 취소하고 한 건으로 되돌릴까요?"><?= lg_csrf_field() ?><input type="hidden" name="do" value="undo"><button class="btn danger" type="submit">나누기 취소 (한 건으로 되돌리기)</button></form><?php endif ?>
  <p class="help">금액을 고치려면 아래에서 다시 나누면 돼요. 새로 저장하면 지금 상태를 덮어써요.</p>
</div>
<?php endif ?>

<?php if (!$ceo): ?>
<div class="box">
  <h2><?= $v['current'] ? '다시 나누기' : '나누기' ?></h2>
  <div class="bar-l">
    <span class="help">줄 구성 불러오기:</span>
    <?php if ($v['last_rows']): ?><button class="btn" type="button" data-preset="__last">지난번 나눈 방식</button><?php endif ?>
    <?php foreach (array_keys($v['presets']) as $name): ?><button class="btn <?= ($name === $v['suggested'] && !$v['last_rows']) ? '' : 'ghost' ?>" type="button" data-preset="<?= h($name) ?>"><?= h($name) ?></button><?php endforeach ?>
    <button class="btn ghost" type="button" id="half">위 두 줄 반반으로</button>
  </div>
  <form method="post" action="<?= h($act) ?>" id="splitForm" style="display:flex;flex-direction:column;gap:12px"><?= lg_csrf_field() ?>
    <div class="scroll"><table>
      <tr><th>설명</th><th class="num">금액(원)</th><th>계정과목</th></tr>
      <?php for ($i = 0; $i < 6; $i++): ?>
      <tr>
        <td><input name="memo[]" aria-label="설명" style="min-width:240px"></td>
        <td><input name="amount[]" inputmode="numeric" aria-label="금액" class="amt" style="width:150px;text-align:right"></td>
        <td><select name="category[]" aria-label="계정과목"><option value="">(미분류)</option><?= lg_cat_options($v['options']) ?></select></td>
      </tr>
      <?php endfor ?>
    </table></div>
    <div class="help" id="remain" style="font-size:15px"></div>
    <div><button class="btn" type="submit">저장</button></div>
  </form>
  <p class="help">고지서의 <b>근로자 부담금</b>을 직원 부담분 줄에, <b>사용자 부담금</b>을 회사 부담분 줄에 넣으세요. 국민연금·건강보험은 보통 반반이라 [위 두 줄 반반으로]를 누르면 바로 채워져요(고지서와 몇십 원 다르면 고쳐주세요). 고용보험은 회사 부담이 더 크니 고지서 금액을 그대로 넣어주세요. 산재보험은 전액 회사 부담이라 나누지 않고 보험료로 자동 분류돼요.</p>
</div>
<script>
(function () {
  var total = <?= (int)$v['total'] ?>;
  var presets = <?= json_encode($v['presets'] + ['__last' => $v['last_rows']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var form = document.getElementById("splitForm");
  var memos = form.querySelectorAll('input[name="memo[]"]');
  var amts = form.querySelectorAll('input[name="amount[]"]');
  var cats = form.querySelectorAll('select[name="category[]"]');
  function num(v) { return parseInt(String(v).replace(/[^0-9]/g, ""), 10) || 0; }
  function update() {
    var sum = 0;
    amts.forEach(function (a) { sum += num(a.value); });
    var left = total - sum;
    var el = document.getElementById("remain");
    el.textContent = "나눈 합계 " + sum.toLocaleString() + "원 · 남은 금액 " + left.toLocaleString() + "원";
    el.className = "help " + (left === 0 ? "in" : "warn");
  }
  amts.forEach(function (a) { a.addEventListener("input", update); });
  function load(name) {
    var rows = presets[name] || [];
    for (var i = 0; i < memos.length; i++) {
      memos[i].value = rows[i] ? rows[i][0] : "";
      cats[i].value = rows[i] ? rows[i][1] : "";
      amts[i].value = "";
    }
    update();
  }
  document.getElementById("half").addEventListener("click", function () {
    var a = Math.floor(total / 2);
    amts[0].value = a.toLocaleString(); amts[1].value = (total - a).toLocaleString();
    for (var i = 2; i < amts.length; i++) amts[i].value = "";
    update();
  });
  document.querySelectorAll("[data-preset]").forEach(function (b) {
    b.addEventListener("click", function () { load(b.getAttribute("data-preset")); });
  });
  <?php if (!$v['current']): ?>load(<?= json_encode($v['last_rows'] ? '__last' : $v['suggested'], JSON_UNESCAPED_UNICODE) ?>);<?php endif ?>
  update();
})();
</script>
<?php endif;
    return ob_get_clean();
}

function lg_view_manual(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    ob_start(); ?>
<?php if (!$ceo): ?>
<div class="box">
  <p class="help">엑셀로 들어오지 않는 거래를 직접 넣어요. <b>현금</b>으로 주고받은 돈은 "현금(시재)"을 고르세요(처음 고르면 자동으로 만들어져요). 통장·카드 거래를 미리 넣어두는 것도 돼요. 나중에 그 통장 엑셀을 가져오면 <b>같은 날·같은 금액의 수기 입력과 자동으로 합쳐져서</b> 두 번 들어가지 않아요.</p>
  <form method="post" action="<?= h(lg_url('manual')) ?>" style="display:flex;flex-direction:column;gap:12px"><?= lg_csrf_field() ?>
    <div class="inline" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <label>날짜<input type="date" name="tx_date" value="<?= h($v['today']) ?>" required></label>
      <label>어디서<select name="account_id">
        <?php if (!$v['has_cash']): ?><option value="cash">현금(시재) — 새로 만들기</option><?php endif ?>
        <?php foreach ($v['accounts'] as $a): ?><option value="<?= $a['id'] ?>"><?= $a['name'] === '현금(시재)' ? '[현금] ' : '[' . h($a['kind']) . '] ' ?><?= h($a['name']) ?></option><?php endforeach ?>
      </select></label>
      <label>구분<select name="dir"><option>출금</option><option>입금</option></select></label>
      <label>금액(원)<input name="amount" inputmode="numeric" required style="width:150px;text-align:right"></label>
    </div>
    <div class="inline" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <label>적요 (거래처·가맹점)<input name="content" placeholder="예: 다이소" style="width:200px"></label>
      <label>내용 (출납장에 들어갈 말)<input name="detail" placeholder="예: 사무실 청소용품 구입" style="width:280px"></label>
      <label>계정과목<select name="category"><option value="">(내용 보고 자동)</option><?= lg_cat_options($v['options']) ?></select></label>
      <label>메모<input name="memo" style="width:160px"></label>
      <button class="btn" type="submit">넣기</button>
    </div>
  </form>
</div>
<?php endif ?>
<div class="box">
  <h2>최근 수기 입력</h2>
  <?php if ($v['recent']): ?><div class="scroll"><table>
    <tr><th>날짜</th><th>어디서</th><th>적요</th><th>내용</th><th class="num">입금</th><th class="num">출금</th><th>계정과목</th><th></th></tr>
    <?php foreach ($v['recent'] as $t): ?><tr><td><?= h($t['tx_date']) ?></td><td style="font-size:13px"><?= h($t['acc_name']) ?></td><td><?= h($t['content']) ?></td><td class="dbk"><?= h($t['detail']) ?></td>
      <td class="num in"><?= lg_won_or_blank($t['amount_in']) ?></td><td class="num out"><?= lg_won_or_blank($t['amount_out']) ?></td><td style="font-size:13px"><?= h($t['category'] ?: '미분류') ?></td>
      <td><?php if (!$ceo): ?><form method="post" action="<?= h(lg_url('manual')) ?>" data-confirm="이 수기 입력을 지울까요?"><?= lg_csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn danger">지우기</button></form><?php endif ?></td></tr><?php endforeach ?>
  </table></div><?php else: ?><p class="help">아직 수기 입력한 거래가 없어요.</p><?php endif ?>
  <p class="help">통장 엑셀을 가져와서 합쳐진 거래는 이 목록에서 빠지고 일반 거래가 돼요. 내용·계정과목은 그대로 남아요.</p>
</div>
<?php
    return ob_get_clean();
}

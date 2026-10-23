<?php
/**
 * BlueLedger — ③ 거래 가져오기 화면 HTML (파이썬 templates/import_*.html, ledger_*.html, inbox*.html,
 * duplicates.html, imports.html 을 같은 문구로 옮김).
 */

require_once __DIR__ . '/ledger_views.php';
require_once __DIR__ . '/ledger_import.php';

/** 거래 가져오기 메뉴 안의 작은 탭 */
function lg_import_tabs($on) {
    $tabs = ['import' => '파일 하나 가져오기', 'inbox' => '여러 파일 한 번에', 'ledger_import' => '출납장 가져오기',
             'duplicates' => '중복 거래 찾기', 'imports' => '가져온 파일별로 보기'];
    $o = '<nav class="subtabs">';
    foreach ($tabs as $p => $label) $o .= '<a class="' . ($p === $on ? 'on' : '') . '" href="' . h(lg_url($p)) . '">' . h($label) . '</a>';
    return $o . '</nav>';
}

function lg_view_import(array $v, array $me) {
    ob_start(); ?>
<?= lg_import_tabs('import') ?>
<div class="box">
  <h2>1. 파일 선택</h2>
  <p class="help">인터넷뱅킹이나 카드사 홈페이지에서 내려받은 거래내역 파일(xlsx, xls, csv)을 올려주세요. 같은 파일을 두 번 올려도 이미 들어간 거래는 자동으로 건너뜁니다.</p>
  <?php if ($v['accounts']): ?>
  <form class="inline" method="post" action="<?= h(lg_url('import')) ?>" enctype="multipart/form-data"><?= lg_csrf_field() ?>
    <label>어느 통장·카드의 내역인가요?<select name="account_id"><?php foreach ($v['accounts'] as $a): ?><option value="<?= $a['id'] ?>">[<?= h($a['kind']) ?>] <?= h($a['name']) ?></option><?php endforeach ?></select></label>
    <label>파일<input type="file" name="file" accept=".xlsx,.xls,.csv" required></label>
    <button class="btn" type="submit">다음 →</button>
  </form>
  <p class="help">여러 파일을 한 번에 하려면 <a href="<?= h(lg_url('inbox')) ?>"><b>여러 파일 한 번에</b></a>에서 올리세요. 통장·카드·홈택스를 알아서 구분해요.</p>
  <p class="help">매일 쓰는 <b>일일출납장</b>이 있다면 <a href="<?= h(lg_url('ledger_import')) ?>">출납장 가져오기</a>로 상세 적요를 거래에 붙이고 자동 분류할 수 있어요.</p>
  <?php else: ?>
  <p class="help">먼저 <a href="<?= h(lg_url('accounts')) ?>">기준정보</a>에서 계좌나 카드를 등록해 주세요.</p>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_import_map(array $v, array $me) {
    if (isset($v['error'])) return '<div class="flash error">' . h($v['error']) . '</div><div><a class="btn" href="' . h(lg_url('import')) . '">파일 다시 올리기</a></div>';
    $acc = $v['acc'];
    $card = $v['is_card'];
    $fields = [['date', '날짜 (필수)'], ['content', '적요·가맹점'], ['in', '입금액'], ['out', '출금액·이용금액']];
    $fields = array_merge($fields, $card
        ? [['card', '카드번호 (있으면)'], ['appr', '승인번호'], ['cancel_amt', '취소금액'], ['cancel_date', '취소일자'], ['due', '결제예정일자'], ['buy_date', '매출표접수일자(매입일)'], ['usage_type', '이용구분(해외 여부)']]
        : [['bal', '거래 후 잔액'], ['note1', '메모 1'], ['note2', '메모 2'], ['note3', '메모 3']]);
    $keep = '<input type="hidden" name="token" value="' . h($v['token']) . '"><input type="hidden" name="account_id" value="' . $acc['id'] . '"><input type="hidden" name="name" value="' . h($v['fname']) . '">';
    ob_start(); ?>
<div class="box">
  <h2>2. 어떤 열이 무엇인지 알려주세요</h2>
  <p class="help"><b><?= h($v['fname']) ?></b> → <b>[<?= h($acc['kind']) ?>] <?= h($acc['name']) ?></b> · 데이터 <?= $v['total_rows'] ?>줄. 자동으로 짐작해서 골라뒀어요. 아래 미리보기를 보고 맞는지만 확인하세요. 한 번 맞춰두면 다음부터는 이 통장·카드에 같은 설정이 자동으로 적용돼요.</p>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="import_map"><?= $keep ?>
    <label>제목 줄은 몇 번째 줄인가요?<input type="number" name="header_row" min="1" value="<?= $v['header_row'] ?>" style="width:120px"></label>
    <button class="btn ghost" type="submit">다시 읽기</button>
  </form>
  <?php if ($card && (string)($v['guess']['due'] ?? '') !== ''): ?><div class="flash ok">카드사 명세서(승인내역) 형식이에요. 취소된 결제는 취소 줄로 따로 넣고, 결제예정일자로 몇 월 23일 결제분인지 정확히 묶어요. 해외 이용은 수수료(추정)도 함께 넣어요. 같은 BC카드 묶음 안의 카드는 카드번호 끝 4자리로 알아서 나눠 넣어요.</div><?php endif ?>
  <?php if (!$card): ?><p class="help"><b>메모 1~3</b>: 이체할 때 적어둔 메모, 거래기록사항, 상대 예금주명 같은 열을 골라주세요. 분류할 때 함께 보이고, 메모에 계정과목 이름(예: 복리후생비)이 있으면 자동으로 그 계정과목이 붙어요.</p><?php endif ?>
  <form method="post" action="<?= h(lg_url('import_map')) ?>" style="display:flex;flex-direction:column;gap:16px"><?= lg_csrf_field() . $keep ?>
    <input type="hidden" name="header_row" value="<?= $v['header_row'] ?>">
    <div class="inline" style="display:flex;gap:12px;flex-wrap:wrap">
      <?php foreach ($fields as [$key, $label]): ?>
      <label><?= h($label) ?><select name="<?= $key ?>"><option value="">(없음)</option>
        <?php foreach ($v['headers'] as $i => $hd): ?><option value="<?= $i ?>"<?= (string)($v['guess'][$key] ?? '') === (string)$i ? ' selected' : '' ?>><?= $i + 1 ?>열 · <?= h($hd !== '' ? $hd : '(제목 없음)') ?></option><?php endforeach ?>
      </select></label>
      <?php endforeach ?>
      <?php if ($card): ?><label>카드번호 열이 없으면, 이 파일 카드의 끝 4자리<input name="manual_card_no" inputmode="numeric" maxlength="4" placeholder="예: 2972" style="width:150px"></label>
      <label>해외이용 수수료율(%)<input name="fee_rate" value="<?= h($v['fee_rate']) ?>" inputmode="decimal" style="width:120px"></label><?php endif ?>
      <label>금액 처리<select name="sign_mode">
        <option value="split"<?= $card ? '' : ' selected' ?>>통장: 입금·출금 열이 따로 있음</option>
        <option value="card"<?= $card ? ' selected' : '' ?>>카드: 금액 열 하나 (마이너스는 취소로)</option>
      </select></label>
    </div>
    <div class="scroll"><table>
      <tr><?php foreach ($v['headers'] as $i => $hd): ?><th><?= $i + 1 ?>열<br><?= h($hd) ?></th><?php endforeach ?></tr>
      <?php foreach ($v['preview'] as $r): ?><tr><?php foreach ($r as $c): ?><td><?= $c === null ? '' : h(lg_str($c)) ?></td><?php endforeach ?></tr><?php endforeach ?>
    </table></div>
    <div class="bar-l"><button class="btn" type="submit">장부에 넣기</button> <a class="btn ghost" href="<?= h(lg_url('import')) ?>">취소</a></div>
  </form>
</div>
<?php
    return ob_get_clean();
}

function lg_view_import_done(array $v, array $me) {
    if (($v['kind'] ?? '') !== 'import') return '<p class="help">보여줄 결과가 없어요.</p>';
    $items = [['새로 들어간 거래', $v['added'], '건', true], ['이미 있어서 건너뜀', $v['dup'], '건', true], ['날짜·금액이 없어 제외', $v['skipped'], '줄', true],
              ['출납장·수기 입력해 둔 거래와 합침', $v['merged'] ?? 0, '건', false], ['마감한 달이라 건너뜀', $v['locked'] ?? 0, '건', false],
              ['이미 있던 거래에 메모 채움', $v['noted'] ?? 0, '건', false], ['기존 거래에 결제예정일 등 보완', $v['updated'] ?? 0, '건', false],
              ['취소 줄 추가', $v['cancels'] ?? 0, '건', false], ['해외이용수수료(추정) 추가', $v['fees'] ?? 0, '건', false],
              ['기존 거래에 카드번호 붙임', $v['tagged'] ?? 0, '건', false], ['규칙으로 자동 분류', $v['ruled'] ?? 0, '건', false],
              ['카드대금 결제로 연결', $v['linked'] ?? 0, '건', false]];
    ob_start(); ?>
<div class="box">
  <h2><?= h($v['fname']) ?> → [<?= h($v['acc']['kind']) ?>] <?= h($v['acc']['name']) ?></h2>
  <div class="result">
    <?php foreach ($items as [$label, $n, $unit, $always]): if (!$always && !$n) continue; ?><div><?= h($label) ?><strong><?= (int)$n ?><?= $unit ?></strong></div><?php endforeach ?>
  </div>
  <p class="help">"제외"에는 합계 줄이나 빈 줄이 들어가요. 실제 거래가 빠진 것 같으면 열 설정을 다시 확인해 주세요.</p>
  <div class="bar-l"><a class="btn" href="<?= h(lg_url('classify')) ?>">분류하러 가기</a> <a class="btn ghost" href="<?= h(lg_url('transactions')) ?>">거래 목록 보기</a> <a class="btn ghost" href="<?= h(lg_url('import')) ?>">다른 파일 가져오기</a></div>
</div>
<?php
    return ob_get_clean();
}

function lg_view_ledger_import(array $v, array $me) {
    ob_start(); ?>
<?= lg_import_tabs('ledger_import') ?>
<div class="box">
  <p class="help">매일 쓰는 일일출납장(월별 시트)을 올리면, 출납장의 <b>적요</b>와 법인카드 <b>사용내용</b>을 장부의 같은 거래(같은 날짜·같은 금액)에 붙여요. 그리고 그 내용을 보고 계정과목을 자동으로 붙여요. (예: "직원 점심외식" → 복리후생비, "외부손님과 점심" → 기업업무추진비, "등기부등본 발급" → 지급수수료)</p>
  <p class="help">이미 분류한 거래의 계정과목은 바꾸지 않아요. 여러 번 올려도 괜찮아요.</p>
  <form class="inline" method="post" action="<?= h(lg_url('ledger_import')) ?>" enctype="multipart/form-data"><?= lg_csrf_field() ?>
    <label>출납장의 왼쪽 표는 어느 통장인가요?<select name="bank_id"><?php foreach ($v['banks'] as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach ?></select></label>
    <label>오른쪽 법인카드 표는?<select name="lead_id"><?php foreach ($v['leads'] as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?> (묶인 카드 포함)</option><?php endforeach ?></select></label>
    <label>출납장 파일<input type="file" name="file" accept=".xlsx" required></label>
    <button class="btn" type="submit">가져오기</button>
  </form>
</div>
<?php
    return ob_get_clean();
}

function lg_view_ledger_done(array $v, array $me) {
    if (($v['kind'] ?? '') !== 'ledger') return '<p class="help">보여줄 결과가 없어요.</p>';
    ob_start(); ?>
<div class="box">
  <div class="result">
    <div>통장 줄 연결<strong><?= $v['m_bank'] ?> / <?= $v['n_bank'] ?></strong></div>
    <div>카드 줄 연결<strong><?= $v['m_card'] ?> / <?= $v['n_card'] ?></strong></div>
    <div>상세 내용을 붙인 거래<strong><?= $v['touched'] ?>건</strong></div>
    <div>내용으로 자동 분류<strong><?= count($v['auto_rows']) ?>건</strong></div>
  </div>
  <p class="help">자동 분류된 거래는 아래에 계정과목별로 모아뒀어요. 훑어보시고 다르게 해야 할 게 있으면 [분류 → 한 건씩 보기]에서 "전체"로 찾아 고치면 돼요.</p>
  <div><a class="btn" href="<?= h(lg_url('classify_groups')) ?>">남은 미분류 정리하러 가기</a></div>
</div>
<?php if ($v['auto_rows']): ?>
<div class="box">
  <h2>내용으로 자동 분류된 거래</h2>
  <div class="scroll"><table>
    <tr><th>계정과목</th><th>날짜</th><th>계좌·카드</th><th>적요</th><th>출납장 내용</th><th class="num">금액</th></tr>
    <?php foreach ($v['auto_rows'] as $r): ?><tr><td><?= h($r['category']) ?></td><td><?= h($r['tx_date']) ?></td><td><?= h($r['acc']) ?></td><td><?= h($r['content']) ?></td><td class="dbk"><?= h($r['detail']) ?></td><td class="num"><?= lg_won((int)$r['amount_in'] ?: (int)$r['amount_out']) ?></td></tr><?php endforeach ?>
  </table></div>
</div>
<?php endif ?>
<?php if ($v['n_miss_bank'] || $v['n_miss_card']): ?>
<div class="box">
  <h2>장부에서 짝을 못 찾은 출납장 줄</h2>
  <p class="help">날짜나 금액이 장부와 다르거나, 장부에 아직 없는 거래예요(예: 새 카드 이전 사용분, 다른 통장 거래). 꼭 맞춰야 하는 건 아니에요.</p>
  <?php if ($v['n_miss_bank']): ?><p class="help"><b>통장 <?= $v['n_miss_bank'] ?>줄</b><?= $v['n_miss_bank'] > count($v['miss_bank']) ? ' (앞의 ' . count($v['miss_bank']) . '줄만 표시)' : '' ?></p>
  <div class="scroll"><table><tr><th>시트</th><th>날짜</th><th>적요</th><th class="num">입금</th><th class="num">출금</th></tr>
    <?php foreach ($v['miss_bank'] as $b): ?><tr><td><?= h($b['sheet']) ?></td><td><?= h($b['date']) ?></td><td><?= h($b['text']) ?></td><td class="num"><?= lg_won_or_blank($b['in']) ?></td><td class="num"><?= lg_won_or_blank($b['out']) ?></td></tr><?php endforeach ?></table></div><?php endif ?>
  <?php if ($v['n_miss_card']): ?><p class="help"><b>카드 <?= $v['n_miss_card'] ?>줄</b><?= $v['n_miss_card'] > count($v['miss_card']) ? ' (앞의 ' . count($v['miss_card']) . '줄만 표시)' : '' ?></p>
  <div class="scroll"><table><tr><th>시트</th><th>사용일</th><th>카드</th><th>가맹점</th><th>사용내용</th><th class="num">금액</th></tr>
    <?php foreach ($v['miss_card'] as $c): ?><tr><td><?= h($c['sheet']) ?></td><td><?= h($c['date']) ?></td><td><?= h($c['card']) ?></td><td><?= h($c['shop']) ?></td><td><?= h($c['text']) ?></td><td class="num"><?= lg_won($c['amount']) ?></td></tr><?php endforeach ?></table></div><?php endif ?>
</div>
<?php endif;
    return ob_get_clean();
}

function lg_view_inbox(array $v, array $me) {
    ob_start(); ?>
<?= lg_import_tabs('inbox') ?>
<div class="box">
  <p class="help">은행·카드사·홈택스에서 받은 엑셀을 <b>여러 개 한꺼번에 올리면</b>, 종류를 알아서 구분해서 한 번에 가져와요. (파이썬 버전의 '가져올 파일 폴더' 대신이에요.)</p>
  <form class="inline" method="post" action="<?= h(lg_url('inbox_upload')) ?>" enctype="multipart/form-data"><?= lg_csrf_field() ?>
    <label>파일 (여러 개 고를 수 있어요)<input type="file" name="files[]" accept=".xlsx,.xls,.csv" multiple required></label>
    <button class="btn" type="submit">올리기</button>
  </form>
  <p class="help" style="margin:0">처음 보는 은행 양식은 [파일 하나 가져오기]에서 한 번만 열 맞추기를 하면, 다음부터 자동으로 알아봐요. 급여대장은 아직 PC 의 파이썬 버전에서 가져와 주세요.</p>
</div>
<div class="box">
  <?php if ($v['items']): ?>
  <form method="post" action="<?= h(lg_url('inbox')) ?>"><?= lg_csrf_field() ?>
  <div class="scroll"><table>
    <tr><th></th><th>파일</th><th>종류</th><th>어디로</th><th>올린 때</th></tr>
    <?php foreach ($v['items'] as $i => $it): ?><tr>
      <td><input type="checkbox" class="chk" name="use_<?= $i ?>" value="1"<?= $it['type'] !== 'unknown' ? ' checked' : ' disabled' ?> aria-label="가져오기"></td>
      <td style="font-size:13px;white-space:normal;max-width:320px"><?= h($it['name']) ?></td>
      <td><span class="badge <?= $it['type'] === 'unknown' ? 'b-gray' : 'b-blue' ?>"><?= h($it['label']) ?></span><?php if (!empty($it['error'])): ?><br><span class="warn" style="font-size:12px"><?= h($it['error']) ?></span><?php endif ?></td>
      <td style="font-size:13px"><?php if ($it['type'] === 'bank'): ?><select name="target_<?= $i ?>" aria-label="통장" style="min-height:34px"><?php foreach ($v['banks'] as $b): ?><option value="<?= $b['id'] ?>"<?= lg_sel($b['id'], $it['target']) ?>><?= h($b['name']) ?></option><?php endforeach ?></select>
        <?php elseif ($it['type'] === 'card'): ?>법인카드 (카드번호로 나눠 넣음)<?php elseif ($it['type'] === 'invoice'): ?>증빙·정산 (거래처·자동 연결)<?php elseif ($it['type'] === 'payroll'): ?>급여대장 (아직 파이썬에서)<?php else: ?>-<?php endif ?></td>
      <td style="font-size:12.5px"><?= h(substr($it['time'], 5, 11)) ?></td></tr><?php endforeach ?>
  </table></div>
  <div class="bar-l"><button class="btn" type="submit">고른 파일 한 번에 가져오기</button>
    <button class="btn ghost" type="submit" name="do" value="clear" formnovalidate>올려 둔 파일 모두 치우기</button></div>
  </form>
  <?php else: ?><p class="help">올려 둔 파일이 없어요. 위에서 받은 엑셀을 골라 [올리기]를 누르세요.</p><?php endif ?>
</div>
<?php
    return ob_get_clean();
}

function lg_view_inbox_done(array $v, array $me) {
    if (($v['kind'] ?? '') !== 'inbox') return '<p class="help">보여줄 결과가 없어요.</p>';
    ob_start(); ?>
<div class="box">
  <div class="scroll"><table>
    <tr><th>파일</th><th>종류</th><th>결과</th><th></th></tr>
    <?php foreach ($v['report'] as [$name, $kind, $msg, $ok]): ?><tr><td style="font-size:13px;white-space:normal;max-width:320px"><?= h($name) ?></td><td><?= h($kind) ?></td><td style="white-space:normal"><?= h($msg) ?></td>
      <td><span class="badge <?= $ok ? 'b-green' : 'b-orange' ?>"><?= $ok ? '완료' : '확인 필요' ?></span></td></tr><?php endforeach ?>
  </table></div>
  <div class="bar-l"><a class="btn" href="<?= h(lg_url('today')) ?>">오늘의 출납장으로</a><a class="btn ghost" href="<?= h(lg_url('classify_groups')) ?>">분류하러 가기</a><a class="btn ghost" href="<?= h(lg_url('inbox')) ?>">여러 파일 한 번에</a></div>
</div>
<?php
    return ob_get_clean();
}

function lg_view_duplicates(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    ob_start(); ?>
<?= lg_import_tabs('duplicates') ?>
<div class="box">
  <p class="help">같은 통장·카드에서 <b>같은 날짜, 같은 금액</b>인 거래를 묶어 보여드려요. 그중 <b>이름도 같고 다른 파일에서 또 들어온 묶음</b>(가장 흔한 중복)은 위쪽에 "중복 의심"으로 표시하고, 지울 줄을 미리 골라뒀어요. 남기는 줄은 세금계산서 연결·계정과목·출납장 내용처럼 <b>정보가 더 많은 줄</b>이에요.</p>
  <p class="help" style="margin:0">같은 날 같은 금액이 실제로 여러 번 있는 경우(예: 주차비 20,000원 여러 건)도 있으니, 아래쪽 "확인용" 묶음은 꼭 눈으로 보고 골라주세요. 지운 거래의 원래 내용은 작업 기록에 남아요.</p>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="duplicates">
    <label>계좌·카드<select name="account_id"><option value="">전체</option><?php foreach ($v['accounts'] as $a): ?><option value="<?= $a['id'] ?>"<?= lg_sel($v['aid'], $a['id']) ?>>[<?= h($a['kind']) ?>] <?= h($a['name']) ?></option><?php endforeach ?></select></label>
    <button class="btn" type="submit">조회</button>
  </form>
</div>
<form method="post" action="<?= h(lg_url('duplicates')) ?>" data-confirm="고른 거래를 지울까요? 지운 거래의 원래 내용은 작업 기록에 남아요."><?= lg_csrf_field() ?>
<input type="hidden" name="account_id" value="<?= h($v['aid']) ?>">
<div class="box">
  <?php if ($v['groups']): ?>
  <p class="help" style="margin:0">중복 의심 <b><?= $v['n_likely'] ?>묶음</b> · 확인용 <?= count($v['groups']) - $v['n_likely'] ?>묶음</p>
  <div class="scroll"><table>
    <tr><th>지우기</th><th>날짜</th><th>통장·카드</th><th>적요</th><th>출납장 내용</th><th class="num">입금</th><th class="num">출금</th><th>계정과목</th><th>가져온 파일</th><th>연결</th></tr>
    <?php foreach ($v['groups'] as $g): ?>
    <tr class="grp<?= $g['likely'] ? ' likely' : '' ?>"><td colspan="10"><?= $g['likely'] ? '중복 의심' : '확인용' ?> · <?= h($g['date']) ?> · <?= h($g['acc']) ?> · <?= lg_won($g['amount']) ?>원 <?= count($g['rows']) ?>건</td></tr>
    <?php foreach ($g['rows'] as $r): ?><tr>
      <td style="text-align:center"><?php if ($r['locked']): ?><span class="sub-s">마감</span><?php elseif (!$ceo): ?><input type="checkbox" class="chk" name="del_id[]" value="<?= $r['id'] ?>"<?= $r['suggest_del'] ? ' checked' : '' ?> aria-label="지우기"><?php endif ?></td>
      <td><?= h($r['tx_date']) ?></td><td style="font-size:13px"><?= h($r['acc_name']) ?><?= $r['card_no'] ? ' · ' . h($r['card_no']) : '' ?></td>
      <td><?= h($r['content']) ?></td><td class="dbk"><?= h($r['detail']) ?></td>
      <td class="num in"><?= lg_won_or_blank($r['amount_in']) ?></td><td class="num out"><?= lg_won_or_blank($r['amount_out']) ?></td>
      <td style="font-size:13px"><?= h($r['category'] ?: '미분류') ?></td>
      <td class="src"><?= h($r['source_file']) ?></td>
      <td style="font-size:12px"><?php if ((int)$r['links']): ?><span class="badge b-blue">세금계산서</span><?php endif ?><?php if ((int)$r['parts']): ?><span class="badge b-gray">나눔</span><?php endif ?></td>
    </tr><?php endforeach ?>
    <?php endforeach ?>
  </table></div>
  <?php if (!$ceo): ?><div><button class="btn" type="submit">고른 거래 지우기</button></div><?php endif ?>
  <?php else: ?><p class="help">같은 날·같은 금액으로 겹친 거래가 없어요.</p><?php endif ?>
</div>
</form>
<?php
    return ob_get_clean();
}

function lg_view_imports(array $v, array $me) {
    $ceo = $me['role'] === LG_ROLE_CEO;
    ob_start(); ?>
<?= lg_import_tabs('imports') ?>
<div class="box">
  <p class="help" style="margin:0">거래 가져오기로 넣은 파일마다 몇 건이 어느 통장·카드에 들어갔는지 보여드려요. <b>파일을 잘못 넣었거나 엉뚱한 통장에 넣었다면</b> 그 파일에서 들어온 거래만 통째로 지울 수 있어요. 지운 거래의 원래 내용은 작업 기록에 남고, 마감한 달은 건너뛰어요.</p>
</div>
<div class="box">
  <?php if ($v['rows']): ?><div class="scroll"><table>
    <tr><th>가져온 때</th><th>파일</th><th>들어간 곳</th><th class="num">건수</th><th>기간</th><th class="num">입금</th><th class="num">출금</th><th></th></tr>
    <?php foreach ($v['rows'] as $r): ?><tr>
      <td style="font-size:13px"><?= h(substr((string)$r['at'], 0, 16)) ?></td><td style="font-size:13px;white-space:normal;max-width:260px"><?= h($r['source_file']) ?></td>
      <td style="font-size:13px"><?= h($r['acc_name']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td style="font-size:13px"><?= h($r['first']) ?> ~ <?= h($r['last']) ?></td>
      <td class="num in"><?= lg_won_or_blank($r['sin']) ?></td><td class="num out"><?= lg_won_or_blank($r['sout']) ?></td>
      <td><?php if (!$ceo): ?><form method="post" action="<?= h(lg_url('imports')) ?>" data-confirm="<?= h($r['source_file']) ?>에서 <?= h($r['acc_name']) ?>로 들어온 거래 <?= (int)$r['n'] ?>건을 지울까요?"><?= lg_csrf_field() ?>
        <input type="hidden" name="source_file" value="<?= h($r['source_file']) ?>"><input type="hidden" name="account_id" value="<?= $r['account_id'] ?>">
        <button class="btn danger">이 파일 거래 지우기</button></form><?php endif ?></td></tr><?php endforeach ?>
  </table></div><?php else: ?><p class="help">가져온 파일이 없어요.</p><?php endif ?>
  <p class="help" style="margin:0">같은 파일 이름으로 여러 번 가져왔다면 한 줄로 합쳐 보여요. 그 파일에서 처음 들어온 거래 중 나중에 다른 파일과 합쳐진 거래는 마지막 파일 이름으로 표시돼요.</p>
</div>
<?php
    return ob_get_clean();
}

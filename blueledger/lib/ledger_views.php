<?php
/**
 * BlueLedger 회계 장부 — 화면 HTML.
 *
 *  파이썬 버전의 Jinja 템플릿(templates/*.html)을 같은 순서·같은 문구로 옮겼다.
 *  위쪽은 포털 공통 상단바, 그 아래는 파이썬 버전과 같은 대시보드 모양이다.
 *  값은 lib/ledger_pages.php 가 만들고, 여기서는 그리기만 한다.
 */

require_once __DIR__ . '/ledger_pages.php';
require_once __DIR__ . '/../../core/pwa.php';

function h($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 0 이면 빈칸, 아니면 쉼표 숫자 (Jinja 의 `x|won if x`) */
function lg_won_or_blank($n) {
    return (int)$n ? lg_won($n) : '';
}

function lg_sel($a, $b) {
    return (string)$a === (string)$b ? ' selected' : '';
}

function lg_md($d) {          // 'YYYY-MM-DD' → 'MM-DD'
    return substr((string)$d, 5);
}

function lg_csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . h(bl_csrf_token()) . '">';
}

/** 계정과목 고르기 <option> — [[묶음, [이름...]], ...] */
function lg_cat_options(array $options, $selected = '') {
    $o = '';
    foreach ($options as [$grp, $names]) {
        $o .= '<optgroup label="' . h($grp) . '">';
        foreach ($names as $n) $o .= '<option' . ((string)$n === (string)$selected ? ' selected' : '') . '>' . h($n) . '</option>';
        $o .= '</optgroup>';
    }
    return $o;
}

function lg_icon($name, $color = '#ffffff', $size = 26) {
    $paths = [
        'home'    => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'book'    => '<path d="M4 4h12a2 2 0 0 1 2 2v14H6a2 2 0 0 1-2-2Z"/><path d="M4 18a2 2 0 0 1 2-2h12"/><path d="M8 8h6"/><path d="M8 12h4"/>',
        'ledger'  => '<path d="M4 4h12a2 2 0 0 1 2 2v14H6a2 2 0 0 1-2-2Z"/><path d="M4 18a2 2 0 0 1 2-2h12"/><path d="M8 8h6"/><path d="M8 12h4"/><path d="M18 9h2v9h-2"/>',
        'list'    => '<path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
        'back'    => '<path d="M10 6l-6 6 6 6"/><path d="M4 12h16"/>',
        'logout'  => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>',
        'upload'  => '<path d="M12 15V3"/><path d="M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'tag'     => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'link'    => '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/>',
        'wallet'  => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M16 15h2"/>',
        'lock'    => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'chart'   => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
        'sliders' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/><circle cx="9" cy="6" r="2" fill="' . $color . '"/><circle cx="15" cy="12" r="2" fill="' . $color . '"/><circle cx="7" cy="18" r="2" fill="' . $color . '"/>',
        'pencil'  => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'plus'    => '<path d="M12 5v14"/><path d="M5 12h14"/>',
    ];
    return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$name] ?? '') . '</svg>';
}

function lg_flash_html(array $flashes) {
    $o = '';
    foreach ($flashes as [$cat, $m]) $o .= '<div class="flash ' . h($cat) . '">' . h($m) . '</div>';
    return $o;
}

// 왼쪽 메뉴: [아이콘, 이름, 화면(p) — null 이면 아직 옮기지 않음]. 대표는 '옮기는 중' 메뉴 중 LG_CEO_MENU 만 본다.
const LG_NAV = [
    '장부' => [
        ['home', '홈', 'home'],
        ['pencil', '오늘의 출납장', 'today'],
        ['book', '일일출납장', 'daybook'],
        ['wallet', '잔액·자금일보', 'cashbook'],
        ['list', '거래 목록', 'transactions'],
        ['upload', '거래 가져오기', 'import'],
        ['tag', '분류', 'classify_groups'],
        ['pencil', '수기 입력', 'manual'],
        ['sliders', '기준정보', 'accounts'],
    ],
    'PHP 옮기는 중' => [
        ['link', '증빙·정산', null],
        ['lock', '월마감', null],
        ['chart', '보고서', null],
        ['users', '급여대장', null],
    ],
];

/** 화면 틀: 왼쪽 남색 메뉴 + 위쪽 제목 줄 + 본문. 포털 상단바 없이 장부만 화면 가득. */
function lg_layout($p, $heading, array $me, array $u, $body) {
    $css = 'styles/ledger.css?v=' . (int)@filemtime(__DIR__ . '/../styles/ledger.css');
    $js = 'static/ledger.js?v=' . (int)@filemtime(__DIR__ . '/../static/ledger.js');
    $ceo = $me['role'] === LG_ROLE_CEO;
    $role = LG_ROLE_LABEL[$me['role']] . ($ceo ? ' · 보기 전용' : '');
    $soonName = $p === 'soon' ? (string)($_GET['name'] ?? '') : '';
    ob_start(); ?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($heading) ?> · BlueLedger</title>
<link rel="icon" type="image/svg+xml" href="../styles/favicons/ledger.svg">
<?= pwa_head_tags('../') ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<link rel="stylesheet" href="<?= h($css) ?>">
</head>
<body>
<div class="app">
  <aside class="side" aria-label="BlueLedger 메뉴">
    <a class="brand" href="<?= h(lg_url('home')) ?>">
      <span class="mark" aria-hidden="true"><?= lg_icon('ledger', '#ffffff') ?></span>
      <span class="brand-t">BlueLedger<small>재무·회계 장부</small></span>
    </a>
    <nav class="nav">
      <?php foreach (LG_NAV as $group => $items):
          $items = array_values(array_filter($items, fn($it) => $it[2] || !$ceo || in_array($it[1], LG_CEO_MENU, true)));
          if (!$items) continue; ?>
      <div class="nav-g"><?= h($group) ?></div>
      <?php foreach ($items as [$ic, $label, $page]):
          $on = $page ? $page === $p : $soonName === $label; ?>
      <a class="nav-i<?= $on ? ' on' : '' ?><?= $page ? '' : ' soon' ?>" href="<?= h($page ? lg_url($page) : lg_soon_link($label)) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <?= lg_icon($ic, 'currentColor', 18) ?><span><?= h($label) ?></span>
      </a>
      <?php endforeach; endforeach ?>
    </nav>
    <div class="side-foot">
      <a class="nav-i" href="../index.php"><?= lg_icon('back', 'currentColor', 18) ?><span>blue-iWorks 포털</span></a>
      <a class="nav-i" href="../api/logout.php"><?= lg_icon('logout', 'currentColor', 18) ?><span>로그아웃</span></a>
    </div>
  </aside>
  <div class="main">
    <header class="topline">
      <h1><?= h($heading) ?></h1>
      <div class="top-r">
        <span class="chip num">작업일 · <?= h(lg_day_label(lg_today())) ?></span>
        <span class="who"><span class="avatar"><?= h(mb_substr($me['name'], 0, 1)) ?></span><span><?= h($me['name']) ?><small><?= h($role) ?></small></span></span>
      </div>
    </header>
    <?= $body ?>
  </div>
</div>
<script src="<?= h($js) ?>"></script>
</body>
</html>
<?php
    return ob_get_clean();
}

/** 하위 화면 공통: [← 이전 화면] + 알림 + 본문 (파이썬 _page.html). 제목은 위쪽 제목 줄에 있다. */
function lg_page($prev, array $flashes, $content) {
    $hasPrev = $prev && $prev !== lg_url('home');
    return '<main class="content">'
         . ($hasPrev ? '<div class="crumb"><a href="' . h($prev) . '">← 이전 화면</a></div>' : '')
         . lg_flash_html($flashes) . $content . '</main>';
}

function lg_soon_link($title) {
    return lg_url('soon', ['name' => $title]);
}

/* ================================================================ 홈 */

function lg_view_home(array $v, array $me, array $flashes) {
    $ti = $v['today_info'];
    $ceo = $me['role'] === LG_ROLE_CEO;
    $md = (int)substr($ti['d'], 5, 2) . '월 ' . (int)substr($ti['d'], 8, 2) . '일 (' . lg_weekday($ti['d']) . ')';
    $dday = fn($n) => $n > 0 ? 'D-' . $n : ($n === 0 ? 'D-DAY' : 'D+' . -$n);
    ob_start(); ?>
<main class="content">
  <?= lg_flash_html($flashes) ?>
  <section class="kpis">
    <a class="kpi hero" href="<?= h(lg_url('cashbook')) ?>">
      <span class="k">자금 현황 · 전체 계좌 잔액</span>
      <strong class="num">₩ <?= lg_won($v['total']) ?></strong>
      <span class="s">계좌 <?= $v['n_bank'] ?>개 · 카드 <?= $v['n_card'] ?>장 · 자금일보 →</span>
    </a>
    <div class="kpi"><span class="k">오늘 입금 · <?= h($md) ?></span><strong class="num in"><?= lg_won($ti['in']) ?></strong><span class="s">원</span></div>
    <div class="kpi"><span class="k">오늘 출금·사용</span><strong class="num out"><?= lg_won($ti['out']) ?></strong><span class="s">원</span></div>
    <div class="kpi"><span class="k">오늘 기록된 거래</span><strong class="num"><?= $ti['n'] ?><small>건</small></strong>
      <span class="s"><?= $ti['nod'] ? '<span class="tag warn">내용 ' . $ti['nod'] . '건 남음</span>' : '내용 모두 적음' ?></span></div>
  </section>

  <div class="actions">
    <a class="btn primary" href="<?= h(lg_url('today', ['d' => $ti['d']])) ?>"><?= $ceo ? '오늘의 출납장 보기' : '오늘의 출납장 쓰기' ?></a>
    <a class="btn" href="<?= h(lg_url('cashbook')) ?>">자금일보</a>
    <a class="btn" href="<?= h(lg_url('daybook')) ?>">출납장 엑셀</a>
    <?php if (!$ceo): ?><a class="btn ghost" href="<?= h(lg_soon_link('거래 가져오기')) ?>">통장·카드 가져오기</a><?php endif ?>
  </div>

  <div class="cols">
    <section class="box">
      <div class="box-h"><h2>계좌·카드 잔액</h2><a href="<?= h(lg_url('accounts')) ?>">기준정보 →</a></div>
      <div class="scroll"><table>
        <tr><th>구분</th><th>이름</th><th class="num">현재 잔액</th><th>최근 거래일</th></tr>
        <?php foreach ($v['accounts'] as $a): if ($a['group_of']) continue; ?>
        <tr><td><span class="tag<?= $a['kind'] === '카드' ? ' card' : '' ?>"><?= h($a['kind']) ?></span></td>
          <td><?= h($a['name']) ?><?= $a['members'] ? ' <span class="sub-s">외 ' . count($a['members']) . '장</span>' : '' ?></td>
          <td class="num"><b class="<?= $a['balance'] < 0 ? 'out' : '' ?>"><?= lg_won($a['balance']) ?></b></td>
          <td class="sub-s"><?= h($a['last_date'] ?: '-') ?></td></tr>
        <?php endforeach ?>
      </table></div>
      <p class="help">카드의 잔액은 아직 결제되지 않은 사용액이에요(마이너스 = 앞으로 낼 금액).</p>
    </section>

    <div class="stack">
      <section class="box">
        <div class="box-h"><h2>이번 주 할 일</h2><a href="<?= h(lg_url('transactions')) ?>">거래 목록 →</a></div>
        <div class="todo-list">
          <a class="todo" href="<?= h(lg_url('classify_groups')) ?>"><span>미분류 거래 확인</span><span class="tag <?= $v['uncategorized'] ? 'warn' : 'ok' ?>"><?= $v['uncategorized'] ?>건</span></a>
          <a class="todo" href="<?= h(lg_url('transactions')) ?>"><span>이번 달 들어온 거래</span><span class="tag"><?= $v['month_cnt'] ?>건</span></a>
          <a class="todo" href="<?= h(lg_soon_link('증빙·정산')) ?>"><span>미수금 · 미지급금 세금계산서<?= $v['n_check'] ? ' (확인 필요 ' . $v['n_check'] . '건)' : '' ?></span><span class="tag <?= $v['n_open'] ? 'warn' : 'ok' ?>"><?= $v['n_open'] ?>건</span></a>
          <a class="todo" href="<?= h(lg_soon_link('월마감')) ?>"><span><?= h($v['close_label']) ?></span><span class="tag <?= $v['is_closed'] ? 'ok' : 'warn' ?>"><?= $v['is_closed'] ? '마감 완료' : '대기' ?></span></a>
        </div>
      </section>
      <section class="box">
        <div class="box-h"><h2>다가오는 일정</h2></div>
        <div class="sched-list">
          <div class="sched"><span class="dday num<?= $v['close_dday'] <= 3 ? ' near' : '' ?>"><?= $dday($v['close_dday']) ?></span>
            <div><div class="t"><?= h($v['close_label']) ?></div><div class="s num"><?= str_replace('-', '.', $v['close_due']) ?> · 월마감 (매월 10일 기준)</div></div></div>
          <div class="sched"><span class="dday num<?= $v['vat_dday'] <= 3 ? ' near' : '' ?>"><?= $dday($v['vat_dday']) ?></span>
            <div><div class="t">부가세 신고 자료 준비</div><div class="s num"><?= str_replace('-', '.', $v['vat']) ?> · 신고 기한 (휴일이면 다음 영업일)</div></div></div>
          <?php foreach ($v['card_sched'] as $c): ?>
          <div class="sched"><span class="dday num<?= $c['dday'] <= 3 ? ' near' : '' ?>"><?= $dday($c['dday']) ?></span>
            <div><div class="t"><?= h($c['name']) ?> 대금 결제</div><div class="s num"><?= str_replace('-', '.', $c['due']) ?> · <?= $c['use_ym'] ? (int)substr($c['use_ym'], 5) . '월 사용분 ' : '' ?><?= lg_won($c['unpaid']) ?>원</div></div></div>
          <?php endforeach ?>
        </div>
      </section>
    </div>
  </div>
</main>
<?php
    return ob_get_clean();
}

/* ================================================================ 준비 중 */

function lg_view_soon($name) {
    return '<div class="box"><h2>' . h($name) . '</h2><p class="help">이 메뉴는 아직 PHP 로 옮기는 중이에요. 지금은 PC 의 파이썬 BlueLedger 에서 해 주세요.<br>'
         . '옮기는 순서: 화면 틀·권한 → 기준정보·거래 목록·출납장·자금일보 → 거래 가져오기 → 분류 → 카드 맞춰보기 → 증빙·정산 → 급여 → 월마감·보고서·백업</p></div>';
}

/* ================================================================ 기준정보 */

function lg_view_accounts(array $v, array $me) {
    $accounts = $v['accounts'];
    $cards = array_values(array_filter($accounts, fn($a) => $a['kind'] === '카드'));
    $act = fn($do, $aid) => '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="aid" value="' . (int)$aid . '">' . lg_csrf_field();
    ob_start(); ?>
<div><a class="btn ghost" href="<?= h(lg_url('categories')) ?>">계정과목·규칙 관리 →</a></div>
<div class="box">
  <h2>새로 등록</h2>
  <p class="help">거래를 가져오기 전에 통장과 법인카드를 먼저 등록해 주세요. 기초잔액은 엑셀 첫 거래 <b>직전</b>의 통장 잔액이에요. 카드는 0으로 두면 됩니다.</p>
  <form class="inline" method="post" action="<?= h(lg_url('accounts')) ?>"><?= $act('add', 0) ?>
    <label>이름<input name="name" placeholder="예: 기업은행 주거래" required></label>
    <label>종류<select name="kind"><option>계좌</option><option>카드</option></select></label>
    <label>금융기관<input name="institution" placeholder="예: IBK기업은행"></label>
    <label>기초잔액(원)<input name="opening_balance" inputmode="numeric" placeholder="0"></label>
    <label>기초일자<input type="date" name="opening_date"></label>
    <button class="btn" type="submit">등록</button>
  </form>
</div>

<div class="box">
  <h2>등록된 계좌·카드</h2>
  <?php if ($accounts): ?>
  <div class="scroll"><table>
    <tr><th>종류</th><th>이름</th><th>이름 · 기초잔액 · 기초일자</th><th class="num">입금 합계</th><th class="num">출금 합계</th><th class="num">통장에서 결제</th><th class="num">현재 잔액</th><th class="num">거래 수</th><th>최근 거래일</th><th></th></tr>
    <?php foreach ($accounts as $a): ?>
    <tr><td><?= h($a['kind']) ?></td><td><?= h($a['name']) ?><?php if ($a['institution']): ?><br><span class="sub-s"><?= h($a['institution']) ?></span><?php endif ?></td>
      <td><form method="post" action="<?= h(lg_url('accounts')) ?>" style="display:flex;gap:6px;align-items:center"><?= $act('opening', $a['id']) ?>
        <input name="name" value="<?= h($a['name']) ?>" aria-label="이름" style="width:150px;min-height:36px;padding:6px 10px">
        <input name="opening_balance" value="<?= lg_won($a['opening_balance']) ?>" aria-label="기초잔액" style="width:130px;min-height:36px;padding:6px 10px">
        <input type="date" name="opening_date" value="<?= h($a['opening_date']) ?>" aria-label="기초일자" style="min-height:36px;padding:6px 10px">
        <button class="btn ghost" style="min-height:36px;padding:6px 12px;font-size:13px">고치기</button></form></td>
      <td class="num in"><?= lg_won($a['tin']) ?></td><td class="num out"><?= lg_won($a['tout']) ?></td>
      <td class="num"><?= $a['kind'] === '카드' ? lg_won($a['paid']) : '-' ?></td>
      <td class="num"><?php if ($a['group_of']): ?><span class="sub-s"><?= h($a['group_of']) ?>에 합산</span><?php else: ?><b><?= lg_won($a['balance']) ?></b><?php if ($a['members']): ?><br><span class="sub-s"><?= count($a['members']) + 1 ?>장 합계</span><?php endif ?><?php endif ?></td>
      <td class="num"><?= $a['cnt'] ?></td><td><?= h($a['last_date'] ?: '-') ?></td>
      <td><?php if (!$a['cnt']): ?><form method="post" action="<?= h(lg_url('accounts')) ?>" data-confirm="삭제할까요?"><?= $act('delete', $a['id']) ?><button class="btn danger">삭제</button></form><?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <p class="help">통장의 현재 잔액이 실제 통장 잔액과 다르면 기초잔액이나 빠진 기간이 없는지 확인해 보세요.<br>
  카드의 현재 잔액은 <b>아직 결제되지 않은 사용액</b>이에요. 마이너스면 앞으로 갚을 금액, 0이면 전부 결제된 상태예요.</p>
  <?php else: ?><p class="help">아직 등록된 계좌·카드가 없어요.</p><?php endif ?>
</div>

<?php if ($cards): ?>
<div class="box">
  <h2>카드대금 연결</h2>
  <p class="help">통장에서 카드대금이 빠져나갈 때 적요에 찍히는 글자를 적어주세요. 그 출금은 비용이 아니라 <b>'카드대금 결제'</b>로 표시되고, 카드 쪽에는 갚은 금액으로 더해져요. 앞으로 통장 거래를 가져올 때마다 자동으로 연결됩니다.</p>
  <?php foreach ($cards as $c): ?>
  <div class="card-set">
  <form class="inline" method="post" action="<?= h(lg_url('accounts')) ?>"><?= $act('group', $c['id']) ?>
    <label><?= h($c['name']) ?> · 대금이 어떻게 빠져나가나요?<select name="lead_id">
      <option value="">이 카드 대금이 따로 빠져나감 (대표 카드)</option>
      <?php foreach ($cards as $o): if ($o['id'] === $c['id'] || $o['group_of']) continue; ?><option value="<?= $o['id'] ?>"<?= lg_sel($c['pay_group'], $o['id']) ?>>'<?= h($o['name']) ?>'와 함께 한 번에 빠져나감</option><?php endforeach ?>
    </select></label>
    <button class="btn ghost" type="submit">저장</button>
  </form>
  <?php if ($c['group_of']): ?>
  <p class="help" style="margin:0">이 카드의 사용분은 <b><?= h($c['group_of']) ?></b> 줄에 합쳐서 계산돼요. 카드대금 글자는 대표 카드에만 적으면 돼요.</p>
  <?php else: ?>
  <form class="inline" method="post" action="<?= h(lg_url('accounts')) ?>"><?= $act('card', $c['id']) ?>
    <label>카드<input value="<?= h($c['name']) ?>" disabled></label>
    <label>통장 적요에 찍히는 글자<input name="pay_keyword" value="<?= h($c['pay_keyword']) ?>" placeholder="예: 비씨카드출금, 비씨카드선결제" style="min-width:280px"></label>
    <label>매달 결제일<input name="pay_day" type="number" min="1" max="31" value="<?= $c['pay_day'] ?: '' ?>" placeholder="23" style="width:100px"></label>
    <label>연결 시작일<input type="date" name="pay_start" value="<?= h($c['pay_start']) ?>"></label>
    <label>연결 종료일 (비우면 계속)<input type="date" name="pay_end" value="<?= h($c['pay_end']) ?>"></label>
    <button class="btn" type="submit">저장하고 연결</button>
    <a class="btn ghost" href="<?= h(lg_url('card_check', ['aid' => $c['id']])) ?>">월별로 맞춰보기</a>
    <span class="help">연결된 결제 <?= lg_won($c['paid']) ?>원 · 미결제 사용액 <?= lg_won($c['balance'] < 0 ? -$c['balance'] : 0) ?>원<?= $c['members'] ? ' (' . (count($c['members']) + 1) . '장 합계)' : '' ?></span>
  </form>
  <?php endif ?>
  </div>
  <?php endforeach ?>
  <p class="help"><b>카드 여러 장의 대금이 한 번에 빠져나간다면</b>, 한 장을 대표 카드로 두고(카드대금 글자는 여기에만) 나머지 카드는 "○○와 함께 한 번에 빠져나감"으로 묶어주세요.</p>
  <p class="help"><b>글자가 여러 개</b>일 때(정기 결제와 선결제의 적요가 다를 때)는 쉼표로 이어서 적어주세요. 예: 비씨카드출금, 비씨카드선결제</p>
  <p class="help"><b>연결 시작일</b>: 이 날짜부터 빠져나간 카드대금만 이 카드의 결제로 봐요. 카드를 새로 발급받았다면, 이전 카드의 마지막 결제일 <b>다음 날</b>을 넣어주세요. 그 전의 출금은 이 카드와 연결되지 않고 미분류로 남아요.</p>
  <p class="help">연결한 뒤 카드 잔액이 <b>플러스</b>가 되면, 통장에서 갚은 돈 중 일부가 가져오기 전 기간(예: 지난달) 사용분이라서 그래요. 그럴 땐 카드의 기초잔액에 <b>그 이전 기간의 미결제 사용액을 마이너스로</b> 넣어주세요. (예: -1,250,000)</p>
</div>
<?php endif;
    return ob_get_clean();
}

/* ================================================================ 거래 목록 */

function lg_view_transactions(array $v, array $me) {
    $rows = $v['rows'];
    $self = lg_url('transactions', ['start' => $v['start'], 'end' => $v['end'], 'account_id' => $v['aid'], 'card_no' => $v['card_no']]);
    $ceo = $me['role'] === LG_ROLE_CEO;
    ob_start(); ?>
<div class="box">
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="transactions">
    <label>시작일<input type="date" name="start" value="<?= h($v['start']) ?>"></label>
    <label>종료일<input type="date" name="end" value="<?= h($v['end']) ?>"></label>
    <label>계좌·카드<select name="account_id"><option value="">전체</option><?php foreach ($v['accounts'] as $a): ?><option value="<?= $a['id'] ?>"<?= lg_sel($v['aid'], $a['id']) ?>>[<?= h($a['kind']) ?>] <?= h($a['name']) ?></option><?php endforeach ?></select></label>
    <?php if ($v['card_nos']): ?><label>카드번호<select name="card_no"><option value="">전체</option><?php foreach ($v['card_nos'] as $c): ?><option<?= lg_sel($v['card_no'], $c) ?>><?= h($c) ?></option><?php endforeach ?></select></label><?php endif ?>
    <button class="btn" type="submit">조회</button>
  </form>
  <div class="result">
    <div>거래 수<strong><?= count($rows) ?>건</strong></div>
    <div>입금 합계<strong class="in num"><?= lg_won($v['sum_in']) ?></strong></div>
    <div>출금 합계<strong class="out num"><?= lg_won($v['sum_out']) ?></strong></div>
  </div>
  <?php if (count($v['by_card']) > 1 || ($v['by_card'] && $v['by_card'][0]['card_no'])): ?>
  <div class="scroll"><table style="max-width:520px">
    <tr><th>카드번호</th><th class="num">건수</th><th class="num">사용액 (취소 차감)</th></tr>
    <?php foreach ($v['by_card'] as $b): ?><tr><td><?= h($b['card_no'] ?: '(번호 없음)') ?></td><td class="num"><?= (int)$b['n'] ?></td><td class="num"><?= lg_won($b['used']) ?></td></tr><?php endforeach ?>
  </table></div>
  <?php endif ?>
  <?php if ($rows): ?>
  <form method="post" action="<?= h(lg_url('transactions')) ?>" id="selDel" data-confirm="고른 거래를 장부에서 지울까요? 지운 거래의 원래 내용은 작업 기록에 남아요."><input type="hidden" name="do" value="delete_selected"><input type="hidden" name="back" value="<?= h($self) ?>"><?= lg_csrf_field() ?></form>
  <div class="scroll"><table>
    <tr><th><?php if (!$ceo): ?><input type="checkbox" class="chk" aria-label="모두 선택" data-pick-all=".pick"><?php endif ?></th><th>날짜</th><th>계좌·카드</th><th>카드</th><th>적요</th><th class="num">입금</th><th class="num">출금</th><th>계정과목</th><th>들어온 경로</th><th></th></tr>
    <?php foreach ($rows as $r): ?><tr><td><?php if (!$r['split_of'] && !$ceo): ?><input type="checkbox" class="pick chk" form="selDel" name="del_id[]" value="<?= $r['id'] ?>" aria-label="지우기"><?php endif ?></td><td><?= h($r['tx_date']) ?></td><td><?= h($r['acc_name']) ?></td><td><?= h($r['card_no']) ?></td>
      <td><?= h($r['content']) ?><?php if ($r['memo']): ?> <span class="sub-s">· <?= h($r['memo']) ?></span><?php endif ?><?php if ($r['note']): ?><br><span class="memo">메모: <?= h($r['note']) ?></span><?php endif ?><?php if ($r['detail']): ?><br><span class="dbk">출납장: <?= h($r['detail']) ?></span><?php endif ?></td>
      <td class="num in"><?= lg_won_or_blank($r['amount_in']) ?></td><td class="num out"><?= lg_won_or_blank($r['amount_out']) ?></td>
      <td><?= h($r['category'] ?: '미분류') ?></td>
      <td class="src"><?php if ($r['split_of']): ?>나눈 조각<?php elseif (str_starts_with($r['content'], LG_OVERSEAS_FEE)): ?>카드 해외수수료(자동)<?php elseif ($r['source_file'] === LG_ADJUST): ?>카드대금 맞춰보기 조정<?php elseif ($r['source_file'] === LG_MANUAL): ?>직접 입력<?php else: ?><?= h($r['source_file']) ?><?php endif ?></td>
      <td><?php if (!$r['linked_card_id'] && !$ceo): ?><a href="<?= h(lg_url('split', ['tid' => $r['id']])) ?>" style="font-size:13px">나누기</a><?php endif ?></td></tr><?php endforeach ?>
  </table></div>
  <?php if (!$ceo): ?>
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><button class="btn danger" type="submit" form="selDel">고른 거래 지우기</button>
    <span class="help">지울 거래를 왼쪽에서 체크하고 누르세요. 지운 거래의 원래 내용은 작업 기록에 남고, 마감한 달은 지워지지 않아요. 나눈 조각은 원래 거래를 지우면 함께 지워져요.</span></div>
  <?php endif ?>
  <?php else: ?><p class="help">이 기간에는 거래가 없어요.</p><?php endif ?>
  <?php if ($v['aid'] !== '' && $rows && !$ceo): ?>
  <form method="post" action="<?= h(lg_url('transactions')) ?>" data-confirm="<?= h($v['start']) ?> ~ <?= h($v['end']) ?> 기간의 거래 <?= count($rows) ?>건을 장부에서 지울까요? 되돌릴 수 없어요." class="period-del"><?= lg_csrf_field() ?>
    <input type="hidden" name="do" value="delete_period"><input type="hidden" name="account_id" value="<?= h($v['aid']) ?>"><input type="hidden" name="start" value="<?= h($v['start']) ?>"><input type="hidden" name="end" value="<?= h($v['end']) ?>">
    <span class="help">잘못 가져온 거래라면, 지금 조회한 이 계좌·카드의 이 기간 거래를 한 번에 지울 수 있어요.</span>
    <button class="btn danger" type="submit">이 기간 거래 지우기</button>
  </form>
  <?php endif ?>
</div>
<?php
    return ob_get_clean();
}

/* ================================================================ 일일출납장 (여러 날) */

function lg_view_daybook(array $v, array $me) {
    $rows = $v['rows'];
    $ceo = $me['role'] === LG_ROLE_CEO;
    $m = (int)substr($v['today'], 5, 2);
    ob_start(); ?>
<div class="box">
  <p class="help">통장·카드 내역을 가져온 뒤, 거래마다 <b>무엇에 쓴 돈인지 한 줄</b>만 적고 저장하세요. 계정과목을 비워두면 적은 내용을 보고 자동으로 붙여요(예: "사무실 간식" → 복리후생비). 적은 내용은 그대로 출납장 엑셀의 적요·사용내용이 돼요.</p>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="daybook">
    <label>시작일<input type="date" name="start" value="<?= h($v['start']) ?>"></label>
    <label>종료일<input type="date" name="end" value="<?= h($v['end']) ?>"></label>
    <label class="row-l"><input type="checkbox" class="chk" name="empty" value="1"<?= $v['only_empty'] ? ' checked' : '' ?>>내용 안 적은 것만</label>
    <button class="btn" type="submit">조회</button>
  </form>
</div>

<div class="box">
  <?php if ($rows): ?>
  <form method="post" action="<?= h(lg_url('daybook', ['start' => $v['start'], 'end' => $v['end'], 'empty' => $v['only_empty'] ? '1' : ''])) ?>"><?= lg_csrf_field() ?>
    <div class="scroll"><table>
      <tr><th>날짜</th><th>통장·카드</th><th>적요·가맹점</th><th class="num">입금</th><th class="num">출금·사용</th><th>내용 (출납장에 들어갈 말)</th><th>계정과목</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(lg_md($r['tx_date'])) ?></td>
        <td style="font-size:13px"><?= h($r['acc_name']) ?><?= $r['card_no'] ? ' · ' . h($r['card_no']) : '' ?></td>
        <td><?= h($r['content']) ?><?php if ($r['note']): ?><br><span class="memo">메모: <?= h($r['note']) ?></span><?php endif ?></td>
        <td class="num in"><?= lg_won_or_blank($r['amount_in']) ?></td>
        <td class="num out"><?= lg_won_or_blank($r['amount_out']) ?></td>
        <td><input type="hidden" name="tx_id[]" value="<?= $r['id'] ?>">
          <input name="detail_<?= $r['id'] ?>" value="<?= h($r['detail']) ?>" placeholder="예: 직원 점심외식" aria-label="내용" class="cell-in"<?= $ceo ? ' disabled' : '' ?>></td>
        <td><?php if ((int)$r['parts']): ?><a href="<?= h(lg_url('split', ['tid' => $r['id']])) ?>" style="font-size:13px"><?= (int)$r['parts'] + 1 ?>줄로 나눔</a>
          <?php elseif (in_array($r['category'], [LG_CARD_PAY, LG_CANCELLED], true)): ?><span class="sub-s"><?= h($r['category']) ?></span>
          <?php else: ?><select name="cat_<?= $r['id'] ?>" aria-label="계정과목" class="cell-sel"<?= $ceo ? ' disabled' : '' ?>><option value="">(자동)</option><?= lg_cat_options($v['options'], $r['category']) ?></select><?php endif ?></td>
      </tr>
      <?php endforeach ?>
    </table></div>
    <?php if (!$ceo): ?><div><button class="btn" type="submit">저장</button></div><?php endif ?>
  </form>
  <?php else: ?><p class="help">이 기간에 거래가 없어요. 먼저 거래 가져오기로 통장·카드 내역을 넣어주세요.</p><?php endif ?>
</div>

<div class="box">
  <p class="help" style="margin:0">하루 마감 확인은 <a href="<?= h(lg_url('cashbook', ['d' => $v['end']])) ?>">잔액·자금일보</a>에서 할 수 있어요. 같은 장부라서 여기서 적은 내용이 자금일보에도 그대로 나와요.</p>
  <h2>출납장 엑셀 만들기</h2>
  <p class="help">지금까지 쓰던 출납장과 같은 모양(왼쪽 통장, 오른쪽 카드별 사용내역, 전월이월·소계·잔액, 담당·승인란)으로 만들어요. 여러 달을 고르면 달마다 시트가 하나씩 생기고, 전월이월이 앞 시트의 잔액과 수식으로 이어져요.</p>
  <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="daybook_xlsx">
    <label>연도<input name="year" type="number" value="<?= (int)substr($v['today'], 0, 4) ?>" style="width:110px"></label>
    <label>시작 월<select name="from"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"<?= lg_sel($i, $m) ?>><?= $i ?>월</option><?php endfor ?></select></label>
    <label>끝 월<select name="to"><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"<?= lg_sel($i, $m) ?>><?= $i ?>월</option><?php endfor ?></select></label>
    <label>왼쪽 통장<select name="bank_id"><?php foreach ($v['banks'] as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach ?></select></label>
    <label>오른쪽 카드<select name="lead_id"><?php foreach ($v['leads'] as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?> (묶인 카드 포함)</option><?php endforeach ?></select></label>
    <button class="btn" type="submit">엑셀 받기</button>
  </form>
</div>
<?php
    return ob_get_clean();
}

/* ================================================================ 오늘의 출납장 */

function lg_view_today(array $v, array $me) {
    $d = $v['d'];
    $ed = $v['editable'] && $me['role'] !== LG_ROLE_CEO;
    $dis = $ed ? '' : ' disabled';
    $keep = fn() => '<input type="hidden" name="d" value="' . h($d) . '"><input type="hidden" name="bank_id" value="' . $v['bank_id'] . '"><input type="hidden" name="lead_id" value="' . $v['lead_id'] . '">';
    $act = lg_url('today');
    $nav = fn($dd) => lg_url('today', ['d' => $dd, 'bank_id' => $v['bank_id'], 'lead_id' => $v['lead_id']]);
    $delForm = fn($id) => '<form method="post" action="' . h($act) . '" data-confirm="이 줄을 지울까요?"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . (int)$id . '">' . $keep() . lg_csrf_field()
                        . '<button class="btn danger" style="min-height:30px;padding:2px 8px">지우기</button></form>';
    $mmdd = lg_md($d);
    ob_start(); ?>
<div class="box">
  <div class="bar">
    <div class="bar-l">
      <a class="btn ghost" href="<?= h($nav($v['prev_day'])) ?>">← 전날</a>
      <form method="get" class="inline" action="ledger.php"><input type="hidden" name="p" value="today"><input type="hidden" name="bank_id" value="<?= $v['bank_id'] ?>"><input type="hidden" name="lead_id" value="<?= $v['lead_id'] ?>">
        <input type="date" name="d" value="<?= h($d) ?>" data-autosubmit aria-label="날짜"></form>
      <a class="btn ghost" href="<?= h($nav($v['next_day'])) ?>">다음날 →</a>
      <a class="btn ghost" href="<?= h(lg_url('today', ['bank_id' => $v['bank_id'], 'lead_id' => $v['lead_id']])) ?>">오늘</a>
    </div>
    <div class="bar-l">
      <a class="btn ghost" href="<?= h(lg_url('daybook', ['start' => substr($d, 0, 8) . '01', 'end' => $d])) ?>">여러 날 한꺼번에 · 출납장 엑셀</a>
      <a class="btn ghost" href="<?= h(lg_url('cashbook', ['d' => $d])) ?>">자금일보</a>
    </div>
  </div>
  <?php if (!$v['editable']): ?><div class="flash error"><?php if ($d < $v['start']): ?><?= h($v['start']) ?> 이전은 기존 출납장 엑셀 기준으로 정리된 기간이라 여기서는 <b>보기만</b> 할 수 있어요.<?php else: ?>마감한 달이라 보기만 할 수 있어요. 고치려면 월마감에서 마감을 풀어주세요.<?php endif ?></div>
  <?php elseif ($me['role'] === LG_ROLE_CEO): ?><p class="help" style="margin:0">대표 계정은 확인용이라 보기만 할 수 있어요.</p>
  <?php else: ?><p class="help" style="margin:0">은행·카드 엑셀을 가져온 거래는 <b>내용</b>만 적으면 되고, 아직 엑셀에 없는 거래는 표 맨 아래 줄에 직접 적으세요. 나중에 엑셀을 가져오면 같은 날·같은 금액 거래와 자동으로 합쳐져요. 계정과목을 비워두면 내용을 보고 자동으로 붙여요.</p><?php endif ?>
</div>

<form method="post" id="addBank" action="<?= h($act) ?>"><input type="hidden" name="do" value="add_bank"><?= $keep() . lg_csrf_field() ?></form>
<form method="post" id="addCard" action="<?= h($act) ?>"><input type="hidden" name="do" value="add_card"><?= $keep() . lg_csrf_field() ?></form>
<form method="post" id="saveForm" action="<?= h($act) ?>"><input type="hidden" name="do" value="save"><?= $keep() . lg_csrf_field() ?></form>

<div class="box">
  <div class="bar">
    <h2>일일출납 (통장)</h2>
    <form method="get" class="inline" action="ledger.php"><input type="hidden" name="p" value="today"><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="lead_id" value="<?= $v['lead_id'] ?>">
      <select name="bank_id" data-autosubmit aria-label="통장" style="min-height:38px"><?php foreach ($v['banks'] as $b): ?><option value="<?= $b['id'] ?>"<?= lg_sel($b['id'], $v['bank_id']) ?>><?= h($b['name']) ?></option><?php endforeach ?></select></form>
  </div>
  <div class="scroll"><table class="ledger">
    <tr><th style="width:70px">일 자</th><th>적 요</th><th>내 용</th><th class="num">입 금</th><th class="num">출 금</th><th class="num">잔 액</th><th>계정과목</th><th></th></tr>
    <tr><td>전일이월</td><td colspan="4" class="sub-s">전일 잔액</td><td class="num"><b><?= lg_won($v['prev']) ?></b></td><td></td><td></td></tr>
    <?php foreach ($v['bank_rows'] as $r): ?><tr>
      <td><?= h($mmdd) ?></td>
      <td><?= h($r['content']) ?><?php if ($r['source_file'] === LG_MANUAL): ?> <span class="badge b-gray" style="font-size:11px">직접 입력</span><?php endif ?><?php if ($r['note']): ?><br><span class="memo">메모: <?= h($r['note']) ?></span><?php endif ?></td>
      <td><input form="saveForm" type="hidden" name="tx_id[]" value="<?= $r['id'] ?>"><input form="saveForm" class="t" name="detail_<?= $r['id'] ?>" value="<?= h($r['detail']) ?>" placeholder="예: 사무실 간식 구매" aria-label="내용"<?= $dis ?>></td>
      <td class="num in"><?= lg_won_or_blank($r['a_in']) ?></td><td class="num out"><?= lg_won_or_blank($r['a_out']) ?></td><td class="num"><?= lg_won($r['bal']) ?></td>
      <td><?php if ((int)$r['parts']): ?><a href="<?= h(lg_url('split', ['tid' => $r['id']])) ?>" style="font-size:12.5px"><?= (int)$r['parts'] + 1 ?>줄로 나눔</a>
        <?php elseif ($r['category'] === LG_CARD_PAY): ?><span class="sub-s"><?= h($r['category']) ?></span>
        <?php else: ?><select form="saveForm" name="cat_<?= $r['id'] ?>" aria-label="계정과목"<?= $dis ?>><option value="">(자동)</option><?= lg_cat_options($v['options'], $r['category']) ?></select><?php endif ?></td>
      <td><?= $ed && $r['source_file'] === LG_MANUAL ? $delForm($r['id']) : '' ?></td>
    </tr><?php endforeach ?>
    <?php if ($ed): ?><tr class="addrow"><td class="sub-s">새 줄</td>
      <td><input form="addBank" name="content" placeholder="적요 (거래처)" aria-label="적요" style="width:150px"></td>
      <td><input form="addBank" name="detail" placeholder="내용" aria-label="내용" style="width:100%;min-width:150px"></td>
      <td><input form="addBank" name="amount_in" inputmode="numeric" placeholder="입금" aria-label="입금" style="width:110px;text-align:right"></td>
      <td><input form="addBank" name="amount_out" inputmode="numeric" placeholder="출금" aria-label="출금" style="width:110px;text-align:right"></td>
      <td></td>
      <td><select form="addBank" name="category" aria-label="계정과목"><option value="">(자동)</option><?= lg_cat_options($v['options']) ?></select></td>
      <td><button form="addBank" class="btn" style="min-height:34px;padding:4px 12px">넣기</button></td></tr><?php endif ?>
    <tr class="sum"><td colspan="3" style="text-align:center">소 계 / 잔 액</td><td class="num in"><?= lg_won($v['sum_in']) ?></td><td class="num out"><?= lg_won($v['sum_out']) ?></td><td class="num"><?= lg_won($v['close']) ?></td><td></td><td></td></tr>
  </table></div>
</div>

<div class="box">
  <div class="bar">
    <h2>법인카드 이용내역</h2>
    <?php if (count($v['leads']) > 1): ?><form method="get" class="inline" action="ledger.php"><input type="hidden" name="p" value="today"><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="bank_id" value="<?= $v['bank_id'] ?>">
      <select name="lead_id" data-autosubmit aria-label="카드" style="min-height:38px"><?php foreach ($v['leads'] as $c): ?><option value="<?= $c['id'] ?>"<?= lg_sel($c['id'], $v['lead_id']) ?>><?= h($c['name']) ?></option><?php endforeach ?></select></form><?php endif ?>
  </div>
  <div class="scroll"><table class="ledger">
    <tr><th>카드번호</th><th>사용일자</th><th class="num">사용금액</th><th>가맹점명</th><th>사용내용</th><th>계정과목</th><th></th></tr>
    <?php foreach ($v['card_rows'] as $r): ?><tr>
      <td><?= h($r['card_no'] ?: lg_last4($r['acc_name'])) ?></td><td><?= h($mmdd) ?></td>
      <td class="num"><?= lg_won((int)$r['amount_out'] - (int)$r['amount_in'] + $r['fee']) ?><?php if ($r['fee']): ?><br><span class="sub-s" style="font-size:11.5px">해외수수료 <?= lg_won($r['fee']) ?> 포함</span><?php endif ?></td>
      <td><?= h($r['content']) ?><?php if ($r['source_file'] === LG_MANUAL): ?> <span class="badge b-gray" style="font-size:11px">직접 입력</span><?php endif ?></td>
      <td><input form="saveForm" type="hidden" name="tx_id[]" value="<?= $r['id'] ?>"><input form="saveForm" class="t" name="detail_<?= $r['id'] ?>" value="<?= h($r['detail']) ?>" placeholder="예: 직원 점심외식" aria-label="사용내용"<?= $dis ?>></td>
      <td><select form="saveForm" name="cat_<?= $r['id'] ?>" aria-label="계정과목"<?= $dis ?>><option value="">(자동)</option><?= lg_cat_options($v['options'], $r['category']) ?></select></td>
      <td><?= $ed && $r['source_file'] === LG_MANUAL ? $delForm($r['id']) : '' ?></td>
    </tr><?php endforeach ?>
    <?php if ($ed && $v['card_accs']): ?><tr class="addrow"><td><select form="addCard" name="card_acc" aria-label="카드"><?php foreach ($v['card_accs'] as $c): ?><option value="<?= $c['id'] ?>"><?= h(lg_last4($c['name']) ?: $c['name']) ?></option><?php endforeach ?></select></td>
      <td class="sub-s">새 줄</td>
      <td><input form="addCard" name="amount_out" inputmode="numeric" placeholder="금액" aria-label="사용금액" style="width:110px;text-align:right"></td>
      <td><input form="addCard" name="content" placeholder="가맹점" aria-label="가맹점명" style="width:150px"></td>
      <td><input form="addCard" name="detail" placeholder="사용내용" aria-label="사용내용" style="width:100%;min-width:150px"></td>
      <td><select form="addCard" name="category" aria-label="계정과목"><option value="">(자동)</option><?= lg_cat_options($v['options']) ?></select></td>
      <td><button form="addCard" class="btn" style="min-height:34px;padding:4px 12px">넣기</button></td></tr><?php endif ?>
    <tr class="sum"><td colspan="2" style="text-align:center">오늘 사용액</td><td class="num"><?= lg_won($v['card_sum']) ?></td><td colspan="4"></td></tr>
  </table></div>
</div>

<?php if ($ed && ($v['bank_rows'] || $v['card_rows'])): ?><div class="box"><div><button class="btn" type="submit" form="saveForm">내용·계정과목 저장</button></div></div><?php endif;
    return ob_get_clean();
}

/* ================================================================ 잔액·자금일보 */

function lg_view_cashbook(array $v, array $me) {
    $day = $v['day'];
    $t = $v['total'];
    ob_start(); ?>
<div class="box">
  <div class="bar">
    <div class="bar-l">
      <?php if ($v['prev_day']): ?><a class="btn ghost" href="<?= h(lg_url('cashbook', ['d' => $v['prev_day']])) ?>">← 전 거래일</a><?php endif ?>
      <form class="inline" method="get" action="ledger.php"><input type="hidden" name="p" value="cashbook"><input type="date" name="d" value="<?= h($day) ?>" aria-label="날짜" data-autosubmit></form>
      <?php if ($v['next_day']): ?><a class="btn ghost" href="<?= h(lg_url('cashbook', ['d' => $v['next_day']])) ?>">다음 거래일 →</a><?php endif ?>
    </div>
    <div class="bar-l">
      <a class="btn ghost" href="<?= h(lg_url('today', ['d' => $day])) ?>">이 날 출납장 <?= $me['role'] === LG_ROLE_CEO ? '보기' : '쓰기' ?><?= $v['no_detail'] ? " ({$v['no_detail']}건 내용 남음)" : '' ?></a>
      <a class="btn" href="<?= h(lg_url('cashbook_xlsx', ['d' => $day])) ?>">자금일보 엑셀</a>
    </div>
  </div>
  <p class="help" style="margin:0"><b><?= (int)substr($day, 0, 4) ?>년 <?= (int)substr($day, 5, 2) ?>월 <?= (int)substr($day, 8, 2) ?>일 (<?= h(lg_weekday($day)) ?>)</b> · 장부에 들어온 마지막 통장 거래일은 <?= h($v['last'] ?: '-') ?>이에요. 따로 쓰는 게 아니라, <b>일일출납장에 기록된 통장·카드 거래로 자동으로 만들어져요.</b> 출납장에서 줄을 넣거나 내용을 적으면 여기도 바로 바뀌어요.</p>
</div>

<div class="box">
  <h2>계좌별 잔액</h2>
  <div class="scroll"><table>
    <tr><th>계좌</th><th class="num">전일잔액</th><th class="num">입금</th><th class="num">출금</th><th class="num">금일잔액</th><th class="num">이번 달 입금 / 출금</th><th>은행 잔액과 대조</th></tr>
    <?php foreach ($v['banks'] as $b): $c = $b['check']; ?>
    <tr><td><?= h($b['acc']['name']) ?></td><td class="num"><?= lg_won($b['prev']) ?></td><td class="num in"><?= lg_won_or_blank($b['in']) ?></td><td class="num out"><?= lg_won_or_blank($b['out']) ?></td>
      <td class="num"><b><?= lg_won($b['close']) ?></b></td><td class="num" style="font-size:13px"><?= lg_won($b['mon_in']) ?> / <?= lg_won($b['mon_out']) ?></td>
      <td style="font-size:13px"><?php if (!$c): ?><span class="sub-s">은행 잔액 정보 없음</span>
        <?php elseif ($c['ok']): ?><span class="badge b-green">일치</span> <?= h(lg_md($c['day'])) ?> 기준
        <?php else: ?><span class="badge b-orange">차이</span> <?= h(lg_md($c['day'])) ?> 장부 <?= lg_won($c['book']) ?> / 은행 <?= h(implode(', ', array_map('lg_won', $c['bank']))) ?><?php endif ?></td></tr>
    <?php endforeach ?>
    <tr class="sum"><td>합계</td><td class="num"><?= lg_won($t['prev']) ?></td><td class="num in"><?= lg_won($t['in']) ?></td><td class="num out"><?= lg_won($t['out']) ?></td><td class="num"><?= lg_won($t['close']) ?></td><td></td><td></td></tr>
  </table></div>
  <p class="help">"은행 잔액과 대조"는 통장 엑셀의 <b>거래 후 잔액</b>과 장부 잔액이 같은지 보는 거예요. <b>차이</b>가 나오면 빠진 거래나 기초잔액을 확인하세요. 정보가 없다고 나오면, 통장 엑셀을 한 번 다시 가져오면서 열 맞추기에서 "거래 후 잔액" 열을 골라주세요(이미 있는 거래엔 잔액만 채워져요).</p>
</div>

<?php if ($v['cards']): ?>
<div class="box">
  <h2>법인카드</h2>
  <div class="scroll"><table>
    <tr><th>카드</th><th class="num">금일 사용</th><th class="num">이번 달 사용</th><th>다음 결제일</th><th class="num">결제 예정액</th></tr>
    <?php foreach ($v['cards'] as $c): ?><tr><td><?= h($c['lead']['name']) ?><?= $c['n'] > 1 ? ' 외 ' . ($c['n'] - 1) . '장' : '' ?></td><td class="num"><?= lg_won_or_blank($c['used']) ?></td><td class="num"><?= lg_won($c['mon']) ?></td>
      <td><?= h($c['next_due'] ?: '-') ?></td><td class="num"><b><?= lg_won_or_blank($c['next_amt']) ?></b></td></tr><?php endforeach ?>
  </table></div>
</div>
<?php endif ?>

<div class="box">
  <h2>이 날의 거래 (<?= count($v['txs']) ?>건)</h2>
  <?php if ($v['txs']): ?><div class="scroll"><table>
    <tr><th>통장·카드</th><th>적요</th><th>내용</th><th class="num">입금</th><th class="num">출금·사용</th><th>계정과목</th></tr>
    <?php foreach ($v['txs'] as $x): ?><tr><td style="font-size:13px"><?= h($x['acc_name']) ?></td><td><?= h($x['content']) ?></td>
      <td<?= $x['detail'] ? '' : ' class="warn"' ?>><?= h($x['detail'] ?: '내용 안 적음') ?></td>
      <td class="num in"><?= lg_won_or_blank($x['amount_in']) ?></td><td class="num out"><?= lg_won_or_blank($x['amount_out']) ?></td><td style="font-size:13px"><?= h($x['category'] ?: '미분류') ?></td></tr><?php endforeach ?>
  </table></div><?php else: ?><p class="help">이 날은 거래가 없어요.</p><?php endif ?>
</div>
<?php
    return ob_get_clean();
}

/* ================================================================ 출입 거절 */

function lg_view_denied(array $u) {
    $css = 'styles/ledger.css?v=' . (int)@filemtime(__DIR__ . '/../styles/ledger.css');
    return '<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
         . '<title>BlueLedger</title><link rel="stylesheet" href="' . h($css) . '"></head><body><main class="wrap" style="padding-top:64px">'
         . '<div class="box"><h2>BlueLedger 장부는 재무 담당자와 대표만 볼 수 있어요</h2>'
         . '<p class="help">' . h($u['name']) . '님 계정은 출입 명단에 없어요. 출장여비 청구는 <a href="index.php">BlueExpense</a>에서 할 수 있어요.</p>'
         . '<div><a class="btn ghost" href="../index.php">포털로 돌아가기</a></div></div></main></body></html>';
}

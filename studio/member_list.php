<?php
/** 구성원 목록 — 역할·팀·가용도와 주로 해 온 분야를 훑는다. 점수 랭킹은 없다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/repo/MemberRepo.php';
require_once __DIR__ . '/inc/service/AvailabilityCalculator.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();

// ┌──────────────────────────────────────────────────────────────────┐
// │ CLAUDE.md 가 금지한 것 — 이 화면을 고칠 때 반드시 지킬 것          │
// │                                                                  │
// │ "구성원 간 종합점수 전체 랭킹 화면을 만들지 않는다."                │
// │                                                                  │
// │ 그래서 이 표에는 cap_score 열이 없고 정렬은 이름 순으로 고정이다.  │
// │ 점수는 특정 과업을 기준으로 한 적합도(api/candidate.php)에서만     │
// │ 나온다. "누가 제일 잘하나" 를 묻는 화면을 여기서 만들지 마라.      │
// │                                                                  │
// │ 보여 주는 것은 역할·팀·가용도·주로 해 온 분야까지다.               │
// └──────────────────────────────────────────────────────────────────┘

$repo  = new MemberRepo(bs_db());
$avail = new AvailabilityCalculator(bs_db());

// 다른 화면에서 권한 문제로 튕겨 왔을 때 이유를 알려 준다.
$err = bs_param_str('err');

// ---------------------------------------------------------------------
// 조회 조건
// ---------------------------------------------------------------------
$fRole    = bs_param_str('role');
$fTeam    = bs_param_str('team');
$fKeyword = bs_param_str('keyword');
// 'all' 이면 배정 제외자(휴직·퇴사 등)까지 본다. 기본은 배정 가능한 사람만.
$fScope   = bs_param_str('scope') === 'all' ? 'all' : 'assignable';

$rows = $repo->search([
    'role_label'    => $fRole,
    'team'          => $fTeam,
    'keyword'       => $fKeyword,
    'is_assignable' => $fScope === 'all' ? null : 1,
]);

// ---------------------------------------------------------------------
// 가용도 기간
//
// 가용도는 기간이 있어야 나오는 숫자다. 목록에는 기간이 없으므로 기본값을
// 둔다 — 오늘부터 3개월. 지금 짜고 있는 배정이 보통 그 안에 든다.
// ---------------------------------------------------------------------
$from = bs_param_str('from') ?: date('Y-m-d');
$to   = bs_param_str('to')   ?: date('Y-m-d', strtotime('+3 months'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// ---------------------------------------------------------------------
// 곁들이 데이터 — 전부 **한 번씩만** 묻는다
//
// 사람마다 forMember()·skills()·metric() 을 부르면 인원수 비례 질의가 된다
// (CLAUDE.md 가 금지한 N+1). 셋 다 일괄 조회를 쓴다.
// ---------------------------------------------------------------------
$ids       = array_map(static fn($r) => (int)$r['id'], $rows);
$availRows = $ids ? $avail->forMembers($ids, $from, $to) : [];
$domains   = $repo->primaryDomains($ids);
$sample    = $repo->sampleStatusFor($ids);
$options   = $repo->filterOptions();

$me = $repo->findByUserId($user['id']);
$myId = $me ? (int)$me['id'] : 0;

bs_layout_head(
    $user,
    '구성원',
    '과업 편성/현황',
    '구성원의 역할·팀·가용도와 주로 해 온 분야를 확인합니다.',
    'member'
);
?>

<?php if ($err !== ''): ?>
<div class="ba-alert">
  <?= h(match ($err) {
      'denied'   => '다른 구성원의 프로파일은 PM 또는 관리자만 볼 수 있습니다. 본인 것은 언제든 볼 수 있습니다.',
      'notfound' => '구성원을 찾을 수 없습니다.',
      default    => '요청을 처리하지 못했습니다.',
  }) ?>
</div>
<?php endif; ?>

<div class="ba-list">

  <!-- ============ 실행 줄 ============ -->
  <div class="ba-filters" style="justify-content:flex-start">
    <a class="ba-btn ba-btn--primary" href="member_profile.php">내 프로파일</a>
    <?php if (bs_is_admin()): ?>
      <!-- 구성원 표가 비면 모듈이 통째로 멈춘다. 채우는 길을 화면에 둔다. -->
      <button type="button" class="ba-btn" id="ba-m-sync"
              title="포털 사용자 목록을 읽어 구성원 표에 넣습니다. 행을 지우지 않습니다.">
        포털에서 구성원 가져오기
      </button>
    <?php endif; ?>
    <span class="ba-head__sub">
      본인 프로파일은 언제든 볼 수 있습니다. 다른 구성원의 것은 PM·관리자만 열립니다.
    </span>
  </div>

  <!-- ============ 조회 조건 ============ -->
  <form class="ba-filters" method="get" action="member_list.php">
    <label class="ba-field">
      <span>역할</span>
      <select name="role">
        <option value="">전체</option>
        <?php foreach ($options['role'] as $v): ?>
          <option value="<?= h($v) ?>" <?= $fRole === $v ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="ba-field">
      <span>팀</span>
      <select name="team">
        <option value="">전체</option>
        <?php foreach ($options['team'] as $v): ?>
          <option value="<?= h($v) ?>" <?= $fTeam === $v ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="ba-field" style="flex:1 1 200px;max-width:360px">
      <span>검색</span>
      <input type="search" name="keyword" value="<?= h($fKeyword) ?>"
             placeholder="이름, 팀, 역할, 슬랙 계정">
    </label>

    <!-- 가용도는 기간이 있어야 나오는 숫자다. 기본은 오늘부터 3개월. -->
    <label class="ba-field">
      <span>가용도 기간</span>
      <input type="date" name="from" value="<?= h($from) ?>" aria-label="기간 시작">
    </label>
    <label class="ba-field">
      <span>~</span>
      <input type="date" name="to" value="<?= h($to) ?>" aria-label="기간 끝">
    </label>

    <label class="ba-check" for="ba-m-scope">
      <input type="checkbox" id="ba-m-scope" name="scope" value="all"
             <?= $fScope === 'all' ? 'checked' : '' ?>>
      <span>배정 제외자 포함</span>
    </label>

    <span class="ba-spacer"></span>
    <button type="submit" class="ba-btn ba-btn--primary">조회</button>
    <a class="ba-btn" href="member_list.php">조건 초기화</a>
  </form>

  <!-- ============ 목록 ============ -->
  <div class="ba-table-wrap">
    <table class="ba-table">
      <thead>
        <tr>
          <th style="width:130px">이름</th>
          <th style="width:110px">역할</th>
          <th style="width:110px">팀</th>
          <th style="width:88px" title="기본 가용 M/M. 0.50 이면 하프">기본 가용</th>
          <th style="width:260px">가용도 (<?= h($from) ?> ~ <?= h($to) ?>)</th>
          <th>주로 해 온 분야</th>
          <th style="width:92px">배정</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr>
          <td colspan="7" class="ba-cell-none">
            <?php if ($fRole !== '' || $fTeam !== '' || $fKeyword !== ''): ?>
              조건에 맞는 구성원이 없습니다.
            <?php elseif (bs_is_admin()): ?>
              구성원이 없습니다. 위의 <b>포털에서 구성원 가져오기</b>를 누르십시오.
              <br>
              <span class="ba-dim">구성원 표가 비어 있으면 후보 도출·배정·역량이 모두 돌지 않습니다.</span>
            <?php else: ?>
              구성원이 없습니다. 관리자가 포털 사용자에서 가져와야 합니다.
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $r):
            $mid  = (int)$r['id'];
            $av   = $availRows[$mid] ?? null;
            if ($av !== null) {
                // 반일 근무자 표기용. 계산값이 아니라 그 사람의 기준 근무량이다.
                $av['base_capacity'] = (float)$r['base_capacity'];
            }
            $isSelf = $mid === $myId;
            // 본인은 항상, 남의 것은 PM/관리자만 (CLAUDE.md).
            $canOpen = bs_can_view_profile((string)$r['user_id']);
        ?>
        <tr<?= $isSelf ? ' class="ba-row--me"' : '' ?>>
          <td>
            <?php if ($canOpen): ?>
              <a href="member_profile.php?member_id=<?= $mid ?>"><?= h($r['emp_name']) ?></a>
            <?php else: ?>
              <?= h($r['emp_name']) ?>
            <?php endif; ?>
            <?= $isSelf ? '<span class="ba-badge">나</span>' : '' ?>
          </td>
          <td><?= $r['role_label'] !== null && $r['role_label'] !== ''
                    ? h($r['role_label']) : '<span class="ba-cell-none">—</span>' ?></td>
          <td><?= $r['team'] !== null && $r['team'] !== ''
                    ? h($r['team']) : '<span class="ba-cell-none">—</span>' ?></td>
          <td><?= h(number_format((float)$r['base_capacity'], 2)) ?></td>
          <td><?= bs_avail_html($av) ?></td>
          <td>
            <?php
            // "데이터가 모자랍니다" 와 "평가 대상이 아닙니다" 는 다른 말이다
            // (CLAUDE.md). 둘을 같은 문구로 뭉개지 않는다.
            $mine = $domains[$mid] ?? [];
            if ($mine !== []):
                foreach ($mine as $d): ?>
                  <span class="ba-chip-s" title="<?= (int)$d['case_count'] ?>건"><?= h($d['name']) ?></span>
                <?php endforeach;
            elseif ((int)$r['is_evaluable'] === 0): ?>
              <span class="ba-cell-none" title="<?= h((string)($r['eval_exclude_reason'] ?? '')) ?>">평가 제외</span>
            <?php elseif (($sample[$mid]['insufficient'] ?? true)): ?>
              <span class="ba-cell-none">표본 부족</span>
            <?php else: ?>
              <span class="ba-cell-none">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php $on = (int)$r['is_assignable'] === 1; ?>
            <?php if (bs_is_admin()): ?>
              <!-- 개발 사업과 무관한 직무(경영지원 등)를 후보에서 뺀다.
                   역량 평가와는 다른 축이다 — 프로파일 화면이 그쪽을 맡는다. -->
              <button type="button"
                      class="ba-badge <?= $on ? 'ba-badge--ok' : '' ?> ba-assign-t"
                      data-mid="<?= $mid ?>" data-on="<?= $on ? '1' : '0' ?>"
                      data-name="<?= h($r['emp_name']) ?>"
                      title="눌러서 바꿉니다. 행을 지우지 않고 후보 목록에서만 빼거나 되돌립니다."
                      style="cursor:pointer"><?= $on ? '가능' : '제외' ?></button>
            <?php else: ?>
              <span class="ba-badge <?= $on ? 'ba-badge--ok' : '' ?>"><?= $on ? '가능' : '제외' ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <p class="ba-head__sub" style="margin-top:10px">
    <?= count($rows) ?>명.
    이름 순으로만 정렬합니다 — <b>구성원을 점수로 줄 세우는 화면은 두지 않습니다.</b>
    적합도는 특정 과업을 정한 뒤 배정 화면에서 나옵니다.
  </p>
</div>

<?php bs_layout_foot(); ?>

<?php
/** 구성원 프로파일 — 계열별 역량 레이더, 점수의 근거, 이의 제기. 전사 랭킹은 없다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/repo/MemberRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user     = bs_require_login();
$memberId = bs_param_int('member_id', 0) ?? 0;
$repo     = new MemberRepo(bs_db());

// ┌──────────────────────────────────────────────────────────────────┐
// │ 열람 권한 (CLAUDE.md)                                             │
// │   · 본인은 자기 프로파일을 **항상** 볼 수 있다                     │
// │   · 남의 것은 PM/관리자만                                         │
// │ 화면과 API 양쪽에서 막는다. 화면만 막으면 API 를 직접 부르면 뚫린다.│
// └──────────────────────────────────────────────────────────────────┘
$target = $memberId > 0 ? $repo->find($memberId) : $repo->findByUserId($user['id']);

if (!$target) {
    bs_layout_head($user, '프로파일', '과업 편성/현황', '', 'member');
    echo '<div class="ba-alert">'
       . ($memberId > 0 ? '구성원을 찾을 수 없습니다.'
                        : '구성원으로 등록되어 있지 않아 프로파일이 없습니다.')
       . '</div>';
    bs_layout_foot();
    exit;
}

if (!bs_can_view_profile((string)$target['user_id'])) {
    header('Location: member_list.php?err=denied');
    exit;
}

$isSelf    = $target['user_id'] === $user['id'];
$evaluable = (int)($target['is_evaluable'] ?? 1) === 1;
$canAdjust = bs_can(BS_CAP_EVAL_RUN);

bs_layout_head(
    $user,
    $isSelf ? '내 프로파일' : '구성원 프로파일',
    '과업 편성/현황',
    '분야별 처리량과 그 근거를 확인하고, 사실과 다르면 이의를 제기할 수 있습니다.',
    'member'
);
?>

<div class="ba-profile" id="ba-profile"
     data-member-id="<?= (int)$target['id'] ?>"
     data-is-self="<?= $isSelf ? '1' : '0' ?>"
     data-can-adjust="<?= $canAdjust ? '1' : '0' ?>">

  <!-- ============ 머리 ============ -->
  <section class="ba-panel ba-prof__head">
    <div>
      <h2><?= h($target['emp_name']) ?>
        <?php if ($isSelf): ?><span class="ba-badge">본인</span><?php endif; ?>
      </h2>
      <p class="ba-panel__hint">
        <?= h($target['role_label'] ?: '역할 미지정') ?>
        <?php if (!empty($target['team'])): ?> · <?= h($target['team']) ?><?php endif; ?>
        <?php if ((int)($target['career_months'] ?? 0) > 0): ?>
          · 경력 <?= (int)round(((int)$target['career_months']) / 12) ?>년
        <?php endif; ?>
      </p>
    </div>
    <div class="ba-prof__run" id="ba-prof-run"></div>
  </section>

  <?php if (!$evaluable): ?>
  <!-- 평가 제외 — '표본 부족' 과 **다른 안내**여야 한다. 전자는 영영 점수가 안 나온다. -->
  <div class="ba-note ba-note--excluded">
    <b>평가 대상이 아닙니다.</b>
    <?= h($target['eval_exclude_reason'] ?: '사유가 기재되어 있지 않습니다.') ?>
    <br>
    <span class="ba-dim">
      배정에서 제외된 것은 아닙니다. 이 데이터로 역량을 재지 않는다는 뜻입니다.
    </span>
  </div>
  <?php endif; ?>

  <div class="ba-alert" id="ba-prof-error" hidden></div>

  <!-- ============ 레이더 + 계열 목록 ============ -->
  <section class="ba-panel">
    <h2>계열별 처리량</h2>
    <p class="ba-panel__hint">
      코스모스(무들) 컴포넌트 계열 기준입니다.
      <b>점수를 누르면 그 점수의 근거가 된 처리 건을 볼 수 있습니다.</b>
    </p>

    <div class="ba-radar-wrap">
      <!-- 인라인 SVG. 차트 라이브러리를 쓰지 않는다(모듈 의존성 0). -->
      <div class="ba-radar" id="ba-radar"></div>
      <div class="ba-catlist" id="ba-catlist"></div>
    </div>
  </section>

  <!-- ============ 종합 지표 ============ -->
  <section class="ba-panel">
    <h2>종합 지표</h2>
    <div class="ba-metrics" id="ba-metrics"></div>
    <p class="ba-panel__hint" style="margin-top:12px">
      처리 속도와 소통 지표는 <b>산출하지 않습니다.</b>
      취합 시스템에 상태 변경 이력이 없어 리드타임 변별력이 없고(중앙값 1일),
      첫 응답 시간을 잴 수 없기 때문입니다.
    </p>
  </section>

  <!-- ============ 이 점수가 무엇이고 무엇이 아닌가 ============ -->
  <section class="ba-panel ba-whatis">
    <h2>이 숫자는 무엇인가</h2>

    <div class="ba-whatis__row">
      <h3>처리량</h3>
      <div>
        <p class="ba-whatis__f">
          <code>처리량 = min(100, 내 난이도 가중 처리량 ÷ 기준값 × 100)</code>
        </p>
        <ul>
          <li>슬랙 취합 시스템에 기록된 <b>최근 6개월</b> 처리 건만 셉니다.</li>
          <li>건마다 난이도(1~5)를 그대로 더합니다. 난이도 5짜리 한 건 = 난이도 1짜리 다섯 건.</li>
          <li>기준값은 <b>처리 이력이 있는 사람들의 90분위</b>입니다.
              가장 많이 처리한 10% 쯤이면 100 이 됩니다.</li>
          <li>한 건이 여러 분야에 걸려도 계열 안에서는 한 번만 셉니다.</li>
        </ul>
        <p class="ba-whatis__not">
          <b>이 숫자가 말하지 않는 것</b> — 잘했는지, 정확했는지, 고객이 만족했는지는
          전혀 재지 않습니다. 난이도 3짜리를 30건 한 사람이 난이도 5짜리를 12건 한
          사람보다 높게 나옵니다. <b>슬랙에 안 올라가는 일은 아예 잡히지 않습니다</b> —
          기획·대외 협의·상주 업무가 그렇습니다.
        </p>
      </div>
    </div>

    <div class="ba-whatis__row">
      <h3>경험 범위</h3>
      <div>
        <p class="ba-whatis__f">
          <code>경험 범위 = 20건 이상 처리한 계열 수 ÷ 점수 대상 계열 수 × 100</code>
        </p>
        <ul>
          <li>계열은 코스모스(무들) 컴포넌트 기준 7개입니다(기획·QA 는 점수를 내지 않습니다).</li>
          <li><b>20건이 기준선</b>입니다. 19건 처리한 계열은 0으로 셉니다 —
              표본이 모자라 점수를 낼 수 없다는 뜻이지, 안 해 봤다는 뜻이 아닙니다.</li>
        </ul>
        <p class="ba-whatis__not">
          <b>이 숫자가 말하지 않는 것</b> — 넓게 할 줄 아는지를 재는 것이 아니라,
          <b>우리가 몇 개 계열에서 판정할 자료를 갖고 있는지</b>에 가깝습니다.
          한 분야를 깊게 파 온 사람은 낮게 나옵니다.
        </p>
      </div>
    </div>

    <div class="ba-whatis__row">
      <h3>이 점수를 어디에 쓰는가</h3>
      <div>
        <ul>
          <li><b>특정 프로젝트의 배정 후보를 추릴 때</b>만 씁니다.</li>
          <li>전사 종합 랭킹 화면은 만들지 않습니다. 사람끼리 줄 세우는 용도가 아닙니다.</li>
          <li>인사 평가·보상과 연결되지 않습니다.</li>
          <li>사실과 다르면 아래에서 <b>이의를 제기</b>해 주세요. 근거 건을 함께 봅니다.</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- ============ 분야별 실적 (근거) ============ -->
  <section class="ba-panel">
    <h2>분야별 처리 실적</h2>
    <p class="ba-panel__hint">
      점수는 계열 단위로만 냅니다. 아래는 어떤 일을 했는지 보기 위한 목록입니다.
    </p>
    <div class="ba-domainlist" id="ba-domainlist"></div>
  </section>

  <!-- ============ 이의 제기 ============ -->
  <?php if ($isSelf): ?>
  <section class="ba-panel">
    <h2>이의 제기</h2>
    <p class="ba-panel__hint">
      점수나 근거가 사실과 다르면 알려 주세요. 관리자가 확인해 회신합니다.
    </p>
    <div class="ba-form">
      <label>
        <span>대상 분야 <em class="ba-help">전체에 대한 것이면 비워 두세요</em></span>
        <select id="ba-obj-domain"><option value="">전체</option></select>
      </label>
      <label>
        <span class="ba-req">내용</span>
        <textarea id="ba-obj-content" rows="4"
                  placeholder="어떤 점이 사실과 다른지 적어 주세요."></textarea>
      </label>
      <div><button type="button" class="ba-btn ba-btn--primary" id="ba-obj-submit">이의 제기</button></div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ============ 관리자 보정 ============ -->
  <?php if ($canAdjust): ?>
  <section class="ba-panel ba-panel--admin">
    <h2>관리자 보정</h2>
    <p class="ba-panel__hint">
      보정치는 <b><?= (int)BS_ADJUST_MIN ?> ~ +<?= (int)BS_ADJUST_MAX ?></b> 범위이고
      <b>사유가 필수</b>입니다. 사유는 본인에게 그대로 표시됩니다.
    </p>
    <div class="ba-form">
      <div class="ba-form__row">
        <label>
          <span class="ba-req">보정치</span>
          <input type="number" id="ba-adj-value" min="<?= (int)BS_ADJUST_MIN ?>"
                 max="<?= (int)BS_ADJUST_MAX ?>" step="0.5" value="0">
        </label>
        <label>
          <span class="ba-req">사유</span>
          <input type="text" id="ba-adj-reason" maxlength="200"
                 placeholder="예) 사내 교육 담당으로 6개월간 업무 배분이 달랐음">
        </label>
      </div>
      <div><button type="button" class="ba-btn" id="ba-adj-submit">보정 반영</button></div>
    </div>

    <hr class="ba-hr">

    <h2>평가 대상 설정</h2>
    <p class="ba-panel__hint">
      평가 제외는 <b>배정 제외가 아닙니다.</b> 점수를 내지 않을 뿐입니다.
    </p>
    <div class="ba-form">
      <label class="ba-check" for="ba-eval-on">
        <input type="checkbox" id="ba-eval-on" <?= $evaluable ? 'checked' : '' ?>>
        <span>이 구성원의 처리량 점수를 산출한다</span>
      </label>
      <label id="ba-eval-reason-wrap" <?= $evaluable ? 'hidden' : '' ?>>
        <span class="ba-req">제외 사유</span>
        <input type="text" id="ba-eval-reason" maxlength="200"
               value="<?= h($target['eval_exclude_reason'] ?? '') ?>"
               placeholder="본인에게 표시됩니다">
      </label>
      <div><button type="button" class="ba-btn" id="ba-eval-submit">적용</button></div>
    </div>
  </section>
  <?php endif; ?>
</div>

<!-- 근거 드로어 — conventions.md §5.1 의 우측 드로어 패턴 -->
<div class="ba-drawer" id="ba-evidence" hidden>
  <div class="ba-drawer__box" role="dialog" aria-modal="true" aria-labelledby="ba-ev-title">
    <div class="ba-drawer__head">
      <h2 id="ba-ev-title">근거</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body" id="ba-ev-body"></div>
    <div class="ba-drawer__foot">
      <span class="ba-dim" id="ba-ev-count"></span>
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn" data-close>닫기</button>
    </div>
  </div>
</div>

<?php bs_layout_foot(); ?>

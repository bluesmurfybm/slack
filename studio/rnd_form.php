<?php
/** R&D 과제 발의·수정 (명세서 §8.2). 저장은 assign.js 가 api/rnd.php 로 보낸다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/presenter.php';
require_once __DIR__ . '/inc/repo/RndRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();
$repo = new RndRepo(bs_db());

$rndId = bs_param_int('id', 0);
$rnd   = null;

if ($rndId) {
    // find() 가 가시성을 이미 본다. 못 보는 과제는 null 로 온다 —
    // 보드로 돌려보내되 "없다" 와 "못 본다" 를 가르지 않는다.
    $rnd = $repo->find($rndId);
    if (!$rnd) {
        header('Location: rnd_board.php?err=notfound');
        exit;
    }
    if (!bs_rnd_can_edit($rnd)) {
        header('Location: rnd_board.php?err=denied');
        exit;
    }
}

$isNew = $rnd === null;
$v     = static fn(string $k, string $d = '') => h((string)($rnd[$k] ?? $d));

bs_layout_head(
    $user,
    $isNew ? '과제 발의' : '과제 수정',
    '업무 배정',
    $isNew
        ? '하고 싶은 R&D 과제를 올립니다. 승인되면 보드에 공개됩니다.'
        : '승인 전까지 고칠 수 있습니다.',
    'project'
);
?>

<div class="ba-form" id="ba-rnd-form" data-id="<?= $isNew ? '' : (int)$rnd['id'] ?>">

  <?php if (!$isNew): ?>
    <div class="ba-head__sub" style="margin-bottom:12px">
      <strong><?= $v('code') ?></strong> ·
      현재 상태 <strong><?= h(BS_RND_STATUS[$rnd['status']] ?? $rnd['status']) ?></strong>
    </div>
  <?php endif; ?>

  <!-- 과제명 -->
  <label class="ba-field ba-field--wide">
    <span>과제명 <em class="ba-req">필수</em></span>
    <input type="text" id="ba-r-name" maxlength="200" value="<?= $v('name') ?>"
           placeholder="예: 출석부 대량 처리 성능 개선 PoC">
  </label>

  <!-- 갈래 -->
  <label class="ba-field">
    <span>갈래</span>
    <select id="ba-r-f-category">
      <option value="">고르지 않음</option>
      <?php foreach (BS_RND_CATEGORY as $code => $label): ?>
        <option value="<?= h($code) ?>"
          <?= ($rnd['rnd_category'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- 공개 범위 -->
  <label class="ba-field">
    <span>공개 범위</span>
    <select id="ba-r-f-visibility">
      <?php foreach (BS_RND_VISIBILITY as $code => $label): ?>
        <option value="<?= h($code) ?>"
          <?= ($rnd['visibility'] ?? 'private') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <p class="ba-head__sub">
    <strong>비공개</strong>는 나와 관리자만 봅니다.
    <strong>공개(합류 가능)</strong>라야 다른 사람이 참여를 신청할 수 있습니다.
    <strong>공개(열람만)</strong>는 보이지만 신청은 받지 않습니다.
  </p>

  <!-- 배경·목적 -->
  <label class="ba-field ba-field--wide">
    <span>배경 · 목적</span>
    <textarea id="ba-r-summary" rows="4"
      placeholder="무엇이 문제이고 왜 지금 해야 하는지"><?= $v('summary') ?></textarea>
  </label>

  <!-- 검토 범위 · 기대 산출물 -->
  <label class="ba-field ba-field--wide">
    <span>검토 범위 · 기대 산출물</span>
    <textarea id="ba-r-notes" rows="4"
      placeholder="어디까지 볼 것인지, 끝나면 무엇이 남는지(문서·저장소·시연 등)"><?= $v('notes') ?></textarea>
  </label>

  <!-- 예상 기간 -->
  <label class="ba-field">
    <span>예상 시작</span>
    <input type="date" id="ba-r-start" value="<?= $v('dev_start') ?>">
  </label>
  <label class="ba-field">
    <span>예상 종료</span>
    <input type="date" id="ba-r-end" value="<?= $v('dev_end') ?>">
  </label>

  <!-- 신고 점유율 -->
  <label class="ba-field">
    <span>신고 점유율</span>
    <input type="number" id="ba-r-loadcap" min="0.05" max="1" step="0.05"
           value="<?= $rnd && $rnd['load_cap'] !== null ? h((string)(float)$rnd['load_cap']) : '' ?>"
           placeholder="예: 0.2">
  </label>

  <p class="ba-head__sub">
    0.2 는 업무 시간의 20% 를 이 과제에 쓰겠다는 뜻입니다.
    <strong>승인되기 전에는 가용도에 반영되지 않습니다.</strong>
    실제 반영은 사람이 합류하고 승인될 때 일어납니다.
  </p>

  <!-- 모집 -->
  <label class="ba-check" for="ba-r-f-recruiting">
    <input type="checkbox" id="ba-r-f-recruiting"
      <?= (int)($rnd['recruiting'] ?? 0) === 1 ? 'checked' : '' ?>>
    <span>팀원을 모집합니다</span>
  </label>

  <!-- 실행 -->
  <div class="ba-form__foot">
    <a class="ba-btn" href="rnd_board.php">취소</a>
    <?php if ($isNew): ?>
      <button type="button" class="ba-btn" id="ba-r-save-draft">임시 저장</button>
      <button type="button" class="ba-btn ba-btn--primary" id="ba-r-submit">발의하기</button>
    <?php else: ?>
      <button type="button" class="ba-btn ba-btn--primary" id="ba-r-submit">저장</button>
    <?php endif; ?>
  </div>
</div>

<?php bs_layout_foot(); ?>

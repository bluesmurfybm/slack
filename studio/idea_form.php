<?php
/** 아이디어 올리기·고치기 (032). 저장은 assign.js 가 api/idea.php 로 보낸다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/presenter.php';
require_once __DIR__ . '/inc/repo/IdeaRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user  = bs_require_login();
$repo  = new IdeaRepo(bs_db());
$id    = bs_param_int('id', 0);
$idea  = null;

if ($id) {
    $idea = $repo->find($id);
    if (!$idea || bs_idea_is_deleted($idea)) {
        header('Location: idea_board.php?err=notfound');
        exit;
    }
    if (!bs_idea_can_edit($idea)) {
        header('Location: idea_view.php?id=' . $id);
        exit;
    }
}

$isNew  = $idea === null;
$v      = static fn(string $k) => h((string)($idea[$k] ?? ''));
$picked = [];
if (!$isNew) {
    foreach ($repo->domainsOf([$id])[$id] ?? [] as $d) { $picked[$d['id']] = true; }
}
$domains = bs_db()->query(
    'SELECT id, name, domain_group FROM bs_domain WHERE is_active = 1 ORDER BY sort_no, id'
)->fetchAll(PDO::FETCH_ASSOC);
$byGroup = [];
foreach ($domains as $d) {
    $byGroup[$d['domain_group'] ?: 'cosmos'][] = $d;
}

bs_layout_head(
    $user,
    $isNew ? '아이디어 올리기' : '아이디어 고치기',
    '과업 편성/현황',
    $isNew ? '다듬어지지 않아도 됩니다. 문제든 제안이든 하나만 적어도 올라갑니다.'
           : '채택·반려되기 전까지 고칠 수 있습니다.',
    'idea'
);
?>

<?= bs_back_link($isNew ? 'idea_board.php' : 'idea_view.php?id=' . $id, $isNew ? '아이디어 목록' : '아이디어 상세') ?>

<div class="ba-form" id="ba-idea-form" data-id="<?= $isNew ? '' : $id ?>">

  <?php if (!$isNew): ?>
    <div class="ba-head__sub" style="margin-bottom:12px">
      <strong><?= $v('code') ?></strong> ·
      현재 상태 <strong><?= h(BS_IDEA_STATUS[$idea['status']] ?? $idea['status']) ?></strong>
    </div>
  <?php endif; ?>

  <label class="ba-field ba-field--wide">
    <span>제목 <em class="ba-req">필수</em></span>
    <input type="text" id="ba-i-title" maxlength="200" value="<?= $v('title') ?>"
           placeholder="예: 3회 결석하면 담당 교수에게 자동으로 알리기">
  </label>

  <!-- 쓰는 중에 비슷한 아이디어를 보여 준다. 같은 생각이 두 군데로 흩어지지 않게. -->
  <div class="ba-idea-similar" id="ba-i-similar" hidden></div>

  <label class="ba-field">
    <span>갈래</span>
    <select id="ba-i-f-category">
      <option value="">고르지 않음</option>
      <?php foreach (BS_RND_CATEGORY as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= ($idea['rnd_category'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="ba-check" for="ba-i-anon" style="align-self:end">
    <input type="checkbox" id="ba-i-anon" <?= !empty($idea['is_anonymous']) ? 'checked' : '' ?>>
    <span>익명으로 올리기 <span class="ba-dim">— 관리자 말고는 작성자를 볼 수 없습니다</span></span>
  </label>

  <label class="ba-field ba-field--wide">
    <span>무엇이 문제인가요</span>
    <textarea id="ba-i-problem" rows="4"
      placeholder="불편한 점, 놓치고 있는 기회, 반복되는 수작업 …"><?= $v('problem') ?></textarea>
  </label>

  <label class="ba-field ba-field--wide">
    <span>어떻게 하면 좋을까요</span>
    <textarea id="ba-i-proposal" rows="5"
      placeholder="떠오른 해결 방법. 구체적이지 않아도 됩니다"><?= $v('proposal') ?></textarea>
  </label>

  <label class="ba-field ba-field--wide">
    <span>기대 효과 <span class="ba-dim">선택</span></span>
    <textarea id="ba-i-effect" rows="3"
      placeholder="되면 무엇이 달라지나요 — 시간 절약, 오류 감소, 새 사업 기회 …"><?= $v('effect') ?></textarea>
  </label>

  <label class="ba-field ba-field--wide">
    <span>참고 링크 <span class="ba-dim">선택</span></span>
    <input type="url" id="ba-i-url" maxlength="<?= BS_URL_MAX ?>" value="<?= $v('url') ?>"
           placeholder="https://…  비슷한 서비스, 관련 문서, 슬랙 스레드">
  </label>

  <fieldset class="ba-field ba-field--wide ba-domains">
    <legend>분야 태그 <span class="ba-dim">선택 — 과제로 넘어가면 그대로 따라갑니다</span></legend>
    <?php foreach (BS_DOMAIN_GROUP as $gkey => $g): ?>
      <?php if (empty($byGroup[$gkey])) { continue; } ?>
      <div class="ba-domains__group">
        <h4 class="ba-domains__title"><?= h($g['label']) ?> <span class="ba-dim"><?= h($g['hint']) ?></span></h4>
        <div class="ba-domains__grid">
          <?php foreach ($byGroup[$gkey] as $d): ?>
            <label class="ba-check">
              <input type="checkbox" class="ba-i-dom" value="<?= (int)$d['id'] ?>"
                <?= isset($picked[(int)$d['id']]) ? 'checked' : '' ?>>
              <span><?= h($d['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </fieldset>
</div>

<div class="ba-formbar ba-rnd-formbar">
  <a class="ba-btn" href="idea_board.php">목록</a>
  <?php if (!$isNew): ?><a class="ba-btn" href="idea_view.php?id=<?= $id ?>">상세 보기</a><?php endif; ?>
  <span class="ba-spacer"></span>
  <button type="button" class="ba-btn ba-btn--primary" id="ba-i-submit"><?= $isNew ? '올리기' : '저장' ?></button>
</div>

<?php bs_layout_foot(); ?>

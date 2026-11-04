<?php
/** 아이디어 올리기·고치기 (032). 저장은 assign.js 가 api/idea.php 로 보낸다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/presenter.php';
require_once __DIR__ . '/inc/repo/IdeaRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user  = bs_require_login();
if (!IdeaRepo::schemaReady(bs_db())) {   // 032 없이 코드만 올라간 서버 — 쓰다가 실패하지 않게 미리 알린다
    bs_layout_head($user, '아이디어', '과업 편성/현황', '', 'idea');
    echo '<div class="ba-alert">이 서버의 DB 에 아이디어 표가 아직 없습니다. 관리자가 '
       . '<code>studio/sql/032_migration_idea.sql</code> 을 적용하면 쓸 수 있습니다.</div>';
    bs_layout_foot();
    exit;
}
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
// 참고 자료 (033). 033 이 없는 DB 에서는 예전 링크 칸 하나로 돈다.
$srcReady = IdeaRepo::sourcesReady(bs_db());
$sources  = $isNew || !$srcReady ? [] : array_map('bs_present_idea_source', $repo->sources($id));
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
    $isNew ? '다듬어지지 않아도 됩니다. 배경이든 아이디어든 하나만 적어도 올라갑니다.'
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
    <span>배경 · 계기</span>
    <textarea id="ba-i-problem" rows="4"
      placeholder="어떻게 이 생각을 하게 됐나요 — 새로 해 보고 싶은 것, 다른 곳에서 본 좋은 사례, 아쉬웠던 점 …"><?= $v('problem') ?></textarea>
  </label>

  <label class="ba-field ba-field--wide">
    <span>아이디어 · 제안</span>
    <textarea id="ba-i-proposal" rows="5"
      placeholder="무엇을 해 보면 좋을까요. 구체적이지 않아도 됩니다"><?= $v('proposal') ?></textarea>
  </label>

  <label class="ba-field ba-field--wide">
    <span>기대 효과 <span class="ba-dim">선택</span></span>
    <textarea id="ba-i-effect" rows="3"
      placeholder="되면 무엇이 달라지나요 — 시간 절약, 오류 감소, 새 사업 기회 …"><?= $v('effect') ?></textarea>
  </label>

  <?php if (!$srcReady): ?>
  <!-- 033 이 없는 DB — 예전 링크 칸 하나로 돈다 -->
  <label class="ba-field ba-field--wide">
    <span>참고 링크 <span class="ba-dim">선택</span></span>
    <input type="url" id="ba-i-url" maxlength="<?= BS_URL_MAX ?>" value="<?= $v('url') ?>"
           placeholder="https://…  비슷한 서비스, 관련 문서, 슬랙 스레드">
  </label>
  <?php endif; ?>

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

<?php if ($srcReady): ?>
<!-- ============ 참고 자료 (033) ============
     R&D 과제 수정 화면과 같은 패널(assign.js initSourcePanel)이고 보낼 곳만
     api/idea.php 다. 새로 올릴 때는 아직 번호가 없으니 골라 두기만 하고,
     [올리기] 로 아이디어가 생긴 뒤 이어서 보낸다(initIdeaForm). 과제·프로젝트로
     전환하면 그쪽 참고 자료로 복사된다. -->
<div class="ba-alert" id="ba-form-error" hidden></div>
<section class="ba-panel ba-rnd-src" data-src-panel="reference" data-src-api="api/idea.php"
         <?= $isNew ? 'data-src-pending="1"' : '' ?>>
  <h2>참고 자료 <span class="ba-dim">선택</span></h2>
  <p class="ba-panel__hint">화면 캡처, 조사 문서, 비슷한 서비스 주소처럼 생각을 설명해 줄 자료입니다.
    파일과 링크를 섞어 <?= IdeaRepo::SOURCE_MAX ?>개까지 붙일 수 있고, 과제·프로젝트로 전환하면 그대로 따라갑니다.</p>

  <div class="ba-source-tabs" role="tablist" aria-label="자료 종류">
    <button type="button" role="tab" data-src-tab="file" aria-selected="true">파일 올리기</button>
    <button type="button" role="tab" data-src-tab="link" aria-selected="false">링크 등록</button>
  </div>

  <div class="ba-source-pane" data-src-pane="file">
    <label>
      <span>파일</span>
      <input type="file" data-src-in="files" multiple accept=".xlsx,.pptx,.docx,.pdf,.jpg,.jpeg,.png,.gif,.webp">
      <em class="ba-help">xlsx · pptx · docx · pdf · 이미지 /
        한 개 최대 <?= (int)round(BS_UPLOAD_MAX_BYTES / 1048576) ?>MB / 한 번에 <?= BS_UPLOAD_MAX_FILES ?>개까지</em>
    </label>
    <button type="button" class="ba-btn" data-src-act="file"><?= $isNew ? '담기' : '올리기' ?></button>
  </div>

  <div class="ba-source-pane" data-src-pane="link" hidden>
    <div class="ba-form__row">
      <label><span class="ba-req">주소</span>
        <input type="url" data-src-in="link-url" maxlength="<?= BS_URL_MAX ?>" placeholder="https://…  비슷한 서비스, 관련 문서, 슬랙 스레드"></label>
      <label><span>표시 이름</span>
        <input type="text" data-src-in="link-title" maxlength="200" placeholder="비우면 주소에서 자동으로"></label>
    </div>
    <button type="button" class="ba-btn" data-src-act="link"><?= $isNew ? '담기' : '등록' ?></button>
  </div>

  <div class="ba-srclist" data-src-list>
    <?php if (!$sources): ?>
      <p class="ba-empty-inline"><?= $isNew ? '담은 자료가 없습니다. 아이디어를 올릴 때 함께 올라갑니다.' : '아직 등록된 자료가 없습니다.' ?></p>
    <?php else: foreach ($sources as $s): ?>
      <div class="ba-src" data-id="<?= (int)$s['id'] ?>">
        <span class="ba-src__kind"><?= h($s['kind_label']) ?></span>
        <span class="ba-src__title">
          <?php if (!empty($s['url'])): ?>
            <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener"><?= h($s['title']) ?></a>
          <?php else: ?><?= h($s['title']) ?><?php endif; ?>
        </span>
        <span class="ba-src__meta"><?= h((string)($s['size_label'] ?? '')) ?></span>
        <?= bs_source_acts_html($s) ?>
        <button type="button" class="ba-btn ba-btn--sm ba-btn--danger" data-src-del="<?= (int)$s['id'] ?>">삭제</button>
      </div>
    <?php endforeach; endif; ?>
  </div>
</section>
<?php endif; ?>

<div class="ba-formbar ba-rnd-formbar">
  <a class="ba-btn" href="idea_board.php">목록</a>
  <?php if (!$isNew): ?><a class="ba-btn" href="idea_view.php?id=<?= $id ?>">상세 보기</a><?php endif; ?>
  <span class="ba-spacer"></span>
  <button type="button" class="ba-btn ba-btn--primary" id="ba-i-submit"><?= $isNew ? '올리기' : '저장' ?></button>
</div>

<?= bs_source_viewer_html() ?>

<?php bs_layout_foot(); ?>

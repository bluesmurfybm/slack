<?php
/** R&D 과제 발의·수정 (명세서 §8.2). 저장은 assign.js 가 api/rnd.php 로 보낸다. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/presenter.php';
require_once __DIR__ . '/inc/repo/RndRepo.php';
require_once __DIR__ . '/inc/repo/ProjectRepo.php';       // 참고자료는 bs_project_source 를 같이 쓴다
require_once __DIR__ . '/inc/service/SourceUploader.php'; // humanSize()

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
// 참고 자료 (2026-10-19). 저장한 과제에만 붙는다.
$sources = $isNew ? [] : (new ProjectRepo(bs_db()))->sources((int)$rnd['id']);
$v     = static fn(string $k, string $d = '') => h((string)($rnd[$k] ?? $d));

bs_layout_head(
    $user,
    $isNew ? '과제 발의' : '과제 수정',
    '과업 편성/현황',
    $isNew
        ? '하고 싶은 R&D 과제를 올립니다. 승인되면 보드에 공개됩니다.'
        : '승인 전까지 고칠 수 있습니다.',
    'rnd'
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

  <!-- 분야 태그 -->
  <?php
  $picked = [];
  if (!$isNew) {
      foreach ($repo->domains((int)$rnd['id']) as $d) { $picked[(int)$d['id']] = true; }
  }
  $domains = bs_db()->query(
      'SELECT id, name, domain_group, category FROM bs_domain WHERE is_active = 1
        ORDER BY sort_no, id'
  )->fetchAll(PDO::FETCH_ASSOC);

  // 묶음별로 가른다. 코스모스 기능 목록만 보이면 사내 과제를 발의하는 사람이
  // 붙일 태그를 못 찾는다 — 묶음을 나눈 이유가 그것이다.
  $byGroup = [];
  foreach ($domains as $d) {
      $byGroup[$d['domain_group'] ?: 'cosmos'][] = $d;
  }
  ?>
  <fieldset class="ba-field ba-field--wide ba-domains">
    <legend>분야 태그</legend>
    <?php foreach (BS_DOMAIN_GROUP as $gkey => $g): ?>
      <?php if (empty($byGroup[$gkey])) { continue; } ?>
      <div class="ba-domains__group">
        <h4 class="ba-domains__title">
          <?= h($g['label']) ?>
          <span class="ba-dim"><?= h($g['hint']) ?></span>
        </h4>
        <div class="ba-domains__grid">
          <?php foreach ($byGroup[$gkey] as $d): ?>
            <label class="ba-check">
              <input type="checkbox" class="ba-r-dom" value="<?= (int)$d['id'] ?>"
                <?= isset($picked[(int)$d['id']]) ? 'checked' : '' ?>>
              <span><?= h($d['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <p class="ba-head__sub">
    과제가 다루는 분야를 고릅니다. <strong>종료한 뒤 경험 범위(역량)에 반영될 때
    계열을 가르는 유일한 근거</strong>입니다 — 비워 두면 반영되지 않습니다.
    <br>
    <span class="ba-dim">일반 묶음 중 <b>AI 환경 구축·운영</b>과 <b>AI 연계·응용</b>만
    역량에 쌓입니다. 나머지는 분류용 태그라 역량으로 잡히지 않습니다 —
    대신 누가 무엇을 했는지는 프로파일 근거에 남습니다.
    <br>
    모델 비교·정확도·토큰 비용을 재는 일은 <b>AI 연계·응용</b>에,
    CI·코드 품질·시험 자동화 같은 개발팀용 도구는 <b>업무생산성 도구</b>에
    넣습니다.</span>
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

<!-- ============ 참고 자료 (2026-10-19) ============
     프로젝트 등록 화면과 같은 패널이다(assign.js initSourcePanel). 보낼 곳만
     api/rnd.php 로 다르고, 권한은 과제를 고칠 수 있는 사람이다. 보관용이라
     분석하지 않는다 — R&D 는 WBS 를 뽑지 않는다. -->
<div class="ba-alert" id="ba-form-error" hidden></div>
<section class="ba-panel ba-rnd-src" data-src-panel="reference" data-src-api="api/rnd.php">
  <h2>참고 자료</h2>
  <p class="ba-panel__hint">기획서, 조사 자료, 화면 시안, 참고할 링크처럼 이 과제를 이해하는 데 필요한 자료입니다.
    파일·링크·직접 입력을 섞어 넣을 수 있습니다.</p>

  <?php if ($isNew): ?>
    <div class="ba-note">과제를 먼저 저장하면 이 자리에서 자료를 올릴 수 있습니다.</div>
  <?php else: ?>
  <div class="ba-source-tabs" role="tablist" aria-label="자료 종류">
    <button type="button" role="tab" data-src-tab="file" aria-selected="true">파일 올리기</button>
    <button type="button" role="tab" data-src-tab="link" aria-selected="false">링크 등록</button>
    <button type="button" role="tab" data-src-tab="text" aria-selected="false">직접 입력</button>
  </div>

  <div class="ba-source-pane" data-src-pane="file">
    <label>
      <span>파일</span>
      <input type="file" data-src-in="files" multiple accept=".xlsx,.pptx,.docx,.pdf,.jpg,.jpeg,.png,.gif,.webp">
      <em class="ba-help">xlsx · pptx · docx · pdf · 이미지 /
        한 개 최대 <?= (int)round(BS_UPLOAD_MAX_BYTES / 1048576) ?>MB / 한 번에 <?= BS_UPLOAD_MAX_FILES ?>개까지</em>
    </label>
    <button type="button" class="ba-btn" data-src-act="file">올리기</button>
  </div>

  <div class="ba-source-pane" data-src-pane="link" hidden>
    <div class="ba-form__row">
      <label><span class="ba-req">주소</span>
        <input type="url" data-src-in="link-url" placeholder="https://www.figma.com/file/..."></label>
      <label><span>표시 이름</span>
        <input type="text" data-src-in="link-title" maxlength="200" placeholder="비우면 주소에서 자동으로"></label>
    </div>
    <button type="button" class="ba-btn" data-src-act="link">등록</button>
  </div>

  <div class="ba-source-pane" data-src-pane="text" hidden>
    <label><span>표시 이름</span>
      <input type="text" data-src-in="text-title" maxlength="200" placeholder="비우면 첫 줄을 제목으로"></label>
    <label><span class="ba-req">내용</span>
      <textarea data-src-in="text" rows="6" placeholder="회의 메모, 조사 내용 등을 붙여 넣으세요."></textarea></label>
    <button type="button" class="ba-btn" data-src-act="text">등록</button>
  </div>

  <div class="ba-srclist" data-src-list>
    <?php if (!$sources): ?>
      <p class="ba-empty-inline">아직 등록된 자료가 없습니다.</p>
    <?php else: foreach ($sources as $s): ?>
      <div class="ba-src" data-id="<?= (int)$s['id'] ?>">
        <span class="ba-src__kind"><?= h(BS_SOURCE_KIND[$s['kind']] ?? $s['kind']) ?></span>
        <span class="ba-src__title">
          <?php if (!empty($s['url'])): ?>
            <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener"><?= h($s['title']) ?></a>
          <?php else: ?><?= h($s['title']) ?><?php endif; ?>
        </span>
        <span class="ba-src__meta"><?= !empty($s['file_size'])
            ? h(SourceUploader::humanSize((int)$s['file_size']))
            : (!empty($s['parsed_len']) ? number_format((int)$s['parsed_len']) . '자' : '') ?></span>
        <?= bs_source_acts_html($s) ?>
        <button type="button" class="ba-btn ba-btn--sm ba-btn--danger" data-src-del="<?= (int)$s['id'] ?>">삭제</button>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <?php endif; ?>
</section>

<?= bs_source_viewer_html() ?>

<?php bs_layout_foot(); ?>

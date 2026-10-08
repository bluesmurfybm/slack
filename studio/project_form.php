<?php
/** 프로젝트 등록·수정 — 명세서 §7.1 Step1(기본정보 + 기간 + 개발범위 출처). */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/repo/ProjectRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user      = bs_require_login();
$projectId = bs_param_int('id', 0) ?? 0;
$repo      = new ProjectRepo(bs_db());

$project = null;
if ($projectId > 0) {
    $project = $repo->find($projectId);
    if (!$project) {
        header('Location: project_list.php?err=notfound');
        exit;
    }
}

// 수정이면 그 프로젝트 기준, 신규면 프로젝트 없이 권한을 본다.
if (!bs_can(BS_CAP_PROJECT_MANAGE, $projectId > 0 ? $projectId : null)) {
    header('Location: project_list.php?err=denied');
    exit;
}

$sources = $projectId > 0 ? $repo->sources($projectId) : [];

// 화면이 쓸 값. 신규면 빈 값.
$v = static fn(string $k): string => (string)($project[$k] ?? '');

// ---------------------------------------------------------------------
// 서버가 첫 화면을 그릴 때만 쓰는 표시 헬퍼.
// 이후 목록 갱신은 assign.js 가 같은 규칙으로 다시 그린다.
// ---------------------------------------------------------------------

/** parse_status → 사람이 읽을 말. assign.js 의 PARSE_LABEL 과 같은 말이어야 한다. */
$parseLabel = static fn(string $s): string => match ($s) {
    'pending' => '분석 대기',
    'ok'      => '분석 완료',
    'fail'    => '분석 실패',
    'skip'    => '분석 안 함',
    default   => $s,
};

/** 바이트 → 사람이 읽을 크기. SourceUploader::humanSize 와 같은 규칙. */
$sizeLabel = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . 'B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024) . 'KB';
    }
    return round($bytes / 1048576, 1) . 'MB';
};

bs_layout_head(
    $user,
    $projectId ? '프로젝트 수정' : '프로젝트 등록',
    '과업 편성/현황',
    '프로젝트 정보와 개발 범위를 등록합니다. 저장한 뒤 출처 문서를 올릴 수 있습니다.',
    'project'
);
?>

<div class="ba-wizard" id="ba-form"
     data-project-id="<?= (int)$projectId ?>"
     data-code="<?= h($v('code')) ?>">

  <!-- 위저드 단계 표시. Step2~4 는 P4~P5 에서 열린다. -->
  <ol class="ba-steps" aria-label="등록 단계">
    <li class="on"><span>1</span> 프로젝트 등록</li>
    <li class="off"><span>2</span> 참여 가능 개발자</li>
    <li class="off"><span>3</span> 업무 배정</li>
    <li class="off"><span>4</span> 대시보드</li>
  </ol>

  <div class="ba-alert" id="ba-form-error" hidden></div>

  <!-- ============ 기본 정보 ============ -->
  <section class="ba-panel">
    <h2>기본 정보</h2>
    <p class="ba-panel__hint">
      <?php if ($projectId): ?>
        코드 <b><?= h($v('code')) ?></b> · 담당 <b><?= h($v('owner_name')) ?></b>
        · 상태 <b><?= h(BS_PROJECT_STATUS[$v('status')] ?? $v('status')) ?></b>
      <?php else: ?>
        프로젝트 코드는 저장할 때 자동으로 매겨집니다(PRJ-연도-일련번호).
      <?php endif; ?>
    </p>

    <div class="ba-form">
      <label>
        <span class="ba-req">프로젝트명</span>
        <input type="text" id="ba-in-name" maxlength="200"
               placeholder="예) OO대학교 LXP 고도화" value="<?= h($v('name')) ?>">
      </label>

      <div class="ba-form__row">
        <label>
          <span>고객 / 기관</span>
          <input type="text" id="ba-in-client" maxlength="100"
                 placeholder="예) OO대학교" value="<?= h($v('client')) ?>">
        </label>
        <label>
          <span>트랙</span>
          <select id="ba-in-track">
            <option value="">선택 안 함</option>
            <?php foreach (BS_PROJECT_TRACK as $code => $label): ?>
              <option value="<?= h($code) ?>"<?= $v('track') === $code ? ' selected' : '' ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <label>
        <span>개요</span>
        <textarea id="ba-in-summary" rows="3" maxlength="2000"
                  placeholder="무엇을 만드는 프로젝트인지 두세 줄로"><?= h($v('summary')) ?></textarea>
      </label>

      <?php if ($projectId): ?>
      <label>
        <span>상태</span>
        <select id="ba-in-status">
          <?php foreach (BS_PROJECT_STATUS as $code => $label): ?>
            <option value="<?= h($code) ?>"<?= $v('status') === $code ? ' selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
    </div>
  </section>

  <!-- ============ 기간 ============ -->
  <section class="ba-panel">
    <h2>기간</h2>
    <p class="ba-panel__hint">
      아직 안 정한 칸은 비워 두세요. 개발과 테스트가 겹치는 것은 정상이라 막지 않습니다.
    </p>

    <div class="ba-periods">
      <div class="ba-period">
        <span class="ba-period__label">개발</span>
        <input type="date" id="ba-in-dev-start" value="<?= h($v('dev_start')) ?>" aria-label="개발 시작일">
        <span class="ba-period__tilde">~</span>
        <input type="date" id="ba-in-dev-end" value="<?= h($v('dev_end')) ?>" aria-label="개발 종료일">
      </div>

      <div class="ba-period">
        <span class="ba-period__label">테스트</span>
        <input type="date" id="ba-in-test-start" value="<?= h($v('test_start')) ?>" aria-label="테스트 시작일">
        <span class="ba-period__tilde">~</span>
        <input type="date" id="ba-in-test-end" value="<?= h($v('test_end')) ?>" aria-label="테스트 종료일">
      </div>

      <div class="ba-period">
        <span class="ba-period__label">운영 배포</span>
        <input type="date" id="ba-in-deploy" value="<?= h($v('deploy_date')) ?>" aria-label="운영 배포일">
      </div>
    </div>

    <!-- 서버도 같은 규칙으로 다시 검사한다(ProjectRepo::assertPeriods). -->
    <p class="ba-period__warn" id="ba-period-warn" hidden></p>

    <!-- ┌────────────────────────────────────────────────────────────┐
         │ 계수를 숨기지 않는다                                        │
         │                                                            │
         │ 우리 회사의 AIDD 속도 향상 실측이 없다. 지어낸 숫자를        │
         │ 코드에 묻어 두면 아무도 그것이 추정인 줄 모른다. 칸에 담아   │
         │ 보여 주고, 고칠 수 있게 하고, 배정안에 박제한다.             │
         └────────────────────────────────────────────────────────────┘ -->
    <div class="ba-aidd">
      <label class="ba-aidd__on">
        <input type="checkbox" id="ba-in-aidd"
               <?= (int)($v('aidd_enabled') === '' ? 1 : $v('aidd_enabled')) === 0 ? '' : 'checked' ?>>
        <b>AIDD 고려</b>
      </label>
      <label class="ba-aidd__f">
        <span>공수 계수</span>
        <input type="number" id="ba-in-aidd-effort" step="0.05" min="0.5" max="1"
               value="<?= h($v('aidd_effort') ?: '0.85') ?>"
               title="난이도 환산 공수에 곱합니다. 0.85 = 15% 단축, 1.00 = 보정 없음">
      </label>
      <label class="ba-aidd__f">
        <span>점유 반영률</span>
        <input type="number" id="ba-in-aidd-load" step="0.05" min="0.5" max="1"
               value="<?= h($v('aidd_load') ?: '0.95') ?>"
               title="다른 업무 점유를 이 비율만큼만 반영합니다. 0.95 = 5% 할인, 1.00 = 보정 없음">
      </label>
    </div>
    <p class="ba-panel__hint ba-aidd__note">
      AI 로 병행이 되는 만큼을 <b>공수</b>와 <b>다른 업무 점유</b>에 반영합니다.
      <b>난이도는 바꾸지 않습니다</b> — AI 는 걸리는 시간을 줄이지 어려운 문제를
      쉽게 만들지 않고, 난이도를 낮추면 ★4 이상을 그 분야 상위자에게 주는 규칙이
      꺼집니다. 계수는 <b>실측이 아니라 가정</b>이라 화면에 드러내 두었습니다.
      공수는 태스크에 저장되므로, 끄거나 계수를 고치면 <b>[난이도 매기기]를 다시</b>
      돌려야 반영됩니다.
    </p>
  </section>

  <!-- ============ 개발 범위 출처 ============ -->
  <section class="ba-panel" id="ba-source-panel">
    <h2>업무 내용 및 범위</h2>
    <p class="ba-panel__hint">
      WBS 초안을 뽑을 재료입니다. 파일·링크·직접 입력을 섞어 넣을 수 있습니다.
      <b>파일 내용 분석은 다음 단계에서 합니다</b> — 지금은 올려두기만 합니다.
    </p>

    <?php if (!$projectId): ?>
      <div class="ba-note" id="ba-source-locked">
        기본 정보를 먼저 저장하면 이 자리에서 문서를 올릴 수 있습니다.
      </div>
    <?php endif; ?>

    <div class="ba-source-tabs" role="tablist" aria-label="출처 종류"
         <?= $projectId ? '' : 'hidden' ?>>
      <button type="button" role="tab" data-src-tab="file" aria-selected="true">파일 올리기</button>
      <button type="button" role="tab" data-src-tab="link" aria-selected="false">링크 등록</button>
      <button type="button" role="tab" data-src-tab="text" aria-selected="false">직접 입력</button>
    </div>

    <div class="ba-source-pane" data-src-pane="file" <?= $projectId ? '' : 'hidden' ?>>
      <label>
        <span>파일</span>
        <input type="file" id="ba-in-files" multiple
               accept=".xlsx,.pptx,.docx,.pdf,.jpg,.jpeg,.png,.gif,.webp">
        <em class="ba-help">
          xlsx · pptx · docx · pdf · 이미지 /
          한 개 최대 <?= (int)round(BS_UPLOAD_MAX_BYTES / 1048576) ?>MB /
          한 번에 <?= BS_UPLOAD_MAX_FILES ?>개까지
        </em>
      </label>
      <button type="button" class="ba-btn" id="ba-src-upload">올리기</button>
    </div>

    <div class="ba-source-pane" data-src-pane="link" hidden>
      <div class="ba-form__row">
        <label>
          <span class="ba-req">주소</span>
          <input type="url" id="ba-in-link-url" placeholder="https://www.figma.com/file/...">
        </label>
        <label>
          <span>표시 이름</span>
          <input type="text" id="ba-in-link-title" maxlength="200" placeholder="비우면 주소에서 자동으로">
        </label>
      </div>
      <button type="button" class="ba-btn" id="ba-src-link">등록</button>
    </div>

    <div class="ba-source-pane" data-src-pane="text" hidden>
      <label>
        <span>표시 이름</span>
        <input type="text" id="ba-in-text-title" maxlength="200" placeholder="비우면 첫 줄을 제목으로">
      </label>
      <label>
        <span class="ba-req">내용</span>
        <textarea id="ba-in-text" rows="8"
                  placeholder="회의록, 요구사항 메모, 메일 본문 등을 붙여 넣으세요."></textarea>
      </label>
      <button type="button" class="ba-btn" id="ba-src-text">등록</button>
    </div>

    <!-- 등록된 출처 목록. 서버가 그린 것을 assign.js 가 이후 갱신한다. -->
    <div class="ba-srclist" id="ba-srclist">
      <?php if (!$sources): ?>
        <p class="ba-empty-inline">아직 등록된 문서가 없습니다.</p>
      <?php else: ?>
        <?php foreach ($sources as $s): ?>
          <div class="ba-src" data-id="<?= (int)$s['id'] ?>">
            <span class="ba-src__kind"><?= h(BS_SOURCE_KIND[$s['kind']] ?? $s['kind']) ?></span>
            <span class="ba-src__title">
              <?php if (!empty($s['url'])): ?>
                <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener"><?= h($s['title']) ?></a>
              <?php else: ?>
                <?= h($s['title']) ?>
              <?php endif; ?>
            </span>
            <span class="ba-src__meta">
              <?php if (!empty($s['file_size'])): ?>
                <?= h($sizeLabel((int)$s['file_size'])) ?>
              <?php elseif (!empty($s['parsed_len'])): ?>
                <?= number_format((int)$s['parsed_len']) ?>자
              <?php endif; ?>
            </span>
            <span class="ba-src__parse ba-src__parse--<?= h($s['parse_status']) ?>">
              <?= h($parseLabel((string)$s['parse_status'])) ?>
            </span>
            <button type="button" class="ba-btn ba-btn--sm ba-btn--danger" data-src-del="<?= (int)$s['id'] ?>">삭제</button>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- ============ 특이점 / 기타 ============ -->
  <section class="ba-panel">
    <h2>특이점 / 기타</h2>
    <div class="ba-form">
      <label>
        <span>프로젝트 특이점</span>
        <textarea id="ba-in-notes" rows="3" maxlength="2000"
                  placeholder="주의할 점, 제약 조건, 과거 이력 등"><?= h($v('notes')) ?></textarea>
      </label>
      <label>
        <span>기타</span>
        <textarea id="ba-in-extra" rows="3" maxlength="2000"
                  placeholder="그 밖에 남길 내용"><?= h($v('extra')) ?></textarea>
      </label>
    </div>
  </section>

  <!-- ============ 하단 버튼 ============ -->
  <div class="ba-formbar">
    <a class="ba-btn" href="project_list.php">목록</a>
    <?php if ($projectId): ?>
      <a class="ba-btn" href="project_view.php?id=<?= (int)$projectId ?>">상세 보기</a>
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn ba-btn--danger" id="ba-delete">삭제</button>
      <button type="button" class="ba-btn ba-btn--primary" id="ba-save">저장</button>
    <?php else: ?>
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn ba-btn--primary" id="ba-save">저장하고 계속</button>
    <?php endif; ?>
  </div>
</div>

<?php
bs_layout_foot();

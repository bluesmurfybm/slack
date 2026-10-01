<?php
/** 프로젝트 상세 — 개요 / 참여 가능 개발자(Step2) / WBS / 배정. 진행상황 공유 화면. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/repo/ProjectRepo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user      = bs_require_login();
$projectId = bs_param_int('id', 0) ?? 0;

if (!$projectId) {
    header('Location: project_list.php');
    exit;
}

$repo    = new ProjectRepo(bs_db());
$project = $repo->find($projectId, bs_is_admin());
if (!$project) {
    header('Location: project_list.php?err=notfound');
    exit;
}

$sources   = $repo->sources($projectId);
$canManage = bs_can(BS_CAP_PROJECT_MANAGE, $projectId);

// 2단계(참여 가능 개발자)는 **인원을 고르는 자리**다. 여러 사람의 점수를
// 나란히 놓고 보므로, 배정을 짜는 사람에게만 연다. API 도 같은 선을 쓴다
// (api/candidate.php) — 화면만 막으면 API 를 직접 불러 뚫린다.
$canStaff = bs_can(BS_CAP_ALLOCATION_PROPOSE, $projectId);

// 가용도 판정 기간 — 개발 기간이 기본. 비면 Step2 를 열 수 없다.
$pFrom = $project['dev_start'] ?: ($project['test_start'] ?: null);
$pTo   = $project['deploy_date'] ?: ($project['test_end'] ?: ($project['dev_end'] ?: null));
$hasPeriod = $pFrom !== null && $pTo !== null && $pFrom <= $pTo;

// 분야 선택지.
//
// 두 화면이 쓰는 목록이 다르다 —
//  · Step2 후보 조건: 점수를 낼 수 없는 계열(기획 등)은 조건이 될 수 없으므로 뺀다.
//  · WBS 태스크 태그: 기획 업무도 실제로 존재하는 일이라 그대로 둔다. 고를 수
//    없게 막으면 사람이 엉뚱한 분야로 갖다 붙인다. 역량을 못 맞추는 것은
//    배정 단계에서 드러나면 된다.
$allDomains = bs_db()->query(
    'SELECT id, code, name, domain_group, category FROM bs_domain WHERE is_active = 1
      ORDER BY sort_no'
)->fetchAll(PDO::FETCH_ASSOC);

// 계열이 비어 있으면 점수가 아예 없다. 일반 묶음의 분야 대부분이 그렇다
// (013_migration_domain_group.sql). 조건으로 걸어 봐야 맞춰 볼 숫자가 없으므로
// 점수를 내지 않는 계열과 같이 뺀다.
$domains = array_values(array_filter(
    $allDomains,
    static fn($d) => ($d['category'] ?? '') !== ''
                     && !in_array($d['category'], BS_CATEGORY_NOT_SCORED, true)
));

bs_layout_head($user, $project['name'], '업무 배정', '', 'project');
?>

<div class="ba-pv" id="ba-pv"
     data-project-id="<?= (int)$projectId ?>"
     data-has-period="<?= $hasPeriod ? '1' : '0' ?>">

  <!-- ============ 머리 ============ -->
  <section class="ba-panel ba-pv__head">
    <div>
      <h2><?= h($project['name']) ?>
        <span class="ba-badge ba-badge--<?= h($project['status']) ?>">
          <?= h(BS_PROJECT_STATUS[$project['status']] ?? $project['status']) ?>
        </span>
      </h2>
      <p class="ba-panel__hint">
        <?= h($project['code']) ?>
        <?php if (!empty($project['client'])): ?> · <?= h($project['client']) ?><?php endif; ?>
        <?php if (!empty($project['track'])): ?>
          · <?= h(BS_PROJECT_TRACK[$project['track']] ?? $project['track']) ?>
        <?php endif; ?>
        · 담당 <?= h($project['owner_name']) ?>
      </p>
    </div>
    <div class="ba-spacer"></div>
    <?php if ($canManage): ?>
      <a class="ba-btn" href="project_form.php?id=<?= (int)$projectId ?>">수정</a>
    <?php endif; ?>
  </section>

  <!-- ============ 단계 탭 ============ -->
  <nav class="ba-steps ba-steps--tabs" role="tablist" aria-label="단계">
    <button type="button" role="tab" data-pv-tab="overview" aria-selected="true">
      <span>1</span> 개요
    </button>
    <button type="button" role="tab" data-pv-tab="candidate" aria-selected="false"
            <?= $canStaff ? '' : 'disabled title="배정을 맡은 PM 과 관리자만 볼 수 있습니다"' ?>>
      <span>2</span> 참여 가능 개발자
    </button>
    <button type="button" role="tab" data-pv-tab="wbs" aria-selected="false">
      <span>3</span> 업무 배정
    </button>
    <button type="button" role="tab" data-pv-tab="dash" aria-selected="false" disabled>
      <span>4</span> 대시보드
    </button>
  </nav>

  <div class="ba-alert" id="ba-pv-error" hidden></div>

  <!-- ============ 1. 개요 ============ -->
  <section data-pv-pane="overview">
    <div class="ba-panel">
      <h2>기간</h2>
      <div class="ba-periods ba-periods--ro">
        <div class="ba-period">
          <span class="ba-period__label">개발</span>
          <b><?= h(bs_date($project['dev_start']) ?: '미정') ?></b>
          <span class="ba-period__tilde">~</span>
          <b><?= h(bs_date($project['dev_end']) ?: '미정') ?></b>
        </div>
        <div class="ba-period">
          <span class="ba-period__label">테스트</span>
          <b><?= h(bs_date($project['test_start']) ?: '미정') ?></b>
          <span class="ba-period__tilde">~</span>
          <b><?= h(bs_date($project['test_end']) ?: '미정') ?></b>
        </div>
        <div class="ba-period">
          <span class="ba-period__label">운영 배포</span>
          <b><?= h(bs_date($project['deploy_date']) ?: '미정') ?></b>
        </div>
      </div>
    </div>

    <?php if (!empty($project['summary'])): ?>
    <div class="ba-panel"><h2>개요</h2><p class="ba-pre"><?= h($project['summary']) ?></p></div>
    <?php endif; ?>

    <div class="ba-panel">
      <h2>업무 내용 및 범위 <span class="ba-dim"><?= count($sources) ?>건</span></h2>
      <?php if (!$sources): ?>
        <p class="ba-empty-inline">등록된 문서가 없습니다.</p>
      <?php else: ?>
        <div class="ba-srclist">
          <?php foreach ($sources as $s): ?>
            <div class="ba-src">
              <span class="ba-src__kind"><?= h(BS_SOURCE_KIND[$s['kind']] ?? $s['kind']) ?></span>
              <span class="ba-src__title">
                <?php if (!empty($s['url'])): ?>
                  <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener"><?= h($s['title']) ?></a>
                <?php else: ?><?= h($s['title']) ?><?php endif; ?>
              </span>
              <span class="ba-src__parse ba-src__parse--<?= h($s['parse_status']) ?>">
                <?= h(['pending'=>'분석 대기','ok'=>'분석 완료','fail'=>'분석 실패','skip'=>'분석 안 함'][$s['parse_status']] ?? $s['parse_status']) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($project['notes']) || !empty($project['extra'])): ?>
    <div class="ba-panel">
      <h2>특이점 / 기타</h2>
      <?php if (!empty($project['notes'])): ?><p class="ba-pre"><?= h($project['notes']) ?></p><?php endif; ?>
      <?php if (!empty($project['extra'])): ?><p class="ba-pre ba-dim"><?= h($project['extra']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- ============ 2. 참여 가능 개발자 (명세서 §7.1 Step2) ============ -->
  <section data-pv-pane="candidate" hidden>
    <?php if (!$canStaff): ?>
      <div class="ba-note">
        <b>이 단계는 배정을 맡은 PM 과 관리자만 볼 수 있습니다.</b><br>
        여러 구성원의 처리량·경험 범위를 나란히 놓고 보는 화면이라,
        인원을 고르는 사람에게만 엽니다.
        본인 것은 <a href="member_profile.php">내 프로파일</a> 에서 언제든 볼 수 있습니다.
      </div>
    <?php elseif (!$hasPeriod): ?>
      <div class="ba-note">
        <b>프로젝트 기간이 없어 가용도를 계산할 수 없습니다.</b><br>
        개발 기간을 먼저 입력하세요.
        <?php if ($canManage): ?>
          <a href="project_form.php?id=<?= (int)$projectId ?>">프로젝트 수정</a>
        <?php endif; ?>
      </div>
    <?php else: ?>

    <div class="ba-panel">
      <h2>조건</h2>
      <p class="ba-panel__hint">
        기간 <b><?= h(bs_date($pFrom)) ?> ~ <?= h(bs_date($pTo)) ?></b> 기준입니다.
        <b>이 프로젝트 기준 순위이며 전사 순위가 아닙니다.</b>
      </p>

      <div class="ba-sliders">
        <label class="ba-slider">
          <span>최소 가용도 <b id="ba-c-av-v">0%</b></span>
          <input type="range" id="ba-c-av" min="0" max="100" step="5" value="0">
        </label>
        <label class="ba-slider">
          <span>최소 처리량 <b id="ba-c-cap-v">0점</b></span>
          <input type="range" id="ba-c-cap" min="0" max="100" step="5" value="0">
        </label>
      </div>

      <div class="ba-domainpick">
        <span class="ba-domainpick__label">필요 분야</span>
        <div class="ba-chips" id="ba-c-domains">
          <?php foreach ($domains as $d): ?>
            <label class="ba-chip">
              <input type="checkbox" value="<?= (int)$d['id'] ?>"
                     data-cat="<?= h($d['category']) ?>">
              <span><?= h($d['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="ba-panel__hint" style="margin:8px 0 0">
          고른 분야가 속한 <b>계열</b> 점수로 적합도를 봅니다.
          분야 단위로는 표본이 차지 않아 점수를 내지 않습니다.
        </p>
      </div>
    </div>

    <div class="ba-panel">
      <h2>후보 <span class="ba-dim" id="ba-c-count"></span></h2>
      <p class="ba-panel__hint" id="ba-c-scope"></p>

      <div class="ba-table-wrap">
        <table class="ba-table ba-ctable" id="ba-c-table">
          <thead>
            <tr>
              <th style="width:38px"></th>
              <th style="width:92px">이름</th>
              <th style="width:96px">역할</th>
              <th style="width:210px">가용도</th>
              <th style="width:86px">분야 매치</th>
              <th style="width:76px"
                  title="난이도를 감안한 6개월 처리량. 가장 많이 처리한 10% 를 100 으로 둡니다.&#10;잘했는지·정확했는지는 재지 않습니다.">처리량</th>
              <th style="width:74px"
                  title="7개 계열 중 20건 이상 처리한 계열의 비율입니다.&#10;20건이 안 되면 표본이 모자라 점수를 내지 않습니다.">경험 범위</th>
              <th style="width:74px">진행 건</th>
              <th style="width:80px"
                  title="이 과업 기준으로 처리량·분야 경험·경험 범위·경력을 합한 값입니다.&#10;가용도는 넣지 않습니다 — 옆 칸에 따로 있습니다.&#10;3단계 배정표의 적합도와는 다른 숫자입니다(그쪽은 가용도를 넣습니다).">적합도</th>
            </tr>
          </thead>
          <tbody><tr><td colspan="9" class="ba-loading">불러오는 중…</td></tr></tbody>
        </table>
      </div>

      <p class="ba-panel__hint" style="margin-top:12px">
        적합도에는 <b>가용도를 넣지 않습니다.</b> 섞으면 바쁜 전문가가 한가한
        초보보다 낮게 나옵니다. 두 축을 나란히 두고 고르시라는 뜻입니다 —
        그래서 3단계 배정표의 적합도와 숫자가 다릅니다(그쪽은 기계가 고르므로
        가용도를 넣습니다).

        가용도는 <b>확정 점유와 추정 점유를 나눠</b> 표시합니다.
        추정은 아직 진행 중인 슬랙 건에서 어림한 값이라 확정과 같은 무게로 보면 안 됩니다.
      </p>
      <p class="ba-panel__hint">
        <b>처리량</b>은 슬랙 취합 시스템에 기록된 6개월치 처리 건을 난이도로 가중해 더한 값입니다.
        잘했는지가 아니라 <b>얼마나 거쳐 갔는지</b>를 잽니다 — 낮다고 실력이 낮다는 뜻이 아니며,
        슬랙에 안 올라가는 일(기획·대외 협의·상주)은 아예 잡히지 않습니다.
        <b>경험 범위</b>는 7개 계열 중 20건 이상 처리한 계열의 비율입니다.
      </p>
    </div>

    <div class="ba-formbar">
      <span class="ba-dim" id="ba-c-picked">선택 0명</span>
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn" id="ba-c-clear">선택 해제</button>
      <button type="button" class="ba-btn ba-btn--primary" id="ba-c-confirm">후보 확정</button>
    </div>
    <?php endif; ?>
  </section>

  <!-- ============ 3. 업무 배정 — 먼저 WBS (명세서 §7.1 Step3) ============ -->
  <section data-pv-pane="wbs" hidden>
    <div class="ba-panel">
      <h2>업무 분해(WBS) <span class="ba-dim" id="ba-w-sum"></span></h2>
      <p class="ba-panel__hint">
        대 / 중 / 소 3단계까지 만들 수 있습니다. 번호(1, 1.1, 1.1.1)는 자리에 따라
        저장할 때 자동으로 매겨집니다.
        <b>확정한 태스크만 배정 대상이 됩니다.</b>
      </p>

      <?php if ($canManage): ?>
      <div class="ba-wbsbar">
        <button type="button" class="ba-btn" id="ba-w-add">대분류 추가</button>
        <button type="button" class="ba-btn" id="ba-w-paste">엑셀에서 붙여넣기</button>
        <span class="ba-wbsbar__sep"></span>
        <button type="button" class="ba-btn" id="ba-w-parse"
                title="업로드한 문서에서 글자를 뽑습니다">문서 분석</button>
        <button type="button" class="ba-btn" id="ba-w-extract"
                title="분석된 문서에서 WBS 초안을 만듭니다">문서에서 WBS 도출</button>
        <span class="ba-spacer"></span>
        <span class="ba-dim" id="ba-w-dirty"></span>
        <button type="button" class="ba-btn" id="ba-w-revert" hidden>되돌리기</button>
        <button type="button" class="ba-btn ba-btn--primary" id="ba-w-save">저장</button>
      </div>
      <div id="ba-w-docs" hidden></div>
      <?php else: ?>
      <div class="ba-note">읽기 전용입니다. WBS 를 고치려면 이 프로젝트의 담당 PM 이어야 합니다.</div>
      <?php endif; ?>

      <div class="ba-table-wrap">
        <table class="ba-table ba-wbs" id="ba-w-table">
          <thead>
            <tr>
              <th style="width:46px" title="확정한 태스크만 배정 대상이 됩니다">확정</th>
              <th style="width:64px">번호</th>
              <th>태스크</th>
              <th style="width:76px">공수<span class="ba-th-unit">M/D</span></th>
              <th style="width:72px">난이도</th>
              <th style="width:196px">계획 기간</th>
              <th style="width:120px">분야</th>
              <th style="width:128px"></th>
            </tr>
          </thead>
          <tbody><tr><td colspan="8" class="ba-loading">불러오는 중…</td></tr></tbody>
        </table>
      </div>

      <p class="ba-panel__hint" style="margin-top:12px">
        제목 칸에서 <b>Alt+→</b> 들여쓰기 · <b>Alt+←</b> 내어쓰기 ·
        <b>Alt+↑ / Alt+↓</b> 순서 이동 · <b>Enter</b> 같은 단계로 한 줄 추가.
        상세 설명과 분야는 행 오른쪽 <b>상세</b> 에서 답니다.
      </p>
    </div>

    <div class="ba-note" id="ba-w-next">
      확정한 태스크만 배정 대상이 됩니다.
    </div>

    <!-- ---- 배정안 (명세서 §6) ---- -->
    <div class="ba-panel" id="ba-al">
      <h2>배정안 <span class="ba-dim" id="ba-al-badge"></span></h2>
      <p class="ba-panel__hint">
        확정된 태스크의 말단만 배정합니다. 상위 태스크는 하위의 묶음이라
        같이 배정하면 공수가 두 번 잡힙니다.
        <b>확정하기 전에는 대시보드에 나오지 않습니다.</b>
      </p>

      <?php if ($canManage): ?>
      <div class="ba-wbsbar">
        <label class="ba-al__ver">
          <span>버전</span>
          <select id="ba-al-ver" class="ba-input"></select>
        </label>
        <button type="button" class="ba-btn" id="ba-al-propose">배정안 산출</button>
        <button type="button" class="ba-btn" id="ba-al-weights">가중치 조정</button>
        <span class="ba-spacer"></span>
        <button type="button" class="ba-btn ba-btn--primary" id="ba-al-confirm">최종 배정 완료</button>
      </div>

      <!-- 가중치 패널 — 명세서 §6.1 표 -->
      <div class="ba-wpanel" id="ba-al-wpanel" hidden>
        <div class="ba-sliders" id="ba-al-wsliders"></div>
        <p class="ba-panel__hint">
          합이 1 이 아니어도 됩니다 — <b>실제로 쓴 가중치의 합으로 나눠</b> 계산합니다.
          그래서 쓰지 않는 항목을 0 으로 둬도 다른 항목이 손해 보지 않습니다.
        </p>
        <div class="ba-formbar">
          <span class="ba-dim" id="ba-al-wnote"></span>
          <span class="ba-spacer"></span>
          <button type="button" class="ba-btn" id="ba-al-wreset">기본값</button>
          <button type="button" class="ba-btn ba-btn--primary" id="ba-al-wapply">이 가중치로 재산출</button>
        </div>
      </div>
      <?php endif; ?>

      <div class="ba-alert" id="ba-al-error" hidden></div>

      <!-- 인원별 부하 -->
      <div id="ba-al-load"></div>

      <!-- 태스크 × 담당자 -->
      <div class="ba-table-wrap">
        <table class="ba-table ba-altable" id="ba-al-table">
          <thead>
            <tr>
              <th style="width:64px">번호</th>
              <th>태스크</th>
              <th style="width:64px">공수</th>
              <th style="width:56px">난이도</th>
              <th style="width:150px">담당자</th>
              <th style="width:86px">역할</th>
              <th style="width:84px"
                  title="분야 경험·처리량·가용도·경력을 합한 값입니다.&#10;2단계 후보 표의 적합도와는 다른 숫자입니다 — 그쪽은 가용도를 넣지 않습니다.">적합도</th>
              <th style="width:96px"></th>
            </tr>
          </thead>
          <tbody><tr><td colspan="8" class="ba-empty">배정안이 없습니다.</td></tr></tbody>
        </table>
      </div>
      <p class="ba-panel__hint" style="margin-top:10px">
        적합도에는 <b>가용도가 들어갑니다.</b> 넣지 않으면 제일 잘하는 한 사람에게
        전부 몰립니다. 그래서 2단계 후보 표와 숫자가 다릅니다 — 같은 사람이라도
        보는 자리에 따라 달라지는 것이 맞습니다.

        적합도를 누르면 <b>왜 이 사람인지</b> 근거가 열립니다.
        담당자를 바꾸면 <b>수동</b> 으로 표시되고 엔진 점수는 지웁니다 —
        그 점수는 다른 사람 것이기 때문입니다.
      </p>
    </div>
  </section>
  <section data-pv-pane="dash" hidden>
    <div class="ba-note">대시보드는 P6 에서 구현합니다.</div>
  </section>
</div>

<!-- 배정 근거 드로어 -->
<div class="ba-drawer" id="ba-al-drawer" hidden>
  <div class="ba-drawer__box" role="dialog" aria-modal="true" aria-labelledby="ba-ar-title">
    <div class="ba-drawer__head">
      <h2 id="ba-ar-title">배정 근거</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body" id="ba-ar-body"></div>
    <div class="ba-drawer__foot">
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn" data-close>닫기</button>
    </div>
  </div>
</div>

<!-- WBS 태스크 상세 드로어 -->
<div class="ba-drawer" id="ba-task-drawer" hidden>
  <div class="ba-drawer__box" role="dialog" aria-modal="true" aria-labelledby="ba-td-title">
    <div class="ba-drawer__head">
      <h2 id="ba-td-title">태스크</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body" id="ba-td-body"></div>
    <div class="ba-drawer__foot">
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn" data-close>닫기</button>
    </div>
  </div>
</div>

<!-- 엑셀 붙여넣기 드로어 -->
<div class="ba-drawer" id="ba-paste-drawer" hidden>
  <div class="ba-drawer__box" role="dialog" aria-modal="true" aria-labelledby="ba-ps-title">
    <div class="ba-drawer__head">
      <h2 id="ba-ps-title">엑셀에서 붙여넣기</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body">
      <p class="ba-panel__hint">
        엑셀에서 범위를 복사해 아래에 붙여 넣으세요.
        <b>줄 앞의 탭 개수가 단계</b>입니다 — 탭 없음은 대분류, 한 개는 중분류, 두 개는 소분류.
      </p>
      <p class="ba-panel__hint">
        줄 앞 탭을 뺀 나머지 칸은 순서대로
        <b>제목 · 공수(M/D) · 난이도(1~5) · 시작일 · 종료일</b> 로 읽습니다.
        뒤쪽 칸은 비워 둬도 됩니다.
      </p>
      <p class="ba-panel__hint">
        단계를 왼쪽 빈 칸으로 표현하므로 <b>제목 칸은 비울 수 없습니다</b> —
        비우면 한 단계 들여쓴 것으로 읽힙니다. 아래 미리보기로 확인한 뒤 넣으세요.
      </p>
      <textarea id="ba-ps-text" class="ba-input ba-ps__ta" rows="12"
                placeholder="요구사항 분석&#10;&#9;현행 조사&#9;3&#9;2&#10;&#9;인터뷰&#9;2"></textarea>
      <div class="ba-ps__mode">
        <label><input type="radio" name="ba-ps-mode" value="append" checked> 아래에 이어 붙이기</label>
        <label><input type="radio" name="ba-ps-mode" value="replace"> 지금 트리를 <b>버리고</b> 이걸로 바꾸기</label>
      </div>
      <div class="ba-alert" id="ba-ps-error" hidden></div>
      <div id="ba-ps-preview"></div>
    </div>
    <div class="ba-drawer__foot">
      <span class="ba-dim" id="ba-ps-count"></span>
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn" data-close>취소</button>
      <button type="button" class="ba-btn ba-btn--primary" id="ba-ps-ok" disabled>넣기</button>
    </div>
  </div>
</div>

<script id="ba-domain-data" type="application/json"><?= json_encode(
    array_map(static fn($d) => [
        'id'        => (int)$d['id'],
        'name'      => $d['name'],
        'category'  => $d['category'],
        'cat_label' => BS_DOMAIN_CATEGORY[$d['category']]['label'] ?? ($d['category'] ?: '계열 없음'),
        'group'     => $d['domain_group'] ?: 'cosmos',
        'group_label' => BS_DOMAIN_GROUP[$d['domain_group'] ?? 'cosmos']['label'] ?? '',
        // 역량 점수를 내지 않는 분야. 골라도 되지만 배정 때 역량으로 맞춰 볼
        // 수 없다는 것을 화면이 말해 줘야 한다. 두 경우가 있다 —
        // 계열이 비었거나(일반 묶음), 점수를 내지 않는 계열(기획 등)이거나.
        'scored'    => ($d['category'] ?? '') !== ''
                       && !in_array($d['category'], BS_CATEGORY_NOT_SCORED, true),
    ], $allDomains),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>

<!-- 후보 근거 드로어 -->
<div class="ba-drawer" id="ba-cand-drawer" hidden>
  <div class="ba-drawer__box" role="dialog" aria-modal="true" aria-labelledby="ba-cd-title">
    <div class="ba-drawer__head">
      <h2 id="ba-cd-title">근거</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body" id="ba-cd-body"></div>
    <div class="ba-drawer__foot">
      <span class="ba-spacer"></span>
      <button type="button" class="ba-btn" data-close>닫기</button>
    </div>
  </div>
</div>

<?php bs_layout_foot(); ?>

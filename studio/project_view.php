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

bs_layout_head($user, $project['name'], '과업 편성/현황', '', 'project');
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

    <?php if ($canManage): ?>
    <!-- ============ 링크 분석 ============
         IA 시트에는 항목마다 기획 화면(피그마·드라이브) 주소가 걸려 있다.
         그 주소를 따라가 내용까지 읽어야 태스크의 성격을 알 수 있다.

         읽기는 바깥을 타므로 **크론 워커가 한다.** 이 화면은 넣고 진행률만
         본다 — 항목이 100개면 외부 호출이 100번이라 요청 안에서 못 한다. -->
    <div class="ba-panel ba-links" id="ba-links" data-project-id="<?= (int)$projectId ?>" hidden>
      <h2>기획 링크 <span class="ba-dim" id="ba-lk-sum"></span></h2>
      <p class="ba-panel__hint">
        출처 문서에 걸린 <b>구글 드라이브·피그마</b> 주소를 따라가 내용을 읽어 둡니다.
        읽어 둔 내용은 WBS 도출과 난이도 판정의 근거가 됩니다.
        <br>
        읽을 수 없는 주소(노션·사내 위키 등)는 <b>건너뜀</b>으로 남습니다 — 오류가 아닙니다.
      </p>

      <div class="ba-wbsbar">
        <button type="button" class="ba-btn" id="ba-lk-scan"
                title="출처 문서의 글자에서 주소를 찾습니다. 바깥으로 나가지 않습니다">링크 찾기</button>
        <button type="button" class="ba-btn ba-btn--primary" id="ba-lk-start"
                title="찾은 주소를 따라가 내용을 읽습니다">링크 분석 시작</button>
        <button type="button" class="ba-btn" id="ba-lk-retry" hidden
                title="실패한 것만 다시 읽습니다. 성공한 것은 다시 읽지 않습니다">실패한 것만 다시</button>
        <button type="button" class="ba-btn ba-btn--danger" id="ba-lk-cancel" hidden>멈추기</button>
        <span class="ba-sep"></span>
        <button type="button" class="ba-btn" id="ba-lk-score"
                title="읽어 둔 기획 내용을 근거로 난이도(1~5)와 예상공수(M/D)를 매깁니다&#10;비어 있는 칸만 채웁니다. 사람이 넣은 값은 건드리지 않습니다">난이도·공수 매기기</button>
        <button type="button" class="ba-btn" id="ba-lk-rescore"
                title="이미 매긴 것도 다시 매깁니다. 사람이 고친 값은 건드리지 않습니다">다시 매기기</button>
        <span class="ba-spacer"></span>
        <span class="ba-dim" id="ba-lk-msg"></span>
      </div>

      <!-- ┌──────────────────────────────────────────────────────────┐
           │ 왜 아무 일도 안 일어나는지 여기에 적는다                   │
           │                                                          │
           │ 2026-10-04 에 피그마가 며칠짜리 호출 제한을 걸었는데,     │
           │ 화면은 그냥 '대기' 만 보여 줬다. 원인을 찾는 데 하루가     │
           │ 걸렸다. 관리자가 연동을 꺼 둔 경우도 마찬가지다.          │
           └──────────────────────────────────────────────────────────┘ -->
      <div class="ba-alert ba-alert--wait" id="ba-lk-block" hidden></div>
      <!-- 거꾸로 — 쓸 수 있게 됐는데 전에 건너뛰기로 해 둔 것이 있을 때.
           이 줄이 없으면 연동을 다시 켜도 아무 일이 안 일어난다. -->
      <div class="ba-note ba-lk-revive" id="ba-lk-revive" hidden></div>

      <div class="ba-lk-prog" id="ba-lk-prog" hidden>
        <div class="ba-lk-bar"><i id="ba-lk-fill"></i></div>
        <span class="ba-dim" id="ba-lk-progtxt"></span>
      </div>

      <!-- ┌──────────────────────────────────────────────────────────┐
           │ 목록은 드로어로 뺐다                                      │
           │                                                          │
           │ 링크가 300건을 넘으면 표가 화면을 통째로 먹어, 바로 아래  │
           │ WBS 칸까지 내려가는 데만 한참 걸렸다. 평소에 볼 것은      │
           │ 숫자 몇 개뿐이고, 줄 하나하나는 뭔가 이상할 때만 본다.    │
           └──────────────────────────────────────────────────────────┘ -->
      <div class="ba-lk-chips" id="ba-lk-chips"></div>
    </div>
    <?php endif; ?>

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
                title="아직 안 읽은 문서만 읽습니다. 이미 읽은 것은 건드리지 않습니다">새 문서만 분석</button>
        <!-- ┌──────────────────────────────────────────────────────────┐
             │ 설명문 안의 글자 링크였다                                 │
             │                                                          │
             │ 자주 쓰는 동작인데 문단 한가운데 숨어 있어 아무도 못       │
             │ 찾았다. 게다가 옆의 [문서 분석] 은 눌러도 이미 읽은 것을  │
             │ 다시 읽지 않아, "눌렀는데 그대로" 가 반복됐다.            │
             │ 실제로 gid 수정 뒤 다시 읽어야 할 때 이것 때문에 막혔다.  │
             └──────────────────────────────────────────────────────────┘ -->
        <button type="button" class="ba-btn" id="ba-w-reparse-all"
                title="이미 읽은 문서까지 전부 다시 읽습니다. 주소나 설정을 고친 뒤에 쓰세요">전부 다시 분석</button>
        <button type="button" class="ba-btn" id="ba-w-extract"
                title="분석된 문서에서 WBS 초안을 만듭니다. AI 가 연결돼 있으면 AI 가 읽습니다&#10;문서가 크면 1분 넘게 걸립니다">문서에서 WBS 도출</button>
        <!-- 모델을 안 부른다. 들여쓰기·번호 규칙만 보므로 즉시 끝나고 비용도
             없다. 문서가 이미 잘 정리돼 있으면 이쪽이 결과도 더 예측 가능하다. -->
        <button type="button" class="ba-btn" id="ba-w-extract-rule"
                title="AI 없이 들여쓰기·번호 규칙만으로 뽑습니다. 즉시 끝나고 비용이 없습니다">규칙으로만 도출</button>
        <span class="ba-sep"></span>
        <!-- 표가 길면 바로 밑 [배정안] 까지 내려가는 길을 막는다.
             대·중분류는 남기고 그 아래만 접는다 — 통째로 숨기면 구조가 안 보인다. -->
        <button type="button" class="ba-btn ba-btn--sm" id="ba-w-fold"
                title="하위를 모두 접습니다. 대분류만 남습니다">모두 접기</button>
        <button type="button" class="ba-btn ba-btn--sm" id="ba-w-unfold"
                title="접어 둔 것을 모두 펼칩니다">모두 펼치기</button>
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
              <!-- 머리의 체크는 **보이는 줄 전체**가 아니라 WBS 전체를 다룬다.
                   접어 둔 하위가 빠지면 "전체 선택" 이 거짓말이 된다. -->
              <th style="width:52px" title="확정한 태스크만 배정 대상이 됩니다">
                <input type="checkbox" class="ba-wr__confirm" id="ba-w-cfall"
                       title="전체 확정 / 해제" aria-label="전체 확정">
                <span class="ba-th-unit">확정</span>
              </th>
              <th style="width:64px">번호</th>
              <th>태스크</th>
              <th style="width:76px">예상공수<span class="ba-th-unit">M/D</span></th>
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
        확정된 태스크를 배정합니다. <b>[가중치 조정]</b> 에서 <b>배정 단위</b> 를
        고르면 대분류·중분류로 묶어 한 사람에게 줄 수 있습니다 —
        묶어도 <b>공수는 하위의 합계 그대로</b>이고 두 번 잡히지 않습니다.
        <b>확정하기 전에는 대시보드에 나오지 않습니다.</b>
      </p>

      <?php if ($canManage): ?>
      <div class="ba-wbsbar">
        <label class="ba-al__ver">
          <span>버전</span>
          <select id="ba-al-ver" class="ba-input"></select>
        </label>
        <button type="button" class="ba-btn" id="ba-al-propose"
                title="가중치·방식·단위를 모두 기본값으로 산출합니다. 이전 안에서 손댄 항목도 가져오지 않습니다">배정안 산출 (기본값)</button>
        <button type="button" class="ba-btn" id="ba-al-weights"
                title="가중치와 배정 방식·단위를 펼칩니다. 이 단추로는 산출되지 않습니다">가중치·방식 조정</button>
        <span class="ba-spacer"></span>
        <button type="button" class="ba-btn ba-btn--primary" id="ba-al-confirm">최종 배정 완료</button>
      </div>

      <!-- 가중치 패널 — 명세서 §6.1 표 -->
      <div class="ba-wpanel" id="ba-al-wpanel" hidden>
        <!-- ┌──────────────────────────────────────────────────────────┐
             │ 방식과 가중치는 한자리에 둔다                              │
             │                                                          │
             │ 무작위를 고르면 가중치가 의미를 잃는다. 떨어뜨려 놓으면    │
             │ 슬라이더를 움직이고도 왜 결과가 안 바뀌는지 모른다.       │
             └──────────────────────────────────────────────────────────┘ -->
        <div class="ba-almode">
          <span class="ba-almode__l">배정 방식</span>
          <label><input type="radio" name="ba-al-method" value="weighted" checked>
            가중치</label>
          <label><input type="radio" name="ba-al-method" value="random_even">
            무작위 — 고르게</label>
          <label><input type="radio" name="ba-al-method" value="random_pure">
            무작위 — 완전 무작위</label>
        </div>
        <!-- ┌──────────────────────────────────────────────────────────┐
             │ 배정 단위 — 어느 덩어리를 한 사람에게 줄 것인가            │
             │                                                          │
             │ 말단까지 쪼개면 WBS 105건이 105명에게 갈 수 있다. 실무는  │
             │ 그렇지 않다 — 로그인 묶음은 한 사람이 통째로 맡는 게 맞다.│
             └──────────────────────────────────────────────────────────┘ -->
        <div class="ba-almode">
          <span class="ba-almode__l">배정 단위</span>
          <label><input type="radio" name="ba-al-level" value="leaf" checked>
            말단까지</label>
          <label><input type="radio" name="ba-al-level" value="d1">
            대분류 단위</label>
          <label><input type="radio" name="ba-al-level" value="d2">
            중분류 단위</label>
          <label><input type="radio" name="ba-al-level" value="auto">
            자동</label>
        </div>
        <div class="ba-almode__hint" id="ba-al-lhint" hidden></div>

        <div class="ba-almode__hint" id="ba-al-mhint" hidden></div>

        <div class="ba-sliders" id="ba-al-wsliders"></div>
        <!-- 항목별 설명은 슬라이더마다 한 줄씩 붙는다(assign.js 의 W_HINT).
             여기는 **여러 항목에 걸친 규칙** 하나만 남긴다 — 설명이 두
             군데로 갈리면 둘 다 안 읽힌다. -->
        <p class="ba-panel__hint ba-wpanel__note">
          합이 1 이 아니어도 됩니다 — <b>실제로 쓴 가중치의 합으로 나눠</b> 계산하므로,
          안 쓰는 항목을 0 으로 둬도 다른 항목이 손해 보지 않습니다.
        </p>
        <div class="ba-formbar">
          <span class="ba-dim" id="ba-al-wnote"></span>
          <span class="ba-spacer"></span>
          <!-- 씨앗을 적으면 같은 무작위 결과를 다시 만든다. 재현되지 않는
               배정안은 "왜 이 사람이죠?" 에 답할 수 없다. -->
          <label class="ba-seed" id="ba-al-seedbox" hidden>
            <span>씨앗</span>
            <input type="text" id="ba-al-seed" inputmode="numeric" placeholder="비우면 새로"
                   title="같은 씨앗을 넣으면 같은 배정이 다시 나옵니다">
          </label>
          <button type="button" class="ba-btn" id="ba-al-wreset">기본값</button>
          <button type="button" class="ba-btn ba-btn--primary" id="ba-al-wapply"
                  title="위에 보이는 값 그대로 산출합니다. 이전 안에서 손댄 항목은 유지합니다">조정한 값으로 재산출</button>
        </div>
      </div>
      <?php endif; ?>

      <div class="ba-alert" id="ba-al-error" hidden></div>
      <!-- 자동이 왜 그렇게 나눴는지. 답할 수 없는 자동은 아무도 안 쓴다. -->
      <div class="ba-note ba-al-splits" id="ba-al-splits" hidden></div>

      <!-- 인원별 부하 -->
      <div id="ba-al-load"></div>

      <!-- 태스크 × 담당자 -->
      <div class="ba-table-wrap">
        <table class="ba-table ba-altable" id="ba-al-table">
          <thead>
            <tr>
              <th style="width:64px">번호</th>
              <th>태스크</th>
              <th style="width:64px">예상공수</th>
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

<!-- 기획 링크 목록 드로어. 상태로 걸러 본다 -->
<div class="ba-drawer" id="ba-lk-drawer" hidden>
  <div class="ba-drawer__box" role="dialog" aria-modal="true" aria-labelledby="ba-lkd-title">
    <div class="ba-drawer__head">
      <h2 id="ba-lkd-title">기획 링크</h2>
      <button type="button" class="ba-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="ba-drawer__body">
      <div class="ba-lk-filter" id="ba-lk-filter"></div>
      <div class="ba-table-wrap">
        <table class="ba-table" id="ba-lk-table">
          <thead>
            <tr>
              <th style="width:76px">상태</th>
              <th>어느 항목</th>
              <th style="width:170px">읽어 온 문서</th>
              <th style="width:64px">글자</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <div class="ba-drawer__foot">
      <span class="ba-dim" id="ba-lk-shown"></span>
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
        <b>제목 · 예상공수(M/D) · 난이도(1~5) · 시작일 · 종료일</b> 로 읽습니다.
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

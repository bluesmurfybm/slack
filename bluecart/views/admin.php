<?php
/** 관리자 화면. */
declare(strict_types=1);
$isAdmin = in_array('ADMIN', $roles, true);
?>
<div class="bc-subtabs" role="tablist" aria-label="관리 기능">
  <button type="button" role="tab" data-adm="queue"    aria-selected="true">신청 물품 관리</button>
  <button type="button" role="tab" data-adm="stats"    aria-selected="false">통계</button>
  <?php if ($isAdmin): ?>
  <button type="button" role="tab" data-adm="category" aria-selected="false">물품 카테고리</button>
  <button type="button" role="tab" data-adm="roles"    aria-selected="false">처리 역할 배정</button>
  <button type="button" role="tab" data-adm="notify"   aria-selected="false">알림 설정</button>
  <?php endif; ?>
</div>

<!-- ============ 신청 물품 관리 ============ -->
<div id="bc-adm-queue">
  <div class="bc-pipe" id="bc-adm-pipe" aria-label="처리 단계별 건수"></div>

  <div class="bc-filters">
    <label class="bc-field">
      <span>연도</span>
      <select id="bc-af-year">
        <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>"<?= $y === $thisYear ? ' selected' : '' ?>><?= $y ?>년</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="bc-field">
      <span>사용처</span>
      <select id="bc-af-category">
        <option value="">전체</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="bc-field" style="flex:1 1 220px">
      <span>검색</span>
      <input type="search" id="bc-af-keyword" placeholder="물품명, 요청번호, 요청자">
    </label>
    <span class="bc-spacer"></span>
    <button type="button" class="bc-btn" id="bc-af-export">엑셀 받기</button>
    <button type="button" class="bc-btn" id="bc-af-reset">필터 초기화</button>
  </div>

  <div class="bc-subtabs" role="tablist" aria-label="처리 구분">
    <button type="button" role="tab" data-atab="todo"     aria-selected="true">내가 처리할 건</button>
    <button type="button" role="tab" data-atab="assigned" aria-selected="false">내가 맡은 건</button>
    <button type="button" role="tab" data-atab="progress" aria-selected="false">진행중 전체</button>
    <button type="button" role="tab" data-atab="stocked"  aria-selected="false">구비완료</button>
    <button type="button" role="tab" data-atab="rejected" aria-selected="false">반려·철회</button>
    <button type="button" role="tab" data-atab="all"      aria-selected="false">전체</button>
  </div>

  <div class="bc-table-wrap">
    <table class="bc-table" id="bc-adm-list">
      <thead>
        <tr>
          <th style="width:92px">요청번호</th>
          <th style="width:110px">사용처</th>
          <th>필요 물품</th>
          <th style="width:72px">갯수</th>
          <th style="width:96px">요청자</th>
          <th style="width:120px">요청일</th>
          <th style="width:104px">처리상태</th>
          <th style="width:96px">구매담당</th>
          <th style="width:124px">처리일 / 처리자</th>
          <th style="width:210px">처리</th>
        </tr>
      </thead>
      <tbody><tr><td colspan="10" class="bc-loading">불러오는 중…</td></tr></tbody>
    </table>
  </div>
  <div class="bc-pager" id="bc-adm-pager"></div>
</div>

<!-- ============ 통계 ============ -->
<div id="bc-adm-stats" hidden>
  <div class="bc-filters">
    <label class="bc-field">
      <span>연도</span>
      <select id="bc-st-year">
        <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>"<?= $y === $thisYear ? ' selected' : '' ?>><?= $y ?>년</option>
        <?php endforeach; ?>
      </select>
    </label>
    <span class="bc-spacer"></span>
    <button type="button" class="bc-btn" id="bc-st-export">통계 엑셀 받기</button>
  </div>
  <div id="bc-st-body"><p class="bc-loading">불러오는 중…</p></div>
</div>

<?php if ($isAdmin): ?>
<!-- ============ 카테고리 ============ -->
<div id="bc-adm-category" hidden>
  <div class="bc-panel">
    <h2>물품 카테고리</h2>
    <p class="bc-panel__hint">요청 화면의 사용처 선택 항목입니다. 이미 요청에 쓰인 카테고리는 삭제되지 않으니 사용 안 함으로 바꾸세요.</p>

    <div class="bc-filters" style="background:none;border:0;padding:0;margin-bottom:12px">
      <label class="bc-field"><span>코드</span>
        <input type="text" id="bc-cat-code" placeholder="BLUESOFT" style="width:150px"></label>
      <label class="bc-field"><span>표시명</span>
        <input type="text" id="bc-cat-name" placeholder="BLUESOFT" style="width:180px"></label>
      <label class="bc-field"><span>정렬</span>
        <input type="number" id="bc-cat-sort" value="0" style="width:80px"></label>
      <button type="button" class="bc-btn bc-btn--primary" id="bc-cat-add" style="align-self:flex-end">추가</button>
    </div>

    <div class="bc-table-wrap">
      <table class="bc-table" id="bc-cat-list">
        <thead><tr>
          <th style="width:140px">코드</th><th>표시명</th>
          <th style="width:90px">정렬</th><th style="width:100px">사용</th><th style="width:100px"></th>
        </tr></thead>
        <tbody><tr><td colspan="5" class="bc-loading">불러오는 중…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============ 역할 배정 ============ -->
<div id="bc-adm-roles" hidden>
  <div class="bc-panel">
    <h2>처리 역할 배정</h2>
    <p class="bc-panel__hint">구성원 목록에서 선택합니다. 각 역할은 여러 명을 지정할 수 있고, 지정된 전원에게 알림이 갑니다.</p>
    <label class="bc-field" style="margin-bottom:12px">
      <span>구성원 검색</span>
      <input type="search" id="bc-role-search" placeholder="이름 또는 아이디" style="width:260px">
    </label>

    <div class="bc-grid2">
      <div>
        <h3>검토승인자</h3>
        <p class="bc-panel__hint">요청을 승인하거나 반려합니다.</p>
        <div class="bc-picker" id="bc-role-REVIEWER"></div>
        <p style="margin-top:10px">
          <button type="button" class="bc-btn bc-btn--primary" data-save-role="REVIEWER">검토승인자 저장</button>
        </p>
      </div>
      <div>
        <h3>구매담당자</h3>
        <p class="bc-panel__hint">승인된 요청을 구매하고 입고 처리합니다.</p>
        <div class="bc-picker" id="bc-role-BUYER"></div>
        <p style="margin-top:10px">
          <button type="button" class="bc-btn bc-btn--primary" data-save-role="BUYER">구매담당자 저장</button>
        </p>
      </div>
      <div>
        <h3>관리자</h3>
        <p class="bc-panel__hint">카테고리, 역할, 알림 설정을 바꿀 수 있습니다.</p>
        <div class="bc-picker" id="bc-role-ADMIN"></div>
        <p style="margin-top:10px">
          <button type="button" class="bc-btn bc-btn--primary" data-save-role="ADMIN">관리자 저장</button>
        </p>
      </div>
    </div>
  </div>
</div>

<!-- ============ 알림 설정 ============ -->
<div id="bc-adm-notify" hidden>
  <div class="bc-panel">
    <h2>프로세스별 알림</h2>
    <p class="bc-panel__hint">각 처리 단계에서 누구에게 어떤 방법으로 알릴지 정합니다. 슬랙 개인 DM 은 봇 토큰이 설정되어 있어야 동작합니다.</p>

    <label class="bc-field" style="margin-bottom:14px">
      <span>기본 슬랙 채널</span>
      <input type="text" id="bc-nt-channel" placeholder="#general" style="width:220px">
    </label>

    <div style="overflow-x:auto"><table class="bc-matrix" id="bc-nt-matrix"></table></div>

    <p style="margin-top:14px">
      <button type="button" class="bc-btn bc-btn--primary" id="bc-nt-save">알림 설정 저장</button>
    </p>
  </div>
</div>
<?php endif; ?>

<?php
/** 구성원 화면. 데이터는 app.js 가 api/requests.php 에서 채운다. */
declare(strict_types=1);
?>
<div class="bc-filters" style="justify-content:flex-start">
  <button type="button" class="bc-btn bc-btn--primary" id="bc-new">새 구매 요청</button>
  <span class="bc-head__sub">필요한 물품을 적어 올리면 검토승인자에게 바로 전달됩니다.</span>
</div>

<!-- 집계: 요청이 흘러가는 순서 그대로 배치한다. 누르면 해당 상태로 목록이 걸러진다. -->
<div class="bc-pipe" id="bc-pipe" aria-label="처리 단계별 건수"></div>

<div class="bc-filters">
  <label class="bc-field">
    <span>연도</span>
    <select id="bc-f-year">
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>"<?= $y === $thisYear ? ' selected' : '' ?>><?= $y ?>년</option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="bc-field">
    <span>사용처</span>
    <select id="bc-f-category">
      <option value="">전체</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="bc-field">
    <span>요청일</span>
    <input type="date" id="bc-f-from">
  </label>
  <label class="bc-field">
    <span>~</span>
    <input type="date" id="bc-f-to">
  </label>

  <label class="bc-field" style="flex:1 1 200px">
    <span>검색</span>
    <input type="search" id="bc-f-keyword" placeholder="물품명, 요청번호, 요청자, 비고">
  </label>

  <label class="bc-field">
    <span>정렬</span>
    <select id="bc-f-sort">
      <option value="recent">최신 요청 순</option>
      <option value="oldest">오래된 요청 순</option>
      <option value="status">처리 단계 순</option>
      <option value="item">물품명 순</option>
    </select>
  </label>

  <label class="bc-field" style="align-self:flex-end">
    <span>&nbsp;</span>
    <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 0">
      <input type="checkbox" id="bc-f-mine"> 내 요청만
    </span>
  </label>

  <span class="bc-spacer"></span>
  <button type="button" class="bc-btn" id="bc-f-export">엑셀 받기</button>
  <button type="button" class="bc-btn" id="bc-f-reset">필터 초기화</button>
</div>

<div class="bc-subtabs" role="tablist" aria-label="목록 구분">
  <button type="button" role="tab" data-tab="progress" aria-selected="true">구매 진행중 물품</button>
  <button type="button" role="tab" data-tab="stocked"  aria-selected="false">구비완료 물품</button>
  <button type="button" role="tab" data-tab="rejected" aria-selected="false">반려·철회</button>
  <button type="button" role="tab" data-tab="all"      aria-selected="false">전체</button>
</div>

<div class="bc-table-wrap">
  <table class="bc-table" id="bc-list">
    <thead>
      <tr>
        <th style="width:92px">요청번호</th>
        <th style="width:110px">사용처</th>
        <th>필요 물품</th>
        <th style="width:72px">갯수</th>
        <th style="width:96px">요청자</th>
        <th style="width:120px">요청일</th>
        <th style="width:104px">처리상태</th>
        <th style="width:124px">처리일 / 처리자</th>
        <th style="width:160px">처리</th>
      </tr>
    </thead>
    <tbody><tr><td colspan="9" class="bc-loading">불러오는 중…</td></tr></tbody>
  </table>
</div>

<div class="bc-pager" id="bc-pager"></div>

<?php
if (!isset($page)) {
    http_response_code(404);
    exit;
}
?>
  <div class="wrap">
    <?php if ($page['admin']): include __DIR__ . '/tabs.php'; else: ?>
      <div class="sch-board">
        <section class="sch-panel" aria-labelledby="sch-list-title">
          <div class="sch-head">
            <h2 id="sch-list-title">Schedule</h2>
            <span class="cnt" id="schCount"></span>
            <div class="sch-seg" role="tablist" aria-label="일정 범위">
              <button type="button" id="schUp" class="on" role="tab" aria-selected="true" onclick="setScheduleScope('up')">다가오는 발표</button>
              <button type="button" id="schPast" role="tab" aria-selected="false" onclick="setScheduleScope('past')">지난 발표</button>
            </div>
          </div>
          <div class="sch-list" id="schList"></div>
        </section>
        <section class="sch-panel" aria-labelledby="sch-cal-title">
          <div class="sch-head"><h2 id="sch-cal-title">Calendar</h2></div>
          <div class="sch-cal">
            <div class="sch-nav">
              <span class="ym" id="schMonth"></span>
              <button type="button" class="sch-icon" title="이전 달" aria-label="이전 달" onclick="moveScheduleMonth(-1)">
                <svg viewBox="0 0 24 24"><path d="m15 6-6 6 6 6" /></svg>
              </button>
              <button type="button" class="sch-icon" title="다음 달" aria-label="다음 달" onclick="moveScheduleMonth(1)">
                <svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6" /></svg>
              </button>
              <button type="button" class="sch-today" onclick="moveScheduleMonth(0)">오늘</button>
            </div>
            <div class="sch-grid" id="schDays"></div>
            <div class="sch-grid" id="schCells"></div>
            <div class="sch-legend">
              <span><i class="planned"></i>다가오는 발표</span>
              <span><i class="done"></i>지난 발표</span>
              <span><i class="mine"></i>내 발표</span>
            </div>
          </div>
        </section>
      </div>

      <section class="mystrip" id="mystrip">
        <div class="m"><b id="statDone">0회</b><span>내 발표</span></div>
        <div class="divider"></div>
        <div class="m"><b id="statUpcoming">0건</b><span>예정 · 준비중</span></div>
        <div class="divider"></div>
        <div class="m"><b id="statLikes">0</b><span>받은 좋아요</span></div>
        <div class="divider"></div>
        <div class="m"><b id="statFields">0개</b><span>내가 발표한 분야</span></div>
      </section>
    <?php endif; ?>

    <div class="toolbar" id="toolbar">
      <div class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="7" />
          <path d="M21 21l-4.3-4.3" />
        </svg>
        <input id="q" type="text" placeholder="제목·키워드·발표자 검색" oninput="listChanged()">
      </div>
      <div class="filters">
        <select id="f-field" onchange="listChanged()"></select>
        <select id="f-magazine" onchange="listChanged()"></select>
        <select id="f-round" onchange="listChanged()" style="display:none"></select>
        <select id="f-team" onchange="listChanged()"></select>
        <select id="f-status" onchange="listChanged()"></select>
        <select id="f-req" onchange="listChanged()">
          <option value="">필수·권장·일반</option>
          <option value="required">필수</option>
          <option value="recommended">권장</option>
          <option value="normal">일반</option>
        </select>
        <label class="check" id="myteamCheck" style="display:none">
          <input id="f-myteam" type="checkbox" onchange="listChanged()"> <span id="myteamLabel">우리 팀</span>
        </label>
        <label class="check"><input id="f-mine" type="checkbox" onchange="listChanged()"> 내가 예약한 것만</label>
        <?php if ($page['key'] === 'list'): ?>
        <div class="viewtoggle">
          <button type="button" id="vList" title="리스트로 보기" onclick="setLayout('list')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
            </svg>
          </button>
          <button type="button" id="vCard" title="카드로 보기" onclick="setLayout('card')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="3" width="7" height="7" rx="1.5" />
              <rect x="14" y="3" width="7" height="7" rx="1.5" />
              <rect x="3" y="14" width="7" height="7" rx="1.5" />
              <rect x="14" y="14" width="7" height="7" rx="1.5" />
            </svg>
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ledger-head">
      <span class="title" id="ledgerTitle">발표 아티클 목록</span>
      <span class="count" id="ledgerCount"></span>
    </div>
    <ul class="list" id="list"></ul>
  </div>

  <div class="scrim" id="scrim" onclick="closeDrawer()"></div>
  <aside class="drawer" id="drawer" aria-label="아티클 상세"></aside>

  <div class="overlay" id="matOverlay" onclick="if(event.target===this)closeMaterial()">
    <div class="cdialog">
      <h3 id="matTitle">발표용 자료</h3>
      <div id="matList"></div>
      <div class="mat-add-head">자료 추가</div>
      <div class="seg" id="seg-mat">
        <button type="button" id="mat-file" class="on" onclick="setMatMode('file')">파일 올리기</button>
        <button type="button" id="mat-link" onclick="setMatMode('link')">링크</button>
      </div>
      <div id="mat-file-box" class="mat-box">
        <label class="dropzone" id="mat-drop">
          <input id="mat-fileinput" type="file" hidden multiple>
          <span class="dz-icon">📎</span>
          <span class="dz-main">파일을 끌어다 놓거나 <b>클릭해서 선택</b> (여러 개 가능)</span>
          <span class="dz-sub" id="mat-filename">형식 제한 없음</span>
        </label>
      </div>
      <div id="mat-link-box" class="mat-box" style="display:none">
        <input id="mat-url" type="url" placeholder="https://...">
        <input id="mat-name" type="text" placeholder="표시 이름 (선택)">
      </div>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeMaterial()">닫기</button>
        <button class="btn-submit" onclick="submitMaterial()">추가</button>
      </div>
    </div>
  </div>

  <div class="overlay" id="viewOverlay" onclick="if(event.target===this)closeViewer()">
    <div class="viewer">
      <div class="viewer-hot"></div>
      <div class="viewer-head">
        <span id="viewName"></span>
        <span class="viewer-act">
          <button class="btn-mini" id="viewFull" onclick="toggleViewerFull()">전체화면</button>
          <a id="viewNewTab" class="btn-mini" href="#" target="_blank" rel="noopener">새 탭</a>
          <a id="viewDownload" class="btn-mini" href="#" download>내려받기</a>
          <button class="btn-mini ghost" onclick="closeViewer()">닫기</button>
        </span>
      </div>
      <div class="viewer-body" id="viewBody"></div>
    </div>
  </div>

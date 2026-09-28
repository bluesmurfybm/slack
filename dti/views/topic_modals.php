<?php
if (!isset($page)) {
    http_response_code(404);
    exit;
}
?>
  <div class="overlay" id="overlay" onclick="if(event.target===this)closeForm()">
    <div class="sheet">
      <div class="sheet-head">
        <div><span class="k" id="sheet-kicker">신규 등록</span>
          <h2 id="sheet-title">새 아티클 등록</h2>
        </div>
      </div>
      <div class="sheet-body">
        <div class="field">
          <label>제목 (아티클)</label>
          <input id="f-title" type="text" placeholder="아티클 제목">
        </div>
        <div class="two">
          <div class="field"><label>관련 팀</label><select id="f-team-in"></select></div>
          <div class="field"><label>분야</label><select id="f-field-in"></select></div>
        </div>
        <div class="field">
          <label>중요 키워드</label>
          <input id="f-keywords" type="text" placeholder="생성형AI">
        </div>
        <div class="field">
          <label>발표 구분</label>
          <div class="seg" id="seg-req">
            <button type="button" id="req-required" onclick="setReq('required')">필수</button>
            <button type="button" id="req-recommended" class="on" onclick="setReq('recommended')">권장</button>
            <button type="button" id="req-normal" onclick="setReq('normal')">일반</button>
          </div>
        </div>
        <div class="field">
          <label>매거진 / Volume / Page</label>
          <div class="triple">
            <select id="f-magazine-in" onchange="fillLatestIssue()"></select>
            <input id="f-volume" type="text" placeholder="Volume">
            <input id="f-page" type="text" placeholder="Page">
          </div>
          <p class="hint" id="issueHint" style="display:none"></p>
        </div>
        <div class="field">
          <label>회차</label>
          <select id="f-round-in"></select>
        </div>
        <div class="two">
          <div class="field">
            <label>발표 예정일 (선택)</label>
            <input id="f-planned" type="date">
          </div>
          <div class="field">
            <label>노출 상태</label>
            <select id="f-active">
              <option value="1">활성 (구성원에게 노출)</option>
              <option value="0">비활성 (숨김)</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label>비고</label>
          <textarea id="f-note" rows="2" placeholder="발표자 지정 사유, 참고 링크 등"></textarea>
        </div>
      </div>
      <div class="sheet-foot">
        <button class="btn-ghost" onclick="closeForm()">취소</button>
        <button class="btn-submit" onclick="submitForm()">저장</button>
      </div>
    </div>
  </div>

  <div class="overlay" id="confirmOverlay" onclick="if(event.target===this)closeConfirm()">
    <div class="cdialog">
      <h3>아티클 삭제</h3>
      <p id="confirmText"></p>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeConfirm()">취소</button>
        <button class="btn-danger" onclick="doDelete()">삭제</button>
      </div>
    </div>
  </div>

  <div class="overlay" id="assignOverlay" onclick="if(event.target===this)closeAssign()">
    <div class="cdialog">
      <h3>발표자 지정</h3>
      <p id="assignSubject"></p>
      <div class="field">
        <label>발표자</label>
        <select id="assignWho"></select>
      </div>
      <div class="field">
        <label>발표 예정일 (선택)</label>
        <input id="assignDate" type="date">
      </div>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeAssign()">취소</button>
        <button class="btn-submit" onclick="submitAssign()">저장</button>
      </div>
    </div>
  </div>

<?php
require_once __DIR__ . '/../bootstrap.php';

$page = dti_page_begin('rounds');
include __DIR__ . '/../views/head.php';
?>
  <div class="wrap">
    <?php include __DIR__ . '/../views/tabs.php'; ?>
    <section class="roundspage" id="roundsPage"></section>
  </div>

  <div class="overlay" id="roundOverlay" onclick="if(event.target===this)closeRoundModal()">
    <div class="cdialog">
      <h3 id="roundTitle">회차 추가</h3>
      <p>여러 매거진 호를 한 회차로 묶습니다.</p>
      <div class="field">
        <label>회차 번호</label>
        <input id="roundNo" type="number" min="1" step="1" onkeydown="if(event.key==='Enter')submitRound()">
      </div>
      <div class="field">
        <label>이름 (선택)</label>
        <input id="roundName" type="text" placeholder="예: 10월 정기" onkeydown="if(event.key==='Enter')submitRound()">
      </div>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeRoundModal()">취소</button>
        <button class="btn-submit" onclick="submitRound()">저장</button>
      </div>
    </div>
  </div>

  <div class="overlay" id="issueOverlay" onclick="if(event.target===this)closeIssueModal()">
    <div class="cdialog">
      <h3 id="issueTitle">호 담기</h3>
      <p>고른 호의 아티클을 모두 이 회차에 담습니다. 다른 회차에 있던 아티클은 옮겨 옵니다.</p>
      <div class="issue-list" id="issueList"></div>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeIssueModal()">취소</button>
        <button class="btn-submit" id="issueSubmit" onclick="submitIssues()" disabled>담기</button>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../views/foot.php'; ?>

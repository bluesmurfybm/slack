<?php
if (!isset($page)) {
    http_response_code(404);
    exit;
}
?>
  <div class="overlay" id="dateOverlay" onclick="if(event.target===this)closeDate(null)">
    <div class="cdialog">
      <h3 id="dateTitle">발표 예정일</h3>
      <p id="dateHint"></p>
      <input id="dateInput" type="date" class="date-field">
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeDate(null)">취소</button>
        <button class="btn-submit" onclick="closeDate(document.getElementById('dateInput').value)">확인</button>
      </div>
    </div>
  </div>

  <div class="overlay" id="askOverlay" onclick="if(event.target===this)closeAsk(false)">
    <div class="cdialog">
      <h3 id="askTitle"></h3>
      <p id="askText"></p>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeAsk(false)">취소</button>
        <button class="btn-submit" id="askOk" onclick="closeAsk(true)">확인</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <!-- 인라인 onclick 이 함수를 전역에서 찾으므로 ES 모듈이 아닌 클래식 스크립트로 둔다 -->
  <script>const DTI_BASE = "<?= $page['base'] ?>";</script>
  <?php foreach ($page['scripts'] as $script): ?>
  <script src="<?= dti_asset($page, "static/{$script}.js") ?>"></script>
  <?php endforeach; ?>
</body>

</html>

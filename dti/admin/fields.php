<?php
require_once __DIR__ . '/../bootstrap.php';

$page = dti_page_begin('fields');
include __DIR__ . '/../views/head.php';
?>
  <div class="wrap">
    <?php include __DIR__ . '/../views/tabs.php'; ?>
    <section class="fieldspage" id="fieldsPage"></section>
  </div>

  <div class="overlay" id="fieldOverlay" onclick="if(event.target===this)closeFieldModal()">
    <div class="cdialog">
      <h3>분야 추가</h3>
      <p>아티클 등록 폼의 분야 선택지에 추가됩니다.</p>
      <div class="field">
        <label>분야 이름</label>
        <input id="newFieldName" type="text" placeholder="예: Data" onkeydown="if(event.key==='Enter')addField()">
      </div>
      <div class="row-btn">
        <button class="btn-ghost" onclick="closeFieldModal()">취소</button>
        <button class="btn-submit" onclick="addField()">추가</button>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../views/foot.php'; ?>

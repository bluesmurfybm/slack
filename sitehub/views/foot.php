<?php
/**
 * 드로어·시트·확인창·토스트 껍데기와 스크립트 목록. 내용은 전부 JS 가 채운다.
 * index.php 가 $scripts(static/*.js 이름 배열)를 정한 뒤 include 한다.
 */
if (!isset($me)) {
    http_response_code(404);
    exit;
}
$scripts = $scripts ?? ['core'];
$cfg = sitehub_config();
?>
  <div class="scrim" id="scrim" onclick="closeDrawer()"></div>
  <aside class="drawer" id="drawer" aria-hidden="true"></aside>

  <div class="overlay" id="overlay" onclick="if(event.target===this)closeSheet()">
    <div class="sheet" id="sheet"></div>
  </div>

  <div class="overlay" id="askOverlay" onclick="if(event.target===this)closeAsk(false)">
    <div class="cdialog">
      <h3 id="askTitle"></h3>
      <p id="askText"></p>
      <div class="row-btn">
        <button class="btn-ghost" type="button" onclick="closeAsk(false)">취소</button>
        <button class="btn-submit" type="button" id="askOk" onclick="closeAsk(true)">확인</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <!-- 인라인 onclick 이 함수를 전역에서 찾으므로 ES 모듈이 아닌 클래식 스크립트로 둔다 -->
  <script>
    const SH = {
      listUrl: <?= json_encode((string)($cfg['sitehub_list_url'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      canSync: <?= json_encode(!empty($canSync)) ?>,
      autoSyncMin: <?= (int)($cfg['sitehub_auto_sync_min'] ?? 10) ?>,   // 화면을 연 사람이 있으면 마지막 동기화가 이 분 넘게 지났을 때 뒤에서 동기화 (0 이면 끔)
      canStats: <?= json_encode(sitehub_can_stats($me)) ?>,   // 요약 네 칸 · 통계 화면 — 지정 계정만 (API 도 403)
      groups: <?= json_encode(sitehub_field_groups(), JSON_UNESCAPED_UNICODE) ?>,
      subs: <?= json_encode(sitehub_sub_fields(), JSON_UNESCAPED_UNICODE) ?>,
      procFields: <?= json_encode(sitehub_procedure_fields(), JSON_UNESCAPED_UNICODE) ?>,
      procCats: <?= json_encode(sitehub_procedure_categories(), JSON_UNESCAPED_UNICODE) ?>,
      soonDays: <?= (int)SITEHUB_SOON_DAYS ?>,
      rootXlsx: <?= json_encode(is_file(dirname(__DIR__, 2) . '/호스팅 정보_v.xlsx') ? '호스팅 정보_v.xlsx' : '', JSON_UNESCAPED_UNICODE) ?>,
      <?php $lc = @include dirname(__DIR__) . '/list_cols.php'; ?>
      titleIsSite: <?= json_encode(is_array($lc) && ($lc['title'] ?? '') !== '' && ($lc['title'] ?? '') === ($lc['site'] ?? null)) ?>,   // 리스트 제목 열 = 업체명이면 이력 머리로 작업내용을 쓴다
    };
  </script>
  <?php foreach ($scripts as $script): ?>
  <script src="<?= sitehub_asset("static/{$script}.js") ?>"></script>
  <?php endforeach; ?>
</body>

</html>

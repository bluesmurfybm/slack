<?php
/**
 * 통합 테스트 페이지 — 슬랙 요청(lists.php) + Gmail(gmail_test.php) 전환.
 *  상단 탭바 대신 플로팅 버튼(우하단) → 세로 공간 점유 0, 임베드 페이지가 화면 100% 사용.
 *  기존 페이지는 수정하지 않고 iframe 임베드. 마지막 탭은 localStorage 로 기억.
 */
require_once __DIR__ . '/auth.php';
require_login();
session_release();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>업무 허브</title>
<script>(function(){var t=localStorage.getItem("ui_theme");if(!t&&matchMedia("(prefers-color-scheme: dark)").matches)t="dark";if(t)document.documentElement.classList.add(t);})();</script>
<style>
  html.dark body { background:#1b1e22; }
  html.dark #switch button { background:#303134; border-color:#3c4043; color:#e8eaed; }
</style>
<style>
  * { box-sizing:border-box; }
  html, body { height:100%; }
  body { margin:0; font:14px/1.5 -apple-system,"Malgun Gothic",sans-serif; background:#f6f8fc; }
  /* 컨텐츠: iframe 이 화면 전체 */
  main { position:fixed; inset:0; }
  main iframe { position:absolute; inset:0; width:100%; height:100%; border:none; background:#fff; display:none; }
  main iframe.on { display:block; }
  /* 플로팅 전환 버튼: 클릭하면 다른 화면으로. 평소 반투명 → hover 시 선명 + 라벨 */
  #switch { position:fixed; right:18px; bottom:76px; z-index:99999; display:flex; align-items:center; gap:8px;
            flex-direction:row-reverse; }
  #switch button { width:46px; height:46px; border-radius:50%; border:1px solid #dadce0; cursor:pointer;
                   background:#fff; font-size:20px; box-shadow:0 3px 10px rgba(60,64,67,.3); opacity:.55;
                   transition:opacity .15s, transform .15s; }
  #switch:hover button { opacity:1; transform:scale(1.06); }
  #switch .lbl { display:none; background:#202124; color:#fff; font-size:12px; border-radius:6px; padding:5px 10px;
                 white-space:nowrap; }
  #switch:hover .lbl { display:block; }
</style></head><body>
<main>
  <iframe id="tab-slack" data-src="lists.php" title="슬랙 요청"></iframe>
  <iframe id="tab-gmail" data-src="gmail_test.php" title="Gmail"></iframe>
</main>
<div id="switch">
  <button id="swBtn" type="button"></button>
  <span class="lbl" id="swLbl"></span>
</div>
<script>
const KEY = "slackapi_main_tab";
const META = { slack: {icon:"📥", name:"슬랙 요청"}, gmail: {icon:"📧", name:"Gmail"} };
let cur = localStorage.getItem(KEY) === "gmail" ? "gmail" : "slack";
function show(tab){
  cur = tab;
  document.querySelectorAll("main iframe").forEach(f => {
    const on = f.id === "tab-" + tab;
    f.classList.toggle("on", on);
    if (on && !f.src) f.src = f.dataset.src;               // 첫 진입 때만 로드 (lazy)
  });
  const other = tab === "slack" ? "gmail" : "slack";
  document.getElementById("swBtn").textContent = META[other].icon;   // 버튼 = "다른 화면" 아이콘
  document.getElementById("swBtn").title = META[other].name + "(으)로 전환";
  document.getElementById("swLbl").textContent = META[other].name + " 열기";
  localStorage.setItem(KEY, tab);
}
document.getElementById("swBtn").addEventListener("click", () => show(cur === "slack" ? "gmail" : "slack"));
show(cur);
</script>
</body></html>

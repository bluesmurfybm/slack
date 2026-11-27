/* ClusterAgent 화면 동작. */
(function () {
  "use strict";

  // 사용자 메뉴 여닫기는 core/usermenu.php 가 한다

  // data-refresh="초" 가 붙은 본문은 그 간격으로 새로 불러 진행 상황을 따라간다
  var main = document.querySelector("[data-refresh]");
  if (main) {
    setTimeout(function () {
      if (!document.hidden) location.reload();
      else document.addEventListener("visibilitychange", function () { location.reload(); }, { once: true });
    }, parseInt(main.getAttribute("data-refresh"), 10) * 1000);
  }
})();

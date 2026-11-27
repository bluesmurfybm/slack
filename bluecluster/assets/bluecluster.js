/* ClusterAgent 화면 동작. */
(function () {
  "use strict";

  window.bclToggleMenu = function (e) {
    if (e) e.stopPropagation();
    document.getElementById("hdrUserMenu").classList.toggle("open");
  };
  document.addEventListener("click", function (e) {
    var um = document.getElementById("hdrUserMenu");
    if (um && !um.contains(e.target)) um.classList.remove("open");
  });

  // data-refresh="초" 가 붙은 본문은 그 간격으로 새로 불러 진행 상황을 따라간다
  var main = document.querySelector("[data-refresh]");
  if (main) {
    setTimeout(function () {
      if (!document.hidden) location.reload();
      else document.addEventListener("visibilitychange", function () { location.reload(); }, { once: true });
    }, parseInt(main.getAttribute("data-refresh"), 10) * 1000);
  }
})();

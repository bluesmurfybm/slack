/* 블루 클러스터 에이전트 화면 동작. */
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
})();

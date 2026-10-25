/* OnWatch 화면 동작. 페이지마다 window.OW.page 로 갈린다(list | edit | view).
   서버 응답 형식은 {ok:true,…} / {ok:false,error:"메시지"} — bluecart 와 같다. */
(function () {
  "use strict";
  var OW = window.OW || {};
  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";

  /* ---- 공통 ---- */
  window.owToggleMenu = function (e) {
    if (e) e.stopPropagation();
    document.getElementById("hdrUserMenu").classList.toggle("open");
  };
  document.addEventListener("click", function (e) {
    var um = document.getElementById("hdrUserMenu");
    if (um && !um.contains(e.target)) um.classList.remove("open");
    document.querySelectorAll(".more.open").forEach(function (m) {
      if (!m.contains(e.target)) { m.classList.remove("open"); m.querySelector("[data-menu]").setAttribute("aria-expanded", "false"); }
    });
  });

  var toastTimer;
  function toast(msg, bad) {
    var t = document.getElementById("owToast");
    if (!t) return;
    t.textContent = msg;
    t.className = "ow-toast on" + (bad ? " bad" : "");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.className = "ow-toast" + (bad ? " bad" : ""); }, bad ? 5000 : 2600);
  }

  function api(url, body) {
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF },
      body: JSON.stringify(body || {}),
      credentials: "same-origin"
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: "응답을 읽지 못했습니다 (" + r.status + ")" }; });
    }).then(function (d) {
      if (!d.ok) { var err = new Error(d.error || "처리하지 못했습니다."); err.data = d; throw err; }
      return d;
    });
  }

  /* ---- 목록: 받기 / 그만 받기 ---- */
  if (OW.page === "list") {
    document.querySelectorAll("[data-sub]").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var on = btn.classList.contains("on");
        btn.disabled = true;
        api("api/action.php", { id: +btn.dataset.sub, action: on ? "unsubscribe" : "subscribe" }).then(function (d) {
          btn.classList.toggle("on", !on);
          btn.setAttribute("aria-pressed", String(!on));
          btn.textContent = !on ? "🔔 받는 중" : "🔕 받기";
          toast(d.message);
        }).catch(function (err) { toast(err.message, true); })
          .then(function () { btn.disabled = false; });
      });
    });
  }

  /* ---- 상세 ---- */
  if (OW.page === "view") {
    var runBox = document.getElementById("runBox");
    var pollTimer = null;

    function setState(state) {
      if (!runBox) return;
      runBox.dataset.state = state;
      var cancel = runBox.querySelector('[data-act="cancel_run"]');
      if (cancel) cancel.hidden = state !== "queued";
      var run = runBox.querySelector('[data-act="run"]');
      if (run && state === "idle") run.hidden = false;
    }
    function poll() {
      clearTimeout(pollTimer);
      pollTimer = setTimeout(function () {
        fetch("api/state.php?id=" + OW.id, { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (d) {
          if (!d.ok) return;
          var s = d.state;
          setState(s.state);
          if (s.state === "idle") {
            if (s.last_run_id !== OW.lastRunId) {
              toast("실행이 끝났습니다. 결과를 불러옵니다…");
              setTimeout(function () { location.reload(); }, 700);
            }
            return;
          }
          poll();
        }).catch(poll);
      }, 5000);
    }
    if (OW.state === "queued" || OW.state === "running") poll();

    // 더보기 메뉴
    document.querySelectorAll("[data-menu]").forEach(function (b) {
      b.addEventListener("click", function (e) {
        e.stopPropagation();
        var m = b.closest(".more");
        var open = m.classList.toggle("open");
        b.setAttribute("aria-expanded", String(open));
      });
    });

    document.querySelectorAll("[data-act]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var act = btn.dataset.act;
        if (btn.dataset.confirm && !confirm(btn.dataset.confirm)) return;
        var body = { id: OW.id, action: act };
        if (btn.dataset.days) body.days = +btn.dataset.days;
        if (act === "baseline" && btn.hasAttribute("data-from-hint")) body.baseline = document.getElementById("hintText").value;
        btn.disabled = true;
        api("api/action.php", body).then(function (d) {
          if (d.redirect) { location.href = d.redirect; return; }
          if (act === "run" || act === "cancel_run") {
            toast(d.message);
            setState(d.state.state);
            if (act === "run") poll();
            btn.disabled = false;
            return;
          }
          toast(d.message);
          setTimeout(function () { location.reload(); }, 600);
        }).catch(function (err) { toast(err.message, true); btn.disabled = false; });
      });
    });

    // 소식 평가 — 같은 것을 다시 누르면 지운다
    document.querySelectorAll(".fb").forEach(function (box) {
      box.addEventListener("click", function (e) {
        var b = e.target.closest("[data-fb]");
        if (!b) return;
        var next = box.dataset.cur === b.dataset.fb ? "" : b.dataset.fb;
        api("api/feedback.php", { id: OW.id, finding_id: +box.dataset.fid, feedback: next }).then(function () {
          box.dataset.cur = next;
          box.querySelectorAll("[data-fb]").forEach(function (x) { x.classList.toggle("on", x.dataset.fb === next); });
          toast(next === "" ? "평가를 지웠습니다." : next === "useful" ? "고맙습니다." : "다음 실행부터 반영합니다.");
        }).catch(function (err) { toast(err.message, true); });
      });
    });
  }

  /* ---- 만들기·고치기 ---- */
  if (OW.page === "edit") {
    var form = document.getElementById("owForm");
    var preview = document.getElementById("schedPreview");

    function val(name) {
      var el = form.elements[name];
      if (!el) return "";
      if (el.type === "checkbox") return el.checked ? "1" : "";
      return el.value;
    }
    function data() {
      var o = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name) return;
        if (el.type === "radio") { if (el.checked) o[el.name] = el.value; return; }
        if (el.type === "checkbox") { o[el.name] = el.checked ? "1" : ""; return; }
        o[el.name] = el.value;
      });
      return o;
    }

    // 주기에 따라 보이는 칸
    function kind() {
      var iv = +val("interval_min");
      return iv >= 10080 ? "weekly" : iv >= 1440 ? "daily" : "hourly";
    }
    function syncSched() {
      var k = kind();
      form.querySelectorAll("[data-show]").forEach(function (el) {
        el.hidden = el.dataset.show.split(" ").indexOf(k) < 0;
      });
    }
    function syncSlack() {
      var box = form.querySelector("[data-slack]");
      // 관리자가 슬랙을 꺼 두면 칸 자체가 없다
      if (box && form.elements.notify_slack) box.hidden = !form.elements.notify_slack.checked;
    }
    form.addEventListener("change", function (e) {
      if (e.target.name === "interval_min") syncSched();
      if (e.target.name === "notify_slack") syncSlack();
      schedulePreview();
    });
    syncSched();
    syncSlack();

    // 웹훅: '__keep__' 이면 저장된 값 유지. 입력을 누르면 비워서 새 값을 받는다
    var hook = form.elements.slack_webhook;
    if (hook && hook.value === "__keep__") {
      hook.type = "text";
      hook.value = "";
      hook.dataset.keep = "1";
    }
    var clearHook = form.querySelector("[data-clear-webhook]");
    if (clearHook) clearHook.addEventListener("click", function () {
      hook.dataset.keep = "";
      hook.value = "";
      hook.placeholder = "기본 채널로 보냅니다(저장하면 전용 웹훅을 지웁니다)";
      clearHook.hidden = true;
    });

    // 종료일 빠른 선택 — 시작일 기준
    form.querySelectorAll("[data-ends] [data-days]").forEach(function (b) {
      b.addEventListener("click", function () {
        var s = val("starts_at") ? new Date(val("starts_at")) : new Date();
        if (isNaN(s)) s = new Date();
        s.setDate(s.getDate() + (+b.dataset.days));
        var p = function (n) { return String(n).padStart(2, "0"); };
        form.elements.ends_at.value = s.getFullYear() + "-" + p(s.getMonth() + 1) + "-" + p(s.getDate());
        schedulePreview();
      });
    });

    // 글자 수
    form.querySelectorAll("[data-count]").forEach(function (c) {
      var ta = document.getElementById(c.dataset.count), max = +c.dataset.max;
      function upd() {
        var n = ta.value.length;
        c.textContent = n.toLocaleString() + " / " + max.toLocaleString();
        c.classList.toggle("over", n > max);
      }
      ta.addEventListener("input", upd);
      upd();
    });

    // 예시 불러오기
    var tpl = document.getElementById("tplSel");
    if (tpl) tpl.addEventListener("change", function () {
      var t = (OW.templates || {})[tpl.value];
      tpl.value = "";
      if (!t) return;
      if ((form.elements.prompt.value.trim() || form.elements.title.value.trim()) && !confirm("적어 둔 이름·지시문·기준 상태를 예시로 바꿀까요?")) return;
      form.elements.title.value = t.title;
      form.elements.prompt.value = t.prompt;
      form.elements.baseline.value = t.baseline || "";
      var r = form.querySelector('input[name="interval_min"][value="' + t.interval + '"]');
      if (r) r.checked = true;
      syncSched();
      form.elements.prompt.dispatchEvent(new Event("input"));
      schedulePreview();
      toast("{ } 로 표시된 자리를 실제 내용으로 바꿔 주세요.");
      form.elements.title.focus();
    });

    // 일정 미리보기 — 계산은 서버(ow_next_run)에 맡긴다
    var pvTimer, pvSeq = 0;
    function schedulePreview() {
      clearTimeout(pvTimer);
      pvTimer = setTimeout(runPreview, 350);
    }
    function money(n) { return "$" + Number(n).toFixed(2); }
    function runPreview() {
      var seq = ++pvSeq;
      var body = data();
      if (hook && hook.dataset.keep && !hook.value) body.slack_webhook = "__keep__";
      api("api/preview.php", body).then(function (d) {
        if (seq !== pvSeq) return;
        if (d.error) { preview.innerHTML = '<span class="over">' + esc(d.error) + "</span>"; return; }
        var c = d.cost;
        preview.innerHTML =
          "<b>" + esc(d.label) + "</b> · 기간 중 <b>" + d.runs.toLocaleString() + "회</b> 실행" +
          (d.next.length ? "<ul>" + d.next.map(function (n) { return "<li>" + esc(n.label) + "</li>"; }).join("") + (d.runs > 3 ? "<li>…</li>" : "") + "</ul>" : "") +
          '<div class="muted" style="margin-top:6px">추정 비용: 한 번 ' + money(c.per_run_low) + "~" + money(c.per_run_high) +
          ", 한 달 약 " + money(c.month_low) + "~" + money(c.month_high) +
          (c.over_budget ? ' <span class="over">— 월 상한 ' + money(c.budget) + "보다 많을 수 있습니다. 주기를 늘리거나 상한을 올리세요</span>" : "") + "</div>";
      }).catch(function () {});
    }
    schedulePreview();

    function esc(s) {
      return String(s == null ? "" : s).replace(/[&<>"']/g, function (ch) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
      });
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      form.querySelectorAll(".err").forEach(function (x) { x.textContent = ""; });
      form.querySelectorAll(".field.bad").forEach(function (x) { x.classList.remove("bad"); });
      var body = data();
      if (hook && hook.dataset.keep && !hook.value) body.slack_webhook = "__keep__";
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      api("api/save.php", body).then(function (d) {
        location.href = d.url;
      }).catch(function (err) {
        btn.disabled = false;
        var fields = (err.data && err.data.fields) || {};
        var first = null;
        Object.keys(fields).forEach(function (k) {
          var box = form.querySelector('.err[data-for="' + k + '"]');
          if (box) {
            box.textContent = fields[k];
            var f = box.closest(".field");
            if (f) f.classList.add("bad");
            if (!first) first = box;
          }
          if (k === "max_searches" || k === "monthly_budget_usd") form.querySelector("details.adv").open = true;
        });
        if (first) first.closest(".field").scrollIntoView({ behavior: "smooth", block: "center" });
        toast(err.message, true);
      });
    });
  }
})();

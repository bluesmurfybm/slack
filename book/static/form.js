/* ---------- 신청 폼 ---------- */

let gubun = "직무무관";
let pickedThumbnail = "";
let editPickedThumbnail = "";
let searchT = null;
let editSearchT = null;
let lastBookResults = { form: [], edit: [] };

function openForm() {
  buildPicker();
  document.getElementById("f-date").value = todayISO();
  document.getElementById("overlay").classList.add("open");
  setTimeout(() => document.getElementById("f-title").focus(), 50);
}
function closeForm() {
  document.getElementById("overlay").classList.remove("open");
  ["f-title", "f-author", "f-pub", "f-price", "f-note"].forEach((i) => { document.getElementById(i).value = ""; });
  document.getElementById("dup").classList.remove("show");
  document.getElementById("price-notice").classList.remove("show");
  hideSearch("booksearch");
  pickedThumbnail = "";
  gubun = "직무무관";
  [...document.querySelectorAll("#seg button")].forEach((b) => b.classList.toggle("on", b.dataset.v === "직무무관"));
}
function setGubun(btn) {
  gubun = btn.dataset.v;
  [...document.querySelectorAll("#seg button")].forEach((b) => b.classList.remove("on"));
  btn.classList.add("on");
  document.getElementById("price-notice").classList.toggle("show", gubun === "직무관련");
}

// 신청자는 항상 로그인한 본인 — 다른 사람으로 바꿀 수 없다(서버도 같게 강제한다)
function buildPicker() {
  const c = APP.me.color || colorFor({ applicant: APP.me.name });
  document.getElementById("picker").innerHTML =
    `<span class="chip pick on" style="background:${esc(c)};border-color:${esc(c)};color:#fff;cursor:default">${esc(APP.me.name) || "본인"}</span>`;
}

/* ---------- 중복 검사 ---------- */

// 도서명을 공백·대소문자 무시로 완전일치시켜 "언제 누가 신청했는지" 를 보여 준다
function checkDup() {
  const t = normTitle(document.getElementById("f-title").value);
  const box = document.getElementById("dup");
  const ul = document.getElementById("dup-list");
  if (t.length < 2) { box.classList.remove("show"); return; }
  const hits = APP.data.filter((d) => normTitle(d.title) === t)
    .sort((a, b) => (b.date || "").localeCompare(a.date || ""));
  if (!hits.length) { box.classList.remove("show"); return; }
  ul.innerHTML = hits.map((h) =>
    `<li><b>${esc(h.title)}</b> <span class="who-line">— ${h.year}년 ${shortDate(h.date)} · ${esc(h.applicant)}님 신청 · ${h.done ? "처리완료" : "미처리"}</span></li>`).join("");
  box.classList.add("show");
}

/* ---------- 책 검색(카카오) ---------- */

function hideSearch(boxId) {
  const box = document.getElementById(boxId);
  if (!box) return;
  box.classList.remove("show");
  box.innerHTML = "";
}

function onTitleInput() {
  checkDup();
  pickedThumbnail = "";
  clearTimeout(searchT);
  const q = document.getElementById("f-title").value.trim();
  if (q.length < 2) { hideSearch("booksearch"); return; }
  searchT = setTimeout(() => searchBooks(q, "booksearch", "form"), 400);
}
function onEditTitleInput() {
  editPickedThumbnail = "";
  clearTimeout(editSearchT);
  const q = document.getElementById("ed-title").value.trim();
  if (q.length < 2) { hideSearch("ed-booksearch"); return; }
  editSearchT = setTimeout(() => searchBooks(q, "ed-booksearch", "edit"), 400);
}

async function searchBooks(q, boxId, target) {
  const box = document.getElementById(boxId);
  if (!box) return;
  try {
    const hits = await api(`/bookapi/booksearch?q=${encodeURIComponent(q)}`);
    lastBookResults[target] = hits;
    if (!hits.length) { hideSearch(boxId); return; }
    box.innerHTML = hits.map((b, i) => `<div class="bs-item" onclick="pickBook('${target}',${i})">
      <div class="bs-thumb">${b.thumbnail ? `<img src="${esc(b.thumbnail)}" alt="">` : ""}</div>
      <div class="bs-text">
        <div class="bs-title">${esc(b.title)}</div>
        <div class="bs-sub">${esc(b.author) || "저자 미상"} · ${esc(b.publisher) || "출판사 미상"}${b.price ? ` · ${fmt(b.price)}원` : ""}</div>
      </div>
    </div>`).join("");
    box.classList.add("show");
  } catch (e) {
    hideSearch(boxId);
  }
}

function pickBook(target, i) {
  const b = lastBookResults[target][i];
  if (!b) return;
  const ids = target === "form"
    ? { title: "f-title", author: "f-author", pub: "f-pub", price: "f-price", box: "booksearch" }
    : { title: "ed-title", author: "ed-author", pub: "ed-pub", price: "ed-price", box: "ed-booksearch" };
  document.getElementById(ids.title).value = b.title;
  document.getElementById(ids.author).value = b.author || "";
  document.getElementById(ids.pub).value = b.publisher || "";
  if (b.price) document.getElementById(ids.price).value = b.price.toLocaleString("ko-KR");
  if (target === "form") { pickedThumbnail = b.thumbnail || ""; checkDup(); }
  else editPickedThumbnail = b.thumbnail || "";
  hideSearch(ids.box);
}

/* ---------- 등록 ---------- */

async function submitForm() {
  const title = document.getElementById("f-title").value.trim();
  if (!title) { showToast("도서명을 입력해 주세요"); return; }
  const payload = {
    date: document.getElementById("f-date").value || todayISO(),
    gubun,
    title,
    author: document.getElementById("f-author").value.trim(),
    publisher: document.getElementById("f-pub").value.trim(),
    price: parsePriceInput(document.getElementById("f-price").value),
    note: document.getElementById("f-note").value.trim(),
    thumbnail: pickedThumbnail || null,
  };
  let rec;
  try {
    rec = await sendJSON("POST", "/bookapi/requests", payload);
  } catch (e) {
    showToast(e.message);
    return;
  }
  APP.data.push(rec);
  if (rec.year && !APP.years.includes(rec.year)) {
    APP.years.push(rec.year);
    APP.years.sort((a, b) => b - a);
  }
  closeForm();
  document.getElementById("q").value = "";
  APP.year = rec.year || APP.year;
  APP.editingId = null;
  buildYearSelect();
  render();
  showToast(`"${title}" 신청 등록 완료`);
}

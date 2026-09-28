// 목록 필터 → 주소 쿼리 키. 주소가 필터 상태의 원본이다 — 새로고침·뒤로가기가 여기서 복원된다
const LIST_QUERY = {
  q: "q", field: "f-field", magazine: "f-magazine", round: "f-round", team: "f-team",
  status: "f-status", req: "f-req", myteam: "f-myteam", mine: "f-mine",
};

const queryOf = key => new URLSearchParams(location.search).get(key) || "";

// 필터는 replaceState, 상세 열기처럼 뒤로가기로 되돌릴 동작만 pushState 로 남긴다.
// replaceState 는 지금 기록의 state 를 그대로 둔다
function writeQuery(changes, push, state = null) {
  const params = new URLSearchParams(location.search);
  Object.entries(changes).forEach(([key, value]) => value ? params.set(key, value) : params.delete(key));
  const qs = params.toString();
  const url = location.pathname + (qs ? `?${qs}` : "");
  if (url === location.pathname + location.search) return;
  if (push) history.pushState(state, "", url);
  else history.replaceState(history.state, "", url);
}

const inputValue = el => el.type === "checkbox" ? (el.checked ? "1" : "") : el.value.trim();

function listChanged() {
  const changes = {};
  Object.entries(LIST_QUERY).forEach(([key, id]) => { changes[key] = inputValue(document.getElementById(id)); });
  writeQuery(changes, false);
  render();
}

// 선택지를 그린 뒤에 부른다 — 선택지에 없는 값을 select 에 넣으면 빈 값이 된다
function applyListQuery() {
  Object.entries(LIST_QUERY).forEach(([key, id]) => {
    const el = document.getElementById(id);
    if (el.type === "checkbox") el.checked = queryOf(key) === "1";
    else el.value = queryOf(key);
  });
}

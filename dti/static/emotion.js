// kind 는 서버의 Emotion enum 과 같아야 한다 (features/emotion/service.py)
const EMOTIONS = [
  { kind: "like", icon: "👍", label: "좋아요" },
  { kind: "apply", icon: "🛠️", label: "바로 적용해볼래요" },
  { kind: "easy", icon: "💡", label: "설명이 쉬웠어요" },
  { kind: "new", icon: "✨", label: "처음 알았어요" },
];

const emotionCount = (t, kind) => (t.emotions || {})[kind] || 0;
const iReacted = (t, kind) => (t.my_emotions || []).includes(kind);

function likeButton(t) {
  const n = emotionCount(t, "like");
  const on = iReacted(t, "like");
  return `<button class="btn-mini${on ? " on" : ""}" onclick="react(${t.id},'like')"
    title="${on ? "좋아요 취소" : "이 발표에 좋아요"}">👍 좋아요${n ? ` ${n}` : ""}</button>`;
}

function reactionsHtml(t) {
  return `<div class="reactions">${EMOTIONS.map(e => {
    const n = emotionCount(t, e.kind);
    return `<button class="rx${iReacted(t, e.kind) ? " on" : ""}"
      onclick="react(${t.id},'${e.kind}')"><em>${e.icon}</em>${e.label}${n ? ` <small>${n}</small>` : ""}</button>`;
  }).join("")}</div>`;
}

async function react(id, kind) {
  try {
    await postJSON(`/magazineapi/topics/${id}/emotions/${kind}`);
    await reload();
  } catch (e) { showToast(e.message); }
}

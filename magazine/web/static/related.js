const RELATED_MAX = 3;
const RELATED_MIN_SCORE = 50;

// 점수 배점. 난수는 쓰지 않는다 — 같은 두 주제는 언제 봐도 같은 점수여야 한다.
const SAME_FIELD = 40;
const SHARED_KEYWORD = 22;
const SHARED_KEYWORD_CAP = 2;
const SAME_TEAM = 14;
const SAME_MAGAZINE = 10;

function keywordTokens(t) {
  return (t.keywords || "").toLowerCase().split(/[\s,/·]+/).filter(Boolean);
}

function relatedScore(a, b) {
  let score = 0;
  if (a.field && a.field === b.field) score += SAME_FIELD;
  const mine = keywordTokens(a);
  const shared = keywordTokens(b).filter(k => mine.includes(k));
  score += Math.min(shared.length, SHARED_KEYWORD_CAP) * SHARED_KEYWORD;
  if (a.team && a.team === b.team) score += SAME_TEAM;
  if (a.magazine && a.magazine === b.magazine) score += SAME_MAGAZINE;
  return Math.min(score, 99);
}

function relatedTo(t) {
  return APP.topics
    .filter(o => o.id !== t.id && o.active && !o.archived)
    .map(o => ({ topic: o, score: relatedScore(t, o) }))
    .filter(r => r.score >= RELATED_MIN_SCORE)
    .sort((x, y) => y.score - x.score || y.topic.id - x.topic.id)
    .slice(0, RELATED_MAX);
}

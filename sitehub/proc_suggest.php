<?php
/**
 * 처리 절차 등록 제안 — 이력(lab_history)의 작업내용에서 자주 나오는 말을 찾아 "이런 절차를 만들면 어떠냐"고 묶어 준다.
 * 규칙 기반(AI 호출 없음): 작업내용을 낱말로 쪼개 흔한 동작어(수정·변경…)를 빼고, 3건 이상에 나오는 낱말을 키워드로 삼는다.
 * 사이트를 지정하면 그 사이트의 이력만으로 따로 센다(사이트 전용 제안). 이력 한 건은 가장 많이 나온 키워드 하나에만 속한다. 이미 절차에 연결된 이력과 '무시'한 키워드는 제안에서 뺀다.
 */

const SITEHUB_PROC_SUGGEST_MIN = 3;
const SITEHUB_PROC_SUGGEST_MIN_SITE = 2;   // 한 사이트만 볼 때는 이력이 적어 2건부터

/** 동작·군더더기 말 — 키워드가 되면 모든 요청이 한 덩어리로 묶여 버린다 */
const SITEHUB_PROC_STOP = ['수정', '변경', '추가', '교체', '업데이트', '요청', '요정', '삭제', '처리', '관련', '확인', '작업', '홈페이지', '페이지',
    '내용', '문구', '부탁', '문의', '대한', '있는', '없는', '해당', '진행', '완료', '건', '및', '등', '또는', '이후', '이전', '현재', '하기', '해주세요',
    '필요', '설정', '적용', '반영', '요청드립니다', '사이트', '웹사이트', '관리자', '안됨', '안나옴', '있도록', '요망', '유상', '무상', '작성', '상단', '하단',
    '메인', '국문', '영문', '일부', '전체', '기타', '접속', '점검', '목록', '링크', '주소', '숨김', '갱신'];

const SITEHUB_PROC_ACTIONS = ['수정', '변경', '추가', '교체', '갱신', '업데이트', '삭제', '설정', '확인', '복구', '이전'];

/** 낱말 하나를 가볍게 정리 — 끝 조사 한 글자와 붙어 있는 동작어를 뗀다 */
function sitehub_proc_stem(string $w): string {
    if (mb_strlen($w) >= 3 && preg_match('/[을를은는의에로과와]$/u', $w)) $w = mb_substr($w, 0, -1);
    foreach (['업데이트', '수정', '변경', '추가', '교체', '처리', '요청', '삭제', '갱신'] as $act) {
        if (mb_strlen($w) > mb_strlen($act) + 1 && mb_substr($w, -mb_strlen($act)) === $act) { $w = mb_substr($w, 0, -mb_strlen($act)); break; }
    }
    return $w;
}

function sitehub_proc_tokens(string $text): array {
    $text = mb_strtolower(mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 160));
    $text = preg_replace('/[^\p{Hangul}a-z0-9]+/u', ' ', $text);
    $out = [];
    foreach (explode(' ', $text) as $w) {
        if ($w === '' || preg_match('/^\d+$/', $w)) continue;
        $w = sitehub_proc_stem($w);
        if (mb_strlen($w) < 2 || in_array($w, SITEHUB_PROC_STOP, true) || preg_match('/^\d/', $w)) continue;
        $out[$w] = true;
    }
    return array_keys($out);
}

function sitehub_proc_guess_category(string $kw): string {
    $map = ['domain' => ['ssl', 'dns', '도메인', '호스팅', 'spf', '메일', '인증서', '보안서버'],
            'account' => ['계정', '비밀번호', '로그인', '접속', '권한', '아이디'],
            'board' => ['게시판', '회원', '문의', '스팸'],
            'backup' => ['백업', '복구', '복원'],
            'deploy' => ['서버', '배포', '업로드', '용량', '이전', '회수'],
            'content' => ['팝업', '배너', '슬라이드', '인사말', '조직도', '사진', '이미지', '공지', '메뉴', '연사', '브로셔', '휴관', '일정']];
    foreach ($map as $cat => $words) foreach ($words as $w) if (mb_strpos($kw, $w) !== false) return $cat;
    return 'etc';
}

/**
 * 제안 목록 — [['keyword','title','category','count','site_count','site_id','examples','ids'], …] 많은 순
 * site_id: 묶음이 한 사이트에서만 나왔으면 그 id(사이트 전용 제안), 여러 곳이면 0(공통 제안)
 */
function sitehub_proc_suggestions(PDO $pdo, ?int $siteId = null): array {
    $min = $siteId ? SITEHUB_PROC_SUGGEST_MIN_SITE : SITEHUB_PROC_SUGGEST_MIN;
    $ignored = array_flip($pdo->query("SELECT keyword FROM lab_procedure_ignores")->fetchAll(PDO::FETCH_COLUMN));
    $rows = $pdo->query("SELECT h.id, h.site_id, h.body FROM lab_history h
                         WHERE h.parent_id IS NULL AND h.archived = 0 AND h.body IS NOT NULL AND h.body <> ''
                           AND NOT EXISTS (SELECT 1 FROM lab_procedure_uses u WHERE u.history_id = h.id)"
                         . ($siteId ? " AND h.site_id = " . (int)$siteId : ""))->fetchAll();
    $docs = [];
    $freq = [];
    foreach ($rows as $r) {
        $tok = sitehub_proc_tokens((string)$r['body']);
        if (!$tok) continue;
        $docs[$r['id']] = ['row' => $r, 'tok' => $tok, 'text' => implode(' ', $tok)];
        foreach ($tok as $t) $freq[$t] = ($freq[$t] ?? 0) + 1;
    }
    $kws = array_keys(array_filter($freq, fn($n) => $n >= $min));
    usort($kws, fn($a, $b) => ($freq[$b] <=> $freq[$a]) ?: (mb_strlen($b) <=> mb_strlen($a)));
    $groups = [];
    foreach ($docs as $id => $d) {
        foreach ($kws as $kw) {
            if (mb_strpos($d['text'], $kw) !== false) { $groups[$kw][] = $id; break; }
        }
    }
    $out = [];
    foreach ($groups as $kw => $ids) {
        if (count($ids) < $min || isset($ignored[$kw])) continue;
        $sites = [];
        $acts = [];
        $examples = [];
        foreach ($ids as $id) {
            $r = $docs[$id]['row'];
            $sites[(int)$r['site_id']] = true;
            foreach (SITEHUB_PROC_ACTIONS as $a) if (mb_strpos((string)$r['body'], $a) !== false) $acts[$a] = ($acts[$a] ?? 0) + 1;
            if (count($examples) < 3) $examples[] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['body'])), 0, 60);
        }
        arsort($acts);
        $act = $acts ? array_key_first($acts) : '수정';
        $out[] = ['keyword' => $kw, 'title' => mb_substr($kw, -mb_strlen($act)) === $act ? $kw : $kw . ' ' . $act, 'category' => sitehub_proc_guess_category($kw),
                  'count' => count($ids), 'site_count' => count($sites), 'site_id' => count($sites) === 1 ? (int)array_key_first($sites) : 0,
                  'examples' => $examples, 'ids' => $ids];
    }
    usort($out, fn($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['keyword'], $b['keyword']));
    return $out;
}

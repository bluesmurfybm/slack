<?php
/**
 * [테스트] 요청 카테고리 분류/집계 API (2단계 드릴다운 + 기간필터). 로그인 필요.
 *  - 분류: 제목·본문을 카테고리별 키워드로 "가중 점수"화(제목 매칭 ×3, 본문 ×1) → 최고점 카테고리 선택.
 *    동점 시 배열 우선순위. 전부 0점이면 기타. (첫 매칭 방식보다 오분류 적음)
 *  - 2단계: 선택된 대분류 안에서 같은 방식으로 세부분류.
 *  - GET ?scope=all|active|archived  &from=YYYY-MM-DD  &to=YYYY-MM-DD
 *  - 반환: {ok, scope, from, to, total, categories:[{key,label,count,pct,subs:[{label,count,pct,samples[]}]}]}
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
session_release();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const W_TITLE = 3;   // 제목 매칭 가중
const W_BODY  = 1;   // 본문 매칭 가중
const BODY_LEN = 500;

/** 대분류: [key, label, [키워드...]] — 동점 시 위쪽 우선 */
$CATS = [
    ['acad',   '학사·연동',        ['학사','연동','동기화','sync','반입','반출','수강신청','수강생','학번','교번','재수강','편람','학적','회원동기화']],
    ['security','보안·점검',        ['해킹','악성코드','취약점','보안','점검','감염','침해','디도스','ddos','백신','랜섬','피싱','방화벽']],
    ['attend', '출석·출결',        ['출석','출결','결석','지각','출석부']],
    ['media',  '동영상·미디어',    ['동영상','영상','재생','스트리밍','미디어','플레이어','인코딩','vod','자막','녹화','진도','시청']],
    ['assess', '과제·시험·성적',   ['과제','시험','퀴즈','quiz','assign','평가','성적','채점','제출','응시','문항','설문','카피킬러','표절']],
    ['course', '강좌·강의실',      ['강좌','강의실','course','코스','분반','개설','강의','클래스','주차','학습활동','수료','이수','수료증']],
    ['auth',   '로그인·계정·권한', ['로그인','로그아웃','계정','인증','sso','비밀번호','패스워드','권한','접속','세션','회원가입','가입','connection']],
    ['board',  '게시판·알림·메시지',['게시판','공지','알림','쪽지','메일','메시지','댓글','채팅','문자','sms','발송']],
    ['file',   '파일·데이터',      ['다운로드','업로드','엑셀','excel','파일','데이터','백업','csv','첨부','내보내기','이관','메타데이터']],
    ['ui',     '화면·UI·페이지',   ['화면','css','이미지','디자인','레이아웃','버튼','페이지','메뉴','아이콘','깨짐','정렬','팝업','링크']],
    ['config', '설정·기능개선',    ['설정','변경','추가','기능','수정','반영','적용','옵션','노출','활성화','생성','크론']],
];

/** 세부분류: 대분류 key => [[label,[키워드...]], ...] (같은 점수 방식, 미매칭은 '기타') */
$SUBS = [
    'acad' => [
        ['자동·회원 동기화', ['동기화','sync','자동동기화','회원동기화']],
        ['수강신청·수강생',  ['수강신청','수강생','수강']],
        ['학사반입·반영',    ['반입','반영','반출']],
        ['학적·학번',        ['학적','학번','교번','편람','재수강']],
    ],
    'security' => [
        ['취약점·점검', ['취약점','점검','진단']],
        ['해킹·악성코드',['해킹','악성코드','감염','침해','랜섬','피싱']],
        ['보안 설정',   ['보안','방화벽','백신','인증서']],
    ],
    'attend' => [
        ['출석부',        ['출석부']],
        ['출석 인정·처리',['인정','처리','정정','반영']],
        ['결석·지각',     ['결석','지각']],
        ['출결 오류',     ['오류','안되','안돼','누락','에러','문제']],
    ],
    'media' => [
        ['재생·스트리밍', ['재생','스트리밍','플레이어','끊김','버퍼']],
        ['업로드·인코딩', ['업로드','인코딩','등록','변환']],
        ['진도·시청',     ['진도','이어보기','시청','조회수']],
        ['자막·녹화',     ['자막','녹화']],
    ],
    'assess' => [
        ['과제·표절',  ['과제','카피킬러','표절']],
        ['시험·퀴즈',  ['시험','퀴즈','quiz','응시','문항']],
        ['성적·채점',  ['성적','채점','평가']],
        ['제출·설문',  ['제출','설문']],
    ],
    'course' => [
        ['강의계획서·운영계획서', ['강의계획서','운영계획서','계획서','수업운영','운영계획']],
        ['강좌관리·분류',        ['강좌관리','교과과정','교과목','교육과정','과정관리','분류','비정규','자율강좌','공개강좌']],
        ['개설·생성·기수',       ['개설','생성','만들','신규','기수']],
        ['복사·가져오기·이관',   ['복사','이동','이관','옮','가져오기','import','복원']],
        ['주차·학습활동·콘텐츠', ['주차','학습활동','활동','콘텐츠']],
        ['수료·이수증',          ['수료','이수','수료증','이수증']],
        ['화상강의·줌',          ['화상','줌','zoom','webex','웹엑스']],
        ['시간표',               ['시간표']],
        ['강좌정보·교수자',      ['강좌명','영문명','교수자','대표교수','썸네일']],
        ['대시보드·배너',        ['대시보드','배너','메인화면','나의강좌']],
        ['접근·조회·권한',       ['접근','조회','목록','권한','안보']],
    ],
    'auth' => [
        ['로그인·접속',   ['로그인','접속','로그아웃','connection']],
        ['권한',          ['권한']],
        ['계정·비밀번호', ['계정','비밀번호','패스워드']],
        ['회원가입',      ['회원가입','가입']],
        ['SSO·인증',      ['sso','인증']],
    ],
    'board' => [
        ['게시판·공지', ['게시판','공지']],
        ['알림·쪽지·채팅',['알림','쪽지','메시지','채팅']],
        ['메일·문자',   ['메일','문자','sms','발송']],
        ['댓글',        ['댓글']],
    ],
    'file' => [
        ['다운로드',        ['다운로드']],
        ['업로드·첨부',     ['업로드','첨부']],
        ['엑셀·CSV',        ['엑셀','excel','csv']],
        ['데이터·백업·이관',['데이터','백업','이관','메타데이터']],
    ],
    'ui' => [
        ['화면 깨짐·오류',  ['깨짐','오류','안보','에러']],
        ['이미지·아이콘',   ['이미지','아이콘']],
        ['레이아웃·정렬',   ['레이아웃','정렬','css','디자인']],
        ['버튼·메뉴·페이지',['버튼','메뉴','페이지']],
        ['팝업·링크',       ['팝업','링크']],
    ],
    'config' => [
        ['설정·옵션',   ['설정','옵션','크론']],
        ['추가·생성',   ['추가','생성','신규']],
        ['수정·변경',   ['수정','변경','반영','조정']],
        ['노출·활성화', ['노출','활성화','적용']],
    ],
];

/** rules=[[label,[kw..]]...] 중 제목(×3)+본문(×1) 가중 최고점 [label,score] 반환. 동점은 앞 순서 유지. */
function scorePick($rules, $t, $b) {
    $bestLabel = null; $bestScore = 0;
    foreach ($rules as $r) {
        $sc = 0;
        foreach ($r[1] as $kw) {
            if (mb_strpos($t, $kw, 0, 'UTF-8') !== false) $sc += W_TITLE;
            if (mb_strpos($b, $kw, 0, 'UTF-8') !== false) $sc += W_BODY;
        }
        if ($sc > $bestScore) { $bestScore = $sc; $bestLabel = $r[0]; }
    }
    return [$bestLabel, $bestScore];
}

try {
    $pdo = db();
    $scope = $_GET['scope'] ?? 'all';
    $conds = [];
    if ($scope === 'active')   $conds[] = "archived=0";
    if ($scope === 'archived') $conds[] = "archived=1";
    $params = [];
    $from = trim($_GET['from'] ?? '');
    $to   = trim($_GET['to'] ?? '');
    if ($from !== '' && ($ts = strtotime($from . ' 00:00:00')) !== false) { $conds[] = "created >= :from"; $params[':from'] = $ts; }
    if ($to   !== '' && ($ts = strtotime($to   . ' 23:59:59')) !== false) { $conds[] = "created <= :to";   $params[':to']   = $ts; }
    $where = $conds ? ("WHERE " . implode(" AND ", $conds)) : "";
    $stmt = $pdo->prepare("SELECT title, body FROM requests $where");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // 도메인 대분류(=config 제외)만 우선 경쟁. config/설정은 도메인 0점일 때만 쓰는 폴백.
    $domainRules = []; $configRule = null;
    foreach ($CATS as $c) {
        if ($c[0] === 'config') { $configRule = [$c[0], $c[2]]; continue; }
        $domainRules[] = [$c[0], $c[2]];
    }

    $agg = [];
    foreach ($CATS as $c) $agg[$c[0]] = ['key'=>$c[0], 'label'=>$c[1], 'count'=>0, 'subs'=>[]];
    $agg['etc'] = ['key'=>'etc', 'label'=>'기타·미분류', 'count'=>0, 'subs'=>[]];

    foreach ($rows as $r) {
        $title = (string)$r['title'];
        $t = mb_strtolower($title, 'UTF-8');
        $b = mb_strtolower(mb_substr((string)$r['body'], 0, BODY_LEN, 'UTF-8'), 'UTF-8');
        [$dl, $ds] = scorePick($domainRules, $t, $b);
        if ($ds > 0) {
            $mk = $dl;
        } else {
            [, $cs] = scorePick([$configRule], $t, $b);
            $mk = $cs > 0 ? 'config' : 'etc';
        }
        $agg[$mk]['count']++;
        [$sk,] = scorePick($SUBS[$mk] ?? [], $t, $b);
        if ($sk === null) $sk = '기타';
        if (!isset($agg[$mk]['subs'][$sk])) $agg[$mk]['subs'][$sk] = ['label'=>$sk, 'count'=>0, 'samples'=>[]];
        $agg[$mk]['subs'][$sk]['count']++;
        if (count($agg[$mk]['subs'][$sk]['samples']) < 6 && $title !== '')
            $agg[$mk]['subs'][$sk]['samples'][] = mb_substr($title, 0, 60, 'UTF-8');
    }

    $total = count($rows);
    $out = [];
    foreach ($agg as $c) {
        $c['pct'] = $total ? round($c['count'] * 100 / $total, 1) : 0;
        $subs = array_values($c['subs']);
        foreach ($subs as &$s) $s['pct'] = $c['count'] ? round($s['count'] * 100 / $c['count'], 1) : 0;
        unset($s);
        usort($subs, fn($a, $b) => $b['count'] <=> $a['count']);
        $c['subs'] = $subs;
        $out[] = $c;
    }
    usort($out, fn($a, $b) => $b['count'] <=> $a['count']);

    echo json_encode(['ok'=>true, 'scope'=>$scope, 'from'=>$from, 'to'=>$to, 'total'=>$total, 'categories'=>$out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

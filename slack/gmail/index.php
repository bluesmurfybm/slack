<?php
/**
 * Gmail 뷰어 (DB 캐시 방식).
 *  - 목록: gmail_mails 테이블에서 즉시 조회(빠름). IMAP 은 목록에 관여하지 않음.
 *  - 동기화: 페이지 로드 후 JS 가 gmail_sync.php 를 백그라운드 호출(초기 백필은 끝날 때까지 반복).
 *  - 본문: 첫 열람 때만 IMAP 에서 받아 DB 에 저장 → 이후 열람은 DB 에서 즉시.
 *  - 열람 시 Gmail 원본도 읽음(\Seen) 처리 + DB 반영.
 */
$__bwBase = '../';   // slack/ 하위 폴더 페이지 — require_login()/header.php 리다이렉트 경로 계산용
$__bwCurrent = 'slack';
require_once __DIR__ . '/../auth.php';
require_login();
session_release();
require_once __DIR__ . '/gmail_lib.php';

mb_internal_encoding('UTF-8');
gmail_ensure_table();
$pdo = db();

/** 선택 uid 들을 각 대화 전체 uid 로 확장 (목록에서 대표 uid 만 넘겨도 스레드 단위 처리) */
function expand_thread_uids($pdo, $acct, array $uids) {
    $uids = array_values(array_filter(array_map('intval', $uids)));
    if (!$uids) return [];
    $ph = implode(',', array_fill(0, count($uids), '?'));
    $q = $pdo->prepare("SELECT DISTINCT thrid FROM gmail_mails WHERE account = ? AND uid IN ($ph) AND thrid IS NOT NULL AND thrid <> 0");
    $q->execute(array_merge([$acct], $uids));
    $thrids = $q->fetchAll(PDO::FETCH_COLUMN);
    if ($thrids) {
        $ph2 = implode(',', array_fill(0, count($thrids), '?'));
        $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ? AND thrid IN ($ph2)");
        $q->execute(array_merge([$acct], $thrids));
        $uids = array_merge($uids, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
    }
    return array_values(array_unique($uids));
}

/** 대화의 보낸편지함 사본 — 내가 보낸/전달한 메일. 동기화는 받은편지함만 읽으므로 여기서 합쳐야
 *  Gmail 대화 화면과 같아진다. 목록은 gmail_sent(동기화가 채우는 보낸편지함 캐시)에서 바로 읽고,
 *  본문이 아직 없는 것만 Gmail 에서 받아 캐시한다 — 열 때마다 받으면 한 통에 수 초라 대화 하나에 30초가 넘었다.
 *  받은편지함에도 있는 사본(나에게 보낸 메일·나를 참조한 메일)은 Gmail 에서 같은 메일이라 같은 대화에서 시각(초)까지 같다
 *  → 그걸로 거른다(목록 건수 쿼리와 같은 기준).
 *  uid=0 으로 돌려준다 → 화면은 받은편지함 uid 로 하는 동작(첨부 링크·읽음·답장 대상)을 건너뛴다. */
function gmail_sent_in_thread($thrid, array $inboxMails) {
    if (!$thrid) return [];
    $pdo = db();
    $acct = gmail_owner();
    $q = $pdo->prepare("SELECT uid, udate, subject, sender, body, body_html, atts FROM gmail_sent
                        WHERE account = ? AND thrid = ? ORDER BY udate DESC LIMIT 50");   // 안전 상한(비정상적으로 긴 대화)
    $q->execute([$acct, $thrid]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $inboxAt = [];
    foreach ($inboxMails as $m) $inboxAt[(int)$m['udate']] = 1;
    $rows = array_values(array_filter($rows, fn($r) => !isset($inboxAt[(int)$r['udate']])));
    $need = array_filter($rows, fn($r) => $r['body_html'] === null);
    if ($need) {                                           // 아직 본문을 안 받은 것만 — 보낸편지함 1연결로
        $g = gmail_cfg();
        $mb = gmail_sent_folder();
        $im = $mb !== '' ? @imap_open($g['host'] . $mb, $g['user'], str_replace(' ', '', (string)$g['pass']), OP_READONLY, 1) : false;
        if ($im) {
            try {
                foreach ($rows as &$r) if ($r['body_html'] === null) $r = array_merge($r, gmail_sent_fill($im, $pdo, $acct, $r['uid']));
                unset($r);
            } finally {
                imap_close($im);
            }
        }
    }
    $myName = gmail_display_name();
    $out = [];
    foreach ($rows as $r) {
        if ($r['body_html'] === null) continue;            // 이번엔 못 받았다(접속 실패) — 다음에 다시
        $disp = (string)$r['sender'];
        // 이름 없이 주소만 찍힌 예전 발송분(이 화면에서 보낸 답장 등)은 내 이름을 붙여 Gmail 처럼 표시
        if ($disp !== '' && strpos($disp, '<') === false && $myName !== '') $disp = $myName . ' <' . $disp . '>';
        $out[] = ['uid' => 0, 'subject' => $r['subject'], 'sender' => $disp, 'udate' => (int)$r['udate'], 'seen' => 1,
                  'body' => (string)$r['body'], 'body_html' => (string)$r['body_html'], 'atts' => $r['atts']];
    }
    return $out;
}

/* ---------- 첨부파일 스트리밍: ?att=<uid>&i=<idx>&name=&dl=1 ----------
   첨부는 이름만 DB 에 있으므로 바이트는 IMAP 파트에서 즉시 받아 전달. */
if (isset($_GET['att'])) {
    $uid = (int)$_GET['att'];
    $idx = (int)($_GET['i'] ?? 0);
    $name = (string)($_GET['name'] ?? '');
    [$im, $err] = gmail_open();
    if (!$im) { http_response_code(502); exit('imap fail'); }
    $msgno = imap_msgno($im, $uid);
    if ($msgno < 1) { imap_close($im); http_response_code(404); exit('mail not found'); }
    $parts = gmail_att_parts(imap_fetchstructure($im, $msgno));
    $p = $parts[$idx] ?? null;
    if (!$p || ($name !== '' && $p['name'] !== $name)) {   // 인덱스 불일치 시 이름으로 재탐색
        $p = null;
        foreach ($parts as $pp) if ($pp['name'] === $name) { $p = $pp; break; }
    }
    if (!$p) { imap_close($im); http_response_code(404); exit('attachment not found'); }
    $raw = gmail_dec_body(imap_fetchbody($im, $msgno, $p['no'], FT_PEEK), $p['enc']);
    imap_close($im);
    header('Content-Type: ' . $p['mime']);
    header('Content-Length: ' . strlen($raw));
    header('Cache-Control: private, max-age=604800');      // 메일 첨부는 불변 → 브라우저 캐시
    header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline')
         . "; filename*=UTF-8''" . rawurlencode($p['name']));
    echo $raw;
    exit;
}

/** 답장 받는사람/참조 — Gmail 과 같은 규칙. $h = imap_headerinfo() 결과, $me = 내 Gmail 주소(소문자).
 *  - 남이 보낸 메일: 답장 = 보낸사람(Reply-To 우선) / 전체답장 = + 원본 받는사람, 원본 참조는 참조로
 *  - 내가 보낸 메일(보낸편지함): 답장·전체답장 모두 원본 받는사람에게 — 보낸사람(나)을 빼고 나면
 *    받는사람이 비어 버려 작성창이 '불러오는 중…' 빈칸으로 남던 문제
 *  - 그래도 아무도 안 남으면(나에게만 보낸 메일 등) 보낸사람에게 */
function reply_recipients($h, $me, $mode) {
    $collect = function ($arr) {                           // 주소 객체 → 소문자 이메일
        $out = [];
        foreach ((array)$arr as $a) {
            if (empty($a->mailbox) || empty($a->host)) continue;
            $out[] = strtolower($a->mailbox . '@' . $a->host);
        }
        return array_values(array_unique($out));
    };
    $notMe = fn($list) => array_values(array_filter($list, fn($e) => $e !== $me));
    $from   = $collect($h->from ?? []);
    $fromMe = in_array($me, $from, true);
    $origTo = $notMe($collect($h->to ?? []));
    $origCc = $notMe($collect($h->cc ?? []));
    $to = $fromMe ? $origTo : $notMe($collect(!empty($h->reply_to) ? $h->reply_to : ($h->from ?? [])));
    $cc = [];
    if ($mode === 'all') {
        if (!$fromMe) $to = array_values(array_unique(array_merge($to, $origTo)));
        $cc = array_values(array_diff($origCc, $to));
    }
    if (!$to && $cc) { $to = $cc; $cc = []; }
    if (!$to) $to = $from ?: ($me !== '' ? [$me] : []);
    return [$to, $cc];
}

/** 답장 대상 계산: 원본 헤더에서 to/cc/제목/스레드 연결 헤더 도출 */
function reply_targets($uid, $mode) {
    [$im, $err] = gmail_open();
    if (!$im) throw new RuntimeException($err);
    $msgno = imap_msgno($im, $uid);
    if ($msgno < 1) { imap_close($im); throw new RuntimeException('원본 메일을 찾을 수 없습니다.'); }
    $h = imap_headerinfo($im, $msgno);
    $rawH = imap_fetchheader($im, $msgno);
    imap_close($im);
    [$to, $cc] = reply_recipients($h, gmail_owner(), $mode);
    $subjO = gmail_dec_header($h->subject ?? '');
    $subject = preg_match('/^\s*re\s*:/i', $subjO) ? $subjO : 'Re: ' . $subjO;
    // Gmail 스레드 연결 헤더 (In-Reply-To / References)
    $mid = trim((string)($h->message_id ?? ''));
    $refs = '';
    if (preg_match('/^References:[ \t]*(.+(?:\r?\n[ \t].+)*)/mi', $rawH, $m)) {
        $refs = trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]));
    }
    $extra = [];
    if ($mid !== '') { $extra['In-Reply-To'] = $mid; $extra['References'] = trim($refs . ' ' . $mid); }
    return [$to, $cc, $subject, $extra];
}

/* ---------- 답장 수신자 미리 계산: ?replyinfo=<uid>&mode= (작성창 프리필용) ---------- */
if (isset($_GET['replyinfo'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        [$to, $cc, $subject] = reply_targets((int)$_GET['replyinfo'], ($_GET['mode'] ?? 'reply') === 'all' ? 'all' : 'reply');
        echo json_encode(['ok' => true, 'to' => $to, 'cc' => $cc, 'subject' => $subject], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- 답장/전체답장 발송: POST ?reply=1 {uid, mode, text, to[], cc[]} ---------- */
if (isset($_GET['reply']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $uid = (int)($in['uid'] ?? 0);
    $mode = (($in['mode'] ?? 'reply') === 'all') ? 'all' : 'reply';
    $text = trim((string)($in['text'] ?? ''));
    if ($uid < 1 || $text === '') { echo json_encode(['ok' => false, 'error' => '내용을 입력하세요.']); exit; }
    try {
        [$to, $cc, $subject, $extra] = reply_targets($uid, $mode);
        // 작성창에서 수정한 수신자가 오면 그것을 사용 (형식 검증)
        $clean = function ($list) {
            $out = [];
            foreach ((array)$list as $e) {
                $e = strtolower(trim((string)$e));
                if ($e === '') continue;
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("잘못된 이메일 형식: $e");
                $out[] = $e;
            }
            return array_values(array_unique($out));
        };
        if (isset($in['to'])) { $to = $clean($in['to']); $cc = $clean($in['cc'] ?? []); }
        if (!$to) throw new RuntimeException('받는사람이 없습니다.');
        // 원문 인용 (Gmail 형식)
        $me = gmail_owner();
        $st = $pdo->prepare("SELECT sender, udate, body FROM gmail_mails WHERE account = ? AND uid = ?");
        $st->execute([$me, $uid]);
        $orig = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $quote = '';
        if (!empty($orig['body'])) {
            $quote = "\n\n" . date('Y년 n월 j일 H:i', (int)$orig['udate']) . ', ' . $orig['sender'] . " 님이 작성:\n"
                   . '> ' . str_replace("\n", "\n> ", trim($orig['body']));
        }
        gmail_smtp_send($to, $cc, $subject, $text . $quote, $extra);
        echo json_encode(['ok' => true, 'to' => $to, 'cc' => $cc], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- 실시간 ping: ?ping=1 — DB 조회만(밀리초). 감시 데몬이 갱신한 변경 마커 확인 ---------- */
if (isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    $res = [
        'ok' => true,
        'changed' => (int)gmeta_get('gmail_changed_at', 0),
        'sync_age' => time() - (int)strtotime((string)gmeta_get('gmail_last_sync', '2000-01-01')),
    ];
    // &unread=1: 안 읽은 대화 수 — 업무 현황판의 Gmail 탭 배지(Gmail 받은편지함 숫자와 같은 기준: 대화 단위)
    if (isset($_GET['unread'])) {
        $res['configured'] = gmail_configured();
        $q = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(thrid,0), uid)) FROM gmail_mails WHERE account = ? AND seen = 0");
        $q->execute([gmail_owner()]);
        $res['unread'] = (int)$q->fetchColumn();
    }
    echo json_encode($res);
    exit;
}

/* ---------- 보낸 사람 사진: POST ?avatars=1 {people:[{email,name}]} → {map:{email:url|''}} ----------
   Gmail 사진은 Google 계정 프로필이라 IMAP(앱 비밀번호)으로는 못 받는다 → Slack 프로필 사진으로 대신한다.
   찾는 순서: ① 이메일(users.lookupByEmail — Slack 앱에 users:read.email 권한이 있을 때)
              ② 이름: 워크스페이스 사용자 목록 → 업무 리스트 요청자·담당자(Slack Connect 외부 사용자)
   동명이인이거나 사진을 안 올린 사람은 '' → 화면은 이니셜 그대로. 결과는 서버(전원 공용)에 캐시. */
function avatar_img($user) {                               // 직접 올린 사진만 (기본 그림은 이니셜이 낫다)
    $p = $user['profile'] ?? [];
    $img = (string)($p['image_72'] ?? '');
    $custom = isset($p['is_custom_image']) ? (bool)$p['is_custom_image'] : (strpos($img, 'gravatar.com') === false);
    return ($img !== '' && $custom) ? $img : '';
}
function avatar_norm($s) { return preg_replace('/\s+/u', '', mb_strtolower(trim((string)$s))); }
/** 이름 → 사진. 사용자 목록 색인은 12시간마다 새로, 목록에 없는 이름은 업무 리스트의 Slack ID 로 한 명씩 */
function avatar_by_name($name, $tok, array &$cache, $pdo) {
    $n = avatar_norm($name);
    if ($n === '') return '';
    if (empty($cache['names']['t']) || time() - $cache['names']['t'] > 12 * 3600) {
        $map = []; $cursor = null; $pages = 0;
        do {
            $p = ['limit' => 200]; if ($cursor) $p['cursor'] = $cursor;
            $r = slackGet('users.list', $tok, $p);
            if (empty($r['ok'])) break;
            foreach ($r['members'] ?? [] as $m) {
                if (!empty($m['deleted']) || !empty($m['is_bot'])) continue;
                $img = avatar_img($m);
                foreach (array_unique(array_filter([$m['real_name'] ?? '', $m['profile']['real_name'] ?? '', $m['profile']['display_name'] ?? ''])) as $nm) {
                    $k = avatar_norm($nm);
                    $map[$k] = (isset($map[$k]) && $map[$k] !== $img) ? '*' : $img;   // '*' = 동명이인
                }
            }
            $cursor = $r['response_metadata']['next_cursor'] ?? null;
        } while ($cursor && ++$pages < 20);
        $cache['names'] = ['t' => time(), 'map' => $map];
    }
    $map = &$cache['names']['map'];
    if (!array_key_exists($n, $map)) {                     // 외부(Slack Connect) 사용자는 사용자 목록에 안 나온다
        $ids = [];
        foreach ($pdo->query("SELECT req_id, req, asg_id, asg FROM requests") as $r) {
            if ($r['req_id'] && avatar_norm($r['req']) === $n) $ids[$r['req_id']] = 1;
            $ai = $r['asg_id'] ? explode(',', $r['asg_id']) : [];
            $an = explode(',', (string)$r['asg']);
            foreach ($ai as $k => $id) if (isset($an[$k]) && avatar_norm($an[$k]) === $n) $ids[$id] = 1;
        }
        $map[$n] = '';
        if (count($ids) === 1) {
            $u = slackGet('users.info', $tok, ['user' => array_key_first($ids)]);
            if (!empty($u['ok'])) $map[$n] = avatar_img($u['user']);
        } elseif (count($ids) > 1) {
            $map[$n] = '*';
        }
    }
    return $map[$n] === '*' ? '' : $map[$n];
}
if (isset($_GET['avatars']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $tok = (string)current_token();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $people = array_slice((array)($in['people'] ?? []), 0, 30);
    $file = slack_cache_path('gmail_avatars.json');
    $cache = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $out = []; $dirty = false;
    $until = microtime(true) + 6;                          // 새로 찾는 데 쓰는 시간 상한 — 못 한 건 다음에 열 때
    foreach ($people as $p) {
        $e = strtolower(trim((string)($p['email'] ?? '')));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
        $c = $cache['e'][$e] ?? null;
        if ($c && time() - (int)$c['t'] < ($c['u'] !== '' ? 86400 : 6 * 3600)) { $out[$e] = $c['u']; continue; }
        if ($tok === '' || microtime(true) > $until) continue;
        $url = null;                                       // null = 이메일로는 못 찾음 → 이름으로
        if (time() - (int)($cache['noscope'] ?? 0) > 3600) {
            $r = slackGet('users.lookupByEmail', $tok, ['email' => $e]);
            if (!empty($r['ok'])) $url = avatar_img($r['user']);
            elseif (($r['error'] ?? '') === 'missing_scope') $cache['noscope'] = time();   // 권한 없음 — 1시간 뒤 다시
        }
        if ($url === null) $url = avatar_by_name((string)($p['name'] ?? ''), $tok, $cache, $pdo);
        $cache['e'][$e] = ['u' => $url, 't' => time()];
        $out[$e] = $url;
        $dirty = true;
    }
    if ($dirty) {
        $tmp = $file . '.' . getmypid();
        if (@file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $file);
    }
    echo json_encode(['ok' => true, 'map' => (object)$out], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 읽음/안읽음/별표 일괄 처리: POST ?flag=seen|unseen|star|unstar {uids:[...]}
     대표 uid 만 와도 대화 전체로 확장, Gmail 원본 플래그도 함께 변경 ---------- */
if (isset($_GET['flag']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $act = (string)$_GET['flag'];
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $uids = expand_thread_uids($pdo, gmail_owner(), (array)($in['uids'] ?? []));
    if (!$uids || !in_array($act, ['seen', 'unseen', 'star', 'unstar'], true)) {
        echo json_encode(['ok' => false, 'error' => '잘못된 요청']); exit;
    }
    try {
        [$im, $err] = gmail_open();
        if (!$im) throw new RuntimeException($err);
        $set = implode(',', $uids);
        $map = ['seen'   => ['\\Seen', true,  'seen', 1],
                'unseen' => ['\\Seen', false, 'seen', 0],
                'star'   => ['\\Flagged', true,  'flagged', 1],
                'unstar' => ['\\Flagged', false, 'flagged', 0]];
        [$flag, $on, $col, $val] = $map[$act];
        $on ? imap_setflag_full($im, $set, $flag, ST_UID) : imap_clearflag_full($im, $set, $flag, ST_UID);
        imap_close($im);
        $ph = implode(',', array_fill(0, count($uids), '?'));
        $pdo->prepare("UPDATE gmail_mails SET $col = $val WHERE account = ? AND uid IN ($ph)")
            ->execute(array_merge([gmail_owner()], $uids));
        echo json_encode(['ok' => true, 'count' => count($uids)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- (호환) 안읽음 표시: POST ?unseen=1 {uids} — 대화 상세의 안읽음 버튼용 ---------- */
if (isset($_GET['unseen']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $uids = expand_thread_uids($pdo, gmail_owner(), (array)($in['uids'] ?? []));
    if (!$uids) { echo json_encode(['ok' => false, 'error' => '대상 없음']); exit; }
    try {
        [$im, $err] = gmail_open();
        if (!$im) throw new RuntimeException($err);
        imap_clearflag_full($im, implode(',', $uids), '\\Seen', ST_UID);   // Gmail 원본 안읽음으로
        imap_close($im);
        $ph = implode(',', array_fill(0, count($uids), '?'));
        $pdo->prepare("UPDATE gmail_mails SET seen = 0 WHERE account = ? AND uid IN ($ph)")
            ->execute(array_merge([gmail_owner()], $uids));
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- 라벨 조회: ?labels=<uid> ---------- */
if (isset($_GET['labels'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = new GmailRaw();
        $out = ['ok' => true, 'labels' => $raw->labelsOf((int)$_GET['labels']), 'all' => $raw->allLabels()];
        $raw->close();
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- 라벨 변경: POST {uid, label, add} → Gmail 원본 반영 ---------- */
if (isset($_GET['label_set']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $uid = (int)($in['uid'] ?? 0);
    $label = trim((string)($in['label'] ?? ''));
    $add = !empty($in['add']);
    if ($uid < 1 || $label === '' || $label[0] === '\\') {           // 시스템 라벨(\Inbox 등)은 변경 불가
        echo json_encode(['ok' => false, 'error' => '잘못된 요청']); exit;
    }
    try {
        $raw = new GmailRaw();
        $ok = $raw->storeLabel($uid, $label, $add);
        $labels = $ok ? $raw->labelsOf($uid) : [];
        $raw->close();
        if ($ok) {                                         // 필터용 DB 라벨도 동기 반영
            $names = [];
            foreach ($labels as $l) if (empty($l['system'])) $names[] = $l['name'];
            $pdo->prepare("UPDATE gmail_mails SET labels = ? WHERE account = ? AND uid = ?")
                ->execute([gmail_labels_pack($names), gmail_owner(), $uid]);
        }
        echo json_encode(['ok' => $ok, 'labels' => $labels], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- 대화(스레드) 조회: ?thread=<uid> — Gmail 처럼 같은 대화를 시간순으로 ---------- */
if (isset($_GET['thread'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)$_GET['thread'];
    $acct = gmail_owner();
    $st = $pdo->prepare("SELECT uid, thrid FROM gmail_mails WHERE account = ? AND uid = ?");
    $st->execute([$acct, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => false]); exit; }
    try {
        $thrid = $row['thrid'];
        if ($thrid === null) {                             // 예열 전 첫 열람: Gmail 대화 ID 조회 후 DB 에 캐시
            $raw = new GmailRaw();
            $thrid = $raw->thridOf($uid);
            $uids = $thrid ? $raw->searchThread($thrid) : [];
            $raw->close();
            if ($thrid && $uids) {
                $ph = implode(',', array_fill(0, count($uids), '?'));
                $pdo->prepare("UPDATE gmail_mails SET thrid = ? WHERE account = ? AND uid IN ($ph)")
                    ->execute(array_merge([$thrid, $acct], $uids));
            } else {                                       // 조회 불가 → 0 마킹(매번 재조회 방지)
                $pdo->prepare("UPDATE gmail_mails SET thrid = 0 WHERE account = ? AND uid = ?")->execute([$acct, $uid]);
            }
        }
        if ($thrid) {
            $q = $pdo->prepare("SELECT uid, subject, sender, udate, seen, body, body_html, atts
                                FROM gmail_mails WHERE account = ? AND thrid = ? ORDER BY udate ASC");
            $q->execute([$acct, $thrid]);
        } else {                                           // 대화 ID 를 못 얻으면 단건 표시
            $q = $pdo->prepare("SELECT uid, subject, sender, udate, seen, body, body_html, atts
                                FROM gmail_mails WHERE account = ? AND uid = ?");
            $q->execute([$acct, $uid]);
        }
        $mails = $q->fetchAll(PDO::FETCH_ASSOC);

        // 본문 미수집분(+cid 이미지가 깨진 구버전 캐시) 채우기 + 안읽음 → 읽음 (IMAP 1연결로 일괄)
        $stale = fn($m) => $m['body_html'] === null || strpos((string)$m['body_html'], 'cid:') !== false;
        foreach ($mails as &$m) if ($stale($m)) $m['body_html'] = null;   // 재수집 대상 표시
        unset($m);
        $needB = array_filter($mails, fn($m) => $m['body_html'] === null);
        $needS = array_filter($mails, fn($m) => !(int)$m['seen']);
        if ($needB || $needS) {
            [$im, $err] = gmail_open();
            if ($im) {
                foreach ($mails as &$m) {
                    if ($m['body_html'] !== null) continue;
                    $msgno = imap_msgno($im, (int)$m['uid']);
                    if ($msgno >= 1) {
                        [$plain, $html, $atts] = gmail_extract($im, $msgno);
                        $m['body'] = gmail_body_text($plain, $html);
                        $m['body_html'] = ($html !== '') ? gmail_sanitize_html($html) : '';
                        $m['atts'] = json_encode($atts, JSON_UNESCAPED_UNICODE);
                        $pdo->prepare("UPDATE gmail_mails SET body = ?, body_html = ?, atts = ? WHERE account = ? AND uid = ?")
                            ->execute([$m['body'], $m['body_html'], $m['atts'], $acct, $m['uid']]);
                    } else { $m['body'] = '(메일함에서 찾을 수 없습니다 — 삭제/이동됨)'; $m['body_html'] = ''; }
                }
                unset($m);
                if ($needS) {                              // 대화 열람 = 대화 전체 읽음 (Gmail 과 동일)
                    $set = implode(',', array_map(fn($m) => (int)$m['uid'], $needS));
                    imap_setflag_full($im, $set, '\\Seen', ST_UID);
                    $pdo->exec("UPDATE gmail_mails SET seen = 1 WHERE account = " . $pdo->quote($acct) . " AND uid IN ($set)");
                }
                imap_close($im);
            } elseif ($needB) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
        }
        // 내가 보낸/전달한 메일(보낸편지함 사본)도 대화에 넣어 시간순으로 — Gmail 대화 화면과 같게
        if ($thrid) {
            try { $mails = array_merge($mails, gmail_sent_in_thread($thrid, $mails)); }
            catch (Throwable $e) { /* 실패해도 받은 메일은 그대로 보여 준다 */ }
            usort($mails, fn($a, $b) => (int)$a['udate'] <=> (int)$b['udate']);
        }
        $out = ['ok' => true, 'subject' => $mails ? $mails[0]['subject'] : '', 'mails' => []];
        foreach ($mails as $m) {
            $out['mails'][] = ['uid' => (int)$m['uid'], 'sender' => $m['sender'],
                'datef' => $m['udate'] ? date('Y-m-d H:i', $m['udate']) : '',
                'body' => (string)$m['body'], 'html' => (string)$m['body_html'],
                'atts' => $m['atts'] ? (json_decode($m['atts'], true) ?: []) : []];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- 본문 lazy 로드: ?body=<uid> ---------- */
if (isset($_GET['body'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)$_GET['body'];
    $st = $pdo->prepare("SELECT body, body_html, atts, seen FROM gmail_mails WHERE account = ? AND uid = ?");
    $st->execute([gmail_owner(), $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => false]); exit; }

    if (strpos((string)$row['body_html'], 'cid:') !== false) $row['body_html'] = null;   // cid 이미지 깨진 구캐시 재수집
    $needBody = ($row['body_html'] === null);              // html 미수집(=구버전 캐시 포함)이면 다시 수집
    $needSeen = ((int)$row['seen'] === 0);
    if ($needBody || $needSeen) {                          // 필요할 때만 IMAP 접속
        [$im, $err] = gmail_open();
        if (!$im && $needBody) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
        if ($im) {
            if ($needBody) {
                $msgno = imap_msgno($im, $uid);
                if ($msgno >= 1) {
                    [$plain, $html, $atts] = gmail_extract($im, $msgno);
                    $row['body'] = gmail_body_text($plain, $html);
                    $row['body_html'] = ($html !== '') ? gmail_sanitize_html($html) : '';   // ''=plain 전용 표시
                    $row['atts'] = json_encode($atts, JSON_UNESCAPED_UNICODE);
                    $pdo->prepare("UPDATE gmail_mails SET body = ?, body_html = ?, atts = ? WHERE account = ? AND uid = ?")
                        ->execute([$row['body'], $row['body_html'], $row['atts'], gmail_owner(), $uid]);
                } else { $row['body'] = '(메일함에서 찾을 수 없습니다 — 삭제/이동됨)'; }
            }
            if ($needSeen) {                               // Gmail 원본도 읽음 처리
                imap_setflag_full($im, (string)$uid, '\\Seen', ST_UID);
                $pdo->prepare("UPDATE gmail_mails SET seen = 1 WHERE account = ? AND uid = ?")->execute([gmail_owner(), $uid]);
            }
            imap_close($im);
        }
    }
    echo json_encode(['ok' => true, 'body' => (string)$row['body'], 'html' => (string)$row['body_html'],
                      'atts' => $row['atts'] ? (json_decode($row['atts'], true) ?: []) : []], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 목록 (DB 조회) ---------- */
$g = gmail_cfg();
/* 페이지당 표시 개수: ?n=50|100|…|1000|all(모두).
   미지정 시 쿠키(마지막 선택값) → config 기본. 'def'=기본으로 리셋 */
$nRaw = (string)($_GET['n'] ?? '');
if ($nRaw === '' && isset($_COOKIE['gm_n'])) $nRaw = (string)$_COOKIE['gm_n'];
if ($nRaw === 'def') { $nRaw = ''; setcookie('gm_n', '', time() - 3600, '/'); }
elseif ($nRaw !== '') setcookie('gm_n', $nRaw, time() + 86400 * 365, '/');
$showAll = ($nRaw === 'all');
$perPage = $showAll ? PHP_INT_MAX
         : ((int)$nRaw > 0 ? min(2000, (int)$nRaw) : max(1, (int)($g['limit'] ?: 50)));
$page        = max(0, (int)($_GET['p'] ?? 1) - 1);         // URL 은 1기준, 내부는 0기준
/* 안읽음 우선: ?unread=1|0. 미지정 시 쿠키(마지막 선택값) 기억 */
$unreadParam = $_GET['unread'] ?? null;
$unreadFirst = $unreadParam !== null ? ($unreadParam == '1') : (($_COOKIE['gm_unread'] ?? '') === '1');
if ($unreadParam !== null) setcookie('gm_unread', $unreadFirst ? '1' : '0', time() + 86400 * 365, '/');

$acct = gmail_owner();                                     // 사용자(=Gmail 계정)별 데이터 구분
/* 필터: 라벨(정확 매칭) / 검색어(제목·보낸사람·본문) / 별표 */
$labelF = trim((string)($_GET['label'] ?? ''));
$qF     = trim((string)($_GET['q'] ?? ''));
$starF  = !empty($_GET['star']);
$flt = ''; $fp = [];
if ($labelF !== '') {
    $flt .= " AND labels LIKE ? ESCAPE '\\\\'";
    $fp[] = '%|' . addcslashes($labelF, '\\%_') . '|%';
}
if ($qF !== '') {
    $like = '%' . addcslashes($qF, '\\%_') . '%';
    $flt .= " AND (subject LIKE ? ESCAPE '\\\\' OR sender LIKE ? ESCAPE '\\\\' OR COALESCE(body,'') LIKE ? ESCAPE '\\\\')";
    array_push($fp, $like, $like, $like);
}
if ($starF) $flt .= " AND flagged = 1";
/* 목록은 대화(thrid) 단위 1행 — 대표는 대화의 최신 메일 (Gmail 과 동일).
   thrid 미예열(NULL)/조회불가(0) 메일은 자기 자신이 한 대화. */
$G = "COALESCE(NULLIF(thrid,0), uid)";                     // 대화 그룹 키
$q = $pdo->prepare("SELECT COUNT(DISTINCT $G) FROM gmail_mails WHERE account = ?$flt");
$q->execute(array_merge([$acct], $fp));
$count = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM gmail_mails WHERE account = ?$flt
                    GROUP BY $G HAVING SUM(seen = 0) > 0) t");
$q->execute(array_merge([$acct], $fp));
$unreadCnt = (int)$q->fetchColumn();
$pages     = max(1, (int)ceil($count / $perPage));
if ($page > $pages - 1) $page = $pages - 1;
// 최신 활동 = 받은 메일과 내가 보낸 답장 중 가장 늦은 것 (Gmail 은 내 답장도 대화 날짜·순서에 반영)
$lastAct = "GREATEST(x.last_udate, COALESCE(sc.slast, 0))";
$order = $unreadFirst ? "(x.unread > 0) DESC, $lastAct DESC" : "$lastAct DESC";
$pdo->exec("SET SESSION group_concat_max_len = 8192");     // 참여자 목록 잘림 방지
// 대표 = 처음 보낸 원본 메일(제목·본문), 날짜/정렬 = 대화의 최신 활동 시각 (Gmail 동일)
// 대화 수 = 받은 메일 + 내가 보낸 메일(gmail_sent) — Gmail 대화 화면의 건수와 같게
$st = $pdo->prepare("
    SELECT m.uid, m.subject, m.sender, m.body, m.labels, x.cnt + COALESCE(sc.scnt, 0) AS cnt, COALESCE(sc.scnt, 0) AS scnt,
           x.unread, x.senders, x.has_att, x.starred,
           $lastAct AS udate,
           CASE WHEN x.unread > 0 THEN 0 ELSE 1 END AS seen
    FROM (
        SELECT $G AS g, COUNT(*) AS cnt, SUM(seen = 0) AS unread, MAX(udate) AS last_udate,
               MAX(CASE WHEN atts IS NOT NULL AND atts <> '[]' THEN 1 ELSE 0 END) AS has_att,
               MAX(flagged) AS starred,
               GROUP_CONCAT(DISTINCT sender ORDER BY udate SEPARATOR '||') AS senders,
               MIN(CONCAT(LPAD(udate, 10, '0'), LPAD(uid, 10, '0'))) AS mk   -- 대화의 첫 메일
        FROM gmail_mails WHERE account = ?$flt GROUP BY g
    ) x
    JOIN gmail_mails m
      ON m.account = ? AND CONCAT(LPAD(m.udate, 10, '0'), LPAD(m.uid, 10, '0')) = x.mk
    LEFT JOIN (SELECT s.thrid, COUNT(*) AS scnt, MAX(s.udate) AS slast
               FROM gmail_sent s
               WHERE s.account = ? AND s.thrid IS NOT NULL
                 -- 나에게 보낸/나를 참조한 메일은 받은편지함에도 같은 메일(같은 대화·같은 시각)이 있다 → 두 번 세지 않게
                 AND NOT EXISTS (SELECT 1 FROM gmail_mails i WHERE i.account = s.account AND i.thrid = s.thrid AND i.udate = s.udate)
               GROUP BY s.thrid) sc
      ON sc.thrid = x.g
    ORDER BY $order
    LIMIT " . (int)$perPage . " OFFSET " . (int)($page * $perPage));
$st->execute(array_merge([$acct], $fp, [$acct, $acct]));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {                                   // 내가 답장한 대화는 참여자에 '나' (Gmail: "신중혁, 나")
    if ((int)$r['scnt'] > 0) $r['senders'] .= '||나';
}
unset($r);

$lastSync = gmeta_get('gmail_last_sync', '없음');
$backfillLeft = (int)gmeta_get('gmail_backfill_next', '-1');   // -1=아직 시작 안 함, 0=완료
$allLabels = json_decode((string)gmeta_get('gmail_labels', '[]'), true) ?: [];
// 페이지 이동/토글 시 유지할 필터 상태 (p 제외)
$keepArr = array_filter(['unread' => $unreadFirst ? 1 : null, 'label' => $labelF !== '' ? $labelF : null,
                         'q' => $qF !== '' ? $qF : null, 'star' => $starF ? 1 : null,
                         'n' => $showAll ? 'all' : ((int)$nRaw > 0 ? (int)$nRaw : null)]);
$keep = $keepArr ? '&' . http_build_query($keepArr) : '';
$pg = ['page' => $page, 'pages' => $pages, 'count' => $count, 'per' => $perPage,
       'unread' => $unreadFirst, 'unreadCnt' => $unreadCnt, 'keep' => $keep, 'keepArr' => $keepArr];
$from = $count ? $page * $perPage + 1 : 0;
$to   = min($count, $from + count($rows) - 1);

/* ---------- 부분 갱신: ?partial=1 — 실시간 푸시 시 목록 영역만 교체(전체 새로고침 없음) ---------- */
if (isset($_GET['partial'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => true,
        'empty' => !$rows,
        'list'  => rows_html($rows, $unreadFirst, $unreadCnt),
        'pager' => pager_html($pg),
        'meta'  => number_format($count) . '개 중 ' . number_format($from) . '–' . number_format($to)
                 . ' · 동기화 ' . (substr((string)$lastSync, 11, 5) ?: $lastSync),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** "이름 <메일주소>" → 이름만 (목록 표시용) */
function sender_name($s) {
    $s = trim((string)$s);
    if (preg_match('/^\s*"?([^"<]*)"?\s*<[^>]+>\s*$/', $s, $m)) { $n = trim($m[1]); if ($n !== '') return $n; }
    return $s;
}
/** Gmail 식 날짜: 오늘=시:분, 올해=n월 j일, 이전=Y. n. j. */
function fmt_date($ts) {
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if (date('Y', $ts) === date('Y')) return date('n월 j일', $ts);
    return date('Y. n. j.', $ts);
}
/** 제목 앞 Re:/Fwd: 류 접두 제거 */
function subject_base($s) {
    $b = trim(preg_replace('/^\s*((re|fw|fwd|답장|전달)\s*:\s*)+/iu', '', (string)$s));
    return $b !== '' ? $b : (string)$s;
}
/** 라벨 색: config.local.php 의 label_colors 매핑(=Gmail 설정과 동일하게 지정) 우선,
 *  미지정 라벨은 이름 해시 기반 자동 색. [bg, fg] 반환 (fg 는 배경 밝기에 따라 자동) */
function label_color($name) {
    $map = gmail_label_colors();                           // 사용자별 설정 우선, 공용 config 폴백
    if (isset($map[$name]) && preg_match('/^#[0-9a-f]{6}$/i', $map[$name])) {
        $bg = $map[$name];
        [$r, $g, $b] = sscanf($bg, '#%02x%02x%02x');
        $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;       // 체감 밝기
        return [$bg, $lum > 160 ? '#202124' : '#ffffff'];
    }
    $h = crc32($name) % 360;                               // 미지정: 자동 파스텔
    return ["hsl($h 60% 92%)", "hsl($h 45% 32%)"];
}
/** 대화 참여자 표시: 시간순 고유 이름, 3명 초과면 처음 2명 + … + 마지막 */
function participants($senders) {
    $me = gmail_owner();
    $names = [];
    foreach (explode('||', (string)$senders) as $s) {
        // 내 주소는 Gmail 처럼 '나' 로 (받은편지함의 '나에게 보낸 메일' + 보낸편지함 몫이 '유병문, 나' 로 겹치지 않게)
        $n = (preg_match('/<\s*([^>]+?)\s*>/', $s, $em) ? strcasecmp($em[1], $me) === 0 : strcasecmp(trim($s), $me) === 0)
            ? '나' : sender_name($s);
        if ($n !== '' && !in_array($n, $names)) $names[] = $n;
    }
    if (count($names) > 3) return implode(', ', array_slice($names, 0, 2)) . ' … ' . end($names);
    return implode(', ', $names);
}
function pager_html($pg) {
    if (!$pg || $pg['pages'] <= 1) return '';
    $p = $pg['page']; $last = $pg['pages'] - 1;
    $uq = $pg['keep'];                                     // 필터 상태(안읽음/라벨/검색/별표) 유지
    $u = function ($n) use ($uq) { return '?p=' . ($n + 1) . $uq; };
    $h  = '<div class="pager">';
    $h .= $p > 0 ? '<a href="' . $u(0) . '">« 처음</a><a href="' . $u($p - 1) . '">‹ 이전</a>'
                 : '<span class="disabled">« 처음</span><span class="disabled">‹ 이전</span>';
    $h .= '<span class="cur">' . ($p + 1) . ' / ' . $pg['pages'] . '</span>';
    $h .= $p < $last ? '<a href="' . $u($p + 1) . '">다음 ›</a><a href="' . $u($last) . '">끝 »</a>'
                     : '<span class="disabled">다음 ›</span><span class="disabled">끝 »</span>';
    $hidden = '';
    foreach ($pg['keepArr'] as $k => $v) $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $h .= '<form method="get">' . $hidden
        . '<input type="number" name="p" min="1" max="' . $pg['pages'] . '" placeholder="' . ($p + 1) . '"><button>이동</button></form>';
    return $h . '</div>';
}

/** 목록 행들(섹션 헤더 포함) HTML — 초기 렌더와 부분 갱신(?partial=1)이 공유 */
function rows_html($rows, $unreadFirst, $unreadCnt) {
    ob_start();
    $sectUn = false; $sectRead = false;
    foreach ($rows as $m):
        $pdisp = participants($m['senders']);
        $snip = mb_substr(preg_replace('/\s+/u', ' ', trim((string)$m['body'])), 0, 90);
        $chips = $m['labels'] ? array_slice(array_filter(explode('|', trim((string)$m['labels'], '|'))), 0, 2) : [];
        if ($unreadFirst && !$m['seen'] && !$sectUn) { $sectUn = true;
            echo '<div class="sect">읽지 않음 <b>' . number_format($unreadCnt) . '</b></div>'; }
        if ($unreadFirst && $m['seen'] && !$sectRead) { $sectRead = true;
            echo '<div class="sect">기타</div>'; }
    ?>
  <div class="mail<?= $m['seen'] ? '' : ' unread' ?><?= $m['starred'] ? ' starred' : '' ?>" data-uid="<?= (int)$m['uid'] ?>"
       data-subj="<?= e(subject_base($m['subject'])) ?>">
    <div class="mail-h">
      <input type="checkbox" class="rchk" title="선택">
      <span class="star" title="별표 (Gmail 반영)"><?= $m['starred'] ? '★' : '☆' ?></span>
      <span class="dot"></span>
      <span class="sender" title="<?= e($pdisp) ?>"><?= e($pdisp) ?><?= (int)$m['cnt'] > 1 ? ' <b class="cnt">' . (int)$m['cnt'] . '</b>' : '' ?></span>
      <span class="subj"><?php foreach ($chips as $ch) { [$bg, $fg] = label_color($ch);
        echo '<span class="lchip" style="background:' . e($bg) . ';color:' . e($fg) . '">' . e($ch) . '</span>'; } ?><?= e(subject_base($m['subject'])) ?><?= $snip !== '' ? '<span class="snip"> - ' . e($snip) . '</span>' : '' ?></span>
      <?= !empty($m['has_att']) ? '<span class="clip" title="첨부파일 있음"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg></span>' : '' ?>
      <span class="date"><?= e(fmt_date($m['udate'])) ?></span>
    </div>
    <div class="mail-b"></div>
  </div>
    <?php endforeach;
    return ob_get_clean();
}
?>
<!doctype html>
<html lang="ko" class="light wh"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gmail · Coursemos WorkHub</title>
<link rel="icon" type="image/svg+xml" href="../../styles/favicons/slack.svg">
<?php require_once __DIR__ . '/../../core/pwa.php'; echo pwa_head_tags('../../'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<?php /* 업무 현황판(lists.php)과 같은 CSS — 머리말(제목·업무 탭·Gmail 탭)이 똑같이 보이게. 수정 시각으로 캐시 무효화.
         이 페이지는 다크 모드를 아직 지원하지 않아 html.light 로 밝은 색에 고정한다. */
foreach (['header.css', 'common.css', 'lists.css', 'lists-dti.css'] as $__css): ?>
<link rel="stylesheet" href="../styles/<?= $__css ?>?v=<?= filemtime(__DIR__ . '/../styles/' . $__css) ?>">
<?php endforeach; ?>
<style>
  :root { --line:#e0e3e7; --hint:#8a9099; --bg2:#f6f8fc; --read:#f2f6fc; --ink:#1f1f1f; --sub:#5f6368; }
  * { box-sizing:border-box; }
  body { font:14px/1.5 -apple-system,"Malgun Gothic",sans-serif; margin:0; color:var(--ink); background:var(--bg2); }
  header { position:sticky; top:0; z-index:10; padding:12px 20px; background:#fff; border-bottom:1px solid var(--line);
           display:flex; align-items:center; gap:12px; box-shadow:0 1px 2px rgba(60,64,67,.06); }
  header h1 { font-size:16px; margin:0; white-space:nowrap; }
  header .meta { color:var(--hint); font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  header #labelsel { max-width:170px; }
  /* 검색창 (Gmail 식) */
  .hsearch { flex:1; min-width:160px; max-width:520px; position:relative; display:flex; }
  .hsearch input { width:100%; border:1px solid transparent; background:#f1f3f4; border-radius:22px;
                   padding:9px 34px 9px 16px; font:13.5px -apple-system,"Malgun Gothic",sans-serif; }
  .hsearch input:focus { outline:none; background:#fff; border-color:#e0e3e7; box-shadow:0 1px 4px rgba(60,64,67,.2); }
  .hsearch .hclear { position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#5f6368;
                     text-decoration:none; font-size:13px; padding:4px; }
  /* 별표 */
  .mail-h .star { flex:none; font-size:16px; color:#c4c7cb; cursor:pointer; line-height:1; }
  .mail-h .star:hover { color:#f4b400; }
  .mail.starred .star { color:#f4b400; }
  /* 목록 라벨 칩 */
  .lchip { display:inline-block; font-size:11px; border-radius:4px; padding:1px 7px; margin-right:6px;
           vertical-align:1px; font-weight:600; white-space:nowrap; }
  /* 섹션 헤더 (읽지 않음 / 기타) */
  .sect { padding:9px 18px 7px; font-size:12px; font-weight:700; color:#5f6368; background:#fafbfe;
          border-bottom:1px solid #eceff3; }
  .sect b { color:#1a73e8; }
  /* 체크박스 */
  .mail-h .rchk { flex:none; width:15px; height:15px; accent-color:#1a73e8; cursor:pointer; }
  /* 일괄 처리 바 */
  #bulkbar { position:fixed; bottom:22px; left:50%; transform:translateX(-50%); display:none; align-items:center; gap:8px;
             background:#202124; color:#e8eaed; border-radius:24px; padding:10px 18px; font-size:13px;
             box-shadow:0 4px 16px rgba(0,0,0,.35); z-index:9998; }
  #bulkbar.show { display:flex; }
  #bulkbar b { color:#8ab4f8; }
  #bulkbar button { background:rgba(255,255,255,.1); color:#e8eaed; border:none; border-radius:16px;
                    padding:6px 14px; font-size:12.5px; cursor:pointer; }
  #bulkbar button:hover { background:rgba(255,255,255,.22); }
  #bulkbar #bulk-x { background:none; color:#9aa0a6; }
  header .toggle { font-size:12px; text-decoration:none; color:#555; border:1px solid var(--line);
                   border-radius:16px; padding:5px 12px; white-space:nowrap; background:#fff; cursor:pointer; }
  header .toggle:hover { background:var(--bg2); }
  header .toggle.on { color:#1a73e8; border-color:#1a73e8; background:#e8f0fe; }
  header .toggle:disabled { color:#aaa; cursor:default; }
  #syncbar { display:none; padding:8px 20px; background:#fff8e6; border-bottom:1px solid #f3e2b3; font-size:12px; color:#7a5c00; }
  #syncbar.show { display:block; }
  #syncbar a { color:#1a73e8; font-weight:600; text-decoration:none; }
  .wrap { max-width:1000px; margin:0 auto; padding:16px 20px 60px; }
  .err { background:#fff4f4; border:1px solid #f3c2c2; color:#a12; padding:14px 16px; border-radius:8px; white-space:pre-wrap; }
  /* ===== Gmail 식 목록: 한 줄 행 (안읽음=흰 배경+굵게 / 읽음=옅은 파랑) ===== */
  .maillist { border:1px solid var(--line); border-radius:14px; overflow:hidden; background:#fff;
              box-shadow:0 1px 2px rgba(60,64,67,.08); }
  .mail { border-bottom:1px solid #eceff3; }
  .mail:last-child { border-bottom:none; }
  .mail-h { display:flex; align-items:center; gap:12px; padding:13px 18px; cursor:pointer; background:var(--read); }
  .mail.unread .mail-h { background:#fff; }
  .mail-h:hover { position:relative; z-index:1; box-shadow:inset 1px 0 0 #dadce0, inset -1px 0 0 #dadce0, 0 1px 3px rgba(60,64,67,.24); }
  .mail-h .dot { flex:none; width:8px; height:8px; border-radius:50%; background:transparent; }
  .mail-h .sender { flex:none; width:190px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--sub); font-size:14px; }
  .mail-h .sender .cnt { font-weight:400; color:var(--sub); margin-left:2px; font-size:12.5px; }
  .mail-h .subj { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--sub); font-size:14px; }
  .mail-h .subj .snip { color:#9aa0a6; font-weight:400; }
  .mail-h .clip { flex:none; display:inline-flex; align-items:center; color:#5f6368; }
  .mail-h .date { flex:none; width:74px; text-align:right; font-size:12px; color:var(--sub); font-variant-numeric:tabular-nums; }
  .mail.unread .dot { background:#1a73e8; }
  .mail.unread .sender, .mail.unread .subj { font-weight:700; color:#202124; }
  .mail.unread .date { font-weight:700; color:#202124; }
  .mail.open { box-shadow:0 2px 10px rgba(60,64,67,.22); border-radius:10px; margin:8px; border:1px solid var(--line); }
  .mail.open .mail-h { background:#fff; }
  /* ===== 상세(펼침): Gmail 식 읽기 화면 ===== */
  .mail-b { display:none; border-top:1px solid var(--line); background:#fff; }
  .mail.open .mail-b { display:block; }
  .det-h { padding:20px 24px 8px; display:flex; align-items:flex-start; gap:10px; }
  .det-subj { flex:1; font-size:19px; font-weight:600; color:#202124; margin-bottom:4px; word-break:break-word; line-height:1.4; }
  .exp-all { flex:none; align-self:center; display:inline-flex; align-items:center; gap:5px; line-height:1;
             font-size:12px; color:#5f6368; border:1px solid var(--line); border-radius:16px;
             padding:7px 12px; background:#fff; cursor:pointer; white-space:nowrap; }
  .exp-all .ic { font-size:10px; line-height:1; }
  .exp-all:hover { background:var(--bg2); color:#1a73e8; border-color:#1a73e8; }
  .msg { border-top:1px solid #f1f3f4; }
  .det-h + .msg { border-top:none; }
  .det-meta { display:flex; align-items:center; gap:10px; padding:10px 20px 4px; cursor:pointer; }
  .det-meta .det-date { margin-left:auto; flex:none; }
  .det-who { flex:1; min-width:0; display:flex; align-items:center; gap:8px; }
  .det-who b { flex:none; font-size:13px; }
  .det-who .snippet { display:none; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
                      color:#5f6368; font-size:12.5px; }
  /* 접힌 메일: 한 줄(이름 + 미리보기 + 날짜)만 */
  .msg.collapsed .msg-body { display:none; }
  .msg.collapsed .det-meta { padding:10px 20px; }
  .msg.collapsed .det-who .em { display:none; }
  .msg.collapsed .det-who .snippet { display:block; }
  .det-av { flex:none; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            color:#fff; font-weight:700; font-size:13px; }
  .det-av img { width:100%; height:100%; border-radius:50%; object-fit:cover; display:block; }   /* Slack 프로필 사진(있으면 이니셜 대신) */
  .det-who b { color:#202124; }
  .det-who .em { color:#5f6368; font-size:12px; margin-left:4px; }
  .det-date { font-size:12px; color:#5f6368; }
  .mail-frame { width:100%; border:none; display:block; min-height:60px; background:#fff; }
  .mail-text { padding:10px 24px 14px; white-space:pre-wrap; word-break:break-word; font-size:13px; line-height:1.55;
               color:#202124; max-width:860px; }
  .mail-load { padding:20px; color:var(--hint); font-size:13px; }
  .q-toggle { display:inline-block; margin:0 20px 10px; padding:0 10px; border:1px solid #dadce0; border-radius:8px;
              background:#f1f3f4; color:#5f6368; font-weight:700; cursor:pointer; line-height:1.5; user-select:none; }
  .q-body { color:#5f6368; }
  /* 답장 */
  .reply-bar { display:flex; gap:8px; padding:14px 20px; border-top:1px solid #f1f3f4; }
  .reply-btn { display:inline-flex; align-items:center; gap:7px; border:1px solid var(--line); border-radius:18px;
               padding:7px 16px; background:#fff; cursor:pointer; font-size:13px; color:#3c4043; line-height:1; }
  .reply-btn svg { flex:none; }
  .reply-btn:hover { background:#e8f0fe; color:#1a73e8; border-color:#1a73e8; }
  .compose .c-row { display:flex; align-items:center; gap:10px; }
  .compose .c-row label { flex:none; width:56px; font-size:12px; color:#5f6368; text-align:right; }
  .compose .c-row input { flex:1; border:1px solid var(--line); border-radius:8px; padding:7px 10px;
                          font:13px -apple-system,"Malgun Gothic",sans-serif; color:#202124; }
  .compose .c-row input:focus { outline:none; border-color:#1a73e8; }
  .compose .c-row input:disabled { background:var(--bg2); color:#9aa0a6; }
  .compose { display:flex; flex-direction:column; gap:8px; padding:0 20px 16px; }
  .compose textarea { width:100%; min-height:110px; border:1px solid var(--line); border-radius:10px; padding:10px 12px;
                      font:13px/1.6 -apple-system,"Malgun Gothic",sans-serif; resize:vertical; }
  .compose textarea:focus { outline:none; border-color:#1a73e8; }
  .compose .c-info { font-size:12px; color:#5f6368; }
  .compose .c-actions { display:flex; align-items:center; gap:8px; }
  .compose .c-send { background:#1a73e8; color:#fff; border:none; border-radius:18px; padding:8px 20px; cursor:pointer; font-size:13px; }
  .compose .c-send:hover { background:#1765cc; }
  .compose .c-send:disabled { background:#9ab8e8; cursor:default; }
  .compose .c-cancel { background:#fff; color:#3c4043; border:1px solid var(--line); border-radius:18px; padding:7px 16px; cursor:pointer; font-size:13px; }
  .compose .c-stat { font-size:12px; color:#c5221f; }
  .c-done { padding:0 20px 14px; font-size:12px; color:#188038; }
  /* 첨부 알약 = 링크 */
  a.att-pill { text-decoration:none; color:#3c4043; cursor:pointer; }
  a.att-pill:hover { border-color:#1a73e8; color:#1a73e8; background:#f8fbff; }
  /* 이미지 라이트박스 모달 */
  #glb { position:fixed; inset:0; display:none; background:rgba(0,0,0,.86); z-index:9999; align-items:center; justify-content:center; }
  #glb.open { display:flex; }
  #glb-img { max-width:92vw; max-height:86vh; border-radius:6px; background:#111; box-shadow:0 10px 44px rgba(0,0,0,.55);
             transform-origin:center; transition:transform .12s ease; cursor:zoom-in; will-change:transform; }
  #glb-img.zoomed { cursor:grab; transition:none; }
  #glb-x { position:fixed; top:16px; right:20px; background:rgba(255,255,255,.14); color:#fff; border:none;
           width:38px; height:38px; border-radius:50%; font-size:17px; cursor:pointer; z-index:2; }
  #glb-x:hover { background:rgba(255,255,255,.3); }
  #glb-bar { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); display:flex; align-items:center; gap:8px;
             background:rgba(30,30,30,.85); border-radius:22px; padding:7px 14px; z-index:2; }
  #glb-bar button { background:rgba(255,255,255,.14); color:#fff; border:none; width:30px; height:28px;
                    border-radius:7px; font-size:15px; line-height:1; cursor:pointer; }
  #glb-bar button:hover { background:rgba(255,255,255,.3); }
  #glb-bar #glb-zv { min-width:46px; text-align:center; color:#ccc; font-size:12px; }
  #glb-bar a { color:#8ab4f8; font-size:13px; text-decoration:none; font-weight:600; margin-left:4px; }
  /* 첨부/라벨 */
  .atts { display:flex; gap:8px; flex-wrap:wrap; padding:12px 20px; border-top:1px solid #f1f3f4; }
  .att-pill { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--line); border-radius:16px;
              padding:5px 12px; font-size:12px; color:#3c4043; background:#fff; }
  .att-pill .pill-ic { display:inline-flex; align-items:center; color:#5f6368; }
  .labels { display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:12px; padding:10px 20px 14px; border-top:1px solid #f1f3f4; margin:0; }
  .labels .lb-t { color:var(--hint); }
  .chip { display:inline-flex; align-items:center; gap:4px; background:#e8f0fe; color:#1a56b8; border-radius:12px; padding:2px 9px; }
  .chip.sys { background:#f1f3f4; color:#5f6368; }
  .chip button { border:none; background:none; color:#1a56b8; cursor:pointer; font-size:13px; line-height:1; padding:0; }
  .chip button:hover { color:#c00; }
  .labels select { border:1px solid var(--line); border-radius:8px; padding:2px 6px; font-size:12px; color:#555; max-width:160px; }
  .labels .lb-busy { color:var(--hint); }
  .empty { color:var(--hint); padding:40px; text-align:center; }
  /* 페이저 */
  .pager { display:flex; align-items:center; justify-content:center; gap:8px; margin:14px 0; flex-wrap:wrap; }
  .pager a, .pager span.cur { padding:6px 14px; border:1px solid var(--line); border-radius:18px; text-decoration:none;
                              color:#1a73e8; font-size:13px; background:#fff; }
  .pager a:hover { background:#e8f0fe; border-color:#1a73e8; }
  .pager .disabled { color:#bbb; border-color:#eceff3; background:transparent; pointer-events:none; }
  .pager .cur { color:#202124; background:#e8f0fe; border-color:#1a73e8; font-weight:600; }
  .pager form { display:inline-flex; gap:4px; align-items:center; }
  .pager input { width:64px; padding:5px; border:1px solid var(--line); border-radius:6px; text-align:center; }

  /* ===== 업무 현황판 디자인(html.wh) — 현황판(slack/styles/lists-dti.css)과 같은 룩.
     구조·기능은 원래 Gmail 화면 그대로, 겉모양만 맞춘다. 위의 원래 규칙을 덮어쓴다. ===== */
  html.wh { --page:#f2f9ff; --line:#e0e3e5; --line2:#eeeff0; --line3:#d3d6d9; --hint:#899199;
               --cos50:#0075f8; --cos45:#0069e1; --cos40:#005dc8; --cos95:#e5f1ff; --ctl-h:32px; }
  html.wh body { font-family:'Pretendard Variable',Pretendard,-apple-system,BlinkMacSystemFont,system-ui,'Malgun Gothic',sans-serif;
                    background:var(--page); color:#161819; letter-spacing:-.01em; -webkit-font-smoothing:antialiased; }
  html.wh header { position:static; background:var(--page); border:none; box-shadow:none; padding:0 0 10px; gap:6px; flex-wrap:wrap; }
  html.wh header h1 { display:none; }
  html.wh .hsearch { flex:0 1 260px; max-width:none; }
  html.wh .hsearch input { height:var(--ctl-h); padding:0 28px 0 30px; border:1px solid var(--line3); border-radius:8px;
    font:12.5px/1 inherit; font-family:inherit; color:#161819;
    background:#fff url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23899199' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M21 21l-4.3-4.3'/%3E%3C/svg%3E") no-repeat 10px center / 14px; }
  html.wh .hsearch input:focus { border-color:var(--cos50); box-shadow:0 0 0 3px var(--cos95); background-color:#fff; }
  /* 브라우저가 type=search 에 붙이는 지우기(✖)는 숨긴다 — 검색 해제는 오른쪽 ✕(다른 필터 유지)가 맡는다. 둘이 겹쳐 보였다 */
  html.wh .hsearch input::-webkit-search-cancel-button { -webkit-appearance:none; appearance:none; display:none; }
  html.wh .hsearch input::-ms-clear { display:none; }
  html.wh .hsearch .hclear { right:9px; color:#899199; font-size:12px; }
  html.wh .hsearch .hclear:hover { color:#161819; }
  html.wh .hsearch .hclear[hidden] { display:none; }
  html.wh header .meta { font-size:12.5px; color:var(--hint); margin-right:auto; }
  html.wh header .toggle { height:var(--ctl-h); display:inline-flex; align-items:center; padding:0 10px; border:1px solid var(--line3);
    border-radius:8px; background:#fff; color:#2c2f32; font-family:inherit; font-size:12.5px; font-weight:600; }
  html.wh header select.toggle { appearance:none; -webkit-appearance:none; padding-right:28px; cursor:pointer;
    background:#fff url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236d767e' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 9px center / 12px; }
  html.wh header .toggle:hover { background-color:#f5f5f5; border-color:#c4c8cc; }
  html.wh header .toggle.on { color:var(--cos40); border-color:var(--cos50); background-color:var(--cos95); }
  html.wh .wrap { max-width:none; padding:0 0 24px; }
  html.wh #syncbar { margin:0 0 10px; border:1px solid #f3e2b3; border-radius:10px; }
  /* 목록 판 = 현황판 목록 판(.listbox) */
  html.wh .maillist { border-color:var(--line); border-radius:16px; box-shadow:0 1px 2px rgba(22,24,25,.05); }
  html.wh .sect { padding:11px 18px; background:#f4f7fd; color:#0f2a55; font-size:12.5px; font-weight:600; border-bottom-color:var(--line); }
  html.wh .sect b { color:var(--cos50); }
  html.wh .mail { border-bottom-color:var(--line2); }
  /* 행 = 현황판 행: 흰 바탕, 안읽음은 왼쪽 파란 띠 + 진한 글자, 읽음은 회색 */
  html.wh .mail-h { padding:11px 18px; gap:12px; background:#fff; transition:background .12s ease; }
  html.wh .mail-h:hover { box-shadow:none; background:#fafafa; }
  html.wh .mail.unread .mail-h { box-shadow:inset 3px 0 0 var(--cos50); }
  html.wh .mail-h .dot { display:none; }
  html.wh .mail-h .rchk { width:16px; height:16px; accent-color:var(--cos50); }
  html.wh .mail-h .sender { width:170px; font-size:12.5px; font-weight:500; color:#41474b; }
  html.wh .mail-h .sender .cnt { font-size:11.5px; color:var(--hint); }
  html.wh .mail-h .subj { font-size:14.5px; letter-spacing:-.015em; color:#575e64; }
  html.wh .mail-h .subj .snip { font-size:12.5px; color:var(--hint); }
  html.wh .mail-h .date { width:80px; font-size:12.5px; color:var(--hint); }
  html.wh .mail.unread .sender { font-weight:600; color:#161819; }
  html.wh .mail.unread .subj { font-weight:500; color:#161819; }
  html.wh .mail.unread .date { font-weight:600; color:#161819; }
  html.wh .lchip { border-radius:6px; font-size:11.5px; font-weight:700; padding:2px 8px; letter-spacing:0; }
  /* 펼친 대화 = 현황판 펼친 행 */
  html.wh .mail.open { margin:0; border:none; border-radius:0; box-shadow:inset 3px 0 0 var(--cos50); }
  html.wh .mail.open .mail-h { background:#f5f9ff; }
  html.wh .mail-b { border-top-color:var(--line2); }
  html.wh .det-subj { font-size:18px; font-weight:700; letter-spacing:-.02em; color:#161819; }
  html.wh .exp-all, html.wh .reply-btn, html.wh .compose .c-cancel { border-radius:8px; border-color:var(--line3);
    font-family:inherit; font-weight:600; font-size:12.5px; }
  html.wh .exp-all:hover, html.wh .reply-btn:hover { background:var(--cos95); color:var(--cos40); border-color:var(--cos50); }
  html.wh .compose .c-send { background:var(--cos50); border-radius:8px; font-family:inherit; font-weight:600; font-size:12.5px; }
  html.wh .compose .c-send:hover { background:var(--cos45); }
  html.wh .compose .c-row input, html.wh .compose textarea { font-family:inherit; border-color:var(--line3); border-radius:8px; }
  html.wh .compose .c-row input:focus, html.wh .compose textarea:focus { border-color:var(--cos50); box-shadow:0 0 0 3px var(--cos95); }
  html.wh .chip { border-radius:6px; background:var(--cos95); color:var(--cos40); }
  html.wh .chip button { color:var(--cos40); }
  html.wh .att-pill { border-radius:8px; border-color:var(--line3); }
  html.wh .labels select { border-color:var(--line3); border-radius:6px; font-family:inherit; }
  /* 페이저 = 현황판 버튼 */
  html.wh .pager a, html.wh .pager span.cur, html.wh .pager .disabled { height:var(--ctl-h); display:inline-flex; align-items:center;
    padding:0 12px; border-radius:8px; border-color:var(--line3); font-size:12.5px; font-weight:600; color:#41474b; }
  html.wh .pager a:hover { background:var(--cos95); border-color:var(--cos50); color:var(--cos40); }
  /* 현재 쪽: 위 규칙(span.cur 까지 묶은 글자색)보다 구체적이어야 흰 글씨가 이긴다 — 파란 바탕에 검은 글씨로 보였다 */
  html.wh .pager span.cur { background:var(--cos50); border-color:var(--cos50); color:#fff; }
  /* 이동할 수 없는 처음·이전·다음·끝: 버튼 모양은 그대로 두고 비활성으로만(흐린 글자·옅은 바탕) */
  html.wh .pager span.disabled { color:#b4b9be; border:1px solid var(--line2); background:#f7f8f9; cursor:not-allowed; }
  html.wh .empty { background:#fff; border:1px solid var(--line); border-radius:16px; color:var(--hint); }
  /* 현황판 머리말(제목·시계·업무 탭·Gmail 탭)을 감싸는 판 — 현황판 .wrap 과 같은 폭·여백 */
  .wh-wrap { width:100%; max-width:1600px; margin:0 auto; padding:16px 22px 60px; }
  html.wh .wh-wrap #clock { margin-right:auto; }               /* 현황판의 동기화 점(#daemonDot) 대신 시계가 오른쪽 버튼을 민다 */
  /* 현황판 CSS(common/lists.css)의 전역 규칙이 이 화면 요소에 번지는 것 막기 */
  html.wh header .meta { display:block; margin:0 auto 0 0; }  /* common.css .meta(상세 메타 줄) */
  html.wh .chip button { height:auto; padding:0; border:none; background:none; }   /* lists.css input,button{height:32px} */
  html.wh .labels select { height:26px; padding:0 26px 0 8px; background-position:right 7px center; }
  html.wh .atts { margin-top:0; }                              /* lists.css .atts(현황판 첨부) */
</style></head><body>
<?php include __DIR__ . '/../header.php'; ?>
<?php if (!gmail_configured()): /* 사용자별 연동 — 등록 전에는 목록 대신 안내만 (공용 계정으로 대신 붙지 않음) */ ?>
<div style="max-width:560px;margin:64px auto;padding:30px 32px;background:#fff;border:1px solid #e0e3e5;border-radius:14px;
            text-align:center;font-size:14px;line-height:1.75;color:#41474b;box-shadow:0 1px 2px rgba(22,24,25,.05)">
  <div style="font-size:30px;margin-bottom:6px">📧</div>
  <div style="font-size:17px;font-weight:700;color:#161819;margin-bottom:6px">Gmail 연동이 필요합니다</div>
  포털 마이페이지에서 <b>Gmail 주소</b>와 <b>Google 앱 비밀번호</b>를 등록하면 본인 메일함이 여기에 표시됩니다.
  <div style="margin-top:18px">
    <a href="<?= e($__bwBase) ?>../index.php?view=profile" target="_top"
       style="display:inline-block;padding:10px 20px;border-radius:8px;background:#0075f8;color:#fff;font-weight:600;text-decoration:none">마이페이지에서 등록하기</a>
  </div>
</div>
</body></html>
<?php exit; endif; ?>
<?php
// 토글 링크 생성: 현재 필터 유지 + 일부만 변경 (p 는 1 로 리셋)
$mkUrl = function (array $over) use ($keepArr) {
    $qs = array_filter(array_merge($keepArr, $over), fn($v) => $v !== null && $v !== '');
    return '?p=1' . ($qs ? '&' . http_build_query($qs) : '');
};
?>
<?php
// 업무 현황판(lists.php)과 같은 머리말 — 업무 탭은 현황판의 그 보드로(?board=), Gmail 탭은 지금 화면.
// 건수는 현황판 탭과 같은 기준(보관 제외 전체), Gmail 숫자 = 안 읽은 대화 수(받은편지함 숫자와 같은 기준).
$boardCnt = ['all' => 0];
foreach ($pdo->query("SELECT board, COUNT(*) AS n FROM requests WHERE archived = 0 GROUP BY board") as $__r) {
    $boardCnt[$__r['board']] = (int)$__r['n'];
    $boardCnt['all'] += (int)$__r['n'];
}
$__q = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(thrid,0), uid)) FROM gmail_mails WHERE account = ? AND seen = 0");
$__q->execute([gmail_owner()]);
$gmailUnread = (int)$__q->fetchColumn();
?>
<div class="wh-wrap">
  <div class="head">
    <h1>Coursemos WorkHub</h1>
    <span id="clock" title="현재 시각"></span>
  </div>
  <div class="toolbar">
    <div class="btabs">
      <?php foreach ([['all', '전체'], ['블루소프트', '유비온'], ['와이오즈', '와이오즈']] as [$__v, $__l]): ?>
      <a class="btab" data-b="<?= e($__v) ?>" href="../lists.php?board=<?= rawurlencode($__v) ?>"><?= e($__l) ?> <span class="btab-n"><?= (int)($boardCnt[$__v] ?? 0) ?></span></a>
      <?php endforeach; ?>
    </div>
    <a id="gmailTab" class="gtab on" href="./" title="Gmail 받은편지함"><svg class="gtab-ic" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><span>Gmail</span><span class="btab-n" id="gmailCnt"<?= $gmailUnread > 0 ? '' : ' hidden' ?>><?= $gmailUnread > 999 ? '999+' : $gmailUnread ?></span></a>
    <!-- 검색: 현황판처럼 탭 줄 오른쪽 (Gmail 탭이 margin-right:auto 로 밀어 준다) -->
    <form class="hsearch" method="get">
      <?php foreach ($keepArr as $k => $v) if ($k !== 'q') echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">'; ?>
      <input type="search" name="q" value="<?= e($qF) ?>" placeholder="메일 검색 (제목·보낸사람·본문)">
      <?php /* ✕ 는 항상 두고 글자가 있을 때만 보인다(아래 스크립트) — 검색 중이면 검색 해제(다른 필터 유지), 입력만 했으면 글자만 지움 */ ?>
      <a class="hclear" href="<?= e($mkUrl(['q' => null])) ?>" title="검색 지우기" data-active="<?= $qF !== '' ? '1' : '0' ?>"<?= $qF !== '' ? '' : ' hidden' ?>>✕</a>
    </form>
  </div>
<header>
  <h1>📧 Gmail</h1>
  <span class="meta">
    <?= number_format($count) ?>개 중 <?= number_format($from) ?>–<?= number_format($to) ?>
    · 동기화 <?= e(substr((string)$lastSync, 11, 5) ?: $lastSync) ?>
  </span>
  <select id="psel" class="toggle<?= $nRaw !== '' ? ' on' : '' ?>" title="페이지당 표시 개수">
    <option value="">개수: 기본(<?= (int)($g['limit'] ?: 50) ?>)</option>
    <?php foreach ([100, 200, 300, 400, 500, 1000] as $nOpt): /* 50 = 기본과 같아 뺀다 */ ?>
    <option value="<?= $nOpt ?>"<?= (int)$nRaw === $nOpt ? ' selected' : '' ?>><?= $nOpt ?>개</option>
    <?php endforeach; ?>
    <option value="all"<?= $showAll ? ' selected' : '' ?>>모두</option>
  </select>
  <select id="labelsel" class="toggle<?= $labelF !== '' ? ' on' : '' ?>" title="라벨로 필터">
    <option value="">🏷️ 전체 라벨</option>
    <?php foreach ($allLabels as $lb): ?>
    <option value="<?= e($lb) ?>"<?= $lb === $labelF ? ' selected' : '' ?>><?= e($lb) ?></option>
    <?php endforeach; ?>
  </select>
  <a class="toggle<?= $starF ? ' on' : '' ?>" href="<?= e($mkUrl(['star' => $starF ? null : 1])) ?>" title="별표 대화만 보기"><?= $starF ? '★ 별표만' : '☆ 별표' ?></a>
  <a class="toggle<?= $unreadFirst ? ' on' : '' ?>" href="<?= e($mkUrl(['unread' => $unreadFirst ? 0 : 1])) ?>"><?= $unreadFirst ? '🔵 안읽음 우선' : '⚪ 안읽음 우선' ?></a>
  <button id="syncbtn" class="toggle" type="button" title="새 메일·읽음 변경만 DB 에 반영">🔄 동기화</button>
</header>
<script>
const BASE_QS = <?= json_encode(($x = array_filter($keepArr, fn($k) => $k !== 'label', ARRAY_FILTER_USE_KEY)) ? '&' . http_build_query($x) : '') ?>;
document.getElementById("labelsel").addEventListener("change", function(){
  location.href = "?p=1" + BASE_QS + (this.value ? "&label=" + encodeURIComponent(this.value) : "");
});
document.getElementById("psel").addEventListener("change", function(){
  const sp = new URLSearchParams(location.search);
  sp.delete("p");
  sp.set("n", this.value || "def");                        // 기본 선택 = def (쿠키 기억값 리셋)
  location.href = "?p=1" + ([...sp].length ? "&" + sp.toString() : "");
});
</script>
<div id="syncbar"></div>
<div class="wrap">
<?php if (!$rows): ?>
  <div class="empty"><?= $count === 0 ? '메일 수집 전입니다 — 백그라운드 동기화가 곧 시작됩니다…' : '표시할 메일이 없습니다.' ?></div>
<?php else: ?>
  <div class="maillist">
  <?= rows_html($rows, $unreadFirst, $unreadCnt) ?>
  </div>
  <?= pager_html($pg) ?>
<?php endif; ?>
</div>
</div><!-- /.wh-wrap -->
<script>
/* 현황판과 같은 머리말: 시계(1초 갱신) + Gmail 안 읽은 수(30초 — 대화를 읽으면 줄어든다) */
(function(){
  const p2 = n => (n < 10 ? "0" : "") + n, D = ["일","월","화","수","목","금","토"];
  const clock = document.getElementById("clock");
  const tick = () => {
    const d = new Date();
    clock.textContent = d.getFullYear() + "-" + p2(d.getMonth() + 1) + "-" + p2(d.getDate())
                      + " (" + D[d.getDay()] + ") " + p2(d.getHours()) + ":" + p2(d.getMinutes()) + ":" + p2(d.getSeconds());
  };
  tick(); setInterval(tick, 1000);
  // 검색칸 ✕ — 글자가 있으면 보인다. 검색 중(data-active=1)이면 링크대로 검색 해제, 입력만 했으면 글자만 지운다
  const sf = document.querySelector(".hsearch");
  if (sf) {
    const inp = sf.querySelector('input[name="q"]'), x = sf.querySelector(".hclear");
    const sync = () => { x.hidden = inp.value === "" && x.dataset.active !== "1"; };
    inp.addEventListener("input", sync);
    x.addEventListener("click", e => {
      if (x.dataset.active === "1") return;
      e.preventDefault(); inp.value = ""; sync(); inp.focus();
    });
    sync();
  }
  const cnt = document.getElementById("gmailCnt");
  setInterval(async () => {
    try {
      const j = await (await fetch("?ping=1&unread=1", {cache: "no-store"})).json();
      if (j && j.ok) { const n = j.unread || 0; cnt.textContent = n > 999 ? "999+" : String(n); cnt.hidden = n <= 0; }
    } catch (e) { /* 다음 주기에 */ }
  }, 30000);
})();
</script>
<script>
/* ---- 본문 lazy 로드 (DB 캐시 → 최초 1회만 IMAP) — Gmail 식 읽기 화면 ---- */
function avColor(s){ let h=0; for(let i=0;i<s.length;i++) h=(h*31+s.charCodeAt(i))>>>0; return `hsl(${h%360} 55% 55%)`; }
function senderParts(full){
  const m = full.match(/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/);
  return m ? {name:(m[1].trim()||m[2]), email:m[2]} : {name:full, email:""};
}
/* ---- 보낸 사람 사진 — 서버(?avatars=1)가 Slack 프로필 사진을 이메일(권한 없으면 이름)로 찾아 준다.
   대화를 먼저 그리고 나중에 이니셜 자리에 끼우므로 화면이 사진을 기다리지 않는다.
   결과는 브라우저에도 보관(사진 있음 하루 / 없음 6시간) — 다시 열 때는 묻지 않는다. ---- */
const AV_KEY = "gmail_avatars_v1";
let avMem = {}; try { avMem = JSON.parse(localStorage.getItem(AV_KEY) || "{}") || {}; } catch (e) { avMem = {}; }
let avWait = [], avTimer = null;
function avApply(el, url){
  if (!url) return;
  const img = new Image(); img.alt = "";
  img.onload = () => { el.textContent = ""; el.style.background = "none"; el.appendChild(img); };   // 못 받으면 이니셜 그대로
  img.src = url;
}
function avRequest(el, email, name){
  email = (email || "").toLowerCase();
  if (!email) return;
  const c = avMem[email];
  if (c && Date.now() - c.t < (c.u ? 86400000 : 21600000)) { avApply(el, c.u); return; }
  avWait.push({el, email, name});
  clearTimeout(avTimer); avTimer = setTimeout(avFlush, 0);   // 한 대화의 보낸 사람들을 한 번에
}
async function avFlush(){
  const batch = avWait; avWait = [];
  const people = [...new Map(batch.map(p => [p.email, {email: p.email, name: p.name}])).values()];
  if (!people.length) return;
  try {
    const j = await (await fetch("?avatars=1", {method: "POST", headers: {"Content-Type": "application/json"},
                                                 body: JSON.stringify({people})})).json();
    if (!j.ok) return;
    for (const p of people) if (p.email in j.map) avMem[p.email] = {u: j.map[p.email] || "", t: Date.now()};
    try { localStorage.setItem(AV_KEY, JSON.stringify(avMem)); } catch (e) {}
    batch.forEach(p => { const c = avMem[p.email]; if (c) avApply(p.el, c.u); });
  } catch (e) { /* 사진은 부가 기능 — 실패하면 이니셜 그대로 */ }
}
/* plain 텍스트에서 인용 시작점 찾기: ">" 줄, "...wrote:", "...님이 작성:" 등 */
function splitQuotedText(text){
  const lines = text.split("\n");
  let idx = -1;
  for (let i = 0; i < lines.length; i++) {
    const L = lines[i];
    if (/^\s*>/.test(L) || /wrote:\s*$/.test(L) || /님이 작성:\s*$/.test(L) || /^-{2,}\s*Original Message/i.test(L)) { idx = i; break; }
  }
  if (idx < 2) return [text, ""];              // 본문 첫머리부터 인용이면 접지 않음
  return [lines.slice(0, idx).join("\n").trimEnd(), lines.slice(idx).join("\n")];
}
/* 메일 본문 컨텐츠(iframe/텍스트 + 첨부)만 생성 — 펼칠 때 lazy 로 빌드 */
function msgContent(m){
  const box = document.createElement("div");
  if (m.html) {                                            // HTML 메일: sandbox iframe 에 서식 그대로
    const f = document.createElement("iframe");
    f.className = "mail-frame";
    f.setAttribute("sandbox", "allow-same-origin allow-popups");   // 스크립트 차단, 링크 새 탭 허용
    box.appendChild(f);
    f.addEventListener("load", () => {
      const resize = () => {
        try {
          f.style.height = "0px";              // 먼저 0 으로 줄여야 축소 시에도 실제 내용 높이가 측정됨
          const h = f.contentDocument.documentElement.scrollHeight;
          f.style.height = Math.min(h + 20, window.innerHeight * 0.75) + "px";
        } catch (e) { f.style.height = "60vh"; }
      };
      // Gmail 식 인용 접기: 답장에 딸려온 이전 대화(gmail_quote/blockquote)를 ⋯ 뒤로 숨김.
      // iframe 내부는 스크립트 차단이라 부모(같은 출처)에서 DOM 을 조작한다.
      try {
        const d = f.contentDocument;
        const qs = [...d.querySelectorAll("div.gmail_quote, blockquote")]
          .filter(q => !q.parentElement.closest("div.gmail_quote, blockquote"));   // 최상위 인용만
        qs.forEach(q => {
          q.style.display = "none";
          const t = d.createElement("div");
          t.textContent = "⋯"; t.title = "이전 대화 내용 표시";
          t.setAttribute("style", "display:inline-block;margin:8px 0;padding:0 10px;border:1px solid #dadce0;border-radius:8px;background:#f1f3f4;color:#5f6368;font-weight:700;cursor:pointer;line-height:1.5;user-select:none");
          q.parentNode.insertBefore(t, q);
          t.addEventListener("click", () => { q.style.display = q.style.display === "none" ? "" : "none"; resize(); });
        });
        // 본문 이미지 클릭 → 부모의 라이트박스 모달로 확대 (iframe 은 same-origin 이라 접근 가능)
        d.querySelectorAll("img").forEach(im => {
          im.style.cursor = "zoom-in";
          im.addEventListener("click", e => { e.preventDefault(); e.stopPropagation(); glbOpen(im.src, im.alt || "image"); });
        });
      } catch (e) { /* 접기 실패해도 본문 표시는 유지 */ }
      resize();
    });
    f.srcdoc = `<style>
      body{margin:0;padding:8px 24px 14px;font:13px/1.55 -apple-system,'Malgun Gothic',sans-serif;color:#202124;word-break:break-word}
      img{max-width:100%;height:auto} table{max-width:100%}
      blockquote{margin:0 0 0 6px;padding-left:12px;border-left:2px solid #dadce0;color:#5f6368}
      a{color:#1a73e8}
    </style>` + m.html;
  } else {
    // plain 텍스트: ">" 인용/"...님이 작성:" 이후를 ⋯ 뒤로 숨김
    const [main, quoted] = splitQuotedText(m.body || "");
    const t = document.createElement("div"); t.className = "mail-text";
    t.textContent = main || "(본문 없음)";
    box.appendChild(t);
    if (quoted) {
      const tg = document.createElement("div"); tg.className = "q-toggle"; tg.textContent = "⋯"; tg.title = "이전 대화 내용 표시";
      const qd = document.createElement("div"); qd.className = "mail-text q-body"; qd.textContent = quoted; qd.style.display = "none";
      tg.addEventListener("click", () => { qd.style.display = qd.style.display === "none" ? "" : "none"; });
      box.appendChild(tg); box.appendChild(qd);
    }
  }
  if (m.atts && m.atts.length) {
    const d = document.createElement("div"); d.className = "atts";
    m.atts.forEach((a, i) => {
      const ext = (a.split(".").pop() || "").toLowerCase();
      const isImg = ["png", "jpg", "jpeg", "gif", "webp", "bmp"].includes(ext);
      const p = document.createElement("a"); p.className = "att-pill";
      const ic = document.createElement("span"); ic.className = "pill-ic";
      ic.innerHTML = isImg
        ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>'
        : '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>';
      p.appendChild(ic);
      p.appendChild(document.createTextNode(a));
      if (m.uid) {                                         // 낙관적(방금 보낸) 블록은 uid 없음 → 링크 생략
        const url = "?att=" + m.uid + "&i=" + i + "&name=" + encodeURIComponent(a);
        if (isImg) { p.href = "javascript:void(0)"; p.addEventListener("click", () => glbOpen(url, a)); }   // 이미지 → 모달 확대
        else if (ext === "pdf") { p.href = url; p.target = "_blank"; p.rel = "noopener"; }                  // pdf → 브라우저 뷰어
        else { p.href = url + "&dl=1"; }                                                                    // 기타 → 다운로드
      }
      d.appendChild(p);
    });
    box.appendChild(d);
  }
  return box;
}
/* 메일 1건 블록 — expanded=false 면 한 줄(이름+미리보기+날짜)로 접힘, 클릭 시 펼침 (Gmail 동일) */
function buildMsg(m, expanded){
  const box = document.createElement("div"); box.className = "msg" + (expanded ? "" : " collapsed");
  const sp = senderParts(m.sender || "");
  const meta = document.createElement("div"); meta.className = "det-meta";
  const av = document.createElement("span"); av.className = "det-av";
  av.style.background = avColor(sp.name); av.textContent = (sp.name || "?").charAt(0).toUpperCase();
  avRequest(av, sp.email, sp.name);
  const who = document.createElement("div"); who.className = "det-who";
  const nm = document.createElement("b"); nm.textContent = sp.name;
  who.appendChild(nm);
  if (sp.email) { const em = document.createElement("span"); em.className = "em"; em.textContent = "<" + sp.email + ">"; who.appendChild(em); }
  const sn = document.createElement("span"); sn.className = "snippet";
  sn.textContent = splitQuotedText(m.body || "")[0].replace(/\s+/g, " ").slice(0, 140);
  who.appendChild(sn);
  const dt = document.createElement("span"); dt.className = "det-date"; dt.textContent = m.datef || "";
  meta.appendChild(av); meta.appendChild(who); meta.appendChild(dt);
  box.appendChild(meta);
  const cont = document.createElement("div"); cont.className = "msg-body";
  box.appendChild(cont);
  let built = false;
  const render = () => { if (!built) { cont.appendChild(msgContent(m)); built = true; } };
  box._setCollapsed = c => { if (!c) render(); box.classList.toggle("collapsed", c); };   // 일괄 펼치기/접기용
  if (expanded) render();
  meta.addEventListener("click", e => {                    // 헤더 클릭으로 접기/펼치기 토글
    if (e.target.closest("a")) return;
    box._setCollapsed(!box.classList.contains("collapsed"));
  });
  return box;
}
/* 대화 전체 렌더: 제목 1회 + 시간순. 마지막(최신) 메일만 펼치고 나머지는 접힘 */
/* 인라인 답장 작성창 (Gmail 식) */
const MY_ACCT = <?= json_encode($acct, JSON_UNESCAPED_UNICODE) ?>;
function openCompose(body, foot, lastMail, mode){
  const old = body.querySelector(".compose"); if (old) old.remove();
  const c = document.createElement("div"); c.className = "compose";
  const info = document.createElement("div"); c.appendChild(info); info.className = "c-info";
  info.textContent = mode === "all" ? "전체 답장" : "답장";
  // 받는사람/참조 — 미리 계산해 채워주고 자유롭게 수정 가능 (쉼표로 여러 명)
  const mkRow = (label, ph) => {
    const row = document.createElement("div"); row.className = "c-row";
    const lb = document.createElement("label"); lb.textContent = label;
    const inp = document.createElement("input"); inp.type = "text"; inp.placeholder = ph;
    inp.disabled = true; inp.value = "";
    row.appendChild(lb); row.appendChild(inp);
    c.appendChild(row);
    return inp;
  };
  const toInp = mkRow("받는사람", "불러오는 중…");
  const ccInp = mkRow("참조", "");
  (async () => {
    // 성공·실패와 상관없이 '불러오는 중…' 안내는 반드시 걷어 낸다 (응답이 비어도, 안 와도)
    let ph = "받는사람 이메일 (쉼표로 여러 명)";
    const ac = new AbortController();
    const timer = setTimeout(() => ac.abort(), 20000);     // IMAP 이 늦어도 20초 뒤엔 직접 입력할 수 있게
    try {
      const j = await (await fetch("?replyinfo=" + lastMail.uid + "&mode=" + mode, {signal: ac.signal})).json();
      if (j.ok) { toInp.value = (j.to || []).join(", "); ccInp.value = (j.cc || []).join(", "); }
      else ph = "(수신자 계산 실패 — 직접 입력)" + (j.error ? ": " + j.error : "");
    } catch (e) { ph = "(수신자 계산 실패 — 직접 입력)"; }
    clearTimeout(timer);
    toInp.placeholder = ph; ccInp.placeholder = "참조 (선택)";
    toInp.disabled = false; ccInp.disabled = false;
    if (!toInp.value) toInp.focus();
  })();
  const ta = document.createElement("textarea"); ta.placeholder = "답장 내용을 입력하세요…";
  c.appendChild(ta);
  const act = document.createElement("div"); act.className = "c-actions";
  const send = document.createElement("button"); send.type = "button"; send.className = "c-send"; send.textContent = "보내기";
  const cancel = document.createElement("button"); cancel.type = "button"; cancel.className = "c-cancel"; cancel.textContent = "취소";
  const stat = document.createElement("span"); stat.className = "c-stat";
  act.appendChild(send); act.appendChild(cancel); act.appendChild(stat);
  c.appendChild(act);
  cancel.addEventListener("click", () => c.remove());
  send.addEventListener("click", async () => {
    const text = ta.value.trim();
    if (!text) { stat.textContent = "내용을 입력하세요."; return; }
    const parse = s => s.split(/[,;]+/).map(x => x.trim()).filter(Boolean);
    const to = parse(toInp.value), cc = parse(ccInp.value);
    if (!to.length) { stat.textContent = "받는사람을 입력하세요."; return; }
    send.disabled = true; stat.textContent = "전송 중…";
    try {
      const j = await (await fetch("?reply=1", {method: "POST", headers: {"Content-Type": "application/json"},
                        body: JSON.stringify({uid: lastMail.uid, mode, text, to, cc})})).json();
      if (!j.ok) { stat.textContent = "실패: " + (j.error || "?"); send.disabled = false; return; }
      // 성공: 보낸 내용을 대화 아래에 낙관적으로 표시 (실제로는 Gmail 보낸편지함에 저장됨)
      const now = new Date();
      const p2 = n => String(n).padStart(2, "0");
      const m = { uid: 0, sender: MY_ACCT, datef: now.getFullYear() + "-" + p2(now.getMonth() + 1) + "-" + p2(now.getDate()) + " " + p2(now.getHours()) + ":" + p2(now.getMinutes()),
                  body: text, html: "", atts: [] };
      body.insertBefore(buildMsg(m, true), foot);
      c.remove();
      const done = document.createElement("div"); done.className = "c-info c-done";
      done.textContent = "✅ 전송됨 → " + j.to.join(", ") + (j.cc.length ? "  (참조: " + j.cc.join(", ") + ")" : "");
      body.insertBefore(done, foot);
    } catch (e) { stat.textContent = "전송 실패 (네트워크)"; send.disabled = false; }
  });
  body.insertBefore(c, foot.nextSibling ? foot.nextSibling : null);
  body.appendChild(c);
  ta.focus();
}
function buildThread(mail, body, j){
  body.innerHTML = "";
  const h = document.createElement("div"); h.className = "det-h";
  const subj = document.createElement("div"); subj.className = "det-subj";
  subj.textContent = (mail.dataset.subj || j.subject || "(제목 없음)")
                   + (j.mails.length > 1 ? "  (" + j.mails.length + ")" : "");
  h.appendChild(subj);
  const boxes = j.mails.map((m, i) => buildMsg(m, i === j.mails.length - 1));
  // 안읽음으로 표시 (Gmail 원본 연동): 대화 닫고 목록을 안읽음 상태로 되돌림
  const unBtn = document.createElement("button");
  unBtn.className = "exp-all"; unBtn.type = "button"; unBtn.title = "이 대화를 안읽음으로 표시 (Gmail 반영)";
  unBtn.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>안읽음';
  unBtn.addEventListener("click", async e => {
    e.stopPropagation();
    unBtn.disabled = true; unBtn.textContent = "처리 중…";
    try {
      const r = await (await fetch("?unseen=1", {method: "POST", headers: {"Content-Type": "application/json"},
                        body: JSON.stringify({uids: j.mails.map(m => m.uid).filter(Boolean)})})).json();
      if (!r.ok) { unBtn.textContent = "실패"; unBtn.disabled = false; return; }
      // 목록 행 안읽음 표시 + 대화 닫기 (다시 열면 Gmail 처럼 읽음 처리됨)
      j.mails.forEach(m => { const row = document.querySelector(`.mail[data-uid="${m.uid}"]`); if (row) row.classList.add("unread"); });
      mail.classList.add("unread");
      mail.classList.remove("open");
      delete body.dataset.loaded;                          // 다음 열람 때 새로 로드(재읽음 처리 포함)
    } catch (err) { unBtn.textContent = "실패"; unBtn.disabled = false; }
  });
  h.appendChild(unBtn);
  if (boxes.length > 1) {                                  // 대화 전체 한 번에 펼치기/접기
    const btn = document.createElement("button");
    btn.className = "exp-all"; btn.type = "button";
    const setLabel = open => { btn.innerHTML = `<span class="ic">${open ? "▲" : "▼"}</span>${open ? "모두 접기" : "모두 펼치기"}`; };
    setLabel(false);
    let allOpen = false;
    btn.addEventListener("click", e => {
      e.stopPropagation();
      allOpen = !allOpen;
      boxes.forEach(b => b._setCollapsed(!allOpen));
      setLabel(allOpen);
    });
    h.appendChild(btn);
  }
  body.appendChild(h);
  boxes.forEach(b => body.appendChild(b));
  // 답장/전체답장 대상: 대화의 마지막 '받은' 메일. 보낸편지함 사본(uid 0)은 받은편지함에 없어 답장 헤더를 못 읽는다.
  // 마지막이 내 답장이어도 상대에게 가는 건 같다(내 답장의 받는사람 = 그 사람).
  const last = [...j.mails].reverse().find(m => m.uid) || j.mails[j.mails.length - 1];
  const foot = document.createElement("div"); foot.className = "reply-bar";
  const ICONS = {                                          // Gmail 스타일 reply / reply_all
    reply: '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-10z"/></svg>',
    all:   '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M7 8V5l-7 7 7 7v-3l-4-4 4-4zm6 1V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-10z"/></svg>'
  };
  const mkBtn = (label, mode) => {
    const b = document.createElement("button"); b.type = "button"; b.className = "reply-btn";
    b.innerHTML = ICONS[mode === "all" ? "all" : "reply"] + "<span>" + label + "</span>";
    b.addEventListener("click", () => openCompose(body, foot, last, mode));
    return b;
  };
  foot.appendChild(mkBtn("답장", "reply"));
  foot.appendChild(mkBtn("전체 답장", "all"));
  body.appendChild(foot);
  // 대화에 포함된 다른 목록 행들도 읽음 표시로
  j.mails.forEach(m => { const r = document.querySelector(`.mail[data-uid="${m.uid}"]`); if (r) r.classList.remove("unread"); });
}
/* 행 이벤트 바인딩 — 초기 렌더와 부분 갱신(refreshList) 후 공용 */
function bindRows(scope){
(scope || document).querySelectorAll(".mail-h").forEach(h => h.addEventListener("click", async (e) => {
  if (e.target.closest(".rchk, .star")) return;            // 체크박스/별표 클릭은 행 열기 아님
  const mail = h.parentNode, body = mail.querySelector(".mail-b");
  mail.classList.toggle("open");
  if (!mail.classList.contains("open") || body.dataset.loaded) return;
  body.dataset.loaded = "1";
  body.innerHTML = '<div class="mail-load">불러오는 중…</div>';
  try {
    const j = await (await fetch("?thread=" + mail.dataset.uid)).json();
    if (!j.ok || !j.mails.length) { body.innerHTML = '<div class="mail-load">(본문을 가져오지 못했습니다)</div>'; delete body.dataset.loaded; return; }
    buildThread(mail, body, j);               // 열람 = 대화 전체 읽음 처리(Gmail 동일)
    loadLabels(mail, body);                    // 라벨은 본문 뒤에 비동기로
  } catch (e) { body.innerHTML = '<div class="mail-load">(본문을 가져오지 못했습니다)</div>'; delete body.dataset.loaded; }
}));
// 별표 클릭 (즉시 화면 + Gmail 반영)
(scope || document).querySelectorAll(".mail .star").forEach(st => st.addEventListener("click", async e => {
  e.stopPropagation();
  const row = st.closest(".mail"), uid = +row.dataset.uid;
  const on = !row.classList.contains("starred");
  const undo = () => { st.textContent = on ? "☆" : "★"; row.classList.toggle("starred", !on); };
  st.textContent = on ? "★" : "☆"; row.classList.toggle("starred", on);   // 낙관적 표시
  flagOp(on ? "star" : "unstar", [uid]).then(ok => {
    if (!ok) { undo(); return; }
    if (!on && STAR_FILTER) row.remove();                  // ★ 필터 중 해제 → 즉시 제거
  });
}));
// 체크박스 선택
(scope || document).querySelectorAll(".rchk").forEach(c => c.addEventListener("change", bulkRefresh));
}
bindRows();

/* ---- 라벨: 조회 + 붙이기/떼기 (Gmail 원본 반영) ---- */
const SYS_NAME = {"\\Inbox":"받은편지함","\\Sent":"보낸편지함","\\Important":"중요","\\Starred":"별표",
                  "\\Draft":"임시보관","\\Trash":"휴지통","\\Spam":"스팸"};
/* 라벨 색 (config 의 Gmail 색 매핑, 미지정은 해시 자동) — 목록 칩과 동일 규칙 */
const LABEL_COLORS = <?= json_encode(gmail_label_colors(), JSON_UNESCAPED_UNICODE) ?>;
function labelColor(name){
  const hex = LABEL_COLORS[name];
  if (hex && /^#[0-9a-f]{6}$/i.test(hex)) {
    const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
    return [hex, (0.299*r + 0.587*g + 0.114*b) > 160 ? "#202124" : "#ffffff"];
  }
  let h = 0; for (let i = 0; i < name.length; i++) h = (h*31 + name.charCodeAt(i)) >>> 0;
  return [`hsl(${h%360} 60% 92%)`, `hsl(${h%360} 45% 32%)`];
}
async function loadLabels(mail, body){
  let box = body.querySelector(".labels");
  if (!box) { box = document.createElement("div"); box.className = "labels"; body.appendChild(box); }
  box.innerHTML = '<span class="lb-t">🏷️ 라벨:</span><span class="lb-busy">불러오는 중…</span>';
  try {
    const j = await (await fetch("?labels=" + mail.dataset.uid)).json();
    if (!j.ok) { box.querySelector(".lb-busy").textContent = "(라벨 조회 실패)"; return; }
    renderLabels(mail, box, j.labels, j.all);
  } catch (e) { box.querySelector(".lb-busy").textContent = "(라벨 조회 실패)"; }
}
function renderLabels(mail, box, labels, all){
  box.innerHTML = '<span class="lb-t">🏷️ 라벨:</span>';
  const has = new Set(labels.map(l => l.name));
  labels.forEach(l => {
    const c = document.createElement("span");
    c.className = "chip" + (l.system ? " sys" : "");
    c.textContent = l.system ? (SYS_NAME[l.name] || l.name) : l.name;
    if (!l.system) {                                       // 사용자 라벨: Gmail 색 매핑 적용 + 제거 가능
      const [bg, fg] = labelColor(l.name);
      c.style.background = bg; c.style.color = fg;
      const x = document.createElement("button"); x.textContent = "✕"; x.title = "라벨 제거";
      x.style.color = fg;
      x.addEventListener("click", () => setLabel(mail, box, l.name, false, all));
      c.appendChild(x);
    }
    box.appendChild(c);
  });
  const addable = (all || []).filter(n => !has.has(n));
  if (addable.length) {
    const sel = document.createElement("select");
    sel.innerHTML = '<option value="">＋ 라벨 추가</option>' + addable.map(n => `<option>${n.replace(/</g,"&lt;")}</option>`).join("");
    sel.addEventListener("change", () => { if (sel.value) setLabel(mail, box, sel.value, true, all); });
    box.appendChild(sel);
  }
}
async function setLabel(mail, box, label, add, all){
  const busy = document.createElement("span"); busy.className = "lb-busy"; busy.textContent = "적용 중…";
  box.appendChild(busy);
  try {
    const j = await (await fetch("?label_set=1", {method:"POST", headers:{"Content-Type":"application/json"},
                     body: JSON.stringify({uid: +mail.dataset.uid, label, add})})).json();
    if (j.ok) renderLabels(mail, box, j.labels, all);
    else { busy.textContent = "(실패: " + (j.error || "?") + ")"; }
  } catch (e) { busy.textContent = "(실패)"; }
}

/* ---- 별표 토글: 클릭 즉시 화면+Gmail 반영 (새로고침 없음) ---- */
const STAR_FILTER = <?= $starF ? 'true' : 'false' ?>;
async function flagOp(act, uids){
  try {
    const j = await (await fetch("?flag=" + act, {method: "POST", headers: {"Content-Type": "application/json"},
                      body: JSON.stringify({uids})})).json();
    return !!j.ok;
  } catch (e) { return false; }
}
/* 행 하나에 플래그 결과를 DOM 에 바로 반영. 별표만 필터에서 해제되면 행 제거 */
function applyFlag(row, act){
  const st = row.querySelector(".star");
  if (act === "seen")   row.classList.remove("unread");
  if (act === "unseen") row.classList.add("unread");
  if (act === "star")   { row.classList.add("starred");    if (st) st.textContent = "★"; }
  if (act === "unstar") {
    row.classList.remove("starred");
    if (st) st.textContent = "☆";
    if (STAR_FILTER) row.remove();                         // ★ 필터 중 해제 → 목록에서 즉시 제거
  }
}
/* (별표 클릭 바인딩은 bindRows() 안으로 이동 — 부분 갱신 후 재바인딩되도록) */

/* ---- 체크박스 다중 선택 + 일괄 처리 바 (읽음/안읽음/별표) ---- */
document.body.insertAdjacentHTML("beforeend", `
<div id="bulkbar">
  <b id="bulk-n"></b>개 선택
  <button data-act="seen">읽음</button>
  <button data-act="unseen">안읽음</button>
  <button data-act="star">★ 별표</button>
  <button data-act="unstar">☆ 해제</button>
  <button id="bulk-x">취소</button>
</div>`);
const bulkbar = document.getElementById("bulkbar");
function selUids(){ return [...document.querySelectorAll(".rchk:checked")].map(c => +c.closest(".mail").dataset.uid); }
function bulkRefresh(){
  const n = selUids().length;
  document.getElementById("bulk-n").textContent = n;
  bulkbar.classList.toggle("show", n > 0);
}
/* (체크박스 바인딩은 bindRows() 안으로 이동) */
document.getElementById("bulk-x").addEventListener("click", () => {
  document.querySelectorAll(".rchk:checked").forEach(c => c.checked = false);
  bulkRefresh();
});
bulkbar.querySelectorAll("button[data-act]").forEach(b => b.addEventListener("click", async () => {
  const uids = selUids();
  if (!uids.length) return;
  b.disabled = true;
  const ok = await flagOp(b.dataset.act, uids);
  b.disabled = false;
  if (!ok) { const t = b.textContent; b.textContent = "실패"; setTimeout(() => b.textContent = t, 1500); return; }
  // 새로고침 없이 선택된 행들에 바로 반영
  document.querySelectorAll(".rchk:checked").forEach(c => {
    const row = c.closest(".mail");
    c.checked = false;
    applyFlag(row, b.dataset.act);
  });
  bulkRefresh();
}));

/* ---- 이미지 라이트박스 모달: 본문 이미지·이미지 첨부 확대 (휠 줌/드래그 팬/더블클릭) ---- */
document.body.insertAdjacentHTML("beforeend", `
<div id="glb">
  <button id="glb-x" title="닫기 (Esc)">✕</button>
  <img id="glb-img" alt="">
  <div id="glb-bar">
    <button data-z="out" title="축소">−</button><span id="glb-zv">100%</span><button data-z="in" title="확대">＋</button>
    <button data-z="reset" title="원래 크기">⤢</button>
    <a id="glb-dl" href="#" download title="저장">⬇ 저장</a>
  </div>
</div>`);
const glb = document.getElementById("glb"), glbImg = document.getElementById("glb-img");
let gz = 1, gx = 0, gy = 0, gdrag = null;
function glbApply(){
  glbImg.style.transform = `translate(${gx}px,${gy}px) scale(${gz})`;
  document.getElementById("glb-zv").textContent = Math.round(gz * 100) + "%";
  glbImg.classList.toggle("zoomed", gz > 1);
}
function glbOpen(src, name){
  gz = 1; gx = 0; gy = 0; glbApply();
  glbImg.src = src;
  const dl = document.getElementById("glb-dl");
  dl.href = src.startsWith("?") ? src + "&dl=1" : src;     // 첨부 URL 이면 다운로드 모드로
  dl.setAttribute("download", name || "image");
  glb.classList.add("open");
}
function glbClose(){ glb.classList.remove("open"); glbImg.removeAttribute("src"); }
glb.addEventListener("click", e => { if (e.target === glb) glbClose(); });
document.getElementById("glb-x").addEventListener("click", glbClose);
document.addEventListener("keydown", e => { if (e.key === "Escape") glbClose(); });
document.querySelectorAll("#glb-bar button[data-z]").forEach(b => b.addEventListener("click", () => {
  const m = b.dataset.z;
  if (m === "reset") { gz = 1; } else gz = Math.min(8, Math.max(1, gz * (m === "in" ? 1.25 : 0.8)));
  if (gz === 1) { gx = 0; gy = 0; }
  glbApply();
}));
glb.addEventListener("wheel", e => {                       // 휠로 확대/축소
  e.preventDefault();
  gz = Math.min(8, Math.max(1, gz * (e.deltaY < 0 ? 1.15 : 0.87)));
  if (gz === 1) { gx = 0; gy = 0; }
  glbApply();
}, {passive: false});
glbImg.addEventListener("mousedown", e => { if (gz <= 1) return; e.preventDefault(); gdrag = {x: e.clientX - gx, y: e.clientY - gy}; });
window.addEventListener("mousemove", e => { if (gdrag) { gx = e.clientX - gdrag.x; gy = e.clientY - gdrag.y; glbApply(); } });
window.addEventListener("mouseup", () => gdrag = null);
glbImg.addEventListener("dblclick", () => { gz = gz > 1 ? 1 : 2; if (gz === 1) { gx = 0; gy = 0; } glbApply(); });

/* ---- 백그라운드 동기화: 페이지는 DB 로 즉시 뜨고, 뒤에서 최신화 ---- */
const bar = document.getElementById("syncbar");
const hadRows = <?= $rows ? 'true' : 'false' ?>;

/* ---- 목록 부분 갱신: 전체 새로고침 없이 현재 필터/페이지의 목록만 교체 (스크롤 유지) ---- */
async function refreshList(){
  try {
    const qs = location.search.replace(/^\?/, "");
    const j = await (await fetch("?partial=1" + (qs ? "&" + qs : ""), {cache: "no-store"})).json();
    if (!j.ok) return false;
    const ml = document.querySelector(".maillist");
    if (!ml || j.empty) { location.reload(); return true; }   // 목록 구조가 달라지는 경우만 전체 리로드
    ml.innerHTML = j.list;
    document.querySelectorAll(".pager").forEach(p => p.outerHTML = j.pager);
    const meta = document.querySelector("header .meta");
    if (meta) meta.textContent = j.meta;
    bindRows(ml);                                             // 새 행들에 이벤트 재바인딩
    bulkRefresh();
    bar.classList.remove("show");
    return true;
  } catch (e) { return false; }
}
const syncBtn = document.getElementById("syncbtn");
let syncing = false;
async function sync(manual){
  if (syncing) return;                        // 자동/수동 중복 실행 방지
  syncing = true;
  if (manual) { syncBtn.disabled = true; syncBtn.textContent = "⏳ 동기화 중…"; }
  try {
    const j = await (await fetch("gmail_sync.php", {cache:"no-store"})).json();
    if (!j.ok) {
      bar.textContent = "동기화 오류: " + (j.error || "?"); bar.classList.add("show");
      return;
    }
    if (j.backfill_remaining > 0) {           // 초기 수집: 끝날 때까지 반복
      bar.textContent = `📥 메일 수집 중… (DB ${j.db_total.toLocaleString()}건, 남은 ${j.backfill_remaining.toLocaleString()}건)`;
      bar.classList.add("show");
      if (!hadRows && j.db_total > 0) { location.reload(); return; }   // 첫 화면이 비어있었으면 바로 표시
      syncing = false;
      sync(manual);                            // 다음 청크
      return;
    }
    if (j.added > 0 || j.flag_updated > 0 || (j.deleted || 0) > 0) {
      if (manual) { if (!(await refreshList())) location.reload(); return; }   // 수동: 목록만 즉시 교체
      const busy = document.querySelector(".mail.open") || document.querySelector(".compose");
      if (!busy && await refreshList()) { /* 부분 갱신 완료 */ }
      else {
        bar.innerHTML = `✉️ 변경 감지 (새 메일 ${j.added}건, 읽음변경 ${j.flag_updated}건, 삭제 ${j.deleted || 0}건) — <a href="javascript:location.reload()">새로고침</a>`;
        bar.classList.add("show");
      }
    } else {
      bar.classList.remove("show");
      if (manual) { syncBtn.textContent = "✅ 변경 없음"; setTimeout(() => { syncBtn.textContent = "🔄 동기화"; syncBtn.disabled = false; }, 1500); return; }
    }
  } catch (e) {
    if (manual) bar.textContent = "동기화 실패 (네트워크)", bar.classList.add("show");
  } finally {
    syncing = false;
    if (manual && syncBtn.disabled && syncBtn.textContent === "⏳ 동기화 중…") { syncBtn.textContent = "🔄 동기화"; syncBtn.disabled = false; }
  }
}
syncBtn.addEventListener("click", () => sync(true));
sync();

/* ---- 실시간 반영: 5초마다 DB 변경 마커만 확인(초경량) ----
   gmail_watch.php(IMAP IDLE 데몬)가 변경을 즉시 DB 에 반영 → 여기서 감지해 화면 갱신.
   대화를 보고 있거나 답장 작성 중이면 새로고침 대신 알림 바만. */
const CHANGED0 = <?= (int)gmeta_get('gmail_changed_at', 0) ?>;
let lastChanged = CHANGED0;
setInterval(async () => {
  if (syncing) return;
  try {
    const j = await (await fetch("?ping=1", {cache: "no-store"})).json();
    if (!j.ok) return;
    if (j.changed > lastChanged) {
      lastChanged = j.changed;
      const busy = document.querySelector(".mail.open") || document.querySelector(".compose");
      if (!busy) {                                         // 실시간 푸시 → 목록만 조용히 교체 (스크롤 유지)
        if (!(await refreshList())) location.reload();
        return;
      }
      bar.innerHTML = `✉️ 새 변경사항 도착 — <a href="javascript:location.reload()">새로고침</a>`;
      bar.classList.add("show");
    }
    // 감시 데몬이 안 돌고 있으면(마지막 동기화가 오래됨) 브라우저가 3분마다 대신 동기화
    if (j.sync_age > 180) sync();
  } catch (e) { /* 다음 ping 에서 재시도 */ }
}, 5000);
</script>
</body></html>

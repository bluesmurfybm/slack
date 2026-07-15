<?php
/**
 * Gmail 뷰어 (DB 캐시 방식).
 *  - 목록: gmail_mails 테이블에서 즉시 조회(빠름). IMAP 은 목록에 관여하지 않음.
 *  - 동기화: 페이지 로드 후 JS 가 gmail_sync.php 를 백그라운드 호출(초기 백필은 끝날 때까지 반복).
 *  - 본문: 첫 열람 때만 IMAP 에서 받아 DB 에 저장 → 이후 열람은 DB 에서 즉시.
 *  - 열람 시 Gmail 원본도 읽음(\Seen) 처리 + DB 반영.
 */
require_once __DIR__ . '/auth.php';
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

/** 대화의 보낸편지함 사본 조회 (INBOX 사본과 X-GM-MSGID 로 중복 제거) — 표시 전용, DB 저장 안 함.
 *  동기화는 INBOX 만 읽으므로 내가 보낸/전달한 메일은 여기서 실시간으로 합쳐야 Gmail 대화와 같아짐.
 *  uid=0 으로 반환 → 뷰어 JS 가 첨부 링크/읽음 등 INBOX uid 기반 동작을 건너뜀. */
function gmail_sent_in_thread($thrid, array $inboxMails) {
    $raw = new GmailRaw();
    $inboxSet = implode(',', array_map(fn($m) => (int)$m['uid'], $inboxMails));
    $inboxIds = $raw->msgidMap($inboxSet);                 // 현재 선택함(INBOX) 기준 — EXAMINE 전에 조회
    $sentMb = $raw->specialFolder('\\Sent');
    $uids = $sentMb ? $raw->searchThreadIn($sentMb, $thrid) : [];
    $ids = $uids ? $raw->msgidMap(implode(',', $uids)) : [];
    $raw->close();
    $dup = array_flip(array_map('strval', $inboxIds));
    $need = array_values(array_filter($uids, fn($u) => !isset($dup[(string)($ids[$u] ?? '')])));
    if (!$need) return [];
    $g = gmail_cfg();
    $im = @imap_open($g['host'] . $sentMb, $g['user'], str_replace(' ', '', (string)$g['pass']), 0, 1);
    if (!$im) return [];
    $out = [];
    foreach (array_slice($need, 0, 30) as $u) {            // 안전 상한 (비정상적으로 긴 대화 보호)
        $msgno = imap_msgno($im, $u);
        if ($msgno < 1) continue;
        $ov = imap_fetch_overview($im, (string)$u, FT_UID)[0] ?? null;
        [$plain, $html, $atts] = gmail_extract($im, $msgno);
        $disp = $ov ? gmail_dec_header($ov->from ?? '') : (string)($g['user'] ?? '');
        // 이름 없이 주소만 있는 옛 발송분 → config 의 표시 이름으로 보정 (Gmail 처럼 이름 표시)
        if ($disp !== '' && strpos($disp, '<') === false && trim((string)($g['name'] ?? '')) !== '') {
            $disp = $g['name'] . ' <' . $disp . '>';
        }
        $out[] = ['uid' => 0,
            'subject' => $ov ? gmail_dec_header($ov->subject ?? '') : '',
            'sender' => $disp,
            'udate' => (int)($ov->udate ?? 0), 'seen' => 1,
            'body' => gmail_body_text($plain, $html),
            'body_html' => ($html !== '') ? gmail_sanitize_html($html) : '',
            'atts' => json_encode($atts, JSON_UNESCAPED_UNICODE)];
    }
    imap_close($im);
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

/** 답장 대상 계산: 원본 헤더에서 to/cc/제목/스레드 연결 헤더 도출 */
function reply_targets($uid, $mode) {
    [$im, $err] = gmail_open();
    if (!$im) throw new RuntimeException($err);
    $msgno = imap_msgno($im, $uid);
    if ($msgno < 1) { imap_close($im); throw new RuntimeException('원본 메일을 찾을 수 없습니다.'); }
    $h = imap_headerinfo($im, $msgno);
    $rawH = imap_fetchheader($im, $msgno);
    imap_close($im);
    $me = gmail_owner();
    $collect = function ($arr) use ($me) {                 // 주소 객체 → 소문자 이메일(본인 제외)
        $out = [];
        foreach ((array)$arr as $a) {
            if (empty($a->mailbox) || empty($a->host)) continue;
            $e = strtolower($a->mailbox . '@' . $a->host);
            if ($e !== $me) $out[] = $e;
        }
        return array_values(array_unique($out));
    };
    $to = $collect(!empty($h->reply_to) ? $h->reply_to : ($h->from ?? []));   // 답장: 보낸사람(reply-to 우선)
    $cc = [];
    if ($mode === 'all') {                                 // 전체답장: 원본 To 합류, Cc 유지
        $to = array_values(array_unique(array_merge($to, $collect($h->to ?? []))));
        $cc = array_values(array_diff($collect($h->cc ?? []), $to));
    }
    if (!$to && $cc) { $to = $cc; $cc = []; }
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

/* ---------- 연락처 자동완성: ?contacts=<검색어> — 받은 메일 보낸사람 풀에서 이름/주소 매칭 (최근 순) ---------- */
if (isset($_GET['contacts'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)$_GET['contacts']);
    $out = [];
    if ($q !== '' && mb_strlen($q) <= 60) {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $st = $pdo->prepare("SELECT sender, MAX(udate) mu FROM gmail_mails
                             WHERE account = ? AND sender LIKE ? ESCAPE '\\\\'
                             GROUP BY sender ORDER BY mu DESC LIMIT 40");
        $st->execute([gmail_owner(), $like]);
        $seen = [];                                        // email 기준 dedupe — 이름 있는 변형 우선
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (string)$r['sender'];
            if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $s, $m)) { $name = trim($m[1], " \t\"'"); $email = strtolower(trim($m[2])); }
            else { $name = ''; $email = strtolower(trim($s)); }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (isset($seen[$email])) {
                if ($seen[$email]['name'] === '' && $name !== '') $seen[$email]['name'] = $name;
                continue;
            }
            $seen[$email] = ['name' => $name, 'email' => $email];
        }
        $out = array_slice(array_values($seen), 0, 8);
    }
    echo json_encode(['ok' => true, 'contacts' => $out], JSON_UNESCAPED_UNICODE);
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
        // 원문 인용 (Gmail 형식: 텍스트 파트는 > 인용, HTML 파트는 blockquote + 원문 HTML 그대로)
        $me = gmail_owner();
        $st = $pdo->prepare("SELECT sender, udate, body, body_html FROM gmail_mails WHERE account = ? AND uid = ?");
        $st->execute([$me, $uid]);
        $orig = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $quote = '';
        $attr = $orig ? date('Y년 n월 j일 H:i', (int)$orig['udate']) . ', ' . $orig['sender'] . ' 님이 작성:' : '';
        if (!empty($orig['body'])) {
            $quote = "\n\n" . $attr . "\n"
                   . '> ' . str_replace("\n", "\n> ", trim($orig['body']));
        }
        // HTML 본문: 입력 텍스트 + 원문 HTML 인용. 원문의 data: 인라인 이미지는 cid 첨부로 변환 (Gmail 이 data: 이미지를 차단하므로)
        $qhtml = trim((string)($orig['body_html'] ?? ''));
        if ($qhtml === '' && !empty($orig['body'])) $qhtml = nl2br(htmlspecialchars(trim($orig['body']), ENT_QUOTES, 'UTF-8'));
        $inlines = [];
        if ($qhtml !== '') {
            $qhtml = preg_replace_callback('/src\s*=\s*(["\'])data:(image\/[a-z0-9.+-]+);base64,([A-Za-z0-9+\/=\s]+?)\1/i',
                function ($m) use (&$inlines) {
                    $bytes = base64_decode(preg_replace('/\s+/', '', $m[3]), true);
                    if ($bytes === false) return $m[0];
                    $cid = 'inl' . (count($inlines) + 1) . '.' . substr(md5($bytes), 0, 12) . '@slackapi';
                    $inlines[] = ['cid' => $cid, 'mime' => strtolower($m[2]), 'data' => $bytes];
                    return 'src="cid:' . $cid . '"';
                }, $qhtml);
        }
        $html = '<div>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>'
              . ($qhtml !== ''
                 ? '<br><div class="gmail_quote">' . htmlspecialchars($attr, ENT_QUOTES, 'UTF-8')
                   . '<br><blockquote class="gmail_quote" style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">'
                   . $qhtml . '</blockquote></div>'
                 : '');
        // From 표시 이름 = 로그인한 사용자의 Slack 실명 (공용 Gmail 계정이라도 보낸 사람은 본인 이름으로)
        $fromName = trim((string)(current_user()['name'] ?? ''));
        gmail_smtp_send($to, $cc, $subject, $text . $quote, $extra, $html, $inlines, $fromName);
        echo json_encode(['ok' => true, 'to' => $to, 'cc' => $cc], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---------- 실시간 ping: ?ping=1 — DB 조회만(밀리초). 감시 데몬이 갱신한 변경 마커 확인 ---------- */
if (isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'changed' => (int)gmeta_get('gmail_changed_at', 0),
        'sync_age' => time() - (int)strtotime((string)gmeta_get('gmail_last_sync', '2000-01-01')),
        'watch_age' => time() - (int)gmeta_get('gmail_watch_beat', 0),   // IDLE 데몬 생존 신호 경과
    ]);
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
        // 내가 보낸/전달한 메일(보낸편지함 사본)도 대화에 포함 — Gmail 대화 화면과 동일
        if ($thrid) {
            try { $mails = array_merge($mails, gmail_sent_in_thread($thrid, $mails)); } catch (Throwable $e) { /* 실패해도 받은 메일은 표시 */ }
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
         : ((int)$nRaw > 0 ? min(2000, (int)$nRaw) : max(1, (int)($g['limit'] ?: 30)));
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
$order = $unreadFirst ? "(unread > 0) DESC, last_udate DESC" : "last_udate DESC";
$pdo->exec("SET SESSION group_concat_max_len = 8192");     // 참여자 목록 잘림 방지
/* 목록 조회 2단계 (전체 그룹 풀집계 + 문자열키 조인은 44k 행에서 수 초 → 페이지 분량만 집계):
   ① 정렬 키만 가볍게 그룹핑해 이번 페이지의 대화 키 N개 확보
   ② 그 대화들만 풀 집계 + 대표(첫 메일)는 mk 에서 uid 를 풀어 PK 로 직조회 */
$rows = [];
$q = $pdo->prepare("SELECT $G AS g, MAX(udate) AS last_udate, SUM(seen = 0) AS unread
                    FROM gmail_mails WHERE account = ?$flt
                    GROUP BY g ORDER BY $order
                    LIMIT " . (int)$perPage . " OFFSET " . (int)($page * $perPage));
$q->execute(array_merge([$acct], $fp));
$gs = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'g');
if ($gs) {
    $ph = implode(',', array_fill(0, count($gs), '?'));
    // ② 해당 대화들만 집계 (thrid 인덱스 + 싱글턴은 uid PK) — 대표 = 처음 보낸 원본 메일, 날짜/정렬 = 최신 활동 (Gmail 동일)
    $st = $pdo->prepare("
        SELECT x.g, x.cnt + COALESCE(sc.scnt, 0) AS cnt,   -- 대화 수 = 받은 메일 + 내가 보낸 메일 (Gmail 동일)
               x.unread, x.senders, x.has_att, x.starred, x.last_udate AS udate, x.mk,
               CASE WHEN x.unread > 0 THEN 0 ELSE 1 END AS seen
        FROM (
            SELECT $G AS g, COUNT(*) AS cnt, SUM(seen = 0) AS unread, MAX(udate) AS last_udate,
                   MAX(CASE WHEN atts IS NOT NULL AND atts <> '[]' THEN 1 ELSE 0 END) AS has_att,
                   MAX(flagged) AS starred,
                   GROUP_CONCAT(DISTINCT sender ORDER BY udate SEPARATOR '||') AS senders,
                   MIN(CONCAT(LPAD(udate, 10, '0'), LPAD(uid, 10, '0'))) AS mk   -- 대화의 첫 메일
            FROM gmail_mails
            WHERE account = ?$flt AND (thrid IN ($ph) OR uid IN ($ph))
            GROUP BY g HAVING g IN ($ph)
        ) x
        LEFT JOIN (SELECT thrid, COUNT(*) AS scnt FROM gmail_sent WHERE account = ? AND thrid IN ($ph) GROUP BY thrid) sc
          ON sc.thrid = x.g");
    $st->execute(array_merge([$acct], $fp, $gs, $gs, $gs, [$acct], $gs));
    $agg = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $agg[$r['g']] = $r;
    // 대표 메일: mk = LPAD(udate)·LPAD(uid) → 뒤 10자리가 uid (PK 직조회)
    $repUids = array_values(array_unique(array_map(fn($r) => (int)substr($r['mk'], 10), $agg)));
    $reps = [];
    if ($repUids) {
        $ph2 = implode(',', array_fill(0, count($repUids), '?'));
        $r2 = $pdo->prepare("SELECT uid, subject, sender, body, labels FROM gmail_mails WHERE account = ? AND uid IN ($ph2)");
        $r2->execute(array_merge([$acct], $repUids));
        foreach ($r2->fetchAll(PDO::FETCH_ASSOC) as $m) $reps[(int)$m['uid']] = $m;
    }
    foreach ($gs as $g) {                                  // ①의 정렬 순서 유지
        if (!isset($agg[$g])) continue;
        $a = $agg[$g];
        $m = $reps[(int)substr($a['mk'], 10)] ?? null;
        if (!$m) continue;
        $rows[] = ['uid' => $m['uid'], 'subject' => $m['subject'], 'sender' => $m['sender'],
                   'body' => $m['body'], 'labels' => $m['labels'], 'cnt' => $a['cnt'],
                   'unread' => $a['unread'], 'senders' => $a['senders'], 'has_att' => $a['has_att'],
                   'starred' => $a['starred'], 'udate' => $a['udate'], 'seen' => $a['seen']];
    }
}

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
    $names = [];
    foreach (explode('||', (string)$senders) as $s) {
        $n = sender_name($s);
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
        echo '<span class="lchip" style="--c:' . e($bg) . ';--t:' . e($fg) . '">' . e($ch) . '</span>'; } ?><?= e(subject_base($m['subject'])) ?><?= $snip !== '' ? '<span class="snip"> - ' . e($snip) . '</span>' : '' ?></span>
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
<html lang="ko"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gmail 가져오기 테스트</title>
<script>(function(){var t=localStorage.getItem("ui_theme");if(!t&&matchMedia("(prefers-color-scheme: dark)").matches)t="dark";if(t)document.documentElement.classList.add(t);})();</script>
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
  /* 목록 라벨 칩 (--c=배경, --t=글자 — 다크 모드에서 color-mix 로 자동 변환) */
  .lchip { display:inline-block; font-size:11px; border-radius:4px; padding:1px 7px; margin-right:6px;
           vertical-align:1px; font-weight:600; white-space:nowrap;
           background:var(--c,#eee); color:var(--t,#333); }
  html.dark .lchip { background:color-mix(in srgb, var(--c,#8ab4f8) 35%, #16181c);
                     color:color-mix(in srgb, var(--t,#e8eaed) 30%, #fff);
                     box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--c,#8ab4f8) 45%, #16181c); }
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
  /* 받는사람/참조 연락처 자동완성 (Gmail 식) */
  .addr-menu { position:fixed; z-index:10005; max-width:420px; background:var(--bg); border:1px solid var(--line);
               border-radius:10px; padding:4px; box-shadow:0 6px 22px rgba(0,0,0,.18); }
  .addr-menu .ai { display:flex; align-items:center; gap:8px; padding:7px 11px; border-radius:7px; font-size:13px;
                   cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .addr-menu .ai b { font-weight:600; }
  .addr-menu .ai .ae { color:var(--muted); font-size:12px; }
  .addr-menu .ai.on, .addr-menu .ai:hover { background:var(--info-bg, #e6f1fb); }
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
  /* 데몬 상태 표시등 */
  #daemonDot { font-size:11px; vertical-align:2px; color:#9aa0a6; cursor:default; }
  /* ===== 다크 모드 (🌓 토글 / OS 설정 따름) ===== */
  html.dark { --line:#3a3d42; --hint:#9aa0a6; --bg2:#1b1e22; --read:#22262b; --ink:#e8eaed; --sub:#9aa0a6; }
  html.dark body { background:var(--bg2); color:var(--ink); }
  html.dark header { background:#202124; border-color:#3a3d42; box-shadow:0 1px 2px rgba(0,0,0,.6); }
  html.dark .hsearch input { background:#303134; border-color:transparent; color:#e8eaed; }
  html.dark .hsearch input:focus { background:#3a3d42; border-color:#5f6368; box-shadow:none; }
  html.dark header .toggle { background:#303134; color:#bdc1c6; border-color:#3c4043; }
  html.dark header .toggle.on { background:#0c2740; color:#8ab4f8; border-color:#8ab4f8; }
  html.dark #syncbar { background:#3a3115; border-color:#5c4d1e; color:#fdd663; }
  html.dark .maillist { background:#202124; border-color:#3a3d42; box-shadow:none; }
  html.dark .mail { border-color:#2c2f33; }
  html.dark .mail-h { background:var(--read); }
  html.dark .mail.unread .mail-h, html.dark .mail.open .mail-h { background:#202124; }
  html.dark .mail-h:hover { box-shadow:inset 1px 0 0 #3c4043, inset -1px 0 0 #3c4043, 0 1px 3px rgba(0,0,0,.6); }
  html.dark .mail.unread .sender, html.dark .mail.unread .subj, html.dark .mail.unread .date { color:#e8eaed; }
  html.dark .mail.open { border-color:#3a3d42; box-shadow:0 2px 10px rgba(0,0,0,.5); }
  html.dark .sect { background:#26282c; border-color:#2c2f33; }
  html.dark .mail-b { background:#202124; border-color:#3a3d42; }
  html.dark .det-subj, html.dark .det-who b, html.dark .mail-text { color:#e8eaed; }
  html.dark .msg, html.dark .atts, html.dark .labels { border-color:#2c2f33; }
  html.dark .att-pill { background:#303134; color:#bdc1c6; border-color:#3c4043; }
  html.dark a.att-pill:hover { background:#0c2740; }
  html.dark .chip.sys { background:#3c4043; color:#9aa0a6; }
  html.dark .labels select { background:#303134; color:#bdc1c6; border-color:#3c4043; }
  html.dark .q-toggle { background:#3c4043; border-color:#5f6368; color:#bdc1c6; }
  html.dark .pager a, html.dark .pager span.cur { background:#303134; border-color:#3c4043; color:#8ab4f8; }
  html.dark .pager .cur { background:#0c2740; border-color:#8ab4f8; color:#e8eaed; }
  html.dark .pager .disabled { color:#5f6368; background:transparent; border-color:#2c2f33; }
  html.dark .pager input { background:#303134; color:#e8eaed; border-color:#3c4043; }
  html.dark .compose textarea, html.dark .compose .c-row input { background:#303134; color:#e8eaed; border-color:#3c4043; }
  html.dark .compose .c-row input:disabled { background:#26282c; color:#5f6368; }
  html.dark .c-cancel, html.dark .reply-btn, html.dark .exp-all { background:#303134; color:#bdc1c6; border-color:#3c4043; }
  html.dark .reply-btn:hover, html.dark .exp-all:hover { background:#0c2740; color:#8ab4f8; border-color:#8ab4f8; }
  html.dark .mail-load, html.dark .empty, html.dark .snippet { color:#9aa0a6; }
  html.dark .err { background:#3a1d1c; border-color:#5c2b28; color:#f28b82; }
  html.dark .mail-frame { background:#fff; }   /* 메일 원문(iframe)은 가독성 위해 밝게 유지 */
  /* 첨부/라벨 */
  .atts { display:flex; gap:8px; flex-wrap:wrap; padding:12px 20px; border-top:1px solid #f1f3f4; }
  .att-pill { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--line); border-radius:16px;
              padding:5px 12px; font-size:12px; color:#3c4043; background:#fff; }
  .att-pill .pill-ic { display:inline-flex; align-items:center; color:#5f6368; }
  .labels { display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:12px; padding:10px 20px 14px; border-top:1px solid #f1f3f4; margin:0; }
  .labels .lb-t { color:var(--hint); }
  .chip { display:inline-flex; align-items:center; gap:4px; border-radius:12px; padding:2px 9px;
          background:var(--c,#e8f0fe); color:var(--t,#1a56b8); }
  html.dark .chip { background:color-mix(in srgb, var(--c,#8ab4f8) 30%, #16181c);
                    color:color-mix(in srgb, var(--t,#8ab4f8) 35%, #fff); }
  .chip.sys { background:#f1f3f4; color:#5f6368; }
  html.dark .chip.sys { background:#3c4043; color:#bdc1c6; }
  .chip button { border:none; background:none; color:inherit; cursor:pointer; font-size:13px; line-height:1; padding:0; }
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
</style></head><body>
<?php
// 토글 링크 생성: 현재 필터 유지 + 일부만 변경 (p 는 1 로 리셋)
$mkUrl = function (array $over) use ($keepArr) {
    $qs = array_filter(array_merge($keepArr, $over), fn($v) => $v !== null && $v !== '');
    return '?p=1' . ($qs ? '&' . http_build_query($qs) : '');
};
?>
<header>
  <h1>📧 Gmail <span id="daemonDot" title="실시간 감시 상태 확인 중…">●</span></h1>
  <form class="hsearch" method="get">
    <?php foreach ($keepArr as $k => $v) if ($k !== 'q') echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">'; ?>
    <input type="search" name="q" value="<?= e($qF) ?>" placeholder="메일 검색 (제목·보낸사람·본문)">
    <?php if ($qF !== ''): ?><a class="hclear" href="<?= e($mkUrl(['q' => null])) ?>" title="검색 지우기">✕</a><?php endif; ?>
  </form>
  <span class="meta">
    <?= number_format($count) ?>개 중 <?= number_format($from) ?>–<?= number_format($to) ?>
    · 동기화 <?= e(substr((string)$lastSync, 11, 5) ?: $lastSync) ?>
  </span>
  <select id="psel" class="toggle<?= $nRaw !== '' ? ' on' : '' ?>" title="페이지당 표시 개수">
    <option value="">개수: 기본(<?= (int)($g['limit'] ?: 30) ?>)</option>
    <?php foreach ([50, 100, 200, 300, 400, 500, 1000] as $nOpt): ?>
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
  <button id="themeBtn" class="toggle" type="button" title="다크/라이트 전환">🌓</button>
</header>
<script>
const BASE_QS = <?= json_encode(($x = array_filter($keepArr, fn($k) => $k !== 'label', ARRAY_FILTER_USE_KEY)) ? '&' . http_build_query($x) : '') ?>;
document.getElementById("labelsel").addEventListener("change", function(){
  location.href = "?p=1" + BASE_QS + (this.value ? "&label=" + encodeURIComponent(this.value) : "");
});
document.getElementById("themeBtn").addEventListener("click", function(){
  const el = document.documentElement;
  const isDark = el.classList.contains("dark")
    || (!el.classList.contains("light") && matchMedia("(prefers-color-scheme: dark)").matches);
  el.classList.remove("dark","light");
  el.classList.add(isDark ? "light" : "dark");
  localStorage.setItem("ui_theme", isDark ? "light" : "dark");
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
  <?= pager_html($pg) ?>
  <div class="maillist">
  <?= rows_html($rows, $unreadFirst, $unreadCnt) ?>
  </div>
  <?= pager_html($pg) ?>
<?php endif; ?>
</div>
<script>
/* ---- 본문 lazy 로드 (DB 캐시 → 최초 1회만 IMAP) — Gmail 식 읽기 화면 ---- */
function avColor(s){ let h=0; for(let i=0;i<s.length;i++) h=(h*31+s.charCodeAt(i))>>>0; return `hsl(${h%360} 55% 55%)`; }
function senderParts(full){
  const m = full.match(/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/);
  return m ? {name:(m[1].trim()||m[2]), email:m[2]} : {name:full, email:""};
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
/* 받는사람/참조 자동완성 (Gmail 식): 마지막 쉼표 뒤 토큰으로 연락처 검색 → 선택 시 주소 삽입 */
function bindAddrAuto(inp){
  let menu = null, items = [], active = 0, timer = null;
  const close = () => { if (menu) { menu.remove(); menu = null; } items = []; };
  const token = () => { const p = inp.value.split(","); return p[p.length - 1].trim(); };
  const pick = i => {
    const c = items[i]; if (!c) return;
    const p = inp.value.split(",");
    p[p.length - 1] = " " + c.email;
    inp.value = p.join(",").replace(/^\s+/, "") + ", ";
    close(); inp.focus();
  };
  const show = list => {
    close();
    if (!list.length) return;
    items = list; active = 0;
    menu = document.createElement("div"); menu.className = "addr-menu";
    list.forEach((c, i) => {
      const d = document.createElement("div"); d.className = "ai" + (i === 0 ? " on" : "");
      d.innerHTML = (c.name ? '<b></b> ' : '') + '<span class="ae"></span>';
      if (c.name) d.querySelector("b").textContent = c.name;
      d.querySelector(".ae").textContent = c.email;
      d.addEventListener("mousedown", e => { e.preventDefault(); pick(i); });
      menu.appendChild(d);
    });
    const r = inp.getBoundingClientRect();
    menu.style.left = r.left + "px"; menu.style.top = (r.bottom + 4) + "px"; menu.style.minWidth = r.width + "px";
    document.body.appendChild(menu);
  };
  inp.addEventListener("input", () => {
    clearTimeout(timer);
    const q = token();
    if (q.length < 1) { close(); return; }
    timer = setTimeout(async () => {
      try {
        const j = await (await fetch("?contacts=" + encodeURIComponent(q))).json();
        if (document.activeElement === inp && token() === q) show(j.contacts || []);
      } catch (e) { close(); }
    }, 180);
  });
  inp.addEventListener("keydown", e => {
    if (!menu) return;
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      active = (active + (e.key === "ArrowDown" ? 1 : items.length - 1)) % items.length;
      menu.querySelectorAll(".ai").forEach((el, i) => el.classList.toggle("on", i === active));
    } else if (e.key === "Enter" || e.key === "Tab") { e.preventDefault(); pick(active); }
    else if (e.key === "Escape") close();
  });
  inp.addEventListener("blur", () => setTimeout(close, 150));   // 클릭 선택 여유
}
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
  bindAddrAuto(toInp); bindAddrAuto(ccInp);   // Gmail 식 연락처 자동완성
  (async () => {
    try {
      const j = await (await fetch("?replyinfo=" + lastMail.uid + "&mode=" + mode)).json();
      if (j.ok) { toInp.value = j.to.join(", "); ccInp.value = j.cc.join(", "); }
      else { toInp.placeholder = "(수신자 계산 실패 — 직접 입력)"; }
    } catch (e) { toInp.placeholder = "(수신자 계산 실패 — 직접 입력)"; }
    toInp.disabled = false; ccInp.disabled = false;
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
  // 답장/전체답장 (대상: 대화의 마지막 메일 — Gmail 동일. 보낸편지함 사본(uid 없음)은 답장 헤더 조회 불가 → 마지막 INBOX 메일로)
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
      c.style.setProperty("--c", bg); c.style.setProperty("--t", fg);   // 다크 모드에서 CSS 가 자동 변환
      const x = document.createElement("button"); x.textContent = "✕"; x.title = "라벨 제거";
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
    // 실시간 감시(IDLE 데몬) 상태 표시등: 재-IDLE 주기(4분) 이내 신호 = 정상
    const dot = document.getElementById("daemonDot");
    if (dot) {
      const ok = j.watch_age < 330;
      dot.style.color = ok ? "#1e8e3e" : "#d93025";
      dot.title = ok ? `실시간 감시 정상 (${j.watch_age}초 전 신호)` : `실시간 감시 끊김 — 3분 폴백으로 동작 중 (gmail_watch 확인)`;
    }
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
}, 2000);   // 2초마다 상태 확인 (DB 1행, ~1ms)
</script>
</body></html>

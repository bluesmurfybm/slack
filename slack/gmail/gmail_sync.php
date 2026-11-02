<?php
/**
 * Gmail → gmail_mails 테이블 동기화 (헤더만; 본문은 뷰어가 첫 열람 때 채움).
 *  - 초기: 최신부터 CHUNK 건씩 백필(뷰어 JS 가 끝날 때까지 자동 반복 호출).
 *  - 이후: 증분(새 UID 만) + 읽음(\Seen) 플래그 갱신.
 *  - UIDVALIDITY 가 바뀌면 UID 가 무효 → 테이블 리셋 후 재수집.
 *  웹: gmail_sync.php (로그인 필요, JSON) / CLI: php gmail_sync.php (백필 끝까지 반복)
 */
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('GMAIL_SYNC_LIB_ONLY')) {
    require_once __DIR__ . '/../auth.php';
    require_login();
    session_release();
    header('Content-Type: application/json; charset=utf-8');
}
require_once __DIR__ . '/gmail_lib.php';

// 백필 1회 처리량. overview 는 건당 ~27ms(Gmail) → 1000건 ≈ 30초.
// 웹 PHP 실행시간 제한(120s) 안에서 안전하게. CLI 는 어차피 끝까지 반복.
const GMAIL_CHUNK = 1000;

/** 백필 하한(msgno, 1기준): 이 번호 이상만 수집한다. 첫 백필 때 한 번만 계산해 저장.
 *  기준일(gmail_cfg since) 이후 메일 중 가장 오래된 메일의 번호 — 없으면 total+1(모을 게 없음).
 *  기준일이 비어 있으면 1(전체). 이미 다 모은 기존 계정은 백필이 끝나 있어 여기 오지 않는다. */
function gmail_backfill_floor($im, $total) {
    $f = gmeta_get('gmail_backfill_floor');
    if ($f !== null && $f !== '') return max(1, (int)$f);
    $since = trim((string)(gmail_cfg()['since'] ?? ''));
    $floor = 1;
    if ($since !== '' && ($ts = strtotime($since)) !== false) {
        $nos = imap_search($im, 'SINCE "' . date('j-M-Y', $ts) . '"') ?: [];   // 서버 검색 1회(msgno 목록)
        $floor = $nos ? (int)min($nos) : $total + 1;
    }
    gmeta_set('gmail_backfill_floor', (string)$floor);
    gmeta_set('gmail_pre_count', (string)max(0, $floor - 1));   // 수집하지 않는 기준일 이전 메일 수
    return $floor;
}

/** 보낸편지함 헤더(uid/thrid/udate)만 증분 동기화 → gmail_sent (본문·목록 노출 없음).
 *  목록의 대화 건수·최신 활동에 내가 보낸 메일을 넣기 위한 것. 대화를 열 때는 index.php(뷰어)가 실시간으로 합친다.
 *  첫 수집은 받은편지함 저장 범위에 맞춘다 — 기준일(since)과 DB 에 있는 가장 오래된 받은 메일 중 이른 날짜부터.
 *  (보낸편지함만 기준일로 자르면, 그보다 앞서 받아 둔 대화에서 내 답장이 건수에서 빠진다) */
function gmail_sync_sent($pdo, $acct) {
    $g = gmail_cfg();
    $mb = gmail_sent_folder();
    if ($mb === '') return 0;
    $im = @imap_open($g['host'] . $mb, $g['user'], str_replace(' ', '', (string)$g['pass']), OP_READONLY, 1);
    if (!$im) { gmeta_set('gmail_sent_folder', ''); return 0; }   // 폴더 이름 변경 등 → 다음 패스에 재탐색
    try {
        $st = imap_status($im, $g['host'] . $mb, SA_UIDVALIDITY | SA_UIDNEXT);
        $uv = (string)($st->uidvalidity ?? '');
        if ($uv !== '' && gmeta_get('gmail_sent_uidvalidity') !== $uv) {
            if (gmeta_get('gmail_sent_uidvalidity') !== null) {          // UID 재발급 → 이 계정 것만 비우고 처음부터
                $pdo->prepare("DELETE FROM gmail_sent WHERE account = ?")->execute([$acct]);
            }
            gmeta_set('gmail_sent_uidvalidity', $uv);
            gmeta_set('gmail_sent_last_uid', '0');
        }
        $q = $pdo->prepare("SELECT COUNT(*) FROM gmail_sent WHERE account = ?");
        $q->execute([$acct]);
        // 표가 비었으면 남아 있는 '어디까지 받았다' 기록(예전 버전이 다른 표에 남긴 것 등)은 믿지 않는다
        $last = (int)$q->fetchColumn() > 0 ? (int)gmeta_get('gmail_sent_last_uid', '0') : 0;
        if ($last > 0) {
            $ovs = imap_fetch_overview($im, ($last + 1) . ':*', FT_UID) ?: [];
            $ovs = array_values(array_filter($ovs, fn($o) => (int)($o->uid ?? 0) > $last));   // "N:*" 은 없어도 마지막 1건을 준다
            $top = $last;
        } else {
            $sinceTs = 0;
            $cfgSince = trim((string)($g['since'] ?? ''));
            if ($cfgSince !== '' && ($cs = strtotime($cfgSince)) !== false) {
                $q = $pdo->prepare("SELECT MIN(udate) FROM gmail_mails WHERE account = ? AND udate > 0");
                $q->execute([$acct]);
                $oldest = (int)$q->fetchColumn();
                $sinceTs = $oldest > 0 ? min($cs, $oldest) : $cs;
            }
            $uids = $sinceTs > 0 ? (imap_search($im, 'SINCE "' . date('j-M-Y', $sinceTs) . '"', SE_UID) ?: []) : null;
            $ovs = [];
            if ($uids === null) $ovs = imap_fetch_overview($im, '1:*', FT_UID) ?: [];
            else foreach (array_chunk($uids, 500) as $ch) $ovs = array_merge($ovs, imap_fetch_overview($im, implode(',', $ch), FT_UID) ?: []);
            $top = max(0, (int)($st->uidnext ?? 0) - 1);           // 기준일 이전 것은 다시 보지 않게 여기까지 받은 것으로
        }
    } finally {
        imap_close($im);
    }
    $max = $top;
    foreach ($ovs as $o) $max = max($max, (int)($o->uid ?? 0));
    if ($ovs) {
        $thr = [];
        try {                                              // 대화 ID 는 raw IMAP 으로 묶어서(300건씩 1커맨드)
            $raw = new GmailRaw();
            if ($raw->examine($mb)) {
                foreach (array_chunk(array_map(fn($o) => (int)$o->uid, $ovs), 300) as $ch) $thr += $raw->thridMap(implode(',', $ch));
            }
            $raw->close();
        } catch (Throwable $e) { /* 실패 시 thrid=NULL 로 저장 — 그 건만 건수에서 빠진다 */ }
        $ins = $pdo->prepare("INSERT INTO gmail_sent (account, uid, thrid, udate, subject, sender) VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE thrid = VALUES(thrid), udate = VALUES(udate),
                                                      subject = VALUES(subject), sender = VALUES(sender)");
        $pdo->beginTransaction();
        foreach ($ovs as $o) {
            $u = (int)$o->uid;
            $ins->execute([$acct, $u, $thr[$u] ?? null, (int)($o->udate ?? 0),
                mb_substr(gmail_dec_header($o->subject ?? '') ?: '(제목 없음)', 0, 500),
                mb_substr(gmail_dec_header($o->from ?? ''), 0, 300)]);
        }
        $pdo->commit();
    }
    gmeta_set('gmail_sent_last_uid', (string)$max);

    // 최근 보낸 메일 본문 미리 받기 — 대화를 처음 열 때 기다리지 않게. 한 통에 수 초(인용·이미지)라 한 번에 최대 8초만 쓴다
    $q = $pdo->prepare("SELECT uid FROM gmail_sent WHERE account = ? AND body_html IS NULL ORDER BY udate DESC LIMIT 20");
    $q->execute([$acct]);
    $todo = $q->fetchAll(PDO::FETCH_COLUMN);
    if ($todo && ($im = @imap_open($g['host'] . $mb, $g['user'], str_replace(' ', '', (string)$g['pass']), OP_READONLY, 1))) {
        try {
            $t0 = microtime(true);
            foreach ($todo as $u) {
                gmail_sent_fill($im, $pdo, $acct, $u);
                if (microtime(true) - $t0 > 8) break;
            }
        } finally {
            imap_close($im);
        }
    }
    return count($ovs);
}

/** 동기화 1회 수행 */
function gmail_sync_pass() {
    $t0 = microtime(true);
    $out = ['ok' => false, 'added' => 0, 'flag_updated' => 0, 'backfill_remaining' => 0];
    // 같은 계정 동기화가 겹치지 않게 — 업무 현황판(배지), Gmail 화면, 감시 데몬이 동시에 부를 수 있다.
    // 이미 돌고 있으면 기다리지 않고 건너뛴다(그쪽 결과가 곧 DB 에 반영된다).
    $lock = @fopen(slack_runtime_dir() . '/gmail_sync_' . md5(gmail_owner()) . '.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['ok' => true, 'skipped' => 'locked', 'took' => 0] + $out;   // 앞쪽 값이 이긴다(ok=true)
    }
    try {
        gmail_ensure_table();
        [$im, $err] = gmail_open();
        if (!$im) throw new RuntimeException($err);
        $pdo = db();
        $acct = gmail_owner();                             // 사용자(=Gmail 계정)별 데이터 구분

        // UIDVALIDITY 확인 — 바뀌면 기존 UID 전부 무효
        $g = gmail_cfg();
        $label = $g['label'] ?: 'INBOX';
        $mb = $g['host'] . (strtoupper($label) === 'INBOX' ? 'INBOX' : imap_utf8_to_mutf7($label));
        $st = imap_status($im, $mb, SA_UIDVALIDITY);
        $uv = (string)($st->uidvalidity ?? '');
        if ($uv !== '' && gmeta_get('gmail_uidvalidity') !== $uv) {
            if (gmeta_get('gmail_uidvalidity') !== null) {
                $pdo->prepare("DELETE FROM gmail_mails WHERE account = ?")->execute([$acct]);   // UID 재발급 → 이 계정만 리셋
                gmeta_set('gmail_backfill_next', '');
                gmeta_set('gmail_last_uid', '0');
                gmeta_set('gmail_backfill_floor', '');    // 수집 기준점(msgno)도 새 UID 기준으로 다시 잡는다
                gmeta_set('gmail_pre_count', '0');
            }
            gmeta_set('gmail_uidvalidity', $uv);
        }

        $total = imap_num_msg($im);
        $ins = $pdo->prepare("
            INSERT INTO gmail_mails (account, uid, subject, sender, udate, seen, flagged, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE subject = VALUES(subject), sender = VALUES(sender),
                                    udate = VALUES(udate), seen = VALUES(seen), flagged = VALUES(flagged), synced_at = NOW()
        ");
        $upsert = function (array $ov) use ($ins, $pdo, $acct) {
            $n = 0;
            $pdo->beginTransaction();
            foreach ($ov as $o) {
                $ins->execute([
                    $acct,
                    (int)($o->uid ?? 0),
                    mb_substr(gmail_dec_header($o->subject ?? '') ?: '(제목 없음)', 0, 500),
                    mb_substr(gmail_dec_header($o->from ?? ''), 0, 300),
                    (int)($o->udate ?? 0),
                    empty($o->seen) ? 0 : 1,
                    empty($o->flagged) ? 0 : 1,
                ]);
                $n++;
            }
            $pdo->commit();
            return $n;
        };

        $maxUid = function () use ($pdo, $acct) {
            $q = $pdo->prepare("SELECT COALESCE(MAX(uid),0) FROM gmail_mails WHERE account = ?");
            $q->execute([$acct]);
            return (string)(int)$q->fetchColumn();
        };
        $next = gmeta_get('gmail_backfill_next');
        $next = ($next === null || $next === '') ? $total : (int)$next;   // 첫 실행: 최신부터

        if ($next > 0) {
            // ---- 백필: 최신 → 과거 방향으로 CHUNK 건 (overview 1콜). 기준일(since) 이전 메일은 건너뜀 ----
            $floor = gmail_backfill_floor($im, $total);
            $hi = min($next, $total);
            $lo = max($floor, $hi - GMAIL_CHUNK + 1);
            if ($hi >= $lo) $out['added'] = $upsert(imap_fetch_overview($im, "$lo:$hi") ?: []);
            $next = $lo - 1;
            if ($next < $floor) $next = 0;                 // 기준일까지 다 모음 → 백필 끝
            gmeta_set('gmail_backfill_next', (string)$next);
            if ($next === 0) {
                // 증분은 '아는 UID 보다 큰 것' 만 새 메일로 본다. 기준일 직전 메일의 UID 를 깔아 두지 않으면
                // (특히 기준일 이후 메일이 0건일 때) 증분이 과거 메일을 500건씩 계속 끌어온다.
                $preUid = $floor > 1 ? (int)imap_uid($im, $floor - 1) : 0;
                gmeta_set('gmail_last_uid', (string)max((int)$maxUid(), $preUid));
            }
            $out['backfill_remaining'] = $next > 0 ? $next - $floor + 1 : 0;
        } else {
            // ---- 증분: 최신 msgno 부터 내려가며 이미 아는 UID 만나면 중단 ----
            $lastUid = (int)gmeta_get('gmail_last_uid', '0');
            $newNos = [];
            for ($n = $total; $n > 0; $n--) {
                $uid = imap_uid($im, $n);
                if ($uid <= $lastUid) break;
                $newNos[] = $n;
                if (count($newNos) >= 500) break;          // 폭주 안전장치(다음 호출이 이어감)
            }
            if ($newNos) {
                $out['added'] = $upsert(imap_fetch_overview($im, implode(',', $newNos)) ?: []);
                gmeta_set('gmail_last_uid', $maxUid());
            }
            // ---- 읽음 플래그 갱신: 서버 UNSEEN 집합과 DB 를 맞춘다 (1콜) ----
            $unseen = imap_search($im, 'UNSEEN', SE_UID) ?: [];
            if ($unseen) {
                $ph = implode(',', array_fill(0, count($unseen), '?'));
                $q = $pdo->prepare("UPDATE gmail_mails SET seen = 0 WHERE account = ? AND seen = 1 AND uid IN ($ph)");
                $q->execute(array_merge([$acct], $unseen));
                $u1 = $q->rowCount();
                $q = $pdo->prepare("UPDATE gmail_mails SET seen = 1 WHERE account = ? AND seen = 0 AND uid NOT IN ($ph)");
                $q->execute(array_merge([$acct], $unseen));
                $out['flag_updated'] = $u1 + $q->rowCount();
            } else {
                $q = $pdo->prepare("UPDATE gmail_mails SET seen = 1 WHERE account = ? AND seen = 0");
                $q->execute([$acct]);
                $out['flag_updated'] = $q->rowCount();
            }
            // ---- 별표(\Flagged) 갱신: 서버 FLAGGED 집합과 DB 를 맞춘다 (1콜) ----
            $flg = imap_search($im, 'FLAGGED', SE_UID) ?: [];
            if ($flg) {
                $ph = implode(',', array_fill(0, count($flg), '?'));
                $q = $pdo->prepare("UPDATE gmail_mails SET flagged = 1 WHERE account = ? AND flagged = 0 AND uid IN ($ph)");
                $q->execute(array_merge([$acct], $flg));
                $u1 = $q->rowCount();
                $q = $pdo->prepare("UPDATE gmail_mails SET flagged = 0 WHERE account = ? AND flagged = 1 AND uid NOT IN ($ph)");
                $q->execute(array_merge([$acct], $flg));
                $out['flag_updated'] += $u1 + $q->rowCount();
            } else {
                $q = $pdo->prepare("UPDATE gmail_mails SET flagged = 0 WHERE account = ? AND flagged = 1");
                $q->execute([$acct]);
                $out['flag_updated'] += $q->rowCount();
            }
            // ---- 삭제 동기화: 서버(INBOX)에서 사라진 메일(휴지통/보관)은 DB 에서도 제거 ----
            // 서버 메일 수와 DB 수가 다를 때만 전체 UID 대조 (평상시 비용 0).
            // 기준일 이전 메일은 처음부터 안 모았으므로 그 수(pre)를 빼고 견준다.
            $pre = (int)gmeta_get('gmail_pre_count', '0');
            $q = $pdo->prepare("SELECT COUNT(*) FROM gmail_mails WHERE account = ?");
            $q->execute([$acct]);
            if ((int)$q->fetchColumn() > $total - $pre) {
                $server = imap_search($im, 'ALL', SE_UID) ?: [];
                if ($server) {                             // 안전장치: 서버 목록 조회 실패 시 삭제 안 함
                    $sset = array_flip($server);
                    $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ?");
                    $q->execute([$acct]);
                    $gone = [];
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) {
                        if (!isset($sset[(int)$u])) $gone[] = (int)$u;
                    }
                    foreach (array_chunk($gone, 500) as $chunk) {
                        $ph = implode(',', array_fill(0, count($chunk), '?'));
                        $pdo->prepare("DELETE FROM gmail_mails WHERE account = ? AND uid IN ($ph)")
                            ->execute(array_merge([$acct], $chunk));
                    }
                    $out['deleted'] = count($gone);
                    // 대조 직후엔 DB = 서버의 수집 범위. 기준일 이전 메일이 보관/삭제돼 줄었으면 여기서 pre 를 다시 맞춘다
                    // (안 맞추면 매번 전체 UID 대조가 돈다). 새 메일이 밀려 있는 중(500건 상한)엔 건수가 아직 안 맞아 건너뜀.
                    if (count($newNos) < 500) {
                        $q = $pdo->prepare("SELECT COUNT(*) FROM gmail_mails WHERE account = ?");
                        $q->execute([$acct]);
                        gmeta_set('gmail_pre_count', (string)max(0, $total - (int)$q->fetchColumn()));
                    }
                }
            }
            // ---- 예열 1: 최근 메일 본문 미리 수집 → 열람 시 IMAP 없이 즉시 표시 ----
            $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ? AND body_html IS NULL ORDER BY udate DESC LIMIT 15");
            $q->execute([$acct]);
            $pre = 0;
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $u) {
                if (gmail_fill_body($im, $pdo, $acct, $u)) $pre++;
            }
            $out['prefetched'] = $pre;
        }

        imap_close($im);

        // ---- 예열 2: 대화 ID(thrid)·라벨 채우기 + 라벨 목록 갱신 (raw IMAP 1연결) ----
        if (($out['backfill_remaining'] ?? 0) === 0) {
            $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ? AND thrid IS NULL ORDER BY udate DESC LIMIT 500");
            $q->execute([$acct]);
            $tUids = $q->fetchAll(PDO::FETCH_COLUMN);
            $q = $pdo->prepare("SELECT uid FROM gmail_mails WHERE account = ? AND labels IS NULL ORDER BY udate DESC LIMIT 500");
            $q->execute([$acct]);
            $lUids = $q->fetchAll(PDO::FETCH_COLUMN);
            if ($tUids || $lUids) {
                try {
                    $raw = new GmailRaw();
                    if ($tUids) {
                        $map = $raw->thridMap(implode(',', $tUids));
                        $st = $pdo->prepare("UPDATE gmail_mails SET thrid = ? WHERE account = ? AND uid = ?");
                        $pdo->beginTransaction();
                        foreach ($tUids as $u) $st->execute([$map[$u] ?? '0', $acct, $u]);   // 0=응답 없음(삭제 등)
                        $pdo->commit();
                        $out['thrid_filled'] = count($tUids);
                    }
                    if ($lUids) {
                        $map = $raw->labelsMap(implode(',', $lUids));
                        $st = $pdo->prepare("UPDATE gmail_mails SET labels = ? WHERE account = ? AND uid = ?");
                        $pdo->beginTransaction();
                        foreach ($lUids as $u) $st->execute([gmail_labels_pack($map[$u] ?? []), $acct, $u]);
                        $pdo->commit();
                        $out['labels_filled'] = count($lUids);
                    }
                    gmeta_set('gmail_labels', json_encode($raw->allLabels(), JSON_UNESCAPED_UNICODE));   // 필터 드롭다운용
                    $raw->close();
                } catch (Throwable $e) {                   // 예열 실패는 치명적이지 않음 — 단, 열린 트랜잭션은 닫는다
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }
            }
        }
        // ---- 보낸편지함 헤더 동기화 (대화 건수용 — 실패해도 받은편지함 동기화에는 영향 없음) ----
        if (($out['backfill_remaining'] ?? 0) === 0) {
            try { $out['sent_added'] = gmail_sync_sent($pdo, $acct); }
            catch (Throwable $e) {
                $out['sent_err'] = $e->getMessage();
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        }
        gmeta_set('gmail_last_sync', date('Y-m-d H:i:s'));
        // 변경 마커: 브라우저의 가벼운 ping 이 이 값으로 실시간 갱신 여부 판단
        if (($out['added'] + $out['flag_updated'] + (int)($out['deleted'] ?? 0) + (int)($out['sent_added'] ?? 0)) > 0) {
            gmeta_set('gmail_changed_at', (string)time());
        }
        $out['ok'] = true;
        $q = $pdo->prepare("SELECT COUNT(*) FROM gmail_mails WHERE account = ?");
        $q->execute([$acct]);
        $out['db_total'] = (int)$q->fetchColumn();
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        // 저장 도중 실패하면 트랜잭션이 열린 채 남는다. 웹 요청은 끝나면 정리되지만 감시 데몬은 같은 DB 연결을
        // 계속 쓰므로, 닫지 않으면 이후 동기화가 전부 'already an active transaction' 으로 실패한다.
        try { $p = db(); if ($p->inTransaction()) $p->rollBack(); } catch (Throwable $e2) { /* 연결 자체가 끊긴 경우 */ }
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }   // 감시 데몬은 계속 살아 있으니 매번 풀어 준다
    $out['took'] = round(microtime(true) - $t0, 1);
    return $out;
}

if (defined('GMAIL_SYNC_LIB_ONLY')) return;   // gmail_watch.php 등이 함수만 쓸 때

if ($isCli) {
    do {
        $out = gmail_sync_pass();
        echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
    } while (!empty($out['ok']) && ($out['backfill_remaining'] ?? 0) > 0);
} else {
    echo json_encode(gmail_sync_pass(), JSON_UNESCAPED_UNICODE);
}

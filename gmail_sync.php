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
    require_once __DIR__ . '/auth.php';
    require_login();
    session_release();
    header('Content-Type: application/json; charset=utf-8');
}
require_once __DIR__ . '/gmail_lib.php';

// 백필 1회 처리량. overview 는 건당 ~27ms(Gmail) → 1000건 ≈ 30초.
// 웹 PHP 실행시간 제한(120s) 안에서 안전하게. CLI 는 어차피 끝까지 반복.
const GMAIL_CHUNK = 1000;

/** 동기화 1회 수행 */
function gmail_sync_pass() {
    $t0 = microtime(true);
    $out = ['ok' => false, 'added' => 0, 'flag_updated' => 0, 'backfill_remaining' => 0];
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
            // ---- 백필: 최신 → 과거 방향으로 CHUNK 건 (overview 1콜) ----
            $hi = min($next, $total);
            $lo = max(1, $hi - GMAIL_CHUNK + 1);
            if ($hi >= $lo) $out['added'] = $upsert(imap_fetch_overview($im, "$lo:$hi") ?: []);
            $next = $lo - 1;
            gmeta_set('gmail_backfill_next', (string)$next);
            if ($next === 0) gmeta_set('gmail_last_uid', $maxUid());
            $out['backfill_remaining'] = $next;
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
            // 서버 메일 수와 DB 수가 다를 때만 전체 UID 대조 (평상시 비용 0)
            $q = $pdo->prepare("SELECT COUNT(*) FROM gmail_mails WHERE account = ?");
            $q->execute([$acct]);
            if ((int)$q->fetchColumn() > $total) {
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
                } catch (Throwable $e) { /* 예열 실패는 치명적이지 않음 */ }
            }
        }
        gmeta_set('gmail_last_sync', date('Y-m-d H:i:s'));
        // 변경 마커: 브라우저의 가벼운 ping 이 이 값으로 실시간 갱신 여부 판단
        if (($out['added'] + $out['flag_updated'] + (int)($out['deleted'] ?? 0)) > 0) {
            gmeta_set('gmail_changed_at', (string)time());
        }
        $out['ok'] = true;
        $q = $pdo->prepare("SELECT COUNT(*) FROM gmail_mails WHERE account = ?");
        $q->execute([$acct]);
        $out['db_total'] = (int)$q->fetchColumn();
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
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

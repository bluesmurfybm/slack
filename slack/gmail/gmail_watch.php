<?php
/**
 * Gmail 실시간 감시 데몬 (IMAP IDLE) — CLI 전용.
 *  Gmail 이 변경(새 메일/읽음/삭제)을 푸시하면 즉시 gmail_sync_pass() 실행.
 *  폴링 없음: 대기 중엔 트래픽 0, 변경 시 수 초 안에 DB 반영.
 *
 *  실행:   php gmail_watch.php --account=<포털 이메일|Gmail 주소>   (마이페이지에 등록한 그 사람 메일함)
 *          php gmail_watch.php                                    (config.local.php 의 단일 계정 — 예전 방식)
 *  상시:   Windows = start_gmail_watch.bat / Linux = systemd 서비스(계정마다 하나)
 *  중지:   작업관리자에서 php.exe 종료 or 창 닫기 / systemctl stop
 */
if (php_sapi_name() !== 'cli') exit('CLI 전용');
define('GMAIL_SYNC_LIB_ONLY', 1);
require __DIR__ . '/gmail_sync.php';                       // gmail_sync_pass() + gmail_lib

function wlog($m) { echo '[' . date('m-d H:i:s') . "] $m\n"; }

/** 한 번 동기화 — 첫 수집(백필) 중이면 끝날 때까지 이어서 */
function watch_sync($why) {
    // MySQL 은 오래(기본 8시간) 쓰지 않은 연결을 끊는다. 밤·주말에 메일이 없으면 다음 동기화가
    // 'server has gone away' 로 계속 실패하고, 데몬은 살아 있어 systemd 재시작도 안 걸린다 → 확인 후 다시 붙는다.
    try { @db()->query('SELECT 1'); } catch (Throwable $e) { wlog('DB 연결이 끊겨 다시 접속합니다'); db(true); }
    do {
        $r = gmail_sync_pass();
        wlog(sprintf('%s sync: 신규 %d, 읽음변경 %d, 삭제 %d, 남은수집 %d (%.1fs)%s', $why,
            $r['added'] ?? 0, $r['flag_updated'] ?? 0, $r['deleted'] ?? 0, $r['backfill_remaining'] ?? 0,
            $r['took'] ?? 0, empty($r['ok']) ? ' 오류: ' . ($r['error'] ?? '?') : ''));
    } while (!empty($r['ok']) && ($r['backfill_remaining'] ?? 0) > 0);
}

if (!gmail_configured()) {
    // 설정 없이 돌면 빈 계정으로 로그인 실패만 반복한다
    wlog('Gmail 계정 설정이 없습니다 — --account=<이메일> 또는 config.local.php 의 gmail 설정을 확인하세요.');
    exit(1);
}
wlog('Gmail 감시 시작 (IMAP IDLE) — ' . gmail_owner());
$fails = 0;                                                // 연속 실패 수 — 재접속 간격을 늘린다
while (true) {                                             // 끊기면 자동 재접속
    $fp = null;
    try {
        $g = gmail_cfg();
        $fp = @stream_socket_client('ssl://imap.gmail.com:993', $ec, $es, 20);
        if (!$fp) throw new RuntimeException("접속 실패: $es");
        stream_set_timeout($fp, 240);                      // 4분 무소식 → DONE 후 재-IDLE (Gmail 은 ~29분에 IDLE 종료)
        $n = 0;
        $q = function ($s) { return '"' . addcslashes($s, "\\\"") . '"'; };
        $cmd = function ($c) use ($fp, &$n) {
            $t = 'A' . (++$n);
            fwrite($fp, "$t $c\r\n");
            while (($l = fgets($fp, 8192)) !== false) {
                if (strpos($l, "$t ") === 0) return $l;
            }
            return false;
        };
        fgets($fp, 8192);                                  // greeting
        $r = $cmd('LOGIN ' . $q($g['user']) . ' ' . $q(str_replace(' ', '', (string)$g['pass'])));
        if ($r === false || strpos($r, 'OK') === false) throw new RuntimeException('로그인 실패');
        $label = $g['label'] ?: 'INBOX';
        $mb = strtoupper($label) === 'INBOX' ? 'INBOX' : imap_utf8_to_mutf7($label);
        if (strpos((string)$cmd('SELECT ' . $q($mb)), 'OK') === false) throw new RuntimeException('메일함 선택 실패');
        $fails = 0;
        // 끊겨 있던 동안(재접속 대기·데몬 정지) 바뀐 것은 푸시가 오지 않는다 — 붙자마자 한 번 맞춘다
        watch_sync('접속');
        $lastSyncAt = time();
        wlog('연결됨 — IDLE 대기');

        while (true) {
            $t = 'A' . (++$n);
            fwrite($fp, "$t IDLE\r\n");
            $changed = false;
            while (true) {
                $l = fgets($fp, 8192);
                if ($l === false) {
                    $meta = stream_get_meta_data($fp);
                    if (!empty($meta['timed_out'])) break; // 타임아웃 틱 → 재-IDLE (연결 유지용)
                    throw new RuntimeException('연결 끊김');
                }
                $l = trim($l);
                // 새 메일(EXISTS)/삭제(EXPUNGE)/플래그 변경(FETCH) 푸시 감지
                if (preg_match('/^\* \d+ (EXISTS|EXPUNGE|FETCH)/', $l)) { $changed = true; break; }
                if (strpos($l, "$t ") === 0) break;        // 서버가 IDLE 종료
            }
            fwrite($fp, "DONE\r\n");
            while (($l = fgets($fp, 8192)) !== false) {    // IDLE 종료 응답 소진
                if (strpos($l, "$t ") === 0) break;
            }
            // 받은편지함 변화(푸시)가 없어도 5분마다 한 번은 맞춘다 — Gmail 웹에서 보낸 답장(보낸편지함)은
            // 받은편지함 푸시가 오지 않아, 이게 없으면 상대가 다시 메일을 보낼 때까지 대화 건수에 안 잡힌다
            if ($changed || time() - $lastSyncAt >= 300) {
                watch_sync($changed ? '변경 푸시' : '주기');
                $lastSyncAt = time();
            }
        }
    } catch (Throwable $e) {
        // 같은 오류(특히 비밀번호 틀림)로 15초마다 로그인하면 Google 이 계정을 잠글 수 있다 →
        // 연속 실패마다 간격을 두 배로(15초 → 최대 30분), 접속에 성공하면 다시 15초부터.
        $fails++;
        $wait = min(1800, 15 * (1 << min($fails - 1, 7)));
        wlog('오류: ' . $e->getMessage() . " — {$wait}초 후 재접속 (연속 {$fails}회)");
        if ($fp) @fclose($fp);
        sleep($wait);
    }
}

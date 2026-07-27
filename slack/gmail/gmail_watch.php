<?php
/**
 * Gmail 실시간 감시 데몬 (IMAP IDLE) — CLI 전용.
 *  Gmail 이 변경(새 메일/읽음/삭제)을 푸시하면 즉시 gmail_sync_pass() 실행.
 *  폴링 없음: 대기 중엔 트래픽 0, 변경 시 수 초 안에 DB 반영.
 *
 *  실행:   php gmail_watch.php          (터미널 점유)
 *  상시:   start_gmail_watch.bat 실행   (백그라운드, 창 최소화)
 *  중지:   작업관리자에서 php.exe 종료 or 창 닫기
 */
if (php_sapi_name() !== 'cli') exit('CLI 전용');
define('GMAIL_SYNC_LIB_ONLY', 1);
require __DIR__ . '/gmail_sync.php';                       // gmail_sync_pass() + gmail_lib

function wlog($m) { echo '[' . date('m-d H:i:s') . "] $m\n"; }

wlog('Gmail 감시 시작 (IMAP IDLE)');
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
            if ($changed) {
                wlog('변경 푸시 감지 → 동기화');
                $r = gmail_sync_pass();
                wlog(sprintf('sync: 신규 %d, 읽음변경 %d, 삭제 %d (%.1fs)',
                    $r['added'] ?? 0, $r['flag_updated'] ?? 0, $r['deleted'] ?? 0, $r['took'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        wlog('오류: ' . $e->getMessage() . ' — 15초 후 재접속');
        if ($fp) @fclose($fp);
        sleep(15);
    }
}

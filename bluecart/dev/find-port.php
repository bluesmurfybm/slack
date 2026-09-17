<?php
/**
 * 실제로 바인딩 가능한 로컬 포트를 찾아 출력한다.
 *
 *   php dev/find-port.php           → 쓸 수 있는 포트 번호 하나를 출력
 *   php dev/find-port.php --verbose → 후보별로 왜 안 되는지 함께 출력
 *
 * Windows 에서는 포트가 비어 있어도 바인딩이 막히는 경우가 있습니다.
 * Hyper-V, WSL2, Docker 가 포트 대역을 통째로 예약해 두기 때문입니다.
 * 그래서 "사용 중인지" 가 아니라 "실제로 열리는지" 를 직접 시험합니다.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI 전용입니다.');
}

$verbose = in_array('--verbose', $argv, true);

$candidates = [8080, 8081, 8088, 8000, 8001, 8888, 9000, 9090, 3000, 5000, 7070, 8181];

$tried = [];

foreach ($candidates as $port) {
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_server(
        "tcp://127.0.0.1:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
    );

    if ($sock !== false) {
        fclose($sock);
        if ($verbose) {
            foreach ($tried as $t) {
                fwrite(STDERR, sprintf("  %d  사용 불가 — %s%s", $t[0], $t[1], PHP_EOL));
            }
            fwrite(STDERR, sprintf("  %d  사용 가능%s", $port, PHP_EOL));
        }
        echo $port;
        exit(0);
    }

    $tried[] = [$port, trim($errstr) !== '' ? $errstr : "오류 {$errno}"];
}

// 하나도 못 찾은 경우 — 진단을 stderr 로 내보낸다.
fwrite(STDERR, PHP_EOL);
fwrite(STDERR, "쓸 수 있는 포트를 찾지 못했습니다." . PHP_EOL . PHP_EOL);
foreach ($tried as $t) {
    fwrite(STDERR, sprintf("  %-6d %s%s", $t[0], $t[1], PHP_EOL));
}
fwrite(STDERR, PHP_EOL);
fwrite(STDERR, "확인해 볼 것:" . PHP_EOL);
fwrite(STDERR, "  1) 누가 쓰고 있는지" . PHP_EOL);
fwrite(STDERR, "       netstat -ano | findstr :8080" . PHP_EOL);
fwrite(STDERR, "     나온 PID 를 작업 관리자 > 세부 정보 에서 찾아보세요." . PHP_EOL);
fwrite(STDERR, "     WampServer 나 XAMPP 의 Apache 가 잡고 있는 경우가 많습니다." . PHP_EOL . PHP_EOL);
fwrite(STDERR, "  2) Windows 가 예약해 둔 대역인지" . PHP_EOL);
fwrite(STDERR, "       netsh interface ipv4 show excludedportrange protocol=tcp" . PHP_EOL);
fwrite(STDERR, "     Hyper-V, WSL2, Docker 를 쓰면 대역이 통째로 막혀 있습니다." . PHP_EOL);
fwrite(STDERR, "     목록에 없는 번호를 골라 쓰면 됩니다." . PHP_EOL . PHP_EOL);
fwrite(STDERR, "  원하는 포트를 직접 지정하려면:  dev\\serve.bat 9123" . PHP_EOL);

exit(1);

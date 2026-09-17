<?php
declare(strict_types=1);

/**
 * 이메일 발송.
 *
 * transport = 'mail'  → PHP mail() (사내 MTA 가 있으면 가장 단순)
 * transport = 'smtp'  → 최소 SMTP 구현 (AUTH LOGIN, STARTTLS/SSL)
 *
 * PHPMailer 를 이미 쓰고 있다면 send() 내부만 교체하면 됩니다.
 */
final class MailChannel
{
    public static function send(string $to, string $subject, string $text): void
    {
        if (!bc_config('notify.enabled', true)) {
            return; // 테스트 모드: 로그만 남긴다
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('잘못된 수신 주소: ' . $to);
        }

        $transport = (string)bc_config('notify.mail.transport', 'mail');
        if ($transport === 'smtp') {
            self::sendSmtp($to, $subject, $text);
        } else {
            self::sendMail($to, $subject, $text);
        }
    }

    private static function fromHeader(): string
    {
        $name = (string)bc_config('notify.mail.from_name', 'BlueCart');
        $addr = (string)bc_config('notify.mail.from_address');
        return sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($name), $addr);
    }

    private static function sendMail(string $to, string $subject, string $text): void
    {
        $headers = implode("\r\n", [
            'From: ' . self::fromHeader(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ]);
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $body = chunk_split(base64_encode($text));

        if (!mail($to, $encSubject, $body, $headers)) {
            throw new RuntimeException('mail() 호출 실패');
        }
    }

    private static function sendSmtp(string $to, string $subject, string $text): void
    {
        $cfg  = bc_config('notify.mail.smtp');
        $host = $cfg['host'];
        $port = (int)$cfg['port'];
        $enc  = $cfg['encryption'] ?? 'tls';

        if ($host === '') {
            throw new RuntimeException('SMTP 호스트가 설정되어 있지 않습니다.');
        }

        $target = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($target, $errno, $errstr, 10);
        if (!$fp) {
            throw new RuntimeException("SMTP 접속 실패: $errstr ($errno)");
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $out;
        };
        $cmd = function (string $c, array $expect) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $res  = $read();
            $code = (int)substr($res, 0, 3);
            if (!in_array($code, $expect, true)) {
                throw new RuntimeException("SMTP 오류 [$c]: " . trim($res));
            }
            return $res;
        };

        $read(); // greeting
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, [250]);

        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS 협상 실패');
            }
            $cmd($ehlo, [250]);
        }

        if (!empty($cfg['user'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($cfg['user']), [334]);
            $cmd(base64_encode($cfg['password']), [235]);
        }

        $from = (string)bc_config('notify.mail.from_address');
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);

        $headers = [
            'From: ' . self::fromHeader(),
            'To: ' . $to,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text));
        // 선행 점 이스케이프
        $data = preg_replace('/^\./m', '..', $data);

        fwrite($fp, $data . "\r\n.\r\n");
        $res = $read();
        if ((int)substr($res, 0, 3) !== 250) {
            throw new RuntimeException('SMTP 전송 거부: ' . trim($res));
        }

        fwrite($fp, "QUIT\r\n");
        fclose($fp);
    }
}

<?php
/**
 * Dependency-free SMTP mailer (no Composer / PHPMailer needed).
 * Sends email over TLS using raw SMTP AUTH LOGIN.
 * Works on Vercel / Railway serverless PHP where mail() is unavailable.
 *
 * Configuration via environment variables (kept out of source):
 *   SMTP_HOST, SMTP_PORT (default 465), SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME
 */

class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody, array $opts = []): bool
    {
        $host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $port = (int)(getenv('SMTP_PORT') ?: 465);
        $user = getenv('SMTP_USER') ?: '';
        $pass = getenv('SMTP_PASS') ?: '';
        $from = getenv('SMTP_FROM') ?: ($user ?: 'noreply@bidduang.gov.ph');
        $fromName = getenv('SMTP_FROM_NAME') ?: 'Barangay Bidduang';

        if (!$user || !$pass) {
            error_log('Mailer: SMTP credentials not configured (SMTP_USER/SMTP_PASS).');
            return false;
        }

        $toName = $opts['to_name'] ?? '';
        $replyTo = $opts['reply_to'] ?? $from;

        $boundary = md5(uniqid('', true));
        $headers = []
            . "From: " . self::encodeFrom($fromName, $from) . "\r\n"
            . "Reply-To: " . $replyTo . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";
        $text = strip_tags(str_replace(['<br>', '<br/>', '</p>'], ["\n", "\n", "\n"], $htmlBody));
        $body = ""
            . "--" . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $text . "\r\n"
            . "--" . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $htmlBody . "\r\n"
            . "--" . $boundary . "--\r\n";

        return self::smtpSend($host, $port, $user, $pass, $from, $to, $subject, $headers, $body);
    }

    private static function encodeFrom(string $name, string $email): string
    {
        $name = trim($name);
        if ($name === '') return $email;
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private static function smtpSend(string $host, int $port, string $user, string $pass, string $from, string $to, string $subject, string $headers, string $body): bool
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $sock = @stream_socket_client('tls://' . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            error_log("Mailer: connection failed to $host:$port ($errno $errstr)");
            return false;
        }
        stream_set_timeout($sock, 15);

        try {
            self::smtpCrlf($sock, 220);
            self::cmd($sock, 'EHLO localhost');
            self::cmd($sock, 'AUTH LOGIN');
            self::cmd($sock, base64_encode($user));
            self::cmd($sock, base64_encode($pass));
            self::cmd($sock, 'MAIL FROM:<' . $from . '>');
            self::cmd($sock, 'RCPT TO:<' . $to . '>');
            self::cmd($sock, 'DATA');
            $payload = "To: " . $to . "\r\n"
                . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                . $headers . "\r\n"
                . $body;
            self::writeLine($sock, $payload . "\r\n.\r\n");
            self::smtpCrlf($sock, 250);
            self::cmd($sock, 'QUIT');
            return true;
        } catch (Throwable $e) {
            error_log('Mailer SMTP error: ' . $e->getMessage());
            return false;
        } finally {
            @fclose($sock);
        }
    }

    private static function cmd($sock, string $line): string
    {
        self::writeLine($sock, $line . "\r\n");
        return self::smtpCrlf($sock, null);
    }

    private static function writeLine($sock, string $data): void
    {
        $len = strlen($data);
        $off = 0;
        while ($off < $len) {
            $sent = @fwrite($sock, substr($data, $off));
            if ($sent === false || $sent === 0) break;
            $off += $sent;
        }
    }

    private static function smtpCrlf($sock, ?int $expectCode): string
    {
        $line = @fgets($sock, 512);
        if ($line === false) {
            throw new RuntimeException('SMTP: no response');
        }
        $code = (int)substr(trim($line), 0, 3);
        if ($expectCode !== null && $code !== $expectCode) {
            throw new RuntimeException('SMTP ' . $code . ': ' . trim($line));
        }
        return $line;
    }
}

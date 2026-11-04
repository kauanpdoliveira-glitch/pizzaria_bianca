<?php
declare(strict_types=1);

/**
 * Envio de e-mail sem bibliotecas. MAIL_DRIVER:
 *  - log  (padrão; desenvolvimento): grava o e-mail em storage/mail/*.eml — nada é enviado;
 *  - mail : função mail() do PHP (comum em hospedagem compartilhada; confirme com a HostMia que está habilitada);
 *  - smtp : SMTP direto (MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_ENCRYPTION=ssl|tls|none).
 * MAIL_FROM define o remetente. Os drivers mail/smtp NÃO foram testados contra servidores reais (ver docs).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $from = env('MAIL_FROM', 'nao-responder@localhost');
        $driver = env('MAIL_DRIVER', 'log');
        $headers = "From: Pizzaria Bianca <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        try {
            if ($driver === 'mail') return @mail($to, $subj, $body, $headers);
            if ($driver === 'smtp') return self::smtp($to, $from, $subj, $body, $headers);
            $dir = APP_ROOT . '/storage/mail'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
            return file_put_contents($dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', "To: $to\r\nSubject: $subject\r\n$headers\r\n\r\n$body") !== false;
        } catch (Throwable $e) { app_log('MAIL falhou: ' . $e->getMessage()); return false; }
    }

    private static function smtp(string $to, string $from, string $subj, string $body, string $headers): bool
    {
        $enc = env('MAIL_ENCRYPTION', 'tls'); $host = (string)env('MAIL_HOST'); $port = (int)env('MAIL_PORT', $enc === 'ssl' ? '465' : '587');
        $s = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $en, $es, 10);
        if (!$s) throw new RuntimeException("SMTP: sem conexão ($es)");
        stream_set_timeout($s, 10);
        $read = function () use ($s): string { $r = ''; while (($l = fgets($s, 512)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
        $cmd = function (string $c, string $ok) use ($s, $read): void { fwrite($s, $c . "\r\n"); $r = $read(); if (!str_starts_with($r, $ok)) throw new RuntimeException('SMTP: ' . trim($r)); };
        $read(); $cmd('EHLO bianca', '250');
        if ($enc === 'tls') { $cmd('STARTTLS', '220'); if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: TLS falhou'); $cmd('EHLO bianca', '250'); }
        if (env('MAIL_USER')) { $cmd('AUTH LOGIN', '334'); $cmd(base64_encode((string)env('MAIL_USER')), '334'); $cmd(base64_encode((string)env('MAIL_PASS')), '235'); }
        $cmd("MAIL FROM:<$from>", '250'); $cmd("RCPT TO:<$to>", '250'); $cmd('DATA', '354');
        fwrite($s, "To: $to\r\nSubject: $subj\r\n$headers\r\n\r\n" . preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))) . "\r\n.\r\n");
        $r = $read(); if (!str_starts_with($r, '250')) throw new RuntimeException('SMTP: ' . trim($r));
        fwrite($s, "QUIT\r\n"); fclose($s);
        return true;
    }
}

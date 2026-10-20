<?php
require_once __DIR__ . '/config.php';

/** Send a plain-text mail. Uses SMTP from config if host is set, else logs to data/mail.log. */
function send_mail(string $to, string $subject, string $body): bool {
    $smtp = cfg('smtp') ?: [];
    $from = cfg('mail_from') ?: 'bookmarks@localhost';
    if (empty($smtp['host'])) {
        $log = cfg('mail_log');
        $new = !is_file($log);
        file_put_contents($log, "--- " . date('c') . " To: $to\nSubject: $subject\n\n$body\n\n", FILE_APPEND | LOCK_EX);
        if ($new) @chmod($log, 0600);
        return true;
    }
    return smtp_send($smtp, $from, $to, $subject, $body);
}

function smtp_send(array $c, string $from, string $to, string $subject, string $body): bool {
    $secure = $c['secure'] ?? 'tls';
    $host = ($secure === 'ssl' ? 'ssl://' : '') . $c['host'];
    $fp = @stream_socket_client($host . ':' . (int)$c['port'], $en, $es, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) {
        $r = '';
        while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $r;
    };
    $cmd = function (string $l, string $expect) use ($fp, $read) { fwrite($fp, $l . "\r\n"); $r = $read(); return str_starts_with($r, $expect); };
    $ok = str_starts_with($read(), '220');
    $ok = $ok && $cmd('EHLO bookmarks', '250');
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd('EHLO bookmarks', '250');
    }
    if ($ok && !empty($c['user'])) {
        $ok = $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($c['user']), '334') && $cmd(base64_encode($c['pass']), '235');
    }
    $ok = $ok && $cmd("MAIL FROM:<$from>", '250') && $cmd("RCPT TO:<$to>", '25') && $cmd('DATA', '354');
    if ($ok) {
        $hdr = "From: $from\r\nTo: $to\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nDate: " . date('r') . "\r\n";
        $msg = $hdr . "\r\n" . preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], "\r\n", $body));
        fwrite($fp, $msg . "\r\n.\r\n");
        $ok = str_starts_with($read(), '250');
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

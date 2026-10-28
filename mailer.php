<?php
/** Tiny mail sender (no Composer). mail_send($to, $subject, $text) -> true or throws. Mode is set in config.php ['mail']. */
require_once __DIR__ . '/db.php';

function mail_send(string $to, string $subject, string $text): bool {
    $m = cfg()['mail'];
    $mode = $m['mode'] ?? 'log';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient address');

    if ($mode === 'log') {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents("$dir/mail.log", '[' . date('Y-m-d H:i:s') . "] To: $to\nSubject: $subject\n$text\n" . str_repeat('-', 40) . "\n", FILE_APPEND | LOCK_EX);
        return true;
    }
    $from = $m['from']; $fromName = $m['from_name'] ?? '';
    $enc  = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $headers = [
        'From: ' . ($fromName !== '' ? $enc($fromName) . " <$from>" : $from),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . (preg_replace('/^.*@/', '', $from) ?: 'localhost') . '>',
    ];
    $body = chunk_split(base64_encode($text));

    if ($mode === 'mail') {
        if (!mail($to, $enc($subject), $body, implode("\r\n", $headers))) throw new RuntimeException('PHP mail() failed (is sendmail/postfix set up?)');
        return true;
    }
    return smtp_deliver($m, $to, $from, $enc($subject), $headers, $body);
}

function smtp_deliver(array $m, string $to, string $from, string $subject, array $headers, string $body): bool {
    $host = $m['host']; $port = (int)$m['port']; $secure = $m['secure'] ?? '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP: cannot connect to $host:$port ($es)");
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {                       // reads a full (possibly multi-line) reply
        $out = '';
        while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read) {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP error: ' . trim($r));
        return $r;
    };
    try {
        $cmd('', [220]);
        $me = gethostname() ?: 'localhost';
        $cmd("EHLO $me", [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: STARTTLS failed');
            $cmd("EHLO $me", [250]);
        }
        if (($m['username'] ?? '') !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($m['username']), [334]);
            $cmd(base64_encode($m['password']), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $msg = implode("\r\n", array_merge($headers, ["To: <$to>", "Subject: $subject"])) . "\r\n\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', $msg);        // dot-stuffing
        fwrite($fp, $msg . "\r\n.\r\n");
        $r = $read();
        if ((int)substr($r, 0, 3) !== 250) throw new RuntimeException('SMTP error: ' . trim($r));
        $cmd('QUIT', [221]);
    } finally { fclose($fp); }
    return true;
}

<?php
// src/utils/smtp.php
// Minimal SMTP client supporting STARTTLS/SSL and AUTH LOGIN

function smtp_cmd($fp, string $cmd, bool $log=false) {
    if ($cmd !== '') fwrite($fp, $cmd . "\r\n");
    $resp = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $resp .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $resp;
}

function smtp_send(array $cfg, string $to, string $subject, string $html, string $fromEmail, string $fromName='') : array {
    $host = $cfg['host'] ?? '';
    $port = (int)($cfg['port'] ?? 587);
    $secure = strtolower((string)($cfg['secure'] ?? 'tls')); // tls|ssl|none
    $user = (string)($cfg['username'] ?? '');
    $pass = (string)($cfg['password'] ?? '');

    if (!$host) return [false, 'SMTP host not configured'];

    $transport = ($secure === 'ssl') ? 'ssl://' : '';
    $context = stream_context_create([
        'ssl' => [
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'SNI_server_name' => $host,
        ]
    ]);
    $fp = @stream_socket_client($transport.$host.':'.$port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) return [false, 'SMTP connect failed: '.$errstr];
    stream_set_timeout($fp, 15);

    $resp = smtp_cmd($fp, '');
    if (strpos($resp, '220') !== 0) { fclose($fp); return [false, 'SMTP greeting failed']; }

    $ehloHost = 'localhost';
    $resp = smtp_cmd($fp, 'EHLO '.$ehloHost);
    if (strpos($resp, '250') !== 0) { smtp_cmd($fp, 'HELO '.$ehloHost); }

    if ($secure === 'tls') {
        $r = smtp_cmd($fp, 'STARTTLS');
        if (strpos($r, '220') !== 0) { fclose($fp); return [false, 'STARTTLS failed']; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            fclose($fp); return [false, 'TLS negotiation failed'];
        }
        smtp_cmd($fp, 'EHLO '.$ehloHost);
    }

    if ($user !== '' && $pass !== '') {
        smtp_cmd($fp, 'AUTH LOGIN');
        smtp_cmd($fp, base64_encode($user));
        $authResp = smtp_cmd($fp, base64_encode($pass));
        if (strpos($authResp, '235') !== 0) { fclose($fp); return [false, 'SMTP auth failed']; }
    }

    $fromHeader = $fromName ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;
    $date = date('r');
    $headers = [];
    $headers[] = 'Date: ' . $date;
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    $msg = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n";

    if (strpos(smtp_cmd($fp, 'MAIL FROM:<'.$fromEmail.'>'), '250') !== 0) { fclose($fp); return [false, 'MAIL FROM failed']; }
    if (strpos(smtp_cmd($fp, 'RCPT TO:<'.$to.'>'), '250') !== 0) { fclose($fp); return [false, 'RCPT TO failed']; }
    if (strpos(smtp_cmd($fp, 'DATA'), '354') !== 0) { fclose($fp); return [false, 'DATA not accepted']; }

    fwrite($fp, $msg . "\r\n.\r\n");
    $dataResp = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $dataResp .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    if (strpos($dataResp, '250') !== 0) { fclose($fp); return [false, 'Message not accepted']; }
    smtp_cmd($fp, 'QUIT');
    fclose($fp);
    return [true, ''];
}
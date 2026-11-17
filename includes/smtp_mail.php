<?php
/**
 * Envoi d’email via SMTP (TLS implicite sur port 465 ou STARTTLS sur 587).
 * Nécessite OpenSSL pour ssl:// et tls.
 */

function samapiece_smtp_get_last_error() {
    return isset($GLOBALS['samapiece_smtp_last_error'])
        ? (string) $GLOBALS['samapiece_smtp_last_error']
        : '';
}

function samapiece_smtp_set_last_error($msg) {
    $GLOBALS['samapiece_smtp_last_error'] = (string) $msg;
}

function smtp_mail_is_configured() {
    return SMTP_HOST !== ''
        && SMTP_PORT > 0
        && SMTP_USERNAME !== ''
        && SMTP_PASSWORD !== '';
}

/**
 * @return bool true si accepté par le serveur SMTP
 */
function smtp_send_html($to, $subject, $htmlBody) {
    samapiece_smtp_set_last_error('');
    if (!smtp_mail_is_configured()) {
        samapiece_smtp_set_last_error('SMTP non configuré (mot de passe ou identifiants manquants).');
        return false;
    }

    $host = SMTP_HOST;
    $port = (int) SMTP_PORT;
    $user = SMTP_USERNAME;
    $pass = SMTP_PASSWORD;
    $from = EMAIL_FROM;
    $encryption = strtolower(SMTP_ENCRYPTION);

    $remote = ($encryption === 'ssl' ? 'ssl' : 'tcp') . '://' . $host . ':' . $port;
    $verify = defined('SMTP_SSL_VERIFY') ? SMTP_SSL_VERIFY : true;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
        ],
    ]);

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        25,
        STREAM_CLIENT_CONNECT,
        $ctx
    );

    if (!$socket) {
        samapiece_smtp_set_last_error('Connexion ' . $remote . ' impossible : ' . $errstr . ' (' . $errno . ')');
        return false;
    }
    stream_set_timeout($socket, 25);

    $read = function () use ($socket) {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $expect = function ($resp, $codes) use ($read) {
        $ok = false;
        foreach ($codes as $c) {
            if (strpos($resp, (string) $c) === 0) {
                $ok = true;
                break;
            }
        }
        return $ok;
    };

    $write = function ($cmd) use ($socket) {
        fwrite($socket, $cmd . "\r\n");
    };

    $greeting = $read();
    if (!$expect($greeting, [220])) {
        samapiece_smtp_set_last_error('Bannière SMTP inattendue : ' . trim(substr($greeting, 0, 200)));
        fclose($socket);
        return false;
    }

    $ehloHost = 'localhost';
    if (preg_match('/@([a-z0-9.-]+)/i', $from, $m)) {
        $ehloHost = $m[1];
    }
    $write('EHLO ' . $ehloHost);
    $ehloResp = $read();
    if (!$expect($ehloResp, [250])) {
        samapiece_smtp_set_last_error('EHLO refusé : ' . trim(substr($ehloResp, 0, 200)));
        fclose($socket);
        return false;
    }

    if ($encryption === 'tls' && $port === 587) {
        $write('STARTTLS');
        $tlsResp = $read();
        if (!$expect($tlsResp, [220])) {
            samapiece_smtp_set_last_error('STARTTLS refusé : ' . trim(substr($tlsResp, 0, 200)));
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            samapiece_smtp_set_last_error('Échec négociation TLS (STARTTLS).');
            fclose($socket);
            return false;
        }
        $write('EHLO ' . $ehloHost);
        $read(); // capacités post-STARTTLS
    }

    $write('AUTH LOGIN');
    if (!$expect($read(), [334])) {
        samapiece_smtp_set_last_error('AUTH LOGIN non accepté par le serveur.');
        fclose($socket);
        return false;
    }
    $write(base64_encode($user));
    if (!$expect($read(), [334])) {
        samapiece_smtp_set_last_error('Identifiant SMTP refusé.');
        fclose($socket);
        return false;
    }
    $write(base64_encode($pass));
    $authResp = $read();
    if (!$expect($authResp, [235])) {
        samapiece_smtp_set_last_error('Mot de passe SMTP refusé ou compte bloqué : ' . trim(substr($authResp, 0, 200)));
        fclose($socket);
        return false;
    }

    $write('MAIL FROM:<' . $from . '>');
    $mf = $read();
    if (!$expect($mf, [250])) {
        samapiece_smtp_set_last_error('MAIL FROM refusé : ' . trim(substr($mf, 0, 200)));
        fclose($socket);
        return false;
    }
    $write('RCPT TO:<' . $to . '>');
    $rc = $read();
    if (!$expect($rc, [250, 251])) {
        samapiece_smtp_set_last_error('RCPT TO refusé (adresse invalide ou refus serveur) : ' . trim(substr($rc, 0, 200)));
        fclose($socket);
        return false;
    }
    $write('DATA');
    $dataResp = $read();
    if (!$expect($dataResp, [354])) {
        samapiece_smtp_set_last_error('DATA refusé : ' . trim(substr($dataResp, 0, 200)));
        fclose($socket);
        return false;
    }

    $boundary = 'b' . bin2hex(random_bytes(8));
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $messageIdHost = preg_match('/@([a-z0-9.-]+)/i', $from, $mm) ? $mm[1] : $ehloHost;
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'To: ' . $to,
        'Subject: ' . $subj,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $messageIdHost . '>',
        'Date: ' . date(DATE_RFC2822),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
    ];
    $body = chunk_split(base64_encode($htmlBody));
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $write($payload);
    $endResp = $read();
    if (!$expect($endResp, [250])) {
        samapiece_smtp_set_last_error('Envoi du message refusé : ' . trim(substr($endResp, 0, 200)));
        fclose($socket);
        return false;
    }
    $write('QUIT');
    fclose($socket);
    return true;
}

<?php
/**
 * Minimalny klient SMTP (STARTTLS + AUTH LOGIN), bez Composera (na hostingu nie ma PHPMailera).
 * Konfiguracja: data/mail.php (JSON, poza gitem): {host, port, user, pass, from, from_name, base_url}.
 * Pułapki: odpowiedzi wieloliniowe ("250-..." kończy "250 ..."); treść w base64, więc kropka na początku
 * linii w DATA nie wymaga podwajania. Wyjątek przy każdym błędzie - wywołujący decyduje, co pokazać użytkownikowi.
 */
require_once __DIR__ . '/store.php';
const MAIL_CONFIG = __DIR__ . '/data/mail.php';

function mail_config(): array
{
    $c = json_read(MAIL_CONFIG);
    if (empty($c['host']) || empty($c['user']) || empty($c['pass']) || empty($c['from']) || empty($c['base_url'])) {
        throw new RuntimeException('SMTP: brak konfiguracji data/mail.php');
    }
    return $c + ['port' => 587, 'from_name' => 'Galaktyka'];
}

function smtp_reply($fp): array
{
    $txt = '';
    while (($l = fgets($fp, 512)) !== false) {
        $txt .= $l;
        if (strlen($l) < 4 || $l[3] !== '-') break;
    }
    return [(int)substr($txt, 0, 3), $txt];
}

function smtp_cmd($fp, ?string $cmd, int $ok): void
{
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    [$code] = smtp_reply($fp);
    if ($code !== $ok) throw new RuntimeException("SMTP: oczekiwano $ok, jest $code");
}

/** Wysyła mail tekstowy UTF-8. */
function send_mail(string $to, string $subject, string $body): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP: zły adres odbiorcy');
    $c  = mail_config();
    $fp = @stream_socket_client("tcp://{$c['host']}:{$c['port']}", $en, $es, 15);
    if (!$fp) throw new RuntimeException('SMTP: brak połączenia');
    stream_set_timeout($fp, 15);
    try {
        smtp_cmd($fp, null, 220);
        smtp_cmd($fp, 'EHLO galaktyka', 250);
        smtp_cmd($fp, 'STARTTLS', 220);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: TLS');
        smtp_cmd($fp, 'EHLO galaktyka', 250);
        smtp_cmd($fp, 'AUTH LOGIN', 334);
        smtp_cmd($fp, base64_encode($c['user']), 334);
        smtp_cmd($fp, base64_encode($c['pass']), 235);
        smtp_cmd($fp, "MAIL FROM:<{$c['from']}>", 250);
        smtp_cmd($fp, "RCPT TO:<$to>", 250);
        smtp_cmd($fp, 'DATA', 354);
        $headers = 'From: =?UTF-8?B?' . base64_encode($c['from_name']) . "?= <{$c['from']}>\r\nTo: <$to>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        fwrite($fp, $headers . "\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n");
        smtp_cmd($fp, null, 250);
        smtp_cmd($fp, 'QUIT', 221);
    } finally {
        fclose($fp);
    }
}

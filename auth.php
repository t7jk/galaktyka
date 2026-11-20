<?php
/**
 * Logowanie admina e-mail+hasło i reset hasła przez e-mail (Galaktyka, bez bazy danych).
 * Dane (poza gitem, w data/): users.php = [{email,name,hash}], auth_state.php = próby logowania i tokeny resetu.
 * Założenia: hasła to bcrypt ($2y$, jak w PSOTA); surowy token resetu nigdy nie trafia na dysk (tylko sha256);
 * odpowiedź na "Nie pamiętam hasła" jest ZAWSZE taka sama (nie ujawnia, czy konto istnieje);
 * GET linku resetu nie zużywa tokenu, robi to dopiero zapis nowego hasła; nowa prośba unieważnia poprzednie.
 * Limity: 5 błędnych logowań/IP = blokada 30 min; reset max 3 maile/h na konto i na IP.
 * Format plików i blokada zapisu: store.php.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/mail.php';

const USERS_FILE   = __DIR__ . '/data/users.php';
const STATE_FILE   = __DIR__ . '/data/auth_state.php';
const RESET_TTL    = 3600;
const RESET_LIMIT  = 3;
const LOGIN_MAX    = 5;
const LOGIN_BLOCK  = 1800;
const MIN_PASSWORD = 8;
// Atrapa hasha bcrypt: przy nieistniejącym koncie liczymy password_verify, by czas odpowiedzi nie zdradzał konta.
const DUMMY_HASH   = '$2y$12$u3PmxQA3qATgmpY8EXio4Ov1P4tzEonJ95eSJNnNRx9KCIVDuLvZK';

// ─── Użytkownicy ─────────────────────────────────────────────────────────────

function norm_email(string $e): string { return mb_strtolower(trim($e)); }

function find_user(string $email): ?array
{
    $email = norm_email($email);
    foreach (json_read(USERS_FILE) as $u) {
        if (($u['email'] ?? '') === $email) return $u;
    }
    return null;
}

/** Zwraca użytkownika przy poprawnym haśle, inaczej null. */
function check_credentials(string $email, string $password): ?array
{
    $u = find_user($email);
    if (!$u) { password_verify($password, DUMMY_HASH); return null; }
    return password_verify($password, $u['hash']) ? $u : null;
}

// ─── CSRF ────────────────────────────────────────────────────────────────────

function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_ok(array $p): bool { return isset($_SESSION['csrf'], $p['csrf']) && hash_equals($_SESSION['csrf'], (string)$p['csrf']); }

// ─── Limit błędnych logowań (IP) ─────────────────────────────────────────────

function login_blocked_minutes(string $ip): int
{
    $until = json_read(STATE_FILE)['login'][$ip]['block'] ?? 0;
    return $until > time() ? (int)ceil(($until - time()) / 60) : 0;
}

/** Zapisuje błędną próbę; zwraca liczbę błędów (0 = właśnie zablokowano). */
function login_fail(string $ip): int
{
    $n = 0;
    json_update(STATE_FILE, function (&$s) use ($ip, &$n) {
        $n = (($s['login'][$ip]['n'] ?? 0)) + 1;
        if ($n >= LOGIN_MAX) {
            $s['login'][$ip] = ['n' => 0, 'block' => time() + LOGIN_BLOCK];
            $n = 0;
        } else {
            $s['login'][$ip] = ['n' => $n, 'block' => 0];
        }
    });
    return $n;
}

function login_ok(string $ip): void
{
    json_update(STATE_FILE, function (&$s) use ($ip) { unset($s['login'][$ip]); });
}

function start_session_for(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_name'] = $u['name'];
}

// ─── Reset hasła ─────────────────────────────────────────────────────────────

/** Tworzy token i wysyła mail, jeśli konto istnieje i limity pozwalają. Nic nie zwraca (odpowiedź jest neutralna). */
function reset_request(string $email, string $ip): void
{
    $u = find_user($email);
    if (!$u) return;
    $token   = bin2hex(random_bytes(32));
    $allowed = false;
    json_update(STATE_FILE, function (&$s) use ($u, $ip, $token, &$allowed) {
        $now = time();
        $s['reset'] = array_values(array_filter($s['reset'] ?? [], fn($t) => $t['created'] > $now - 86400)); // sprzątanie
        $recent = array_filter($s['reset'], fn($t) => $t['created'] > $now - 3600 && ($t['email'] === $u['email'] || $t['ip'] === $ip));
        if (count($recent) >= RESET_LIMIT) return;
        foreach ($s['reset'] as &$t) { if ($t['email'] === $u['email']) $t['used'] = true; } // unieważnij poprzednie
        unset($t);
        $s['reset'][] = ['email' => $u['email'], 'hash' => hash('sha256', $token), 'until' => $now + RESET_TTL,
                         'used' => false, 'ip' => $ip, 'created' => $now];
        $allowed = true;
    });
    if (!$allowed) return;
    $link = rtrim(mail_config()['base_url'], '/') . '/admin.php?a=reset&t=' . $token; // adres z konfiguracji, nie z nagłówka Host
    try {
        send_mail($u['email'], 'Galaktyka – ustawienie nowego hasła',
            "Dzień dobry,\n\notrzymaliśmy prośbę o ustawienie nowego hasła do panelu Galaktyka. Kliknij link (ważny 1 godzinę, działa jeden raz):\n\n$link\n\nJeśli to nie Ty, zignoruj tę wiadomość.\n");
    } catch (Throwable $e) {
        error_log('Galaktyka: błąd wysyłki maila resetu: ' . $e->getMessage());
    }
}

/** E-mail właściciela ważnego, niezużytego tokenu albo null. */
function reset_token_email(string $token): ?string
{
    $h = hash('sha256', $token);
    foreach (json_read(STATE_FILE)['reset'] ?? [] as $t) {
        if (hash_equals($t['hash'], $h) && !$t['used'] && $t['until'] > time()) return $t['email'];
    }
    return null;
}

/** Ustawia nowe hasło i zużywa tokeny konta. */
function reset_apply(string $email, string $password): void
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    json_update(USERS_FILE, function (&$users) use ($email, $hash) {
        foreach ($users as &$u) { if ($u['email'] === $email) $u['hash'] = $hash; }
    });
    json_update(STATE_FILE, function (&$s) use ($email) {
        foreach ($s['reset'] ?? [] as &$t) { if ($t['email'] === $email) $t['used'] = true; }
    });
}

// ─── Kontroler ekranów przed logowaniem ──────────────────────────────────────

/**
 * Obsługuje formularze logowania/resetu. Przy sukcesie loguje i przekierowuje (kończy skrypt).
 * Zwraca dane widoku dla szablonu: ['view' => login|forgot|reset|reset_bad, 'err', 'info', 'token'].
 */
function auth_handle(array $post, array $get): array
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $view = ['view' => 'login', 'err' => '', 'info' => '', 'token' => ''];
    $a    = $get['a'] ?? '';

    if ($a === 'forgot') {
        $view['view'] = 'forgot';
        if (isset($post['email'])) {
            if (!csrf_ok($post)) $view['err'] = 'Sesja wygasła, spróbuj ponownie.';
            else {
                $t0 = microtime(true);
                reset_request((string)$post['email'], $ip);
                $left = 1.5 - (microtime(true) - $t0); // stała minimalna odpowiedź: czas nie zdradza, czy konto istnieje
                if ($left > 0) usleep((int)($left * 1e6));
                $view['info'] = 'Jeśli konto istnieje, wysłano link do ustawienia hasła.';
            }
        }
        return $view;
    }

    if ($a === 'reset') {
        header('Referrer-Policy: no-referrer'); // token w URL nie może wyciec w nagłówku Referer
        $token = (string)($post['t'] ?? $get['t'] ?? '');
        $email = reset_token_email($token);
        if (!$email) { $view['view'] = 'reset_bad'; return $view; }
        $view['view']  = 'reset';
        $view['token'] = $token;
        if (isset($post['password'])) {
            if (!csrf_ok($post)) $view['err'] = 'Sesja wygasła, spróbuj ponownie.';
            elseif (mb_strlen($post['password']) < MIN_PASSWORD) $view['err'] = 'Hasło musi mieć min. ' . MIN_PASSWORD . ' znaków.';
            elseif ($post['password'] !== ($post['password2'] ?? '')) $view['err'] = 'Hasła się różnią.';
            else {
                reset_apply($email, $post['password']);
                start_session_for(find_user($email)); // po zmianie hasła od razu do panelu
                header('Location: admin.php'); exit;
            }
        }
        return $view;
    }

    if ($min = login_blocked_minutes($ip)) { $view['err'] = "Zablokowano. Spróbuj za $min min."; return $view; }
    if (isset($post['login'])) {
        if (!csrf_ok($post)) $view['err'] = 'Sesja wygasła, spróbuj ponownie.';
        elseif ($u = check_credentials((string)($post['email'] ?? ''), (string)($post['password'] ?? ''))) {
            login_ok($ip);
            start_session_for($u);
            header('Location: admin.php'); exit;
        } else {
            $n = login_fail($ip);
            $view['err'] = $n ? "Zły e-mail lub hasło ($n/" . LOGIN_MAX . ')' : 'Zablokowano na 30 minut.';
        }
    }
    return $view;
}

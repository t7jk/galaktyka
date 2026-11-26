<?php
/**
 * PIN dostępu do tablicy kafelków (index.php) i ikon (icon.php). Admin (sesja) ma dostęp bez PIN-u.
 *
 * Stan w jednym pliku data/pin.php (format store.php, flock), bo zmiana PIN-u musi atomowo wyczyścić tokeny:
 *   hash   - bcrypt PIN-u (brak pliku/klucza = domyślny PIN 1980, hash poniżej)
 *   tokens - [sha256(token) => wygasa]  (surowy token jest tylko w ciasteczku przeglądarki)
 *   ips    - [ip => ['n' => błędy, 'block' => do kiedy]]
 *   day    - ['start' => początek doby, 'n' => błędy ze wszystkich IP]
 *   global_block - do kiedy PIN jest zablokowany dla wszystkich
 *
 * Zagrożenie: PIN ma 10 000 kombinacji, więc bez limitów bot zgadnie go w minuty. Limity:
 *   5 błędów z IP = blokada IP na 15 min; 50 błędów/dobę łącznie = blokada PIN-u dla wszystkich na 1 h
 *   (obrona przed botnetem z wielu IP). Blokada NIE dotyczy już wydanych ciasteczek (biuro nie traci dostępu),
 *   admin zdejmuje ją w panelu. W czasie blokady nie sprawdzamy PIN-u (poprawny PIN też nie wpuszcza).
 */
const PIN_FILE         = DATA_DIR . '/pin.php';
const PIN_DEFAULT_HASH = '$2y$12$DPrjvlNxfDThLfPvrl7Ba.i.1KS7mE7RL00UMWEW5j944g0ZFwbUm'; // bcrypt "1980"
const PIN_COOKIE       = 'galaktyka_pin';
const PIN_COOKIE_TTL   = 7 * 86400;
const PIN_IP_MAX       = 5;
const PIN_IP_BLOCK     = 900;
const PIN_DAY_MAX      = 50;
const PIN_DAY_BLOCK    = 3600;

// ─── Dostęp ──────────────────────────────────────────────────────────────────

function pin_cookie_valid(): bool
{
    $token = $_COOKIE[PIN_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) return false;
    return (json_read(PIN_FILE)['tokens'][hash('sha256', $token)] ?? 0) > time();
}

function pin_has_access(): bool { return !empty($_SESSION['admin']) || pin_cookie_valid(); }

function pin_valid_format(string $pin): bool { return (bool)preg_match('/^\d{4}$/', $pin); }

// ─── Blokady ─────────────────────────────────────────────────────────────────

function pin_minutes_left(int $until): int { return $until > time() ? (int)ceil(($until - time()) / 60) : 0; }

/** Minuty do końca blokady (globalnej albo tego IP); 0 = brak blokady. */
function pin_blocked_minutes(string $ip): int
{
    $s = json_read(PIN_FILE);
    return max(pin_minutes_left($s['global_block'] ?? 0), pin_minutes_left($s['ips'][$ip]['block'] ?? 0));
}

function pin_global_blocked_minutes(): int { return pin_minutes_left(json_read(PIN_FILE)['global_block'] ?? 0); }

function pin_unblock_global(): void
{
    json_update(PIN_FILE, function (&$s) { unset($s['global_block']); $s['day'] = ['start' => time(), 'n' => 0]; });
}

// ─── Próba wpisania PIN-u ────────────────────────────────────────────────────

/** Usuwa wygasłe tokeny i stare wpisy IP (żeby plik nie rósł). */
function pin_cleanup(array &$s): void
{
    $now = time();
    $s['tokens'] = array_filter($s['tokens'] ?? [], fn($exp) => $exp > $now);
    $s['ips']    = array_filter($s['ips'] ?? [], fn($r) => ($r['block'] ?? 0) > $now || ($r['n'] ?? 0) > 0);
}

/** Zlicza błąd: IP i licznik dobowy; ustawia blokady po przekroczeniu limitów. Zwraca liczbę błędów IP (0 = zablokowano). */
function pin_record_failure(array &$s, string $ip): int
{
    $now = time();
    $n   = ($s['ips'][$ip]['n'] ?? 0) + 1;
    $s['ips'][$ip] = $n >= PIN_IP_MAX ? ['n' => 0, 'block' => $now + PIN_IP_BLOCK] : ['n' => $n, 'block' => 0];

    if ($now - ($s['day']['start'] ?? 0) >= 86400) $s['day'] = ['start' => $now, 'n' => 0];
    $s['day']['n']++;
    if ($s['day']['n'] >= PIN_DAY_MAX) { $s['global_block'] = $now + PIN_DAY_BLOCK; $s['day'] = ['start' => $now, 'n' => 0]; }
    return $n >= PIN_IP_MAX ? 0 : $n;
}

/**
 * Sprawdza PIN pod blokadą pliku. Zwraca ['status' => ok|bad|blocked, 'token' => surowy token (tylko ok), 'n' => błędy IP].
 */
function pin_attempt(string $pin, string $ip): array
{
    $res = ['status' => 'bad', 'token' => '', 'n' => 0];
    json_update(PIN_FILE, function (&$s) use ($pin, $ip, &$res) {
        $now = time();
        pin_cleanup($s);
        if (($s['global_block'] ?? 0) > $now || ($s['ips'][$ip]['block'] ?? 0) > $now) { $res['status'] = 'blocked'; return; }
        if (pin_valid_format($pin) && password_verify($pin, $s['hash'] ?? PIN_DEFAULT_HASH)) {
            $token = bin2hex(random_bytes(32));
            $s['tokens'][hash('sha256', $token)] = $now + PIN_COOKIE_TTL;
            unset($s['ips'][$ip]);
            $res = ['status' => 'ok', 'token' => $token, 'n' => 0];
            return;
        }
        $res['n'] = pin_record_failure($s, $ip);
        if ($res['n'] === 0) $res['status'] = 'blocked';
    });
    return $res;
}

function pin_issue_cookie(string $token): void
{
    setcookie(PIN_COOKIE, $token, [
        'expires' => time() + PIN_COOKIE_TTL, 'path' => cookie_path(),
        'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/** Zmiana PIN-u (admin): nowy hash i unieważnienie WSZYSTKICH ciasteczek. */
function pin_change(string $newPin): void
{
    $hash = password_hash($newPin, PASSWORD_BCRYPT);
    json_update(PIN_FILE, function (&$s) use ($hash) { $s['hash'] = $hash; $s['tokens'] = []; });
}

// ─── Bramka (formularz PIN) ──────────────────────────────────────────────────

/** Przetwarza formularz PIN. Zwraca komunikat błędu ('' = brak). Przy sukcesie ustawia ciasteczko i przekierowuje. */
function pin_handle_post(array $post): string
{
    if (!csrf_ok($post)) return 'Sesja wygasła, spróbuj ponownie.';
    $r = pin_attempt((string)($post['pin'] ?? ''), client_ip());
    if ($r['status'] === 'ok') {
        pin_issue_cookie($r['token']);
        header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        exit;
    }
    if ($r['status'] === 'blocked') {
        $min = max(1, pin_blocked_minutes(client_ip()));
        return "Zbyt wiele prób. Spróbuj za $min min.";
    }
    return 'Zły PIN (' . $r['n'] . '/' . PIN_IP_MAX . ')';
}

/** Wymaga dostępu: bez niego pokazuje formularz PIN i kończy skrypt. Wołane na górze board.php. */
function pin_require_access(): void
{
    if (pin_has_access()) return;
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') $err = pin_handle_post($_POST);
    elseif ($min = pin_blocked_minutes(client_ip())) $err = "Zbyt wiele prób. Spróbuj za $min min.";
    header('Cache-Control: no-store');
    http_response_code($err !== '' && str_starts_with($err, 'Zbyt') ? 429 : 200);
    require APP_DIR . '/views/pin_form.php';
    exit;
}

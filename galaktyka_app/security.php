<?php
/**
 * Drobne mechanizmy bezpieczeństwa wspólne dla wszystkich stron: nagłówki, sesja, CSRF, IP klienta.
 */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; // za proxy; wpływa tylko na flagę Secure ciasteczek
}

/** Adres klienta do limitów. Celowo REMOTE_ADDR (nagłówki typu X-Forwarded-For może podrobić atakujący). */
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }

/** Ścieżka ciasteczek = katalog aplikacji w URL (dev: /galaktyka/, prod: /), by ciasteczka nie wyciekały do innych aplikacji. */
function cookie_path(): string { return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/'; }

function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}

/**
 * Sesja (admin + CSRF formularza PIN). Dla icon.php sesja jest tylko do odczytu i od razu zamykana,
 * żeby ~60 równoległych żądań ikon nie czekało na blokadę pliku sesji.
 */
function start_app_session(): void
{
    session_set_cookie_params([
        'lifetime' => 0, 'path' => cookie_path(),
        'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start(['read_and_close' => GALAKTYKA_PAGE === 'icon']);
}

// ─── CSRF ────────────────────────────────────────────────────────────────────

function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_ok(array $p): bool { return isset($_SESSION['csrf'], $p['csrf']) && hash_equals($_SESSION['csrf'], (string)$p['csrf']); }

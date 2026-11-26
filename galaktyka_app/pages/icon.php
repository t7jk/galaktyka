<?php
/**
 * Serwuje ikony z data/icons/ (poza WWW) wyłącznie po PIN-ie albo dla admina.
 * Bezpieczeństwo: tylko basename (brak ../), whitelist rozszerzeń, własny Content-Type (nigdy z zawartości).
 * SVG może zawierać skrypty, więc dostaje CSP sandbox (nie wykona JS nawet po otwarciu bezpośrednio).
 * Sesja jest tu tylko do odczytu (start_app_session), a ikony mają cache prywatny (nie na proxy).
 */
const ICON_TYPES = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
];

function icon_deny(int $code)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($code === 403 ? 'Brak dostępu' : 'Nie znaleziono');
}

if (!pin_has_access()) icon_deny(403);

$name = basename((string)($_GET['f'] ?? ''));
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$path = ICONS_DIR . $name;
if ($name === '' || $name[0] === '.' || !isset(ICON_TYPES[$ext]) || !is_file($path)) icon_deny(404);

header('Content-Type: ' . ICON_TYPES[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
if ($ext === 'svg') header("Content-Security-Policy: sandbox");
readfile($path);

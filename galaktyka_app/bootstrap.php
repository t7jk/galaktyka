<?php
/**
 * Wspólny start każdej strony. Stuby w public_html/ definiują GALAKTYKA_PAGE (board|admin|icon) i wołają ten plik.
 * Tu: stałe ścieżek, nagłówki bezpieczeństwa, sesja, ładowanie modułów, uruchomienie strony z pages/.
 * Założenie: pages/*.php to "kontrolery" - wykonują się w zakresie globalnym, jak dawne pliki w public_html.
 */
const APP_DIR   = __DIR__;
const DATA_DIR  = APP_DIR . '/data';
const ICONS_DIR = DATA_DIR . '/icons/';

const KNOWN_PAGES = ['board', 'admin', 'icon'];
if (!defined('GALAKTYKA_PAGE') || !in_array(GALAKTYKA_PAGE, KNOWN_PAGES, true)) {
    http_response_code(404);
    exit;
}

require_once APP_DIR . '/security.php';
require_once APP_DIR . '/store.php';
require_once APP_DIR . '/pin.php';

send_security_headers();
start_app_session();

require APP_DIR . '/pages/' . GALAKTYKA_PAGE . '.php';

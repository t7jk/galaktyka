================================================================
GALAKTYKA - prywatna tablica skrótów do aplikacji webowych
================================================================

Tablica kafelków (PHP + vanilla JS, bez bazy danych; dane w plikach JSON).
Dostęp do tablicy: PIN (4 cyfry, domyślnie 1980 - ZMIEŃ w panelu admina).
Panel admina: osobne logowanie e-mail + hasło (reset hasła przez e-mail).

UKŁAD KATALOGÓW (dev 1:1 jak prod; root = domains/galaktyka.edu.pl)
---------------------------------------------------------------------
  /var/www/galaktyka/            repo git (na dev); odpowiada rootowi FTP
  ├── public_html/               JEDYNY katalog dostępny przez WWW
  │   ├── index.php admin.php icon.php   cienkie stuby (define GALAKTYKA_PAGE + require bootstrap)
  │   ├── assets/ favicon.ico robots.txt
  ├── galaktyka_app/             kod (poza WWW)
  │   ├── bootstrap.php          ścieżki, nagłówki bezpieczeństwa, sesja, routing do pages/
  │   ├── security.php           nagłówki, sesja, CSRF, IP klienta
  │   ├── pin.php                PIN: hash, limity, ciasteczka
  │   ├── auth.php mail.php store.php   logowanie admina, SMTP, magazyn JSON (flock)
  │   ├── pages/ board.php admin.php icon.php   kontrolery stron
  │   ├── views/ pin_form.php
  │   ├── tools/                 skrypty CLI (setup.sh, setup.php, ikony); WWW daje 404
  │   └── data/                  dane i sekrety, poza gitem i poza deployem
  ├── docs/  update_galaktyka.sh  PLAN-bezpieczenstwo.md   (nie są wgrywane)
  Dev: /var/www/html/galaktyka -> symlink do /var/www/galaktyka/public_html
  (Apache: FollowSymLinks). Stub używa dirname(__DIR__), więc to samo działa na prod.

INSTALACJA (nowa)
-----------------
  1. Umieść repo tak, by do WWW wystawić tylko public_html/.
  2. data/ musi być zapisywalne przez serwer WWW:
       chgrp -R www-data galaktyka_app/data && chmod -R g+rwX,o-rwx galaktyka_app/data
  3. bash galaktyka_app/tools/setup.sh   (kopiuje *.example do plików roboczych)
  4. Utwórz data/users.php i data/mail.php z *.example (format: store.php).
  5. Otwórz http://<serwer>/galaktyka/ (PIN 1980), potem admin.php.

PLIKI DANYCH (galaktyka_app/data/, poza gitem)
----------------------------------------------
  kafelki.config, menu.config, settings.config   tablica (JSON)
  users.php, mail.php, auth_state.php, pin.php   sekrety i stan (JSON z guardem, store.php)
  ftp.config                                     FTP dla update_galaktyka.sh
  icons/                                         ikony; serwowane TYLKO przez icon.php po PIN-ie

PIN I BEZPIECZEŃSTWO
--------------------
  - PIN: bcrypt w data/pin.php (brak pliku = 1980). Po poprawnym PIN-ie ciasteczko
    na 7 dni (na serwerze tylko sha256 tokenu). Zmiana PIN-u w adminie unieważnia wszystkie ciasteczka.
  - Limity: 5 błędów z IP = blokada 15 min; 50 błędów/dobę łącznie = PIN zablokowany
    dla wszystkich na 1 h (wydane ciasteczka działają dalej). Admin zdejmuje blokadę w panelu.
  - Nagłówki: X-Frame-Options DENY, nosniff, Referrer-Policy no-referrer, noindex.
  - Admin ma dostęp do tablicy bez PIN-u.

WDROŻENIE NA PRODUKCJĘ
-----------------------
  update_galaktyka.sh (lftp): public_html/ -> /public_html, galaktyka_app/ (bez data/ i tools/)
  -> /galaktyka_app. data/ nigdy nie jest wgrywane. Jednorazowa migracja prod jest opisana
  w komentarzu na początku skryptu (nie jest automatyczna).

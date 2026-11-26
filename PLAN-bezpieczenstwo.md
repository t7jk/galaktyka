# Galaktyka — plan zwiększenia bezpieczeństwa (2026-11-26)

## Cel
1. Tablica kafelków (index.php) dostępna dopiero po podaniu PIN-u (4 cyfry, domyślnie 1980). Po poprawnym PIN-ie ciasteczko na 7 dni.
2. PIN zmienia się w panelu admina (admin ma osobne logowanie e-mail + hasło, bez zmian).
3. Podział: w katalogu publicznym tylko cienkie punkty wejścia i statyczne pliki. Kod, dane i sekrety leżą poziom wyżej.

## Stan obecny (sprawdzony)
- Wszystko leży w katalogu publicznym: kod PHP, data/ (users.php, mail.php, auth_state.php, ftp.config, kafelki/menu/settings.config, icons/), docs/ (zrzut ekranu z kafelkami), skrypty narzędziowe, a na dev także .git.
- index.php jest dziś publiczny, bez logowania. Ochronę data/ daje tylko .htaccess (na dev Apache go ignoruje, blokada jest w dev-blokada-plikow.conf).
- Prod (FTP Hostido): root FTP = domains/galaktyka.edu.pl, więc /galaktyka_app można założyć obok /public_html. Prod ma własne kafelki, menu i konta, nowsze niż dev, i NIE wolno ich nadpisać.

## Docelowy układ
Dev odwzorowuje prod 1:1 (decyzja użytkownika):
  /var/www/galaktyka/                 (= domains/galaktyka.edu.pl/, repo git tutaj)
    public_html/                      stuby index.php, admin.php, icon.php; assets/, favicon.ico, robots.txt
    galaktyka_app/                    kod: bootstrap.php, auth.php, pin.php, store.php, mail.php, views/
    galaktyka_app/data/               dane i sekrety (poza gitem i poza deployem)
    galaktyka_app/tools/              skrypty ikon, setup.php (tylko CLI)
    docs/, update_galaktyka.sh, PLAN (nie są wgrywane)
  /var/www/html/galaktyka  → symlink do /var/www/galaktyka/public_html
Stub zawsze robi require dirname(__DIR__).'/galaktyka_app/bootstrap.php'. __DIR__ rozwiązuje symlink, więc ta sama reguła działa na dev i prod.

## PIN — projekt bezpieczeństwa
- Ryzyko: 4 cyfry to tylko 10 000 kombinacji, więc bez limitów bot zgadnie PIN w kilka minut. Dlatego:
  - limit na IP: 5 błędnych prób, potem blokada 15 min;
  - limit globalny: 50 błędnych prób na dobę, potem PIN blokuje się dla wszystkich na 1 h (ochrona przed botnetem). Ciasteczka już wydane działają dalej, więc biuro nie traci dostępu, a admin może zdjąć blokadę w panelu;
  - porównanie PIN-u: password_verify (PIN trzymany jako hash bcrypt w data/pin.php), pierwsze uruchomienie = hash 1980.
- Ciasteczko: losowy token, na serwerze zapisany tylko jego hash i data ważności (7 dni od wpisania). Flagi HttpOnly, Secure (na https), SameSite=Lax. Zmiana PIN-u unieważnia wszystkie ciasteczka.
- Zalogowany admin ma też dostęp do tablicy.
- Formularz PIN-u z CSRF, bez podpowiedzi „zły PIN” w zależności od istnienia czegokolwiek. Zalecany inputmode=numeric.
- Nagłówki na wszystkich stronach: X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy no-referrer, meta noindex.
- Ikony: serwowane przez icon.php dopiero po PIN-ie (walidacja nazwy pliku, basename + whitelist rozszerzeń). Upload ikon zapisuje do data/icons poza WWW.

## Deploy (update_galaktyka.sh)
- Dwa mirrory: public/ → /public_html, app/ + tools? (nie) → /galaktyka_app/app. data/ nigdy nie jest wgrywane.
- Jednorazowa migracja prod (osobny krok, za zgodą): kopia całego public_html do ~/backups/galaktyka/prod-YYYYMMDD; potem FTP `mv /public_html/data /galaktyka_app/data` (dane prod zostają nietknięte); wgranie nowego kodu; usunięcie z public_html starych auth.php, mail.php, store.php, docs/ i data/; test.
- Skrypt nie wypisuje hasła FTP.

## Przypadki brzegowe i ryzyka
- Prod jest nowszy niż dev (kafelki/menu): migrujemy przez mv na serwerze, nie przez upload.
- Kolejność na prod: najpierw kod w galaktyka_app, potem stuby w public_html, żeby nie było okna z błędem 500.
- Hostido może mieć open_basedir: trzeba sprawdzić, czy PHP z public_html czyta ../galaktyka_app (test plikiem przed migracją).
- Zapis plików (flock), uprawnienia data/ na dev (grupa www-data).
- Symlink na dev wymaga FollowSymLinks w Apache: sprawdzić.

## Kryterium „gotowe” na dev
Bez PIN-u nie ma kafelków ani ikon. PIN 1980 wpuszcza, a ciasteczko działa 7 dni. 6. błędna próba z IP jest blokowana. Zmiana PIN-u w adminie wylogowuje ciasteczka. Admin działa jak dotąd. Żaden plik z data/ ani app/ nie jest osiągalny przez URL.

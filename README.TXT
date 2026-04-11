================================================================
GALAKTYKA — Prywatna tablica skrótów do aplikacji webowych
================================================================

OPIS PROGRAMU
-------------
Galaktyka to prywatny, lokalny launcher (tablica kafelków) do
aplikacji i serwisów webowych. Działa jako strona startowa
w przeglądarce — wszystkie ważne usługi (banki, AI, social media,
panele adminów) dostępne w jednym miejscu jednym kliknięciem.

Napisany w PHP + vanilla JavaScript. Bez bazy danych — dane
przechowywane w plikach JSON.


WYMAGANIA SYSTEMOWE
--------------------
  - PHP 8.0 lub nowszy
  - Serwer WWW (Apache lub Nginx z obsługą PHP)
  - Dostęp przez przeglądarkę (lokalnie lub przez sieć LAN)


INSTALACJA — KROK PO KROKU
----------------------------

1. POBIERZ PLIKI
   Sklonuj repozytorium lub wypakuj archiwum:
     git clone https://github.com/[twoj-login]/galaktyka.git

2. UMIEŚĆ W KATALOGU SERWERA
   Skopiuj folder do katalogu serwera WWW, np.:
     /var/www/html/galaktyka/     (Apache/Linux)
     C:\xampp\htdocs\galaktyka\   (XAMPP/Windows)

3. UPRAWNIENIA (Linux)
   Ustaw uprawnienia zapisu do folderu data/:
     chmod -R 775 /var/www/html/galaktyka/data/
     chown -R www-data:www-data /var/www/html/galaktyka/data/

4. URUCHOM SKRYPT KONFIGURACYJNY
     bash setup.sh
   Skrypt skopiuje pliki *.example do plików .config.

5. SKONFIGURUJ DANE LOGOWANIA ADMINA
   Edytuj plik:  data/admin.config
   Zmień:        {"user":"admin","pass":"your_secret_password"}
   Na własne dane logowania (login i bezpieczne hasło).

6. SKONFIGURUJ KAFELKI (opcjonalnie)
   Edytuj ręcznie:
     data/kafelki.config  — lista kafelków (nazwa, URL, ikona)
     data/menu.config     — układ kafelków w siatce (rząd, kolumna)
   lub użyj panelu admina po uruchomieniu.

7. OTWÓRZ W PRZEGLĄDARCE
     http://<adres-serwera>/galaktyka/


PLIKI KONFIGURACYJNE
---------------------

  data/admin.config    — login i hasło admina
                         {"user":"...", "pass":"..."}

  data/kafelki.config  — lista wszystkich kafelków
                         Format: [{"id":"...", "tytul":"...",
                                   "ikona":"...", "url":"..."}]

  data/menu.config     — układ siatki (który kafelek gdzie)
                         Format: [{"id":"...", "rzad":1, "kolumna":1}]

  data/settings.config — ustawienia (liczba kolumn w siatce)
                         {"cols":10}

  data/ftp.config      — dane FTP (używane przez update_galaktyka.sh)
                         {"host":"...", "user":"...", "pass":"..."}

  data/icons/          — ikony PNG kafelków (80x80 px lub większe)


PLIKI EXAMPLE
--------------
Każdy plik konfiguracyjny ma swój szablon *.example z przykładowymi
danymi (Gmail, Outlook, Office 365). Skrypt setup.sh automatycznie
kopiuje je do plików roboczych podczas pierwszej instalacji.

Pliki *.example są bezpieczne do udostępnienia — nie zawierają
prywatnych danych.


PANEL ADMINISTRACYJNY
----------------------
Dostęp:    http://<serwer>/galaktyka/admin.php
lub kliknij kafelek "Admin" w siatce.

Funkcje:
  - Dodawanie, edytowanie i usuwanie kafelków
  - Upload własnych ikon PNG
  - Zmiana układu siatki (drag & drop lub przyciski)
  - Regulacja liczby kolumn
  - Cofanie ostatniej zmiany (Undo)


DOKUMENTACJA
-------------
Pełna dokumentacja z zrzutem ekranu dostępna lokalnie:
  http://<serwer>/galaktyka/docs/
lub kliknij kafelek "Dokumentacja" w siatce.


BEZPIECZEŃSTWO
---------------
  - Zmień domyślne hasło admina przed uruchomieniem!
  - Galaktyka przeznaczona do użytku lokalnego/LAN.
  - Nie wystawiaj na publiczny internet bez zabezpieczeń
    (HTTPS, firewall, HTTP Basic Auth na poziomie serwera).
  - Pliki *.config z prywatnymi danymi nigdy nie trafiają
    do repozytorium git (patrz .gitignore).


STRUKTURA PLIKÓW
-----------------
  galaktyka/
  ├── index.php              — widok główny
  ├── admin.php              — panel admina
  ├── setup.sh               — skrypt konfiguracyjny
  ├── README.TXT             — ten plik
  ├── .gitignore             — wykluczenia git
  ├── assets/                — biblioteki JS/CSS (cropper)
  ├── data/
  │   ├── admin.config       — dane logowania (nie w repo!)
  │   ├── kafelki.config     — kafelki (nie w repo!)
  │   ├── menu.config        — układ (nie w repo!)
  │   ├── settings.config    — ustawienia (nie w repo!)
  │   ├── ftp.config         — FTP (nie w repo!)
  │   ├── *.config.example   — szablony (w repo)
  │   └── icons/             — ikony kafelków
  └── docs/
      ├── index.html         — dokumentacja HTML
      ├── style.css          — style dokumentacji
      └── screenshot.png     — zrzut ekranu


================================================================

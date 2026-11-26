#!/usr/bin/env bash
# update_galaktyka.sh — synchronizacja Galaktyki na produkcję przez FTP
#
# Układ (dev 1:1 jak prod; root FTP = domains/galaktyka.edu.pl):
#   public_html/   -> /public_html     (stuby, assets, favicon, robots.txt)
#   galaktyka_app/ -> /galaktyka_app   (kod; BEZ data/ i tools/)
# data/ (kafelki, menu, konta, PIN, sekrety, ikony) NIGDY nie jest wgrywane - prod ma własne, nowsze dane.
#
# JEDNORAZOWA MIGRACJA PROD (stary układ -> nowy), osobny krok, tylko za zgodą właściciela. NIE jest częścią skryptu
# (opis, nic z tego nie jest wykonywane):
#   1. Kopia zapasowa całego /public_html do ~/backups/galaktyka/prod-YYYYMMDD.
#   2. Test open_basedir na Hostido: plik w public_html czytający ../galaktyka_app (czy PHP ma tam dostęp).
#   3. Przenieść dane na serwerze (nie uploadem): FTP `mkdir /galaktyka_app` i `mv /public_html/data /galaktyka_app/data`.
#   4. Wgrać KOD najpierw (galaktyka_app), potem stuby (public_html) - żeby nie było okna z błędem 500:
#      uruchomić ten skrypt (mirror 2, potem mirror 1; kolejność jest w skrypcie).
#   5. Usunąć z /public_html stare: auth.php, mail.php, store.php, setup.php, check_icons.php, convert_icons.php,
#      replace_icons.php, docs/, data/ (jeśli coś zostało), .htaccess w data.
#   6. Test: tablica bez PIN-u nie pokazuje kafelków, PIN 1980 wpuszcza, ikony przez icon.php, admin loguje się,
#      /galaktyka_app/ i /data/ dają 404. Zmienić domyślny PIN 1980 w panelu admina!
#
# Użycie:
#   ./update_galaktyka.sh          — wgrywa zmienione pliki
#   ./update_galaktyka.sh dry      — dry run, tylko pokazuje co zostanie podmienione

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="$SCRIPT_DIR/galaktyka_app/data/ftp.config"

# ── Kolory ────────────────────────────────────────────────────────────────────
R='\033[0;31m' G='\033[0;32m' Y='\033[1;33m' C='\033[0;36m' B='\033[1m' N='\033[0m'

# ── Tryb ──────────────────────────────────────────────────────────────────────
DRY=false
WIPE=false
case "${1:-}" in
  dry|dry-run|--dry-run) DRY=true ;;
  wipe|--wipe)           WIPE=true ;;
esac

# ── Konfiguracja ──────────────────────────────────────────────────────────────
if [[ ! -f "$CONFIG" ]]; then
  echo -e "${R}Błąd:${N} brak pliku konfiguracyjnego: $CONFIG"; exit 1
fi

cfg() { python3 -c "import json; d=json.load(open('$CONFIG')); print(d.get('$1',''))" 2>/dev/null; }

HOST=$(cfg host); USER=$(cfg user); PASS=$(cfg pass)
PORT=$(cfg port);  [[ -z "$PORT" ]] && PORT=21
REMOTE=$(cfg remote_path)
APP_REMOTE="$(dirname "$REMOTE")/galaktyka_app"; [[ "$APP_REMOTE" == //* ]] && APP_REMOTE="${APP_REMOTE#/}"

if [[ -z "$HOST" || -z "$USER" || -z "$PASS" || -z "$REMOTE" ]]; then
  echo -e "${R}Błąd:${N} uzupełnij pola host / user / pass / remote_path w:"
  echo "  $CONFIG"; exit 1
fi

# ── Sprawdź lftp ──────────────────────────────────────────────────────────────
if ! command -v lftp &>/dev/null; then
  echo -e "${R}Błąd:${N} brak programu 'lftp'"
  echo "  Instalacja: sudo apt install lftp"; exit 1
fi

# ── Nagłówek ──────────────────────────────────────────────────────────────────
echo -e "${B}═══ Galaktyka → Produkcja ═══${N}"
echo -e "  Host:   ${C}${HOST}:${PORT}${N}"
echo -e "  Remote: ${C}${REMOTE}${N}  +  ${C}${APP_REMOTE}${N}"
echo -e "  Lokal:  ${C}${SCRIPT_DIR}${N}"
if $DRY; then
  echo -e "  Tryb:   ${Y}DRY RUN — żadne pliki nie zostaną przesłane${N}"
elif $WIPE; then
  echo -e "  Tryb:   ${R}WIPE + UPLOAD — czyści serwer i wgrywa wszystko od nowa${N}"
else
  echo -e "  Tryb:   ${G}UPLOAD — podmienia zmienione pliki${N}"
fi
echo

# ── Flagi mirror ──────────────────────────────────────────────────────────────
FLAGS="--reverse --only-newer --verbose=1 --no-perms --no-umask"
$DRY  && FLAGS="$FLAGS --dry-run"
$WIPE && FLAGS="${FLAGS/--only-newer/} --delete"  # wipe: usuń zbędne + wymuś wszystkie pliki (pliki wykluczone nie są kasowane)

# Wykluczenia (regex lftp, względem katalogu źródłowego danego mirrora)
EXCL_PUBLIC=(
  "^\.git(/|$)" "^\.claude(/|$)" "^\.playwright-mcp(/|$)"
)
EXCL_APP=(
  "^data(/|$)"     # dane i sekrety: NIGDY na serwer (users, mail, auth_state, pin, ftp.config, kafelki, menu, ikony)
  "^tools(/|$)"    # skrypty CLI (setup, ikony) - nie na produkcję
)

excl_args() { local r; for r in "$@"; do printf " --exclude '%s'" "$r"; done; }

# ── Uruchom ───────────────────────────────────────────────────────────────────
# Dane logowania przez `open -u` w heredocu (nie w argumentach procesu, nie są nigdzie wypisywane).
# Kolejność: najpierw kod aplikacji, potem stuby - brak okna, w którym stub wskazuje na nieistniejący kod.
lftp <<LFTP_CMD
set ftp:passive-mode yes
set ssl:verify-certificate no
set net:timeout 30
set net:max-retries 3
set net:reconnect-interval-base 5
open -u '${USER}','${PASS}' -p ${PORT} ${HOST}
mirror ${FLAGS}$(excl_args "${EXCL_APP[@]}") "${SCRIPT_DIR}/galaktyka_app" "${APP_REMOTE}"
mirror ${FLAGS}$(excl_args "${EXCL_PUBLIC[@]}") "${SCRIPT_DIR}/public_html" "${REMOTE}"
quit
LFTP_CMD

echo
if $DRY; then
  echo -e "${Y}Dry run zakończony — żadne pliki nie zostały przesłane.${N}"
elif $WIPE; then
  echo -e "${G}Wipe + upload zakończony.${N}"
else
  echo -e "${G}Synchronizacja zakończona.${N}"
fi

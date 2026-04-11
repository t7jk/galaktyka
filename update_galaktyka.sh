#!/usr/bin/env bash
# update_galaktyka.sh — synchronizacja Galaktyki na produkcję przez FTP
#
# Użycie:
#   ./update_galaktyka.sh          — wgrywa zmienione pliki
#   ./update_galaktyka.sh dry      — dry run, tylko pokazuje co zostanie podmienione

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="$SCRIPT_DIR/data/ftp.config"

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
echo -e "  Remote: ${C}${REMOTE}${N}"
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
$WIPE && FLAGS="${FLAGS/--only-newer/} --delete"  # wipe: usuń zbędne + wymuś wszystkie pliki

# Pliki wykluczone z synchronizacji (regex dla lftp --exclude)
# Katalogi: wzorzec z / na końcu wyklucza katalog i całą jego zawartość
EXCL_REGEX=(
  "^data/ftp\.config$"       # dane logowania — nigdy nie wgrywaj
  "^data/undo\.json$"        # tymczasowy snapshot undo
  "^\.git(/|$)"              # repozytorium git — NIE na serwer!
  "^\.claude(/|$)"
  "^\.playwright-mcp(/|$)"
  "^setup\.php$"             # skrypt jednorazowy
  "^convert_icons\.php$"     # skrypt jednorazowy
  "^update_galaktyka\.sh$"   # sam skrypt
  "^check_icons\.php$"
  "^replace_icons\.php$"
  "^research(/|$)"
  "^wiki(/|$)"
  "^nocowanka-current\.jpeg$"
  "^README\.TXT$"
  "^setup\.sh$"
  "^\.gitignore$"
)

# Zbuduj argumenty --exclude dla lftp (regex)
EXCL_ARGS=""
for r in "${EXCL_REGEX[@]}"; do
  EXCL_ARGS+=" --exclude='${r}'"
done

# ── Uruchom ───────────────────────────────────────────────────────────────────
eval lftp -u "'${USER}','${PASS}'" "'ftp://${HOST}:${PORT}'" <<LFTP_CMD
set ftp:passive-mode yes
set ssl:verify-certificate no
set net:timeout 30
set net:max-retries 3
set net:reconnect-interval-base 5
mirror ${FLAGS} ${EXCL_ARGS} "${SCRIPT_DIR}" "${REMOTE}"
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

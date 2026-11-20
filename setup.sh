#!/bin/bash
# setup.sh — Konfiguracja nowej instalacji Galaktyki
# Kopiuje pliki *.example do plików konfiguracyjnych roboczych.
# Uruchom: bash setup.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DATA="$SCRIPT_DIR/data"

echo "=== Galaktyka — setup nowej instalacji ==="
echo ""

EXAMPLES=(
  "admin.config"
  "kafelki.config"
  "menu.config"
  "settings.config"
  "ftp.config"
)

for name in "${EXAMPLES[@]}"; do
  src="$DATA/$name.example"
  dst="$DATA/$name"
  if [ ! -f "$src" ]; then
    echo "  POMINIĘTO: $name.example nie istnieje"
    continue
  fi
  if [ -f "$dst" ]; then
    echo "  ISTNIEJE:  $name — pominięto (nie nadpisano)"
  else
    cp "$src" "$dst"
    echo "  UTWORZONO: $name"
  fi
done

echo ""
echo "=== Gotowe! Kolejne kroki: ==="
echo "  1. Skopiuj data/users.php.example i data/mail.php.example do data/users.php i data/mail.php (JSON po linii-guardzie, patrz store.php); admin.config jest już nieużywany"
echo "  2. Edytuj data/kafelki.config — dodaj własne kafelki"
echo "  3. Edytuj data/menu.config    — ustaw układ kafelków"
echo "  4. Otwórz http://<serwer>/galaktyka/ w przeglądarce"
echo ""
echo "  Szczegóły w README.TXT"

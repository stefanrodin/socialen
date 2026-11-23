#!/usr/bin/env bash
set -euo pipefail
PUBLICERA_PROJECT_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PUBLICERA_PROJECT_ROOT"
if [[ ! -t 0 ]]; then
  printf '%s\n' 'Kör skriptet i din interaktiva SSH-terminal.' >&2
  exit 1
fi
IFS= read -r -p 'Ditt namn: ' publicera_admin_name
IFS= read -r -p 'E-post för inloggning: ' publicera_admin_email
IFS= read -r -s -p 'Lösenord (minst 12 tecken, visas inte): ' publicera_admin_password
printf '\n'
IFS= read -r -s -p 'Upprepa lösenordet: ' publicera_admin_repeat
printf '\n'
printf '%s\n' "$publicera_admin_name" "$publicera_admin_email" "$publicera_admin_password" "$publicera_admin_repeat" | php bin/install.php --stdin
unset publicera_admin_password publicera_admin_repeat

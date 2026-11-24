#!/usr/bin/env bash
set -euo pipefail
PUBLICERA_PROJECT_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PUBLICERA_PROJECT_ROOT"
if [[ ! -t 0 ]]; then
  printf '%s\n' 'Kör skriptet i din interaktiva SSH-terminal.' >&2
  exit 1
fi
publicera_meta_app_id=${1:-}
publicera_meta_config_id=${2:-}
if [[ -z "$publicera_meta_app_id" ]]; then IFS= read -r -p 'Meta App ID: ' publicera_meta_app_id; fi
if [[ -z "$publicera_meta_config_id" ]]; then IFS= read -r -p 'Login Configuration ID (tomt om det saknas): ' publicera_meta_config_id; fi
IFS= read -r -s -p 'Meta App secret (visas inte): ' publicera_meta_secret
printf '\n'
printf '%s\n' "$publicera_meta_app_id" "$publicera_meta_secret" "$publicera_meta_config_id" | php bin/configure-meta.php
unset publicera_meta_secret

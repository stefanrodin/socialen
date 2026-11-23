#!/bin/sh
set -eu
PUBLICERA_PROJECT_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PUBLICERA_PROJECT_ROOT"
PUBLICERA_DEMO=1 exec php -S 127.0.0.1:8098 -t public

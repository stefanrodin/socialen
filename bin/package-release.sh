#!/usr/bin/env bash
set -euo pipefail
output=${1:?Usage: bash bin/package-release.sh /absolute/path/release.tar.gz}
[[ "$output" = /* ]] || { echo 'Output must be an absolute path.' >&2; exit 1; }
root=$(cd -- "$(dirname -- "$0")/.." && pwd)
revision=$(git -C "$root" rev-parse HEAD)
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
# Only committed application files enter a release; never config.php or storage.
git -C "$root" archive "$revision" src public bin config.example.php README.md | tar -xf - -C "$stage"
printf '%s\n' "$revision" > "$stage/REVISION"
tar -czf "$output" -C "$stage" src public bin config.example.php README.md REVISION
echo "Packaged $revision"

#!/usr/bin/env bash
# Run once from the existing Inleed SSH session. Does not install software.
set -euo pipefail
umask 077
source_root=$(cd -- "$(dirname -- "$0")/.." && pwd)
root=/home/rondit/domains/socialen.rondit.se
[[ $(id -un) == rondit && -f "$root/config.php" && -d "$root/storage" ]] || { echo 'Wrong account or missing Socialen installation.' >&2; exit 1; }
for command in flock tar curl php; do command -v "$command" >/dev/null; done
mkdir -p "$root/deploy" "$root/releases" "$HOME/backups"
chmod 700 "$root/deploy"
chmod 755 "$root/releases"
exec 9>"$root/deploy/deploy.lock"
flock -w 90 9
exec 8>"$root/storage/worker.lock"
flock -w 90 8
backup=$(mktemp -d "$HOME/backups/socialen-autodeploy.XXXXXXXX")
if [[ ! -L "$root/current" ]]; then
  [[ ! -e "$root/current" && ! -L "$root/public_html" && ! -L "$root/src" && ! -L "$root/bin" ]] || { echo 'Unexpected layout; no files changed.' >&2; exit 1; }
  initial=$(mktemp -d "$root/releases/bootstrap.XXXXXXXX")
  chmod 755 "$initial"
  cp -a "$root/public_html" "$initial/public"
  cp -a "$root/src" "$initial/src"
  cp -a "$root/bin" "$initial/bin"
  ln -s "$root/config.php" "$initial/config.php"
  ln -s "$root/storage" "$initial/storage"
  printf 'bootstrap\n' > "$initial/REVISION"
  ln -s "$initial" "$root/current"
  moved=()
  restore_initial() {
    status=$?
    if (( status != 0 )); then
      for name in "${moved[@]}"; do
        [[ ! -L "$root/$name" ]] || rm -- "$root/$name"
        mv -- "$backup/$name" "$root/$name"
      done
      rm -- "$root/current"
    fi
    exit "$status"
  }
  trap restore_initial EXIT
  for name in public_html src bin; do
    target=$name
    [[ "$name" != public_html ]] || target=public
    mv -- "$root/$name" "$backup/$name"
    moved+=("$name")
    ln -s "$root/current/$target" "$root/$name"
  done
fi
for name in inleed-receive.sh hosting.htaccess; do
  [[ ! -e "$root/deploy/$name" ]] || cp -p "$root/deploy/$name" "$backup/$name"
done
cp "$source_root/deploy/inleed-receive.sh" "$root/deploy/inleed-receive.sh.next"
chmod 700 "$root/deploy/inleed-receive.sh.next"
mv -f "$root/deploy/inleed-receive.sh.next" "$root/deploy/inleed-receive.sh"
cp "$source_root/deploy/inleed.htaccess" "$root/deploy/hosting.htaccess"
mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"
authorized="$HOME/.ssh/authorized_keys"
public_key=$(cat "$source_root/deploy/actions_ed25519.pub")
key_body=$(printf '%s\n' "$public_key" | awk '{print $2}')
if ! grep -qF "$key_body" "$authorized" 2>/dev/null; then
  [[ ! -f "$authorized" ]] || cp -p "$authorized" "$backup/authorized_keys"
  printf '\nrestrict,command="/bin/bash %s/deploy/inleed-receive.sh %s /usr/local/php81/bin/php https://socialen.rondit.se socialen.rondit.se:443:185.189.48.105" %s\n' "$root" "$root" "$public_key" >> "$authorized"
fi
chmod 600 "$authorized"
/usr/local/php81/bin/php -r 'require $argv[1]."/src/bootstrap.php"; app()->query("SELECT 1"); echo "Database OK\n";' "$root"
curl --resolve socialen.rondit.se:443:185.189.48.105 --fail --silent --show-error --max-time 20 -o /dev/null https://socialen.rondit.se/
printf 'Autodeploy ready. Backup: %s\n' "$backup"

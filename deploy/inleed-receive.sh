#!/usr/bin/env bash
# Installed outside the served release and invoked by an SSH forced command.
set -euo pipefail
umask 022
root=${1:?Application root is required}
php=${2:?PHP binary is required}
health_url=${3:?Health URL is required}
resolve=${4:-}
[[ ${SSH_ORIGINAL_COMMAND:-} == deploy ]] || { echo 'Only deploy is allowed.' >&2; exit 1; }
[[ -L "$root/current" && -L "$root/public_html" && -f "$root/config.php" && -d "$root/storage" ]] || {
  echo 'Initialize the release layout before deploying.' >&2; exit 1;
}
exec 9>"$root/deploy/deploy.lock"
flock -w 90 9
release=$(mktemp -d "$root/releases/release.XXXXXXXX")
previous=$(readlink "$root/current")
activated=0
completed=0
cleanup() {
  status=$?
  trap - EXIT
  if (( activated && !completed )); then
    ln -s -- "$previous" "$root/.current.rollback.$$"
    mv -Tf -- "$root/.current.rollback.$$" "$root/current"
    echo 'Health check failed; previous release restored.' >&2
  fi
  if (( !completed && !activated )); then rm -rf -- "$release"; fi
  exit "$status"
}
trap cleanup EXIT
# Input is the allowlisted archive built by bin/package-release.sh.
tar -xzf - -C "$release" --no-same-owner --no-same-permissions
# The staging archive must not make the release directory private to the SSH user.
chmod 755 "$release"
revision=$(cat "$release/REVISION")
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid revision.' >&2; exit 1; }
[[ ! -e "$release/config.php" && ! -e "$release/storage" ]] || { echo 'Release contains private runtime files.' >&2; exit 1; }
for file in public/index.php public/api.php src/bootstrap.php bin/worker.php; do
  [[ -f "$release/$file" ]] || { echo "Missing $file" >&2; exit 1; }
done
find "$release" -type f -name '*.php' -print0 |
  while IFS= read -r -d '' file; do "$php" -l "$file" >/dev/null; done
ln -s -- "$root/config.php" "$release/config.php"
ln -s -- "$root/storage" "$release/storage"
cat "$root/deploy/hosting.htaccess" >> "$release/public/.htaccess"
printf '%s\n' "$revision" > "$release/public/deploy-revision.txt"
# Do not run migrations or change credentials during an automatic code deployment.
"$php" -r 'require $argv[1]."/src/bootstrap.php"; $a=app(); $a->query("SELECT 1"); if (($a->one("SELECT setting_value FROM settings WHERE setting_key=?",["schema_version"])["setting_value"]??null)!=="1") {fwrite(STDERR,"Unsupported schema.\n"); exit(1);}' "$release"
# Use the same lock as the publisher, so a publishing job completes before switching code.
exec 8>"$root/storage/worker.lock"
flock -w 90 8
ln -s -- "$release" "$root/.current.next.$$"
mv -Tf -- "$root/.current.next.$$" "$root/current"
activated=1
curl_options=(--fail --silent --show-error --connect-timeout 10 --max-time 20 --retry 2 --retry-delay 2)
if [[ -n "$resolve" ]]; then curl_options+=(--resolve "$resolve"); fi
actual=$(curl "${curl_options[@]}" "$health_url/deploy-revision.txt?revision=$revision")
[[ "$actual" == "$revision" ]] || { echo 'Served revision does not match.' >&2; exit 1; }
bootstrap=$(curl "${curl_options[@]}" "$health_url/api.php?action=bootstrap")
printf '%s' "$bootstrap" | "$php" -r '$d=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR); if (!is_array($d)||!array_key_exists("user",$d)||$d["user"]!==null||($d["demo"]??true)!==false||($d["revision"]??null)!==$argv[1]) {fwrite(STDERR,"API health check failed; PHP revision does not match or response is unhealthy.\n"); exit(1);}' "$revision"
completed=1
printf 'Deployed %s; previous release: %s\n' "$revision" "$previous"
# Releases are retained for manual rollback; this script never prunes backups.

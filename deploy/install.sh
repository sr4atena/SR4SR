#!/usr/bin/env bash
# Install or update Manor Ledger on the VPS. Run from the repo root on the
# development machine. Idempotent: safe to re-run after every change.
#
#   deploy/install.sh                 # code + config + services
#   deploy/install.sh --with-data     # also push data/history.json + snapshots (first deploy)
#   deploy/install.sh --api-key FILE  # also install the Roblox key (first deploy)
#   deploy/install.sh --api-key FILE --game colorblind
#                                     # the key of another game (config/app.php `games`)
#
# The host runs other services that were there first. This script touches none
# of them: no firewall rule, no unit it did not install itself, nothing on ports
# 80/443. The only shared component it edits is /etc/cloudflared/config.yml
# (adds one hostname), and only when the rule is missing.
set -euo pipefail
cd "$(dirname "$0")/.."

# The server address is deliberately not in the repository: the origin sits
# behind a Cloudflare Tunnel precisely so that it is never published. It comes
# from the environment or from the git-ignored deploy/deploy.local.env.
_root="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$_root/deploy/deploy.local.env" ] && . "$_root/deploy/deploy.local.env"
VPS="${MANOR_VPS:?set MANOR_VPS=user@host in the environment or in deploy/deploy.local.env}"
KEY="${MANOR_SSH_KEY:-$HOME/.ssh/id_ed25519}"
HOST="${MANOR_HOST:-manor.handgivers.it}"
APP_DIR=/opt/manor-ledger
DATA_DIR=/var/lib/manor-ledger

WITH_DATA=0; API_KEY_FILE=""; GAME=""
while [ $# -gt 0 ]; do
  case "$1" in
    --with-data) WITH_DATA=1 ;;
    --api-key) API_KEY_FILE="$2"; shift ;;
    --game) GAME="${2:-}"; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
  shift
done
# Each game reads its key from its own file, all in /etc/manor-ledger: the
# default game keeps the original name, any other gets a suffix, and the
# refresh unit passes each path to the job (MANOR_API_KEY_FILE_<SLUG>).
# The slug travels into a privileged shell, so it is checked here, with the
# same rule the application applies to slugs.
KEY_NAME=api-key
if [ -n "$GAME" ]; then
  [ -n "$API_KEY_FILE" ] || { echo "--game only goes with --api-key" >&2; exit 2; }
  [[ "$GAME" =~ ^[a-z0-9][a-z0-9-]{0,31}$ ]] || { echo "invalid game slug: $GAME" >&2; exit 2; }
  grep -Eq "^    '$GAME' => \[" config/app.php || { echo "game $GAME is not in config/app.php" >&2; exit 2; }
  [ "$GAME" = locust ] || KEY_NAME="api-key-$GAME"
fi

SSH=(ssh -i "$KEY" -o BatchMode=yes "$VPS")
RSYNC_SSH="ssh -i $KEY -o BatchMode=yes"

# The code, the key and the data used to be staged in /tmp, which is
# world-writable: any local user on this shared host could swap a file or plant
# a symlink between the upload and the privileged install that reads it. The
# remote now makes a private directory of its own — mode 700, under /var/tmp —
# and we check the name against the template before it travels through
# "ssh sudo", so nothing unvalidated ever reaches a privileged shell.
STAGE=$("${SSH[@]}" 'umask 077 && mktemp -d /var/tmp/manor-stage.XXXXXX')
case "$STAGE" in
  /var/tmp/manor-stage.??????) ;;
  *) echo "unexpected staging directory from the server: $STAGE" >&2; exit 1 ;;
esac
cleanup() { "${SSH[@]}" "rm -rf -- '$STAGE'" >/dev/null 2>&1; return 0; }
trap cleanup EXIT

echo "→ syncing code to $VPS:$STAGE/code"
rsync -az --delete --no-links -e "$RSYNC_SSH" \
  --exclude '/.git' --exclude '/data' --exclude '/vendor' --exclude '/.phpunit.cache' \
  --exclude '/tests' --exclude 'composer.lock' \
  ./ "$VPS:$STAGE/code/"

if [ -n "$API_KEY_FILE" ]; then
  echo "→ uploading Roblox API key (${GAME:-locust} → /etc/manor-ledger/$KEY_NAME)"
  rsync -az --chmod=600 --no-links -e "$RSYNC_SSH" "$API_KEY_FILE" "$VPS:$STAGE/api-key"
fi
if [ "$WITH_DATA" = 1 ]; then
  echo "→ uploading history and snapshots"
  rsync -az --no-links -e "$RSYNC_SSH" data/history.json data/snapshots "$VPS:$STAGE/data/"
fi

echo "→ installing on the VPS"
"${SSH[@]}" "sudo HOST='$HOST' APP_DIR='$APP_DIR' DATA_DIR='$DATA_DIR' STAGE='$STAGE' KEY_NAME='$KEY_NAME' bash -s" <<'REMOTE'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

# Everything below installs from $STAGE. It was uploaded with --no-links, so a
# link anywhere in it is something that appeared afterwards: refuse the lot.
[ -d "$STAGE" ] && [ ! -L "$STAGE" ] || { echo "the staging directory is not a directory" >&2; exit 1; }
[ -z "$(find "$STAGE" -type l -print -quit)" ] || { echo "refusing to install from a tree containing symlinks" >&2; exit 1; }

# 1. Packages (nginx-light has everything we need and half the footprint).
need=(nginx-light php8.3-fpm php8.3-cli php8.3-curl php8.3-mbstring)
missing=()
for p in "${need[@]}"; do dpkg -s "$p" >/dev/null 2>&1 || missing+=("$p"); done
if [ ${#missing[@]} -gt 0 ]; then
  apt-get update -qq
  apt-get install -y -qq --no-install-recommends "${missing[@]}"
fi
# nginx must never listen on 80/443 here: those ports belong to another service.
rm -f /etc/nginx/sites-enabled/default

# 2. Unprivileged service user + directories.
id manor >/dev/null 2>&1 || useradd --system --home-dir "$DATA_DIR" --shell /usr/sbin/nologin manor
# The job user owns the data directory, the web pool only belongs to its group:
# manor-fetch must be able to CREATE files there (ads.json, a first history),
# not just rewrite the ones it already has, and the setgid bit hands every new
# file to group manor, which is all php-fpm needs to read the built dashboard.
id manor-fetch >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin -G manor manor-fetch
install -d -m 2750 -o manor-fetch -g manor "$DATA_DIR" "$DATA_DIR"/{cache,snapshots}
chown manor-fetch:manor "$DATA_DIR" "$DATA_DIR"/{cache,snapshots}
chmod 2750 "$DATA_DIR" "$DATA_DIR"/{cache,snapshots}
install -d -m 750 -o manor -g manor "$DATA_DIR"/{sessions,throttle}
install -d -m 750 -o root -g manor /etc/manor-ledger
install -d -m 755 -o root -g root /var/log/php
touch /var/log/php/manor-ledger.log && chown manor:manor /var/log/php/manor-ledger.log && chmod 640 /var/log/php/manor-ledger.log

# 3. Code: root-owned, read-only for the service user.
install -d -m 755 "$APP_DIR"
rsync -a --delete --chown=root:root "$STAGE/code/" "$APP_DIR/"
find "$APP_DIR" -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
chmod 755 "$APP_DIR"/bin/*

# 3b. One data directory per additional game, same policy as the main one
# (job owns, web group reads, setgid). The default game is the one whose data
# dir is the root itself; every other slug in config/app.php gets
# $DATA_DIR/games/<slug>. Nothing is moved and nothing is ever removed.
install -d -m 2750 -o manor-fetch -g manor "$DATA_DIR/games"
chown manor-fetch:manor "$DATA_DIR/games"
chmod 2750 "$DATA_DIR/games"
# Assigned first so that set -e stops the install if the config does not load.
game_slugs=$(MANOR_DATA_DIR="$DATA_DIR" php -r '
  $c = require $argv[1];
  foreach ($c["games"] ?? [] as $slug => $game) {
      if (($game["paths"]["data"] ?? "") !== $c["paths"]["data"]) { echo $slug, "\n"; }
  }' "$APP_DIR/config/app.php")
for slug in $game_slugs; do
  [[ "$slug" =~ ^[a-z0-9][a-z0-9-]{0,31}$ ]] || { echo "skipping invalid game slug: $slug" >&2; continue; }
  dir="$DATA_DIR/games/$slug"
  install -d -m 2750 -o manor-fetch -g manor "$dir" "$dir"/{cache,snapshots}
  chown manor-fetch:manor "$dir" "$dir"/{cache,snapshots}
  chmod 2750 "$dir" "$dir"/{cache,snapshots}
done

# 4. Secrets and data (only when uploaded).
[[ "$KEY_NAME" =~ ^api-key(-[a-z0-9][a-z0-9-]{0,31})?$ ]] || { echo "unexpected key name: $KEY_NAME" >&2; exit 1; }
if [ -f "$STAGE/api-key" ]; then
  # Only the refresh job (manor-fetch) reads the key: the web pool (manor) can
  # list the directory but never open the file.
  install -m 640 -o root -g manor-fetch "$STAGE/api-key" "/etc/manor-ledger/$KEY_NAME"
  shred -u "$STAGE/api-key"
fi
if [ -d "$STAGE/data" ]; then
  [ -f "$STAGE/data/history.json" ] && install -m 600 -o manor-fetch -g manor "$STAGE/data/history.json" "$DATA_DIR/history.json"
  if [ -d "$STAGE/data/snapshots" ]; then
    rsync -a --chown=manor-fetch:manor "$STAGE/data/snapshots/" "$DATA_DIR/snapshots/"
    chmod 600 "$DATA_DIR"/snapshots/* 2>/dev/null || true
  fi
fi

# 5. php-fpm pool, nginx site, systemd timer.
install -m 644 "$APP_DIR/deploy/php-fpm/manor-ledger.conf" /etc/php/8.3/fpm/pool.d/manor-ledger.conf
install -m 644 "$APP_DIR/deploy/nginx/manor-ledger.conf" /etc/nginx/sites-available/manor-ledger.conf
install -m 644 "$APP_DIR/deploy/nginx/manor-ledger-fastcgi.conf" /etc/nginx/snippets/manor-ledger-fastcgi.conf
ln -sfn /etc/nginx/sites-available/manor-ledger.conf /etc/nginx/sites-enabled/manor-ledger.conf
install -m 644 "$APP_DIR/deploy/systemd/manor-ledger-refresh.service" /etc/systemd/system/
install -m 644 "$APP_DIR/deploy/systemd/manor-ledger-refresh.timer" /etc/systemd/system/
systemctl daemon-reload
php-fpm8.3 -t >/dev/null
nginx -t >/dev/null
systemctl enable --now php8.3-fpm nginx >/dev/null
systemctl reload php8.3-fpm
systemctl reload nginx
systemctl enable --now manor-ledger-refresh.timer >/dev/null

# 6. Cloudflare Tunnel: add the hostname once, never rewrite the rest.
CF=/etc/cloudflared/config.yml
if ! grep -q "hostname: $HOST" "$CF"; then
  cp -p "$CF" "$CF.bak-$(date +%Y%m%d%H%M%S)"
  python3 - "$CF" "$HOST" <<'PY'
import sys
path, host = sys.argv[1], sys.argv[2]
lines = open(path).read().splitlines()
idx = max(i for i, l in enumerate(lines) if l.strip() == '- service: http_status:404')
block = [
    f"  # Manor Ledger dashboard (nginx on loopback, app-level login).",
    f"  - hostname: {host}",
    f"    service: http://127.0.0.1:8090",
    f"    originRequest:",
    f"      httpHostHeader: {host}",
]
lines[idx:idx] = block
open(path, 'w').write("\n".join(lines) + "\n")
PY
  cloudflared --config "$CF" tunnel ingress validate
  systemctl restart cloudflared
  echo "cloudflared: ingress for $HOST added and service restarted"
fi

# 7. sshd hardening drop-in, validated before reload so a typo cannot lock us out.
if ! cmp -s "$APP_DIR/deploy/sshd-hardening.conf" /etc/ssh/sshd_config.d/70-manor-hardening.conf 2>/dev/null; then
  install -m 644 "$APP_DIR/deploy/sshd-hardening.conf" /etc/ssh/sshd_config.d/70-manor-hardening.conf
  if sshd -t; then systemctl reload ssh; echo "sshd: hardening applied"; else rm -f /etc/ssh/sshd_config.d/70-manor-hardening.conf; echo "sshd: config rejected, drop-in removed" >&2; fi
fi

rm -rf "$STAGE"

echo "ok: $(php -v | head -1)"
systemctl is-active nginx php8.3-fpm cloudflared manor-ledger-refresh.timer | paste -sd' '
REMOTE

echo "→ publishing DNS route (no-op if it already exists)"
"${SSH[@]}" "cloudflared tunnel route dns \$(sudo awk '/^tunnel:/{print \$2}' /etc/cloudflared/config.yml) $HOST" 2>&1 | tail -1 || true
echo "done: https://$HOST"

# Deploy

Target: Ubuntu 24.04 on an Oracle Cloud *Always Free* `VM.Standard.E2.1.Micro`
(2 vCPU, 1 GB RAM) that already hosts other services. The design goal is to
add the dashboard **without changing the host's exposure**: no new open port,
no service on 80/443, no reboot.

## Topology

```
browser ──TLS──► Cloudflare edge ──tunnel──► cloudflared (host) ──► nginx 127.0.0.1:8090
                                                                        │ unix socket
                                                                   php-fpm pool "manor-ledger" (user manor)
                                                                        │ reads
                                                                   /var/lib/manor-ledger/dashboard.json

systemd timer 07:00 Europe/Rome ─► bin/refresh --all (user manor-fetch, keys in /etc/manor-ledger) ─► bin/build --all
```

| Path on the host | Owner / mode | Content |
|---|---|---|
| `/opt/manor-ledger` | root, 755 / 644 | the repository (no `data/`, no tests) |
| `/var/lib/manor-ledger` | manor-fetch:manor, 2750 | cache, snapshots, history, dashboard (the web pool reads, never writes) |
| `/var/lib/manor-ledger/web` | manor:manor, 700 | state the web pool owns: sessions, throttle, users, audit log |
| `/var/lib/manor-ledger/games/<slug>` | manor-fetch:manor, 2750 | the same files for every game after the first (cache, snapshots, history, dashboard) |
| `/etc/manor-ledger/api-key` | root:manor-fetch, 640 | Roblox Open Cloud key (read scope) |
| `/etc/manor-ledger/api-key-<slug>` | as above | the key of another game (e.g. `api-key-colorblind`) |
| `/etc/nginx/sites-available/manor-ledger.conf` | root | loopback-only site |
| `/etc/php/8.3/fpm/pool.d/manor-ledger.conf` | root | dedicated pool |
| `/etc/systemd/system/manor-ledger-refresh.{service,timer}` | root | daily job |
| `/etc/cloudflared/config.yml` | root | one added `hostname:` rule |

## First deployment

```bash
# from the repo root on the dev machine (needs ssh access as ubuntu)
deploy/install.sh --api-key ~/secrets/roblox-analytics.key --with-data
ssh ubuntu@<vps> sudo -u manor MANOR_DATA_DIR=/var/lib/manor-ledger php /opt/manor-ledger/bin/user add <owner> --role=owner
ssh ubuntu@<vps> sudo systemctl start manor-ledger-refresh.service   # first build now, don't wait for 07:00
```

`--with-data` uploads `data/history.json` and `data/snapshots/` so the
dashboard starts with the history already collected elsewhere instead of an
empty month. `install.sh` publishes the DNS record through `cloudflared
tunnel route dns`; DNS propagation at Cloudflare is immediate.

## Games

The dashboard follows every game in the `games` map of `config/app.php`; the
header shows a selector when there is more than one. The first game (The
Locust's Manor, slug `locust`) keeps the original layout at the root of the
data directory, so its production data never moved; every other game lives
under `/var/lib/manor-ledger/games/<slug>/`. The refresh unit runs
`bin/refresh --all` and `bin/build --all`: games in turn, the default first,
one game's failure never skipping another's build.

Adding a game:

1. add an entry to `games` in `config/app.php` (name, `universeId`,
   `royaltyShare`, `voices`/`ads` on or off, paths under `$gameDir('<slug>')`,
   key path from `MANOR_API_KEY_FILE_<SLUG>`);
2. add `Environment=MANOR_API_KEY_FILE_<SLUG>=/etc/manor-ledger/api-key-<slug>`
   to `deploy/systemd/manor-ledger-refresh.service`;
3. `make check && deploy/install.sh --api-key /path/to/key --game <slug>`:
   creates `games/<slug>/{cache,snapshots}` and installs the key;
4. first data now rather than at 07:00:
   `ssh ubuntu@<vps> sudo systemctl start manor-ledger-refresh.service`.

Until a game has its key and a first history, the unit is marked failed
(`bin/build --all` exits non-zero for it) while the other games are still
refreshed and built. One game by hand:

```bash
sudo -u manor-fetch MANOR_DATA_DIR=/var/lib/manor-ledger MANOR_API_KEY_FILE_COLORBLIND=/etc/manor-ledger/api-key-colorblind \
  php /opt/manor-ledger/bin/refresh --game=colorblind
sudo -u manor-fetch MANOR_DATA_DIR=/var/lib/manor-ledger php /opt/manor-ledger/bin/build --game=colorblind
```

## Updates

```bash
make check && deploy/install.sh
```

The script re-syncs the code, re-installs configs only if they changed, and
reloads nginx / php-fpm (no downtime). It never touches the data directory.

## Operations

```bash
systemctl list-timers manor-ledger-refresh.timer         # next run
journalctl -u manor-ledger-refresh.service -n 50         # last job log
sudo -u manor MANOR_DATA_DIR=/var/lib/manor-ledger php /opt/manor-ledger/bin/user list   # MANOR_STATE_DIR defaults to the data dir
tail -f /var/lib/manor-ledger/web/auth.log                   # logins, lockouts
tail -f /var/log/nginx/manor-ledger.access.log
```

The refresh unit is idempotent: `--if-older-than=8` makes the noon retry a
no-op when the morning run succeeded, and a manual `systemctl start` is
always safe. Each game has its own lock (`refresh.lock` in its data
directory) and all of them share one rate-limit window file
(`cache/.budget.json`), since the quota belongs to the owner, not the key.

### Importing the advertising spend

Ads Manager has no API, so the Ads view's costs come from an export downloaded
by hand. Copy the zip to the VPS and import it as the job user that owns the
data directory, then rebuild:

```bash
scp RobloxAdsReport_*.zip ubuntu@vps:/tmp/
sudo -u manor-fetch MANOR_DATA_DIR=/var/lib/manor-ledger php /opt/manor-ledger/bin/ads-import /tmp/RobloxAdsReport_*.zip
sudo -u manor-fetch MANOR_DATA_DIR=/var/lib/manor-ledger php /opt/manor-ledger/bin/build
```

The ledger is replaced at every import and the next scheduled `bin/build`
picks it up on its own; until the first import the view shows its sources and
leaves the cost and return cards empty.

## Shared-host guard rails

This machine is not the dashboard's alone. Other services were there first,
they own ports 80 and 443, and which one holds a port at any given moment is
not something a deploy may assume. The installer therefore:

- never installs anything on 80/443 and removes nginx's `default` site;
- edits `/etc/cloudflared/config.yml` only to *add* one hostname (with a
  timestamped backup and `cloudflared tunnel ingress validate` before restart);
- touches no firewall rule and no unit it did not install itself;
- applies the sshd hardening drop-in only after `sshd -t` accepts it, and
  reloads (not restarts) sshd.

## Rollback

`install.sh` keeps the previous cloudflared config as `config.yml.bak-<ts>`.
To remove the dashboard entirely:

```bash
sudo systemctl disable --now manor-ledger-refresh.timer
sudo rm /etc/nginx/sites-enabled/manor-ledger.conf /etc/php/8.3/fpm/pool.d/manor-ledger.conf
sudo systemctl reload nginx php8.3-fpm
# delete the manor.handgivers.it rule from /etc/cloudflared/config.yml, then: sudo systemctl restart cloudflared
```

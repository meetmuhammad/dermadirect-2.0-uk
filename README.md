# DermaDirect UK — Local Copy (Docker)

Local-only Docker Compose environment for a Bedrock/Sage WordPress site, intended to be
populated from a source snapshot (database dump + `web/app` files). This scaffold does not
contain any site content, live credentials, or a connection to any hosted server — it is
infrastructure only, to be filled in locally.

## Safety boundaries

- **No live/legacy access.** This stack never talks to any hosted server. `web`, `db`,
  `wpcli`, and `mailpit` run on a Docker network with `internal: true`, which Docker enforces
  at the network level — outbound requests from those containers are dropped.
- **Controlled dependency downloads only.** `composer` and `node` run under the `deps` Compose
  profile on a separate bridge network with internet access, but that network has no route to
  `db`/`web`/`mailpit`. Dependencies are only fetched when you explicitly run a `deps` command
  (`scripts/theme-build.sh`, `docker compose run --rm composer ...`) — never during normal
  `docker compose up`.
- **No live credentials.** `.env.example` contains placeholders only. Generate fresh local-only
  DB passwords and WordPress salts; never reuse production secrets.
- **Defense in depth at the application layer.** `local/mu-plugins/000-local-safety.php.example`
  (copy into `web/app/mu-plugins/` after import) disables WP-Cron and Action Scheduler, blocks
  outbound HTTP requests to anything but this local stack, routes mail through Mailpit, and
  disables wordpress.org update checks — so even code that ignores the network boundary can't
  reach out.
- **All ports are bound to `127.0.0.1`.** Nothing here is reachable from other machines on your
  network.
- **Ingress is a dedicated, code-free proxy container, not a network change.** Because the
  `internal` network has no gateway, `web`/`mailpit` publishing host ports directly wouldn't
  actually be reachable from this host (see "Reaching the site" below). Instead, a `proxy`
  service (plain `nginx:alpine`, config at `docker/nginx/nginx.conf`) is the only container that
  also joins a second, non-internal bridge network (`ingress`), and publishes
  `127.0.0.1:8088:80` -> `web:80` / `127.0.0.1:18025:8025` -> `mailpit:8025` from there. It mounts
  only its own nginx config — never the application or WordPress code — and does not grant
  `web`/`db`/`mailpit`/`wpcli` any outbound access; they stay on `internal` only, and the
  `internal: true` egress block on them is untouched. `scripts/host-proxy.py` (a host-side Python
  TCP relay) is no longer started automatically and is kept only as an obsolete fallback — see
  "Reaching the site" below.

## Layout

```
docker-compose.yml              # web (apache/php), db (mariadb), mailpit, wpcli, composer/node (deps)
docker/php/Dockerfile           # PHP 8.2 + Apache, document root web/, required extensions
docker/php/000-default.conf     # Apache vhost pointing at /var/www/html/web
docker/php/php.ini              # Local dev PHP overrides
docker/mariadb/my.cnf           # MariaDB tuning for large dump imports
.env.example                    # Copy to .env — local DB creds, salts, Mailpit SMTP, ports
wp-cli.yml                      # WP-CLI path config placeholder (path: web/wp) — replaced by
                                 # the snapshot's own wp-cli.yml if it ships one (see step 3)
local/mu-plugins/*.example      # Copy into web/app/mu-plugins/ after snapshot import
scripts/                        # start, stop, restart, logs, db-import, search-replace, theme-build, verify
scripts/host-proxy.py           # Local-only TCP relay 127.0.0.1 -> web/mailpit container IPs
                                 # (managed automatically by start.sh/stop.sh/restart.sh)
```

The site itself (`web/app`, `composer.json`, `config/environments/*.php`, etc.) is expected to
arrive via the source snapshot and is **not** created by this scaffold — nothing here assumes
or overwrites that structure beyond the templates in `local/`.

## First-time setup

1. Make scripts executable (one-time): `chmod +x scripts/*.sh`
2. Copy the env template and fill in local-only values:
   ```
   cp .env.example .env
   ```
   Generate 8 unique salts (e.g. `openssl rand -base64 64` run 8 times) and set a local DB
   password/root password. Do not copy values from the live site.
3. Place the site snapshot's Bedrock files (`web/app`, `composer.json`, `config/`, `wp-cli.yml`,
   etc.) into this directory, alongside the files created here. If the snapshot includes its own
   `wp-cli.yml`, let it replace the placeholder committed here — do not merge into it — so
   WP-CLI's path settings match the real project. Do not overwrite `docker-compose.yml`,
   `docker/`, `scripts/`, `.env.example`, or `local/`.
4. Install PHP dependencies (requires internet, runs in the isolated `deps` network only):
   ```
   docker compose --profile deps run --rm composer install
   ```
5. Start the stack:
   ```
   ./scripts/start.sh
   ```
6. Import the database snapshot:
   ```
   ./scripts/db-import.sh /path/to/snapshot.sql.gz
   ```
7. Rewrite the live domain to the local one (dry-run shown first, requires explicit
   confirmation before applying):
   ```
   ./scripts/search-replace.sh https://www.dermadirect.co.uk http://localhost:8088
   ```
8. Copy the local safety mu-plugin into place:
   ```
   cp local/mu-plugins/000-local-safety.php.example web/app/mu-plugins/000-local-safety.php
   ```
9. Build theme assets if needed (requires internet, isolated `deps` network):
   ```
   ./scripts/theme-build.sh build
   ```
10. Run the local sanity checks:
    ```
    ./scripts/verify.sh
    ```

## Day-to-day usage

| Task                                    | Command                                              |
|------------------------------------------|-------------------------------------------------------|
| Start the stack (+ host proxy)           | `./scripts/start.sh`                                   |
| Stop the stack (+ host proxy)            | `./scripts/stop.sh`                                     |
| Restart a service (or all; + host proxy) | `./scripts/restart.sh [service]`                        |
| Tail logs (or one service's logs)        | `./scripts/logs.sh [service]`                            |
| Import/replace the local database        | `./scripts/db-import.sh <dump.sql[.gz]>`                  |
| Rewrite URLs (dry-run then confirm)      | `./scripts/search-replace.sh <old-url> <new-url>`          |
| Build theme assets                       | `./scripts/theme-build.sh [build\|watch\|dev]`               |
| Install/update PHP dependencies          | `docker compose --profile deps run --rm composer install`    |
| Run WP-CLI ad hoc                        | `docker compose --profile cli run --rm wpcli <command>`       |
| Sanity checks + egress isolation check   | `./scripts/verify.sh`                                            |

- Site: http://localhost:8088 (port configurable via `WEB_PORT` in `.env`)
- Mailpit UI (catches all outgoing mail): http://localhost:18025

### Reaching the site (host proxy)

Runtime evidence in this environment shows the `internal: true` network (required for the egress
block described above) has no gateway, so although `docker-compose.yml`'s `127.0.0.1:8088:80` and
`127.0.0.1:18025:8025` port bindings are present in each container's HostConfig, they are not
actually reachable from this Mac host — connections to `localhost:8088`/`localhost:18025` fail
even while the containers are healthy.

`./scripts/start.sh` (and `./scripts/restart.sh`) work around this automatically by launching
`scripts/host-proxy.py` in the background: a small Python-stdlib-only TCP relay that resolves the
current `web` and `mailpit` container IPs read-only (via `docker compose ps` + `docker inspect`)
and forwards exactly:

- `127.0.0.1:8088` (or `WEB_PORT`) -> the `web` container's IP, port `80`
- `127.0.0.1:18025` (or `MAILPIT_UI_PORT`) -> the `mailpit` container's IP, port `8025`

It binds strictly to `127.0.0.1`, proxies no other destination, and does not create, join, or
modify any Docker network or container — the `internal: true` network and the egress block stay
exactly as configured. `./scripts/stop.sh` stops it along with the stack. Its PID is tracked in
`.host-proxy.pid` (gitignored) and its output is appended to `logs/host-proxy.log` (gitignored);
if a service is recreated and gets a new container IP, re-run `./scripts/restart.sh` to have the
proxy re-resolve it. To run it in the foreground for debugging: `scripts/host-proxy.py`.

## PHP memory & WP-CLI configuration

- The `web` container (Apache/PHP, built from `docker/php/Dockerfile`) gets its PHP overrides
  from `docker/php/php.ini`: `memory_limit=512M`, `upload_max_filesize=256M`,
  `post_max_size=256M`, `max_execution_time=300`, `max_input_vars=5000`.
- The `wpcli` service runs the official `wordpress:cli` image directly (see
  `docker-compose.yml`) — it does **not** build from `docker/php/Dockerfile` and does not mount
  `docker/php/php.ini`, so it never shares the `web` container's `512M` override.
- Instead, `docker-compose.yml` sets `WP_CLI_PHP_ARGS=-d memory_limit=1G` on the `wpcli` service
  only. WP-CLI's own `wp` wrapper script reads `WP_CLI_PHP_ARGS` and passes it straight through to
  the underlying `php` binary as `-d` flags, so **every** `wp ...` invocation of this service
  (interactive or via `scripts/search-replace.sh`) persistently runs with `memory_limit=1G`,
  without a custom Dockerfile or mounted `php.ini`. The `web` service is untouched.
- `1G` is a starting point, not a guarantee for every dataset. If a WP-CLI command still exhausts
  memory at `1G` (large `search-replace --all-tables` runs over big/serialized tables are the
  most likely case), use the table-batch mode described below rather than raising this value
  first — see "Known problematic tables".

## WP-CLI table-batch (search-replace) syntax

`scripts/search-replace.sh` is the source of truth for the exact command — don't hand-type a
variant that drifts from it. It supports two modes:

**Full run** (no trailing table names) — the default, and what step 7 of setup uses:

```
scripts/search-replace.sh <old-url> <new-url>
```

Under the hood, this runs a dry-run first:

```
docker compose --profile cli run --rm wpcli \
    search-replace "<old-url>" "<new-url>" --all-tables --skip-plugins --skip-themes --skip-columns=guid --dry-run
```

and, only after typing `yes` at the confirmation prompt, the real run plus cache/rewrite flush:

```
docker compose --profile cli run --rm wpcli \
    search-replace "<old-url>" "<new-url>" --all-tables --skip-plugins --skip-themes --skip-columns=guid
```

**Batch run** (one or more trailing table names) — for working around memory exhaustion on very
large/serialized tables even with the `wpcli` service's `memory_limit=1G` (see above):

```
scripts/search-replace.sh <old-url> <new-url> wp_posts wp_postmeta
```

which runs the proven positional-table-plus-`--all-tables` form:

```
docker compose --profile cli run --rm wpcli \
    search-replace "<old-url>" "<new-url>" wp_posts wp_postmeta --all-tables --skip-plugins --skip-themes --skip-columns=guid --dry-run
```

(and the same without `--dry-run` after confirmation). Passing explicit table names restricts
the run to exactly those tables; `--all-tables` is kept alongside them (rather than dropped) so
the same prefix-independent, plugin/theme-skipping behavior applies whether this is a full run
or a batch — don't drop `--all-tables` just because table names are also given.

`--all-tables` (not `--all-tables-with-prefix`) is required in both modes because Bedrock/plugin
tables aren't guaranteed to share the configured `DB_PREFIX` (`wp_` by default, see
`.env.example`) — `--all-tables-with-prefix` would silently skip any table that doesn't match the
prefix. `--skip-plugins`/`--skip-themes` avoid bootstrapping plugin and theme code during the
replace, reducing memory pressure and avoiding plugin/theme hooks firing mid-rewrite.
`--skip-columns=guid` preserves post GUIDs, per WordPress core's own recommendation.

### Continuation batch method

Only needed if a full run still exhausts memory at `1G`:

1. List every table so batches are drawn from a real, current list rather than guessed:
   `docker compose --profile cli run --rm wpcli db tables --all-tables`
2. Split that list into groups of roughly 10-20 tables, smallest/simplest first.
3. Run `scripts/search-replace.sh <old-url> <new-url>` once per group, passing the group as
   trailing table names. Each invocation writes its own timestamped log to `logs/` — keep all of
   them as evidence of which tables were completed.
4. Track completed tables externally (e.g. append table names to a scratch file as each batch
   succeeds) and remove them from the next group before running it, so no table is skipped or
   double-processed across batches.
5. Only the last batch needs the `rewrite flush` / `cache flush` step the script already runs
   after every confirmed apply — earlier batches' flushes are harmless but redundant.

### Known problematic tables

Isolate these into their own single-table batch first if a group containing them fails even with
`memory_limit=1G`:

- `wp_options` — large autoloaded, heavily serialized values (theme mods, plugin settings
  caches).
- `wp_postmeta` / `wp_usermeta` — high row counts with serialized meta values.
- `wp_actionscheduler_actions` / `wp_actionscheduler_logs` (if Action Scheduler/WooCommerce are
  present) — very high row counts, low value from URL rewriting but expensive to scan.
- Any `wp_woocommerce_*` order/session/log tables — large, frequently serialized.

## Exact local URL

Per `.env.example` defaults: `WP_HOME=http://localhost:8088`, `WP_SITEURL=http://localhost:8088/wp`,
Mailpit UI at `http://localhost:18025`, DB exposed at `127.0.0.1:3306`. These derive from
`WEB_PORT` / `MAILPIT_UI_PORT` / `DB_PORT` in `.env` (defaults `8088` / `18025` / `3306` — see
`docker-compose.yml`). If any of these are overridden locally, use the real port both when
running `search-replace.sh` and when recording evidence below, rather than the defaults shown
here.

## Runtime ingress and completed verification

The runtime application services stay on the egress-blocked `internal` Docker network. The `proxy` service is nginx-only, has no application mounts, attaches to `internal` and a separate `ingress` network, and publishes only `127.0.0.1:8088` and `127.0.0.1:18025`. This is required because Docker Desktop does not make published ports on an `internal` network reachable from the Mac host. The obsolete `scripts/host-proxy.py` is retained only as historical fallback and is not started by the normal scripts.

The continuation verification report is in `VERIFICATION-REPORT.md`. The latest executable evidence is `logs/verify-20260926-232444.log`; URL replacement batch evidence is in `logs/url-replacement-batches.log` and `logs/local-port-replacement-batches.log`.

The transferred source tracked files match GitHub commit `79840268b41b117df372bc1c8f707ef4e218abe7`. The local-only additions are the Compose ingress proxy, local `.htaccess`, safety mu-plugin, local environment, local administrator, and documentation.

## Recording URL-replacement and verification evidence

`scripts/search-replace.sh` and `scripts/verify.sh` write a timestamped copy of their output to
`logs/` (gitignored — see `.gitignore`) in addition to the terminal, e.g.
`logs/search-replace-20260101-120000.log` / `logs/verify-20260101-120000.log`. When handing off
or continuing this work, cite the specific log file(s) produced by an actual run as evidence —
the environment has been executed and verified. Cite `VERIFICATION-REPORT.md` and the timestamped logs for current evidence.

## Notes

- The `wp-cli.yml` committed here is only a placeholder for a stock Bedrock layout
  (`path: web/wp`). The source snapshot's own `wp-cli.yml`, if it ships one, is authoritative —
  let it replace this placeholder (per step 3) rather than merging into it, so WP-CLI's path
  settings always match the real project structure.
- `.gitignore` excludes dumps, uploads, `.env`, WordPress core, `vendor/`, `node_modules/`, and
  the copied `000-local-safety.php` — none of these should be committed.
- This scaffold has not been run or verified in this session (no commands were executed). After
  following the setup steps above, use `./scripts/verify.sh` and manual browsing to confirm the
  stack behaves as expected before relying on it.

## Continuation notes

The local safety mu-plugin replaces the production Swiper CDN with pinned Swiper 11.0.5 and disables external Contact Form 7 reCAPTCHA. Barcode-scanner vendor assets were restored from pinned npm package versions through the isolated `deps` network. Do not run dependency updates.

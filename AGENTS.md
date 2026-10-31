# Install guide for AI agents

You are an LLM/coding agent asked to install this app on a Linux VPS. Follow the steps in order, verify each one, and ask the human only for the items marked ASK. Never print secrets back into chat and never commit `config.php`.

## What this is
PHP 8 + SQLite (FTS5) + htmx bookmarks service. No Composer, no npm, no build step. Only `public/` is served; the worker `bin/worker.php` runs every minute (crawl + tag via OpenRouter). Details: `README.md`. If the human prefers Docker, skip steps 1-4 and follow README "Deploy with Docker Compose" instead.

## ASK the human first
1. Public domain (e.g. `bookmarks.example.com`) whose DNS A/AAAA record already points to the VPS. Or a path on an existing site, see `deploy/nginx-bookmarks.conf`.
2. Admin email address (receives the first login link).
3. Optional: OpenRouter API key (without one only rule-based tagging runs); SMTP host/user/password for login mails (without SMTP the links are only written to `data/mail.log`, fine for a single admin: read the link from there).
4. Whether the machine has other sites. If so, do not touch their nginx/PHP config beyond adding a server block or include.

## 1. Prerequisites (verify, install only what is missing)
```
php -v                                   # >= 8.1
php -m | grep -Ei 'pdo_sqlite|curl|dom|mbstring|sqlite3'   # all needed
php -r 'new PDO("sqlite::memory:")->exec("CREATE VIRTUAL TABLE t USING fts5(a)");' && echo FTS5 ok
```
Packages: Debian/Ubuntu `php-fpm php-sqlite3 php-curl php-xml php-mbstring nginx`; Arch `php php-fpm php-sqlite nginx` and enable `pdo_sqlite`, `sqlite3`, `curl`, `dom`, `mbstring` in `/etc/php/php.ini`. Find the web user (`ps aux | grep php-fpm`): `www-data` (Debian/Ubuntu) or `http` (Arch). Find the FPM socket (`ls /run/php*/`).

## 2. Install files
```
sudo mkdir -p /srv/bookmarks
sudo rsync -rlt --exclude=.git ./ /srv/bookmarks/        # run from the repo checkout
cd /srv/bookmarks && sudo cp config.example.php config.php
sudo mkdir -p data && sudo chown -R WEBUSER:WEBUSER data config.php && sudo chmod 600 config.php
```
Edit `config.php`: `base_url` = `https://DOMAIN` (no trailing slash, REQUIRED), `mail_from`, `smtp` (optional), `openrouter_key` (optional). Leave `db_path`/`lock_path` as is. The database is created automatically from `schema.sql` on the first request.

## 3. nginx
Use `deploy/nginx-bookmarks-root.conf` (app at the domain root) inside a `server { server_name DOMAIN; ... }` block, plus `include` of `deploy/nginx-bookmarks-security.conf` (strict CSP: do not weaken it, the app has no inline scripts). Replace `fastcgi_pass unix:/run/php-fpm/php-fpm.sock` with the real socket (Debian: `/run/php/php8.x-fpm.sock`). Only `/srv/bookmarks/public` may be web-reachable. Get TLS with certbot (`sudo certbot --nginx -d DOMAIN`). Then `sudo nginx -t && sudo systemctl reload nginx`.

## 4. Worker
```
sudo sed -i 's/^User=.*/User=WEBUSER/; s/^Group=.*/Group=WEBUSER/' deploy/bookmarks-worker.service
sudo cp deploy/bookmarks-worker.service deploy/bookmarks-worker.timer /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now bookmarks-worker.timer
```
(Alternative without systemd: `deploy/crontab.txt` as the web user.) `MemoryMax=200M` is fine for a 1-2 GB VPS.

## 5. First admin
```
cd /srv/bookmarks && sudo -u WEBUSER php bin/create-user.php ADMIN_EMAIL --admin
```
It prints a one-time login link (valid 15 min). Give it to the human.

## 6. Verify (all must pass)
```
php bin/test-urls.php && php bin/test-db.php                    # tests, throw-away DB
curl -sI https://DOMAIN/login.php | head -1                      # HTTP/2 200
curl -sI https://DOMAIN/config.php https://DOMAIN/data/ | grep HTTP   # must NOT be 200 (404/403)
curl -sI https://DOMAIN/ | grep -i content-security-policy       # present
systemctl list-timers | grep bookmarks                           # timer active
sudo -u WEBUSER php bin/worker.php; echo exit=$?                 # 0
ls -l /srv/bookmarks/data                                        # owned by WEBUSER
```
Then tell the human: log in via the link, open the menu on the index page for the bookmarklet and the iOS/macOS Shortcut instructions (both use `https://DOMAIN/add.php`).

## Troubleshooting
- 500 "config.php missing": step 2 skipped. Blank page / 502: wrong FPM socket or PHP error, see `journalctl -u php*-fpm` and the nginx error log.
- "unable to open database" / "readonly database": `data/` and the DB files must be owned by the web user (never create the DB as root: use `sudo -u WEBUSER`).
- `no such module: fts5`: PHP's SQLite lacks FTS5; install a PHP build whose `sqlite3` has it (stock distro packages do).
- Login mail not arriving: check `data/mail.log` (fallback when `smtp.host` is empty) or the SMTP credentials; Gmail needs an app password.
- Bookmarks stay "pending": the timer is not running or the web user cannot write `data/`; check `systemctl status bookmarks-worker.service` and `data/worker.log`.

## Updating later
Re-run the `rsync` from step 2 with `--exclude=config.php --exclude=data --delete` per directory (never delete `config.php` or `data/`), back up `data/bookmarks.db` first (`sqlite3 data/bookmarks.db ".backup /root/bm.db"`), then reload php-fpm. Schema migrations run automatically.

## Rules for contributors/agents
Keep the CSP strict (no inline JS), no frameworks, secrets only in `config.php`, schema changes via `src/migrations.php` + `schema.sql` (see README "Database changes").

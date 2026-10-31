# Bookmarks

Tiny self-hosted, multi-user bookmarks: PHP + SQLite (FTS5) + htmx; tags and summaries from rules plus one OpenRouter call per bookmark.

## Setup
1. PHP 8 with pdo_sqlite, curl, dom, mbstring.
2. `cp config.example.php config.php`; set `base_url` (e.g. `http://host/bookmarks`, no trailing slash) and optionally `smtp`.
3. Serve `public/` only (see `deploy/nginx-bookmarks.conf`); `src/`, `data/`, `bin/`, `config.php` must stay unreachable. Links are relative, so any base path works.
4. Worker every minute: `deploy/bookmarks-worker.{service,timer}` (systemd, `MemoryMax=700M`) or `deploy/crontab.txt`. flock inside `worker.php` prevents overlap.
5. Tagging needs an OpenRouter key in config.php (see Tagging); nothing else to install.
6. First admin: `php bin/create-user.php you@example.com --admin` prints a one-time login link (15 min). Run it as the web user so data/ ownership stays right.

## Deploy on a VPS (example: nginx + PHP-FPM, systemd)
```
sudo mkdir -p /srv/bookmarks && sudo rsync -rlt --exclude=config.php --exclude=data --exclude=.git ./ /srv/bookmarks/
cd /srv/bookmarks && sudo cp config.example.php config.php   # set base_url (https://your.domain), mail and openrouter_key
sudo mkdir -p data && sudo chown -R http:http data config.php && sudo chmod 600 config.php   # web user: http (Arch), www-data (Debian/Ubuntu)
sudo cp deploy/bookmarks-worker.* /etc/systemd/system/ && sudo systemctl enable --now bookmarks-worker.timer
sudo -u http php bin/create-user.php you@example.com --admin
```
Adjust the web user in `deploy/bookmarks-worker.service`, the PHP-FPM socket and server name in the nginx snippets, and `include` `deploy/nginx-bookmarks-security.conf` in the server block (it sets the strict CSP the app relies on). Use HTTPS; `config.php` and `data/` hold secrets and are never committed.

## Code layout
- `public/*.php`: one small entry file per URL; each starts with `require __DIR__ . '/../src/app.php'` and, for htmx endpoints, `endpoint()` / `owned_card()` (session, CSRF, ownership). `public/app.js` holds all browser behaviour (no inline scripts, handlers or eval: the CSP is `script-src 'self'` and htmx runs with `allowEval=false`).
- `src/`: `db.php` (connection, `q()`, migration runner), `migrations.php` (schema history), `bookmarks.php` (add, trash, search, cards), `pipeline.php` (crawl + tag one bookmark, used by `bin/worker.php`), `crawler.php`, `tagger.php`, `url.php`, `auth.php`, `transfer.php`, `view.php` (prepares data, `render()`), `app.php` (loads everything).
- `views/*.phtml`: the HTML fragments (card, tags, edit form, tag cloud, toast, trash row, popup, page head).
- Tables: `bookmarks` (content), `jobs` (processing state per bookmark: status, attempts, error, deferred OpenRouter step), `capture` (page data sent by the bookmarklet), `tags`, `bookmarks_fts`. Trashed bookmarks keep their row with `deleted_at` set.

## Database changes
`schema.sql` is the schema of a fresh database (`SCHEMA_VERSION` in `src/db.php`). A schema change is a numbered entry in `src/migrations.php` plus the matching edit to `schema.sql` and a bumped `SCHEMA_VERSION`; `migrate()` runs pending migrations once (`PRAGMA user_version`) inside a transaction on the first request or worker run after a deploy. Never edit an applied migration. Back up `data/bookmarks.db` before deploying one.

## Tests
`php bin/test-urls.php` (URL cleaning) and `php bin/test-db.php` (migrations, add/dup, trash/restore, search, job state, tag replacement; uses a throw-away database). `BM_CONFIG=/path/config.php` points any script at another config file.

## Accounts
Login by emailed magic link (15 min, single use, sessions 90 days, revocable). Unknown emails become pending requests; admins approve on the Users page. Without `smtp` configured, mails are written to `data/mail.log`.

## Adding bookmarks
- Field at the top of the index page: accepts `bla.com`, `www.bla.com/path`, `//bla.com`, with or without scheme. A guessed scheme tries https, then http, then the `www.` variants in the worker.
- Bookmarklet (index page, per-user key; Regenerate invalidates the old one): `add.php?k=KEY&u=URL&t=TITLE` only does an INSERT; crawling happens in the worker. The same `add.php` serves the JSON API, the add field (htmx POST) and the bookmarklet popup, so existing Shortcut and bookmarklet URLs keep working.
- JSON API (iOS/macOS Shortcuts, scripts): `add.php?format=json` with `k` (bookmarklet key), `u` (a URL, or text containing one such as "Title https://...", bare domains work) and optional `t`; GET, form POST or a JSON body (JSON is detected from the body, `Content-Type`, `Accept: application/json`, `format=json` or a Shortcuts User-Agent; `u` may be a string or an array/object whose first string is used). Returns `{"ok":true,"status":"saved"|"duplicate","title":"..."}` or `{"ok":false,"error":"..."}` with 400 (bad/missing input) or 403 (bad key). The index page has step-by-step instructions for building the Shortcut.
- URLs are cleaned: tracking parameters are removed (`url_strip_params.txt`, editable: exact names, `prefix*`, `name@host,host`), redirect wrappers unwrapped, YouTube/Amazon canonicalized. Duplicates are detected on a normalized key (no scheme, no leading `www.`, no trailing slash). Check with `php bin/test-urls.php`.

## Blocked or thin pages
The worker tries: direct fetch, a desktop-browser retry, the `r.jina.ai` reader proxy, and finally the data the bookmarklet sent from your browser (title, description, og:image, favicon, keywords and the first 1500 characters of the page text or your selection; stored as `client_*`). The source is recorded in `crawl_source` (direct, jina, client). Favicons fall back to Google's favicon service.

## Tagging (rules + one OpenRouter call, no local ML)
`src/tagger.php`; applied topic tags: 2 to 4 (format tags come on top and do not count), up to 4 dimmed suggestions.
1. Deterministic: `domain_rules.txt` (`domain => tag, tag | category`), format tags from `format_rules.txt` (book, video, podcast, recipe, paper, product, repo; schema.org types, URL, meta, ISBN, title; never from the model), YouTube category, vocabulary keyword hits.
2. One OpenRouter call per bookmark (`openrouter_key`, `openrouter_models` fallback list, `openrouter_cap` per month in config.php): title, domain, description/structured data, YouTube data, ~1200 characters of page text, the vocabulary (`seed_tags.txt` `tag: description` plus the user's tags) and up to 3 of the user's own corrected bookmarks as examples. It returns vocabulary tags (most relevant first), new tags, a category and a 2-3 sentence summary. The first picks are trusted, later ones need support in the page text; new tags are dimmed suggestions (click to accept, x to dismiss). Brand/site names are dropped.
3. If the API fails (429, outage) the bookmark keeps its rule tags and meta keywords, and the step is retried with backoff (1 min, 5 min, 30 min, 2 h, then given up). Without a key only step 1 runs.
Optional `youtube_api_key` adds YouTube topic categories as keywords.
`php bin/bench-tags.php urls.txt` (one URL per line) runs the pipeline on a URL list without saving anything.

## Resources
The worker is a short PHP process (about 75 MB) run by a systemd timer with `MemoryMax=200M`. No local model, no daemon. OpenRouter cost is about $0.10 per 1000 bookmarks with Gemini Flash-Lite.

## Import / export

Index page, "Import / export": exports all bookmarks as Netscape bookmark HTML (tags in `TAGS`, summary as `<DD>`), which Safari, Chrome and Firefox import. Importing reads any such file (Safari: File > Export > Bookmarks); duplicates are skipped, tracking parameters stripped, and bookmarks with tags keep them untouched, the rest are tagged by the worker.

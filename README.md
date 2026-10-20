# Bookmarks

Tiny self-hosted, multi-user bookmarks: PHP + SQLite (FTS5) + htmx, background worker with llama.cpp (Qwen2.5-0.5B) for tags.

## Setup
1. PHP 8 with pdo_sqlite, curl, dom, mbstring.
2. `cp config.example.php config.php`; set `base_url` (e.g. `http://host/bookmarks`, no trailing slash) and optionally `smtp`.
3. Serve `public/` only (see `deploy/nginx-bookmarks.conf`); `src/`, `data/`, `bin/`, `config.php` must stay unreachable. Links are relative, so any base path works.
4. Worker every minute: `deploy/bookmarks-worker.{service,timer}` (systemd, `MemoryMax=700M`) or `deploy/crontab.txt`. flock inside `worker.php` prevents overlap.
5. LLM: llama.cpp release in `/opt/llama.cpp` (uses `llama-completion`), model in `/opt/models` (paths in config).
6. First admin: `php bin/create-user.php you@example.com --admin` prints a one-time login link (15 min). Run it as the web user so data/ ownership stays right.

## Accounts
Login by emailed magic link (15 min, single use, sessions 90 days, revocable). Unknown emails become pending requests; admins approve on the Users page. Without `smtp` configured, mails are written to `data/mail.log`.

## Adding bookmarks
- Field at the top of the index page: accepts `bla.com`, `www.bla.com/path`, `//bla.com`, with or without scheme. A guessed scheme tries https, then http, then the `www.` variants in the worker.
- Bookmarklet (index page, per-user key; Regenerate invalidates the old one): `add.php?k=KEY&u=URL&t=TITLE` only does an INSERT; crawling happens in the worker.
- URLs are cleaned: tracking parameters are removed (`url_strip_params.txt`, editable: exact names, `prefix*`, `name@host,host`), redirect wrappers unwrapped, YouTube/Amazon canonicalized. Duplicates are detected on a normalized key (no scheme, no leading `www.`, no trailing slash). Check with `php bin/test-urls.php`.

## Tagging
Order: `domain_rules.txt` (editable: `domain => tag, tag | category`) -> keyword/alias match against the vocabulary -> LLM only if fewer than 3 tags. The vocabulary is `seed_tags.txt` (editable, one per line) plus the user's most-used tags; the LLM is constrained by a JSON-schema enum to this closed list and its picks must be backed by page text. It may propose one `new_tag`, stored as a dimmed *suggested* tag (click to accept, never auto-applied). Tags can be edited on every card; edited bookmarks become few-shot examples for the same domain.
`php bin/bench-tags.php bench/urls.txt` runs the pipeline on a URL list without saving anything.

## RAM notes
llama is run per job with `-c 2048 -t 2`, exits when done, no idle daemon. The systemd service caps the whole worker at 700 MB (`MemoryMax`, no swap). Measured peak RSS about 650 MB including the mmapped 491 MB model (reclaimable page cache).

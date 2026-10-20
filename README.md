# Bookmarks

Tiny self-hosted, multi-user bookmarks: PHP + SQLite (FTS5) + htmx; tags and summaries from rules plus one OpenRouter call per bookmark.

## Setup
1. PHP 8 with pdo_sqlite, curl, dom, mbstring.
2. `cp config.example.php config.php`; set `base_url` (e.g. `http://host/bookmarks`, no trailing slash) and optionally `smtp`.
3. Serve `public/` only (see `deploy/nginx-bookmarks.conf`); `src/`, `data/`, `bin/`, `config.php` must stay unreachable. Links are relative, so any base path works.
4. Worker every minute: `deploy/bookmarks-worker.{service,timer}` (systemd, `MemoryMax=700M`) or `deploy/crontab.txt`. flock inside `worker.php` prevents overlap.
5. Tagging needs an OpenRouter key in config.php (see Tagging); nothing else to install.
6. First admin: `php bin/create-user.php you@example.com --admin` prints a one-time login link (15 min). Run it as the web user so data/ ownership stays right.

## Accounts
Login by emailed magic link (15 min, single use, sessions 90 days, revocable). Unknown emails become pending requests; admins approve on the Users page. Without `smtp` configured, mails are written to `data/mail.log`.

## Adding bookmarks
- Field at the top of the index page: accepts `bla.com`, `www.bla.com/path`, `//bla.com`, with or without scheme. A guessed scheme tries https, then http, then the `www.` variants in the worker.
- Bookmarklet (index page, per-user key; Regenerate invalidates the old one): `add.php?k=KEY&u=URL&t=TITLE` only does an INSERT; crawling happens in the worker.
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
`php bin/bench-tags.php bench/urls.txt` runs the pipeline on a URL list without saving anything.

## Resources
The worker is a short PHP process (about 75 MB) run by a systemd timer with `MemoryMax=200M`. No local model, no daemon. OpenRouter cost is about $0.10 per 1000 bookmarks with Gemini Flash-Lite.

## Import / export

Index page, "Import / export": exports all bookmarks as Netscape bookmark HTML (tags in `TAGS`, summary as `<DD>`), which Safari, Chrome and Firefox import. Importing reads any such file (Safari: File > Export > Bookmarks); duplicates are skipped, tracking parameters stripped, and bookmarks with tags keep them untouched, the rest are tagged by the worker.

# Bookmarks

Tiny self-hosted, multi-user bookmarks: PHP + SQLite (FTS5) + htmx, background worker with llama.cpp (Qwen2.5-0.5B) for tags.

## Setup
1. PHP 8 with pdo_sqlite, curl, dom, mbstring.
2. `cp config.example.php config.php`; set `base_url` (e.g. `http://host/bookmarks`, no trailing slash) and optionally `smtp`.
3. Serve `public/` only (see `deploy/nginx-bookmarks.conf`); `src/`, `data/`, `bin/`, `config.php` must stay unreachable. Links are relative, so any base path works.
4. Worker every minute: `deploy/bookmarks-worker.{service,timer}` (systemd, `MemoryMax=700M`) or `deploy/crontab.txt`. flock inside `worker.php` prevents overlap.
5. Embeddings: llama.cpp release in `/opt/llama.cpp` (`llama-server`), model `multilingual-e5-small-Q8_0.gguf` (keisuke-miyako/multilingual-e5-small-gguf-q8_0 on Hugging Face, 132 MB) in `/opt/models`.
6. First admin: `php bin/create-user.php you@example.com --admin` prints a one-time login link (15 min). Run it as the web user so data/ ownership stays right.

## Accounts
Login by emailed magic link (15 min, single use, sessions 90 days, revocable). Unknown emails become pending requests; admins approve on the Users page. Without `smtp` configured, mails are written to `data/mail.log`.

## Adding bookmarks
- Field at the top of the index page: accepts `bla.com`, `www.bla.com/path`, `//bla.com`, with or without scheme. A guessed scheme tries https, then http, then the `www.` variants in the worker.
- Bookmarklet (index page, per-user key; Regenerate invalidates the old one): `add.php?k=KEY&u=URL&t=TITLE` only does an INSERT; crawling happens in the worker.
- URLs are cleaned: tracking parameters are removed (`url_strip_params.txt`, editable: exact names, `prefix*`, `name@host,host`), redirect wrappers unwrapped, YouTube/Amazon canonicalized. Duplicates are detected on a normalized key (no scheme, no leading `www.`, no trailing slash). Check with `php bin/test-urls.php`.

## Blocked or thin pages
The worker tries: direct fetch, a desktop-browser retry, the `r.jina.ai` reader proxy, and finally the data the bookmarklet sent from your browser (title, description, og:image, favicon, keywords and the first 1500 characters of the page text or your selection; stored as `client_*`). The source is recorded in `crawl_source` (direct, jina, client). Favicons fall back to Google's favicon service.

## Tagging (hybrid, local first)
`src/tagger.php` fuses small, local signals; every bookmark gets at least 3 topic tags counting applied + suggested (the format tag `video` does not count).
1. `domain_rules.txt` (`domain => tag, tag | category`), keyword/alias hits in title/description/meta keywords, YouTube's own category.
2. A compact YAKE keyphrase extractor (English + German stopwords, title weighted x3). Phrases matching the vocabulary add evidence, the rest become dimmed *suggestions*.
3. Embeddings: multilingual-e5-small (GGUF, in `/opt/models`) served by a short-lived `llama-server --embeddings` that the worker starts and stops. Each vocabulary tag has a description in `seed_tags.txt` (`tag: description`, editable; embeddings are cached in SQLite and refreshed when the text changes). Tags whose similarity is an outlier (z-score) are candidates.
4. kNN over the user's own bookmark embeddings: tags of the most similar bookmarks boost candidates (edited bookmarks count 1.5x).
5. Optional: set `youtube_api_key` in config.php (YouTube Data API v3, enable it in Google Cloud Console, create an API key, free quota) to add `topicCategories` and the video's own tags as keywords. Empty = skipped.
6. Optional OpenRouter (`openrouter_key`, `openrouter_model`, `openrouter_cap` in config.php; empty key = off): only when fewer than 3 topic tags were applied or the page was thin/blocked. Vocabulary picks the embedding also ranks highly are applied, other picks and its new tags are suggestions. Calls are counted per month in SQLite and stop at the cap (default 3000, about $0.36 with Gemini Flash-Lite); any error falls back to the local result.
Scores are summed; >= 0.5 is applied (max 6), the rest becomes suggestions. Click a dimmed chip to accept it, x to dismiss. Edited bookmarks become kNN evidence. `suggest_external()` in the tagger is an empty hook for an optional external service (e.g. OpenRouter).
`php bin/bench-tags.php bench/urls.txt` runs the pipeline on a URL list without saving anything.

## RAM notes
The worker starts one `llama-server` (-c 512, 2 threads) only while it has pending work and stops it on exit; no idle daemon. Measured peak RSS about 200 MB. The systemd service still caps the whole worker at 700 MB (`MemoryMax`, no swap).

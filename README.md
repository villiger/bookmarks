# Bookmarks

Tiny self-hosted bookmarks: PHP + SQLite (FTS5) + htmx, background worker with llama.cpp (Qwen2.5-0.5B) for tags.

## Setup
1. Needs PHP 8 with pdo_sqlite, curl, dom, mbstring.
2. `cp config.example.php config.php`, set `token` (also the UI password).
3. Serve `public/` (see `deploy/nginx-bookmarks.conf`); keep `data/`, `src/` unreachable. Links are relative, so any base path works.
4. Worker every minute: `deploy/crontab.txt` (or a systemd timer if no cron). It holds a flock, one job at a time.
5. LLM: llama.cpp release binary in `/opt/llama.cpp`, model in `/opt/models` (paths in config).

## Bookmarklet
Shown on the index page (drag "+ Save bookmark" to the bar). `add.php?k=TOKEN&u=URL&t=TITLE` only does an INSERT; crawling happens in the worker.

## Tags
`seed_tags.txt` (one tag per line, editable) is the starter vocabulary, merged with the most-used existing tags. If a crawled page's keywords/title/description match >=2 known tags, they are used and the LLM is skipped.

## RAM notes
llama-cli runs per job with `-c 2048 -t 2`, exits when done, wrapped in `systemd-run --scope -p MemoryMax=600M` when available (else ulimit). No idle daemon.

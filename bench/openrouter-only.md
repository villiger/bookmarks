# OpenRouter-only tagging (no local ML)

Same 17 URLs as `bench/urls.txt`, single run on villiger (`bench/openrouter-only.txt`). Models: google/gemini-2.5-flash-lite (primary; 2 of 17 calls were answered by the mistral-small-3.2 fallback in an earlier run), prices $0.10 / $0.40 per 1M tokens.

| | Hybrid + OpenRouter (embeddings, YAKE, kNN) | OpenRouter only |
|--|--|--|
| applied >= 3 | 53% | 82% |
| applied + suggested >= 3 | 100% | 88% (Rick Astley video and the StackOverflow page have 2) |
| avg applied / applied+suggested | 2.9 / 6.0 | 2.8 / 5.3 |
| avg time per URL | 3.1 s | 2.2 s |
| API calls / est. cost per 1000 bookmarks | 14 of 15 / $0.08 | 17 of 17 / $0.10 |
| peak RAM, local model on disk | 205 MB, 132 MB | none (about 75 MB for the PHP worker) |
| `src/*.php` lines | 1313 (tagger 425) | 1151 (tagger 267) |

Quality: comparable. Titles, summaries (2-3 sentences, in the page language), format tags and suggestions are the same quality; the model now also decides the category. Without the embedding gate it sometimes adds one loosely related vocabulary tag (`linux` and `devops` on SQLite, `devops` on Joel Test, `science` on Spotify/Amazon when it had to reach 3 applied). Mitigations in place: only the first picks plus rule hits are trusted, later ones need page-text support, brand names are dropped. A prompt tweak removed the catch-all tags (`tools`, `tech`, `business`) that the first run produced.

# Tagging benchmark

Three generations, same URLs, run on villiger (1 vCPU):
- **Qwen v0**: first LLM tagger (`before.txt`).
- **Qwen final**: tuned LLM tagger, closed vocabulary + min-3 rule (`qwen-final*.txt`).
- **Hybrid**: rules + YAKE + e5 embeddings + kNN, no generative model (`hybrid.txt`).
- **Hybrid + OpenRouter**: same, plus `google/gemini-2.5-flash-lite` for short/blocked pages or fewer than 3 applied tags (`hybrid-or.txt`, current code).

"applied" = tags actually applied, "sugg" = dimmed click-to-accept suggestions; `video` is not counted.

## Summary
| | Qwen v0 | Qwen final | Hybrid |
|--|--|--|--|
| 13 crawlable URLs: applied >= 3 | 92% (4 to 5 tags each, mostly junk) | 15% | 8% |
| applied + suggested >= 3 | n/a | 85% | **100%** |
| avg applied / applied+sugg | 4.2 / n/a | 1.5 / 3.1 | 1.5 / 3.8 |
| 6 YouTube URLs: applied >= 3 | 0% | 17% | 17% |
| applied + sugg >= 3 | n/a | 50% | **100%** |
| user's 9 crawlable bookmarks: applied >= 3 / a+s >= 3 | n/a | n/a | 44% / 100% |
| avg time per URL (crawl + tag) | 4.5 s | 7.3 to 10 s | **0.6 to 1.6 s** |
| peak RAM (max RSS) | 706 MB | 652 MB | **203 MB** |
| model on disk | 491 MB | 491 MB | 132 MB |
| `src/tagger.php` | 325 lines (final Qwen state) | | 223 lines |

Applied >= 3 is low for the hybrid by design: only evidence-backed tags are applied; the rest is offered as suggestions to fill the 3 minimum. (Qwen v0's 92% came from padding with irrelevant tags.)

## Per URL: Qwen final vs Hybrid (13 crawlable of 15; joelonsoftware and stackoverflow return 403)
| URL | Qwen final | Hybrid | Verdict |
|--|--|--|--|
| llama.cpp | opensource, ai | ai, opensource (sugg: llm inference, gguf, hardware) | same |
| YouTube Rick Astley | video | video, music (sugg: gonna give) | better |
| YouTube Simple Made Easy | video, design, database, tech | video, design, tech | better (database gone) |
| Wikipedia SQLite | database | database, self-hosted (sugg: sqlite) | mixed (self-hosted wrong) |
| Guardian technology | news | news (sugg: tech, cloud) | same |
| php.net PDO | php, docs, self-hosted | php, docs, database | better |
| IKEA product | shopping | shopping (sugg: billy) | same |
| BBC lasagne | food | food (sugg: lasagne, sauce) | same |
| immich.app | video, self-hosted | self-hosted (sugg: server, privacy) | better (video was wrong) |
| MDN grid-template | docs, css | docs, css (sugg: grid, columns) | same |
| Hacker News item | news | news (sugg: hacker news) | same |
| tagesschau | news | news (sugg: nachrichten) | same |
| Spotify show | music | music (sugg: player, web) | same |

Better 4, same 8, mixed 1, worse 0. The topic quality is similar to Qwen final; the gains are speed (10 s to under 1 s), RAM, reliable suggestions, and no hallucinated tags.

## YouTube (6 URLs): Qwen final vs Hybrid
Despacito: Qwen video, music (sugg latin, pop); Hybrid video, music (sugg despacito, daddy yankee, gaming). Linus git talk: tech, tutorial both. The Egg: design, film, tutorial both. GTA VI trailer: music, gaming both. Rammstein: music both. One video was unavailable (both only get `video, music` from the generic page).
Verdict: same quality, 6x faster. Tags for videos remain thin because only title, channel, YouTube keywords/category and 800 chars of description are available; the optional YouTube API key adds topic categories.

## The user's real bookmarks (before = Qwen era, after = hybrid, edited ones untouched)
| # | URL | Before | After |
|--|--|--|--|
| 1 | beszel.dev | devops, food | devops (sugg: lightweight, server) |
| 2 | monitorix.org | linux, opensource, php | linux, opensource, self-hosted (sugg: open source, system) |
| 3 | YouTube b4xjias39sE | gamedev, gaming, video | unchanged (edited) |
| 4 | YouTube 6amzFFhCIGc | fun, video (sugg gamedev, gaming, self-hosted) | video, fun (sugg: politics, history, krieg, deutschland, gaming) |
| 5 | YouTube qYq_V-wyfyA | gaming, tech, video | unchanged (edited) |
| 6 | YouTube rB_Fqt6UUxU | gaming, video (sugg ancient, endgame crisis, stellaris-style) | video, gaming (sugg: final boss, game) |
| 7 | Wikipedia London Monster | none (sugg artificialflowers, policeoffense) | none (sugg: attacks, london monster, women) |
| 8 | YouTube QgZVVFgrr-E (Blender) | gaming, music, tutorial, video | video, art, music, tutorial (sugg: house, speed modeling) |
| 9 | smallboatsmonthly | fun (sugg features, structure) | design, fun (sugg: harris, tenderly dinghy) |
| 10 | gartsideboats | error (HTTP 429) | not retagged (still error) |

Judgement: 1, 2, 4, 6, 8, 9 better (junk such as food, php, gamedev guesses gone), 7 unchanged (needs a history/crime tag in the vocabulary; the suggestions are sensible), 4 gets the right topic only as a suggestion (German query vs English tag descriptions).

## Caveats
- e5-small similarities are compressed (0.88 to 0.95 between unrelated pages), so the embedding is used through outlier z-scores, and kNN only boosts other evidence (it needs a larger bookmark base to help).
- Broad words in the vocabulary still produce occasional wrong tags (self-hosted on SQLite).

## Hybrid + OpenRouter (google/gemini-2.5-flash-lite: $0.10 per 1M input tokens, $0.40 per 1M output tokens)
Called only when fewer than 3 topic tags are applied locally or the page was blocked/thin. A vocabulary pick from the model is applied only if the embedding also ranks it in its top 10; otherwise it becomes a dimmed suggestion (the model over-tags). Its new tags are always suggestions.

| | Hybrid | Hybrid + OpenRouter |
|--|--|--|
| 15 URLs (2 more crawlable now): applied >= 3 / a+s >= 3 | 8% / 100% (13 URLs) | 53% / 100% |
| avg applied / applied+suggested | 1.5 / 3.8 | 2.9 / 6.0 |
| 6 YouTube URLs: applied >= 3 / a+s >= 3 | 17% / 100% | 50% / 83% |
| user's 10 bookmarks: applied >= 3 / a+s >= 3 | 44% / 100% (9 URLs) | 80% / 100% |
| avg time per URL | 0.6 to 1.6 s | 1.5 to 3.1 s |
| API calls (15 / 6 / 10 URLs) | 0 | 14 / 5 / 6 |
| est. cost per 1000 bookmarks | $0 | about $0.06 to $0.08 (about 0.012 cent per call) |
| peak RAM | 203 MB | 205 MB |

Cap: 3000 calls per month (`openrouter_cap`), about $0.36 at worst, far below the $1 limit. Upstream returned HTTP 429 in bursts (retried twice with backoff, then silently falls back to the local result; the log line is `openrouter: failed ...`).

Per URL, compared with plain hybrid:
| URL | Hybrid + OpenRouter | Verdict |
|--|--|--|
| llama.cpp | ai, opensource, tools, devops | same (tools/devops weak) |
| Rick Astley | video, music, tech | same |
| Simple Made Easy | video, design, tech (sugg: complexity, programming, software design) | same, better suggestions |
| Wikipedia SQLite | database, self-hosted, opensource, tools, tech (sugg: rdbms, embedded database) | same (self-hosted wrong) |
| Guardian technology | news, tech, ai, business | better |
| php.net PDO | php, docs, database | same |
| IKEA | shopping, tools (sugg: bookshelf, ikea furniture) | same |
| lasagne | food, health (sugg: lasagne recipe, beef mince) | same |
| immich | self-hosted, tools, tech, productivity (sugg: photo management) | better |
| MDN | css, docs | same |
| Hacker News | news, tech, business | better |
| tagesschau | news (sugg: politics, german news) | same |
| Spotify show | music, self-hosted, news, business | worse (self-hosted, news wrong) |
| joelonsoftware (was 403) | tech, career, productivity, tools | now works (desktop UA retry) |
| stackoverflow (was 403) | tech (sugg: algorithm optimization, data structures) | now works (jina reader) |

Of the 13 URLs that worked before: better 3, same 9, worse 1. The main gain is in the suggestions (specific tags such as `geopolitics`, `yacht design`, `lapstrake`), the 3-tag minimum, and blocked pages.

User's 10 bookmarks after the retag (edited #3 and #5 untouched; crawl source in brackets):
| # | Tags |
|--|--|
| 1 beszel.dev | devops (sugg: lightweight, server) [direct, API rate-limited that run] |
| 2 monitorix.org | linux, opensource, self-hosted |
| 4 YouTube "Sind wir im Krieg?" | video, fun, politics, security (sugg: geopolitics, hybrid warfare, armed conflict, history) |
| 6 YouTube Paradox game | video, gaming, history, tech (sugg: grand strategy, stellaris, post-apocalyptic) |
| 7 London Monster | history (sugg: london history) |
| 8 YouTube Blender | video, art, music, tutorial (sugg: speed modeling, house) |
| 9 smallboatsmonthly | fun, design, tools (sugg: dinghy boat, clinker design, kit boat) |
| 10 gartsideboats (was error 429) | design (sugg: yacht design, boat building, traditional craft) [direct, fetched with the desktop UA] |

# Tagging benchmark

Three generations, same URLs, run on villiger (1 vCPU):
- **Qwen v0**: first LLM tagger (`before.txt`).
- **Qwen final**: tuned LLM tagger, closed vocabulary + min-3 rule (`qwen-final*.txt`).
- **Hybrid**: rules + YAKE + e5 embeddings + kNN, no generative model (`hybrid.txt`, current code).

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

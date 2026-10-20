# Tagging benchmark: before vs after

Same 15 URLs (`bench/urls.txt`), run on villiger (1 vCPU, Qwen2.5-0.5B Q4_K_M, `-c 2048 -t 2`), no user data, vocabulary = seed tags only.
Raw output: `bench/before.txt`, `bench/after.txt`. "llm" = the model was called.

| # | URL | Before | After | Verdict |
|---|-----|--------|-------|---------|
| 1 | github.com/ggml-org/llama.cpp | devops, ai, golang, c, c++ | opensource, ai | better (no wrong languages) |
| 2 | YouTube, Rick Astley | video, music, remaster, riff, ramp | video | mixed (junk gone, `music` lost) |
| 3 | YouTube, Rich Hickey talk | dev, music, self-hosted, tools | video | better |
| 4 | Wikipedia SQLite | ref, tool, self-hosted, database, other | database | better |
| 5 | theguardian.com/technology | technology, business, live, google, cloud | news | better |
| 6 | php.net PDO manual | dev, php, tool, data-access, database-extensions | php, docs, self-hosted | better (`self-hosted` is still wrong) |
| 7 | joelonsoftware.com | ERROR HTTP 403 | ERROR HTTP 403 | n/a (site blocks the crawler) |
| 8 | ikea.com product | dev, news, video, shopping, tool | shopping | better |
| 9 | bbcgoodfood lasagne | food, homemade, easy, preparation, recipe | food | better |
| 10 | immich.app | video, self-hosted (category dev) | video, self-hosted (category article) | same tags (`video` wrong in both); category slightly worse |
| 11 | MDN grid-template-columns | css, grid, template, columns, property | docs, css | better |
| 12 | news.ycombinator.com item | dev, news, video, tool, self-hosted | news | better |
| 13 | tagesschau.de | news, travel, finance, tool, self-hosted | news | better |
| 14 | stackoverflow question | ERROR HTTP 403 | ERROR HTTP 403 | n/a (blocks crawler) |
| 15 | open.spotify.com show | music, tools, self-hosted, video | music | better |

## Summary (13 URLs that could be crawled)
- Better 11, mixed 1, same 1, worse 0.
- The old output was mostly noise: free-form invented tags (`ramp`, `riff`, `data-access`) and the old "prefer these" hint made the model pad every result with `self-hosted`/`tool`/`video`. Now every tag is from the closed vocabulary and an LLM pick must be backed by words on the page.
- Cost: fewer tags (average 4.4 before, 1.5 after) so recall is lower. Examples: no `music` on the Rick Astley video, no `ai` or `opensource` beyond the obvious. Wrong tags that remain: `self-hosted` on php.net, `video` on immich.
- Average time per URL (crawl + tag): 4.5 s before, 7.3 s after. Slower, because the prompt is longer (vocabulary list plus 3 few-shot examples).
- LLM skipped: 1/13 before (7.7 %, by the old keyword shortcut), 0/13 after. The new rule "skip LLM at 3 or more rule tags" never fired on this set; domain rules give 1 to 2 tags, so the model still runs. It would fire for pages with good meta keywords.
- Peak RAM (max RSS of the whole run, incl. the mmapped 491 MB model): 706 MB before, 652 MB after. The systemd service cap is 700 MB.
- No `new_tag` suggestion was produced on this set (the 0.5B model almost never offers one).

## Caveats
- The 0.5B model needed these guard rails to be usable. It copies the first enum entries and few-shot answers (an early variant tagged nearly everything `php` or `food`), so the vocabulary is shuffled per URL, the examples include one with an empty result, and picks without textual evidence are dropped.
- Bookmarks already stored before this change keep their old tags (for example beszel.dev has `food`). Edit them on the card or delete and re-add.
- Two of 15 URLs were blocked by the sites (403), so the sample for judgment is 13.

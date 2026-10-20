<?php
// Tagger: deterministic rules (domain rules, format tags, YouTube category, vocabulary keyword hits) plus ONE OpenRouter call per
// bookmark for vocabulary picks, new-tag suggestions, category and a short summary. No local ML. Falls back to the rules when the API fails.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crawler.php';

const BM_CATEGORIES = ['dev', 'news', 'video', 'shopping', 'reference', 'article', 'tool', 'other'];
const BM_FORMAT_TAGS = ['video', 'book', 'podcast', 'recipe', 'paper', 'product', 'repo'];   // medium, from structure (format_rules.txt); never from the model, never counted toward the 3
const BM_MIN = 2;      // applied topic tags: 2..4 (format tags come on top)
const BM_MAX = 4;
const BM_ALIASES = ['golang' => 'go', 'js' => 'javascript', 'machine learning' => 'ai', 'llm' => 'ai', 'open source' => 'opensource', 'selfhosted' => 'self-hosted', 'homelab' => 'self-hosted',
    'recipe' => 'food', 'sqlite' => 'database', 'sql' => 'database', 'docker' => 'devops', 'kubernetes' => 'devops', 'cybersecurity' => 'security', 'nachrichten' => 'news', 'politik' => 'politics'];
const BM_YT_CATEGORY = ['music' => 'music', 'science & technology' => 'tech', 'education' => 'tutorial', 'gaming' => 'gaming', 'howto & style' => 'tutorial', 'news & politics' => 'news',
    'comedy' => 'fun', 'entertainment' => 'fun', 'film & animation' => 'film', 'sports' => 'sports', 'travel & events' => 'travel', 'autos & vehicles' => 'cars', 'pets & animals' => 'animals'];

function normalize_tags(array $tags): array {
    $out = [];
    foreach ($tags as $t) {
        $t = trim(preg_replace('/[^\p{L}\p{N} +#.-]+/u', '', mb_strtolower(trim((string)$t))));
        if ($t !== '' && mb_strlen($t) <= 24) $out[$t] = true;
    }
    return array_keys($out);
}

/** Vocabulary tag => description: seed_tags.txt ("tag: description") plus the user's most-used tags. */
function vocab(PDO $pdo, int $uid, int $limit = 60): array {
    $v = [];
    foreach (@file(cfg('seed_tags_file') ?: __DIR__ . '/../seed_tags.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        if ($l[0] === '#') continue;
        [$t, $d] = array_pad(explode(':', $l, 2), 2, '');
        if ($t = mb_strtolower(trim($t))) $v[$t] = trim($d) ?: $t;
    }
    $q = $pdo->prepare("SELECT t.tag FROM tags t JOIN bookmarks b ON b.id=t.bookmark_id WHERE b.user_id=? AND t.suggested=0 GROUP BY t.tag ORDER BY COUNT(*) DESC LIMIT $limit");
    $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) $v[$t] ??= $t;
    return $v;
}

function host_of(string $url): string { return preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?: '')); }

/** domain_rules.txt ("domain => tag, tag | category") for this URL; the first match wins for the category. */
function domain_rule(string $url): array {
    static $rules = null;
    if ($rules === null) {
        $rules = [];
        foreach (@file(cfg('domain_rules_file') ?: __DIR__ . '/../domain_rules.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            if ($l[0] === '#' || !str_contains($l, '=>')) continue;
            [$d, $r] = array_map('trim', explode('=>', $l, 2));
            [$t, $c] = array_pad(array_map('trim', explode('|', $r, 2)), 2, '');
            $rules[strtolower($d)] = [array_filter(array_map('trim', explode(',', $t))), in_array($c, BM_CATEGORIES, true) ? $c : null];
        }
    }
    $host = host_of($url); $tags = []; $cat = null;
    foreach ($rules as $d => [$t, $c]) if ($host === $d || str_ends_with($host, ".$d")) { $tags = array_merge($tags, $t); $cat ??= $c; }
    return ['tags' => $tags, 'category' => $cat];
}

/** Deterministic format tags (book, video, podcast, recipe, paper, product, repo): schema.org types, URL, meta, title. See format_rules.txt. */
function format_tags(string $url, string $title, array $sig): array {
    $host = host_of($url); $path = $host . (parse_url($url, PHP_URL_PATH) ?: '');
    $out = [];
    foreach (@file(cfg('format_rules_file') ?: __DIR__ . '/../format_rules.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] === '#') continue;
        $parts = array_map('trim', explode(' | ', $line));
        $tag = array_shift($parts);
        foreach ($parts as $sg) {
            if ($sg[0] === '!') { if (in_array(substr($sg, 1), $out, true)) continue 2; continue; }
            [$k, $v] = array_pad(explode(':', $sg, 2), 2, '');
            $hit = match ($k) {
                'type' => in_array($v, $sig['types'] ?? [], true),
                'host' => str_starts_with($path, $v) || (!str_contains($v, '/') && str_contains(".$host", ".$v")),
                'og' => str_starts_with((string)($sig['og_type'] ?? ''), $v),
                'meta' => (bool)array_filter($sig['metas'] ?? [], fn($m) => str_starts_with($m, $v)),
                'title' => (bool)@preg_match($v, $title),
                'url' => (bool)@preg_match($v, $url),
                'html' => (bool)@preg_match($v, (string)($sig['html'] ?? '')),
                default => $sg === 'isbn' && !empty($sig['isbn']),
            };
            if ($hit) { $out[] = $tag; continue 2; }
        }
    }
    return array_values(array_unique($out));
}

/** Vocabulary tags (and aliases) that occur as whole words in the text. */
function word_hits(array $names, string $text): array {
    $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}+#.-]+/u', ' ', $text)) . ' ';
    $hit = [];
    foreach ($names as $t) if (mb_strlen($t) > 2 && (str_contains($hay, " $t ") || str_contains($hay, " {$t}s "))) $hit[] = $t;
    foreach (BM_ALIASES as $p => $t) if (in_array($t, $names, true) && str_contains($hay, " $p ")) $hit[] = $t;
    return array_values(array_unique($hit));
}

function category_for(array $tags, bool $yt): string {
    if ($yt) return 'video';
    if (array_intersect($tags, ['php', 'javascript', 'go', 'rust', 'devops', 'css', 'database', 'opensource', 'self-hosted', 'linux', 'security', 'docs'])) return 'dev';
    foreach (['news' => 'news', 'shopping' => 'shopping', 'tools' => 'tool', 'tutorial' => 'article'] as $t => $c) if (in_array($t, $tags, true)) return $c;
    return 'other';
}

/** A usable summary: 20-450 chars, about the subject (not availability/price/store), not "This page..." (and not "This article..." for books, videos, ...); '' otherwise. */
function valid_summary(string $s, array $format = []): string {
    $s = clean_text(strip_tags($s));
    $generic = array_intersect($format, ['book', 'video', 'podcast', 'product', 'repo']) ? '|article|website|site|post' : '';
    return (mb_strlen($s) < 20 || mb_strlen($s) > 450 || preg_match('/^(this (web)?page' . $generic . ')\b|^diese seite\b|\b(available|kaufen|disponibile|formats?|price|shipping|in stock|add to cart)\b/i', $s)) ? '' : $s;
}

/** New-tag candidates: not vocabulary tags (or stems of them), no site/brand/channel names. */
function novel_tags(array $cands, array $names, string $channel, string $url, array $brands = []): array {
    $brands = array_merge($brands, brand_tokens($url));
    $cands = array_filter($cands, fn($p) => !array_filter($brands, fn($b) => str_contains($p, $b)));
    $skip = preg_replace('/[^a-z0-9]/', '', mb_strtolower($channel . host_of($url)));
    return array_values(array_filter($cands, fn($p) => mb_strlen($p) >= 3 && mb_strlen($p) <= 24 && !preg_match('/^\d/', $p) && !in_array($p, $names, true)
        && !array_filter($names, fn($n) => mb_strlen($n) >= 5 && mb_substr($n, 0, 5) === mb_substr($p, 0, 5)) && !str_contains($skip, preg_replace('/[^a-z0-9]/', '', $p))));
}

/** Examples of how this user tags: their corrected bookmarks, same domain first, else the most recent. */
function user_shots(PDO $pdo, int $uid, string $host, int $n = 3): array {
    if ($uid <= 0) return [];
    $st = $pdo->prepare('SELECT id, title, url FROM bookmarks WHERE user_id=? AND tags_edited=1 ORDER BY id DESC LIMIT 60');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    usort($rows, fn($a, $b) => (host_of($b['url']) === $host) <=> (host_of($a['url']) === $host));   // stable: same domain first
    $out = [];
    foreach (array_slice($rows, 0, $n) as $b) {
        $t = $pdo->prepare('SELECT tag FROM tags WHERE bookmark_id=? AND suggested=0'); $t->execute([$b['id']]);
        if ($tags = array_diff($t->fetchAll(PDO::FETCH_COLUMN), BM_FORMAT_TAGS)) $out[] = '- "' . mb_substr((string)$b['title'], 0, 80) . '" -> ' . implode(', ', $tags);
    }
    return $out;
}

/** Does the page text mention the tag (or a description term of it)? */
function tag_in_text(string $tag, string $desc, string $text): bool {
    $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}+#.-]+/u', ' ', $text)) . ' ';
    foreach (array_merge([$tag], array_map('trim', explode(',', mb_strtolower($desc)))) as $w) {
        if (mb_strlen($w) >= 4 && (str_contains($hay, " $w ") || str_contains($hay, " {$w}s "))) return true;
    }
    return false;
}

/** Transport: monthly cap + counters, `models` fallback list. @return array{j:array,cost:float}|array{transient:true}|array{} */
function openrouter_post(PDO $pdo, string $prompt, int $maxTokens): array {
    if (!($key = cfg('openrouter_key'))) return [];
    $month = date('Y-m');
    $pdo->prepare('INSERT OR IGNORE INTO api_usage(month,calls) VALUES(?,0)')->execute([$month]);
    $n = $pdo->prepare('SELECT calls FROM api_usage WHERE month=?'); $n->execute([$month]);
    if ((int)$n->fetchColumn() >= (int)(cfg('openrouter_cap') ?: 3000)) { error_log('openrouter: monthly call cap reached'); return []; }
    $pdo->prepare('UPDATE api_usage SET calls=calls+1 WHERE month=?')->execute([$month]);
    $ch = curl_init(cfg('openrouter_url') ?: 'https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'HTTP-Referer: ' . (cfg('base_url') ?: 'https://localhost'), 'X-Title: Bookmarks'],
        CURLOPT_POSTFIELDS => json_encode(['models' => cfg('openrouter_models') ?: ['google/gemini-2.5-flash-lite'], 'temperature' => 0.2, 'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => 'You tag and summarize bookmarks. Reply with JSON only.'], ['role' => 'user', 'content' => $prompt]]])]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $r = json_decode((string)$raw, true);
    $ec = (int)($r['error']['code'] ?? 0);
    if ($raw === false || $code == 429 || $code >= 500 || $ec == 429 || $ec >= 500) { error_log('openrouter: transient failure (http ' . $code . '), will retry later'); return ['transient' => true]; }
    $j = json_decode((string)($r['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($j)) { error_log('openrouter: failed ' . substr(json_encode($r['error'] ?? curl_error($ch)), 0, 160)); return []; }
    if (!empty($r['model'])) {                                           // which model of the fallback list actually answered
        $pdo->prepare('INSERT INTO api_model_usage(month,model,calls) VALUES(?,?,1) ON CONFLICT(month,model) DO UPDATE SET calls=calls+1')->execute([$month, $r['model']]);
        error_log('openrouter: answered by ' . $r['model']);
    }
    $u = $r['usage'] ?? [];
    return ['j' => $j, 'cost' => ($u['prompt_tokens'] ?? 0) * (float)(cfg('openrouter_price_in') ?: 1e-7) + ($u['completion_tokens'] ?? 0) * (float)(cfg('openrouter_price_out') ?: 4e-7)];
}

/**
 * Ask the model about one page: vocabulary picks, new tags, category, summary. [] on a permanent problem, ['transient' => true] to retry later.
 * $format = detected format tags (book, video, ...): the summary must start by saying what the thing is; one stricter retry if it does not.
 * @return array{tags:string[],new_tags:string[],category:string,summary:string,cost:float}|array
 */
function suggest_external(PDO $pdo, string $url, array $c, array $names, array $shots = [], array $format = []): array {
    $yt = youtube_id($url) !== null;
    $type = ['book' => 'a book', 'video' => 'a YouTube video', 'podcast' => 'a podcast', 'recipe' => 'a recipe', 'paper' => 'a paper', 'product' => 'a product', 'repo' => 'a code repository'][$format[0] ?? ''] ?? 'a web page';
    $base = 'Domain: ' . host_of($url) . "\nDetected type: $type\nTitle: " . mb_substr($c['title'] ?: $url, 0, 200) . (!empty($c['author']) ? "\nAuthor: {$c['author']}" : '') . "\nDescription: " . mb_substr($c['description'], 0, 400)
        . ($yt ? "\nChannel: {$c['channel']}\nYouTube category: {$c['yt_category']}\nYouTube keywords: " . mb_substr($c['keywords'], 0, 300) : '') . "\nText: " . mb_substr($c['body'], 0, 1200)
        . "\n\nVocabulary: " . implode(', ', array_diff($names, BM_FORMAT_TAGS))
        . ($shots ? "\nHow this user tags other pages:\n" . implode("\n", $shots) : '');
    $ask = fn(string $extra) => $base . "\n\nReply with JSON only: {\"tags\": [2 to 4 tags chosen ONLY from the vocabulary, most relevant first; only tags central to this page; avoid vague catch-alls like tools, tech, business, productivity or fun unless the page is really about them], "
        . "\"new_tags\": [0 to 3 specific new lowercase tags of 1-2 words; if fewer than 2 vocabulary tags fit, give at least 2 minus that many], \"category\": \"one of " . implode('|', BM_CATEGORIES) . "\", "
        . "\"summary\": \"Write 2-3 sentences (max 400 characters) that say what this is and what it is about, starting with what it is, e.g. 'A book by Gil Durán arguing...', 'A YouTube video by X showing...', 'An essay by James Madison (Federalist No. 10) arguing...', "
        . "'A free open-source tool for...', 'A Wikipedia article about...'. Describe the content itself, not the website, availability, price, formats or shipping. Same language as the page. No marketing fluff.$extra\"}";
    $out = ['transient' => true];
    foreach (['', " It is $type: the first words must name it (for example 'A " . ltrim(substr($type, 2)) . "...'); never start with 'This'."] as $i => $extra) {
        $p = openrouter_post($pdo, $ask($extra), 450);
        if (!isset($p['j'])) return $i ? $out : $p;
        $j = $p['j'];
        $out = ['tags' => normalize_tags((array)($j['tags'] ?? [])), 'new_tags' => normalize_tags((array)($j['new_tags'] ?? [])), 'category' => in_array($j['category'] ?? '', BM_CATEGORIES, true) ? $j['category'] : '',
                'summary' => valid_summary((string)($j['summary'] ?? ''), $format), 'cost' => $p['cost'] + ($out['cost'] ?? 0)];
        if ($out['summary'] !== '') break;
    }
    return $out;
}

/**
 * Merge the model's answer into applied/suggested lists (applied: 2..4). Its vocabulary picks come most-relevant first: the first 2 (counting rule hits) are trusted,
 * the 3rd and 4th need support in the page text, the rest become suggestions. New tags are suggestions; under 2 applied, text-supported ones are promoted. Brand names never become tags. $text = title + description + summary, $body = page text.
 */
function merge_picks(array $x, array $vocab, array $applied, array $sugg, string $channel, string $url, array $brands, string $text, string $body = ''): array {
    $names = array_keys($vocab);
    $brandHit = fn($t) => (bool)array_filter(array_merge($brands, brand_tokens($url)), fn($b) => str_contains($t, $b));
    $held = [];
    foreach ($x['tags'] ?? [] as $t) {
        if (!in_array($t, $names, true) || in_array($t, BM_FORMAT_TAGS, true) || $brandHit($t) || in_array($t, $applied, true)) continue;
        if (count($applied) < 2 || tag_in_text($t, $vocab[$t], "$text $body")) $applied[] = $t; else $held[] = $t;
    }
    $applied = array_slice(array_values(array_unique($applied)), 0, BM_MAX);
    $new = novel_tags($x['new_tags'] ?? [], $names, $channel, $url, $brands);
    $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)) . ' ';
    foreach ($new as $i => $t) {
        if (count($applied) >= BM_MIN) break;
        $words = array_filter(preg_split('/\s+/u', mb_strtolower($t)), fn($w) => mb_strlen($w) >= 4);
        if ($words && !array_filter($words, fn($w) => !str_contains($hay, " $w ") && !str_contains($hay, " {$w}s "))) { $applied[] = $t; unset($new[$i]); }
    }
    return [$applied, array_slice(array_values(array_unique(array_diff(array_merge($new, $held, $sugg), $applied))), 0, 4)];
}

/**
 * Tag one crawled page.
 * @return array{tags:string[],suggested:string[],category:string,summary:string,api:int,cost:float,llm_pending:bool}
 */
function tag_bookmark(string $url, array $c, array $vocab, PDO $pdo, int $uid = 0): array {
    $yt = youtube_id($url) !== null;
    $title = $c['title'] ?: $url;
    $names = array_keys($vocab);
    $dr = domain_rule($url);
    $local = array_merge($dr['tags'], word_hits($names, "$title {$c['description']} {$c['keywords']}"));      // 1. rules and keyword hits
    if ($yt && isset(BM_YT_CATEGORY[$k = mb_strtolower((string)($c['yt_category'] ?? ''))])) $local[] = BM_YT_CATEGORY[$k];
    $format = array_values(array_unique(array_merge(format_tags($url, $title, $c['signals'] ?? []), $yt ? ['video'] : [], array_intersect($local, BM_FORMAT_TAGS))));
    $applied = array_slice(array_values(array_unique(array_diff($local, BM_FORMAT_TAGS))), 0, BM_MAX);
    $sugg = [];
    $brands = brand_tokens($url, (string)($c['site_name'] ?? ''));
    $x = [];                                                                                                     // 2. one OpenRouter call
    try { $x = suggest_external($pdo, $url, $c, $names, user_shots($pdo, $uid, host_of($url)), $format); } catch (Throwable $e) { error_log('openrouter: ' . $e->getMessage()); }
    $deferred = !empty($x['transient']);
    if ($deferred) $x = [];
    $text = $title . ' ' . $c['description'] . ' ' . ($x['summary'] ?? '');
    [$applied, $sugg] = merge_picks($x, $vocab, $applied, $sugg, (string)($c['channel'] ?? ''), $url, $brands, $text, $c['body']);
    if (!$x) {                                                                                                   // API unavailable: page meta keywords as suggestions
        $kw = normalize_tags(array_map('trim', explode(',', $c['keywords'])));
        $sugg = array_slice(array_values(array_diff(novel_tags($kw, $names, (string)($c['channel'] ?? ''), $url, $brands), $applied)), 0, 4);
    }
    return ['tags' => array_merge($format, $applied), 'suggested' => $sugg, 'category' => $dr['category'] ?? ($x['category'] ?? '') ?: category_for($applied, $yt),
            'summary' => $x['summary'] ?? '', 'api' => $x ? 1 : 0, 'cost' => $x['cost'] ?? 0.0, 'llm_pending' => $deferred];
}

/** Seconds until the next deferred OpenRouter attempt after `$attempts` failures; null = give up. */
function llm_backoff(int $attempts): ?int { return [60, 300, 1800, 7200][$attempts - 1] ?? null; }

/**
 * Deferred OpenRouter step for a bookmark that already has local tags: merges into its tags (not for tags_edited=1 unless refreshing; the summary is always set).
 * @return string done | retry | gaveup
 */
function llm_retry(PDO $pdo, array $b, array $vocab): string {
    $c = ['title' => (string)$b['title'], 'description' => (string)$b['description'], 'body' => (string)$b['body'], 'keywords' => '', 'channel' => '', 'yt_category' => ''];
    $fmt = array_column($pdo->query('SELECT tag FROM tags WHERE bookmark_id=' . (int)$b['id'])->fetchAll(), 'tag');
    $x = suggest_external($pdo, $b['url'], $c, array_keys($vocab), user_shots($pdo, (int)$b['user_id'], host_of($b['url'])), array_values(array_intersect($fmt, BM_FORMAT_TAGS)));
    $attempts = (int)$b['llm_attempts'] + 1;
    if (!empty($x['transient'])) {
        $d = llm_backoff($attempts);
        if ($d === null) { $pdo->prepare('UPDATE bookmarks SET llm_pending=0, llm_attempts=? WHERE id=?')->execute([$attempts, $b['id']]); error_log("openrouter: gave up on #{$b['id']} after $attempts attempts"); return 'gaveup'; }
        $pdo->prepare('UPDATE bookmarks SET llm_attempts=?, llm_next_at=? WHERE id=?')->execute([$attempts, time() + $d, $b['id']]);
        return 'retry';
    }
    $pdo->prepare('UPDATE bookmarks SET llm_pending=0, llm_attempts=?, refresh=0 WHERE id=?')->execute([$attempts, $b['id']]);
    if (!$x) return 'gaveup';                                                         // permanent failure or cap reached
    if (!empty($x['summary'])) $pdo->prepare('UPDATE bookmarks SET summary=? WHERE id=?')->execute([$x['summary'], $b['id']]);
    if (!$b['tags_edited'] || $b['refresh']) {      // edited bookmarks only during a refresh: their applied tags stay, new ones are added
        $cur = fn($sg) => array_column($pdo->query('SELECT tag FROM tags WHERE bookmark_id=' . (int)$b['id'] . ' AND suggested=' . $sg)->fetchAll(), 'tag');
        [$applied, $sugg] = merge_picks($x, $vocab, array_values(array_diff($cur(0), BM_FORMAT_TAGS)), $cur(1), '', $b['url'], [], $c['title'] . ' ' . $c['description'] . ' ' . ($x['summary'] ?? ''), $c['body']);
        $keep = array_values(array_intersect($cur(0), BM_FORMAT_TAGS));
        $pdo->prepare('DELETE FROM tags WHERE bookmark_id=?')->execute([$b['id']]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO tags(bookmark_id, tag, suggested) VALUES(?,?,?)');
        foreach (array_merge($keep, $applied) as $t) $ins->execute([$b['id'], $t, 0]);
        foreach ($sugg as $t) $ins->execute([$b['id'], $t, 1]);
    }
    fts_reindex((int)$b['id']);
    return 'done';
}
